<template>
	<!-- v4.10.20 — ask a service desk for something.
	     v4.10.25 — the form behind a catalogue card. The service is no
	     longer picked here: the card the requester clicked *is* the choice,
	     so the dialog opens on what they already decided and asks only what
	     the desk cannot know. `label-id`, not `name`, like the other
	     workflow dialogs. -->
	<NcModal label-id="svc-request-title" size="small" @close="$emit('close')">
		<div class="svc-request">
			<h3 id="svc-request-title" class="svc-request__title">{{ service.label }}</h3>
			<p v-if="service.description" class="svc-request__hint">{{ service.description }}</p>

			<!-- v4.10.39 — sent, and the service starts with the requester: their
			     first task, straight away, instead of a trip to My Work. Pressing
			     a task's link does the task (the link opens as well); a task
			     without one has *Mark as done*. -->
			<template v-if="nextTasks.length">
				<NcNoteCard type="success">
					{{ t('teamhub', 'Your request has been sent. This helps the team get started:') }}
				</NcNoteCard>
				<ul class="svc-request__next" :aria-label="t('teamhub', 'Your tasks')">
					<li v-for="task in nextTasks" :key="task.key" class="svc-request__task">
						<span class="svc-request__task-label">{{ task.label }}</span>
						<WorkflowStepLinks
							v-if="hasLinks(task)"
							:step="task"
							completes
							variant="primary"
							@done="$emit('complete-task', { workflow: created, step: task.key })" />
						<div v-else>
							<NcButton
								variant="primary"
								:disabled="busy"
								@click="$emit('complete-task', { workflow: created, step: task.key })">
								{{ t('teamhub', 'Mark as done') }}
							</NcButton>
						</div>
					</li>
				</ul>
				<p class="svc-request__note">{{ t('teamhub', 'You can also do this later from My Work.') }}</p>
				<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
				<div class="svc-request__actions">
					<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">{{ t('teamhub', 'Later') }}</NcButton>
				</div>
			</template>

			<template v-else>
			<!-- Who answers, said once. The requester does not choose it and
			     cannot change it; it is here because a request you can see
			     the destination of is one you can chase. -->
			<p v-if="service.serviceTeamName" class="svc-request__desk">
				<LifebuoyIcon :size="ICON_INLINE" aria-hidden="true" />
				{{ t('teamhub', 'Answered by {team}', { team: service.serviceTeamName }) }}
			</p>


			<NcTextField
				v-model="summary"
				:label="isNewTeam ? t('teamhub', 'Team name') : t('teamhub', 'In one line')"
				maxlength="255"
				:disabled="busy" />

			<label class="svc-request__field">
				<span>{{ isNewTeam ? t('teamhub', 'What is the team for?') : t('teamhub', 'Describe what you need') }}</span>
				<NcTextArea
					v-model="details"
					rows="4"
					maxlength="1000"
					label-outside
					:aria-label="isNewTeam ? t('teamhub', 'What is the team for?') : t('teamhub', 'Describe what you need')"
					:disabled="busy" />
			</label>

			<!-- v4.10.39 — below the description, and optional on a service a
			     team built (Justin, 2026-09-25): left empty, the request is the
			     requester's own. The Nextcloud services act on a team, so there
			     it is still asked for. -->
			<label class="svc-request__field">
				<span>{{ teamRequired ? t('teamhub', 'Ask from team') : t('teamhub', 'Ask from team (optional)') }}</span>
				<NcSelect
					v-model="teamId"
					input-id="svc-request-team"
					label-outside
					:options="teamOptions"
					:reduce="o => o.id"
					:clearable="!teamRequired"
					:disabled="busy"
					:placeholder="teamRequired ? t('teamhub', 'Choose a team') : t('teamhub', 'No team: a personal request')"
					:aria-label-combobox="teamRequired ? t('teamhub', 'Ask from team') : t('teamhub', 'Ask from team (optional)')" />
				<span class="svc-request__note">
					{{ t('teamhub', 'The team the request is about. Its members can follow it in My Work.') }}
				</span>
			</label>

			<!-- v4.10.38 — the paperclip: the requester's files, shared with
			     the service team for the service's number of days. -->
			<FileAttachField
				v-if="fileSettings.allowed"
				v-model="files"
				:note="shareNote(fileSettings, t('teamhub', 'the service team'))"
				:disabled="busy" />

			<p v-if="service.leadTime" class="svc-request__note">{{ service.leadTime }}</p>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="svc-request__actions">
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">{{ t('teamhub', 'Cancel') }}</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !canSubmit"
					@click="submit">
					<template v-if="busy" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Send request') }}
				</NcButton>
			</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import FileAttachField from '../mywork/FileAttachField.vue'
