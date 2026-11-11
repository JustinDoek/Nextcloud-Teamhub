<template>
    <div class="shift-types-manager">
        <NcSettingsSection
            :name="t('teamhub', 'Shift types')"
            :description="t('teamhub', 'The shifts your organisation works, and the hours each one covers. A shift whose end time is earlier than its start runs into the next day. Built-in shifts can be renamed, retimed and switched off, but not deleted.')">

            <div v-if="loading" class="shift-loading">
                <NcLoadingIcon :size="ICON_LARGE" />
            </div>
            <NcNoteCard v-else-if="error" type="error">
                {{ error }}
                <template #action>
                    <NcButton variant="secondary" @click="load">{{ t('teamhub', 'Try again') }}</NcButton>
                </template>
            </NcNoteCard>

            <template v-else>
                <NcButton variant="primary" class="shift-add-btn" @click="openCreate">
                    <template #icon><PlusIcon :size="ICON_BODY" /></template>
                    {{ t('teamhub', 'Add shift type') }}
                </NcButton>

                <ul class="shift-type-list" role="list">
                    <li
                        v-for="type in types"
                        :key="type.id"
                        class="shift-type-row"
                        :class="{ 'shift-type-row--off': !type.isActive }">

                        <span
                            class="shift-type-row__swatch"
                            :style="type.color ? { backgroundColor: type.color } : null"></span>

                        <span class="shift-type-row__name">{{ type.label }}</span>

                        <span class="shift-type-row__time">{{ timeLabel(type) }}</span>

                        <span class="shift-type-row__length">{{ lengthLabel(type) }}</span>

                        <span v-if="type.isBuiltin" class="shift-type-row__badge">
                            {{ t('teamhub', 'Built in') }}
                        </span>
                        <span v-if="!type.isActive" class="shift-type-row__badge shift-type-row__badge--off">
                            {{ t('teamhub', 'Switched off') }}
                        </span>

                        <NcButton
                            variant="tertiary"
                            :aria-label="editLabel(type)"
                            :title="editLabel(type)"
                            @click="openEdit(type)">
                            <template #icon><PencilIcon :size="ICON_BODY" /></template>
                        </NcButton>
                        <NcButton
                            v-if="!type.isBuiltin"
                            variant="tertiary"
                            :aria-label="deleteLabel(type)"
                            :title="deleteLabel(type)"
                            @click="confirmDelete(type)">
                            <template #icon><DeleteIcon :size="ICON_BODY" /></template>
                        </NcButton>
                    </li>
                </ul>
            </template>
        </NcSettingsSection>

        <NcDialog
            v-if="dialog.open"
            :name="dialog.id ? t('teamhub', 'Edit shift type') : t('teamhub', 'Add shift type')"
            :open="dialog.open"
            @update:open="dialog.open = $event">

            <div class="shift-dialog">
                <NcNoteCard v-if="dialog.error" type="error">{{ dialog.error }}</NcNoteCard>

                <NcTextField v-model="dialog.label" :label="t('teamhub', 'Name')" :maxlength="128" />

                <div class="shift-dialog__times">
                    <div class="shift-dialog__field">
                        <label :for="'shift-start'">{{ t('teamhub', 'Starts') }}</label>
                        <!-- Native time input: that is Nextcloud's own
                             recommendation for a time, and `/ui-standards`
                             lists it as a documented carve-out. -->
                        <input id="shift-start" v-model="dialog.startTime" type="time" required>
                    </div>
                    <div class="shift-dialog__field">
                        <label :for="'shift-end'">{{ t('teamhub', 'Ends') }}</label>
                        <input id="shift-end" v-model="dialog.endTime" type="time" required>
                    </div>
                </div>

                <p v-if="crossesMidnight" class="shift-dialog__hint">
                    {{ t('teamhub', 'This shift ends the next day.') }}
                </p>

                <div class="shift-dialog__field">
                    <label :for="'shift-colour'">{{ t('teamhub', 'Colour') }}</label>
                    <input id="shift-colour" v-model="dialog.color" type="color">
                </div>

                <NcCheckboxRadioSwitch
                    :model-value="dialog.isActive"
                    type="switch"
                    @update:model-value="dialog.isActive = $event">
                    {{ t('teamhub', 'Teams can roster this shift') }}
                </NcCheckboxRadioSwitch>
            </div>

            <template #actions>
                <NcButton variant="tertiary" @click="dialog.open = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="saving || !dialog.label" @click="save">
                    {{ t('teamhub', 'Save') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import {
    NcButton, NcLoadingIcon, NcSettingsSection, NcDialog, NcTextField,
    NcCheckboxRadioSwitch, NcNoteCard,
} from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { ICON_BODY, ICON_LARGE } from '../constants/uiTokens.js'

/**
 * Shift types, for the Nextcloud administrator (v4.13.68, DESIGN §2.234).
 *
 * The vocabulary a team's roster week template is written from:
 * Admin → TeamHub → People → Shift types.
 *
 * v4.14.4 — nothing on this screen consumes the list any more, so the
 * `changed` event this panel used to emit is gone with it. It existed to
 * remount the Shifts panel that sat underneath, which held an instance-wide
 * weekly pattern; the week belongs to the team and is written in Manage team,
 * Module settings (DESIGN §2.247). The template editor there fetches the
 * types with the templates, so it already sees an edit made here.
 *
 * A built-in has no delete control, because the seed is insert-when-absent
 * and a deleted one would reappear at the next upgrade looking like a bug.
 * Switching it off is the durable way to say the organisation does not work
 * nights, and the server refuses the delete as well.
 */
export default {
    name: 'ShiftTypesManager',

    components: {
        NcButton, NcLoadingIcon, NcSettingsSection, NcDialog, NcTextField,
        NcCheckboxRadioSwitch, NcNoteCard,
        PlusIcon, PencilIcon, DeleteIcon,
    },


    data() {
        return {
            ICON_BODY,
            ICON_LARGE,
            loading: false,
            saving: false,
            error: null,
            types: [],
            dialog: {
                open: false, id: null, label: '', startTime: '09:00', endTime: '17:00',
                color: '#0082C9', icon: '', isActive: true, error: null,
            },
        }
    },

    computed: {
        /** An end at or before the start means the shift runs past midnight. */
        crossesMidnight() {
            const start = this.minutes(this.dialog.startTime)
            const end = this.minutes(this.dialog.endTime)
            if (start === null || end === null) return false
            return end <= start
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t, n,

        minutes(hhmm) {
            const m = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(hhmm || '')
            return m ? (Number(m[1]) * 60) + Number(m[2]) : null
        },

        timeLabel(type) {
            return type.endsNextDay
                ? t('teamhub', '{start} to {end} the next day', { start: type.startTime, end: type.endTime })
                : `${type.startTime}-${type.endTime}`
        },

        lengthLabel(type) {
            return t('teamhub', '{hours} hours', { hours: type.lengthHours })
        },

        editLabel(type) {
            return t('teamhub', 'Edit {name}', { name: type.label })
        },

        deleteLabel(type) {
            return t('teamhub', 'Delete {name}', { name: type.label })
        },

        async load() {
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/shift-types'))
                this.types = data.types || []
            } catch (err) {
                this.error = err?.response?.data?.error || t('teamhub', 'Could not load the shift types.')
            } finally {
                this.loading = false
            }
        },

        openCreate() {
            this.dialog = {
                open: true, id: null, label: '', startTime: '09:00', endTime: '17:00',
                color: '#0082C9', icon: '', isActive: true, error: null,
            }
        },

        openEdit(type) {
            this.dialog = {
                open: true,
                id: type.id,
                label: type.label,
                startTime: type.startTime,
                endTime: type.endTime,
                color: type.color || '#0082C9',
                icon: type.icon || '',
                isActive: type.isActive,
                error: null,
            }
        },

        async save() {
            this.saving = true
            this.dialog.error = null
            const payload = {
                label: this.dialog.label,
                startTime: this.dialog.startTime,
                endTime: this.dialog.endTime,
                color: this.dialog.color,
                icon: this.dialog.icon,
                isActive: this.dialog.isActive,
            }
            try {
                if (this.dialog.id) {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/admin/shift-types/${this.dialog.id}`),
                        payload,
                    )
                } else {
                    await axios.post(generateUrl('/apps/teamhub/api/v1/admin/shift-types'), payload)
                }
                this.dialog.open = false
                await this.load()
            } catch (err) {
                this.dialog.error = err?.response?.data?.error
                    || t('teamhub', 'Could not save the shift type.')
            } finally {
                this.saving = false
            }
        },

        async confirmDelete(type) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(t('teamhub', 'Delete the shift type {name}?', { name: type.label }))) {
                return
            }
            try {
                await axios.delete(generateUrl(`/apps/teamhub/api/v1/admin/shift-types/${type.id}`))
                await this.load()
            } catch (err) {
                this.error = err?.response?.data?.error || t('teamhub', 'Could not delete the shift type.')
            }
        },
    },
}
</script>

<style scoped>
.shift-loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-lg);
}

.shift-add-btn {
    margin-block-end: var(--th-space-md);
}

.shift-type-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.shift-type-row {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) 0;
    border-block-end: 1px solid var(--color-border);
}

.shift-type-row--off {
    opacity: 0.6;
}

/* Circle-locked: six properties so neither content nor the flex parent can
   stretch it into an oval. */
.shift-type-row__swatch {
    display: inline-block;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 12px;
    height: 12px;
    min-width: 12px;
    min-height: 12px;
    max-width: 12px;
    max-height: 12px;
    padding: 0;
    border-radius: 50%;
    background: var(--color-text-maxcontrast);
}

.shift-type-row__name {
    font-weight: 500;
}

.shift-type-row__time,
.shift-type-row__length {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.shift-type-row__length {
    margin-inline-end: auto;
}

.shift-type-row__badge {
    padding-inline: var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    white-space: nowrap;
}

.shift-type-row__badge--off {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.shift-dialog {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-sm) 0;
    min-width: 300px;
}

.shift-dialog__times {
    display: flex;
    gap: var(--th-space-md);
}

.shift-dialog__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
}

.shift-dialog__field label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.shift-dialog__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}
</style>
