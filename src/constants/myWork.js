/**
 * TeamHub — My Work shared vocabulary (v4.5.21).
 *
 * Mirrors the PHP constants in
 * `lib/MyWork/{Category,ActionType,Priority,OpenTarget}.php`.
 * KEEP THESE IN SYNC — the strings travel over the wire in both directions.
 *
 * The point of centralising this is that the item row renders from the
 * normalized model alone: there is no `if (providerId === 'deck')` anywhere in
 * the UI, so a new backend provider needs no frontend change beyond adding its
 * icon to PROVIDER_ICONS below (and even that falls back gracefully).
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDate, formatDateTime, todayIso, shiftIsoDate, fromDateInput } from '../lib/localDate.js'

// ── Categories ──────────────────────────────────────────────────────────

export const CATEGORY = {
	ACTION_REQUIRED: 'action_required',
	TODAY: 'today',
	UPCOMING: 'upcoming',
	WAITING_FOR_OTHERS: 'waiting_for_others',
	// v4.5.45 — housekeeping a team admin owes their team, not the viewer's
	// own deliverables. Ranked below Waiting for others on purpose: an
	// unreviewed resource is real work, but it is never more urgent than a
	// deadline. Mirror of Category::TEAM_ADMIN.
	TEAM_ADMIN: 'team_admin',
	COMPLETED: 'completed',
}

/** Display + urgency order. Index 0 is the most urgent. */
export const CATEGORY_ORDER = [
	CATEGORY.ACTION_REQUIRED,
	CATEGORY.TODAY,
	CATEGORY.UPCOMING,
	CATEGORY.WAITING_FOR_OTHERS,
	CATEGORY.TEAM_ADMIN,
	CATEGORY.COMPLETED,
]

/**
 * Labels are functions, not constants, because `t()` must run after
 * Nextcloud's l10n bundle has loaded. Evaluating at module scope gives
 * English on a slow bundle load.
 */
export function categoryLabel(category) {
	switch (category) {
	case CATEGORY.ACTION_REQUIRED:
		return t('teamhub', 'Action required')
	case CATEGORY.TODAY:
		return t('teamhub', 'Today')
	case CATEGORY.UPCOMING:
		return t('teamhub', 'Upcoming')
	case CATEGORY.WAITING_FOR_OTHERS:
		return t('teamhub', 'Waiting for others')
	case CATEGORY.TEAM_ADMIN:
		// TRANSLATORS: My Work category — housekeeping the viewer owes their team as its admin
		return t('teamhub', 'Team admin')
	case CATEGORY.COMPLETED:
		return t('teamhub', 'Completed')
	default:
		return category
	}
}

/**
 * The action-oriented empty states the specification asks for, one per
 * category plus the all-clear.
 */
export function categoryEmptyState(category) {
	switch (category) {
	case CATEGORY.ACTION_REQUIRED:
		return t('teamhub', 'You currently have no actions requiring immediate attention.')
	case CATEGORY.TODAY:
		return t('teamhub', 'Nothing is due today.')
	case CATEGORY.UPCOMING:
		return t('teamhub', 'Nothing is coming up in the next few days.')
	case CATEGORY.WAITING_FOR_OTHERS:
		return t('teamhub', 'You are not currently waiting for actions from others.')
	case CATEGORY.TEAM_ADMIN:
		return t('teamhub', 'Your teams need nothing from you as their admin.')
	case CATEGORY.COMPLETED:
		return t('teamhub', 'Nothing has been completed recently.')
	default:
		return ''
	}
}

/** MDI component name per category, resolved by MyWorkView's component map. */
export const CATEGORY_ICONS = {
	[CATEGORY.ACTION_REQUIRED]: 'AlertCircleOutline',
	[CATEGORY.TODAY]: 'CalendarToday',
	[CATEGORY.UPCOMING]: 'CalendarClock',
	[CATEGORY.WAITING_FOR_OTHERS]: 'AccountClock',
	// v4.5.45 — a shield with a person: the work you have because of the role
	// you hold, not because of what you were assigned. Deliberately not a
	// second Account* glyph — AccountClock is already Waiting for others and
	// the two would be a coin-flip at 16px.
	[CATEGORY.TEAM_ADMIN]: 'ShieldAccountOutline',
	[CATEGORY.COMPLETED]: 'CheckCircleOutline',
}

/**
 * CSS tone suffix per category, matching the `--th-mywork-{tone}-*` tokens
 * in widget-tokens.css. Kept as a map rather than derived from the category
 * string so the token names stay greppable from both sides.
 */
export const CATEGORY_TONES = {
	[CATEGORY.ACTION_REQUIRED]: 'action',
	[CATEGORY.TODAY]: 'today',
	[CATEGORY.UPCOMING]: 'upcoming',
	[CATEGORY.WAITING_FOR_OTHERS]: 'waiting',
	[CATEGORY.TEAM_ADMIN]: 'admin',
	[CATEGORY.COMPLETED]: 'done',
}

