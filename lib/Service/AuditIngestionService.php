<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\AuditLogMapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * AuditIngestionService — populates the TeamHub audit log from external sources.
 *
 * Three independent passes are run by AuditMirrorJob each cycle:
 *
 *   1. mirrorCircles()  — scans oc_activity rows with app='circles' since the
 *                          last cursor and translates the 7 known Circles
 *                          subjects into TeamHub event types.
 *
 *   2. mirrorFiles()    — scans oc_activity rows with app='files' since the
 *                          last cursor and prefix-matches paths against
 *                          known team-shared folders.
 *
 *   3. snapshotShares() — diffs the current oc_share state for team circles
 *                          against the previous run's snapshot to surface
 *                          share.created / share.permissions_changed / share.deleted.
 *
 * Cursor and snapshot are persisted in IAppConfig under the 'teamhub' app.
 *
 * IMPORTANT: this service is INTENDED to be idempotent against the cursor —
 * running it twice with the same cursor must produce zero new rows the
 * second time. Each pass advances its cursor only after a successful read.
 *
 * Failures in any single mirror pass are caught and logged — never thrown —
 * so a subject parse error cannot block the rest of the cycle.
 */
class AuditIngestionService {

    /** Cursor key for circles activity ingestion. Unix seconds, last seen timestamp. */
    public const CFG_CURSOR_CIRCLES = 'audit_cursor_circles';

    /** Cursor key for files activity ingestion. */
    public const CFG_CURSOR_FILES = 'audit_cursor_files';

    /** JSON snapshot of last-seen team shares (keyed by share id). */
    public const CFG_SHARE_SNAPSHOT = 'audit_share_snapshot';

    /** Set when the activity app is missing or disabled — used by AuditTab.vue. */
    public const CFG_ACTIVITY_MISSING = 'audit_activity_missing';

    /** Hard cap per pass — defends against runaway batches if the cursor was reset. */
    private const MAX_ROWS_PER_PASS = 5000;

    /**
     * Dedup window for direct-logged + mirrored events that target the same
     * row. e.g. team.deleted is logged directly by deleteTeam() AND mirrors
     * Circles' circle_delete activity row. Within this window we keep only one.
     */
    private const DEDUP_WINDOW_SECONDS = 5;

    public function __construct(
        private IDBConnection   $db,
        private IAppConfig      $appConfig,
        private AuditLogMapper  $mapper,
        private LoggerInterface $logger,
    ) {
    }

    // =========================================================================
    // Top-level orchestration helpers
    // =========================================================================

    /**
     * Detect whether the activity app is installed and has produced rows.
     * Sets CFG_ACTIVITY_MISSING accordingly so the AuditTab can show a banner.
     *
     * Returns true when activity is available, false when missing.
     */
    public function checkActivityAvailable(): bool {
        try {
            // Cheapest possible probe: SELECT 1 FROM oc_activity LIMIT 1.
            $qb = $this->db->getQueryBuilder();
            $r = $qb->select($qb->createFunction('1'))
                ->from('activity')
                ->setMaxResults(1)
                ->executeQuery();
            $r->fetch();
            $r->closeCursor();
            $this->appConfig->setValueString(Application::APP_ID, self::CFG_ACTIVITY_MISSING, '0');
            return true;
        } catch (\Throwable $e) {
            $this->appConfig->setValueString(Application::APP_ID, self::CFG_ACTIVITY_MISSING, '1');
            return false;
        }
    }

    // =========================================================================
    // Pass 1 — Circles activity mirror
    // =========================================================================

