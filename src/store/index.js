// Vue import removed — not needed in Vuex 4 store
import { createStore } from 'vuex'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'

// Vue.use(Vuex) removed — Vuex 4 uses app.use(store) in the entrypoint

export default createStore({
    state: {
        teams: [],
        currentTeamId: null,
        currentView: 'msgstream',
        // When set, the files view embeds this specific file URL instead of the
        // team folder. Set by file widgets (shared/favourites/recent) so files
        // open inside TeamHub's iframe rather than a new browser tab. Cleared
        // when the user navigates away from the files view.
        filesEmbedFileUrl: null,
        currentUser: getCurrentUser(),
        messages: [],
        pinnedMessage: null,   // single pinned message for the current team, or null
        pinMinLevel: 4,        // minimum Circles level to pin (loaded from admin settings)
        messagesPage: 1,       // current page (1-based)
        messagesTotal: 0,      // total non-pinned message count from last fetch
        messagesLimit: 5,      // messages per page
        messageSettings: { pinMinLevel: 'moderator', postMinLevel: 'member' }, // per-team message settings
        comments: {},          // { messageId: [comments] }
        members: [],
        allEffectiveMembers: [],   // flat [{userId, displayName, email?, phone?, ncStatus?}] of ALL members including indirect (via groups/teams) — used by the MembersWidget and for @mention autocomplete
        allEffectiveMembersTalkAvailable: false, // per-request fact: is Talk (spreed) enabled for the current user — drives whether the chat icon shows in the members widget rows
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
        commentsForMessage: state => id => state.comments[id] || [],

        /**
         * True if the current user's level meets the per-team pinMinLevel threshold.
         * Reads from messageSettings (loaded per team) and falls back to pinMinLevel.
         */
        canPin: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            if (!member) return false
            const levelMap = { member: 1, moderator: 4, admin: 8 }
            const required = levelMap[state.messageSettings?.pinMinLevel] ?? state.pinMinLevel
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
         * True if the current user is a team admin (Circles level >= 8).
         */
        currentUserIsTeamAdmin: state => (state.currentUserLevel || 0) >= 8,
    },

    mutations: {
        SET_TEAMS(state, teams) { state.teams = teams },

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
            state.currentView = view
        },
        SET_FILES_EMBED_FILE_URL(state, url) { state.filesEmbedFileUrl = url },
        SET_MESSAGES(state, messages) { state.messages = messages },
        SET_PINNED_MESSAGE(state, message) { state.pinnedMessage = message },
        SET_PIN_MIN_LEVEL(state, level) { state.pinMinLevel = level },
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
        SET_MEMBERS(state, members) { state.members = members },
        SET_ALL_EFFECTIVE_MEMBERS(state, members) { state.allEffectiveMembers = Array.isArray(members) ? members : [] },
        SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE(state, available) { state.allEffectiveMembersTalkAvailable = !!available },
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
        SET_BUDGET_CONFIG(state, config) { state.budgetConfig = config },
        SET_TIME_CONFIG(state, config) { state.timeConfig = config },
        SET_PROJECT(state, project) { state.project = project },
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
        SET_DECISIONS_PRESELECT_STATUS(state, val) { state.decisionsPreselectStatus = val },
        SET_LOADING(state, { key, value }) { state.loading[key] = value }, // Vue 3: direct assignment is reactive
        SET_ERROR(state, error) { state.error = error },
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
            commit('SET_FILES_EMBED_FILE_URL', fileUrl)
            commit('SET_VIEW', 'files')
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

        async fetchTeams({ commit }) {
            commit('SET_LOADING', { key: 'teams', value: true })
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/teams'))
                commit('SET_TEAMS', Array.isArray(data) ? data : [])
            } catch (e) {
                commit('SET_ERROR', 'Failed to load teams')
            } finally {
                commit('SET_LOADING', { key: 'teams', value: false })
            }
        },

        async selectTeam({ commit, dispatch }, teamId) {
            commit('SET_CURRENT_TEAM', teamId)
            commit('SET_VIEW', 'msgstream')
            commit('SET_MESSAGES', [])
            commit('SET_PINNED_MESSAGE', null)
            commit('SET_MESSAGES_PAGE', 1)
            commit('SET_MESSAGES_TOTAL', 0)
            commit('SET_MESSAGE_SETTINGS', { pinMinLevel: 'moderator', postMinLevel: 'member' })
            commit('SET_MEMBERS', [])
            commit('SET_ALL_EFFECTIVE_MEMBERS', [])
            commit('SET_RESOURCES', {})
            commit('SET_WEB_LINKS', [])
            commit('SET_TEAM_WIDGETS', [])
            commit('SET_TEAM_MENU_ITEMS', [])
            // Reset project fact so a previous team's stepper never flashes before
            // the layout bundle for the newly selected team arrives.
            commit('SET_PROJECT', { isProject: false, mode: null, phase: null, startDate: null, targetEnd: null })

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

        async fetchMessages({ commit, state }, { teamId, page } = {}) {
            // Allow callers to pass just teamId as a string (backwards compat)
            if (typeof teamId !== 'string') {
                teamId = teamId || state.currentTeamId
            }
            const targetPage = page || state.messagesPage || 1
            commit('SET_LOADING', { key: 'messages', value: true })
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages`),
                    { params: { page: targetPage, limit: state.messagesLimit } }
                )
                commit('SET_PINNED_MESSAGE', data.pinned || null)
                commit('SET_MESSAGES', Array.isArray(data.messages) ? data.messages : [])
                commit('SET_MESSAGES_TOTAL', data.total || 0)
                commit('SET_MESSAGES_PAGE', targetPage)
            } catch (e) {
                commit('SET_ERROR', 'Failed to load messages')
            } finally {
                commit('SET_LOADING', { key: 'messages', value: false })
            }
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

        async postMessage({ commit, state, dispatch }, { subject, message, priority, messageType, pollOptions, decision }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/messages`),
                { subject, message, priority, messageType, pollOptions, decision }
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
                // Backend returns { members: [...], talkAvailable: bool }.
                // Tolerate a bare array too for forward/backward compatibility.
                const members = Array.isArray(data)
                    ? data
                    : (Array.isArray(data?.members) ? data.members : [])
                commit('SET_ALL_EFFECTIVE_MEMBERS', members)
                commit('SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE',
                    !Array.isArray(data) && !!data?.talkAvailable)
            } catch (e) {
                commit('SET_ALL_EFFECTIVE_MEMBERS', [])
                commit('SET_ALL_EFFECTIVE_MEMBERS_TALK_AVAILABLE', false)
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
