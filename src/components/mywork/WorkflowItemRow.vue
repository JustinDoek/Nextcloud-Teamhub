<template>
	<!-- v4.10.15 — one workflow instance in My Work's workflow sections.
	     Not a MyWorkItemRow: an aggregated item is a task from a source,
	     a workflow is a request travelling between people, and the two
	     say different things — a step, a position in the whole, who is
	     responsible. -->
	<li
		class="wf-row"
		:class="{ 'wf-row--busy': busy, 'wf-row--action-required': actionRequired }"
		:aria-busy="busy ? 'true' : 'false'">
		<!-- v4.10.20 — decorative. The word is on the chip beside the title:
		     workflow rows and aggregated rows share one list, and a glyph on
		     its own was leaving people to guess which kind they were reading
		     (Justin, 2026-09-22). -->
		<SourceBranch :size="iconBody" class="wf-row__glyph" aria-hidden="true" />

		<div class="wf-row__main">
			<div class="wf-row__head">
				<!-- The title opens the detail view. A button, not a link:
				     nothing navigates, a modal opens. -->
				<NcButton
					variant="tertiary"
					class="wf-row__title"
					:aria-label="t('teamhub', 'Open details of {title}', { title: workflow.title })"
					:title="t('teamhub', 'Open details')"
					@click="$emit('open', workflow)">
					{{ workflow.title }}
				</NcButton>
				<!-- Marks the row as a workflow, in the same words and the
				     same glyph MyWorkItemRow uses for a task that is one step
				     of one. -->
				<!-- v4.10.44 — a request a service team handles reads *Service*. -->
				<NcChip
					no-close
					variant="tertiary"
					class="wf-row__flag"
					:text="isService ? t('teamhub', 'Service') : t('teamhub', 'Workflow')">
					<template #icon>
						<LifebuoyIcon v-if="isService" :size="iconInline" />
						<SourceBranch v-else :size="iconInline" />
					</template>
				</NcChip>
				<!-- v4.10.32 — the viewer's own work, from the server's
				     `viewer.actionRequired` (was "You are responsible", on
				     isResponsible: that also marked an unclaimed desk request
				     on every member of the desk). -->
				<NcChip
					v-if="actionRequired"
					no-close
					variant="primary"
					class="wf-row__flag"
					:text="t('teamhub', 'Action required')">
					<template #icon><AccountCheckOutline :size="iconInline" /></template>
				</NcChip>
				<NcChip
					v-else-if="workflow.status === 'waiting' || workflow.status === 'blocked'"
					no-close
					variant="warning"
					class="wf-row__flag"
					:text="statusLabel(workflow.status)" />
			</div>

			<div class="wf-row__meta">
				<span v-if="workflow.teamName && !workflow.personal" class="wf-row__team">{{ workflow.teamName }}</span>
				<!-- v4.10.16 — the step and the position in the whole are
				     licensed; an unlicensed view carries no step list and
				     these two simply do not render. Who is responsible does. -->
				<!-- v4.10.44 — the task and the role it needs, as in the service
				     team's queue: words in grey, values in the primary colour. -->
				<WorkflowTaskMeta v-if="step" :step="step" />
				<span v-if="progressText" class="wf-row__progress">{{ progressText }}</span>
				<span v-if="!actionRequired && actor.label" class="wf-row__actor">
					<span class="wf-row__actor-label">{{ t('teamhub', 'Waiting for') }}</span>
					<NcUserBubble
						v-if="actor.uid"
						:user="actor.uid"
						:display-name="actor.label"
						:size="avatarSize" />
					<span v-else>{{ actor.label }}</span>
				</span>
				<span v-if="workflow.updatedAt" class="wf-row__updated" :title="formatAbsolute(workflow.updatedAt)">
					{{ t('teamhub', 'Updated {time}', { time: formatRecent(workflow.updatedAt) }) }}
				</span>
				<span v-if="workflow.dueAt" class="wf-row__due" :title="formatAbsolute(workflow.dueAt)">
					{{ t('teamhub', 'Due {time}', { time: formatRecent(workflow.dueAt) }) }}
				</span>
			</div>

			<!-- v4.10.36 — the current step's links: the form to fill in, the
			     page to open. v4.10.37 — on the requester's own task, pressing
			     one is doing the task. -->
			<WorkflowStepLinks
				v-if="step"
				:step="step"
				:completes="completesByLink"
				:variant="completesByLink ? 'secondary' : 'tertiary'"
				class="wf-row__links"
				@done="$emit('action', { workflow, action: 'complete', direct: true })" />
		</div>

		<div class="wf-row__actions">
			<!-- v4.10.39 — an unclaimed team task is claimed first. -->
			<NcButton
				v-if="claimable"
				variant="secondary"
				:disabled="busy"
				@click="$emit('action', { workflow, action: 'claim' })">
				<template v-if="busy" #icon><NcLoadingIcon :size="iconBody" /></template>
				{{ claimLabel }}
			</NcButton>
			<NcButton
				v-else-if="primary"
				variant="secondary"
				:disabled="busy"
				@click="$emit('action', { workflow, action: primary })">
				<template v-if="busy" #icon><NcLoadingIcon :size="iconBody" /></template>
				{{ actionLabel(primary, workflow) }}
			</NcButton>
			<NcActions
				v-if="secondary.length"
				:aria-label="t('teamhub', 'More actions for {title}', { title: workflow.title })"
				:disabled="busy">
				<NcActionButton
					v-for="a in secondary"
					:key="a"
					:close-after-click="true"
					@click="$emit('action', { workflow, action: a })">
					<template #icon>
						<component :is="actionIcon(a)" :size="iconBody" />
					</template>
					{{ actionLabel(a, workflow) }}
				</NcActionButton>
				<NcActionSeparator />
				<NcActionButton :close-after-click="true" @click="$emit('open', workflow)">
					<template #icon><InformationOutline :size="iconBody" /></template>
					{{ t('teamhub', 'Open details') }}
				</NcActionButton>
			</NcActions>
		</div>
	</li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcChip, NcActions, NcActionButton, NcActionSeparator, NcLoadingIcon, NcUserBubble } from '@nextcloud/vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import AccountClockOutline from 'vue-material-design-icons/AccountClockOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import ArchiveCheckOutline from 'vue-material-design-icons/ArchiveCheckOutline.vue'
