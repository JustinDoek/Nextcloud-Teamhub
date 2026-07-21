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
                    :active="activeView === 'browse'"
                    @click="showView('browse')">
                    <template #icon>
                        <Magnify :size="20" />
                    </template>
                </NcAppNavigationItem>

                <!-- v4.2.12 — Personal "What’s new" feed. Aggregates recent
                     messages from every team the user is a member of plus
                     public messages from other teams. Sits under Browse
                     Teams because it's a cross-team view, not a per-team one.
                     v4.3.0 — gated behind an active TeamHub license. The
                     backend endpoint enforces the gate too (SKILLS §Security
                     standards: frontend is not a security boundary). -->
                <NcAppNavigationItem
                    v-if="isLicensed"
                    :name="t('teamhub', 'What’s new')"
                    :active="activeView === 'feed'"
                    @click="showView('feed')">
                    <template #icon>
                        <Rss :size="20" />
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
                    <!-- Team actions — moved here from the Team-info widget so
                         Team info can be hidden without losing Manage/Invite/
                         Leave. Role-gated per team via team.level (0 = indirect,
                         1 = member, 4 = moderator, 8 = admin, 9 = owner). -->
                    <template #actions>
                        <!-- v4.2.2: NcActionButton.closeAfterClick defaults
                             to FALSE in @nextcloud/vue 9, so the popover
                             would otherwise stay open after our handler
                             runs. Explicit true on all four so the menu
                             collapses as soon as the user picks an action. -->
                        <NcActionButton
                            v-if="(team.level || 0) >= 8"
                            close-after-click
                            :aria-label="t('teamhub', 'Manage team')"
                            @click="onSidebarManageTeam(team.id)">
                            <template #icon><CogOutline :size="20" /></template>
                            {{ t('teamhub', 'Manage team') }}
                        </NcActionButton>
                        <NcActionButton
                            close-after-click
                            :aria-label="t('teamhub', 'Copy team link')"
                            @click="onSidebarCopyLink(team.id)">
                            <template #icon><LinkVariant :size="20" /></template>
                            {{ t('teamhub', 'Copy link') }}
                        </NcActionButton>
                        <NcActionButton
                            v-if="(team.level || 0) >= 4"
                            close-after-click
                            :aria-label="t('teamhub', 'Invite members')"
                            @click="onSidebarInvite(team.id)">
                            <template #icon><AccountPlus :size="20" /></template>
                            {{ t('teamhub', 'Invite members') }}
                        </NcActionButton>
                        <NcActionButton
                            v-if="(team.level || 0) >= 1 && (team.level || 0) < 9"
                            close-after-click
                            :aria-label="t('teamhub', 'Leave team')"
                            @click="onSidebarLeave(team.id)">
                            <template #icon><LocationExit :size="20" /></template>
                            {{ t('teamhub', 'Leave team') }}
                        </NcActionButton>
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

                <!-- v4.2.6 — sidebar footer. Rendered only on UNLICENSED
                     instances: brand mark + wordmark + "Development version"
                     label above the docs/help ? button. On licensed instances
                     (Active/Trial/Grace) the whole block is hidden — no
                     brand, no version tag, no help button. License state
                     comes from the member-callable /api/v1/license/entitlements
                     endpoint (loaded once on mount). Fails-open: if the
                     entitlements call fails we assume unlicensed so the
                     branding still appears rather than silently vanishing. -->
                <template v-if="!isLicensed">
                    <div class="teamhub-feedback-separator" />
                    <!-- v4.2.8 — brand mark + wordmark + tagline + help button
                         collapsed into a single row. Help button sits on the
                         right, pushed there by margin-left:auto on the button
                         wrapper, so the wordmark + tagline stay left-aligned
                         and the ? affordance mirrors the sidebar header rhythm. -->
                    <li class="teamhub-brand-item">
                        <div class="teamhub-brand__mark" aria-hidden="true">
                            <!-- Concept 01A "The Living Network" — flat icon variant
                                 from marketing/brand.html. Central Signal Orange hub +
                                 seven satellites with Hub-Blue linkers. Fixed viewBox
                                 so it scales cleanly at any size. -->
                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="TeamHub">
                                <g stroke="var(--th-brand-linker)" stroke-width="1.4" opacity="0.5" stroke-linecap="round">
                                    <line x1="32" y1="34" x2="14" y2="16"/>
                                    <line x1="32" y1="34" x2="28" y2="10"/>
                                    <line x1="32" y1="34" x2="46" y2="16"/>
                                    <line x1="32" y1="34" x2="54" y2="32"/>
                                    <line x1="32" y1="34" x2="48" y2="50"/>
                                    <line x1="32" y1="34" x2="28" y2="56"/>
                                    <line x1="32" y1="34" x2="10" y2="44"/>
                                </g>
                                <circle cx="14" cy="16" r="3.5" fill="#328FB7"/>
                                <circle cx="28" cy="10" r="2.8" fill="#FE7161"/>
                                <circle cx="46" cy="16" r="4"   fill="#7FDDEE"/>
                                <circle cx="54" cy="32" r="3"   fill="#328FB7"/>
                                <circle cx="48" cy="50" r="3.5" fill="#FE7161"/>
                                <circle cx="28" cy="56" r="4"   fill="#7FDDEE"/>
                                <circle cx="10" cy="44" r="2.8" fill="#328FB7"/>
                                <circle cx="32" cy="34" r="9"   fill="#FB5000"/>
                            </svg>
                        </div>
                        <div class="teamhub-brand__text">
                            <span class="teamhub-brand__wordmark">TeamHub</span>
                            <span class="teamhub-brand__tagline">{{ t('teamhub', 'Development version') }}</span>
                        </div>
                        <div class="teamhub-brand__help">
                            <NcButton
                                variant="tertiary"
                                :title="t('teamhub', 'Help & Documentation')"
                                :aria-label="t('teamhub', 'Help & Documentation')"
                                @click="openDocs">
                                <template #icon>
                                    <HelpCircleOutlineIcon :size="20" />
                                </template>
                            </NcButton>
                        </div>
                    </li>
                </template>
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

            <WhatsHappeningView
                v-else-if="activeView === 'feed' && isLicensed"
                @open-team="selectTeamFromSidebar"
                @open-team-talk="onOpenTeamTalk" />

            <!-- v4.3.0 — license-required fallback for the feed view.
                 Reachable via a stale ?feed deep-link on an instance
                 whose license was removed after the sidebar was rendered. -->
            <NcEmptyContent
                v-else-if="activeView === 'feed' && !isLicensed"
                :name="t('teamhub', 'License required')"
                :description="t('teamhub', 'What’s new requires an active TeamHub license. Add or renew a license in Admin settings → License to unlock the feed.')">
                <template #icon><Rss :size="48" /></template>
            </NcEmptyContent>

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
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton, NcActionButton } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Rss from 'vue-material-design-icons/Rss.vue'
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import LocationExit from 'vue-material-design-icons/LocationExit.vue'
import TeamView from './components/TeamView.vue'
import BrowseTeamsView from './components/BrowseTeamsView.vue'
import ManageTeamView from './components/ManageTeamView.vue'
import CreateTeamView from './components/CreateTeamView.vue'
import WhatsHappeningView from './components/WhatsHappeningView.vue'

