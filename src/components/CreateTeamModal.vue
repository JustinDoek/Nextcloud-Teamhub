<template>
    <NcDialog
        :name="t('teamhub', 'Create new team')"
        :open="true"
        size="normal"
        @closing="$emit('close')">

        <div class="ctm">
            <!-- Step indicator -->
            <div class="ctm__steps">
                <div :class="['ctm__step', { 'ctm__step--active': step === 1, 'ctm__step--done': step > 1 }]">
                    <span class="ctm__step-num">1</span>
                    <span class="ctm__step-label">{{ t('teamhub', 'Team details') }}</span>
                </div>
                <div class="ctm__step-divider" />
                <div :class="['ctm__step', { 'ctm__step--active': step === 2, 'ctm__step--done': step > 2 }]">
                    <span class="ctm__step-num">2</span>
                    <span class="ctm__step-label">{{ t('teamhub', 'Members & Apps') }}</span>
                </div>
            </div>

            <!-- Step 1: Name, description, type -->
            <div v-if="step === 1" class="ctm__body">
                <div class="ctm__field">
                    <label class="ctm__label" for="ctm-team-name">{{ t('teamhub', 'Team name') }} <span class="ctm__required">*</span></label>
                    <NcTextField
                        id="ctm-team-name"
                        v-model="form.name"
                        label-outside
                        :placeholder="namePlaceholder"
                        :error="!!nameError"
                        :helper-text="nameError || ''" />
                </div>

                <div class="ctm__field">
                    <label class="ctm__label" for="ctm-team-description">{{ t('teamhub', 'Description') }}</label>
                    <NcTextArea
                        id="ctm-team-description"
                        v-model="form.description"
                        label-outside
                        :placeholder="t('teamhub', 'What is this team about?')"
                        :rows="3" />
                </div>

                <div class="ctm__field">
                    <label class="ctm__label">{{ t('teamhub', 'Team type') }}</label>
                    <div class="ctm__types">
                        <div
                            v-for="type in teamTypes"
                            :key="type.id"
                            :class="['ctm__type', 'ctm__type--' + type.accent, { 'ctm__type--selected': form.teamType === type.id }]"
                            @click="form.teamType = type.id">
                            <component :is="type.icon" :size="28" class="ctm__type-icon" />
                            <span class="ctm__type-name">{{ type.label }}</span>
                            <span class="ctm__type-desc">{{ type.description }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Members + apps -->
            <div v-if="step === 2" class="ctm__body">
                <div class="ctm__field">
                    <label class="ctm__label" for="ctm-member-search">{{ t('teamhub', 'Add members') }}</label>
                    <div class="ctm__member-search">
                        <NcTextField
                            id="ctm-member-search"
                            v-model="memberSearch"
                            label-outside
                            :placeholder="t('teamhub', 'Search by name or username...')"
                            @input="onMemberSearch" />
                        <div v-if="userResults.length > 0" class="ctm__user-results">
                            <div
                                v-for="user in userResults"
                                :key="user.id"
                                class="ctm__user-result"
                                @click="addMember(user)">
                                <NcAvatar :user="user.id" :display-name="user.displayName" :size="28" :show-user-status="false" />
                                <span>{{ user.displayName }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="form.members.length > 0" class="ctm__member-chips">
                        <div v-for="m in form.members" :key="m.id" class="ctm__chip">
                            <NcAvatar :user="m.id" :display-name="m.displayName" :size="22" :show-user-status="false" />
                            <span>{{ m.displayName }}</span>
                            <button class="ctm__chip-remove"
                                :aria-label="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                :title="t('teamhub', 'Remove {name}', { name: m.displayName })"
                                @click="removeMember(m.id)">
                                <Close :size="14" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="ctm__field">
                    <label class="ctm__label">{{ t('teamhub', 'App integrations') }}</label>
                    <p class="ctm__hint">{{ t('teamhub', 'TeamHub will create a dedicated space in each checked app and add the team as a member.') }}</p>
                    <div class="ctm__apps">
                        <label v-for="app in appOptions" :key="app.id" class="ctm__app">
                            <input
                                v-model="form.apps[app.id]"
                                type="checkbox"
                                class="ctm__app-check" />
                            <component :is="app.icon" :size="20" />
                            <span class="ctm__app-name">{{ app.label }}</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <template #actions>
            <NcButton v-if="step > 1" variant="tertiary" :disabled="creating" @click="step--">
                {{ t('teamhub', 'Back') }}
            </NcButton>
            <NcButton v-if="step < 2" variant="primary" @click="goToStep2">
                {{ t('teamhub', 'Next') }}
            </NcButton>
            <NcButton v-if="step === 2" variant="primary" :disabled="creating" @click="submit">
                <template #icon>
                    <NcLoadingIcon v-if="creating" :size="20" />
                    <Check v-else :size="20" />
                </template>
                {{ creating ? t('teamhub', 'Creating...') : t('teamhub', 'Create team') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcDialog, NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'

export default {
    name: 'CreateTeamModal',
    components: {
        NcDialog, NcButton, NcTextField, NcTextArea, NcAvatar, NcLoadingIcon,
        Check, Close, Chat, Folder, Calendar, CardText, Briefcase, AccountMultiple, OfficeBuildingOutline,
    },
    emits: ['close', 'created'],
    data() {
        return {
            step: 1,
            creating: false,
            nameError: '',
            memberSearch: '',
            userResults: [],
            searchTimer: null,
            talkAvailable: true,
            calendarAvailable: true,
            deckAvailable: true,
            groupfoldersAvailable: false,
            form: {
                name: '',
                description: '',
                teamType: 'collaboration',
                members: [],
                apps: { talk: false, files: false, calendar: false, deck: false },
            },
        }
    },
    computed: {
        teamTypes() {
            return [
                {
                    id: 'project',
                    label: t('teamhub', 'Project'),
                    description: t('teamhub', 'Time-bound work with clear goals'),
                    icon: 'Briefcase',
                    accent: 'project',
                },
                {
                    id: 'collaboration',
                    label: t('teamhub', 'Collaboration'),
                    description: t('teamhub', 'Ongoing knowledge sharing'),
                    icon: 'AccountMultiple',
                    accent: 'collaboration',
                },
                {
                    id: 'department',
                    label: t('teamhub', 'Department'),
                    description: t('teamhub', 'Organizational unit'),
                    icon: 'OfficeBuildingOutline',
                    accent: 'department',
                },
            ]
        },
        templateProfile() {
            const profiles = {
                project: {
                    apps: { talk: false, files: true, calendar: true, deck: true },
                    placeholder: t('teamhub', 'e.g. Website Redesign'),
                },
                collaboration: {
                    apps: { talk: true, files: true, calendar: false, deck: false },
                    placeholder: t('teamhub', 'e.g. Design Guild'),
                },
                department: {
                    apps: { talk: true, files: true, calendar: true, deck: false },
                    placeholder: t('teamhub', 'e.g. Human Resources'),
                },
            }
            return profiles[this.form.teamType] || profiles.collaboration
        },
        namePlaceholder() {
            return this.templateProfile.placeholder
        },
        appOptions() {
            const filesLabel = this.groupfoldersAvailable
                ? t('teamhub', 'Files — create a group folder')
                : t('teamhub', 'Files — create a shared folder')
            const all = [
                { id: 'talk', label: t('teamhub', 'Talk — create a group conversation'), icon: 'Chat', available: this.talkAvailable },
                { id: 'files', label: filesLabel, icon: 'Folder', available: true },
                { id: 'calendar', label: t('teamhub', 'Calendar — create a team calendar'), icon: 'Calendar', available: this.calendarAvailable },
                { id: 'deck', label: t('teamhub', 'Deck — create a task board'), icon: 'CardText', available: this.deckAvailable },
            ]
            return all.filter(a => a.available)
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
        await this.checkApps()
        this.applyTemplateDefaults()
    },
    methods: {
        t,

        applyTemplateDefaults() {
            const profile = this.templateProfile
            const availableIds = new Set(this.appOptions.map(a => a.id))
            for (const [appId, checked] of Object.entries(profile.apps)) {
                this.form.apps[appId] = availableIds.has(appId) ? checked : false
            }
        },

        async checkApps() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                this.talkAvailable          = !!data.talk
                this.calendarAvailable      = !!data.calendar
                this.deckAvailable          = !!data.deck
                this.groupfoldersAvailable  = !!data.groupfolders
            } catch {
                // Defaults remain
            }
        },

        goToStep2() {
            this.nameError = ''
            if (!this.form.name.trim()) {
                this.nameError = t('teamhub', 'Team name is required')
                return
            }
            this.step = 2
        },

        onMemberSearch() {
            clearTimeout(this.searchTimer)
            if (this.memberSearch.length < 2) {
                this.userResults = []
                return
            }
            this.searchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/users/search'),
                        { params: { q: this.memberSearch } }
                    )
                    const added = new Set(this.form.members.map(m => m.id))
                    this.userResults = (data || []).filter(u => !added.has(u.id))
                } catch {
                    this.userResults = []
                }
            }, 300)
        },

        addMember(user) {
            this.form.members.push(user)
            this.memberSearch = ''
            this.userResults = []
        },

        removeMember(userId) {
            this.form.members = this.form.members.filter(m => m.id !== userId)
        },

        async submit() {
            if (this.creating) return
            this.creating = true
            try {
                // 1. Create the team (with members via backend)
                const { data: team } = await axios.post(
                    generateUrl('/apps/teamhub/api/v1/teams'),
                    {
                        name: this.form.name.trim(),
                        description: this.form.description.trim(),
                        members: this.form.members.map(m => m.id),
                    }
                )

                // 2. Create app resources for checked apps (best-effort via existing endpoint)
                const appsToCreate = Object.entries(this.form.apps)
                    .filter(([, checked]) => checked)
                    .map(([id]) => id)

                if (appsToCreate.length > 0) {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/apps`),
                        { apps: appsToCreate }
                    ).catch(() => {})
                }

                // 3. Create IntraVox page from template (best-effort)
                await this.createIntravoxPage(team).catch(e =>
                )

                this.$emit('created', team)
            } catch (error) {
                const msg = error.response?.data?.error || error.response?.data?.message
                showError(msg
                    ? t('teamhub', 'Failed to create team: {error}', { error: msg })
                    : t('teamhub', 'Failed to create team')
                )
            } finally {
                this.creating = false
            }
        },

        async createIntravoxPage(team) {
            await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${team.id}/intravox/page`))
        },
    },
}
</script>

