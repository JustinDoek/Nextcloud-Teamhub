/**
 * Service Teams — the frontend half of the vocabulary (v4.10.20).
 *
 * Mirrors `lib/Constants/ServiceCatalogue.php` and the `assign_mode`
 * values in `lib/Service/ServiceTeam/ServiceTeamService.php`. Keep in
 * sync. Every function here is pure so `tests/js/serviceTeams.test.mjs`
 * can run it without a browser.
 *
 * The labels are **not** mirrored: the server sends each catalogue entry's
 * label and description already translated (`ServiceCatalogue::describe()`),
 * because the same list has to read the same way in a notification, on the
 * Services tab and in the request picker. What lives here is the vocabulary
 * the client needs to *reason* about — the queue's three buckets and what
 * an agent may do with a row.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { activeStep } from './workflows.js'
import { isServiceIcon } from './serviceIcons.js'

// ── The catalogue ──────────────────────────────────────────────────────

export const SERVICE = {
	NEW_TEAM: 'new_team',
	// Retired in v4.10.45; its requests still carry the key.
	TEAM_CHANGE: 'team_change',
	// v4.10.45 — more time before a team's expiration date.
	TEAM_EXPIRY: 'team_expiry',
	EXTERNAL_ACCESS: 'external_access',
	SHARED_FOLDER: 'shared_folder',
	TEAM_ARCHIVE: 'team_archive',
	GENERAL: 'general',
	// v4.10.29 — the quota request, moved off the ledger into the bundle.
	TEAM_QUOTA: 'team_quota',
	// v4.10.50 — accept a team made outside TeamHub. Started by TeamHub
	// itself, so it never has a card on the Services page.
	TEAM_ADOPTION: 'team_adoption',
}

/** Display order; the specific services first, the catch-all last. */
export const SERVICE_ORDER = [
	SERVICE.NEW_TEAM,
	SERVICE.TEAM_EXPIRY,
	SERVICE.EXTERNAL_ACCESS,
	SERVICE.SHARED_FOLDER,
	SERVICE.TEAM_QUOTA,
	SERVICE.TEAM_ARCHIVE,
	SERVICE.TEAM_ADOPTION,
	SERVICE.GENERAL,
]

/**
 * An MDI component name per service, resolved by the view's component map.
 * A service with no glyph of its own falls back to the general one rather
 * than rendering nothing.
 */
export const SERVICE_ICONS = {
	[SERVICE.NEW_TEAM]: 'AccountGroupOutline',
	[SERVICE.TEAM_CHANGE]: 'PencilOutline',
	[SERVICE.TEAM_EXPIRY]: 'CalendarClock',
	[SERVICE.EXTERNAL_ACCESS]: 'AccountKeyOutline',
	[SERVICE.SHARED_FOLDER]: 'FolderAccountOutline',
	[SERVICE.TEAM_QUOTA]: 'DatabaseArrowUpOutline',
	[SERVICE.TEAM_ARCHIVE]: 'ArchiveOutline',
	[SERVICE.GENERAL]: 'HelpCircleOutline',
	[SERVICE.TEAM_ADOPTION]: 'AccountGroupOutline',
}

export function serviceIcon(serviceKey) {
	return SERVICE_ICONS[serviceKey] || SERVICE_ICONS[SERVICE.GENERAL]
}

/**
 * v4.10.45 — a catalogue entry's icon: the one its team picked for a
 * service it built, else the Nextcloud service's own.
 */
export function entryIcon(entry) {
	return isServiceIcon(entry?.icon) ? entry.icon : serviceIcon(entry?.serviceKey)
}

// ── More time for a team (v4.10.45) ────────────────────────────────────

/** The workflow definition a request for more time runs. */
export const EXPIRY_DEFINITION = 'team_expiry'

/** Whether a catalogue entry is the request for more time (it gets its own form). */
export function isExpiryService(entry) {
	return entry?.definitionKey === EXPIRY_DEFINITION || entry?.serviceKey === SERVICE.TEAM_EXPIRY
}

