/**
 * TeamHub — single source of truth for "which team tabs exist, and in what order".
 *
 * Why this file exists
 * --------------------
 * The gates deciding which tabs a team has lived inside `TeamView.vue`, and the
 * only way anything else could learn the answer was the `availableTabs` array
 * TeamView published to the store on its way through `buildOrderedTabs()`.
 * DESIGN §2.59 chose that deliberately, so Manage Team → Settings → Dashboard
 * would not carry a second copy of the activation logic — and the reasoning is
 * still right. What it missed is that `App.vue` renders TeamView and
 * ManageTeamView as mutually exclusive branches of one `v-if` chain, so three
 * paths open Manage Team without TeamView ever mounting:
 *
 *   - the sidebar 3-dot → Manage team  (App.vue::onSidebarManageTeam)
 *   - a My Work deep-link              (OPEN_KIND.MANAGE_TEAM)
 *   - an `intent=manage` deep-link
 *
 * On any of those, `availableTabs` was whatever the store happened to hold:
 * `[]` on a cold load, so the Default-tab `<select>` rendered with **no
 * options at all** and could never fire `@change` — which is why no team on
 * the test instance had ever stored a `dashboard_tab_*` value. Worse, after a
 * team switch it held the *previous* team's tabs, so the picker offered team
 * A's tabs while saving to team B.
 *
 * So the builders live here, taking store facts as a plain context object, and
 * the store can publish them with or without TeamView on screen. Same shape as
 * `activeWidgets.js`, and for the same reason: any new tab gate belongs here
 * and nowhere else.
 *
 * Fixed in v4.6.15.
 */

import { translate as t } from '@nextcloud/l10n'
import { computeActiveWidgetIds } from './activeWidgets.js'

/**
 * Built-in tab keys — the ones whose descriptors are pushed unconditionally
 * and gated later by resource availability. Everything else (ext-*, link-*,
 * and the config-gated internals) is gated at insertion time below.
 */
export const BUILTIN_TAB_KEYS = new Set([
    'talk', 'files', 'calendar', 'deck', 'collectives',
    'presence', 'decisions', 'timeline', 'budget', 'time',
])

/**
 * Whether a stored web-link URL is Nextcloud-relative (and so embeddable in
 * the app's own iframe) rather than an absolute external address.
 *
 * @param {string} url stored link target
 * @return {boolean}
 */
export function isNcRelativeUrl(url) {
    if (!url) return false
    return url.startsWith('/apps/') || url.startsWith('/index.php/')
}

/**
 * Every tab descriptor that applies to this team and viewer, in canonical
 * order (before the user's saved arrangement is applied).
 *
 * @param {object}  ctx                        gating facts, all from the store
 * @param {object}  ctx.collectivesConfig      { collectives_enabled }
 * @param {boolean} ctx.presenceModuleEnabled  instance-wide Presence switch
 * @param {object}  ctx.presenceConfig         { presence_enabled }
 * @param {boolean} ctx.decisionsModuleEnabled instance-wide Decisions switch
 * @param {object}  ctx.decisionsConfig        { decisions_enabled }
 * @param {object}  ctx.timelineConfig         { timeline_enabled }
 * @param {object}  ctx.project                project facts from the layout bundle
 * @param {object}  ctx.budgetConfig           { budget_enabled, can_view_budget }
 * @param {object}  ctx.timeConfig             { time_enabled, can_view_time }
 * @param {Array}   ctx.teamMenuItems          app-registered menu items
 * @param {Array}   ctx.webLinks               admin-configured web links
 * @return {Array<object>} tab descriptors
 */
