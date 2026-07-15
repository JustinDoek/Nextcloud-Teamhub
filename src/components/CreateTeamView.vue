<template>
    <div class="ctv">
        <div class="ctv__inner">
            <div class="ctv__header">
                <h2 class="ctv__title">{{ t('teamhub', 'Create new team') }}</h2>
                <p class="ctv__subtitle">{{ wizardDescription || templateProfile.subtitle }}</p>
            </div>

            <!-- Step indicator -->
            <div class="ctv__steps">
                <div v-for="(s, i) in steps" :key="i" class="ctv__step-wrap">
                    <div :class="['ctv__step', { 'ctv__step--active': step === i+1, 'ctv__step--done': step > i+1 }]">
                        <span class="ctv__step-num">{{ i+1 }}</span>
                        <span class="ctv__step-label">{{ s }}</span>
                    </div>
                    <div v-if="i < steps.length - 1" class="ctv__step-line" />
                </div>
            </div>

            <!-- ── STEP 1: Name, description, type ── -->
            <div v-if="step === 1" class="ctv__section">
                <div class="ctv__field">
                    <NcTextField
                        v-model="form.name"
                        :label="t('teamhub', 'Team name')"
                        :placeholder="namePlaceholder"
                        :error="!!nameError"
                        :helper-text="nameError || ''" />
                </div>

                <div class="ctv__field">
                    <NcTextArea
                        v-model="form.description"
                        :label="t('teamhub', 'Description')"
                        :placeholder="t('teamhub', 'What is this team about?')"
                        :rows="3" />
                </div>

                <div class="ctv__field">
                    <label class="ctv__label">{{ t('teamhub', 'Team type') }}</label>
                    <div class="ctv__types">
                        <div
                            v-for="type in teamTypes"
                            :key="type.id"
                            :class="['ctv__type', 'ctv__type--' + type.accent, { 'ctv__type--selected': form.teamType === type.id }]"
                            @click="form.teamType = type.id">
                            <component :is="type.icon" :size="32" class="ctv__type-icon" />
                            <span class="ctv__type-name">{{ type.label }}</span>
                            <span class="ctv__type-desc">{{ type.description }}</span>
                        </div>
                    </div>
                </div>

                <!-- Project mode — only for the Project template -->
                <div v-if="form.teamType === 'project'" class="ctv__field">
                    <label id="project-mode-label" class="ctv__label">{{ t('teamhub', 'Project setup') }}</label>
                    <div class="ctv__modes" role="radiogroup" aria-labelledby="project-mode-label">
                        <div
                            v-for="m in projectModes"
                            :key="m.id"
                            :class="['ctv__mode', {
                                'ctv__mode--selected': form.projectMode === m.id,
                                'ctv__mode--locked':   m.locked,
                            }]"
                            role="radio"
                            :tabindex="m.locked ? -1 : 0"
                            :aria-checked="form.projectMode === m.id ? 'true' : 'false'"
                            :aria-disabled="m.locked ? 'true' : 'false'"
                            @click="!m.locked && (form.projectMode = m.id)"
                            @keydown.enter.prevent="!m.locked && (form.projectMode = m.id)"
                            @keydown.space.prevent="!m.locked && (form.projectMode = m.id)">
                            <span class="ctv__mode-name">
                                {{ m.label }}
                                <LockOutline v-if="m.locked" :size="14" class="ctv__mode-lock" />
                            </span>
                            <span class="ctv__mode-desc">{{ m.description }}</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── STEP 2: Settings ── -->
            <div v-if="step === 2" class="ctv__section">
                <div class="ctv__field">
                    <p class="ctv__hint">{{ t('teamhub', 'Configure how people can join and interact with this team.') }}</p>
                    <div class="ctv__settings-groups">
                        <div class="ctv__settings-group">
                            <span class="ctv__settings-group-label">{{ t('teamhub', 'Invitations') }}</span>
                            <NcCheckboxRadioSwitch
                                v-for="opt in inviteConfigOptions"
                                :key="opt.key"
                                v-model="form.config[opt.key]"
                                type="checkbox">
                                <span class="ctv__setting-name">{{ opt.label }}</span>
                                <span class="ctv__setting-desc">{{ opt.description }}</span>
                            </NcCheckboxRadioSwitch>
                        </div>
                        <div class="ctv__settings-group">
                            <span class="ctv__settings-group-label">{{ t('teamhub', 'Privacy') }}</span>
                            <NcCheckboxRadioSwitch
                                v-for="opt in privacyConfigOptions"
                                :key="opt.key"
                                v-model="form.config[opt.key]"
                                type="checkbox">
                                <span class="ctv__setting-name">{{ opt.label }}</span>
                                <span class="ctv__setting-desc">{{ opt.description }}</span>
                            </NcCheckboxRadioSwitch>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── STEP 3: Members ── -->
            <div v-if="step === 3" class="ctv__section">
                <div class="ctv__field">
                    <p class="ctv__hint">{{ t('teamhub', 'Invite people to join this team. You can also add members later.') }}</p>
                    <div class="ctv__member-search">
                        <NcTextField
                            v-model="memberSearch"
                            :label="t('teamhub', 'Search members')"
                            :placeholder="t('teamhub', 'Search by name or username...')"
                            @input="onMemberSearch" />
                        <div v-if="userResults.length > 0" class="ctv__user-results">
                            <div
                                v-for="user in userResults"
                                :key="(user.type || 'user') + ':' + user.id"
                                class="ctv__user-result"
                                @click="addMember(user)">
                                <div v-if="user.type === 'group'" class="ctv__group-avatar">
                                    <AccountGroup :size="20" />
                                </div>
                                <NcAvatar v-else :user="user.id" :display-name="user.displayName" :size="32" :show-user-status="false" />
                                <div class="ctv__user-info">
                                    <span class="ctv__user-name">{{ user.displayName }}</span>
                                    <span class="ctv__user-id">{{ user.type === 'group' ? t('teamhub', 'Group') : user.id }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="form.members.length > 0" class="ctv__chips">
                        <div v-for="m in form.members" :key="(m.type || 'user') + ':' + m.id" class="ctv__chip">
                            <div v-if="m.type === 'group'" class="ctv__group-avatar ctv__group-avatar--small">
                                <AccountGroup :size="16" />
                            </div>
                            <NcAvatar v-else :user="m.id" :display-name="m.displayName" :size="24" :show-user-status="false" />
                            <span>{{ m.displayName }}</span>
                            <button class="ctv__chip-remove"
                                :aria-label="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                :title="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                @click="removeMember(m.id, m.type)">
                                <Close :size="14" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── STEP 4: App integrations ── -->
            <div v-if="step === 4" class="ctv__section">
                <div class="ctv__field">
                    <p class="ctv__hint">{{ t('teamhub', 'For each app, choose whether to create a new resource for this team or connect one you already own.') }}</p>
                    <div class="ctv__apps">
                        <div v-for="app in appOptions" :key="app.id" class="ctv__app ctv__app--compact">
                            <div class="ctv__app-row">
                                <label class="ctv__app-header">
                                    <input
                                        type="checkbox"
                                        :checked="form.apps[app.id].mode !== null"
                                        class="ctv__app-check"
                                        :aria-label="t('teamhub', 'Enable {app}', { app: app.label })"
                                        @change="onAppToggle(app.id, $event)" />
                                    <component :is="app.icon" :size="24" class="ctv__app-icon" aria-hidden="true" />
                                    <div class="ctv__app-text">
                                        <span class="ctv__app-name">{{ app.label }}</span>
                                        <span class="ctv__app-desc">{{ app.description }}</span>
                                        <span v-if="app.id === 'files' && !groupfoldersAvailable" class="ctv__app-hint">
                                            {{ t('teamhub', 'Group Folders not available — using shared folder') }}
                                        </span>
                                    </div>
                                </label>
                                <div v-if="form.apps[app.id].mode !== null" class="ctv__app-toggle">
                                    <button
                                        type="button"
                                        :class="['ctv__toggle-btn', { 'ctv__toggle-btn--active': form.apps[app.id].mode === 'create' }]"
                                        :aria-pressed="form.apps[app.id].mode === 'create' ? 'true' : 'false'"
                                        @click="form.apps[app.id].mode = 'create'; form.apps[app.id].resourceId = null; form.apps[app.id].name = ''">
                                        {{ t('teamhub', 'New') }}
                                    </button>
                                    <button
                                        type="button"
                                        :class="['ctv__toggle-btn', { 'ctv__toggle-btn--active': form.apps[app.id].mode === 'connect' }]"
                                        :aria-pressed="form.apps[app.id].mode === 'connect' ? 'true' : 'false'"
                                        @click="form.apps[app.id].mode = 'connect'">
                                        {{ t('teamhub', 'Existing') }}
                                    </button>
                                </div>
                            </div>
                            <div v-if="form.apps[app.id].mode === 'connect'" class="ctv__app-picker">
                                <ResourcePicker
                                    :app="app.id"
                                    v-model="form.apps[app.id].resourceId"
                                    @selected-name="form.apps[app.id].name = $event" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TeamHub modules -->
                <div class="ctv__field">
                    <label class="ctv__label">{{ t('teamhub', 'Team modules') }}</label>
                    <p class="ctv__hint">{{ t('teamhub', 'Enable additional features for this team.') }}</p>
                    <div class="ctv__modules">
                        <label
                            v-for="mod in moduleOptions"
                            :key="mod.id"
                            :class="['ctv__module', { 'ctv__module--disabled': !mod.available }]">
                            <input
                                v-model="form.modules[mod.id]"
                                type="checkbox"
                                class="ctv__module-check"
                                :disabled="!mod.available"
                                :aria-label="mod.label" />
                            <component :is="mod.icon" :size="20" class="ctv__module-icon" aria-hidden="true" />
                            <div class="ctv__module-text">
                                <span class="ctv__module-name">{{ mod.label }}</span>
                                <span class="ctv__module-desc">{{ mod.description }}</span>
                                <span v-if="!mod.available" class="ctv__module-unavailable">
                                    {{ t('teamhub', 'Not available — disabled by your administrator') }}
                                </span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── STEP 4: Progress ── -->
            <div v-if="step === 5" class="ctv__progress">
                <div v-for="(task, i) in progressTasks" :key="i" class="ctv__progress-task">
                    <NcLoadingIcon v-if="task.status === 'running'" :size="20" />
                    <CheckCircle v-else-if="task.status === 'done'" :size="20" class="ctv__progress-done" />
                    <AlertCircle v-else-if="task.status === 'error'" :size="20" class="ctv__progress-error" />
                    <span v-else class="ctv__progress-dot" />
                    <span :class="['ctv__progress-label', { 'ctv__progress-label--dim': task.status === 'waiting' }]">
                        {{ task.label }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Footer — always at bottom -->
        <div v-if="step < 5 || creationDone" class="ctv__footer">
            <NcButton v-if="step < 5" variant="tertiary" @click="$emit('cancel')">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
            <div v-else></div>
            <div class="ctv__footer-right">
                <NcButton v-if="step > 1 && step < 5" variant="secondary" @click="step--">
                    {{ t('teamhub', 'Back') }}
                </NcButton>
                <NcButton v-if="step < 4" variant="primary" @click="nextStep">
                    {{ t('teamhub', 'Next') }}
                </NcButton>
                <NcButton v-if="step === 4" variant="primary" @click="submit">
                    <template #icon><Check :size="20" /></template>
                    {{ t('teamhub', 'Create team') }}
                </NcButton>
                <NcButton v-if="creationDone" variant="primary" @click="$emit('created', createdTeam)">
                    <template #icon><Check :size="20" /></template>
                    {{ t('teamhub', 'Open team') }}
                </NcButton>
            </div>
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import TimelineClockOutline from 'vue-material-design-icons/TimelineClockOutline.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import ResourcePicker from './ResourcePicker.vue'

// Canonical Circles config bit values — see src/constants/circlesConfig.js
import {
    CFG_VISIBLE,
    CFG_OPEN,
    CFG_INVITE,
    CFG_REQUEST,
    CFG_PROTECTED,
} from '../constants/circlesConfig.js'

export default {
    name: 'CreateTeamView',
    components: {
        NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon, NcCheckboxRadioSwitch,
        Check, Close, CheckCircle, AlertCircle,
        Chat, Folder, Calendar, CardText, Briefcase, AccountMultiple, AccountGroup, OfficeBuildingOutline,
        Gavel, AccountClock, TimelineClockOutline, FileDocumentOutline, LockOutline,
        ResourcePicker,
    },
    emits: ['created', 'cancel'],
    data() {
        return {
            step: 1,
            nameError: '',
            memberSearch: '',
            userResults: [],
            searchTimer: null,
            progressTasks: [],
            intravoxAvailable: false,
            talkAvailable: true,
            calendarAvailable: true,
            deckAvailable: true,
            groupfoldersAvailable: false,
            presenceModuleEnabled: false,
            decisionsModuleEnabled: false,
            wizardDescription: '',
            creationDone: false,
            createdTeam: null,
            // v3.100.1 — cheap license entitlements probe. Fetched
            // once in mounted() from GET /license/entitlements (member-
            // callable, minimal payload). Drives whether the "Advanced"
            // project-mode tile is selectable or shown as a locked upsell.
            licenseCanCreateAdvanced: true,   // optimistic default; corrected on mount
            form: {
                name: '',
                description: '',
                teamType: 'collaboration',
                // Project Teams (v3.88.0) — only meaningful when teamType==='project'.
                // 'advanced' = guided PMC lifecycle (default, "force into project mode");
                // 'basic' = the historical cosmetic project preset, still recorded.
                projectMode: 'advanced',
                members: [],
                apps: {
                    talk:     { mode: null, resourceId: null, name: '' },
                    files:    { mode: null, resourceId: null, name: '' },
                    calendar: { mode: null, resourceId: null, name: '' },
                    deck:     { mode: null, resourceId: null, name: '' },
                },
                modules: {
                    decisions: true,
                    presence: false,
                    timeline: false,
                    messages: true,
                    pages: true,
                },
                config: {
                    open: false,         // anyone can join
                    invite: true,        // members can invite
                    request: false,      // requests need approval
                    visible: false,      // visible to all
                    protected: false,    // password-protect shared files
                },
            },
        }
    },
    computed: {
        steps() {
            return [t('teamhub', 'Details'), t('teamhub', 'Settings'), t('teamhub', 'Members'), t('teamhub', 'Apps')]
        },
        teamTypes() {
            return [
                { id: 'project', label: t('teamhub', 'Project'), description: t('teamhub', 'Time-bound work with clear goals'), icon: 'Briefcase', accent: 'project' },
                { id: 'collaboration', label: t('teamhub', 'Collaboration'), description: t('teamhub', 'Ongoing team knowledge sharing'), icon: 'AccountMultiple', accent: 'collaboration' },
                { id: 'department', label: t('teamhub', 'Department'), description: t('teamhub', 'Organizational department or unit'), icon: 'OfficeBuildingOutline', accent: 'department' },
            ]
        },
        projectModes() {
            const advancedLocked = !this.licenseCanCreateAdvanced
            return [
                {
                    id: 'advanced',
                    // TRANSLATORS: project setup mode — the full guided project experience
                    label: t('teamhub', 'Advanced'),
                    description: advancedLocked
                        // TRANSLATORS: shown on the Advanced project mode tile when the instance has no valid business license
                        ? t('teamhub', 'This feature requires a license — ask your admin.')
                        : t('teamhub', 'Guided project lifecycle — phases (Initiation, Planning, Execution, Closing) and project tools.'),
                    locked: advancedLocked,
                },
                {
                    id: 'basic',
                    // TRANSLATORS: project setup mode — the simple, no-lifecycle project experience
                    label: t('teamhub', 'Basic'),
                    description: t('teamhub', 'A project-flavoured team with the familiar setup — no lifecycle tools.'),
                    locked: false,
                },
            ]
        },
        appOptions() {
            const filesDesc = this.groupfoldersAvailable
                ? t('teamhub', 'Create a group folder for this team')
                : t('teamhub', 'Create a shared folder for this team')
            const all = [
                { id: 'talk',     label: 'Talk',     description: t('teamhub', 'Create a group conversation for this team'), icon: Chat,     available: this.talkAvailable },
                { id: 'files',    label: 'Files',    description: filesDesc,                                                  icon: Folder,   available: true },
                { id: 'calendar', label: 'Calendar', description: t('teamhub', 'Create a shared calendar for this team'),     icon: Calendar, available: this.calendarAvailable },
                { id: 'deck',     label: 'Deck',     description: t('teamhub', 'Create a task board for this team'),          icon: CardText, available: this.deckAvailable },
            ]
            return all.filter(a => a.available)
        },
        moduleOptions() {
            return [
                {
                    id: 'decisions',
                    label: t('teamhub', 'Decisions'),
                    description: t('teamhub', 'Track and record team decisions'),
                    icon: Gavel,
                    available: this.decisionsModuleEnabled,
                },
                {
                    id: 'presence',
                    label: t('teamhub', 'Presence'),
                    description: t('teamhub', 'Track team member availability'),
                    icon: AccountClock,
                    available: this.presenceModuleEnabled,
                },
                {
                    id: 'timeline',
                    label: t('teamhub', 'Timeline'),
                    description: t('teamhub', 'Visualize team activity over time'),
                    icon: TimelineClockOutline,
                    available: true,
                },
                {
                    id: 'messages',
                    label: t('teamhub', 'Messages'),
                    description: t('teamhub', 'Team message stream — posts, questions, polls, pinned messages'),
                    icon: MessageOutline,
                    available: true,
                },
                {
                    id: 'pages',
                    label: t('teamhub', 'Pages'),
                    description: t('teamhub', 'Team documentation and knowledge base'),
                    icon: FileDocumentOutline,
                    available: this.intravoxAvailable,
                },
            ]
        },
        templateProfile() {
            const profiles = {
                project: {
                    // v3.99.6 — Talk preselected for project teams. Justin
                    // wanted advanced projects to open the wizard with Talk
                    // enabled; since Advanced is the default projectMode
                    // and the user can still uncheck Talk in Step 2, we
                    // just preselect it at the profile level rather than
                    // adding a projectMode watcher.
                    apps: { talk: 'create', files: 'create', calendar: 'create', deck: 'create' },
                    config: { open: false, invite: true, request: false, visible: false, protected: false },
                    modules: { decisions: true, presence: false, timeline: true, messages: true, pages: true },
                    subtitle: t('teamhub', 'Set up a project team in a few steps'),
                    placeholder: t('teamhub', 'e.g. Website Redesign'),
                },
                collaboration: {
                    apps: { talk: 'create', files: 'create', calendar: null, deck: null },
                    config: { open: false, invite: true, request: false, visible: true, protected: false },
                    modules: { decisions: true, presence: false, timeline: false, messages: true, pages: true },
                    subtitle: t('teamhub', 'Set up a collaboration space in a few steps'),
                    placeholder: t('teamhub', 'e.g. Design Guild'),
                },
                department: {
                    apps: { talk: 'create', files: 'create', calendar: 'create', deck: null },
                    config: { open: false, invite: false, request: false, visible: true, protected: false },
                    modules: { decisions: true, presence: true, timeline: false, messages: true, pages: true },
                    subtitle: t('teamhub', 'Set up a department team in a few steps'),
                    placeholder: t('teamhub', 'e.g. Human Resources'),
                },
            }
            return profiles[this.form.teamType] || profiles.collaboration
        },
        namePlaceholder() {
            return this.templateProfile.placeholder
        },
        // Pre-grouped views of configOptions so the template's two v-for loops
        // don't re-allocate a filtered array on every render (perf pass V6).
        inviteConfigOptions() {
            return this.configOptions.filter(o => o.group === 'invite')
        },
        privacyConfigOptions() {
            return this.configOptions.filter(o => o.group === 'privacy')
        },
        configOptions() {
            return [
                { key: 'open',         group: 'invite', label: t('teamhub', 'Anyone can join'),               description: t('teamhub', 'No invitation needed — anyone can become a member') },
                { key: 'invite',       group: 'invite', label: t('teamhub', 'Members can invite others'),      description: t('teamhub', 'Existing members can invite new people') },
                { key: 'request',      group: 'invite', label: t('teamhub', 'Requests need moderator approval'), description: t('teamhub', 'Requires "Anyone can join" to be active') },
                { key: 'visible',      group: 'privacy', label: t('teamhub', 'Visible to everyone'),           description: t('teamhub', 'This team appears in the team directory') },
                { key: 'protected',    group: 'privacy', label: t('teamhub', 'Password-protect shared files'), description: t('teamhub', 'Enforce password on files shared with this team') },
            ]
        },
        configValue() {
            let v = 0
            if (this.form.config.open)         v |= CFG_OPEN
            if (this.form.config.invite)        v |= CFG_INVITE
            if (this.form.config.request)       v |= CFG_REQUEST
            if (this.form.config.protected)     v |= CFG_PROTECTED
            if (this.form.config.visible)       v |= CFG_VISIBLE
            // System bits (CFG_SINGLE, CFG_SYSTEM, CFG_NO_OWNER, CFG_HIDDEN,
            // CFG_BACKEND) are never written by TeamHub — Circles manages them
            // internally and setting them on a user team corrupts its state.
            // Team-as-member prevention is enforced server-side in MemberService.
            return v
        },
    },
    watch: {
        'form.teamType': {
            handler() {
                this.applyTemplateDefaults()
            },
            immediate: true,
        },
    },
    async mounted() {
        await this.checkIntravox()
        this.applyTemplateDefaults()
        await this.loadWizardDescription()
        await this.loadLicenseEntitlements()
    },
    methods: {
        t,

        applyTemplateDefaults() {
            const profile = this.templateProfile
            const availableIds = new Set(this.appOptions.map(a => a.id))
            for (const [appId, mode] of Object.entries(profile.apps)) {
                if (this.form.apps[appId]) {
                    this.form.apps[appId].mode = availableIds.has(appId) ? mode : null
                    if (this.form.apps[appId].mode === null) {
                        this.form.apps[appId].resourceId = null
                        this.form.apps[appId].name = ''
                    }
                }
            }
            for (const [key, val] of Object.entries(profile.config)) {
                this.form.config[key] = val
            }
            for (const [modId, val] of Object.entries(profile.modules)) {
                const mod = this.moduleOptions.find(m => m.id === modId)
                this.form.modules[modId] = mod && mod.available ? val : false
            }
        },

        nextStep() {
            this.nameError = ''
            if (this.step === 1 && !this.form.name.trim()) {
                this.nameError = t('teamhub', 'Team name is required')
                return
            }
            this.step++
        },

        onMemberSearch() {
            clearTimeout(this.searchTimer)
            if (this.memberSearch.length < 2) { this.userResults = []; return }
            this.searchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/users/search'),
                        { params: { q: this.memberSearch } }
                    )
                    const added = new Set(this.form.members.map(m => (m.type || 'user') + ':' + m.id))
                    this.userResults = (data || [])
                        .filter(u => !added.has((u.type || 'user') + ':' + u.id))
                        .map(u => ({ id: u.id, displayName: u.displayName || u.id, type: u.type || 'user' }))
                } catch { this.userResults = [] }
            }, 300)
        },

        addMember(user) {
            this.form.members.push(user)
            this.memberSearch = ''
            this.userResults = []
        },

        removeMember(userId, type) {
            const t = type || 'user'
            this.form.members = this.form.members.filter(m => !(m.id === userId && (m.type || 'user') === t))
        },

        setTask(index, status) {
            if (this.progressTasks[index]) {
                this.progressTasks[index] = { ...this.progressTasks[index], status }
            }
        },

        // Step 4 — toggle an app on/off. When turning on we default to "create new";
        // when turning off we clear any pending picker selection.
        onAppToggle(appId, event) {
            if (event.target.checked) {
                this.form.apps[appId].mode = 'create'
            } else {
                this.form.apps[appId].mode = null
                this.form.apps[appId].resourceId = null
                this.form.apps[appId].name = ''
            }
        },

        async submit() {
            // Validate: any app set to "connect" must have a resourceId selected.
            const incompleteConnect = Object.entries(this.form.apps)
                .find(([, v]) => v.mode === 'connect' && !v.resourceId)
            if (incompleteConnect) {
                const appLabel = (this.appOptions.find(a => a.id === incompleteConnect[0]) || {}).label
                    || incompleteConnect[0]
                showError(
                    // TRANSLATORS: error shown when the user picked "Connect existing" for an app but didn't pick a resource. {app} is e.g. "Calendar".
                    t('teamhub', 'Please select an item to connect for {app}, or switch it to "Create new for this team".', { app: appLabel })
                )
                return
            }

            // Apps split by mode.
            const appsToCreate  = Object.entries(this.form.apps)
                .filter(([, v]) => v.mode === 'create').map(([k]) => k)
            const appsToConnect = Object.entries(this.form.apps)
                .filter(([, v]) => v.mode === 'connect' && v.resourceId)
                .map(([k, v]) => ({ app: k, resourceId: v.resourceId }))
            const anyAppEnabled = Object.values(this.form.apps).some(v => v.mode !== null)

            // Build task list
            const tasks = [{ label: t('teamhub', 'Creating team'), status: 'waiting' }]
            if (this.form.description.trim()) tasks.push({ label: t('teamhub', 'Saving description'), status: 'waiting' })
            if (this.form.members.length > 0) tasks.push({ label: t('teamhub', 'Inviting members'), status: 'waiting' })
            if (appsToCreate.length > 0) tasks.push({ label: t('teamhub', 'Creating new app resources'), status: 'waiting' })
            if (appsToConnect.length > 0) tasks.push({ label: t('teamhub', 'Connecting existing app resources'), status: 'waiting' })
            if (this.intravoxAvailable && this.form.modules.pages) tasks.push({ label: t('teamhub', 'Creating documentation page'), status: 'waiting' })
            const hasModuleConfig = this.form.modules.presence || this.form.modules.decisions || !this.form.modules.timeline
            if (hasModuleConfig) tasks.push({ label: t('teamhub', 'Configuring team modules'), status: 'waiting' })
            if (this.form.teamType === 'project') tasks.push({ label: t('teamhub', 'Setting up project'), status: 'waiting' })

            // Build the full app-state payload for ALL known apps so the backend can
            // persist enabled/disabled in teamhub_team_apps immediately after team creation.
            // An app counts as "enabled" if it has any non-null mode (create or connect).
            //
            // Note: the wizard uses 'talk' but teamhub_team_apps stores 'spreed' (NC app name).
            const wizardToAppId = { talk: 'spreed' }
            const appStates = Object.entries(this.form.apps).map(([k, v]) => ({
                app_id: wizardToAppId[k] || k,
                enabled: v.mode !== null,
            }))
            if (this.intravoxAvailable) {
                appStates.push({ app_id: 'intravox', enabled: this.form.modules.pages })
            }

            this.progressTasks = tasks
            this.step = 5  // Progress is step 5

            let i = 0
            let team = null

            try {
                // 1. Create team
                this.setTask(i, 'running')
                const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/teams'), {
                    name: this.form.name.trim(),
                })
                team = data
                this.setTask(i++, 'done')

                // 2. Save config (always — even default 0 is meaningful)
                const configVal = this.configValue
                if (configVal > 0) {
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/config`),
                            { config: configVal }
                        )
                    } catch (e) { /* non-fatal */ }
                }

                // 3. Save description
                if (this.form.description.trim()) {
                    this.setTask(i, 'running')
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/description`),
                            { description: this.form.description.trim() }
                        )
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 4. Invite members
                if (this.form.members.length > 0) {
                    this.setTask(i, 'running')
                    try {
                        await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/invite-members`),
                            { members: this.form.members.map(m => ({ id: m.id, type: m.type || 'user' })) }
                        )
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 5a. Create new app resources for "create" mode apps (also persists appStates).
                if (appsToCreate.length > 0) {
                    this.setTask(i, 'running')
                    try {
                        // Project Teams (v3.90.x) — when Deck is among appsToCreate and this
                        // is an Advanced project, DeckService seeds the "Project management"
                        // stack + starter cards. Irrelevant for the other create-resources
                        // calls below (persist-only / connect-existing paths never create a
                        // new Deck board, so they don't need it).
                        const projectMode = this.form.teamType === 'project' ? this.form.projectMode : null
                        const { data: resourceResults } = await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                            { apps: appsToCreate, teamName: team.name, appStates, projectMode }
                        )
                        const anyError = Object.values(resourceResults).some(r => r?.error)
                        this.setTask(i++, anyError ? 'error' : 'done')
                    } catch (e) {
                        this.setTask(i++, 'error')
                    }
                } else if (!anyAppEnabled || appsToConnect.length === 0) {
                    // Persist appStates even when nothing is being created — keeps manage view honest.
                    axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                        { apps: [], teamName: team.name, appStates }
                    ).catch(() => {})
                }

                // 5b. Connect existing app resources for "connect" mode apps.
                //     The connect endpoint persists each app's team_apps row itself, so we
                //     only need to call it per-app. If we had no "create" call, we still
                //     need to make sure appStates is persisted — do it via the empty
                //     create-resources call above (the else-if path).
                if (appsToConnect.length > 0) {
                    if (appsToCreate.length === 0) {
                        // No create call has fired — persist appStates for non-connecting apps now.
                        axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                            { apps: [], teamName: team.name, appStates }
                        ).catch(() => {})
                    }

                    this.setTask(i, 'running')
                    let connectErrors = 0
                    for (const { app, resourceId } of appsToConnect) {
                        try {
                            await axios.post(
                                generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/resources/${app}/connect`),
                                { resourceId }
                            )
                        } catch (e) {
                            connectErrors++
                            const detail = e?.response?.data?.error || e?.message || ''
                            showError(detail
                                // TRANSLATORS: error shown when connecting an existing resource fails. {app} is e.g. "Calendar", {error} is the detail.
                                ? t('teamhub', 'Could not connect {app}: {error}', { app, error: detail })
                                : t('teamhub', 'Could not connect {app}', { app })
                            )
                        }
                    }
                    this.setTask(i++, connectErrors > 0 ? 'error' : 'done')
                }

                // 6. IntraVox page (only if installed and pages module enabled)
                if (this.intravoxAvailable && this.form.modules.pages) {
                    this.setTask(i, 'running')
                    try {
                        await this.createIntravoxPage(team)
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 7. Configure team modules (presence, decisions, timeline)
                if (hasModuleConfig) {
                    this.setTask(i, 'running')
                    try {
                        await this.saveModuleConfig(team.id)
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // 8. Persist project-ness (Project template only) — records mode so the
                //    team is a real project (basic or advanced) from creation onward.
                if (this.form.teamType === 'project') {
                    this.setTask(i, 'running')
                    try {
                        await axios.put(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/project`),
                            { mode: this.form.projectMode }
                        )
                        // Project-owner onboarding (v3.90.x) — one-shot signal read
                        // once by TeamView on first open of this team to auto-show
                        // the phase guide. Advanced only; Basic has no phase to guide.
                        if (this.form.projectMode === 'advanced') {
                            this.$store.commit('SET_JUST_CREATED_ADVANCED_PROJECT', team.id)
                        }
                        this.setTask(i++, 'done')
                    } catch { this.setTask(i++, 'error') }
                }

                // Show completed progress for a moment, then show "Open team" button
                await new Promise(r => setTimeout(r, 600))
                this.createdTeam = team
                this.creationDone = true

            } catch (error) {
                if (i < this.progressTasks.length) this.setTask(i, 'error')
                const msg = error.response?.data?.error || error.response?.data?.message
                showError(msg
                    ? t('teamhub', 'Failed to create team: {error}', { error: msg })
                    : t('teamhub', 'Failed to create team')
                )
                setTimeout(() => { this.step = 1 }, 1500)
            }
        },

        async checkIntravox() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                this.intravoxAvailable        = !!data.intravox
                this.talkAvailable            = !!data.talk
                this.calendarAvailable        = !!data.calendar
                this.deckAvailable            = !!data.deck
                this.groupfoldersAvailable    = !!data.groupfolders
                this.presenceModuleEnabled    = !!data.presenceModuleEnabled
                this.decisionsModuleEnabled   = !!data.decisionsModuleEnabled
            } catch (e) {
                this.intravoxAvailable = false
            }
        },

        async loadWizardDescription() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.wizardDescription = data.wizardDescription || ''
            } catch {
                this.wizardDescription = ''
            }
        },

        /**
         * v3.100.1 — Check whether the instance's TeamHub Business
         * license allows creating new Advanced projects. Endpoint is
         * member-callable and returns only { canCreateAdvanced,
         * enforcementLevel } — no sensitive license detail.
         *
         * When Advanced is locked out and the wizard defaulted to
         * projectMode='advanced', silently flip to 'basic' so the user
         * has a working selection preselected — the tile remains
         * visible but greyed to explain why they can't pick it.
         */
        async loadLicenseEntitlements() {
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/license/entitlements')
                )
                this.licenseCanCreateAdvanced = !!data?.canCreateAdvanced
                if (!this.licenseCanCreateAdvanced && this.form.projectMode === 'advanced') {
                    this.form.projectMode = 'basic'
                }
            } catch {
                // Endpoint error → fail-open (assume Advanced allowed).
                // The backend upsert() still enforces the gate on submit,
                // so the worst case is we don't grey out the tile — the
                // user gets a clean 403 later instead of the pre-flight
                // upsell. Old behavior.
                this.licenseCanCreateAdvanced = true
            }
        },

        async saveModuleConfig(teamId) {
            const calls = []
            if (this.form.modules.presence && this.presenceModuleEnabled) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/presence/config`),
                        { presence_enabled: 1 }
                    )
                )
            }
            if (this.form.modules.decisions && this.decisionsModuleEnabled) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/decisions/config`),
                        { decisions_enabled: 1 }
                    )
                )
            }
            if (!this.form.modules.timeline) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/timeline/config`),
                        { timeline_enabled: 0 }
                    )
                )
            }
            // v3.104.1 — Messages is default on. Only PUT when unchecked so
            // the backend default holds otherwise (fewer create-time calls).
            if (!this.form.modules.messages) {
                calls.push(
                    axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/config`),
                        { messages_enabled: 0 }
                    )
                )
            }
            await Promise.all(calls)
        },

        async createIntravoxPage(team) {
            // Route through TeamHub's IntravoxService — reads admin config for parentPath,
            // uses in-process PageService call (no loopback HTTP).
            // Project Teams (v3.88.x) — Advanced projects get the 9-element charter
            // seeded server-side; Basic/Collaboration/Department keep the blank page.
            const projectMode = this.form.teamType === 'project' ? this.form.projectMode : null
            await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/intravox/page`),
                { projectMode }
            )
        },

        toSlug(text) {
            return (text || '').toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '').trim()
                .replace(/\s+/g, '-').replace(/-+/g, '-') || 'team'
        },
    },
}
</script>

