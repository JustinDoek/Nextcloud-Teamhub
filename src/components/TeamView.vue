<template>
    <div class="teamhub-team-view">
        <!-- Tab bar -->
        <div class="teamhub-tab-bar">
            <!-- Home tab — in-page, always a button -->
            <button
                class="teamhub-tab"
                :class="{ active: currentView === 'msgstream' }"
                @click="setView('msgstream')">
                <component :is="'MessageOutline'" :size="16" />
                {{ t('teamhub', 'Home') }}
            </button>

        <!-- App tabs — load in iframe -->
            <button
                v-if="resources.talk && resources.talk.token"
                class="teamhub-tab"
                :class="{ active: currentView === 'talk' }"
                @click="setView('talk')">
                <Chat :size="16" />
                {{ t('teamhub', 'Chat') }}
            </button>
            <button
                v-if="resources.files && resources.files.path"
                class="teamhub-tab"
                :class="{ active: currentView === 'files' }"
                @click="setView('files')">
                <Folder :size="16" />
                {{ t('teamhub', 'Files') }}
            </button>
            <button
                v-if="resources.calendar"
                class="teamhub-tab"
                :class="{ active: currentView === 'calendar' }"
                @click="setView('calendar')">
                <Calendar :size="16" />
                {{ t('teamhub', 'Calendar') }}
            </button>
            <button
                v-if="resources.deck && resources.deck.board_id"
                class="teamhub-tab"
                :class="{ active: currentView === 'deck' }"
                @click="setView('deck')">
                <CardText :size="16" />
                {{ t('teamhub', 'Deck') }}
            </button>

            <!-- Custom web links (open in new tab) -->
            <a
                v-for="link in webLinks"
                :key="'link-' + link.id"
                :href="link.url"
                target="_blank"
                rel="noopener"
                class="teamhub-tab teamhub-tab--link">
                <OpenInNew :size="14" />
                {{ link.title }}
            </a>

            <NcButton
                class="teamhub-tab-add"
                type="tertiary"
                :aria-label="t('teamhub', 'Manage links')"
                @click="showManageLinks = true">
                <template #icon><Plus :size="18" /></template>
            </NcButton>
        </div>

        <!-- Content + Sidebar -->
        <div class="teamhub-layout">
            <!-- Main content area -->
            <div class="teamhub-main">
                <ActivityFeedView v-if="currentView === 'activity'" />
                <MessageStream v-else-if="currentView === 'msgstream'" />
                <AppEmbed
                    v-else-if="currentView === 'talk' && resources.talk"
                    :url="talkUrl"
                    :label="t('teamhub', 'Chat')" />
                <AppEmbed
                    v-else-if="currentView === 'files' && resources.files"
                    :url="filesUrl"
                    :label="t('teamhub', 'Files')" />
                <AppEmbed
                    v-else-if="currentView === 'calendar'"
                    :url="calendarUrl"
                    :label="t('teamhub', 'Calendar')" />
                <AppEmbed
                    v-else-if="currentView === 'deck' && resources.deck"
                    :url="deckUrl"
                    :label="t('teamhub', 'Deck')" />
            </div>

            <!-- Right sidebar — only on Home and Activity views -->
            <aside v-if="currentView === 'msgstream' || currentView === 'activity'" class="teamhub-sidebar">
                <!-- Team info with members and actions menu -->
                <NcAppNavigationItem 
                    :name="t('teamhub', 'Team Info')" 
                    :allow-collapse="true" 
                    :open="false"
                    class="teamhub-team-info-widget">
                    <template #icon><InformationOutline :size="20" /></template>
                    <template #actions>
                        <NcActionButton v-if="isTeamAdmin" @click="openManageTeam">
                            <template #icon><Cog :size="20" /></template>
                            {{ t('teamhub', 'Manage team') }}
                        </NcActionButton>
                        <NcActionButton @click="copyTeamLink">
                            <template #icon><ContentCopy :size="20" /></template>
                            {{ t('teamhub', 'Copy team link') }}
                        </NcActionButton>
                        <NcActionButton @click="inviteToTeam">
                            <template #icon><AccountPlus :size="20" /></template>
                            {{ t('teamhub', 'Invite user') }}
                        </NcActionButton>
                    </template>
                    <template #default>
                        <div class="teamhub-team-info-content">
                            <p class="teamhub-team-description">{{ team.description || t('teamhub', 'No description') }}</p>

                            <!-- Owner row -->
                            <div v-if="teamOwner" class="teamhub-team-owner">
                                <span class="teamhub-info-label">{{ t('teamhub', 'Owner') }}</span>
                                <div class="teamhub-owner-row">
                                    <NcAvatar
                                        v-if="teamOwner.userId"
                                        :user="teamOwner.userId"
                                        :display-name="teamOwner.displayName"
                                        :show-user-status="false"
                                        :size="22" />
                                    <span class="teamhub-owner-name">{{ teamOwner.displayName }}</span>
                                </div>
                            </div>

                            <!-- Members row -->
                            <div class="teamhub-team-members-compact">
                                <span class="teamhub-info-label">{{ t('teamhub', 'Members') }} ({{ members.length }})</span>
                                <div class="teamhub-avatar-stack">
                                    <NcAvatar
                                        v-for="member in members.slice(0, 8)"
                                        v-if="member.userId"
                                        :key="member.userId"
                                        :user="member.userId"
                                        :display-name="member.displayName"
                                        :show-user-status="false"
                                        :disable-menu="false"
                                        :size="28"
                                        class="teamhub-stacked-avatar" />
                                    <span v-if="members.length > 8" class="teamhub-more-members">
                                        +{{ members.length - 8 }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>
                </NcAppNavigationItem>

                <!-- Calendar widget (collapsible) -->
                <NcAppNavigationItem 
                    v-if="resources.calendar"
                    :name="t('teamhub', 'Upcoming Events')" 
                    :allow-collapse="true" 
                    :open="true"
                    class="teamhub-widget-item">
                    <template #icon><Calendar :size="20" /></template>
                    <template #default>
                        <CalendarWidget />
                    </template>
                </NcAppNavigationItem>

                <!-- Deck widget (collapsible) -->
                <NcAppNavigationItem 
                    v-if="resources.deck"
                    :name="t('teamhub', 'Upcoming Tasks')" 
                    :allow-collapse="true" 
                    :open="true"
                    class="teamhub-widget-item">
                    <template #icon><CardText :size="20" /></template>
                    <template #default>
                        <DeckWidget />
                    </template>
                </NcAppNavigationItem>

                <!-- Pages widget (Intravox integration) -->
                <NcAppNavigationItem 
                    v-if="intravoxAvailable"
                    :name="t('teamhub', 'Pages')" 
                    :allow-collapse="true" 
                    :open="true"
                    class="teamhub-widget-item">
                    <template #icon><FileDocumentOutline :size="20" /></template>
                    <template #default>
                        <IntravoxWidget />
                    </template>
                </NcAppNavigationItem>

                <!-- Activity widget (collapsible) -->
                <NcAppNavigationItem
                    :name="t('teamhub', 'Team Activity')"
                    :allow-collapse="true"
                    :open="true"
                    class="teamhub-widget-item">
                    <template #icon><ClockOutline :size="20" /></template>
                    <template #default>
                        <ActivityWidget @show-more="setView('activity')" />
                    </template>
                </NcAppNavigationItem>
            </aside>
        </div>

        <!-- Manage links modal -->
        <ManageLinksModal v-if="showManageLinks" @close="showManageLinks = false" />

        <!-- Invite member modal -->
        <InviteMemberModal
            v-if="showInviteModal"
            :team-id="currentTeamId"
            @close="showInviteModal = false"
            @invited="$store.dispatch('fetchMembers', currentTeamId)" />
    </div>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess, showInfo } from '@nextcloud/dialogs'