export default {
    name: 'App',
    components: {
        NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcEmptyContent, NcCounterBubble, NcButton, NcActionButton,
        AccountGroup, Plus, Magnify, Rss, HelpCircleOutlineIcon,
        CogOutline, LinkVariant, AccountPlus, LocationExit,
        TeamView, BrowseTeamsView, ManageTeamView, CreateTeamView, WhatsHappeningView,
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
            // v4.2.7 — license state for the sidebar footer. Read once on
            // mount via /api/v1/license/entitlements (member-callable, unlike
            // /admin/license which requires admin). enforcementLevel is one
            // of: 'none' (fully active) | 'grace' | 'soft-lock' | 'unlicensed'.
            // Kept nullable — `null` means "not yet loaded or errored"; the
            // isLicensed computed treats null as licensed=true so the brand
            // block on licensed instances never flashes before hiding.
            licenseEntitlements: null,
        }
    },
    computed: {
        ...mapState(['teams', 'currentTeamId', 'loading']),
        ...mapGetters(['currentTeam']),
        /**
         * True when a currently-honoured license is installed (Active, Trial,
         * or Grace). Determines whether the sidebar hides the branding + help
         * footer.
         *
         * v4.2.7 — defaults to TRUE while the entitlements call is in-flight
         * or if it errored, so the branding never briefly flashes on a
         * licensed instance before hiding. The trade-off is that a genuinely
         * unlicensed instance won't see the brand block for the few hundred
         * milliseconds it takes the endpoint to respond — an acceptable
         * silence on unlicensed vs a broken-looking flicker on licensed.
         */
        isLicensed() {
            if (this.licenseEntitlements === null) return true
            const level = this.licenseEntitlements.enforcementLevel
            return level === 'none' || level === 'grace'
        },
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

        // v4.2.6 — one-shot license entitlements fetch for the sidebar
        // footer's brand + help gate. Member-callable endpoint (no admin).
        // Errors are swallowed silently — the sidebar just keeps its
        // fails-open "assume unlicensed" state.
        try {
            const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/license/entitlements'))
            this.licenseEntitlements = data || null
        } catch (e) {
            this.licenseEntitlements = null
        }
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
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET', 'SET_PENDING_TEAM_ACTION']),

        // ── Sidebar 3-dot actions (moved from the Team-info widget) ─────
        //
        // Manage/Invite/Leave rely on the currently-open team's state, so we
        // always select the team first (no-op when it's already active).
        // Invite + Leave publish a one-shot intent flag that TeamView consumes
        // after mount, which handles the cold-start case where the team hasn't
        // been opened this session yet.

        /** Manage team — jump to the Manage Team view for the picked team. */
        onSidebarManageTeam(teamId) {
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.showView('manage')
        },

        /** Copy the deep-link that opens this team on any device. */
        onSidebarCopyLink(teamId) {
            const url = window.location.origin + generateUrl(`/apps/teamhub?team=${teamId}`)
            const done = () => showSuccess(t('teamhub', 'Team link copied to clipboard'))
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(() => this.fallbackCopy(url, done))
            } else {
                this.fallbackCopy(url, done)
            }
            this.closeSidebarIfOverlay()
        },

        /** Open the Invite modal for the picked team (opens Team view first). */
        onSidebarInvite(teamId) {
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.activeView = 'team'
            this.SET_PENDING_TEAM_ACTION('invite')
            this.closeSidebarIfOverlay()
        },

        /** Fire the leave flow via TeamView (which handles routing + toast). */
        onSidebarLeave(teamId) {
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            this.activeView = 'team'
            this.SET_PENDING_TEAM_ACTION('leave')
            this.closeSidebarIfOverlay()
        },

        /**
         * document.execCommand('copy') fallback for the sparse browsers that
         * still block Clipboard API on non-secure origins. Matches the pattern
         * TeamView.fallbackCopy uses so both entry points behave identically.
         */
        fallbackCopy(text, onDone) {
            const ta = document.createElement('textarea')
            ta.value = text
            ta.style.cssText = 'position:fixed;left:-999999px'
            document.body.appendChild(ta)
            ta.select()
            try {
                document.execCommand('copy')
                onDone && onDone()
            } catch (e) {
                showError(t('teamhub', 'Copy failed'))
            } finally {
                document.body.removeChild(ta)
            }
        },

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
         * v4.2.14 — feed click-through for Talk items. Opens the team's
         * home view and switches its currentView to 'talk' so the Talk
         * tab is the one rendered on arrival. Deep-linking to a specific
         * message id inside the Talk embed is out of scope; the user
         * lands in the room and can scroll or reply from there.
         */
        onOpenTeamTalk(teamId) {
            this.activeView = 'team'
            if (this.currentTeamId !== teamId) {
                this.selectTeam(teamId)
            }
            // TeamView reads currentView from the store; set it after
            // selectTeam has committed so the view switch doesn't get
            // overwritten by the team-load default-tab logic.
            this.$nextTick(() => {
                this.SET_VIEW('talk')
            })
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

/* v4.2.6 — Sidebar brand block (unlicensed instances only).
   Concept 01A "Living Network" logo + Hub-Blue wordmark + tagline.
   Palette: marketing/brand.html.
     --th-brand-hub      #245C80  wordmark on light
     --th-brand-linker   #245C80  hub-satellite lines on light, #7FDDEE on dark
     --th-brand-tagline  muted secondary label
   Dark-theme aware: the wordmark flips to white and the linker lines flip
   to Signal Cyan (per the brand-file's on-ink treatment). */
.teamhub-brand-item {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 2px;
    --th-brand-hub: #245C80;
    --th-brand-linker: #245C80;
    --th-brand-tagline: var(--color-text-maxcontrast);
}
[data-theme-dark] .teamhub-brand-item,
[data-theme-dark-highcontrast] .teamhub-brand-item,
body.theme--dark .teamhub-brand-item {
    --th-brand-hub: #FFFFFF;
    --th-brand-linker: #7FDDEE;
}
@media (prefers-color-scheme: dark) {
    .teamhub-brand-item {
        --th-brand-hub: #FFFFFF;
        --th-brand-linker: #7FDDEE;
    }
}
.teamhub-brand__mark {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    display: block;
    line-height: 0;
}
.teamhub-brand__mark svg {
    width: 100%;
    height: 100%;
    display: block;
}
.teamhub-brand__text {
    display: flex;
    flex-direction: column;
    gap: 0;
    min-width: 0;
}
.teamhub-brand__wordmark {
    font-family: 'Inter', 'Inter var', system-ui, -apple-system, 'Segoe UI', sans-serif;
    font-weight: 700;
    font-size: 15px;
    letter-spacing: -0.02em;
    line-height: 1.1;
    color: var(--th-brand-hub);
}
.teamhub-brand__tagline {
    font-size: var(--th-font-micro, 11px);
    font-weight: 500;
    line-height: 1.2;
    color: var(--th-brand-tagline);
    margin-top: 1px;
}
// v4.2.8 — help button sits on the same row, aligned to the right end.
// margin-left:auto lets the text column keep its natural width and pushes
// the help affordance against the sidebar's right edge.
.teamhub-brand__help {
    margin-left: auto;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
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
