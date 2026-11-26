<template>
	<div class="svc-builder__links">
		<!-- v4.20.71 — a list of fields to fill in: the request form's, and
		     since phase 8j a task's own (`docs/service-builder.md` § 14.6).
		     Out of RequestFormEditor, unchanged. -->
		<ol v-if="fields.length" class="svc-builder__form-fields" :aria-label="listLabel">
			<li
				v-for="(field, i) in fields"
				:key="field.key"
				class="svc-builder__form-field"
				:data-field-key="field.key">
				<div class="svc-builder__row">
					<label class="svc-builder__field svc-builder__field--grow">
						<span>{{ t('teamhub', 'Field name') }}</span>
						<NcTextField
							v-model="field.label"
							label-outside
							class="svc-builder__field-name"
							:maxlength="limits.fieldLabel || 100"
							:placeholder="standardLabel(field.key)"
							:aria-label="t('teamhub', 'Name of field {n}', { n: i + 1 })"
							:disabled="busy" />
					</label>
					<label class="svc-builder__field svc-builder__field--grow">
						<span>{{ t('teamhub', 'Type') }}</span>
						<NcSelect
							v-model="field.type"
							label-outside
							:input-id="idPrefix + '-field-type-' + field.key"
							:options="typeOptions"
							:reduce="o => o.id"
							:clearable="false"
							:disabled="busy"
							:aria-label-combobox="t('teamhub', 'Type of field {n}', { n: i + 1 })" />
					</label>
				</div>

				<label v-if="hasOptions(field.type)" class="svc-builder__field">
					<span>{{ t('teamhub', 'Options, one per line') }}</span>
					<NcTextArea
						v-model="field.optionsText"
						rows="4"
						label-outside
						:error="!optionsOk(field)"
						:helper-text="optionsOk(field) ? '' : t('teamhub', 'Enter at most {max} options of at most 100 characters.', { max: maxOptions })"
						:aria-label="t('teamhub', 'Options of field {n}, one per line', { n: i + 1 })"
						:disabled="busy" />
				</label>

				<label class="svc-builder__field">
					<span>{{ t('teamhub', 'Explanation (optional)') }}</span>
					<NcTextField
						v-model="field.help"
						label-outside
						:maxlength="limits.fieldHelp || 255"
						:aria-label="t('teamhub', 'Explanation of field {n} (optional)', { n: i + 1 })"
						:disabled="busy" />
				</label>

				<NcCheckboxRadioSwitch v-model="field.required" type="checkbox" :disabled="busy">
					{{ t('teamhub', 'Must be filled in') }}
				</NcCheckboxRadioSwitch>

				<div class="svc-builder__form-tools">
					<NcButton
						variant="tertiary"
						class="svc-builder__move-up"
						:disabled="busy || i === 0"
						:aria-label="t('teamhub', 'Move field {n} up', { n: i + 1 })"
						:title="t('teamhub', 'Move field {n} up', { n: i + 1 })"
						@click="move(i, -1)">
						<template #icon><ArrowUp :size="ICON_BODY" /></template>
					</NcButton>
					<NcButton
						variant="tertiary"
						class="svc-builder__move-down"
						:disabled="busy || i === fields.length - 1"
						:aria-label="t('teamhub', 'Move field {n} down', { n: i + 1 })"
						:title="t('teamhub', 'Move field {n} down', { n: i + 1 })"
						@click="move(i, 1)">
						<template #icon><ArrowDown :size="ICON_BODY" /></template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('teamhub', 'Remove field {n}', { n: i + 1 })"
						:title="t('teamhub', 'Remove field')"
						@click="fields.splice(i, 1)">
						<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
					</NcButton>
				</div>
			</li>
		</ol>

		<div class="svc-builder__add">
			<NcButton
				variant="secondary"
				size="small"
				class="svc-builder__add-field"
				:disabled="busy || fields.length >= maxFields"
				@click="add">
				<template #icon><Plus :size="ICON_BODY" /></template>
				{{ t('teamhub', 'Add field') }}
			</NcButton>
			<span v-if="fields.length >= maxFields" class="svc-builder__hint">
				{{ n('teamhub', 'A request form has at most %n field.', 'A request form has at most %n fields.', maxFields) }}
			</span>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { moveStep } from '../../constants/serviceBuilder.js'
import { FIELD_TYPES, editableField, fieldTypeLabel, hasOptions, optionsValid, standardLabel } from '../../constants/requestForm.js'

export default {
	name: 'FieldListEditor',

	components: {
		NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField,
		ArrowDown, ArrowUp, Plus, TrashCanOutline,
	},

	props: {
		/** The editable fields (`editableField()`), edited in place. */
		modelValue: { type: Array, required: true },
		/** The accessible name of the list. */
		listLabel: { type: String, required: true },
		/** Keeps ids apart where several lists are on one page. */
		idPrefix: { type: String, default: 'service-builder' },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return { ICON_BODY }
	},

	computed: {
		fields() {
			return this.modelValue
		},

		maxFields() {
			return this.limits.fields || 20
		},

		maxOptions() {
			return this.limits.options || 20
		},

		typeOptions() {
			return FIELD_TYPES.map(type => ({ id: type, label: fieldTypeLabel(type) }))
		},
	},

	methods: {
		t,
		n,
		hasOptions,
		standardLabel,

		optionsOk(field) {
			return optionsValid(field, this.maxOptions)
		},

		add() {
			const field = editableField()
			this.fields.push(field)
			this.focusIn(field.key, '.svc-builder__field-name input')
		},

		/** Focus stays on the pressed button, or on its twin once it is at the end of the list. */
		move(index, delta) {
			const key = this.fields[index]?.key
			this.$emit('update:modelValue', moveStep(this.fields, index, delta))
			const pressed = delta < 0 ? '.svc-builder__move-up' : '.svc-builder__move-down'
			const twin = delta < 0 ? '.svc-builder__move-down' : '.svc-builder__move-up'
			this.focusIn(key, pressed + ':not(:disabled)', twin)
		},

		focusIn(key, selector, fallback = '') {
			this.$nextTick(() => {
				const row = this.$el?.querySelector?.(`[data-field-key="${key}"]`)
				const target = row?.querySelector(selector) || (fallback ? row?.querySelector(fallback) : null)
				target?.focus()
				target?.scrollIntoView({ block: 'nearest' })
			})
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
