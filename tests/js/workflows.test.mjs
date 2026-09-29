/**
 * src/constants/workflows.js and src/store/workflows.js (v4.10.15) — the
 * partition into Action required / Waiting for others, the progress text,
 * the privacy-appropriate actor labels, the available actions from the
 * server's `viewer` block, the timeline, and the store module's
 * load/act/upsert behaviour over a fake API (no duplicate submission, a
 * finished workflow leaves the lists, a stale action re-reads).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    WORKFLOW_ACTION,
    stepRoleText,
    stepLinks,
    stepLinkDescription,
    rowKey,
    stagesOf,
    taskTitle,
    canAttachFiles,
    fileSharingOf,
    shareNote,
    ownOpenTasks,
    stepStateLabel,
    TIMELINE_EVENT_TYPES,
    hasStepDetail,
    responsibleActor,
    isWaitingForInformation,
    eventLabel,
    WORKFLOW_STATUS,
    STEP_STATUS,
    isOpen,
    activeStep,
    progress,
    progressLabel,
    actorDescription,
    availableActions,
    actionText,
    partition,
    timeline,
    conversation,
    eventTone,
    formatStamp,
    isoInstant,
    trackerState,
    statusTone,
    actionLabel,
} from '../../src/constants/workflows.js'
import { createWorkflowsModule } from '../../src/store/workflows.js'

const step = (key, order, status, actor = { type: 'team', id: '' }) => ({ key, order, status, label: 'L ' + key, actor })

const teamRequest = (overrides = {}) => ({
    id: 1,
    title: 'Team request: Marketing',
    status: WORKFLOW_STATUS.IN_PROGRESS,
    updatedAt: 100,
    steps: [
        step('submit', 1, STEP_STATUS.COMPLETED, { type: 'user', id: 'jaap' }),
        step('approve', 2, STEP_STATUS.AVAILABLE, { type: 'team_moderator', id: '' }),
        step('process', 3, STEP_STATUS.PENDING, { type: 'group', id: 'admin' }),
        step('confirm', 4, STEP_STATUS.PENDING, { type: 'user', id: 'jaap' }),
    ],
    viewer: { isParticipant: true, isResponsible: false, canAct: false, canCancel: true, canRequestStatus: true },
    ...overrides,
})

// ── Progress and steps ────────────────────────────────────────────────

test('the active step is the one somebody can act on, and progress counts from it', () => {
    const w = teamRequest()
    assert.equal(activeStep(w).key, 'approve')
    assert.deepEqual(progress(w), { current: 2, total: 4 })
    assert.equal(progressLabel(w), 'Step 2 of 4')
    assert.equal(trackerState(w.steps[0]), 'done')
    assert.equal(trackerState(w.steps[1]), 'current')
    assert.equal(trackerState(w.steps[2]), 'pending')
})

test('a finished workflow reads as its last step and has no active step', () => {
    const w = teamRequest({
        status: WORKFLOW_STATUS.COMPLETED,
        steps: teamRequest().steps.map(s => ({ ...s, status: STEP_STATUS.COMPLETED })),
    })
    assert.equal(activeStep(w), null)
    assert.deepEqual(progress(w), { current: 4, total: 4 })
    assert.equal(isOpen(w), false)
    assert.equal(statusTone(w.status), 'success')
})

// ── Actors ────────────────────────────────────────────────────────────

test('actors are described as roles, never as lists of people', () => {
    assert.deepEqual(actorDescription({ type: 'team_owner', id: '' }), { label: 'Team owner', uid: null })
    assert.deepEqual(actorDescription({ type: 'team_moderator', id: '' }), { label: 'Team owner or moderator', uid: null })
    assert.deepEqual(actorDescription({ type: 'team', id: '' }), { label: 'Team members', uid: null })
    // v4.10.17 — a group is named as the people in it. "Group admin" read
    // like somebody called Group Admin; `admin` is Nextcloud's own
    // administrators and has a name of its own.
    assert.deepEqual(actorDescription({ type: 'group', id: 'it' }), { label: 'Members of it', uid: null })
    assert.deepEqual(actorDescription({ type: 'group', id: 'admin' }), { label: 'Nextcloud administrators', uid: null })
    // A user actor carries the uid for the avatar bubble and nothing more.
    assert.deepEqual(actorDescription({ type: 'user', id: 'jaap' }), { label: 'jaap', uid: 'jaap' })
    assert.deepEqual(actorDescription(undefined), { label: '', uid: null })
})

// ── Actions ───────────────────────────────────────────────────────────

test('the responsible viewer gets complete, reject and ask; a waiting participant gets the update request', () => {
    const responsible = teamRequest({ viewer: { isParticipant: true, isResponsible: true, canAct: true, canCancel: false, canRequestStatus: false } })
    assert.deepEqual(availableActions(responsible), [WORKFLOW_ACTION.COMPLETE, WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.REQUEST_INFORMATION])

    const waiting = teamRequest()
    assert.deepEqual(availableActions(waiting), [WORKFLOW_ACTION.REQUEST_STATUS, WORKFLOW_ACTION.CANCEL])

    // Nothing on an ended workflow, whatever the viewer block says.
    assert.deepEqual(availableActions(teamRequest({ status: WORKFLOW_STATUS.REJECTED, viewer: responsible.viewer })), [])
})

test('an admin of the desk gets Close request, at any step, with an optional note (v4.10.31)', () => {
    const admin = teamRequest({ viewer: { isParticipant: true, isResponsible: false, canAct: false, canClose: true, canCancel: false, canRequestStatus: false } })
    assert.deepEqual(availableActions(admin), [WORKFLOW_ACTION.CLOSE])
    // Working the step and able to close it: both, the step's own verbs first.
    const working = teamRequest({ viewer: { isParticipant: true, isResponsible: true, canAct: true, canClose: true, canCancel: false, canRequestStatus: false } })
    assert.deepEqual(availableActions(working), [WORKFLOW_ACTION.COMPLETE, WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.REQUEST_INFORMATION, WORKFLOW_ACTION.CLOSE])
    assert.equal(actionText(WORKFLOW_ACTION.CLOSE), 'optional')
    assert.equal(actionLabel(WORKFLOW_ACTION.CLOSE), 'Close request')
})

test("an unclaimed desk request is nobody's own turn, so it waits rather than asks (v4.10.31)", () => {
    const unclaimed = teamRequest({ id: 7, viewer: { isParticipant: true, isResponsible: true, canAct: true, actionRequired: false } })
    const claimed = teamRequest({ id: 8, viewer: { isParticipant: true, isResponsible: true, canAct: true, actionRequired: true } })
    const { actionRequired, waiting } = partition([unclaimed, claimed])
    assert.deepEqual(actionRequired.map(w => w.id), [8])
    assert.deepEqual(waiting.map(w => w.id), [7])
})

test('a step waiting for information takes an answer from any participant and no completion', () => {
    const steps = teamRequest().steps.map(s => s.key === 'approve' ? { ...s, status: STEP_STATUS.WAITING_FOR_INFORMATION } : s)
    const holder = teamRequest({ status: WORKFLOW_STATUS.WAITING, steps, viewer: { isParticipant: true, isResponsible: true, canAct: true, canCancel: false, canRequestStatus: false } })
    assert.deepEqual(availableActions(holder), [WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.PROVIDE_INFORMATION])
    const requester = teamRequest({ status: WORKFLOW_STATUS.WAITING, steps })
    assert.deepEqual(availableActions(requester), [WORKFLOW_ACTION.PROVIDE_INFORMATION, WORKFLOW_ACTION.REQUEST_STATUS, WORKFLOW_ACTION.CANCEL])
})

test('a blocked workflow offers nothing to act on', () => {
    const blocked = teamRequest({ status: WORKFLOW_STATUS.BLOCKED, viewer: { isParticipant: true, isResponsible: true, canAct: false, canCancel: true, canRequestStatus: false } })
    assert.deepEqual(availableActions(blocked), [WORKFLOW_ACTION.CANCEL])
})

test('reject, ask and answer need a text; complete, withdraw and update take one', () => {
    assert.equal(actionText(WORKFLOW_ACTION.REJECT), 'required')
    assert.equal(actionText(WORKFLOW_ACTION.REQUEST_INFORMATION), 'required')
    assert.equal(actionText(WORKFLOW_ACTION.PROVIDE_INFORMATION), 'required')
    assert.equal(actionText(WORKFLOW_ACTION.COMPLETE), 'optional')
    assert.equal(actionText(WORKFLOW_ACTION.CANCEL), 'optional')
    assert.equal(actionText(WORKFLOW_ACTION.REQUEST_STATUS), 'optional')
    assert.equal(actionText('open'), 'none')
})

// ── Sections ──────────────────────────────────────────────────────────

test('open workflows split by responsibility; ended ones are Completed; newest first', () => {
    const mine = teamRequest({ id: 1, updatedAt: 10, viewer: { isParticipant: true, isResponsible: true, canAct: true } })
    const mineLater = teamRequest({ id: 2, updatedAt: 20, viewer: { isParticipant: true, isResponsible: true, canAct: true } })
    const theirs = teamRequest({ id: 3, updatedAt: 5 })
    // v4.10.17 — an ended workflow is Completed, not dropped. It used to go
    // nowhere, so finishing a request made it vanish on a licensed instance
    // even though the server still had it.
    const done = teamRequest({ id: 4, status: WORKFLOW_STATUS.COMPLETED, endedAt: 30, viewer: { isParticipant: true, isResponsible: false } })
    const older = teamRequest({ id: 6, status: WORKFLOW_STATUS.CANCELLED, endedAt: 15, viewer: { isParticipant: true, isResponsible: false } })
    const notMine = teamRequest({ id: 5, viewer: { isParticipant: false, isResponsible: false } })
    const { actionRequired, waiting, completed } = partition([mine, theirs, done, mineLater, notMine, older])
    assert.deepEqual(actionRequired.map(w => w.id), [2, 1])
    assert.deepEqual(waiting.map(w => w.id), [3])
    // Most recently ended first, and a non-participant's ended workflow is
    // still not the viewer's business — the server never sends it.
    assert.deepEqual(completed.map(w => w.id), [4, 6])
    assert.deepEqual(partition(undefined), { actionRequired: [], waiting: [], completed: [] })
})

// ── Timeline ──────────────────────────────────────────────────────────

test('the timeline keeps the meaningful events, newest first, with step labels', () => {
    const w = teamRequest()
    const history = [
        { type: 'participant_added', occurredAt: 1 },
        { type: 'created', occurredAt: 2, actorUid: 'jaap' },
        { type: 'step_available', occurredAt: 3, stepKey: 'approve' },
        { type: 'step_completed', occurredAt: 4, stepKey: 'submit', actorUid: 'jaap' },
        { type: 'status_requested', occurredAt: 5, stepKey: 'approve', actorUid: 'jaap', payload: { note: 'any news?' } },
    ]
    const rows = timeline(w, history)
    assert.deepEqual(rows.map(r => r.type), ['status_requested', 'step_completed', 'created'])
    assert.equal(rows[1].stepLabel, 'L submit')
    assert.deepEqual(timeline(w, undefined), [])
})

test('every timeline event gets a dot colour; the unknown ones are neutral (v4.10.30)', () => {
    assert.equal(eventTone({ type: 'created' }), 'start')
    assert.equal(eventTone({ type: 'step_completed' }), 'success')
    assert.equal(eventTone({ type: 'completed' }), 'success')
    assert.equal(eventTone({ type: 'step_rejected' }), 'error')
    assert.equal(eventTone({ type: 'rejected' }), 'error')
    assert.equal(eventTone({ type: 'information_requested' }), 'warning')
    assert.equal(eventTone({ type: 'blocked' }), 'warning')
    assert.equal(eventTone({ type: 'cancelled' }), 'neutral')
    assert.equal(eventTone({ type: 'something_new' }), 'neutral')
    assert.equal(eventTone(null), 'neutral')
})

test('a timeline stamp carries the date and the time, the year only when it is another year (v4.10.30)', () => {
    const now = Math.floor(Date.now() / 1000)
    const thisYear = String(new Date().getFullYear())
    const stamp = formatStamp(now)
    assert.ok(stamp.length > 0)
    assert.ok(/\d{1,2}:\d{2}/.test(stamp), 'has a time: ' + stamp)
    assert.ok(!stamp.includes(thisYear), 'no year this year: ' + stamp)
    const old = formatStamp(now - 400 * 24 * 3600)
    assert.ok(old.includes(String(Number(thisYear) - 1)), 'has the year: ' + old)
    assert.equal(formatStamp(0), '')
    assert.equal(isoInstant(1700000000), '2023-11-14T22:13:20.000Z')
    assert.equal(isoInstant(null), '')
})

// ── The store module ──────────────────────────────────────────────────

/**
 * What `listWorkflows` resolves with since v4.10.16: the rows plus the
 * instance's tier and capability map.
 */
