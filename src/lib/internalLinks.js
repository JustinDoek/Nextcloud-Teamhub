/**
 * Recognising Nextcloud links, so TeamHub can keep them in the team.
 *
 * TeamHub embeds Files, Calendar, Deck and the Wiki as tabs. Links to those
 * things are everywhere — the activity feed, the message stream, decision
 * links, and inside the embedded apps themselves (Talk renders a shared file as
 * `<a href="/f/{id}" target="_blank">`). Followed naively, every one of them
 * throws the user out into a separate browser window and out of the team.
 *
 * This module answers one question — *"is this URL something TeamHub can open
 * itself, and if so, what?"* — in one place, so each new surface is a two-line
 * call rather than another bespoke click handler. The store turns the answer
 * into a tab switch (`openInEmbed`); it does not belong here.
 *
 * **Match on the URL, not on markup.** These are server routes, not app
 * internals: `/f/{id}` is declared in `apps/files/appinfo/routes.php`. Keying on
 * them works for embedded apps nobody here has looked at, and cannot break when
 * one of them restyles its DOM.
 *
 * **Cross-origin never matches.** That is a security boundary, not an
 * optimisation: a link inside an embedded page must never be able to make
 * TeamHub navigate to an origin of its choosing.
 */
import logger from '../logger.js'

/**
 * True for a plain left click — the kind we are allowed to repurpose.
 * Ctrl / Cmd / Shift / middle click always keep their native behaviour, so a
 * real new tab stays one keypress away everywhere.
 *
 * @param {MouseEvent} event
 * @return {boolean}
 */
export function isPlainClick(event) {
    return !(event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1)
}

/**
 * Coerce an id to a positive integer, or null. Defence in depth: these values
 * end up in URLs and router params. See SKILLS.md § security standards.
 *
 * @param {number|string} value
 * @return {number|null}
 */
function toId(value) {
    const id = Number(value)
    return Number.isSafeInteger(id) && id > 0 ? id : null
}

/**
 * Parse a URL and reject anything not on this Nextcloud.
 *
 * @param {string} url
 * @param {string} base
 * @return {URL|null}
 */
function sameOriginUrl(url, base) {
    if (!url || typeof url !== 'string') {
        return null
    }
    try {
        const parsed = new URL(url, base)
        return parsed.origin === new URL(base).origin ? parsed : null
    } catch (e) {
        return null
    }
}

/**
 * The file id in a Nextcloud file link, or null.
 *
 * Kept exported because the activity feed also resolves file ids from its own
 * row data and falls back to this.
 *
 * @param {string} url
 * @param {string} [base]
 * @return {number|null}
 */
export function fileIdFromUrl(url, base = window.location.href) {
    const parsed = sameOriginUrl(url, base)
    if (!parsed) {
        return null
    }
    // /f/{id} — the canonical short link. Trailing slash allowed; nothing after.
    const short = parsed.pathname.match(/\/f\/(\d+)\/?$/)
    if (short) {
        return toId(short[1])
    }
    if (!parsed.pathname.includes('/apps/files')) {
        return null
    }
    const queryId = toId(parsed.searchParams.get('fileid'))
    if (queryId !== null) {
        return queryId
    }
    // /apps/files/{view}/{id}
    const deep = parsed.pathname.match(/\/apps\/files\/[^/]+\/(\d+)\/?$/)
    return deep ? toId(deep[1]) : null
}

/**
 * Work out what a URL points at, if TeamHub can open it in one of its tabs.
 *
 * @param {string} url absolute or relative
 * @param {string} [base] resolved against, and the origin that must match
 * @return {{type: string}|null} one of:
 *   { type: 'file',        fileId }
 *   { type: 'deck',        boardId, cardId? }
 *   { type: 'calendar',    object, recurrenceId }
 *   { type: 'collectives', path }
 */
export function resolveInternalTarget(url, base = window.location.href) {
    const parsed = sameOriginUrl(url, base)
    if (!parsed) {
        return null
    }

    const fileId = fileIdFromUrl(url, base)
    if (fileId !== null) {
        return { type: 'file', fileId }
    }

    // Deck writes two forms and TeamHub itself generates both: the widget uses
    // the plain path, TeamView's deckUrl the legacy hash route. Match either by
    // testing path and hash together.
    const deckSource = parsed.pathname + parsed.hash
    if (parsed.pathname.includes('/apps/deck')) {
        const card = deckSource.match(/\/board\/(\d+)\/card\/(\d+)/)
        if (card) {
            const boardId = toId(card[1])
            const cardId = toId(card[2])
            if (boardId !== null && cardId !== null) {
                return { type: 'deck', boardId, cardId }
            }
        }
        const board = deckSource.match(/\/board\/(\d+)/)
        if (board) {
            const boardId = toId(board[1])
            if (boardId !== null) {
                return { type: 'deck', boardId, cardId: null }
            }
        }
        return null
    }

    // Calendar: only event links. Both route families — the personal app's
    // `edit/{sidebar,popover,full}` and a shared calendar's read-only
    // `view/{popover,full}` — carry the same two trailing segments.
    //
    // A bare calendar link (a month view) deliberately does NOT match: TeamHub's
    // calendar tab shows the *team* calendar, so silently switching to it for a
    // link to some other calendar would show the wrong thing.
    if (parsed.pathname.includes('/apps/calendar')) {
        const ev = parsed.pathname.match(/\/(?:edit|view)\/(?:sidebar|popover|full)\/([^/]+)\/([^/]+)\/?$/)
        return ev ? { type: 'calendar', object: ev[1], recurrenceId: ev[2] } : null
    }

    // Collectives: hand back the app-relative path, because TeamView feeds it
    // through generateUrl() — passing an already-based URL would double the
    // instance's base path on a sub-directory install.
    const wiki = parsed.pathname.match(/(\/apps\/collectives\/.+)$/)
    if (wiki) {
        return { type: 'collectives', path: wiki[1] }
    }

    return null
}

