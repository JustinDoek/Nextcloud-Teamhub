<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\AuditMirrorJob;
use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\Service\AuditIngestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-only audit-log endpoints.
 *
 * All routes require NC admin via #[AuthorizedAdminSetting]. The audit log can
 * contain user-identifying information (uids, file paths, group names) — exposing
 * any of it to non-admins would be a privacy violation.
 *
 * Endpoints:
 *   GET    /api/v1/admin/audit/teams                       — summary list
 *   GET    /api/v1/admin/audit/teams/{teamId}/events       — paginated rows
 *   GET    /api/v1/admin/audit/teams/{teamId}/export       — ZIP export
 *   GET    /api/v1/admin/audit/retention                    — retention setting
 *   PUT    /api/v1/admin/audit/retention                    — save retention setting
 */
class AuditController extends Controller {

    /** Hard ceiling on perPage to defend against runaway queries. */
    private const MAX_PER_PAGE = 200;
    private const DEFAULT_PER_PAGE = 50;

    public function __construct(
        string $appName,
        IRequest $request,
        private AuditLogMapper  $mapper,
        private IDBConnection   $db,
        private IAppConfig      $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/audit/teams
    // -------------------------------------------------------------------------

    /**
     * Returns all teams that have at least one audit-log row, with a count
     * and last-event timestamp. Joined against circles_circle to surface the
     * display name. Teams with zero audit rows are NOT included — there is
     * nothing to show for them in the audit-tab dropdown.
     *
     * Also surfaces whether the activity app is missing, so the frontend
     * can show a banner without an extra round-trip.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listTeams(): JSONResponse {
        try {
            $summary  = $this->mapper->summarize();
            $teamIds  = array_map(static fn(array $r) => $r['team_id'], $summary);

            // Look up display names in one query.
            $names = [];
            if (!empty($teamIds)) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('unique_id', 'name', 'display_name', 'sanitized_name')
                    ->from('circles_circle')
                    ->where($qb->expr()->in(
                        'unique_id',
                        $qb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    ));
                $r = $qb->executeQuery();
                while ($row = $r->fetch()) {
                    $names[(string)$row['unique_id']] = $this->resolveDisplayName($row);
                }
                $r->closeCursor();
            }

            $teams = [];
            foreach ($summary as $row) {
                $tid = $row['team_id'];
                $teams[] = [
                    'team_id'       => $tid,
                    // The team may have been deleted — fall back to the team_id
                    // as display so admins can still see the audit history of
                    // a now-deleted team.
                    'display_name'  => $names[$tid] ?? $tid,
                    'event_count'   => $row['event_count'],
                    'last_event_at' => $row['last_event_at'],
                ];
            }

            // Sort by last_event_at desc — most-recently-active first.
            usort($teams, static fn(array $a, array $b) => $b['last_event_at'] <=> $a['last_event_at']);

            $activityMissing = (string)$this->appConfig->getValueString(
                Application::APP_ID,
                AuditIngestionService::CFG_ACTIVITY_MISSING,
                '0',
            ) === '1';

            return new JSONResponse([
                'teams'            => $teams,
                'activity_missing' => $activityMissing,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/audit/teams/{teamId}/events
    // -------------------------------------------------------------------------

    /**
     * Paginated read of audit events for a single team.
     *
     * Query params:
     *   page        int       1-based (default 1)
     *   perPage     int       capped at MAX_PER_PAGE (default 50)
     *   eventTypes  string    Comma-separated list. Empty = no filter.
     *   from        int       Unix seconds. Inclusive lower bound. 0 = no filter.
     *   to          int       Unix seconds. Inclusive upper bound. 0 = no filter.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listEvents(
        string $teamId,
        int    $page = 1,
        int    $perPage = self::DEFAULT_PER_PAGE,
        string $eventTypes = '',
        int    $from = 0,
        int    $to = 0,
    ): JSONResponse {
        try {
            $teamId = trim($teamId);
            if ($teamId === '' || strlen($teamId) > 64) {
                return new JSONResponse(['error' => 'Invalid teamId'], Http::STATUS_BAD_REQUEST);
            }

            $page    = max(1, $page);
            $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

            $eventTypeArr = [];
            if ($eventTypes !== '') {
                foreach (explode(',', $eventTypes) as $t) {
                    $t = trim($t);
                    if ($t !== '' && strlen($t) <= 64) {
                        $eventTypeArr[] = $t;
                    }
                }
            }

            $fromTs = $from > 0 ? $from : null;
            $toTs   = $to   > 0 ? $to   : null;

            $offset = ($page - 1) * $perPage;
            $rows   = $this->mapper->findByTeam($teamId, $offset, $perPage, $eventTypeArr, $fromTs, $toTs);
            $total  = $this->mapper->countByTeam($teamId, $eventTypeArr, $fromTs, $toTs);

            return new JSONResponse([
                'rows'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/audit/teams/{teamId}/export
    // -------------------------------------------------------------------------

    /**
     * Stream a ZIP containing two JSON files:
     *   - team-info.json  — minimal team metadata (id, display name, exported_at)
     *   - events.json     — every audit row for the team, oldest first
     *
     * Built with PHP's ZipArchive. For realistic data volumes (a year of activity
     * on a busy team is a few tens of MB pre-zip) this completes well within a
     * standard PHP request budget without needing streaming.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function exportTeam(string $teamId): mixed {
        try {
            $teamId = trim($teamId);
            if ($teamId === '' || strlen($teamId) > 64) {
                return new JSONResponse(['error' => 'Invalid teamId'], Http::STATUS_BAD_REQUEST);
            }

            // Resolve display name for the export filename + team-info.json.
            $displayName = $teamId;
            try {
                $qb = $this->db->getQueryBuilder();
                $r = $qb->select('name', 'display_name', 'sanitized_name')
                    ->from('circles_circle')
                    ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $row = $r->fetch();
                $r->closeCursor();
                if ($row !== false) {
                    $displayName = $this->resolveDisplayName($row);
                }
            } catch (\Throwable $e) { /* non-fatal — fall back to team_id */ }

