/**
 * Requests to Talk's own OCS API for the team conversation (v4.20.2).
 *
 * Made from the browser, as the viewer: Talk decides what this person may
 * read, and a viewer who is not a participant gets Talk's 404. Nothing here
 * goes through TeamHub's server, so there is no new endpoint to gate and no
 * Talk table read on our side. Routes and parameters checked against Talk
 * 25.0.8; every version in our Nextcloud range (33-35) serves them.
 *
 * Every request here only reads, apart from casting a vote, (v4.20.23)
 * posting a reply in a thread and (v4.20.25) ending a poll, all explicit
 * actions: none of the reads
 * moves the viewer's read marker, so a glance at the Home view never marks
 * the conversation as read. (v4.20.4: the chat history request is gone. The
 * widget shows threads and polls, not the running chat, and a thread list is
 * its own endpoint.)
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const OCS_HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

function ocsData(response) {
    return response?.data?.ocs?.data ?? null
}

/** `GET api/v4/room/{token}` — unread count, mention, call state. */
export async function fetchTalkRoom(token) {
    const url = generateOcsUrl('apps/spreed/api/v4/room/{token}', { token })
    return ocsData(await axios.get(url, { headers: OCS_HEADERS }))
}

/**
 * `GET api/v1/chat/{token}/threads/recent` — the conversation's threads,
 * most recently active first, each as `{ thread, attendee, first, last }`
 * (TalkThreadInfo). Needs Talk's `threads` capability; a Talk without it
 * answers 404, which the caller treats as "no threads".
 */
export async function fetchRecentThreads(token, limit) {
    const url = generateOcsUrl('apps/spreed/api/v1/chat/{token}/threads/recent', { token })
    return ocsData(await axios.get(url, { headers: OCS_HEADERS, params: { limit } })) || []
}

/** `GET api/v1/chat/{token}/share?objectType=poll` — the poll messages. */
export async function fetchSharedPolls(token, limit) {
    const url = generateOcsUrl('apps/spreed/api/v1/chat/{token}/share', { token })
    const params = { objectType: 'poll', limit }
    return ocsData(await axios.get(url, { headers: OCS_HEADERS, params })) || {}
}

/**
 * v4.20.23 — `GET api/v1/chat/{token}/share?objectType=pinned` — the pinned
 * messages, id-keyed, newest pin first (Talk sorts them by `pinnedAt`). Needs
 * Talk's `pinned-messages` capability; the caller asks only when the room's
 * `lastPinnedId` says something is pinned.
 */
export async function fetchPinnedMessages(token, limit) {
    const url = generateOcsUrl('apps/spreed/api/v1/chat/{token}/share', { token })
    const params = { objectType: 'pinned', limit }
    return ocsData(await axios.get(url, { headers: OCS_HEADERS, params })) || {}
}

/**
 * v4.20.23 — `POST api/v1/chat/{token}` with `threadId`: a reply in the
 * thread, without quoting a message (Talk 25.0.8 `ChatController::
 * sendMessage`, `threads` capability). Answers with the posted message.
 */
export async function replyInThread(token, threadId, message) {
    const url = generateOcsUrl('apps/spreed/api/v1/chat/{token}', { token })
    return ocsData(await axios.post(url, { message, threadId }, { headers: OCS_HEADERS }))
}

/**
 * v4.20.25 — `DELETE api/v1/poll/{token}/{pollId}` ends an open poll (Talk
 * 25.0.8 `PollController::closePoll`: the author or a moderator, else 403).
 * Answers with the closed poll.
 */
export async function closePoll(token, pollId) {
    const url = generateOcsUrl('apps/spreed/api/v1/poll/{token}/{pollId}', { token, pollId })
    return ocsData(await axios.delete(url, { headers: OCS_HEADERS }))
}

/** `GET api/v1/poll/{token}/{pollId}` */
export async function fetchPoll(token, pollId) {
    const url = generateOcsUrl('apps/spreed/api/v1/poll/{token}/{pollId}', { token, pollId })
    return ocsData(await axios.get(url, { headers: OCS_HEADERS }))
}

/** `POST api/v1/poll/{token}/{pollId}` — answers with the updated poll. */
export async function voteOnPoll(token, pollId, optionIds) {
    const url = generateOcsUrl('apps/spreed/api/v1/poll/{token}/{pollId}', { token, pollId })
    return ocsData(await axios.post(url, { optionIds }, { headers: OCS_HEADERS }))
}
