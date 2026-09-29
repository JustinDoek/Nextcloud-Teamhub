<template>
	<!-- v4.10.29 — ask the service team for more storage in a team space.
	     One form for both doors: the quota card on the Services page (which
	     has to ask which team) and the action menu on Manage team → Files
	     (which is already on one, so passes a single team). -->
	<NcModal label-id="quota-request-title" size="small" @close="$emit('close')">
		<div class="quota-request">
			<h3 id="quota-request-title" class="quota-request__title">
				{{ service && service.label ? service.label : t('teamhub', 'Request a quota increase') }}
			</h3>

			<p v-if="service && service.serviceTeamName" class="quota-request__desk">
				<LifebuoyIcon :size="ICON_INLINE" aria-hidden="true" />
				{{ t('teamhub', 'Answered by {team}', { team: service.serviceTeamName }) }}
			</p>

			<!-- Nothing to ask for: every team this person administers
			     already has a request open. The card is only listed for
			     somebody with a team, so this is the one empty case. -->
			<NcNoteCard v-if="!options.length" type="info">
				{{ t('teamhub', 'Every team you administer already has a quota request open.') }}
			</NcNoteCard>

			<template v-else>
				<label class="quota-request__field">
					<span>{{ t('teamhub', 'Requested size (GB)') }}</span>
					<NcTextField
						v-model.number="gb"
						type="number"
						min="1"
						step="1"
						label-outside
						:aria-label="t('teamhub', 'Requested size (GB)')"
						:disabled="busy" />
				</label>

				<label class="quota-request__field">
					<span>{{ t('teamhub', 'Why the team needs it') }}</span>
					<NcTextArea
						v-model="reason"
						rows="3"
						maxlength="1000"
						label-outside
						:aria-label="t('teamhub', 'Why the team needs it')"
						:disabled="busy" />
				</label>

				<!-- v4.10.44 — the team below what is asked, as on every other
				     service form (Justin, 2026-09-25). A quota is about one team,
				     so it is still asked for. -->
				<label v-if="options.length > 1" class="quota-request__field">
					<span>{{ t('teamhub', 'Team') }}</span>
					<NcSelect
						v-model="teamId"
						input-id="quota-request-team"
						label-outside
						:options="teamOptions"
						:reduce="o => o.id"
						:clearable="false"
						:disabled="busy"
						:aria-label-combobox="t('teamhub', 'Team')" />
				</label>

				<p class="quota-request__hint">
					{{ chosen && chosen.quota > 0
						? t('teamhub', 'The team space of {team} currently has a quota of {size}.', { team: chosen.teamName, size: formatBytes(chosen.quota) })
						: t('teamhub', 'The team space of {team} currently has no quota.', { team: chosen ? chosen.teamName : '' }) }}
				</p>

				<p class="quota-request__hint">
					{{ t('teamhub', 'The service team grants or declines it, with a reason. Granting sets the quota at once; you close the request in My Work.') }}
				</p>
				<p v-if="service && service.leadTime" class="quota-request__hint">{{ service.leadTime }}</p>
			</template>

			<p v-if="waiting.length" class="quota-request__hint">
				{{ t('teamhub', 'A request is already open for: {teams}', { teams: waiting.join(', ') }) }}
			</p>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="quota-request__actions">
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">{{ t('teamhub', 'Cancel') }}</NcButton>
				<NcButton
					v-if="options.length"
					variant="primary"
					:disabled="busy || !canSubmit"
					@click="submit">
					<template v-if="busy" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Send request') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import { ICON_BODY, ICON_INLINE } from '../../constants/uiTokens.js'
import { formatBytes, gbToBytes, quotaRequestValid, suggestedQuotaGb } from '../../constants/serviceTeams.js'

/**
 * The quota request's form (v4.10.29) — a size and a reason, for one team.
 *
 * Its own dialog rather than `ServiceRequestDialog` because the quota
 * request is the one service that asks for a number and whose answer *does*
 * something: granting it writes the quota. The server checks everything
 * again (a space exists, the size is an increase, the caller administers the
 * team) and records the current quota itself; the form only refuses the
 * obviously wrong.
 */
export default {
	name: 'QuotaRequestDialog',

	components: {
		NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField,
		LifebuoyIcon,
	},

	props: {
		/** The catalogue entry, when there is one: its label, desk and lead time. */
		service: { type: Object, default: null },
		/** `[{ teamId, teamName, quota, openRequest }]` — `GET /service-teams/quota-teams`. */
		teams: { type: Array, default: () => [] },
		busy: { type: Boolean, default: false },
		/** A server-side refusal to show under the form. */
		error: { type: String, default: '' },
	},

	emits: ['close', 'submit'],

	data() {
		const first = this.teams.find(team => !team.openRequest)
		return {
			ICON_BODY,
			ICON_INLINE,
			teamId: first ? first.teamId : '',
			gb: suggestedQuotaGb(first ? first.quota : 0),
			reason: '',
		}
	},

	computed: {
		/** The teams a request can still be opened for. */
		options() {
			return this.teams.filter(team => !team.openRequest)
		},

		teamOptions() {
			return this.options.map(team => ({ id: team.teamId, label: team.teamName }))
		},

		/** The teams that already have one open, by name. */
		waiting() {
			return this.teams.filter(team => team.openRequest).map(team => team.teamName)
		},

		chosen() {
			return this.options.find(team => team.teamId === this.teamId) || null
		},

		canSubmit() {
			return !!this.chosen && quotaRequestValid({ gb: this.gb, reason: this.reason, currentBytes: this.chosen.quota })
		},
	},

	watch: {
		/** A different team has a different space: start from its quota. */
		teamId() {
			this.gb = suggestedQuotaGb(this.chosen ? this.chosen.quota : 0)
		},
	},

	methods: {
		t,
		formatBytes,

		submit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', {
				teamId: this.teamId,
				requestedBytes: gbToBytes(this.gb),
				reason: this.reason.trim(),
			})
		},
	},
}
</script>

<style scoped lang="scss">
.quota-request {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.quota-request__title {
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

.quota-request__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.quota-request__desk {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	font-size: var(--th-font-meta, 13px);
}

.quota-request__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.quota-request__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-sm, 8px);
}
</style>
