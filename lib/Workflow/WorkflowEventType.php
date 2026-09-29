<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * What can happen to a workflow (WorkflowHub phase 1, v4.10.13).
 *
 * Every state change the engine makes appends exactly one event of one of
 * these types, with the actor and the moment, to `teamhub_wf_event`. The
 * event log is the source for the tracker's "who and when", for the
 * history a participant will read, and for the audit line the engine writes
 * alongside (`workflow.<definition>.<event>` in `teamhub_audit_log`).
 */
final class WorkflowEventType {
    /** Instance created from a definition; carries `definitionVersion`. */
    public const CREATED               = 'created';
    /** A step became available to its responsible actor. */
    public const STEP_AVAILABLE        = 'step_available';
    /** A holder of the responsible actor started the step. */
    public const STEP_STARTED          = 'step_started';
    /** The step was completed; carries the optional note. */
    public const STEP_COMPLETED        = 'step_completed';
    /** The step was rejected; carries the reason. */
    public const STEP_REJECTED         = 'step_rejected';
    /** A step that was never reached, because the workflow ended. */
    public const STEP_SKIPPED          = 'step_skipped';
    /** The step's actor asked for information; carries the note. */
    public const INFORMATION_REQUESTED = 'information_requested';
    /** A participant answered; carries the note. */
    public const INFORMATION_PROVIDED  = 'information_provided';
    /**
     * v4.10.14 — a participant who is not responsible asked the responsible
     * actor where things stand; carries the note. Changes no status. The
     * rate limit is measured on these.
     */
    public const STATUS_REQUESTED      = 'status_requested';
    /** A participant joined the workflow (initiator, responsible actor, somebody who acted). */
    public const PARTICIPANT_ADDED     = 'participant_added';
    /** The workflow was declared blocked; carries the reason. */
    public const BLOCKED               = 'blocked';
    /** The block was lifted. */
    public const UNBLOCKED             = 'unblocked';
    /** The last step completed. */
    public const COMPLETED             = 'completed';
    /** Ended by a rejection. */
    public const REJECTED              = 'rejected';
    /** Withdrawn; carries the reason. */
    public const CANCELLED             = 'cancelled';
    /** v4.10.31 — closed by an admin of the service team; carries the optional `note`. */
    public const CLOSED                = 'closed';
    /**
     * v4.10.20 — a service agent took an unclaimed step out of the queue.
     * Carries nothing: the actor is who claimed it.
     */
    public const STEP_CLAIMED          = 'step_claimed';
    /** v4.10.20 — a step was handed to another eligible agent; carries `to`. */
    public const STEP_ASSIGNED         = 'step_assigned';
    /** v4.10.20 — a claimed step went back to the queue; carries the optional reason. */
    public const STEP_RELEASED         = 'step_released';
    /**
     * v4.10.20 — a note the service team wrote for itself. The one event
     * type recorded with `visibility = internal`: it is in the workflow's
     * history for the agents and is never returned to the requester.
     */
    public const INTERNAL_NOTE         = 'internal_note';
    /**
     * v4.11.0 — the requester and the person who claimed the request wrote
     * to each other; carries the `note` and any `files`. Changes no status
     * and moves no step: the conversation a request carries, kept in its
     * own history (and so in its archive) rather than in a chat app.
     */
    public const MESSAGE               = 'message';
    /**
     * v4.10.21 — a document was attached; carries `fileId`, `fileName` and
     * the attachment's `visibility`. Recorded `internal` when the document
     * is, so the requester's history does not learn that a file they may
     * not read exists.
     */
    public const ATTACHMENT_ADDED      = 'attachment_added';
    /** v4.10.21 — a document was detached before the workflow ended; carries `fileName`. */
    public const ATTACHMENT_REMOVED    = 'attachment_removed';
    /**
     * v4.10.21 — the completed workflow was archived. The **last** event any
     * workflow ever carries: it is written inside the ending transaction,
     * before the seal is computed, and no write path can append to an ended
     * instance afterwards. Carries the `reference` and the `archiveId`.
     */
    public const ARCHIVED              = 'archived';

    public const ALL = [
        self::CREATED, self::STEP_AVAILABLE, self::STEP_STARTED, self::STEP_COMPLETED,
        self::STEP_REJECTED, self::STEP_SKIPPED, self::INFORMATION_REQUESTED,
        self::INFORMATION_PROVIDED, self::STATUS_REQUESTED, self::PARTICIPANT_ADDED, self::BLOCKED, self::UNBLOCKED,
        self::COMPLETED, self::REJECTED, self::CANCELLED, self::CLOSED,
        self::STEP_CLAIMED, self::STEP_ASSIGNED, self::STEP_RELEASED, self::INTERNAL_NOTE, self::MESSAGE,
        self::ATTACHMENT_ADDED, self::ATTACHMENT_REMOVED, self::ARCHIVED,
    ];

    /**
     * Event visibility (v4.10.20).
     *
     * `ALL` is everything every participant may read — which is every event
     * type but one. `INTERNAL` is the service team's own record: written by
     * an eligible agent, read by eligible agents, filtered out of the
     * history for the person who made the request. The split is a column on
     * the row rather than a rule about the type, so a later internal
     * variant of an existing type costs nothing.
     */
    public const VISIBILITY_ALL      = 'all';
    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITIES = [self::VISIBILITY_ALL, self::VISIBILITY_INTERNAL];

    public static function isValidVisibility(string $visibility): bool {
        return in_array($visibility, self::VISIBILITIES, true);
    }

    public static function isValid(string $type): bool {
        return in_array($type, self::ALL, true);
    }
}
