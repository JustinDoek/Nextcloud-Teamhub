/**
 * The team's Talk conversation, read for the Home view's Chat widget and the
 * Chat tab's unread badge (v4.20.2).
 *
 * Pure helpers only: what counts as a chat message, how a message reads as
 * plain text, how a thread list becomes rows, which polls a shared-items
 * answer names. The requests themselves live in `talkApi.js` so these can be
 * unit-tested without a browser (tests/js/talkChat.test.mjs).
 *
 * The shapes are Talk's public OCS responses, checked against Talk 25.0.8
 * (`lib/ResponseDefinitions.php`): `TalkRoom` from `GET api/v4/room/{token}`,
 * `TalkThreadInfo` from `GET api/v1/chat/{token}/threads/recent`,
 * `TalkChatMessage` inside it and from `GET api/v1/chat/{token}/share`,
 * `TalkPoll` from `GET api/v1/poll/{token}/{pollId}`.
 *
 * v4.20.4 — the widget shows threads and polls only; the running chat moves
 * too fast for a dashboard card (Justin). The unread count stays on the Chat
 * tab's badge.
 */

/** Talk's message types that are something a person said. */
const SPOKEN_TYPES = new Set(['comment', 'voice-message', 'record-audio', 'record-video'])

/**
 * Rich-object types that name a participant. Their placeholder renders with
 * an `@`, the way Talk itself shows a mention.
 */
const MENTION_TYPES = new Set(['user', 'guest', 'call', 'user-group', 'circle', 'email', 'federated_user'])

/** Talk's poll status: 0 open, 1 closed, 2 draft. */
export const POLL_STATUS_OPEN = 0

/**
 * Whether a chat entry is a message a person wrote, rather than a system line
 * ("Lieke joined the call"), a deleted message or a command.
 * @param {object} m a TalkChatMessage
 * @return {boolean}
 */
export function isChatMessage(m) {
    if (!m || typeof m !== 'object') return false
    if (m.deleted === true) return false
    if (typeof m.systemMessage === 'string' && m.systemMessage !== '') return false
    return SPOKEN_TYPES.has(m.messageType)
}

/**
 * A message as plain text: every `{placeholder}` replaced by the name of the
 * rich object it stands for. Rendered as text, never as HTML, so nothing a
 * participant typed can reach the DOM as markup.
 * @param {object} m a TalkChatMessage
 * @return {string}
 */
export function messagePlainText(m) {
    const raw = typeof m?.message === 'string' ? m.message : ''
    const params = (m?.messageParameters && typeof m.messageParameters === 'object') ? m.messageParameters : {}
    return raw.replace(/\{([a-z0-9_-]+)\}/gi, (whole, key) => {
        const p = params[key]
        if (!p || typeof p !== 'object') return whole
        const name = typeof p.name === 'string' && p.name !== '' ? p.name : String(p.id ?? '')
        if (name === '') return whole
        return MENTION_TYPES.has(p.type) ? '@' + name : name
    }).trim()
}

/**
 * Rows for the widget's Threads tab, from Talk's thread list
 * (`GET api/v1/chat/{token}/threads/recent`, a list of TalkThreadInfo:
 * `{ thread: { id, title, lastActivity, numReplies, ... }, first, last }`).
 *
 * Most recently active first, whatever order Talk sent. The title falls back
 * to the first message's text, and the preview is the newest reply; a thread
 * nobody has answered yet has no preview, its first message already being the
 * title or right under it.
 *
 * @param {Array<object>} list TalkThreadInfo list
 * @param {number} limit
 * @return {Array<{id: number, title: string, replies: number, lastActivity: number, first: object|null, last: object|null, preview: object|null}>}
 */
/**
 * v4.20.22 — the path of the team conversation, or of one of its threads, to
 * pass to `generateUrl()`. Talk 25.0.8 opens a thread from `?threadId=` on
 * `/call/{token}`: its own thread links are router links with exactly that
 * query, and the conversation view reads it from the route.
 * @param {string} token
 * @param {number|null} [threadId]
 * @return {string}
 */
export function talkConversationPath(token, threadId = null) {
    const path = '/call/' + encodeURIComponent(String(token || ''))
    const id = Number(threadId)
    return Number.isSafeInteger(id) && id > 0 ? `${path}?threadId=${id}` : path
}

/**
 * v4.20.23 — the hash Talk scrolls to a message with (its own quote links).
 * @param {number} messageId
 * @return {string}
 */
export function talkMessageHash(messageId) {
    const id = Number(messageId)
    return Number.isSafeInteger(id) && id > 0 ? `#message_${id}` : ''
}

/**
 * v4.20.23 — the pinned messages as rows, newest pin first. The answer of
 * `share?objectType=pinned` is id-keyed; the pin's details are in
 * `metaData` (`pinnedActorDisplayName`, `pinnedAt`, `pinnedUntil`). The one
 * the viewer hid in Talk (`hiddenPinnedId`) stays hidden here, and a pin past
 * its end is gone even before Talk's job unpins it.
 * @param {object|Array} shared
 * @param {number} hiddenId
 * @param {number} limit
 * @param {number} [nowSecs]
 * @return {Array<object>}
 */
