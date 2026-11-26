<template>
    <div class="th-widget">
        <div v-if="loading" class="th-widget__state">
            <NcLoadingIcon :size="ICON_INLINE" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>
        <div v-else-if="rows.length === 0" class="th-widget__state th-widget__state--empty">
            <CalendarIcon :size="ICON_BODY" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No upcoming events') }}</span>
        </div>
        <ul v-else class="th-widget__rows">
            <li v-for="event in rows" :key="event.id" class="th-widget__row">
                <!-- Date badge -->
                <div class="th-cal__date-badge" aria-hidden="true">
                    <span class="th-cal__date-badge-month">{{ formatMonth(event.start, event.allDay) }}</span>
                    <span class="th-cal__date-badge-day">{{ formatDay(event.start, event.allDay) }}</span>
                </div>

                <!-- Main content -->
                <div class="th-cal__body">
                    <div class="th-cal__title-row">
                        <!-- v4.9.7 — an OpenProject meeting opens in OpenProject,
                             in a new tab; the title says so. -->
                        <a
                            v-if="event.source === 'openproject' && event.url"
                            :href="event.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-cal__title"
                            :title="t('teamhub', 'Opens in OpenProject')">
                            {{ event.title }}
                        </a>
                        <a
                            v-else-if="event.editUrl"
                            :href="eventUrl(event)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-cal__title"
                            :title="t('teamhub', 'Open in Calendar')"
                            @click="onOpenEvent($event, event)">
                            {{ event.title }}
                        </a>
                        <span v-else class="th-cal__title">{{ event.title }}</span>
                        <!-- Join button — shown when location is a https URL (Talk or video link) -->
                        <a
                            v-if="joinUrl(event)"
                            :href="joinUrl(event)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-cal__join-btn"
                            :title="t('teamhub', 'Join meeting')">
                            <VideoIcon :size="ICON_INLINE" />
                            {{
                                // TRANSLATORS: short button label to join a video/conference meeting link
                                t('teamhub', 'Join')
                            }}
                        </a>
                    </div>
                    <!-- v4.20.8 — second line: the times (the date is already in
                         the badge), who accepted, the room. v4.20.19: no
                         source chips; a click on the row opens the calendar. -->
                    <div class="th-cal__meta">
                        <span>{{ formatTimes(event) }}</span>
                        <span v-if="event.recurring" class="th-cal__icon-text" :title="t('teamhub', 'Repeats')">
                            <RepeatIcon :size="ICON_INLINE" />
                            <span class="hidden-visually">{{ t('teamhub', 'Repeats') }}</span>
                        </span>

                        <!-- Who accepted (licensed: the server only sends
                             `attendance` to a licensed instance). -->
                        <!-- Escape is handled here, on the trigger and in the panel:
                             NcPopover 9.8.0 passes `fallBackFocus` (sic) to
                             focus-trap, so a panel with nothing focusable in it
                             never takes focus and never sees Escape. -->
                        <NcPopover
                            v-if="event.attendance && event.attendance.total > 0"
                            popup-role="dialog"
                            :shown="openPopover === `att:${event.id}`"
                            @update:shown="v => setPopover(`att:${event.id}`, v)">
                            <template #trigger>
                                <!-- v4.20.19 — soft red while your own reply is
                                     owed, soft green when nothing is asked of
                                     you (DESIGN §2.270). The accessible name
                                     says the same, so it never rests on colour. -->
                                <NcButton
                                    size="small"
                                    variant="secondary"
                                    class="th-cal__pill"
                                    :class="replyOwed(event) ? 'th-cal__pill--owed' : 'th-cal__pill--settled'"
                                    :aria-label="attendanceLabel(event)"
                                    :title="attendanceLabel(event)"
                                    @keydown.esc="closePopover">
                                    <template #icon>
                                        <AccountCheckIcon :size="ICON_INLINE" />
                                    </template>
                                    <!-- v4.20.10 — the icon says "accepted"; the
                                         words are in the accessible name. -->
                                    {{ event.attendance.accepted }}/{{ event.attendance.total }}
                                </NcButton>
                            </template>
                            <div class="th-cal__popover" @keydown.esc="closePopover">
                                <!-- v4.20.10 — your answer, when you are invited. -->
                                <div v-if="event.attendance.canReply && event.uid" class="th-cal__reply">
                                    <p class="th-cal__popover-title">{{ t('teamhub', 'Your reply') }}</p>
                                    <div class="th-cal__reply-buttons" role="group" :aria-label="t('teamhub', 'Your reply')">
                                        <NcButton
                                            v-for="option in replyOptions"
                                            :key="option.status"
                                            size="small"
                                            :pressed="event.attendance.myStatus === option.status"
                                            :disabled="replying !== ''"
                                            @click="sendReply(event, option.status)">
                                            <template #icon>
                                                <NcLoadingIcon v-if="replying === `${event.id}:${option.status}`" :size="ICON_INLINE" />
                                            </template>
                                            {{ option.label }}
                                        </NcButton>
                                    </div>
                                </div>
                                <p class="th-cal__popover-title">{{ t('teamhub', 'Attendees') }}</p>
                                <ul class="th-cal__attendees">
                                    <li v-for="(person, i) in event.attendance.attendees" :key="`att-${i}`" class="th-cal__attendee">
                                        <CheckCircleIcon v-if="person.status === 'accepted'" :size="ICON_INLINE" class="th-cal__status th-cal__status--accepted" />
                                        <HelpCircleIcon v-else-if="person.status === 'tentative'" :size="ICON_INLINE" class="th-cal__status th-cal__status--tentative" />
                                        <CloseCircleIcon v-else-if="person.status === 'declined'" :size="ICON_INLINE" class="th-cal__status th-cal__status--declined" />
                                        <ClockOutlineIcon v-else :size="ICON_INLINE" class="th-cal__status" />
                                        <span class="th-cal__attendee-name">{{ person.name }}</span>
                                        <span v-if="person.organizer" class="th-cal__attendee-note">{{ t('teamhub', 'Organizer') }}</span>
                                        <span class="th-cal__attendee-note th-cal__attendee-status">{{ replyLabel(person.status) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </NcPopover>

                        <!-- The room: its details on click (licensed), its
                             name otherwise. -->
                        <template v-if="event.room">
                            <NcPopover
                                v-if="licensed && roomFor(event)"
                                popup-role="dialog"
                                :shown="openPopover === `room:${event.id}`"
                                @update:shown="v => setPopover(`room:${event.id}`, v)">
                                <template #trigger>
                                    <NcButton
                                        size="small"
                                        variant="tertiary"
                                        class="th-cal__pill"
                                        :aria-label="t('teamhub', 'Details of {room}', { room: event.room.name })"
                                        @keydown.esc="closePopover">
                                        <template #icon>
                                            <MapMarkerIcon :size="ICON_INLINE" />
                                        </template>
                                        {{ event.room.name }}
                                    </NcButton>
                                </template>
                                <div class="th-cal__popover th-cal__popover--compact" @keydown.esc="closePopover">
                                    <p class="th-cal__popover-title">{{ roomFor(event).displayName }}</p>
                                    <dl class="th-cal__room">
                                        <template v-if="roomFor(event).building">
                                            <dt>{{ t('teamhub', 'Building') }}</dt>
                                            <dd>{{ roomFor(event).building }}</dd>
                                        </template>
                                        <template v-if="roomFor(event).floor">
                                            <dt>{{ t('teamhub', 'Floor') }}</dt>
                                            <dd>{{ roomFor(event).floor }}</dd>
                                        </template>
                                        <template v-if="roomFor(event).roomNumber">
                                            <dt>{{ t('teamhub', 'Room number') }}</dt>
                                            <dd>{{ roomFor(event).roomNumber }}</dd>
                                        </template>
                                        <template v-if="roomFor(event).capacity">
                                            <dt>{{ t('teamhub', 'Seats') }}</dt>
                                            <dd>{{ roomFor(event).capacity }}</dd>
                                        </template>
                                        <template v-if="(roomFor(event).features || []).length">
                                            <dt>{{ t('teamhub', 'Equipment') }}</dt>
                                            <dd>{{ roomFor(event).features.map(featureLabel).join(', ') }}</dd>
                                        </template>
                                    </dl>
                                </div>
                            </NcPopover>
                            <span v-else class="th-cal__meta-sep">
                                <MapMarkerIcon :size="ICON_INLINE" />{{ event.room.name }}
                            </span>
                        </template>
                        <span v-else-if="locationText(event)" class="th-cal__meta-sep">
                            <MapMarkerIcon :size="ICON_INLINE" />{{ locationText(event) }}
                        </span>
                    </div>
                </div>
            </li>
        </ul>
        <!-- v4.9.7 — the OpenProject meetings are read live as the viewer
             and merged above; a failed read costs the meetings, never the
             events, and shows no line here: the Project info widget carries
             the OpenProject state for the whole team home (Justin,
             2026-09-14). -->
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatTime, formatIsoDate, zonedIsoDate } from '../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import { isPlainClick } from '../lib/internalLinks.js'
import { featureLabel } from '../lib/roomFeatureLabels.js'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcPopover } from '@nextcloud/vue'
import AccountCheckIcon from 'vue-material-design-icons/AccountCheck.vue'
import CalendarIcon  from 'vue-material-design-icons/Calendar.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import VideoIcon     from 'vue-material-design-icons/Video.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'

