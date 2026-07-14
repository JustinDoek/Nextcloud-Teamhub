<template>
    <div class="pres-cal">
        <div class="pres-cal__months">
            <div
                v-for="(month, mi) in months"
                :key="mi"
                class="pres-cal__month">

                <!-- Month header -->
                <div class="pres-cal__month-title">
                    {{ month.label }}
                </div>

                <!-- Day-of-week header: Mon … Sun -->
                <div class="pres-cal__dow-row">
                    <div
                        v-for="d in dayNames"
                        :key="d"
                        class="pres-cal__dow-label">
                        {{ d }}
                    </div>
                </div>

                <!-- Weeks -->
                <div
                    v-for="(week, wi) in month.weeks"
                    :key="wi"
                    class="pres-cal__week">
                    <div
                        v-for="(day, di) in week"
                        :key="di"
                        class="pres-cal__day"
                        :class="{
                            'pres-cal__day--empty':   !day,
                            'pres-cal__day--today':   day && day.iso === today,
                            'pres-cal__day--past':    day && day.iso < today,
                            'pres-cal__day--weekend': day && (di === 5 || di === 6),
                        }">
                        <template v-if="day">
                            <div class="pres-cal__day-num">{{ day.d }}</div>

                            <!-- Morning block -->
                            <div
                                class="pres-cal__slot pres-cal__slot--am"
                                :class="{
                                    'pres-cal__slot--locked': isLocked(day.iso, 0),
                                    'pres-cal__slot--empty':  !slotFor(day.iso, 0),
                                    'pres-cal__slot--past':   day.iso < today,
                                }"
                                :style="slotStyle(day.iso, 0)"
                                :title="slotTitle(day.iso, 0, t('teamhub', 'Morning'))"
                                :aria-label="slotAriaLabel(day, 0)"
                                :role="canEdit(day) ? 'button' : undefined"
                                :tabindex="canEdit(day) ? 0 : undefined"
                                @click="canEdit(day) && $emit('pick', { iso: day.iso, half: 0 })"
                                @keydown.enter="canEdit(day) && $emit('pick', { iso: day.iso, half: 0 })"
                                @keydown.space.prevent="canEdit(day) && $emit('pick', { iso: day.iso, half: 0 })">
                                <span v-if="slotFor(day.iso, 0)" class="pres-cal__slot-label">
                                    {{ slotFor(day.iso, 0).presence_type_label }}
                                </span>
                                <LockIcon v-if="isLocked(day.iso, 0)" :size="9" class="pres-cal__lock" />
                            </div>

                            <!-- Afternoon block -->
                            <div
                                class="pres-cal__slot pres-cal__slot--pm"
                                :class="{
                                    'pres-cal__slot--locked': isLocked(day.iso, 1),
                                    'pres-cal__slot--empty':  !slotFor(day.iso, 1),
                                    'pres-cal__slot--past':   day.iso < today,
                                }"
                                :style="slotStyle(day.iso, 1)"
                                :title="slotTitle(day.iso, 1, t('teamhub', 'Afternoon'))"
                                :aria-label="slotAriaLabel(day, 1)"
                                :role="canEdit(day) ? 'button' : undefined"
                                :tabindex="canEdit(day) ? 0 : undefined"
                                @click="canEdit(day) && $emit('pick', { iso: day.iso, half: 1 })"
                                @keydown.enter="canEdit(day) && $emit('pick', { iso: day.iso, half: 1 })"
                                @keydown.space.prevent="canEdit(day) && $emit('pick', { iso: day.iso, half: 1 })">
                                <span v-if="slotFor(day.iso, 1)" class="pres-cal__slot-label">
                                    {{ slotFor(day.iso, 1).presence_type_label }}
                                </span>
                                <LockIcon v-if="isLocked(day.iso, 1)" :size="9" class="pres-cal__lock" />
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import LockIcon from 'vue-material-design-icons/Lock.vue'

/**
 * Two-month calendar showing materialised presence slots.
 *
 * Props:
 *   slots   — array of slot objects from GET /presence/slots (enriched)
 *   loading — bool
 *
 * Emits:
 *   pick({ iso, half }) — user clicked an editable cell; parent opens picker
 *
 * Slot cells are clickable for today and future non-holiday dates.
 * Past dates and holiday slots are displayed but not clickable.
 */
