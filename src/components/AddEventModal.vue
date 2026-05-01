<template>
    <NcModal
        :name="t('teamhub', 'Add agenda item')"
        @close="$emit('close')">
        <div class="addevent-modal">
            <h3 class="addevent-modal__title">
                <CalendarPlus :size="20" />
                {{ t('teamhub', 'Add agenda item') }}
            </h3>

            <div class="addevent-modal__field">
                <NcTextField
                    v-model="form.title"
                    :label="t('teamhub', 'Title')"
                    :placeholder="t('teamhub', 'e.g. Sprint planning')"
                    :error="!!errors.title"
                    :helper-text="errors.title || ''" />
            </div>

            <div class="addevent-modal__row">
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label">{{ t('teamhub', 'Start date') }}</label>
                    <input
                        v-model="form.startDate"
                        type="date"
                        class="addevent-modal__input"
                        :min="todayDate" />
                </div>
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label">{{ t('teamhub', 'Start time') }}</label>
                    <input
                        v-model="form.startTime"
                        type="time"
                        class="addevent-modal__input" />
                </div>
            </div>

            <div class="addevent-modal__row">
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label">{{ t('teamhub', 'End date') }}</label>
                    <input
                        v-model="form.endDate"
                        type="date"
                        class="addevent-modal__input"
                        :min="form.startDate || todayDate" />
                </div>
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label">{{ t('teamhub', 'End time') }}</label>
                    <input
                        v-model="form.endTime"
                        type="time"
                        class="addevent-modal__input" />
                </div>
            </div>

            <div class="addevent-modal__field">
                <NcTextField
                    v-model="form.location"
                    :label="t('teamhub', 'Location (optional)')"
                    :placeholder="t('teamhub', 'e.g. Conference room B')" />
            </div>

            <div class="addevent-modal__field">
                <NcTextArea
                    v-model="form.description"
                    :label="t('teamhub', 'Notes (optional)')"
                    :placeholder="t('teamhub', 'Agenda, links, attachments…')"
                    :rows="3" />
            </div>

            <p v-if="errors.general" class="addevent-modal__error">{{ errors.general }}</p>

            <div class="addevent-modal__actions">
                <NcButton type="primary" :disabled="saving" @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="18" />
                        <CalendarPlus v-else :size="18" />
                    </template>
                    {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Add to calendar') }}
                </NcButton>
                <NcButton type="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea } from '@nextcloud/vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'

export default {
    name: 'AddEventModal',
    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, CalendarPlus },
    props: {
        teamId: { type: String, required: true },
    },
    emits: ['close'],
    data() {
        const now = new Date()
        const pad = n => String(n).padStart(2, '0')
        const dateStr = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`
        const startHour = pad(now.getHours()+1)
        return {
            saving: false,
            errors: {},
            form: {
                title:       '',
                startDate:   dateStr,
                startTime:   `${startHour}:00`,
                endDate:     dateStr,
                endTime:     `${startHour}:30`,
                location:    '',
                description: '',
            },
        }
    },
    computed: {
        todayDate() {
            const n = new Date()
            const pad = v => String(v).padStart(2, '0')
            return `${n.getFullYear()}-${pad(n.getMonth()+1)}-${pad(n.getDate())}`
        },
    },
    methods: {
        t,
        validate() {
            this.errors = {}
            if (!this.form.title.trim()) {
                this.errors.title = t('teamhub', 'Title is required')
            }
            if (!this.form.startDate) {
                this.errors.general = t('teamhub', 'Start date is required')
            }
            return Object.keys(this.errors).length === 0
        },
        async submit() {
            if (!this.validate()) return
            this.saving = true
            this.errors = {}
            try {
                const startDt = new Date(`${this.form.startDate}T${this.form.startTime}:00`)
                const endDt   = new Date(`${this.form.endDate}T${this.form.endTime}:00`)

                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events`),
                    {
                        title:       this.form.title.trim(),
                        start:       startDt.toISOString(),
                        end:         endDt.toISOString(),
                        location:    this.form.location.trim(),
                        description: this.form.description.trim(),
                    }
                )
                showSuccess(t('teamhub', 'Event added to calendar'))
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.errors.general = msg ? t('teamhub', 'Failed to add event: {error}', { error: msg }) : t('teamhub', 'Failed to add event')
                showError(t('teamhub', 'Failed to add event'))
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.addevent-modal {
    padding: 24px;
    max-width: 560px;
    min-width: 360px;
}

.addevent-modal__title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 24px;
    color: var(--color-main-text);
}

.addevent-modal__field { margin-bottom: 16px; }

.addevent-modal__row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.addevent-modal__row .addevent-modal__field {
    flex: 1;
    min-width: 140px;
    margin-bottom: 0;
}

.addevent-modal__label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 5px;
}

.addevent-modal__input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
}

.addevent-modal__input:focus {
    border-color: var(--color-primary-element);
}

.addevent-modal__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.addevent-modal__error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
}

.addevent-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
