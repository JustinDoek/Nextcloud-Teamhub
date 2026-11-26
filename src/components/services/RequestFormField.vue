<template>
	<div class="svc-field">
		<!-- v4.20.54 — one field of a service's own request form
		     (`docs/service-builder.md` § 14.3). The server checks every answer
		     again; this draws the control its type asks for. -->
		<template v-if="field.type === FIELD_TYPE.MULTI || field.type === FIELD_TYPE.YESNO">
			<fieldset class="svc-field__set" :aria-describedby="field.help ? helpId : undefined">
				<legend class="svc-field__label">{{ label }}</legend>
				<span v-if="field.help" :id="helpId" class="svc-field__help">{{ field.help }}</span>
				<template v-if="field.type === FIELD_TYPE.MULTI">
					<NcCheckboxRadioSwitch
						v-for="option in field.options"
						:key="option"
						:model-value="picked.includes(option)"
						type="checkbox"
						:disabled="disabled"
						@update:model-value="toggle(option, $event)">
						{{ option }}
					</NcCheckboxRadioSwitch>
				</template>
				<template v-else>
					<NcCheckboxRadioSwitch
						v-for="option in yesNo"
						:key="option.id"
						:model-value="yesNoValue"
						:value="option.id"
						:name="inputId"
						type="radio"
						:disabled="disabled"
						@update:model-value="update($event === 'yes')">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</template>
			</fieldset>
		</template>

		<template v-else>
			<label class="svc-field__label" :for="inputId">{{ label }}</label>
			<span v-if="field.help" :id="helpId" class="svc-field__help">{{ field.help }}</span>

			<NcTextArea
				v-if="field.type === FIELD_TYPE.LONG"
				:id="inputId"
				:model-value="text"
				rows="4"
				:maxlength="MAX_LONG"
				label-outside
				:aria-describedby="field.help ? helpId : undefined"
				:disabled="disabled"
				@update:model-value="update" />

			<NcSelect
				v-else-if="field.type === FIELD_TYPE.CHOICE"
				:model-value="modelValue || null"
				:input-id="inputId"
				label-outside
				:options="field.options"
				:clearable="!field.required"
				:disabled="disabled"
				:placeholder="t('teamhub', 'Choose an option')"
				:aria-label-combobox="described"
				@update:model-value="update" />

			<PersonSelect
				v-else-if="field.type === FIELD_TYPE.PERSON"
				:model-value="modelValue"
				:input-id="inputId"
				:label="described"
				:disabled="disabled"
				@update:model-value="update" />

			<!-- Native, as the design guide says for a date (/ui-standards). -->
			<input
				v-else-if="field.type === FIELD_TYPE.DATE"
				:id="inputId"
				type="date"
				class="svc-field__date"
				:value="text"
				:aria-describedby="field.help ? helpId : undefined"
				:disabled="disabled"
				@input="update($event.target.value)">

			<NcTextField
				v-else
				:id="inputId"
				:model-value="text"
				:type="field.type === FIELD_TYPE.NUMBER ? 'number' : 'text'"
				:step="field.type === FIELD_TYPE.NUMBER ? 'any' : undefined"
				:maxlength="MAX_SHORT"
				label-outside
				:aria-describedby="field.help ? helpId : undefined"
				:disabled="disabled"
				@update:model-value="update" />
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import PersonSelect from './PersonSelect.vue'
import { FIELD_TYPE, fieldLabel } from '../../constants/requestForm.js'

export default {
	name: 'RequestFormField',

	components: { NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField, PersonSelect },

	props: {
		/** `{ key, type, label, help, required, options }`. */
		field: { type: Object, required: true },
		/** What was filled in: text, `true`/`false`, a list of options, or `{ id, name }` for a person. */
		modelValue: { type: [String, Number, Boolean, Array, Object], default: null },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			FIELD_TYPE,
			/** The server's own limits (`RequestForm::MAX_SHORT`, `MAX_LONG`). */
			MAX_SHORT: 255,
			MAX_LONG: 4000,
		}
	},

	computed: {
		label() {
			const name = fieldLabel(this.field)
			return this.field.required
				? name
				// TRANSLATORS: {field} is the name of a field on a request form that may stay empty
				: t('teamhub', '{field} (optional)', { field: name })
		},

		/** NcSelect takes no `aria-describedby`, so its name carries the explanation. */
		described() {
			return [this.label, this.field.help].filter(Boolean).join('. ')
		},

		inputId() {
			return 'svc-field-' + this.field.key
		},

		helpId() {
			return this.inputId + '-help'
		},

		text() {
			return this.modelValue === null || this.modelValue === undefined ? '' : String(this.modelValue)
		},

		picked() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		yesNo() {
			return [
				{ id: 'yes', label: t('teamhub', 'Yes') },
				{ id: 'no', label: t('teamhub', 'No') },
			]
		},

		yesNoValue() {
			if (typeof this.modelValue !== 'boolean') {
				return ''
			}
			return this.modelValue ? 'yes' : 'no'
		},
	},

	methods: {
		t,

		update(value) {
			this.$emit('update:modelValue', value)
		},

		toggle(option, on) {
			this.update(on ? [...this.picked, option] : this.picked.filter(one => one !== option))
		},
	},
}
</script>

<style scoped lang="scss">
.svc-field,
.svc-field__set {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

.svc-field__set {
	margin: 0;
	padding: 0;
	border: 0;
}

.svc-field__label {
	padding: 0;
}

.svc-field__help {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-field__date {
	max-width: 100%;
}
</style>
