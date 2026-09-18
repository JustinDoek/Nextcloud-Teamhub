<template>
    <div class="th-calgrid">
        <div v-if="error" class="th-calgrid__state th-calgrid__state--error">
            <!-- 32 is off the icon scale deliberately: it matches AppEmbed's
                 own error/loading state icons, which this replaces in the
                 viewport, rather than the in-content scale. -->
            <AlertCircleOutline :size="32" aria-hidden="true" />
            <strong>{{ t('teamhub', 'Could not load the calendar') }}</strong>
            <span>{{ error }}</span>
        </div>

        <div v-else-if="noCalendar" class="th-calgrid__state">
            <CalendarIcon :size="32" aria-hidden="true" />
            <strong>{{ t('teamhub', 'No calendar for this team') }}</strong>
            <span>{{ t('teamhub', 'Add a calendar in Manage team → Modules & integrations to see it here.') }}</span>
        </div>

        <template v-else>
            <!-- aria-live so a screen reader hears the count change when the
                 range moves; the grid itself is a table FullCalendar owns. -->
            <p class="th-calgrid__sr-status" aria-live="polite">
                {{ n('teamhub', '%n event in this period', '%n events in this period', events.length, { n: events.length }) }}
            </p>

            <div v-if="truncated" class="th-calgrid__notice">
                <AlertCircleOutline :size="iconBody" aria-hidden="true" />
                <span>{{ t('teamhub', 'This period has too many events to show them all. Narrow the view to see the rest.') }}</span>
            </div>

            <FullCalendar ref="fc" :options="calendarOptions" />

            <div v-if="loading" class="th-calgrid__loading">
                <NcLoadingIcon :size="iconNav" />
            </div>

            <!-- Event detail popover. Read-only by design: the grid shows, and
                 "Edit in Calendar" hands off to NC Calendar, which is the only
                 place an event can be changed. -->
            <NcModal
                v-if="selected"
                size="small"
                :name="selected.title || t('teamhub', 'Event')"
                @close="selected = null">
                <div class="th-calgrid__detail">
                    <h3 class="th-calgrid__detail-title">
                        {{ selected.title || t('teamhub', 'Untitled event') }}
                        <span v-if="selected.recurring" class="th-calgrid__chip">
                            <RepeatIcon :size="iconInline" aria-hidden="true" />
                            {{ t('teamhub', 'Repeats') }}
                        </span>
                        <span v-if="selected.status === 'CANCELLED'" class="th-calgrid__chip th-calgrid__chip--cancelled">
                            {{ t('teamhub', 'Cancelled') }}
                        </span>
                    </h3>

                    <dl class="th-calgrid__detail-list">
                        <div class="th-calgrid__detail-row">
                            <dt><ClockIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'When') }}</dt>
                            <dd>{{ formatWhen(selected) }}</dd>
                        </div>
                        <div v-if="selected.location" class="th-calgrid__detail-row">
                            <dt><MapMarkerIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'Where') }}</dt>
                            <dd>
                                <a
                                    v-if="joinUrl(selected)"
                                    :href="joinUrl(selected)"
                                    target="_blank"
                                    rel="noopener noreferrer">{{ selected.location }}</a>
                                <span v-else>{{ selected.location }}</span>
                            </dd>
                        </div>
                        <div v-if="selected.calendarName" class="th-calgrid__detail-row">
                            <dt><CalendarIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'Calendar') }}</dt>
                            <dd>{{ selected.calendarName }}</dd>
                        </div>
                        <div v-if="selected.organiser" class="th-calgrid__detail-row">
                            <dt><AccountIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'Organiser') }}</dt>
                            <dd>{{ selected.organiser }}</dd>
                        </div>
                        <div v-if="selected.attendees && selected.attendees.length" class="th-calgrid__detail-row">
                            <dt><AccountMultipleIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'Attendees') }}</dt>
                            <dd>
                                <ul class="th-calgrid__attendees">
                                    <li v-for="(a, i) in selected.attendees" :key="i">
                                        <!-- Status is spelled out, not shown as a
                                             colour alone — WCAG 1.4.1. -->
                                        <span>{{ a.name || a.email || t('teamhub', 'Unnamed') }}</span>
                                        <span class="th-calgrid__partstat">{{ partstatLabel(a.partstat) }}</span>
                                    </li>
                                </ul>
                            </dd>
                        </div>
                        <div v-if="selected.description" class="th-calgrid__detail-row">
                            <dt><TextIcon :size="iconBody" aria-hidden="true" />{{ t('teamhub', 'Description') }}</dt>
                            <!-- Plain interpolation, never v-html: this is
                                 calendar data from an arbitrary client. -->
                            <dd class="th-calgrid__description">{{ selected.description }}</dd>
                        </div>
                    </dl>

                    <div class="th-calgrid__detail-actions">
                        <NcButton
                            v-if="selected.editUrl"
                            variant="primary"
                            :href="editHref(selected)"
                            target="_blank"
                            rel="noopener noreferrer">
                            <template #icon>
                                <OpenInNew :size="iconNav" />
                            </template>
                            {{ t('teamhub', 'Edit in Calendar') }}
                        </NcButton>
                        <NcButton variant="tertiary" @click="selected = null">
                            {{ t('teamhub', 'Close') }}
                        </NcButton>
                    </div>
                </div>
            </NcModal>
        </template>
    </div>