export function pinnedRows(shared, hiddenId, limit, nowSecs = Math.floor(Date.now() / 1000)) {
    const list = Array.isArray(shared) ? shared : Object.values(shared && typeof shared === 'object' ? shared : {})
    const hidden = Number(hiddenId) || 0
    return list
        .filter(m => m && typeof m === 'object' && Number(m.id) > 0 && Number(m.id) !== hidden && m.deleted !== true)
        .filter(m => {
            const until = Number(m.metaData?.pinnedUntil)
            return !(Number.isFinite(until) && until > 0 && until <= nowSecs)
        })
        .map(m => {
            const meta = m.metaData && typeof m.metaData === 'object' ? m.metaData : {}
            const threadId = Number(m.threadId)
            return {
                id: Number(m.id),
                text: messagePlainText(m),
                author: typeof m.actorDisplayName === 'string' ? m.actorDisplayName : '',
                timestamp: Number(m.timestamp) || 0,
                pinnedBy: typeof meta.pinnedActorDisplayName === 'string' ? meta.pinnedActorDisplayName : '',
                pinnedAt: Number(meta.pinnedAt) || 0,
                threadId: m.isThread === true && Number.isSafeInteger(threadId) && threadId > 0 ? threadId : 0,
            }
        })
        .filter(row => row.text !== '')
        .sort((a, b) => b.pinnedAt - a.pinnedAt)
        .slice(0, Math.max(0, limit))
}

export function threadRows(list, limit) {
    const rows = (Array.isArray(list) ? list : [])
        .filter(info => Number(info?.thread?.id) > 0)
        .map(info => {
            const t = info.thread
            const first = info.first && typeof info.first === 'object' ? info.first : null
            const last = info.last && typeof info.last === 'object' ? info.last : null
            const replies = Number(t.numReplies)
            const title = typeof t.title === 'string' ? t.title.trim() : ''
            const hasReply = !!last && (!first || Number(last.id) !== Number(first.id))
            return {
                id: Number(t.id),
                title: title || (first ? messagePlainText(first) : ''),
                replies: Number.isFinite(replies) && replies > 0 ? Math.floor(replies) : 0,
                lastActivity: Number(t.lastActivity) || Number(last?.timestamp) || 0,
                first,
                last,
                preview: hasReply && isChatMessage(last) ? last : null,
            }
        })
    rows.sort((a, b) => b.lastActivity - a.lastActivity)
    return rows.slice(0, Math.max(0, limit))
}

/**
 * The poll ids a shared-items answer (`objectType=poll`) names, newest first,
 * each once. The answer is an id-keyed map of poll messages; the poll itself
 * is the message's `object` parameter, of type `talk-poll`.
 * @param {Array<object>|object} shared
 * @return {number[]}
 */
export function pollIdsFromShared(shared) {
    const rows = Array.isArray(shared) ? shared : Object.values(shared || {})
    const seen = new Set()
    const ids = []
    rows
        .slice()
        .sort((a, b) => Number(b?.id) - Number(a?.id))
        .forEach(m => {
            const obj = m?.messageParameters?.object
            if (!obj || obj.type !== 'talk-poll') return
            const id = Number(obj.id)
            if (!Number.isInteger(id) || id <= 0 || seen.has(id)) return
            seen.add(id)
            ids.push(id)
        })
    return ids
}

/**
 * The room facts the badge and the widget's status line need, with Talk's
 * absent or odd values made safe.
 * @param {object|null} room a TalkRoom
 * @return {{unread: number, mention: boolean, hasCall: boolean, callStartTime: number, lastMessageId: number, readOnly: boolean}}
 */
