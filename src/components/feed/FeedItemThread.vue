<template>
    <div class="feed-thread">
        <!-- Toggle. Collapsed on mount by design — the feed's job is to let
             you scan what happened; a thread expanded by default turns every
             row into a wall. Nothing is fetched until it is opened. -->
        <button
            type="button"
            class="feed-thread__toggle"
            :aria-expanded="expanded ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="toggle">
            <ChevronDown
                class="feed-thread__chevron"
                :class="{ 'feed-thread__chevron--open': expanded }"
                :size="ICON_INLINE"
                aria-hidden="true" />
            <span>{{ toggleLabel }}</span>
        </button>

        <div v-show="expanded" :id="panelId" class="feed-thread__panel">
            <div v-if="loading" class="feed-thread__loading">
                <NcLoadingIcon :size="ICON_TOOLBAR" />
                <span>{{ t('teamhub', 'Loading…') }}</span>
            </div>

            <p v-else-if="loadError" class="feed-thread__error" role="alert">
                {{ loadError }}
            </p>

            <p v-else-if="!entries.length" class="feed-thread__empty">
                {{ t('teamhub', 'No replies yet') }}
            </p>

            <ul v-else class="feed-thread__list">
                <li v-for="entry in entries" :key="entry.key" class="feed-thread__entry">
                    <NcAvatar
                        :user="entry.authorId"
                        :display-name="entry.authorName"
                        :size="AVATAR_SIZE"
                        :show-user-status="false"
                        :disable-menu="true"
                        class="feed-thread__avatar" />
                    <div class="feed-thread__body">
                        <div class="feed-thread__meta">
                            <span class="feed-thread__author">{{ entry.authorName }}</span>
                            <span class="feed-thread__time">{{ formatTimestamp(entry.createdAt) }}</span>
                        </div>
                        <!-- Plain text node: Vue escapes it, so a comment body
                             cannot inject markup here. The team stream renders
                             markdown through DOMPurify; the feed deliberately
                             does not, because a preview is not the place to
                             re-implement a sanitiser. -->
                        <p class="feed-thread__text">{{ entry.text }}</p>
                    </div>
                </li>
            </ul>

            <!-- Reply box. Rendered only when the *server* said this viewer may
                 write here — `can_comment` for a TeamHub message (membership +
                 the team's commentMinLevel + the decision lock) and `can_reply`
                 for a Talk thread (Talk's own participant and read-only rules).
                 The server re-checks on submit regardless; this is what keeps
                 the UI from offering something it knows will be refused. -->
            <!-- v4.5.39 — a decision renders nothing here. 4.5.29 showed the
                 block disabled with "coming in a future update" underneath, on
                 the reasoning that a reserved affordance beats a missing one;
                 in practice it announced an unfinished product on every
                 decision card. **To re-activate when Decisions is reworked**:
                 restore `v-if="canReply || isDecision"` here, drop `!isDecision`
                 from the `v-else-if` below, and make `canReply` stop returning
                 false for a decision. The disabled styling, the denied-reason
                 branch and `.feed-thread__compose--disabled` are all still in
                 place for that. -->
            <form
                v-if="canReply"
                class="feed-thread__compose"
                :class="{ 'feed-thread__compose--disabled': !canReply }"
                @submit.prevent="submit">
                <label :for="inputId" class="feed-thread__compose-label">
                    {{ replyLabel }}
                </label>
                <textarea
                    :id="inputId"
                    ref="input"
                    v-model="draft"
                    class="feed-thread__input"
                    rows="2"
                    :maxlength="MAX_LENGTH"
                    :disabled="submitting || !canReply"
                    :placeholder="replyPlaceholder" />
                <!-- No Enter-to-submit. This is a textarea in a page of
                     textareas, and a stray Enter posting a half-written
                     comment into a team stream is not recoverable. The Reply
                     button is the only way out. -->
                <div class="feed-thread__compose-actions">
                    <span v-if="!canReply" class="feed-thread__denied">
                        {{ replyDeniedReason }}
                    </span>
                    <span v-if="submitError" class="feed-thread__error" role="alert">
                        {{ submitError }}
                    </span>
                    <!-- `type`, not `native-type`: NcButton renamed that prop
                         in @nextcloud/vue 9. The 8.x name is silently ignored,
                         so the button rendered as type="button", the form never
                         submitted, and Reply did nothing without an error. -->
                    <NcButton
                        variant="primary"
                        :disabled="!canReply || !draft.trim() || submitting"
                        type="submit">
                        <template v-if="submitting" #icon>
                            <NcLoadingIcon :size="ICON_BODY" />
                        </template>
                        {{ t('teamhub', 'Reply') }}
                    </NcButton>
                </div>
            </form>

            <p v-else-if="expanded && !isDecision && replyDeniedReason" class="feed-thread__denied">
                {{ replyDeniedReason }}
            </p>
        </div>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDateTime } from '../../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcAvatar, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR } from '../../constants/uiTokens.js'

/**
 * Longest reply this box accepts. Mirrors FeedTalkController::MAX_REPLY_LENGTH
 * so the counter runs out at the same place the server refuses, rather than
 * letting someone write 9 000 characters and then lose them.
 */
const MAX_LENGTH = 8000

/** Avatar size in the thread. One step below the card's own author avatar. */
const AVATAR_SIZE = 24

export default {
    name: 'FeedItemThread',
    components: { NcAvatar, NcButton, NcLoadingIcon, ChevronDown },

    props: {
        /** The feed row this thread belongs to. */
        item: {
            type: Object,
            required: true,
        },
    },

    emits: ['count-changed'],

    data() {
        return {
            expanded: false,
            loading: false,
            loadError: '',
            submitting: false,
            submitError: '',
            draft: '',
            entries: [],
            // Set once the panel has been opened, so collapsing and
            // re-expanding doesn't re-fetch a thread that hasn't changed.
            fetched: false,
            ICON_INLINE,
            ICON_BODY,
            ICON_TOOLBAR,
            AVATAR_SIZE,
            MAX_LENGTH,
        }
    },

    computed: {
        /**
         * A Talk row whose replies live in the conversation.
         *
         * v4.5.33 — a `talk-mention` behaves identically: its replies are the
         * messages threaded onto it, and replying threads onto it in turn. The
         * only difference is that a thread already exists for one and is
         * created by the first reply to the other, which is Talk's business,
         * not this component's.
         */
        isTalkThread() {
            return this.item.source === 'talk-thread'
                || this.item.source === 'talk-mention'
        },

        /**
         * Whether a thread can be shown at all. A public post from a team the
         * viewer is not in has a readable card and an unreadable thread —
         * `CommentController::listComments` would refuse, so no toggle.
         */
        canView() {
            if (this.isTalkThread) return true
            return !!this.item.can_view_comments
        },

        /**
         * Decisions show their discussion but do not take one here.
         *
         * v4.5.29 rendered the reply box disabled rather than hidden, so the
         * affordance read as reserved instead of missing. v4.5.39 hides it:
         * the accompanying "coming in a future update" line read as an
         * unfinished product on every decision card, and a card that simply
         * ends after its thread makes no claim either way. The disabled path
         * is kept intact — see the note on the compose form for how to turn it
         * back on when Decisions is reworked.
         */
        isDecision() {
            return this.item.messageType === 'decision'
        },

        canReply() {
            if (this.isDecision) {
                return false
            }
            return this.isTalkThread ? !!this.item.can_reply : !!this.item.can_comment
        },

        /**
         * Why the reply box is absent, when the reason is one we can name.
         * Silence would read as a bug; "you can't" without a reason reads as
         * a bug the user caused.
         */
        replyDeniedReason() {
            if (this.canReply) return ''
            // Unreachable since v4.5.39 — nothing renders this for a decision.
            // Kept with the rest of the disabled path; delete it only if the
            // decision reply box is dropped rather than activated.
            if (this.isDecision) {
                return t('teamhub', 'Responding to decisions from here is coming in a future update.')
            }
            if (this.isTalkThread) {
                return t('teamhub', 'You cannot post in this conversation.')
            }
            if (this.item.comments_locked) {
                return t('teamhub', 'Comments are locked on this decision.')
            }
            return t('teamhub', 'Your role in this team does not allow commenting.')
        },

        count() {
            return this.isTalkThread
                ? (this.item.num_replies || 0)
                : (this.item.comment_count || 0)
        },

        toggleLabel() {
            if (this.expanded) {
                return t('teamhub', 'Hide replies')
            }
            const c = this.fetched ? this.entries.length : this.count
            if (!c) {
                return this.canReply
                    ? t('teamhub', 'Reply')
                    : t('teamhub', 'No replies')
            }
            return n('teamhub', '{n} reply', '{n} replies', c, { n: c })
        },

        replyLabel() {
            return this.isTalkThread
                ? t('teamhub', 'Reply in this conversation')
                : t('teamhub', 'Add a comment')
        },

        replyPlaceholder() {
            return this.isTalkThread
                ? t('teamhub', 'Write a reply…')
                : t('teamhub', 'Write a comment…')
        },

        // Ids are per-instance so aria-controls / label-for stay unique when
        // fifty cards render at once.
        panelId() {
            return `feed-thread-panel-${this.item.source || 'msg'}-${this.item.id}`
        },

        inputId() {
            return `feed-thread-input-${this.item.source || 'msg'}-${this.item.id}`
        },
    },

    methods: {
        t,
        n,

        toggle() {
            this.expanded = !this.expanded
            if (this.expanded && !this.fetched) {
                this.load()
            }
        },

        async load() {
            if (!this.canView) {
                this.fetched = true
                return
            }
            this.loading = true
            this.loadError = ''
            try {
                this.entries = this.isTalkThread
                    ? await this.fetchTalkReplies()
                    : await this.fetchComments()
                this.fetched = true
            } catch (e) {
                this.loadError = this.isTalkThread
                    ? t('teamhub', 'Could not load replies.')
                    : t('teamhub', 'Could not load comments.')
            } finally {
                this.loading = false
            }
        },

        async fetchComments() {
            const { data } = await axios.get(
                generateUrl('/apps/teamhub/api/v1/messages/{messageId}/comments', {
                    messageId: this.item.id,
                }),
            )
            return (Array.isArray(data) ? data : []).map((c) => ({
                key: `c-${c.id}`,
                authorId: c.author_id,
                authorName: c.author_display_name || c.author_id,
                text: c.comment || '',
                createdAt: c.created_at,
            }))
        },

        async fetchTalkReplies() {
            const { data } = await axios.get(
                generateUrl('/apps/teamhub/api/v1/feed/talk/{token}/threads/{threadId}/replies', {
                    token: this.item.room_token,
                    threadId: this.item.id,
                }),
            )
            return (data?.replies || []).map((r) => ({
                key: `r-${r.id}`,
                authorId: r.actor_type === 'users' ? r.actor_id : '',
                authorName: r.actor_display_name || r.actor_id,
                text: r.message || '',
                createdAt: r.created_at,
            }))
        },

        async submit() {
            const body = this.draft.trim()
            if (!body || this.submitting) return

            this.submitting = true
            this.submitError = ''
            try {
                if (this.isTalkThread) {
                    const { data } = await axios.post(
                        generateUrl('/apps/teamhub/api/v1/feed/talk/{token}/threads/{threadId}/replies', {
                            token: this.item.room_token,
                            threadId: this.item.id,
                        }),
                        {
                            message: body,
                            // A Talk mention is a plain chat message with no
                            // thread of its own — see the note on
                            // TalkService::replyToThread.
                            thread: this.item.source === 'talk-mention' ? 0 : 1,
                        },
                    )
                    // The POST hands back the refreshed thread, so there is no
                    // second round trip and no window where the reply is
                    // posted but invisible.
                    this.entries = (data?.replies || []).map((r) => ({
                        key: `r-${r.id}`,
                        authorId: r.actor_type === 'users' ? r.actor_id : '',
                        authorName: r.actor_display_name || r.actor_id,
                        text: r.message || '',
                        createdAt: r.created_at,
                    }))
                } else {
                    const { data } = await axios.post(
                        generateUrl('/apps/teamhub/api/v1/messages/{messageId}/comments', {
                            messageId: this.item.id,
                        }),
                        { comment: body },
                    )
                    this.entries.push({
                        key: `c-${data.id}`,
                        authorId: data.author_id,
                        authorName: data.author_display_name || data.author_id,
                        text: data.comment || body,
                        createdAt: data.created_at,
                    })
                }
                this.draft = ''
                this.fetched = true
                this.$emit('count-changed', this.entries.length)
            } catch (e) {
                // The server's own message when it gave one — it is the thing
                // that explains a refused write (locked decision, read-only
                // room, role floor), and a generic string would hide it.
                this.submitError = e?.response?.data?.error
                    || t('teamhub', 'Could not post your reply.')
            } finally {
                this.submitting = false
            }
        },

        formatTimestamp(secs) {
            if (!secs) return ''
            return formatDateTime(secs * 1000)
        },
    },
}
</script>

<style scoped lang="scss">
.feed-thread {
    border-top: 1px solid var(--color-border);
    margin-top: 10px;
    padding-top: 8px;
}

// Raw <button>: this is a disclosure affordance sized to meta text, well
// under NcButton's 44px touch-target minimum, and it has to sit flush with
// the card's own left edge. Same carve-out as the chip-remove buttons in
// SKILLS.md § "NcButton is the default".
.feed-thread__toggle {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    padding: 2px 0;
    cursor: pointer;
    color: var(--color-primary-element);
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-semibold, 600);

    &:hover {
        text-decoration: underline;
    }

    &:focus-visible {
        outline: 2px solid var(--color-primary-element);
        outline-offset: 2px;
        border-radius: 2px;
        text-decoration: underline;
    }
}

