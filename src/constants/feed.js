/**
 * TeamHub — "What's new" feed constants (v4.5.26).
 *
 * Everything the redesigned feed needs to classify, colour and group an item,
 * in one place so the view, the row and the control rail cannot drift apart.
 *
 * Deliberately free of Vue: this module is imported by three components and a
 * couple of them only want the pure functions.
 */

import { translate as t } from '@nextcloud/l10n'

// ── Source tabs ──────────────────────────────────────────────────────────
// The ids the server's `sourceCounts` block is keyed on. 'all' is the
// resting state and never filters anything.
export const FEED_TAB_ALL = 'all'
export const FEED_TAB_TEAM = 'team'
export const FEED_TAB_PUBLIC = 'public'
export const FEED_TAB_TALK = 'talk'
export const FEED_TAB_MENTIONS = 'mentions'
export const FEED_TAB_DECISIONS = 'decisions'
// v4.9.7 — OpenProject news. Its own source, its own tab: a news item is
// not a TeamHub message, and hiding it under Team would blur the one thing
// the feed must keep clear — where a row comes from.
export const FEED_TAB_OPENPROJECT = 'openproject'

/**
 * Note that these do not partition the feed. Team / Public / Talk /
 * OpenProject do, but Mentions and Decisions are lenses over the same rows —
 * a decision is also a team message. The counts are therefore not expected
 * to sum to All.
 */
export const FEED_TABS = [
	FEED_TAB_ALL,
	FEED_TAB_TEAM,
	FEED_TAB_PUBLIC,
	FEED_TAB_TALK,
	FEED_TAB_OPENPROJECT,
	FEED_TAB_DECISIONS,
	FEED_TAB_MENTIONS,
]

// ── OpenProject news (v4.9.7) ────────────────────────────────────────────
// The feed's OpenProject source is the project's *news* — what a project
// manager writes for the whole project to read. Work-package edits were
// the first draft's source and are not in the feed (Justin, 2026-09-14:
// "What's new is for news and messages").

/**
 * The notice the feed shows when the OpenProject source did not answer
 * cleanly (v4.9.7). `status` is the server's `sources.openproject` block.
 * Null when there is nothing to say (`ok`, `skipped`, or simply switched
 * off).
 *
 * @param {object} status { state, code, message, covered, skipped }
 * @return {{text: string, action: string|null}|null}
 */
export function openProjectSourceNotice(status) {
	if (!status || !status.state) {
		return null
	}
	switch (status.state) {
	case 'not_connected':
		return {
			text: t('teamhub', 'Connect your OpenProject account to see project news here.'),
			action: 'connect',
		}
	case 'auth_required':
		return {
			text: t('teamhub', 'OpenProject no longer accepts your connection. Reconnect your OpenProject account in your personal settings.'),
			action: 'connect',
		}
	case 'unavailable':
		return null
	case 'partial':
		return {
			text: t('teamhub', 'Some OpenProject projects could not be read. News from the projects that answered is shown.'),
			action: null,
		}
	case 'error':
		return {
			text: status.message || t('teamhub', 'OpenProject data could not be loaded.'),
			action: null,
		}
	default:
		return null
	}
}

/**
 * Tab labels. A function rather than a frozen object because `t()` must run
 * after the l10n bundle is registered, not at module-evaluation time.
 *
 * @param {string} id one of FEED_TABS
 * @return {string} the translated label
 */
export function feedTabLabel(id) {
	switch (id) {
	case FEED_TAB_TEAM:
		return t('teamhub', 'Team messages')
	case FEED_TAB_PUBLIC:
		return t('teamhub', 'Public messages')
	case FEED_TAB_OPENPROJECT:
		// TRANSLATORS: feed tab showing OpenProject work-package activity
		return t('teamhub', 'OpenProject')
	case FEED_TAB_TALK:
		return t('teamhub', 'Talk polls & threads')
	case FEED_TAB_MENTIONS:
		return t('teamhub', 'Mentions')
	case FEED_TAB_DECISIONS:
		// v4.5.45 — "proposals", not "decisions": what is listed here is
		// still open, and it is not a decision until an approver has acted
		// on it. The tab *key* stays `decisions` — it is persisted in user
		// preferences and travels as a query param, so renaming it would
		// reset everyone's feed for a label change.
		// TRANSLATORS: feed tab showing decision proposals that are still open for discussion
		return t('teamhub', 'Open proposals')
	default:
		return t('teamhub', 'All')
	}
}

