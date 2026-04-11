<template>
    <div class="message-card" :class="{
        'message-card--priority': isPriority,
        'message-card--question-solved': isQuestionSolved,
        'message-card--pinned': isPinnedSlot,
    }">
        <!-- Header -->
        <div class="message-card__header">
            <NcAvatar
                :user="message.author_id"
                :display-name="message.author_id"
                :show-user-status="true"
                :disable-menu="false"
                :size="36" />
            <div class="message-card__meta">
                <span class="message-card__author">{{ message.author_id }}</span>
                <span class="message-card__date">{{ formattedDate }}</span>
            </div>
            <span v-if="isPriority" class="message-card__priority-badge">
                🔴 {{ t('teamhub', 'Priority') }}
            </span>
            <!-- Question badge -->
            <span v-if="message.messageType === 'question'" class="message-card__question-badge-subtle">
                <HelpCircleOutline :size="16" />
            </span>
            <!-- Unpin button — shown on the pinned slot to users with pin rights -->
            <NcButton
                v-if="canPin && isPinnedSlot"
                type="tertiary"
                :aria-label="t('teamhub', 'Unpin message')"
                :title="t('teamhub', 'Unpin message')"
                @click="doUnpin">
                <template #icon><PinOff :size="16" /></template>
            </NcButton>
            <!-- Pin button — shown on regular messages to users with pin rights -->
            <NcButton
                v-else-if="canPin && !isPinnedSlot"
                type="tertiary"
                :aria-label="t('teamhub', 'Pin message')"
                :title="t('teamhub', 'Pin message')"
                @click="doPin">
                <template #icon><Pin :size="16" /></template>
            </NcButton>
            <NcButton
                v-if="isAuthor"
                type="tertiary"
                :aria-label="t('teamhub', 'Edit message')"
                @click="startEdit">
                <template #icon><Pencil :size="16" /></template>
            </NcButton>
            <NcButton
                v-if="isAuthor"
                type="tertiary"
                :aria-label="t('teamhub', 'Delete message')"
                @click="confirmDelete">
                <template #icon><Delete :size="16" /></template>
            </NcButton>
        </div>

        <!-- Edit mode -->
        <div v-if="editing" class="message-card__edit">
            <input
                v-model="editSubject"
                class="message-card__edit-subject"
                :placeholder="t('teamhub', 'Subject')" />
            <textarea
                v-model="editBody"
                class="message-card__edit-body"
                rows="5" />
            <div class="message-card__edit-actions">
                <NcButton type="primary" :disabled="saving" @click="saveEdit">
                    <template #icon><NcLoadingIcon v-if="saving" :size="16" /></template>
                    {{ t('teamhub', 'Save') }}
                </NcButton>
                <NcButton type="tertiary" @click="cancelEdit">{{ t('teamhub', 'Cancel') }}</NcButton>
            </div>
        </div>

        <!-- View mode -->
        <template v-else>
            <!-- Subject -->
            <h3 class="message-card__subject">{{ message.subject }}</h3>

            <!-- Body (markdown rendered) -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="message-card__body" v-html="renderedMessage" />
        </template>

        <!-- Link / attachment previews -->
        <div v-if="previews.length" class="message-card__previews">
            <template v-for="(preview, i) in previews">

                <!-- Image thumbnail — full-width when the URL is a direct image -->
                <a
                    v-if="preview.type === 'image'"
                    :key="'img-' + i"
                    :href="preview.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="message-preview message-preview--image-only">
                    <img
                        :src="preview.url"
                        :alt="preview.title || t('teamhub', 'Image attachment')"
                        class="message-preview__thumbnail" />
                    <span class="message-preview__image-caption">{{ preview.title }}</span>
                </a>

                <!-- Rich OG card — title + optional description + optional image -->
                <a
                    v-else-if="preview.type === 'og' || preview.type === 'rich'"
                    :key="'og-' + i"
                    :href="preview.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="message-preview message-preview--og">
                    <img
                        v-if="preview.image"
                        :src="preview.image"
                        :alt="preview.title || t('teamhub', 'Preview')"
                        class="message-preview__og-image" />
                    <div class="message-preview__body">
                        <span v-if="preview.site_name" class="message-preview__provider">{{ preview.site_name }}</span>
                        <span class="message-preview__title">{{ preview.title || preview.url }}</span>
                        <span v-if="preview.description" class="message-preview__desc">{{ preview.description }}</span>
                    </div>
                </a>

                <!-- File fallback card — for attachments that could not be resolved -->
                <a
                    v-else-if="preview.type === 'file'"
                    :key="'file-' + i"
                    :href="preview.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="message-preview message-preview--file">
                    <PaperclipIcon :size="28" class="message-preview__file-icon" />
                    <div class="message-preview__body">
                        <span class="message-preview__title">{{ preview.title }}</span>
                        <span class="message-preview__desc">{{ t('teamhub', 'Click to open attachment') }}</span>
                    </div>
                </a>

            </template>
        </div>

        <!-- Poll rendering (if messageType === 'poll') -->
        <div v-if="message.messageType === 'poll' && pollOptions.length" class="poll-widget">
            <div
                v-for="(option, index) in pollOptions"
                :key="index"
                class="poll-option"
                :class="{ 
                    'poll-option--voted': pollResults.userVote === index,
                    'poll-option--clickable': !isPollClosed && pollResults.userVote !== index
                }"
                @click="isPollClosed ? null : vote(index)">
                
                <div class="poll-option__bar" :style="{ width: getPercentage(index) + '%' }" />
                
                <div class="poll-option__content">
                    <span class="poll-option__text">{{ option }}</span>
                    <span class="poll-option__votes">{{ getPollVotes(index) }}</span>
                </div>
            </div>
            
            <div class="poll-footer">
                <ClipboardCheckOutline :size="16" />
                <span v-if="isPollClosed" class="poll-closed-label">
                    {{ t('teamhub', '{total} total votes - Poll closed', { total: pollResults.totalVotes }) }}
                </span>
                <span v-else>
                    {{ t('teamhub', '{total} total votes', { total: pollResults.totalVotes }) }}
                </span>
                <NcButton
                    v-if="isAuthor && !isPollClosed"
                    type="tertiary"
                    :aria-label="t('teamhub', 'Close poll')"
                    @click="closePoll">
                    <template #icon><Lock :size="16" /></template>
                    {{ t('teamhub', 'Close poll') }}
                </NcButton>
            </div>
        </div>

        <!-- Question solved banner -->
        <div v-if="message.messageType === 'question' && isQuestionSolved" class="question-solved-banner">
            <CheckCircle :size="20" />
            <span>{{ t('teamhub', 'Question solved') }}</span>
        </div>

        <!-- Footer: comment toggle -->
        <div class="message-card__footer">
            <NcButton type="tertiary" @click="toggleComments">
                <template #icon><CommentOutline :size="16" /></template>
                {{ commentLabel }}
            </NcButton>
        </div>

        <!-- Comments section -->
        <Transition name="comments">
            <CommentsSection
                v-if="commentsOpen"
                :message-id="message.id"
                :message-type="message.messageType"
                :is-author="isAuthor"
                :question-solved="isQuestionSolved"
                :solved-comment-id="message.solvedCommentId"
                @mark-solved="markSolved"
                @unmark-solved="unmarkSolved" />
        </Transition>
    </div>