import WorkflowStepLinks from './WorkflowStepLinks.vue'
import WorkflowTaskMeta from './WorkflowTaskMeta.vue'
import {
	WORKFLOW_ACTION,
	activeStep,
	actorDescription,
	responsibleActor,
	actionLabel,
	availableActions,
	progressLabel,
	statusLabel,
	formatRecent,
	formatAbsolute,
	isServiceRequest,
} from '../../constants/workflows.js'
import { ICON_INLINE, ICON_BODY, AVATAR_SM } from '../../constants/uiTokens.js'
import { AGENT_ACTION, agentActionLabel } from '../../constants/serviceTeams.js'

const ACTION_ICONS = {
	[WORKFLOW_ACTION.COMPLETE]: Check,
	[WORKFLOW_ACTION.REJECT]: Close,
	[WORKFLOW_ACTION.REQUEST_INFORMATION]: HelpCircleOutline,
	[WORKFLOW_ACTION.PROVIDE_INFORMATION]: CommentOutline,
	[WORKFLOW_ACTION.REQUEST_STATUS]: AccountClockOutline,
	[WORKFLOW_ACTION.CANCEL]: CloseCircleOutline,
	// v4.10.32 — the glyph My Work's own Close uses (constants/myWork.js).
	[WORKFLOW_ACTION.CLOSE]: ArchiveCheckOutline,
}

