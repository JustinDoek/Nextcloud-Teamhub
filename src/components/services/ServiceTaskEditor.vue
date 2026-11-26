<template>
	<li class="svc-builder__task">
		<!-- One task of a step in the service builder: its name, the role it
		     needs, whether the request may move on without it, and its links.
		     v4.20.54 — out of ServiceBuilderDialog, unchanged. -->
		<div class="svc-builder__row">
			<label class="svc-builder__field svc-builder__field--grow">
				<span>{{ count > 1 ? t('teamhub', 'Task name') : t('teamhub', 'Task name (optional)') }}</span>
				<NcTextField
					v-model="task.label"
					label-outside
					:maxlength="limits.stepLabel || 100"
					:placeholder="count > 1 ? '' : t('teamhub', 'The step\'s name')"
					:aria-label="t('teamhub', 'Name of task {t} of step {n}', { t: index + 1, n: stepIndex + 1 })"
					:disabled="busy" />
			</label>
			<label v-if="kind === STEP_KIND.DESK" class="svc-builder__field svc-builder__field--grow">
				<span>{{ t('teamhub', 'Role (optional)') }}</span>
				<NcTextField
					v-model="task.role"
					label-outside
					:maxlength="limits.role || 64"
					:placeholder="t('teamhub', 'For example: Privacy officer')"
					:aria-label="t('teamhub', 'Role for task {t} of step {n}', { t: index + 1, n: stepIndex + 1 })"
					:disabled="busy" />
			</label>
			<NcButton
				v-if="count > 1"
				variant="tertiary"
				class="svc-builder__link-remove"
				:disabled="busy"
				:aria-label="t('teamhub', 'Remove task {t} of step {n}', { t: index + 1, n: stepIndex + 1 })"
				:title="t('teamhub', 'Remove task')"
				@click="$emit('remove')">
				<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
			</NcButton>
		</div>
		<NcCheckboxRadioSwitch
			v-if="kind === STEP_KIND.DESK && count > 1"
			v-model="task.nonBlocking"
			type="checkbox"
			:disabled="busy">
			{{ t('teamhub', 'The request may move on while this task is open') }}
		</NcCheckboxRadioSwitch>

		<!-- v4.20.71 — fields to fill in when the task is done (§ 14.6): on a
		     requester task they are what the requester sends, on a team task
		     what the team records, such as an inventory number. -->
		<div class="svc-builder__links">
			<span class="svc-builder__hint">
				{{ kind === STEP_KIND.DESK
					? t('teamhub', 'Fields whoever does this task fills in to complete it. Only the team sees the answers.')
					: t('teamhub', 'Fields the requester fills in to complete this task. Everybody on the request sees the answers.') }}
			</span>
			<FieldListEditor
				v-model="task.fields"
				:list-label="t('teamhub', 'Fields of task {t} of step {n}', { t: index + 1, n: stepIndex + 1 })"
				:id-prefix="'service-builder-task-' + task.key"
				:limits="limits"
				:busy="busy" />
		</div>

		<!-- v4.10.36 — links on the task (§ 6.1). -->
		<div class="svc-builder__links">
			<span class="svc-builder__hint">
				{{ kind === STEP_KIND.DESK
					? t('teamhub', 'Links for the team, such as work instructions or the system to go to. Only the team sees them.')
					: t('teamhub', 'Links the requester opens for this task, such as a form to fill in. Opening one marks the task done.') }}
			</span>
			<ul v-if="task.links.length" class="svc-builder__link-list">
				<li v-for="(link, li) in task.links" :key="link.key" class="svc-builder__link">
					<div class="svc-builder__kind" role="group" :aria-label="t('teamhub', 'What link {n} of step {step} opens', { n: li + 1, step: stepIndex + 1 })">
						<NcButton
							size="small"
							variant="tertiary"
							:pressed="link.kind === LINK_KIND.LINK"
							:disabled="busy"
							@click="link.kind = LINK_KIND.LINK">
							{{ t('teamhub', 'Page') }}
						</NcButton>
						<NcButton
							size="small"
							variant="tertiary"
							:pressed="link.kind === LINK_KIND.FORM"
							:disabled="busy"
							@click="link.kind = LINK_KIND.FORM">
							{{ t('teamhub', 'Form') }}
						</NcButton>
					</div>
					<div class="svc-builder__row">
						<label class="svc-builder__field svc-builder__field--grow">
							<span>{{ t('teamhub', 'Link name') }}</span>
							<NcTextField
								v-model="link.label"
								label-outside
								:maxlength="limits.linkLabel || 100"
								:placeholder="t('teamhub', 'For example: Intake form')"
								:aria-label="t('teamhub', 'Name of link {n} of step {step}', { n: li + 1, step: stepIndex + 1 })"
								:disabled="busy" />
						</label>
						<label class="svc-builder__field svc-builder__field--grow">
							<span>{{ t('teamhub', 'Address') }}</span>
							<NcTextField
								v-model="link.url"
								type="url"
								label-outside
								:maxlength="limits.url || 2000"
								placeholder="https://"
								:error="!urlOk(link.url)"
								:helper-text="urlOk(link.url) ? '' : t('teamhub', 'An address starts with https:// and has no spaces.')"
								:aria-label="t('teamhub', 'Address of link {n} of step {step}', { n: li + 1, step: stepIndex + 1 })"
								:disabled="busy" />
						</label>
						<NcButton
							variant="tertiary"
							class="svc-builder__link-remove"
							:disabled="busy"
							:aria-label="t('teamhub', 'Remove link {n} of step {step}', { n: li + 1, step: stepIndex + 1 })"
							:title="t('teamhub', 'Remove link')"
							@click="task.links.splice(li, 1)">
							<template #icon><Close :size="ICON_BODY" /></template>
						</NcButton>
					</div>
				</li>
			</ul>
			<div>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="busy || task.links.length >= maxLinks"
					@click="task.links.push(newLink())">
					<template #icon><LinkVariantPlus :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Add link') }}
				</NcButton>
			</div>
		</div>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import FieldListEditor from './FieldListEditor.vue'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariantPlus from 'vue-material-design-icons/LinkVariantPlus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { LINK_KIND, STEP_KIND, isHttpsUrl, newLink } from '../../constants/serviceBuilder.js'

export default {
	name: 'ServiceTaskEditor',

	components: { NcButton, NcCheckboxRadioSwitch, NcTextField, Close, LinkVariantPlus, TrashCanOutline, FieldListEditor },

	props: {
		/** The task, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		/** Whose step it is: the team or the requester. */
		kind: { type: String, required: true },
		stepIndex: { type: Number, required: true },
		index: { type: Number, required: true },
		/** How many tasks the step has. */
		count: { type: Number, required: true },
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
	},

	emits: ['remove'],

	data() {
		return { ICON_BODY, LINK_KIND, STEP_KIND }
	},

	computed: {
		task() {
			return this.modelValue
		},

		maxLinks() {
			return this.limits.links || 5
		},
	},

	methods: {
		t,
		newLink,

		/** Empty, or an address the server accepts. */
		urlOk(value) {
			return String(value ?? '').trim() === '' || isHttpsUrl(value)
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