function list(workflows, tier = 'full') {
    return {
        workflows,
        tier,
        capabilities: {
            view_action_required: true,
            view_waiting_for_others: true,
            view_responsible_actor: true,
            view_available_actions: true,
            view_timeline: true,
            start_built_in: true,
            view_current_step: tier === 'full',
            view_step_progress: tier === 'full',
            request_status_update: tier === 'full',
            completed_history: tier === 'full',
        },
    }
}

function harness(api) {
    const mod = createWorkflowsModule(api)
    const state = mod.state()
    const commit = (type, payload) => mod.mutations[type](state, payload)
    const getters = {}
    for (const [k, g] of Object.entries(mod.getters)) {
        Object.defineProperty(getters, k, { get: () => g(state, getters) })
    }
    const dispatch = (type, payload) => mod.actions[type]({ state, commit, getters }, payload)
    return { state, commit, getters, dispatch }
}

test('load fills the list and records an error instead of throwing', async () => {
    let fail = false
    const api = {
        listWorkflows: async () => {
            if (fail) {
                const e = new Error('nope'); e.response = { status: 500, data: { error: 'down' } }; throw e
            }
            return list([teamRequest({ id: 1 }), teamRequest({ id: 2, viewer: { isParticipant: true, isResponsible: true, canAct: true } })])
        },
    }
    const h = harness(api)
    await h.dispatch('load')
    assert.equal(h.state.loaded, true)
    assert.equal(h.state.items.length, 2)
    assert.equal(h.getters.actionRequiredCount, 1)
    assert.deepEqual(h.getters.sections.waiting.map(w => w.id), [1])

    fail = true
    await h.dispatch('load')
    assert.equal(h.state.loading, false)
    assert.deepEqual(h.state.error, { status: 500, message: 'down', conflict: false, retryAfter: 0 })
    // The last good list is kept while the error shows.
    assert.equal(h.state.items.length, 2)
})

