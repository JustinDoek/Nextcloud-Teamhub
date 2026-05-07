<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\PendingDeletion;
use OCA\TeamHub\Db\PendingDeletionMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates team archiving: capture, bundle, store, then soft- or hard-delete.
 *
 * Entry point: produceTeamArchive(string $teamId): array
 *
 * Operation order (see inline comments):
 *   1. Auth — owner only (level 9)
 *   2. Pre-check — no duplicate pending row
 *   3. Read admin settings
 *   4. Resolve NC Files output location
 *   5. Capture team metadata (before any destructive step)
 *   6. Estimate bundle size
 *   7. Insert pending_dels row (team is now hidden)
 *   8. Create .partial working directory
 *   9. Write circles/ layer
 *  10. Write teamhub/ layer
 *  11. Write index.html
 *  12. Build and write manifest.json
 *  13. Zip atomically
 *  14. Move ZIP into NC Files; update pending_dels row
 *  15. Audit-log team.archived
 *  16. Hard-delete or leave for cron (mode-dependent)
 *
 * On any failure in steps 8–13: the .partial dir is deleted, the pending_dels
 * row is set to 'failed', the team is NOT deleted.
 */
class ArchiveService {

    public const ARCHIVE_FORMAT_VERSION = '1.0';

    /** Admin config keys */
    private const CFG_MODE         = 'archiveMode';
    private const CFG_PATH         = 'archivePath';    // Team Folder /f/{id} link or empty (fallback)
    private const CFG_MAX_BYTES    = 'archiveMaxBytes';
    private const CFG_PSEUDONYMIZE = 'anonymizeData';

    private const DEFAULT_MODE      = 'soft30';
    private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024 * 1024; // 5 GB

    public function __construct(
        private PendingDeletionMapper  $pendingMapper,
        private ArchiveBundleWriter    $writer,
        private MemberService          $memberService,
        private TeamService            $teamService,
        private AuditService           $auditService,
        private IUserSession           $userSession,
        private IDBConnection          $db,
        private IConfig                $config,
        private ITimeFactory           $timeFactory,
        private ContainerInterface     $container,
        private LoggerInterface        $logger,
        private IAppManager            $appManager,
        private TalkService            $talkService,
        private FilesService           $filesService,
        private CalendarService        $calendarService,
        private DeckService            $deckService,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Produce a complete team archive and register it in teamhub_pending_dels.
     *
     * Returns the pending-deletion row as an array on success.
     *
     * @throws \Exception on auth failure, duplicate request, size cap exceeded,
     *                    or archive production failure.
     */
    public function produceTeamArchive(string $teamId): array {

        // ── 1. Auth ─────────────────────────────────────────────────────────
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated.');
        }
        $uid = $user->getUID();

        $level = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $uid);
        if ($level < 9) {
            throw new \Exception('Only the team owner can archive a team.');
        }

        // ── 2. Pre-check ────────────────────────────────────────────────────
        $existing = $this->pendingMapper->findByTeamId($teamId);
        if ($existing !== null) {
            if ($existing->getStatus() === 'pending') {
                throw new \RuntimeException(
                    'This team is already pending deletion. Administrators can restore or force-delete it.',
                    409
                );
            }
            if ($existing->getStatus() === 'completed') {
                throw new \RuntimeException(
                    'This team has already been permanently deleted.',
                    409
                );
            }
            // status='failed' or 'restored' — clear the stale row and allow retry.
            $this->pendingMapper->deleteByTeamId($teamId);
        }

        // ── 3. Read admin settings ───────────────────────────────────────────
        $mode        = $this->config->getAppValue(Application::APP_ID, self::CFG_MODE, self::DEFAULT_MODE);
        $archPath    = $this->config->getAppValue(Application::APP_ID, self::CFG_PATH, '');
        $maxBytes    = (int)$this->config->getAppValue(
            Application::APP_ID, self::CFG_MAX_BYTES, (string)self::DEFAULT_MAX_BYTES
        );
        $pseudonymize = $this->config->getAppValue(Application::APP_ID, self::CFG_PSEUDONYMIZE, '0') === '1';

        $this->logger->debug('[TeamHub][ArchiveService] Starting archive', [
            'teamId' => $teamId, 'uid' => $uid, 'mode' => $mode,
            'pseudonymize' => $pseudonymize, 'app' => Application::APP_ID,
        ]);

        // ── 4. Resolve NC Files output location ──────────────────────────────
        // Resolved early so a bad config fails before any data is captured.
        [$archiveFolder, $archiveDisplayPath] = $this->resolveArchiveFolder($archPath, $uid);

        // ── 5. Capture team metadata BEFORE any destructive step ─────────────
        $teamMeta      = $this->captureTeamMeta($teamId);
        $members       = $this->captureMembers($teamId);
        $effectiveUsers = $this->captureEffectiveUsers($teamId);

        $teamName = $teamMeta['name'] ?? 'unknown-team';

        // ── 6. Estimate bundle size and check against all ceilings ───────────
        // estimateBundleSize() includes: TeamHub DB rows (rough) + actual Files
        // folder size from oc_filecache. The DB portion is deliberately generous.
        $dbEstimate     = $this->estimateBundleSize($teamId);
        $folderSize     = $this->estimateFolderSize($teamId);
        $estimatedBytes = $dbEstimate; // folder size already included inside estimateBundleSize

        // Measure free space at the archive destination.
        // Uses the local filesystem path of the resolved NC Files folder.
        // If the destination is on external storage we can't measure it — log and skip.
        $freeBytes          = null;  // null = could not determine
        $freeSpaceCheckPath = null;
        try {
            $destStorage   = $archiveFolder->getStorage();
            $destLocalPath = $destStorage->getLocalFile($archiveFolder->getInternalPath());
            if ($destLocalPath !== null) {
                $freeSpaceCheckPath = $destLocalPath;
                $measured           = disk_free_space($destLocalPath);
                $freeBytes          = ($measured !== false) ? (int)$measured : null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not measure destination free space', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Determine the effective ceiling: the more restrictive of admin cap and free space.
        // A 10% safety buffer is subtracted from free space to avoid racing with other writes.
        $freeBytesSafe     = ($freeBytes !== null) ? (int)($freeBytes * 0.90) : null;
        $effectiveCeiling  = $maxBytes;
        $ceilingSource     = 'configured limit';

        if ($freeBytesSafe !== null && $freeBytesSafe < $maxBytes) {
            $effectiveCeiling = $freeBytesSafe;
            $ceilingSource    = 'available disk space at destination';
        }

        if ($estimatedBytes > $effectiveCeiling) {
            $this->logger->warning('[TeamHub][ArchiveService] Pre-flight size check failed', [
                'teamId'          => $teamId,
                'estimated'       => $estimatedBytes,
                'folder'          => $folderSize,
                'cap'             => $maxBytes,
                'freeBytes'       => $freeBytes,
                'freeBytesSafe'   => $freeBytesSafe,
                'effectiveCeil'   => $effectiveCeiling,
                'ceilingSource'   => $ceilingSource,
                'app'             => Application::APP_ID,
            ]);

            // Build a specific, actionable message telling the owner exactly
            // which constraint was hit and how much space they need to free up.
            if ($ceilingSource === 'available disk space at destination') {
                $msg = sprintf(
                    'The estimated archive size (%.1f MB) exceeds the available space at the archive destination (%.1f MB free, %.1f MB usable after safety buffer). ' .
                    'The team\'s shared folder accounts for %.1f MB of this total. ' .
                    'Please contact your administrator to free up space at the archive destination or choose a different archive location.',
                    $estimatedBytes / 1048576,
                    $freeBytes / 1048576,
                    $freeBytesSafe / 1048576,
                    $folderSize / 1048576
                );
            } else {
                $msg = sprintf(
                    'The estimated archive size (%.1f MB) exceeds the configured limit (%.1f MB). ' .
                    'The team\'s shared folder accounts for %.1f MB of this total. ' .
                    'Please contact your administrator to raise the archive size limit or reduce the amount of data in the team folder before archiving.',
                    $estimatedBytes / 1048576,
                    $maxBytes / 1048576,
                    $folderSize / 1048576
                );
            }

            throw new \RuntimeException($msg, 413);
        }

        // ── 7. Insert pending_dels row — team is now hidden ───────────────────
        $now          = $this->timeFactory->getTime();
        $graceSeconds = $this->graceSeconds($mode);

        $pending = new PendingDeletion();
        $pending->setTeamId($teamId);
        $pending->setTeamName($teamName);
        $pending->setArchivedAt($now);
        $pending->setHardDeleteAt($now + $graceSeconds);
        $pending->setArchivedBy($uid);
        $pending->setStatus('pending');
        $this->pendingMapper->insert($pending);

        $this->logger->debug('[TeamHub][ArchiveService] Pending-deletion row inserted', [
            'teamId' => $teamId, 'hardDeleteAt' => $pending->getHardDeleteAt(), 'app' => Application::APP_ID,
        ]);

        // ── 8–14: Produce the archive (guarded: failure rolls back to 'failed') ─
        $workDir = null;
        try {
            [$zipPath, $archiveSizeBytes] = $this->buildAndStoreArchive(
                $teamId, $teamName, $teamMeta, $members, $effectiveUsers,
                $archiveFolder, $archiveDisplayPath, $pseudonymize
            );

            // ── 14b. Update pending_dels row with archive location ─────────
            $pending->setArchivePath($zipPath);
            $pending->setArchiveBytes($archiveSizeBytes);
            $this->pendingMapper->update($pending);

            $this->logger->debug('[TeamHub][ArchiveService] Archive stored in NC Files', [
                'teamId' => $teamId, 'path' => $zipPath, 'bytes' => $archiveSizeBytes,
                'app' => Application::APP_ID,
            ]);

        } catch (\Throwable $e) {
            // Archive production failed — mark the row failed so the team
            // becomes visible again (callers of isTeamPendingDeletion check
            // only status='pending').
            $pending->setStatus('failed');
            $pending->setFailureReason($e->getMessage());
            $this->pendingMapper->update($pending);

            $this->logger->error('[TeamHub][ArchiveService] Archive production failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);

            throw new \RuntimeException(
                'Archive production failed: ' . $e->getMessage(),
                500,
                $e
            );
        }

        // ── 15. Suspend connected app resources (soft-delete only) ───────────
        // For hard-delete mode, deleteTeam() at step 16 destroys resources fully.
        // For soft modes we remove the team circle from each connected app so
        // members lose access immediately, while content stays intact for restore.
        // The IDs needed to re-add access are stored in suspended_resources JSON.
        if ($mode !== 'hard') {
            $suspended = $this->suspendConnectedAppResources($teamId, $teamName);
            if (!empty($suspended)) {
                $pending->setSuspendedResources(json_encode($suspended));
                $this->pendingMapper->update($pending);
            }
        }

        // ── 16. Audit-log team.archived ───────────────────────────────────────
        $this->auditService->log(
            $teamId,
            'team.archived',
            $uid,
            'team',
            $teamId,
            [
                'name'         => $teamName,
                'archive_path' => $zipPath,
                'archive_bytes'=> $archiveSizeBytes,
                'mode'         => $mode,
                'pseudonymized'=> $pseudonymize,
            ]
        );

        // ── 17. Hard-delete immediately if mode=hard ──────────────────────────
        if ($mode === 'hard') {
            try {
                $this->teamService->deleteTeam($teamId);
                $pending->setStatus('completed');
                $this->pendingMapper->update($pending);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ArchiveService] Hard-delete failed after archive', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                // Archive exists and row is marked pending — cron will retry.
            }
        }

        return $this->pendingToArray($pending);
    }

    /**
     * Restore a pending-deletion team within the grace period.
     * Admin-only — caller must verify NC admin before calling this.
     */
    public function restorePendingDeletion(int $pendingId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_pending_dels')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row === false) {
            throw new \RuntimeException('Pending deletion not found.', 404);
        }
        if ($row['status'] !== 'pending') {
            throw new \RuntimeException('Only pending deletions can be restored.', 409);
        }

        // Update status to 'restored' — removes the team from the hidden list.
        $uqb = $this->db->getQueryBuilder();
        $uqb->update('teamhub_pending_dels')
            ->set('status', $uqb->createNamedParameter('restored'))
            ->where($uqb->expr()->eq('id', $uqb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $uqb->executeStatement();

        // ── Resume connected app resources ───────────────────────────────────
        // Re-add the team circle to each suspended app resource so members
        // regain access. Uses the stored suspended_resources JSON blob.
        if (!empty($row['suspended_resources'])) {
            try {
                $suspended = json_decode((string)$row['suspended_resources'], true, 8, JSON_THROW_ON_ERROR);
                $this->resumeConnectedAppResources($row['team_id'], $row['team_name'], $suspended);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ArchiveService] Failed to resume app resources on restore', [
                    'teamId' => $row['team_id'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                // Non-fatal — team is restored in TeamHub regardless.
                // Admin may need to manually re-share resources.
            }
        }

        // ── Audit log ────────────────────────────────────────────────────────
        $adminUid = $this->userSession->getUser()?->getUID() ?? 'system';
        $this->auditService->log(
            $row['team_id'],
            'team.restored',
            $adminUid,
            'team',
            $row['team_id'],
            [
                'name'        => $row['team_name'],
                'restored_by' => $adminUid,
                'was_mode'    => $row['status'],
            ]
        );

        $this->logger->debug('[TeamHub][ArchiveService] Team restored from pending deletion', [
            'teamId' => $row['team_id'], 'app' => Application::APP_ID,
        ]);

        return ['restored' => true, 'teamId' => $row['team_id'], 'teamName' => $row['team_name']];
    }

    /**
     * Discard a failed archive attempt without touching the team.
     * Drops the pending_dels row so the team becomes fully usable again.
     * Admin-only — caller must verify NC admin before calling this.
     */
    public function discardFailedArchive(int $pendingId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_pending_dels')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row === false) {
            throw new \RuntimeException('Pending deletion not found.', 404);
        }
        if ($row['status'] !== 'failed') {
            throw new \RuntimeException('Only failed archive rows can be discarded this way.', 409);
        }

        $this->pendingMapper->deleteByTeamId($row['team_id']);

        $adminUid = $this->userSession->getUser()?->getUID() ?? 'system';
        $this->auditService->log(
            $row['team_id'],
            'team.archive_discarded',
            $adminUid,
            'team',
            $row['team_id'],
            ['name' => $row['team_name'], 'discarded_by' => $adminUid]
        );

        $this->logger->debug('[TeamHub][ArchiveService] Failed archive row discarded', [
            'teamId' => $row['team_id'], 'app' => Application::APP_ID,
        ]);

        return ['discarded' => true, 'teamId' => $row['team_id'], 'teamName' => $row['team_name']];
    }

    /**
     * Admin-initiated retry of a failed archive.
     * Clears the failed row and re-runs produceTeamArchive, bypassing the
     * owner-level check. The admin's UID is recorded as archived_by.
     * Admin-only — caller must verify NC admin before calling this.
     */
    public function retryArchive(int $pendingId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_pending_dels')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row === false) {
            throw new \RuntimeException('Pending deletion not found.', 404);
        }
        if ($row['status'] !== 'failed') {
            throw new \RuntimeException('Only failed archive rows can be retried.', 409);
        }

        $adminUid     = $this->userSession->getUser()?->getUID() ?? 'system';
        $originalOwner = $row['archived_by'];

        // Drop the failed row — produceTeamArchive's pre-check would
        // otherwise refuse to run since a row already exists for this team.
        $this->pendingMapper->deleteByTeamId($row['team_id']);

        // Re-run the full archive, but skip the owner-level gate by calling
        // the internal method directly with the original owner's UID as context.
        // We temporarily impersonate context so the archive is written to the
        // correct Files location — but record the admin as the actor.
        $result = $this->produceTeamArchiveAsAdmin($row['team_id'], $originalOwner, $adminUid);

        return $result;
    }

