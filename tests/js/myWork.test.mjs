/**
 * src/constants/myWork.js — the parts v4.9.7 added for OpenProject: the
 * predefined views and their on/off rule, the status labels, the provider
 * warnings, the milestone glyph, and the grouping list.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    CATEGORY,
    RESOURCE_TYPE_ICONS,
    PROVIDER_ICONS,
    groupByOptions,
    predefinedViews,
    isViewActive,
    viewFilterPatch,
    statusLabel,
    providerWarning,
    resourceTypeLabel,
    SOURCE_GROUP_MEMBERS,
    sourceGroupOf,
    sourceGroupLabel,
    buildSourceTabs,
    sourceOptions,
} from '../../src/constants/myWork.js'

const defaultFilters = () => ({
    search: '', teamId: '', providerId: '', priority: '', status: '', resourceType: '',
    dueWindow: '', showSnoozed: false, category: '', projectId: '', workType: '',
})

// ── Views ─────────────────────────────────────────────────────────────

test('the four views are shortcuts over the filter state', () => {
    const views = predefinedViews()
    // No OpenProject view (dropped 2026-09-14): the source tabs filter by
    // source, and OpenProject rows group under By project.
    assert.deepEqual(views.map(v => v.key), ['attention', 'week', 'by-team', 'by-project'])
    const byKey = Object.fromEntries(views.map(v => [v.key, v]))
    assert.deepEqual(byKey.attention.filters, { category: CATEGORY.ACTION_REQUIRED, dueWindow: '' })
    assert.deepEqual(byKey.week.filters, { category: '', dueWindow: 'week' })
    assert.equal(views.some(v => v.requiresProvider), false, 'no view is tied to a provider')
    assert.equal(byKey['by-team'].groupBy, 'team')
    assert.equal(byKey['by-project'].groupBy, 'project')
})

test('a view is on when the state matches it, and two can be on at once', () => {
    const [attention, week, byTeam, byProject] = predefinedViews()
    const f = defaultFilters()

    assert.equal(isViewActive(attention, f, 'category'), false)
    assert.equal(isViewActive(attention, { ...f, category: CATEGORY.ACTION_REQUIRED }, 'category'), true)
    assert.equal(isViewActive(attention, { ...f, category: CATEGORY.ACTION_REQUIRED, dueWindow: 'week' }, 'category'), false, 'every key it sets must match')
    assert.equal(isViewActive(week, { ...f, dueWindow: 'week' }, 'category'), true)
    assert.equal(isViewActive(byTeam, f, 'team'), true)
    assert.equal(isViewActive(byTeam, f, 'category'), false)
    assert.equal(isViewActive(byProject, f, 'project'), true)
    // Needs attention + By team together.
    const both = { ...f, category: CATEGORY.ACTION_REQUIRED }
    assert.equal(isViewActive(attention, both, 'team') && isViewActive(byTeam, both, 'team'), true)
})

test('applying a filter view replaces what another filter view set', () => {
    const [attention, week, byTeam] = predefinedViews()
    // Needs attention on, then Due this week: the category goes, the window comes.
    const weekPatch = viewFilterPatch(week, false)
    assert.deepEqual(weekPatch, { category: '', dueWindow: 'week' })
    // Needs attention keeps its own explicit values on top of the reset.
    assert.deepEqual(viewFilterPatch(attention, false), { category: CATEGORY.ACTION_REQUIRED, dueWindow: '' })
    // A grouping view touches no filter.
    assert.deepEqual(viewFilterPatch(byTeam, false), {})
    // A filter view some other code adds later still clears every filter
    // view's keys first — the rule is generic, not a list of names.
    const extra = { key: 'x', filters: { providerId: 'deck' } }
    assert.deepEqual(viewFilterPatch(extra, false), { category: '', dueWindow: '', providerId: 'deck' })
})

test('turning an active view off clears only its own keys', () => {
    const [attention, , byTeam] = predefinedViews()
    assert.deepEqual(viewFilterPatch(attention, true), { category: '', dueWindow: '' })
    assert.deepEqual(viewFilterPatch(byTeam, true), {})
    assert.deepEqual(viewFilterPatch({ key: 'x', filters: { providerId: 'deck' } }, true), { providerId: '' })
})

test('a view with nothing to set is never reported on', () => {
    assert.equal(isViewActive({ key: 'x', filters: {} }, defaultFilters(), 'category'), false)
})

// ── Grouping + labels ─────────────────────────────────────────────────

test('grouping offers Project between Team and Resource type', () => {
    const keys = groupByOptions().map(o => o.key)
    assert.deepEqual(keys, ['category', 'date', 'team', 'project', 'resource_type'])
})

test('the shared statuses have words and an unknown key shows as itself', () => {
    assert.equal(statusLabel('assigned'), 'Assigned to me')
    assert.equal(statusLabel('authored'), 'Created by me, unassigned')
    assert.equal(statusLabel('overdue'), 'Overdue')
    assert.equal(statusLabel('due_today'), 'Due today')
    assert.equal(statusLabel('due_soon'), 'Due soon')
    assert.equal(statusLabel('updated'), 'Updated recently')
    assert.equal(statusLabel('milestone'), 'Upcoming milestone')
    assert.equal(statusLabel('completed'), 'Completed')
    assert.equal(statusLabel('some_provider_state'), 'some_provider_state')
})

test('OpenProject rows have their own glyphs and type labels', () => {
    assert.equal(RESOURCE_TYPE_ICONS.openproject_work_package, 'BriefcaseOutline')
    assert.equal(RESOURCE_TYPE_ICONS.openproject_milestone, 'FlagOutline')
    assert.equal(PROVIDER_ICONS.openproject, 'BriefcaseOutline')
    assert.equal(resourceTypeLabel('openproject_work_package'), 'Work package')
    assert.equal(resourceTypeLabel('openproject_milestone'), 'Milestone')
})

// ── Provider warnings ─────────────────────────────────────────────────

test('provider warnings become one notice each, with the source named', () => {
    const auth = providerWarning('auth_required', 'OpenProject')
    assert.equal(auth.action, 'reconnect')
    assert.equal(auth.tone, 'warning')
    assert.match(auth.text, /^OpenProject no longer accepts/)

    const partial = providerWarning('partial', 'OpenProject')
    assert.equal(partial.action, null)
    assert.equal(partial.tone, 'info')
    assert.match(partial.text, /Some OpenProject projects/)

    const budget = providerWarning('budget', 'OpenProject')
    assert.match(budget.text, /answered slowly/)

    assert.equal(providerWarning('not_connected', 'OpenProject'), null, 'never connected is not a notice in My Work')
    assert.equal(providerWarning('whatever', 'X'), null)
})

// ── Source groups (v4.9.17) ───────────────────────────────────────────

/** The nine providers of 4.9.15, in registry order, all available. */
const allProviders = () => [
    { id: 'deck', name: 'Deck', available: true },
    { id: 'approval', name: 'File approval', available: true },
    { id: 'decisions', name: 'Decisions', available: true },
    { id: 'meetings', name: 'Meetings', available: true },
    { id: 'teamadmin', name: 'Team admin', available: true },
    { id: 'teamexpiry_team', name: 'Team expiration', available: true },
    { id: 'teamexpiry_admin', name: 'Team lifecycle', available: true },
    { id: 'file_review', name: 'File reviews', available: true },
    { id: 'openproject', name: 'OpenProject', available: true },
]

