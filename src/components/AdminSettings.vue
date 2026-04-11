<template>
    <div class="teamhub-admin">

        <!-- Tab bar -->
        <div class="teamhub-admin-tabs" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                role="tab"
                class="teamhub-admin-tab"
                :class="{ 'teamhub-admin-tab--active': activeTab === tab.id }"
                :aria-selected="activeTab === tab.id"
                :aria-controls="'tab-panel-' + tab.id"
                @click="activeTab = tab.id">
                <component :is="tab.icon" :size="18" />
                {{ tab.label }}
            </button>
        </div>

        <!-- ── Tab: Team creation ─────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'creation'"
            id="tab-panel-creation"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Team creation wizard')"
                :description="t('teamhub', 'This text is shown at the top of the Create new team dialog. Leave empty to show no description.')">
                <NcTextArea
                    v-model="form.wizardDescription"
                    :label="t('teamhub', 'Wizard introduction text')"
                    :placeholder="t('teamhub', 'e.g. Fill in the details below to create a new team.')"
                    :rows="3" />
            </NcSettingsSection>

            <NcSettingsSection
                :name="t('teamhub', 'Creation permissions')"
                :description="t('teamhub', 'Only members of the selected groups can create teams. Leave empty to allow all users.')">

                <!-- Selected group chips -->
                <div v-if="selectedGroups.length" class="admin-group-chips">
                    <span
                        v-for="g in selectedGroups"
                        :key="g.id"
                        class="admin-group-chip">
                        <AccountGroup :size="14" />
                        {{ g.displayName }}
                        <button
                            class="admin-group-chip__remove"
                            :aria-label="t('teamhub', 'Remove {name}', { name: g.displayName })"
                            @click="removeGroup(g)">
                            ×
                        </button>
                    </span>
                </div>

                <!-- Group typeahead search -->
                <div class="admin-group-search">
                    <NcTextField
                        v-model="groupQuery"
                        :label="t('teamhub', 'Search for a group')"
                        :placeholder="t('teamhub', 'Type to search groups…')"
                        @input="onGroupSearch" />

                    <ul v-if="groupResults.length" class="admin-group-results">
                        <li
                            v-for="g in groupResults"
                            :key="g.id"
                            class="admin-group-result"
                            @mousedown.prevent="addGroup(g)">
                            <AccountGroup :size="18" />
                            <span class="admin-group-result__name">{{ g.displayName }}</span>
                            <span class="admin-group-result__id">{{ g.id }}</span>
                        </li>
                    </ul>
                    <p v-else-if="groupSearching" class="admin-group-hint">
                        <NcLoadingIcon :size="16" /> {{ t('teamhub', 'Searching…') }}
                    </p>
                    <p v-else-if="groupQuery.length >= 1 && !groupSearching" class="admin-group-hint">
                        {{ t('teamhub', 'No groups found') }}
                    </p>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Invitations ───────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'invitations'"
            id="tab-panel-invitations"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Allowed invite types')"
                :description="t('teamhub', 'Choose which types of accounts team admins can invite to a team.')">
                <div class="admin-checks">
                    <NcCheckboxRadioSwitch
                        :checked="true"
                        :disabled="true"
                        type="checkbox">
                        {{ t('teamhub', 'Local users') }}
                        <template #description>{{ t('teamhub', 'Always enabled — local Nextcloud accounts') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteGroup"
                        type="checkbox">
                        {{ t('teamhub', 'Groups') }}
                        <template #description>{{ t('teamhub', 'Add all members of a Nextcloud group at once') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteEmail"
                        type="checkbox">
                        {{ t('teamhub', 'Email addresses') }}
                        <template #description>{{ t('teamhub', 'Invite external people by email (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteFederated"
                        type="checkbox">
                        {{ t('teamhub', 'Federated users') }}
                        <template #description>{{ t('teamhub', 'Invite users from other Nextcloud instances (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Messages ─────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'messages'"
            id="tab-panel-messages"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Pin messages')"
                :description="t('teamhub', 'Minimum member role required to pin or unpin a message. One message can be pinned per team at a time.')">
                <div class="admin-select-row">
                    <label for="teamhub-pin-level" class="admin-select-label">
                        {{ t('teamhub', 'Minimum role to pin') }}
                    </label>
                    <select
                        id="teamhub-pin-level"
                        v-model="form.pinMinLevel"
                        class="admin-select">
                        <option value="member">{{ t('teamhub', 'Member') }}</option>
                        <option value="moderator">{{ t('teamhub', 'Moderator') }}</option>
                        <option value="admin">{{ t('teamhub', 'Admin / Owner') }}</option>
                    </select>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Integrations ─────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'integrations'"
            id="tab-panel-integrations"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Registered integrations')"
                :description="t('teamhub', 'Integrations registered by installed apps via the TeamHub API. Registration and deregistration require NC admin access and are done via the REST API or the app\'s own settings.')">

                <div v-if="integrationsLoading" class="admin-integrations-loading">
                    <NcLoadingIcon :size="24" />
                    <span>{{ t('teamhub', 'Loading integrations…') }}</span>
                </div>

                <div v-else-if="integrationsError" class="admin-integrations-error">
                    {{ integrationsError }}
                </div>

                <!--
                    Only show EXTERNAL (non-builtin) integrations.
                    Built-in NC apps (Talk, Files, Calendar, Deck) are seeded into
                    the registry automatically and did not register via the API.
                    They are not third-party integrations and must not appear here.
                -->
                <div v-else-if="externalIntegrations.length === 0" class="admin-integrations-empty">
                    {{ t('teamhub', 'No third-party integrations registered yet.') }}
                </div>

                <div v-else class="admin-integrations-list">
                    <div
                        v-for="item in externalIntegrations"
                        :key="item.id"
                        class="admin-integration-row">

                        <div class="admin-integration-row__body">
                            <div class="admin-integration-row__header">
                                <!-- App icon — svg → png → hide fallback -->
                                <img
                                    :src="appIconUrl(item.app_id)"
                                    :alt="item.app_id"
                                    class="admin-integration-row__icon"
                                    @error="onAppIconError($event, item)" />
                                <span class="admin-integration-row__title">{{ item.title }}</span>
                                <span class="admin-integration-row__appid">{{ item.app_id }}</span>
                                <span
                                    class="admin-integration-row__badge"
                                    :class="'admin-integration-row__badge--' + item.integration_type">
                                    {{ item.integration_type === 'widget' ? t('teamhub', 'Widget') : t('teamhub', 'Tab') }}
                                </span>
                            </div>
                            <div v-if="item.description" class="admin-integration-row__desc">
                                {{ item.description }}
                            </div>
                            <div class="admin-integration-row__urls">
                                <span v-if="item.data_url">
                                    <strong>{{ t('teamhub', 'Data URL:') }}</strong> {{ item.data_url }}
                                </span>
                                <span v-if="item.iframe_url">
                                    <strong>{{ t('teamhub', 'iFrame URL:') }}</strong> {{ item.iframe_url }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Statistics ───────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'statistics'"
            id="tab-panel-statistics"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Usage statistics')"
                :description="t('teamhub', 'TeamHub can send anonymous usage data to help improve the app. No URLs, hostnames, or user data are ever included — only an anonymous UUID and aggregate counts.')">

                <div v-if="telemetryLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <template v-else>
                    <NcCheckboxRadioSwitch
                        :checked="telemetry.enabled"
                        type="switch"
                        @update:checked="toggleTelemetry">
                        {{ t('teamhub', 'Send daily anonymous usage report') }}
                    </NcCheckboxRadioSwitch>

                    <div v-if="telemetry.enabled" class="admin-telemetry-details">
                        <p class="admin-section-hint">
                            {{ t('teamhub', 'Reports are sent once per day to:') }}
                            <code>{{ telemetry.report_url }}</code>
                        </p>
                        <p class="admin-section-hint">{{ t('teamhub', 'Preview of what will be sent:') }}</p>
                        <pre class="admin-telemetry-preview">{{ JSON.stringify(telemetry.preview, null, 2) }}</pre>
                    </div>
                    <p v-else class="admin-section-hint">
                        {{ t('teamhub', 'Usage reporting is disabled. No data is sent.') }}
                    </p>
                </template>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Maintenance ──────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'maintenance'"
            id="tab-panel-maintenance"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Orphaned teams')"
                :description="t('teamhub', 'Teams that have no owner, usually because the owner account was deleted. You can delete these teams or assign a new owner.')">

                <div v-if="orphanedLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <div v-else-if="orphanedError" class="admin-error">
                    {{ orphanedError }}
                </div>
                <div v-else-if="orphanedTeams.length === 0" class="admin-empty">
                    {{ t('teamhub', 'No orphaned teams found.') }}
                </div>
                <div v-else class="admin-orphan-list">
                    <div
                        v-for="team in orphanedTeams"
                        :key="team.id"
                        class="admin-orphan-row">
                        <div class="admin-orphan-info">
                            <span class="admin-orphan-name">{{ team.name || team.raw_name }}</span>
                            <span class="admin-orphan-meta">
                                {{ t('teamhub', '{n} members', { n: team.member_count }) }}
                                <template v-if="team.description"> · {{ team.description }}</template>
                            </span>
                            <span class="admin-orphan-meta admin-orphan-meta--id">
                                <code class="admin-orphan-id">{{ team.id }}</code>
                            </span>
                        </div>

                        <!-- Owner assignment inline form -->
                        <div v-if="assignTeamId === team.id" class="admin-assign-owner">
                            <NcTextField
                                v-model="ownerQuery"
                                :label="t('teamhub', 'Search user')"
                                :placeholder="t('teamhub', 'Type a username or display name…')"
                                @input="onOwnerSearch" />
                            <ul v-if="ownerResults.length" class="admin-owner-results">
                                <li
                                    v-for="u in ownerResults"
                                    :key="u.uid"
                                    class="admin-owner-result"
                                    @mousedown.prevent="confirmAssignOwner(team, u)">
                                    {{ u.displayName }} <span class="admin-owner-result__uid">({{ u.uid }})</span>
                                </li>
                            </ul>
                            <p v-else-if="ownerSearching" class="admin-section-hint">
                                <NcLoadingIcon :size="14" /> {{ t('teamhub', 'Searching…') }}
                            </p>
                            <NcButton type="tertiary" @click="cancelAssign">
                                {{ t('teamhub', 'Cancel') }}
                            </NcButton>
                        </div>

                        <div v-else class="admin-orphan-actions">
                            <NcButton
                                type="secondary"
                                :disabled="assigningOwner"
                                @click="startAssignOwner(team)">
                                <template #icon><AccountEditIcon :size="18" /></template>
                                {{ t('teamhub', 'Assign owner') }}
                            </NcButton>
                            <NcButton
                                type="error"
                                :disabled="deletingTeam === team.id"
                                @click="confirmDeleteOrphan(team)">
                                <template #icon>
                                    <NcLoadingIcon v-if="deletingTeam === team.id" :size="18" />
                                    <DeleteIcon v-else :size="18" />
                                </template>
                                {{ t('teamhub', 'Delete') }}
                            </NcButton>
                        </div>
                    </div>
                </div>

                <NcButton
                    type="tertiary"
                    class="admin-orphan-refresh"
                    :disabled="orphanedLoading"
                    @click="loadOrphanedTeams">
                    <template #icon><NcLoadingIcon v-if="orphanedLoading" :size="18" /></template>
                    {{ t('teamhub', 'Refresh') }}
                </NcButton>
            </NcSettingsSection>
        </div>

        <!-- ── Save row — only for settings tabs, not statistics/maintenance ─ -->
        <div v-show="!(['statistics','maintenance'].includes(activeTab))" class="admin-save-row">
            <NcButton
                type="primary"
                :disabled="saving"
                @click="save">
                <template #icon>
                    <NcLoadingIcon v-if="saving" :size="18" />
                    <ContentSave v-else :size="18" />
                </template>
                {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save settings') }}
            </NcButton>
            <span v-if="saved" class="admin-save-ok">✓ {{ t('teamhub', 'Settings saved') }}</span>
            <span v-if="saveError" class="admin-save-err">{{ saveError }}</span>
        </div>

        <!-- ── Delete orphan confirmation dialog ─────────────────────── -->
        <NcDialog
            v-if="confirmDeleteDialog && confirmDeleteTeam"
            :name="t('teamhub', 'Delete team')"
            :open="confirmDeleteDialog"
            @update:open="cancelDeleteOrphan">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Delete "{name}" and all its data? This cannot be undone.', { name: confirmDeleteTeam.name || confirmDeleteTeam.id }) }}
                </p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="cancelDeleteOrphan">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    type="error"
                    :disabled="deletingTeam === confirmDeleteTeam.id"
                    @click="executeDeleteOrphan">
                    <template #icon>
                        <NcLoadingIcon v-if="deletingTeam === confirmDeleteTeam.id" :size="18" />
                        <DeleteIcon v-else :size="18" />
                    </template>
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
import {
    NcSettingsSection, NcButton, NcLoadingIcon,
    NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import EmailSendIcon from 'vue-material-design-icons/EmailArrowRight.vue'
import MessageTextIcon from 'vue-material-design-icons/MessageText.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AccountEditIcon from 'vue-material-design-icons/AccountEdit.vue'

export default {
    name: 'AdminSettings',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon,
        NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
        ContentSave, AccountGroup, AccountPlusIcon, EmailSendIcon, MessageTextIcon, PuzzleIcon,
        ChartBarIcon, WrenchIcon, DeleteIcon, AccountEditIcon,
    },
    data() {
        return {
            activeTab: 'creation',
            loading: true,
            saving: false,
            saved: false,
            saveError: null,
            form: {
                wizardDescription: '',
                pinMinLevel: 'moderator',
            },
            // Invite type toggles
            inviteGroup: true,
            inviteEmail: false,
            inviteFederated: false,
            // Group picker
            selectedGroups: [],
            groupQuery: '',
            groupResults: [],
            groupSearching: false,
            groupSearchTimer: null,
            // Integrations tab
            integrations: [],
            integrationsLoading: false,
            integrationsError: null,
            // Statistics tab
            telemetry: { enabled: true, report_url: '', preview: {} },
            telemetryLoading: false,
            telemetrySaving: false,
            // Maintenance tab
            orphanedTeams: [],
            orphanedLoading: false,
            orphanedError: null,
            deletingTeam: null,
            // Delete confirmation dialog
            confirmDeleteDialog: false,
            confirmDeleteTeam: null,
            // Owner assignment
            assignTeamId: null,
            ownerQuery: '',
            ownerResults: [],
            ownerSearching: false,
            ownerSearchTimer: null,
            assigningOwner: false,
        }
    },
    computed: {
        tabs() {
            return [
                { id: 'creation',      label: this.t('teamhub', 'Team creation'), icon: 'AccountPlusIcon' },
                { id: 'invitations',   label: this.t('teamhub', 'Invitations'),   icon: 'EmailSendIcon'   },
                { id: 'messages',      label: this.t('teamhub', 'Messages'),       icon: 'MessageTextIcon' },
                { id: 'integrations',  label: this.t('teamhub', 'Integrations'),  icon: 'PuzzleIcon'      },
                { id: 'statistics',    label: this.t('teamhub', 'Statistics'),    icon: 'ChartBarIcon'    },
                { id: 'maintenance',   label: this.t('teamhub', 'Maintenance'),   icon: 'WrenchIcon'      },
            ]
        },

        /**
         * Only external (non-builtin) integrations.
         * Built-in NC apps (Talk, Files, Calendar, Deck) are seeded automatically
         * into the registry as is_builtin=true. They did NOT register via the
         * integration API and must not appear in this admin list.
         */
        externalIntegrations() {
            return this.integrations.filter(i => !i.is_builtin)
        },
    },
    watch: {
        activeTab(tab) {
            if (tab === 'integrations' && this.integrations.length === 0 && !this.integrationsLoading) {
                this.loadIntegrations()
            }
            if (tab === 'statistics' && !this.telemetryLoading && !this.telemetry.preview.uuid) {
                this.loadTelemetry()
            }
            if (tab === 'maintenance' && !this.orphanedLoading && this.orphanedTeams.length === 0 && !this.orphanedError) {
                this.loadOrphanedTeams()
            }
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        t(app, str, vars) {
            if (window.t) return window.t(app, str, vars)
            if (vars) return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
            return str
        },

        async load() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.form.wizardDescription = data.wizardDescription || ''
                this.form.pinMinLevel       = data.pinMinLevel        || 'moderator'

                const types = (data.inviteTypes || 'user,group').split(',').map(s => s.trim())
                this.inviteGroup     = types.includes('group')
                this.inviteEmail     = types.includes('email')
                this.inviteFederated = types.includes('federated')

                this.selectedGroups = Array.isArray(data.createTeamGroups) ? data.createTeamGroups : []
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to load settings')
            } finally {
                this.loading = false
            }
        },

        // ── Integrations tab ──────────────────────────────────────────────

        async loadIntegrations() {
            this.integrationsLoading = true
            this.integrationsError = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/ext/integrations'))
                this.integrations = Array.isArray(data) ? data : []
            } catch (e) {
                const msg = e?.response?.data?.error || e.message || 'unknown error'
                this.integrationsError = this.t('teamhub', 'Failed to load integrations: {error}', { error: msg })
            } finally {
                this.integrationsLoading = false
            }
        },

        /**
         * NC app icon URL — /apps/{app_id}/img/app.svg
         * Mirrors TeamView.appIconUrl() and IntegrationWidget.appIconUrl().
         */
        appIconUrl(appId) {
            return generateUrl(`/apps/${appId}/img/app.svg`)
        },

        /**
         * Fallback: svg → png → hide.
         * We store the app_id on the img via data attribute so we can track
         * which fallback stage we are in without extra component state.
         */
        onAppIconError(event, item) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = generateUrl(`/apps/${item.app_id}/img/app.png`)
            } else {
                // Both svg and png failed — hide the img entirely
                img.style.display = 'none'
            }
        },

        // ── Group picker ──────────────────────────────────────────────────

        onGroupSearch() {
            clearTimeout(this.groupSearchTimer)
            this.groupResults = []
            if (this.groupQuery.length < 1) {
                this.groupSearching = false
                return
            }
            this.groupSearching = true
            this.groupSearchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/admin/groups/search'),
                        { params: { q: this.groupQuery } }
                    )
                    const selectedIds = new Set(this.selectedGroups.map(g => g.id))
                    this.groupResults = (Array.isArray(data) ? data : [])
                        .filter(g => !selectedIds.has(g.id))
                } catch {
                    this.groupResults = []
                } finally {
                    this.groupSearching = false
                }
            }, 250)
        },

        addGroup(group) {
            if (!this.selectedGroups.find(g => g.id === group.id)) {
                this.selectedGroups.push(group)
            }
            this.groupQuery   = ''
            this.groupResults = []
        },

        removeGroup(group) {
            this.selectedGroups = this.selectedGroups.filter(g => g.id !== group.id)
        },

        // ── Save ─────────────────────────────────────────────────────────

        async save() {
            this.saving    = true
            this.saved     = false
            this.saveError = null

            const types = ['user']
            if (this.inviteGroup)     types.push('group')
            if (this.inviteEmail)     types.push('email')
            if (this.inviteFederated) types.push('federated')

            const groupIds = JSON.stringify(this.selectedGroups.map(g => g.id))

            const params = new URLSearchParams()
            params.set('wizardDescription', this.form.wizardDescription)
            params.set('createTeamGroup',   groupIds)
            params.set('pinMinLevel',        this.form.pinMinLevel)
            params.set('inviteTypes',        types.join(','))

            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/settings'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.saved = true
                setTimeout(() => { this.saved = false }, 3000)
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to save settings')
            } finally {
                this.saving = false
            }
        },

        // ------------------------------------------------------------------
        // Statistics / telemetry
        // ------------------------------------------------------------------

        async loadTelemetry() {
            this.telemetryLoading = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/telemetry'))
                this.telemetry = data
            } catch (e) {
            } finally {
                this.telemetryLoading = false
            }
        },

        async toggleTelemetry(enabled) {
            this.telemetrySaving = true
            try {
                const params = new URLSearchParams()
                params.set('enabled', enabled ? '1' : '0')
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/admin/telemetry'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.telemetry.enabled = enabled
            } catch (e) {
            } finally {
                this.telemetrySaving = false
            }
        },

        // ------------------------------------------------------------------
        // Maintenance — orphaned teams
        // ------------------------------------------------------------------

        async loadOrphanedTeams() {
            this.orphanedLoading = true
            this.orphanedError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/orphaned-teams')
                )
                this.orphanedTeams = Array.isArray(data) ? data : []
            } catch (e) {
                this.orphanedError = this.t('teamhub', 'Failed to load orphaned teams')
            } finally {
                this.orphanedLoading = false
            }
        },

        confirmDeleteOrphan(team) {
            this.confirmDeleteTeam = team
            this.confirmDeleteDialog = true
        },

        cancelDeleteOrphan() {
            this.confirmDeleteDialog = false
            this.confirmDeleteTeam = null
        },

        async executeDeleteOrphan() {
            if (!this.confirmDeleteTeam) return
            const team = this.confirmDeleteTeam
            this.deletingTeam = team.id
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/orphaned-teams/${team.id}`)
                )
                this.orphanedTeams = this.orphanedTeams.filter(t => t.id !== team.id)
                this.cancelDeleteOrphan()
                showSuccess(this.t('teamhub', 'Team deleted successfully'))
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(this.t('teamhub', 'Failed to delete team') + (msg ? ': ' + msg : ''))
            } finally {
                this.deletingTeam = null
            }
        },

        startAssignOwner(team) {
            this.assignTeamId  = team.id
            this.ownerQuery    = ''
            this.ownerResults  = []
        },

        cancelAssign() {
            this.assignTeamId  = null
            this.ownerQuery    = ''
            this.ownerResults  = []
        },

        onOwnerSearch() {
            clearTimeout(this.ownerSearchTimer)
            if (this.ownerQuery.length < 1) {
                this.ownerResults = []
                return
            }
            this.ownerSearching = true
            this.ownerSearchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/admin/users/search'),
                        { params: { q: this.ownerQuery } }
                    )
                    this.ownerResults = Array.isArray(data) ? data : []
                } catch (e) {
                    this.ownerResults = []
                } finally {
                    this.ownerSearching = false
                }
            }, 300)
        },

        async confirmAssignOwner(team, user) {
            this.ownerResults  = []
            this.assigningOwner = true
            try {
                const params = new URLSearchParams()
                params.set('userId', user.uid)
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/orphaned-teams/${team.id}/assign-owner`),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                // Remove from orphaned list — team now has an owner
                this.orphanedTeams = this.orphanedTeams.filter(t => t.id !== team.id)
                this.cancelAssign()
                showSuccess(this.t('teamhub', 'Owner assigned successfully'))
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(this.t('teamhub', 'Failed to assign owner') + (msg ? ': ' + msg : ''))
            } finally {
                this.assigningOwner = false
            }
        },
    },
}
</script>

