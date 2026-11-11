/**
 * TeamHub — the home layout as an administrator chooses it: a page structure,
 * and the order the cards fill it in.
 *
 * Why this file exists
 * --------------------
 * `buildDashboardWidgetCatalog()` in `teamTabs.js` answers "which widgets does
 * *this team* have, and what are they called" — it runs the activation rules
 * over a loaded team. Admin → TeamHub → Policy has no team: an administrator
 * setting the default home for a kind of team is deciding for teams that may
 * not exist yet. So the names live here, and that function reads them from
 * here, because a widget labelled one way in Manage team and another way in
 * Policy is the same card with two names.
 *
 * What an administrator actually chooses (v4.13.36)
 * -------------------------------------------------
 * v4.13.35 asked them for a width per widget — *narrow*, *wide*, *main
 * column*. Justin, 2026-10-28: *"how does the admin know what narrow, main
 * column etc. mean and how they are positioned?"* They cannot, and no label
 * was going to fix it: a width per card is a description of a page written one
 * card at a time, and the person has to assemble the picture in their head.
 *
 * So they choose a **structure** — two side columns and a main one, a top row
 * with a main column and a side column under it — from a set that shows each
 * one as a diagram. The structure decides the widths. The order decides which
 * card lands where. Nothing asks for a coordinate.
 *
 * The diagram is drawn from the same `slots` array the structure applies, by
 * `structurePreview()`. That is the point of generating rather than drawing it:
 * a hand-drawn picture of a layout is a second description of the same thing,
 * and the one that goes stale is always the picture.
 */
import { translate as t } from '@nextcloud/l10n'

/** The grid's column count. Mirrors `:col-num="12"` on the `<grid-layout>`. */
const COLUMNS = 12

/**
 * Widget id → display name. A function rather than a constant because `t()`
 * must run after the locale is registered, not at module load.
 *
 * @return {Object<string, string>}
 */
export function layoutWidgetLabels() {
    return {
        // TRANSLATORS: dashboard widget name — the team's message stream
        msgstream:                 t('teamhub', 'Message stream'),
        'widget-teaminfo':         t('teamhub', 'Team info'),
        'widget-members':          t('teamhub', 'Members'),
        'widget-birthdays':         t('teamhub', 'Birthdays'),
        'widget-activity':         t('teamhub', 'Activity'),
        'widget-calendar':         t('teamhub', 'Calendar'),
        'widget-deck':             t('teamhub', 'Tasks'),
        'widget-pages':            t('teamhub', 'Pages'),
        'widget-files-center':     t('teamhub', 'File center'),
        'widget-decisions':        t('teamhub', 'Decisions'),
        'widget-project-health':   t('teamhub', 'Project health'),
        // TRANSLATORS: dashboard widget name — the linked OpenProject project's overview
        'widget-openproject':      t('teamhub', 'Project info'),
        // TRANSLATORS: dashboard widget name — a service team's request queue
        'widget-service-queue':    t('teamhub', 'Service requests'),
        // TRANSLATORS: dashboard widget name — a service team's request statistics
        'widget-service-stats':    t('teamhub', 'Statistics'),
        // TRANSLATORS: dashboard widget name — the grid where teams made outside TeamHub are accepted or declined
        'widget-team-adoption':    t('teamhub', 'Teams made outside TeamHub'),
    }
}

/**
 * What each widget needs a template to have switched on, for the editor to
 * offer it.
 *
 * Justin, 2026-10-28: *"why do I see project info in a collaboration
 * template?"* Because the editor listed every widget TeamHub has, on every
 * template. A Collaboration team has no OpenProject link, so Project info was
 * a card to position that would never appear.
 *
 * `app` and `module` name keys from `TeamTemplates::APPS` and `::MODULES` —
 * the vocabulary a template actually carries. Mirrors the real activation
 * rules in `src/lib/activeWidgets.js`, which gate on the **resource** a team
 * ends up with; a template declaring the app is what produces that resource.
 *
 * An entry of `true` is a widget every team has.
 *
 * **Widgets that are in no list here are deliberately absent from the
 * editor**, and that is the honest answer rather than an oversight: Project
 * health, Project info, Service requests, Statistics and the adoption grid
 * depend on things a template does not decide — a project's phase and its
 * budget and time permissions, an OpenProject link, a desk's published
 * services, which team holds the adoption service. There is no template-level
 * fact to gate them on, so they are not offered. They still appear on the
 * teams that have them, after the ordered cards, and a team that wants one of
 * them higher sets its own layout from its Home view.
 */
