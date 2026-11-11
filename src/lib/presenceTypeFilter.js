/**
 * The status filter on the team presence grid (v4.13.63, rescoped v4.13.64).
 *
 * ## Why this is a module and not a few computeds
 *
 * Both functions turn on one distinction that is easy to get wrong, and is
 * invisible in a template: **the raw server cell versus the cell the grid
 * paints.** `TeamPresenceView.slotFor()` never returns null — it fabricates a
 * cell for every coordinate so every square has a colour and a label, falling
 * back to *Not working*. Filtering through it matches everybody, because
 * everybody has a cell. The honest read is `slots[uid]['<date>_<half>']`, which
 * is absent where the member has scheduled nothing.
 *
 * So these take the slot map directly, and `tests/js/presenceTypeFilter.test.mjs`
 * pins exactly that: a member with no schedule is not "in the office".
 *
 * ## Why the options are derived rather than fetched
 *
 * `GET /api/v1/presence/types` is instance-wide and administrator-editable, so
 * it offers types nobody in this team has, and costs a request to say so. The
 * grid response already carries a label and a colour on every cell.
 *
 * With the team's `hide_reasons` setting on, the server sends `slug: null` for
 * every cell (`PresenceTeamService::serializeSlotCell()`), so there is nothing
 * to filter by and these return an empty option list. The caller hides the
 * control rather than offering an empty dropdown — once the reasons are hidden,
 * a filter over them is not a feature that can exist.
 */

/** The key `PresenceTeamService` serialises a half-day under. */
function cellKey(date, half) {
	return `${date}_${half}`
}

/**
 * The server's cell for one coordinate, or null.
 *
 * @param {object} slots the grid response's `slots` map, keyed by user id
 * @param {string} userId whose row
 * @param {string} date ISO date
 * @param {number} half 0 for the morning, 1 for the afternoon
 * @return {object|null}
 */
export function rawSlot(slots, userId, date, half) {
	return slots?.[userId]?.[cellKey(date, half)] || null
}

/**
 * The status types present in the visible window, as filter options.
 *
 * A type carried in `selected` is kept on the list even when the window holds
 * none of it, so its chip stays a valid option after the user pages to another
 * week. Without that, navigating would silently drop the selection.
 *
 * @param {Array<{userId: string}>} members rows on screen
 * @param {object} slots the grid response's `slots` map
 * @param {Array<{iso: string}>} days the visible dates
 * @param {Array<{id: string, label: string}>} selected currently chosen options
 * @return {Array<{id: string, label: string, color: ?string}>} sorted by label
 */
export function presenceTypeOptions(members, slots, days, selected = []) {
	const seen = new Map()

	for (const member of members || []) {
		for (const day of days || []) {
			for (const half of [0, 1]) {
				const raw = rawSlot(slots, member.userId, day.iso, half)
				// Both are null under hide_reasons, which is what empties the list.
				if (!raw || !raw.slug || !raw.label) continue
				if (!seen.has(raw.slug)) {
					seen.set(raw.slug, { id: raw.slug, label: raw.label, color: raw.color || null })
				}
			}
		}
	}

	for (const option of selected || []) {
		if (option?.id && !seen.has(option.id)) {
			seen.set(option.id, option)
		}
	}

	return [...seen.values()].sort((a, b) => String(a.label).localeCompare(String(b.label)))
}

/**
 * The half-days a "part of day" choice covers.
 *
 * @param {string} part 'morning' | 'afternoon' | anything else for the whole day
 * @return {number[]}
 */
export function halvesFor(part) {
	if (part === 'morning') return [0]
	if (part === 'afternoon') return [1]
	return [0, 1]
}

/**
 * Does this member hold one of `wanted` on this date, in these halves?
 *
 * @param {object} slots the grid response's `slots` map
 * @param {string} userId whose row
 * @param {string} date ISO date
 * @param {number[]} halves which halves of that date count
 * @param {Set<string>} wanted the slugs being looked for
 * @return {boolean}
 */
