<template>
    <div class="roster-stats">
        <!-- ── Header: back, title, year ─────────────────────────────────── -->
        <div class="roster-stats__bar">
            <NcButton variant="tertiary" @click="$emit('close')">
                <template #icon><ArrowLeftIcon :size="ICON_BODY" /></template>
                {{ t('teamhub', 'Back to the roster') }}
            </NcButton>

            <h2 class="roster-stats__title">{{ title }}</h2>

            <div class="roster-stats__year" role="group" :aria-label="t('teamhub', 'Year')">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Previous year')"
                    :disabled="busy"
                    @click="stepYear(-1)">
                    <template #icon><ChevronLeftIcon :size="ICON_BODY" /></template>
                </NcButton>
                <span class="roster-stats__year-label" aria-live="polite">{{ year }}</span>
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Next year')"
                    :disabled="busy || year >= maxYear"
                    @click="stepYear(1)">
                    <template #icon><ChevronRightIcon :size="ICON_BODY" /></template>
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="roster-stats__loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <NcNoteCard v-else-if="error" type="error">
            {{ error }}
            <template #action>
                <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
            </template>
        </NcNoteCard>

        <div v-else class="roster-stats__body">
            <!-- ── The year, in four figures ─────────────────────────────── -->
            <div class="roster-stats__totals">
                <div class="roster-stats__tile">
                    <span class="roster-stats__figure">{{ total.plannedShifts }}</span>
                    <span class="roster-stats__caption">{{ t('teamhub', 'Shifts planned') }}</span>
                </div>
                <div class="roster-stats__tile">
                    <span class="roster-stats__figure">{{ hours(total.plannedMinutes) }}</span>
                    <span class="roster-stats__caption">{{ t('teamhub', 'Hours planned') }}</span>
                </div>
                <div class="roster-stats__tile">
                    <span class="roster-stats__figure">{{ hours(total.workedMinutes) }}</span>
                    <span class="roster-stats__caption">{{ t('teamhub', 'Hours worked') }}</span>
                </div>
                <div class="roster-stats__tile">
                    <span class="roster-stats__figure">{{ total.handoverRequests }}</span>
                    <span class="roster-stats__caption">{{ t('teamhub', 'Replacements asked for') }}</span>
                </div>
            </div>

            <!-- What "worked" rests on, said rather than implied. -->
            <p class="roster-stats__basis">
                {{ t('teamhub', 'Worked counts every shift whose date has passed. TeamHub does not record attendance.') }}
            </p>
            <!-- v4.14.11 — the personal year is the person's, not this
                 team's (DESIGN §2.253), and the page says so. -->
            <p v-if="scope === 'user'" class="roster-stats__basis">
                {{ t('teamhub', 'Your shifts in all your teams are counted.') }}
            </p>

            <!-- ── Month by month ────────────────────────────────────────── -->
            <h3 class="roster-stats__heading">{{ t('teamhub', 'Month by month') }}</h3>
            <table class="roster-stats__table">
                <thead>
                    <tr>
                        <th scope="col">{{ t('teamhub', 'Month') }}</th>
                        <th scope="col">{{ t('teamhub', 'Shifts planned') }}</th>
                        <th scope="col">{{ t('teamhub', 'Hours planned') }}</th>
                        <th scope="col">{{ t('teamhub', 'Hours worked') }}</th>
                        <th scope="col">{{ t('teamhub', 'Replacements asked for') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="bucket in months"
                        :key="bucket.month"
                        :class="{ 'roster-stats__row--empty': bucket.plannedShifts === 0 }">
                        <th scope="row">{{ monthName(bucket.month) }}</th>
                        <td>{{ bucket.plannedShifts || '' }}</td>
                        <td>{{ bucket.plannedMinutes ? hours(bucket.plannedMinutes) : '' }}</td>
                        <td>{{ bucket.workedMinutes ? hours(bucket.workedMinutes) : '' }}</td>
                        <td>{{ bucket.handoverRequests || '' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">{{ t('teamhub', 'Whole year') }}</th>
                        <td>{{ total.plannedShifts }}</td>
                        <td>{{ hours(total.plannedMinutes) }}</td>
                        <td>{{ hours(total.workedMinutes) }}</td>
                        <td>{{ total.handoverRequests }}</td>
                    </tr>
                </tfoot>
            </table>

            <!-- ── Per team, personal view only, and only when there is more
                 than one: a split of one is the total again. ─────────── -->
            <template v-if="scope === 'user' && teams.length > 1">
                <h3 class="roster-stats__heading">{{ t('teamhub', 'Per team') }}</h3>
                <table class="roster-stats__table">
                    <thead>
                        <tr>
                            <th scope="col">{{ t('teamhub', 'Team') }}</th>
                            <th scope="col">{{ t('teamhub', 'Shifts planned') }}</th>
                            <th scope="col">{{ t('teamhub', 'Hours planned') }}</th>
                            <th scope="col">{{ t('teamhub', 'Hours worked') }}</th>
                            <th scope="col">{{ t('teamhub', 'Replacements asked for') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="team in teams" :key="team.teamId">
                            <th scope="row">{{ team.teamName || t('teamhub', 'A team that no longer exists') }}</th>
                            <td>{{ team.total.plannedShifts || '' }}</td>
                            <td>{{ team.total.plannedMinutes ? hours(team.total.plannedMinutes) : '' }}</td>
                            <td>{{ team.total.workedMinutes ? hours(team.total.workedMinutes) : '' }}</td>
                            <td>{{ team.total.handoverRequests || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <!-- ── Per person, team view only ────────────────────────────── -->
            <template v-if="scope === 'team'">
                <h3 class="roster-stats__heading">{{ t('teamhub', 'Per person') }}</h3>
                <p v-if="people.length === 0" class="roster-stats__empty">
                    {{ t('teamhub', 'Nobody was rostered this year.') }}
                </p>
                <table v-else class="roster-stats__table">
                    <thead>
                        <tr>
                            <th scope="col">{{ t('teamhub', 'Team member') }}</th>
                            <th scope="col">{{ t('teamhub', 'Shifts planned') }}</th>
                            <th scope="col">{{ t('teamhub', 'Hours planned') }}</th>
                            <th scope="col">{{ t('teamhub', 'Hours worked') }}</th>
                            <th scope="col">{{ t('teamhub', 'Replacements asked for') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="person in people"
                            :key="person.userId"
                            :class="{ 'roster-stats__row--empty': person.total.plannedShifts === 0 }">
                            <th scope="row" class="roster-stats__person">
                                <NcAvatar
                                    :user="person.userId"
                                    :display-name="person.displayName"
                                    :size="AVATAR_SM"
                                    :disable-tooltip="true"
                                    :disable-menu="false" />
                                {{ person.displayName }}
                            </th>
                            <td>{{ person.total.plannedShifts || '' }}</td>
                            <td>{{ person.total.plannedMinutes ? hours(person.total.plannedMinutes) : '' }}</td>
                            <td>{{ person.total.workedMinutes ? hours(person.total.workedMinutes) : '' }}</td>
                            <td>{{ person.total.handoverRequests || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcAvatar } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import { ICON_BODY, ICON_LARGE, AVATAR_SM } from '../constants/uiTokens.js'

/**
 * A year of the roster, as a page over the grid (v4.13.71, DESIGN §2.237).
 *
 * One component for both readings. The personal one is four figures, twelve
 * months and a year; the team one is the same shape plus a line per member.
 * They differ by one table, so two components would have been two copies of
 * the arithmetic and the formatting.
 *
 * ## A page, not a dialog
 *
 * It replaces the roster rather than floating over it, with a back control in
 * the same place the eye already is. A year of figures does not fit a dialog,
 * and a dialog over a grid invites reading one against the other, which is
 * precisely what the numbers are not for.
 *
 * ## Hours are rounded for reading, never for arithmetic
 *
 * The server sends minutes. Rounding happens here, once, at the moment of
 * display — a column of figures each rounded before being summed is the
 * classic way a total stops matching its parts.
 */
export default {
    name: 'RosterStatsOverlay',

    components: {
        NcButton, NcLoadingIcon, NcNoteCard, NcAvatar,
        ArrowLeftIcon, ChevronLeftIcon, ChevronRightIcon,
    },

    props: {
        teamId: { type: String, required: true },
        /** 'user' for the caller's own year, 'team' for everybody's. */
        scope: { type: String, default: 'user' },
    },

    emits: ['close'],

    data() {
        return {
            ICON_BODY,
            ICON_LARGE,
            AVATAR_SM,
            year: new Date().getFullYear(),
            loading: false,
            busy: false,
            error: null,
            months: [],
            total: this.emptyBucket(),
            people: [],
            // The personal year split by team (v4.14.11).
            teams: [],
        }
    },

    computed: {
        title() {
            return this.scope === 'team'
                ? t('teamhub', 'Team shift statistics')
                : t('teamhub', 'My shift statistics')
        },

        /** No point offering a year nobody can have rostered into. */
        maxYear() {
            return new Date().getFullYear() + 1
        },
    },

    watch: {
        year() { this.load() },
        scope() { this.load() },
    },

    mounted() {
        this.load()
    },

    methods: {
        t, n,

        emptyBucket() {
            return {
                plannedShifts: 0,
                plannedMinutes: 0,
                workedShifts: 0,
                workedMinutes: 0,
                upcomingShifts: 0,
                upcomingMinutes: 0,
                handoverRequests: 0,
            }
        },

        /**
         * Minutes as hours, to one decimal and only where it earns it.
         *
         * 7.5 is a shift somebody recognises; 7.0 is noise, so it reads 7.
         */
        hours(minutes) {
            const value = Math.round(((minutes || 0) / 60) * 10) / 10
            return Number.isInteger(value) ? String(value) : value.toFixed(1)
        },

        monthName(index) {
            // Month 1 of a year that is certainly valid, so the name comes
            // from the browser's own locale data rather than a list we would
            // then have to translate seven times.
            return new Date(Date.UTC(2000, index, 1))
                .toLocaleDateString(undefined, { month: 'long', timeZone: 'UTC' })
        },

        stepYear(by) {
            const next = this.year + by
            if (next >= 2000 && next <= this.maxYear) this.year = next
        },

        async load() {
            this.loading = true
            this.busy = true
            this.error = null
            const path = this.scope === 'team' ? 'team' : 'me'
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/roster/stats/${path}`),
                    { params: { year: this.year } },
                )
                this.months = data.months || []
                this.total = data.total || this.emptyBucket()
                this.people = data.people || []
                this.teams = data.teams || []
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Could not load the statistics.')
            } finally {
                this.loading = false
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.roster-stats {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

.roster-stats__bar {
    display: flex;
    align-items: center;
    gap: var(--th-space-md);
    flex-wrap: wrap;
    padding: var(--th-space-sm) var(--th-space-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.roster-stats__title {
    margin: 0;
    font-size: var(--th-font-heading);
    font-weight: 600;
}

.roster-stats__year {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    flex-shrink: 0;
    margin-inline-start: auto;
}

.roster-stats__year-label {
    font-weight: 600;
    min-width: 48px;
    text-align: center;
}

.roster-stats__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-xl);
}

.roster-stats__body {
    flex: 1;
    overflow: auto;
    padding: var(--th-space-lg);
}

/* ── The four figures ──────────────────────────────────────────── */
.roster-stats__totals {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--th-space-md);
}

.roster-stats__tile {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    padding: var(--th-space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
}

/* A bare figure is the one place --th-font-display is allowed. */
.roster-stats__figure {
    font-size: var(--th-font-display);
    font-weight: 700;
    line-height: 1;
}

.roster-stats__caption {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-stats__basis {
    margin: var(--th-space-md) 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.roster-stats__heading {
    margin: var(--th-space-xl) 0 var(--th-space-sm);
    font-size: var(--th-font-heading);
    font-weight: 600;
}

.roster-stats__empty {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* ── Tables ────────────────────────────────────────────────────── */
.roster-stats__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--th-font-meta);
}

.roster-stats__table th,
.roster-stats__table td {
    padding: var(--th-space-xs) var(--th-space-sm);
    text-align: end;
    border-block-end: 1px solid var(--color-border);
}

.roster-stats__table thead th,
.roster-stats__table th[scope="row"] {
    text-align: start;
}

.roster-stats__table thead th {
    color: var(--color-text-maxcontrast);
    font-weight: 500;
}

.roster-stats__table tfoot th,
.roster-stats__table tfoot td {
    font-weight: 700;
    border-block-end: none;
}

/* A month with nothing in it is quiet rather than a row of zeroes. */
.roster-stats__row--empty {
    color: var(--color-text-maxcontrast);
}

.roster-stats__person {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    font-weight: 400;
}
</style>
