/**
 * The service builder's pure half (v4.10.34, WorkflowHub phase 8a;
 * `docs/service-builder.md`) — the form the builder dialog edits, the
 * document it sends, what stands between a draft and publishing, and how a
 * service's state reads in the Services widget.
 *
 * The server is the authority on all of it (`TeamServiceBuilder`): it
 * normalises every document and refuses a publish with the same reasons
 * `publishProblems()` gives here. The client copy only lets the
 * dialog say them while the admin is still typing. The sentences are the
 * server's own, word for word, so both sides share one translation.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export const STEP_KIND = {
	DESK: 'desk',
	REQUESTER: 'requester',
}

/** v4.10.36 — what a link on a step opens: its button's words and icon. */
export const LINK_KIND = {
	LINK: 'link',
	FORM: 'form',
}

/**
 * Whether an address is one the server accepts: absolute `https://` with a
 * host and no spaces. The same rule as `TeamServiceBuilder::url()`, so the
 * dialog can say it before the server refuses.
 */
export function isHttpsUrl(value) {
	const url = String(value ?? '').trim()
	const hasSpaceOrControl = [...url].some(ch => ch.charCodeAt(0) <= 0x20 || ch.charCodeAt(0) === 0x7f)
	if (!/^https:\/\//i.test(url) || hasSpaceOrControl) {
		return false
	}
	try {
		const parsed = new URL(url)
		return parsed.protocol === 'https:' && parsed.hostname !== ''
	} catch (e) {
		return false
	}
}

/** The category a new service starts in: the catch-all, as on the server. */
export const DEFAULT_CATEGORY = 'support_requests'

let nextKey = 1

/**
 * One task of a step (v4.10.37). `key` is the list's own, never sent. A
 * role and "may be left open" only mean something on a team task.
 */
export function newTask(task = {}) {
	return {
		key: nextKey++,
		label: task.label || '',
		role: task.role || '',
		nonBlocking: !!task.nonBlocking,
		links: (Array.isArray(task.links) ? task.links : []).map(newLink),
	}
}

/**
 * One step of the form: a kind, a name and at least one task. `open`
 * (v4.10.42) is the builder's own — whether the step's card is unfolded; a
 * new step opens, a saved one starts folded. Never sent.
 */
export function newStep(kind = STEP_KIND.DESK) {
	return { key: nextKey++, kind, label: '', tasks: [newTask()], open: true }
}

/**
 * A step's tasks, whichever shape stored it: a step saved before v4.10.37
 * carried its role and links itself, and is one task.
 */
export function tasksOfStep(step) {
	if (Array.isArray(step?.tasks) && step.tasks.length) {
		return step.tasks
	}
	return [{ label: '', role: step?.role || '', links: step?.links || [], nonBlocking: false }]
}

/** One link of a step (v4.10.36). `key` is the list's own, never sent. */
export function newLink(link = {}) {
	return {
		key: nextKey++,
		label: link.label || '',
		url: link.url || '',
		kind: link.kind === LINK_KIND.FORM ? LINK_KIND.FORM : LINK_KIND.LINK,
	}
}

/**
 * The form for a new service, or for a service's draft. The lead time is
 * held as text, because the field is: `''` is "none said", as `0` is on the
 * server. `askTeam` is carried through untouched — the form does not offer
 * it until personal requests land.
 */
export function formFromService(service = null) {
	const doc = service?.draft || {}
	const steps = Array.isArray(doc.steps) ? doc.steps : []
	return {
		title: doc.title || '',
		description: doc.description || '',
		category: doc.category || DEFAULT_CATEGORY,
		// v4.10.45 — the card's icon; '' is the default.
		icon: doc.icon || '',
		leadDays: doc.leadDays > 0 ? String(doc.leadDays) : '',
		askTeam: !!doc.askTeam,
		// v4.10.38 — the paperclip: on unless switched off, view only, 14 days.
		files: {
			allowed: doc.files?.allowed !== false,
			edit: doc.files?.edit === true,
			days: String(doc.files?.days > 0 ? doc.files.days : 14),
		},
		steps: service
			? steps.map(s => ({
				...newStep(s.kind === STEP_KIND.REQUESTER ? STEP_KIND.REQUESTER : STEP_KIND.DESK),
				label: s.label || '',
				tasks: tasksOfStep(s).map(newTask),
				open: false,
			}))
			: [newStep(STEP_KIND.DESK)],
	}
}

/** The document `POST` / `PUT /built-services` take. */
export function documentFromForm(form) {
	const days = String(form?.leadDays ?? '').trim()
	return {
		title: String(form?.title || '').trim(),
		description: String(form?.description || '').trim(),
		category: form?.category || DEFAULT_CATEGORY,
		icon: form?.icon || '',
		leadDays: days === '' ? 0 : Number(days),
		askTeam: !!form?.askTeam,
		files: {
			allowed: form?.files?.allowed !== false,
			edit: form?.files?.edit === true,
			days: Number(String(form?.files?.days ?? '14').trim()) || 14,
		},
		steps: (form?.steps || []).map(s => ({
			kind: s.kind,
			label: String(s.label || '').trim(),
			tasks: tasksOfStep(s).map(task => ({
				label: String(task.label || '').trim(),
				// A role and "may be left open" only mean something on the
				// team's own tasks.
				role: s.kind === STEP_KIND.DESK ? String(task.role || '').trim() : '',
				nonBlocking: s.kind === STEP_KIND.DESK && !!task.nonBlocking,
				links: (task.links || []).map(l => ({
					label: String(l.label || '').trim(),
					url: String(l.url || '').trim(),
					kind: l.kind === LINK_KIND.FORM ? LINK_KIND.FORM : LINK_KIND.LINK,
				})),
			})),
		})),
	}
}

