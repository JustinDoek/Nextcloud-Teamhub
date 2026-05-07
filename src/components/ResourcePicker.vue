<template>
    <div class="resource-picker">
        <!-- Files: button-triggered NC file picker dialog -->
        <div v-if="app === 'files'" class="resource-picker__files">
            <NcButton
                type="secondary"
                :disabled="disabled || loading"
                @click="openFilePicker">
                <template #icon>
                    <Folder :size="18" />
                </template>
                {{ selectedFolderLabel || t('teamhub', 'Choose folder…') }}
            </NcButton>
        </div>

        <!-- Calendar / Deck / Talk: dropdown of user-owned resources -->
        <div v-else class="resource-picker__select">
            <select
                v-model="selectedId"
                class="resource-picker__select-el"
                :disabled="disabled || loading"
                :aria-label="ariaLabel"
                @change="onSelectChange">
                <option :value="null" disabled>
                    {{ loading
                        ? t('teamhub', 'Loading…')
                        : (resources.length === 0
                            ? t('teamhub', 'No items available')
                            : placeholderText) }}
                </option>
                <option
                    v-for="r in resources"
                    :key="r.id"
                    :value="r.id">
                    {{ r.name || t('teamhub', 'Untitled') }}
                </option>
            </select>
            <span v-if="loadError" class="resource-picker__error" role="alert">
                {{ loadError }}
            </span>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton } from '@nextcloud/vue'
import Folder from 'vue-material-design-icons/Folder.vue'

/**
 * ResourcePicker — pick an existing user-owned resource for connecting to a team.
 *
 * For 'calendar', 'deck', 'talk': renders a native <select> populated from the
 * corresponding /api/v1/pickers/{app} endpoint.
 *
 * For 'files': renders a button that opens NC's standard file picker dialog
 * (getFilePickerBuilder from @nextcloud/dialogs).
 *
 * Emits 'input' (v-model) with the selected resource ID, or null when cleared.
 */
export default {
    name: 'ResourcePicker',
    components: { NcButton, Folder },
    props: {
        app: {
            type: String,
            required: true,
            validator: v => ['talk', 'files', 'calendar', 'deck'].includes(v),
        },
        value: {
            type: [Number, String, null],
            default: null,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['input', 'selected-name'],
    data() {
        return {
            resources: [],
            loading: false,
            loadError: '',
            selectedId: this.value,
            selectedFolderLabel: '',
        }
    },
    computed: {
        placeholderText() {
            // TRANSLATORS: placeholder shown in a dropdown that lists the user's resources to connect to a team
            switch (this.app) {
            case 'calendar': return t('teamhub', 'Select a calendar…')
            case 'deck':     return t('teamhub', 'Select a board…')
            case 'talk':     return t('teamhub', 'Select a conversation…')
            default:         return t('teamhub', 'Select…')
            }
        },
        ariaLabel() {
            switch (this.app) {
            case 'calendar': return t('teamhub', 'Calendar to connect')
            case 'deck':     return t('teamhub', 'Deck board to connect')
            case 'talk':     return t('teamhub', 'Talk conversation to connect')
            default:         return t('teamhub', 'Resource to connect')
            }
        },
    },
    watch: {
        value(newVal) {
            this.selectedId = newVal
            if (newVal === null) {
                this.selectedFolderLabel = ''
            }
        },
    },
    mounted() {
        if (this.app !== 'files') {
            this.loadResources()
        }
    },
    methods: {
        t,

        async loadResources() {
            this.loading = true
            this.loadError = ''
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/pickers/${this.app}`)
                )
                this.resources = Array.isArray(data?.resources) ? data.resources : []
            } catch (e) {
                const detail = e?.response?.data?.error || e?.message || ''
                // TRANSLATORS: error shown when the picker fails to load the user's resources
                this.loadError = detail
                    ? t('teamhub', 'Could not load list: {error}', { error: detail })
                    : t('teamhub', 'Could not load list')
            } finally {
                this.loading = false
            }
        },

        onSelectChange() {
            const id = this.selectedId === null ? null : Number(this.selectedId)
            this.$emit('input', id)
            const found = this.resources.find(r => r.id === id)
            this.$emit('selected-name', found ? (found.name || '') : '')
        },

        async openFilePicker() {
            try {
                const picker = getFilePickerBuilder(t('teamhub', 'Select a folder to connect'))
                    .setMultiSelect(false)
                    .setMimeTypeFilter(['httpd/unix-directory'])
                    .setType(FilePickerType.Choose)
                    .allowDirectories(true)
                    .build()

                const result = await picker.pick()
                // result is a path string in NC dialogs v5; we need to resolve to a fileId
                const path = Array.isArray(result) ? result[0] : result
                if (!path) {
                    return
                }

                // Resolve path → fileId via WebDAV PROPFIND on the user's home (cheap, one request).
                const fileId = await this.resolveFileIdByPath(path)
                if (!fileId) {
                    // TRANSLATORS: error shown when the selected folder cannot be resolved to an internal file ID
                    this.loadError = t('teamhub', 'Could not resolve folder. Please try a different folder.')
                    return
                }

                this.selectedFolderLabel = path
                this.selectedId = fileId
                this.$emit('input', fileId)
                this.$emit('selected-name', path)
            } catch (e) {
                // User cancelled or picker failed — silent unless it was a real error
                if (e && e.message && !/cancel/i.test(e.message)) {
                    this.loadError = t('teamhub', 'Folder picker failed: {error}', { error: e.message })
                }
            }
        },

        async resolveFileIdByPath(path) {
            // WebDAV PROPFIND on the user's files endpoint to fetch fileid.
            // Path is e.g. "/Photos/2024" — we strip the leading slash.
            const userId = window.OC?.getCurrentUser?.()?.uid
            if (!userId) return null
            const cleanPath = String(path).replace(/^\//, '')
            const url = window.OC.linkToRemote('dav') + '/files/' + encodeURIComponent(userId) + '/' + cleanPath.split('/').map(encodeURIComponent).join('/')
            try {
                const resp = await axios({
                    method: 'PROPFIND',
                    url,
                    headers: { 'Content-Type': 'application/xml', Depth: '0' },
                    data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
                })
                const m = String(resp.data).match(/<oc:fileid>(\d+)<\/oc:fileid>/)
                return m ? Number(m[1]) : null
            } catch (e) {
                return null
            }
        },
    },
}
</script>

<style scoped>
.resource-picker {
    width: 100%;
}

.resource-picker__select-el {
    width: 100%;
    min-height: 36px;
    padding: 4px 8px;
    border: 1px solid var(--color-border, #ccc);
    border-radius: var(--border-radius, 4px);
    background: var(--color-main-background, #fff);
    color: var(--color-main-text, #222);
    font: inherit;
}

.resource-picker__select-el:focus-visible {
    outline: 2px solid var(--color-primary-element, #0082c9);
    outline-offset: 1px;
}

.resource-picker__select-el:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.resource-picker__error {
    display: block;
    margin-top: 4px;
    color: var(--color-error, #b00020);
    font-size: 12px;
}
</style>
