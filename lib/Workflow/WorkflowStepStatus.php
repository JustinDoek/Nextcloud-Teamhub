<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Exception\WorkflowTransitionException;

/**
 * The statuses a step instance can carry, and the transitions between them
 * (WorkflowHub phase 1, v4.10.13).
 *
 * Steps are strictly sequential in this phase: exactly one step of an open
 * workflow is *active* (available, in progress, or waiting for information)
 * at any moment, every step before it is finished, and every step after it
 * is pending. `WorkflowEngine` is the only writer; it asserts every change
 * here.
 */
final class WorkflowStepStatus {
    /** Not yet reached. */
    public const PENDING                 = 'pending';
    /** The previous step is done (or this is the first); the responsible actor can start it. */
    public const AVAILABLE               = 'available';
    /** A holder of the responsible actor has started it. */
    public const IN_PROGRESS             = 'in_progress';
    /** The actor asked another participant for information; the workflow waits. */
    public const WAITING_FOR_INFORMATION = 'waiting_for_information';
    /** Done; the next step became available (or the workflow completed). Terminal. */
    public const COMPLETED               = 'completed';
    /** Not done because the workflow ended earlier (rejected, or closed by the service team). Terminal. */
    public const SKIPPED                 = 'skipped';
    /** The responsible actor rejected it; the workflow is rejected. Terminal. */
    public const REJECTED                = 'rejected';
    /** The workflow was cancelled while this step was active or pending. Terminal. */
    public const CANCELLED               = 'cancelled';

    public const ALL = [
        self::PENDING, self::AVAILABLE, self::IN_PROGRESS, self::WAITING_FOR_INFORMATION,
        self::COMPLETED, self::SKIPPED, self::REJECTED, self::CANCELLED,
    ];

    /** The one step of an open workflow somebody can act on. */
    public const ACTIVE = [self::AVAILABLE, self::IN_PROGRESS, self::WAITING_FOR_INFORMATION];

    public const TERMINAL = [self::COMPLETED, self::SKIPPED, self::REJECTED, self::CANCELLED];

    private const TRANSITIONS = [
        self::PENDING                 => [self::AVAILABLE, self::SKIPPED, self::CANCELLED],
        self::AVAILABLE               => [self::IN_PROGRESS, self::REJECTED, self::SKIPPED, self::CANCELLED],
        // v4.10.20 - back to AVAILABLE is the *release* of a service
        // team's claimed step: the agent who had it puts it down and it
        // returns to the shared queue. The only backwards edge in this
        // table, and it is the honest one - an in-progress step with
        // nobody on it would read as work in hand that nobody is doing.
        // v4.10.31 - IN_PROGRESS and WAITING_FOR_INFORMATION -> SKIPPED is
        // an admin of the service team closing the request while the step
        // was being worked (WorkflowEngine::closeRequest()): not done, and
        // not rejected either.
        self::IN_PROGRESS             => [self::AVAILABLE, self::WAITING_FOR_INFORMATION, self::COMPLETED, self::REJECTED, self::CANCELLED, self::SKIPPED],
        self::WAITING_FOR_INFORMATION => [self::IN_PROGRESS, self::REJECTED, self::CANCELLED, self::SKIPPED],
        self::COMPLETED               => [],
        self::SKIPPED                 => [],
        self::REJECTED                => [],
        self::CANCELLED               => [],
    ];

    public static function isValid(string $status): bool {
        return in_array($status, self::ALL, true);
    }

    public static function isActive(string $status): bool {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isTerminal(string $status): bool {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Strict: a step never "moves" to the status it already has. Starting a
     * step in progress, answering when nobody asked — those are exactly the
     * stale-view mistakes this table exists to refuse.
     */
    public static function canTransition(string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws WorkflowTransitionException */
    public static function assertTransition(string $from, string $to): void {
        if (!self::canTransition($from, $to)) {
            throw new WorkflowTransitionException(
                sprintf('A workflow step cannot go from "%s" to "%s".', $from, $to),
            );
        }
    }
}
