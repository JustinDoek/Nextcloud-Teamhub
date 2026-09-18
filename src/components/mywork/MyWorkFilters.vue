<template>
	<section class="mywork-filters" :aria-label="t('teamhub', 'Filter and group My Work')">
		<!-- Search. The field is deliberately bare: NcTextField's default slot
		     renders a trailing icon BUTTON that overlaps the input and eats
		     clicks, so the magnifier sits outside it (HANDOFF.md § Key
		     technical facts). -->
		<div class="mywork-filters__search">
			<Magnify :size="iconBody" class="mywork-filters__search-icon" aria-hidden="true" />
			<NcTextField
				:model-value="search"
				:label="t('teamhub', 'Search my work')"
				:placeholder="t('teamhub', 'Search title, document or team')"
				trailing-button-icon="close"
				:trailing-button-label="t('teamhub', 'Clear search')"
				:show-trailing-button="search !== ''"
				@update:model-value="$emit('update:search', $event)"
				@trailing-button-click="$emit('update:search', '')" />
		</div>

		<div class="mywork-filters__row">
			<!-- Grouping -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Group by') }}</span>
				<select
					class="mywork-filters__native"
					:value="groupBy"
					@change="$emit('update:groupBy', $event.target.value)">
					<option v-for="opt in groupOptions" :key="opt.key" :value="opt.key">
						{{ opt.label }}
					</option>
				</select>
			</label>

			<!-- Team -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Team') }}</span>
				<select
					class="mywork-filters__native"
					:value="teamId"
					@change="$emit('update:teamId', $event.target.value)">
					<option value="">{{ t('teamhub', 'All teams') }}</option>
					<option v-for="team in teams" :key="team.id" :value="team.id">
						{{ team.name }}
					</option>
				</select>
			</label>

			<!-- Source / provider. v4.9.17 — the same vocabulary as the source
			     tabs (`sourceOptions`): a group (Files, Teams, Administration)
			     is one option, a single provider is one option, and a group
			     key is a valid providerId the server expands. -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Source') }}</span>
				<select
					class="mywork-filters__native"
					:value="providerId"
					@change="$emit('update:providerId', $event.target.value)">
					<option value="">{{ t('teamhub', 'All sources') }}</option>
					<option
						v-for="source in sources"
						:key="source.key"
						:value="source.key"
						:disabled="!source.available">
						{{ source.available
							? source.label
							: t('teamhub', '{name} (unavailable)', { name: source.label }) }}
					</option>
				</select>
			</label>

			<!-- Priority -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Priority') }}</span>
				<select
					class="mywork-filters__native"
					:value="priority"
					@change="$emit('update:priority', $event.target.value)">
					<option value="">{{ t('teamhub', 'Any priority') }}</option>
					<option v-for="p in priorities" :key="p" :value="p">
						{{ priorityLabel(p) }}
					</option>
				</select>
			</label>

			<!-- Due window -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Due') }}</span>
				<select
					class="mywork-filters__native"
					:value="dueWindow"
					@change="$emit('update:dueWindow', $event.target.value)">
					<option value="">{{ t('teamhub', 'Any date') }}</option>
					<option value="overdue">{{ t('teamhub', 'Overdue') }}</option>
					<option value="today">{{ t('teamhub', 'Today') }}</option>
					<option value="week">{{ t('teamhub', 'Next 7 days') }}</option>
					<option value="month">{{ t('teamhub', 'Next 30 days') }}</option>
				</select>
			</label>

			<!-- Resource type -->
			<label class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Type') }}</span>
				<select
					class="mywork-filters__native"
					:value="resourceType"
					@change="$emit('update:resourceType', $event.target.value)">
					<option value="">{{ t('teamhub', 'All types') }}</option>
					<option v-for="type in resourceTypes" :key="type" :value="type">
						{{ resourceTypeLabel(type) }}
					</option>
				</select>
			</label>

			<!-- Status. Populated from the enabled providers' capabilities, so
			     a new provider's statuses appear here without a code change.
			     v4.9.7 — labelled where the key is a shared one; an unknown
			     key still shows as itself. -->
			<label v-if="statuses.length" class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Status') }}</span>
				<select
					class="mywork-filters__native"
					:value="status"
					@change="$emit('update:status', $event.target.value)">
					<option value="">{{ t('teamhub', 'Any status') }}</option>
					<option v-for="s in statuses" :key="s" :value="s">
						{{ statusLabel(s) }}
					</option>
				</select>
			</label>

			<!-- v4.9.7 — the project and the work type, offered only when the
			     queue holds rows that carry them (OpenProject work packages
			     today). Both come from the payload's facets, so the list is
			     what is actually there, not every project on the instance. -->
			<label v-if="projects.length" class="mywork-filters__select">
				<span class="mywork-filters__label">{{ t('teamhub', 'Project') }}</span>
				<select
					class="mywork-filters__native"
					:value="projectId"
					@change="$emit('update:projectId', $event.target.value)">
					<option value="">{{ t('teamhub', 'All projects') }}</option>
					<option v-for="project in projects" :key="project.id" :value="project.id">
						{{ project.name }} ({{ project.count }})
					</option>
				</select>
			</label>

			<label v-if="workTypes.length" class="mywork-filters__select">
				<!-- TRANSLATORS: My Work filter — the work-package type in OpenProject (Task, Bug, Milestone …) -->
				<span class="mywork-filters__label">{{ t('teamhub', 'Work type') }}</span>
				<select
					class="mywork-filters__native"
					:value="workType"
					@change="$emit('update:workType', $event.target.value)">
					<option value="">{{ t('teamhub', 'All work types') }}</option>
					<option v-for="wt in workTypes" :key="wt.id" :value="wt.id">
						{{ wt.id }} ({{ wt.count }})
					</option>
				</select>
			</label>
		</div>

		<div class="mywork-filters__row mywork-filters__row--toggles">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="showSnoozed"
				@update:model-value="$emit('update:showSnoozed', $event)">
				{{ t('teamhub', 'Show snoozed items') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				v-if="hasActiveFilters"
				variant="tertiary"
				@click="$emit('reset')">
				<template #icon><FilterVariant :size="iconBody" /></template>
				{{ t('teamhub', 'Clear filters') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcTextField, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

import {
	PRIORITY_ORDER,
	groupByOptions,
	priorityLabel,
	resourceTypeLabel,
	statusLabel,
	sourceOptions,
} from '../../constants/myWork.js'
import { ICON_BODY } from '../../constants/uiTokens.js'

/**
 * Filter and grouping controls for My Work.
 *
 * Every control is a controlled input driven by props and reporting through
 * events — the state itself lives in the Vuex store so it survives the view
 * unmounting when the user opens an item inside a team, which is what the
 * specification means by "My Work state is preserved".
 *
 * Native `<select>` rather than `NcSelect`: seven filters in a row need to
 * stay compact, and the native control is the one that is already keyboard-
 * and screen-reader-correct on every platform without configuration. Each is
 * wrapped in a `<label>`, so the visible caption is the accessible name.
 */
export default {
	name: 'MyWorkFilters',
	components: { NcTextField, NcCheckboxRadioSwitch, NcButton, Magnify, FilterVariant },
	props: {
		search: { type: String, default: '' },
		groupBy: { type: String, default: 'category' },
		teamId: { type: String, default: '' },
		providerId: { type: String, default: '' },
		priority: { type: String, default: '' },
		status: { type: String, default: '' },
		resourceType: { type: String, default: '' },
		dueWindow: { type: String, default: '' },
		showSnoozed: { type: Boolean, default: false },
		/** v4.9.7 — source-specific narrowing, with the facets that feed the selects. */
		projectId: { type: String, default: '' },
		workType: { type: String, default: '' },
		projects: { type: Array, default: () => [] },
		workTypes: { type: Array, default: () => [] },
		teams: { type: Array, default: () => [] },
		providers: { type: Array, default: () => [] },
	},
	emits: [
		'update:search', 'update:groupBy', 'update:teamId', 'update:providerId',
		'update:priority', 'update:status', 'update:resourceType',
		'update:dueWindow', 'update:showSnoozed', 'update:projectId', 'update:workType', 'reset',
	],

	computed: {
		iconBody() { return ICON_BODY },
		groupOptions() { return groupByOptions() },
		priorities() { return PRIORITY_ORDER },
		/** v4.9.17 — the Source options, grouped the way the tabs are. */
		sources() { return sourceOptions(this.providers) },

		/** Union of the resource types the enabled providers can emit. */
		resourceTypes() {
			const set = new Set()
			this.providers.forEach(p => {
				(p.capabilities?.resourceTypes || []).forEach(rt => set.add(rt))
			})
			return [...set]
		},

		/** Union of the source statuses the enabled providers can emit. */
		statuses() {
			const set = new Set()
			this.providers.forEach(p => {
				(p.capabilities?.statuses || []).forEach(s => set.add(s))
			})
			return [...set]
		},

		hasActiveFilters() {
			return !!(this.search || this.teamId || this.providerId || this.priority
				|| this.status || this.resourceType || this.dueWindow || this.showSnoozed
				|| this.projectId || this.workType)
		},
	},

	methods: {
		t,
		priorityLabel,
		resourceTypeLabel,
		statusLabel,
	},
}
</script>

<style scoped lang="scss">
.mywork-filters {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 12px 14px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card, var(--border-radius-large));
}

.mywork-filters__search {
	display: flex;
	align-items: center;
	gap: 8px;
}

.mywork-filters__search-icon {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.mywork-filters__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 10px 14px;
}

.mywork-filters__row--toggles {
	align-items: center;
	justify-content: space-between;
}

.mywork-filters__select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.mywork-filters__label {
	font-size: var(--th-font-micro, 11px);
	font-weight: var(--th-font-weight-semibold, 600);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

// Native select styled to sit with NC's own form controls. The base rule
// clears the outline and :focus recolours the border — NC's documented form
// pattern — with a :focus-visible ring on top so keyboard focus is
// unmistakable (SKILLS.md § Focus visibility standard).
.mywork-filters__native {
	appearance: auto;
	min-width: 130px;
	max-width: 220px;
	padding: 5px 8px;
	font-size: var(--th-font-meta, 12px);
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

@media (max-width: 700px) {
	.mywork-filters__native {
		min-width: 0;
		max-width: none;
		width: 100%;
	}

	.mywork-filters__select {
		flex: 1 1 45%;
	}
}
</style>
