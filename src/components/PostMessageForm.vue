<template>
    <div class="post-form">
        <!-- Message type selector -->
        <div class="post-form__type">
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
} from '@nextcloud/vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'

// TeamHub attachment folder inside the user's Files
const ATTACH_FOLDER = 'TeamHub Attachments'

export default {
    name: 'PostMessageForm',
    components: {
        NcButton, NcTextField, NcRichContenteditable, NcLoadingIcon,
        MessageOutline, HelpCircleOutline, PollIcon, Plus, Close, Send,
        Paperclip, LinkVariant,
        FormatBold, FormatItalic, CodeTags, CodeBraces,
        FormatHeader2, FormatListBulleted,
    },
    emits: ['submitted', 'cancel'],

    data() {
        return {
            subject: '',
            body: '',
            messageType: 'normal',
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
        }
    },

    computed: {
        ...mapState(['members', 'allEffectiveMembers', 'messageSettings']),

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
            return t('teamhub', 'Subject')
        },
        subjectPlaceholder() {
            if (this.messageType === 'poll') return t('teamhub', 'What would you like to ask?')
            if (this.messageType === 'question') return t('teamhub', 'Your question…')
            return t('teamhub', 'Message subject')
        },
        bodyLabel() {
            if (this.messageType === 'poll') return t('teamhub', 'Description (optional)')
            if (this.messageType === 'question') return t('teamhub', 'Details (optional)')
            return t('teamhub', 'Message')
        },
        bodyPlaceholder() {
            if (this.messageType === 'poll') return t('teamhub', 'Add more context to your poll…')
            if (this.messageType === 'question') return t('teamhub', 'Provide more details…')
            return t('teamhub', 'Write your message… (type / for Smart Picker, @ to mention)')
        },
        submitButtonText() {
            if (this.messageType === 'poll') return t('teamhub', 'Create Poll')
            if (this.messageType === 'question') return t('teamhub', 'Ask Question')
            return t('teamhub', 'Post Message')
        },

        canSubmit() {
            if (!this.subject.trim()) return false
            if (this.messageType === 'poll') {
                return this.pollOptions.filter(o => o.text.trim()).length >= 2
            }
            if (this.messageType === 'normal' && !this.body.trim() && this.attachments.filter(a => a.shareUrl).length === 0) return false
            return true
        },
    },

    methods: {
        t,
        ...mapActions(['postMessage']),

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
                // Determine upload folder:
                // Prefer team shared folder → Attachments subfolder
                // Fall back to personal /TeamHub Attachments
                const teamFilesPath = this.$store.state.resources?.files?.path || null
                let uploadFolder

                if (teamFilesPath) {
                    // teamFilesPath is the file_target from share table, e.g. "/Team Name"
                    uploadFolder = teamFilesPath.replace(/\/$/, '') + '/Attachments'
                } else {
                    uploadFolder = '/' + ATTACH_FOLDER
                }

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

                await axios.put(fileDavUrl, file, {
                    headers: { 'Content-Type': file.type || 'application/octet-stream' },
                })

                // 3. Share the file with the circle (internal share, not public link)
                //    This lets all team members access it
                const ncFilePath = `${uploadFolder}/${fileName}`
                const circleId = this.$store.state.currentTeamId
                let shareUrl = null

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
                    } catch (shareErr) {
                        // Share with circle failed — file is uploaded but not shared
                        // Just link to the file in the poster's Files
                    }
                }

                // 4. Build the file URL — link to NC Files viewer for the uploaded file
                // Build the best available URL to open the file directly:
                // 1. shareUrl (circle internal share) — opens the shared file view
                // 2. WebDAV download URL — direct download (works for any file)
                // We avoid /apps/files/?openfile= because that needs a numeric fileId
                const uid2 = getCurrentUser()?.uid
                const davDownloadUrl = generateRemoteUrl(`dav/files/${uid2}${uploadFolder}/${fileName}`)
                const fileViewUrl = shareUrl || davDownloadUrl

                // 5. Append markdown link
                const linkText = `[📎 ${fileName}](${fileViewUrl})`
                this.body = this.body + (this.body && !this.body.endsWith('\n') ? '\n' : '') + linkText

                writeAttachment({ uploading: false, filePath: ncFilePath })

            } catch (e) {
                const msg = e.response?.data?.ocs?.meta?.message || e.response?.statusText || e.message || 'Upload failed'
                writeAttachment({ uploading: false, error: msg })
                showError(t('teamhub', 'Failed to upload {name}: {error}', { name: file.name, error: msg }))
            }
        },

        removeAttachment(i) {
            const att = this.attachments[i]
            if (att.name) {
                const linkText = `[📎 ${att.name}]`
                const lineToRemove = new RegExp(`\\n?\\[📎 ${att.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\]\\([^)]+\\)`, 'g')
                this.body = this.body.replace(lineToRemove, '')
            }
            this.attachments.splice(i, 1)
        },

        // ── Poll ────────────────────────────────────────────────────────────
        addPollOption() { this.pollOptions.push({ id: this.pollOptionSeq++, text: '' }) },
        removePollOption(index) { this.pollOptions.splice(index, 1) },

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
                }
                if (this.messageType === 'poll') {
                    messageData.pollOptions = this.pollOptions.map(o => o.text.trim()).filter(Boolean)
                }

                await this.postMessage(messageData)

                showSuccess(
                    this.messageType === 'poll'    ? t('teamhub', 'Poll created!') :
                    this.messageType === 'question' ? t('teamhub', 'Question posted!') :
                                                      t('teamhub', 'Message posted!')
                )
                this.$emit('submitted')

                // Reset
                this.subject = ''
                this.body = ''
                this.messageType = 'normal'
                this.pollOptions = [{ id: this.pollOptionSeq++, text: '' }, { id: this.pollOptionSeq++, text: '' }]
                this.attachments = []
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
</style>
