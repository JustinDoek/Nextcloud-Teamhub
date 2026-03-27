<template>
    <div class="app-embed">
        <div class="app-embed__bar">
            <span class="app-embed__label">{{ label }}</span>
            <NcButton
                type="tertiary"
                tag="a"
                :href="url"
                target="_blank"
                :aria-label="t('teamhub', 'Open in new tab')">
                <template #icon><OpenInNew :size="16" /></template>
                {{ t('teamhub', 'Open in new tab') }}
            </NcButton>
        </div>
        <iframe
            ref="frame"
            :src="iframeSrc"
            class="app-embed__frame"
            allowfullscreen
            @load="onLoad" />
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

// CSS injected into the iframe to strip NC navigation chrome.
// Written as a function so it's applied fresh each time.
function buildCss() {
    return `
/* ── Hide all Nextcloud navigation chrome ── */
#header,
#navigation,
#app-navigation,
nav.app-navigation,
.app-navigation,
#appmenu,
.app-menu,
.app-menu-main,
.header-start,
.header-end,
.header-menu,
.header-right,
.header-left,
.header-center,
.unified-search,
.notifications-button,
.user-status-menu-item,
[data-cy-top-menu],
#app-navigation-toggle,
.app-navigation-toggle,
#app-sidebar,
.app-sidebar,
NcAppSidebar {
    display: none !important;
}

/* ── Full-bleed layout ── */
html, body {
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    overflow: hidden !important;
}

#content,
#content-vue,
.nc-content,
[id^="content"] {
    position: fixed !important;
    inset: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    height: 100% !important;
    border-radius: 0 !important;
}

#app-content,
#app-content-vue,
#app-content-wrapper,
.app-content,
.app-content-list {
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    width: 100% !important;
    border-radius: 0 !important;
}

/* ── Talk: full-width conversation, no contact list ── */
#app-navigation.app-navigation--talk,
.conversations-list-wrapper,
.new-conversation-button-wrapper {
    display: none !important;
}
.call-view, .conversation-view {
    margin-left: 0 !important;
    width: 100% !important;
}

/* ── Files: remove left nav, toolbar spacing ── */
#app-navigation-files { display: none !important; }
.files-controls { margin-bottom: 4px !important; }
#app-content-files { margin-left: 0 !important; padding: 8px !important; }

/* ── Calendar: full-width view ── */
.app-navigation--calendar { display: none !important; }
.calendar-view-wrapper, .app-calendar { margin-left: 0 !important; width: 100% !important; }

/* ── Deck: hide sidebar, full-width board ── */
.app-navigation--deck { display: none !important; }
.board-wrapper, .deck-board { margin-left: 0 !important; width: 100% !important; padding: 8px !important; }
`
}

export default {
    name: 'AppEmbed',
    components: { NcButton, OpenInNew },

    props: {
        url:   { type: String, required: true },
        label: { type: String, required: true },
    },

    data() {
        return {
            // Use a stable src — only change when url prop actually changes
            iframeSrc: this.url,
            _observer: null,
            _retryTimer: null,
        }
    },

    watch: {
        url(newUrl) {
            // When URL changes (tab switch), reload the iframe
            this.iframeSrc = newUrl
            this.stopObserver()
        },
    },

    beforeDestroy() {
        this.stopObserver()
    },

    methods: {
        t,

        onLoad() {
            this.stopObserver()
            this.injectCss()

            // NC apps render asynchronously via Vue — re-inject after delays
            // to catch dynamically mounted navigation elements
            const delays = [300, 800, 1500, 3000]
            delays.forEach(ms => {
                setTimeout(() => this.injectCss(), ms)
            })

            // Also watch for DOM mutations inside the iframe (app-nav mounting)
            this.startObserver()
        },

        injectCss() {
            try {
                const frame = this.$refs.frame
                if (!frame) return
                const doc = frame.contentDocument || frame.contentWindow?.document
                if (!doc || !doc.head) return

                // Remove old injected style if present
                const old = doc.getElementById('teamhub-embed-style')
                if (old) old.remove()

                const style = doc.createElement('style')
                style.id = 'teamhub-embed-style'
                style.textContent = buildCss()
                doc.head.appendChild(style)
            } catch (e) {
                // Cross-origin — nothing we can do; iframe shows with full chrome
                // This only happens on misconfigured NC setups where the app
                // is served from a different origin than the embed host
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
                // Cross-origin — ignore
            }
        },

        stopObserver() {
            if (this._observer) {
                try { this._observer.disconnect() } catch (e) {}
                this._observer = null
            }
            if (this._retryTimer) {
                clearTimeout(this._retryTimer)
                this._retryTimer = null
            }
        },
    },
}
</script>

<style scoped>
.app-embed {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: calc(100vh - 180px);
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

.app-embed__label {
    font-weight: 600;
    font-size: 14px;
}

.app-embed__frame {
    width: 100%;
    height: 85%;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);
    background: var(--color-background-plain);
    display: block;
}
</style>
