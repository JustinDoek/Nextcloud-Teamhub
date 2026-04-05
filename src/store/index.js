import Vue from 'vue'
import Vuex from 'vuex'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'

Vue.use(Vuex)

export default new Vuex.Store({
    state: {
        teams: [],
        currentTeamId: null,
        currentView: 'msgstream',
        currentUser: getCurrentUser(),
        messages: [],
        pinnedMessage: null,   // single pinned message for the current team, or null
        pinMinLevel: 4,        // minimum Circles level to pin (loaded from admin settings)
        comments: {},          // { messageId: [comments] }
        members: [],
        resources: {},         // { talk, files, calendar, deck }
        webLinks: [],
        deckTasks: [],
        teamWidgets: [],        // enabled sidebar widgets for the current team
        teamMenuItems: [],      // enabled menu_item integrations for the current team
        intravoxAvailable: false,
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
         * True if the current user's level meets the pinMinLevel threshold.
         * members array rows have { userId, level } where level is the Circles integer.
         */
        canPin: state => {
            const uid = state.currentUser?.uid
            if (!uid) return false
            const member = state.members.find(m => m.userId === uid)
            if (!member) return false
            return (member.level || 0) >= state.pinMinLevel
        },
    },

    mutations: {
        SET_TEAMS(state, teams) { state.teams = teams },
        SET_CURRENT_TEAM(state, id) { state.currentTeamId = id },
        SET_VIEW(state, view) { state.currentView = view },
        SET_MESSAGES(state, messages) { state.messages = messages },
        SET_PINNED_MESSAGE(state, message) { state.pinnedMessage = message },
        SET_PIN_MIN_LEVEL(state, level) { state.pinMinLevel = level },
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
            if (idx !== -1) Vue.set(state.messages, idx, { ...state.messages[idx], ...message })
            // Also sync the pinned slot if it's the same message
            if (state.pinnedMessage && state.pinnedMessage.id === message.id) {
                state.pinnedMessage = { ...state.pinnedMessage, ...message }
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
            if (team) Vue.set(team, 'unread', false)
        },
        UPDATE_COMMENT(state, { messageId, comment }) {
            const list = state.comments[messageId]
            if (!list) return
            const idx = list.findIndex(c => c.id === comment.id)
            if (idx !== -1) Vue.set(list, idx, { ...list[idx], ...comment })
        },
        SET_COMMENTS(state, { messageId, comments }) {
            Vue.set(state.comments, messageId, comments)
        },
        ADD_COMMENT(state, { messageId, comment }) {
            if (!state.comments[messageId]) Vue.set(state.comments, messageId, [])
            state.comments[messageId].push(comment)
        },
        SET_MEMBERS(state, members) { state.members = members },
        SET_RESOURCES(state, resources) { state.resources = resources },
        SET_WEB_LINKS(state, links) { state.webLinks = links },
        SET_DECK_TASKS(state, tasks) { state.deckTasks = tasks },
        SET_TEAM_WIDGETS(state, widgets) { state.teamWidgets = widgets },
        SET_TEAM_MENU_ITEMS(state, items) { state.teamMenuItems = items },
        SET_LOADING(state, { key, value }) { Vue.set(state.loading, key, value) },
        SET_ERROR(state, error) { state.error = error },
        SET_INTRAVOX_AVAILABLE(state, value) { state.intravoxAvailable = value },
    },

    actions: {
        async checkIntravox({ commit }) {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/apps/check'))
                commit('SET_INTRAVOX_AVAILABLE', !!data.intravox)
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
            commit('SET_MEMBERS', [])
            commit('SET_RESOURCES', {})
            commit('SET_WEB_LINKS', [])
            commit('SET_TEAM_WIDGETS', [])
            commit('SET_TEAM_MENU_ITEMS', [])

            // Mark seen immediately (optimistic) + fire-and-forget to backend
            commit('MARK_TEAM_SEEN', teamId)
            dispatch('markTeamSeen', teamId)

            await Promise.all([
                dispatch('fetchMessages', teamId),
                dispatch('fetchMembers', teamId),
                dispatch('fetchResources', teamId),
                dispatch('fetchWebLinks', teamId),
                dispatch('fetchTeamIntegrations', teamId),
            ])
        },

        async fetchMessages({ commit }, teamId) {
            commit('SET_LOADING', { key: 'messages', value: true })
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages`))
                // Backend now returns { pinned: object|null, messages: array }
                commit('SET_PINNED_MESSAGE', data.pinned || null)
                commit('SET_MESSAGES', Array.isArray(data.messages) ? data.messages : [])
            } catch (e) {
                commit('SET_ERROR', 'Failed to load messages')
            } finally {
                commit('SET_LOADING', { key: 'messages', value: false })
            }
        },

        async postMessage({ commit, state }, { subject, message, priority, messageType, pollOptions }) {
            const { data } = await axios.post(
                generateUrl(`/apps/teamhub/api/v1/teams/${state.currentTeamId}/messages`),
                { subject, message, priority, messageType, pollOptions }
            )
            commit('ADD_MESSAGE', data)
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

        async updateComment({ commit }, { messageId, commentId, comment }) {
            const { data } = await axios.put(
                generateUrl(`/apps/teamhub/api/v1/comments/${commentId}`),
                { comment }
            )
            commit('UPDATE_COMMENT', { messageId, comment: data })
            return data
        },

        async fetchMembers({ commit }, teamId) {
            commit('SET_LOADING', { key: 'members', value: true })
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members`))
                commit('SET_MEMBERS', Array.isArray(data) ? data : [])
            } catch (e) {
                commit('SET_MEMBERS', [])
            } finally {
                commit('SET_LOADING', { key: 'members', value: false })
            }
        },

        async fetchResources({ commit, dispatch }, teamId) {
            commit('SET_LOADING', { key: 'resources', value: true })
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/resources`))
                commit('SET_RESOURCES', data || {})
                if (data?.deck?.board_id) {
                    dispatch('fetchDeckTasks', data.deck.board_id)
                }
            } catch (e) {
                commit('SET_RESOURCES', {})
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

        async fetchDeckTasks({ commit }, boardId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks`),
                    { headers: { 'OCS-APIRequest': 'true' } }
                )
                const now = new Date()
                const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
                const cutoff = new Date(todayStart)
                cutoff.setDate(cutoff.getDate() + 14)
                const cards = []
                ;(Array.isArray(data) ? data : []).forEach(stack => {
                    ;(stack.cards || []).forEach(card => {
                        if (!card.archived && !card.done && card.duedate) {
                            const due = new Date(card.duedate)
                            if (due >= todayStart && due <= cutoff) {
                                cards.push({
                                    id: card.id,
                                    title: card.title,
                                    duedate: card.duedate,
                                    assignedUsers: card.assignedUsers || [],
                                    boardId,
                                    overdue: due < now,
                                })
                            }
                        }
                    })
                })
                cards.sort((a, b) => new Date(a.duedate) - new Date(b.duedate))
                commit('SET_DECK_TASKS', cards.slice(0, 5))
            } catch (e) {
                commit('SET_DECK_TASKS', [])
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
