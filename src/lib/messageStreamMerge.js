/**
 * Merging a fresh read of the open team's newest message page into the one on
 * screen (v4.13.20).
 *
 * `fetchMessages` ran on team select and on a pager click, and nothing else —
 * so a message posted by somebody else in the team you were *looking at* never
 * appeared. The sidebar's unread bubble did update, which made it worse: the
 * app told you there was something new and then did not show it until you
 * reloaded.
 *
 * A pure function for the same reason `teamListReconcile` is one: the two
 * rules below are easy to "simplify" into `state.messages = messages`, and
 * that breaks things no gate would catch.
 */

/**
 * @param {Array<object>} current  The messages on screen, newest first.
 * @param {Array<object>} incoming The server's newest page, newest first.
 * @return {Array<object>} The list to store.
 */
export function mergeMessages(current, incoming) {
    if (!Array.isArray(incoming)) {
        return Array.isArray(current) ? current : []
    }
    const held = new Map((Array.isArray(current) ? current : []).map(m => [m.id, m]))

    // Rule 1 — the server's page is adopted whole, including its length. A
    // message that has dropped off the newest page leaves the view, because it
    // is on page 2 now and the pager has to stay honest: `messagesLimit` is 5,
    // so three new messages push three old ones over the edge. Prepending the
    // new ones instead would grow "page 1" to eight rows and make every page
    // number below it a lie.
    //
    // Rule 2 — a message we already hold keeps its **own object**, with the
    // server's fields assigned onto it. The cards are keyed `:key="msg.id"`,
    // so preserving identity keeps each card's own component state — an
    // expanded comment thread, a half-typed reply — across a refresh that the
    // reader did not ask for and should not notice. `Object.assign` also only
    // writes the keys the server sent, which is what lets lazily loaded
    // comments survive while `comment_count` still refreshes.
    return incoming.map(row => {
        const current = held.get(row.id)
        if (!current) return row
        Object.assign(current, row)
        return current
    })
}
