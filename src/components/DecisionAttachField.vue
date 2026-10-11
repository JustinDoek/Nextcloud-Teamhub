<template>
	<!-- v4.11.30 — the file a review requester puts forward.
	     One file, two ways to name it: pick one that is already in your
	     Nextcloud, or hand over one that is not. Both end in the same place —
	     a file id the server can read as this user — and the server copies it
	     into the decision's proposal folder, which is what makes it readable
	     by the approvers at all. -->
	<div class="th-attach">
		<span v-if="label" :id="labelId" class="th-attach__label">{{ label }}</span>

		<div v-if="!modelValue" class="th-attach__row">
			<NcButton
				variant="secondary"
				:disabled="disabled || busy"
				:aria-describedby="label ? labelId : undefined"
				@click="pick">
				<template #icon><Paperclip :size="ICON_INLINE" /></template>
				{{ t('teamhub', 'Choose a file') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="disabled || busy"
				:aria-describedby="label ? labelId : undefined"
				@click="triggerUpload">
				<template #icon><UploadIcon :size="ICON_INLINE" /></template>
				{{ busy ? t('teamhub', 'Uploading') : t('teamhub', 'Upload a file') }}
			</NcButton>
			<NcLoadingIcon v-if="busy" :size="ICON_BODY" />
		</div>

		<!-- Picked: one chip, removable. The chip is the whole state — there
		     is no second slot to fill, so there is no list to render. -->
		<div v-else class="th-attach__row">
			<NcChip
				:text="modelValue.name"
				:aria-label-close="t('teamhub', 'Remove {file}', { file: modelValue.name })"
				:no-close="disabled || busy"
				@close="clear">
				<template #icon><FileOutline :size="ICON_INLINE" /></template>
			</NcChip>
		</div>

		<p v-if="hint" class="th-attach__hint">{{ hint }}</p>

		<!-- Kept out of the layout rather than conditionally rendered: the
		     click handler needs it in the DOM at the moment it fires. -->
		<input
			ref="fileInput"
			type="file"
			class="th-attach__input"
			@change="onFileSelected">
	</div>
</template>

<script>
/**
 * DecisionAttachField — one optional file on a decision form.
 *
 * `modelValue` is `{ fileId, name }` or null, which is exactly what the
 * review-request endpoint takes. The component never talks to TeamHub's own
 * API: picking resolves a file id out of Nextcloud's picker, uploading
 * resolves one out of a WebDAV PUT, and the form that owns the field is what
 * sends it.
 *
 * Single-valued on purpose. Justin's framing was singular — *a text and an
 * attachment* — and a review asks for one wording from one document; a
 * second document is a second thing to decide, which is a second review. The
 * schema says the same thing: two columns on the review row rather than a
 * table (see the v4.11.30 migration).
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcChip, NcLoadingIcon } from '@nextcloud/vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import { ICON_INLINE, ICON_BODY } from '../constants/uiTokens.js'
import { uploadToPersonalFiles } from '../lib/fileUpload.js'

/**
 * Hands out one stable id per mounted field, so the label and the buttons
 * that describe themselves by it can be wired together. A module counter
 * rather than `this.$.uid`, which is Vue's internal instance object and not
 * something a component should read.
 */
let fieldSeq = 0

export default {
	name: 'DecisionAttachField',

	components: { NcButton, NcChip, NcLoadingIcon, FileOutline, Paperclip, UploadIcon },

	props: {
		/** `{ fileId, name }` or null. */
		modelValue: { type: Object, default: null },
		/** Visible label above the buttons. */
		label: { type: String, default: '' },
		/** One line under them, saying what the file is for. */
		hint: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			ICON_INLINE,
			ICON_BODY,
			busy: false,
			labelId: `th-attach-label-${++fieldSeq}`,
		}
	},

	methods: {
		t,
		n,

		/** Nextcloud's own file picker, files only, one at a time. */
		async pick() {
			let closed = null
			let nodes = []
			try {
				const { getFilePickerBuilder, FilePickerClosed } = await import('@nextcloud/dialogs')
				closed = FilePickerClosed
				const picker = getFilePickerBuilder(t('teamhub', 'Choose a file'))
					.setMultiSelect(false)
					.allowDirectories(false)
					.addButton({
						// @nextcloud/dialogs 7 needs a button, or nothing can
						// be chosen — the same note FileAttachField carries.
						label: t('teamhub', 'Choose'),
						variant: 'primary',
						callback: () => {},
					})
					.build()
				nodes = await picker.pickNodes()
			} catch (e) {
				// Closing the picker is not an error.
				if (!(closed && e instanceof closed)) {
					showError(t('teamhub', 'The file could not be picked.'))
				}
				return
			}

			const node = (nodes || [])[0]
			const fileId = Number(node?.fileid)
			if (!(fileId > 0)) {
				return
			}
			this.$emit('update:modelValue', {
				fileId,
				name: node.basename || node.displayname || String(fileId),
			})
		},

		triggerUpload() {
			this.$refs.fileInput.value = ''
			this.$refs.fileInput.click()
		},

		async onFileSelected(event) {
			const file = (event.target.files || [])[0]
			if (!file) {
				return
			}
			this.busy = true
			try {
				const result = await uploadToPersonalFiles(file)
				this.$emit('update:modelValue', result)
			} catch (e) {
				const detail = e.response?.data?.ocs?.meta?.message
					|| e.response?.statusText
					|| e.message
					|| ''
				showError(t('teamhub', 'Could not upload {name}: {error}', { name: file.name, error: detail }))
			} finally {
				this.busy = false
			}
		},

		clear() {
			this.$emit('update:modelValue', null)
		},
	},
}
</script>

<style scoped lang="scss">
.th-attach {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.th-attach__label {
	font-size: var(--th-font-meta);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.th-attach__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-sm);
}

.th-attach__hint {
	margin: 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-attach__input {
	display: none;
}
</style>
