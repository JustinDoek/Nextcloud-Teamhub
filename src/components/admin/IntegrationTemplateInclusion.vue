<template>
	<section class="th-incl">

		<div v-if="loading" class="th-incl__loading">
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

		<template v-else-if="template">
			<p class="th-incl__lead">
				{{ t('teamhub', 'Teams created from this template take part in these integrations. Every widget and tab of an included integration is shown by default; a team administrator may hide individual ones but cannot add or remove the integration itself.') }}
			</p>

			<!-- A save is announced, not only shown. Same shape as the two
			     panels this section can appear inside. -->
			<div class="th-incl__status" role="status" aria-live="polite">
				<p v-if="notice" class="th-incl__notice">{{ notice }}</p>
			</div>

			<!-- v4.13.25 — a checkbox each, in the same grid as the editor's
			     Apps and Modules groups above (Justin, 2026-10-24). A picker
			     that added to a list below it read as a different kind of
			     control for what is the same kind of decision: what is a team
			     of this sort made of.

			     The box is **controlled by the server's answer**, not by the
			     click: `candidate.included` comes from the last load, and the
			     toggle only opens the preview. So cancelling the dialog leaves
			     the box exactly as it was with no code to put it back, and a
			     box can never show a state the server has not accepted. -->
			<div v-if="candidates.length" class="th-incl__checks">
				<div
					v-for="candidate in candidates"
					:key="candidate.id"
					class="th-incl__check">
					<NcCheckboxRadioSwitch
						:model-value="candidate.included"
						:disabled="busy !== null"
						@update:model-value="onToggle(candidate, $event)">
						{{ candidate.title || t('teamhub', 'Unknown integration') }}
					</NcCheckboxRadioSwitch>
					<NcChip
						v-if="candidate.unavailable"
						no-close
						variant="warning"
						:text="t('teamhub', 'Included, but currently unavailable')" />
					<span v-if="candidate.unavailable" class="th-incl__reason">
						{{ reasonText(candidate) }}
					</span>
				</div>
			</div>

			<p v-else class="th-incl__empty">
				{{ t('teamhub', 'No enabled integration is available to include. An administrator enables one under Administration → TeamHub → Integrations first.') }}
			</p>
		</template>

		<!-- ── Impact preview before a change ──────────────────────────────
		     Including or removing is one click that changes a widget grid and
		     a tab bar across every team of this template. The preview is what
		     turns that into a decision, and it is not skippable. -->
		<NcDialog
			v-if="target"
			:name="target.including
				? t('teamhub', 'Include {title}', { title: target.package.title })
				: t('teamhub', 'Remove {title}', { title: target.package.title || target.package.id })"
			:open="!!target"
			size="normal"
			@update:open="cancelChange">
			<template #default>
				<div v-if="previewLoading" class="th-incl__loading">
					<NcLoadingIcon :size="ICON_BODY" />
					<span>{{ t('teamhub', 'Working out what this affects') }}</span>
				</div>

				<template v-else-if="preview">
					<p class="th-incl__preview-lead">
						{{ preview.action === 'add'
							? t('teamhub', 'Teams of {template} will take part in this integration from now on. No team administrator has to do anything.', { template: preview.template_label })
							: t('teamhub', 'This integration stops working for teams of {template}. Nothing is deleted.', { template: preview.template_label }) }}
					</p>

					<dl class="th-incl__preview">
						<dt>{{ t('teamhub', 'Affected teams') }}</dt>
						<dd>{{ n('teamhub', '%n team', '%n teams', preview.teams_affected, { n: preview.teams_affected }) }}</dd>

						<template v-if="appearing.length">
							<dt>{{ t('teamhub', 'Will appear') }}</dt>
							<dd>{{ appearing.map(capability => capability.title).join(', ') }}</dd>
						</template>

						<template v-if="disappearing.length">
							<dt>{{ t('teamhub', 'Will disappear') }}</dt>
							<dd>{{ disappearing.map(capability => capability.title).join(', ') }}</dd>
						</template>

						<template v-if="myWorkChange.length">
							<dt>{{ preview.action === 'add'
								? t('teamhub', 'My Work sources that start contributing')
								: t('teamhub', 'My Work sources that stop contributing') }}</dt>
							<dd>{{ myWorkChange.map(capability => capability.title).join(', ') }}</dd>
						</template>

						<template v-if="preservedHidden > 0">
							<dt>{{ t('teamhub', 'Kept hidden') }}</dt>
							<dd>
								{{ n('teamhub',
									'%n team has previously hidden one of these and will keep it hidden.',
									'%n teams have previously hidden one of these and will keep them hidden.',
									preservedHidden, { n: preservedHidden }) }}
							</dd>
						</template>
					</dl>

					<NcNoteCard v-if="preview.unavailable" type="warning">
						{{ reasonText(preview) }}
					</NcNoteCard>

					<NcNoteCard v-if="preview.exceeds_run_limit" type="warning">
						{{ t('teamhub', 'This template has more teams than one reconciliation run covers. The change applies to every team at once; the notification pass runs in batches.') }}
					</NcNoteCard>
				</template>
			</template>
			<template #actions>
				<NcButton variant="tertiary" @click="cancelChange">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton
					:variant="target.including ? 'primary' : 'error'"
					:disabled="previewLoading || busy !== null"
					@click="executeChange">
					<template #icon>
						<NcLoadingIcon v-if="busy !== null" :size="ICON_BODY" />
						<CheckIcon v-else :size="ICON_BODY" />
					</template>
					{{ target.including
						? t('teamhub', 'Include in this template')
						: t('teamhub', 'Remove from this template') }}
				</NcButton>
			</template>
		</NcDialog>
	</section>
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import { ICON_BODY } from '../../constants/uiTokens.js'

