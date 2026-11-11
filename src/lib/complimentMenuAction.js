/**
 * The Compliment entry in Nextcloud's contacts menu (v4.13.2, DESIGN §2.186).
 *
 * ── Why this mechanism and not a contacts-menu provider ──────────────────
 *
 * Nextcloud's own extension point for that menu is a PHP
 * `OCP\Contacts\ContactsMenu\IProvider`, and every action it can add is a
 * **link** — `IActionFactory` makes nothing else. A link would mean
 * navigating away (or reloading the page we are already on) just to open a
 * box for a one-line note.
 *
 * `@nextcloud/vue` 9 ships the other half: `registerContactsMenuAction()`
 * puts a JS callback in the menu, which `NcAvatar` renders as an
 * `NcActionButton` beside the server's link actions. So the entry can simply
 * open a dialog in the app that is already running. Confirmed present in the
 * installed 9.8.0 dist (`dist/functions/contactsMenu/`, read by
 * `NcAvatar`'s `menu` computed through `getEnabledContactsMenuActions`).
 *
 * ── Where it appears, and where it does not ──────────────────────────────
 *
 * The registry is one object on `window`, so the entry shows on **every**
 * avatar menu on a page where this module ran — in TeamHub that is the
 * members widget, the presence rows, message cards, Deck cards, decisions
 * and a workflow's detail view, all of which already pass
 * `:disable-menu="false"`. It is registered from `main.js` only, so it does
 * not turn up in the Files sidebar or anywhere else in Nextcloud, where
 * TeamHub's own bundle is not what is running.
 */
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { registerContactsMenuAction } from '@nextcloud/vue'
import logger from '../logger.js'

/** Unique within the window-level registry; registering twice logs an error. */
export const COMPLIMENT_ACTION_ID = 'teamhub-compliment'

/**
 * Material Design's `party-popper`, inlined as a string because the registry
 * wants raw SVG markup and `vue-material-design-icons` ships components. The
 * path is copied verbatim from `vue-material-design-icons/PartyPopper.vue`,
 * which this app already bundles — so this is the same icon the dialog it
 * opens uses, not a second one that will drift.
 *
 * `currentColor` rather than a token: it is rendered inside an NcActionButton,
 * which owns its own colour.
 */
export const PARTY_POPPER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">'
	+ '<path d="M14.53 1.45L13.45 2.53L15.05 4.13C15.27 4.38 15.38 4.67 15.38 5S15.27 5.64 15.05 5.86L11.5 9.47L12.5 10.55L16.13 6.94C16.66 6.35 16.92 5.7 16.92 5C16.92 4.3 16.66 3.64 16.13 3.05L14.53 1.45M10.55 3.47L9.47 4.55L10.08 5.11C10.3 5.33 10.41 5.63 10.41 6S10.3 6.67 10.08 6.89L9.47 7.45L10.55 8.53L11.11 7.92C11.64 7.33 11.91 6.69 11.91 6C11.91 5.28 11.64 4.63 11.11 4.03L10.55 3.47M21 5.06C20.31 5.06 19.67 5.33 19.08 5.86L13.45 11.5L14.53 12.5L20.11 6.94C20.36 6.69 20.66 6.56 21 6.56S21.64 6.69 21.89 6.94L22.5 7.55L23.53 6.47L22.97 5.86C22.38 5.33 21.72 5.06 21 5.06M7 8L2 22L16 17L7 8M19 11.06C18.3 11.06 17.66 11.33 17.06 11.86L15.47 13.45L16.55 14.53L18.14 12.94C18.39 12.69 18.67 12.56 19 12.56C19.33 12.56 19.63 12.69 19.88 12.94L21.5 14.53L22.55 13.5L20.95 11.86C20.36 11.33 19.7 11.06 19 11.06Z" />'
	+ '</svg>'

/**
 * Whether the entry belongs in this particular menu.
 *
 * Three cases it must not offer itself for, each for its own reason:
 *  - a contact who is not a Nextcloud account (`isUser` false): there is
 *    nobody to store a compliment for;
 *  - no uid at all, which is what an email-only contact looks like;
 *  - the viewer themselves — the server refuses it, and an entry that always
 *    fails is worse than no entry.
 *
 * It deliberately does **not** try to decide whether the two share a team.
 * That is a question only the server can answer, it would cost a request per
 * menu open, and the server answers it on send with a sentence the sender can
 * read. Offering and explaining beats a menu that silently lacks an entry.
 *
 * @param {object} entry the contacts-menu entry as the backend returned it
 * @return {boolean}
 */
export function complimentEnabledFor(entry) {
	if (!entry || entry.isUser !== true) {
		return false
	}
	const uid = entry.uid
	if (typeof uid !== 'string' || uid === '') {
		return false
	}
	return uid !== getCurrentUser()?.uid
}

/**
 * Register the entry. Call once, from the app bundle's entry point.
 *
 * @param {Function} onPick called with `{ uid, displayName }` when clicked
 */
export function registerComplimentAction(onPick) {
	try {
		registerContactsMenuAction({
			id: COMPLIMENT_ACTION_ID,
			// TRANSLATORS: menu entry on a person's avatar — to pay them a
			// compliment, i.e. tell them they did something well
			displayName: () => t('teamhub', 'Compliment'),
			enabled: complimentEnabledFor,
			iconSvg: () => PARTY_POPPER_SVG,
			callback: (entry) => onPick({
				uid: entry.uid,
				displayName: entry.fullName || entry.uid,
			}),
		})
	} catch (e) {
		// A failure here must not take the app down with it: the menu entry
		// is a nicety and everything else on the page still works without it.
		logger.warn('[TeamHub][compliments] could not register the contacts-menu action', { error: e })
	}
}
