<template>
    <NcModal
        :name="t('teamhub', 'Feedback & Feature Requests')"
        size="normal"
        @close="close">
        <div class="feedback-modal">
            <h2 class="feedback-modal__title">
                {{ t('teamhub', 'Send feedback') }}
            </h2>

            <!-- Success state -->
            <div v-if="submitted" class="feedback-modal__success">
                <CheckCircleOutline :size="48" class="feedback-modal__success-icon" />
                <p class="feedback-modal__success-text">
                    {{ t('teamhub', 'Thank you! Your feedback has been sent.') }}
                </p>
                <NcButton type="primary" @click="close">
                    {{ t('teamhub', 'Close') }}
                </NcButton>
            </div>

            <!-- Form state -->
            <template v-else>
                <!-- Type selector -->
                <div class="feedback-modal__field">
                    <label class="feedback-modal__label" for="feedback-type">
                        {{ t('teamhub', 'Type') }}
                    </label>
                    <div class="feedback-modal__type-group" role="group" aria-labelledby="feedback-type-label">
                        <NcButton
                            v-for="option in typeOptions"
                            :key="option.value"
                            :type="form.type === option.value ? 'primary' : 'secondary'"
                            :aria-pressed="form.type === option.value ? 'true' : 'false'"
                            @click="form.type = option.value">
                            {{ option.label }}
                        </NcButton>
                    </div>
                </div>

                <!-- Subject -->
                <div class="feedback-modal__field">
                    <label class="feedback-modal__label feedback-modal__label--required" for="feedback-subject">
                        {{ t('teamhub', 'Subject') }}
                    </label>
                    <input
                        id="feedback-subject"
                        v-model="form.subject"
                        class="feedback-modal__input"
                        type="text"
                        :placeholder="t('teamhub', 'Short summary of your feedback')"
                        maxlength="200"
                        required
                        @input="clearError('subject')" />
                    <span v-if="errors.subject" class="feedback-modal__error">{{ errors.subject }}</span>
                </div>

                <!-- Description -->
                <div class="feedback-modal__field">
                    <label class="feedback-modal__label feedback-modal__label--required" for="feedback-body">
                        {{ t('teamhub', 'Description') }}
                    </label>
                    <textarea
                        id="feedback-body"
                        v-model="form.body"
                        class="feedback-modal__textarea"
                        :placeholder="t('teamhub', 'Describe your feedback in detail')"
                        maxlength="5000"
                        rows="6"
                        required
                        @input="clearError('body')" />
                    <span v-if="errors.body" class="feedback-modal__error">{{ errors.body }}</span>
                </div>

                <!-- Contact (optional) -->
                <div class="feedback-modal__field">
                    <label class="feedback-modal__label" for="feedback-contact">
                        {{ t('teamhub', 'Your email address (optional)') }}
                    </label>
                    <input
                        id="feedback-contact"
                        v-model="form.contact"
                        class="feedback-modal__input"
                        type="email"
                        :placeholder="t('teamhub', 'So we can follow up with you')"
                        maxlength="254"
                        @input="clearError('contact')" />
                    <span v-if="errors.contact" class="feedback-modal__error">{{ errors.contact }}</span>
                </div>

                <!-- Server error -->
                <p v-if="serverError" class="feedback-modal__server-error">
                    {{ serverError }}
                </p>

                <!-- Actions -->
                <div class="feedback-modal__actions">
                    <NcButton type="tertiary" :disabled="sending" @click="close">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton type="primary" :disabled="sending" @click="submit">
                        <template v-if="sending">
                            {{ t('teamhub', 'Sending…') }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'Send feedback') }}
                        </template>
                    </NcButton>
                </div>
            </template>
        </div>
    </NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcModal, NcButton } from '@nextcloud/vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'

export default {
    name: 'FeedbackModal',

    components: {
        NcModal,
        NcButton,
        CheckCircleOutline,
    },

    data() {
        return {
            form: {
                type: 'feature',
                subject: '',
                body: '',
                contact: '',
            },
            errors: {
                subject: '',
                body: '',
                contact: '',
            },
            sending: false,
            submitted: false,
            serverError: '',
            typeOptions: [
                { value: 'bug',     label: t('teamhub', 'Bug report') },
                { value: 'feature', label: t('teamhub', 'Feature request') },
                { value: 'other',   label: t('teamhub', 'Other') },
            ],
        }
    },

    methods: {
        t,

        close() {
            console.log('[TeamHub][FeedbackModal] close called')
            this.$emit('close')
        },

        clearError(field) {
            this.errors[field] = ''
            this.serverError = ''
        },

        validate() {
            let valid = true

            if (!this.form.subject.trim()) {
                this.errors.subject = t('teamhub', 'Subject is required.')
                valid = false
            }

            if (!this.form.body.trim()) {
                this.errors.body = t('teamhub', 'Description is required.')
                valid = false
            }

            if (this.form.contact.trim() && !this.isValidEmail(this.form.contact.trim())) {
                this.errors.contact = t('teamhub', 'Please enter a valid email address.')
                valid = false
            }

            console.log('[TeamHub][FeedbackModal] validate result:', valid)
            return valid
        },

        isValidEmail(value) {
            // Simple RFC-5322-ish check — server validates authoritatively.
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
        },

        async submit() {
            console.log('[TeamHub][FeedbackModal] submit called', this.form)

            if (!this.validate()) {
                return
            }

            this.sending = true
            this.serverError = ''

            try {
                await axios.post(generateUrl('/apps/teamhub/api/v1/feedback'), {
                    type:    this.form.type,
                    subject: this.form.subject.trim(),
                    body:    this.form.body.trim(),
                    contact: this.form.contact.trim(),
                })
                console.log('[TeamHub][FeedbackModal] feedback sent successfully')
                this.submitted = true
            } catch (err) {
                console.error('[TeamHub][FeedbackModal] send error:', err)
                const msg = err.response?.data?.error
                this.serverError = msg || t('teamhub', 'Something went wrong. Please try again.')
            } finally {
                this.sending = false
            }
        },
    },
}
</script>

<style scoped lang="scss">
.feedback-modal {
    padding: 20px 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;

    &__title {
        font-size: 1.1em;
        font-weight: 600;
        margin: 0 0 4px;
    }

    &__field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    &__label {
        font-weight: 500;
        font-size: 0.9em;
        color: var(--color-text-maxcontrast);

        &--required::after {
            content: ' *';
            color: var(--color-error-text);
        }
    }

    &__type-group {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    &__input,
    &__textarea {
        width: 100%;
        box-sizing: border-box;
        border: 2px solid var(--color-border-maxcontrast);
        border-radius: var(--border-radius);
        background: var(--color-main-background);
        color: var(--color-main-text);
        padding: 8px 12px;
        font-size: 0.95em;
        transition: border-color 0.1s;

        &:focus {
            outline: none;
            border-color: var(--color-primary-element);
        }
    }

    &__textarea {
        resize: vertical;
        min-height: 100px;
        font-family: inherit;
    }

    &__error {
        font-size: 0.85em;
        color: var(--color-error-text);
    }

    &__server-error {
        padding: 8px 12px;
        background: var(--color-error-light, #ffe0e0);
        color: var(--color-error-text);
        border-radius: var(--border-radius);
        font-size: 0.9em;
    }

    &__actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 4px;
    }

    // Success state
    &__success {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
        padding: 24px 0;
        text-align: center;
    }

    &__success-icon {
        color: var(--color-success-text);
    }

    &__success-text {
        font-size: 1em;
        color: var(--color-main-text);
    }
}
</style>
