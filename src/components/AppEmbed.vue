<template>
    <div class="app-embed">
        <div
            v-if="showBar"
            class="app-embed__bar"
            :class="{ 'app-embed__bar--collapsed': barCollapsed }">
            <span class="app-embed__label">{{ label }}</span>
            <!--
                v4.5.17 — collapse the toolbar to a thin strip. The embedded app
                has its own toolbars, so ours competes for vertical space in a
                frame that is already short. The toggle sits outside
                .app-embed__bar-actions on purpose: that whole group is what
                collapses, and the control that brings it back must not go with
                it. Raw <button> per SKILLS.md § "NcButton is the default" —
                NcButton's 44px touch target defeats the point of a thin strip.
            -->
            <button
                type="button"
                class="app-embed__bar-collapse"
                :aria-expanded="barCollapsed ? 'false' : 'true'"
                :aria-label="barCollapsed ? t('teamhub', 'Show toolbar') : t('teamhub', 'Hide toolbar')"
                :title="barCollapsed ? t('teamhub', 'Show toolbar') : t('teamhub', 'Hide toolbar')"
                @click="toggleBar">
                <ChevronDown v-if="barCollapsed" :size="16" />
                <ChevronUp v-else :size="16" />
            </button>
            <div v-show="!barCollapsed" class="app-embed__bar-actions">
                <!-- Inline select dropdowns (e.g. calendar view switcher) -->
                <select
                    v-for="sel in embedSelects"
                    :key="sel.id"
                    class="app-embed__bar-select"
                    :value="sel.value"
                    :aria-label="sel.label"
                    :title="sel.label"
                    @change="$emit('select', { id: sel.id, value: $event.target.value })">
                    <option
                        v-for="opt in sel.options"
                        :key="opt.value"
                        :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>

                <!-- Custom action buttons injected by the parent (e.g. calendar
                     add/delete). Stacked icon-above-label layout — see DESIGN.md
                     §2.23: small icon-only buttons in this bar were reported as
                     indistinguishable, so every button now shows its label
                     directly below the icon. -->
                <template v-for="action in embedActions" :key="action.id">
                    <!-- Date label between prev/next — plain text, not a button -->
                    <span v-if="action.isLabel" class="app-embed__bar-date-label">
                        {{ action.label }}
                    </span>
                    <button
                        v-else
                        type="button"
                        class="app-embed__bar-btn"
                        :aria-label="action.label"
                        :title="action.label"
                        @click="$emit('action', action.id)">
                        <component :is="action.icon" :size="20" class="app-embed__bar-btn-icon" />
                        <!-- shortLabel is a one-or-two-word verb shown under the icon
                             (e.g. "Add"); label is the full descriptive name kept for
                             tooltip + screen reader (e.g. "Add event"). Falls back to
                             label when shortLabel isn't set. -->
                        <span class="app-embed__bar-btn-label">{{ action.shortLabel || action.label }}</span>
                    </button>
                </template>

                <!-- Toggle buttons: pressed/active state, e.g. timeline source filters -->
                <button
                    v-for="tog in embedToggles"
                    :key="tog.id"
                    type="button"
                    class="app-embed__bar-btn"
                    :class="{ 'app-embed__bar-btn--active': tog.active }"
                    :aria-pressed="tog.active ? 'true' : 'false'"
                    :aria-label="tog.label"
                    :title="tog.label"
                    @click="$emit('toggle', tog.id)">
                    <component :is="tog.icon" :size="20" class="app-embed__bar-btn-icon" />
                    <span class="app-embed__bar-btn-label">{{ tog.shortLabel || tog.label }}</span>
                </button>

                <!--
                    Filter menu: a single dropdown button that opens a popover
                    with checkbox items. Used where there are too many filter
                    options for inline toggle buttons to remain readable (e.g.
                    Timeline's four sources). Emits 'menu-toggle' with the
                    item id whenever the user toggles a checkbox.
                -->
                <NcActions
                    v-if="embedMenu"
                    :menu-name="embedMenu.label"
                    :menu-title="embedMenu.label"
                    type="tertiary"
                    :force-menu="true">
                    <template #icon>
                        <component :is="embedMenu.icon" :size="16" />
                    </template>
                    <template v-for="item in embedMenu.items" :key="item.id">
                        <!-- Captions render as section headers in the dropdown.
                             Useful for grouping sub-filters (e.g. "Deck events")
                             under their parent source. -->
                        <NcActionCaption
                            v-if="item.isCaption"
                            :name="item.label" />
                        <NcActionCheckbox
                            v-else
                            :model-value="item.active"
                            :disabled="item.disabled === true"
                            @update:model-value="$emit('menu-toggle', item.id)">
                            {{ item.label }}
                        </NcActionCheckbox>
                    </template>
                </NcActions>
                <button
                    type="button"
                    class="app-embed__bar-btn"
                    :aria-label="t('teamhub', 'Reload')"
                    :title="t('teamhub', 'Reload')"
                    :disabled="!hosted && !iframeSrc"
                    @click="reload">
                    <Refresh :size="20" class="app-embed__bar-btn-icon" />
                    <span class="app-embed__bar-btn-label">{{ t('teamhub', 'Reload') }}</span>
                </button>
                <a
                    v-if="url"
                    class="app-embed__bar-btn"
                    :href="url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :title="t('teamhub', 'Open in new tab')">
                    <OpenInNew :size="20" class="app-embed__bar-btn-icon" />
                    <!-- Kept as the full phrase rather than abbreviated to a single
                         verb: 'Open' alone collides with the existing decision-status
                         "Open" translation in nl/de/fr/da (which is the adjective
                         "Offen"/"Ouvert"/"Åben"). Disambiguating costs more than the
                         label width buys. -->
                    <span class="app-embed__bar-btn-label">{{ t('teamhub', 'Open in new tab') }}</span>
                </a>
            </div>
        </div>

        <div class="app-embed__viewport" :class="{ 'app-embed__viewport--full': !showBar }">
            <!--
                v4.6.20 — hosted mode. A default slot replaces the iframe, so a
                view TeamHub renders itself gets this component's toolbar
                (§2.23's stacked icon+label buttons, the wrapping behaviour, the
                action/select plumbing) without a second copy of that markup
                living in the child. The team calendar grid is the first user.

                Nothing else changes shape: with no `url` prop the "Open in new
                tab" anchor is already `v-if="url"` and simply does not render,
                which is exactly the behaviour a self-hosted view wants.
            -->
            <slot v-if="hosted" />
            <!--
                Error state: shown when the URL was rejected by validation
                (TeamView's menuItemUrl returns '' for non-https / non-NC paths).
                We surface this clearly rather than spinning forever.
            -->
            <div v-else-if="!iframeSrc" class="app-embed__error">
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
import { NcLoadingIcon, NcActions, NcActionCheckbox, NcActionCaption } from '@nextcloud/vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import { attachCollabSidebar, RENDERED_FILE_SELECTOR } from '../lib/filesCollab.js'
import logger from '../logger.js'
import { attachInternalLinkInterceptor } from '../lib/internalLinks.js'

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

/**
 * How long to wait for the embedded app to react to an in-place navigation
 * before giving up and doing a real one. Long enough for a router transition,
 * short enough that a failure is not a visible stall.
 */
const IN_PLACE_VERIFY_MS = 700

// How often the location poller reads the embedded app's address when
// `trackLocation` is on. Slow enough to be free, fast enough that a page
// rail highlight does not visibly lag the page it is pointing at.
const LOCATION_POLL_MS = 600

/**
 * Whether the embed toolbar is collapsed, remembered across embeds and sessions.
 *
 * Deliberately localStorage rather than the per-team layout: this is a standing
 * preference about screen space, the same on every tab and every team, and it
 * must survive a reload without waiting for a round-trip.
 */
const BAR_COLLAPSED_KEY = 'teamhub:embed-bar-collapsed'

function readBarCollapsed() {
    try {
        return window.localStorage?.getItem(BAR_COLLAPSED_KEY) === '1'
    } catch (e) {
        return false        // storage blocked (private mode, cookie policy)
    }
}

function writeBarCollapsed(collapsed) {
    try {
        window.localStorage?.setItem(BAR_COLLAPSED_KEY, collapsed ? '1' : '0')
    } catch (e) {
        // Non-fatal: the toggle still works for this session.
    }
}

/**
 * Opening a file means loading the Files app, letting it list the folder, and
 * only then opening the document on top. The middle step is not something the
 * user asked to see — it flashed the whole file browser, navigation and all,
 * before the file appeared.
 *
 * So for a file-open navigation we hold our own loading state until the viewer
 * is actually on screen. Bounded, because not every file has one: on timeout we
 * reveal whatever is there rather than spin forever.
 */
const FILE_RENDER_POLL_MS = 150
const FILE_RENDER_TIMEOUT_MS = 8000

/**
 * Does this URL ask Nextcloud to open a file, rather than just show a folder?
 *
 * @param {string} url
 * @return {boolean}
 */
function opensAFile(url) {
    const s = String(url || '')
    return /\/f\/\d+/.test(s) || /[?&]openfile=(?!false)/i.test(s)
}

/**
 * The Nextcloud app id a path belongs to, e.g. '/nc/index.php/apps/deck/board/1'
 * -> 'deck'. Used to tell 'route this app somewhere else' (cheap) from 'load a
 * different app' (a full page load).
 *
 * @param {string} pathname
 * @return {string|null}
 */
function appIdOf(pathname) {
    const m = String(pathname || '').match(/\/apps\/([^/]+)/)
    return m ? m[1] : null
}

/**
 * The routing context a URL belongs to — the scope inside which an embedded
 * app's router can move without being rebooted.
 *
 * Coarser than "same app". Nextcloud Calendar decides at boot whether it is a
 * user view or a public view and initialises its DAV client accordingly
 * (`initializeClientForUserView` vs `initializeClientForPublicView`), and a
 * public view is bound to one token. Routing between those in place changes the
 * URL while the app keeps showing the calendar it booted with — which is exactly
 * how "switching agenda only ever shows the first one" was introduced in
 * v4.5.12. Those transitions must be real navigations.
 *
 * @param {string} pathname
 * @return {string|null} null when nothing may be done in place
 */
function routingContextOf(pathname) {
    const app = appIdOf(pathname)
    if (!app) {
        return null
    }
    // /apps/calendar/p/{token}/… and /apps/calendar/embed/{token}/… are each
    // their own boot context, distinct from each other and from the user view.
    const scoped = String(pathname).match(/\/apps\/[^/]+\/(p|embed)\/([^/]+)/)
    return scoped ? `${app}:${scoped[1]}:${scoped[2]}` : app
}

/**
 * Class added to <html> inside a same-origin embed (v4.7.6).
 *
 * This is a public styling contract for instance administrators, not an
 * internal detail: it is the selector their Theming custom CSS keys on to
 * change an app's appearance only where TeamHub embeds it. Renaming it
 * silently breaks every instance that uses it — treat it as an API.
 *
 * Deliberately bare rather than prefixed. NC's own layout puts a single class
 * on <html> (`ng-csp`) and nothing else claims that element, so there is
 * nothing to collide with and a longer name would only lengthen every admin
 * rule that uses it.
 *
 * KEEP IN SYNC with `templates/timeline.php`, which writes the same class into
 * its own <html> tag. That page is served by TeamHub rather than embedded from
 * another app, so it cannot read this constant.
 */
const EMBED_MARKER_CLASS = 'teamhub'

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

/* ── Third-party "Announcement Banner" app (announcementbanner) ──
   Suppresses the notification bar injected by mohamedsakhri/nextcloud-announcementbanner.
   The app renders its banners inside a stack wrapper with class "announcementbanner-stack".
   We target both the stack and any individual banner elements. ── */
#announcementbanner,
.announcementbanner-stack,
.announcement-banner,
.announcement-banner-container,
.announcement-banner-wrapper,
[id^="announcementbanner"],
[class^="announcementbanner"] {
    display: none !important;
}

/* ── Layout: zero out NC 32 CSS variable offsets ── */
:root {
    --body-container-margin: 0px !important;
    --body-container-radius: 0px !important;
    /* v4.5.17 — do NOT zero --header-height here. It was tried in 4.5.16 to
       close the gap under Office documents and it broke the Text editor: its
       title is positioned against the same variable, so .md files rendered
       their filename on top of the formatting toolbar. The variable is shared
       by far more than the top bar; fix the specific offender instead, as the
       Viewer rule below does. */
}

/* ── Nextcloud Office (ONLYOFFICE): put the editor back in the layout ──
   The onlyoffice app takes its editor iframe OUT of flow:

     #app > iframe { position: fixed; left: 0; width: 100%;
                     height: calc(100dvh - 58px); margin-top: -50px; }

   Both of the reported symptoms follow from that one declaration:

     - short at the bottom — it reserves 58px for Nextcloud's top bar, which we
       hide, so the reservation is pure loss;
     - the conversation sidebar sitting behind the document — a fixed element
       sized against the viewport spans the full width by construction, so it
       covers the sidebar whatever the viewer is told about it.

   Overriding the height alone (4.5.18) could only ever have fixed the first.
   Returning the editor to normal flow fixes both, and without hardcoding a
   sidebar width: Nextcloud's own layout already reserves that space, so an
   in-flow element simply gets what is left.

   Matched on name="frameEditor" — ONLYOFFICE's own attribute — and :has() keeps
   the height rule to the container that actually holds it, so neither touches
   another app that happens to use #app.

   If this fails it will fail visibly, as a blank or collapsed editor, because
   height: 100% needs an unbroken chain of heights above it. This whole block is
   then the thing to remove. Do NOT respond by zeroing --header-height: that was
   tried in 4.5.16 and broke the Text editor, which positions its filename
   against the same variable. */
#app:has(> iframe[name="frameEditor"]) {
    height: 100% !important;
    width: 100% !important;
}
#app > iframe[name="frameEditor"] {
    position: static !important;
    display: block !important;
    inset: auto !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
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
    components: { NcLoadingIcon, NcActions, NcActionCheckbox, NcActionCaption, OpenInNew, Refresh, AlertCircleOutline, ChevronUp, ChevronDown },

    props: {
        url:   { type: String, required: true },
        label: { type: String, required: true },
        /**
         * Optional array of extra action buttons rendered in the embed bar.
         * Each item: { id: string, label: string, icon: Component }
         * When the user clicks a button, the component emits 'action' with the id.
         */
        embedActions: { type: Array, default: () => [] },
        /**
         * Optional array of select dropdowns rendered in the embed bar before
         * the action buttons.
         * Each item: { id: string, label: string, value: string, options: [{ value, label }] }
         * When the user changes the select, the component emits 'select' with { id, value }.
         */
        embedSelects: { type: Array, default: () => [] },
        /**
         * Optional array of toggle buttons rendered in the embed bar after
         * embedActions. Each item: { id: string, label: string, icon: Component, active: boolean }
         * Unlike embedActions, these carry a persistent pressed/active visual
         * state (aria-pressed + highlight class) — used for filters like the
         * Timeline source toggles (Calendar/Decisions/Deck) where the user
         * needs to see which sources are currently shown.
         * When clicked, the component emits 'toggle' with the id; the parent
         * owns the active state and passes the updated array back down.
         */
        embedToggles: { type: Array, default: () => [] },
        /**
         * Optional single dropdown menu rendered as an NcActions popover in
         * the embed bar. Shape:
         *   { id: string, label: string, icon: Component,
         *     items: [{ id: string, label: string, active: boolean }] }
         * Each checkbox toggle emits 'menu-toggle' with the item's id. The
         * parent owns the active state and re-passes the updated array.
         * Used when there are too many filter options for inline toggle
         * buttons to remain readable in the bar (Timeline with four sources).
         */
        embedMenu: { type: Object, default: null },
        /**
         * Collaboration-first file opening (v4.5.5). When true, a same-origin
         * Files iframe gets the sidebar switched to Talk's chat tab and the
         * conversation mounted, so a file opened from TeamHub arrives with the
         * team's discussion of it already visible. See src/lib/filesCollab.js.
         *
         * Only meaningful on the Files embed — the other embeds have no Files
         * sidebar — and silently inert on cross-origin frames.
         */
        collabSidebar: { type: Boolean, default: false },
        /**
         * Keep internal links clicked inside this embed in TeamHub (v4.5.6,
         * widened to all tab types in v4.5.11). Talk links a shared file as
         * `/f/{id}` with `target="_blank"`, and Deck / Calendar / Wiki do the
         * same for their own objects, which threw the user out into a new
         * window. With this set the click becomes an `open-target` event.
         */
        interceptFileLinks: { type: Boolean, default: false },
        /**
         * This embed's own target type (v4.5.11). Links of that type are left
         * alone, because they navigate *within* this app — intercepting a card
         * link inside the Deck tab would turn a fast SPA route change into a
         * full iframe reload. See attachInternalLinkInterceptor.
         */
        linkSelfType: { type: String, default: '' },
        /**
         * Report where the embedded app has navigated to (v4.8.8).
         *
         * Off by default because nothing else needs it and a poller that
         * runs for every mounted embed is a standing cost for one tab's
         * benefit. The Collectives tab turns it on only while its own view
         * is showing, so the page rail can follow a link the user clicked
         * inside the page body rather than only the ones it issued itself.
         *
         * Polled rather than hooked: the embedded app routes with
         * history.pushState, which fires no event on its own window, and
         * patching another app's History object from here is not a trade we
         * want for a highlight. Same-origin only; a sandboxed frame reports
         * nothing and the rail falls back to what it navigated to itself.
         */
        trackLocation: { type: Boolean, default: false },
        /**
         * Show TeamHub's own toolbar above the frame (v4.5.18).
         *
         * Off for embeds whose bar carried nothing but Reload and Open in new
         * tab — two stacked toolbars cost more vertical space than those buy,
         * and the embedded app already has its own. Left on where the bar
         * carries real controls (Calendar's view switcher and date navigation,
         * Timeline's source filters).
         */
        showBar: { type: Boolean, default: true },
        /**
         * Render the default slot instead of an iframe (v4.6.20).
         *
         * For views TeamHub draws itself but that still want this component's
         * toolbar. In hosted mode `url` should be omitted — which also removes
         * the "Open in new tab" anchor, since there is no external page to open
         * — and Reload emits `reload` rather than rebinding a frame.
         */
        hosted: { type: Boolean, default: false },
    },

    emits: ['action', 'select', 'toggle', 'menu-toggle', 'open-target', 'reload'],

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
            // Toolbar collapsed to a thin strip; remembered across embeds and
            // sessions, since it is a standing space preference not a per-tab one.
            barCollapsed: readBarCollapsed(),
            _observer: null,
            _retryTimers: [],
            // { detach, refresh } from attachCollabSidebar, or null when not attached.
            _collab: null,
            // detach() returned by attachInternalLinkInterceptor.
            _detachFileLinks: null,
            // Guards against a stale async file resolve applying after the url
            // prop moved on again.
            _fileResolveToken: 0,
            // Wait-for-file-render timer (see awaitFileRender).
            _fileRenderTimer: null,
            // Location poller (see trackLocation) and the last href it saw.
            _locationTimer: null,
            _lastLocation: '',
            // In-place navigation watchdog (see tryInPlaceNavigation).
            _inPlaceObserver: null,
            _inPlaceTimer: null,
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
        trackLocation(on) {
            if (on) {
                this.startLocationPoll()
            } else {
                this.stopLocationPoll()
            }
        },
        url(newUrl) {
            // Only fires when the URL prop genuinely changes (e.g. team switch
            // or a dynamic resource update). Tab switches no longer reach here
            // because the component stays in the DOM via v-show — the url prop
            // is unchanged so this watcher is silent.
            //
            // v4.5.12 — when the change stays inside the app already loaded in
            // this frame (opening one event in the calendar that is on screen),
            // route it in place instead of rebinding src. Rebinding src is a
            // full document load: the whole Nextcloud app boots again, which is
            // the 4–6 seconds that made preloading pointless the moment a
            // widget pinned something.
            if (this.tryInPlaceNavigation(newUrl)) {
                return
            }
            if (this.tryResolvedFileNavigation(newUrl)) {
                return          // async; owns its own fallback
            }
            this.iframeSrc = newUrl
            this.loading = true
            this.stopObserver()
            this.clearRetryTimers()
            this.detachCollab()
        },
    },

    mounted() {
        if (this.trackLocation) {
            this.startLocationPoll()
        }
    },

    // v4.5.5 — was beforeDestroy(), which Vue 3 never calls, so the observer and
    // timers leaked on unmount. The collab hooks below need real teardown.
    beforeUnmount() {
        this.stopObserver()
        this.clearRetryTimers()
        this.detachCollab()
        this.cancelInPlaceWatchdog()
        this.cancelFileRenderWait()
        this.stopLocationPoll()
    },

    methods: {
        t,

        /**
         * Collapse or restore the toolbar. Every embed reads the same stored
         * value on mount, so the choice applies everywhere on the next render
         * rather than tab by tab.
         */
        toggleBar() {
            this.barCollapsed = !this.barCollapsed
            writeBarCollapsed(this.barCollapsed)
        },

        /**
         * Manual reload triggered by the toolbar refresh button.
         * Bumps reloadKey so Vue tears down the iframe element entirely
         * before recreating it — equivalent to a hard reload, no cache reuse.
         */
        reload() {
            // v4.6.20 — in hosted mode there is no frame to rebind, so Reload
            // becomes a request to the host view to refetch. Emitted rather
            // than handled here because only the host knows what "reload"
            // means for its own data.
            if (this.hosted) {
                this.$emit('reload')
                return
            }
            this.loading = true
            this.stopObserver()
            this.clearRetryTimers()
            this.detachCollab()
            this.cancelInPlaceWatchdog()
            // Always reload at the *current* url: an in-place navigation
            // deliberately leaves iframeSrc alone (assigning it would rebind
            // src and cause the very reload we avoided).
            this.iframeSrc = this.url
            this.reloadKey++
        },

        onLoad() {
            this.loading = false
            // A file open is not finished when the document loads — the Files
            // app still has to list the folder and open the document. Keep the
            // skeleton up until it does.
            if (!this.isCrossOrigin && opensAFile(this.iframeSrc)) {
                this.awaitFileRender()
            }
            this.stopObserver()
            this.clearRetryTimers()
            this.detachCollab()

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

            // Collaboration-first file opening — only the Files embed asks for it.
            if (this.collabSidebar) {
                this._collab = attachCollabSidebar(this.$refs.frame)
            }

            // Keep file links clicked inside this embed in TeamHub.
            if (this.interceptFileLinks) {
                this._detachFileLinks = attachInternalLinkInterceptor(this.$refs.frame, {
                    selfType: this.linkSelfType,
                    canOpen:  target => this.$store.getters.canOpenInEmbed(target),
                    onTarget: target => this.$emit('open-target', target),
                })
            }
        },

        /**
         * Keep the loading state up until the file the user asked for is on
         * screen, so the Files app's own listing never flashes past on the way
         * (v4.5.16).
         *
         * Stops on the first of: the viewer appearing, the frame going away, or
         * the timeout — the last because plenty of file types have no viewer at
         * all and would otherwise spin forever.
         */
        awaitFileRender() {
            this.cancelFileRenderWait()
            const deadline = Date.now() + FILE_RENDER_TIMEOUT_MS
            this.loading = true
            this._fileRenderTimer = setInterval(() => {
                let rendered = false
                try {
                    rendered = !!this.$refs.frame?.contentDocument?.querySelector(RENDERED_FILE_SELECTOR)
                } catch (e) {
                    rendered = true          // cannot inspect it; stop waiting
                }
                if (rendered || Date.now() >= deadline) {
                    this.cancelFileRenderWait()
                    this.loading = false
                    if (rendered) {
                        // The document is on screen: this is the moment a viewer
                        // or editor can be told the sidebar is open. Doing it on
                        // a timer instead is what let Office render over it.
                        try { this._collab?.nudgeViewer() } catch (e) {}
                    }
                }
            }, FILE_RENDER_POLL_MS)
        },

        cancelFileRenderWait() {
            if (this._fileRenderTimer) {
                clearInterval(this._fileRenderTimer)
                this._fileRenderTimer = null
            }
        },

        /**
         * Where the embedded app currently is, or null when that cannot be read
         * (cross-origin, or nothing loaded yet). Lets a parent notice that the
         * app has navigated itself — there is no event for that.
         *
         * @return {string|null}
         */
        frameLocationHref() {
            try {
                if (this.isCrossOrigin) {
                    return null
                }
                return this.$refs.frame?.contentWindow?.location?.href || null
            } catch (e) {
                return null
            }
        },

        /**
         * Route the embedded app to a new URL without reloading it (v4.5.12).
         *
         * Both Calendar and Deck run vue-router in **history mode**, which
         * listens for `popstate`. Pushing the new URL onto the frame's own
         * history and dispatching `popstate` is the documented way to drive
         * such a router from outside; the app re-renders in place, instantly,
         * instead of booting from scratch.
         *
         * Only attempted when the frame is already showing the *same* app, so
         * there is a live router to talk to. Anything else falls through to a
         * normal src navigation.
         *
         * @param {string} newUrl
         * @return {boolean} true when in-place navigation was attempted
         */
        tryInPlaceNavigation(newUrl) {
            if (this.isCrossOrigin || !newUrl || !this.iframeSrc) {
                return false
            }
            try {
                const frame = this.$refs.frame
                const win = frame?.contentWindow
                if (!win || !win.location || !frame.contentDocument?.body) {
                    return false           // nothing loaded yet
                }
                const current = new URL(win.location.href)
                const next = new URL(newUrl, current.href)
                if (next.origin !== current.origin) {
                    return false
                }
                const context = routingContextOf(next.pathname)
                if (context === null || context !== routingContextOf(current.pathname)) {
                    return false           // different boot context — must load it
                }
                if (next.href === current.href) {
                    return true            // already there; nothing to do
                }

                win.history.pushState({}, '', next.href)
                win.dispatchEvent(new win.PopStateEvent('popstate', { state: {} }))
                this.watchInPlaceNavigation(newUrl)
                if (opensAFile(next.href)) {
                    this.awaitFileRender()
                }
                // No document load, so the collaboration hooks never re-run on
                // their own — the next file would arrive without its conversation.
                try { this._collab?.refresh() } catch (e) {}
                return true
            } catch (e) {
                return false
            }
        },

        /**
         * In-place navigation for a `/f/{id}` file link (v4.5.14).
         *
         * The short link carries no folder, which the Files app needs before it
         * can show a file: its list only opens a fileid that is in the folder it
         * has loaded. `/f/{id}` exists precisely to work that out — the server
         * redirects to the real `/apps/files/{view}/{id}?dir=…` URL.
         *
         * So: follow the redirect once with fetch, read the resolved URL off the
         * response, and route to *that* in place. One request (~300 ms, body
         * cancelled immediately) instead of booting the whole Files app again.
         *
         * @param {string} newUrl
         * @return {boolean} true when this took ownership of the navigation
         */
        tryResolvedFileNavigation(newUrl) {
            if (this.isCrossOrigin || !newUrl) {
                return false
            }
            try {
                const win = this.$refs.frame?.contentWindow
                if (!win?.location || !this.$refs.frame.contentDocument?.body) {
                    return false           // nothing loaded to route
                }
                const next = new URL(newUrl, win.location.href)
                if (next.origin !== win.location.origin
                    || !/\/f\/\d+\/?$/.test(next.pathname)
                    || appIdOf(win.location.pathname) !== 'files') {
                    return false           // not a short link, or Files not loaded
                }

                const token = ++this._fileResolveToken
                fetch(next.href, { redirect: 'follow' })
                    .then((res) => {
                        try { res.body?.cancel() } catch (e) {}
                        // The url prop moved on while we were resolving.
                        if (token !== this._fileResolveToken || this.url !== newUrl) {
                            return
                        }
                        if (res.ok && res.url && this.tryInPlaceNavigation(res.url)) {
                            return
                        }
                        throw new Error('unresolved')
                    })
                    .catch(() => {
                        if (token !== this._fileResolveToken || this.url !== newUrl) {
                            return
                        }
                        logger.debug('AppEmbed: could not resolve a file link in place — reloading')
                        this.iframeSrc = newUrl
                        this.loading = true
                        this.stopObserver()
                        this.clearRetryTimers()
                        this.detachCollab()
                    })
                return true
            } catch (e) {
                return false
            }
        },

        /**
         * Safety net for the above. If the app's router ignored our `popstate`
         * the URL would change while the view did not — a silent no-op, which
         * is worse than the slow reload we were avoiding.
         *
         * Any route change repaints something, so we watch the frame for DOM
         * mutations and fall back to a real navigation if none arrive. A false
         * negative merely costs the reload we would have done anyway.
         */
        watchInPlaceNavigation(newUrl) {
            this.cancelInPlaceWatchdog()
            try {
                const doc = this.$refs.frame?.contentDocument
                if (!doc?.body) {
                    return
                }
                let mutated = false
                const observer = new (this.$refs.frame.contentWindow.MutationObserver || MutationObserver)(
                    () => { mutated = true },
                )
                observer.observe(doc.body, { childList: true, subtree: true })
                this._inPlaceObserver = observer
                this._inPlaceTimer = setTimeout(() => {
                    this.cancelInPlaceWatchdog()
                    if (mutated) {
                        return
                    }
                    logger.debug('AppEmbed: in-place navigation had no effect — reloading the frame')
                    this.iframeSrc = newUrl
                    this.loading = true
                    this.stopObserver()
                    this.clearRetryTimers()
                    this.detachCollab()
                }, IN_PLACE_VERIFY_MS)
            } catch (e) {
                // No watchdog — the app either routed or the user can hit Reload.
            }
        },

        cancelInPlaceWatchdog() {
            if (this._inPlaceObserver) {
                try { this._inPlaceObserver.disconnect() } catch (e) {}
                this._inPlaceObserver = null
            }
            if (this._inPlaceTimer) {
                clearTimeout(this._inPlaceTimer)
                this._inPlaceTimer = null
            }
        },

        /**
         * Remove the collaboration-first hooks from the current document.
         * Called before every (re)load and on unmount — the hooks are bound to
         * one iframe window, so a navigation invalidates them.
         */
        detachCollab() {
            if (this._collab) {
                try { this._collab.detach() } catch (e) {}
                this._collab = null
            }
            if (this._detachFileLinks) {
                try { this._detachFileLinks() } catch (e) {}
                this._detachFileLinks = null
            }
        },

        injectCss() {
            try {
                const frame = this.$refs.frame
                if (!frame) return
                const doc = frame.contentDocument || frame.contentWindow?.document
                if (!doc) return

                // v4.7.6 — a stable hook for per-instance admin CSS.
                //
                // Marks the embedded document as "this NC page is running
                // inside TeamHub", so an admin's Theming custom CSS can style
                // an app differently here than in its standalone view:
                //
                //   html.teamhub .app-files .some-toolbar { display: none }
                //
                // The app itself needs no marker of ours — NC's layout already
                // puts `app-{appid}` on its content element (verified against
                // core/templates/layout.user.php on NC 34), so the two compose.
                //
                // Applied here rather than in onLoad() on purpose: injectCss()
                // also runs from the retry timers and the MutationObserver, so
                // the class is re-asserted if an embedded app rewrites
                // documentElement.className after boot. classList.add is a
                // no-op when the class is already there.
                //
                // Same-origin only, and that is the whole useful set: a
                // cross-origin embed is sandboxed without allow-same-origin
                // and onLoad() returns before ever reaching this method.
                doc.documentElement?.classList.add(EMBED_MARKER_CLASS)

                if (!doc.head) return

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

        /**
         * Watch the embedded app's address and emit `navigate` when it
         * changes. Emits the app-relative form (pathname + search) because
         * that is what our own URLs are built as, so a consumer comparing
         * the two never has to strip an origin.
         */
        startLocationPoll() {
            this.stopLocationPoll()
            if (this.isCrossOrigin) {
                return
            }
            this._locationTimer = setInterval(() => {
                try {
                    const win = this.$refs.frame?.contentWindow
                    const href = win?.location?.href
                    if (!href || href === this._lastLocation) {
                        return
                    }
                    this._lastLocation = href
                    const u = new URL(href)
                    this.$emit('navigate', u.pathname + u.search)
                } catch (e) {
                    // Frame torn down mid-tick, or it navigated somewhere
                    // cross-origin. Nothing to report either way.
                }
            }, LOCATION_POLL_MS)
        },

        stopLocationPoll() {
            if (this._locationTimer) {
                clearInterval(this._locationTimer)
                this._locationTimer = null
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

/* Collapsed: a thin strip carrying just the app name and the way back.
   The bar keeps its own border and radius so the frame still reads as one
   panel rather than the iframe running into the tab bar. */
.app-embed__bar--collapsed {
    padding: 2px 12px;
}

.app-embed__bar-collapse {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 24px;
    height: 24px;
    min-width: 24px;
    min-height: 24px;
    max-width: 24px;
    max-height: 24px;
    margin-inline-start: auto;   /* pushed to the right, before the actions */
    padding: 0;
    border: none;
    border-radius: var(--border-radius);
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}
.app-embed__bar-collapse:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}
/* Split from :hover per SKILLS.md § focus visibility — grouping them would
   silence the keyboard focus ring. */
.app-embed__bar-collapse:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.app-embed__bar-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    /* Stacked icon+label buttons take more horizontal room — allow wrap to
       a second row on narrow viewports rather than horizontally overflow. */
    flex-wrap: wrap;
    row-gap: 6px;
    justify-content: flex-end;
}

.app-embed__bar-select {
    height: 34px;
    padding: 0 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    font-size: 13px;
    cursor: pointer;
    outline: none;
    margin-right: 4px;
}

.app-embed__bar-select:focus-visible {
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.app-embed__bar-date-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
    padding: 0 6px;
    min-width: 110px;
    text-align: center;
    white-space: nowrap;
}

/*
 * Stacked icon-above-label bar buttons. Reported in 3.81.x as the next
 * usability issue after the upcoming-events widget rework: the bar was
 * full of similar-looking 16px icon-only buttons (prev/next, today,
 * add event, delete events, reload, open in new tab) and users couldn't
 * tell them apart at a glance. Each button now carries its own short
 * label directly under its icon.
 *
 * Density trade-off: the bar grows ~14px taller. Acceptable — losing
 * a sliver of viewport beats forcing the user to hover-test every icon.
 */
.app-embed__bar-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-width: 56px;
    padding: 4px 8px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
    line-height: 1.1;
}
.app-embed__bar-btn:hover {
    background: var(--color-background-hover);
}
.app-embed__bar-btn:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.app-embed__bar-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.app-embed__bar-btn-icon {
    color: var(--color-main-text);
    flex-shrink: 0;
}
.app-embed__bar-btn-label {
    font-size: var(--th-font-micro);
    font-weight: 400;
    white-space: nowrap;
    color: var(--color-text-maxcontrast);
}

/* Active state for toggle buttons (e.g. Timeline source filters).
 * Uses the full-saturation primary token per SKILLS.md design rules. */
.app-embed__bar-btn--active {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}
.app-embed__bar-btn--active .app-embed__bar-btn-icon,
.app-embed__bar-btn--active .app-embed__bar-btn-label {
    color: var(--color-primary-element-text);
}
.app-embed__bar-btn--active:hover {
    background: var(--color-primary-element-hover, var(--color-primary-element));
}

.app-embed__label {
    font-weight: 600;
    font-size: var(--th-font-body);
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
    /* v4.6.20 — no background. `--color-background-plain` is the themed
       page-behind colour, which was fine when this only ever held an iframe
       that painted over it, but in hosted mode it shows through wherever the
       hosted view is transparent — most visibly behind the calendar grid's
       list views. The hosted view owns its own surface. */
}

/* No toolbar above us: close the frame on all four sides instead of leaving
   the open top edge that assumed a bar was sitting on it. */
.app-embed__viewport--full {
    border-top: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
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
