<template>
    <div class="resource-picker">
        <!-- Files / Calendar / Deck / Talk: dropdown of available resources -->
        <div class="resource-picker__select">
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
                    {{ resourceLabel(r) }}
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
import axios from '@nextcloud/axios'

/**
 * ResourcePicker — pick an existing resource for connecting to a team.
 *
 * All apps (files, calendar, deck, talk) use a server-driven list from
 * /api/v1/pickers/{app}. For files, the endpoint returns group folders
 * (type=group_folder) first, then shared folders (type=shared_folder).
 *
 * Emits 'update:modelValue' (v-model) with the selected resource ID (string or int).
 */
export default {
    name: 'ResourcePicker',
    props: {
        app: {
            type: String,
            required: true,
            validator: v => ['talk', 'files', 'calendar', 'deck'].includes(v),
        },
        // teamId required for files to scope group folder results to team membership
        teamId: {
            type: String,
            default: '',
        },
        modelValue: {
            type: [Number, String, null],
            default: null,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['update:modelValue', 'selected-name'],
    data() {
        return {
            resources: [],
            loading: false,
            loadError: '',
            selectedId: this.modelValue,
        }
    },
    computed: {
        placeholderText() {
            switch (this.app) {
            case 'files':    return t('teamhub', 'Select a folder\u2026')
            case 'calendar': return t('teamhub', 'Select a calendar\u2026')
            case 'deck':     return t('teamhub', 'Select a board\u2026')
            case 'talk':     return t('teamhub', 'Select a conversation\u2026')
            default:         return t('teamhub', 'Select\u2026')
            }
        },
        ariaLabel() {
            switch (this.app) {
            case 'files':    return t('teamhub', 'Folder to connect')
            case 'calendar': return t('teamhub', 'Calendar to connect')
            case 'deck':     return t('teamhub', 'Deck board to connect')
            case 'talk':     return t('teamhub', 'Talk conversation to connect')
            default:         return t('teamhub', 'Resource to connect')
            }
        },
    },
    watch: {
        modelValue(newVal) {
            this.selectedId = newVal
        },
    },
    mounted() {
        this.loadResources()
    },
    methods: {
        t,

        async loadResources() {
            this.loading = true
            this.loadError = ''
            try {
                const url = this.app === 'files'
                    ? generateUrl(`/apps/teamhub/api/v1/pickers/files?teamId=${encodeURIComponent(this.teamId)}`)
                    : generateUrl(`/apps/teamhub/api/v1/pickers/${this.app}`)
                const { data } = await axios.get(url)
                this.resources = Array.isArray(data?.resources) ? data.resources : []
            } catch (e) {
                const detail = e?.response?.data?.error || e?.message || ''
                this.loadError = detail
                    ? t('teamhub', 'Could not load list: {error}', { error: detail })
                    : t('teamhub', 'Could not load list')
            } finally {
                this.loading = false
            }
        },

        resourceLabel(r) {
            if (this.app === 'files') {
                if (r.type === 'group_folder') {
                    // TRANSLATORS: badge prefix for a Group Folder item in the file folder picker
                    return t('teamhub', '[Group Folder] {name}', { name: r.name || r.id })
                }
                if (r.type === 'shared_folder') {
                    // TRANSLATORS: badge prefix for a personal shared folder in the file folder picker
                    return t('teamhub', '[Shared folder] {name}', { name: r.name || r.id })
                }
            }
            return r.name || t('teamhub', 'Untitled')
        },

        onSelectChange() {
            const val = this.selectedId
            this.$emit('update:modelValue', val)
            const found = this.resources.find(r => String(r.id) === String(val))
            this.$emit('selected-name', found ? (found.name || '') : '')
        },
    },
}
</script>

<style scoped>
.resource-picker {
    width: 100%;
}

/* v3.100.16: retired defensive hex fallbacks (`#ccc`, `#fff`, `#222`,
   `#0082c9`) — NC always defines these tokens, so the fallbacks were
   dead code that pinned specific values regardless of theme. */
.resource-picker__select-el {
    width: 100%;
    min-height: 36px;
    padding: 4px 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font: inherit;
}

.resource-picker__select-el:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.resource-picker__select-el:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.resource-picker__error {
    display: block;
    margin-top: 4px;
    color: var(--color-error-text);
    font-size: var(--th-font-meta);
}
</style>