<style scoped>
/* ── Wrapper ─────────────────────────────────────────────────────────────── */
.teamhub-admin {
    display: flex;
    flex-direction: column;
}

/* ── Tab bar ─────────────────────────────────────────────────────────────── */
.teamhub-admin-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 0 16px 0;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 8px;
}

.teamhub-admin-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;       /* overlaps the tab bar border-bottom */
    cursor: pointer;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
    white-space: nowrap;
}

.teamhub-admin-tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.teamhub-admin-tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: 600;
}

/* ── Tab panels ──────────────────────────────────────────────────────────── */
.teamhub-admin-panel {
    padding-top: 8px;
}

/* ── Group chips ─────────────────────────────────────────────────────────── */
.admin-group-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 10px;
}

.admin-group-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: var(--color-primary-element-light);
    border: 1px solid var(--color-primary-element);
    border-radius: var(--border-radius-pill);
    font-size: 13px;
    font-weight: 500;
}

.admin-group-chip__remove {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    color: var(--color-text-maxcontrast);
    padding: 0 2px;
    margin-left: 2px;
}

.admin-group-chip__remove:hover {
    color: var(--color-error);
}

/* ── Group typeahead ─────────────────────────────────────────────────────── */
.admin-group-search {
    position: relative;
    max-width: 400px;
}