/**
 * The accessible name for one source chip under a summary card's number.
 *
 * v4.5.25 — the chip itself is now the source's **icon and its count**, not
 * its name and its count. Five cards each spelling out three source names was
 * three lines of text competing with the number above them, and the number is
 * what the card is for; the glyph says the same thing in one character and is
 * already the icon of the tab the row opens.
 *
 * The critical sub-count went with it, at Justin's request. It answered "how
 * many of these are urgent", which the deadline column answers per row and the
 * category answers per section — a third place to learn it was one too many.
 *
 * This string survives as the chip's `title`/`aria-label`: an icon that
 * conveys meaning needs a text alternative, so the name is not lost, only
 * moved off the screen.
 *
 * @param {object} row  { providerId, count } from the API breakdown
 * @param {object} providerNames  id → translated name
 * @return {string}
 */
export function breakdownLabel(row, providerNames = {}) {
	const name = providerNames[row.providerId] || row.providerId
	return t('teamhub', '{source} {count}', { source: name, count: row.count })
}

// ── Team badges ─────────────────────────────────────────────────────────

/** Number of team badge tints defined in widget-tokens.css. */
export const TEAM_TONE_COUNT = 6

/**
 * Deterministic badge tone (1–6) for a team.
 *
 * A team must be the same colour on every page, for every user, forever —
 * that is what makes the badge *recognisable* rather than decorative, which
 * is what the specification asks for. Hashing the immutable circle id gives
 * that for free, with nothing to store and nothing to migrate. Renaming a
 * team keeps its colour; that is the intent.
 *
 * FNV-1a: tiny, no dependency, and well spread over short ASCII ids — a
 * naive charCode sum clusters badly on ids sharing a prefix, which circle
 * ids frequently do.
 *
 * @param {string} teamId
 * @return {number} 1-based tone index
 */
export function teamBadgeTone(teamId) {
	if (!teamId) {
		return 1
	}
	let hash = 0x811c9dc5
	for (let i = 0; i < teamId.length; i++) {
		hash ^= teamId.charCodeAt(i)
		// 16777619, via shifts — Math.imul keeps this in 32-bit space.
		hash = Math.imul(hash, 0x01000193) >>> 0
	}
	return (hash % TEAM_TONE_COUNT) + 1
}

// ── Priorities ──────────────────────────────────────────────────────────

export const PRIORITY = {
	URGENT: 'urgent',
	HIGH: 'high',
	NORMAL: 'normal',
	LOW: 'low',
}

export const PRIORITY_ORDER = [
	PRIORITY.URGENT,
	PRIORITY.HIGH,
	PRIORITY.NORMAL,
	PRIORITY.LOW,
]

export function priorityLabel(priority) {
	switch (priority) {
	case PRIORITY.URGENT:
		// TRANSLATORS: priority level of a work item — the most pressing
		return t('teamhub', 'Urgent')
	case PRIORITY.HIGH:
		// TRANSLATORS: priority level of a work item
		return t('teamhub', 'High')
	case PRIORITY.NORMAL:
		// TRANSLATORS: priority level of a work item — the default
		return t('teamhub', 'Normal')
	case PRIORITY.LOW:
		// TRANSLATORS: priority level of a work item — the least pressing
		return t('teamhub', 'Low')
	default:
		return priority
	}
}

// ── Actions ─────────────────────────────────────────────────────────────

export const ACTION = {
	OPEN: 'open',
	COMPLETE: 'complete',
	APPROVE: 'approve',
	REJECT: 'reject',
	REQUEST_CHANGES: 'request_changes',
	COMMENT: 'comment',
	DELEGATE: 'delegate',
	SNOOZE: 'snooze',
	UNSNOOZE: 'unsnooze',
	// v4.5.42 — a decision proposer closing their own drafting phase. Not
	// COMPLETE: finalizing hands the proposal to an approver rather than
	// finishing it. See ActionType::FINALIZE.
	FINALIZE: 'finalize',
	// v4.5.40 — `follow` / `unfollow` removed. The pair had no undo: the only
	// control that could unfollow wrote the muted state, so an item you
	// stopped following left My Work for good. See ActionType.php.
	// v4.5.25 — navigation only. Mirrors ActionType::NAVIGATION: the item
	// carries the URL in its metadata and the frontend opens it. These are
	// never posted to the action endpoint.
	JOIN: 'join',
	AGENDA: 'agenda',
	// v4.6.16 — write to the person behind the item, in the viewer's own mail
	// client. Navigation for the same reason JOIN is: the item carries a
	// compose URL and TeamHub never sends anything itself.
	EMAIL: 'email',
	// v4.6.16 — a team admin asking for more time.
	// v4.6.17 — a real action, executed from a modal in My Work. It used to be
	// navigation resolved against the item's openTarget, which meant the row
	// answered "ask for more time" by ejecting the reader to Manage team →
	// Maintenance. It carries `{ proposedOn, reason }` as params.
	REQUEST_EXTENSION: 'request_extension',
	// v4.8.18 — the person who asked for a file review ending it. Not
	// COMPLETE: that verb belongs to the reviewer and means "my part is done",
	// while this one ends the whole request, deletes its Talk conversation, and
	// withdraws the item from the queue of anybody who had not answered. It is
	// listed in DESTRUCTIVE_ACTIONS for exactly those reasons. See
	// ActionType::CLOSE.
	CLOSE: 'close',
}

