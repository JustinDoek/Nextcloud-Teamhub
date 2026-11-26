<template>
    <section class="room-finder" :aria-label="t('teamhub', 'Meeting room')">
        <span class="room-finder__label">{{ t('teamhub', 'Meeting room') }}</span>

        <!-- Filters: building and people, and on Add meeting the equipment
             (v4.20.19). Floor, date and search live on the full room list,
             which opens from the button below. -->
        <div class="room-finder__filters">
            <div v-if="buildingOptions.length > 1" class="room-finder__filter room-finder__filter--building">
                <label class="room-finder__sublabel" :for="`${uid}-building`">{{ t('teamhub', 'Building') }}</label>
                <NcSelect
                    :input-id="`${uid}-building`"
                    label-outside
                    :model-value="building || null"
                    :options="buildingOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :placeholder="t('teamhub', 'Choose a building')"
                    :aria-label-combobox="t('teamhub', 'Building')"
                    @update:model-value="onBuildingChange" />
            </div>
            <p v-else-if="buildingOptions.length === 1" class="room-finder__single-building">
                <OfficeBuildingIcon :size="ICON_INLINE" />
                {{ buildingOptions[0].label }}
            </p>

            <div class="room-finder__filter room-finder__filter--people">
                <label class="room-finder__sublabel" :for="`${uid}-people`">{{ t('teamhub', 'People') }}</label>
                <NcTextField
                    :id="`${uid}-people`"
                    :model-value="String(people)"
                    type="number"
                    min="1"
                    max="999"
                    label-outside
                    :aria-label="t('teamhub', 'People')"
                    @update:model-value="onPeopleChange" />
            </div>

            <div v-if="equipment && featureOptions.length > 0" class="room-finder__filter room-finder__filter--equipment">
                <label class="room-finder__sublabel" :for="`${uid}-equipment`">{{ t('teamhub', 'Equipment') }}</label>
                <NcSelect
                    :input-id="`${uid}-equipment`"
                    label-outside
                    :multiple="true"
                    :close-on-select="false"
                    :model-value="features"
                    :options="featureOptions"
                    :reduce="o => o.id"
                    :placeholder="t('teamhub', 'Any equipment')"
                    :aria-label-combobox="t('teamhub', 'Equipment')"
                    @update:model-value="onFeaturesChange" />
            </div>
        </div>

        <!-- Prerequisites -->
        <p v-if="!slotRange" class="room-finder__hint">
            {{ t('teamhub', 'Pick a date and time first to see which rooms are free.') }}
        </p>
        <p v-else-if="!building" class="room-finder__hint">
            {{ t('teamhub', 'Choose a building to see which rooms are free.') }}
        </p>

        <template v-else>
            <NcNoteCard v-if="loadError" type="error" class="room-finder__note">
                <p>{{ t('teamhub', 'Could not check room availability') }}</p>
                <NcButton variant="secondary" @click="fetchAvailability">
                    {{ t('teamhub', 'Retry') }}
                </NcButton>
            </NcNoteCard>

            <NcNoteCard v-if="selectedIsBooked" type="warning" class="room-finder__note">
                {{ t('teamhub', '{room} is booked at this time. Pick another room or time.', { room: modelValue.displayName }) }}
            </NcNoteCard>
            <NcNoteCard v-else-if="selectedConflicts > 0" type="warning" class="room-finder__note">
                {{ n('teamhub', '{room} is booked on %n date of this series. Pick another room.', '{room} is booked on %n dates of this series. Pick another room.', selectedConflicts, { n: selectedConflicts, room: modelValue.displayName }) }}
            </NcNoteCard>

            <p class="room-finder__summary" aria-live="polite">
                <template v-if="loading">
                    <NcLoadingIcon :size="ICON_INLINE" />
                    {{ t('teamhub', 'Checking availability') }}
                </template>
                <template v-else-if="groups.freeCount > 0">
                    {{ n('teamhub', '%n room is free and fits everyone', '%n rooms are free and fit everyone', groups.freeCount, { n: groups.freeCount }) }}
                </template>
                <template v-else-if="!loadError && features.length > 0 && matchingRooms.length === 0">
                    {{ t('teamhub', 'No room in this building has all of this equipment.') }}
                </template>
                <template v-else-if="!loadError && matchingRooms.length > 0">
                    {{ n('teamhub', 'No free room fits %n person at this time', 'No free room fits %n people at this time', people, { n: people }) }}
                </template>
            </p>

            <!-- Add meeting: one room, the one picked or else the best fit on
                 offer. Add event (`simple`, v4.20.19): up to three, the picked
                 one first, and no full list.
                 Selection tile: a documented raw <button> carve-out
                 (ui-standards). aria-pressed carries the selection; the fill,
                 the border and the check repeat it so it never rests on
                 colour alone. -->
            <button
                v-for="room in shownRooms"
                :key="room.key"
                type="button"
                class="room-finder__tile"
                :class="{
                    'room-finder__tile--selected': isSelected(room),
                    'room-finder__tile--best': !isSelected(room),
                }"
                :aria-pressed="isSelected(room) ? 'true' : 'false'"
                @click="toggle(room)">
                <span class="room-finder__row">
                    <CheckCircleIcon v-if="isSelected(room)" :size="ICON_BODY" class="room-finder__check" />
                    <span class="room-finder__name">{{ room.displayName }}</span>
                    <NcChip v-if="room.key === groups.bestKey" no-close variant="primary" :text="t('teamhub', 'Best fit')" />
                    <NcChip no-close :variant="statusVariant(room)" :text="statusLabel(room)" />
                </span>
                <span class="room-finder__meta">
                    <span v-if="room.floor">{{ room.floor }}</span>
                    <span v-if="room.roomNumber">{{ room.roomNumber }}</span>
                    <span class="room-finder__icon-text">
                        <AccountMultipleIcon :size="ICON_INLINE" />
                        {{ room.capacity ? n('teamhub', '%n seat', '%n seats', room.capacity, { n: room.capacity }) : t('teamhub', 'Capacity not set') }}
                    </span>
                    <span v-if="hasVideo(room)" class="room-finder__icon-text" :title="t('teamhub', 'Video conferencing')">
                        <VideoIcon :size="ICON_INLINE" />
                        <span class="hidden-visually">{{ t('teamhub', 'Video conferencing') }}</span>
                    </span>
                    <span v-if="whenLabel(room)">{{ whenLabel(room) }}</span>
                </span>
                <RoomFinderTimeline
                    :segments="segmentsFor(room)"
                    :slot-offset="slotGeometry.offset"
                    :slot-size="slotGeometry.size"
                    :hours="hours"
                    :now-at="nowAt"
                    :label="timelineLabel(room)" />
            </button>
            <p v-if="shownRooms.length > 0 && !modelValue" class="room-finder__hint">
                <template v-if="simple">
                    {{ t('teamhub', 'Select a room to book it.') }}
                </template>
                <template v-else>
                    {{ t('teamhub', 'Select the room to book it, or choose another one.') }}
                </template>
            </p>

            <div class="room-finder__actions">
                <NcButton v-if="!simple" ref="browseButton" variant="secondary" @click="openBrowser">
                    <template #icon>
                        <ViewListIcon :size="ICON_BODY" />
                    </template>
                    {{ n('teamhub', 'Show all %n room', 'Show all %n rooms', matchingRooms.length, { n: matchingRooms.length }) }}
                </NcButton>
                <NcButton v-if="modelValue" variant="tertiary" @click="select(null)">
                    {{ t('teamhub', 'Remove room') }}
                </NcButton>
            </div>
        </template>

        <p v-if="pickedIsRoomVox" class="room-finder__hint">
            {{ t('teamhub', 'This room will be booked automatically via RoomVox.') }}
        </p>
        <p v-else-if="modelValue" class="room-finder__hint">
            {{ t('teamhub', 'This room will be invited to the event. Booking must be confirmed by the resource manager.') }}
        </p>
    </section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcChip, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import ViewListIcon from 'vue-material-design-icons/ViewList.vue'
