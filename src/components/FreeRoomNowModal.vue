<template>
    <NcDialog
        :name="t('teamhub', 'Free room now')"
        :open="true"
        size="normal"
        @update:open="v => { if (!v) $emit('close') }">
        <div class="free-room">
            <p class="free-room__intro">
                {{ t('teamhub', 'Rooms you can walk into now. Book one in a click.') }}
            </p>

            <div class="free-room__filters">
                <div v-if="buildingOptions.length > 1" class="free-room__filter free-room__filter--building">
                    <label class="free-room__label" :for="`${uid}-building`">{{ t('teamhub', 'Building') }}</label>
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
                <div class="free-room__filter">
                    <span class="free-room__label" :id="`${uid}-duration`">{{ t('teamhub', 'For') }}</span>
                    <div class="free-room__durations" role="group" :aria-labelledby="`${uid}-duration`">
                        <NcButton
                            v-for="option in durations"
                            :key="option.minutes"
                            :pressed="minutes === option.minutes"
                            @click="minutes = option.minutes">
                            {{ option.label }}
                        </NcButton>
                    </div>
                </div>
                <div class="free-room__filter free-room__filter--title">
                    <label class="free-room__label" :for="`${uid}-title`">{{ t('teamhub', 'Title') }}</label>
                    <NcTextField
                        :id="`${uid}-title`"
                        v-model="title"
                        label-outside
                        :aria-label="t('teamhub', 'Title')" />
                </div>
            </div>

            <NcLoadingIcon v-if="loadingRooms" :size="ICON_BODY" />
            <NcEmptyContent
                v-else-if="rooms.length === 0"
                :name="t('teamhub', 'No bookable rooms')"
                :description="t('teamhub', 'Your Nextcloud has no meeting rooms you can book.')">
                <template #icon>
                    <DoorOpenIcon :size="ICON_HERO" />
                </template>
            </NcEmptyContent>
            <p v-else-if="!building" class="free-room__hint">
                {{ t('teamhub', 'Choose a building to see which rooms are free.') }}
            </p>

            <template v-else>
                <NcNoteCard v-if="loadError" type="error">
                    <p>{{ t('teamhub', 'Could not check room availability') }}</p>
                    <NcButton variant="secondary" @click="fetchFree">
                        {{ t('teamhub', 'Retry') }}
                    </NcButton>
                </NcNoteCard>

                <p class="free-room__summary" aria-live="polite">
                    <template v-if="loading">
                        <NcLoadingIcon :size="ICON_INLINE" />
                        {{ t('teamhub', 'Checking availability') }}
                    </template>
                    <template v-else-if="!loadError && freeRooms.length === 0">
                        {{ n('teamhub', 'No room is free for the next %n minute.', 'No room is free for the next %n minutes.', minutes, { n: minutes }) }}
                    </template>
                    <template v-else-if="!loadError">
                        {{ n('teamhub', '%n room is free for the next {minutes} minutes', '%n rooms are free for the next {minutes} minutes', freeRooms.length, { n: freeRooms.length, minutes }) }}
                    </template>
                </p>

                <ul v-if="freeRooms.length > 0" class="free-room__list">
                    <li v-for="room in freeRooms" :key="room.key" class="free-room__row">
                        <span class="free-room__room">
                            <span class="free-room__name">{{ room.displayName }}</span>
                            <span class="free-room__meta">
                                <span v-if="room.floor">{{ room.floor }}</span>
                                <span v-if="room.capacity" class="free-room__icon-text">
                                    <AccountMultipleIcon :size="ICON_INLINE" />
                                    {{ n('teamhub', '%n seat', '%n seats', room.capacity, { n: room.capacity }) }}
                                </span>
                                <span v-if="hasVideo(room)" class="free-room__icon-text" :title="t('teamhub', 'Video conferencing')">
                                    <VideoIcon :size="ICON_INLINE" />
                                    <span class="hidden-visually">{{ t('teamhub', 'Video conferencing') }}</span>
                                </span>
                                <span>{{ untilLabel(room) }}</span>
                            </span>
                        </span>
                        <NcButton
                            variant="secondary"
                            :disabled="bookingKey !== ''"
                            :aria-label="t('teamhub', 'Book {room}', { room: room.displayName })"
                            @click="book(room)">
                            <template #icon>
                                <NcLoadingIcon v-if="bookingKey === room.key" :size="ICON_BODY" />
                            </template>
                            {{
                                // TRANSLATORS: button to book a meeting room right now
                                t('teamhub', 'Book')
                            }}
                        </NcButton>
                    </li>
                </ul>
            </template>
        </div>
    </NcDialog>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import DoorOpenIcon from 'vue-material-design-icons/DoorOpen.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import { ICON_BODY, ICON_HERO, ICON_INLINE } from '../constants/uiTokens.js'
