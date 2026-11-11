<template>
	<NcDialog
		:name="t('teamhub', 'Request a quote')"
		:open="open"
		size="normal"
		@update:open="onOpenChange">
		<template #default>
			<!-- Sent: the reference replaces the form, so nobody sends twice
			     by pressing the button that is still there. The live region is
			     always in the DOM so the swap is announced (WCAG 4.1.3). -->
			<div aria-live="polite">
			<div v-if="reference" class="quote-dialog">
				<NcNoteCard type="success">
					<p class="quote-dialog__note">
						<!-- TRANSLATORS: {reference} is a short code such as TH-2611-K7QM that the administrator can quote when following up. -->
						{{ t('teamhub', 'Request sent. Your reference is {reference}.', { reference }) }}
					</p>
					<p class="quote-dialog__note">
						{{ t('teamhub', 'We reply to {email} with a quotation, normally within two business days. Nothing is activated by asking.', { email: sentTo }) }}
					</p>
				</NcNoteCard>
			</div>
			</div>

			<form
				v-if="!reference"
				class="quote-dialog"
				novalidate
				@submit.prevent="submit">
				<p class="quote-dialog__intro">
					{{ t('teamhub', 'We reply by email with a quotation. Nothing is activated by asking: the key is issued once you order, and you paste it into this tab yourself.') }}
				</p>

				<fieldset class="quote-dialog__fieldset">
					<!-- TRANSLATORS: heading above two choices, a TeamHub license or a support contract without a license. -->
					<legend class="quote-dialog__legend">{{ t('teamhub', 'What do you need') }}</legend>
					<NcCheckboxRadioSwitch
						v-model="kind"
						value="licence"
						name="teamhub_quote_kind"
						type="radio">
						<!-- TRANSLATORS: choice in the quote form; a paid TeamHub license key. -->
						{{ t('teamhub', 'License') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="kind"
						value="support"
						name="teamhub_quote_kind"
						type="radio">
						<!-- TRANSLATORS: choice in the quote form; a support contract for the free edition, with no license key. -->
						{{ t('teamhub', 'Support only') }}
					</NcCheckboxRadioSwitch>
					<p class="quote-dialog__hint">
						{{ kind === 'support'
							? t('teamhub', 'Support for the free edition. No license key and no licensed modules.')
							: t('teamhub', 'A license key for this server, which unlocks the licensed modules.') }}
					</p>
				</fieldset>

				<NcTextField
					v-model="email"
					type="email"
					autocomplete="email"
					:label="t('teamhub', 'Email address for the quotation')"
					:error="errorField === 'email'"
					:helper-text="errorField === 'email' ? t('teamhub', 'Enter a valid email address.') : ''" />

				<div class="quote-dialog__field">
					<label class="quote-dialog__label" for="teamhub-quote-country">
						{{ t('teamhub', 'Country') }}
					</label>
					<NcSelect
						v-model="country"
						input-id="teamhub-quote-country"
						:options="countries"
						:reduce="o => o.id"
						:clearable="false"
						:placeholder="t('teamhub', 'Choose a country')"
						label-outside
						:aria-label-combobox="t('teamhub', 'Country')" />
					<p :class="['quote-dialog__hint', { 'quote-dialog__hint--error': errorField === 'country' }]">
						{{ errorField === 'country'
							? t('teamhub', 'Choose the country the quotation is for.')
							: t('teamhub', 'The quotation carries the tax treatment for this country.') }}
					</p>
				</div>

				<NcTextField
					v-if="kind === 'licence'"
					v-model="seats"
					type="number"
					inputmode="numeric"
					min="1"
					:max="seatsMax"
					:label="t('teamhub', 'Intended TeamHub seats')"
					:error="errorField === 'seats'"
					:helper-text="errorField === 'seats'
						? t('teamhub', 'Enter a number of seats between 1 and {max}.', { max: seatsMax })
						: t('teamhub', 'The number of people you expect to use TeamHub. An estimate is fine.')" />

				<!-- v4.14.12 — the support plan, license only. None is the
				     Included level every license carries; Supported and
				     Priority are the paid plans. Support only has no choice
				     here because it is a plan already. -->
				<div v-if="kind === 'licence'" class="quote-dialog__field">
					<label class="quote-dialog__label" for="teamhub-quote-support-plan">
						{{ t('teamhub', 'Support plan') }}
					</label>
					<NcSelect
						v-model="supportPlan"
						input-id="teamhub-quote-support-plan"
						:options="supportPlanOptions"
						:reduce="o => o.id"
						:clearable="false"
						:searchable="false"
						label-outside
						:aria-label-combobox="t('teamhub', 'Support plan')" />
				</div>

				<!-- TRANSLATORS of "Comments": label of a free-text field (a noun, remarks) in the quote request. -->
				<NcTextArea
					v-model="message"
					:label="t('teamhub', 'Comments')"
					:maxlength="messageMax"
					rows="4"
					:error="errorField === 'message'"
					:helper-text="errorField === 'message'
						? n('teamhub', 'Keep the comment under {n} character.', 'Keep the comment under {n} characters.', messageMax, { n: messageMax })
						: t('teamhub', 'Billing preference, a deadline, an existing Nextcloud provider, or a question.')" />

				<!-- Said, not hidden. The two facts the server adds are not
				     fields, but the administrator (and their DPO) is told they
				     leave, the same way the member-reporting panel says what it
				     sends. -->
				<p class="quote-dialog__hint">
					{{ t('teamhub', 'The request also carries this server’s instance UUID and its number of unique team members, so the quotation can be bound to this server. No names and no team content are sent.') }}
				</p>

				<NcNoteCard v-if="failure" :type="failure === 'rate_limited' ? 'warning' : 'error'">
					<p class="quote-dialog__note">{{ failureText }}</p>
					<NcButton
						v-if="offerEmail"
						variant="secondary"
						:href="mailtoHref">
						<template #icon>
							<EmailOutlineIcon :size="ICON_BODY" />
						</template>
						{{ t('teamhub', 'Send by email') }}
					</NcButton>
				</NcNoteCard>
			</form>
		</template>

		<template #actions>
			<template v-if="reference">
				<NcButton variant="primary" @click="close">
					{{ t('teamhub', 'Close') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton variant="tertiary" :disabled="sending" @click="close">
					{{ t('teamhub', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="sending" :size="ICON_BODY" />
						<SendIcon v-else :size="ICON_BODY" />
					</template>
					{{ t('teamhub', 'Send request') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { translate as t, translatePlural as n, getCanonicalLocale } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import { ICON_BODY } from '../../constants/uiTokens.js'
import { countryOptions } from '../../constants/countries.js'

const SEATS_MAX   = 100000
const MESSAGE_MAX = 2000

/**
 * Admin → TeamHub → License → *Request a quote* (v4.14.12).
 *
 * Replaces the mailto the button opened since v4.10.0. The administrator
 * chooses a license or support only and gives an address, a country, the
 * intended seats (license only) and a comment; `POST /admin/license/quote`
 * adds the instance UUID and the member count on the server and passes it to
 * the licensing service, which files it with the website's quote requests.
 *
 * The mailto is not gone: when the server cannot reach the service (an
 * air-gapped install, a proxy that refuses it) the error offers *Send by
 * email*, prefilled with what was typed plus the UUID and seats in use, so the
 * administrator never has to type it twice. That is why the two figures are
 * props although the form never shows them.
 *
 * No VAT number: the VAT check is done by hand after the quotation is sent
 * (Justin, 2026-11-10).
 */
export default {
	name: 'LicenseQuoteDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		EmailOutlineIcon,
		SendIcon,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		/** For the e-mail fallback only. */
		instanceUuid: {
			type: String,
			default: '',
		},
		/** For the e-mail fallback only. */
		seatsUsed: {
			type: Number,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			ICON_BODY,
			seatsMax: SEATS_MAX,
			messageMax: MESSAGE_MAX,
			countries: countryOptions(getCanonicalLocale()),
			kind: 'licence',
			email: '',
			country: null,
			seats: '',
			supportPlan: 'none',
			message: '',
			sending: false,
			errorField: null,
			failure: null,
			reference: '',
			sentTo: '',
		}
	},

	computed: {
		/** Values match QuoteRequestService::SUPPORT_PLANS. */
		supportPlanOptions() {
			return [
				// TRANSLATORS: support plan option; no paid support plan, only the support included with every license.
				{ id: 'none', label: t('teamhub', 'None') },
				// TRANSLATORS: name of a paid support plan for a TeamHub license.
				{ id: 'supported', label: t('teamhub', 'Supported') },
				// TRANSLATORS: name of the highest paid support plan for a TeamHub license.
				{ id: 'priority', label: t('teamhub', 'Priority') },
			]
		},
		seatCount() {
			const v = String(this.seats).trim()
			return /^\d+$/.test(v) ? parseInt(v, 10) : null
		},
		canSubmit() {
			if (this.sending || !this.email.trim() || !this.country) {
				return false
			}
			return this.kind !== 'licence' || this.seatCount !== null
		},
		/** Every failure can still go by e-mail, a refused throttle included. */
		offerEmail() {
			return this.failure !== null
		},
		failureText() {
			switch (this.failure) {
			case 'rate_limited':
				return t('teamhub', 'Too many requests from this server. Try again later, or send the request by email.')
			case 'unreachable':
				return t('teamhub', 'This server could not reach the licensing service. Send the request by email instead.')
			default:
				return t('teamhub', 'The licensing service could not take the request right now. Send it by email instead.')
			}
		},
		/** Same address and subject as the v4.10.0 mailto, with what was typed. */
		mailtoHref() {
			const countryLabel = this.countries.find(c => c.id === this.country)?.label || ''
			const lines = [
				`Request: ${this.kind === 'support' ? 'Support only' : 'License'}`,
				`Contact e-mail: ${this.email.trim()}`,
				`Country: ${countryLabel}${this.country ? ` (${this.country})` : ''}`,
			]
			if (this.kind === 'licence' && this.seatCount !== null) {
				lines.push(`Intended seats: ${this.seatCount}`)
			}
			if (this.kind === 'licence') {
				lines.push(`Support plan: ${this.supportPlan}`)
			}
			lines.push('', `UUID: ${this.instanceUuid}`)
			if (Number.isInteger(this.seatsUsed)) {
				lines.push(`Seats in use: ${this.seatsUsed}`)
			}
			if (this.message.trim()) {
				lines.push('', this.message.trim())
			}
			return `mailto:sales@doekworks.eu?subject=${encodeURIComponent('Supporter quote')}&body=${encodeURIComponent(lines.join('\n'))}`
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
			this.kind = 'licence'
			this.email = ''
			this.country = null
			this.seats = ''
			this.supportPlan = 'none'
			this.message = ''
			this.sending = false
			this.errorField = null
			this.failure = null
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
			this.sending = true
			this.errorField = null
			this.failure = null
			try {
				const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/admin/license/quote'), {
					kind: this.kind,
					email: this.email.trim(),
					country: this.country,
					seats: this.kind === 'licence' ? this.seatCount : null,
					// Always a string: the server ignores it for Support only.
					supportPlan: this.supportPlan,
					message: this.message,
				})
				this.sentTo = this.email.trim()
				this.reference = data?.reference || ''
			} catch (err) {
				const status = err?.response?.status
				const body = err?.response?.data || {}
				if (status === 400 && body.error === 'invalid') {
					this.errorField = body.field || null
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
.quote-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-lg);
	padding-block-end: var(--th-space-sm);
}

.quote-dialog__intro,
.quote-dialog__note {
	margin: 0;
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-body);
}

.quote-dialog__fieldset {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
	margin: 0;
	padding: 0;
	border: 0;
}

.quote-dialog__legend {
	margin-block-end: var(--th-space-xs);
	padding: 0;
	font-weight: var(--th-font-weight-semibold, 600);
}

.quote-dialog__field {
	display: flex;
	flex-direction: column;
	gap: var(--th-space-xs);
}

.quote-dialog__label {
	font-weight: var(--th-font-weight-semibold, 600);
}

.quote-dialog__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
	line-height: var(--th-line-height-body);
}

.quote-dialog__hint--error {
	color: var(--color-text-error);
}
</style>
