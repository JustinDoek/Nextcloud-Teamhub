/**
 * Shared behaviour for the Markdown formatting toolbars (v4.7.18).
 *
 * Three surfaces carry the same toolbar — the composer (`PostMessageForm`),
 * the comment box (`CommentsSection`) and message edit mode (`MessageCard`) —
 * and until now each carried its own copy of the insertion logic. The copies
 * drifted, and the drift caused a real bug: `MessageCard`'s copy addressed its
 * editor as a `<textarea>` through `selectionStart` / `selectionEnd`, which
 * that editor stopped being at some point. Because the code read
 * `el.selectionStart ?? this.editBody.length`, an editor with no such property
 * silently resolved to "caret at the end of the text", so every button
 * appended at the end and no selection was ever seen. The `??` hid it for as
 * long as the buttons still did *something*.
 *
 * All three write into an `NcRichContenteditable`, so they are one
 * implementation now and this is it.
 *
 * The one thing worth knowing about that component: `renderContent()` maps
 * every `\n` in the model to a `<br>`, and `parseContent()` maps it back. Line
 * breaks are therefore elements, not characters in a text node, which is why
 * caret movement here counts steps rather than string offsets, and why text
 * containing newlines cannot simply be handed to `insertText`.
 */

/**
 * The contenteditable behind a `NcRichContenteditable` ref, or null.
 *
 * @param {object} componentRef the value of a `ref` on the component
 * @return {HTMLElement|null}
 */
export function resolveEditorElement(componentRef) {
    return componentRef?.$el?.querySelector('.rich-contenteditable__input')
        || componentRef?.$el
        || null
}

/**
 * Whether the caret is currently inside this editor.
 *
 * @param {HTMLElement|null} editorEl the contenteditable
 * @return {boolean}
 */
export function editorHasFocus(editorEl) {
    if (!editorEl) {
        return false
    }
    const activeEl = document.activeElement
    return editorEl === activeEl || editorEl.contains(activeEl)
}

/**
 * The text currently selected inside this editor, or '' when there is none.
 *
 * @param {HTMLElement|null} editorEl the contenteditable
 * @return {string}
 */
export function selectedText(editorEl) {
    if (!editorHasFocus(editorEl)) {
        return ''
    }
    const sel = window.getSelection()
    return (sel && !sel.isCollapsed) ? sel.toString() : ''
}

/**
 * Write text at the caret, replacing the selection.
 *
 * Single-line text goes through `execCommand('insertText')`, which is well
 * behaved and keeps the browser's own undo stack.
 *
 * Multi-line text does not go through `execCommand` at all. Two attempts to
 * make it work are on record; both were measured in a browser against this
 * component's own `parseContent()`, and both lost breaks:
 *
 *   1. `insertText` with the whole string. The browser does not produce
 *      `<br>` for the newlines — it wraps the trailing lines in `<div>`s,
 *      giving `a<div>b</div><div>c</div>`. `parseContent()` maps `</div>` to
 *      `\n`, which puts a break at the *end* of each div rather than between
 *      the first line and the second, so lines one and two arrive joined:
 *      `"- a- b\n- c\n"`.
 *   2. `insertText` per line with `insertHTML('<br>')` between. Worse — every
 *      break is lost, leaving `a- b- c<br>`. Each command fires its own
 *      `input` event, so the component re-reads and re-emits the model
 *      between steps, and the separator `<br>`s do not survive that.
 *
 * So the fragment is built by hand instead: real `Text` nodes and real `<br>`
 * elements, which is exactly the structure `renderContent()` produces and
 * `parseContent()` reads back, with nothing left for the browser to
 * interpret. Measured the same way, it yields `a<br>b<br>c` and round-trips
 * to the string it was given. One synthetic `input` event at the end is what
 * tells `NcRichContenteditable` to re-read itself — its `onInput` handler
 * takes `event.target.innerHTML`, so a bubbling event on the contenteditable
 * is enough, and it fires once rather than once per line.
 *
 * The cost is that a multi-line insertion is not on the native undo stack.
 * That is worth one correct list.
 *
 * @param {HTMLElement|null} editorEl the contenteditable being written into
 * @param {string} text the text to insert
 */
export function insertIntoEditor(editorEl, text) {
    if (!text.includes('\n')) {
        document.execCommand('insertText', false, text)
        return
    }

    const sel = window.getSelection()
    if (!editorEl || !sel || !sel.rangeCount) {
        return
    }

    const range = sel.getRangeAt(0)
    range.deleteContents()

    const fragment = document.createDocumentFragment()
    text.split('\n').forEach((line, index) => {
        if (index > 0) {
            fragment.appendChild(document.createElement('br'))
        }
        if (line) {
            fragment.appendChild(document.createTextNode(line))
        }
    })

    // insertNode() empties the fragment, so the node to sit after has to be
    // taken while it still has children.
    const lastNode = fragment.lastChild
    range.insertNode(fragment)

    if (lastNode) {
        const caret = document.createRange()
        caret.setStartAfter(lastNode)
        caret.collapse(true)
        sel.removeAllRanges()
        sel.addRange(caret)
    }

    editorEl.dispatchEvent(new InputEvent('input', { bubbles: true }))
}

/**
 * Move the caret back `count` characters from where it sits now.
 *
 * `Selection.modify()` rather than Range offset arithmetic, because a step
 * across a line boundary here is a step over a `<br>` node, not a change of
 * offset within a text node. `modify()` counts that as the one character it
 * round-trips to; offset maths would have to special-case it. It is
 * non-standard but implemented in every browser Nextcloud supports, and the
 * feature test below simply leaves the caret alone on anything that lacks it.
 *
 * @param {number} count characters to step back
 */
export function moveCaretBack(count) {
    if (count <= 0) {
        return
    }
    const sel = window.getSelection()
    if (!sel || typeof sel.modify !== 'function') {
        return
    }
    for (let i = 0; i < count; i++) {
        sel.modify('move', 'backward', 'character')
    }
}

/**
 * Focus the editor with the caret at the very end of its content.
 *
 * Used by the keyboard path, where focus has left the editor and there is no
 * live selection to write into. Focusing a contenteditable does not promise
 * where the caret lands, so it is placed explicitly before anything is
 * measured from it.
 *
 * @param {HTMLElement|null} editorEl the contenteditable
 */
export function focusEditorAtEnd(editorEl) {
    if (!editorEl) {
        return
    }
    editorEl.focus()
    const sel = window.getSelection()
    if (!sel) {
        return
    }
    const range = document.createRange()
    range.selectNodeContents(editorEl)
    range.collapse(false)
    sel.removeAllRanges()
    sel.addRange(range)
}

/**
 * Prefix every non-blank line with a list marker.
 *
 * Blank lines are passed through unmarked: in Markdown a blank line is what
 * separates one list from the next, so marking them would both read wrong and
 * merge the two lists into one. Numbering counts only the lines that get a
 * marker, so a blank line does not create a gap in the sequence.
 *
 * @param {string} text     the selected text, newline-separated
 * @param {boolean} ordered `1. 2. 3.` when true, `- ` when false
 * @return {string}
 */
export function buildList(text, ordered) {
    let itemNumber = 0
    return text.split('\n').map(line => {
        if (!line.trim()) {
            return line
        }
        itemNumber++
        return listMarker(itemNumber, ordered) + line
    }).join('\n')
}

/**
 * One list marker.
 *
 * @param {number} index    1-based item number, used only when ordered
 * @param {boolean} ordered `1. ` when true, `- ` when false
 * @return {string}
 */
export function listMarker(index, ordered) {
    return ordered ? `${index}. ` : '- '
}