</template>

<script>
import { mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcAvatar, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import CommentsSection from './CommentsSection.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'

// Image extensions we can render as inline thumbnails
const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif']

function extensionOf(url) {
    try {
        const path = new URL(url).pathname
        return path.split('.').pop().toLowerCase().split('?')[0]
    } catch (e) {
        return ''
    }
}

function isImageUrl(url) {
    return IMAGE_EXTENSIONS.includes(extensionOf(url))
}

function filenameFromUrl(url) {
    try {
        const path = new URL(url).pathname
        return decodeURIComponent(path.split('/').pop()) || url
    } catch (e) {
        return url
    }
}

/**
 * Extract all URLs from message text.
 * Returns array of { url, label, isAttachment } objects.
 * isAttachment = true when the link came from the 📎 attachment markdown pattern.
 */
function extractUrlObjects(text) {
    if (!text) return []
    const results = []
    const seen = new Set()

    // Markdown links (includes 📎 attachment links from PostMessageForm)
    const mdRe = /\[([^\]]+)\]\(([^)]+)\)/g
    let m
    while ((m = mdRe.exec(text)) !== null) {
        try {
            const href = new URL(m[2]).href
            if (seen.has(href)) continue
            seen.add(href)
            results.push({ url: href, label: m[1], isAttachment: m[1].startsWith('📎') })
        } catch (e) {}
    }

    // Bare URLs not inside markdown parentheses
    const bareRe = /(?<!\()https?:\/\/[^\s<>"'\)]+/g
    while ((m = bareRe.exec(text)) !== null) {
        try {
            const href = new URL(m[0]).href
            if (seen.has(href)) continue
            seen.add(href)
            results.push({ url: href, label: href, isAttachment: false })
        } catch (e) {}
    }

    return results
}

// Simple markdown renderer
function renderMarkdown(text) {
    if (!text) return ''
    let html = text
        .replace(/```([\s\S]+?)```/g, '<pre><code>$1</code></pre>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/_([^_]+)_/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/(?<!href=")(?<!\()https?:\/\/[^\s<>"'\)]+/g, '<a href="$&" target="_blank" rel="noopener noreferrer">$&</a>')
        .replace(/\n/g, '<br>')
    return html
}

