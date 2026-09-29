<template>
	<div class="svc-queue-w">
		<!-- The three tabs. A toggle group of NcButtons rather than a tab bar:
		     the widget is narrow, the content below is one list whichever tab
		     is pressed, and NC has no tab component for a content switch. -->
		<div class="svc-queue-w__tabs" role="group" :aria-label="t('teamhub', 'Show requests')">
			<NcButton
				v-for="tab in tabs"
				:key="tab"
				size="small"
				variant="tertiary"
				:pressed="activeTab === tab"
				@click="activeTab = tab">
				<!-- One inline row, so the count sits behind the label rather
				     than wrapping under it inside the button's text slot. -->
				<span class="svc-queue-w__tab-label">
					{{ queueTabLabel(tab) }}
					<NcCounterBubble
						v-if="tab !== QUEUE_TAB.CLOSED && count(tab) > 0"
						class="svc-count"
						:class="{ 'svc-count--on': activeTab === tab }"
						:count="count(tab)" />
				</span>
			</NcButton>
		</div>

		<NcNoteCard v-if="error && !busy" type="error">
			{{ error.message || t('teamhub', 'Could not load the requests.') }}
			<template #action>
				<NcButton variant="secondary" @click="reload">{{ t('teamhub', 'Try again') }}</NcButton>
			</template>
		</NcNoteCard>

		<div v-else-if="loading && !rows.length" class="svc-queue-w__loading">
			<NcLoadingIcon :size="ICON_LARGE" />
		</div>

		<p v-else-if="!rows.length" class="svc-queue-w__empty">{{ queueTabEmptyState(activeTab) }}</p>

		<ul v-else class="svc-queue-w__list">
			<li v-for="request in rows" :key="rowKey(request)" class="svc-queue-w__row">
				<div class="svc-queue-w__main">
					<!-- A tertiary button so the title is keyboard-reachable and
					     opens the request; it should read as a title. -->
					<NcButton
						variant="tertiary"
						class="svc-queue-w__title"
						:aria-label="t('teamhub', 'Open details of {title}', { title: request.title })"
						@click="openRequest(request)">
						{{ request.title }}
					</NcButton>

					<!-- v4.10.44 — two lines per row (Justin, 2026-09-25): the title,
					     then the task and the role it needs — and who has it, or
					     what it waits for — on one line. Who asked and when are in
					     the detail view; so are the task's links. -->
					<WorkflowTaskMeta
						v-if="activeTab !== QUEUE_TAB.CLOSED"
						class="svc-queue-w__meta"
						:step="currentStep(request)"
						:extra="rowExtra(request)" />

					<template v-else>
						<div class="svc-queue-w__meta">
							<span>{{ t('teamhub', 'Asked by {name}', { name: nameOf(request, request.startedBy) }) }}</span>
							<span v-if="request.desk" :title="formatAbsolute(request.desk.closedAt)">
								{{ formatRecent(request.desk.closedAt) }}
							</span>
						</div>
						<div v-if="request.desk" class="svc-queue-w__chips">
							<NcChip
								no-close
								:variant="deskOutcomeVariant(request.desk.status)"
								:text="deskOutcomeLabel(request.desk.status)" />
						</div>
					</template>
				</div>

				<div class="svc-queue-w__actions">
					<NcButton
						v-if="request.internal && request.internal.claimable"
						variant="secondary"
						size="small"
						:disabled="busy"
						@click="claim(request)">
						<template v-if="busyId === request.id" #icon><NcLoadingIcon :size="ICON_INLINE" /></template>
						{{ agentActionLabel(AGENT_ACTION.CLAIM) }}
					</NcButton>

					<NcActions
						v-if="menuActions(request).length"
						:aria-label="t('teamhub', 'More actions for {title}', { title: request.title })"
						:disabled="busy">
						<NcActionButton
							v-for="action in menuActions(request)"
							:key="action"
							close-after-click
							@click="startDialog(request, action)">
							<template #icon>
								<component :is="actionIcon(action)" :size="ICON_BODY" />
							</template>
							{{ menuLabel(action) }}
						</NcActionButton>
					</NcActions>
				</div>
			</li>
		</ul>

		<!-- Reassign, set back to unclaimed, or add an internal note. One small
		     dialog for the three, because each asks one thing. -->
		<NcModal
			v-if="dialog"
			label-id="svc-queue-w-dialog-title"
			size="small"
			@close="dialog = null">
			<div class="svc-queue-w__dialog">
				<h3 id="svc-queue-w-dialog-title" class="svc-queue-w__dialog-title">{{ menuLabel(dialog.action) }}</h3>
				<p class="svc-queue-w__dialog-subject">{{ dialog.request.title }}</p>

				<label v-if="dialog.action === AGENT_ACTION.ASSIGN" class="svc-queue-w__field">
					<span>{{ t('teamhub', 'Team member') }}</span>
					<NcSelect
						v-model="dialog.value"
						input-id="svc-queue-w-assignee"
						label-outside
						:options="assignableAgents(dialog.request)"
						:reduce="o => o.id"
						:clearable="false"
						:placeholder="t('teamhub', 'Choose a team member')"
						:aria-label-combobox="t('teamhub', 'Team member')" />
				</label>

				<label v-else class="svc-queue-w__field">
					<span>{{ dialog.action === AGENT_ACTION.INTERNAL_NOTE ? t('teamhub', 'Internal note') : t('teamhub', 'Reason') }}</span>
					<NcTextArea
						v-model="dialog.value"
						rows="3"
						maxlength="1000"
						label-outside
						:aria-label="dialog.action === AGENT_ACTION.INTERNAL_NOTE ? t('teamhub', 'Internal note') : t('teamhub', 'Reason')"
						:placeholder="dialog.action === AGENT_ACTION.INTERNAL_NOTE
							? t('teamhub', 'Only your team can read this')
							: t('teamhub', 'Optional')" />
				</label>
				<!-- v4.10.38 — an internal note's files go to the team, and stay the team's. -->
				<FileAttachField
					v-if="dialog.action === AGENT_ACTION.INTERNAL_NOTE && fileSharingOf(dialog.request).allowed"
					v-model="dialog.files"
					:note="shareNote(fileSharingOf(dialog.request), t('teamhub', 'your team'))"
					:disabled="busy" />

				<div class="svc-queue-w__dialog-buttons">
					<NcButton variant="tertiary" :disabled="busy" @click="dialog = null">{{ t('teamhub', 'Cancel') }}</NcButton>
					<NcButton variant="primary" :disabled="busy || !dialogReady" @click="submitDialog">
						<template v-if="busy" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
						{{ menuLabel(dialog.action) }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Processing a request is the workflow's own detail and actions —
		     complete, reject, ask the requester — exactly as in My Work, so
		     the two places a member works a request can never disagree. -->
		<WorkflowDetailModal
			v-if="detailOpen"
			:workflow="workflows.detail"
			:loading="workflows.detailLoading"
			:error="workflows.detailError"
			:busy="workflows.busyId !== null && !!workflows.detail && workflows.busyId === workflows.detail.id"
			:display-names="workflows.detail ? (workflows.detail.people || {}) : {}"
			@close="closeRequest"
			@retry="reopenRequest"
			@action="onWorkflowAction"
			@changed="reload" />
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
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcActionButton, NcActions, NcButton, NcChip, NcCounterBubble, NcLoadingIcon,
	NcModal, NcNoteCard, NcSelect, NcTextArea,
} from '@nextcloud/vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import LockOpenVariantOutline from 'vue-material-design-icons/LockOpenVariantOutline.vue'
import NoteEditOutline from 'vue-material-design-icons/NoteEditOutline.vue'

