/**
 * WorkflowHub API client (v4.10.15) — `APIendpoints.md` § WorkflowHub.
 *
 * Every call is made as the session user; nothing here names an actor or
 * a step. The `note`/`reason` strings are the only payload the action
 * routes take.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path = '') => generateUrl('/apps/teamhub/api/v1/workflows' + path)

/**
 * The viewer's workflows, with the instance's licence tier and its
 * capability map (v4.10.16). The tier decides what the server put in each
 * workflow — an unlicensed instance sends no step list — and what the
 * client may offer; both travel with the list so one request answers both.
 *
 * @return {Promise<{workflows: object[], tier: string, capabilities: object}>}
 */
export async function listWorkflows(status = '') {
	const { data } = await axios.get(base(), { params: status ? { status } : {} })
	return {
		workflows: Array.isArray(data?.workflows) ? data.workflows : [],
		tier: data?.tier || '',
		capabilities: data?.capabilities || {},
	}
}

/** v4.10.37 — `step` is the task the caller opened it from; the view answers for it. */
export async function getWorkflow(id, step = '') {
	const { data } = await axios.get(base('/' + encodeURIComponent(id)), { params: step ? { step } : {} })
	return data?.workflow || null
}

/** The definitions this instance may start — an unlicensed one lists fewer. */
export async function listDefinitions() {
	const { data } = await axios.get(base('/definitions'))
	return Array.isArray(data?.definitions) ? data.definitions : []
}

export async function startWorkflow(teamId, definitionKey, payload) {
	const { data } = await axios.post(
		generateUrl('/apps/teamhub/api/v1/teams/' + encodeURIComponent(teamId) + '/workflows/' + encodeURIComponent(definitionKey)),
		{ data: payload },
	)
	return data?.workflow || null
}

/**
 * One POST per verb, as the routes are. `text` is the note or reason;
 * `step` (v4.10.37) the task it acts on — without it the server picks the
 * caller's own.
 */
export async function actOnWorkflow(id, action, text = '', step = '', fileIds = []) {
	const path = {
		complete: '/complete',
		reject: '/reject',
		request_information: '/request-information',
		provide_information: '/provide-information',
		cancel: '/cancel',
		request_status: '/status-request',
		// v4.10.31 — ServiceTeamController::close(), an admin of the service team.
		close: '/close-request',
		// v4.11.0 — a message on a service request; changes no status.
		message: '/message',
	}[action]
	if (!path) {
		throw new Error('Unknown workflow action: ' + action)
	}
	const body = ['reject', 'cancel'].includes(action) ? { reason: text } : { note: text }
	if (step) {
		body.step = step
	}
	// v4.10.38 — the paperclip: files of the caller's to share with the other side.
	if (fileIds && fileIds.length) {
		body.fileIds = fileIds
	}
	const { data } = await axios.post(base('/' + encodeURIComponent(id) + path), body)
	return data?.workflow || null
}