/**
 * Actions handled entirely in the browser, and the metadata key holding the
 * URL each one opens. An action listed here never reaches the server.
 */
export const NAVIGATION_ACTIONS = {
	[ACTION.JOIN]: 'talkUrl',
	[ACTION.AGENDA]: 'agendaUrl',
	[ACTION.EMAIL]: 'mailtoUrl',
}

/**
 * Actions that open a form in My Work before anything is sent (v4.6.17).
 *
 * The row's own controls cannot carry a date field, but that is an argument for
 * a dialog rather than for sending the reader somewhere else: `request_extension`
 * used to be resolved against the item's `openTarget` and landed on Manage team
 * → Maintenance, so the queue answered "ask for more time" by ejecting you from
 * the queue. These collect their fields in a modal and then post to the action
 * endpoint like any other verb.
 */
export const FORM_ACTIONS = [ACTION.REQUEST_EXTENSION]

/**
 * Which actions get a button on the row versus a place in the overflow menu.
 *
 * The specification allows up to three primary actions; PRIMARY_ACTIONS is
 * ordered, and the row takes the first three of an item's available actions
 * that appear here. Everything else falls to the menu, so an item that gains
 * an action in a future release degrades into the menu rather than pushing
 * the row wider.
 */
export const PRIMARY_ACTIONS = [
	ACTION.OPEN,
	ACTION.APPROVE,
	ACTION.REJECT,
	ACTION.COMPLETE,
	// v4.8.22 — ranked, not promoted to a button: since v4.5.25 the row shows
	// only Open and everything else lives in the menu. Listing it here puts
	// "Close review" at the top of that menu rather than at the end, which
	// matters because it is the requester's only action and they were looking
	// for it.
	ACTION.CLOSE,
	ACTION.REQUEST_CHANGES,
	// v4.6.16 — ranked, not promoted: since v4.5.25 the row itself carries only
	// Open and everything else lives in the menu. Listing it here puts Email
	// owner above any unranked action rather than at the end of the menu.
	ACTION.EMAIL,
]

export const MAX_PRIMARY_ACTIONS = 3

export function actionLabel(action) {
	switch (action) {
	case ACTION.OPEN:
		// TRANSLATORS: button — open the item's document or card
		return t('teamhub', 'Open')
	case ACTION.COMPLETE:
		// TRANSLATORS: button — mark a task as finished
		return t('teamhub', 'Complete')
	case ACTION.APPROVE:
		// TRANSLATORS: button — approve a file in an approval workflow
		return t('teamhub', 'Approve')
	case ACTION.REJECT:
		// TRANSLATORS: button — reject a file in an approval workflow
		return t('teamhub', 'Reject')
	case ACTION.REQUEST_CHANGES:
		return t('teamhub', 'Request changes')
	case ACTION.COMMENT:
		// TRANSLATORS: button — write a comment on the item
		return t('teamhub', 'Comment')
	case ACTION.DELEGATE:
		// TRANSLATORS: button — hand the item to somebody else
		return t('teamhub', 'Delegate')
	case ACTION.SNOOZE:
		// TRANSLATORS: button — hide this item until a chosen moment
		return t('teamhub', 'Snooze')
	case ACTION.UNSNOOZE:
		return t('teamhub', 'Stop snoozing')
	case ACTION.FINALIZE:
		// TRANSLATORS: button — the proposer closes drafting and sends the decision proposal to its approvers
		return t('teamhub', 'Finalize')
	case ACTION.JOIN:
		// TRANSLATORS: button — join a meeting's Talk call
		return t('teamhub', 'Join call')
	case ACTION.AGENDA:
		// TRANSLATORS: button — open a meeting's notes/agenda document
		return t('teamhub', 'Open agenda')
	case ACTION.EMAIL:
		// TRANSLATORS: button — start an email to the owner of the team named on this row, in the reader's own mail client
		return t('teamhub', 'Email owner')
	case ACTION.REQUEST_EXTENSION:
		// TRANSLATORS: button — ask a Nextcloud administrator to push back the team's expiration date
		return t('teamhub', 'Request extension')
	case ACTION.CLOSE:
		// TRANSLATORS: button — the person who asked for a file review ends it; this also deletes the review's chat conversation
		return t('teamhub', 'Close review')
	default:
		return action
	}
}

export const ACTION_ICONS = {
	[ACTION.OPEN]: 'OpenInNew',
	[ACTION.COMPLETE]: 'CheckCircleOutline',
	[ACTION.APPROVE]: 'Check',
	[ACTION.REJECT]: 'Close',
	[ACTION.REQUEST_CHANGES]: 'CommentEditOutline',
	[ACTION.COMMENT]: 'CommentOutline',
	[ACTION.DELEGATE]: 'AccountArrowRight',
	[ACTION.SNOOZE]: 'AlarmSnooze',
	[ACTION.UNSNOOZE]: 'AlarmOff',
	[ACTION.FINALIZE]: 'Gavel',
	[ACTION.JOIN]: 'VideoOutline',
	[ACTION.AGENDA]: 'FileDocumentOutline',
	[ACTION.EMAIL]: 'EmailOutline',
	[ACTION.REQUEST_EXTENSION]: 'CalendarClock',
	[ACTION.CLOSE]: 'ArchiveCheckOutline',
}

