<template>
	<div class="md-bar" role="toolbar" :aria-label="t('teamhub', 'Formatting')">
		<NcButton
			v-for="button in buttons"
			:key="button.key"
			variant="tertiary"
			:title="button.label"
			:aria-label="button.label"
			:disabled="disabled"
			@mousedown.prevent
			@click="button.run()">
			<template #icon><component :is="button.icon" :size="iconBody" /></template>
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { ICON_BODY } from '../constants/uiTokens.js'
import {
	headingSelection,
	linkSelection,
	listSelection,
	wrapSelection,
} from '../lib/markdownTextarea.js'

/**
 * A formatting toolbar for a plain `<textarea>` (v4.13.57).
 *
 * ## Why it exists
 *
 * Justin, 2026-10-31, on the welcome-page message added in v4.13.56: *"It
 * works but i am missing the buttons to format the text. For people not used
 * to code we should add the options to do heading, bold, italics, bullet list,
 * numbered list and link."* An administrator writing the first thing every
 * user of the instance reads should not have to know markdown to put a link
 * in it.
 *
 * ## Six buttons, and the one that is not here
 *
 * Underline was asked for and is deliberately absent. Markdown has no
 * underline, `renderMarkdown`'s allowlist has no `<u>`, and inventing a
 * syntax (`++text++`) would be a TeamHub dialect in the renderer that draws
 * every team message. Justin's call, 2026-10-31. On the web an underline also
 * reads as a link, so underlined text that is not clickable is a small trap of
 * its own.
 *
 * ## How it talks to the field
 *
 * `resolve` is a function returning the `<textarea>` element. A function and
 * not the element itself, because the parent's `ref` points at an
 * `NcTextArea` whose inner `<textarea>` does not exist until that component
 * has mounted, and a prop evaluated at the parent's own setup time would be
 * null for the lifetime of the page.
 *
 * The value is a prop and every change leaves through `update:value`: this
 * component owns no text. After the parent re-renders, `restore()` puts focus
 * and the selection back, because a toolbar that loses your place is a
 * toolbar you use once.
 *
 * The mechanics are pure functions in `src/lib/markdownTextarea.js`, pinned by
 * `tests/js/markdownTextarea.test.mjs`. Nothing in this file does string work.
 */
export default {
	name: 'MarkdownTextareaToolbar',

	components: { NcButton },

	props: {
		/** The field's current text. This component never mutates it. */
		value: {
			type: String,
			default: '',
		},
		/**
		 * Returns the `<textarea>` to act on, or null before it has mounted.
		 * See the note above on why this is a function.
		 */
		resolve: {
			type: Function,
			required: true,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:value'],

	data() {
		return {
			iconBody: ICON_BODY,
		}
	},

	computed: {
		/**
		 * The buttons, in reading order: the block constructs first (heading,
		 * then the two lists), the inline ones after, link last. Built here
		 * rather than written out six times in the template, which was nine
		 * near-identical `NcButton` blocks in `PostMessageForm`.
		 */
		buttons() {
			return [
				{
					key: 'heading',
					label: t('teamhub', 'Heading'),
					icon: FormatHeader2,
					run: () => this.apply(headingSelection),
				},
				{
					key: 'bold',
					label: t('teamhub', 'Bold'),
					icon: FormatBold,
					run: () => this.apply((text, start, end) => wrapSelection(text, start, end, '**', '**')),
				},
				{
					key: 'italic',
					label: t('teamhub', 'Italic'),
					icon: FormatItalic,
					run: () => this.apply((text, start, end) => wrapSelection(text, start, end, '*', '*')),
				},
				{
					key: 'bullets',
					label: t('teamhub', 'Bullet list'),
					icon: FormatListBulleted,
					run: () => this.apply((text, start, end) => listSelection(text, start, end, false)),
				},
				{
					key: 'numbers',
					label: t('teamhub', 'Numbered list'),
					icon: FormatListNumbered,
					run: () => this.apply((text, start, end) => listSelection(text, start, end, true)),
				},
				{
					key: 'link',
					label: t('teamhub', 'Insert link'),
					icon: LinkVariant,
					run: () => this.apply(linkSelection),
				},
			]
		},
	},

	methods: {
		t,

		/**
		 * Run one transform against the live selection and hand the result up.
		 *
		 * With no textarea to read, the transform is applied at the end of the
		 * text instead of being dropped. That is the case where the field has
		 * never been focused, and a button that appears to do nothing is worse
		 * than one that appends.
		 *
		 * @param {Function} transform `(text, start, end) => { text, selStart, selEnd }`
		 */
		apply(transform) {
			if (this.disabled) {
				return
			}
			const el = this.resolve()
			const text = this.value || ''
			const start = el ? el.selectionStart : text.length
			const end = el ? el.selectionEnd : text.length

			const result = transform(text, start, end)
			this.$emit('update:value', result.text)

			if (el) {
				this.restore(el, result.selStart, result.selEnd)
			}
		},

		/**
		 * Put focus and the selection back after the parent has re-rendered.
		 *
		 * `$nextTick` is not enough on its own: the parent owns the value, so
		 * the textarea's `value` is only updated once *its* render has run,
		 * and setting a selection before that applies the offsets to the old
		 * text. The guard re-reads the element and clamps, because a maxlength
		 * on the field can leave the value shorter than the offsets expect.
		 *
		 * @param {HTMLTextAreaElement} el the field
		 * @param {number} selStart where the selection should resume
		 * @param {number} selEnd and where it should end
		 */
		restore(el, selStart, selEnd) {
			this.$nextTick(() => {
				const limit = el.value.length
				el.focus()
				el.setSelectionRange(Math.min(selStart, limit), Math.min(selEnd, limit))
			})
		},
	},
}
</script>

<style scoped>
/* Sits directly above the field it formats, so it reads as part of it rather
   than as a row of unrelated icons. `NcButton` tertiary is already square and
   icon-only here, so the row needs nothing but a gap. */
.md-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs);
	margin-bottom: var(--th-space-xs);
}
</style>
