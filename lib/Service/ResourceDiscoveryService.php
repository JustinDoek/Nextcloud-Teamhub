<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Db\TeamAppResource;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Reconciles teamhub_team_app_resources against NC's live ACL/share tables.
 *
 * Algorithm (per team, per app):
 *   DB_REAL  = resource IDs where the team's circle actually has ACL access
 *   DB_LOCAL = rows in teamhub_team_app_resources for (team, app)
 *
 *   - In DB_REAL but not DB_LOCAL → insert new row
 *       owner is a team admin (level ≥ 8) → status=active, origin=discovered_auto_accepted
 *       otherwise                          → status=pending, origin=discovered_pending
 *   - In DB_LOCAL but not DB_REAL → delete row (ACL was withdrawn externally)
 *   - In both                     → re-evaluate risk_status from current owner state
 *
 * Called render-time from ResourceService::getTeamResources() for the viewed team,
 * and hourly from ResourceDiscoveryJob for all teams.
 */
class ResourceDiscoveryService {

    public function __construct(
        private readonly TeamAppResourceMapper $resourceMapper,
        private readonly AuditService          $auditService,
        private readonly IDBConnection         $db,
        private readonly IAppManager           $appManager,
        private readonly LoggerInterface       $logger,
        private readonly GroupFolderService    $groupFolderService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run reconciliation for a single team across all installed resource-backed apps.
     * Safe to call on every page load — idempotent, fast on quiet installs.
     *
     * @param string $teamId  circles_circle.unique_id
     */
    public function reconcileTeam(string $teamId): void {
        $this->logger->debug('[TeamHub][ResourceDiscoveryService] reconcileTeam start', [
            'teamId' => $teamId, 'app' => Application::APP_ID,
        ]);

        $this->reconcileApp($teamId, 'files');

        if ($this->appManager->isInstalled('spreed')) {
            $this->reconcileApp($teamId, 'talk');
        }
        if ($this->appManager->isInstalled('calendar')) {
            $this->reconcileApp($teamId, 'calendar');
        }
        if ($this->appManager->isInstalled('deck')) {
            $this->reconcileApp($teamId, 'deck');
        }
    }

    /**
     * Run reconciliation for ALL teams.
     * Called by ResourceDiscoveryJob (hourly cron backstop).
     */
    public function reconcileAllTeams(): void {
        $teamIds = $this->getAllTeamIds();
        $this->logger->debug('[TeamHub][ResourceDiscoveryService] reconcileAllTeams', [
            'count' => count($teamIds), 'app' => Application::APP_ID,
        ]);
        foreach ($teamIds as $teamId) {
            try {
                $this->reconcileTeam($teamId);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][ResourceDiscoveryService] reconcileTeam failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * Count of resources in pending or at-risk state for a team.
     * Returned as part of getTeamResources() for the Teaminfo widget warning block.
     *
     * @return array{pending: int, atRisk: int}
     */
    public function getWarningCounts(string $teamId): array {
        // Pending count
        $qbP = $this->db->getQueryBuilder();
        $qbP->select($qbP->func()->count('*', 'cnt'))
            ->from('teamhub_team_app_resources')
            ->where($qbP->expr()->eq('team_id', $qbP->createNamedParameter($teamId)))
            ->andWhere($qbP->expr()->eq('status', $qbP->createNamedParameter('pending')));
        $rP = $qbP->executeQuery();
        $pending = (int) $rP->fetchOne();
        $rP->closeCursor();

        // At-risk count (active rows with risk_status != none)
        $atRisk = $this->resourceMapper->countAtRiskByTeam($teamId);

        return ['pending' => $pending, 'atRisk' => $atRisk];
    }

    /**
     * Return all (team, app) resource rows regardless of status.
     * Used by the Manage Team → Settings → Team Apps panel.
     *
     * @return array[]  Serialised TeamAppResource rows grouped by app_id
     */
    public function getSettingsPanelData(string $teamId): array {
        $rows = $this->resourceMapper->findAllByTeam($teamId);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->getAppId()][] = $this->serializeRow($row);
        }

        // ── Dual-folder detection ──────────────────────────────────────────
        // When the team has an active legacy shared-folder resource (non-gf:)
        // AND a pending gf: resource, we are in the dual-folder state (§2.19).
        // Tag the pending gf: row so the UI can render the migration modal
        // instead of the normal Accept/Ignore buttons.
        $fileRows = $grouped['files'] ?? [];
        $hasActiveLegacy = false;
        $hasPendingGf    = false;
        foreach ($fileRows as &$fr) {
            if ($fr['status'] === 'active' && !str_starts_with($fr['resourceId'], 'gf:')) {
                $hasActiveLegacy = true;
            }
            if ($fr['status'] === 'pending' && str_starts_with($fr['resourceId'], 'gf:')) {
                $hasPendingGf = true;
            }
        }
        unset($fr);

        if ($hasActiveLegacy && $hasPendingGf) {
            foreach ($fileRows as &$fr) {
                if ($fr['status'] === 'pending' && str_starts_with($fr['resourceId'], 'gf:')) {
                    $fr['isDualFolderPending'] = true;
                }
            }
            unset($fr);
            $grouped['files'] = $fileRows;
        }

        return $grouped;
    }

    /**
     * Accept a pending resource row.
     * Sets status=active, records decided_by + decided_at, writes audit event.
     *
     * @param string $teamId
     * @param string $appId
     * @param string $resourceId
     * @param string $actorUid    UID of the admin performing the action
     */
    public function acceptResource(string $teamId, string $appId, string $resourceId, string $actorUid): void {
        $row = $this->resourceMapper->findByTeamAppResource($teamId, $appId, $resourceId);
        if ($row === null) {
            throw new \RuntimeException("Resource row not found: {$teamId}/{$appId}/{$resourceId}");
        }
        if ($row->getStatus() !== 'pending') {
            throw new \RuntimeException("Resource is not pending (status={$row->getStatus()})");
        }

        // ── Files: enforce 1:1 at the accept layer ────────────────────────
        // A pending gf: row alongside an active shared folder is the dual-folder
        // migration case — acceptResource must not be called directly for that row;
        // the migration endpoint handles it. All other duplicates are refused here.
        if ($appId === 'files') {
            $activeRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'files');
            if (!empty($activeRows)) {
                $activeId   = $activeRows[0]->getResourceId();
                $activeIsGf = str_starts_with($activeId, 'gf:');
                $incomingIsGf = str_starts_with($resourceId, 'gf:');

                if (!$activeIsGf && $incomingIsGf) {
                    // Dual-folder migration case — should go through the migrate endpoint
                    throw new \RuntimeException(
                        'This group folder must be accepted through the folder migration flow, not directly. ' .
                        'Use the "Review migration" button in the team settings.'
                    );
                }

                // Any other duplicate — suppress and audit
                $this->resourceMapper->updateStatus($row->getId(), 'ignored', $actorUid, time());
                $reason = $activeIsGf
                    ? 'active_group_folder_takes_precedence'
                    : 'duplicate_shared_folder_rejected';
                $this->auditService->log(
                    $teamId,
                    'resource.suppressed_duplicate',
                    $actorUid,
                    'resource',
                    "files:{$resourceId}",
                    [
                        'app_id'             => 'files',
                        'resource_id'        => $resourceId,
                        'active_resource_id' => $activeId,
                        'reason'             => $reason,
                        'actor'              => $actorUid,
                    ],
                );
                $this->logger->warning('[TeamHub][ResourceDiscoveryService] acceptResource refused — files 1:1 constraint', [
                    'teamId' => $teamId, 'resourceId' => $resourceId,
                    'activeId' => $activeId, 'reason' => $reason, 'app' => Application::APP_ID,
                ]);
                throw new \RuntimeException(
                    'A files resource is already connected to this team. ' .
                    'Disconnect the existing folder before connecting another one.'
                );
            }
        }

        $this->resourceMapper->updateStatus($row->getId(), 'active', $actorUid, time());

        $this->auditService->log(
            $teamId,
            'resource.accepted',
            $actorUid,
            'resource',
            "{$appId}:{$resourceId}",
            ['app_id' => $appId, 'resource_id' => $resourceId],
        );

        $this->logger->info('[TeamHub][ResourceDiscoveryService] resource accepted', [
            'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
            'by' => $actorUid, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Ignore a pending resource row (status=ignored).
     * Reversible. Does not touch the underlying ACL.
     */
    public function ignoreResource(string $teamId, string $appId, string $resourceId, string $actorUid): void {
        $row = $this->resourceMapper->findByTeamAppResource($teamId, $appId, $resourceId);
        if ($row === null) {
            throw new \RuntimeException("Resource row not found: {$teamId}/{$appId}/{$resourceId}");
        }
        if (!in_array($row->getStatus(), ['pending', 'active'], true)) {
            throw new \RuntimeException("Resource cannot be ignored (status={$row->getStatus()})");
        }

        $this->resourceMapper->updateStatus($row->getId(), 'ignored', $actorUid, time());

        $this->auditService->log(
            $teamId,
            'resource.ignored',
            $actorUid,
            'resource',
            "{$appId}:{$resourceId}",
            ['app_id' => $appId, 'resource_id' => $resourceId],
        );

        $this->logger->info('[TeamHub][ResourceDiscoveryService] resource ignored', [
            'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
            'by' => $actorUid, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Un-ignore a resource (status=active).
     */
    public function unignoreResource(string $teamId, string $appId, string $resourceId, string $actorUid): void {
        $row = $this->resourceMapper->findByTeamAppResource($teamId, $appId, $resourceId);
        if ($row === null) {
            throw new \RuntimeException("Resource row not found: {$teamId}/{$appId}/{$resourceId}");
        }
        if ($row->getStatus() !== 'ignored') {
            throw new \RuntimeException("Resource is not ignored (status={$row->getStatus()})");
        }

        // ── Files: refuse un-ignore if one is already active ─────────────
        if ($appId === 'files') {
            $activeRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'files');
            if (!empty($activeRows)) {
                $activeId = $activeRows[0]->getResourceId();
                $this->auditService->log(
                    $teamId,
                    'resource.suppressed_duplicate',
                    $actorUid,
                    'resource',
                    "files:{$resourceId}",
                    [
                        'app_id'             => 'files',
                        'resource_id'        => $resourceId,
                        'active_resource_id' => $activeId,
                        'reason'             => 'unignore_refused_one_active_files_resource',
                        'actor'              => $actorUid,
                    ],
                );
                throw new \RuntimeException(
                    'A files resource is already connected to this team. ' .
                    'Disconnect the existing folder before activating another one.'
                );
            }
        }

        $this->resourceMapper->updateStatus($row->getId(), 'active', $actorUid, time());

        $this->auditService->log(
            $teamId,
            'resource.unignored',
            $actorUid,
            'resource',
            "{$appId}:{$resourceId}",
            ['app_id' => $appId, 'resource_id' => $resourceId],
        );
    }

    // -------------------------------------------------------------------------
    // Reconciliation — per-app
    // -------------------------------------------------------------------------

    private function reconcileApp(string $teamId, string $appId): void {
        try {
            $realIds  = $this->getRealResourceIds($teamId, $appId);
            $localRows = $this->resourceMapper->findAllByTeamAndApp($teamId, $appId);

            // Index local rows by resource_id for O(1) lookup.
            $localByResourceId = [];
            foreach ($localRows as $row) {
                $localByResourceId[$row->getResourceId()] = $row;
            }

            $realIdSet = array_flip($realIds);

            // ── Files: snapshot the current active resource before inserting new ones ──
            // This drives the 1:1 enforcement and the migration-trigger logic.
            $activeFilesRow = null;
            if ($appId === 'files') {
                foreach ($localRows as $row) {
                    if ($row->getStatus() === 'active') {
                        $activeFilesRow = $row;
                        break;
                    }
                }
            }

            // — Resources in NC reality but not in our table → insert —
            foreach ($realIds as $resourceId) {
                if (isset($localByResourceId[$resourceId])) {
                    // Already tracked — refresh risk_status.
                    $this->refreshRiskStatus($teamId, $appId, $resourceId, $localByResourceId[$resourceId]);
                    continue;
                }

                // ── Files: enforce 1:1 and group-folder-wins rules ──────────
                if ($appId === 'files' && $activeFilesRow !== null) {
                    $this->insertDiscoveredFilesRowWithGuard(
                        $teamId, $resourceId, $activeFilesRow
                    );
                    continue;
                }

                $this->insertDiscoveredRow($teamId, $appId, $resourceId);
            }

            // — Resources in our table but not in NC reality → delete —
            foreach ($localRows as $row) {
                if (!isset($realIdSet[$row->getResourceId()])) {
                    $this->resourceMapper->deleteById($row->getId());
                    $this->auditService->log(
                        $teamId,
                        'resource.external_withdrawn',
                        null, // system actor
                        'resource',
                        "{$appId}:{$row->getResourceId()}",
                        ['app_id' => $appId, 'resource_id' => $row->getResourceId()],
                    );
                    $this->logger->info('[TeamHub][ResourceDiscoveryService] resource externally withdrawn', [
                        'teamId' => $teamId, 'appId' => $appId,
                        'resourceId' => $row->getResourceId(), 'app' => Application::APP_ID,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Never crash the page load — reconciliation failure is non-fatal.
            $this->logger->warning('[TeamHub][ResourceDiscoveryService] reconcileApp failed', [
                'teamId' => $teamId, 'appId' => $appId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Insert a newly discovered resource row with the correct origin and status.
     */
    private function insertDiscoveredRow(string $teamId, string $appId, string $resourceId): void {
        $ownerUid = $this->getResourceOwner($teamId, $appId, $resourceId);
        $ownerIsAdmin = $ownerUid !== null && $this->isTeamAdmin($teamId, $ownerUid);

        if ($ownerIsAdmin) {
            $origin = 'discovered_auto_accepted';
            $status = 'active';
        } else {
            $origin = 'discovered_pending';
            $status = 'pending';
        }

        $row = $this->resourceMapper->insertResource(
            $teamId,
            $appId,
            $resourceId,
            $origin,
            $status,
            'none',
            0,
            null,
            null,
            $ownerUid,
        );

        // Audit: discovered
        $this->auditService->log(
            $teamId,
            'resource.discovered',
            null, // system actor
            'resource',
            "{$appId}:{$resourceId}",
            ['app_id' => $appId, 'resource_id' => $resourceId, 'origin' => $origin],
        );

        // Audit: auto_accepted (only when auto-accepted)
        if ($status === 'active') {
            $this->auditService->log(
                $teamId,
                'resource.auto_accepted',
                null,
                'resource',
                "{$appId}:{$resourceId}",
                ['app_id' => $appId, 'resource_id' => $resourceId, 'owner' => $ownerUid],
            );
        }

        $this->logger->info('[TeamHub][ResourceDiscoveryService] resource discovered', [
            'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
            'origin' => $origin, 'status' => $status, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * Handle a newly discovered files resource when the team already has an active
     * files resource. Enforces the 1:1 rule and the group-folder-wins precedence.
     *
     * Rules:
     *   Active = shared folder (non-gf:), incoming = gf:
     *     → Insert as pending with isDualFolderPending context (migration flow).
     *   Active = GF (gf:), incoming = anything
     *     → Suppress (insert as ignored). Audit: resource.suppressed_duplicate.
     *   Active = shared folder (non-gf:), incoming = another shared folder
     *     → Suppress (insert as ignored). Audit: resource.suppressed_duplicate.
     */
    private function insertDiscoveredFilesRowWithGuard(
        string           $teamId,
        string           $resourceId,
        TeamAppResource  $activeRow
    ): void {
        $activeId    = $activeRow->getResourceId();
        $activeIsGf  = str_starts_with($activeId, 'gf:');
        $incomingIsGf = str_starts_with($resourceId, 'gf:');

        // Case 1: active = shared folder, incoming = GF → migration pending
        if (!$activeIsGf && $incomingIsGf) {
            $ownerUid = $this->getResourceOwner($teamId, 'files', $resourceId);
            $this->resourceMapper->insertResource(
                teamId:      $teamId,
                appId:       'files',
                resourceId:  $resourceId,
                origin:      'discovered_pending',
                status:      'pending',
                riskStatus:  'none',
                displayOrder: 0,
                decidedBy:   null,
                decidedAt:   null,
                ownerUid:    $ownerUid,
            );
            $this->auditService->log(
                $teamId,
                'resource.discovered',
                null,
                'resource',
                "files:{$resourceId}",
                ['app_id' => 'files', 'resource_id' => $resourceId,
                 'origin' => 'discovered_pending', 'note' => 'dual_folder_pending_review'],
            );
            $this->logger->info('[TeamHub][ResourceDiscoveryService] files GF discovered alongside active shared folder — dual-folder pending', [
                'teamId' => $teamId, 'resourceId' => $resourceId,
                'activeResourceId' => $activeId, 'app' => Application::APP_ID,
            ]);
            return;
        }

        // Case 2: active = GF or active = shared, incoming would be a duplicate type
        // → suppress immediately (insert as ignored)
        $reason = $activeIsGf
            ? 'active_group_folder_takes_precedence'
            : 'duplicate_shared_folder_rejected';

        $ownerUid = $this->getResourceOwner($teamId, 'files', $resourceId);
        $this->resourceMapper->insertResource(
            teamId:      $teamId,
            appId:       'files',
            resourceId:  $resourceId,
            origin:      'discovered_pending',
            status:      'ignored',
            riskStatus:  'none',
            displayOrder: 0,
            decidedBy:   null,
            decidedAt:   null,
            ownerUid:    $ownerUid,
        );
        $this->auditService->log(
            $teamId,
            'resource.suppressed_duplicate',
            null, // system actor
            'resource',
            "files:{$resourceId}",
            [
                'app_id'            => 'files',
                'resource_id'       => $resourceId,
                'active_resource_id' => $activeId,
                'reason'            => $reason,
            ],
        );
        $this->logger->info('[TeamHub][ResourceDiscoveryService] files resource suppressed — 1:1 constraint', [
            'teamId'           => $teamId,
            'resourceId'       => $resourceId,
            'activeResourceId' => $activeId,
            'reason'           => $reason,
            'app'              => Application::APP_ID,
        ]);
    }

    /**
     * Re-evaluate risk_status for an existing row during reconciliation.
     *
     * Catches drift that event listeners may have missed (e.g. user was disabled
     * while TeamHub was not installed, or owner_uid was null on a grandfathered row).
     *
     * Logic:
     * - If owner_uid is null → resolve it now and persist it.
     * - If owner_uid is set and owner is disabled → ensure risk_status = 'owner_disabled'.
     * - If owner_uid is set and owner is enabled  → clear risk_status if it was 'owner_disabled'.
     * - transfer_failed is not touched here — that requires human action (admin accepts/re-assigns).
     */
    private function refreshRiskStatus(string $teamId, string $appId, string $resourceId, TeamAppResource $row): void {
        try {
            // Always re-resolve the live owner from the NC app table.
            // This catches external ownership changes (e.g. Deck board transferred
            // to another user directly in Deck) that we never received an event for.
            $liveOwnerUid = $this->getResourceOwner($teamId, $appId, $resourceId);

            // Persist owner_uid if it is missing or has drifted from reality.
            if ($liveOwnerUid !== null && $liveOwnerUid !== $row->getOwnerUid()) {
                $this->resourceMapper->updateOwnerUid($row->getId(), $liveOwnerUid);
                $this->logger->debug('[TeamHub][ResourceDiscoveryService] owner_uid updated', [
                    'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
                    'old' => $row->getOwnerUid(), 'new' => $liveOwnerUid,
                    'app' => Application::APP_ID,
                ]);
                $row->setOwnerUid($liveOwnerUid);
            }

            $ownerUid = $row->getOwnerUid();

            if ($ownerUid === null) {
                // Still can't determine owner — leave risk_status as-is.
                return;
            }

            $ownerEnabled = $this->isUserEnabled($ownerUid);

            if (!$ownerEnabled && $row->getRiskStatus() === 'none') {
                // Owner is disabled but risk wasn't flagged (missed event or ownership drift).
                $this->resourceMapper->updateRiskStatus($row->getId(), 'owner_disabled');
                $this->auditService->log(
                    $teamId, 'resource.risk_flagged', null,
                    'resource', "{$appId}:{$resourceId}",
                    ['app_id' => $appId, 'resource_id' => $resourceId,
                     'owner_uid' => $ownerUid, 'reason' => 'reconcile_drift'],
                );
            } elseif ($ownerEnabled && $row->getRiskStatus() === 'owner_disabled') {
                // Owner was re-enabled but risk wasn't cleared (missed event).
                $this->resourceMapper->updateRiskStatus($row->getId(), 'none');
                $this->auditService->log(
                    $teamId, 'resource.risk_cleared', null,
                    'resource', "{$appId}:{$resourceId}",
                    ['app_id' => $appId, 'resource_id' => $resourceId, 'owner_uid' => $ownerUid],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceDiscoveryService] refreshRiskStatus failed', [
                'teamId' => $teamId, 'appId' => $appId,
                'resourceId' => $resourceId, 'error' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Check whether a NC user account is currently enabled.
     * Uses oc_preferences (app='core', configkey='enabled', value='false' = disabled).
     */
    private function isUserEnabled(string $uid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configvalue')
                ->from('preferences')
                ->where($qb->expr()->eq('userid',    $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('appid',  $qb->createNamedParameter('core')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('enabled')))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            // If no row exists, the user is enabled (default).
            if (!$row) {
                return true;
            }
            return $row['configvalue'] !== 'false';
        } catch (\Throwable) {
            // Assume enabled on error — better to miss a flag than false-positive.
            return true;
        }
    }

    // -------------------------------------------------------------------------
    // NC reality queries — what does each app's ACL table actually say?
    // -------------------------------------------------------------------------

    /**
     * Return the resource IDs (as strings) where the team's circle has live ACL access.
     *
     * @return string[]
     */
    private function getRealResourceIds(string $teamId, string $appId): array {
        return match ($appId) {
            'files'    => $this->getRealFileIds($teamId),
            'talk'     => $this->getRealTalkTokens($teamId),
            'calendar' => $this->getRealCalendarIds($teamId),
            'deck'     => $this->getRealDeckBoardIds($teamId),
            default    => [],
        };
    }

    /**
     * Files: oc_share rows where share_type=7 (circle) and share_with=teamId.
     * resource_id = file_source (integer, stored as string).
     * Also includes Group Folder IDs prefixed 'gf:{id}' when GroupFolders is installed.
     */
    private function getRealFileIds(string $teamId): array {
        // ── Legacy shared-folder shares ───────────────────────────────────────
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_source')
            ->from('share')
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (string)(int)$row['file_source'];
        }
        $result->closeCursor();

        // ── Group Folder-backed resources ─────────────────────────────────────
        // resource_id format: 'gf:{folderId}' — distinct from share-based IDs.
        $gfIds = $this->groupFolderService->getRealGroupFolderResourceIds($teamId);
        $ids   = array_merge($ids, $gfIds);

        $this->logger->debug('[TeamHub][ResourceDiscoveryService] getRealFileIds', [
            'teamId' => $teamId, 'shareIds' => count($ids) - count($gfIds),
            'gfIds' => count($gfIds), 'app' => Application::APP_ID,
        ]);

        return $ids;
    }

    /**
     * Talk: oc_talk_attendees rows where actor_type='circles' and actor_id=teamId.
     * resource_id = room token (JOIN to talk_rooms).
     */
    private function getRealTalkTokens(string $teamId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('r.token')
                ->from('talk_attendees', 'a')
                ->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
                ->where($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('a.actor_id', $qb->createNamedParameter($teamId)));

            $result = $qb->executeQuery();
            $tokens = [];
            while ($row = $result->fetch()) {
                $tokens[] = $row['token'];
            }
            $result->closeCursor();
            return $tokens;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceDiscoveryService] getRealTalkTokens failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Calendar: oc_dav_shares rows where principaluri='principals/circles/{teamId}'
     * and type='calendar' and access IN (1,2,3).
     *
     * NC Calendar uses these access values for circle/group shares:
     *   1 = read-only
     *   2 = read-write (older NC Calendar versions)
     *   3 = read-write (NC Calendar 5.x+ circle shares)
     *   4 = public link (publicuri) — not a team share, excluded
     *
     * We include all three to survive NC Calendar version changes without
     * losing connected calendars.
     */
    private function getRealCalendarIds(string $teamId): array {
        try {
            $principal = 'principals/circles/' . $teamId;
            $qb = $this->db->getQueryBuilder();
            $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('principaluri', $qb->createNamedParameter($principal)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->in(
                    'access',
                    $qb->createNamedParameter([1, 2, 3], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                ));

            $result = $qb->executeQuery();
            $ids = [];
            while ($row = $result->fetch()) {
                $ids[] = (string)(int)$row['resourceid'];
            }
            $result->closeCursor();

            $this->logger->debug('[TeamHub][ResourceDiscoveryService] getRealCalendarIds', [
                'teamId' => $teamId, 'count' => count($ids), 'app' => \OCA\TeamHub\AppInfo\Application::APP_ID,
            ]);

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceDiscoveryService] getRealCalendarIds failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => \OCA\TeamHub\AppInfo\Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Deck: check both deck_board_acl and deck_acl (Deck 1.x vs 2.x).
     * participant=teamId, type=1 (group/circle type).
     * resource_id = board_id (integer, stored as string).
     */
    private function getRealDeckBoardIds(string $teamId): array {
        $ids = [];
        foreach (['deck_board_acl', 'deck_acl'] as $table) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('board_id')
                    ->from($table)
                    ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)));

                $result = $qb->executeQuery();
                while ($row = $result->fetch()) {
                    $id = (string)(int)$row['board_id'];
                    if (!in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                $result->closeCursor();
            } catch (\Throwable) {
                // Table may not exist on this Deck version — skip silently.
            }
        }
        return $ids;
    }

    // -------------------------------------------------------------------------
    // Owner resolution — per §5.3 of the design doc
    // -------------------------------------------------------------------------

    /**
     * Return the NC user UID who owns the given resource, or null if unknown.
     */
    private function getResourceOwner(string $teamId, string $appId, string $resourceId): ?string {
        return match ($appId) {
            'files'    => $this->getFilesOwner($teamId, $resourceId),
            'talk'     => $this->getTalkOwner($resourceId),
            'calendar' => $this->getCalendarOwner($resourceId),
            'deck'     => $this->getDeckOwner($resourceId),
            default    => null,
        };
    }

    /**
     * Files: uid_owner from the share row for share-based resources,
     * or null for group-folder-backed resources (gf: prefix) — GF resources
     * have no personal owner, so they always go to pending review on discovery.
     */
    private function getFilesOwner(string $teamId, string $resourceId): ?string {
        // Group folder resources have no share owner — always treat as pending.
        if (str_starts_with($resourceId, 'gf:')) {
            return null;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('uid_owner')
                ->from('share')
                ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? ($row['uid_owner'] ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Talk: actor_type='users', participant_type=1 (OWNER) in talk_attendees for the room.
     */
    private function getTalkOwner(string $token): ?string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('a.actor_id')
                ->from('talk_attendees', 'a')
                ->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
                ->where($qb->expr()->eq('r.token', $qb->createNamedParameter($token)))
                ->andWhere($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')))
                ->andWhere($qb->expr()->eq('a.participant_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? ($row['actor_id'] ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Calendar: parse the principaluri of the calendar row.
     * Format: principals/users/{uid}  — returns uid.
     * Circle-principal calendars return null (no individual owner).
     */
    private function getCalendarOwner(string $resourceId): ?string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('principaluri')
                ->from('calendars')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            if (!$row) {
                return null;
            }
            $principal = $row['principaluri'] ?? '';
            // e.g. principals/users/alice → alice
            if (preg_match('#^principals/users/(.+)$#', $principal, $m)) {
                return $m[1];
            }
            return null; // circle-owned or unknown
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Deck: deck_boards.owner column.
     */
    private function getDeckOwner(string $resourceId): ?string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('owner')
                ->from('deck_boards')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? ($row['owner'] ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a given user has team-admin level (≥ 8) on the team.
     */
    private function isTeamAdmin(string $teamId, string $uid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('level')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row && (int)$row['level'] >= 8;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return all team IDs (circles_circle.unique_id) for the cron sweep.
     * @return string[]
     */
    private function getAllTeamIds(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id')->from('circles_circle');
            $result = $qb->executeQuery();
            $ids = [];
            while ($row = $result->fetch()) {
                $ids[] = $row['unique_id'];
            }
            $result->closeCursor();
            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceDiscoveryService] getAllTeamIds failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Serialize a TeamAppResource entity to an array for API responses.
     * Includes a resolved displayName for the resource (human-readable).
     */
    private function serializeRow(TeamAppResource $row): array {
        return [
            'id'           => $row->getId(),
            'teamId'       => $row->getTeamId(),
            'appId'        => $row->getAppId(),
            'resourceId'   => $row->getResourceId(),
            'displayName'  => $this->resolveDisplayName($row->getAppId(), $row->getResourceId()),
            'ownerUid'     => $row->getOwnerUid(),
            'origin'       => $row->getOrigin(),
            'status'       => $row->getStatus(),
            'riskStatus'   => $row->getRiskStatus(),
            'displayOrder' => $row->getDisplayOrder(),
            'decidedBy'    => $row->getDecidedBy(),
            'decidedAt'    => $row->getDecidedAt(),
            'riskSetAt'    => $row->getRiskSetAt(),
            'createdAt'    => $row->getCreatedAt(),
            'updatedAt'    => $row->getUpdatedAt(),
        ];
    }

    /**
     * Resolve a human-readable display name for a resource.
     * Falls back to the raw resourceId string if the lookup fails or the
     * underlying app table is absent (app not installed).
     *
     * @param string $appId      One of: files, talk, calendar, deck
     * @param string $resourceId The NC resource identifier stored in our table
     * @return string            Display name, never empty — falls back to resourceId
     */
    private function resolveDisplayName(string $appId, string $resourceId): string {
        try {
            return match ($appId) {
                'files'    => $this->resolveFileName($resourceId),
                'talk'     => $this->resolveTalkName($resourceId),
                'calendar' => $this->resolveCalendarName($resourceId),
                'deck'     => $this->resolveDeckName($resourceId),
                default    => $resourceId,
            };
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][ResourceDiscoveryService] resolveDisplayName failed', [
                'appId' => $appId, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return $resourceId;
        }
    }

    /**
     * Files: look up the file/folder name from oc_filecache using fileid,
     * or from group_folders for gf:-prefixed resource IDs.
     * resourceId is either a plain integer string (share-based) or 'gf:{id}'.
     */
    private function resolveFileName(string $resourceId): string {
        // Group Folder-backed resource: use mount_point as display name.
        if (str_starts_with($resourceId, 'gf:')) {
            $gfData = $this->groupFolderService->resolveGroupFolderResourceId($resourceId);
            if ($gfData !== null) {
                return $gfData['mount_point'] ?: $resourceId;
            }
            return $resourceId;
        }

        // Legacy share-based resource: look up file name from filecache.
        // Select both name and path so we can fall back to basename(path)
        // when name is empty (occurs on some storage backends).
        $qb = $this->db->getQueryBuilder();
        $qb->select('name', 'path')
            ->from('filecache')
            ->where($qb->expr()->eq(
                'fileid',
                $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return $resourceId;
        }

        $name = (string)($row['name'] ?? '');
        if ($name === '' && ($row['path'] ?? '') !== '') {
            $name = basename((string)$row['path']);
        }
        return $name !== '' ? $name : $resourceId;
    }

    /**
     * Talk: look up the room name from oc_talk_rooms using the room token.
     * resourceId IS the token (string).
     */
    private function resolveTalkName(string $resourceId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
            ->from('talk_rooms')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($resourceId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        // talk_rooms.name can be empty for one-on-one conversations.
        // Fall back to the token so the admin can still identify it.
        $name = $row ? (string)($row['name'] ?? '') : '';
        return $name !== '' ? $name : $resourceId;
    }

    /**
     * Calendar: look up displayname from oc_calendars using the calendar ID.
     * resourceId is the calendar ID cast to string (integer).
     */
    private function resolveCalendarName(string $resourceId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('displayname', 'uri')
            ->from('calendars')
            ->where($qb->expr()->eq(
                'id',
                $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return $resourceId;
        }
        // Prefer displayname, fall back to uri (NC stores the short name there),
        // then the raw ID.
        $name = (string)($row['displayname'] ?? '');
        if ($name === '') {
            $name = (string)($row['uri'] ?? '');
        }
        return $name !== '' ? $name : $resourceId;
    }

    /**
     * Deck: look up the board title from oc_deck_boards using the board ID.
     * resourceId is the board ID cast to string (integer).
     */
    private function resolveDeckName(string $resourceId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('title')
            ->from('deck_boards')
            ->where($qb->expr()->eq(
                'id',
                $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        $title = $row ? (string)($row['title'] ?? '') : '';
        return $title !== '' ? $title : $resourceId;
    }
}