/**
 * Actions whose effect the user should confirm before it happens.
 *
 * v4.8.18 — CLOSE joined REJECT. It is the more consequential of the two: it
 * deletes the review's Talk conversation, and every reviewer who had not
 * answered loses the request from their own queue. A one-click control for
 * that would be the worst button in the app.
 */
export const DESTRUCTIVE_ACTIONS = [ACTION.REJECT, ACTION.CLOSE]

// ── Snooze presets ──────────────────────────────────────────────────────

export function snoozePresets() {
	return [
		{ key: 'later_today', label: t('teamhub', 'Later today') },
		{ key: 'tomorrow', label: t('teamhub', 'Tomorrow') },
		{ key: 'next_week', label: t('teamhub', 'Next week') },
		{ key: 'custom', label: t('teamhub', 'Pick a date and time…') },
	]
}

/**
 * Resolve a snooze preset to an absolute moment **in the browser's timezone**
 * (v4.5.24).
 *
 * The server can resolve these too, and did until now — but only against its
 * own clock, so a user five zones away asking for "Tomorrow" got a moment that
 * was neither their tomorrow nor 09:00. Only the browser knows what the user
 * meant, so it computes the moment and sends it as a `custom` snooze. The
 * server keeps its preset branch for API callers with no clock of their own.
 *
 * Semantics match the server's exactly, including the Sunday case: PHP's
 * `monday next week` lands on tomorrow when today is a Sunday, because the ISO
 * week is already ending. That reads correctly in English too.
 *
 * @param {string} key `later_today` | `tomorrow` | `next_week`
 * @param {Date} now injectable so the arithmetic is testable
 * @return {number|null} Unix seconds, or null when the key is not a preset
 *                       this function resolves (`custom`, or anything unknown)
 */
export function resolveSnoozePreset(key, now = new Date()) {
	const nowSeconds = Math.floor(now.getTime() / 1000)
	// setHours/getDay/getDate are all local-time by definition, which is the
	// entire point — no zone arithmetic of our own, and DST is the platform's
	// problem rather than ours.
	const atHour = (date, hour) => {
		const d = new Date(date)
		d.setHours(hour, 0, 0, 0)
		return Math.floor(d.getTime() / 1000)
	}
	const inDays = (days) => {
		const d = new Date(now)
		d.setDate(d.getDate() + days)
		return d
	}

	switch (key) {
		// 18:00 today, or three hours out if the working day is already done.
		case 'later_today': {
			const six = atHour(now, 18)
			return six > nowSeconds ? six : nowSeconds + (3 * 3600)
		}
		case 'tomorrow':
			return atHour(inDays(1), 9)
		case 'next_week': {
			const dow = now.getDay() // 0 = Sunday … 6 = Saturday
			return atHour(inDays(dow === 0 ? 1 : 8 - dow), 9)
		}
		default:
			return null
	}
}

// ── Opening a row ───────────────────────────────────────────────────────

/**
 * The four ways a row can open. Mirrors `lib/MyWork/OpenTarget.php`.
 *
 * A provider names one of these; the shell owns what each one does. That is
 * the seam §2.71 left open: before this, opening was inferred from
 * `resourceType`, so every TeamHub-native provider needed its own branch in
 * `App.vue`. Now there is one branch per mechanism, and mechanisms are ours.
 */
export const OPEN_KIND = {
	DECK_CARD: 'deck_card',
	FILE: 'file',
	TEAMHUB_VIEW: 'teamhub_view',
	CALENDAR_EVENT: 'calendar_event',
	// v4.5.45 — Manage team at a tab/section. Not a team *tab*, which is why
	// TEAMHUB_VIEW could not describe it. Mirror of OpenTarget::MANAGE_TEAM.
	MANAGE_TEAM: 'manage_team',
	EXTERNAL: 'external',
}

/**
 * TeamHub views a provider may open, and the store mutation that pre-selects a
 * row in each.
 *
 * `null` means the view opens but has nothing to select. A key that is **absent
 * entirely** means the shell has no such view, and the row falls back to its
 * external URL — that is the deliberate failure mode for a provider pointing at
 * a tab that does not exist.
 *
 * This map is the whole cost of a new TeamHub-native source. A provider that
 * targets Decisions costs nothing at all.
 */
export const TEAMHUB_VIEW_TARGETS = {
	decisions: 'SET_DECISIONS_TARGET',
}

// ── Sorting ─────────────────────────────────────────────────────────────

/**
 * Order within a group. Mirrors `WorkQuery::SORTS`.
 *
 * Deliberately does not offer "category" — the categories already order the
 * page, and a sort that could dismantle them would let a user hide Action
 * required below Completed, which is the one arrangement this view must never
 * produce.
 */
