/**
 * Service Team API client (v4.10.20) — `APIendpoints.md` § Service Teams.
 *
 * Two halves, as the routes are: the *listings* hang off `/service-teams`
 * and answer "which desks may I work, and what do they offer"; the *queue
 * verbs* hang off `/workflows/{id}` because that is what they act on — one
 * step of one workflow. Nothing here names an agent except the target of
 * an assignment, which the server re-checks for eligibility before writing.
 *
 * Every route is licensed: an unlicensed instance answers 403 with
 * `licenseGate: true`, which the store turns into "no service teams" rather
 * than an error message — the surface is hidden, not broken.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path = '') => generateUrl('/apps/teamhub/api/v1/service-teams' + path)
const workflow = (id, path) => generateUrl('/apps/teamhub/api/v1/workflows/' + encodeURIComponent(id) + path)
const teamServices = (teamId, path = '') =>
	generateUrl('/apps/teamhub/api/v1/teams/' + encodeURIComponent(teamId) + '/services' + path)
const adminBase = (path = '') => generateUrl('/apps/teamhub/api/v1/admin/service-teams' + path)

/**
 * The service teams the viewer may work the queue of, each with its
 * catalogue. Empty for everybody who is not an agent — which is most
 * people, and is not an error.
 *
 * @return {Promise<{serviceTeams: object[], available: boolean, tier: string}>}
 */
export async function listServiceTeams() {
	const { data } = await axios.get(base())
	return {
		serviceTeams: Array.isArray(data?.serviceTeams) ? data.serviceTeams : [],
		available: !!data?.available,
		tier: data?.tier || '',
	}
}

/**
 * One service team's queue: `unclaimed`, `mine`, `others`, and (v4.10.27)
 * `closed` — what the desk finished in the last 30 days.
 */
export async function loadQueue(teamId) {
	const { data } = await axios.get(base('/' + encodeURIComponent(teamId) + '/queue'))
	return {
		queue: data?.queue || { unclaimed: [], mine: [], others: [], closed: [] },
		capabilities: data?.capabilities || {},
	}
}

/**
 * v4.10.27 — one service team's statistics for a period of 7, 30 or 90 days:
 * `{ days, since, totals, services }`.
 */
export async function loadStatistics(teamId, days) {
	const { data } = await axios.get(base('/' + encodeURIComponent(teamId) + '/statistics'), { params: { days } })
	return data?.statistics || null
}

/**
 * Every service any active desk offers, for the request picker. Flat: a
 * requester picks a service, not a desk.
 */
export async function loadCatalogue() {
	const { data } = await axios.get(base('/catalogue'))
	// v4.10.45 — with the administrator's categories (in their order) and
	// the two links under the catalog.
	return {
		catalogue: Array.isArray(data?.catalogue) ? data.catalogue : [],
		categories: Array.isArray(data?.categories) ? data.categories : [],
		links: {
			serviceDesk: String(data?.links?.serviceDesk || ''),
			knowledgePortal: String(data?.links?.knowledgePortal || ''),
		},
	}
}

/**
 * v4.10.45 — the teams the viewer may ask more time for: the ones they
 * administer that have an expiration date, each with `expiresOn` and any
 * `openRequest`. The card's team picker.
 *
 * @return {Promise<Array<{teamId: string, teamName: string, expiresOn: string, openRequest: object|null}>>}
 */
export async function loadExpiryTeams() {
	const { data } = await axios.get(base('/expiry-teams'))
	return Array.isArray(data?.teams) ? data.teams : []
}

/**
 * v4.10.29 — the teams the viewer may ask more storage for: the ones they
 * administer that have a team space, each with its current `quota` and any
 * `openRequest`. The quota card's team picker.
 *
 * @return {Promise<Array<{teamId: string, teamName: string, quota: number, openRequest: object|null}>>}
 */
export async function loadQuotaTeams() {
	const { data } = await axios.get(base('/quota-teams'))
	return Array.isArray(data?.teams) ? data.teams : []
}

/**
 * Take an unclaimed request. 409 when somebody else already has it.
 * v4.10.37 — `step` names the task, when the request's step holds several.
 */
export async function claimRequest(id, step = '') {
	const { data } = await axios.post(workflow(id, '/claim'), step ? { step } : {})
	return data?.workflow || null
}

/** Hand a request to an eligible agent — or to yourself, to take it over. */
export async function assignRequest(id, uid, step = '') {
	const { data } = await axios.post(workflow(id, '/assign'), step ? { uid, step } : { uid })
	return data?.workflow || null
}

/** Put a claimed request back in the queue. */
export async function releaseRequest(id, reason = '', step = '') {
	const body = reason ? { reason } : {}
	if (step) {
		body.step = step
	}
	const { data } = await axios.post(workflow(id, '/release'), body)
	return data?.workflow || null
}

