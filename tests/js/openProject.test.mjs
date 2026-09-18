/**
 * src/lib/openProject.js — the state and error derivations the two
 * OpenProject widgets and the settings panel branch on (v4.9.3).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    CODES, classifyError, errorMessage, widgetState, dueState, projectStatusTone,
    mergeItems, configFromLink, isConfigurationCode, isConnectionCode,
    isTransientCode, isBrokenConnectionCode, personalSettingsUrl, buildWorkPackagePayload,
} from '../../src/lib/openProject.js'

// ── Error classification ─────────────────────────────────────────────

test('a backend OpenProject failure is classified by its code, not its text', () => {
    const e = { response: { status: 412, data: { code: 'user_not_connected', error: 'Connect first', administratorMessage: 'Personal settings → OpenProject' } } }
    const c = classifyError(e)
    assert.equal(c.code, CODES.USER_NOT_CONNECTED)
    assert.equal(c.message, 'Connect first')
    assert.equal(c.administratorMessage, 'Personal settings → OpenProject')
    assert.equal(c.status, 412)
})

test('a backend failure without a sentence gets the sentence for its code', () => {
    const c = classifyError({ response: { status: 502, data: { code: 'api_unavailable', error: '' } } })
    assert.equal(c.message, errorMessage(CODES.API_UNAVAILABLE))
})

test('a project-already-linked 409 carries the team', () => {
    const c = classifyError({ response: { status: 409, data: { code: 'project_already_linked', error: 'x', teams: [{ teamId: 'b', name: 'B' }] } } })
    assert.equal(c.code, CODES.PROJECT_ALREADY_LINKED)
    assert.deepEqual(c.teams, [{ teamId: 'b', name: 'B' }])
})

test('a plain 403 from the membership gate is permission_denied', () => {
    const c = classifyError({ response: { status: 403, data: { error: 'You are not a member of this team' } } })
    assert.equal(c.code, CODES.PERMISSION_DENIED)
})

test('a network drop or a 500 is a temporary failure with a sentence, never a stack', () => {
    assert.equal(classifyError(new Error('Network Error')).code, CODES.TEMPORARY_FAILURE)
    const c = classifyError({ response: { status: 500, data: { error: 'Failed to load the OpenProject overview', ref: 'ab12cd' } } })
    assert.equal(c.code, CODES.TEMPORARY_FAILURE)
    assert.equal(c.message, 'Failed to load the OpenProject overview')
    assert.equal(classifyError(undefined).code, CODES.TEMPORARY_FAILURE)
})

test('every code has a sentence and the unknown code has a fallback', () => {
    for (const code of Object.values(CODES)) {
        if (code === CODES.PROJECT_ALREADY_LINKED) continue
        assert.ok(errorMessage(code).length > 10, code)
    }
    assert.ok(errorMessage('something_new').length > 0)
})

test('code families drive the action shown', () => {
    assert.ok(isConfigurationCode(CODES.INTEGRATION_NOT_INSTALLED))
    assert.ok(isConfigurationCode(CODES.HOST_NOT_CONFIGURED))
    assert.ok(!isConfigurationCode(CODES.USER_NOT_CONNECTED))
    assert.ok(isConnectionCode(CODES.USER_NOT_CONNECTED))
    assert.ok(isConnectionCode(CODES.AUTH_FAILED))
    assert.ok(!isConnectionCode(CODES.PERMISSION_DENIED))
    assert.ok(isTransientCode(CODES.API_UNAVAILABLE))
    assert.ok(isTransientCode(CODES.RATE_LIMITED))
    assert.ok(!isTransientCode(CODES.PROJECT_NOT_FOUND))
})

test("a broken connection is the administrator's: configuration, reachability, a stale link, an unknown answer", () => {
    for (const code of [
        CODES.INTEGRATION_NOT_INSTALLED, CODES.INTEGRATION_DISABLED, CODES.INTEGRATION_INCOMPATIBLE,
        CODES.HOST_NOT_CONFIGURED, CODES.API_UNAVAILABLE, CODES.TEMPORARY_FAILURE, CODES.RATE_LIMITED,
        CODES.LINK_STALE, CODES.UNSUPPORTED_RESPONSE,
    ]) {
        assert.ok(isBrokenConnectionCode(code), code)
    }
    // The member can act on these (connect / reconnect), or they are about
    // the project rather than the connection — the widget keeps its words.
    for (const code of [CODES.USER_NOT_CONNECTED, CODES.AUTH_FAILED, CODES.PERMISSION_DENIED, CODES.PROJECT_NOT_FOUND]) {
        assert.ok(!isBrokenConnectionCode(code), code)
    }
})

test('the connect action points at the official app\'s personal settings', () => {
    assert.equal(personalSettingsUrl(), '/index.php/settings/user/openproject')
})

// ── Widget state ─────────────────────────────────────────────────────

test('widget state: unlinked wins over everything', () => {
    assert.equal(widgetState({ linked: false }, { x: 1 }, null, false), 'unlinked')
    assert.equal(widgetState(null, null, null, true), 'unlinked')
})

test('widget state: loading until the first payload, error without one, stale-error with one', () => {
    const cfg = { linked: true }
    assert.equal(widgetState(cfg, null, null, true), 'loading')
    assert.equal(widgetState(cfg, null, null, false), 'loading')
    assert.equal(widgetState(cfg, null, { code: 'x' }, false), 'error')
    assert.equal(widgetState(cfg, { project: {} }, { code: 'x' }, false), 'stale-error')
    assert.equal(widgetState(cfg, { project: {} }, null, true), 'ready')
    assert.equal(widgetState(cfg, { project: {} }, null, false), 'ready')
})

// ── Dates and status ─────────────────────────────────────────────────

test('due state relative to the viewer\'s today', () => {
    assert.equal(dueState('2026-09-10', '2026-09-11'), 'overdue')
    assert.equal(dueState('2026-09-11', '2026-09-11'), 'today')
    assert.equal(dueState('2026-09-18', '2026-09-11'), 'soon')
    assert.equal(dueState('2026-09-19', '2026-09-11'), 'later')
    assert.equal(dueState(null, '2026-09-11'), null)
    assert.equal(dueState('2026-09-11', ''), null)
})

test('project status tone: three known codes, everything else neutral', () => {
    assert.equal(projectStatusTone('on_track'), 'success')
    assert.equal(projectStatusTone('at_risk'), 'warning')
    assert.equal(projectStatusTone('off_track'), 'error')
    assert.equal(projectStatusTone('finished'), 'neutral')
    assert.equal(projectStatusTone(null), 'neutral')
})

// ── Lists ────────────────────────────────────────────────────────────

test('paging never repeats a work package', () => {
    const merged = mergeItems([{ id: 1 }, { id: 2 }], [{ id: 2 }, { id: 3 }])
    assert.deepEqual(merged.map(i => i.id), [1, 2, 3])
    assert.deepEqual(mergeItems([], []), [])
})

// ── Store facts ──────────────────────────────────────────────────────

test('config from a saved link mirrors the layout bundle shape', () => {
    const cfg = configFromLink({
        projectId: 12, projectIdentifier: 'demo', projectName: 'Demo', stale: false,
        urls: { project: 'https://op.example/projects/demo' },
    }, true)
    assert.deepEqual(cfg, {
        available: true, eligible: true, linked: true, stale: false,
        project: { id: 12, identifier: 'demo', name: 'Demo', url: 'https://op.example/projects/demo' },
    })
})

test('config without a link is unlinked', () => {
    assert.deepEqual(configFromLink(null, false), { available: false, eligible: false, linked: false, stale: false, project: null })
})

// ── Create work package (v4.9.15) ────────────────────────────────────

test('the create payload carries only what was filled in', () => {
    const full = buildWorkPackagePayload({
        subject: '  Plan the review  ', type: { id: 3, name: 'Task' }, assignee: { id: 6, name: 'Lieke' },
        dueDate: '2026-09-30', description: '  Notes  ',
    })
    assert.deepEqual(full, { subject: 'Plan the review', typeId: 3, assigneeId: 6, dueDate: '2026-09-30', description: 'Notes' })

    const minimal = buildWorkPackagePayload({ subject: 'Only a subject', type: { id: 3 }, assignee: null, dueDate: '', description: '' })
    assert.deepEqual(minimal, { subject: 'Only a subject', typeId: 3 })

    // A malformed date and a "Nobody" assignee are absent, never sent as empty strings.
    const odd = buildWorkPackagePayload({ subject: 'x', type: { id: '5' }, assignee: { id: 0 }, dueDate: '30/09/2026' })
    assert.deepEqual(odd, { subject: 'x', typeId: 5 })

    assert.deepEqual(buildWorkPackagePayload(null), { subject: '', typeId: 0 })
})
