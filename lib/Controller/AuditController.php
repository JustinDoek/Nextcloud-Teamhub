<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\AuditMirrorJob;
use OCA\TeamHub\Db\AuditLogMapper;
use OCA\TeamHub\Service\AuditIngestionService;
use OCA\TeamHub\Service\AuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
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
 *   GET    /api/v1/admin/audit/events                      — paginated rows, every team
 *   GET    /api/v1/admin/audit/teams/{teamId}/events       — paginated rows, one team
 *   GET    /api/v1/admin/audit/teams/{teamId}/export       — ZIP export
 *   GET    /api/v1/admin/audit/retention                    — retention setting
 *   PUT    /api/v1/admin/audit/retention                    — save retention setting
 */
class AuditController extends Controller {

    /** Hard ceiling on perPage to defend against runaway queries. */
    private const MAX_PER_PAGE = 200;
    private const DEFAULT_PER_PAGE = 50;

    /**
     * The all-teams view's page size (v4.11.39). Smaller than the per-team
     * default because this page is read, not scanned: an administrator opens
     * the Compliance tab to see what happened recently, and fifteen rows is
     * what fits above the fold next to the filters.
     */
    private const ALL_PER_PAGE = 15;

    public function __construct(
        string $appName,
        IRequest $request,
        private AuditLogMapper  $mapper,
        private IDBConnection   $db,
        private IAppConfig      $appConfig,
        private IUserSession    $userSession,
        private TimezoneService $timezoneService,
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
            // Only teams that still have a circles_circle row are included —
            // this naturally excludes hard-deleted teams.
            //
            // apps.md R-1: direct SELECT retained — the audit admin must
            // see display names for teams they may not be a member of.
            // CirclesManager::getCircle() throws CircleNotFoundException
            // on any circle not visible to the caller.
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

            // Collect team IDs that are in a soft-delete grace period
            // (status='pending' in teamhub_pending_dels). These teams still
            // have a circles_circle row but must not appear in the dropdown.
            $pendingIds = [];
            if (!empty($teamIds)) {
                $pdQb = $this->db->getQueryBuilder();
                $pdQb->select('team_id')
                    ->from('teamhub_pending_dels')
                    ->where($pdQb->expr()->in(
                        'team_id',
                        $pdQb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    ))
                    ->andWhere($pdQb->expr()->eq('status', $pdQb->createNamedParameter('pending')));
                $pdRes = $pdQb->executeQuery();
                while ($pdRow = $pdRes->fetch()) {
                    $pendingIds[(string)$pdRow['team_id']] = true;
                }
                $pdRes->closeCursor();
            }

            // v4.11.39 — the instance scope as its own bucket. Its rows are
            // real events about the installation (policy profiles, blueprints,
            // provisioning runs, the directory-group history) that had no
            // reader at all while this list was the only way into the log;
            // they are reported separately because the sentinel is not a
            // circle and has no name to read out of the database.
            $instanceCount = 0;
            $instanceLast  = 0;
            foreach ($summary as $row) {
                if ($row['team_id'] === AuditService::INSTANCE_SCOPE) {
                    $instanceCount = (int)$row['event_count'];
                    $instanceLast  = (int)$row['last_event_at'];
                    break;
                }
            }

            $teams = [];
            foreach ($summary as $row) {
                $tid = $row['team_id'];

                // Exclude hard-deleted teams (no circles_circle row — name not resolved).
                if (!isset($names[$tid])) {
                    continue;
                }

                // Exclude soft-deleted teams (pending grace period).
                // Their audit log is archived in the ZIP; no need to surface here.
                if (isset($pendingIds[$tid])) {
                    continue;
                }

                $teams[] = [
                    'team_id'       => $tid,
                    'display_name'  => $names[$tid],
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
                'instance_scope'   => [
                    'team_id'       => AuditService::INSTANCE_SCOPE,
                    'event_count'   => $instanceCount,
                    'last_event_at' => $instanceLast,
                ],
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/audit/events
    // -------------------------------------------------------------------------

    /**
     * v4.11.39 — paginated read across **every** team, instance-scoped rows
     * included, with the team as an optional narrowing filter.
     *
     * This is what the Compliance tab reads. Until 4.11.39 it could only read
     * {@see listEvents()}, which meant picking a team first — so an event
     * filed under `AuditService::INSTANCE_SCOPE` was written by four services
     * and readable by none, and the directory-group history (v4.11.39) would
     * have gone the same way.
     *
     * `teamId` accepts `_instance` like any other value: the sentinel is a
     * `team_id` as far as the query is concerned, which is the property that
     * let the instance scope ship without a schema change.
     *
     * Query params:
     *   teamId      string    '' = every team. `_instance` = not about a team.
     *   page        int       1-based (default 1)
     *   perPage     int       capped at MAX_PER_PAGE (default ALL_PER_PAGE)
     *   eventTypes  string    Comma-separated list. Empty = no filter.
     *   from        int       Unix seconds. Inclusive lower bound. 0 = no filter.
     *   to          int       Unix seconds. Inclusive upper bound. 0 = no filter.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function listAllEvents(
        string $teamId = '',
        int    $page = 1,
        int    $perPage = self::ALL_PER_PAGE,
        string $eventTypes = '',
        int    $from = 0,
        int    $to = 0,
    ): JSONResponse {
        try {
            $teamId = trim($teamId);
            if (strlen($teamId) > 64) {
                return new JSONResponse(['error' => 'Invalid teamId'], Http::STATUS_BAD_REQUEST);
            }

            $page    = max(1, $page);
            $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
            $offset  = ($page - 1) * $perPage;

            $eventTypeArr = $this->parseEventTypes($eventTypes);
            $fromTs = $from > 0 ? $from : null;
            $toTs   = $to   > 0 ? $to   : null;

            if ($teamId === '') {
                $rows  = $this->mapper->findAll($offset, $perPage, $eventTypeArr, $fromTs, $toTs);
                $total = $this->mapper->countAll($eventTypeArr, $fromTs, $toTs);
            } else {
                $rows  = $this->mapper->findByTeam($teamId, $offset, $perPage, $eventTypeArr, $fromTs, $toTs);
                $total = $this->mapper->countByTeam($teamId, $eventTypeArr, $fromTs, $toTs);
            }

            // The all-teams view mixes teams, so each row has to say which
            // one it belongs to; the per-team view never needed that.
            $names = $this->displayNamesFor(array_values(array_unique(
                array_map(static fn(array $r) => (string)($r['team_id'] ?? ''), $rows)
            )));
            foreach ($rows as $i => $row) {
                $tid = (string)($row['team_id'] ?? '');
                $rows[$i]['team_name'] = $names[$tid] ?? '';
            }

            return new JSONResponse([
                'rows'        => $rows,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int)ceil($total / $perPage),
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

            $eventTypeArr = $this->parseEventTypes($eventTypes);

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
    public function exportTeam(string $teamId): DataDownloadResponse|JSONResponse {
        try {
            $teamId = trim($teamId);
            if ($teamId === '' || strlen($teamId) > 64) {
                return new JSONResponse(['error' => 'Invalid teamId'], Http::STATUS_BAD_REQUEST);
            }

            // Resolve display name for the export filename + team-info.json.
            // apps.md R-1: direct SELECT retained — same rationale as
            // listTeams() above; admin export must see the display name of
            // teams they may not be a member of.
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
                // $tmpFile is tempnam()'s own path (above), never request data.
                @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use
                return new JSONResponse(['error' => 'Could not open zip for writing'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $zip->addFromString('team-info.json',
                json_encode($teamInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $zip->addFromString('events.json',
                json_encode($events,   JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
            $zip->close();

            $bytes = file_get_contents($tmpFile);
            // Same tempnam() path as above.
            @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use

            if ($bytes === false) {
                return new JSONResponse(['error' => 'Could not read zip file'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $filename = sprintf(
                'teamhub-audit-%s-%s.zip',
                $this->slugify($displayName),
                $this->timezoneService->today($this->userSession->getUser()?->getUID() ?? ''),
            );

            return new DataDownloadResponse($bytes, $filename, 'application/zip');
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][AuditController] export failed', [
                'teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
     * The `eventTypes` query parameter: a comma-separated list, trimmed,
     * empties and over-long entries dropped.
     *
     * @return list<string>
     */
    private function parseEventTypes(string $eventTypes): array {
        if ($eventTypes === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $eventTypes) as $t) {
            $t = trim($t);
            if ($t !== '' && strlen($t) <= 64) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Display names for a set of team ids, in one query (v4.11.39).
     *
     * `AuditService::INSTANCE_SCOPE` is not a circle and never resolves — the
     * caller renders it from its own label, because "not about one team" is a
     * phrase for the user's language, not a name to read out of the database.
     *
     * apps.md R-1: direct SELECT retained — same rationale as listTeams().
     *
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function displayNamesFor(array $teamIds): array {
        $teamIds = array_values(array_filter(
            $teamIds,
            static fn(string $id) => $id !== '' && $id !== AuditService::INSTANCE_SCOPE,
        ));
        if ($teamIds === []) {
            return [];
        }
        $names = [];
        try {
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
        } catch (\Throwable $e) {
            // A name is a convenience; the rows themselves still answer.
            $this->logger->warning('[TeamHub][AuditController] team name lookup failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
        return $names;
    }

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
