<template>
	<!-- v4.10.15 — one of My Work's two workflow sections: Action required
	     (the viewer is responsible for the active step) or Waiting for
	     others (they take part, somebody else is responsible). Same card
	     chrome as the aggregated groups below it, its own rows. Owns its
	     loading, empty and error states; the parent owns the data. -->
	<section class="mywork__group wf-section" :aria-labelledby="titleId">
		<div class="wf-section__head">
			<component :is="icon" :size="iconToolbar" class="wf-section__icon" aria-hidden="true" />
			<h3 :id="titleId" class="wf-section__title">
				<span>{{ title }}</span>
				<span class="mywork__group-count">{{ workflows.length }}</span>
			</h3>
			<span class="wf-section__kind">{{ t('teamhub', 'Workflows') }}</span>
		</div>

		<div v-if="loading && !loaded" class="wf-section__state">
			<NcLoadingIcon :size="iconBody" />
			<span>{{ t('teamhub', 'Loading workflows') }}</span>
		</div>

		<NcNoteCard v-else-if="error" type="error" class="wf-section__error">
			<p>{{ errorText }}</p>
			<NcButton variant="secondary" @click="$emit('retry')">{{ t('teamhub', 'Try again') }}</NcButton>
		</NcNoteCard>

		<p v-else-if="!workflows.length" class="wf-section__state wf-section__empty">
			{{ emptyText }}
		</p>

		<ul v-else class="wf-section__list">
			<WorkflowItemRow
				v-for="w in workflows"
				:key="rowKey(w)"
				:workflow="w"
				:busy="busyId === w.id"
				@open="$emit('open', $event)"
				@action="$emit('action', $event)" />
		</ul>
	</section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard, NcButton } from '@nextcloud/vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountClockOutline from 'vue-material-design-icons/AccountClockOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import WorkflowItemRow from './WorkflowItemRow.vue'
import { WORKFLOW_SECTION, rowKey } from '../../constants/workflows.js'
import { ICON_BODY, ICON_TOOLBAR } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowSection',
	components: { NcLoadingIcon, NcNoteCard, NcButton, WorkflowItemRow },
	props: {
		/** One of WORKFLOW_SECTION — action required, waiting, or completed. */
		kind: { type: String, required: true },
		workflows: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		loaded: { type: Boolean, default: false },
		/** `{ status, message }` or null. */
		error: { type: Object, default: null },
		busyId: { type: Number, default: null },
	},
	emits: ['open', 'action', 'retry'],
	computed: {
		iconBody() { return ICON_BODY },
		iconToolbar() { return ICON_TOOLBAR },
		isAction() { return this.kind === WORKFLOW_SECTION.ACTION_REQUIRED },
		isCompleted() { return this.kind === WORKFLOW_SECTION.COMPLETED },
		titleId() { return 'wf-section-title-' + this.kind },
		icon() {
			if (this.isCompleted) {
				return CheckCircleOutline
			}
			return this.isAction ? AccountCheckOutline : AccountClockOutline
		},
		title() {
			if (this.isCompleted) {
				return t('teamhub', 'Completed')
			}
			return this.isAction
				? t('teamhub', 'Action required')
				: t('teamhub', 'Waiting for others')
		},
		emptyText() {
			if (this.isCompleted) {
				return t('teamhub', 'No workflow has been completed yet.')
			}
			return this.isAction
				? t('teamhub', 'No workflow step is waiting for you.')
				: t('teamhub', 'You are not waiting on anybody in a workflow.')
		},
		errorText() {
			return this.error?.message
				? t('teamhub', 'Workflows could not be loaded: {error}', { error: this.error.message })
				: t('teamhub', 'Workflows could not be loaded.')
		},
	},
	methods: { t, n, rowKey },
}
</script>

<style scoped lang="scss">
.wf-section__head {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-sm, 8px) var(--th-space-lg, 16px);
	border-bottom: 1px solid var(--color-border);
}

.wf-section__icon {
	flex: 0 0 auto;
	color: var(--color-primary-element);
}

.wf-section__title {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-primary-element);
}

/* What tells this card from the aggregated groups under it, in words. */
.wf-section__kind {
	flex: 0 0 auto;
	font-size: var(--th-font-meta, 13px);
	color: var(--color-text-maxcontrast);
}

.wf-section__state {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-md, 12px) var(--th-space-lg, 16px);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-section__empty {
	margin: 0;
}

.wf-section__error {
	margin: var(--th-space-md, 12px) var(--th-space-lg, 16px);

	p {
		margin: 0 0 var(--th-space-sm, 8px);
	}
}

.wf-section__list {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
