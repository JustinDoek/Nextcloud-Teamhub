/**
 * Vuex module `workflows` (v4.10.15) — the viewer's workflow instances
 * for My Work's three workflow sections and the detail view.
 *
 * Held in the store for the same reason `state.myWork` is: opening a team
 * from a row unmounts the view, and coming back must render at once from
 * the last result while a refresh runs behind it.
 *
 * `createWorkflowsModule(api)` takes the API client so the tests can hand
 * in a fake; the default export is the module over the real one.
 *
 * Duplicate submission is prevented here, not per button: `busyId` names
 * the one workflow an action is in flight for, `act()` refuses a second
 * call while it is set, and every action button reads it.
 */
import { partition, rowKey } from '../constants/workflows.js'

/**
 * The real client, loaded on first use. A static import would pull
 * `@nextcloud/axios` (and through it `@nextcloud/auth`, which touches
 * `window` at import time) into every consumer of this module — including
 * the node test harness, which has no window. The bundle gets one small
 * extra chunk; nothing else changes.
 */
const lazyApi = {
	listWorkflows: (...a) => import('../api/workflows.js').then(m => m.listWorkflows(...a)),
	getWorkflow: (...a) => import('../api/workflows.js').then(m => m.getWorkflow(...a)),
	actOnWorkflow: (...a) => import('../api/workflows.js').then(m => m.actOnWorkflow(...a)),
	startWorkflow: (...a) => import('../api/workflows.js').then(m => m.startWorkflow(...a)),
}

function errorOf(e) {
	return {
		status: e?.response?.status || 0,
		message: e?.response?.data?.error || e?.message || '',
		conflict: !!e?.response?.data?.conflict,
		retryAfter: Number(e?.response?.data?.retryAfter) || 0,
	}
}

