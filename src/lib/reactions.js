/**
 * Emoji reactions (v4.20.27): the optimistic step ReactionBar takes before
 * the server answers. Pure, so it is testable in node; the server's summary
 * replaces the result as soon as it arrives.
 *
 * A summary item is `{ emoji, count, reacted, names, more }`, in the order the
 * emojis were first given. Adding a new emoji appends it; taking back the last
 * one removes the chip. The viewer's own name is not guessed into `names`:
 * the tooltip catches up when the server's answer lands.
 *
 * @param {Array<{emoji:string,count:number,reacted:boolean,names?:string[],more?:number}>} items
 * @param {string} emoji
 * @param {boolean} add
 * @return {Array} a new array; `items` is not changed
 */
export function applyReactionToggle(items, emoji, add) {
    const list = Array.isArray(items) ? items.map((r) => ({ ...r })) : []
    const i = list.findIndex((r) => r.emoji === emoji)

    if (add) {
        if (i === -1) {
            list.push({ emoji, count: 1, reacted: true, names: [], more: 0 })
        } else if (!list[i].reacted) {
            list[i].count += 1
            list[i].reacted = true
        }
        return list
    }

    if (i === -1 || !list[i].reacted) {
        return list
    }
    if (list[i].count <= 1) {
        list.splice(i, 1)
    } else {
        list[i].count -= 1
        list[i].reacted = false
    }
    return list
}

/**
 * A message row from an endpoint that does not stamp reactions (pin, unpin)
 * takes the reactions of the row it replaces. A row that carries its own
 * `reactions` keeps them.
 *
 * @param {object} incoming the server's row
 * @param {object|null|undefined} previous the row it replaces
 * @return {object}
 */
export function withKeptReactions(incoming, previous) {
    if (!incoming || Array.isArray(incoming.reactions) || !previous || !Array.isArray(previous.reactions)) {
        return incoming
    }
    return { ...incoming, reactions: previous.reactions }
}