<style scoped>
.ctm {
    padding: 4px 0 8px;
    min-height: 340px;
}

.ctm__steps {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
}

.ctm__step {
    display: flex;
    align-items: center;
    gap: 8px;
    opacity: 0.4;
    transition: opacity 0.2s;
}

.ctm__step--active,
.ctm__step--done { opacity: 1; }

.ctm__step-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--color-border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--th-font-meta);
    font-weight: 700;
    flex-shrink: 0;
}

.ctm__step--active .ctm__step-num {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.ctm__step--done .ctm__step-num {
    background: var(--color-success);
    color: var(--color-success-text);
}

.ctm__step-label {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
}

.ctm__step-divider {
    flex: 1;
    height: 1px;
    background: var(--color-border);
    margin: 0 12px;
}

.ctm__body {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.ctm__label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}

.ctm__required { color: var(--color-error-text); }

.ctm__field { position: relative; }

/* Team type cards */
.ctm__types {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.ctm__type {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    padding: 16px 8px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    text-align: center;
    transition: border-color 0.15s, background 0.15s;
}

/* Accent colors per template type */
.ctm__type--project .ctm__type-icon { color: var(--color-warning); }
.ctm__type--collaboration .ctm__type-icon { color: var(--color-primary-element); }
.ctm__type--department .ctm__type-icon { color: var(--color-success); }

/* v3.100.14: split hover from selected per SKILLS.md § "State-coloured
   backgrounds". Hover is transient feedback (neutral background-hover);
   selected is the persistent state and gets the matching full-saturation
   fill + text pair. Previously both were the same soft tint (--color-*-hover
   or -light used as a background), so selected and hover looked identical
   and the selected chip washed out against the canvas. */
.ctm__type:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.ctm__type--project.ctm__type--selected {
    border-color: var(--color-warning);
    background: var(--color-warning);
    color: var(--color-warning-text);
}
.ctm__type--collaboration.ctm__type--selected {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}
.ctm__type--department.ctm__type--selected {
    border-color: var(--color-success);
    background: var(--color-success);
    color: var(--color-success-text);
}
.ctm__type-name { font-weight: 600; font-size: 13px; }
.ctm__type-desc { font-size: var(--th-font-micro); color: var(--color-text-maxcontrast); line-height: 1.3; }

/* Member search */
.ctm__member-search { position: relative; }

.ctm__user-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 200;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    max-height: 180px;
    overflow-y: auto;
}

.ctm__user-result {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
}

.ctm__user-result:hover { background: var(--color-background-hover); }

.ctm__member-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.ctm__chip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px 3px 4px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-pill);
    font-size: var(--th-font-meta);
}

.ctm__chip-remove {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
}

.ctm__chip-remove:hover { color: var(--color-error-text); }

/* App checkboxes */
.ctm__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0 0 10px;
}

.ctm__apps {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ctm__app {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 13px;
    padding: 8px 12px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border);
    transition: background 0.15s;
}

.ctm__app:hover { background: var(--color-background-hover); }

.ctm__app-check {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--color-primary-element);
    flex-shrink: 0;
}

.ctm__app-name { flex: 1; }
</style>
