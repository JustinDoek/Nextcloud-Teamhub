<template>
    <NcContent app-name="teamhub">
        <NcAppNavigation :open.sync="navOpen">
            <template #list>
                <!-- Spacer to clear the show/hide sidebar toggle button -->
                <div style="height: 44px; flex-shrink: 0;" />

                <NcAppNavigationItem
                    v-if="canCreateTeam"
                    :name="t('teamhub', 'New Team')"
                    @click="startCreateTeam">
                    <template #icon>
                        <Plus :size="20" />
                    </template>
                </NcAppNavigationItem>

                <NcAppNavigationItem
                    :name="t('teamhub', 'Browse Teams')"
                    @click="showView('browse')">
                    <template #icon>
                        <Magnify :size="20" />
                    </template>
                </NcAppNavigationItem>

                <NcAppNavigationCaption :name="t('teamhub', 'My Teams')" />

                <NcAppNavigationItem
                    v-for="team in teams"
                    :key="team.id"
                    :name="team.name"
                    :active="team.id === currentTeamId && activeView === 'team'"
                    @click="selectTeamFromSidebar(team.id)">
                    <template #icon>
                        <AccountGroup :size="20" />
                    </template>
                    <template v-if="team.unread" #counter>
                        <NcCounterBubble type="highlighted">1</NcCounterBubble>
                    </template>
                </NcAppNavigationItem>

                <NcEmptyContent
                    v-if="!loading.teams && teams.length === 0"
                    :name="t('teamhub', 'No teams yet')"
                    :description="t('teamhub', 'Create your first team above')">
                    <template #icon>
                        <AccountGroup :size="64" />
                    </template>
                </NcEmptyContent>

                <!-- Feedback icon-button at bottom of list, visually separated -->
                <div class="teamhub-feedback-separator" />
                <li class="teamhub-feedback-item">
                    <button
                        class="teamhub-feedback-btn"
                        :title="t('teamhub', 'Feedback & Feature Requests')"
                        :aria-label="t('teamhub', 'Feedback & Feature Requests')"
                        @click="openFeedbackModal">
                        <MessageAlertIcon :size="20" />
                    </button>
                </li>
            </template>
        </NcAppNavigation>

        <NcAppContent>
            <CreateTeamView
                v-if="activeView === 'create'"
                @created="onTeamCreated"
                @cancel="onCreateCancel" />

            <ManageTeamView
                v-else-if="activeView === 'manage' && currentTeam"
                :team="currentTeam"
                @description-updated="onDescriptionUpdated"
                @team-deleted="onTeamDeleted" />

            <BrowseTeamsView
                v-else-if="activeView === 'browse'"
                @team-joined="onTeamJoined" />

            <NcEmptyContent
                v-else-if="!currentTeamId"
                :name="t('teamhub', 'Welcome to TeamHub')"
                :description="t('teamhub', 'Select a team from the sidebar or create a new one')">
                <template #icon>
                    <AccountGroup :size="64" />
                </template>
            </NcEmptyContent>

            <TeamView
                v-else
                :key="currentTeamId"
                @show-manage-team="showView('manage')"
                @team-left="onTeamLeft" />
        </NcAppContent>

        <FeedbackModal
            v-if="showFeedbackModal"
            @close="showFeedbackModal = false" />
    </NcContent>
</template>

