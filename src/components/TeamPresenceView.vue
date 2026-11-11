<template>
    <div class="team-presence-view">

        <!-- ── Toolbar: day navigation only ─────────────────────────────── -->
        <div class="presence-toolbar" role="toolbar" :aria-label="t('teamhub', 'Presence controls')">
            <div class="presence-toolbar__nav" role="group" :aria-label="t('teamhub', 'Date navigation')">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Previous week')"
                    @click="navigateBack">
                    <template #icon><ChevronLeftIcon :size="ICON_BODY" /></template>
                </NcButton>

                <span class="presence-toolbar__period" aria-live="polite">
                    {{ periodLabel }}
                </span>

                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Next week')"
                    @click="navigateForward">
                    <template #icon><ChevronRightIcon :size="ICON_BODY" /></template>
                </NcButton>

                <NcButton
                    v-if="!isCurrentPeriod"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Back to today')"
                    @click="goToToday">
                    {{ t('teamhub', 'Today') }}
                </NcButton>
            </div>

            <!-- Hide-reasons badge (shown to admins when enabled) -->
            <div
                v-if="isTeamAdmin && hideReasons"
                class="presence-toolbar__privacy-badge"
                :title="t('teamhub', 'Status details are hidden from team members')">
                <EyeOffIcon :size="ICON_BODY" />
                {{ t('teamhub', 'Details hidden') }}
            </div>
        </div>

        <!-- ── Status filter ───────────────────────────────────
             Pills, not dropdowns. `/ui-standards`: "A filter pill is
             TogglePill.vue." Three NcSelects were the wrong control and could
             not have been made to fit: `.v-select.select` carries
             `min-width: 260px` in @nextcloud/vue's own stylesheet, so three of
             them need 780px before gaps, and pinning a smaller max-width just
             deformed them.

             A pill also shows its count, which a closed dropdown cannot, so
             the statuses and their numbers are readable without opening
             anything. The counts are for the day in scope. -->
        <div
            v-if="typeOptions.length > 0"
            class="presence-filter"
            role="group"
            :aria-label="t('teamhub', 'Filter members by status')">

            <div class="presence-filter__pills">
                <TogglePill
                    v-for="type in typeOptions"
                    :key="type.id"
                    :label="type.label"
                    :count="scopedCount(type)"
                    :pressed="filterSlugs.includes(type.id)"
                    :aria-label="statusPillLabel(type)"
                    @click="toggleType(type)" />
            </div>

            <!-- Part of day only narrows something once a status is chosen. -->
            <div v-if="typeFilter.length > 0" class="presence-filter__pills presence-filter__pills--parts">
                <TogglePill
                    v-for="part in halfOptions"
                    :key="part.id"
                    :label="part.label"
                    :pressed="dayPart === part.id"
                    @click="dayPart = part.id" />
            </div>

            <div v-if="typeFilter.length > 0" class="presence-filter__status">
                <span aria-live="polite">{{ filterCountLabel }}</span>
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Clear the status filter')"
                    @click="clearTypeFilter">
                    {{ t('teamhub', 'Clear') }}
                </NcButton>
            </div>
        </div>

        <!-- ── Loading / error ───────────────────────────────────────────── -->
        <div v-if="loading" class="presence-grid-loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <div v-else-if="error" class="presence-grid-error" role="alert">
            {{ error }}
        </div>

        <!-- ── Week grid: members × 7 days, stacked AM/PM blocks ──────────── -->
        <template v-else>
            <div
                v-if="members.length === 0"
                class="presence-grid-empty">
                {{ t('teamhub', 'No team members with presence data found.') }}
            </div>
            <!-- A filter that matches nobody is its own state, and it says what
                 to do about it. Falling back to the line above would have read
                 as "this team has no data". -->
            <div
                v-else-if="visibleMembers.length === 0"
                class="presence-grid-empty">
                <p>{{ emptyFilterMessage }}</p>
                <NcButton variant="secondary" @click="clearTypeFilter">
                    {{ t('teamhub', 'Clear the status filter') }}
                </NcButton>
            </div>
            <div
                v-else
                class="presence-week-grid"
                role="grid"
                :aria-label="t('teamhub', 'Team presence: week view')">

                <!-- Column headers: Mon … Sun -->
                <div class="presence-week-grid__header" role="row">
                    <div class="presence-week-grid__member-col" role="columnheader" aria-hidden="true"></div>
                    <!-- The day header is the day picker (v4.13.66). The
                         tallies the user is reading live here, so the thing
                         they click is the number they are looking at — which
                         is why there is no day dropdown any more. A raw
                         <button> under the `/ui-standards` carve-out for full
                         width headers with bespoke chrome; `font-weight` is
                         set explicitly because a raw button hands 700 to every
                         child that does not claim its own. -->
                    <div
                        v-for="day in weekDays"
                        :key="day.iso"
                        class="presence-week-grid__day-col"
                        :class="{
                            'presence-week-grid__day-col--today': day.iso === isoToday(),
                            'presence-week-grid__day-col--scoped': isScopedDay(day),
                        }"
                        role="columnheader">
                        <!-- The button lives inside the header cell rather than
                             being it: `role="columnheader"` on a <button> would
                             replace the button role, and `aria-pressed` is not
                             allowed on a columnheader. A raw <button> under the
                             `/ui-standards` carve-out for full width headers
                             with bespoke chrome, with its own font-weight
                             because a raw button hands 700 to every child that
                             does not claim one. -->
                        <button
                            type="button"
                            class="presence-week-grid__day-scope"
                            :aria-pressed="isScopedDay(day) ? 'true' : 'false'"
                            :aria-label="dayScopeLabel(day)"
                            @click="scopeToDay(day)">
                        <span class="presence-week-grid__day-name">{{ day.label }}</span>
                        <span class="presence-week-grid__day-date">{{ day.dateLabel }}</span>
                        <!-- The overview, and it is not conditional on a filter
                             (v4.13.65). One tally per status, counted over every
                             member rather than the filtered rows: this is what
                             somebody reads *before* choosing anything, and a
                             number that only described the rows already on
                             screen could not point at another day. -->
                        <span
                            v-if="countsByDay[day.iso].length > 0"
                            class="presence-week-grid__day-counts">
                            <span
                                v-for="entry in countsByDay[day.iso]"
                                :key="entry.id"
                                class="presence-week-grid__count"
                                :class="{ 'presence-week-grid__count--selected': filterSlugs.includes(entry.id) }"
                                role="img"
                                :aria-label="countLabel(entry, day)"
                                :title="countLabel(entry, day)">
                                <span
                                    class="presence-week-grid__count-dot"
                                    :style="entry.color ? { backgroundColor: entry.color } : null"></span>
                                {{ entry.count }}
                            </span>
                        </span>
                        </button>
                    </div>
                </div>

                <!-- Member rows -->
                <div
                    v-for="member in visibleMembers"
                    :key="member.userId"
                    class="presence-week-grid__row"
                    role="row">

                    <div
                        class="presence-week-grid__member-col"
                        role="rowheader"
                        :title="member.displayName">
                        <!-- disable-menu="false" is the point: the avatar opens
                             Nextcloud's own contacts menu, which is the route to
                             the profile page the collaboration profile renders
                             into (§2.219). The tooltip stays off — the display
                             name is the next element and the row carries a
                             title. Same pair as MemberPresenceRow. -->
                        <NcAvatar
                            :user="member.userId"
                            :display-name="member.displayName"
                            :size="24"
                            :disable-tooltip="true"
                            :disable-menu="false" />
                        <span class="presence-week-grid__member-name">{{ member.displayName }}</span>
                    </div>

                    <!-- One cell per day -->
                    <div
                        v-for="day in weekDays"
                        :key="day.iso"
                        class="presence-week-grid__day-cell"
                        role="gridcell"
                        :aria-label="dayAriaLabel(member, day)">

                        <!-- Same type both halves → 60px merged block -->
                        <template v-if="sameTypeDay(member.userId, day.iso)">
                            <div
                                class="presence-day-block presence-day-block--full"
                                :class="{ 'presence-day-block--unset': slotFor(member.userId, day.iso, 0).isUnset }"
                                :style="dayBlockStyle(member.userId, day.iso, 0)"
                                :title="blockTitle(member.userId, day.iso, 0)">
                                <span v-if="!slotFor(member.userId, day.iso, 0).isUnset" class="presence-day-block__label">
                                    {{ slotFor(member.userId, day.iso, 0).label }}
                                </span>
                            </div>
                        </template>

                        <!-- Different types → two 30px blocks -->
                        <template v-else>
                            <div
                                class="presence-day-block presence-day-block--half"
                                :class="{ 'presence-day-block--unset': slotFor(member.userId, day.iso, 0).isUnset }"
                                :style="dayBlockStyle(member.userId, day.iso, 0)"
                                :title="blockTitle(member.userId, day.iso, 0)">
                                <span v-if="!slotFor(member.userId, day.iso, 0).isUnset" class="presence-day-block__label">
                                    {{ slotFor(member.userId, day.iso, 0).label }}
                                </span>
                            </div>
                            <div
                                class="presence-day-block presence-day-block--half"
                                :class="{ 'presence-day-block--unset': slotFor(member.userId, day.iso, 1).isUnset }"
                                :style="dayBlockStyle(member.userId, day.iso, 1)"
                                :title="blockTitle(member.userId, day.iso, 1)">
                                <span v-if="!slotFor(member.userId, day.iso, 1).isUnset" class="presence-day-block__label">
                                    {{ slotFor(member.userId, day.iso, 1).label }}
                                </span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { todayIso, toIsoDate, shiftIsoDate, formatIsoDate } from '../lib/localDate.js'
