/**
 * Recurring meetings in the browser (v4.20.8): the Repeat field's model,
 * mirroring Nextcloud Calendar's own choices, and what is sent to the
 * server. The server (`lib/Service/MeetingRecurrence.php`) builds the RRULE
 * and is the one that validates; this only shapes the form. Pure functions,
 * no Vue and no strings. DESIGN §2.263.
 */

export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']
export const FREQUENCIES = ['none', 'daily', 'weekly', 'monthly', 'yearly']

/**
 * The weekday code of a YYYY-MM-DD date, read as a calendar date (no zone).
 *
 * @param {string} iso YYYY-MM-DD
 * @return {string} MO..SU, or '' for no date
 */
export function weekdayCode(iso) {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '')
	if (!m) return ''
	const day = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay() // 0 = Sunday
	return WEEKDAYS[(day + 6) % 7]
}

/**
 * Which occurrence of its weekday a date is in its month, as Calendar
 * offers it: 1 to 4, or -1 for a fifth one ("the last").
 *
 * @param {string} iso YYYY-MM-DD
 * @return {number}
 */
export function monthlyPosition(iso) {
	const day = Number((iso || '').slice(8, 10)) || 1
	const position = Math.floor((day - 1) / 7) + 1
	return position >= 5 ? -1 : position
}

/**
 * A fresh Repeat model: does not repeat, weekly on the meeting's own day if
 * the user switches it on, ending never.
 *
 * @param {string} iso the meeting's date
 * @return {object}
 */
export function defaultRecurrence(iso = '') {
	const code = weekdayCode(iso)
	return {
		freq: 'none',
		interval: 1,
		byDay: code ? [code] : [],
		monthlyMode: 'date',
		end: { type: 'never', until: '', count: 10 },
	}
}

/**
 * Whether the model asks for a series at all.
 *
 * @param {object|null} rec the Repeat model
 * @return {boolean}
 */
export function isRecurring(rec) {
	return !!rec && rec.freq && rec.freq !== 'none'
}

/**
 * Why the model cannot be sent, or '' when it can. Codes, so the component
 * picks the sentence.
 *
 * @param {object|null} rec the Repeat model
 * @param {string} iso the meeting's date
 * @return {string} '' | 'until-missing' | 'until-before-start' | 'count-range' | 'interval-range'
 */
export function recurrenceProblem(rec, iso) {
	if (!isRecurring(rec)) return ''
	const interval = Number(rec.interval)
	if (!Number.isInteger(interval) || interval < 1 || interval > 99) return 'interval-range'
	if (rec.end?.type === 'until') {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(rec.end.until || '')) return 'until-missing'
		if (iso && rec.end.until < iso) return 'until-before-start'
	}
	if (rec.end?.type === 'count') {
		const count = Number(rec.end.count)
		if (!Number.isInteger(count) || count < 1 || count > 500) return 'count-range'
	}
	return ''
}

/**
 * The repeat setting as the server takes it: a JSON string, or null for a
 * single meeting. Weekdays go in week order; only the fields the frequency
 * uses are sent.
 *
 * @param {object|null} rec the Repeat model
 * @return {string|null}
 */
export function serializeRecurrence(rec) {
	if (!isRecurring(rec)) return null
	const out = { freq: rec.freq, interval: Number(rec.interval) || 1 }
	if (rec.freq === 'weekly') {
		out.byDay = WEEKDAYS.filter(d => (rec.byDay || []).includes(d))
	}
	if (rec.freq === 'monthly') {
		out.monthlyMode = rec.monthlyMode === 'weekday' ? 'weekday' : 'date'
	}
	const type = rec.end?.type || 'never'
	out.end = { type }
	if (type === 'until') out.end.until = rec.end.until
	if (type === 'count') out.end.count = Number(rec.end.count) || 1
	return JSON.stringify(out)
}
