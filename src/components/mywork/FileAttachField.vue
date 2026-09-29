<template>
	<!-- v4.10.38 — the paperclip (`docs/service-builder.md` § 7): beside a
	     text box on a service request. The writer picks files from their own
	     Nextcloud; nothing is uploaded or copied. When the text is sent, the
	     server shares them with the other side for the service's number of
	     days, and takes the shares back when the request ends. The line
	     beside the button says with whom, how long and how, before anything
	     is sent. -->
	<div class="wf-files">
		<div class="wf-files__row">
			<NcButton
				variant="tertiary"
				size="small"
				:disabled="disabled || modelValue.length >= max"
				:aria-label="t('teamhub', 'Attach files from Nextcloud')"
				:title="t('teamhub', 'Attach files from Nextcloud')"
				@click="pick">
				<template #icon><Paperclip :size="ICON_INLINE" /></template>
				{{ t('teamhub', 'Attach files') }}
			</NcButton>
			<span v-if="note" class="wf-files__note">{{ note }}</span>
		</div>
		<ul v-if="modelValue.length" class="wf-files__list" :aria-label="t('teamhub', 'Attached files')">
			<li v-for="file in modelValue" :key="file.fileId">
				<NcChip
					:text="file.name"
					:aria-label-close="t('teamhub', 'Remove {file}', { file: file.name })"
					:no-close="disabled"
					@close="remove(file)">
					<template #icon><FileOutline :size="ICON_INLINE" /></template>
				</NcChip>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcChip } from '@nextcloud/vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import { ICON_INLINE } from '../../constants/uiTokens.js'
import { MAX_ATTACHED_FILES } from '../../constants/workflows.js'

export default {
	name: 'FileAttachField',

	components: { NcButton, NcChip, FileOutline, Paperclip },

	props: {
		/** `[{ fileId, name }]` — what the writer picked so far. */
		modelValue: { type: Array, default: () => [] },
		/** With whom, for how long and how: `shareNote()`. */
		note: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
		max: { type: Number, default: MAX_ATTACHED_FILES },
	},

	emits: ['update:modelValue'],

	data() {
		return { ICON_INLINE }
	},

	methods: {
		t,
		n,

		/** Nextcloud's own file picker; files only, several at once. */
		async pick() {
			let nodes = []
			let closed = null
			try {
				const { getFilePickerBuilder, FilePickerClosed } = await import('@nextcloud/dialogs')
				closed = FilePickerClosed
				const picker = getFilePickerBuilder(t('teamhub', 'Attach files'))
					.setMultiSelect(true)
					.allowDirectories(false)
					.addButton({
						// @nextcloud/dialogs 7 needs a button, or nothing can be chosen.
						label: t('teamhub', 'Attach'),
						variant: 'primary',
						callback: () => {},
					})
					.build()
				nodes = await picker.pickNodes()
			} catch (e) {
				// Closing the picker is not an error.
				if (!(closed && e instanceof closed)) {
					showError(t('teamhub', 'The files could not be picked.'))
				}
				return
			}
			const picked = [...this.modelValue]
			for (const node of nodes || []) {
				const fileId = Number(node?.fileid)
				if (fileId > 0 && !picked.some(f => f.fileId === fileId)) {
					picked.push({ fileId, name: node.basename || node.displayname || String(fileId) })
				}
			}
			if (picked.length > this.max) {
				showError(n('teamhub', 'You can attach at most {n} file.', 'You can attach at most {n} files.', this.max, { n: this.max }))
			}
			this.$emit('update:modelValue', picked.slice(0, this.max))
		},

		remove(file) {
			this.$emit('update:modelValue', this.modelValue.filter(f => f.fileId !== file.fileId))
		},
	},
}
</script>

<style scoped lang="scss">
.wf-files {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs, 4px);
}

.wf-files__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--th-space-sm, 8px);
}

.wf-files__note {
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta, 13px);
}

.wf-files__list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--th-space-xs, 4px);
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
