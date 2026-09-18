/**
 * Collaboration-first file opening.
 *
 * TeamHub's premise is that a file opened from a team should arrive with the
 * conversation about it in reach. Nextcloud can do this — Talk registers a
 * `chat` tab in the Files sidebar — but it takes five interactions to get
 * there: Files app → select → … → Details → Chat, and only then open the file
 * (open it first and the sidebar never appears).
 *
 * What stands between us and one interaction is that **the sidebar lands on
 * the wrong tab.** `/f/{id}?opendetails=true` is a first-class server route
 * (`Files\ViewController::showFile()` forwards `opendetails` / `openfile`), so
 * the file and the sidebar open together — but on the *first* tab, `sharing`.
 * Talk's tab is `chat`, `order: 30`. So we switch the tab ourselves.
 *
 * **What we deliberately leave to the user is Talk's "Join conversation"
 * button** (v4.9.20; v4.5.5–4.9.19 clicked it for them). Opening the chat tab
 * only asks Talk for the room (`GET …/spreed/api/v1/file/{fileId}` creates it
 * with no attendees); the button is what joins the room, and joining is what
 * makes the file's conversation appear in Talk's own conversation list. With
 * the click automated every file the user so much as looked at piled up in
 * Talk — verified in Talk 24.0.5: `FilesSidebarLoaderApp.vue` →
 * `FilesSidebarTabApp.joinConversation()` → `ParticipantService::joinRoom()`,
 * which inserts the attendee row. One click, by the person who wants the
 * conversation, is the right price.
 *
 * Everything here is best-effort. Each failure degrades to today's behaviour
 * (file opens, no sidebar, or the sidebar on Sharing) — never to a broken view.
 * That contract is why reaching into the Files app's sidebar API is
 * acceptable; keep it.
 *
 * Nextcloud version differences (verified against nextcloud/server on
 * stable32, stable33 and stable34):
 *
 *   NC 33/34  window.OCA.Files._sidebar()  — @nextcloud/files ISidebar impl.
 *                                            setActiveTab() THROWS on an
 *                                            unavailable tab.
 *   NC 32     window.OCA.Files.Sidebar     — legacy global, state under .state
 */
import { generateUrl } from '@nextcloud/router'
import logger from '../logger.js'

/** Talk's Files-sidebar tab id. Stable across NC 32/33/34. */
const CHAT_TAB_ID = 'chat'

/**
 * A file row in the Files list. `data-cy-*` attributes are test hooks rather
 * than API, but they are the only stable identifiers on the row and they are
 * unchanged across NC 32/33/34 (`FileEntry.vue` and `FileEntryGrid.vue`).
 */
const ROW_SELECTOR = '[data-cy-files-list-row-fileid]'
const ROW_FILEID_ATTR = 'data-cy-files-list-row-fileid'

/**
 * The row's name cell — preview icon plus filename. Both bind
 * `execDefaultAction`, so a click in here is the user asking to open the file.
 * Deliberately narrower than the whole row: the mime / size / mtime cells bind
 * `openDetailsIfAvailable` instead, and the 3-dot menu and checkbox open
 * nothing — none of those are "open this file".
 * Same markup in the list and grid views on NC 32/33/34.
 */
const ROW_NAME_CELL_SELECTOR = 'td.files-list__row-name'

/**
 * Folders render `<FolderIcon>` inside the row's icon slot; files render a
 * preview image or an inline mime SVG. Same in all three NC versions.
 */
const ROW_FOLDER_SELECTOR = '.files-list__row-icon .folder-icon'

/**
 * Event-bus events after which the sidebar may be showing a new file.
 *
 * `viewer:sidebar:open` only: the Viewer emits it whenever it (re)shows the
 * sidebar, including on next/prev navigation — and a file open in the Viewer is
 * exactly the case we want to be collaboration-first.
 *
 * `files:sidebar:opened` is deliberately NOT here. It also fires for the row's
 * `…` → Details gesture, which NC opens on the Sharing tab on purpose; hijacking
 * that to chat would override an explicit user intent. The two cases we do care
 * about are kicked off directly instead — once on attach (a URL-driven open) and
 * once per name-cell click (Hook A).
 */