    /**
     * Internal: run archive without owner-level gate.
     * Used by admin retry — does not check caller level.
     */
    private function produceTeamArchiveAsAdmin(
        string $teamId,
        string $originalOwner,
        string $adminUid
    ): array {
        // ── Read settings ────────────────────────────────────────────────────
        $mode         = $this->config->getAppValue(Application::APP_ID, self::CFG_MODE, self::DEFAULT_MODE);
        $archPath     = $this->config->getAppValue(Application::APP_ID, self::CFG_PATH, '');
        $maxBytes     = (int)$this->config->getAppValue(
            Application::APP_ID, self::CFG_MAX_BYTES, (string)self::DEFAULT_MAX_BYTES
        );
        $pseudonymize = $this->config->getAppValue(Application::APP_ID, self::CFG_PSEUDONYMIZE, '0') === '1';

        // ── Resolve location ─────────────────────────────────────────────────
        // Use original owner as fallback UID if no admin-configured location.
        [$archiveFolder, $archiveDisplayPath] = $this->resolveArchiveFolder($archPath, $originalOwner);

        // ── Capture metadata ─────────────────────────────────────────────────
        $teamMeta       = $this->captureTeamMeta($teamId);
        $members        = $this->captureMembers($teamId);
        $effectiveUsers = $this->captureEffectiveUsers($teamId);
        $teamName       = $teamMeta['name'] ?? 'unknown-team';

        // ── Size check (cap + destination free space) ────────────────────────
        $estimatedBytes = $this->estimateBundleSize($teamId);
        $folderSize     = $this->estimateFolderSize($teamId);

        $freeBytes = null;
        try {
            $destStorage   = $archiveFolder->getStorage();
            $destLocalPath = $destStorage->getLocalFile($archiveFolder->getInternalPath());
            if ($destLocalPath !== null) {
                $measured  = disk_free_space($destLocalPath);
                $freeBytes = ($measured !== false) ? (int)$measured : null;
            }
        } catch (\Throwable) {}

        $freeBytesSafe    = ($freeBytes !== null) ? (int)($freeBytes * 0.90) : null;
        $effectiveCeiling = $maxBytes;
        $ceilingSource    = 'configured limit';
        if ($freeBytesSafe !== null && $freeBytesSafe < $maxBytes) {
            $effectiveCeiling = $freeBytesSafe;
            $ceilingSource    = 'available disk space at destination';
        }

        if ($estimatedBytes > $effectiveCeiling) {
            if ($ceilingSource === 'available disk space at destination') {
                throw new \RuntimeException(
                    sprintf('Estimated archive size (%.1f MB) exceeds available destination space (%.1f MB usable).',
                        $estimatedBytes / 1048576, $freeBytesSafe / 1048576),
                    413
                );
            }
            throw new \RuntimeException(
                sprintf('Estimated archive size (%.1f MB) exceeds the configured limit (%.1f MB).',
                    $estimatedBytes / 1048576, $maxBytes / 1048576),
                413
            );
        }

        // ── Insert pending row (actor = admin) ───────────────────────────────
        $now          = $this->timeFactory->getTime();
        $graceSeconds = $this->graceSeconds($mode);

        $pending = new PendingDeletion();
        $pending->setTeamId($teamId);
        $pending->setTeamName($teamName);
        $pending->setArchivedAt($now);
        $pending->setHardDeleteAt($now + $graceSeconds);
        $pending->setArchivedBy($adminUid);
        $pending->setStatus('pending');
        $this->pendingMapper->insert($pending);

        // ── Build archive ────────────────────────────────────────────────────
        try {
            [$zipPath, $archiveSizeBytes] = $this->buildAndStoreArchive(
                $teamId, $teamName, $teamMeta, $members, $effectiveUsers,
                $archiveFolder, $archiveDisplayPath, $pseudonymize
            );

            $pending->setArchivePath($zipPath);
            $pending->setArchiveBytes($archiveSizeBytes);
            $this->pendingMapper->update($pending);
        } catch (\Throwable $e) {
            $pending->setStatus('failed');
            $pending->setFailureReason($e->getMessage());
            $this->pendingMapper->update($pending);
            throw new \RuntimeException('Archive production failed: ' . $e->getMessage(), 500, $e);
        }

        // ── Audit log ────────────────────────────────────────────────────────
        $this->auditService->log(
            $teamId, 'team.archived', $adminUid, 'team', $teamId,
            [
                'name'           => $teamName,
                'archive_path'   => $zipPath,
                'archive_bytes'  => $archiveSizeBytes,
                'mode'           => $mode,
                'pseudonymized'  => $pseudonymize,
                'original_owner' => $originalOwner,
                'retried_by'     => $adminUid,
            ]
        );

        // ── Hard-delete if mode=hard ─────────────────────────────────────────
        if ($mode === 'hard') {
            try {
                $this->teamService->deleteTeam($teamId);
                $pending->setStatus('completed');
                $this->pendingMapper->update($pending);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ArchiveService] Hard-delete after admin retry failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        return $this->pendingToArray($pending);
    }

    /**
     * Immediately hard-delete a pending-deletion team (admin force-purge).
     * Admin-only — caller must verify NC admin before calling this.
     */
    public function purgePendingDeletion(int $pendingId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_pending_dels')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if ($row === false) {
            throw new \RuntimeException('Pending deletion not found.', 404);
        }

        try {
            $this->teamService->deleteTeam($row['team_id']);
        } catch (\Throwable $e) {
            // Circle may already be gone — log and continue to mark completed.
            $this->logger->warning('[TeamHub][ArchiveService] purgePendingDeletion: deleteTeam failed', [
                'teamId' => $row['team_id'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $uqb = $this->db->getQueryBuilder();
        $uqb->update('teamhub_pending_dels')
            ->set('status', $uqb->createNamedParameter('completed'))
            ->where($uqb->expr()->eq('id', $uqb->createNamedParameter($pendingId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $uqb->executeStatement();

        $this->logger->debug('[TeamHub][ArchiveService] Team force-purged', [
            'teamId' => $row['team_id'], 'app' => Application::APP_ID,
        ]);

        return ['purged' => true, 'teamId' => $row['team_id'], 'teamName' => $row['team_name']];
    }

    /**
     * Return the admin-facing archive settings.
     */
    public function getAdminSettings(): array {
        return [
            'archiveMode'     => $this->config->getAppValue(Application::APP_ID, self::CFG_MODE, self::DEFAULT_MODE),
            'archiveLocation' => $this->config->getAppValue(Application::APP_ID, self::CFG_PATH, ''),
            'archiveMaxMb'    => (int)round(
                (int)$this->config->getAppValue(Application::APP_ID, self::CFG_MAX_BYTES, (string)self::DEFAULT_MAX_BYTES)
                / 1048576
            ),
            'anonymizeData'   => $this->config->getAppValue(Application::APP_ID, self::CFG_PSEUDONYMIZE, '0') === '1',
        ];
    }

    /**
     * Save admin archive settings.
     *
     * @param array<string, mixed> $data
     */
    public function saveAdminSettings(array $data): void {
        $allowedModes = ['hard', 'soft30', 'soft60'];

        if (isset($data['archiveMode']) && in_array($data['archiveMode'], $allowedModes, true)) {
            $this->config->setAppValue(Application::APP_ID, self::CFG_MODE, $data['archiveMode']);
        }
        if (isset($data['archiveLocation'])) {
            $this->config->setAppValue(Application::APP_ID, self::CFG_PATH, trim((string)$data['archiveLocation']));
        }
        if (isset($data['archiveMaxMb'])) {
            $mb = max(1, min(50 * 1024, (int)$data['archiveMaxMb'])); // 1 MB–50 GB
            $this->config->setAppValue(Application::APP_ID, self::CFG_MAX_BYTES, (string)($mb * 1048576));
        }
        if (isset($data['anonymizeData'])) {
            $this->config->setAppValue(
                Application::APP_ID,
                self::CFG_PSEUDONYMIZE,
                ((bool)$data['anonymizeData']) ? '1' : '0'
            );
        }
    }

    /**
     * Return the pending-deletion row for a specific team, or null if none exists.
     * Used by the owner-facing danger zone to surface failed/pending state.
     *
     * @return array<string, mixed>|null
     */
    public function getTeamArchiveStatus(string $teamId): ?array {
        $row = $this->pendingMapper->findByTeamId($teamId);
        if ($row === null) {
            return null;
        }
        return $this->pendingToArray($row);
    }

    /**
     * List all pending-deletion rows for the admin panel.
     *
     * @return array{rows: array<mixed>, total: int}
     */
    public function listPendingDeletions(int $limit = 50, int $offset = 0): array {
        $rows  = $this->pendingMapper->listAll($limit, $offset);
        $total = $this->pendingMapper->countAll();
        $now   = $this->timeFactory->getTime();

        return [
            'rows'  => array_map(fn($p) => $this->pendingToArray($p, $now), $rows),
            'total' => $total,
        ];
    }

    // =========================================================================
    // Internal — archive production
    // =========================================================================

    /**
     * Build the archive in a temp dir, zip it, and move it into NC Files.
     * Returns [display path string, bytes].
     */
    private function buildAndStoreArchive(
        string $teamId,
        string $teamName,
        array $teamMeta,
        array $members,
        array $effectiveUsers,
        \OCP\Files\Folder $archiveFolder,
        string $archiveDisplayPath,
        bool $pseudonymize
    ): array {
        $ps      = $pseudonymize ? new ArchivePseudonymizer() : null;
        $tempDir = sys_get_temp_dir();
        $workDir = $this->writer->createWorkDir($tempDir, $teamName);
        $entries = ['teamhub' => [], 'circles' => [], 'apps' => []];
        $incomplete       = false;
        $incompleteReason = null;

        try {
            // ── 9. Circles layer ─────────────────────────────────────────────
            // Each file is independently try-caught — a failed circles write
            // marks the bundle incomplete but does not abort the archive.
            foreach ([
                ['circles/team.json',            $teamMeta,      [],                           true],
                ['circles/members.json',          $members,       ['uid', 'user_id', 'single_id'], false],
                ['circles/effective_users.json',  $effectiveUsers,['user_id', 'single_id'],    false],
            ] as [$circlePath, $data, $uidFields, $isObject]) {
                try {
                    if ($isObject) {
                        $entries['circles'][] = $this->writer->writeJsonObject($workDir, $circlePath, $data);
                    } else {
                        $entries['circles'][] = $this->writer->writeJson($workDir, $circlePath, $data, $uidFields, $ps);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ArchiveService] Circles layer write failed', [
                        'path' => $circlePath, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                    $incomplete       = true;
                    $incompleteReason = "Failed to write {$circlePath}: " . $e->getMessage();
                }
            }

            // ── 10. TeamHub layer ─────────────────────────────────────────────
            $teamhubWriters = [
                ['teamhub/team.json',         [$this, 'readTeamConfig'],    ['team_id'],  true],
                ['teamhub/messages.json',      [$this, 'readMessages'],      ['author_id'], false],
                ['teamhub/comments.json',      [$this, 'readComments'],      ['author_id'], false],
                ['teamhub/poll_votes.json',    [$this, 'readPollVotes'],     ['user_id'],  false],
                ['teamhub/web_links.json',     [$this, 'readWebLinks'],      [],           false],
                ['teamhub/layouts.json',       [$this, 'readLayouts'],       ['user_id'],  false],
                ['teamhub/integrations.json',  [$this, 'readIntegrations'],  [],           false],
                ['teamhub/audit_log.json',     [$this, 'readAuditLog'],      ['actor_uid'], false],
            ];

            foreach ($teamhubWriters as [$path, $reader, $uidFields, $isObject]) {
                try {
                    $data = $reader($teamId);
                    if ($isObject) {
                        $entry = $this->writer->writeJsonObject($workDir, $path, $data);
                    } else {
                        $entry = $this->writer->writeJson($workDir, $path, $data, $uidFields, $ps);
                    }
                    $entries['teamhub'][] = $entry;
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][ArchiveService] Layer write failed', [
                        'path' => $path, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                    $incomplete       = true;
                    $incompleteReason = "Failed to write {$path}: " . $e->getMessage();
                }
            }

            // Handle audit log pseudonymization of metadata JSON field separately.
            // The writeJson call above replaced actor_uid; now process metadata blobs.
            if ($ps !== null) {
                $this->pseudonymizeAuditMetadata($workDir, $ps);
            }

            // ── 10b. Calendar layer ───────────────────────────────────────────
            // Independently try-caught — a missing or broken calendar never
            // aborts the rest of the archive.
            try {
                $calEntries = $this->extractCalendarData($teamId, $workDir, $ps);
                foreach ($calEntries as $entry) {
                    $entries['apps'][] = $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Calendar extraction failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $incomplete       = true;
                $incompleteReason = ($incompleteReason ?? '') . ' | Calendar: ' . $e->getMessage();
            }

            // ── 10c. Files layer ──────────────────────────────────────────────
            // Copies the team's shared folder into apps/files/ in the work dir.
            // The share and folder are left completely intact — no permission
            // changes during the grace period. Hard delete happens at expiry.
            try {
                $fileEntries = $this->extractFilesData($teamId, $teamName, $workDir);
                foreach ($fileEntries as $entry) {
                    $entries['apps'][] = $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Files extraction failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $incomplete       = true;
                $incompleteReason = ($incompleteReason ?? '') . ' | Files: ' . $e->getMessage();
            }

            // ── 10d. Talk layer ───────────────────────────────────────────────
            // Extracts the Talk room's full message history from oc_comments.
            // Must run before suspension (step 15) while the circle attendee
            // row still exists to locate the room.
            try {
                $talkEntries = $this->extractTalkData($teamId, $workDir, $ps);
                foreach ($talkEntries as $entry) {
                    $entries['apps'][] = $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Talk extraction failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $incomplete       = true;
                $incompleteReason = ($incompleteReason ?? '') . ' | Talk: ' . $e->getMessage();
            }

            // ── 10e. Deck layer ───────────────────────────────────────────────
            // Extracts the full Deck board: stacks, cards, labels, assignees,
            // and card comments. Must run before suspension while the circle
            // ACL row still exists to locate the board.
            try {
                $deckEntries = $this->extractDeckData($teamId, $workDir, $ps);
                foreach ($deckEntries as $entry) {
                    $entries['apps'][] = $entry;
                }
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][ArchiveService] Deck extraction failed', [
                    'teamId' => $teamId,
                    'error'  => $e->getMessage(),
                    'trace'  => substr($e->getTraceAsString(), 0, 500),
                    'app'    => Application::APP_ID,
                ]);
                $incomplete       = true;
                $incompleteReason = ($incompleteReason ?? '') . ' | Deck: ' . $e->getMessage();
            }

            // ── 11. index.html ────────────────────────────────────────────────
            $this->writer->writeIndexHtml($workDir, $teamName, $pseudonymize);

            // ── 12. Manifest ──────────────────────────────────────────────────
            $ncVersion  = $this->config->getSystemValue('version', '0.0.0');
            $appVersion = $this->appManager->getAppVersion('teamhub');
            $totalBytes  = array_sum(array_map(
                fn($e) => $e['bytes'],
                array_merge($entries['teamhub'], $entries['circles'], $entries['apps'])
            ));

            $manifest = [
                'format_version'   => self::ARCHIVE_FORMAT_VERSION,
                'anonymized'       => $pseudonymize,
                'produced_by'      => [
                    'app'         => Application::APP_ID,
                    'app_version' => $appVersion,
                    'nc_version'  => $ncVersion,
                ],
                'team' => [
                    'team_id'     => $teamId,
                    'team_name'   => $teamName,
                    'archived_at' => $this->timeFactory->getTime(),
                    'archived_by' => $pseudonymize ? '(pseudonymized)' : ($this->userSession->getUser()?->getUID() ?? ''),
                ],
                'contents'          => $entries,
                'incomplete'        => $incomplete,
                'incomplete_reason' => $incompleteReason,
                'total_bytes'       => $totalBytes,
            ];
            $this->writer->writeJsonObject($workDir, 'manifest.json', $manifest);

            // ── 13. Zip atomically ────────────────────────────────────────────
            $slug     = $this->slugify($teamName);
            $dateStr  = date('Y-m-d', $this->timeFactory->getTime());
            $filename = "{$slug}-{$dateStr}.zip";
            $tmpZip   = sys_get_temp_dir() . '/' . $filename . '.tmp';
            $this->writer->produceZip($workDir, $tmpZip, $tmpZip);

            // ── 14a. Move ZIP into NC Files ────────────────────────────────────
            $ncPath = $this->writeZipToNcFiles($archiveFolder, $filename, $tmpZip, $archiveDisplayPath);
            @unlink($tmpZip);

            // Get actual size from the written NC Files node.
            $archiveBytes = 0;
            try {
                $ncFile = $archiveFolder->get($filename);
                $archiveBytes = $ncFile->getSize();
            } catch (\Throwable) {}

            return [$ncPath, $archiveBytes];

        } finally {
            $this->writer->cleanup($workDir);
        }
    }

    /**
     * After audit_log.json is written (with actor_uid replaced), re-open it
     * and walk the metadata JSON field through the pseudonymizer.
     * We write a replacement file in-place.
     */
    private function pseudonymizeAuditMetadata(string $workDir, ArchivePseudonymizer $ps): void {
        $path = $workDir . '/teamhub/audit_log.json';
        if (!file_exists($path)) {
            return;
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return;
            }
            foreach ($data as &$row) {
                if (isset($row['metadata']) && is_string($row['metadata'])) {
                    $row['metadata'] = $ps->processMetadataJson($row['metadata']);
                }
            }
            unset($row);
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not pseudonymize audit metadata', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Move the ZIP file from the server temp path into a resolved NC Files folder.
     * Returns a display path string for the manifest and audit log.
     */
    private function writeZipToNcFiles(
        \OCP\Files\Folder $archiveFolder,
        string $filename,
        string $tmpZipPath,
        string $displayPath
    ): string {
        // Use file path string rather than a stream handle — NC's putContent()
        // closes stream handles internally, which causes fclose() warnings.
        $content = file_get_contents($tmpZipPath);
        if ($content === false) {
            throw new \RuntimeException("Could not read zip for NC Files transfer: {$tmpZipPath}");
        }

        $ncFile = $archiveFolder->newFile($filename);
        $ncFile->putContent($content);
        unset($content); // free memory immediately

        $this->logger->debug('[TeamHub][ArchiveService] ZIP written to NC Files', [
            'displayPath' => $displayPath,
            'file'        => $filename,
            'app'         => Application::APP_ID,
        ]);

        return $displayPath . '/' . $filename;
    }

    // =========================================================================
    // Internal — data capture helpers
    // =========================================================================

    /** @return array<string, mixed> */
    private function captureTeamMeta(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'description', 'source', 'config', 'creation')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row !== false ? $row : ['unique_id' => $teamId, 'name' => 'unknown'];
    }

    /** @return array<int, array<string, mixed>> */
    private function captureMembers(string $teamId): array {
        if (!$this->appManager->isInstalled('circles')) {
            return [];
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id', 'circle_id', 'user_id', 'user_type', 'single_id', 'level', 'status', 'joined')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function captureEffectiveUsers(string $teamId): array {
        if (!$this->appManager->isInstalled('circles')) {
            return [];
        }
        // circles_membership only has circle_id and single_id as its own columns.
        // single_id is the unique_id of each user's personal circle.
        // To resolve the NC uid we JOIN circles_member: where circle_id = single_id
        // AND user_type=1 (direct user row), which gives us user_id = NC uid.
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('ms.circle_id', 'ms.single_id', 'm.user_id')
            ->from('circles_membership', 'ms')
            ->leftJoin(
                'ms',
                'circles_member',
                'm',
                $qb->expr()->andX(
                    $qb->expr()->eq('m.circle_id',  'ms.single_id'),
                    $qb->expr()->eq('m.user_type',
                        $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                )
            )
            ->where($qb->expr()->eq('ms.circle_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<string, mixed> */
    private function readTeamConfig(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('app_id', 'enabled', 'config')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $apps = [];
        while ($row = $res->fetch()) {
            $apps[] = $row;
        }
        $res->closeCursor();
        return ['team_id' => $teamId, 'apps' => $apps];
    }

    /** @return array<int, array<string, mixed>> */
    private function readMessages(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('*')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('created_at', 'ASC')
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readComments(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('c.*')
            ->from('teamhub_comments', 'c')
            ->join('c', 'teamhub_messages', 'm', $qb->expr()->eq('c.message_id', 'm.id'))
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('c.created_at', 'ASC')
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readPollVotes(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('v.*')
            ->from('teamhub_poll_votes', 'v')
            ->join('v', 'teamhub_messages', 'm', $qb->expr()->eq('v.message_id', 'm.id'))
            ->where($qb->expr()->eq('m.team_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readWebLinks(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('*')
            ->from('teamhub_web_links')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('sort_order', 'ASC')
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readLayouts(string $teamId): array {
        // teamhub_widget_layouts stores per-user layout per team.
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('*')
            ->from('teamhub_widget_layouts')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readIntegrations(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('ti.*', 'ir.integration_id', 'ir.name', 'ir.description')
            ->from('teamhub_team_integrations', 'ti')
            ->leftJoin('ti', 'teamhub_integ_registry', 'ir', $qb->expr()->eq('ti.integration_id', 'ir.id'))
            ->where($qb->expr()->eq('ti.team_id', $qb->createNamedParameter($teamId)))
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function readAuditLog(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('*')
            ->from('teamhub_audit_log')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('created_at', 'ASC')
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();
        return $rows;
    }

    // =========================================================================
    // Internal — connected app extractors
    // =========================================================================

    /**
     * Extract all calendar events for the team and write them to the archive.
     *
     * Lookup chain:
     *   dav_shares (principaluri = principals/circles/{teamId}, type = calendar)
     *   → resourceid = oc_calendars.id
     *   → CalDavBackend::getCalendarObjects(int $calendarId)
     *   → each row's calendardata = raw VCALENDAR iCalendar text
     *
     * Writes:
     *   apps/calendar/events.ics  — merged ICS with one VCALENDAR wrapper
     *   apps/calendar/events.json — structured array of event metadata
     *   apps/calendar/calendar.json — calendar metadata (name, colour, owner)
     *
     * Returns the manifest entry array for each file written.
     *
     * @return array<int, array{path: string, bytes: int, sha256: string}>
     */
    private function extractCalendarData(
        string $teamId,
        string $workDir,
        ?ArchivePseudonymizer $ps
    ): array {
        // ── Guard: calendar app installed? ────────────────────────────────────
        if (!$this->appManager->isInstalled('calendar')) {
            $this->logger->debug('[TeamHub][ArchiveService] Calendar app not installed — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // ── Step 1: Find the calendar ID via dav_shares ───────────────────────
        // TeamHub creates a dav_shares row with principaluri = principals/circles/{teamId}
        // when the team calendar is created (CalendarService::createCalendar).
        $principalUri = 'principals/circles/' . $teamId;
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('resourceid')
            ->from('dav_shares')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
            ->setMaxResults(1)
            ->executeQuery();
        $shareRow = $res->fetch();
        $res->closeCursor();

        if ($shareRow === false) {
            $this->logger->debug('[TeamHub][ArchiveService] No calendar share found for team — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        $calendarId = (int)$shareRow['resourceid'];

        // ── Step 2: Fetch calendar metadata from oc_calendars ─────────────────
        $cqb  = $this->db->getQueryBuilder();
        $cres = $cqb->select('id', 'principaluri', 'displayname', 'calendarcolor', 'description', 'timezone', 'deleted_at')
            ->from('calendars')
            ->where($cqb->expr()->eq('id', $cqb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $calMeta = $cres->fetch();
        $cres->closeCursor();

        if ($calMeta === false || !empty($calMeta['deleted_at'])) {
            // Calendar has been soft-deleted — nothing to export.
            $this->logger->debug('[TeamHub][ArchiveService] Calendar deleted_at set — skipping', [
                'teamId' => $teamId, 'calendarId' => $calendarId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // Extract owner UID from principaluri (principals/users/{uid}).
        $ownerUid = '';
        if (preg_match('#^principals/users/(.+)$#', (string)$calMeta['principaluri'], $m)) {
            $ownerUid = $m[1];
        }

        // ── Step 3: Fetch all calendar objects ────────────────────────────────
        $caldav  = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $objects = $caldav->getCalendarObjects($calendarId);

        // ── Step 4: Build ICS and JSON ────────────────────────────────────────
        // Merge individual VCALENDAR blocks into one wrapper ICS file.
        // Each object's calendardata is a complete VCALENDAR; extract the
        // VEVENT/VTODO/VJOURNAL components from each and collect into one.
        $vcomponents    = [];
        $structuredRows = [];
        $timezones      = [];

        foreach ($objects as $obj) {
            // getCalendarObjects() returns stub rows; fetch full data for each.
            $full = $caldav->getCalendarObject($calendarId, $obj['uri']);
            if ($full === null || empty($full['calendardata'])) {
                continue;
            }

            $raw  = (string)$full['calendardata'];

            // Extract VTIMEZONE blocks (deduplicate by TZID).
            preg_match_all('/BEGIN:VTIMEZONE.*?END:VTIMEZONE/s', $raw, $tzMatches);
            foreach ($tzMatches[0] as $tz) {
                preg_match('/TZID:([^\r\n]+)/', $tz, $tzid);
                $key = trim($tzid[1] ?? 'TZ');
                $timezones[$key] = $tz;
            }

            // Extract VEVENT / VTODO / VJOURNAL blocks.
            preg_match_all('/BEGIN:(VEVENT|VTODO|VJOURNAL).*?END:\1/s', $raw, $compMatches);
            foreach ($compMatches[0] as $comp) {
                $vcomponents[] = $comp;

                // Build a structured row for JSON: extract common fields.
                $structuredRows[] = $this->parseVComponent($comp, $ps);
            }
        }

        $entries = [];

        // ── Step 4a: Write calendar metadata JSON ─────────────────────────────
        $calMetaOut = [
            'calendar_id'    => $calendarId,
            'display_name'   => $calMeta['displayname'] ?? '',
            'description'    => $calMeta['description'] ?? '',
            'color'          => $calMeta['calendarcolor'] ?? '',
            'timezone'       => $calMeta['timezone'] ?? '',
            'owner_uid'      => $ps ? $ps->aliasFor($ownerUid) : $ownerUid,
            'event_count'    => count($vcomponents),
        ];
        $entries[] = $this->writer->writeJsonObject($workDir, 'apps/calendar/calendar.json', $calMetaOut);

        // ── Step 4b: Write structured events JSON ─────────────────────────────
        $entries[] = $this->writer->writeJson(
            $workDir, 'apps/calendar/events.json', $structuredRows, [], null
            // Pseudonymization of structured rows is handled inside parseVComponent.
        );

        // ── Step 4c: Write merged ICS ─────────────────────────────────────────
        if (!empty($vcomponents)) {
            $icsLines = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//TeamHub Archive//EN"];
            foreach ($timezones as $tz) {
                $icsLines[] = $tz;
            }
            foreach ($vcomponents as $comp) {
                $icsLines[] = $comp;
            }
            $icsLines[] = "END:VCALENDAR";
            $icsContent = implode("\r\n", $icsLines);
        } else {
            // Empty but valid ICS.
            $icsContent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TeamHub Archive//EN\r\nEND:VCALENDAR";
        }
        $entries[] = $this->writer->writeRaw($workDir, 'apps/calendar/events.ics', $icsContent);

        $this->logger->debug('[TeamHub][ArchiveService] Calendar extracted', [
            'teamId'    => $teamId,
            'calId'     => $calendarId,
            'eventCount'=> count($vcomponents),
            'app'       => Application::APP_ID,
        ]);

        return $entries;
    }

    /**
     * Parse a VEVENT/VTODO/VJOURNAL iCalendar block into a structured array.
     * Extracts common fields for the JSON output. Raw iCal text is NOT stored
     * in JSON — the .ics file is the authoritative raw source.
     *
     * Pseudonymizes ORGANIZER and ATTENDEE CN/EMAIL fields if $ps is set.
     *
     * @return array<string, mixed>
     */
    private function parseVComponent(string $raw, ?ArchivePseudonymizer $ps): array {
        $get = static function (string $prop) use ($raw): string {
            // Match PROP or PROP;params: value, handling line folding.
            // RFC 5545 folds lines at 75 chars with CRLF + space/tab.
            $unfolded = preg_replace('/\r?\n[ \t]/', '', $raw);
            if (preg_match('/^' . $prop . '(?:;[^:]*)?:(.*)$/im', $unfolded, $m)) {
                return trim($m[1]);
            }
            return '';
        };

        // Type: VEVENT, VTODO, VJOURNAL
        preg_match('/^BEGIN:(VEVENT|VTODO|VJOURNAL)/m', $raw, $typeMatch);
        $type = $typeMatch[1] ?? 'VEVENT';

        // Collect all ATTENDEEs (may be multiple lines).
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $raw);
        preg_match_all('/^ATTENDEE(?:;[^:]*)?:(.+)$/im', $unfolded, $attMatches);
        $attendees = array_map('trim', $attMatches[1] ?? []);

        $organizer = $get('ORGANIZER');

        // Pseudonymize organizer and attendee mailto: addresses.
        if ($ps !== null) {
            if (preg_match('/mailto:(.+)/i', $organizer, $orgM)) {
                $organizer = 'mailto:' . $ps->aliasFor($orgM[1]) . '@archive.local';
            }
            $attendees = array_map(static function (string $att) use ($ps): string {
                if (preg_match('/mailto:(.+)/i', $att, $attM)) {
                    return 'mailto:' . $ps->aliasFor($attM[1]) . '@archive.local';
                }
                return $att;
            }, $attendees);
        }

        return [
            'type'        => $type,
            'uid'         => $get('UID'),
            'summary'     => $get('SUMMARY'),
            'description' => $get('DESCRIPTION'),
            'location'    => $get('LOCATION'),
            'dtstart'     => $get('DTSTART'),
            'dtend'       => $get('DTEND'),
            'due'         => $get('DUE'),           // VTODO
            'status'      => $get('STATUS'),
            'organizer'   => $organizer,
            'attendees'   => $attendees,
            'created'     => $get('CREATED'),
            'last_modified' => $get('LAST-MODIFIED'),
            'rrule'       => $get('RRULE'),          // recurrence rule if any
        ];
    }

    /**
     * Copy the team's shared Files folder into the archive ZIP.
     *
     * Strategy:
     *   1. Find the circle share (share_type=7) to get uid_initiator and file_source.
     *   2. Resolve the folder node via IRootFolder::getUserFolder($owner)->getById($fileId).
     *   3. Walk the folder tree recursively, adding each file to the ZIP under
     *      apps/files/{relative-path}.
     *   4. For local storage: use ZipArchive::addFile() with the real filesystem path
     *      (no memory overhead). For external storage: fall back to reading content,
     *      skipping individual files over $singleFileMaxBytes to avoid OOM.
     *
     * The share and the folder itself are left completely intact — no changes to
     * permissions or folder contents. Hard deletion happens only at grace period
     * expiry (PendingDeletionJob) or admin force-purge.
     *
     * Returns manifest entries for files added. Returns [] if no Files share found.
     *
     * @return array<int, array{path: string, bytes: int, sha256: string}>
     */
    private function extractFilesData(
        string $teamId,
        string $teamName,
        string $workDir
    ): array {
        // ── Find the circle share ─────────────────────────────────────────────
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('uid_initiator', 'file_source')
            ->from('share')
            ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
            ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
            ->setMaxResults(1)
            ->executeQuery();
        $shareRow = $res->fetch();
        $res->closeCursor();

        if ($shareRow === false) {
            $this->logger->debug('[TeamHub][ArchiveService] No Files share found — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        $ownerUid = (string)$shareRow['uid_initiator'];
        $fileId   = (int)$shareRow['file_source'];

        // ── Resolve folder node ───────────────────────────────────────────────
        $rootFolder = $this->container->get(IRootFolder::class);
        $userFolder = $rootFolder->getUserFolder($ownerUid);
        $nodes      = $userFolder->getById($fileId);

        if (empty($nodes)) {
            $this->logger->warning('[TeamHub][ArchiveService] Files folder node not found', [
                'teamId' => $teamId, 'fileId' => $fileId, 'owner' => $ownerUid,
                'app' => Application::APP_ID,
            ]);
            return [];
        }

        /** @var \OCP\Files\Folder $teamFolder */
        $teamFolder = $nodes[0];
        if (!($teamFolder instanceof \OCP\Files\Folder)) {
            return [];
        }

        // ── Write a folder index JSON ─────────────────────────────────────────
        // Written before walking — gives the viewer a manifest of what's in apps/files/
        // even if some files are skipped due to external storage limits.
        $entries     = [];
        $fileLog     = [];   // [{path, size, skipped, skip_reason}]
        $zipFilePath = $workDir . '/apps/files';

        if (!is_dir($zipFilePath)) {
            mkdir($zipFilePath, 0700, true);
        }

        // ── Walk the tree and add files to ZIP ────────────────────────────────
        // Per-file limit for external storage fallback: 100 MB.
        $singleFileMaxBytes = 100 * 1024 * 1024;
        $zipPath            = $workDir . '/../' . basename($workDir) . '_files.partial';

        // We add files directly to the final work dir so ArchiveBundleWriter
        // picks them up when it zips the whole workDir tree.
        $this->walkAndCopyFiles(
            $teamFolder,
            'apps/files',
            $workDir,
            $fileLog,
            $singleFileMaxBytes
        );

        // ── Write the file index JSON ─────────────────────────────────────────
        $indexData = [
            'folder_name'  => $teamFolder->getName(),
            'folder_owner' => $ownerUid,
            'total_files'  => count(array_filter($fileLog, fn($f) => !$f['skipped'])),
            'skipped_files'=> count(array_filter($fileLog, fn($f) => $f['skipped'])),
            'files'        => $fileLog,
        ];
        $entries[] = $this->writer->writeJsonObject($workDir, 'apps/files/index.json', $indexData);

        // Add a manifest entry for each copied file.
        foreach ($fileLog as $f) {
            if (!$f['skipped'] && file_exists($workDir . '/' . $f['archive_path'])) {
                $bytes = filesize($workDir . '/' . $f['archive_path']) ?: 0;
                $hash  = hash_file('sha256', $workDir . '/' . $f['archive_path']) ?: '';
                $entries[] = [
                    'path'   => $f['archive_path'],
                    'bytes'  => $bytes,
                    'sha256' => $hash,
                ];
            }
        }

        $this->logger->debug('[TeamHub][ArchiveService] Files extracted', [
            'teamId'   => $teamId,
            'copied'   => $indexData['total_files'],
            'skipped'  => $indexData['skipped_files'],
            'app'      => Application::APP_ID,
        ]);

        return $entries;
    }

    /**
     * Recursively walk a Files folder and copy each file into the archive work dir.
     * Populates $fileLog with one entry per node encountered.
     *
     * Uses getLocalFile() for local storage (no memory overhead).
     * Falls back to getContent() for external storage, skipping files over $maxBytes.
     *
     * @param array<int, array<string, mixed>> $fileLog  Accumulator — modified in place.
     */
    private function walkAndCopyFiles(
        \OCP\Files\Folder $folder,
        string $archivePrefix,
        string $workDir,
        array &$fileLog,
        int $maxBytes
    ): void {
        try {
            $nodes = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] walkAndCopyFiles: listing failed', [
                'path' => $folder->getPath(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }

        foreach ($nodes as $node) {
            $safeSegment  = $this->slugifyFilename($node->getName());
            $archivePath  = $archivePrefix . '/' . $safeSegment;
            $destFullPath = $workDir . '/' . $archivePath;

            if ($node instanceof \OCP\Files\Folder) {
                // Recurse into subdirectory.
                if (!is_dir($destFullPath)) {
                    mkdir($destFullPath, 0700, true);
                }
                $this->walkAndCopyFiles($node, $archivePath, $workDir, $fileLog, $maxBytes);
                continue;
            }

            // It's a file node.
            $nodeSize    = $node->getSize();
            $logEntry    = [
                'archive_path'  => $archivePath,
                'original_name' => $node->getName(),
                'size'          => $nodeSize,
                'skipped'       => false,
                'skip_reason'   => null,
            ];

            try {
                // Try local filesystem path first (no memory overhead).
                $storage   = $node->getStorage();
                $localPath = $storage->getLocalFile($node->getInternalPath());

                if ($localPath !== null && file_exists($localPath)) {
                    // Local storage — copy directly without loading into memory.
                    $dir = dirname($destFullPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0700, true);
                    }
                    copy($localPath, $destFullPath);
                } else {
                    // External storage fallback — load via NC Files API.
                    if ($nodeSize > $maxBytes) {
                        $logEntry['skipped']     = true;
                        $logEntry['skip_reason'] = sprintf(
                            'File too large for external storage export (%.1f MB > %.1f MB limit)',
                            $nodeSize / 1048576,
                            $maxBytes / 1048576
                        );
                        $fileLog[] = $logEntry;
                        continue;
                    }
                    $content = $node->getContent();
                    $dir     = dirname($destFullPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0700, true);
                    }
                    file_put_contents($destFullPath, $content);
                    unset($content);
                }
            } catch (\Throwable $e) {
                $logEntry['skipped']     = true;
                $logEntry['skip_reason'] = 'Copy failed: ' . $e->getMessage();
                $this->logger->warning('[TeamHub][ArchiveService] walkAndCopyFiles: file copy failed', [
                    'path' => $node->getPath(), 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $fileLog[] = $logEntry;
        }
    }

    /**
     * Make a filename safe for use as a ZIP path segment.
     * Preserves the file extension; replaces unsafe characters in the stem.
     */
    private function slugifyFilename(string $name): string {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        // Allow letters, numbers, dots, hyphens, underscores, spaces.
        // Replace anything else with underscore.
        $safe = preg_replace('/[^\p{L}\p{N}\s.\-_]/u', '_', $stem) ?? $stem;
        $safe = trim($safe);
        if ($safe === '') {
            $safe = 'file';
        }
        return $ext !== '' ? $safe . '.' . $ext : $safe;
    }

    /**
     * Extract the Deck board for the team and write it to the archive.
     *
     * Lookup chain:
     *   deck_board_acl / deck_acl (participant=teamId, type=7) → board_id
     *   deck_boards (id=board_id)                              → board metadata
     *   deck_stacks (board_id=board_id)                        → columns
     *   deck_cards (stack_id in stack_ids)                     → cards
     *   deck_labels (board_id=board_id)                        → labels
     *   deck_assigned_labels (card_id in card_ids)             → card→label map
     *   deck_card_assigned_users (card_id in card_ids)         → assignees
     *   oc_comments (object_type='deckCard', object_id=card_id)→ card comments
     *
     * Writes:
     *   apps/deck/board.json  — full board in Deck's own export shape
     *   apps/deck/board.html  — human-readable kanban view
     *
     * @return array<int, array{path: string, bytes: int, sha256: string}>
     */
    private function extractDeckData(
        string $teamId,
        string $workDir,
        ?ArchivePseudonymizer $ps
    ): array {
        if (!$this->appManager->isInstalled('deck')) {
            $this->logger->debug('[TeamHub][ArchiveService] Deck not installed — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // ── Step 1: Find board_id via circle ACL ──────────────────────────────
        $boardId  = null;
        $aclTable = null;
        foreach (['deck_board_acl', 'deck_acl'] as $tbl) {
            try {
                $qb  = $this->db->getQueryBuilder();
                $res = $qb->select('board_id')
                    ->from($tbl)
                    ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                    ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(7)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $row = $res->fetch();
                $res->closeCursor();
                if ($row) {
                    $boardId  = (int)$row['board_id'];
                    $aclTable = $tbl;
                    break;
                }
            } catch (\Throwable) {}
        }

        if ($boardId === null) {
            $this->logger->debug('[TeamHub][ArchiveService] No Deck board found for team — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // ── Step 2: Board metadata ────────────────────────────────────────────
        // Use SELECT * to avoid failing on columns that may not exist in all
        // Deck versions (e.g. 'archived', 'deleted_at' differ between versions).
        $bqb  = $this->db->getQueryBuilder();
        $bres = $bqb->select('*')
            ->from('deck_boards')
            ->where($bqb->expr()->eq('id', $bqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $boardRow = $bres->fetch();
        $bres->closeCursor();

        if ($boardRow === false) {
            return [];
        }

        $boardOwner = (string)($boardRow['owner'] ?? '');

        // ── Step 3: Labels ────────────────────────────────────────────────────
        $lqb  = $this->db->getQueryBuilder();
        $lres = $lqb->select('id', 'title', 'color', 'board_id')
            ->from('deck_labels')
            ->where($lqb->expr()->eq('board_id', $lqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $labelsById = [];
        while ($lrow = $lres->fetch()) {
            $labelsById[(int)$lrow['id']] = [
                'id'    => (int)$lrow['id'],
                'title' => (string)$lrow['title'],
                'color' => (string)$lrow['color'],
            ];
        }
        $lres->closeCursor();

        // ── Step 4: Stacks and cards ──────────────────────────────────────────
        $sqb  = $this->db->getQueryBuilder();
        $sres = $sqb->select('*')
            ->from('deck_stacks')
            ->where($sqb->expr()->eq('board_id', $sqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')   // 'order' is a reserved SQL word — use id for stable ordering
            ->executeQuery();
        $stacks = [];
        $allCardIds = [];
        while ($srow = $sres->fetch()) {
            $stackId = (int)$srow['id'];
            // Fetch cards for this stack.
            $cqb  = $this->db->getQueryBuilder();
            $cres = $cqb->select('*')
                ->from('deck_cards')
                ->where($cqb->expr()->eq('stack_id', $cqb->createNamedParameter($stackId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')   // 'order' is a reserved SQL word — use id for stable ordering
                ->executeQuery();
            $cards = [];
            while ($crow = $cres->fetch()) {
                $cardId        = (int)$crow['id'];
                $cardOwner     = (string)$crow['owner'];
                $allCardIds[]  = $cardId;
                $cards[] = [
                    'id'            => $cardId,
                    'title'         => (string)$crow['title'],
                    'description'   => (string)($crow['description'] ?? ''),
                    'type'          => (string)($crow['type'] ?? ''),
                    'order'         => (int)$crow['order'],
                    'owner'         => $ps ? $ps->aliasFor($cardOwner) : $cardOwner,
                    'created_at'    => (string)($crow['created_at'] ?? ''),
                    'last_modified' => (string)($crow['last_modified'] ?? ''),
                    'deleted_at'    => $crow['deleted_at'] ?? null,
                    'due_date'      => $crow['due_date'] ?? null,
                    'labels'        => [],   // filled below
                    'assignees'     => [],   // filled below
                    'comments'      => [],   // filled below
                ];
            }
            $cres->closeCursor();

            $stacks[] = [
                'id'         => $stackId,
                'title'      => (string)$srow['title'],
                'order'      => (int)$srow['order'],
                'deleted_at' => $srow['deleted_at'] ?? null,
                'cards'      => $cards,
            ];
        }
        $sres->closeCursor();

        // ── Step 5: Assigned labels per card ─────────────────────────────────
        if (!empty($allCardIds)) {
            $alqb = $this->db->getQueryBuilder();
            try {
                $alres = $alqb->select('label_id', 'card_id')
                    ->from('deck_assigned_labels')
                    ->where($alqb->expr()->in(
                        'card_id',
                        $alqb->createNamedParameter($allCardIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                    ))
                    ->executeQuery();
                $cardLabels = [];
                while ($alrow = $alres->fetch()) {
                    $cardLabels[(int)$alrow['card_id']][] = (int)$alrow['label_id'];
                }
                $alres->closeCursor();

                // Attach label objects to cards.
                foreach ($stacks as &$stack) {
                    foreach ($stack['cards'] as &$card) {
                        foreach (($cardLabels[$card['id']] ?? []) as $lid) {
                            if (isset($labelsById[$lid])) {
                                $card['labels'][] = $labelsById[$lid];
                            }
                        }
                    }
                    unset($card);
                }
                unset($stack);
            } catch (\Throwable) {}
        }

        // ── Step 6: Assigned users per card ──────────────────────────────────
        if (!empty($allCardIds)) {
            try {
                $auqb  = $this->db->getQueryBuilder();
                $aures = $auqb->select('participant_uid', 'card_id')
                    ->from('deck_card_assigned_users')
                    ->where($auqb->expr()->in(
                        'card_id',
                        $auqb->createNamedParameter($allCardIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                    ))
                    ->executeQuery();
                $cardAssignees = [];
                while ($aurow = $aures->fetch()) {
                    $uid = (string)$aurow['participant_uid'];
                    $cardAssignees[(int)$aurow['card_id']][] = $ps ? $ps->aliasFor($uid) : $uid;
                }
                $aures->closeCursor();

                foreach ($stacks as &$stack) {
                    foreach ($stack['cards'] as &$card) {
                        $card['assignees'] = $cardAssignees[$card['id']] ?? [];
                    }
                    unset($card);
                }
                unset($stack);
            } catch (\Throwable) {}
        }

        // ── Step 7: Card comments from oc_comments ────────────────────────────
        if (!empty($allCardIds)) {
            try {
                // Comments uses string object_id — cast card IDs to strings.
                $strCardIds = array_map('strval', $allCardIds);
                $ccqb  = $this->db->getQueryBuilder();
                $ccres = $ccqb->select('id', 'actor_id', 'message', 'creation_timestamp', 'object_id')
                    ->from('comments')
                    ->where($ccqb->expr()->eq('object_type', $ccqb->createNamedParameter('deckCard')))
                    ->andWhere($ccqb->expr()->in(
                        'object_id',
                        $ccqb->createNamedParameter($strCardIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                    ))
                    ->orderBy('creation_timestamp', 'ASC')
                    ->executeQuery();

                $cardComments = [];
                while ($ccrow = $ccres->fetch()) {
                    $actorId = (string)$ccrow['actor_id'];
                    $cardComments[(int)$ccrow['object_id']][] = [
                        'id'                 => (int)$ccrow['id'],
                        'actor_id'           => $ps ? $ps->aliasFor($actorId) : $actorId,
                        'message'            => (string)$ccrow['message'],
                        'creation_timestamp' => (string)$ccrow['creation_timestamp'],
                    ];
                }
                $ccres->closeCursor();

                foreach ($stacks as &$stack) {
                    foreach ($stack['cards'] as &$card) {
                        $card['comments'] = $cardComments[$card['id']] ?? [];
                    }
                    unset($card);
                }
                unset($stack);
            } catch (\Throwable) {}
        }

        // ── Step 8: Assemble board JSON ───────────────────────────────────────
        $boardData = [
            'id'            => $boardId,
            'title'         => (string)($boardRow['title'] ?? ''),
            'owner'         => $ps ? $ps->aliasFor($boardOwner) : $boardOwner,
            'color'         => (string)($boardRow['color'] ?? ''),
            'archived'      => !empty($boardRow['archived']),
            'last_modified' => (string)($boardRow['last_modified'] ?? ''),
            'labels'        => array_values($labelsById),
            'stacks'        => $stacks,
        ];

        $entries   = [];
        $entries[] = $this->writer->writeJsonObject($workDir, 'apps/deck/board.json', $boardData);

        // ── Step 9: HTML kanban view ──────────────────────────────────────────
        $entries[] = $this->writer->writeRaw(
            $workDir,
            'apps/deck/board.html',
            $this->renderDeckBoard($boardData, count($allCardIds), (bool)$ps)
        );

        $this->logger->debug('[TeamHub][ArchiveService] Deck extracted', [
            'teamId'  => $teamId,
            'boardId' => $boardId,
            'stacks'  => count($stacks),
            'cards'   => count($allCardIds),
            'app'     => Application::APP_ID,
        ]);

        return $entries;
    }

    /**
     * Render a self-contained HTML kanban board from the extracted Deck data.
     * No external dependencies — readable offline from the archive ZIP.
     *
     * @param array<string, mixed> $board
     */
    private function renderDeckBoard(array $board, int $totalCards, bool $pseudonymized): string {
        $esc  = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = fn(mixed $v): string  => $esc((string)($v ?? ''));

        $pseudoBanner = $pseudonymized
            ? '<div class="banner">User identifiers have been replaced with aliases in this board.</div>'
            : '';

        // Build label colour map for inline chips.
        $labelMap = [];
        foreach ($board['labels'] ?? [] as $label) {
            $labelMap[(int)$label['id']] = $label;
        }

        $stacksHtml = '';
        foreach ($board['stacks'] ?? [] as $stack) {
            $cardsHtml = '';
            foreach ($stack['cards'] ?? [] as $card) {
                if (!empty($card['deleted_at'])) {
                    continue; // skip archived/deleted cards
                }

                // Label chips.
                $labelChips = '';
                foreach ($card['labels'] ?? [] as $lbl) {
                    $hex         = ltrim((string)($lbl['color'] ?? '0082c9'), '#');
                    $labelChips .= '<span class="label" style="background:#' . $esc($hex) . '">'
                        . $safe($lbl['title']) . '</span>';
                }

                // Assignees.
                $assigneeHtml = '';
                foreach ($card['assignees'] ?? [] as $uid) {
                    $assigneeHtml .= '<span class="assignee">' . $esc((string)$uid) . '</span>';
                }

                // Due date.
                $dueHtml = '';
                if (!empty($card['due_date'])) {
                    $dueTs    = strtotime((string)$card['due_date']);
                    $overdue  = $dueTs && $dueTs < time();
                    $dueHtml  = '<div class="due' . ($overdue ? ' due--overdue' : '') . '">Due: '
                        . $esc($dueTs ? date('Y-m-d', $dueTs) : (string)$card['due_date']) . '</div>';
                }

                // Description (first 200 chars — cards can have long markdown).
                $desc    = (string)($card['description'] ?? '');
                $descHtml = '';
                if ($desc !== '') {
                    $preview = mb_strlen($desc) > 200
                        ? mb_substr($desc, 0, 200) . '…'
                        : $desc;
                    $descHtml = '<div class="card-desc">' . $esc($preview) . '</div>';
                }

                // Comment count.
                $commentCount = count($card['comments'] ?? []);
                $commentsHtml = $commentCount > 0
                    ? '<div class="card-meta">💬 ' . $commentCount . '</div>'
                    : '';

                $cardsHtml .= '<div class="card">'
                    . '<div class="card-title">' . $safe($card['title']) . '</div>'
                    . ($labelChips ? '<div class="card-labels">' . $labelChips . '</div>' : '')
                    . $descHtml
                    . $dueHtml
                    . ($assigneeHtml ? '<div class="card-assignees">' . $assigneeHtml . '</div>' : '')
                    . $commentsHtml
                    . '</div>';
            }

            $stacksHtml .= '<div class="stack">'
                . '<div class="stack-title">' . $safe($stack['title']) . '</div>'
                . '<div class="stack-cards">' . ($cardsHtml ?: '<p class="empty">No cards</p>') . '</div>'
                . '</div>';
        }

        $title = $esc((string)($board['title'] ?? 'Deck Board'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Deck board — {$title}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;background:#f0f2f5;color:#1a1a1a;padding:24px}
h1{font-size:18px;font-weight:500;margin-bottom:4px}
.meta{font-size:12px;color:#888;margin-bottom:16px}
.banner{background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px;color:#5d4037;max-width:900px}
.board{display:flex;gap:16px;overflow-x:auto;align-items:flex-start;padding-bottom:16px}
.stack{background:#ebecf0;border-radius:8px;min-width:260px;max-width:260px;padding:12px}
.stack-title{font-size:13px;font-weight:600;color:#444;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
.stack-cards{display:flex;flex-direction:column;gap:8px}
.card{background:#fff;border-radius:6px;padding:10px 12px;box-shadow:0 1px 2px rgba(0,0,0,.08);display:flex;flex-direction:column;gap:5px}
.card-title{font-weight:500;font-size:13px}
.card-labels{display:flex;flex-wrap:wrap;gap:4px}
.label{display:inline-block;border-radius:3px;padding:2px 6px;font-size:11px;font-weight:600;color:#fff}
.card-desc{font-size:12px;color:#666;line-height:1.4;word-break:break-word}
.due{font-size:11px;color:#666}
.due--overdue{color:#c62828;font-weight:600}
.card-assignees{display:flex;flex-wrap:wrap;gap:4px}
.assignee{background:#e8f0fe;border-radius:10px;padding:2px 8px;font-size:11px;color:#1a73e8}
.card-meta{font-size:11px;color:#aaa}
.empty{font-size:12px;color:#aaa;font-style:italic;padding:4px 0}
</style>
</head>
<body>
<h1>🗂 {$title}</h1>
<p class="meta">Owner: {$safe($board['owner'])} · {$esc((string)count($board['stacks'] ?? []))} columns · {$esc((string)$totalCards)} cards</p>
{$pseudoBanner}
<div class="board">{$stacksHtml}</div>
</body>
</html>
HTML;
    }

    /**
     * Extract the Talk room's chat history for the team and write it to the archive.
     *
     * Lookup chain:
     *   talk_attendees (actor_type='circles', actor_id=teamId) → room_id
     *   talk_rooms (id=room_id)                                → token, name
     *   oc_comments (object_type='chat', object_id=room_id)    → all messages
     *
     * Note: object_id stores the INTEGER room ID as a string, NOT the room token.
     * Confirmed against live schema (oc_talk_rooms / oc_comments).
     *
     * Writes:
     *   apps/talk/messages.json   — structured message array
     *   apps/talk/transcript.html — human-readable HTML transcript
     *
     * @return array<int, array{path: string, bytes: int, sha256: string}>
     */
    private function extractTalkData(
        string $teamId,
        string $workDir,
        ?ArchivePseudonymizer $ps
    ): array {
        // ── Guard: Talk (spreed) installed? ───────────────────────────────────
        if (!$this->appManager->isInstalled('spreed')) {
            $this->logger->debug('[TeamHub][ArchiveService] Talk not installed — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // ── Step 1: Find room_id via circle attendee ──────────────────────────
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('room_id')
            ->from('talk_attendees')
            ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
            ->andWhere($qb->expr()->eq('actor_id',   $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $attendeeRow = $res->fetch();
        $res->closeCursor();

        if ($attendeeRow === false) {
            // Circle attendee row was removed by suspension — try finding the
            // room directly by looking for any attendee whose actor_id matches
            // the teamId in any actor_type, or fall back to the suspended_resources
            // room_id if available. For simplicity, skip if not found.
            $this->logger->debug('[TeamHub][ArchiveService] No Talk circle attendee found — skipping', [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        $roomId = (int)$attendeeRow['room_id'];

        // ── Step 2: Get room token and name from talk_rooms ───────────────────
        $rqb  = $this->db->getQueryBuilder();
        $rres = $rqb->select('token', 'name')
            ->from('talk_rooms')
            ->where($rqb->expr()->eq('id', $rqb->createNamedParameter($roomId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $roomRow = $rres->fetch();
        $rres->closeCursor();

        if ($roomRow === false) {
            $this->logger->warning('[TeamHub][ArchiveService] Talk room row not found', [
                'teamId' => $teamId, 'roomId' => $roomId, 'app' => Application::APP_ID,
            ]);
            return [];
        }

        $token    = (string)$roomRow['token'];
        $roomName = (string)$roomRow['name'];

        // ── Step 3: Read all messages from oc_comments ────────────────────────
        // Talk stores chat in the NC core comments table with:
        //   object_type = 'chat'
        //   object_id   = room ID as a string (NOT the room token)
        // Confirmed against live schema: object_id stores the integer room ID.
        $cqb  = $this->db->getQueryBuilder();
        $cres = $cqb->select(
                'id', 'parent_id', 'actor_type', 'actor_id',
                'message', 'verb', 'creation_timestamp'
            )
            ->from('comments')
            ->where($cqb->expr()->eq('object_type', $cqb->createNamedParameter('chat')))
            ->andWhere($cqb->expr()->eq('object_id',   $cqb->createNamedParameter((string)$roomId)))
            ->orderBy('creation_timestamp', 'ASC')
            ->orderBy('id',                 'ASC')
            ->executeQuery();

        $messages = [];
        while ($row = $cres->fetch()) {
            $actorId = (string)$row['actor_id'];

            $messages[] = [
                'id'                 => (int)$row['id'],
                'parent_id'          => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'actor_type'         => (string)$row['actor_type'],
                'actor_id'           => $ps ? $ps->aliasFor($actorId) : $actorId,
                'message'            => (string)$row['message'],
                'verb'               => (string)$row['verb'],
                'creation_timestamp' => (string)$row['creation_timestamp'],
            ];
        }
        $cres->closeCursor();

        if (empty($messages)) {
            $this->logger->debug('[TeamHub][ArchiveService] Talk room has no messages — writing empty transcript', [
                'teamId' => $teamId, 'token' => $token, 'app' => Application::APP_ID,
            ]);
        }

        // ── Step 4: Write messages.json ───────────────────────────────────────
        $entries   = [];
        $entries[] = $this->writer->writeJson(
            $workDir, 'apps/talk/messages.json', $messages, [], null
            // Pseudonymization already applied inline above — no uidFields needed.
        );

        // ── Step 5: Write transcript.html ─────────────────────────────────────
        $entries[] = $this->writer->writeRaw(
            $workDir,
            'apps/talk/transcript.html',
            $this->renderTalkTranscript($roomName, $token, $messages, (bool)$ps)
        );

        $this->logger->debug('[TeamHub][ArchiveService] Talk extracted', [
            'teamId'       => $teamId,
            'token'        => $token,
            'messageCount' => count($messages),
            'app'          => Application::APP_ID,
        ]);

        return $entries;
    }

    /**
     * Render a self-contained HTML transcript from the extracted Talk messages.
     * No external dependencies — readable offline from the archive ZIP.
     *
     * System messages (verb != 'comment') are rendered as events in a lighter style.
     * Rich-object placeholders like {file} are shown as-is — the JSON has the raw text.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function renderTalkTranscript(
        string $roomName,
        string $token,
        array $messages,
        bool $pseudonymized
    ): string {
        $esc       = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pseudoBanner = $pseudonymized
            ? '<div class="banner">User identifiers have been replaced with aliases in this transcript.</div>'
            : '';

        $rows = '';
        $lastDate = '';
        foreach ($messages as $msg) {
            // Date separator.
            $ts        = strtotime((string)$msg['creation_timestamp']) ?: 0;
            $dateStr   = $ts ? date('Y-m-d', $ts) : '';
            $timeStr   = $ts ? date('H:i', $ts)   : '';

            if ($dateStr && $dateStr !== $lastDate) {
                $rows     .= '<div class="date-sep">' . $esc($dateStr) . '</div>';
                $lastDate  = $dateStr;
            }

            $isSystem  = ($msg['verb'] !== 'comment');
            $actor     = $esc((string)$msg['actor_id']);
            $text      = $esc((string)$msg['message']);
            // Highlight rich-object placeholders {foo} so they're visible.
            $text      = preg_replace('/\{(\w+)\}/', '<span class="placeholder">{$1}</span>', $text) ?? $text;

            if ($isSystem) {
                $rows .= '<div class="msg msg--system"><span class="time">' . $esc($timeStr) . '</span>'
                    . '<span class="system-text">' . $text . '</span></div>';
            } else {
                $rows .= '<div class="msg"><span class="time">' . $esc($timeStr) . '</span>'
                    . '<span class="actor">' . $actor . '</span>'
                    . '<span class="text">' . $text . '</span></div>';
            }
        }

        if ($rows === '') {
            $rows = '<p class="empty">No messages in this room.</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Talk transcript — {$esc($roomName)}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;line-height:1.5;background:#f5f5f5;color:#1a1a1a;padding:24px}
.wrap{max-width:800px;margin:auto}
h1{font-size:18px;font-weight:500;margin-bottom:4px}
.meta{font-size:12px;color:#888;margin-bottom:16px}
.banner{background:#fff8e1;border:1px solid #f9a825;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:16px;color:#5d4037}
.date-sep{text-align:center;font-size:11px;color:#aaa;margin:12px 0;position:relative}
.date-sep::before,.date-sep::after{content:'';position:absolute;top:50%;width:40%;height:1px;background:#e0e0e0}
.date-sep::before{left:0}.date-sep::after{right:0}
.msg{display:grid;grid-template-columns:42px 120px 1fr;gap:0 8px;padding:3px 0}
.msg--system{display:grid;grid-template-columns:42px 1fr;gap:0 8px;padding:3px 0}
.time{font-size:11px;color:#aaa;padding-top:2px;text-align:right}
.actor{font-weight:600;color:#333;font-size:13px;padding-top:1px}
.text{word-break:break-word}
.system-text{color:#888;font-style:italic;font-size:13px}
.placeholder{background:#e8f0fe;border-radius:3px;padding:0 3px;font-family:monospace;font-size:12px;color:#1a73e8}
.empty{color:#888;font-style:italic;padding:16px 0}
</style>
</head>
<body>
<div class="wrap">
<h1>💬 {$esc($roomName)}</h1>
<p class="meta">Room token: {$esc($token)} · {$esc((string)count($messages))} messages</p>
{$pseudoBanner}
{$rows}
</div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // Internal — utilities
    // =========================================================================

    /**
     * Rough size estimation — row-count × average bytes per row per table.
     * Used only for the pre-archive cap check; deliberately generous.
     */
    private function estimateBundleSize(string $teamId): int {
        $tables = [
            'teamhub_messages'          => ['col' => 'team_id', 'avgBytes' => 2048],
            'teamhub_comments'          => ['col' => null,      'avgBytes' => 512,  'join' => true],
            'teamhub_poll_votes'        => ['col' => null,      'avgBytes' => 64,   'join' => true],
            'teamhub_web_links'         => ['col' => 'team_id', 'avgBytes' => 256],
            'teamhub_widget_layouts'    => ['col' => 'team_id', 'avgBytes' => 1024],
            'teamhub_team_integrations' => ['col' => 'team_id', 'avgBytes' => 256],
            'teamhub_audit_log'         => ['col' => 'team_id', 'avgBytes' => 1024],
        ];

        $total = 0;
        foreach ($tables as $table => $cfg) {
            try {
                $qb = $this->db->getQueryBuilder();
                if (!empty($cfg['col'])) {
                    $qb->select($qb->func()->count('*', 'cnt'))
                        ->from($table)
                        ->where($qb->expr()->eq($cfg['col'], $qb->createNamedParameter($teamId)));
                } else {
                    // Joined tables — just count all (over-estimates, which is safe).
                    $qb->select($qb->func()->count('*', 'cnt'))->from($table);
                }
                $res   = $qb->executeQuery();
                $count = (int)$res->fetchOne();
                $res->closeCursor();
                $total += $count * $cfg['avgBytes'];
            } catch (\Throwable) {}
        }

        // Add the real Files folder size from oc_filecache.
        // NC stores the recursive total size for a folder in filecache.size,
        // kept up to date by the storage scanner — no need to walk the tree.
        $total += $this->estimateFolderSize($teamId);

        return $total;
    }

    /**
     * Return the actual size of the team's shared Files folder in bytes,
     * by looking up the circle share in oc_share and reading oc_filecache.size.
     * Returns 0 if no Files share exists or if the lookup fails.
     */
    private function estimateFolderSize(string $teamId): int {
        try {
            // Find the circle share (share_type=7) for this team's folder.
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7)))
                ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return 0;
            }

            $fileId = (int)$row['file_source'];

            // oc_filecache.size for a folder = recursive total of all children.
            // NC keeps this accurate via the storage scanner.
            $cqb  = $this->db->getQueryBuilder();
            $cres = $cqb->select('size')
                ->from('filecache')
                ->where($cqb->expr()->eq('fileid', $cqb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $crow = $cres->fetch();
            $cres->closeCursor();

            return $crow ? max(0, (int)$crow['size']) : 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] estimateFolderSize failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return 0;
        }
    }

    /**
     * Resolve the NC Files owner UID and folder path from admin settings.
     * Falls back to the current user's Files/TeamHub Archives.
     *
     * @return array{string, string} [ownerUid, folderPath]
     */
    /**
     * Resolve the archive storage folder from admin config.
     *
     * Three formats accepted for archivePath:
     *   1. '/f/{nodeId}' — Team Folder or Files node link (from NC URL bar).
     *      We resolve by node ID via IRootFolder::getById(). No owner needed.
     *   2. '' (empty) — fall back to the current team owner's Files under
     *      'TeamHub Archives'. The folder is created if it does not exist.
     *
     * Returns [\OCP\Files\Folder $folder, string $displayPath].
     *
     * @return array{\OCP\Files\Folder, string}
     */
    private function resolveArchiveFolder(string $archPath, string $ownerUid): array {
        $rootFolder = $this->container->get(IRootFolder::class);

        // ── Case 1: /f/{nodeId} Team Folder link ─────────────────────────────
        if (preg_match('#^/f/(\d+)$#', trim($archPath), $m)) {
            $nodeId = (int)$m[1];
            $nodes  = $rootFolder->getById($nodeId);
            if (empty($nodes)) {
                throw new \RuntimeException(
                    "Archive location /f/{$nodeId} could not be resolved. Verify the Team Folder link in Archive settings."
                );
            }
            $node = $nodes[0];
            if (!($node instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException(
                    "Archive location /f/{$nodeId} resolves to a file, not a folder. Enter a folder link."
                );
            }
            return [$node, '/f/' . $nodeId];
        }

        // ── Case 2: Fallback — team owner's Files/TeamHub Archives ───────────
        $userFolder    = $rootFolder->getUserFolder($ownerUid);
        $folderName    = 'TeamHub Archives';
        if (!$userFolder->nodeExists($folderName)) {
            $userFolder->newFolder($folderName);
        }
        /** @var \OCP\Files\Folder $folder */
        $folder = $userFolder->get($folderName);
        return [$folder, $ownerUid . ':' . $folderName];
    }

    private function graceSeconds(string $mode): int {
        return match ($mode) {
            'soft30' => 30 * 86400,
            'soft60' => 60 * 86400,
            default  => 0,  // hard — immediate
        };
    }

    private function slugify(string $name): string {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? 'team';
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 64, 'UTF-8');
        return $slug !== '' ? $slug : 'team';
    }

    /**
     * @param int|null $now Pass current timestamp to compute grace countdown.
     * @return array<string, mixed>
     */
    private function pendingToArray(PendingDeletion $p, ?int $now = null): array {
        $now ??= $this->timeFactory->getTime();
        return [
            'id'             => $p->getId(),
            'teamId'         => $p->getTeamId(),
            'teamName'       => $p->getTeamName(),
            'archivedAt'     => $p->getArchivedAt(),
            'hardDeleteAt'   => $p->getHardDeleteAt(),
            'daysRemaining'  => max(0, (int)ceil(($p->getHardDeleteAt() - $now) / 86400)),
            'archivePath'    => $p->getArchivePath(),
            'archiveBytes'   => $p->getArchiveBytes(),
            'archivedBy'     => $p->getArchivedBy(),
            'status'         => $p->getStatus(),
            'failureReason'  => $p->getFailureReason(),
        ];
    }

    // =========================================================================
    // Internal — connected app resource suspension / resume
    // =========================================================================

    /**
     * Remove the team circle from each connected NC app resource.
     * Called after archive production for soft-delete modes.
     *
     * Each suspension is independently try-caught — a failure on one app
     * does not prevent the others from being suspended.
     *
     * Returns the suspended_resources array for storage on the pending_dels row.
     *
     * @return array<string, mixed>
     */
    private function suspendConnectedAppResources(string $teamId, string $teamName): array {
        $suspended = [];

        // Talk — remove circle attendee row.
        try {
            $roomId = $this->talkService->suspendTalkAccess($teamId, $this->db);
            if ($roomId !== null) {
                $suspended['talk'] = ['room_id' => $roomId];
                $this->logger->debug('[TeamHub][ArchiveService] Talk access suspended', [
                    'teamId' => $teamId, 'roomId' => $roomId, 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not suspend Talk access', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Files — remove circle share row (folder and contents stay intact).
        try {
            $filesMeta = $this->filesService->suspendFilesAccess($teamId, $this->db);
            if ($filesMeta !== null) {
                $suspended['files'] = $filesMeta;
                $this->logger->debug('[TeamHub][ArchiveService] Files access suspended', [
                    'teamId' => $teamId, 'shareId' => $filesMeta['share_id'], 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not suspend Files access', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Calendar — remove dav_shares row for the circle principal.
        try {
            $calMeta = $this->calendarService->suspendCalendarAccess($teamId, $this->db);
            if ($calMeta !== null) {
                $suspended['calendar'] = $calMeta;
                $this->logger->debug('[TeamHub][ArchiveService] Calendar access suspended', [
                    'teamId' => $teamId, 'calId' => $calMeta['calendar_id'], 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not suspend Calendar access', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Deck — remove circle ACL row.
        try {
            $deckMeta = $this->deckService->suspendDeckAccess($teamId, $this->db);
            if ($deckMeta !== null) {
                $suspended['deck'] = $deckMeta;
                $this->logger->debug('[TeamHub][ArchiveService] Deck access suspended', [
                    'teamId' => $teamId, 'boardId' => $deckMeta['board_id'], 'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ArchiveService] Could not suspend Deck access', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return $suspended;
    }

    /**
     * Re-add the team circle to each previously suspended NC app resource.
     * Called from restorePendingDeletion using the stored suspended_resources JSON.
     *
     * Each resume is independently try-caught — partial resume is logged but
     * does not throw; the team is restored in TeamHub regardless.
     *
     * @param array<string, mixed> $suspended  Decoded suspended_resources JSON.
     */
    private function resumeConnectedAppResources(
        string $teamId,
        string $teamName,
        array $suspended
    ): void {
        // Talk.
        if (isset($suspended['talk']['room_id'])) {
            try {
                $this->talkService->resumeTalkAccess(
                    (int)$suspended['talk']['room_id'],
                    $teamId,
                    $teamName,
                    $this->db
                );
                $this->logger->debug('[TeamHub][ArchiveService] Talk access resumed', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Could not resume Talk access', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // Files.
        if (isset($suspended['files']['uid_initiator'], $suspended['files']['file_source'])) {
            try {
                $this->filesService->resumeFilesAccess(
                    $teamId,
                    (string)$suspended['files']['uid_initiator'],
                    (int)$suspended['files']['file_source'],
                    (int)($suspended['files']['permissions'] ?? 31),
                    $this->db
                );
                $this->logger->debug('[TeamHub][ArchiveService] Files access resumed', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Could not resume Files access', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // Calendar.
        if (isset($suspended['calendar']['calendar_id'])) {
            try {
                $this->calendarService->resumeCalendarAccess(
                    (int)$suspended['calendar']['calendar_id'],
                    $teamId,
                    $this->db
                );
                $this->logger->debug('[TeamHub][ArchiveService] Calendar access resumed', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Could not resume Calendar access', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // Deck.
        if (isset($suspended['deck']['board_id'])) {
            try {
                $this->deckService->resumeDeckAccess(
                    (int)$suspended['deck']['board_id'],
                    $teamId,
                    (string)($suspended['deck']['acl_table'] ?? 'deck_board_acl'),
                    $this->db
                );
                $this->logger->debug('[TeamHub][ArchiveService] Deck access resumed', [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ArchiveService] Could not resume Deck access', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }
}
