/**
 * TeamHub — packing the home grid's default arrangement.
 *
 * Why this file exists
 * --------------------
 * The default home layout used to be fifteen absolute coordinates in a PHP
 * constant, and the grid it is drawn into (`grid-layout-plus`, 12 columns,
 * `:responsive="false"`) compacts **vertically only**. Nothing ever moves
 * sideways. So every widget a team does not have left a hole exactly its own
 * width, for the whole height of the page, and no amount of compaction could
 * close it: a team with the message stream switched off opened with nine empty
 * columns beside a thin strip of cards.
 *
 * It had to be a hole, because a coordinate is an answer to "where does this
 * widget go" that was computed once, for one team, by a person. This module
 * answers the question instead — from the order the widgets are declared in
 * and the space each one needs.
 *
 * Why it runs in the client
 * -------------------------
 * Not a preference. Which widgets are active is decided in
 * `src/lib/activeWidgets.js` from facts that arrive on two different requests
 * — the layout bundle, and the registered integration packages — so the server
 * cannot know the visible set at the moment it serves the layout. It serves the
 * order and the sizes; this packs them.
 *
 * What it is not
 * --------------
 * It is not applied to a layout a member arranged. A saved arrangement is
 * theirs, holes included: somebody who dragged a card into the gap left by a
 * widget they do not use is making the same point this module makes, and
 * repacking over them would undo it every reload. The server says which is
 * which (`isDefault` on the layout bundle).
 */

/**
 * The grid's column count. Mirrors `:col-num="12"` on the `<grid-layout>` in
 * TeamWidgetGrid — the one number in here that another file also states, and
 * it is a prop on a template rather than a token we own.
 */
export const GRID_COLUMNS = 12

/**
 * Default height, in grid rows, of an integration widget that lists rows.
 *
 * The same rule as `LayoutController::H_ROW`, which is where it is explained:
 * five rows of list plus the card's chrome. Stated in both places because an
 * integration's widget is created in the client, by `getOrCreateIntegrationItem`,
 * and never passes through the default layout at all.
 */
export const H_ROW = 4

/**
 * Default height of a blank-canvas (`widget_iframe`) widget — v4.13.26.
 *
 * Five rows, and this one cannot be derived from its content at all: the body
 * is an iframe at `height: 100%`, so it reports exactly the space it is given
 * whatever the embedded document does inside it. ~440 px of card, which holds
 * the small chart or form a canvas exists for; three rows did not.
 */
export const H_CANVAS = 5

/**
 * Pack widgets into the grid, in order, leaving no gaps.
 *
 * A skyline fit: keep the current bottom edge of each of the 12 columns, and
 * place each widget at the leftmost position whose span is the **lowest** —
 * which is where it leaves the least space behind it. Ties go to the left, so
 * the result is a function of the input and nothing else: the same team, on
 * two machines, in two sessions, gets the same home.
 *
 * The properties this is meant to have, in the order they matter:
 *
 *  1. **No hole a widget would have fitted in.** A widget never lands below
 *     free space wide enough for it, because the candidate it is placed at was
 *     the lowest available.
 *  2. **Deterministic.** No randomness, no dependence on iteration order of
 *     anything but `items`, no dependence on what was on screen before.
 *  3. **Order is kept as far as the grid allows.** Reading left to right, top
 *     to bottom, widgets come out in the order given — which is why the server
 *     sends an order at all. It cannot be absolute: a 9-wide stream and a
 *     3-wide card do not divide a 12-column row evenly, so a later narrow card
 *     can fill a band an earlier wide one could not use.
 *
 * Balance across columns is a consequence rather than a goal, and that is
 * deliberate: forcing columns to equal height means moving a widget away from
 * the place the order asked for, and the order is the thing a template, an
 * administrator or a team actually chose.
 *
 * @param {Array<object>} items  layout items in the order they should appear,
 *                               each with at least `i` and `w`; `h` defaults
 *                               to H_ROW and `w` is clamped to the grid
 * @return {Array<object>} new items, same order, with `x` and `y` replaced.
 *                         Every other field is carried through untouched.
 */