test('the three groups name their members and nothing else', () => {
    assert.deepEqual(SOURCE_GROUP_MEMBERS, {
        files: ['approval', 'file_review'],
        teams: ['teamadmin', 'teamexpiry_team'],
        administration: ['teamexpiry_admin'],
    })
    assert.equal(sourceGroupOf('approval'), 'files')
    assert.equal(sourceGroupOf('file_review'), 'files')
    assert.equal(sourceGroupOf('teamexpiry_admin'), 'administration')
    assert.equal(sourceGroupOf('deck'), null, 'Deck stays a single tab')
    assert.equal(sourceGroupOf('openproject'), null, 'so does OpenProject')
    assert.equal(sourceGroupLabel('files'), 'Files')
    assert.equal(sourceGroupLabel('teams'), 'Teams')
    assert.equal(sourceGroupLabel('administration'), 'Administration')
})

test('nine providers become seven tabs after All, groups at their first member', () => {
    const tabs = buildSourceTabs(allProviders(), {}, '')
    assert.deepEqual(tabs.map(t => t.key), ['', 'deck', 'files', 'decisions', 'meetings', 'teams', 'administration', 'openproject'])
    assert.deepEqual(tabs.map(t => t.label), ['All', 'Deck', 'Files', 'Decisions', 'Meetings', 'Teams', 'Administration', 'OpenProject'])
    assert.equal(tabs[0].active, true)
    assert.equal(tabs[0].count, null, 'All carries no number')
    assert.deepEqual(tabs.find(t => t.key === 'files').members, ['approval', 'file_review'])
    assert.equal(tabs.find(t => t.key === 'files').icon, 'Folder')
    assert.equal(tabs.find(t => t.key === 'teams').icon, 'AccountGroupOutline')
    assert.equal(tabs.find(t => t.key === 'administration').icon, 'ShieldCrownOutline')
})