import WorkflowActionDialog from './mywork/WorkflowActionDialog.vue'
import WorkflowDetailModal from './mywork/WorkflowDetailModal.vue'
import WorkflowTaskMeta from './mywork/WorkflowTaskMeta.vue'
import FileAttachField from './mywork/FileAttachField.vue'
import {
	AGENT_ACTION, QUEUE_TAB, QUEUE_TABS,
	agentActionLabel, assignableAgents, assigneeLabel, deskOutcomeLabel, deskOutcomeVariant,
	queueTabEmptyState, queueTabLabel, queueTabRows,
} from '../constants/serviceTeams.js'
import { actionText, activeStep, fileSharingOf, formatAbsolute, formatRecent, isOpen, rowKey, shareNote } from '../constants/workflows.js'
import { ICON_BODY, ICON_INLINE, ICON_LARGE } from '../constants/uiTokens.js'

/**
 * The service team's queue (WorkflowHub phase B, v4.10.27) — a widget on
 * the team's own home, because the desk *is* the team (`/service-teams`).
 *
 * Three tabs: **New** (nobody has it), **Claimed** (the viewer's first,
 * then the team's) and **Closed** (what the desk finished in the last 30
 * days). Any member claims and processes; processing is the workflow's own
 * detail view, the same one My Work opens, so a request worked here and a
 * request worked there are one request. Team admins additionally reassign
 * and set a request back to unclaimed.
 *
 * **Every action is offered from the server's `internal` block alone** —
 * `claimable`, `canAssign`, `canRelease` — and hidden, not disabled, when
 * the server says no (CLAUDE.md § Permissions). This component decides
 * nothing about who may do what.
 */
