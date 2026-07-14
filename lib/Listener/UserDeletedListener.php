<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Fires before a user account is deleted.
 *
 * For each resource row owned by the deleted user:
 *   1. Find the most recently active team admin (level ≥ 8) who is a direct member.
 *   2. Attempt to transfer NC resource ownership to that admin (app-specific).
 *   3. On success: update owner_uid, set risk_status = 'none', audit resource.owner_transferred.
 *   4. On failure or no eligible admin: set risk_status = 'transfer_failed', audit resource.transfer_failed.
 *
 * Transfer logic per app:
 *   - files:    UPDATE oc_share SET uid_owner = newUid WHERE id = shareId
 *   - talk:     update talk_attendees actor_id for OWNER row (participant_type = 1)
 *   - calendar: UPDATE oc_calendars SET principaluri = 'principals/users/{newUid}' WHERE id = calendarId
 *   - deck:     UPDATE oc_deck_boards SET owner = newUid WHERE id = boardId
 *
 * Note: These are direct DB writes, not NC API calls. This is intentional — the NC
 * IShareManager / Deck API require a full user session which is unavailable in an
 * event listener. Direct DB writes are consistent with the pattern used elsewhere in
 * TeamHub for cross-app data operations. Flag this in code review if NC adds
 * proper transfer APIs in a future version.
 *
 * LISTENER-ORDERING HAZARD (apps.md W-8):
 * NC does not guarantee the order in which UserDeletedListener implementations
 * run. If Files / Deck / Talk register their own listeners with a higher priority,
 * they may DELETE the departing user's data before this listener gets a chance to
 * REASSIGN it. Symptom: shares disappear on user deletion despite there being an
 * eligible team admin to inherit them. If that regression appears in the wild,
 * pin execution order with a listener priority via #[ListensTo] on the AppInfo
 * registration (e.g. priority: 100 to run earlier).
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

    public function __construct(
        private readonly TeamAppResourceMapper $resourceMapper,
        private readonly AuditService          $auditService,
        private readonly IDBConnection         $db,
        private readonly LoggerInterface       $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof BeforeUserDeletedEvent)) {
            return;
        }

        $uid = $event->getUser()->getUID();

        $this->logger->debug('[TeamHub][UserDeletedListener] user being deleted', [
            'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        try {
            $rows = $this->resourceMapper->findByOwnerUid($uid);

            if (empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $this->processRow($uid, $row);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][UserDeletedListener] handle failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Per-row transfer logic
    // -------------------------------------------------------------------------

    private function processRow(string $deletedUid, \OCA\TeamHub\Db\TeamAppResource $row): void {
        $teamId     = $row->getTeamId();
        $appId      = $row->getAppId();
        $resourceId = $row->getResourceId();

        $this->logger->debug('[TeamHub][UserDeletedListener] processing row', [
            'teamId' => $teamId, 'appId' => $appId,
            'resourceId' => $resourceId, 'app' => Application::APP_ID,
        ]);

        // Find best candidate admin to transfer to.
        $newOwner = $this->findBestTeamAdmin($teamId, $deletedUid);

        if ($newOwner === null) {
            $this->logger->warning('[TeamHub][UserDeletedListener] no eligible admin for transfer', [
                'teamId' => $teamId, 'appId' => $appId,
                'resourceId' => $resourceId, 'app' => Application::APP_ID,
            ]);
            $this->markTransferFailed($row, $deletedUid, 'no_eligible_admin');
            return;
        }

        $transferred = $this->transferOwnership($appId, $resourceId, $deletedUid, $newOwner);

        if ($transferred) {
            $this->resourceMapper->updateOwnerUid($row->getId(), $newOwner);
            $this->resourceMapper->updateRiskStatus($row->getId(), 'none');

            $this->auditService->log(
                $teamId,
                'resource.owner_transferred',
                null,
                'resource',
                "{$appId}:{$resourceId}",
                [
                    'app_id'       => $appId,
                    'resource_id'  => $resourceId,
                    'from_uid'     => $deletedUid,
                    'to_uid'       => $newOwner,
                ],
            );

            $this->logger->info('[TeamHub][UserDeletedListener] ownership transferred', [
                'teamId' => $teamId, 'appId' => $appId, 'resourceId' => $resourceId,
                'from' => $deletedUid, 'to' => $newOwner, 'app' => Application::APP_ID,
            ]);
        } else {
            $this->markTransferFailed($row, $deletedUid, 'transfer_error');
        }
    }

    private function markTransferFailed(\OCA\TeamHub\Db\TeamAppResource $row, string $deletedUid, string $reason): void {
        $this->resourceMapper->updateRiskStatus($row->getId(), 'transfer_failed');

        $this->auditService->log(
            $row->getTeamId(),
            'resource.transfer_failed',
            null,
            'resource',
            $row->getAppId() . ':' . $row->getResourceId(),
            [
                'app_id'      => $row->getAppId(),
                'resource_id' => $row->getResourceId(),
                'owner_uid'   => $deletedUid,
                'reason'      => $reason,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Find best transfer target — most recently active direct team admin
    // -------------------------------------------------------------------------

    /**
     * Return the NC uid of the most recently active team admin (level ≥ 8),
     * excluding the deleted user. Direct members only (user_type = 1).
     */
    private function findBestTeamAdmin(string $teamId, string $excludeUid): ?string {
        try {
            // Get all direct admins of the team.
            $qb = $this->db->getQueryBuilder();
            $qb->select('cm.user_id')
                ->from('circles_member', 'cm')
                ->where($qb->expr()->eq('cm.circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('cm.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->gte('cm.level', $qb->createNamedParameter(8, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->neq('cm.user_id', $qb->createNamedParameter($excludeUid)));

            $result = $qb->executeQuery();
            $adminUids = [];
            while ($row = $result->fetch()) {
                $adminUids[] = $row['user_id'];
            }
            $result->closeCursor();

            if (empty($adminUids)) {
                return null;
            }

            // Among the admins, find the one with the most recent last_login.
            // NC stores this in oc_preferences: app='login', configkey='lastLogin' (ms epoch).
            $pQb = $this->db->getQueryBuilder();
            $pQb->select('userid', 'configvalue')
                ->from('preferences')
                ->where($pQb->expr()->eq('appid', $pQb->createNamedParameter('login')))
                ->andWhere($pQb->expr()->eq('configkey', $pQb->createNamedParameter('lastLogin')))
                ->andWhere($pQb->expr()->in(
                    'userid',
                    $pQb->createNamedParameter($adminUids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
                ));

            $pResult    = $pQb->executeQuery();
            $lastLogins = [];
            while ($pRow = $pResult->fetch()) {
                $lastLogins[$pRow['userid']] = (int)$pRow['configvalue'];
            }
            $pResult->closeCursor();

            // Sort admins by last login descending; fall back to any admin if no login data.
            arsort($lastLogins);
            if (!empty($lastLogins)) {
                return array_key_first($lastLogins);
            }

            // No login data — return first admin alphabetically for determinism.
            sort($adminUids);
            return $adminUids[0];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][UserDeletedListener] findBestTeamAdmin failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Ownership transfer — per app
    // -------------------------------------------------------------------------

    /**
     * Transfer NC resource ownership from $fromUid to $toUid.
     * Returns true on success, false on failure.
     *
     * Direct DB writes — see class docblock for rationale.
     */
    private function transferOwnership(string $appId, string $resourceId, string $fromUid, string $toUid): bool {
        return match ($appId) {
            'files'    => $this->transferFilesOwner($resourceId, $fromUid, $toUid),
            'talk'     => $this->transferTalkOwner($resourceId, $fromUid, $toUid),
            'calendar' => $this->transferCalendarOwner($resourceId, $toUid),
            'deck'     => $this->transferDeckOwner($resourceId, $toUid),
            default    => false,
        };
    }

    /**
     * Files: update uid_owner on the circle share row.
     * file_source = resourceId (integer).
     */
    private function transferFilesOwner(string $resourceId, string $fromUid, string $toUid): bool {
        try {
            $now = time();
            $qb  = $this->db->getQueryBuilder();
            $qb->update('share')
                ->set('uid_owner',    $qb->createNamedParameter($toUid))
                ->set('uid_initiator',$qb->createNamedParameter($toUid))
                ->set('stime',        $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('share_type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('uid_owner',   $qb->createNamedParameter($fromUid)));

            $affected = $qb->executeStatement();
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][UserDeletedListener] transferFilesOwner failed', [
                'resourceId' => $resourceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Talk: update actor_id on the OWNER attendee row (participant_type = 1).
     * resourceId = room token.
     */
    private function transferTalkOwner(string $token, string $fromUid, string $toUid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('talk_attendees', 'a')
                ->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
                ->set('a.actor_id', $qb->createNamedParameter($toUid))
                ->where($qb->expr()->eq('r.token',            $qb->createNamedParameter($token)))
                ->andWhere($qb->expr()->eq('a.actor_type',    $qb->createNamedParameter('users')))
                ->andWhere($qb->expr()->eq('a.actor_id',      $qb->createNamedParameter($fromUid)))
                ->andWhere($qb->expr()->eq('a.participant_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

            $affected = $qb->executeStatement();
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][UserDeletedListener] transferTalkOwner failed', [
                'token' => $token, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Calendar: update principaluri in oc_calendars.
     * resourceId = calendar id (integer).
     */
    private function transferCalendarOwner(string $resourceId, string $toUid): bool {
        try {
            $newPrincipal = 'principals/users/' . $toUid;
            $qb           = $this->db->getQueryBuilder();
            $qb->update('calendars')
                ->set('principaluri', $qb->createNamedParameter($newPrincipal))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

            $affected = $qb->executeStatement();
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][UserDeletedListener] transferCalendarOwner failed', [
                'resourceId' => $resourceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Deck: update owner column in oc_deck_boards.
     * resourceId = board id (integer).
     */
    private function transferDeckOwner(string $resourceId, string $toUid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('deck_boards')
                ->set('owner', $qb->createNamedParameter($toUid))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$resourceId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

            $affected = $qb->executeStatement();
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][UserDeletedListener] transferDeckOwner failed', [
                'resourceId' => $resourceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }
}
