<template>
    <div class="post-form">
        <!-- Message type selector — hidden when the form is embedded in
             ComposeDecisionModal (forceDecision prop locks it to 'decision'). -->
        <div v-if="!forceDecision" class="post-form__type">
            <label class="post-form__type-option" :class="{ active: messageType === 'normal' }">
                <input v-model="messageType" type="radio" value="normal">
                <MessageOutline :size="16" />
                {{ t('teamhub', 'Message') }}
            </label>
            <label class="post-form__type-option" :class="{ active: messageType === 'poll' }">
                <input v-model="messageType" type="radio" value="poll">
                <PollIcon :size="16" />
                {{ t('teamhub', 'Poll') }}
            </label>
            <label class="post-form__type-option" :class="{ active: messageType === 'question' }">
                <input v-model="messageType" type="radio" value="question">
                <HelpCircleOutline :size="16" />
                {{ t('teamhub', 'Question') }}
            </label>
            <label
                v-if="decisionsAvailable"
                class="post-form__type-option"
                :class="{ active: messageType === 'decision' }">
                <input v-model="messageType" type="radio" value="decision">
                <GavelIcon :size="16" />
                {{ t('teamhub', 'Decision') }}
            </label>
        </div>

        <NcTextField
            v-model="subject"
            :label="subjectLabel"
            :placeholder="subjectPlaceholder" />

        <!-- Body editor -->
        <div class="post-form__body">
            <label class="post-form__label">{{ bodyLabel }}</label>
            <NcRichContenteditable
                ref="editor"
                v-model="body"
                :placeholder="bodyPlaceholder"
                :multiline="true"
                :link-autocomplete="true"
                :auto-complete="mentionAutoComplete"
                :user-data="mentions" />

            <!-- Markdown formatting toolbar.
                 @mousedown.prevent keeps focus+cursor in the contenteditable
                 so execCommand fires into the correct element (mouse path).
                 For keyboard activation, applyMarkdown focuses the editor
                 manually before inserting. -->
            <div class="post-form__md-toolbar" role="toolbar" :aria-label="t('teamhub', 'Formatting')">
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Bold (Ctrl+B)')"
                    :aria-label="t('teamhub', 'Bold')"
                    @mousedown.prevent
                    @click="applyMarkdown('**', '**', t('teamhub', 'bold text'))">
                    <template #icon><FormatBold :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Italic (Ctrl+I)')"
                    :aria-label="t('teamhub', 'Italic')"
                    @mousedown.prevent
                    @click="applyMarkdown('*', '*', t('teamhub', 'italic text'))">
                    <template #icon><FormatItalic :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Inline code')"
                    :aria-label="t('teamhub', 'Inline code')"
                    @mousedown.prevent
                    @click="applyMarkdown('`', '`', t('teamhub', 'code'))">
                    <template #icon><CodeTags :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Code block')"
                    :aria-label="t('teamhub', 'Code block')"
                    @mousedown.prevent
                    @click="applyMarkdown('```\n', '\n```', t('teamhub', 'code block'))">
                    <template #icon><CodeBraces :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Heading')"
                    :aria-label="t('teamhub', 'Heading')"
                    @mousedown.prevent
                    @click="applyMarkdown('## ', '', t('teamhub', 'Heading'))">
                    <template #icon><FormatHeader2 :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Bullet list')"
                    :aria-label="t('teamhub', 'Bullet list')"
                    @mousedown.prevent
                    @click="applyMarkdown('- ', '', t('teamhub', 'list item'))">
                    <template #icon><FormatListBulleted :size="16" /></template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Link')"
                    :aria-label="t('teamhub', 'Insert link')"
                    @mousedown.prevent
                    @click="applyLink">
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

            <!-- Toolbar: Smart Picker + Attach file -->
            <div class="post-form__toolbar">
                <!-- Smart Picker button -->
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Insert link from Smart Picker')"
                    :title="t('teamhub', 'Smart Picker — type / in the editor, or click here')"
                    @click="openSmartPicker">
                    <template #icon><LinkVariant :size="18" /></template>
                </NcButton>

                <!-- Attach file -->
                <NcButton
                    variant="tertiary"
                    :disabled="uploading"
                    :aria-label="t('teamhub', 'Attach a file')"
                    :title="t('teamhub', 'Attach file — uploads to your Files and inserts a link')"
                    @click="triggerFilePicker">
                    <template #icon>
                        <NcLoadingIcon v-if="uploading" :size="18" />
                        <Paperclip v-else :size="18" />
                    </template>
                </NcButton>

                <!-- Hidden native file input -->
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="post-form__file-input"
                    @change="onFilesSelected" />

                <span class="post-form__toolbar-hint">
                    {{ t('teamhub', 'Type / to open Smart Picker') }}
                </span>
            </div>

            <!-- Upload progress list — aria-live announces status changes to screen readers (WCAG 4.1.3) -->
            <div
                v-if="attachments.length > 0"
                class="post-form__attachments"
                aria-live="polite"
                aria-atomic="false">
                <div
                    v-for="(att, i) in attachments"
                    :key="att.id"
                    class="post-form__attachment"
                    :class="{ 'post-form__attachment--error': att.error }">
                    <Paperclip :size="14" class="post-form__attachment-icon" />
                    <span class="post-form__attachment-name">{{ att.name }}</span>
                    <span v-if="att.uploading" class="post-form__attachment-status">
                        <NcLoadingIcon :size="14" />
                        {{ t('teamhub', 'Uploading…') }}
                    </span>
                    <span v-else-if="att.error" class="post-form__attachment-status post-form__attachment-status--error">
                        {{ att.error }}
                    </span>
                    <span v-else class="post-form__attachment-status post-form__attachment-status--done">
                        <!-- aria-label provides text alternative to the checkmark symbol -->
                        <span :aria-label="t('teamhub', 'Upload complete')">✓</span>
                    </span>
                    <NcButton
                        variant="tertiary"
                        :aria-label="t('teamhub', 'Remove attachment')"
                        @click="removeAttachment(i)">
                        <template #icon><Close :size="14" /></template>
                    </NcButton>
                </div>
            </div>
        </div>

        <!-- Poll options -->
        <div v-if="messageType === 'poll'" class="post-form__poll-options">
            <label class="post-form__label">{{ t('teamhub', 'Poll Options') }}</label>
            <div v-for="(option, index) in pollOptions" :key="option.id" class="poll-option-row">
                <NcTextField
                    v-model="option.text"
                    :label="t('teamhub', 'Option {n}', { n: index + 1 })"
                    :placeholder="t('teamhub', 'Enter option text')" />
                <NcButton
                    v-if="pollOptions.length > 2"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Remove option')"
                    @click="removePollOption(index)">
                    <template #icon><Close :size="20" /></template>
                </NcButton>
            </div>
            <NcButton
                v-if="pollOptions.length < 10"
                variant="tertiary"
                @click="addPollOption">
                <template #icon><Plus :size="20" /></template>
                {{ t('teamhub', 'Add option') }}
            </NcButton>
        </div>

        <!-- Decision options -->
        <div v-if="messageType === 'decision'" class="post-form__decision-options">
            <!-- Supersede banner — shown when this proposal will replace another -->
            <div v-if="decisionSupersedesId" class="decision-supersede-banner" role="note">
                <SwapHorizontal :size="16" aria-hidden="true" />
                <span class="decision-supersede-banner__text">
                    <!-- TRANSLATORS: shown above the message composer when the user is creating a proposal that supersedes an earlier one -->
                    {{ t('teamhub', 'This proposal will supersede decision #{id}. The original will be withdrawn if it is still open.', { id: decisionSupersedesId }) }}
                </span>
                <button
                    type="button"
                    class="decision-supersede-banner__clear"
                    :aria-label="t('teamhub', 'Cancel superseding')"
                    :title="t('teamhub', 'Cancel superseding')"
                    @click="decisionSupersedesId = null">
                    <Close :size="14" />
                </button>
            </div>

            <div class="decision-field">
                <label class="post-form__label" for="decision-impact">
                    {{ t('teamhub', 'Impact') }}
                    <span class="decision-required" aria-hidden="true">*</span>
                </label>
                <div id="decision-impact" class="decision-impact-row" role="radiogroup" :aria-label="t('teamhub', 'Decision impact')">
                    <label
                        v-for="opt in impactOptions"
                        :key="opt.value"
                        class="decision-impact-chip"
                        :class="{ active: decisionImpact === opt.value, ['decision-impact-chip--' + opt.value]: true }">
                        <input
                            v-model="decisionImpact"
                            type="radio"
                            name="decision-impact-radio"
                            :value="opt.value">
                        <span>{{ opt.label }}</span>
                    </label>
                </div>
            </div>

            <!-- Level picker — only rendered when the per-team toggle is on -->
            <div v-if="decisionsLevelEnabled" class="decision-field">
                <label class="post-form__label" for="decision-level">
                    {{ t('teamhub', 'Level') }}
                </label>
                <div id="decision-level" class="decision-impact-row" role="radiogroup" :aria-label="t('teamhub', 'Decision level')">
                    <label
                        v-for="opt in levelOptions"
                        :key="opt.value"
                        class="decision-impact-chip decision-level-chip"
                        :class="{ active: decisionLevel === opt.value }">
                        <input
                            v-model="decisionLevel"
                            type="radio"
                            name="decision-level-radio"
                            :value="opt.value">
                        <span>{{ opt.label }}</span>
                    </label>
                </div>
            </div>

            <div class="decision-field">
                <label class="post-form__label" for="decision-category">
                    {{ t('teamhub', 'Category') }}
                    <span class="decision-required" aria-hidden="true">*</span>
                </label>

                <!-- No categories yet — admin warning -->
                <div v-if="!loadingCategories && !decisionCategoryOptions.length" class="decision-category-empty" role="alert">
                    <!-- TRANSLATORS: Shown in the message composer when no decision categories have been set up for this team -->
                    {{ t('teamhub', 'No decision categories have been set up for this team. Ask a team admin to add categories in Manage team → Decisions.') }}
                </div>

                <NcSelect
                    v-else
                    id="decision-category"
                    v-model="decisionCategory"
                    :options="decisionCategoryOptions"
                    :loading="loadingCategories"
                    :clearable="false"
                    :searchable="true"
                    :placeholder="t('teamhub', 'Pick a category')"
                    label="name"
                    track-by="id"
                    :aria-label="t('teamhub', 'Decision category')" />
            </div>
        </div>

        <!-- Actions -->
        <div class="post-form__actions">
            <NcButton
                variant="primary"
                :disabled="!canSubmit || submitting || uploading"
                @click="submit">
                <template #icon>
                    <NcLoadingIcon v-if="submitting" :size="20" />
                    <Send v-else :size="20" />
                </template>
                {{ submitButtonText }}
            </NcButton>
            <NcButton variant="tertiary" @click="$emit('cancel')">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
        </div>

        <!-- Insert image by URL dialog -->
        <NcDialog
            v-if="imageDialogOpen"
            :name="t('teamhub', 'Insert image')"
            :open="true"
            size="normal"
            @closing="imageDialogOpen = false">
            <div class="post-form__image-dialog">
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
                <p class="post-form__image-dialog-divider">
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
                <p class="post-form__image-dialog-hint">
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
    </div>
