<template>
	<li
		class="svc-builder__step"
		:class="step.kind === STEP_KIND.DESK ? 'svc-builder__step--team' : 'svc-builder__step--requester'"
		:data-step-key="step.key">
		<!-- One step of the service builder: who does it, its name and its
		     tasks, folded to its header or open. v4.20.54 — out of
		     ServiceBuilderDialog, unchanged. -->
		<component
			:is="glyph"
			:size="ICON_BODY"
			class="svc-builder__glyph"
			aria-hidden="true" />

		<div class="svc-builder__step-body">
			<!-- v4.10.42 — the step's header, always shown: its name,
			     who does it and how many tasks. Pressing it folds or
			     unfolds the step (Justin, 2026-09-25: a step unfolded
			     is too tall to drag past another). -->
			<div class="svc-builder__step-head">
				<NcButton
					variant="tertiary"
					class="svc-builder__step-toggle"
					:aria-expanded="step.open ? 'true' : 'false'"
					:title="step.open ? t('teamhub', 'Fold step') : t('teamhub', 'Unfold step')"
					@click="step.open = !step.open">
					<template #icon>
						<ChevronDown v-if="step.open" :size="ICON_BODY" />
						<ChevronRight v-else :size="ICON_BODY" />
					</template>
					{{ step.label.trim() || t('teamhub', 'Step {n}', { n: index + 1 }) }}
				</NcButton>
				<span class="svc-builder__step-summary">
					<span>{{ who }}</span>
					<span v-if="!isApproval">{{ n('teamhub', '{n} task', '{n} tasks', step.tasks.length, { n: step.tasks.length }) }}</span>
					<span v-if="conditionText">{{ conditionText }}</span>
					<span v-if="dueDays">{{ n('teamhub', 'Within {n} working day', 'Within {n} working days', dueDays, { n: dueDays }) }}</span>
					<span v-if="step.notify.length">{{ n('teamhub', '{n} notice', '{n} notices', step.notify.length, { n: step.notify.length }) }}</span>
				</span>
			</div>

			<template v-if="step.open">
			<!-- Who does it: a toggle pair, NcButton :pressed. An approval is a
			     kind of its own and stays one (v4.20.58). -->
			<div v-if="!isApproval" class="svc-builder__kind" role="group" :aria-label="t('teamhub', 'Who does step {n}', { n: index + 1 })">
				<NcButton
					size="small"
					variant="tertiary"
					:pressed="step.kind === STEP_KIND.DESK"
					:disabled="busy"
					@click="step.kind = STEP_KIND.DESK">
					{{ t('teamhub', 'The team') }}
				</NcButton>
				<NcButton
					size="small"
					variant="tertiary"
					:pressed="step.kind === STEP_KIND.REQUESTER"
					:disabled="busy"
					@click="step.kind = STEP_KIND.REQUESTER">
					{{ t('teamhub', 'The requester') }}
				</NcButton>
			</div>

			<label class="svc-builder__field">
				<span>{{ t('teamhub', 'Step name') }}</span>
				<NcTextField
					v-model="step.label"
					label-outside
					:maxlength="limits.stepLabel || 100"
					:aria-label="t('teamhub', 'Name of step {n}', { n: index + 1 })"
					:disabled="busy" />
			</label>
			<!-- v4.20.68 — a deadline: whoever has the step is reminded once
			     when it passes, and the queue and My Work say it is overdue. -->
			<label class="svc-builder__field">
				<span>{{ t('teamhub', 'Deadline in working days (optional)') }}</span>
				<NcTextField
					v-model="step.dueDays"
					type="number"
					min="1"
					:max="limits.dueDays || 60"
					step="1"
					label-outside
					:error="!dueDaysOk"
					:helper-text="dueDaysOk ? t('teamhub', 'Counted from when the request reaches this step, Monday to Friday.') : t('teamhub', 'Enter a whole number of working days from 1 to {max}, or leave it empty.', { max: limits.dueDays || 60 })"
					:aria-label="t('teamhub', 'Deadline of step {n} in working days (optional)', { n: index + 1 })"
					:disabled="busy" />
			</label>
			<span v-if="step.kind === STEP_KIND.REQUESTER" class="svc-builder__hint">
				{{ t('teamhub', 'Something the requester does before the team continues, such as filling in a form. A task with a link is done when the requester opens the link.') }}
			</span>

			<ApproverEditor
				v-if="isApproval"
				:model-value="step.approval"
				:index="index"
				:step-key="step.key"
				:fields="personFields"
				:limits="limits"
				:busy="busy" />
			<template v-else>
			<!-- v4.10.37 — the step's tasks (`docs/service-builder.md`
			     § 4): done at the same time, each claimed on its own.
			     One task may go without a name: it borrows the step's. -->
			<ul class="svc-builder__task-list" :aria-label="t('teamhub', 'Tasks of step {n}', { n: index + 1 })">
				<ServiceTaskEditor
					v-for="(task, ti) in step.tasks"
					:key="task.key"
					:model-value="task"
					:kind="step.kind"
					:step-index="index"
					:index="ti"
					:count="step.tasks.length"
					:limits="limits"
					:busy="busy"
					@remove="step.tasks.splice(ti, 1)" />
			</ul>
			<div>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="busy || step.tasks.length >= maxTasks"
					@click="step.tasks.push(newTask())">
					<template #icon><Plus :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Add task') }}
				</NcButton>
			</div>
			</template>
			<ConditionEditor
				:model-value="step.condition"
				:index="index"
				:step-key="step.key"
				:fields="conditionFields"
				:busy="busy" />
			<NoticesEditor
				:model-value="step.notify"
				:index="index"
				:step-key="step.key"
				:approval="isApproval"
				:fields="formFields"
				:limits="limits"
				:busy="busy" />
			</template>
		</div>

		<div class="svc-builder__step-tools">
			<!-- The handle: drag it, or press arrow up / down on it. -->
			<NcButton
				variant="tertiary"
				class="svc-builder__drag"
				:disabled="busy"
				:aria-label="t('teamhub', 'Move step {n}: drag it, or press the up and down arrow keys', { n: index + 1 })"
				:title="t('teamhub', 'Drag to move')"
				@keydown.up.prevent="$emit('move-by-key', -1)"
				@keydown.down.prevent="$emit('move-by-key', 1)">
				<template #icon><DragVertical :size="ICON_BODY" /></template>
			</NcButton>
			<!-- v4.11.0 — WCAG 2.5.7: moving a step without dragging,
			     for a pointer that cannot drag. Focus follows the step,
			     which is what the 4.10.39 buttons lacked. -->
			<NcButton
				variant="tertiary"
				class="svc-builder__move-up"
				:disabled="busy || index === 0"
				:aria-label="t('teamhub', 'Move step {n} up', { n: index + 1 })"
				:title="t('teamhub', 'Move step {n} up', { n: index + 1 })"
				@click="$emit('move-by-button', -1)">
				<template #icon><ArrowUp :size="ICON_BODY" /></template>
			</NcButton>
			<NcButton
				variant="tertiary"
				class="svc-builder__move-down"
				:disabled="busy || index === count - 1"
				:aria-label="t('teamhub', 'Move step {n} down', { n: index + 1 })"
				:title="t('teamhub', 'Move step {n} down', { n: index + 1 })"
				@click="$emit('move-by-button', 1)">
				<template #icon><ArrowDown :size="ICON_BODY" /></template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="busy"
				:aria-label="t('teamhub', 'Remove step {n}', { n: index + 1 })"
				:title="t('teamhub', 'Remove step')"
				@click="$emit('remove')">
				<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
			</NcButton>
		</div>
	</li>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ApproverEditor from './ApproverEditor.vue'