/**
 * Which integrations one team template includes — v4.13.24.
 *
 * The third of TeamHub's four governance levels, scoped to a single template.
 *
 * ## Why this moved
 *
 * The inclusion matrix was a section of Administration → TeamHub →
 * **Integrations**, listing every template with its own picker. It sat beside
 * the enable switches because an administrator who has just enabled something
 * needs somewhere to put it — which is true, and was the wrong conclusion.
 *
 * A template is the thing an administrator sets up: what a new team is
 * created with, its apps, its modules, its default policy, whether it can
 * expire. The Integrations tab answers a different question — which
 * integrations exist on this server and which are switched on. Editing one
 * template meant leaving the template editor, finding that template again in
 * a list on another tab, and working out which of several pickers belonged to
 * it. So inclusion now lives inside the template editor, next to the apps and
 * modules it sits alongside conceptually, and the Integrations tab keeps the
 * read-only line that says where each enabled integration ended up.
 *
 * ## What is and is not decided here
 *
 * A Nextcloud administrator's decision, and only theirs — the server checks
 * that itself in `IntegrationTemplateService` rather than trusting the route.
 * Nothing here reaches a single team: which widget or tab a team shows is a
 * team administrator's, in Manage team → Modules & integrations →
 * Integration widgets and tabs.
 *
 * ## What a removal does not do
 *
 * Nothing is deleted. Not the per-team shown/hidden preferences, not the
 * ordering, not the other app's data. Re-including restores each team's
 * layout exactly, and that property is what makes an administrator willing to
 * try a template change at all — so the preview dialog states it rather than
 * leaving it to be discovered.
 */
