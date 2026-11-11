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
import { layoutWidgetLabels } from './layoutWidgetCatalog.js'

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
 * What kind of thing a tab is — v4.13.24.
 *
 * ## Why a descriptor carries its own kind now
 *
 * `TeamTabBar` used to render the strip from a sixteen-arm
 * `v-if`/`v-else-if` chain keyed on `tab.key`, each arm a near-copy of the
 * one above it: same `role="tab"`, same drag handle, same active class, same
 * reorder handlers, differing only in an icon, a label and occasionally a
 * click handler. A descriptor that matched no arm produced **no node at
 * all** — silently, because a `v-else-if` chain has no else.
 *
 * That cost us the same bug three times. The Wiki tab (v4.3.9) did not
 * render until somebody added its arm. The Services tab (v4.10.41) was
 * dropped by a hand-kept key list in `syncExtTabs`. And the integration
 * package tabs (v4.13.23) rendered nowhere at all: no arm, and an
 * `isTabRenderable` whose default branch was an allowlist of two prefixes.
 * A third-party app could register a team tab, an administrator could
 * include it in a template, a team admin could switch it on — and the tab
 * bar would not show it, because nobody had edited this component.
 *
 * So the kind is on the descriptor and the strip renders one node per
 * descriptor. A new tab appears because the builder below emits it, which is
 * the promise `developers.md` already makes to an integrating app.
 */
export const TAB_KIND = {
    /** One of TeamHub's own views or embedded Nextcloud apps. */
    BUILTIN: 'builtin',
    /** A legacy-registry `menu_item` — an iframe, keyed `ext-<registry_id>`. */
    EXT: 'ext',
    /** A package `menu_item` capability — an iframe, keyed `pkgtab-<cap_id>`. */
    PKG: 'pkg',
    /** An admin-configured web link, keyed `link-<id>`. */
    LINK: 'link',
}

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
 * @param {Array}   ctx.teamMenuItems          app-registered menu items (legacy registry)
 * @param {Array}   ctx.packageTabs            shown menu_item capabilities (v4.13.23 packages)
 * @param {Array}   ctx.webLinks               admin-configured web links
 * @return {Array<object>} tab descriptors, each carrying its {@see TAB_KIND}
 */
