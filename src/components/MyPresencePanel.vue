<template>
    <div class="my-presence-panel">
        <div class="my-presence-panel__header">
            <h2 class="my-presence-panel__title">{{ t('teamhub', 'My workplace') }}</h2>
            <p class="my-presence-panel__desc">
                {{ t('teamhub', 'Set where you normally work. Nextcloud manages your working hours and absences; workplace choices apply across all your teams.') }}
            </p>
            <a class="my-presence-panel__availability-link" :href="availabilitySettingsUrl" target="_blank" rel="noopener noreferrer">
                {{ t('teamhub', 'Configure working hours and absences in Nextcloud') }} ↗
            </a>
        </div>

        <div v-if="loading" class="my-presence-loading">
            <NcLoadingIcon :size="ICON_LARGE" />
        </div>
        <div v-else-if="loadError" class="my-presence-error" role="alert">
            {{ loadError }}
        </div>

        <template v-else>
            <!-- Section 1: Week template -->
            <section class="my-presence-section">
                <h3 class="my-presence-section__title">{{ t('teamhub', 'Usual workplace') }}</h3>
                <p class="my-presence-section__desc">
                    {{ t('teamhub', 'Your workplace pattern is separate from your hours. Use morning and afternoon only when your location changes during the day.') }}
                </p>

                <div class="presence-grid" role="grid" :aria-label="t('teamhub', 'Week presence template')">
                    <div class="presence-grid__header" role="row">
                        <div class="presence-grid__corner" role="columnheader" aria-hidden="true"></div>
                        <div
                            v-for="day in days"
                            :key="day.index"
                            class="presence-grid__day-label"
                            role="columnheader">
                            {{ day.label }}
                        </div>
                    </div>

                    <!-- One workplace for the whole day is the default.
                         AM/PM are optional refinements, not working hours. -->
                    <div class="presence-grid__row" role="row">
                        <div class="presence-grid__half-label" role="rowheader">{{ t('teamhub', 'All day') }}</div>
                        <div v-for="day in days" :key="'full-'+day.index" class="presence-grid__cell" role="gridcell">
                            <button v-if="isSplitDay(day.index)"
                                type="button" class="presence-split-day"
                                :aria-label="t('teamhub', 'Change workplace for entire day')"
                                @click="onDayPick(day.index)">
                                {{ t('teamhub', 'Split') }}
                            </button>
                            <PresenceCell v-else
                                :cell="getDraftCell(day.index, 0)"
                                :types="types"
                                :dirty="isDirty(day.index, 0) || isDirty(day.index, 1)"
                                :saving="false"
                                :half-day-label="t('teamhub', 'All day')"
                                :day-label="day.label"
                                @pick="onDayPick(day.index)" />
                        </div>
                    </div>

                    <template v-if="showSplitDays">
                    <div class="presence-grid__row" role="row">
                        <div class="presence-grid__half-label" role="rowheader">{{ t('teamhub', 'Morning') }}</div>
                        <div
                            v-for="day in days"
                            :key="'am-'+day.index"
                            class="presence-grid__cell"
                            role="gridcell">
                            <PresenceCell
                                :cell="getDraftCell(day.index, 0)"
                                :types="types"
                                :dirty="isDirty(day.index, 0)"
                                :saving="false"
                                :half-day-label="t('teamhub', 'Morning')"
                                :day-label="day.label"
                                @pick="onTemplatePick(day.index, 0)" />
                        </div>
                    </div>

                    <div class="presence-grid__row" role="row">
                        <div class="presence-grid__half-label" role="rowheader">{{ t('teamhub', 'Afternoon') }}</div>
                        <div
                            v-for="day in days"
                            :key="'pm-'+day.index"
                            class="presence-grid__cell"
                            role="gridcell">
                            <PresenceCell
                                :cell="getDraftCell(day.index, 1)"
                                :types="types"
                                :dirty="isDirty(day.index, 1)"
                                :saving="false"
                                :half-day-label="t('teamhub', 'Afternoon')"
                                :day-label="day.label"
                                @pick="onTemplatePick(day.index, 1)" />
                        </div>
                    </div>
                    </template>
                </div>

                <div class="presence-grid__actions">
                    <NcButton variant="tertiary" @click="showSplitDays = !showSplitDays">
                        {{ showSplitDays ? t('teamhub', 'Hide morning/afternoon') : t('teamhub', 'Split morning/afternoon') }}
                    </NcButton>
                    <NcButton
                        variant="primary"
                        :disabled="!hasDirty || savingTemplate"
                        @click="saveTemplate">
                        <template #icon>
                            <NcLoadingIcon v-if="savingTemplate" :size="ICON_BODY" />
                            <ContentSaveIcon v-else :size="ICON_BODY" />
                        </template>
                        {{ t('teamhub', 'Save') }}
                    </NcButton>
                    <NcButton
                        v-if="hasDirty"
                        variant="tertiary"
                        :disabled="savingTemplate"
                        @click="discardDraft">
                        {{ t('teamhub', 'Discard changes') }}
                    </NcButton>
                    <span v-if="hasDirty" class="presence-grid__unsaved-hint" aria-live="polite">
                        {{ t('teamhub', 'You have unsaved changes') }}
                    </span>
                </div>
            </section>

            <!-- Section 2: Legend -->
            <section class="my-presence-section my-presence-section--legend">
                <div class="presence-legend__list">
                    <div v-for="type in selectableTypes" :key="type.id" class="presence-legend__item">
                        <span class="presence-legend__swatch" :style="{ background: type.color }" aria-hidden="true"></span>
                        <span>{{ type.label }}</span>
                    </div>
                    <div class="presence-legend__item">
                        <span class="presence-legend__swatch presence-legend__swatch--empty" aria-hidden="true"></span>
                        <span>{{ t('teamhub', 'Clear') }}</span>
                    </div>
                </div>
            </section>

            <!-- Section 3: Per-date overrides -->
            <section class="my-presence-section">
                <h3 class="my-presence-section__title">{{ t('teamhub', 'Temporary workplace changes') }}</h3>
                <p class="my-presence-section__desc">
                    {{ t('teamhub', 'Change a workplace or mark vacation, sickness or other non-working time. Your usual workplace remains unchanged. Public holidays are managed by administrators.') }}
                </p>
                <div class="presence-period">
                    <h4 class="presence-period__title">{{ t('teamhub', 'Set a day or date range') }}</h4>
                    <div class="presence-period__fields">
                        <label class="presence-period__field">
                            <span>{{ t('teamhub', 'From') }}</span>
                            <input v-model="range.from" type="date" :min="today" required>
                        </label>
                        <label class="presence-period__field">
                            <span>{{ t('teamhub', 'To (inclusive)') }}</span>
                            <input v-model="range.to" type="date" :min="range.from || today" required>
                        </label>
                        <label class="presence-period__field">
                            <span>{{ t('teamhub', 'Part of day') }}</span>
                            <select v-model="range.part">
                                <option value="all">{{ t('teamhub', 'Full day') }}</option>
                                <option value="morning">{{ t('teamhub', 'Morning') }}</option>
                                <option value="afternoon">{{ t('teamhub', 'Afternoon') }}</option>
                            </select>
                        </label>
                        <label class="presence-period__field">
                            <span>{{ t('teamhub', 'Temporary change') }}</span>
                            <select v-model="range.typeSlug">
                                <option v-for="type in absenceTypes" :key="type.id" :value="type.slug">{{ type.label }}</option>
                                <option value="restore">{{ t('teamhub', 'Restore usual workplace') }}</option>
                            </select>
                        </label>
                    </div>
                    <p class="presence-period__note">
                        {{ t('teamhub', 'Time off here blocks TeamHub meeting suggestions, but is not synchronized to Nextcloud Calendar. Update Nextcloud Absence separately if needed.') }}
                    </p>
                    <NcButton variant="primary" :disabled="savingRange || !rangeIsValid" @click="saveRange">
                        <template #icon><NcLoadingIcon v-if="savingRange" :size="ICON_BODY" /></template>
                        {{ t('teamhub', 'Apply temporary change') }}
                    </NcButton>
                </div>
                <div v-if="loadingSlots" class="my-presence-loading">
                    <NcLoadingIcon :size="ICON_BODY" />
                </div>
                <PresenceCalendarView
                    v-else
                    :slots="calendarSlots"
                    @pick="onCalendarPick" />
            </section>
        </template>

        <!-- Shared picker dialog -->
        <NcDialog
            v-if="picker.open"
            :name="pickerTitle"
            size="small"
            @update:open="closePicker">
            <template #default>
                <ul class="presence-picker-list" role="listbox" :aria-label="t('teamhub', 'Select a workplace')">
                    <li
                        v-for="type in pickerTypes"
                        :key="type.id"
                        class="presence-picker-item"
                        :class="{ 'presence-picker-item--selected': picker.currentTypeId === type.id }"
                        role="option"
                        :aria-selected="picker.currentTypeId === type.id"
                        tabindex="0"
                        @click="selectType(type.id)"
                        @keydown.enter="selectType(type.id)"
                        @keydown.space.prevent="selectType(type.id)">
                        <span class="presence-picker-item__swatch" :style="{ background: type.color }" aria-hidden="true"></span>
                        <span class="presence-picker-item__label">{{ type.label }}</span>
                        <CheckIcon v-if="picker.currentTypeId === type.id" :size="ICON_BODY" class="presence-picker-item__check" />
                    </li>

                    <li v-if="selectedTypeRequiresLocation" class="presence-picker-location" role="presentation">
                        <label class="presence-picker-location__label">{{ t('teamhub', 'Location (optional)') }}</label>
                        <!-- v4.10.10: NcSelect (design guide § Dropdown). NcSelect has no
                             option groups, so the building and floor are part of each
                             room's label instead. -->
                        <NcSelect
                            v-model="picker.roomId"
                            @update:modelValue="onRoomPicked"
                            class="presence-picker-location__select"
                            :aria-label-combobox="t('teamhub', 'Select a room')"
                            label-outside
                            :options="roomOptions"
                            :reduce="o => o.id"
                            :clearable="false" />
                    </li>

                    <li
                        class="presence-picker-item presence-picker-item--clear"
                        :class="{ 'presence-picker-item--selected': picker.currentTypeId === null && !picker.justOpened }"
                        role="option"
                        :aria-selected="picker.currentTypeId === null && !picker.justOpened"
                        tabindex="0"
                        @click="selectType(null)"
                        @keydown.enter="selectType(null)"
                        @keydown.space.prevent="selectType(null)">
                        <span class="presence-picker-item__swatch presence-picker-item__swatch--empty" aria-hidden="true"></span>
                        <span class="presence-picker-item__label">{{ t('teamhub', 'Clear (no entry)') }}</span>
                    </li>
                </ul>
            </template>
            <template #actions>
                <NcButton @click="closePicker">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton variant="primary" :disabled="picker.justOpened" @click="confirmPicker">
                    {{ t('teamhub', 'Apply') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { todayIso, toIsoDate, formatIsoDate } from '../lib/localDate.js'
import { NcButton, NcLoadingIcon, NcDialog, NcSelect } from '@nextcloud/vue'
import CheckIcon            from 'vue-material-design-icons/Check.vue'
import ContentSaveIcon      from 'vue-material-design-icons/ContentSave.vue'
import PresenceCell         from './PresenceCell.vue'
import PresenceCalendarView from './PresenceCalendarView.vue'
import { ICON_BODY, ICON_LARGE } from '../constants/uiTokens.js'

export default {
    name: 'MyPresencePanel',
    components: { NcButton, NcLoadingIcon, NcDialog, NcSelect, CheckIcon, ContentSaveIcon, PresenceCell, PresenceCalendarView },
    data() {
        return {
            ICON_BODY,
            ICON_LARGE,
            availabilitySettingsUrl: generateUrl('/settings/user/availability'),
            loading: true,
            loadingSlots: true,
            loadError: null,
            savingTemplate: false,
            showSplitDays: false,
            cells: {},   // server-confirmed
            draft: {},   // local edits
            types: [],
            locationTree: [],
            calendarSlots: [],
            picker: {
                open: false,
                mode: 'template',   // 'template' | 'override'
                day: null, half: null,
                iso: null, overrideHalf: null,
                currentTypeId: null,
                roomId: null,
                justOpened: true,
            },
            today: todayIso(),
            range: { from: todayIso(), to: todayIso(), part: 'all', typeSlug: 'vacation' },
            savingRange: false,
        }
    },
    computed: {
        /** Flat room list for the picker: "Building — Floor — Room", with a "no room" entry first. */
        roomOptions() {
            const out = [{ id: null, label: t('teamhub', 'Office location not specified') }]
            for (const building of this.locationTree) {
                for (const floor of building.floors || []) {
                    for (const room of floor.rooms || []) {
                        out.push({ id: room.id, label: `${building.name} — ${floor.name} — ${room.name}` })
                    }
                }
            }
            return out
        },
        days() {
            const labels = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return labels.map((label, index) => ({ label, index }))
        },
        selectableTypes() {
            return this.types.filter(tp => tp.selectable_by_user && !tp.is_busy)
        },
        absenceTypes() {
            // Only these built-ins are allowed as time-off overrides. An admin
            // may rename their labels, but their slugs remain the stable keys.
            return this.types.filter(tp => tp.selectable_by_user && ['vacation', 'sick', 'non_working_day'].includes(tp.slug))
        },
        pickerTypes() {
            return this.picker.mode === 'override'
                ? [...this.selectableTypes, ...this.absenceTypes]
                : this.selectableTypes
        },
        rangeIsValid() {
            if (!this.range.from || !this.range.to || this.range.to < this.range.from) return false
            if (this.range.typeSlug !== 'restore' && !this.absenceTypes.some(tp => tp.slug === this.range.typeSlug)) return false
            // Back-end enforces the exact 120-day cap; give immediate feedback.
            const days = Math.round((Date.parse(this.range.to) - Date.parse(this.range.from)) / 86400000) + 1
            return days >= 1 && days <= 120
        },
        hasDirty() {
            for (let day = 0; day <= 6; day++) {
                for (const half of [0, 1]) {
                    const k = `${day}_${half}`
                    if ((this.cells[k]?.presence_type_id ?? null) !== (this.draft[k]?.presence_type_id ?? null)) return true
                    if ((this.cells[k]?.location_room_id ?? null) !== (this.draft[k]?.location_room_id ?? null)) return true
                }
            }
            return false
        },
        pickerTitle() {
            if (this.picker.mode === 'override' && this.picker.iso) {
                const dateStr = formatIsoDate(this.picker.iso, { weekday: 'short', month: 'short', day: 'numeric' })
                const half = this.picker.overrideHalf === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
                return `${dateStr} — ${half}`
            }
            if (this.picker.day !== null) {
                if (this.picker.mode === 'day') return `${this.days[this.picker.day]?.label || ''} — ${t('teamhub', 'All day')}`
                const half = this.picker.half === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
                return `${this.days[this.picker.day]?.label || ''} ${half}`
            }
            return t('teamhub', 'Select a workplace')
        },
        selectedTypeRequiresLocation() {
            if (this.picker.currentTypeId === null) return false
            return this.types.find(tp => tp.id === this.picker.currentTypeId)?.requires_location || false
        },
    },
    mounted() { this.load() },
    methods: {
        t, n,

        async load() {
            this.loading = true
            this.loadError = null
            try {
                const [tmplRes, typesRes, locRes] = await Promise.all([
                    axios.get(generateUrl('/apps/teamhub/api/v1/presence/template')),
                    axios.get(generateUrl('/apps/teamhub/api/v1/presence/types')),
                    axios.get(generateUrl('/apps/teamhub/api/v1/presence/locations')),
                ])
                this.types        = typesRes.data || []
                this.locationTree = locRes.data   || []
                this.applyTemplate(tmplRes.data || [])
            } catch (err) {
                this.loadError = err?.response?.data?.error || t('teamhub', 'Failed to load presence settings')
            } finally {
                this.loading = false
            }
            this.loadSlots()
        },

        applyTemplate(rows) {
            const cells = {}
            for (const row of rows) {
                cells[`${row.day_of_week}_${row.half_day}`] = { ...row }
            }
            this.cells = cells
            this.draft = JSON.parse(JSON.stringify(cells))
        },

        /**
         * The slot window the panel loads: today through the end of the
         * fourth month out. Local-calendar dates — `slot_date` is a floating
         * date, so deriving it through UTC asked for the wrong first day
         * during the viewer's early hours.
         *
         * @return {{from: string, to: string}}
         */
        slotWindow() {
            const end = new Date()
            end.setMonth(end.getMonth() + 4)
            end.setDate(0) // last day of the 4th month from now
            return { from: todayIso(), to: toIsoDate(end) }
        },

        async loadSlots() {
            this.loadingSlots = true
            try {
                const { from: today, to: endDate } = this.slotWindow()
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/presence/slots'),
                    { params: { from: today, to: endDate } },
                )
                this.calendarSlots = data || []
                // If any of the 4 months is completely empty, trigger a
                // materialisation so the calendar shows the template default.
                // This covers the case where the rolling-window cron hasn't
                // run yet for months 3–4, or the user just saved their template.
                if (this.calendarSlots.length === 0 && Object.keys(this.cells).length > 0) {
                    await this.triggerMaterialise()
                }
            } catch (err) { /* non-fatal */ } finally {
                this.loadingSlots = false
            }
        },

        async triggerMaterialise() {
            try {
                await axios.post(generateUrl('/apps/teamhub/api/v1/presence/slots/materialise'))
                // Reload slots after materialisation.
                const { from: today, to: endDate } = this.slotWindow()
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/presence/slots'),
                    { params: { from: today, to: endDate } },
                )
                this.calendarSlots = data || []
            } catch (err) { /* non-fatal — calendar stays with whatever it has */ }
        },

        getDraftCell(day, half) {
            return this.draft[`${day}_${half}`] || { day_of_week: day, half_day: half, presence_type_id: null, location_room_id: null }
        },

        isSplitDay(day) {
            const am = this.getDraftCell(day, 0)
            const pm = this.getDraftCell(day, 1)
            return am.presence_type_id !== pm.presence_type_id
                || am.location_room_id !== pm.location_room_id
        },

        onDayPick(day) {
            const cell = this.getDraftCell(day, 0)
            this.picker = { open: true, mode: 'day', day, half: null, iso: null, overrideHalf: null,
                currentTypeId: this.isSplitDay(day) ? null : cell.presence_type_id,
                roomId: this.isSplitDay(day) ? null : cell.location_room_id, justOpened: true }
        },

        isDirty(day, half) {
            const k = `${day}_${half}`
            return (this.cells[k]?.presence_type_id ?? null) !== (this.draft[k]?.presence_type_id ?? null)
                || (this.cells[k]?.location_room_id ?? null) !== (this.draft[k]?.location_room_id ?? null)
        },

        discardDraft() {
            this.draft = JSON.parse(JSON.stringify(this.cells))
        },

        async saveTemplate() {
            this.savingTemplate = true
            try {
                // Build the full 14-cell payload and send in one request.
                // The bulk endpoint saves all cells then materialises once,
                // avoiding the duplicate-key race from concurrent rematerialisation.
                const cells = []
                for (let day = 0; day <= 6; day++) {
                    for (const half of [0, 1]) {
                        const cell = this.getDraftCell(day, half)
                        cells.push({
                            day_of_week:      day,
                            half_day:         half,
                            presence_type_id: cell.presence_type_id,
                            location_room_id: cell.presence_type_id !== null ? cell.location_room_id : null,
                        })
                    }
                }
                const { data } = await axios.put(
                    generateUrl('/apps/teamhub/api/v1/presence/template/bulk'),
                    { cells }
                )
                // Server returns the full template; sync confirmed state.
                this.applyTemplate(data)
                showSuccess(t('teamhub', 'Workplace pattern saved'))
                this.loadSlots()
            } catch (err) {
                showError(err?.response?.data?.error || t('teamhub', 'Failed to save template'))
            } finally {
                this.savingTemplate = false
            }
        },

        onTemplatePick(day, half) {
            const cell = this.getDraftCell(day, half)
            this.picker = { open: true, mode: 'template', day, half, iso: null, overrideHalf: null,
                currentTypeId: cell.presence_type_id, roomId: cell.location_room_id, justOpened: true }
        },

        onCalendarPick({ iso, half }) {
            const existing = this.calendarSlots.find(s => s.slot_date === iso && s.half_day === half)
            if (existing?.is_locked) return
            this.picker = { open: true, mode: 'override', day: null, half: null, iso, overrideHalf: half,
                currentTypeId: existing?.presence_type_id || null, roomId: existing?.location_room_id || null, justOpened: true }
        },

        onRoomPicked(roomId) {
            // Selecting a different room is a real change even when Office was
            // already selected. Otherwise Apply stays disabled until the user
            // toggles Office → Home → Office.
            this.picker.roomId = roomId
            this.picker.justOpened = false
        },

        selectType(typeId) {
            this.picker.currentTypeId = typeId
            this.picker.justOpened   = false
            if (typeId === null || !this.selectedTypeRequiresLocation) this.picker.roomId = null
        },

        closePicker() {
            this.picker = { ...this.picker, open: false, justOpened: true }
        },

        async confirmPicker() {
            if (this.picker.justOpened) return
            if (this.picker.mode === 'template' || this.picker.mode === 'day') {
                const { day, half, currentTypeId, roomId } = this.picker
                const halves = this.picker.mode === 'day' ? [0, 1] : [half]
                for (const h of halves) {
                    this.draft[`${day}_${h}`] = {
                        day_of_week: day, half_day: h,
                        presence_type_id: currentTypeId,
                        location_room_id: currentTypeId !== null ? roomId : null,
                    }
                }
                this.closePicker()
            } else {
                const { iso, overrideHalf, currentTypeId, roomId } = this.picker
                this.closePicker()
                await this.saveOverride(iso, overrideHalf, currentTypeId, roomId)
            }
        },

        async saveRange() {
            if (this.savingRange || !this.rangeIsValid) return
            this.savingRange = true
            try {
                const restore = this.range.typeSlug === 'restore'
                const type = this.absenceTypes.find(tp => tp.slug === this.range.typeSlug)
                const { data } = await axios.put(generateUrl('/apps/teamhub/api/v1/presence/slots/override-range'), {
                    from_date: this.range.from,
                    to_date: this.range.to,
                    part: this.range.part,
                    presence_type_id: restore ? null : type.id,
                    restore,
                })
                await this.loadSlots()
                showSuccess(t('teamhub', 'Temporary change saved'))
                if (data?.skipped > 0) showSuccess(t('teamhub', '{count} public holiday days were skipped', { count: data.skipped }))
            } catch (err) {
                showError(err?.response?.data?.error || t('teamhub', 'Failed to save temporary change'))
            } finally {
                this.savingRange = false
            }
        },

        async saveOverride(iso, half, typeId, roomId) {
            // Optimistic update — reflect the change immediately in the calendar
            // so the user sees feedback without waiting for the server round-trip.
            const typeObj = this.types.find(tp => tp.id === typeId) || null
            const optimisticSlot = typeId !== null ? {
                slot_date:           iso,
                half_day:            half,
                presence_type_id:    typeId,
                presence_type_label: typeObj?.label || null,
                presence_type_color: typeObj?.color || null,
                presence_type_slug:  typeObj?.slug  || null,
                is_locked:           false,
                source:              'override',
                location_room_id:    roomId,
            } : null

            // Apply optimistic update to calendarSlots.
            const idx = this.calendarSlots.findIndex(
                s => s.slot_date === iso && s.half_day === half
            )
            if (optimisticSlot) {
                if (idx >= 0) {
                    this.calendarSlots[idx] = optimisticSlot
                } else {
                    this.calendarSlots.push(optimisticSlot)
                }
            } else if (idx >= 0) {
                this.calendarSlots.splice(idx, 1)
            }

            try {
                await axios.put(generateUrl('/apps/teamhub/api/v1/presence/slots/override'), {
                    slot_date: iso, half_day: half,
                    presence_type_id: typeId,
                    location_room_id: typeId !== null ? roomId : null,
                })
                // Reload slots silently in the background to get server-confirmed state.
                this.loadSlots()
            } catch (err) {
                // Roll back the optimistic update on failure.
                this.loadSlots()
                if (err?.response?.status === 409) {
                    showError(t('teamhub', 'This slot is locked by a holiday and cannot be changed.'))
                } else {
                    showError(err?.response?.data?.error || t('teamhub', 'Failed to save override'))
                }
            }
        },
    },
}
</script>

