<template>
    <section class="room-browser" :aria-labelledby="`${uid}-heading`">
        <div class="room-browser__header">
            <NcButton variant="tertiary" @click="$emit('back')">
                <template #icon>
                    <ArrowLeftIcon :size="ICON_BODY" />
                </template>
                {{ t('teamhub', 'Back') }}
            </NcButton>
            <h3 :id="`${uid}-heading`" ref="heading" class="room-browser__title" tabindex="-1">
                {{ t('teamhub', 'Choose a room') }}
            </h3>
        </div>
        <p v-if="slotLabel" class="room-browser__slot">
            <ClockOutlineIcon :size="ICON_INLINE" />
            {{ slotLabel }}
        </p>

        <!-- Filters, opened on what the form already had: building, people
             and the meeting's day. Floor starts on every floor. -->
        <div class="room-browser__filters">
            <div v-if="buildingOptions.length > 1" class="room-browser__filter room-browser__filter--building">
                <label class="room-browser__label" :for="`${uid}-building`">{{ t('teamhub', 'Building') }}</label>
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
            <p v-else-if="buildingOptions.length === 1" class="room-browser__single">
                <OfficeBuildingIcon :size="ICON_INLINE" />
                {{ buildingOptions[0].label }}
            </p>

            <div v-if="floorOptions.length > 2" class="room-browser__filter room-browser__filter--floor">
                <label class="room-browser__label" :for="`${uid}-floor`">{{ t('teamhub', 'Floor') }}</label>
                <NcSelect
                    :input-id="`${uid}-floor`"
                    label-outside
                    :model-value="floor"
                    :options="floorOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :aria-label-combobox="t('teamhub', 'Floor')"
                    @update:model-value="v => floor = v || ''" />
            </div>

            <div class="room-browser__filter room-browser__filter--date">
                <label class="room-browser__label" :for="`${uid}-date`">{{ t('teamhub', 'Date') }}</label>
                <!-- Native date input: NC's recommended picker (ui-standards). -->
                <input
                    :id="`${uid}-date`"
                    v-model="day"
                    type="date"
                    class="room-browser__date"
                    :min="today">
            </div>

            <div class="room-browser__filter room-browser__filter--people">
                <label class="room-browser__label" :for="`${uid}-people`">{{ t('teamhub', 'People') }}</label>
                <NcTextField
                    :id="`${uid}-people`"
                    :model-value="String(peopleNeeded)"
                    type="number"
                    min="1"
                    max="999"
                    label-outside
                    :aria-label="t('teamhub', 'People')"
                    @update:model-value="onPeopleChange" />
            </div>

            <!-- v4.20.19 — the rooms' equipment, opened on what the inline
                 finder had. Only equipment a room in this building has. -->
            <div v-if="featureOptions.length > 0" class="room-browser__filter room-browser__filter--equipment">
                <label class="room-browser__label" :for="`${uid}-equipment`">{{ t('teamhub', 'Equipment') }}</label>
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
                    @update:model-value="v => features = Array.isArray(v) ? v : []" />
            </div>

            <div class="room-browser__filter room-browser__filter--search">
                <label class="room-browser__label" :for="`${uid}-search`">{{ t('teamhub', 'Search rooms') }}</label>
                <NcTextField
                    :id="`${uid}-search`"
                    v-model="query"
                    type="search"
                    label-outside
                    :aria-label="t('teamhub', 'Search rooms')">
                    <template #icon>
                        <MagnifyIcon :size="ICON_INLINE" />
                    </template>
                </NcTextField>
            </div>
        </div>

        <NcNoteCard v-if="dayMoved && slotRange" type="info" class="room-browser__note">
            {{ t('teamhub', 'Picking a room on this day also moves the meeting to {date}.', { date: dayLabel }) }}
        </NcNoteCard>

        <p v-if="!slotRange" class="room-browser__hint">
            {{ t('teamhub', 'Pick a date and time first to see which rooms are free.') }}
        </p>
        <p v-else-if="!building" class="room-browser__hint">
            {{ t('teamhub', 'Choose a building to see which rooms are free.') }}
        </p>

        <template v-else>
            <NcNoteCard v-if="loadError" type="error" class="room-browser__note">
                <p>{{ t('teamhub', 'Could not check room availability') }}</p>
                <NcButton variant="secondary" @click="fetchAvailability">
                    {{ t('teamhub', 'Retry') }}
                </NcButton>
            </NcNoteCard>

            <p class="room-browser__summary" aria-live="polite">
                <template v-if="loading">
                    <NcLoadingIcon :size="ICON_INLINE" />
                    {{ t('teamhub', 'Checking availability') }}
                </template>
                <template v-else-if="query && filteredRooms.length === 0">
                    {{ t('teamhub', 'No rooms match the search.') }}
                </template>
                <template v-else-if="features.length > 0 && filteredRooms.length === 0">
                    {{ t('teamhub', 'No room in this building has all of this equipment.') }}
                </template>
                <template v-else-if="groups.freeCount > 0">
                    {{ n('teamhub', '%n room is free and fits everyone', '%n rooms are free and fit everyone', groups.freeCount, { n: groups.freeCount }) }}
                </template>
                <template v-else-if="!loadError && filteredRooms.length > 0">
                    {{ n('teamhub', 'No free room fits %n person at this time', 'No free room fits %n people at this time', peopleNeeded, { n: peopleNeeded }) }}
                </template>
            </p>

            <!-- One hour scale for the whole list, on the same grid as the
                 rows so each label sits over its line. -->
            <div v-if="filteredRooms.length > 0" class="room-browser__grid room-browser__axis" aria-hidden="true">
                <span class="room-browser__axis-gap" />
                <span class="room-browser__axis-gap" />
                <span class="room-browser__scale">
                    <span
                        v-for="(mark, i) in labelledHours"
                        :key="`label-${i}`"
                        class="room-browser__tick"
                        :style="{ insetInlineStart: `${mark.offset}%` }">{{ mark.label }}</span>
                </span>
            </div>

            <template v-for="section in sections" :key="section.id">
                <h4 v-if="section.rooms.length > 0 && section.id !== 'small'" class="room-browser__section">
                    {{ section.title }}
                </h4>
                <NcButton
                    v-else-if="section.id === 'small' && section.rooms.length > 0"
                    variant="tertiary"
                    :aria-expanded="showSmall ? 'true' : 'false'"
                    @click="showSmall = !showSmall">
                    <template #icon>
                        <ChevronUpIcon v-if="showSmall" :size="ICON_BODY" />
                        <ChevronDownIcon v-else :size="ICON_BODY" />
                    </template>
                    {{ section.title }}
                </NcButton>

                <div
                    v-if="section.rooms.length > 0 && (section.id !== 'small' || showSmall)"
                    class="room-browser__list"
                    role="group"
                    :aria-label="section.title">
                    <!-- Room rows: multi-line selection rows are a documented
                         raw <button> carve-out (ui-standards). aria-pressed
                         carries the selection; the fill and the border repeat
                         it so it never rests on colour alone. -->
                    <button
                        v-for="room in section.rooms"
                        :key="room.key"
                        type="button"
                        class="room-browser__grid room-browser__row"
                        :class="{
                            'room-browser__row--selected': isSelected(room),
                            'room-browser__row--muted': section.id !== 'free',
                        }"
                        :aria-pressed="isSelected(room) ? 'true' : 'false'"
                        @click="pick(room)">
                        <span class="room-browser__room">
                            <span class="room-browser__name">{{ room.displayName }}</span>
                            <span class="room-browser__meta">
                                <span v-if="room.floor && !floor">{{ room.floor }}</span>
                                <span v-if="room.roomNumber">{{ room.roomNumber }}</span>
                                <span class="room-browser__icon-text">
                                    <AccountMultipleIcon :size="ICON_INLINE" />
                                    {{ room.capacity ? n('teamhub', '%n seat', '%n seats', room.capacity, { n: room.capacity }) : t('teamhub', 'Capacity not set') }}
                                </span>
                                <span v-if="hasVideo(room)" class="room-browser__icon-text" :title="t('teamhub', 'Video conferencing')">
                                    <VideoIcon :size="ICON_INLINE" />
                                    <span class="hidden-visually">{{ t('teamhub', 'Video conferencing') }}</span>
                                </span>
                            </span>
                        </span>
                        <span class="room-browser__state">
                            <span class="room-browser__chips">
                                <NcChip v-if="room.key === groups.bestKey" no-close variant="primary" :text="t('teamhub', 'Best fit')" />
                                <NcChip no-close :variant="statusVariant(room)" :text="statusLabel(room)" />
                            </span>
                            <span v-if="whenLabel(room)" class="room-browser__when">{{ whenLabel(room) }}</span>
                        </span>
                        <RoomFinderTimeline
                            tall
                            :segments="segmentsFor(room)"
                            :slot-offset="slotGeometry.offset"
                            :slot-size="slotGeometry.size"
                            :hours="hours"
                            :now-at="nowAt"
                            :label="timelineLabel(room)" />
                    </button>
                </div>
            </template>
        </template>
    </section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcChip, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import RoomFinderTimeline from './RoomFinderTimeline.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
