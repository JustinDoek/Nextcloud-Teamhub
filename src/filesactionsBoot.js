/**
 * TeamHub's actions inside the Nextcloud Files app ⋯ menu — "Request review"
 * (v4.8.18) and "Propose a decision" (v4.11.42).
 *
 * Stage two of the file-action entry (v4.10.11). `filesactions.js` — the entry
 * `FilesScriptsListener` puts on the Files page — is a stub that waits until
 * the Files app has loaded and the page has gone idle, then imports this
 * module. Only here do `@nextcloud/files`, axios and the l10n runtime get
 * evaluated. Its job:
 *
 *   1. learn, once, which folders belong to the user's teams, and what each
 *      team allows;
 *   2. offer those actions in a file's ⋯ menu when it sits in one;
 *   3. mount the review modal when that one is clicked (stage three,
 *      `filesactionsModal.js`, loaded on the click), or open TeamHub's
 *      proposal composer in a new tab.
 *
 * ## Why only one of the two opens a dialog here
 *
 * A review request is a small form of its own: who, by when, a sentence. A
 * proposal is not — `ComposeDecisionModal` is the single entry point for
 * proposing a decision (a second one was deliberately removed in v4.5.42) and
 * it needs the team's categories, approvers, members, Talk room and milestone
 * list, all of which live in TeamHub's Vuex store. Rebuilding a thinner
 * version of it here would either re-implement all of that on a page that has
 * none of it, or create proposals that behave unlike every other proposal.
 * So the entry hands the job to the real composer: a new tab at
 * `?team=…&propose=1&file=…`, which `App.vue`'s deep-link reader turns into
 * the open modal with the file linked in its Context field.
 *
 * A new tab rather than a navigation, in both directions: the Files app also
 * runs inside an iframe on a team's Files tab, where navigating would load
 * TeamHub inside TeamHub, and in the standalone app it would throw away
 * whatever the user was browsing.
 *
 * ## Why registering late is safe
 *
 * `registerFileAction()` pushes onto the registry the Files app reads when it
 * builds a row's ⋯ menu, so an action added after the list has rendered is in
 * the menu the next time one opens. Nothing here has to beat the Files app to
 * the page.
 *
 * ## Why the scopes are fetched up front
 *
 * `IFileAction.enabled()` is **synchronous**. It cannot ask the server whether
 * the file under the cursor is in a team folder, so the answer has to already
 * be in memory when the menu opens. One request on boot buys a pure
 * string-prefix test for every file afterwards.
 *
 * The cost is one small GET per Files page load for every user, including users
 * in no team at all — they get `[]` and no action ever appears. That is the
 * price of putting the entry point in the Files app's own menu rather than in a
 * TeamHub toolbar, and it is paid on a page TeamHub is otherwise absent from,
 * so it is worth knowing about: `FilesScriptsListener` does not even register
 * this script when both modules are switched off globally, and **one** call
 * answers for both actions — a second endpoint would double this cost.
 *
 * ## Why the modal is mounted here rather than handed to TeamHub
 *
 * The Files app runs inside an iframe on a team's Files tab, and standalone at
 * /apps/files. Mounting our own modal works identically in both, with no
 * postMessage bridge between frame and parent and nothing for `AppEmbed` to
 * know about. The alternative — telling the TeamHub shell to open its own
 * modal — would only work in one of the two places.
 *
 * ## Why the modal is a dynamic import (v4.10.11)
 *
 * The entry runs on **every** Files page load, for every user, and until
 * 4.10.11 it statically imported Vue, `@nextcloud/vue` and the modal — which
 * made Rollup hand it the app's 2.5 MB shared vendor chunk plus 285 KB of
 * component CSS. That is a second copy of Vue, parsed and executed on a page
 * that already has NC's own, before the file list is interactive, to offer one
 * menu entry that most page loads never click. Inside TeamHub's own Files tab
 * it was the single largest thing we added to the frame's boot.
 *
 * The rule now: **this module imports nothing heavier than `@nextcloud/files`.**
 * The dialog and everything it needs live in `filesactionsModal.js` and arrive
 * on the first click (Vite's preload helper brings the chunk's CSS with it).
 * Rollup groups modules by the set of entries that reach them, so keeping
 * `vue` out of this module's static graph is what keeps the vendor chunk out
 * of the Files page — one stray `import … from 'vue'` here undoes the whole
 * thing.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { registerFileAction } from '@nextcloud/files'

import logger from './logger.js'

/**
 * mdi:file-eye-outline — the same glyph the My Work provider uses, so a review
 * looks like the same thing in both places. Inline because `iconSvgInline`
 * takes markup, not a component.
 */
