/**
 * Room finder logic (v4.20.0): which rooms to show, in what order, and how
 * to draw each room's day. Pure functions, no Vue and no strings, so the
 * rules are unit-tested in tests/js/roomFinder.test.mjs and the component
 * only renders what comes back.
 *
 * A room here is a `/rooms` catalogue entry ({ key, displayName, building,
 * floor, capacity, … }); an availability entry is what `/rooms/availability`
 * answers per key ({ status: 'free' | 'busy' | 'unknown', busy: [{ start, end }] }).
 */

/** The building filter value for rooms that publish no building. */
export const UNPLACED = '\u0000unplaced'

/**
 * The building choices, alphabetically, with the unplaced bucket last when
 * any room has no building.
 *
 * @param {Array<object>} rooms catalogue entries
 * @return {{ buildings: string[], hasUnplaced: boolean }}
 */
export function buildingChoices(rooms) {
	const names = new Set()
	let hasUnplaced = false
	for (const room of rooms || []) {
		const name = (room.building || '').trim()
		if (name) {
			names.add(name)
		} else {
			hasUnplaced = true
		}
	}
	const buildings = [...names].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }))
	return { buildings, hasUnplaced }
}

/**
 * The building choices as select options, the unplaced bucket last under
 * the label the caller translated.
 *
 * @param {Array<object>} rooms catalogue entries
 * @param {string} otherLabel label for rooms without a building
 * @return {Array<{ id: string, label: string }>}
 */
export function buildingOptionList(rooms, otherLabel) {
	const { buildings, hasUnplaced } = buildingChoices(rooms)
	const options = buildings.map(name => ({ id: name, label: name }))
	if (hasUnplaced) options.push({ id: UNPLACED, label: otherLabel })
	return options
}

/**
 * The building to open on: the preferred one (the presence suggestion's
 * best building) when it exists, then the one the user last picked, then the
 * only choice there is. Otherwise none, and the finder asks.
 *
 * @param {Array<object>} rooms catalogue entries
 * @param {string} preferred building name or ''
 * @param {string} remembered building name or ''
 * @return {string} a building name, UNPLACED, or ''
 */
export function initialBuilding(rooms, preferred = '', remembered = '') {
	const { buildings, hasUnplaced } = buildingChoices(rooms)
	if (preferred && buildings.includes(preferred)) return preferred
	if (remembered && (buildings.includes(remembered) || (remembered === UNPLACED && hasUnplaced))) return remembered
	if (buildings.length === 1 && !hasUnplaced) return buildings[0]
	if (buildings.length === 0 && hasUnplaced) return UNPLACED
	return ''
}

/**
 * The rooms in one building, optionally narrowed by a search over the name,
 * floor and room number.
 *
 * @param {Array<object>} rooms catalogue entries
 * @param {string} building a building name or UNPLACED
 * @param {string} query free text
 * @return {Array<object>}
 */
export function roomsInBuilding(rooms, building, query = '') {
	const needle = (query || '').trim().toLocaleLowerCase()
	return (rooms || []).filter(room => {
		const name = (room.building || '').trim()
		const inBuilding = building === UNPLACED ? name === '' : name === building
		if (!inBuilding) return false
		if (!needle) return true
		return [room.displayName, room.floor, room.roomNumber]
			.some(v => (v || '').toLocaleLowerCase().includes(needle))
	})
}

/**
 * Sort and split the rooms of one building for the finder.
 *
 *   - `available`: big enough (or capacity not published) and free, then
 *     those whose availability is unknown. Rooms that fit with the fewest
 *     spare seats come first, because a twelve-seat room for three people is
 *     a room somebody else needed. Rooms with no published capacity follow
 *     the ones that have one.
 *   - `booked`: big enough, but taken in the slot.
 *   - `tooSmall`: publishes a capacity below the people needed, whatever
 *     its availability.
 *   - `bestKey`: the first free room with a published capacity that fits,
 *     or null.
 *
 * Unknown is never counted as free: an unknown room sorts after every free
 * one and is never the best fit. Neither is `partial` (v4.20.8): a room free
 * for the first meeting of a series but taken on a later date.
 *
 * @param {Array<object>} rooms catalogue entries (one building)
 * @param {object} availability key → { status, busy }
 * @param {number} people seats needed
 * @return {{ available: Array<object>, booked: Array<object>, tooSmall: Array<object>, bestKey: string|null, freeCount: number }}
 */
