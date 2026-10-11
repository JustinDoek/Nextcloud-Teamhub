<template>
	<!-- v4.11.7 — the requester's own side of the catalogue (Justin,
	     2026-09-30): the page where you ask is the page you come back to when
	     you wonder where it went. Open and Completed, searchable, and a row
	     opens the request's own detail view — the same one My Work and the
	     desk's queue open, so the three can never disagree. -->
	<section class="svc-mine" :class="{ 'svc-mine--request': detailOpen }" :aria-label="t('teamhub', 'My requests')">
		<!-- v4.11.12 — a request the viewer opens takes the catalogue's whole
		     page, with *Back to catalog* (Justin, 2026-09-30), rather than a
		     dialog over the cards. The widget keeps the request, because the
		     actions on it are already wired here; the page around it steps
		     aside on `detail`, and the card's own chrome comes off. -->
		<WorkflowDetailView
			v-if="detailOpen"
			inline
			:back-label="t('teamhub', 'Back to catalog')"
			:workflow="workflows.detail"
			:loading="workflows.detailLoading"
			:error="workflows.detailError"
			:busy="workflows.busyId !== null && !!workflows.detail && workflows.busyId === workflows.detail.id"
			:display-names="workflows.detail ? (workflows.detail.people || {}) : {}"
			@back="close"
			@close="close"
			@retry="reopen"
			@action="onWorkflowAction"
			@changed="reload" />

		<template v-else>
		<h3 class="svc-mine__title">{{ t('teamhub', 'My requests') }}</h3>

		<!-- v4.11.8 — the pills of What's new, as on the catalogue beside it. -->
		<div class="svc-mine__tabs" role="group" :aria-label="t('teamhub', 'Filter my requests')">
			<TogglePill
				:label="t('teamhub', 'Open')"
				:count="counts.open"
				:pressed="filter === 'open'"
				@click="filter = 'open'" />
			<TogglePill
				:label="t('teamhub', 'Completed')"
				:count="counts.completed"
				:pressed="filter === 'completed'"
				@click="filter = 'completed'" />
		</div>

		<NcTextField
			v-model="query"
			class="svc-mine__search"
			type="search"
			label-outside
			:label="t('teamhub', 'Search my requests')"
			:aria-label="t('teamhub', 'Search my requests')"
			:placeholder="t('teamhub', 'Search')" />

		<div v-if="loading && !loaded" class="svc-mine__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<p v-else-if="!rows.length" class="svc-mine__empty">{{ emptyState }}</p>

		<ul v-else class="svc-mine__list">
			<li v-for="request in pageRows" :key="request.id" class="svc-mine__row">
				<NcButton
					variant="tertiary"
					size="small"
					class="svc-mine__row-title"
					:aria-label="t('teamhub', 'Open details of {title}', { title: request.title })"
					@click="open(request)">
					{{ request.title }}
				</NcButton>
				<div class="svc-mine__meta">
					<span v-if="request.teamName && !request.personal">{{ request.teamName }}</span>
					<span :title="formatAbsolute(dateOf(request))">{{ formatRecent(dateOf(request)) }}</span>
					<NcChip
						no-close
						:variant="statusTone(request.status)"
						:text="statusLabel(request.status)" />
				</div>
			</li>
		</ul>

		<!-- v4.11.11 — five to a page (Justin, 2026-09-30). The widget sits
		     beside the catalogue and must not grow taller than it. -->
		<div v-if="pages > 1" class="svc-mine__pages">
			<NcButton
				variant="tertiary"
				size="small"
				:disabled="page === 0"
				:aria-label="t('teamhub', 'Previous page')"
				@click="page -= 1">
				<template #icon><ChevronLeft :size="ICON_INLINE" /></template>
			</NcButton>
			<span class="svc-mine__page">{{ t('teamhub', 'Page {page} of {pages}', { page: page + 1, pages }) }}</span>
			<NcButton
				variant="tertiary"
				size="small"
				:disabled="page + 1 >= pages"
				:aria-label="t('teamhub', 'Next page')"
				@click="page += 1">
				<template #icon><ChevronRight :size="ICON_INLINE" /></template>
			</NcButton>
		</div>

		</template>

		<WorkflowActionDialog
			v-if="workflowAction"
			:action="workflowAction.action"
			:subject="workflowAction.workflow.title"
			:workflow="workflowAction.workflow"
			:busy="workflows.busyId === workflowAction.workflow.id"
			@close="workflowAction = null"
			@submit="submitWorkflowAction" />
	</section>
</template>

<script>
import { mapState } from 'vuex'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcChip, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

import TogglePill from '../TogglePill.vue'
import WorkflowActionDialog from '../mywork/WorkflowActionDialog.vue'
import WorkflowDetailView from '../mywork/WorkflowDetailView.vue'
import {
	actionText, formatAbsolute, formatRecent, isOpen, isServiceRequest,
	statusLabel, statusTone,
} from '../../constants/workflows.js'
import { ICON_INLINE, ICON_LARGE } from '../../constants/uiTokens.js'

/** Rows to a page. The widget sits beside the catalogue, not under it. */
const PAGE = 5

