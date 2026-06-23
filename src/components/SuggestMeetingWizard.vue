<template>
    <NcModal size="normal" @close="$emit('close')">
        <div class="suggest-wizard">
            <h2 class="suggest-wizard__heading">
                <AccountGroupIcon v-if="!lockAttendees" :size="20" aria-hidden="true" />
                <CalendarClock v-else :size="20" aria-hidden="true" />
                {{ headingLabel }}
            </h2>

            <!-- Step indicator — two compact pills -->
            <ol class="suggest-wizard__stepbar" aria-label="meeting wizard progress">
                <li :class="['suggest-wizard__stepbar-item', { 'suggest-wizard__stepbar-item--active': step === 1 }]">
                    {{ t('teamhub', '1. Who & When') }}
                </li>
                <li :class="['suggest-wizard__stepbar-item', { 'suggest-wizard__stepbar-item--active': step === 2 }]">
                    {{ t('teamhub', '2. Setup') }}
                </li>
            </ol>

            <!-- ────────────────────────────────────────────────────────────────
                 STEP 1 — Who & When
                 Combines the legacy steps 1 (attendees), 2 (meeting type),
                 3 (target date), and 4 (suggestions) into a single screen.
                 Suggestions auto-load when date or meeting-type changes.
            ──────────────────────────────────────────────────────────────── -->
            <div v-if="step === 1" class="suggest-wizard__step">

                <!-- Title — moved up so it carries through the whole flow -->
                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-title">
                        {{ t('teamhub', 'Title') }}
                    </label>
                    <input
                        id="meeting-title"
                        v-model="title"
                        type="text"
                        class="suggest-wizard__input"
                        :class="{ 'suggest-wizard__input--error': !!titleError }"
                        :placeholder="t('teamhub', 'e.g. Weekly sync')" />
                    <p v-if="titleError" class="suggest-wizard__error">{{ titleError }}</p>
                </div>

                <!-- Two-column row: attendees on the left, schedule controls on the right -->
                <div class="suggest-wizard__two-col">
                    <!-- Left: attendees -->
                    <section class="suggest-wizard__col" :aria-label="t('teamhub', 'Attendees')">
                        <div v-if="prefillBanner" class="suggest-wizard__prefill-banner" role="status">
                            {{ prefillBanner }}
                        </div>
                        <div class="suggest-wizard__col-header">
                            <span class="suggest-wizard__label">{{ t('teamhub', 'Attendees') }}</span>
                            <button
                                v-if="!lockAttendees && members.length > 0"
                                type="button"
                                class="suggest-wizard__toolbtn"
                                @click="toggleSelectAll">
                                {{ allSelected ? t('teamhub', 'Clear all') : t('teamhub', 'Select all') }}
                            </button>
                        </div>
                        <p v-if="!lockAttendees" class="suggest-wizard__hint suggest-wizard__count">
                            {{ n('teamhub', '%n member selected', '%n members selected', selectedCount, { n: selectedCount }) }}
                        </p>
                        <NcLoadingIcon v-if="loadingMembers" :size="24" />
                        <ul v-else class="suggest-wizard__members">
                            <li
                                v-for="m in displayedMembers"
                                :key="m.userId"
                                class="suggest-wizard__member">
                                <NcCheckboxRadioSwitch
                                    :model-value="!!checked[m.userId]"
                                    :disabled="lockAttendees"
                                    @update:model-value="val => setChecked(m.userId, val)">
                                    {{ m.displayName || m.userId }}
                                </NcCheckboxRadioSwitch>
                            </li>
                        </ul>
                    </section>

                    <!-- Right: schedule (type + date + suggestions) -->
                    <section class="suggest-wizard__col" :aria-label="t('teamhub', 'Schedule')">
                        <span class="suggest-wizard__label">{{ t('teamhub', 'Meeting type') }}</span>
                        <div class="suggest-wizard__pills" role="radiogroup" :aria-label="t('teamhub', 'Meeting type')">
                            <button
                                v-for="opt in [
                                    { v: 'online', label: t('teamhub', 'Online') },
                                    { v: 'office', label: t('teamhub', 'Office') },
                                ]"
                                :key="opt.v"
                                type="button"
                                class="suggest-wizard__pill"
                                :class="{ 'suggest-wizard__pill--selected': meetingType === opt.v }"
                                :aria-pressed="meetingType === opt.v ? 'true' : 'false'"
                                @click="onMeetingTypeChange(opt.v)">
                                {{ opt.label }}
                            </button>
                        </div>

                        <!-- Presence-driven suggestion flow (default) -->
                        <template v-if="presenceAvailable">
                            <label class="suggest-wizard__label" for="suggest-date">
                                {{ t('teamhub', 'Target date') }}
                            </label>
                            <input
                                id="suggest-date"
                                v-model="pickedDate"
                                type="date"
                                :min="todayDate"
                                class="suggest-wizard__input"
                                @change="onTargetDateChange" />
                            <p class="suggest-wizard__hint">
                                {{ t('teamhub', 'We’ll suggest the best half-days within three working days of this date.') }}
                            </p>
                        </template>

                        <!-- Manual schedule fallback — when the presence module
                             is off (globally or for this team) we can't compute
                             suggestions, so the user picks date/start/end by
                             hand. eventDate / eventStart / eventEnd are the
                             same fields the submit path reads from, so no
                             extra plumbing is needed. -->
                        <template v-else>
                            <label class="suggest-wizard__label" for="manual-date">
                                {{ t('teamhub', 'Date') }}
                            </label>
                            <input
                                id="manual-date"
                                v-model="eventDate"
                                type="date"
                                :min="todayDate"
                                class="suggest-wizard__input" />
                            <div class="suggest-wizard__time-row">
                                <div class="suggest-wizard__time-col">
                                    <label class="suggest-wizard__label" for="manual-start">
                                        {{ t('teamhub', 'Start time') }}
                                    </label>
                                    <input
                                        id="manual-start"
                                        v-model="eventStart"
                                        type="time"
                                        class="suggest-wizard__input" />
                                </div>
                                <div class="suggest-wizard__time-col">
                                    <label class="suggest-wizard__label" for="manual-end">
                                        {{ t('teamhub', 'End time') }}
                                    </label>
                                    <input
                                        id="manual-end"
                                        v-model="eventEnd"
                                        type="time"
                                        class="suggest-wizard__input"
                                        :class="{ 'suggest-wizard__input--error': eventEnd && eventStart && eventEnd <= eventStart }" />
                                </div>
                            </div>
                            <p v-if="eventEnd && eventStart && eventEnd <= eventStart" class="suggest-wizard__error">
                                {{ t('teamhub', 'End time must be after start time') }}
                            </p>
                            <p class="suggest-wizard__hint suggest-wizard__hint--info">
                                {{ t('teamhub', 'Enable the Presence module for date/time suggestions.') }}
                            </p>
                        </template>

                        <!-- Suggestions list — auto-loaded. Only relevant in
                             the presence-driven flow. -->
                        <div v-if="presenceAvailable && selectedCount > 0" class="suggest-wizard__suggest-block" aria-live="polite">
                            <NcLoadingIcon v-if="loadingSuggestions" :size="20" />
                            <template v-else>
                                <p v-if="suggestions.length === 0" class="suggest-wizard__empty">
                                    {{ t('teamhub', 'No suitable half-days near that date. Try another date or meeting type.') }}
                                </p>
                                <ul v-else class="suggest-wizard__suggestions">
                                    <li v-for="(s, i) in suggestions" :key="`half-${i}`">
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

                                <!-- Inline timeslot picker, shown after a half-day is selected -->
                                <div v-if="chosenIndex !== null" class="suggest-wizard__timeslots-inline" aria-live="polite">
                                    <div class="suggest-wizard__timeslots-header">
                                        <span class="suggest-wizard__label">{{ t('teamhub', 'Pick a time') }}</span>
                                        <label class="suggest-wizard__duration">
                                            {{ t('teamhub', 'Duration') }}
                                            <select
                                                v-model.number="durationMinutes"
                                                class="suggest-wizard__input suggest-wizard__input--inline"
                                                @change="fetchTimeslots">
                                                <option :value="30">{{ t('teamhub', '30 min') }}</option>
                                                <option :value="45">{{ t('teamhub', '45 min') }}</option>
                                                <option :value="60">{{ t('teamhub', '1 hour') }}</option>
                                                <option :value="90">{{ t('teamhub', '1.5 hours') }}</option>
                                                <option :value="120">{{ t('teamhub', '2 hours') }}</option>
                                            </select>
                                        </label>
                                    </div>
                                    <NcLoadingIcon v-if="loadingTimeslots" :size="20" />
                                    <template v-else>
                                        <p v-if="timeslots.length === 0" class="suggest-wizard__empty">
                                            {{ t('teamhub', 'No conflict-free windows in that half-day.') }}
                                        </p>
                                        <ul v-else class="suggest-wizard__suggestions">
                                            <li v-for="(ts, i) in timeslots" :key="`ts-${i}`">
                                                <button
                                                    type="button"
                                                    class="suggest-wizard__suggestion suggest-wizard__suggestion--compact"
                                                    :class="{ 'suggest-wizard__suggestion--active': chosenTimeslotIndex === i }"
                                                    @click="chooseTimeslot(i)">
                                                    <span class="suggest-wizard__suggestion-when">{{ ts.start }}–{{ ts.end }}</span>
                                                    <span class="suggest-wizard__suggestion-why">{{ describeTimeslot(ts) }}</span>
                                                </button>
                                            </li>
                                        </ul>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <p v-else-if="presenceAvailable" class="suggest-wizard__hint">
                            {{ t('teamhub', 'Pick at least one attendee to see suggested times.') }}
                        </p>
                    </section>
                </div>
            </div>

            <!-- ────────────────────────────────────────────────────────────────
                 STEP 2 — Setup
                 Description, room, Talk, notes, agenda options. Submit.
            ──────────────────────────────────────────────────────────────── -->
            <div v-if="step === 2" class="suggest-wizard__step">

                <!-- Summary banner: what we already have from step 1 -->
                <div class="suggest-wizard__summary">
                    <div class="suggest-wizard__summary-row">
                        <strong>{{ title || t('teamhub', '(untitled)') }}</strong>
                    </div>
                    <div class="suggest-wizard__summary-row">
                        <CalendarClock :size="14" aria-hidden="true" />
                        {{ formatChosenWhen() }}
                    </div>
                    <div class="suggest-wizard__summary-row">
                        <AccountGroupIcon :size="14" aria-hidden="true" />
                        {{ n('teamhub', '%n attendee', '%n attendees', selectedCount, { n: selectedCount }) }}
                    </div>
                </div>

                <!-- Description -->
                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-description">
                        {{ t('teamhub', 'Description (optional)') }}
                    </label>
                    <textarea
                        id="meeting-description"
                        v-model="description"
                        rows="3"
                        class="suggest-wizard__input suggest-wizard__textarea"></textarea>
                </div>

                <!-- Room picker — only when RoomVox / CRM rooms are available -->
                <div v-if="loadingRooms" class="suggest-wizard__field">
                    <NcLoadingIcon :size="18" /> {{ t('teamhub', 'Looking up rooms…') }}
                </div>
                <div v-else-if="rooms.length > 0" class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-room">
                        {{ t('teamhub', 'Meeting room') }}
                    </label>
                    <select
                        id="meeting-room"
                        v-model="selectedRoomId"
                        class="suggest-wizard__input">
                        <option value="">{{ t('teamhub', '— No room —') }}</option>
                        <option v-for="r in rooms" :key="r.id" :value="r.id">
                            {{ r.displayName }}
                        </option>
                    </select>
                    <p v-if="selectedRoomId" class="suggest-wizard__hint">
                        {{ t('teamhub', 'Picking a room books it via RoomVox.') }}
                    </p>
                </div>
                <div v-else class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-location">
                        {{ t('teamhub', 'Location (optional)') }}
                    </label>
                    <input
                        id="meeting-location"
                        v-model="location"
                        type="text"
                        class="suggest-wizard__input" />
                </div>

                <!-- Category — kept for the legacy approver-meeting flow; optional otherwise -->
                <div class="suggest-wizard__field">
                    <label class="suggest-wizard__label" for="meeting-category">
                        {{ t('teamhub', 'Category (optional)') }}
                    </label>
                    <input
                        id="meeting-category"
                        v-model="categories"
                        type="text"
                        class="suggest-wizard__input" />
                </div>

                <!-- Talk toggle -->
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

                <!-- Meeting notes section — only in the new Add Meeting flow.
                     The approver-meeting flow (lockAttendees=true) keeps the
                     simpler /calendar/events path with no notes file. -->
                <div v-if="!lockAttendees" class="suggest-wizard__notes-block">
                    <div class="suggest-wizard__notes-header">
                        <FileDocumentIcon :size="16" aria-hidden="true" />
                        <h3 class="suggest-wizard__notes-title">{{ t('teamhub', 'Meeting notes') }}</h3>
                    </div>
                    <p class="suggest-wizard__hint suggest-wizard__hint--block">
                        {{ t('teamhub', 'A notes file is created in the team Meetings folder and linked from the calendar event.') }}
                    </p>

                    <div class="suggest-wizard__field">
                        <label class="suggest-wizard__label" for="meeting-filename">
                            {{ t('teamhub', 'Notes filename') }}
                        </label>
                        <input
                            id="meeting-filename"
                            v-model="filename"
                            type="text"
                            class="suggest-wizard__input" />
                        <p class="suggest-wizard__hint">
                            {{ t('teamhub', 'Saved as {filename}.md', { filename: filename || '…' }) }}
                        </p>
                    </div>

                    <!-- Agenda sources -->
                    <div class="suggest-wizard__agenda-grid">
                        <NcCheckboxRadioSwitch
                            v-model="askAgenda"
                            type="checkbox"
                            :disabled="!effectiveIncludeTalk">
                            {{ t('teamhub', 'Ask team for agenda items') }}
                        </NcCheckboxRadioSwitch>
                        <NcCheckboxRadioSwitch
                            v-model="includeOverdueTasks"
                            type="checkbox">
                            {{ t('teamhub', 'Add overdue Deck tasks') }}
                        </NcCheckboxRadioSwitch>
                        <NcCheckboxRadioSwitch
                            v-model="includeUnscheduledTasks"
                            type="checkbox">
                            {{ t('teamhub', 'Add unscheduled Deck tasks') }}
                        </NcCheckboxRadioSwitch>
                        <NcCheckboxRadioSwitch
                            v-model="includeProposals"
                            type="checkbox">
                            {{ t('teamhub', 'Discuss proposals awaiting a decision') }}
                        </NcCheckboxRadioSwitch>
                    </div>

                    <!-- Category multi-select for proposals. Only shown when
                         the master toggle is on AND we successfully loaded a
                         non-empty list of categories. Default selection =
                         all categories ticked. Empty selection sends no
                         filter — UI hint makes that explicit. -->
                    <div v-if="includeProposals && proposalCategories.length > 0" class="suggest-wizard__cat-block">
                        <div class="suggest-wizard__cat-header">
                            <span class="suggest-wizard__label suggest-wizard__label--inline">
                                {{ t('teamhub', 'From categories') }}
                            </span>
                            <button
                                type="button"
                                class="suggest-wizard__toolbtn"
                                @click="toggleAllProposalCategories">
                                {{ selectedProposalCategories.length === proposalCategories.length
                                    ? t('teamhub', 'Clear all')
                                    : t('teamhub', 'Select all') }}
                            </button>
                        </div>
                        <div class="suggest-wizard__cat-chips" role="group" :aria-label="t('teamhub', 'Proposal categories')">
                            <button
                                v-for="cat in proposalCategories"
                                :key="cat"
                                type="button"
                                class="suggest-wizard__chip"
                                :class="{ 'suggest-wizard__chip--selected': selectedProposalCategories.includes(cat) }"
                                :aria-pressed="selectedProposalCategories.includes(cat) ? 'true' : 'false'"
                                @click="toggleProposalCategory(cat)">
                                {{ cat }}
                            </button>
                        </div>
                        <p class="suggest-wizard__hint">
                            {{ proposalCategoryHint }}
                        </p>
                    </div>

                    <p v-if="askAgenda && !effectiveIncludeTalk" class="suggest-wizard__hint suggest-wizard__hint--warn">
                        {{ t('teamhub', 'Ask-for-agenda needs Talk — enable “Add Talk meeting” above.') }}
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
                    v-if="step < 2"
                    type="primary"
                    :disabled="!canAdvance || busy"
                    @click="next">
                    {{ t('teamhub', 'Next') }}
                </NcButton>
                <NcButton
                    v-else
                    type="primary"
                    :disabled="busy"
                    @click="submit">
                    <template #icon>
                        <NcLoadingIcon v-if="busy" :size="18" />
                    </template>
                    {{ busy ? t('teamhub', 'Creating…') : submitLabel }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { mapState } from 'vuex'
import { NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'

export default {
    name: 'SuggestMeetingWizard',
    components: { NcModal, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch,
        AccountGroupIcon, CalendarClock, FileDocumentIcon },
    props: {
        teamId: { type: String, required: true },
        calendars: { type: Array, default: () => [] },
        // Resources object — used to read the team Talk room token so the
        // /meetings call can post the agenda-request message without a
        // second lookup, and so the Talk toggle has accurate room info.
        resources: { type: Object, default: () => ({}) },
        // Prefill props — used by the approver-meeting flow on a proposal.
        prefilledAttendees:   { type: Array,  default: () => [] },
        prefilledTitle:       { type: String, default: '' },
        prefilledDescription: { type: String, default: '' },
        prefilledCategory:    { type: String, default: '' },
        prefillBanner:        { type: String, default: '' },
        // When true (approver-meeting flow): attendees locked, notes block
        // hidden, submits to /calendar/events. When false (Add Meeting):
        // notes always created, submits to /meetings.
        lockAttendees:        { type: Boolean, default: false },
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
            // Step-2 event fields — initialised from prefill props (empty by default).
            title: this.prefilledTitle || '',
            eventDate: iso,
            eventStart: '10:00',
            eventEnd: '11:00',
            location: '',
            description: this.prefilledDescription || '',
            categories: this.prefilledCategory || '',
            filename: '',
            includeTalkChoice: false,
            titleError: '',
            busy: false,
            durationMinutes: 60,
            loadingTimeslots: false,
            timeslots: [],
            chosenTimeslotIndex: null,
            loadingRooms: false,
            rooms: [],
            selectedRoomId: '',
            // New Add-Meeting features (not surfaced in approver-meeting flow):
            askAgenda: false,
            includeOverdueTasks: false,
            includeUnscheduledTasks: false,
            includeProposals: false,
            // Proposal category filter — list of category names loaded from
            // the decisions/categories endpoint; selectedProposalCategories
            // is the user's pick (empty = no filter = all categories).
            loadingProposalCategories: false,
            proposalCategories: [],
            selectedProposalCategories: [],
        }
    },
    computed: {
        ...mapState(['presenceModuleEnabled', 'presenceConfig']),
        /**
         * Presence-driven suggestions only make sense when the module is
         * enabled globally AND for this team. Otherwise we silently skip
         * the suggestion API and fall back to a manual date/start/end
         * picker, with a one-line hint pointing the user at the setting.
         */
        presenceAvailable() {
            return !!this.presenceModuleEnabled
                && !!(this.presenceConfig && this.presenceConfig.presence_enabled)
        },
        headingLabel() {
            return this.lockAttendees
                ? t('teamhub', 'Meeting wizard')
                : t('teamhub', 'Add Meeting')
        },
        submitLabel() {
            return this.lockAttendees
                ? t('teamhub', 'Create event')
                : t('teamhub', 'Create meeting')
        },
        selectedCount() {
            return Object.values(this.checked).filter(Boolean).length
        },
        allSelected() {
            return this.members.length > 0 && this.selectedCount === this.members.length
        },
        selectedIds() {
            return this.members.map(m => m.userId).filter(id => this.checked[id])
        },
        displayedMembers() {
            if (this.lockAttendees) {
                return this.members.filter(m => this.checked[m.userId])
            }
            return this.members
        },
        canAdvance() {
            if (this.step === 1) {
                if (!this.title.trim()) return false
                // Approver-meeting and full Add Meeting both need at least
                // one attendee on the invite list.
                if (this.selectedCount === 0) return false
                if (this.presenceAvailable) {
                    // Suggestion-driven flow: user must have picked a
                    // half-day AND a concrete timeslot inside it.
                    return this.chosenIndex !== null
                        && this.chosenTimeslotIndex !== null
                }
                // Manual schedule fallback: user typed date + valid times.
                if (!this.eventDate) return false
                if (!this.eventStart || !this.eventEnd) return false
                return this.eventEnd > this.eventStart
            }
            return true
        },
        effectiveIncludeTalk() {
            return this.meetingType === 'online' ? true : !!this.includeTalkChoice
        },
        /**
         * Helper text below the proposal category chips. We surface three
         * states explicitly so "no proposals will appear" doesn't surprise
         * anyone after they've ticked the master toggle.
         */
        /**
         * True when the user has narrowed the selection to a real subset.
         * We only send the filter in that case — "all" and "none" both fall
         * back to the no-filter default on the backend (every awaiting
         * proposal). This keeps the payload small and the intent explicit.
         */
        shouldSendProposalCategoryFilter() {
            const total  = this.proposalCategories.length
            const picked = this.selectedProposalCategories.length
            return picked > 0 && picked < total
        },
        proposalCategoryHint() {
            const total = this.proposalCategories.length
            const picked = this.selectedProposalCategories.length
            if (picked === total) {
                return t('teamhub', 'All categories — every awaiting proposal will be added.')
            }
            if (picked === 0) {
                return t('teamhub', 'No categories picked — every awaiting proposal will be added.')
            }
            return n(
                'teamhub',
                'Only proposals in the %n selected category will be added.',
                'Only proposals in the %n selected categories will be added.',
                picked,
                { n: picked }
            )
        },
    },
    watch: {
        // Auto-derive filename from title + meeting date until the user
        // touches it directly (any manual edit detaches it from the title).
        title() { this.updateFilename() },
        eventDate() { this.updateFilename() },
    },
    mounted() {
        this.loadMembers()
        this.loadRooms()
        this.updateFilename()
        // Only the full Add Meeting flow can use the proposal category
        // filter; the approver-meeting flow has no notes block at all.
        if (!this.lockAttendees) {
            this.loadProposalCategories()
        }
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
                const me = (getCurrentUser()?.uid || '').toLowerCase()
                this.members = me
                    ? list.filter(m => (m.userId || '').toLowerCase() !== me)
                    : list

                if (Array.isArray(this.prefilledAttendees) && this.prefilledAttendees.length > 0) {
                    const presetIds = new Set(
                        this.prefilledAttendees
                            .map(id => (id || '').toLowerCase())
                            .filter(Boolean),
                    )
                    const next = { ...this.checked }
                    this.members.forEach(m => {
                        const uid = (m.userId || '').toLowerCase()
                        if (presetIds.has(uid)) {
                            next[m.userId] = true
                            presetIds.delete(uid)
                        }
                    })
                    this.checked = next
                } else if (!this.lockAttendees) {
                    // Default for the new Add Meeting flow: pre-select everyone
                    // so the common case ("invite the whole team") doesn't need
                    // the user to click "Select all" first.
                    const next = {}
                    for (const m of this.members) next[m.userId] = true
                    this.checked = next
                }
                // Trigger an initial suggestions fetch now that we have
                // members + the default date + meeting type. Skipped when
                // presence isn't available — the manual schedule pickers
                // already have sensible defaults from data().
                if (this.selectedCount > 0 && this.presenceAvailable) {
                    this.fetchSuggestions()
                }
            } catch (e) {
                showError(t('teamhub', 'Could not load team members'))
            } finally {
                this.loadingMembers = false
            }
        },

        async loadRooms() {
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

        /**
         * Load the team's decision categories so the user can scope the
         * proposals agenda section. Default selection is all categories
         * ticked. Two endpoints can satisfy this — accept either shape:
         *  - /decisions/categories returns { categories: [string, …] }
         *    (the distinct set already present on the team's decisions —
         *    matches the natural source of truth for the filter).
         *  - /decisions/manage/categories returns { items: [{name, …}, …] }
         *    (the predefined-category list — kept as a fallback shape so
         *    a future endpoint swap doesn't silently break this UI).
         * Endpoint failure (404 when the module isn't enabled for this
         * team, network errors, etc.) degrades silently: the chip block
         * just doesn't render.
         */
        async loadProposalCategories() {
            this.loadingProposalCategories = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/decisions/categories`)
                )
                let names = []
                if (Array.isArray(data?.categories)) {
                    names = data.categories
                        .map(c => String(c || '').trim())
                        .filter(Boolean)
                } else if (Array.isArray(data?.items)) {
                    names = data.items
                        .map(c => String(c?.name || '').trim())
                        .filter(Boolean)
                }
                // Dedupe + stable sort so the chip order doesn't shuffle
                // between calls.
                this.proposalCategories = [...new Set(names)].sort((a, b) =>
                    a.localeCompare(b, undefined, { sensitivity: 'base' }))
                // Default: all ticked. Reapplied every load (re-mounting
                // the modal resets the user's previous selection — that's
                // intentional, the default-all expectation outweighs the
                // remembered subset).
                this.selectedProposalCategories = [...this.proposalCategories]
            } catch (e) {
                this.proposalCategories = []
                this.selectedProposalCategories = []
            } finally {
                this.loadingProposalCategories = false
            }
        },

        toggleProposalCategory(name) {
            const idx = this.selectedProposalCategories.indexOf(name)
            if (idx === -1) {
                this.selectedProposalCategories = [...this.selectedProposalCategories, name]
            } else {
                this.selectedProposalCategories = this.selectedProposalCategories.filter(c => c !== name)
            }
        },
        toggleAllProposalCategories() {
            if (this.selectedProposalCategories.length === this.proposalCategories.length) {
                this.selectedProposalCategories = []
            } else {
                this.selectedProposalCategories = [...this.proposalCategories]
            }
        },

        setChecked(userId, val) {
            this.checked = { ...this.checked, [userId]: !!val }
            this.refetchSuggestionsSoon()
        },
        toggleSelectAll() {
            const target = !this.allSelected
            const next = {}
            for (const m of this.members) next[m.userId] = target
            this.checked = next
            this.refetchSuggestionsSoon()
        },
        onMeetingTypeChange(v) {
            this.meetingType = v
            this.refetchSuggestionsSoon()
        },
        onTargetDateChange() {
            this.refetchSuggestionsSoon()
        },
        // Debounce auto-fetch so rapid checkbox toggles don't fire a request
        // per click. 250 ms is a comfortable interaction window. No-op when
        // presence isn't available — the manual schedule pickers are the
        // only source of truth in that case.
        refetchSuggestionsSoon() {
            if (!this.presenceAvailable) return
            if (this.selectedCount === 0) {
                this.suggestions = []
                this.chosenIndex = null
                this.timeslots = []
                this.chosenTimeslotIndex = null
                return
            }
            clearTimeout(this._suggestTimer)
            this._suggestTimer = setTimeout(() => this.fetchSuggestions(), 250)
        },

        back() {
            if (this.step > 1) this.step -= 1
        },
        next() {
            if (this.canAdvance) this.step = 2
        },

        async fetchSuggestions() {
            this.loadingSuggestions = true
            this.suggestions = []
            this.chosenIndex = null
            this.timeslots = []
            this.chosenTimeslotIndex = null
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
            this.eventDate = s.date
            if (s.half === 0) {
                this.eventStart = '10:00'
                this.eventEnd = '11:00'
            } else {
                this.eventStart = '14:00'
                this.eventEnd = '15:00'
            }
            this.fetchTimeslots()
        },

        async fetchTimeslots() {
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
                            type: this.meetingType,
                            buildingName: (this.meetingType === 'office' && s.bestBuildingName) ? s.bestBuildingName : '',
                        },
                    }
                )
                this.timeslots = Array.isArray(data?.suggestions) ? data.suggestions : []
                // Auto-select the first timeslot — users overwhelmingly pick
                // it, and pre-selecting lets canAdvance gate on chosenTimeslotIndex
                // without forcing an extra click.
                if (this.timeslots.length > 0) {
                    this.chooseTimeslot(0)
                }
            } catch (e) {
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
            const dayLabel = d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
            const half = s.half === 0 ? t('teamhub', 'morning') : t('teamhub', 'afternoon')
            return `${dayLabel} — ${half}`
        },

        describeSuggestion(s) {
            if (this.meetingType === 'office') {
                const building = s.bestBuildingName || t('teamhub', 'an office')
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

        formatChosenWhen() {
            // Manual-schedule mode reads straight from the form fields,
            // since there is no suggestion to anchor against.
            const haveTimes = this.eventDate && this.eventStart && this.eventEnd
            if (!haveTimes) return t('teamhub', '— not chosen yet —')
            if (!this.presenceAvailable || this.chosenIndex !== null) {
                const d = new Date(`${this.eventDate}T00:00:00`)
                const dayLabel = d.toLocaleDateString(undefined,
                    { weekday: 'long', day: 'numeric', month: 'long' })
                return `${dayLabel} · ${this.eventStart}–${this.eventEnd}`
            }
            return t('teamhub', '— not chosen yet —')
        },

        updateFilename() {
            if (!this.title) {
                this.filename = ''
                return
            }
            const slug = this.title
                .toLowerCase()
                .replace(/[^a-z0-9\s]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .substring(0, 60)
            this.filename = `${this.eventDate}-${slug}`
        },

        async submit() {
            this.titleError = ''
            if (!this.title.trim()) {
                this.titleError = t('teamhub', 'Title is required')
                return
            }
            this.busy = true
            try {
                const picked = this.rooms.find(r => r.id === this.selectedRoomId)
                if (this.lockAttendees) {
                    // Legacy approver-meeting path — write a calendar event,
                    // no notes file. Matches pre-3.81 behaviour exactly.
                    await this.submitCalendarOnly(picked)
                } else {
                    // New Add Meeting path — write notes file + calendar event
                    // + optional agenda sections.
                    await this.submitFullMeeting(picked)
                }
            } finally {
                this.busy = false
            }
        },

        async submitCalendarOnly(picked) {
            try {
                const startDt = new Date(`${this.eventDate}T${this.eventStart}:00`)
                const endDt = new Date(`${this.eventDate}T${this.eventEnd}:00`)
                const payload = {
                    title: this.title.trim(),
                    start: startDt.toISOString(),
                    end: endDt.toISOString(),
                    location: picked ? '' : this.location.trim(),
                    description: this.description.trim(),
                    categories: this.categories.trim(),
                    includeTalk: this.effectiveIncludeTalk ? 1 : 0,
                    roomEmail: picked ? picked.email : '',
                    roomName: picked ? picked.displayName : '',
                    roomId: (picked && (picked.source === 'roomvox' || picked.source === 'mixed'))
                        ? picked.id : '',
                    attendees: this.selectedIds.join(','),
                    calendarId: this.calendars[0]?.id ?? null,
                }
                const { data: created } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events`),
                    payload
                )
                showSuccess(t('teamhub', 'Event added to calendar'))
                this.$emit('created', {
                    eventUid: created?.eventUid || '',
                    start: created?.start || '',
                    title: created?.title || this.title.trim(),
                })
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? t('teamhub', 'Failed to add event: {error}', { error: msg })
                    : t('teamhub', 'Failed to add event'))
            }
        },

        async submitFullMeeting(picked) {
            try {
                const payload = {
                    title: this.title.trim(),
                    date: this.eventDate,
                    startTime: this.eventStart,
                    endTime: this.eventEnd,
                    location: picked ? '' : this.location.trim(),
                    description: this.description.trim(),
                    categories: this.categories.trim(),
                    filename: this.filename.trim() || this.title.trim(),
                    includeTalk: this.effectiveIncludeTalk ? 1 : 0,
                    talkToken: (this.effectiveIncludeTalk && this.resources?.talk?.token)
                        ? this.resources.talk.token : '',
                    askAgenda: (this.effectiveIncludeTalk && this.askAgenda) ? 1 : 0,
                    attendees: this.selectedIds.join(','),
                    roomEmail: picked ? picked.email : '',
                    roomName: picked ? picked.displayName : '',
                    roomId: (picked && (picked.source === 'roomvox' || picked.source === 'mixed'))
                        ? picked.id : '',
                    includeOverdueTasks: this.includeOverdueTasks ? 1 : 0,
                    includeUnscheduledTasks: this.includeUnscheduledTasks ? 1 : 0,
                    includeProposals: this.includeProposals ? 1 : 0,
                    // Only send a category filter when the user has narrowed
                    // the selection to a real subset; empty payload means
                    // "no filter" (all categories), matching the default.
                    proposalCategories: this.shouldSendProposalCategoryFilter
                        ? this.selectedProposalCategories.join(',')
                        : '',
                }
                const { data: created } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/meetings`),
                    payload
                )
                showSuccess(t('teamhub', 'Meeting created'))
                this.$emit('created', {
                    eventUid: created?.eventUid || '',
                    start: `${this.eventDate}T${this.eventStart}:00`,
                    title: this.title.trim(),
                })
                this.$emit('close')
            } catch (e) {
                const status = e?.response?.status
                const msg = e?.response?.data?.error || ''
                if (status === 403) {
                    showError(t('teamhub', 'You do not have permission to create team meetings.'))
                } else if (status === 422) {
                    showError(t('teamhub', 'Team setup incomplete: {error}', { error: msg }))
                } else {
                    showError(msg
                        ? t('teamhub', 'Failed to create meeting: {error}', { error: msg })
                        : t('teamhub', 'Failed to create meeting'))
                }
            }
        },
    },
}
</script>

<style scoped>
.suggest-wizard {
    padding: 20px 24px 24px;
    max-width: 760px;
    min-width: 360px;
}
@media (max-width: 768px) {
    .suggest-wizard {
        min-width: 0;
        padding: 12px;
    }
}
.suggest-wizard__heading {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 12px;
    font-size: 1.2rem;
}
.suggest-wizard__stepbar {
    display: flex;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0 0 16px;
}
.suggest-wizard__stepbar-item {
    flex: 1;
    padding: 6px 10px;
    border-radius: var(--border-radius-pill);
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
    text-align: center;
}
.suggest-wizard__stepbar-item--active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: 600;
}
.suggest-wizard__step { display: flex; flex-direction: column; gap: 12px; }
.suggest-wizard__two-col {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    gap: 16px;
}
@media (max-width: 700px) {
    .suggest-wizard__two-col { grid-template-columns: 1fr; }
}
.suggest-wizard__col {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}
.suggest-wizard__col-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.suggest-wizard__toolbtn {
    background: transparent;
    border: none;
    color: var(--color-primary-element);
    cursor: pointer;
    font-size: 0.85rem;
    padding: 2px 4px;
}
.suggest-wizard__toolbtn:hover { text-decoration: underline; }
.suggest-wizard__toolbtn:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: 4px;
}
.suggest-wizard__count { margin: 0; }
.suggest-wizard__members {
    max-height: 260px;
    overflow-y: auto;
    padding: 0;
    margin: 0;
    list-style: none;
}
.suggest-wizard__member { padding: 2px 0; }
.suggest-wizard__label {
    display: block;
    margin: 4px 0 4px;
    font-weight: 600;
    font-size: 0.9rem;
}
.suggest-wizard__field { margin-top: 4px; }
.suggest-wizard__input {
    width: 100%;
    box-sizing: border-box;
    padding: 7px 10px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font: inherit;
    line-height: 1.4;
}
.suggest-wizard__input--inline { width: auto; padding: 4px 8px; }
.suggest-wizard__input:focus,
.suggest-wizard__input:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
}
.suggest-wizard__input--error { border-color: var(--color-error); }
.suggest-wizard__textarea {
    resize: vertical;
    min-height: 64px;
    font-family: inherit;
}
.suggest-wizard__pills {
    display: flex;
    gap: 6px;
    margin: 4px 0 8px;
}
.suggest-wizard__pill {
    flex: 1;
    padding: 6px 10px;
    background: var(--color-main-background);
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-pill);
    color: var(--color-main-text);
    cursor: pointer;
    font-size: 0.9rem;
}
.suggest-wizard__pill:hover { background: var(--color-background-hover); }
.suggest-wizard__pill:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.suggest-wizard__pill--selected {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: 600;
}
.suggest-wizard__hint {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
    margin: 4px 0 0;
}
.suggest-wizard__hint--block { margin: 0 0 8px; }
.suggest-wizard__hint--warn {
    color: var(--color-warning-text);
}
.suggest-wizard__hint--info {
    padding: 6px 10px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-radius: var(--border-radius);
    margin-top: 8px;
}
.suggest-wizard__time-row {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}
.suggest-wizard__time-col { flex: 1; min-width: 0; }
.suggest-wizard__error {
    color: var(--color-error-text);
    font-size: 0.85rem;
    margin: 4px 0 0;
}
.suggest-wizard__suggest-block { margin-top: 8px; }
.suggest-wizard__suggestions {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 0;
    margin: 0;
    list-style: none;
}
.suggest-wizard__suggestion {
    width: 100%;
    text-align: left;
    padding: 10px 12px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.suggest-wizard__suggestion--compact { padding: 6px 10px; }
.suggest-wizard__suggestion:hover { background: var(--color-background-hover); }
.suggest-wizard__suggestion:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.suggest-wizard__suggestion--active { border-color: var(--color-primary-element); }
.suggest-wizard__suggestion-when { font-weight: 600; }
.suggest-wizard__suggestion-why {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
}
.suggest-wizard__empty {
    color: var(--color-text-maxcontrast);
    margin: 8px 0;
}
.suggest-wizard__timeslots-inline {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--color-border);
}
.suggest-wizard__timeslots-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
}
.suggest-wizard__duration {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    color: var(--color-text-maxcontrast);
}
.suggest-wizard__prefill-banner {
    background: var(--color-success);
    color: var(--color-success-text);
    padding: 6px 10px;
    border-radius: var(--border-radius);
    margin-bottom: 8px;
    font-size: 0.85rem;
    line-height: 1.4;
}
.suggest-wizard__summary {
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    padding: 10px 14px;
    margin-bottom: 8px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.suggest-wizard__summary-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: var(--color-main-text);
}
.suggest-wizard__notes-block {
    margin-top: 10px;
    padding: 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-background-hover);
}
.suggest-wizard__notes-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
}
.suggest-wizard__notes-title {
    margin: 0;
    font-size: 1rem;
}
.suggest-wizard__agenda-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
    margin-top: 8px;
}
@media (max-width: 540px) {
    .suggest-wizard__agenda-grid { grid-template-columns: 1fr; }
}
.suggest-wizard__cat-block {
    margin-top: 10px;
    padding: 8px 10px;
    border: 1px dashed var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
}
.suggest-wizard__cat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.suggest-wizard__label--inline { margin: 0; }
.suggest-wizard__cat-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 4px;
}
.suggest-wizard__chip {
    padding: 4px 10px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-pill);
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    font-size: 0.85rem;
    line-height: 1.4;
}
.suggest-wizard__chip:hover { background: var(--color-background-hover); }
.suggest-wizard__chip:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.suggest-wizard__chip--selected {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: 600;
}
.suggest-wizard__footer {
    display: flex;
    align-items: center;
    margin-top: 18px;
    gap: 8px;
}
.suggest-wizard__spacer { flex: 1; }
</style>