.admin-group-results {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 100;
    list-style: none;
    padding: 4px 0;
    margin: 0;
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius-large);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    max-height: 220px;
    overflow-y: auto;
}

.admin-group-result {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.admin-group-result:hover {
    background: var(--color-background-hover);
}

.admin-group-result__name {
    font-size: 14px;
    font-weight: 500;
    flex: 1;
}

.admin-group-result__id {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.admin-group-hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 0;
    margin: 0;
}

/* ── Invite type checkboxes ──────────────────────────────────────────────── */
.admin-checks {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

/* ── Pin level select ────────────────────────────────────────────────────── */
.admin-select-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.admin-select-label {
    font-size: 14px;
    font-weight: 500;
    min-width: 180px;
}

.admin-select {
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border-maxcontrast);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    min-width: 180px;
    cursor: pointer;
}

.admin-select:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

/* ── Integrations list ───────────────────────────────────────────────────── */
.admin-integrations-loading,
.admin-integrations-error,
.admin-integrations-empty {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    padding: 8px 0;
}

.admin-integrations-error { color: var(--color-error); }

.admin-integrations-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

.admin-integration-row {
    padding: 12px 14px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}

.admin-integration-row__body {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.admin-integration-row__header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* App icon — inline before the title, same size as a small avatar */
.admin-integration-row__icon {
    width: 22px;
    height: 22px;
    object-fit: contain;
    flex-shrink: 0;
}

.admin-integration-row__title {
    font-size: 14px;
    font-weight: 600;
}

.admin-integration-row__appid {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.admin-integration-row__desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.admin-integration-row__urls {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    word-break: break-all;
}

.admin-integration-row__badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    border-radius: var(--border-radius-pill);
    padding: 1px 7px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.admin-integration-row__badge--widget {
    background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
    color: var(--color-primary-element);
}

.admin-integration-row__badge--menu_item,
.admin-integration-row__badge--tab {
    background: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success, #46ba61);
}
.admin-save-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 16px 24px;
    border-top: 1px solid var(--color-border);
    margin-top: 8px;
}

.admin-save-ok  { font-size: 14px; color: var(--color-success); font-weight: 500; }
.admin-save-err { font-size: 14px; color: var(--color-error); }
/* ── Statistics tab ────────────────────────────────────────────── */
.admin-telemetry-details {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.admin-telemetry-preview {
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    padding: 12px;
    font-size: 12px;
    font-family: monospace;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
    color: var(--color-main-text);
    max-height: 260px;
    overflow-y: auto;
}

/* ── Maintenance tab ───────────────────────────────────────────── */
.admin-loading {
    padding: 12px 0;
}

.admin-error {
    color: var(--color-error);
    font-size: 13px;
    padding: 8px 0;
}

.admin-empty {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    padding: 8px 0;
}

.admin-orphan-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}

.admin-orphan-row {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 12px 14px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    flex-wrap: wrap;
}

.admin-orphan-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.admin-orphan-name {
    font-size: 14px;
    font-weight: 600;
}

.admin-orphan-meta {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.admin-orphan-id {
    font-family: monospace;
    font-size: 11px;
}

.admin-orphan-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    align-items: center;
}

.admin-assign-owner {
    flex: 1;
    min-width: 240px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.admin-owner-results {
    list-style: none;
    margin: 0;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    max-height: 180px;
    overflow-y: auto;
}

.admin-owner-result {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid var(--color-border-dark);
}

.admin-owner-result:last-child {
    border-bottom: none;
}

.admin-owner-result:hover {
    background: var(--color-background-hover);
}

.admin-owner-result__uid {
    color: var(--color-text-maxcontrast);
    font-size: 12px;
    margin-left: 4px;
}

.admin-orphan-refresh {
    margin-top: 4px;
}

.admin-section-hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 4px 0 0;
}
</style>

