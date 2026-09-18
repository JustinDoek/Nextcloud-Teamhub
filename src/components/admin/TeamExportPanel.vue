<template>
	<div class="team-export">

		<p class="team-export__intro">
			{{ t('teamhub', 'Write teams out to a CSV file in the same format the importer reads. Account names, groups, the template, its policy and the expiration date all come along. Apps and modules are not written — a re-imported team is provisioned from its template, exactly as the New Team wizard would.') }}
		</p>

		<!-- Said here rather than discovered later: the importer refuses a name
		     that already exists, so feeding an unedited export straight back in
		     skips every row. That is correct — it is an importer, not a sync —
		     but it is the first thing an admin will try. -->
		<p class="team-export__intro team-export__intro--caution">
			{{ t('teamhub', 'Importing this file back into the same server creates nothing: every team in it already exists, and the importer skips a name it already knows. Use it to move teams to another server, to build new teams by editing the rows first, or as a record of how things are set up today.') }}
		</p>

		<!-- Transient status region, matching the import panel: a screen reader
		     hears the outcome of an action whose result renders elsewhere. -->
		<div class="team-export__status" role="status" aria-live="polite">
			<template v-if="busyLabel">
				<NcLoadingIcon :size="iconBody" />
				<span>{{ busyLabel }}</span>
			</template>
			<span v-else-if="error" class="team-export__status-err">{{ error }}</span>
			<span v-else-if="notice" class="team-export__status-ok">{{ notice }}</span>
		</div>

		<!-- ── Scope ──────────────────────────────────────────────────── -->
		<fieldset class="team-export__scope">
			<legend class="team-export__legend">{{ t('teamhub', 'What to export') }}</legend>

			<NcCheckboxRadioSwitch
				v-model="scope"
				value="all"
				name="team_export_scope"
				type="radio">
				{{ t('teamhub', 'Every team on this server') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				v-model="scope"
				value="selection"
				name="team_export_scope"
				type="radio">
				{{ t('teamhub', 'Only the teams I pick') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<!-- ── Team picker ────────────────────────────────────────────── -->
		<div v-if="scope === 'selection'" class="team-export__picker">
			<label for="team-export-select" class="team-export__label">
				{{ t('teamhub', 'Teams') }}
			</label>
			<NcSelect
				v-model="selected"
				input-id="team-export-select"
				:options="teamOptions"
				:multiple="true"
				:close-on-select="false"
				:loading="loadingTeams"
				:clearable="true"
				label="label"
				track-by="id"
				:placeholder="t('teamhub', 'Search teams…')"
				:aria-label="t('teamhub', 'Teams to export')" />

			<div class="team-export__picker-actions">
				<NcButton
					variant="tertiary"
					:disabled="loadingTeams || !teamOptions.length"
					@click="selectAll">
					{{ t('teamhub', 'Select all') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!selected.length"
					@click="selected = []">
					{{ t('teamhub', 'Clear selection') }}
				</NcButton>
				<span class="team-export__count">
					<!-- TRANSLATORS: N is how many teams the admin has picked for export -->
					{{ n('teamhub', '{n} team selected', '{n} teams selected', selected.length, { n: selected.length }) }}
				</span>
			</div>
		</div>

		<!-- ── Actions ────────────────────────────────────────────────── -->
		<div class="team-export__actions">
			<NcButton
				variant="secondary"
				:disabled="busy || !hasSomethingToExport"
				@click="loadPreview">
				<template #icon><FileTableOutlineIcon :size="iconBody" /></template>
				{{ t('teamhub', 'Check what will be exported') }}
			</NcButton>

			<NcButton
				variant="primary"
				:disabled="busy || !hasSomethingToExport"
				@click="download">
				<template #icon><DownloadIcon :size="iconBody" /></template>
				{{ t('teamhub', 'Download CSV') }}
			</NcButton>
		</div>

		<!-- ── Preview ────────────────────────────────────────────────── -->
		<template v-if="preview">
			<h4 class="team-export__heading">{{ t('teamhub', 'What the file will contain') }}</h4>

			<p class="team-export__summary">
				{{ n('teamhub', '{n} team will be written.', '{n} teams will be written.', preview.total, { n: preview.total }) }}
				<template v-if="preview.blocked > 0">
					{{ n('teamhub',
					     '{n} of them cannot be imported again as-is — see below.',
					     '{n} of them cannot be imported again as-is — see below.',
					     preview.blocked,
					     { n: preview.blocked }) }}
				</template>
				<template v-else>
					{{ t('teamhub', 'All of them can be imported again without editing.') }}
				</template>
			</p>

			<!-- Only the rows with something to say. A clean export has nothing
			     here, and a table of 200 "no problems" rows would bury the three
			     that matter. -->
			<div v-if="flaggedRows.length" class="team-export__table-wrap">
				<table class="team-export__table">
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Team') }}</th>
							<th scope="col">{{ t('teamhub', 'What is missing') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in flaggedRows" :key="row.teamId">
							<td>{{ row.name }}</td>
							<td>
								<span v-for="warning in row.warnings" :key="warning.code" class="team-export__warning">
									{{ warning.message }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="team-export__hint">
				{{ t('teamhub', 'The file is written anyway — it is a faithful picture of what is on this server. A row that cannot be imported again is reported when you try, and the other rows are unaffected.') }}
			</p>
		</template>

		<p class="team-export__hint team-export__hint--privacy">
			{{ t('teamhub', 'The file lists the account names of every member of every exported team. Downloading one is recorded in the audit log, per team.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FileTableOutlineIcon from 'vue-material-design-icons/FileTableOutline.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'

/**
 * Bulk team export (v4.6.14) — sits under the importer on Admin → TeamHub →
 * Import/Export, because the two share one CSV contract and reading it next to
 * the panel that consumes it is the point.
 *
 * ## Why the download is a plain navigation
 *
 * `window.location` rather than an XHR with a Blob: the response carries
 * `Content-Disposition`, so letting the browser own it gets the filename, the
 * downloads folder and the progress UI for free. The cost is that errors come
 * back as a JSON page rather than a toast, which is why "Check what will be
 * exported" exists — it is the same selection through an endpoint that can
 * answer in the UI, and it runs before anything is written.
 *
 * ## Why the preview only lists problem rows
 *
 * The server returns a verdict for every row, and rendering all of them would
 * put the two teams that need attention below two hundred that do not. The
 * count is in the summary line; the table is the exceptions.
 */
export default {
	name: 'TeamExportPanel',
	components: {
		NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect,
		DownloadIcon, FileTableOutlineIcon,
	},

	data() {
		return {
			scope: 'all',
			teams: [],
			selected: [],
			loadingTeams: false,
			loadingPreview: false,
			error: null,
			notice: null,
			/** @type {?{total:number, blocked:number, reimportable:number, rows:Array}} */
			preview: null,
			iconBody: ICON_BODY,
		}
	},

	computed: {
		busy() {
			return this.loadingTeams || this.loadingPreview
		},

		busyLabel() {
			if (this.loadingTeams) return t('teamhub', 'Loading teams…')
			if (this.loadingPreview) return t('teamhub', 'Checking the selection…')
			return null
		},

		teamOptions() {
			return this.teams.map(team => ({
				id: team.id,
				// The template is part of the label rather than a second column
				// because NcSelect renders one line per option, and "which
				// Marketing is this" is the question a picker has to answer.
				label: team.template
					? `${team.name} · ${this.templateLabel(team.template)}`
					: team.name,
			}))
		},

		/** Exporting nothing is not an error, it is a disabled button. */
		hasSomethingToExport() {
			return this.scope === 'all' || this.selected.length > 0
		},

		selectedIds() {
			return this.scope === 'all' ? [] : this.selected.map(option => option.id)
		},

		flaggedRows() {
			return (this.preview?.rows || []).filter(row => row.warnings?.length)
		},
	},

	watch: {
		/**
		 * A preview belongs to the selection it was built from. Leaving it on
		 * screen after the scope or the picks change would show an answer to a
		 * question the admin is no longer asking — and the counts in it would
		 * be quietly wrong rather than visibly absent.
		 */
		scope() {
			this.preview = null
			this.notice = null
		},
		selected() {
			this.preview = null
			this.notice = null
		},
	},

	mounted() {
		this.loadTeams()
	},

	methods: {
		t,
		n,

		templateLabel(template) {
			switch (template) {
			case 'collaboration':
				return t('teamhub', 'Collaboration')
			case 'project':
				return t('teamhub', 'Project')
			case 'department':
				return t('teamhub', 'Department')
			case 'openproject':
				return t('teamhub', 'OpenProject project')
			default:
				return template
			}
		},

		async loadTeams() {
			this.loadingTeams = true
			this.error = null
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/admin/export/teams/selectable')
				)
				this.teams = Array.isArray(data.teams) ? data.teams : []
			} catch (e) {
				this.error = e?.response?.data?.error || t('teamhub', 'Failed to load the team list')
			} finally {
				this.loadingTeams = false
			}
		},

		selectAll() {
			this.selected = [...this.teamOptions]
		},

		async loadPreview() {
			this.loadingPreview = true
			this.error = null
			this.notice = null
			try {
				const { data } = await axios.post(
					generateUrl('/apps/teamhub/api/v1/admin/export/teams/preview'),
					{ teamIds: this.selectedIds }
				)
				this.preview = data
			} catch (e) {
				this.preview = null
				this.error = e?.response?.data?.error || t('teamhub', 'Failed to check the selection')
			} finally {
				this.loadingPreview = false
			}
		},

		download() {
			this.error = null

			// Team ids only — no account names travel in the query string,
			// which is the rule for anything that lands in a server log.
			const params = new URLSearchParams()
			for (const id of this.selectedIds) {
				params.append('teamIds[]', id)
			}
			const query = params.toString()

			window.location.href = generateUrl(
				'/apps/teamhub/api/v1/admin/export/teams/download'
			) + (query ? `?${query}` : '')

			this.notice = t('teamhub', 'Building the file. Your browser will save it when it is ready.')
		},
	},
}
</script>

<style scoped lang="scss">
.team-export {
	display: flex;
	flex-direction: column;
	gap: 14px;
	max-width: 980px;
}

.team-export__intro {
	margin: 0;
	color: var(--color-text-light);
	font-size: var(--th-font-body, 14px);
	line-height: var(--th-line-height-body, 1.4);
}

/* A standing caveat rather than an alert — it is always true, so it gets a
   left rule and the muted colour rather than a warning background that would
   read as something having gone wrong. */
.team-export__intro--caution {
	border-inline-start: 3px solid var(--color-border-dark);
	padding-inline-start: 10px;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 12px);
}

/* Reserves its height when empty so nothing shifts as messages appear —
   same treatment as the import panel directly above it. */
.team-export__status {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 24px;
	font-size: var(--th-font-body, 14px);
}

.team-export__status-err {
	color: var(--color-error-text);
}

.team-export__status-ok {
	color: var(--color-success-text);
}

.team-export__scope {
	border: none;
	margin: 0;
	padding: 0;
}

.team-export__legend {
	padding: 0;
	margin-bottom: 4px;
	font-weight: var(--th-font-weight-semibold, 600);
	font-size: var(--th-font-body, 14px);
}

.team-export__picker {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.team-export__label {
	font-weight: var(--th-font-weight-semibold, 600);
	font-size: var(--th-font-body, 14px);
}

.team-export__picker-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.team-export__count {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 12px);
}

.team-export__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.team-export__heading {
	margin: 6px 0 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.team-export__summary {
	margin: 0;
	font-size: var(--th-font-body, 14px);
}

.team-export__table-wrap {
	overflow-x: auto;
}

.team-export__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--th-font-meta, 12px);

	th,
	td {
		text-align: start;
		padding: 6px 10px;
		border-bottom: 1px solid var(--color-border);
		vertical-align: top;
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: var(--th-font-weight-semibold, 600);
	}
}

.team-export__warning {
	display: block;
}

.team-export__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 12px);
	line-height: var(--th-line-height-body, 1.4);
}

.team-export__hint--privacy {
	margin-top: 4px;
}
</style>
