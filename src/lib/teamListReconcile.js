/**
 * Reconciling the sidebar's team list against a fresh read of
 * `GET /api/v1/teams` (v4.13.18).
 *
 * A pure function, in `src/lib` and not inline in the mutation, because the
 * three rules below are each a decision somebody will want to re-read — and
 * because the bug this fixes was invisible for exactly as long as the logic
 * was one unguarded `forEach` with no test on it.
 *
 * The mutation it replaced iterated the *stored* teams and copied `unread`
 * onto the ones it already had, so a team present in the response but not in
 * the store was discarded. The 60s poll therefore fetched the right answer
 * every minute and threw it away: a team joined by accepting an invitation
 * never appeared until a page reload, and a team the user had been removed
 * from never left.
 */

/**
 * @param {Array<object>} current    The teams in the store, in sidebar order.
 * @param {Array<object>} incoming   The server's team list, in its own order.
 * @param {?string} currentTeamId    The team the user has open, if any.
 * @return {Array<object>} The list to store.
 */
export function reconcileTeams(current, incoming, currentTeamId = null) {
    if (!Array.isArray(incoming)) {
        return Array.isArray(current) ? current : []
    }
    const existing = new Map((Array.isArray(current) ? current : []).map(t => [t.id, t]))

    // Rule 1 — the server's order is the sidebar's order, so the response is
    // what we iterate. A team new to the response is stored as it arrived.
    //
    // Rule 2 — a team we already hold keeps its *own object*. `UPDATE_TEAM_IMAGE`
    // patches `image_url` with a cache-buster the plain server value does not
    // carry, so replacing the object would revert a just-uploaded picture to a
    // URL the browser already has cached. Only `unread` is copied across: the
    // same single field the old mutation handled, deliberately, because making
    // this poll authoritative for name, level and the rest is a wider change
    // than the bug needs.
    const next = incoming.map(row => {
        const held = existing.get(row.id)
        if (!held) return row
        held.unread = row.unread || 0
        return held
    })

    // Rule 3 — the open team stays on screen even when this read no longer
    // lists it. Being removed from a team while you are reading it should not
    // teleport you out of it mid-sentence; the row goes on the next pass, once
    // the user has navigated away of their own accord.
    if (currentTeamId && !incoming.some(t => t.id === currentTeamId)) {
        const open = existing.get(currentTeamId)
        if (open) next.push(open)
    }

    return next
}