import ConditionEditor from './ConditionEditor.vue'
import NoticesEditor from './NoticesEditor.vue'
import ServiceTaskEditor from './ServiceTaskEditor.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { approverSourceLabel } from '../../constants/approvals.js'
import { STEP_KIND, dueDaysValid, newTask } from '../../constants/serviceBuilder.js'
import { conditionDocument, conditionSummary } from '../../constants/conditions.js'

export default {
	name: 'ServiceStepCard',

	components: {
		NcButton, NcTextField, ApproverEditor, ConditionEditor, NoticesEditor, ServiceTaskEditor,
		AccountCheckOutline, AccountGroupOutline, AccountOutline, ArrowDown, ArrowUp, ChevronDown, ChevronRight, DragVertical, Plus, TrashCanOutline,
	},

	props: {
		/** The step, edited in place: it belongs to the builder's form. */
		modelValue: { type: Object, required: true },
		index: { type: Number, required: true },
		/** How many steps there are. */
		count: { type: Number, required: true },
		limits: { type: Object, default: () => ({}) },
		/** v4.20.58 — `[{ id, label }]`: the form's fields that pick a person. */
		personFields: { type: Array, default: () => [] },
		/** v4.20.62 — `[{ id, label }]`: every field of the form. */
		formFields: { type: Array, default: () => [] },
		/** v4.20.71 — the fields a condition here may use: the form's and the earlier steps'. */
		conditionFields: { type: Array, default: () => [] },
		busy: { type: Boolean, default: false },
	},

	emits: ['move-by-key', 'move-by-button', 'remove'],

	data() {
		return { ICON_BODY, STEP_KIND }
	},

	computed: {
		step() {
			return this.modelValue
		},

		isApproval() {
			return this.step.kind === STEP_KIND.APPROVAL
		},

		glyph() {
			if (this.isApproval) {
				return 'AccountCheckOutline'
			}
			return this.step.kind === STEP_KIND.DESK ? 'AccountGroupOutline' : 'AccountOutline'
		},

		who() {
			if (this.isApproval) {
				return approverSourceLabel(this.step.approval.source)
			}
			return this.step.kind === STEP_KIND.DESK ? t('teamhub', 'The team') : t('teamhub', 'The requester')
		},

		/** v4.20.66 — "Only when Kind is Laptop" in the header; '' when it always runs. */
		conditionText() {
			const fields = this.conditionFields.map(f => ({ key: f.id, label: f.label, type: f.type }))
			return conditionSummary(conditionDocument(this.step.condition, fields), fields)
		},

		/** v4.20.68 — the deadline as a number, 0 for none or one still being typed wrong. */
		dueDays() {
			return this.dueDaysOk && String(this.step.dueDays ?? '').trim() !== '' ? Number(this.step.dueDays) : 0
		},

		dueDaysOk() {
			return dueDaysValid(this.step.dueDays, this.limits.dueDays || 60)
		},

		maxTasks() {
			return this.limits.tasks || 10
		},
	},

	methods: {
		t,
		n,
		newTask,
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
