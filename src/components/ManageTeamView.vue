<template>
    <div class="manage-team-view">
        <div class="manage-team-header">
            <h2>{{ t('teamhub', 'Manage Team') }}</h2>
            <p class="manage-team-subtitle">{{ team.name }}</p>
        </div>

        <!-- Tab bar -->
        <div class="manage-tabs">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="manage-tab"
                :class="{ 'manage-tab--active': activeTab === tab.key, 'manage-tab--danger': tab.key === 'danger' }"
                @click="activeTab = tab.key">
                <component :is="tab.icon" :size="18" />
                {{ tab.label }}
            </button>
        </div>

        <!-- TAB: Description -->
        <div v-if="activeTab === 'description'" class="manage-tab-content">

            <!-- Team Image section -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Team Image') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Upload a custom image to represent this team. Shown on the team home view. Maximum 200×200 px, 2 MB.') }}
                </p>

                <!-- Current image preview -->
                <div class="team-image-preview-row">
                    <div class="team-image-preview" :class="{ 'team-image-preview--empty': !imagePreviewUrl }">
                        <img
                            v-if="imagePreviewUrl"
                            :src="imagePreviewUrl"
                            :alt="t('teamhub', 'Team image')"
                            class="team-image-preview__img" />
                        <ImageIcon v-else :size="48" class="team-image-preview__placeholder" />
                    </div>

                    <div class="team-image-actions">
                        <!-- Upload button — triggers hidden file input -->
                        <NcButton
                            type="secondary"
                            :disabled="imageUploading || imageRemoving"
                            @click="$refs.teamImageInput.click()">
                            <template #icon>
                                <NcLoadingIcon v-if="imageUploading" :size="20" />
                                <UploadIcon v-else :size="20" />
                            </template>
                            {{ imagePreviewUrl ? t('teamhub', 'Replace image') : t('teamhub', 'Upload image') }}
                        </NcButton>

                        <!-- Remove button — only shown when an image exists -->
                        <NcButton
                            v-if="imagePreviewUrl"
                            type="error"
                            :disabled="imageUploading || imageRemoving"
                            @click="removeTeamImage">
                            <template #icon>
                                <NcLoadingIcon v-if="imageRemoving" :size="20" />
                                <TrashCanOutline v-else :size="20" />
                            </template>
                            {{ t('teamhub', 'Remove image') }}
                        </NcButton>

                        <!-- Hidden file input -->
                        <input
                            ref="teamImageInput"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            class="team-image-hidden-input"
                            @change="onTeamImageSelected" />
                    </div>
                </div>
            </div>

            <div class="manage-section">
                <h3>{{ t('teamhub', 'Team Description') }}</h3>
                <div class="manage-description-form">
                    <NcTextArea
                        v-model="editedDescription"
                        :label="t('teamhub', 'Description')"
                        :placeholder="t('teamhub', 'Enter team description...')"
                        :rows="4" />
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

        </div>

        <!-- TAB: Settings -->
        <div v-else-if="activeTab === 'settings'" class="manage-tab-content">
            <!-- Circle Settings -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Circle Settings') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'These settings control how people can join and interact with this team.') }}
                </p>
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
        </div>

        <!-- TAB: Members -->
        <div v-else-if="activeTab === 'members'" class="manage-tab-content">
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
                        <select
                            v-if="canChangeLevel(member)"
                            :value="member.level"
                            :disabled="changingLevel === member.userId"
                            class="member-level-select"
                            :aria-label="t('teamhub', 'Change role for {name}', { name: member.displayName })"
                            @change="changeLevel(member, Number($event.target.value))">
                            <option :value="1">{{ t('teamhub', 'Member') }}</option>
                            <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                            <option v-if="currentUserIsOwner" :value="8">{{ t('teamhub', 'Admin') }}</option>
                        </select>
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
        </div>

        <!-- TAB: Integrations -->
        <div v-else-if="activeTab === 'integrations'" class="manage-tab-content">
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Integrations') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Enable or disable third-party integrations registered by other Nextcloud apps. Widgets appear on the Home view. Tab integrations add a tab to the tab bar.') }}
                </p>
                <div v-if="loadingWidgets" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>
                <template v-else>
                    <!--
                        Only EXTERNAL (non-builtin) integrations appear here.
                        Built-in NC apps (Talk, Files, Calendar, Deck) are managed
                        in the Settings tab under Team Apps. They are seeded into
                        the registry as is_builtin=true and did NOT register via
                        the integration API — so they must not appear here.
                    -->
                    <div v-if="externalIntegrations.length === 0" class="no-pending">
                        {{ t('teamhub', 'No third-party integrations available. Install a compatible app to add integrations to this team.') }}
                    </div>
                    <div v-else class="widgets-list">
                        <div
                            v-for="integration in externalIntegrations"
                            :key="integration.registry_id"
                            class="widget-item"
                            :class="{ 'widget-item--enabled': integration.enabled }">
                            <span
                                v-if="integration.enabled"
                                class="widget-drag-handle"
                                :draggable="true"
                                :aria-label="t('teamhub', 'Drag to reorder')"
                                @dragstart="onDragStart($event, integration)"
                                @dragover.prevent
                                @drop="onDrop($event, integration)">
                                <DragVertical :size="18" />
                            </span>
                            <span v-else class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    {{ integration.title }}
                                    <span
                                        class="widget-badge"
                                        :class="integration.integration_type === 'widget' ? 'widget-badge--widget' : 'widget-badge--tab'">
                                        {{ integration.integration_type === 'widget' ? t('teamhub', 'Widget') : t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span v-if="integration.description" class="widget-description">{{ integration.description }}</span>
                                <span v-if="integration.app_id" class="widget-app-id">{{ integration.app_id }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :checked="integration.enabled"
                                :disabled="togglingWidget === integration.registry_id"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable {title}', { title: integration.title })"
                                @update:checked="toggleIntegration(integration, $event)">
                                {{ integration.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- TAB: Danger Zone -->
        <div v-else-if="activeTab === 'danger'" class="manage-tab-content">
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
        </div>

        <!-- Hard-delete confirmation dialog -->
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
import TextIcon from 'vue-material-design-icons/Text.vue'
import TuneIcon from 'vue-material-design-icons/Tune.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

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
        ImageIcon, TrashCanOutline, UploadIcon,
        TextIcon, TuneIcon, AccountMultipleIcon, PuzzleIcon, AlertIcon,
    },
    props: {
        team: { type: Object, required: true },
    },
    emits: ['description-updated', 'team-deleted'],
    data() {
        return {
            activeTab: 'description',
            editedDescription: this.team.description || '',
            members: [],
            pendingRequests: [],
            loadingMembers: false,
            loadingPending: false,
            loadingConfig: false,
            saving: false,
            configSaved: false,
            deleting: false,
            changingLevel: null,
            circleConfig: {
                open: false,
                invite: false,
                request: false,
                visible: false,
                protected: false,
                singleMember: false,
            },
            integrationRegistry: [],
            loadingWidgets: false,
            togglingWidget: null,
            dragSourceWidget: null,
            teamApps: [],
            installedApps: {},
            loadingApps: false,
            togglingApp: null,
            pendingDisableApp: null,
            // Team image
            imageUploading: false,
            imageRemoving: false,
            imagePreviewUrl: this.team.image_url || null,
        }
    },
    computed: {
        ...mapState(['intravoxAvailable']),

        tabs() {
            return [
                { key: 'description',  label: t('teamhub', 'Description'),  icon: 'TextIcon' },
                { key: 'settings',     label: t('teamhub', 'Settings'),     icon: 'TuneIcon' },
                { key: 'members',      label: t('teamhub', 'Members'),      icon: 'AccountMultipleIcon' },
                { key: 'integrations', label: t('teamhub', 'Integrations'), icon: 'PuzzleIcon' },
                { key: 'danger',       label: t('teamhub', 'Danger Zone'),  icon: 'AlertIcon' },
            ]
        },

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
        currentUserLevel() {
            const me = this.members.find(m => m.userId === this.currentUserId)
            return me ? (me.level || 1) : 1
        },
        currentUserIsOwner() {
            return this.currentUserLevel >= 9
        },

        /**
         * Only EXTERNAL (non-builtin) integrations.
         *
         * Built-in NC apps (Talk, Files, Calendar, Deck) are seeded into the
         * registry as is_builtin=true by seedBuiltins() in IntegrationService.
         * They are managed under "Team Apps" (Settings tab). They did NOT
         * register via the external integration API and must not appear here.
         */
        externalIntegrations() {
            return this.integrationRegistry.filter(i => !i.is_builtin)
        },

        teamAppsList() {
            const definitions = [
                {
                    id: 'spreed',
                    label: t('teamhub', 'Talk'),
                    description: t('teamhub', 'Team chat and video calls'),
                    icon: MessageIcon,
                    installed: !!this.installedApps.talk,
                },
                {
                    id: 'files',
                    label: t('teamhub', 'Files'),
                    description: t('teamhub', 'Shared team folder'),
                    icon: FolderIcon,
                    installed: true,
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
                    installed: !!this.intravoxAvailable,
                },
            ]
            return definitions
                .filter(def => def.installed)
                .map(def => {
                    const row = this.teamApps.find(a => a.app_id === def.id)
                    const enabled = row ? row.enabled : true
                    return { ...def, enabled }
                })
        },
    },
    watch: {
        'team.id'() {
            this.editedDescription = this.team.description || ''
            this.activeTab = 'description'
            this.loadAll()
        },
    },
    mounted() {
        this.loadAll()
    },
    methods: {
        t,

        loadAll() {
            this.loadMembers()
            this.loadPendingRequests()
            this.loadConfig()
            this.loadTeamApps()
            this.loadIntegrationRegistry()
        },

        getMemberRoleLabel(level) {
            if (level >= 9) return t('teamhub', 'Owner')
            if (level >= 8) return t('teamhub', 'Admin')
            if (level >= 4) return t('teamhub', 'Moderator')
            return t('teamhub', 'Member')
        },

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
                this.members = Array.isArray(data) ? data : this.members
                showSuccess(t('teamhub', 'Role updated'))
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update role') + (msg ? `: ${msg}` : ''))
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
            } catch (e) {
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
            } catch (e) {
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
            } catch (e) {
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
            } catch (e) {
                showError(t('teamhub', 'Failed to remove member'))
            }
        },

        async approve(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/approve/${req.userId}`))
                showSuccess(t('teamhub', '{name} has been approved', { name: req.displayName }))
                await Promise.all([this.loadMembers(), this.loadPendingRequests()])
            } catch (e) {
                showError(t('teamhub', 'Failed to approve request'))
            }
        },

        async reject(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/reject/${req.userId}`))
                showSuccess(t('teamhub', 'Request rejected'))
                await this.loadPendingRequests()
            } catch (e) {
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
        // Team apps
        // ------------------------------------------------------------------

        async loadTeamApps() {
            this.loadingApps = true
            try {
                const [appsRes, installedRes] = await Promise.all([
                    axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/apps`)),
                    axios.get(generateUrl('/apps/teamhub/api/v1/apps/check')),
                ])
                this.teamApps      = Array.isArray(appsRes.data) ? appsRes.data : []
                this.installedApps = installedRes.data || {}
            } catch (e) {
                this.teamApps      = []
                this.installedApps = {}
            } finally {
                this.loadingApps = false
            }
        },

        async toggleApp(app, enabled) {
            if (!app.installed) return
            if (!enabled) {
                this.pendingDisableApp = app
                return
            }
            await this._executeToggleApp(app, true)
        },

        async confirmDisableApp() {
            const app = this.pendingDisableApp
            this.pendingDisableApp = null
            if (!app) return
            await this._executeToggleApp(app, false)
        },

        cancelDisableApp() {
            const existing = this.teamApps.find(a => a.app_id === this.pendingDisableApp?.id)
            if (existing) existing.enabled = true
            this.pendingDisableApp = null
        },

        async _executeToggleApp(app, enabled) {
            this.togglingApp = app.id
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
                if (existing) {
                    existing.enabled = !enabled
                } else {
                    this.teamApps = this.teamApps.filter(a => a.app_id !== app.id)
                }
                const msg = error.response?.data?.error || ''
                showError(t('teamhub', 'Failed to update {name}', { name: app.label }) + (msg ? `: ${msg}` : ''))
                await this.loadTeamApps()
            } finally {
                this.togglingApp = null
            }
        },

        // ------------------------------------------------------------------
        // External integrations
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
                await this.$store.dispatch('fetchTeamIntegrations', this.team.id)
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

        // ------------------------------------------------------------------
        // Team image
        // ------------------------------------------------------------------

        async onTeamImageSelected(event) {
            const file = event.target.files?.[0]
            if (!file) return

            // Client-side size guard (2 MB)
            if (file.size > 2 * 1024 * 1024) {
                showError(t('teamhub', 'Image too large. Maximum size is 2 MB.'))
                return
            }

            this.imageUploading = true
            try {
                const formData = new FormData()
                formData.append('image', file)

                const resp = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/image`),
                    formData,
                    { headers: { 'Content-Type': 'multipart/form-data' } }
                )

                // Append cache-buster so the browser reloads the new image
                this.imagePreviewUrl = resp.data.image_url
                    ? resp.data.image_url + '?t=' + Date.now()
                    : null

                // Propagate to Vuex so TeamView reflects the change immediately
                this.$store.commit('UPDATE_TEAM_IMAGE', {
                    teamId: this.team.id,
                    imageUrl: resp.data.image_url || null,
                })

                showSuccess(t('teamhub', 'Team image updated'))
            } catch (e) {
                const msg = e?.response?.data?.error || e.message || ''
                showError(t('teamhub', 'Failed to upload image') + (msg ? ': ' + msg : ''))
            } finally {
                this.imageUploading = false
                // Reset so the same file can be re-selected if needed
                if (this.$refs.teamImageInput) {
                    this.$refs.teamImageInput.value = ''
                }
            }
        },

        async removeTeamImage() {
            this.imageRemoving = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/image`)
                )
                this.imagePreviewUrl = null

                this.$store.commit('UPDATE_TEAM_IMAGE', {
                    teamId: this.team.id,
                    imageUrl: null,
                })

                showSuccess(t('teamhub', 'Team image removed'))
            } catch (e) {
                showError(t('teamhub', 'Failed to remove image'))
            } finally {
                this.imageRemoving = false
            }
        },

        async onDrop(event, targetIntegration) {
            event.preventDefault()
            if (!this.dragSourceWidget || this.dragSourceWidget.registry_id === targetIntegration.registry_id) {
                this.dragSourceWidget = null
                return
            }
            if (this.dragSourceWidget.integration_type !== targetIntegration.integration_type) {
                this.dragSourceWidget = null
                return
            }

            const enabled = this.externalIntegrations
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
    padding: 32px 40px;
    max-width: 900px;
    margin: 0 auto;
}

.manage-team-header {
    margin-bottom: 24px;
}
.manage-team-header h2 {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 4px;
}
.manage-team-subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* ── Tab bar ─────────────────────────────────────────────────── */
.manage-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.manage-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border: none;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
    margin-bottom: -2px;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
    white-space: nowrap;
}

.manage-tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.manage-tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    background: transparent;
}

.manage-tab--active:hover {
    background: color-mix(in srgb, var(--color-primary-element) 6%, transparent);
}

/* Danger tab styling */
.manage-tab--danger:hover {
    color: var(--color-error);
}
.manage-tab--danger.manage-tab--active {
    color: var(--color-error);
    border-bottom-color: var(--color-error);
}

/* ── Sections ─────────────────────────────────────────────────── */
.manage-tab-content {
    display: flex;
    flex-direction: column;
}

.manage-section {
    margin-bottom: 36px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--color-border);
}
.manage-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.manage-section h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 16px;
}

.manage-section-desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: -8px 0 16px;
}

.section-loading {
    padding: 12px 0;
}

/* Description */
.manage-description-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.manage-description-actions {
    display: flex;
    justify-content: flex-end;
}

/* Settings */
.manage-settings {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.manage-settings-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
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

/* Members */
.members-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
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
.member-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.member-name {
    font-size: 14px;
    font-weight: 500;
}
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

/* Pending requests */
.no-pending {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
}
.pending-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pending-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}
.pending-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.pending-name {
    font-size: 14px;
    font-weight: 500;
}
.pending-date {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}
.pending-actions {
    display: flex;
    gap: 8px;
}

/* Integrations */
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
.widget-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    border-radius: var(--border-radius-pill);
    padding: 1px 6px;
    margin-left: 6px;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.widget-badge--widget {
    background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
    color: var(--color-primary-element);
}
.widget-badge--tab {
    background: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success, #46ba61);
}

/* Danger zone */
.manage-section--danger {
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-error);
    border-radius: var(--border-radius-large);
    padding: 20px 24px;
    background: color-mix(in srgb, var(--color-error) 5%, transparent);
}
.manage-section--danger h3 {
    color: var(--color-error);
}
.manage-danger-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.manage-danger-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}
.manage-danger-title {
    font-size: 14px;
    font-weight: 500;
}
.manage-danger-desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

/* ── Team image ────────────────────────────────────────────────── */
.team-image-preview-row {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.team-image-preview {
    width: 100px;
    height: 100px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-background-dark);
    flex-shrink: 0;
}

.team-image-preview--empty {
    border-style: dashed;
}

.team-image-preview__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.team-image-preview__placeholder {
    color: var(--color-text-maxcontrast);
    opacity: 0.4;
}

.team-image-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.team-image-hidden-input {
    display: none;
}
</style>
