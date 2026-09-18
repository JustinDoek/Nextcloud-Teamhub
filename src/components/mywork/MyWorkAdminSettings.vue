<template>
	<div class="mywork-admin">
		<div v-if="loading" class="mywork-admin__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<!-- v4.9.19 — the Reload button and the save outcome moved from the
			     foot of the page to its head: every control on this page saves
			     itself, and "Saved" three screens below the switch that was
			     flipped is feedback nobody sees. Same shape as the Integrations
			     tab's autosave line. The status span reserves its height so
			     nothing shifts as Saved appears and clears. -->
			<div class="mywork-admin__toolbar">
				<NcButton variant="secondary" :disabled="saving" @click="reload">
					<template #icon><RefreshIcon :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Reload') }}
				</NcButton>
				<span class="mywork-admin__status" role="status" aria-live="polite">
					<span v-if="saved" class="mywork-admin__saved">{{ t('teamhub', 'Saved') }}</span>
					<span v-if="error" class="mywork-admin__error">{{ error }}</span>
				</span>
			</div>

			<!-- ── Sources ────────────────────────────────────────────────
			     v4.9.19 — a list and one shared detail pane, where every
			     source used to be a full card of facts, checkboxes and a
			     diagnostics disclosure stacked one under the other. Justin,
			     2026-09-15: "the page looks like one long list and I lose
			     overview." The switch stays on the row so a source is turned
			     on or off without opening it; everything else about the
			     selected source — availability, sync facts, the action
			     allow-list, integration details — is read in the pane. -->
			<section class="mywork-admin__section">
				<h3 class="mywork-admin__heading">{{ t('teamhub', 'Sources') }}</h3>
				<p class="mywork-admin__hint">
					{{ t('teamhub', 'A source that is turned off, or whose app is missing, is skipped entirely. A source that fails at request time never blocks the others — members see the results that did arrive plus a notice.') }}
				</p>

				<div v-if="!providers.length" class="mywork-admin__empty">
					{{ t('teamhub', 'No sources are registered.') }}
				</div>

				<div v-else class="mywork-admin__sources">
					<ul class="mywork-admin__list">
						<li
							v-for="provider in providers"
							:key="provider.id"
							class="mywork-admin__row">
							<NcCheckboxRadioSwitch
								type="switch"
								class="mywork-admin__row-switch"
								:model-value="provider.enabled"
								:aria-label="t('teamhub', 'Enable {name} in My Work', { name: provider.name })"
								@update:model-value="setProviderEnabled(provider, $event)" />

							<!-- Raw <button>: a full-width list-row item that
							     selects what the pane shows — the card-row
							     carve-out in /ui-standards. NcButton would
							     centre the label and impose its own padding on
							     a row that has to line up with the switch
							     beside it. aria-current names the selected row
							     to a reader; the fill and weight say it on
							     screen. -->
							<button
								type="button"
								class="mywork-admin__row-button"
								:class="{ 'mywork-admin__row-button--selected': provider.id === selectedId }"
								:aria-current="provider.id === selectedId ? 'true' : undefined"
								@click="selectedId = provider.id">
								<span class="mywork-admin__row-name">{{ provider.name }}</span>
								<!-- Only the exception is flagged on the row; the
								     pane states availability in words for every
								     source, so an available one carries nothing
								     here and the list stays scannable. -->
								<span v-if="!provider.available" class="mywork-admin__pill mywork-admin__pill--warn">
									{{ t('teamhub', 'Unavailable') }}
								</span>
								<ChevronRightIcon :size="ICON_BODY" class="mywork-admin__row-chevron" aria-hidden="true" />
							</button>
						</li>
					</ul>

					<section
						v-if="selected"
						class="mywork-admin__detail"
						aria-labelledby="mywork-admin-detail-title">
						<div class="mywork-admin__detail-head">
							<h4 id="mywork-admin-detail-title" class="mywork-admin__detail-title">
								{{ selected.name }}
							</h4>
							<span
								class="mywork-admin__pill"
								:class="selected.available
									? 'mywork-admin__pill--ok'
									: 'mywork-admin__pill--warn'">
								{{ selected.available
									? t('teamhub', 'Available')
									: t('teamhub', 'Unavailable') }}
							</span>
						</div>

						<p v-if="!selected.available && selected.unavailableReason" class="mywork-admin__reason">
							{{ selected.unavailableReason }}
						</p>

						<dl class="mywork-admin__facts">
							<div class="mywork-admin__fact">
								<dt>{{ t('teamhub', 'Last successful sync') }}</dt>
								<dd>{{ selected.lastSyncAt ? formatAbsolute(selected.lastSyncAt) : t('teamhub', 'Never') }}</dd>
							</div>
							<div v-if="selected.lastError" class="mywork-admin__fact">
								<dt>{{ t('teamhub', 'Last error') }}</dt>
								<dd class="mywork-admin__fact-error">
									{{ selected.lastError }}
									<span v-if="selected.lastErrorAt">({{ formatAbsolute(selected.lastErrorAt) }})</span>
								</dd>
							</div>
						</dl>

						<!-- Action allow-list. Only source-mutating actions are
						     restrictable — snooze and unsnooze are personal queue
						     management and touch no other app, so there is nothing
						     for an administrator to govern. -->
						<div v-if="restrictableActions(selected).length" class="mywork-admin__actions">
							<span class="mywork-admin__label">{{ t('teamhub', 'Actions members may perform') }}</span>
							<div class="mywork-admin__action-grid">
								<NcCheckboxRadioSwitch
									v-for="action in restrictableActions(selected)"
									:key="action"
									:model-value="selected.allowedActions.includes(action)"
									@update:model-value="toggleAction(selected, action, $event)">
									{{ actionLabel(action) }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>

						<!-- Integration diagnostics. Only providers that ship them
						     have this — File approval and OpenProject, whose backing
						     app's schema or state TeamHub probes rather than assumes,
						     so "it returns nothing" needs to be diagnosable without
						     reading the server log. Open, not a disclosure: the pane
						     is the place an administrator came to read exactly this. -->
						<div v-if="(selected.diagnostics || []).length" class="mywork-admin__detail-diag">
							<span class="mywork-admin__label">{{ t('teamhub', 'Integration details') }}</span>
							<dl class="mywork-admin__diag-list">
								<div
									v-for="(row, i) in selected.diagnostics"
									:key="i"
									class="mywork-admin__diag-row">
									<dt>{{ row.label }}</dt>
									<dd><code>{{ row.value }}</code></dd>
								</div>
							</dl>
						</div>
					</section>
				</div>
			</section>

			<!-- ── Time windows · Performance ─────────────────────────────
			     Side by side where the width allows (v4.9.19): seven number
			     fields in one column was most of the page's height, and the
			     two topics are read together — how far ahead My Work looks and
			     how long it may take to look. -->
			<div class="mywork-admin__columns">
				<section class="mywork-admin__section">
					<h3 class="mywork-admin__heading">{{ t('teamhub', 'Time windows') }}</h3>

					<!-- v4.5.22 — the actionable band opens before the deadline,
					     not after it. Justin's smoke test: a card due tomorrow sat
					     under Upcoming, and "all cards should be handled before
					     the due date". -->
					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Action required starts this many days before the due date') }}</span>
						<input
							v-model.number="config.actionRequiredDays"
							type="number"
							class="mywork-admin__input"
							:min="bounds.actionRequiredDays.min"
							:max="bounds.actionRequiredDays.max"
							@change="save">
						<span class="mywork-admin__hint">
							{{ t('teamhub', 'Overdue items are always Action required. Set this to 0 if only overdue work should count as actionable.') }}
						</span>
					</label>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Upcoming covers the next (days)') }}</span>
						<input
							v-model.number="config.upcomingDays"
							type="number"
							class="mywork-admin__input"
							:min="bounds.upcomingDays.min"
							:max="bounds.upcomingDays.max"
							@change="save">
					</label>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Completed keeps items for (days)') }}</span>
						<input
							v-model.number="config.completedDays"
							type="number"
							class="mywork-admin__input"
							:min="bounds.completedDays.min"
							:max="bounds.completedDays.max"
							@change="save">
					</label>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Treat a pending approval as expired after (days)') }}</span>
						<input
							v-model.number="config.approvalStaleDays"
							type="number"
							class="mywork-admin__input"
							min="1"
							max="365"
							@change="save">
						<span class="mywork-admin__hint">
							{{ t('teamhub', 'The Nextcloud Approval app has no expiry of its own. This is a TeamHub display rule and it never changes anything in the source app.') }}
						</span>
					</label>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Warn this many days beforehand') }}</span>
						<input
							v-model.number="config.approvalWarnDays"
							type="number"
							class="mywork-admin__input"
							min="1"
							:max="Math.max(1, config.approvalStaleDays - 1)"
							@change="save">
					</label>
				</section>

				<section class="mywork-admin__section">
					<h3 class="mywork-admin__heading">{{ t('teamhub', 'Performance') }}</h3>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Cache results for (seconds)') }}</span>
						<input
							v-model.number="config.cacheTtl"
							type="number"
							class="mywork-admin__input"
							:min="bounds.cacheTtl.min"
							:max="bounds.cacheTtl.max"
							@change="save">
						<span class="mywork-admin__hint">
							{{ t('teamhub', 'Zero disables caching. Acting on an item always clears that member’s cache immediately, so a short lifetime is safe.') }}
						</span>
					</label>

					<label class="mywork-admin__field">
						<span class="mywork-admin__label">{{ t('teamhub', 'Total time budget for all sources (milliseconds)') }}</span>
						<input
							v-model.number="config.budgetMs"
							type="number"
							class="mywork-admin__input"
							:min="bounds.budgetMs.min"
							:max="bounds.budgetMs.max"
							:step="500"
							@change="save">
						<span class="mywork-admin__hint">
							{{ t('teamhub', 'Sources run in order until the budget is spent; any that have not run yet are reported as timed out rather than being started.') }}
						</span>
					</label>
				</section>
			</div>

			<!-- ── Category mapping ───────────────────────────────────────
			     A table (v4.9.19): one row per (source, status) with the
			     category in its own column, so the eye runs down the statuses
			     instead of reading each pair as a sentence. -->
			<section class="mywork-admin__section">
				<h3 class="mywork-admin__heading">{{ t('teamhub', 'Category mapping') }}</h3>
				<p class="mywork-admin__hint">
					{{ t('teamhub', 'Each source reports its own status for an item. These rules decide which My Work category that status lands in. Leave them alone unless your organisation reads one of these statuses differently.') }}
				</p>

				<table class="mywork-admin__map">
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Source') }}</th>
							<th scope="col">{{ t('teamhub', 'Status') }}</th>
							<th scope="col">{{ t('teamhub', 'Category') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in mappingRows"
							:key="row.key"
							class="mywork-admin__map-row">
							<td class="mywork-admin__map-source">{{ row.providerName }}</td>
							<td><code>{{ row.status }}</code></td>
							<td>
								<!-- The flex wrapper is a span, not the cell: a
								     `display: flex` td stops being a table cell
								     to assistive technology. -->
								<span class="mywork-admin__map-category">
									<select
										class="mywork-admin__input mywork-admin__select"
										:aria-label="t('teamhub', 'Category for {status} from {source}', { status: row.status, source: row.providerName })"
										:value="config.categoryMap[row.key] || row.default"
										@change="setMapping(row.key, $event.target.value)">
										<option v-for="c in categories" :key="c" :value="c">
											{{ categoryLabel(c) }}
										</option>
									</select>
									<NcButton
										v-if="isOverridden(row)"
										variant="tertiary"
										size="small"
										:aria-label="t('teamhub', 'Reset {status} to its default category', { status: row.status })"
										@click="resetMapping(row)">
										{{ t('teamhub', 'Reset') }}
									</NcButton>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- ── Talk threading ─────────────────────────────────────────
			     Not a My Work setting, and last on the page because of it.
			     It is here for the same reason the server puts it on this
			     endpoint: this is the one admin-gated status surface that
			     exists, and the fact it carries is the one that settles
			     whether a proposal shared with a whole team opens a Talk
			     thread. `startProposalThread()` has now been written against
			     an assumed `ChatManager::sendMessage()` three times; the
			     signature below is what the instance actually declares.

			     The server has returned this since v4.5.45 with nothing
			     rendering it — a diagnostic nobody can reach is not a
			     diagnostic.

			     Keys print verbatim rather than translated: they are API
			     identifiers, and a localised paraphrase of
			     `sendMessageSignature` would be harder to act on, not
			     easier.

			     TRANSLATORS: "Talk threading" is the heading of a diagnostic
			     panel. "Talk" is the Nextcloud chat app and stays untranslated;
			     "threading" means replies grouped into conversation threads —
			     nothing to do with CPU threads or sewing. -->
			<section v-if="talkThreadingRows.length" class="mywork-admin__section">
				<details class="mywork-admin__diag mywork-admin__diag--talk">
					<summary class="mywork-admin__diag-summary">
						{{ t('teamhub', 'Talk threading') }}
					</summary>
					<dl class="mywork-admin__diag-list">
						<div
							v-for="row in talkThreadingRows"
							:key="row.key"
							class="mywork-admin__diag-row">
							<dt><code>{{ row.key }}</code></dt>
							<dd><code>{{ row.value }}</code></dd>
						</div>
					</dl>
				</details>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

import { CATEGORY_ORDER, actionLabel, categoryLabel, formatAbsolute } from '../../constants/myWork.js'
import { ICON_BODY } from '../../constants/uiTokens.js'

/**
 * Admin settings panel for My Work (v4.5.21).
 *
 * Kept out of AdminSettings.vue's own 4 600 lines: this is a self-contained
 * panel with its own endpoints and its own state, and folding it in would make
 * that file harder to work in for no benefit. AdminSettings mounts it inside a
 * tab panel and nothing else — and, since v4.9.19, only while a licence is
 * active: My Work itself is licence-gated, and a settings page for a feature
 * nobody can open was a page that showed on every unlicensed instance.
 *
 * Saves are per-change rather than batched behind a Save button, matching the
 * Integrations tab: every field here is independent, and an admin who flips a
 * provider off expects it to be off.
 */
export default {
	name: 'MyWorkAdminSettings',
	components: { ChevronRightIcon, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, RefreshIcon },

	data() {
		return {
			ICON_BODY,
			loading: true,
			saving: false,
			saved: false,
			error: null,
			providers: [],
			restrictable: [],
			// The source whose details the pane shows. Kept by id, not by
			// object: `reload()` replaces the array, and the pane should stay
			// on the same source across a reload rather than jumping back to
			// the first.
			selectedId: null,
			// Not a My Work fact. It rides this endpoint because this is the
			// one admin-gated status surface that already exists, and the
			// server has been reporting it since v4.5.45 with nothing on
			// screen to read it — see the comment on the endpoint.
			talkThreading: null,
			config: {
				upcomingDays: 7,
				actionRequiredDays: 2,
				completedDays: 7,
				cacheTtl: 60,
				budgetMs: 4000,
				approvalStaleDays: 14,
				approvalWarnDays: 3,
				categoryMap: {},
			},
			defaultCategoryMap: {},
			bounds: {
				upcomingDays: { min: 1, max: 90 },
				actionRequiredDays: { min: 0, max: 30 },
				completedDays: { min: 1, max: 90 },
				cacheTtl: { min: 0, max: 900 },
				budgetMs: { min: 500, max: 30000 },
			},
			categories: CATEGORY_ORDER,
			_savedTimer: null,
		}
	},

	computed: {
		/** The provider the detail pane shows; the first one until a row is chosen. */
		selected() {
			return this.providers.find(p => p.id === this.selectedId) || this.providers[0] || null
		},

		/**
		 * One row per (provider, source status) pair the providers declare, so
		 * a future provider's statuses appear here with no code change.
		 */
		mappingRows() {
			const rows = []
			this.providers.forEach(provider => {
				(provider.capabilities?.statuses || []).forEach(status => {
					const key = `${provider.id}.${status}`
					rows.push({
						key,
						status,
						providerId: provider.id,
						providerName: provider.name,
						default: this.defaultCategoryMap[key] || 'upcoming',
					})
				})
			})
			return rows
		},

		/**
		 * The Talk-threading diagnostic as printable rows.
		 *
		 * Stringified here rather than in the template so `false` renders as
		 * "false" instead of disappearing — which matters, because "no thread
		 * title placement was found" is the whole answer this block exists to
		 * give, and a blank row would read as a broken panel.
		 */
		talkThreadingRows() {
			const d = this.talkThreading
			if (!d) return []
			return Object.keys(d).map(key => {
				const v = d[key]
				if (Array.isArray(v)) {
					return { key, value: v.length ? v.join('\n') : '—' }
				}
				return { key, value: v === '' || v === null ? '—' : String(v) }
			})
		},
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
		if (this._savedTimer) {
			clearTimeout(this._savedTimer)
			this._savedTimer = null
		}
	},

	methods: {
		t,
		actionLabel,
		categoryLabel,
		formatAbsolute,

		async reload() {
			this.loading = true
			this.error = null
			try {
				const [configResp, statusResp] = await Promise.all([
					axios.get(generateUrl('/apps/teamhub/api/v1/admin/mywork/config')),
					axios.get(generateUrl('/apps/teamhub/api/v1/admin/mywork/status')),
				])

				const cfg = configResp.data || {}
				this.config = {
					upcomingDays: cfg.upcomingDays ?? 7,
					actionRequiredDays: cfg.actionRequiredDays ?? 2,
					completedDays: cfg.completedDays ?? 7,
					cacheTtl: cfg.cacheTtl ?? 60,
					budgetMs: cfg.budgetMs ?? 4000,
					approvalStaleDays: cfg.approvalStaleDays ?? 14,
					approvalWarnDays: cfg.approvalWarnDays ?? 3,
					categoryMap: { ...(cfg.categoryMap || {}) },
				}
				this.defaultCategoryMap = cfg.defaultCategoryMap || {}
				if (cfg.bounds) {
					this.bounds = cfg.bounds
				}

				this.providers = statusResp.data?.providers || []
				this.restrictable = statusResp.data?.actions || []
				this.talkThreading = statusResp.data?.talkThreading || null

				// A source that disappeared between reloads (an app uninstalled)
				// must not leave the pane pointing at nothing.
				if (!this.providers.some(p => p.id === this.selectedId)) {
					this.selectedId = this.providers[0]?.id ?? null
				}
			} catch (e) {
				this.error = t('teamhub', 'Could not load My Work settings.')
			} finally {
				this.loading = false
			}
		},

		/** Actions this provider can perform AND an admin can restrict. */
		restrictableActions(provider) {
			const capable = provider.capabilities?.actions || []
			return this.restrictable.filter(a => capable.includes(a))
		},

		isOverridden(row) {
			const current = this.config.categoryMap[row.key]
			return !!current && current !== row.default
		},

		setMapping(key, value) {
			this.config.categoryMap = { ...this.config.categoryMap, [key]: value }
			this.save()
		},

		resetMapping(row) {
			const next = { ...this.config.categoryMap }
			delete next[row.key]
			this.config.categoryMap = next
			this.save()
		},

		async setProviderEnabled(provider, enabled) {
			await this.saveProvider(provider, { enabled })
		},

		async toggleAction(provider, action, checked) {
			const capable = provider.capabilities?.actions || []
			// Send only the source-mutating subset. The server drops native
			// actions anyway, but sending them would make the stored value
			// misleading if it were ever read by hand.
			const current = provider.allowedActions.filter(a => capable.includes(a))
			const next = checked
				? [...new Set([...current, action])]
				: current.filter(a => a !== action)
			await this.saveProvider(provider, { actions: next })
		},

		async saveProvider(provider, patch) {
			this.saving = true
			this.error = null
			try {
				const { data } = await axios.put(
					generateUrl(`/apps/teamhub/api/v1/admin/mywork/providers/${encodeURIComponent(provider.id)}`),
					patch,
				)
				provider.enabled = data.enabled
				provider.allowedActions = data.allowedActions || []
				this.flashSaved()
			} catch (e) {
				this.error = t('teamhub', 'Could not save the source settings.')
			} finally {
				this.saving = false
			}
		},

		async save() {
			this.saving = true
			this.error = null
			try {
				const { data } = await axios.put(
					generateUrl('/apps/teamhub/api/v1/admin/mywork/config'),
					this.config,
				)
				// Re-read from the response: the server clamps out-of-range
				// values, so the field must show what was actually stored
				// rather than what was typed.
				this.config = {
					upcomingDays: data.upcomingDays,
					actionRequiredDays: data.actionRequiredDays,
					completedDays: data.completedDays,
					cacheTtl: data.cacheTtl,
					budgetMs: data.budgetMs,
					approvalStaleDays: data.approvalStaleDays,
					approvalWarnDays: data.approvalWarnDays,
					categoryMap: { ...(data.categoryMap || {}) },
				}
				this.flashSaved()
			} catch (e) {
				this.error = t('teamhub', 'Could not save My Work settings.')
			} finally {
				this.saving = false
			}
		},

		flashSaved() {
			this.saved = true
			if (this._savedTimer) {
				clearTimeout(this._savedTimer)
			}
			this._savedTimer = setTimeout(() => { this.saved = false }, 2000)
		},
	},
}
</script>

<style scoped lang="scss">
.mywork-admin {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xl, 24px);
	// Wider than the 780px the stacked page had: the list and the pane sit
	// side by side, and the pane has to hold a diagnostics row unbroken.
	max-width: 980px;
}

.mywork-admin__loading {
	display: flex;
	justify-content: center;
	padding: var(--th-space-xl, 24px) 0;
}

.mywork-admin__toolbar {
	display: flex;
	align-items: center;
	gap: var(--th-space-md, 12px);
}

// Reserves one line so Saved / an error appearing does not move the page.
.mywork-admin__status {
	display: inline-flex;
	align-items: center;
	min-height: var(--default-clickable-area, 44px);
	font-size: var(--th-font-meta, 12px);
}

.mywork-admin__section {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	min-width: 0;
}

.mywork-admin__heading {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-bold, 700);
}

.mywork-admin__hint {
	margin: 0;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
	line-height: var(--th-line-height-body, 1.4);
}

.mywork-admin__empty {
	font-size: var(--th-font-meta, 12px);
	color: var(--color-text-maxcontrast);
}

/* ── Sources: list + detail pane ──────────────────────────────────── */

.mywork-admin__sources {
	display: grid;
	grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
	gap: var(--th-space-lg, 16px);
	align-items: start;
}

.mywork-admin__list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xxs, 2px);
	margin: 0;
	padding: var(--th-space-xs, 4px);
	list-style: none;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
}

