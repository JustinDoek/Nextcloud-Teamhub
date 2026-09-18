<template>
    <div class="manage-section op-panel" data-section="openproject">
        <h3 class="op-panel__title">
            <BriefcaseOutline :size="ICON_TOOLBAR" aria-hidden="true" />
            {{ t('teamhub', 'OpenProject') }}
        </h3>
        <p class="op-panel__desc">
            {{ t('teamhub', 'The OpenProject project this team was created for. It was chosen when the team was created and cannot be changed here; the project itself is managed in OpenProject.') }}
        </p>

        <div v-if="loading" class="op-panel__loading">
            <NcLoadingIcon :size="ICON_NAV" />
        </div>

        <template v-else>
            <dl class="op-panel__status">
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'Linked project') }}</dt>
                    <dd>
                        <StatusMark :ok="!!link && !link.stale" :unknown="!link" />
                        <template v-if="link">
                            <a
                                v-if="link.urls && link.urls.project"
                                :href="link.urls.project"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="op-panel__host"
                                :title="t('teamhub', 'Opens in OpenProject')">{{ link.projectName }}</a>
                            <span v-else>{{ link.projectName }}</span>
                            <span class="op-panel__muted">{{ link.projectIdentifier }} · #{{ link.projectId }}</span>
                            <span v-if="link.stale" class="op-panel__chip op-panel__chip--warning">
                                {{ t('teamhub', 'Linked to a different OpenProject host') }}
                            </span>
                        </template>
                        <template v-else>{{ t('teamhub', 'No project linked') }}</template>
                    </dd>
                </div>
                <div v-if="link" class="op-panel__row">
                    <dt>{{ t('teamhub', 'Last successful read') }}</dt>
                    <dd>
                        <template v-if="link.lastValidatedAt">{{ formatDateTime(link.lastValidatedAt * 1000) }}</template>
                        <template v-else>{{ t('teamhub', 'Never') }}</template>
                    </dd>
                </div>
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'Integration app') }}</dt>
                    <dd>
                        <StatusMark :ok="capabilities.integrationAppEnabled" />
                        <template v-if="!capabilities.integrationAppInstalled">{{ t('teamhub', 'Not installed') }}</template>
                        <template v-else-if="!capabilities.integrationAppEnabled">{{ t('teamhub', 'Installed, but disabled') }}</template>
                        <template v-else>{{ t('teamhub', 'Enabled') }}</template>
                    </dd>
                </div>
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'OpenProject host') }}</dt>
                    <dd>
                        <StatusMark :ok="capabilities.hostConfigured" />
                        <a
                            v-if="capabilities.host"
                            :href="capabilities.host"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="op-panel__host"
                            :title="t('teamhub', 'Opens in OpenProject')">{{ capabilities.host }}</a>
                        <template v-else>{{ t('teamhub', 'Not configured') }}</template>
                    </dd>
                </div>
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'Your account') }}</dt>
                    <dd>
                        <StatusMark :ok="capabilities.userConnected" :unknown="!capabilities.hostConfigured" />
                        <template v-if="capabilities.userConnected && capabilities.openProjectUser">
                            {{ t('teamhub', 'Connected as {name}', { name: capabilities.openProjectUser.name }) }}
                        </template>
                        <template v-else-if="capabilities.userConnected">{{ t('teamhub', 'Connected') }}</template>
                        <template v-else>
                            {{ t('teamhub', 'Not connected') }}
                            <a v-if="capabilities.hostConfigured" :href="personalSettingsUrl()" class="op-panel__inline-link">
                                {{ t('teamhub', 'Connect OpenProject account') }}
                            </a>
                        </template>
                    </dd>
                </div>
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'Connection health') }}</dt>
                    <dd>
                        <StatusMark :ok="capabilities.apiReachable === true" :unknown="capabilities.apiReachable === null || capabilities.apiReachable === undefined" />
                        <template v-if="capabilities.apiReachable === true">{{ t('teamhub', 'OpenProject answered') }}</template>
                        <template v-else-if="capabilities.apiReachable === false">{{ t('teamhub', 'OpenProject could not be reached') }}</template>
                        <template v-else>{{ t('teamhub', 'Not tested yet') }}</template>
                        <span v-if="testedAt" class="op-panel__muted">
                            {{ t('teamhub', 'Tested {time}', { time: formatDateTime(testedAt * 1000) }) }}
                        </span>
                    </dd>
                </div>
                <div class="op-panel__row">
                    <dt>{{ t('teamhub', 'Available in TeamHub') }}</dt>
                    <dd class="op-panel__caps">
                        <span class="op-panel__chip" :class="capChipClass(capabilities.projectReadAvailable)">{{ t('teamhub', 'Project overview') }}</span>
                        <span class="op-panel__chip" :class="capChipClass(capabilities.workPackageReadAvailable)">{{ t('teamhub', 'Work packages') }}</span>
                        <span class="op-panel__chip" :class="capChipClass(testedProject ? testedProject.canCreateWorkPackage : null)" :title="t('teamhub', 'Answered per project by Test connection')">{{ t('teamhub', 'Create work packages') }}</span>
                        <!-- v4.9.6 — Phase 2 answers this from the probe: may this admin create projects. -->
                        <span class="op-panel__chip" :class="capChipClass(capabilities.probed ? capabilities.provisioningAvailable : null)" :title="t('teamhub', 'Answered by Test connection')">{{ t('teamhub', 'Project provisioning') }}</span>
                    </dd>
                </div>
            </dl>

            <!-- v4.9.6 — membership on both sides. TeamHub initiated the
                 memberships when the workspace was created; OpenProject is
                 authoritative afterwards. This compares the two and offers
                 one explicit, one-way action: add to the project the team
                 members who are missing there. Nothing is ever removed from
                 OpenProject here, and a role changed there is reported, not
                 reverted. -->
            <section v-if="link" class="op-panel__members">
                <h4 class="op-panel__subtitle">{{ t('teamhub', 'Members in OpenProject') }}</h4>
                <p class="op-panel__desc">
                    {{ t('teamhub', 'Compares this team\'s members with the project\'s members in OpenProject. Members can be added to the project from here; nothing is ever removed from OpenProject by TeamHub.') }}
                </p>
                <div class="op-panel__actions">
                    <NcButton variant="secondary" :disabled="busy || !capabilities.userConnected" @click="loadDrift">
                        <template #icon>
                            <NcLoadingIcon v-if="driftLoading" :size="ICON_BODY" />
                            <AccountMultipleCheck v-else :size="ICON_BODY" />
                        </template>
                        {{ t('teamhub', 'Compare with OpenProject') }}
                    </NcButton>
                    <NcButton
                        v-if="drift && drift.missingInOpenProject.length"
                        variant="primary"
                        :disabled="busy"
                        @click="syncMembers">
                        <template #icon>
                            <NcLoadingIcon v-if="syncing" :size="ICON_BODY" />
                            <AccountPlus v-else :size="ICON_BODY" />
                        </template>
                        {{ n('teamhub', 'Add {n} missing member to OpenProject', 'Add {n} missing members to OpenProject', drift.missingInOpenProject.length, { n: drift.missingInOpenProject.length }) }}
                    </NcButton>
                </div>
                <p v-if="driftError" class="op-panel__problem-text op-panel__error" role="alert">{{ driftError }}</p>
                <template v-else-if="drift">
                    <p class="op-panel__muted">
                        {{ n('teamhub', '{n} member is in step on both sides.', '{n} members are in step on both sides.', drift.inSync, { n: drift.inSync }) }}
                        {{ t('teamhub', 'Checked {time}.', { time: formatDateTime(drift.checkedAt * 1000) }) }}
                    </p>
                    <ul v-if="driftRows.length" class="op-panel__drift" :aria-label="t('teamhub', 'Differences')">
                        <li v-for="row in driftRows" :key="row.key" class="op-panel__drift-row">
                            <span class="op-panel__chip" :class="row.chip">{{ row.kind }}</span>
                            <span class="op-panel__drift-name">{{ row.name }}</span>
                            <span class="op-panel__muted">{{ row.note }}</span>
                        </li>
                    </ul>
                    <p v-else class="op-panel__muted">{{ t('teamhub', 'No differences.') }}</p>
                </template>
            </section>

            <div v-if="problem" class="op-panel__problem" role="alert">
                <AlertCircleOutline :size="ICON_BODY" aria-hidden="true" />
                <div>
                    <p class="op-panel__problem-text">{{ problem.message }}</p>
                    <p v-if="problem.administratorMessage" class="op-panel__problem-admin">
                        <span class="op-panel__problem-label">{{ t('teamhub', 'For administrators') }}</span>
                        {{ problem.administratorMessage }}
                    </p>
                </div>
            </div>

            <div class="op-panel__actions">
                <NcButton
                    variant="secondary"
                    :disabled="busy || !capabilities.integrationAppEnabled"
                    @click="testConnection">
                    <template #icon>
                        <NcLoadingIcon v-if="testing" :size="ICON_BODY" />
                        <Refresh v-else :size="ICON_BODY" />
                    </template>
                    {{ t('teamhub', 'Test connection') }}
                </NcButton>
            </div>
        </template>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AccountMultipleCheck from 'vue-material-design-icons/AccountMultipleCheck.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import StatusMark from './OpenProjectStatusMark.vue'