// ── Periods ──────────────────────────────────────────────────────────────
// Mirrors MessageService::FEED_PERIODS. The *range* is resolved in the
// browser, never on the server: "today" means the viewer's today, and the
// server's timezone is not theirs. Same reasoning as the My Work snooze
// presets moving client-side in 4.5.24.
export const FEED_PERIODS = ['all', 'today', 'week', 'month', 'custom']

/**
 * Resolve a period id to the `{ from, to }` unix-second pair the feed
 * endpoint takes. Both are inclusive; 0 means unbounded on that side.
 *
 * @param {string} period one of FEED_PERIODS
 * @param {number} customFrom unix seconds, only read when period === 'custom'
 * @param {number} customTo unix seconds, only read when period === 'custom'
 * @param {Date} [now] injectable for tests
 * @return {{from: number, to: number}} inclusive range in unix seconds
 */
export function resolvePeriodRange(period, customFrom, customTo, now = new Date()) {
	const startOfDay = (d) => {
		const c = new Date(d)
		c.setHours(0, 0, 0, 0)
		return Math.floor(c.getTime() / 1000)
	}

	switch (period) {
	case 'today':
		return { from: startOfDay(now), to: 0 }
	case 'week': {
		// Rolling seven days rather than "since Monday". A feed answers
		// "what did I miss", and on a Monday morning "this week" meaning
		// the last few hours is not that answer.
		const d = new Date(now)
		d.setDate(d.getDate() - 6)
		return { from: startOfDay(d), to: 0 }
	}
	case 'month': {
		const d = new Date(now)
		d.setDate(d.getDate() - 29)
		return { from: startOfDay(d), to: 0 }
	}
	case 'custom': {
		const from = Number.isFinite(customFrom) ? Math.max(0, customFrom) : 0
		let to = Number.isFinite(customTo) ? Math.max(0, customTo) : 0
		// A date picker hands back midnight. Without this, picking the same
		// day for both ends selects a zero-length window and the user sees
		// an empty feed with no clue why.
		if (to > 0) {
			to += 86399
		}
		return from > 0 && to > 0 && from > to
			? { from: to - 86399, to: from + 86399 }
			: { from, to }
	}
	default:
		return { from: 0, to: 0 }
	}
}

// ── Item typing ──────────────────────────────────────────────────────────

/**
 * Classify a feed row into one of the visual kinds the card renders.
 *
 * Order matters: a Talk row is a Talk row before anything else, a system post
 * is a system post before it is a plain message, and `is_public` only decides
 * the kind once the message type is the unremarkable 'normal' — a public
 * *decision* is still a decision, and losing that would be worse than losing
 * the public tint (the Public badge carries that fact anyway).
 *
 * @param {object} item a feed row
 * @return {string} one of: talk-poll, talk-thread, system, question, poll, decision, public, message
 */
export function feedItemKind(item) {
	if (item.source === 'talk-poll') return 'talk-poll'
	if (item.source === 'talk-thread') return 'talk-thread'
	if (item.source === 'talk-mention') return 'talk-mention'
	if (item.source === 'openproject') return 'openproject'
	if (item.isSystem) return 'system'

	const type = item.messageType || 'normal'
	if (type === 'question') return 'question'
	if (type === 'poll') return 'poll'
	if (type === 'decision') return 'decision'

	return item.isPublic ? 'public' : 'message'
}

/**
 * The All tab without the same news item twice (v4.9.9).
 *
 * A news item read live from OpenProject and the team message the mirror
 * wrote for it are the same thing said twice, and on a tab that lists
 * both sources they would sit a few rows apart. The live card stays — it
 * is the one with the project link and the author — and the mirrored
 * message goes, but only when its news item is on the same page: the
 * mirrored post is a message like any other on the Team tab, and on All
 * when the news source is switched off or the card is on another page.
 *
 * @param {Array<object>} items the page as the server sent it
 * @return {Array<object>} the same rows, minus the mirrored duplicates
 */