.mywork-admin__row {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

// The switch has no visible label (its aria-label names the source); the
// name beside it is the row's button, so clicking the name opens the pane
// rather than flipping the switch.
.mywork-admin__row-switch {
	flex: 0 0 auto;
}

.mywork-admin__row-button {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm, 8px);
	flex: 1 1 auto;
	min-width: 0;
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 0 var(--th-space-sm, 8px) 0 var(--th-space-md, 12px);
	border: none;
	border-radius: var(--th-radius-control, var(--border-radius));
	background-color: transparent;
	color: var(--color-main-text);
	font-size: var(--th-font-body, 14px);
	font-weight: var(--th-font-weight-regular, 400);
	text-align: start;
	cursor: pointer;
}

.mywork-admin__row-button:hover {
	background-color: var(--color-background-hover);
}

.mywork-admin__row-button:focus-visible {
	background-color: var(--color-background-hover);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

// Selected: the canonical full-saturation pair, plus a weight change so it
// is not colour alone (WCAG 1.4.1); aria-current says the same to a reader.
// `--color-primary-element-hover` is hover feedback on an element that is
// already state-coloured — the one place /ui-standards allows it.
.mywork-admin__row-button--selected,
.mywork-admin__row-button--selected:focus-visible {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: var(--th-font-weight-semibold, 600);
}

.mywork-admin__row-button--selected:hover {
	background-color: var(--color-primary-element-hover);
	color: var(--color-primary-element-text);
	font-weight: var(--th-font-weight-semibold, 600);
}

// The primary ring would vanish on the primary fill; the text colour is the
// contrast pair that exists for exactly this surface.
.mywork-admin__row-button--selected:focus-visible {
	outline-color: var(--color-primary-element-text);
}

.mywork-admin__row-name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.mywork-admin__row-chevron {
	flex: 0 0 auto;
	opacity: 0.6;
}

.mywork-admin__detail {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	min-width: 0;
	padding: var(--th-space-md, 12px) var(--th-space-lg, 16px);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
	background-color: var(--color-main-background);
}

.mywork-admin__detail-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--th-space-sm, 8px);
	flex-wrap: wrap;
}

