<template>
    <div class="manage-team-view">
        <div class="manage-team-header">
            <h2>{{ t('teamhub', 'Manage Team') }}</h2>
            <p class="manage-team-subtitle">{{ team.name }}</p>
        </div>

        <!-- Description -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Description') }}</h3>
            <div class="manage-description-form">
                <NcTextArea
                    v-model="editedDescription"
                    :label="t('teamhub', 'Description')"
                    :placeholder="t('teamhub', 'Enter team description...')"
                    :rows="3" />
                <div class="manage-description-actions">
                    <NcButton
                        type="primary"
                        :disabled="(editedDescription === (team.description || '')) || saving"
                        @click="saveDescription">
                        <template #icon>
                            <NcLoadingIcon v-if="saving" :size="20" />
                            <ContentSave v-else :size="20" />
                        </template>
                        {{ t('teamhub', 'Save Description') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Circle Settings -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Settings') }}</h3>
            <div v-if="loadingConfig" class="section-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else class="manage-settings">
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Invitations') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in invitationOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Membership') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in membershipOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <div class="manage-settings-group">
                    <h4>{{ t('teamhub', 'Privacy') }}</h4>
                    <NcCheckboxRadioSwitch
                        v-for="opt in privacyOptions"
                        :key="opt.key"
                        :checked.sync="circleConfig[opt.key]"
                        type="checkbox"
                        @update:checked="saveConfig">
                        {{ opt.label }}
                    </NcCheckboxRadioSwitch>
                </div>
                <p v-if="configSaved" class="manage-settings-saved">
                    <CheckCircle :size="14" />{{ t('teamhub', 'Settings saved') }}
                </p>
            </div>
        </div>

        <!-- Team Apps -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Apps') }}</h3>
            <p class="manage-section-desc">
                {{ t('teamhub', 'Enable or disable Nextcloud apps for this team. Disabled apps are hidden from the tab bar.') }}
            </p>
            <div v-if="loadingApps" class="section-loading">
                <NcLoadingIcon :size="24" />
            </div>
            <div v-else class="team-apps-list">
                <div
                    v-for="app in teamAppsList"
                    :key="app.id"
                    class="team-app-item">
                    <div class="team-app-icon">
                        <component :is="app.icon" :size="22" />
                    </div>
                    <div class="team-app-info">
                        <span class="team-app-name">{{ app.label }}</span>
                        <span class="team-app-desc">{{ app.description }}</span>
                    </div>
                    <NcCheckboxRadioSwitch
                        :checked="app.enabled"
                        :disabled="togglingApp === app.id || !app.installed"
                        type="switch"
                        :aria-label="t('teamhub', 'Enable {name}', { name: app.label })"
                        @update:checked="toggleApp(app, $event)">
                        {{ app.installed ? (app.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled')) : t('teamhub', 'Not installed') }}
                    </NcCheckboxRadioSwitch>
                </div>
            </div>
        </div>

        <!-- Members -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Team Members') }} ({{ members.length }})</h3>
            <div v-if="loadingMembers" class="section-loading">
                <NcLoadingIcon :size="32" />
            </div>
            <div v-else class="members-list">
                <div
                    v-for="member in members"
                    :key="member.userId || member.displayName"
                    class="member-item">
                    <NcAvatar
                        v-if="member.userId"
                        :user="member.userId"
                        :display-name="member.displayName"
                        :size="32"
                        :show-user-status="false" />
                    <div v-else class="member-avatar-fallback">
                        {{ (member.displayName || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="member-info">
                        <span class="member-name">{{ member.displayName }}</span>
                    </div>

                    <!-- Role dropdown — disabled for owners and for the current user -->
                    <select
                        v-if="canChangeLevel(member)"
                        :value="member.level"
                        :disabled="changingLevel === member.userId"
                        class="member-level-select"
                        :aria-label="t('teamhub', 'Change role for {name}', { name: member.displayName })"
                        @change="changeLevel(member, Number($event.target.value))">
                        <option :value="1">{{ t('teamhub', 'Member') }}</option>
                        <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                        <!-- Admin option only shown to owners -->
                        <option v-if="currentUserIsOwner" :value="8">{{ t('teamhub', 'Admin') }}</option>
                    </select>
                    <!-- Static label for owners and current user -->
                    <span v-else class="member-role-static">{{ getMemberRoleLabel(member.level) }}</span>

                    <NcButton
                        v-if="canRemoveMember(member)"
                        type="error"
                        :aria-label="t('teamhub', 'Remove member')"
                        @click="confirmRemoveMember(member)">
                        <template #icon><AccountRemove :size="20" /></template>
                        {{ t('teamhub', 'Remove') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Pending Join Requests -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Pending Join Requests') }}</h3>
            <div v-if="loadingPending" class="section-loading">
                <NcLoadingIcon :size="32" />
            </div>
            <div v-else-if="pendingRequests.length === 0" class="no-pending">
                {{ t('teamhub', 'No pending requests') }}
            </div>
            <div v-else class="pending-list">
                <div v-for="req in pendingRequests" :key="req.userId" class="pending-item">
                    <NcAvatar
                        v-if="req.userId"
                        :user="req.userId"
                        :display-name="req.displayName"
                        :size="32"
                        :show-user-status="false" />
                    <div v-else class="member-avatar-fallback">
                        {{ (req.displayName || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="pending-info">
                        <span class="pending-name">{{ req.displayName }}</span>
                        <span class="pending-date">{{ req.userId }}</span>
                    </div>
                    <div class="pending-actions">
                        <NcButton type="primary" @click="approve(req)">
                            <template #icon><Check :size="20" /></template>
                            {{ t('teamhub', 'Approve') }}
                        </NcButton>
                        <NcButton type="error" @click="reject(req)">
                            <template #icon><Close :size="20" /></template>
                            {{ t('teamhub', 'Reject') }}
                        </NcButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integrations -->
        <div class="manage-section">
            <h3>{{ t('teamhub', 'Integrations') }}</h3>
            <p class="manage-section-desc">
                {{ t('teamhub', 'Enable or disable integrations for this team. Sidebar widgets show data in the right panel. Menu items add a tab to the tab bar.') }}
            </p>

            <div v-if="loadingWidgets" class="section-loading">
                <NcLoadingIcon :size="32" />
            </div>

            <template v-else>
                <!-- Menu items (tabs) -->
                <div class="integrations-group">
                    <h4 class="integrations-group-title">{{ t('teamhub', 'Tab bar items') }}</h4>
                    <div v-if="menuItemIntegrations.length === 0" class="no-pending">
                        {{ t('teamhub', 'No tab bar integrations available.') }}
                    </div>
                    <div v-else class="widgets-list">
                        <div
                            v-for="item in menuItemIntegrations"
                            :key="item.registry_id"
                            class="widget-item"
                            :class="{ 'widget-item--enabled': item.enabled }">

                            <span
                                v-if="item.enabled && !item.is_builtin"
                                class="widget-drag-handle"
                                :draggable="true"
                                :aria-label="t('teamhub', 'Drag to reorder')"
                                @dragstart="onDragStart($event, item)"
                                @dragover.prevent
                                @drop="onDrop($event, item)">
                                <DragVertical :size="18" />
                            </span>
                            <span v-else class="widget-drag-handle widget-drag-handle--placeholder" />

                            <div class="widget-info">
                                <span class="widget-title">
                                    {{ item.title }}
                                    <span v-if="item.is_builtin" class="widget-badge">{{ t('teamhub', 'Built-in') }}</span>
                                </span>
                                <span v-if="item.description" class="widget-description">{{ item.description }}</span>
                            </div>

                            <NcCheckboxRadioSwitch
                                :checked="item.enabled"
                                :disabled="togglingWidget === item.registry_id"
                                type="checkbox"
                                :aria-label="t('teamhub', 'Enable {title}', { title: item.title })"
                                @update:checked="toggleIntegration(item, $event)">
                                {{ item.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>
                    </div>
                </div>

                <!-- Sidebar widgets -->
                <div class="integrations-group">
                    <h4 class="integrations-group-title">{{ t('teamhub', 'Sidebar widgets') }}</h4>
                    <div v-if="widgetIntegrations.length === 0" class="no-pending">
                        {{ t('teamhub', 'No sidebar widgets registered. Install a compatible app to add widgets to this team.') }}
                    </div>
                    <div v-else class="widgets-list">
                        <div
                            v-for="widget in widgetIntegrations"
                            :key="widget.registry_id"
                            class="widget-item"
                            :class="{ 'widget-item--enabled': widget.enabled }">

                            <span
                                v-if="widget.enabled"
                                class="widget-drag-handle"
                                :draggable="true"
                                :aria-label="t('teamhub', 'Drag to reorder')"
                                @dragstart="onDragStart($event, widget)"
                                @dragover.prevent
                                @drop="onDrop($event, widget)">
                                <DragVertical :size="18" />
                            </span>
                            <span v-else class="widget-drag-handle widget-drag-handle--placeholder" />

                            <div class="widget-info">
                                <span class="widget-title">{{ widget.title }}</span>
                                <span v-if="widget.description" class="widget-description">{{ widget.description }}</span>
                                <span class="widget-app-id">{{ widget.app_id }}</span>
                            </div>

                            <NcCheckboxRadioSwitch
                                :checked="widget.enabled"
                                :disabled="togglingWidget === widget.registry_id"
                                type="checkbox"
                                :aria-label="t('teamhub', 'Enable {title}', { title: widget.title })"
                                @update:checked="toggleIntegration(widget, $event)">
                                {{ widget.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Danger zone: Delete Team -->
        <div class="manage-section manage-section--danger">
            <h3>{{ t('teamhub', 'Danger Zone') }}</h3>
            <div class="manage-danger-row">
                <div class="manage-danger-info">
                    <span class="manage-danger-title">{{ t('teamhub', 'Delete this team') }}</span>
                    <span class="manage-danger-desc">{{ t('teamhub', 'Permanently delete the team and all its settings. Resources (files, calendar, chat) are not deleted.') }}</span>
                </div>
                <NcButton
                    type="error"
                    :disabled="deleting"
                    @click="confirmDeleteTeam">
                    <template #icon>
                        <NcLoadingIcon v-if="deleting" :size="20" />
                        <Delete v-else :size="20" />
                    </template>
                    {{ t('teamhub', 'Delete team') }}
                </NcButton>
            </div>
        </div>
        <!-- Hard-delete confirmation dialog (shown when user toggles an app OFF) -->
        <NcDialog
            v-if="pendingDisableApp"
            :name="t('teamhub', 'Permanently delete {name} data?', { name: pendingDisableApp.label })"
            :open="true"
            @update:open="cancelDisableApp">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Disabling {name} will permanently delete all data associated with this team:', { name: pendingDisableApp.label }) }}
                </p>
                <ul style="margin: 0 0 12px; padding-left: 20px;">
                    <li v-if="pendingDisableApp.id === 'spreed'">{{ t('teamhub', 'The Talk chat room and all messages') }}</li>
                    <li v-if="pendingDisableApp.id === 'files'">{{ t('teamhub', 'The shared team folder and all files inside it') }}</li>
                    <li v-if="pendingDisableApp.id === 'calendar'">{{ t('teamhub', 'The team calendar and all events') }}</li>
                    <li v-if="pendingDisableApp.id === 'deck'">{{ t('teamhub', 'The Deck board and all cards') }}</li>
                    <li v-if="pendingDisableApp.id === 'intravox'">{{ t('teamhub', 'The Intravox team page') }}</li>
                </ul>
                <p style="margin: 0; font-weight: 600; color: var(--color-error);">
                    {{ t('teamhub', 'This action cannot be undone.') }}
                </p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="cancelDisableApp">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton type="error" @click="confirmDisableApp">
                    {{ t('teamhub', 'Yes, permanently delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { mapState } from 'vuex'
import { NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountRemove from 'vue-material-design-icons/AccountRemove.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import MessageIcon from 'vue-material-design-icons/Message.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

// Circles config bitmask constants (match MANAGED_BITS in TeamService.php)
const CFG_OPEN         = 1
const CFG_INVITE       = 2
const CFG_REQUEST      = 4
const CFG_PROTECTED    = 16
const CFG_VISIBLE      = 512
const CFG_SINGLE       = 1024

export default {
    name: 'ManageTeamView',
    components: {
        NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
        ContentSave, AccountRemove, Check, Close, CheckCircle, Delete, DragVertical,
        MessageIcon, FolderIcon, CalendarIcon, CardTextIcon, FileDocumentOutlineIcon,
    },
    props: {
        team: { type: Object, required: true },
    },
    emits: ['description-updated', 'team-deleted'],
    data() {
        return {
            editedDescription: this.team.description || '',
            members: [],
            pendingRequests: [],
            loadingMembers: false,
            loadingPending: false,
            loadingConfig: false,
            saving: false,
            configSaved: false,
            deleting: false,
            // userId of the member whose level is currently being saved, or null
            changingLevel: null,
            circleConfig: {
                open: false,
                invite: false,
                request: false,
                visible: false,
                protected: false,
                singleMember: false,
            },
            // Integrations tab (merged widget + menu_item registry)
            integrationRegistry: [], // full list from /integrations/registry with enabled state
            loadingWidgets: false,
            togglingWidget: null,    // registry_id currently being toggled, or null
            dragSourceWidget: null,  // integration row being dragged
            // Team apps (Talk, Files, Calendar, Deck)
            teamApps: [],          // rows from GET /api/v1/teams/{id}/apps
            installedApps: {},     // { talk, calendar, deck } from /api/v1/apps/check
            loadingApps: false,
            togglingApp: null,     // app_id currently being toggled, or null
            pendingDisableApp: null, // app awaiting hard-delete confirmation, or null
        }
    },
    computed: {
        // Pull intravoxAvailable from Vuex — same source as TeamView uses
        ...mapState(['intravoxAvailable']),

        invitationOptions() {
            return [
                { key: 'open',    label: t('teamhub', 'Anyone can join (no invitation needed)') },
                { key: 'invite',  label: t('teamhub', 'Members can invite others') },
                { key: 'request', label: t('teamhub', 'Membership requests must be approved by a Moderator (requires "Anyone can join")') },
            ]
        },
        membershipOptions() {
            return [
                { key: 'singleMember', label: t('teamhub', 'Prevent teams from being a member of another team') },
            ]
        },
        privacyOptions() {
            return [
                { key: 'visible',   label: t('teamhub', 'Visible to everyone') },
                { key: 'protected', label: t('teamhub', 'Enforce password protection on files shared with this team') },
            ]
        },
        configValue() {
            let v = 0
            if (this.circleConfig.open)        v |= CFG_OPEN
            if (this.circleConfig.invite)       v |= CFG_INVITE
            if (this.circleConfig.request)      v |= CFG_REQUEST
            if (this.circleConfig.visible)      v |= CFG_VISIBLE
            if (this.circleConfig.protected)    v |= CFG_PROTECTED
            if (this.circleConfig.singleMember) v |= CFG_SINGLE
            return v
        },
        currentUserId() {
            return getCurrentUser()?.uid
        },
        // The caller's own level in this team
        currentUserLevel() {
            const me = this.members.find(m => m.userId === this.currentUserId)
            return me ? (me.level || 1) : 1
        },
        currentUserIsOwner() {
            return this.currentUserLevel >= 9
        },

        /** All integrations — alias kept for drag/drop logic */
        widgetRegistry() {
            return this.integrationRegistry
        },

        /** Only menu_item integrations (built-in tab bar entries + external mini apps) */
        menuItemIntegrations() {
            return this.integrationRegistry.filter(i => i.integration_type === 'menu_item')
        },

        /** Only sidebar widget integrations (external data-driven widgets) */
        widgetIntegrations() {
            return this.integrationRegistry.filter(i => i.integration_type === 'widget')
        },

        /**
         * Merged list of built-in apps with their installed + enabled state.
         *
         * app_id values must match what the TeamView's isBuiltinEnabled() uses
         * ('spreed', 'files', 'calendar', 'deck', 'intravox').
         *
         * installedApps keys from checkInstalledApps(): talk, calendar, deck, intravox.
         * Note: 'talk' maps to app_id 'spreed' — the NC app name for Talk.
         *
         * Intravox installed state comes from the Vuex store (intravoxAvailable)
         * which uses the same dual-check as TeamView.
         */
        teamAppsList() {
            // Icons must be component references, not strings — <component :is="..."> requires an object
            const definitions = [
                {
                    id: 'spreed',
                    label: t('teamhub', 'Talk'),
                    description: t('teamhub', 'Team chat and video calls'),
                    icon: MessageIcon,
                    installed: !!this.installedApps.talk,   // key is 'talk' not 'spreed'
                },
                {
                    id: 'files',
                    label: t('teamhub', 'Files'),
                    description: t('teamhub', 'Shared team folder'),
                    icon: FolderIcon,
                    installed: true, // Files is always available in NC core
                },
                {
                    id: 'calendar',
                    label: t('teamhub', 'Calendar'),
                    description: t('teamhub', 'Team calendar and events'),
                    icon: CalendarIcon,
                    installed: !!this.installedApps.calendar,
                },
                {
                    id: 'deck',
                    label: t('teamhub', 'Deck'),
                    description: t('teamhub', 'Kanban task board'),
                    icon: CardTextIcon,
                    installed: !!this.installedApps.deck,
                },
                {
                    id: 'intravox',
                    label: t('teamhub', 'Pages'),
                    description: t('teamhub', 'Team wiki and pages (Intravox)'),
                    icon: FileDocumentOutlineIcon,
                    installed: !!this.intravoxAvailable,    // from Vuex store
                },
            ]

            return definitions
                .filter(def => def.installed) // hide apps that are not installed
                .map(def => {
                    const row = this.teamApps.find(a => a.app_id === def.id)
                    // Default to enabled when no row exists (matches isBuiltinEnabled rule #20)
                    const enabled = row ? row.enabled : true
                    return { ...def, enabled }
                })
        },
    },
    watch: {
        'team.id'() {
            this.editedDescription = this.team.description || ''
            this.loadMembers()
            this.loadPendingRequests()
            this.loadConfig()
            this.loadTeamApps()
            this.loadIntegrationRegistry()
        },
    },
    mounted() {
        this.loadMembers()
        this.loadPendingRequests()
        this.loadConfig()
        this.loadTeamApps()
        this.loadIntegrationRegistry()
    },
    methods: {
        t,

        getMemberRoleLabel(level) {
            if (level >= 9) return t('teamhub', 'Owner')
            if (level >= 8) return t('teamhub', 'Admin')
            if (level >= 4) return t('teamhub', 'Moderator')
            return t('teamhub', 'Member')
        },

        // Show the dropdown when the current user is admin/owner, the target is
        // not the owner, and the target is not the current user themselves.
        canChangeLevel(member) {
            if (this.currentUserLevel < 8) return false
            if (member.userId === this.currentUserId) return false
            if (member.level >= 9) return false
            return true
        },

        canRemoveMember(member) {
            return member.userId !== this.currentUserId && member.level < 9
        },

        async changeLevel(member, newLevel) {
            if (newLevel === member.level) return
            this.changingLevel = member.userId
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${member.userId}/level`),
                    { level: newLevel }
                )
                // Backend returns the updated full member list
                this.members = Array.isArray(data) ? data : this.members
                showSuccess(t('teamhub', 'Role updated'))
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update role') + (msg ? `: ${msg}` : ''))
                // Revert the dropdown visually by re-fetching
                await this.loadMembers()
            } finally {
                this.changingLevel = null
            }
        },

        async saveDescription() {
            this.saving = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/description`),
                    { description: this.editedDescription }
                )
                showSuccess(t('teamhub', 'Description updated'))
                this.$emit('description-updated', this.editedDescription)
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update description') + (msg ? `: ${msg}` : ''))
            } finally {
                this.saving = false
            }
        },

        async loadConfig() {
            this.loadingConfig = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`)
                )
                const v = data.config || 0
                this.circleConfig.open         = !!(v & CFG_OPEN)
                this.circleConfig.invite        = !!(v & CFG_INVITE)
                this.circleConfig.request       = !!(v & CFG_REQUEST)
                this.circleConfig.visible       = !!(v & CFG_VISIBLE)
                this.circleConfig.protected     = !!(v & CFG_PROTECTED)
                this.circleConfig.singleMember  = !!(v & CFG_SINGLE)
            } catch {
                // Silently ignore — config is optional
            } finally {
                this.loadingConfig = false
            }
        },

        async saveConfig() {
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`),
                    { config: this.configValue }
                )
                this.configSaved = true
                setTimeout(() => { this.configSaved = false }, 2000)
            } catch (error) {
                showError(t('teamhub', 'Failed to save settings'))
            }
        },

        async loadMembers() {
            this.loadingMembers = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members`)
                )
                this.members = Array.isArray(data) ? data : []
            } catch {
                showError(t('teamhub', 'Failed to load members'))
            } finally {
                this.loadingMembers = false
            }
        },

        async loadPendingRequests() {
            this.loadingPending = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/pending-requests`)
                )
                this.pendingRequests = Array.isArray(data) ? data : []
            } catch {
                this.pendingRequests = []
            } finally {
                this.loadingPending = false
            }
        },

        async confirmRemoveMember(member) {
            if (!window.confirm(
                t('teamhub', 'Are you sure you want to remove {name} from this team?', { name: member.displayName })
            )) return
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${member.userId}`)
                )
                showSuccess(t('teamhub', 'Member removed'))
                await this.loadMembers()
            } catch {
                showError(t('teamhub', 'Failed to remove member'))
            }
        },

        async approve(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/approve/${req.userId}`))
                showSuccess(t('teamhub', '{name} has been approved', { name: req.displayName }))
                await Promise.all([this.loadMembers(), this.loadPendingRequests()])
            } catch {
                showError(t('teamhub', 'Failed to approve request'))
            }
        },

        async reject(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/reject/${req.userId}`))
                showSuccess(t('teamhub', 'Request rejected'))
                await this.loadPendingRequests()
            } catch {
                showError(t('teamhub', 'Failed to reject request'))
            }
        },

        async confirmDeleteTeam() {
            this.deleting = true
            try {
                await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}`))
                showSuccess(t('teamhub', 'Team deleted'))
                this.$emit('team-deleted')
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to delete team') + (msg ? ': ' + msg : ''))
            } finally {
                this.deleting = false
            }
        },

        // ------------------------------------------------------------------
        // Team apps (Talk / Files / Calendar / Deck)
        // ------------------------------------------------------------------

        async loadTeamApps() {
            this.loadingApps = true
            try {
                const [appsRes, installedRes] = await Promise.all([
                    axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/apps`)),
                    axios.get(generateUrl('/apps/teamhub/api/v1/apps/check')),
                ])
                this.teamApps     = Array.isArray(appsRes.data) ? appsRes.data : []
                this.installedApps = installedRes.data || {}
            } catch (e) {
                this.teamApps     = []
                this.installedApps = {}
            } finally {
                this.loadingApps = false
            }
        },

        /**
         * Toggle a built-in app on or off for this team.
         *
         * Enabling  → POST /teams/{id}/create-resources  (creates resource + grants access)
         * Disabling → DELETE /teams/{id}/resources/{appId}  (hard-deletes resource, all data gone)
         *
         * The enabled flag is always saved to teamhub_team_apps via the backend,
         * which now handles both the resource op and the flag upsert in one call
         * via PUT /teams/{id}/apps.
         */
        async toggleApp(app, enabled) {
            if (!app.installed) return

            // Disabling = hard delete of all app data. Show confirmation before proceeding.
            if (!enabled) {
                this.pendingDisableApp = app
                return
            }

            // Enable path: go straight to the API call
            await this._executeToggleApp(app, true)
        },

        /** Called when the user confirms the hard-delete warning dialog. */
        async confirmDisableApp() {
            const app = this.pendingDisableApp
            this.pendingDisableApp = null
            if (!app) return
            await this._executeToggleApp(app, false)
        },

        cancelDisableApp() {
            // Revert the switch back to enabled visually
            const existing = this.teamApps.find(a => a.app_id === this.pendingDisableApp?.id)
            if (existing) existing.enabled = true
            this.pendingDisableApp = null
        },

        /** Internal: execute the actual API call after confirmation (or for enable). */
        async _executeToggleApp(app, enabled) {
            this.togglingApp = app.id

            // Optimistic UI update
            const existing = this.teamApps.find(a => a.app_id === app.id)
            if (existing) {
                existing.enabled = enabled
            } else {
                this.teamApps.push({ app_id: app.id, enabled })
            }

            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/apps`),
                    { apps: [{ app_id: app.id, enabled }] }
                )
                if (enabled) {
                    showSuccess(t('teamhub', '{name} enabled for this team', { name: app.label }))
                } else {
                    showSuccess(t('teamhub', '{name} and all its data have been removed from this team', { name: app.label }))
                }
            } catch (error) {
                // Revert optimistic update
                if (existing) {
                    existing.enabled = !enabled
                } else {
                    this.teamApps = this.teamApps.filter(a => a.app_id !== app.id)
                }
                const msg = error.response?.data?.error || ''
                console.error('[ManageTeamView] toggleApp failed:', error?.response?.data)
                showError(t('teamhub', 'Failed to update {name}', { name: app.label }) + (msg ? `: ${msg}` : ''))
                await this.loadTeamApps()
            } finally {
                this.togglingApp = null
            }
        },

        // ------------------------------------------------------------------
        // Integrations tab
        // ------------------------------------------------------------------

        async loadIntegrationRegistry() {
            this.loadingWidgets = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/registry`)
                )
                this.integrationRegistry = Array.isArray(data) ? data : []
            } catch (e) {
                this.integrationRegistry = []
            } finally {
                this.loadingWidgets = false
            }
        },

        async toggleIntegration(integration, enabled) {
            this.togglingWidget = integration.registry_id
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/${integration.registry_id}/toggle`),
                    { enable: enabled }
                )
                this.integrationRegistry = Array.isArray(data) ? data : this.integrationRegistry
                showSuccess(
                    enabled
                        ? t('teamhub', '{title} enabled for this team', { title: integration.title })
                        : t('teamhub', '{title} disabled for this team', { title: integration.title })
                )
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update integration') + (msg ? `: ${msg}` : ''))
            } finally {
                this.togglingWidget = null
            }
        },

        onDragStart(event, integration) {
            this.dragSourceWidget = integration
            event.dataTransfer.effectAllowed = 'move'
        },

        async onDrop(event, targetIntegration) {
            event.preventDefault()
            if (!this.dragSourceWidget || this.dragSourceWidget.registry_id === targetIntegration.registry_id) {
                this.dragSourceWidget = null
                return
            }

            // Only reorder within the same type group.
            if (this.dragSourceWidget.integration_type !== targetIntegration.integration_type) {
                this.dragSourceWidget = null
                return
            }

            const enabled = this.integrationRegistry
                .filter(i => i.enabled && i.integration_type === this.dragSourceWidget.integration_type)
                .map(i => i.registry_id)

            const srcIdx = enabled.indexOf(this.dragSourceWidget.registry_id)
            const tgtIdx = enabled.indexOf(targetIntegration.registry_id)

            if (srcIdx === -1 || tgtIdx === -1) {
                this.dragSourceWidget = null
                return
            }

            enabled.splice(srcIdx, 1)
            enabled.splice(tgtIdx, 0, this.dragSourceWidget.registry_id)

            this.dragSourceWidget = null

            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/reorder`),
                    { order: enabled }
                )
                if (Array.isArray(data)) {
                    const sortMap = {}
                    data.forEach(i => { sortMap[i.registry_id] = i.sort_order })
                    this.integrationRegistry = this.integrationRegistry.map(i =>
                        i.enabled && sortMap[i.registry_id] !== undefined
                            ? { ...i, sort_order: sortMap[i.registry_id] }
                            : i
                    )
                    this.integrationRegistry.sort((a, b) => {
                        if (a.enabled && b.enabled) return a.sort_order - b.sort_order
                        if (a.enabled) return -1
                        if (b.enabled) return 1
                        return 0
                    })
                }
            } catch (e) {
                showError(t('teamhub', 'Failed to save order'))
                await this.loadIntegrationRegistry()
            }
        },
    },
}
</script>

<style scoped>
.manage-team-view {
    padding: 40px;
    max-width: 900px;
    margin: 0 auto;
}

.manage-team-header { margin-bottom: 32px; }
.manage-team-header h2 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
.manage-team-subtitle { color: var(--color-text-maxcontrast); margin: 0; }

.manage-section {
    margin-bottom: 36px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--color-border);
}
.manage-section:last-child { border-bottom: none; }
.manage-section h3 { font-size: 15px; font-weight: 600; margin: 0 0 16px; }

.section-loading { padding: 12px 0; }

/* Description */
.manage-description-form { display: flex; flex-direction: column; gap: 12px; }
.manage-description-actions { display: flex; justify-content: flex-end; }

/* Team apps */
.team-apps-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.team-app-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}

.team-app-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--border-radius);
    background: var(--color-primary-element-light);
    color: var(--color-primary-element);
    flex-shrink: 0;
}

.team-app-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.team-app-name {
    font-size: 14px;
    font-weight: 500;
}

.team-app-desc {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

/* Settings */
.manage-settings { display: flex; flex-direction: column; gap: 20px; }
.manage-settings-group { display: flex; flex-direction: column; gap: 4px; }
.manage-settings-group h4 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin: 0 0 4px;
}
.manage-settings-saved {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-success);
    margin: 4px 0 0;
}

/* Members */
.members-list { display: flex; flex-direction: column; gap: 8px; }
.member-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}
.member-avatar-fallback {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    flex-shrink: 0;
}
.member-info { flex: 1; display: flex; flex-direction: column; }
.member-name { font-size: 14px; font-weight: 500; }

/* Role dropdown */
.member-level-select {
    padding: 5px 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    cursor: pointer;
    min-width: 110px;
}
.member-level-select:disabled {
    opacity: 0.6;
    cursor: wait;
}
.member-role-static {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    min-width: 110px;
    text-align: center;
}

/* Pending */
.no-pending { font-size: 14px; color: var(--color-text-maxcontrast); }
.pending-list { display: flex; flex-direction: column; gap: 10px; }
.pending-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}
.pending-info { flex: 1; display: flex; flex-direction: column; }
.pending-name { font-size: 14px; font-weight: 500; }
.pending-date { font-size: 12px; color: var(--color-text-maxcontrast); }
.pending-actions { display: flex; gap: 8px; }

/* Danger zone */
.manage-section--danger {
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-error);
    border-radius: var(--border-radius-large);
    padding: 20px 24px;
    margin-top: 8px;
    background: color-mix(in srgb, var(--color-error) 5%, transparent);
}
.manage-section--danger h3 { color: var(--color-error); }
.manage-danger-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.manage-danger-info { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.manage-danger-title { font-size: 14px; font-weight: 500; }
.manage-danger-desc { font-size: 13px; color: var(--color-text-maxcontrast); }

/* Widgets section */
.widgets-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.widget-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    transition: background 0.15s ease;
}

.widget-item--enabled {
    background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-background-dark));
}

.widget-drag-handle {
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    width: 20px;
}

.widget-drag-handle:not(.widget-drag-handle--placeholder) {
    cursor: grab;
}

.widget-drag-handle:not(.widget-drag-handle--placeholder):active {
    cursor: grabbing;
}

.widget-drag-handle--placeholder {
    pointer-events: none;
    opacity: 0;
}

.widget-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.widget-title {
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.widget-description {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.widget-app-id {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    opacity: 0.7;
}
.widget-app-id {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.integrations-group {
    margin-bottom: 24px;
}

.integrations-group-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin: 0 0 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--color-border);
}

.manage-section-desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: -8px 0 16px;
}

.widget-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    background: var(--color-primary-element-light);
    color: var(--color-primary-element);
    border-radius: var(--border-radius-pill);
    padding: 1px 6px;
    margin-left: 6px;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
</style>