<style scoped>
/* Canvas layout: natural document flow, footer follows content */
.ctv {
    display: flex;
    flex-direction: column;
}

.ctv__inner {
    max-width: 680px;
    width: 100%;
    margin: 0 auto;
    padding: 40px 40px 0;
    box-sizing: border-box;
}

.ctv__header { margin-bottom: 32px; }

.ctv__title {
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 6px;
}

.ctv__subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* Steps */
.ctv__steps {
    display: flex;
    align-items: center;
    margin-bottom: 36px;
}

.ctv__step-wrap {
    display: flex;
    align-items: center;
    flex: 1;
}

.ctv__step-wrap:last-child { flex: 0; }

.ctv__step {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    opacity: 0.4;
    transition: opacity 0.2s;
}

.ctv__step--active, .ctv__step--done { opacity: 1; }

.ctv__step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--color-border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

.ctv__step--active .ctv__step-num {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__step--done .ctv__step-num {
    background: var(--color-success);
    color: var(--color-success-text);
}

.ctv__step-label { font-size: var(--th-font-body); font-weight: 500; }

.ctv__step-line {
    flex: 1;
    height: 2px;
    background: var(--color-border);
    margin: 0 16px;
}

/* Content sections */
.ctv__section {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding-bottom: 24px;
}

.ctv__field { display: flex; flex-direction: column; gap: 8px; }

.ctv__label { font-size: var(--th-font-body); font-weight: 600; }
.ctv__hint { font-size: 13px; color: var(--color-text-maxcontrast); margin: 0 0 4px; }

/* Team types */
.ctv__types {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.ctv__type {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px 12px;
    /* v3.100.14: neutral resting border (was --color-success-hover — a
       transient hover token, and the resting border isn't a state signal
       anyway; the icon accent below carries the type/state cue). */
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: center;
    transition: border-color 0.15s, background 0.15s;
}

/* Icon accent — one consistent colour across all three template-type cards.
   Only selection state distinguishes a card now, not which type it is. */
.ctv__type-icon { color: var(--color-success); }

.ctv__type:hover { background: var(--color-background-hover); }

/* Selected state — full-saturation primary token + matching text token
   (SKILLS.md state-colour rule). Matches the Basic/Advanced mode selector's
   treatment below and the phase stepper's active/info markers. Supersedes the
   earlier soft-tint exception (DESIGN.md §2.37) — reverted per Justin's
   follow-up request for visual consistency across all selection tiles.
   Uses --color-primary-element (not --color-success) so a *selected* tile
   doesn't read as a "success/done" state — success is reserved for the
   phase stepper's completed markers. */
.ctv__type--selected,
.ctv__type--selected:hover {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__type-name { font-weight: 600; font-size: var(--th-font-body); }
.ctv__type-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); line-height: 1.4; }
.ctv__type--selected .ctv__type-desc { color: var(--color-primary-element-text); }

/* Project mode selector (Basic / Advanced) */
.ctv__modes {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.ctv__mode {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px 16px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}

.ctv__mode:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.ctv__mode:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Selected mode — full-saturation primary token + matching text (SKILLS.md). */
.ctv__mode--selected {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* Re-assert selected colours on hover — .ctv__mode:hover has equal CSS
   specificity (class + pseudo-class) to .ctv__mode--selected (single class)
   and was winning the tie, reverting a hovered selected tile to the grey
   hover background while its text stayed white — unreadable. */
.ctv__mode--selected:hover {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__mode-name { font-weight: 600; font-size: var(--th-font-body); }
.ctv__mode-desc { font-size: var(--th-font-meta); line-height: 1.4; color: var(--color-text-maxcontrast); }
.ctv__mode--selected .ctv__mode-desc { color: var(--color-primary-element-text); }

/* v3.100.1 — locked-out project mode (Advanced without a valid license).
   Not disabled outright — we still render the tile so the user sees the
   feature exists — but the click handler no-ops and the tile is muted so
   it doesn't compete visually with the picked mode. */
.ctv__mode--locked {
    cursor: not-allowed;
    opacity: 0.55;
    background: var(--color-background-hover);
}
.ctv__mode--locked:hover {
    border-color: var(--color-border);
    background: var(--color-background-hover);
}
/* MDI icons render as an inline-block <span> containing the SVG; nudge
   alignment so the lock sits on the text baseline of the label. */
.ctv__mode-lock {
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    vertical-align: -2px;
    color: var(--color-text-maxcontrast);
}

/* Member search */
.ctv__member-search { position: relative; }

.ctv__user-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 200;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    max-height: 240px;
    overflow-y: auto;
}

.ctv__user-result {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
}

.ctv__user-result:hover { background: var(--color-background-hover); }

.ctv__group-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    /* v3.100.14: neutral surface for a decorative "not a photo"
       avatar per SKILLS.md — the primary-coloured icon inside carries
       the accent. Was --color-primary-element-light which is a state
       token and shouldn't back non-state surfaces. */
    background: var(--color-background-dark);
    color: var(--color-primary-element);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ctv__group-avatar--small {
    width: 24px;
    height: 24px;
}

.ctv__user-info { display: flex; flex-direction: column; }
.ctv__user-name { font-size: var(--th-font-body); font-weight: 500; }
.ctv__user-id { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

.ctv__chips { display: flex; flex-wrap: wrap; gap: 8px; }

.ctv__chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 10px 4px 6px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-pill);
    font-size: 13px;
}

.ctv__chip-remove {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
}

.ctv__chip-remove:hover { color: var(--color-error-text); }

/* App options */
.ctv__apps { display: flex; flex-direction: column; gap: 10px; }

.ctv__app {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}

.ctv__app:hover { background: var(--color-background-hover); }
/* v3.104.5: reverted 3.100.14's full-saturation treatment. The Apps
   step is a MULTI-select (a project team ticks all four apps by
   default), so all four tiles turn into solid dark-green blocks with
   white text on them — the eye reads the whole card as a "primary
   button" and the app name washes out. Reverted to the light-tint
   background + dark border pattern used pre-3.100.14: the border tells
   you it's selected, the light tint is a soft state cue, and both the
   app name and description keep their normal (readable) text colours.
   Same reasoning applied to .ctv__module below. */
.ctv__app:has(.ctv__app-check:checked) {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

.ctv__app-check {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--color-primary-element);
    flex-shrink: 0;
}

.ctv__app-icon { color: var(--color-primary-element); flex-shrink: 0; }
.ctv__app-text { display: flex; flex-direction: column; gap: 2px; }
.ctv__app-name { font-size: var(--th-font-body); font-weight: 600; }
.ctv__app-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

/* Compact variant: header row with inline toggle, picker below only when needed */
.ctv__app--compact {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
    cursor: default;
}

.ctv__app-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ctv__app-header {
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    flex: 1;
    min-width: 0;
}

/* Segmented New/Existing toggle */
.ctv__app-toggle {
    display: flex;
    flex-shrink: 0;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius-pill);
    overflow: hidden;
}

.ctv__toggle-btn {
    background: transparent;
    border: none;
    padding: 4px 12px;
    font-size: var(--th-font-meta);
    font-weight: 500;
    cursor: pointer;
    color: var(--color-text-maxcontrast);
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

.ctv__toggle-btn:hover { background: var(--color-background-hover); }

.ctv__toggle-btn--active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctv__toggle-btn--active:hover {
    background: var(--color-primary-element-hover);
}

.ctv__app-picker {
    margin-left: 56px;
    margin-top: 8px;
    margin-bottom: 4px;
    max-width: 360px;
}

/* Progress */
.ctv__progress {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 32px 0;
}

.ctv__progress-task {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 15px;
}

.ctv__progress-done { color: var(--color-success-text); }
.ctv__progress-error { color: var(--color-error-text); }
.ctv__progress-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--color-border);
    display: inline-block;
    flex-shrink: 0;
}
.ctv__progress-label--dim { color: var(--color-text-maxcontrast); }