export default {
	name: 'IntegrationTemplateInclusion',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcChip,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		CheckIcon,
		CloseIcon,
	},

	props: {
		/** The template being edited. Its inclusions are the only ones shown. */
		templateKey: { type: String, required: true },
	},

	emits: ['changed'],

	data() {
		return {
			ICON_BODY,

			loading: true,
			error: null,
			notice: null,

			/** This template's row from the inclusion matrix, or null. */
			template: null,
			/** Packages the server would accept — never the full list. */
			eligible: [],
			teamhubVersion: '',

			/** The package id a write is in flight for. */
			busy: null,

			/** { package, including } while the preview dialog is open. */
			target: null,
			preview: null,
			previewLoading: false,
		}
	},

	computed: {
		/**
		 * One row per integration this template could have or already has.
		 *
		 * The union of two server lists, and each contributes something the
		 * other cannot:
		 *
		 *  - `eligible` is what may be **added** — available, registered,
		 *    compatible and enabled. Built from this list and never from the
		 *    full one, so an administrator cannot tick something the server
		 *    would refuse.
		 *  - `template.included` is what the template **asks for**, which can
		 *    include a package that has since become unavailable. That one
		 *    stays listed, ticked and flagged: dropping it would hide the one
		 *    thing an administrator whose integration stopped working needs
		 *    to see, and they still have to be able to untick it.
		 *
		 * Sorted by title so ticking a box never reorders the list under the
		 * pointer.
		 */
		candidates() {
			const included = this.template?.included || []
			const byId = new Map()

			for (const row of included) {
				byId.set(row.id, {
					id: row.id,
					title: row.title,
					included: true,
					unavailable: !!row.included_but_unavailable,
					unavailable_reason: row.unavailable_reason,
				})
			}
			for (const row of this.eligible) {
				if (byId.has(row.id)) {
					continue
				}
				byId.set(row.id, {
					id: row.id,
					title: row.title,
					included: false,
					unavailable: false,
					unavailable_reason: row.unavailable_reason,
				})
			}

			return [...byId.values()].sort((a, b) => String(a.title).localeCompare(String(b.title)))
		},

		/** Capabilities the pending change would reveal. */
		appearing() {
			if (!this.preview) {
				return []
			}
			return [
				...this.preview.widgets_appearing,
				...this.preview.tabs_appearing,
			]
		},

		/** Capabilities the pending change would take away. */
		disappearing() {
			if (!this.preview) {
				return []
			}
			return [
				...this.preview.widgets_disappearing,
				...this.preview.tabs_disappearing,
			]
		},

		myWorkChange() {
			if (!this.preview) {
				return []
			}
			return this.preview.action === 'add'
				? this.preview.my_work_starting
				: this.preview.my_work_stopping
		},

		/**
		 * Teams that already hold a hidden preference for one of the
		 * capabilities being added.
		 *
		 * The single most surprising thing about re-including a package: a
		 * preserved hidden preference wins over shown-by-default, so the
		 * capability does not come back on those teams' screens. The preview
		 * says it rather than letting an administrator discover it.
		 */
		preservedHidden() {
			return this.appearing.reduce(
				(total, capability) => total + (capability.teams_with_preserved_hidden || 0),
				0,
			)
		},
	},

	watch: {
		// The editor can be reopened on a different template without the
		// component being destroyed.
		templateKey() {
			this.load()
		},
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
				const [templates, listing] = await Promise.all([
					axios.get(generateUrl('/apps/teamhub/api/v1/admin/integrations/templates')),
					// Only for the TeamHub version an incompatibility message
					// names. A failure here must not cost the editor its
					// inclusion list, so it is read separately below.
					axios.get(generateUrl('/apps/teamhub/api/v1/admin/integrations')).catch(() => null),
				])
				const rows = Array.isArray(templates.data.templates) ? templates.data.templates : []
				this.template = rows.find(row => row.template_key === this.templateKey) || null
				this.eligible = Array.isArray(templates.data.eligible) ? templates.data.eligible : []
				this.teamhubVersion = listing?.data?.teamhub_version || ''
			} catch (e) {
				this.error = this.errorText(e, t('teamhub', 'Could not load the integrations.'))
			} finally {
				this.loading = false
			}
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

		/**
		 * A box was toggled. Opens the preview; writes nothing yet.
		 *
		 * `including` comes from the checkbox's new value rather than from
		 * `!candidate.included`, so a double click or a stale render cannot
		 * ask for the change that is already true.
		 */
		onToggle(candidate, including) {
			if (including === candidate.included) {
				return
			}
			this.confirmChange(candidate, including)
		},

		async confirmChange(pkg, including) {
			this.target = { package: pkg, including }
			this.preview = null
			this.previewLoading = true
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/teamhub/api/v1/admin/integrations/templates/${encodeURIComponent(this.templateKey)}/preview/${pkg.id}`),
					{ params: { include: including ? 'true' : 'false' } },
				)
				this.preview = data
			} catch (e) {
				showError(this.errorText(e, t('teamhub', 'Could not build the impact preview.')))
				this.target = null
			} finally {
				this.previewLoading = false
			}
		},

		cancelChange() {
			this.target = null
			this.preview = null
		},

		async executeChange() {
			const target = this.target
			if (!target) {
				return
			}
			this.busy = target.package.id
			const url = generateUrl(
				`/apps/teamhub/api/v1/admin/integrations/templates/${encodeURIComponent(this.templateKey)}/include/${target.package.id}`,
			)
			try {
				const { data } = target.including
					? await axios.post(url)
					: await axios.delete(url)

				this.cancelChange()
				await this.load()

				const label = this.template?.label || this.templateKey
				this.notice = target.including
					? t('teamhub', '{title} is included in {template}.', { title: target.package.title, template: label })
					: t('teamhub', '{title} is no longer included in {template}. Nothing was deleted.', { title: target.package.title, template: label })
				showSuccess(this.notice)
				this.$emit('changed')

				// Lifecycle providers are the only thing a reconciliation
				// notifies, so it is offered only when the package has one —
				// a run that fires nothing is noise, not reassurance.
				if (data && data.reconcile_recommended) {
					await this.reconcile(target)
				}
			} catch (e) {
				showError(this.errorText(e, target.including
					? t('teamhub', 'Could not include the integration in the template.')
					: t('teamhub', 'Could not remove the integration from the template.')))
			} finally {
				this.busy = null
			}
		},

		async reconcile(target) {
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/teamhub/api/v1/admin/integrations/templates/${encodeURIComponent(this.templateKey)}/reconcile/${target.package.id}`),
					{ action: target.including ? 'added' : 'removed' },
				)
				if (data.failed > 0) {
					showError(n('teamhub',
						'The integration was notified about every team but one, which reported a problem.',
						'The integration was notified, but {failed} teams reported a problem.',
						data.failed, { failed: data.failed }))
				}
			} catch (e) {
				// The inclusion itself has already been saved and is already
				// in effect — inclusion is read live. A failed notification
				// pass is retryable and must not read as a failed change.
				showError(t('teamhub', 'The change was saved, but the integration could not be notified. Reconcile again from this page.'))
			}
		},

		/**
		 * The server's own message when it sent one, otherwise our fallback.
		 *
		 * The server's message is NOT wrapped in t(): it arrives already
		 * composed, and wrapping it would add a translation key whose entire
		 * content is one placeholder. The fallbacks each caller passes in are
		 * the translated half.
		 */
		errorText(e, fallback) {
			return e?.response?.data?.error || fallback
		},
	},
}
</script>

