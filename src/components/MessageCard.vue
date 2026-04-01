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

        <!-- Link previews -->
        <div v-if="previews.length" class="message-card__previews">
            <a
                v-for="(preview, i) in previews"
                :key="i"
                :href="preview.url"
                target="_blank"
                rel="noopener noreferrer"
                class="message-preview">
                <img
                    v-if="preview.image"
                    :src="preview.image"
                    :alt="preview.title || 'Preview'"
                    class="message-preview__image" />
                <div class="message-preview__body">
                    <span v-if="preview.provider" class="message-preview__provider">{{ preview.provider }}</span>
                    <span class="message-preview__title">{{ preview.title || preview.url }}</span>
                    <span v-if="preview.description" class="message-preview__desc">{{ preview.description }}</span>
                </div>
            </a>
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

// Extract all URLs from message text (markdown links + bare URLs)
function extractUrls(text) {
    if (!text) return []
    const urls = []
    // Markdown links: [label](url)
    const mdRe = /\[([^\]]+)\]\(([^)]+)\)/g
    let m
    while ((m = mdRe.exec(text)) !== null) {
        try { urls.push(new URL(m[2]).href) } catch (e) {}
    }
    // Bare URLs not inside markdown
    const bareRe = /(?<!\()https?:\/\/[^\s<>"'\)]+/g
    while ((m = bareRe.exec(text)) !== null) {
        try { urls.push(new URL(m[0]).href) } catch (e) {}
    }
    // Deduplicate
    return [...new Set(urls)]
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
        async loadPreviews() {
            const urls = extractUrls(this.message.message || '')
            if (!urls.length) return

            // Use NC's reference/preview API — resolves rich metadata for known providers
            // Only show a preview card when the API actually returns useful metadata.
            // If nothing resolves (e.g. Deck links NC can't resolve), show nothing —
            // the link in the message body is already clickable.
            const results = []
            for (const url of urls.slice(0, 3)) {
                try {
                    const resp = await axios.post(
                        generateUrl('/ocs/v2.php/references/resolve'),
                        { references: [url] },
                        { headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json' } }
                    )
                    const refData = resp.data?.ocs?.data?.references?.[url]
                    // Only add a preview card when we have real metadata — title OR image
                    // An empty/null response means the API couldn't resolve it; skip.
                    if (refData && (refData.title || refData.imageUrl)) {
                        results.push({
                            url,
                            title:       refData.title || null,
                            description: refData.description || null,
                            image:       refData.imageUrl || null,
                            provider:    refData.richObjectType || refData.openGraphObject?.name || null,
                        })
                    }
                    // else: API returned nothing useful — the inline link is enough, no card
                } catch (e) {
                    // API not available or network error — skip silently, inline link still works
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
                console.error('Failed to load poll results:', error)
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
                console.error('Failed to close poll:', error)
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
                console.error('Failed to mark question as solved:', error)
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
                console.error('Failed to unmark question:', error)
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

/* Link preview cards */
.message-card__previews {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.message-preview {
    display: flex;
    align-items: stretch;
    gap: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    background: var(--color-background-hover);
    transition: box-shadow 0.15s, border-color 0.15s;
    max-height: 100px;
}

.message-preview:hover {
    border-color: var(--color-primary-element);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.message-preview__image {
    width: 120px;
    min-width: 120px;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--color-background-dark);
}

.message-preview__body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
    padding: 10px 14px;
    overflow: hidden;
    flex: 1;
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
