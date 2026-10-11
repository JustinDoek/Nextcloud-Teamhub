<template>
	<!-- v4.11.8 — the pill of What's new (`WhatsHappeningView.__tab`), as one
	     component so the pages that filter with it cannot drift apart
	     (Justin, 2026-09-30). An optional icon, a name, an optional number.
	     A raw <button> with `aria-pressed`: a segmented toggle group narrows
	     one region below it, so `role="tab"` would promise tabpanels that do
	     not exist — the carve-out `/ui-standards` § "NcButton is the default"
	     names. -->
	<button
		type="button"
		class="th-pill"
		:class="{ 'th-pill--on': pressed }"
		:aria-pressed="pressed ? 'true' : 'false'"
		:aria-label="ariaLabel || null"
		@click="$emit('click', $event)">
		<span v-if="$slots.icon" class="th-pill__icon" aria-hidden="true"><slot name="icon" /></span>
		<span class="th-pill__label">{{ label }}</span>
		<span v-if="count !== null" class="th-pill__count">{{ count }}</span>
	</button>
</template>

<script>
export default {
	name: 'TogglePill',

	props: {
		label: { type: String, required: true },
		/** The number behind the name; `null` leaves it off. */
		count: { type: Number, default: null },
		pressed: { type: Boolean, default: false },
		/** When the visible name is not the whole accessible name. */
		ariaLabel: { type: String, default: '' },
	},

	emits: ['click'],
}
</script>

<style scoped lang="scss">
.th-pill {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-xs, 4px) var(--th-space-md, 12px);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 13px);
	font-weight: var(--th-font-weight-medium, 500);
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}

	/* Split from :hover — grouping them silences the keyboard ring, which is
	   the trap `/ui-standards` § Focus visibility documents. */
	&:focus-visible {
		background: var(--color-background-hover);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

/* Selection is carried by the fill AND the border AND the bolder number, so
   it never rests on colour alone (WCAG 1.4.1). */
.th-pill--on {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: var(--th-font-weight-semibold, 600);

	&:hover,
	&:focus-visible {
		background: var(--color-primary-element-hover, var(--color-primary-element));
	}
}

.th-pill__icon {
	display: inline-flex;
	align-items: center;
}

.th-pill__label {
	white-space: nowrap;
}

.th-pill__count {
	font-variant-numeric: tabular-nums;
	opacity: 0.85;
	font-weight: var(--th-font-weight-semibold, 600);
}
</style>