const SIDEBAR_EVENTS = ['viewer:sidebar:open']

/**
 * How long to keep trying, and how often, after something suggests the sidebar
 * is (or is about to be) showing a file.
 *
 * A fixed short ladder is not enough. `iframe.onload` fires when the Files
 * *document* has loaded, which is well before its Vue app has fetched the
 * directory listing, populated `nodes` and run `openSidebarForFile` — and the
 * sidebar only lists Talk's tab once it has a node to ask about. On a cold
 * load of a large folder that comfortably exceeds three seconds.
 *
 * So: poll cheaply (a store read) until the chat tab is active, or until the
 * deadline. Bounded, so it can't leak.
 */
const POLL_INTERVAL = 400
const POLL_TIMEOUT = 15000

/**
 * How long to keep watching for the document to appear once the conversation
 * is up, and how often to look.
 *
 * v4.5.19 established that a **fixed delay cannot express this**: Nextcloud
 * Office loads an entire document server before its editor mounts, so any
 * stopwatch is either too short on a cold load or a pointless wait on a warm
 * one. That version replaced the stopwatch with a poll — but only on the
 * URL-driven path, in `AppEmbed.awaitFileRender()`. A file opened by clicking
 * a row inside the Files tab changes no URL that `AppEmbed` can see (Hook A
 * does a silent in-iframe router replace), so it never reached that code and
 * kept the 2500 ms guess until this version.
 */
const RENDER_POLL_INTERVAL = 300
const RENDER_POLL_TIMEOUT = 20000

/**
 * How many times one file may be told the sidebar is open.
 *
 * The Viewer's `files:sidebar:opened` handler fetches its sorting config, so
 * emits cost an HTTP request and are not free to spam. Three covers the real
 * sequence — nothing yet, the Viewer shell, then Office's editor inside it —
 * with one spare.
 */
const MAX_VIEWER_NUDGES = 3

/**
 * The two shapes a rendered document takes, kept apart so a change *between*
 * them can be noticed.
 *
 * Nextcloud Office does not go through the Viewer alone — it mounts its own
 * editor iframe — so a `#viewer` check reports that an Office document never
 * rendered, while a `#viewer`-only check reports success the moment the Viewer
 * shell appears, which is well before Office's async chunk has mounted inside
 * it. Watching both is what tells those two moments apart.
 */
const VIEWER_SELECTOR = '#viewer'
const OFFICE_EDITOR_SELECTOR = '#app > iframe[name="frameEditor"]'

/** "The file the user asked for is now on screen." */
export const RENDERED_FILE_SELECTOR = `${VIEWER_SELECTOR}, ${OFFICE_EDITOR_SELECTOR}`

/**
 * Which of the render shapes are present, as a comparable string.
 *
 * `''` is nothing on screen; `'v'` the Viewer; `'ve'` the Viewer with Office's
 * editor inside it. A *change* in this value is the signal — that is the
 * moment something newly mounted, and therefore the moment something new needs
 * telling about the sidebar.
 *
 * @param {Document} doc the iframe's document
 * @return {string}
 */
function renderSignature(doc) {
    try {
        return (doc.querySelector(VIEWER_SELECTOR) ? 'v' : '')
            + (doc.querySelector(OFFICE_EDITOR_SELECTOR) ? 'e' : '')
    } catch (e) {
        return ''
    }
}

/** Inert handle returned when there is nothing to attach to (cross-origin, no document). */
const NO_COLLAB = Object.freeze({ detach() {}, refresh() {}, nudgeViewer() {} })

/**
 * TeamHub's canonical "this is a small screen" test.
 *
 * Kept identical to the query in `App.vue` and `TeamView.vue` (`isMobile`) so
 * the conversation sidebar appears exactly where the rest of the app considers
 * itself to be on a desktop. Duplicated rather than imported because those are
 * component-local reactive flags and this module is plain JS — if the breakpoint
 * changes, change it in all three.
 *
 * @return {boolean}
 */