    /**
     * Mirror oc_activity rows with app='circles' since the last cursor.
     *
     * Subjects handled (locked down via DB inspection on Circles 33.0.0):
     *   member_added         -> member.joined
     *   member_circle_added  -> member.joined  (metadata.via_group=true)
     *   member_invited       -> invite.sent
     *   member_remove        -> member.removed
     *   member_left          -> member.left
     *   member_level         -> member.level_changed
     *   circle_delete        -> team.deleted
     *
     * Returns the number of rows inserted.
     */
    public function mirrorCircles(array $teamIdSet): int {
        if (empty($teamIdSet)) {
            return 0;
        }

        $cursor = (int)$this->appConfig->getValueString(Application::APP_ID, self::CFG_CURSOR_CIRCLES, '0');
        $rows   = $this->fetchActivityRows('circles', $cursor);
        $inserted = 0;
        $maxTs    = $cursor;

        // Index existing recent rows once, for the team.deleted dedup check.
        $teamIdLookup = array_flip($teamIdSet);

        foreach ($rows as $row) {
            $ts = (int)$row['timestamp'];
            if ($ts > $maxTs) {
                $maxTs = $ts;
            }

            $subject = (string)$row['subject'];
            $params  = $this->decodeSubjectParams((string)$row['subjectparams']);
            $teamId  = $params['circle']['singleId'] ?? null;

            // Skip rows that don't reference a team we know about — these are
            // for system circles, personal circles, group circles, etc.
            if (!is_string($teamId) || $teamId === '' || !isset($teamIdLookup[$teamId])) {
                continue;
            }

            $mapped = $this->mapCirclesSubject($subject);
            if ($mapped === null) {
                // Unknown subject — log once at debug level then skip.
                $this->logger->debug('[TeamHub][AuditIngestion] Unknown circles subject', [
                    'subject' => $subject,
                    'app'     => Application::APP_ID,
                ]);
                continue;
            }

            [$eventType, $extraMeta] = $mapped;

            // Dedup against direct-logged team.deleted within DEDUP_WINDOW_SECONDS.
            if ($eventType === 'team.deleted' && $this->hasRecentDirectLog($teamId, 'team.deleted', $ts)) {
                continue;
            }
            // Same dedup principle for join.approved → member_added pair.
            if ($eventType === 'member.joined' && $this->hasRecentDirectLog($teamId, 'join.approved', $ts)) {
                // join.approved already implies member.joined — keep only the more specific one.
                continue;
            }

            // Build metadata: actor info from the activity row + any subject-specific extras.
            $metadata = $extraMeta;

            // The 'member' subject param shape varies — capture the affected member's
            // identifier if present, useful for the audit-tab "target" column.
            $memberParam = $params['member'] ?? null;
            if (is_array($memberParam)) {
                $metadata['member_uid']         = (string)($memberParam['userId'] ?? '');
                $metadata['member_display']     = (string)($memberParam['displayName'] ?? '');
                $metadata['member_user_type']   = (int)($memberParam['type'] ?? 0);
            }

            // Some Circles subjects record the level change in subjectparams.member.level.
            if ($eventType === 'member.level_changed' && isset($params['member']['level'])) {
                $metadata['new_level'] = (int)$params['member']['level'];
            }

            $actorUid  = $row['user'] !== null && $row['user'] !== '' ? (string)$row['user'] : null;
            // For member-affecting events the affected member is the better target_id.
            $targetId  = (string)($metadata['member_uid'] ?? '');
            if ($targetId === '') {
                $targetId = $teamId;
            }
            $targetType = match (true) {
                str_starts_with($eventType, 'member.')
                || str_starts_with($eventType, 'invite.') => 'member',
                str_starts_with($eventType, 'team.')      => 'team',
                default                                    => null,
            };

            // Backfill created_at to the activity row's timestamp so the audit log
            // preserves chronology. We do this by passing the original timestamp
            // via metadata then updating in a single insert path.
            $this->insertWithBackdate(
                $teamId,
                $eventType,
                $actorUid,
                $targetType,
                $targetId,
                $metadata,
                $ts,
            );
            $inserted++;
        }

        // Advance cursor.
        if ($maxTs > $cursor) {
            $this->appConfig->setValueString(Application::APP_ID, self::CFG_CURSOR_CIRCLES, (string)$maxTs);
        }

        return $inserted;
    }