export function createWorkflowsModule(api = lazyApi) {
	return {
		namespaced: true,

		state: () => ({
			/** Every workflow the viewer takes part in, as the server listed them. */
			items: [],
			loading: false,
			/** True once a list has been received at least once (empty counts). */
			loaded: false,
			loadedAt: 0,
			/** `{ status, message }` of the last failed list load, or null. */
			error: null,
			/** The workflow an action is in flight for; refuses a second one. */
			busyId: null,
			/**
			 * The instance's licence tier (v4.10.16) — `full` or `basic`,
			 * as the last list said. Empty until the first answer.
			 */
			tier: '',
			/** capability → allowed, from the same answer. */
			capabilities: {},
			/** The workflow open in the detail view (with `history`), or null. */
			detail: null,
			detailLoading: false,
			detailError: null,
		}),

		getters: {
			/** `{ actionRequired, waiting, completed }` — the three sections (v4.10.17). */
			sections: state => partition(state.items),
			actionRequiredCount: (state, getters) => getters.sections.actionRequired.length,
			byId: state => id => state.items.find(w => w.id === id) || null,
			/** True while the instance is licensed; false on an unlicensed one. */
			licensed: state => state.tier === 'full',
			/** Whether the instance has one capability, e.g. `completed_history`. */
			can: state => capability => !!state.capabilities[capability],
		},

		mutations: {
			SET_LOADING(state, loading) { state.loading = !!loading },
			SET_ITEMS(state, payload) {
				const { workflows, tier, capabilities } = payload || {}
				state.items = Array.isArray(workflows) ? workflows : []
				if (tier) {
					state.tier = tier
					state.capabilities = capabilities || {}
				}
				state.loaded = true
				state.loadedAt = Date.now()
				state.error = null
			},
			SET_ERROR(state, error) { state.error = error },
			SET_BUSY(state, id) { state.busyId = id },
			/**
			 * Replace one workflow with the server's answer.
			 *
			 * An **ended** workflow is kept (v4.10.17): it leaves the two
			 * open sections and appears under Completed, which is where
			 * somebody looks for the request they just finished. Before
			 * this the row was dropped, so a completed workflow vanished
			 * on a licensed instance even though the server still had it
			 * and `completed_history` said so.
			 *
			 * A **purged** workflow is dropped, because it no longer
			 * exists: on an unlicensed instance the server deleted it in
			 * the same transaction that ended it (v4.10.16), and nothing
			 * ever answers for it again. `purged` is the server saying so;
			 * the client does not infer it from the tier, so a tier that
			 * changed mid-session cannot strand a row either way.
			 */
			UPSERT(state, workflow) {
				if (!workflow || typeof workflow.id !== 'number') {
					return
				}
				// v4.10.37 — a row is a workflow *and a task*: one request may
				// be listed once per open task of the viewer's.
				const i = state.items.findIndex(w => rowKey(w) === rowKey(workflow))
				if (workflow.purged) {
					if (i >= 0) {
						state.items.splice(i, 1)
					}
				} else if (i >= 0) {
					state.items.splice(i, 1, workflow)
				} else {
					state.items.unshift(workflow)
				}
				if (state.detail && state.detail.id === workflow.id) {
					// Keep the history the detail already has; the caller
					// refetches when it wants the new events.
					state.detail = { ...workflow, history: workflow.history || state.detail.history || [] }
				}
			},
			SET_DETAIL(state, workflow) { state.detail = workflow },
			SET_DETAIL_LOADING(state, loading) { state.detailLoading = !!loading },
			SET_DETAIL_ERROR(state, error) { state.detailError = error },
		},

		actions: {
			/**
			 * Start a built-in workflow on a team. Resolves with the new
			 * workflow (already in the list); rejects with
			 * `{ status, message }`. Shares `busyId` (as 0) so nothing else
			 * fires meanwhile.
			 */
			async start({ state, commit }, { teamId, definitionKey, data }) {
				if (state.busyId !== null) {
					throw { status: 0, message: 'busy', conflict: false, retryAfter: 0 }
				}
				commit('SET_BUSY', 0)
				try {
					const workflow = await api.startWorkflow(teamId, definitionKey, data)
					if (workflow) {
						commit('UPSERT', workflow)
					}
					return workflow
				} catch (e) {
					throw errorOf(e)
				} finally {
					commit('SET_BUSY', null)
				}
			},

			/**
			 * Re-read the list without the loading state (v4.10.37): after an
			 * action, the rows are replaced in place. Never throws.
			 */
			async refresh({ commit }) {
				try {
					commit('SET_ITEMS', await api.listWorkflows())
				} catch (e) {
					// The list on screen stays; the next load says what failed.
				}
			},

			/** Fetch the list. Never throws; the error lands in state. */
			async load({ commit }) {
				commit('SET_LOADING', true)
				try {
					commit('SET_ITEMS', await api.listWorkflows())
				} catch (e) {
					commit('SET_ERROR', errorOf(e))
				} finally {
					commit('SET_LOADING', false)
				}
			},

			/**
			 * Open one workflow with its history in the detail view. Takes an
			 * id, or `{ id, step }` (v4.10.37) to open it on one task.
			 */
			async open({ commit }, target) {
				const id = typeof target === 'object' && target !== null ? target.id : target
				const step = typeof target === 'object' && target !== null ? (target.step || '') : ''
				commit('SET_DETAIL_ERROR', null)
				commit('SET_DETAIL_LOADING', true)
				try {
					const workflow = await api.getWorkflow(id, step)
					commit('SET_DETAIL', workflow)
					// The list row is refreshed from the same answer.
					if (workflow) {
						commit('UPSERT', workflow)
					}
				} catch (e) {
					commit('SET_DETAIL_ERROR', errorOf(e))
				} finally {
					commit('SET_DETAIL_LOADING', false)
				}
			},

			close({ commit }) {
				commit('SET_DETAIL', null)
				commit('SET_DETAIL_ERROR', null)
			},

			/**
			 * Perform one action. Resolves with the updated workflow; rejects
			 * with `{ status, message, conflict, retryAfter }`. A second call
			 * while one is in flight is refused with status 0.
			 */
			async act({ state, commit }, { id, action, text = '', step = '', fileIds = [] }) {
				if (state.busyId !== null) {
					throw { status: 0, message: 'busy', conflict: false, retryAfter: 0 }
				}
				commit('SET_BUSY', id)
				try {
					const workflow = await api.actOnWorkflow(id, action, text, step, fileIds)
					if (workflow) {
						commit('UPSERT', workflow)
					}
					return workflow
				} catch (e) {
					const err = errorOf(e)
					if (err.status === 404 || err.status === 409) {
						// Stale view: the server's state wins; re-read.
						try {
							const fresh = await api.getWorkflow(id)
							if (fresh) {
								commit('UPSERT', fresh)
							}
						} catch (e2) {
							if (e2?.response?.status === 404 || e2?.response?.status === 403) {
								commit('UPSERT', { id, status: 'gone' })
							}
						}
					}
					throw err
				} finally {
					commit('SET_BUSY', null)
				}
			},
		},
	}
}

export default createWorkflowsModule()