/**
 * Whether a date asked for is one the server will take: `YYYY-MM-DD`, after
 * the current expiration date, with a reason. The server checks it again.
 */
export function expiryRequestValid({ date, reason, currentOn }) {
	const d = String(date || '')
	if (!/^\d{4}-\d{2}-\d{2}$/.test(d) || !String(reason || '').trim()) {
		return false
	}
	return !currentOn || d > String(currentOn)
}

/** The first day after the current date, as the date field's minimum. */
export function dayAfter(date) {
	const d = new Date(String(date || '') + 'T12:00:00Z')
	if (Number.isNaN(d.getTime())) {
		return ''
	}
	d.setUTCDate(d.getUTCDate() + 1)
	return d.toISOString().slice(0, 10)
}

// ── The quota request (v4.10.29) ───────────────────────────────────────
//
// The one service whose form asks for a size rather than a summary, and
// whose definition is `teamspace_quota` (`QuotaRequestDefinition`). Opened
// from the card on the Services page and from the action menu on Manage
// team → Files; both render `QuotaRequestDialog`.

/** The workflow definition a quota request runs. */
export const QUOTA_DEFINITION = 'teamspace_quota'

const GIB = 1024 ** 3

/** Whether a catalogue entry is the quota request (it gets its own form). */
export function isQuotaService(entry) {
	return entry?.definitionKey === QUOTA_DEFINITION || entry?.serviceKey === SERVICE.TEAM_QUOTA
}

