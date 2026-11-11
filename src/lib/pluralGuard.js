/**
 * Plural guard for languages TeamHub does not ship (issue #112).
 *
 * Nextcloud core registers each app's plural function from the USER's
 * language, not from the translation file it actually loaded. When TeamHub
 * has no `l10n/<lang>.js` for that language, the server falls back to another
 * bundle (English, or the instance's default language), and every bundle we
 * ship has two plural forms. Polish, Czech, Russian, Ukrainian, Arabic and
 * others have three or more, so for counts like 0 or 5 core asks for form
 * index 2. `@nextcloud/l10n` then reads `translation[2]`, gets `undefined`
 * and throws inside `translate()` ("can't access property replace"), which
 * takes the whole component down with it: in #112 every poll vanished for a
 * Polish user.
 *
 * The fix: when the user's language is not one we ship, register a two-form
 * plural rule for `teamhub` that matches the bundle that really loaded. For a
 * shipped language nothing changes, core's own rule is already right.
 *
 * `register()` merges translations, so passing `{}` keeps the loaded bundle
 * and only replaces the plural function.
 */
import { getLanguage, register } from '@nextcloud/l10n'

// Languages with an l10n/<lang>.json in this app. tests/js/pluralGuard.test.mjs
// fails when this list and the l10n folder disagree. Every one of them has
// two plural forms; that test also fails if a file with a different count
// is added.
export const SHIPPED_LANGUAGES = ['da', 'de', 'en', 'es', 'fr', 'it', 'nl']

/**
 * Whether a user language falls back to a bundle we did not write for it.
 *
 * @param {string|undefined} language Nextcloud language code, `pt-BR` or `pt_BR` form
 * @return {boolean}
 */
export function needsPluralGuard(language) {
	if (!language) {
		return false
	}
	return !SHIPPED_LANGUAGES.includes(String(language).replace('_', '-'))
}

/**
 * Plural index for every bundle TeamHub ships: 0 for one, 1 for the rest.
 *
 * @param {number} count the number the string counts
 * @return {number}
 */
export function twoFormPlural(count) {
	return count === 1 ? 0 : 1
}

/**
 * Install the guard for the current page. Safe to call more than once.
 *
 * @param {string} [language] defaults to the page's language
 * @return {boolean} whether the guard was installed
 */
export function installPluralGuard(language = getLanguage()) {
	if (!needsPluralGuard(language)) {
		return false
	}
	register('teamhub', {}, twoFormPlural)
	return true
}
