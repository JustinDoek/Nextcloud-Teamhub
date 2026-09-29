<template>
	<!-- v4.11.0 — the conversation a service request carries (Justin,
	     2026-09-28): the requester and whoever claimed the request write to
	     each other here, and it stays in the request's history. Who may
	     write is the server's answer (`viewer.canMessage`); the box is not
	     there for anybody else. A request nobody has claimed takes the
	     requester's messages without telling anybody: whoever claims it
	     reads them here. -->
	<div class="wf-talk">
		<!-- A message that arrives (the viewer's own, or the other side's on
		     the re-read after sending) is announced, not only drawn. -->
		<div aria-live="polite">
		<p v-if="!items.length" class="wf-talk__muted">{{ t('teamhub', 'No messages yet.') }}</p>
		<ol v-else class="wf-talk__list">
			<li
				v-for="(e, i) in items"
				:key="i"
				class="wf-talk__item"
				:class="{ 'wf-talk__item--mine': e.actorUid === currentUid }">
				<div class="wf-talk__head">
					<span class="wf-talk__who">{{ authorOf(e) }}</span>
					<span v-if="kindOf(e)" class="wf-talk__kind">{{ kindOf(e) }}</span>
					<time
						class="wf-talk__when"
						:datetime="isoInstant(e.occurredAt)"
						:title="formatAbsolute(e.occurredAt)">{{ formatStamp(e.occurredAt) }}</time>
				</div>
				<p class="wf-talk__text">{{ e.payload && e.payload.note }}</p>
				<ul v-if="filesOf(e).length" class="wf-talk__files" :aria-label="t('teamhub', 'Attached files')">
					<li v-for="f in filesOf(e)" :key="f.fileId">
						<a :href="fileUrl(f)" target="_blank" rel="noopener noreferrer" class="wf-talk__file">
							<FileOutline :size="iconInline" aria-hidden="true" />
							{{ f.name }}
						</a>
					</li>
				</ul>
			</li>
		</ol>
		</div>

		<form v-if="canWrite" class="wf-talk__compose" @submit.prevent="send">
			<NcNoteCard v-if="answers" type="info">
				<p>{{ t('teamhub', 'The service team is waiting for your answer. What you send now answers their question.') }}</p>
			</NcNoteCard>
			<NcTextArea
				v-model="text"
				rows="3"
				maxlength="1000"
				:label="textLabel"
				:disabled="busy"
				@keydown.ctrl.enter.prevent="send"
				@keydown.meta.enter.prevent="send" />
			<!-- What it does is said once it is ticked, not before: the box
			     stays small for the plain message it usually is. -->
			<NcCheckboxRadioSwitch
				v-if="viewer.canAskRequester"
				v-model="ask"
				type="checkbox"
				:disabled="busy">
				{{ t('teamhub', 'Wait for the requester\'s answer') }}
				<template v-if="ask" #description>{{ t('teamhub', 'The request shows as waiting until the requester answers.') }}</template>
			</NcCheckboxRadioSwitch>
			<!-- The paperclip and the send button share one line. -->
			<div class="wf-talk__footer">
				<FileAttachField
					v-if="filesAllowed"
					v-model="files"
					class="wf-talk__attach"
					:note="filesNote"
					:disabled="busy" />
				<NcButton
					class="wf-talk__send"
					type="submit"
					variant="secondary"
					:disabled="busy || !text.trim()">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="iconBody" />
						<Send v-else :size="iconBody" />
					</template>
					{{ sendLabel }}
				</NcButton>
			</div>
		</form>
		<p v-else-if="hint" class="wf-talk__muted">{{ hint }}</p>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Send from 'vue-material-design-icons/Send.vue'
import FileAttachField from './FileAttachField.vue'
import {
	canAttachFiles,
	conversation,
	fileSharingOf,
	formatAbsolute,
	formatStamp,
	isOpen as workflowIsOpen,
	isoInstant,
	shareNote,
} from '../../constants/workflows.js'
import { ICON_BODY, ICON_INLINE } from '../../constants/uiTokens.js'

