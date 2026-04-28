<template>
    <NcModal
        :name="t('teamhub', 'Team meeting')"
        size="normal"
        @close="$emit('close')">
        <div class="team-meeting-modal">

            <!-- Header -->
            <div class="team-meeting-modal__header">
                <AccountGroupIcon :size="22" class="team-meeting-modal__header-icon" />
                <div>
                    <h3 class="team-meeting-modal__title">{{ t('teamhub', 'Team meeting') }}</h3>
                    <p class="team-meeting-modal__subtitle">
                        {{ t('teamhub', 'Creates meeting notes, a calendar event and links your team Talk room.') }}
                    </p>
                </div>
            </div>

            <!-- Step indicator -->
            <div class="team-meeting-modal__steps">
                <span class="team-meeting-modal__step" :class="{ 'team-meeting-modal__step--active': true }">
                    <FileDocumentIcon :size="14" />{{ t('teamhub', 'Notes') }}
                </span>
                <span class="team-meeting-modal__step-sep">→</span>
                <span class="team-meeting-modal__step" :class="{ 'team-meeting-modal__step--active': true }">
                    <CalendarIcon :size="14" />{{ t('teamhub', 'Calendar') }}
                </span>
                <span class="team-meeting-modal__step-sep">→</span>
                <span class="team-meeting-modal__step" :class="{ 'team-meeting-modal__step--active': form.includeTalk }">
                    <VideoIcon :size="14" />{{ t('teamhub', 'Talk') }}
                </span>
            </div>

            <!-- Form -->
            <div class="team-meeting-modal__form">

                <!-- Meeting title -->
                <div class="team-meeting-modal__field">
                    <NcTextField
                        v-model="form.title"
                        :label="t('teamhub', 'Meeting title')"
                        :placeholder="t('teamhub', 'e.g. Weekly sync')"
                        :error="!!errors.title"
                        :helper-text="errors.title || ''"
                        @input="updateFilename" />
                </div>

                <!-- Date + Start time + End time -->
                <div class="team-meeting-modal__row">
                    <div class="team-meeting-modal__field">
                        <label class="team-meeting-modal__label">
                            {{ t('teamhub', 'Date') }}
                            <span class="team-meeting-modal__required" aria-hidden="true">*</span>
                        </label>
                        <input
                            v-model="form.date"
                            type="date"
                            class="team-meeting-modal__input"
                            :min="todayDate"
                            :class="{ 'team-meeting-modal__input--error': !!errors.date }" />
                        <span v-if="errors.date" class="team-meeting-modal__field-error">{{ errors.date }}</span>
                    </div>
                    <div class="team-meeting-modal__field">
                        <label class="team-meeting-modal__label">{{ t('teamhub', 'Start time') }}</label>
                        <input
                            v-model="form.startTime"
                            type="time"
                            class="team-meeting-modal__input" />
                    </div>
                    <div class="team-meeting-modal__field">
                        <label class="team-meeting-modal__label">{{ t('teamhub', 'End time') }}</label>
                        <input
                            v-model="form.endTime"
                            type="time"
                            class="team-meeting-modal__input"
                            :class="{ 'team-meeting-modal__input--error': !!errors.endTime }" />
                        <span v-if="errors.endTime" class="team-meeting-modal__field-error">{{ errors.endTime }}</span>
                    </div>
                </div>

                <!-- Location -->
                <div class="team-meeting-modal__field">
                    <NcTextField
                        v-model="form.location"
                        :label="t('teamhub', 'Location (optional)')"
                        :placeholder="t('teamhub', 'e.g. Room A or leave blank')" />
                </div>

                <!-- Notes filename -->
                <div class="team-meeting-modal__field">
                    <NcTextField
                        v-model="form.filename"
                        :label="t('teamhub', 'Notes filename')"
                        :placeholder="t('teamhub', 'e.g. 2026-04-27-weekly-sync')"
                        :helper-text="t('teamhub', 'Saved as {filename}.md in the team Meetings folder', { filename: form.filename || '…' })" />
                </div>

                <!-- Talk room -->
                <div class="team-meeting-modal__field">
                    <NcCheckboxRadioSwitch
                        :checked.sync="form.includeTalk"
                        type="checkbox">
                        {{ t('teamhub', 'Schedule in Talk') }}
                    </NcCheckboxRadioSwitch>
                    <transition name="fade">
                        <div v-if="form.includeTalk" class="team-meeting-modal__talk-info">
                            <div v-if="resources.talk" class="team-meeting-modal__info team-meeting-modal__info--talk">
                                <VideoIcon :size="16" />
                                <span>{{ t('teamhub', 'Will be linked to the team Talk room: {name}', { name: resources.talk.name || t('teamhub', 'Team room') }) }}</span>
                            </div>
                            <div v-else class="team-meeting-modal__info team-meeting-modal__info--warning">
                                <AlertIcon :size="16" />
                                <span>{{ t('teamhub', 'No Talk room found — a new Talk chat will be created for this meeting.') }}</span>
                            </div>
                            <!-- Ask for agenda items -->
                            <NcCheckboxRadioSwitch
                                :checked.sync="form.askAgenda"
                                type="checkbox"
                                class="team-meeting-modal__agenda-check">
                                {{ t('teamhub', 'Ask team for agenda items') }}
                            </NcCheckboxRadioSwitch>
                            <p v-if="form.askAgenda" class="team-meeting-modal__agenda-hint">
                                {{ t('teamhub', 'A message with a link to the meeting notes will be posted in the Talk room asking team members to add agenda items.') }}
                            </p>
                        </div>
                    </transition>
                </div>

                <!-- General error -->
                <p v-if="errors.general" class="team-meeting-modal__error">
                    {{ errors.general }}
                </p>

                <!-- Success result -->
                <div v-if="result" class="team-meeting-modal__result">
                    <CheckCircleIcon :size="20" class="team-meeting-modal__result-icon" />
                    <div class="team-meeting-modal__result-body">
                        <p class="team-meeting-modal__result-title">{{ t('teamhub', 'Team meeting created!') }}</p>
                        <a :href="result.notesUrl" target="_blank" rel="noopener noreferrer" class="team-meeting-modal__result-link">
                            <FileDocumentIcon :size="14" />{{ t('teamhub', 'Open meeting notes') }}
                        </a>
                        <a v-if="result.talkUrl" :href="result.talkUrl" target="_blank" rel="noopener noreferrer" class="team-meeting-modal__result-link">
                            <VideoIcon :size="14" />{{ t('teamhub', 'Join Talk room') }}
                        </a>
                        <p v-if="!result.calendarEventCreated" class="team-meeting-modal__result-warn">
                            {{ t('teamhub', 'Note: calendar event could not be created. Check that a team calendar is set up.') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="team-meeting-modal__actions">
                <template v-if="!result">
                    <NcButton
                        type="primary"
                        :disabled="saving"
                        @click="submit">
                        <template #icon>
                            <NcLoadingIcon v-if="saving" :size="18" />
                            <AccountGroupIcon v-else :size="18" />
                        </template>
                        {{ saving ? t('teamhub', 'Creating…') : t('teamhub', 'Create team meeting') }}
                    </NcButton>
                    <NcButton type="tertiary" :disabled="saving" @click="$emit('close')">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                </template>
                <template v-else>
                    <NcButton type="primary" @click="$emit('close')">
                        {{ t('teamhub', 'Done') }}
                    </NcButton>
                </template>
            </div>

        </div>
    </NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import AlertIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'

export default {
    name: 'TeamMeetingModal',

    components: {
        NcModal, NcButton, NcLoadingIcon, NcTextField, NcCheckboxRadioSwitch,
        AccountGroupIcon, CalendarIcon, VideoIcon, FileDocumentIcon, AlertIcon, CheckCircleIcon,
    },

    props: {
        teamId: {
            type: String,
            required: true,
        },
        /** Resources object from parent — used to detect Talk room availability */
        resources: {
            type: Object,
            default: () => ({}),
        },
    },

    emits: ['close'],

    data() {
        const now = new Date()
        const pad = n => String(n).padStart(2, '0')
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
        const nextHour = `${pad(now.getHours() + 1)}:00`
        const twoHours = `${pad(now.getHours() + 2)}:00`

        return {
            saving: false,
            errors: {},
            result: null,
            form: {
                title:       '',
                date:        todayStr,
                startTime:   nextHour,
                endTime:     twoHours,
                location:    '',
                filename:    '',
                includeTalk: true,
                askAgenda:   false,
            },
        }
    },

    computed: {
        todayDate() {
            const n = new Date()
            const pad = v => String(v).padStart(2, '0')
            return `${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`
        },
    },

    methods: {
        t,

        /** Auto-update filename when title or date changes */
        updateFilename() {
            if (!this.form.title) {
                this.form.filename = ''
                return
            }
            const slug = this.form.title
                .toLowerCase()
                .replace(/[^a-z0-9\s]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .substring(0, 60)
            this.form.filename = `${this.form.date}-${slug}`
        },

        validate() {
            this.errors = {}

            if (!this.form.title.trim()) {
                this.errors.title = t('teamhub', 'Meeting title is required')
            }
            if (!this.form.date) {
                this.errors.date = t('teamhub', 'Date is required')
            }
            if (!this.form.startTime) {
                this.errors.startTime = t('teamhub', 'Start time is required')
            }
            if (!this.form.endTime) {
                this.errors.endTime = t('teamhub', 'End time is required')
            } else if (this.form.startTime && this.form.endTime <= this.form.startTime) {
                this.errors.endTime = t('teamhub', 'End time must be after start time')
            }

            return Object.keys(this.errors).length === 0
        },

        async submit() {
            if (!this.validate()) return

            this.saving = true
            this.errors = {}

            const payload = {
                title:       this.form.title.trim(),
                date:        this.form.date,
                startTime:   this.form.startTime,
                endTime:     this.form.endTime,
                location:    this.form.location.trim(),
                filename:    this.form.filename.trim() || this.form.title.trim(),
                includeTalk: this.form.includeTalk ? 1 : 0,
                talkToken:   (this.form.includeTalk && this.resources?.talk?.token) ? this.resources.talk.token : '',
                askAgenda:   (this.form.includeTalk && this.form.askAgenda) ? 1 : 0,
            }

            try {
                const response = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/meetings`),
                    payload
                )

                this.result = response.data
                showSuccess(t('teamhub', 'Team meeting created successfully'))

            } catch (e) {
                const serverMsg = e?.response?.data?.error || ''
                const status    = e?.response?.status

                if (status === 403) {
                    this.errors.general = t('teamhub', 'You do not have permission to create team meetings.')
                } else if (status === 422) {
                    this.errors.general = t('teamhub', 'Team setup incomplete: ') + serverMsg
                } else {
                    this.errors.general = t('teamhub', 'Failed to create team meeting') + (serverMsg ? `: ${serverMsg}` : '')
                }

                showError(t('teamhub', 'Failed to create team meeting'))
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.team-meeting-modal {
    padding: 24px;
    max-width: 580px;
    min-width: 340px;
}

/* Header */
.team-meeting-modal__header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
}

.team-meeting-modal__header-icon {
    color: var(--color-primary-element);
    flex-shrink: 0;
    margin-top: 2px;
}

.team-meeting-modal__title {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--color-main-text);
}

.team-meeting-modal__subtitle {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* Step indicator */
.team-meeting-modal__steps {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 24px;
    padding: 10px 14px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    flex-wrap: wrap;
}

.team-meeting-modal__step {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    opacity: 0.5;
}

.team-meeting-modal__step--active {
    opacity: 1;
    color: var(--color-primary-element);
}

.team-meeting-modal__step-sep {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
}

/* Form */
.team-meeting-modal__form {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.team-meeting-modal__field {
    margin-bottom: 16px;
}

.team-meeting-modal__row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.team-meeting-modal__row .team-meeting-modal__field {
    flex: 1;
    min-width: 110px;
    margin-bottom: 0;
}

.team-meeting-modal__label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 5px;
}

.team-meeting-modal__required {
    color: var(--color-error-text);
    margin-left: 2px;
}

.team-meeting-modal__input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    transition: border-color 0.15s;
}

.team-meeting-modal__input:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.team-meeting-modal__input--error {
    border-color: var(--color-error);
}

.team-meeting-modal__field-error {
    display: block;
    font-size: 12px;
    color: var(--color-error-text);
    margin-top: 4px;
}

/* Info banners */
.team-meeting-modal__info {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: var(--border-radius-large);
    font-size: 13px;
    margin-bottom: 16px;
}

.team-meeting-modal__info--talk {
    background: var(--color-primary-element-light);
    color: var(--color-primary-element-text);
    border: 1px solid var(--color-primary-element);
}

.team-meeting-modal__info--warning {
    background: color-mix(in srgb, var(--color-warning) 12%, transparent);
    color: var(--color-main-text);
    border: 1px solid var(--color-warning);
}

/* General error */
.team-meeting-modal__error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
    padding: 10px 14px;
    background: color-mix(in srgb, var(--color-error) 10%, transparent);
    border-radius: var(--border-radius-large);
    border: 1px solid var(--color-error);
}

/* Success result */
.team-meeting-modal__result {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 16px;
    background: color-mix(in srgb, var(--color-success) 10%, transparent);
    border: 1px solid var(--color-success);
    border-radius: var(--border-radius-large);
    margin-bottom: 16px;
}

.team-meeting-modal__result-icon {
    color: var(--color-success-text);
    flex-shrink: 0;
    margin-top: 1px;
}

.team-meeting-modal__result-body {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.team-meeting-modal__result-title {
    font-weight: 600;
    font-size: 14px;
    margin: 0;
    color: var(--color-main-text);
}

.team-meeting-modal__result-link {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    color: var(--color-primary-element);
    text-decoration: none;
}

.team-meeting-modal__result-link:hover {
    text-decoration: underline;
}

.team-meeting-modal__result-warn {
    font-size: 12px;
    color: var(--color-warning-text);
    margin: 0;
}

/* Talk checkbox info */
.team-meeting-modal__talk-info {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.team-meeting-modal__agenda-check {
    margin-top: 4px;
}

.team-meeting-modal__agenda-hint {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin: 0;
    padding-left: 4px;
    line-height: 1.5;
}

/* Fade transition */
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter, .fade-leave-to {
    opacity: 0;
}

/* Actions */
.team-meeting-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