export function classifyRooms(rooms, availability, people) {
	const need = Math.max(1, Number(people) || 1)
	const available = []
	const booked = []
	const tooSmall = []

	for (const room of rooms || []) {
		const capacity = Number.isInteger(room.capacity) && room.capacity > 0 ? room.capacity : null
		if (capacity !== null && capacity < need) {
			tooSmall.push(room)
			continue
		}
		const status = availability?.[room.key]?.status || 'unknown'
		if (status === 'busy') {
			booked.push(room)
		} else {
			available.push(room)
		}
	}

	const rank = room => {
		const status = availability?.[room.key]?.status || 'unknown'
		const capacity = Number.isInteger(room.capacity) && room.capacity > 0 ? room.capacity : null
		// v4.20.8 — `partial` (free now, taken on a later date of a recurring
		// meeting) sorts after free and before unknown.
		return [
			status === 'free' ? 0 : (status === 'partial' ? 1 : 2),
			capacity === null ? 1 : 0,
			capacity === null ? 0 : capacity - need,
		]
	}
	const byRank = (a, b) => {
		const ra = rank(a)
		const rb = rank(b)
		for (let i = 0; i < ra.length; i++) {
			if (ra[i] !== rb[i]) return ra[i] - rb[i]
		}
		return byName(a, b)
	}
	available.sort(byRank)
	booked.sort(byName)
	tooSmall.sort((a, b) => (b.capacity - a.capacity) || byName(a, b))

	const best = available.find(room =>
		(availability?.[room.key]?.status === 'free') && Number.isInteger(room.capacity) && room.capacity > 0)

	return {
		available,
		booked,
		tooSmall,
		bestKey: best ? best.key : null,
		freeCount: available.filter(room => availability?.[room.key]?.status === 'free').length,
	}
}

function byName(a, b) {
	return (a.displayName || '').localeCompare(b.displayName || '', undefined, { sensitivity: 'base', numeric: true })
}

/**
 * Group rooms by floor, keeping the order they came in within each floor and
 * ordering floors by where their first room appeared. A building with one
 * floor, or none published, comes back as a single group with an empty name,
 * and the finder then draws no floor heading.
 *
 * @param {Array<object>} rooms already sorted
 * @return {Array<{ floor: string, rooms: Array<object> }>}
 */
export function groupByFloor(rooms) {
	const groups = new Map()
	for (const room of rooms || []) {
		const floor = (room.floor || '').trim()
		if (!groups.has(floor)) groups.set(floor, [])
		groups.get(floor).push(room)
	}
	const out = [...groups.entries()].map(([floor, list]) => ({ floor, rooms: list }))
	if (out.length === 1) out[0].floor = ''
	return out
}

/**
 * When a booked room is free again: the end of the busy run that covers the
 * slot, following blocks that start exactly where the previous one ended.
 * Null when the run lasts to the end of the window (booked for the rest of
 * the day, as far as the finder can see) or the room is not booked in the slot.
 *
 * @param {Array<{start: string, end: string}>} busy blocks, ISO instants
 * @param {number} slotStart ms
 * @param {number} slotEnd ms
 * @param {number} windowEnd ms
 * @return {number|null} ms
 */
export function freeAgainAt(busy, slotStart, slotEnd, windowEnd) {
	const blocks = (busy || [])
		.map(b => [Date.parse(b.start), Date.parse(b.end)])
		.filter(([s, e]) => Number.isFinite(s) && Number.isFinite(e) && e > s)
		.sort((a, b) => a[0] - b[0])
	let runEnd = null
	for (const [s, e] of blocks) {
		if (runEnd === null) {
			if (s < slotEnd && e > slotStart) runEnd = e
			continue
		}
		if (s <= runEnd) {
			runEnd = Math.max(runEnd, e)
		} else {
			break
		}
	}
	if (runEnd === null || runEnd >= windowEnd) return null
	return runEnd
}