export const SORT = {
	DEADLINE: 'deadline',
	PRIORITY: 'priority',
	TEAM: 'team',
	RECENT: 'recent',
}

export function sortOptions() {
	return [
		// TRANSLATORS: sort order — by when the item is due
		{ key: SORT.DEADLINE, label: t('teamhub', 'Deadline') },
		{ key: SORT.PRIORITY, label: t('teamhub', 'Priority') },
		{ key: SORT.TEAM, label: t('teamhub', 'Team') },
		// TRANSLATORS: sort order — most recently changed first
		{ key: SORT.RECENT, label: t('teamhub', 'Recently updated') },
	]
}

export function sortLabel(key) {
	return sortOptions().find(o => o.key === key)?.label
		|| sortOptions()[0].label
}

// ── Grouping ────────────────────────────────────────────────────────────

export function groupByOptions() {
	return [
		{ key: 'category', label: t('teamhub', 'Category and urgency') },
		{ key: 'date', label: t('teamhub', 'Date') },
		{ key: 'team', label: t('teamhub', 'Team') },
		// v4.9.7 — a source that names a project (an OpenProject work
		// package) groups under it; everything else under its team.
		// TRANSLATORS: My Work grouping — by the project an item belongs to
		{ key: 'project', label: t('teamhub', 'Project') },
		{ key: 'resource_type', label: t('teamhub', 'Resource type') },
	]
}

// ── Predefined views (v4.9.7) ───────────────────────────────────────────

/**
 * One-click views: each is a patch over the filter state plus, optionally,
 * a grouping. They are shortcuts, not a second filter system — applying
 * one sets the same fields the filter bar sets, so the bar shows what the
 * view did and "Clear filters" undoes it.
 *
 * A view may name `requiresProvider` (a provider's stable id) to be offered
 * only where that source is registered. No view does today: the
 * *OpenProject* filter view was dropped on 2026-09-14 (Justin: the source
 * tabs already filter by source, and OpenProject rows belong under
 * *By project*, where they group under their project's name).
 *
 * @return {Array<{key: string, label: string, filters: object, groupBy?: string, requiresProvider?: string}>}
 */
export function predefinedViews() {
	return [
		{
			key: 'attention',
			// TRANSLATORS: My Work predefined view — only the items that require action
			label: t('teamhub', 'Needs attention'),
			filters: { category: CATEGORY.ACTION_REQUIRED, dueWindow: '' },
		},
		{
			key: 'week',
			// TRANSLATORS: My Work predefined view — everything due in the next seven days, overdue included
			label: t('teamhub', 'Due this week'),
			filters: { category: '', dueWindow: 'week' },
		},
		{
			key: 'by-team',
			// TRANSLATORS: My Work predefined view — grouped by team
			label: t('teamhub', 'By team'),
			filters: {},
			groupBy: 'team',
		},
		{
			key: 'by-project',
			// TRANSLATORS: My Work predefined view — grouped by project
			label: t('teamhub', 'By project'),
			filters: {},
			groupBy: 'project',
		},
	]
}

/**
 * The filter patch that applying a view means.
 *
 * A filter view (Needs attention, Due this week) is a destination, not a
 * modifier: it **replaces** whatever another filter view left behind.
 * Justin's review (2026-09-14): with *Needs attention* still on from
 * earlier, clicking the then *OpenProject* view showed "Nothing matches
 * these filters" for two work packages that were sitting under Upcoming —
 * and read as the work packages being missing. So every key any filter
 * view sets is cleared first, then this view's own values are applied.
 * A grouping view (By team, By project) sets no filter and touches none.
 *
 * Toggling an active view off clears only its own keys — the filter bar
 * may have set the others and those are not the view's to undo.
 *
 * @param {object} view from predefinedViews()
 * @param {boolean} active whether the view is currently on (→ turn it off)
 * @return {object} the patch for SET_MYWORK_FILTERS; empty for a grouping view
 */
export function viewFilterPatch(view, active) {
	const own = view?.filters || {}
	if (active) {
		return Object.fromEntries(Object.keys(own).map(k => [k, k === 'showSnoozed' ? false : '']))
	}
	if (Object.keys(own).length === 0) {
		return {}
	}
	const patch = {}
	for (const other of predefinedViews()) {
		for (const key of Object.keys(other.filters || {})) {
			patch[key] = key === 'showSnoozed' ? false : ''
		}
	}
	return { ...patch, ...own }
}

/**
 * Is a predefined view the current state? A view is "on" when every filter
 * it sets has that value and, when it names a grouping, that grouping is
 * active — so a filter view and a grouping view can be on at once (Needs
 * attention + By team), which is what a reader who clicked both expects.
 * Two filter views cannot: applying one replaces the other (see
 * `viewFilterPatch`).
 *
 * @param {object} view from predefinedViews()
 * @param {object} filters the store's My Work filters
 * @param {string} groupBy the store's grouping
 * @return {boolean}
 */
export function isViewActive(view, filters, groupBy) {
	const keys = Object.keys(view.filters || {})
	if (keys.length === 0 && !view.groupBy) {
		return false
	}
	for (const key of keys) {
		if ((filters?.[key] ?? '') !== view.filters[key]) {
			return false
		}
	}
	return view.groupBy ? groupBy === view.groupBy : true
}

