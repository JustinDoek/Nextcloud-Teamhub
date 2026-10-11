<template>
	<!-- v4.10.50 — the grid of teams made outside TeamHub (DESIGN §2.149).
	     One component, two homes: Admin → TeamHub → Import/Export (top of
	     the tab since 2026-09-29; was the foot of Team creation), and a
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
			<!-- v4.11.34 — the description follows the instance's rule: in
			     the two automatic modes nothing ever waits here, so promising
			     that teams turn up within a few minutes would be a lie. The
			     server sends the mode because this component is also a widget
			     on the holding team, where the setting is out of reach. -->
			<NcEmptyContent
				v-if="queue.length === 0"
				:name="t('teamhub', 'No teams are waiting')"
				:description="emptyDescription">
				<template #icon>
					<AccountMultipleCheckOutline :size="ICON_LARGE" />
				</template>
			</NcEmptyContent>

			<div v-else class="team-adoption__table-wrap">
				<table class="team-adoption__table">
					<caption class="team-adoption__sr">
						{{ t('teamhub', 'Teams and groups waiting for a decision') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Team or group') }}</th>
							<th v-if="directory" scope="col">{{ t('teamhub', 'Comes from') }}</th>
							<th scope="col">{{ t('teamhub', 'Who') }}</th>
							<th v-if="!compact" scope="col">{{ t('teamhub', 'Found') }}</th>
							<th scope="col">{{ t('teamhub', 'Template') }}</th>
							<th scope="col">{{ t('teamhub', 'Policy') }}</th>
							<th scope="col">
								<span class="team-adoption__sr">{{ t('teamhub', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in queue" :key="row.key">
							<td class="team-adoption__name">
								{{ row.name }}
								<span v-if="row.sub" class="team-adoption__meta team-adoption__reason">{{ row.sub }}</span>
							</td>
							<!-- The pill is the whole reason one list works
							     (Justin, 2026-10-09): the two kinds answer
							     different questions and the row has to say
							     which it is before anybody presses Accept. -->
							<td v-if="directory">
								<NcChip no-close variant="tertiary" :text="kindLabel(row.kind)" />
							</td>
							<td>{{ row.who }}</td>
							<td v-if="!compact">{{ formatWhen(row.detectedAt) }}</td>
							<td class="team-adoption__pick">
								<NcSelect
									:model-value="row.templateKey"
									:options="templateOptions"
									:reduce="o => o.id"
									:clearable="false"
									:disabled="busyKey === row.key || row.claimedByOther"
									label-outside
									:aria-label-combobox="t('teamhub', 'Template for {team}', { team: row.name })"
									@update:model-value="value => chooseTemplate(row, value)" />
							</td>
							<td class="team-adoption__pick">
								<NcSelect
									:model-value="row.profileKey"
									:options="profileOptions"
									:reduce="o => o.id"
									:clearable="false"
									:disabled="busyKey === row.key || row.claimedByOther"
									:placeholder="t('teamhub', 'No policy')"
									label-outside
									:aria-label-combobox="t('teamhub', 'Policy for {team}', { team: row.name })"
									@update:model-value="value => chooseProfile(row, value)" />
							</td>
							<td class="team-adoption__actions">
								<!-- Somebody else on the desk claimed the request:
								     only they (or an administrator) can answer it,
								     so the buttons are hidden, not disabled. -->
								<span v-if="row.claimedByOther" class="team-adoption__meta">
									{{ t('teamhub', 'Handled by {name}', { name: row.claimedByName || row.claimedBy }) }}
								</span>
								<!-- One row, one weight (Justin, 2026-09-29): the two
								     answers are equal choices, so neither looks like
								     the default. NcButton is display: flex, so the
								     wrapper is what keeps them side by side. -->
								<div v-else class="team-adoption__buttons">
									<NcButton
										variant="secondary"
										:disabled="busyKey === row.key"
										@click="accept(row)">
										{{ t('teamhub', 'Accept') }}
									</NcButton>
									<NcButton
										variant="secondary"
										:disabled="busyKey === row.key"
										@click="askDecline(row)">
										{{ t('teamhub', 'Decline') }}
									</NcButton>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="team-adoption__hint">
				{{ t('teamhub', 'Accepting a team shows it in TeamHub at once and locks it against deletion from the Teams page; its apps are added within a few minutes. Accepting a group builds a new team whose members follow the group. Declining leaves either one exactly as it is in Nextcloud.') }}
			</p>

			<!-- v4.11.39 — what has been decided is in the audit log now
			     (DESIGN §2.172). Both halves of this grid kept their own
			     thirty-day table, which was a third surface for one history
			     and the only record of a group that failed; the Compliance
			     tab already holds every other decision TeamHub makes, keeps
			     it for the retention window rather than thirty days, and
			     exports. The link is admin-only because that tab is. -->
			<p v-if="!compact && directory" class="team-adoption__hint">
				{{ t('teamhub', 'Every decision is recorded in the audit log, under Compliance.') }}
				<a :href="auditLogUrl">{{ t('teamhub', 'Open the audit log') }}</a>
			</p>
		</template>

		<NcDialog
			v-if="declining"
			:name="t('teamhub', 'Decline {team}', { team: declining.name })"
			size="small"
			@closing="declining = null">
			<p class="team-adoption__dialog-text">
				{{ declineExplanation }}
			</p>
			<NcTextArea
				v-model="declineReason"
				:label="t('teamhub', 'Reason')"
				:maxlength="1000"
				/>
			<template #actions>
				<NcButton variant="tertiary" :disabled="busyKey !== null" @click="declining = null">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busyKey !== null || declineReason.trim() === ''"
					@click="decline">
					{{ t('teamhub', 'Decline') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
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

		/**
		 * The instance's rule, when the parent already knows it (v4.11.36).
		 *
		 * The admin panel knows it *before the server does* — it has just
		 * changed it and the save is still in flight. Reading it only from
		 * our own fetch made the empty state show the previous mode's
		 * sentence until the next load: flip to *Never accept it* and the
		 * line still claimed teams were being accepted automatically.
		 *
		 * Empty means "ask the server", which is what the widget on the
		 * holding team does: an administrator's setting is out of reach
		 * there, so the fetch is the only source.
		 */
		mode: { type: String, default: '' },

		/**
		 * Also list the Nextcloud groups waiting for a team (v4.11.38).
		 *
		 * One queue, two kinds, told apart by a pill (Justin, 2026-10-09).
		 * They are the same question asked from two ends — a team that
		 * exists and has no record, and a group that has a record and no
		 * team — and answering them in two places meant two empty states
		 * and two sets of words for one decision.
		 *
		 * Off by default, because the widget on the holding team must not
		 * show them: a desk decides adoptions, directory groups are a
		 * Nextcloud administrator's, and the endpoint behind them is
		 * admin-gated.
		 */
		directory: { type: Boolean, default: false },

		/** What a directory row's pickers start on, from the Team creation tab. */
		directoryTemplate: { type: String, default: '' },
		directoryProfile: { type: String, default: '' },
	},

	data() {
		return {
			ICON_BODY,
			ICON_LARGE,
			loading: false,
			loaded: false,
			loadError: '',
			// v4.11.34 — the instance's rule, as the server reports it:
			// 'accept', 'review' or 'decline'. Read-only here; it is set in
			// Admin → TeamHub → Import/Export. The `mode` prop wins over it
			// when the parent passes one (v4.11.36).
			serverMode: 'review',
			pending: [],
			templates: [],
			profiles: [],
			// v4.11.38 — the directory half, fetched only when asked for.
			directoryPending: [],
			directoryProblem: '',
			// Keyed rather than by id: the two kinds number their rows
			// independently, so an id alone is ambiguous in one list.
			busyKey: null,
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

		/** The rule to render by: the parent's, else the server's. */
		effectiveMode() {
			return this.mode || this.serverMode
		},

		/**
		 * One queue, both kinds (v4.11.38). The row shape is the table's,
		 * not either endpoint's, so the markup never asks which kind it is
		 * except for the pill and the two dispatches.
		 */
		queue() {
			const out = this.pending.map(row => ({
				key: 'a' + row.id,
				kind: 'adoption',
				id: row.id,
				name: row.teamName,
				who: row.ownerName || row.ownerUid || t('teamhub', 'No owner'),
				sub: '',
				detectedAt: row.detectedAt,
				templateKey: row.templateKey,
				profileKey: row.profileKey,
				claimedByOther: row.claimedByOther,
				claimedByName: row.claimedByName || row.claimedBy,
				raw: row,
			}))
			if (this.directory) {
				for (const row of this.directoryPending) {
					out.push({
						key: 'd' + row.id,
						kind: 'directory',
						id: row.id,
						name: row.displayName || row.groupId,
						who: n('teamhub', '%n person', '%n people', row.memberCount, { n: row.memberCount })
							+ (row.backend ? ' \u00b7 ' + row.backend : ''),
						sub: row.groupDn || '',
						detectedAt: row.detectedAt,
						// Held on the row rather than on the server: a
						// directory group has no stored pair until it is
						// accepted, so these start on the tab's defaults.
						templateKey: row.templateKey || this.directoryTemplate,
						profileKey: row.profileKey || this.directoryProfile,
						claimedByOther: false,
						claimedByName: '',
						raw: row,
					})
				}
			}
			return out.sort((a, b) => (b.detectedAt || 0) - (a.detectedAt || 0))
		},

		/**
		 * Compliance → Audit log, where the decisions are kept (v4.11.39).
		 *
		 * `#audit-log`, not `#tab-panel-audit`: the panel's own id is not a
		 * hash the admin page acts on, so linking to it lands on the default
		 * tab. `AdminSettings.mounted()` switches tabs for this one.
		 */
		auditLogUrl() {
			return generateUrl('/settings/admin/teamhub') + '#audit-log'
		},

		/**
		 * The decline dialog's sentence. The two kinds lose different
		 * things, and the generic wording was written for a team: a group
		 * has no owner to tell and nothing of its own is touched.
		 */
		declineExplanation() {
			if (this.declining && this.declining.kind === 'directory') {
				return t('teamhub', 'The group keeps working in Nextcloud, no team is made for it, and it is not offered again. Nobody is notified.')
			}
			return t('teamhub', 'The team stays as it is in Nextcloud, but it is not shown in TeamHub and is not offered again. The owner is told why.')
		},

		/**
		 * Why the list is empty, which depends on the rule (v4.11.34). In
		 * `review` mode a team turns up here on its own; in the other two it
		 * is decided before anybody sees it, and the audit log under
		 * Compliance is where it shows up instead (v4.11.39).
		 */
		emptyDescription() {
			// With both kinds in the list the mode-specific sentences below
			// are only half the story: they speak about teams made
			// elsewhere, while the groups half follows its own setting
			// (v4.11.38). One neutral sentence is the only one that cannot
			// be wrong about the other half.
			if (this.directory) {
				return t('teamhub', 'Teams made elsewhere in Nextcloud, and Nextcloud groups that qualify for a team, appear here within a few minutes.')
			}
			if (this.effectiveMode === 'accept') {
				return t('teamhub', 'This instance accepts a team made outside TeamHub automatically, so none ever waits for a decision.')
			}
			if (this.effectiveMode === 'decline') {
				return t('teamhub', 'This instance never accepts a team made outside TeamHub, so none ever waits for a decision.')
			}
			return t('teamhub', 'Teams made in Contacts, on the Teams page or by a provisioning tool appear here within a few minutes.')
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
				this.serverMode = data.mode || 'review'
				this.pending = data.pending || []
				this.templates = data.templates || []
				this.profiles = data.profiles || []
				if (this.directory) {
					await this.loadDirectory()
				}
				this.loaded = true
			} catch (e) {
				this.loadError = e?.response?.data?.error || t('teamhub', 'Could not load the teams made outside TeamHub.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The directory half (v4.11.38). Its own try/catch: this endpoint is
		 * admin-gated and the adoption one is not, so a reader who may see
		 * the teams but not the groups gets the teams rather than an error
		 * page — and a directory failure must not blank the queue.
		 */
		async loadDirectory() {
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/idp-groups'))
				this.directoryPending = (data.pending || []).map(row => ({
					...row,
					templateKey: this.directoryTemplate,
					profileKey: this.directoryProfile,
				}))
				this.directoryProblem = data.serviceAccountProblem || ''
			} catch (e) {
				this.directoryPending = []
				this.directoryProblem = ''
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

		/**
		 * An adoption row's pair is stored server-side, because a request in
		 * a desk queue or in My Work accepts with whatever the grid holds.
		 * A directory row has no such request and no stored pair, so its
		 * choice lives on the row until Accept carries it.
		 */
		async save(row, templateKey, profileKey) {
			const before = { templateKey: row.templateKey, profileKey: row.profileKey }
			row.templateKey = templateKey
			row.profileKey = profileKey || ''
			if (row.kind === 'directory') {
				Object.assign(row.raw, { templateKey: row.templateKey, profileKey: row.profileKey })
				return
			}
			this.busyKey = row.key
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}', { id: row.id }),
					{ templateKey, profileKey: profileKey || '' },
				)
				Object.assign(row.raw, data.adoption || {})
			} catch (e) {
				Object.assign(row, before)
				this.fail(e, t('teamhub', 'Could not save the choice.'))
			} finally {
				this.busyKey = null
			}
		},

		async accept(row) {
			this.busyKey = row.key
			const url = row.kind === 'directory'
				? generateUrl('/apps/teamhub/api/v1/admin/idp-groups/{id}/create', { id: row.id })
				: generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}/accept', { id: row.id })
			try {
				await axios.post(url, { templateKey: row.templateKey, profileKey: row.profileKey || '' })
				showSuccess(row.kind === 'directory'
					? t('teamhub', 'Team created for {group}', { group: row.name })
					: t('teamhub', '{team} added to TeamHub', { team: row.name }))
			} catch (e) {
				this.fail(e, t('teamhub', 'Could not accept it.'))
			} finally {
				this.busyKey = null
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
			this.busyKey = row.key
			const url = row.kind === 'directory'
				? generateUrl('/apps/teamhub/api/v1/admin/idp-groups/{id}/decline', { id: row.id })
				: generateUrl('/apps/teamhub/api/v1/team-adoptions/{id}/decline', { id: row.id })
			try {
				await axios.post(url, { reason: this.declineReason.trim() })
				showSuccess(t('teamhub', '{team} declined', { team: row.name }))
				this.declining = null
			} catch (e) {
				this.fail(e, t('teamhub', 'Could not decline it.'))
			} finally {
				this.busyKey = null
				this.load()
			}
		},

		fail(e, fallback) {
			showError(e?.response?.data?.error || fallback)
		},

		formatWhen(secs) {
			return secs ? formatDateTime(secs * 1000) : ''
		},

		kindLabel(kind) {
			return kind === 'directory'
				// TRANSLATORS: pill on a queue row - this one is a Nextcloud group that has no team yet
				? t('teamhub', 'Nextcloud group')
				// TRANSLATORS: pill on a queue row - this one is a team that already exists outside TeamHub
				: t('teamhub', 'Team made elsewhere')
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
   and the table scrolls inside it rather than stretching the page. The
   scroll is the narrow-screen fallback only: at admin-page widths the
   columns fit (see .team-adoption__pick). */
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

/* NcSelect carries its own `min-width: 260px`; two of them per row pushed
   the table past the page and gave it a horizontal scrollbar (Justin,
   2026-09-29). The drop-downs take the column's width instead, 144px at
   least — enough for a template or policy name. */
.team-adoption__pick {
	min-width: calc(36 * var(--default-grid-baseline));

	:deep(.v-select.select) {
		width: 100%;
		min-width: calc(36 * var(--default-grid-baseline));
	}
}

.team-adoption__actions {
	white-space: nowrap;
}

.team-adoption__buttons {
	display: flex;
	flex-wrap: nowrap;
	align-items: center;
	gap: var(--th-space-xs);
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

.team-adoption__dialog-text {
	margin-block-end: var(--th-space-sm);
}

.team-adoption--compact .team-adoption__pick {
	min-width: calc(32 * var(--default-grid-baseline));

	:deep(.v-select.select) {
		min-width: calc(32 * var(--default-grid-baseline));
	}
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
