<template>
    <fieldset class="recurrence">
        <legend class="recurrence__legend">{{ t('teamhub', 'Repeat') }}</legend>

        <div class="recurrence__row">
            <div class="recurrence__freq">
                <NcSelect
                    :input-id="`${uid}-freq`"
                    label-outside
                    :model-value="modelValue.freq"
                    :options="freqOptions"
                    :reduce="o => o.id"
                    :clearable="false"
                    :aria-label-combobox="t('teamhub', 'Repeat')"
                    @update:model-value="v => patch({ freq: v || 'none' })" />
            </div>
            <template v-if="recurring">
                <label class="recurrence__inline-label" :for="`${uid}-interval`">
                    {{
                        // TRANSLATORS: before a number and a unit, as in "Every 2 weeks"
                        t('teamhub', 'Every')
                    }}
                </label>
                <NcTextField
                    :id="`${uid}-interval`"
                    class="recurrence__number"
                    :model-value="String(modelValue.interval)"
                    type="number"
                    min="1"
                    max="99"
                    label-outside
                    :aria-label="t('teamhub', 'Every')"
                    @update:model-value="v => patch({ interval: toInt(v, 1) })" />
                <span class="recurrence__unit">{{ unitLabel }}</span>
            </template>
        </div>

        <!-- Weekly: the days, Monday first. Toggles, as Calendar draws them. -->
        <div
            v-if="modelValue.freq === 'weekly'"
            class="recurrence__days"
            role="group"
            :aria-label="t('teamhub', 'Repeat on')">
            <NcButton
                v-for="day in weekdays"
                :key="day.code"
                size="small"
                :pressed="(modelValue.byDay || []).includes(day.code)"
                :aria-label="day.long"
                :title="day.long"
                @click="toggleDay(day.code)">
                {{ day.short }}
            </NcButton>
        </div>

        <!-- Monthly: the same date, or the same weekday position. -->
        <div v-if="modelValue.freq === 'monthly'" class="recurrence__choices">
            <NcCheckboxRadioSwitch
                :model-value="modelValue.monthlyMode"
                value="date"
                :name="`${uid}-monthly`"
                type="radio"
                @update:model-value="v => patch({ monthlyMode: v })">
                {{
                    // TRANSLATORS: monthly repeat on a day of the month, {day} is a number such as 16
                    t('teamhub', 'On day {day}', { day: dayOfMonth })
                }}
            </NcCheckboxRadioSwitch>
            <NcCheckboxRadioSwitch
                :model-value="modelValue.monthlyMode"
                value="weekday"
                :name="`${uid}-monthly`"
                type="radio"
                @update:model-value="v => patch({ monthlyMode: v })">
                {{ positionLabel }}
            </NcCheckboxRadioSwitch>
        </div>

        <!-- Ends: never, on a date, or after a number of times. -->
        <fieldset v-if="recurring" class="recurrence__ends">
            <legend class="recurrence__sublegend">{{ t('teamhub', 'Ends') }}</legend>
            <NcCheckboxRadioSwitch
                :model-value="modelValue.end.type"
                value="never"
                :name="`${uid}-end`"
                type="radio"
                @update:model-value="v => patchEnd({ type: v })">
                {{
                    // TRANSLATORS: a repeating meeting that has no end date
                    t('teamhub', 'Never')
                }}
            </NcCheckboxRadioSwitch>
            <div class="recurrence__end-row">
                <NcCheckboxRadioSwitch
                    :model-value="modelValue.end.type"
                    value="until"
                    :name="`${uid}-end`"
                    type="radio"
                    @update:model-value="v => patchEnd({ type: v })">
                    {{ t('teamhub', 'On date') }}
                </NcCheckboxRadioSwitch>
                <!-- Native date input: NC's recommended picker (ui-standards). -->
                <input
                    v-if="modelValue.end.type === 'until'"
                    :id="`${uid}-until`"
                    class="recurrence__date"
                    type="date"
                    :min="date"
                    :value="modelValue.end.until"
                    :aria-label="t('teamhub', 'Last date')"
                    @input="e => patchEnd({ until: e.target.value })">
            </div>
            <div class="recurrence__end-row">
                <NcCheckboxRadioSwitch
                    :model-value="modelValue.end.type"
                    value="count"
                    :name="`${uid}-end`"
                    type="radio"
                    @update:model-value="v => patchEnd({ type: v })">
                    {{
                        // TRANSLATORS: a repeating meeting that ends after a number of times, followed by the number
                        t('teamhub', 'After')
                    }}
                </NcCheckboxRadioSwitch>
                <template v-if="modelValue.end.type === 'count'">
                    <NcTextField
                        :id="`${uid}-count`"
                        class="recurrence__number"
                        :model-value="String(modelValue.end.count)"
                        type="number"
                        min="1"
                        max="500"
                        label-outside
                        :aria-label="t('teamhub', 'Number of times')"
                        @update:model-value="v => patchEnd({ count: toInt(v, 1) })" />
                    <span class="recurrence__unit">{{ n('teamhub', 'time', 'times', toInt(modelValue.end.count, 1)) }}</span>
                </template>
            </div>
        </fieldset>

        <p v-if="problemText" class="recurrence__problem">{{ problemText }}</p>
    </fieldset>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { formatIsoDate } from '../lib/localDate.js'
import { WEEKDAYS, isRecurring, monthlyPosition, recurrenceProblem, weekdayCode } from '../lib/meetingRecurrence.js'

