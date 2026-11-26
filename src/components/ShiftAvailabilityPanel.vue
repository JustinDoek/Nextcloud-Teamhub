<template>
    <div class="th-personal__section">
        <h2 class="th-personal__title">{{ t('teamhub', 'Shift availability') }}</h2>
        <p class="th-personal__desc">
            {{ t('teamhub', 'The half-days you can work a shift. A team admin sees this when staffing a roster. It is advice, not a block: you can still be asked, and you can still agree.') }}
        </p>

        <div v-if="loading" class="th-avail__loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <NcNoteCard v-else-if="loadError" type="error">
            {{ loadError }}
            <template #action>
                <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
            </template>
        </NcNoteCard>

        <template v-else>
            <table class="th-avail__grid">
                <thead>
                    <tr>
                        <th scope="col" class="th-avail__corner"></th>
                        <th v-for="day in days" :key="day.index" scope="col">{{ day.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="half in halves" :key="half.index">
                        <th scope="row" class="th-avail__half">{{ half.label }}</th>
                        <td v-for="day in days" :key="day.index">
                            <NcCheckboxRadioSwitch
                                :model-value="isAvailable(day.index, half.index)"
                                :aria-label="cellLabel(day, half)"
                                @update:model-value="setCell(day.index, half.index, $event)" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="th-avail__actions">
                <NcButton variant="tertiary" @click="setAll(true)">
                    {{ t('teamhub', 'Select all') }}
                </NcButton>
                <NcButton variant="tertiary" @click="setWorkweek">
                    {{ t('teamhub', 'Weekdays only') }}
                </NcButton>
            </div>
            <AutosaveStatus
                :saving="saving"
                :saved="saved"
                :error="saveError"
                @retry="saver.now()" />
        </template>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { ICON_LARGE } from '../constants/uiTokens.js'
import { createAutosaver } from '../lib/autosave.js'
import AutosaveStatus from './AutosaveStatus.vue'

/**
 * When I can work a shift (v4.13.71, DESIGN §2.237).
 *
 * Personal settings → TeamHub. Seven weekdays by two half-days, ticked where
 * the person is available. **Everything starts ticked**, which matches the
 * server's rule that an absent row means available: somebody who never opens
 * this screen stays rosterable.
 *
 * ## It says nothing about where they are
 *
 * The workplace grid in the People tab is *where I am on Tuesday morning*.
 * This is *whether I can be put on a shift then*. A person can be at the
 * office on a morning they cannot take a shift, and anybody who works nights
 * is the reverse, so the two are separate and the wording here never mentions
 * a location.
 *
 * ## It saves itself (v4.20.12, DESIGN §2.265)
 *
 * Until 4.20.11 this had one Save button, on the grounds that fourteen
 * switches each writing at once would be fourteen requests and a half-saved
 * week. Both are answered by the debounce instead: a change waits 800 ms for
 * the next one, so setting up a week is one write of the whole grid, and the
 * PUT always carries all fourteen cells. A write never overlaps another
 * (src/lib/autosave.js).
 */
export default {
    name: 'ShiftAvailabilityPanel',

    components: { NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcNoteCard, AutosaveStatus },

    data() {
        return {
            ICON_LARGE,
            loading: false,
            loadError: null,
            saving: false,
            saved: false,
            saveError: '',
            /** `${day}:${half}` -> boolean */
            cells: {},
            original: '',
            saver: null,
            savedTimer: null,
        }
    },

    computed: {
        /** 0 = Monday, matching the stored day_of_week. */
        days() {
            const labels = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return labels.map((label, index) => ({ index, label }))
        },

        halves() {
            return [
                { index: 0, label: t('teamhub', 'Morning') },
                { index: 1, label: t('teamhub', 'Afternoon') },
            ]
        },

        dirty() {
            return JSON.stringify(this.cells) !== this.original
        },
    },

    created() {
        this.saver = createAutosaver({ save: () => this.save(), delay: 800 })
    },

    mounted() {
        this.load()
    },

    beforeUnmount() {
        // A change still waiting for its debounce is written, not dropped:
        // leaving the page is not a reason to lose the last switch.
        this.saver.flush()
        clearTimeout(this.savedTimer)
    },

    methods: {
        t, n,

        isAvailable(day, half) {
            return this.cells[`${day}:${half}`] !== false
        },

        cellLabel(day, half) {
            return t('teamhub', 'Available on {day} {part}', { day: day.label, part: half.label })
        },

        setCell(day, half, value) {
            this.cells = { ...this.cells, [`${day}:${half}`]: !!value }
            this.changed()
        },

        /** Every edit lands here: hide a stale "Saved" and queue the write. */
        changed() {
            this.saved = false
            this.saver.schedule()
        },

        setAll(value) {
            const next = {}
            for (const day of this.days) {
                for (const half of this.halves) {
                    next[`${day.index}:${half.index}`] = value
                }
            }
            this.cells = next
            this.changed()
        },

        /** Monday to Friday on, the weekend off. The common answer. */
        setWorkweek() {
            const next = {}
            for (const day of this.days) {
                for (const half of this.halves) {
                    next[`${day.index}:${half.index}`] = day.index < 5
                }
            }
            this.cells = next
            this.changed()
        },

        async load() {
            this.loading = true
            this.loadError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/people/shift-availability'),
                )
                const cells = {}
                for (const cell of data.cells || []) {
                    cells[`${cell.dayOfWeek}:${cell.halfDay}`] = cell.available !== false
                }
                this.cells = cells
                this.original = JSON.stringify(cells)
            } catch (err) {
                this.loadError = err?.response?.data?.error
                    || t('teamhub', 'Could not load your availability.')
            } finally {
                this.loading = false
            }
        },

        /**
         * Write the whole grid. Called by the autosaver only, which runs it
         * after the debounce and reads the grid then, so a switch flipped
         * during a write is carried by the follow-up write.
         */
        async save() {
            if (!this.dirty) {
                return
            }
            const snapshot = JSON.stringify(this.cells)
            const cells = []
            for (const day of this.days) {
                for (const half of this.halves) {
                    cells.push({
                        dayOfWeek: day.index,
                        halfDay: half.index,
                        available: this.isAvailable(day.index, half.index),
                    })
                }
            }
            this.saving = true
            this.saveError = ''
            try {
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/people/shift-availability'),
                    { cells },
                )
                this.original = snapshot
                this.saved = true
                clearTimeout(this.savedTimer)
                this.savedTimer = setTimeout(() => { this.saved = false }, 2000)
            } catch (err) {
                this.saveError = err?.response?.data?.error
                    || t('teamhub', 'Could not save your availability.')
            } finally {
                this.saving = false
            }
        },
    },
}
</script>

<style scoped>
.th-avail__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-lg);
}

.th-avail__grid {
    border-collapse: collapse;
    font-size: var(--th-font-meta);
}

.th-avail__grid th,
.th-avail__grid td {
    padding: var(--th-space-xs) var(--th-space-sm);
    text-align: center;
}

.th-avail__grid thead th {
    color: var(--color-text-maxcontrast);
    font-weight: 500;
}

.th-avail__corner,
.th-avail__half {
    text-align: start;
    white-space: nowrap;
    font-weight: 400;
    color: var(--color-text-maxcontrast);
}

.th-avail__actions {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    flex-wrap: wrap;
    margin-block-start: var(--th-space-md);
}
</style>
