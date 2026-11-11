/**
 * The in-app announcement markdown renderer.
 *
 * Moved out of `AnnouncementView.vue` in v4.13.56, unchanged, because the
 * forced popup for Nextcloud administrators renders the same bodies and a
 * second copy of this would have been the **fourth** markdown sanitiser in
 * the app. `messageMarkdown.js` carries the same note for the same reason;
 * `CommentsSection.vue` still has its own, with a deliberately narrower
 * allowlist.
 *
 * The output is sanitised HTML intended for `v-html`. Both steps are
 * required and both must stay: `marked` does not sanitise, and rendering
 * unsanitised HTML from any source — the app's own `announcements/` folder
 * included — is the pattern CLAUDE.md § Vue keeps out.
 */
import { marked } from 'marked'
import DOMPurify from 'dompurify'

// Configure marked once: GitHub-flavoured shorthand plus hard line breaks, so
// an author can format an announcement the way they would a release note.
marked.setOptions({ gfm: true, breaks: true })

/**
 * Sanitiser config: only the tags an announcement realistically needs.
 *
 * Deliberately excludes `<iframe>`, `<object>`, `<script>`, `<style>`, every
 * event handler, and any URL scheme other than http(s) and mailto. Images are
 * **not** on the list: unlike a team message there is no proxy path for an
 * announcement body, and a remote `<img>` in one would be a tracking pixel
 * fired on every unlicensed instance that renders it.
 */
export const ANNOUNCEMENT_SANITIZE_CONFIG = {
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

/**
 * Render an announcement body to sanitised HTML.
 *
 * @param {?string} body the markdown source, or null/'' before it loads
 * @return {string} sanitised HTML, '' when there is nothing to render
 */
export function renderAnnouncement(body) {
	if (body === null || body === undefined || body === '') {
		return ''
	}
	return DOMPurify.sanitize(marked.parse(String(body)), ANNOUNCEMENT_SANITIZE_CONFIG)
}