/**
 * What stands between the form and publishing it — the server's
 * `TeamServiceBuilder::publishProblems()` plus the name, which the server
 * refuses on any save.
 *
 * @return {string[]}
 */
export function publishProblems(form) {
	const problems = []
	const steps = form?.steps || []
	if (!String(form?.title || '').trim()) {
		problems.push(t('teamhub', 'Give the service a name.'))
	}
	if (!steps.some(s => s.kind === STEP_KIND.DESK)) {
		problems.push(t('teamhub', 'Add at least one step for the team.'))
	}
	if (steps.some(s => !String(s.label || '').trim())) {
		problems.push(t('teamhub', 'Give every step a name.'))
	}
	// v4.10.37 — tasks: several need a name each, and a step must hold one
	// task the request waits for.
	if (steps.some(s => { const tasks = tasksOfStep(s); return tasks.length > 1 && tasks.some(task => !String(task.label || '').trim()) })) {
		problems.push(t('teamhub', 'Give every task a name when a step has more than one.'))
	}
	if (steps.some(s => s.kind === STEP_KIND.DESK && tasksOfStep(s).every(task => task.nonBlocking))) {
		problems.push(t('teamhub', 'Every step needs at least one task the request waits for.'))
	}
	if (steps.some(s => tasksOfStep(s).some(task => (task.links || []).some(l => !String(l.label || '').trim() || !String(l.url || '').trim())))) {
		problems.push(t('teamhub', 'Give every link a name and an address.'))
	}
	// v4.10.39 — a service may start with the requester (the v4.10.35 rule is lifted).
	if (steps.length && steps[steps.length - 1].kind === STEP_KIND.REQUESTER) {
		problems.push(t('teamhub', 'The last step is for the team, not the requester.'))
	}
	return problems
}

/** Whether the days a file is shared for is a whole number the server accepts (v4.10.38). */
export function fileDaysValid(value, max = 365) {
	const days = String(value ?? '').trim()
	return /^\d+$/.test(days) && Number(days) >= 1 && Number(days) <= max
}

/** Whether the lead time field holds something the server accepts. */
export function leadDaysValid(value, max = 60) {
	const days = String(value ?? '').trim()
	if (days === '') {
		return true
	}
	return /^\d+$/.test(days) && Number(days) >= 1 && Number(days) <= max
}

/** A copy of the steps with one moved by `delta` places; unchanged at an edge. */
export function moveStep(steps, index, delta) {
	const to = index + delta
	if (to < 0 || to >= steps.length) {
		return steps
	}
	const out = steps.slice()
	const [step] = out.splice(index, 1)
	out.splice(to, 0, step)
	return out
}

// ── How a service reads in the widget ───────────────────────────────────

export const SERVICE_STATE = {
	DRAFT: 'draft',
	PUBLISHED: 'published',
	CHANGED: 'changed',
	UNPUBLISHED: 'unpublished',
	// v4.10.42 — published as a link service (v4.10.36, withdrawn in
	// v4.10.37): listed, but off the Services page until published again.
	REPUBLISH: 'republish',
}

/**
 * - *draft*: never published;
 * - *published*: on the Services page as it is being edited;
 * - *changed*: on the Services page, with draft changes not yet published;
 * - *unpublished*: was published, is off the Services page now.
 */
export function serviceState(service) {
	if (!service || !(service.version > 0)) {
		return SERVICE_STATE.DRAFT
	}
	if (!service.listed) {
		return SERVICE_STATE.UNPUBLISHED
	}
	if (service.published?.start === 'link') {
		return SERVICE_STATE.REPUBLISH
	}
	return service.hasChanges ? SERVICE_STATE.CHANGED : SERVICE_STATE.PUBLISHED
}

export function serviceStateLabel(state) {
	switch (state) {
	case SERVICE_STATE.DRAFT:
		// TRANSLATORS: a service a team is building and has not published yet
		return t('teamhub', 'Draft')
	case SERVICE_STATE.PUBLISHED:
		// TRANSLATORS: a service that is on the Services page
		return t('teamhub', 'Published')
	case SERVICE_STATE.CHANGED:
		// TRANSLATORS: a service on the Services page whose edits are not published yet
		return t('teamhub', 'Unpublished changes')
	case SERVICE_STATE.REPUBLISH:
		// TRANSLATORS: a service published as a link service, a kind that no longer exists; it is off the Services page until published again
		return t('teamhub', 'Not in the service catalog: publish again')
	default:
		// TRANSLATORS: a service that was taken off the Services page
		return t('teamhub', 'Unpublished')
	}
}

/** The NcChip variant: published reads as the good state, a pending edit as the one to act on. */
export function serviceStateVariant(state) {
	switch (state) {
	case SERVICE_STATE.PUBLISHED: return 'success'
	case SERVICE_STATE.CHANGED: return 'warning'
	case SERVICE_STATE.REPUBLISH: return 'warning'
	default: return 'tertiary'
	}
}

/** The name a row shows: the draft's for the builder, the published one otherwise. */
export function serviceTitle(service, preferDraft = true) {
	const doc = preferDraft ? (service?.draft || service?.published) : (service?.published || service?.draft)
	return doc?.title || ''
}

/** "3 steps" — the draft's steps between the two fixed ones. */
export function stepCountLabel(service) {
	const count = (service?.draft?.steps || []).length
	return n('teamhub', '{n} step', '{n} steps', count, { n: count })
}
