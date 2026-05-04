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
                            <!-- Delete button — author or team admin, hidden during edit -->
                            <NcButton
                                v-if="canDeleteComment(c) && editingCommentId !== c.id"
                                type="tertiary"
                                :aria-label="t('teamhub', 'Delete comment')"
                                @click="askDeleteComment(c)">
                                <template #icon><Delete :size="14" /></template>
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
                        ref="commentEditor"
                        v-model="newComment"
                        :placeholder="commentPlaceholder"
                        :multiline="true"
                        :disabled="questionSolved"
                        @keydown.ctrl.enter="submit" />
                    <!-- Markdown formatting toolbar for comments -->
                    <div class="comments-section__md-toolbar" role="toolbar" :aria-label="t('teamhub', 'Formatting')">
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Bold')"
                            :aria-label="t('teamhub', 'Bold')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('**', '**', t('teamhub', 'bold text'))">
                            <template #icon><FormatBold :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Italic')"
                            :aria-label="t('teamhub', 'Italic')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('*', '*', t('teamhub', 'italic text'))">
                            <template #icon><FormatItalic :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Inline code')"
                            :aria-label="t('teamhub', 'Inline code')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('`', '`', t('teamhub', 'code'))">
                            <template #icon><CodeTags :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Code block')"
                            :aria-label="t('teamhub', 'Code block')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('```\n', '\n```', t('teamhub', 'code block'))">
                            <template #icon><CodeBraces :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Heading')"
                            :aria-label="t('teamhub', 'Heading')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('## ', '', t('teamhub', 'Heading'))">
                            <template #icon><FormatHeader2 :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Bullet list')"
                            :aria-label="t('teamhub', 'Bullet list')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyMarkdown('- ', '', t('teamhub', 'list item'))">
                            <template #icon><FormatListBulleted :size="14" /></template>
                        </NcButton>
                        <NcButton
                            type="tertiary"
                            :title="t('teamhub', 'Link')"
                            :aria-label="t('teamhub', 'Insert link')"
                            :disabled="questionSolved"
                            @mousedown.prevent
                            @click="applyLink">
                            <template #icon><LinkVariant :size="14" /></template>
                        </NcButton>
                    </div>
                    <NcButton
                        type="primary"
                        :disabled="!newComment.trim() || questionSolved"
                        @click="submit">
                        {{ t('teamhub', 'Send') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Confirmation dialog for hard-deleting a comment.
             Surfaces a warning when the comment being removed is the marked
             answer to a solved question, since deletion will revert the
             question to unsolved. -->
        <NcDialog
            v-if="pendingDeleteComment"
            :name="t('teamhub', 'Delete comment')"
            :message="deleteDialogMessage"
            size="small"
            @closing="cancelDeleteComment">
            <template #actions>
                <NcButton type="tertiary" :disabled="deletingComment" @click="cancelDeleteComment">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton type="error" :disabled="deletingComment" @click="executeDeleteComment">
                    <template v-if="deletingComment" #icon>
                        <NcLoadingIcon :size="18" />
                    </template>
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import { mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { NcAvatar, NcLoadingIcon, NcButton, NcRichContenteditable, NcDialog } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

import DOMPurify from 'dompurify'

const ALLOWED_TAGS = ['strong', 'em', 'code', 'pre', 'a', 'br', 'ul', 'ol', 'li', 'h1', 'h2', 'h3']
const ALLOWED_ATTR = ['href', 'target', 'rel']

// Shared rendering pipeline — see MessageCard.vue for the full explanation of
// the processing order. Both components use identical logic so that message
// bodies and comment bodies render consistently.
function renderMarkdown(text) {
    if (!text) return ''

    const codeBlocks = []
    let html = text.replace(/```([\s\S]+?)```/g, (_, code) => {
        codeBlocks.push(`<pre><code>${code}</code></pre>`)
        return `\u0000${codeBlocks.length - 1}\u0000`
    })

    const inlineCodes = []
    html = html.replace(/`([^`]+)`/g, (_, code) => {
        inlineCodes.push(`<code>${code}</code>`)
        return `\u0001${inlineCodes.length - 1}\u0001`
    })

    html = html
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/_([^_]+)_/g, '<em>$1</em>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
        .replace(/(?<!href=")(?<!\()https?:\/\/[^\s<>"'\)]+/g, '<a href="$&" target="_blank" rel="noopener">$&</a>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')

    html = html.replace(/((?:^- .+(?:\n|$))+)/gm, (block) => {
        const items = block.trimEnd().split('\n')
            .map(line => `<li>${line.replace(/^- /, '')}</li>`)
            .join('')
        return `<ul>${items}</ul>\n`
    })

    html = html.replace(/\n/g, '<br>')

    html = html
        .replace(/\u0000(\d+)\u0000/g, (_, i) => codeBlocks[+i])
        .replace(/\u0001(\d+)\u0001/g, (_, i) => inlineCodes[+i])

    return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR })
}

export default {
    name: 'CommentsSection',
    components: { 
        NcAvatar, 
        NcLoadingIcon, 
        NcButton, 
        NcRichContenteditable,
        NcDialog,
        CheckCircle,
        Close,
        CodeBraces,
        CodeTags,
        Delete,
        FormatBold,
        FormatHeader2,
        FormatItalic,
        FormatListBulleted,
        LinkVariant,
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
            // The comment object pending hard-delete confirmation, or null when no
            // dialog is open. Keeping the full row (not just the id) so the dialog
            // can show context-specific copy (e.g. solved-answer warning).
            pendingDeleteComment: null,
            deletingComment: false,
        }
    },
    computed: {
        ...mapGetters(['commentsForMessage', 'currentUserIsTeamAdmin']),
        comments() { return this.commentsForMessage(this.messageId) },
        currentUser() { return getCurrentUser()?.uid || '' },
        commentPlaceholder() {
            if (this.questionSolved) {
                return t('teamhub', 'This question has been solved')
            }
            return t('teamhub', 'Write a comment…')
        },
        /**
         * Body copy for the delete-confirmation dialog. When the comment being
         * removed is the marked answer to a solved question, we additionally
         * warn that deletion will revert the question to unsolved (handled
         * server-side, mirrored in the local store via UPDATE_MESSAGE).
         */
        deleteDialogMessage() {
            if (!this.pendingDeleteComment) return ''
            const isSolvedAnswer = this.messageType === 'question'
                && this.solvedCommentId
                && this.pendingDeleteComment.id === this.solvedCommentId
            if (isSolvedAnswer) {
                // TRANSLATORS: Shown in a confirmation dialog when the user tries to delete a comment that is the accepted answer to a question. Deletion will revert the question to unsolved.
                return t('teamhub', 'This comment is the marked answer. Deleting it will mark the question as unsolved.')
            }
            return t('teamhub', 'This comment will be permanently deleted. This cannot be undone.')
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

        // ── Markdown toolbar ──────────────────────────────────────────────
        // Same mechanic as PostMessageForm: @mousedown.prevent on the buttons
        // keeps the contenteditable's cursor alive; execCommand fires into it.
        applyMarkdown(before, after, placeholder = '') {
        
            const editorEl = this.$refs.commentEditor?.$el?.querySelector('.rich-contenteditable__input')
                          || this.$refs.commentEditor?.$el

            if (!editorEl) {
                this.newComment += before + (placeholder || '') + after
                return
            }

            const activeEl = document.activeElement
            const editorHasFocus = editorEl === activeEl || editorEl.contains(activeEl)

            if (editorHasFocus) {
                const sel = window.getSelection()
                const selectedText = (sel && !sel.isCollapsed) ? sel.toString() : (placeholder || '')
                document.execCommand('insertText', false, before + selectedText + after)
            } else {
                const selectedText = placeholder || ''
                this.newComment += (this.newComment && !this.newComment.endsWith('\n') ? '\n' : '') + before + selectedText + after
                this.$nextTick(() => editorEl.focus())
            }
        },

        applyLink() {
            const editorEl = this.$refs.commentEditor?.$el?.querySelector('.rich-contenteditable__input')
                          || this.$refs.commentEditor?.$el

            const sel = window.getSelection()
            const selectedText = (sel && !sel.isCollapsed) ? sel.toString() : ''
            const label = selectedText || t('teamhub', 'link text')

            if (editorEl && (editorEl === document.activeElement || editorEl.contains(document.activeElement))) {
                document.execCommand('insertText', false, `[${label}](url)`)
            } else {
                this.newComment += `[${label}](url)`
                this.$nextTick(() => editorEl?.focus())
            }
        },
        /**
         * Permission predicate for the delete button.
         * Mirrors backend gating (CommentController::deleteComment): the comment
         * author may always delete their own comment; team admins may delete
         * any comment. The backend remains authoritative — this is purely a
         * UI-affordance check, not a security boundary.
         */
        canDeleteComment(comment) {
            if (!comment) return false
            if (comment.author_id === this.currentUser) return true
            return this.currentUserIsTeamAdmin
        },
        askDeleteComment(comment) {
            this.pendingDeleteComment = comment
        },
        cancelDeleteComment() {
            if (this.deletingComment) return
            this.pendingDeleteComment = null
        },
        async executeDeleteComment() {
            if (!this.pendingDeleteComment || this.deletingComment) return
            const commentId = this.pendingDeleteComment.id
            this.deletingComment = true
            try {
                await this.$store.dispatch('deleteComment', {
                    messageId: this.messageId,
                    commentId,
                })
                this.pendingDeleteComment = null
            } catch (e) {
                const status = e?.response?.status
                let msg
                if (status === 403) {
                    msg = t('teamhub', 'You do not have permission to delete this comment.')
                } else if (status === 404) {
                    msg = t('teamhub', 'This comment no longer exists.')
                } else {
                    msg = t('teamhub', 'Failed to delete comment')
                }
                showError(msg)
                        } finally {
                this.deletingComment = false
            }
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
    border-color: var(--color-primary-element);
}

.comment__edit-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.comment__edit-actions {
    display: flex;
    gap: 6px;
}

/* Markdown formatting toolbar below the comment input */
.comments-section__md-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-wrap: wrap;
}
</style>