import RoomFinderTimeline from './RoomFinderTimeline.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
import { fetchRoomAvailability } from '../lib/roomAvailabilityApi.js'
import {
    UNPLACED,
    buildingOptionList,
    classifyRooms,
    clockTime,
    freeAgainAt,
    freeUntil,
    hourMarks,
    featureChoices,
    initialBuilding,
    nowOffset,
    roomsInBuilding,
    roomsWithFeatures,
    shortList,
    slotWindow,
    timelineSegments,
} from '../lib/roomFinder.js'
import { featureLabel } from '../lib/roomFeatureLabels.js'

const STORAGE_KEY = 'teamhub.roomFinder.building'

let instanceCounter = 0

/** The building a catalogue entry belongs to, as the building filter names it. */
function buildingOf(room) {
    return (room?.building || '').trim() || UNPLACED
}

/**
 * RoomFinder (v4.20.0): the room picker for the meeting wizard and Add event.
 *
 * Replaces a flat dropdown of every room on the instance, which knew nothing
 * about where a room was, how many it seated, or whether it was free, and so
 * produced a steady stream of failed and double bookings. Rooms are narrowed
 * to one building, judged against the people coming and checked against the
 * room's own calendar for the slot. DESIGN §2.261.
 *
 * v4.20.7: the form shows one room. That is the picked room, or else the best
 * fit (free, seats everyone, fewest spare seats). Every other room is on
 * RoomBrowser, the full list with floor, date and search filters, opened with
 * "Show all rooms". A list of ten or fifteen rooms made the dialog taller
 * than the form it sat in. This component emits `browse` with its building
 * and head count; the host swaps its dialog content for RoomBrowser and calls
 * `returnFromBrowser()` when it comes back. DESIGN §2.262.
 *
 * v4.20.19: `simple` (Add event, the free form) shows up to three rooms and
 * no full list; `equipment` (Add meeting) adds an equipment filter, handed to
 * the full list with the building and head count. DESIGN §2.270.
 *
 * The parent owns the catalogue (`rooms`, from `/rooms`) so it can fall back
 * to a free-text location when there is none. v-model is the picked
 * catalogue entry, or null for no room.
 */
