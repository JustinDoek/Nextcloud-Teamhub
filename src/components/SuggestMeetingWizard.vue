<template>
    <NcModal size="normal" @close="$emit('close')">
        <div class="suggest-wizard">
            <h2 class="suggest-wizard__heading">{{ t('teamhub', 'Meeting wizard') }}</h2>

            <!-- Step 1: attendees -->
            <div v-if="step === 1" class="suggest-wizard__step">
                <p class="suggest-wizard__intro">
                    {{ t('teamhub', 'Choose who should attend. Use “Select all” for the whole team, or tick individual members.') }}
                </p>
                <div class="suggest-wizard__toolbar">
                    <NcButton type="secondary" @click="toggleSelectAll">
                        {{ allSelected ? t('teamhub', 'Clear all') : t('teamhub', 'Select all') }}
                    </NcButton>
                    <span class="suggest-wizard__count">
                        {{ n('teamhub', '%n member selected', '%n members selected', selectedCount, { n: selectedCount }) }}
                    </span>
                </div>
                <NcLoadingIcon v-if="loadingMembers" :size="32" />
                <ul v-else class="suggest-wizard__members">
                    <li v-for="m in members" :key="m.userId" class="suggest-wizard__member">
                        <NcCheckboxRadioSwitch
                            :model-value="!!checked[m.userId]"
                            @update:model-value="val => setChecked(m.userId, val)">
                            {{ m.displayName || m.userId }}
                        </NcCheckboxRadioSwitch>
                    </li>
                </ul>
            </div>

            <!-- Step 2: meeting type -->
            <div v-else-if="step === 2" class="suggest-wizard__step">
                <p class="suggest-wizard__intro">{{ t('teamhub', 'What kind of meeting is this?') }}</p>
                <NcCheckboxRadioSwitch
                    type="radio"
                    value="online"
                    :model-value="meetingType"
                    name="meeting-type"
                    @update:model-value="v => meetingType = v">
                    <!-- TRANSLATORS: meeting type — meeting held over video conferencing rather than in a physical room -->
                    {{ t('teamhub', 'Online') }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    type="radio"
                    value="office"
                    :model-value="meetingType"
                    name="meeting-type"
                    @update:model-value="v => meetingType = v">
                    <!-- TRANSLATORS: meeting type — meeting held in a physical office location -->
                    {{ t('teamhub', 'Office') }}
                </NcCheckboxRadioSwitch>
            </div>

            <!-- Step 3: pick a date -->
            <div v-else-if="step === 3" class="suggest-wizard__step">
                <p class="suggest-wizard__intro">
                    {{ t('teamhub', 'Pick a target date. We’ll suggest the best half-days within three working days of it.') }}
                </p>
                <label class="suggest-wizard__label" for="suggest-date">{{ t('teamhub', 'Target date') }}</label>
                <input
                    id="suggest-date"
                    v-model="pickedDate"
                    type="date"
                    :min="todayDate"
                    class="suggest-wizard__input">
            </div>

            <!-- Step 4: suggestions -->
            <div v-else-if="step === 4" class="suggest-wizard__step" aria-live="polite">
                <NcLoadingIcon v-if="loadingSuggestions" :size="32" />
                <template v-else>
                    <p v-if="suggestions.length === 0" class="suggest-wizard__empty">
                        {{ t('teamhub', 'No suitable times found near that date. Try another date or a different meeting type.') }}
                    </p>
                    <ul v-else class="suggest-wizard__suggestions">
                        <li v-for="(s, i) in suggestions" :key="i">
                            <button
                                type="button"
                                class="suggest-wizard__suggestion"
                                :class="{ 'suggest-wizard__suggestion--active': chosenIndex === i }"
                                @click="chooseSuggestion(i)">
                                <span class="suggest-wizard__suggestion-when">{{ formatSuggestion(s) }}</span>
                                <span class="suggest-wizard__suggestion-why">{{ describeSuggestion(s) }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
            </div>

            <!-- Step 5: details + create -->
            <div v-else-if="step === 5" class="suggest-wizard__step">
                <!-- Fine-grained timeslot suggestions for the half-day the
                     user picked in step 4. Loads asynchronously when step 5
                     is entered; user may pick one to pre-fill the time
                     fields, or ignore and set times manually. -->
                <div class="suggest-wizard__timeslots" aria-live="polite">
                    <div class="suggest-wizard__timeslots-header">
                        <span class="suggest-wizard__label">{{ t('teamhub', 'Suggested times') }}</span>
                        <label class="suggest-wizard__duration">
                            {{ t('teamhub', 'Duration') }}
                            <select
                                v-model.number="durationMinutes"
                                class="suggest-wizard__input"
                                @change="fetchTimeslots">
                                <option :value="30">{{ t('teamhub', '30 min') }}</option>
                                <option :value="45">{{ t('teamhub', '45 min') }}</option>
                                <option :value="60">{{ t('teamhub', '1 hour') }}</option>
                                <option :value="90">{{ t('teamhub', '1.5 hours') }}</option>
                                <option :value="120">{{ t('teamhub', '2 hours') }}</option>
                            </select>
                        </label>
                    </div>
                    <NcLoadingIcon v-if="loadingTimeslots" :size="24" />
                    <template v-else>
                        <p v-if="timeslots.length === 0" class="suggest-wizard__empty">
                            {{ t('teamhub', 'No conflict-free windows found in that half-day. Go back and pick a different half-day.') }}
                        </p>
                        <ul v-else class="suggest-wizard__suggestions">
                            <li v-for="(ts, i) in timeslots" :key="`ts-${i}`">
                                <button
                                    type="button"
                                    class="suggest-wizard__suggestion"
                                    :class="{ 'suggest-wizard__suggestion--active': chosenTimeslotIndex === i }"
                                    @click="chooseTimeslot(i)">
                                    <span class="suggest-wizard__suggestion-when">{{ ts.start }}–{{ ts.end }}</span>
                                    <span class="suggest-wizard__suggestion-why">{{ describeTimeslot(ts) }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>
                </div>

                <!-- Step 5 fields all use the same label-on-top pattern
                     so the description block doesn't visually stand out
                     against the others (the NcTextArea component renders
                     with a different label style than NcTextField, which
                     was the source of the previous inconsistency). -->
                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-title">{{ t('teamhub', 'Title') }}</label>
                    <input
                        id="meeting-title"
                        v-model="title"
                        type="text"
                        class="suggest-wizard__input"
                        :class="{ 'suggest-wizard__input--error': !!titleError }">
                    <p v-if="titleError" class="suggest-wizard__error">{{ titleError }}</p>
                </div>

                <!-- Room picker swap: when RoomVox is enabled and has rooms,
                     pick from the room list (which actually books the room
                     by adding it as a CalDAV ATTENDEE on the event). When
                     no rooms are discoverable, fall back to the free-text
                     location input. -->
                <div v-if="loadingRooms" class="suggest-wizard__field">
                    <NcLoadingIcon :size="18" /> {{ t('teamhub', 'Looking up rooms…') }}
                </div>
                <div v-else-if="rooms.length > 0" class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-room">{{ t('teamhub', 'Meeting room') }}</label>
                    <select
                        id="meeting-room"
                        v-model="selectedRoomId"
                        class="suggest-wizard__input">
                        <option value="">{{ t('teamhub', '— No room —') }}</option>
                        <option v-for="r in rooms" :key="r.id" :value="r.id">
                            {{ r.displayName }}
                        </option>
                    </select>
                    <p class="suggest-wizard__hint">
                        {{ t('teamhub', 'Picking a room books it via RoomVox.') }}
                    </p>
                </div>
                <div v-else class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-location">{{ t('teamhub', 'Location (optional)') }}</label>
                    <input
                        id="meeting-location"
                        v-model="location"
                        type="text"
                        class="suggest-wizard__input">
                </div>

                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-description">{{ t('teamhub', 'Description (optional)') }}</label>
                    <textarea
                        id="meeting-description"
                        v-model="description"
                        rows="3"
                        class="suggest-wizard__input suggest-wizard__textarea"></textarea>
                </div>

                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-category">{{ t('teamhub', 'Category (optional)') }}</label>
                    <input
                        id="meeting-category"
                        v-model="categories"
                        type="text"
                        class="suggest-wizard__input">
                    <p class="suggest-wizard__hint">{{ t('teamhub', 'Comma-separated, e.g. Sprint planning, Retro') }}</p>
                </div>

                <div class="suggest-wizard__field">
                    <NcCheckboxRadioSwitch
                        type="checkbox"
                        :model-value="effectiveIncludeTalk"
                        :disabled="meetingType === 'online'"
                        @update:model-value="v => includeTalkChoice = v">
                        {{ t('teamhub', 'Add Talk meeting') }}
                    </NcCheckboxRadioSwitch>
                    <p v-if="meetingType === 'online'" class="suggest-wizard__hint">
                        {{ t('teamhub', 'Always included for online meetings.') }}
                    </p>
                </div>
            </div>

            <!-- Footer nav -->
            <div class="suggest-wizard__footer">
                <NcButton v-if="step > 1" type="tertiary" :disabled="busy" @click="back">
                    {{ t('teamhub', 'Back') }}
                </NcButton>
                <span class="suggest-wizard__spacer" />
                <NcButton
                    v-if="step < 5"
                    type="primary"
                    :disabled="!canAdvance || busy"
                    @click="next">
                    {{ t('teamhub', 'Next') }}
                </NcButton>
                <NcButton
                    v-else
                    type="primary"
                    :disabled="busy"
                    @click="createEvent">
                    {{ t('teamhub', 'Create event') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'

export default {
    name: 'SuggestMeetingWizard',
    components: { NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch },
    props: {
        teamId: { type: String, required: true },
        calendars: { type: Array, default: () => [] },
    },
    data() {
        const today = new Date()
        const iso = today.toISOString().slice(0, 10)
        return {
            step: 1,
            members: [],
            checked: {},
            loadingMembers: false,
            meetingType: 'online',
            pickedDate: iso,
            todayDate: iso,
            loadingSuggestions: false,
            suggestions: [],
            chosenIndex: null,
            // Step 5 event fields
            title: '',
            eventDate: iso,
            eventStart: '10:00',
            eventEnd: '11:00',
            location: '',
            description: '',
            categories: '',
            // User's explicit Talk-meeting choice when it's settable (office meetings).
            // For online meetings the effective value is forced to true via the
            // computed `effectiveIncludeTalk` regardless of this field.
            includeTalkChoice: false,
            titleError: '',
            busy: false,
            // Stage-two: fine-grained timeslot suggestions within the
            // half-day picked at step 4. Loaded when entering step 5.
            durationMinutes: 60,
            loadingTimeslots: false,
            timeslots: [],
            chosenTimeslotIndex: null,
            // Room picker (step 5). Loaded once when the wizard mounts;
            // if RoomVox isn't installed the endpoint returns [] and the
            // wizard transparently falls back to the free-text location.
            loadingRooms: false,
            rooms: [],
            selectedRoomId: '',
        }
    },
    computed: {
        selectedCount() {
            return Object.values(this.checked).filter(Boolean).length
        },
        allSelected() {
            return this.members.length > 0 && this.selectedCount === this.members.length
        },
        selectedIds() {
            return this.members.map(m => m.userId).filter(id => this.checked[id])
        },
        canAdvance() {
            if (this.step === 1) return this.selectedCount > 0
            if (this.step === 3) return !!this.pickedDate
            if (this.step === 4) return this.chosenIndex !== null
            return true
        },
        // For online meetings the Talk meeting checkbox is forced on and disabled —
        // an online meeting without a Talk room is conceptually inconsistent.
        // For office meetings the user's explicit choice is honoured.
        effectiveIncludeTalk() {
            return this.meetingType === 'online' ? true : !!this.includeTalkChoice
        },
    },
    mounted() {
        this.loadMembers()
        this.loadRooms()
    },
    methods: {
        t,
        n,
        async loadMembers() {
            this.loadingMembers = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/members/all`)
                )
                const list = Array.isArray(data) ? data : (Array.isArray(data?.members) ? data.members : [])
                // The organiser is on the event by definition (we emit them
                // as ROLE=CHAIR server-side). Removing them from the picker
                // prevents self-invite — both a UX footgun and the root of
                // a real Sabre UID-collision when the organiser is also an
                // ATTENDEE on a calendar their principal can write to.
                // Case-insensitive match because external user backends
                // (LDAP) have been known to return different casings between
                // the users table and the wizard's member list.
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
            // Best-effort: the rooms endpoint always succeeds (returning
            // [] when RoomVox isn't installed), so failure here only
            // happens on transport errors. In that case stay silent and
            // fall back to the free-text location field.
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
        back() {
            if (this.step > 1) this.step -= 1
        },
        async next() {
            if (!this.canAdvance) return
            if (this.step === 3) {
                // Moving into suggestions — fetch them.
                this.step = 4
                await this.fetchSuggestions()
                return
            }
            this.step += 1
        },
        async fetchSuggestions() {
            this.loadingSuggestions = true
            this.suggestions = []
            this.chosenIndex = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/presence/suggest-times`),
                    {
                        params: {
                            date: this.pickedDate,
                            type: this.meetingType,
                            attendees: this.selectedIds.join(','),
                        },
                    }
                )
                this.suggestions = Array.isArray(data?.suggestions) ? data.suggestions : []
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? t('teamhub', 'Could not get suggestions: {error}', { error: msg })
                    : t('teamhub', 'Could not get suggestions'))
            } finally {
                this.loadingSuggestions = false
            }
        },
        chooseSuggestion(i) {
            this.chosenIndex = i
            const s = this.suggestions[i]
            if (!s) return
            // Default a sensible 1-hour block within the chosen half-day:
            // morning -> 10:00-11:00, afternoon -> 14:00-15:00, organiser-local.
            // The stage-two timeslot fetcher (below) may overwrite these with
            // a conflict-free window the user explicitly picks.
            this.eventDate = s.date
            if (s.half === 0) {
                this.eventStart = '10:00'
                this.eventEnd = '11:00'
            } else {
                this.eventStart = '14:00'
                this.eventEnd = '15:00'
            }
            this.step = 5
            // Fire-and-forget the stage-two fetch; the step renders
            // immediately and the suggestions populate when they arrive.
            this.fetchTimeslots()
        },
        async fetchTimeslots() {
            // Stage-two fetch: requires step 4 to have produced a chosen
            // suggestion. Guard so a manual durationMinutes change before
            // a half-day is chosen is a no-op.
            const s = this.suggestions[this.chosenIndex]
            if (!s) {
                this.timeslots = []
                this.chosenTimeslotIndex = null
                return
            }
            this.loadingTimeslots = true
            this.timeslots = []
            this.chosenTimeslotIndex = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/presence/suggest-timeslots`),
                    {
                        params: {
                            date: s.date,
                            half: s.half,
                            duration: this.durationMinutes,
                            attendees: this.selectedIds.join(','),
                            // Pass the picked half-day's meeting context down so
                            // each timeslot can be labelled with the best
                            // building (no recomputation needed — the half-day
                            // scorer already worked this out).
                            type: this.meetingType,
                            buildingName: (this.meetingType === 'office' && s.bestBuildingName) ? s.bestBuildingName : '',
                        },
                    }
                )
                this.timeslots = Array.isArray(data?.suggestions) ? data.suggestions : []
            } catch (e) {
                // Non-blocking: timeslot suggestions are an aid, not a
                // gate. On failure the user simply sets times manually.
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? t('teamhub', 'Could not get timeslot suggestions: {error}', { error: msg })
                    : t('teamhub', 'Could not get timeslot suggestions'))
            } finally {
                this.loadingTimeslots = false
            }
        },
        chooseTimeslot(i) {
            this.chosenTimeslotIndex = i
            const ts = this.timeslots[i]
            if (!ts) return
            this.eventStart = ts.start
            this.eventEnd = ts.end
        },
        describeTimeslot(ts) {
            // TRANSLATORS: {available} of {total} attendees available in this concrete time window
            let line = t('teamhub', '{available} of {total} available', {
                available: ts.availableCount,
                total: ts.attendeeCount,
            })
            if (ts.conflictCount > 0) {
                line += ' · ' + n('teamhub', '%n has a calendar conflict', '%n have a calendar conflict', ts.conflictCount, { n: ts.conflictCount })
            }
            if (ts.bestBuildingName) {
                // TRANSLATORS: appended to a timeslot suggestion when the meeting is in-office; {building} is the building name with the most attendees on site
                line += ' · ' + t('teamhub', 'most suitable location: {building}', { building: ts.bestBuildingName })
            }
            return line
        },
        formatSuggestion(s) {
            const d = new Date(`${s.date}T00:00:00`)
            const dayLabel = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
            const half = s.half === 0 ? t('teamhub', 'morning') : t('teamhub', 'afternoon')
            return `${dayLabel} — ${half}`
        },
        describeSuggestion(s) {
            if (this.meetingType === 'office') {
                const building = s.bestBuildingName || t('teamhub', 'an office')
                // TRANSLATORS: {available}/{total} attendees free, {count} at the same building (named {building})
                let line = t('teamhub', '{available} of {total} available, {count} at {building}', {
                    available: s.availableCount,
                    total: s.attendeeCount,
                    count: s.bestBuildingCount,
                    building,
                })
                if (s.remoteCount > 0) {
                    line += ' · ' + n('teamhub', '%n could join online', '%n could join online', s.remoteCount, { n: s.remoteCount })
                }
                return line
            }
            let line = t('teamhub', '{available} of {total} available', {
                available: s.availableCount,
                total: s.attendeeCount,
            })
            if (s.conflictCount > 0) {
                line += ' · ' + n('teamhub', '%n has a conflict', '%n have a conflict', s.conflictCount, { n: s.conflictCount })
            }
            return line
        },
        async createEvent() {
            this.titleError = ''
            if (!this.title.trim()) {
                this.titleError = t('teamhub', 'Title is required')
                return
            }
            this.busy = true
            try {
                // eventDate/eventStart/eventEnd are set by chooseSuggestion (step 4)
                // and refined by chooseTimeslot (step 5). The user no longer edits
                // them directly — the wizard locks them to the workflow.
                const startDt = new Date(`${this.eventDate}T${this.eventStart}:00`)
                const endDt = new Date(`${this.eventDate}T${this.eventEnd}:00`)
                // Resolve the picked room (if any). When the picker isn't
                // visible (no rooms), selectedRoomId stays empty and the
                // free-text location field is used instead.
                const picked = this.rooms.find(r => r.id === this.selectedRoomId)
                const payload = {
                    title: this.title.trim(),
                    start: startDt.toISOString(),
                    end: endDt.toISOString(),
                    // When a room is picked, the free-text location field
                    // wasn't shown — its value should not be sent. The
                    // backend derives LOCATION from roomName.
                    location: picked ? '' : this.location.trim(),
                    description: this.description.trim(),
                    categories: this.categories.trim(),
                    includeTalk: this.effectiveIncludeTalk ? 1 : 0,
                    roomEmail: picked ? picked.email : '',
                    roomName: picked ? picked.displayName : '',
                    // roomId is the RoomVox-internal id (needed for the
                    // /api/v1/rooms/{id}/bookings call). Empty when no
                    // room was picked.
                    roomId: picked ? picked.id : '',
                    // The wizard's selected-members list becomes the
                    // attendee list on the event so the meeting lands in
                    // each invitee's personal calendar with a 15-min
                    // reminder (handled server-side).
                    attendees: this.selectedIds.join(','),
                    calendarId: this.calendars[0]?.id ?? null,
                }
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events`),
                    payload
                )
                showSuccess(t('teamhub', 'Event added to calendar'))
                this.$emit('created')
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? t('teamhub', 'Failed to add event: {error}', { error: msg })
                    : t('teamhub', 'Failed to add event'))
            } finally {
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.suggest-wizard {
    padding: 24px;
    max-width: 560px;
    min-width: 360px;
}
.suggest-wizard__heading {
    margin: 0 0 16px;
    font-size: 1.25rem;
}
.suggest-wizard__intro {
    color: var(--color-text-maxcontrast);
    margin-bottom: 12px;
}
.suggest-wizard__toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}
.suggest-wizard__count {
    color: var(--color-text-maxcontrast);
    font-size: 0.9rem;
}
.suggest-wizard__members {
    max-height: 320px;
    overflow-y: auto;
}
.suggest-wizard__label {
    display: block;
    margin: 8px 0 4px;
    font-weight: 600;
}
.suggest-wizard__input {
    width: 100%;
    box-sizing: border-box;
    /* NC-native control look — match NcTextField's resting state so the
       step-5 fields read as a single, consistent group. */
    padding: 7px 10px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font: inherit;
    line-height: 1.4;
}
.suggest-wizard__input:focus,
.suggest-wizard__input:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
}
.suggest-wizard__input--error {
    border-color: var(--color-error);
}
.suggest-wizard__textarea {
    resize: vertical;
    min-height: 72px;
    font-family: inherit;
}
.suggest-wizard__error {
    color: var(--color-error-text);
    font-size: 0.85rem;
    margin: 4px 0 0;
}
.suggest-wizard__times {
    display: flex;
    gap: 12px;
}
.suggest-wizard__times > div {
    flex: 1;
}
.suggest-wizard__suggestions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.suggest-wizard__suggestion {
    width: 100%;
    text-align: left;
    padding: 12px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.suggest-wizard__suggestion:hover {
    background: var(--color-background-hover);
}
.suggest-wizard__suggestion:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.suggest-wizard__suggestion--active {
    border-color: var(--color-primary-element);
}
.suggest-wizard__suggestion-when {
    font-weight: 600;
}
.suggest-wizard__suggestion-why {
    color: var(--color-text-maxcontrast);
    font-size: 0.9rem;
}
.suggest-wizard__empty {
    color: var(--color-text-maxcontrast);
}
.suggest-wizard__timeslots {
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--color-border);
}
.suggest-wizard__timeslots-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
}
.suggest-wizard__duration {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: var(--color-text-maxcontrast);
}
.suggest-wizard__duration select {
    width: auto;
}
.suggest-wizard__field {
    margin-top: 12px;
}
.suggest-wizard__hint {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
    margin: 4px 0 0 24px;
}
.suggest-wizard__footer {
    display: flex;
    align-items: center;
    margin-top: 24px;
}
.suggest-wizard__spacer {
    flex: 1;
}
</style>
