<template>
	<!-- v4.11.7 — the Services tab has two panes: what the team offers, and
	     what it has been asked (Justin, 2026-09-30: the grid opens "in the
	     service iframe"). The pane is store state, so the queue widget's
	     *View all requests* can open the tab straight onto the grid. -->
	<div class="svc-tab">
		<div v-if="isDesk" class="svc-tab__panes" role="group" :aria-label="t('teamhub', 'What to show')">
			<NcButton
				variant="secondary"
				:pressed="pane === 'services'"
				@click="setPane('services')">
				<template #icon><ViewGridOutline :size="ICON_BODY" /></template>
				{{ t('teamhub', 'Services') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:pressed="pane === 'requests'"
				@click="setPane('requests')">
				<template #icon><ClipboardTextOutline :size="ICON_BODY" /></template>
				{{ t('teamhub', 'Requests') }}
			</NcButton>
		</div>

		<ServiceRequestsGrid v-if="isDesk && pane === 'requests'" />
		<ServicesWidget v-else page />
	</div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'

import ServiceRequestsGrid from './ServiceRequestsGrid.vue'
import ServicesWidget from '../ServicesWidget.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'

export default {
	name: 'ServiceTeamTabView',

	components: { NcButton, ClipboardTextOutline, ViewGridOutline, ServiceRequestsGrid, ServicesWidget },

	data() {
		return { ICON_BODY }
	},

	computed: {
		...mapState(['servicesTabPane']),
		...mapState({ serviceDeskConfig: state => state.serviceDeskConfig }),

		/**
		 * The grid is the desk's, and the route behind it refuses anybody who
		 * is not an eligible agent. A Service-template team that is not a
		 * desk yet has services to build and no requests to show, so it gets
		 * the tab it always had — the switcher is hidden, not disabled.
		 */
		isDesk() {
			return !!this.serviceDeskConfig?.isDesk
		},

		pane() {
			return this.servicesTabPane === 'requests' ? 'requests' : 'services'
		},
	},

	methods: {
		t,

		setPane(pane) {
			this.$store.commit('SET_SERVICES_TAB_PANE', pane)
		},
	},
}
</script>

<style scoped lang="scss">
.svc-tab {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
}

.svc-tab__panes {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	padding: var(--th-space-lg, 16px) var(--th-space-lg, 16px) 0;
}
</style>
