<template>
    <div class="comments-section" :class="{ 'comments-section--solved': questionSolved }">
        <div v-if="loading" class="comments-section__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <div v-else>
            <div v-if="comments.length === 0" class="comments-section__empty">
                {{ t('teamhub', 'No comments yet') }}
            </div>

            <div v-else class="comments-section__list">
                <div 
                    v-for="c in comments" 
                    :key="c.id" 
                    class="comment"
                    :class="{ 
                        'comment--solved': messageType === 'question' && c.id === solvedCommentId
                    }">
                    <NcAvatar :user="c.author_id" :display-name="c.author_id" :size="28" />
                    <div class="comment__content">
                        <div class="comment__header">
                            <span class="comment__author">{{ c.author_id }}</span>
                            <span class="comment__date">{{ formatDate(c.created_at) }}</span>
                            <!-- Solved badge for the answer -->
                            <span v-if="messageType === 'question' && c.id === solvedCommentId" class="comment__solved-badge">
                                <CheckCircle :size="14" />
                                {{ t('teamhub', 'Answer') }}
                            </span>
                            <!-- Mark as solved button (only visible to question author) -->
                            <NcButton
                                v-if="messageType === 'question' && isAuthor && !questionSolved"
                                type="tertiary"
                                :aria-label="t('teamhub', 'Mark as answer')"
                                @click="$emit('mark-solved', c.id)">
                                <template #icon><CheckCircle :size="14" /></template>
                            </NcButton>
                            <!-- Edit button (own comments only) -->
                            <NcButton
                                v-if="c.author_id === currentUser && editingCommentId !== c.id"
                                type="tertiary"
                                :aria-label="t('teamhub', 'Edit comment')"
                                @click="startEditComment(c)">
                                <template #icon><Pencil :size="14" /></template>
                            </NcButton>
                        </div>

                        <!-- Edit mode -->
                        <div v-if="editingCommentId === c.id" class="comment__edit">
                            <textarea
                                v-model="editCommentText"
                                class="comment__edit-input"
                                rows="3"
                                @keydown.ctrl.enter="saveCommentEdit(c)" />
                            <div class="comment__edit-actions">
                                <NcButton type="primary" :disabled="savingComment" @click="saveCommentEdit(c)">
                                    {{ t('teamhub', 'Save') }}
                                </NcButton>
                                <NcButton type="tertiary" @click="cancelCommentEdit">{{ t('teamhub', 'Cancel') }}</NcButton>
                            </div>
                        </div>

                        <!-- View mode -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div v-else class="comment__body" v-html="renderMarkdown(c.comment)" />
                    </div>
                </div>
            </div>

            <!-- Unmark solved button (only for question author when solved) -->
            <div v-if="messageType === 'question' && isAuthor && questionSolved" class="comments-section__unmark">
                <NcButton
                    type="tertiary"
                    @click="$emit('unmark-solved')">
                    <template #icon><Close :size="16" /></template>
                    {{ t('teamhub', 'Unmark as solved') }}
                </NcButton>
            </div>

            <!-- Add comment (greyed out if question is solved) -->
            <div class="comments-section__add" :class="{ 'comments-section__add--disabled': questionSolved }">
                <NcAvatar :user="currentUser" :display-name="currentUser" :size="28" />
                <div class="comments-section__input">
                    <NcRichContenteditable
                        v-model="newComment"
                        :placeholder="commentPlaceholder"
                        :multiline="true"
                        :disabled="questionSolved"
                        @keydown.ctrl.enter="submit" />
                    <NcButton
                        type="primary"
                        :disabled="!newComment.trim() || questionSolved"
                        @click="submit">
                        {{ t('teamhub', 'Send') }}
                    </NcButton>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { NcAvatar, NcLoadingIcon, NcButton, NcRichContenteditable } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

function renderMarkdown(text) {
    if (!text) return ''
    return text
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
        .replace(/\n/g, '<br>')
}