export default {
	name: 'WorkflowItemRow',
	components: {
		NcButton, NcChip, NcActions, NcActionButton, NcActionSeparator, NcLoadingIcon, NcUserBubble,
		SourceBranch, LifebuoyIcon, AccountCheckOutline, InformationOutline, WorkflowStepLinks, WorkflowTaskMeta,
	},
	props: {
		/** A workflow view from GET /api/v1/workflows. */
		workflow: { type: Object, required: true },
		/** True while an action on this workflow is in flight. */
		busy: { type: Boolean, default: false },
	},
	emits: ['open', 'action'],
	computed: {
		iconInline() { return ICON_INLINE },
		iconBody() { return ICON_BODY },
		avatarSize() { return AVATAR_SM },
		/** v4.10.32 — older payloads carry only isResponsible; see partition(). */
		actionRequired() { return !!(this.workflow.viewer?.actionRequired ?? this.workflow.viewer?.isResponsible) },
		step() { return activeStep(this.workflow) },
		/**
		 * v4.10.37 — the requester's own open task: its links complete it.
		 * The server says whether the viewer may act on the task (`canAct`);
		 * a team task's links are material to read, never a completion.
		 */
		completesByLink() {
			return !!this.step?.canAct && this.step?.actor?.type === 'user'
		},
		/** Shown on both tiers: the server sends `responsible` even when it sends no steps. */
		actor() { return actorDescription(responsibleActor(this.workflow)) },
		progressText() { return progressLabel(this.workflow) },
		actions() { return availableActions(this.workflow) },
		/** v4.10.44 — handled by a service team: a *Service* row. */
		isService() { return isServiceRequest(this.workflow) },
		/** v4.10.39 — an open team task nobody has claimed yet. */
		claimable() { return !!this.workflow.internal?.claimable && !['completed', 'rejected', 'cancelled'].includes(this.workflow.status) },
		claimLabel() { return agentActionLabel(AGENT_ACTION.CLAIM) },
		/** The first available action is the row's button; the rest go in the menu. */
		primary() { return this.actions[0] || null },
		secondary() { return this.actions.slice(1) },
	},
	methods: {
		t,
		n,
		actionLabel,
		statusLabel,
		formatRecent,
		formatAbsolute,
		actionIcon(action) {
			return ACTION_ICONS[action] || InformationOutline
		},
	},
}
</script>

<style scoped lang="scss">
.wf-row {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-sm, 8px) var(--th-space-lg, 16px);
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);

	&:hover {
		background: var(--color-background-hover);
	}
}

.wf-row--busy {
	opacity: 0.7;
}

.wf-row__glyph {
	flex: 0 0 auto;
	margin-block-start: var(--th-space-sm, 8px);
	color: var(--color-text-maxcontrast);
}

.wf-row__main {
	flex: 1 1 auto;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.wf-row__head {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
	min-width: 0;
}

/* The title is a tertiary button so it is keyboard-reachable; it should
   read as a title, not as a button, so the padding goes and the weight
   comes back. */
.wf-row__title {
	min-width: 0;
	max-width: 100%;
	font-weight: var(--th-font-weight-semibold, 600);
	font-size: var(--th-font-body, 15px);
	text-align: start;

	:deep(.button-vue__text) {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

.wf-row__flag {
	flex: 0 0 auto;
}

.wf-row__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs, 4px) var(--th-space-md, 12px);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
	padding-inline-start: var(--th-space-sm, 8px);
}

.wf-row__links {
	padding-inline-start: var(--th-space-sm, 8px);
}

.wf-row__team {
	color: var(--color-main-text);
}

.wf-row__progress {
	font-variant-numeric: tabular-nums;
}

.wf-row__actor {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
}

.wf-row__due {
	color: var(--color-text-error);
}

.wf-row__actions {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
}

@media (max-width: 700px) {
	.wf-row {
		flex-wrap: wrap;
	}

	.wf-row__actions {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
