/**
 * Vuex module `serviceTeams` (v4.10.20) — the desks the viewer works and
 * the queue of whichever one is open.
 *
 * Shaped after `store/workflows.js`, for the same reasons: it is held in
 * the store so leaving the queue and coming back renders at once from the
 * last result while a refresh runs behind it, and `createServiceTeamsModule(api)`
 * takes the API client so the tests can hand in a fake.
 *
 * **An unlicensed instance is not an error state.** Every route is 403
 * `licenseGate` there, and `available: false` is what this module records —
 * the whole surface is then hidden rather than shown broken. The one thing
 * it does not do is retry.
 *
 * Duplicate submission is prevented here, not per button: `busyId` names
 * the one request an action is in flight for.
 */
import { QUEUE_BUCKET, bucketOf } from '../constants/serviceTeams.js'
import { rowKey } from '../constants/workflows.js'

/**
 * The real client, loaded on first use — the same lazy shape
 * `store/workflows.js` uses, and for the same reason: a static import
 * would pull `@nextcloud/axios` into the node test harness, which has no
 * window.
 */
const lazyApi = {
	listServiceTeams: (...a) => import('../api/serviceTeams.js').then(m => m.listServiceTeams(...a)),
	loadQueue: (...a) => import('../api/serviceTeams.js').then(m => m.loadQueue(...a)),
	loadCatalogue: (...a) => import('../api/serviceTeams.js').then(m => m.loadCatalogue(...a)),
	claimRequest: (...a) => import('../api/serviceTeams.js').then(m => m.claimRequest(...a)),
	assignRequest: (...a) => import('../api/serviceTeams.js').then(m => m.assignRequest(...a)),
	releaseRequest: (...a) => import('../api/serviceTeams.js').then(m => m.releaseRequest(...a)),
	addInternalNote: (...a) => import('../api/serviceTeams.js').then(m => m.addInternalNote(...a)),
	loadStatistics: (...a) => import('../api/serviceTeams.js').then(m => m.loadStatistics(...a)),
	// v4.10.34 — the service builder.
	loadBuiltServices: (...a) => import('../api/serviceTeams.js').then(m => m.loadBuiltServices(...a)),
	createBuiltService: (...a) => import('../api/serviceTeams.js').then(m => m.createBuiltService(...a)),
	saveBuiltService: (...a) => import('../api/serviceTeams.js').then(m => m.saveBuiltService(...a)),
	publishBuiltService: (...a) => import('../api/serviceTeams.js').then(m => m.publishBuiltService(...a)),
	unpublishBuiltService: (...a) => import('../api/serviceTeams.js').then(m => m.unpublishBuiltService(...a)),
	deleteBuiltService: (...a) => import('../api/serviceTeams.js').then(m => m.deleteBuiltService(...a)),
}

function errorOf(e) {
	return {
		status: e?.response?.status || 0,
		message: e?.response?.data?.error || e?.message || '',
		conflict: !!e?.response?.data?.conflict,
		licenseGate: !!e?.response?.data?.licenseGate,
	}
}

// v4.10.27 — `closed` is the Closed tab of the queue widget: what the desk
// finished in the last 30 days. It is a read, never a place an action files a
// row into; after an action that ends the desk's part the queue is re-read.
// v4.10.35 — `withOthers`: the desk's open requests that are back with the
// requester; the widget's *With requester* tab.
const emptyQueue = () => ({ unclaimed: [], mine: [], others: [], closed: [], withOthers: [] })

