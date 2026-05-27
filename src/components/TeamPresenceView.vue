<template>
    <div class="team-presence-view">

        <!-- ── Toolbar: day navigation only ─────────────────────────────── -->
        <div class="presence-toolbar" role="toolbar" :aria-label="t('teamhub', 'Presence controls')">
            <div class="presence-toolbar__nav" role="group" :aria-label="t('teamhub', 'Date navigation')">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Previous week')"
                    @click="navigateBack">
                    <template #icon><ChevronLeftIcon :size="20" /></template>
                </NcButton>

                <span class="presence-toolbar__period" aria-live="polite">
                    {{ periodLabel }}
                </span>

                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Next week')"
                    @click="navigateForward">
                    <template #icon><ChevronRightIcon :size="20" /></template>
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
                <EyeOffIcon :size="16" />
                {{ t('teamhub', 'Details hidden') }}
            </div>
        </div>

        <!-- ── Loading / error ───────────────────────────────────────────── -->
        <div v-if="loading" class="presence-grid-loading">
            <NcLoadingIcon :size="32" />
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
            <div
                v-else
                class="presence-week-grid"
                role="grid"
                :aria-label="t('teamhub', 'Team presence — week view')">

                <!-- Column headers: Mon … Sun -->
                <div class="presence-week-grid__header" role="row">
                    <div class="presence-week-grid__member-col" role="columnheader" aria-hidden="true"></div>
                    <div
                        v-for="day in weekDays"
                        :key="day.iso"
                        class="presence-week-grid__day-col"
                        :class="{ 'presence-week-grid__day-col--today': day.iso === isoToday() }"
                        role="columnheader">
                        <span class="presence-week-grid__day-name">{{ day.label }}</span>
                        <span class="presence-week-grid__day-date">{{ day.dateLabel }}</span>
                    </div>
                </div>

                <!-- Member rows -->
                <div
                    v-for="member in members"
                    :key="member.userId"
                    class="presence-week-grid__row"
                    role="row">

                    <div
                        class="presence-week-grid__member-col"
                        role="rowheader"
                        :title="member.displayName">
                        <NcAvatar
                            :user="member.userId"
                            :display-name="member.displayName"
                            :size="22"
                            :disable-tooltip="true"
                            :disable-menu="true" />
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
                                :style="dayBlockStyle(member.userId, day.iso, 0)"
                                :title="blockTitle(member.userId, day.iso, 0)">
                                <span v-if="slotFor(member.userId, day.iso, 0)" class="presence-day-block__label">
                                    {{ slotFor(member.userId, day.iso, 0).label }}
                                </span>
                            </div>
                        </template>

                        <!-- Different types → two 30px blocks -->
                        <template v-else>
                            <div
                                class="presence-day-block presence-day-block--half"
                                :style="dayBlockStyle(member.userId, day.iso, 0)"
                                :title="blockTitle(member.userId, day.iso, 0)">
                                <span v-if="slotFor(member.userId, day.iso, 0)" class="presence-day-block__label">
                                    {{ slotFor(member.userId, day.iso, 0).label }}
                                </span>
                            </div>
                            <div
                                class="presence-day-block presence-day-block--half"
                                :style="dayBlockStyle(member.userId, day.iso, 1)"
                                :title="blockTitle(member.userId, day.iso, 1)">
                                <span v-if="slotFor(member.userId, day.iso, 1)" class="presence-day-block__label">
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
import { NcButton, NcLoadingIcon, NcAvatar } from '@nextcloud/vue'
import ChevronLeftIcon  from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import EyeOffIcon       from 'vue-material-design-icons/EyeOff.vue'
import { mapGetters } from 'vuex'
import PresenceGridCell from './PresenceGridCell.vue'

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
        PresenceGridCell,
    },
    props: {
        teamId: { type: String, required: true },
        hideReasons: { type: Boolean, default: false },
    },
    data() {
        return {
            mode: 'day',   // day-only; week mode removed
            anchorDate: this.isoToday(),
            loading: false,
            error: null,
            members: [],
            slots: {},
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
                    dateLabel: d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
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

        slotFor(userId, date, half) {
            return this.slots[userId]?.[`${date}_${half}`] || null
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
            const amLabel = am?.label || t('teamhub', 'not set')
            const pmLabel = pm?.label || t('teamhub', 'not set')
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
            return new Date().toISOString().slice(0, 10)
        },

        dateToIso(d) {
            return d.toISOString().slice(0, 10)
        },

        shiftDate(iso, days) {
            const d = new Date(iso + 'T12:00:00')
            d.setDate(d.getDate() + days)
            return this.dateToIso(d)
        },

        formatDateShort(iso) {
            const [y, m, d] = iso.split('-').map(Number)
            return new Date(y, m - 1, d).toLocaleDateString(undefined, {
                month: 'short', day: 'numeric',
            })
        },

        formatDateLong(iso) {
            const [y, m, d] = iso.split('-').map(Number)
            return new Date(y, m - 1, d).toLocaleDateString(undefined, {
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
    font-size: 14px;
    font-weight: 500;
    min-width: 160px;
    text-align: center;
}

.presence-toolbar__privacy-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    padding: 4px 8px;
    border-radius: var(--border-radius-pill, 12px);
    background: var(--color-background-hover);
    margin-left: auto;
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
    color: var(--color-error-text);
    background: var(--color-error-background, var(--color-background-hover));
    margin: 12px;
    border-radius: var(--border-radius);
}
.presence-grid-empty {
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
    grid-template-columns: 160px repeat(7, minmax(80px, 1fr));
    border-bottom: 1px solid var(--color-border);
    min-width: 720px;
}

.presence-week-grid__member-col {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    font-size: 13px;
    overflow: hidden;
    position: sticky;
    left: 0;
    background: var(--color-main-background);
    z-index: 1;
}

.presence-week-grid__member-name {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 13px;
}

.presence-week-grid__day-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4px 2px;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    border-left: 1px solid var(--color-border);
    gap: 1px;
}

.presence-week-grid__day-col--today {
    background: var(--color-primary-light);
    font-weight: 600;
    color: var(--color-primary-text, var(--color-main-text));
}

.presence-week-grid__day-name { font-weight: 500; }
.presence-week-grid__day-date { opacity: 0.7; font-size: 10px; }

.presence-week-grid__day-cell {
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--color-border);
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
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
}
.presence-day-block--half:last-child {
    border-bottom: none;
}

.presence-day-block--full {
    height: 60px;
}

.presence-day-block__label {
    font-size: 10px;
    font-weight: 500;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 100%;
    text-align: center;
}
</style>
