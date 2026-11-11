<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Accepting and refusing a team invitation — TeamHub's own path, so that
 * neither depends on Circles' frontend being switched on.
 *
 * v4.13.18. Why this exists at all: §2.190 gave a Nextcloud administrator a
 * switch over `circles`/`frontend_enabled`, and that session's own notes
 * recorded what the flag gates — `LocalController::setCurrentFederatedUser()`
 * throws `FrontendException('frontend disabled')` as the first statement
 * inside every one of that controller's 26 OCS endpoints. What the session
 * missed is that `circleJoin` is one of them, and that the Accept button on
 * Circles' *own* invitation notification posts straight to it. So with the
 * switch off, an invited member clicked Accept and got a raw
 * `FrontendException` — reported by Justin on 2026-10-22, from an invite sent
 * through the sidebar's per-team menu.
 *
 * The fix is not to widen the switch. Circles' **service** layer is ungated:
 * only the controller carries the check. `MemberService::requestJoinTeam()`
 * had already learned to call `CircleService::circleJoin()` directly, for an
 * unrelated reason, so the technique was in the codebase before the switch
 * existed. This service does the same for the two decisions an invitee can
 * take, and TeamHub sends its own notification with its own actions rather
 * than relying on another app's buttons.
 *
 * What it deliberately does **not** do:
 *
 *  - **No invite-only guard.** `requestJoinTeam()` refuses a team whose
 *    config lacks `CFG_OPEN`, which is right for somebody asking to join
 *    uninvited and exactly wrong here: an invitation is the team's answer to
 *    that question already. Circles agrees — `CircleJoin::manageMemberStatus()`
 *    takes an `Invited` row at `LEVEL_NONE` to `LEVEL_MEMBER`/`STATUS_MEMBER`
 *    without consulting the config or an invitation code. Reusing
 *    `requestJoinTeam()` here would refuse every invitation to a private team,
 *    which is nearly all of them.
 *  - **No audit call.** `AuditIngestionService` already maps Circles'
 *    `member_added` activity to `member.joined`, and carries a
 *    `hasRecentDirectLog()` dedupe against direct writes. A line of our own
 *    here would be a second row for one event.
 *  - **No ledger, and no My Work workflow.** The invitation's state is
 *    `circles_member.status`, which is where it already lives — a ledger entry
 *    beside it would be a second source of truth (DESIGN, top). My Work is
 *    also licensed, and accepting an invitation has to work on every instance.
 */
class TeamInvitationService {

    /** Circles' own status literal for a pending invitation. */
    private const STATUS_INVITED = 'Invited';

    /**
     * Notification object type. Keyed by team rather than by the Circles
     * member row id, so a decision can withdraw the invitation without having
     * to resolve an id Circles owns.
     */
    public const OBJECT_TYPE = 'invitation';

    public function __construct(
        private ContainerInterface $container,
        private IDBConnection $db,
        private IUserSession $userSession,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Tell an invited user that they have been invited, in TeamHub's own
     * notification with TeamHub's own actions.
     *
     * Best-effort by design: a failure here must not fail the invite itself,
     * which is already written by the time this runs. The invitee can still
     * accept from the team's shared link, and a team admin can resend.
     *
     * @param string $teamId   The circle id.
     * @param string $teamName Pre-resolved, so this never needs probeCircles().
     * @param string $uid      The invited local user.
     */
    public function notifyInvited(string $teamId, string $teamName, string $uid): void {
        try {
            $actor = $this->userSession->getUser();
            $inviterUid = $actor?->getUID() ?? '';
            $inviterName = $actor?->getDisplayName() ?? '';
            if ($uid === $inviterUid) {
                return;
            }

            // Withdraw any earlier invitation notification for this team first,
            // so a resend replaces rather than stacks. v3.100.10 made resending
            // an invite a supported action; without this the bell grows a row
            // per attempt and every one of them stays clickable.
            $this->withdraw($teamId, $uid);

            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime())
                ->setObject(self::OBJECT_TYPE, $teamId)
                ->setSubject('team_invitation', [
                    'inviter' => $inviterName,
                    'inviterId' => $inviterUid,
                    'team' => $teamName,
                    'teamId' => $teamId,
                ]);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamInvitationService] notifyInvited failed (non-fatal)', [
                'teamId' => $teamId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Accept the invitation the current user holds on this team.
     *
     * @throws \RuntimeException 'not_authenticated' | 'not_invited'
     */
    public function accept(string $teamId): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('not_authenticated');
        }
        $uid = $user->getUID();