export function packLayout(items) {
    if (!Array.isArray(items)) return []

    // Bottom edge of each column, in grid rows. All zero: a pack starts from
    // an empty grid every time, because it is computed from the order and the
    // visible set and must not depend on where anything sat a moment ago.
    const bottom = new Array(GRID_COLUMNS).fill(0)

    return items.map(item => {
        const w = Math.max(1, Math.min(GRID_COLUMNS, Number(item?.w) || 1))
        const h = Math.max(1, Number(item?.h) || H_ROW)

        let bestX = 0
        let bestY = Infinity
        for (let x = 0; x <= GRID_COLUMNS - w; x++) {
            // The widget's top edge is the lowest it can sit without
            // overlapping any column it spans.
            let top = 0
            for (let c = x; c < x + w; c++) {
                if (bottom[c] > top) top = bottom[c]
            }
            // Strictly less than: the first (leftmost) candidate at a given
            // height wins, which is what makes ties deterministic.
            if (top < bestY) {
                bestY = top
                bestX = x
            }
        }

        for (let c = bestX; c < bestX + w; c++) {
            bottom[c] = bestY + h
        }

        return { ...item, x: bestX, y: bestY, w, h }
    })
}

/**
 * Pack the visible part of a layout and leave the rest where it is.
 *
 * The grid renders `visibleLayout` — `gridLayout` filtered by the active
 * widget set — but `gridLayout` keeps every item, so a widget switched back on
 * returns to its place (DESIGN §2.59). Packing therefore has to apply to the
 * visible subset and preserve the invisible remainder untouched, in the array
 * order the rest of the component expects.
 *
 * @param {Array<object>} layout   the full gridLayout
 * @param {Set<string>|Array<string>} visibleIds  ids the grid will render
 * @return {Array<object>} a new array, same length and order as `layout`
 */
export function packVisible(layout, visibleIds) {
    if (!Array.isArray(layout)) return []
    const visible = visibleIds instanceof Set ? visibleIds : new Set(visibleIds || [])

    const packed = packLayout(readingOrder(layout.filter(item => visible.has(item?.i))))
    const byId = new Map(packed.map(item => [item.i, item]))

    return layout.map(item => byId.get(item?.i) || item)
}

/**
 * Sort items the way a person reads the page: top to bottom, then left to
 * right.
 *
 * **This is the order a pack has to be given, and it is not the array's own.**
 * v4.13.33 packed in array order, and that was wrong in a way that only showed
 * on a layout somebody had actually arranged:
 *
 *  - A stored layout's array order is the order rows were *appended*, not the
 *    order they appear. `mergeNewWidgets` adds a widget the saved row predates
 *    at the end of the array and at the bottom of a column, and
 *    `DEFAULT_LAYOUT` itself declares the two top-of-column cockpit widgets
 *    ninth and twelfth. Packing in that order rebuilt a different page from
 *    the same coordinates.
 *  - So an administrator who arranged a template's home, saved it as that
 *    template's default and looked at it again got a layout that was *not* the
 *    one they arranged. It had the right widgets and the right widths and it
 *    read in the wrong order, which is indistinguishable from "it didn't save
 *    properly".
 *
 * Coordinates are what an arrangement *is*, so they decide the order and the
 * packer then closes whatever gaps the team's own widget set leaves. Ties on
 * `y` go left, and ties on both go to the id, so the result stays a function
 * of the layout rather than of how the array was built.
 *
 * @param {Array<object>} items
 * @return {Array<object>} a new array, sorted; the input is not touched
 */
export function readingOrder(items) {
    return [...items].sort((a, b) =>
        (a?.y ?? 0) - (b?.y ?? 0)
        || (a?.x ?? 0) - (b?.x ?? 0)
        || String(a?.i ?? '').localeCompare(String(b?.i ?? '')))
}

/**
 * Whether two layouts place every widget identically.
 *
 * Used to decide whether a pack changed anything before telling the rest of
 * the component about it — a pack runs whenever the visible set changes, and
 * most of those produce the arrangement already on screen.
 *
 * @param {Array<object>} a
 * @param {Array<object>} b
 * @return {boolean}
 */
export function samePlacement(a, b) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false
    return a.every((item, idx) => {
        const other = b[idx]
        return item?.i === other?.i
            && item?.x === other?.x
            && item?.y === other?.y
            && item?.w === other?.w
            && item?.h === other?.h
    })
}