import { formatIsoDate, todayIso } from '../lib/localDate.js'
import { fetchRoomAvailability } from '../lib/roomAvailabilityApi.js'
import {
    buildingOptionList,
    classifyRooms,
    clockTime,
    floorChoices,
    freeAgainAt,
    freeUntil,
    hourMarks,
    nowOffset,
    featureChoices,
    roomsInBuilding,
    roomsOnFloor,
    roomsWithFeatures,
    slotWindow,
    timelineSegments,
} from '../lib/roomFinder.js'
import { featureLabel } from '../lib/roomFeatureLabels.js'

let instanceCounter = 0

/**
 * RoomBrowser (v4.20.7): every room in a building, compact, with its day.
 *
 * The inline RoomFinder shows one room, the best fit, so the meeting form
 * stays short however many rooms a building has. This page is where the
 * rest live: filters for building, floor, date, people and a search, then
 * one row per room with its seats, its verdict, how long it stays free (or
 * when it is free again) and its day line with hour lines.
 *
 * It replaces the content of the dialog it was opened from instead of
 * opening a second dialog on top: a modal over a modal is the shape Justin
 * reported on the Policy tab (DESIGN §2.223). The host hides its own form
 * with v-show while this is open, so nothing typed there is lost, and widens
 * the dialog. Picking a room emits `pick` with the room and the day it was
 * picked for; the host adopts both. `back` returns without a change.
 * DESIGN §2.262. v4.20.19: an equipment filter, opened on the inline
 * finder's and handed back with a pick (DESIGN §2.270).
 */