export function createServiceTeamsModule(api = lazyApi) {
	return {
		namespaced: true,

		state: () => ({
			/** The desks this viewer may work, each with its catalogue. */
			teams: [],
			/** Whether the instance has Service Teams at all (the licence). */
			available: false,
			/** Which desk's queue is on screen; '' before one is chosen. */
			selectedId: '',
			/** The selected desk's queue, in the server's three buckets. */
			queue: emptyQueue(),
			/** Every service any active desk offers, for the request picker. */
			catalogue: [],
			/** v4.10.45 — `[{ key, label, icon }]`, in the administrator's order. */
			catalogueCategories: [],
			/** v4.10.45 — the links under the catalog. */
			catalogueLinks: { serviceDesk: '', knowledgePortal: '' },
			loading: false,
			loaded: false,
			/** The one request an action is in flight for; null when idle. */
			busyId: null,
			error: null,
		}),

		getters: {
			/** Is this viewer on any desk at all? The whole nav gate. */
			isAgent: state => state.available && state.teams.length > 0,

			/**
			 * Is there anything to request? The Services nav gate (v4.10.25).
			 *
			 * Read from the catalogue itself rather than from a licence flag:
			 * an unlicensed instance answers 403 and an instance with no desk
			 * answers an empty list, and in both cases the honest answer is
			 * that nothing can be started. One condition, no second rule to
			 * keep in step with the server's.
			 */
			hasCatalogue: state => state.catalogue.length > 0,

			selected: state => state.teams.find(tm => tm.teamId === state.selectedId) || state.teams[0] || null,

			/** How many requests nobody has taken, in the queue on screen. */
			unclaimedCount: state => state.queue.unclaimed.length,

			/**
			 * v4.10.27 — the badge behind a service team's name in the
			 * navigation, like unread messages. 0 for a team that is not a desk
			 * this viewer works, so the caller never has to ask which it is.
			 */
			unclaimedFor: state => teamId => Number(state.teams.find(tm => tm.teamId === teamId)?.unclaimedCount) || 0,

			/** Everything in the queue as one list, for a lookup by id. */
			allRequests: state => [
				...state.queue.unclaimed,
				...state.queue.mine,
				...state.queue.others,
				...(state.queue.withOthers || []),
			],

			byId: (state, getters) => id => getters.allRequests.find(w => w.id === id) || null,
		},

		mutations: {
			SET_TEAMS(state, { teams, available }) {
				state.teams = Array.isArray(teams) ? teams : []
				state.available = !!available
				if (!state.teams.some(tm => tm.teamId === state.selectedId)) {
					state.selectedId = state.teams[0]?.teamId || ''
				}
			},
			SET_SELECTED(state, teamId) {
				state.selectedId = teamId || ''
			},
			SET_QUEUE(state, queue) {
				state.queue = {
					unclaimed: queue?.unclaimed || [],
					mine: queue?.mine || [],
					others: queue?.others || [],
					closed: queue?.closed || [],
					// v4.10.35 — back with the requester (the server's `withOthers`).
					withOthers: queue?.withOthers || [],
				}
				// Keep the navigation badge in step with the queue just read:
				// the widget is where a desk works, and a claim made there
				// should not leave a stale number beside the team's name.
				const desk = state.teams.find(tm => tm.teamId === (queue?.serviceTeamId || state.selectedId))
				if (desk) {
					desk.unclaimedCount = state.queue.unclaimed.length
				}
			},
			SET_CATALOGUE(state, catalogue) {
				state.catalogue = Array.isArray(catalogue) ? catalogue : []
			},
			SET_CATALOGUE_EXTRAS(state, { categories = [], links = {} } = {}) {
				state.catalogueCategories = Array.isArray(categories) ? categories : []
				state.catalogueLinks = {
					serviceDesk: String(links.serviceDesk || ''),
					knowledgePortal: String(links.knowledgePortal || ''),
				}
			},
			SET_LOADING(state, on) {
				state.loading = !!on
			},
			SET_LOADED(state, on) {
				state.loaded = !!on
			},
			SET_BUSY(state, id) {
				state.busyId = id
			},
			SET_ERROR(state, error) {
				state.error = error
			},
			/**
			 * Put one request back where it now belongs.
			 *
			 * An action can move a row between buckets — claiming takes it
			 * out of *unclaimed* and into *mine* — so the row is removed from
			 * all three and re-filed, rather than updated in place. A request
			 * that has ended leaves the queue altogether: there is nothing
			 * left to work.
			 */
			UPSERT(state, { workflow, uid }) {
				const id = workflow?.id
				if (!id) {
					return
				}
				const open = !!workflow.internal
					&& !['completed', 'rejected', 'cancelled'].includes(workflow.status)
				// v4.10.37 — a row is one task of a request. The answer is for
				// the task acted on, so only that row is re-filed; a request
				// that ended takes all its rows with it.
				const key = rowKey(workflow)
				for (const bucket of Object.keys(state.queue)) {
					state.queue[bucket] = state.queue[bucket].filter(w => (open ? rowKey(w) !== key : w.id !== id))
				}
				if (open) {
					state.queue[bucketOf(workflow, uid)].unshift(workflow)
				}
				const desk = state.teams.find(tm => tm.teamId === state.selectedId)
				if (desk) {
					desk.unclaimedCount = state.queue.unclaimed.length
				}
			},
		},

		actions: {
			/** The desks, then the selected desk's queue. One entry point for the view. */
			async load({ commit, dispatch, state }) {
				commit('SET_LOADING', true)
				commit('SET_ERROR', null)
				try {
					const { serviceTeams, available } = await api.listServiceTeams()
					commit('SET_TEAMS', { teams: serviceTeams, available })
					commit('SET_LOADED', true)
					if (state.selectedId) {
						await dispatch('loadQueue', state.selectedId)
					} else {
						commit('SET_QUEUE', emptyQueue())
					}
				} catch (e) {
					const error = errorOf(e)
					// An unlicensed instance has no service teams; that is an
					// answer, not a failure, and the surface hides itself.
					if (error.licenseGate) {
						commit('SET_TEAMS', { teams: [], available: false })
						commit('SET_LOADED', true)
					} else {
						commit('SET_ERROR', error)
					}
				} finally {
					commit('SET_LOADING', false)
				}
			},

			/**
			 * v4.10.27 — the desks alone, without anybody's queue: what the
			 * navigation needs for its unclaimed badges on every page load.
			 * Silent on every failure — no desk is the normal answer for
			 * almost everybody, and a badge has no room for an error.
			 */
			async loadDesks({ commit }) {
				try {
					const { serviceTeams, available } = await api.listServiceTeams()
					commit('SET_TEAMS', { teams: serviceTeams, available })
				} catch (e) {
					commit('SET_TEAMS', { teams: [], available: false })
				}
			},

			/**
			 * v4.10.27 — one desk's statistics for the widget. Not held in
			 * state: only the statistics widget reads them, and it reads them
			 * for the period it shows. Throws the normalised error.
			 */
			async statistics(_, { teamId, days }) {
				try {
					return await api.loadStatistics(teamId, days)
				} catch (e) {
					throw errorOf(e)
				}
			},

			async select({ commit, dispatch }, teamId) {
				commit('SET_SELECTED', teamId)
				await dispatch('loadQueue', teamId)
			},

			async loadQueue({ commit }, teamId) {
				if (!teamId) {
					commit('SET_QUEUE', emptyQueue())
					return
				}
				commit('SET_LOADING', true)
				try {
					const { queue } = await api.loadQueue(teamId)
					commit('SET_QUEUE', queue)
				} catch (e) {
					const error = errorOf(e)
					commit('SET_QUEUE', emptyQueue())
					if (!error.licenseGate) {
						commit('SET_ERROR', error)
					}
				} finally {
					commit('SET_LOADING', false)
				}
			},

			/**
			 * v4.10.34 — the service builder. Not held in state, like the
			 * statistics: only the Services widget reads the team's own list.
			 * Each action throws the normalised error; the ones that change
			 * what the Services page lists re-read the catalogue after, so
			 * a published service is requestable without a reload.
			 */
			async builtServices(_, teamId) {
				try {
					return await api.loadBuiltServices(teamId)
				} catch (e) {
					throw errorOf(e)
				}
			},

			async createBuiltService(_, { teamId, service }) {
				try {
					return await api.createBuiltService(teamId, service)
				} catch (e) {
					throw errorOf(e)
				}
			},

			async saveBuiltService(_, { teamId, id, service }) {
				try {
					return await api.saveBuiltService(teamId, id, service)
				} catch (e) {
					throw errorOf(e)
				}
			},

			async publishBuiltService({ dispatch }, { teamId, id }) {
				let saved
				try {
					saved = await api.publishBuiltService(teamId, id)
				} catch (e) {
					throw errorOf(e)
				}
				dispatch('loadCatalogue')
				return saved
			},

			async unpublishBuiltService({ dispatch }, { teamId, id }) {
				let saved
				try {
					saved = await api.unpublishBuiltService(teamId, id)
				} catch (e) {
					throw errorOf(e)
				}
				dispatch('loadCatalogue')
				return saved
			},

			async deleteBuiltService(_, { teamId, id }) {
				try {
					await api.deleteBuiltService(teamId, id)
				} catch (e) {
					throw errorOf(e)
				}
			},

			async loadCatalogue({ commit }) {
				try {
					const { catalogue, categories, links } = await api.loadCatalogue()
					commit('SET_CATALOGUE', catalogue)
					commit('SET_CATALOGUE_EXTRAS', { categories, links })
				} catch (e) {
					// The picker simply offers nothing; it is not a page of
					// its own and has no room for an error of its own.
					commit('SET_CATALOGUE', [])
				}
			},

			/**
			 * One agent action on one request. Refuses a second call while
			 * one is in flight, and re-reads the queue on a conflict — a 409
			 * means somebody else got there first, and the honest answer is
			 * the current state, not a retry.
			 *
			 * @return {Promise<{ok: boolean, error: (object|null)}>}
			 */
			async act({ commit, state, dispatch }, { id, action, uid, text = '', step = '', fileIds = [] }) {
				if (state.busyId !== null) {
					return { ok: false, error: { message: '', busy: true } }
				}
				commit('SET_BUSY', id)
				commit('SET_ERROR', null)
				try {
					let workflow = null
					if (action === 'claim') {
						workflow = await api.claimRequest(id, step)
					} else if (action === 'assign') {
						workflow = await api.assignRequest(id, text, step)
					} else if (action === 'release') {
						workflow = await api.releaseRequest(id, text, step)
					} else if (action === 'internal_note') {
						workflow = await api.addInternalNote(id, text, step, fileIds)
					} else {
						throw new Error('Unknown agent action: ' + action)
					}
					commit('UPSERT', { workflow, uid })
					return { ok: true, error: null }
				} catch (e) {
					const error = errorOf(e)
					if (error.conflict) {
						await dispatch('loadQueue', state.selectedId)
					}
					commit('SET_ERROR', error)
					return { ok: false, error }
				} finally {
					commit('SET_BUSY', null)
				}
			},
		},
	}
}

export default createServiceTeamsModule()
export { QUEUE_BUCKET }