export default {
    name: 'CalendarWidget',
    components: {
        NcButton,
        NcLoadingIcon,
        NcPopover,
        AccountCheckIcon,
        CalendarIcon,
        CheckCircleIcon,
        ClockOutlineIcon,
        CloseCircleIcon,
        HelpCircleIcon,
        MapMarkerIcon,
        RepeatIcon,
        VideoIcon,
    },
    data() {
        return {
            ICON_BODY,
            ICON_INLINE,
            loading: false,
            events: [],
            /**
             * v4.9.7 — the linked OpenProject project's upcoming meetings,
             * fetched beside the calendar's events (never merged into the
             * calendar itself). A failed read is silent here — the Project
             * info widget carries the OpenProject state for the team home.
             */
            opMeetings: [],
            /**
             * v4.20.8 — the team's room catalogue (`/rooms`), for the room
             * details on a row. Fetched once per team, and only on a licensed
             * instance with a room on screen; null until then.
             */
            roomCatalogue: null,
            roomCatalogueTeam: '',
            /** v4.20.8 — which row popover is open (`att:<id>` / `room:<id>`), or ''. */
            openPopover: '',
            /** v4.20.10 — the reply being sent (`<event id>:<status>`), or ''. */
            replying: '',
        }
    },
    computed: {
        ...mapState(['currentTeamId', 'resources', 'widgetRefreshNonce', 'openProjectConfig', 'licensed']),

        /** v4.20.10 — the three answers to an invitation, as Calendar offers them. */
        replyOptions() {
            return [
                { status: 'accepted', label: t('teamhub', 'Accept') },
                { status: 'tentative', label: t('teamhub', 'Maybe') },
                { status: 'declined', label: t('teamhub', 'Decline') },
            ]
        },

        /** v4.9.7 — the team is linked to an OpenProject project. */
        openProjectLinked() {
            return !!(this.openProjectConfig?.linked && !this.openProjectConfig?.stale)
        },

        /**
         * v4.9.7 — calendar events and OpenProject meetings as one list,
         * soonest first. Each row keeps its source: the pill, the link and
         * the open behaviour differ.
         */
        rows() {
            // v4.9.10 — once a meeting has been copied into the team
            // calendar, the calendar's row is the one to show: the live
            // OpenProject row would be the same meeting twice.
            const copied = new Set(
                this.events.map((e) => e.openProjectMeetingId).filter((id) => id != null),
            )
            const live = this.opMeetings.filter((m) => !copied.has(m.meetingId))
            const all = [...this.events, ...live]
            all.sort((a, b) => String(a.start || '').localeCompare(String(b.start || '')))
            return all
        },

        /**
         * v4.5.9 — reload trigger. Combining the team and the home-view nonce
         * into one key means a team switch (which changes both) still fires a
         * single load rather than two.
         */
        reloadKey() {
            return `${this.currentTeamId}|${this.widgetRefreshNonce}`
        },
    },
    watch: {
        // Fires on mount, on team switch, and whenever the user lands back on
        // the home view — the widget stays mounted behind v-show, so without
        // the nonce it would keep showing whatever it fetched hours ago.
        reloadKey: { immediate: true, handler() { this.loadEvents() } },
        // v4.20.10 — Escape closes an open row popover wherever focus is. The
        // handlers on the trigger and the panel miss one case: after a click
        // on a reply button, focus can rest on the popover's own frame, which
        // NcPopover stops Escape on before our panel hears it.
        openPopover(value) {
            if (value) {
                window.addEventListener('keydown', this.onWindowKeydown, true)
            } else {
                window.removeEventListener('keydown', this.onWindowKeydown, true)
            }
        },
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onWindowKeydown, true)
    },
    methods: {
        t,
        n,

        /**
         * Absolute URL for an event. The backend hands us a root-relative path
         * (`/apps/calendar/…`), which breaks on a sub-directory install unless
         * it goes through generateUrl — it was previously used raw in the href.
         */
        eventUrl(event) {
            return event.editUrl ? generateUrl(event.editUrl) : ''
        },

        /**
         * Open the event inside TeamHub's calendar iframe rather than navigating
         * away to the Calendar app. Modified clicks (ctrl/cmd/shift/middle) fall
         * through to the native new tab.
         *
         * Hands over the backend's own URL — it targets the personal Calendar
         * app, which is the only place the event id resolves — plus the calendar
         * the event belongs to, so the tab can return to *that* agenda once the
         * event is closed rather than leaving the user in their own calendar.
         */
        onOpenEvent(domEvent, event) {
            if (!event.editUrl || !isPlainClick(domEvent)) {
                return
            }
            domEvent.preventDefault()
            this.$store.dispatch('openEventInEmbed', {
                url:        this.eventUrl(event),
                calendarId: event.calendarId ?? null,
            })
        },

        /**
         * @param {boolean} withMeetings false for the one reload the
         *   meeting sync asks for — the meetings were just fetched.
         */
        async loadEvents(withMeetings = true) {
            if (!this.currentTeamId) return
            this.loading = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/calendar/events`)
                )
                this.events = data || []
            } catch (e) {
                this.events = []
            } finally {
                this.loading = false
            }
            this.loadRoomCatalogue()
            // Behind the calendar's own rows, never ahead of them: the widget
            // renders the events as soon as they are in.
            if (withMeetings) {
                this.loadOpenProjectMeetings()
            }
        },

        /**
         * v4.9.7 — the linked project's upcoming meetings, as the viewer.
         * Shaped like a calendar row (`start`/`end` are instants, `allDay`
         * false, `editUrl` null) plus `source` and the OpenProject `url`.
         */
        async loadOpenProjectMeetings() {
            const teamId = this.currentTeamId
            if (!teamId || !this.openProjectLinked) {
                this.opMeetings = []
                return
            }
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/openproject/meetings`),
                )
                if (this.currentTeamId !== teamId) return
                this.opMeetings = (data?.items || []).map((m) => ({
                    id: 'op-meeting-' + m.id,
                    meetingId: m.id,
                    title: m.title,
                    start: m.start,
                    end: m.end,
                    location: m.location,
                    description: null,
                    allDay: false,
                    editUrl: null,
                    calendarId: null,
                    calendarName: '',
                    source: 'openproject',
                    url: /^https?:\/\//i.test(m.url || '') ? m.url : null,
                }))
                // v4.9.10 — the read copied something into the team calendar
                // (or took a copy away): the calendar's rows are stale by
                // exactly that much, so fetch them once more — without the
                // meetings, which were fetched a moment ago.
                const sync = data?.sync
                if (sync && (sync.created + sync.updated + sync.removed) > 0 && this.currentTeamId === teamId) {
                    this.loadEvents(false)
                }
            } catch (e) {
                // Silent, whatever the reason: the Project info widget
                // carries the OpenProject state (connect hint, broken
                // connection); two widgets nagging is one too many.
                this.opMeetings = []
            }
        },

        /**
         * Public method: called by parent (TeamWidgetGrid → TeamView) after an event
         * is created to refresh the widget without a full page reload.
         */
        refresh() {
            return this.loadEvents()
        },

        /**
         * The calendar day an event belongs on, `YYYY-MM-DD`.
         *
         * ActivityService emits `format('c')` for every event and carries
         * all-day as a separate flag — unlike CalendarService, which sends a
         * bare `Y-m-d`. So an all-day start arrives here already pinned to a
         * zone at midnight, and re-reading it through the reader's zone would
         * move it to the previous day west of Greenwich. Its date part is
         * taken verbatim instead. A timed event is a real instant and is read
         * in the reader's zone.
         */
        eventIsoDay(start, allDay) {
            return allDay ? String(start).slice(0, 10) : zonedIsoDate(start)
        },

        formatMonth(start, allDay) {
            if (!start) return ''
            return formatIsoDate(this.eventIsoDay(start, allDay), { month: 'short' }).toUpperCase()
        },

        formatDay(start, allDay) {
            if (!start) return ''
            return formatIsoDate(this.eventIsoDay(start, allDay), { day: 'numeric' })
        },

        /**
         * v4.20.8 — the row's times. The date is in the badge beside it, so
         * only an event that ends on another day names that day.
         */
        formatTimes(event) {
            if (!event.start) return ''
            if (event.allDay) return t('teamhub', 'All day')
            const timeOpts = { hour: '2-digit', minute: '2-digit' }
            const startStr = formatTime(event.start, timeOpts)
            if (!event.end) return startStr
            let endStr = formatTime(event.end, timeOpts)
            if (zonedIsoDate(event.end) !== zonedIsoDate(event.start)) {
                endStr = `${formatIsoDate(zonedIsoDate(event.end), { month: 'short', day: 'numeric' })} ${endStr}`
            }
            return `${startStr} - ${endStr}`
        },

        /**
         * v4.20.10 — answer an invitation from the attendee list. The server
         * records it through Nextcloud's own iTIP broker and reads it back;
         * the row is updated from the answer only once that succeeded. A
         * series is answered as a whole, so every row of it changes.
         */
        async sendReply(event, status) {
            if (this.replying) return
            this.replying = `${event.id}:${status}`
            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/teams/{teamId}/meetings/reply', { teamId: this.currentTeamId }),
                    { uid: event.uid, status },
                )
                for (const row of this.events) {
                    if (row.uid !== event.uid || !row.attendance) continue
                    const attendees = row.attendance.attendees.map(a => (a.self && !a.organizer ? { ...a, status } : a))
                    row.attendance = {
                        ...row.attendance,
                        myStatus: status,
                        attendees,
                        accepted: attendees.filter(a => a.status === 'accepted').length,
                    }
                }
                showSuccess({
                    accepted: t('teamhub', 'You accepted the meeting.'),
                    tentative: t('teamhub', 'You answered maybe.'),
                    declined: t('teamhub', 'You declined the meeting.'),
                }[status])
            } catch (e) {
                showError(t('teamhub', 'Your reply could not be saved. Try again, or reply in Calendar.'))
            } finally {
                this.replying = ''
            }
        },

        /** v4.20.8 — one row popover open at a time. */
        setPopover(key, shown) {
            if (shown) {
                this.openPopover = key
            } else if (this.openPopover === key) {
                this.openPopover = ''
            }
        },

        closePopover() {
            this.openPopover = ''
        },

        onWindowKeydown(event) {
            if (event.key === 'Escape' && this.openPopover) {
                this.closePopover()
            }
        },

        /** v4.20.8 — "2 of 5 accepted". */
        attendanceLabel(event) {
            const { accepted, total } = event.attendance
            if (this.replyOwed(event)) {
                // TRANSLATORS: replies to a meeting invitation the viewer has not answered yet, e.g. "2 of 5 accepted, your reply is needed"
                return n('teamhub', '%n of {total} accepted, your reply is needed', '%n of {total} accepted, your reply is needed', accepted, { n: accepted, total })
            }
            // TRANSLATORS: replies to a meeting invitation, e.g. "2 of 5 accepted"
            return n('teamhub', '%n of {total} accepted', '%n of {total} accepted', accepted, { n: accepted, total })
        },

        /** v4.20.19 — the viewer is invited and has not answered. */
        replyOwed(event) {
            return !!event.attendance?.canReply && event.attendance.myStatus === 'needs-action'
        },

        replyLabel(status) {
            switch (status) {
            case 'accepted': return t('teamhub', 'Accepted')
            case 'declined': return t('teamhub', 'Declined')
            case 'tentative': return t('teamhub', 'Maybe')
            default: return t('teamhub', 'No reply')
            }
        },

        /**
         * v4.20.8 — the catalogue entry of the event's room, matched on its
         * address, or null when the room is not (or no longer) bookable here.
         */
        roomFor(event) {
            if (!event.room || !Array.isArray(this.roomCatalogue)) return null
            const email = (event.room.email || '').toLowerCase()
            return this.roomCatalogue.find(r => (r.email || '').toLowerCase() === email) || null
        },

        /** The features Nextcloud's room metadata defines, in words. */
        featureLabel,

        async loadRoomCatalogue() {
            const teamId = this.currentTeamId
            if (!this.licensed || !teamId || !this.events.some(e => e.room)) return
            if (this.roomCatalogueTeam === teamId && this.roomCatalogue !== null) return
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams/{teamId}/rooms', { teamId }))
                if (this.currentTeamId !== teamId) return
                this.roomCatalogue = Array.isArray(data?.rooms) ? data.rooms : []
                this.roomCatalogueTeam = teamId
            } catch (e) {
                // Silent: the row keeps the room's name, without details.
                this.roomCatalogue = []
                this.roomCatalogueTeam = teamId
            }
        },

        /** The parts of LOCATION: TeamHub writes "Room | Talk link". */
        locationParts(event) {
            return String(event.location || '').split(' | ').map(p => p.trim()).filter(Boolean)
        },

        /**
         * Returns the join URL if the event location looks like a video/talk link,
         * otherwise null. Checks for https:// URLs in location or description.
         */
        joinUrl(event) {
            const candidates = [...this.locationParts(event), event.description]
            for (const candidate of candidates) {
                if (candidate && /^https?:\/\//i.test(candidate.trim())) {
                    return candidate.trim()
                }
            }
            return null
        },

        /**
         * Returns display text for location — omitted when location is a raw URL
         * (it becomes the join button instead).
         */
        locationText(event) {
            // v4.20.8 — "Room | https://…" shows the room; the link is the Join button.
            const text = this.locationParts(event).filter(p => !/^https?:\/\//i.test(p)).join(' | ')
            return text || null
        },
    },
}
</script>