<style scoped>
.th-incl {
	margin-block-start: var(--th-space-md);
	padding-block-start: var(--th-space-md);
	border-block-start: 1px solid var(--color-border);
}

.th-incl__loading {
	display: flex;
	align-items: center;
	gap: var(--th-space-sm);
	padding: var(--th-space-md) 0;
	color: var(--color-text-maxcontrast);
}

.th-incl__lead {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-incl__status {
	min-height: var(--th-space-md);
}

.th-incl__notice {
	margin: 0 0 var(--th-space-sm);
	color: var(--color-text-success);
}

.th-incl__empty,
.th-incl__reason {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-incl__empty {
	margin: var(--th-space-xs) 0 0;
}

/* The same wrapping row of checkboxes the editor's Apps and Modules groups
   use, so the three read as one set of decisions. */
.th-incl__checks {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-sm) var(--th-space-md);
	margin-block-start: var(--th-space-xs);
}

.th-incl__check {
	display: flex;
	align-items: center;
	gap: var(--th-space-xs);
}

.th-incl__preview-lead {
	margin: 0 0 var(--th-space-md);
}

.th-incl__preview {
	margin: 0 0 var(--th-space-md);
}

.th-incl__preview dt {
	font-weight: 600;
	margin-block-start: var(--th-space-sm);
}

.th-incl__preview dd {
	margin: 0;
	margin-inline-start: 0;
}
</style>
