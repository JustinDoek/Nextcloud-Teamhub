<template>
	<section class="bp-section">
		<h4 class="bp-section__title">{{ t('teamhub', 'OpenProject workspace') }}</h4>
		<p class="bp-section__desc">
			{{ t('teamhub', 'How a workspace of this kind is built around its OpenProject project. The apps and modules above decide what the team is connected to; this decides the project, the roles, the files and the dashboard. What is inside the OpenProject project — work-package types, milestones, phases — is the OpenProject template\'s.') }}
		</p>

		<div v-if="loading" class="bp-section__state"><NcLoadingIcon :size="ICON_NAV" /></div>
		<p v-else-if="loadError" class="bp-section__error" role="alert">{{ loadError }}</p>

		<template v-else-if="bp">
			<span class="bp-section__label">{{ t('teamhub', 'Project') }}</span>
			<div class="bp-section__checks">
				<NcCheckboxRadioSwitch :model-value="bp.openproject.modes.includes('create')" @update:model-value="setMode('create', $event)">
					{{ t('teamhub', 'May create a new project') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :model-value="bp.openproject.modes.includes('link')" @update:model-value="setMode('link', $event)">
					{{ t('teamhub', 'May connect an existing project') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :model-value="bp.openproject.allowParent" @update:model-value="bp.openproject.allowParent = $event">
					{{ t('teamhub', 'May choose a parent project') }}
				</NcCheckboxRadioSwitch>
			</div>

			<span class="bp-section__label">{{ t('teamhub', 'Approved OpenProject templates') }}</span>
			<span class="bp-section__hint">
				{{ t('teamhub', 'Only these templates are offered when a new project is created. None ticked means every template the creator may copy.') }}
			</span>
			<p v-if="opError" class="bp-section__error" role="alert">{{ opError }}</p>
			<div v-else-if="opTemplates.length" class="bp-section__checks">
				<NcCheckboxRadioSwitch
					v-for="tpl in opTemplates"
					:key="tpl.id"
					:model-value="isApproved(tpl.id)"
					@update:model-value="setApproved(tpl, $event)">
					{{ tpl.name }} <code class="bp-section__key">{{ tpl.identifier }}</code>
				</NcCheckboxRadioSwitch>
			</div>
			<p v-else class="bp-section__hint">{{ t('teamhub', 'No OpenProject template is available to your account.') }}</p>
			<ul v-if="approvedUnknown.length" class="bp-section__hint bp-section__list">
				<li v-for="tpl in approvedUnknown" :key="tpl.id">
					{{ t('teamhub', 'Approved template #{id} ({name}) is not visible to your account; it stays approved.', { id: tpl.id, name: tpl.name || tpl.identifier || '' }) }}
				</li>
			</ul>

			<span class="bp-section__label">{{ t('teamhub', 'Copied from the OpenProject template') }}</span>
			<div class="bp-section__checks">
				<NcCheckboxRadioSwitch
					v-for="key in copyKeysShown"
					:key="key"
					:model-value="bp.openproject.copy[key] !== false"
					@update:model-value="bp.openproject.copy[key] = $event">
					{{ copyLabel(key) }}
				</NcCheckboxRadioSwitch>
			</div>

			<span class="bp-section__label">{{ t('teamhub', 'Role mapping') }}</span>
			<span class="bp-section__hint">
				{{ t('teamhub', 'The OpenProject role each TeamHub role gets when a workspace is created. Memberships are created as the person creating the workspace, so OpenProject applies its own rules on top.') }}
			</span>
			<table class="bp-section__table">
				<thead>
					<tr>
						<th scope="col">{{ t('teamhub', 'TeamHub role') }}</th>
						<th scope="col">{{ t('teamhub', 'OpenProject role') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="key in vocabulary.roleKeys" :key="key">
						<td>{{ roleLabel(key) }}</td>
						<td>
							<label class="bp-section__sr" :for="'bp-role-' + key">{{ t('teamhub', 'OpenProject role for {role}', { role: roleLabel(key) }) }}</label>
							<select :id="'bp-role-' + key" class="bp-section__select" :value="bp.roles.mapping[key] || ''" @change="setRole(key, $event.target.value)">
								<option value="">{{ t('teamhub', 'No OpenProject access') }}</option>
								<option v-for="r in roleChoices(key)" :key="r" :value="r">{{ r }}</option>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="bp-section__field">
				<label class="bp-section__label" for="bp-folder">{{ t('teamhub', 'Project files') }}</label>
				<select id="bp-folder" v-model="bp.folder.behavior" class="bp-section__select">
					<option value="both">{{ t('teamhub', 'Team folder, and link the OpenProject project folder') }}</option>
					<option value="teamhub">{{ t('teamhub', 'Team folder only') }}</option>
					<option value="openproject">{{ t('teamhub', 'Link the OpenProject project folder only') }}</option>
					<option value="none">{{ t('teamhub', 'No folder') }}</option>
				</select>
				<span class="bp-section__hint">
					{{ t('teamhub', 'The project folder OpenProject manages is OpenProject\'s: linked, never created or changed by TeamHub. The team folder is the team\'s.') }}
				</span>
			</div>

			<span class="bp-section__label">{{ t('teamhub', 'Dashboard widgets shown') }}</span>
			<span class="bp-section__hint">{{ t('teamhub', 'Widgets for applications the workspace does not have are hidden anyway. Leave every box unticked to hide nothing else.') }}</span>
			<div class="bp-section__checks">
				<NcCheckboxRadioSwitch
					v-for="w in vocabulary.widgets"
					:key="w"
					:model-value="bp.dashboard.widgets.includes(w)"
					@update:model-value="setWidget(w, $event)">
					{{ widgetLabel(w) }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="bp-section__actions">
				<NcButton v-if="stored" type="tertiary" :disabled="busy" @click="reset">
					{{ t('teamhub', 'Reset to shipped') }}
				</NcButton>
			</div>
			<p v-if="saveError" class="bp-section__error" role="alert">{{ saveError }}</p>
		</template>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { ICON_NAV } from '../../constants/uiTokens.js'
import { classifyError } from '../../lib/openProject.js'
import { roleLabel } from '../../lib/provisioning.js'

/**
 * OpenProjectBlueprintSection (v4.9.6, Phase 2) — the OpenProject part of
 * the template editor on Admin → TeamHub → Policy, shown for the
 * OpenProject template only. One editor per template (Justin, 2026-09-13):
 * the apps and modules above it decide what the team is connected to; this
 * section decides the project, the roles, the files and the dashboard.
 *
 * Loads the stored blueprint and the OpenProject roles and templates the
 * administrator's own account sees; `save()` is called by the owner after
 * the template itself is saved, so one Save button covers both.
 */
export default {
	name: 'OpenProjectBlueprintSection',

	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon },

	props: {
		templateKey: { type: String, required: true },
	},

	emits: ['saved'],

	data() {
		return {
			loading: false,
			loadError: '',
			busy: false,
			saveError: '',
			stored: false,
			bp: null,
			vocabulary: { widgets: [], roleKeys: [] },
			opRoles: [],
			opTemplates: [],
			opError: '',
			ICON_NAV,
		}
	},

	computed: {
		approvedUnknown() {
			const known = new Set(this.opTemplates.map(tpl => tpl.id))
			return (this.bp?.openproject?.approvedTemplates || []).filter(tpl => !known.has(tpl.id))
		},
		copyKeysShown() {
			return ['members', 'workPackages', 'versions', 'categories', 'wiki', 'queries', 'boards', 'overview', 'phases', 'storages']
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		roleLabel,

		url(path) {
			return generateUrl('/apps/teamhub/api/v1/admin' + path)
		},

		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { data } = await axios.get(this.url('/policy/templates/' + encodeURIComponent(this.templateKey) + '/blueprint'))
				this.stored = !!data.stored
				this.vocabulary = data.vocabulary || this.vocabulary
				this.bp = this.normalise(data.blueprint)
			} catch (e) {
				this.loadError = classifyError(e).message
			} finally {
				this.loading = false
			}
			try {
				const { data } = await axios.get(this.url('/policy/openproject-roles'))
				this.opRoles = Array.isArray(data.roles) ? data.roles : []
				this.opTemplates = Array.isArray(data.templates) ? data.templates : []
			} catch (e) {
				this.opError = classifyError(e).message
			}
		},

		/** A blueprint with every section present, so the template can bind into it. */
		normalise(raw) {
			const b = raw && typeof raw === 'object' ? JSON.parse(JSON.stringify(raw)) : {}
			b.dashboard = { widgets: [...(b.dashboard?.widgets || [])], hidden: [...(b.dashboard?.hidden || [])] }
			b.openproject = {
				required: b.openproject?.required !== false,
				modes: [...(b.openproject?.modes || ['create', 'link'])],
				approvedTemplates: [...(b.openproject?.approvedTemplates || [])],
				allowParent: b.openproject?.allowParent !== false,
				copy: { ...(b.openproject?.copy || {}) },
			}
			b.roles = { mapping: { ...(b.roles?.mapping || {}) }, required: [...(b.roles?.required || [])] }
			b.folder = { behavior: b.folder?.behavior || 'both' }
			b.governance = b.governance || {}
			b.lifecycle = b.lifecycle || {}
			b.version = 1
			// Apps, modules and their behaviours are the template row's and are
			// re-derived on the server; not sent from here.
			delete b.apps; delete b.modules; delete b.talk; delete b.calendar; delete b.collective
			return b
		},

		setWidget(w, on) {
			this.bp.dashboard.widgets = on
				? [...new Set([...this.bp.dashboard.widgets, w])]
				: this.bp.dashboard.widgets.filter(x => x !== w)
		},

		setMode(mode, on) {
			this.bp.openproject.modes = on
				? [...new Set([...this.bp.openproject.modes, mode])]
				: this.bp.openproject.modes.filter(x => x !== mode)
		},

		isApproved(id) {
			return this.bp.openproject.approvedTemplates.some(tpl => tpl.id === id)
		},

		setApproved(tpl, on) {
			const list = this.bp.openproject.approvedTemplates.filter(x => x.id !== tpl.id)
			if (on) list.push({ id: tpl.id, identifier: tpl.identifier, name: tpl.name })
			this.bp.openproject.approvedTemplates = list
		},

		setRole(key, name) {
			this.bp.roles.mapping[key] = name || null
		},

		/** The live roles, plus the stored name when it is not among them (kept visible, flagged in the wizard). */
		roleChoices(key) {
			const names = this.opRoles.map(r => r.name)
			const current = this.bp.roles.mapping[key]
			if (current && !names.includes(current)) names.push(current)
			return names
		},

		widgetLabel(w) {
			switch (w) {
			case 'msgstream': return t('teamhub', 'Messages')
			case 'widget-teaminfo': return t('teamhub', 'Team info')
			case 'widget-members': return t('teamhub', 'Members')
			case 'widget-calendar': return t('teamhub', 'Calendar')
			case 'widget-deck': return t('teamhub', 'Upcoming tasks')
			case 'widget-activity': return t('teamhub', 'Activity')
			case 'widget-pages': return t('teamhub', 'Pages')
			case 'widget-files-center': return t('teamhub', 'Files')
			case 'widget-decisions': return t('teamhub', 'Decisions')
			case 'widget-project-health': return t('teamhub', 'Project health')
			case 'widget-openproject': return t('teamhub', 'Project info')
			default: return w
			}
		},

		copyLabel(key) {
			switch (key) {
			case 'members': return t('teamhub', 'Members of the template')
			case 'workPackages': return t('teamhub', 'Work packages')
			case 'versions': return t('teamhub', 'Versions')
			case 'categories': return t('teamhub', 'Categories')
			case 'wiki': return t('teamhub', 'Wiki')
			case 'queries': return t('teamhub', 'Saved views')
			case 'boards': return t('teamhub', 'Boards')
			case 'overview': return t('teamhub', 'Overview page')
			case 'phases': return t('teamhub', 'Project phases')
			case 'storages': return t('teamhub', 'File storages')
			default: return key
			}
		},

		/**
		 * Store the blueprint. Called by the template editor's Save, after the
		 * template itself is saved. Rejects with the server's sentence so the
		 * owner can show it; the section shows it too.
		 */
		async save() {
			if (!this.bp) return
			this.busy = true
			this.saveError = ''
			try {
				await axios.put(this.url('/policy/templates/' + encodeURIComponent(this.templateKey) + '/blueprint'), { blueprint: this.bp })
				this.$emit('saved')
			} catch (e) {
				this.saveError = classifyError(e).message
				throw e
			} finally {
				this.busy = false
			}
		},

		async reset() {
			this.busy = true
			this.saveError = ''
			try {
				const { data } = await axios.delete(this.url('/policy/templates/' + encodeURIComponent(this.templateKey) + '/blueprint'))
				this.stored = !!data.stored
				this.bp = this.normalise(data.blueprint)
				this.$emit('saved')
			} catch (e) {
				this.saveError = classifyError(e).message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.bp-section {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm);
	margin-top: var(--th-space-lg);
	padding-top: var(--th-space-lg);
	border-top: 1px solid var(--color-border);
}

.bp-section__title {
	margin: 0;
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
}

.bp-section__desc,
.bp-section__hint {
	margin: 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.bp-section__list {
	padding-left: var(--th-space-lg);
}

.bp-section__state {
	display: flex;
	justify-content: center;
	padding: var(--th-space-lg);
}

.bp-section__error {
	margin: 0;
	font-size: var(--th-font-meta);
	color: var(--color-error-text);
}

.bp-section__label {
	margin-top: var(--th-space-sm);
	font-weight: var(--th-font-weight-semibold);
}

.bp-section__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.bp-section__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--th-font-meta);
}

.bp-section__table th,
.bp-section__table td {
	padding: var(--th-space-xs) var(--th-space-sm);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.bp-section__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.bp-section__select {
	min-height: 34px;
	padding: 0 var(--th-space-sm);
	border: 2px solid var(--color-border-dark);
	border-radius: var(--th-radius-control);
	background: var(--color-main-background);
	color: var(--color-main-text);
	outline: none;
}

.bp-section__select:focus {
	border-color: var(--color-primary-element);
}

.bp-section__select:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.bp-section__checks {
	display: flex;
	flex-wrap: wrap;
	gap: 0 var(--th-space-md);
}

.bp-section__key {
	margin-left: var(--th-space-xs);
	font-size: var(--th-font-micro);
	color: var(--color-text-maxcontrast);
}

.bp-section__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