.mywork-admin__detail-title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-bold, 700);
}

// Availability is stated in words inside the pill, so the colour is
// reinforcement rather than the signal (WCAG 1.4.1).
.mywork-admin__pill {
	flex: 0 0 auto;
	padding: 1px var(--th-space-sm, 8px);
	border-radius: var(--th-radius-pill, 999px);
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	border: 1px solid var(--color-border);
	white-space: nowrap;
}

.mywork-admin__pill--ok {
	background: var(--color-success);
	color: var(--color-primary-element-text);
	border-color: var(--color-success);
}

.mywork-admin__pill--warn {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.mywork-admin__reason {
	margin: 0;
	font-size: var(--th-font-meta, 12px);
	color: var(--color-warning-text, var(--color-warning));
}

.mywork-admin__facts {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px) var(--th-space-xl, 24px);
	margin: 0;
	font-size: var(--th-font-meta, 12px);
}

.mywork-admin__fact {
	display: flex;
	gap: var(--th-space-xs, 4px);

	dt {
		color: var(--color-text-maxcontrast);
	}
	dd {
		margin: 0;
	}
}

.mywork-admin__fact-error {
	color: var(--color-error-text, var(--color-error));
	word-break: break-word;
}

.mywork-admin__actions,
.mywork-admin__detail-diag {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	padding-top: var(--th-space-sm, 8px);
	border-top: 1px solid var(--color-border);
}

