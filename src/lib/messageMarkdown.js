/**
 * The team-message markdown renderer.
 *
 * Moved out of `MessageCard.vue` in v4.6.25, unchanged, so more than one
 * surface can render a message body without a second copy of the pipeline.
 * The message stream was the only consumer; "What's new" is the second, and a
 * feed card that re-implemented this would have been the third sanitiser in
 * the app (`CommentsSection.vue` still carries its own, with a deliberately
 * narrower allowlist — see the note at the bottom of this file).
 *
 * The output is sanitised HTML intended for `v-html`. Two layers make that
 * safe and both must stay:
 *
 *   1. The regex renderer only ever emits tags from ALLOWED_TAGS.
 *   2. DOMPurify drops anything else regardless, and an `afterSanitizeAttributes`
 *      hook is the single point that decides what an <img> src may become.
 *
 * Layer 2 is what actually holds. Layer 1 is defence in depth: a future regex
 * change that widened the output would be caught rather than shipped.
 */
import DOMPurify from 'dompurify'
import { generateUrl } from '@nextcloud/router'
import { resolveMentionToken } from './mentions.js'

// Tags and attributes that our regex renderer intentionally produces.
// (img is allowed; its src is constrained by the hook registered below.)
// DOMPurify drops everything not on these lists — defence-in-depth even if
// a future regex change accidentally widens the output.
const ALLOWED_TAGS = ['strong', 'em', 'code', 'pre', 'a', 'br', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'span', 'img']
const ALLOWED_ATTR = ['href', 'target', 'rel', 'class', 'data-mention-user', 'src', 'alt', 'width', 'loading', 'decoding', 'referrerpolicy', 'tabindex', 'role']

// Path of the TeamHub backend image proxy. Every REMOTE image src is rewritten
// to point here so the viewer's browser never hits the third-party host directly
// (no IP leak / tracking-pixel surface; satisfies NC's img-src CSP).
const IMAGE_PROXY_PATH = generateUrl('/apps/teamhub/api/v1/preview/image')

/**
 * Escape a string for safe inclusion inside a double-quoted HTML attribute.
 * DOMPurify is still the authoritative sanitizer; this just prevents the
 * markdown layer from emitting attribute-breaking characters.
 */
function attrEscape(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
}

/**
 * Decide what an <img> src is allowed to become, or null to drop the image.
 *
 * Three outcomes:
 *   - Remote https:// URL        → rewrite to the proxy path (?url=<encoded>)
 *   - Same-origin NC path/URL     → pass through unchanged (uploads, previews)
 *   - Anything else (data:, http:,
 *     javascript:, other origins) → null  (image is removed by the sanitizer)
 *
 * The sanitizer hook (below) is the single point that enforces this — the
 * markdown regex never emits a raw remote src, but we still re-derive the safe
 * src here so a future regex change can't widen what actually renders.
 */
function safeImageSrc(rawSrc) {
    if (!rawSrc) return null

    // Already proxied by us — accept as-is (avoids double-encoding on re-render/edit).
    if (rawSrc.startsWith(IMAGE_PROXY_PATH)) {
        return rawSrc
    }

    // Same-origin: relative path, or an absolute URL whose origin matches the page.
    // These are NC-served (uploaded images, share previews) and need no proxy.
    if (rawSrc.startsWith('/')) {
        // Reject protocol-relative '//evil.com/x' (starts with '/' but is cross-origin).
        if (rawSrc.startsWith('//')) return null
        return rawSrc
    }
    try {
        const u = new URL(rawSrc, window.location.origin)
        if (u.origin === window.location.origin) {
            return u.pathname + u.search
        }
        // Remote: only https is proxied. http/ftp/data/javascript are dropped.
        if (u.protocol === 'https:') {
            return IMAGE_PROXY_PATH + '?url=' + encodeURIComponent(u.href)
        }
    } catch (e) {
        return null
    }
    return null
}

// One-time DOMPurify hook: enforce the image src policy and harden every <img>.
// Runs after DOMPurify has parsed attributes, on every sanitize() call. Because
// it is the only place that sets the final src, no raw remote/data/http URL can
// reach the DOM even if the markdown layer is later changed.
let imageHookRegistered = false
function ensureImageHook() {
    if (imageHookRegistered) return
    imageHookRegistered = true
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.nodeName !== 'IMG') return

        const safe = safeImageSrc(node.getAttribute('src'))
        if (safe === null) {
            // Disallowed source — remove the element entirely.
            node.remove()
            return
        }
        node.setAttribute('src', safe)

        // Clamp width to a sane integer (1–2000). Drop anything non-numeric.
        const w = parseInt(node.getAttribute('width'), 10)
        if (Number.isFinite(w) && w >= 1 && w <= 2000) {
            node.setAttribute('width', String(w))
        } else {
            node.removeAttribute('width')
        }

        // Guarantee a frame class, lazy loading, and async decode.
        node.setAttribute('class', 'teamhub-inline-image')
        node.setAttribute('loading', 'lazy')
        node.setAttribute('decoding', 'async')
        // Never let an inline image carry a referrer to the (proxied) origin.
        node.setAttribute('referrerpolicy', 'no-referrer')
        // Keyboard-accessible: focusable and announced as an activatable control
        // (Enter opens the lightbox, handled by the delegated body listener).
        node.setAttribute('tabindex', '0')
        node.setAttribute('role', 'button')
    })
}

