<template>
	<div class="svc-builder__step-body">
		<!-- v4.20.58 — who approves a step, in which words, and what a
		     rejection does (`docs/service-builder.md` § 14.4). -->
		<label class="svc-builder__field">
			<span>{{ t('teamhub', 'Who approves') }}</span>
			<NcSelect
				v-model="approval.source"
				label-outside
				:input-id="id('source')"
				:options="sources"
				:reduce="o => o.id"
				:clearable="false"
				:disabled="busy"
				:aria-label-combobox="t('teamhub', 'Who approves step {n}', { n: index + 1 })" />
		</label>

		<label v-if="approval.source === APPROVER_SOURCE.USER" class="svc-builder__field">
			<span>{{ t('teamhub', 'Person') }}</span>
			<PersonSelect
				v-model="approval.user"
				:input-id="id('user')"
				:label="t('teamhub', 'The person who approves step {n}', { n: index + 1 })"
				:disabled="busy" />
		</label>
		<label v-else-if="approval.source === APPROVER_SOURCE.GROUP" class="svc-builder__field">
			<span>{{ t('teamhub', 'Group') }}</span>
			<PersonSelect
				v-model="approval.group"
				type="group"
				:input-id="id('group')"
				:label="t('teamhub', 'The group that approves step {n}', { n: index + 1 })"
				:placeholder="t('teamhub', 'Search for a group')"
				:disabled="busy" />
			<span class="svc-builder__hint">{{ t('teamhub', 'Everybody in the group can answer. The first answer decides.') }}</span>
		</label>
		<template v-else-if="approval.source === APPROVER_SOURCE.FIELD">
			<label v-if="fields.length" class="svc-builder__field">
				<span>{{ t('teamhub', 'Field of the request form') }}</span>
				<NcSelect
					v-model="approval.field"
					label-outside
					:input-id="id('field')"
					:options="fields"
					:reduce="o => o.id"
					:clearable="false"
					:disabled="busy"
					:aria-label-combobox="t('teamhub', 'The field that names who approves step {n}', { n: index + 1 })" />
			</label>
			<p v-else class="svc-builder__hint">
				{{ t('teamhub', 'Add a field of the type Person to the request form first.') }}
			</p>
		</template>
		<p v-else class="svc-builder__hint">
			{{ t('teamhub', 'The manager in the requester\'s account, as an administrator set it.') }}
		</p>

		<label class="svc-builder__field">
			<span>{{ needsFallback ? t('teamhub', 'Who approves otherwise') : t('teamhub', 'Who approves otherwise (optional)') }}</span>
			<PersonSelect
				v-model="approval.fallback"
				:input-id="id('fallback')"
				:label="t('teamhub', 'Who approves step {n} otherwise', { n: index + 1 })"
				:disabled="busy" />
			<span class="svc-builder__hint">{{ t('teamhub', 'Used when there is nobody else, or when the approver made the request. Nobody approves their own request.') }}</span>
		</label>

		<fieldset class="svc-builder__fieldset">
			<legend class="svc-builder__legend">{{ t('teamhub', 'When the approver rejects') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in outcomes"
				:key="option.id"
				v-model="approval.onReject"
				:value="option.id"
				:name="id('reject')"
				type="radio"
				:disabled="busy">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<div class="svc-builder__row">
			<label class="svc-builder__field svc-builder__field--grow">
				<span>{{ t('teamhub', 'Text of the approve button (optional)') }}</span>
				<NcTextField
					v-model="approval.approveLabel"
					label-outside
					:maxlength="limits.verb || 40"
					:placeholder="t('teamhub', 'Approve')"
					:aria-label="t('teamhub', 'Text of the approve button of step {n} (optional)', { n: index + 1 })"
					:disabled="busy" />
			</label>
			<label class="svc-builder__field svc-builder__field--grow">
				<span>{{ t('teamhub', 'Text of the reject button (optional)') }}</span>
				<NcTextField
					v-model="approval.rejectLabel"
					label-outside
					:maxlength="limits.verb || 40"
					:placeholder="t('teamhub', 'Reject')"
					:aria-label="t('teamhub', 'Text of the reject button of step {n} (optional)', { n: index + 1 })"
					:disabled="busy" />
			</label>
		</div>

		<p class="svc-builder__hint">
			{{ t('teamhub', 'The approver sees the request, its files and the steps so far from the moment it is their turn. They do not see the messages or your internal notes.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import PersonSelect from './PersonSelect.vue'
import { APPROVER_SOURCE, ON_REJECT, approverNeedsFallback, approverSourceLabel } from '../../constants/approvals.js'

export default {
	name: 'ApproverEditor',

	components: { NcCheckboxRadioSwitch, NcSelect, NcTextField, PersonSelect },

	props: {
		/** The approval, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		index: { type: Number, required: true },
		/** The step's own key, for ids that stay put when steps move. */
		stepKey: { type: Number, required: true },
		/** `[{ id, label }]` — the request form's fields of the type person. */
		fields: { type: Array, default: () => [] },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	data() {
		return { APPROVER_SOURCE }
	},

	computed: {
		approval() {
			return this.modelValue
		},

		needsFallback() {
			return approverNeedsFallback(this.approval.source)
		},

		sources() {
			return Object.values(APPROVER_SOURCE).map(id => ({ id, label: approverSourceLabel(id) }))
		},

		outcomes() {
			return [
				{ id: ON_REJECT.END, label: t('teamhub', 'The request ends') },
				{ id: ON_REJECT.CONTINUE, label: t('teamhub', 'The request goes on, and the team decides what follows') },
			]
		},
	},

	methods: {
		t,

		id(name) {
			return 'service-builder-approval-' + this.stepKey + '-' + name
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
