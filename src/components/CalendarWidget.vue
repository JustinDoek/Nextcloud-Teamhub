<template>
    <div class="th-widget">
        <div v-if="loading" class="th-widget__state">
            <NcLoadingIcon :size="20" />
        </div>
        <div v-else-if="events.length === 0" class="th-widget__state">
            <CalendarIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'No upcoming events') }}</span>
        </div>
        <ul v-else class="th-widget__list">
            <li v-for="event in events" :key="event.id" class="th-widget__row">
                <!-- Date badge -->
                <div class="th-widget__badge th-widget__badge--calendar" aria-hidden="true">
                    <span class="th-widget__badge-month">{{ formatMonth(event.start) }}</span>
                    <span class="th-widget__badge-day">{{ formatDay(event.start) }}</span>
                </div>

                <!-- Main content -->
                <div class="th-widget__body">
                    <div class="th-widget__row-top">
                        <a
                            v-if="event.editUrl"
                            :href="event.editUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-widget__title th-widget__title--link"
                            :title="t('teamhub', 'Open in Calendar')">
                            {{ event.title }}
                        </a>
                        <span v-else class="th-widget__title">{{ event.title }}</span>
                        <!-- Join button — shown when location is a https URL (Talk or video link) -->
                        <a
                            v-if="joinUrl(event)"
                            :href="joinUrl(event)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="th-widget__join-btn"
                            :title="t('teamhub', 'Join meeting')">
                            <VideoIcon :size="14" />
                            {{ t('teamhub', 'Join') }}
                        </a>
                    </div>
                    <div class="th-widget__row-bottom">
                        <span class="th-widget__meta">{{ formatTimeRange(event.start, event.end, event.allDay) }}</span>
                        <span v-if="locationText(event)" class="th-widget__meta th-widget__meta--sep">
                            <MapMarkerIcon :size="12" />{{ locationText(event) }}
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
        ...mapState(['currentTeamId']),
    },
    watch: {
        currentTeamId: { immediate: true, handler() { this.loadEvents() } },
    },
    methods: {
        t,
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
.th-widget { padding: 0; }

/* Shared state: loading / empty */
.th-widget__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 20px 16px;
    color: var(--color-text-maxcontrast);
    font-size: 15px;
    text-align: center;
}
.th-widget__empty-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}

/* List */
.th-widget__list { list-style: none; padding: 0; margin: 0; }

.th-widget__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border);
}
.th-widget__row:last-child { border-bottom: none; }

/* Date badge */
.th-widget__badge {
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
.th-widget__badge--calendar {}
.th-widget__badge-month {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--color-primary-element);
    line-height: 1;
    text-transform: uppercase;
}
.th-widget__badge-day {
    font-size: 16px;
    font-weight: 700;
    color: var(--color-main-text);
    line-height: 1.1;
}

/* Body: stacked top/bottom rows */
.th-widget__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.th-widget__row-top {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.th-widget__row-bottom {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

/* Title */
.th-widget__title {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.th-widget__title--link {
    color: var(--color-main-text);
    text-decoration: none;
    cursor: pointer;
}

.th-widget__title--link:hover {
    color: var(--color-primary-element);
    text-decoration: underline;
}

/* Meta line */
.th-widget__meta {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}
.th-widget__meta--sep::before {
    content: '·';
    margin-right: 4px;
    color: var(--color-border-dark);
}

/* Join button */
.th-widget__join-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    padding: 2px 8px;
    font-size: 12px;
    font-weight: 600;
    border-radius: var(--border-radius-pill);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    text-decoration: none;
    transition: opacity 0.15s;
}
.th-widget__join-btn:hover { opacity: 0.85; }
</style>