import {
    presenceTypeOptions,
    filterMembersByStatus,
    statusCountsFor,
    countMembersWithStatus,
    halvesFor,
} from '../lib/presenceTypeFilter.js'
import { NcButton, NcLoadingIcon, NcAvatar } from '@nextcloud/vue'
import ChevronLeftIcon  from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import EyeOffIcon       from 'vue-material-design-icons/EyeOff.vue'
import { mapGetters } from 'vuex'
import PresenceGridCell from './PresenceGridCell.vue'
import TogglePill from './TogglePill.vue'
import { ICON_BODY, ICON_LARGE } from '../constants/uiTokens.js'

/**
 * Team presence grid view — rendered inside TeamView alongside the other
 * app tabs (Talk, Files, Calendar, Deck).
 *
 * Two modes:
 *   week — all members × 7 days × AM/PM (14 columns). Horizontally scrollable.
 *   day  — all members × AM + PM for one date. Default on mobile.
 *
 * The anchor date is today on mount. Navigation moves the anchor by one week
 * (week mode) or one day (day mode). Switching modes preserves the anchor so
 * the user stays on the same point in time.
 *
 * Data is fetched from GET /teams/{teamId}/presence?from=&to= whenever the
 * visible range changes. Privacy filter (hide_reasons) is applied server-side.
 */