export default {
    name: 'RoomFinder',
    components: {
        NcButton,
        NcChip,
        NcLoadingIcon,
        NcNoteCard,
        NcSelect,
        NcTextField,
        AccountMultipleIcon,
        CheckCircleIcon,
        OfficeBuildingIcon,
        VideoIcon,
        ViewListIcon,
        RoomFinderTimeline,
    },
    props: {
        teamId: { type: String, required: true },
        rooms: { type: Array, default: () => [] },
        modelValue: { type: Object, default: null },
        date: { type: String, default: '' },
        startTime: { type: String, default: '' },
        endTime: { type: String, default: '' },
        /** Seats needed by default: the attendees plus the organiser. */
        peopleCount: { type: Number, default: 1 },
        /** A building to open on, such as the presence suggestion's best one. */
        preferredBuilding: { type: String, default: '' },
        /**
         * v4.20.8 — a recurring meeting's repeat setting (serializeRecurrence()
         * output), or ''. Every date of the series is then checked.
         */
        recurrence: { type: String, default: '' },
        /** v4.20.19 — Add event: up to three rooms, no full list. */
        simple: { type: Boolean, default: false },
        /** v4.20.19 — Add meeting: filter on the rooms' equipment. */
        equipment: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'browse'],
    data() {
        instanceCounter += 1
        return {
            ICON_BODY,
            ICON_INLINE,
            uid: `room-finder-${instanceCounter}`,
            building: '',
            people: Math.max(1, this.peopleCount || 1),
            peopleEdited: false,
            // v4.20.19 — equipment codes every room shown must have.
            features: [],
            availability: {},
            loading: false,
            loadError: false,
            requestSeq: 0,
            fetchTimer: null,
        }
    },
    computed: {
        buildingOptions() {
            return buildingOptionList(this.rooms, t('teamhub', 'Other rooms'))
        },
        slotRange() {
            return slotWindow(this.date, this.startTime, this.endTime)
        },
        buildingRooms() {
            return this.building ? roomsInBuilding(this.rooms, this.building) : []
        },
        featureOptions() {
            return featureChoices(this.buildingRooms).map(code => ({ id: code, label: featureLabel(code) }))
        },
        /** The building's rooms with the equipment asked for. */
        matchingRooms() {
            return this.equipment ? roomsWithFeatures(this.buildingRooms, this.features) : this.buildingRooms
        },
        groups() {
            return classifyRooms(this.matchingRooms, this.availability, this.people)
        },
        /** The one room the form shows: the picked one, else the best fit. */
        featured() {
            if (this.modelValue) {
                return this.rooms.find(r => r.key === this.modelValue.key) || this.modelValue
            }
            if (!this.groups.bestKey) return null
            return this.groups.available.find(r => r.key === this.groups.bestKey) || null
        },
        /** The tiles: Add event's short list, or Add meeting's one room. */
        shownRooms() {
            if (this.simple) {
                const picked = this.modelValue
                    ? (this.rooms.find(r => r.key === this.modelValue.key) || this.modelValue)
                    : null
                return shortList(this.groups, picked, 3)
            }
            return this.featured ? [this.featured] : []
        },
        slotGeometry() {
            if (!this.slotRange) return { offset: 0, size: 0 }
            const [segment] = timelineSegments(
                [{ start: new Date(this.slotRange.slotStart).toISOString(), end: new Date(this.slotRange.slotEnd).toISOString() }],
                this.slotRange.windowStart,
                this.slotRange.windowEnd,
            )
            return segment || { offset: 0, size: 0 }
        },
        hours() {
            return this.slotRange ? hourMarks(this.slotRange.windowStart, this.slotRange.windowEnd) : []
        },
        nowAt() {
            return this.slotRange ? nowOffset(this.slotRange.windowStart, this.slotRange.windowEnd) : null
        },
        selectedIsBooked() {
            return !!this.modelValue && this.availability[this.modelValue.key]?.status === 'busy'
        },
        /** v4.20.8 — the picked room is free now but taken on later dates of the series. */
        selectedConflicts() {
            if (!this.modelValue || this.availability[this.modelValue.key]?.status !== 'partial') return 0
            return this.availability[this.modelValue.key]?.series?.conflicts?.length || 0
        },
        pickedIsRoomVox() {
            return !!this.modelValue && (this.modelValue.source === 'roomvox' || this.modelValue.source === 'mixed')
        },
        /** Everything that changes the answer the server gives. */
        requestKey() {
            if (!this.slotRange || !this.building) return ''
            return [this.building, this.slotRange.slotStart, this.slotRange.slotEnd, this.recurrence].join('|')
        },
    },
    watch: {
        requestKey() {
            this.scheduleFetch()
        },
        peopleCount(value) {
            if (!this.peopleEdited) {
                this.people = Math.max(1, value || 1)
            }
        },
        rooms() {
            if (!this.building) this.pickInitialBuilding()
        },
        preferredBuilding(value) {
            if (value && !this.modelValue && this.buildingOptions.some(o => o.id === value)) {
                this.building = value
            }
        },
        // A room picked on the full list may sit in another building: follow it,
        // so the card, its status and its day line describe the room picked.
        modelValue(room) {
            if (room && buildingOf(room) !== this.building) {
                this.building = buildingOf(room)
            }
        },
    },
    mounted() {
        this.pickInitialBuilding()
        this.scheduleFetch()
    },
    beforeUnmount() {
        clearTimeout(this.fetchTimer)
    },
    methods: {
        t,
        n,

        pickInitialBuilding() {
            let remembered = ''
            try {
                remembered = window.localStorage.getItem(STORAGE_KEY) || ''
            } catch (e) {
                remembered = ''
            }
            // An already picked room wins: reopening a step keeps its building.
            const fromPicked = this.modelValue ? buildingOf(this.modelValue) : ''
            this.building = fromPicked || initialBuilding(this.rooms, this.preferredBuilding, remembered)
        },

        onFeaturesChange(value) {
            this.features = Array.isArray(value) ? value : []
        },

        onBuildingChange(value) {
            this.building = value || ''
            // Equipment another building does not have would only empty the list.
            this.features = []
            try {
                window.localStorage.setItem(STORAGE_KEY, this.building)
            } catch (e) {
                // Per-viewer convenience only; nothing depends on it.
            }
            // A room in the building just left is no longer what the form shows.
            if (this.modelValue && buildingOf(this.modelValue) !== this.building) {
                this.select(null)
            }
        },

        onPeopleChange(value) {
            const parsed = parseInt(value, 10)
            this.people = Number.isFinite(parsed) && parsed > 0 ? Math.min(parsed, 999) : 1
            this.peopleEdited = true
        },

        scheduleFetch() {
            clearTimeout(this.fetchTimer)
            if (!this.requestKey) {
                this.availability = {}
                return
            }
            this.fetchTimer = setTimeout(() => this.fetchAvailability(), 250)
        },

        async fetchAvailability() {
            if (!this.slotRange || !this.building) return
            const seq = ++this.requestSeq
            this.loading = true
            this.loadError = false
            try {
                const rooms = await fetchRoomAvailability(this.teamId, this.building, this.slotRange, this.recurrence || null)
                // A slower answer to an older question must not overwrite a newer one.
                if (seq !== this.requestSeq) return
                this.availability = rooms
            } catch (e) {
                if (seq !== this.requestSeq) return
                this.availability = {}
                this.loadError = true
            } finally {
                if (seq === this.requestSeq) this.loading = false
            }
        },

        openBrowser() {
            this.$emit('browse', { building: this.building, people: this.people, features: this.features })
        },

        /**
         * Called by the host when the full list closes. Adopts a head count
         * and equipment changed there and puts focus back on the button that
         * opened it.
         *
         * @param {{ people?: number, features?: string[] }} result what the list hands back
         */
        returnFromBrowser(result = {}) {
            if (Array.isArray(result.features)) {
                this.features = result.features
            }
            if (Number.isInteger(result.people) && result.people > 0 && result.people !== this.people) {
                this.people = result.people
                this.peopleEdited = true
            }
            this.$nextTick(() => this.$refs.browseButton?.$el?.focus())
        },

        select(room) {
            this.$emit('update:modelValue', room)
        },

        toggle(room) {
            this.select(this.isSelected(room) ? null : room)
        },

        isSelected(room) {
            return !!this.modelValue && this.modelValue.key === room.key
        },

        hasVideo(room) {
            return (room.features || []).some(f => f.includes('VIDEO'))
        },

        statusOf(room) {
            if (this.loading && !this.availability[room.key]) return 'unknown'
            return this.availability[room.key]?.status || 'unknown'
        },

        statusVariant(room) {
            const status = this.statusOf(room)
            if (status === 'free') return 'success'
            if (status === 'busy') return 'error'
            if (status === 'partial') return 'warning'
            return 'tertiary'
        },

        statusLabel(room) {
            const status = this.statusOf(room)
            if (status === 'free') return t('teamhub', 'Free')
            if (status === 'busy') return t('teamhub', 'Booked')
            // TRANSLATORS: a meeting room free for the first meeting of a series but taken on some later dates
            if (status === 'partial') return t('teamhub', 'Partly booked')
            return t('teamhub', 'Availability unknown')
        },

        whenLabel(room) {
            if (!this.slotRange) return ''
            const busy = this.availability[room.key]?.busy || []
            const status = this.statusOf(room)
            // v4.20.8 — a recurring meeting: the verdict over all its dates.
            const series = this.availability[room.key]?.series
            if (status === 'partial' && series) {
                // TRANSLATORS: {count} and {total} are numbers of dates in a repeating meeting
                return t('teamhub', 'Booked on {count} of {total} dates', { count: series.conflicts.length, total: series.total })
            }
            if (status === 'free' && series?.checked && series.total > 1) {
                return t('teamhub', 'Free on all {total} dates', { total: series.total })
            }
            if (status === 'free') {
                const until = freeUntil(busy, this.slotRange.slotEnd, this.slotRange.windowEnd)
                return until === null
                    ? t('teamhub', 'Free for the rest of the day')
                    // TRANSLATORS: {time} is a clock time such as 15:00, when a free meeting room's next booking starts
                    : t('teamhub', 'Free until {time}', { time: clockTime(until) })
            }
            if (status === 'busy') {
                const at = freeAgainAt(busy, this.slotRange.slotStart, this.slotRange.slotEnd, this.slotRange.windowEnd)
                return at === null
                    ? t('teamhub', 'Booked for the rest of the day')
                    // TRANSLATORS: {time} is a clock time such as 11:30, when a booked meeting room becomes free
                    : t('teamhub', 'Free again at {time}', { time: clockTime(at) })
            }
            return ''
        },

        segmentsFor(room) {
            if (!this.slotRange) return []
            return timelineSegments(this.availability[room.key]?.busy || [], this.slotRange.windowStart, this.slotRange.windowEnd)
        },

        timelineLabel(room) {
            if (!this.slotRange) return ''
            // TRANSLATORS: accessible name of a meeting room's day line; {room} is the room name, {start} and {end} clock times
            return t('teamhub', 'Bookings for {room} between {start} and {end}', {
                room: room.displayName,
                start: clockTime(this.slotRange.windowStart),
                end: clockTime(this.slotRange.windowEnd),
            })
        },
    },
}
</script>

