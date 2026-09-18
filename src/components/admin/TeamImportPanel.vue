<template>
	<div class="team-import">

		<p class="team-import__intro">
			{{ t('teamhub', 'Create many teams at once from a CSV file. Every team is set up exactly as the New Team wizard would — apps, modules and privacy settings all follow the template and policy you name in each row — and each team gets the owner and team admins you name, which the wizard cannot do.') }}
		</p>

		<!-- Status region. Transient only, so a screen reader hears the outcome
		     of an action whose result is rendered elsewhere on the page. -->
		<div class="team-import__status" role="status" aria-live="polite">
			<template v-if="busyLabel">
				<NcLoadingIcon :size="iconBody" />
				<span>{{ busyLabel }}</span>
			</template>
			<span v-else-if="error" class="team-import__status-err">{{ error }}</span>
			<span v-else-if="notice" class="team-import__status-ok">{{ notice }}</span>
		</div>

		<!-- ── Idle ───────────────────────────────────────────────────── -->
		<template v-if="stage === 'idle'">
			<div class="team-import__actions">
				<NcButton variant="secondary" @click="downloadSample">
					<template #icon><DownloadIcon :size="iconBody" /></template>
					{{ t('teamhub', 'Download sample CSV') }}
				</NcButton>

				<label class="team-import__file-label" for="team-import-file">
					{{ t('teamhub', 'CSV file') }}
				</label>
				<input
					id="team-import-file"
					ref="fileInput"
					type="file"
					accept=".csv,text/csv"
					class="team-import__file"
					:disabled="uploading"
					@change="onFileChange" />
			</div>

			<p class="team-import__hint">
				{{ t('teamhub', 'One row per team, with a header row. Up to {rows} teams and 2 MB per file. Columns may be separated by a comma, a semicolon or a tab.', { rows: maxRows }) }}
			</p>

			<h4 class="team-import__heading">{{ t('teamhub', 'Columns') }}</h4>
			<div class="team-import__table-wrap">
				<table class="team-import__table">
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Column') }}</th>
							<th scope="col">{{ t('teamhub', 'Required') }}</th>
							<th scope="col">{{ t('teamhub', 'Accepted values') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="column in columnReference" :key="column.name">
							<td><code>{{ column.name }}</code></td>
							<td>{{ column.required ? t('teamhub', 'Yes') : t('teamhub', 'No') }}</td>
							<td>{{ column.values }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<p class="team-import__hint">
				{{ t('teamhub', 'Cells that hold more than one value are split on a semicolon or a vertical bar, so one of the two always works whichever column separator your spreadsheet used.') }}
			</p>

			<p class="team-import__hint">
				{{ t('teamhub', 'Every name in the admin column and every member is checked against a real account before anything is created. Names are matched without regard to case, and the preview shows you which account each one resolved to. A row whose first admin matches no account is never created; a later admin or a member with no matching account is dropped and the rest of the team is created without them.') }}
			</p>

			<!-- ── Recent runs ─────────────────────────────────────────── -->
			<template v-if="recent.length">
				<h4 class="team-import__heading">{{ t('teamhub', 'Recent imports') }}</h4>
				<div class="team-import__table-wrap">
					<table class="team-import__table">
						<thead>
							<tr>
								<th scope="col">{{ t('teamhub', 'File') }}</th>
								<th scope="col">{{ t('teamhub', 'Status') }}</th>
								<th scope="col">{{ t('teamhub', 'Created') }}</th>
								<th scope="col">{{ t('teamhub', 'Skipped') }}</th>
								<th scope="col">{{ t('teamhub', 'Failed') }}</th>
								<th scope="col">{{ t('teamhub', 'Started') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="run in recent" :key="run.id">
								<td>{{ run.filename }}</td>
								<td>{{ runStatusLabel(run.status) }}</td>
								<td>{{ run.created_count }}</td>
								<td>{{ run.skipped_count }}</td>
								<td>{{ run.failed_count }}</td>
								<td>{{ formatTimestamp(run.started_at || run.created_at) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div class="team-import__actions">
					<NcButton
						v-if="resumableRun"
						variant="secondary"
						@click="resume(resumableRun.id)">
						{{ t('teamhub', 'Open the import that is still running') }}
					</NcButton>
				</div>
			</template>
		</template>

		<!-- ── Preview / Running / Done ───────────────────────────────── -->
		<template v-else>
			<div class="team-import__summary">
				<span class="team-import__count team-import__count--ready">
					{{ n('teamhub', '{n} team ready', '{n} teams ready', summary.ready, { n: summary.ready }) }}
				</span>
				<span class="team-import__sep" aria-hidden="true">·</span>
				<span class="team-import__count team-import__count--created">
					{{ n('teamhub', '{n} created', '{n} created', summary.created, { n: summary.created }) }}
				</span>
				<span class="team-import__sep" aria-hidden="true">·</span>
				<span class="team-import__count team-import__count--skipped">
					{{ n('teamhub', '{n} skipped', '{n} skipped', summary.skipped, { n: summary.skipped }) }}
				</span>
				<span class="team-import__sep" aria-hidden="true">·</span>
				<span class="team-import__count team-import__count--error">
					{{ n('teamhub', '{n} error', '{n} errors', errorTotal, { n: errorTotal }) }}
				</span>
			</div>

			<!-- Progress. The percentage is also written out in the label so the
			     bar is never the only way to read it (WCAG 1.4.1). -->
			<div v-if="stage === 'running'" class="team-import__progress">
				<div
					class="team-import__progress-track"
					role="progressbar"
					:aria-valuenow="progressPercent"
					aria-valuemin="0"
					aria-valuemax="100"
					:aria-label="t('teamhub', 'Import progress')">
					<div class="team-import__progress-fill" :style="{ width: progressPercent + '%' }"></div>
				</div>
				<span class="team-import__progress-label">
					{{ t('teamhub', '{done} of {total} rows processed', { done: processedRows, total: totalRows }) }}
				</span>
			</div>

			<!-- Defensive: a validated run always has rows, but a run whose rows
			     were pruned underneath an open tab would otherwise render an
			     empty table with no explanation. -->
			<NcEmptyContent
				v-if="!rows.length"
				:name="t('teamhub', 'No rows to show')"
				:description="t('teamhub', 'This import has no rows left. It may have been cleaned up already.')">
				<template #icon><FileTableOutlineIcon :size="iconHero" /></template>
			</NcEmptyContent>

			<div v-else class="team-import__table-wrap">
				<table class="team-import__table">
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Row') }}</th>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Template') }}</th>
							<th scope="col">{{ t('teamhub', 'Owner') }}</th>
							<th scope="col">{{ t('teamhub', 'Team admins') }}</th>
							<th scope="col">{{ t('teamhub', 'Members') }}</th>
							<th scope="col">{{ t('teamhub', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.row_num">
							<td>{{ row.row_num }}</td>
							<td>{{ row.name }}</td>
							<td>{{ templateLabel(row.template, row.project_mode) }}</td>
							<td>{{ row.owner }}</td>
							<!-- Owner and admins are split into two columns rather
							     than one "admin" cell echoing the CSV: the whole
							     point of the preview is to show what the position
							     rule resolved to, and a cell that reads back what
							     was typed cannot do that. Em dash, not empty, so
							     the cell is never mistaken for a rendering fault. -->
							<td>{{ row.admins && row.admins.length ? row.admins.join(', ') : '—' }}</td>
							<td>{{ row.member_count }}</td>
							<td>
								<!-- The word is the signal; the colour only reinforces it. -->
								<span class="team-import__pill" :class="pillClass(row.status)">
									{{ rowStatusLabel(row.status) }}
								</span>
								<span v-if="row.message" class="team-import__reason">{{ row.message }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="team-import__actions">
				<NcButton
					v-if="stage === 'preview'"
					variant="primary"
					:disabled="summary.ready === 0 || processing"
					@click="confirmOpen = true">
					{{ n('teamhub', 'Create {n} team', 'Create {n} teams', summary.ready, { n: summary.ready }) }}
				</NcButton>

				<NcButton
					v-if="stage === 'preview' || stage === 'running'"
					variant="secondary"
					:disabled="processing && stage === 'preview'"
					@click="discard">
					{{ stage === 'running' ? t('teamhub', 'Stop importing') : t('teamhub', 'Cancel') }}
				</NcButton>

				<NcButton v-if="stage === 'done'" variant="secondary" @click="downloadResults">
					<template #icon><DownloadIcon :size="iconBody" /></template>
					{{ t('teamhub', 'Download results as CSV') }}
				</NcButton>

				<NcButton v-if="stage === 'done'" variant="tertiary" @click="reset">
					{{ t('teamhub', 'Import another file') }}
				</NcButton>
			</div>

			<p v-if="stage === 'running'" class="team-import__hint">
				{{ t('teamhub', 'Keep this page open until the import finishes. If you close it, the remaining teams are created in the background within a few minutes.') }}
			</p>
		</template>

		<!-- ── Confirmation ───────────────────────────────────────────── -->
		<NcModal
			v-if="confirmOpen"
			:name="t('teamhub', 'Create these teams?')"
			size="normal"
			@close="confirmOpen = false">
			<div class="team-import__confirm">
				<h3 class="team-import__confirm-title">{{ t('teamhub', 'Create these teams?') }}</h3>
				<p class="team-import__confirm-body">
					{{ n('teamhub',
						'{n} team will be created, with its apps, privacy settings and modules. This cannot be undone from here — a team created by mistake has to be deleted individually.',
						'{n} teams will be created, each with its apps, privacy settings and modules. This cannot be undone from here — a team created by mistake has to be deleted individually.',
						summary.ready, { n: summary.ready }) }}
				</p>
				<div class="team-import__actions">
					<NcButton variant="primary" @click="start">
						{{ t('teamhub', 'Create teams') }}
					</NcButton>
					<NcButton variant="tertiary" @click="confirmOpen = false">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDateTime } from '../../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FileTableOutlineIcon from 'vue-material-design-icons/FileTableOutline.vue'

import { ICON_BODY, ICON_HERO } from '../../constants/uiTokens.js'

/**
 * Bulk team import (v4.6.6).
 *
 * Rendered as a section at the foot of AdminSettings' **Team creation** tab
 * rather than as a tenth tab: bulk creation obeys the team-creation policy set
 * directly above it, and a tab per feature is not worth the tab stop. Its own
 * file because AdminSettings.vue is already ~6 700 lines.
 *
 * Three states, derived from the run's own status rather than a local flag:
 *
 *   idle     — no run loaded. Sample download, column reference, file picker.
 *   preview  — a validated run. Per-row table with reasons; confirm to start.
 *   running  — the pump loop, then the results.
 *
 * The browser drives provisioning: it POSTs /process in a loop until the server
 * reports `completed`. That runs inside a real admin session, which is what
 * Circles and the resource services read. Closing the tab is survivable — the
 * TeamImportJob adopts a run whose heartbeat goes quiet — which is what the
 * beforeunload warning says rather than pretending the work would be lost.
 */
export default {
	name: 'TeamImportPanel',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcModal, DownloadIcon, FileTableOutlineIcon },

	data() {
		return {
			uploading: false,
			processing: false,
			// Set when the admin cancels or resets, so a /process response that
			// was already in flight cannot bring the dismissed run back.
			abandoned: false,
			loadingRecent: false,
			error: null,
			notice: null,
			confirmOpen: false,
			/** @type {?{import: object, summary: object, rows: Array}} */
			data: null,
			recent: [],
			// The instance's own vocabulary for the two columns that name one.
			// Both are read once on mount and only feed the help table, so a
			// failed fetch degrades to a generic description rather than an
			// error — the importer still works without them.
			templates: [],
			profiles: [],
			iconBody: ICON_BODY,
			iconHero: ICON_HERO,
			maxRows: 500,
			_unloadHandler: null,
		}
	},

	computed: {
		stage() {
			const status = this.data?.import?.status
			if (!status) return 'idle'
			if (status === 'validated') return 'preview'
			if (status === 'running') return 'running'
			return 'done'
		},

		summary() {
			return {
				ready: 0, skipped: 0, errors: 0, created: 0, failed: 0, warnings: 0,
				...(this.data?.summary || {}),
			}
		},

		rows() {
			return this.data?.rows || []
		},

		/** Validation errors before the run, provisioning failures after it. */
		errorTotal() {
			return this.summary.errors + this.summary.failed
		},

		totalRows() {
			return this.data?.import?.total_rows || this.rows.length
		},

		processedRows() {
			return this.summary.created + this.summary.skipped + this.errorTotal
		},

		progressPercent() {
			if (!this.totalRows) return 0
			return Math.round((this.processedRows / this.totalRows) * 100)
		},

		busyLabel() {
			if (this.uploading) return t('teamhub', 'Checking the file…')
			if (this.processing) return t('teamhub', 'Creating teams…')
			if (this.loadingRecent) return t('teamhub', 'Loading…')
			return null
		},

		/** A run left `running` by an earlier session, offered for resumption. */
		resumableRun() {
			return this.recent.find(run => run.status === 'running') || null
		},

		/**
		 * Template keys as a readable list, from the instance's own templates.
		 *
		 * Not a hard-coded string: the three keys have been admin-editable rows
		 * since v4.8.3, and a help table naming what the constants used to say
		 * is the same class of drift the docs audit found on the public site.
		 * Empty until the fetch lands, which the reference falls back for.
		 */
		templateKeyList() {
			return this.templates.map(tpl => tpl.templateKey).join(', ')
		},

		/** Policy profile keys as a readable list, from the instance. */
		policyKeyList() {
			return this.profiles.map(p => p.profileKey).join(', ')
		},

		columnReference() {
			return [
				{ name: 'name',         required: true,  values: t('teamhub', 'Letters, digits, spaces, hyphens and underscores only. Must not match an existing team.') },
				{ name: 'description',  required: false, values: t('teamhub', 'Free text.') },
				{
					name: 'template',
					required: true,
					values: this.templateKeyList
						// TRANSLATORS: {keys} is a comma-separated list of template keys, e.g. "collaboration, project, department"
						? t('teamhub', 'One of {keys}. The header may also be called team_type.', { keys: this.templateKeyList })
						: t('teamhub', 'A template key. The header may also be called team_type.'),
				},
				{ name: 'project_mode', required: false, values: t('teamhub', 'advanced or basic. Only read for the project template; defaults to advanced.') },
				{ name: 'admin',        required: true,  values: t('teamhub', 'One or more account names. The first becomes the team owner; the rest become team admins. Capitals, spaces and accents are all fine, and names are matched without regard to case.') },
				{ name: 'members',      required: false, values: t('teamhub', 'Account names, group:<group name> or team:<team name>. All three are matched without regard to case; team: also accepts a team ID. A name cannot itself contain a semicolon or a vertical bar.') },
				{ name: 'expires',      required: false, values: t('teamhub', 'A date as YYYY-MM-DD, in the future. Empty means the team has no end date. Only read when the row’s template allows an expiration date — a date on any other row is ignored with a warning.') },
				{
					name: 'policy',
					required: false,
					values: this.policyKeyList
						// TRANSLATORS: {keys} is a comma-separated list of policy profile keys, e.g. "public, internal, confidential"
						? t('teamhub', 'One of {keys}. Empty uses the policy the template starts its teams on.', { keys: this.policyKeyList })
						: t('teamhub', 'This server has no policy profiles, so leave this column empty.'),
				},
			]
		},
	},

	mounted() {
		this.fetchRecent()
		this.fetchVocabulary()
	},

	beforeUnmount() {
		this.detachUnloadWarning()
	},

	methods: {
		t,
		n,

		// ── Loading ──────────────────────────────────────────────────

		async fetchRecent() {
			this.loadingRecent = true
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/import/teams'))
				this.recent = data?.imports || []
			} catch (e) {
				// A failed history read is not worth an error banner over the
				// feature itself — the picker still works.
				this.recent = []
			} finally {
				this.loadingRecent = false
			}
		},

		/**
		 * The template and policy keys a row may name (v4.8.25).
		 *
		 * Two reads, both of endpoints the create-team wizard already calls, so
		 * the help table names what this instance actually offers rather than
		 * what TeamHub shipped with. Settled together rather than sequentially
		 * — neither depends on the other, and the table renders once.
		 *
		 * Failure is silent by design: `columnReference` falls back to a
		 * description without keys, and nothing else on the panel reads either
		 * list.
		 */
		async fetchVocabulary() {
			const [templates, profiles] = await Promise.allSettled([
				axios.get(generateUrl('/apps/teamhub/api/v1/templates')),
				axios.get(generateUrl('/apps/teamhub/api/v1/policy/creation')),
			])
			if (templates.status === 'fulfilled') {
				this.templates = templates.value?.data?.templates || []
			}
			if (profiles.status === 'fulfilled') {
				this.profiles = profiles.value?.data?.profiles || []
			}
		},

		async resume(importId) {
			this.error = null
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/admin/import/teams/{id}', { id: importId }),
				)
				this.data = data
				if (this.stage === 'running') this.pump()
			} catch (e) {
				this.error = this.errorMessage(e, t('teamhub', 'Could not open that import.'))
			}
		},

		// ── Sample + upload ──────────────────────────────────────────

		downloadSample() {
			// DataDownloadResponse sends Content-Disposition: attachment, so
			// this downloads without navigating away from the settings page.
			window.location.href = generateUrl('/apps/teamhub/api/v1/admin/import/teams/template')
		},

		async onFileChange(event) {
			const file = event.target?.files?.[0]
			if (!file) return

			this.error = null
			this.notice = null
			this.uploading = true

			const body = new FormData()
			body.append('file', file)

			try {
				const { data } = await axios.post(
					generateUrl('/apps/teamhub/api/v1/admin/import/teams/validate'),
					body,
					{ headers: { 'Content-Type': 'multipart/form-data' } },
				)
				this.data = data
				this.notice = t('teamhub', 'File checked. Review the rows below before creating anything.')
			} catch (e) {
				this.error = this.errorMessage(e, t('teamhub', 'Could not read that file.'))
			} finally {
				this.uploading = false
				// Clear the picker so re-selecting the same file fires @change.
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
			}
		},

		// ── Running ──────────────────────────────────────────────────

		async start() {
			this.confirmOpen = false
			this.error = null
			this.notice = null

			const importId = this.data?.import?.id
			if (!importId) return

			try {
				const { data } = await axios.post(
					generateUrl('/apps/teamhub/api/v1/admin/import/teams/{id}/start', { id: importId }),
				)
				this.data = data
				this.attachUnloadWarning()
				this.pump()
			} catch (e) {
				this.error = this.errorMessage(e, t('teamhub', 'Could not start the import.'))
			}
		},

		/**
		 * Call /process until the server says the run is finished.
		 *
		 * Sequential rather than parallel: each call provisions a few teams, and
		 * the server claims rows one at a time, so overlapping requests would
		 * only queue behind each other while making a cancel harder to honour.
		 */
		async pump() {
			const importId = this.data?.import?.id
			if (!importId || this.processing) return

			this.processing = true
			this.abandoned = false
			try {
				while (this.data?.import?.status === 'running') {
					const { data } = await axios.post(
						generateUrl('/apps/teamhub/api/v1/admin/import/teams/{id}/process', { id: importId }),
					)
					// The admin may have cancelled or reset while this call was
					// in flight. Assigning the response would resurrect a run
					// they have already dismissed.
					if (this.abandoned) break
					this.data = data
				}
				// Only claim success when the run actually reached the end. A
				// cancel drops out of the loop above with its own message
				// already set, and must not be overwritten by this one.
				if (!this.abandoned && this.data?.import?.status === 'completed') {
					this.notice = t('teamhub', 'Import finished.')
				}
			} catch (e) {
				this.error = this.errorMessage(e, t('teamhub', 'The import stopped early. It will continue in the background.'))
			} finally {
				this.processing = false
				this.detachUnloadWarning()
				this.fetchRecent()
			}
		},

		async discard() {
			const importId = this.data?.import?.id
			if (!importId) return

			this.error = null
			try {
				await axios.delete(
					generateUrl('/apps/teamhub/api/v1/admin/import/teams/{id}', { id: importId }),
				)
				// A cancelled run keeps the teams it already created; say so
				// rather than implying everything was undone.
				this.notice = this.stage === 'running'
					? t('teamhub', 'Import stopped. Teams already created were kept.')
					: t('teamhub', 'Import discarded. Nothing was created.')
				this.reset()
			} catch (e) {
				this.error = this.errorMessage(e, t('teamhub', 'Could not cancel the import.'))
			}
		},

		reset() {
			// Tells an in-flight pump call to drop its response on the floor.
			this.abandoned = true
			this.data = null
			this.confirmOpen = false
			this.detachUnloadWarning()
			this.fetchRecent()
		},

		// ── Results CSV ──────────────────────────────────────────────

		/**
		 * Build the results file in the browser. The server already returns
		 * every field it holds, so a download endpoint would add a route and a
		 * second serialiser for data that is on screen.
		 */
		downloadResults() {
			// Owner and admins stay in separate columns rather than being
			// re-joined into one `admin` cell. The results file is a record of
			// what happened, not a re-export of the source: an admin comparing
			// it against their input needs to see which name the position rule
			// made owner.
			const header = ['row', 'name', 'template', 'owner', 'admins', 'members', 'status', 'message']
			const lines = [header, ...this.rows.map(row => ([
				String(row.row_num),
				row.name,
				this.templateLabel(row.template, row.project_mode),
				row.owner,
				(row.admins || []).join(';'),
				String(row.member_count),
				this.rowStatusLabel(row.status),
				row.message || '',
			]))]

			const csv = lines.map(cells => cells.map(this.csvCell).join(',')).join('\r\n')
			// ﻿ is a BOM, so Excel opens this as UTF-8 instead of guessing
			// the codepage — the same reason the server writes one into the
			// sample. Written as an escape rather than a literal: an invisible
			// character in source is a character the next edit silently drops.
			const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' })
			const url = URL.createObjectURL(blob)
			const anchor = document.createElement('a')
			anchor.href = url
			anchor.download = 'teamhub-import-results.csv'
			document.body.appendChild(anchor)
			anchor.click()
			document.body.removeChild(anchor)
			URL.revokeObjectURL(url)
		},

		csvCell(value) {
			const text = String(value ?? '')
			return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text
		},

		// ── beforeunload ─────────────────────────────────────────────

		attachUnloadWarning() {
			if (this._unloadHandler) return
			this._unloadHandler = (event) => {
				event.preventDefault()
				// Browsers show their own wording; returnValue only has to be
				// set for the prompt to appear at all.
				event.returnValue = ''
			}
			window.addEventListener('beforeunload', this._unloadHandler)
		},

		detachUnloadWarning() {
			if (!this._unloadHandler) return
			window.removeEventListener('beforeunload', this._unloadHandler)
			this._unloadHandler = null
		},

		// ── Labels ───────────────────────────────────────────────────

		/**
		 * v4.8.25 — the instance's own label first.
		 *
		 * Templates are admin-editable rows, and their labels are free text an
		 * administrator typed, so they are used verbatim and never passed
		 * through `t()`. The translated map below it is the fallback for the
		 * moment before the fetch lands, and for a key the table no longer has.
		 */
		templateLabel(template, projectMode) {
			const labels = {
				collaboration: t('teamhub', 'Collaboration'),
				project: t('teamhub', 'Project'),
				department: t('teamhub', 'Department'),
			}
			const live = this.templates.find(tpl => tpl.templateKey === template)?.label
			const base = live || labels[template] || template || '—'
			if (template !== 'project' || !projectMode) return base
			const mode = projectMode === 'advanced'
				// TRANSLATORS: project setup mode — the full guided project experience
				? t('teamhub', 'Advanced')
				// TRANSLATORS: project setup mode — the simple, no-lifecycle project experience
				: t('teamhub', 'Basic')
			return t('teamhub', '{template} ({mode})', { template: base, mode })
		},

		rowStatusLabel(status) {
			const labels = {
				// TRANSLATORS: import row state — this team will be created when the admin confirms
				pending: t('teamhub', 'Ready'),
				// TRANSLATORS: import row state — this team is being created right now
				running: t('teamhub', 'Creating'),
				// TRANSLATORS: import row state — this team was created
				created: t('teamhub', 'Created'),
				// TRANSLATORS: import row state — this row was deliberately not created
				skipped: t('teamhub', 'Skipped'),
				// TRANSLATORS: import row state — this row produced no team
				failed: t('teamhub', 'Error'),
			}
			return labels[status] || status
		},

		runStatusLabel(status) {
			const labels = {
				validated: t('teamhub', 'Awaiting confirmation'),
				running: t('teamhub', 'Running'),
				completed: t('teamhub', 'Completed'),
				cancelled: t('teamhub', 'Cancelled'),
			}
			return labels[status] || status
		},

		pillClass(status) {
			if (status === 'created') return 'team-import__pill--ok'
			if (status === 'failed') return 'team-import__pill--err'
			if (status === 'skipped') return 'team-import__pill--warn'
			return 'team-import__pill--neutral'
		},

		formatTimestamp(seconds) {
			if (!seconds) return '—'
			return formatDateTime(seconds * 1000)
		},

		errorMessage(e, fallback) {
			const detail = e?.response?.data?.error
			return detail
				? t('teamhub', '{message} ({error})', { message: fallback, error: detail })
				: fallback
		},
	},
}
</script>

<style scoped lang="scss">
.team-import {
	display: flex;
	flex-direction: column;
	gap: 14px;
	max-width: 980px;
}

.team-import__intro {
	margin: 0;
	color: var(--color-text-light);
	font-size: var(--th-font-body, 14px);
	line-height: var(--th-line-height-body, 1.4);
}

/* Reserves its height when empty so nothing shifts as messages appear. */
.team-import__status {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 24px;
	font-size: var(--th-font-meta, 12px);
}

.team-import__status-err { color: var(--color-error-text, var(--color-error)); }
.team-import__status-ok  { color: var(--color-success-text, var(--color-success)); }

.team-import__heading {
	margin: 6px 0 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-bold, 700);
}

.team-import__hint {
	margin: 0;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
	line-height: var(--th-line-height-body, 1.4);
}

.team-import__actions {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
}

.team-import__file-label {
	font-size: var(--th-font-meta, 12px);
	font-weight: var(--th-font-weight-medium, 500);
}

.team-import__file {
	font-size: var(--th-font-meta, 12px);

	/* NC's form-field convention: no outline on the base rule, a ring on
	   keyboard focus only (SKILLS.md § Focus visibility standard). */
	&:focus { outline: none; }
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

/* Wide tables scroll inside their own box rather than the settings page. */
.team-import__table-wrap {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
}

.team-import__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--th-font-meta, 12px);
	line-height: var(--th-line-height-body, 1.4);

	th, td {
		text-align: start;
		padding: 8px 12px;
		vertical-align: top;
	}

	th {
		font-weight: var(--th-font-weight-semibold, 600);
		color: var(--color-text-maxcontrast);
		border-bottom: 1px solid var(--color-border);
	}

	tbody tr + tr td {
		border-top: 1px solid var(--color-border);
	}

	code {
		font-family: monospace;
		font-size: var(--th-font-micro, 11px);
	}
}

.team-import__summary {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-size: var(--th-font-body, 14px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.team-import__sep { color: var(--color-text-maxcontrast); }

.team-import__count--skipped { color: var(--color-text-maxcontrast); }
.team-import__count--error   { color: var(--color-error-text, var(--color-error)); }
.team-import__count--created { color: var(--color-success-text, var(--color-success)); }

.team-import__progress {
	display: flex;
	align-items: center;
	gap: 10px;
}

.team-import__progress-track {
	flex: 1 1 auto;
	height: 8px;
	background: var(--color-background-dark);
	border-radius: var(--th-radius-pill, 999px);
	overflow: hidden;
}

.team-import__progress-fill {
	height: 100%;
	background: var(--color-primary-element);
	transition: width 0.2s ease;
}

.team-import__progress-label {
	flex: 0 0 auto;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
}

/* The status word sits inside the pill, so colour reinforces rather than
   carries the meaning (WCAG 1.4.1). */
.team-import__pill {
	display: inline-block;
	padding: 1px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-pill, 999px);
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	white-space: nowrap;
}

.team-import__pill--ok      { color: var(--color-success-text, var(--color-success)); }
.team-import__pill--err     { color: var(--color-error-text, var(--color-error)); }
.team-import__pill--warn    { color: var(--color-text-maxcontrast); }
.team-import__pill--neutral { color: var(--color-main-text); }

.team-import__reason {
	display: block;
	margin-top: 4px;
	font-size: var(--th-font-micro, 11px);
	color: var(--color-text-maxcontrast);
}

.team-import__confirm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.team-import__confirm-title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-bold, 700);
}

.team-import__confirm-body {
	margin: 0;
	font-size: var(--th-font-body, 14px);
	line-height: var(--th-line-height-body, 1.4);
}
</style>
