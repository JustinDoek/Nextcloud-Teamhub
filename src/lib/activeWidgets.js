/**
 * TeamHub — single source of truth for "which home widgets are active".
 *
 * Why this file exists
 * --------------------
 * The gating rules lived in two places that drifted: TeamWidgetGrid's
 * `activeWidgetIds` computed (which applied the admin's hidden-widget list)
 * and TeamView's `getActiveWidgetIds()` (which did not). The grid stopped
 * rendering a hidden widget while TeamView's reflow still reserved its rows,
 * leaving a hole exactly the height of the widget the admin had hidden.
 * That divergence was the root cause fixed in v4.6.1.
 *
 * Any new widget gate belongs here and nowhere else.
 *
 * The `applyHidden` distinction
 * -----------------------------
 * Two callers want different answers and the difference is deliberate:
 *
 *  - The grid asks "what do I render?" → applyHidden: true. An admin-hidden
 *    widget must not appear.
 *  - Manage Team → Settings → Dashboard asks "what can be toggled?" →
 *    applyHidden: false. It must list already-hidden widgets, otherwise the
 *    admin who hid one could never un-hide it.
 */

/**
 * Whether the Project Health widget is active for this viewer.
 *
 * Advanced projects in Planning or Execution phase, where the viewer can see
 * BOTH the Budget and Time tabs. Both `can_view_*` flags are precomputed on
 * the layout bundle, so this stays a synchronous read.
 *
 * @param {object} ctx see computeActiveWidgetIds
 * @return {boolean}
 */
export function isProjectHealthActive(ctx) {
    const phase = ctx.project?.phase
    return !!(
        ctx.project?.isProject
        && ctx.project?.mode === 'advanced'
        && (phase === 'planning' || phase === 'execution')
        && ctx.budgetConfig?.can_view_budget
        && ctx.timeConfig?.can_view_time
    )
}

/**
 * The set of widget IDs whose v-if condition in TeamWidgetGrid is true.
 *
 * @param {object}  ctx                        gating facts, all from the store
 * @param {object}  ctx.resources              team resource bundle
 * @param {object}  ctx.collectivesConfig      { collectives_enabled }
 * @param {boolean} ctx.decisionsModuleEnabled instance-wide Decisions switch
 * @param {object}  ctx.decisionsConfig        { decisions_enabled }
 * @param {object}  ctx.project                project facts from the layout bundle
 * @param {object}  ctx.budgetConfig           { can_view_budget }
 * @param {object}  ctx.timeConfig             { can_view_time }
 * @param {object}  ctx.openProjectConfig      { eligible, linked } (v4.9.3)
 * @param {Array}   ctx.teamWidgets            registered integration widgets
 * @param {object}  ctx.dashboardConfig        { hidden_widgets }
 * @param {object}  [options]
 * @param {boolean} [options.applyHidden=true] subtract admin-hidden widgets
 * @return {Set<string>}
 */
export function computeActiveWidgetIds(ctx, { applyHidden = true } = {}) {
    const active = new Set()

    // Always active — no resource or permission gate.
    active.add('msgstream')
    active.add('widget-teaminfo')
    active.add('widget-members')
    active.add('widget-activity')

    if (ctx.resources?.calendar?.length > 0) {
        active.add('widget-calendar')
    }

    // Tasks widget shows for Deck OR when Tasks app + calendar are both active
    // OR (v4.9.5) when the team is linked to an OpenProject project, whose
    // work packages it then lists by due date.
    if ((ctx.resources?.deck?.length > 0)
        || (ctx.resources?.tasks && ctx.resources?.calendar?.length > 0)
        || (ctx.openProjectConfig?.eligible && ctx.openProjectConfig?.linked)) {
        active.add('widget-deck')
    }

    // v4.3.7 — unified Pages widget renders Intranet (Intravox) + Wiki
    // (Collectives) sections. Active when EITHER provider is on.
    if (ctx.resources?.intravox || ctx.collectivesConfig?.collectives_enabled) {
        active.add('widget-pages')
    }

    if (ctx.resources?.files) {
        active.add('widget-files-center')
    }

    if (ctx.decisionsModuleEnabled && ctx.decisionsConfig?.decisions_enabled) {
        active.add('widget-decisions')
    }

    if (isProjectHealthActive(ctx)) {
        active.add('widget-project-health')
    }

    // v4.9.3 — OpenProject Phase 1. The Project info widget exists exactly
    // when the team is of the OpenProject template (`eligible`) and linked
    // to a project. Deliberately NOT gated on `available`: a member whose
    // integration app is disabled, or who has not connected an account,
    // still sees the widget — with the sentence that says what to do —
    // rather than a dashboard that silently differs from their teammates'.
    // (v4.9.5: the My OpenProject work widget is gone; its rows are in My
    // Work, and the project's are in the Upcoming tasks widget above.)
    if (ctx.openProjectConfig?.eligible && ctx.openProjectConfig?.linked) {
        active.add('widget-openproject')
    }

    ;(ctx.teamWidgets || []).forEach(w => active.add('widget-int-' + w.registry_id))

    // Team-wide owner/admin hidden widgets. Applied last so it overrides every
    // activation rule above. Positions stay in gridLayout, so a widget toggled
    // back on returns to its place.
    if (applyHidden) {
        (ctx.dashboardConfig?.hidden_widgets || []).forEach(id => active.delete(id))
    }

    return active
}
