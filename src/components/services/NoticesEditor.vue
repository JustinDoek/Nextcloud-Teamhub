<template>
	<div class="svc-builder__links">
		<!-- v4.20.62 — whom the service tells when the step is reached, done or
		     rejected (`docs/service-builder.md` § 14.5). Folded while the step
		     has none: most steps need no more than the app's own bell. -->
		<span :id="id('title')">{{ t('teamhub', 'Notices') }}</span>
		<span class="svc-builder__hint">
			{{ t('teamhub', 'Tell somebody about this step, in your own words. Whoever has to act is told already.') }}
		</span>

		<ul v-if="notices.length" class="svc-builder__task-list" :aria-labelledby="id('title')">
			<li v-for="(notice, i) in notices" :key="notice.key" class="svc-builder__task">
				<!-- v4.20.64 — a header of its own, and the two choices full width:
				     side by side they cut *When the step is reached* off. -->
				<div class="svc-builder__notice-head">
					<span class="svc-builder__fixed-label">{{ t('teamhub', 'Notice {n}', { n: i + 1 }) }}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('teamhub', 'Remove notice {n} of step {step}', { n: i + 1, step: index + 1 })"
						:title="t('teamhub', 'Remove notice')"
						@click="notices.splice(i, 1)">
						<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
					</NcButton>
				</div>
				<div class="svc-builder__row">
					<label class="svc-builder__field svc-builder__field--grow svc-builder__field--wide">
						<span>{{ t('teamhub', 'When') }}</span>
						<NcSelect
							v-model="notice.on"
							label-outside
							:input-id="id('on', notice)"
							:options="moments"
							:reduce="o => o.id"
							:clearable="false"
							:disabled="busy"
							:aria-label-combobox="t('teamhub', 'When notice {n} of step {step} is sent', { n: i + 1, step: index + 1 })" />
					</label>
					<label class="svc-builder__field svc-builder__field--grow svc-builder__field--wide">
						<span>{{ t('teamhub', 'Who gets it') }}</span>
						<NcSelect
							v-model="notice.to"
							label-outside
							:input-id="id('to', notice)"
							:options="recipients"
							:reduce="o => o.id"
							:clearable="false"
							:disabled="busy"
							:aria-label-combobox="t('teamhub', 'Who gets notice {n} of step {step}', { n: i + 1, step: index + 1 })" />
					</label>
				</div>

				<label v-if="notice.to === NOTICE_TO.USER" class="svc-builder__field">
					<span>{{ t('teamhub', 'Person') }}</span>
					<PersonSelect
						v-model="notice.user"
						:input-id="id('user', notice)"
						:label="t('teamhub', 'The person who gets notice {n} of step {step}', { n: i + 1, step: index + 1 })"
						:disabled="busy" />
				</label>
				<label v-else-if="notice.to === NOTICE_TO.GROUP" class="svc-builder__field">
					<span>{{ t('teamhub', 'Group') }}</span>
					<PersonSelect
						v-model="notice.group"
						type="group"
						:input-id="id('group', notice)"
						:label="t('teamhub', 'The group that gets notice {n} of step {step}', { n: i + 1, step: index + 1 })"
						:placeholder="t('teamhub', 'Search for a group')"
						:disabled="busy" />
					<span class="svc-builder__hint">{{ t('teamhub', 'A group of more than 100 people is not told.') }}</span>
				</label>

				<label class="svc-builder__field">
					<span>{{ t('teamhub', 'Subject') }}</span>
					<NcTextField
						v-model="notice.subject"
						label-outside
						:maxlength="limits.noticeSubject || 100"
						:aria-label="t('teamhub', 'Subject of notice {n} of step {step}', { n: i + 1, step: index + 1 })"
						:disabled="busy" />
				</label>
				<label class="svc-builder__field">
					<span>{{ t('teamhub', 'Text (optional)') }}</span>
					<NcTextArea
						v-model="notice.text"
						rows="3"
						label-outside
						:maxlength="limits.noticeText || 1000"
						:aria-label="t('teamhub', 'Text of notice {n} of step {step} (optional)', { n: i + 1, step: index + 1 })"
						:disabled="busy" />
				</label>

				<fieldset class="svc-builder__fieldset">
					<legend class="svc-builder__legend">{{ t('teamhub', 'How it is sent') }}</legend>
					<NcCheckboxRadioSwitch v-model="notice.bell" type="checkbox" :disabled="busy">
						{{ t('teamhub', 'As a notification in Nextcloud') }}
					</NcCheckboxRadioSwitch>
					<!-- Hidden where services may not send email, unless this
					     notice was made when they might. -->
					<NcCheckboxRadioSwitch v-if="mayEmail || notice.email" v-model="notice.email" type="checkbox" :disabled="busy">
						{{ t('teamhub', 'By email, to the address in the account') }}
					</NcCheckboxRadioSwitch>
					<span v-if="notice.email && !mayEmail" class="svc-builder__hint">
						{{ t('teamhub', 'Services cannot send email on this server.') }}
					</span>
				</fieldset>

				<fieldset v-if="notice.email && mayEmail" class="svc-builder__fieldset">
					<legend class="svc-builder__legend">{{ t('teamhub', 'What the email also says') }}</legend>
					<NcCheckboxRadioSwitch v-model="notice.requester" type="checkbox" :disabled="busy">
						{{ t('teamhub', 'The name of the requester') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-for="field in fields"
						:key="field.id"
						:model-value="notice.fields.includes(field.id)"
						type="checkbox"
						:disabled="busy"
						@update:model-value="tick(notice, field.id, $event)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
					<span class="svc-builder__hint">
						{{ t('teamhub', 'An email leaves Nextcloud. Whoever can read it reads these answers, whatever they may open here. The notification shows the subject and the text only.') }}
					</span>
				</fieldset>
			</li>
		</ul>

		<div>
			<NcButton variant="secondary" size="small" :disabled="busy || notices.length >= max" @click="notices.push(newNotice())">
				<template #icon><Plus :size="ICON_BODY" /></template>
				{{ t('teamhub', 'Add notice') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PersonSelect from './PersonSelect.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { NOTICE_ON, NOTICE_TO, newNotice, noticeOnLabel, noticeToLabel } from '../../constants/notices.js'

export default {
	name: 'NoticesEditor',

	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField, PersonSelect, Plus, TrashCanOutline },

	props: {
		/** The step's notices, edited in place: they belong to the builder's form. */
		modelValue: { type: Array, required: true },
		index: { type: Number, required: true },
		/** The step's own key, for ids that stay put when steps move. */
		stepKey: { type: Number, required: true },
		/** An approval is answered by its approver, not by whoever has a task. */
		approval: { type: Boolean, default: false },
		/** `[{ id, label }]` — the request form's fields. */
		fields: { type: Array, default: () => [] },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	data() {
		return { ICON_BODY, NOTICE_TO }
	},

	computed: {
		notices() {
			return this.modelValue
		},

		max() {
			return this.limits.notices || 5
		},

		mayEmail() {
			return this.limits.noticeEmail === true
		},

		moments() {
			return Object.values(NOTICE_ON).map(id => ({ id, label: noticeOnLabel(id) }))
		},

		recipients() {
			return Object.values(NOTICE_TO).map(id => ({ id, label: noticeToLabel(id, this.approval) }))
		},
	},

	methods: {
		t,
		newNotice,

		id(name, notice = null) {
			return 'service-builder-notice-' + this.stepKey + '-' + (notice ? notice.key + '-' : '') + name
		},

		tick(notice, key, on) {
			notice.fields = on ? [...notice.fields, key] : notice.fields.filter(k => k !== key)
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
