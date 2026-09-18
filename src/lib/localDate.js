/**
 * Date helpers that are explicit about which of the app's two date kinds
 * they handle, and about whose locale and zone they render in. Mixing any of
 * those up is the bug this module exists to prevent.
 *
 * ── The two kinds ────────────────────────────────────────────────────────
 *
 * 1. INSTANTS — `created_at`, `updated_at`, message and comment times.
 *    Stored as unix seconds; an absolute moment. Render with
 *    `formatDateTime()` / `formatDate()` / `formatTime()`, which convert to
 *    the viewer's zone.
 *
 * 2. FLOATING DATES — a calendar day with no time and no zone: the date a
 *    milestone falls on, the day an expense was incurred, the day someone
 *    logged time, a project's start date, a presence slot. "11 August" is
 *    the same 11 August in Amsterdam and in Denver.
 *
 *    The app stores floating dates two ways, both fine:
 *      - ISO `YYYY-MM-DD` strings (`slot_date`, `holiday_date`, milestone
 *        `date` over the wire), and
 *      - unix seconds snapped to UTC midnight (`workedAt`, `incurredAt`,
 *        project `startDate` / `targetEnd`). TimeService::normalizeWorkedAt
 *        does the snapping server-side.
 *
 *    Render these with `formatIsoDate()` / `formatEpochDate()`, which read
 *    the value back through UTC so no zone can move the day.
 *
 * ── The two mistakes ─────────────────────────────────────────────────────
 *
 * A. Deriving a floating date from *now* through UTC:
 *      new Date().toISOString().slice(0, 10)
 *    `toISOString` converts to UTC first, so this names the wrong day
 *    whenever the viewer's offset has already rolled them over — for
 *    Europe/Amsterdam that is every day between 00:00 and 02:00, and for
 *    anywhere west of Greenwich it is the whole evening. Use `todayIso()`.
 *
 * B. Rendering a UTC-midnight floating date through a *zoned* formatter:
 *      new Intl.DateTimeFormat(locale, opts).format(new Date(ts * 1000))
 *    2026-08-11T00:00Z is 2026-08-10 in Denver, so the expense moves to the
 *    previous day. Floating dates must be read back through UTC —
 *    `formatEpochDate()` does that.
 *
 * The inverse pair `epochDateToIso` / `isoToEpochDate` is deliberately UTC
 * on both sides. Those are NOT instances of mistake A: they round-trip a
 * value that was stored at UTC midnight, and going through zoned getters
 * would be the bug.
 *
 * ── Whose locale, whose zone (v4.7.2) ────────────────────────────────────
 *
 * Nextcloud has two separate user settings and renders them as two separate
 * attributes on <html>: `lang` is the UI *language*, `data-locale` is the
 * date/number *format*. They diverge constantly — an English UI with Dutch
 * date format is the ordinary European setup — and it is `data-locale` that
 * governs how a date is written. `getCanonicalLocale()` reads it.
 *
 * The zone is the user's `core`/`timezone` preference, which appears nowhere
 * in the DOM; `DateContextService` publishes it as initial state. Nextcloud
 * writes that preference from the browser on login, so it normally agrees
 * with the browser anyway — the browser is the fallback when it does not
 * resolve, which covers a public page or an account that has never signed in.
 *
 * This supersedes §2.92's "the browser owns today". The browser is now the
 * fallback rather than the authority, so that one answer to "what zone is
 * this reader in" serves both rendering and `todayIso()`. Splitting them
 * would let a reader see timestamps in one zone and "today" in another.
 */

import { getCanonicalLocale } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

const pad = (v) => String(v).padStart(2, '0')

/**
 * Option sets that reproduce the bare `toLocaleString()` family exactly.
 * Spelled out because `Intl.DateTimeFormat` with no component options
 * defaults to date-only, which is NOT what `toLocaleString()` does — the
 * sweep that introduced these helpers would otherwise have silently dropped
 * the time from every timestamp in the app.
 */