import { ICON_BODY, ICON_TOOLBAR, ICON_NAV } from '../constants/uiTokens.js'
import { formatDateTime } from '../lib/localDate.js'
import { classifyError, personalSettingsUrl } from '../lib/openProject.js'

/**
 * OpenProjectSettingsPanel (v4.9.3, OpenProject Phase 1).
 *
 * Manage team → Modules & integrations → OpenProject, for teams created
 * from the OpenProject template. **Read-only** (Justin, 2026-09-12): the
 * project is chosen once, in the creation wizard, and this panel shows what
 * was chosen — the linked project, the integration's state for *this* admin
 * (the official app, the host, their own account), and a "Test connection"
 * diagnostic. There is no change and no remove here.
 */
export default {
    name: 'OpenProjectSettingsPanel',

    components: {
        NcButton, NcLoadingIcon,
        BriefcaseOutline, Refresh, AlertCircleOutline, AccountMultipleCheck, AccountPlus,
        StatusMark,
    },

    props: {
        teamId: { type: String, required: true },
    },

    data() {
        return {
            loading: true,
            busy: false,
            testing: false,
            link: null,
            capabilities: {},
            problem: null,
            testedAt: null,
            testedProject: null,
            // v4.9.6 — the membership comparison, once asked for.
            drift: null,
            driftLoading: false,
            driftError: '',
            syncing: false,
            ICON_BODY,
            ICON_TOOLBAR,
            ICON_NAV,
        }
    },

    computed: {
        /** The differences as rows: missing here, extra there, a role that differs, no account. */
        driftRows() {
            if (!this.drift) return []
            const rows = []
            for (const m of this.drift.missingInOpenProject) {
                rows.push({ key: 'm:' + m.id, kind: t('teamhub', 'Not in the project'), chip: 'op-panel__chip--warning', name: m.displayName, note: t('teamhub', 'would get {role}', { role: m.openProjectRole?.name || '' }) })
            }
            for (const r of this.drift.roleDrift) {
                rows.push({ key: 'r:' + r.id, kind: t('teamhub', 'Different role'), chip: 'op-panel__chip--unknown', name: r.displayName, note: t('teamhub', 'has {current} in OpenProject, {expected} expected', { current: r.currentRoles.map(x => x.name).join(', '), expected: r.expectedRole?.name || '' }) })
            }
            for (const e of this.drift.extraInOpenProject) {
                rows.push({ key: 'e:' + e.membershipId, kind: t('teamhub', 'Only in OpenProject'), chip: 'op-panel__chip--unknown', name: e.principal?.name || '', note: e.roles.map(x => x.name).join(', ') })
            }
            for (const u of this.drift.unmatched) {
                rows.push({ key: 'u:' + u.id, kind: t('teamhub', 'No OpenProject account'), chip: 'op-panel__chip--off', name: u.displayName, note: '' })
            }
            return rows
        },
    },

    watch: {
        teamId() {
            this.load()
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t, n, formatDateTime, personalSettingsUrl,

        api(path) {
            return generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/openproject${path}`)
        },

        async load() {
            this.loading = true
            try {
                const { data } = await axios.get(this.api('/link'))
                this.link = data.link
                this.capabilities = data.capabilities || {}
                this.problem = this.problemFromCapabilities(this.capabilities)
            } catch (e) {
                this.problem = classifyError(e)
            } finally {
                this.loading = false
            }
        },

        /** The capability array's own error, in the panel's problem shape. */
        problemFromCapabilities(caps) {
            if (!caps || !caps.errorCode) return null
            return {
                code: caps.errorCode,
                message: caps.userMessage,
                administratorMessage: caps.administratorMessage,
            }
        },

        capChipClass(value) {
            if (value === null || value === undefined) return 'op-panel__chip--unknown'
            return value ? 'op-panel__chip--on' : 'op-panel__chip--off'
        },

        async testConnection() {
            this.testing = true
            this.busy = true
            try {
                const { data } = await axios.post(this.api('/test'))
                this.capabilities = data.capabilities || {}
                this.link = data.link
                this.testedAt = data.testedAt
                this.testedProject = data.project
                this.problem = data.projectError || this.problemFromCapabilities(this.capabilities)
                if (!this.problem) {
                    showSuccess(t('teamhub', 'OpenProject connection works'))
                }
            } catch (e) {
                this.problem = classifyError(e)
            } finally {
                this.testing = false
                this.busy = false
            }
        },

        // v4.9.6 — membership on both sides.
        async loadDrift() {
            this.driftLoading = true
            this.busy = true
            this.driftError = ''
            try {
                const { data } = await axios.get(this.api('/membership-drift'))
                this.drift = data
            } catch (e) {
                this.driftError = classifyError(e).message
            } finally {
                this.driftLoading = false
                this.busy = false
            }
        },

        async syncMembers() {
            this.syncing = true
            this.busy = true
            try {
                const { data } = await axios.post(this.api('/membership-sync'))
                this.drift = data.drift
                const added = Array.isArray(data.added) ? data.added.length : 0
                const refused = data.refused ? Object.keys(data.refused).length : 0
                if (refused > 0) {
                    showError(n('teamhub', 'OpenProject refused {n} member.', 'OpenProject refused {n} members.', refused, { n: refused }))
                } else {
                    showSuccess(n('teamhub', 'Added {n} member to the project.', 'Added {n} members to the project.', added, { n: added }))
                }
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.syncing = false
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.op-panel__title {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: 0 0 var(--th-space-lg);
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.op-panel__desc {
    margin: calc(-1 * var(--th-space-sm)) 0 var(--th-space-lg);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.op-panel__loading {
    padding: var(--th-space-md) 0;
}

.op-panel__status {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    margin: 0 0 var(--th-space-lg);
}

.op-panel__row {
    display: grid;
    grid-template-columns: minmax(0, 12rem) minmax(0, 1fr);
    gap: var(--th-space-md);
    align-items: baseline;
}

.op-panel__row dt {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
}

.op-panel__row dd {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-xs) var(--th-space-sm);
    margin: 0;
    min-width: 0;
    font-size: var(--th-font-body);
}

.op-panel__host,
.op-panel__inline-link {
    color: var(--color-primary-element);
    text-decoration: underline;
    overflow-wrap: anywhere;
}

.op-panel__host:focus-visible,
.op-panel__inline-link:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--th-radius-control);
}

.op-panel__muted {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.op-panel__caps {
    gap: var(--th-space-xs);
}

.op-panel__chip {
    display: inline-flex;
    align-items: center;
    padding: 0 var(--th-space-sm);
    border-radius: var(--th-radius-chip);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-relaxed);
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

.op-panel__chip--on      { background: var(--color-success); color: var(--color-success-text); }
.op-panel__chip--off     { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.op-panel__chip--unknown { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.op-panel__chip--warning { background: var(--color-warning); color: var(--color-warning-text); }

.op-panel__problem {
    display: flex;
    gap: var(--th-space-sm);
    align-items: flex-start;
    padding: var(--th-space-sm) var(--th-space-md);
    margin: 0 0 var(--th-space-lg);
    border-radius: var(--th-radius-control);
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.op-panel__problem-text {
    margin: 0;
}

.op-panel__problem-admin {
    margin: var(--th-space-xs) 0 0;
    font-size: var(--th-font-meta);
}

.op-panel__problem-label {
    font-weight: var(--th-font-weight-semibold);
    margin-inline-end: var(--th-space-xs);
}

/* v4.9.6 — the membership comparison. */
.op-panel__members {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
    margin-top: var(--th-space-lg);
    padding-top: var(--th-space-lg);
    border-top: 1px solid var(--color-border);
}

.op-panel__subtitle {
    margin: 0;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-semibold);
}

.op-panel__error {
    color: var(--color-error-text);
}

.op-panel__drift {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.op-panel__drift-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-sm);
    font-size: var(--th-font-meta);
}

.op-panel__drift-name {
    font-weight: var(--th-font-weight-medium);
}

.op-panel__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}
</style>
