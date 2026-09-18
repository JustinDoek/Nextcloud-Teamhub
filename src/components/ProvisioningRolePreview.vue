<template>
    <div class="prov-roles">
        <h4 class="prov-roles__title">{{ t('teamhub', 'Roles in OpenProject') }}</h4>
        <p class="prov-roles__hint">
            {{ t('teamhub', 'Each TeamHub role maps to an OpenProject role, as configured by your administrator. Memberships are created as you, so OpenProject applies its own permission rules.') }}
        </p>

        <table v-if="mappingRows.length" class="prov-roles__mapping">
            <caption class="prov-roles__sr">{{ t('teamhub', 'Role mapping') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('teamhub', 'TeamHub role') }}</th>
                    <th scope="col">{{ t('teamhub', 'OpenProject role') }}</th>
                    <th scope="col">{{ t('teamhub', 'Can use') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in mappingRows" :key="row.key">
                    <td>{{ row.label }}</td>
                    <td :class="{ 'prov-roles__missing': row.missing }">
                        {{ row.name || t('teamhub', 'No OpenProject access') }}
                        <span v-if="row.missing"> · {{ t('teamhub', 'not found in OpenProject') }}</span>
                    </td>
                    <td class="prov-roles__components">{{ row.components }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="loading" class="prov-roles__state">
            <NcLoadingIcon :size="ICON_BODY" />
            <span>{{ t('teamhub', 'Matching members with OpenProject') }}</span>
        </div>
        <p v-else-if="error" class="prov-roles__error" role="alert">{{ error }}</p>

        <template v-else-if="entries.length">
            <ul class="prov-roles__members" :aria-label="t('teamhub', 'Members and their OpenProject roles')">
                <li v-for="e in entries" :key="e.type + ':' + e.id" class="prov-roles__member" :class="'prov-roles__member--' + e.status">
                    <NcAvatar v-if="e.type === 'user'" :user="e.id" :display-name="e.displayName" :size="24" :show-user-status="false" />
                    <span v-else class="prov-roles__avatar-alt"><AccountGroup :size="ICON_BODY" aria-hidden="true" /></span>
                    <span class="prov-roles__member-name">{{ e.displayName }}</span>
                    <span class="prov-roles__member-role">{{ roleLabel(e.teamRole) }}</span>
                    <span class="prov-roles__member-op">
                        <template v-if="e.status === 'no_access'">{{ t('teamhub', 'No OpenProject access') }}</template>
                        <template v-else-if="e.status === 'exists'">
                            {{ t('teamhub', 'Already in the project') }}<span v-if="e.roleDrift"> · {{ t('teamhub', 'different role') }}</span>
                        </template>
                        <template v-else-if="e.status === 'add'">
                            {{ t('teamhub', '{role} as {name}', { role: e.openProjectRole?.name || '', name: e.principal?.name || '' }) }}
                        </template>
                        <template v-else-if="e.status === 'unmatched'">
                            <span class="prov-roles__unmatched">{{ t('teamhub', 'No OpenProject account found') }}</span>
                        </template>
                        <template v-else-if="e.status === 'leaves'">
                            {{ e.leavesProject
                                ? t('teamhub', 'Hands the team over and leaves it and the project')
                                : t('teamhub', 'Hands the team over and leaves it; keeps the project access they already have') }}
                        </template>
                    </span>
                    <template v-if="e.status === 'unmatched'">
                        <label class="prov-roles__sr" :for="'prov-decision-' + e.type + '-' + e.id">
                            {{ t('teamhub', 'What to do with {name}', { name: e.displayName }) }}
                        </label>
                        <select
                            :id="'prov-decision-' + e.type + '-' + e.id"
                            class="prov-roles__decision"
                            :value="decisions[e.type + ':' + e.id] || ''"
                            @change="decide(e, $event.target.value)">
                            <option value="" disabled>{{ t('teamhub', 'Choose') }}</option>
                            <option value="teamhub_only">{{ t('teamhub', 'Add to the team only') }}</option>
                            <option value="omit">{{ t('teamhub', 'Leave out') }}</option>
                        </select>
                    </template>
                </li>
            </ul>
            <p v-if="undecided.length" class="prov-roles__error" role="alert">
                {{ n('teamhub', '{n} member has no OpenProject account. Decide what to do with them before continuing.', '{n} members have no OpenProject account. Decide what to do with them before continuing.', undecided.length, { n: undecided.length }) }}
            </p>
        </template>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import { ICON_BODY } from '../constants/uiTokens.js'
import { classifyError } from '../lib/openProject.js'
import { componentLabel, roleLabel } from '../lib/provisioning.js'

/**
 * ProvisioningRolePreview (v4.9.6, Phase 2) — under the wizard's members
 * step for the OpenProject Workspace template: the role mapping (read-only;
 * an administrator's setting), and every member with the OpenProject user
 * they match and the role they will get. A member without an OpenProject
 * account needs an explicit decision — add them to the team only, or leave
 * them out — and the wizard does not go on until each has one.
 *
 * Asks POST /api/v1/provisioning/preview whenever the member list or the
 * chosen project changes (debounced); nothing is created.
 */
export default {
    name: 'ProvisioningRolePreview',

    components: { AccountGroup, NcAvatar, NcLoadingIcon },

    props: {
        templateKey: { type: String, default: 'openproject' },
        mode: { type: String, default: 'create' },
        projectId: { type: Number, default: 0 },
        /** Wizard members: { id, type, level, displayName } */
        members: { type: Array, default: () => [] },
        /** Which components the workspace has, for the "can use" column. */
        apps: { type: Array, default: () => [] },
        /** decisions by "type:id" → 'teamhub_only' | 'omit' */
        decisions: { type: Object, default: () => ({}) },
    },

    emits: ['update:decisions', 'ready'],

    data() {
        return {
            loading: false,
            error: '',
            entries: [],
            roleMapping: {},
            timer: null,
            ICON_BODY,
        }
    },

    computed: {
        mappingRows() {
            const keys = ['owner', 'admin', 'moderator', 'member', 'guest']
            return keys
                .filter(k => this.roleMapping[k])
                .map(k => ({
                    key: k,
                    label: roleLabel(k),
                    name: this.roleMapping[k].name,
                    missing: !!this.roleMapping[k].missing,
                    components: this.componentsFor(k),
                }))
        },
        undecided() {
            return this.entries.filter(e => e.status === 'unmatched' && !this.decisions[e.type + ':' + e.id])
        },
        /** The preview key — anything in here changing re-asks. */
        previewKey() {
            return JSON.stringify([this.mode, this.projectId, this.members.map(m => [m.id, m.type || 'user', m.level || 1])])
        },
    },

    watch: {
        previewKey: {
            immediate: true,
            handler() {
                clearTimeout(this.timer)
                this.timer = setTimeout(() => this.load(), 300)
            },
        },
        undecided(list) {
            this.$emit('ready', list.length === 0 && !this.loading && !this.error)
        },
    },

    beforeUnmount() {
        clearTimeout(this.timer)
    },

    methods: {
        t,
        n,
        roleLabel,

        componentsFor(roleKey) {
            const all = [componentLabel('dashboard'), ...this.apps.map(componentLabel)]
            if (roleKey === 'guest') {
                return t('teamhub', 'Team page, {apps}', { apps: this.apps.map(componentLabel).join(', ') })
            }
            return all.join(', ')
        },

        async load() {
            this.loading = true
            this.error = ''
            try {
                const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/provisioning/preview'), {
                    templateKey: this.templateKey,
                    mode: this.mode,
                    members: this.members.map(m => ({ id: m.id, type: m.type || 'user', level: m.level || 1, displayName: m.displayName || m.id })),
                    openProject: { projectId: this.projectId || 0 },
                })
                this.entries = Array.isArray(data.entries) ? data.entries : []
                this.roleMapping = data.roleMapping && typeof data.roleMapping === 'object' ? data.roleMapping : {}
                this.$emit('ready', this.undecided.length === 0)
            } catch (e) {
                this.error = classifyError(e).message
                this.$emit('ready', false)
            } finally {
                this.loading = false
            }
        },

        decide(entry, decision) {
            this.$emit('update:decisions', { ...this.decisions, [entry.type + ':' + entry.id]: decision })
        },
    },
}
</script>

<style scoped>
.prov-roles {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    margin-top: var(--th-space-lg);
    padding-top: var(--th-space-lg);
    border-top: 1px solid var(--color-border);
}

.prov-roles__title {
    margin: 0;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.prov-roles__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-roles__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.prov-roles__mapping {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--th-font-meta);
}

.prov-roles__mapping th,
.prov-roles__mapping td {
    padding: var(--th-space-xs) var(--th-space-sm);
    border-bottom: 1px solid var(--color-border);
    text-align: start;
    vertical-align: top;
}

.prov-roles__mapping th {
    font-weight: var(--th-font-weight-semibold);
}

.prov-roles__components {
    color: var(--color-text-maxcontrast);
}

.prov-roles__missing {
    color: var(--color-warning-text);
}

.prov-roles__state {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-roles__error {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
}

.prov-roles__members {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.prov-roles__member {
    display: grid;
    grid-template-columns: auto minmax(8rem, 1fr) auto minmax(8rem, 1.4fr) auto;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-xs) var(--th-space-sm);
    border-radius: var(--th-radius-control);
    font-size: var(--th-font-meta);
}

.prov-roles__member--unmatched {
    background: var(--color-background-dark);
}

.prov-roles__avatar-alt {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 24px;
    height: 24px;
    min-width: 24px;
    min-height: 24px;
    max-width: 24px;
    max-height: 24px;
    border-radius: 50%;
    background: var(--color-background-dark);
}

.prov-roles__member-name {
    font-weight: var(--th-font-weight-medium);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.prov-roles__member-role {
    color: var(--color-text-maxcontrast);
}

.prov-roles__member-op {
    color: var(--color-text-maxcontrast);
}

.prov-roles__unmatched {
    color: var(--color-warning-text);
    font-weight: var(--th-font-weight-medium);
}

.prov-roles__decision {
    min-height: 34px;
    padding: 0 var(--th-space-sm);
    border: 2px solid var(--color-border-dark);
    border-radius: var(--th-radius-control);
    background: var(--color-main-background);
    color: var(--color-main-text);
    outline: none;
}

.prov-roles__decision:focus {
    border-color: var(--color-primary-element);
}

.prov-roles__decision:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

@media (max-width: 700px) {
    .prov-roles__member {
        grid-template-columns: auto 1fr;
    }
    .prov-roles__member-role,
    .prov-roles__member-op,
    .prov-roles__decision {
        grid-column: 2;
    }
}
</style>
