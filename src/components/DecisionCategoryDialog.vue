<template>
	<NcDialog
		:name="category ? t('teamhub', 'Edit category') : t('teamhub', 'Add category')"
		:buttons="buttons"
		size="normal"
		@closing="$emit('close')">
		<div class="th-cat-dialog">
			<p v-if="isArchive" class="th-cat-dialog__locked">
				{{ t('teamhub', 'Superseded and archived decisions are kept here. Its name, approvers and review period are fixed; the icon and description are yours.') }}
			</p>

			<!-- Icon + name on one row: the icon is a 42px square and the
			     name is the rest, which is also how the card renders them. -->
			<div class="th-cat-dialog__row">
				<div class="th-cat-dialog__icon-col">
					<label class="th-cat-dialog__label" :for="iconButtonId">{{ t('teamhub', 'Icon') }}</label>
					<button
						:id="iconButtonId"
						type="button"
						class="th-cat-dialog__icon-btn"
						:aria-label="t('teamhub', 'Choose category icon')"
						:aria-expanded="pickerOpen"
						@click.stop="pickerOpen = !pickerOpen">
						<component :is="iconMap[form.icon]" v-if="form.icon && iconMap[form.icon]" :size="ICON_BODY" aria-hidden="true" />
						<span v-else class="th-cat-dialog__icon-empty" aria-hidden="true">—</span>
					</button>
				</div>

				<div class="th-cat-dialog__name-col">
					<label class="th-cat-dialog__label" :for="nameFieldId">{{ t('teamhub', 'Name') }}</label>
					<NcTextField
						:id="nameFieldId"
						v-model="form.name"
						maxlength="128"
						:disabled="isArchive || saving"
						:placeholder="t('teamhub', 'e.g. Architecture, Hiring, Policy')"
						label-outside />
				</div>
			</div>

			<!-- The picker sits in the flow rather than in a popover: inside a
			     dialog a popover either clips at the dialog's edge or escapes
			     it, and neither is worth the 300px this costs. -->
			<div v-if="pickerOpen" class="th-cat-dialog__picker">
				<NcTextField
					v-model="iconSearch"
					type="search"
					:label="t('teamhub', 'Search icons')"
					:placeholder="t('teamhub', 'Search icons')" />

				<div class="th-cat-dialog__picker-scroll" role="listbox" :aria-label="t('teamhub', 'Select an icon')">
					<div v-for="group in iconGroups" :key="group.name" class="th-cat-dialog__icon-group">
						<div class="th-cat-dialog__icon-group-hdr">{{ group.name }}</div>
						<div class="th-cat-dialog__icon-grid">
							<button
								v-for="ic in group.icons"
								:key="ic.name"
								type="button"
								class="th-cat-dialog__icon-cell"
								:class="{ 'th-cat-dialog__icon-cell--selected': form.icon === ic.name }"
								:aria-label="ic.label"
								:title="ic.label"
								role="option"
								:aria-selected="form.icon === ic.name"
								@click.stop="pickIcon(ic.name)">
								<component :is="iconMap[ic.name]" :size="ICON_BODY" aria-hidden="true" />
							</button>
						</div>
					</div>
					<p v-if="!iconGroups.length" class="th-cat-dialog__icon-none">
						{{ t('teamhub', 'No icons match "{q}"', { q: iconSearch }) }}
					</p>
				</div>

				<NcButton variant="tertiary" @click="pickIcon('')">
					{{ t('teamhub', 'No icon') }}
				</NcButton>
			</div>

			<label class="th-cat-dialog__label" :for="descFieldId">{{ t('teamhub', 'Description') }}</label>
			<NcTextField
				:id="descFieldId"
				v-model="form.description"
				maxlength="500"
				:disabled="saving"
				:placeholder="t('teamhub', 'Optional short description shown in the category grid')"
				label-outside />

			<label class="th-cat-dialog__label" :for="reviewFieldId">{{ t('teamhub', 'Review period (months)') }}</label>
			<p class="th-cat-dialog__hint">
				{{ isArchive
					? t('teamhub', 'Archived decisions are never reviewed.')
					: t('teamhub', 'The approvers get a task two weeks before a decision is due for review. 0 means no review.') }}
			</p>
			<NcTextField
				:id="reviewFieldId"
				v-model="form.reviewPeriodMonths"
				type="number"
				min="0"
				max="120"
				class="th-cat-dialog__months"
				:disabled="isArchive || saving"
				label-outside />

			<template v-if="!isArchive">
				<label class="th-cat-dialog__label">{{ t('teamhub', 'Approvers') }}</label>
				<p class="th-cat-dialog__hint">{{ t('teamhub', 'Leave empty to default to the team owner.') }}</p>
				<NcSelect
					v-model="form.approvers"
					:options="memberOptions"
					:multiple="true"
					:close-on-select="false"
					:clearable="true"
					:disabled="saving"
					label="displayName"
					track-by="userId"
					:placeholder="t('teamhub', 'Pick one or more members')"
					:aria-label="t('teamhub', 'Approver users')" />
			</template>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
