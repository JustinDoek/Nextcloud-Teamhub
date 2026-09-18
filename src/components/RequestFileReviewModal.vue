<template>
	<NcModal
		:name="t('teamhub', 'Request review')"
		size="normal"
		@close="close">
		<div class="fr-modal">
			<h3 class="fr-modal__title">{{ t('teamhub', 'Request review') }}</h3>

			<NcLoadingIcon v-if="loading" :size="32" class="fr-modal__loading" />

			<!-- The action only appears for files in a team folder, so this is
			     a race rather than a normal outcome: a folder detached between
			     page load and click. Said plainly, with no way forward. -->
			<p v-else-if="!context.eligible" class="fr-modal__notice" role="alert">
				{{ t('teamhub', 'This file is not in one of your team folders, so a review cannot be requested for it.') }}
			</p>

			<template v-else>
				<p class="fr-modal__file">
					<FileEyeOutline :size="20" aria-hidden="true" />
					<span class="fr-modal__file-name">{{ context.fileName }}</span>
					<span class="fr-modal__file-team">{{ context.teamName }}</span>
				</p>

				<!-- A warning, not a block: a second opinion on the same file is
				     a legitimate thing to want, and only the requester knows
				     whether the open one covers what they need. -->
				<p v-if="context.openReviews.length" class="fr-modal__warning">
					{{ n('teamhub',
						'There is already %n open review on this file.',
						'There are already %n open reviews on this file.',
						context.openReviews.length) }}
				</p>

				<!-- v4.8.34 — a `div role="group"`, not a `<label>`.
				     `NcCheckboxRadioSwitch` renders its own `<input>` and a
				     `<label for>` pointing at it. Nested inside an outer
				     `<label>`, a mouse click hit that inner label, which fired
				     a synthetic click on the input, which bubbled to the outer
				     label, which fired a *second* synthetic click on the same
				     input — two toggles, net nothing. The box could only be
				     ticked with the spacebar, which goes straight to the
				     focused input and never involves a label at all.

				     An outer `<label>` was wrong here regardless: a label
				     points at exactly one control, and this wraps a checkbox
				     *and* a multiselect. `role="group"` with
				     `aria-labelledby` is what names a set of controls. -->
				<div
					class="fr-modal__field"
					role="group"
					aria-labelledby="fr-modal-reviewers-label">
					<span id="fr-modal-reviewers-label" class="fr-modal__label">{{ t('teamhub', 'Reviewers') }}</span>
					<NcCheckboxRadioSwitch
						:model-value="allMembers"
						class="fr-modal__all"
						@update:model-value="toggleAll">
						{{ t('teamhub', 'Everyone in this team') }}
					</NcCheckboxRadioSwitch>
					<NcSelect
						v-if="!allMembers"
						v-model="reviewers"
						:options="memberOptions"
						:multiple="true"
						:close-on-select="false"
						:clearable="true"
						label="displayName"
						track-by="uid"
						:placeholder="t('teamhub', 'Pick one or more team members')"
						:aria-label="t('teamhub', 'Reviewers')" />
					<span v-else class="fr-modal__hint">
						{{ n('teamhub',
							'%n team member will be asked.',
							'%n team members will be asked.',
							context.members.length) }}
					</span>
				</div>

				<label class="fr-modal__field">
					<span class="fr-modal__label">{{ t('teamhub', 'Message (optional)') }}</span>
					<textarea
						v-model="message"
						class="fr-modal__textarea"
						rows="3"
						maxlength="4000"
						:placeholder="t('teamhub', 'What would you like them to look at?')" />
				</label>

				<label class="fr-modal__field">
					<span class="fr-modal__label">{{ t('teamhub', 'Due date (optional)') }}</span>
					<input
						v-model="dueDate"
						type="date"
						class="fr-modal__input">
				</label>

				<p v-if="error" class="fr-modal__error" role="alert">{{ error }}</p>

				<div class="fr-modal__actions">
					<NcButton variant="tertiary" @click="close">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="!canSubmit"
						@click="submit">
						{{ submitting ? t('teamhub', 'Sending…') : t('teamhub', 'Request review') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
/**
 * Ask teammates to review one file (v4.8.18).
 *
 * Mounted by `src/filesactions.js` from inside the Nextcloud Files app — both
 * standalone and in the iframe on a team's Files tab. It is therefore the one
 * TeamHub component that has to stand entirely on its own: no Vuex store, no
 * router, no shell around it.
 *
 * Everything it renders comes from one `review-context` call, and the reviewer
 * list it posts is a **snapshot** resolved at submit time. "Everyone in this
 * team" is expanded server-side, by posting an empty reviewer array — sending
 * the names the browser happens to know would freeze a roster that may have
 * changed since the modal opened.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcModal, NcButton, NcSelect, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import FileEyeOutline from 'vue-material-design-icons/FileEyeOutline.vue'

import { fromDateInput } from '../lib/localDate.js'
import logger from '../logger.js'

export default {
	name: 'RequestFileReviewModal',

	components: {
		NcModal, NcButton, NcSelect, NcLoadingIcon, NcCheckboxRadioSwitch,
		FileEyeOutline,
	},

	props: {
		fileId: { type: Number, required: true },
		fileName: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			submitting: false,
			error: '',
			/** Filled by review-context; the empty shape keeps the template safe. */
			context: {
				eligible: false,
				teamId: null,
				teamName: '',
				fileName: this.fileName,
				members: [],
				openReviews: [],
			},
			allMembers: false,
			reviewers: [],
			message: '',
			dueDate: '',
		}
	},

	computed: {
		memberOptions() {
			return this.context.members.map(m => ({
				uid: m.uid,
				displayName: m.displayName && m.displayName.trim() ? m.displayName : m.uid,
			}))
		},

		canSubmit() {
			if (this.submitting || !this.context.eligible) {
				return false
			}
			return this.allMembers
				? this.context.members.length > 0
				: this.reviewers.length > 0
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/teamhub/api/v1/files/{fileId}/review-context', { fileId: this.fileId }),
				)
				this.context = {
					eligible: !!data.eligible,
					teamId: data.teamId ?? null,
					teamName: data.teamName ?? '',
					fileName: data.fileName || this.fileName,
					members: Array.isArray(data.members) ? data.members : [],
					openReviews: Array.isArray(data.openReviews) ? data.openReviews : [],
				}
			} catch (e) {
				logger.warn('TeamHub: could not load the review context', { error: e })
				this.error = t('teamhub', 'This file could not be checked. Please try again.')
			} finally {
				this.loading = false
			}
		},

		toggleAll(value) {
			this.allMembers = value
			if (value) {
				// Clearing rather than keeping a hidden selection: switching
				// back should start from nothing, not from a set the user can
				// no longer see.
				this.reviewers = []
			}
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.error = ''

			try {
				const payload = {
					fileId: this.fileId,
					// Empty means "everyone", resolved server-side. See the
					// component docblock for why the browser does not expand it.
					reviewers: this.allMembers ? [] : this.reviewers.map(r => r.uid),
					message: this.message.trim() || null,
					dueAt: this.dueDate ? fromDateInput(this.dueDate) : null,
				}

				await axios.post(
					generateUrl('/apps/teamhub/api/v1/teams/{teamId}/file-reviews', { teamId: this.context.teamId }),
					payload,
				)

				showSuccess(t('teamhub', 'Review requested. It has been posted in this file’s chat.'))
				this.$emit('close')
			} catch (e) {
				// The server's own message where there is one — it names the
				// people who cannot open the file, which is the whole value of
				// that check.
				this.error = e?.response?.data?.error
					|| t('teamhub', 'The review could not be requested.')
				logger.warn('TeamHub: file review request failed', { error: e })
			} finally {
				this.submitting = false
			}
		},

		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.fr-modal {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 14px;
	max-width: 520px;
}

.fr-modal__title {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 600;
}

.fr-modal__loading {
	align-self: center;
	margin: 24px 0;
}

.fr-modal__file {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.fr-modal__file-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fr-modal__file-team {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.fr-modal__notice,
.fr-modal__warning,
.fr-modal__error {
	margin: 0;
	font-size: 0.95em;
}

.fr-modal__warning {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.fr-modal__error {
	color: var(--color-error-text, var(--color-error));
}

.fr-modal__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.fr-modal__label {
	font-weight: 600;
}

.fr-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.fr-modal__all {
	margin-block-end: 4px;
}

.fr-modal__input,
.fr-modal__textarea {
	width: 100%;
	box-sizing: border-box;
}

.fr-modal__textarea {
	resize: vertical;
	min-height: 68px;
}

.fr-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-block-start: 4px;
}
</style>
