<template>
	<NcDialog
		:name="t('teamhub', 'Request a trial key')"
		:open="open"
		size="normal"
		@update:open="onOpenChange">
		<template #default>
			<!-- Installed: the tab behind the dialog has already redrawn
			     from the status the server answered with. The live region is
			     always in the DOM so the swap is announced (WCAG 4.1.3), and it
			     sits outside .trial-dialog so its empty state adds no flex gap. -->
			<div aria-live="polite">
			<div v-if="step === 'issued' || step === 'extension_sent'" class="trial-dialog">
				<NcNoteCard v-if="step === 'issued'" type="success">
					<p class="trial-dialog__note">
						<!-- TRANSLATORS: {date} is the last day of the one-month trial, formatted for the reader's locale. -->
						{{ expiresAt
							? t('teamhub', 'Trial key installed. Every licensed module is available until {date}.', { date: expiresLabel })
							: t('teamhub', 'Trial key installed. Every licensed module is available now.') }}
					</p>
				</NcNoteCard>

				<NcNoteCard v-else-if="step === 'extension_sent'" type="success">
					<p class="trial-dialog__note">
						{{ t('teamhub', 'Request sent. Your reference is {reference}.', { reference }) }}
					</p>
					<p class="trial-dialog__note">
						{{ t('teamhub', 'We reply to {email} about an extended trial, normally within two business days.', { email: sentTo }) }}
					</p>
				</NcNoteCard>
			</div>
			</div>

			<div v-if="step !== 'issued' && step !== 'extension_sent'" class="trial-dialog">
				<template v-if="step === 'form' || step === 'used'">
					<p v-if="step === 'form'" class="trial-dialog__intro">
						{{ t('teamhub', 'A trial key unlocks every licensed module for one month and is installed straight away. Each server can have one trial.') }}
					</p>
					<NcNoteCard v-else type="info">
						<p class="trial-dialog__note">
							{{ t('teamhub', 'This server has already had its trial. Ask us for an extended trial and we reply by email.') }}
						</p>
					</NcNoteCard>

					<NcTextField
						v-model="email"
						type="email"
						autocomplete="email"
						:label="t('teamhub', 'Email address')"
						:error="errorField === 'email'"
						:helper-text="errorField === 'email'
							? t('teamhub', 'Enter a valid email address.')
							: t('teamhub', 'We use it to reach you about the trial.')" />

					<div class="trial-dialog__uuid">
						<span class="trial-dialog__label">{{ t('teamhub', 'Instance UUID') }}</span>
						<code>{{ instanceUuid }}</code>
					</div>

					<NcTextArea
						v-if="step === 'used'"
						v-model="message"
						:label="t('teamhub', 'Comments')"
						:maxlength="messageMax"
						rows="3"
						:error="errorField === 'message'"
						:helper-text="errorField === 'message'
							? n('teamhub', 'Keep the comment under {n} character.', 'Keep the comment under {n} characters.', messageMax, { n: messageMax })
							: t('teamhub', 'What you still want to try before you decide.')" />

					<p class="trial-dialog__hint">
						{{ t('teamhub', 'The request also carries this server’s number of unique team members. No names and no team content are sent.') }}
					</p>

					<NcNoteCard v-if="failure" :type="failure === 'rate_limited' ? 'warning' : 'error'">
						<p class="trial-dialog__note">{{ failureText }}</p>
						<NcButton
							v-if="failure !== 'licensed'"
							variant="secondary"
							:href="mailtoHref">
							<template #icon>
								<EmailOutlineIcon :size="ICON_BODY" />
							</template>
							{{ t('teamhub', 'Send by email') }}
						</NcButton>
					</NcNoteCard>
				</template>
			</div>
		</template>

		<template #actions>
			<NcButton
				v-if="step === 'issued' || step === 'extension_sent'"
				variant="primary"
				@click="close">
				{{ t('teamhub', 'Close') }}
			</NcButton>
			<template v-else>
				<NcButton variant="tertiary" :disabled="sending" @click="close">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="sending" :size="ICON_BODY" />
						<KeyPlusIcon v-else-if="step === 'form'" :size="ICON_BODY" />
						<SendIcon v-else :size="ICON_BODY" />
					</template>
					{{ step === 'form'
						? t('teamhub', 'Get trial key')
						: t('teamhub', 'Ask for an extended trial') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import KeyPlusIcon from 'vue-material-design-icons/KeyPlus.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { formatDate } from '../../lib/localDate.js'

const MESSAGE_MAX = 2000

/**
 * Admin → TeamHub → License → *Request a trial key* (v4.14.12).
 *
 * Three steps in one dialog:
 *
 *  - `form`: the administrator gives an email address; the instance UUID is
 *    shown, not typed. *Get trial key* asks `POST /admin/license/trial`, which
 *    fetches this server's one trial from the licensing service and installs
 *    it through the same verification as a pasted key.
 *  - `used`: the service answered that this UUID has had its trial. The same
 *    dialog offers to ask for an extended one, with an optional comment; that
 *    is the same endpoint with `extension`, and it files a request with sales
 *    rather than issuing anything. The comment asks what they still want to
 *    try, **not** how long they need (v4.14.13, Justin): an extension is a
 *    little more time to finish deciding, granted by us, and a field that
 *    invites a duration reads as a form that sets one.
 *  - `issued` / `extension_sent`: the outcome, and Close.
 *
 * `installed` hands the parent the fresh status envelope, so the License tab
 * shows the trial without a second request.
 *
 * The tab hides the button while any licence is in force, a trial included
 * (`salesActionsVisible`, v4.14.14), so the `used` step is reached after a
 * trial has expired rather than during it. The server is looser on purpose:
 * it refuses (`licensed`) only a *paid* key in force, the one case where a
 * trial key would destroy something.
 */
export default {
	name: 'LicenseTrialDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		EmailOutlineIcon,
		KeyPlusIcon,
		SendIcon,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		instanceUuid: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'installed'],

	data() {
		return {
			ICON_BODY,
			messageMax: MESSAGE_MAX,
			step: 'form',
			email: '',
			message: '',
			sending: false,
			errorField: null,
			failure: null,
			expiresAt: null,
			reference: '',
			sentTo: '',
		}
	},

	computed: {
		canSubmit() {
			return !this.sending && this.email.trim() !== ''
		},
		expiresLabel() {
			return this.expiresAt ? formatDate(this.expiresAt * 1000) : ''
		},
		failureText() {
			switch (this.failure) {
			case 'rate_limited':
				return t('teamhub', 'Too many requests from this server. Try again later, or send the request by email.')
			case 'unreachable':
				return t('teamhub', 'This server could not reach the licensing service. Send the request by email instead.')
			case 'licensed':
				return t('teamhub', 'A license key is already installed. A trial key would replace it, so none was requested.')
			case 'invalid_key':
				return t('teamhub', 'A trial key was issued but this server did not accept it. Send us an email and we sort it out.')
			default:
				return t('teamhub', 'The licensing service could not take the request right now. Send it by email instead.')
			}
		},
		/** The e-mail route for every failure, with what was typed. */
		mailtoHref() {
			const subject = this.step === 'used' ? 'Extended trial request' : 'Trial request'
			const lines = [
				`Contact e-mail: ${this.email.trim()}`,
				`UUID: ${this.instanceUuid}`,
			]
			if (this.failure === 'invalid_key') {
				lines.push('', 'The trial key was issued but this server did not accept it.')
			}
			if (this.message.trim()) {
				lines.push('', this.message.trim())
			}
			return `mailto:sales@doekworks.eu?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(lines.join('\n'))}`
		},
	},

	watch: {
		open(now) {
			if (now) {
				this.reset()
			}
		},
	},

	methods: {
		t,
		n,

		reset() {
			this.step = 'form'
			this.email = ''
			this.message = ''
			this.sending = false
			this.errorField = null
			this.failure = null
			this.expiresAt = null
			this.reference = ''
			this.sentTo = ''
		},

		onOpenChange(value) {
			if (!value) {
				this.close()
			}
		},

		close() {
			if (this.sending) {
				return
			}
			this.$emit('close')
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}
			const extension = this.step === 'used'
			this.sending = true
			this.errorField = null
			this.failure = null
			try {
				const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/admin/license/trial'), {
					email: this.email.trim(),
					extension,
					message: extension ? this.message : '',
				})
				this.sentTo = this.email.trim()
				if (data?.status === 'issued') {
					this.expiresAt = Number.isInteger(data.expiresAt) ? data.expiresAt : null
					this.step = 'issued'
					if (data.license) {
						this.$emit('installed', data.license)
					}
				} else {
					this.reference = data?.reference || ''
					this.step = 'extension_sent'
				}
			} catch (err) {
				const status = err?.response?.status
				const body = err?.response?.data || {}
				if (status === 400 && body.error === 'invalid') {
					this.errorField = body.field || null
				} else if (status === 409 && body.error === 'trial_used') {
					this.step = 'used'
				} else if (status === 409 && body.error === 'licensed') {
					this.failure = 'licensed'
				} else if (status === 429) {
					this.failure = 'rate_limited'
				} else if (status === 502 && typeof body.error === 'string') {
					this.failure = body.error
				} else {
					this.failure = 'unavailable'
				}
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.trial-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg);
	padding-block-end: var(--th-space-sm);
}

.trial-dialog__intro,
.trial-dialog__note {
	margin: 0;
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-body);
}

.trial-dialog__uuid {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.trial-dialog__label {
	font-weight: var(--th-font-weight-semibold);
}

.trial-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
	line-height: var(--th-line-height-body);
}
</style>
