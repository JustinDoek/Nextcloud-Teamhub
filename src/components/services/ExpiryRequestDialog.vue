<template>
	<!-- v4.10.45 — ask the service team for more time before a team's
	     expiration date. One form for both doors: the card in the service
	     catalog (which asks which team) and Manage team → Expiration date
	     (which is already on one, so passes a single team). -->
	<NcModal label-id="expiry-request-title" size="small" @close="$emit('close')">
		<div class="expiry-request">
			<h3 id="expiry-request-title" class="expiry-request__title">
				{{ service && service.label ? service.label : t('teamhub', 'Request more time for a team') }}
			</h3>

			<p v-if="service && service.serviceTeamName" class="expiry-request__desk">
				<LifebuoyIcon :size="ICON_INLINE" aria-hidden="true" />
				{{ t('teamhub', 'Answered by {team}', { team: service.serviceTeamName }) }}
			</p>

			<NcNoteCard v-if="!options.length" type="info">
				{{ t('teamhub', 'Every team you administer with an expiration date already has a request for more time open.') }}
			</NcNoteCard>

			<template v-else>
				<label class="expiry-request__field" for="expiry-request-date">
					<span>{{ t('teamhub', 'Until when does the team need to run?') }}</span>
					<!-- A native date field: NC's recommended picker (/ui-standards). -->
					<input
						id="expiry-request-date"
						v-model="date"
						type="date"
						class="expiry-request__date"
						:min="minDate"
						:disabled="busy">
				</label>

				<label class="expiry-request__field">
					<span>{{ t('teamhub', 'Why is the team still needed?') }}</span>
					<NcTextArea
						v-model="reason"
						rows="3"
						maxlength="1000"
						label-outside
						:aria-label="t('teamhub', 'Why is the team still needed?')"
						:disabled="busy" />
				</label>

				<!-- The team below what is asked, as on every service form. -->
				<label v-if="options.length > 1" class="expiry-request__field">
					<span>{{ t('teamhub', 'Team') }}</span>
					<NcSelect
						v-model="teamId"
						input-id="expiry-request-team"
						label-outside
						:options="teamOptions"
						:reduce="o => o.id"
						:clearable="false"
						:disabled="busy"
						:aria-label-combobox="t('teamhub', 'Team')" />
				</label>

				<p v-if="chosen" class="expiry-request__hint">
					{{ t('teamhub', '{team} expires on {date}.', { team: chosen.teamName, date: chosen.expiresOn }) }}
				</p>
				<p class="expiry-request__hint">
					{{ t('teamhub', 'The service team grants or declines it, with a reason. Granting sets the new date at once; you close the request in My Work.') }}
				</p>
				<p v-if="service && service.leadTime" class="expiry-request__hint">{{ service.leadTime }}</p>
			</template>

			<p v-if="waiting.length" class="expiry-request__hint">
				{{ t('teamhub', 'A request is already open for: {teams}', { teams: waiting.join(', ') }) }}
			</p>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="expiry-request__actions">
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
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea } from '@nextcloud/vue'
import LifebuoyIcon from 'vue-material-design-icons/Lifebuoy.vue'
import { ICON_BODY, ICON_INLINE } from '../../constants/uiTokens.js'
import { dayAfter, expiryRequestValid } from '../../constants/serviceTeams.js'

/**
 * The request for more time's form (v4.10.45) — a date and a reason, for
 * one team. Its own dialog, like the quota request's, because it asks for a
 * date and granting it *does* something: it sets the date. The server checks
 * everything again (an expiration date exists, the date is later, the caller
 * administers the team) and records the current date itself.
 */
export default {
	name: 'ExpiryRequestDialog',

	components: {
		NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea,
		LifebuoyIcon,
	},

	props: {
		/** The catalogue entry, when there is one: its label, desk and lead time. */
		service: { type: Object, default: null },
		/** `[{ teamId, teamName, expiresOn, openRequest }]` — `GET /service-teams/expiry-teams`. */
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
			date: '',
			reason: '',
		}
	},

	computed: {
		options() {
			return this.teams.filter(team => !team.openRequest)
		},

		teamOptions() {
			return this.options.map(team => ({ id: team.teamId, label: team.teamName }))
		},

		waiting() {
			return this.teams.filter(team => team.openRequest).map(team => team.teamName)
		},

		chosen() {
			return this.options.find(team => team.teamId === this.teamId) || null
		},

		minDate() {
			const today = new Date().toISOString().slice(0, 10)
			const after = dayAfter(this.chosen?.expiresOn)
			return after && after > today ? after : dayAfter(today)
		},

		canSubmit() {
			return !!this.chosen && expiryRequestValid({ date: this.date, reason: this.reason, currentOn: this.chosen.expiresOn })
		},
	},

	methods: {
		t,

		submit() {
			if (!this.canSubmit) {
				return
			}
			this.$emit('submit', {
				teamId: this.teamId,
				proposedOn: this.date,
				reason: this.reason.trim(),
			})
		},
	},
}
</script>

<style scoped lang="scss">
.expiry-request {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.expiry-request__title {
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
	margin: 0;
}

.expiry-request__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.expiry-request__desk {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	font-size: var(--th-font-meta, 13px);
}

.expiry-request__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.expiry-request__date {
	max-width: calc(var(--default-grid-baseline, 4px) * 50);
}

.expiry-request__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-sm, 8px);
}
</style>
