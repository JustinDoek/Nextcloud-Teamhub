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
 * Whether a capability draws its own card body — v4.13.24, shared v4.13.26.
 *
 * Keyed on the server's `cap_type`, not on the presence of a `url`: the two
 * agree today, and a descriptor that gained a URL for some other reason must
 * not silently start rendering as a frame.
 *
 * Shared because three surfaces ask it — the desktop grid, the tablet stack
 * and the mobile canvas — and each one has to answer it the same way twice:
 * once to pick the body component, once to give that body a height. The
 * desktop grid and MobileWidgetView each carried their own copy of the test,
 * which is the shape §2.199 and §2.200 were both about.
 *
 * @param {object} cap a capability descriptor from packageWidgets
 * @return {boolean}
 */
export function isCanvasCapability(cap) {
    return cap?.cap_type === 'widget_iframe'
}

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
 * @param {object}  ctx.messagesConfig         { messages_enabled } (v4.13.35)
 * @param {object}  ctx.collectivesConfig      { collectives_enabled }
 * @param {boolean} ctx.decisionsModuleEnabled instance-wide Decisions switch
 * @param {object}  ctx.decisionsConfig        { decisions_enabled }
 * @param {object}  ctx.project                project facts from the layout bundle
 * @param {object}  ctx.budgetConfig           { can_view_budget }
 * @param {object}  ctx.timeConfig             { can_view_time }
 * @param {object}  ctx.openProjectConfig      { eligible, linked } (v4.9.3)
 * @param {object}  ctx.serviceDeskConfig      { isDesk, isAdmin } (v4.10.27), { isServiceTeam, canBuild } (v4.10.34)
 * @param {Array}   ctx.teamWidgets            registered integration widgets (legacy registry)
 * @param {Array}   ctx.packageWidgets         shown widget capabilities (v4.13.23 packages)
 * @param {object}  ctx.dashboardConfig        { hidden_widgets }
 * @param {object}  [options]
 * @param {boolean} [options.applyHidden=true] subtract admin-hidden widgets
 * @return {Set<string>}
 */
export function computeActiveWidgetIds(ctx, { applyHidden = true } = {}) {
    const active = new Set()

    // v4.13.35 — the message stream is gated on the team's Messages switch,
    // and that gate belongs here.
    //
    // It did not live here until now, and TeamWidgetGrid's template carried a
    // second one (`showMessagesWidget`) instead. For four releases that cost
    // nothing, because the only thing reading this set was a filter over the
    // same template. Then v4.13.33 made the set the input to the *packer* —
    // and a team with Messages switched off got a nine-column, nine-row hole
    // at the top of its home, because the packer reserved the stream's block
    // for a card the template then refused to draw.
    //
    // This is the divergence in this file's own docblock, happening again: one
    // surface deciding what to render and another deciding what space to
    // reserve. `!== false` rather than a truthiness test, matching the
    // template's gate exactly — the config is absent until the bundle lands,
    // and absent means on.
    if (ctx.messagesConfig?.messages_enabled !== false) {
        active.add('msgstream')
    }

    // Always active — no resource or permission gate.
    active.add('widget-teaminfo')
    active.add('widget-members')
    active.add('widget-activity')
    if (ctx.teamType === 'department' && ctx.presenceModuleEnabled
        && ctx.presenceConfig?.presence_enabled) {
        active.add('widget-birthdays')
    }

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

    // v4.10.27 — the service team's queue and statistics (`/service-teams`).
    // Exactly when the team is a desk the viewer works: licensed, an active
    // service team, and a member of it — the rule the queue route applies.
    if (ctx.serviceDeskConfig?.isDesk) {
        active.add('widget-service-queue')
        active.add('widget-service-stats')
    }

    // v4.10.50 — the grid of teams made outside TeamHub, on the home of the
    // team that holds the adoption service, for its members. The same rule
    // the grid's routes apply (TeamAdoptionDecisionService::mayDecide()).
    if (ctx.serviceDeskConfig?.handlesAdoption) {
        active.add('widget-team-adoption')
    }

    // v4.10.44 — `widget-services` (v4.10.34) is retired: the team's own
    // services and the builder are on the Services tab (src/lib/teamTabs.js).

    ;(ctx.teamWidgets || []).forEach(w => active.add('widget-int-' + w.registry_id))

    // v4.13.24 — integration-package widgets (v4.13.23). The id matches
    // `getOrCreateIntegrationItem('cap-' + cap_id)` in TeamWidgetGrid, which
    // prefixes `widget-int-`.
    //
    // Missing this was the whole of the "the widget renders but has fallen out
    // of the dashboard" report. `visibleLayout` is `gridLayout` filtered by
    // this set and it is what `<grid-layout :layout>` receives, so a card
    // whose id never arrives here is rendered as a `<grid-item>` that the
    // layout does not manage: it sits at its prop position, takes part in no
    // collision or compaction, and every drag it emits is discarded because
    // the parent cannot find it. Arrow-key moves mutated `gridLayout` and
    // changed nothing on screen, and `runAutoFitPass()` bailed out early
    // because the rendered node count no longer matched `visibleLayout`.
    //
    // Hidden capabilities are already dropped server-side
    // (IntegrationPresentationService), so there is no second hide to apply
    // here — unlike `dashboardConfig.hidden_widgets` below, which governs
    // TeamHub's own widgets.
    ;(ctx.packageWidgets || []).forEach(w => active.add('widget-int-cap-' + w.cap_id))

    // Team-wide owner/admin hidden widgets. Applied last so it overrides every
    // activation rule above. Positions stay in gridLayout, so a widget toggled
    // back on returns to its place.
    if (applyHidden) {
        (ctx.dashboardConfig?.hidden_widgets || []).forEach(id => active.delete(id))
    }

    return active
}