<script>
import { mapState, mapActions, mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MessageAlertIcon from 'vue-material-design-icons/MessageAlert.vue'
import TeamView from './components/TeamView.vue'
import BrowseTeamsView from './components/BrowseTeamsView.vue'
import ManageTeamView from './components/ManageTeamView.vue'
import CreateTeamView from './components/CreateTeamView.vue'
import FeedbackModal from './components/FeedbackModal.vue'

export default {
    name: 'App',
    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble,
        AccountGroup, Plus, Magnify, MessageAlertIcon,
        TeamView, BrowseTeamsView, ManageTeamView, CreateTeamView, FeedbackModal,
    },
    data() {
        return {
            activeView: null,
            canCreateTeam: true,
            showFeedbackModal: false,
            // True when the NC sidebar renders as an overlay that should
            // auto-close on selection: phone portrait (≤768px) OR tablet
            // portrait (≤1024px and orientation:portrait).
            isMobileSidebar: false,
            navOpen: true, // NcAppNavigation open state — set to false to close on mobile
            _mobileSidebarMql: null,
            _mobileSidebarMqlHandler: null,
        }
    },
    computed: {
        ...mapState(['teams', 'currentTeamId', 'loading']),
        ...mapGetters(['currentTeam']),
    },
    async mounted() {
        // Detect viewport states where NC's sidebar renders as an overlay.
        // We auto-close it after the user selects a team / action, matching
        // expected mobile nav behaviour without building a custom drawer.
        // Matches: phone (≤768px any orientation) OR tablet portrait (≤1024px portrait).
        if (typeof window !== 'undefined' && window.matchMedia) {
            const query = '(max-width: 768px), (max-width: 1024px) and (orientation: portrait)'
            this._mobileSidebarMql = window.matchMedia(query)
            this.isMobileSidebar = this._mobileSidebarMql.matches
            this._mobileSidebarMqlHandler = (e) => { this.isMobileSidebar = e.matches }
            if (typeof this._mobileSidebarMql.addEventListener === 'function') {
                this._mobileSidebarMql.addEventListener('change', this._mobileSidebarMqlHandler)
            } else if (typeof this._mobileSidebarMql.addListener === 'function') {
                this._mobileSidebarMql.addListener(this._mobileSidebarMqlHandler)
            }
        }

        await Promise.all([
            this.fetchTeams(),
            this.fetchCanCreateTeam(),
        ])
    },

    beforeDestroy() {
        if (this._mobileSidebarMql && this._mobileSidebarMqlHandler) {
            if (typeof this._mobileSidebarMql.removeEventListener === 'function') {
                this._mobileSidebarMql.removeEventListener('change', this._mobileSidebarMqlHandler)
            } else if (typeof this._mobileSidebarMql.removeListener === 'function') {
                this._mobileSidebarMql.removeListener(this._mobileSidebarMqlHandler)
            }
            this._mobileSidebarMql = null
            this._mobileSidebarMqlHandler = null
        }
    },
    methods: {
        t,
        ...mapActions(['fetchTeams', 'selectTeam']),

        async fetchCanCreateTeam() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/user/can-create-team'))
                this.canCreateTeam = !!data.canCreate
            } catch (e) {
                // If the endpoint fails, default to showing the button
                this.canCreateTeam = true
            }
        },

        showView(view) {
            this.activeView = view
            this.closeSidebarIfOverlay()
        },

        openFeedbackModal() {
            this.showFeedbackModal = true
            this.closeSidebarIfOverlay()
        },

        startCreateTeam() {
            this.activeView = 'create'
            this.closeSidebarIfOverlay()
        },

        selectTeamFromSidebar(teamId) {
            this.activeView = 'team'
            this.selectTeam(teamId)
            this.closeSidebarIfOverlay()
        },

        /**
         * Close NC's sidebar when it is in overlay mode (phone / tablet portrait).
         * Uses the official NcAppNavigation :open.sync prop — no DOM touching.
         */
        closeSidebarIfOverlay() {
            if (this.isMobileSidebar) {
                this.navOpen = false
            }
        },

        async onTeamCreated(team) {
            await this.fetchTeams()
            await this.selectTeam(team.id)
            this.activeView = 'team'
        },

        onCreateCancel() {
            this.activeView = this.currentTeamId ? 'team' : null
        },

        onTeamJoined() {
            this.fetchTeams()
            this.activeView = null
        },

        onDescriptionUpdated(newDescription) {
            if (this.currentTeam) {
                this.currentTeam.description = newDescription
            }
        },

        async onTeamDeleted() {
            this.$store.commit('SET_CURRENT_TEAM', null)
            await this.$store.dispatch('fetchTeams')
            this.activeView = 'default'
        },

        async onTeamLeft() {
            this.$store.commit('SET_CURRENT_TEAM', null)
            await this.$store.dispatch('fetchTeams')
            this.activeView = null
        },
    },
}
</script>

<style scoped lang="scss">
// Visual separator above the feedback item at the bottom of the list.
.teamhub-feedback-separator {
    height: 1px;
    margin: 4px 12px;
    background-color: var(--color-border);
}

// Icon-only feedback button — sits in the nav list but shows only the icon.
.teamhub-feedback-item {
    list-style: none;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px 0;
}

.teamhub-feedback-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: none;
    background: transparent;
    border-radius: var(--border-radius-pill);
    color: var(--color-main-text);
    cursor: pointer;
    transition: background-color 0.15s;

    &:hover {
        background-color: var(--color-background-hover);
    }

    &:focus-visible {
        background-color: var(--color-background-hover);
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
    }
}
</style>
