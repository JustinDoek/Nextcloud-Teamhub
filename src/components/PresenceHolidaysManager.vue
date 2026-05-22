<template>
    <div class="presence-holidays-manager">
        <NcSettingsSection
            :name="t('teamhub', 'Holidays')"
            :description="t('teamhub', 'Public or company holidays. Adding a holiday overwrites everyone\'s entries on that date with the built-in ‘Holiday’ status. Users cannot edit a holiday slot.')">

            <div class="presence-holidays-toolbar">
                <NcButton
                    type="primary"
                    @click="openCreate">
                    <template #icon><PlusIcon :size="18" /></template>
                    {{ t('teamhub', 'Add holiday') }}
                </NcButton>

                <label class="presence-year-picker">
                    <span class="presence-year-picker__label">{{ t('teamhub', 'Year') }}</span>
                    <select
                        v-model.number="year"
                        class="presence-year-picker__select"
                        :aria-label="t('teamhub', 'Filter holidays by year')"
                        @change="load">
                        <option
                            v-for="y in yearOptions"
                            :key="y"
                            :value="y">{{ y }}</option>
                    </select>
                </label>
            </div>

            <div v-if="loading" class="presence-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else-if="error" class="presence-error" role="alert">
                {{ error }}
            </div>
            <div
                v-else-if="holidays.length === 0"
                class="presence-empty">
                {{ t('teamhub', 'No holidays for {year}.', { year }) }}
            </div>
            <ul v-else class="presence-holidays-list" role="list">
                <li
                    v-for="h in holidays"
                    :key="h.id"
                    class="presence-holiday-row">
                    <CalendarStarIcon :size="18" class="presence-holiday-row__icon" />
                    <div class="presence-holiday-row__main">
                        <div class="presence-holiday-row__date">{{ formatDate(h.holiday_date) }}</div>
                        <div class="presence-holiday-row__name">{{ h.name }}</div>
                    </div>
                    <NcButton
                        type="tertiary"
                        :aria-label="t('teamhub', 'Delete holiday')"
                        @click="confirmDelete(h)">
                        <template #icon><DeleteIcon :size="16" /></template>
                    </NcButton>
                </li>
            </ul>
        </NcSettingsSection>

        <!-- ──────────────────────────────────────────────────────────────
             Add dialog — date + name, no preview yet
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="addDialog.open"
            :name="t('teamhub', 'Add holiday')"
            size="small"
            @update:open="closeAddDialog">
            <template #default>
                <div class="presence-form">
                    <label class="presence-date-field">
                        <span>{{ t('teamhub', 'Date') }}</span>
                        <input
                            v-model="addDialog.date"
                            type="date"
                            class="presence-date-input"
                            :aria-label="t('teamhub', 'Holiday date')" />
                    </label>
                    <NcTextField
                        :value.sync="addDialog.name"
                        :label="t('teamhub', 'Name')"
                        :placeholder="t('teamhub', 'e.g. King\'s Day')"
                        :maxlength="128" />
                </div>
            </template>
            <template #actions>
                <NcButton @click="closeAddDialog">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    type="primary"
                    :disabled="!canSubmit || previewing"
                    @click="requestPreview">
                    <template #icon>
                        <NcLoadingIcon v-if="previewing" :size="16" />
                        <ChevronRightIcon v-else :size="16" />
                    </template>
                    {{ t('teamhub', 'Next') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ──────────────────────────────────────────────────────────────
             Preview-confirm dialog — shows "N entries will be overwritten"
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="confirmDialog.open"
            :name="t('teamhub', 'Confirm holiday')"
            size="small"
            @update:open="closeConfirmDialog">
            <template #default>
                <p>
                    {{ t('teamhub', 'Add ‘{name}’ on {date}.', {
                        name: confirmDialog.name,
                        date: formatDate(confirmDialog.date),
                    }) }}
                </p>
                <p
                    v-if="confirmDialog.affectedSlots > 0"
                    class="presence-confirm-warning"
                    aria-live="polite">
                    <!-- TRANSLATORS: shown before committing a holiday that will destructively overwrite user entries -->
                    {{ overwriteWarning }}
                </p>
                <p v-else class="presence-confirm-info" aria-live="polite">
                    {{ t('teamhub', 'No existing entries on this date — nothing will be overwritten.') }}
                </p>
            </template>
            <template #actions>
                <NcButton @click="closeConfirmDialog">
                    {{ t('teamhub', 'Back') }}
                </NcButton>
                <NcButton
                    :type="confirmDialog.affectedSlots > 0 ? 'error' : 'primary'"
                    :disabled="committing"
                    @click="commit">
                    <template #icon>
                        <NcLoadingIcon v-if="committing" :size="16" />
                        <ContentSaveIcon v-else :size="16" />
                    </template>
                    {{ t('teamhub', 'Add holiday') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ──────────────────────────────────────────────────────────────
             Delete confirmation dialog
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="deleteDialog.open"
            :name="t('teamhub', 'Delete holiday')"
            size="small"
            @update:open="closeDeleteDialog">
            <template #default>
                <p>
                    {{ t('teamhub', 'Delete ‘{name}’ on {date}? Any holiday entries on that date will revert to each user\'s default week template.', {
                        name: deleteDialog.name,
                        date: formatDate(deleteDialog.date),
                    }) }}
                </p>
            </template>
            <template #actions>
                <NcButton @click="closeDeleteDialog">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton type="error" @click="executeDelete">
                    {{ t('teamhub', 'Delete') }}
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
import {
    NcSettingsSection, NcButton, NcLoadingIcon, NcTextField, NcDialog,
} from '@nextcloud/vue'
import PlusIcon          from 'vue-material-design-icons/Plus.vue'
import DeleteIcon        from 'vue-material-design-icons/Delete.vue'
import CalendarStarIcon  from 'vue-material-design-icons/CalendarStar.vue'
import ChevronRightIcon  from 'vue-material-design-icons/ChevronRight.vue'
import ContentSaveIcon   from 'vue-material-design-icons/ContentSave.vue'

/**
 * Admin sub-panel: manage admin-locked holidays.
 *
 * Adding a holiday is a two-step flow:
 *   1. Admin enters date+name and clicks Next.
 *   2. App calls POST /presence/holidays/preview to find out how many slot
 *      rows currently sit on that date.
 *   3. Confirmation dialog shows "N entries will be overwritten" and asks
 *      for explicit consent before committing.
 *
 * The preview always returns 0 in B1 (slots table is empty), but the
 * complete flow is wired so the dialog will work correctly the moment B2
 * starts populating slots.
 *
 * Year selector defaults to the current year. The dropdown spans current-2
 * to current+5, which covers every realistic admin use case (back-dating a
 * holiday they forgot to enter, planning a few years out).
 */
export default {
    name: 'PresenceHolidaysManager',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon, NcTextField, NcDialog,
        PlusIcon, DeleteIcon, CalendarStarIcon, ChevronRightIcon, ContentSaveIcon,
    },
    data() {
        return {
            loading: true,
            error: null,
            year: new Date().getFullYear(),
            holidays: [],
            addDialog: { open: false, date: '', name: '' },
            previewing: false,
            confirmDialog: { open: false, date: '', name: '', affectedSlots: 0 },
            committing: false,
            deleteDialog: { open: false, id: null, name: '', date: '' },
        }
    },
    computed: {
        yearOptions() {
            const current = new Date().getFullYear()
            const out = []
            for (let y = current - 2; y <= current + 5; y++) out.push(y)
            return out
        },

        canSubmit() {
            return /^\d{4}-\d{2}-\d{2}$/.test(this.addDialog.date)
                && this.addDialog.name.trim() !== ''
        },

        overwriteWarning() {
            return n(
                'teamhub',
                'This will overwrite {n} existing entry on this date.',
                'This will overwrite {n} existing entries on this date.',
                this.confirmDialog.affectedSlots,
                { n: this.confirmDialog.affectedSlots },
            )
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        t, n,

        formatDate(iso) {
            if (!iso) return ''
            // Render in the user's locale; iso comes as YYYY-MM-DD which
            // Date parses as UTC midnight — formatting that with the user's
            // locale gives the right day for every timezone east of UTC.
            try {
                const [y, m, d] = iso.split('-').map(Number)
                const dt = new Date(y, m - 1, d)
                return dt.toLocaleDateString(undefined, {
                    weekday: 'short',
                    year:    'numeric',
                    month:   'short',
                    day:     'numeric',
                })
            } catch (e) {
                return iso
            }
        },

        async load() {
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/holidays'),
                    { params: { year: this.year } },
                )
                this.holidays = Array.isArray(data) ? data : []
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Failed to load holidays')
            } finally {
                this.loading = false
            }
        },

        // ── Add (preview → confirm → commit) ──────────────────────────

        openCreate() {
            const today = new Date().toISOString().slice(0, 10)
            this.addDialog = { open: true, date: today, name: '' }
        },

        closeAddDialog() {
            this.addDialog = { open: false, date: '', name: '' }
        },

        async requestPreview() {
            if (!this.canSubmit) return
            this.previewing = true
            try {
                const { data } = await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/holidays/preview'),
                    { date: this.addDialog.date },
                )
                this.confirmDialog = {
                    open: true,
                    date: this.addDialog.date,
                    name: this.addDialog.name.trim(),
                    affectedSlots: data?.affectedSlots || 0,
                }
                // Hide the add dialog; confirm dialog takes over.
                this.addDialog.open = false
            } catch (err) {
                showError(err?.response?.data?.error
                    || t('teamhub', 'Failed to check this date'))
            } finally {
                this.previewing = false
            }
        },

        closeConfirmDialog() {
            // Going "back" returns the user to the add dialog with their
            // input preserved so they can change the date or name.
            this.addDialog = {
                open: true,
                date: this.confirmDialog.date,
                name: this.confirmDialog.name,
            }
            this.confirmDialog = { open: false, date: '', name: '', affectedSlots: 0 }
        },

        async commit() {
            this.committing = true
            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/holidays'),
                    { date: this.confirmDialog.date, name: this.confirmDialog.name },
                )
                showSuccess(t('teamhub', 'Holiday added'))
                this.confirmDialog = { open: false, date: '', name: '', affectedSlots: 0 }
                this.addDialog = { open: false, date: '', name: '' }
                await this.load()
            } catch (err) {
                showError(err?.response?.data?.error
                    || t('teamhub', 'Failed to add holiday'))
            } finally {
                this.committing = false
            }
        },

        // ── Delete ────────────────────────────────────────────────────

        confirmDelete(h) {
            this.deleteDialog = {
                open: true,
                id: h.id,
                name: h.name,
                date: h.holiday_date,
            }
        },

        closeDeleteDialog() {
            this.deleteDialog = { open: false, id: null, name: '', date: '' }
        },

        async executeDelete() {
            const { id } = this.deleteDialog
            try {
                await axios.delete(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/holidays/{id}', { id }),
                )
                showSuccess(t('teamhub', 'Holiday deleted'))
                this.closeDeleteDialog()
                await this.load()
            } catch (err) {
                showError(err?.response?.data?.error
                    || t('teamhub', 'Failed to delete holiday'))
                this.closeDeleteDialog()
            }
        },
    },
}
</script>