</template>

<script>
/**
 * TeamCalendarGrid — the team's calendar, rendered by TeamHub (v4.6.20).
 *
 * Replaces the NC Calendar iframe on the Calendar tab. The iframe showed a
 * single calendar only by pointing at `/apps/calendar/p/{token}`, the *public*
 * share route, which meant every team calendar had to be published to the
 * internet: an `access = 4` row in `dav_shares` reachable unauthenticated, ICS
 * export and all. NC Calendar has no authenticated route that scopes the view
 * to one calendar — its `view#index` routes take a view and a date and nothing
 * else — so the choice was "one calendar, public" or "authenticated, every
 * calendar the user owns". Rendering it here is what makes "one calendar,
 * authenticated" possible at all.
 *
 * **Read-only on purpose.** Events come from
 * `GET /api/v1/teams/{teamId}/calendar/events/range`, which is membership-gated
 * and expands recurrence server-side. Editing stays in NC Calendar, reached
 * through the detail popover, so this component never has to reason about
 * recurrence-editing semantics ("this event / this and following / all"), which
 * is where a hand-built calendar would start owning real risk. Add and Delete
 * still work — they are TeamHub's own toolbar actions on TeamHub's own
 * endpoints, unchanged from the iframe era.
 *
 * The toolbar is not here: the host renders this inside `AppEmbed` in hosted
 * mode, so prev/next/today/view/Add/Delete/Suggest/Reload come from there and
 * stay identical to every other tab. `Open in new tab` disappears on its own,
 * because hosted mode passes no `url`.
 */
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { locale as ncLocale, formatDate, formatTime, formatIsoDate, shiftIsoDate, zonedIsoDate } from '../lib/localDate.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import TextIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import { ICON_INLINE, ICON_BODY, ICON_NAV } from '../constants/uiTokens.js'