/** A note the desk keeps to itself; never returned to the requester. */
export async function addInternalNote(id, note, step = '', fileIds = []) {
	const body = { note }
	if (step) {
		body.step = step
	}
	// v4.10.38 — files an internal note shares with the team.
	if (fileIds && fileIds.length) {
		body.fileIds = fileIds
	}
	const { data } = await axios.post(workflow(id, '/internal-note'), body)
	return data?.workflow || null
}

/**
 * Whether the Nextcloud services exist on this instance and whether a team
 * already holds them — what the creation wizard reads before the team it
 * would belong to exists. Readable by anybody who may create a team.
 *
 * @return {Promise<{available: boolean, claimed: boolean, holderName: string, availableServices: object[]}>}
 */
export async function loadClaimStatus() {
	const { data } = await axios.get(base('/claim-status'))
	return {
		available: !!data?.available,
		claimed: !!data?.claimed,
		holderName: data?.holderName || '',
		availableServices: Array.isArray(data?.availableServices) ? data.availableServices : [],
	}
}

// ── Manage team → Services (v4.10.23) ──────────────────────────────────
//
// The team admin's half: whether this team holds the Nextcloud services,
// who holds them otherwise, and the two writes that change it. Team admins
// of a Service-template team only — both re-checked server-side.

/**
 * What the Services tab renders.
 *
 * @param {string} teamId the team being managed
 * @return {Promise<{services: object, availableServices: object[]}>}
 */
export async function loadTeamServices(teamId) {
	const { data } = await axios.get(teamServices(teamId))
	return {
		services: data?.services || {},
		availableServices: Array.isArray(data?.availableServices) ? data.availableServices : [],
	}
}

/** Claim the Nextcloud services for this team. 400 when another team holds them. */
export async function claimTeamServices(teamId) {
	const { data } = await axios.post(teamServices(teamId))
	return data?.services || {}
}

/** Give them back. The team stays a service team. */
export async function releaseTeamServices(teamId) {
	const { data } = await axios.delete(teamServices(teamId))
	return data?.services || {}
}

// ── Administration → TeamHub (v4.10.23) ────────────────────────────────

/**
 * Which team holds the Nextcloud services, for the Setup checklist row.
 * Nextcloud administrators only.
 */
export async function loadServiceDeskStatus() {
	const { data } = await axios.get(adminBase())
	return {
		holderTeamId: data?.holderTeamId || '',
		holderName: data?.holderName || '',
		agentCount: Number(data?.agentCount || 0),
		availableServices: Array.isArray(data?.availableServices) ? data.availableServices : [],
	}
}

/** Take the claim back from a team. Administrators only. */
export async function releaseServiceDesk(teamId) {
	const { data } = await axios.delete(adminBase('/' + encodeURIComponent(teamId)))
	return !!data?.released
}

// ── The service builder (v4.10.34) ───────────────────────────────────────
// `APIendpoints.md` § The service builder. The services a service team
// builds and publishes itself; members read, team admins write, and the
// server re-checks both on every call.

const builtServices = (teamId, path = '') =>
	generateUrl('/apps/teamhub/api/v1/teams/' + encodeURIComponent(teamId) + '/built-services' + path)

/**
 * The team's services, drafts included, with what the builder's pickers
 * offer: `{ services, canEdit, categories: [{ key, label }], limits }`.
 */
export async function loadBuiltServices(teamId) {
	const { data } = await axios.get(builtServices(teamId))
	return {
		services: Array.isArray(data?.services) ? data.services : [],
		canEdit: !!data?.canEdit,
		categories: Array.isArray(data?.categories) ? data.categories : [],
		limits: data?.limits || {},
	}
}

/** A new draft. `service` is the document. */
export async function createBuiltService(teamId, service) {
	const { data } = await axios.post(builtServices(teamId), { service })
	return data?.service || null
}

/** Save the draft; requests keep starting from the published version. */
export async function saveBuiltService(teamId, id, service) {
	const { data } = await axios.put(builtServices(teamId, '/' + encodeURIComponent(id)), { service })
	return data?.service || null
}

/** Publish the draft: the new version, on the Services page. */
export async function publishBuiltService(teamId, id) {
	const { data } = await axios.post(builtServices(teamId, '/' + encodeURIComponent(id) + '/publish'))
	return data?.service || null
}

/** Off the Services page; running requests finish. */
export async function unpublishBuiltService(teamId, id) {
	const { data } = await axios.post(builtServices(teamId, '/' + encodeURIComponent(id) + '/unpublish'))
	return data?.service || null
}

/** Delete a draft that was never published. */
export async function deleteBuiltService(teamId, id) {
	await axios.delete(builtServices(teamId, '/' + encodeURIComponent(id)))
}
