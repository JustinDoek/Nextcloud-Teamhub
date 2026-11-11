/**
 * TeamHub — the surface an embedded Nextcloud page sits on (v4.13.27).
 *
 * Why this is one rule in one place
 * ---------------------------------
 * A page rendered by a Nextcloud app is a *page*: Nextcloud paints the
 * instance's background on its body, which on a themed instance is the
 * administrator's wallpaper. Inside TeamHub it is not a page — it is the
 * interior of a card or a tab whose surface TeamHub has already drawn, with
 * its own border, radius and background. The wallpaper showing through there
 * is not the app's design choice and not ours; it is a page background that
 * only exists because the app is served as a page.
 *
 * `AppEmbed` has stripped it since 2.42.3, as four lines inside a 200-line
 * chrome-stripping stylesheet. The blank-canvas widget (4.13.24) injects no
 * CSS at all and therefore kept the wallpaper — reported by Justin on the
 * TOPdesk cards: *"de achtergrond van widgets voor integraties zouden dezelfde
 * achtergrond kleur moeten hebben als andere widgets en niet de background
 * wallpaper"*. Both surfaces now take the rule from here, so neither can be
 * fixed without the other.
 *
 * `background`, not `background-color`
 * ------------------------------------
 * The shorthand is load-bearing and is the whole of the bug. Setting only the
 * colour leaves any `background-image` painted on top of it, so an app that
 * carefully sets `background-color: var(--color-main-background)` on its body
 * — TOPdesk does — still shows the wallpaper through it. The shorthand resets
 * the image to none in the same declaration.
 */

/**
 * The rules, as text, for injection into an embedded document's head.
 *
 * Deliberately only html and body: everything else an embedded page draws is
 * the app's own business. No overflow rule either — overflow: hidden on body
 * would clip the app's internal scroll areas, and both surfaces instead rely
 * on the iframe being a bounded viewport of its own.
 */
export const EMBED_SURFACE_CSS = `
html, body {
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    background: var(--color-main-background) !important;
}
`

/** The id of the style element, so a re-injection replaces rather than stacks. */
export const EMBED_SURFACE_STYLE_ID = 'teamhub-embed-surface'

/**
 * Put the surface rules into a same-origin embedded document.
 *
 * Returns whether the rules are in place, so a caller can tell "not injected"
 * from "injected" without reaching into the frame itself — which is also what
 * makes this testable without a browser.
 *
 * A cross-origin frame is skipped and reports false: there is nothing to
 * reach, by design, and a sandboxed frame without `allow-same-origin` throws
 * on the attempt. Such a page owns its own background, the way a page on
 * somebody else's instance must.
 *
 * @param {HTMLIFrameElement|object} frame      the frame element (or a stub with contentDocument)
 * @param {object}                   [options]
 * @param {boolean}                  [options.crossOrigin] true to skip injection entirely
 * @return {boolean} whether the rules are now in the document
 */
export function applyEmbedSurface(frame, { crossOrigin = false } = {}) {
    if (!frame || crossOrigin) return false
    try {
        const doc = frame.contentDocument || frame.contentWindow?.document
        if (!doc?.head) return false

        let style = doc.getElementById(EMBED_SURFACE_STYLE_ID)
        if (!style) {
            style = doc.createElement('style')
            style.id = EMBED_SURFACE_STYLE_ID
            doc.head.appendChild(style)
        }
        style.textContent = EMBED_SURFACE_CSS
        return true
    } catch (e) {
        // A same-origin frame that redirected somewhere else, or a document
        // torn down between the load event and this call. The card keeps its
        // own background either way, so there is nothing to report.
        return false
    }
}