const DATETIME_DEFAULT = { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' }
const DATE_DEFAULT = { year: 'numeric', month: 'numeric', day: 'numeric' }
const TIME_DEFAULT = { hour: 'numeric', minute: 'numeric', second: 'numeric' }

/** The medium date used wherever the app shows a date without a time. */
const DATE_MED = { year: 'numeric', month: 'short', day: 'numeric' }

let cachedZone

/**
 * The viewer's locale, canonical form (`nl-NL`). Read fresh rather than
 * cached: `@nextcloud/l10n` already resolves this from a module-level value.
 */
export function locale() {
	try {
		return getCanonicalLocale()
	} catch (e) {
		return undefined
	}
}

/**
 * The viewer's IANA timezone, from their Nextcloud preference, falling back
 * to the browser's own zone. Memoised — it cannot change within a page load.
 */
export function timeZone() {
	if (cachedZone === undefined) {
		let zone = ''
		try {
			zone = loadState('teamhub', 'dateContext', null)?.timezone || ''
		} catch (e) {
			zone = ''
		}
		if (!zone) {
			try {
				zone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
			} catch (e) {
				zone = 'UTC'
			}
		}
		cachedZone = zone
	}
	return cachedZone
}

/** Test seam — drops the memoised zone so the next call re-reads it. */
export function resetDateContextCache() {
	cachedZone = undefined
}

/**
 * Anything date-like → a `Date`. Accepts a `Date`, milliseconds, or a string
 * the platform can parse (ISO 8601 with an offset, as the API emits).
 *
 * Deliberately does NOT accept unix *seconds*: seconds and milliseconds are
 * indistinguishable at runtime and guessing wrong puts the value in 1970 or
 * the year 55000. Call sites keep their explicit `new Date(secs * 1000)`.
 */
function toDate(value) {
	if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value
	if (value === null || value === undefined || value === '') return null
	const d = new Date(value)
	return Number.isNaN(d.getTime()) ? null : d
}

/** Format a `Date` in the viewer's locale and zone. */
function render(d, opts, zone) {
	try {
		return new Intl.DateTimeFormat(locale(), { ...opts, timeZone: zone }).format(d)
	} catch (e) {
		// A bad zone name or an exotic option set must not blank the UI.
		try {
			return new Intl.DateTimeFormat(undefined, opts).format(d)
		} catch (e2) {
			return d.toISOString().slice(0, 10)
		}
	}
}

/**
 * The zone's UTC offset in ms at a given instant.
 * Formats the instant in the zone, reads the wall-clock parts back, and asks
 * what UTC instant those parts would name. The difference is the offset.
 */
function zoneOffsetMs(utcMs, zone) {
	const parts = {}
	const dtf = new Intl.DateTimeFormat('en-US', {
		timeZone: zone,
		hour12: false,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
	})
	for (const { type, value } of dtf.formatToParts(new Date(utcMs))) {
		parts[type] = value
	}
	// Some engines render midnight as hour 24 rather than 0.
	const hour = parts.hour === '24' ? 0 : Number(parts.hour)
	const asUtc = Date.UTC(
		Number(parts.year), Number(parts.month) - 1, Number(parts.day),
		hour, Number(parts.minute), Number(parts.second),
	)
	return asUtc - utcMs
}

/** `YYYY-MM-DD` as the given zone sees the given instant. */
function isoInZone(d, zone) {
	const parts = {}
	const dtf = new Intl.DateTimeFormat('en-US', {
		timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
	})
	for (const { type, value } of dtf.formatToParts(d)) {
		parts[type] = value
	}
	return `${parts.year}-${parts.month}-${parts.day}`
}

// ── Instants ────────────────────────────────────────────────────────────

/**
 * An instant → date and time, the replacement for `.toLocaleString()`.
 * @param {Date|number|string} value  a Date, milliseconds, or a parseable string
 * @param {object} opts  Intl.DateTimeFormat options
 */
export function formatDateTime(value, opts = DATETIME_DEFAULT) {
	const d = toDate(value)
	if (!d) return ''
	return render(d, opts, timeZone())
}

/** An instant → date only, the replacement for `.toLocaleDateString()`. */
export function formatDate(value, opts = DATE_DEFAULT) {
	const d = toDate(value)
	if (!d) return ''
	return render(d, opts, timeZone())
}

/** An instant → time only, the replacement for `.toLocaleTimeString()`. */
export function formatTime(value, opts = TIME_DEFAULT) {
	const d = toDate(value)
	if (!d) return ''
	return render(d, opts, timeZone())
}

/**
 * An instant → the `YYYY-MM-DD` the viewer's calendar puts it on.
 *
 * For deciding whether two instants fall on the same day, which is a
 * question about the reader's calendar and not the browser's. Replaces
 * `a.toDateString() === b.toDateString()`, which asks the browser.
 */
export function zonedIsoDate(value) {
	const d = toDate(value)
	if (!d) return ''
	return isoInZone(d, timeZone())
}

// ── "Today" and calendar arithmetic ─────────────────────────────────────

/**
 * Today as the viewer's calendar sees it, `YYYY-MM-DD`.
 * The replacement for `new Date().toISOString().slice(0, 10)`.
 */
export function todayIso(now = new Date()) {
	return isoInZone(now, timeZone())
}

/**
 * A `Date` → `YYYY-MM-DD`, read off local getters.
 * Use when you have built a date by local arithmetic and want it as a string;
 * `shiftIsoDate` is built on it. Not for rendering an instant — that is
 * `formatDate`.
 */
export function toIsoDate(d) {
	if (!(d instanceof Date) || Number.isNaN(d.getTime())) return ''
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * `YYYY-MM-DD` → a local `Date` at midnight, for calendar arithmetic.
 * `new Date('2026-08-11')` would parse as UTC midnight and then read back as
 * the 10th west of Greenwich; this keeps the day intact everywhere.
 */
export function isoToLocalDate(iso) {
	if (!iso) return null
	const [y, m, d] = String(iso).split('-').map(Number)
	if (!y || !m || !d) return null
	return new Date(y, m - 1, d)
}

/**
 * Calendar arithmetic on an ISO date, string in and string out.
 *
 * Never builds a Date from the string, so there is no zone to get wrong and
 * no DST edge where "+1 day" is 23 or 25 hours. Month shifts clamp to the
 * end of the target month, matching what a person means by "six months from
 * 31 August" (28/29 February, not 2/3 March).
 *
 * @param {string} iso    `YYYY-MM-DD`
 * @param {{days?: number, months?: number}} delta
 * @return {string} `YYYY-MM-DD`, or '' if the input was not a date.
 */
export function shiftIsoDate(iso, { days = 0, months = 0 } = {}) {
	const base = isoToLocalDate(iso)
	if (!base) return ''
	if (months) {
		const targetMonth = base.getMonth() + months
		const anchorDay = base.getDate()
		base.setDate(1)
		base.setMonth(targetMonth)
		// Clamp: 31 Jan + 1 month is 28/29 Feb, not 2/3 Mar.
		const lastDay = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate()
		base.setDate(Math.min(anchorDay, lastDay))
	}
	if (days) {
		base.setDate(base.getDate() + days)
	}
	return toIsoDate(base)
}

/** Today shifted by a calendar delta, `YYYY-MM-DD`. */
export function shiftToday(delta) {
	return shiftIsoDate(todayIso(), delta)
}

// ── Floating dates ──────────────────────────────────────────────────────

/**
 * UTC-midnight unix seconds → `YYYY-MM-DD`.
 * The stored-floating-date round trip; UTC on purpose. See the header.
 */
export function epochDateToIso(secs) {
	if (!secs || !Number.isFinite(secs)) return ''
	return new Date(secs * 1000).toISOString().slice(0, 10)
}

/**
 * `YYYY-MM-DD` → UTC-midnight unix seconds. Inverse of epochDateToIso.
 */
export function isoToEpochDate(iso) {
	if (!iso) return null
	const parsed = Date.parse(`${iso}T00:00:00Z`)
	return Number.isFinite(parsed) ? Math.floor(parsed / 1000) : null
}

/**
 * Display a UTC-midnight floating date in the viewer's locale, without
 * letting any zone move it a day. Fixes mistake B in the header.
 *
 * @param {number} secs  unix seconds, snapped to UTC midnight
 * @param {object} opts  Intl.DateTimeFormat options
 */
export function formatEpochDate(secs, opts = DATE_MED) {
	if (!secs || !Number.isFinite(secs)) return ''
	// timeZone: 'UTC' is the whole point — the value is a calendar day that
	// happens to be encoded at UTC midnight, not a moment in time.
	const out = render(new Date(secs * 1000), opts, 'UTC')
	return out || epochDateToIso(secs)
}

/**
 * Display an ISO `YYYY-MM-DD` floating date in the viewer's locale.
 *
 * Parsed to UTC midnight and rendered through UTC, the same treatment
 * `formatEpochDate` gives the other floating-date encoding — so the calendar
 * day survives regardless of the viewer's zone or the browser's.
 */
export function formatIsoDate(iso, opts = DATE_MED) {
	if (!iso) return ''
	const ms = Date.parse(`${iso}T00:00:00Z`)
	if (!Number.isFinite(ms)) return iso
	const out = render(new Date(ms), opts, 'UTC')
	return out || iso
}

// ── Date-input bounds over a range of instants ──────────────────────────

/**
 * Instant (unix seconds) → the `YYYY-MM-DD` an `<input type="date">` wants,
 * read in the viewer's zone.
 *
 * This pair is for date pickers that bound a range of *instants* — the feed's
 * from/to filter, where "from 11 August" means from midnight on the 11th
 * where the reader is. Not interchangeable with epochDateToIso /
 * isoToEpochDate, which round-trip a stored floating date and are UTC on
 * both sides.
 *
 * Originally `toDateInput` in FeedControlRail.vue, which was the only place
 * in the app that had this right.
 */
export function toDateInput(secs) {
	if (!secs) return ''
	return zonedIsoDate(new Date(secs * 1000))
}

/**
 * `YYYY-MM-DD` → unix seconds at midnight *in the viewer's zone*.
 * Inverse of toDateInput.
 *
 * Two passes: the zone's offset at the UTC guess can differ from its offset
 * at the answer when the date sits on a DST boundary, so the corrected
 * instant is re-measured before it is returned.
 */
export function fromDateInput(str) {
	if (!str) return 0
	const guess = Date.parse(`${String(str)}T00:00:00Z`)
	if (!Number.isFinite(guess)) return 0
	const zone = timeZone()
	try {
		const firstPass = guess - zoneOffsetMs(guess, zone)
		const ms = guess - zoneOffsetMs(firstPass, zone)
		return Math.floor(ms / 1000)
	} catch (e) {
		const d = isoToLocalDate(str)
		return d ? Math.floor(d.getTime() / 1000) : 0
	}
}
