<template>
    <NcContent app-name="teamhub">
        <NcAppNavigation :aria-label="t('teamhub', 'Teams navigation')">
            <template #list>
                <!-- Spacer to clear the show/hide sidebar toggle button -->
                <div class="teamhub-nav-spacer" />

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
                    <template v-if="team.unread > 0" #counter>
                        <!-- NcCounterBubble reads its number from the `count` prop, NOT
                             from the default slot. Passing it via `{{ }}` silently sets
                             the prop to undefined → Intl.NumberFormat formats it as
                             the literal string "NaN" (root cause of the 3.81.x bug). -->
                        <NcCounterBubble type="highlighted" :count="team.unread" />
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

                <!-- Docs / help link — at bottom of list, visually separated.
                     v3.100.15: uses NcButton so it inherits NC's hover/focus
                     ring, keyboard behaviour and the six-lock circular sizing
                     from SKILLS.md (raw <button> previously required its own
                     44×44 pill CSS to hold shape). -->
                <div class="teamhub-feedback-separator" />
                <li class="teamhub-feedback-item">
                    <NcButton
                        variant="tertiary"
                        :title="t('teamhub', 'Help & Documentation')"
                        :aria-label="t('teamhub', 'Help & Documentation')"
                        @click="openDocs">
                        <template #icon>
                            <HelpCircleOutlineIcon :size="20" />
                        </template>
                    </NcButton>
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
                @team-joined="onTeamJoined"
                @team-opened="selectTeamFromSidebar" />

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

    </NcContent>
</template>