import WorkflowStepLinks from '../mywork/WorkflowStepLinks.vue'
import { fileSharingOf, ownOpenTasks, shareNote, stepLinks } from '../../constants/workflows.js'
import { SERVICE } from '../../constants/serviceTeams.js'
import { ICON_BODY, ICON_INLINE } from '../../constants/uiTokens.js'

/**
 * The requester's half of Service Teams (WorkflowHub phase 5, v4.10.20;
 * moved out of My Work in phase 7A, v4.10.25).
 *
 * Deliberately small. A service desk's value to the person asking is that
 * they do **not** have to know how it is organised: they pick what they
 * need from the catalogue and say it in their own words, and the catalogue
 * routes it. So this form names no agent and no workflow definition — the
 * only decisions left to the requester are which team the request is about
 * and what they want to say.
 *
 * From here the request is an ordinary workflow: it appears under *Waiting
 * for others* in the requester's My Work, it says who is responsible, and
 * the last step is theirs to confirm. Nothing internal to the desk — who
 * claimed it, what they noted — is ever part of that view.
 */
export default {
	name: 'ServiceRequestDialog',

	components: {
		NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField,
		LifebuoyIcon,
		FileAttachField,
		WorkflowStepLinks,
	},

	props: {
		/** One catalogue entry, as `GET /service-teams/catalogue` returned it. */
		service: { type: Object, required: true },
		/** `[{ id, name }]` — the teams the viewer may ask from. */
		teams: { type: Array, default: () => [] },
		busy: { type: Boolean, default: false },
		/** A server-side refusal to show under the form. */
		error: { type: String, default: '' },
		/**
		 * v4.10.39 — the request just made, when the service starts with the
		 * requester: the dialog then shows their tasks instead of the form.
		 */
		created: { type: Object, default: null },
	},

	emits: ['close', 'submit', 'complete-task'],

	data() {
		return {
			ICON_BODY,
			ICON_INLINE,
			// v4.10.39 — empty unless the team must be named.
			teamId: (this.service.teamOptional ?? this.service.builtService) ? '' : (this.teams[0]?.id || ''),
			summary: '',
			details: '',
			/** v4.10.38 — `[{ fileId, name }]` from the paperclip. */
			files: [],
		}
	},

	computed: {
		/** v4.10.39 — the requester's own open tasks on the request just sent. */
		nextTasks() {
			return this.created ? ownOpenTasks(this.created) : []
		},

		/** v4.10.38 — what the service lets the paperclip do (the catalogue says). */
		fileSettings() {
			return fileSharingOf({ data: { fileSharing: this.service.fileSharing } })
		},

		teamOptions() {
			return this.teams.map(team => ({ id: team.id, label: team.name }))
		},

		/** The server validates again; this only refuses the obviously empty. */
		/** v4.10.39 — a service a team built may be asked for without a team. */
		teamRequired() {
			// v4.10.44 — the catalogue says per service (`teamOptional`); a
			// service a team built never needs one.
			return !(this.service.teamOptional ?? this.service.builtService)
		},

		/** v4.10.44 — the new-team card: its line is the team's name. */
		isNewTeam() {
			return this.service.serviceKey === SERVICE.NEW_TEAM
		},

		canSubmit() {
			return (!!this.teamId || !this.teamRequired)
				&& this.summary.trim() !== ''
				&& this.details.trim() !== ''
		},
	},

	methods: {
		t,
		shareNote,
		hasLinks(task) {
			return stepLinks(task).length > 0
		},

		submit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', {
				// A personal request is recorded against the service team
				// itself (TeamServiceDefinition::canStart()).
				teamId: this.teamId || this.service.serviceTeamId,
				summary: this.summary.trim(),
				details: this.details.trim(),
				fileIds: this.fileSettings.allowed ? this.files.map(f => f.fileId) : [],
			})
		},
	},
}
</script>

<style scoped lang="scss">
.svc-request__next {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.svc-request__task {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-request__task-label {
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-request {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.svc-request__title {
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

.svc-request__hint,
.svc-request__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-request__desk {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	font-size: var(--th-font-meta, 13px);
}

.svc-request__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-request__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-sm, 8px);
}
</style>