export default {
    name: 'MessageCard',
    components: {
        NcAvatar,
        NcButton,
        NcLoadingIcon,
        CommentOutline,
        ClipboardCheckOutline,
        HelpCircleOutline,
        CheckCircle,
        Lock,
        Delete,
        Pencil,
        Pin,
        PinOff,
        CommentsSection,
        PaperclipIcon,
    },
    props: {
        message:      { type: Object,  required: true },
        canPin:       { type: Boolean, default: false },
        isPinnedSlot: { type: Boolean, default: false },
    },
    data() {
        return {
            commentsOpen: false,
            pollResults: { votes: {}, userVote: null, totalVotes: 0 },
            votingInProgress: false,
            previews: [],
            editing: false,
            editSubject: '',
            editBody: '',
            saving: false,
        }
    },
    computed: {
        ...mapGetters(['commentsForMessage']),
        isPriority() { return this.message.priority === 'priority' },
        isPollClosed() { return this.message.pollClosed === true },
        isQuestionSolved() { return this.message.questionSolved === true },
        renderedMessage() { return renderMarkdown(this.message.message) },
        formattedDate() {
            return new Date(this.message.created_at * 1000).toLocaleString()
        },
        commentCount() { return this.message.comment_count || 0 },
        commentLabel() {
            if (this.commentCount === 0) return t('teamhub', 'Comment')
            return this.commentCount === 1
                ? t('teamhub', '1 comment')
                : t('teamhub', '{n} comments', { n: this.commentCount })
        },
        pollOptions() {
            if (!this.message.pollOptions) {
                return []
            }
            if (Array.isArray(this.message.pollOptions)) {
                return this.message.pollOptions
            }
            try {
                const options = JSON.parse(this.message.pollOptions)
                return options
            } catch (e) {
                return []
            }
        },
        isAuthor() {
            return this.$store.state.currentUser?.uid === this.message.author_id
        },
    },
    mounted() {
        if (this.message.messageType === 'poll') {
            this.loadPollResults()
        }
        this.loadPreviews()
    },
    methods: {
        t,
        async doPin() {
            try {
                await this.$store.dispatch('pinMessage', {
                    teamId: this.$store.state.currentTeamId,
                    messageId: this.message.id,
                })
                showSuccess(t('teamhub', 'Message pinned'))
            } catch (e) {
                showError(t('teamhub', 'Failed to pin message'))
            }
        },
        async doUnpin() {
            try {
                await this.$store.dispatch('unpinMessage', {
                    teamId: this.$store.state.currentTeamId,
                    messageId: this.message.id,
                })
                showSuccess(t('teamhub', 'Message unpinned'))
            } catch (e) {
                showError(t('teamhub', 'Failed to unpin message'))
            }
        },
        /**
         * Build preview cards for all URLs found in the message.
         *
         * Two-tier resolution per URL:
         *  1. Direct image URL (jpg/png/gif/webp/svg/avif) → image thumbnail card
         *  2. Our PHP Open Graph proxy  → title / description / og:image card
         *
         * Named file attachments (📎 links) that cannot be resolved get a fallback
         * file card so the user can still click through to the file.
         * Bare web URLs that yield no metadata are silently dropped — the inline
         * clickable link in the message body is sufficient.
         *
         * Cap: first 5 URLs per message.
         */
        async loadPreviews() {
            const urlObjs = extractUrlObjects(this.message.message || '')
            if (!urlObjs.length) return

            const results = []

            for (const { url, label, isAttachment } of urlObjs.slice(0, 5)) {

                // ── Tier 1: the URL itself is an image ──────────────────
                if (isImageUrl(url)) {
                    results.push({
                        url,
                        title:       filenameFromUrl(url),
                        description: null,
                        image:       url,
                        site_name:   null,
                        type:        'image',
                    })
                    continue
                }

                // ── Tier 2: PHP Open Graph proxy ────────────────────────
                try {
                    const resp = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/preview'),
                        { params: { url } }
                    )
                    const d = resp.data
                    if (d && (d.title || d.image)) {
                        results.push({
                            url,
                            title:       d.title || filenameFromUrl(url),
                            description: d.description || null,
                            image:       d.image || null,
                            site_name:   d.site_name || null,
                            type:        d.is_image ? 'image' : 'og',
                        })
                        continue
                    }
                } catch (e) {
                    // 204 no content or network error — fall through to fallback
                }

                // ── Fallback: file card for named attachments only ───────
                // Bare web URLs that resolve to nothing are silently dropped —
                // their clickable inline link is already visible in the body.
                if (isAttachment) {
                    results.push({
                        url,
                        title:       filenameFromUrl(url),
                        description: null,
                        image:       null,
                        site_name:   null,
                        type:        'file',
                    })
                }
            }

            this.previews = results
        },

        toggleComments() {
            this.commentsOpen = !this.commentsOpen
            if (this.commentsOpen) {
                this.$store.dispatch('fetchComments', this.message.id)
            }
        },
        async confirmDelete() {
            if (!confirm(t('teamhub', 'Are you sure you want to delete this message?'))) {
                return
            }
            
            try {
                await this.$store.dispatch('deleteMessage', {
                    teamId: this.$store.state.currentTeamId,
                    messageId: this.message.id
                })
                showSuccess(t('teamhub', 'Message deleted'))
            } catch (error) {
                showError(t('teamhub', 'Failed to delete message'))
            }
        },

        startEdit() {
            this.editSubject = this.message.subject || ''
            this.editBody = this.message.message || ''
            this.editing = true
        },

        cancelEdit() {
            this.editing = false
        },

        async saveEdit() {
            if (!this.editSubject.trim() || !this.editBody.trim()) return
            this.saving = true
            try {
                await this.$store.dispatch('updateMessage', {
                    teamId: this.$store.state.currentTeamId,
                    messageId: this.message.id,
                    subject: this.editSubject.trim(),
                    message: this.editBody.trim(),
                })
                this.editing = false
                showSuccess(t('teamhub', 'Message updated'))
            } catch (error) {
                showError(t('teamhub', 'Failed to update message'))
            } finally {
                this.saving = false
            }
        },
        async loadPollResults() {
            try {
                const response = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/messages/${this.message.id}/poll-results`)
                )
                this.pollResults = response.data
            } catch (error) {
            }
        },
        async vote(optionIndex) {
            if (this.votingInProgress || this.pollResults.userVote === optionIndex || this.isPollClosed) return
            
            this.votingInProgress = true
            try {
                const response = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/messages/${this.message.id}/vote`),
                    { optionIndex }
                )
                this.pollResults = response.data
                showSuccess(t('teamhub', 'Vote recorded!'))
            } catch (error) {
                showError(t('teamhub', 'Failed to vote'))
            } finally {
                this.votingInProgress = false
            }
        },
        async closePoll() {
            if (!confirm(t('teamhub', 'Close this poll? No more votes will be accepted.'))) {
                return
            }
            
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/messages/${this.message.id}/close-poll`)
                )
                this.message.pollClosed = true
                showSuccess(t('teamhub', 'Poll closed'))
            } catch (error) {
                const errorMsg = error?.response?.data?.error || error?.message || 'Unknown error'
                showError(t('teamhub', 'Failed to close poll: {error}', { error: errorMsg }))
            }
        },
        async markSolved(commentId) {
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/messages/${this.message.id}/mark-solved`),
                    { commentId }
                )
                this.message.questionSolved = true
                this.message.solvedCommentId = commentId
                showSuccess(t('teamhub', 'Question marked as solved'))
                // Refresh to show updated state
                this.$store.dispatch('fetchComments', this.message.id)
            } catch (error) {
                const errorMsg = error?.response?.data?.error || error?.message || 'Unknown error'
                showError(t('teamhub', 'Failed to mark question as solved: {error}', { error: errorMsg }))
            }
        },
        async unmarkSolved() {
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/messages/${this.message.id}/unmark-solved`)
                )
                this.message.questionSolved = false
                this.message.solvedCommentId = null
                showSuccess(t('teamhub', 'Question unmarked'))
                // Refresh to show updated state
                this.$store.dispatch('fetchComments', this.message.id)
            } catch (error) {
                const errorMsg = error?.response?.data?.error || error?.message || 'Unknown error'
                showError(t('teamhub', 'Failed to unmark question: {error}', { error: errorMsg }))
            }
        },
        getPercentage(index) {
            if (this.pollResults.totalVotes === 0) return 0
            const votes = this.pollResults.votes[index] || 0
            return Math.round((votes / this.pollResults.totalVotes) * 100)
        },
        getPollVotes(index) {
            const votes = this.pollResults.votes[index] || 0
            return votes === 1 ? '1 vote' : `${votes} votes`
        },
    },
}
</script>

<style scoped>
.message-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    width: 100%;
    box-sizing: border-box;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: box-shadow 0.2s ease;
}

.message-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.message-card--priority {
    border-left: 4px solid var(--color-error);
    background: color-mix(in srgb, var(--color-error) 3%, var(--color-main-background));
}

.message-card--question-solved {
    border-left: 4px solid var(--color-success);
    background: color-mix(in srgb, var(--color-success) 2%, var(--color-main-background));
}

.message-card--pinned {
    border-left: 4px solid var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 3%, var(--color-main-background));
}

.message-card__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.message-card__meta {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
}

.message-card__author {
    font-weight: 600;
    font-size: 15px;
    color: var(--color-main-text);
}

.message-card__date {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.message-card__priority-badge {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: var(--border-radius-pill);
    background: var(--color-error);
    color: white;
    font-weight: 600;
    white-space: nowrap;
    margin-left: auto;
    flex-shrink: 0;
}

.message-card__question-badge-subtle {
    display: flex;
    align-items: center;
    color: var(--color-primary-element);
    opacity: 0.8;
    margin-left: auto;
    flex-shrink: 0;
}

.message-card__subject {
    font-size: 1.1em;
    font-weight: 700;
    margin: 0 0 12px;
    color: var(--color-main-text);
    word-break: break-word;
    line-height: 1.4;
}

.message-card__body {
    color: var(--color-main-text);
    font-size: 14px;
    line-height: 1.7;
    word-break: break-word;
    overflow-wrap: anywhere;
    margin-bottom: 16px;
}

.message-card__body :deep(code) {
    background: var(--color-background-dark);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 13px;
}

.message-card__body :deep(pre) {
    background: var(--color-background-dark);
    padding: 12px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 12px 0;
}

.message-card__body :deep(a) {
    color: var(--color-primary-element);
    text-decoration: underline;
}

.message-card__footer {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
}

.comments-enter-active, .comments-leave-active {
    transition: opacity 0.2s, transform 0.2s;
}
.comments-enter, .comments-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

/* ── Link / attachment preview cards ──────────────────────────────────── */
.message-card__previews {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

/* Base card — shared by all three types */
.message-preview {
    display: flex;
    align-items: stretch;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    background: var(--color-background-hover);
    transition: box-shadow 0.15s, border-color 0.15s;
}

.message-preview:hover {
    border-color: var(--color-primary-element);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

/* ── Image thumbnail card ──────────────────────────────────────────────── */
.message-preview--image-only {
    flex-direction: column;
    align-items: flex-start;
    max-width: 360px;
    background: var(--color-background-dark);
}

.message-preview__thumbnail {
    width: 100%;
    max-height: 220px;
    object-fit: cover;
    display: block;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.message-preview__image-caption {
    display: block;
    padding: 6px 10px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    box-sizing: border-box;
}

/* ── OG / rich link card ───────────────────────────────────────────────── */
.message-preview--og {
    max-height: 110px;
}

.message-preview__og-image {
    width: 130px;
    min-width: 130px;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--color-background-dark);
}

/* ── File fallback card ────────────────────────────────────────────────── */
.message-preview--file {
    align-items: center;
    padding: 10px 14px;
    gap: 12px;
}

.message-preview__file-icon {
    flex-shrink: 0;
    color: var(--color-text-maxcontrast);
}

/* ── Shared body / text ────────────────────────────────────────────────── */
.message-preview__body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
    padding: 10px 14px;
    overflow: hidden;
    flex: 1;
}

.message-preview--file .message-preview__body {
    padding: 0;
}

.message-preview__provider {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-primary-element);
}

.message-preview__title {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.message-preview__desc {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    line-height: 1.4;
}

.poll-widget {
    margin-top: 20px;
    margin-bottom: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 16px;
    background: var(--color-background-hover);
}

.poll-option {
    position: relative;
    padding: 14px 16px;
    margin-bottom: 10px;
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    border: 2px solid var(--color-border);
    overflow: hidden;
    transition: all 0.2s;
}

.poll-option--clickable {
    cursor: pointer;
}

.poll-option--clickable:hover {
    border-color: var(--color-primary-element);
    transform: translateX(3px);
}

.poll-option--voted {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

.poll-option__bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    background: var(--color-primary-element);
    opacity: 0.15;
    transition: width 0.3s ease;
}

.poll-option__content {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1;
}

.poll-option__text {
    font-weight: 500;
    font-size: 14px;
}

.poll-option__votes {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    font-weight: 600;
}

.poll-footer {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.poll-closed-label {
    font-weight: 600;
    color: var(--color-text-maxcontrast);
}

/* Question solved banner */
.question-solved-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;
    padding: 12px 16px;
    background: var(--color-success);
    color: var(--color-main-background);
    border-radius: var(--border-radius-large);
    font-size: 14px;
    font-weight: 600;
}

.message-card__edit {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 8px 0;
}

.message-card__edit-subject,
.message-card__edit-body {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    resize: vertical;
}

.message-card__edit-subject:focus,
.message-card__edit-body:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.message-card__edit-actions {
    display: flex;
    gap: 8px;
}
</style>