export function buildAllTabDescriptors(ctx) {
    const tabs = []
    /** Push a built-in, stamping the kind every descriptor carries. */
    const builtin = descriptor => tabs.push({ kind: TAB_KIND.BUILTIN, ...descriptor })
    ;[
        { key: 'talk',     label: t('teamhub', 'Chat'),     icon: 'Chat' },
        { key: 'files',    label: t('teamhub', 'Files'),    icon: 'Folder' },
        // `countResource` names the resource bundle key whose length renders
        // as the small number badge on the tab — a team with three calendars
        // or two Deck boards. Only these two have one.
        { key: 'calendar', label: t('teamhub', 'Calendar'), icon: 'Calendar', countResource: 'calendar' },
        { key: 'deck',     label: t('teamhub', 'Deck'),     icon: 'CardText', countResource: 'deck' },
    ].forEach(builtin)
    // Collectives tab (v4.3.5, named "Wiki" until v4.6.9). Per-team toggle in
    // Manage Team → Integrations. Default off — teams opt in.
    if (ctx.collectivesConfig?.collectives_enabled) {
        builtin({ key: 'collectives', label: t('teamhub', 'Collectives'), icon: 'BookOpenOutline' })
    }
    // Presence tab — only when the NC admin has enabled the module
    // AND the team admin has enabled it for this specific team.
    if (ctx.presenceModuleEnabled && ctx.presenceConfig && ctx.presenceConfig.presence_enabled) {
        builtin({ key: 'presence', label: t('teamhub', 'People'), icon: 'OfficeBuilding' })
    }
    // Roster (v4.13.67, gated on its own switch in v4.13.68) — People's
    // second surface, and licensed. Three gates: the module instance-wide,
    // the module for this team, and Shifts for this team. The server answers
    // `shifts_enabled` only when the instance is licensed, so an unlicensed
    // instance never reaches this line with a true.
    if (ctx.presenceModuleEnabled
        && ctx.presenceConfig
        && ctx.presenceConfig.presence_enabled
        && ctx.presenceConfig.shifts_enabled) {
        builtin({ key: 'roster', label: t('teamhub', 'Roster'), icon: 'CalendarClock' })
    }
    // Decisions tab — same double-gate pattern.
    if (ctx.decisionsModuleEnabled && ctx.decisionsConfig && ctx.decisionsConfig.decisions_enabled) {
        builtin({ key: 'decisions', label: t('teamhub', 'Decisions'), icon: 'Gavel' })
    }
    // Timeline tab — per-team toggle (managed in Manage Team →
    // Integrations → Internal). Default is on; admins can disable it
    // for teams that don't need a timeline view. Empty state inside
    // the iframe handles the no-data case when enabled but no source
    // has events yet.
    if (ctx.timelineConfig && ctx.timelineConfig.timeline_enabled !== false) {
        builtin({ key: 'timeline', label: t('teamhub', 'Timeline'), icon: 'TimelineCheckOutline' })
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
        builtin({ key: 'budget', label: t('teamhub', 'Budget'), icon: 'WalletOutline' })
    }
    // Time tab — Advanced-mode projects only. Same three-gate pattern
    // as Budget. can_view_time is precomputed server-side (role floor
    // OR named project-participant row).
    if (isAdvancedProject
        && ctx.timeConfig
        && ctx.timeConfig.time_enabled !== false
        && ctx.timeConfig.can_view_time !== false) {
        builtin({ key: 'time', label: t('teamhub', 'Time'), icon: 'ClockOutline' })
    }
    // v4.10.41 — Services: a service team's own services and the builder,
    // on the whole tab instead of a widget and a modal (Justin, 2026-09-25).
    // Every member of a Service-template team sees it (a desk or not yet);
    // building is a team admin's, gated inside the view and on the server.
    if (ctx.serviceDeskConfig?.isServiceTeam) {
        builtin({ key: 'services', label: t('teamhub', 'Services'), icon: 'ViewGridOutline' })
    }
    // Legacy-registry menu items (2.42.3 onwards).
    ;(ctx.teamMenuItems || []).filter(item => !item.is_builtin)
        .forEach(item => tabs.push({
            kind:  TAB_KIND.EXT,
            key:   'ext-' + item.registry_id,
            label: item.title,
            icon:  item.icon || 'Puzzle',
            appId: item.app_id || null,
        }))
    // v4.13.24 — integration-package `menu_item` capabilities (v4.13.23).
    //
    // These were the missing descriptors. `TeamView.syncExtTabs()` appended
    // them to `orderedTabs` directly, but nine config watchers call
    // `buildOrderedTabs()`, which reassigns the whole array from this builder
    // — so every package tab was wiped the moment presence, decisions,
    // timeline, collectives, budget, time, the project facts or the service
    // desk facts arrived. Exactly the v4.10.45 Services bug, one layer up.
    //
    // The view key is `pkgtab-<cap_id>`, matching the `AppEmbed` branch in
    // TeamView. The URL is not carried here: the server hands it over with
    // TeamHub's own verified team id already appended, and the team a tab is
    // scoped to is settled by the membership check that produced the list.
    ;(ctx.packageTabs || []).forEach(tab => tabs.push({
        kind:  TAB_KIND.PKG,
        key:   'pkgtab-' + tab.cap_id,
        label: tab.title,
        icon:  tab.icon || 'Puzzle',
        appId: tab.app_id || null,
    }))
    ;(ctx.webLinks || []).forEach(link => tabs.push({
        kind:         TAB_KIND.LINK,
        key:          'link-' + link.id,
        label:        link.title,
        url:          link.url,
        isNcRelative: isNcRelativeUrl(link.url),
        // An NC-relative link embeds in TeamHub's own iframe; an absolute one
        // opens in a browser tab, so the strip renders it as an anchor.
        icon:         isNcRelativeUrl(link.url) ? 'Web' : 'OpenInNew',
    }))
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
 * Whether a tab has a backing resource and will actually render.
 *
 * Four built-ins embed a Nextcloud app that the team may not have: Chat
 * without a Talk token, Files without a path, Calendar or Deck with none.
 * Everything else was already gated at the point of insertion in
 * {@see buildAllTabDescriptors} — a descriptor exists because its gate said
 * yes — so the default is `true`.
 *
 * **The default is the point.** v4.13.24 made this the only copy of the rule.
 * `TeamTabBar` carried its own near-identical `isTabRenderable()` whose
 * default branch was an allowlist (`ext-` or `link-`, nothing else), so a
 * descriptor of any newer kind was filtered out of the strip *and* the
 * overflow menu with no way to tell from the outside. That is half of why a
 * registered integration package's team tab rendered nowhere. A gate that
 * answers "no" for everything it has not been told about is not a gate; it
 * is a list that someone has to remember to edit.
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
    // v4.13.35 — the names come from `layoutWidgetCatalog.js`, which the
    // administrator's layout editor also reads. They were written out here,
    // and the editor added in the same release would have been a second list
    // of the same cards under its own names.
    const labels = layoutWidgetLabels()
    const catalog = []
    computeActiveWidgetIds(ctx, { applyHidden: false }).forEach(id => {
        if (id === 'msgstream' || id.startsWith('widget-int-')) return
        if (labels[id]) catalog.push({ key: id, label: labels[id] })
    })
    ;(ctx.teamWidgets || []).forEach(w => {
        catalog.push({ key: 'widget-int-' + w.registry_id, label: w.title || t('teamhub', 'Widget') })
    })
    // v4.13.24 — `ctx.packageWidgets` is deliberately NOT added here, and the
    // omission is the design rather than the oversight it looks like.
    //
    // A package capability's shown/hidden state is a server-side preference
    // the presentation manager owns (Manage team → Integrations,
    // `IntegrationPresentationService`), and the server has already dropped
    // every hidden one before `packageWidgets` reaches the frontend. Listing
    // them here would give the same card a second, independent hide stored in
    // `dashboardConfig.hidden_widgets` — two switches for one widget, each
    // unaware of the other, and a card that cannot be brought back from
    // whichever one the admin did not use.
    return catalog
}