test('an action replaces the row, a completed workflow moves to Completed, and a second click is refused', async () => {
    let resolveAct
    const api = {
        listWorkflows: async () => list([teamRequest({ id: 1, viewer: { isParticipant: true, isResponsible: true, canAct: true } })]),
        actOnWorkflow: () => new Promise(r => { resolveAct = r }),
        getWorkflow: async () => null,
    }
    const h = harness(api)
    await h.dispatch('load')

    const first = h.dispatch('act', { id: 1, action: 'complete', text: '' })
    assert.equal(h.state.busyId, 1)
    // Duplicate submission while in flight: refused, not queued, not sent.
    await assert.rejects(h.dispatch('act', { id: 1, action: 'complete', text: '' }), e => e.status === 0)

    resolveAct(teamRequest({ id: 1, status: WORKFLOW_STATUS.COMPLETED, endedAt: 40, viewer: { isParticipant: true, isResponsible: false } }))
    const updated = await first
    assert.equal(updated.status, 'completed')
    assert.equal(h.state.busyId, null)
    // v4.10.17 — it leaves both open sections and appears under Completed,
    // which is where somebody looks for the request they just finished. The
    // server still has it (nothing said `purged`), so the client keeps it.
    assert.deepEqual(h.getters.sections.actionRequired, [])
    assert.deepEqual(h.getters.sections.waiting, [])
    assert.deepEqual(h.getters.sections.completed.map(w => w.id), [1])
    assert.equal(h.state.items.length, 1)
})

