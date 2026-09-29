<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Why an actor is on a workflow's participant list (WorkflowHub phase 1).
 *
 * A participant row is never removed while the workflow is open — a person
 * whose step is done keeps seeing the workflow under *Waiting for others*,
 * which is the product rule this table exists for. `removedAt` is set only
 * by a later maintenance pass (a user deleted, a delegate replaced), never
 * by a step transition.
 */
final class WorkflowParticipantRole {
    /** Created the workflow. Always a user. */
    public const INITIATOR   = 'initiator';
    /** Responsible for one of the steps, as materialised at creation. */
    public const RESPONSIBLE = 'responsible';
    /** A concrete user who acted on a step whose actor is a group or a team role. */
    public const ACTOR       = 'actor';
    /** Added to watch, without a step. Not written by phase 1; reserved. */
    public const OBSERVER    = 'observer';

    public const ALL = [self::INITIATOR, self::RESPONSIBLE, self::ACTOR, self::OBSERVER];

    public static function isValid(string $role): bool {
        return in_array($role, self::ALL, true);
    }
}
