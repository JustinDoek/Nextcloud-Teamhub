<template>
	<!-- v4.10.15 — the workflow detail view: what the request is, where it
	     stands, who is in it, what the viewer can do, what happened. Data
	     is the server's `workflow` view plus its `history`; the server
	     already left out what the viewer may not see (row ids, removed
	     participants), and this renders nothing it was not given. -->
	<!-- v4.10.17 — `label-id`, not `name`: see WorkflowActionDialog. The
	     id is on a visually-hidden heading rather than on the workflow's
	     own <h2>, because that <h2> does not exist while the detail is
	     loading or has failed, and a dangling aria-labelledby names
	     nothing. -->
	<NcModal label-id="wf-detail-title" size="normal" @close="$emit('close')">
		<div class="wf-detail">
			<h2 id="wf-detail-title" class="wf-detail__sr">
				{{ workflow ? workflow.title : t('teamhub', 'Workflow') }}
			</h2>
			<div v-if="loading && !workflow" class="wf-detail__state">
				<NcLoadingIcon :size="iconLarge" />
			</div>

			<NcNoteCard v-else-if="error && !workflow" type="error">
				<p>{{ errorText }}</p>
				<NcButton variant="secondary" @click="$emit('retry')">{{ t('teamhub', 'Try again') }}</NcButton>
			</NcNoteCard>

			<template v-else-if="workflow">
				<header class="wf-detail__head">
					<SourceBranch :size="iconToolbar" class="wf-detail__glyph" aria-hidden="true" />
					<div class="wf-detail__head-text">
						<h2 class="wf-detail__title">{{ workflow.title }}</h2>
						<!-- v4.10.39 — a personal request was asked from no team. -->
						<p v-if="workflow.personal" class="wf-detail__team">
							{{ t('teamhub', 'Personal request') }}
						</p>
						<p v-else-if="workflow.teamName" class="wf-detail__team">
							{{ t('teamhub', 'Requested from {team}', { team: workflow.teamName }) }}
						</p>
					</div>
					<NcChip no-close :variant="statusVariant" :text="statusText" />
				</header>

				<!-- v4.10.39 — what is going on now, and what the viewer can do
				     about it, stays on top whatever the tab: the task this view
				     answers for (`viewer.stepKey`), its links, and the buttons.
				     Justin, 2026-09-25: "The request can be a long story" — the
				     rest moved into tabs below. -->
				<section v-if="isOpen && (focus || responsibleLabel)" class="wf-detail__now" :aria-label="t('teamhub', 'Current task')">
					<p class="wf-detail__now-head">
						<span class="wf-detail__now-label">{{ t('teamhub', 'Now') }}</span>
						<span class="wf-detail__now-task">{{ focus ? taskTitle(focus) : responsibleLabel }}</span>
					</p>
					<!-- v4.10.44 — the role, as in the queue: word grey, value primary. -->
					<WorkflowTaskMeta v-if="focus" :step="focus" :show-task="false" />
					<p v-if="focus" class="wf-detail__step-meta">
						<span>{{ stepStateLabel(focus) }}</span>
						<span v-if="taskHolder(focus)">{{ taskHolder(focus) }}</span>
					</p>
					<WorkflowStepLinks
						v-if="focus"
						:step="focus"
						:completes="completesByLink(focus)"
						variant="secondary"
						@done="completeTask(focus)" />
					<!-- v4.10.50 — where the work of this request is done, when that
					     is not here (the adoption grid). Any definition may send one
					     (`IWorkflowDefinitionReference`); rendered the same for all. -->
					<p v-if="referenceUrl" class="wf-detail__reference">
						<NcButton variant="secondary" :href="referenceUrl">
							<template #icon>
								<OpenInApp :size="iconBody" />
							</template>
							{{ workflow.reference.label }}
						</NcButton>
					</p>
					<div v-if="claimable || actions.length" class="wf-detail__actions" :aria-label="t('teamhub', 'Actions')">
						<!-- v4.10.39 — a team task is claimed before anybody acts on it. -->
						<NcButton
							v-if="claimable"
							variant="primary"
							:disabled="busy"
							@click="$emit('action', { workflow, action: 'claim' })">
							<template v-if="busy" #icon><NcLoadingIcon :size="iconBody" /></template>
							{{ claimLabel }}
						</NcButton>
						<NcButton
							v-for="(a, i) in actions"
							:key="a"
							:variant="i === 0 && !claimable ? 'primary' : 'secondary'"
							:disabled="busy"
							@click="$emit('action', { workflow, action: a })">
							<template v-if="busy && i === 0 && !claimable" #icon><NcLoadingIcon :size="iconBody" /></template>
							{{ actionLabel(a, workflow) }}
						</NcButton>
					</div>
				</section>
				<p v-else-if="!isOpen" class="wf-detail__ended">
					{{ endedText }}
				</p>

				<!-- v4.10.39 — the tabs: a toggle group of NcButtons, the pattern
				     the queue widget's New / Claimed / Closed already uses. -->
				<div class="wf-detail__tabs" role="group" :aria-label="t('teamhub', 'Show')">
					<NcButton
						v-for="tabItem in tabs"
						:key="tabItem.id"
						size="small"
						variant="tertiary"
						:pressed="tab === tabItem.id"
						@click="tab = tabItem.id">
						{{ tabItem.label }}
					</NcButton>
				</div>

				<!-- v4.11.0 — the requester and whoever claimed the request,
				     writing to each other. Kept in the request's history. -->
				<section v-if="tab === 'messages' && hasConversation" class="wf-detail__section" :aria-label="t('teamhub', 'Messages')">
					<WorkflowConversation :workflow="workflow" @changed="$emit('changed', $event)" />
				</section>

				<section v-if="tab === 'request'" class="wf-detail__section" :aria-label="t('teamhub', 'Request')">
					<p v-if="workflow.description" class="wf-detail__description">{{ workflow.description }}</p>
					<p v-else class="wf-detail__muted">{{ t('teamhub', 'No description.') }}</p>
				</section>

				<!-- Where it stands. v4.10.16 — the step tracker is licensed:
				     an unlicensed instance sends no steps, and then the one
				     line it may show is who is responsible right now. -->
				<section v-if="tab === 'steps' && !hasSteps && responsibleLabel" class="wf-detail__section">
					<h3 class="wf-detail__heading">{{ t('teamhub', 'Waiting for') }}</h3>
					<p class="wf-detail__responsible">{{ responsibleLabel }}</p>
				</section>

				<section v-if="tab === 'steps' && hasSteps" class="wf-detail__section" :aria-labelledby="'wf-detail-steps-' + workflow.id">
					<h3 :id="'wf-detail-steps-' + workflow.id" class="wf-detail__heading">
						<span>{{ t('teamhub', 'Steps') }}</span>
						<span v-if="progressText" class="wf-detail__progress">{{ progressText }}</span>
					</h3>
					<!-- v4.10.37 — a step may hold several tasks, done in parallel
					     (`docs/service-builder.md` § 4). A step of one task reads as
					     it always did; a step of several lists its tasks under its
					     name, each with its role, its state, who has it (the team
					     only) and its links. -->
					<ol class="wf-detail__steps">
						<li
							v-for="stage in stages"
							:key="stage.order"
							class="wf-detail__step"
							:class="'wf-detail__step--' + stageState(stage)">
							<span class="wf-detail__step-mark" aria-hidden="true">
								<CheckCircle v-if="stageState(stage) === 'done'" :size="iconBody" />
								<RadioboxMarked v-else-if="stageState(stage) === 'current'" :size="iconBody" />
								<CircleOutline v-else :size="iconBody" />
							</span>
							<div class="wf-detail__step-body">
								<span class="wf-detail__step-label">{{ stage.label }}</span>
								<template v-if="stage.tasks.length === 1">
									<!-- v4.10.32 — the role a built service's desk step needs. -->
									<span v-if="stepRoleText(stage.tasks[0])" class="wf-detail__step-role">{{ stepRoleText(stage.tasks[0]) }}</span>
									<!-- v4.10.17 — the separator is a flex gap plus a
									     `::before` on each following part, not a "· "
									     typed inside the next span. Vue's compiler
									     drops the whitespace between sibling tags. -->
									<span class="wf-detail__step-meta">
										<span>{{ stepStateLabel(stage.tasks[0]) }}</span>
										<span v-if="trackerState(stage.tasks[0]) !== 'done'">{{ taskHolder(stage.tasks[0]) }}</span>
										<span v-else-if="stage.tasks[0].completedBy">{{ t('teamhub', 'by {name}', { name: displayName(stage.tasks[0].completedBy) }) }}</span>
										<time v-if="stage.tasks[0].completedAt" :datetime="isoInstant(stage.tasks[0].completedAt)" :title="formatAbsolute(stage.tasks[0].completedAt)">{{ formatStamp(stage.tasks[0].completedAt) }}</time>
									</span>
									<span v-if="stage.tasks[0].reason" class="wf-detail__step-reason">{{ stage.tasks[0].reason }}</span>
									<!-- v4.10.36 — the step's links; v4.10.37 — on the
									     requester's own open task, pressing one does it. -->
									<WorkflowStepLinks
										:step="stage.tasks[0]"
										:completes="completesByLink(stage.tasks[0])"
										:variant="trackerState(stage.tasks[0]) === 'current' && isOpen ? 'secondary' : 'tertiary'"
										class="wf-detail__step-links"
										@done="completeTask(stage.tasks[0])" />
								</template>
								<ul v-else class="wf-detail__tasks" :aria-label="t('teamhub', 'Tasks of {step}', { step: stage.label })">
									<li
										v-for="task in stage.tasks"
										:key="task.key"
										class="wf-detail__task"
										:class="'wf-detail__task--' + trackerState(task)">
										<span class="wf-detail__task-label">{{ task.label }}</span>
										<span class="wf-detail__step-meta">
											<span v-if="stepRoleText(task)">{{ stepRoleText(task) }}</span>
											<span>{{ stepStateLabel(task) }}</span>
											<span v-if="trackerState(task) !== 'done' && taskHolder(task)">{{ taskHolder(task) }}</span>
											<span v-else-if="task.completedBy">{{ t('teamhub', 'by {name}', { name: displayName(task.completedBy) }) }}</span>
											<!-- TRANSLATORS: a task the request may move on without; it must still be done before the request ends -->
											<span v-if="task.nonBlocking">{{ t('teamhub', 'Does not hold up the request') }}</span>
										</span>
										<span v-if="task.reason" class="wf-detail__step-reason">{{ task.reason }}</span>
										<WorkflowStepLinks
											:step="task"
											:completes="completesByLink(task)"
											:variant="trackerState(task) === 'current' && isOpen ? 'secondary' : 'tertiary'"
											class="wf-detail__step-links"
											@done="completeTask(task)" />
									</li>
								</ul>
							</div>
						</li>
					</ol>
				</section>

				<!-- Who is in it -->
				<section v-if="tab === 'people'" class="wf-detail__section" :aria-labelledby="'wf-detail-people-' + workflow.id">
					<h3 :id="'wf-detail-people-' + workflow.id" class="wf-detail__heading">{{ t('teamhub', 'Participants') }}</h3>
					<ul class="wf-detail__people">
						<!-- v4.11.0 — a person's avatar opens Nextcloud's contact menu
						     (call, chat, profile, availability), as in the members
						     widget (`MemberRow`); a group or a role is plain text. -->
						<li v-for="(p, i) in workflow.participants" :key="i" class="wf-detail__person">
							<template v-if="p.actor.type === 'user'">
								<NcAvatar
									:user="p.actor.id"
									:display-name="displayName(p.actor.id)"
									:disable-menu="false"
									:hide-status="true"
									:size="avatarSize" />
								<span class="wf-detail__person-body">
									<span class="wf-detail__person-name">{{ displayName(p.actor.id) }}</span>
									<span class="wf-detail__person-role">{{ participantRoleLabel(p.role) }}</span>
								</span>
							</template>
							<template v-else>
								<span class="wf-detail__person-glyph" aria-hidden="true">
									<AccountGroupOutline :size="iconBody" />
								</span>
								<span class="wf-detail__person-body">
									<span class="wf-detail__person-name">{{ actorLabel(p.actor) }}</span>
									<span class="wf-detail__person-role">{{ participantRoleLabel(p.role) }}</span>
								</span>
							</template>
						</li>
					</ul>
				</section>

				<!-- What happened. v4.11.0 — under the steps rather than a tab of
				     its own (Justin, 2026-09-28): where the request stands and how
				     it got there are read together. -->
				<section
					v-if="tab === 'steps'"
					class="wf-detail__section"
					:class="{ 'wf-detail__section--divided': hasSteps || responsibleLabel }"
					:aria-labelledby="'wf-detail-history-' + workflow.id">
					<h3 :id="'wf-detail-history-' + workflow.id" class="wf-detail__heading">{{ t('teamhub', 'Timeline') }}</h3>
					<p v-if="!events.length" class="wf-detail__muted">{{ t('teamhub', 'Nothing has happened yet.') }}</p>
					<!-- v4.10.30 — drawn like the decisions audit trail: a dot
					     per event on one connecting line, what happened and who
					     on the first line, the date and time at the end of it,
					     the note underneath. -->
					<ol v-else class="wf-detail__timeline">
						<li
							v-for="(e, i) in events"
							:key="i"
							class="wf-detail__event"
							:class="'wf-detail__event--' + eventTone(e)">
							<span class="wf-detail__event-dot" aria-hidden="true" />
							<div class="wf-detail__event-body">
								<div class="wf-detail__event-head">
									<span class="wf-detail__event-label">{{ eventLabel(e) }}</span>
									<span v-if="e.actorUid" class="wf-detail__event-actor">{{ displayName(e.actorUid) }}</span>
									<time
										class="wf-detail__event-when"
										:datetime="isoInstant(e.occurredAt)"
										:title="formatAbsolute(e.occurredAt)">{{ formatStamp(e.occurredAt) }}</time>
								</div>
								<p v-if="eventNote(e)" class="wf-detail__event-note">{{ eventNote(e) }}</p>
								<!-- v4.10.38 — the files that went with it (the paperclip). -->
								<ul v-if="eventFiles(e).length" class="wf-detail__files" :aria-label="t('teamhub', 'Attached files')">
									<li v-for="f in eventFiles(e)" :key="f.fileId">
										<a :href="fileUrl(f)" target="_blank" rel="noopener noreferrer" class="wf-detail__file">
											<FileOutline :size="iconInline" aria-hidden="true" />
											{{ f.name }}
										</a>
									</li>
								</ul>
							</div>
						</li>
					</ol>
				</section>

				<!-- v4.10.27 — the service team's own half. Rendered only for a
				     member of the team handling the request (`internal` is null
				     for everybody else, the requester included), and the server
				     leaves internal events out of their history anyway. Newest
				     first, like the timeline. -->
				<section
					v-if="tab === 'internal' && workflow.internal"
					class="wf-detail__section"
					:aria-labelledby="'wf-detail-internal-' + workflow.id">
					<h3 :id="'wf-detail-internal-' + workflow.id" class="wf-detail__heading">{{ t('teamhub', 'Internal notes') }}</h3>
					<p class="wf-detail__muted">{{ t('teamhub', 'Only your team can read these. The requester never sees them.') }}</p>
					<p v-if="!notes.length" class="wf-detail__muted">{{ t('teamhub', 'No internal notes yet.') }}</p>
					<ol v-else class="wf-detail__timeline">
						<li v-for="note in notes" :key="note.id" class="wf-detail__event wf-detail__event--neutral">
							<span class="wf-detail__event-dot" aria-hidden="true" />
							<div class="wf-detail__event-body">
								<div class="wf-detail__event-head">
									<span class="wf-detail__event-label">{{ note.actorUid ? displayName(note.actorUid) : t('teamhub', 'Internal note') }}</span>
									<time
										class="wf-detail__event-when"
										:datetime="isoInstant(note.occurredAt)"
										:title="formatAbsolute(note.occurredAt)">{{ formatStamp(note.occurredAt) }}</time>
								</div>
								<p class="wf-detail__event-note">{{ note.payload && note.payload.note }}</p>
								<ul v-if="eventFiles(note).length" class="wf-detail__files" :aria-label="t('teamhub', 'Attached files')">
									<li v-for="f in eventFiles(note)" :key="f.fileId">
										<a :href="fileUrl(f)" target="_blank" rel="noopener noreferrer" class="wf-detail__file">
											<FileOutline :size="iconInline" aria-hidden="true" />
											{{ f.name }}
										</a>
									</li>
								</ul>
							</div>
						</li>
					</ol>
				</section>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcModal, NcAvatar, NcButton, NcChip, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import RadioboxMarked from 'vue-material-design-icons/RadioboxMarked.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import WorkflowTaskMeta from './WorkflowTaskMeta.vue'
