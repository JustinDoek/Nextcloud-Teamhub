<template>
    <div class="calendar-widget">
        <div v-if="loading" class="calendar-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>
        <div v-else-if="events.length === 0" class="calendar-widget__empty">
            {{ t('teamhub', 'No upcoming events') }}
        </div>
        <ul v-else class="calendar-widget__list">
            <li v-for="event in events" :key="event.id" class="calendar-event">
                <div class="calendar-event__time">
                    <CalendarIcon :size="16" />
                    <span>{{ formatEventTime(event.start) }}</span>
                </div>
                <div class="calendar-event__title">{{ event.title }}</div>
                <div v-if="event.location" class="calendar-event__location">
                    <MapMarkerIcon :size="14" />
                    {{ event.location }}
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
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'

export default {
    name: 'CalendarWidget',
    components: {
        NcLoadingIcon,
        CalendarIcon,
        MapMarkerIcon,
    },
    data() {
        return {
            loading: false,
            events: [],
        }
    },
    computed: {
        ...mapState(['currentTeamId']),
    },
    watch: {
        currentTeamId: {
            immediate: true,
            handler() {
                this.loadEvents()
            },
        },
    },
    methods: {
        t,
        async loadEvents() {
            if (!this.currentTeamId) return
            
            this.loading = true
            try {
                const response = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/calendar/events`)
                )
                this.events = response.data || []
            } catch (error) {
                this.events = []
            } finally {
                this.loading = false
            }
        },
        formatEventTime(start) {
            if (!start) return ''
            
            const date = new Date(start)
            const now = new Date()
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
            const tomorrow = new Date(today)
            tomorrow.setDate(tomorrow.getDate() + 1)
            const eventDate = new Date(date.getFullYear(), date.getMonth(), date.getDate())
            
            let dateStr = ''
            if (eventDate.getTime() === today.getTime()) {
                dateStr = t('teamhub', 'Today')
            } else if (eventDate.getTime() === tomorrow.getTime()) {
                dateStr = t('teamhub', 'Tomorrow')
            } else {
                dateStr = date.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
            }
            
            const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            return `${dateStr} ${timeStr}`
        },
    },
}
</script>

<style scoped>
.calendar-widget {
    padding: 0;
}

.calendar-widget__loading,
.calendar-widget__empty {
    text-align: left !important;
    padding: 12px 16px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.calendar-widget__list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.calendar-event {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border-dark);
}

.calendar-event:last-child {
    border-bottom: none;
}

.calendar-event__time {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-bottom: 4px;
}

.calendar-event__title {
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    margin-bottom: 2px;
}

.calendar-event__location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-top: 4px;
}
</style>