test('a stale action re-reads the workflow and surfaces the conflict', async () => {
    const fresh = teamRequest({ id: 1, updatedAt: 999, viewer: { isParticipant: true, isResponsible: false, canRequestStatus: true } })
    const api = {
        listWorkflows: async () => list([teamRequest({ id: 1, viewer: { isParticipant: true, isResponsible: true, canAct: true } })]),
        actOnWorkflow: async () => { const e = new Error('stale'); e.response = { status: 409, data: { error: 'moved on', conflict: true } }; throw e },
        getWorkflow: async () => fresh,
    }
    const h = harness(api)
    await h.dispatch('load')
    await assert.rejects(h.dispatch('act', { id: 1, action: 'complete' }), e => e.status === 409 && e.conflict && e.message === 'moved on')
    assert.equal(h.state.items[0].updatedAt, 999)
    assert.deepEqual(h.getters.sections.waiting.map(w => w.id), [1])
    assert.equal(h.state.busyId, null)
})

test('opening a workflow keeps its history and updates the row from the same answer', async () => {
    const detail = { ...teamRequest({ id: 1, updatedAt: 50 }), history: [{ type: 'created', occurredAt: 1 }] }
    const api = {
        listWorkflows: async () => list([teamRequest({ id: 1, updatedAt: 10 })]),
        getWorkflow: async () => detail,
        actOnWorkflow: async () => teamRequest({ id: 1, updatedAt: 60 }),
    }
    const h = harness(api)
    await h.dispatch('load')
    await h.dispatch('open', 1)
    assert.equal(h.state.detail.history.length, 1)
    assert.equal(h.state.items[0].updatedAt, 50)
    // An action while the detail is open updates it too, without losing the history it had.
    await h.dispatch('act', { id: 1, action: 'request_status', text: 'hi' })
    assert.equal(h.state.detail.updatedAt, 60)
    assert.equal(h.state.detail.history.length, 1)
    h.dispatch('close')
    assert.equal(h.state.detail, null)
})