    /**
     * Map a Circles subject string to [eventType, extraMetadata].
     * Returns null for unknown subjects.
     *
     * @return array{0:string,1:array<string,mixed>}|null
     */
    private function mapCirclesSubject(string $subject): ?array {
        return match ($subject) {
            'member_added'        => ['member.joined',        []],
            'member_circle_added' => ['member.joined',        ['via_group' => true]],
            'member_invited'      => ['invite.sent',          []],
            'member_remove'       => ['member.removed',       []],
            'member_left'         => ['member.left',          []],
            'member_level'        => ['member.level_changed', []],
            'circle_delete'       => ['team.deleted',         []],
            default               => null,
        };
    }

    // =========================================================================
    // Pass 2 — Files activity mirror
    // =========================================================================

    /**
     * Mirror oc_activity rows with app='files' that affected a path under a
     * team-shared folder.
     *
     * Subjects handled (locked down via DB inspection on NC 33):
     *   created_self / created_by / created_public  -> file.created
     *   changed_self / changed_by                    -> file.edited
     *   deleted_self / deleted_by                    -> file.deleted
     *
     * (renamed_*, moved_*, added_favorite, removed_favorite are intentionally skipped.)
     *
     * @param array<string,string> $teamFolders  Map of [path_prefix => team_id].
     * @return int                                Rows inserted.
     */
    public function mirrorFiles(array $teamFolders): int {
        if (empty($teamFolders)) {
            return 0;
        }

        $cursor = (int)$this->appConfig->getValueString(Application::APP_ID, self::CFG_CURSOR_FILES, '0');
        $rows   = $this->fetchActivityRows('files', $cursor);
        $inserted = 0;
        $maxTs    = $cursor;

        foreach ($rows as $row) {
            $ts = (int)$row['timestamp'];
            if ($ts > $maxTs) {
                $maxTs = $ts;
            }

            $subject = (string)$row['subject'];
            $eventType = $this->mapFilesSubject($subject);
            if ($eventType === null) {
                continue;
            }

            // The path of the affected node lives in oc_activity.file as a
            // user-relative path like '/Shared/TeamFolder/foo.txt' on older NC
            // or in subjectparams on newer NC. We try the explicit column first.
            $relPath = (string)($row['file'] ?? '');
            if ($relPath === '') {
                // Fall back to subjectparams.files (NC30+ shape).
                $params = $this->decodeSubjectParams((string)$row['subjectparams']);
                if (isset($params['files']) && is_array($params['files'])) {
                    $first = reset($params['files']);
                    if (is_array($first) && isset($first['path'])) {
                        $relPath = (string)$first['path'];
                    } elseif (is_string($first)) {
                        $relPath = $first;
                    }
                }
            }
            if ($relPath === '') {
                continue;
            }

            // The activity row's path is user-relative; the share path stored
            // by us is also user-relative (built in buildTeamFolderMap). Match
            // is therefore a simple prefix string compare. Normalise by
            // ensuring leading slash.
            if ($relPath[0] !== '/') {
                $relPath = '/' . $relPath;
            }

            $teamId = $this->resolveTeamForPath($relPath, $teamFolders);
            if ($teamId === null) {
                continue;
            }

            $actorUid = $row['user'] !== null && $row['user'] !== '' ? (string)$row['user'] : null;

            $this->insertWithBackdate(
                $teamId,
                $eventType,
                $actorUid,
                'file',
                substr($relPath, 0, 255),
                ['path' => $relPath],
                $ts,
            );
            $inserted++;
        }

        if ($maxTs > $cursor) {
            $this->appConfig->setValueString(Application::APP_ID, self::CFG_CURSOR_FILES, (string)$maxTs);
        }

        return $inserted;
    }

    private function mapFilesSubject(string $subject): ?string {
        return match ($subject) {
            'created_self', 'created_by', 'created_public' => 'file.created',
            'changed_self', 'changed_by'                   => 'file.edited',
            'deleted_self', 'deleted_by'                   => 'file.deleted',
            default                                         => null,
        };
    }

