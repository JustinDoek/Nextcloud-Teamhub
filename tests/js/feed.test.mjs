/**
 * src/constants/feed.js — the What's new vocabulary, with the OpenProject
 * news source added in v4.9.7: its tab, its kind, its tone, and the notice
 * the feed shows when the source did not answer cleanly.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
    FEED_TABS,
    FEED_TAB_OPENPROJECT,
    feedItemKind,
    feedKindLabel,
    feedKindTone,
    feedTabLabel,
    openProjectSourceNotice,
    feedDateBucket,
    dedupeMirroredNews,
} from '../../src/constants/feed.js'

// ── The source ────────────────────────────────────────────────────────

test('OpenProject is a tab of its own, between Talk and the lenses', () => {
    assert.ok(FEED_TABS.includes(FEED_TAB_OPENPROJECT))
    assert.ok(FEED_TABS.indexOf(FEED_TAB_OPENPROJECT) > FEED_TABS.indexOf('talk'))
    assert.ok(FEED_TABS.indexOf(FEED_TAB_OPENPROJECT) < FEED_TABS.indexOf('decisions'))
    assert.equal(feedTabLabel(FEED_TAB_OPENPROJECT), 'OpenProject')
})

test('an OpenProject news row is its own kind, before any message typing', () => {
    // `source` wins over `messageType`, the way Talk rows win.
    assert.equal(feedItemKind({ source: 'openproject', activityType: 'news', messageType: 'decision' }), 'openproject')
    assert.equal(feedKindLabel('openproject'), 'OpenProject news')
    assert.equal(feedKindTone('openproject'), 'openproject')
    // Nothing else changed.
    assert.equal(feedItemKind({ source: 'team', messageType: 'decision' }), 'decision')
    assert.equal(feedItemKind({ source: 'talk-poll' }), 'talk-poll')
})

test('a news row is dated by its creation time like any other row', () => {
    const now = new Date('2026-09-14T12:00:00')
    const todayStart = Math.floor(new Date('2026-09-14T00:00:00').getTime() / 1000)
    assert.equal(feedDateBucket({ source: 'openproject', created_at: todayStart + 3600 }, now), 'today')
    assert.equal(feedDateBucket({ source: 'openproject', created_at: todayStart - 3600 }, now), 'yesterday')
})

// ── The mirror (v4.9.9) ───────────────────────────────────────────────

test('the All tab shows a mirrored news item once, and only when its card is there', () => {
    const live = { id: 'op:abc:4:news:7', source: 'openproject', news: { id: 7, url: 'https://op.example/news/7' } }
    const mirrored = { id: 41, source: 'team', isSystem: true, origin: { kind: 'openproject', newsId: 7, url: 'https://op.example/news/7' } }
    const other = { id: 42, source: 'team', isSystem: true, origin: { kind: 'openproject', newsId: 8, url: null } }
    const plain = { id: 43, source: 'team' }

    assert.deepEqual(dedupeMirroredNews([live, mirrored, other, plain]), [live, other, plain])
    // The news source off, or its card on another page: the message stands.
    assert.deepEqual(dedupeMirroredNews([mirrored, other, plain]), [mirrored, other, plain])
    assert.deepEqual(dedupeMirroredNews([]), [])
})

// ── The notice ────────────────────────────────────────────────────────

test('the source notice says what to do, and nothing for a clean answer', () => {
    assert.equal(openProjectSourceNotice(null), null)
    assert.equal(openProjectSourceNotice({ state: 'ok' }), null)
    assert.equal(openProjectSourceNotice({ state: 'skipped' }), null)
    assert.equal(openProjectSourceNotice({ state: 'unavailable' }), null, 'no integration app: nothing to nag about')

    const notConnected = openProjectSourceNotice({ state: 'not_connected', code: 'user_not_connected' })
    assert.equal(notConnected.action, 'connect')
    assert.match(notConnected.text, /Connect your OpenProject account/)

    const auth = openProjectSourceNotice({ state: 'auth_required', code: 'auth_failed' })
    assert.equal(auth.action, 'connect')

    const partial = openProjectSourceNotice({ state: 'partial', code: 'permission_denied' })
    assert.equal(partial.action, null)
    assert.match(partial.text, /Some OpenProject projects/)

    const error = openProjectSourceNotice({ state: 'error', code: 'api_unavailable', message: 'OpenProject cannot be reached right now.' })
    assert.equal(error.text, 'OpenProject cannot be reached right now.', 'the server sentence is used when there is one')
    assert.equal(openProjectSourceNotice({ state: 'error', message: '' }).text, 'OpenProject data could not be loaded.')
})