/**
 * Where to draw each busy block and the slot on a room's day line, as
 * percentages of the window. Blocks are clipped to the window.
 *
 * @param {Array<{start: string, end: string}>} busy blocks, ISO instants
 * @param {number} windowStart ms
 * @param {number} windowEnd ms
 * @return {Array<{ offset: number, size: number }>}
 */
export function timelineSegments(busy, windowStart, windowEnd) {
	const span = windowEnd - windowStart
	if (!(span > 0)) return []
	const out = []
	for (const block of busy || []) {
		const s = Math.max(Date.parse(block.start), windowStart)
		const e = Math.min(Date.parse(block.end), windowEnd)
		if (!Number.isFinite(s) || !Number.isFinite(e) || e <= s) continue
		out.push({ offset: ((s - windowStart) / span) * 100, size: ((e - s) / span) * 100 })
	}
	return out
}

/**
 * The finder's day window around a slot, in the browser's local time: 07:00
 * to 20:00 on the meeting's day, widened to whole hours around the slot when
 * the meeting starts earlier or ends later. Local, because the date and time
 * fields it is computed from are local too.
 *
 * @param {string} date YYYY-MM-DD
 * @param {string} startTime HH:MM
 * @param {string} endTime HH:MM
 * @return {{ slotStart: number, slotEnd: number, windowStart: number, windowEnd: number }|null}
 */
export function slotWindow(date, startTime, endTime) {
	if (!/^\d{4}-\d{2}-\d{2}$/.test(date || '') || !/^\d{2}:\d{2}$/.test(startTime || '') || !/^\d{2}:\d{2}$/.test(endTime || '')) {
		return null
	}
	const slotStart = new Date(`${date}T${startTime}:00`).getTime()
	const slotEnd = new Date(`${date}T${endTime}:00`).getTime()
	if (!Number.isFinite(slotStart) || !Number.isFinite(slotEnd) || slotEnd <= slotStart) return null

	const dayOpen = new Date(`${date}T07:00:00`)
	const dayClose = new Date(`${date}T20:00:00`)
	const open = new Date(Math.min(dayOpen.getTime(), slotStart))
	open.setMinutes(0, 0, 0)
	const close = new Date(Math.max(dayClose.getTime(), slotEnd))
	if (close.getMinutes() || close.getSeconds()) {
		close.setHours(close.getHours() + 1, 0, 0, 0)
	}
	return { slotStart, slotEnd, windowStart: open.getTime(), windowEnd: close.getTime() }
}

/**
 * The floors published in a list of rooms, in natural order ("2" before
 * "10"), without blanks.
 *
 * @param {Array<object>} rooms catalogue entries
 * @return {string[]}
 */
export function floorChoices(rooms) {
	const floors = new Set()
	for (const room of rooms || []) {
		const floor = (room.floor || '').trim()
		if (floor) floors.add(floor)
	}
	return [...floors].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }))
}

/**
 * The rooms on one floor; '' keeps every floor.
 *
 * @param {Array<object>} rooms catalogue entries
 * @param {string} floor floor name or ''
 * @return {Array<object>}
 */
export function roomsOnFloor(rooms, floor) {
	if (!floor) return rooms || []
	return (rooms || []).filter(room => (room.floor || '').trim() === floor)
}

// v4.20.19 — the equipment filter on Add meeting's room finder. The codes are
// Nextcloud's room metadata (`{http://nextcloud.com/ns}room-features`); the
// common ones come first in this order, anything else after them by name.
const FEATURE_ORDER = ['VIDEO-CONFERENCING', 'PROJECTOR', 'TV', 'WHITEBOARD', 'PHONE', 'WHEELCHAIR-ACCESSIBLE']

/**
 * The equipment the given rooms offer between them, each code once. Only
 * what at least one room has: a filter for equipment no room has would
 * only ever empty the list.
 *
 * @param {Array<object>} rooms catalogue entries
 * @return {string[]}
 */
