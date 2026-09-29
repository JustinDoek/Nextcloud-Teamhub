<template>
	<!-- v4.10.15 — start the one built-in workflow a member can open from
	     My Work: request a new team. Which team is asking, what the new
	     team should be called, and why. The definition validates again
	     server-side; this only refuses the obviously empty. -->
	<!-- v4.10.17 — `label-id`, not `name`: see WorkflowActionDialog. -->
	<NcModal label-id="wf-start-title" size="small" @close="$emit('close')">
		<div class="wf-start">
			<h3 id="wf-start-title" class="wf-start__title">{{ t('teamhub', 'Request a new team') }}</h3>
			<p class="wf-start__hint">
				<!-- v4.10.23 — "the administrators" became "the service
				     team": step 3 is the desk that holds the Nextcloud
				     services. The sentence is what the requester is told
				     will happen, so it cannot keep naming the old actor. -->
				{{ t('teamhub', 'The owner or a moderator of the team you ask from approves the request; the service team then creates the team and you confirm it.') }}
			</p>

			<label class="wf-start__field">
				<span>{{ t('teamhub', 'Ask from team') }}</span>
				<NcSelect
					v-model="teamId"
					input-id="wf-start-team"
					label-outside
					:options="teamOptions"
					:reduce="o => o.id"
					:clearable="false"
					:disabled="busy"
					:placeholder="t('teamhub', 'Choose a team')"
					:aria-label-combobox="t('teamhub', 'Ask from team')" />
			</label>

			<NcTextField
				v-model="teamName"
				:label="t('teamhub', 'Name of the new team')"
				maxlength="255"
				:disabled="busy" />

			<label class="wf-start__field">
				<span>{{ t('teamhub', 'Why is it needed?') }}</span>
				<NcTextArea
					v-model="reason"
					rows="3"
					maxlength="1000"
					label-outside
					:aria-label="t('teamhub', 'Why is it needed?')"
					:disabled="busy" />
			</label>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="wf-start__actions">
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">{{ t('teamhub', 'Cancel') }}</NcButton>
				<NcButton variant="primary" :disabled="busy || !canSubmit" @click="submit">
					<template v-if="busy" #icon><NcLoadingIcon :size="iconBody" /></template>
					{{ t('teamhub', 'Send request') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcModal, NcSelect, NcTextField, NcTextArea, NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { ICON_BODY } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowStartDialog',
	components: { NcModal, NcSelect, NcTextField, NcTextArea, NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		/** `[{ id, name }]` — the teams the viewer may ask from. */
		teams: { type: Array, default: () => [] },
		busy: { type: Boolean, default: false },
		/** A server-side refusal to show under the form. */
		error: { type: String, default: '' },
	},
	emits: ['close', 'submit'],
	data() {
		return {
			teamId: this.teams.length === 1 ? String(this.teams[0].id) : null,
			teamName: '',
			reason: '',
		}
	},
	computed: {
		iconBody() { return ICON_BODY },
		teamOptions() {
			return this.teams.map(team => ({ id: String(team.id), label: team.name || String(team.id) }))
		},
		canSubmit() {
			return !!this.teamId && this.teamName.trim() !== '' && this.reason.trim() !== ''
		},
	},
	methods: {
		t,
		n,
		submit() {
			if (this.busy || !this.canSubmit) {
				return
			}
			this.$emit('submit', { teamId: this.teamId, teamName: this.teamName.trim(), reason: this.reason.trim() })
		},
	},
}
</script>

<style scoped lang="scss">
.wf-start {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.wf-start__title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-start__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-start__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	font-size: var(--th-font-meta, 13px);
}

.wf-start__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
}
</style>
