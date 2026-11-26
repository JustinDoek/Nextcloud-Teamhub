<template>
	<li class="svc-builder__step svc-builder__step--requester">
		<!-- v4.20.54 — the last step of a service: who confirms the result
		     (`docs/service-builder.md` § 14.3). -->
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
					{{ t('teamhub', 'Confirmation') }}
				</NcButton>
				<span class="svc-builder__step-summary">{{ summary }}</span>
			</div>

			<template v-if="open">
				<fieldset class="svc-builder__fieldset">
					<legend class="svc-builder__legend">{{ t('teamhub', 'When the last step is done') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="option in options"
						:key="option.id"
						v-model="confirm.mode"
						:value="option.id"
						name="service-builder-confirm"
						type="radio"
						:disabled="busy">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<label v-if="confirm.mode === CONFIRM_MODE.AUTO" class="svc-builder__field">
					<span>{{ t('teamhub', 'Confirm automatically after (days)') }}</span>
					<NcTextField
						v-model="confirm.days"
						type="number"
						min="1"
						:max="maxDays"
						step="1"
						label-outside
						:error="!daysOk"
						:helper-text="daysOk ? '' : t('teamhub', 'Enter a whole number of days from 1 to {max}.', { max: maxDays })"
						:aria-label="t('teamhub', 'Confirm automatically after (days)')"
						:disabled="busy" />
				</label>

				<p v-if="confirm.mode !== CONFIRM_MODE.NONE" class="svc-builder__hint">
					{{ t('teamhub', 'An admin of the team can close the request instead.') }}
				</p>
			</template>
		</div>
	</li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { CONFIRM_MODE, confirmDaysValid } from '../../constants/requestForm.js'

export default {
	name: 'ConfirmEditor',

	components: { NcButton, NcCheckboxRadioSwitch, NcTextField, AccountOutline, ChevronDown, ChevronRight },

	props: {
		/** `{ mode, days }`, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	data() {
		return { CONFIRM_MODE, ICON_BODY, open: false }
	},

	computed: {
		confirm() {
			return this.modelValue
		},

		maxDays() {
			return this.limits.confirmDays || 90
		},

		daysOk() {
			return confirmDaysValid(this.confirm, this.maxDays)
		},

		options() {
			return [
				{ id: CONFIRM_MODE.REQUESTER, label: t('teamhub', 'The requester confirms the result') },
				{ id: CONFIRM_MODE.AUTO, label: t('teamhub', 'The requester confirms, or it is confirmed automatically') },
				{ id: CONFIRM_MODE.NONE, label: t('teamhub', 'The request ends without a confirmation') },
			]
		},

		summary() {
			switch (this.confirm.mode) {
			case CONFIRM_MODE.AUTO: {
				if (!this.daysOk) {
					return t('teamhub', 'Automatic')
				}
				const days = Number(this.confirm.days)
				return n('teamhub', 'Automatic after {n} day', 'Automatic after {n} days', days, { n: days })
			}
			case CONFIRM_MODE.NONE:
				// TRANSLATORS: a service whose requests end without the requester confirming the result
				return t('teamhub', 'No confirmation')
			default:
				return t('teamhub', 'The requester')
			}
		},
	},

	methods: { t, n },
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