import { generateUrl } from '@nextcloud/router'
import WorkflowStepLinks from './WorkflowStepLinks.vue'
import WorkflowConversation from './WorkflowConversation.vue'
import {
	conversation,
	isOpen as workflowIsOpen,
	actionLabel,
	actorDescription,
	availableActions,
	eventLabel,
	hasStepDetail,
	responsibleActor,
	participantRoleLabel,
	progressLabel,
	statusLabel,
	statusTone,
	stepStateLabel,
	stepRoleText,
	stagesOf,
	taskTitle,
	timeline,
	trackerState,
	activeStep,
	formatStamp,
	isoInstant,
	eventTone,
	formatAbsolute,
} from '../../constants/workflows.js'
import { AGENT_ACTION, agentActionLabel, internalNotes } from '../../constants/serviceTeams.js'
import { ICON_BODY, ICON_INLINE, ICON_TOOLBAR, ICON_LARGE, AVATAR_MD } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowDetailModal',
	components: { NcModal, NcAvatar, NcButton, NcChip, NcLoadingIcon, NcNoteCard, AccountGroupOutline, SourceBranch, CheckCircle, RadioboxMarked, CircleOutline, FileOutline, OpenInApp, WorkflowStepLinks, WorkflowTaskMeta, WorkflowConversation },
	props: {
		/** The server's view with `history`, or null while loading. */
		workflow: { type: Object, default: null },
		loading: { type: Boolean, default: false },
		error: { type: Object, default: null },
		/** True while an action on this workflow is in flight. */
		busy: { type: Boolean, default: false },
		/** uid → display name, for the people the store already knows. */
		displayNames: { type: Object, default: () => ({}) },
	},
	/** v4.11.0 — `changed`: a message, question or answer was sent from the Messages tab. */
	emits: ['close', 'action', 'retry', 'changed'],
	data() {
		return {
			/**
			 * v4.10.39 — which tab is showing; *Steps* on every newly opened
			 * request, *Messages* (v4.11.0) when the service team is waiting
			 * for the viewer's answer.
			 */
			tab: this.initialTab(),
		}
	},
	computed: {
		/**
		 * v4.10.50 — the definition's reference link, only as a same-origin
		 * path: the server builds it with the URL generator, and anything
		 * else (a scheme, `//host`) is not rendered at all.
		 */
		referenceUrl() {
			const url = this.workflow?.reference?.url
			return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : ''
		},
		iconBody() { return ICON_BODY },
		iconInline() { return ICON_INLINE },
		iconToolbar() { return ICON_TOOLBAR },
		iconLarge() { return ICON_LARGE },
		/** v4.11.0 — 32, the members widget's size: the avatar is what opens the contact menu. */
		avatarSize() { return AVATAR_MD },
		isOpen() { return workflowIsOpen(this.workflow) },
		/** v4.10.16 — false on an unlicensed instance: no step list, no tracker. */
		hasSteps() { return hasStepDetail(this.workflow) },
		/** Who is responsible right now — shown on both tiers. */
		responsibleLabel() {
			const actor = responsibleActor(this.workflow)
			return actor ? this.actorLabel(actor) : ''
		},
		actions() { return availableActions(this.workflow) },
		progressText() { return progressLabel(this.workflow) },
		/** v4.10.37 — the steps with their tasks. */
		stages() { return stagesOf(this.workflow) },
		/** v4.10.39 — the task this view answers for (`viewer.stepKey`). */
		focus() { return activeStep(this.workflow) },
		/** v4.10.39 — an open team task nobody has claimed: claiming is the one thing to do. */
		claimable() { return this.isOpen && !!this.workflow?.internal?.claimable },
		claimLabel() { return agentActionLabel(AGENT_ACTION.CLAIM) },
		/**
		 * v4.10.39 — the tabs. *Internal notes* only for the team handling
		 * the request; the counts say what is behind a tab before it opens.
		 */
		tabs() {
			const out = [
				{ id: 'steps', label: t('teamhub', 'Steps') },
			]
			if (this.hasConversation) {
				out.push({ id: 'messages', label: t('teamhub', 'Messages ({n})', { n: this.messages.length }) })
			}
			out.push(
				{ id: 'request', label: t('teamhub', 'Request') },
				{ id: 'people', label: t('teamhub', 'Participants') },
			)
			if (this.workflow?.internal) {
				out.push({ id: 'internal', label: t('teamhub', 'Internal notes ({n})', { n: this.notes.length }) })
			}
			return out
		},
		events() { return timeline(this.workflow, this.workflow?.history) },
		/** v4.11.0 — the conversation, oldest first. */
		messages() { return conversation(this.workflow?.history) },
		/**
		 * v4.11.0 — only a request a service team handles has a
		 * conversation; any other workflow shows no Messages tab.
		 */
		hasConversation() {
			return !!this.workflow?.viewer?.canMessage
				|| !!this.workflow?.internal
				|| (this.workflow?.steps || []).some(s => s?.actor?.type === 'service_agent')
				|| this.messages.length > 0
		},
		/** v4.10.27 — the desk's internal notes, newest first; empty for anybody not on the desk. */
		notes() { return this.workflow?.internal ? internalNotes(this.workflow?.history) : [] },
		errorText() {
			return this.error?.message
				? t('teamhub', 'The workflow could not be loaded: {error}', { error: this.error.message })
				: t('teamhub', 'The workflow could not be loaded.')
		},
		/** v4.10.32 — closed by an admin of the service team is *completed* in the engine, *Closed* to the reader. */
		closedByDesk() { return this.workflow?.status === 'completed' && this.workflow?.outcome === 'closed' },
		statusText() {
			// TRANSLATORS: status of a request an admin of the service team closed before it was finished
			return this.closedByDesk ? t('teamhub', 'Closed') : statusLabel(this.workflow?.status)
		},
		statusVariant() { return this.closedByDesk ? 'tertiary' : statusTone(this.workflow?.status) },
		endedText() {
			if (this.closedByDesk) {
				return t('teamhub', 'The service team closed this request.')
			}
			switch (this.workflow?.status) {
			case 'completed': return t('teamhub', 'This workflow is completed.')
			case 'rejected': return t('teamhub', 'This workflow was rejected.')
			case 'cancelled': return t('teamhub', 'This workflow was withdrawn.')
			default: return ''
			}
		},
	},
	watch: {
		/** A different request opens on its steps, not on the last one's tab. */
		'workflow.id'() {
			this.tab = this.initialTab()
		},
	},
	methods: {
		t,
		n,
		/** v4.11.0 — straight to the conversation when an answer is owed. */
		initialTab() {
			return this.workflow?.viewer?.answersQuestion ? 'messages' : 'steps'
		},
		actionLabel,
		eventLabel,
		participantRoleLabel,
		statusLabel,
		statusTone,
		stepStateLabel,
		stepRoleText,
		taskTitle,
		trackerState,
		formatStamp,
		isoInstant,
		eventTone,
		formatAbsolute,
		actorLabel(actor) {
			const d = actorDescription(actor)
			return d.uid ? this.displayName(d.uid) : d.label
		},
		/** A display name when one is known; the uid is what the server sent otherwise. */
		displayName(uid) {
			return this.displayNames[uid] || uid
		},
		/** v4.10.38 — the files an event carried, `[{ fileId, name }]`. */
		eventFiles(e) {
			const files = e?.payload?.files
			return Array.isArray(files) ? files.filter(f => Number(f?.fileId) > 0) : []
		},
		/**
		 * The file in Files. It opens for whoever Nextcloud lets open it: the
		 * other side while the share lasts, the sender always.
		 */
		fileUrl(f) {
			return generateUrl('/f/{id}', { id: Number(f.fileId) })
		},
		eventNote(e) {
			return e?.payload?.note || e?.payload?.reason || ''
		},
		/** v4.10.37 — a step is done when all its tasks are, current while any is open. */
		stageState(stage) {
			const states = stage.tasks.map(trackerState)
			if (states.includes('current')) {
				return 'current'
			}
			return states.every(s => s === 'done') ? 'done' : 'pending'
		},
		/**
		 * Who a task is with: the team member who has it (the server sends
		 * that to the team only), else the actor it waits for.
		 */
		taskHolder(task) {
			if (task?.assignee) {
				// TRANSLATORS: {name} is the team member who has taken a task on
				return t('teamhub', 'With {name}', { name: this.displayName(task.assignee) })
			}
			return this.actorLabel(task?.actor)
		},
		/** v4.10.37 — the requester's own open task: its links complete it. */
		completesByLink(task) {
			return this.isOpen && !!task?.canAct && task?.actor?.type === 'user'
		},
		/**
		 * The requester pressed a task's link: complete *that* task, whichever
		 * one this view was opened on.
		 */
		completeTask(task) {
			const workflow = { ...this.workflow, viewer: { ...(this.workflow?.viewer || {}), stepKey: task.key } }
			this.$emit('action', { workflow, action: 'complete', direct: true })
		},
	},
}
</script>