export default {
	name: 'ServiceQueueWidget',

	components: {
		NcActionButton, NcActions, NcButton, NcChip, NcCounterBubble, NcLoadingIcon,
		NcModal, NcNoteCard, NcSelect, NcTextArea,
		WorkflowActionDialog, WorkflowDetailModal, WorkflowTaskMeta, FileAttachField,
		AccountArrowRight, LockOpenVariantOutline, NoteEditOutline,
	},

	data() {
		return {
			AGENT_ACTION,
			QUEUE_TAB,
			ICON_BODY,
			ICON_INLINE,
			ICON_LARGE,
			tabs: QUEUE_TABS,
			activeTab: QUEUE_TAB.NEW,
			/** `{ request, action, value }` while an agent dialog is open. */
			dialog: null,
			detailOpen: false,
			/** `{ workflow, action }` while a workflow verb collects its text. */
			workflowAction: null,
		}
	},

	computed: {
		...mapState(['currentTeamId']),
		...mapState('serviceTeams', ['queue', 'loading', 'busyId', 'error', 'selectedId']),
		...mapState({ workflows: state => state.workflows }),

		currentUid() {
			return getCurrentUser()?.uid || ''
		},

		busy() {
			return this.busyId !== null
		},

		/**
		 * The queue on screen is this team's only once the store has
		 * selected it; until then the previous team's rows must not flash
		 * up under this team's name.
		 */
		rows() {
			return this.selectedId === this.currentTeamId ? queueTabRows(this.queue, this.activeTab) : []
		},

		dialogReady() {
			if (!this.dialog) {
				return false
			}
			if (this.dialog.action === AGENT_ACTION.ASSIGN) {
				return !!this.dialog.value
			}
			if (this.dialog.action === AGENT_ACTION.INTERNAL_NOTE) {
				return String(this.dialog.value || '').trim() !== ''
			}
			return true
		},
	},

	watch: {
		currentTeamId: {
			immediate: true,
			handler(teamId) {
				if (teamId) {
					this.activeTab = QUEUE_TAB.NEW
					this.$store.dispatch('serviceTeams/select', teamId)
				}
			},
		},
	},

	methods: {
		t,
		rowKey,
		fileSharingOf,
		shareNote,
		currentStep(request) { return activeStep(request) },
		/**
		 * v4.10.44 — what else goes on the task line: who has it (Claimed),
		 * or what the requester still has to do (With requester).
		 */
		rowExtra(request) {
			if (this.activeTab === QUEUE_TAB.CLAIMED && request.internal?.assignee) {
				// TRANSLATORS: before the name of the team member who has a task, e.g. "With: Jaap"
				return [{ key: 'with', label: t('teamhub', 'With:'), value: this.isMine(request) ? t('teamhub', 'You') : (request.internal.assigneeName || request.internal.assignee) }]
			}
			if (this.activeTab === QUEUE_TAB.WITH_REQUESTER) {
				const step = activeStep(request)
				// TRANSLATORS: before the requester's step a request waits on, e.g. "Waiting for: Sign the agreement"
				return step?.label ? [{ key: 'waiting', label: t('teamhub', 'Waiting for:'), value: step.label }] : []
			}
			return []
		},
		agentActionLabel,
		assignableAgents,
		assigneeLabel,
		deskOutcomeLabel,
		deskOutcomeVariant,
		formatAbsolute,
		formatRecent,
		queueTabEmptyState,
		queueTabLabel,

		count(tab) {
			return this.selectedId === this.currentTeamId ? queueTabRows(this.queue, tab).length : 0
		},

		reload() {
			if (this.currentTeamId) {
				this.$store.dispatch('serviceTeams/loadQueue', this.currentTeamId)
			}
		},

		isMine(request) {
			return request.internal?.assignee === this.currentUid
		},

		nameOf(request, uid) {
			return request.people?.[uid] || uid || ''
		},

		/**
		 * The row's menu: an internal note for anybody on the desk (the
		 * `internal` block's presence is that), reassigning and unclaiming
		 * where the server said so. Claiming has its own button.
		 */
		menuActions(request) {
			const internal = request.internal
			if (!internal || this.activeTab === QUEUE_TAB.CLOSED) {
				return []
			}
			const out = []
			if (internal.canAssign) {
				out.push(AGENT_ACTION.ASSIGN)
			}
			if (internal.canRelease) {
				out.push(AGENT_ACTION.RELEASE)
			}
			out.push(AGENT_ACTION.INTERNAL_NOTE)
			return out
		},

		menuLabel(action) {
			switch (action) {
			case AGENT_ACTION.ASSIGN:
				// TRANSLATORS: menu item - a team admin hands a request to another team member
				return t('teamhub', 'Reassign')
			case AGENT_ACTION.RELEASE:
				// TRANSLATORS: menu item - put a claimed request back in the New tab
				return t('teamhub', 'Set as unclaimed')
			default:
				return agentActionLabel(action)
			}
		},

		actionIcon(action) {
			switch (action) {
			case AGENT_ACTION.ASSIGN: return 'AccountArrowRight'
			case AGENT_ACTION.RELEASE: return 'LockOpenVariantOutline'
			default: return 'NoteEditOutline'
			}
		},

		async claim(request) {
			const result = await this.$store.dispatch('serviceTeams/act', {
				id: request.id, action: AGENT_ACTION.CLAIM, uid: this.currentUid, step: request.viewer?.stepKey || '',
			})
			if (result.ok) {
				showSuccess(t('teamhub', 'Claimed: {title}', { title: request.title }))
			} else if (!result.error?.busy) {
				showError(result.error?.message || t('teamhub', 'The request could not be claimed.'))
			}
		},

		async claimFromDetail(workflow) {
			await this.claim(workflow)
			this.$store.dispatch('workflows/open', { id: workflow.id, step: workflow.viewer?.stepKey || '' })
		},

		startDialog(request, action) {
			this.dialog = { request, action, value: '', files: [] }
		},

		async submitDialog() {
			if (!this.dialogReady) {
				return
			}
			const { request, action, value } = this.dialog
			const result = await this.$store.dispatch('serviceTeams/act', {
				id: request.id, action, uid: this.currentUid, text: String(value || '').trim(), step: request.viewer?.stepKey || '',
				fileIds: (this.dialog.files || []).map(f => f.fileId),
			})
			if (result.ok) {
				this.dialog = null
				showSuccess(action === AGENT_ACTION.INTERNAL_NOTE
					? t('teamhub', 'Note added')
					: t('teamhub', 'Done: {title}', { title: request.title }))
			} else if (!result.error?.busy) {
				showError(result.error?.message || t('teamhub', 'That action could not be completed.'))
			}
		},

		/** v4.10.37 — opened on the row's own task. */
		openRequest(request) {
			this.detailOpen = true
			this.$store.dispatch('workflows/open', { id: request.id, step: request.viewer?.stepKey || '' })
		},

		reopenRequest() {
			const detail = this.workflows.detail
			if (detail?.id) {
				this.$store.dispatch('workflows/open', { id: detail.id, step: detail.viewer?.stepKey || '' })
			}
		},

		closeRequest() {
			this.detailOpen = false
			this.$store.dispatch('workflows/close')
		},

		/** A verb from the detail view. Those that take a text ask for it first. */
		onWorkflowAction({ workflow, action, direct = false }) {
			if (this.workflows.busyId !== null) {
				return
			}
			// v4.10.39 — claiming from the detail view: the task it is open on.
			if (action === AGENT_ACTION.CLAIM) {
				this.claimFromDetail(workflow)
				return
			}
			if (direct || actionText(action) === 'none') {
				this.runWorkflowAction(workflow, action, '')
				return
			}
			this.workflowAction = { workflow, action }
		},

		/** v4.10.38 — `files` from the dialog's paperclip. */
		submitWorkflowAction(text, files = []) {
			if (this.workflowAction) {
				this.runWorkflowAction(this.workflowAction.workflow, this.workflowAction.action, text, files)
			}
		},

		async runWorkflowAction(workflow, action, text, files = []) {
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
					this.closeRequest()
				}
			} catch (e) {
				if (e?.status === 0) {
					return // busy — the first click is still running
				}
				showError(e?.message || t('teamhub', 'That action could not be completed.'))
				if (e?.status === 409) {
					this.workflowAction = null
				}
			} finally {
				// Answering or rejecting moves the request to Closed; asking the
				// requester keeps it where it is. Either way the queue is re-read
				// rather than guessed at.
				this.reload()
			}
		},
	},
}
</script>