export function roomStatus(room) {
    const unread = Number(room?.unreadMessages)
    const start = Number(room?.callStartTime)
    const lastId = Number(room?.lastMessage?.id)
    // `permissions` is the participant's combined final set (Attendee::
    // PERMISSIONS_*): 2 may start a call, 4 may join one. Absent means Talk
    // did not say; Talk itself still refuses what is not allowed, so the
    // widget does not hide the action on a missing field.
    const perms = Number(room?.permissions)
    const knownPerms = Number.isInteger(perms) && perms > 0
    return {
        unread: Number.isFinite(unread) && unread > 0 ? Math.floor(unread) : 0,
        mention: room?.unreadMention === true,
        hasCall: room?.hasCall === true,
        callStartTime: Number.isFinite(start) && start > 0 ? start : 0,
        lastMessageId: Number.isFinite(lastId) && lastId > 0 ? lastId : 0,
        // readOnly 1 = the conversation is locked; no votes, no calls.
        readOnly: Number(room?.readOnly) === 1,
        canStartCall: knownPerms ? (perms & PERMISSION_CALL_START) !== 0 : true,
        canJoinCall: knownPerms ? (perms & PERMISSION_CALL_JOIN) !== 0 : true,
        // participantFlags bit 1 (Participant::FLAG_IN_CALL): this viewer is
        // in the call right now, from this or another device.
        inCall: (Number(room?.participantFlags) & 1) === 1,
        // v4.20.23 — 128 may chat (Attendee::PERMISSIONS_CHAT): the quick reply.
        canChat: knownPerms ? (perms & PERMISSION_CHAT) !== 0 : true,
        // v4.20.23 — the newest pin, and the one this viewer hid in Talk
        // (pinned-messages capability; 0 when Talk does not send them).
        lastPinnedId: Number.isSafeInteger(Number(room?.lastPinnedId)) && Number(room?.lastPinnedId) > 0 ? Number(room.lastPinnedId) : 0,
        hiddenPinnedId: Number.isSafeInteger(Number(room?.hiddenPinnedId)) && Number(room?.hiddenPinnedId) > 0 ? Number(room.hiddenPinnedId) : 0,
        // v4.20.25 — who the viewer is in the room, and whether a moderator
        // (Participant::OWNER, MODERATOR, GUEST_MODERATOR): ending a poll.
        actorType: typeof room?.actorType === 'string' ? room.actorType : '',
        actorId: typeof room?.actorId === 'string' ? room.actorId : '',
        isModerator: MODERATOR_TYPES.has(Number(room?.participantType)),
    }
}

/** Talk's Participant::OWNER, MODERATOR and GUEST_MODERATOR. */
const MODERATOR_TYPES = new Set([1, 2, 6])

/**
 * v4.20.25 — whether the widget offers End poll: an open poll, and the viewer
 * is its author or a moderator, the rule Talk's `PollService::closePoll`
 * applies. Hidden from everybody else; Talk still refuses on its own.
 * @param {object} poll a TalkPoll
 * @param {object} status the store's talkStatus
 * @return {boolean}
 */
export function canClosePoll(poll, status) {
    if (!poll || status?.available !== true || status.readOnly) return false
    if (Number(poll.status) !== POLL_STATUS_OPEN) return false
    if (status.isModerator === true) return true
    return status.actorId !== '' && typeof status.actorId === 'string'
        && poll.actorType === status.actorType && poll.actorId === status.actorId
}

/**
 * v4.20.23 — whether the widget offers a reply: a participant, the
 * conversation not locked, and allowed to chat. Talk still refuses on its own.
 * @param {object} status the store's talkStatus
 * @return {boolean}
 */
export function canReply(status) {
    return status?.available === true && !status.readOnly && status.canChat !== false
}

/** Talk's Attendee::PERMISSIONS_CALL_START and PERMISSIONS_CALL_JOIN. */
const PERMISSION_CALL_START = 2
const PERMISSION_CALL_JOIN = 4
/** Talk's Attendee::PERMISSIONS_CHAT. */
const PERMISSION_CHAT = 128

/**
 * What a "meet" action does for this viewer right now: start a call, join
 * the running one, or nothing (the action is then hidden, not disabled).
 * @param {object} status the store's talkStatus
 * @return {'start'|'join'|null}
 */
export function callAction(status) {
    if (!status?.available || status.readOnly) return null
    if (status.hasCall) return status.canJoinCall ? 'join' : null
    return status.canStartCall ? 'start' : null
}

/**
 * Whether the viewer has already voted on a poll.
 * @param {object} poll a TalkPoll
 * @return {boolean}
 */
export function hasVoted(poll) {
    return Array.isArray(poll?.votedSelf) && poll.votedSelf.length > 0
}

/**
 * Whether a selection may be submitted: at least one option, and no more than
 * the poll allows (`maxVotes` 0 means any number).
 * @param {object} poll a TalkPoll
 * @param {number[]} selected option indexes
 * @return {boolean}
 */
export function canSubmitVote(poll, selected) {
    const n = Array.isArray(selected) ? selected.length : 0
    if (n < 1) return false
    const max = Number(poll?.maxVotes) || 0
    return max === 0 || n <= max
}

/**
 * One row per option with its vote count when Talk shows counts to this
 * viewer (after they voted on a public poll, or once it is closed), else
 * `null` for every count.
 * @param {object} poll a TalkPoll
 * @return {Array<{index: number, label: string, votes: number|null, mine: boolean}>}
 */
export function pollOptionRows(poll) {
    const options = Array.isArray(poll?.options) ? poll.options : []
    const votes = poll?.votes && typeof poll.votes === 'object' ? poll.votes : null
    const mine = new Set(Array.isArray(poll?.votedSelf) ? poll.votedSelf.map(Number) : [])
    return options.map((label, index) => ({
        index,
        label: String(label),
        votes: votes ? Number(votes['option-' + index] || 0) : null,
        mine: mine.has(index),
    }))
}
