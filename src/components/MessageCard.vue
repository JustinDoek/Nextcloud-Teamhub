<template>
    <div class="message-card" :class="{
        'message-card--priority': isPriority,
        'message-card--question-solved': isQuestionSolved,
        'message-card--pinned': isPinnedSlot,
        'message-card--decision': isDecision,
        'message-card--decision-open':       isDecision && (decisionStatus === 'open' || decisionStatus === 'proposed'),
        'message-card--decision-finalized':  isDecision && decisionStatus === 'finalized',
        'message-card--decision-approved':   isDecision && (decisionStatus === 'approved' || decisionStatus === 'decided'),
        'message-card--decision-denied':     isDecision && decisionStatus === 'denied',
        'message-card--decision-withdrawn':  isDecision && decisionStatus === 'withdrawn',
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
            <!-- Decision badge with impact + status -->
            <span
                v-if="isDecision"
                class="message-card__decision-badge"
                :class="'message-card__decision-badge--' + (decision.impact || 'medium')"
                :title="decisionBadgeTitle">
                <GavelIcon :size="14" />
                <span class="message-card__decision-badge-impact">{{ impactLabel(decision.impact) }}</span>
                <span class="message-card__decision-badge-dot" aria-hidden="true">·</span>
                <span class="message-card__decision-badge-status">{{ statusLabel(decisionStatus) }}</span>
            </span>
            <!-- Unpin button — shown on the pinned slot to users with pin rights -->
            <NcButton
                v-if="canPin && isPinnedSlot"
                variant="tertiary"
                :aria-label="t('teamhub', 'Unpin message')"
                :title="t('teamhub', 'Unpin message')"
                @click="doUnpin">
                <template #icon><PinOff :size="16" /></template>
            </NcButton>
            <!-- Pin button — shown on regular messages to users with pin rights -->
            <NcButton
                v-else-if="canPin && !isPinnedSlot"
                variant="tertiary"
                :aria-label="t('teamhub', 'Pin message')"
                :title="t('teamhub', 'Pin message')"
                @click="doPin">
                <template #icon><Pin :size="16" /></template>
            </NcButton>
            <NcButton
                v-if="isAuthor"
                variant="tertiary"
                :aria-label="t('teamhub', 'Edit message')"
                @click="startEdit">
                <template #icon><Pencil :size="16" /></template>
            </NcButton>
            <NcButton
                v-if="isAuthor"
                variant="tertiary"
                :aria-label="t('teamhub', 'Delete message')"
                @click="confirmDelete">
                <template #icon><Delete :size="16" /></template>
            </NcButton>
        </div>

        <!-- Edit mode -->
        <div v-if="editing" class="message-card__edit">
            <label class="message-card__edit-label" :for="'edit-subject-' + message.id">
                {{ t('teamhub', 'Subject') }}
            </label>
            <input
                :id="'edit-subject-' + message.id"
                v-model="editSubject"
                class="message-card__edit-subject"
                :placeholder="t('teamhub', 'Subject')" />
            <label class="message-card__edit-label" :for="'edit-body-' + message.id">
                {{ t('teamhub', 'Message') }}
            </label>
            <NcRichContenteditable
                :id="'edit-body-' + message.id"
                ref="editBodyRef"
                v-model="editBody"
                :placeholder="t('teamhub', 'Write your message… (@ to mention)')"
                :multiline="true"
                :link-autocomplete="true"
                :auto-complete="editMentionAutoComplete"
                :user-data="mentionsObj" />
            <!-- Markdown formatting toolbar for the edit body textarea.
                 Uses selectionStart/End (plain textarea API) rather than
                 execCommand, so no contenteditable quirks.
                 @mousedown.prevent keeps the textarea selection alive while
                 the button click is processed. -->
            <div class="message-card__edit-md-toolbar" role="toolbar" :aria-label="t('teamhub', 'Formatting')">
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Bold (Ctrl+B)')"
                    :aria-label="t('teamhub', 'Bold')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('**', '**', t('teamhub', 'bold text'))">
                    <template #icon><FormatBold :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Italic (Ctrl+I)')"
                    :aria-label="t('teamhub', 'Italic')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('*', '*', t('teamhub', 'italic text'))">
                    <template #icon><FormatItalic :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Inline code')"
                    :aria-label="t('teamhub', 'Inline code')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('`', '`', t('teamhub', 'code'))">
                    <template #icon><CodeTags :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Code block')"
                    :aria-label="t('teamhub', 'Code block')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('```\n', '\n```', t('teamhub', 'code block'))">
                    <template #icon><CodeBraces :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Heading')"
                    :aria-label="t('teamhub', 'Heading')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('## ', '', t('teamhub', 'Heading'))">
                    <template #icon><FormatHeader2 :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Bullet list')"
                    :aria-label="t('teamhub', 'Bullet list')"
                    @mousedown.prevent
                    @click="applyEditMarkdown('- ', '', t('teamhub', 'list item'))">
                    <template #icon><FormatListBulleted :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Link')"
                    :aria-label="t('teamhub', 'Insert link')"
                    @mousedown.prevent
                    @click="applyEditLink">
                    <template #icon><LinkVariant :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Insert image by URL')"
                    :aria-label="t('teamhub', 'Insert image')"
                    @mousedown.prevent
                    @click="openImageDialog">
                    <template #icon><ImageIcon :size="16" /></template>
                </NcButton>
            </div>
            <div class="message-card__edit-actions">
                <NcButton variant="primary" :disabled="saving" @click="saveEdit">
                    <template #icon><NcLoadingIcon v-if="saving" :size="16" /></template>
                    {{ t('teamhub', 'Save') }}
                </NcButton>
                <NcButton variant="tertiary" @click="cancelEdit">{{ t('teamhub', 'Cancel') }}</NcButton>
            </div>
        </div>

        <!-- View mode -->
        <template v-else>
            <!-- Subject -->
            <h3 class="message-card__subject">{{ message.subject }}</h3>

            <!-- Body (markdown rendered) -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="message-card__body" v-html="renderedMessage" @click="onBodyClick" @keydown="onBodyKeydown" />
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
                :role="isPollClosed ? 'listitem' : 'button'"
                :tabindex="isPollClosed || pollResults.userVote === index ? -1 : 0"
                :aria-pressed="!isPollClosed ? pollResults.userVote === index : undefined"
                :aria-label="isPollClosed
                    ? t('teamhub', '{option}: {votes}', { option, votes: getPollVotes(index) })
                    : (pollResults.userVote === index
                        ? t('teamhub', '{option} — your vote', { option })
                        : t('teamhub', 'Vote for: {option}', { option }))"
                :aria-disabled="isPollClosed || pollResults.userVote === index ? 'true' : undefined"
                @click="isPollClosed ? null : vote(index)"
                @keydown.enter.prevent="isPollClosed ? null : vote(index)"
                @keydown.space.prevent="isPollClosed ? null : vote(index)">
                
                <div class="poll-option__bar" :style="{ width: getPercentage(index) + '%' }" />
                
                <div class="poll-option__content">
                    <span class="poll-option__text">{{ option }}</span>
                    <span class="poll-option__right">
                        <!-- Visible non-color indicator for "your vote" (WCAG 1.4.1) -->
                        <CheckCircleOutline
                            v-if="pollResults.userVote === index"
                            :size="16"
                            class="poll-option__voted-icon"
                            aria-hidden="true" />
                        <span class="poll-option__votes">{{ getPollVotes(index) }}</span>
                    </span>
                </div>
            </div>
            
            <div class="poll-footer">
                <ClipboardCheckOutline :size="16" />
                <span v-if="isPollClosed" class="poll-closed-label">
                    {{
                        // TRANSLATORS: total vote count when poll is closed, e.g. "1 total vote – Poll closed"
                        n('teamhub', '{total} total vote \u2013 Poll closed', '{total} total votes \u2013 Poll closed', pollResults.totalVotes, { total: pollResults.totalVotes })
                    }}
                </span>
                <span v-else>
                    {{
                        // TRANSLATORS: live vote count while poll is open, e.g. "1 total vote"
                        n('teamhub', '{total} total vote', '{total} total votes', pollResults.totalVotes, { total: pollResults.totalVotes })
                    }}
                </span>
                <NcButton
                    v-if="isAuthor && !isPollClosed"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Close poll')"
                    @click="closePoll">
                    <template #icon><Lock :size="16" /></template>
                    {{ t('teamhub', 'Close poll') }}
                </NcButton>
            </div>
        </div>

        <!-- Decision: meta strip (category) and status banner -->
        <div v-if="isDecision" class="decision-block">
            <!-- Meta strip — currently only category. The source_type/source_ref
                 columns exist for future contexts (e.g. decisions created from
                 meeting notes or external systems); the in-stream compose
                 form does not surface them. -->
            <div v-if="decision.category" class="decision-meta">
                <span class="decision-meta__category" :title="t('teamhub', 'Category')">
                    {{ decision.category }}
                </span>
            </div>

            <!-- Open: action buttons (proposer or admin) -->
            <div
                v-if="(decisionStatus === 'open' || decisionStatus === 'proposed') && canManageDecision"
                class="decision-actions">
                <NcButton
                    variant="secondary"
                    :aria-label="t('teamhub', 'Withdraw this decision proposal')"
                    @click="openWithdrawDialog">
                    <template #icon><Close :size="16" /></template>
                    {{ t('teamhub', 'Withdraw proposal') }}
                </NcButton>
                <span class="decision-actions__hint">
                    {{ t('teamhub', 'Post your final wording as a comment below, then click the gavel on that comment to finalize.') }}
                </span>
            </div>

            <!-- Finalized banner (discussion closed, awaiting approval) -->
            <div v-if="decisionStatus === 'finalized'" class="decision-banner decision-banner--finalized" role="status">
                <GavelIcon :size="20" />
                <div class="decision-banner__body">
                    <strong>{{ t('teamhub', 'Finalized — awaiting approval') }}</strong>
                    <span v-if="decision.answeredBy" class="decision-banner__sub">
                        {{ t('teamhub', 'Final wording by {name}', { name: decision.answeredBy }) }}
                    </span>
                </div>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'View this decision in the Decisions tab')"
                    :aria-label="t('teamhub', 'View in Decisions tab')"
                    @click="viewInDecisionsTab">
                    {{ t('teamhub', 'View in Decisions tab') }}
                </NcButton>
            </div>

            <!-- Approved banner (legacy 'decided' rows fall in here) -->
            <div v-if="decisionStatus === 'approved' || decisionStatus === 'decided'" class="decision-banner decision-banner--approved" role="status">
                <CheckCircle :size="20" />
                <div class="decision-banner__body">
                    <strong>{{ t('teamhub', 'Approved') }}</strong>
                    <span v-if="decision.resolvedBy" class="decision-banner__sub">
                        {{ t('teamhub', 'Approved by {name}', { name: decision.resolvedBy }) }}
                    </span>
                </div>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'View this decision in the Decisions tab')"
                    :aria-label="t('teamhub', 'View in Decisions tab')"
                    @click="viewInDecisionsTab">
                    {{ t('teamhub', 'View in Decisions tab') }}
                </NcButton>
            </div>

            <!-- Denied banner -->
            <div v-if="decisionStatus === 'denied'" class="decision-banner decision-banner--denied" role="status">
                <Close :size="20" />
                <div class="decision-banner__body">
                    <strong>{{ t('teamhub', 'Denied') }}</strong>
                    <span v-if="decision.withdrawnReason" class="decision-banner__sub">
                        {{ t('teamhub', 'Reason: {reason}', { reason: decision.withdrawnReason }) }}
                    </span>
                </div>
            </div>

            <!-- Withdrawn banner -->
            <div v-if="decisionStatus === 'withdrawn'" class="decision-banner decision-banner--withdrawn" role="status">
                <Close :size="20" />
                <div class="decision-banner__body">
                    <strong>{{ t('teamhub', 'Withdrawn') }}</strong>
                    <span v-if="decision.withdrawnReason" class="decision-banner__sub">
                        {{ t('teamhub', 'Reason: {reason}', { reason: decision.withdrawnReason }) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Question solved banner -->
        <div v-if="message.messageType === 'question' && isQuestionSolved" class="question-solved-banner">
            <CheckCircle :size="20" />
            <span>{{ t('teamhub', 'Question solved') }}</span>
        </div>

        <!-- Footer: comment toggle -->
        <div class="message-card__footer">
            <NcButton variant="tertiary" @click="toggleComments">
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
                :decision="decision"
                :can-manage-decision="canManageDecision"
                :can-finalize-decision="canFinalizeDecision"
                @mark-solved="markSolved"
                @unmark-solved="unmarkSolved"
                @mark-decision-best="onMarkDecisionBest" />
        </Transition>

        <!-- Withdraw confirmation dialog -->
        <NcDialog
            v-if="showWithdrawDialog"
            :name="t('teamhub', 'Withdraw decision proposal')"
            :open="showWithdrawDialog"
            size="normal"
            @update:open="showWithdrawDialog = $event">
            <template #default>
                <p class="decision-dialog__intro">
                    {{ t('teamhub', 'Withdrawing closes this proposal. The thread will be locked from new comments. This action cannot be undone.') }}
                </p>
                <label for="withdraw-reason" class="post-form__label">
                    {{ t('teamhub', 'Reason') }}
                    <span class="decision-required" aria-hidden="true">*</span>
                </label>
                <textarea
                    id="withdraw-reason"
                    v-model="withdrawReason"
                    rows="3"
                    maxlength="1000"
                    class="decision-withdraw-textarea"
                    :placeholder="t('teamhub', 'Why is this proposal being withdrawn?')"></textarea>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="showWithdrawDialog = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="error"
                    :disabled="!withdrawReason.trim() || withdrawing"
                    @click="confirmWithdraw">
                    <template #icon>
                        <NcLoadingIcon v-if="withdrawing" :size="16" />
                    </template>
                    {{ t('teamhub', 'Withdraw') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Finalize confirmation dialog (Session H — was mark-as-best) -->
        <NcDialog
            v-if="showMarkDialog"
            :name="t('teamhub', 'Finalize decision')"
            :open="showMarkDialog"
            size="normal"
            @update:open="showMarkDialog = $event">
            <template #default>
                <p class="decision-dialog__intro">
                    {{ t('teamhub', 'This will record your comment as the final wording, close the discussion (no more comments), and send the decision to its approver(s) for sign-off. This action cannot be undone.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelMark">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="marking"
                    @click="confirmMark">
                    <template #icon>
                        <NcLoadingIcon v-if="marking" :size="16" />
                    </template>
                    <!-- TRANSLATORS: Confirm button in the Finalize dialog. Irreversible: locks discussion and triggers approval. -->
                    {{ t('teamhub', 'Finalize') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Insert image by URL dialog (edit toolbar) -->
        <NcDialog
            v-if="imageDialogOpen"
            :name="t('teamhub', 'Insert image')"
            :open="true"
            size="normal"
            @closing="imageDialogOpen = false">
            <div class="teamhub-image-dialog">
                <NcButton
                    variant="secondary"
                    :disabled="imageDialogBrowsing"
                    @click="browseImageFromFiles">
                    <template #icon>
                        <NcLoadingIcon v-if="imageDialogBrowsing" :size="16" />
                        <FolderIcon v-else :size="16" />
                    </template>
                    {{ t('teamhub', 'Browse Files…') }}
                </NcButton>
                <p class="teamhub-image-dialog__divider">
                    {{ t('teamhub', 'or paste a URL') }}
                </p>
                <NcTextField
                    ref="imageUrlField"
                    v-model="imageDialogUrl"
                    :label="t('teamhub', 'Image URL (https://…)')"
                    :placeholder="t('teamhub', 'https://example.com/photo.jpg')"
                    type="url"
                    @keydown.enter="confirmImageDialog" />
                <NcTextField
                    v-model="imageDialogAlt"
                    :label="t('teamhub', 'Alt text (describe the image)')"
                    :placeholder="t('teamhub', 'A short description for accessibility')"
                    @keydown.enter="confirmImageDialog" />
                <NcTextField
                    v-model="imageDialogWidth"
                    :label="t('teamhub', 'Width in pixels (optional)')"
                    type="number"
                    @keydown.enter="confirmImageDialog" />
                <p class="teamhub-image-dialog__hint">
                    {{ t('teamhub', 'Remote images are loaded through a privacy-preserving proxy and scaled to fit without cropping.') }}
                </p>
            </div>
            <template #actions>
                <NcButton variant="tertiary" @click="imageDialogOpen = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="!imageDialogUrl.trim()"
                    @click="confirmImageDialog">
                    {{ t('teamhub', 'Insert') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Full-size image lightbox.
             Teleported to <body> so it escapes any parent stacking context
             (NC's widget cards and the team hero/background both create their
             own, and an element only competes within its parent's context —
             a z-index alone is not enough). -->
        <Teleport to="body">
            <div
                v-if="lightboxSrc"
                class="teamhub-lightbox"
                role="dialog"
                aria-modal="true"
                :aria-label="t('teamhub', 'Image preview')"
                tabindex="-1"
                @click="closeLightbox"
                @keydown.esc="closeLightbox">
                <NcButton
                    class="teamhub-lightbox__close"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Close image preview')"
                    @click.stop="closeLightbox">
                    <template #icon><Close :size="24" /></template>
                </NcButton>
                <img
                    :src="lightboxSrc"
                    :alt="lightboxAlt"
                    class="teamhub-lightbox__img"
                    referrerpolicy="no-referrer"
                    @click.stop>
            </div>
        </Teleport>
    </div>
</template>

<script>
import { mapState, mapGetters, mapMutations } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import { NcAvatar, NcButton, NcLoadingIcon, NcRichContenteditable, NcDialog, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import Close from 'vue-material-design-icons/Close.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
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

    // Strip image-markdown spans first so their URLs don't get picked up as
    // preview targets. The image itself is the content — a preview card under
    // it is redundant and ugly. Order matters: do this BEFORE the [..](..)
    // link match below, otherwise the inner ](url) of ![..](..) leaks through.
    const stripped = text.replace(/!\[[^\]]*\]\([^)\s]+\)/g, '')

    // Markdown links (includes 📎 attachment links from PostMessageForm)
    const mdRe = /\[([^\]]+)\]\(([^)]+)\)/g
    let m
    while ((m = mdRe.exec(stripped)) !== null) {
        try {
            const href = new URL(m[2]).href
            if (seen.has(href)) continue
            seen.add(href)
            results.push({ url: href, label: m[1], isAttachment: m[1].startsWith('📎') })
        } catch (e) {}
    }

    // Bare URLs not inside markdown parentheses
    const bareRe = /(?<!\()https?:\/\/[^\s<>"'\)]+/g
    while ((m = bareRe.exec(stripped)) !== null) {
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
import DOMPurify from 'dompurify'

// Tags and attributes that our regex renderer intentionally produces.
// (img is allowed; its src is constrained by the hook registered below.)
// DOMPurify drops everything not on these lists — defence-in-depth even if
// a future regex change accidentally widens the output.
const ALLOWED_TAGS = ['strong', 'em', 'code', 'pre', 'a', 'br', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'span', 'img']
const ALLOWED_ATTR = ['href', 'target', 'rel', 'class', 'data-mention-user', 'src', 'alt', 'width', 'loading', 'decoding', 'referrerpolicy', 'tabindex', 'role']

// Path of the TeamHub backend image proxy. Every REMOTE image src is rewritten
// to point here so the viewer's browser never hits the third-party host directly
// (no IP leak / tracking-pixel surface; satisfies NC's img-src CSP).
const IMAGE_PROXY_PATH = generateUrl('/apps/teamhub/api/v1/preview/image')

/**
 * Decide what an <img> src is allowed to become, or null to drop the image.
 *
 * Three outcomes:
 *   - Remote https:// URL        → rewrite to the proxy path (?url=<encoded>)
 *   - Same-origin NC path/URL     → pass through unchanged (uploads, previews)
 *   - Anything else (data:, http:,
 *     javascript:, other origins) → null  (image is removed by the sanitizer)
 *
 * The sanitizer hook (below) is the single point that enforces this — the
 * markdown regex never emits a raw remote src, but we still re-derive the safe
 * src here so a future regex change can't widen what actually renders.
 */
/**
 * Escape a string for safe inclusion inside a double-quoted HTML attribute.
 * DOMPurify is still the authoritative sanitizer; this just prevents the
 * markdown layer from emitting attribute-breaking characters.
 */
function attrEscape(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
}

function safeImageSrc(rawSrc) {
    if (!rawSrc) return null

    // Already proxied by us — accept as-is (avoids double-encoding on re-render/edit).
    if (rawSrc.startsWith(IMAGE_PROXY_PATH)) {
        return rawSrc
    }

    // Same-origin: relative path, or an absolute URL whose origin matches the page.
    // These are NC-served (uploaded images, share previews) and need no proxy.
    if (rawSrc.startsWith('/')) {
        // Reject protocol-relative '//evil.com/x' (starts with '/' but is cross-origin).
        if (rawSrc.startsWith('//')) return null
        return rawSrc
    }
    try {
        const u = new URL(rawSrc, window.location.origin)
        if (u.origin === window.location.origin) {
            return u.pathname + u.search
        }
        // Remote: only https is proxied. http/ftp/data/javascript are dropped.
        if (u.protocol === 'https:') {
            return IMAGE_PROXY_PATH + '?url=' + encodeURIComponent(u.href)
        }
    } catch (e) {
        return null
    }
    return null
}

// One-time DOMPurify hook: enforce the image src policy and harden every <img>.
// Runs after DOMPurify has parsed attributes, on every sanitize() call. Because
// it is the only place that sets the final src, no raw remote/data/http URL can
// reach the DOM even if the markdown layer is later changed.
let imageHookRegistered = false
function ensureImageHook() {
    if (imageHookRegistered) return
    imageHookRegistered = true
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.nodeName !== 'IMG') return

        const safe = safeImageSrc(node.getAttribute('src'))
        if (safe === null) {
            // Disallowed source — remove the element entirely.
            node.remove()
            return
        }
        node.setAttribute('src', safe)

        // Clamp width to a sane integer (1–2000). Drop anything non-numeric.
        const w = parseInt(node.getAttribute('width'), 10)
        if (Number.isFinite(w) && w >= 1 && w <= 2000) {
            node.setAttribute('width', String(w))
        } else {
            node.removeAttribute('width')
        }

        // Guarantee a frame class, lazy loading, and async decode.
        node.setAttribute('class', 'teamhub-inline-image')
        node.setAttribute('loading', 'lazy')
        node.setAttribute('decoding', 'async')
        // Never let an inline image carry a referrer to the (proxied) origin.
        node.setAttribute('referrerpolicy', 'no-referrer')
        // Keyboard-accessible: focusable and announced as an activatable control
        // (Enter opens the lightbox, handled by the delegated body listener).
        node.setAttribute('tabindex', '0')
        node.setAttribute('role', 'button')
    })
}

/**
 * Convert a subset of Markdown to sanitised HTML.
 * @param {string} text - raw message body
 * @param {Object} membersMap - optional { [userId]: displayName } for @mention rendering
 */
function renderMarkdown(text, membersMap = {}) {
    if (!text) return ''

    // 1. Fenced code blocks
    const codeBlocks = []
    let html = text.replace(/```([\s\S]+?)```/g, (_, code) => {
        codeBlocks.push(`<pre><code>${code}</code></pre>`)
        return `\u0000${codeBlocks.length - 1}\u0000`
    })

    // 2. Inline code
    const inlineCodes = []
    html = html.replace(/`([^`]+)`/g, (_, code) => {
        inlineCodes.push(`<code>${code}</code>`)
        return `\u0001${inlineCodes.length - 1}\u0001`
    })

    // 3. @mentions — convert @userId to a styled mention span with display name
    html = html.replace(/@([a-zA-Z0-9._-]+)/g, (match, userId) => {
        const displayName = membersMap[userId] || userId
        return `<span class="teamhub-mention" data-mention-user="${userId}">@${displayName}</span>`
    })

    // 4. Bold and italic
    html = html
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/_([^_]+)_/g, '<em>$1</em>')

    // 4b. Inline images — ![alt](url) and ![alt|320](url) (optional width).
    //     Emitted BEFORE the link rule so ![..](..) is not eaten by [..](..),
    //     AND stashed behind a placeholder so the bare-URL autolinker in step 5
    //     can't match the https:// inside the emitted src="..." attribute.
    //     (That corruption is exactly what broke v3.58.0.) src/alt are
    //     attribute-escaped; the final src policy + width clamp are enforced in
    //     the DOMPurify hook (safeImageSrc) — a src the hook rejects (data:,
    //     http:, cross-origin) is dropped entirely at sanitize time.
    const imageTags = []
    html = html.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, altRaw, urlRaw) => {
        let alt = altRaw
        let widthAttr = ''
        // Optional "|<width>" suffix inside the alt segment: ![alt|320](url)
        const pipe = altRaw.lastIndexOf('|')
        if (pipe !== -1) {
            const maybeWidth = altRaw.slice(pipe + 1).trim()
            if (/^\d{1,4}$/.test(maybeWidth)) {
                alt = altRaw.slice(0, pipe)
                widthAttr = ` width="${maybeWidth}"`
            }
        }
        const safeAlt = attrEscape(alt.trim())
        const safeUrl = attrEscape(urlRaw)
        imageTags.push(`<img src="${safeUrl}" alt="${safeAlt}"${widthAttr} />`)
        return `\u0002${imageTags.length - 1}\u0002`
    })

    // 5. Links — explicit [text](url) then bare https?:// URLs
    html = html
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/(?<!href=")(?<!\()https?:\/\/[^\s<>"'\)]+/g, '<a href="$&" target="_blank" rel="noopener noreferrer">$&</a>')

    // 6a. Headings
    html = html
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')

    // 6b. Bullet lists
    html = html.replace(/((?:^- .+(?:\n|$))+)/gm, (block) => {
        const items = block.trimEnd().split('\n')
            .map(line => `<li>${line.replace(/^- /, '')}</li>`)
            .join('')
        return `<ul>${items}</ul>\n`
    })

    // 7. Remaining newlines → <br>
    html = html.replace(/\n/g, '<br>')

    // 8. Restore code + image placeholders
    html = html
        .replace(/\u0000(\d+)\u0000/g, (_, i) => codeBlocks[+i])
        .replace(/\u0001(\d+)\u0001/g, (_, i) => inlineCodes[+i])
        .replace(/\u0002(\d+)\u0002/g, (_, i) => imageTags[+i])

    // 9. Sanitize
    ensureImageHook()
    return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR })
}

export default {
    name: 'MessageCard',
    components: {
        NcAvatar,
        NcButton,
        NcLoadingIcon,
        NcRichContenteditable,
        NcDialog,
        NcTextField,
        CommentOutline,
        ClipboardCheckOutline,
        HelpCircleOutline,
        CheckCircleOutline,
        CheckCircle,
        GavelIcon,
        Lock,
        Delete,
        Pencil,
        Pin,
        PinOff,
        CommentsSection,
        PaperclipIcon,
        FormatBold,
        FormatItalic,
        CodeTags,
        CodeBraces,
        FormatHeader2,
        FormatListBulleted,
        LinkVariant,
        ImageIcon,
        FolderIcon,
        Close,
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
            // Insert-image-by-URL dialog (edit toolbar)
            imageDialogOpen: false,
            imageDialogUrl: '',
            imageDialogAlt: '',
            imageDialogWidth: '',
            imageDialogBrowsing: false,
            // Full-size image lightbox
            lightboxSrc: '',
            lightboxAlt: '',
            // Decision-flow dialog state
            showWithdrawDialog: false,
            withdrawReason: '',
            withdrawing: false,
            showMarkDialog: false,
            markingCommentId: null,
            marking: false,
        }
    },
    computed: {
        ...mapState(['members', 'allEffectiveMembers']),
        ...mapGetters(['commentsForMessage', 'currentUserIsTeamAdmin']),
        isPriority() { return this.message.priority === 'priority' },
        isPollClosed() { return this.message.pollClosed === true },
        isQuestionSolved() { return this.message.questionSolved === true },

        // ── Decision computed properties ────────────────────────────────────

        /** True when this message carries a decision payload. */
        isDecision() {
            return this.message.messageType === 'decision' && !!this.message.decision
        },

        /** The decision payload (or a safe empty shell) — never null in template. */
        decision() {
            return this.message.decision || {}
        },

        decisionStatus() {
            return (this.message.decision && this.message.decision.status) || 'open'
        },

        /**
         * True iff the current user may withdraw OR finalize this open decision.
         * Finalize specifically is proposer-only — we tighten that gate at the
         * gavel button itself; canManageDecision is broader (includes admin
         * for withdraw).
         */
        canManageDecision() {
            if (!this.isDecision) return false
            // Legacy 'proposed' rows behave like 'open'.
            const s = this.decisionStatus
            if (s !== 'open' && s !== 'proposed') return false
            const uid = this.$store.state.currentUser?.uid
            if (uid && this.decision.proposedBy === uid) return true
            return this.currentUserIsTeamAdmin
        },

        /**
         * True iff the current user is the proposer of an open decision — the
         * only case where the gavel-finalize button appears. Admin override
         * does NOT apply here (only the proposer can finalize per spec).
         */
        canFinalizeDecision() {
            if (!this.isDecision) return false
            const s = this.decisionStatus
            if (s !== 'open' && s !== 'proposed') return false
            const uid = this.$store.state.currentUser?.uid
            return !!(uid && this.decision.proposedBy === uid)
        },

        decisionBadgeTitle() {
            if (!this.isDecision) return ''
            return t('teamhub', 'Decision · {impact} impact · {status}', {
                impact: this.impactLabel(this.decision.impact),
                status: this.statusLabel(this.decisionStatus),
            })
        },

        /** { [userId]: displayName } for @mention rendering */
        membersMap() {
            const map = {}
            for (const m of (this.members || [])) {
                map[m.userId] = m.displayName || m.userId
            }
            return map
        },

        renderedMessage() { return renderMarkdown(this.message.message, this.membersMap) },
        formattedDate() {
            return new Date(this.message.created_at * 1000).toLocaleString()
        },
        commentCount() { return this.message.comment_count || 0 },
        commentLabel() {
            // TRANSLATORS: button label to open/add a comment (verb), shown when there are no comments yet
            if (this.commentCount === 0) return t('teamhub', 'Comment')
            // TRANSLATORS: count of comments on a message, e.g. "1 comment" or "5 comments"
            return n('teamhub', '{n} comment', '{n} comments', this.commentCount, { n: this.commentCount })
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

        /** user-data object for NcRichContenteditable — keyed by userId */
        mentionsObj() {
            const result = {}
            for (const m of (this.members || [])) {
                result[m.userId] = {
                    id:     m.userId,
                    label:  m.displayName || m.userId,
                    source: 'users',
                    icon:   'icon-user',
                    status: null,
                }
            }
            return result
        },
    },
    mounted() {
        if (this.message.messageType === 'poll') {
            this.loadPollResults()
        }
        this.loadPreviews()
    },
    methods: {
        t, n,
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET']),

        /**
         * Navigate to the Decisions tab and highlight the row for this message.
         * The SET_DECISIONS_TARGET mutation stores the messageId; TeamDecisionsView
         * watches it and scrolls/expands the matching row on arrival.
         */
        viewInDecisionsTab() {
            const messageId = this.message?.id
            this.SET_DECISIONS_TARGET(messageId)
            this.SET_VIEW('decisions')
        },

        /** Same NC OCS autocomplete approach as PostMessageForm */
        async editMentionAutoComplete(search, callback) {
            try {
                // Ensure we have the full effective member list
                let mentionList = this.allEffectiveMembers.length > 0
                    ? this.allEffectiveMembers
                    : (this.members || [])

                if (mentionList.length === 0) {
                    const teamId = this.$store.state.currentTeamId
                    if (teamId) {
                        try {
                            const { data: allData } = await axios.get(
                                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members/all`)
                            )
                            const allList = Array.isArray(allData) ? allData : (Array.isArray(allData?.members) ? allData.members : [])
                            if (allList.length > 0) {
                                this.$store.commit('SET_ALL_EFFECTIVE_MEMBERS', allList)
                                mentionList = allList
                            }
                        } catch (_) { /* non-fatal */ }
                    }
                }

                const ocsUsers = await axios.get(
                    generateUrl('/ocs/v2.php/core/autocomplete/get'),
                    {
                        params: { search: search || '', itemType: 'call', itemId: 'new', limit: 20, format: 'json' },
                        headers: { 'OCS-APIREQUEST': 'true' },
                    }
                ).then(r => r.data?.ocs?.data || []).catch(() => [])

                const memberIds = new Set(mentionList.map(m => m.userId))
                const lower = (search || '').toLowerCase()

                const filtered = ocsUsers.filter(u =>
                    memberIds.has(u.id) || memberIds.has(u.value?.shareWith)
                )

                const foundIds = new Set(filtered.map(u => u.id))
                const supplemental = mentionList
                    .filter(m =>
                        !foundIds.has(m.userId) && (
                            (m.displayName || '').toLowerCase().includes(lower) ||
                            (m.userId     || '').toLowerCase().includes(lower)
                        )
                    )
                    .map(m => ({
                        id:     m.userId,
                        label:  m.displayName || m.userId,
                        source: 'users',
                        icon:   'icon-user',
                        value:  { shareWith: m.userId, shareWithDisplayNameUnique: m.displayName || m.userId },
                    }))

                callback([...filtered, ...supplemental])
            } catch (e) {
                const lower = (search || '').toLowerCase()
                let fallbackList = this.allEffectiveMembers.length > 0
                    ? this.allEffectiveMembers
                    : (this.members || [])

                if (fallbackList.length === 0) {
                    const teamId = this.$store.state.currentTeamId
                    if (teamId) {
                        try {
                            const { data: allData } = await axios.get(
                                generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/members/all`)
                            )
                            const allList = Array.isArray(allData) ? allData : (Array.isArray(allData?.members) ? allData.members : [])
                            if (allList.length > 0) fallbackList = allList
                        } catch (_) { /* give up */ }
                    }
                }

                callback(
                    fallbackList
                        .filter(m =>
                            (m.displayName || '').toLowerCase().includes(lower) ||
                            (m.userId     || '').toLowerCase().includes(lower)
                        )
                        .slice(0, 10)
                        .map(m => ({
                            id:     m.userId,
                            label:  m.displayName || m.userId,
                            source: 'users',
                            icon:   'icon-user',
                            value:  { shareWith: m.userId, shareWithDisplayNameUnique: m.displayName || m.userId },
                        }))
                )
            }
        },
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

        // ── Markdown toolbar for edit mode ───────────────────────────────
        // The edit body is a plain <textarea>, so we use selectionStart /
        // selectionEnd to locate the cursor and setSelectionRange to restore
        // it after Vue re-renders. No execCommand needed.
        //
        // @mousedown.prevent on the toolbar buttons keeps the textarea's
        // selection alive while the click fires.

        /**
         * Wrap the selected text in the textarea with markdown syntax,
         * or insert `before + placeholder + after` at the cursor when nothing
         * is selected.
         *
         * @param {string} before       Prefix (e.g. '**')
         * @param {string} after        Suffix (e.g. '**'), empty for line-prefix syntax
         * @param {string} placeholder  Fallback label when there is no selection
         */
        applyEditMarkdown(before, after, placeholder = '') {
            const el = this.$refs.editBodyRef
            if (!el) {
                this.editBody += before + (placeholder || '') + after
                return
            }

            const start = el.selectionStart ?? this.editBody.length
            const end   = el.selectionEnd   ?? this.editBody.length
            const selected = this.editBody.slice(start, end) || placeholder || ''
            const replacement = before + selected + after

            this.editBody = this.editBody.slice(0, start) + replacement + this.editBody.slice(end)

            // Restore cursor to end of inserted text after Vue re-renders
            this.$nextTick(() => {
                const cursor = start + replacement.length
                el.focus()
                el.setSelectionRange(cursor, cursor)
            })
        },

        /**
         * Insert a Markdown link at the cursor, using the current selection
         * (if any) as the link label.
         */
        applyEditLink() {
            const el = this.$refs.editBodyRef
            const start = el?.selectionStart ?? this.editBody.length
            const end   = el?.selectionEnd   ?? this.editBody.length
            const selected = this.editBody.slice(start, end)
            const label = selected || t('teamhub', 'link text')
            const replacement = `[${label}](url)`

            this.editBody = this.editBody.slice(0, start) + replacement + this.editBody.slice(end)

            this.$nextTick(() => {
                if (el) {
                    el.focus()
                    el.setSelectionRange(start + replacement.length, start + replacement.length)
                }
            })
        },

        // ── Insert image by URL (edit toolbar) ───────────────────────────────
        /**
         * Open the insert-image dialog. The actual src safety (https→proxy,
         * same-origin passthrough, everything else dropped) is enforced at
         * render time by the DOMPurify hook — this dialog only composes the
         * `![alt|width](url)` markdown.
         */
        openImageDialog() {
            this.imageDialogUrl = ''
            this.imageDialogAlt = ''
            this.imageDialogWidth = ''
            this.imageDialogOpen = true
            this.$nextTick(() => this.$refs.imageUrlField?.$el?.querySelector('input')?.focus())
        },

        confirmImageDialog() {
            const url = this.imageDialogUrl.trim()
            if (!url) return
            const alt = this.imageDialogAlt.trim()
            const w = parseInt(this.imageDialogWidth, 10)
            const widthSeg = (Number.isFinite(w) && w >= 1 && w <= 2000) ? `|${w}` : ''
            const snippet = `![${alt}${widthSeg}](${url})`

            const el = this.$refs.editBodyRef
            if (el) {
                const start = el.selectionStart ?? this.editBody.length
                const end   = el.selectionEnd   ?? this.editBody.length
                this.editBody = this.editBody.slice(0, start) + snippet + this.editBody.slice(end)
                this.$nextTick(() => {
                    el.focus()
                    el.setSelectionRange(start + snippet.length, start + snippet.length)
                })
            } else {
                this.editBody += (this.editBody && !this.editBody.endsWith('\n') ? '\n' : '') + snippet
            }
            this.imageDialogOpen = false
        },

        /**
         * Open the NC FilePicker filtered to images, then resolve the chosen
         * file's numeric id and pre-fill the URL field with the core preview URL.
         * Identical to the PostMessageForm path — kept in lockstep so the two
         * insertion points behave the same.
         */
        async browseImageFromFiles() {
            this.imageDialogBrowsing = true
            try {
                const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
                const picker = getFilePickerBuilder(t('teamhub', 'Choose an image'))
                    .setMimeTypeFilter(['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif'])
                    .setMultiSelect(false)
                    .allowDirectories(false)
                    .addButton({
                        // @nextcloud/dialogs v7 requires at least one button — otherwise
                        // the picker renders with no confirm action and selections cannot
                        // be applied. pick() resolves with the path on click; callback no-op.
                        label: t('teamhub', 'Choose'),
                        variant: 'primary',
                        callback: () => {},
                    })
                    .build()
                const result = await picker.pick()

                let path = null
                if (Array.isArray(result)) {
                    const first = result[0]
                    path = typeof first === 'string' ? first : (first?.path || null)
                } else if (typeof result === 'string') {
                    path = result
                } else if (result && typeof result === 'object') {
                    path = result.path || null
                }
                if (!path) return

                const uid = getCurrentUser()?.uid
                if (!uid) return
                const propUrl = generateRemoteUrl(`dav/files/${uid}${path}`)
                const propResp = await axios({
                    method: 'PROPFIND',
                    url: propUrl,
                    headers: { Depth: '0', 'Content-Type': 'application/xml' },
                    data:
                        '<?xml version="1.0"?>'
                        + '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
                        + '<d:prop><oc:fileid/></d:prop></d:propfind>',
                })
                const idMatch = String(propResp.data).match(/<oc:fileid>(\d+)<\/oc:fileid>/)
                const fileId = idMatch ? idMatch[1] : null
                if (!fileId) {
                    showError(t('teamhub', 'Could not resolve the file'))
                    return
                }

                const basename = path.split('/').filter(Boolean).pop() || ''
                this.imageDialogUrl = generateUrl('/core/preview') + `?fileId=${fileId}&x=1024&y=1024&a=true`
                if (!this.imageDialogAlt) {
                    this.imageDialogAlt = basename
                }
            } catch (e) {
                // User cancelled or picker failed silently
            } finally {
                this.imageDialogBrowsing = false
            }
        },
        /**
         * Open the lightbox when an inline image in the rendered body is clicked.
         * Uses event delegation on the body container so dynamically rendered
         * images don't each need a listener. The src reused here is the already
         * sanitised (proxied / same-origin) value — no second trust path.
         */
        onBodyClick(event) {
            const img = event.target.closest && event.target.closest('img.teamhub-inline-image')
            if (!img) return
            event.preventDefault()
            this.lightboxSrc = img.getAttribute('src') || ''
            this.lightboxAlt = img.getAttribute('alt') || ''
            this.$nextTick(() => {
                const box = document.querySelector('.teamhub-lightbox')
                box?.focus()
            })
        },

        closeLightbox() {
            this.lightboxSrc = ''
            this.lightboxAlt = ''
        },

        /** Keyboard activation (Enter / Space) for a focused inline image. */
        onBodyKeydown(event) {
            if (event.key !== 'Enter' && event.key !== ' ') return
            const img = event.target.closest && event.target.closest('img.teamhub-inline-image')
            if (!img) return
            event.preventDefault()
            this.lightboxSrc = img.getAttribute('src') || ''
            this.lightboxAlt = img.getAttribute('alt') || ''
            this.$nextTick(() => {
                const box = document.querySelector('.teamhub-lightbox')
                box?.focus()
            })
        },        async saveEdit() {
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

        // ── Decision flow ───────────────────────────────────────────────────

        impactLabel(impact) {
            if (impact === 'high')   return t('teamhub', 'High')
            if (impact === 'medium') return t('teamhub', 'Medium')
            if (impact === 'low')    return t('teamhub', 'Low')
            return t('teamhub', 'Unknown')
        },

        statusLabel(status) {
            // TRANSLATORS: status pill — proposer finalized; awaiting approver decision
            if (status === 'finalized')  return t('teamhub', 'Awaits approval')
            if (status === 'approved')   return t('teamhub', 'Approved')
            if (status === 'denied')     return t('teamhub', 'Denied')
            if (status === 'withdrawn')  return t('teamhub', 'Withdrawn')
            // Legacy fallback for stale rows
            if (status === 'decided')    return t('teamhub', 'Approved')
            // 'open' / 'proposed' / unknown
            return t('teamhub', 'Open')
        },

        openWithdrawDialog() {
            this.withdrawReason = ''
            this.showWithdrawDialog = true
        },

        async confirmWithdraw() {
            const reason = this.withdrawReason.trim()
            if (!reason) return
            this.withdrawing = true
            try {
                await this.$store.dispatch('withdrawDecision', {
                    decisionId: this.decision.id,
                    reason,
                    messageId: this.message.id,
                })
                showSuccess(t('teamhub', 'Decision withdrawn'))
                this.showWithdrawDialog = false
                this.withdrawReason = ''
                // Refresh comments view to update lock state.
                this.$store.dispatch('fetchComments', this.message.id)
            } catch (error) {
                const errorMsg = error?.response?.data?.error || error?.message || 'Unknown error'
                showError(t('teamhub', 'Failed to withdraw: {error}', { error: errorMsg }))
            } finally {
                this.withdrawing = false
            }
        },

        /**
         * Called by CommentsSection when the user clicks "Mark as best answer"
         * on a specific comment. We open a confirmation dialog before actually
         * calling the API — the action is irreversible.
         */
        onMarkDecisionBest(commentId) {
            this.markingCommentId = commentId
            this.showMarkDialog = true
        },

        cancelMark() {
            this.showMarkDialog = false
            this.markingCommentId = null
        },

        async confirmMark() {
            if (!this.markingCommentId) return
            this.marking = true
            try {
                // Session H: dispatch the new finalizeDecision action.
                // (markDecisionBest is still available as a backward-compat alias.)
                await this.$store.dispatch('finalizeDecision', {
                    decisionId: this.decision.id,
                    commentId: this.markingCommentId,
                    messageId: this.message.id,
                })
                showSuccess(t('teamhub', 'Decision finalized — awaiting approval'))
                this.showMarkDialog = false
                this.markingCommentId = null
                this.$store.dispatch('fetchComments', this.message.id)
            } catch (error) {
                const errorMsg = error?.response?.data?.error || error?.message || 'Unknown error'
                showError(t('teamhub', 'Failed to finalize: {error}', { error: errorMsg }))
            } finally {
                this.marking = false
            }
        },
        getPercentage(index) {
            if (this.pollResults.totalVotes === 0) return 0
            const votes = this.pollResults.votes[index] || 0
            return Math.round((votes / this.pollResults.totalVotes) * 100)
        },
        getPollVotes(index) {
            const votes = this.pollResults.votes[index] || 0
            // TRANSLATORS: vote count for a single poll option, e.g. "1 vote" / "5 votes"
            return n('teamhub', '{n} vote', '{n} votes', votes, { n: votes })
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

/* v3.100.14: message-body background is a neutral separator, not a
   state background — the 4px coloured border-left is the state signal.
   Full-saturation on the whole message body would swamp the feed;
   --color-background-dark is the SKILLS.md-permitted neutral surface
   for visual separation on non-state rows. */
.message-card--priority {
    border-left: 4px solid var(--color-error);
    background: var(--color-background-dark);
}

.message-card--question-solved {
    border-left: 4px solid var(--color-success);
    background: var(--color-background-dark);
}

.message-card--pinned {
    border-left: 4px solid var(--color-primary-element);
    background: var(--color-background-dark);
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
    font-size: var(--th-font-micro);
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
    font-size: var(--th-font-body);
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

/* NC's global styles reset font-style/list-style on these tags — restore them
   so markdown italics and bullet lists render as users expect. */
.message-card__body :deep(em) {
    font-style: italic;
}

.message-card__body :deep(strong) {
    font-weight: 700;
}

.message-card__body :deep(ul),
.message-card__body :deep(ol) {
    margin: 8px 0;
    padding-left: 28px;
}

.message-card__body :deep(ul) {
    list-style: disc outside;
}

.message-card__body :deep(ol) {
    list-style: decimal outside;
}

.message-card__body :deep(li) {
    margin: 2px 0;
}

/* @mention rendered pill in message body */
.message-card__body :deep(.teamhub-mention) {
    display: inline-block;
    padding: 0 4px;
    border-radius: 4px;
    background: var(--color-primary-light);
    color: var(--color-primary-element);
    font-weight: 500;
    cursor: default;
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
    font-size: var(--th-font-meta);
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
    font-size: var(--th-font-micro);
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
    font-size: var(--th-font-meta);
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

/* v3.100.14: full-saturation voted state per SKILLS.md
   (was --color-primary-element-light). */
.poll-option--voted {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
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
    font-size: var(--th-font-body);
}

/* Right-side cluster: checkmark + vote count */
.poll-option__right {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Checkmark shown on voted option — non-color indicator (WCAG 1.4.1) */
.poll-option__voted-icon {
    color: var(--color-primary-element);
    flex-shrink: 0;
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
    font-size: var(--th-font-body);
    font-weight: 600;
}

/* ── Decision card ──────────────────────────────────────────────────────── */
.message-card--decision {
    border-left: 4px solid var(--color-primary-element);
    padding-left: calc(var(--default-clickable-area, 14px) - 4px);
}

.message-card--decision-open {
    border-left-color: var(--color-primary-element);
}

.message-card--decision-finalized {
    border-left-color: var(--color-warning);
}

.message-card--decision-approved {
    border-left-color: var(--color-success);
}

.message-card--decision-denied {
    border-left-color: var(--color-error-text);
    opacity: 0.95;
}

.message-card--decision-withdrawn {
    border-left-color: var(--color-text-maxcontrast);
    opacity: 0.92;
}

/* Decision badge in header */
/* v3.100.14: full-saturation decision badge per SKILLS.md
   (was --color-primary-element-light). */
.message-card__decision-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: var(--border-radius-pill);
    font-size: var(--th-font-meta);
    font-weight: 600;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* v3.100.14: impact badges — full-saturation state per SKILLS.md
   (were 16% color-mix soft tints). */
.message-card__decision-badge--high {
    background: var(--color-error);
    color: var(--color-error-text);
}

.message-card__decision-badge--medium {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.message-card__decision-badge--low {
    background: var(--color-success);
    color: var(--color-success-text);
}

.message-card__decision-badge-dot {
    opacity: 0.5;
    padding: 0 2px;
}

.message-card__decision-badge-impact,
.message-card__decision-badge-status {
    white-space: nowrap;
}

/* Decision body block */
.decision-block {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
}

.decision-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.decision-meta__category {
    padding: 2px 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-pill);
    color: var(--color-main-text);
}

.decision-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 8px 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}

.decision-actions__hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    flex: 1;
    min-width: 200px;
}

.decision-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--border-radius-large);
    font-size: var(--th-font-body);
}

.decision-banner__body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}

.decision-banner__sub {
    font-size: 13px;
    font-weight: normal;
    opacity: 0.85;
}

/* v3.100.14: decision banners — full-saturation state per SKILLS.md
   § "State-coloured backgrounds" (were 10-14% color-mix soft tints
   with fallback #hex values that also violated the theme-token rule).
   The comment on --approved referenced an older interpretation of the
   rule; SKILLS.md's canonical pattern is fill + matching -text token. */
.decision-banner--finalized {
    background: var(--color-warning);
    border: 1px solid var(--color-warning);
    color: var(--color-warning-text);
}

.decision-banner--approved {
    background: var(--color-success);
    border: 1px solid var(--color-success);
    color: var(--color-success-text);
}

.decision-banner--approved :deep(button:disabled) {
    color: var(--color-success-text);
    opacity: 0.7;
}

.decision-banner--denied {
    background: var(--color-error);
    border: 1px solid var(--color-error);
    color: var(--color-error-text);
}

.decision-banner--withdrawn {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    border: 1px solid var(--color-border);
}

/* Withdraw dialog */
.decision-dialog__intro {
    margin: 0 0 12px 0;
    color: var(--color-text-maxcontrast);
}

.decision-withdraw-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-family: inherit;
    font-size: 0.95em;
    resize: vertical;
}

.decision-withdraw-textarea:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.decision-required {
    color: var(--color-error-text);
    margin-left: 4px;
}

.message-card__edit {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 8px 0;
}

.message-card__edit-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: -4px; /* tighten gap between label and its input */
}

.message-card__edit-subject,
.message-card__edit-body {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    font-family: inherit;
    box-sizing: border-box;
    resize: vertical;
}

.message-card__edit-subject:focus,
.message-card__edit-body:focus {
    border-color: var(--color-primary-element);
}

/* Explicit keyboard focus ring — suppressed for pointer/touch (2.4.7) */
.message-card__edit-subject:focus-visible,
.message-card__edit-body:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Voted poll option focus ring */
.poll-option:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--border-radius);
}

.message-card__edit-actions {
    display: flex;
    gap: 8px;
}

/* Markdown toolbar beneath the edit textarea — same border treatment as
   PostMessageForm's toolbar so the chrome reads consistently. */
.message-card__edit-md-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 2px 4px;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    background: var(--color-background-hover);
    margin-top: -8px; /* close the gap from the textarea above */
}

/* ── Inline images in the message body (the "smart frame") ──────────────────
   Scale to fit the column without cropping. max-width keeps wide images inside
   the card even if an explicit width="" was larger; object-fit: contain means
   a width/height combination never distorts or crops. */
.message-card__body :deep(img.teamhub-inline-image) {
    display: block;
    max-width: 100%;
    height: auto;
    max-height: 400px;
    object-fit: contain;
    border-radius: var(--border-radius);
    margin: 8px 0;
    cursor: zoom-in;
    background: var(--color-background-dark);
}

.message-card__body :deep(img.teamhub-inline-image):focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* ── Full-size image lightbox ─────────────────────────────────────────────
   z-index above NcModal (~10000) and NC toast/notification layers. The
   element is teleported to <body> so it escapes any parent stacking
   context — z-index alone wouldn't have been enough. */
.teamhub-lightbox {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.85);
    cursor: zoom-out;
}

.teamhub-lightbox__img {
    max-width: 92vw;
    max-height: 92vh;
    object-fit: contain;
    border-radius: var(--border-radius);
    cursor: default;
}

.teamhub-lightbox__close {
    position: absolute;
    top: 16px;
    right: 16px;
    color: white;
}

/* ── Insert-image dialog ────────────────────────────────────────────────── */
.teamhub-image-dialog {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 4px 0;
    min-width: 320px;
}

.teamhub-image-dialog__hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

.teamhub-image-dialog__divider {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    text-align: center;
    margin: 0;
    position: relative;
}
.teamhub-image-dialog__divider::before,
.teamhub-image-dialog__divider::after {
    content: "";
    position: absolute;
    top: 50%;
    width: calc(50% - 60px);
    height: 1px;
    background: var(--color-border);
}
.teamhub-image-dialog__divider::before { left: 0; }
.teamhub-image-dialog__divider::after  { right: 0; }
</style>
