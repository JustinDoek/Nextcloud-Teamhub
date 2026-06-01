<template>
    <NcModal
        :name="t('teamhub', 'Add event')"
        size="normal"
        @close="$emit('close')">
        <div class="addevent-modal">

            <h3 class="addevent-modal__title">
                <CalendarPlus :size="20" />
                {{ t('teamhub', 'Add event') }}
            </h3>

            <!-- Title -->
            <div class="addevent-modal__field">
                <NcTextField
                    v-model="form.title"
                    :label="t('teamhub', 'Title')"
                    :placeholder="t('teamhub', 'e.g. Sprint planning')"
                    :error="!!errors.title"
                    :helper-text="errors.title || ''" />
            </div>

            <!-- Date + Start time + End time -->
            <div class="addevent-modal__row">
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label" for="addevent-date">
                        {{ t('teamhub', 'Date') }}
                    </label>
                    <input
                        id="addevent-date"
                        v-model="form.date"
                        type="date"
                        class="addevent-modal__input"
                        :min="todayDate"
                        :class="{ 'addevent-modal__input--error': !!errors.date }" />
                    <span v-if="errors.date" class="addevent-modal__field-error">{{ errors.date }}</span>
                </div>
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label" for="addevent-start">
                        {{ t('teamhub', 'Start time') }}
                    </label>
                    <input
                        id="addevent-start"
                        v-model="form.startTime"
                        type="time"
                        class="addevent-modal__input" />
                </div>
                <div class="addevent-modal__field">
                    <label class="addevent-modal__label" for="addevent-end">
                        {{ t('teamhub', 'End time') }}
                    </label>
                    <input
                        id="addevent-end"
                        v-model="form.endTime"
                        type="time"
                        class="addevent-modal__input"
                        :class="{ 'addevent-modal__input--error': !!errors.endTime }" />
                    <span v-if="errors.endTime" class="addevent-modal__field-error">{{ errors.endTime }}</span>
                </div>
            </div>

            <!-- Location — room picker when rooms are available (RoomVox and/or CRM), free-text fallback -->
            <div v-if="loadingRooms" class="addevent-modal__field addevent-modal__rooms-loading">
                <NcLoadingIcon :size="16" />
                <span>{{ t('teamhub', 'Looking up rooms…') }}</span>
            </div>
            <div v-else-if="rooms.length > 0" class="addevent-modal__field">
                <label class="addevent-modal__label" for="addevent-room">
                    {{ t('teamhub', 'Meeting room') }}
                </label>
                <select
                    id="addevent-room"
                    v-model="selectedRoomId"
                    class="addevent-modal__input addevent-modal__select">
                    <option value="">{{ t('teamhub', '— No room —') }}</option>
                    <option v-for="r in rooms" :key="r.id" :value="r.id">
                        {{ r.displayName }}
                    </option>
                </select>
                <p v-if="pickedRoomIsRoomVox" class="addevent-modal__hint">
                    {{ t('teamhub', 'This room will be booked automatically via RoomVox.') }}
                </p>
                <p v-else-if="selectedRoomId" class="addevent-modal__hint">
                    {{ t('teamhub', 'This room will be invited to the event. Booking must be confirmed by the resource manager.') }}
                </p>
            </div>
            <div v-else class="addevent-modal__field">
                <NcTextField
                    v-model="form.location"
                    :label="t('teamhub', 'Location (optional)')"
                    :placeholder="t('teamhub', 'e.g. Conference room B')" />
            </div>

            <!-- Description -->
            <div class="addevent-modal__field">
                <NcTextArea
                    v-model="form.description"
                    :label="t('teamhub', 'Notes (optional)')"
                    :placeholder="t('teamhub', 'Agenda, links, attachments…')"
                    :rows="3" />
            </div>

            <!-- Category -->
            <div class="addevent-modal__field">
                <label class="addevent-modal__label" for="addevent-category">
                    {{ t('teamhub', 'Category (optional)') }}
                </label>
                <input
                    id="addevent-category"
                    v-model="form.categories"
                    type="text"
                    class="addevent-modal__input"
                    :placeholder="t('teamhub', 'e.g. Sprint planning, Retro')" />
                <p class="addevent-modal__hint">{{ t('teamhub', 'Comma-separated') }}</p>
            </div>

            <!-- Calendar picker — only shown when team has 2+ calendars -->
            <div v-if="showCalendarPicker" class="addevent-modal__field">
                <label class="addevent-modal__label">{{ t('teamhub', 'Calendar') }}</label>
                <div class="addevent-modal__pills" role="group" :aria-label="t('teamhub', 'Calendar')">
                    <button
                        v-for="cal in calendars"
                        :key="cal.id"
                        type="button"
                        class="addevent-modal__pill"
                        :class="{ 'addevent-modal__pill--selected': selectedCalendarId === cal.id }"
                        :aria-pressed="selectedCalendarId === cal.id ? 'true' : 'false'"
                        @click="selectedCalendarId = cal.id">
                        {{ cal.name }}
                    </button>
                </div>
            </div>

            <!-- Attendees -->
            <div class="addevent-modal__field">
                <div class="addevent-modal__attendees-header">
                    <label class="addevent-modal__label">{{ t('teamhub', 'Attendees') }}</label>
                    <button
                        v-if="members.length > 0"
                        type="button"
                        class="addevent-modal__select-all"
                        @click="toggleSelectAll">
                        {{ allSelected ? t('teamhub', 'Deselect all') : t('teamhub', 'Select all') }}
                    </button>
                </div>
                <div v-if="loadingMembers" class="addevent-modal__members-loading">
                    <NcLoadingIcon :size="16" />
                    <span>{{ t('teamhub', 'Loading members…') }}</span>
                </div>
                <ul v-else-if="members.length > 0" class="addevent-modal__members">
                    <li v-for="m in members" :key="m.userId" class="addevent-modal__member">
                        <NcCheckboxRadioSwitch
                            :model-value="!!checked[m.userId]"
                            type="checkbox"
                            @update:model-value="val => setChecked(m.userId, val)">
                            {{ m.displayName || m.userId }}
                        </NcCheckboxRadioSwitch>
                    </li>
                </ul>
                <p v-else class="addevent-modal__hint">
                    {{ t('teamhub', 'No other team members found.') }}
                </p>
                <p v-if="selectedIds.length > 0" class="addevent-modal__hint addevent-modal__hint--attendees">
                    <!-- TRANSLATORS: %n = number of attendees selected -->
                    {{ n('teamhub', '%n attendee will be invited', '%n attendees will be invited', selectedIds.length, { n: selectedIds.length }) }}
                </p>
            </div>

            <!-- Talk -->
            <div class="addevent-modal__field">
                <NcCheckboxRadioSwitch
                    v-model="form.includeTalk"
                    type="checkbox">
                    {{ t('teamhub', 'Add Talk meeting') }}
                </NcCheckboxRadioSwitch>
            </div>

            <!-- General error -->
            <p v-if="errors.general" class="addevent-modal__error">{{ errors.general }}</p>

            <!-- Actions -->
            <div class="addevent-modal__actions">
                <NcButton variant="primary" :disabled="saving" @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="18" />
                        <CalendarPlus v-else :size="18" />
                    </template>
                    {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Add to calendar') }}
                </NcButton>
                <NcButton variant="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'

export default {
    name: 'AddEventModal',
    components: { NcModal, NcButton, NcLoadingIcon, NcTextField, NcTextArea, NcCheckboxRadioSwitch, CalendarPlus },

    props: {
        teamId:    { type: String, required: true },
        calendars: { type: Array,  default: () => [] }, // [{ id, name }]
    },

    emits: ['close'],

    data() {
        const now = new Date()
        const pad = n => String(n).padStart(2, '0')
        const dateStr  = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
        const nextHour = `${pad(now.getHours() + 1)}:00`
        const twoHours = `${pad(now.getHours() + 2)}:00`
        return {
            saving: false,
            errors: {},
            selectedCalendarId: null,
            // Members / attendees
            loadingMembers: false,
            members: [],
            checked: {},
            // Rooms (RoomVox)
            loadingRooms: false,
            rooms: [],
            selectedRoomId: '',
            // Form
            form: {
                title:       '',
                date:        dateStr,
                startTime:   nextHour,
                endTime:     twoHours,
                location:    '',
                description: '',
                categories:  '',
                includeTalk: false,
            },
        }
    },

    computed: {
        todayDate() {
            const n = new Date()
            const pad = v => String(v).padStart(2, '0')
            return `${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`
        },
        showCalendarPicker() {
            return this.calendars && this.calendars.length > 1
        },
        /** True when the selected room comes from RoomVox (auto-booking applies). */
        pickedRoomIsRoomVox() {
            if (!this.selectedRoomId) return false
            const r = this.rooms.find(r => r.id === this.selectedRoomId)
            return r ? (r.source === 'roomvox' || r.source === 'mixed') : false
        },
        allSelected() {
            return this.members.length > 0
                && this.members.every(m => !!this.checked[m.userId])
        },
        selectedIds() {
            return this.members.map(m => m.userId).filter(id => this.checked[id])
        },
    },

    mounted() {
        this.loadMembers()
        this.loadRooms()
    },

    methods: {
        t,
        n,

        setChecked(userId, val) {
            this.checked = { ...this.checked, [userId]: !!val }
        },

        toggleSelectAll() {
            const target = !this.allSelected
            const next = {}
            for (const m of this.members) {
                next[m.userId] = target
            }
            this.checked = next
        },

        async loadMembers() {
            this.loadingMembers = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/members/all`)
                )
                const list = Array.isArray(data) ? data : (Array.isArray(data?.members) ? data.members : [])
                // Exclude self — the organiser is added server-side as CHAIR.
                const me = (getCurrentUser()?.uid || '').toLowerCase()
                this.members = me
                    ? list.filter(m => (m.userId || '').toLowerCase() !== me)
                    : list
            } catch (e) {
                showError(t('teamhub', 'Could not load team members'))
            } finally {
                this.loadingMembers = false
            }
        },

        async loadRooms() {
            // Best-effort — returns [] when RoomVox is not installed.
            this.loadingRooms = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/rooms`)
                )
                this.rooms = Array.isArray(data?.rooms) ? data.rooms : []
            } catch (e) {
                this.rooms = []
            } finally {
                this.loadingRooms = false
            }
        },

        validate() {
            this.errors = {}
            if (!this.form.title.trim()) {
                this.errors.title = t('teamhub', 'Title is required')
            }
            if (!this.form.date) {
                this.errors.date = t('teamhub', 'Date is required')
            }
            if (this.form.startTime && this.form.endTime && this.form.endTime <= this.form.startTime) {
                this.errors.endTime = t('teamhub', 'End time must be after start time')
            }
            return Object.keys(this.errors).length === 0
        },

        async submit() {
            if (!this.validate()) return
            this.saving = true
            this.errors = {}

            try {
                const startDt = new Date(`${this.form.date}T${this.form.startTime}:00`)
                const endDt   = new Date(`${this.form.date}T${this.form.endTime}:00`)

                const picked = this.rooms.find(r => r.id === this.selectedRoomId)

                const payload = {
                    title:       this.form.title.trim(),
                    start:       startDt.toISOString(),
                    end:         endDt.toISOString(),
                    location:    picked ? '' : this.form.location.trim(),
                    description: this.form.description.trim(),
                    categories:  this.form.categories.trim(),
                    includeTalk: this.form.includeTalk ? 1 : 0,
                    attendees:   this.selectedIds.join(','),
                    calendarId:  this.selectedCalendarId || (this.calendars[0]?.id ?? null),
                    roomEmail:   picked ? picked.email       : '',
                    roomName:    picked ? picked.displayName : '',
                    // Only pass roomId for RoomVox rooms — ActivityService uses
                    // roomId !== '' as the gate for calling RoomVoxClient::createBooking.
                    // CRM rooms go in as NEEDS-ACTION ATTENDEEs without a booking attempt.
                    roomId:      (picked && this.pickedRoomIsRoomVox) ? picked.id : '',
                }


                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events`),
                    payload
                )
                showSuccess(t('teamhub', 'Event added to calendar'))
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.errors.general = msg
                    ? t('teamhub', 'Failed to add event: {error}', { error: msg })
                    : t('teamhub', 'Failed to add event')
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

@media (max-width: 768px) {
    .addevent-modal {
        min-width: 0;
        padding: 16px;
    }
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

.addevent-modal__field {
    margin-bottom: 16px;
}

.addevent-modal__row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.addevent-modal__row .addevent-modal__field {
    flex: 1;
    min-width: 110px;
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
    transition: border-color 0.15s;
}

.addevent-modal__input:focus {
    border-color: var(--color-primary-element);
}

.addevent-modal__input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.addevent-modal__input--error {
    border-color: var(--color-error);
}

.addevent-modal__select {
    cursor: pointer;
}

.addevent-modal__field-error {
    display: block;
    font-size: 12px;
    color: var(--color-error-text);
    margin-top: 4px;
}

.addevent-modal__hint {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin: 4px 0 0;
}

.addevent-modal__hint--attendees {
    margin-top: 8px;
    font-style: italic;
}

/* Calendar pills */
.addevent-modal__pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.addevent-modal__pill {
    padding: 4px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-pill);
    background: var(--color-background-hover);
    cursor: pointer;
    font-size: 13px;
    color: var(--color-main-text);
    transition: background 0.15s, border-color 0.15s;
}

.addevent-modal__pill:hover {
    background: var(--color-primary-light);
}

.addevent-modal__pill--selected {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
}

.addevent-modal__pill:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Attendees */
.addevent-modal__attendees-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.addevent-modal__attendees-header .addevent-modal__label {
    margin-bottom: 0;
}

.addevent-modal__select-all {
    font-size: 12px;
    color: var(--color-primary-element);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
}

.addevent-modal__select-all:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: 2px;
}

.addevent-modal__members {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 180px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 4px 0;
}

.addevent-modal__member {
    padding: 2px 12px;
}

.addevent-modal__members-loading,
.addevent-modal__rooms-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

/* Error */
.addevent-modal__error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
    padding: 10px 14px;
    background: color-mix(in srgb, var(--color-error) 10%, transparent);
    border-radius: var(--border-radius-large);
    border: 1px solid var(--color-error);
}

/* Actions */
.addevent-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
