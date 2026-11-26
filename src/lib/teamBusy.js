/**
 * Busy times on the team calendar (v4.20.15, DESIGN §2.267; drawn as a
 * timeline with one row per person since v4.20.17, §2.268).
 *
 * The Calendar tab can show when the people on the team are busy without
 * anybody sharing a calendar. TeamHub does not read personal calendars for
 * it: the browser sends one free/busy request to the viewer's own CalDAV
 * outbox, the same request Nextcloud Calendar sends when somebody is added to
 * an event, and Nextcloud answers it. The answer holds times only, and only
 * what each person's own settings let count as busy: an event marked Free, a
 * cancelled event or a calendar kept out of availability is not in it.
 *
 * `GET …/calendar/busy-people` (TeamBusyService) says whom to ask about and
 * by which address. Everything here is pure, so it is tested without a
 * browser (`tests/js/teamBusy.test.mjs`), except `readScheduleResponse()`,
 * which takes the browser's DOMParser.
 *
 * Times on the timeline are in the browser's local zone, as in the room
 * finder (`src/lib/roomFinder.js`), whose track this one copies.
 */

/**
 * People one request may name. Mirrors
 * `TeamBusyService::MAX_PEOPLE_PER_REQUEST`; the server's value wins when it
 * sends one (`maxPerRequest`).
 */
export const MAX_BUSY_PEOPLE = 12

const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav'
const DAV_NS = 'DAV:'

/**
 * The kinds of busy the timeline draws. `BUSY-UNAVAILABLE` is left out on
 * purpose: it is Nextcloud's "outside working hours" from the person's
 * Availability settings, which would grey every evening and weekend of every
 * person and bury the appointments the timeline is for.
 */
export const SHOWN_TYPES = ['BUSY', 'BUSY-TENTATIVE']

/** A Date as an iCalendar UTC date-time, `20261116T090000Z`. */
export function toIcsUtc(date) {
	return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
}

/**
 * An iCalendar date-time back to a Date. Nextcloud writes free/busy periods
 * in UTC (`…Z`); a floating value is read as UTC too, since a free/busy reply
 * has no zone to float in. Null for anything unreadable.
 */
export function parseIcsDateTime(value) {
	const m = /^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/.exec(String(value || '').trim())
	if (!m) {
		return null
	}
	const [, y, mo, d, h = '00', mi = '00', s = '00'] = m
	const ms = Date.UTC(+y, +mo - 1, +d, +h, +mi, +s)
	return Number.isNaN(ms) ? null : new Date(ms)
}

/** An RFC 5545 duration (`PT1H30M`, `P1D`, `P1W`) in milliseconds, or null. */
export function parseDuration(value) {
	const m = /^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/.exec(String(value || '').trim())
	if (!m || value === 'P' || value === 'PT') {
		return null
	}
	const [, sign, w = 0, d = 0, h = 0, mi = 0, s = 0] = m
	const ms = ((((+w * 7 + +d) * 24 + +h) * 60 + +mi) * 60 + +s) * 1000
	return sign === '-' ? -ms : ms
}

/**
 * The ORGANIZER of the request. Nextcloud refuses a free/busy request whose
 * organizer is not one of the viewer's own calendar addresses. Those are the
 * viewer's email, when they have one, and always their principal URL, which
 * is what an account without an email falls back to.
 *
 * Nextcloud compares the organizer as a plain string against those addresses
 * in the form sabre stores them, which is URL-encoded (v4.20.18): a user id
 * with a space is `Lund%20Langvak` there, and the same id written with its
 * space is refused with 403 "The organizer in the request did not match any
 * of the addresses for the owner of this inbox". So the address goes out
 * encoded exactly the way sabre encodes it, see `sabreEncodePath`.
 *
 * @param {string|null} email  `organizer` from the busy-people answer
 * @param {string} uid         the viewer's user id
 * @param {string} rootUrl     `getRootUrl()`, '' or the web root path
 */
