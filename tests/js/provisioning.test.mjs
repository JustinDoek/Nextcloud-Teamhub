/**
 * src/lib/provisioning.js — the wizard's, the banner's and the admin
 * panel's shared helpers (v4.9.6, Phase 2): labels, the review
 * classification, the request key, and the pump that drives an operation
 * to rest.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    STEP_KEYS, OP_STATUS, STEP_STATUS, DECISIONS,
    stepLabel, stepStatusLabel, operationStatusLabel, isActive, isUsable,
    classifyComponent, classificationLabel, componentLabel, roleLabel,
    newIdempotencyKey, pumpOperation, progressRows, resourceLinks,
} from '../../src/lib/provisioning.js'

// ── Labels ───────────────────────────────────────────────────────────

test('every backend step key has a human-readable label', () => {
    for (const key of STEP_KEYS) {
        assert.notEqual(stepLabel(key), key, key)
    }
    assert.equal(stepLabel('some_future_step'), 'some_future_step', 'an unknown key falls back to itself')
})

test('every status has a sentence', () => {
    for (const s of Object.values(STEP_STATUS)) assert.notEqual(stepStatusLabel(s), s)
    for (const s of Object.values(OP_STATUS)) assert.notEqual(operationStatusLabel(s), s)
    for (const k of ['owner', 'admin', 'moderator', 'member', 'guest']) assert.notEqual(roleLabel(k), k)
    for (const c of ['talk', 'files', 'calendar', 'collectives', 'intravox', 'messages', 'decisions', 'dashboard']) assert.notEqual(componentLabel(c), c)
})

test('active means the pump keeps calling; usable means the workspace can be opened', () => {
    assert.equal(isActive(OP_STATUS.PENDING), true)
    assert.equal(isActive(OP_STATUS.RUNNING), true)
    assert.equal(isActive(OP_STATUS.FAILED), false)
    assert.equal(isUsable(OP_STATUS.COMPLETED), true)
    assert.equal(isUsable(OP_STATUS.ATTENTION), true)
    assert.equal(isUsable(OP_STATUS.FAILED), false)
    assert.equal(isUsable(OP_STATUS.ROLLED_BACK), false)
})

// ── Review classification ────────────────────────────────────────────

test('the review screen tells new from linked from skipped from unavailable', () => {
    assert.equal(classifyComponent({ required: true, installed: true, action: 'create' }, false), 'new')
    assert.equal(classifyComponent({ required: false, installed: true, action: 'create' }, true), 'new')
    assert.equal(classifyComponent({ required: false, installed: true, action: 'create' }, false), 'skipped')
    assert.equal(classifyComponent({ required: true, installed: true, action: 'link' }, false), 'link')
    assert.equal(classifyComponent({ required: true, installed: true, action: 'none' }, true), 'skipped')
    assert.equal(classifyComponent({ required: false, installed: false, action: 'create' }, true), 'unavailable')
    assert.equal(classifyComponent({ required: true, installed: false, action: 'create' }, true), 'required-missing')
    for (const k of ['new', 'link', 'skipped', 'unavailable', 'required-missing']) assert.notEqual(classificationLabel(k), k)
})

// ── The request key ──────────────────────────────────────────────────

test('the request key is within the backend rule and never repeats', () => {
    const a = newIdempotencyKey()
    const b = newIdempotencyKey()
    assert.match(a, /^[A-Za-z0-9_-]{8,64}$/)
    assert.notEqual(a, b)
})

test('the decisions vocabulary matches the backend', () => {
    assert.deepEqual(Object.values(DECISIONS).sort(), ['omit', 'teamhub_only'])
})

// ── The pump ─────────────────────────────────────────────────────────

test('the pump calls run until the operation rests and reports every state', async () => {
    const states = [
        { status: 'running', steps: [] },
        { status: 'running', steps: [], pollAfter: 5 },
        { status: 'completed', steps: [] },
    ]
    const seen = []
    const waits = []
    let i = 0
    const final = await pumpOperation(
        async () => states[i++],
        s => seen.push(s.status),
        { sleep: async ms => { waits.push(ms) } },
    )
    assert.equal(final.status, 'completed')
    assert.deepEqual(seen, ['running', 'running', 'completed'])
    assert.deepEqual(waits, [800, 5000], 'a short beat, then the pause OpenProject asked for')
    assert.equal(i, 3, 'no call after the operation rested')
})

test('the pump backs off while somebody else holds the lease', async () => {
    const states = [
        { status: 'running', busy: true },
        { status: 'running', busy: true },
        { status: 'failed' },
    ]
    const waits = []
    let i = 0
    await pumpOperation(async () => states[i++], () => {}, { sleep: async ms => { waits.push(ms) } })
    assert.deepEqual(waits, [1500, 3000])
})

test('the pump stops when aborted and when the transport throws', async () => {
    const signal = { aborted: false }
    let calls = 0
    const final = await pumpOperation(
        async () => { calls++; signal.aborted = true; return { status: 'running' } },
        () => {},
        { sleep: async () => {}, signal },
    )
    assert.equal(calls, 1)
    assert.equal(final.status, 'running')

    await assert.rejects(pumpOperation(async () => { throw new Error('network') }, () => {}, { sleep: async () => {} }), /network/)
})

// ── Progress rows and links ──────────────────────────────────────────

test('progress rows carry what the list renders and nothing undefined', () => {
    const rows = progressRows({ steps: [
        { key: 'team', status: 'completed', externalId: 'team-1', attempts: 1 },
        { key: 'talk', status: 'failed', errorCode: 'talk_create', errorMessage: 'down', retrySafe: true, attempts: 2 },
    ] })
    assert.equal(rows.length, 2)
    assert.equal(rows[0].label, stepLabel('team'))
    assert.equal(rows[0].errorMessage, null)
    assert.equal(rows[1].retrySafe, true)
    assert.equal(rows[1].statusLabel, stepStatusLabel('failed'))
    assert.deepEqual(rows[1].detail, {})
    assert.deepEqual(progressRows(null), [])
})

test('resource links come from the result: the project and the managed folder', () => {
    const links = resourceLinks({ result: {
        project: { url: 'https://op.example.test/projects/apollo' },
        resources: [
            { app: 'files', type: 'openproject_folder', url: 'https://op.example.test/open' },
            { app: 'talk', type: 'conversation', url: null },
        ],
    } })
    assert.equal(links.length, 2)
    assert.equal(links[0].url, 'https://op.example.test/projects/apollo')
    assert.equal(links[1].url, 'https://op.example.test/open')
    assert.deepEqual(resourceLinks(null), [])
})
