<template>
    <div class="presence-locations-manager">
        <NcSettingsSection
            :name="t('teamhub', 'Locations')"
            :description="t('teamhub', 'The places people can pick when they set a presence status that requires a location. Organised as a tree of buildings, floors, and rooms.')">

            <div v-if="loading" class="presence-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else-if="error" class="presence-error" role="alert">
                {{ error }}
            </div>
            <template v-else>
                <NcButton
                    type="primary"
                    class="presence-add-btn"
                    @click="openCreate('building')">
                    <template #icon><PlusIcon :size="18" /></template>
                    {{ t('teamhub', 'Add building') }}
                </NcButton>

                <div
                    v-if="buildings.length === 0"
                    class="presence-empty">
                    {{ t('teamhub', 'No buildings yet. Add a building to get started.') }}
                </div>

                <ul v-else class="presence-tree" role="tree">
                    <li
                        v-for="b in buildings"
                        :key="'b'+b.id"
                        class="presence-node presence-node--building"
                        role="treeitem"
                        :aria-expanded="expanded['b'+b.id] !== false">

                        <div class="presence-node__row">
                            <NcButton
                                type="tertiary-no-background"
                                :aria-label="expanded['b'+b.id] !== false
                                    ? t('teamhub', 'Collapse')
                                    : t('teamhub', 'Expand')"
                                @click="toggle('b'+b.id)">
                                <template #icon>
                                    <ChevronDownIcon v-if="expanded['b'+b.id] !== false" :size="16" />
                                    <ChevronRightIcon v-else :size="16" />
                                </template>
                            </NcButton>
                            <OfficeBuildingIcon :size="18" class="presence-node__icon" />
                            <div class="presence-node__label">
                                <strong>{{ b.name }}</strong>
                                <span v-if="b.address" class="presence-node__sub">{{ b.address }}</span>
                            </div>
                            <div class="presence-node__actions">
                                <NcButton
                                    type="tertiary"
                                    :aria-label="t('teamhub', 'Add floor')"
                                    @click="openCreate('floor', { building_id: b.id })">
                                    <template #icon><PlusIcon :size="16" /></template>
                                </NcButton>
                                <NcButton
                                    type="tertiary"
                                    :aria-label="t('teamhub', 'Edit building')"
                                    @click="openEdit('building', b)">
                                    <template #icon><PencilIcon :size="16" /></template>
                                </NcButton>
                                <NcButton
                                    type="tertiary"
                                    :aria-label="t('teamhub', 'Delete building')"
                                    @click="confirmDelete('building', b)">
                                    <template #icon><DeleteIcon :size="16" /></template>
                                </NcButton>
                            </div>
                        </div>

                        <ul
                            v-if="expanded['b'+b.id] !== false"
                            class="presence-tree presence-tree--nested"
                            role="group">
                            <li
                                v-for="f in b.floors"
                                :key="'f'+f.id"
                                class="presence-node presence-node--floor"
                                role="treeitem"
                                :aria-expanded="expanded['f'+f.id] !== false">

                                <div class="presence-node__row">
                                    <NcButton
                                        type="tertiary-no-background"
                                        :aria-label="expanded['f'+f.id] !== false
                                            ? t('teamhub', 'Collapse')
                                            : t('teamhub', 'Expand')"
                                        @click="toggle('f'+f.id)">
                                        <template #icon>
                                            <ChevronDownIcon v-if="expanded['f'+f.id] !== false" :size="16" />
                                            <ChevronRightIcon v-else :size="16" />
                                        </template>
                                    </NcButton>
                                    <LayersOutlineIcon :size="16" class="presence-node__icon" />
                                    <div class="presence-node__label">
                                        <span>{{ f.name }}</span>
                                    </div>
                                    <div class="presence-node__actions">
                                        <NcButton
                                            type="tertiary"
                                            :aria-label="t('teamhub', 'Add room')"
                                            @click="openCreate('room', { floor_id: f.id })">
                                            <template #icon><PlusIcon :size="16" /></template>
                                        </NcButton>
                                        <NcButton
                                            type="tertiary"
                                            :aria-label="t('teamhub', 'Edit floor')"
                                            @click="openEdit('floor', f)">
                                            <template #icon><PencilIcon :size="16" /></template>
                                        </NcButton>
                                        <NcButton
                                            type="tertiary"
                                            :aria-label="t('teamhub', 'Delete floor')"
                                            @click="confirmDelete('floor', f)">
                                            <template #icon><DeleteIcon :size="16" /></template>
                                        </NcButton>
                                    </div>
                                </div>

                                <ul
                                    v-if="expanded['f'+f.id] !== false && f.rooms.length > 0"
                                    class="presence-tree presence-tree--nested"
                                    role="group">
                                    <li
                                        v-for="r in f.rooms"
                                        :key="'r'+r.id"
                                        class="presence-node presence-node--room"
                                        role="treeitem">

                                        <div class="presence-node__row">
                                            <span class="presence-node__indent" aria-hidden="true"></span>
                                            <DoorClosedIcon :size="16" class="presence-node__icon" />
                                            <div class="presence-node__label">
                                                <span>{{ r.name }}</span>
                                            </div>
                                            <div class="presence-node__actions">
                                                <NcButton
                                                    type="tertiary"
                                                    :aria-label="t('teamhub', 'Edit room')"
                                                    @click="openEdit('room', r)">
                                                    <template #icon><PencilIcon :size="16" /></template>
                                                </NcButton>
                                                <NcButton
                                                    type="tertiary"
                                                    :aria-label="t('teamhub', 'Delete room')"
                                                    @click="confirmDelete('room', r)">
                                                    <template #icon><DeleteIcon :size="16" /></template>
                                                </NcButton>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                                <div
                                    v-else-if="expanded['f'+f.id] !== false && f.rooms.length === 0"
                                    class="presence-empty-inline">
                                    {{ t('teamhub', 'No rooms') }}
                                </div>
                            </li>

                            <li
                                v-if="b.floors.length === 0"
                                class="presence-empty-inline">
                                {{ t('teamhub', 'No floors') }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </template>
        </NcSettingsSection>

        <!-- ──────────────────────────────────────────────────────────────
             Create / edit dialog
             ────────────────────────────────────────────────────────────── -->
        <NcDialog
            v-if="dialog.open"
            :name="dialogTitle"
            size="small"
            @update:open="closeDialog">
            <template #default>
                <div class="presence-form">
                    <NcTextField
                        :value.sync="dialog.name"
                        :label="t('teamhub', 'Name')"
                        :maxlength="255"
                        :placeholder="namePlaceholder" />
                    <NcTextField
                        v-if="dialog.kind === 'building'"
                        :value.sync="dialog.address"
                        :label="t('teamhub', 'Address (optional)')"
                        :maxlength="255" />
                </div>
            </template>
            <template #actions>
                <NcButton @click="closeDialog">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    type="primary"
                    :disabled="!dialog.name.trim() || saving"
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
            :name="t('teamhub', 'Confirm delete')"
            size="small"
            @update:open="closeDeleteDialog">
            <template #default>
                <p v-if="deleteDialog.subtreeCount > 0">
                    <!-- TRANSLATORS: warning shown before deleting a building or floor that contains other items -->
                    {{ deleteSubtreeMessage }}
                </p>
                <p v-else>
                    {{ t('teamhub', 'Delete {label}?', { label: deleteDialog.label }) }}
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
import PlusIcon            from 'vue-material-design-icons/Plus.vue'
import PencilIcon          from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon          from 'vue-material-design-icons/Delete.vue'
import ChevronDownIcon     from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon    from 'vue-material-design-icons/ChevronRight.vue'
import OfficeBuildingIcon  from 'vue-material-design-icons/OfficeBuilding.vue'
import LayersOutlineIcon   from 'vue-material-design-icons/LayersOutline.vue'
import DoorClosedIcon      from 'vue-material-design-icons/DoorClosed.vue'
import ContentSaveIcon     from 'vue-material-design-icons/ContentSave.vue'

/**
 * Admin sub-panel: manage the building → floor → room location hierarchy.
 *
 * The tree expands by default — common case is a small org with a handful
 * of rooms total, and collapsing-by-default would make the panel look empty.
 *
 * Delete shows a subtree-count warning for buildings and floors. The actual
 * "in use by N slot rows" rejection happens server-side; the dialog only
 * surfaces the count returned in the 409 response.
 */
export default {
    name: 'PresenceLocationsManager',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon, NcTextField, NcDialog,
        PlusIcon, PencilIcon, DeleteIcon, ChevronDownIcon, ChevronRightIcon,
        OfficeBuildingIcon, LayersOutlineIcon, DoorClosedIcon, ContentSaveIcon,
    },
    data() {
        return {
            loading: true,
            error: null,
            saving: false,
            buildings: [],
            expanded: {},
            dialog: this.emptyDialog(),
            deleteDialog: { open: false, kind: '', id: null, label: '', subtreeCount: 0 },
        }
    },
    computed: {
        dialogTitle() {
            const t = this.t.bind(this)
            if (this.dialog.kind === 'building') {
                return this.dialog.id ? t('teamhub', 'Edit building') : t('teamhub', 'Add building')
            }
            if (this.dialog.kind === 'floor') {
                return this.dialog.id ? t('teamhub', 'Edit floor') : t('teamhub', 'Add floor')
            }
            return this.dialog.id ? t('teamhub', 'Edit room') : t('teamhub', 'Add room')
        },

        namePlaceholder() {
            const t = this.t.bind(this)
            if (this.dialog.kind === 'building') return t('teamhub', 'e.g. HQ')
            if (this.dialog.kind === 'floor')    return t('teamhub', 'e.g. 3rd floor')
            return t('teamhub', 'e.g. Conference room A')
        },

        /**
         * Composed warning text shown when deleting a building or floor
         * that contains other items. Plural form via n() on each segment.
         */
        deleteSubtreeMessage() {
            const d = this.deleteDialog
            if (d.kind === 'building') {
                const floors = n('teamhub',
                    'This building has {n} floor',
                    'This building has {n} floors',
                    d.subtreeCount,
                    { n: d.subtreeCount })
                return `${floors} ${t('teamhub', 'Continue?')}`
            }
            if (d.kind === 'floor') {
                const rooms = n('teamhub',
                    'This floor has {n} room',
                    'This floor has {n} rooms',
                    d.subtreeCount,
                    { n: d.subtreeCount })
                return `${rooms} ${t('teamhub', 'Continue?')}`
            }
            return t('teamhub', 'Delete {label}?', { label: d.label })
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        t, n,

        emptyDialog() {
            return {
                open: false,
                kind: '',     // 'building' | 'floor' | 'room'
                id: null,
                name: '',
                address: '',
                building_id: null,
                floor_id: null,
            }
        },

        toggle(key) {
            this.$set(this.expanded, key, !(this.expanded[key] !== false))
        },

        async load() {
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/presence/locations'),
                )
                this.buildings = Array.isArray(data) ? data : []
            } catch (err) {
                this.error = err?.response?.data?.error
                    || t('teamhub', 'Failed to load locations')
            } finally {
                this.loading = false
            }
        },

        // ── Create / edit ─────────────────────────────────────────────

        openCreate(kind, parent = {}) {
            this.dialog = this.emptyDialog()
            this.dialog.kind = kind
            this.dialog.open = true
            if (kind === 'floor')  this.dialog.building_id = parent.building_id
            if (kind === 'room')   this.dialog.floor_id    = parent.floor_id
        },

        openEdit(kind, item) {
            this.dialog = {
                open: true,
                kind,
                id: item.id,
                name: item.name || '',
                address: item.address || '',
                building_id: item.building_id || null,
                floor_id: item.floor_id || null,
            }
        },

        closeDialog() {
            this.dialog = this.emptyDialog()
        },

        async save() {
            const name = this.dialog.name.trim()
            if (!name) return

            this.saving = true
            try {
                const { kind, id } = this.dialog
                if (id) {
                    // Update
                    const payload = { name }
                    if (kind === 'building') payload.address = this.dialog.address.trim() || null
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/admin/presence/${this.urlSegment(kind)}/{id}`, { id }),
                        payload,
                    )
                    showSuccess(t('teamhub', 'Saved'))
                } else {
                    // Create
                    const payload = { name }
                    if (kind === 'building') {
                        payload.address = this.dialog.address.trim() || null
                    }
                    if (kind === 'floor')  payload.building_id = this.dialog.building_id
                    if (kind === 'room')   payload.floor_id    = this.dialog.floor_id

                    await axios.post(
                        generateUrl(`/apps/teamhub/api/v1/admin/presence/${this.urlSegment(kind)}`),
                        payload,
                    )
                    showSuccess(t('teamhub', 'Created'))
                }
                this.closeDialog()
                await this.load()
            } catch (err) {
                showError(err?.response?.data?.error
                    || t('teamhub', 'Failed to save'))
            } finally {
                this.saving = false
            }
        },

        urlSegment(kind) {
            return kind === 'building' ? 'buildings'
                : kind === 'floor'    ? 'floors'
                                      : 'rooms'
        },

        // ── Delete ────────────────────────────────────────────────────

        confirmDelete(kind, item) {
            let subtreeCount = 0
            if (kind === 'building') {
                subtreeCount = (item.floors || []).length
            } else if (kind === 'floor') {
                subtreeCount = (item.rooms || []).length
            }
            this.deleteDialog = {
                open: true,
                kind,
                id: item.id,
                label: item.name,
                subtreeCount,
            }
        },

        closeDeleteDialog() {
            this.deleteDialog = { open: false, kind: '', id: null, label: '', subtreeCount: 0 }
        },

        async executeDelete() {
            const { kind, id, label } = this.deleteDialog
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/presence/${this.urlSegment(kind)}/{id}`, { id }),
                )
                showSuccess(t('teamhub', '{label} deleted', { label }))
                this.closeDeleteDialog()
                await this.load()
            } catch (err) {
                const data = err?.response?.data
                if (data?.affectedCount > 0) {
                    showError(n(
                        'teamhub',
                        'Cannot delete: in use by {n} entry',
                        'Cannot delete: in use by {n} entries',
                        data.affectedCount,
                        { n: data.affectedCount },
                    ))
                } else {
                    showError(data?.error
                        || t('teamhub', 'Failed to delete'))
                }
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
.presence-add-btn { margin-bottom: 12px; }

.presence-empty {
    padding: 20px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    border: 1px dashed var(--color-border);
    border-radius: var(--border-radius);
}
.presence-empty-inline {
    padding: 4px 8px 4px 36px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.presence-tree {
    list-style: none;
    margin: 0;
    padding: 0;
}
.presence-tree--nested {
    margin-left: 28px;
    border-left: 1px solid var(--color-border);
    padding-left: 8px;
    margin-top: 4px;
    margin-bottom: 8px;
}

.presence-node {
    margin: 0;
}
.presence-node__row {
    display: grid;
    grid-template-columns: auto auto 1fr auto;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    border-radius: var(--border-radius);
}
.presence-node__row:hover {
    background: var(--color-background-hover);
}
.presence-node__indent {
    width: 28px;
}
.presence-node__icon {
    color: var(--color-text-maxcontrast);
}
.presence-node__label {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.presence-node__sub {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}
.presence-node__actions {
    display: flex;
    gap: 2px;
}

.presence-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 8px 0;
}
</style>
