<template>
    <div class="op-picker">
        <label class="op-picker__label" :for="inputId">{{ t('teamhub', 'Find an OpenProject project') }}</label>
        <NcTextField
            :id="inputId"
            :model-value="query"
            :placeholder="t('teamhub', 'Project name or identifier')"
            :label="t('teamhub', 'Find an OpenProject project')"
            :label-outside="true"
            :show-trailing-button="query !== ''"
            trailing-button-icon="close"
            @update:model-value="onQuery"
            @trailing-button-click="onQuery('')" />
        <p class="op-picker__hint">
            {{ t('teamhub', 'Only projects you administer in OpenProject, or public projects, are listed. A project can be linked to one team.') }}
        </p>

        <div v-if="searching" class="op-picker__state">
            <NcLoadingIcon :size="ICON_BODY" />
            <span>{{ t('teamhub', 'Searching OpenProject') }}</span>
        </div>
        <div v-else-if="searchError" class="op-picker__state op-picker__state--error" role="alert">
            {{ searchError.message }}
        </div>
        <div v-else-if="results.length === 0" class="op-picker__state">
            {{ query ? t('teamhub', 'No projects match your search.') : t('teamhub', 'No projects available to you in OpenProject.') }}
        </div>
        <ul v-else class="op-picker__list" role="listbox" :aria-label="t('teamhub', 'OpenProject projects')">
            <li v-for="p in results" :key="p.id" role="presentation">
                <!-- Raw <button>: custom card-row list-item (resource-picker
                     carve-out). role=option carries the selection state.

                     v4.9.4 — a project another team already links is shown,
                     not hidden (a creator whose project is absent would
                     conclude OpenProject does not list it), and cannot be
                     picked: aria-disabled rather than disabled, so the row
                     stays focusable and the reason is read out. -->
                <button
                    type="button"
                    class="op-picker__item"
                    :class="{
                        'op-picker__item--selected': isSelected(p),
                        'op-picker__item--taken': isTaken(p),
                    }"
                    role="option"
                    :aria-selected="isSelected(p) ? 'true' : 'false'"
                    :aria-disabled="isTaken(p) ? 'true' : 'false'"
                    :title="isTaken(p) ? takenLabel(p) : undefined"
                    @click="select(p)">
                    <span class="op-picker__item-name">{{ p.name }}</span>
                    <span class="op-picker__item-meta">
                        {{ p.identifier }}
                        <span v-if="p.public">· {{ t('teamhub', 'Public') }}</span>
                        <span v-if="p.canEditProject">· {{ t('teamhub', 'You administer this project') }}</span>
                    </span>
                    <span v-if="isTaken(p)" class="op-picker__item-taken">
                        <LinkVariantIcon :size="ICON_INLINE" aria-hidden="true" />
                        {{ takenLabel(p) }}
                    </span>
                </button>
            </li>
        </ul>
        <p v-if="results.length >= resultLimit" class="op-picker__hint">
            {{ n('teamhub', 'Showing the first {n} match. Narrow the search to find others.', 'Showing the first {n} matches. Narrow the search to find others.', resultLimit, { n: resultLimit }) }}
        </p>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
import { classifyError } from '../lib/openProject.js'

/**
 * OpenProjectProjectPicker (v4.9.3) — search-and-pick over the OpenProject
 * projects the current user may link: administered by them, or public
 * (the backend filters; this component only shows what it is given).
 *
 * Used by the team-creation wizard for the OpenProject template. Not
 * team-scoped, because the team does not exist yet when the pick is made.
 * `v-model` holds the selected project summary object, or null.
 *
 * v4.9.4 — each project carries `linkedTeam` (null, or `{ teamId, name }`
 * with `name` only when the user is in that team). A taken project is
 * listed with the reason and cannot be picked; the wizard refuses it again
 * at step 1 and the server before creating anything.
 */
export default {
    name: 'OpenProjectProjectPicker',

    components: { LinkVariantIcon, NcLoadingIcon, NcTextField },

    props: {
        modelValue: { type: Object, default: null },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            inputId: 'op-picker-' + Math.random().toString(36).slice(2, 8),
            query: '',
            results: [],
            resultLimit: 25,
            searching: false,
            searchError: null,
            ICON_BODY,
            ICON_INLINE,
        }
    },

    mounted() {
        this.search()
    },

    beforeUnmount() {
        if (this._timer) clearTimeout(this._timer)
    },

    methods: {
        t, n,

        isSelected(p) {
            return !!this.modelValue && this.modelValue.id === p.id
        },

        /** v4.9.4 — another team already links this project. */
        isTaken(p) {
            return !!p.linkedTeam
        },

        takenLabel(p) {
            return p.linkedTeam?.name
                ? t('teamhub', 'Already linked to {team}', { team: p.linkedTeam.name })
                : t('teamhub', 'Already linked to another team')
        },

        select(p) {
            if (this.isTaken(p)) {
                // Deselect a pick that has become taken since it was made;
                // never select one.
                if (this.isSelected(p)) this.$emit('update:modelValue', null)
                return
            }
            this.$emit('update:modelValue', this.isSelected(p) ? null : p)
        },

        onQuery(value) {
            this.query = value
            if (this._timer) clearTimeout(this._timer)
            this._timer = setTimeout(() => this.search(), 300)
        },

        async search() {
            const query = this.query
            this.searching = true
            this.searchError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/openproject/projects'),
                    { params: { q: query } },
                )
                if (query !== this.query) return
                this.results = data.projects || []
                this.resultLimit = data.limit || 25
            } catch (e) {
                if (query !== this.query) return
                this.results = []
                this.searchError = classifyError(e)
            } finally {
                if (query === this.query) this.searching = false
            }
        },
    },
}
</script>

<style scoped>
.op-picker {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
}

.op-picker__label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
}

.op-picker__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.op-picker__state {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-xs);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.op-picker__state--error {
    color: var(--color-error-text);
}

.op-picker__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 18rem;
    overflow-y: auto;
}

/* Raw <button>: custom card-row list-item — the resource-picker carve-out. */
.op-picker__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--th-space-xxs);
    width: 100%;
    padding: var(--th-space-sm) var(--th-space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-control);
    background: var(--color-main-background);
    color: var(--color-main-text);
    text-align: start;
    cursor: pointer;
}

.op-picker__item:hover {
    background: var(--color-background-hover);
}

.op-picker__item:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.op-picker__item--selected,
.op-picker__item--selected:hover {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.op-picker__item--selected .op-picker__item-meta {
    color: inherit;
}

/* v4.9.4 — a project another team already links: listed, dimmed, and not
   a pointer target. The ring on :focus-visible is kept — the row stays
   focusable so the reason can be read. */
.op-picker__item--taken,
.op-picker__item--taken:hover {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    cursor: not-allowed;
}

.op-picker__item-taken {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xxs);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-warning-text);
}

.op-picker__item-name {
    font-weight: var(--th-font-weight-medium);
}

.op-picker__item-meta {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}
</style>