<style scoped>
.my-presence-panel { max-width: 900px; }
.my-presence-panel__title { font-size: var(--th-font-heading-lg); font-weight: 600; margin: 0 0 6px; }
.my-presence-panel__desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); margin: 0 0 24px; }

.my-presence-loading { display: flex; justify-content: center; padding: 40px; }
.my-presence-error { padding: 12px 16px; background: var(--color-error-background, var(--color-background-hover)); color: var(--color-text-error); border-radius: var(--border-radius-small); }

.my-presence-section { margin-bottom: 36px; }
.my-presence-section__title { font-size: var(--th-font-heading); font-weight: 600; margin: 0 0 4px; }
.my-presence-section__desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); margin: 0 0 16px; }
.my-presence-section--legend { margin-bottom: 24px; }

.presence-grid { display: flex; flex-direction: column; gap: 4px; overflow-x: auto; margin-bottom: 12px; }
.presence-grid__header,
.presence-grid__row { display: grid; grid-template-columns: 80px repeat(7, 1fr); gap: 4px; min-width: 520px; }
.presence-grid__day-label { text-align: center; font-size: var(--th-font-meta); font-weight: 500; color: var(--color-text-maxcontrast); padding: 4px 2px; }
.presence-grid__half-label { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); display: flex; align-items: center; }
.presence-grid__cell { min-width: 60px; }

