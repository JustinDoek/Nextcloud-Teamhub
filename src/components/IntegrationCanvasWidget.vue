<template>
    <div class="th-canvas">
        <div v-if="!src" class="th-canvas__state">
            <AlertCircleIcon :size="ICON_LARGE" class="th-canvas__state-icon" />
            <span>{{ t('teamhub', 'This widget has no address to load.') }}</span>
        </div>

        <template v-else>
            <div v-if="loading" class="th-canvas__state">
                <NcLoadingIcon :size="ICON_LARGE" />
            </div>
            <iframe
                ref="frame"
                :src="src"
                :title="title"
                :sandbox="sandbox"
                allow=""
                referrerpolicy="strict-origin-when-cross-origin"
                class="th-canvas__frame"
                :class="{
                    'th-canvas__frame--loading': loading,
                    'th-canvas__frame--inert': arranging,
                }"
                @load="onLoad" />
        </template>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'

import { ICON_LARGE } from '../constants/uiTokens.js'
import { applyEmbedSurface } from '../lib/embedSurface.js'

/**
 * The body of a blank-canvas widget — v4.13.24.
 *
 * A `widget_iframe` capability's card. TeamHub draws the card: its place in
 * the grid, its size, its header, its title, its collapse control, and the
 * team administrator's shown/hidden switch. The owning app draws the inside,
 * on the same terms the iframe tab has had since 2.42.3.
 *
 * ## Why this is not AppEmbed
 *
 * `AppEmbed` is the full-tab canvas, and almost all of it is chrome this card
 * must not have: a toolbar with its own label and collapse state, an action
 * row, inline selects, an "open in new tab" control, same-origin CSS
 * injection to strip the embedded app's own chrome, in-place navigation,
 * location polling. Inside a card a few hundred pixels wide, every one of
 * those is either noise or a second header competing with the one the grid
 * already drew. What is left of AppEmbed after taking them away is this
 * component, so it is this component rather than a dozen props turning the
 * other one off.
 *
 * **One exception, v4.13.27: the surface.** Of all that injected CSS, four
 * lines are not about the app's chrome at all — they stop Nextcloud painting
 * the instance's page background, i.e. the administrator's wallpaper, inside
 * a card whose background TeamHub has already drawn. That belongs to whoever
 * drew the card, so both surfaces now take those four lines from
 * `src/lib/embedSurface.js`. Nothing else is injected here: the chrome of an
 * app that renders its embed as a page is still the app's own problem to
 * solve, with `RENDER_AS_BASE`.
 *
 * ## The sandbox
 *
 * Same rule as `AppEmbed`, deliberately, so the two cannot disagree about
 * what an embedded app is allowed to do: a cross-origin frame is sandboxed,
 * a same-origin Nextcloud app is not. A registered capability's URL is
 * normally `/apps/<app>/…` on this very instance — the app is installed here
 * and is already trusted code, and sandboxing it without `allow-same-origin`
 * breaks it while sandboxing it with `allow-same-origin` is theatre. An
 * `https://` URL somewhere else gets the real sandbox.
 *
 * The URL arrives from the server with TeamHub's own verified team id
 * appended, validated by `IframeUrl` at registration and again at render.
 * This component never builds or edits it: the team a card is scoped to is
 * settled by the membership check that produced the list, and nothing here
 * can influence it.
 */
