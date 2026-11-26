<template>
	<!-- v4.10.15 — one dialog for every workflow verb that takes a text:
	     a reason to reject or withdraw, a question, an answer, an optional
	     note. The verb, the label and whether the text is required come
	     from the action (constants/workflows.js), so the dialog itself
	     knows no workflow. NcModal rather than NcDialog: the latter is on
	     CLAUDE.md's known-uncertain list for @nextcloud/vue 9.

	     v4.10.17 — `label-id`, not `name`: `name` also renders the title
	     in NcModal's own floating header, which sits over the Nextcloud
	     header bar. The dialog's h3 below is the one title, and the id
	     gives the dialog its accessible name. -->
	<NcModal :label-id="titleId" size="small" @close="$emit('close')">
		<div class="wf-dialog">
			<h3 :id="titleId" class="wf-dialog__title">{{ title }}</h3>
			<p class="wf-dialog__subject">{{ subject }}</p>
			<!-- v4.20.58 — the verbs that name somebody: who approves. -->
			<label v-if="withPerson" class="wf-dialog__field">
				<span>{{ t('teamhub', 'Who approves') }}</span>
				<PersonSelect
					v-model="person"
					input-id="wf-dialog-person"
					:label="t('teamhub', 'Who approves')"
					:disabled="busy" />
			</label>
			<!-- v4.20.71 — a task with fields is completed by filling them in. -->
			<RequestFormField
				v-for="field in fields"
				:key="field.key"
				v-model="answers[field.key]"
				:field="field"
				:disabled="busy" />
			<label class="wf-dialog__field">
				<span>{{ textLabel }}</span>
				<NcTextArea
					ref="input"
					v-model="text"
					class="wf-dialog__textarea"
					rows="3"
					maxlength="1000"
					label-outside
					:aria-label="textLabel"
					:disabled="busy" />
				<span v-if="action === ASK" class="wf-dialog__hint">{{ t('teamhub', 'The requester can read this. The request waits for the answer, and a rejection comes back to you.') }}</span>
			</label>
			<!-- v4.10.38 — the paperclip, where files may go with this verb. -->
			<FileAttachField
				v-if="filesAllowed"
				v-model="files"
				:note="filesNote"
				:disabled="busy" />
			<div class="wf-dialog__actions">
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton
					:variant="destructive ? 'error' : 'primary'"
					:disabled="busy || !ready"
					@click="submit">
					<template v-if="busy" #icon><NcLoadingIcon :size="iconBody" /></template>
					{{ confirmLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { NcModal, NcTextArea, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import FileAttachField from './FileAttachField.vue'
import PersonSelect from '../services/PersonSelect.vue'
import RequestFormField from '../services/RequestFormField.vue'
import { answersPayload, isComplete } from '../../constants/requestForm.js'
import { ACTIONS_WITH_PERSON, WORKFLOW_ACTION, activeStep, actionLabel, actionText, actionTextLabel, canAttachFiles, fileSharingOf, shareNote, DESTRUCTIVE_WORKFLOW_ACTIONS } from '../../constants/workflows.js'
import { ICON_BODY } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowActionDialog',
	components: { NcModal, NcTextArea, NcButton, NcLoadingIcon, FileAttachField, PersonSelect, RequestFormField },
	props: {
		/** One of WORKFLOW_ACTION. */
		action: { type: String, required: true },
		/** The workflow's title, shown under the dialog title. */
		subject: { type: String, default: '' },
		/** True while the action is in flight — locks the form (no double submit). */
		busy: { type: Boolean, default: false },
		/** v4.10.29 — the workflow, for the step's own words (*Grant* for *Complete step*). */
		workflow: { type: Object, default: null },
	},
	emits: ['close', 'submit'],
	data() {
		return { ASK: WORKFLOW_ACTION.REQUEST_APPROVAL, text: '', files: [], person: null, answers: {} }
	},
	computed: {
		iconBody() { return ICON_BODY },
		title() { return actionLabel(this.action, this.workflow) },
		/** The heading NcModal takes its accessible name from (v4.10.17). */
		titleId() { return 'wf-dialog-title-' + this.action },
		confirmLabel() { return actionLabel(this.action, this.workflow) },
		textLabel() { return actionTextLabel(this.action) },
		required() { return actionText(this.action) === 'required' },
		withPerson() { return ACTIONS_WITH_PERSON.includes(this.action) },
		/** v4.20.71 — what the task being completed asks to fill in. */
		fields() {
			return this.action === WORKFLOW_ACTION.COMPLETE ? (activeStep(this.workflow)?.fields || []) : []
		},
		ready() {
			return (!this.required || !!this.text.trim()) && (!this.withPerson || !!this.person?.id)
				&& isComplete(this.fields, this.answers)
		},
		destructive() { return DESTRUCTIVE_WORKFLOW_ACTIONS.includes(this.action) },
		/** v4.10.38 — only on a service request whose service takes files. */
		filesAllowed() { return canAttachFiles(this.workflow, this.action) },
		/**
		 * With whom the files are shared: the requester's go to the service
		 * team, a team member's to the requester.
		 */
		filesNote() {
			const uid = getCurrentUser()?.uid || ''
			const requester = this.workflow?.startedBy || ''
			const who = uid === requester
				? t('teamhub', 'the service team')
				: (this.workflow?.people?.[requester] || requester)
			return shareNote(fileSharingOf(this.workflow), who)
		},
	},
	mounted() {
		// The text is the point of the dialog; put the caret in it — or, for a
		// verb that names somebody, in the search for that person.
		this.$nextTick(() => {
			const el = this.withPerson
				? this.$el?.querySelector?.('#wf-dialog-person') || document.getElementById('wf-dialog-person')
				: this.$refs.input?.$el?.querySelector('textarea')
			if (el) {
				el.focus()
			}
		})
	},
	methods: {
		t,
		n,
		submit() {
			if (this.busy || !this.ready) {
				return
			}
			// v4.10.38 — the files ride along as a second argument.
			this.$emit('submit', this.text.trim(), this.filesAllowed ? this.files : [], this.withPerson ? this.person.id : '',
				this.fields.length ? answersPayload(this.fields, this.answers) : null)
		},
	},
}
</script>

<style scoped lang="scss">
.wf-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
	padding: var(--th-space-lg, 16px);
}

.wf-dialog__title {
	margin: 0;
	font-size: var(--th-font-heading, 16px);
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-dialog__subject {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-dialog__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	font-size: var(--th-font-meta, 13px);
}

.wf-dialog__hint {
	color: var(--color-text-maxcontrast);
}

.wf-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--th-space-sm, 8px);
	margin-block-start: var(--th-space-xs, 4px);
}
</style>