            // Stream events out of the mapper. For very large teams this would
            // hold the result set open while we accumulate, but we then write
            // to a temp file before zipping — memory cost is bounded by JSON
            // string size, not the underlying SQL cursor.
            $events = [];
            foreach ($this->mapper->streamByTeam($teamId) as $event) {
                $events[] = $event;
            }

            $teamInfo = [
                'team_id'      => $teamId,
                'display_name' => $displayName,
                'exported_at'  => time(),
                'event_count'  => count($events),
            ];

            // Build the zip in a temp file.
            $tmpFile = tempnam(sys_get_temp_dir(), 'teamhub-audit-');
            if ($tmpFile === false) {
                return new JSONResponse(['error' => 'Could not create temp file'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                @unlink($tmpFile);
                return new JSONResponse(['error' => 'Could not open zip for writing'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $zip->addFromString('team-info.json',
                json_encode($teamInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $zip->addFromString('events.json',
                json_encode($events,   JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
            $zip->close();

            $bytes = file_get_contents($tmpFile);
            @unlink($tmpFile);

            if ($bytes === false) {
                return new JSONResponse(['error' => 'Could not read zip file'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $filename = sprintf(
                'teamhub-audit-%s-%s.zip',
                $this->slugify($displayName),
                date('Y-m-d', time()),
            );

            return new DataDownloadResponse($bytes, $filename, 'application/zip');
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][AuditController] export failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // GET / PUT /api/v1/admin/audit/retention
    // -------------------------------------------------------------------------

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getRetention(): JSONResponse {
        try {
            $raw = $this->appConfig->getValueString(
                Application::APP_ID,
                AuditMirrorJob::CFG_RETENTION_DAYS,
                '',
            );
            $n = $raw === '' ? AuditMirrorJob::DEFAULT_RETENTION_DAYS : (int)$raw;
            $n = max(AuditMirrorJob::MIN_RETENTION_DAYS, min(AuditMirrorJob::MAX_RETENTION_DAYS, $n));
            return new JSONResponse([
                'retention_days' => $n,
                'min'            => AuditMirrorJob::MIN_RETENTION_DAYS,
                'max'            => AuditMirrorJob::MAX_RETENTION_DAYS,
                'default'        => AuditMirrorJob::DEFAULT_RETENTION_DAYS,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveRetention(int $retentionDays = 0): JSONResponse {
        try {
            if ($retentionDays < AuditMirrorJob::MIN_RETENTION_DAYS
                || $retentionDays > AuditMirrorJob::MAX_RETENTION_DAYS) {
                return new JSONResponse(
                    ['error' => sprintf(
                        'retentionDays must be between %d and %d',
                        AuditMirrorJob::MIN_RETENTION_DAYS,
                        AuditMirrorJob::MAX_RETENTION_DAYS,
                    )],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $this->appConfig->setValueString(
                Application::APP_ID,
                AuditMirrorJob::CFG_RETENTION_DAYS,
                (string)$retentionDays,
            );
            return new JSONResponse(['retention_days' => $retentionDays]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a circle row's best display name (matches the pattern used in
     * MaintenanceService::getAllTeams).
     *
     * @param array<string,mixed> $row
     */
    private function resolveDisplayName(array $row): string {
        if (!empty($row['display_name'])) {
            return (string)$row['display_name'];
        }
        if (!empty($row['sanitized_name'])) {
            return (string)$row['sanitized_name'];
        }
        $raw = (string)($row['name'] ?? '');
        if (str_starts_with($raw, 'app:circles:')) {
            return substr($raw, strlen('app:circles:'));
        }
        return $raw;
    }

    /**
     * Make a string safe to embed in a download filename. ASCII-only,
     * lowercase, hyphenated, no path separators.
     */
    private function slugify(string $s): string {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s === '' ? 'team' : substr($s, 0, 60);
    }
}
