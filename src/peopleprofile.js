/**
 * The collaboration profile, rendered into the Nextcloud profile page.
 *
 * Loaded by `PeopleProfileSectionListener` on the documented
 * `OCP\Profile\BeforeTemplateRenderedEvent`, and only when the People module
 * is on instance-wide.
 *
 * ## Why this file imports nothing
 *
 * It runs on a page TeamHub does not own, on every visit to anybody's profile.
 * The same reasoning as `src/filesactions.js` (see the note at the top of
 * vite.config.mjs): a single `vue` or `@nextcloud/vue` import here would pull
 * the shared vendor chunk into a page that needs none of it. With no imports
 * at all the entry emits no stylesheet either, which sidesteps the multi-entry
 * CSS problem that note describes -- the few rules below are injected once,
 * from here, in Nextcloud's own colour and spacing variables.
 *
 * Every label and value arrives translated from `PeopleDirectoryService`, so
 * there are no strings to localise in this file.
 *
 * ## It must never break somebody else's page
 *
 * The profile page's markup is not a contract we are party to. Everything here
 * is wrapped, the mount point is searched for rather than assumed, and a miss
 * means no section -- never an exception on a page that was rendering fine.
 */
(function () {
	'use strict'

	var APP = 'teamhub'
	var MOUNT_ID = 'teamhub-people-profile-section'

	/** The profile being viewed: initial state first, then the /u/<uid> URL. */
	function targetUserId() {
		try {
			var el = document.getElementById('initial-state-' + APP + '-people_profile_user')
			if (el && el.value) {
				// Nextcloud encodes initial state as base64 JSON.
				try {
					return JSON.parse(atob(el.value))
				} catch (e) {
					return JSON.parse(el.value)
				}
			}
		} catch (e) { /* fall through to the URL */ }

		try {
			var m = window.location.pathname.match(/\/u\/([^/?#]+)/)
			if (m) {
				return decodeURIComponent(m[1])
			}
		} catch (e) { /* no id, no section */ }

		return null
	}

	function apiUrl(uid) {
		var path = '/apps/' + APP + '/api/v1/people/directory/' + encodeURIComponent(uid)
		// OC.generateUrl is on every Nextcloud page and knows the webroot and
		// whether index.php is in play. @nextcloud/router would be the house
		// choice, and is an import this entry cannot afford.
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl(path)
		}
		return path
	}

	/**
	 * Where the section goes. The profile page is a Vue app, so the markup
	 * arrives after this script does; each candidate is tried in turn and the
	 * last two are deliberately generic so a markup change downgrades the
	 * placement instead of losing the section.
	 */
	var MOUNT_CANDIDATES = [
		'.profile__content',
		'.profile__blocks',
		'.profile__sections',
		'#profile-content',
		'main .profile',
		'#content main',
		'#app-content',
	]

	function findMount() {
		for (var i = 0; i < MOUNT_CANDIDATES.length; i++) {
			var el = document.querySelector(MOUNT_CANDIDATES[i])
			if (el) {
				return { el: el, selector: MOUNT_CANDIDATES[i] }
			}
		}
		return null
	}

	/** Wait for the Vue app to render, then give up quietly. */
	function whenMounted(callback) {
		var found = findMount()
		if (found) {
			callback(found)
			return
		}
		var tries = 0
		var timer = window.setInterval(function () {
			tries++
			var hit = findMount()
			if (hit) {
				window.clearInterval(timer)
				callback(hit)
			} else if (tries > 40) { // ~10s: no candidate matched, render nothing
				window.clearInterval(timer)
			}
		}, 250)
	}

	function injectStyles() {
		if (document.getElementById(MOUNT_ID + '-style')) {
			return
		}
		var style = document.createElement('style')
		style.id = MOUNT_ID + '-style'
		style.textContent = [
			'#' + MOUNT_ID + '{margin-block-start:24px;max-width:600px}',
			'#' + MOUNT_ID + ' h3{font-size:16px;font-weight:600;margin:0 0 8px}',
			'#' + MOUNT_ID + ' dl{margin:0;display:grid;grid-template-columns:minmax(120px,max-content) 1fr;gap:4px 16px}',
			'#' + MOUNT_ID + ' dt{color:var(--color-text-maxcontrast);font-size:13px}',
			'#' + MOUNT_ID + ' dd{margin:0;font-size:13px;overflow-wrap:anywhere}',
			'@media (max-width:480px){#' + MOUNT_ID + ' dl{grid-template-columns:1fr;gap:0 0}',
			'#' + MOUNT_ID + ' dd{margin-block-end:8px}}',
		].join('')
		document.head.appendChild(style)
	}

	function render(mount, profile) {
		if (document.getElementById(MOUNT_ID)) {
			return
		}
		injectStyles()

		var section = document.createElement('section')
		section.id = MOUNT_ID

		var heading = document.createElement('h3')
		// textContent throughout: these values are another user's free text and
		// never become markup.
		heading.textContent = profile.sectionTitle || ''
		section.appendChild(heading)

		var dl = document.createElement('dl')
		profile.fields.forEach(function (field) {
			var dt = document.createElement('dt')
			dt.textContent = field.label
			var dd = document.createElement('dd')
			dd.textContent = field.value
			dl.appendChild(dt)
			dl.appendChild(dd)
		})
		section.appendChild(dl)
		mount.el.appendChild(section)
	}

	function start() {
		var uid = targetUserId()
		if (!uid) {
			return
		}

		var headers = { Accept: 'application/json' }
		try {
			if (window.OC && window.OC.requestToken) {
				headers.requesttoken = window.OC.requestToken
			}
		} catch (e) { /* the endpoint does not require it */ }

		window.fetch(apiUrl(uid), { credentials: 'same-origin', headers: headers })
			.then(function (response) {
				return response.ok ? response.json() : null
			})
			.then(function (profile) {
				// A null answer is the access gate, and an empty field list is
				// somebody who has published nothing. Both mean no section.
				if (!profile || !profile.fields || !profile.fields.length) {
					return
				}
				whenMounted(function (mount) {
					try {
						render(mount, profile)
					} catch (e) { /* never disturb the page */ }
				})
			})
			.catch(function () { /* never disturb the page */ })
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start)
	} else {
		start()
	}
})()
