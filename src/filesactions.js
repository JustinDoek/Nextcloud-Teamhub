/**
 * TeamHub's "Request review" action inside the Nextcloud Files app (v4.8.18).
 *
 * This is the app's fourth build entry, and the first one that runs on a page
 * TeamHub does not render. It is loaded by `FilesScriptsListener` on the Files
 * app's `LoadAdditionalScriptsEvent`, and its whole job is:
 *
 *   1. learn, once, which folders belong to the user's teams;
 *   2. offer "Request review" in a file's ⋯ menu when it sits in one;
 *   3. mount the request modal when it is clicked.
 *
 * ## Why the scopes are fetched up front
 *
 * `IFileAction.enabled()` is **synchronous**. It cannot ask the server whether
 * the file under the cursor is in a team folder, so the answer has to already
 * be in memory when the menu opens. One request on boot buys a pure
 * string-prefix test for every file afterwards.
 *
 * The cost is one small GET per Files page load for every user, including users
 * in no team at all — they get `[]` and the action never appears. That is the
 * price of putting the entry point in the Files app's own menu rather than in a
 * TeamHub toolbar, and it is paid on a page TeamHub is otherwise absent from,
 * so it is worth knowing about: `FilesScriptsListener` does not even register
 * this script when the module is switched off globally.
 *
 * ## Why the modal is mounted here rather than handed to TeamHub
 *
 * The Files app runs inside an iframe on a team's Files tab, and standalone at
 * /apps/files. Mounting our own modal works identically in both, with no
 * postMessage bridge between frame and parent and nothing for `AppEmbed` to
 * know about. The alternative — telling the TeamHub shell to open its own
 * modal — would only work in one of the two places.
 */

import { createApp } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { registerFileAction } from '@nextcloud/files'

import RequestFileReviewModal from './components/RequestFileReviewModal.vue'
import logger from './logger.js'

/**
 * mdi:file-eye-outline — the same glyph the My Work provider uses, so a review
 * looks like the same thing in both places. Inline because `iconSvgInline`
 * takes markup, not a component.
 */
const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h5.81c-.35-.61-.61-1.29-.71-2H6V4h7v5h5v1.08c.71.1 1.38.36 2 .72V8l-6-6m3.5 11c-2.8 0-5.2 1.73-6.2 4.19C12.3 19.65 14.7 21 17.5 21s5.2-1.35 6.2-3.81c-1-2.46-3.4-4.19-6.2-4.19m0 6.5a2.5 2.5 0 0 1-2.5-2.5 2.5 2.5 0 0 1 2.5-2.5 2.5 2.5 0 0 1 2.5 2.5 2.5 2.5 0 0 1-2.5 2.5m0-4a1.5 1.5 0 0 0-1.5 1.5 1.5 1.5 0 0 0 1.5 1.5 1.5 1.5 0 0 0 1.5-1.5 1.5 1.5 0 0 0-1.5-1.5Z" />'
	+ '</svg>'

/**
 * Team folder prefixes for this user, filled once on boot.
 *
 * Starts empty and stays empty on failure, which is the safe direction: the
 * action simply never offers itself. An error here must never make the Files
 * app look broken — TeamHub is a guest on this page.
 *
 * @type {Array<{teamId: string, teamName: string, paths: string[]}>}
 */
let scopes = []

/**
 * Does this path sit inside one of the user's team folders?
 *
 * The trailing slash on both sides is load-bearing: a plain `startsWith` would
 * claim `/Team Alpha Archive/x` for the team folder `/Team Alpha`.
 *
 * @param {string} path root-relative node path, e.g. `/Team Alpha/report.odt`
 * @return {boolean} true when a team folder contains it
 */
function inTeamFolder(path) {
	if (!path) {
		return false
	}
	return scopes.some(scope => scope.paths.some(folder => {
		return path === folder || path.startsWith(folder.replace(/\/$/, '') + '/')
	}))
}

/**
 * Mount the request modal for one file.
 *
 * A fresh Vue app per invocation, torn down on close. The Files app is not a
 * Vue app we can reach into, so there is no shared root to attach to — and a
 * modal that exists only while it is open cannot leak state between files.
 *
 * @param {object} node the INode the action was invoked on
 */
function openModal(node) {
	const mount = document.createElement('div')
	document.body.appendChild(mount)

	const app = createApp(RequestFileReviewModal, {
		fileId: node.fileid,
		fileName: node.basename,
		onClose: () => {
			app.unmount()
			mount.remove()
		},
	})
	app.mount(mount)
}

/**
 * Register the action, once the scopes are known.
 *
 * Registration itself is unconditional when the user has at least one team
 * folder; the per-file decision is `enabled()`. Per SKILLS.md § Permissions the
 * entry is **hidden** where it does not apply rather than shown and disabled.
 */
function register() {
	registerFileAction({
		id: 'teamhub-request-review',

		// TRANSLATORS: entry in a file's ⋯ menu — ask teammates to review this file
		displayName: () => t('teamhub', 'Request review'),

		iconSvgInline: () => ICON_SVG,

		/**
		 * Synchronous by contract. Single files only: a review is a question
		 * about one document, and a folder or a multi-selection has no answer.
		 *
		 * @param {object} context the action context
		 * @param {object[]} context.nodes selected nodes
		 * @return {boolean} whether to show the entry
		 */
		enabled: ({ nodes }) => {
			if (!Array.isArray(nodes) || nodes.length !== 1) {
				return false
			}
			const node = nodes[0]
			// `type` is 'file' | 'folder' in @nextcloud/files.
			if (!node || node.type !== 'file' || !node.fileid) {
				return false
			}
			return inTeamFolder(node.path)
		},

		/**
		 * @param {object} context the action context
		 * @param {object[]} context.nodes the single selected node
		 * @return {Promise<null>} null — the action opens a dialog rather than
		 *                         succeeding or failing on its own
		 */
		exec: async ({ nodes }) => {
			openModal(nodes[0])
			// null means "silent": the Files app shows no success toast for
			// merely opening a dialog. The modal reports its own outcome.
			return null
		},

		order: 30,
	})
}

/**
 * Boot: ask the server what this user's team folders are, then register.
 *
 * Everything is best-effort. A user in no team, a failed request, or the module
 * being switched off all end the same way — no action, no error on screen, and
 * a Files app that behaves exactly as if TeamHub were not installed.
 */
async function boot() {
	try {
		const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/file-reviews/scopes'))
		scopes = Array.isArray(data?.scopes) ? data.scopes : []
	} catch (error) {
		logger.debug('TeamHub: file review scopes unavailable', { error })
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
		logger.warn('TeamHub: could not register the file review action', { error })
	}
}

boot()
