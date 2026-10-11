<template>
	<!-- v4.11.12 — where the request's detail is drawn (Justin, 2026-09-30).
	     A request is read and worked for as long as it takes, so it is a page
	     with a way back, not a dialog over the list you came from: the desk
	     opens it inside the Services tab, the requester inside the catalogue,
	     and My Work inside My Work. Each of those passes its own `backLabel`,
	     because "back" has to name where back *is*.

	     A shell rather than a second copy of the detail: `WorkflowDetailView`
	     keeps one body and one script, and this decides only what is drawn
	     around it. The modal is still here for any surface that has nowhere
	     to put a page (none today; it is what an embedded widget would want). -->
	<div v-if="inline" class="wf-shell">
		<div class="wf-shell__bar">
			<NcButton variant="tertiary" @click="$emit('back')">
				<template #icon><ArrowLeft :size="iconBody" /></template>
				{{ backLabel }}
			</NcButton>
		</div>
		<slot />
	</div>

	<!-- `label-id`, not `name`: see WorkflowActionDialog. The id is on a
	     visually-hidden heading inside the body rather than on the workflow's
	     own <h2>, because that <h2> does not exist while the detail is
	     loading or has failed, and a dangling aria-labelledby names nothing. -->
	<NcModal v-else :label-id="labelId" size="normal" @close="$emit('close')">
		<slot />
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

import { ICON_BODY } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowDetailShell',

	components: { NcButton, NcModal, ArrowLeft },

	props: {
		/** Draw it as a page with a back button instead of a dialog. */
		inline: {
			type: Boolean,
			default: false,
		},
		/** Where back goes, in words: *Back to requests*, *Back to my work*. */
		backLabel: {
			type: String,
			default: '',
		},
		/** The modal's accessible name, ignored when inline. */
		labelId: {
			type: String,
			default: '',
		},
	},

	emits: ['back', 'close'],

	computed: {
		iconBody() { return ICON_BODY },
	},
}
</script>

<style scoped lang="scss">
.wf-shell {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	/* The page's own gutter: inline, this is the whole surface. */
	padding: var(--th-space-lg, 16px);
}

/**
 * The back button sits on its own line above the request, at the start of it,
 * where the eye lands first when a page replaces a list.
 */
.wf-shell__bar {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
}
</style>
