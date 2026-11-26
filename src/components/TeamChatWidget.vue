<template>
    <!-- v4.20.2 — the team conversation on the Home view.

         v4.20.4 — threads and polls only (Justin): the running chat moves
         too fast for a dashboard card, and its unread count is already on the
         Chat tab's badge. What stays is what lasts a while.

         v4.20.5 — the card is called Communication; its header menu adds
         Meet now (TeamView.startTeamCall), and Join call below really joins.

         v4.20.23 — one view, no tabs (Justin): a call while one runs, what
         is pinned, the open polls (answerable here) and the threads, each
         with a quick reply. Pinned and polls show only when there are any.

         Everything comes from Talk's own API, as the viewer (talkApi.js), so
         Talk decides what this person may see and write. Reading here never
         marks the conversation as read. -->
    <div class="th-chat-widget">
        <!-- Talk refused this viewer: not a participant of the team room. -->
        <div v-if="talkStatus.available === false" class="th-widget__state th-widget__state--empty">
            <span class="th-widget__state-text">{{ t('teamhub', 'You are not a participant in the team conversation.') }}</span>
        </div>

        <template v-else>
            <!-- A call in progress. The one thing the Chat tab cannot tell
                 anybody who is not looking at it. -->
            <div v-if="talkStatus.hasCall" class="th-chat-widget__call">
                <Phone :size="ICON_BODY" class="th-chat-widget__call-icon" />
                <span class="th-chat-widget__call-text">{{ callText }}</span>
                <NcButton
                    v-if="canJoin"
                    variant="secondary"
                    size="small"
                    @click="$emit('start-call')">
                    <!-- TRANSLATORS: button that opens the team's Chat tab to take part in the video or audio call running there -->
                    {{ t('teamhub', 'Join call') }}
                </NcButton>
            </div>

            <div v-if="loading && !loaded" class="th-widget__state">
                <NcLoadingIcon :size="ICON_INLINE" />
                <span class="th-widget__state-text">{{ t('teamhub', 'Loading') }}</span>
            </div>
            <div v-else-if="loadError" class="th-widget__state th-widget__state--error">
                <span class="th-widget__state-text">{{ t('teamhub', 'Could not load the team conversation.') }}</span>
            </div>

            <template v-else>
                <!-- ── Pinned messages (v4.20.23) ── -->
                <section v-if="pinned.length" class="th-chat-widget__section" :aria-labelledby="domId('h-pinned')">
                    <h3 :id="domId('h-pinned')" class="th-chat-widget__heading">
                        <!-- TRANSLATORS: heading over the messages pinned in the team conversation -->
                        {{ t('teamhub', 'Pinned') }}
                    </h3>
                    <ul class="th-chat-widget__list">
                        <li v-for="m in pinned" :key="m.id" class="th-chat-widget__item">
                            <div class="th-widget__row th-chat-widget__entry th-chat-widget__entry--link">
                                <div class="th-chat-widget__body">
                                    <a
                                        :href="messageHref(m)"
                                        class="th-chat-widget__title th-chat-widget__title--wrap th-chat-widget__title-link"
                                        :title="m.pinnedBy ? t('teamhub', 'Pinned by {name}', { name: m.pinnedBy }) : null"
                                        @click="onOpenMessage($event, m)">{{ m.text }}</a>
                                    <div class="th-chat-widget__meta">
                                        <span>{{ m.author || t('teamhub', 'Guest') }}</span>
                                        <span v-if="m.timestamp" :title="absoluteTime(m.timestamp)">{{ shortTime(m.timestamp) }}</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- ── Open polls, answerable here ── -->
                <section v-if="openPolls.length" class="th-chat-widget__section" :aria-labelledby="domId('h-polls')">
                    <h3 :id="domId('h-polls')" class="th-chat-widget__heading">
                        <!-- TRANSLATORS: heading over the polls in the team conversation that are still open -->
                        {{ t('teamhub', 'Open polls') }}
                        <template v-if="pollsAwaitingVote > 0">
                            <NcCounterBubble :count="pollsAwaitingVote" type="highlighted" aria-hidden="true" />
                            <span class="hidden-visually">
                                {{ n('teamhub', '{n} poll awaits your vote', '{n} polls await your vote', pollsAwaitingVote, { n: pollsAwaitingVote }) }}
                            </span>
                        </template>
                    </h3>
                    <div>
                        <div v-for="poll in openPolls" :key="poll.id" class="th-chat-widget__poll">
                            <p :id="domId('poll-' + poll.id)" class="th-chat-widget__question">{{ poll.question }}</p>

                            <template v-if="!hasVoted(poll) && !talkStatus.readOnly">
                                <div
                                    class="th-chat-widget__options"
                                    role="group"
                                    :aria-labelledby="domId('poll-' + poll.id)">
                                    <template v-if="Number(poll.maxVotes) === 1">
                                        <NcCheckboxRadioSwitch
                                            v-for="row in pollOptionRows(poll)"
                                            :key="row.index"
                                            type="radio"
                                            :name="domId('poll-' + poll.id)"
                                            :value="String(row.index)"
                                            :model-value="selectedRadio(poll.id)"
                                            @update:model-value="v => setSelection(poll.id, [Number(v)])">
                                            {{ row.label }}
                                        </NcCheckboxRadioSwitch>
                                    </template>
                                    <template v-else>
                                        <NcCheckboxRadioSwitch
                                            v-for="row in pollOptionRows(poll)"
                                            :key="row.index"
                                            type="checkbox"
                                            :model-value="isSelected(poll.id, row.index)"
                                            @update:model-value="v => toggleOption(poll.id, row.index, v)">
                                            {{ row.label }}
                                        </NcCheckboxRadioSwitch>
                                    </template>
                                </div>
                                <p v-if="Number(poll.maxVotes) > 1" class="th-chat-widget__hint">
                                    {{ n('teamhub', 'Choose up to {n} option', 'Choose up to {n} options', Number(poll.maxVotes), { n: Number(poll.maxVotes) }) }}
                                </p>
                                <NcButton
                                    variant="secondary"
                                    :disabled="!canSubmitVote(poll, selection[poll.id]) || votingPollId === poll.id"
                                    @click="submitVote(poll)">
                                    <!-- TRANSLATORS: button that sends the user's answer to a poll in the team chat -->
                                    {{ t('teamhub', 'Vote') }}
                                </NcButton>
                            </template>

                            <ul v-else class="th-chat-widget__results">
                                <li v-for="row in pollOptionRows(poll)" :key="row.index" class="th-chat-widget__result">
                                    <Check v-if="row.mine" :size="ICON_INLINE" class="th-chat-widget__mine" />
                                    <span class="th-chat-widget__result-label">
                                        {{ row.label }}
                                        <span v-if="row.mine" class="hidden-visually">{{ t('teamhub', 'Your vote') }}</span>
                                    </span>
                                    <span v-if="row.votes !== null" class="th-chat-widget__result-count">
                                        {{ n('teamhub', '{n} vote', '{n} votes', row.votes, { n: row.votes }) }}
                                    </span>
                                </li>
                            </ul>

                            <!-- v4.20.25 — the poll's author or a moderator ends it,
                                 as in Talk. Hidden from everybody else. -->
                            <NcButton
                                v-if="canClosePoll(poll, talkStatus)"
                                variant="tertiary"
                                size="small"
                                @click="closingPoll = poll">
                                <!-- TRANSLATORS: button that closes a poll in the team chat so nobody can vote any more; Talk's own wording -->
                                {{ t('teamhub', 'End poll') }}
                            </NcButton>
                        </div>
                    </div>
                </section>

                <!-- ── Threads ── -->
                <section class="th-chat-widget__section" :aria-labelledby="domId('h-threads')">
                    <h3 :id="domId('h-threads')" class="th-chat-widget__heading">
                        {{ t('teamhub', 'Threads') }}
                    </h3>
                    <div v-if="!threads.length" class="th-widget__state th-widget__state--empty">
                        <span class="th-widget__state-text">{{ t('teamhub', 'No threads yet') }}</span>
                    </div>
                    <!-- v4.20.22 — a thread opens in the Chat tab, on that
                         thread. The title is the one link; it stretches over
                         the row, so the whole row takes the click and a
                         middle click still opens Talk in a new tab.
                         v4.20.23 — Reply answers in the thread from here. -->
                    <ul v-else class="th-chat-widget__list">
                        <li v-for="thread in threads" :key="thread.id" class="th-chat-widget__item">
                            <div class="th-widget__row th-chat-widget__entry th-chat-widget__entry--link">
                                <div class="th-chat-widget__body">
                                    <div class="th-chat-widget__title-row">
                                        <a
                                            :href="threadHref(thread)"
                                            class="th-chat-widget__title th-chat-widget__title-link"
                                            @click="onOpenThread($event, thread)">{{ thread.title || t('teamhub', 'Thread') }}</a>
                                        <span
                                            v-if="thread.lastActivity"
                                            class="th-chat-widget__time"
                                            :title="absoluteTime(thread.lastActivity)">{{ shortTime(thread.lastActivity) }}</span>
                                    </div>
                                    <div class="th-chat-widget__meta">
                                        <span class="th-chat-widget__meta-fixed">
                                            {{ thread.replies > 0
                                                ? n('teamhub', '{n} reply', '{n} replies', thread.replies, { n: thread.replies })
                                                : t('teamhub', 'No replies yet') }}
                                        </span>
                                        <span v-if="thread.preview" class="th-chat-widget__preview">{{ previewText(thread.preview) }}</span>
                                    </div>
                                </div>
                                <NcButton
                                    v-if="mayReply"
                                    :ref="'replyToggle-' + thread.id"
                                    variant="tertiary"
                                    size="small"
                                    class="th-chat-widget__reply-toggle"
                                    :aria-label="t('teamhub', 'Reply to {title}', { title: thread.title || t('teamhub', 'Thread') })"
                                    :title="t('teamhub', 'Reply to {title}', { title: thread.title || t('teamhub', 'Thread') })"
                                    :aria-expanded="replyThreadId === thread.id ? 'true' : 'false'"
                                    @click="toggleReply(thread)">
                                    <template #icon>
                                        <ReplyIcon :size="ICON_INLINE" />
                                    </template>
                                </NcButton>
                            </div>
                            <div v-if="mayReply && replyThreadId === thread.id" class="th-chat-widget__reply">
                                <NcTextField
                                    :ref="'replyField-' + thread.id"
                                    v-model="replyText"
                                    class="th-chat-widget__reply-field"
                                    :label="t('teamhub', 'Your reply')"
                                    :disabled="replySending"
                                    @keydown.enter.prevent="sendReply(thread)"
                                    @keydown.esc.stop="closeReply(thread)" />
                                <NcButton
                                    variant="primary"
                                    :disabled="!replyText.trim() || replySending"
                                    @click="sendReply(thread)">
                                    {{ t('teamhub', 'Send reply') }}
                                </NcButton>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </template>

        <!-- v4.20.25 — ending a poll cannot be undone: confirm first. -->
        <NcDialog
            v-if="closingPoll"
            :name="t('teamhub', 'End this poll?')"
            :open="true"
            @update:open="v => !v && cancelClosePoll()">
            <p class="th-chat-widget__dialog-question">{{ closingPoll.question }}</p>
            <p>{{ t('teamhub', 'Nobody can vote after this, and everyone in the conversation sees the results.') }}</p>
            <template #actions>
                <NcButton variant="tertiary" :disabled="closingBusy" @click="cancelClosePoll">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" :disabled="closingBusy" @click="confirmClosePoll">
                    {{ t('teamhub', 'End poll') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import ReplyIcon from 'vue-material-design-icons/Reply.vue'
import { ICON_INLINE, ICON_BODY } from '../constants/uiTokens.js'
import {
    fetchRecentThreads, fetchSharedPolls, fetchPoll, voteOnPoll, fetchPinnedMessages, replyInThread, closePoll,
} from '../lib/talkApi.js'
import {
    threadRows, talkConversationPath, talkMessageHash, pinnedRows, canReply, canClosePoll,
    messagePlainText, pollIdsFromShared, pollOptionRows,
    hasVoted, canSubmitVote, callAction, POLL_STATUS_OPEN,
} from '../lib/talkChat.js'
import { isPlainClick } from '../lib/internalLinks.js'
import {
    formatDateTime, formatDate, formatTime, todayIso, fromDateInput, shiftIsoDate,
} from '../lib/localDate.js'

/** Threads shown on the Threads tab, most recently active first. */
const THREAD_LIMIT = 5
/** Poll messages looked at, and open polls shown on the Polls tab. */
const POLL_SCAN = 20
const POLL_LIMIT = 5
/** Pinned messages looked at, and shown (v4.20.23). */
const PINNED_SCAN = 10
const PINNED_LIMIT = 3
/** Talk's own cap on a chat message. */
const REPLY_MAX = 32000

/** Per-instance prefix for element ids, so two mounted widgets never clash. */
let instanceSeq = 0

export default {
    name: 'TeamChatWidget',

    components: {
        NcButton, NcCheckboxRadioSwitch, NcCounterBubble, NcDialog, NcLoadingIcon, NcTextField,
        Check, Phone, ReplyIcon,
    },

    // v4.20.5 — 'start-call' (Join call): TeamView opens the Chat tab with
    // Talk joining the call, rather than only showing the conversation.
    emits: ['open-talk', 'start-call'],

    data() {
        instanceSeq += 1
        return {
            ICON_INLINE,
            ICON_BODY,
            uid: instanceSeq,
            loading: false,
            loaded: false,
            loadError: false,
            threads: [],
            // v4.20.23 — the pinned messages, newest pin first (pinnedRows).
            pinned: [],
            openPolls: [],
            // pollId → selected option indexes
            selection: {},
            votingPollId: null,
            // v4.20.23 — the quick reply: one thread at a time.
            replyThreadId: null,
            replyText: '',
            replySending: false,
            // v4.20.25 — the poll waiting for the End poll confirmation.
            closingPoll: null,
            closingBusy: false,
            // Guards the pinned list against a slow answer (loadPinned).
            pinSeq: 0,
            // Guards against a slow answer for a previous team.
            loadSeq: 0,
        }
    },

    computed: {
        ...mapState(['talkStatus']),

        token() {
            return this.talkStatus.token
        },

        /** v4.20.23 — Talk would take a message from this viewer. Hidden, not disabled, otherwise. */
        mayReply() {
            return canReply(this.talkStatus)
        },

        /** Talk would let this viewer join the running call. */
        canJoin() {
            return callAction(this.talkStatus) === 'join'
        },

        pollsAwaitingVote() {
            if (this.talkStatus.readOnly) return 0
            return this.openPolls.filter(p => !hasVoted(p)).length
        },

        callText() {
            const start = this.talkStatus.callStartTime
            if (!start) {
                return t('teamhub', 'Call in progress')
            }
            // TRANSLATORS: {time} is the clock time the call started, e.g. "14:05"
            return t('teamhub', 'Call in progress since {time}', {
                time: formatTime(start * 1000, { hour: '2-digit', minute: '2-digit' }),
            })
        },
    },

    watch: {
        token() {
            this.threads = []
            this.pinned = []
            this.replyThreadId = null
            this.replyText = ''
            this.openPolls = []
            this.selection = {}
            this.loaded = false
            this.load()
        },

        // v4.20.25 — pins load from the room's own pin fields. The token
        // arrives before Talk's answer (the store commits it with nothing
        // else), so loading on the token alone found nothing pinned and the
        // section never came back. Pinning, unpinning and hiding in Talk
        // each move one of these.
        'talkStatus.lastPinnedId'() {
            this.loadPinned()
        },
        'talkStatus.hiddenPinnedId'() {
            this.loadPinned()
        },

        // A new message in the room (seen by the store's status refresh) may
        // be a thread reply or a new poll: the one reason to fetch again.
        'talkStatus.lastMessageId'(next, prev) {
            if (next && prev && next !== prev) {
                this.load()
            }
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t,
        n,
        hasVoted,
        canSubmitVote,
        canClosePoll,
        pollOptionRows,

        domId(suffix) {
            return 'th-chat-' + this.uid + '-' + suffix
        },

        /** v4.20.22 — Talk's own address of the thread, for a new tab. */
        threadHref(thread) {
            return generateUrl(talkConversationPath(this.token, thread.id))
        },

        /**
         * v4.20.22 — a plain click opens the thread in the Chat tab; a click
         * with a modifier is left to the browser (a new tab on Talk itself).
         */
        onOpenThread(domEvent, thread) {
            if (!isPlainClick(domEvent)) {
                return
            }
            domEvent.preventDefault()
            this.$store.commit('SET_TALK_EMBED_TARGET', { threadId: thread.id })
            this.$emit('open-talk')
        },

        /** v4.20.23 — Talk's own address of a pinned message, in its thread when it has one. */
        messageHref(m) {
            return generateUrl(talkConversationPath(this.token, m.threadId)) + talkMessageHash(m.id)
        },

        /** v4.20.23 — a pinned message opens the Chat tab at that message. */
        onOpenMessage(domEvent, m) {
            if (!isPlainClick(domEvent)) {
                return
            }
            domEvent.preventDefault()
            this.$store.commit('SET_TALK_EMBED_TARGET', { threadId: m.threadId, messageId: m.id })
            this.$emit('open-talk')
        },

        /** A ref inside v-for is an array in Vue 3; the element or component either way. */
        refOf(name) {
            const r = this.$refs[name]
            return Array.isArray(r) ? r[0] : r
        },

        /** v4.20.23 — open or close the quick reply under a thread. */
        toggleReply(thread) {
            if (this.replyThreadId === thread.id) {
                this.closeReply(thread)
                return
            }
            this.replyThreadId = thread.id
            this.replyText = ''
            this.$nextTick(() => this.refOf('replyField-' + thread.id)?.$el?.querySelector('input')?.focus())
        },

        /** Close the quick reply and give focus back to its Reply button. */
        closeReply(thread) {
            if (this.replySending) return
            this.replyThreadId = null
            this.replyText = ''
            this.$nextTick(() => this.refOf('replyToggle-' + thread.id)?.$el?.focus())
        },

        /**
         * v4.20.23 — post the reply in the thread, as the viewer, through
         * Talk's own chat endpoint. Talk decides whether this person may
         * write; the widget only hides Reply where Talk already said no.
         */
        async sendReply(thread) {
            const message = this.replyText.trim()
            if (!message || this.replySending || !this.token) {
                return
            }
            if (message.length > REPLY_MAX) {
                showError(t('teamhub', 'This reply is too long.'))
                return
            }
            this.replySending = true
            try {
                await replyInThread(this.token, thread.id, message)
                showSuccess(t('teamhub', 'Reply sent'))
                this.replySending = false
                this.closeReply(thread)
                this.load()
            } catch (e) {
                showError(t('teamhub', 'Could not send your reply.'))
            } finally {
                this.replySending = false
            }
        },

        /** The newest reply, attributed: "Lieke: sounds good". */
        previewText(m) {
            // TRANSLATORS: preview of the newest reply in a chat thread, e.g. "Lieke: sounds good"
            return t('teamhub', '{name}: {message}', {
                name: m?.actorDisplayName || t('teamhub', 'Guest'),
                message: messagePlainText(m),
            })
        },

        /** Called from the card's header menu (TeamWidgetGrid, MobileWidgetView). */
        async refresh() {
            await this.$store.dispatch('fetchTalkStatus')
            await this.load()
        },

        async load() {
            const token = this.token
            if (!token || this.talkStatus.available === false) {
                return
            }
            const seq = ++this.loadSeq
            this.loading = true
            this.loadError = false
            try {
                this.loadPinned()
                const [threadList, shared] = await Promise.all([
                    // A Talk without threads answers 404: no threads, not an error.
                    fetchRecentThreads(token, THREAD_LIMIT).catch(e => {
                        if (e?.response?.status === 404 && this.talkStatus.available) return []
                        throw e
                    }),
                    fetchSharedPolls(token, POLL_SCAN).catch(() => ({})),
                ])
                if (seq !== this.loadSeq) return
                this.threads = threadRows(threadList, THREAD_LIMIT)
                await this.loadPolls(token, pollIdsFromShared(shared), seq)
                if (seq === this.loadSeq) {
                    this.loaded = true
                }
            } catch (e) {
                if (seq !== this.loadSeq) return
                const status = e?.response?.status
                if (status === 404 || status === 403) {
                    // The store owns "not a participant"; let it say so.
                    this.$store.dispatch('fetchTalkStatus')
                } else {
                    this.loadError = true
                }
            } finally {
                if (seq === this.loadSeq) {
                    this.loading = false
                }
            }
        },

        /**
         * v4.20.23 — the pinned messages. The room says whether anything is
         * pinned (`lastPinnedId`, Talk's pinned-messages capability), so a
         * room without pins costs no request, and a Talk without the
         * capability sends no such field: no pinned section.
         * v4.20.25 — its own load, run by load() and by the pin watchers.
         */
        async loadPinned() {
            const token = this.token
            const status = this.talkStatus
            const seq = ++this.pinSeq
            if (!token || !(status.lastPinnedId > 0)) {
                this.pinned = []
                return
            }
            const shared = await fetchPinnedMessages(token, PINNED_SCAN).catch(() => ({}))
            if (seq !== this.pinSeq || token !== this.token) return
            this.pinned = pinnedRows(shared, this.talkStatus.hiddenPinnedId, PINNED_LIMIT)
        },

        cancelClosePoll() {
            if (this.closingBusy) return
            this.closingPoll = null
        },

        /**
         * v4.20.25 — end the poll through Talk, as the viewer. Talk lets the
         * author and moderators do it and posts "poll closed" in the chat;
         * the poll then leaves Open polls.
         */
        async confirmClosePoll() {
            const poll = this.closingPoll
            if (!poll || !this.token || this.closingBusy) return
            this.closingBusy = true
            try {
                await closePoll(this.token, poll.id)
                this.openPolls = this.openPolls.filter(p => p.id !== poll.id)
                const rest = { ...this.selection }
                delete rest[poll.id]
                this.selection = rest
                showSuccess(t('teamhub', 'Poll ended'))
            } catch (e) {
                showError(t('teamhub', 'Could not end the poll.'))
            } finally {
                this.closingBusy = false
                this.closingPoll = null
            }
        },

        async loadPolls(token, ids, seq) {
            const polls = await Promise.all(ids.map(id => fetchPoll(token, id).catch(() => null)))
            if (seq !== this.loadSeq) return
            this.openPolls = polls
                .filter(p => p && Number(p.status) === POLL_STATUS_OPEN)
                .slice(0, POLL_LIMIT)
        },

        selectedRadio(pollId) {
            const sel = this.selection[pollId]
            return Array.isArray(sel) && sel.length ? String(sel[0]) : ''
        },

        isSelected(pollId, index) {
            return (this.selection[pollId] || []).includes(index)
        },

        setSelection(pollId, indexes) {
            this.selection = { ...this.selection, [pollId]: indexes }
        },

        toggleOption(pollId, index, checked) {
            const current = (this.selection[pollId] || []).filter(i => i !== index)
            this.setSelection(pollId, checked ? [...current, index] : current)
        },

        async submitVote(poll) {
            const chosen = this.selection[poll.id] || []
            if (!canSubmitVote(poll, chosen) || !this.token) {
                return
            }
            this.votingPollId = poll.id
            try {
                const updated = await voteOnPoll(this.token, poll.id, chosen)
                if (updated) {
                    this.openPolls = this.openPolls.map(p => (p.id === poll.id ? updated : p))
                }
                const rest = { ...this.selection }
                delete rest[poll.id]
                this.selection = rest
            } catch (e) {
                showError(t('teamhub', 'Could not send your vote.'))
            } finally {
                this.votingPollId = null
            }
        },

        absoluteTime(secs) {
            return secs ? formatDateTime(secs * 1000) : ''
        },

        /**
         * A clock time today, "Yesterday", a weekday within the week, a date
         * beyond that. Each boundary is the reader's own midnight, the same
         * rule as the feed card (FeedItemCard.relativeTime).
         */
        shortTime(secs) {
            if (!secs) return ''
            const ms = secs * 1000
            const today = todayIso()
            if (secs >= fromDateInput(today)) {
                return formatTime(ms, { hour: '2-digit', minute: '2-digit' })
            }
            if (secs >= fromDateInput(shiftIsoDate(today, { days: -1 }))) {
                return t('teamhub', 'Yesterday')
            }
            if (secs >= fromDateInput(shiftIsoDate(today, { days: -6 }))) {
                return formatDate(ms, { weekday: 'short' })
            }
            return formatDate(ms, { month: 'short', day: 'numeric' })
        },
    },
}
</script>

<style scoped>
/* v4.20.24 — type and spacing on the shared widget tokens (widget-tokens.css),
   as the other widgets: a row title at body size in medium weight, a meta
   line at meta size in the muted colour, section titles as the OpenProject
   widget's, and the rows' own side padding for everything in the card. */
.th-chat-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.th-chat-widget__section + .th-chat-widget__section {
    border-block-start: 1px solid var(--color-border);
}

.th-chat-widget__heading {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    margin: 0;
    padding: var(--th-space-md) var(--th-space-lg) var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
}

.th-chat-widget__list {
    list-style: none;
    margin: 0;
    padding: 0;
}

/* The rule sits on the item, so a thread and its open reply read as one. */
.th-chat-widget__item {
    border-block-end: 1px solid var(--color-border);
}

.th-chat-widget__item:last-child {
    border-block-end: none;
}

.th-chat-widget__item > .th-widget__row {
    border-block-end: none;
}

.th-chat-widget__entry {
    align-items: center;
}

.th-chat-widget__body {
    flex: 1 1 auto;
    min-width: 0;
}

.th-chat-widget__title-row {
    display: flex;
    align-items: baseline;
    gap: var(--th-space-sm);
}

.th-chat-widget__title {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
}

/* A pinned message is the title itself: two lines, then ellipsis. */
.th-chat-widget__title--wrap {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    white-space: normal;
    overflow-wrap: anywhere;
}

.th-chat-widget__time {
    flex-shrink: 0;
    margin-inline-start: auto;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
}

/* The second line, as the Upcoming events widget's. */
.th-chat-widget__meta {
    display: flex;
    align-items: baseline;
    gap: var(--th-space-sm);
    min-width: 0;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
}

.th-chat-widget__meta-fixed {
    flex-shrink: 0;
}

.th-chat-widget__preview {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* v4.20.22 — the row opens the thread. The title link stretches over the
   row (::after), so there is one focusable element per row. */
.th-chat-widget__entry--link {
    position: relative;
    cursor: pointer;
    transition: background var(--animation-quick) ease;
}

.th-chat-widget__entry--link:hover {
    background: var(--color-background-hover);
}

.th-chat-widget__title-link {
    text-decoration: none;
}

.th-chat-widget__title-link::after {
    content: '';
    position: absolute;
    inset: 0;
}

.th-chat-widget__entry--link:hover .th-chat-widget__title-link {
    color: var(--color-primary-element);
    text-decoration: underline;
}

.th-chat-widget__title-link:focus-visible {
    outline: none;
}

.th-chat-widget__entry--link:has(.th-chat-widget__title-link:focus-visible) {
    outline: calc(var(--default-grid-baseline) / 2) solid var(--color-primary-element);
    outline-offset: calc(var(--default-grid-baseline) / -2);
}

/* Above the stretched link, so it takes its own click. */
.th-chat-widget__reply-toggle {
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}

.th-chat-widget__reply {
    display: flex;
    align-items: flex-end;
    gap: var(--th-space-sm);
    padding: 0 var(--th-space-lg) var(--th-space-sm);
}

.th-chat-widget__reply-field {
    flex: 1 1 auto;
    min-width: 0;
}

.th-chat-widget__call {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    margin: var(--th-space-sm) var(--th-space-lg);
    padding: var(--th-space-sm) var(--th-space-md);
    border-radius: var(--border-radius-element);
    background: var(--color-success);
    color: var(--color-success-text);
}

.th-chat-widget__call-icon {
    flex-shrink: 0;
}

.th-chat-widget__call-text {
    flex: 1 1 auto;
    min-width: 0;
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
}

/* v4.20.24 — polls as rows: no box, a rule between two. */
.th-chat-widget__poll {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--th-space-xs);
    padding: var(--th-widget-row-padding);
}

.th-chat-widget__poll + .th-chat-widget__poll {
    border-block-start: 1px solid var(--color-border);
}

.th-chat-widget__question {
    margin: 0;
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    overflow-wrap: anywhere;
}

.th-chat-widget__options {
    align-self: stretch;
}

.th-chat-widget__hint {
    margin: 0;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
}

.th-chat-widget__results {
    align-self: stretch;
    margin: 0;
    padding: 0;
    list-style: none;
}

.th-chat-widget__result {
    display: flex;
    align-items: center;
    gap: var(--th-space-xs);
    padding-block: var(--th-space-xs);
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-meta-weight);
}

.th-chat-widget__mine {
    flex-shrink: 0;
    color: var(--color-primary-element);
}

.th-chat-widget__result-label {
    flex: 1 1 auto;
    min-width: 0;
    overflow-wrap: anywhere;
}

.th-chat-widget__dialog-question {
    font-weight: var(--th-widget-row-primary-weight);
    overflow-wrap: anywhere;
}

.th-chat-widget__result-count {
    flex-shrink: 0;
    font-size: var(--th-widget-row-meta-size);
    color: var(--th-widget-meta-color);
}
</style>