export function buildAllTabDescriptors(ctx) {
    const tabs = []
    ;[
        { key: 'talk',     label: t('teamhub', 'Chat'),     icon: 'Chat' },
        { key: 'files',    label: t('teamhub', 'Files'),    icon: 'Folder' },
        { key: 'calendar', label: t('teamhub', 'Calendar'), icon: 'Calendar' },
        { key: 'deck',     label: t('teamhub', 'Deck'),     icon: 'CardText' },
    ].forEach(b => tabs.push(b))
    // Collectives tab (v4.3.5, named "Wiki" until v4.6.9). Per-team toggle in
    // Manage Team → Integrations. Default off — teams opt in.
    if (ctx.collectivesConfig?.collectives_enabled) {
        tabs.push({ key: 'collectives', label: t('teamhub', 'Collectives'), icon: 'BookOpenOutline' })
    }
    // Presence tab — only when the NC admin has enabled the module
    // AND the team admin has enabled it for this specific team.
    if (ctx.presenceModuleEnabled && ctx.presenceConfig && ctx.presenceConfig.presence_enabled) {
        tabs.push({ key: 'presence', label: t('teamhub', 'Presence'), icon: 'OfficeBuilding' })
    }
    // Decisions tab — same double-gate pattern.
    if (ctx.decisionsModuleEnabled && ctx.decisionsConfig && ctx.decisionsConfig.decisions_enabled) {
        tabs.push({ key: 'decisions', label: t('teamhub', 'Decisions'), icon: 'Gavel' })
    }
    // Timeline tab — per-team toggle (managed in Manage Team →
    // Integrations → Internal). Default is on; admins can disable it
    // for teams that don't need a timeline view. Empty state inside
    // the iframe handles the no-data case when enabled but no source
    // has events yet.
    if (ctx.timelineConfig && ctx.timelineConfig.timeline_enabled !== false) {
        tabs.push({ key: 'timeline', label: t('teamhub', 'Timeline'), icon: 'TimelineCheckOutline' })
    }
    const isAdvancedProject = !!ctx.project?.isProject && ctx.project?.mode === 'advanced'
    // Budget tab — Advanced-mode projects only. Three gates:
    //  (a) the per-team on/off toggle in Manage Team → Integrations
    //      (default on).
    //  (b) the project-level view floor — a caller below the floor
    //      doesn't see the tab UNLESS
    //  (c) they are a named editor on any of the project's lanes.
    // (b)+(c) are precomputed server-side as budgetConfig.can_view_budget.
    if (isAdvancedProject
        && ctx.budgetConfig
        && ctx.budgetConfig.budget_enabled !== false
        && ctx.budgetConfig.can_view_budget !== false) {
        tabs.push({ key: 'budget', label: t('teamhub', 'Budget'), icon: 'WalletOutline' })
    }
    // Time tab — Advanced-mode projects only. Same three-gate pattern
    // as Budget. can_view_time is precomputed server-side (role floor
    // OR named project-participant row).
    if (isAdvancedProject
        && ctx.timeConfig
        && ctx.timeConfig.time_enabled !== false
        && ctx.timeConfig.can_view_time !== false) {
        tabs.push({ key: 'time', label: t('teamhub', 'Time'), icon: 'ClockOutline' })
    }
    ;(ctx.teamMenuItems || []).filter(item => !item.is_builtin)
        .forEach(item => tabs.push({ key: 'ext-' + item.registry_id, label: item.title, icon: item.icon || 'Puzzle', appId: item.app_id || null }))
    ;(ctx.webLinks || []).forEach(link => tabs.push({ key: 'link-' + link.id, label: link.title, url: link.url, isNcRelative: isNcRelativeUrl(link.url) }))
    return tabs
}

/**
 * Apply the user's saved tab arrangement to a descriptor list. Keys in
 * `savedOrder` that no longer resolve to a tab are dropped; tabs the saved
 * order never knew about are appended in canonical order — which is what
 * keeps a stale saved key (a tab since renamed or disabled) from hiding a tab
 * that does exist.
 *
 * @param {Array<object>} all        descriptors from buildAllTabDescriptors
 * @param {Array<string>} savedOrder persisted tab-key order (may be empty)
 * @return {Array<object>} ordered descriptors
 */