export default {
    name: 'PresenceCalendarView',
    components: { LockIcon },
    props: {
        slots:   { type: Array, default: () => [] },
        loading: { type: Boolean, default: false },
    },
    emits: ['pick'],
    computed: {
        today() {
            return new Date().toISOString().slice(0, 10)
        },

        dayNames() {
            // Short Mon–Sun labels in the user's locale.
            const base = new Date('2024-01-01') // a Monday
            return Array.from({ length: 7 }, (_, i) => {
                const d = new Date(base)
                d.setDate(d.getDate() + i)
                return d.toLocaleDateString(undefined, { weekday: 'short' })
            })
        },

        /** Slot lookup: key = `${iso}_${half}` */
        slotMap() {
            const m = {}
            for (const s of this.slots) {
                m[`${s.slot_date}_${s.half_day}`] = s
            }
            return m
        },

        /** Build four months starting from the 1st of the current month. */
        months() {
            const now   = new Date()
            const year  = now.getFullYear()
            const month = now.getMonth() // 0-based
            return Array.from({ length: 4 }, (_, i) => {
                const totalMonth = month + i
                const y = year + Math.floor(totalMonth / 12)
                const m = totalMonth % 12
                return this.buildMonth(y, m)
            })
        },
    },
    methods: {
        t,

        buildMonth(year, month) {
            const firstDay = new Date(year, month, 1)
            // Monday-based grid: getDay() returns 0=Sun,1=Mon,...
            const startDow = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1
            const daysInMonth = new Date(year, month + 1, 0).getDate()

            const label = firstDay.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
            const cells = []

            // Leading empty cells
            for (let i = 0; i < startDow; i++) cells.push(null)

            // Day cells
            for (let d = 1; d <= daysInMonth; d++) {
                const iso = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
                cells.push({ iso, d })
            }

            // Chunk into weeks of 7
            const weeks = []
            for (let i = 0; i < cells.length; i += 7) {
                const week = cells.slice(i, i + 7)
                while (week.length < 7) week.push(null) // trailing padding
                weeks.push(week)
            }

            return { label, weeks }
        },

        slotFor(iso, half) {
            return this.slotMap[`${iso}_${half}`] || null
        },

        isLocked(iso, half) {
            return this.slotFor(iso, half)?.is_locked === true
        },

        canEdit(day) {
            if (!day) return false
            if (day.iso < this.today) return false
            return true // holiday lock is enforced per-half, not per-day
        },

        slotStyle(iso, half) {
            const s = this.slotFor(iso, half)
            if (!s || !s.presence_type_color) return {}
            return { backgroundColor: s.presence_type_color }
        },

        slotTitle(iso, half, halfLabel) {
            const s = this.slotFor(iso, half)
            if (!s) return halfLabel
            // Defensive fallback if a locked slot somehow has a null label —
            // shouldn't happen (holiday lock always carries a type) but a
            // null here would interpolate as the literal string "null".
            if (s.is_locked) return `${s.presence_type_label || halfLabel} (${t('teamhub', 'Holiday — locked')})`
            return s.presence_type_label || halfLabel
        },

        slotAriaLabel(day, half) {
            const halfLabel = half === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
            const s = this.slotFor(day.iso, half)
            const dateStr = new Date(day.iso + 'T12:00:00').toLocaleDateString(undefined, {
                weekday: 'long', month: 'short', day: 'numeric',
            })
            // A slot row exists even after the user clears it (presence_type_id=null);
            // its presence_type_label comes through as null in that case. Treat a
            // null label as "not set" — otherwise NC's t() runs String.replace with
            // a null param and throws, which Vue then propagates and the entire
            // calendar render fails. One bad slot must not blank the calendar.
            if (!s || !s.presence_type_label) {
                return t('teamhub', '{date} {half}: not set. Click to set.', { date: dateStr, half: halfLabel })
            }
            if (s.is_locked) return t('teamhub', '{date} {half}: {type} (holiday, locked)', { date: dateStr, half: halfLabel, type: s.presence_type_label })
            return t('teamhub', '{date} {half}: {type}. Click to change.', { date: dateStr, half: halfLabel, type: s.presence_type_label })
        },
    },
}
</script>

<style scoped>
.pres-cal__months {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}

@media (max-width: 700px) {
    .pres-cal__months {
        grid-template-columns: 1fr;
    }
}

.pres-cal__month {
    flex: 1;
    min-width: 280px;
}

.pres-cal__month-title {
    font-size: var(--th-font-body);
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--color-main-text);
}

.pres-cal__dow-row {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 2px;
}
.pres-cal__dow-label {
    font-size: var(--th-font-micro);
    font-weight: 500;
    text-align: center;
    color: var(--color-text-maxcontrast);
    padding: 2px 0;
}

.pres-cal__week {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 2px;
}

.pres-cal__day {
    min-height: 58px;
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.pres-cal__day--empty {
    background: transparent;
}
.pres-cal__day--today .pres-cal__day-num {
    color: var(--color-primary);
    font-weight: 700;
}
.pres-cal__day--weekend {
    background: var(--color-background-dark);
}
.pres-cal__day--past {
    opacity: 0.55;
}

.pres-cal__day-num {
    font-size: var(--th-font-micro);
    text-align: right;
    padding: 2px 4px;
    color: var(--color-text-maxcontrast);
    line-height: 1.2;
}

.pres-cal__slot {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 500;
    text-align: center;
    overflow: hidden;
    position: relative;
    line-height: 1.1;
    padding: 1px 2px;
    cursor: default;
}
.pres-cal__slot--am {
    border-bottom: 1px solid rgba(0,0,0,0.06);
}
.pres-cal__slot[role="button"] {
    cursor: pointer;
}
.pres-cal__slot[role="button"]:hover {
    filter: brightness(0.88);
}
.pres-cal__slot[role="button"]:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: -1px;
}
.pres-cal__slot--empty {
    background: transparent;
}
.pres-cal__slot--locked {
    /* Diagonal stripe for holiday */
    background-image: repeating-linear-gradient(
        -45deg,
        transparent,
        transparent 2px,
        rgba(0,0,0,0.07) 2px,
        rgba(0,0,0,0.07) 4px
    );
}
.pres-cal__slot--past[role="button"] {
    cursor: default;
    pointer-events: none;
}

.pres-cal__slot-label {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    max-width: 100%;
}

.pres-cal__lock {
    position: absolute;
    top: 1px;
    right: 2px;
    opacity: 0.5;
}
</style>