.mywork-admin__action-grid {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px) var(--th-space-lg, 16px);
}

/* ── Time windows · Performance ───────────────────────────────────── */

// Two columns when they fit, one otherwise — the same rule, no media query.
.mywork-admin__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: var(--th-space-xl, 24px) var(--th-space-xl, 24px);
}

/* Label and input on one line, hint underneath spanning both.
   Justin, v4.5.21 review: "put the input field for Time windows next to the
   text instead of below it" — a column of stacked label/input pairs makes a
   settings list twice as tall as it needs to be and separates each number
   from the sentence that explains it. */
.mywork-admin__field {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	align-items: center;
	gap: var(--th-space-xxs, 2px) var(--th-space-md, 12px);
	padding: var(--th-space-xs, 4px) 0;
}

.mywork-admin__label {
	font-size: var(--th-font-meta, 12px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-main-text);
	line-height: var(--th-line-height-body, 1.4);
}

/* Full-width, under both columns — a hint belongs to the pair, not to the
   input alone. */
.mywork-admin__field > .mywork-admin__hint {
	grid-column: 1 / -1;
}

.mywork-admin__input {
	width: 110px;
	max-width: 110px;
	text-align: right;
	padding: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
	font-size: var(--th-font-body, 14px);
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--th-radius-control, var(--border-radius));
	outline: none;

	&:focus {
		border-color: var(--color-primary-element);
	}
	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