const WIDGET_REQUIREMENTS = {
    msgstream:             { module: 'messages' },
    'widget-teaminfo':     true,
    'widget-members':      true,
    'widget-birthdays':    { type: 'department' },
    'widget-activity':     true,
    'widget-calendar':     { app: 'calendar' },
    'widget-deck':         { app: 'deck' },
    'widget-files-center': { app: 'files' },
    'widget-decisions':    { module: 'decisions' },
    // Either provider: `pages` is Intravox, `wiki` is Collectives, and the
    // unified Pages widget renders when one of them is on (v4.3.7).
    'widget-pages':        { anyModule: ['pages', 'wiki'] },
}

/**
 * The widgets a team of this template will have, in the shipped order.
 *
 * @param {string[]} apps    the template's app keys
 * @param {string[]} modules the template's module keys
 * @param {Array<object>} shipped the layout TeamHub ships, for the order
 * @return {string[]} widget ids
 */
export function templateWidgetIds(apps, modules, shipped, templateKey = null) {
    const hasApp = (key) => (apps || []).includes(key)
    const hasModule = (key) => (modules || []).includes(key)

    const offered = Object.keys(WIDGET_REQUIREMENTS).filter(id => {
        const need = WIDGET_REQUIREMENTS[id]
        if (need === true) return true
        if (need.type) return templateKey === need.type
        if (need.app) return hasApp(need.app)
        if (need.module) return hasModule(need.module)
        if (need.anyModule) return need.anyModule.some(hasModule)
        return false
    })

    // Shipped order first, so a template that has everything reads like the
    // home people already know; anything the shipped layout does not mention
    // follows in declaration order.
    const shippedOrder = (Array.isArray(shipped) ? shipped : []).map(item => item.i)
    return offered.slice().sort((a, b) => {
        const ia = shippedOrder.indexOf(a)
        const ib = shippedOrder.indexOf(b)
        return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib)
    })
}

/**
 * The page structures an administrator may choose.
 *
 * `slots` are the first cards' widths in twelfths, in reading order; `repeat`
 * is what every card after them takes. Together they describe a page without
 * naming a single coordinate — which is the whole reason this replaced a width
 * per widget.
 *
 * The widths divide twelve evenly except for the 9/3 pair, which is the
 * asymmetric split the shipped home uses and the one people already know.
 *
 * @return {Array<{id: string, label: string, slots: number[], repeat: number}>}
 */
export function layoutStructures() {
    return [
        {
            id: 'main-side',
            // TRANSLATORS: page structure — one wide column with a narrow one beside it
            label: t('teamhub', 'Main column with a side column'),
            slots: [9, 3],
            repeat: 3,
        },
        {
            id: 'side-main-side',
            // TRANSLATORS: page structure — a wide middle column with a narrow column on each side
            label: t('teamhub', 'Main column between two side columns'),
            slots: [3, 6, 3],
            repeat: 3,
        },
        {
            id: 'banner-main-side',
            // TRANSLATORS: page structure — a full-width card on top, then a main column with a side column
            label: t('teamhub', 'Full width row, then a main column with a side column'),
            slots: [12, 9, 3],
            repeat: 3,
        },
        {
            id: 'halves',
            // TRANSLATORS: page structure — two equal columns
            label: t('teamhub', 'Two equal columns'),
            slots: [],
            repeat: 6,
        },
        {
            id: 'thirds',
            // TRANSLATORS: page structure — three equal columns
            label: t('teamhub', 'Three equal columns'),
            slots: [],
            repeat: 4,
        },
        {
            id: 'single',
            // TRANSLATORS: page structure — every card the full width of the page
            label: t('teamhub', 'One column, full width'),
            slots: [],
            repeat: 12,
        },
    ]
}

/**
 * The width the structure gives the card at `index`.
 *
 * @param {object} structure
 * @param {number} index
 * @return {number} columns, 1 to 12
 */
export function widthAt(structure, index) {
    const slots = structure?.slots || []
    if (index < slots.length) return slots[index]
    return structure?.repeat || 3
}

