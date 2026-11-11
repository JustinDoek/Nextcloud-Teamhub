<template>
	<!-- v4.13.56 — a page, not a modal. It used to open as a second NcModal
	     over the template editor's own, which is the stack Justin reported.
	     The back control is the editor's, not the overlay's, because there is
	     no overlay: `backLabel` says where it goes, and Cancel below does the
	     same thing for somebody who reached the bottom of the form. -->
	<section class="th-layout-editor">
		<div class="th-layout-editor__head">
			<NcButton variant="tertiary" @click="$emit('close')">
				<template #icon><ArrowLeft :size="ICON_BODY" /></template>
				{{ backLabel }}
			</NcButton>
		</div>
		<div class="th-layout-editor__body">
			<h3 class="th-layout-editor__title">{{ name }}</h3>

			<!-- ── 1. the page structure ─────────────────────────────────── -->
			<h4 class="th-layout-editor__step">{{ t('teamhub', 'Page structure') }}</h4>
			<p class="th-layout-editor__hint">
				{{ t('teamhub', 'How the page is divided. Each diagram shows where the first cards land.') }}
			</p>

			<div class="th-layout-editor__structures" role="radiogroup" :aria-label="t('teamhub', 'Page structure')">
				<button
					v-for="option in structures"
					:key="option.id"
					type="button"
					role="radio"
					:aria-checked="option.id === structureId ? 'true' : 'false'"
					class="th-layout-structure"
					:class="{ 'th-layout-structure--active': option.id === structureId }"
					@click="structureId = option.id">
					<!-- The diagram is generated from the structure's own slot
					     widths, so it cannot drift from what the structure
					     does. A hand-drawn picture of a layout is a second
					     description of the same thing, and the one that goes
					     stale is always the picture. -->
					<svg
						class="th-layout-structure__preview"
						viewBox="0 0 48 30"
						aria-hidden="true"
						focusable="false">
						<rect
							v-for="(cell, index) in preview(option)"
							:key="index"
							:x="cell.x * 4 + 0.5"
							:y="cell.y * 9 + 0.5"
							:width="cell.w * 4 - 1"
							:height="8"
							rx="1" />
					</svg>
					<span class="th-layout-structure__label">{{ option.label }}</span>
				</button>
			</div>

			<!-- ── 2. the order ──────────────────────────────────────────── -->
			<h4 class="th-layout-editor__step">{{ t('teamhub', 'Order of the cards') }}</h4>
			<p class="th-layout-editor__hint">
				{{ t('teamhub', 'The first card takes the first place in the structure, the second the next, and so on.') }}
			</p>

			<ol v-if="rows.length" class="th-layout-editor__list">
				<li
					v-for="(row, index) in rows"
					:key="row.i"
					class="th-layout-editor__row">
					<span class="th-layout-editor__position" aria-hidden="true">{{ index + 1 }}</span>
					<span class="th-layout-editor__name">{{ row.label }}</span>
					<span class="th-layout-editor__width">{{ widthLabel(index) }}</span>

					<div class="th-layout-editor__moves">
						<NcButton
							variant="tertiary"
							:disabled="index === 0"
							:aria-label="t('teamhub', 'Move {widget} up', { widget: row.label })"
							:title="t('teamhub', 'Move {widget} up', { widget: row.label })"
							@click="move(index, -1)">
							<template #icon><ArrowUp :size="ICON_BODY" /></template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="index === rows.length - 1"
							:aria-label="t('teamhub', 'Move {widget} down', { widget: row.label })"
							:title="t('teamhub', 'Move {widget} down', { widget: row.label })"
							@click="move(index, 1)">
							<template #icon><ArrowDown :size="ICON_BODY" /></template>
						</NcButton>
					</div>
				</li>
			</ol>

			<p v-else class="th-layout-editor__hint">
				{{ t('teamhub', 'This template switches on no apps or modules, so its teams have no cards to arrange yet.') }}
			</p>

			<!-- Said once, plainly, because the list is deliberately shorter
			     than the set of widgets TeamHub has and an administrator who
			     does not know why will go looking. -->
			<p class="th-layout-editor__hint">
				{{ t('teamhub', 'Only the cards every team of this template has are listed. A card that depends on the team itself, such as a project or a service desk, and any card another app adds, appears after these.') }}
			</p>

			<p v-if="error" class="th-layout-editor__error">{{ error }}</p>

			<div class="th-layout-editor__actions">
				<NcButton variant="tertiary" @click="reset">
					{{ resetLabel }}
				</NcButton>
				<span class="th-layout-editor__spacer" />
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !rows.length" @click="save">
					{{ busy ? t('teamhub', 'Saving…') : t('teamhub', 'Save') }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import {
	layoutEditorRows,
	layoutFromEditorRows,
	layoutStructures,
	structureOf,
	structurePreview,
	widthAt,
} from '../../lib/layoutWidgetCatalog.js'

/**
 * The default home layout for a kind of team (v4.13.35, rebuilt v4.13.36).
 *
 * ## Two questions, in this order
 *
 * **How is the page divided**, and **which card goes where in it**. That is
 * all an administrator is asked, and neither question needs them to picture a
 * grid.
 *
 * v4.13.35 asked instead for a width per widget — *narrow*, *wide*, *main
 * column*. Justin, 2026-10-28: *"how does the admin know what narrow, main
 * column etc. mean and how they are positioned?"* They cannot. A width per
 * card is a page described one card at a time, with the reader assembling the
 * picture in their head, and no better label was going to fix that. The
 * structures show themselves.
 *
 * ## The diagrams are generated
 *
 * `structurePreview()` places the first cards the same way the packer does,
 * from the structure's own slot widths, so the picture is derived from the
 * behaviour rather than drawn alongside it.
 *
 * ## Only this template's cards
 *
 * The list comes from the template's own apps and modules. A Collaboration
 * template has no OpenProject link, so Project info is not in it — Justin's
 * other finding, and it was the editor offering a card to position that would
 * never appear. Cards that depend on the team rather than the template are
 * named in a sentence instead, because there is no template-level fact to gate
 * them on and guessing one would be the same mistake twice.
 *
 * ## Nothing can be removed
 *
 * A layout that omits a widget does not hide it: `mergeNewWidgets()` adds it
 * back when the layout is read. A Remove control here would be a button that
 * quietly does nothing. Hiding a card is a per-team decision with its own
 * switches, in Manage team.
 */
export default {
	name: 'LayoutDefaultEditor',

	components: { NcButton, ArrowDown, ArrowLeft, ArrowUp },

	props: {
		/** The page's heading. */
		name: { type: String, required: true },
		/** The layout being edited, or null when none is set. */
		layout: { type: Array, default: null },
		/** The layout TeamHub ships: the card order and each card's height. */
		shipped: { type: Array, default: () => [] },
		/** The template's app keys, which decide which cards are offered. */
		apps: { type: Array, default: () => [] },
		/** The template's module keys, likewise. */
		modules: { type: Array, default: () => [] },
		templateKey: { type: String, default: null },
		/** What the Reset button says. */
		resetLabel: { type: String, required: true },
		/** What the back control at the top of the page says (v4.13.56). */
		backLabel: { type: String, required: true },
		busy: { type: Boolean, default: false },
		error: { type: String, default: null },
	},

	emits: ['save', 'close'],

	data() {
		return {
			rows: [],
			structureId: layoutStructures()[0].id,
			ICON_BODY,
		}
	},

	computed: {
		structures() {
			return layoutStructures()
		},

		structure() {
			return this.structures.find(s => s.id === this.structureId) || this.structures[0]
		},
	},

	watch: {
		// immediate, and on the layout rather than in mounted(), because the
		// panel opens this modal as soon as the administrator clicks and the
		// GET behind `layout` may land a moment later.
		layout: {
			immediate: true,
			handler() {
				this.seed()
			},
		},
	},

	methods: {
		t,

		seed() {
			const stored = this.layout && this.layout.length ? this.layout : null
			this.structureId = structureOf(stored || this.shipped)
			this.rows = layoutEditorRows(stored, this.shipped, this.apps, this.modules, this.templateKey)
		},

		/** Back to the arrangement TeamHub ships, for this template's cards. */
		reset() {
			this.structureId = structureOf(this.shipped)
			this.rows = layoutEditorRows(null, this.shipped, this.apps, this.modules, this.templateKey)
		},

		preview(structure) {
			return structurePreview(structure)
		},

		/**
		 * How wide the card at this position ends up, in words. Shown on the
		 * row so the order and the structure can be read together, rather than
		 * the administrator counting places against the diagram.
		 */
		widthLabel(index) {
			const w = widthAt(this.structure, index)
			if (w >= 12) return t('teamhub', 'Full width')
			if (w >= 9) return t('teamhub', 'Main column')
			if (w >= 6) return t('teamhub', 'Half the row')
			if (w >= 4) return t('teamhub', 'A third of the row')
			return t('teamhub', 'Side column')
		},

		move(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.rows.length) return
			const next = [...this.rows]
			;[next[index], next[target]] = [next[target], next[index]]
			this.rows = next
		},

		save() {
			// No tab order: this editor arranges the grid, and the tab bar is a
			// different thing with a different control. An empty tab order
			// means "do not set one", and the cascade then keeps whatever tab
			// order the member already has.
			this.$emit('save', {
				layout: layoutFromEditorRows(this.rows, this.structure),
				tabOrder: [],
			})
		},
	},
}
</script>

