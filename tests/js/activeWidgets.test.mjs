/**
 * src/lib/activeWidgets.js — the OpenProject gate, and that the admin's
 * hidden-widget list still wins (v4.9.3; one widget since v4.9.5, and the
 * Upcoming tasks widget joins in on a linked team).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { computeActiveWidgetIds } from '../../src/lib/activeWidgets.js'
import { buildDashboardWidgetCatalog } from '../../src/lib/teamTabs.js'

const base = {
    resources: {},
    collectivesConfig: { collectives_enabled: false },
    decisionsModuleEnabled: false,
    decisionsConfig: { decisions_enabled: false },
    project: { isProject: false },
    budgetConfig: {},
    timeConfig: {},
    teamWidgets: [],
    dashboardConfig: { hidden_widgets: [] },
}

test('an unlinked team has no OpenProject widget', () => {
    const ids = computeActiveWidgetIds({ ...base, openProjectConfig: { eligible: true, linked: false } })
    assert.ok(!ids.has('widget-openproject'))
    assert.ok(!ids.has('widget-deck'), 'and no Upcoming tasks either, with nothing else to feed it')
    const none = computeActiveWidgetIds(base)
    assert.ok(!none.has('widget-openproject'), 'missing config is unlinked')
})

test('a team of another template never shows it, even with a link', () => {
    const ids = computeActiveWidgetIds({ ...base, openProjectConfig: { eligible: false, linked: true } })
    assert.ok(!ids.has('widget-openproject'))
    assert.ok(!ids.has('widget-deck'))
})

test('a linked OpenProject team has Project info and Upcoming tasks, regardless of the viewer\'s own connection', () => {
    const ids = computeActiveWidgetIds({ ...base, openProjectConfig: { eligible: true, linked: true, available: false } })
    assert.ok(ids.has('widget-openproject'))
    assert.ok(ids.has('widget-deck'), 'Upcoming tasks lists the project\'s work packages')
    assert.ok(!ids.has('widget-openproject-work'), 'the 4.9.3 my-work widget is gone')
})

test('the admin can hide either one', () => {
    const ids = computeActiveWidgetIds({
        ...base,
        openProjectConfig: { eligible: true, linked: true },
        dashboardConfig: { hidden_widgets: ['widget-deck'] },
    })
    assert.ok(ids.has('widget-openproject'))
    assert.ok(!ids.has('widget-deck'))
})

test('the dashboard catalog offers both with labels, even when hidden', () => {
    const catalog = buildDashboardWidgetCatalog({
        ...base,
        openProjectConfig: { eligible: true, linked: true },
        dashboardConfig: { hidden_widgets: ['widget-openproject'] },
    })
    const keys = catalog.map(c => c.key)
    assert.ok(keys.includes('widget-openproject'))
    assert.ok(keys.includes('widget-deck'))
    assert.ok(!keys.includes('widget-openproject-work'))
    for (const c of catalog) assert.ok(c.label.length > 0)
})