/* Footer */
.ctv__footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 680px;
    width: 100%;
    margin: 0 auto;
    padding: 24px 40px;
    border-top: 1px solid var(--color-border);
    box-sizing: border-box;
}

.ctv__footer-right { display: flex; gap: 8px; }

/* Team settings */
.ctv__settings-groups {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.ctv__settings-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.ctv__settings-group-label {
    font-size: var(--th-font-meta);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin-bottom: 6px;
    display: block;
}

.ctv__setting {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.12s;
}

.ctv__setting:hover { background: var(--color-background-hover); }

.ctv__setting-name { font-size: var(--th-font-body); font-weight: 500; line-height: 1.3; display: block; }
.ctv__setting-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); line-height: 1.4; display: block; }

/* Files — group folders hint */
.ctv__app-hint {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    font-style: italic;
    display: block;
    margin-top: 2px;
}

/* Team modules */
.ctv__modules { display: flex; flex-direction: column; gap: 8px; }

.ctv__module {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}

.ctv__module:hover { background: var(--color-background-hover); }
/* v3.104.5: reverted 3.100.14's full-saturation treatment — same
   reasoning as .ctv__app above. Team modules is a multi-select and a
   fully saturated selected state read as "everything is a primary
   action" with unreadable module names in white on dark green.
   Light-tint background + dark border is the correct pattern. */
.ctv__module:has(.ctv__module-check:checked) {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

.ctv__module--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.ctv__module--disabled:hover { background: transparent; }

.ctv__module-check {
    width: 16px;
    height: 16px;
    margin-top: 2px;
    accent-color: var(--color-primary-element);
    flex-shrink: 0;
    cursor: inherit;
}

.ctv__module-icon { color: var(--color-primary-element); flex-shrink: 0; margin-top: 1px; }
.ctv__module--disabled .ctv__module-icon { color: var(--color-text-maxcontrast); }
.ctv__module-text { display: flex; flex-direction: column; gap: 2px; }
.ctv__module-name { font-size: var(--th-font-body); font-weight: 600; }
.ctv__module-desc { font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }

.ctv__module-unavailable {
    font-size: var(--th-font-micro);
    color: var(--color-warning-text);
    font-style: italic;
    margin-top: 2px;
}
</style>