const REVIEW_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h5.81c-.35-.61-.61-1.29-.71-2H6V4h7v5h5v1.08c.71.1 1.38.36 2 .72V8l-6-6m3.5 11c-2.8 0-5.2 1.73-6.2 4.19C12.3 19.65 14.7 21 17.5 21s5.2-1.35 6.2-3.81c-1-2.46-3.4-4.19-6.2-4.19m0 6.5a2.5 2.5 0 0 1-2.5-2.5 2.5 2.5 0 0 1 2.5-2.5 2.5 2.5 0 0 1 2.5 2.5 2.5 2.5 0 0 1-2.5 2.5m0-4a1.5 1.5 0 0 0-1.5 1.5 1.5 1.5 0 0 0 1.5 1.5 1.5 1.5 0 0 0 1.5-1.5 1.5 1.5 0 0 0-1.5-1.5Z" />'
	+ '</svg>'

/**
 * mdi:gavel — the glyph the Decisions module uses everywhere else
 * (`TeamDecisionsView`, the widget, the My Work rows), so a proposal looks
 * like the same thing started from the Files app as from inside TeamHub.
 */
const PROPOSE_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M2.3,20.28L11.9,10.68L10.5,9.26L9.78,9.97C9.39,10.36 8.76,10.36 8.37,9.97L7.66,9.26C7.27,8.87 7.27,8.24 7.66,7.85L13.32,2.19C13.71,1.8 14.34,1.8 14.73,2.19L15.44,2.9C15.83,3.29 15.83,3.92 15.44,4.31L14.73,5L16.15,6.43C16.54,6.04 17.17,6.04 17.56,6.43C17.95,6.82 17.95,7.46 17.56,7.85L18.97,9.26L19.68,8.55C20.07,8.16 20.71,8.16 21.1,8.55L21.8,9.26C22.19,9.65 22.19,10.29 21.8,10.68L16.15,16.33C15.76,16.72 15.12,16.72 14.73,16.33L14.03,15.63C13.63,15.24 13.63,14.6 14.03,14.21L14.73,13.5L13.32,12.09L3.71,21.7C3.32,22.09 2.69,22.09 2.3,21.7C1.91,21.31 1.91,20.67 2.3,20.28M20,19A2,2 0 0,1 22,21V22H12V21A2,2 0 0,1 14,19H20Z" />'
	+ '</svg>'

/**
 * Team folder prefixes for this user, filled once on boot.
 *
 * Starts empty and stays empty on failure, which is the safe direction: the
 * action simply never offers itself. An error here must never make the Files
 * app look broken — TeamHub is a guest on this page.
 *
 * @type {Array<{teamId: string, teamName: string, paths: string[], actions: string[]}>}
 */
let scopes = []

/**
 * Which of the user's teams offers `action` on this path?
 *
 * Returns the scope rather than a boolean because the action needs the team id
 * it matched on: a proposal is proposed *in a team*, and the file's folder is
 * what says which one.
 *
 * The trailing slash on both sides is load-bearing: a plain `startsWith` would
 * claim `/Team Alpha Archive/x` for the team folder `/Team Alpha`.
 *
 * @param {string} path root-relative node path, e.g. `/Team Alpha/report.odt`
 * @param {string} action `review` or `propose`
 * @return {{teamId: string, teamName: string, paths: string[], actions: string[]}|null}
 *         the first matching scope, or null
 */
function scopeFor(path, action) {
	if (!path) {
		return null
	}
	return scopes.find(scope => scope.actions.includes(action) && scope.paths.some(folder => {
		return path === folder || path.startsWith(folder.replace(/\/$/, '') + '/')
	})) || null
}

/**
 * Is this node a single file we can act on at all?
 *
 * Both actions are about one document: a folder or a multi-selection has no
 * answer to "who should review this" or "what are we deciding". Synchronous by
 * the `IFileAction.enabled()` contract, which is also why the team-folder test
 * next to it works off the scopes fetched on boot.
 *
 * @param {object[]} nodes the action context's selected nodes
 * @return {object|null} the single file node, or null
 */
function singleFile(nodes) {
	if (!Array.isArray(nodes) || nodes.length !== 1) {
		return null
	}
	const node = nodes[0]
	// `type` is 'file' | 'folder' in @nextcloud/files.
	if (!node || node.type !== 'file' || !node.fileid) {
		return null
	}
	return node
}

/**
 * Open the request modal for one file, loading it first if this is the first
 * click on the page.
 *
 * The chunk (Vue, the NC components, the dialog and its CSS) is fetched once
 * and stays in the module cache, so only the first open pays the load. A
 * failed load is reported to the console and swallowed: a TeamHub fault must
 * not put an error toast on somebody's Files page.
 *
 * @param {object} node the INode the action was invoked on
 * @return {Promise<void>}
 */
async function openModal(node) {
	let mod
	try {
		mod = await import('./filesactionsModal.js')
	} catch (error) {
		logger.warn('TeamHub: could not load the file review dialog', { error })
		return
	}
	mod.openRequestReviewModal(node)
}

