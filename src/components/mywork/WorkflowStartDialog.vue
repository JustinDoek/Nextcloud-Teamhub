<template>
	<!-- v4.10.15 — start the one built-in workflow a member can open from
	     My Work: request a new team. What the new team should be called, what
	     shape it should have, and why. The definition validates again
	     server-side; this only refuses the obviously empty. -->
	<!-- v4.10.17 — `label-id`, not `name`: see WorkflowActionDialog. -->
	<NcModal label-id="wf-start-title" size="small" @close="$emit('close')">
		<div class="wf-start">
			<h3 id="wf-start-title" class="wf-start__title">{{ t('teamhub', 'Request a new team') }}</h3>
			<p class="wf-start__hint">
				<!-- v4.10.23 — "the administrators" became "the service
				     team": step 3 is the desk that holds the Nextcloud
				     services. The sentence is what the requester is told
				     will happen, so it cannot keep naming the old actor.
				     v4.12.4 — and it no longer names a team the requester
				     asks from, because there is none to name. -->
				{{ t('teamhub', 'The service team approves the request and creates the team; you confirm it afterwards.') }}
			</p>

			<!-- v4.12.4 — *Ask from team* was here, and was required. A new
			     team is asked for by a person, not by a team: there is nothing
			     for the asking team to own, a member of no team could not ask
			     at all, and the service catalogue had already made the field
			     optional for this service (ServiceCatalogue::TEAM_OPTIONAL,
			     Justin 2026-09-25). The request is recorded against the
			     service team that answers it, which is what the Services page
			     has done for a personal request since v4.10.44. -->
			<NcTextField
				v-model="teamName"
				:label="t('teamhub', 'Name of the new team')"
				maxlength="255"
				:disabled="busy" />

			<!-- The two answers the service team needs before it can open the
			     create-team wizard. Shared with the new-team card on the
			     Services page, so both start points ask the same thing. -->
			<TeamShapeFields
				id-prefix="wf-start"
				:disabled="busy"
				@update:template-key="templateKey = $event"
				@update:profile-key="profileKey = $event" />

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
import { NcModal, NcTextField, NcTextArea, NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TeamShapeFields from '../services/TeamShapeFields.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowStartDialog',
	components: { NcModal, NcTextField, NcTextArea, NcButton, NcLoadingIcon, NcNoteCard, TeamShapeFields },
	props: {
		busy: { type: Boolean, default: false },
		/** A server-side refusal to show under the form. */
		error: { type: String, default: '' },
	},
	emits: ['close', 'submit'],
	data() {
		return {
			teamName: '',
			reason: '',
			// v4.12.4 — TeamShapeFields owns the two values and reports them
			// here. Empty when its fetch failed, which the definition allows:
			// the request is then sent without them.
			templateKey: '',
			profileKey: '',
		}
	},
	computed: {
		iconBody() { return ICON_BODY },
		canSubmit() {
			return this.teamName.trim() !== '' && this.reason.trim() !== ''
		},
	},
	methods: {
		t,
		n,
		submit() {
			if (this.busy || !this.canSubmit) {
				return
			}
			this.$emit('submit', {
				teamName: this.teamName.trim(),
				reason: this.reason.trim(),
				templateKey: this.templateKey,
				profileKey: this.profileKey,
			})
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