        // The authorisation for this endpoint, and the only one available: an
        // invitee is not yet a member, so no team-level gate can speak for
        // them. Holding an `Invited` row *is* the permission, and it is the
        // team's own config that decided to write one.
        if ($this->memberStatus($teamId, $uid) !== self::STATUS_INVITED) {
            throw new \RuntimeException('not_invited');
        }

        $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
        $circleService = $this->container->get(\OCA\Circles\Service\CircleService::class);

        // CircleService, never LocalController — the frontend gate is on the
        // controller only. See the class docblock.
        $federatedUserService->setLocalCurrentUser($user);
        $circleService->circleJoin($teamId);

        $this->logger->info('[TeamHub][TeamInvitationService] invitation accepted', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        $this->withdraw($teamId, $uid);
    }

    /**
     * Refuse the invitation the current user holds on this team.
     *
     * `circleLeave()` is Circles' own refusal path: `CircleLeave` implements
     * `IFederatedItemInitiatorMembershipNotRequired` and checks neither level
     * nor status, so it deletes an `Invited` row at `LEVEL_NONE` as readily as
     * it removes a full member. Nothing needs cleaning up behind it — Circles
     * generates no membership rows below `LEVEL_MEMBER`.
     *
     * @throws \RuntimeException 'not_authenticated' | 'not_invited'
     */
    public function decline(string $teamId): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('not_authenticated');
        }
        $uid = $user->getUID();

        if ($this->memberStatus($teamId, $uid) !== self::STATUS_INVITED) {
            throw new \RuntimeException('not_invited');
        }

        $federatedUserService = $this->container->get(\OCA\Circles\Service\FederatedUserService::class);
        $circleService = $this->container->get(\OCA\Circles\Service\CircleService::class);

        $federatedUserService->setLocalCurrentUser($user);
        $circleService->circleLeave($teamId);

        $this->logger->info('[TeamHub][TeamInvitationService] invitation declined', [
            'teamId' => $teamId, 'uid' => $uid, 'app' => Application::APP_ID,
        ]);

        $this->withdraw($teamId, $uid);
    }

    /**
     * Whether this user currently holds a pending invitation on this team.
     * Lets the frontend offer the decision where the subject is, rather than
     * only in the bell.
     */
    public function isInvited(string $teamId, string $uid): bool {
        return $this->memberStatus($teamId, $uid) === self::STATUS_INVITED;
    }

    /**
     * Retire our invitation notification for this team and user, whichever way
     * the decision went. Scoped by app, user and object, so it touches nothing
     * else in the bell.
     */
    private function withdraw(string $teamId, string $uid): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setObject(self::OBJECT_TYPE, $teamId);
            $this->notificationManager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamInvitationService] withdraw failed (non-fatal)', [
                'teamId' => $teamId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * The raw `status` column for this user's own row on this team, or null
     * when there is none.
     *
     * A direct read, for the reason the rest of MemberService reads this table
     * directly: `probeCircles()` filters on the circle's config bitmask and
     * answers nothing for a team the caller is not yet a member of — which is
     * every team this service is asked about. `user_type = 1` keeps it to the
     * local-user row; a group's row for the same team is a different subject.
     *
     * `protected` so a unit test can answer it without a database, the way
     * `ComplimentService::shareATeam()` is — this query is the one piece of
     * this service no unit test can prove.
     */
    protected function memberStatus(string $teamId, string $uid): ?string {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('status')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (string)$row['status'] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamInvitationService] memberStatus failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }
}
