/**
 * The "Request review" dialog — stage three of the file-action entry, loaded
 * on demand by `filesactionsBoot.js` (v4.10.11).
 *
 * This file exists to keep the Files app's page light. Everything in here —
 * Vue itself, the `@nextcloud/vue` components the dialog is built from and the
 * dialog's own styles — used to be imported statically by the file action
 * entry, which made every Files page load (standalone *and* inside TeamHub's
 * Files tab) pull in the app's 2.5 MB shared vendor chunk and 285 KB of
 * component CSS before the file list was interactive. A second copy of Vue,
 * parsed and executed on a page that already has NC's own, to register one
 * menu entry that most page loads never open.
 *
 * Now the entry stub imports nothing, the boot stage imports nothing heavier
 * than `@nextcloud/files`, and this module — with everything it drags in —
 * arrives only when somebody actually clicks the action. Vite's preload helper
 * fetches the chunk's CSS alongside it (resolved through `OC.filePath`, so it
 * works on any NC page), which is why `FilesScriptsListener` no longer adds
 * any stylesheet of ours to the page.
 *
 * Keep this boundary: nothing in `filesactions.js` or `filesactionsBoot.js`
 * may import `vue`, `@nextcloud/vue` or a `.vue` component. Anything that
 * needs them belongs here.
 */

import { createApp } from 'vue'
import RequestFileReviewModal from './components/RequestFileReviewModal.vue'

/**
 * Mount the request modal for one file.
 *
 * A fresh Vue app per invocation, torn down on close. The Files app is not a
 * Vue app we can reach into, so there is no shared root to attach to — and a
 * modal that exists only while it is open cannot leak state between files.
 *
 * @param {object} node the INode the action was invoked on
 */
export function openRequestReviewModal(node) {
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
