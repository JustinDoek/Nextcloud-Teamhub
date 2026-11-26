<template>
	<!-- v4.10.34 — the service builder (`docs/service-builder.md` § 2): a
	     large dialog, opened from the Services widget on the service team's
	     home. It edits the draft; publishing makes the draft the version
	     requests start from. The first and last steps have a fixed place —
	     the engine adds them — and, since v4.20.54, their own settings: the
	     request form and the confirmation. -->
	<!-- v4.10.41 — `inline`: the same builder on the Services tab, using the
	     whole tab instead of a modal (Justin, 2026-09-25). -->
	<component
		:is="inline ? 'section' : 'NcModal'"
		v-bind="inline ? { class: 'svc-builder-page', 'aria-labelledby': 'service-builder-title' } : { labelId: 'service-builder-title', size: 'large' }"
		@close="$emit('close')">
		<div class="svc-builder">
			<h3 id="service-builder-title" class="svc-builder__title">
				{{ service ? t('teamhub', 'Edit service') : t('teamhub', 'New service') }}
			</h3>

			<NcNoteCard v-if="service && service.version > 0" type="info">
				{{ t('teamhub', 'Requests already made keep the version they started on. Changes reach the service catalog when you publish them.') }}
			</NcNoteCard>

			<!-- v4.20.58 — what a new service begins with: its steps and its
			     form, to change from there. The name and the rest stay. -->
			<div v-if="!service" class="svc-builder__field">
				<span id="service-builder-starter">{{ t('teamhub', 'Start from') }}</span>
				<div class="svc-builder__kind" role="group" aria-labelledby="service-builder-starter">
					<NcButton
						v-for="option in starters"
						:key="option.id"
						size="small"
						variant="tertiary"
						:pressed="starter === option.id"
						:disabled="busy"
						@click="startFrom(option.id)">
						{{ option.label }}
					</NcButton>
				</div>
			</div>

			<label class="svc-builder__field">
				<span>{{ t('teamhub', 'Name') }}</span>
				<NcTextField
					v-model="form.title"
					label-outside
					:maxlength="limits.title || 100"
					:aria-label="t('teamhub', 'Name')"
					:disabled="busy" />
			</label>

			<label class="svc-builder__field">
				<span>{{ t('teamhub', 'Description') }}</span>
				<NcTextArea
					v-model="form.description"
					rows="3"
					label-outside
					:maxlength="limits.description || 1000"
					:aria-label="t('teamhub', 'Description')"
					:disabled="busy" />
				<span class="svc-builder__hint">{{ t('teamhub', 'What the card in the service catalog says: what somebody can ask for, and what they get.') }}</span>
			</label>

			<!-- v4.10.45 — the card's icon (Justin, 2026-09-25: every built
			     service showed the question mark). -->
			<div class="svc-builder__field">
				<span id="service-builder-icon">{{ t('teamhub', 'Icon') }}</span>
				<ServiceIconPicker
					v-model:value="form.icon"
					:label="t('teamhub', 'Icon of the service')"
					:disabled="busy" />
			</div>

			<div class="svc-builder__row">
				<label class="svc-builder__field svc-builder__field--grow">
					<span>{{ t('teamhub', 'Category') }}</span>
					<NcSelect
						v-model="form.category"
						input-id="service-builder-category"
						label-outside
						:options="categoryOptions"
						:reduce="o => o.id"
						:clearable="false"
						:disabled="busy"
						:aria-label-combobox="t('teamhub', 'Category')" />
				</label>

				<label class="svc-builder__field">
					<span>{{ t('teamhub', 'Lead time in working days (optional)') }}</span>
					<NcTextField
						v-model="form.leadDays"
						type="number"
						min="1"
						:max="limits.leadDays || 60"
						step="1"
						label-outside
						:error="!leadTimeOk"
						:helper-text="leadTimeOk ? '' : t('teamhub', 'Enter a whole number of working days from 1 to {max}, or leave it empty.', { max: limits.leadDays || 60 })"
						:aria-label="t('teamhub', 'Lead time in working days (optional)')"
						:disabled="busy" />
				</label>
			</div>

			<section class="svc-builder__steps" aria-labelledby="service-builder-steps">
				<h4 id="service-builder-steps" class="svc-builder__subtitle">{{ t('teamhub', 'Steps') }}</h4>
				<p class="svc-builder__hint">
					{{ t('teamhub', 'Steps run in order. A step holds one or more tasks, done at the same time; any member of the team can pick up a team task, and its role says what knowledge it needs.') }}
				</p>
				<!-- v4.10.42 — fold every step to its header to see and drag the
				     whole process at once. -->
				<div v-if="form.steps.length > 1" class="svc-builder__fold-all">
					<NcButton variant="tertiary" size="small" :disabled="busy" @click="setAllOpen(!allOpen)">
						<template #icon>
							<UnfoldLessHorizontal v-if="allOpen" :size="ICON_BODY" />
							<UnfoldMoreHorizontal v-else :size="ICON_BODY" />
						</template>
						{{ allOpen ? t('teamhub', 'Fold all steps') : t('teamhub', 'Unfold all steps') }}
					</NcButton>
				</div>

				<!-- v4.10.39 — the steps are dragged into order by their handle
				     (vuedraggable, as the team's tab bar), or moved with the arrow
				     keys on that handle; the fixed first and last steps sit in the
				     header and footer and do not move. A team step and a
				     requester step have different backgrounds (Justin,
				     2026-09-25: "easier for the eye"). -->
				<draggable
					v-model="form.steps"
					tag="ol"
					class="svc-builder__list"
					item-key="key"
					handle=".svc-builder__drag"
					ghost-class="svc-builder__step--ghost"
					:animation="150"
					:force-fallback="true"
					:disabled="busy">
					<template #header>
						<!-- Its place is fixed: the engine completes it when the request
						     is sent. v4.20.54 — what it asks is the service's own. -->
						<RequestFormEditor :model-value="form.request" :limits="limits" :busy="busy" />
					</template>

					<template #item="{ element: step, index }">
						<ServiceStepCard
							:model-value="step"
							:index="index"
							:count="form.steps.length"
							:person-fields="personFieldOptions"
							:form-fields="formFieldOptions"
							:condition-fields="fieldsBefore(form, index)"
							:limits="limits"
							:busy="busy"
							@move-by-key="moveByKey(index, $event)"
							@move-by-button="moveByButton(index, $event)"
							@remove="remove(index)" />
					</template>

					<template #footer>
						<!-- v4.20.54 — the requester confirms, it confirms itself, or
						     there is nothing to confirm. An admin of the team may close
						     a request in the requester's place. -->
						<ConfirmEditor :model-value="form.confirm" :limits="limits" :busy="busy" />
					</template>
				</draggable>

				<div class="svc-builder__add">
					<NcButton variant="secondary" :disabled="busy || atMaxSteps" @click="add(STEP_KIND.DESK)">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Add team step') }}
					</NcButton>
					<NcButton variant="secondary" :disabled="busy || atMaxSteps" @click="add(STEP_KIND.REQUESTER)">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Add requester step') }}
					</NcButton>
					<!-- v4.20.58 — somebody outside the team approves. -->
					<NcButton variant="secondary" :disabled="busy || atMaxSteps" @click="add(STEP_KIND.APPROVAL)">
						<template #icon><Plus :size="ICON_BODY" /></template>
						{{ t('teamhub', 'Add approval step') }}
					</NcButton>
					<span v-if="atMaxSteps" class="svc-builder__hint">
						{{ n('teamhub', 'A service has at most %n step.', 'A service has at most %n steps.', maxSteps) }}
					</span>
				</div>
			</section>

			<!-- v4.10.38 — the paperclip (`docs/service-builder.md` § 7): files
			     the people on a request attach are shared with the other side,
			     never copied, for this many days. -->
			<section class="svc-builder__files" aria-labelledby="service-builder-files">
				<h4 id="service-builder-files" class="svc-builder__subtitle">{{ t('teamhub', 'Files') }}</h4>
				<NcCheckboxRadioSwitch v-model="form.files.allowed" type="switch" :disabled="busy">
					{{ t('teamhub', 'People on a request can attach files') }}
				</NcCheckboxRadioSwitch>
				<template v-if="form.files.allowed">
					<p class="svc-builder__hint">
						{{ t('teamhub', 'An attached file is not uploaded. It is shared from the sender\'s own files with the other side, and unshared when the request ends.') }}
					</p>
					<NcCheckboxRadioSwitch v-model="form.files.edit" type="switch" :disabled="busy">
						{{ t('teamhub', 'The other side can edit shared files') }}
					</NcCheckboxRadioSwitch>
					<label class="svc-builder__field">
						<span>{{ t('teamhub', 'Shared for (days)') }}</span>
						<NcTextField
							v-model="form.files.days"
							type="number"
							min="1"
							:max="limits.fileDays || 365"
							step="1"
							label-outside
							:error="!fileDaysOk"
							:helper-text="fileDaysOk ? '' : t('teamhub', 'Enter a whole number of days from 1 to {max}.', { max: limits.fileDays || 365 })"
							:aria-label="t('teamhub', 'Shared for (days)')"
							:disabled="busy" />
					</label>
				</template>
			</section>

			<NcNoteCard v-if="problems.length" type="warning">
				<p class="svc-builder__problems-title">{{ t('teamhub', 'Before you can publish') }}</p>
				<ul class="svc-builder__problems">
					<li v-for="problem in problems" :key="problem">{{ problem }}</li>
				</ul>
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="svc-builder__actions">
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">{{ t('teamhub', 'Cancel') }}</NcButton>
				<NcButton variant="secondary" :disabled="busy || !canSave" @click="save(false)">
					<template v-if="busy && pending === 'save'" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
					{{ t('teamhub', 'Save draft') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !canSave || problems.length > 0" @click="save(true)">
					<template v-if="busy && pending === 'publish'" #icon><NcLoadingIcon :size="ICON_BODY" /></template>
					{{ service && service.version > 0 ? t('teamhub', 'Publish changes') : t('teamhub', 'Publish') }}
				</NcButton>
			</div>
		</div>
	</component>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import draggable from 'vuedraggable'
import Plus from 'vue-material-design-icons/Plus.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import ConfirmEditor from './ConfirmEditor.vue'
import RequestFormEditor from './RequestFormEditor.vue'
import ServiceIconPicker from './ServiceIconPicker.vue'
import ServiceStepCard from './ServiceStepCard.vue'
import {
	STEP_KIND,
	documentFromForm,
	fileDaysValid,
	formFromService,
	dueDaysValid,
	fieldsBefore,
	isHttpsUrl,
	leadDaysValid,
	moveStep,
	newStep,
	personFields,
	publishProblems,
} from '../../constants/serviceBuilder.js'
import { STARTER, starterDocument, starterLabel } from '../../constants/approvals.js'
import { confirmDaysValid, fieldLabel, optionsValid } from '../../constants/requestForm.js'

/**
 * The service builder's form (v4.10.34). It owns the form and nothing else:
 * saving and publishing are the widget's, which emits them to the store and
 * hands a refusal back through `error`. The server normalises and checks
 * everything again (`TeamServiceBuilder`); the checks here only let the
 * dialog say what is missing before anybody presses a button.
 */
export default {
	name: 'ServiceBuilderDialog',

	components: {
		NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField,
		ConfirmEditor, Plus, RequestFormEditor, ServiceIconPicker, ServiceStepCard,
		UnfoldLessHorizontal, UnfoldMoreHorizontal, draggable,
	},

	props: {
		/** The service being edited, or null for a new one. */
		service: { type: Object, default: null },
		/** `[{ key, label }]` — the app's categories, from the server. */
		categories: { type: Array, default: () => [] },
		/** The server's limits (`GET /built-services`). */
		limits: { type: Object, default: () => ({}) },
		busy: { type: Boolean, default: false },
		/** v4.10.41 — render on the page (the Services tab) instead of in a modal. */
		inline: { type: Boolean, default: false },
		/** A server-side refusal to show under the form. */
		error: { type: String, default: '' },
	},

	emits: ['close', 'save'],

	data() {
		return {
			ICON_BODY,
			STEP_KIND,
			form: this.initialForm(),
			/** v4.20.58 — what a new service started from. */
			starter: STARTER.ISSUE,
			/** Which button is working: 'save' or 'publish'. */
			pending: '',
		}
	},

	computed: {
		categoryOptions() {
			return this.categories.map(c => ({ id: c.key, label: c.label }))
		},

		/** v4.20.58 — what a new service may start from. */
		starters() {
			return Object.values(STARTER).map(id => ({ id, label: starterLabel(id) }))
		},

		/** v4.20.58 — the person fields an approval may take its approver from. */
		personFieldOptions() {
			return personFields(this.form).map(field => ({ id: field.key, label: fieldLabel(field) || t('teamhub', 'Person') }))
		},

		/** v4.20.62 — the answers a notice may carry. */
		formFieldOptions() {
			return this.form.request.fields.map(field => ({
				id: field.key,
				label: fieldLabel(field) || t('teamhub', 'A field without a name'),
				// v4.20.66 — what a condition can compare it with.
				type: field.type,
				options: String(field.optionsText || '').split('\n').map(o => o.trim()).filter(Boolean),
			}))
		},

		maxSteps() {
			return this.limits.steps || 10
		},

		/** v4.10.42 — every step unfolded. */
		allOpen() {
			return this.form.steps.every(step => step.open)
		},

		/**
		 * v4.10.36 — every address typed so far is one the server accepts.
		 * An empty one is allowed in a draft (publishing asks for it); an
		 * address that is not https:// is refused on any save.
		 */
		urlsOk() {
			return this.form.steps.every(step => step.tasks.every(task => task.links.every(link => this.urlOk(link.url))))
		},

		atMaxSteps() {
			return this.form.steps.length >= this.maxSteps
		},

		leadTimeOk() {
			return leadDaysValid(this.form.leadDays, this.limits.leadDays || 60)
		},

		problems() {
			return publishProblems(this.form, this.limits.noticeEmail === true)
		},

		/** A draft needs a name and a lead time the server accepts; the rest may wait. */
		canSave() {
			return this.form.title.trim() !== '' && this.leadTimeOk && this.urlsOk && this.fileDaysOk
				&& this.confirmOk && this.optionsOk
				&& this.form.steps.every(step => dueDaysValid(step.dueDays, this.limits.dueDays || 60))
		},

		/** v4.20.54 — only checked while the service confirms by itself. */
		confirmOk() {
			return confirmDaysValid(this.form.confirm, this.limits.confirmDays || 90)
		},

		/** v4.20.54 — no field has more or longer options than the server takes. */
		optionsOk() {
			return this.form.request.fields.every(field => optionsValid(field, this.limits.options || 20))
		},

		/** v4.10.38 — only checked while files are allowed; off, the number is kept as it was. */
		fileDaysOk() {
			return !this.form.files.allowed || fileDaysValid(this.form.files.days, this.limits.fileDays || 365)
		},
	},

	watch: {
		/** The widget finished saving or publishing: no button is working any more. */
		busy(now) {
			if (!now) {
				this.pending = ''
			}
		},
	},

	methods: {
		/**
		 * v4.10.45 — the draft's form. A new service, or one whose category
		 * an administrator has since made unavailable, starts in the first
		 * category there is: the catch-all may have been removed.
		 */
		initialForm() {
			const form = formFromService(this.service)
			if (!this.service) {
				// v4.20.59 — the starter it shows as chosen, not a nameless step.
				Object.assign(form, { steps: formFromService({ draft: starterDocument(STARTER.ISSUE) }).steps })
				form.steps.forEach(step => { step.open = true })
			}
			if (this.categories.length && !this.categories.some(c => c.key === form.category)) {
				form.category = this.categories[0].key
			}
			return form
		},

		t,
		n,
		fieldsBefore,

		/** v4.10.42 — fold or unfold every step at once. */
		setAllOpen(open) {
			this.form.steps.forEach(step => { step.open = open })
		},

		/** Empty, or an address the server accepts. */
		urlOk(value) {
			return String(value ?? '').trim() === '' || isHttpsUrl(value)
		},

		/** v4.20.58 — the steps and the request form of a starter; what was typed above stays. */
		startFrom(starter) {
			this.starter = starter
			const start = formFromService({ draft: { ...documentFromForm(this.form), request: undefined, ...starterDocument(starter) } })
			start.steps.forEach(step => { step.open = true })
			this.form.steps = start.steps
			this.form.request = start.request
		},

		add(kind) {
			if (!this.atMaxSteps) {
				this.form.steps.push(newStep(kind))
			}
		},

		remove(index) {
			this.form.steps.splice(index, 1)
		},

		/**
		 * The handle's arrow keys (v4.10.39): move the step one place and keep
		 * the keyboard on it — the old arrow buttons left focus behind, and
		 * the step had to be found again.
		 */
		moveByKey(index, delta) {
			const key = this.form.steps[index]?.key
			this.form.steps = moveStep(this.form.steps, index, delta)
			this.$nextTick(() => {
				const handle = this.$el?.querySelector?.(`[data-step-key="${key}"] .svc-builder__drag`)
				if (handle) {
					handle.focus()
					handle.scrollIntoView({ block: 'nearest' })
				}
			})
		},

		/**
		 * v4.11.0 — the up / down buttons (WCAG 2.5.7). The same move as the
		 * handle's arrow keys; focus stays on the pressed button in the
		 * step's new place, or on its handle once that button has reached
		 * the end of the list and is disabled.
		 */
		moveByButton(index, delta) {
			const key = this.form.steps[index]?.key
			this.form.steps = moveStep(this.form.steps, index, delta)
			this.$nextTick(() => {
				const row = this.$el?.querySelector?.(`[data-step-key="${key}"]`)
				const button = row?.querySelector(delta < 0 ? '.svc-builder__move-up' : '.svc-builder__move-down')
				const target = button && !button.disabled ? button : row?.querySelector('.svc-builder__drag')
				if (target) {
					target.focus()
					target.scrollIntoView({ block: 'nearest' })
				}
			})
		},

		save(publish) {
			if (!this.canSave || (publish && this.problems.length)) {
				return
			}
			this.pending = publish ? 'publish' : 'save'
			this.$emit('save', { document: documentFromForm(this.form), publish })
		},
	},
}
</script>

<style scoped lang="scss" src="./serviceBuilder.scss"></style>
