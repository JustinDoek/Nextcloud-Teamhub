<template>
    <NcContent app-name="teamhub">
        <NcAppNavigation>
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
                @show-manage-team="showView('manage')" />
        </NcAppContent>
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
import TeamView from './components/TeamView.vue'
import BrowseTeamsView from './components/BrowseTeamsView.vue'
import ManageTeamView from './components/ManageTeamView.vue'
import CreateTeamView from './components/CreateTeamView.vue'

export default {
    name: 'App',
    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble,
        AccountGroup, Plus, Magnify,
        TeamView, BrowseTeamsView, ManageTeamView, CreateTeamView,
    },
    data() {
        return {
            activeView: null,
            canCreateTeam: true, // default true; overwritten after mount
        }
    },
    computed: {
        ...mapState(['teams', 'currentTeamId', 'loading']),
        ...mapGetters(['currentTeam']),
    },
    async mounted() {
        await Promise.all([
            this.fetchTeams(),
            this.fetchCanCreateTeam(),
        ])
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
        },

        startCreateTeam() {
            this.activeView = 'create'
        },

        selectTeamFromSidebar(teamId) {
            this.activeView = 'team'
            this.selectTeam(teamId)
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
    },
}
</script>