<style scoped>
/* v4.13.56 — the padding, the 80vh cap and the inner scroll were the modal's
   needs, not the editor's. On a page the settings page scrolls, and a second
   scroll container inside it is the thing that hides the Save button. */
.th-layout-editor {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md);
}

.th-layout-editor__head {
	display: flex;
	align-items: center;
	padding-bottom: var(--th-space-sm);
	border-bottom: 1px solid var(--color-border);
}

.th-layout-editor__body {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
	max-width: 780px;
}

.th-layout-editor__title {
	margin: 0;
	font-size: var(--th-font-heading);
}

.th-layout-editor__step {
	margin: 12px 0 0;
	font-size: var(--th-font-meta);
	font-weight: var(--th-font-weight-semibold);
	color: var(--color-text-maxcontrast);
}

.th-layout-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-layout-editor__structures {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 8px;
	margin-block-start: 4px;
}

/* A documented raw <button> carve-out (/ui-standards): a selectable tile whose
   whole surface is the control, which NcButton cannot be. */
.th-layout-structure {
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: stretch;
	padding: 8px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-element);
	cursor: pointer;
	text-align: start;
	font-size: var(--th-font-meta);
	color: var(--color-main-text);
}

.th-layout-structure:hover { background: var(--color-background-hover); }

.th-layout-structure:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.th-layout-structure--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.th-layout-structure__preview {
	width: 100%;
	height: auto;
	display: block;
}

.th-layout-structure__preview rect { fill: var(--color-text-maxcontrast); }
.th-layout-structure--active .th-layout-structure__preview rect { fill: var(--color-primary-element); }

.th-layout-structure__label { line-height: 1.3; }

.th-layout-editor__list {
	list-style: none;
	margin: 4px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.th-layout-editor__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-radius: var(--border-radius-element);
	background: var(--color-background-hover);
}

.th-layout-editor__position {
	min-width: 20px;
	text-align: end;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-layout-editor__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: var(--th-font-meta);
}

.th-layout-editor__width {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-layout-editor__error {
	margin: 0;
	color: var(--color-text-error);
	font-size: var(--th-font-meta);
}

.th-layout-editor__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-block-start: 12px;
}

.th-layout-editor__spacer { flex: 1; }
</style>
