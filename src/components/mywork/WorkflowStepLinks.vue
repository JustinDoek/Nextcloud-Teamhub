<template>
	<!-- v4.10.36 — the links a service team put on a step of a built service
	     (`docs/service-builder.md` § 6.1): on a requester step the thing to
	     do, on a team step the team's own material. One button per link,
	     opening in a new tab; NcButton sets rel="noopener noreferrer" itself.
	     Renders nothing for a step without links.
	     v4.10.37 — `completes`: on the requester's own open task, pressing
	     a link *is* doing the task (Justin: "the requester can then click
	     the button and that is the signal for the assigned member to
	     continue"). The link opens as always and `done` is emitted; the
	     parent completes the task. -->
	<ul v-if="links.length" class="wf-links" :aria-label="t('teamhub', 'Links')">
		<li v-for="(link, i) in links" :key="i">
			<NcButton
				:href="link.url"
				target="_blank"
				:variant="variant"
				size="small"
				:aria-label="completes ? t('teamhub', '{link} (marks this task done)', { link: stepLinkDescription(link) }) : stepLinkDescription(link)"
				:title="completes ? t('teamhub', '{link} (marks this task done)', { link: stepLinkDescription(link) }) : stepLinkDescription(link)"
				@click="completes && $emit('done', { step, link })">
				<template #icon>
					<ClipboardEditOutline v-if="link.kind === 'form'" :size="ICON_INLINE" />
					<OpenInNew v-else :size="ICON_INLINE" />
				</template>
				{{ link.label }}
			</NcButton>
		</li>
	</ul>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ClipboardEditOutline from 'vue-material-design-icons/ClipboardEditOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import { ICON_INLINE } from '../../constants/uiTokens.js'
import { stepLinkDescription, stepLinks } from '../../constants/workflows.js'

export default {
	name: 'WorkflowStepLinks',

	components: { NcButton, ClipboardEditOutline, OpenInNew },

	props: {
		/** One step of a workflow view; its `links` are what is shown. */
		step: { type: Object, default: null },
		/** `secondary` where the links are the thing to do, `tertiary` in a list. */
		variant: { type: String, default: 'tertiary' },
		/** v4.10.37 — pressing a link completes this task (the requester's own). */
		completes: { type: Boolean, default: false },
	},

	emits: ['done'],

	data() {
		return { ICON_INLINE }
	},

	computed: {
		links() {
			return stepLinks(this.step)
		},
	},

	methods: {
		t,
		stepLinkDescription,
	},
}
</script>

<style scoped lang="scss">
.wf-links {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
