<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One place to say "make the team's connected resources match its membership".
 *
 * Why this exists (GitHub #87, v4.7.9)
 * ────────────────────────────────────
 * Every resource a team owns resolves membership one of two ways:
 *
 *   - **At read time**, by asking Circles. Deck (`deck_board_acl` type 7),
 *     the team folder (`IShare::TYPE_CIRCLE`), the calendar (a `dav_shares`
 *     row for the circle principal) and Collectives all work this way. They
 *     are correct the instant `circles_membership` is correct, and there is
 *     nothing for this service to push at them.
 *   - **Materialised**, as one stored row per person. Talk is the only one:
 *     `talk_attendees` carries a row per attendee, and a person with no row
 *     is told the conversation does not exist.
 *
 * So Talk is the only resource that can drift, and every drift report so far
 * has been Talk. What maintains those rows upstream is Talk's own
 * `CircleMembershipListener`, which on spreed 24.0.3 × circles 34.0.2.1 dies
 * with a TypeError before it adds anybody — see HANDOFF §0000. That is not
 * ours to fix, but it is ours to survive, which is what the reconcile below
 * does.
 *
 * The reason this is a service and not a private method on MemberService is
 * that four different callers need it — invite, remove, leave, and the admin
 * maintenance path — and none of them should have to know which resources
 * exist. When a second materialising resource appears, it is added here and
 * every caller gets it for free.
 *
 * Order matters. `MembershipService::onUpdate()` runs first because it is what
 * flattens a newly attached group or sub-team into `circles_membership`, and
 * the Talk reconcile reads exactly that table. Reconciling first would compute
 * the effective set from a cache that does not yet know about the change —
 * which is the shape of the bug this service was written to close.
 *
 * Nothing here throws. A membership change must not fail because a downstream
 * resource could not be synced; the hourly TalkMembershipReconcileJob remains
 * the backstop for anything that fails or is missed here.
 */
class ResourceMembershipService {

    public function __construct(
        private TalkService        $talkService,
        private ContainerInterface $container,
        private LoggerInterface    $logger,
    ) {
    }

    /**
     * Bring every connected resource in line with the team's current
     * membership.
     *
     * Idempotent and safe to call more than once per request — the reconcile
     * is a diff, so a second run is a pair of empty result sets. Callers
     * should still prefer one call at the end of an operation over one per
     * member, so a ten-member invite does one reconcile rather than ten.
     *
     * @param string $teamId       Circle unique_id of the team
     * @param string $reason       Short slug naming the operation, for the log line
     * @param bool   $rebuildCache Rebuild Circles' membership cache first. Pass
     *                             false when the caller is reacting to Circles
     *                             having just rebuilt it — see the listener note
     *                             below; rebuilding from inside that reaction
     *                             re-fires the event that called us.
     *
     * @return array{added: int, removed: int} Talk attendee rows changed
     */
    public function reconcileTeamMembership(string $teamId, string $reason, bool $rebuildCache = true): array {
        if ($teamId === '') {
            return ['added' => 0, 'removed' => 0];
        }

        // 1. Rebuild Circles' effective-membership cache for this team. This is
        //    what expands an attached group or sub-team into per-user rows, and
        //    every read-time resource (Deck, Files, Calendar, Collectives) is
        //    correct from this point on without us touching them.
        //
        //    Skipped when we are already downstream of Circles' own rebuild:
        //    MembershipService::onUpdate() is what dispatches the Memberships*
        //    events, so calling it from a listener on those events would loop.
        if ($rebuildCache) {
            try {
                $membershipService = $this->container->get(\OCA\Circles\Service\MembershipService::class);
                $membershipService->onUpdate($teamId);
            } catch (\Throwable $e) {
                // Non-fatal: Circles' own maintenance job rebuilds this too. The
                // reconcile below still runs — it has a direct-member fallback that
                // covers the common case of a freshly added individual.
                $this->logger->warning('[TeamHub][ResourceMembership] membership cache rebuild failed', [
                    'teamId' => $teamId,
                    'reason' => $reason,
                    'error'  => $e->getMessage(),
                    'app'    => Application::APP_ID,
                ]);
            }
        }

        // 2. Push the effective membership into Talk, the one resource that
        //    stores it rather than resolving it.
        $result = ['added' => 0, 'removed' => 0];
        try {
            $result = $this->talkService->reconcileEffectiveTalkRoomMembers($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceMembership] Talk reconcile failed', [
                'teamId' => $teamId,
                'reason' => $reason,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }

        if (($result['added'] ?? 0) !== 0 || ($result['removed'] ?? 0) !== 0) {
            $this->logger->info('[TeamHub][ResourceMembership] resources reconciled', [
                'teamId'  => $teamId,
                'reason'  => $reason,
                'added'   => $result['added'] ?? 0,
                'removed' => $result['removed'] ?? 0,
                'app'     => Application::APP_ID,
            ]);
        }

        return $result;
    }
}