export default {
    name: 'CommentsSection',
    components: { 
        NcAvatar, 
        NcLoadingIcon, 
        NcButton, 
        NcRichContenteditable,
        CheckCircle,
        Close,
        Pencil,
    },
    props: {
        messageId: { type: Number, required: true },
        messageType: { type: String, default: 'normal' },
        isAuthor: { type: Boolean, default: false },
        questionSolved: { type: Boolean, default: false },
        solvedCommentId: { type: Number, default: null },
    },
    emits: ['mark-solved', 'unmark-solved'],
    data() {
        return {
            newComment: '',
            loading: false,
            editingCommentId: null,
            editCommentText: '',
            savingComment: false,
        }
    },
    computed: {
        ...mapGetters(['commentsForMessage']),
        comments() { return this.commentsForMessage(this.messageId) },
        currentUser() { return getCurrentUser()?.uid || '' },
        commentPlaceholder() {
            if (this.questionSolved) {
                return t('teamhub', 'This question has been solved')
            }
            return t('teamhub', 'Write a comment…')
        },
    },
    methods: {
        t,
        renderMarkdown,
        formatDate(ts) { return new Date(ts * 1000).toLocaleString() },
        startEditComment(comment) {
            this.editingCommentId = comment.id
            this.editCommentText = comment.comment
        },
        cancelCommentEdit() {
            this.editingCommentId = null
            this.editCommentText = ''
        },
        async saveCommentEdit(comment) {
            if (!this.editCommentText.trim()) return
            this.savingComment = true
            try {
                await this.$store.dispatch('updateComment', {
                    messageId: this.messageId,
                    commentId: comment.id,
                    comment: this.editCommentText.trim(),
                })
                this.editingCommentId = null
                this.editCommentText = ''
            } catch (e) {
                // showError is not imported here — just log
                showError(t('teamhub', 'Failed to update comment'))
            } finally {
                this.savingComment = false
            }
        },
        async submit() {
            if (!this.newComment.trim() || this.questionSolved) return
            await this.$store.dispatch('postComment', {
                messageId: this.messageId,
                comment: this.newComment.trim(),
            })
            this.newComment = ''
        },
    },
}
</script>

<style scoped>
.comments-section {
    margin-top: 12px;
    padding: 12px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
}

.comments-section--solved {
    background: color-mix(in srgb, var(--color-success) 5%, var(--color-background-dark));
}

.comments-section__loading { 
    display: flex; 
    justify-content: center; 
    padding: 12px; 
}

.comments-section__empty { 
    color: var(--color-text-maxcontrast); 
    font-size: 13px; 
    padding: 4px 0 8px; 
}

.comments-section__list { 
    display: flex; 
    flex-direction: column; 
    gap: 12px; 
    margin-bottom: 12px; 
}

.comment { 
    display: flex; 
    gap: 10px; 
    align-items: flex-start; 
    padding: 8px;
    border-radius: 6px;
    transition: background 0.2s;
}

.comment--solved {
    background: var(--color-success);
    color: var(--color-main-background);
    border: 2px solid var(--color-success);
}

.comment--solved .comment__author,
.comment--solved .comment__date,
.comment--solved .comment__body {
    color: var(--color-main-background);
}

.comment__content { 
    flex: 1; 
    min-width: 0; 
}

.comment__header { 
    display: flex; 
    gap: 8px; 
    align-items: center; 
    margin-bottom: 4px; 
}

.comment__author { 
    font-weight: 600; 
    font-size: 13px; 
}

.comment__date { 
    font-size: 11px; 
    color: var(--color-text-maxcontrast); 
}

.comment__solved-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    background: var(--color-main-background);
    color: var(--color-success-text);
    margin-left: auto;
}

.comment__body { 
    font-size: 13px; 
    line-height: 1.5; 
    word-break: break-word; 
}

.comment__body :deep(code) {
    background: var(--color-background-dark);
    padding: 2px 4px;
    border-radius: 3px;
}

.comment--solved .comment__body :deep(code) {
    background: rgba(255, 255, 255, 0.2);
    color: var(--color-main-background);
}

.comments-section__unmark {
    padding: 8px 0;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--color-border-dark);
}

.comments-section__add { 
    display: flex; 
    gap: 10px; 
    align-items: flex-start; 
    padding-top: 10px; 
    border-top: 1px solid var(--color-border-dark); 
}

.comments-section__add--disabled {
    opacity: 0.5;
    pointer-events: none;
}

.comments-section__input { 
    flex: 1; 
    display: flex; 
    flex-direction: column; 
    gap: 8px; 
}

.comment__edit {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 4px;
}

.comment__edit-input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    font-family: inherit;
    box-sizing: border-box;
    resize: vertical;
}

.comment__edit-input:focus {
    outline: none;
    border-color: var(--color-primary-element);
}

.comment__edit-actions {
    display: flex;
    gap: 6px;
}
</style>