export function isNarrowViewport() {
    try {
        return !!window.matchMedia?.(
            '(max-width: 768px), (max-width: 1024px) and (orientation: portrait)',
        ).matches
    } catch (e) {
        return false
    }
}

/**
 * Strip the sidebar request from a file URL.
 *
 * The conversation sidebar is worse than useless on a phone: below its own
 * breakpoint Nextcloud renders the Files sidebar full-width, so it covers the
 * file the user asked to open. On a narrow viewport we open the file and stop.
 *
 * Applied when a URL is *used*, never when one is stored — `fileOpenUrl()` keeps
 * `opendetails` so that a link posted from a phone still opens the conversation
 * for whoever clicks it on a desktop.
 *
 * @param {string} url
 * @return {string}
 */
export function withoutSidebarRequest(url) {
    if (!url) {
        return url
    }
    return url.replace(/([?&])opendetails=[^&]*&?/, '$1').replace(/[?&]$/, '')
}

/**
 * Coerce a Nextcloud file id to a positive integer, or null.
 *
 * Every caller currently feeds a server-produced id (`FilesService::nodeToArray`
 * returns `$node->getId()`; the Files list renders `node.fileid`), so this is
 * defence in depth rather than a fix — but the value ends up interpolated into a
 * URL that becomes an iframe `src`, an `href`, and a router param, and a
 * non-numeric value there could smuggle extra path or query segments. Cheapest
 * possible way to remove that whole class of bug. See SKILLS.md § security
 * standards (input validation) and NC guideline 12 (defensive programming).
 *
 * @param {number|string} fileId
 * @return {number|null}
 */
function toFileId(fileId) {
    const id = Number(fileId)
    return Number.isSafeInteger(id) && id > 0 ? id : null
}

/**
 * Build the URL that opens a file in Nextcloud with its conversation showing.
 *
 * Single source of truth for the widgets, which feed the same URL to both the
 * `href` (ctrl-click → real new tab) and the in-app embed — so a new tab is
 * collaboration-first too.
 *
 * Folders get the bare URL: Talk registers its tab as files-only
 * (`enabled: ({ node }) => node.type === FileType.File`), so there is no
 * conversation to show and `opendetails` would only open an unwanted sidebar.
 *
 * @param {number|string} fileId Nextcloud numeric file id
 * @param {object} [options]
 * @param {boolean} [options.isFolder] true to skip the sidebar request
 * @return {string} the open URL, or '' when the id is not a usable file id
 */
export function fileOpenUrl(fileId, { isFolder = false } = {}) {
    const id = toFileId(fileId)
    if (id === null) {
        return ''
    }
    const base = generateUrl(`/f/${id}`)
    return isFolder ? base : `${base}?opendetails=true`
}

/**
 * Resolve the Files sidebar implementation inside a window, normalising the
 * NC 32 legacy global and the NC 33+ store behind one shape.
 *
 * @param {Window} win the iframe's window
 * @return {{ isOpen: () => boolean, hasChatTab: () => boolean, activeTab: () => string|undefined, setChatTab: () => void }|null}
 */
function resolveSidebar(win) {
    // ── NC 33+ — the @nextcloud/files ISidebar implementation ──────────────
    if (typeof win.OCA?.Files?._sidebar === 'function') {
        const api = win.OCA.Files._sidebar()
        return {
            isOpen: () => !!api.isOpen,
            hasChatTab: () => (api.currentTabs || []).some(tab => tab?.id === CHAT_TAB_ID),
            activeTab: () => api.activeTab,
            setChatTab: () => api.setActiveTab(CHAT_TAB_ID),
            node: () => api.currentNode,
        }
    }

    // ── NC 32 — legacy global; state lives under .state, open state is the
    //    presence of a file path ─────────────────────────────────────────────
    const legacy = win.OCA?.Files?.Sidebar
    if (legacy) {
        return {
            isOpen: () => !!legacy.file,
            hasChatTab: () => (legacy.state?.tabs || []).some(tab => tab?.id === CHAT_TAB_ID),
            activeTab: () => legacy.state?.activeTab,
            setChatTab: () => legacy.setActiveTab(CHAT_TAB_ID),
            // Legacy tracks a path, not a node. The only consumer of this
            // (the Viewer's sidebar handler) ignores the payload anyway.
            node: () => undefined,
        }
    }

    return null
}

