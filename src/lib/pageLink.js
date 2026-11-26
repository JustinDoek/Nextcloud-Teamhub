/**
 * The address of the page a person is on, for a quick task — v4.20.29.
 *
 * TeamHub's own address bar never changes: switching team or tab is store
 * state, not navigation. So "save the page link" cannot read
 * `window.location`; it has to say in a URL what the store says about where
 * the person is, in the deep-link form `App.vue`'s `consumeDeepLink()` reads
 * back:
 *
 *   ?mywork | ?services | ?feed | ?browse          a page across teams
 *   ?team=<id>                                      a team's Home
 *   ?team=<id>&tab=<key>                            one of its tabs
 *   ?team=<id>&tab=files&open=<path>                an embedded app, at the
 *                                                   page it was showing
 *   ?team=<id>&message=<id>                         one message and its thread
 *                                                   (v4.20.30, a follow-up task)
 *
 * `open` is only ever a path inside the embedded app the tab hosts (Files,
 * Deck, Wiki), checked when the link is built and again when it is opened, so
 * a link cannot make a tab load somebody else's page.
 *
 * Pure: no `@nextcloud/router` in here, so it runs under `node --test`. The
 * caller passes the app's base URL and the web root.
 */

/** Embedded apps whose current page a link may carry, by the tab that hosts them. */
export const EMBED_PREFIXES = Object.freeze({
    files:       '/apps/files/',
    deck:        '/apps/deck/',
    collectives: '/apps/collectives/',
})

/** Longest link we build or accept. `TaskService::MAX_LINK_LENGTH` is 2000. */
export const MAX_PAGE_LINK = 1800

/** Pages across teams, and the bare query word that opens each. */
const PAGE_WORDS = Object.freeze(['mywork', 'services', 'feed', 'browse'])

const TAB_KEY = /^[A-Za-z0-9_.:-]{1,80}$/
// eslint-disable-next-line no-control-regex
const UNSAFE = /[\s\\\u0000-\u001f\u007f]/

/**
 * A path into this instance: one leading slash, no `//host`, no whitespace,
 * backslash or control character.
 *
 * @param {unknown} path
 * @return {boolean}
 */
export function isSafePath(path) {
    return typeof path === 'string'
        && path.length > 0
        && path.length <= MAX_PAGE_LINK
        && path.startsWith('/')
        && !path.startsWith('//')
        && !UNSAFE.test(path)
}

/**
 * The embedded app's own path, when it is one the tab may carry.
 *
 * @param {string} tab  the tab key (`files`, `deck`, `collectives`)
 * @param {unknown} path the frame's pathname + search
 * @param {string} root  the web root (`getRootUrl()`), '' at the domain root
 * @return {string|null}
 */
export function embedPathFor(tab, path, root = '') {
    const prefix = EMBED_PREFIXES[tab]
    if (!prefix || !isSafePath(path)) {
        return null
    }
    let rest = root && path.startsWith(root + '/') ? path.slice(root.length) : path
    if (rest.startsWith('/index.php/')) {
        rest = rest.slice('/index.php'.length)
    }
    return rest.startsWith(prefix) ? path : null
}

/**
 * The deep-link query for a place in the app, without the `?`.
 *
 * @param {object} place
 * @param {string} place.view      the App view: `team`, `mywork`, `services`, `feed`, `browse`
 * @param {string} [place.teamId]
 * @param {string} [place.tab]     the team tab key; Home (`msgstream`) is left out
 * @param {string} [place.embedPath] the embedded app's path, from `embedPathFor()`
 * @param {number} [place.messageId] v4.20.30: one message, for a follow-up
 *   task on it or on one of its comments. Wins over `tab`: the message lives
 *   on Home, where `consumeDeepLink()` scrolls to it.
 * @return {string}
 */
export function pageQuery(place = {}) {
    if (PAGE_WORDS.includes(place.view)) {
        return place.view
    }
    if (place.view !== 'team' || !place.teamId) {
        return ''
    }
    const params = new URLSearchParams()
    params.set('team', place.teamId)
    const messageId = Number(place.messageId)
    if (Number.isInteger(messageId) && messageId > 0) {
        params.set('message', String(messageId))
        return params.toString()
    }
    if (place.tab && place.tab !== 'msgstream' && TAB_KEY.test(place.tab)) {
        params.set('tab', place.tab)
        if (place.embedPath) {
            params.set('open', place.embedPath)
        }
    }
    return params.toString()
}

/**
 * The full link, or '' when the place has no address (a create form, a
 * settings page).
 *
 * @param {string} appBase `generateUrl('/apps/teamhub/')`
 * @param {object} place   see `pageQuery()`
 * @return {string}
 */
export function buildPageLink(appBase, place) {
    const query = pageQuery(place)
    if (!query) {
        return ''
    }
    const link = `${appBase}?${query}`
    return link.length <= MAX_PAGE_LINK ? link : `${appBase}?${pageQuery({ ...place, embedPath: '' })}`
}

/**
 * What `consumeDeepLink()` may do with `tab` and `open`: the tab key when it
 * is well formed, and the embedded path only when it belongs to that tab.
 *
 * @param {URLSearchParams} params
 * @param {string} root the web root
 * @return {{tab: string|null, open: string|null}}
 */
export function readTabLink(params, root = '') {
    const tab = params.get('tab')
    if (!tab || !TAB_KEY.test(tab)) {
        return { tab: null, open: null }
    }
    return { tab, open: embedPathFor(tab, params.get('open'), root) }
}
