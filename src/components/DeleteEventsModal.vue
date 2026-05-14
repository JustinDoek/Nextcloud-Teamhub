<template>
    <NcModal
        :name="t('teamhub', 'Delete calendar events')"
        @close="$emit('close')">
        <div class="delete-events-modal">
            <h3 class="delete-events-modal__title">
                <CalendarRemove :size="20" aria-hidden="true" />
                {{ t('teamhub', 'Delete calendar events') }}
            </h3>

            <!-- Week navigation -->
            <div class="delete-events-modal__week-nav" role="group" :aria-label="t('teamhub', 'Select week')">
                <NcButton
                    type="tertiary"
                    :aria-label="t('teamhub', 'Previous week')"
                    @click="shiftWeek(-1)">
                    <template #icon><ChevronLeft :size="20" /></template>
                </NcButton>
                <span class="delete-events-modal__week-label">{{ weekLabel }}</span>
                <NcButton
                    type="tertiary"
                    :aria-label="t('teamhub', 'Next week')"
                    @click="shiftWeek(1)">
                    <template #icon><ChevronRight :size="20" /></template>
                </NcButton>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="delete-events-modal__loading">
                <NcLoadingIcon :size="24" />
                <span>{{ t('teamhub', 'Loading events…') }}</span>
            </div>

            <!-- Error state -->
            <p v-else-if="loadError" class="delete-events-modal__error">
                {{ loadError }}
            </p>

            <!-- Empty week -->
            <p v-else-if="events.length === 0" class="delete-events-modal__empty">
                {{ t('teamhub', 'No events this week.') }}
            </p>

            <!-- Event list grouped by day -->
            <div v-else class="delete-events-modal__list" role="list">
                <div
                    v-for="day in groupedByDay"
                    :key="day.label"
                    class="delete-events-modal__day-group">
                    <p class="delete-events-modal__day-label">{{ day.label }}</p>
                    <label
                        v-for="ev in day.events"
                        :key="ev.id + ev.calendarId"
                        class="delete-events-modal__event"
                        :class="{ 'delete-events-modal__event--checked': isChecked(ev) }">
                        <input
                            type="checkbox"
                            class="delete-events-modal__checkbox"
                            :checked="isChecked(ev)"
                            :aria-label="ev.title"
                            @change="toggleEvent(ev)" />
                        <span class="delete-events-modal__event-info">
                            <span class="delete-events-modal__event-title">{{ ev.title }}</span>
                            <span class="delete-events-modal__event-time">
                                {{ ev.allDay ? t('teamhub', 'All day') : formatTime(ev.start, ev.end) }}
                                <span v-if="calendars.length > 1" class="delete-events-modal__event-cal">
                                    · {{ ev.calendarName }}
                                </span>
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Selection summary -->
            <p v-if="checkedEvents.length > 0" class="delete-events-modal__selection-count">
                {{
                    n('teamhub',
                      '{n} event selected',
                      '{n} events selected',
                      checkedEvents.length,
                      { n: checkedEvents.length })
                }}
            </p>

            <!-- General error from delete attempt -->
            <p v-if="deleteError" class="delete-events-modal__error">{{ deleteError }}</p>

            <div class="delete-events-modal__actions">
                <NcButton
                    type="error"
                    :disabled="checkedEvents.length === 0 || deleting"
                    @click="confirmDelete">
                    <template #icon>
                        <NcLoadingIcon v-if="deleting" :size="18" />
                        <Delete v-else :size="18" />
                    </template>
                    {{ deleting
                        ? t('teamhub', 'Deleting…')
                        : n('teamhub', 'Delete {n} event', 'Delete {n} events', checkedEvents.length, { n: checkedEvents.length || 0 })
                    }}
                </NcButton>
                <NcButton type="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcModal, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
    name: 'DeleteEventsModal',

    components: { NcModal, NcButton, NcLoadingIcon, CalendarRemove, ChevronLeft, ChevronRight, Delete },

    props: {
        teamId:    { type: String, required: true },
        // [{ id, name }] — same shape as passed to AddEventModal
        calendars: { type: Array, default: () => [] },
    },

    emits: ['close', 'deleted'],

    data() {
        // Start on Monday of the current week.
        const monday = this.getMondayOfWeek(new Date())
        return {
            weekStart:    monday,   // Date object — Monday 00:00:00
            events:       [],       // raw events from API for this week
            checkedEvents: [],      // events the user has ticked
            loading:      false,
            loadError:    null,
            deleting:     false,
            deleteError:  null,
        }
    },

    computed: {
        weekLabel() {
            const end = new Date(this.weekStart)
            end.setDate(end.getDate() + 6)
            const fmt = (d) => d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
            return `${fmt(this.weekStart)} – ${fmt(end)}`
        },

        /** Events grouped into day buckets, sorted by start time. */
        groupedByDay() {
            const buckets = {}
            for (const ev of this.events) {
                const d = new Date(ev.start)
                const key = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
                if (!buckets[key]) {
                    buckets[key] = { label: key, sortKey: d.toDateString(), events: [] }
                }
                buckets[key].events.push(ev)
            }
            return Object.values(buckets).sort((a, b) => a.sortKey < b.sortKey ? -1 : 1)
        },
    },

    mounted() {
        this.fetchEvents()
    },

    methods: {
        t,
        n,

        getMondayOfWeek(date) {
            const d = new Date(date)
            const day = d.getDay() // 0=Sun … 6=Sat
            const diff = (day === 0 ? -6 : 1 - day)
            d.setDate(d.getDate() + diff)
            d.setHours(0, 0, 0, 0)
            return d
        },

        shiftWeek(delta) {
            const d = new Date(this.weekStart)
            d.setDate(d.getDate() + delta * 7)
            this.weekStart = d
            this.checkedEvents = []
            this.fetchEvents()
        },

        async fetchEvents() {
            this.loading   = true
            this.loadError = null
            this.events    = []
            try {
                const resp = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events/week`),
                    { params: { weekStart: this.weekStart.toISOString() } }
                )
                this.events = Array.isArray(resp.data) ? resp.data : []
            } catch (e) {
                this.loadError = t('teamhub', 'Failed to load events')
            } finally {
                this.loading = false
            }
        },

        isChecked(ev) {
            return this.checkedEvents.some(c => c.uri === ev.uri && c.calendarId === ev.calendarId)
        },

        toggleEvent(ev) {
            if (this.isChecked(ev)) {
                this.checkedEvents = this.checkedEvents.filter(
                    c => !(c.uri === ev.uri && c.calendarId === ev.calendarId)
                )
            } else {
                this.checkedEvents = [...this.checkedEvents, ev]
            }
        },

        async confirmDelete() {
            if (this.checkedEvents.length === 0 || this.deleting) return
            this.deleting     = true
            this.deleteError  = null
            try {
                const payload = this.checkedEvents.map(ev => ({
                    calendarId: ev.calendarId,
                    uri:        ev.uri,
                    title:      ev.title,
                }))
                const resp = await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events`),
                    { data: { events: payload } }
                )
                const result = resp.data

                if (result.errors > 0 && result.deleted === 0) {
                    showError(t('teamhub', 'Failed to delete events'))
                    this.deleteError = t('teamhub', 'Could not delete the selected events. Please try again.')
                } else if (result.errors > 0) {
                    showError(
                        n('teamhub',
                          '{n} event could not be deleted',
                          '{n} events could not be deleted',
                          result.errors,
                          { n: result.errors })
                    )
                } else {
                    showSuccess(
                        n('teamhub',
                          '{n} event deleted',
                          '{n} events deleted',
                          result.deleted,
                          { n: result.deleted })
                    )
                }

                this.$emit('deleted')
                this.$emit('close')
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.deleteError = msg
                    ? t('teamhub', 'Failed to delete events: {error}', { error: msg })
                    : t('teamhub', 'Failed to delete events')
                showError(t('teamhub', 'Failed to delete events'))
            } finally {
                this.deleting = false
            }
        },

        formatTime(start, end) {
            const fmt = (iso) => new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
            if (!start) return ''
            if (!end)   return fmt(start)
            return `${fmt(start)} – ${fmt(end)}`
        },
    },
}
</script>

