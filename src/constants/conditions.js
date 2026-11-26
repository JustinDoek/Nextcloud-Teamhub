/**
 * A condition that skips a step of a service, the pure half (v4.20.66, phase
 * 8j; `docs/service-builder.md` § 14.6): what the builder edits and sends,
 * what stands between it and publishing, and how it reads in a step's header.
 *
 * The server is the authority (`StepCondition`, `TeamServiceBuilder::when()`).
 * The sentences are its own, word for word.
 */
import { translate as t } from '@nextcloud/l10n'
import { FIELD_TYPE } from './requestForm.js'

export const CONDITION_OP = {
	IS: 'is',
	IS_NOT: 'is_not',
	AT_LEAST: 'at_least',
	AT_MOST: 'at_most',
	FILLED: 'filled',
}

const ORDERED = [CONDITION_OP.IS, CONDITION_OP.IS_NOT, CONDITION_OP.AT_LEAST, CONDITION_OP.AT_MOST, CONDITION_OP.FILLED]

/** Which comparisons a field of this type can answer; the server's `OPS_BY_TYPE`. */
export const OPS_BY_TYPE = {
	[FIELD_TYPE.SHORT]: [CONDITION_OP.IS, CONDITION_OP.IS_NOT, CONDITION_OP.FILLED],
	[FIELD_TYPE.LONG]: [CONDITION_OP.FILLED],
	[FIELD_TYPE.CHOICE]: [CONDITION_OP.IS, CONDITION_OP.IS_NOT, CONDITION_OP.FILLED],
	[FIELD_TYPE.MULTI]: [CONDITION_OP.IS, CONDITION_OP.IS_NOT, CONDITION_OP.FILLED],
	[FIELD_TYPE.YESNO]: [CONDITION_OP.IS, CONDITION_OP.FILLED],
	[FIELD_TYPE.NUMBER]: ORDERED,
	[FIELD_TYPE.DATE]: ORDERED,
	[FIELD_TYPE.PERSON]: [CONDITION_OP.FILLED],
}

export function opsFor(type) {
	return OPS_BY_TYPE[type] || [CONDITION_OP.FILLED]
}

/** The comparison in words, for the field's type: a list *includes*, a date is *on or after*. */
export function opLabel(op, type) {
	const multi = type === FIELD_TYPE.MULTI
	const date = type === FIELD_TYPE.DATE
	switch (op) {
	// TRANSLATORS: a condition on a step of a service: the answer to a field is this value
	case CONDITION_OP.IS: return multi ? t('teamhub', 'includes') : t('teamhub', 'is')
	// TRANSLATORS: a condition on a step of a service: the answer to a field is not this value
	case CONDITION_OP.IS_NOT: return multi ? t('teamhub', 'does not include') : t('teamhub', 'is not')
	// TRANSLATORS: a condition on a step of a service: a number or a date is this value or more, or later
	case CONDITION_OP.AT_LEAST: return date ? t('teamhub', 'is on or after') : t('teamhub', 'is at least')
	// TRANSLATORS: a condition on a step of a service: a number or a date is this value or less, or earlier
	case CONDITION_OP.AT_MOST: return date ? t('teamhub', 'is on or before') : t('teamhub', 'is at most')
	// TRANSLATORS: a condition on a step of a service: the field was answered at all
	case CONDITION_OP.FILLED: return t('teamhub', 'is filled in')
	default: return ''
	}
}

/** The condition as the builder edits it; `on: false` is a step that always runs. */
export function conditionForm(when = null) {
	const on = !!when?.field
	return {
		on,
		field: on ? when.field : '',
		op: on && ORDERED.includes(when.op) ? when.op : CONDITION_OP.IS,
		value: on && when.value !== undefined ? when.value : '',
	}
}

/** The document's `when`, or null for a step that always runs. */
export function conditionDocument(form, fields = []) {
	if (!form?.on || !form.field) {
		return null
	}
	const type = fields.find(f => f.key === form.field)?.type
	let value = form.op === CONDITION_OP.FILLED ? '' : form.value
	if (type === FIELD_TYPE.YESNO) {
		value = value === true || value === 'yes'
	} else if (typeof value !== 'string') {
		value = value === null || value === undefined ? '' : String(value)
	}
	return { field: form.field, op: form.op, value: typeof value === 'string' ? value.trim() : value }
}

/**
 * What stands between the conditions and publishing, as the server says it.
 *
 * @param {object[]} steps the builder's steps, with `kind` and `condition`
 * @param {object[]} fields the request form's fields
 * @return {string[]}
 */
export function conditionProblems(steps, fields) {
	const problems = []
	let always = false
	let desk = false
	// v4.20.71 — a step may use the request form and the fields of the steps
	// before it, never its own or a later one.
	const known = (fields || []).slice()
	const taskFields = step => (step.tasks || []).flatMap(task => task.fields || [])
	const later = (steps || []).flatMap(taskFields)
	;(steps || []).forEach((step, i) => {
		desk = desk || step.kind === 'desk'
		const when = conditionDocument(step.condition, known.concat(later))
		if (!when) {
			always = always || step.kind === 'desk'
			known.push(...taskFields(step))
			return
		}
		const type = known.find(f => f.key === when.field)?.type
		if (!type && later.some(f => f.key === when.field)) {
			problems.push(t('teamhub', 'A condition can only use a field of the request form or of a step before it.'))
		} else if (!type) {
			problems.push(t('teamhub', 'A condition uses a field that is not on the request form any more.'))
		} else if (!opsFor(type).includes(when.op)) {
			problems.push(t('teamhub', 'A condition asks something its field cannot answer. Pick another comparison.'))
		} else if (when.op !== CONDITION_OP.FILLED && type !== FIELD_TYPE.YESNO && String(when.value).trim() === '') {
			problems.push(t('teamhub', 'Give every condition a value.'))
		}
		if (i === steps.length - 1) {
			problems.push(t('teamhub', 'The last step runs on every request. Remove its condition.'))
		}
		known.push(...taskFields(step))
	})
	if (desk && !always) {
		problems.push(t('teamhub', 'At least one team step runs on every request. Remove the condition from one.'))
	}
	return [...new Set(problems)]
}

/** "Only when Kind is Laptop", for a step's header; '' when it always runs. */
export function conditionSummary(when, fields = [], labelOf = f => f?.label || '') {
	if (!when?.field) {
		return ''
	}
	const field = fields.find(f => f.key === when.field)
	const name = labelOf(field) || t('teamhub', 'A field without a name')
	if (when.op === CONDITION_OP.FILLED) {
		// TRANSLATORS: in the header of a step of a service; {field} is the name of a field of the request form
		return t('teamhub', 'Only when {field} is filled in', { field: name })
	}
	const value = field?.type === FIELD_TYPE.YESNO ? (when.value ? t('teamhub', 'Yes') : t('teamhub', 'No')) : String(when.value)
	// TRANSLATORS: in the header of a step of a service; {field} is a field of the request form, {op} a comparison such as "is" or "is at least", {value} what it is compared with
	return t('teamhub', 'Only when {field} {op} {value}', { field: name, op: opLabel(when.op, field?.type), value })
}