import { UNPLACED, buildingOptionList, clockTime, freeUntil, initialBuilding, roomsInBuilding } from '../lib/roomFinder.js'

/** Shared with the room finder, so both open on the building last used. */
const STORAGE_KEY = 'teamhub.roomFinder.building'

let instanceCounter = 0

/**
 * FreeRoomNowModal (v4.20.8, licensed): the rooms of one building that are
 * free from this minute for the next 30 or 60 minutes, each bookable in one
 * click under a title the user can change. Opened from the Upcoming events
 * widget's menu, which only offers it on a licensed instance; the server's
 * `/rooms/free-now` refuses an unlicensed one regardless.
 *
 * Booking goes through the ordinary `POST …/calendar/events`, so the event
 * lands in the team calendar with the room invited, and the server checks the
 * room once more: a room taken between the list and the click answers 409,
 * and the list is refreshed. DESIGN §2.263.
 */
export default {
    name: 'FreeRoomNowModal',
    components: {
        NcButton,
        NcDialog,
        NcEmptyContent,
        NcLoadingIcon,
        NcNoteCard,
        NcSelect,
        NcTextField,
        AccountMultipleIcon,
        DoorOpenIcon,
        VideoIcon,
    },
    props: {
        teamId: { type: String, required: true },
    },
    emits: ['close', 'booked'],
    data() {
        instanceCounter += 1
        return {
            ICON_BODY,
            ICON_HERO,
            ICON_INLINE,
            uid: `free-room-${instanceCounter}`,
            rooms: [],
            loadingRooms: true,
            building: '',
            minutes: 30,
            title: t('teamhub', 'Quick meeting'),
            availability: {},
            slot: null,
            loading: false,
            loadError: false,
            bookingKey: '',
            requestSeq: 0,
        }
    },
    computed: {
        buildingOptions() {
            return buildingOptionList(this.rooms, t('teamhub', 'Other rooms'))
        },
        durations() {
            return [
                { minutes: 30, label: t('teamhub', '30 min') },
                { minutes: 60, label: t('teamhub', '1 hour') },
            ]
        },
        /** Free rooms, the ones free longest first, then by name. */
        freeRooms() {
            if (!this.building) return []
            const end = this.slot ? Date.parse(this.slot.end) : 0
            const horizon = end + 12 * 3600 * 1000
            return roomsInBuilding(this.rooms, this.building)
                .filter(room => this.availability[room.key]?.status === 'free')
                .map(room => ({ room, until: freeUntil(this.availability[room.key]?.busy || [], end, horizon) }))
                .sort((a, b) => ((b.until ?? Infinity) - (a.until ?? Infinity)) || a.room.displayName.localeCompare(b.room.displayName))
                .map(entry => entry.room)
        },
    },
    watch: {
        building() { this.fetchFree() },
        minutes() { this.fetchFree() },
    },
    mounted() {
        this.loadRooms()
    },
    methods: {
        t,
        n,

        async loadRooms() {
            this.loadingRooms = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams/{teamId}/rooms', { teamId: this.teamId }))
                this.rooms = Array.isArray(data?.rooms) ? data.rooms : []
            } catch (e) {
                this.rooms = []
            } finally {
                this.loadingRooms = false
            }
            let remembered = ''
            try {
                remembered = window.localStorage.getItem(STORAGE_KEY) || ''
            } catch (e) {
                remembered = ''
            }
            this.building = initialBuilding(this.rooms, '', remembered)
        },

        onBuildingChange(value) {
            this.building = value || ''
            try {
                window.localStorage.setItem(STORAGE_KEY, this.building)
            } catch (e) {
                // Per-viewer convenience only.
            }
        },

        async fetchFree() {
            if (!this.building) return
            const seq = ++this.requestSeq
            this.loading = true
            this.loadError = false
            const params = { minutes: this.minutes }
            if (this.building === UNPLACED) {
                params.unplaced = '1'
            } else {
                params.building = this.building
            }
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/teams/{teamId}/rooms/free-now', { teamId: this.teamId }),
                    { params },
                )
                if (seq !== this.requestSeq) return
                this.availability = (data && typeof data.rooms === 'object' && data.rooms) ? data.rooms : {}
                this.slot = data?.start && data?.end ? { start: data.start, end: data.end } : null
            } catch (e) {
                if (seq !== this.requestSeq) return
                this.availability = {}
                this.slot = null
                this.loadError = true
            } finally {
                if (seq === this.requestSeq) this.loading = false
            }
        },

        hasVideo(room) {
            return (room.features || []).some(f => f.includes('VIDEO'))
        },

        untilLabel(room) {
            if (!this.slot) return ''
            const end = Date.parse(this.slot.end)
            const until = freeUntil(this.availability[room.key]?.busy || [], end, end + 12 * 3600 * 1000)
            return until === null
                ? t('teamhub', 'Free for the rest of the day')
                : t('teamhub', 'Free until {time}', { time: clockTime(until) })
        },

        async book(room) {
            if (!this.slot || this.bookingKey) return
            this.bookingKey = room.key
            // Booked from now, not from when the list was loaded.
            const start = new Date(Math.max(Date.parse(this.slot.start), Math.floor(Date.now() / 60000) * 60000))
            const end = new Date(start.getTime() + this.minutes * 60000)
            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/teams/{teamId}/calendar/events', { teamId: this.teamId }),
                    {
                        title: this.title.trim() || t('teamhub', 'Quick meeting'),
                        start: start.toISOString(),
                        end: end.toISOString(),
                        includeTalk: 0,
                        roomEmail: room.email,
                        roomName: room.displayName,
                        roomId: (room.source === 'roomvox' || room.source === 'mixed') ? room.id : '',
                    },
                )
                // TRANSLATORS: {room} is a meeting room, {time} the clock time the booking ends
                showSuccess(t('teamhub', '{room} is booked until {time}', { room: room.displayName, time: clockTime(end.getTime()) }))
                this.$emit('booked')
                this.$emit('close')
            } catch (e) {
                const data = e?.response?.data
                if (e?.response?.status === 409 && data?.code === 'room_busy') {
                    showError(t('teamhub', '{room} was just booked by someone else. Pick another room.', { room: room.displayName }))
                    this.fetchFree()
                } else {
                    const msg = data?.error || ''
                    showError(msg
                        ? t('teamhub', 'Failed to book the room: {error}', { error: msg })
                        : t('teamhub', 'Failed to book the room'))
                }
            } finally {
                this.bookingKey = ''
            }
        },
    },
}
</script>

<style scoped>
.free-room {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
}

.free-room__intro,
.free-room__hint,
.free-room__summary {
    margin: 0;
    color: var(--color-text-maxcontrast);
}

.free-room__summary {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    min-height: var(--th-icon-body);
    color: var(--color-main-text);
}

.free-room__filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--th-space-md);
}

.free-room__filter {
    min-width: 0;
}

.free-room__filter--building,
.free-room__filter--title {
    flex: 1 1 calc(var(--th-space-xxl) * 6);
}

.free-room__filter :deep(.v-select.select) {
    margin: 0;
}

.free-room__label {
    display: block;
    margin-block-end: var(--th-space-xs);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.free-room__durations {
    display: flex;
    gap: var(--th-space-xs);
}

.free-room__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    list-style: none;
}

.free-room__row {
    display: flex;
    align-items: center;
    gap: var(--th-space-md);
    padding: var(--th-space-sm) var(--th-space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-element);
}

.free-room__room {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    flex: 1 1 auto;
    min-width: 0;
}

.free-room__name {
    font-weight: var(--th-font-weight-semibold);
}

.free-room__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    column-gap: var(--th-space-sm);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.free-room__icon-text {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
}
</style>
