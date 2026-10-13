<template>
	<!-- v4.12.4 — the two questions a request for a new team has to answer
	     before the service team can open the create-team wizard: which
	     template, and which policy profile. One component because both start
	     points ask them — the sidebar's *Request team* dialog and the new-team
	     card on the Services page — and a second copy of the fetch, the
	     preselect and the "move the profile with the template" rule is how
	     the two drift apart.

	     Presentation follows CreateSubTeamModal: a select each, where the
	     full-page wizard uses a grid of cards, because this is a dialog and
	     /ui-standards names NcSelect as the control for a select-shaped
	     choice. All three read GET /api/v1/templates, so there is one source
	     of templates and three presentations of it. -->
	<div class="th-shape">
		<div v-if="templateOptions.length" class="th-shape__field">
			<label class="th-shape__label" :for="idPrefix + '-template'">
				{{ t('teamhub', 'Template') }}
			</label>
			<NcSelect
				v-model="templateKey"
				:input-id="idPrefix + '-template'"
				label-outside
				:aria-label-combobox="t('teamhub', 'Template')"
				:options="templateOptions"
				:reduce="o => o.id"
				:clearable="false"
				:disabled="disabled" />
			<span class="th-shape__hint">
				{{ t('teamhub', 'The template decides which modules and apps the new team gets.') }}
			</span>
		</div>

		<div v-if="profileOptions.length" class="th-shape__field">
			<label class="th-shape__label" :for="idPrefix + '-profile'">
				{{ t('teamhub', 'Profile') }}
			</label>
			<NcSelect
				v-model="profileKey"
				:input-id="idPrefix + '-profile'"
				label-outside
				:aria-label-combobox="t('teamhub', 'Profile')"
				:options="profileOptions"
				:reduce="o => o.id"
				:clearable="false"
				:disabled="disabled"
				@update:model-value="profileTouched = true" />
			<span v-if="activeProfile?.description" class="th-shape__hint">
				{{ activeProfile.description }}
			</span>
			<span v-else class="th-shape__hint">
				{{ t('teamhub', 'The profile sets the new team\'s sharing and privacy settings.') }}
			</span>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

/**
 * Template + profile pickers for a *request* for a new team.
 *
 * The component owns the two values and reports them upward
 * (`v-model:template-key`, `v-model:profile-key`); the dialogs around it only
 * record what it says and put it in the request.
 *
 * **Both loads fail open**, as in CreateSubTeamModal: a field whose options
 * could not be fetched is absent, the request is sent without it, and the
 * service team picks in the wizard as it did before this version existed.
 * `TeamRequestDefinition::validateStart()` keeps both optional for exactly
 * that reason. A dialog that refuses to open because a defaults fetch failed
 * would be the worse failure.
 */
export default {
	name: 'TeamShapeFields',

	components: { NcSelect },

	props: {
		/** Unique prefix for the two input ids, so `<label for>` resolves. */
		idPrefix: { type: String, default: 'th-shape' },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:templateKey', 'update:profileKey'],

	data() {
		return {
			templates: [],
			profiles: [],
			templateKey: '',
			profileKey: '',
			/** Set once the reader picks a profile, so switching template leaves it alone. */
			profileTouched: false,
		}
	},

	computed: {
		templateOptions() {
			return this.templates.map(tpl => ({ id: tpl.templateKey, label: tpl.label }))
		},

		profileOptions() {
			return this.profiles.map(p => ({ id: p.profileKey, label: p.label }))
		},

		/** The chosen profile's row, for the administrator's own words about it. */
		activeProfile() {
			return this.profiles.find(p => p.profileKey === this.profileKey) || null
		},
	},

	watch: {
		templateKey(key) {
			this.$emit('update:templateKey', key)
			// A template names the profile it expects; switching template
			// moves the selection with it, unless the reader has chosen a
			// profile themselves. Same rule as the team wizard
			// (`applyTemplateDefaultPolicy`), so a deliberate choice is never
			// overwritten by switching template to compare.
			if (!this.profileTouched) {
				this.applyTemplateDefault()
			}
		},

		profileKey(key) {
			this.$emit('update:profileKey', key)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Both lists first, then one preselect. Sequencing matters: the
		 * template's default profile can only be checked against a profile
		 * list that has arrived, and two independent fetches each doing their
		 * own preselect would make the result depend on which answered first.
		 */
		async load() {
			await Promise.all([this.loadTemplates(), this.loadProfiles()])
			this.preselect()
		},

		async loadTemplates() {
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/templates'))
				this.templates = data.templates || []
			} catch (e) {
				this.templates = []
			}
		},

		async loadProfiles() {
			try {
				const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/policy/creation'))
				this.profiles = data.profiles || []
			} catch (e) {
				this.profiles = []
			}
		},

		/** The first template, and the profile that template asks for. */
		preselect() {
			this.templateKey = this.templates[0]?.templateKey || ''
			// The watcher above has run `applyTemplateDefault()` already when
			// there is a template; this covers the case where there is none.
			if (!this.templateKey) {
				this.applyTemplateDefault()
			}
		},

		applyTemplateDefault() {
			const key = this.templates.find(x => x.templateKey === this.templateKey)?.defaultProfileKey || ''
			this.profileKey = this.profiles.some(p => p.profileKey === key)
				? key
				: (this.profiles[0]?.profileKey || '')
		},
	},
}
</script>

<style scoped lang="scss">
.th-shape {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
}

.th-shape__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.th-shape__label {
	font-size: var(--th-font-meta, 13px);
}

.th-shape__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}
</style>