// ── Source groups (v4.9.17) ─────────────────────────────────────────────

/**
 * The source tabs cluster providers that answer the same question. Mirror
 * of `lib/MyWork/SourceGroup.php` — KEEP IN SYNC.
 *
 * Justin, 2026-09-15: nine tabs was too many. Everything about files under
 * **Files** (the Approval app's approvals, TeamHub's file reviews); everything
 * a team's own admin owes their team under **Teams** (resources to review,
 * join requests, the team's own expiration and extension requests); anything
 * aimed at a Nextcloud administrator acting in the administration area
 * (granting a longer expiration, later: enlarging a team folder) under
 * **Administration**. Deck, Decisions, Meetings and OpenProject stay single
 * tabs for now.
 *
 * A group key is a valid `providerId` filter value: the server expands it
 * to its members, so the tab bar, the Source dropdown and a stored
 * preference all send the same thing.
 */
export const SOURCE_GROUP = {
	FILES: 'files',
	TEAMS: 'teams',
	ADMINISTRATION: 'administration',
}

/** Group → provider ids, in display order. */
export const SOURCE_GROUP_MEMBERS = {
	[SOURCE_GROUP.FILES]: ['approval', 'file_review'],
	[SOURCE_GROUP.TEAMS]: ['teamadmin', 'teamexpiry_team'],
	[SOURCE_GROUP.ADMINISTRATION]: ['teamexpiry_admin'],
}

/**
 * One glyph per group. Files keeps the approval provider's Folder — the
 * team tab bar's glyph for files. Teams is the group-of-people glyph the
 * app uses for a team elsewhere. Administration is a shield with a crown:
 * the instance's authority, distinct from Team admin's shield-with-person
 * that now lives inside Teams.
 */
export const SOURCE_GROUP_ICONS = {
	[SOURCE_GROUP.FILES]: 'Folder',
	[SOURCE_GROUP.TEAMS]: 'AccountGroupOutline',
	[SOURCE_GROUP.ADMINISTRATION]: 'ShieldCrownOutline',
}

export function sourceGroupLabel(key) {
	switch (key) {
	case SOURCE_GROUP.FILES:
		// TRANSLATORS: My Work source tab — every file-related source (approvals, file reviews)
		return t('teamhub', 'Files')
	case SOURCE_GROUP.TEAMS:
		// TRANSLATORS: My Work source tab — the housekeeping a team's own admin owes their team
		return t('teamhub', 'Teams')
	case SOURCE_GROUP.ADMINISTRATION:
		// TRANSLATORS: My Work source tab — work aimed at a Nextcloud administrator in the administration area
		return t('teamhub', 'Administration')
	default:
		return key
	}
}

/** The group a provider belongs to, or null when it is its own tab. */
export function sourceGroupOf(providerId) {
	for (const [group, members] of Object.entries(SOURCE_GROUP_MEMBERS)) {
		if (members.includes(providerId)) {
			return group
		}
	}
	return null
}

/**
 * The source bar: All, then one entry per registered, available source —
 * a provider on its own, or a group standing in for its members at the
 * position of the first member. A pure function so it can be tested.
 *
 * A source with nothing in it still gets a tab, showing zero: "no file
 * work waiting on me" is information, and a tab that vanishes when it
 * empties makes the bar reflow every refresh. A source that is *not
 * available* (its app is not installed, its module is off) gets no tab:
 * that is not an empty queue, it is no queue.
 *
 * `active` is the current `providerId` filter. A stored value that names a
 * member of a group (a preference from before 4.9.17 holding `approval`)
 * lights the group's tab, so the bar agrees with the rows.
 *
 * @param {Array<{id: string, name: string, enabled?: boolean, available?: boolean, group?: string|null}>} providers
 * @param {Object<string, number>} counts  providerId → count from the payload
 * @param {string} active  the current providerId filter ('' = All)
 * @return {Array<{key: string, label: string, icon: string, count: number|null, active: boolean, members: string[]}>}
 */
export function buildSourceTabs(providers, counts = {}, active = '') {
	const tabs = [{
		key: '',
		label: t('teamhub', 'All'),
		icon: 'ViewGrid',
		// No number on All: the summary cards above already total the
		// queue, and repeating it here would be the same fact twice.
		count: null,
		active: !active,
		members: [],
	}]
	const seen = new Set()
	for (const p of providers) {
		if (p.enabled === false || p.available === false) {
			continue
		}
		const group = p.group ?? sourceGroupOf(p.id)
		if (group === null) {
			tabs.push({
				key: p.id,
				label: p.name,
				icon: PROVIDER_ICONS[p.id] || FALLBACK_ICON,
				count: counts[p.id] || 0,
				active: active === p.id,
				members: [p.id],
			})
			continue
		}
		if (seen.has(group)) {
			continue
		}
		seen.add(group)
		// The members this instance actually has, in the group's own order.
		const members = SOURCE_GROUP_MEMBERS[group].filter(id =>
			providers.some(q => q.id === id && q.enabled !== false && q.available !== false))
		tabs.push({
			key: group,
			label: sourceGroupLabel(group),
			icon: SOURCE_GROUP_ICONS[group] || FALLBACK_ICON,
			count: members.reduce((sum, id) => sum + (counts[id] || 0), 0),
			active: active === group || members.includes(active),
			members,
		})
	}
	return tabs
}