test('starting a request puts the new workflow in the list and refuses to overlap another action', async () => {
    const api = {
        listWorkflows: async () => list([]),
        startWorkflow: async (teamId, key, data) => teamRequest({ id: 9, title: 'Team request: ' + data.teamName, viewer: { isParticipant: true, isResponsible: false, canRequestStatus: true } }),
    }
    const h = harness(api)
    await h.dispatch('load')
    const created = await h.dispatch('start', { teamId: 't1', definitionKey: 'team_request', data: { teamName: 'Sales', reason: 'r' } })
    assert.equal(created.id, 9)
    assert.deepEqual(h.getters.sections.waiting.map(w => w.id), [9])
    assert.equal(h.state.busyId, null)

    h.commit('SET_BUSY', 3)
    await assert.rejects(h.dispatch('start', { teamId: 't1', definitionKey: 'team_request', data: {} }), e => e.status === 0)
})


// ── The unlicensed tier (v4.10.16) ────────────────────────────────────
//
// An unlicensed instance sends no step list: no current step, no
// progress, no tracker. It does send who is responsible, the available
// actions and a basic timeline, and it never sends an ended workflow.

/** A workflow as an unlicensed instance sends it: no steps, a responsible actor. */
const unlicensedRequest = (overrides = {}) => ({
    id: 1,
    title: 'Team request: Marketing',
    status: WORKFLOW_STATUS.IN_PROGRESS,
    updatedAt: 100,
    steps: [],
    currentStep: null,
    responsible: { type: 'group', id: 'admin' },
    viewer: { isParticipant: true, isResponsible: false, canAct: false, canCancel: true, canRequestStatus: false },
    ...overrides,
})

test('an unlicensed workflow carries no step detail and no progress', () => {
    const w = unlicensedRequest()
    assert.equal(hasStepDetail(w), false)
    assert.equal(activeStep(w), null)
    assert.deepEqual(progress(w), { current: 0, total: 0 })
    assert.equal(progressLabel(w), '', 'nothing to render, so the row shows nothing')
    // And the licensed view still does.
    assert.equal(hasStepDetail(teamRequest()), true)
    assert.equal(progressLabel(teamRequest()), 'Step 2 of 4')
})

test('the responsible actor is shown on both tiers', () => {
    assert.deepEqual(responsibleActor(unlicensedRequest()), { type: 'group', id: 'admin' })
    assert.equal(actorDescription(responsibleActor(unlicensedRequest())).label, 'Nextcloud administrators')
    // Licensed: the server sends it too, and the active step is the fallback.
    assert.deepEqual(responsibleActor(teamRequest({ responsible: { type: 'team_moderator', id: '' } })).type, 'team_moderator')
    assert.deepEqual(responsibleActor(teamRequest()), { type: 'team_moderator', id: '' })
    assert.equal(responsibleActor({ steps: [], status: WORKFLOW_STATUS.COMPLETED }), null)
})

test('an unlicensed instance never offers the status update', () => {
    const w = unlicensedRequest()
    assert.equal(availableActions(w).includes(WORKFLOW_ACTION.REQUEST_STATUS), false)
    assert.deepEqual(availableActions(w), [WORKFLOW_ACTION.CANCEL])
    // Licensed, same viewer: the action is there.
    const licensed = unlicensedRequest({ viewer: { ...unlicensedRequest().viewer, canRequestStatus: true } })
    assert.equal(availableActions(licensed).includes(WORKFLOW_ACTION.REQUEST_STATUS), true)
})