<style scoped>
.delete-events-modal {
    padding: 24px;
    min-width: 360px;
    max-width: 540px;
}

@media (max-width: 768px) {
    .delete-events-modal {
        min-width: 0;
        padding: 16px;
    }
}

.delete-events-modal__title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--color-main-text);
}

/* ── Week navigation ── */
.delete-events-modal__week-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 20px;
    padding: 6px 4px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
}

.delete-events-modal__week-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
    text-align: center;
    flex: 1;
}

/* ── States ── */
.delete-events-modal__loading {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 24px 0;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.delete-events-modal__empty {
    padding: 20px 0;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin: 0;
}

.delete-events-modal__error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 0 0 16px;
}

/* ── Event list ── */
.delete-events-modal__list {
    max-height: 340px;
    overflow-y: auto;
    margin-bottom: 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}

.delete-events-modal__day-group {
    border-bottom: 1px solid var(--color-border);
}

.delete-events-modal__day-group:last-child {
    border-bottom: none;
}

.delete-events-modal__day-label {
    margin: 0;
    padding: 6px 14px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-text-maxcontrast);
    background: var(--color-background-dark);
}

.delete-events-modal__event {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    cursor: pointer;
    transition: background 0.12s;
    border-bottom: 1px solid var(--color-border-dark);
}

.delete-events-modal__event:last-child {
    border-bottom: none;
}

.delete-events-modal__event:hover {
    background: var(--color-background-hover);
}

.delete-events-modal__event--checked {
    background: var(--color-primary-light);
}

.delete-events-modal__checkbox {
    margin-top: 2px;
    flex-shrink: 0;
    cursor: pointer;
    accent-color: var(--color-primary-element);
    width: 16px;
    height: 16px;
}

.delete-events-modal__event-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}

.delete-events-modal__event-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.delete-events-modal__event-time {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.delete-events-modal__event-cal {
    opacity: 0.8;
}

/* ── Footer ── */
.delete-events-modal__selection-count {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
}

.delete-events-modal__actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}
</style>