function holdsStatus(slots, userId, date, halves, wanted) {
	return halves.some(half => {
		const raw = rawSlot(slots, userId, date, half)
		return !!raw?.slug && wanted.has(raw.slug)
	})
}

/**
 * The members holding a chosen status **on one date**, in the chosen halves.
 *
 * ## Why a date and not the week (v4.13.64)
 *
 * The first version of this filter asked whether a member held the status
 * anywhere in the visible week. On a real team that matches almost everybody —
 * nearly everyone is in the office *at some point* in five days — so the
 * filter hid nobody and answered nothing. Justin's report. The question people
 * actually have is "who is in on Thursday", and the morning and the afternoon
 * are different answers, which is why the halves are a parameter.
 *
 * An empty `slugs` means no filter and returns the list untouched — the same
 * array, so the caller's `v-for` keys do not churn.
 *
 * @param {Array<{userId: string}>} members rows on screen
 * @param {object} slots the grid response's `slots` map
 * @param {?string} date the ISO date in scope
 * @param {number[]} halves which halves of it count
 * @param {string[]} slugs chosen type slugs
 * @return {Array<{userId: string}>}
 */
export function filterMembersByStatus(members, slots, date, halves, slugs) {
	if (!slugs || slugs.length === 0) return members || []
	if (!date) return []

	const wanted = new Set(slugs)

	return (members || []).filter(
		member => holdsStatus(slots, member.userId, date, halves, wanted),
	)
}

/**
 * How many members hold **each** status on one date.
 *
 * The day column's own summary, and it is not conditional on a filter being
 * set (v4.13.65): the numbers are the overview — *Thursday has four in the
 * office and three at home* — which is the question somebody has before they
 * have chosen anything to filter by. Earlier versions only drew them once a
 * status was selected, so the overview was hidden behind the detail view.
 *
 * Counted over every member, never the filtered rows.
 *
 * A member who is in the office in the morning and at home in the afternoon
 * counts once in **each** status for an all-day window. That is the honest
 * answer to "how many will be in the office that day", so the totals across
 * statuses can exceed the member count, and the labels say which day part they
 * cover.
 *
 * @param {Array<{userId: string}>} members every member of the team
 * @param {object} slots the grid response's `slots` map
 * @param {string} date ISO date
 * @param {number[]} halves which halves of it count
 * @param {Array<{id: string, label: string, color: ?string}>} types the statuses to count, in display order
 * @return {Array<{id: string, label: string, color: ?string, count: number}>} only the non-zero ones
 */
export function statusCountsFor(members, slots, date, halves, types) {
	if (!date || !types || types.length === 0) return []

	const counts = new Map(types.map(type => [type.id, 0]))

	for (const member of members || []) {
		for (const half of halves) {
			const slug = rawSlot(slots, member.userId, date, half)?.slug
			if (!slug || !counts.has(slug)) continue
			// A member is counted once per status per day, however many of the
			// halves in scope carry it.
			if (!halves.some(earlier => earlier < half
				&& rawSlot(slots, member.userId, date, earlier)?.slug === slug)) {
				counts.set(slug, counts.get(slug) + 1)
			}
		}
	}

	return types
		.map(type => ({ ...type, count: counts.get(type.id) || 0 }))
		.filter(entry => entry.count > 0)
}

/**
 * How many members hold a chosen status on one date.
 *
 * Fed the **whole** member list, never the filtered rows: the number exists so
 * somebody can see which day is worth switching the day picker to, and a count
 * that only ever described the rows already on screen would answer nothing.
 *
 * @param {Array<{userId: string}>} members every member of the team
 * @param {object} slots the grid response's `slots` map
 * @param {string} date ISO date
 * @param {number[]} halves which halves of it count
 * @param {string[]} slugs chosen type slugs
 * @return {number}
 */
export function countMembersWithStatus(members, slots, date, halves, slugs) {
	if (!slugs || slugs.length === 0 || !date) return 0

	const wanted = new Set(slugs)
	let count = 0
	for (const member of members || []) {
		if (holdsStatus(slots, member.userId, date, halves, wanted)) count++
	}
	return count
}