</template>

<script>
import { mapState, mapActions } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import {
    NcButton,
    NcTextField,
    NcRichContenteditable,
    NcLoadingIcon,
    NcDialog,
    NcSelect,
} from '@nextcloud/vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'

// TeamHub attachment folder inside the user's Files
const ATTACH_FOLDER = 'TeamHub Attachments'

export default {
    name: 'PostMessageForm',
    components: {
        NcButton, NcTextField, NcRichContenteditable, NcLoadingIcon, NcDialog, NcSelect,
        MessageOutline, HelpCircleOutline, PollIcon, GavelIcon, Plus, Close, Send, SwapHorizontal,
        Paperclip, LinkVariant,
        FormatBold, FormatItalic, CodeTags, CodeBraces,
        FormatHeader2, FormatListBulleted, ImageIcon, FolderIcon,
    },
    emits: ['submitted', 'cancel'],

    props: {
        // When true, hides the message-type selector (Message/Poll/Question/Decision)
        // and locks the form into 'decision' mode. Used by ComposeDecisionModal
        // so the form can be embedded in a Decisions-only modal without exposing
        // the other message types.
        forceDecision: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            subject: '',
            body: '',
            // When forceDecision is set we initialise messageType to 'decision'
            // in created() (props aren't available at data() time in Options API
            // factory functions). The default below covers the normal inline case.
            messageType: 'normal',
            // Decision-specific fields (only used when messageType === 'decision')
            decisionImpact: '',
            decisionLevel: 'operational',
            // NcSelect bound — selected option object { id, name, approvers, ... } or null
            decisionCategory: null,
            // Predefined categories for this team — populated by loadDecisionCategories
            decisionCategoryOptions: [],
            decisionCategoriesLoaded: false,
            loadingCategories: false,
            // Supersede handshake (Session E flow finally wired in Session K).
            // When non-null, this proposal supersedes the referenced decision —
            // the backend auto-withdraws that decision on successful submit.
            decisionSupersedesId: null,
            // Poll options carry a stable `id` so the v-for can key on identity
            // rather than array index (perf pass V6). Index-as-key on inputs
            // bound with v-model causes Vue to reuse the wrong DOM node when an
            // option is removed from the middle of the list. `pollOptionSeq` is
            // a monotonic counter that hands out those ids.
            pollOptionSeq: 2,
            pollOptions: [{ id: 0, text: '' }, { id: 1, text: '' }],
            submitting: false,
            uploading: false,
            // Monotonic counter handing out stable ids for attachment rows so
            // the v-for keys on identity, not array index (perf pass V6).
            attachmentSeq: 0,
            // Each entry: { id, name, uploading, error, shareUrl }
            attachments: [],
            // Insert-image-by-URL dialog
            imageDialogOpen: false,
            imageDialogUrl: '',
            imageDialogAlt: '',
            imageDialogWidth: '',
            imageDialogBrowsing: false,
            // DAV path of the file chosen via browseImageFromFiles (e.g. /Photos/cat.jpg).
            // Set during browse; cleared when the dialog is opened fresh or confirmed.
            // Used by confirmImageDialog to share the file with the team circle
            // before inserting the public-preview URL.
            imageDialogFilePath: null,
        }
    },

    computed: {
        ...mapState(['members', 'allEffectiveMembers', 'messageSettings', 'decisionsModuleEnabled', 'decisionsConfig', 'currentView']),

        mentions() {
            // NcRichContenteditable user-data must be a plain object keyed by userId.
            // Each value: { id, label, source, icon, status }
            // An array is silently ignored, producing no mention autocomplete.
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

        subjectLabel() {
            if (this.messageType === 'poll') return t('teamhub', 'Poll Question')
            if (this.messageType === 'question') return t('teamhub', 'Question')
            if (this.messageType === 'decision') return t('teamhub', 'Decision question')
            return t('teamhub', 'Subject')
        },
        subjectPlaceholder() {
            if (this.messageType === 'poll') return t('teamhub', 'What would you like to ask?')
            if (this.messageType === 'question') return t('teamhub', 'Your question…')
            if (this.messageType === 'decision') return t('teamhub', 'What needs to be decided?')
            return t('teamhub', 'Message subject')
        },
        bodyLabel() {
            if (this.messageType === 'poll') return t('teamhub', 'Description (optional)')
            if (this.messageType === 'question') return t('teamhub', 'Details (optional)')
            if (this.messageType === 'decision') return t('teamhub', 'Context')
            return t('teamhub', 'Message')
        },
        bodyPlaceholder() {
            if (this.messageType === 'poll') return t('teamhub', 'Add more context to your poll…')
            if (this.messageType === 'question') return t('teamhub', 'Provide more details…')
            if (this.messageType === 'decision') return t('teamhub', 'Explain the trade-offs, constraints, or background…')
            return t('teamhub', 'Write your message… (type / for Smart Picker, @ to mention)')
        },
        submitButtonText() {
            if (this.messageType === 'poll') return t('teamhub', 'Create Poll')
            if (this.messageType === 'question') return t('teamhub', 'Ask Question')
            if (this.messageType === 'decision') return t('teamhub', 'Propose Decision')
            return t('teamhub', 'Post Message')
        },

        canSubmit() {
            if (!this.subject.trim()) return false
            if (this.messageType === 'poll') {
                return this.pollOptions.filter(o => o.text.trim()).length >= 2
            }
            if (this.messageType === 'normal' && !this.body.trim() && this.attachments.filter(a => a.shareUrl).length === 0) return false
            if (this.messageType === 'decision') {
                // Impact is required.
                if (!this.decisionImpact) return false
                // Category is now required — picked from the predefined team
                // list. If the team has no categories at all, the form shows
                // a warning above and the user can't pick one, so submit
                // remains disabled.
                if (!this.decisionCategory) return false
            }
            return true
        },

        // ── Decision helpers ────────────────────────────────────────────────

        /**
         * Decision compose option is shown only when the module is enabled
         * globally AND for the current team. Both flags arrive on the layout
         * response (loaded by TeamView) and live in the Vuex store.
         */
        decisionsAvailable() {
            return !!(this.decisionsModuleEnabled
                && this.decisionsConfig
                && this.decisionsConfig.decisions_enabled)
        },

        // True when the per-team level toggle is on.
        decisionsLevelEnabled() {
            return !!(this.decisionsConfig && this.decisionsConfig.decisions_level_enabled)
        },

        impactOptions() {
            return [
                { value: 'low',    label: t('teamhub', 'Low') },
                { value: 'medium', label: t('teamhub', 'Medium') },
                { value: 'high',   label: t('teamhub', 'High') },
            ]
        },

        levelOptions() {
            return [
                // TRANSLATORS: Decision level — day-to-day operational decisions
                { value: 'operational', label: t('teamhub', 'Operational') },
                // TRANSLATORS: Decision level — medium-term tactical decisions
                { value: 'tactical',    label: t('teamhub', 'Tactical') },
                // TRANSLATORS: Decision level — long-term strategic decisions
                { value: 'strategic',   label: t('teamhub', 'Strategic') },
            ]
        },
    },

    watch: {
        // When the user picks Decision for the first time, fetch the team's
        // predefined categories. Subsequent toggles are a no-op.
        messageType(newVal) {
            if (newVal === 'decision' && !this.decisionCategoriesLoaded) {
                this.loadDecisionCategories()
            }
        },
        // When the user navigates back to the message stream (e.g. from
        // the Decisions tab's Supersede flow), check the global handshake.
        currentView(newVal) {
            if (newVal === 'msgstream') {
                this.consumeDecisionComposeHandshake()
            }
        },
    },

    created() {
        // When the form is embedded in ComposeDecisionModal, lock into decision
        // mode from the very first render so the decision-specific UI shows
        // without flicker, and prime the category dropdown.
        if (this.forceDecision) {
            this.messageType = 'decision'
            this.loadDecisionCategories()
        }
    },

    mounted() {
        // Catch the handshake if the form was already mounted when the
        // user clicked Supersede (the common case — the stream form is
        // typically already in the DOM, hidden behind a tab).
        this.consumeDecisionComposeHandshake()
    },

    methods: {
        t,
        ...mapActions(['postMessage']),

        /**
         * Read window.__teamhubDecisionCompose set by TeamView.openDecisionCompose().
         * If present, pre-set the form to Decision mode and stash the
         * supersedesId. Clears the global so we don't double-consume.
         */
        consumeDecisionComposeHandshake() {
            const handshake = window.__teamhubDecisionCompose
            if (!handshake) return
            window.__teamhubDecisionCompose = null
            this.messageType = 'decision'
            this.decisionSupersedesId = handshake.supersedesId || null
            // Trigger category load if not already done (watcher handles
            // the case where messageType was already 'decision').
            if (!this.decisionCategoriesLoaded) {
                this.loadDecisionCategories()
            }
        },

        /**
         * Auto-complete callback for NcRichContenteditable @-mentions.
         *
         * We use the NC core OCS autocomplete API rather than filtering the local
         * members array ourselves. This is the same endpoint NC Talk and NC Comments
         * use — it returns users in the exact shape NcRichContenteditable expects,
         * and its results render correctly via NcAutoCompleteResult (themed, avatar, etc).
         *
         * We scope results to the team's members by intersecting with the store's
         * members list after fetching.
         */
        async mentionAutoComplete(search, callback) {
            try {
                // Ensure we have the full effective member list — fetch live if
                // the store is empty (e.g. component mounted before selectTeam
                // completed, or the fetch failed silently on team load).
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
                        } catch (fetchErr) {
                            // Non-fatal — continue with empty mentionList
                        }
                    }
                }

                // Run OCS autocomplete and team-member filter in parallel
                const ocsPromise = axios.get(
                    generateUrl('/ocs/v2.php/core/autocomplete/get'),
                    {
                        params: { search: search || '', itemType: 'call', itemId: 'new', sorter: '', limit: 20, format: 'json' },
                        headers: { 'OCS-APIREQUEST': 'true' },
                    }
                ).then(r => r.data?.ocs?.data || []).catch(() => [])

                const ocsUsers = await ocsPromise

                const memberIds = new Set(mentionList.map(m => m.userId))
                const lower = (search || '').toLowerCase()

                // OCS results scoped to team members
                const filtered = ocsUsers.filter(u =>
                    memberIds.has(u.id) || memberIds.has(u.value?.shareWith)
                )

                // Supplement with team members absent from OCS (NC privacy settings
                // may restrict user enumeration for users in other groups/orgs).
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
                // Full fallback — fetch live from API, bypass OCS entirely
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

        // ── Markdown toolbar ────────────────────────────────────────────────
        /**
         * Insert markdown syntax around the current selection (or around a
         * placeholder if nothing is selected). Works for both mouse and keyboard
         * activation:
         *
         * - Mouse path: @mousedown.prevent keeps the contenteditable focused and
         *   selection alive; execCommand fires into the active element, the
         *   component's 'input' listener updates v-model automatically.
         *
         * - Keyboard path: the button receives focus, so we re-focus the editor
         *   and append to the model string as a reliable fallback (no cursor
         *   position tracking when focus has left the editor).
         *
         * @param {string} before   Markdown prefix (e.g. '**')
         * @param {string} after    Markdown suffix (e.g. '**'), empty for line prefixes
         * @param {string} placeholder  Fallback text when there is no selection
         */
        applyMarkdown(before, after, placeholder = '') {
        
            const editorEl = this.$refs.editor?.$el?.querySelector('.rich-contenteditable__input')
                          || this.$refs.editor?.$el

            if (!editorEl) {
                // No DOM reference — fall back to appending to the model.
                this.body += before + (placeholder || '') + after
                return
            }

            const activeEl = document.activeElement
            const editorHasFocus = editorEl === activeEl || editorEl.contains(activeEl)

            if (editorHasFocus) {
                // Mouse path: selection is still live in the contenteditable.
                const sel = window.getSelection()
                const selectedText = (sel && !sel.isCollapsed) ? sel.toString() : (placeholder || '')
                document.execCommand('insertText', false, before + selectedText + after)
            } else {
                // Keyboard path: focus was elsewhere. Append to the model string
                // and move the cursor to the body field for the user to continue.
                const selectedText = placeholder || ''
                this.body += (this.body && !this.body.endsWith('\n') ? '\n' : '') + before + selectedText + after
                this.$nextTick(() => editorEl.focus())
            }
        },

        /**
         * Insert a Markdown link `[text](url)`.
         * If text is selected, it becomes the link label;
         * otherwise a placeholder is used.
         * The user can tab through the brackets to complete the URL.
         */
        applyLink() {
            const editorEl = this.$refs.editor?.$el?.querySelector('.rich-contenteditable__input')
                          || this.$refs.editor?.$el

            const sel = window.getSelection()
            const selectedText = (sel && !sel.isCollapsed) ? sel.toString() : ''
            const label = selectedText || t('teamhub', 'link text')

            if (editorEl && (editorEl === document.activeElement || editorEl.contains(document.activeElement))) {
                document.execCommand('insertText', false, `[${label}](url)`)
            } else {
                this.body += `[${label}](url)`
                this.$nextTick(() => editorEl?.focus())
            }
        },

        // ── Insert image by URL ──────────────────────────────────────────────
        /**
         * Open the insert-image dialog. Source safety (https→proxy, same-origin
         * passthrough, everything else dropped) is enforced at render time by the
         * DOMPurify hook in MessageCard; this dialog only composes the
         * `![alt|width](url)` markdown and appends it to the body.
         */
        openImageDialog() {
            this.imageDialogUrl = ''
            this.imageDialogAlt = ''
            this.imageDialogWidth = ''
            this.imageDialogFilePath = null
            this.imageDialogOpen = true
            this.$nextTick(() => this.$refs.imageUrlField?.$el?.querySelector('input')?.focus())
        },

        confirmImageDialog() {
            const url = this.imageDialogUrl.trim()
            if (!url) return
            const alt = this.imageDialogAlt.trim()
            const w = parseInt(this.imageDialogWidth, 10)
            const widthSeg = (Number.isFinite(w) && w >= 1 && w <= 2000) ? `|${w}` : ''
            // The URL is either a remote https URL (proxied at render time by
            // MessageCard) or a /core/preview URL pointing to the .teamhub-cache
            // copy in the team folder — accessible to all circle members.
            const snippet = `![${alt}${widthSeg}](${url})`
            this.body = this.body + (this.body && !this.body.endsWith('\n') ? '\n' : '') + snippet
            this.imageDialogFilePath = null
            this.imageDialogOpen = false
        },

        /**
         * Open the NC FilePicker filtered to images, then copy the chosen file
         * into the team folder's hidden image cache (.teamhub-cache) via the
         * PHP endpoint. The cached copy lives in the circle-shared team folder
         * so all members can load it via /core/preview without per-user ACL.
         *
         * Blocked with an error if the team has no Files folder connected.
         */
        async browseImageFromFiles() {
            // Guard: team must have a Files folder for the cache to work.
            const teamFiles = this.$store.state.resources?.files
            if (!teamFiles?.folder_id) {
                showError(t('teamhub', 'Connect a Files folder to this team first to insert images from your files.'))
                return
            }

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
                        // be applied. The pick() promise resolves with the chosen path
                        // when this button is clicked; the callback itself is a no-op.
                        label: t('teamhub', 'Choose'),
                        variant: 'primary',
                        callback: () => {},
                    })
                    .build()
                const result = await picker.pick()

                // v6 returns string path, v7 returns Node[] or string[] depending
                // on configuration. Normalise to a single path string.
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


                // POST to the PHP endpoint — copies the file into .teamhub-cache
                // inside the team folder and returns the cached file's numeric fileId.
                const teamId = this.$store.state.currentTeamId
                const resp = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/messages/cache-image`),
                    { teamFolderId: teamFiles.folder_id, sourcePath: path },
                )
                const fileId = resp.data?.fileId
                if (!fileId) {
                    showError(t('teamhub', 'Could not cache the image in the team folder'))
                    return
                }


                // Pre-fill the dialog with /core/preview for the CACHED copy.
                // This URL is accessible to all team members because the cached
                // file lives in the circle-shared team folder.
                const basename = path.split('/').filter(Boolean).pop() || ''
                this.imageDialogUrl = generateUrl('/core/preview') + `?fileId=${fileId}&x=1024&y=1024&a=true`
                this.imageDialogFilePath = null  // no client-side sharing needed
                if (!this.imageDialogAlt) {
                    this.imageDialogAlt = basename
                }
            } catch (e) {
                if (e?.response?.data?.error) {
                    showError(t('teamhub', 'Failed to cache image: {error}', { error: e.response.data.error }))
                }
            } finally {
                this.imageDialogBrowsing = false
            }
        },

        // ── Smart Picker ────────────────────────────────────────────────────
        async openSmartPicker() {
            try {
                // getLinkWithPicker opens the NC Smart Picker modal and resolves with
                // the picked URL/text. null = show provider selection first.
                const { getLinkWithPicker } = await import('@nextcloud/vue/components/NcRichText') // v9: /dist/ path removed
                const result = await getLinkWithPicker(null)
                if (result) {
                    // Append the picked link to the body
                    this.body = this.body + (this.body && !this.body.endsWith('\n') ? '\n' : '') + result
                }
            } catch (e) {
                if (e?.message !== 'User cancelled') {
                }
                // User closed picker — no-op
            }
        },

        // ── File attachment ─────────────────────────────────────────────────
        triggerFilePicker() {
            this.$refs.fileInput.value = ''
            this.$refs.fileInput.click()
        },

        async onFilesSelected(event) {
            const files = Array.from(event.target.files || [])
            if (!files.length) return

            this.uploading = true
            await Promise.all(files.map(file => this.uploadFile(file)))
            this.uploading = false
        },

        async uploadFile(file) {
            const uid = getCurrentUser()?.uid
            if (!uid) {
                showError(t('teamhub', 'Cannot upload — not logged in'))
                return
            }

            const att = { id: this.attachmentSeq++, name: file.name, uploading: true, error: null, filePath: null }
            this.attachments.push(att)
            // Resolve the row by identity at write time, never by a captured index.
            // Concurrent uploads (Promise.all) and removeAttachment() splicing the
            // array mid-upload both invalidate any index captured before an await,
            // which previously caused the upload result to land on the wrong row.
            const writeAttachment = (patch) => {
                const at = this.attachments.findIndex(a => a.id === att.id)
                // Row may have been removed by the user while the upload was in
                // flight — if so, silently drop the result.
                if (at === -1) {
                    return
                }
                this.attachments[at] = { ...this.attachments[at], ...patch }
            }

            try {
                // Determine upload folder. Strategy:
                //   - Shared-folder team folder → upload into {team folder}/Attachments
                //     (lives inside the team's share; everyone in the team can read it)
                //   - Group Folder team folder → upload into the user's PERSONAL
                //     'TeamHub Attachments/' instead. Group Folders have their own
                //     ACL layer that often forbids create-folder for normal members
                //     (MKCOL returns 405) and the path can include the mount-point
                //     prefix vs. the team-subfolder prefix in ways that vary by
                //     install. Keeping uploads in personal files for the GF case
                //     avoids both problems. The decision-finalize step later
                //     copies attachments into .proposals/{decisionId}/ via
                //     server-side file-id lookup, so the user-side upload
                //     location is decoupled from where the proposal eventually
                //     stores its source files.
                //   - No team folder → personal /TeamHub Attachments
                const teamFiles    = this.$store.state.resources?.files || null
                const teamFilesPath = teamFiles?.path || null
                const teamFolderType = teamFiles?.folder_type || null
                let uploadFolder

                if (teamFilesPath && teamFolderType === 'shared') {
                    // teamFilesPath is the file_target from share table, e.g. "/Team Name"
                    uploadFolder = teamFilesPath.replace(/\/$/, '') + '/Attachments'
                } else {
                    // Group folder, or no team folder at all → personal attachments
                    uploadFolder = '/' + ATTACH_FOLDER
                }
                // Debug log stripped at session end (3.71.10).
                // 1. Ensure folder exists (MKCOL — 405 = already exists, fine)
                const folderDavUrl = generateRemoteUrl(`dav/files/${uid}${uploadFolder}`)
                try {
                    await axios({ method: 'MKCOL', url: folderDavUrl })
                } catch (e) {
                    if (e.response?.status !== 405) throw e
                }

                // 2. Upload file via WebDAV PUT (deduplicate filename if needed)
                let fileName = file.name
                let fileDavUrl = generateRemoteUrl(`dav/files/${uid}${uploadFolder}/${fileName}`)
                // Check if file exists first with a HEAD — if so, add timestamp suffix
                try {
                    await axios.head(fileDavUrl)
                    // File exists — add timestamp
                    const ext = fileName.includes('.') ? '.' + fileName.split('.').pop() : ''
                    const base = ext ? fileName.slice(0, -ext.length) : fileName
                    fileName = `${base}-${Date.now()}${ext}`
                    fileDavUrl = generateRemoteUrl(`dav/files/${uid}${uploadFolder}/${fileName}`)
                } catch (headErr) {
                    // 404 = file doesn't exist — good, use original name
                }

                const putResp = await axios.put(fileDavUrl, file, {
                    headers: { 'Content-Type': file.type || 'application/octet-stream' },
                })

                // NC returns the new numeric fileId in OC-FileId on a successful
                // PUT — we need it to build the core preview URL for images.
                // Header may be `<id>oc<instanceid>`; strip the suffix.
                const fileIdRaw = putResp.headers?.['oc-fileid'] || ''
                const fileIdMatch = String(fileIdRaw).match(/^(\d+)/)
                const fileId = fileIdMatch ? fileIdMatch[1] : null

                // 3. Share the file with the circle (internal share, not public link)
                //    This lets all team members access it
                const ncFilePath = `${uploadFolder}/${fileName}`
                const circleId = this.$store.state.currentTeamId
                let shareUrl = null
                let shareToken = null

                if (circleId) {
                    try {
                        const shareResp = await axios.post(
                            generateUrl('/ocs/v2.php/apps/files_sharing/api/v1/shares'),
                            new URLSearchParams({
                                path: ncFilePath,
                                shareType: '7',       // 7 = TYPE_CIRCLE (internal, no password needed)
                                shareWith: circleId,
                                permissions: '1',     // read-only
                            }),
                            { headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json' } }
                        )
                        shareUrl = shareResp.data?.ocs?.data?.url || null
                        shareToken = shareResp.data?.ocs?.data?.token || null
                    } catch (shareErr) {
                        // Share with circle failed — file is uploaded but not shared
                    }
                }

                // 4. Build the file URL.
                //    For images: use the public-preview endpoint keyed on the share
                //    token — accessible to all circle members without per-user ACL.
                //    /core/preview checks per-user ACL via session and returns 404
                //    for other users even when the file is circle-shared.
                //    For non-images: prefer share landing URL, fall back to dav URL.
                const uid2 = getCurrentUser()?.uid
                const davDownloadUrl = generateRemoteUrl(`dav/files/${uid2}${uploadFolder}/${fileName}`)
                const isImage = (file.type || '').startsWith('image/')
                let fileViewUrl
                if (isImage && shareToken) {
                    // Public-preview: served by files_sharing, honours share token,
                    // no per-user ACL check — all circle members can render this URL.
                    fileViewUrl = generateUrl('/apps/files_sharing/publicpreview')
                        + `?token=${encodeURIComponent(shareToken)}&x=1024&y=1024&a=true`
                } else if (isImage && fileId) {
                    // Fallback: share failed — poster sees it, others may not
                    fileViewUrl = generateUrl('/core/preview') + `?fileId=${fileId}&x=1024&y=1024&a=true`
                } else {
                    fileViewUrl = shareUrl || davDownloadUrl
                }

                // 5. Append markdown link.
                //    For image content types, emit inline-image markdown so it
                //    renders in the body (the preview URL above is same-origin,
                //    so it passes the sanitizer's same-origin check). Non-images
                //    keep the 📎 link.
                const linkText = isImage
                    ? `![${fileName}](${fileViewUrl})`
                    : `[📎 ${fileName}](${fileViewUrl})`
                this.body = this.body + (this.body && !this.body.endsWith('\n') ? '\n' : '') + linkText

                writeAttachment({
                    uploading: false,
                    filePath: ncFilePath,
                    fileId: fileId ? parseInt(fileId, 10) : null,
                    fileName,
                })

            } catch (e) {
                const msg = e.response?.data?.ocs?.meta?.message || e.response?.statusText || e.message || 'Upload failed'
                writeAttachment({ uploading: false, error: msg })
                showError(t('teamhub', 'Failed to upload {name}: {error}', { name: file.name, error: msg }))
            }
        },

        removeAttachment(i) {
            const att = this.attachments[i]
            if (att.name) {
                // Match both the inline-image form ![name](url) and the 📎 link
                // form [📎 name](url) — uploads emit one or the other by type.
                const escName = att.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
                const lineToRemove = new RegExp(`\\n?(?:!\\[${escName}\\]|\\[📎 ${escName}\\])\\([^)]+\\)`, 'g')
                this.body = this.body.replace(lineToRemove, '')
            }
            this.attachments.splice(i, 1)
        },

        // ── Poll ────────────────────────────────────────────────────────────
        addPollOption() { this.pollOptions.push({ id: this.pollOptionSeq++, text: '' }) },
        removePollOption(index) { this.pollOptions.splice(index, 1) },

        // ── Decision helpers ────────────────────────────────────────────────

        async loadDecisionCategories() {
            const teamId = this.$store.state.currentTeamId
            this.loadingCategories = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/decisions/manage/categories`)
                )
                this.decisionCategoryOptions = Array.isArray(data?.items) ? data.items : []
                this.decisionCategoriesLoaded = true
            } catch (err) {
                console.error('[TeamHub][PostMessageForm] loadDecisionCategories error:', err)
                // Non-fatal — the template renders the "no categories" warning
                // when the list is empty, so the user still sees something.
                this.decisionCategoriesLoaded = true
            } finally {
                this.loadingCategories = false
            }
        },

        resetDecisionFields() {
            this.decisionImpact    = ''
            this.decisionLevel     = 'operational'
            this.decisionCategory  = null
            this.decisionSupersedesId = null
        },

        // ── Submit ──────────────────────────────────────────────────────────
        async submit() {
            if (!this.canSubmit) return
            this.submitting = true
            try {
                const messageData = {
                    subject: this.subject.trim(),
                    message: this.body.trim(),
                    messageType: this.messageType,
                    priority: 'normal',
                    pollOptions: null,
                    decision: null,
                }
                if (this.messageType === 'poll') {
                    messageData.pollOptions = this.pollOptions.map(o => o.text.trim()).filter(Boolean)
                }
                if (this.messageType === 'decision') {
                    messageData.decision = {
                        impact: this.decisionImpact,
                        level: this.decisionsLevelEnabled ? this.decisionLevel : 'operational',
                        // Category is now picked from the predefined list (an
                        // object with id/name) — send the name string the
                        // backend currently stores. Required field; submit is
                        // gated upstream so this is non-null.
                        category: this.decisionCategory?.name || null,
                        // source_type defaults to 'message' server-side for
                        // decisions created from the compose form.
                        // supersedesId not exposed in v1 compose form — only via
                        // the "Supersede this decision" entry point from Session E.
                        // Set when the proposer used the "Supersede" action
                        // from the Decisions tab. Backend auto-withdraws the
                        // referenced decision if it was still open.
                        supersedesId: this.decisionSupersedesId,
                        // Session A: when the form is in the compose modal
                        // (forceDecision prop), the proposer has written the
                        // full proposal upfront. Skip the open/discussion phase
                        // and land directly on 'finalized' (awaits approval).
                        autoFinalize: this.forceDecision,
                    }
                }

                const createdMessage = await this.postMessage(messageData)

                // Register attachments (v3.71.2) — link uploaded files to this
                // message via the sidecar table so the Decisions module can
                // copy them into .proposals/{decisionId}/ on finalize.
                // Best-effort: a single failure does not block the success path.
                const newMessageId = createdMessage?.id || createdMessage?.message?.id || null
                if (newMessageId) {
                    const toRegister = this.attachments.filter(a => a.fileId && !a.error)
                    if (this.attachments.length > 0 && toRegister.length === 0) {
                        console.warn('[TeamHub][PostMessageForm] attachments present but none have fileId — uploads likely failed')
                    }
                    for (const att of toRegister) {
                        try {
                            await axios.post(
                                generateUrl(`/apps/teamhub/api/v1/messages/${newMessageId}/attachments`),
                                { file_id: att.fileId, file_name: att.fileName || att.name }
                            )
                        } catch (regErr) {
                            console.warn('[TeamHub][PostMessageForm] attachment registration failed (non-fatal)', {
                                fileId: att.fileId, err: regErr?.message,
                            })
                            showError(t(
                                'teamhub',
                                'Failed to link attachment {name}: {error}',
                                {
                                    name:  att.fileName || att.name || '(unknown)',
                                    error: regErr?.response?.data?.error || regErr?.message || 'Unknown error',
                                },
                            ))
                        }
                    }

                    // Session A — compose modal posts as auto-finalized. The
                    // proposal-doc write was intentionally skipped inside
                    // propose() (transaction-timing concerns). Run it now
                    // via refresh-proposal so .proposals/{id}/{id}.md is
                    // written and any attachment copies land alongside.
                    // Always fires for compose-modal decisions, even with
                    // zero attachments — the .md is the primary artefact.
                    const newDecisionId = createdMessage?.decision?.id || null
                    if (this.forceDecision && newDecisionId) {
                        try {
                            await axios.post(
                                generateUrl(`/apps/teamhub/api/v1/teams/${this.$store.state.currentTeamId}/decisions/${newDecisionId}/refresh-proposal`)
                            )
                        } catch (refreshErr) {
                            console.warn('[TeamHub][PostMessageForm] refresh-proposal failed (non-fatal)', refreshErr?.message)
                        }
                    }
                }

                showSuccess(
                    this.messageType === 'poll'     ? t('teamhub', 'Poll created!') :
                    this.messageType === 'question' ? t('teamhub', 'Question posted!') :
                    this.messageType === 'decision' ? t('teamhub', 'Decision proposed!') :
                                                      t('teamhub', 'Message posted!')
                )
                this.$emit('submitted', createdMessage)

                // Reset
                this.subject = ''
                this.body = ''
                this.messageType = 'normal'
                this.pollOptions = [{ id: this.pollOptionSeq++, text: '' }, { id: this.pollOptionSeq++, text: '' }]
                this.attachments = []
                this.resetDecisionFields()
            } catch (e) {
                const status = e?.response?.status
                const isHtml = (e?.response?.headers?.['content-type'] ?? '').includes('text/html')
                if (isHtml && (status === 500 || status === 403)) {
                    showError(t('teamhub', 'Session expired — please reload the page and try again'))
                } else {
                    showError(t('teamhub', 'Failed to post — server error {status}', { status: status ?? '?' }))
                }
            } finally {
                this.submitting = false
            }
        },
    },
}
</script>

