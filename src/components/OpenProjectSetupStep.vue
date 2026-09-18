<template>
    <div class="op-setup">
        <!-- ── Host and capabilities ─────────────────────────────────── -->
        <div class="op-setup__host">
            <BriefcaseCheckOutline :size="ICON_BODY" aria-hidden="true" />
            <span v-if="options.capabilities?.host">
                {{ t('teamhub', 'OpenProject at {host}', { host: options.capabilities.host }) }}
            </span>
            <span v-else>{{ t('teamhub', 'OpenProject') }}</span>
            <span v-if="options.capabilities?.openProjectUser?.name" class="op-setup__host-user">
                · {{ t('teamhub', 'connected as {name}', { name: options.capabilities.openProjectUser.name }) }}
            </span>
        </div>
        <p v-if="options.capabilities?.errorCode" class="op-setup__error" role="alert">
            {{ options.capabilities.userMessage }}
        </p>

        <!-- ── Mode ──────────────────────────────────────────────────── -->
        <div class="op-setup__field">
            <label id="op-setup-mode-label" class="op-setup__label">{{ t('teamhub', 'OpenProject project') }}</label>
            <div class="op-setup__modes" role="radiogroup" aria-labelledby="op-setup-mode-label">
                <!-- Raw <button>s: selection tiles (template type-card carve-out);
                     role=radio carries the state, aria-disabled the reason. -->
                <button
                    v-for="m in modes"
                    :key="m.id"
                    type="button"
                    class="op-setup__mode"
                    :class="{ 'op-setup__mode--selected': value.mode === m.id, 'op-setup__mode--locked': m.locked }"
                    role="radio"
                    :aria-checked="value.mode === m.id ? 'true' : 'false'"
                    :aria-disabled="m.locked ? 'true' : 'false'"
                    :title="m.lockedReason || undefined"
                    @click="!m.locked && setMode(m.id)">
                    <span class="op-setup__mode-name">
                        {{ m.label }}
                        <LockOutline v-if="m.locked" :size="ICON_INLINE" aria-hidden="true" />
                    </span>
                    <span class="op-setup__mode-desc">{{ m.locked ? m.lockedReason : m.description }}</span>
                </button>
            </div>
        </div>

        <!-- ── Mode A: create ────────────────────────────────────────── -->
        <template v-if="value.mode === 'create'">
            <div class="op-setup__field">
                <label class="op-setup__label" :for="identifierId">{{ t('teamhub', 'Project identifier') }}</label>
                <p class="op-setup__hint">
                    {{ t('teamhub', 'Lowercase letters, digits, dashes and underscores. It becomes part of the project address in OpenProject and cannot be changed later.') }}
                </p>
                <NcTextField
                    :id="identifierId"
                    :model-value="value.identifier"
                    :label="t('teamhub', 'Project identifier')"
                    :label-outside="true"
                    :error="identifierState === 'invalid' || identifierState === 'taken'"
                    :success="identifierState === 'free'"
                    :helper-text="identifierHelp"
                    @update:model-value="setIdentifier" />
            </div>

            <div class="op-setup__field">
                <label id="op-setup-template-label" class="op-setup__label">{{ t('teamhub', 'OpenProject template') }}</label>
                <p class="op-setup__hint">
                    {{ options.approvedOnly
                        ? t('teamhub', 'Only templates approved for this kind of team are listed. Work packages, milestones and phases come from the template.')
                        : t('teamhub', 'The templates you may copy in OpenProject. Work packages, milestones and phases come from the template.') }}
                </p>
                <p v-if="templatesWarning" class="op-setup__error" role="alert">{{ templatesWarning }}</p>
                <div v-else-if="templates.length === 0" class="op-setup__state">
                    {{ t('teamhub', 'No OpenProject template is available to you. The project will be created empty.') }}
                </div>
                <ul v-else class="op-setup__list" role="radiogroup" aria-labelledby="op-setup-template-label">
                    <li role="presentation">
                        <!-- Raw <button>: card-row list-item (resource-picker carve-out). -->
                        <button
                            type="button"
                            class="op-setup__item"
                            :class="{ 'op-setup__item--selected': !value.templateId }"
                            role="radio"
                            :aria-checked="!value.templateId ? 'true' : 'false'"
                            @click="setTemplate(null)">
                            <span class="op-setup__item-name">{{ t('teamhub', 'No template') }}</span>
                            <span class="op-setup__item-meta">{{ t('teamhub', 'An empty project with the default types') }}</span>
                        </button>
                    </li>
                    <li v-for="tpl in templates" :key="tpl.id" role="presentation">
                        <button
                            type="button"
                            class="op-setup__item"
                            :class="{ 'op-setup__item--selected': value.templateId === tpl.id }"
                            role="radio"
                            :aria-checked="value.templateId === tpl.id ? 'true' : 'false'"
                            @click="setTemplate(tpl)">
                            <span class="op-setup__item-name">{{ tpl.name }}</span>
                            <span class="op-setup__item-meta">{{ tpl.identifier }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div v-if="options.allowParent" class="op-setup__field">
                <label class="op-setup__label" :for="parentId">
                    {{ t('teamhub', 'Parent project') }}
                    <span class="op-setup__optional">{{ t('teamhub', '(optional)') }}</span>
                </label>
                <NcTextField
                    :id="parentId"
                    :model-value="parentQuery"
                    :label="t('teamhub', 'Parent project')"
                    :label-outside="true"
                    :placeholder="t('teamhub', 'Search projects you may create under')"
                    :show-trailing-button="parentQuery !== '' || !!value.parent"
                    trailing-button-icon="close"
                    @update:model-value="onParentQuery"
                    @trailing-button-click="clearParent" />
                <p v-if="value.parent" class="op-setup__chosen">
                    <CheckCircle :size="ICON_INLINE" aria-hidden="true" />
                    {{ t('teamhub', 'Under {name}', { name: value.parent.name }) }}
                </p>
                <div v-else-if="parentSearching" class="op-setup__state">
                    <NcLoadingIcon :size="ICON_BODY" />
                    <span>{{ t('teamhub', 'Searching OpenProject') }}</span>
                </div>
                <ul v-else-if="parentResults.length" class="op-setup__list op-setup__list--compact" role="listbox" :aria-label="t('teamhub', 'Parent projects')">
                    <li v-for="p in parentResults" :key="p.id" role="presentation">
                        <button type="button" class="op-setup__item" role="option" aria-selected="false" @click="setParent(p)">
                            <span class="op-setup__item-name">{{ p.name }}</span>
                            <span class="op-setup__item-meta">{{ p.identifier }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </template>

        <!-- ── Mode B: link ──────────────────────────────────────────── -->
        <div v-else-if="value.mode === 'link'" class="op-setup__field">
            <p class="op-setup__hint">
                {{ t('teamhub', 'The workspace is built around a project that already exists in OpenProject. Its members and work stay as they are.') }}
            </p>
            <OpenProjectProjectPicker :model-value="value.project" @update:model-value="setProject" />
        </div>

        <!-- ── Project details ──────────────────────────────────────────
             Everything OpenProject in one place (Justin, 2026-09-13). The
             visibility is the new project's own flag, so only for mode A;
             the start date and category are recorded with the workspace. -->
        <template v-if="value.mode">
            <div v-if="value.mode === 'create'" class="op-setup__field">
                <label id="op-setup-visibility-label" class="op-setup__label">{{ t('teamhub', 'Visibility in OpenProject') }}</label>
                <div class="op-setup__modes" role="radiogroup" aria-labelledby="op-setup-visibility-label">
                    <!-- Raw <button>s: selection tiles (type-card carve-out); role=radio carries the state. -->
                    <button
                        v-for="v in visibilityOptions"
                        :key="v.id"
                        type="button"
                        class="op-setup__mode"
                        :class="{ 'op-setup__mode--selected': value.visibility === v.id }"
                        role="radio"
                        :aria-checked="value.visibility === v.id ? 'true' : 'false'"
                        @click="update({ visibility: v.id })">
                        <span class="op-setup__mode-name">{{ v.label }}</span>
                        <span class="op-setup__mode-desc">{{ v.description }}</span>
                    </button>
                </div>
                <p class="op-setup__hint">
                    {{ t('teamhub', 'Who may see the team in TeamHub is the policy\'s setting; this is the OpenProject project\'s own visibility, applied when a new project is created.') }}
                </p>
            </div>
            <div class="op-setup__grid">
                <div class="op-setup__field">
                    <label class="op-setup__label" :for="startDateId">
                        {{ t('teamhub', 'Start date') }}
                        <span class="op-setup__optional">{{ t('teamhub', '(optional)') }}</span>
                    </label>
                    <input
                        :id="startDateId"
                        :value="value.startDate"
                        type="date"
                        class="op-setup__date"
                        @input="update({ startDate: $event.target.value })" />
                </div>
                <div class="op-setup__field">
                    <label class="op-setup__label" :for="categoryId">
                        {{ t('teamhub', 'Project category') }}
                        <span class="op-setup__optional">{{ t('teamhub', '(optional)') }}</span>
                    </label>
                    <NcTextField
                        :id="categoryId"
                        :model-value="value.category"
                        :label="t('teamhub', 'Project category')"
                        :label-outside="true"
                        :placeholder="t('teamhub', 'e.g. Software, Construction, Governance')"
                        @update:model-value="update({ category: $event })" />
                </div>
            </div>
        </template>

        <p v-if="error" class="op-setup__error" role="alert">{{ error }}</p>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import BriefcaseCheckOutline from 'vue-material-design-icons/BriefcaseCheckOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import OpenProjectProjectPicker from './OpenProjectProjectPicker.vue'
import { ICON_BODY, ICON_INLINE } from '../constants/uiTokens.js'
import { classifyError, errorMessage } from '../lib/openProject.js'

/**
 * OpenProjectSetupStep (v4.9.6, Phase 2) — the wizard's OpenProject step
 * for the OpenProject Workspace template: create a new project from a
 * template, or connect an existing one.
 *
 * `options` is the answer of GET /api/v1/provisioning/options — the
 * capabilities (probed), the templates the creator may copy (already
 * narrowed to the approved ones), whether creating is possible at all, and
 * whether a parent may be chosen. Nothing is promised the probe did not
 * confirm: a mode the creator cannot use is shown locked with the reason.
 *
 * `v-model` is `{ mode, identifier, templateId, template, parentId, parent, project }`.
 */
export default {
    name: 'OpenProjectSetupStep',

    components: { BriefcaseCheckOutline, CheckCircle, LockOutline, NcLoadingIcon, NcTextField, OpenProjectProjectPicker },

    props: {
        options: { type: Object, required: true },
        modelValue: { type: Object, required: true },
        /** The team name, for the identifier suggestion. */
        teamName: { type: String, default: '' },
        error: { type: String, default: '' },
    },

    emits: ['update:modelValue', 'identifier-state'],

    data() {
        return {
            identifierId: 'op-setup-identifier-' + Math.random().toString(36).slice(2, 8),
            parentId: 'op-setup-parent-' + Math.random().toString(36).slice(2, 8),
            startDateId: 'op-setup-start-' + Math.random().toString(36).slice(2, 8),
            categoryId: 'op-setup-category-' + Math.random().toString(36).slice(2, 8),
            identifierState: 'unknown', // unknown | checking | free | taken | invalid
            identifierTouched: false,
            identifierTimer: null,
            parentQuery: '',
            parentResults: [],
            parentSearching: false,
            parentTimer: null,
            ICON_BODY,
            ICON_INLINE,
        }
    },

    computed: {
        value() {
            return this.modelValue
        },
        templates() {
            return Array.isArray(this.options.templates) ? this.options.templates : []
        },
        visibilityOptions() {
            return [
                { id: 'private', label: t('teamhub', 'Private'), description: t('teamhub', 'Only project members see it in OpenProject') },
                { id: 'public', label: t('teamhub', 'Public'), description: t('teamhub', 'Every OpenProject user can see it') },
            ]
        },
        templatesWarning() {
            const w = (this.options.warnings || []).find(x => String(x).startsWith('templates:'))
            return w ? errorMessage(w.slice('templates:'.length)) : ''
        },
        modes() {
            const caps = this.options.capabilities || {}
            const modesAllowed = Array.isArray(this.options.modes) ? this.options.modes : ['create', 'link']
            const createLocked = !modesAllowed.includes('create')
                ? t('teamhub', 'This kind of team connects to an existing project.')
                : (caps.errorCode ? caps.userMessage : (!this.options.canCreate ? t('teamhub', 'OpenProject does not let you create projects.') : ''))
            const linkLocked = !modesAllowed.includes('link')
                ? t('teamhub', 'This kind of team always starts a new project.')
                : (caps.errorCode ? caps.userMessage : '')
            return [
                {
                    id: 'create',
                    label: t('teamhub', 'Create a new project'),
                    description: t('teamhub', 'From an OpenProject template, with its work packages and milestones'),
                    locked: !!createLocked,
                    lockedReason: createLocked,
                },
                {
                    id: 'link',
                    label: t('teamhub', 'Connect an existing project'),
                    description: t('teamhub', 'A project you administer, or a public one'),
                    locked: !!linkLocked,
                    lockedReason: linkLocked,
                },
            ]
        },
        identifierHelp() {
            switch (this.identifierState) {
            case 'checking': return t('teamhub', 'Checking with OpenProject')
            case 'free': return t('teamhub', 'Available')
            case 'taken': return t('teamhub', 'This identifier is already taken in OpenProject.')
            case 'invalid': return t('teamhub', 'Only lowercase letters, digits, dashes and underscores, starting with a letter or digit.')
            default: return ''
            }
        },
    },

    watch: {
        teamName: {
            immediate: true,
            handler(name) {
                // Suggest while the creator has not typed an identifier of their own.
                if (this.value.mode === 'create' && !this.identifierTouched && name) {
                    this.setIdentifier(this.slug(name), false)
                }
            },
        },
        identifierState(state) {
            this.$emit('identifier-state', state)
        },
    },

    mounted() {
        // The first mode the creator may use.
        if (!this.value.mode) {
            const first = this.modes.find(m => !m.locked)
            if (first) this.setMode(first.id)
        }
        if (this.value.identifier) {
            this.checkIdentifier(this.value.identifier)
        }
    },

    beforeUnmount() {
        clearTimeout(this.identifierTimer)
        clearTimeout(this.parentTimer)
    },

    methods: {
        t,

        update(patch) {
            this.$emit('update:modelValue', { ...this.value, ...patch })
        },

        setMode(mode) {
            this.update({ mode })
            if (mode === 'create' && !this.value.identifier && this.teamName) {
                this.setIdentifier(this.slug(this.teamName), false)
            }
        },

        setTemplate(tpl) {
            this.update({ templateId: tpl ? tpl.id : null, template: tpl })
        },

        setProject(project) {
            this.update({ project })
        },

        setParent(p) {
            this.parentResults = []
            this.parentQuery = ''
            this.update({ parentId: p.id, parent: p })
        },

        clearParent() {
            this.parentQuery = ''
            this.parentResults = []
            this.update({ parentId: null, parent: null })
        },

        setIdentifier(raw, touched = true) {
            if (touched) this.identifierTouched = true
            const identifier = String(raw || '').toLowerCase()
            this.update({ identifier })
            clearTimeout(this.identifierTimer)
            if (!/^[a-z0-9][a-z0-9_-]{0,99}$/.test(identifier)) {
                this.identifierState = identifier === '' ? 'unknown' : 'invalid'
                return
            }
            this.identifierState = 'checking'
            this.identifierTimer = setTimeout(() => this.checkIdentifier(identifier), 400)
        },

        async checkIdentifier(identifier) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/provisioning/identifier'), { params: { identifier } })
                if (this.value.identifier !== identifier) return
                this.identifierState = !data.valid ? 'invalid' : (data.available ? 'free' : 'taken')
            } catch (e) {
                if (this.value.identifier !== identifier) return
                // Could not ask — the validate step asks again before anything is made.
                this.identifierState = 'unknown'
                console.warn('[TeamHub][OpenProjectSetupStep] identifier check failed:', classifyError(e).code)
            }
        },

        onParentQuery(q) {
            this.parentQuery = q
            clearTimeout(this.parentTimer)
            if (!q) {
                this.parentResults = []
                return
            }
            this.parentTimer = setTimeout(() => this.searchParents(q), 350)
        },

        async searchParents(q) {
            this.parentSearching = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/provisioning/parents'), { params: { q } })
                if (this.parentQuery !== q) return
                this.parentResults = Array.isArray(data.projects) ? data.projects : []
            } catch (e) {
                this.parentResults = []
            } finally {
                this.parentSearching = false
            }
        },

        /** The same rule as the backend's suggestIdentifier(), for the first suggestion. */
        slug(name) {
            const s = String(name || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-').replace(/^[-_]+|[-_]+$/g, '')
            if (!s) return 'project'
            return /^[a-z0-9]/.test(s) ? s.slice(0, 100) : ('project-' + s).slice(0, 100)
        },
    },
}
</script>