.feed-thread__chevron {
    transition: transform 150ms ease-out;
    display: inline-flex;
}

.feed-thread__chevron--open {
    transform: rotate(180deg);
}

.feed-thread__panel {
    padding-top: 8px;
}

.feed-thread__loading {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
    padding: 4px 0;
}

.feed-thread__empty,
.feed-thread__denied {
    margin: 0;
    padding: 4px 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta, 12px);
}

// --color-error-text, not --color-error: SKILLS.md § NC design guidelines —
// the plain token is a fill colour and fails contrast as body text.
.feed-thread__error {
    margin: 0;
    color: var(--color-error-text);
    font-size: var(--th-font-meta, 12px);
}

.feed-thread__list {
    list-style: none;
    margin: 0 0 8px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.feed-thread__entry {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.feed-thread__avatar {
    flex: 0 0 auto;
}

.feed-thread__body {
    flex: 1 1 auto;
    min-width: 0;
}

.feed-thread__meta {
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex-wrap: wrap;
}

.feed-thread__author {
    font-size: var(--th-font-meta, 12px);
    font-weight: var(--th-font-weight-semibold, 600);
}

.feed-thread__time {
    font-size: var(--th-font-micro, 11px);
    color: var(--color-text-maxcontrast);
}

.feed-thread__text {
    margin: 2px 0 0;
    font-size: var(--th-font-body, 14px);
    line-height: var(--th-line-height-body, 1.4);
    // A pasted wall of text must not stretch the card.
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.feed-thread__compose {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

/* v4.5.29 — a reserved affordance, not a broken one. The label and the reason
   stay at full contrast so it is legible why it is off; only the controls dim.
   `not-allowed` on the wrapper because a disabled input swallows pointer
   events and would otherwise show no cursor feedback at all. */
.feed-thread__compose--disabled {
    cursor: not-allowed;

    .feed-thread__input {
        opacity: 0.6;
        background: var(--color-background-dark);
    }
}

.feed-thread__compose-label {
    font-size: var(--th-font-micro, 11px);
    font-weight: var(--th-font-weight-semibold, 600);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.feed-thread__input {
    width: 100%;
    resize: vertical;
    min-height: 56px;
    font-size: var(--th-font-body, 14px);
    line-height: var(--th-line-height-body, 1.4);
    border: 1px solid var(--color-border-dark);
    border-radius: var(--th-radius-control, var(--border-radius));
    background: var(--color-main-background);
    color: var(--color-main-text);
    padding: 6px 8px;

    // The NC form-field pattern from SKILLS.md § Focus visibility: outline
    // removed on the base rule, replaced by a border-colour change on focus
    // and reinforced with a ring for keyboard users.
    &:focus {
        outline: none;
        border-color: var(--color-primary-element);
    }

    &:focus-visible {
        box-shadow: 0 0 0 2px var(--color-primary-element);
    }
}

.feed-thread__compose-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}
</style>