<style scoped lang="scss">
.svc-queue-w {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding-block: var(--th-space-sm, 8px);
	/* v4.10.43 — with the rows' own 8px, content sits 16px from the card's
	   edge like the other widgets (the 4.10.x padding check). */
	padding-inline: var(--th-space-sm, 8px);
}

.svc-queue-w__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	padding-inline: var(--th-space-sm, 8px);
}

.svc-queue-w__tab-label {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	white-space: nowrap;
}

/* The count inverts its button (Justin, 2026-09-24): the bubble takes the
   button's ink as its fill and the button's fill as its number, so it stands
   out on a pressed (primary) tab and on an unpressed (tertiary) one alike.
   Scoped under the tab row so it outranks NcCounterBubble's own two-class
   rule without !important. */
.svc-queue-w__tabs .svc-count {
	background-color: var(--color-main-text);
	color: var(--color-main-background);
}

.svc-queue-w__tabs .svc-count--on {
	background-color: var(--color-primary-element-text);
	color: var(--color-primary-element);
}

.svc-queue-w__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg, 16px);
}

.svc-queue-w__empty {
	margin: 0;
	padding-inline: var(--th-space-md, 12px);
	color: var(--th-widget-meta-color, var(--color-text-maxcontrast));
	font-size: var(--th-font-meta, 13px);
}

