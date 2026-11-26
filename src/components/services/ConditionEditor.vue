<template>
	<div class="svc-builder__links">
		<!-- v4.20.66 — a step that runs only on some requests
		     (`docs/service-builder.md` § 14.6): one answer of the request
		     form decides. Off, the step runs on every request. -->
		<NcCheckboxRadioSwitch
			v-model="condition.on"
			type="switch"
			:disabled="busy || !fields.length">
			{{ t('teamhub', 'Only on some requests') }}
		</NcCheckboxRadioSwitch>
		<span v-if="!fields.length" class="svc-builder__hint">
			{{ t('teamhub', 'A condition uses an answer of the request form. This form has no fields.') }}
		</span>

		<template v-if="condition.on && fields.length">
			<div class="svc-builder__row">
				<label class="svc-builder__field svc-builder__field--grow svc-builder__field--wide">
					<span>{{ t('teamhub', 'When the answer to') }}</span>
					<NcSelect
						v-model="condition.field"
						label-outside
						:input-id="id('field')"
						:options="fieldOptions"
						:reduce="o => o.id"
						:clearable="false"
						:disabled="busy"
						:placeholder="t('teamhub', 'Pick a field')"
						:aria-label-combobox="t('teamhub', 'The field the condition of step {n} looks at', { n: index + 1 })" />
				</label>
				<label class="svc-builder__field svc-builder__field--grow svc-builder__field--wide">
					<span>{{ t('teamhub', 'Comparison') }}</span>
					<NcSelect
						v-model="condition.op"
						label-outside
						:input-id="id('op')"
						:options="opOptions"
						:reduce="o => o.id"
						:clearable="false"
						:disabled="busy || !field"
						:aria-label-combobox="t('teamhub', 'The comparison of the condition of step {n}', { n: index + 1 })" />
				</label>
			</div>

			<label v-if="field && condition.op !== CONDITION_OP.FILLED" class="svc-builder__field">
				<span>{{ t('teamhub', 'Value') }}</span>
				<NcSelect
					v-if="choices.length"
					v-model="condition.value"
					label-outside
					:input-id="id('value')"
					:options="choices"
					:reduce="o => o.id"
					:clearable="false"
					:disabled="busy"
					:aria-label-combobox="t('teamhub', 'The value of the condition of step {n}', { n: index + 1 })" />
				<!-- A native date picker: NC's recommended one (/ui-standards). -->
				<input
					v-else-if="field.type === FIELD_TYPE.DATE"
					:id="id('value')"
					v-model="condition.value"
					type="date"
					class="svc-builder__date"
					:aria-label="t('teamhub', 'The value of the condition of step {n}', { n: index + 1 })"
					:disabled="busy">
				<NcTextField
					v-else
					v-model="condition.value"
					label-outside
					:type="field.type === FIELD_TYPE.NUMBER ? 'number' : 'text'"
					:step="field.type === FIELD_TYPE.NUMBER ? 'any' : undefined"
					:maxlength="255"
					:aria-label="t('teamhub', 'The value of the condition of step {n}', { n: index + 1 })"
					:disabled="busy" />
			</label>

			<span class="svc-builder__hint">
				{{ t('teamhub', 'Otherwise the step is left out and the request goes on to the next one. A field nobody answered never matches.') }}
			</span>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { CONDITION_OP, opLabel, opsFor } from '../../constants/conditions.js'
import { FIELD_TYPE } from '../../constants/requestForm.js'

export default {
	name: 'ConditionEditor',

	components: { NcCheckboxRadioSwitch, NcSelect, NcTextField },

	props: {
		/** The step's condition, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		index: { type: Number, required: true },
		/** The step's own key, for ids that stay put when steps move. */
		stepKey: { type: Number, required: true },
		/** `[{ id, label, type, options }]` — the request form's fields. */
		fields: { type: Array, default: () => [] },
		busy: { type: Boolean, default: false },
	},

	data() {
		return { CONDITION_OP, FIELD_TYPE }
	},

	computed: {
		condition() {
			return this.modelValue
		},

		field() {
			return this.fields.find(f => f.id === this.condition.field) || null
		},

		fieldOptions() {
			return this.fields.map(f => ({ id: f.id, label: f.label }))
		},

		opOptions() {
			return opsFor(this.field?.type).map(id => ({ id, label: opLabel(id, this.field?.type) }))
		},

		/** A value picked from a list: the field's options, or yes and no. */
		choices() {
			if (this.field?.type === FIELD_TYPE.YESNO) {
				return [{ id: true, label: t('teamhub', 'Yes') }, { id: false, label: t('teamhub', 'No') }]
			}
			if ([FIELD_TYPE.CHOICE, FIELD_TYPE.MULTI].includes(this.field?.type)) {
				return (this.field.options || []).map(o => ({ id: o, label: o }))
			}
			return []
		},
	},

	watch: {
		/** Another field: a comparison it can answer, and no value meant for the last one. */
		'condition.field'(now, before) {
			if (before === undefined || now === before) {
				return
			}
			const ops = opsFor(this.field?.type)
			if (!ops.includes(this.condition.op)) {
				this.condition.op = ops[0]
			}
			this.condition.value = this.field?.type === FIELD_TYPE.YESNO ? true : ''
		},
	},

	methods: {
		t,

		id(name) {
			return 'service-builder-condition-' + this.stepKey + '-' + name
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