    /**
     * Walk the team-folder map and find the longest matching prefix.
     * Longest-match is important when teams share nested folders.
     *
     * @param array<string,string> $teamFolders
     */
    private function resolveTeamForPath(string $path, array $teamFolders): ?string {
        $best = null;
        $bestLen = -1;
        foreach ($teamFolders as $prefix => $teamId) {
            $plen = strlen($prefix);
            if ($plen > $bestLen && (str_starts_with($path, $prefix . '/') || $path === $prefix)) {
                $best = $teamId;
                $bestLen = $plen;
            }
        }
        return $best;
    }

    // =========================================================================
    // Pass 3 — Share snapshot diff
    // =========================================================================

    /**
     * Diff current oc_share state for team circles against the previous snapshot.
     * Emits share.created / share.permissions_changed / share.deleted events.
     *
     * @param array<string,string> $teamIdMap  Map of team unique_id -> team_id (== unique_id; here for clarity).
     * @return int                              Rows inserted.
     */
    public function snapshotShares(array $teamIdMap): int {
        if (empty($teamIdMap)) {
            return 0;
        }

        $previous = $this->loadShareSnapshot();
        $current  = $this->collectCurrentShares(array_keys($teamIdMap));
        $now      = time();
        $inserted = 0;

        // Created and permissions-changed
        foreach ($current as $shareId => $cur) {
            $teamId = $cur['team_id'];
            if (!isset($previous[$shareId])) {
                $this->mapper->insert(
                    $teamId,
                    'share.created',
                    $cur['uid_initiator'],
                    'share',
                    (string)$shareId,
                    [
                        'path'        => $cur['path'],
                        'permissions' => $cur['permissions'],
                        'item_type'   => $cur['item_type'],
                    ],
                );
                $inserted++;
                continue;
            }

            $prev = $previous[$shareId];
            // Compare permissions and expiration only — those are the audit-relevant deltas.
            if (
                $prev['permissions'] !== $cur['permissions']
                || ($prev['expiration'] ?? null) !== ($cur['expiration'] ?? null)
            ) {
                $this->mapper->insert(
                    $teamId,
                    'share.permissions_changed',
                    null,                            // actor unknown for snapshot diffs
                    'share',
                    (string)$shareId,
                    [
                        'path'    => $cur['path'],
                        'changed' => [
                            'permissions' => ['old' => $prev['permissions'], 'new' => $cur['permissions']],
                            'expiration'  => ['old' => $prev['expiration'] ?? null, 'new' => $cur['expiration'] ?? null],
                        ],
                    ],
                );
                $inserted++;
            }
        }

        // Deleted
        foreach ($previous as $shareId => $prev) {
            if (!isset($current[$shareId])) {
                $this->mapper->insert(
                    (string)$prev['team_id'],
                    'share.deleted',
                    null,
                    'share',
                    (string)$shareId,
                    ['path' => $prev['path'] ?? null],
                );
                $inserted++;
            }
        }

        // Persist the new snapshot.
        $this->saveShareSnapshot($current);
        return $inserted;
    }

