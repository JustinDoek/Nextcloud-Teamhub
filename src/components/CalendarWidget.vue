<template>
    <div class="th-widget">
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>
        <div v-else-if="events.length === 0" class="th-widget__state th-widget__state--empty">
            <CalendarIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No upcoming events') }}</span>
        </div>
        <ul v-else class="th-widget__rows">
            <li v-for="event in events" :key="event.id" class="th-widget__row">
                <!-- Date badge -->
                <div class="th-cal__date-badge" aria-hidden="true">
                    <span class="th-cal__date-badge-month">{{ formatMonth(event.start) }}</span>
                    <span class="th-cal__date-badge-day">{{ formatDay(event.start) }}</span>
                </div>

                <!-- Main content -->
                <div class="th-cal__body">
                    <div class="th-cal__title-row">
                        <a
                            v-if="event.editUrl"
                            :href="event.editUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-cal__title"
                            :title="t('teamhub', 'Open in Calendar')">
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
                        <span class="th-widget__pill th-widget__pill--outline th-widget__pill--primary">
                            {{ t('teamhub', 'Calendar') }}
                        </span>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon } from '@nextcloud/vue'
import CalendarIcon  from 'vue-material-design-icons/Calendar.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import VideoIcon     from 'vue-material-design-icons/Video.vue'

export default {
    name: 'CalendarWidget',
    components: { NcLoadingIcon, CalendarIcon, MapMarkerIcon, VideoIcon },
    data() {
        return { loading: false, events: [] }
    },
    computed: {
        ...mapState(['currentTeamId', 'resources']),
    },
    watch: {
        currentTeamId: { immediate: true, handler() { this.loadEvents() } },
    },
    methods: {
        t,

        truncate(str, max) {
            if (!str) return ''
            return str.length > max ? str.slice(0, max) + '…' : str
        },

        async loadEvents() {
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
        },

        /**
         * Public method: called by parent (TeamWidgetGrid → TeamView) after an event
         * is created to refresh the widget without a full page reload.
         */
        refresh() {
            return this.loadEvents()
        },

        formatMonth(start) {
            if (!start) return ''
            return new Date(start).toLocaleDateString([], { month: 'short' }).toUpperCase()
        },

        formatDay(start) {
            if (!start) return ''
            return new Date(start).getDate()
        },

        formatTimeRange(start, end, allDay) {
            if (!start) return ''
            const s = new Date(start)
            const now = new Date()
            const today    = new Date(now.getFullYear(), now.getMonth(), now.getDate())
            const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1)
            const eventDay = new Date(s.getFullYear(), s.getMonth(), s.getDate())

            let dateLabel = ''
            if (eventDay.getTime() === today.getTime()) {
                dateLabel = t('teamhub', 'Today')
            } else if (eventDay.getTime() === tomorrow.getTime()) {
                dateLabel = t('teamhub', 'Tomorrow')
            } else {
                dateLabel = s.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
            }

            if (allDay) return dateLabel

            const timeOpts = { hour: '2-digit', minute: '2-digit' }
            const startStr = s.toLocaleTimeString([], timeOpts)

            if (end) {
                const e = new Date(end)
                const endStr = e.toLocaleTimeString([], timeOpts)
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
    background: var(--color-background-dark, #f4f4f4);
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
    font-size: 16px;
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