export default {
    name: 'TeamCalendarGrid',

    components: {
        FullCalendar,
        NcButton,
        NcModal,
        NcLoadingIcon,
        AlertCircleOutline,
        CalendarIcon,
        ClockIcon,
        MapMarkerIcon,
        AccountIcon,
        AccountMultipleIcon,
        TextIcon,
        RepeatIcon,
        OpenInNew,
    },

    props: {
        teamId: { type: String, required: true },
        /**
         * FullCalendar view name, driven by the host's existing view selector.
         * The six values are exactly the ones the iframe's dropdown offered, so
         * switching to this component does not change the menu.
         */
        view: { type: String, default: 'dayGridMonth' },
        /** The date the host's prev/next/today buttons are pointing at. */
        date: { type: Date, required: true },
        /** False when the team has no calendar at all — shows the empty state. */
        hasCalendar: { type: Boolean, default: true },
    },

    emits: ['range-change'],

    data() {
        return {
            events: [],
            loading: false,
            error: '',
            truncated: false,
            selected: null,
            // The window currently loaded, so a view change that stays inside
            // it does not refetch. FullCalendar asks for a wider range than the
            // visible month (leading/trailing days), and week→list-week
            // switches often ask for the same dates twice.
            loadedFrom: null,
            loadedTo: null,
        }
    },

    computed: {
        // Exposed as computed getters because an imported constant is not
        // visible to an Options API template — same pattern as MyWorkView.
        iconInline() { return ICON_INLINE },
        iconBody()   { return ICON_BODY },
        iconNav()    { return ICON_NAV },

        noCalendar() {
            return !this.hasCalendar
        },

        calendarOptions() {
            return {
                plugins: [dayGridPlugin, timeGridPlugin, listPlugin],
                initialView: this.view,
                initialDate: this.date,
                // TeamHub's own bar supplies all navigation, so FullCalendar's
                // header would be a second set of the same controls.
                headerToolbar: false,
                height: '100%',
                // Read-only: no drag, no resize, no click-to-create. Without
                // the interaction plugin these are inert anyway, but stating
                // them keeps the intent legible next to the component's name.
                editable: false,
                selectable: false,
                eventStartEditable: false,
                eventDurationEditable: false,
                navLinks: false,
                dayMaxEvents: true,
                nowIndicator: true,
                // Match NC's own conventions rather than FullCalendar's US
                // defaults: weeks start Monday and times are 24-hour in every
                // locale this app ships in.
                firstDay: 1,
                locale: this.locale,
                // `timeZone` is deliberately left at FullCalendar's default of
                // 'local'. v6 resolves a named IANA zone only with a plugin
                // (@fullcalendar/luxon or moment-timezone), and neither is a
                // dependency here — setting one anyway falls back to UTC and
                // silently misplaces every event. So the grid lays events out
                // in the browser's zone while the detail modal reads them in
                // the Nextcloud zone. Identical unless the two disagree, and
                // Nextcloud writes core/timezone from the browser on login,
                // so they normally do not. Tracked in HANDOFF.md.
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                allDayText: t('teamhub', 'All day'),
                noEventsText: t('teamhub', 'No events in this period'),
                events: this.fcEvents,
                eventClick: this.onEventClick,
                datesSet: this.onDatesSet,
            }
        },

        locale() {
            // Nextcloud's Locale setting, not its Language setting — `lang`
            // is which words to use, `data-locale` is how to write a date.
            return ncLocale()
        },

        /**
         * The payload mapped to FullCalendar's event shape.
         *
         * `id` is the server's per-instance id (`uri#startTs`), not the UID —
         * a recurring series shares one UID across every occurrence, so keying
         * on UID would collapse five standups into one rendered event.
         */
        fcEvents() {
            return this.events.map(ev => ({
                id: ev.id,
                title: ev.title || t('teamhub', 'Untitled event'),
                start: ev.start,
                end: ev.end,
                allDay: ev.allDay,
                backgroundColor: ev.calendarColor || undefined,
                borderColor: ev.calendarColor || undefined,
                classNames: ev.status === 'CANCELLED' ? ['th-calgrid__event--cancelled'] : [],
                extendedProps: { source: ev },
            }))
        },
    },

    watch: {
        view(next) {
            this.withApi(api => api.changeView(next))
        },
        date(next) {
            this.withApi(api => api.gotoDate(next))
        },
        teamId() {
            // A different team invalidates the window as well as the events —
            // without clearing it, switching to a team whose calendar covers
            // the same dates would keep the previous team's rows.
            this.loadedFrom = null
            this.loadedTo = null
            this.events = []
            this.refresh()
        },
    },

    mounted() {
        this.observeSize()
    },

    beforeUnmount() {
        // Vue 3 never calls beforeDestroy, and an observer left attached to a
        // detached element keeps the component alive — see the HANDOFF note.
        this._sizeObserver?.disconnect()
        this._sizeObserver = null
    },

    methods: {
        t,
        n,

        /**
         * Re-measure whenever the container gains size (v4.6.20).
         *
         * The Calendar tab is preloaded *hidden*: `TeamView` renders the embed
         * with `v-if="preloadedViews.has('calendar') || …"` and hides it with
         * `v-show`, so this component almost always mounts inside a
         * `display: none` subtree. FullCalendar measures its container once at
         * render, and a hidden element measures zero — which is why the month
         * view came up mis-laid-out and empty, and why switching to Week and
         * back "fixed" it: `changeView()` forces a fresh layout at the size the
         * container has by then.
         *
         * A ResizeObserver is the right shape for it because the component
         * cannot otherwise know when it became visible — `v-show` toggles a
         * style on an ancestor and fires no event here. Going from
         * `display: none` to visible is a 0 → N size change, which is exactly
         * what this fires on.
         *
         * `updateSize()` rather than a re-render: it is FullCalendar's own
         * "you were resized, re-measure" call, and it repaints the events
         * already loaded rather than refetching them.
         */
        observeSize() {
            if (typeof ResizeObserver === 'undefined') {
                return
            }
            this._lastSize = { w: 0, h: 0 }
            this._sizeObserver = new ResizeObserver(entries => {
                for (const entry of entries) {
                    const w = Math.round(entry.contentRect.width)
                    const h = Math.round(entry.contentRect.height)
                    if (w === 0 || h === 0) {
                        // Hidden again — record it so becoming visible reads as
                        // a change rather than as the same size.
                        this._lastSize = { w, h }
                        continue
                    }
                    if (w === this._lastSize.w && h === this._lastSize.h) {
                        // Dedupe. updateSize() can itself alter scroll extents,
                        // and re-entering on an unchanged box is how a
                        // ResizeObserver ends up in a loop.
                        continue
                    }
                    this._lastSize = { w, h }
                    // rAF so the measurement happens after the browser has
                    // finished the layout pass that triggered this.
                    window.requestAnimationFrame(() => this.withApi(api => api.updateSize()))
                }
            })
            this._sizeObserver.observe(this.$el)
        },

        /**
         * Run something against FullCalendar's imperative API.
         *
         * Guarded because the ref is absent whenever the component is showing
         * one of its own states instead of the grid (no calendar, load error),
         * and the view/date watchers still fire in those states.
         */
        withApi(fn) {
            const api = this.$refs.fc?.getApi?.()
            if (api) {
                fn(api)
            }
        },

        /**
         * FullCalendar tells us which window it needs, which is the only
         * reliable source for it: a month view includes trailing and leading
         * days of the neighbouring months, and the list views use their own
         * spans. Asking the component beats recomputing the same arithmetic
         * from `view` and `date` and drifting from it.
         */
        onDatesSet(info) {
            this.$emit('range-change', { start: info.start, end: info.end })

            // Refetch only when the new window leaves what is already loaded.
            // Switching Week → List (week) asks for identical dates, and the
            // month grid's window covers every week inside it.
            if (this.loadedFrom && this.loadedTo
                && info.start >= this.loadedFrom && info.end <= this.loadedTo) {
                return
            }
            this.fetchRange(info.start, info.end)
        },

        /** Reload the window currently displayed. Bound to the toolbar's Reload. */
        refresh() {
            const api = this.$refs.fc?.getApi?.()
            if (!api) {
                return
            }
            const v = api.view
            this.loadedFrom = null
            this.loadedTo = null
            this.fetchRange(v.activeStart, v.activeEnd)
        },

        async fetchRange(start, end) {
            if (!this.teamId || !this.hasCalendar) {
                return
            }
            this.loading = true
            this.error = ''
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/calendar/events/range`),
                    { params: { start: start.toISOString(), end: end.toISOString() } },
                )
                this.events = Array.isArray(data?.events) ? data.events : []
                this.truncated = data?.truncated === true
                this.loadedFrom = start
                this.loadedTo = end
            } catch (e) {
                // Leaves whatever was last loaded on screen rather than
                // blanking the grid on a transient failure.
                this.error = e.response?.data?.error
                    || t('teamhub', 'The calendar could not be loaded. Try reloading.')
            } finally {
                this.loading = false
            }
        },

        onEventClick(info) {
            info.jsEvent?.preventDefault()
            this.selected = info.event.extendedProps.source
        },

        editHref(ev) {
            // The server builds a root-relative path; generateUrl would prefix
            // the app root onto something that is already absolute from the
            // web root, so it is used as-is.
            return ev.editUrl || ''
        },

        /**
         * A location that is an https URL is a join link (Talk, or any video
         * provider), matching what CalendarWidget already does with LOCATION.
         */
        joinUrl(ev) {
            const loc = (ev.location || '').trim()
            return /^https:\/\//i.test(loc) ? loc : null
        },

        formatWhen(ev) {
            const dateOpts = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }
            const timeOpts = { hour: '2-digit', minute: '2-digit', hour12: false }

            if (ev.allDay) {
                // CalendarService sends an all-day start/end as bare `Y-m-d`
                // (a floating date) and a timed one as `format('c')`. So this
                // branch never builds a Date: `new Date('2026-08-11')` parses
                // as UTC midnight, and rendering that through any zone west
                // of Greenwich reports the 10th. Arithmetic on the string has
                // no zone to get wrong.
                //
                // An all-day DTEND is exclusive — a one-day event ends on the
                // following date — so the label subtracts a day rather than
                // reporting a range one day longer than the event.
                if (!ev.end) {
                    return formatIsoDate(ev.start, dateOpts)
                }
                const lastDay = shiftIsoDate(ev.end, { days: -1 })
                if (lastDay === ev.start) {
                    return formatIsoDate(ev.start, dateOpts)
                }
                return formatIsoDate(ev.start, dateOpts)
                    + ' – ' + formatIsoDate(lastDay, dateOpts)
            }

            const startLabel = formatDate(ev.start, dateOpts)
                + ' ' + formatTime(ev.start, timeOpts)
            if (!ev.end) {
                return startLabel
            }
            // Same calendar day for the reader, not for the browser.
            if (zonedIsoDate(ev.end) === zonedIsoDate(ev.start)) {
                return startLabel + ' – ' + formatTime(ev.end, timeOpts)
            }
            return startLabel + ' – '
                + formatDate(ev.end, dateOpts)
                + ' ' + formatTime(ev.end, timeOpts)
        },

        partstatLabel(partstat) {
            switch (partstat) {
            case 'ACCEPTED':
                // TRANSLATORS: an attendee has accepted a meeting invitation
                return t('teamhub', 'Accepted')
            case 'DECLINED':
                // TRANSLATORS: an attendee has declined a meeting invitation
                return t('teamhub', 'Declined')
            case 'TENTATIVE':
                // TRANSLATORS: an attendee has tentatively accepted a meeting invitation
                return t('teamhub', 'Maybe')
            default:
                // TRANSLATORS: an attendee has not yet replied to a meeting invitation
                return t('teamhub', 'No reply')
            }
        },
    },
}
</script>

<style scoped>
.th-calgrid {
    position: relative;
    height: 100%;
    min-height: 0;
    padding: 8px 12px 12px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}

/* FullCalendar measures its own height; the flex child needs a definite basis
   or the grid collapses to zero in a flex column. */
.th-calgrid > :deep(.fc) {
    flex: 1 1 auto;
    min-height: 0;
}

/* ══════════════════════════════════════════════════════════════════════════
   Grid palette (v4.6.20)

   Every rule below is `:deep()`. FullCalendar renders its own DOM from its own
   component, so none of those elements carry this file's scope attribute and a
   plain selector compiles to `.fc-day-past[data-v-…]`, which matches nothing —
   the same trap the HANDOFF note records for `v-html` subtrees.

   Written against NC theme tokens rather than literal colours so the grid
   follows dark mode. "White" here means `--color-main-background`, which is
   white in the light theme and the dark surface in the dark one; a hard-coded
   #fff would leave white cells and black borders in dark mode.
   ══════════════════════════════════════════════════════════════════════════ */

.th-calgrid :deep(.fc) {
    /* FullCalendar's own variables, pointed at NC's. Setting these is cheaper
       and more complete than overriding the rules that consume them. */
    --fc-page-bg-color: var(--color-main-background);
    --fc-border-color: var(--color-border);
    --fc-neutral-bg-color: var(--color-background-hover);
    --fc-today-bg-color: transparent;
    --fc-now-indicator-color: var(--color-error);
    --fc-event-text-color: var(--color-primary-element-text);
}

/* Borders read as a shift in tone against the cell, never as a drawn line. */
.th-calgrid :deep(.fc-theme-standard td),
.th-calgrid :deep(.fc-theme-standard th),
.th-calgrid :deep(.fc-theme-standard .fc-scrollgrid) {
    border-color: var(--color-border);
}

/* ── Day cells ─────────────────────────────────────────────────────────────
   Past is recessed, today and the future sit on the page background. Past is
   tinted rather than greyed-out-as-disabled: the events on it are still real
   and still clickable. */
.th-calgrid :deep(.fc-day-past) {
    background-color: var(--color-background-dark);
}

.th-calgrid :deep(.fc-day-future),
.th-calgrid :deep(.fc-day-today) {
    background-color: var(--color-main-background);
}

/* Today is marked with a pill on its label, in every view, rather than by
   colouring the header cell or flooding the day. Two reasons: the events in
   today's column keep the same contrast as every other day, and it is the one
   marker that still reads once the header band itself is light. Same shape NC
   Calendar uses. The pill is a filled shape as well as a colour, so it is not
   signalled by colour alone — WCAG 1.4.1. */
.th-calgrid :deep(.fc-col-header-cell.fc-day-today) {
    background-color: var(--color-main-background);
}

.th-calgrid :deep(.fc-col-header-cell.fc-day-today .fc-col-header-cell-cushion) {
    display: inline-block;
    padding: 2px 6px;
    border-radius: var(--th-radius-control);
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-weight: var(--th-font-weight-bold);
}

.th-calgrid :deep(.fc-daygrid-day.fc-day-today .fc-daygrid-day-number) {
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-radius: var(--th-radius-pill);
    padding: 0 7px;
    font-weight: var(--th-font-weight-bold);
}

/* ── Day-of-week header ────────────────────────────────────────────────────
   One treatment for every view: no band, lighter text. This started out as a
   darker inverted bar and became this in stages — Week and Day first, because
   there the header sits on top of a full-height hour column and the pair read
   as a wall, then Month for consistency once the two views sat side by side.
   It is also what NC Calendar's own `fullcalendar.scss` does.

   Since Month and Week/Day now agree, the rule is written once, unscoped. The
   Week/Day block below no longer has to override the colours — only the axis
   and slot labels, which are timegrid-only elements.

   The list views keep an inverted day header: there the day is a divider
   between groups of rows rather than a column heading, and it needs the weight
   to read as a break. */
.th-calgrid :deep(.fc-col-header-cell) {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
}

.th-calgrid :deep(.fc-col-header-cell-cushion) {
    /* The token NC Calendar uses for exactly this. */
    color: var(--color-text-lighter);
    font-weight: var(--th-font-weight-medium);
    text-decoration: none;
}

/* The list-view day header keeps the inverted treatment, and is set in the
   list section below rather than here — it has to come after that section's
   blanket table-cell rule, which would otherwise flatten it. */

.th-calgrid :deep(.fc-list-day-cushion a) {
    color: var(--color-primary-element-text);
    font-weight: var(--th-font-weight-semibold);
    text-decoration: none;
}

/* ── Week / Day hour column ────────────────────────────────────────────────
   Only the timegrid-only elements are left here. The header cells used to need
   their own override to escape an inverted Month band; now that every view
   shares one header treatment, they are covered by the rules above and
   repeating them here would be two places to change.

   The axis is the piece that made the inverted treatment untenable in the first
   place: it runs the full height of the view, so a saturated column reads as a
   wall rather than as chrome, where the same colour on a single-row Month band
   read as a header. */
.th-calgrid :deep(.fc-timegrid-axis),
.th-calgrid :deep(.fc-timegrid-slot-label) {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
}

.th-calgrid :deep(.fc-timegrid-axis-cushion),
.th-calgrid :deep(.fc-timegrid-slot-label-cushion) {
    /* NC Calendar uses --color-text-lighter for exactly these. */
    color: var(--color-text-lighter);
    font-weight: var(--th-font-weight-medium);
    text-decoration: none;
}

/* Day numbers inside the month grid stay body text — only the header band is
   inverted. */
.th-calgrid :deep(.fc-daygrid-day-number) {
    color: var(--color-main-text);
    text-decoration: none;
}

/* ── Events ────────────────────────────────────────────────────────────────
   The backgroundColor set per event carries the calendar's own colour; these
   rules only handle shape and the text that sits on it. */
.th-calgrid :deep(.fc-event) {
    border-radius: var(--th-radius-chip);
    padding: 0 3px;
    font-size: var(--th-font-meta);
    cursor: pointer;
}

.th-calgrid :deep(.fc-event:focus-visible) {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

/* A dot-style event (month view default) keeps body text on the page
   background, so its title must not be inverted with the block events. */
.th-calgrid :deep(.fc-daygrid-dot-event) {
    color: var(--color-main-text);
}

.th-calgrid :deep(.fc-daygrid-dot-event:hover) {
    background-color: var(--color-background-hover);
}

/* ── List views ────────────────────────────────────────────────────────────
   FullCalendar paints no background on the list container, its table, or its
   event rows — the only list rule that sets one is `.fc-list-day-cushion`, the
   sticky per-day header. Everything else is transparent, so the list sat on
   whatever was behind it instead of on a surface of its own. Each level is
   given the page background explicitly. */
.th-calgrid :deep(.fc-list) {
    border-color: var(--color-border);
    background-color: var(--color-main-background);
}

.th-calgrid :deep(.fc-list-table),
.th-calgrid :deep(.fc-list-table td),
.th-calgrid :deep(.fc-list-table th) {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    border-color: var(--color-border);
}

/* Re-asserted after the blanket rule above, which would otherwise flatten the
   day header back to the page colour. */
.th-calgrid :deep(.fc-list-day-cushion),
.th-calgrid :deep(.fc-list-day th) {
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.th-calgrid :deep(.fc-list-event:hover td) {
    background-color: var(--color-background-hover);
}

.th-calgrid :deep(.fc-list-empty) {
    background-color: var(--color-main-background);
    color: var(--color-text-maxcontrast);
}

.th-calgrid__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 100%;
    text-align: center;
    color: var(--color-text-maxcontrast);
    padding: 24px;
}

.th-calgrid__state--error strong {
    color: var(--color-text-error, var(--color-error));
}

/* Visually hidden, still announced. */
.th-calgrid__sr-status {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.th-calgrid__notice {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-calgrid__loading {
    position: absolute;
    inset-block-start: 12px;
    inset-inline-end: 16px;
    z-index: 2;
}

/* A cancelled event still renders — knowing a meeting was cancelled is
   information — but reads as struck through rather than merely faded, so it is
   not signalled by colour alone. */
.th-calgrid :deep(.th-calgrid__event--cancelled) {
    text-decoration: line-through;
    opacity: 0.7;
}

.th-calgrid__detail {
    padding: 16px;
}

.th-calgrid__detail-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 12px;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-tight);
}

.th-calgrid__chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 8px;
    border-radius: var(--th-radius-pill);
    border: 1px solid var(--color-border);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
}

.th-calgrid__chip--cancelled {
    color: var(--color-text-error, var(--color-error));
    border-color: var(--color-error);
}

.th-calgrid__detail-list {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.th-calgrid__detail-row {
    display: grid;
    grid-template-columns: 132px 1fr;
    gap: 12px;
    align-items: start;
}

.th-calgrid__detail-row dt {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-calgrid__detail-row dd {
    margin: 0;
    font-size: var(--th-font-body);
    overflow-wrap: anywhere;
}

.th-calgrid__description {
    white-space: pre-wrap;
    max-height: 220px;
    overflow-y: auto;
}

.th-calgrid__attendees {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.th-calgrid__attendees li {
    display: flex;
    gap: 8px;
    justify-content: space-between;
}

.th-calgrid__partstat {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    white-space: nowrap;
}

.th-calgrid__detail-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}

@media (max-width: 500px) {
    .th-calgrid__detail-row {
        grid-template-columns: 1fr;
        gap: 2px;
    }
}
</style>
