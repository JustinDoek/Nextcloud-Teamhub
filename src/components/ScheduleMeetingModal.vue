<template>
    <NcModal
        :name="t('teamhub', 'Schedule meeting')"
        @close="$emit('close')">
        <div class="schedule-modal">
            <h3 class="schedule-modal__title">
                <VideoIcon :size="20" />
                {{ t('teamhub', 'Schedule meeting') }}
            </h3>

            <div class="schedule-modal__field">
                <NcTextField
                    v-model="form.title"
                    :label="t('teamhub', 'Meeting title')"
                    :placeholder="t('teamhub', 'e.g. Weekly sync')"
                    :error="!!errors.title"
                    :helper-text="errors.title || ''" />
            </div>

            <div class="schedule-modal__row">
                <div class="schedule-modal__field">
                    <label class="schedule-modal__label">{{ t('teamhub', 'Date') }}</label>
                    <input
                        v-model="form.date"
                        type="date"
                        class="schedule-modal__input"
                        :min="todayDate" />
                </div>
                <div class="schedule-modal__field">
                    <label class="schedule-modal__label">{{ t('teamhub', 'Time') }}</label>
                    <input
                        v-model="form.time"
                        type="time"
                        class="schedule-modal__input" />
                </div>
                <div class="schedule-modal__field">
                    <label class="schedule-modal__label">{{ t('teamhub', 'Duration (min)') }}</label>
                    <select v-model="form.duration" class="schedule-modal__input schedule-modal__select">
                        <option value="15">15</option>
                        <option value="30">30</option>
                        <option value="45">45</option>
                        <option value="60" selected>60</option>
                        <option value="90">90</option>
                        <option value="120">120</option>
                    </select>
                </div>
            </div>

            <div class="schedule-modal__field">
                <NcTextField
                    v-model="form.location"
                    :label="t('teamhub', 'Location / link (optional)')"
                    :placeholder="t('teamhub', 'e.g. Room A or a URL')" />
            </div>

            <div class="schedule-modal__field">
                <NcTextArea
                    v-model="form.description"
                    :label="t('teamhub', 'Description (optional)')"
                    :placeholder="t('teamhub', 'Agenda or notes…')"
                    :rows="3" />
            </div>

            <p v-if="errors.general" class="schedule-modal__error">{{ errors.general }}</p>

            <div class="schedule-modal__actions">
                <NcButton type="primary" :disabled="saving" @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="18" />
                        <CalendarPlus v-else :size="18" />
                    </template>
                    {{ saving ? t('teamhub', 'Scheduling…') : t('teamhub', 'Schedule meeting') }}
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
import VideoIcon from 'vue-material-design-icons/Video.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'

export default {
    name: 'ScheduleMeetingModal',
    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, VideoIcon, CalendarPlus },
    props: {
        teamId:        { type: String, required: true },
        calendarToken: { type: String, default: null },  // public_token for the team calendar
    },
    emits: ['close'],
    data() {
        const now = new Date()
        const pad = n => String(n).padStart(2, '0')
        return {
            saving: false,
            errors: {},
            form: {
                title:       '',
                date:        `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`,
                time:        `${pad(now.getHours()+1)}:00`,
                duration:    '60',
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
                this.errors.title = t('teamhub', 'Meeting title is required')
            }
            if (!this.form.date) {
                this.errors.general = t('teamhub', 'Date is required')
            }
            return Object.keys(this.errors).length === 0
        },
        async submit() {
            if (!this.validate()) return
            this.saving = true
            this.errors = {}
            try {
                const startDt = new Date(`${this.form.date}T${this.form.time}:00`)
                const endDt   = new Date(startDt.getTime() + parseInt(this.form.duration) * 60000)

                // Build an iCalendar event via NC Calendar's internal CalDAV endpoint
                // We post to the team calendar using the backend helper endpoint
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
                showSuccess(t('teamhub', 'Meeting scheduled'))
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.errors.general = msg ? t('teamhub', 'Failed to schedule meeting: {error}', { error: msg }) : t('teamhub', 'Failed to schedule meeting')
                showError(t('teamhub', 'Failed to schedule meeting'))
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.schedule-modal {
    padding: 24px;
    max-width: 560px;
    min-width: 360px;
}

/* Allow the modal to shrink below 360px on narrow screens. */
@media (max-width: 768px) {
    .schedule-modal {
        min-width: 0;
        padding: 16px;
    }
}

.schedule-modal__title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 24px;
    color: var(--color-main-text);
}

.schedule-modal__field {
    margin-bottom: 16px;
}

.schedule-modal__row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.schedule-modal__row .schedule-modal__field {
    flex: 1;
    min-width: 120px;
    margin-bottom: 0;
}

.schedule-modal__label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 5px;
}

.schedule-modal__input {
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

.schedule-modal__input:focus {
    border-color: var(--color-primary-element);
}

.schedule-modal__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.schedule-modal__select { cursor: pointer; }

.schedule-modal__error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
}

.schedule-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