export function dedupeMirroredNews(items) {
	const live = new Set()
	for (const item of items) {
		if (item.source === 'openproject' && item.news && item.news.id != null) {
			live.add(String(item.news.id))
		}
	}
	if (live.size === 0) {
		return items
	}
	return items.filter(item => !(
		item.source !== 'openproject'
		&& item.origin?.kind === 'openproject'
		&& live.has(String(item.origin.newsId))
	))
}

/**
 * The badge word for a kind. Every card carries one, so colour is never the
 * only thing distinguishing two kinds (WCAG 1.4.1).
 *
 * @param {string} kind from feedItemKind()
 * @return {string} the translated badge label
 */
export function feedKindLabel(kind) {
	switch (kind) {
	case 'talk-poll':
		// TRANSLATORS: badge on a feed card for a poll created in a Talk chat
		return t('teamhub', 'Talk poll')
	case 'talk-thread':
		// TRANSLATORS: badge on a feed card for a discussion thread in a Talk chat
		return t('teamhub', 'Talk thread')
	case 'talk-mention':
		// TRANSLATORS: badge on a feed card for a Talk chat message that names you
		return t('teamhub', 'Talk mention')
	case 'openproject':
		// TRANSLATORS: badge on a feed card for a news item from OpenProject
		return t('teamhub', 'OpenProject news')
	case 'system':
		// TRANSLATORS: badge on a feed card for a message posted automatically by TeamHub, not by a person
		return t('teamhub', 'System')
	case 'question':
		return t('teamhub', 'Question')
	case 'poll':
		return t('teamhub', 'Poll')
	case 'decision':
		return t('teamhub', 'Decision')
	case 'public':
		return t('teamhub', 'Public message')
	default:
		return t('teamhub', 'Team message')
	}
}

/**
 * CSS-variable suffix for a kind's glyph-chip tone. Maps several kinds onto
 * one tone on purpose — seven distinct hues in one list is noise, so the two
 * Talk kinds share the cyan and a public post borrows the green.
 *
 * @param {string} kind from feedItemKind()
 * @return {string} the token family name, e.g. 'talk' for --th-feed-talk-*
 */
export function feedKindTone(kind) {
	switch (kind) {
	case 'talk-poll':
	case 'talk-thread':
	case 'talk-mention':
		return 'talk'
	case 'openproject':
		return 'openproject'
	case 'system':
		return 'system'
	case 'question':
		return 'question'
	case 'poll':
		return 'poll'
	case 'decision':
		return 'decision'
	case 'public':
		return 'public'
	default:
		return 'message'
	}
}

// ── Date grouping ────────────────────────────────────────────────────────

/** Order the day sections render in. 'undated' is always last. */
export const FEED_DATE_BUCKETS = ['today', 'yesterday', 'earlier', 'undated']

/**
 * Bucket a feed row into the feed's date sections.
 *
 * Computed against the viewer's own clock — the server sends unix seconds and
 * says nothing about which day they fall on, which is correct: it does not
 * know what day it is where the reader is sitting.
 *
 * Takes the whole row rather than a timestamp because a Talk poll on a schema
 * with no timestamp column arrives with `date_unknown` set, and "no date" is a
 * different answer from "the epoch" — v4.5.26 stopped inventing one.
 *
 * @param {object} item a feed row
 * @param {Date} [now] injectable for tests
 * @return {string} one of FEED_DATE_BUCKETS
 */
export function feedDateBucket(item, now = new Date()) {
	const seconds = item?.created_at || 0
	if (item?.date_unknown || !seconds) return 'undated'

	const startOfToday = new Date(now)
	startOfToday.setHours(0, 0, 0, 0)
	const todayStart = Math.floor(startOfToday.getTime() / 1000)
	const yesterdayStart = todayStart - 86400

	if (seconds >= todayStart) return 'today'
	if (seconds >= yesterdayStart) return 'yesterday'
	return 'earlier'
}

/**
 * Heading for a date bucket.
 *
 * @param {string} bucket from feedDateBucket()
 * @return {string} the translated section heading
 */
export function feedBucketLabel(bucket) {
	switch (bucket) {
	case 'today':
		return t('teamhub', 'Today')
	case 'yesterday':
		return t('teamhub', 'Yesterday')
	case 'undated':
		// TRANSLATORS: heading over feed items whose source app does not record when they were created
		return t('teamhub', 'No date')
	default:
		return t('teamhub', 'Earlier')
	}
}
