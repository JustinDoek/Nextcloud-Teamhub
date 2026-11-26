import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { UNPLACED } from './roomFinder.js'

/**
 * Ask the server which rooms of one building are free in a slot, and what
 * their day looks like (v4.20.7). Shared by the inline RoomFinder and the
 * full RoomBrowser page so both send the same question. Times only travel
 * back, never booking titles or attendees (DESIGN §2.261).
 *
 * @param {string} teamId team the meeting belongs to
 * @param {string} building building name or UNPLACED
 * @param {{ slotStart: number, slotEnd: number, windowStart: number, windowEnd: number }} range from slotWindow()
 * @param {string|null} recurrence v4.20.8: serializeRecurrence() output, or
 *   null. With it each room also says on how many dates of the series it is
 *   taken (`series`), and a room free now but taken later is `partial`.
 *   Licensed: an unlicensed instance answers 403.
 * @return {Promise<object>} key → { status, busy, series? }
 */
export async function fetchRoomAvailability(teamId, building, range, recurrence = null) {
	const params = {
		start: new Date(range.slotStart).toISOString(),
		end: new Date(range.slotEnd).toISOString(),
		windowStart: new Date(range.windowStart).toISOString(),
		windowEnd: new Date(range.windowEnd).toISOString(),
	}
	if (recurrence) {
		params.recurrence = recurrence
	}
	if (building === UNPLACED) {
		params.unplaced = '1'
	} else {
		params.building = building
	}
	const { data } = await axios.get(
		generateUrl('/apps/teamhub/api/v1/teams/{teamId}/rooms/availability', { teamId }),
		{ params },
	)
	return (data && typeof data.rooms === 'object' && data.rooms) ? data.rooms : {}
}
