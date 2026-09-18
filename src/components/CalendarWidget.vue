<template>
    <div class="th-widget">
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>
        <div v-else-if="rows.length === 0" class="th-widget__state th-widget__state--empty">
            <CalendarIcon :size="18" aria-hidden="true" />
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
                            <VideoIcon :size="14" />
                            {{
                                // TRANSLATORS: short button label to join a video/conference meeting link
                                t('teamhub', 'Join')
                            }}
                        </a>
                    </div>
                    <div class="th-cal__meta">
                        <span>{{ formatTimeRange(event.start, event.end, event.allDay) }}</span>
                        <span v-if="locationText(event)" class="th-cal__meta-sep">
                            <MapMarkerIcon :size="12" />{{ locationText(event) }}
                        </span>
                        <!-- Source pills: calendar name + app label.
                             Use shared outline pill vocabulary. -->
                        <span
                            v-if="event.calendarName && resources.calendar && resources.calendar.length > 1"
                            class="th-widget__pill th-widget__pill--outline th-widget__pill--neutral th-cal__calname"
                            :title="event.calendarName">
                            {{ truncate(event.calendarName, 20) }}
                        </span>
                        <span
                            v-if="event.source === 'openproject'"
                            class="th-widget__pill th-widget__pill--outline th-widget__pill--neutral"
                            :title="t('teamhub', 'A meeting scheduled in OpenProject')">
                            {{ t('teamhub', 'OpenProject') }}
                        </span>
                        <template v-else>
                            <span class="th-widget__pill th-widget__pill--outline th-widget__pill--primary">
                                {{ t('teamhub', 'Calendar') }}
                            </span>
                            <!-- v4.9.10 — a copy the OpenProject meeting sync wrote
                                 into the team calendar: both pills, one row. -->
                            <span
                                v-if="event.openProjectMeetingId"
                                class="th-widget__pill th-widget__pill--outline th-widget__pill--neutral"
                                :title="t('teamhub', 'A meeting scheduled in OpenProject, copied into the team calendar')">
                                {{ t('teamhub', 'OpenProject') }}
                            </span>
                        </template>
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
import { translate as t } from '@nextcloud/l10n'
import { formatTime, formatIsoDate, zonedIsoDate, todayIso, shiftIsoDate } from '../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import { isPlainClick } from '../lib/internalLinks.js'
import axios from '@nextcloud/axios'
import { NcLoadingIcon } from '@nextcloud/vue'
import CalendarIcon  from 'vue-material-design-icons/Calendar.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import VideoIcon     from 'vue-material-design-icons/Video.vue'

export default {
    name: 'CalendarWidget',
    components: { NcLoadingIcon, CalendarIcon, MapMarkerIcon, VideoIcon },
    data() {
        return {
            loading: false,
            events: [],
            /**
             * v4.9.7 — the linked OpenProject project's upcoming meetings,
             * fetched beside the calendar's events (never merged into the
             * calendar itself). A failed read is silent here — the Project
             * info widget carries the OpenProject state for the team home.
             */
            opMeetings: [],
        }
    },
    computed: {
        ...mapState(['currentTeamId', 'resources', 'widgetRefreshNonce', 'openProjectConfig']),

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
    },
    methods: {
        t,

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

        truncate(str, max) {
            if (!str) return ''
            return str.length > max ? str.slice(0, max) + '…' : str
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

        formatTimeRange(start, end, allDay) {
            if (!start) return ''
            const iso = this.eventIsoDay(start, allDay)
            const today = todayIso()

            let dateLabel = ''
            if (iso === today) {
                dateLabel = t('teamhub', 'Today')
            } else if (iso === shiftIsoDate(today, { days: 1 })) {
                dateLabel = t('teamhub', 'Tomorrow')
            } else {
                dateLabel = formatIsoDate(iso, { weekday: 'short', month: 'short', day: 'numeric' })
            }

            if (allDay) return dateLabel

            const timeOpts = { hour: '2-digit', minute: '2-digit' }
            const startStr = formatTime(start, timeOpts)

            if (end) {
                const endStr = formatTime(end, timeOpts)
                return `${dateLabel}  ${startStr} – ${endStr}`
            }

            return `${dateLabel}  ${startStr}`
        },

        /**
         * Returns the join URL if the event location looks like a video/talk link,
         * otherwise null. Checks for https:// URLs in location or description.
         */
        joinUrl(event) {
            const candidates = [event.location, event.description]
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
            const loc = event.location
            if (!loc) return null
            if (/^https?:\/\//i.test(loc.trim())) return null
            return loc
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
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
}
.th-cal__date-badge-month {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--color-primary-element);
    line-height: 1;
    text-transform: uppercase;
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
    gap: 2px;
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
    gap: 6px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}

.th-cal__meta-sep {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.th-cal__meta-sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}

/* Join meeting — primary action; uses NC primary directly */
.th-cal__join-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    padding: 2px 8px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-pill-weight);
    border-radius: var(--border-radius-pill);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    text-decoration: none;
    transition: opacity 0.15s;
}
.th-cal__join-btn:hover { opacity: 0.85; }

/* Constrain calendar-name outline pill width */
.th-cal__calname {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
