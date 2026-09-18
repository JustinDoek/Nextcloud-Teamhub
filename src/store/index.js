// Vue import removed — not needed in Vuex 4 store
import { createStore } from 'vuex'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { teamImageUrl, uploadTeamsAvatar } from '../lib/teamAvatar.js'
import { fileOpenUrl, isNarrowViewport, withoutSidebarRequest } from '../lib/filesCollab.js'
import {
    buildAllTabDescriptors,
    buildAvailableTabs,
    buildDashboardWidgetCatalog,
    orderTabDescriptors,
} from '../lib/teamTabs.js'

// Vue.use(Vuex) removed — Vuex 4 uses app.use(store) in the entrypoint

/**
 * Move a team's legacy TeamHub app-data image into Nextcloud Teams' own avatar
 * storage, then drop TeamHub's copy. Called only for NC 34+ teams the server
 * reported as still carrying a TeamHub-era picture, where the current user is a
 * circle admin — a silent one-way migration (see the migrateTeamAvatars action).
 *
 * @param {string} teamId circle id
 * @param {string} legacyUrl TeamHub serve URL for the existing app-data image
 */
async function migrateLegacyImageToTeams(teamId, legacyUrl) {
    const imgResp = await axios.get(legacyUrl, { responseType: 'blob' })
    await uploadTeamsAvatar(teamId, imgResp.data)
    await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/image`))
}

export default createStore({
    state: {
        teams: [],
        // v4.7.3 — personal sidebar grouping. `null` means "not fetched yet",
        // which is deliberately distinct from "fetched, no groups": until the
        // state arrives the sidebar renders the flat team list it has always
        // rendered, so a slow preferences read shows the old sidebar rather
        // than flashing every team into the wrong place and then regrouping.
        teamGroups: null,
        // teamId -> groupId. Only deliberate placements are stored; a team
        // with no entry is loose. See TeamGroupService's class docblock.
        teamGroupAssignments: {},
        currentTeamId: null,
        currentView: 'msgstream',
        // When set, the files view embeds this specific file URL instead of the
        // team folder. Set by file widgets (shared/favourites/recent) so files
        // open inside TeamHub's iframe rather than a new browser tab. Cleared
        // when the user navigates away from the files view.
        filesEmbedFileUrl: null,
        // v4.5.9 — same one-shot deep-link pattern as filesEmbedFileUrl, for the
        // calendar and deck tabs. Set by the upcoming-events / upcoming-tasks
        // widgets so an item opens inside TeamHub's own iframe instead of
        // navigating away to the NC app. Cleared by SET_VIEW on leaving the tab.
        // v4.5.15 — back to the shape that actually worked: the backend's own
        // event URL, plus which calendar the event belongs to.
        //
        // { url, calendarId }. Composing a public-view URL ourselves was a dead
        // end — NC Calendar derives an event's route id from the DAV URL it
        // loaded the object with, and the public view uses a different DAV root,
        // so an id built from the owner's path resolves to nothing there.
        // `calendarId` is what lets us return to the event's own agenda once the
        // user closes it.
        calendarEmbedEvent: null,
        deckEmbedCardUrl: null,
        // Bumped whenever the user lands on the home view — a tab click back to
        // Home, or picking a team (selectTeam commits SET_VIEW 'msgstream').
        // Widgets that should show fresh data on return watch this; see
        // CalendarWidget / DeckWidget for the reload-key pattern.
        widgetRefreshNonce: 0,
        currentUser: getCurrentUser(),
        messages: [],
        pinnedMessage: null,   // single pinned message for the current team, or null
        messagesPage: 1,       // current page (1-based)
        messagesTotal: 0,      // total non-pinned message count from last fetch
        messagesLimit: 5,      // messages per page
        messageSettings: { manageMinLevel: 'admin', postMinLevel: 'member', linkMinLevel: 'admin', commentMinLevel: 'member', commentsEnabled: {}, allowPublicMessages: false }, // per-team message settings
        comments: {},          // { messageId: [comments] }
        members: [],
        allEffectiveMembers: [],   // flat [{userId, displayName, email?, phone?, ncStatus?}] of ALL members including indirect (via groups/teams) — used by the MembersWidget and for @mention autocomplete
        allEffectiveMembersTalkAvailable: false, // per-request fact: is Talk (spreed) enabled for the current user — drives whether the chat icon shows in the members widget rows
        allEffectiveMembersMailAvailable: false, // per-request fact: can the CURRENT user compose in NC Mail (app enabled + an account configured) — decides whether a member's email icon opens Mail or hands off to the OS mailto: handler
        memberships: [],           // flat list of {type: 'group'|'circle', displayName, memberCount}
        effectiveMemberCount: 0,   // total users including those via groups/teams (from circles_membership)
        hasMoreMembers: false,     // true when effective_count > members shown in widget
        isCurrentUserDirectMember: true, // false when user is only in team via a group/team
        currentUserLevel: 0,       // current user's direct Circles level on the active team (0 = no direct row)
        resources: {},         // { talk, files, calendar, deck, tasks }
        resourceWarnings: { pending: 0, atRisk: 0 }, // from _warnings in resources response
        resourceWarningFocus: false,                  // true when warning block button clicked — ManageTeamView scrolls to at-risk section
        webLinks: [],
        deckTasks: [],
        selectedDeckBoard: null,  // { board_id, name, color } — set by picker or widget click
        deckUnassignedCounts: {}, // { [boardId]: { count: N, boardName: 'X' } }
        teamTasks: [],         // VTODO tasks from the team calendar (NC Tasks app)
        teamWidgets: [],        // enabled sidebar widgets for the current team
        teamMenuItems: [],      // enabled menu_item integrations for the current team
        intravoxAvailable: false,
        intravoxParentPath: 'en/teamhub',
        presenceConfig: { presence_enabled: false, hide_reasons: false },
        presenceModuleEnabled: false,
        decisionsConfig: { decisions_enabled: false, decisions_level_enabled: false, decisions_action_min_level: 1 },
        decisionsModuleEnabled: false,
        decisionsTargetMessageId: null, // set by widget/stream to highlight a decision in the tab
        // v4.5.26 — { messageId, nonce } | null. Set when something outside the
        // stream wants a specific message brought into view (the Open button in
        // "What's new"). MessageStream loads the page it lives on and
        // MessageCard highlights it. Cleared by the stream once consumed.
        messageTarget: null,
        // v3.99.8 — when set, TeamDecisionsView skips the landing view and
        // opens the "All decisions" list with this status filter pre-
        // applied. Dispatched by ProjectHealthWidget when a user clicks
        // "Open Decisions" so the click lands on the pressing subset
        // (Awaits approval) instead of the generic landing page.
        // Consumers MUST clear it (SET_DECISIONS_PRESELECT_STATUS(null))
        // after honoring it, so a later back-navigation shows landing.
        decisionsPreselectStatus: null,
        // Timeline integration: per-team toggle, default enabled. Mirrors the
        // decisionsConfig shape so consumers can guard the Timeline tab the
        // same way they guard the Decisions tab.
        timelineConfig: { timeline_enabled: true },
        // Messages integration (v3.104.1): per-team toggle, default enabled.
        // Gates the message stream widget, PostMessageForm, and any surface
        // that renders team messages so a team can be run without a stream.
        messagesConfig: { messages_enabled: true },
        // Collectives (Wiki) integration (v4.3.5): per-team toggle, default OFF.
        // Rides along with the layout bundle, same pattern as messagesConfig /
        // timelineConfig.
        //
        // The two halves have different scopes and v4.5.35 stopped treating
        // them alike. `collectives_enabled` is per-team. `collectives_installed`
        // is instance-global (`IAppManager::isInstalled`) and therefore cannot
        // honestly differ between two teams on one instance — so it starts as
        // **null meaning "not known yet"**, not false. Booting it at false made
        // the Manage Team toggle state "Not installed" during the window before
        // the first layout bundle landed, and permanently if that request ever
        // failed. A claim about the instance is not something to guess at.
        collectivesConfig: { collectives_enabled: false, collectives_installed: null },
        // v4.3.9 — one-shot iframe deep-link for the Wiki (Collectives) tab.
        // Set right after "Create Wiki page" so the iframe opens directly on
        // the new page instead of the collective's landing view. Same
        // pattern as filesEmbedFileUrl: cleared by SET_VIEW when the user
        // navigates away from the collectives view.
        collectivesEmbedPageUrl: null,
        // Team-wide dashboard customization (owner/admin controlled):
        //   hidden_widgets — widget ids removed from every member's dashboard
        //   default_tab    — tab key opened when a member enters the team
        // Rides along with the layout bundle, same pattern as messagesConfig.
        dashboardConfig: { hidden_widgets: [], default_tab: 'msgstream' },
        // Published so Manage Team → Settings → Dashboard can offer exactly the
        // tabs/widgets this member actually has, without re-deriving the
        // (complex) activation logic there. Built by src/lib/teamTabs.js.
        //
        // v4.6.15 — these used to be published only by TeamView, which App.vue
        // unmounts whenever Manage Team is on screen. Three paths open Manage
        // Team without TeamView ever mounting, and on those the picker read a
        // stale array: empty on a cold load (a <select> with no options, which
        // can never fire @change — so no team could store a default tab at
        // all), or the *previous* team's tabs after a switch. `publishTeamTabs`
        // now builds them from store facts, and availableTabsTeamId records who
        // they belong to so a stale set is never trusted.
        availableTabs: [],            // [{ key, label }] — Home + ordered tabs
        dashboardWidgetCatalog: [],   // [{ key, label }] — hideable home widgets
        availableTabsTeamId: null,    // team the two lists above were built for
        teamTabOrder: [],             // saved tab-key order from the layout bundle
        // One-shot action intent set by the sidebar 3-dot menu so App.vue can
        // trigger Invite / Leave team on a not-yet-open team. TeamView watches
        // the pair (pendingTeamAction, currentTeamId), fires the action once
        // the team is mounted, then clears the flag. Values: 'invite'|'leave'|null.
        pendingTeamAction: null,
        // Team template label (v4.0.2): 'collaboration'|'project'|'department'|null.
        // Set once by CreateTeamView after team creation; null for legacy
        // teams so the frontend renders no template badge.
        teamType: null,
        // v4.6.13 — optional expiration date for the current team, or null.
        // Rides the layout bundle next to teamType. Shape:
        // { expiresAt, expiresOn, daysRemaining, expired, warning, setBy, … }
        teamExpiry: null,
        // Budget integration (v3.92.0): per-team toggle, default enabled.
        // Only surfaced in the UI for Advanced projects, but the store shape
        // is universal so consumers can guard the Budget tab consistently.
        budgetConfig: { budget_enabled: true, can_view_budget: false },
        // Time investment integration (v3.96.0): mirrors budgetConfig. Same
        // universal shape so TeamView's tab-gating watcher pattern applies
        // uniformly. can_view_time is precomputed server-side (level ≥ floor
        // OR user is a named project participant).
        timeConfig: { time_enabled: true, can_view_time: false },
        // Project Teams (v3.88.0): persisted project-ness for teams created from
        // the Project template. Delivered in the layout bundle (SET_PROJECT).
        // isProject=false for non-project teams. mode: 'basic'|'advanced';
        // phase (advanced only): initiation|planning|execution|closing.
        project: { isProject: false, mode: null, phase: null, startDate: null, targetEnd: null },
        // OpenProject link facts (v4.9.3, Phase 1). Ride the layout bundle
        // like collectivesConfig. `available` is instance-level (the official
        // integration app is usable for this user); `eligible` (the team is
        // of the OpenProject template), `linked` and `project` are per team;
        // `stale` says the link was made against another OpenProject host.
        // Both OpenProject widgets gate on `eligible && linked`. v4.9.16 —
        // `moduleAvailable` is TeamHub's own module (licensed and switched
        // on); when it is false the backend already reports `eligible` and
        // `linked` false, so every widget hides without asking.
        openProjectConfig: { moduleAvailable: false, available: false, eligible: false, linked: false, stale: false, project: null },
        // v4.9.6 — the team's provisioning state from the layout bundle: null
        // for a team that was never provisioned, else { id, status, complete,
        // currentStep, openSteps, createdBy, updatedAt }. The team page's
        // incomplete-provisioning banner reads it.
        provisioning: null,
        // Project-owner onboarding (v3.90.x): set by CreateTeamView right after an
        // Advanced project team is persisted; read once by TeamView on first open
        // of that exact team to auto-show ProjectPhaseGuide, then cleared — a
        // one-shot in-memory signal, deliberately not persisted anywhere.
        justCreatedAdvancedProjectTeamId: null,
        // Same deep-link-to-a-tab pattern as resourceWarningFocus, for "Open
        // Project settings" in ProjectPhaseGuide — ManageTeamView watches this to
        // jump straight to the Project tab.
        projectTabFocus: false,
        // v3.98.0 — Project Compass deep-link target. Set by any component that
        // wants to route the user into Manage Team at a specific tab + section
        // (e.g. Compass items linking to project/milestones or project/budget).
        // Shape: { tab: string, section: string, nonce: number } | null.
        // The nonce forces the watcher to re-fire even when tab+section are
        // unchanged (a user might click the same link twice). Consumers set
        // back to null after acting to keep the mutation single-shot.
        manageTeamDeepLink: null,
        // v4.5.21 — My Work (cross-team personal work queue).
        //
        // Held in the store, not in MyWorkView's own data(), for one reason:
        // opening an item navigates to the team view, which unmounts MyWorkView
        // entirely. The specification requires that returning preserves the
        // search query, filters, sorting and scroll position — so all of it
        // lives here and the component reads it on mount. `payload` is kept
        // too, so coming back renders instantly from the last result while a
        // refresh runs in the background.
        //
        // `filters` is deliberately flat and all-string (except showSnoozed):
        // it maps one-to-one onto query parameters, and onto the personal
        // preferences the backend persists.
        myWork: {
            filters: {
                search: '',
                teamId: '',
                providerId: '',
                priority: '',
                status: '',
                resourceType: '',
                dueWindow: '',
                showSnoozed: false,
                category: '',
                // v4.9.7 — source-specific narrowing: an OpenProject project
                // and a work-package type. Offered only when the queue holds
                // rows that carry them (the payload's `facets`).
                projectId: '',
                workType: '',
            },
            groupBy: 'category',
            // v4.5.25 — order within a group. Never reorders the groups: the
            // categories are the page's structure, not a preference.
            sortBy: 'deadline',
            page: 1,
            scrollTop: 0,
            // v4.5.39 — keys of the sections the user has folded shut, in the
            // main column. A list of *collapsed* keys, not expanded ones, so
            // the default (empty) is a queue that arrives open. Replaces the
            // single `completedExpanded` boolean.
            collapsedGroups: [],
            // v4.5.22 — row density. Compact collapses action buttons to
            // icons only; it does not hide any action.
            compact: false,
            payload: null,
            providers: [],
            loadedAt: 0,
        },
        loading: {
            teams: false,
            messages: false,
            members: false,
            resources: false,
            activity: false,
        },
        error: null,
    },

    getters: {
        currentTeam: state => state.teams.find(t => t.id === state.currentTeamId) || null,

        /**
         * The sidebar's teams, bucketed into the user's own groups (v4.7.3).
         *
         * Returns `{ groups, ungrouped }`. A team that has not been placed
         * anywhere comes back in `ungrouped` and renders as a loose item, so
         * the sidebar of a user who never uses this feature is byte-for-byte
         * the list that shipped before it — there is no catch-all container.
         *
         * `hasUpdates` is derived here rather than stored, from the same
         * `unread` the per-team counter bubble reads. That is what stops the
         * bold group name and the badge inside it ever disagreeing.
         *
         * @return {{groups: Array<object>, ungrouped: Array<object>}}
         */
        sidebarTeamGroups: (state) => {
            const teams = state.teams || []

            // Not fetched yet — every team loose. See the state comment.
            if (!Array.isArray(state.teamGroups)) {
                return { groups: [], ungrouped: teams }
            }

            const assignments = state.teamGroupAssignments || {}
            const buckets = {}
            state.teamGroups.forEach(g => { buckets[g.id] = [] })

            const ungrouped = []
            teams.forEach(team => {
                const groupId = assignments[team.id]
                if (groupId && buckets[groupId]) {
                    buckets[groupId].push(team)
                } else {
                    ungrouped.push(team)
                }
            })

            // Favorites first, then the user's own groups by name. Sorted
            // with the reader's locale (v4.7.2's `data-locale`, not the UI
            // language) because a byte sort puts "Ärzte" after "Zulieferer"
            // for a German reader.
            const locale = getCanonicalLocale()
            const builtin = state.teamGroups.filter(g => g.builtin)
            const custom = state.teamGroups
                .filter(g => !g.builtin)
                .slice()
                .sort((a, b) => (a.name || '').localeCompare(b.name || '', locale))

            const withTeams = (g) => {
                const groupTeams = buckets[g.id] || []
                return {
                    ...g,
                    teams: groupTeams,
                    hasUpdates: groupTeams.some(t => (t.unread || 0) > 0),
                }
            }

            return {
                groups: [...builtin, ...custom].map(withTeams),
                ungrouped,
            }
        },

        /**
         * Can TeamHub open this target in one of its own tabs for the current
         * team? (v4.5.11)
         *
         * Callers MUST consult this before suppressing a link's default action:
         * a Deck card link in a team with no Deck board has to fall through to
         * the original link, not swallow the click and switch to a tab whose
         * v-if is false — that would be a dead click.
         *
         * @param {{type: string}} target from resolveInternalTarget()
         * @return {boolean}
         */
        canOpenInEmbed: state => (target) => {
            switch (target?.type) {
            case 'file':
                // The Files embed renders on filesEmbedFileUrl alone, so a file
                // is openable even without a team folder connected.
                return true
            case 'deck':
                return !!(state.resources.deck && state.resources.deck.length > 0)
            case 'calendar':
                // Without a team calendar the tab falls back to the personal
                // Calendar app, which is not "the team's calendar" — leave the
                // link alone rather than pretend.
                return !!(state.resources.calendar && state.resources.calendar.length > 0)
            case 'collectives':
                return !!state.collectivesConfig?.collectives_enabled
            default:
                return false
            }
        },
        commentsForMessage: state => id => state.comments[id] || [],

        /**
         * True if the current user clears the team's message-moderation floor
         * (v4.7.4) — pin or unpin any message, and edit or delete a message or
         * comment written by somebody else.
         *
         * One getter because it is one permission. Before 4.7.4 this was
         * `canPin` and the moderation half did not exist on the client at all;
         * deletion was gated server-side on admin and the UI simply offered
         * the affordance and let the request fail.
         *
         * **This decides what is rendered, never what is allowed.** The
         * service re-checks every one of these actions — SKILLS.md § Security
         * standards: the frontend is not a security boundary.
         *
         * Falls back to admin (8) rather than to the old global default, so a
         * team whose settings have not loaded yet under-offers rather than
         * over-offers.
         */
        canManageMessages: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            if (!member) return false
            const levelMap = { member: 1, moderator: 4, admin: 8 }
            const required = levelMap[state.messageSettings?.manageMinLevel] ?? 8
            return (member.level || 0) >= required
        },

        /**
         * True if the current user's level meets the per-team postMinLevel threshold.
         */
        canPost: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            // Indirect members (via group/team) have no direct level row — default to 1
            const userLevel = member ? (member.level || 1) : 1
            const levelMap = { member: 1, moderator: 4, admin: 8 }
            const required = levelMap[state.messageSettings?.postMinLevel] ?? 1
            return userLevel >= required
        },

        /**
         * True if the current user's level meets the per-team linkMinLevel threshold.
         * Default: admin (level 8) when no setting is stored.
         */
        canManageLinks: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            const userLevel = member ? (member.level || 1) : 1
            const levelMap = { member: 1, moderator: 4, admin: 8 }
            const required = levelMap[state.messageSettings?.linkMinLevel] ?? 8
            return userLevel >= required
        },

        /**
         * True if the current user's level meets the per-team commentMinLevel threshold.
         * Default: member (level 1) — everyone can comment unless overridden.
         * Mirrors the backend enforcement in CommentController::createComment /
         * MessageService::enforceCommentMinLevel (v4.3.1). Indirect members
         * (no direct row) resolve to level 1, so they always pass the
         * default floor but are refused above it — same behaviour as canPost.
         */
        canComment: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            const userLevel = member ? (member.level || 1) : 1
            const levelMap = { member: 1, moderator: 4, admin: 8 }
            const required = levelMap[state.messageSettings?.commentMinLevel] ?? 1
            return userLevel >= required
        },

        /**
         * Whether this team takes comments on messages of a given type (v4.5.38).
         *
         * Distinct from `canComment`, and the two produce different UI on
         * purpose: the role floor leaves the thread readable and disables the
         * composer, while this removes the comment count and the section
         * outright — the reader sees the message only.
         *
         * **Missing means enabled.** An empty map is what a pre-4.5.38 server
         * returns and what a failed settings fetch leaves behind, and neither is
         * a reason to hide every thread in the team. Questions are never
         * switchable — mirrors MessageService::COMMENTS_ALWAYS_ON_TYPES.
         */
        commentsEnabledForType: state => (messageType) => {
            const type = messageType || 'normal'
            if (type === 'question') return true
            const map = state.messageSettings?.commentsEnabled
            if (!map || typeof map !== 'object') return true
            return map[type] !== false
        },

        /**
         * True if the current user is a team admin (Circles level >= 8).
         */
        currentUserIsTeamAdmin: state => (state.currentUserLevel || 0) >= 8,
    },

    mutations: {
        SET_TEAMS(state, teams) { state.teams = teams },

        // v4.7.3 — the whole grouping state, as returned by every one of the
        // team-group endpoints. They all answer with the full state rather
        // than a delta, so the client never has to reconstruct what the
        // server did and cannot drift from it.
        SET_TEAM_GROUPS(state, payload) {
            state.teamGroups = Array.isArray(payload?.groups) ? payload.groups : []
            state.teamGroupAssignments = (payload?.assignments && typeof payload.assignments === 'object')
                ? payload.assignments
                : {}
        },

        // Optimistic chevron: the collapse animation should not wait for a
        // round trip. The PUT follows and re-commits the server's own state,
        // so a failed write self-corrects on the next load rather than
        // leaving the two permanently out of step.
        SET_TEAM_GROUP_EXPANDED(state, { groupId, expanded }) {
            const group = (state.teamGroups || []).find(g => g.id === groupId)
            if (group) { group.expanded = expanded }
        },

        // Patch only the unread count on each team — does NOT replace the
        // teams array, so Vue does not tear down and re-mount navigation
        // items. Safe to call on a background poll.
        UPDATE_UNREAD_COUNTS(state, teams) {
            if (!Array.isArray(teams)) return
            const map = {}
            teams.forEach(t => { map[t.id] = t.unread || 0 })
            state.teams.forEach(t => {
                t.unread = map[t.id] ?? t.unread ?? 0 // Vue 3: proxy reactivity — Vue.set not needed
            })
        },
        UPDATE_TEAM_IMAGE(state, { teamId, imageUrl }) {
            const team = state.teams.find(t => t.id === teamId)
            if (team) {
                // Cache-buster already applied in ManageTeamView — store the raw URL
                team.image_url = imageUrl
            }
        },
        SET_CURRENT_TEAM(state, id) { state.currentTeamId = id },
        SET_VIEW(state, view) {
            // Leaving the files view discards any one-off file override so the
            // files tab reverts to the team folder next time it is opened.
            if (view !== 'files') {
                state.filesEmbedFileUrl = null
            }
            // v4.3.9 — same clear-on-leave for the Wiki tab's one-shot
            // deep-link, so the tab reverts to the collective's landing
            // page next time it is opened after a "Create Wiki page" jump.
            if (view !== 'collectives') {
                state.collectivesEmbedPageUrl = null
            }
            // v4.5.9 — same clear-on-leave for the calendar event and deck card
            // deep-links, so each tab reverts to the team's own default view.
            if (view !== 'calendar') {
                state.calendarEmbedEvent = null
            }
            if (view !== 'deck') {
                state.deckEmbedCardUrl = null
            }
            // Landing on Home is the signal to refresh home-view widgets. Also
            // covers picking a team, since selectTeam commits this with
            // 'msgstream'; widgets key off (teamId, nonce) together so that
            // still results in exactly one load, not two.
            if (view === 'msgstream') {
                state.widgetRefreshNonce++
            }
            state.currentView = view
        },
        SET_FILES_EMBED_FILE_URL(state, url) { state.filesEmbedFileUrl = url },
        SET_CALENDAR_EMBED_EVENT(state, ev) { state.calendarEmbedEvent = ev },
        SET_DECK_EMBED_CARD_URL(state, url) { state.deckEmbedCardUrl = url },
        SET_MESSAGES(state, messages) { state.messages = messages },
        SET_PINNED_MESSAGE(state, message) { state.pinnedMessage = message },
        SET_MESSAGES_PAGE(state, page) { state.messagesPage = page },
        SET_MESSAGES_TOTAL(state, total) { state.messagesTotal = total },
        SET_MESSAGE_SETTINGS(state, settings) { state.messageSettings = settings },
        ADD_MESSAGE(state, message) { state.messages.unshift(message) },
        REMOVE_MESSAGE(state, messageId) {
            state.messages = state.messages.filter(m => m.id !== messageId)
            if (state.pinnedMessage && state.pinnedMessage.id === messageId) {
                state.pinnedMessage = null
            }
        },
        UPDATE_MESSAGE(state, message) {
            // Update in the regular list
            const idx = state.messages.findIndex(m => m.id === message.id)
            if (idx !== -1) state.messages[idx] = { ...state.messages[idx], ...message } // Vue 3: direct index assignment is reactive
            // Also sync the pinned slot if it's the same message
            if (state.pinnedMessage && state.pinnedMessage.id === message.id) {
                state.pinnedMessage = { ...state.pinnedMessage, ...message }
            }
        },
        /**
         * Patch the embedded `decision` payload on a message after a
         * mark/withdraw call, without replacing the rest of the message row.
         * Targeted mutation (rather than full UPDATE_MESSAGE) so we don't
         * clobber transient frontend state like in-flight comments.
         */
        SET_MESSAGE_DECISION(state, { messageId, decision }) {
            const idx = state.messages.findIndex(m => m.id === messageId)
            if (idx !== -1) {
                state.messages[idx] = { ...state.messages[idx], decision }
            }
            if (state.pinnedMessage && state.pinnedMessage.id === messageId) {
                state.pinnedMessage = { ...state.pinnedMessage, decision }
            }
        },
        /**
         * Patch a message's comment-notification subscription flag
         * (v4.8.7, GitHub #95).
         *
         * Targeted for the same reason SET_MESSAGE_DECISION is: replacing the
         * whole row would clobber transient frontend state such as an
         * in-flight comment draft, and the server's answer to a subscribe
         * carries only the one field that changed.
         */
        SET_MESSAGE_SUBSCRIPTION(state, { messageId, subscribed }) {
            const idx = state.messages.findIndex(m => m.id === messageId)
            if (idx !== -1) {
                state.messages[idx] = { ...state.messages[idx], subscribed }
            }
            if (state.pinnedMessage && state.pinnedMessage.id === messageId) {
                state.pinnedMessage = { ...state.pinnedMessage, subscribed }
            }
        },
        // Called after a successful pin: move the message out of the regular list
        // and into the pinned slot, clearing any previous pin from the regular list.
        PIN_MESSAGE(state, message) {
            // Remove old pinned message from regular list if it ended up there
            state.messages = state.messages.filter(m => m.id !== message.id)
            // Unpin the previous pinned message back into the top of the regular list
            if (state.pinnedMessage && state.pinnedMessage.id !== message.id) {
                state.messages.unshift({ ...state.pinnedMessage, pinned: false })
            }
            state.pinnedMessage = message
        },
        // Called after a successful unpin: move the message back into the regular list.
        UNPIN_MESSAGE(state, message) {
            state.pinnedMessage = null
            state.messages.unshift({ ...message, pinned: false })
        },
        // Mark a team as read in the sidebar list (optimistic update)
        MARK_TEAM_SEEN(state, teamId) {
            const team = state.teams.find(t => t.id === teamId)
            if (team) team.unread = 0 // Vue 3: proxy reactivity — Vue.set not needed
        },
        UPDATE_COMMENT(state, { messageId, comment }) {
            const list = state.comments[messageId]
            if (!list) return
            const idx = list.findIndex(c => c.id === comment.id)
            if (idx !== -1) list[idx] = { ...list[idx], ...comment } // Vue 3: direct index assignment is reactive
        },
        SET_COMMENTS(state, { messageId, comments }) {
            state.comments[messageId] = comments // Vue 3: proxy reactivity — Vue.set not needed
        },
        ADD_COMMENT(state, { messageId, comment }) {
            if (!state.comments[messageId]) state.comments[messageId] = [] // Vue 3: proxy reactivity — Vue.set not needed
            state.comments[messageId].push(comment)
        },
        /**
         * Move a message's `comment_count` by `delta`.
         *
         * The count on the card comes from the message row, not from the loaded
         * comment list — the stream fetches counts for every message but only
         * fetches a thread when it is opened. So posting a comment has to move
         * the number itself; without this the footer kept reading "3 comments"
         * with four on screen until the next stream fetch. The delete path
         * doesn't need it — its response carries the whole refreshed message.
         *
         * Clamped at zero: a count that has drifted below the truth must not be
         * able to render as negative.
         */
        BUMP_COMMENT_COUNT(state, { messageId, delta }) {
            const apply = (m) => {
                if (!m || m.id !== messageId) return m
                return { ...m, comment_count: Math.max(0, (m.comment_count || 0) + delta) }
            }
            const idx = state.messages.findIndex(m => m.id === messageId)
            if (idx !== -1) state.messages[idx] = apply(state.messages[idx])
            if (state.pinnedMessage && state.pinnedMessage.id === messageId) {
                state.pinnedMessage = apply(state.pinnedMessage)
            }
        },
        SET_MEMBERS(state, members) { state.members = members },
        SET_ALL_EFFECTIVE_MEMBERS(state, members) { state.allEffectiveMembers = Array.isArray(members) ? members : [] },
        SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE(state, available) { state.allEffectiveMembersTalkAvailable = !!available },
        SET_ALL_EFFECTIVE_MEMBERS_MAIL_AVAILABLE(state, available) { state.allEffectiveMembersMailAvailable = !!available },
        SET_MEMBERSHIPS(state, memberships) { state.memberships = memberships },
        SET_EFFECTIVE_MEMBER_COUNT(state, count) { state.effectiveMemberCount = count },
        SET_HAS_MORE_MEMBERS(state, val) { state.hasMoreMembers = val },
        SET_IS_DIRECT_MEMBER(state, val) { state.isCurrentUserDirectMember = val },
        SET_CURRENT_USER_LEVEL(state, val) { state.currentUserLevel = (typeof val === 'number') ? val : 0 },
        REMOVE_COMMENT(state, { messageId, commentId }) {
            const list = state.comments[messageId]
            if (!list) return
            const idx = list.findIndex(c => c.id === commentId)
            if (idx !== -1) list.splice(idx, 1)
        },
        SET_RESOURCES(state, resources) { state.resources = resources },
        SET_RESOURCE_WARNINGS(state, warnings) { state.resourceWarnings = warnings },
        SET_RESOURCE_WARNING_FOCUS(state, value) { state.resourceWarningFocus = value },
        SET_WEB_LINKS(state, links) { state.webLinks = links },
        SET_DECK_TASKS(state, tasks) { state.deckTasks = tasks },
        SET_DECK_UNASSIGNED(state, counts) { state.deckUnassignedCounts = counts },
        SET_SELECTED_DECK_BOARD(state, board) { state.selectedDeckBoard = board },
        SET_TEAM_TASKS(state, tasks) { state.teamTasks = tasks },
        SET_TEAM_WIDGETS(state, widgets) { state.teamWidgets = widgets },
        SET_TEAM_MENU_ITEMS(state, items) { state.teamMenuItems = items },
        SET_PRESENCE_CONFIG(state, config) { state.presenceConfig = config },
        SET_PRESENCE_MODULE_ENABLED(state, val) { state.presenceModuleEnabled = val },
        SET_DECISIONS_CONFIG(state, config) { state.decisionsConfig = config },
        SET_TIMELINE_CONFIG(state, config) { state.timelineConfig = config },
        SET_MESSAGES_CONFIG(state, config) { state.messagesConfig = config },
        SET_COLLECTIVES_CONFIG(state, config) { state.collectivesConfig = config },
        /**
         * v4.5.35 — clear only the per-team half on a team switch.
         *
         * `selectTeam` used to reset the whole object, which threw away
         * `collectives_installed` too. That value is an instance-global fact,
         * so re-asking for it per team was never meaningful — but zeroing it
         * meant the Wiki toggle read "Not installed" on every team switch until
         * the layout bundle arrived, and stayed there if it never did.
         */
        RESET_COLLECTIVES_TEAM_CONFIG(state) {
            state.collectivesConfig = {
                ...state.collectivesConfig,
                collectives_enabled: false,
            }
        },
        SET_COLLECTIVES_EMBED_PAGE_URL(state, url) { state.collectivesEmbedPageUrl = url },
        SET_DASHBOARD_CONFIG(state, config) { state.dashboardConfig = config },
        SET_AVAILABLE_TABS(state, tabs) { state.availableTabs = tabs },
        SET_DASHBOARD_WIDGET_CATALOG(state, catalog) { state.dashboardWidgetCatalog = catalog },
        SET_AVAILABLE_TABS_TEAM_ID(state, teamId) { state.availableTabsTeamId = teamId },
        SET_TEAM_TAB_ORDER(state, order) { state.teamTabOrder = Array.isArray(order) ? order : [] },
        SET_PENDING_TEAM_ACTION(state, action) { state.pendingTeamAction = action },
        SET_TEAM_TYPE(state, teamType) { state.teamType = teamType },
        SET_TEAM_EXPIRY(state, teamExpiry) { state.teamExpiry = teamExpiry },
        SET_BUDGET_CONFIG(state, config) { state.budgetConfig = config },
        SET_TIME_CONFIG(state, config) { state.timeConfig = config },
        SET_PROJECT(state, project) { state.project = project },
        SET_OPENPROJECT_CONFIG(state, config) {
            state.openProjectConfig = {
                // v4.9.16 — TeamHub's module is licensed and switched on. A
                // bundle from before the key existed reads as available, so
                // an older backend keeps its surfaces.
                moduleAvailable: config?.moduleAvailable !== false,
                available: !!config?.available,
                eligible: !!config?.eligible,
                linked: !!config?.linked,
                stale: !!config?.stale,
                project: config?.project ?? null,
            }
        },
        /**
         * v4.9.3 — clear the per-team half on a team switch, keep
         * `available` (instance-level), for the reason
         * RESET_COLLECTIVES_TEAM_CONFIG records.
         */
        RESET_OPENPROJECT_TEAM_CONFIG(state) {
            state.openProjectConfig = { ...state.openProjectConfig, eligible: false, linked: false, stale: false, project: null }
        },
        // v4.9.6 — per team; cleared on a switch like the OpenProject facts.
        SET_PROVISIONING(state, summary) { state.provisioning = summary && typeof summary === 'object' ? summary : null },
        SET_JUST_CREATED_ADVANCED_PROJECT(state, teamId) { state.justCreatedAdvancedProjectTeamId = teamId },
        SET_PROJECT_TAB_FOCUS(state, value) { state.projectTabFocus = value },
        // v3.98.0 — Project Compass deep-link. Payload: { tab, section } or null.
        SET_MANAGE_TEAM_DEEP_LINK(state, payload) {
            state.manageTeamDeepLink = payload
                ? { tab: payload.tab, section: payload.section || null, nonce: Date.now() }
                : null
        },
        SET_DECISIONS_MODULE_ENABLED(state, val) { state.decisionsModuleEnabled = val },
        SET_DECISIONS_TARGET(state, messageId) { state.decisionsTargetMessageId = messageId },

        /**
         * v4.5.26 — the message a deep link wants brought into view.
         *
         * Carries a nonce for the same reason SET_MANAGE_TEAM_DEEP_LINK does:
         * opening the *same* message twice in a row has to re-trigger the
         * highlight, and a bare id would not change, so the watcher would never
         * fire the second time.
         */
        SET_MESSAGE_TARGET(state, messageId) {
            state.messageTarget = messageId
                ? { messageId: Number(messageId), nonce: Date.now() }
                : null
        },
        SET_DECISIONS_PRESELECT_STATUS(state, val) { state.decisionsPreselectStatus = val },
        SET_LOADING(state, { key, value }) { state.loading[key] = value }, // Vue 3: direct assignment is reactive
        SET_ERROR(state, error) { state.error = error },

        // ── My Work (v4.5.21) ────────────────────────────────────────────
        // Filters merge rather than replace, so a component can update one
        // key without knowing the others exist.
        SET_MYWORK_FILTERS(state, patch) {
            state.myWork.filters = { ...state.myWork.filters, ...(patch || {}) }
        },
        RESET_MYWORK_FILTERS(state) {
            state.myWork.filters = {
                search: '', teamId: '', providerId: '', priority: '',
                status: '', resourceType: '', dueWindow: '',
                showSnoozed: false, category: '',
                projectId: '', workType: '',
            }
        },
        SET_MYWORK_GROUP_BY(state, groupBy) { state.myWork.groupBy = groupBy },
        SET_MYWORK_SORT_BY(state, sortBy) { state.myWork.sortBy = sortBy },
        SET_MYWORK_PAGE(state, page) { state.myWork.page = page },
        SET_MYWORK_SCROLL(state, top) { state.myWork.scrollTop = top },
        SET_MYWORK_COLLAPSED_GROUPS(state, keys) {
            state.myWork.collapsedGroups = Array.isArray(keys) ? keys.map(String) : []
        },
        SET_MYWORK_COMPACT(state, compact) { state.myWork.compact = !!compact },
        SET_MYWORK_PAYLOAD(state, payload) {
            state.myWork.payload = payload
            state.myWork.loadedAt = Date.now()
        },
        SET_MYWORK_PROVIDERS(state, providers) {
            state.myWork.providers = Array.isArray(providers) ? providers : []
        },
        SET_INTRAVOX_AVAILABLE(state, value) { state.intravoxAvailable = value },
        SET_INTRAVOX_PARENT_PATH(state, value) { state.intravoxParentPath = value },
    },

    actions: {
        /**
         * Open a file inside TeamHub's files-view iframe instead of a new tab.
         * Sets the override URL first, then switches to the files view (order
         * matters: the view's iframe reads filesEmbedFileUrl on render).
         * `fileUrl` is the in-Files app URL the widget already builds (/f/{id}).
         */
        openFileInEmbed({ commit }, fileUrl) {
            if (!fileUrl) { return }
            // v4.5.8 — on a phone or tablet-portrait, drop the conversation
            // sidebar request. Below its own breakpoint NC renders the Files
            // sidebar full-width, so it covered the file the user asked to
            // open. Stripped here, at the single point every in-app file open
            // funnels through, rather than in fileOpenUrl() — links stored in
            // messages must stay viewport-independent.
            const url = isNarrowViewport() ? withoutSidebarRequest(fileUrl) : fileUrl
            commit('SET_FILES_EMBED_FILE_URL', url)
            commit('SET_VIEW', 'files')
        },

        /**
         * Open the team's own folder in TeamHub's files tab (v4.6.25).
         *
         * `TeamView::filesUrl` already falls back to the team folder when no
         * file is pinned, so there is no URL to build here — the work is
         * clearing a pin that may still be set. `SET_VIEW` only drops
         * `filesEmbedFileUrl` when *leaving* the files view, which means a user
         * who opened a file, went back to Home and then asked for the team
         * folder would have been handed the file again.
         */
        openTeamFolderInEmbed({ commit }) {
            commit('SET_FILES_EMBED_FILE_URL', null)
            commit('SET_VIEW', 'files')
        },

        /**
         * Pin a single calendar event into TeamHub's calendar tab (v4.5.9,
         * reworked v4.5.10). Order matters, as with openFileInEmbed: set the
         * pin first, then switch view — TeamView's calendarUrl reads it on
         * render.
         *
         * @param {object} event
         * @param {string} event.url          the backend's own event URL
         * @param {number} [event.calendarId] the calendar it belongs to, so the
         *        tab can return to that agenda once the event is closed
         */
        openEventInEmbed({ commit }, event) {
            if (!event?.url) { return }
            commit('SET_CALENDAR_EMBED_EVENT', event)
            commit('SET_VIEW', 'calendar')
        },

        /**
         * Open a single Deck card inside TeamHub's deck iframe (v4.5.9).
         * Pre-selects the board as well, so leaving the card (or a later plain
         * Deck-tab visit) lands on the right board rather than the first one.
         */
        openDeckCardInEmbed({ commit }, { boardId, cardId, boardName }) {
            if (!boardId) { return }
            commit('SET_SELECTED_DECK_BOARD', { board_id: boardId, name: boardName || '' })
            // v4.5.12 — history form, not the legacy `#/board/…` hash one that
            // deckUrl still uses for the plain board view. Deck runs vue-router
            // in history mode, so it routes on the path; a hash-only URL cannot
            // be navigated to in place (see AppEmbed.tryInPlaceNavigation) and
            // this is the form DeckWidget already uses for its own card links.
            //
            // Without a card this is a board link, which deckUrl already handles
            // via selectedDeckBoard, so clear any card left pinned from before.
            commit('SET_DECK_EMBED_CARD_URL', cardId
                ? generateUrl(`/apps/deck/board/${boardId}/card/${cardId}`)
                : null)
            commit('SET_VIEW', 'deck')
        },

        /** Open a Wiki (Collectives) page in the wiki tab. `path` is app-relative. */
        openCollectivePageInEmbed({ commit }, path) {
            if (!path) { return }
            commit('SET_COLLECTIVES_EMBED_PAGE_URL', path)
            commit('SET_VIEW', 'collectives')
        },

        /**
         * Open whatever `resolveInternalTarget()` recognised, in the tab that
         * owns it (v4.5.11). One entry point so every surface — activity feed,
         * message stream, decision links, links inside an embedded app — routes
         * the same way and gains new target types for free.
         *
         * Callers must have checked `canOpenInEmbed` first; this is the doing,
         * not the deciding.
         */
        openInEmbed({ dispatch }, target) {
            switch (target?.type) {
            case 'file':
                return dispatch('openFileInEmbed', fileOpenUrl(target.fileId))
            case 'deck':
                return dispatch('openDeckCardInEmbed', {
                    boardId: target.boardId,
                    cardId:  target.cardId,
                })
            case 'calendar':
                return dispatch('openEventInEmbed', {
                    object:       target.object,
                    recurrenceId: target.recurrenceId,
                })
            case 'collectives':
                return dispatch('openCollectivePageInEmbed', target.path)
            }
        },

        async checkIntravox({ commit }) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                commit('SET_INTRAVOX_AVAILABLE', !!data.intravox)
                commit('SET_INTRAVOX_PARENT_PATH', data.intravoxParentPath || 'en/teamhub')
            } catch (e) {
                try {
                    await axios.get(generateUrl('/apps/intravox/api/pages'), { timeout: 3000 })
                    commit('SET_INTRAVOX_AVAILABLE', true)
                } catch (e2) {
                    commit('SET_INTRAVOX_AVAILABLE', false)
                }
            }
        },

        async fetchTeams({ commit, dispatch }) {
            commit('SET_LOADING', { key: 'teams', value: true })
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams'))
                commit('SET_TEAMS', Array.isArray(data) ? data : [])
                // NC 34+: move any TeamHub-era picture into Teams in the
                // background (no await so the team list renders immediately).
                // Usually a no-op — see the action.
                dispatch('migrateTeamAvatars')
            } catch (e) {
                commit('SET_ERROR', 'Failed to load teams')
            } finally {
                commit('SET_LOADING', { key: 'teams', value: false })
            }
        },

        /**
         * v4.7.3 — the user's sidebar grouping.
         *
         * Dispatched alongside `fetchTeams` rather than after it: the two are
         * independent reads and the sidebar needs both, so serialising them
         * would double the time to first paint for no gain.
         *
         * A failure is swallowed on purpose. This is sidebar chrome, and the
         * getter's fallback is the flat team list — the same fails-open
         * choice the licence-entitlements fetch in App.vue makes. Raising a
         * global error banner because a preference row would not load would
         * be louder than the problem.
         */
        async fetchTeamGroups({ commit }) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/team-groups'))
                commit('SET_TEAM_GROUPS', data)
            } catch (e) {
                // Deliberately silent — see above.
            }
        },

        /**
         * Create a group, and optionally move a team straight into it.
         *
         * The create response carries the new `groupId`, so the common path —
         * "New group…" from a team's action menu — costs two requests rather
         * than a create, a re-fetch and then a move.
         *
         * Unlike the read above, every mutation here rethrows: the user just
         * clicked something, so a failure has to reach them as a toast rather
         * than a sidebar that quietly did not change.
         */
        async createTeamGroup({ commit, dispatch }, { name, teamId = null }) {
            const { data } = await axios.post(
                generateUrl('/apps/teamhub/api/v1/team-groups'),
                { name },
            )
            commit('SET_TEAM_GROUPS', data)

            if (teamId && data?.groupId) {
                await dispatch('assignTeamToGroup', { teamId, groupId: data.groupId })
            }
            return data?.groupId ?? null
        },

        async renameTeamGroup({ commit }, { groupId, name }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/team-groups/${encodeURIComponent(groupId)}`),
                { name },
            )
            commit('SET_TEAM_GROUPS', data)
        },

        /**
         * Persist the chevron. Commits optimistically first so the group
         * opens instantly; see SET_TEAM_GROUP_EXPANDED for what happens when
         * the write loses.
         */
        async setTeamGroupExpanded({ commit }, { groupId, expanded }) {
            commit('SET_TEAM_GROUP_EXPANDED', { groupId, expanded })
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/team-groups/${encodeURIComponent(groupId)}`),
                { expanded },
            )
            commit('SET_TEAM_GROUPS', data)
        },

        /** Delete a group. Its teams become loose items again. */
        async deleteTeamGroup({ commit }, groupId) {
            const { data } = await axios.delete(
                generateUrl(`/apps/teamhub/api/v1/team-groups/${encodeURIComponent(groupId)}`),
            )
            commit('SET_TEAM_GROUPS', data)
        },

        /** Move a team into a group, or out of every group with `groupId: null`. */
        async assignTeamToGroup({ commit }, { teamId, groupId }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${encodeURIComponent(teamId)}/group`),
                { groupId: groupId ?? null },
            )
            commit('SET_TEAM_GROUPS', data)
        },

        /**
         * NC 34+ only: move any team still carrying a TeamHub-era picture into
         * Nextcloud Teams' own avatar storage, so the two UIs show one picture
         * (DESIGN §2.68). Runs off fetchTeams in the background and never
         * blocks the team list.
         *
         * v4.6.25 — this replaces `resolveTeamAvatars`, which fetched every
         * team's Teams avatar over OCS to find out whether it had one. Display
         * no longer needs that: `image_url` already serves whichever storage
         * holds the picture. All that is left is the migration, and the server
         * says which teams need it (`image_source === 'legacy'`), so on an
         * instance with nothing to migrate — the normal case — this issues no
         * requests at all. That is what took the 404s out of the console: the
         * old action asked once per team and most teams had no avatar to
         * report.
         *
         * No-op on NC 32/33, where `nc_avatar_supported` is false everywhere
         * and TeamHub's own storage stays the source of truth.
         */
        async migrateTeamAvatars({ state, commit }) {
            const pending = state.teams.filter(t =>
                t
                && t.nc_avatar_supported
                && t.image_source === 'legacy'
                // Circles gates avatar writes at circle admin, so anyone below
                // that would only earn a 403. Their teams migrate when an admin
                // next opens the list.
                && (t.level ?? 0) >= 8
                && t.image_url
            )

            await Promise.allSettled(pending.map(async (team) => {
                try {
                    await migrateLegacyImageToTeams(team.id, team.image_url)
                    // Same route, different bytes behind it now — the
                    // cache-buster is what makes the browser notice.
                    commit('UPDATE_TEAM_IMAGE', {
                        teamId: team.id,
                        imageUrl: teamImageUrl(team.id),
                    })
                } catch (e) {
                    // Leave the legacy picture in place and try again next
                    // load. It still renders either way — the migration only
                    // decides which app stores it.
                    console.error('[TeamHub][store] migrateTeamAvatars failed for ' + team.id, e)
                }
            }))
        },

        async selectTeam({ commit, dispatch }, teamId) {
            commit('SET_CURRENT_TEAM', teamId)
            commit('SET_VIEW', 'msgstream')
            commit('SET_MESSAGES', [])
            commit('SET_PINNED_MESSAGE', null)
            commit('SET_MESSAGES_PAGE', 1)
            commit('SET_MESSAGES_TOTAL', 0)
            commit('SET_MESSAGE_SETTINGS', { manageMinLevel: 'admin', postMinLevel: 'member', linkMinLevel: 'admin', commentMinLevel: 'member', commentsEnabled: {}, allowPublicMessages: false })
            commit('RESET_COLLECTIVES_TEAM_CONFIG')
            commit('RESET_OPENPROJECT_TEAM_CONFIG')
            commit('SET_MEMBERS', [])
            commit('SET_ALL_EFFECTIVE_MEMBERS', [])
            commit('SET_RESOURCES', {})
            commit('SET_WEB_LINKS', [])
            commit('SET_TEAM_WIDGETS', [])
            commit('SET_TEAM_MENU_ITEMS', [])
            // Reset project fact so a previous team's stepper never flashes before
            // the layout bundle for the newly selected team arrives.
            commit('SET_PROJECT', { isProject: false, mode: null, phase: null, startDate: null, targetEnd: null })
            // v4.6.15 — same reasoning for the dashboard pickers. Left in place,
            // they let Manage Team → Settings → Dashboard show the previous
            // team's tabs and default-tab value while saving to this one.
            commit('SET_AVAILABLE_TABS', [])
            commit('SET_DASHBOARD_WIDGET_CATALOG', [])
            commit('SET_AVAILABLE_TABS_TEAM_ID', null)
            commit('SET_TEAM_TAB_ORDER', [])
            commit('SET_DASHBOARD_CONFIG', { hidden_widgets: [], default_tab: 'msgstream' })

            // Mark seen immediately (optimistic) + fire-and-forget to backend
            commit('MARK_TEAM_SEEN', teamId)
            dispatch('markTeamSeen', teamId)

            await Promise.all([
                dispatch('fetchMessages', teamId),
                dispatch('fetchMembers', teamId),
                dispatch('fetchAllEffectiveMembers', teamId),
                dispatch('fetchResources', teamId),
                dispatch('fetchWebLinks', teamId),
                dispatch('fetchTeamIntegrations', teamId),
                dispatch('fetchMessageSettings', teamId),
            ])
        },

        // ── Team layout bundle + dashboard pickers (v4.6.15) ────────────────

        /**
         * GET the team's layout bundle and commit every per-team fact riding on
         * it. Returns the raw bundle so the caller can take the parts that are
         * not store state — TeamView keeps the widget grid (`layout`,
         * `userDefault`) and the saved `tabOrder` for its own arrangement.
         *
         * This lives here rather than in TeamView because Manage Team needs the
         * same facts on the paths where TeamView never mounts, and two copies of
         * a 13-commit sequence is exactly the drift this refactor removes.
         */
        async loadTeamLayout({ commit }, teamId) {
            const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/layout`))
            // Presence module flag and per-team config both arrive with layout — no race.
            if (typeof data.presenceModuleEnabled === 'boolean') {
                commit('SET_PRESENCE_MODULE_ENABLED', data.presenceModuleEnabled)
            }
            if (data.presenceConfig) {
                commit('SET_PRESENCE_CONFIG', data.presenceConfig)
            }
            // Decisions module flag — default off until getTeam confirms it's on.
            if (typeof data.decisionsModuleEnabled === 'boolean') {
                commit('SET_DECISIONS_MODULE_ENABLED', data.decisionsModuleEnabled)
            }
            if (data.decisionsConfig) {
                commit('SET_DECISIONS_CONFIG', data.decisionsConfig)
            }
            if (data.timelineConfig) {
                commit('SET_TIMELINE_CONFIG', data.timelineConfig)
            }
            // Messages integration (v3.104.1) — per-team toggle rides along
            // with the layout, same pattern as timelineConfig.
            if (data.messagesConfig) {
                commit('SET_MESSAGES_CONFIG', data.messagesConfig)
            }
            // Collectives (v4.3.5) — per-team toggle + install-state, same
            // layout-bundle pattern. Default off so a team that never touches
            // the setting hides the widget + tab.
            if (data.collectivesConfig) {
                commit('SET_COLLECTIVES_CONFIG', data.collectivesConfig)
            }
            // Team-wide dashboard customization (hidden widgets + default
            // tab) rides along with the layout, same pattern as messagesConfig.
            if (data.dashboardConfig) {
                commit('SET_DASHBOARD_CONFIG', data.dashboardConfig)
            }
            // Team template label (v4.0.2) — always emitted (null for legacy
            // teams). Reset the store so switching from a labelled team to a
            // legacy one clears the badge.
            commit('SET_TEAM_TYPE', data.teamType ?? null)
            // v4.6.13 — expiration date, always emitted (null for teams without
            // one). Reset for the same reason as the type above: switching from
            // an expiring team to one with no date must clear the banner rather
            // than leave the previous team's.
            commit('SET_TEAM_EXPIRY', data.teamExpiry ?? null)
            // Budget integration (v3.92.0) — per-team toggle rides along
            // with the layout, same pattern as timelineConfig.
            if (data.budgetConfig) {
                commit('SET_BUDGET_CONFIG', data.budgetConfig)
            }
            // Time investment integration (v3.96.0) — same pattern.
            if (data.timeConfig) {
                commit('SET_TIME_CONFIG', data.timeConfig)
            }
            // Project Teams (v3.88.0) — project fact rides along with the layout.
            if (data.project) {
                commit('SET_PROJECT', data.project)
            }
            // OpenProject (v4.9.3) — link facts ride along with the layout.
            if (data.openProjectConfig) {
                commit('SET_OPENPROJECT_CONFIG', data.openProjectConfig)
            }
            // Provisioning (v4.9.6) — the incomplete-workspace fact rides along too;
            // null is a real value (never provisioned) and is set as such.
            commit('SET_PROVISIONING', data.provisioning ?? null)
            // Kept so a republish (a Manage Team toggle changing which tabs
            // exist) can re-apply the user's arrangement without TeamView, which
            // owns the drag-reorderable copy, being on screen to supply it.
            commit('SET_TEAM_TAB_ORDER', Array.isArray(data.tabOrder) ? data.tabOrder : [])
            return data
        },

        /**
         * Publish the Manage Team → Settings → Dashboard pickers from current
         * store facts. `ordered` lets TeamView pass its own arrangement (which
         * the user can drag-reorder) so the picker matches the tab bar exactly;
         * without it the saved `tabOrder` is applied here instead.
         */
        publishTeamTabs({ state, commit }, { teamId = null, ordered = null, tabOrder = null } = {}) {
            const list = ordered || orderTabDescriptors(buildAllTabDescriptors(state), tabOrder || state.teamTabOrder)
            commit('SET_AVAILABLE_TABS', buildAvailableTabs(list, state.resources))
            commit('SET_DASHBOARD_WIDGET_CATALOG', buildDashboardWidgetCatalog(state))
            commit('SET_AVAILABLE_TABS_TEAM_ID', teamId || state.currentTeamId || null)
        },

        /**
         * Make sure the dashboard pickers describe THIS team before Manage Team
         * renders them. A no-op when TeamView has already published for the same
         * team, so the common path (open team → Manage team button) costs
         * nothing; the sidebar and deep-link paths pay one layout request, which
         * is the request TeamView would have made anyway had it mounted.
         *
         * A failed bundle leaves the lists empty on purpose — the Dashboard
         * section then says the tabs could not be loaded, which is honest,
         * rather than offering a picker built from half-loaded facts.
         */
        async ensureTeamDashboardFacts({ state, dispatch }, teamId) {
            if (!teamId || state.availableTabsTeamId === teamId) return
            try {
                await dispatch('loadTeamLayout', teamId)
                dispatch('publishTeamTabs', { teamId })
            } catch (err) {
                // Guard state above is the user-visible outcome.
            }
        },

        // ── Project Teams (v3.88.0) ─────────────────────────────────────────
        // The project fact arrives with the layout bundle (SET_PROJECT). These
        // actions mutate it (admin-gated server-side) and re-commit the result.

        async saveProjectMode({ commit, state }, { mode, startDate = null, targetEnd = null }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/project`),
                { mode, start_date: startDate, target_end: targetEnd }
            )
            commit('SET_PROJECT', data)
            return data
        },

        async setProjectPhase({ commit, state }, phase) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/project/phase`),
                { phase }
            )
            commit('SET_PROJECT', data)
            return data
        },

        async fetchMessages({ commit, state }, { teamId, page, aroundMessageId } = {}) {
            // Allow callers to pass just teamId as a string (backwards compat)
            if (typeof teamId !== 'string') {
                teamId = teamId || state.currentTeamId
            }
            const targetPage = page || state.messagesPage || 1
            commit('SET_LOADING', { key: 'messages', value: true })
            try {
                const params = { page: targetPage, limit: state.messagesLimit }
                // v4.5.26 — "land on the page holding this message". The server
                // resolves it, because the page size and the stream's order are
                // its business, not the caller's.
                if (aroundMessageId) {
                    params.aroundMessageId = aroundMessageId
                }
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages`),
                    { params }
                )
                commit('SET_PINNED_MESSAGE', data.pinned || null)
                commit('SET_MESSAGES', Array.isArray(data.messages) ? data.messages : [])
                commit('SET_MESSAGES_TOTAL', data.total || 0)
                // The response's own page number, not the one we asked for —
                // with aroundMessageId the server decides, and the pager has to
                // show where we actually landed.
                commit('SET_MESSAGES_PAGE', Number(data.page) || targetPage)
            } catch (e) {
                commit('SET_ERROR', 'Failed to load messages')
            } finally {
                commit('SET_LOADING', { key: 'messages', value: false })
            }
        },

        /**
         * Subscribe to / unsubscribe from comment notifications on one message
         * (v4.8.7, GitHub #95).
         *
         * The verb carries the intent — PUT subscribes, DELETE unsubscribes —
         * so there is no body and no way for the two to be read as the same
         * request. The commit uses the server's answer rather than the
         * requested value, so a state the server resolved differently is what
         * the toggle ends up showing.
         *
         * Throws on failure so the caller can restore the toggle and say so;
         * silently swallowing it would leave the button showing a state the
         * server never accepted.
         */
        async setMessageSubscription({ commit }, { messageId, subscribed }) {
            const url = generateUrl(`/apps/teamhub/api/v1/messages/${messageId}/subscription`)
            const { data } = subscribed
                ? await axios.put(url)
                : await axios.delete(url)
            commit('SET_MESSAGE_SUBSCRIPTION', {
                messageId,
                subscribed: !!data.subscribed,
            })
            return !!data.subscribed
        },

        async fetchMessageSettings({ commit }, teamId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/settings`)
                )
                commit('SET_MESSAGE_SETTINGS', data)
            } catch (e) {
                // Non-fatal — defaults remain in state
            }
        },

        async postMessage({ commit, state, dispatch }, { subject, message, priority, messageType, pollOptions, decision, isPublic }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/messages`),
                { subject, message, priority, messageType, pollOptions, decision, isPublic: !!isPublic }
            )
            commit('ADD_MESSAGE', data)
            // Refresh unread counts so other users' badges reflect the new message.
            // Fire-and-forget — don't await, don't block the UI.
            dispatch('refreshUnreadCounts')
            return data
        },

        // ── Decisions module — Session C ────────────────────────────────────

        /**
         * Fetch decisions for the widget.
         * status: null for latest (any status), or 'proposed' for the Open tab.
         * Returns a plain array of serialised decision objects.
         */
        async fetchWidgetDecisions({ state }, { status = null, limit = 5 } = {}) {
            const params = { limit, sort: 'recent' }
            if (status) params.status = status
            const { data } = await axios.get(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions`),
                { params }
            )
            return Array.isArray(data?.items) ? data.items : []
        },

        /** Fetch the distinct categories used in this team for autocomplete. */
        async fetchDecisionCategories({ state }) {
            const { data } = await axios.get(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions/categories`)
            )
            return data?.categories || []
        },

        /**
         * Session H — finalize an open decision. The proposer's chosen comment
         * becomes the canonical final wording. Status: open → finalized.
         *
         * Backward compat: also exposed as markDecisionBest below so any
         * older caller keeps working without renaming.
         */
        async finalizeDecision({ commit, state }, { decisionId, commentId, messageId }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions/${decisionId}/finalize`),
                { comment_id: commentId }
            )
            commit('SET_MESSAGE_DECISION', { messageId, decision: data })
            return data
        },

        // Alias for callers that may still reference the legacy name.
        async markDecisionBest(ctx, payload) {
            return ctx.dispatch('finalizeDecision', payload)
        },

        /** Withdraw a non-terminal decision with a non-empty reason. */
        async withdrawDecision({ commit, state }, { decisionId, reason, messageId }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions/${decisionId}/withdraw`),
                { reason }
            )
            commit('SET_MESSAGE_DECISION', { messageId, decision: data })
            return data
        },

        /**
         * Approve a finalized decision. Caller must be in the category's
         * approver list (enforced server-side). Status: finalized → approved.
         */
        async approveDecision({ commit, state }, { decisionId, messageId, reason }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions/${decisionId}/approve`),
                { reason }
            )
            commit('SET_MESSAGE_DECISION', { messageId, decision: data })
            return data
        },

        /**
         * Deny a finalized decision with a non-empty reason. Caller must be
         * in the category's approver list. Status: finalized → denied (terminal).
         */
        async denyDecision({ commit, state }, { decisionId, reason, messageId }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/decisions/${decisionId}/deny`),
                { reason }
            )
            commit('SET_MESSAGE_DECISION', { messageId, decision: data })
            return data
        },

        async deleteMessage({ commit }, { teamId, messageId }) {
            await axios.delete(
                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/${messageId}`)
            )
            commit('REMOVE_MESSAGE', messageId)
        },

        async updateMessage({ commit }, { teamId, messageId, subject, message }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/${messageId}`),
                { subject, message }
            )
            commit('UPDATE_MESSAGE', data)
            return data
        },

        async pinMessage({ commit }, { teamId, messageId }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/${messageId}/pin`)
            )
            commit('PIN_MESSAGE', data)
        },

        async unpinMessage({ commit }, { teamId, messageId }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/${messageId}/unpin`)
            )
            commit('UNPIN_MESSAGE', data)
        },

        async markTeamSeen(_, teamId) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/seen`))
            } catch (e) {
                // Non-critical — silently ignore
            }
        },

        /**
         * Silently re-fetch the team list to refresh unread counts in the
         * sidebar. Does NOT set the loading spinner — this runs in the
         * background every 60s. Only updates the teams array in place so
         * the sidebar re-renders its NcCounterBubble values.
         */
        async refreshUnreadCounts({ commit }) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams'))
                if (Array.isArray(data)) {
                    commit('UPDATE_UNREAD_COUNTS', data)
                }
            } catch (e) {
                // Non-critical — silently ignore
            }
        },

        async updateComment({ commit }, { messageId, commentId, comment }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/comments/${commentId}`),
                { comment }
            )
            commit('UPDATE_COMMENT', { messageId, comment: data })
            return data
        },

        /**
         * Hard-delete a comment. Backend enforces author-or-admin permission.
         * Response carries the updated parent message so comment_count and any
         * cleared solved-question state refresh in one round trip.
         */
        async deleteComment({ commit }, { messageId, commentId }) {
            const { data } = await axios.delete(
                generateUrl(`/apps/teamhub/api/v1/comments/${commentId}`)
            )
            commit('REMOVE_COMMENT', { messageId, commentId })
            if (data && data.message) {
                commit('UPDATE_MESSAGE', data.message)
            }
            return data
        },

        async fetchMembers({ commit }, teamId) {
            commit('SET_LOADING', { key: 'members', value: true })
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members`))
                // Response: { members, memberships, effective_count, has_more, is_direct_member }
                if (Array.isArray(data)) {
                    commit('SET_MEMBERS', data)
                    commit('SET_MEMBERSHIPS', [])
                    commit('SET_EFFECTIVE_MEMBER_COUNT', data.length)
                    commit('SET_HAS_MORE_MEMBERS', false)
                    commit('SET_IS_DIRECT_MEMBER', true)
                    commit('SET_CURRENT_USER_LEVEL', 0)
                } else {
                    commit('SET_MEMBERS', Array.isArray(data.members) ? data.members : [])
                    commit('SET_MEMBERSHIPS', Array.isArray(data.memberships) ? data.memberships : [])
                    commit('SET_EFFECTIVE_MEMBER_COUNT', data.effective_count || 0)
                    commit('SET_HAS_MORE_MEMBERS', !!data.has_more)
                    commit('SET_IS_DIRECT_MEMBER', data.is_direct_member !== false)
                    commit('SET_CURRENT_USER_LEVEL', typeof data.current_user_level === 'number' ? data.current_user_level : 0)
                }
            } catch (e) {
                commit('SET_MEMBERS', [])
                commit('SET_MEMBERSHIPS', [])
                commit('SET_EFFECTIVE_MEMBER_COUNT', 0)
                commit('SET_HAS_MORE_MEMBERS', false)
                commit('SET_IS_DIRECT_MEMBER', true)
                commit('SET_CURRENT_USER_LEVEL', 0)
            } finally {
                commit('SET_LOADING', { key: 'members', value: false })
            }
        },

        /**
         * Fetch the full flat list of ALL effective members — direct and indirect
         * (via groups or sub-teams). Uses circles_membership as the source of truth.
         * Stored separately from `members` (which is the capped-at-16 widget list).
         * Used exclusively for @mention autocomplete filtering so indirect members
         * are mentionable.
         *
         * Silently degrades — if the call fails, mention filtering falls back to
         * the direct members list via the OCS fallback path.
         */
        async fetchAllEffectiveMembers({ commit }, teamId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members/all`)
                )
                // Backend returns { members: [...], talkAvailable: bool, mailAvailable: bool }.
                // Tolerate a bare array too for forward/backward compatibility.
                const members = Array.isArray(data)
                    ? data
                    : (Array.isArray(data?.members) ? data.members : [])
                commit('SET_ALL_EFFECTIVE_MEMBERS', members)
                commit('SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE',
                    !Array.isArray(data) && !!data?.talkAvailable)
                commit('SET_ALL_EFFECTIVE_MEMBERS_MAIL_AVAILABLE',
                    !Array.isArray(data) && !!data?.mailAvailable)
            } catch (e) {
                commit('SET_ALL_EFFECTIVE_MEMBERS', [])
                commit('SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE', false)
                commit('SET_ALL_EFFECTIVE_MEMBERS_MAIL_AVAILABLE', false)
            }
        },

        async fetchResources({ commit, dispatch }, teamId) {
            commit('SET_LOADING', { key: 'resources', value: true })
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/resources`))
                // Strip _warnings before storing as resources — it's a meta key, not a resource.
                const { _warnings, ...resourceData } = data || {}
                commit('SET_RESOURCES', resourceData)
                commit('SET_RESOURCE_WARNINGS', _warnings || { pending: 0, atRisk: 0 })
                // Fetch tasks for ALL connected Deck boards.
                if (data?.deck?.length > 0) {
                    dispatch('fetchDeckTasks', data.deck)
                }
                // Fetch team calendar tasks when Tasks app is installed AND the team has a calendar.
                if (data?.tasks && data?.calendar?.length > 0) {
                    dispatch('fetchTeamTasks', teamId)
                } else {
                    commit('SET_TEAM_TASKS', [])
                }
            } catch (e) {
                commit('SET_RESOURCES', {})
                commit('SET_RESOURCE_WARNINGS', { pending: 0, atRisk: 0 })
                commit('SET_TEAM_TASKS', [])
            } finally {
                commit('SET_LOADING', { key: 'resources', value: false })
            }
        },

        async fetchWebLinks({ commit }, teamId) {
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/links`))
                commit('SET_WEB_LINKS', Array.isArray(data) ? data : [])
            } catch (e) {
                commit('SET_WEB_LINKS', [])
            }
        },

        /**
         * Fetch all enabled integrations for a team (widgets + menu_items).
         * Called by selectTeam. Silently degrades — most installs start with none.
         * Response shape: { widgets: [...], menu_items: [...] }
         */
        async fetchTeamIntegrations({ commit }, teamId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/integrations`)
                )
                commit('SET_TEAM_WIDGETS',    Array.isArray(data.widgets)    ? data.widgets    : [])
                commit('SET_TEAM_MENU_ITEMS', Array.isArray(data.menu_items) ? data.menu_items : [])
            } catch (e) {
                // Non-fatal — integrations are optional.
                commit('SET_TEAM_WIDGETS', [])
                commit('SET_TEAM_MENU_ITEMS', [])
            }
        },

        async fetchDeckTasks({ commit }, boards) {
            // boards can be a single boardId (legacy) or array of board objects.
            const boardList = Array.isArray(boards)
                ? boards
                : (typeof boards === 'number' ? [{ board_id: boards }] : [])

            if (boardList.length === 0) {
                commit('SET_DECK_TASKS', [])
                commit('SET_DECK_UNASSIGNED', {})
                return
            }

            try {
                const now = new Date()
                const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
                const cutoff = new Date(todayStart)
                cutoff.setDate(cutoff.getDate() + 14)
                const allCards = []
                // Unassigned counts: { [boardId]: { count, boardName } }
                // Card qualifies when: not archived, not done, no assignees,
                // and due date is in the future OR absent (no due date = not overdue).
                const unassignedCounts = {}

                for (const board of boardList) {
                    const boardId = board.board_id ?? board
                    try {
                        const { data } = await axios.get(
                            generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks`),
                            { headers: { 'OCS-APIRequest': 'true' } }
                        )
                        ;(Array.isArray(data) ? data : []).forEach(stack => {
                            ;(stack.cards || []).forEach(card => {
                                if (card.archived || card.done) return

                                // ── Upcoming tasks (for the task list) ──────────
                                if (card.duedate) {
                                    const due = new Date(card.duedate)
                                    if (due >= todayStart && due <= cutoff) {
                                        allCards.push({
                                            id: card.id,
                                            title: card.title,
                                            duedate: card.duedate,
                                            assignedUsers: card.assignedUsers || [],
                                            boardId,
                                            boardName: board.name || '',
                                            overdue: due < now,
                                        })
                                    }
                                }

                                // ── Unassigned count ─────────────────────────────
                                // Include cards with no assignees that are not yet
                                // overdue: no due date counts as "not overdue".
                                const hasAssignees = card.assignedUsers && card.assignedUsers.length > 0
                                if (!hasAssignees) {
                                    const due = card.duedate ? new Date(card.duedate) : null
                                    const isOverdue = due && due < now
                                    if (!isOverdue) {
                                        if (!unassignedCounts[boardId]) {
                                            unassignedCounts[boardId] = { count: 0, boardName: board.name || '' }
                                        }
                                        unassignedCounts[boardId].count++
                                    }
                                }
                            })
                        })
                    } catch (e) {
                        // skip failed board, continue with others
                    }
                }

                allCards.sort((a, b) => new Date(a.duedate) - new Date(b.duedate))
                commit('SET_DECK_TASKS', allCards.slice(0, 20))
                commit('SET_DECK_UNASSIGNED', unassignedCounts)
            } catch (e) {
                commit('SET_DECK_TASKS', [])
                commit('SET_DECK_UNASSIGNED', {})
            }
        },

        /**
         * Fetch VTODO tasks from the team calendar via the TeamHub backend.
         * Only called when resources.tasks === true AND resources.calendar is set.
         */
        async fetchTeamTasks({ commit }, teamId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/tasks`),
                )
                const tasks = Array.isArray(data) ? data : []
                commit('SET_TEAM_TASKS', tasks)
            } catch (e) {
                commit('SET_TEAM_TASKS', [])
            }
        },

        async fetchComments({ commit }, messageId) {
            const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/messages/${messageId}/comments`))
            commit('SET_COMMENTS', { messageId, comments: Array.isArray(data) ? data : [] })
        },

        async postComment({ commit }, { messageId, comment }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/messages/${messageId}/comments`),
                { comment }
            )
            commit('ADD_COMMENT', { messageId, comment: data })
            commit('BUMP_COMMENT_COUNT', { messageId, delta: 1 })
            return data
        },

        async saveWebLink({ dispatch, state }, { title, url }) {
            await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/links`), { title, url })
            await dispatch('fetchWebLinks', state.currentTeamId)
        },

        async deleteWebLink({ dispatch, state }, linkId) {
            await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/links/${linkId}`))
            await dispatch('fetchWebLinks', state.currentTeamId)
        },

        async createTeam({ dispatch }, { name, description }) {
            const { data } = await axios.post(generateUrl('/apps/teamhub/api/v1/teams'), { name, description })
            await dispatch('fetchTeams')
            return data
        },

        async updateTeamDescription({ state }, { teamId, description }) {
            await axios.put(
                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/description`),
                { description }
            )
        },

        async removeMember({ dispatch }, { teamId, userId }) {
            await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members/${userId}`))
            await dispatch('fetchMembers', teamId)
        },

        async fetchPendingRequests(_, teamId) {
            const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/pending-requests`))
            return Array.isArray(data) ? data : []
        },

        async approveRequest({ dispatch }, { teamId, userId }) {
            await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/approve/${userId}`))
            await dispatch('fetchMembers', teamId)
        },

        async rejectRequest(_, { teamId, userId }) {
            await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/reject/${userId}`))
        },
    },
})
