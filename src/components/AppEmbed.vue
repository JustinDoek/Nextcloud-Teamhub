<template>
    <div class="app-embed">
        <div class="app-embed__bar">
            <span class="app-embed__label">{{ label }}</span>
            <div class="app-embed__bar-actions">
                <NcButton
                    type="tertiary"
                    :aria-label="t('teamhub', 'Reload')"
                    :title="t('teamhub', 'Reload')"
                    :disabled="!iframeSrc"
                    @click="reload">
                    <template #icon><Refresh :size="16" /></template>
                </NcButton>
                <NcButton
                    v-if="url"
                    type="tertiary"
                    tag="a"
                    :href="url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :aria-label="t('teamhub', 'Open in new tab')">
                    <template #icon><OpenInNew :size="16" /></template>
                    {{ t('teamhub', 'Open in new tab') }}
                </NcButton>
            </div>
        </div>

        <div class="app-embed__viewport">
            <!--
                Error state: shown when the URL was rejected by validation
                (TeamView's menuItemUrl returns '' for non-https / non-NC paths).
                We surface this clearly rather than spinning forever.
            -->
            <div v-if="!iframeSrc" class="app-embed__error">
                <AlertCircleOutline :size="32" />
                <strong>{{ t('teamhub', 'Cannot load this view') }}</strong>
                <span>{{ t('teamhub', 'The integration URL was rejected by TeamHub. Only https:// and Nextcloud-relative URLs are allowed.') }}</span>
            </div>

            <!--
                Loading skeleton: shown until the iframe fires its load event.
                Sits behind the iframe (z-index) so it's hidden the instant the
                frame paints.
            -->
            <div v-else-if="loading" class="app-embed__loading">
                <NcLoadingIcon :size="32" />
                <span>{{ t('teamhub', 'Loading…') }}</span>
            </div>

            <!--
                Iframe attributes:
                  - referrerpolicy: never leak the team-scoped TeamHub URL to
                    third-party origins.
                  - allow="": deny all powerful features (camera, mic, geo,
                    USB, payment, etc.) by default.
                  - sandbox: applied ONLY for cross-origin iframes. For
                    same-origin (built-in NC apps Talk/Files/Calendar/Deck)
                    we deliberately omit sandbox because we still need DOM
                    access to inject the chrome-stripping CSS — and there's
                    no security gain since same-origin code can already
                    access the page anyway.
                  - loading="lazy" intentionally NOT used: it defers the
                    load event, which breaks our injection timing.
            -->
            <iframe
                v-if="iframeSrc"
                ref="frame"
                :key="reloadKey"
                :src="iframeSrc"
                :title="label"
                :sandbox="effectiveSandbox"
                allow=""
                referrerpolicy="strict-origin-when-cross-origin"
                class="app-embed__frame"
                allowfullscreen
                @load="onLoad" />
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

/**
 * Sandbox token set applied to cross-origin iframes.
 *
 * What we INCLUDE (and why):
 *   allow-scripts                   — third-party app needs JS to function
 *   allow-forms                     — login screens, search, settings forms
 *   allow-popups                    — "open in new tab" links inside the app
 *   allow-popups-to-escape-sandbox  — popups should land as normal tabs, not
 *                                     inherit our hard-locked sandbox
 *
 * What we DELIBERATELY EXCLUDE (and why):
 *   allow-same-origin    — denied. Treat the embedded site as a hostile origin
 *                          even if it claims our domain. Blocks document.cookie
 *                          / localStorage abuse from malformed registrations.
 *   allow-top-navigation — denied. The embedded site cannot redirect the
 *                          parent TeamHub window. Phishing protection.
 *   allow-modals         — denied. No alert/confirm/prompt dialogs.
 *   allow-pointer-lock   — denied. No silent mouse capture.
 *   allow-presentation   — denied. No casting / second-screen.
 *   allow-downloads      — denied. Forces the user to "Open in new tab" for
 *                          downloads, which is the safer audit trail.
 *   allow-orientation-lock, allow-storage-access-by-user-activation — denied.
 *
 * Intentionally NOT applied to same-origin built-in iframes (Talk/Files/etc).
 * Same-origin NC apps are already trusted code, and we need DOM access for
 * the chrome-stripping CSS injection. A sandbox there would either break NC
 * (no allow-same-origin) or be theatre (with allow-same-origin it adds nothing).
 */
const CROSS_ORIGIN_SANDBOX = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox'

// CSS injected into the iframe to strip NC navigation chrome.
// Written as a function so it's applied fresh each time.
function buildCss() {
    return `
/* ══════════════════════════════════════════════════════════════════
   TeamHub iframe chrome-stripping CSS — NC 32+

   NC 32 renamed several key DOM IDs (old → new):
     #app-navigation  → #app-navigation-vue
     #app-sidebar     → #app-sidebar-vue  (NOTE: NOT hidden — apps use this
                        for share panels, file details, calendar popovers etc.)
     #content         → #content-vue
     .app-menu-main   → #app-menu-container

   All old + new selectors listed so this works across NC versions.
   Also hides the "Custom Menu" third-party app (side_menu).
   ══════════════════════════════════════════════════════════════════ */

/* ── Top header bar ── */
#header,
header[role="banner"],
.header-start,
.header-end,
.header-left,
.header-right,
.header-center,
.header-menu,
.unified-search,
.notifications-button,
.user-status-menu-item,
[data-cy-top-menu] {
    display: none !important;
}

/* ── App navigation / left sidebar — old NC + NC 32 ── */
#navigation,
#app-navigation,
#app-navigation-vue,
nav.app-navigation,
.app-navigation,
#app-navigation-toggle,
.app-navigation-toggle,
.app-navigation-toggle-wrapper {
    display: none !important;
}

/* ── App menu (top icon row) — old NC + NC 32 ── */
#appmenu,
#app-menu-container,
.app-menu,
.app-menu-main {
    display: none !important;
}

/* ── Right sidebar: intentionally NOT hidden globally.
   NC apps use #app-sidebar-vue for share dialogs, file details,
   calendar event editors, Deck card details, etc.
   Hiding it would break those features inside the iframe.
   It starts hidden because the app doesn't open it on load — no rule needed. ── */

/* ── Profiler toolbar (NC dev mode) ── */
#profiler-toolbar {
    display: none !important;
}

/* ── Third-party "Custom Menu" app (side_menu) ── */
#side-menu-container,
.cm--topwidemenu,
.cm--sidemenu,
.cm-standardmenu,
[id^="side-menu"] {
    display: none !important;
}

/* ── Layout: zero out NC 32 CSS variable offsets ── */
:root {
    --body-container-margin: 0px !important;
    --body-container-radius: 0px !important;
}

html, body {
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    background: var(--color-main-background) !important;
    /* overflow: hidden on body would clip internal app scroll areas.
       Instead we rely on the iframe's own overflow:hidden viewport. */
}

/* Content wrapper fills the full iframe — old NC uses #content, NC 32 uses #content-vue.
   Use height/width rather than position:fixed so app-internal modals and
   popovers can still escape the content box via their own z-index stacking. */
#content,
#content-vue,
.nc-content {
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    height: 100% !important;
    border-radius: 0 !important;
    box-sizing: border-box !important;
}

#app-content,
#app-content-vue,
#app-content-wrapper,
.app-content {
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    width: 100% !important;
    max-width: 100% !important;
    border-radius: 0 !important;
    box-sizing: border-box !important;
}

/* ── Talk: full-width conversation view ── */
#app-navigation.app-navigation--talk,
#app-navigation-vue.app-navigation--talk,
.conversations-list-wrapper,
.new-conversation-button-wrapper {
    display: none !important;
}
.call-view,
.conversation-view {
    margin-left: 0 !important;
    width: 100% !important;
}

/* ── Files: left nav hidden, content fills width ── */
#app-navigation-files,
#app-navigation-vue .files-navigation {
    display: none !important;
}
.files-controls { margin-bottom: 4px !important; }
#app-content-files { margin-left: 0 !important; padding: 8px !important; }

/* ── Calendar: full-width view ── */
.app-navigation--calendar,
#app-navigation-vue.app-navigation--calendar {
    display: none !important;
}
.calendar-view-wrapper,
.app-calendar {
    margin-left: 0 !important;
    width: 100% !important;
}

/* ── Deck: hide sidebar, full-width board ── */
.app-navigation--deck,
#app-navigation-vue.app-navigation--deck {
    display: none !important;
}
.board-wrapper,
.deck-board {
    margin-left: 0 !important;
    width: 100% !important;
    padding: 8px !important;
}
`
}

export default {
    name: 'AppEmbed',
    components: { NcButton, NcLoadingIcon, OpenInNew, Refresh, AlertCircleOutline },

    props: {
        url:   { type: String, required: true },
        label: { type: String, required: true },
    },

    data() {
        return {
            // Use a stable src — only change when url prop actually changes.
            // Empty string is allowed and means "show loading skeleton, no
            // navigation" (used when TeamView's URL re-validation rejects
            // a malformed iframe_url).
            iframeSrc: this.url,
            loading: true,
            // Bumping reloadKey forces Vue to destroy + recreate the iframe.
            // Just changing src would let some apps cache state.
            reloadKey: 0,
            _observer: null,
            _retryTimers: [],
        }
    },

    computed: {
        /**
         * True when the iframe URL points to a different origin than the
         * page hosting TeamHub. Cross-origin frames cannot be DOM-accessed,
         * so we sandbox them harder and skip CSS injection retries.
         */
        isCrossOrigin() {
            if (!this.iframeSrc) return false
            try {
                // Relative URLs (e.g. /apps/files/...) resolve against the
                // current location and are by definition same-origin.
                const u = new URL(this.iframeSrc, window.location.href)
                return u.origin !== window.location.origin
            } catch (e) {
                // Malformed URL — treat as cross-origin out of caution.
                return true
            }
        },

        /**
         * Sandbox attribute string. `null` means "render the iframe without
         * a sandbox attribute at all" (Vue omits null/undefined attrs).
         * Returning empty string '' would be the strictest possible sandbox
         * — we explicitly do NOT want that for built-ins.
         */
        effectiveSandbox() {
            return this.isCrossOrigin ? CROSS_ORIGIN_SANDBOX : null
        },
    },

    watch: {
        url(newUrl) {
            // Only fires when the URL prop genuinely changes (e.g. team switch
            // or a dynamic resource update). Tab switches no longer reach here
            // because the component stays in the DOM via v-show — the url prop
            // is unchanged so this watcher is silent.
            this.iframeSrc = newUrl
            this.loading = true
            this.stopObserver()
            this.clearRetryTimers()
        },
    },

    beforeDestroy() {
        this.stopObserver()
        this.clearRetryTimers()
    },

    methods: {
        t,

        /**
         * Manual reload triggered by the toolbar refresh button.
         * Bumps reloadKey so Vue tears down the iframe element entirely
         * before recreating it — equivalent to a hard reload, no cache reuse.
         */
        reload() {
            this.loading = true
            this.stopObserver()
            this.clearRetryTimers()
            this.reloadKey++
        },

        onLoad() {
            this.loading = false
            this.stopObserver()
            this.clearRetryTimers()

            // Cross-origin frames: we cannot read or modify the document.
            // Skip the injection + retry loop entirely. The user will see
            // the embedded app with whatever chrome it ships with — that's
            // the price of sandboxing. They can still use "Open in new tab".
            if (this.isCrossOrigin) {
                return
            }

            this.injectCss()

            // Same-origin NC apps render asynchronously via Vue — re-inject
            // after delays to catch dynamically mounted navigation elements.
            const delays = [300, 800, 1500, 3000]
            delays.forEach(ms => {
                const timer = setTimeout(() => this.injectCss(), ms)
                this._retryTimers.push(timer)
            })

            // Also watch for DOM mutations inside the iframe (app-nav mounting).
            this.startObserver()
        },

        injectCss() {
            try {
                const frame = this.$refs.frame
                if (!frame) return
                const doc = frame.contentDocument || frame.contentWindow?.document
                if (!doc || !doc.head) return

                // If our style tag is already present, don't remove+re-add it.
                // Re-inserting it would trigger the MutationObserver again,
                // creating an infinite loop. Only inject if it's missing.
                if (doc.getElementById('teamhub-embed-style')) return

                const style = doc.createElement('style')
                style.id = 'teamhub-embed-style'
                style.textContent = buildCss()
                doc.head.appendChild(style)
            } catch (e) {
                // Same-origin failure path. The cross-origin path is guarded
                // out in onLoad() so we never reach here for sandboxed frames.
            }
        },

        startObserver() {
            try {
                const frame = this.$refs.frame
                if (!frame) return
                const doc = frame.contentDocument || frame.contentWindow?.document
                if (!doc || !doc.body) return

                this._observer = new (frame.contentWindow.MutationObserver || MutationObserver)(
                    () => this.injectCss()
                )
                this._observer.observe(doc.body, {
                    childList: true,
                    subtree: true,
                    attributes: false,
                })
            } catch (e) {
                // Cross-origin path doesn't reach here — this catch covers
                // weird states (frame torn down mid-call, etc.)
            }
        },

        stopObserver() {
            if (this._observer) {
                try { this._observer.disconnect() } catch (e) {}
                this._observer = null
            }
        },

        clearRetryTimers() {
            if (Array.isArray(this._retryTimers)) {
                this._retryTimers.forEach(t => clearTimeout(t))
            }
            this._retryTimers = []
        },
    },
}
</script>

<style scoped>
.app-embed {
    display: flex;
    flex-direction: column;
    /* No min-height here — let the flex parent control our size.
       min-height: 100vh would push us outside the available area. */
    height: 100%;
    overflow: hidden;
}

.app-embed__bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 12px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.app-embed__bar-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

.app-embed__label {
    font-weight: 600;
    font-size: 14px;
}

.app-embed__viewport {
    position: relative;
    flex: 1 1 auto;
    min-height: 0;  /* essential in flex columns — prevents blowing past parent */
    width: 100%;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
    background: var(--color-background-plain);
}

.app-embed__frame {
    /* Absolutely fill the viewport container exactly.
       This means the iframe is strictly bounded by its parent —
       the NC app inside may scroll, but the frame itself cannot overflow. */
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    background: transparent;
    display: block;
    z-index: 1;
}

.app-embed__loading {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    background: var(--color-background-plain);
    z-index: 2;
}

.app-embed__error {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 24px;
    text-align: center;
    color: var(--color-error-text);
    font-size: 13px;
    background: var(--color-background-plain);
    z-index: 2;
}

.app-embed__error span {
    color: var(--color-text-maxcontrast);
    max-width: 480px;
}
</style>