.svc-queue-w__list {
	display: flex;
	flex-direction: column;
	list-style: none;
	margin: 0;
	padding: 0;
}

.svc-queue-w__row {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-xs, 4px);
	padding: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	border-radius: var(--th-radius-control, var(--border-radius-element));

	&:hover {
		background: var(--color-background-hover);
	}
}

.svc-queue-w__main {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-queue-w__title {
	max-width: 100%;
	font-weight: var(--th-widget-row-primary-weight, 500);

	/* A request title is a sentence ("Request a shared folder: …"); in the
	   narrow right column NcButton's one-line ellipsis cut it to "R…" on the
	   instance. Wrap it instead — layout only, the button keeps its chrome. */
	:deep(.button-vue__text) {
		white-space: normal;
		text-align: start;
	}
}

/* The widget measures itself, not the viewport: it lives in a 3-of-12
   column on a wide screen and full width on a phone. When it is narrow the
   row's buttons move under the title instead of squeezing it. */
.svc-queue-w {
	container-type: inline-size;
}

@container (max-width: 420px) {
	.svc-queue-w__row {
		flex-wrap: wrap;
	}

	.svc-queue-w__main {
		flex-basis: 100%;
	}

	.svc-queue-w__actions {
		padding-inline-start: var(--th-space-sm, 8px);
	}
}

.svc-queue-w__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0 var(--th-space-sm, 8px);
	padding-inline-start: var(--th-space-sm, 8px);
	color: var(--th-widget-meta-color, var(--color-text-maxcontrast));
	font-size: var(--th-font-meta, 13px);
}

.svc-queue-w__chips {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	padding-inline-start: var(--th-space-sm, 8px);

	&:empty {
		display: none;
	}
}

.svc-queue-w__actions {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
}

.svc-queue-w__dialog {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-lg, 16px);
}

.svc-queue-w__dialog-title {
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

.svc-queue-w__dialog-subject {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.svc-queue-w__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-queue-w__dialog-buttons {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-sm, 8px);
}
</style>