test('the responsible person gets the right actions without a step list', () => {
    const mine = unlicensedRequest({ viewer: { isParticipant: true, isResponsible: true, canAct: true, canCancel: false, canRequestStatus: false } })
    assert.deepEqual(availableActions(mine), [
        WORKFLOW_ACTION.COMPLETE, WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.REQUEST_INFORMATION,
    ])

    // Waiting for information is read from the instance status when there
    // are no steps, so completing is not offered and answering is.
    const waiting = unlicensedRequest({
        status: WORKFLOW_STATUS.WAITING,
        viewer: { isParticipant: true, isResponsible: true, canAct: true, canCancel: false, canRequestStatus: false },
    })
    assert.equal(isWaitingForInformation(waiting), true)
    assert.deepEqual(availableActions(waiting), [WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.PROVIDE_INFORMATION])
})

test('a basic timeline entry reads without naming a step', () => {
    // The server sends the basic timeline with stepKey null.
    assert.equal(eventLabel({ type: 'step_completed', stepKey: null }), 'Step completed')
    assert.equal(eventLabel({ type: 'step_rejected', stepKey: null }), 'Step rejected')
    // Licensed: the step is named.
    assert.equal(eventLabel({ type: 'step_completed', stepLabel: 'Approve' }), 'Step completed: Approve')
})

test('the timeline of an unlicensed workflow keeps its meaningful events', () => {
    const w = unlicensedRequest()
    const events = timeline(w, [
        { type: 'created', occurredAt: 1 },
        { type: 'step_completed', occurredAt: 2, stepKey: null },
        { type: 'information_requested', occurredAt: 3, payload: { note: 'how many?' } },
    ])
    assert.deepEqual(events.map(e => e.type), ['information_requested', 'step_completed', 'created'])
    assert.deepEqual(events.map(e => e.stepLabel), ['', '', ''], 'no step is named')
})

test('the store keeps the tier and answers what the instance may do', async () => {
    const api = { listWorkflows: async () => list([unlicensedRequest()], 'basic') }
    const h = harness(api)
    await h.dispatch('load')
    assert.equal(h.state.tier, 'basic')
    assert.equal(h.getters.licensed, false)
    assert.equal(h.getters.can('view_timeline'), true)
    assert.equal(h.getters.can('view_current_step'), false)
    assert.equal(h.getters.can('completed_history'), false)
    assert.equal(h.getters.can('something_invented'), false)
    assert.deepEqual(h.getters.sections.waiting.map(w => w.id), [1])
})

test('a workflow that ends on an unlicensed instance leaves My Work and is not kept anywhere', async () => {
    let resolveAct
    const api = {
        listWorkflows: async () => list([
            unlicensedRequest({ viewer: { isParticipant: true, isResponsible: true, canAct: true } }),
        ], 'basic'),
        actOnWorkflow: () => new Promise(r => { resolveAct = r }),
        getWorkflow: async () => null,
    }
    const h = harness(api)
    await h.dispatch('load')
    await h.dispatch('open', 1)

    const acting = h.dispatch('act', { id: 1, action: 'complete', text: '' })
    // The server's last answer: completed, and purged.
    resolveAct(unlicensedRequest({ status: WORKFLOW_STATUS.COMPLETED, purged: true, viewer: { isParticipant: true, isResponsible: false } }))
    const done = await acting

    assert.equal(done.status, 'completed')
    assert.equal(done.purged, true)
    assert.equal(h.state.items.length, 0, 'gone from the list')
    // Not under Completed either: `purged` means the server deleted it in
    // the transaction that ended it, so there is nothing to keep. This is
    // what tells the unlicensed path from the licensed one, where the same
    // completion leaves the row under Completed (v4.10.17).
    assert.deepEqual(h.getters.sections, { actionRequired: [], waiting: [], completed: [] })
    assert.equal(isOpen(done), false)
})

test('a step may name the verbs in its own words (v4.10.29)', () => {
    const quota = { actionLabels: { complete: 'Grant', reject: 'Decline' } }
    assert.equal(actionLabel(WORKFLOW_ACTION.COMPLETE, quota), 'Grant')
    assert.equal(actionLabel(WORKFLOW_ACTION.REJECT, quota), 'Decline')
    // A verb the step does not rename keeps the engine's word…
    assert.equal(actionLabel(WORKFLOW_ACTION.CANCEL, quota), actionLabel(WORKFLOW_ACTION.CANCEL))
    // …and so does every workflow without its own words.
    assert.equal(actionLabel(WORKFLOW_ACTION.COMPLETE, { actionLabels: [] }), actionLabel(WORKFLOW_ACTION.COMPLETE))
    assert.equal(actionLabel(WORKFLOW_ACTION.COMPLETE, null), actionLabel(WORKFLOW_ACTION.COMPLETE))
})