export default {
	name: 'MyRequestsWidget',

	components: {
		NcButton, NcChip, NcLoadingIcon, NcTextField,
		ChevronLeft, ChevronRight,
		TogglePill, WorkflowActionDialog, WorkflowDetailView,
	},

	emits: ['detail'],

	data() {
		return {
			ICON_INLINE,
			ICON_LARGE,
			/** 'open' | 'completed' */
			filter: 'open',
			query: '',
			page: 0,
			workflowAction: null,
			/** Local, as in the desk's queue: another component opening a
			    detail must not raise this widget's modal. */
			detailOpen: false,
		}
	},

	computed: {
		...mapState({ workflows: state => state.workflows }),

		loading() {
			return this.workflows.loading
		},

		loaded() {
			return this.workflows.loaded
		},

		/**
		 * The viewer's own service requests — the ones they asked for, not
		 * the ones they work. A desk member's claimed requests are their
		 * work and belong in My Work and the team's queue; this widget is
		 * the requester's side of the catalogue.
		 */
		mine() {
			const uid = getCurrentUser()?.uid || ''
			return (this.workflows.items || []).filter(w => isServiceRequest(w) && w.startedBy === uid)
		},

		counts() {
			let open = 0
			for (const w of this.mine) {
				if (isOpen(w)) { open += 1 }
			}
			return { open, completed: this.mine.length - open }
		},

		rows() {
			const wanted = this.filter === 'open'
			const needle = this.query.trim().toLowerCase()
			return this.mine
				.filter(w => isOpen(w) === wanted)
				.filter(w => !needle || this.matches(w, needle))
				.sort((a, b) => this.dateOf(b) - this.dateOf(a))
		},

		pages() {
			return Math.max(1, Math.ceil(this.rows.length / PAGE))
		},

		pageRows() {
			return this.rows.slice(this.page * PAGE, this.page * PAGE + PAGE)
		},

		emptyState() {
			if (this.query.trim()) {
				return t('teamhub', 'No request matches the search.')
			}
			return this.filter === 'open'
				? t('teamhub', 'You have no open request.')
				: t('teamhub', 'You have not completed a request yet.')
		},
	},

	watch: {
		// Narrowing the list makes the page you were on meaningless, and a
		// page past the end would render empty with no way back.
		filter() { this.page = 0 },
		query() { this.page = 0 },
		rows(list) {
			const last = Math.max(0, Math.ceil(list.length / PAGE) - 1)
			if (this.page > last) {
				this.page = last
			}
		},
	},

	mounted() {
		if (!this.workflows.loaded && !this.workflows.loading) {
			this.reload()
		}
	},

	methods: {
		t,
		formatAbsolute,
		formatRecent,
		statusLabel,
		statusTone,

		/** Newest first on whichever date the row has. */
		dateOf(request) {
			return request?.endedAt || request?.updatedAt || request?.startedAt || 0
		},

		matches(request, needle) {
			return [request.title, request.teamName, request.description]
				.some(field => String(field || '').toLowerCase().includes(needle))
		},

		reload() {
			this.$store.dispatch('workflows/load')
		},

		open(request) {
			this.detailOpen = true
			this.$emit('detail', true)
			this.$store.dispatch('workflows/open', { id: request.id, step: request.viewer?.stepKey || '' })
		},

		reopen() {
			const id = this.workflows.detail?.id
			if (id) {
				this.$store.dispatch('workflows/open', { id, step: this.workflows.detail?.viewer?.stepKey || '' })
			}
		},

		close() {
			this.detailOpen = false
			this.$emit('detail', false)
			this.$store.dispatch('workflows/close')
		},

		onWorkflowAction({ workflow, action, direct = false }) {
			if (this.workflows.busyId !== null) {
				return
			}
			if (direct || actionText(action) === 'none') {
				this.run(workflow, action, '')
				return
			}
			this.workflowAction = { workflow, action }
		},

		submitWorkflowAction(text, files = []) {
			if (this.workflowAction) {
				this.run(this.workflowAction.workflow, this.workflowAction.action, text, files)
			}
		},

		async run(workflow, action, text, files = []) {
			try {
				const updated = await this.$store.dispatch('workflows/act', {
					id: workflow.id, action, text, step: workflow.viewer?.stepKey || '',
					fileIds: (files || []).map(f => f.fileId),
				})
				this.workflowAction = null
				showSuccess(t('teamhub', 'Done: {title}', { title: updated?.title || workflow.title }))
				if (updated && isOpen(updated) && this.workflows.detail?.id === updated.id) {
					this.$store.dispatch('workflows/open', { id: updated.id, step: updated.viewer?.stepKey || '' })
				} else {
					this.close()
				}
			} catch (e) {
				if (e?.status === 0) {
					return // busy — the first click is still running
				}
				showError(e?.message || t('teamhub', 'That action could not be completed.'))
				if (e?.status === 409) {
					this.workflowAction = null
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-mine {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-lg, 16px);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-container));
	background: var(--color-main-background);
}

/* v4.11.12 — holding the page, it is not a card beside the catalogue. */
.svc-mine--request {
	padding: 0;
	border: 0;
	background: none;
}

.svc-mine__title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: 600;
}

.svc-mine__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
}

.svc-mine__pages {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: var(--th-space-sm, 8px);
}

.svc-mine__page {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-mine__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg, 16px);
}

.svc-mine__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-mine__list {
	display: flex;
	flex-direction: column;
	list-style: none;
	margin: 0;
	padding: 0;
}

.svc-mine__row {
	/* Where the title's text starts, so the meta line below lines up with
	   it: NcButton indents a text-only label by its own inline padding.
	   The same rule as the desk's queue row. */
	--svc-row-indent: calc(2 * var(--default-grid-baseline) + var(--border-radius-element));

	padding-block: var(--th-space-xs, 4px);
	border-radius: var(--th-radius-control, var(--border-radius-element));

	&:hover {
		background: var(--color-background-hover);
	}
}

.svc-mine__row-title {
	max-width: 100%;

	:deep(.button-vue__text) {
		white-space: normal;
		text-align: start;
	}
}

.svc-mine__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	padding-inline-start: var(--svc-row-indent);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}
</style>