/**
 * Switch the sidebar to Talk's chat tab.
 *
 * Returns false — and changes nothing — whenever the tab isn't there: Talk not
 * installed, the node is a folder, the admin disabled `conversations_files`, or
 * Talk refused to create a room because fewer than two users can reach the file
 * (its own guard, which single-member teams hit). Checking first also keeps us
 * clear of NC 33+'s `setActiveTab()`, which throws on an unavailable tab.
 *
 * @param {Window} win the iframe's window
 * @return {boolean} true when the chat tab is now active
 */
function activateChatTab(win) {
    const sidebar = resolveSidebar(win)
    if (!sidebar || !sidebar.isOpen() || !sidebar.hasChatTab()) {
        return false
    }
    if (sidebar.activeTab() === CHAT_TAB_ID) {
        return true
    }
    try {
        sidebar.setChatTab()
    } catch (e) {
        // NC 33+ throws when the tab isn't available for the current context.
        // hasChatTab() above should prevent it; treat a throw as "not ready".
        return false
    }
    return sidebar.activeTab() === CHAT_TAB_ID
}

/**
 * Tell a late-mounting Viewer that the sidebar is open.
 *
 * The Viewer subscribes to `files:sidebar:opened` in its `mounted()` hook and
 * shrinks itself to make room. But with `?openfile=true&opendetails=true` the
 * Files app starts the file action and opens the sidebar in the same tick, and
 * the sidebar's `@opened` fires after a ~300 ms CSS transition. A light handler
 * (image, plain text) is mounted and subscribed by then; a heavy one is not.
 *
 * **Nextcloud Office is the case that loses this race** — its Viewer component
 * is an async chunk, so richdocuments' bundle has to be fetched before the
 * component mounts. It misses the event, never learns the sidebar is open, and
 * renders full-width on top of it. The conversation is there; it is covered.
 *
 * Re-emitting NC's own event makes the Viewer re-measure. Idempotent, and a
 * no-op when no Viewer is on screen — which matters, because the Viewer's
 * handler fetches its sorting config, so this must not be emitted freely.
 *
 * @param {Window} win the iframe's window
 * @param {Document} doc the iframe's document
 * @return {boolean} true when an emit was made
 */
function nudgeViewerForSidebar(win, doc) {
    try {
        if (!doc.querySelector(RENDERED_FILE_SELECTOR)) {
            return false
        }
        const bus = win._nc_event_bus
        if (typeof bus?.emit !== 'function') {
            return false
        }
        bus.emit('files:sidebar:opened', resolveSidebar(win)?.node())
        return true
    } catch (e) {
        return false
    }
}

/**
 * Attach collaboration-first behaviour to a same-origin Files iframe.
 *
 * Installs two hooks and returns a `detach()` that removes both. Safe to call
 * on a frame that hasn't finished loading — the retries cover it — and safe to
 * call on a frame with no Files app in it, in which case nothing happens.
 *
 * @param {HTMLIFrameElement} iframe the Files iframe
 * @return {() => void} detach
 */