<script>
import { mapState, mapActions, mapGetters, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import TeamView from './components/TeamView.vue'
import BrowseTeamsView from './components/BrowseTeamsView.vue'
import ManageTeamView from './components/ManageTeamView.vue'
import CreateTeamView from './components/CreateTeamView.vue'

export default {
    name: 'App',
    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton,
        AccountGroup, Plus, Magnify, HelpCircleOutlineIcon,
        TeamView, BrowseTeamsView, ManageTeamView, CreateTeamView,
    },
    data() {
        return {
            activeView: null,
            canCreateTeam: true,
            // True when the NC sidebar renders as an overlay that should
            // auto-close on selection: phone portrait (≤768px) OR tablet
            // portrait (≤1024px and orientation:portrait).
            isMobileSidebar: false,
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

        // v3.75.1 — consume ?team=…&decision=… deep link.
        // Used by approver-meeting descriptions and any future "open this
        // proposal" entry point (Talk message, email link, etc).
        // Done after fetchTeams so the team list is available before we
        // try to select one.
        await this.consumeDeepLink()

        // Poll for new messages every 60s so the unread badge stays current
        // without requiring a page reload. Uses refreshUnreadCounts (silent —
        // no loading spinner) rather than fetchTeams to avoid UI flicker.
        this._unreadPollInterval = setInterval(() => {
            this.$store.dispatch('refreshUnreadCounts')
        }, 60000)
    },

    beforeDestroy() {
        if (this._unreadPollInterval) {
            clearInterval(this._unreadPollInterval)
            this._unreadPollInterval = null
        }
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
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET']),

        /**
         * v3.75.1 — Consume the ?team=…&decision=… deep link in the URL.
         *
         * Used by approver-meeting calendar descriptions and any future
         * external link into a specific proposal (Talk message, email, etc).
         *
         *   ?team=<teamId>                       → open the team's home view
         *   ?team=<teamId>&decision=<decisionId> → open the team, switch to
         *                                          the Decisions tab, and
         *                                          pre-select the proposal
         *
         * The decision id is resolved to its messageId via a single fetch;
         * decisionsTargetMessageId then drives the existing scroll/select
         * behaviour in TeamDecisionsView.
         */
        async consumeDeepLink() {
            try {
                const params = new URLSearchParams(window.location.search || '')
                const teamId     = params.get('team')
                const decisionId = params.get('decision')
                if (!teamId) return

                // Only select the team if it appears in the user's team list.
                // Defensive: a stale URL pointing at a team the user is no
                // longer a member of would otherwise leave the UI in an
                // inconsistent "loading forever" state.
                const known = (this.teams || []).some(t => t.id === teamId)
                if (!known) {
                    console.warn('[TeamHub][App] consumeDeepLink: team not in current user list:', teamId)
                    return
                }

                await this.selectTeam(teamId)
                this.activeView = 'team'

                if (decisionId) {
                    // Resolve the decision's messageId so the existing
                    // scrollAndSelectTarget watcher in TeamDecisionsView
                    // can highlight the right card.
                    try {
                        const { data } = await axios.get(
                            generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/decisions/${decisionId}`),
                        )
                        const messageId = data?.messageId
                        if (messageId) {
                            // Switch to the Decisions tab and set the target.
                            // The order matters: setting the target before the
                            // view ensures the watcher in TeamDecisionsView
                            // sees the change after the view renders.
                            this.SET_VIEW('decisions')
                            this.$nextTick(() => {
                                this.SET_DECISIONS_TARGET(messageId)
                            })
                        }
                    } catch (e) {
                        console.warn('[TeamHub][App] consumeDeepLink: decision fetch failed', e?.message)
                    }
                }
            } catch (e) {
                // Never let a bad URL crash the app.
                console.warn('[TeamHub][App] consumeDeepLink: failed', e?.message)
            }
        },

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

        openDocs() {
            window.open('https://tldr.host/teamhub/docs/', '_blank', 'noopener,noreferrer')
            this.closeSidebarIfOverlay()
        },

        startCreateTeam() {
            this.activeView = 'create'
            this.closeSidebarIfOverlay()
        },

        selectTeamFromSidebar(teamId) {
            this.activeView = 'team'
            // Guard against re-clicking the currently open team. selectTeam's
            // store action unconditionally resets SET_PROJECT to isProject:false,
            // then relies on TeamView's `currentTeamId` watcher to re-run
            // loadLayout so the project fact + budgetConfig + timeConfig come
            // back. When teamId is unchanged the watcher doesn't fire, so we
            // would leave advanced-project state empty (phase stepper, Budget
            // + Time tabs, Manage Team → Project tab all vanish) until the
            // user reloads. If they were already on 'admin' or 'create' view
            // and clicked their team, activeView above is enough — no need to
            // touch team state.
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.closeSidebarIfOverlay()
        },

        /**
         * Close NC's sidebar when it is in overlay mode (phone / tablet portrait).
         * v9 NcAppNavigation has no `open` prop — its open state is internal and
         * controlled via the `toggle-navigation` event bus event.
         */
        closeSidebarIfOverlay() {
            if (this.isMobileSidebar) {
                emit('toggle-navigation', { open: false })
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
// v3.100.17: moved from inline style="height: 44px" (gui.md § 13).
// Spacer at the top of the sidebar list that clears the NC show/hide
// sidebar toggle button so its icon doesn't overlap the first nav item.
.teamhub-nav-spacer {
    height: 44px;
    flex-shrink: 0;
}

// Visual separator above the feedback item at the bottom of the list.
.teamhub-feedback-separator {
    height: 1px;
    margin: 4px 12px;
    background-color: var(--color-border);
}

// Icon-only feedback button — sits in the nav list but shows only the icon.
// The button itself is now NcButton; only the <li> wrapper's centring stays here.
// v3.100.15: the custom .teamhub-feedback-btn CSS block (44×44 pill with
// bespoke hover / focus-visible rules) was retired because NcButton owns
// all of that behaviour natively.
.teamhub-feedback-item {
    list-style: none;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px 0;
}
</style>

<!--
    Global (non-scoped) styles for the Tribute.js @-mention autocomplete dropdown.
    NcRichContenteditable appends the .tribute-container ul to document.body so
    scoped styles never reach it. NC vue 8.x uses CSS modules (hashed class names)
    internally but the outer container also retains the plain `tribute-container`
    class. We set explicit colors here so the dropdown is readable in all themes.
-->
<style>
/* Outer container — appended to document.body by Tribute.js */
ul.tribute-container,
[class*="tribute-container"] {
    background-color: var(--color-main-background) !important;
    border: 1px solid var(--color-border) !important;
    border-radius: var(--border-radius-large) !important;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2) !important;
    z-index: 10000 !important;
    max-height: 240px !important;
    overflow-y: auto !important;
}

ul.tribute-container li,
[class*="tribute-container"] li {
    background-color: var(--color-main-background) !important;
    color: var(--color-main-text) !important;
    cursor: pointer !important;
}

ul.tribute-container li.highlight,
ul.tribute-container li:hover,
[class*="tribute-container"] li.highlight,
[class*="tribute-container"] li:hover {
    background-color: var(--color-background-hover) !important;
    color: var(--color-main-text) !important;
}

/* NC vue renders items inside a div with id="nc-rich-contenteditable-tribute-item-*" */
[id^="nc-rich-contenteditable-tribute-item-"] {
    color: var(--color-main-text) !important;
    background-color: transparent !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 6px 12px !important;
    font-size: 13px !important;
}

[id^="nc-rich-contenteditable-tribute-item-"] * {
    color: var(--color-main-text) !important;
}
</style>