<style scoped>
.post-form {
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Type selector */
.post-form__type {
    display: flex;
    gap: 10px;
}

.post-form__type-option {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}

.post-form__type-option input { display: none; }

.post-form__type-option.active {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
}

/* Body + toolbar */
.post-form__body {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.post-form__label {
    font-weight: 500;
    font-size: 13px;
}

/* Toolbar row beneath the editor */
.post-form__toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 2px 4px;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    background: var(--color-background-hover);
}

/* Markdown formatting toolbar — sits between the editor and the utility toolbar.
   Uses the same border treatment so it reads as a unified editor chrome. */
.post-form__md-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 2px 4px;
    border: 1px solid var(--color-border);
    border-top: none;
    background: var(--color-background-hover);
}

/* Thin separator between the md-toolbar and the utility toolbar */
.post-form__md-toolbar + .post-form__toolbar {
    border-top: 1px solid var(--color-border-dark);
}

.post-form__toolbar-hint {
    margin-left: auto;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    padding-right: 6px;
    white-space: nowrap;
}

/* Hidden file input */
.post-form__file-input {
    display: none;
}

/* Attachment list */
.post-form__attachments {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 4px;
}

.post-form__attachment {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    font-size: 13px;
}

.post-form__attachment--error {
    background: var(--color-error-hover, #fff0f0);
}

.post-form__attachment-icon { flex-shrink: 0; color: var(--color-text-maxcontrast); }
.post-form__attachment-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.post-form__attachment-status {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.post-form__attachment-status--done { color: var(--color-success-text); }
.post-form__attachment-status--error { color: var(--color-error-text); }

/* Actions */
.post-form__actions {
    display: flex;
    gap: 8px;
}

/* Poll */
.post-form__poll-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.poll-option-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.poll-option-row > :first-child { flex: 1; }

/* Decision form */
.post-form__decision-options {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-background-hover);
}

.decision-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.decision-required {
    color: var(--color-error-text);
    margin-left: 4px;
}

.decision-optional {
    color: var(--color-text-maxcontrast);
    font-weight: normal;
    font-size: 0.85em;
    margin-left: 6px;
}

.decision-impact-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.decision-impact-chip {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    border-radius: var(--border-radius-pill);
    border: 1px solid var(--color-border);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    user-select: none;
}

.decision-impact-chip input { display: none; }

.decision-impact-chip:hover {
    background: var(--color-background-dark);
}

.decision-impact-chip.active {
    border-color: var(--color-primary-element);
    background: var(--color-primary-element-light);
    color: var(--color-primary-element-text-dark, var(--color-main-text));
    font-weight: 600;
}

.decision-impact-chip--high.active {
    border-color: var(--color-error);
    background: color-mix(in srgb, var(--color-error) 12%, transparent);
}

.decision-impact-chip--medium.active {
    border-color: var(--color-warning);
    background: color-mix(in srgb, var(--color-warning) 12%, transparent);
}

.decision-impact-chip--low.active {
    border-color: var(--color-success);
    background: color-mix(in srgb, var(--color-success) 12%, transparent);
}

/* Level chip uses a neutral blue-ish accent so it's visually distinct from impact */
.decision-level-chip.active {
    border-color: var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
    color: var(--color-primary-element-text, var(--color-main-text));
}

.decision-category-empty {
    padding: 10px 12px;
    background: var(--color-background-dark);
    border: 1px solid var(--color-warning, var(--color-border-dark));
    border-radius: var(--border-radius);
    font-size: 0.9em;
    color: var(--color-text-maxcontrast);
    line-height: 1.4;
}

.post-form__image-dialog {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 4px 0;
    min-width: 320px;
}

.post-form__image-dialog-hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

.post-form__image-dialog-divider {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    text-align: center;
    margin: 0;
    position: relative;
}
.post-form__image-dialog-divider::before,
.post-form__image-dialog-divider::after {
    content: "";
    position: absolute;
    top: 50%;
    width: calc(50% - 60px);
    height: 1px;
    background: var(--color-border);
}
.post-form__image-dialog-divider::before { left: 0; }
.post-form__image-dialog-divider::after  { right: 0; }
.decision-supersede-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    margin-bottom: 8px;
    background: color-mix(in srgb, var(--color-warning, #c9a227) 12%, transparent);
    border: 1px solid var(--color-warning, #c9a227);
    border-radius: var(--border-radius);
    color: var(--color-warning-text, #a05a00);
    font-size: 0.9em;
}

.decision-supersede-banner__text {
    flex: 1;
    line-height: 1.4;
}

.decision-supersede-banner__clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: var(--border-radius);
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    flex-shrink: 0;
}

.decision-supersede-banner__clear:hover {
    background: color-mix(in srgb, var(--color-warning, #c9a227) 20%, transparent);
}

.decision-supersede-banner__clear:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

</style>