<style scoped>
.op-setup {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
}

.op-setup__host {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-md);
    border-radius: var(--th-radius-control);
    background: var(--color-background-dark);
    font-size: var(--th-font-meta);
}

.op-setup__host-user {
    color: var(--color-text-maxcontrast);
}

.op-setup__field {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
}

.op-setup__label {
    font-weight: var(--th-font-weight-semibold);
}

.op-setup__optional {
    font-weight: var(--th-font-weight-regular);
    color: var(--color-text-maxcontrast);
}

.op-setup__hint,
.op-setup__state {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.op-setup__state {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
}

.op-setup__error {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
}

.op-setup__chosen {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xxs);
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-success-text);
}

.op-setup__modes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: var(--th-space-sm);
}

/* Raw <button>: selection tile (type-card carve-out). */
.op-setup__mode {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--th-space-xxs);
    padding: var(--th-space-md);
    border: 2px solid var(--color-border);
    border-radius: var(--th-radius-card);
    background: var(--color-main-background);
    color: var(--color-main-text);
    text-align: start;
    cursor: pointer;
}

.op-setup__mode:hover {
    background: var(--color-background-hover);
}

.op-setup__mode:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.op-setup__mode--selected,
.op-setup__mode--selected:hover {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.op-setup__mode--locked,
.op-setup__mode--locked:hover {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    cursor: not-allowed;
}

.op-setup__mode-name {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xxs);
    font-weight: var(--th-font-weight-semibold);
}

.op-setup__mode-desc {
    font-size: var(--th-font-meta);
    opacity: 0.9;
}

.op-setup__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 18rem;
    overflow-y: auto;
}

.op-setup__list--compact {
    max-height: 12rem;
}

.op-setup__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: var(--th-space-md);
}

/* The same date control the wizard's expiry field uses. */
.op-setup__date {
    min-height: 44px;
    padding: 0 var(--th-space-md);
    border: 2px solid var(--color-border-dark);
    border-radius: var(--th-radius-control);
    background: var(--color-main-background);
    color: var(--color-main-text);
    outline: none;
}

.op-setup__date:focus {
    border-color: var(--color-primary-element);
}

.op-setup__date:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

/* Raw <button>: card-row list-item (resource-picker carve-out). */
.op-setup__item {
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

.op-setup__item:hover {
    background: var(--color-background-hover);
}

.op-setup__item:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.op-setup__item--selected,
.op-setup__item--selected:hover {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.op-setup__item--selected .op-setup__item-meta {
    color: inherit;
}

.op-setup__item-name {
    font-weight: var(--th-font-weight-medium);
}

.op-setup__item-meta {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}
</style>