export function organizerAddress(email, uid, rootUrl = '') {
	if (email) {
		return sabreEncodePath(`mailto:${email}`)
	}
	return sabreEncodePath(`${rootUrl}/remote.php/dav/principals/users/${uid}/`)
}

/**
 * `Sabre\HTTP\encodePath`, byte for byte: every UTF-8 byte outside
 * `A-Z a-z 0-9 _ - . ~ ( ) / : @` becomes `%` and two lowercase hex digits.
 * `LocalHref`, which holds the viewer's calendar addresses, runs every address
 * through it, and the organizer has to equal one of them exactly. Not
 * `encodeURIComponent`: that also encodes `/`, `:` and `@` and writes
 * uppercase hex, and either would fail the comparison.
 *
 * @param {string} path
 * @return {string}
 */
export function sabreEncodePath(path) {
	let out = ''
	for (const byte of new TextEncoder().encode(String(path))) {
		const ch = String.fromCharCode(byte)
		out += /[A-Za-z0-9_\-.~()/:@]/.test(ch) && byte < 0x80
			? ch
			: '%' + byte.toString(16).padStart(2, '0')
	}
	return out
}

/**
 * The VFREEBUSY request body. One request names every person, and Nextcloud
 * answers per person in one response.
 *
 * @param {{ organizer: string, emails: string[], start: Date, end: Date, uid: string }} req
 * @return {string}
 */
export function buildFreeBusyRequest({ organizer, emails, start, end, uid }) {
	const lines = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Nextcloud//TeamHub//EN',
		'METHOD:REQUEST',
		'BEGIN:VFREEBUSY',
		`UID:${uid}`,
		`DTSTAMP:${toIcsUtc(new Date())}`,
		`DTSTART:${toIcsUtc(start)}`,
		`DTEND:${toIcsUtc(end)}`,
		`ORGANIZER:${organizer}`,
		...emails.map(email => `ATTENDEE:mailto:${email}`),
		'END:VFREEBUSY',
		'END:VCALENDAR',
	]
	return lines.join('\r\n') + '\r\n'
}

/**
 * The busy periods in one VFREEBUSY reply.
 *
 * `FREEBUSY;FBTYPE=BUSY:20261116T090000Z/20261116T100000Z,…` carries one or
 * more periods, each `start/end` or `start/duration`. A missing FBTYPE is
 * BUSY (RFC 5545 §3.2.9). Lines are unfolded first, since a long FREEBUSY
 * line arrives folded.
 *
 * @param {string} ics
 * @return {Array<{ start: Date, end: Date, type: string }>}
 */
