<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Exception\WorkflowTransitionException;

/**
 * The statuses a workflow instance can carry, and the transitions between
 * them (WorkflowHub phase 1, v4.10.13; `docs/workflowhub-architecture.md`).
 *
 * The status of an instance is derived from its steps by the engine
 * (`WorkflowEngine::deriveOpenStatus()`), never set by a caller, and every
 * change passes through `assertTransition()` — the one table below is the
 * whole rule. `blocked` is the exception: it is not derived from a step but
 * declared by a person (the responsible actor cannot proceed), and lifting it
 * re-derives the status from the steps.
 */
final class WorkflowStatus {
    /** Created; the first step is available; nobody has started anything. */
    public const SUBMITTED   = 'submitted';
    /** A step is being worked on, or a later step is available after an earlier one was completed. */
    public const IN_PROGRESS = 'in_progress';
    /** The active step waits for information from another participant. */
    public const WAITING     = 'waiting';
    /** Declared blocked by the responsible actor or an administrator; nothing moves until it is lifted. */
    public const BLOCKED     = 'blocked';
    /** Withdrawn by the initiator or an administrator. Terminal. */
    public const CANCELLED   = 'cancelled';
    /** The responsible actor of a step rejected it. Terminal. */
    public const REJECTED    = 'rejected';
    /** The last step was completed. Terminal. */
    public const COMPLETED   = 'completed';

    public const ALL = [
        self::SUBMITTED, self::IN_PROGRESS, self::WAITING, self::BLOCKED,
        self::CANCELLED, self::REJECTED, self::COMPLETED,
    ];

    /** Statuses in which the workflow still has a step to act on. */
    public const OPEN = [self::SUBMITTED, self::IN_PROGRESS, self::WAITING, self::BLOCKED];

    /** Statuses nothing can follow. */
    public const TERMINAL = [self::CANCELLED, self::REJECTED, self::COMPLETED];

    /**
     * From → the statuses it may move to. Absent from-status or absent
     * to-status means "never". A status may always stay what it is.
     *
     * `SUBMITTED → WAITING` was missing until v4.10.17. A workflow sits in
     * `submitted` until some step completes, so a definition whose first
     * step is not `autoCompleteOnCreate` — `QuotaRequestDefinition`'s
     * `decide` — was in `submitted` while its first step was live, and
     * `requestInformation()` on that step moved the step to
     * `waiting_for_information` and then re-derived the instance as
     * `waiting`, which the table refused with a 409 (HANDOFF §0-wf-submitted).
     * Asking the requester a question before deciding is ordinary use, not
     * an irregular transition, so the entry belongs here.
     */
    private const TRANSITIONS = [
        self::SUBMITTED   => [self::IN_PROGRESS, self::WAITING, self::BLOCKED, self::CANCELLED, self::REJECTED, self::COMPLETED],
        self::IN_PROGRESS => [self::WAITING, self::BLOCKED, self::CANCELLED, self::REJECTED, self::COMPLETED],
        // v4.10.31 - WAITING and BLOCKED -> COMPLETED is an admin of the
        // service team closing the request (outcome `closed`), which may
        // happen at any moment, including while it waits or is blocked.
        self::WAITING     => [self::IN_PROGRESS, self::BLOCKED, self::CANCELLED, self::REJECTED, self::COMPLETED],
        self::BLOCKED     => [self::SUBMITTED, self::IN_PROGRESS, self::WAITING, self::CANCELLED, self::COMPLETED],
        self::CANCELLED   => [],
        self::REJECTED    => [],
        self::COMPLETED   => [],
    ];

    public static function isValid(string $status): bool {
        return in_array($status, self::ALL, true);
    }

    public static function isOpen(string $status): bool {
        return in_array($status, self::OPEN, true);
    }

    public static function isTerminal(string $status): bool {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(string $from, string $to): bool {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws WorkflowTransitionException */
    public static function assertTransition(string $from, string $to): void {
        if (!self::canTransition($from, $to)) {
            throw new WorkflowTransitionException(
                sprintf('A workflow cannot go from "%s" to "%s".', $from, $to),
            );
        }
    }
}
