<template>
	<!-- v4.11.7 — the desk's whole history (Justin, 2026-09-30). The queue
	     widget is the glance at now and is bounded by
	     `WorkflowEngine::CLOSED_WINDOW_DAYS`; this is the grid behind it, so
	     a member can find a request they never worked and that closed months
	     ago. Every member of the desk may read it — the team is the desk. -->
	<div class="svc-grid">
		<!-- v4.11.12 — a service worker opens a request **here**, in the
		     Services tab, not in a dialog over the grid (Justin, 2026-09-30):
		     working a request is not a glance. *Back to requests* brings the
		     grid back with its filters, page and rows intact: this component
		     stays mounted and keeps them in its own state, so returning costs
		     no reload. -->
		<WorkflowDetailView
			v-if="detailOpen"
			inline
			:back-label="t('teamhub', 'Back to requests')"
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
		<div class="svc-grid__filters">
			<NcTextField
				v-model="query"
				class="svc-grid__search"
				type="search"
				label-outside
				:label="t('teamhub', 'Search the requests')"
				:aria-label="t('teamhub', 'Search the requests')"
				:placeholder="t('teamhub', 'Search')" />

			<NcSelect
				v-model="state"
				class="svc-grid__filter"
				input-id="svc-grid-state"
				label-outside
				:clearable="false"
				:options="stateOptions"
				:reduce="option => option.id"
				:aria-label-combobox="t('teamhub', 'Filter by status')" />

			<!-- TRANSLATORS for the placeholder below: no person picked, so any of them -->
			<NcSelect
				v-model="assignee"
				class="svc-grid__filter"
				input-id="svc-grid-assignee"
				label-outside
				:options="assigneeOptions"
				:reduce="option => option.id"
				:placeholder="t('teamhub', 'Anyone')"
				:aria-label-combobox="t('teamhub', 'Filter by service worker')" />

			<NcSelect
				v-model="requester"
				class="svc-grid__filter"
				input-id="svc-grid-requester"
				label-outside
				:options="requesterOptions"
				:reduce="option => option.id"
				:placeholder="t('teamhub', 'Anyone')"
				:aria-label-combobox="t('teamhub', 'Filter by requester')" />
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="reload">{{ t('teamhub', 'Try again') }}</NcButton>
			</template>
		</NcNoteCard>

		<div v-else-if="loading && !rows.length" class="svc-grid__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<NcEmptyContent
			v-else-if="!rows.length"
			:name="t('teamhub', 'No request found')"
			:description="t('teamhub', 'Nothing here matches the filters. Widen them or clear the search.')">
			<template #icon><LifebuoyIcon :size="ICON_HERO" /></template>
		</NcEmptyContent>

		<template v-else>
			<table class="svc-grid__table">
				<thead>
					<tr>
						<!-- v4.11.12 — the date leads the row, and the rows arrive
						     newest first: the server orders the grid on
						     `startedAt` descending
						     (`WorkflowEngine::listDeskRequests()`), so the column
						     the desk reads down is the one it is sorted on. -->
						<th scope="col" class="svc-grid__date-col">{{ t('teamhub', 'Date') }}</th>
						<th scope="col">{{ t('teamhub', 'Request') }}</th>
						<th scope="col">{{ t('teamhub', 'Requester') }}</th>
						<!-- TRANSLATORS: column header - the member of the service team who has this request in hand -->
						<th scope="col">{{ t('teamhub', 'Service worker') }}</th>
						<th scope="col">{{ t('teamhub', 'Status') }}</th>
						<!-- v4.11.12 — the decisions behind the request, last:
						     the row reads date → what → who → where it stands,
						     and only then what it was decided by. -->
						<th scope="col">{{ t('teamhub', 'Decisions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="request in rows" :key="request.id">
						<td class="svc-grid__date-col">
							<time
								:datetime="isoInstant(request.startedAt)"
								:title="formatAbsolute(request.startedAt)">
								{{ formatDay(request.startedAt) }}
							</time>
						</td>
						<td>
							<NcButton
								variant="tertiary"
								size="small"
								class="svc-grid__title"
								:aria-label="t('teamhub', 'Open details of {title}', { title: request.title })"
								@click="open(request)">
								{{ request.title }}
							</NcButton>
						</td>
						<td>{{ nameOf(request, request.startedBy) }}</td>
						<td>
							<span v-if="request.desk && request.desk.assignee">
								{{ nameOf(request, request.desk.assignee) }}
							</span>
							<!-- TRANSLATORS: no member of the service team has this request in hand yet -->
							<span v-else class="svc-grid__none">{{ t('teamhub', 'Not assigned') }}</span>
						</td>
						<td>
							<NcChip
								no-close
								:variant="statusTone(request.status)"
								:text="statusLabel(request.status)" />
						</td>
						<td>
							<div class="svc-grid__decisions">
								<!-- v4.11.12 — the decisions behind the request,
								     each a pill that opens the decision's page.
								     **Read-only here**: a decision is linked from
								     the request's Messages tab, where the desk is
								     already writing to the requester (Justin,
								     2026-09-30), so the grid reports and never
								     edits.

								     A pill that is a link, so `NcChip` cannot be
								     it (/ui-standards § components): the two
								     `<a>` origin pills are the same carve-out.
								     A decision this reader may not open is not
								     drawn as a link at all — it says a decision
								     is there, without its words. -->
								<a
									v-for="decision in readableDecisions(request)"
									:key="decision.id"
									class="svc-grid__pill"
									:href="decisionUrl(decision.teamId, decision.id)"
									:title="pillTitle(decision)"
									@click="openDecision($event, decision)">
									<GavelIcon :size="ICON_INLINE" aria-hidden="true" />
									<span class="svc-grid__pill-text">{{ decision.question }}</span>
								</a>
								<span
									v-for="decision in hiddenDecisions(request)"
									:key="'x' + decision.id"
									class="svc-grid__pill svc-grid__pill--mute">
									<GavelIcon :size="ICON_INLINE" aria-hidden="true" />
									<!-- TRANSLATORS: stands in for a decision the reader is not allowed to open -->
									<span class="svc-grid__pill-text">{{ t('teamhub', 'A decision you cannot open') }}</span>
								</span>

							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="svc-grid__foot">
				<p class="svc-grid__count">
					{{ n('teamhub', '%n request', '%n requests', total, { n: total }) }}
					<span v-if="truncated">{{ t('teamhub', 'Older requests are not shown.') }}</span>
				</p>
				<div v-if="pages > 1" class="svc-grid__pages">
					<NcButton
						variant="secondary"
						size="small"
						:disabled="page === 0 || loading"
						@click="go(page - 1)">
						{{ t('teamhub', 'Previous') }}
					</NcButton>
					<span class="svc-grid__page">
						{{ t('teamhub', 'Page {page} of {pages}', { page: page + 1, pages }) }}
					</span>
					<NcButton
						variant="secondary"
						size="small"
						:disabled="page + 1 >= pages || loading"
						@click="go(page + 1)">
						{{ t('teamhub', 'Next') }}
					</NcButton>
				</div>
			</div>
		</template>

		</template>

		<!-- v4.11.12 — the verbs of the request the grid has open. They used to
		     hang off the queue widget’s dialog; the request is worked here now,
		     so they are here (a verb that needs a text asks for it first, as in
		     My Work). -->
		<WorkflowActionDialog
			v-if="workflowAction"
			:action="workflowAction.action"
			:subject="workflowAction.workflow.title"
			:workflow="workflowAction.workflow"
			:busy="workflows.busyId === workflowAction.workflow.id"
			@close="workflowAction = null"
			@submit="submitWorkflowAction" />
	</div>
</template>

<script>
import { mapState } from 'vuex'
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcChip, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'

import WorkflowActionDialog from '../mywork/WorkflowActionDialog.vue'
import WorkflowDetailView from '../mywork/WorkflowDetailView.vue'
import { loadDeskRequests } from '../../api/serviceTeams.js'
import { actionText, formatAbsolute, formatDay, isOpen, isoInstant, statusLabel, statusTone } from '../../constants/workflows.js'
import { decisionUrl, isPlainClick } from '../../lib/internalLinks.js'
import { decisionStatusLabel } from '../../constants/decisions.js'
import { AGENT_ACTION } from '../../constants/serviceTeams.js'
import { ICON_HERO, ICON_INLINE, ICON_LARGE } from '../../constants/uiTokens.js'

/** One page of the grid; the server uses the same number when none is sent. */
const PAGE = 25

export default {
	name: 'ServiceRequestsGrid',

	components: {
		NcButton, NcChip, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField,
		LifebuoyIcon, GavelIcon, WorkflowActionDialog, WorkflowDetailView,
	},

	data() {
		return {
			ICON_HERO,
			ICON_INLINE,
			ICON_LARGE,
			rows: [],
			total: 0,
			truncated: false,
			agents: [],
			requesters: [],
			loading: false,
			error: '',
			page: 0,
			state: 'all',
			assignee: null,
			requester: null,
			query: '',
			detailOpen: false,
			/** v4.11.12 — the verb waiting for its text, or null. */
			workflowAction: null,
			/** The search box waits for typing to stop before it asks. */
			searchTimer: null,
		}
	},

	computed: {
		...mapState(['currentTeamId']),
		...mapState({ workflows: state => state.workflows }),

		/** Who is claiming, when the request the grid has open is claimed. */
		currentUid() {
			return getCurrentUser()?.uid || ''
		},

		pages() {
			return Math.max(1, Math.ceil(this.total / PAGE))
		},

		stateOptions() {
			return [
				{ id: 'all', label: t('teamhub', 'All requests') },
				{ id: 'open', label: t('teamhub', 'Open') },
				{ id: 'closed', label: t('teamhub', 'Closed') },
			]
		},

		assigneeOptions() {
			return [
				// 'unassigned' is a value, not the absence of one: a desk
				// asking "what has nobody picked up" is asking for a filter.
				// Same words as the cell, so the filter and the rows it
				// returns read alike.
				// TRANSLATORS: filter value - requests no member of the service team has in hand yet
				{ id: 'unassigned', label: t('teamhub', 'Not assigned') },
				...this.agents.map(uid => ({ id: uid, label: this.displayName(uid) })),
			]
		},

		requesterOptions() {
			return this.requesters.map(uid => ({ id: uid, label: this.displayName(uid) }))
		},
	},

	watch: {
		currentTeamId() { this.go(0) },
		state() { this.go(0) },
		assignee() { this.go(0) },
		requester() { this.go(0) },
		query() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.go(0), 300)
		},
	},

	mounted() {
		this.reload()
		// v4.11.12 — a request somebody opened from the queue widget on the
		// team's home. The widget commits the id with the pane and the view
		// (`SET_SERVICES_TAB_REQUEST`); this is where it is honoured, so the
		// tab opens on the request rather than on the grid with the request
		// still to find. One-shot: cleared as soon as it is read, so coming
		// back to the grid later shows the grid.
		const handed = this.$store.state.servicesTabRequestId
		if (handed) {
			this.$store.commit('SET_SERVICES_TAB_REQUEST', null)
			this.detailOpen = true
			this.$store.dispatch('workflows/open', { id: handed, step: '' })
		}
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		t,
		n,
		formatAbsolute,
		formatDay,
		isoInstant,
		statusLabel,
		statusTone,
		decisionUrl,

		/**
		 * The pill's tooltip: the question and where the decision stands.
		 *
		 * The status is what tells a decision the desk **asked for** from one
		 * it **recorded** — the distinction Justin drew — and the cell is too
		 * narrow for a second chip per pill, so it rides on the title with the
		 * question it belongs to.
		 */
		pillTitle(decision) {
			const status = decisionStatusLabel(decision.status)
			if (!decision.question) {
				return status
			}
			// TRANSLATORS: tooltip on a decision pill — the decision's question, then where that decision stands
			return t('teamhub', '{question} — {status}', { question: decision.question, status })
		},

		/** The linked decisions this reader may open — drawn as links. */
		readableDecisions(request) {
			return (request.decisions || []).filter(d => d.canOpen)
		},

		/**
		 * The ones they may not. Kept visible on purpose: that a request was
		 * decided is the desk's own record, and hiding the row would make two
		 * agents of the same desk see a different history. The decision's
		 * words are what the server withholds, not its existence.
		 */
		hiddenDecisions(request) {
			return (request.decisions || []).filter(d => !d.canOpen)
		},

		/**
		 * Open a linked decision in the team's own Decisions tab rather than
		 * reloading the app into it — the grid's filters and page survive, and
		 * the decision arrives highlighted, the same handle `App.vue`'s
		 * `?team=…&decision=…` deep link resolves to.
		 *
		 * The pill stays a real `<a href>` on purpose: ctrl/cmd/middle click
		 * keeps its native meaning (`isPlainClick`), and anything that goes
		 * wrong here — a decision whose message cannot be resolved, an agent
		 * who is not a member of the team — simply lets the browser follow the
		 * link it was already pointing at.
		 */
		async openDecision(event, decision) {
			if (!isPlainClick(event) || decision.teamId !== this.currentTeamId) {
				return
			}
			// Held back before the await, not after: by the time a promise
			// resolves the browser has already navigated, so the fallback is
			// to follow the same href by hand.
			const href = event.currentTarget?.getAttribute('href') || ''
			event.preventDefault()

			let messageId = null
			try {
				const { data } = await axios.get(generateUrl(
					'/apps/teamhub/api/v1/teams/' + encodeURIComponent(decision.teamId)
					+ '/decisions/' + encodeURIComponent(decision.id),
				))
				messageId = data?.messageId || null
			} catch (e) {
				messageId = null
			}
			if (!messageId) {
				if (href) {
					window.location.assign(href)
				}
				return
			}
			this.$store.commit('SET_VIEW', 'decisions')
			this.$nextTick(() => this.$store.commit('SET_DECISIONS_TARGET', messageId))
		},

		go(page) {
			this.page = Math.max(0, page)
			this.reload()
		},

		async reload() {
			if (!this.currentTeamId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const data = await loadDeskRequests(this.currentTeamId, {
					state: this.state,
					assignee: this.assignee || '',
					requester: this.requester || '',
					q: this.query.trim(),
					offset: this.page * PAGE,
					limit: PAGE,
				})
				this.rows = data.requests
				this.total = data.total
				this.truncated = data.truncated
				this.agents = data.agents
				this.requesters = data.requesters
			} catch (e) {
				this.error = e?.response?.data?.error || t('teamhub', 'The requests could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/** A uid's display name from the row the server resolved it on. */
		nameOf(request, uid) {
			if (!uid) {
				return ''
			}
			return (request?.people || {})[uid] || uid
		},

		/** For the pickers, where no single row carries the name. */
		displayName(uid) {
			for (const row of this.rows) {
				const name = (row.people || {})[uid]
				if (name) {
					return name
				}
			}
			return uid
		},

		open(request) {
			this.detailOpen = true
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
			this.$store.dispatch('workflows/close')
		},

		/**
		 * A verb pressed in the request the grid has open (v4.11.12). The same
		 * shape My Work uses — a verb that needs a text asks for it first — so
		 * a request behaves the same wherever it is worked.
		 */
		onWorkflowAction({ workflow, action, direct = false }) {
			if (this.workflows.busyId !== null) {
				return
			}
			// Claiming is the desk's own verb and goes through the service-team
			// store, which is what keeps the queue and its badge in step.
			if (action === AGENT_ACTION.CLAIM) {
				this.claimAndReopen(workflow)
				return
			}
			if (direct || actionText(action) === 'none') {
				this.run(workflow, action, '')
				return
			}
			this.workflowAction = { workflow, action }
		},

		/** `files` from the dialog's paperclip. */
		submitWorkflowAction(text, files = []) {
			if (this.workflowAction) {
				this.run(this.workflowAction.workflow, this.workflowAction.action, text, files)
			}
		},

		async claimAndReopen(workflow) {
			const result = await this.$store.dispatch('serviceTeams/act', {
				id: workflow.id,
				action: AGENT_ACTION.CLAIM,
				uid: this.currentUid,
				step: workflow.viewer?.stepKey || '',
			})
			if (result.ok) {
				showSuccess(t('teamhub', 'Claimed: {title}', { title: workflow.title }))
				// Re-read the request: what the viewer may do next changed.
				this.$store.dispatch('workflows/open', { id: workflow.id, step: workflow.viewer?.stepKey || '' })
				this.reload()
			} else if (!result.error?.busy) {
				showError(result.error?.message || t('teamhub', 'The request could not be claimed.'))
			}
		},

		async run(workflow, action, text, files = []) {
			try {
				const updated = await this.$store.dispatch('workflows/act', {
					id: workflow.id,
					action,
					text,
					step: workflow.viewer?.stepKey || '',
					fileIds: (files || []).map(f => f.fileId),
				})
				this.workflowAction = null
				showSuccess(t('teamhub', 'Done: {title}', { title: updated?.title || workflow.title }))
				// A request that is still open stays on the page, re-read; one
				// that has ended hands the page back to the grid.
				if (updated && isOpen(updated) && this.workflows.detail?.id === updated.id) {
					this.$store.dispatch('workflows/open', { id: updated.id, step: updated.viewer?.stepKey || '' })
				} else {
					this.close()
				}
			} catch (e) {
				if (e?.status === 0) {
					return // busy — the first press is still running
				}
				showError(e?.message || t('teamhub', 'That action could not be completed.'))
				if (e?.status === 409) {
					this.workflowAction = null
				}
			} finally {
				// The row's status, assignee and date may all have moved.
				this.reload()
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-grid {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.svc-grid__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-sm, 8px);
}

.svc-grid__search {
	flex: 1 1 240px;
	min-width: 0;
}

.svc-grid__filter {
	flex: 0 1 200px;
	min-width: 0;
}

.svc-grid__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-xl, 24px);
}

.svc-grid__table {
	inline-size: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: var(--th-space-sm, 8px);
		text-align: start;
		vertical-align: top;
		border-block-end: 1px solid var(--color-border);
	}

	th {
		color: var(--color-text-maxcontrast);
		font-size: var(--th-font-meta, 13px);
		font-weight: 600;
	}

	td {
		font-size: var(--th-font-meta, 13px);
	}

	tbody tr:hover {
		background: var(--color-background-hover);
	}
}

.svc-grid__title {
	max-width: 100%;

	:deep(.button-vue__text) {
		white-space: normal;
		text-align: start;
	}
}

/**
 * v4.11.12 — a request nobody has picked up is the one thing on this grid a
 * desk still has to act on, so it is written in the theme's own accent rather
 * than greyed out like an absent value. `--color-primary-element` is the
 * variant NC keeps readable on the canvas in both themes; the words carry the
 * meaning on their own, the colour only draws the eye.
 */
.svc-grid__none {
	color: var(--color-primary-element);
	font-weight: 500;
}

/* A date is read as one token; wrapping "30-9-2026" over two lines is noise. */
.svc-grid__date-col {
	white-space: nowrap;
}

.svc-grid__decisions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs, 4px);
}

/**
 * v4.11.12 — the decision pill. `NcChip` cannot be a link, which is the
 * documented carve-out this shares with the two origin pills in the widget
 * styles; the tint and ink are NC's own pair, so it reads as one of them.
 */
.svc-grid__pill {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	max-inline-size: 22ch;
	padding: 0 var(--th-space-sm, 8px);
	border: 2px solid transparent;
	border-radius: var(--th-radius-pill, var(--border-radius-pill));
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 13px);
	line-height: var(--clickable-area-small, 24px);
	text-decoration: none;

	&:hover {
		background: var(--color-primary-element-light-hover);
	}

	/* Split from :hover so the keyboard ring is never silenced with it. */
	&:focus-visible {
		background: var(--color-primary-element-light-hover);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	/* A decision this reader may not open: still a pill, not a link. */
	&--mute {
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}
}

.svc-grid__pill-text {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}

.svc-grid__foot {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--th-space-sm, 8px);
}

.svc-grid__count {
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-grid__pages {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
}

.svc-grid__page {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}
</style>