/**
 * Where a structure's first `count` cards land, as rectangles in grid units.
 *
 * Shared by the editor's diagram and nothing else today — but it is the same
 * left-to-right, wrap-when-full placement the packer performs for equal-height
 * cards, which is why the diagram can be trusted as a picture of the result.
 *
 * @param {object} structure
 * @param {number} count how many cards to place
 * @return {Array<{x: number, y: number, w: number}>}
 */
export function structurePreview(structure, count = 7) {
    const cells = []
    let x = 0
    let y = 0
    for (let i = 0; i < count; i++) {
        const w = widthAt(structure, i)
        if (x + w > COLUMNS) {
            x = 0
            y += 1
        }
        cells.push({ x, y, w })
        x += w
        if (x >= COLUMNS) {
            x = 0
            y += 1
        }
    }
    return cells
}

/**
 * Which structure a stored layout was built with, by matching its widths.
 *
 * Inferred rather than stored, so no column had to be added for it. The
 * structures produce distinct width sequences, so the first match is the only
 * match; a layout that matches none — one captured from a team's Home view
 * before this editor existed, say — falls back to the first structure, and the
 * administrator sees a page they can then choose properly.
 *
 * @param {Array<object>} layout
 * @return {string} a structure id
 */
export function structureOf(layout) {
    const structures = layoutStructures()
    const widths = (Array.isArray(layout) ? layout : [])
        .slice()
        .sort((a, b) => (a.y ?? 0) - (b.y ?? 0) || (a.x ?? 0) - (b.x ?? 0))
        .map(item => Number(item.w) || 0)

    if (widths.length === 0) return structures[0].id

    const match = structures.find(structure =>
        widths.every((w, index) => w === widthAt(structure, index)))

    return (match || structures[0]).id
}

/**
 * The editor's rows: the widgets this template's teams will have, in the
 * order the stored layout puts them, with the ones it does not mention after.
 *
 * @param {Array<object>} layout  the stored layout, or null
 * @param {Array<object>} shipped the layout TeamHub ships
 * @param {string[]} apps
 * @param {string[]} modules
 * @return {Array<{i: string, label: string, h: number, minW: number, minH: number}>}
 */
export function layoutEditorRows(layout, shipped, apps, modules, templateKey = null) {
    const labels = layoutWidgetLabels()
    const available = templateWidgetIds(apps, modules, shipped, templateKey)

    const sized = new Map()
    ;(Array.isArray(shipped) ? shipped : []).forEach(item => sized.set(item.i, item))

    const ordered = (Array.isArray(layout) ? layout : [])
        .slice()
        .sort((a, b) => (a.y ?? 0) - (b.y ?? 0) || (a.x ?? 0) - (b.x ?? 0))
        .map(item => item.i)
        .filter(id => available.includes(id))

    const ids = [...ordered, ...available.filter(id => !ordered.includes(id))]

    return ids.map(id => {
        const ship = sized.get(id) || {}
        return {
            i: id,
            label: labels[id] || id,
            // Heights are the rule's, not the administrator's: every widget
            // that lists rows gets the same height, tall enough for five of
            // them (LayoutSchema::H_ROW). An administrator choosing a height
            // per widget is how the layout this work replaced ended up with a
            // Members card two rows tall.
            h: Number(ship.h) || 4,
            minW: Number(ship.minW) || 2,
            minH: Number(ship.minH) || 1,
        }
    })
}

/**
 * Turn the editor's rows and chosen structure into a layout to store.
 *
 * `y` is only the order; `x` is left at zero. The client packs the result for
 * each team's own widget set on every load, which is what lets one stored
 * layout serve teams that do not all have the same cards.
 *
 * @param {Array<object>} rows
 * @param {object} structure
 * @return {Array<object>}
 */
export function layoutFromEditorRows(rows, structure) {
    return (Array.isArray(rows) ? rows : []).map((row, index) => {
        const w = Math.max(1, Math.min(COLUMNS, widthAt(structure, index)))
        const h = Math.max(1, Number(row.h) || 4)
        return {
            i: row.i,
            x: 0,
            y: index,
            w,
            h,
            minW: Math.min(w, Number(row.minW) || 2),
            minH: Number(row.minH) || 1,
            isResizable: true,
            collapsed: false,
            hSaved: h,
        }
    })
}