/**
 * The Source dropdown's options — the same vocabulary as the tabs, so the
 * two controls never disagree about what a source is. An unavailable
 * provider is listed disabled, as before; an unavailable group is one whose
 * members are all unavailable.
 *
 * @param {Array<{id: string, name: string, enabled?: boolean, available?: boolean, group?: string|null}>} providers
 * @return {Array<{key: string, label: string, available: boolean}>}
 */
export function sourceOptions(providers) {
	const out = []
	const seen = new Set()
	for (const p of providers) {
		if (p.enabled === false) {
			continue
		}
		const group = p.group ?? sourceGroupOf(p.id)
		if (group === null) {
			out.push({ key: p.id, label: p.name, available: p.available !== false })
			continue
		}
		if (seen.has(group)) {
			continue
		}
		seen.add(group)
		const members = providers.filter(q => (q.group ?? sourceGroupOf(q.id)) === group && q.enabled !== false)
		out.push({
			key: group,
			label: sourceGroupLabel(group),
			available: members.some(q => q.available !== false),
		})
	}
	return out
}

// ── Providers + resource types ──────────────────────────────────────────

/**
 * Icon per resource type — **the same glyphs the team tab bar uses**.
 *
 * A Deck card gets `CardText`, a file gets `Folder`, a decision gets `Gavel`,
 * exactly as in `TeamView.buildAllTabDescriptors()`. That is the point: the
 * row tells you which tab the item lives in before you read a word, and a
 * user who has learned the tab bar has already learned this.
 *
 * Keep these in sync with the tab descriptors. If a tab's icon changes, this
 * changes with it — they are the same affordance seen twice.
 */
export const RESOURCE_TYPE_ICONS = {
	deck_card: 'CardText',
	file: 'Folder',
	decision: 'Gavel',
	meeting: 'Calendar',
	// v4.5.45 — a resource awaiting an admin's review. Same glyph as the
	// Team admin category, because this provider is the only thing in it.
	team_resource: 'ShieldAccountOutline',
	// v4.6.17 — a person asking to be let into a team. The verb the admin is
	// being asked for, rather than a second shield.
	team_join_request: 'AccountPlusOutline',
	// v4.6.13 — a team approaching its expiration date, and a request to push
	// that date back. A calendar glyph with a clock on it: the subject is a
	// date running out, which is what CalendarClock says at a glance and what
	// ShieldAccountOutline (an admin-role glyph) would not.
	team_expiry: 'CalendarClock',
	team_expiry_request: 'CalendarClock',
	// v4.9.5 — an OpenProject work package. The Project info widget's glyph,
	// because that widget is where the team meets the same project.
	openproject_work_package: 'BriefcaseOutline',
	// v4.9.7 — a milestone of the project: a date the whole project is
	// heading for, not a task of yours. The flag says "marker", not "job".
	openproject_milestone: 'FlagOutline',
}

/** Same glyphs, keyed by provider, for the source chip. */
export const PROVIDER_ICONS = {
	deck: 'CardText',
	approval: 'Folder',
	decisions: 'Gavel',
	meetings: 'Calendar',
	teamadmin: 'ShieldAccountOutline',
	teamexpiry_team: 'CalendarClock',
	teamexpiry_admin: 'CalendarClock',
	// v4.8.18 — file reviews. Deliberately not `approval`'s plain Folder: the
	// two providers both emit `file` rows and sit next to each other in the
	// queue, so they need to be distinguishable at a glance.
	file_review: 'FileEyeOutline',
	openproject: 'BriefcaseOutline',
}

export const FALLBACK_ICON = 'Puzzle'

export function resourceTypeLabel(type) {
	switch (type) {
	case 'deck_card':
		return t('teamhub', 'Deck card')
	case 'file':
		return t('teamhub', 'File')
	case 'decision':
		return t('teamhub', 'Decision')
	case 'meeting':
		// TRANSLATORS: the kind of thing a My Work row is — a calendar meeting
		return t('teamhub', 'Meeting')
	case 'team_resource':
		// TRANSLATORS: the kind of thing a My Work row is — an app resource connected to a team
		return t('teamhub', 'Team resource')
	case 'team_join_request':
		// TRANSLATORS: the kind of thing a My Work row is — somebody's pending request to join a team
		return t('teamhub', 'Membership request')
	case 'team_expiry':
		// TRANSLATORS: the kind of thing a My Work row is — a team's expiration date
		return t('teamhub', 'Team expiration')
	case 'team_expiry_request':
		// TRANSLATORS: the kind of thing a My Work row is — a request to extend a team's expiration date
		return t('teamhub', 'Extension request')
	case 'openproject_work_package':
		// TRANSLATORS: the kind of thing a My Work row is — a work package in OpenProject
		return t('teamhub', 'Work package')
	case 'openproject_milestone':
		// TRANSLATORS: the kind of thing a My Work row is — a milestone of an OpenProject project
		return t('teamhub', 'Milestone')
	default:
		return type
	}
}

