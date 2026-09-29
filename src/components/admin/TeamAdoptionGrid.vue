<template>
	<!-- v4.10.50 — the grid of teams made outside TeamHub (DESIGN §2.149).
	     One component, two homes: Admin → TeamHub → Team creation, and a
	     widget on the team that holds the Nextcloud services. The requests
	     in a service queue or in My Work link here, because this is where a
	     template and a policy are chosen per team before accepting. -->
	<div class="team-adoption" :class="{ 'team-adoption--compact': compact }">
		<div v-if="loading && !loaded" class="team-adoption__loading">
			<NcLoadingIcon :size="ICON_BODY" />
		</div>

		<NcNoteCard v-else-if="loadError" type="error">
			<p>{{ loadError }}</p>
			<NcButton variant="secondary" @click="load">
				{{ t('teamhub', 'Retry') }}
			</NcButton>
		</NcNoteCard>

		<template v-else>
			<NcEmptyContent
				v-if="pending.length === 0"
				:name="t('teamhub', 'No teams are waiting')"
				:description="t('teamhub', 'Teams made in Contacts, on the Teams page or by a provisioning tool appear here within a few minutes.')">
				<template #icon>
					<AccountMultipleCheckOutline :size="ICON_LARGE" />
				</template>
			</NcEmptyContent>

			<div v-else class="team-adoption__table-wrap">
				<table class="team-adoption__table">
					<caption class="team-adoption__sr">
						{{ t('teamhub', 'Teams waiting for a decision') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Team') }}</th>
							<th scope="col">{{ t('teamhub', 'Owner') }}</th>
							<th v-if="!compact" scope="col">{{ t('teamhub', 'Found') }}</th>
							<th v-if="!compact" scope="col">{{ t('teamhub', 'Asked') }}</th>
							<th scope="col">{{ t('teamhub', 'Template') }}</th>
							<th scope="col">{{ t('teamhub', 'Policy') }}</th>
							<th scope="col">
								<span class="team-adoption__sr">{{ t('teamhub', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in pending" :key="row.id">
							<td class="team-adoption__name">{{ row.teamName }}</td>
							<td>{{ row.ownerName || row.ownerUid || t('teamhub', 'No owner') }}</td>
							<td v-if="!compact">{{ formatWhen(row.detectedAt) }}</td>
							<td v-if="!compact">{{ routeLabel(row.route) }}</td>
							<td class="team-adoption__pick">
								<NcSelect
									:model-value="row.templateKey"
									:options="templateOptions"
									:reduce="o => o.id"
									:clearable="false"
									:disabled="busyId === row.id || row.claimedByOther"
									label-outside
									:aria-label-combobox="t('teamhub', 'Template for {team}', { team: row.teamName })"
									@update:model-value="value => chooseTemplate(row, value)" />
							</td>
							<td class="team-adoption__pick">
								<NcSelect
									:model-value="row.profileKey"
									:options="profileOptions"
									:reduce="o => o.id"
									:clearable="false"
									:disabled="busyId === row.id || row.claimedByOther"
									:placeholder="t('teamhub', 'No policy')"
									label-outside
									:aria-label-combobox="t('teamhub', 'Policy for {team}', { team: row.teamName })"
									@update:model-value="value => chooseProfile(row, value)" />
							</td>
							<td class="team-adoption__actions">
								<!-- Somebody else on the desk claimed the request:
								     only they (or an administrator) can answer it,
								     so the buttons are hidden, not disabled. -->
								<span v-if="row.claimedByOther" class="team-adoption__meta">
									{{ t('teamhub', 'Handled by {name}', { name: row.claimedByName || row.claimedBy }) }}
								</span>
								<template v-else>
									<NcButton
										variant="secondary"
										:disabled="busyId === row.id"
										@click="accept(row)">
										{{ t('teamhub', 'Accept') }}
									</NcButton>
									<NcButton
										variant="tertiary"
										:disabled="busyId === row.id"
										@click="askDecline(row)">
										{{ t('teamhub', 'Decline') }}
									</NcButton>
								</template>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="team-adoption__hint">
				{{ t('teamhub', 'Accepting shows the team in TeamHub at once and locks it against deletion from the Teams page. The template\'s apps are added within a few minutes, in the name of the team owner. A declined team stays as it is in Nextcloud and is not offered again.') }}
			</p>

			<template v-if="decided.length > 0">
				<h3 class="team-adoption__subheading">
					{{ t('teamhub', 'Decided in the last 30 days') }}
				</h3>
				<div class="team-adoption__table-wrap">
					<table class="team-adoption__table">
						<thead>
							<tr>
								<th scope="col">{{ t('teamhub', 'Team') }}</th>
								<th scope="col">{{ t('teamhub', 'Decision') }}</th>
								<th scope="col">{{ t('teamhub', 'By') }}</th>
								<th v-if="!compact" scope="col">{{ t('teamhub', 'When') }}</th>
								<th scope="col">{{ t('teamhub', 'Apps') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in decided" :key="row.id">
								<td class="team-adoption__name">{{ row.teamName }}</td>
								<td>
									<NcChip no-close :variant="decisionVariant(row.status)" :text="decisionLabel(row.status)" />
									<span v-if="row.reason" class="team-adoption__meta team-adoption__reason">{{ row.reason }}</span>
								</td>
								<td>{{ row.decidedBy ? (row.decidedByName || row.decidedBy) : t('teamhub', 'TeamHub') }}</td>
								<td v-if="!compact">{{ formatWhen(row.decidedAt) }}</td>
								<td>
									<template v-if="row.status === 'accepted'">
										{{ provisionLabel(row.provisionStatus) }}
										<span v-if="row.provisionNote" class="team-adoption__meta team-adoption__reason">{{ row.provisionNote }}</span>
									</template>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</template>

		<NcDialog
			v-if="declining"
			:name="t('teamhub', 'Decline {team}', { team: declining.teamName })"
			size="small"
			@closing="declining = null">
			<p class="team-adoption__dialog-text">
				{{ t('teamhub', 'The team stays as it is in Nextcloud, but it is not shown in TeamHub and is not offered again. The owner is told why.') }}
			</p>
			<NcTextArea
				v-model="declineReason"
				:label="t('teamhub', 'Reason')"
				:maxlength="1000"
				/>
			<template #actions>
				<NcButton variant="tertiary" :disabled="busyId !== null" @click="declining = null">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busyId !== null || declineReason.trim() === ''"
					@click="decline">
					{{ t('teamhub', 'Decline team') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import {
	NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea,
} from '@nextcloud/vue'
import AccountMultipleCheckOutline from 'vue-material-design-icons/AccountMultipleCheckOutline.vue'
import { ICON_BODY, ICON_LARGE } from '../../constants/uiTokens.js'
import { formatDateTime } from '../../lib/localDate.js'

/**
 * TeamAdoptionGrid (v4.10.50) — accept or decline teams made outside
 * TeamHub, with a template and a policy chosen per team.
 *
 * The server decides who may see it (`TeamAdoptionDecisionService::mayDecide()`);
 * its parents only mount it where that holds — the admin page, and the
 * widget gated on `serviceDeskConfig.handlesAdoption`.
 */
export default {
	name: 'TeamAdoptionGrid',

	components: {
		NcButton, NcChip, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea,
		AccountMultipleCheckOutline,
	},

	props: {
		/** The widget's narrow layout: fewer columns. */
		compact: { type: Boolean, default: false },
	},

	data() {
		return {
			ICON_BODY,
			ICON_LARGE,
			loading: false,
			loaded: false,
			loadError: '',
			pending: [],
			decided: [],
			templates: [],
			profiles: [],
			busyId: null,
			declining: null,
			declineReason: '',
		}
	},

	computed: {
		templateOptions() {
			return this.templates.map(tpl => ({ id: tpl.templateKey, label: tpl.label }))
		},
		profileOptions() {
			return this.profiles.map(p => ({ id: p.profileKey, label: p.label }))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/team-adoptions'))
				this.pending = data.pending || []
				this.decided = data.decided || []
				this.templates = data.templates || []
				this.profiles = data.profiles || []
				this.loaded = true
			} catch (e) {
				this.loadError = e?.response?.data?.error || t('teamhub', 'Could not load the teams made outside TeamHub.')
			} finally {
				this.loading = false
			}
		},

		/** Choosing a template also takes its default policy, as in the wizard. */
		chooseTemplate(row, templateKey) {
			const tpl = this.templates.find(x => x.templateKey === templateKey)
			this.save(row, templateKey, tpl?.defaultProfileKey || row.profileKey)
		},

		chooseProfile(row, profileKey) {
			this.save(row, row.templateKey, profileKey)
		},

		async save(row, templateKey, profileKey) {
			const before = { templateKey: row.templateKey, profileKey: row.profileKey }
			row.templateKey = templateKey
			row.profileKey = profileKey || ''
			this.busyId = row.id
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}', { id: row.id }),
					{ templateKey, profileKey: profileKey || '' },
				)
				Object.assign(row, data.adoption || {})
			} catch (e) {
				Object.assign(row, before)
				this.fail(e, t('teamhub', 'Could not save the choice.'))
			} finally {
				this.busyId = null
			}
		},

		async accept(row) {
			this.busyId = row.id
			try {
				await axios.post(
					generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}/accept', { id: row.id }),
					{ templateKey: row.templateKey, profileKey: row.profileKey || '' },
				)
				showSuccess(t('teamhub', '{team} added to TeamHub', { team: row.teamName }))
			} catch (e) {
				this.fail(e, t('teamhub', 'Could not accept the team.'))
			} finally {
				this.busyId = null
				this.load()
			}
		},

		askDecline(row) {
			this.declineReason = ''
			this.declining = row
		},

		async decline() {
			const row = this.declining
			if (!row) {
				return
			}
			this.busyId = row.id
			try {
				await axios.post(
					generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}/decline', { id: row.id }),
					{ reason: this.declineReason.trim() },
				)
				showSuccess(t('teamhub', '{team} declined', { team: row.teamName }))
				this.declining = null
			} catch (e) {
				this.fail(e, t('teamhub', 'Could not decline the team.'))
			} finally {
				this.busyId = null
				this.load()
			}
		},

		fail(e, fallback) {
			showError(e?.response?.data?.error || fallback)
		},

		formatWhen(secs) {
			return secs ? formatDateTime(secs * 1000) : ''
		},

		routeLabel(route) {
			switch (route) {
			case 'desk':
				// TRANSLATORS: who was asked to decide about a team made outside TeamHub - the service team's request queue
				return t('teamhub', 'Service team')
			case 'admin':
				// TRANSLATORS: who was asked to decide about a team made outside TeamHub - a task in the administrators' My Work
				return t('teamhub', 'Administrators in My Work')
			case 'auto':
				return t('teamhub', 'Accepted by itself')
			default:
				// TRANSLATORS: who was asked to decide about a team made outside TeamHub - nobody but this list
				return t('teamhub', 'This list')
			}
		},

		decisionLabel(status) {
			switch (status) {
			case 'accepted':
				return t('teamhub', 'Accepted')
			case 'declined':
				return t('teamhub', 'Declined')
			default:
				// TRANSLATORS: a team made outside TeamHub that no longer needs a decision (deleted, or added another way)
				return t('teamhub', 'Withdrawn')
			}
		},

		decisionVariant(status) {
			if (status === 'accepted') {
				return 'success'
			}
			return status === 'declined' ? 'error' : 'tertiary'
		},

		provisionLabel(status) {
			switch (status) {
			case 'done':
				return t('teamhub', 'Added')
			case 'failed':
				return t('teamhub', 'Needs a hand')
			case 'queued':
				return t('teamhub', 'Being added')
			default:
				return ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.team-adoption__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg);
}

/* The grid is a rounded card (Justin, 2026-09-26): NC's container radius,
   and the table scrolls inside it rather than stretching the page. */
.team-adoption__table-wrap {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-main-background);
}

.team-adoption__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--th-font-meta);
	line-height: var(--th-line-height-body);

	th, td {
		text-align: start;
		padding: var(--th-space-sm) var(--th-space-md);
		vertical-align: middle;
	}

	th {
		font-weight: var(--th-font-weight-semibold);
		color: var(--color-text-maxcontrast);
		border-block-end: 1px solid var(--color-border);
	}

	tbody tr + tr td {
		border-block-start: 1px solid var(--color-border);
	}
}

.team-adoption__name {
	font-weight: var(--th-font-weight-semibold);
}

.team-adoption__pick {
	min-width: calc(40 * var(--default-grid-baseline));
}

.team-adoption__actions {
	white-space: nowrap;

	> * + * {
		margin-inline-start: var(--th-space-xs);
	}
}

.team-adoption__meta {
	color: var(--color-text-maxcontrast);
}

.team-adoption__reason {
	display: block;
	margin-block-start: var(--th-space-xs);
}

.team-adoption__hint {
	margin: var(--th-space-sm) 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.team-adoption__subheading {
	margin: var(--th-space-lg) 0 var(--th-space-sm);
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
}

.team-adoption__dialog-text {
	margin-block-end: var(--th-space-sm);
}

.team-adoption--compact .team-adoption__pick {
	min-width: calc(32 * var(--default-grid-baseline));
}

.team-adoption__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
</style>
