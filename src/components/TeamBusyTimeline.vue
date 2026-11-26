<template>
    <section class="th-busy" :aria-label="t('teamhub', 'Busy times')">
        <div class="th-busy__toolbar">
            <div class="th-busy__nav">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Previous period')"
                    :title="t('teamhub', 'Previous period')"
                    @click="step(-1)">
                    <template #icon>
                        <ChevronLeftIcon :size="iconBody" />
                    </template>
                </NcButton>
                <NcButton variant="secondary" :disabled="showsToday" @click="goToday">
                    {{ t('teamhub', 'Today') }}
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Next period')"
                    :title="t('teamhub', 'Next period')"
                    @click="step(1)">
                    <template #icon>
                        <ChevronRightIcon :size="iconBody" />
                    </template>
                </NcButton>
                <h3 class="th-busy__range" aria-live="polite">{{ rangeLabel }}</h3>
            </div>

            <div class="th-busy__modes" role="group" :aria-label="t('teamhub', 'Period')">
                <NcButton variant="tertiary" :pressed="mode === 'day'" @click="setMode('day')">
                    {{ t('teamhub', 'Day') }}
                </NcButton>
                <NcButton variant="tertiary" :pressed="mode === 'week'" @click="setMode('week')">
                    {{ t('teamhub', 'Work week') }}
                </NcButton>
            </div>

            <!-- A width of its own for the select, as the Roster picker
                 (DESIGN §2.252), or it comes apart from its menu when open. -->
            <div v-if="people && people.length > 0" class="th-busy__people-field">
                <NcSelect
                    :model-value="selectedPeople"
                    :options="people"
                    :multiple="true"
                    :close-on-select="false"
                    :clearable="false"
                    label="displayName"
                    label-outside
                    :aria-label-combobox="t('teamhub', 'People whose busy times are shown')"
                    :placeholder="t('teamhub', 'Choose people')"
                    @update:model-value="onPick" />
            </div>

            <NcLoadingIcon v-if="loading" :size="iconBody" />
        </div>

        <p v-if="note" class="th-busy__note">{{ note }}</p>

        <NcNoteCard v-if="error" type="error">
            <p>{{ error }}</p>
            <NcButton variant="secondary" @click="reload">
                {{ t('teamhub', 'Try again') }}
            </NcButton>
        </NcNoteCard>

        <div v-if="selectedPeople.length > 0" class="th-busy__timeline">
            <!-- One scale for every row, on the same grid, so a label sits
                 over its line (the room finder's layout). -->
            <div class="th-busy__grid th-busy__axis" aria-hidden="true">
                <span />
                <span class="th-busy__scale">
                    <template v-if="mode === 'week'">
                        <span
                            v-for="d in marks.days"
                            :key="`day-${d.day}`"
                            class="th-busy__day-label"
                            :class="{ 'th-busy__day-label--today': d.day === todayStart }"
                            :style="{ insetInlineStart: `${d.offset}%`, width: `${d.size}%` }">{{ dayLabel(d.day) }}</span>
                    </template>
                    <template v-else>
                        <span
                            v-for="(h, i) in labelledHours"
                            :key="`hour-${i}`"
                            class="th-busy__tick"
                            :style="{ insetInlineStart: `${h.offset}%` }">{{ h.label }}</span>
                    </template>
                </span>
            </div>

            <div
                v-for="person in selectedPeople"
                :key="person.userId"
                class="th-busy__grid th-busy__row">
                <span class="th-busy__name" :title="person.displayName">
                    {{ person.self ? t('teamhub', 'You') : person.displayName }}
                </span>
                <span class="th-busy__track" role="img" :aria-label="trackLabel(person)">
                    <span
                        v-for="(h, i) in marks.hours"
                        :key="`h-${i}`"
                        class="th-busy__hour"
                        :style="{ insetInlineStart: `${h.offset}%` }" />
                    <span
                        v-for="d in dayLines"
                        :key="`d-${d.day}`"
                        class="th-busy__day-line"
                        :style="{ insetInlineStart: `${d.offset}%` }" />
                    <span
                        v-for="(bar, i) in barsFor(person)"
                        :key="`b-${i}`"
                        class="th-busy__bar"
                        :class="{ 'th-busy__bar--tentative': bar.type === 'BUSY-TENTATIVE' }"
                        :title="barTitle(bar)"
                        :style="{ insetInlineStart: `${bar.offset}%`, width: `${bar.size}%` }" />
                    <span
                        v-if="nowAt !== null"
                        class="th-busy__now"
                        :style="{ insetInlineStart: `${nowAt}%` }" />
                </span>
            </div>

            <p class="th-busy__legend">
                <span class="th-busy__legend-item">
                    <span class="th-busy__swatch" aria-hidden="true" />
                    {{ t('teamhub', 'Busy') }}
                </span>
                <span class="th-busy__legend-item">
                    <span class="th-busy__swatch th-busy__swatch--tentative" aria-hidden="true" />
                    {{ t('teamhub', 'Not confirmed') }}
                </span>
            </p>
        </div>
    </section>
</template>

<script>
/**
 * TeamBusyTimeline (v4.20.17, DESIGN §2.268): when the people on the team are
 * busy, as the room finder draws a room's day. A scale on top, one row per
 * picked person, a bar for each busy stretch. A day, or a work week at most.
 *
 * Replaces v4.20.15's blocks inside the calendar grid (§2.267), which Justin
 * found was not the view he meant. Where the times come from is unchanged:
 * one free/busy request to the viewer's own CalDAV outbox, answered by
 * Nextcloud with times only. `GET …/calendar/busy-people` says whom to ask;
 * the geometry and the request are pure functions in `src/lib/teamBusy.js`.
 *
 * Bars are plain bars with the small radius, never pills (Justin). Busy and
 * not confirmed differ in fill and in a dashed edge, so the difference does
 * not rest on colour alone, and every track has an accessible name listing
 * its times.
 */
import axios from '@nextcloud/axios'
import { generateUrl, generateRemoteUrl, getRootUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { ICON_BODY } from '../constants/uiTokens.js'
import { locale as ncLocale } from '../lib/localDate.js'
import { clockTime } from '../lib/roomFinder.js'
import {
    MAX_BUSY_PEOPLE,
    buildFreeBusyRequest,
    busyWindows,
    containsToday,
    emailKey,
    initialSelection,
    organizerAddress,
    periodsByEmail,
    readScheduleResponse,
    requestRange,
    shiftAnchor,
    startOfLocalDay,
    trackBars,
    trackMarks,
    trackOffset,
} from '../lib/teamBusy.js'

// Per viewer and per browser: the period and, per team, whom they picked. A
// convenience only, so every read and write survives storage being
// unavailable (private window, blocked site data).
const MODE_KEY = 'teamhub.calendar.busyMode'
const peopleKey = teamId => `teamhub.calendar.busyPeople.${teamId}`

function readStorage(key) {
    try {
        return window.localStorage?.getItem(key) ?? null
    } catch (e) {
        return null
    }
}

function writeStorage(key, value) {
    try {
        window.localStorage?.setItem(key, value)
    } catch (e) {
        // Not remembered; nothing else depends on it.
    }
}

export default {
    name: 'TeamBusyTimeline',

    components: {
        NcButton,
        NcSelect,
        NcNoteCard,
        NcLoadingIcon,
        ChevronLeftIcon,
        ChevronRightIcon,
    },

    props: {
        teamId: { type: String, required: true },
    },

    data() {
        return {
            mode: readStorage(MODE_KEY) === 'day' ? 'day' : 'week',
            anchor: Date.now(),
            now: Date.now(),
            // `null` until the list for this team has been asked for.
            people: null,
            organizer: null,
            max: MAX_BUSY_PEOPLE,
            notShown: 0,
            selected: [],
            byEmail: {},
            loading: false,
            error: '',
            capped: false,
            // Answers that arrive after a newer request was sent are dropped.
            seq: 0,
        }
    },

    computed: {
        iconBody() { return ICON_BODY },

        windows() {
            return busyWindows(this.anchor, this.mode)
        },

        marks() {
            return trackMarks(this.windows)
        },

        labelledHours() {
            return this.marks.hours.filter(h => h.label)
        },

        /** A stronger line where each weekday starts, the first excepted. */
        dayLines() {
            return this.mode === 'week' ? this.marks.days.slice(1) : []
        },

        todayStart() {
            return startOfLocalDay(this.now)
        },

        showsToday() {
            return containsToday(this.windows, this.now)
        },

        nowAt() {
            return trackOffset(this.windows, this.now)
        },

        selectedPeople() {
            const picked = new Set(this.selected)
            return (this.people || []).filter(p => picked.has(p.userId))
        },

        rangeLabel() {
            const first = this.windows[0].day
            if (this.mode === 'day') {
                return this.format(first, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
            }
            const last = this.windows[this.windows.length - 1].day
            return t('teamhub', '{start} to {end}', {
                start: this.format(first, { day: 'numeric', month: 'short' }),
                end: this.format(last, { day: 'numeric', month: 'short', year: 'numeric' }),
            })
        },

        /**
         * One line under the toolbar: that the cap was reached, why the list
         * is empty, or how many are not shown. Never names anybody; the
         * server does not say who switched it off, and neither does this.
         */
        note() {
            if (this.people === null) {
                return ''
            }
            if (this.capped) {
                return n('teamhub',
                    'Busy times can be shown for %n person at a time.',
                    'Busy times can be shown for %n people at a time.',
                    this.max, { n: this.max })
            }
            if (this.people.length === 0) {
                return t('teamhub', 'Nobody on this team shows busy times here. Busy times need an email address on the account.')
            }
            if (this.notShown > 0) {
                return n('teamhub',
                    '%n member is not shown: they switched busy times off, keep their email private or have none.',
                    '%n members are not shown: they switched busy times off, keep their email private or have none.',
                    this.notShown, { n: this.notShown })
            }
            return ''
        },
    },

    watch: {
        teamId() {
            this.reset()
            this.load()
        },
    },

    mounted() {
        this.load()
        // The now line moves; once a minute is as often as anybody can see.
        this._nowTimer = window.setInterval(() => { this.now = Date.now() }, 60 * 1000)
    },

    beforeUnmount() {
        window.clearInterval(this._nowTimer)
    },

    methods: {
        t,
        n,

        format(ms, opts) {
            // The browser's zone, like the geometry; the Nextcloud locale for
            // how a date is written.
            try {
                return new Intl.DateTimeFormat(ncLocale(), opts).format(new Date(ms))
            } catch (e) {
                return new Date(ms).toDateString()
            }
        },

        dayLabel(day) {
            return this.format(day, { weekday: 'short', day: 'numeric' })
        },

        setMode(mode) {
            if (mode === this.mode) {
                return
            }
            this.mode = mode
            writeStorage(MODE_KEY, mode)
            this.fetchBusy()
        },

        step(direction) {
            this.anchor = shiftAnchor(this.anchor, this.mode, direction)
            this.fetchBusy()
        },

        goToday() {
            this.anchor = Date.now()
            this.fetchBusy()
        },

        /** Bound to the Calendar tab's Reload, through the grid. */
        refresh() {
            this.fetchBusy()
        },

        reset() {
            this.seq++
            this.people = null
            this.organizer = null
            this.notShown = 0
            this.selected = []
            this.byEmail = {}
            this.error = ''
            this.capped = false
            this.loading = false
        },

        reload() {
            this.reset()
            this.load()
        },

        /** The people this team's calendar may show, once per team, then their times. */
        async load() {
            if (!this.teamId) {
                return
            }
            if (this.people === null) {
                const teamId = this.teamId
                const seq = ++this.seq
                this.loading = true
                this.error = ''
                try {
                    const { data } = await axios.get(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/calendar/busy-people`),
                    )
                    if (seq !== this.seq || teamId !== this.teamId) {
                        return
                    }
                    this.people = Array.isArray(data?.people) ? data.people : []
                    this.organizer = data?.organizer || null
                    this.max = Number(data?.maxPerRequest) > 0 ? Number(data.maxPerRequest) : MAX_BUSY_PEOPLE
                    this.notShown = Number(data?.notShown) || 0
                    let remembered = []
                    try {
                        remembered = JSON.parse(readStorage(peopleKey(teamId)) || '[]')
                    } catch (e) {
                        remembered = []
                    }
                    this.selected = initialSelection(this.people, this.max, Array.isArray(remembered) ? remembered : [])
                } catch (e) {
                    if (seq === this.seq) {
                        this.error = e.response?.data?.error
                            || t('teamhub', 'The busy times could not be loaded.')
                    }
                    return
                } finally {
                    if (seq === this.seq) {
                        this.loading = false
                    }
                }
            }
            this.fetchBusy()
        },

        onPick(options) {
            const ids = (options || []).map(o => o.userId)
            this.capped = ids.length > this.max
            const kept = ids.slice(0, this.max)
            const added = kept.some(id => !this.selected.includes(id))
            this.selected = kept
            writeStorage(peopleKey(this.teamId), JSON.stringify(kept))
            if (added) {
                this.fetchBusy()
            }
        },

        /**
         * One free/busy request for the picked people over the days shown,
         * to the viewer's own outbox: the request Nextcloud Calendar sends
         * for an event's attendees. A person Nextcloud cannot find is missing
         * from the answer and draws an empty row, never "free" by guess.
         */
        async fetchBusy() {
            const uid = getCurrentUser()?.uid
            if (!uid || !this.people || typeof DOMParser === 'undefined') {
                return
            }
            const emails = this.selectedPeople.map(p => p.email)
            if (emails.length === 0) {
                return
            }
            const { start, end } = requestRange(this.windows)
            const seq = ++this.seq
            this.loading = true
            this.error = ''
            try {
                const body = buildFreeBusyRequest({
                    organizer: organizerAddress(this.organizer, uid, getRootUrl()),
                    emails,
                    start,
                    end,
                    uid: window.crypto?.randomUUID?.() || `teamhub-${Date.now()}`,
                })
                const { data } = await axios.post(
                    `${generateRemoteUrl('dav')}/calendars/${encodeURIComponent(uid)}/outbox/`,
                    body,
                    {
                        headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
                        responseType: 'text',
                        transformResponse: [raw => raw],
                    },
                )
                if (seq !== this.seq) {
                    return
                }
                this.byEmail = periodsByEmail(readScheduleResponse(data, DOMParser))
            } catch (e) {
                if (seq === this.seq) {
                    this.error = t('teamhub', 'The busy times could not be loaded.')
                }
            } finally {
                if (seq === this.seq) {
                    this.loading = false
                }
            }
        },

        barsFor(person) {
            return trackBars(this.byEmail[emailKey(person.email)] || [], this.windows)
        },

        barTitle(bar) {
            const when = t('teamhub', '{start} to {end}', { start: clockTime(bar.start), end: clockTime(bar.end) })
            const prefix = this.mode === 'week' ? `${this.dayLabel(startOfLocalDay(bar.start))} ` : ''
            return bar.type === 'BUSY-TENTATIVE'
                // TRANSLATORS: tooltip on a bar in the busy times timeline; {when} is a day and time range. No event details are known.
                ? t('teamhub', 'Not confirmed, {when}', { when: prefix + when })
                // TRANSLATORS: tooltip on a bar in the busy times timeline; {when} is a day and time range. No event details are known.
                : t('teamhub', 'Busy, {when}', { when: prefix + when })
        },

        /** The track's accessible name: who, and every stretch, so nothing rests on the drawing. */
        trackLabel(person) {
            const name = person.self ? t('teamhub', 'You') : person.displayName
            const bars = this.barsFor(person)
            if (bars.length === 0) {
                // TRANSLATORS: accessible name of one person's row in the busy times timeline
                return t('teamhub', '{name}: no busy times in this period', { name })
            }
            // TRANSLATORS: accessible name of one person's row in the busy times timeline; {times} is a list of times
            return t('teamhub', '{name}: {times}', { name, times: bars.map(b => this.barTitle(b)).join('; ') })
        },
    },
}
</script>

<style scoped>
.th-busy {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    min-height: 0;
    overflow-y: auto;
}

.th-busy__toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm) var(--th-space-md);
}

.th-busy__nav,
.th-busy__modes {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
}

.th-busy__range {
    margin: 0;
    margin-inline-start: var(--th-space-sm);
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

/* An NcSelect in a row takes its width from its container (§2.252). */
.th-busy__people-field {
    flex: 0 1 auto;
    inline-size: calc(96 * var(--default-grid-baseline));
    max-inline-size: 100%;
}

.th-busy__people-field :deep(.v-select.select) {
    inline-size: 100%;
    margin-block-end: 0;
}

.th-busy__note,
.th-busy__legend {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-busy__timeline {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

/* The rows and the scale share one grid, so a label sits over its line. */
.th-busy__grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 9fr);
    align-items: center;
    column-gap: var(--th-space-md);
}

.th-busy__axis {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-busy__scale {
    position: relative;
    display: block;
    height: var(--th-space-lg);
}

/* A zero-width box at the hour, its text centred over it: the same in both
   reading directions, unlike a translate. */
.th-busy__tick {
    position: absolute;
    inset-block-start: 0;
    display: flex;
    justify-content: center;
    width: 0;
    white-space: nowrap;
}

.th-busy__day-label {
    position: absolute;
    inset-block-start: 0;
    padding-inline-start: var(--th-space-xs);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Today is bold as well as dark, not marked by colour alone. */
.th-busy__day-label--today {
    color: var(--color-main-text);
    font-weight: var(--th-font-weight-semibold);
}

.th-busy__row {
    padding-block: var(--th-space-xs);
}

.th-busy__name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-busy__track {
    position: relative;
    display: block;
    height: var(--th-space-xl);
    border-radius: var(--border-radius-small);
    background: var(--color-background-dark);
    overflow: hidden;
}

.th-busy__hour {
    position: absolute;
    inset-block: 0;
    width: 1px;
    background: var(--color-main-background);
    opacity: 0.6;
}

.th-busy__day-line {
    position: absolute;
    inset-block: 0;
    width: 2px;
    background: var(--color-main-background);
}

/* A plain bar with the small radius: no pill (Justin, 2026-11-16). It sits
   a little inside the track so two bars side by side stay two. */
.th-busy__bar,
.th-busy__swatch {
    box-sizing: border-box;
    border-radius: var(--border-radius-small);
    background: var(--color-primary-element);
    border: 1px solid var(--color-primary-element);
}

.th-busy__bar {
    position: absolute;
    inset-block: var(--th-space-xs);
    min-width: 2px;
}

/* Not confirmed: a light fill and a dashed edge, so it differs from busy in
   shape as well as colour (WCAG 1.4.1). */
.th-busy__bar--tentative,
.th-busy__swatch--tentative {
    background: var(--color-primary-element-light);
    border-style: dashed;
}

.th-busy__now {
    position: absolute;
    inset-block: 0;
    width: 2px;
    background: var(--color-main-text);
}

.th-busy__legend {
    display: flex;
    gap: var(--th-space-lg);
    margin-block-start: var(--th-space-xs);
}

.th-busy__legend-item {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
}

.th-busy__swatch {
    display: inline-block;
    inline-size: var(--th-space-lg);
    block-size: var(--th-space-md);
}

@media (max-width: 600px) {
    .th-busy__grid {
        grid-template-columns: minmax(0, 1fr);
        row-gap: var(--th-space-xs);
    }
    .th-busy__axis > span:first-child {
        display: none;
    }
}
</style>