    /**
     * Build the current snapshot by querying oc_share for share_type=7 rows
     * targeting team unique_ids.
     *
     * @param string[] $teamIds
     * @return array<int,array<string,mixed>>
     */
    private function collectCurrentShares(array $teamIds): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'share_with', 'permissions', 'expiration', 'uid_initiator', 'item_type', 'file_target')
            ->from('share')
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in(
                'share_with',
                $qb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $sid = (int)$row['id'];
            $rows[$sid] = [
                'team_id'       => (string)$row['share_with'],
                'permissions'   => (int)$row['permissions'],
                'expiration'    => $row['expiration'] !== null ? (string)$row['expiration'] : null,
                'uid_initiator' => $row['uid_initiator'] !== null ? (string)$row['uid_initiator'] : null,
                'item_type'     => $row['item_type'] !== null ? (string)$row['item_type'] : null,
                'path'          => $row['file_target'] !== null ? (string)$row['file_target'] : null,
            ];
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Build the prefix → team_id map used by mirrorFiles().
     * Keyed by '/Shared/Folder' style paths (user-relative) so it matches
     * what oc_activity records as the file path.
     *
     * @param string[] $teamIds
     * @return array<string,string>
     */
    public function buildTeamFolderMap(array $teamIds): array {
        if (empty($teamIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('share_with', 'file_target')
            ->from('share')
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in(
                'share_with',
                $qb->createNamedParameter($teamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $target = $row['file_target'] !== null ? (string)$row['file_target'] : '';
            $teamId = (string)$row['share_with'];
            if ($target === '' || $teamId === '') {
                continue;
            }
            // file_target is recipient-relative. Real-world activity rows record
            // /<recipient-folder> too, so prefix-match is consistent.
            if ($target[0] !== '/') {
                $target = '/' . $target;
            }
            // If multiple shares to the same team for the same path exist we
            // simply overwrite — same team_id either way.
            $map[$target] = $teamId;
        }
        $result->closeCursor();
        return $map;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Pull the current set of TeamHub team unique_ids (real user-created teams only).
     * Used by every pass to scope the work.
     *
     * @return string[]
     */
    public function listTeamIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('unique_id', 'name')
            ->from('circles_circle');

        $result = $qb->executeQuery();
        $teams  = [];
        $systemPrefixes = ['user:', 'group:', 'mail:', 'app:occ:', 'contact:'];
        while ($row = $result->fetch()) {
            $name = (string)($row['name'] ?? '');
            $skip = false;
            foreach ($systemPrefixes as $p) {
                if (str_starts_with($name, $p)) { $skip = true; break; }
            }
            if (!$skip) {
                $teams[] = (string)$row['unique_id'];
            }
        }
        $result->closeCursor();
        return $teams;
    }

    /**
     * Fetch oc_activity rows for a given app since the cursor.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchActivityRows(string $app, int $cursor): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user', 'subject', 'subjectparams', 'timestamp', 'file', 'object_id', 'object_type')
            ->from('activity')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter($app)))
            ->andWhere($qb->expr()->gt('timestamp', $qb->createNamedParameter($cursor, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('timestamp', 'ASC')
            ->setMaxResults(self::MAX_ROWS_PER_PASS);

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Decode an oc_activity subjectparams JSON blob safely.
     *
     * @return array<string,mixed>
     */
    private function decodeSubjectParams(string $raw): array {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Check whether a recent direct-logged audit row exists for the given
     * (team, event_type) within DEDUP_WINDOW_SECONDS of $ts.
     */
    private function hasRecentDirectLog(string $teamId, string $eventType, int $ts): bool {
        $low  = $ts - self::DEDUP_WINDOW_SECONDS;
        $high = $ts + self::DEDUP_WINDOW_SECONDS;
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('1'))
            ->from('teamhub_audit_log')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($low,  \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($high, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $hit = $result->fetch();
        $result->closeCursor();
        return $hit !== false;
    }

    /**
     * Insert via the mapper's explicit-timestamp path so mirrored events keep
     * their original chronological position rather than the (much later)
     * ingestion time. The immutability contract is preserved — this is still
     * insert-only, no existing row is touched.
     */
    private function insertWithBackdate(
        string $teamId,
        string $eventType,
        ?string $actorUid,
        ?string $targetType,
        ?string $targetId,
        ?array $metadata,
        int $createdAt,
    ): void {
        $this->mapper->insertWithTimestamp(
            $teamId,
            $eventType,
            $actorUid,
            $targetType,
            $targetId,
            $metadata,
            $createdAt,
        );
    }

    private function loadShareSnapshot(): array {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::CFG_SHARE_SNAPSHOT, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveShareSnapshot(array $snapshot): void {
        $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        $this->appConfig->setValueString(Application::APP_ID, self::CFG_SHARE_SNAPSHOT, $encoded);
    }
}
