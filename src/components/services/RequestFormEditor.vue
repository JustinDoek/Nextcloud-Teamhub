<template>
	<li class="svc-builder__step svc-builder__step--requester">
		<!-- v4.20.54 — the first step of a service: what its request form asks
		     (`docs/service-builder.md` § 14.3). Folded to its header until it is
		     opened, like every other step. -->
		<AccountOutline :size="ICON_BODY" class="svc-builder__glyph" aria-hidden="true" />

		<div class="svc-builder__step-body">
			<div class="svc-builder__step-head">
				<NcButton
					variant="tertiary"
					class="svc-builder__step-toggle"
					:aria-expanded="open ? 'true' : 'false'"
					:title="open ? t('teamhub', 'Fold step') : t('teamhub', 'Unfold step')"
					@click="open = !open">
					<template #icon>
						<ChevronDown v-if="open" :size="ICON_BODY" />
						<ChevronRight v-else :size="ICON_BODY" />
					</template>
					{{ t('teamhub', 'Request form') }}
				</NcButton>
				<span class="svc-builder__step-summary">
					<span>{{ t('teamhub', 'The requester') }}</span>
					<span>{{ n('teamhub', '{n} field', '{n} fields', request.fields.length, { n: request.fields.length }) }}</span>
				</span>
			</div>

			<template v-if="open">
				<p class="svc-builder__hint">
					{{ t('teamhub', 'What somebody fills in to make a request. Without fields they only read the description and send the request.') }}
				</p>

				<FieldListEditor
					v-model="request.fields"
					:list-label="t('teamhub', 'Fields of the request form')"
					:limits="limits"
					:busy="busy" />

				<label v-if="titleOptions.length" class="svc-builder__field">
					<span>{{ t('teamhub', 'The request is named after') }}</span>
					<NcSelect
						:model-value="titleValue"
						label-outside
						input-id="service-builder-title-field"
						:options="titleOptions"
						:reduce="o => o.id"
						:disabled="busy"
						:placeholder="t('teamhub', 'The name of the service')"
						:aria-label-combobox="t('teamhub', 'The request is named after')"
						@update:model-value="request.titleField = $event || ''" />
				</label>

				<NcCheckboxRadioSwitch v-model="request.askTeam" type="switch" :disabled="busy">
					{{ t('teamhub', 'Ask which team the request is for') }}
				</NcCheckboxRadioSwitch>
				<p class="svc-builder__hint">
					{{ request.askTeam
						? t('teamhub', 'The requester may leave it empty. The members of the team they pick can follow the request.')
						: t('teamhub', 'Every request is personal: only the requester and this team can follow it.') }}
				</p>
			</template>
		</div>
	</li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FieldListEditor from './FieldListEditor.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { fieldLabel, titleCandidates } from '../../constants/requestForm.js'

export default {
	name: 'RequestFormEditor',

	components: {
		NcButton, NcCheckboxRadioSwitch, NcSelect, FieldListEditor,
		AccountOutline, ChevronDown, ChevronRight,
	},

	props: {
		/** `{ askTeam, titleField, fields }`, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	data() {
		return { ICON_BODY, open: false }
	},

	computed: {
		request() {
			return this.modelValue
		},

		/** The fields that may name a request and already have a name of their own. */
		titleOptions() {
			return titleCandidates(this.request.fields)
				.map(field => ({ id: field.key, label: fieldLabel(field) }))
				.filter(option => option.label)
		},

		titleValue() {
			return this.titleOptions.some(option => option.id === this.request.titleField) ? this.request.titleField : null
		},
	},

	methods: {
		t,
		n,
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