export default {
    name: 'IntegrationCanvasWidget',

    components: {
        NcLoadingIcon,
        AlertCircleIcon,
    },

    props: {
        /**
         * The capability descriptor from `renderableWidgetsForTeam`. Its
         * `url` already carries the verified team id.
         */
        integration: { type: Object, required: true },

        /**
         * The pointer contract — v4.13.32.
         *
         * True while the surface this card sits on is being rearranged (the
         * home grid's edit mode). The frame then takes no pointer events, so
         * a drag or a resize that crosses it keeps working.
         *
         * It has to be a prop rather than a CSS rule the grid reaches in
         * with: the grid is the only thing that knows a drag is possible,
         * and the mobile and tablet surfaces that also mount this component
         * have no drag at all. Default false — a surface that cannot be
         * rearranged says nothing and keeps a fully interactive frame.
         */
        arranging: { type: Boolean, default: false },
    },

    data() {
        return {
            ICON_LARGE,
            loading: true,
        }
    },

    computed: {
        /**
         * Only an acceptable address reaches the frame. The server validates
         * at registration and again on the way out; this is the third check,
         * for the same reason `TeamView.menuItemUrl()` is: stored data is not
         * trusted on the way out either, and an empty string renders the
         * "no address" state rather than an iframe with no src.
         */
        src() {
            const raw = String(this.integration?.url || '').trim()
            if (raw === '') return ''
            const acceptable = raw.startsWith('https://')
                || raw.startsWith('/apps/')
                || raw.startsWith('/index.php/')
            return acceptable ? raw : ''
        },

        title() {
            return this.integration?.title || t('teamhub', 'Widget')
        },

        isCrossOrigin() {
            if (!this.src) return false
            try {
                // A relative URL resolves against this location and is
                // same-origin by definition.
                return new URL(this.src, window.location.href).origin !== window.location.origin
            } catch (e) {
                // Unparseable — treat as cross-origin out of caution.
                return true
            }
        },

        /**
         * `null` renders no sandbox attribute at all, which is what a
         * same-origin Nextcloud app needs. An empty string would be the
         * strictest possible sandbox and is deliberately not what happens
         * when the rule does not apply.
         */
        sandbox() {
            return this.isCrossOrigin
                ? 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox'
                : null
        },
    },

    watch: {
        src() {
            this.loading = true
        },
    },

    methods: {
        t,

        onLoad() {
            this.loading = false
            // v4.13.27 — the card's surface, before the frame becomes
            // visible: `loading` is cleared in the same tick, so the
            // wallpaper is never painted and then corrected. Cross-origin
            // pages are skipped and own their own background.
            applyEmbedSurface(this.$refs.frame, { crossOrigin: this.isCrossOrigin })
        },
    },
}
</script>

<style scoped>
/* The height contract — v4.13.26.
 *
 * `height: 100%` is the whole of this component's sizing, and it means the
 * surface that mounts it MUST give it a definite height. There is no second
 * option: an iframe has no content to grow from, so a parent of `height:
 * auto` leaves the frame at its intrinsic 300x150 and the embedded page lays
 * itself out against 150px however large the card is. That is exactly what
 * the tablet stack and the mobile canvas did until 4.13.26 — both wrapped
 * this in a body with padding and no height — and it reads as the embedded
 * app's bug, because the two symptoms are "the content is tiny" and "it will
 * not scroll": a page that correctly sets `body { overflow: hidden }` and
 * scrolls its own panes cannot scroll a viewport that is smaller than its
 * own chrome.
 *
 * Deliberately no `min-height` fallback here. It would have to be large
 * enough to be useful on a phone, which is larger than a three-row card on
 * the desktop grid, so it would break the one surface that gets this right.
 * A new surface owes this component a height. */
.th-canvas {
    position: relative;
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

.th-canvas__frame {
    flex: 1;
    width: 100%;
    min-height: 0;
    border: 0;
    border-radius: var(--border-radius-small);
    background: var(--color-main-background);
}

/* Hidden rather than unmounted while it loads: the load event is what clears
   the spinner, and a frame that is not in the DOM never fires one. */
.th-canvas__frame--loading {
    visibility: hidden;
}

/* The pointer contract — v4.13.32.
 *
 * Why a canvas card was so much harder to resize than every other widget.
 * `grid-layout-plus` drags and resizes through **interact.js**, which listens
 * for `pointermove` on the document and never calls `setPointerCapture` (the
 * bundled copy has no such call). For a mouse, a pointer event is NOT
 * implicitly captured by the element the drag started on — it goes to
 * whatever is under the cursor. An iframe is a separate document, so the
 * moment the cursor crossed into the frame the parent stopped hearing
 * `pointermove` and the interaction froze until it came back out.
 *
 * The resize handle sits 4px from the card's bottom-right corner with the
 * frame directly underneath it, so **shrinking** a canvas card meant dragging
 * straight into the dead zone: the handle grabbed, the card did not follow.
 * Dragging the card by its body was dead for the same reason — the grid sets
 * no `drag-allow-from`, so on every other widget the whole card is a drag
 * surface.
 *
 * `pointer-events: none` hands those events back to `.th-canvas` behind it,
 * where they bubble to the grid item like any other widget's. The cost is
 * deliberate and small: while the layout is in edit mode the embedded app
 * cannot be clicked or scrolled. That is what edit mode is — the banner says
 * *Drag widgets to rearrange* — and every card is already `cursor: move`.
 *
 * Doing this only for the duration of an interaction was the alternative and
 * is worse: `GridItem` emits `resize` on the first move it has already
 * processed, so the flag would arrive one frame into the exact pixels where
 * the stall happens. */
.th-canvas__frame--inert {
    pointer-events: none;
}

.th-canvas__state {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--th-space-sm);
    text-align: center;
    padding: var(--th-space-md);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.th-canvas__state-icon {
    color: var(--color-text-maxcontrast);
}
</style>
