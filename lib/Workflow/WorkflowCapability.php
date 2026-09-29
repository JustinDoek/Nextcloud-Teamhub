<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * What a person may do with workflows, per licence tier (WorkflowHub
 * phase 4, v4.10.16; `docs/unlicensed-workflow-data-lifecycle.md`).
 *
 * The instance is either licensed or unlicensed — there is no licence per
 * user, team, workflow or feature — so this table has exactly two columns.
 * `WorkflowLicenceTier::can()` reads it; `WorkflowEngine` enforces it on
 * every call; the API hands the whole map to the client so it hides what
 * is not available (hidden, not disabled — CLAUDE.md § Permissions).
 *
 * The unlicensed column is the product's list, capability by capability.
 * Several licensed capabilities still name features that do not exist in
 * any tier (definition editing, analytics, audit export, reopening); the
 * licence would allow them, nothing implements them, and a client must not
 * offer them on the strength of this map. Service Teams were such a name
 * until v4.10.20 built them, and archiving until v4.10.21.
 */
final class WorkflowCapability {

    // ── What the unlicensed tier has ─────────────────────────────────────
    /** The workflows the viewer is responsible for right now. */
    public const VIEW_ACTION_REQUIRED     = 'view_action_required';
    /** The open workflows the viewer takes part in but is not responsible for. */
    public const VIEW_WAITING_FOR_OTHERS  = 'view_waiting_for_others';
    /** Who is responsible for the workflow right now (a person, a group, a role). */
    public const VIEW_RESPONSIBLE_ACTOR   = 'view_responsible_actor';
    /** The actions the viewer may take on the workflow. */
    public const VIEW_AVAILABLE_ACTIONS   = 'view_available_actions';
    /** A basic timeline of what happened, while the workflow is open. */
    public const VIEW_TIMELINE            = 'view_timeline';
    /** Start a built-in workflow that allows unlicensed use. */
    public const START_BUILT_IN           = 'start_built_in';

    // ── Licensed only ────────────────────────────────────────────────────
    /** Which step the workflow is at (the step list and the active step's label). */
    public const VIEW_CURRENT_STEP        = 'view_current_step';
    /** "Step 2 of 4" — the position in the whole. */
    public const VIEW_STEP_PROGRESS       = 'view_step_progress';
    /** Ask the responsible actor for a status update. */
    public const REQUEST_STATUS_UPDATE    = 'request_status_update';
    /** Completed workflows stay readable after they end. */
    public const COMPLETED_HISTORY        = 'completed_history';
    /** Create or modify workflow definitions (no editor exists yet). */
    public const MANAGE_DEFINITIONS       = 'manage_definitions';
    /** Service Teams (built in v4.10.20 — WorkflowHub phase 5). */
    public const SERVICE_TEAMS            = 'service_teams';
    /** Workflow analytics (not built yet). */
    public const ANALYTICS                = 'analytics';
    /** Audit exports (not built yet). */
    public const AUDIT_EXPORT             = 'audit_export';
    /** Reopen a completed workflow (not built yet). */
    public const REOPEN_COMPLETED         = 'reopen_completed';
    /**
     * The workflow archive (built in v4.10.21 — WorkflowHub phase 6;
     * `docs/workflow-archiving.md`). It gates all three halves of the
     * feature: a completed workflow is *recorded* only on an instance that
     * has this capability, an archive is *read* only through it, and
     * workflow documents — which exist in order to be projected into an
     * archive — are gated on it too. An unlicensed instance keeps phase 4's
     * rule instead, and deletes an ended workflow where a licensed one
     * records it.
     */
    public const ARCHIVE_RESULTS          = 'archive_results';

    /** Every capability, in the order the table above lists them. */
    public const ALL = [
        self::VIEW_ACTION_REQUIRED,
        self::VIEW_WAITING_FOR_OTHERS,
        self::VIEW_RESPONSIBLE_ACTOR,
        self::VIEW_AVAILABLE_ACTIONS,
        self::VIEW_TIMELINE,
        self::START_BUILT_IN,
        self::VIEW_CURRENT_STEP,
        self::VIEW_STEP_PROGRESS,
        self::REQUEST_STATUS_UPDATE,
        self::COMPLETED_HISTORY,
        self::MANAGE_DEFINITIONS,
        self::SERVICE_TEAMS,
        self::ANALYTICS,
        self::AUDIT_EXPORT,
        self::REOPEN_COMPLETED,
        self::ARCHIVE_RESULTS,
    ];

    /** What an unlicensed instance has. Everything else needs the licence. */
    public const UNLICENSED = [
        self::VIEW_ACTION_REQUIRED,
        self::VIEW_WAITING_FOR_OTHERS,
        self::VIEW_RESPONSIBLE_ACTOR,
        self::VIEW_AVAILABLE_ACTIONS,
        self::VIEW_TIMELINE,
        self::START_BUILT_IN,
    ];

    /**
     * The capability map of one tier: capability → allowed.
     *
     * @return array<string, bool>
     */
    public static function forTier(string $tier): array {
        $full = $tier === 'full';
        $out  = [];
        foreach (self::ALL as $capability) {
            $out[$capability] = $full || in_array($capability, self::UNLICENSED, true);
        }
        return $out;
    }

    public static function isValid(string $capability): bool {
        return in_array($capability, self::ALL, true);
    }
}