test('a group tab sums its members and an empty source still shows zero', () => {
    const tabs = buildSourceTabs(allProviders(), { approval: 2, file_review: 3, deck: 1 }, '')
    assert.equal(tabs.find(t => t.key === 'files').count, 5)
    assert.equal(tabs.find(t => t.key === 'deck').count, 1)
    assert.equal(tabs.find(t => t.key === 'teams').count, 0, 'empty is a tab at zero, not a missing tab')
})

test('an unavailable or disabled source gets no tab; a group survives on its available members', () => {
    const providers = allProviders().map(p => ({
        ...p,
        available: p.id !== 'deck' && p.id !== 'approval',
        enabled: p.id !== 'openproject',
    }))
    const tabs = buildSourceTabs(providers, { approval: 9, file_review: 1 }, '')
    assert.equal(tabs.some(t => t.key === 'deck'), false, 'Deck not installed → no Deck tab')
    assert.equal(tabs.some(t => t.key === 'openproject'), false, 'disabled by the admin → no tab')
    const files = tabs.find(t => t.key === 'files')
    assert.deepEqual(files.members, ['file_review'], 'the unavailable member is not counted as present')
    assert.equal(files.count, 1, 'nor is its count summed')
})

test('a group is absent when the server listed none of its members', () => {
    // A non-admin is not told about teamexpiry_admin at all (the server
    // leaves it out), so there is no Administration tab to hide.
    const tabs = buildSourceTabs(allProviders().filter(p => p.id !== 'teamexpiry_admin'), {}, '')
    assert.equal(tabs.some(t => t.key === 'administration'), false)
})

test('the active tab is the group when the filter names the group or one of its members', () => {
    assert.equal(buildSourceTabs(allProviders(), {}, 'files').find(t => t.key === 'files').active, true)
    const legacy = buildSourceTabs(allProviders(), {}, 'approval')
    assert.equal(legacy.find(t => t.key === 'files').active, true, 'a pre-4.9.17 preference still lights the right tab')
    assert.equal(legacy[0].active, false)
    assert.equal(buildSourceTabs(allProviders(), {}, 'deck').find(t => t.key === 'deck').active, true)
})

test('the Source dropdown speaks the same vocabulary as the tabs', () => {
    const providers = allProviders().map(p => ({ ...p, available: p.id !== 'approval' && p.id !== 'file_review' && p.id !== 'teamadmin' }))
    const options = sourceOptions(providers)
    assert.deepEqual(options.map(o => o.key), ['deck', 'files', 'decisions', 'meetings', 'teams', 'administration', 'openproject'])
    assert.equal(options.find(o => o.key === 'files').available, false, 'every member unavailable → the group is')
    assert.equal(options.find(o => o.key === 'teams').available, true, 'one member available → the group is')
    assert.equal(options.find(o => o.key === 'files').label, 'Files')
})