export default {
	name: 'WorkflowConversation',

	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextArea, FileAttachField, FileOutline, Send },

	props: {
		/** The server's view with `history` and `people`. */
		workflow: { type: Object, required: true },
	},

	emits: ['changed'],

	data() {
		return {
			text: '',
			files: [],
			/** Desk only: send as a question the request waits for. */
			ask: false,
			sending: false,
		}
	},

	computed: {
		iconBody() { return ICON_BODY },
		iconInline() { return ICON_INLINE },
		currentUid() { return getCurrentUser()?.uid || '' },
		viewer() { return this.workflow?.viewer || {} },
		items() { return conversation(this.workflow?.history) },
		isOpen() { return workflowIsOpen(this.workflow) },
		canWrite() { return this.isOpen && !!this.viewer.canMessage },
		/** The requester's next message answers the desk's question. */
		answers() { return this.viewer.messageSide === 'requester' && !!this.viewer.answersQuestion },
		busy() {
			const busyId = this.$store.state.workflows?.busyId
			return this.sending || (busyId !== null && busyId !== undefined && busyId === this.workflow?.id)
		},
		action() {
			if (this.answers) {
				return 'provide_information'
			}
			if (this.ask && this.viewer.canAskRequester) {
				return 'request_information'
			}
			return 'message'
		},
		textLabel() {
			if (this.answers) {
				return t('teamhub', 'Your answer')
			}
			return this.action === 'request_information'
				? t('teamhub', 'What do you need to know?')
				: t('teamhub', 'Message')
		},
		sendLabel() {
			switch (this.action) {
			case 'provide_information': return t('teamhub', 'Send answer')
			case 'request_information': return t('teamhub', 'Ask the requester')
			default: return t('teamhub', 'Send message')
			}
		},
		filesAllowed() { return canAttachFiles(this.workflow, this.action) },
		filesNote() {
			const requester = this.workflow?.startedBy || ''
			const who = this.currentUid === requester
				? t('teamhub', 'the service team')
				: (this.workflow?.people?.[requester] || requester)
			return shareNote(fileSharingOf(this.workflow), who)
		},
		/** Why there is no box, for the one person on the desk who might expect one. */
		hint() {
			if (!this.isOpen) {
				return this.items.length ? t('teamhub', 'This request is closed. The conversation stays with it.') : ''
			}
			if (this.workflow?.internal?.claimable) {
				return t('teamhub', 'Claim the request to write to the requester.')
			}
			if (this.viewer.isServiceAgent) {
				return t('teamhub', 'Only the team member who claimed the request writes to the requester.')
			}
			return ''
		},
	},

	watch: {
		/** A different request starts with an empty box. */
		'workflow.id'() {
			this.text = ''
			this.files = []
			this.ask = false
		},
	},

	methods: {
		t,
		n,
		formatAbsolute,
		formatStamp,
		isoInstant,

		authorOf(e) {
			if (e.actorUid && e.actorUid === this.currentUid) {
				// TRANSLATORS: the author of a message the viewer wrote themselves
				return t('teamhub', 'You')
			}
			return this.workflow?.people?.[e.actorUid] || e.actorUid || ''
		},

		/** A question or an answer held the request up; a plain message did not. */
		kindOf(e) {
			switch (e.type) {
			// TRANSLATORS: marks a message the request waited on until it was answered
			case 'information_requested': return t('teamhub', 'Question')
			// TRANSLATORS: marks the message that answered the service team's question
			case 'information_provided': return t('teamhub', 'Answer')
			default: return ''
			}
		},

		filesOf(e) {
			const files = e?.payload?.files
			return Array.isArray(files) ? files.filter(f => Number(f?.fileId) > 0) : []
		},

		fileUrl(f) {
			return generateUrl('/f/{id}', { id: Number(f.fileId) })
		},

		async send() {
			const text = this.text.trim()
			if (!text || this.busy || !this.canWrite) {
				return
			}
			const action = this.action
			const id = this.workflow.id
			// The desk's question lands on its own task; an answer and a plain
			// message are placed by the server.
			const step = action === 'request_information' ? (this.viewer.stepKey || '') : ''
			this.sending = true
			try {
				await this.$store.dispatch('workflows/act', {
					id, action, text, step, fileIds: this.files.map(f => f.fileId),
				})
				this.text = ''
				this.files = []
				this.ask = false
				if (action === 'request_information') {
					showSuccess(t('teamhub', 'Question sent. The request waits for the answer.'))
				} else if (action === 'provide_information') {
					showSuccess(t('teamhub', 'Answer sent'))
				}
				// The history changed: re-read it, and the lists around it.
				await this.$store.dispatch('workflows/open', { id, step: this.viewer.stepKey || '' })
				this.$store.dispatch('workflows/refresh')
				this.$emit('changed', { id, action })
			} catch (e) {
				if (e?.status === 0) {
					return // busy — the first press is still running
				}
				showError(e?.status === 429
					? t('teamhub', 'You sent many messages in a short time. Try again later.')
					: (e?.message || t('teamhub', 'The message could not be sent.')))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.wf-talk {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-md, 12px);
}

.wf-talk__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-talk__list {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	margin: 0;
	padding: 0;
	list-style: none;
}

/* One message: the other side's on the neutral surface at the start, the
   viewer's own on the primary tint at the end. */
.wf-talk__item {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
	max-width: 85%;
	padding: var(--th-space-sm, 8px) var(--th-space-md, 12px);
	border-radius: var(--border-radius-container);
	background: var(--color-background-hover);
}

.wf-talk__item--mine {
	align-self: flex-end;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.wf-talk__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--th-space-sm, 8px);
	font-size: var(--th-font-meta, 13px);
}

.wf-talk__who {
	font-weight: var(--th-font-weight-semibold, 600);
}

.wf-talk__kind {
	padding: 0 var(--th-space-sm, 8px);
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.wf-talk__when {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.wf-talk__text {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.wf-talk__files {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px) var(--th-space-md, 12px);
	margin: 0;
	padding: 0;
	list-style: none;
}

.wf-talk__file {
	display: inline-flex;
	align-items: center;
	gap: var(--th-space-xs, 4px);
	color: inherit;
	font-size: var(--th-font-meta, 13px);
	text-decoration: underline;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
		border-radius: var(--border-radius-small);
	}
}

.wf-talk__compose {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-sm, 8px);
	padding-block-start: var(--th-space-md, 12px);
	border-block-start: 1px solid var(--color-border);
}

.wf-talk__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: var(--th-space-sm, 8px);
}

.wf-talk__attach {
	flex: 1 1 auto;
	min-width: 0;
}

/* Layout only: it keeps to the end of the line with or without a paperclip. */
.wf-talk__send {
	margin-inline-start: auto;
}
</style>
