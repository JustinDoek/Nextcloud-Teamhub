<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ResourceMembershipService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Reconcile a team's connected resources the moment Circles finishes changing
 * its effective membership (GitHub #87, v4.7.10).
 *
 * Why a listener, when MemberService already reconciles
 * ─────────────────────────────────────────────────────
 * Because for a *direct* member add, the synchronous call site is structurally
 * too early. Adding a user runs in two separate HTTP requests:
 *
 *   1. The request TeamHub handles. It asks Circles to add the member and
 *      returns. Circles has queued a federated event and done little else.
 *   2. A later POST to /apps/circles/async/{token}, where
 *      `CircleJoin::manage()` actually calls `memberService->insertOrUpdate()`
 *      and then `membershipService->onUpdate()`.
 *
 * A reconcile in (1) reads a `circles_member` row that is not confirmed yet and
 * a `circles_membership` cache that has not been built yet, so it correctly
 * concludes there is nothing to do. Observed on 2026-08-29: the member row
 * carried `joined = 11:24:46`, the async request ran at 11:24:52, and the Talk
 * attendee row was still missing afterwards.
 *
 * Attaching a group or sub-team does not have this problem — `inviteMembers()`
 * auto-confirms those itself and rebuilds the cache in request (1) — which is
 * exactly why group inheritance started working from the synchronous call and
 * individual adds did not.
 *
 * Why this fires even though Talk's own listener is broken
 * ────────────────────────────────────────────────────────
 * `CircleJoin::manage()` calls `onUpdate()` — which dispatches the events this
 * class listens to — on the line *before* `memberJoining()`, which is where
 * spreed's `CircleMembershipListener` throws its TypeError (HANDOFF §0000). We
 * are upstream of the crash, so we still run on an instance suffering it. That
 * is the whole point: the crash is why the attendee row is missing, and this
 * listener is what puts it back.
 *
 * Not covered: `MembershipsEditedEvent` exists in Circles but is never
 * dispatched, so a pure level change arrives through neither event. The hourly
 * TalkMembershipReconcileJob remains the backstop for that and for anything
 * that fails here.
 *
 * @template-implements IEventListener<Event>
 */
class CircleMembershipChangedListener implements IEventListener {

    /**
     * Re-entrancy guard. The reconcile itself must never rebuild the Circles
     * cache (we pass rebuildCache: false for that reason), but a resource sync
     * could still, indirectly, cause Circles to emit another membership event.
     * One flag is enough: everything this listener does is idempotent, so
     * dropping a nested pass loses nothing.
     */
    private static bool $running = false;

    public function __construct(
        private ResourceMembershipService $resourceMembership,
        private LoggerInterface           $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (self::$running) {
            return;
        }

        $memberships = [];
        if (method_exists($event, 'getMemberships')) {
            /** @var iterable<mixed> $memberships */
            $memberships = $event->getMemberships();
        }

        // Distinct circle ids only: one group add produces a Membership per
        // affected user, and they very often share a circle. Reconciling per
        // membership would do the same diff a dozen times.
        $circleIds = [];
        foreach ($memberships as $membership) {
            if (!method_exists($membership, 'getCircleId')) {
                continue;
            }
            $circleId = (string)$membership->getCircleId();
            if ($circleId !== '') {
                $circleIds[$circleId] = true;
            }
        }

        if ($circleIds === []) {
            return;
        }

        self::$running = true;
        try {
            foreach (array_keys($circleIds) as $circleId) {
                // No "is this a TeamHub team" lookup on purpose. The reconcile
                // returns immediately when the circle has no Talk room attached,
                // which every non-team circle and every team without a chat
                // satisfies — one query, and it cannot fall out of step with
                // whatever the definition of a team becomes later.
                $this->resourceMembership->reconcileTeamMembership(
                    $circleId,
                    'circles_membership_changed',
                    false,
                );
            }
        } catch (\Throwable $e) {
            // Circles is mid-operation and dispatched this event itself. A
            // throw here would abort somebody else's membership write, which is
            // a far worse outcome than a missing attendee row the hourly job
            // will pick up.
            $this->logger->warning('[TeamHub][CircleMembershipChanged] reconcile failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        } finally {
            self::$running = false;
        }
    }
}
