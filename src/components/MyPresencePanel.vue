<template>
    <div class="my-presence-panel">
        <div class="my-presence-panel__header">
            <h2 class="my-presence-panel__title">{{ t('teamhub', 'My Presence') }}</h2>
            <p class="my-presence-panel__desc">
                {{ t('teamhub', 'Set your typical weekly presence below. Changes take effect after you click Save. Use the calendar to override individual dates.') }}
            </p>
        </div>

        <div v-if="loading" class="my-presence-loading">
            <NcLoadingIcon :size="28" />
        </div>
        <div v-else-if="loadError" class="my-presence-error" role="alert">
            {{ loadError }}
        </div>

        <template v-else>
            <!-- Section 1: Week template -->
            <section class="my-presence-section">
                <h3 class="my-presence-section__title">{{ t('teamhub', 'Weekly template') }}</h3>
                <p class="my-presence-section__desc">
                    {{ t('teamhub', 'Your default schedule. Click a cell to change it, then click Save.') }}
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
                </div>

                <div class="presence-grid__actions">
                    <NcButton
                        variant="primary"
                        :disabled="!hasDirty || savingTemplate"
                        @click="saveTemplate">
                        <template #icon>
                            <NcLoadingIcon v-if="savingTemplate" :size="16" />
                            <ContentSaveIcon v-else :size="16" />
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
                <h3 class="my-presence-section__title">{{ t('teamhub', 'Date overrides') }}</h3>
                <p class="my-presence-section__desc">
                    {{ t('teamhub', 'Click a date cell to override your schedule for that day. Holidays (striped) cannot be changed.') }}
                </p>
                <div v-if="loadingSlots" class="my-presence-loading">
                    <NcLoadingIcon :size="22" />
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
                <ul class="presence-picker-list" role="listbox" :aria-label="t('teamhub', 'Select a status')">
                    <li
                        v-for="type in selectableTypes"
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
                        <CheckIcon v-if="picker.currentTypeId === type.id" :size="16" class="presence-picker-item__check" />
                    </li>

                    <li v-if="selectedTypeRequiresLocation" class="presence-picker-location" role="presentation">
                        <label class="presence-picker-location__label">{{ t('teamhub', 'Location (optional)') }}</label>
                        <select v-model.number="picker.roomId" class="presence-picker-location__select" :aria-label="t('teamhub', 'Select a room')">
                            <option :value="null">{{ t('teamhub', '— No specific room —') }}</option>
                            <template v-for="building in locationTree">
                                <optgroup
                                    v-for="floor in building.floors"
                                    :key="'f'+floor.id"
                                    :label="building.name + ' — ' + floor.name">
                                    <option v-for="room in floor.rooms" :key="room.id" :value="room.id">{{ room.name }}</option>
                                </optgroup>
                            </template>
                        </select>
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
import { NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import CheckIcon            from 'vue-material-design-icons/Check.vue'
import ContentSaveIcon      from 'vue-material-design-icons/ContentSave.vue'
import PresenceCell         from './PresenceCell.vue'
import PresenceCalendarView from './PresenceCalendarView.vue'

export default {
    name: 'MyPresencePanel',
    components: { NcButton, NcLoadingIcon, NcDialog, CheckIcon, ContentSaveIcon, PresenceCell, PresenceCalendarView },
    data() {
        return {
            loading: true,
            loadingSlots: true,
            loadError: null,
            savingTemplate: false,
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
        }
    },
    computed: {
        days() {
            const labels = [
                t('teamhub', 'Mon'), t('teamhub', 'Tue'), t('teamhub', 'Wed'),
                t('teamhub', 'Thu'), t('teamhub', 'Fri'), t('teamhub', 'Sat'), t('teamhub', 'Sun'),
            ]
            return labels.map((label, index) => ({ label, index }))
        },
        selectableTypes() {
            return this.types.filter(tp => tp.selectable_by_user)
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
                const [y, m, d] = this.picker.iso.split('-').map(Number)
                const dateStr = new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
                const half = this.picker.overrideHalf === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
                return `${dateStr} — ${half}`
            }
            if (this.picker.day !== null) {
                const half = this.picker.half === 0 ? t('teamhub', 'Morning') : t('teamhub', 'Afternoon')
                return `${this.days[this.picker.day]?.label || ''} ${half}`
            }
            return t('teamhub', 'Select a status')
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

        async loadSlots() {
            this.loadingSlots = true
            try {
                const today = new Date().toISOString().slice(0, 10)
                const d4 = new Date()
                d4.setMonth(d4.getMonth() + 4)
                d4.setDate(0) // last day of 4th month from now
                const endDate = d4.toISOString().slice(0, 10)
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
                const today = new Date().toISOString().slice(0, 10)
                const d4 = new Date()
                d4.setMonth(d4.getMonth() + 4)
                d4.setDate(0)
                const endDate = d4.toISOString().slice(0, 10)
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
                showSuccess(t('teamhub', 'Weekly template saved'))
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
            if (this.picker.mode === 'template') {
                const { day, half, currentTypeId, roomId } = this.picker
                this.draft[`${day}_${half}`] = {
                    day_of_week: day, half_day: half,
                    presence_type_id: currentTypeId,
                    location_room_id: currentTypeId !== null ? roomId : null,
                }
                this.closePicker()
            } else {
                const { iso, overrideHalf, currentTypeId, roomId } = this.picker
                this.closePicker()
                await this.saveOverride(iso, overrideHalf, currentTypeId, roomId)
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
.my-presence-panel__title { font-size: 20px; font-weight: 600; margin: 0 0 6px; }
.my-presence-panel__desc { font-size: 13px; color: var(--color-text-maxcontrast); margin: 0 0 24px; }

.my-presence-loading { display: flex; justify-content: center; padding: 40px; }
.my-presence-error { padding: 12px 16px; background: var(--color-error-background, var(--color-background-hover)); color: var(--color-error-text); border-radius: var(--border-radius); }

.my-presence-section { margin-bottom: 36px; }
.my-presence-section__title { font-size: 16px; font-weight: 600; margin: 0 0 4px; }
.my-presence-section__desc { font-size: 13px; color: var(--color-text-maxcontrast); margin: 0 0 16px; }
.my-presence-section--legend { margin-bottom: 24px; }

.presence-grid { display: flex; flex-direction: column; gap: 4px; overflow-x: auto; margin-bottom: 12px; }
.presence-grid__header,
.presence-grid__row { display: grid; grid-template-columns: 80px repeat(7, 1fr); gap: 4px; min-width: 520px; }
.presence-grid__day-label { text-align: center; font-size: 13px; font-weight: 500; color: var(--color-text-maxcontrast); padding: 4px 2px; }
.presence-grid__half-label { font-size: 12px; color: var(--color-text-maxcontrast); display: flex; align-items: center; }
.presence-grid__cell { min-width: 60px; }

.presence-grid__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.presence-grid__unsaved-hint { font-size: 12px; color: var(--color-warning-text, var(--color-text-maxcontrast)); }

.presence-legend__list { display: flex; flex-wrap: wrap; gap: 12px; }
.presence-legend__item { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.presence-legend__swatch { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.presence-legend__swatch--empty { border: 2px dashed var(--color-border-dark); background: transparent; }

.presence-picker-list { list-style: none; margin: 0; padding: 4px 0; }
.presence-picker-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: var(--border-radius); cursor: pointer; user-select: none; }
.presence-picker-item:hover, .presence-picker-item:focus-visible { background: var(--color-background-hover); outline: none; }
.presence-picker-item--selected { background: var(--color-primary-light); }
.presence-picker-item--clear { margin-top: 8px; border-top: 1px solid var(--color-border); border-radius: 0; padding-top: 12px; }
.presence-picker-item__swatch { width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0; }
.presence-picker-item__swatch--empty { border: 2px dashed var(--color-border-dark); background: transparent; }
.presence-picker-item__label { flex: 1; }
.presence-picker-item__check { color: var(--color-primary); }
.presence-picker-location { padding: 12px 12px 4px; display: flex; flex-direction: column; gap: 6px; }
.presence-picker-location__label { font-size: 12px; color: var(--color-text-maxcontrast); }
.presence-picker-location__select { padding: 6px 10px; border-radius: var(--border-radius); border: 1px solid var(--color-border-dark); background: var(--color-main-background); color: var(--color-main-text); width: 100%; }
</style>
