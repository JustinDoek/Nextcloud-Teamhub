<template>
	<!-- v4.10.34 — the service builder (`docs/service-builder.md` § 2): a
	     large dialog, opened from the Services widget on the service team's
	     home. It edits the draft; publishing makes the draft the version
	     requests start from. The first and last steps are fixed — the
	     engine adds them — so they are shown, not edited. -->
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
						<!-- Fixed: the engine completes it when the request is sent. -->
						<li class="svc-builder__step svc-builder__step--fixed svc-builder__step--requester">
							<AccountOutline :size="ICON_BODY" class="svc-builder__glyph" aria-hidden="true" />
							<div class="svc-builder__fixed">
								<span class="svc-builder__fixed-label">{{ t('teamhub', 'Request submitted') }}</span>
								<span class="svc-builder__hint">{{ t('teamhub', 'The requester fills in the form.') }}</span>
							</div>
						</li>
					</template>

					<template #item="{ element: step, index }">
					<li
						class="svc-builder__step"
						:class="step.kind === STEP_KIND.DESK ? 'svc-builder__step--team' : 'svc-builder__step--requester'"
						:data-step-key="step.key">
						<component
							:is="step.kind === STEP_KIND.DESK ? 'AccountGroupOutline' : 'AccountOutline'"
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
									<span>{{ step.kind === STEP_KIND.DESK ? t('teamhub', 'The team') : t('teamhub', 'The requester') }}</span>
									<span>{{ n('teamhub', '{n} task', '{n} tasks', step.tasks.length, { n: step.tasks.length }) }}</span>
								</span>
							</div>

							<template v-if="step.open">
							<!-- Who does it: a toggle pair, NcButton :pressed. -->
							<div class="svc-builder__kind" role="group" :aria-label="t('teamhub', 'Who does step {n}', { n: index + 1 })">
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
							<span v-if="step.kind === STEP_KIND.REQUESTER" class="svc-builder__hint">
								{{ t('teamhub', 'Something the requester does before the team continues, such as filling in a form. A task with a link is done when the requester opens the link.') }}
							</span>

							<!-- v4.10.37 — the step's tasks (`docs/service-builder.md`
							     § 4): done at the same time, each claimed on its own.
							     One task may go without a name: it borrows the step's. -->
							<ul class="svc-builder__task-list" :aria-label="t('teamhub', 'Tasks of step {n}', { n: index + 1 })">
								<li v-for="(task, ti) in step.tasks" :key="task.key" class="svc-builder__task">
									<div class="svc-builder__row">
										<label class="svc-builder__field svc-builder__field--grow">
											<span>{{ step.tasks.length > 1 ? t('teamhub', 'Task name') : t('teamhub', 'Task name (optional)') }}</span>
											<NcTextField
												v-model="task.label"
												label-outside
												:maxlength="limits.stepLabel || 100"
												:placeholder="step.tasks.length > 1 ? '' : t('teamhub', 'The step\'s name')"
												:aria-label="t('teamhub', 'Name of task {t} of step {n}', { t: ti + 1, n: index + 1 })"
												:disabled="busy" />
										</label>
										<label v-if="step.kind === STEP_KIND.DESK" class="svc-builder__field svc-builder__field--grow">
											<span>{{ t('teamhub', 'Role (optional)') }}</span>
											<NcTextField
												v-model="task.role"
												label-outside
												:maxlength="limits.role || 64"
												:placeholder="t('teamhub', 'For example: Privacy officer')"
												:aria-label="t('teamhub', 'Role for task {t} of step {n}', { t: ti + 1, n: index + 1 })"
												:disabled="busy" />
										</label>
										<NcButton
											v-if="step.tasks.length > 1"
											variant="tertiary"
											class="svc-builder__link-remove"
											:disabled="busy"
											:aria-label="t('teamhub', 'Remove task {t} of step {n}', { t: ti + 1, n: index + 1 })"
											:title="t('teamhub', 'Remove task')"
											@click="step.tasks.splice(ti, 1)">
											<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
										</NcButton>
									</div>
									<NcCheckboxRadioSwitch
										v-if="step.kind === STEP_KIND.DESK && step.tasks.length > 1"
										v-model="task.nonBlocking"
										type="checkbox"
										:disabled="busy">
										{{ t('teamhub', 'The request may move on while this task is open') }}
									</NcCheckboxRadioSwitch>

									<!-- v4.10.36 — links on the task (§ 6.1). -->
									<div class="svc-builder__links">
										<span class="svc-builder__hint">
											{{ step.kind === STEP_KIND.DESK
												? t('teamhub', 'Links for the team, such as work instructions or the system to go to. Only the team sees them.')
												: t('teamhub', 'Links the requester opens for this task, such as a form to fill in. Opening one marks the task done.') }}
										</span>
										<ul v-if="task.links.length" class="svc-builder__link-list">
											<li v-for="(link, li) in task.links" :key="link.key" class="svc-builder__link">
												<div class="svc-builder__kind" role="group" :aria-label="t('teamhub', 'What link {n} of step {step} opens', { n: li + 1, step: index + 1 })">
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
															:aria-label="t('teamhub', 'Name of link {n} of step {step}', { n: li + 1, step: index + 1 })"
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
															:aria-label="t('teamhub', 'Address of link {n} of step {step}', { n: li + 1, step: index + 1 })"
															:disabled="busy" />
													</label>
													<NcButton
														variant="tertiary"
														class="svc-builder__link-remove"
														:disabled="busy"
														:aria-label="t('teamhub', 'Remove link {n} of step {step}', { n: li + 1, step: index + 1 })"
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
						</div>

						<div class="svc-builder__step-tools">
							<!-- The handle: drag it, or press arrow up / down on it. -->
							<NcButton
								variant="tertiary"
								class="svc-builder__drag"
								:disabled="busy"
								:aria-label="t('teamhub', 'Move step {n}: drag it, or press the up and down arrow keys', { n: index + 1 })"
								:title="t('teamhub', 'Drag to move')"
								@keydown.up.prevent="moveByKey(index, -1)"
								@keydown.down.prevent="moveByKey(index, 1)">
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
								@click="moveByButton(index, -1)">
								<template #icon><ArrowUp :size="ICON_BODY" /></template>
							</NcButton>
							<NcButton
								variant="tertiary"
								class="svc-builder__move-down"
								:disabled="busy || index === form.steps.length - 1"
								:aria-label="t('teamhub', 'Move step {n} down', { n: index + 1 })"
								:title="t('teamhub', 'Move step {n} down', { n: index + 1 })"
								@click="moveByButton(index, 1)">
								<template #icon><ArrowDown :size="ICON_BODY" /></template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:aria-label="t('teamhub', 'Remove step {n}', { n: index + 1 })"
								:title="t('teamhub', 'Remove step')"
								@click="remove(index)">
								<template #icon><TrashCanOutline :size="ICON_BODY" /></template>
							</NcButton>
						</div>
					</li>
					</template>

					<template #footer>
						<!-- Fixed: the requester confirms; an admin of the team may
						     close it in their place. -->
						<li class="svc-builder__step svc-builder__step--fixed svc-builder__step--requester">
							<AccountOutline :size="ICON_BODY" class="svc-builder__glyph" aria-hidden="true" />
							<div class="svc-builder__fixed">
								<span class="svc-builder__fixed-label">{{ t('teamhub', 'Requester confirms') }}</span>
								<span class="svc-builder__hint">{{ t('teamhub', 'The requester confirms the result. An admin of the team can close the request instead.') }}</span>
							</div>
						</li>
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
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import draggable from 'vuedraggable'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariantPlus from 'vue-material-design-icons/LinkVariantPlus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import ServiceIconPicker from './ServiceIconPicker.vue'
import {
	LINK_KIND,
	STEP_KIND,
	documentFromForm,
	fileDaysValid,
	formFromService,
	isHttpsUrl,
	leadDaysValid,
	moveStep,
	newLink,
	newStep,
	newTask,
	publishProblems,
} from '../../constants/serviceBuilder.js'

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
		AccountGroupOutline, AccountOutline, ArrowDown, ArrowUp, ChevronDown, ChevronRight, Close, DragVertical, LinkVariantPlus, Plus,
		TrashCanOutline, UnfoldLessHorizontal, UnfoldMoreHorizontal, draggable, ServiceIconPicker,
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
			LINK_KIND,
			STEP_KIND,
			form: this.initialForm(),
			/** Which button is working: 'save' or 'publish'. */
			pending: '',
		}
	},

	computed: {
		categoryOptions() {
			return this.categories.map(c => ({ id: c.key, label: c.label }))
		},

		maxSteps() {
			return this.limits.steps || 10
		},

		maxLinks() {
			return this.limits.links || 5
		},

		maxTasks() {
			return this.limits.tasks || 10
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
			return publishProblems(this.form)
		},

		/** A draft needs a name and a lead time the server accepts; the rest may wait. */
		canSave() {
			return this.form.title.trim() !== '' && this.leadTimeOk && this.urlsOk && this.fileDaysOk
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
			if (this.categories.length && !this.categories.some(c => c.key === form.category)) {
				form.category = this.categories[0].key
			}
			return form
		},

		t,
		n,

		newLink,
		newTask,

		/** v4.10.42 — fold or unfold every step at once. */
		setAllOpen(open) {
			this.form.steps.forEach(step => { step.open = open })
		},

		/** Empty, or an address the server accepts. */
		urlOk(value) {
			return String(value ?? '').trim() === '' || isHttpsUrl(value)
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

<style scoped lang="scss">
.svc-builder {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px) var(--th-space-xl, 24px);
}

/* v4.10.41 — on the Services tab the tab already has its padding. */
.svc-builder-page .svc-builder {
	padding: 0;
}

.svc-builder__title {
	margin: 0;
	font-size: var(--th-font-heading-lg, 20px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-builder__subtitle {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-builder__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-builder__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	min-width: 0;
}

.svc-builder__field--grow {
	flex: 1 1 var(--th-space-xxl, 32px);
}

.svc-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-md, 12px);
	align-items: flex-start;
}

.svc-builder__steps {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.svc-builder__list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.svc-builder__step {
	display: flex;
	align-items: flex-start;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-md, 12px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-main-background);
}

.svc-builder__step--fixed {
	align-items: center;
}

/* v4.10.39 — the team's steps and the requester's read apart at a glance.
   v4.10.42 — found on the instance: NC's light primary and the neutral
   background-dark were two near-identical greys (rgb 230,233,237 against
   237,237,237). Now the team's step is the primary tint with a primary rail,
   the requester's the plain canvas with a neutral rail. */
.svc-builder__step--team {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border-inline-start: var(--th-accent-border, 3px) solid var(--color-primary-element);
}

.svc-builder__step--requester {
	background: var(--color-main-background);
	border-inline-start: var(--th-accent-border, 3px) solid var(--color-border-maxcontrast);
}

.svc-builder__step-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-xs, 4px) var(--th-space-md, 12px);
}