/**
 * The date of weekday `index` (0 = Monday) in the week of 16 November 2026,
 * to name the weekdays in the viewer's language.
 */
function weekdayIso(index) {
    return `2026-11-${String(16 + index).padStart(2, '0')}`
}

let instanceCounter = 0

/**
 * RecurrenceEditor (v4.20.8): the Repeat field of the meeting wizard and Add
 * event, with the choices Nextcloud Calendar offers, so a series made here
 * opens in Calendar as an ordinary recurring event it can edit. A licensed
 * feature: the hosts render this only on a licensed instance, and the server
 * refuses a series from an unlicensed one regardless.
 *
 * v-model is the model from `defaultRecurrence()` in
 * `src/lib/meetingRecurrence.js`; every change emits a new object.
 * DESIGN §2.263.
 */
export default {
    name: 'RecurrenceEditor',
    components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField },
    props: {
        modelValue: { type: Object, required: true },
        /** The first meeting's date, YYYY-MM-DD. */
        date: { type: String, default: '' },
    },
    emits: ['update:modelValue'],
    data() {
        instanceCounter += 1
        return { uid: `recurrence-${instanceCounter}` }
    },
    computed: {
        recurring() {
            return isRecurring(this.modelValue)
        },
        freqOptions() {
            return [
                { id: 'none', label: t('teamhub', 'Does not repeat') },
                { id: 'daily', label: t('teamhub', 'Daily') },
                { id: 'weekly', label: t('teamhub', 'Weekly') },
                { id: 'monthly', label: t('teamhub', 'Monthly') },
                { id: 'yearly', label: t('teamhub', 'Yearly') },
            ]
        },
        unitLabel() {
            const count = this.toInt(this.modelValue.interval, 1)
            switch (this.modelValue.freq) {
            case 'daily': return n('teamhub', 'day', 'days', count)
            case 'weekly': return n('teamhub', 'week', 'weeks', count)
            case 'monthly': return n('teamhub', 'month', 'months', count)
            case 'yearly': return n('teamhub', 'year', 'years', count)
            default: return ''
            }
        },
        weekdays() {
            return WEEKDAYS.map((code, i) => {
                const iso = weekdayIso(i)
                return {
                    code,
                    short: formatIsoDate(iso, { weekday: 'short' }),
                    long: formatIsoDate(iso, { weekday: 'long' }),
                }
            })
        },
        dayOfMonth() {
            return Number((this.date || '').slice(8, 10)) || 1
        },
        positionLabel() {
            const code = weekdayCode(this.date) || 'MO'
            const index = WEEKDAYS.indexOf(code)
            const weekday = formatIsoDate(weekdayIso(index), { weekday: 'long' })
            switch (monthlyPosition(this.date)) {
            // TRANSLATORS: monthly repeat, {weekday} is a weekday name such as Tuesday
            case 1: return t('teamhub', 'On the first {weekday}', { weekday })
            case 2: return t('teamhub', 'On the second {weekday}', { weekday })
            case 3: return t('teamhub', 'On the third {weekday}', { weekday })
            case 4: return t('teamhub', 'On the fourth {weekday}', { weekday })
            default: return t('teamhub', 'On the last {weekday}', { weekday })
            }
        },
        problemText() {
            switch (recurrenceProblem(this.modelValue, this.date)) {
            case 'until-missing': return t('teamhub', 'Choose the last date of the series.')
            case 'until-before-start': return t('teamhub', 'The last date is before the first meeting.')
            case 'count-range': return t('teamhub', 'Enter a number of times between 1 and 500.')
            case 'interval-range': return t('teamhub', 'Enter a number between 1 and 99.')
            default: return ''
            }
        },
    },
    methods: {
        t,
        n,

        toInt(value, fallback) {
            const parsed = parseInt(value, 10)
            return Number.isFinite(parsed) ? parsed : fallback
        },

        patch(changes) {
            const next = { ...this.modelValue, ...changes }
            // Switching to weekly with no day picked repeats on the meeting's own day.
            if (changes.freq === 'weekly' && !(next.byDay || []).length) {
                const code = weekdayCode(this.date)
                next.byDay = code ? [code] : []
            }
            this.$emit('update:modelValue', next)
        },

        patchEnd(changes) {
            this.patch({ end: { ...this.modelValue.end, ...changes } })
        },

        toggleDay(code) {
            const days = new Set(this.modelValue.byDay || [])
            if (days.has(code)) {
                // Keep at least one day: an empty weekly series is not a series.
                if (days.size > 1) days.delete(code)
            } else {
                days.add(code)
            }
            this.patch({ byDay: WEEKDAYS.filter(d => days.has(d)) })
        },
    },
}
</script>

<style scoped>
.recurrence {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.recurrence__legend,
.recurrence__sublegend {
    padding: 0;
    margin-block-end: var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
}

.recurrence__row,
.recurrence__end-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-sm);
}

.recurrence__freq {
    flex: 1 1 calc(var(--th-space-xxl) * 6);
    min-width: 0;
}

.recurrence__freq :deep(.v-select.select) {
    margin: 0;
}

.recurrence__inline-label,
.recurrence__unit {
    color: var(--color-main-text);
}

.recurrence__number {
    flex: 0 0 calc(var(--th-space-xxl) * 2.5);
}

.recurrence__days {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-xs);
}

.recurrence__choices {
    display: flex;
    flex-direction: column;
}

.recurrence__ends {
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.recurrence__end-row input.recurrence__date {
    margin: 0;
    min-height: var(--default-clickable-area);
}

.recurrence__problem {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-error);
}
</style>