export function parseFreeBusy(ics) {
	const unfolded = String(ics || '').replace(/\r?\n[ \t]/g, '')
	const periods = []
	for (const line of unfolded.split(/\r?\n/)) {
		const colon = line.indexOf(':')
		if (colon < 0) {
			continue
		}
		const head = line.slice(0, colon)
		const [name, ...params] = head.split(';')
		if (name.toUpperCase() !== 'FREEBUSY') {
			continue
		}
		let type = 'BUSY'
		for (const param of params) {
			const [key, val] = param.split('=')
			if ((key || '').toUpperCase() === 'FBTYPE' && val) {
				type = val.replace(/"/g, '').toUpperCase()
			}
		}
		for (const raw of line.slice(colon + 1).split(',')) {
			const [startRaw, endRaw] = raw.split('/')
			const start = parseIcsDateTime(startRaw)
			if (!start || !endRaw) {
				continue
			}
			let end = parseIcsDateTime(endRaw)
			if (!end) {
				const duration = parseDuration(endRaw)
				end = duration !== null ? new Date(start.getTime() + duration) : null
			}
			if (end && end > start) {
				periods.push({ start, end, type })
			}
		}
	}
	return periods
}

/**
 * The CalDAV `schedule-response` as one row per person.
 *
 * @param {string} xml
 * @param {typeof DOMParser} Parser  the browser's DOMParser
 * @return {Array<{ email: string, ok: boolean, calendarData: string }>}
 */
export function readScheduleResponse(xml, Parser) {
	const doc = new Parser().parseFromString(String(xml || ''), 'application/xml')
	const rows = []
	for (const response of Array.from(doc.getElementsByTagNameNS(CALDAV_NS, 'response'))) {
		const recipient = response.getElementsByTagNameNS(CALDAV_NS, 'recipient')[0]
		const href = recipient?.getElementsByTagNameNS(DAV_NS, 'href')[0]?.textContent || ''
		const status = response.getElementsByTagNameNS(CALDAV_NS, 'request-status')[0]?.textContent || ''
		const data = response.getElementsByTagNameNS(CALDAV_NS, 'calendar-data')[0]?.textContent || ''
		rows.push({
			email: emailKey(href),
			ok: status.trim().startsWith('2.0'),
			calendarData: data,
		})
	}
	return rows
}

/** The key an address is matched on: no `mailto:`, lower case. */
export function emailKey(value) {
	return String(value || '').trim().replace(/^mailto:/i, '').toLowerCase()
}

/**
 * The response rows as `{ [emailKey]: periods }`. A person Nextcloud could
 * not answer for (`3.7;Could not find principal`) is absent, never "free".
 */
export function periodsByEmail(rows) {
	const out = {}
	for (const row of rows) {
		if (row.ok && row.email) {
			out[row.email] = parseFreeBusy(row.calendarData)
		}
	}
	return out
}

/**
 * The people a viewer sees when they first switch busy times on: themselves
 * and whoever follows in the list, up to the cap. The list arrives viewer
 * first, so a small team shows everybody and a big one starts with the
 * viewer and a handful.
 *
 * @param {Array<{ userId: string }>} people
 * @param {number} max
 * @param {string[]} remembered  user ids picked last time, kept when still listed
 * @return {string[]}
 */
export function initialSelection(people, max = MAX_BUSY_PEOPLE, remembered = []) {
	const listed = new Set(people.map(p => p.userId))
	const kept = (remembered || []).filter(id => listed.has(id)).slice(0, max)
	if (kept.length > 0) {
		return kept
	}
	return people.slice(0, max).map(p => p.userId)
}

/** The hours of a day the timeline shows: 07:00 to 20:00, as the room finder. */
export const DAY_OPEN_HOUR = 7
export const DAY_CLOSE_HOUR = 20

/** Local midnight of the day `ms` falls on. */
export function startOfLocalDay(ms) {
	const d = new Date(ms)
	d.setHours(0, 0, 0, 0)
	return d.getTime()
}

function atHour(dayMs, hour) {
	const d = new Date(dayMs)
	d.setHours(hour, 0, 0, 0)
	return d.getTime()
}

function addDays(dayMs, days) {
	const d = new Date(dayMs)
	d.setDate(d.getDate() + days)
	return d.getTime()
}

/** Monday of the week `ms` falls in, local midnight. */
export function mondayOf(ms) {
	const day = startOfLocalDay(ms)
	const weekday = (new Date(day).getDay() + 6) % 7
	return addDays(day, -weekday)
}

/**
 * The stretches of time one row shows. `day`: one day, 07:00 to 20:00.
 * `week`: Monday to Friday of the anchor's week, each 07:00 to 20:00, side
 * by side in equal parts. A work week is the longest view there is
 * (Justin, 2026-11-16).
 *
 * @param {number} anchor ms, any moment on the day (or in the week) to show
 * @param {'day'|'week'} mode
 * @return {Array<{ day: number, start: number, end: number }>}
 */
export function busyWindows(anchor, mode) {
	const first = mode === 'week' ? mondayOf(anchor) : startOfLocalDay(anchor)
	const count = mode === 'week' ? 5 : 1
	const out = []
	for (let i = 0; i < count; i++) {
		const day = addDays(first, i)
		out.push({ day, start: atHour(day, DAY_OPEN_HOUR), end: atHour(day, DAY_CLOSE_HOUR) })
	}
	return out
}

/**
 * The window one free/busy request asks for: whole days around the
 * windows, so a block that starts before 07:00 still arrives and is cut at
 * the edge of the track.
 *
 * @return {{ start: Date, end: Date }}
 */
export function requestRange(windows) {
	const first = windows[0].day
	const last = windows[windows.length - 1].day
	return { start: new Date(first), end: new Date(addDays(last, 1)) }
}

/**
 * Step the anchor. A day steps over Saturday and Sunday, since the view is a
 * working day; a week steps seven days.
 *
 * @param {number} anchor ms
 * @param {'day'|'week'} mode
 * @param {number} direction 1 or -1
 * @return {number} ms
 */
export function shiftAnchor(anchor, mode, direction) {
	const day = startOfLocalDay(anchor)
	if (mode === 'week') {
		return addDays(day, 7 * direction)
	}
	let next = addDays(day, direction)
	while ([0, 6].includes(new Date(next).getDay())) {
		next = addDays(next, direction)
	}
	return next
}

/**
 * Where a moment lands on the track, in percent, or null when it falls
 * outside every window (the night, the weekend).
 */
export function trackOffset(windows, ms) {
	const part = 100 / windows.length
	for (let i = 0; i < windows.length; i++) {
		const w = windows[i]
		if (ms >= w.start && ms <= w.end) {
			return i * part + ((ms - w.start) / (w.end - w.start)) * part
		}
	}
	return null
}

/**
 * One person's busy periods as bars on the track, in percent. A period that
 * runs past 20:00 is cut there and picks up again at 07:00 the next day; the
 * night between is not drawn. Bars keep the clipped times for their title.
 *
 * @param {Array<{ start: Date, end: Date, type: string }>} periods
 * @param {Array<{ start: number, end: number }>} windows
 * @return {Array<{ offset: number, size: number, type: string, start: number, end: number }>}
 */
export function trackBars(periods, windows) {
	const part = 100 / windows.length
	const bars = []
	for (const period of periods || []) {
		if (!SHOWN_TYPES.includes(period.type)) {
			continue
		}
		const ps = period.start.getTime()
		const pe = period.end.getTime()
		windows.forEach((w, i) => {
			const s = Math.max(ps, w.start)
			const e = Math.min(pe, w.end)
			if (e <= s) {
				return
			}
			const span = w.end - w.start
			bars.push({
				offset: i * part + ((s - w.start) / span) * part,
				size: ((e - s) / span) * part,
				type: period.type,
				start: s,
				end: e,
			})
		})
	}
	return bars.sort((a, b) => a.offset - b.offset)
}

/**
 * The scale's marks. Every whole hour gets a faint line; in a day, every
 * second hour is labelled, as in the room finder. In a week the hours stay
 * unlabelled and each day gets a stronger line at its start and its name
 * over its part.
 *
 * @return {{ hours: Array<{ offset: number, label: string }>, days: Array<{ offset: number, size: number, day: number }> }}
 */
export function trackMarks(windows) {
	const part = 100 / windows.length
	const hours = []
	const days = []
	windows.forEach((w, i) => {
		days.push({ offset: i * part, size: part, day: w.day })
		for (let h = DAY_OPEN_HOUR + 1; h < DAY_CLOSE_HOUR; h++) {
			const at = atHour(w.day, h)
			hours.push({
				offset: i * part + ((at - w.start) / (w.end - w.start)) * part,
				label: windows.length === 1 && h % 2 === 0 ? `${String(h).padStart(2, '0')}:00` : '',
			})
		}
	})
	return { hours, days }
}

/** True when today is one of the days shown, for the Today button and the now line. */
export function containsToday(windows, now = Date.now()) {
	const today = startOfLocalDay(now)
	return windows.some(w => w.day === today)
}