export function orderTabDescriptors(all, savedOrder) {
    const order = Array.isArray(savedOrder) ? savedOrder : []
    if (order.length === 0) return all
    const allMap = Object.fromEntries(all.map(tab => [tab.key, tab]))
    const ordered = []
    order.forEach(key => { if (allMap[key]) ordered.push(allMap[key]) })
    all.forEach(tab => { if (!ordered.find(existing => existing.key === tab.key)) ordered.push(tab) })
    return ordered
}

/**
 * Whether a built-in tab has a backing resource and will actually render.
 * Mirror of TeamTabBar.isTabRenderable for the four resource-gated built-ins.
 * Non-built-in tabs (presence, decisions, timeline, budget, time, ext-*
 * integrations, link-* custom links) are already filtered by their own gates
 * at the point of insertion in buildAllTabDescriptors, so they return true.
 *
 * @param {string} key       tab key
 * @param {object} resources team resource bundle from the store
 * @return {boolean}
 */
export function isBuiltinTabRenderable(key, resources) {
    const r = resources || {}
    switch (key) {
    case 'talk':     return !!(r.talk && r.talk.token)
    case 'files':    return !!(r.files && r.files.path)
    case 'calendar': return Array.isArray(r.calendar) && r.calendar.length > 0
    case 'deck':     return Array.isArray(r.deck) && r.deck.length > 0
    default:         return true
    }
}

/**
 * The selectable tab list published to the store: Home plus every ordered tab
 * that will actually render for this team.
 *
 * v4.2.2 — tabs TeamTabBar would hide anyway (Chat/Files/Calendar/Deck with no
 * backing resource) are filtered out, so the Default-tab select never offers an
 * option that renders nothing. The caller's own `orderedTabs` stays unfiltered
 * so a temporary resource dropout doesn't rewrite the saved tab order.
 *
 * @param {Array<object>} ordered   ordered descriptors
 * @param {object}        resources team resource bundle from the store
 * @return {Array<{key: string, label: string}>}
 */
export function buildAvailableTabs(ordered, resources) {
    return [
        { key: 'msgstream', label: t('teamhub', 'Home') },
        ...(ordered || [])
            .filter(tab => isBuiltinTabRenderable(tab.key, resources))
            .map(tab => ({ key: tab.key, label: tab.label })),
    ]
}

/**
 * Labeled catalog of the home widgets currently active for this team (mirrors
 * computeActiveWidgetIds), minus the message-stream widget — Messages
 * visibility is owned by its own integration toggle. Consumed by Manage Team →
 * Settings → Dashboard for the per-widget show/hide switches. Integration
 * widgets carry their registry title.
 *
 * `applyHidden: false` is deliberate: the list must include already-hidden
 * widgets, otherwise the admin who hid one could never un-hide it.
 *
 * @param {object} ctx see computeActiveWidgetIds; plus ctx.teamWidgets
 * @return {Array<{key: string, label: string}>}
 */
export function buildDashboardWidgetCatalog(ctx) {
    const labels = {
        'widget-teaminfo':       t('teamhub', 'Team info'),
        'widget-members':        t('teamhub', 'Members'),
        'widget-activity':       t('teamhub', 'Activity'),
        'widget-calendar':       t('teamhub', 'Calendar'),
        'widget-deck':           t('teamhub', 'Tasks'),
        'widget-pages':          t('teamhub', 'Pages'),
        'widget-files-center':   t('teamhub', 'File center'),
        'widget-decisions':      t('teamhub', 'Decisions'),
        'widget-project-health': t('teamhub', 'Project health'),
        // TRANSLATORS: dashboard widget name — the linked OpenProject project's overview
        'widget-openproject':      t('teamhub', 'Project info'),
    }
    const catalog = []
    computeActiveWidgetIds(ctx, { applyHidden: false }).forEach(id => {
        if (id === 'msgstream' || id.startsWith('widget-int-')) return
        if (labels[id]) catalog.push({ key: id, label: labels[id] })
    })
    ;(ctx.teamWidgets || []).forEach(w => {
        catalog.push({ key: 'widget-int-' + w.registry_id, label: w.title || t('teamhub', 'Widget') })
    })
    return catalog
}