<style scoped lang="scss">
.wf-detail {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg, 16px);
	padding: var(--th-space-lg, 16px) var(--th-space-xl, 24px) var(--th-space-xl, 24px);
}

.wf-detail__state {
	display: flex;
	justify-content: center;
	padding: var(--th-space-xl, 24px);
}

.wf-detail__head {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-md, 12px);
}

.wf-detail__glyph {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	margin-block-start: var(--th-space-xs, 4px);
}

.wf-detail__head-text {
	flex: 1 1 auto;
	min-width: 0;
}

/* The dialog's accessible name; the visible title is the <h2> below. */
.wf-detail__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

.wf-detail__title {
	margin: 0;
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-semibold, 600);
	overflow-wrap: anywhere;
}

.wf-detail__team,
.wf-detail__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-detail__reference {
	margin: 0;
}

.wf-detail__description {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.wf-detail__section {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.wf-detail__heading {
	display: flex;
	align-items: baseline;
	gap: var(--th-space-sm, 8px);
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-detail__progress {
	font-size: var(--th-font-meta, 13px);
	font-weight: var(--th-font-weight-regular, 400);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.wf-detail__steps,
.wf-detail__people {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.wf-detail__step {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-sm, 8px);
}

.wf-detail__step-mark {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.wf-detail__step--done .wf-detail__step-mark { color: var(--color-element-success); }
.wf-detail__step--current .wf-detail__step-mark { color: var(--color-primary-element); }

.wf-detail__step-body {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.wf-detail__now {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-md, 12px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-background-hover);
}

.wf-detail__now-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--th-space-sm, 8px);
	margin: 0;
}

.wf-detail__now-label {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-detail__now-task {
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-detail__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	border-block-end: 1px solid var(--color-border);
	padding-block-end: var(--th-space-sm, 8px);
}

.wf-detail__files {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px) var(--th-space-md, 12px);
	margin: var(--th-space-xs, 4px) 0 0;
	padding: 0;
	list-style: none;
}

.wf-detail__file {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	color: var(--color-main-text);
	font-size: var(--th-font-meta, 13px);
	text-decoration: underline;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: var(--border-radius-small);
	}
}

.wf-detail__tasks {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	margin: var(--th-space-xs, 4px) 0 0;
	padding: 0;
	list-style: none;
}

.wf-detail__task {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	padding-inline-start: var(--th-space-sm, 8px);
	border-inline-start: 1px solid var(--color-border);
}

.wf-detail__task--current {
	border-inline-start-color: var(--color-primary-element);
}

.wf-detail__task-label {
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-detail__step-links {
	margin-block-start: var(--th-space-xs, 4px);
}

.wf-detail__responsible {
	margin: 0;
	color: var(--color-main-text);
}

.wf-detail__step--current .wf-detail__step-label {
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-detail__step-meta,
.wf-detail__step-role,
.wf-detail__step-reason,
.wf-detail__person-role {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

/* The step's facts, separated rather than run together (v4.10.17). */
.wf-detail__step-meta {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--th-space-xs, 4px);

	/* `*`, not `span`: the time is a <time> since v4.10.30. */
	> * + *::before {
		content: '·';
		margin-inline-end: var(--th-space-xs, 4px);
	}
}

.wf-detail__step-reason,
.wf-detail__event-note {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.wf-detail__actions {
	display: flex;
	flex-direction: row;
	flex-wrap: wrap;
	gap: var(--th-space-sm, 8px);
}

.wf-detail__ended {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.wf-detail__person {
	display: flex;
	align-items: center;
	gap: var(--th-space-md, 12px);
}

/* A group or a role has no avatar: its glyph takes the avatar's place, so
   every name starts on the same line. */
.wf-detail__person-glyph {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: var(--th-icon-large, 32px);
	color: var(--color-text-maxcontrast);
}

.wf-detail__person-body {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.wf-detail__person-name {
	overflow-wrap: anywhere;
}

/* v4.11.0 — the timeline under the steps, set apart from them. */
.wf-detail__section--divided {
	margin-block-start: var(--th-space-sm, 8px);
	padding-block-start: var(--th-space-lg, 16px);
	border-block-start: 1px solid var(--color-border);
}

/* v4.10.30 — the timeline and the internal notes, drawn like the
   decisions audit trail (`TeamDecisionsView` `.th-dv__audit-*`): a dot per
   event, a line joining the dots, what happened and who on one line with
   the date and time at its end, the note underneath. */
.wf-detail__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.wf-detail__event {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-sm, 8px);
	padding-block: var(--th-space-sm, 8px);
	font-size: var(--th-font-meta, 13px);
	line-height: 1.3;
}

/* The line to the next dot, drawn per item from this dot's centre (the
   top padding plus half a line) to the same point on the next item, so a
   long note can neither leave it short nor make it overshoot. The first
   `inset-block-*` pair is the fallback for a browser without `lh`. */
.wf-detail__event:not(:last-child)::before {
	content: '';
	position: absolute;
	inset-inline-start: calc(var(--th-space-md, 12px) / 2);
	inset-block-start: calc(var(--th-space-sm, 8px) * 2);
	inset-block-end: calc(var(--th-space-sm, 8px) * -2);
	inset-block-start: calc(var(--th-space-sm, 8px) + 0.5lh);
	inset-block-end: calc(-1 * (var(--th-space-sm, 8px) + 0.5lh));
	width: 1px;
	background: var(--color-border);
}

/* One line high, so the dot sits on the middle of the first line. */
.wf-detail__event-dot {
	position: relative;
	z-index: 1;
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	height: 1lh;

	&::before {
		content: '';
		box-sizing: border-box;
		width: var(--th-space-md, 12px);
		height: var(--th-space-md, 12px);
		border-radius: 50%;
		background: var(--color-text-maxcontrast);
		border: 2px solid var(--color-main-background);
		box-shadow: 0 0 0 1px var(--color-border);
	}
}

.wf-detail__event--start .wf-detail__event-dot::before { background: var(--color-primary-element); }
.wf-detail__event--success .wf-detail__event-dot::before { background: var(--color-element-success); }
.wf-detail__event--error .wf-detail__event-dot::before { background: var(--color-element-error); }
.wf-detail__event--warning .wf-detail__event-dot::before { background: var(--color-element-warning); }

.wf-detail__event-body {
	flex: 1 1 auto;
	min-width: 0;
}

.wf-detail__event-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--th-space-sm, 8px);
}

.wf-detail__event-label {
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.wf-detail__event-actor {
	color: var(--color-text-maxcontrast);
}

.wf-detail__event-when {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.wf-detail__event-note {
	margin: var(--th-space-xs, 4px) 0 0;
	line-height: 1.4;
}
</style>
