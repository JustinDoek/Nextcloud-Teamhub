<template>
	<!-- v4.10.15 — the one place My Work mentions the licence. A quiet line,
	     not a card: the built-in workflows work everywhere; this only says
	     what does not, and — on an unlicensed instance — that the aggregated
	     sources are the licensed part. No buttons, no upsell. -->
	<p class="wf-licence" role="note">
		<InformationOutline :size="iconInline" aria-hidden="true" />
		<span v-if="licensed">
			{{ t('teamhub', 'Custom workflows, Service Teams and archiving are part of the licensed edition.') }}
		</span>
		<span v-else>
			{{ t('teamhub', 'Work from Deck, Files, Decisions and the other sources, custom workflows, Service Teams and archiving are part of the licensed edition. The built-in workflows work without one.') }}
		</span>
		<!-- v4.10.16 — the one sentence an unlicensed instance needs before
		     a workflow ends, not after: a finished workflow is not kept. -->
		<span v-if="!workflowsLicensed" class="wf-licence__retention">
			{{ t('teamhub', 'A workflow is removed when it is finished. Keep anything you need from it before you complete the last step.') }}
		</span>
	</p>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import { ICON_INLINE } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowLicenceNote',
	components: { InformationOutline },
	props: {
		/** Whether the aggregated My Work sources are available. */
		licensed: { type: Boolean, default: true },
		/**
		 * Whether the instance is licensed for the full workflow
		 * experience (v4.10.16). False adds the retention sentence: on an
		 * unlicensed instance a finished workflow is deleted, so people
		 * must know that before they finish one.
		 */
		workflowsLicensed: { type: Boolean, default: true },
	},
	computed: {
		iconInline() { return ICON_INLINE },
	},
	methods: { t, n },
}
</script>

<style scoped lang="scss">
.wf-licence {
	display: flex;
	align-items: flex-start;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);

	svg {
		flex: 0 0 auto;
		margin-block-start: 1px;
	}
}

.wf-licence__retention {
	flex-basis: 100%;
	padding-inline-start: calc(var(--th-space-lg, 16px) + var(--th-space-sm, 8px));
}
</style>
