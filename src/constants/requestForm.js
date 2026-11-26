/**
 * The request form of a service a service team built, the pure half
 * (v4.20.54, phase 8g; `docs/service-builder.md` § 14.3): the field types,
 * the form the builder edits, what a requester fills in, and how an answer
 * reads afterwards.
 *
 * The server is the authority (`RequestForm.php`): it normalises the form and
 * checks every answer again. The sentences here are the server's own, word
 * for word, so both sides share one translation.
 */
import { translate as t } from '@nextcloud/l10n'
import { formatIsoDate } from '../lib/localDate.js'

export const FIELD_TYPE = {
	SHORT: 'short',
	LONG: 'long',
	CHOICE: 'choice',
	MULTI: 'multi',
	DATE: 'date',
	NUMBER: 'number',
	YESNO: 'yesno',
	PERSON: 'person',
}

export const FIELD_TYPES = Object.values(FIELD_TYPE)

/** The types whose answer may name the request: one short line. */
export const TITLE_TYPES = [FIELD_TYPE.SHORT, FIELD_TYPE.CHOICE]

/** The two fields of the standard form; their names may stay empty. */
export const FIELD_KEY = {
	SUMMARY: 'summary',
	DETAILS: 'details',
}

export function fieldTypeLabel(type) {
	switch (type) {
	// TRANSLATORS: a type of field on a request form
	case FIELD_TYPE.SHORT: return t('teamhub', 'Short text')
	// TRANSLATORS: a type of field on a request form
	case FIELD_TYPE.LONG: return t('teamhub', 'Long text')
	// TRANSLATORS: a type of field on a request form: pick one of the options
	case FIELD_TYPE.CHOICE: return t('teamhub', 'One option')
	// TRANSLATORS: a type of field on a request form: pick any number of the options
	case FIELD_TYPE.MULTI: return t('teamhub', 'Several options')
	// TRANSLATORS: a type of field on a request form: a calendar date
	case FIELD_TYPE.DATE: return t('teamhub', 'Date')
	// TRANSLATORS: a type of field on a request form: a number
	case FIELD_TYPE.NUMBER: return t('teamhub', 'Number')
	// TRANSLATORS: a type of field on a request form: the answer is yes or no
	case FIELD_TYPE.YESNO: return t('teamhub', 'Yes or no')
	// TRANSLATORS: a type of field on a request form: pick a Nextcloud user
	case FIELD_TYPE.PERSON: return t('teamhub', 'Person')
	default: return type || ''
	}
}

export function hasOptions(type) {
	return type === FIELD_TYPE.CHOICE || type === FIELD_TYPE.MULTI
}

/** The words a standard field asks in when it has no name of its own. */
export function standardLabel(key) {
	switch (key) {
	case FIELD_KEY.SUMMARY: return t('teamhub', 'In one line')
	case FIELD_KEY.DETAILS: return t('teamhub', 'Describe what you need')
	default: return ''
	}
}

/** What a field (or an answer that carries its field's name) is called. */
export function fieldLabel(field) {
	return String(field?.label || '').trim() || standardLabel(field?.key)
}

/** The form every service had before it could have its own. */
export function standardRequest() {
	return {
		askTeam: true,
		titleField: FIELD_KEY.SUMMARY,
		fields: [
			{ key: FIELD_KEY.SUMMARY, type: FIELD_TYPE.SHORT, label: '', help: '', required: true, options: [] },
			{ key: FIELD_KEY.DETAILS, type: FIELD_TYPE.LONG, label: '', help: '', required: true, options: [] },
		],
	}
}

/**
 * The key of a new field, in the shape the server accepts. It stays with the
 * field for good, so renaming one breaks nothing that refers to it; the
 * server replaces a key that is already taken.
 */
export function newFieldKey() {
	let key = 'f'
	for (let i = 0; i < 8; i++) {
		key += Math.floor(Math.random() * 16).toString(16)
	}
	return key
}

/** One field of the builder's form. `optionsText` is the options, one per line. */
export function editableField(field = {}) {
	return {
		key: field.key || newFieldKey(),
		type: FIELD_TYPES.includes(field.type) ? field.type : FIELD_TYPE.SHORT,
		label: field.label || '',
		help: field.help || '',
		required: !!field.required,
		optionsText: (field.options || []).join('\n'),
	}
}

/** The options somebody typed, one per line: trimmed, without the empty and the repeated. */
export function optionsFromText(text) {
	const out = []
	for (const line of String(text || '').split('\n')) {
		const option = line.trim()
		if (option && !out.includes(option)) {
			out.push(option)
		}
	}
	return out
}

/** The builder's form for a stored document; one without a form is the standard form. */
export function requestFormFrom(document) {
	const request = document?.request && typeof document.request === 'object' ? document.request : standardRequest()
	return {
		askTeam: request.askTeam !== false,
		titleField: request.titleField || '',
		fields: (Array.isArray(request.fields) ? request.fields : []).map(editableField),
	}
}

/** The document's `request`, from the builder's form. */
export function requestDocument(request) {
	const fields = (request?.fields || []).map(field => ({
		key: field.key,
		type: field.type,
		label: String(field.label || '').trim(),
		help: String(field.help || '').trim(),
		required: !!field.required,
		options: hasOptions(field.type) ? optionsFromText(field.optionsText) : [],
	}))
	const titleField = request?.titleField || ''
	return {
		askTeam: request?.askTeam !== false,
		titleField: titleCandidates(fields).some(field => field.key === titleField) ? titleField : '',
		fields,
	}
}

