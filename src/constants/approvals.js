/**
 * Approvals on a service request, the pure half (v4.20.58, phase 8h;
 * `docs/service-builder.md` § 14.4): what the builder edits and sends, what
 * stands between it and publishing, and the starters a new service can begin
 * from.
 *
 * The server is the authority (`TeamServiceBuilder`, `ApproverResolver`). The
 * sentences are its own, word for word, so both sides share one translation.
 */
import { translate as t } from '@nextcloud/l10n'

export const APPROVER_SOURCE = {
	USER: 'user',
	GROUP: 'group',
	MANAGER: 'manager',
	FIELD: 'field',
}

export const ON_REJECT = {
	END: 'end',
	CONTINUE: 'continue',
}

export function approverSourceLabel(source) {
	switch (source) {
	// TRANSLATORS: who approves a step of a service: one person the team names
	case APPROVER_SOURCE.USER: return t('teamhub', 'A person')
	// TRANSLATORS: who approves a step of a service: a Nextcloud group the team names
	case APPROVER_SOURCE.GROUP: return t('teamhub', 'A group')
	// TRANSLATORS: who approves a step of a service: the manager in the requester's account
	case APPROVER_SOURCE.MANAGER: return t('teamhub', 'The requester\'s manager')
	// TRANSLATORS: who approves a step of a service: the person the requester picked on the request form
	case APPROVER_SOURCE.FIELD: return t('teamhub', 'A person picked on the request form')
	default: return ''
	}
}

/** The sources that can come up empty, and so need somebody to fall back on. */
export function approverNeedsFallback(source) {
	return source === APPROVER_SOURCE.MANAGER || source === APPROVER_SOURCE.FIELD
}

function person(value) {
	return value?.id ? { id: value.id, name: value.name || value.id } : null
}

/**
 * The approval as the builder edits it: one slot per source, so changing the
 * source back finds what was picked before.
 */
export function approvalForm(approval = {}) {
	const approver = approval?.approver || {}
	const source = Object.values(APPROVER_SOURCE).includes(approver.source) ? approver.source : APPROVER_SOURCE.USER
	const picked = person(approver)
	return {
		source,
		user: source === APPROVER_SOURCE.USER ? picked : null,
		group: source === APPROVER_SOURCE.GROUP ? picked : null,
		field: source === APPROVER_SOURCE.FIELD ? approver.id || '' : '',
		fallback: person(approver.fallback),
		approveLabel: approval?.approveLabel || '',
		rejectLabel: approval?.rejectLabel || '',
		onReject: approval?.onReject === ON_REJECT.CONTINUE ? ON_REJECT.CONTINUE : ON_REJECT.END,
	}
}

/** The document's `approval`. */
export function approvalDocument(form) {
	const picked = {
		[APPROVER_SOURCE.USER]: person(form?.user),
		[APPROVER_SOURCE.GROUP]: person(form?.group),
		[APPROVER_SOURCE.FIELD]: form?.field ? { id: form.field, name: '' } : null,
	}[form?.source] || { id: '', name: '' }
	return {
		approver: {
			source: form?.source || APPROVER_SOURCE.USER,
			id: picked.id,
			name: picked.name,
			fallback: person(form?.fallback) || { id: '', name: '' },
		},
		approveLabel: String(form?.approveLabel || '').trim(),
		rejectLabel: String(form?.rejectLabel || '').trim(),
		onReject: form?.onReject === ON_REJECT.CONTINUE ? ON_REJECT.CONTINUE : ON_REJECT.END,
	}
}

/**
 * What stands between the approvals and publishing, as far as the builder can
 * know: whether somebody still exists is the server's to say.
 *
 * @param {object[]} approvals the steps' approvals, as the builder edits them
 * @param {string[]} personFields the keys of the form's person fields
 * @return {string[]}
 */
export function approvalProblems(approvals, personFields) {
	const problems = []
	for (const form of approvals || []) {
		const { approver } = approvalDocument(form)
		const missing = approver.source !== APPROVER_SOURCE.MANAGER && !approver.id
		if (missing || (approver.source === APPROVER_SOURCE.FIELD && !personFields.includes(approver.id))) {
			problems.push(t('teamhub', 'Say for every approval who approves.'))
		}
		if (!approver.fallback.id && approverNeedsFallback(approver.source)) {
			problems.push(t('teamhub', 'Say who approves when there is no manager or nobody was picked.'))
		}
	}
	return [...new Set(problems)]
}

// ── Starters ─────────────────────────────────────────────────────────────

export const STARTER = {
	ISSUE: 'issue',
	APPROVAL: 'approval',
	INTAKE: 'intake',
}

export function starterLabel(starter) {
	switch (starter) {
	// TRANSLATORS: what a new service can start from: one step, the team handles it
	case STARTER.ISSUE: return t('teamhub', 'Issue')
	// TRANSLATORS: what a new service can start from: somebody approves, then the team handles it
	case STARTER.APPROVAL: return t('teamhub', 'Request with approval')
	// TRANSLATORS: what a new service can start from: a form of the team's own, checked and then handled
	case STARTER.INTAKE: return t('teamhub', 'Intake form')
	default: return ''
	}
}

/**
 * The steps and the request form a starter begins with, as a document: words
 * in the language of whoever builds it, which the team then owns.
 */
export function starterDocument(starter) {
	const handle = { kind: 'desk', label: t('teamhub', 'Handle the request') }
	switch (starter) {
	case STARTER.APPROVAL:
		return {
			steps: [
				{
					kind: 'approval',
					label: t('teamhub', 'Manager approves'),
					approval: { approver: { source: APPROVER_SOURCE.MANAGER } },
				},
				handle,
			],
		}
	case STARTER.INTAKE:
		return {
			request: {
				askTeam: false,
				titleField: 'subject',
				fields: [
					{ key: 'subject', type: 'short', label: t('teamhub', 'Subject'), required: true },
					{ key: 'explanation', type: 'long', label: t('teamhub', 'Explanation'), required: true },
					{ key: 'needed_by', type: 'date', label: t('teamhub', 'Needed by') },
				],
			},
			steps: [
				{ kind: 'desk', label: t('teamhub', 'Check the request') },
				handle,
			],
		}
	default:
		return { steps: [handle] }
	}
}
