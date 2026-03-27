<template>
    <div class="ctv">
        <div class="ctv__inner">
            <div class="ctv__header">
                <h2 class="ctv__title">{{ t('teamhub', 'Create new team') }}</h2>
                <p class="ctv__subtitle">{{ wizardDescription || t('teamhub', 'Set up your team in a few steps') }}</p>
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
                        :placeholder="t('teamhub', 'e.g. Marketing Team')"
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
                            :class="['ctv__type', { 'ctv__type--selected': form.teamType === type.id }]"
                            @click="form.teamType = type.id">
                            <component :is="type.icon" :size="32" class="ctv__type-icon" />
                            <span class="ctv__type-name">{{ type.label }}</span>
                            <span class="ctv__type-desc">{{ type.description }}</span>
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
                                v-for="opt in configOptions.filter(o => o.group === 'invite')"
                                :key="opt.key"
                                :checked.sync="form.config[opt.key]"
                                type="checkbox">
                                <span class="ctv__setting-name">{{ opt.label }}</span>
                                <span class="ctv__setting-desc">{{ opt.description }}</span>
                            </NcCheckboxRadioSwitch>
                        </div>
                        <div class="ctv__settings-group">
                            <span class="ctv__settings-group-label">{{ t('teamhub', 'Membership') }}</span>
                            <NcCheckboxRadioSwitch
                                v-for="opt in configOptions.filter(o => o.group === 'member')"
                                :key="opt.key"
                                :checked.sync="form.config[opt.key]"
                                type="checkbox">
                                <span class="ctv__setting-name">{{ opt.label }}</span>
                                <span class="ctv__setting-desc">{{ opt.description }}</span>
                            </NcCheckboxRadioSwitch>
                        </div>
                        <div class="ctv__settings-group">
                            <span class="ctv__settings-group-label">{{ t('teamhub', 'Privacy') }}</span>
                            <NcCheckboxRadioSwitch
                                v-for="opt in configOptions.filter(o => o.group === 'privacy')"
                                :key="opt.key"
                                :checked.sync="form.config[opt.key]"
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
                            <button class="ctv__chip-remove" @click="removeMember(m.id, m.type)">
                                <Close :size="14" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── STEP 4: App integrations ── -->
            <div v-if="step === 4" class="ctv__section">
                <div class="ctv__field">
                    <p class="ctv__hint">{{ t('teamhub', 'TeamHub will create a dedicated space in each selected app and add this team as a member.') }}</p>
                    <div class="ctv__apps">
                        <label v-for="app in appOptions" :key="app.id" class="ctv__app">
                            <input v-model="form.apps[app.id]" type="checkbox" class="ctv__app-check" />
                            <component :is="app.icon" :size="24" class="ctv__app-icon" />
                            <div class="ctv__app-text">
                                <span class="ctv__app-name">{{ app.label }}</span>
                                <span class="ctv__app-desc">{{ app.description }}</span>
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
            <NcButton v-if="step < 5" type="tertiary" @click="$emit('cancel')">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
            <div v-else></div>
            <div class="ctv__footer-right">
                <NcButton v-if="step > 1 && step < 5" type="secondary" @click="step--">
                    {{ t('teamhub', 'Back') }}
                </NcButton>
                <NcButton v-if="step < 4" type="primary" @click="nextStep">
                    {{ t('teamhub', 'Next') }}
                </NcButton>
                <NcButton v-if="step === 4" type="primary" @click="submit">
                    <template #icon><Check :size="20" /></template>
                    {{ t('teamhub', 'Create team') }}
                </NcButton>
                <NcButton v-if="creationDone" type="primary" @click="$emit('created', createdTeam)">
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

// templateId values confirmed from /api/templates response:
// available ids: department, event, knowledge-base, landing-page, news-article, news-hub, project
const INTRAVOX_TEMPLATES = {
    project:       'project',
    collaboration: 'knowledge-base',
    department:    'department',
}