.svc-builder__step-toggle {
	max-width: 100%;
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-builder__step-summary {
	display: flex;
	flex-wrap: wrap;
	gap: 0 var(--th-space-sm, 8px);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.svc-builder__fold-all {
	display: flex;
	justify-content: flex-end;
}

.svc-builder__step--ghost {
	opacity: 0.5;
}

.svc-builder__drag {
	cursor: grab;
}

.svc-builder__glyph {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	margin-block-start: var(--th-space-xs, 4px);
}

.svc-builder__fixed {
	display: flex;
	flex-direction: column;
}

.svc-builder__fixed-label {
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-builder__step-body {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	min-width: 0;
}

.svc-builder__kind {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
}

.svc-builder__files {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
}

.svc-builder__task-list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.svc-builder__task {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding: var(--th-space-sm, 8px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.svc-builder__links {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.svc-builder__link-list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.svc-builder__link {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	padding-inline-start: var(--th-space-sm, 8px);
	border-inline-start: 1px solid var(--color-border);
}

.svc-builder__link-remove {
	align-self: flex-end;
}

.svc-builder__step-tools {
	display: flex;
	flex: 0 0 auto;
	gap: var(--th-space-xs, 4px);
}

.svc-builder__add {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-sm, 8px);
}

.svc-builder__problems-title {
	margin: 0;
	font-weight: var(--th-font-weight-semibold, 600);
}

.svc-builder__problems {
	margin: 0;
	padding-inline-start: var(--th-space-lg, 16px);
	list-style: disc;
}

.svc-builder__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-sm, 8px);
}
</style>
