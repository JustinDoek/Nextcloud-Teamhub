<template>
	<div class="th-integ">

		<div v-if="loading" class="th-integ__loading">
			<NcLoadingIcon :size="ICON_BODY" />
			<span>{{ t('teamhub', 'Loading integrations') }}</span>
		</div>

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
			<template #action>
				<NcButton variant="secondary" @click="load">
					{{ t('teamhub', 'Try again') }}
				</NcButton>
			</template>
		</NcNoteCard>

		<template v-else>

			<!-- Status region: a save is announced to a screen reader, not
			     only shown. Same shape as PolicyAdminPanel. -->
			<div class="th-integ__status" role="status" aria-live="polite">
				<p v-if="notice" class="th-integ__notice">{{ notice }}</p>
			</div>

			<NcEmptyContent
				v-if="integrations.length === 0"
				:name="t('teamhub', 'No integrations registered')"
				:description="t('teamhub', 'An installed Nextcloud app registers its TeamHub integration when it starts. Nothing on this server has registered one yet.')">
				<template #icon>
					<PuzzleIcon :size="ICON_HERO" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<!-- ── The four governance levels, stated once ───────────
				     An administrator reading this page is making the second
				     of four decisions, and the page has to say which one or
				     "enabled" reads as "in use". -->
				<p class="th-integ__lead">
					{{ t('teamhub', 'Enabling an integration makes it available to the team template editor. It does not add it to any template and changes no existing team.') }}
				</p>

				<ul class="th-integ__list">
					<li
						v-for="item in integrations"
						:key="item.id"
						class="th-integ__row">

						<div class="th-integ__row-main">
							<div class="th-integ__row-head">
								<img
									v-if="!iconFailed[item.app_id]"
									:src="appIconUrl(item.app_id)"
									:alt="''"
									class="th-integ__row-icon"
									aria-hidden="true"
									@error="iconFailed[item.app_id] = true">
								<PuzzleIcon v-else :size="ICON_BODY" class="th-integ__row-icon" aria-hidden="true" />

								<span class="th-integ__row-title">{{ item.title }}</span>

								<NcChip
									no-close
									variant="tertiary"
									:text="item.app_id" />

								<NcChip
									v-if="item.version"
									no-close
									variant="tertiary"
									:text="item.version" />

								<NcChip
									v-if="item.is_legacy"
									no-close
									variant="tertiary"
									:text="t('teamhub', 'Legacy API')" />

								<NcChip
									no-close
									:variant="stateVariant(item)"
									:text="stateLabel(item)" />
							</div>

							<p v-if="item.description" class="th-integ__row-desc">
								{{ item.description }}
							</p>

							<!-- Capability summary: what this integration
							     actually supplies. A count rather than a list,
							     with the detail below it. -->
							<p class="th-integ__row-caps">
								<span>{{ capabilitySummary(item) }}</span>
							</p>

							<ul v-if="item.capabilities.length" class="th-integ__caps">
								<li
									v-for="capability in item.capabilities"
									:key="capability.capability_key"
									class="th-integ__cap">
									<component
										:is="capabilityIcon(capability.cap_type)"
										:size="ICON_INLINE"
										aria-hidden="true" />
									<span class="th-integ__cap-title">{{ capability.title }}</span>
									<span class="th-integ__cap-type">{{ capabilityTypeLabel(capability.cap_type) }}</span>
									<span v-if="!capability.registered" class="th-integ__cap-gone">
										{{ t('teamhub', 'no longer supplied') }}
									</span>
								</li>
							</ul>

							<p v-if="item.templates.length" class="th-integ__row-templates">
								{{ t('teamhub', 'In templates: {templates}', { templates: item.templates.map(template => template.label).join(', ') }) }}
							</p>

							<NcNoteCard
								v-if="item.unavailable_reason"
								:type="item.unavailable_reason === 'integration_not_enabled' ? 'info' : 'warning'"
								class="th-integ__row-note">
								{{ reasonText(item) }}
							</NcNoteCard>
						</div>

						<div class="th-integ__row-control">
							<NcCheckboxRadioSwitch
								type="switch"
								:model-value="item.integration_enabled"
								:disabled="busyId === item.id || (!item.integration_enabled && !canEnable(item))"
								:aria-label="item.integration_enabled
									? t('teamhub', 'Disable {title}', { title: item.title })
									: t('teamhub', 'Enable {title}', { title: item.title })"
								@update:model-value="onToggle(item, $event)">
								{{ item.integration_enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
							</NcCheckboxRadioSwitch>
						</div>
					</li>
				</ul>
			</template>

			<!-- ── Where an enabled integration ended up ────────────
			     v4.13.24 — the inclusion matrix that stood here is now inside the
			     template editor, Policy → Team templates → Edit. A template is
			     the thing an administrator sets up — its apps, its modules, its
			     default policy — and which integrations its teams take part in
			     belongs with those, not on a second tab holding one picker per
			     template.

			     What stays here is the read-only "In templates: …" line on each
			     row above, which answers the question this page should answer:
			     this integration is enabled, so where did it end up? -->
			<p class="th-integ__lead">
				{{ t('teamhub', 'To decide which teams take part in an integration, include it in a team template under Policy → Team templates.') }}
			</p>
		</template>

		<!-- ── Impact preview before a disable ───────────────────────────
		     A disable is one click that can empty a widget grid and a tab bar
		     across every team of several templates. The preview is what turns
		     that into a decision, and it is not skippable. -->
		<NcDialog
			v-if="disableTarget"
			:name="t('teamhub', 'Disable {title}', { title: disableTarget.title })"
			:open="!!disableTarget"
			size="normal"
			@update:open="cancelDisable">
			<template #default>
				<div v-if="previewLoading" class="th-integ__loading">
					<NcLoadingIcon :size="ICON_BODY" />
					<span>{{ t('teamhub', 'Working out what this affects') }}</span>
				</div>

				<template v-else-if="preview">
					<p class="th-integ__preview-lead">
						{{ t('teamhub', 'Disabling stops this integration everywhere on this server. Nothing is deleted.') }}
					</p>

					<dl class="th-integ__preview">
						<template v-if="preview.templates.length">
							<dt>{{ t('teamhub', 'Affected templates') }}</dt>
							<dd>
								<ul class="th-integ__preview-list">
									<li v-for="template in preview.templates" :key="template.template_key">
										{{ template.label }}
										<span class="th-integ__preview-meta">
											{{ n('teamhub', '%n team', '%n teams', template.team_count, { n: template.team_count }) }}
										</span>
									</li>
								</ul>
							</dd>
						</template>

						<template v-if="preview.widgets.length">
							<dt>{{ t('teamhub', 'Widgets that will disappear') }}</dt>
							<dd>{{ preview.widgets.map(capability => capability.title).join(', ') }}</dd>
						</template>

						<template v-if="preview.menu_items.length">
							<dt>{{ t('teamhub', 'Team tabs that will disappear') }}</dt>
							<dd>{{ preview.menu_items.map(capability => capability.title).join(', ') }}</dd>
						</template>

						<template v-if="preview.my_work_providers.length">
							<dt>{{ t('teamhub', 'My Work sources that will stop contributing') }}</dt>
							<dd>{{ preview.my_work_providers.map(capability => capability.title).join(', ') }}</dd>
						</template>
					</dl>

					<!-- The four facts that make the decision reversible. An
					     administrator who does not know them treats a disable
					     as destructive and never uses it. -->
					<NcNoteCard type="info">
						{{ t('teamhub', 'Template inclusions, each team\'s shown and hidden choices, the ordering and the other app\'s own data are all kept. Enabling the integration again restores it exactly.') }}
					</NcNoteCard>
				</template>
			</template>
			<template #actions>
				<NcButton variant="tertiary" @click="cancelDisable">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="previewLoading || busyId === disableTarget.id"
					@click="executeDisable">
					<template #icon>
						<NcLoadingIcon v-if="busyId === disableTarget.id" :size="ICON_BODY" />
						<CloseIcon v-else :size="ICON_BODY" />
					</template>
					{{ t('teamhub', 'Disable integration') }}
				</NcButton>
			</template>
		</NcDialog>

	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import CloseIcon from 'vue-material-design-icons/Close.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import TabIcon from 'vue-material-design-icons/Tab.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'

import { ICON_BODY, ICON_HERO, ICON_INLINE } from '../../constants/uiTokens.js'

/**
 * Administration → TeamHub → Integrations (v4.13.23).
 *
 * The second and third of TeamHub's four governance levels, which the page
 * names rather than collapsing:
 *
 *   available  the owning app is installed, enabled and has registered
 *   enabled    a Nextcloud administrator switched the integration on here
 *   included   a Nextcloud administrator put it in a team template
 *   shown      a team administrator shows it in their own team
 *
 * Only the middle two are decided here. "Available" is read from the server
 * and reported; "shown" belongs to Manage team and is deliberately absent —
 * there is no control on this page that reaches a single team.
 *
 * Every state the server sends is rendered, including the unavailable ones. An
 * integration whose owning app is disabled stays on the list with its reason,
 * because showing nothing reads as "it was removed" and sends an
 * administrator looking for a deletion that never happened.
 */
export default {
	name: 'IntegrationGovernancePanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		CloseIcon,
		PuzzleIcon,
	},

	data() {
		return {
			ICON_BODY,
			ICON_HERO,
			ICON_INLINE,

			loading: true,
			error: null,
			notice: null,

			integrations: [],
			teamhubVersion: '',

			// Per-app-icon failure, so a missing app icon degrades to the
			// puzzle glyph once rather than retrying on every re-render.
			iconFailed: {},

			busyId: null,

			// The disable flow: target, then its preview, then the write.
			disableTarget: null,
			preview: null,
			previewLoading: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		async load() {
			this.loading = true
			this.error = null
			try {
				// v4.13.24 — one request. The inclusion matrix this page used
				// to fetch alongside the listing belongs to the template
				// editor now, which fetches it itself.
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/integrations'))
				this.integrations = Array.isArray(data.integrations) ? data.integrations : []
				this.teamhubVersion = data.teamhub_version || ''
			} catch (e) {
				this.error = this.errorText(e, t('teamhub', 'Could not load the integrations.'))
			} finally {
				this.loading = false
			}
		},

		// ── State vocabulary ────────────────────────────────────────────

		canEnable(item) {
			return item.app_available && item.registered && item.compatible
		},

		stateVariant(item) {
			if (!item.app_available || !item.registered || !item.compatible) {
				return 'warning'
			}
			return item.integration_enabled ? 'success' : 'tertiary'
		},

		stateLabel(item) {
			if (!item.app_installed) {
				return t('teamhub', 'App not installed')
			}
			if (!item.app_available) {
				return t('teamhub', 'App disabled')
			}
			if (!item.registered) {
				return t('teamhub', 'Not registered')
			}
			if (!item.compatible) {
				return t('teamhub', 'Incompatible')
			}
			return item.integration_enabled
				? t('teamhub', 'Enabled')
				: t('teamhub', 'Available')
		},

		/**
		 * One sentence per reason, each naming the administrator's next step.
		 * The server sends the first condition that failed, so there is never
		 * a list to render.
		 */
		reasonText(item) {
			switch (item.unavailable_reason) {
			case 'app_not_installed':
				return t('teamhub', 'The owning app is not installed on this server.')
			case 'app_disabled':
				return t('teamhub', 'The owning app is disabled. Enable it in Nextcloud\'s app settings and this integration comes back with its configuration intact.')
			case 'not_registered':
				return t('teamhub', 'The owning app is enabled but did not register its integration. Check the Nextcloud log.')
			case 'incompatible':
				return t('teamhub', 'This integration does not support TeamHub {version}.', { version: this.teamhubVersion })
			case 'integration_not_enabled':
				return t('teamhub', 'Enable this integration to make it available to the template editor.')
			case 'package_record_missing':
				return t('teamhub', 'The template references an integration this server no longer has a record of.')
			default:
				return t('teamhub', 'This integration is not available.')
			}
		},

		capabilityTypeLabel(capType) {
			switch (capType) {
			case 'widget':
				// TRANSLATORS: a card an integration adds to a team's home view
				return t('teamhub', 'Widget')
			case 'widget_iframe':
				// TRANSLATORS: a card an integration adds to a team's home view and draws itself
				return t('teamhub', 'Widget (own design)')
			case 'menu_item':
				// TRANSLATORS: a tab an integration adds to a team's tab bar
				return t('teamhub', 'Team tab')
			case 'my_work_provider':
				return t('teamhub', 'My Work source')
			default:
				return capType
			}
		},

		capabilityIcon(capType) {
			switch (capType) {
			case 'widget':
			// v4.13.24 — a blank-canvas widget is a card on the same grid, so
			// it wears the same icon. The type label beside it is what says
			// the app draws the inside of this one.
			case 'widget_iframe':
				return ViewDashboardIcon
			case 'menu_item':
				return TabIcon
			default:
				return FormatListBulletedIcon
			}
		},

		/**
		 * "Two widgets, one team tab and a My Work source."
		 *
		 * Built from counted clauses rather than a sentence with three
		 * placeholders, so each clause pluralises in its own language and a
		 * zero clause is absent instead of reading "0 widgets".
		 */
		capabilitySummary(item) {
			const summary = item.capability_summary || {}
			const parts = []
			if (summary.widgets) {
				parts.push(n('teamhub', '%n widget', '%n widgets', summary.widgets, { n: summary.widgets }))
			}
			if (summary.menu_items) {
				parts.push(n('teamhub', '%n team tab', '%n team tabs', summary.menu_items, { n: summary.menu_items }))
			}
			if (summary.my_work_providers) {
				parts.push(n('teamhub', '%n My Work source', '%n My Work sources', summary.my_work_providers, { n: summary.my_work_providers }))
			}
			if (parts.length === 0) {
				return t('teamhub', 'Supplies nothing at the moment.')
			}
			return t('teamhub', 'Supplies {capabilities}.', { capabilities: parts.join(', ') })
		},

		/**
		 * Mirrors AdminSettings.appIconUrl(), TeamView's and
		 * IntegrationWidget's: the app's own shipped icon, by route rather
		 * than through imagePath(), which is what the other three do.
		 */
		appIconUrl(appId) {
			return generateUrl(`/apps/${appId}/img/app.svg`)
		},

		// ── Enable and disable ──────────────────────────────────────────

		onToggle(item, enable) {
			if (enable) {
				this.enable(item)
				return
			}
			// A disable always goes through the preview. Not skippable: see
			// the dialog's own comment.
			this.confirmDisable(item)
		},

		async enable(item) {
			this.busyId = item.id
			this.notice = null
			try {
				await axios.post(
					generateUrl(`/apps/teamhub/api/v1/admin/integrations/${item.id}/enable`),
				)
				await this.load()
				this.notice = t('teamhub', '{title} is enabled. Include it in a team template to put it to work.', { title: item.title })
				showSuccess(this.notice)
			} catch (e) {
				showError(this.errorText(e, t('teamhub', 'Could not enable the integration.')))
			} finally {
				this.busyId = null
			}
		},

		async confirmDisable(item) {
			this.disableTarget = item
			this.preview = null
			this.previewLoading = true
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/teamhub/api/v1/admin/integrations/${item.id}/disable-preview`),
				)
				this.preview = data
			} catch (e) {
				showError(this.errorText(e, t('teamhub', 'Could not build the impact preview.')))
				this.disableTarget = null
			} finally {
				this.previewLoading = false
			}
		},

		cancelDisable() {
			this.disableTarget = null
			this.preview = null
		},

		async executeDisable() {
			const item = this.disableTarget
			if (!item) {
				return
			}
			this.busyId = item.id
			try {
				await axios.post(
					generateUrl(`/apps/teamhub/api/v1/admin/integrations/${item.id}/disable`),
				)
				this.cancelDisable()
				await this.load()
				this.notice = t('teamhub', '{title} is disabled. Nothing was deleted.', { title: item.title })
				showSuccess(this.notice)
			} catch (e) {
				showError(this.errorText(e, t('teamhub', 'Could not disable the integration.')))
			} finally {
				this.busyId = null
			}
		},

		// ── Template inclusion ──────────────────────────────────────────

		/**
		 * The server's own message when it sent one, otherwise our fallback.
		 *
		 * The server's message is NOT wrapped in t(): it arrives already
		 * composed, and wrapping it would add a translation key whose entire
		 * content is one placeholder — nothing for a translator to translate,
		 * and one more string in every locale file. (Written out rather than
		 * shown as code because check-l10n.js reads comments too, and an
		 * example call in a docblock becomes a key.) The fallbacks each caller
		 * passes in are the translated half.
		 */
		errorText(e, fallback) {
			return e?.response?.data?.error || fallback
		},
	},
}
</script>

<style scoped>
.th-integ__loading {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm);
	padding: var(--th-space-md) 0;
	color: var(--color-text-maxcontrast);
}

.th-integ__status {
	min-height: var(--th-space-xl);
}

.th-integ__notice {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-success);
}

.th-integ__lead {
	margin: 0 0 var(--th-space-md);
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-integ__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
}

.th-integ__row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: var(--th-space-lg);
	padding: var(--th-space-md);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
	background: var(--color-main-background);
}

.th-integ__row-main {
	min-width: 0;
	flex: 1 1 auto;
}

.th-integ__row-head {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--th-space-sm);
}

.th-integ__row-icon {
	width: var(--th-icon-body);
	height: var(--th-icon-body);
	flex: 0 0 auto;
}

.th-integ__row-title {
	font-size: var(--th-font-heading);
	font-weight: 600;
}

.th-integ__row-desc,
.th-integ__row-caps,
.th-integ__row-templates {
	margin: var(--th-space-xs) 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-integ__caps {
	list-style: none;
	margin: var(--th-space-sm) 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.th-integ__cap {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs);
	font-size: var(--th-font-meta);
}

.th-integ__cap-type,
.th-integ__cap-gone {
	color: var(--color-text-maxcontrast);
}

.th-integ__cap-gone {
	color: var(--color-warning-text);
}

.th-integ__row-note {
	margin-block-start: var(--th-space-sm);
}

.th-integ__row-control {
	flex: 0 0 auto;
}

.th-integ__section {
	margin-block-start: var(--th-space-xl);
}

.th-integ__section-title {
	margin: 0 0 var(--th-space-sm);
	font-size: var(--th-font-heading);
	font-weight: 600;
}

.th-integ__template {
	padding: var(--th-space-md);
	margin-block-end: var(--th-space-sm);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
}

.th-integ__template-head {
	display: flex;
	align-items: baseline;
	gap: var(--th-space-sm);
}

.th-integ__template-label {
	font-weight: 600;
}

.th-integ__template-count,
.th-integ__template-empty,
.th-integ__included-reason {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-integ__template-empty {
	margin: var(--th-space-xs) 0 0;
}

.th-integ__included {
	list-style: none;
	margin: var(--th-space-sm) 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.th-integ__included-row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--th-space-sm);
}

.th-integ__included-title {
	font-size: var(--th-font-body);
}

.th-integ__template-add {
	margin-block-start: var(--th-space-sm);
	max-width: 32rem;
}

.th-integ__preview-lead {
	margin: 0 0 var(--th-space-md);
}

.th-integ__preview {
	margin: 0 0 var(--th-space-md);
}

.th-integ__preview dt {
	font-weight: 600;
	margin-block-start: var(--th-space-sm);
}

.th-integ__preview dd {
	margin: 0;
	margin-inline-start: 0;
}

.th-integ__preview-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.th-integ__preview-meta {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
	margin-inline-start: var(--th-space-xs);
}

@media (max-width: 640px) {
	.th-integ__row {
		flex-direction: column;
		gap: var(--th-space-sm);
	}
}
</style>
