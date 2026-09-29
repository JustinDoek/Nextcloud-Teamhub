/**
 * src/constants/serviceTeams.js and src/store/serviceTeams.js (v4.10.20) —
 * which queue bucket a request belongs to, what an agent may do with it
 * (read from the server's `internal` block alone), and the store module's
 * load/act/upsert behaviour over a fake API: the licence gate is an
 * answer rather than an error, a claim moves a row between buckets, a
 * finished request leaves the queue, and a second action while one is in
 * flight is refused.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
	AGENT_ACTION,
	QUEUE_BUCKET,
	QUEUE_ORDER,
	SERVICE,
	SERVICE_ORDER,
	agentActions,
	assignableAgents,
	assigneeLabel,
	bucketOf,
	internalNotes,
	serviceIcon,
	CATEGORY,
	CATEGORY_ORDER,
	catalogueKey,
	categoryIcon,
	categoryTiles,
	filterByCategory,
	groupedByTeam,
	sortedCatalogue,
	QUOTA_DEFINITION,
	formatBytes,
	gbToBytes,
	isQuotaService,
	quotaRequestValid,
	suggestedQuotaGb,
} from '../../src/constants/serviceTeams.js'
import { createServiceTeamsModule } from '../../src/store/serviceTeams.js'

const request = (overrides = {}) => ({
	id: 1,
	title: 'Shared folder: Finance archive',
	status: 'in_progress',
	startedBy: 'requester',
	internal: {
		serviceTeamId: 'desk1',
		serviceTeamName: 'Service desk',
		assignee: '',
		assigneeName: '',
		claimable: true,
		canAssign: true,
		canRelease: false,
		isServiceOwner: false,
		agents: { svcowner: 'Service Owner', agent1: 'Agent One' },
	},
	...overrides,
})

const claimed = (uid, extra = {}) => request({
	internal: {
		...request().internal,
		assignee: uid,
		assigneeName: uid === 'agent1' ? 'Agent One' : uid,
		claimable: false,
		canRelease: true,
		...extra,
	},
})

// ── The vocabulary ─────────────────────────────────────────────────────

test('the catalogue and the queue keep their order', () => {
	assert.equal(SERVICE_ORDER[0], SERVICE.NEW_TEAM, 'the specific services first')
	assert.equal(SERVICE_ORDER[SERVICE_ORDER.length - 1], SERVICE.GENERAL, 'the catch-all last')
	assert.deepEqual(QUEUE_ORDER, [QUEUE_BUCKET.UNCLAIMED, QUEUE_BUCKET.MINE, QUEUE_BUCKET.OTHERS])
	assert.equal(new Set(SERVICE_ORDER).size, SERVICE_ORDER.length)
})

test('every service has a glyph, and an unknown one falls back rather than rendering nothing', () => {
	for (const key of SERVICE_ORDER) {
		assert.ok(serviceIcon(key), key)
	}
	assert.equal(serviceIcon('invented'), serviceIcon(SERVICE.GENERAL))
})

test('a request belongs to the bucket its assignee puts it in', () => {
	assert.equal(bucketOf(request(), 'agent1'), QUEUE_BUCKET.UNCLAIMED)
	assert.equal(bucketOf(claimed('agent1'), 'agent1'), QUEUE_BUCKET.MINE)
	assert.equal(bucketOf(claimed('agent2'), 'agent1'), QUEUE_BUCKET.OTHERS)
})

test('an unclaimed request says nothing about who has it; the bucket already did', () => {
	assert.equal(assigneeLabel(request()), '')
	assert.ok(assigneeLabel(claimed('agent1')).includes('Agent One'))
})

// ── What an agent may do ───────────────────────────────────────────────

test('the actions come from the server, so a button the call would refuse is never offered', () => {
	const unclaimed = agentActions(request())
	assert.ok(unclaimed.includes(AGENT_ACTION.CLAIM))
	assert.ok(unclaimed.includes(AGENT_ACTION.ASSIGN))
	assert.ok(!unclaimed.includes(AGENT_ACTION.RELEASE), 'nothing to release')

	const mine = agentActions(claimed('agent1'))
	assert.ok(!mine.includes(AGENT_ACTION.CLAIM), 'already taken')
	assert.ok(mine.includes(AGENT_ACTION.RELEASE))

	// A note needs no permission beyond being on the desk.
	assert.ok(mine.includes(AGENT_ACTION.INTERNAL_NOTE))
})

test('a request this viewer does not handle offers nothing at all', () => {
	assert.deepEqual(agentActions({ id: 2, internal: null }), [])
	assert.deepEqual(agentActions({ id: 2 }), [])
	assert.deepEqual(assignableAgents({ id: 2 }), [])
})

test('the assignable agents are the server list, named', () => {
	assert.deepEqual(assignableAgents(request()), [
		{ id: 'svcowner', label: 'Service Owner' },
		{ id: 'agent1', label: 'Agent One' },
	])
})

test('the internal notes are the desk half of the history, newest first', () => {
	const history = [
		{ type: 'created', occurredAt: 1 },
		{ type: 'internal_note', occurredAt: 2, payload: { note: 'first' } },
		{ type: 'step_completed', occurredAt: 3 },
		{ type: 'internal_note', occurredAt: 4, payload: { note: 'second' } },
	]
	const notes = internalNotes(history)
	assert.equal(notes.length, 2)
	assert.equal(notes[0].payload.note, 'second')
	assert.deepEqual(internalNotes([]), [])
	assert.deepEqual(internalNotes(undefined), [])
})

// ── The store ──────────────────────────────────────────────────────────

function harness(api) {
	const mod = createServiceTeamsModule(api)
	const state = mod.state()
	const commit = (name, payload) => mod.mutations[name](state, payload)
	const getters = {}
	for (const [key, fn] of Object.entries(mod.getters)) {
		Object.defineProperty(getters, key, { get: () => fn(state, getters), enumerable: true })
	}
	const dispatch = (name, payload) => mod.actions[name]({ commit, state, getters, dispatch }, payload)
	return { state, getters, dispatch, commit }
}

const okApi = (overrides = {}) => ({
	listServiceTeams: async () => ({
		serviceTeams: [{ teamId: 'desk1', teamName: 'Service desk', catalogue: [] }],
		available: true,
	}),
	loadQueue: async () => ({ queue: { unclaimed: [request()], mine: [], others: [] }, capabilities: {} }),
	loadCatalogue: async () => ({ catalogue: [{ serviceKey: SERVICE.GENERAL, definitionKey: 'service_general', label: 'General' }], categories: [], links: {} }),
	claimRequest: async () => claimed('agent1'),
	assignRequest: async (id, uid) => claimed(uid),
	releaseRequest: async () => request(),
	addInternalNote: async () => claimed('agent1'),
	...overrides,
})

test('load fills the desks and the selected queue', async () => {
	const h = harness(okApi())
	await h.dispatch('load')
	assert.equal(h.state.available, true)
	assert.equal(h.state.selectedId, 'desk1')
	assert.equal(h.state.queue.unclaimed.length, 1)
	assert.equal(h.getters.isAgent, true)
	assert.equal(h.getters.unclaimedCount, 1)
	assert.equal(h.state.error, null)
})

test('an unlicensed instance is an answer, not an error', async () => {
	const h = harness(okApi({
		listServiceTeams: async () => {
			throw { response: { status: 403, data: { licenseGate: true, error: 'licensed' } } }
		},
	}))
	await h.dispatch('load')
	assert.equal(h.state.available, false)
	assert.deepEqual(h.state.teams, [])
	assert.equal(h.state.error, null, 'the surface hides itself rather than showing a failure')
	assert.equal(h.getters.isAgent, false)
})

test('a real failure is recorded so the view can offer a retry', async () => {
	const h = harness(okApi({
		listServiceTeams: async () => {
			throw { response: { status: 500, data: { error: 'boom' } } }
		},
	}))
	await h.dispatch('load')
	assert.equal(h.state.error.message, 'boom')
})

test('claiming moves the row from unclaimed to mine', async () => {
	const h = harness(okApi())
	await h.dispatch('load')
	const result = await h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	assert.equal(result.ok, true)
	assert.equal(h.state.queue.unclaimed.length, 0)
	assert.equal(h.state.queue.mine.length, 1)
	assert.equal(h.state.queue.mine[0].internal.assignee, 'agent1')
})

test('assigning to a colleague moves the row to theirs', async () => {
	const h = harness(okApi())
	await h.dispatch('load')
	await h.dispatch('act', { id: 1, action: 'assign', uid: 'agent1', text: 'agent2' })
	assert.equal(h.state.queue.mine.length, 0)
	assert.equal(h.state.queue.others.length, 1)
	assert.equal(h.state.queue.others[0].internal.assignee, 'agent2')
})

test('a request that has ended leaves the queue; there is nothing left to work', async () => {
	const h = harness(okApi({
		claimRequest: async () => ({ ...claimed('agent1'), status: 'completed' }),
	}))
	await h.dispatch('load')
	await h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	assert.equal(h.getters.allRequests.length, 0)
})

test('a second action while one is in flight is refused, not queued', async () => {
	let release
	const gate = new Promise(resolve => { release = resolve })
	const h = harness(okApi({
		claimRequest: async () => { await gate; return claimed('agent1') },
	}))
	await h.dispatch('load')

	const first = h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	const second = await h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	assert.equal(second.ok, false)
	assert.equal(second.error.busy, true)
	release()
	assert.equal((await first).ok, true)
	assert.equal(h.state.busyId, null)
})

test('a conflict re-reads the queue: somebody else got there first', async () => {
	let reloads = 0
	const h = harness(okApi({
		claimRequest: async () => {
			throw { response: { status: 409, data: { conflict: true, error: 'taken' } } }
		},
		loadQueue: async () => {
			reloads++
			return { queue: { unclaimed: [], mine: [], others: [claimed('agent2')] }, capabilities: {} }
		},
	}))
	await h.dispatch('load')
	const result = await h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	assert.equal(result.ok, false)
	assert.equal(result.error.conflict, true)
	assert.equal(reloads, 2, 'the honest answer is the current state, not a retry')
	assert.equal(h.state.queue.others.length, 1)
})

test('an unknown action is a programming error, and the module says so', async () => {
	const h = harness(okApi())
	await h.dispatch('load')
	const result = await h.dispatch('act', { id: 1, action: 'invent', uid: 'agent1' })
	assert.equal(result.ok, false)
	assert.ok(result.error.message.includes('invent'))
	assert.equal(h.state.busyId, null, 'the lock is released even when the verb was nonsense')
})

test('the catalogue never breaks the page it sits on', async () => {
	const h = harness(okApi({
		loadCatalogue: async () => { throw new Error('nope') },
	}))
	await h.dispatch('loadCatalogue')
	assert.deepEqual(h.state.catalogue, [])
	assert.equal(h.state.error, null)
})

// v4.10.23 — the assignment-mode test was here. A new request always waits
// in the queue now, so there is no mode to assert.

// ── The catalogue page (phase 7A, v4.10.25) ────────────────────────────
//
// One payload, two readings and a filter. Everything the page shows is
// derived here, so these are the tests that say the grouping, the counts
// and the ordering are right without a browser.

const entry = (overrides = {}) => ({
	serviceKey: 'shared_folder',
	definitionKey: 'service_shared_folder',
	label: 'Request a shared folder',
	description: 'Ask for a shared folder to be set up.',
	category: CATEGORY.FILES,
	categoryLabel: 'Files and storage',
	leadTime: 'Usually within 1 working day',
	serviceTeamId: 'desk1',
	serviceTeamName: 'IT service desk',
	...overrides,
})

const catalogue = () => [
	entry({ serviceKey: 'general', label: 'Submit a general request', category: CATEGORY.SUPPORT, categoryLabel: 'Support and requests' }),
	entry({ serviceKey: 'new_team', label: 'Request a new team', category: CATEGORY.TEAMS, categoryLabel: 'Teams and spaces' }),
	entry({ serviceKey: 'shared_folder', serviceTeamId: 'desk2', serviceTeamName: 'Facilities' }),
	entry({ serviceKey: 'shared_folder' }),
]

test('the tiles count what this viewer can start, in the app order, and skip what is empty', () => {
	const tiles = categoryTiles(catalogue())
	assert.deepEqual(tiles.map(tile => tile.id), [CATEGORY.TEAMS, CATEGORY.FILES, CATEGORY.SUPPORT])
	assert.equal(tiles.find(tile => tile.id === CATEGORY.FILES).count, 2)
	assert.equal(tiles.find(tile => tile.id === CATEGORY.FILES).label, 'Files and storage',
		'the label is the server translation, never a second copy in the client')
	assert.ok(!tiles.some(tile => tile.id === CATEGORY.APPS),
		'a category nothing is in is not advertised')
})

test('a category the client has never heard of still gets a tile, at the end', () => {
	const tiles = categoryTiles([...catalogue(), entry({ category: 'printing', categoryLabel: 'Printing' })])
	assert.equal(tiles[tiles.length - 1].id, 'printing')
	assert.ok(CATEGORY_ORDER.every(key => key !== 'printing'))
})

test('a tile filters the list; no tile is every service', () => {
	assert.equal(filterByCategory(catalogue(), CATEGORY.FILES).length, 2)
	assert.equal(filterByCategory(catalogue(), '').length, 4)
	assert.equal(filterByCategory(catalogue(), 'printing').length, 0)
})

test('A to Z sorts by name, and by the offering team where two names match', () => {
	const sorted = sortedCatalogue(catalogue())
	assert.deepEqual(sorted.map(e => e.label), [
		'Request a new team',
		'Request a shared folder',
		'Request a shared folder',
		'Submit a general request',
	])
	assert.deepEqual(
		sorted.filter(e => e.serviceKey === 'shared_folder').map(e => e.serviceTeamName),
		['Facilities', 'IT service desk'],
		'two desks offering the same service stay adjacent and in a stable order')
})

test('by team is one group per desk, each desk A to Z', () => {
	const groups = groupedByTeam(catalogue())
	assert.deepEqual(groups.map(g => g.teamName), ['Facilities', 'IT service desk'])
	assert.deepEqual(groups[1].services.map(s => s.label), [
		'Request a new team',
		'Request a shared folder',
		'Submit a general request',
	])
})

test('the same service on two desks is two rows, with two keys', () => {
	const [a, b] = catalogue().filter(e => e.serviceKey === 'shared_folder')
	assert.notEqual(catalogueKey(a), catalogueKey(b))
	assert.equal(catalogueKey(a), 'desk2:shared_folder')
})

test('every category has a glyph, and an unknown one falls back rather than rendering nothing', () => {
	for (const key of CATEGORY_ORDER) {
		assert.equal(typeof categoryIcon(key), 'string')
	}
	assert.equal(categoryIcon('printing'), categoryIcon(CATEGORY.SUPPORT))
})

test('the Services entry appears only when something can be started', async () => {
	const h = harness(okApi({ loadCatalogue: async () => ({ catalogue: [], categories: [], links: {} }) }))
	await h.dispatch('loadCatalogue')
	assert.equal(h.getters.hasCatalogue, false, 'no desk, or no licence, is no entry')

	const full = harness(okApi({ loadCatalogue: async () => ({ catalogue: catalogue(), categories: [], links: {} }) }))
	await full.dispatch('loadCatalogue')
	assert.equal(full.getters.hasCatalogue, true)
})

// ── v4.10.45 — the administrator's categories, icons, links ────────────

test('the catalogue keeps the categories and links an administrator set beside the entries', async () => {
	const h = harness(okApi({
		loadCatalogue: async () => ({
			catalogue: catalogue(),
			categories: [{ key: 'c_1', label: 'Printing', icon: 'Printer' }],
			links: { serviceDesk: 'https://desk.example.org', knowledgePortal: '' },
		}),
	}))
	await h.dispatch('loadCatalogue')
	assert.equal(h.state.catalogueCategories[0].label, 'Printing')
	assert.equal(h.state.catalogueLinks.serviceDesk, 'https://desk.example.org')
	assert.equal(h.state.catalogueLinks.knowledgePortal, '')
})

test('the tiles follow the order an administrator set and carry each category icon', async () => {
	const { categoryTiles } = await import('../../src/constants/serviceTeams.js')
	const entries = [
		{ category: 'teams_spaces', categoryLabel: 'Teams', categoryIcon: 'AccountGroupOutline' },
		{ category: 'c_1', categoryLabel: 'Printing', categoryIcon: 'Printer' },
		{ category: 'c_1', categoryLabel: 'Printing', categoryIcon: 'Printer' },
	]
	const tiles = categoryTiles(entries, ['c_1', 'teams_spaces'])
	assert.deepEqual(tiles.map(tile => [tile.id, tile.count, tile.icon]), [['c_1', 2, 'Printer'], ['teams_spaces', 1, 'AccountGroupOutline']])
	assert.equal(categoryTiles([{ category: 'c_2', categoryIcon: 'Skull' }])[0].icon, categoryIcon('c_2'), 'an icon nobody offers falls back')
})

test('a built service shows the icon its team picked; a Nextcloud service its own', async () => {
	const { entryIcon } = await import('../../src/constants/serviceTeams.js')
	assert.equal(entryIcon({ serviceKey: 'team_service_7', icon: 'Printer' }), 'Printer')
	assert.equal(entryIcon({ serviceKey: 'team_service_7', icon: 'Skull' }), serviceIcon('team_service_7'))
	assert.equal(entryIcon({ serviceKey: SERVICE.TEAM_EXPIRY }), 'CalendarClock')
})

test('more time for a team: a later date and a reason', async () => {
	const { dayAfter, expiryRequestValid, isExpiryService } = await import('../../src/constants/serviceTeams.js')
	assert.ok(isExpiryService({ definitionKey: 'team_expiry' }))
	assert.ok(!isExpiryService({ definitionKey: 'teamspace_quota' }))
	assert.ok(expiryRequestValid({ date: '2027-06-30', reason: 'Still running', currentOn: '2027-01-31' }))
	assert.ok(!expiryRequestValid({ date: '2027-01-31', reason: 'x', currentOn: '2027-01-31' }), 'not later')
	assert.ok(!expiryRequestValid({ date: '2027-06-30', reason: '  ', currentOn: '2027-01-31' }), 'no reason')
	assert.ok(!expiryRequestValid({ date: '30-06-2027', reason: 'x' }))
	assert.equal(dayAfter('2027-02-28'), '2027-03-01')
	assert.equal(dayAfter(''), '')
})

// ── Phase B: the widgets on the service team (v4.10.27) ───────────────

test('the queue tabs: New is unclaimed, Claimed is mine first, Closed is the last 30 days', async () => {
	const { QUEUE_TAB, queueTabRows } = await import('../../src/constants/serviceTeams.js')
	const queue = {
		unclaimed: [{ id: 1 }],
		mine: [{ id: 2 }],
		others: [{ id: 3 }],
		closed: [{ id: 4 }],
	}
	assert.deepEqual(queueTabRows(queue, QUEUE_TAB.NEW).map(r => r.id), [1])
	assert.deepEqual(queueTabRows(queue, QUEUE_TAB.CLAIMED).map(r => r.id), [2, 3], 'the viewer\'s own first')
	assert.deepEqual(queueTabRows(queue, QUEUE_TAB.CLOSED).map(r => r.id), [4])
	assert.deepEqual(queueTabRows(null, QUEUE_TAB.CLOSED), [])
})

test('a closed request says how the desk\'s part ended', async () => {
	const { deskOutcomeLabel, deskOutcomeVariant } = await import('../../src/constants/serviceTeams.js')
	assert.equal(deskOutcomeLabel('completed'), 'Answered')
	assert.equal(deskOutcomeLabel('rejected'), 'Rejected')
	assert.equal(deskOutcomeLabel('cancelled'), 'Withdrawn')
	// v4.10.32 — the step an admin of the service team closed the request on.
	assert.equal(deskOutcomeLabel('skipped'), 'Closed')
	assert.equal(deskOutcomeVariant('rejected'), 'error')
	assert.equal(deskOutcomeVariant('completed'), 'success')
})

test('a median reads as minutes, hours or days — and nothing measured says so', async () => {
	const { formatDuration } = await import('../../src/constants/serviceTeams.js')
	assert.equal(formatDuration(null), 'No data')
	assert.equal(formatDuration(20), '1 minute', 'never "0 minutes" for something that happened')
	assert.equal(formatDuration(600), '10 minutes')
	assert.equal(formatDuration(3 * 3600), '3 hours')
	assert.equal(formatDuration(47 * 3600), '47 hours')
	assert.equal(formatDuration(3 * 86400), '3 days')
})

test('the desk\'s activity lines read as sentences, and nothing else is claimed as one', async () => {
	const { deskActivityLine, isDeskActivity } = await import('../../src/constants/serviceTeams.js')
	const row = (subject, params = {}) => ({ app: 'teamhub', subject, displayName: 'Inge', subjectparams: { title: 'Shared folder: Finance', ...params } })
	assert.equal(deskActivityLine(row('service.request_claimed')), 'Inge claimed Shared folder: Finance')
	assert.equal(deskActivityLine(row('service.request_assigned', { to_name: 'Jaap' })), 'Inge assigned Shared folder: Finance to Jaap')
	assert.equal(deskActivityLine(row('service.request_released')), 'Inge set Shared folder: Finance back to unclaimed')
	assert.equal(isDeskActivity({ app: 'teamhub', subject: 'project.time_log_added' }), false)
	assert.equal(deskActivityLine({ app: 'teamhub', subject: 'project.time_log_added' }), '')
})

test('the navigation badge is per desk, and a desk the viewer does not work reads 0', async () => {
	const h = harness(okApi({
		listServiceTeams: async () => ({
			serviceTeams: [{ teamId: 'desk1', teamName: 'IT', catalogue: [], unclaimedCount: 3 }],
			available: true,
		}),
	}))
	await h.dispatch('loadDesks')
	assert.equal(h.getters.unclaimedFor('desk1'), 3)
	assert.equal(h.getters.unclaimedFor('another-team'), 0)
	assert.deepEqual(h.state.queue.unclaimed, [], 'loading the desks reads nobody\'s queue')
})

test('reading the queue keeps the badge in step with it', async () => {
	const h = harness(okApi({
		listServiceTeams: async () => ({
			serviceTeams: [{ teamId: 'desk1', teamName: 'IT', catalogue: [], unclaimedCount: 7 }],
			available: true,
		}),
	}))
	await h.dispatch('load')
	assert.equal(h.getters.unclaimedFor('desk1'), 1, 'the queue has one unclaimed row; the stale 7 is gone')
	await h.dispatch('act', { id: 1, action: 'claim', uid: 'agent1' })
	assert.equal(h.getters.unclaimedFor('desk1'), 0, 'a claim in the widget clears the badge at once')
})

test('an unlicensed instance has no desks to badge', async () => {
	const h = harness(okApi({
		listServiceTeams: async () => { throw { response: { status: 403, data: { licenseGate: true } } } },
	}))
	await h.dispatch('loadDesks')
	assert.equal(h.getters.unclaimedFor('desk1'), 0)
	assert.equal(h.state.error, null)
})

// ── The quota request (v4.10.29) ───────────────────────────────────────

test('the quota request is a Nextcloud service with a form of its own', () => {
	assert.ok(SERVICE_ORDER.includes(SERVICE.TEAM_QUOTA))
	assert.notEqual(serviceIcon(SERVICE.TEAM_QUOTA), serviceIcon(SERVICE.GENERAL), 'a glyph of its own')
	assert.equal(isQuotaService({ serviceKey: 'team_quota', definitionKey: QUOTA_DEFINITION }), true)
	assert.equal(isQuotaService({ serviceKey: SERVICE.SHARED_FOLDER, definitionKey: 'service_shared_folder' }), false)
	assert.equal(isQuotaService(null), false)
})

test('the size starts at twice the current quota, in whole gigabytes', () => {
	const GB = 1024 ** 3
	assert.equal(suggestedQuotaGb(5 * GB), 10)
	assert.equal(suggestedQuotaGb(1.2 * GB), 3)
	assert.equal(suggestedQuotaGb(0), 10, 'no quota: a round starting point')
	assert.equal(gbToBytes(20), 20 * GB)
	assert.equal(formatBytes(20 * GB), '20 GB')
	assert.equal(formatBytes(1.5 * 1024 * GB), '1.5 TB')
})

test('the form asks for an increase and a reason; the server checks again', () => {
	const current = 5 * 1024 ** 3
	assert.equal(quotaRequestValid({ gb: 10, reason: 'video', currentBytes: current }), true)
	assert.equal(quotaRequestValid({ gb: 5, reason: 'video', currentBytes: current }), false, 'not an increase')
	assert.equal(quotaRequestValid({ gb: 10, reason: '  ', currentBytes: current }), false, 'no reason')
	assert.equal(quotaRequestValid({ gb: 0, reason: 'x', currentBytes: 0 }), false)
	assert.equal(quotaRequestValid({ gb: 10241, reason: 'x', currentBytes: 0 }), false, 'past 10 TB')
	assert.equal(quotaRequestValid({ gb: 1, reason: 'x', currentBytes: 0 }), true, 'no quota yet: any size')
})

test('a request back with the requester has a tab of its own, and stays in it after an action (v4.10.35)', async () => {
	const { QUEUE_TAB, QUEUE_TABS, QUEUE_WITH_OTHERS, queueTabRows, queueTabLabel } = await import('../../src/constants/serviceTeams.js')
	assert.deepEqual(QUEUE_TABS, [QUEUE_TAB.NEW, QUEUE_TAB.CLAIMED, QUEUE_TAB.WITH_REQUESTER, QUEUE_TAB.CLOSED])
	assert.equal(queueTabLabel(QUEUE_TAB.WITH_REQUESTER), 'With requester')
	assert.deepEqual(queueTabRows({ withOthers: [{ id: 6 }] }, QUEUE_TAB.WITH_REQUESTER).map(r => r.id), [6])
	assert.deepEqual(queueTabRows({}, QUEUE_TAB.WITH_REQUESTER), [])

	// Found on the instance: its step is the requester's, so nobody on the
	// desk holds it — and it used to be filed as unclaimed.
	const withRequester = request({
		steps: [
			{ key: 'submit', status: 'completed', actor: { type: 'user', id: 'asker' } },
			{ key: 'step_1', status: 'available', actor: { type: 'user', id: 'asker' } },
			{ key: 'step_2', status: 'pending', actor: { type: 'service_agent', id: 'desk1' } },
		],
	})
	assert.equal(bucketOf(withRequester, 'agent1'), QUEUE_WITH_OTHERS)
	assert.equal(bucketOf(claimed('agent1', {}), 'agent1'), QUEUE_BUCKET.MINE, 'a desk step still files by its assignee')
})
