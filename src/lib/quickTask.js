/**
 * A follow-up task's starting title, from the thing it follows up on —
 * v4.20.30.
 *
 * A message, a comment or a decision opens the task dialog with its own words
 * as the title, so the person only has to sharpen it. The words arrive as the
 * author typed them: markdown, mentions, links. A task title is one plain
 * line, so this strips what would read as noise in the Tasks app and in My
 * Work, and cuts at a word.
 *
 * Pure, so it runs under `node --test`.
 */

/** Long enough to say what it is about; a task title is a line, not a body. */
export const TASK_TITLE_MAX = 80

/**
 * @param {unknown} text  the subject, body or comment, as stored
 * @param {number}  [max] the longest title, in characters
 * @return {string} one plain line, '' when nothing is left
 */
export function taskTitleFrom(text, max = TASK_TITLE_MAX) {
    if (typeof text !== 'string') {
        return ''
    }
    let plain = text
        // Images first, or the link rule below keeps their alt text.
        .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
        // [label](url) reads as its label.
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        // A mention as stored, @"uid with spaces", reads as @uid with spaces.
        .replace(/@"([^"]+)"/g, '@$1')
        // Bare URLs say nothing in a title.
        .replace(/https?:\/\/\S+/g, ' ')
        // Markdown marks: headings, quotes, emphasis, code, list bullets.
        .replace(/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/gm, '')
        .replace(/[*_~`]+/g, '')
        .replace(/\s+/g, ' ')
        .trim()

    if (plain.length <= max) {
        return plain
    }
    plain = plain.slice(0, max)
    const lastSpace = plain.lastIndexOf(' ')
    // Cut at a word when there is one in the last third; a single long word
    // is cut where it is.
    if (lastSpace > max * 2 / 3) {
        plain = plain.slice(0, lastSpace)
    }
    return plain.replace(/[\s.,;:]+$/, '')
}