<style scoped>
/* Widget-specific only — shared classes from widget-tokens.css */

/* Date badge — distinct to Calendar widget */
.th-cal__date-badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-element);
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
}
.th-cal__date-badge-month {
    font-size: var(--th-font-meta);
    font-weight: 700;
    color: var(--color-primary-element);
    line-height: 1;
}
.th-cal__date-badge-day {
    font-size: var(--th-font-heading);
    font-weight: 700;
    color: var(--color-main-text);
    line-height: 1.1;
}

.th-cal__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.th-cal__title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.th-cal__title {
    flex: 1;
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-cal__title:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

.th-cal__meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}

/* v4.20.19 — the acceptance pill: soft red while your reply is owed, soft
   green otherwise. NC's state tints and their inks (ui-standards).
   v4.20.21 — the class is doubled on purpose: NcButton's own
   `.button-vue--secondary` weighs the same as a single class and its
   stylesheet loads after ours, so a single class left the pill blue. The
   border is NcButton's too and takes the tint's hover shade. */
.th-cal__pill.th-cal__pill--owed {
    background-color: var(--color-error);
    border-color: var(--color-error-hover);
    color: var(--color-error-text);
}

.th-cal__pill.th-cal__pill--owed:hover:not(:disabled) {
    background-color: var(--color-error-hover);
    color: var(--color-error-text);
}

