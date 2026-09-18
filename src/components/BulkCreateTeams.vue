<template>
	<div class="btc">

		<div v-if="loading" class="btc__loading">
			<NcLoadingIcon :size="iconToolbar" />
			<span>{{ t('teamhub', 'Loading…') }}</span>
		</div>

		<div class="btc__status" role="status" aria-live="polite">
			<p v-if="error" class="btc__error">
				<AlertCircleOutline :size="iconInline" aria-hidden="true" />
				{{ error }}
			</p>
		</div>

		<!-- ── The table ──────────────────────────────────────────────────── -->
		<template v-if="!loading && stage === 'edit'">
			<p class="btc__hint">
				{{ t('teamhub', 'One row per team. Apps and settings come from the template and policy you pick, and can be changed per team afterwards. Rows without a name are ignored, so you can leave spare rows empty.') }}
			</p>

			<div class="btc__scroll">
				<table class="btc__table">
					<caption class="btc__sr">
						{{ t('teamhub', 'Teams to create') }}
					</caption>
					<thead>
						<tr>
							<th scope="col" class="btc__col-num"><span class="btc__sr">{{ t('teamhub', 'Row') }}</span></th>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Template') }}</th>
							<th scope="col">{{ t('teamhub', 'Policy') }}</th>
							<th scope="col">{{ t('teamhub', 'Owner') }}</th>
							<th scope="col">{{ t('teamhub', 'Team admins') }}</th>
							<th scope="col">{{ t('teamhub', 'Members') }}</th>
							<th scope="col">{{ t('teamhub', 'Expires') }}</th>
							<th scope="col"><span class="btc__sr">{{ t('teamhub', 'Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in rows" :key="row.key" :class="{ 'btc__row--error': !!row.error }">
							<td class="btc__col-num">{{ index + 1 }}</td>
							<td>
								<label class="btc__sr" :for="'btc-name-' + row.key">{{ t('teamhub', 'Team name') }}</label>
								<input
									:id="'btc-name-' + row.key"
									v-model="row.name"
									type="text"
									class="btc__input"
									:class="{ 'btc__input--error': !!row.error }"
									:placeholder="t('teamhub', 'Team name')"
									maxlength="120"
									@input="row.error = ''">
								<!-- The server's reason, on the row that caused
								     it. A list of messages above the table
								     makes the reader count rows to find theirs. -->
								<span v-if="row.error" class="btc__row-error">{{ row.error }}</span>
							</td>
							<td>
								<label class="btc__sr" :for="'btc-tpl-' + row.key">{{ t('teamhub', 'Template') }}</label>
								<select
									:id="'btc-tpl-' + row.key"
									v-model="row.template"
									class="btc__input"
									@change="onTemplateChange(row)">
									<!-- v4.9.3 — no OpenProject teams in bulk: the project
									     must be picked per team by its creator, which a
									     row cannot express. The importer refuses it too. -->
									<option v-for="tpl in bulkTemplates" :key="tpl.templateKey" :value="tpl.templateKey">
										{{ tpl.label }}
									</option>
								</select>
							</td>
							<td>
								<label class="btc__sr" :for="'btc-pol-' + row.key">{{ t('teamhub', 'Policy') }}</label>
								<select
									:id="'btc-pol-' + row.key"
									v-model="row.policy"
									class="btc__input">
									<option v-for="p in profiles" :key="p.profileKey" :value="p.profileKey">
										{{ p.label }}
									</option>
								</select>
							</td>
							<!-- v4.8.12 — Owner and Team admins are separate
							     columns. One "Administrator" field where the
							     first name silently became the owner and the
							     rest became admins was a rule you had to be
							     told; two columns say it. -->
							<td>
								<BulkUserCell
									v-model="row.owner"
									:placeholder="t('teamhub', 'Search a person…')" />
							</td>
							<td>
								<BulkUserCell
									v-model="row.admins"
									multiple
									:placeholder="t('teamhub', 'Add people…')" />
							</td>
							<td>
								<BulkUserCell
									v-model="row.members"
									multiple
									allow-groups
									:placeholder="t('teamhub', 'Add people or groups…')" />
							</td>
							<td>
								<label class="btc__sr" :for="'btc-exp-' + row.key">{{ t('teamhub', 'Expires') }}</label>
								<!-- Disabled, not hidden, when the template does
								     not allow expiry: a cell that vanishes on a
								     template change reads as a glitch. -->
								<input
									:id="'btc-exp-' + row.key"
									v-model="row.expires"
									type="date"
									class="btc__input"
									:disabled="!templateAllowsExpiry(row.template)"
									@focus="onExpiryFocus(row)"
									:title="templateAllowsExpiry(row.template) ? '' : t('teamhub', 'This template does not allow an expiration date.')">
							</td>
							<td>
								<NcButton
									type="tertiary"
									:aria-label="t('teamhub', 'Remove row {n}', { n: index + 1 })"
									:disabled="rows.length === 1"
									@click="removeRow(index)">
									<template #icon><Close :size="18" /></template>
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="btc__actions">
				<NcButton type="secondary" @click="addRow()">
					<template #icon><Plus :size="18" /></template>
					{{ t('teamhub', 'Add row') }}
				</NcButton>
				<span class="btc__hint">
					{{ n('teamhub', '%n team', '%n teams', filledRows.length, { n: filledRows.length }) }}
				</span>
				<!-- v4.8.12 — no Check step. Accounts are resolved as you
				     type, so the only thing a separate pass could still find is
				     what this button finds anyway, one click later. Rows that
				     fail validation come back annotated in place. -->
				<div class="btc__actions-right">
					<NcButton
						type="primary"
						:disabled="checking || filledRows.length === 0"
						@click="createTeams">
						{{ checking
							? t('teamhub', 'Checking…')
							: n('teamhub', 'Create %n team', 'Create %n teams', filledRows.length, { n: filledRows.length }) }}
					</NcButton>
				</div>
			</div>
		</template>

		<!-- ── Preview ────────────────────────────────────────────────────── -->
		<template v-else-if="stage === 'preview'">
			<h3 class="btc__h3">{{ t('teamhub', 'Ready to create') }}</h3>
			<p class="btc__hint">
				{{ t('teamhub', 'Rows with a problem are not created. Go back to fix them, or create the rest.') }}
			</p>

			<div class="btc__summary">
				<span class="btc__pill btc__pill--ok">
					{{ n('teamhub', '%n row ready', '%n rows ready', summary.ready, { n: summary.ready }) }}
				</span>
				<span v-if="summary.skipped" class="btc__pill">
					{{ n('teamhub', '%n skipped', '%n skipped', summary.skipped, { n: summary.skipped }) }}
				</span>
				<span v-if="summary.errors" class="btc__pill btc__pill--error">
					{{ n('teamhub', '%n with an error', '%n with errors', summary.errors, { n: summary.errors }) }}
				</span>
			</div>

			<div class="btc__scroll">
				<table class="btc__table">
					<thead>
						<tr>
							<th scope="col" class="btc__col-num">{{ t('teamhub', 'Row') }}</th>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Status') }}</th>
							<th scope="col">{{ t('teamhub', 'Detail') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in previewRows" :key="r.row_num">
							<td class="btc__col-num">{{ r.row_num }}</td>
							<td>{{ r.name || '—' }}</td>
							<td><span :class="['btc__pill', pillClass(r.status)]">{{ statusLabel(r.status) }}</span></td>
							<td class="btc__detail">{{ r.message || '' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="btc__actions">
				<NcButton type="tertiary" @click="backToEdit">
					{{ t('teamhub', 'Back') }}
				</NcButton>
				<div class="btc__actions-right">
					<NcButton
						type="primary"
						:disabled="summary.ready === 0 || running"
						@click="createAll">
						{{ n('teamhub', 'Create %n team', 'Create %n teams', summary.ready, { n: summary.ready }) }}
					</NcButton>
				</div>
			</div>
		</template>

		<!-- ── Running / done ─────────────────────────────────────────────── -->
		<template v-else-if="stage === 'running' || stage === 'done'">
			<h3 class="btc__h3">
				{{ stage === 'done' ? t('teamhub', 'Finished') : t('teamhub', 'Creating teams…') }}
			</h3>

			<div class="btc__progress">
				<progress class="btc__bar" :value="processed" :max="Math.max(totalToProcess, 1)" />
				<span>{{ t('teamhub', '{done} of {total}', { done: processed, total: totalToProcess }) }}</span>
			</div>

			<div class="btc__summary">
				<span class="btc__pill btc__pill--ok">
					{{ n('teamhub', '%n created', '%n created', summary.created, { n: summary.created }) }}
				</span>
				<span v-if="summary.failed" class="btc__pill btc__pill--error">
					{{ n('teamhub', '%n failed', '%n failed', summary.failed, { n: summary.failed }) }}
				</span>
				<span v-if="summary.warnings" class="btc__pill btc__pill--warn">
					{{ n('teamhub', '%n with a warning', '%n with warnings', summary.warnings, { n: summary.warnings }) }}
				</span>
			</div>

			<div class="btc__scroll">
				<table class="btc__table">
					<thead>
						<tr>
							<th scope="col" class="btc__col-num">{{ t('teamhub', 'Row') }}</th>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Status') }}</th>
							<th scope="col">{{ t('teamhub', 'Detail') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in previewRows" :key="r.row_num">
							<td class="btc__col-num">{{ r.row_num }}</td>
							<td>{{ r.name || '—' }}</td>
							<td><span :class="['btc__pill', pillClass(r.status)]">{{ statusLabel(r.status) }}</span></td>
							<td class="btc__detail">{{ r.message || '' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-if="stage === 'done'" class="btc__actions">
				<div class="btc__actions-right">
					<NcButton type="secondary" @click="reset">
						{{ t('teamhub', 'Create more teams') }}
					</NcButton>
					<NcButton type="primary" @click="$emit('done')">
						{{ t('teamhub', 'Close') }}
					</NcButton>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import { ICON_INLINE, ICON_TOOLBAR } from '../constants/uiTokens.js'
import { shiftToday } from '../lib/localDate.js'
// v4.8.12 — resolves accounts as you type, replacing the free-text cells and
// the separate Check pass that used to validate them.
import BulkUserCell from './admin/BulkUserCell.vue'

/**
 * Bulk team creation — one page, one table (v4.8.9).
 *
 * **This is a front end onto `TeamImportService`, not a second importer.** The
 * rows typed here go through the same `normaliseRow()` validation, the same
 * durable run and the same chunked provisioning as a CSV upload, via
 * `BulkTeamController`. Building a separate creation loop would have made a
 * third path — beside the wizard and the CSV importer — that has to stay in
 * step with both, and this codebase has paid for that shape before.
 *
 * Three stages, derived from the run's own status rather than a local flag, the
 * same way `TeamImportPanel` does it:
 *
 *   edit     — the table. Nothing has been sent.
 *   preview  — validated. Per-row verdicts; nothing created yet.
 *   running  — the pump loop, then the results.
 *
 * The browser drives provisioning by calling `process` until the run reports
 * `completed`. That runs inside a real session, which is what Circles and the
 * resource services read. Closing the tab is survivable — the background job
 * adopts a run whose heartbeat goes quiet.
 */
export default {
	name: 'BulkCreateTeams',
	components: { NcButton, NcLoadingIcon, AlertCircleOutline, Close, Plus, BulkUserCell },

	props: {
		/** Template rows, already fetched by the parent — no second request. */
		templates: { type: Array, default: () => [] },
		/** Policy profiles, likewise. */
		profiles: { type: Array, default: () => [] },
	},

	emits: ['done'],

	data() {
		return {
			loading: false,
			checking: false,
			running: false,
			error: null,
			rows: [],
			/** @type {?object} the validated run */
			run: null,
			processed: 0,
			// Monotonic key per row: the array index cannot be the v-for key
			// when rows are removed from the middle, or Vue reuses the wrong
			// input and the text follows the wrong row.
			nextKey: 1,
			iconInline: ICON_INLINE,
			iconToolbar: ICON_TOOLBAR,
		}
	},

	computed: {
		/** v4.9.3 — every template but OpenProject; see the option's comment. */
		bulkTemplates() {
			return this.templates.filter(tpl => tpl.templateKey !== 'openproject')
		},
		stage() {
			const status = this.run?.import?.status
			if (!status) return 'edit'
			if (status === 'validated') return 'preview'
			if (status === 'running') return 'running'
			return 'done'
		},

		summary() {
			return {
				ready: 0, skipped: 0, errors: 0, created: 0, failed: 0, warnings: 0,
				...(this.run?.summary || {}),
			}
		},

		previewRows() {
			return this.run?.rows || []
		},

		totalToProcess() {
			return this.summary.ready + this.summary.created + this.summary.failed
		},

		/** Rows the user has actually typed something into. */
		filledRows() {
			return this.rows.filter(r => r.name.trim() !== '')
		},
	},

	mounted() {
		// v4.8.12 — five, was three. A blank row costs nothing and asking for
		// more is a click you should not have to make for an ordinary batch.
		for (let i = 0; i < 5; i++) {
			this.addRow()
		}
	},

	methods: {
		t,
		n,

		blankRow() {
			const firstTemplate = this.bulkTemplates[0]
			return {
				key: this.nextKey++,
				name: '',
				template: firstTemplate?.templateKey || 'collaboration',
				// Preselect the template's default policy, exactly as the
				// single-team wizard does.
				policy: firstTemplate?.defaultProfileKey || this.profiles[0]?.profileKey || '',
				// v4.8.12 — resolved accounts, not free text. Owner is a
				// single-element array so one picker component serves all three.
				owner: [],
				admins: [],
				members: [],
				expires: '',
				/** Server-side message for this row, shown in place. */
				error: '',
			}
		},

		addRow() {
			this.rows.push(this.blankRow())
		},

		removeRow(index) {
			this.rows.splice(index, 1)
		},

		/**
		 * Changing the template moves the policy to that template's default and
		 * clears a date the new template does not allow — the same two rules
		 * the single-team wizard applies.
		 */
		onTemplateChange(row) {
			const tpl = this.templates.find(x => x.templateKey === row.template)
			if (tpl?.defaultProfileKey) {
				row.policy = tpl.defaultProfileKey
			}
			if (!this.templateAllowsExpiry(row.template)) {
				row.expires = ''
			}
		},

		templateAllowsExpiry(templateKey) {
			return !!this.templates.find(x => x.templateKey === templateKey)?.expiryEnabled
		},

		/**
		 * v4.8.35 — first focus of an empty cell fills in today plus the
		 * template's default period, which is what the single-team wizard's
		 * `onExpiryFocus()` has done since 4.8.3. This table had no default at
		 * all, so the native picker opened on today and an admin who set
		 * "90 days" on the Project template got none of it.
		 *
		 * On focus rather than in `blankRow()`, for the same reason as the
		 * wizard: a date written into the model would give every team a
		 * deadline nobody chose, and an untouched cell has to keep meaning
		 * "no end date".
		 *
		 * Deliberately narrower than the wizard, which falls back to the
		 * instance-wide default and then to six months. Those come from admin
		 * settings this component is not given, and inventing a six-month
		 * fallback here would put a date on rows the wizard would have left
		 * empty. A template with no default period still opens on today, and
		 * that is the correct reading of "this template sets no default".
		 */
		onExpiryFocus(row) {
			if (row.expires) {
				return
			}
			const days = this.templates.find(x => x.templateKey === row.template)?.expiryDefaultDays || 0
			if (days > 0) {
				row.expires = shiftToday({ days })
			}
		},

		url(path) {
			return generateUrl('/apps/teamhub/api/v1/teams/bulk' + path)
		},

		readError(e, fallback) {
			return e?.response?.data?.error || fallback
		},

		statusLabel(status) {
			switch (status) {
			case 'ready': return t('teamhub', 'Ready')
			case 'created': return t('teamhub', 'Created')
			case 'failed': return t('teamhub', 'Failed')
			case 'skipped': return t('teamhub', 'Skipped')
			default: return status
			}
		},

		pillClass(status) {
			if (status === 'created' || status === 'ready') return 'btc__pill--ok'
			if (status === 'failed') return 'btc__pill--error'
			return ''
		},

		/**
		 * Validate and, if every row is good, go straight into creating.
		 *
		 * There is no user-facing Check any more (v4.8.12). Accounts resolve as
		 * you type, so validation has nothing left to tell you that you could
		 * not already see — except the things only the server knows, like a name
		 * already taken. Those come back annotated on the row that caused them
		 * rather than on a separate screen.
		 *
		 * **Rows with no name are never sent.** `filledRows` is the filter, so
		 * the five blank rows the table starts with cost nothing and a half-typed
		 * row at the bottom is not an error.
		 */
		async createTeams() {
			this.checking = true
			this.error = null
			this.rows.forEach(r => { r.error = '' })

			const sending = this.filledRows
			try {
				const payload = sending.map(r => ({
					name: r.name.trim(),
					template: r.template,
					policy: r.policy,
					// The server's `admin` column is "owner first, then team
					// admins" (v4.6.10). Two UI columns, one wire format —
					// changing that contract would touch the CSV importer too.
					admin: [...r.owner, ...r.admins].map(u => u.id).join(';'),
					members: r.members
						.map(u => (u.type === 'group' ? 'group:' + u.id : u.id))
						.join(';'),
					expires: r.expires || '',
				}))

				const { data } = await axios.post(this.url('/validate'), { rows: payload })

				const failed = (data.rows || []).filter(r => r.status === 'failed')
				if (failed.length > 0) {
					// Put each message back on the row the user typed. `row_num`
					// is 1-based over the rows we sent, which is `sending`'s
					// order — not the table's, because blanks were skipped.
					for (const f of failed) {
						const target = sending[f.row_num - 1]
						if (target) target.error = f.message || t('teamhub', 'This row cannot be created.')
					}
					this.error = n('teamhub',
						'%n row needs attention before these teams can be created.',
						'%n rows need attention before these teams can be created.',
						failed.length, { n: failed.length })
					// Discard the run: it holds rows nobody will provision, and
					// leaving it stranded clutters the list of recent imports.
					await this.discardRun(data.import?.id)
					return
				}

				// `createAll` sets `run` itself from the start response. Setting
				// it here first would render the old preview screen for one
				// tick on the way past — that screen is now only a fallback for
				// a start that failed, not a step anybody should see.
				await this.createAll(data.import?.id)
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not create these teams.'))
			} finally {
				this.checking = false
			}
		},

		/** Best-effort cleanup of a validated run we are not going to provision. */
		async discardRun(importId) {
			if (!importId) return
			try {
				await axios.delete(this.url('/' + importId))
			} catch { /* non-fatal — a stranded run is untidy, not broken */ }
		},

		async backToEdit() {
			// Discard the validated run rather than leaving it stranded: it
			// holds rows nobody will provision, and the list of recent runs is
			// shared with the CSV importer.
			const id = this.run?.import?.id
			this.run = null
			if (id) {
				try { await axios.delete(this.url('/' + id)) } catch { /* non-fatal */ }
			}
		},

		async createAll(importId = null) {
			this.running = true
			this.error = null
			this.processed = 0
			const id = importId || this.run?.import?.id
			try {
				const { data } = await axios.post(this.url('/' + id + '/start'))
				this.run = data
				await this.pump(id)
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not create these teams.'))
			} finally {
				this.running = false
			}
		},

		/**
		 * Drive the run to completion, one chunk per request.
		 *
		 * Bounded rather than `while (true)`: a server that stops advancing
		 * would otherwise spin the browser forever. The cap is generous enough
		 * for the row limit the service enforces.
		 */
		async pump(id) {
			for (let guard = 0; guard < 500; guard++) {
				const { data } = await axios.post(this.url('/' + id + '/process'))
				this.run = data
				this.processed = this.summary.created + this.summary.failed
				if (this.run?.import?.status !== 'running') {
					return
				}
			}
			this.error = t('teamhub', 'Stopped after too many attempts. Check the results and try the remaining teams again.')
		},

		reset() {
			this.run = null
			this.rows = []
			this.processed = 0
			// Same five the table opens with — starting a second batch should
			// look like starting the first.
			for (let i = 0; i < 5; i++) {
				this.addRow()
			}
		},
	},
}
</script>

<style scoped>
.btc {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.btc__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.btc__status:empty {
	display: none;
}

.btc__error {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-error-text);
	font-size: var(--th-font-body);
}

.btc__h3 {
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
	margin: 0;
}

.btc__hint {
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	max-width: 80ch;
}

/* The table scrolls inside its own box; the page never scrolls sideways. */
.btc__scroll {
	overflow-x: auto;
	max-width: 100%;
}

.btc__table {
	width: 100%;
	border-collapse: collapse;
	min-width: 900px;
}

.btc__table th,
.btc__table td {
	text-align: start;
	padding: 6px 8px 6px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: var(--th-font-body);
	vertical-align: middle;
}

.btc__table th {
	font-size: var(--th-font-meta);
	font-weight: var(--th-font-weight-semibold);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.btc__col-num {
	width: 2.5rem;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.btc__input {
	width: 100%;
	min-width: 8rem;
	border-radius: var(--th-radius-control);
}

/* v4.8.12 — a row the server refused. Border and message, never colour alone:
   the text under the name field is what says what to do about it. */
.btc__input--error {
	border-color: var(--color-error-text);
}

.btc__row--error > td {
	background: var(--color-background-hover);
}

.btc__row-error {
	display: block;
	margin-top: 2px;
	font-size: var(--th-font-micro);
	color: var(--color-error-text);
	max-width: 22ch;
}

/* NC's field pattern: no outline, a border change on focus. Kept out of a
   grouped :hover selector — grouping is what silences the keyboard ring. */
.btc__input:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.btc__input:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.btc__input:disabled {
	opacity: 0.5;
}

.btc__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.btc__actions-right {
	margin-inline-start: auto;
	display: flex;
	gap: 8px;
}

.btc__summary {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

/* Every pill carries its own words; colour is never the only signal. */
.btc__pill {
	font-size: var(--th-font-micro);
	font-weight: var(--th-font-weight-medium);
	padding: 1px 8px;
	border-radius: var(--th-radius-pill);
	border: 1px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.btc__pill--ok {
	border-color: var(--color-success-text);
	color: var(--color-success-text);
}

.btc__pill--error {
	border-color: var(--color-error-text);
	color: var(--color-error-text);
}

.btc__pill--warn {
	border-color: var(--color-warning-text);
	color: var(--color-warning-text);
}

.btc__progress {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: var(--th-font-body);
}

.btc__bar {
	flex: 1 1 auto;
	max-width: 320px;
}

.btc__detail {
	max-width: 40ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.btc__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
</style>