/**
 * Open TeamHub's proposal composer for one file, in a new tab.
 *
 * The deep link is read by `App.vue`'s `consumeDeepLink()`, which selects the
 * team, switches to its Decisions tab and opens `ComposeDecisionModal` with a
 * markdown link to the file in the Context field. Only the ids travel: the
 * file's **name** is fetched from the server on the other side, because it
 * ends up inside markdown and a name carried in a URL is a name anybody can
 * choose.
 *
 * `noopener` because the new tab has no business reaching back into the Files
 * page, and a blocked popup is not reported: the click opened nothing, which
 * the user can see, and a toast on somebody's Files page is a cost TeamHub
 * does not get to impose for its own feature.
 *
 * @param {object} node the INode the action was invoked on
 * @param {string} teamId the team whose folder contains it
 */
function openProposalTab(node, teamId) {
	const url = generateUrl('/apps/teamhub/')
		+ '?team=' + encodeURIComponent(teamId)
		+ '&propose=1&file=' + encodeURIComponent(String(node.fileid))
	window.open(url, '_blank', 'noopener')
}

/**
 * Register both actions, once the scopes are known.
 *
 * Registration itself is unconditional when the user has at least one team
 * folder that allows something; the per-file decision is each action's own
 * `enabled()`. Per CLAUDE.md § Permissions an entry is **hidden** where it does
 * not apply rather than shown and disabled — which is also why both are
 * registered unconditionally and neither is registered as disabled.
 */
function register() {
	registerFileAction({
		id: 'teamhub-request-review',

		// TRANSLATORS: entry in a file's ⋯ menu — ask teammates to review this file
		displayName: () => t('teamhub', 'Request review'),

		iconSvgInline: () => REVIEW_ICON_SVG,

		/**
		 * Synchronous by contract — see `scopeFor()`.
		 *
		 * @param {object} context the action context
		 * @param {object[]} context.nodes selected nodes
		 * @return {boolean} whether to show the entry
		 */
		enabled: ({ nodes }) => {
			const node = singleFile(nodes)

			return node !== null && scopeFor(node.path, 'review') !== null
		},

		/**
		 * @param {object} context the action context
		 * @param {object[]} context.nodes the single selected node
		 * @return {Promise<null>} null — the action opens a dialog rather than
		 *                         succeeding or failing on its own
		 */
		exec: async ({ nodes }) => {
			await openModal(nodes[0])
			// null means "silent": the Files app shows no success toast for
			// merely opening a dialog. The modal reports its own outcome.
			return null
		},

		order: 30,
	})

	// v4.11.42 — the second entry. Registered separately rather than as a
	// submenu: two unrelated things to start, each hidden where its own module
	// is off, and a team may well have one without the other.
	registerFileAction({
		id: 'teamhub-propose-decision',

		// TRANSLATORS: entry in a file's ⋯ menu — start a TeamHub decision proposal about this file
		displayName: () => t('teamhub', 'Propose a decision'),

		iconSvgInline: () => PROPOSE_ICON_SVG,

		/**
		 * @param {object} context the action context
		 * @param {object[]} context.nodes selected nodes
		 * @return {boolean} whether to show the entry
		 */
		enabled: ({ nodes }) => {
			const node = singleFile(nodes)

			return node !== null && scopeFor(node.path, 'propose') !== null
		},

		/**
		 * @param {object} context the action context
		 * @param {object[]} context.nodes the single selected node
		 * @return {Promise<null>} null — silent, like the review entry: opening
		 *                         the composer is not an outcome to report
		 */
		exec: async ({ nodes }) => {
			const node = singleFile(nodes)
			// `enabled()` already said yes, so this is the between-render case:
			// re-checked rather than assumed, because the scope carries the team
			// id the link needs and there is nothing to open without it.
			const scope = node === null ? null : scopeFor(node.path, 'propose')
			if (scope === null) {
				return null
			}
			openProposalTab(node, scope.teamId)

			return null
		},

		order: 31,
	})
}

/**
 * Boot: ask the server what this user's team folders are, then register.
 *
 * Everything is best-effort. A user in no team, a failed request, or the module
 * being switched off all end the same way — no action, no error on screen, and
 * a Files app that behaves exactly as if TeamHub were not installed.
 *
 * Called once by the `filesactions.js` stub; exported rather than run on
 * import so the stub decides *when*, which is the whole point of the split.
 */
export async function boot() {
	try {
		const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/file-actions/scopes'))
		scopes = (Array.isArray(data?.scopes) ? data.scopes : [])
			// A scope with no actions, or one from a server that predates them,
			// matches nothing rather than matching everything.
			.filter(scope => Array.isArray(scope?.actions) && scope.actions.length > 0)
	} catch (error) {
		logger.debug('TeamHub: file action scopes unavailable', { error })
		return
	}

	if (scopes.length === 0) {
		return
	}

	try {
		register()
	} catch (error) {
		// A duplicate registration (the script loaded twice) or an API change
		// lands here. Reported to the console, never to the user: a TeamHub
		// fault must not put an error toast on somebody's Files page.
		logger.warn('TeamHub: could not register the file actions', { error })
	}
}
