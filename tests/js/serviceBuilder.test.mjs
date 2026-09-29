/**
 * src/constants/serviceBuilder.js (v4.10.34) — the service builder's form,
 * the document it sends, the client copy of the server's publish checks,
 * and how a service's state reads in the Services widget. v4.10.36 — links;
 * v4.10.37 — tasks in a step (and the link service withdrawn).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    LINK_KIND,
    STEP_KIND,
    SERVICE_STATE,
    documentFromForm,
    fileDaysValid,
    formFromService,
    isHttpsUrl,
    leadDaysValid,
    moveStep,
    newStep,
    newTask,
    publishProblems,
    serviceState,
    serviceStateLabel,
    serviceStateVariant,
    serviceTitle,
    stepCountLabel,
} from '../../src/constants/serviceBuilder.js'

const SERVICE = {
    id: 7,
    version: 1,
    listed: true,
    hasChanges: false,
    draft: {
        title: 'Application intake',
        description: 'Ask for a new application.',
        category: 'apps_tools',
        // v4.10.45 — the card's icon.
        icon: 'Printer',
        leadDays: 3,
        askTeam: true,
        steps: [
            { kind: 'desk', label: 'Assess the request', tasks: [
                { label: 'Privacy check', role: 'Privacy officer', nonBlocking: false, links: [{ label: 'Checklist', url: 'https://wiki.example.org/intake', kind: 'link' }] },
                { label: 'Security check', role: 'CISO', nonBlocking: true, links: [] },
            ] },
            { kind: 'requester', label: 'Sign the agreement', tasks: [
                { label: '', role: '', nonBlocking: false, links: [{ label: 'Agreement form', url: 'https://forms.example.org/a?x=1', kind: 'form' }] },
            ] },
            { kind: 'desk', label: 'Install the app', tasks: [{ label: '', role: '', nonBlocking: false, links: [] }] },
        ],
        files: { allowed: true, edit: false, days: 14 },
    },
}

test('a new service starts as a form with one team step', () => {
    const form = formFromService(null)
    assert.equal(form.title, '')
    assert.equal(form.category, 'support_requests')
    assert.equal(form.leadDays, '', 'no lead time said')
    assert.equal(form.steps.length, 1)
    assert.equal(form.steps[0].kind, STEP_KIND.DESK)
})

test('the form round-trips the draft to the same document', () => {
    const form = formFromService(SERVICE)
    assert.equal(form.leadDays, '3')
    assert.ok(form.steps.every(s => typeof s.key === 'number'), 'every row has a key of its own')
    assert.deepEqual(documentFromForm(form), SERVICE.draft, 'askTeam is carried through untouched')
})

test('keys are unique, and never sent', () => {
    const a = newStep()
    const b = newStep()
    assert.notEqual(a.key, b.key)
    const doc = documentFromForm({ title: 'x', steps: [a] })
    assert.equal(doc.steps[0].key, undefined)
})

test('the document trims, drops a requester task\'s role, and sends no lead time as 0', () => {
    const doc = documentFromForm({
        title: '  Laptop  ',
        leadDays: '  ',
        steps: [
            { key: 1, kind: 'requester', label: ' Sign ', tasks: [{ label: ' Sign it ', role: 'stale', nonBlocking: true, links: [] }] },
            { key: 2, kind: 'desk', label: 'Order', tasks: [{ label: '', role: ' Buyer ', links: [] }] },
        ],
    })
    assert.equal(doc.title, 'Laptop')
    assert.equal(doc.leadDays, 0)
    assert.equal(doc.steps[0].label, 'Sign')
    assert.deepEqual(doc.steps[0].tasks[0], { label: 'Sign it', role: '', nonBlocking: false, links: [] })
    assert.equal(doc.steps[1].tasks[0].role, 'Buyer')
})

test('a step saved before tasks existed is read as one task', () => {
    const form = formFromService({ draft: { title: 'x', steps: [
        { kind: 'desk', label: 'Assess', role: 'Functional admin', links: [{ label: 'Guide', url: 'https://x.org' }] },
    ] } })
    assert.equal(form.steps[0].tasks.length, 1)
    assert.equal(form.steps[0].tasks[0].role, 'Functional admin')
    assert.equal(form.steps[0].tasks[0].links[0].url, 'https://x.org')
    assert.equal(newStep().tasks.length, 1, 'a new step starts with one task')
    assert.notEqual(newTask().key, newTask().key)
})

test('what stands between the form and publishing, as the server says it', () => {
    assert.deepEqual(publishProblems(formFromService(SERVICE)), [])
    assert.deepEqual(publishProblems({ title: '', steps: [] }), [
        'Give the service a name.',
        'Add at least one step for the team.',
    ])
    assert.deepEqual(publishProblems({ title: 'x', steps: [{ kind: 'desk', label: '' }] }), ['Give every step a name.'])
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a' }, { kind: 'requester', label: 'b' }] }),
        ['The last step is for the team, not the requester.'],
    )
    // v4.10.39 — a service may start with the requester again.
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'requester', label: 'a' }, { kind: 'desk', label: 'b' }] }),
        [],
    )
})

test('a lead time is empty or a whole number of days up to the limit', () => {
    assert.ok(leadDaysValid(''))
    assert.ok(leadDaysValid('5'))
    assert.ok(leadDaysValid(60))
    assert.ok(!leadDaysValid('0'))
    assert.ok(!leadDaysValid('61'))
    assert.ok(!leadDaysValid('1.5'))
    assert.ok(!leadDaysValid('-2'))
    assert.ok(leadDaysValid('10', 10))
    assert.ok(!leadDaysValid('11', 10))
})

test('moving a step keeps the rest in order and stops at an edge', () => {
    const steps = ['a', 'b', 'c']
    assert.deepEqual(moveStep(steps, 0, 1), ['b', 'a', 'c'])
    assert.deepEqual(moveStep(steps, 2, -1), ['a', 'c', 'b'])
    assert.equal(moveStep(steps, 0, -1), steps)
    assert.equal(moveStep(steps, 2, 1), steps)
    assert.deepEqual(steps, ['a', 'b', 'c'], 'the original is not touched')
})

test('a service reads as draft, published, changed or unpublished', () => {
    assert.equal(serviceState({ version: 0, listed: false }), SERVICE_STATE.DRAFT)
    assert.equal(serviceState(SERVICE), SERVICE_STATE.PUBLISHED)
    assert.equal(serviceState({ ...SERVICE, hasChanges: true }), SERVICE_STATE.CHANGED)
    assert.equal(serviceState({ ...SERVICE, listed: false }), SERVICE_STATE.UNPUBLISHED)
    assert.equal(serviceState(null), SERVICE_STATE.DRAFT)

    assert.equal(serviceStateLabel(SERVICE_STATE.CHANGED), 'Unpublished changes')
    assert.equal(serviceStateVariant(SERVICE_STATE.PUBLISHED), 'success')
    assert.equal(serviceStateVariant(SERVICE_STATE.CHANGED), 'warning')
    assert.equal(serviceStateVariant(SERVICE_STATE.DRAFT), 'tertiary')
})

test('the builder names a service by its draft, everybody else by what is published', () => {
    const renamed = { ...SERVICE, published: { title: 'Old name' }, draft: { ...SERVICE.draft, title: 'New name' } }
    assert.equal(serviceTitle(renamed), 'New name')
    assert.equal(serviceTitle(renamed, false), 'Old name')
    assert.equal(stepCountLabel(SERVICE), '3 steps')
})

test('a link is https:// with a host and no spaces', () => {
    assert.ok(isHttpsUrl('https://example.org'))
    assert.ok(isHttpsUrl('  https://example.org/path?q=1  '), 'trimmed, as the server trims')
    assert.ok(isHttpsUrl('HTTPS://example.org'))
    assert.ok(!isHttpsUrl('http://example.org'))
    assert.ok(!isHttpsUrl('javascript:alert(1)'))
    assert.ok(!isHttpsUrl('https://'))
    assert.ok(!isHttpsUrl('https://exa mple.org'))
    assert.ok(!isHttpsUrl('//example.org'))
    assert.ok(!isHttpsUrl('/apps/teamhub'))
    assert.ok(!isHttpsUrl(''))
    assert.ok(!isHttpsUrl(null))
})

test('links on a step: keys of their own, never sent, and a page unless said otherwise', () => {
    const form = formFromService(SERVICE)
    const link = form.steps[0].tasks[0].links[0]
    assert.equal(typeof link.key, 'number')
    assert.equal(documentFromForm(form).steps[0].tasks[0].links[0].key, undefined)
    const doc = documentFromForm({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ links: [{ label: ' Guide ', url: ' https://x.org ', kind: 'odd' }] }] }] })
    assert.deepEqual(doc.steps[0].tasks[0].links, [{ label: 'Guide', url: 'https://x.org', kind: LINK_KIND.LINK }])
    assert.deepEqual(newStep().tasks[0].links, [], 'a new task has no links')
})

test('a link without a name or an address stops the publish', () => {
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ links: [{ label: '', url: 'https://x.org' }] }] }] }),
        ['Give every link a name and an address.'],
    )
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ links: [{ label: 'Guide', url: '' }] }] }] }),
        ['Give every link a name and an address.'],
    )
})

test('several tasks need a name each, and a team step one task it waits for', () => {
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ label: 'p' }, { label: '' }] }] }),
        ['Give every task a name when a step has more than one.'],
    )
    assert.deepEqual(
        publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ label: 'p', nonBlocking: true }] }] }),
        ['Every step needs at least one task the request waits for.'],
    )
    assert.deepEqual(publishProblems({ title: 'x', steps: [{ kind: 'desk', label: 'a', tasks: [{ label: '' }] }] }), [],
        'one task may borrow the step\'s name')
})

test('the paperclip: on, view only, 14 days unless the service says otherwise (v4.10.38)', () => {
    const form = formFromService({ draft: { title: 'x', steps: [] } })
    assert.deepEqual(form.files, { allowed: true, edit: false, days: '14' })
    assert.deepEqual(documentFromForm({ ...form, files: { allowed: false, edit: true, days: ' 30 ' } }).files, { allowed: false, edit: true, days: 30 })
    assert.ok(fileDaysValid('1'))
    assert.ok(fileDaysValid(365))
    assert.ok(!fileDaysValid('0'))
    assert.ok(!fileDaysValid('366'))
    assert.ok(!fileDaysValid('1.5'))
    assert.ok(!fileDaysValid(''))
})

test('a new step opens, a saved one starts folded, and folding is never sent (v4.10.42)', () => {
    assert.equal(newStep().open, true)
    const form = formFromService(SERVICE)
    assert.ok(form.steps.every(s => s.open === false))
    assert.ok(documentFromForm(form).steps.every(s => !('open' in s)))
})

test('a service published as a link says it is off the Services page (v4.10.42)', () => {
    const legacy = { ...SERVICE, published: { title: 'x', start: 'link' } }
    assert.equal(serviceState(legacy), SERVICE_STATE.REPUBLISH)
    assert.equal(serviceStateLabel(SERVICE_STATE.REPUBLISH), 'Not in the service catalog: publish again')
    assert.equal(serviceStateVariant(SERVICE_STATE.REPUBLISH), 'warning')
    assert.equal(serviceState({ ...legacy, listed: false }), SERVICE_STATE.UNPUBLISHED, 'unpublished says so first')
})
