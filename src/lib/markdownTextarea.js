/**
 * Markdown toolbar mechanics for a plain `<textarea>` (v4.13.57).
 *
 * ## Why this is not `markdownToolbar.js`
 *
 * That module is for `NcRichContenteditable` and says so in its own docblock,
 * which also records the bug that made it so: an earlier copy addressed its
 * editor through `selectionStart` / `selectionEnd`, the editor stopped being a
 * `<textarea>`, and because the code read `el.selectionStart ?? length` every
 * button silently started appending at the end. The lesson was "one
 * implementation per kind of editor", not "one implementation".
 *
 * A textarea is the easy case: `selectionStart` / `selectionEnd` are real
 * character offsets into the value, so none of the caret-stepping that module
 * needs for `<br>`-as-element applies here. These functions are therefore
 * **pure string transforms** — `(text, selStart, selEnd) -> { text, selStart,
 * selEnd }` — and the component does nothing but read the selection, call one,
 * and put the selection back. That is what makes them testable without a DOM;
 * `tests/js/markdownTextarea.test.mjs` pins the behaviour.
 *
 * ## What the syntax has to match
 *
 * Not CommonMark: the surfaces that render these fields use TeamHub's own
 * regex renderer (`renderMarkdown` in `messageMarkdown.js`), so a button may
 * only emit syntax that renderer actually understands. That is why there is no
 * underline button (no syntax, and no `<u>` on the allowlist) and why the
 * heading button writes `## ` — `#`, `##` and `###` are the three the renderer
 * has.
 *
 * ## No placeholder text
 *
 * v4.7.17 settled this for the message toolbars and the same holds here: a
 * button that inserts `**bold text**` is a button whose every use begins by
 * deleting the example. The syntax goes in alone and the caret lands where the
 * typing goes.
 */
import { buildList, listMarker } from './markdownToolbar.js'

/**
 * Expand a selection to cover whole lines.
 *
 * Line constructs — headings, list items — are per-line, so a selection that
 * starts mid-word has to grow outwards before anything is prefixed. With no
 * selection this returns the bounds of the line the caret sits on, which is
 * what makes the heading button work on the line you are typing.
 *
 * @param {string} text the whole field
 * @param {number} start selectionStart
 * @param {number} end selectionEnd
 * @return {{from: number, to: number}} offsets of the first and last line
 */
export function lineBounds(text, start, end) {
	const from = text.lastIndexOf('\n', start - 1) + 1
	const nextBreak = text.indexOf('\n', end)
	// A selection ending exactly on a newline should not swallow the line
	// after it, which `indexOf` from `end` would do when end sits on the
	// break itself.
	const to = nextBreak === -1 ? text.length : nextBreak
	return { from, to: Math.max(to, from) }
}

/**
 * Wrap the selection in a prefix and suffix. Bold and italic.
 *
 * With a selection the caret lands after the whole construct, because the
 * thing the person asked for is finished. With nothing selected it lands
 * between the halves, ready to type into.
 *
 * @param {string} text the whole field
 * @param {number} start selectionStart
 * @param {number} end selectionEnd
 * @param {string} before e.g. `**`
 * @param {string} after e.g. `**`
 * @return {{text: string, selStart: number, selEnd: number}}
 */
export function wrapSelection(text, start, end, before, after) {
	const selection = text.slice(start, end)
	const next = text.slice(0, start) + before + selection + after + text.slice(end)
	const caret = selection
		? start + before.length + selection.length + after.length
		: start + before.length
	return { text: next, selStart: caret, selEnd: caret }
}

/**
 * Make the selected lines a heading.
 *
 * **Replaces an existing heading marker rather than stacking on it**, so
 * clicking the button twice leaves `## Title` and not `## ## Title`. That also
 * makes it the way to change a heading's level: the marker it writes always
 * wins.
 *
 * @param {string} text the whole field
 * @param {number} start selectionStart
 * @param {number} end selectionEnd
 * @param {string} marker `#`, `##` or `###` — the three the renderer has
 * @return {{text: string, selStart: number, selEnd: number}}
 */
export function headingSelection(text, start, end, marker = '##') {
	const { from, to } = lineBounds(text, start, end)
	const block = text.slice(from, to)

	// An empty line has nothing to prefix, so the marker goes in on its own
	// and the caret follows it. Without this the button does nothing at all on
	// a blank line, which is exactly where somebody starting a heading is.
	if (block.trim() === '') {
		const prefix = marker + ' '
		const next = text.slice(0, from) + prefix + text.slice(to)
		const caret = from + prefix.length
		return { text: next, selStart: caret, selEnd: caret }
	}

	const rebuilt = block.split('\n')
		.map(line => (line.trim() ? marker + ' ' + line.replace(/^#{1,6}\s+/, '') : line))
		.join('\n')
	const next = text.slice(0, from) + rebuilt + text.slice(to)
	return { text: next, selStart: from, selEnd: from + rebuilt.length }
}

/**
 * Make the selected lines a bullet or numbered list, one item per line.
 *
 * The marker rules are `buildList()`'s, shared with the three message
 * toolbars and pinned by `npm run check:markdown-list`: a blank line keeps no
 * marker and does not consume a number. What is added here is the strip pass
 * below.
 *
 * **Existing markers are stripped first.** Clicking Bullet list on something
 * already bulleted leaves it bulleted instead of producing `- - item`, and
 * clicking Numbered list on a bulleted list converts it rather than nesting
 * two markers. `*` and `+` are stripped as well as `-`: the renderer does not
 * accept them as bullets, so a line starting that way is somebody's hand-typed
 * list that never rendered, and converting it is the useful answer.
 *
 * @param {string} text the whole field
 * @param {number} start selectionStart
 * @param {number} end selectionEnd
 * @param {boolean} ordered `1. 2. 3.` when true, `- ` when false
 * @return {{text: string, selStart: number, selEnd: number}}
 */
export function listSelection(text, start, end, ordered = false) {
	const { from, to } = lineBounds(text, start, end)
	const block = text.slice(from, to)

	if (block.trim() === '') {
		const prefix = listMarker(1, ordered)
		const next = text.slice(0, from) + prefix + text.slice(to)
		const caret = from + prefix.length
		return { text: next, selStart: caret, selEnd: caret }
	}

	const stripped = block.split('\n')
		.map(line => line.replace(/^\s*(?:[-*+]\s+|\d+\.\s+)/, ''))
		.join('\n')
	const rebuilt = buildList(stripped, ordered)
	const next = text.slice(0, from) + rebuilt + text.slice(to)
	return { text: next, selStart: from, selEnd: from + rebuilt.length }
}

/**
 * Insert a markdown link.
 *
 * A selection becomes the label and the caret lands between the parentheses,
 * ready for the address. With nothing selected the caret lands between the
 * brackets, where the label goes. Either way there is nothing to delete
 * first, which is v4.7.17's rule for the message toolbars.
 *
 * @param {string} text the whole field
 * @param {number} start selectionStart
 * @param {number} end selectionEnd
 * @return {{text: string, selStart: number, selEnd: number}}
 */
export function linkSelection(text, start, end) {
	const selection = text.slice(start, end)
	const next = text.slice(0, start) + '[' + selection + ']()' + text.slice(end)
	// `[sel]()` -> between the parens; `[]()` -> between the brackets.
	const caret = selection
		? start + 1 + selection.length + 2
		: start + 1
	return { text: next, selStart: caret, selEnd: caret }
}