/** Bytes → the unit a person reads: 5 GB, 1.5 TB, 512 MB. */
export function formatBytes(bytes) {
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = Number(bytes) || 0
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`
}

/**
 * Where the size field starts: twice the current quota rounded up to whole
 * gigabytes, or 10 GB for a space without one.
 */
export function suggestedQuotaGb(currentBytes) {
	const current = Number(currentBytes) || 0
	return current > 0 ? Math.max(1, Math.ceil((current * 2) / GIB)) : 10
}

/** Whole gigabytes → bytes, the unit on the wire. */
export function gbToBytes(gb) {
	return Math.round(Number(gb) * GIB)
}

/** The largest size the form offers, as `QuotaRequestDefinition::MAX_BYTES` (10 TB). */
export const QUOTA_MAX_GB = 10240

/**
 * The form's own check; the server checks again. A size of at least 1 GB
 * and at most 10 TB that is more than the space has, and a reason.
 */
export function quotaRequestValid({ gb, reason, currentBytes }) {
	const size = Number(gb)
	if (!Number.isFinite(size) || size < 1 || size > QUOTA_MAX_GB) {
		return false
	}
	const current = Number(currentBytes) || 0
	return String(reason || '').trim() !== '' && (current <= 0 || gbToBytes(size) > current)
}

// ── The catalogue page (phase 7A, v4.10.25) ────────────────────────────
//
// Mirrors the category keys in `lib/Constants/ServiceCatalogue.php`. Only
// the **keys** and an icon per key live here: every label the page prints
// comes with the entry from the server, already translated, so the same
// category cannot read one way on the card and another on the tile.

export const CATEGORY = {
	TEAMS: 'teams_spaces',
	ACCESS: 'access_accounts',
	FILES: 'files_storage',
	SUPPORT: 'support_requests',
	APPS: 'apps_tools',
}

/** Display order of the category filter; the catch-all sits late, as in the service order. */
export const CATEGORY_ORDER = [
	CATEGORY.TEAMS,
	CATEGORY.ACCESS,
	CATEGORY.FILES,
	CATEGORY.SUPPORT,
	CATEGORY.APPS,
]

/** An MDI component name per category, resolved by the view's component map. */
export const CATEGORY_ICONS = {
	[CATEGORY.TEAMS]: 'AccountGroupOutline',
	[CATEGORY.ACCESS]: 'AccountKeyOutline',
	[CATEGORY.FILES]: 'FolderAccountOutline',
	[CATEGORY.SUPPORT]: 'HelpCircleOutline',
	[CATEGORY.APPS]: 'ViewGridOutline',
}

export function categoryIcon(category) {
	return CATEGORY_ICONS[category] || CATEGORY_ICONS[CATEGORY.SUPPORT]
}

/** The two readings of one list: everything by name, or grouped per desk. */
export const CATALOGUE_VIEW = {
	ALPHABETICAL: 'az',
	BY_TEAM: 'team',
}

/** `ALL_CATEGORIES` is not a category; it is the unfiltered view. */
export const ALL_CATEGORIES = ''

/** One row of the catalogue is a service *of a desk*: two desks may offer the same service. */
export function catalogueKey(entry) {
	return `${entry?.serviceTeamId || ''}:${entry?.serviceKey || ''}`
}

/**
 * The A–Z view: by label, and by the offering team where two labels match
 * — the card's subline is then the only thing that tells the two apart, so
 * ordering by it keeps them adjacent and in a stable order.
 *
 * `localeCompare` so a Danish å sorts where a Danish reader expects it.
 */
export function sortedCatalogue(entries) {
	return (entries || []).slice().sort((a, b) => {
		const byLabel = String(a?.label || '').localeCompare(String(b?.label || ''))
		return byLabel !== 0
			? byLabel
			: String(a?.serviceTeamName || '').localeCompare(String(b?.serviceTeamName || ''))
	})
}

/** The by-team view: one group per offering desk, each group's services A–Z. */
export function groupedByTeam(entries) {
	const groups = new Map()
	for (const entry of sortedCatalogue(entries)) {
		const id = entry?.serviceTeamId || ''
		if (!groups.has(id)) {
			groups.set(id, { teamId: id, teamName: entry?.serviceTeamName || '', services: [] })
		}
		groups.get(id).services.push(entry)
	}
	return [...groups.values()].sort((a, b) => a.teamName.localeCompare(b.teamName))
}

/** One category, or everything when no tile is selected. */
export function filterByCategory(entries, category) {
	if (!category) {
		return (entries || []).slice()
	}
	return (entries || []).filter(entry => entry?.category === category)
}

/**
 * The tiles across the top, counted per viewer.
 *
 * **A category nothing is in is not a tile.** A tile reading "Apps and
 * tools — 0" advertises something this server does not have, so the row
 * grows as services appear instead of promising them (
 * `docs/service-catalogue.md` § 2). The label is the server's, taken from
 * the first entry in the category; a category present in the data but
 * absent from `CATEGORY_ORDER` still gets a tile, at the end, so a service
 * added server-side is never invisible.
 */
export function categoryTiles(entries, order = CATEGORY_ORDER) {
	const counts = new Map()
	for (const entry of entries || []) {
		const key = entry?.category || ''
		if (!key) {
			continue
		}
		const tile = counts.get(key) || {
			id: key,
			label: entry?.categoryLabel || key,
			// v4.10.45 — the icon the administrator picked, else the built-in one.
			icon: isServiceIcon(entry?.categoryIcon) ? entry.categoryIcon : categoryIcon(key),
			count: 0,
		}
		tile.count += 1
		counts.set(key, tile)
	}
	// v4.10.45 — the administrator's order, when the server sent it.
	const keys = order && order.length ? order : CATEGORY_ORDER
	const known = keys.filter(key => counts.has(key)).map(key => counts.get(key))
	const rest = [...counts.values()].filter(tile => !keys.includes(tile.id))
	return [...known, ...rest]
}

// v4.10.23 — the assignment modes were here. A new request always waits in
// the queue now: "assign to the service owner" needed one owner, and the
// service owner became the team's admins (DESIGN §2.146).

// ── The queue ──────────────────────────────────────────────────────────

export const QUEUE_BUCKET = {
	UNCLAIMED: 'unclaimed',
	MINE: 'mine',
	OTHERS: 'others',
}

/** The buckets in the order an agent reads them: what is free, what is mine, what is taken. */
export const QUEUE_ORDER = [QUEUE_BUCKET.UNCLAIMED, QUEUE_BUCKET.MINE, QUEUE_BUCKET.OTHERS]

export function bucketLabel(bucket) {
	switch (bucket) {
	case QUEUE_BUCKET.UNCLAIMED:
		// TRANSLATORS: queue section - requests nobody has taken on yet
		return t('teamhub', 'Unclaimed')
	case QUEUE_BUCKET.MINE:
		// TRANSLATORS: queue section - requests this agent has claimed
		return t('teamhub', 'Mine')
	default:
		// TRANSLATORS: queue section - requests another agent has claimed
		return t('teamhub', 'With other agents')
	}
}

export function bucketEmptyState(bucket) {
	switch (bucket) {
	case QUEUE_BUCKET.UNCLAIMED:
		return t('teamhub', 'Nothing is waiting to be picked up.')
	case QUEUE_BUCKET.MINE:
		return t('teamhub', 'You have not claimed anything.')
	default:
		return t('teamhub', 'No other agent has anything in hand.')
	}
}

// ── What an agent may do with a row ────────────────────────────────────

export const AGENT_ACTION = {
	CLAIM: 'claim',
	ASSIGN: 'assign',
	RELEASE: 'release',
	INTERNAL_NOTE: 'internal_note',
}

export function agentActionLabel(action) {
	switch (action) {
	case AGENT_ACTION.CLAIM:
		// TRANSLATORS: button - take an unclaimed request out of the queue
		return t('teamhub', 'Claim request')
	case AGENT_ACTION.ASSIGN:
		// TRANSLATORS: button - hand a request to another agent of the same service team
		return t('teamhub', 'Assign to agent')
	case AGENT_ACTION.RELEASE:
		// TRANSLATORS: button - put a claimed request back in the shared queue
		return t('teamhub', 'Release request')
	case AGENT_ACTION.INTERNAL_NOTE:
		// TRANSLATORS: button - add a note only the service team can read
		return t('teamhub', 'Add internal note')
	default:
		return action || ''
	}
}

/**
 * The agent actions a row offers, read from the server's `internal` block
 * alone.
 *
 * **Hidden, not disabled** (CLAUDE.md § Permissions): the server has
 * already decided what this viewer may do — `claimable`, `canAssign`,
 * `canRelease` — and a button the call would refuse is worse than no
 * button. A workflow with no `internal` block is one this viewer does not
 * handle, and offers nothing at all.
 *
 * @return {string[]}
 */
export function agentActions(workflow) {
	const internal = workflow?.internal
	if (!internal) {
		return []
	}
	const out = []
	if (internal.claimable) {
		out.push(AGENT_ACTION.CLAIM)
	}
	if (internal.canAssign) {
		out.push(AGENT_ACTION.ASSIGN)
	}
	if (internal.canRelease) {
		out.push(AGENT_ACTION.RELEASE)
	}
	// A note needs no permission beyond being on the desk, which is what
	// having an `internal` block means.
	out.push(AGENT_ACTION.INTERNAL_NOTE)
	return out
}

/**
 * v4.10.35 — the queue's list of this desk's open requests whose active step
 * is somebody else's: back with the requester for a planned action or the
 * confirmation (`withOthers` on `GET /service-teams/{id}/queue`, v4.10.31).
 */
export const QUEUE_WITH_OTHERS = 'withOthers'

/**
 * Which queue list a row belongs to, from the viewer's point of view.
 *
 * v4.10.35 — a request whose active step is not the desk's is *with the
 * requester*, whoever claimed the desk's earlier step. Until then it was
 * filed as unclaimed after any action on it, because nobody holds the
 * requester's step.
 */
export function bucketOf(workflow, uid) {
	const step = activeStep(workflow)
	if (step && step.actor?.type !== 'service_agent') {
		return QUEUE_WITH_OTHERS
	}
	const assignee = workflow?.internal?.assignee || ''
	if (assignee === '') {
		return QUEUE_BUCKET.UNCLAIMED
	}
	return assignee === uid ? QUEUE_BUCKET.MINE : QUEUE_BUCKET.OTHERS
}

/**
 * Who has this request, as a line of text. Empty for an unclaimed one —
 * the bucket already says that, and "Unclaimed: nobody" is the same fact
 * twice.
 */
export function assigneeLabel(workflow) {
	const internal = workflow?.internal
	if (!internal || !internal.assignee) {
		return ''
	}
	// TRANSLATORS: {name} is the agent who has taken this request on
	return t('teamhub', 'With {name}', { name: internal.assigneeName || internal.assignee })
}

/**
 * The agents an assignment may name, as `{ id, label }` for NcSelect.
 * Drawn from the server's list, which is the eligibility rule itself — a
 * client that filtered this further would be guessing.
 */
export function assignableAgents(workflow) {
	const agents = workflow?.internal?.agents || {}
	return Object.keys(agents).map(uid => ({ id: uid, label: agents[uid] || uid }))
}

/** The internal events, newest first — the desk's own half of the history. */
export function internalNotes(history) {
	return (history || [])
		.filter(e => e?.type === 'internal_note')
		.slice()
		.sort((a, b) => (b.occurredAt || 0) - (a.occurredAt || 0))
}

// ── The widgets on the service team (phase B, v4.10.27) ────────────────
//
// The desk works on its own team's home (`/service-teams`): a tabbed queue
// and a statistics widget. What the two read from the server is shaped here
// so the tests can say the tabs and the numbers are right without a browser.

/**
 * The queue widget's tabs. v4.10.35 — *With requester*: the desk's open
 * requests that are back with the requester (a planned requester step, or
 * the confirmation). Without it they were in no tab at all, and a request
 * that started with the requester never showed on the team.
 */
export const QUEUE_TAB = {
	NEW: 'new',
	CLAIMED: 'claimed',
	WITH_REQUESTER: 'with_requester',
	CLOSED: 'closed',
}

export const QUEUE_TABS = [QUEUE_TAB.NEW, QUEUE_TAB.CLAIMED, QUEUE_TAB.WITH_REQUESTER, QUEUE_TAB.CLOSED]

export function queueTabLabel(tab) {
	switch (tab) {
	case QUEUE_TAB.NEW:
		// TRANSLATORS: queue widget tab - requests nobody has claimed yet
		return t('teamhub', 'New')
	case QUEUE_TAB.CLAIMED:
		// TRANSLATORS: queue widget tab - requests a team member has claimed
		return t('teamhub', 'Claimed')
	case QUEUE_TAB.WITH_REQUESTER:
		// TRANSLATORS: queue widget tab - the team's open requests that wait for the person who asked (a step of theirs, or their confirmation)
		return t('teamhub', 'With requester')
	default:
		// TRANSLATORS: queue widget tab - requests the team finished in the last 30 days
		return t('teamhub', 'Closed')
	}
}

export function queueTabEmptyState(tab) {
	switch (tab) {
	case QUEUE_TAB.NEW:
		return t('teamhub', 'Nothing is waiting to be picked up.')
	case QUEUE_TAB.CLAIMED:
		return t('teamhub', 'Nobody has a request in hand.')
	case QUEUE_TAB.WITH_REQUESTER:
		return t('teamhub', 'No request is waiting for its requester.')
	default:
		return t('teamhub', 'Nothing was closed in the last 30 days.')
	}
}

/**
 * The rows of one tab. *Claimed* puts the viewer's own requests first —
 * they are the ones this person is answerable for — and then the rest of
 * the team's, so an admin redistributing work sees everything in one list.
 */
export function queueTabRows(queue, tab) {
	switch (tab) {
	case QUEUE_TAB.NEW:
		return queue?.unclaimed || []
	case QUEUE_TAB.CLAIMED:
		return [...(queue?.mine || []), ...(queue?.others || [])]
	case QUEUE_TAB.WITH_REQUESTER:
		return queue?.[QUEUE_WITH_OTHERS] || []
	default:
		return queue?.closed || []
	}
}

/** How the desk's part of a closed request ended, from the row's `desk` block. */
export function deskOutcomeLabel(status) {
	switch (status) {
	case 'completed':
		// TRANSLATORS: a request the service team answered
		return t('teamhub', 'Answered')
	case 'rejected':
		// TRANSLATORS: a request the service team turned down
		return t('teamhub', 'Rejected')
	case 'cancelled':
		// TRANSLATORS: a request the requester cancelled while the service team had it
		return t('teamhub', 'Withdrawn')
	case 'skipped':
		// v4.10.32 — the step an admin of the service team closed the request on (closeRequest()).
		// TRANSLATORS: a request an admin of the service team closed before it was finished
		return t('teamhub', 'Closed')
	default:
		return status || ''
	}
}

/** The NcChip variant for that outcome: rejected reads as the exception. */
export function deskOutcomeVariant(status) {
	return status === 'rejected' ? 'error' : (status === 'completed' ? 'success' : 'tertiary')
}

/** The statistics widget's periods, in days — the server refuses any other. */
export const STATISTICS_PERIODS = [7, 30, 90]

export function periodLabel(days) {
	return n('teamhub', 'Last {n} day', 'Last {n} days', days, { n: days })
}

/**
 * A median in seconds as words a person reads at a glance: minutes under
 * an hour, hours under two days, days after that. Rounded, because "3 hours"
 * is the information and "3 hours 7 minutes" is noise on a dashboard. An
 * empty period says so rather than showing a zero it did not measure.
 */
export function formatDuration(seconds) {
	if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) {
		// TRANSLATORS: a statistic with nothing to measure in the period
		return t('teamhub', 'No data')
	}
	const s = Math.max(0, Number(seconds))
	if (s < 3600) {
		const minutes = Math.max(1, Math.round(s / 60))
		return n('teamhub', '{n} minute', '{n} minutes', minutes, { n: minutes })
	}
	if (s < 2 * 86400) {
		const hours = Math.round(s / 3600)
		return n('teamhub', '{n} hour', '{n} hours', hours, { n: hours })
	}
	const days = Math.round(s / 86400)
	return n('teamhub', '{n} day', '{n} days', days, { n: days })
}

// ── The desk in the team's activity stream (phase B, v4.10.27) ────────

/** Is this activity row one of the desk's own state changes? */
export function isDeskActivity(item) {
	return item?.app === 'teamhub' && String(item?.subject || '').startsWith('service.request_')
}

/**
 * The sentence for one desk event, or '' for anything else. Shared by the
 * Activity widget and the full activity view so the two cannot word the
 * same event differently.
 *
 * `wrap` is the caller's `raw()` — it stops `t()` HTML-escaping a name or a
 * title that Vue already escapes as a text node.
 */
export function deskActivityLine(item, wrap = v => v) {
	if (!isDeskActivity(item)) {
		return ''
	}
	const p = item.subjectparams || {}
	const user = wrap(item.displayName || item.user || '')
	const title = wrap(p.title || '')
	switch (item.subject) {
	case 'service.request_received':
		// TRANSLATORS: activity line - {user} sent a request to this service team; {title} is the request
		return t('teamhub', '{user} sent a request: {title}', { user, title })
	case 'service.request_claimed':
		// TRANSLATORS: activity line - {user} took a request out of the queue
		return t('teamhub', '{user} claimed {title}', { user, title })
	case 'service.request_assigned':
		// TRANSLATORS: activity line - a team admin ({user}) handed a request to {to}
		return t('teamhub', '{user} assigned {title} to {to}', { user, title, to: wrap(p.to_name || p.to_user || '') })
	case 'service.request_released':
		// TRANSLATORS: activity line - {user} put a claimed request back in the queue
		return t('teamhub', '{user} set {title} back to unclaimed', { user, title })
	case 'service.request_answered':
		// TRANSLATORS: activity line - {user} answered a request; the requester confirms next
		return t('teamhub', '{user} answered {title}', { user, title })
	case 'service.request_rejected':
		// TRANSLATORS: activity line - {user} turned a request down
		return t('teamhub', '{user} rejected {title}', { user, title })
	case 'service.request_cancelled':
		// TRANSLATORS: activity line - the requester {user} cancelled their own request
		return t('teamhub', '{user} withdrew {title}', { user, title })
	case 'service.request_closed':
		// TRANSLATORS: activity line - a team admin ({user}) closed a request for the service team, done or not
		return t('teamhub', '{user} closed {title}', { user, title })
	default:
		return ''
	}
}