<style scoped>
.room-finder {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
}

.room-finder__label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
}

.room-finder__sublabel {
    display: block;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-block-end: var(--th-space-xs);
}

.room-finder__filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-md);
    /* Top-aligned, so Building and People share one label line; NcSelect's
       own bottom margin shifted it under bottom alignment (v4.20.7). */
    align-items: flex-start;
}

.room-finder__filter--building :deep(.v-select.select) {
    margin: 0;
}

.room-finder__filter--building {
    flex: 2 1 calc(var(--th-space-xxl) * 6);
    min-width: 0;
}

.room-finder__filter--people {
    flex: 0 0 calc(var(--th-space-xxl) * 3);
}

/* v4.20.19 — the select fills its own box (the Roster picker fix, §2.252). */
.room-finder__filter--equipment {
    flex: 2 1 calc(var(--th-space-xxl) * 6);
    min-width: 0;
}

.room-finder__filter--equipment :deep(.v-select.select) {
    inline-size: 100%;
    margin: 0;
}

.room-finder__single-building {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    margin: 0;
    flex: 2 1 calc(var(--th-space-xxl) * 6);
    color: var(--color-main-text);
    /* No label above it: sit on the field's line, not the label's. */
    align-self: flex-end;
    min-height: var(--default-clickable-area);
}

.room-finder__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0;
}

.room-finder__summary {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-height: var(--th-icon-body);
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
}

.room-finder__note {
    margin: 0;
}

/* Selection tile (raw <button> carve-out). font-weight resets NC's global
   bold on raw buttons, so only the room name is emphasised. */
.room-finder__tile {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: var(--th-space-xs);
    width: 100%;
    min-height: auto;
    margin: 0;
    padding: var(--th-space-sm) var(--th-space-md);
    text-align: start;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-regular);
    color: var(--color-main-text);
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-container);
    cursor: pointer;
}

.room-finder__tile:hover {
    background: var(--color-background-hover);
}

.room-finder__tile:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.room-finder__tile--best {
    border-color: var(--color-primary-element);
}

.room-finder__tile--selected,
.room-finder__tile--selected:hover {
    background: var(--color-primary-element-light);
    color: var(--color-primary-element-light-text);
    border-color: var(--color-primary-element);
    box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.room-finder__row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

.room-finder__check {
    color: var(--color-primary-element);
}

.room-finder__name {
    font-weight: var(--th-font-weight-semibold);
    margin-inline-end: auto;
}

.room-finder__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-md);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.room-finder__icon-text {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
}

.room-finder__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}
</style>