export function attachCollabSidebar(iframe) {
    let win
    let doc
    try {
        win = iframe?.contentWindow
        doc = iframe?.contentDocument || win?.document
    } catch (e) {
        // Cross-origin — nothing to attach. Callers guard on this too.
        return NO_COLLAB
    }
    if (!win || !doc) {
        return NO_COLLAB
    }

    // Traceable via the server's `loglevel_frontend` — this is the shared
    // logger, not throwaway console debugging, because every failure path here
    // is intentionally silent and otherwise indistinguishable from the old
    // behaviour. Keep these; they are the only way to tell "no conversation
    // exists" from "Talk moved its button".
    logger.debug('filesCollab: attached to the Files embed')

    let poll = null
    let pollDeadline = 0
    let sequenceDone = false
    let renderPoll = null
    let renderDeadline = 0
    let lastRenderSignature = ''
    let nudgeCount = 0
    let busUnsubscribe = null
    let rowClickHandler = null
    let detached = false

    const stopPolling = () => {
        if (poll !== null) {
            try { win.clearInterval(poll) } catch (e) {}
            poll = null
        }
    }

    const stopRenderWatch = () => {
        if (renderPoll !== null) {
            try { win.clearInterval(renderPoll) } catch (e) {}
            renderPoll = null
        }
    }

    /** Emit, against this file's budget. Returns true when one was spent. */
    const nudgeOnce = (reason) => {
        if (nudgeCount >= MAX_VIEWER_NUDGES) {
            return false
        }
        if (!nudgeViewerForSidebar(win, doc)) {
            return false
        }
        nudgeCount++
        logger.debug('filesCollab: told the document the sidebar is open', {
            reason,
            signature: lastRenderSignature,
            nudge: nudgeCount,
        })
        return true
    }

    /**
     * Watch for the document mounting, and tell it about the sidebar when it
     * does.
     *
     * Fires on a *change* of render signature rather than on a clock, because
     * the thing being waited for has no predictable duration — see
     * RENDER_POLL_INTERVAL. Office's editor appearing inside an already-present
     * Viewer shell is a change, which is the case the old fixed delay missed
     * whenever the document server took longer than 2.5 s to answer.
     *
     * Bounded three ways — the nudge budget, the deadline, and detach — so it
     * cannot outlive the embed or hammer the Viewer's handler.
     */
    const watchForRender = () => {
        stopRenderWatch()
        renderDeadline = Date.now() + RENDER_POLL_TIMEOUT
        renderPoll = win.setInterval(() => {
            if (detached) {
                stopRenderWatch()
                return
            }
            const signature = renderSignature(doc)
            if (signature !== lastRenderSignature) {
                lastRenderSignature = signature
                if (signature) {
                    nudgeOnce('mounted')
                }
            }
            if (nudgeCount >= MAX_VIEWER_NUDGES || Date.now() >= renderDeadline) {
                stopRenderWatch()
            }
        }, RENDER_POLL_INTERVAL)
    }

    /**
     * End the sequence successfully: the conversation tab is on screen.
     *
     * The document may not be. A light handler (image, plain text) is already
     * mounted and subscribed by now and only needs telling once; Office is
     * still fetching, and needs telling when it arrives. Both are covered by
     * nudging for whatever is on screen right now and then watching for
     * anything that mounts after.
     */
    const finishSequence = (message) => {
        sequenceDone = true
        stopPolling()
        logger.debug(message)
        nudgeCount = 0
        lastRenderSignature = renderSignature(doc)
        if (lastRenderSignature) {
            nudgeOnce('already on screen')
        }
        watchForRender()
    }

    // ── Hook B — switch the sidebar to the chat tab ───────────────────────
    //
    // Runs whenever something suggests the sidebar is showing a file. Setting
    // the tab needs the sidebar to have its node context, which arrives some
    // time after the document loads — so it is retried until it lands.
    //
    // It ends with the chat tab active and Talk's own "Join conversation"
    // button in front of the user. That click is theirs to make (see the
    // header): it is what joins the room and puts it in Talk's list.
    //
    // Latched: the sequence ends once per file. Restarting it (a different
    // file) resets the latch, because NC puts the sidebar back on its first
    // tab for every new node.
    const attempt = () => {
        if (detached || sequenceDone) {
            stopPolling()
            return
        }
        if (activateChatTab(win)) {
            finishSequence('filesCollab: conversation tab is showing for the current file')
            return
        }
        if (Date.now() >= pollDeadline) {
            // Gave up. Either the sidebar never opened, or it opened without a
            // chat tab (folder, Talk absent, `conversations_files` off, or too
            // few users can reach the file — Talk's own guard). The file is
            // open either way, which is exactly today's behaviour, so this is a
            // soft landing rather than an error.
            sequenceDone = true
            stopPolling()
            const sidebar = resolveSidebar(win)
            logger.debug('filesCollab: gave up waiting for the file conversation', {
                sidebarFound: !!sidebar,     // false → not the Files app
                sidebarOpen: !!sidebar?.isOpen(),
                chatTabAvailable: !!sidebar?.hasChatTab(),  // false → no conversation possible
            })
        }
    }

    const startChatSequence = () => {
        if (detached) {
            return
        }
        // Reset: a new file means the sidebar is back on its first tab, so a
        // sequence that already finished must be allowed to run again.
        //
        // The previous file's render watch stops here rather than running to
        // its deadline: its signature belongs to a document that is being
        // replaced, so any change it saw from here on would be the *new* file
        // mounting, reported against the old file's budget.
        stopRenderWatch()
        sequenceDone = false
        pollDeadline = Date.now() + POLL_TIMEOUT
        attempt()
        if (!sequenceDone && poll === null) {
            poll = win.setInterval(attempt, POLL_INTERVAL)
        }
    }

    /**
     * Clear Talk's leaked sidebar instance when the sidebar closes.
     *
     * Talk guards against two chat instances on one page with
     * `isOtherTalkInstanceMounted = !!window.OCA.Talk`, evaluated once when its
     * loader component is created, and it renders **Join conversation disabled**
     * when that is true. `window.OCA.Talk` is only cleaned up by
     * `unmountInstance()`, which Talk calls from a watcher on its tab's `active`
     * prop — so it runs when you switch tabs, but NOT when the sidebar closes:
     * closing drops `sidebar.hasContext`, which destroys the tab outright and
     * that watcher never sees `active` go false.
     *
     * Result upstream: join a file conversation, close the sidebar, open another
     * file — the button is greyed out and the conversation is unreachable. It is
     * a Talk bug, and since 4.9.20 it is the user's own Join click that arms
     * it — but every file we open lands them on that button, so a second file
     * after a joined one would still show it disabled without this.
     *
     * `unmountInstance()` is Talk's own cleanup and ends with
     * `delete window.OCA.Talk`, so calling it here is exactly what Talk would do
     * on a tab switch. Unconditional: the sidebar is closing, so any instance is
     * going away regardless, and checking the DOM first would race Vue's async
     * teardown and skip the clear in precisely the case that needs it.
     */
    const clearStaleTalkInstance = () => {
        try {
            if (!win.OCA?.Talk) {
                return
            }
            win.OCA.Talk.unmountInstance?.()
            logger.debug('filesCollab: released Talk\'s sidebar instance on close')
        } catch (e) {
            // Talk changed its cleanup contract — the join button may be
            // disabled on the next file, which is the pre-4.5.5 behaviour.
        }
    }

    // Re-run whenever the Viewer re-shows the sidebar — NC resets the active tab
    // to the first one on every new node, so moving to the next file in the
    // Viewer would otherwise drop the user back on Sharing. See SIDEBAR_EVENTS
    // for why `files:sidebar:opened` is not subscribed.
    try {
        const bus = win._nc_event_bus
        if (bus && typeof bus.subscribe === 'function') {
            SIDEBAR_EVENTS.forEach(name => bus.subscribe(name, startChatSequence))
            bus.subscribe('files:sidebar:closed', clearStaleTalkInstance)
            busUnsubscribe = () => {
                SIDEBAR_EVENTS.forEach(name => bus.unsubscribe(name, startChatSequence))
                bus.unsubscribe('files:sidebar:closed', clearStaleTalkInstance)
            }
        }
    } catch (e) {
        // No event bus — the initial pass below still covers a URL-driven open.
    }

    // Covers the URL-driven open: the frame was loaded with ?opendetails=true,
    // so the Files app will open the sidebar itself once its listing lands.
    // The poll waits for that.
    startChatSequence()

    // ── Hook A — make files clicked inside the Files tab open the sidebar ──
    //
    // Files opened by URL carry `?opendetails=true` (see fileOpenUrl), but a
    // click in the file list never touches the route: FileEntryMixin's
    // execDefaultAction() calls the default action straight through, and the
    // Viewer does not write `openfile` either. So there is nothing to observe
    // — we have to notice the click ourselves.
    //
    // We only read a file id off the clicked row and then add NC's own
    // `opendetails` route param. The Files app's openDetails watcher takes it
    // from there and resolves the node itself (openSidebarForFile), so we
    // never construct an INode. Capture phase, non-preventing: the Files app's
    // own click handling runs untouched and still opens the Viewer.
    rowClickHandler = (event) => {
        if (detached) {
            return
        }
        try {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
                return          // modified click — NC opens a real new tab
            }
            const nameCell = event.target?.closest?.(ROW_NAME_CELL_SELECTOR)
            if (!nameCell) {
                return          // not an "open this file" click
            }
            const row = nameCell.closest(ROW_SELECTOR)
            if (!row || row.querySelector(ROW_FOLDER_SELECTOR)) {
                // Not a file row, or a folder — folders have no conversation,
                // and forcing the sidebar open on every folder navigation
                // would be a regression.
                return
            }
            const fileId = toFileId(row.getAttribute(ROW_FILEID_ATTR))
            if (fileId === null) {
                return
            }

            const router = win.OCP?.Files?.Router
            if (!router) {
                return
            }
            router.goToRoute(
                null,
                { ...(router.params || {}), fileid: String(fileId) },
                { ...(router.query || {}), opendetails: 'true' },
                true,           // silent replace — no reload, no history entry
            )

            // Kick the sequence directly. When the sidebar was already open on
            // another file, `opendetails` is unchanged, so no sidebar event
            // fires — only the Files app's own fileId watcher runs. The early
            // attempts no-op until the sidebar has re-targeted; the retries
            // catch it.
            logger.debug('filesCollab: file opened from the list', { fileId })
            startChatSequence()
        } catch (e) {
            // Row markup or router shape changed — the file still opens, just
            // without the conversation.
        }
    }
    doc.addEventListener('click', rowClickHandler, true)

    return {
        detach() {
            detached = true
            stopPolling()
            stopRenderWatch()
            if (rowClickHandler) {
                try { doc.removeEventListener('click', rowClickHandler, true) } catch (e) {}
                rowClickHandler = null
            }
            if (busUnsubscribe) {
                try { busUnsubscribe() } catch (e) {}
                busUnsubscribe = null
            }
        },
        /**
         * Run the chat sequence again (v4.5.14).
         *
         * The initial pass covers the file the frame was loaded with. When a
         * later file is opened by routing the Files app in place there is no
         * new document, no `load` event and no sidebar-open transition to
         * listen for — so without this the second file would arrive without
         * its conversation.
         */
        refresh() {
            if (!detached) {
                startChatSequence()
            }
        },
        /**
         * Tell a viewer or editor that the sidebar is open, now (v4.5.19).
         *
         * For callers that can see the document appear — `AppEmbed` polls for
         * it on the URL-driven path and calls this when it lands.
         *
         * **Deliberately outside the nudge budget.** This is an external
         * caller reporting something it has observed, not a guess; dropping it
         * because an internal watcher had already spent the budget on the same
         * file would silently break the path that has worked since v4.5.19.
         * The budget exists to stop the *polling* from spamming an HTTP-backed
         * handler, and that reason does not apply here.
         */
        nudgeViewer() {
            if (!detached) {
                nudgeViewerForSidebar(win, doc)
            }
        },
    }
}