/**
 * Labels for the source statuses the Status filter offers (v4.9.7).
 *
 * A status is a provider's own machine key (`assigned`, `due_soon`, …) and
 * used to be listed raw. The keys every provider shares get a word here;
 * anything unknown still falls back to the key, so a new provider's
 * statuses show up without a change in this file — just less prettily.
 *
 * @param {string} status
 * @return {string}
 */
export function statusLabel(status) {
	switch (status) {
	case 'assigned':
		// TRANSLATORS: My Work status filter option
		return t('teamhub', 'Assigned to me')
	case 'authored':
		// TRANSLATORS: My Work status filter option — work packages the viewer created that nobody is assigned to
		return t('teamhub', 'Created by me, unassigned')
	case 'overdue':
		return t('teamhub', 'Overdue')
	case 'due_today':
		// TRANSLATORS: My Work status filter option
		return t('teamhub', 'Due today')
	case 'due_soon':
		// TRANSLATORS: My Work status filter option — due inside the Upcoming horizon
		return t('teamhub', 'Due soon')
	case 'updated':
		// TRANSLATORS: My Work status filter option — changed recently in the source app
		return t('teamhub', 'Updated recently')
	case 'milestone':
		// TRANSLATORS: My Work status filter option — an upcoming project milestone
		return t('teamhub', 'Upcoming milestone')
	case 'completed':
		return t('teamhub', 'Completed')
	default:
		return status
	}
}

/**
 * The notice a provider warning becomes (v4.9.7). Mirrors
 * `WorkItemPage::WARN_*`. Returns `{ text, tone, action }` where `action`
 * names what the notice offers: `reconnect` (the personal settings page) or
 * nothing.
 *
 * @param {string} code a WARN_* code
 * @param {string} providerName translated source name
 * @return {{text: string, tone: string, action: string|null}|null}
 */
export function providerWarning(code, providerName) {
	switch (code) {
	case 'auth_required':
		return {
			text: t('teamhub', '{source} no longer accepts your connection. Reconnect your account in your personal settings.', { source: providerName }),
			tone: 'warning',
			action: 'reconnect',
		}
	case 'partial':
		return {
			text: t('teamhub', 'Some {source} projects could not be read. The rows shown are complete for the projects that answered.', { source: providerName }),
			tone: 'info',
			action: null,
		}
	case 'budget':
		return {
			text: t('teamhub', '{source} answered slowly, so not every project was read this time. Refresh to try again.', { source: providerName }),
			tone: 'info',
			action: null,
		}
	default:
		return null
	}
}

// ── Formatting ──────────────────────────────────────────────────────────

/**
 * Human due-date label. Returns `{ text, tone }` where tone is one of
 * `overdue | today | soon | normal | none`, so the row can convey urgency
 * with an icon and a word as well as a colour — WCAG 1.4.1 requires that
 * status is not carried by colour alone.
 */
export function formatDue(dueAt, nowSeconds) {
	if (!dueAt) {
		return { text: '', tone: 'none' }
	}
	const now = nowSeconds || Math.floor(Date.now() / 1000)
	// Day boundaries in the reader's zone, each a real midnight rather than a
	// multiple of 86400 added to one — the day either side of a DST change is
	// 23 or 25 hours long, which moved every one of these cutoffs.
	const today = todayIso()
	const startOfToday = fromDateInput(today)
	const startOfTomorrow = fromDateInput(shiftIsoDate(today, { days: 1 }))
	const startOfDayAfter = fromDateInput(shiftIsoDate(today, { days: 2 }))

	if (dueAt < now && dueAt < startOfToday) {
		const days = Math.max(1, Math.round((startOfToday - dueAt) / 86400))
		return {
			// Real plural, not t() with a count: "Overdue by 1 days" is the
			// kind of thing that makes a product look unfinished, and several
			// project languages need more than two forms.
			text: n('teamhub', 'Overdue by {n} day', 'Overdue by {n} days', days, { n: days }),
			tone: 'overdue',
		}
	}
	if (dueAt >= startOfToday && dueAt < startOfTomorrow) {
		return {
			// TRANSLATORS: due-date label on a work item
			text: dueAt < now ? t('teamhub', 'Due today (passed)') : t('teamhub', 'Today'),
			tone: dueAt < now ? 'overdue' : 'today',
		}
	}
	if (dueAt >= startOfTomorrow && dueAt < startOfDayAfter) {
		return { text: t('teamhub', 'Tomorrow'), tone: 'soon' }
	}
	return {
		text: formatDate(dueAt * 1000, { day: 'numeric', month: 'short' }),
		tone: 'normal',
	}
}

/** Absolute date+time, for the `title` tooltip behind a relative label. */
export function formatAbsolute(seconds) {
	if (!seconds) {
		return ''
	}
	return formatDateTime(seconds * 1000)
}