/**
 * Take over a click on an internal link, if TeamHub can open it.
 *
 * Availability is checked **before** `preventDefault()`: a Deck card link in a
 * team with no Deck board must fall through to the original link, not swallow
 * the click and switch to a tab that does not exist.
 *
 * @param {MouseEvent} event
 * @param {object} store the Vuex store
 * @param {object} [options]
 * @param {string} [options.base] URL to resolve relative hrefs against
 * @param {string} [options.selfType] target type to ignore — see
 *        attachInternalLinkInterceptor
 * @return {boolean} true when the click was handled
 */
export function handleInternalLinkClick(event, store, { base, selfType } = {}) {
    if (!isPlainClick(event)) {
        return false
    }
    const anchor = event.target?.closest?.('a[href]')
    if (!anchor) {
        return false
    }
    const target = resolveInternalTarget(anchor.getAttribute('href'), base)
    if (!target || target.type === selfType) {
        return false
    }
    if (!store.getters.canOpenInEmbed(target)) {
        return false
    }
    event.preventDefault()
    store.dispatch('openInEmbed', target)
    return true
}

/**
 * Keep internal links clicked inside an embedded Nextcloud app in TeamHub.
 *
 * `selfType` is what stops this being actively harmful. Without it, clicking a
 * card inside the Deck tab would be intercepted and re-navigate the very iframe
 * it came from — turning a fast in-app route change into a full app reload. An
 * embed never intercepts links to its own app; only links that would have left
 * it anyway.
 *
 * @param {HTMLIFrameElement} iframe a same-origin embed
 * @param {object} options
 * @param {(target: object) => boolean} options.canOpen availability check
 * @param {(target: object) => void} options.onTarget called for a handled click
 * @param {string} [options.selfType] this embed's own target type
 * @return {() => void} detach
 */
export function attachInternalLinkInterceptor(iframe, { canOpen, onTarget, selfType } = {}) {
    let doc
    let win
    try {
        win = iframe?.contentWindow
        doc = iframe?.contentDocument || win?.document
    } catch (e) {
        return () => {}         // cross-origin
    }
    if (!doc || !win || typeof onTarget !== 'function') {
        return () => {}
    }

    const handler = (event) => {
        try {
            if (!isPlainClick(event)) {
                return
            }
            const anchor = event.target?.closest?.('a[href]')
            if (!anchor) {
                return
            }
            const href = anchor.getAttribute('href')
            const target = resolveInternalTarget(href, win.location.href)
            if (!target) {
                // Diagnostic, debug level only. Some apps post a link as a rich
                // reference widget rather than a plain anchor, and the shape it
                // renders is not something we can know from the outside — this
                // prints the href that got away so it can be matched properly
                // instead of guessed at.
                logger.debug('internalLinks: link not recognised as internal', { href })
                return
            }
            if (target.type === selfType) {
                return
            }
            if (typeof canOpen === 'function' && !canOpen(target)) {
                return
            }
            event.preventDefault()
            event.stopPropagation()
            logger.debug('internalLinks: keeping an embedded link in TeamHub', { type: target.type })
            onTarget(target)
        } catch (e) {
            // Let the click through unchanged rather than swallowing it.
        }
    }

    doc.addEventListener('click', handler, true)

    // Not every link is an anchor. Talk renders a Deck card posted through the
    // smart picker (or shared from Deck) as a *reference widget*, which opens
    // its target with `window.open()` — invisible to a click listener, so those
    // still escaped into a new window. Wrap `open` and route the ones we own.
    //
    // Everything else is passed straight through to the original, so genuinely
    // external links, OAuth popups and downloads are untouched.
    let originalOpen = null
    try {
        originalOpen = win.open
        if (typeof originalOpen === 'function') {
            win.open = function (url, ...rest) {
                try {
                    const target = url ? resolveInternalTarget(String(url), win.location.href) : null
                    if (target && target.type !== selfType
                        && (typeof canOpen !== 'function' || canOpen(target))) {
                        logger.debug('internalLinks: intercepted window.open', { type: target.type })
                        onTarget(target)
                        return null
                    }
                } catch (e) {
                    // fall through to the real window.open
                }
                return originalOpen.apply(win, [url, ...rest])
            }
        }
    } catch (e) {
        originalOpen = null
    }

    return function detach() {
        try { doc.removeEventListener('click', handler, true) } catch (e) {}
        try {
            // Only restore if nothing else replaced it after us.
            if (originalOpen && win.open !== originalOpen) {
                win.open = originalOpen
            }
        } catch (e) {}
    }
}