export default {
    name: 'RoomBrowser',
    components: {
        NcButton,
        NcChip,
        NcLoadingIcon,
        NcNoteCard,
        NcSelect,
        NcTextField,
        AccountMultipleIcon,
        ArrowLeftIcon,
        ChevronDownIcon,
        ChevronUpIcon,
        ClockOutlineIcon,
        MagnifyIcon,
        OfficeBuildingIcon,
        VideoIcon,
        RoomFinderTimeline,
    },
    props: {
        teamId: { type: String, required: true },
        rooms: { type: Array, default: () => [] },
        /** The room currently picked in the form, or null. */
        modelValue: { type: Object, default: null },
        /** The meeting's day and times, as the form has them. */
        date: { type: String, default: '' },
        startTime: { type: String, default: '' },
        endTime: { type: String, default: '' },
        /** Opening filters, handed over by the inline finder. */
        initialBuilding: { type: String, default: '' },
        initialPeople: { type: Number, default: 1 },
        /** v4.20.19 — equipment codes the inline finder asked for. */
        initialFeatures: { type: Array, default: () => [] },
        /** v4.20.8 — a recurring meeting's repeat setting, or ''. */
        recurrence: { type: String, default: '' },
    },
    emits: ['pick', 'back'],
    data() {
        instanceCounter += 1
        return {
            ICON_BODY,
            ICON_INLINE,
            uid: `room-browser-${instanceCounter}`,
            building: this.initialBuilding,
            floor: '',
            day: this.date,
            peopleNeeded: Math.max(1, this.initialPeople || 1),
            query: '',
            features: [...this.initialFeatures],
            availability: {},
            loading: false,
            loadError: false,
            showSmall: false,
            requestSeq: 0,
            fetchTimer: null,
            today: todayIso(),
        }
    },
    computed: {
        buildingOptions() {
            return buildingOptionList(this.rooms, t('teamhub', 'Other rooms'))
        },
        buildingRooms() {
            return this.building ? roomsInBuilding(this.rooms, this.building) : []
        },
        featureOptions() {
            return featureChoices(this.buildingRooms).map(code => ({ id: code, label: featureLabel(code) }))
        },
        floorOptions() {
            return [
                { id: '', label: t('teamhub', 'All floors') },
                ...floorChoices(this.buildingRooms).map(f => ({ id: f, label: f })),
            ]
        },
        filteredRooms() {
            if (!this.building) return []
            return roomsWithFeatures(roomsOnFloor(roomsInBuilding(this.rooms, this.building, this.query), this.floor), this.features)
        },
        groups() {
            return classifyRooms(this.filteredRooms, this.availability, this.peopleNeeded)
        },
        sections() {
            const g = this.groups
            return [
                {
                    id: 'free',
                    // TRANSLATORS: heading over the meeting rooms that are free (or not yet confirmed booked) and seat everyone
                    title: n('teamhub', '%n room fits everyone', '%n rooms fit everyone', g.available.length, { n: g.available.length }),
                    rooms: g.available,
                },
                {
                    id: 'booked',
                    title: n('teamhub', '%n room booked at this time', '%n rooms booked at this time', g.booked.length, { n: g.booked.length }),
                    rooms: g.booked,
                },
                {
                    id: 'small',
                    title: n('teamhub', '%n room is too small', '%n rooms are too small', g.tooSmall.length, { n: g.tooSmall.length }),
                    rooms: g.tooSmall,
                },
            ]
        },
        slotRange() {
            return slotWindow(this.day, this.startTime, this.endTime)
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
        labelledHours() {
            return this.hours.filter(mark => mark.label)
        },
        nowAt() {
            return this.slotRange ? nowOffset(this.slotRange.windowStart, this.slotRange.windowEnd) : null
        },
        dayMoved() {
            return !!this.day && this.day !== this.date
        },
        dayLabel() {
            return this.day ? formatIsoDate(this.day) : ''
        },
        slotLabel() {
            if (!this.slotRange) return ''
            // TRANSLATORS: the meeting being planned, e.g. "Nov 16, 2026 from 11:00 to 12:00"
            return t('teamhub', '{date} from {start} to {end}', { date: this.dayLabel, start: this.startTime, end: this.endTime })
        },
        requestKey() {
            if (!this.slotRange || !this.building) return ''
            return [this.building, this.slotRange.slotStart, this.slotRange.slotEnd, this.recurrence].join('|')
        },
    },
    watch: {
        requestKey() {
            this.scheduleFetch()
        },
    },
    mounted() {
        this.scheduleFetch()
        // The page replaced the form under the user's focus; move it here.
        this.$nextTick(() => this.$refs.heading?.focus())
    },
    beforeUnmount() {
        clearTimeout(this.fetchTimer)
    },
    methods: {
        t,
        n,

        onBuildingChange(value) {
            this.building = value || ''
            this.floor = ''
            this.query = ''
            this.features = []
        },

        onPeopleChange(value) {
            const parsed = parseInt(value, 10)
            this.peopleNeeded = Number.isFinite(parsed) && parsed > 0 ? Math.min(parsed, 999) : 1
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

        pick(room) {
            this.$emit('pick', { room, date: this.day, people: this.peopleNeeded, features: this.features })
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
.room-browser {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-xl);
}

.room-browser__header {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
}

.room-browser__title {
    margin: 0;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.room-browser__title:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--border-radius-small);
}

.room-browser__slot,
.room-browser__single {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    margin: 0;
    color: var(--color-text-maxcontrast);
}

.room-browser__single {
    color: var(--color-main-text);
    flex: 2 1 0;
    /* No label above it: sit on the fields' line, not the labels'. */
    align-self: flex-end;
    min-height: var(--default-clickable-area);
}

.room-browser__filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-md);
    /* Top-aligned, so the labels share one line and the fields under them
       start at the same height. Bottom alignment let each control's own
       margin shift its label (v4.20.7). */
    align-items: flex-start;
}

/* NcSelect carries `margin: 0 0 var(--default-grid-baseline)` and NC's global
   input rule gives the native date field a margin too; inside this row the
   gap does the spacing, so both go. */
.room-browser__filter :deep(.v-select.select) {
    margin: 0;
}

.room-browser__filter {
    min-width: 0;
}

.room-browser__filter--building {
    flex: 2 1 calc(var(--th-space-xxl) * 6);
}

.room-browser__filter--floor,
.room-browser__filter--date {
    flex: 1 1 calc(var(--th-space-xxl) * 4);
}

.room-browser__filter--people {
    flex: 0 0 calc(var(--th-space-xxl) * 3);
}

.room-browser__filter--equipment {
    flex: 2 1 calc(var(--th-space-xxl) * 6);
}

.room-browser__filter--equipment :deep(.v-select.select) {
    inline-size: 100%;
}

.room-browser__filter--search {
    flex: 1 1 100%;
}

.room-browser__label {
    display: block;
    margin-block-end: var(--th-space-xs);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Three-part selector so it outranks NC's global input margin rule. */
.room-browser__filter input.room-browser__date {
    width: 100%;
    box-sizing: border-box;
    min-height: var(--default-clickable-area);
    margin: 0;
}

.room-browser__note {
    margin: 0;
}

.room-browser__hint,
.room-browser__summary {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.room-browser__summary {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-height: var(--th-icon-body);
    color: var(--color-main-text);
}

.room-browser__section {
    margin: var(--th-space-sm) 0 0;
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
}

/* The rows and the hour scale share one grid, so a label sits over its line. */
.room-browser__grid {
    display: grid;
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) minmax(0, 5fr);
    align-items: center;
    column-gap: var(--th-space-md);
}

.room-browser__axis {
    padding-inline: var(--th-space-md);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.room-browser__scale {
    position: relative;
    display: block;
    height: var(--th-space-lg);
}

/* A zero-width box at the hour, its text centred over it: works the same in
   both reading directions, unlike a translate. */
.room-browser__tick {
    position: absolute;
    inset-block-start: 0;
    display: flex;
    justify-content: center;
    width: 0;
    white-space: nowrap;
}

.room-browser__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

/* Selection row (raw <button> carve-out). font-weight resets NC's global
   bold on raw buttons, so only the room name is emphasised. */
.room-browser__row {
    width: 100%;
    min-height: auto;
    margin: 0;
    padding: var(--th-space-sm) var(--th-space-md);
    row-gap: var(--th-space-xs);
    text-align: start;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-regular);
    color: var(--color-main-text);
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
    cursor: pointer;
}

.room-browser__row:hover {
    background: var(--color-background-hover);
}

.room-browser__row:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.room-browser__row--selected,
.room-browser__row--selected:hover {
    background: var(--color-primary-element-light);
    color: var(--color-primary-element-light-text);
    border-color: var(--color-primary-element);
    box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.room-browser__row--muted:not(.room-browser__row--selected) .room-browser__name {
    color: var(--color-text-maxcontrast);
}

.room-browser__room,
.room-browser__state {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    min-width: 0;
}

.room-browser__name {
    font-weight: var(--th-font-weight-semibold);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.room-browser__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    column-gap: var(--th-space-sm);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.room-browser__icon-text {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
}

.room-browser__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
}

.room-browser__when {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
    .room-browser {
        padding: var(--th-space-md);
    }

    .room-browser__grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .room-browser__axis-gap {
        display: none;
    }
}
</style>