/* ── Category mapping ─────────────────────────────────────────────── */

.mywork-admin__map {
	width: 100%;
	max-width: 620px;
	border-collapse: collapse;
	font-size: var(--th-font-meta, 12px);

	th {
		padding: var(--th-space-xs, 4px) var(--th-space-sm, 8px);
		text-align: start;
		font-weight: var(--th-font-weight-semibold, 600);
		color: var(--color-text-maxcontrast);
		border-bottom: 1px solid var(--color-border);
	}

	td {
		padding: var(--th-space-xxs, 2px) var(--th-space-sm, 8px);
		vertical-align: middle;
	}

	code {
		font-size: var(--th-font-micro, 11px);
		color: var(--color-text-maxcontrast);
	}
}

.mywork-admin__map-source {
	font-weight: var(--th-font-weight-semibold, 600);
	white-space: nowrap;
}

.mywork-admin__map-category {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
}

/* The category select is a select, not a number field — it needs room for
   its longest option ("Waiting for others"). */
.mywork-admin__select {
	width: auto;
	max-width: 220px;
	text-align: start;
}

/* ── Diagnostics (pane rows and the Talk threading disclosure) ────── */

.mywork-admin__diag {
	border-top: 1px solid var(--color-border);
	padding-top: var(--th-space-sm, 8px);
}