test('a built service shows the role a step needs, and a closed step reads Closed (v4.10.32)', () => {
    assert.equal(stepRoleText({ roleLabel: 'Privacy officer' }), 'Role: Privacy officer')
    // The built-in services carry no role, and then nothing is said.
    assert.equal(stepRoleText({ roleLabel: '' }), '')
    assert.equal(stepRoleText({ roleLabel: '   ' }), '')
    assert.equal(stepRoleText(null), '')

    // An admin of the service team closed the request on this step.
    assert.equal(stepStateLabel({ status: STEP_STATUS.SKIPPED, actionTaken: 'close' }), 'Closed')
    // Any other skipped step stays Skipped.
    assert.equal(stepStateLabel({ status: STEP_STATUS.SKIPPED, actionTaken: null }), 'Skipped')
    assert.equal(eventLabel({ type: 'closed' }), 'Closed by the service team')
    assert.ok(TIMELINE_EVENT_TYPES.includes('closed'))
})

test('Action required follows actionRequired, not "may act" (v4.10.31)', () => {
    const open = { status: WORKFLOW_STATUS.IN_PROGRESS }
    // An unclaimed desk step: every member may act, nobody's action is required yet.
    const unclaimed = { ...open, id: 1, viewer: { isParticipant: true, isResponsible: true, actionRequired: false } }
    const claimed = { ...open, id: 2, viewer: { isParticipant: true, isResponsible: true, actionRequired: true } }
    // A payload from before the field falls back to isResponsible.
    const older = { ...open, id: 3, viewer: { isParticipant: true, isResponsible: true } }
    const s = partition([unclaimed, claimed, older])
    assert.deepEqual(s.actionRequired.map(w => w.id).sort(), [2, 3])
    assert.deepEqual(s.waiting.map(w => w.id), [1])
})

test('a step shows only its https:// links, and says what each opens (v4.10.36)', () => {
    const step = {
        links: [
            { label: 'Intake form', url: 'https://forms.example.org/1', kind: 'form' },
            { label: 'Old', url: 'http://example.org', kind: 'link' },
            { label: 'Bad', url: 'javascript:alert(1)', kind: 'link' },
            { label: 'Guide', url: 'https://wiki.example.org/guide', kind: 'link' },
        ],
    }
    assert.deepEqual(stepLinks(step).map(l => l.label), ['Intake form', 'Guide'])
    assert.deepEqual(stepLinks({}), [])
    assert.deepEqual(stepLinks(null), [])
    assert.equal(stepLinkDescription(step.links[0]), 'Fill in the form: Intake form (opens in a new tab)')
    assert.equal(stepLinkDescription(step.links[3]), 'Open Guide (opens in a new tab)')
})

test('a step of several tasks: the view answers for one, the tracker groups them (v4.10.37)', () => {
    const wf = {
        id: 9,
        viewer: { stepKey: 'step_1_2' },
        steps: [
            { key: 'submit', order: 1, status: 'completed', label: 'Request submitted', stageLabel: '' },
            { key: 'step_1_1', order: 2, status: 'in_progress', label: 'Privacy check', stageLabel: 'Assess' },
            { key: 'step_1_2', order: 2, status: 'available', label: 'Security check', stageLabel: 'Assess' },
            { key: 'step_2', order: 3, status: 'pending', label: 'Install', stageLabel: '' },
            { key: 'confirm', order: 4, status: 'pending', label: 'Requester confirms', stageLabel: '' },
        ],
    }
    assert.equal(activeStep(wf).key, 'step_1_2', 'the task the view is about, not the first open one')
    assert.equal(activeStep({ ...wf, viewer: {} }).key, 'step_1_1')
    assert.equal(activeStep({ ...wf, viewer: { stepKey: 'step_2' } }).key, 'step_1_1', 'a task that is not open falls back')
    assert.equal(rowKey(wf), '9:step_1_2')
    assert.notEqual(rowKey(wf), rowKey({ ...wf, viewer: { stepKey: 'step_1_1' } }))

    const stages = stagesOf(wf)
    assert.deepEqual(stages.map(s => [s.label, s.tasks.length]), [['Request submitted', 1], ['Assess', 2], ['Install', 1], ['Requester confirms', 1]])
    assert.deepEqual(progress(wf), { current: 2, total: 4 }, 'steps, not task rows')

    assert.equal(taskTitle(wf.steps[1]), 'Assess: Privacy check')
    assert.equal(taskTitle(wf.steps[3]), 'Install')
})

