<template>
    <div class="presence-types-manager">
        <NcSettingsSection
            :name="t('teamhub', 'Status types')"
            :description="t('teamhub', 'The vocabulary your organisation uses for presence — what people can pick to describe where they are. Built-in types cannot be deleted but their label, icon, and colour can be customised. Drag rows to reorder.')">

            <div v-if="loading" class="presence-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else-if="error" class="presence-error" role="alert">
                {{ error }}
            </div>
            <template v-else>
                <!-- TRANSLATORS: button to open the dialog that creates a new presence status type -->
                <NcButton
                    variant="primary"
                    class="presence-add-btn"
                    @click="openCreate">
                    <template #icon><PlusIcon :size="18" /></template>
                    {{ t('teamhub', 'Add status type') }}
                </NcButton>

                <ul class="presence-types-list" role="list">
                    <li
                        v-for="(type, index) in types"
                        :key="type.id"
                        class="presence-type-row"
                        :class="{ 'presence-type-row--builtin': type.is_builtin }">
                        <!-- Sort handle -->
                        <div class="presence-type-row__sort">
                            <NcButton
                                variant="tertiary-no-background"
                                :aria-label="t('teamhub', 'Move up')"
                                :disabled="index === 0"
                                @click="moveUp(index)">
                                <template #icon><ChevronUpIcon :size="16" /></template>
                            </NcButton>
                            <NcButton
                                variant="tertiary-no-background"
                                :aria-label="t('teamhub', 'Move down')"
                                :disabled="index === types.length - 1"
                                @click="moveDown(index)">
                                <template #icon><ChevronDownIcon :size="16" /></template>
                            </NcButton>
                        </div>

                        <!-- Swatch -->
                        <div
                            class="presence-type-row__swatch"
                            :style="{ background: type.color || 'transparent', borderColor: type.color || 'var(--color-border-dark)' }"
                            aria-hidden="true"></div>

                        <!-- Label + meta -->
                        <div class="presence-type-row__main">
                            <div class="presence-type-row__label">
                                <span>{{ type.label }}</span>
                                <span
                                    v-if="type.is_builtin"
                                    class="presence-type-row__badge"
                                    :title="t('teamhub', 'Built-in status type')">
                                    <LockIcon :size="12" />
                                    {{ t('teamhub', 'Built-in') }}
                                </span>
                            </div>
                            <div class="presence-type-row__meta">
                                <span v-if="type.requires_location">{{ t('teamhub', 'Requires location') }}</span>
                                <span v-if="type.is_busy">{{ t('teamhub', 'Busy') }}</span>
                                <span v-else>{{ t('teamhub', 'Free') }}</span>
                                <span v-if="!type.selectable_by_user">
                                    {{ t('teamhub', 'Not user-selectable') }}
                                </span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="presence-type-row__actions">
                            <NcButton
                                variant="tertiary"
                                :aria-label="t('teamhub', 'Edit status type')"
                                @click="openEdit(type)">
                                <template #icon><PencilIcon :size="16" /></template>
                            </NcButton>
                            <NcButton
                                variant="tertiary"
                                :aria-label="t('teamhub', 'Delete status type')"
                                :disabled="type.is_builtin"
                                :title="type.is_builtin ? t('teamhub', 'Built-in status types cannot be deleted') : ''"
                                @click="confirmDelete(type)">
                                <template #icon><DeleteIcon :size="16" /></template>
                            </NcButton>
                        </div>
                    </li>
                </ul>
            </template>
        </NcSettingsSection>

        <!-- ──────────────────────────────────────────────────────────────
             Create / edit dialog
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="dialog.open"
            :name="dialog.id ? t('teamhub', 'Edit status type') : t('teamhub', 'Add status type')"
            size="normal"
            @update:open="closeDialog">
            <template #default>
                <div class="presence-form">
                    <NcTextField
                        v-model="dialog.label"
                        :label="t('teamhub', 'Label')"
                        :placeholder="t('teamhub', 'e.g. Client site')"
                        :maxlength="128" />
                    <NcTextField
                        v-model="dialog.icon"
                        :label="t('teamhub', 'Material Design icon name')"
                        placeholder="OfficeBuilding"
                        :maxlength="64" />
                    <NcTextField
                        v-model="dialog.color"
                        :label="t('teamhub', 'Colour (hex, e.g. #1976D2)')"
                        placeholder="#1976D2"
                        :maxlength="7" />

                    <NcCheckboxRadioSwitch
                        v-model="dialog.requiresLocation"
                        type="switch"
                        :disabled="dialog.builtin">
                        {{ t('teamhub', 'Requires a location') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="dialog.isBusy"
                        type="switch"
                        :disabled="dialog.builtin">
                        {{ t('teamhub', 'Counts as busy on the calendar') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="dialog.selectableByUser"
                        type="switch"
                        :disabled="dialog.builtin">
                        {{ t('teamhub', 'Users can pick this themselves') }}
                    </NcCheckboxRadioSwitch>

                    <p v-if="dialog.builtin" class="presence-form__hint">
                        {{ t('teamhub', 'Structural flags cannot be changed on built-in status types.') }}
                    </p>
                </div>
            </template>
            <template #actions>
                <NcButton @click="closeDialog">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="!dialog.label.trim() || saving"
                    @click="save">
                    <template #icon>
                        <NcLoadingIcon v-if="saving" :size="16" />
                        <ContentSaveIcon v-else :size="16" />
                    </template>
                    {{ t('teamhub', 'Save') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ──────────────────────────────────────────────────────────────
             Delete confirmation dialog
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="deleteDialog.open"
            :name="t('teamhub', 'Delete status type')"
            size="small"
            @update:open="closeDeleteDialog">
            <template #default>
                <p>
                    {{ t('teamhub', 'Delete the status type {label}? This cannot be undone.', { label: deleteDialog.label }) }}
                </p>
            </template>
            <template #actions>
                <NcButton @click="closeDeleteDialog">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" @click="executeDelete">
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
    NcSettingsSection, NcButton, NcLoadingIcon, NcTextField,
    NcCheckboxRadioSwitch, NcDialog,
} from '@nextcloud/vue'
import PlusIcon          from 'vue-material-design-icons/Plus.vue'
import PencilIcon        from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon        from 'vue-material-design-icons/Delete.vue'
import LockIcon          from 'vue-material-design-icons/Lock.vue'
import ChevronUpIcon     from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon   from 'vue-material-design-icons/ChevronDown.vue'
import ContentSaveIcon   from 'vue-material-design-icons/ContentSave.vue'

/**
 * Admin sub-panel: manage presence status types.
 *
 * Built-in types (is_builtin === true) cannot be deleted and have their
 * structural flags (requires_location, is_busy, selectable_by_user) locked.
 * Their label/icon/color can still be customised — branding the catalogue
 * (e.g. "Office" → "HQ") is a common use case.
 *
 * Reorder uses ChevronUp/ChevronDown buttons rather than drag-and-drop:
 * keyboard-accessible by default and avoids pulling a drag library for a
 * surface that will rarely have more than a dozen rows.
 */
export default {
    name: 'PresenceTypesManager',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon, NcTextField,
        NcCheckboxRadioSwitch, NcDialog,
        PlusIcon, PencilIcon, DeleteIcon, LockIcon,
        ChevronUpIcon, ChevronDownIcon, ContentSaveIcon,
    },
    data() {
        return {
            loading: true,
            error: null,
            saving: false,
            types: [],
            dialog: this.emptyDialog(),
            deleteDialog: { open: false, id: null, label: '' },
        }
    },
    mounted() {
        this.load()
    },
    methods: {
        t, n,

        emptyDialog() {
            return {
                open: false,
                id: null,
                builtin: false,
                label: '',
                icon: '',
                color: '',
                requiresLocation: false,
                isBusy: true,
                selectableByUser: true,
            }
        },

        async load() {
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/types'),
                )
                this.types = Array.isArray(data) ? data : []
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Failed to load status types')
            } finally {
                this.loading = false
            }
        },

        // ── Create / edit ─────────────────────────────────────────────

        openCreate() {
            this.dialog = this.emptyDialog()
            this.dialog.open = true
        },

        openEdit(type) {
            this.dialog = {
                open: true,
                id: type.id,
                builtin: type.is_builtin,
                label: type.label,
                icon: type.icon,
                color: type.color,
                requiresLocation: type.requires_location,
                isBusy: type.is_busy,
                selectableByUser: type.selectable_by_user,
            }
        },

        closeDialog() {
            this.dialog = this.emptyDialog()
        },

        async save() {
            const label = this.dialog.label.trim()
            if (!label) return

            this.saving = true
            try {
                const payload = {
                    label,
                    icon:  this.dialog.icon.trim(),
                    color: this.dialog.color.trim(),
                    sort_order: this.dialog.id
                        ? this.types.find(t => t.id === this.dialog.id)?.sort_order || 0
                        : (this.types.length + 1) * 10,
                }
                if (!this.dialog.builtin) {
                    payload.requires_location  = this.dialog.requiresLocation ? 1 : 0
                    payload.is_busy            = this.dialog.isBusy ? 1 : 0
                    payload.selectable_by_user = this.dialog.selectableByUser ? 1 : 0
                }

                if (this.dialog.id) {
                    await axios.put(
                        generateUrl('/apps/teamhub/api/v1/admin/presence/types/{id}', { id: this.dialog.id }),
                        payload,
                    )
                    showSuccess(t('teamhub', 'Status type updated'))
                } else {
                    await axios.post(
                        generateUrl('/apps/teamhub/api/v1/admin/presence/types'),
                        payload,
                    )
                    showSuccess(t('teamhub', 'Status type created'))
                }
                this.closeDialog()
                await this.load()
            } catch (err) {
                showError(err?.response?.data?.error
                    || t('teamhub', 'Failed to save status type'))
            } finally {
                this.saving = false
            }
        },

        // ── Delete ────────────────────────────────────────────────────

        confirmDelete(type) {
            if (type.is_builtin) return
            this.deleteDialog = { open: true, id: type.id, label: type.label }
        },

        closeDeleteDialog() {
            this.deleteDialog = { open: false, id: null, label: '' }
        },

        async executeDelete() {
            const { id, label } = this.deleteDialog
            try {
                await axios.delete(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/types/{id}', { id }),
                )
                showSuccess(t('teamhub', '{label} deleted', { label }))
                this.closeDeleteDialog()
                await this.load()
            } catch (err) {
                const data = err?.response?.data
                if (data?.affectedCount > 0) {
                    showError(n(
                        'teamhub',
                        'Cannot delete: status type is in use by {n} entry',
                        'Cannot delete: status type is in use by {n} entries',
                        data.affectedCount,
                        { n: data.affectedCount },
                    ))
                } else {
                    showError(data?.error
                        || t('teamhub', 'Failed to delete status type'))
                }
                this.closeDeleteDialog()
            }
        },

        // ── Reorder ───────────────────────────────────────────────────

        async moveUp(index) {
            if (index === 0) return
            await this.swap(index, index - 1)
        },

        async moveDown(index) {
            if (index >= this.types.length - 1) return
            await this.swap(index, index + 1)
        },

        async swap(a, b) {
            // Optimistic local swap so the UI feels responsive.
            const arr = this.types.slice()
            const tmp = arr[a]
            arr[a] = arr[b]
            arr[b] = tmp
            // Recompute sort_order in 10-step increments so future inserts
            // can land between rows without renumbering everything.
            arr.forEach((row, i) => { row.sort_order = (i + 1) * 10 })
            this.types = arr

            // Persist only the two rows whose sort_order actually changed.
            try {
                await Promise.all([
                    this.persistSortOrder(arr[a]),
                    this.persistSortOrder(arr[b]),
                ])
            } catch (err) {
                showError(t('teamhub', 'Failed to save new order: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
                // Roll back by reloading from the server.
                await this.load()
            }
        },

        async persistSortOrder(row) {
            await axios.put(
                generateUrl('/apps/teamhub/api/v1/admin/presence/types/{id}', { id: row.id }),
                { sort_order: row.sort_order },
            )
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

.presence-add-btn {
    margin-bottom: 12px;
}

.presence-types-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.presence-type-row {
    display: grid;
    grid-template-columns: auto auto 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 8px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
}
.presence-type-row--builtin {
    background: var(--color-background-hover);
}

.presence-type-row__sort {
    display: flex;
    flex-direction: column;
}

.presence-type-row__swatch {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid;
}

.presence-type-row__main {
    min-width: 0;
}
.presence-type-row__label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}
.presence-type-row__badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: normal;
    padding: 2px 6px;
    border-radius: var(--border-radius-pill, 12px);
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
.presence-type-row__meta {
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
}

.presence-type-row__actions {
    display: flex;
    gap: 4px;
}

.presence-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 8px 0;
}
.presence-form__hint {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin: 0;
}
</style>