export function featureChoices(rooms) {
	const codes = new Set()
	for (const room of rooms || []) {
		for (const code of room.features || []) {
			const c = String(code || '').trim().toUpperCase()
			if (c) codes.add(c)
		}
	}
	const rank = c => {
		const i = FEATURE_ORDER.indexOf(c)
		return i === -1 ? FEATURE_ORDER.length : i
	}
	return [...codes].sort((a, b) => (rank(a) - rank(b)) || a.localeCompare(b))
}

/**
 * The rooms that have every piece of equipment asked for; none asked keeps
 * every room. A room that publishes no equipment has none of it.
 *
 * @param {Array<object>} rooms catalogue entries
 * @param {string[]} features codes from featureChoices()
 * @return {Array<object>}
 */
export function roomsWithFeatures(rooms, features) {
	const wanted = (features || []).map(f => String(f).toUpperCase())
	if (wanted.length === 0) return rooms || []
	return (rooms || []).filter(room => {
		const has = new Set((room.features || []).map(f => String(f || '').trim().toUpperCase()))
		return wanted.every(f => has.has(f))
	})
}

/**
 * Add event's short list (v4.20.19): the picked room, then the best rooms on
 * offer, `max` in all. Add event is the free form, so it shows a few good
 * rooms and no full list.
 *
 * @param {{ available: Array<object> }} groups classifyRooms() result
 * @param {object|null} picked the room in the form, or null
 * @param {number} max rooms to show
 * @return {Array<object>}
 */
export function shortList(groups, picked, max = 3) {
	const rest = (groups?.available || []).filter(room => !picked || room.key !== picked.key)
	return [...(picked ? [picked] : []), ...rest].slice(0, Math.max(0, max))
}

/**
 * The hour lines on a room's day line: one per whole local hour strictly
 * inside the window, as a percentage, with every second hour labelled so the
 * scale reads without crowding.
 *
 * @param {number} windowStart ms
 * @param {number} windowEnd ms
 * @return {Array<{ offset: number, label: string }>} label is '' for an unlabelled line
 */
export function hourMarks(windowStart, windowEnd) {
	const span = windowEnd - windowStart
	if (!(span > 0)) return []
	const marks = []
	const cursor = new Date(windowStart)
	cursor.setMinutes(0, 0, 0)
	cursor.setHours(cursor.getHours() + 1)
	while (cursor.getTime() < windowEnd) {
		const at = cursor.getTime()
		marks.push({
			offset: ((at - windowStart) / span) * 100,
			label: cursor.getHours() % 2 === 0 ? clockTime(at) : '',
		})
		cursor.setHours(cursor.getHours() + 1)
	}
	return marks
}

/**
 * How long a room that is free in the slot stays free: the start of its next
 * booking after the slot, or null when nothing follows before the end of the
 * window (free for the rest of the day, as far as the finder can see).
 *
 * @param {Array<{start: string, end: string}>} busy blocks, ISO instants
 * @param {number} slotEnd ms
 * @param {number} windowEnd ms
 * @return {number|null} ms
 */
export function freeUntil(busy, slotEnd, windowEnd) {
	let next = null
	for (const block of busy || []) {
		const s = Date.parse(block.start)
		if (!Number.isFinite(s) || s < slotEnd || s >= windowEnd) continue
		if (next === null || s < next) next = s
	}
	return next
}

/**
 * Where "now" falls on the day line, or null when now is outside the window.
 *
 * @param {number} windowStart ms
 * @param {number} windowEnd ms
 * @param {number} now ms
 * @return {number|null} percentage
 */
export function nowOffset(windowStart, windowEnd, now = Date.now()) {
	if (!(windowEnd > windowStart) || now <= windowStart || now >= windowEnd) return null
	return ((now - windowStart) / (windowEnd - windowStart)) * 100
}

/**
 * HH:MM in the browser's local time, matching the time fields the slot was
 * typed into.
 *
 * @param {number} ms
 * @return {string}
 */
export function clockTime(ms) {
	const d = new Date(ms)
	const pad = v => String(v).padStart(2, '0')
	return `${pad(d.getHours())}:${pad(d.getMinutes())}`
}