/**
 * Create or edit a Decisions category (v4.11.23).
 *
 * **One form, two places.** It was written for the Decisions landing page,
 * where Justin asked for an *Add category* action so a team admin does not
 * have to go to Manage team to make one — *"he/she will have the same
 * options"*. The same options means the same form, and a second copy of a
 * 319-icon picker is a copy that drifts. So `ManageTeamView` uses this too,
 * and its two inline forms (add and edit, themselves near-duplicates of each
 * other) are gone.
 *
 * The component owns the whole exchange: the fields, the validation the
 * server also does, the POST or PUT, and the error. Its callers supply the
 * team, the members to pick approvers from, and the category to edit — and
 * get back a `saved` event when the server has agreed.
 *
 * **The archive category is editable here but mostly locked.** Its name,
 * approvers and review period are fixed server-side, so the form disables
 * them rather than letting somebody type into a field whose value will be
 * refused; the icon and description are genuinely theirs to change.
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

import { CATEGORY_ICONS, CATEGORY_ICON_MAP } from '../lib/decisionCategoryIcons.js'
import { ICON_BODY } from '../constants/uiTokens.js'

export default {
	name: 'DecisionCategoryDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField },

	props: {
		teamId: {
			type: String,
			required: true,
		},
		/** The category to edit, or null to create one. */
		category: {
			type: Object,
			default: null,
		},
		/** `[{ userId, displayName }]` — who may be picked as an approver. */
		members: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			ICON_BODY,
			form: {
				name: '',
				icon: '',
				description: '',
				// A string, because the field is a native number input. It
				// becomes an integer in one place, on submit.
				reviewPeriodMonths: '0',
				approvers: [],
			},
			pickerOpen: false,
			iconSearch: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		iconMap() {
			return CATEGORY_ICON_MAP
		},

		isArchive() {
			return !!this.category?.is_archive
		},

		/** Ids are per-instance so two dialogs could never share a label. */
		iconButtonId() {
			return `th-cat-icon-${this.category?.id ?? 'new'}`
		},
		nameFieldId() {
			return `th-cat-name-${this.category?.id ?? 'new'}`
		},
		descFieldId() {
			return `th-cat-desc-${this.category?.id ?? 'new'}`
		},
		reviewFieldId() {
			return `th-cat-review-${this.category?.id ?? 'new'}`
		},

		memberOptions() {
			return (this.members || [])
				.filter(m => m && m.userId)
				.map(m => ({ userId: m.userId, displayName: m.displayName || m.userId }))
		},

		iconGroups() {
			const q = this.iconSearch.trim().toLowerCase()
			const groups = new Map()
			for (const ic of CATEGORY_ICONS) {
				if (q && !ic.label.toLowerCase().includes(q) && !ic.name.toLowerCase().includes(q)) {
					continue
				}
				if (!groups.has(ic.group)) groups.set(ic.group, [])
				groups.get(ic.group).push(ic)
			}
			return [...groups.entries()].map(([name, icons]) => ({ name, icons }))
		},

		buttons() {
			return [
				{
					label: t('teamhub', 'Cancel'),
					type: 'secondary',
					disabled: this.saving,
					callback: () => this.$emit('close'),
				},
				{
					label: this.saving ? t('teamhub', 'Saving') : t('teamhub', 'Save'),
					type: 'primary',
					disabled: this.saving || !this.form.name.trim(),
					callback: () => this.submit(),
				},
			]
		},
	},

	created() {
		if (this.category) {
			const byId = new Map(this.memberOptions.map(o => [o.userId, o]))
			this.form = {
				name: this.category.name,
				icon: this.category.icon || '',
				description: this.category.description || '',
				reviewPeriodMonths: String(this.category.review_period_months ?? 0),
				// A former member who has left the team is kept as a bare uid
				// rather than dropped: removing somebody from the approver
				// list is a decision, not something a form does on open.
				approvers: (this.category.approvers || [])
					.map(uid => byId.get(uid) || { userId: uid, displayName: uid }),
			}
		} else {
			// A new category starts with the team owner, matching what the
			// server substitutes for an empty list — so the default the form
			// shows is the default the server would apply anyway.
			const owner = (this.members || []).find(m => Number(m.level) >= 9)
			this.form.approvers = owner
				? [{ userId: owner.userId, displayName: owner.displayName || owner.userId }]
				: []
		}
	},

	methods: {
		t,

		pickIcon(name) {
			this.form.icon = name
			this.pickerOpen = false
			this.iconSearch = ''
		},

		async submit() {
			const name = this.form.name.trim()
			if (!name) {
				this.error = t('teamhub', 'Name cannot be empty')
				return
			}

			const months = Number.parseInt(this.form.reviewPeriodMonths, 10)
			const payload = {
				icon: this.form.icon.trim() || null,
				description: this.form.description.trim() || null,
			}
			// The archive refuses a name, an approver list and a review
			// period, so they are not sent — sending them would turn an edit
			// of the description into a 400.
			if (!this.isArchive) {
				payload.name = name
				payload.approvers = this.form.approvers.map(a => a.userId).filter(Boolean)
				payload.review_period_months = Number.isFinite(months) ? months : 0
			}

			this.saving = true
			this.error = ''
			try {
				const base = generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/decisions/manage/categories`)
				const { data } = this.category
					? await axios.put(`${base}/${this.category.id}`, payload)
					: await axios.post(base, payload)
				this.$emit('saved', data)
				this.$emit('close')
			} catch (err) {
				console.error('[TeamHub][DecisionCategoryDialog] save failed:', err)
				// The server's sentence, not ours: it is the one that knows
				// which rule was broken.
				this.error = err?.response?.data?.error || t('teamhub', 'Could not save the category')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.th-cat-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.th-cat-dialog__locked {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.th-cat-dialog__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
}

.th-cat-dialog__icon-col {
	flex: 0 0 auto;
}

.th-cat-dialog__name-col {
	flex: 1 1 auto;
	min-width: 0;
}

.th-cat-dialog__label {
	display: block;
	margin-block-end: 4px;
	font-size: var(--th-font-meta);
	font-weight: var(--th-font-weight-semibold);
}

.th-cat-dialog__hint {
	margin: 0 0 4px;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

/* A raw <button> because it is a 44px swatch whose content is the chosen
   icon; NcButton would draw its own chrome around it. Focus ring kept. */
.th-cat-dialog__icon-btn {
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	color: var(--color-primary-element);
	cursor: pointer;
}

.th-cat-dialog__icon-btn:hover {
	border-color: var(--color-primary-element);
}

.th-cat-dialog__icon-btn:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.th-cat-dialog__icon-empty {
	color: var(--color-text-maxcontrast);
}

.th-cat-dialog__picker {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-element);
}

.th-cat-dialog__picker-scroll {
	max-height: 260px;
	overflow-y: auto;
}

.th-cat-dialog__icon-group-hdr {
	position: sticky;
	top: 0;
	padding: 4px 0;
	background: var(--color-background-dark);
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-cat-dialog__icon-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(36px, 1fr));
	gap: 4px;
	margin-block-end: 8px;
}

.th-cat-dialog__icon-cell {
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 1;
	background: transparent;
	border: 1px solid transparent;
	border-radius: var(--border-radius-small);
	color: var(--color-main-text);
	cursor: pointer;
}

.th-cat-dialog__icon-cell:hover {
	background: var(--color-background-hover);
	border-color: var(--color-border);
}

.th-cat-dialog__icon-cell:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.th-cat-dialog__icon-cell--selected {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.th-cat-dialog__icon-none {
	margin: 0;
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-cat-dialog__months {
	max-width: 120px;
}
</style>