test('files go only with a verb the other side reads, on a request a service team handles (v4.10.38)', () => {
    const desk = { steps: [{ key: 'handle', order: 2, status: 'available', actor: { type: 'service_agent', id: 'desk' } }], data: {} }
    assert.ok(canAttachFiles(desk, 'complete'))
    assert.ok(canAttachFiles(desk, 'provide_information'))
    assert.ok(!canAttachFiles(desk, 'cancel'), 'not with a withdrawal')
    assert.ok(!canAttachFiles(desk, 'request_status'))
    assert.ok(!canAttachFiles({ ...desk, data: { fileSharing: { allowed: false } } }, 'complete'), 'the service said no')
    assert.ok(!canAttachFiles({ steps: [{ actor: { type: 'group', id: 'admin' } }] }, 'complete'), 'no service team')
    assert.ok(!canAttachFiles({ steps: [] }, 'complete'), 'an unlicensed view has no steps')

    assert.deepEqual(fileSharingOf({}), { allowed: true, edit: false, days: 14 })
    assert.equal(shareNote({ edit: false, days: 14 }, 'the service team'), 'Files are shared with the service team for 14 days, to view only.')
    assert.equal(shareNote({ edit: true, days: 1 }, 'Jaap'), 'Files are shared with Jaap for 1 day, to view and edit.')
})

test("the requester's own open tasks are what the request form hands them after sending (v4.10.39)", () => {
    const wf = { steps: [
        { key: 'submit', status: 'completed', canAct: false, actor: { type: 'user', id: 'jaap' } },
        { key: 'step_1_1', status: 'available', canAct: true, actor: { type: 'user', id: 'jaap' } },
        { key: 'step_1_2', status: 'available', canAct: true, actor: { type: 'user', id: 'jaap' } },
        { key: 'step_2', status: 'pending', canAct: false, actor: { type: 'service_agent', id: 'desk' } },
    ] }
    assert.deepEqual(ownOpenTasks(wf).map(s => s.key), ['step_1_1', 'step_1_2'])
    assert.deepEqual(ownOpenTasks({ steps: [{ key: 'x', status: 'available', canAct: true, actor: { type: 'service_agent', id: 'desk' } }] }), [],
        "a team task is never the requester's to do")
    assert.deepEqual(ownOpenTasks(null), [])
})

test("a service team's task names the service team (v4.10.40)", () => {
    assert.deepEqual(actorDescription({ type: 'service_agent', id: 'desk' }), { label: 'Service team', uid: null })
})

// ── v4.11.0 — the conversation on a service request ───────────────────

test('conversation: messages, questions and answers, oldest first; nothing else', () => {
    const history = [
        { type: 'message', occurredAt: 30, payload: { note: 'third' } },
        { type: 'internal_note', occurredAt: 15, payload: { note: 'the desk only' } },
        { type: 'information_requested', occurredAt: 10, payload: { note: 'first' } },
        { type: 'step_completed', occurredAt: 25 },
        { type: 'information_provided', occurredAt: 20, payload: { note: 'second' } },
    ]
    assert.deepEqual(conversation(history).map(e => e.payload.note), ['first', 'second', 'third'])
    assert.deepEqual(conversation(undefined), [])
    // The caller's array is not reordered.
    assert.equal(history[0].occurredAt, 30)
})

test('conversation: a message stays off the timeline and has its own label', () => {
    assert.ok(!TIMELINE_EVENT_TYPES.includes('message'), 'the Messages tab is where a message is read')
    assert.equal(eventLabel({ type: 'message' }), 'Message')
})

test('conversation: files go with a message where the service takes them', () => {
    const desk = { steps: [{ key: 'handle', actor: { type: 'service_agent', id: 'd' } }], data: {} }
    assert.equal(canAttachFiles(desk, 'message'), true)
    assert.equal(canAttachFiles({ ...desk, data: { fileSharing: { allowed: false } } }, 'message'), false)
    assert.equal(canAttachFiles({ steps: [{ actor: { type: 'user', id: 'u' } }] }, 'message'), false)
})

test('httpsOnly: an absolute https address, or nothing (v4.11.0)', async () => {
    const { httpsOnly } = await import('../../src/constants/workflows.js')
    assert.equal(httpsOnly('https://help.example.org/desk'), 'https://help.example.org/desk')
    for (const bad of ['http://example.org', 'javascript:alert(1)', 'data:text/html,x', '/apps/x', '//evil.example', 'https://', '', null, undefined]) {
        assert.equal(httpsOnly(bad), '', String(bad))
    }
})