<style scoped>
.presence-loading {
    display: flex;
    justify-content: center;
    padding: 20px;
}
.presence-error {
    color: var(--color-error-text);
    padding: 12px 16px;
    background: var(--color-error-background, var(--color-background-hover));
    border-radius: var(--border-radius);
    margin-bottom: 12px;
}
.presence-empty {
    padding: 20px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    border: 1px dashed var(--color-border);
    border-radius: var(--border-radius);
}

.presence-holidays-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.presence-year-picker {
    display: flex;
    align-items: center;
    gap: 8px;
}
.presence-year-picker__label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}
.presence-year-picker__select {
    padding: 4px 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
}

.presence-holidays-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.presence-holiday-row {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
}
.presence-holiday-row__icon {
    color: var(--color-text-maxcontrast);
}
.presence-holiday-row__main {
    min-width: 0;
}
.presence-holiday-row__date {
    font-weight: 500;
}
.presence-holiday-row__name {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.presence-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 8px 0;
}
.presence-date-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.presence-date-input {
    padding: 6px 10px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
}

.presence-confirm-warning {
    margin: 12px 0 0 0;
    padding: 8px 12px;
    border-radius: var(--border-radius);
    background: var(--color-warning-background, var(--color-background-hover));
    color: var(--color-warning-text, var(--color-main-text));
}
.presence-confirm-info {
    margin: 12px 0 0 0;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}
</style>