export default {
    name: 'CreateTeamView',
    components: {
        NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon, NcCheckboxRadioSwitch,
        Check, Close, CheckCircle, AlertCircle,
        Chat, Folder, Calendar, CardText, Briefcase, AccountMultiple, AccountGroup, OfficeBuildingOutline,
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
            wizardDescription: '',
            creationDone: false,
            createdTeam: null,
            form: {
                name: '',
                description: '',
                teamType: 'collaboration',
                members: [],
                apps: { talk: false, files: false, calendar: false, deck: false },
                config: {
                    open: false,         // anyone can join
                    invite: true,        // members can invite
                    request: false,      // requests need approval
                    visible: false,      // visible to all
                    protected: false,    // password-protect shared files
                    singleMember: false, // prevent sub-teams
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
                { id: 'project', label: t('teamhub', 'Project'), description: t('teamhub', 'Time-bound work with clear goals'), icon: 'Briefcase' },
                { id: 'collaboration', label: t('teamhub', 'Collaboration'), description: t('teamhub', 'Ongoing team knowledge sharing'), icon: 'AccountMultiple' },
                { id: 'department', label: t('teamhub', 'Department'), description: t('teamhub', 'Organizational department or unit'), icon: 'OfficeBuildingOutline' },
            ]
        },
        appOptions() {
            const all = [
                { id: 'talk',     label: 'Talk',     description: t('teamhub', 'Create a group conversation for this team'), icon: Chat,     available: this.talkAvailable },
                { id: 'files',    label: 'Files',    description: t('teamhub', 'Create a shared folder for this team'),       icon: Folder,   available: true },
                { id: 'calendar', label: 'Calendar', description: t('teamhub', 'Create a shared calendar for this team'),     icon: Calendar, available: this.calendarAvailable },
                { id: 'deck',     label: 'Deck',     description: t('teamhub', 'Create a task board for this team'),          icon: CardText, available: this.deckAvailable },
            ]
            return all.filter(a => a.available)
        },
        configOptions() {
            return [
                { key: 'open',         group: 'invite', label: t('teamhub', 'Anyone can join'),               description: t('teamhub', 'No invitation needed — anyone can become a member') },
                { key: 'invite',       group: 'invite', label: t('teamhub', 'Members can invite others'),      description: t('teamhub', 'Existing members can invite new people') },
                { key: 'request',      group: 'invite', label: t('teamhub', 'Requests need moderator approval'), description: t('teamhub', 'Requires "Anyone can join" to be active') },
                { key: 'singleMember', group: 'member', label: t('teamhub', 'Prevent sub-team membership'),    description: t('teamhub', 'Prevent other teams from being added as members') },
                { key: 'visible',      group: 'privacy', label: t('teamhub', 'Visible to everyone'),           description: t('teamhub', 'This team appears in the team directory') },
                { key: 'protected',    group: 'privacy', label: t('teamhub', 'Password-protect shared files'), description: t('teamhub', 'Enforce password on files shared with this team') },
            ]
        },
        configValue() {
            let v = 0
            if (this.form.config.open)         v |= 1     // CFG_OPEN
            if (this.form.config.invite)        v |= 2     // CFG_INVITE
            if (this.form.config.request)       v |= 4     // CFG_REQUEST
            if (this.form.config.protected)     v |= 16    // CFG_PROTECTED
            if (this.form.config.visible)       v |= 512   // CFG_VISIBLE
            if (this.form.config.singleMember)  v |= 1024  // CFG_SINGLE
            return v
        },
    },
    async mounted() {
        await this.checkIntravox()
        await this.loadWizardDescription()
    },
    methods: {
        t,

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
                this.$set(this.progressTasks, index, { ...this.progressTasks[index], status })
            }
        },

        async submit() {
            // Build task list
            const appsSelected = Object.entries(this.form.apps).filter(([, v]) => v).map(([k]) => k)
            const tasks = [{ label: t('teamhub', 'Creating team'), status: 'waiting' }]
            if (this.form.description.trim()) tasks.push({ label: t('teamhub', 'Saving description'), status: 'waiting' })
            if (this.form.members.length > 0) tasks.push({ label: t('teamhub', 'Inviting members'), status: 'waiting' })
            if (appsSelected.length > 0) tasks.push({ label: t('teamhub', 'Setting up app integrations'), status: 'waiting' })
            if (this.intravoxAvailable) tasks.push({ label: t('teamhub', 'Creating documentation page'), status: 'waiting' })

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

                // 5. Create app resources (new dedicated endpoint)
                if (appsSelected.length > 0) {
                    this.setTask(i, 'running')
                    try {
                        const { data: resourceResults } = await axios.post(
                            generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/create-resources`),
                            { apps: appsSelected, teamName: team.name }
                        )
                        // Log full results so console shows which apps succeeded/failed
                        console.log('[CreateTeam] create-resources results:', JSON.stringify(resourceResults))
                        const anyError = Object.values(resourceResults).some(r => r?.error)
                        this.setTask(i++, anyError ? 'error' : 'done')
                    } catch (e) {
                        console.error('[CreateTeam] create-resources failed:', e?.response?.data)
                        this.setTask(i++, 'error')
                    }
                }

                // 6. IntraVox page (only if installed)
                if (this.intravoxAvailable) {
                    this.setTask(i, 'running')
                    try {
                        await this.createIntravoxPage(team)
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
                // Server-side check — reliable, uses IAppManager::isInstalled()
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                this.intravoxAvailable = !!data.intravox
                this.talkAvailable     = !!data.talk
                this.calendarAvailable = !!data.calendar
                this.deckAvailable     = !!data.deck
            } catch (e) {
                // Fallback: assume nothing — user can still create team without integrations
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

        async createIntravoxPage(team) {
            // Find the matching template id from the templates API
            const wantedId = INTRAVOX_TEMPLATES[this.form.teamType]
            let templateId = null
            try {
                const { data: templates } = await axios.get(generateUrl('/apps/intravox/api/templates'))
                const tplList = Array.isArray(templates) ? templates : (templates?.templates || [])

                // Match on short id (e.g. "project"), then slug, then fuzzy title
                const match = tplList.find(t => t.id === wantedId || t.slug === wantedId)
                    || tplList.find(t => (t.title || '').toLowerCase().includes(this.form.teamType.toLowerCase()))

                templateId = match?.id || null
                console.log('[CreateTeam] IntraVox templateId resolved:', templateId, '(wanted:', wantedId + ')')
            } catch (e) {
                console.warn('[CreateTeam] IntraVox templates API failed:', e?.message)
            }

            if (!templateId) {
                console.warn('[CreateTeam] IntraVox: no template found for type', this.form.teamType)
                return
            }

            // POST /api/pages/from-template — minimal payload per OpenAPI spec.
            // Do NOT send parentPath: the value "/teamhub" causes a 400 because IntraVox
            // validates that the path exists as a real page. Omitting it creates at root.
            const payload = {
                templateId,
                pageTitle: team.name,
            }
            console.log('[CreateTeam] IntraVox POST /api/pages/from-template:', JSON.stringify(payload))
            await axios.post(generateUrl('/apps/intravox/api/pages/from-template'), payload)
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
    color: #fff;
}

.ctv__step-label { font-size: 14px; font-weight: 500; }

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

.ctv__label { font-size: 14px; font-weight: 600; }
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
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: center;
    transition: border-color 0.15s, background 0.15s;
}

.ctv__type:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.ctv__type--selected { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.ctv__type-icon { color: var(--color-primary-element); }
.ctv__type-name { font-weight: 600; font-size: 14px; }
.ctv__type-desc { font-size: 12px; color: var(--color-text-maxcontrast); line-height: 1.4; }

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
    background: var(--color-primary-element-light);
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
.ctv__user-name { font-size: 14px; font-weight: 500; }
.ctv__user-id { font-size: 12px; color: var(--color-text-maxcontrast); }

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

.ctv__chip-remove:hover { color: var(--color-error); }

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
.ctv__app-name { font-size: 14px; font-weight: 600; }
.ctv__app-desc { font-size: 12px; color: var(--color-text-maxcontrast); }

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

.ctv__progress-done { color: var(--color-success); }
.ctv__progress-error { color: var(--color-error); }
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
    font-size: 12px;
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

.ctv__setting-name { font-size: 14px; font-weight: 500; line-height: 1.3; display: block; }
.ctv__setting-desc { font-size: 12px; color: var(--color-text-maxcontrast); line-height: 1.4; display: block; }
</style>
