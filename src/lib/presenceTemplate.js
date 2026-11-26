/**
 * The week template as the personal workplace panel sends and reads it
 * (v4.20.12, DESIGN §2.265). Pure, so tests/js/presenceTemplate.test.mjs can
 * pin it without a component.
 */

/**
 * The fourteen cells the bulk endpoint takes, Monday morning first. A cell
 * with no workplace carries no room either: a room only means something
 * beside a type that asks for one.
 *
 * @param {function(number, number): {presence_type_id: ?number, location_room_id: ?number}} getCell day (0 = Monday), half (0 = morning)
 * @return {Array<{day_of_week: number, half_day: number, presence_type_id: ?number, location_room_id: ?number}>}
 */
export function buildTemplatePayload(getCell) {
    const cells = []
    for (let day = 0; day <= 6; day++) {
        for (const half of [0, 1]) {
            const cell = getCell(day, half) || {}
            const typeId = cell.presence_type_id ?? null
            cells.push({
                day_of_week: day,
                half_day: half,
                presence_type_id: typeId,
                location_room_id: typeId !== null ? (cell.location_room_id ?? null) : null,
            })
        }
    }
    return cells
}

/**
 * Did the server send the read-time default (office, Monday to Friday)
 * rather than a template the person saved? `GET /presence/template` marks
 * every cell of the default `is_default: true`; a saved template, even an
 * empty one, marks them false.
 *
 * @param {Array<{is_default?: boolean}>} cells
 * @return {boolean}
 */
export function isDefaultTemplate(cells) {
    return Array.isArray(cells) && cells.length > 0 && cells.every(cell => cell?.is_default === true)
}