.presence-split-day { width: 100%; min-height: 40px; border: 1px dashed var(--color-border-dark); border-radius: var(--border-radius-small); background: var(--color-background-hover); color: var(--color-text-maxcontrast); cursor: pointer; }
.presence-split-day:focus-visible { outline: 2px solid var(--color-primary-element); }
.my-presence-panel__availability-link { display: inline-block; margin-bottom: 12px; }
.presence-grid__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.presence-grid__unsaved-hint { font-size: var(--th-font-meta); color: var(--color-warning-text); }

.presence-legend__list { display: flex; flex-wrap: wrap; gap: 12px; }
.presence-legend__item { display: flex; align-items: center; gap: 6px; font-size: var(--th-font-meta); }
.presence-legend__swatch { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.presence-legend__swatch--empty { border: 2px dashed var(--color-border-dark); background: transparent; }

.presence-picker-list { list-style: none; margin: 0; padding: 4px 0; }
.presence-picker-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: var(--border-radius-small); cursor: pointer; user-select: none; }
.presence-picker-item:hover { background: var(--color-background-hover); }
/* v3.100.14 WCAG 2.4.7: previously grouped with :hover and set outline: none,
   which silenced the keyboard focus ring entirely. Give focus its own ring. */
.presence-picker-item:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}
.presence-picker-item--selected { background: var(--color-primary-light); }
.presence-picker-item--clear { margin-top: 8px; border-top: 1px solid var(--color-border); border-radius: 0; padding-top: 12px; }
.presence-picker-item__swatch { width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0; }
.presence-picker-item__swatch--empty { border: 2px dashed var(--color-border-dark); background: transparent; }
.presence-picker-item__label { flex: 1; }
.presence-picker-item__check { color: var(--color-primary); }
.presence-picker-location { padding: 12px 12px 4px; display: flex; flex-direction: column; gap: 6px; }
.presence-picker-location__label { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }
.presence-picker-location__select { width: 100%; }
.presence-period { padding: 16px; margin: 0 0 18px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.presence-period__title { font-size: var(--th-font-body); font-weight: 600; margin: 0 0 12px; }
.presence-period__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 12px; }
.presence-period__field { display: flex; flex-direction: column; gap: 4px; font-size: var(--th-font-meta); }
.presence-period__field input, .presence-period__field select { width: 100%; min-height: 38px; color: var(--color-main-text); background: var(--color-main-background); border: 1px solid var(--color-border-dark); border-radius: var(--border-radius-small); padding: 6px; }
.presence-period__note { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); margin-bottom: 12px; }
</style>