/**
 * Convert a subset of Markdown to sanitised HTML.
 *
 * @param {string} text - raw message body
 * @param {Object} membersMap - optional { [userId]: displayName } for @mention rendering
 * @return {string} sanitised HTML, safe for v-html
 */
export function renderMarkdown(text, membersMap = {}) {
    if (!text) return ''

    // 1. Fenced code blocks
    const codeBlocks = []
    let html = text.replace(/```([\s\S]+?)```/g, (_, code) => {
        codeBlocks.push(`<pre><code>${code}</code></pre>`)
        return `\u0000${codeBlocks.length - 1}\u0000`
    })

    // 2. Inline code
    const inlineCodes = []
    html = html.replace(/`([^`]+)`/g, (_, code) => {
        inlineCodes.push(`<code>${code}</code>`)
        return `\u0001${inlineCodes.length - 1}\u0001`
    })

    // 3. @mentions — convert @userId to a styled mention span with display name.
    //
    // v4.5.27 — was `/@([a-zA-Z0-9._-]+)/g`, which cannot see a uid containing
    // an '@'. On an instance whose usernames are e-mail addresses that is every
    // uid: `@JDoek@aaenhunze.nl` rendered as two broken mention spans, neither
    // resolving to a name. The token is now everything up to whitespace, and
    // resolveMentionToken() decides where the mention actually ends by matching
    // against the members we know about (longest wins) — so `@jdoek.nl,` wraps
    // the uid and leaves the comma as text.
    html = html.replace(/(?=[a-z0-9_\-@.'])\B@([a-z0-9_\-@.']+)/gi, (match, token) => {
        const userId = resolveMentionToken(token, membersMap)
        if (!userId) {
            // Not a member we know — leave the text exactly as written rather
            // than styling an arbitrary word as a mention.
            return match
        }
        const displayName = membersMap[userId] || userId
        const rest = token.slice(userId.length)
        return `<span class="teamhub-mention" data-mention-user="${userId}">@${displayName}</span>${rest}`
    })

    // 4. Bold and italic
    html = html
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        .replace(/_([^_]+)_/g, '<em>$1</em>')

    // 4b. Inline images — ![alt](url) and ![alt|320](url) (optional width).
    //     Emitted BEFORE the link rule so ![..](..) is not eaten by [..](..),
    //     AND stashed behind a placeholder so the bare-URL autolinker in step 5
    //     can't match the https:// inside the emitted src="..." attribute.
    //     (That corruption is exactly what broke v3.58.0.) src/alt are
    //     attribute-escaped; the final src policy + width clamp are enforced in
    //     the DOMPurify hook (safeImageSrc) — a src the hook rejects (data:,
    //     http:, cross-origin) is dropped entirely at sanitize time.
    const imageTags = []
    html = html.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, altRaw, urlRaw) => {
        let alt = altRaw
        let widthAttr = ''
        // Optional "|<width>" suffix inside the alt segment: ![alt|320](url)
        const pipe = altRaw.lastIndexOf('|')
        if (pipe !== -1) {
            const maybeWidth = altRaw.slice(pipe + 1).trim()
            if (/^\d{1,4}$/.test(maybeWidth)) {
                alt = altRaw.slice(0, pipe)
                widthAttr = ` width="${maybeWidth}"`
            }
        }
        const safeAlt = attrEscape(alt.trim())
        const safeUrl = attrEscape(urlRaw)
        imageTags.push(`<img src="${safeUrl}" alt="${safeAlt}"${widthAttr} />`)
        return `\u0002${imageTags.length - 1}\u0002`
    })

    // 5. Links — explicit [text](url) then bare https?:// URLs
    html = html
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/(?<!href=")(?<!\()https?:\/\/[^\s<>"'\)]+/g, '<a href="$&" target="_blank" rel="noopener noreferrer">$&</a>')

    // 6a. Headings
    html = html
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')

    // 6b. Bullet lists
    html = html.replace(/((?:^- .+(?:\n|$))+)/gm, (block) => {
        const items = block.trimEnd().split('\n')
            .map(line => `<li>${line.replace(/^- /, '')}</li>`)
            .join('')
        return `<ul>${items}</ul>\n`
    })

    // 7. Remaining newlines → <br>
    html = html.replace(/\n/g, '<br>')

    // 8. Restore code + image placeholders
    html = html
        .replace(/\u0000(\d+)\u0000/g, (_, i) => codeBlocks[+i])
        .replace(/\u0001(\d+)\u0001/g, (_, i) => inlineCodes[+i])
        .replace(/\u0002(\d+)\u0002/g, (_, i) => imageTags[+i])

    // 9. Sanitize
    ensureImageHook()
    return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR })
}

/**
 * Why `CommentsSection.vue` is not on this module yet.
 *
 * It renders comments through its own smaller pipeline with a narrower
 * allowlist — no images, no headings — which is a deliberate difference, not
 * drift: a comment is a reply, and a reply that can post an H1 or an image
 * changes the shape of the thread it sits in. Folding it in here means either
 * widening what a comment may contain or giving this function an options
 * argument, and that is a product decision rather than a refactor.
 */