export default {
    name: 'TeamPresenceView',
    components: {
        NcButton, NcLoadingIcon, NcAvatar,
        ChevronLeftIcon, ChevronRightIcon, EyeOffIcon,
        PresenceGridCell, TogglePill,
    },
    props: {
        teamId: { type: String, required: true },
        hideReasons: { type: Boolean, default: false },
    },
    data() {
        return {
            ICON_BODY,
            ICON_LARGE,
            mode: 'day',   // day-only; week mode removed
            anchorDate: this.isoToday(),
            loading: false,
            error: null,
            members: [],
            slots: {},
            // Chosen status types, as option objects ({ id, label, color }).
            // Kept across week navigation on purpose: "show me who is in the
            // office" is a question about the team, not about one week, so
            // paging to the next week must not silently answer a different one.
            typeFilter: [],
            // Which day the filter is about, as a **weekday index** (0 = Mon)
            // rather than a date, so paging a week keeps "Thursday" instead of
            // pointing at a day that is no longer on screen. Null until the
            // user picks one; `dayFilter` then reports today's column.
            dayIndex: null,
            // 'all' | 'morning' | 'afternoon'.
            dayPart: 'all',
        }
    },
    computed: {
        ...mapGetters(['currentUserIsTeamAdmin']),

        isTeamAdmin() {
            return this.currentUserIsTeamAdmin
        },

        /** Mon–Sun ISO dates for the current week anchored on anchorDate */
        weekDays() {
            const anchor = new Date(this.anchorDate + 'T12:00:00')
            const dow    = anchor.getDay() === 0 ? 6 : anchor.getDay() - 1
            const monday = new Date(anchor)
            monday.setDate(anchor.getDate() - dow)
            const dayNames = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return Array.from({ length: 7 }, (_, i) => {
                const d = new Date(monday)
                d.setDate(monday.getDate() + i)
                const iso = this.dateToIso(d)
                return {
                    iso,
                    label: dayNames[i],
                    dateLabel: formatIsoDate(iso, { month: 'short', day: 'numeric' }),
                }
            })
        },

        /** Range fed to the API — full week Mon–Sun */
        apiFrom() { return this.weekDays[0].iso },
        apiTo()   { return this.weekDays[6].iso },

        periodLabel() {
            const from = this.weekDays[0]
            const to   = this.weekDays[6]
            return from.dateLabel + ' – ' + to.dateLabel
        },

        isCurrentPeriod() {
            const today = this.isoToday()
            return this.weekDays.some(d => d.iso === today)
        },

        /**
         * The status types to offer, and the rows to draw.
         *
         * Both delegate to src/lib/presenceTypeFilter.js, which carries the
         * reasoning and is unit tested — the raw-cell-versus-painted-cell trap
         * these turn on is invisible in a template.
         */
        typeOptions() {
            return presenceTypeOptions(this.members, this.slots, this.weekDays, this.typeFilter)
        },

        /** The chosen slugs, for the row test. */
        filterSlugs() {
            return this.typeFilter.map(o => o?.id).filter(Boolean)
        },

        halfOptions() {
            return [
                { id: 'all', label: t('teamhub', 'All day') },
                { id: 'morning', label: t('teamhub', 'Morning') },
                { id: 'afternoon', label: t('teamhub', 'Afternoon') },
            ]
        },

        /**
         * Today's column where the visible week holds today, else Monday.
         *
         * Only a fallback: once the user picks a day, `dayIndex` wins and
         * survives paging, because they asked about a weekday.
         */
        defaultDayIndex() {
            const today = this.isoToday()
            const index = this.weekDays.findIndex(day => day.iso === today)
            return index === -1 ? 0 : index
        },

        /** The day picker's value. Writing it is the user choosing a day. */
        dayFilter: {
            get() {
                return this.dayIndex === null ? this.defaultDayIndex : this.dayIndex
            },
            set(value) {
                this.dayIndex = value
            },
        },

        /** The day the filter is about. */
        scopedDay() {
            return this.weekDays[this.dayFilter] || null
        },

        scopedHalves() {
            return halvesFor(this.dayPart)
        },

        /**
         * Per-status tallies for every visible day, keyed by ISO date.
         *
         * A computed rather than a method so the seven columns are counted
         * once per data change instead of once per render, and so the template
         * can test for emptiness without counting twice.
         */
        countsByDay() {
            const out = {}
            for (const day of this.weekDays) {
                out[day.iso] = statusCountsFor(
                    this.members, this.slots, day.iso, this.scopedHalves, this.typeOptions,
                )
            }
            return out
        },

        visibleMembers() {
            return filterMembersByStatus(
                this.members, this.slots, this.scopedDay?.iso, this.scopedHalves, this.filterSlugs,
            )
        },

        /**
         * Says which day came back empty, and offers the day that did not.
         *
         * "No member has the selected status" on its own sends somebody back
         * to the pickers to work out which of three controls was wrong.
         */
        emptyFilterMessage() {
            const day = this.scopedDay?.label || ''
            const best = this.weekDays
                .map(d => ({ label: d.label, count: this.dayCount(d) }))
                .filter(d => d.count > 0)
                .sort((a, b) => b.count - a.count)[0]

            if (!best) {
                return t('teamhub', 'No member has the selected status anywhere this week.')
            }
            return t('teamhub', 'No member has the selected status on {day}. The most is {count} on {bestDay}.', {
                day, count: best.count, bestDay: best.label,
            })
        },

        filterCountLabel() {
            return n('teamhub',
                'Showing {shown} of {total} member',
                'Showing {shown} of {total} members',
                this.members.length,
                { shown: this.visibleMembers.length, total: this.members.length, n: this.members.length },
            )
        },
    },
    watch: {
        teamId:  { immediate: true, handler() { this.load() } },
        apiFrom: { handler() { this.load() } },
    },
    methods: {
        t, n,

        async load() {
            if (!this.teamId) return
            this.loading = true
            this.error   = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/presence`),
                    { params: { from: this.apiFrom, to: this.apiTo } }
                )
                this.members = data.members || []
                this.slots   = data.slots   || {}
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Failed to load presence data')
            } finally {
                this.loading = false
            }
        },

        clearTypeFilter() {
            this.typeFilter = []
            this.dayIndex = null
            this.dayPart = 'all'
        },

        /**
         * How many of the **whole team** hold the chosen status on this day.
         *
         * Deliberately not `visibleMembers`: the number is the overview that
         * tells somebody which day to switch the picker to, so it has to count
         * days the rows are not currently about.
         */
        dayCount(day) {
            return countMembersWithStatus(
                this.members, this.slots, day.iso, this.scopedHalves, this.filterSlugs,
            )
        },

        /** Add or remove one status from the filter. */
        toggleType(type) {
            const index = this.typeFilter.findIndex(o => o?.id === type.id)
            if (index === -1) {
                this.typeFilter = [...this.typeFilter, type]
            } else {
                this.typeFilter = this.typeFilter.filter(o => o?.id !== type.id)
            }
        },

        /** How many hold this status on the day in scope. The pill's number. */
        scopedCount(type) {
            return (this.countsByDay[this.scopedDay?.iso] || [])
                .find(entry => entry.id === type.id)?.count || 0
        },

        statusPillLabel(type) {
            return n('teamhub',
                '{status}, {count} member on {day}',
                '{status}, {count} members on {day}',
                this.scopedCount(type),
                {
                    status: type.label,
                    count: this.scopedCount(type),
                    day: this.scopedDay?.label || '',
                    n: this.scopedCount(type),
                },
            )
        },

        /** Clicking a day header scopes the filter to it. */
        scopeToDay(day) {
            const index = this.weekDays.findIndex(d => d.iso === day.iso)
            if (index !== -1) this.dayIndex = index
        },

        dayScopeLabel(day) {
            return t('teamhub', 'Show the selected statuses for {day}', { day: day.label })
        },

        /** The accessible name of one tally: "4 in Office on Thu". */
        countLabel(entry, day) {
            return n('teamhub',
                /* TRANSLATORS: the accessible name of one tally under a day column.
                   {status} is a workplace status name such as Office, Home or
                   Vacation, and {day} a weekday abbreviation such as Thu. */
                '{count} member in {status} on {day}',
                '{count} members in {status} on {day}',
                entry.count,
                { count: entry.count, status: entry.label, day: day.label, n: entry.count },
            )
        },

        /** Is this the column the rows below are about? */
        isScopedDay(day) {
            return this.typeFilter.length > 0 && day.iso === this.scopedDay?.iso
        },

        /**
         * The cell the grid paints. Never null: every coordinate gets a colour
         * and a label so no square is blank. That is exactly why it must not
         * be used to decide whether somebody *has* a status — the filter reads
         * the raw response through presenceTypeFilter.js instead.
         */
        slotFor(userId, date, half) {
            const workplace = this.slots[userId]?.[`${date}_${half}`] || null
            if (workplace?.slug === 'holiday') return workplace
            const label = this.hideReasons
                ? t('teamhub', 'Workplace hidden')
                // TRANSLATORS: a half-day of the week with no workplace on it — the member does not work then
                : t('teamhub', 'Not working')
            return {
                ...(workplace || {}),
                label: workplace?.label || label,
                // v4.13.66 — an unset half-day is drawn blank, and the label
                // survives only in the cell's tooltip and its row's aria-label.
                // A team where most people have filled nothing in rendered that
                // sentence at full width in every square, which made the emptiest
                // cells the loudest thing on the screen and buried the handful
                // that carried real data. v4.14.2: the label is "Not working" —
                // an empty half-day is a statement about the week, not a gap in
                // the data.
                isUnset: !workplace,
                color: workplace?.color || 'transparent',
            }
        },

        // ── Navigation ────────────────────────────────────────────────

        navigateBack()    { this.anchorDate = this.shiftDate(this.anchorDate, -7) },
        navigateForward() { this.anchorDate = this.shiftDate(this.anchorDate, 7)  },
        goToToday() {
            this.anchorDate = this.isoToday()
        },

        // ── Block helpers ─────────────────────────────────────────────

        sameTypeDay(userId, date) {
            const am = this.slotFor(userId, date, 0)
            const pm = this.slotFor(userId, date, 1)
            if (!am || !pm) return false
            if (am.location_room_id !== pm.location_room_id) return false
            // Team grid API returns 'slug' and 'color' — use slug when available
            // (null when hide_reasons is on), fall back to color comparison.
            if (am.slug && pm.slug) return am.slug === pm.slug
            return !!(am.color && am.color === pm.color)
        },

        dayBlockStyle(userId, date, half) {
            const slot = this.slotFor(userId, date, half)
            // Team grid API returns 'color' (not 'presence_type_color')
            if (!slot || !slot.color) return {}
            return { backgroundColor: slot.color }
        },

        blockTitle(userId, date, half) {
            const slot = this.slotFor(userId, date, half)
            const halfLabel = half === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
            if (!slot) return halfLabel
            // Team grid API returns 'label' (not 'presence_type_label')
            return slot.label || halfLabel
        },

        dayAriaLabel(member, day) {
            const am = this.slotFor(member.userId, day.iso, 0)
            const pm = this.slotFor(member.userId, day.iso, 1)
            const amLabel = am?.label || t('teamhub', 'Not working')
            const pmLabel = pm?.label || t('teamhub', 'Not working')
            if (this.sameTypeDay(member.userId, day.iso)) {
                return t('teamhub', '{who} {day}: {status} all day', {
                    who: member.displayName, day: day.label, status: amLabel,
                })
            }
            return t('teamhub', '{who} {day}: Morning {am}, Afternoon {pm}', {
                who: member.displayName, day: day.label, am: amLabel, pm: pmLabel,
            })
        },

        // ── Date helpers ──────────────────────────────────────────────

        isoToday() {
            return todayIso()
        },

        dateToIso(d) {
            return toIsoDate(d)
        },

        shiftDate(iso, days) {
            return shiftIsoDate(iso, { days })
        },

        // Presence slots are floating dates, so both of these go through the
        // zone-immune path — a half-day on the 11th is the 11th everywhere.
        formatDateShort(iso) {
            return formatIsoDate(iso, { month: 'short', day: 'numeric' })
        },

        formatDateLong(iso) {
            return formatIsoDate(iso, {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
            })
        },
    },
}
</script>

<style scoped>
.team-presence-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

/* ── Toolbar ───────────────────────────────────────────────────── */
.presence-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    border-bottom: 1px solid var(--color-border);
    flex-wrap: wrap;
    flex-shrink: 0;
}

.presence-toolbar__nav {
    display: flex;
    align-items: center;
    gap: 4px;
}

.presence-toolbar__period {
    font-size: var(--th-font-body);
    font-weight: 500;
    min-width: 160px;
    text-align: center;
}

/* ── Status filter ──────────────────────────────────────
   Its own row, and nothing in it may shrink below its own label: the row
   wraps instead. The earlier version put NcSelects inside .presence-toolbar
   under `min-width: 0`, which both clipped the Clear button to "C" and
   fought the 260px minimum @nextcloud/vue gives .v-select.select. */
.presence-filter {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm) var(--th-space-md);
    padding: var(--th-space-sm) var(--th-space-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.presence-filter__pills {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

/* A thin rule separating "which status" from "which part of the day". */
.presence-filter__pills--parts {
    padding-inline-start: var(--th-space-md);
    border-inline-start: 1px solid var(--color-border);
}

.presence-filter__status {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    flex-shrink: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    margin-inline-start: auto;
}

.presence-toolbar__privacy-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    padding: 4px 8px;
    border-radius: var(--border-radius-pill, 12px);
    background: var(--color-background-hover);
    margin-inline-start: auto;
}

/* ── Loading / error / empty ────────────────────────────────────── */
.presence-grid-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
}
.presence-grid-error {
    padding: 20px;
    color: var(--color-text-error);
    background: var(--color-error-background, var(--color-background-hover));
    margin: 12px;
    border-radius: var(--border-radius-small);
}
/* Flex because the filtered-to-nothing state carries a button as well as a
   sentence, and both have to centre. */
.presence-grid-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 40px;
    text-align: center;
    color: var(--color-text-maxcontrast);
}

/* ── Week grid ──────────────────────────────────────────────────── */
.presence-week-grid {
    overflow-x: auto;
    overflow-y: auto;
    flex: 1;
    padding: 0 8px 12px;
    min-width: 0;
}

.presence-week-grid__header,
.presence-week-grid__row {
    display: grid;
    grid-template-columns: 160px repeat(7, minmax(96px, 1fr));
    border-bottom: 1px solid var(--color-border);
    min-width: 832px;
}

.presence-week-grid__member-col {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    font-size: var(--th-font-meta);
    overflow: hidden;
    position: sticky;
    inset-inline-start: 0;
    background: var(--color-main-background);
    z-index: 1;
}

.presence-week-grid__member-name {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: var(--th-font-meta);
}

.presence-week-grid__day-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    border-inline-start: 1px solid var(--color-border);
}

/* The whole header cell is the day's scope control. `font-weight` is set
   here because Nextcloud's server.css gives every raw <button> bold, which
   every child without its own weight then inherits (`/ui-standards`). */
.presence-week-grid__day-scope {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1px;
    width: 100%;
    padding: var(--th-space-xs);
    border: none;
    background: none;
    color: inherit;
    font-size: inherit;
    font-weight: var(--th-font-weight-regular, 400);
    cursor: pointer;
    border-radius: var(--th-radius-control);
}

.presence-week-grid__day-scope:hover {
    background: var(--color-background-hover);
}

/* Split from :hover — grouping them silences the keyboard ring. */
.presence-week-grid__day-scope:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.presence-week-grid__day-col--today {
    background: var(--color-primary-light);
    font-weight: 600;
    color: var(--color-primary-text, var(--color-main-text));
}

/* The column the filtered rows are about. An inset edge rather than a fill,
   so it composes with --today instead of fighting it for the background. */
.presence-week-grid__day-col--scoped {
    box-shadow: inset 0 -2px 0 0 var(--color-primary-element);
}

/* An unset half-day is blank, not a grey block announcing "Workplace not
   set" at full width. The sentence survives in the cell's tooltip and in
   the row's aria-label, where it answers rather than shouts. */
.presence-day-block--unset {
    background: none;
}

/* Per-status tallies under each day name, always on. */
.presence-week-grid__day-counts {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: var(--th-space-xs);
    margin-block-start: var(--th-space-xs);
}

.presence-week-grid__count {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    background: var(--color-background-hover);
    border-radius: var(--th-radius-chip);
    padding-inline: var(--th-space-xs);
}

/* Selection never rests on the dot's colour alone: the chip takes the
   primary tint and its number goes bold with it. */
.presence-week-grid__count--selected {
    background: var(--color-primary-element-light);
    color: var(--color-main-text);
    font-weight: 700;
}

/* The status colour, pinned to a circle by the six-lock recipe so content
   and the flex parent cannot stretch it into an oval. */
.presence-week-grid__count-dot {
    display: inline-block;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 8px;
    height: 8px;
    min-width: 8px;
    min-height: 8px;
    max-width: 8px;
    max-height: 8px;
    padding: 0;
    border-radius: 50%;
    background: var(--color-text-maxcontrast);
}

.presence-week-grid__day-name { font-weight: 500; }
.presence-week-grid__day-date { opacity: 0.7; font-size: var(--th-font-meta); }

.presence-week-grid__day-cell {
    display: flex;
    flex-direction: column;
    border-inline-start: 1px solid var(--color-border);
}

/* ── Shared block styles ─────────────────────────────────────────── */
.presence-day-block {
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 0 4px;
}

.presence-day-block--half {
    height: 30px;
    border-bottom: 1px solid var(--color-box-shadow);
}
.presence-day-block--half:last-child {
    border-bottom: none;
}

.presence-day-block--full {
    height: 60px;
}

.presence-day-block__label {
    font-size: var(--th-font-meta);
    font-weight: 500;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 100%;
    text-align: center;
}
</style>