import { NcButton, NcAppNavigationItem, NcAvatar, NcLoadingIcon, NcActionButton } from '@nextcloud/vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ExitToApp from 'vue-material-design-icons/ExitToApp.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

import MessageStream from './MessageStream.vue'
import DeckWidget from './DeckWidget.vue'
import CalendarWidget from './CalendarWidget.vue'
import IntravoxWidget from './IntravoxWidget.vue'
import ActivityWidget from './ActivityWidget.vue'
import ActivityFeedView from './ActivityFeedView.vue'
import ManageLinksModal from './ManageLinksModal.vue'
import InviteMemberModal from './InviteMemberModal.vue'
import AppEmbed from './AppEmbed.vue'

export default {
    name: 'TeamView',
    components: {
        NcButton,
        NcAppNavigationItem,
        NcAvatar,
        NcLoadingIcon,
        NcActionButton,
        MessageOutline, Chat, Folder, Calendar, CardText, Plus, OpenInNew, InformationOutline, AccountGroup, ClockOutline, FileDocumentOutline, ExitToApp, Cog, ContentCopy, AccountPlus,
        MessageStream,
        DeckWidget,
        CalendarWidget,
        IntravoxWidget,
        ActivityWidget,
        ActivityFeedView,
        ManageLinksModal,
        InviteMemberModal,
        AppEmbed,
    },
    data() {
        return {
            showManageLinks: false,
            showInviteModal: false,
        }
    },
    computed: {
        ...mapState(['currentTeamId', 'currentView', 'resources', 'webLinks', 'members', 'loading', 'intravoxAvailable']),
        ...mapGetters(['currentTeam']),
        team() { return this.currentTeam || {} },
        
        teamOwner() {
            if (!this.members || !Array.isArray(this.members)) return null
            return this.members.find(m => m.level >= 9) || null
        },

        isTeamAdmin() {
            // Check if current user is admin (level >= 8) or owner (level >= 9)
            if (!this.members || !Array.isArray(this.members) || this.members.length === 0) {
                return false
            }
            const currentUser = getCurrentUser()?.uid
            if (!currentUser) return false
            const currentMember = this.members.find(m => m.userId === currentUser)
            return currentMember && currentMember.level >= 8
        },

        // Talk: /call/{token} opens the conversation directly
        talkUrl() {
            const token = this.resources.talk?.token
            return token ? generateUrl('/call/' + token) : generateUrl('/apps/spreed')
        },
        // Files: deep-link directly into the shared folder by path
        filesUrl() {
            const path = this.resources.files?.path || '/'
            return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(path)
        },
        // Calendar: /apps/calendar/p/{token} is the public embed URL
        calendarUrl() {
            const cal = this.resources.calendar
            if (cal?.public_token) {
                return generateUrl('/apps/calendar/p/' + cal.public_token)
            }
            return generateUrl('/apps/calendar')
        },
        // Deck: /apps/deck/#/board/{id} is the correct SPA hash route
        deckUrl() {
            const id = this.resources.deck?.board_id
            return generateUrl('/apps/deck') + (id ? '/#/board/' + id : '/')
        },
    },
    mounted() {
        this.$store.dispatch('checkIntravox')
    },
    methods: {
        t,
        ...mapActions(['selectTeam']),
        ...mapMutations(['SET_VIEW']),
        setView(view) { this.SET_VIEW(view) },
        
        
        openManageTeam() {
            // Open manage team view in main canvas
            this.$emit('show-manage-team')
        },
        
        copyTeamLink() {
            // Copy the TeamHub URL for this team
            const teamUrl = window.location.origin + generateUrl(`/apps/teamhub?team=${this.currentTeamId}`)
            
            // Use modern clipboard API if available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(teamUrl).then(() => {
                    showSuccess(t('teamhub', 'Team link copied to clipboard'))
                }).catch(() => {
                    // Fallback
                    this.fallbackCopy(teamUrl)
                })
            } else {
                this.fallbackCopy(teamUrl)
            }
        },
        
        fallbackCopy(text) {
            const textArea = document.createElement('textarea')
            textArea.value = text
            textArea.style.position = 'fixed'
            textArea.style.left = '-999999px'
            document.body.appendChild(textArea)
            textArea.select()
            try {
                document.execCommand('copy')
                showSuccess(t('teamhub', 'Team link copied to clipboard'))
            } catch (err) {
                showError(t('teamhub', 'Could not copy link'))
            }
            document.body.removeChild(textArea)
        },
        
        inviteToTeam() {
            this.showInviteModal = true
        },
        
        openContactsApp() {
            // Open Contacts app team page for advanced management
            const url = generateUrl(`/apps/contacts/circle/${this.currentTeamId}`)
            window.open(url, '_blank')
        },
        
        async confirmLeaveTeam() {
            const teamName = this.team.name || t('teamhub', 'this team')
            
            // Use Nextcloud's confirmation dialog
            const confirmed = await new Promise(resolve => {
                if (typeof OC !== 'undefined' && OC.dialogs && OC.dialogs.confirm) {
                    OC.dialogs.confirm(
                        t('teamhub', 'Are you sure you want to leave "{team}"? You will lose access to all team resources.', { team: teamName }),
                        t('teamhub', 'Leave Team'),
                        confirmed => resolve(confirmed),
                        true
                    )
                } else {
                    // Fallback to native confirm
                    resolve(confirm(t('teamhub', 'Are you sure you want to leave "{team}"?', { team: teamName })))
                }
            })
            
            if (!confirmed) return
            
            await this.leaveTeam()
        },
        
        async leaveTeam() {
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/leave`),
                    {},
                    
                )
                
                showSuccess(t('teamhub', 'You have left the team'))
                
                // Refresh teams list and select first available team
                await this.fetchTeams()
                if (this.$store.state.teams.length > 0) {
                    await this.selectTeam(this.$store.state.teams[0].id)
                }
            } catch (error) {
                showError(t('teamhub', 'Failed to leave team: {error}', { error: error.response?.data?.error || error.message }))
            }
        },
    },
}
</script>

<style scoped>
.teamhub-team-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

.teamhub-tab-bar {
    display: flex;
    gap: 4px;
    padding: 8px 16px 8px 44px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
    flex-shrink: 0;
    align-items: center;
    flex-wrap: wrap;
}

.teamhub-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--border-radius-pill);
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
    text-decoration: none;
    white-space: nowrap;
}

.teamhub-tab:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

.teamhub-tab.active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.teamhub-tab--link {
    opacity: 0.85;
    border: 1px solid var(--color-border);
}

.teamhub-tab--app {
    text-decoration: none;
    color: var(--color-text-maxcontrast);
}

.teamhub-tab--app:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

.teamhub-tab-add {
    margin-left: auto;
}

.teamhub-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 0;
    flex: 1;
    overflow: hidden;
    min-height: 0;
}

.teamhub-main {
    overflow-y: auto;
    overflow-x: hidden;
    min-width: 0;
    padding: 16px;
    box-sizing: border-box;
}

.teamhub-sidebar {
    overflow-y: auto;
    border-left: 1px solid var(--color-border);
    padding: 8px 0;
    background: var(--color-main-background);
}

.teamhub-widget-item {
    margin-top: 10px;
}

.teamhub-widget-item :deep(.app-navigation-entry__name) {
    font-weight: 600;
    color: var(--color-primary-element);
}

.teamhub-team-info-widget {
    border-bottom: 1px solid var(--color-border-dark);
    padding-bottom: 10px;
}

.teamhub-team-description {
    padding: 8px 16px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin: 0;
}

@media (max-width: 1024px) {
    .teamhub-layout {
        grid-template-columns: 1fr;
    }
    .teamhub-sidebar {
        border-left: none;
        border-top: 1px solid var(--color-border);
    }
}

.teamhub-team-info-content {
    padding: 0 16px 8px;
}

.teamhub-team-members-compact {
    margin-top: 12px;
}

.teamhub-info-label {
    display: block;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    margin-bottom: 6px;
    font-weight: 400;
    letter-spacing: 0.04em;
}

.teamhub-team-owner {
    margin-bottom: 12px;
}

.teamhub-owner-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.teamhub-owner-name {
    font-size: 13px;
    color: var(--color-main-text);
}

.teamhub-avatar-stack {
    display: flex;
    align-items: center;
    gap: 4px;
}

.teamhub-stacked-avatar {
    border: 2px solid var(--color-main-background);
}

.teamhub-more-members {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    margin-left: 4px;
}

.teamhub-empty-small,
.teamhub-loading-small {
    padding: 12px 16px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}
</style>