.mywork-admin__diag-summary {
	cursor: pointer;
	font-size: var(--th-font-meta, 12px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-primary-element);

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: var(--th-radius-control, var(--border-radius));
	}
}

.mywork-admin__diag-list {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.mywork-admin__diag > .mywork-admin__diag-list {
	margin-top: var(--th-space-sm, 8px);
}

.mywork-admin__diag-row {
	display: grid;
	grid-template-columns: 190px minmax(0, 1fr);
	gap: var(--th-space-sm, 8px);
	font-size: var(--th-font-micro, 11px);

	dt { color: var(--color-text-maxcontrast); }
	dd { margin: 0; min-width: 0; }

	code {
		word-break: break-word;
		font-size: var(--th-font-micro, 11px);
	}
}

/* The threading rows print method signatures and a list of them, so the
   newlines the computed joins on have to survive. `pre-line` rather than
   `pre`: it keeps the line breaks without also preserving the indentation
   the template's own markup would otherwise contribute. */
.mywork-admin__diag--talk {
	.mywork-admin__diag-row {
		grid-template-columns: 210px minmax(0, 1fr);

		dd code { white-space: pre-line; }
	}
}

.mywork-admin__saved {
	color: var(--color-success-text, var(--color-success));
}

.mywork-admin__error {
	color: var(--color-error-text, var(--color-error));
}

/* ── Narrow: the pane drops under the list ─────────────────────────── */

@media (max-width: 720px) {
	.mywork-admin__sources {
		grid-template-columns: minmax(0, 1fr);
	}

	.mywork-admin__diag-row {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