.th-cal__pill.th-cal__pill--settled {
    background-color: var(--color-success);
    border-color: var(--color-success-hover);
    color: var(--color-success-text);
}

.th-cal__pill.th-cal__pill--settled:hover:not(:disabled) {
    background-color: var(--color-success-hover);
    color: var(--color-success-text);
}

.th-cal__meta-sep {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.th-cal__meta-sep::before {
    content: '·';
    margin-inline-end: 4px;
    color: var(--color-border-dark);
}

/* Join meeting — primary action; uses NC primary directly */
.th-cal__join-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    padding: 4px 8px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-pill-weight);
    border-radius: var(--border-radius-pill);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    text-decoration: none;
    transition: opacity var(--animation-quick);
}
.th-cal__join-btn:hover { opacity: 0.85; }

/* v4.20.8 — the acceptance and room buttons sit in the meta line. */
.th-cal__icon-text {
    display: inline-flex;
    align-items: center;
}

.th-cal__popover {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    max-width: calc(var(--th-space-xxl) * 10);
    padding: var(--th-space-sm) var(--th-space-md);
}

/* v4.20.10 — the room details: a few short lines, so no padding of their own
   beyond keeping the text off the popover's edge. */
.th-cal__popover--compact {
    gap: var(--th-space-xs);
    padding: var(--th-space-xs) var(--th-space-sm);
}

