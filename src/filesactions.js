/**
 * TeamHub's "Request review" action inside the Nextcloud Files app — the entry
 * stub (v4.10.11).
 *
 * This is the app's fourth build entry and the only one that runs on a page
 * TeamHub does not render: `FilesScriptsListener` puts it on every Files page
 * load, for every user, standalone and inside TeamHub's own Files tab.
 *
 * What it costs is therefore what TeamHub adds to the boot of the Files app,
 * and on a real instance that boot is ~20 MB of JavaScript from every app that
 * hooks into Files, parsed and executed on one thread. Until 4.10.11 this entry
 * added 2.5 MB of vendor chunk and 278 KB of CSS to that, up front, for a menu
 * entry most page loads never open.
 *
 * So the entry is now a stub that **imports nothing** and only decides *when*:
 *
 *   1. `filesactions.js`      — this file: waits for the Files app to finish
 *                               loading and the page to go idle;
 *   2. `filesactionsBoot.js`  — fetches the user's team-folder scopes and
 *                               registers the action (needs `@nextcloud/files`,
 *                               axios, l10n — a few hundred KB, after the list
 *                               is on screen);
 *   3. `filesactionsModal.js` — Vue, the NC components and the dialog, on the
 *                               first click.
 *
 * Registering late is safe: the Files app reads the action registry when it
 * builds a row's ⋯ menu, so an action added after the list has rendered is
 * there the next time a menu opens. Files first, then us — a guest on this
 * page does not push in front of the host.
 *
 * Keep this file free of imports. Anything imported here is evaluated during
 * the Files boot, which is exactly what the split exists to avoid.
 */

/**
 * The idle wait is bounded so a page that never goes idle (a long file list
 * still rendering, a busy tab) still gets the action within a few seconds.
 */
const IDLE_TIMEOUT_MS = 4000

/**
 * Run `fn` once the document has finished loading and the browser reports an
 * idle moment — or after the timeout, whichever comes first.
 *
 * @param {Function} fn
 */
function whenFilesIsUp(fn) {
	const idle = () => {
		if (typeof window.requestIdleCallback === 'function') {
			window.requestIdleCallback(() => fn(), { timeout: IDLE_TIMEOUT_MS })
		} else {
			window.setTimeout(fn, 1000)
		}
	}
	if (document.readyState === 'complete') {
		idle()
	} else {
		window.addEventListener('load', idle, { once: true })
	}
}

whenFilesIsUp(async () => {
	try {
		const { boot } = await import('./filesactionsBoot.js')
		await boot()
	} catch (error) {
		// Reported to the console, never to the user: a TeamHub fault must not
		// put an error toast on somebody's Files page. No logger here — that
		// would be an import.
		console.warn('[TeamHub] could not load the file review action', error)
	}
})
