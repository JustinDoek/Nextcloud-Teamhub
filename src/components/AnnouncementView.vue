<template>
    <div class="announcement-view">
        <div class="announcement-view__toolbar">
            <h2 class="announcement-view__title">
                {{ t('teamhub', 'Message from TeamHub') }}
            </h2>
            <div class="announcement-view__toolbar-actions">
                <NcButton
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Close')"
                    @click="$emit('close')">
                    {{ t('teamhub', 'Close') }}
                </NcButton>
                <NcButton
                    v-if="body !== null && !error"
                    variant="primary"
                    :disabled="dismissing"
                    @click="dismiss">
                    <!-- TRANSLATORS: button that marks the current in-app
                         announcement as read so the sidebar envelope stops
                         surfacing it for this user. -->
                    {{ t('teamhub', 'Got it, don’t show again') }}
                </NcButton>
            </div>
        </div>

        <div v-if="loading" class="announcement-view__loading">
            {{ t('teamhub', 'Loading message…') }}
        </div>

        <NcEmptyContent
            v-else-if="error"
            :name="t('teamhub', 'Message unavailable')"
            :description="errorDescription">
            <template #icon><EmailOutline :size="48" /></template>
        </NcEmptyContent>

        <article
            v-else
            class="announcement-view__body"
            v-html="rendered" />
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

// v4.4.17 — Canvas view for a single in-app announcement. Fetches the
// markdown body from the server (which enforces license/version/role/
// dismissal filtering), renders it through `marked` for markdown parsing
// and `dompurify` for HTML sanitisation. Both steps are required — marked
// itself does not sanitise, and rendering unsanitised HTML from any
// source (even the app's own announcements/ folder) is exactly the kind
// of pattern SKILLS.md § Security asks us to keep out.

// Configure marked once: enable GitHub-flavoured shorthand + hard line breaks
// so authors can format announcements the way they would a release note.
marked.setOptions({ gfm: true, breaks: true })

// Sanitiser config: allow only the tag set an announcement realistically
// needs. Deliberately excludes <iframe>, <object>, <script>, <style>,
// event handlers, and any URL scheme other than http(s) and mailto.
const SANITIZE_CONFIG = {
    ALLOWED_TAGS: [
        'a', 'p', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'code', 'pre',
        'ul', 'ol', 'li',
        'blockquote',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ],
    ALLOWED_ATTR: ['href', 'title', 'target', 'rel'],
    ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i,
}

export default {
    name: 'AnnouncementView',
    components: { NcButton, NcEmptyContent, EmailOutline },
    props: {
        filename: {
            type: String,
            required: true,
        },
    },
    emits: ['close', 'dismissed'],
    data() {
        return {
            body: null,
            loading: true,
            error: false,
            errorDescription: '',
            dismissing: false,
        }
    },
    computed: {
        rendered() {
            if (this.body === null) return ''
            const html = marked.parse(this.body)
            return DOMPurify.sanitize(html, SANITIZE_CONFIG)
        },
    },
    watch: {
        filename: {
            immediate: true,
            handler() {
                this.load()
            },
        },
    },
    methods: {
        t,
        async load() {
            this.loading = true
            this.error = false
            this.body = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/announcements/body'),
                    { params: { filename: this.filename } },
                )
                this.body = String(data?.body ?? '')
            } catch (e) {
                this.error = true
                this.errorDescription = e?.response?.status === 404
                    ? t('teamhub', 'This message is no longer available.')
                    : t('teamhub', 'Could not load the message. Try again in a moment.')
            } finally {
                this.loading = false
            }
        },
        async dismiss() {
            this.dismissing = true
            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/announcements/dismiss'),
                    { filename: this.filename },
                )
                this.$emit('dismissed')
            } catch (e) {
                // Leave the view open on failure so the user can retry —
                // silently returning to the team list would look like a
                // successful dismiss when it wasn't.
                this.dismissing = false
            }
        },
    },
}
</script>

<style scoped>
.announcement-view {
    padding: 16px 24px 24px;
    max-width: 780px;
    margin: 0 auto;
}

.announcement-view__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 12px;
    margin-bottom: 16px;
    border-bottom: 1px solid var(--color-border);
}

.announcement-view__title {
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-tight);
    margin: 0;
}

.announcement-view__toolbar-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.announcement-view__loading {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
    padding: 24px 0;
    text-align: center;
}

/* Non-scoped-friendly rendered-markdown styles. Because v-html injects
   sanitised HTML, we cannot rely on scoped selectors here — but scoped
   CSS still targets descendants of the scoped root as long as we use
   plain tag selectors. */
.announcement-view__body {
    font-size: var(--th-font-body);
    line-height: var(--th-line-height-relaxed);
    color: var(--color-main-text);
}
.announcement-view__body :deep(h1),
.announcement-view__body :deep(h2),
.announcement-view__body :deep(h3) {
    margin-top: 24px;
    margin-bottom: 8px;
    font-weight: var(--th-font-weight-semibold);
    line-height: var(--th-line-height-tight);
}
.announcement-view__body :deep(h1) { font-size: var(--th-font-heading-lg); }
.announcement-view__body :deep(h2) { font-size: var(--th-font-heading); }
.announcement-view__body :deep(h3) { font-size: var(--th-font-body); }
.announcement-view__body :deep(p) { margin: 0 0 12px; }
.announcement-view__body :deep(ul),
.announcement-view__body :deep(ol) {
    margin: 0 0 12px;
    padding-left: 24px;
}
.announcement-view__body :deep(li) { margin-bottom: 4px; }
.announcement-view__body :deep(a) {
    color: var(--color-primary-element);
    text-decoration: underline;
}
.announcement-view__body :deep(a:focus-visible) {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: 2px;
}
.announcement-view__body :deep(code) {
    background: var(--color-background-hover);
    padding: 1px 4px;
    border-radius: var(--th-radius-control);
    font-size: var(--th-font-meta);
}
.announcement-view__body :deep(pre) {
    background: var(--color-background-hover);
    padding: 12px;
    border-radius: var(--th-radius-card);
    overflow-x: auto;
}
.announcement-view__body :deep(blockquote) {
    border-left: 3px solid var(--color-border);
    padding-left: 12px;
    margin: 0 0 12px;
    color: var(--color-text-maxcontrast);
}
</style>