.th-cal__popover--compact .th-cal__room {
    row-gap: 0;
}

.th-cal__popover-title {
    margin: 0;
    font-weight: var(--th-font-weight-semibold);
}

.th-cal__reply {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    padding-block-end: var(--th-space-sm);
    border-block-end: 1px solid var(--color-border);
}

.th-cal__reply-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
}

.th-cal__attendees {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    list-style: none;
}

.th-cal__attendee {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
}

.th-cal__attendee-name {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-cal__attendee-note {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-cal__status {
    flex: 0 0 auto;
    color: var(--color-text-maxcontrast);
}

.th-cal__status--accepted {
    color: var(--color-element-success);
}

.th-cal__status--tentative {
    color: var(--color-element-warning);
}

.th-cal__status--declined {
    color: var(--color-element-error);
}

.th-cal__room {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--th-space-xs) var(--th-space-md);
    margin: 0;
    /* Nextcloud's server CSS pads every dl (12px block) and every dt/dd
       (12px on three sides); that, not our own padding, made the room
       details tall (found on claudius, v4.20.10). */
    padding: 0;
}

.th-cal__room dt {
    padding: 0;
    color: var(--color-text-maxcontrast);
}

.th-cal__room dd {
    margin: 0;
    padding: 0;
}

/* Constrain calendar-name outline pill width */
.th-cal__calname {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
