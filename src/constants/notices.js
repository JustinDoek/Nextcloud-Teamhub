/**
 * The notices of a step in the service builder, the pure half (v4.20.62,
 * phase 8i; `docs/service-builder.md` § 14.5): what the builder edits and
 * sends, what stands between it and publishing, and how a notice reads in the
 * history.
 *
 * The server is the authority (`TeamServiceBuilder::notify()`,
 * `WorkflowNoticeService`). The sentences are its own, word for word.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export const NOTICE_ON = {
	REACHED: 'reached',
	DONE: 'done',
	REJECTED: 'rejected',
}

export const NOTICE_TO = {
	REQUESTER: 'requester',
	TASK: 'task',
	USER: 'user',
	GROUP: 'group',
}

export const NOTICE_CHANNEL = {
	BELL: 'bell',
	EMAIL: 'email',
}

export function noticeOnLabel(on) {
	switch (on) {
	// TRANSLATORS: when a service sends a notice: the request arrives at this step
	case NOTICE_ON.REACHED: return t('teamhub', 'When the step is reached')
	// TRANSLATORS: when a service sends a notice: this step is finished
	case NOTICE_ON.DONE: return t('teamhub', 'When the step is done')
	// TRANSLATORS: when a service sends a notice: somebody rejected this step
	case NOTICE_ON.REJECTED: return t('teamhub', 'When the step is rejected')
	default: return ''
	}
}

export function noticeToLabel(to, approval = false) {
	switch (to) {
	case NOTICE_TO.REQUESTER: return t('teamhub', 'The requester')
	case NOTICE_TO.TASK: return approval
		// TRANSLATORS: who gets a notice: whoever approves this step
		? t('teamhub', 'The approver')
		// TRANSLATORS: who gets a notice: whoever has this task, or everybody who may take it up while nobody has
		: t('teamhub', 'Whoever has the task')
	case NOTICE_TO.USER: return t('teamhub', 'A person')
	case NOTICE_TO.GROUP: return t('teamhub', 'A group')
	default: return ''
	}
}

let nextKey = 1

function named(to, type) {
	return to?.type === type && to?.id ? { id: to.id, name: to.name || to.id } : null
}

/** One notice as the builder edits it. `key` is the list's own, never sent. */
export function newNotice(notice = {}) {
	const to = notice.to || {}
	return {
		key: nextKey++,
		on: Object.values(NOTICE_ON).includes(notice.on) ? notice.on : NOTICE_ON.REACHED,
		to: Object.values(NOTICE_TO).includes(to.type) ? to.type : NOTICE_TO.REQUESTER,
		user: named(to, NOTICE_TO.USER),
		group: named(to, NOTICE_TO.GROUP),
		bell: Array.isArray(notice.channels) ? notice.channels.includes(NOTICE_CHANNEL.BELL) : true,
		email: Array.isArray(notice.channels) && notice.channels.includes(NOTICE_CHANNEL.EMAIL),
		subject: notice.subject || '',
		text: notice.text || '',
		requester: !!notice.requester,
		fields: Array.isArray(notice.fields) ? [...notice.fields] : [],
	}
}

/** The document's notice. */
export function noticeDocument(form) {
	const picked = { [NOTICE_TO.USER]: form.user, [NOTICE_TO.GROUP]: form.group }[form.to] || { id: '', name: '' }
	return {
		on: form.on,
		to: { type: form.to, id: picked.id, name: picked.name },
		channels: [
			...(form.bell ? [NOTICE_CHANNEL.BELL] : []),
			...(form.email ? [NOTICE_CHANNEL.EMAIL] : []),
		],
		subject: String(form.subject || '').trim(),
		text: String(form.text || '').trim(),
		requester: !!form.requester,
		fields: [...form.fields],
	}
}

/**
 * What stands between the notices and publishing, as far as the builder can
 * know: whether a person or a group still exists is the server's to say.
 *
 * @param {object[]} notices every step's notices, as the builder edits them
 * @param {boolean} mayEmail whether services may send email on this server
 * @return {string[]}
 */
export function noticeProblems(notices, mayEmail) {
	const problems = []
	for (const form of notices || []) {
		const doc = noticeDocument(form)
		if (!doc.subject) {
			problems.push(t('teamhub', 'Give every notice a subject.'))
		}
		if ([NOTICE_TO.USER, NOTICE_TO.GROUP].includes(doc.to.type) && !doc.to.id) {
			problems.push(t('teamhub', 'Say for every notice who gets it.'))
		}
		if (!doc.channels.length) {
			problems.push(t('teamhub', 'Say for every notice how it is sent.'))
		} else if (!doc.channels.includes(NOTICE_CHANNEL.BELL) && !mayEmail) {
			problems.push(t('teamhub', 'Services cannot send email on this server. Send the notice as a notification.'))
		}
	}
	return [...new Set(problems)]
}

// ── In the history, for the service team ─────────────────────────────────

/** What went out, or did not. */
export function noticeLabel(event) {
	const subject = event?.payload?.subject || ''
	return event?.type === 'notice_failed'
		// TRANSLATORS: history of a service request — a notice, or the email of one, did not go out; {subject} is the notice's subject
		? t('teamhub', 'Notice not sent: {subject}', { subject })
		// TRANSLATORS: history of a service request — the service sent a notice; {subject} is its subject
		: t('teamhub', 'Notice: {subject}', { subject })
}

/** Whom it went to and how, or why it did not go out; `nameOf` turns a uid into a name. */
export function noticeDetail(event, nameOf) {
	const payload = event?.payload || {}
	const people = (payload.uids || []).map(nameOf)
	const names = people.length > 5
		// TRANSLATORS: a list of names that goes on; {names} is the first few, {n} how many more there are
		? n('teamhub', '{names} and {n} more', '{names} and {n} more', people.length - 5, { n: people.length - 5, names: people.slice(0, 5).join(', ') })
		: people.join(', ')
	switch (payload.why) {
	case 'nobody': return t('teamhub', 'There was nobody to send it to.')
	case 'too_many': return t('teamhub', 'It would go to more than 100 people.')
	case 'email_off': return t('teamhub', 'Services cannot send email on this server.')
	// TRANSLATORS: {names} are the people an email could not be sent to
	case 'email': return t('teamhub', 'No email could be sent to {names}.', { names })
	}
	const email = (payload.channels || []).includes(NOTICE_CHANNEL.EMAIL)
	const bell = (payload.channels || []).includes(NOTICE_CHANNEL.BELL)
	if (email && bell) {
		// TRANSLATORS: {names} are the people a notice went to
		return t('teamhub', 'To {names}, as a notification and by email', { names })
	}
	return email
		// TRANSLATORS: {names} are the people a notice went to
		? t('teamhub', 'To {names}, by email', { names })
		// TRANSLATORS: {names} are the people a notice went to
		: t('teamhub', 'To {names}, as a notification', { names })
}