/** The fields whose answer may name the request. */
export function titleCandidates(fields) {
	return (fields || []).filter(field => TITLE_TYPES.includes(field.type))
}

/** What stands between this form and publishing: the server's own sentences. */
export function requestProblems(request) {
	const problems = []
	const { fields } = requestDocument(request)
	if (fields.some(field => fieldLabel(field) === '')) {
		problems.push(t('teamhub', 'Give every field of the request form a name.'))
	}
	if (fields.some(field => hasOptions(field.type) && field.options.length < 2)) {
		problems.push(t('teamhub', 'Give every field with choices at least two options.'))
	}
	return problems
}

/** Whether the options typed for a field are within the server's limits. */
export function optionsValid(field, maxOptions = 20, maxLength = 100) {
	if (!hasOptions(field?.type)) {
		return true
	}
	const options = optionsFromText(field.optionsText)
	return options.length <= maxOptions && options.every(option => option.length <= maxLength)
}

// ── Filling it in ────────────────────────────────────────────────────────

/** Nothing filled in. `false` is an answer: it is what *No* sends. */
export function isEmptyAnswer(value) {
	return value === null || value === undefined
		|| (Array.isArray(value) && value.length === 0)
		|| (typeof value === 'string' && value.trim() === '')
}

/** Whether every field that must be answered is. The server checks the rest. */
export function isComplete(fields, answers) {
	return (fields || []).every(field => !field.required || !isEmptyAnswer(answers?.[field.key]))
}

/** What is sent: the answers that were given, a person as their uid. */
export function answersPayload(fields, answers) {
	const out = {}
	for (const field of fields || []) {
		const value = answers?.[field.key]
		if (isEmptyAnswer(value)) {
			continue
		}
		if (field.type === FIELD_TYPE.PERSON) {
			out[field.key] = value.id
		} else {
			out[field.key] = typeof value === 'string' ? value.trim() : value
		}
	}
	return out
}

/**
 * The answers that still fit after the service was published again while
 * somebody was filling in its form: the same field, of the same type, and
 * for a choice only the options that are still there.
 */
export function keepAnswers(before, after, answers) {
	const out = {}
	for (const field of after || []) {
		const old = (before || []).find(candidate => candidate.key === field.key && candidate.type === field.type)
		const value = answers?.[field.key]
		if (!old || isEmptyAnswer(value)) {
			continue
		}
		if (field.type === FIELD_TYPE.MULTI) {
			out[field.key] = value.filter(option => (field.options || []).includes(option))
		} else if (field.type !== FIELD_TYPE.CHOICE || (field.options || []).includes(value)) {
			out[field.key] = value
		}
	}
	return out
}

// ── Reading it ───────────────────────────────────────────────────────────

/** One answer of `workflow.data.form`, as text. */
export function answerText(entry) {
	const value = entry?.value
	switch (entry?.type) {
	case FIELD_TYPE.MULTI:
		return Array.isArray(value) ? value.join(', ') : ''
	case FIELD_TYPE.YESNO:
		return value === true ? t('teamhub', 'Yes') : t('teamhub', 'No')
	case FIELD_TYPE.PERSON:
		return entry.text || value || ''
	case FIELD_TYPE.DATE:
		return formatIsoDate(value) || value || ''
	default:
		return typeof value === 'string' ? value : ''
	}
}

/** The answers of a request, in the form's order; empty for one made before forms. */
export function answersOf(workflow) {
	const form = workflow?.data?.form
	return Array.isArray(form) ? form.filter(entry => entry && typeof entry === 'object') : []
}

// ── The confirmation ─────────────────────────────────────────────────────

export const CONFIRM_MODE = {
	REQUESTER: 'requester',
	AUTO: 'auto',
	NONE: 'none',
}

export const DEFAULT_CONFIRM_DAYS = 14

export function confirmFormFrom(document) {
	const confirm = document?.confirm || {}
	return {
		mode: Object.values(CONFIRM_MODE).includes(confirm.mode) ? confirm.mode : CONFIRM_MODE.REQUESTER,
		days: String(confirm.days > 0 ? confirm.days : DEFAULT_CONFIRM_DAYS),
	}
}

export function confirmDocument(confirm) {
	return {
		mode: confirm?.mode || CONFIRM_MODE.REQUESTER,
		days: Number(String(confirm?.days ?? '').trim()) || DEFAULT_CONFIRM_DAYS,
	}
}

export function confirmDaysValid(confirm, max = 90) {
	if (confirm?.mode !== CONFIRM_MODE.AUTO) {
		return true
	}
	const days = String(confirm.days ?? '').trim()
	return /^\d+$/.test(days) && Number(days) >= 1 && Number(days) <= max
}

/**
 * When a request that waits for its confirmation is confirmed by itself, in
 * epoch seconds; 0 when it is not (yet) or never.
 */
export function confirmsItselfAt(workflow) {
	const confirm = workflow?.data?.confirm
	if (confirm?.mode !== CONFIRM_MODE.AUTO || !(confirm.days > 0)) {
		return 0
	}
	const step = (workflow.steps || []).find(candidate => candidate.key === 'confirm')
	const waiting = step && ['available', 'in_progress'].includes(step.status)
	return waiting && step.enteredAt ? step.enteredAt + confirm.days * 86400 : 0
}
