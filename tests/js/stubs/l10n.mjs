/** Minimal `@nextcloud/l10n` for tests: identity translation + placeholders. */
function fill(text, vars) {
    return vars ? text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text
}
export function translate(app, text, vars) {
    return fill(text, vars)
}
export function translatePlural(app, singular, plural, count, vars) {
    return fill(count === 1 ? singular : plural, { n: count, ...(vars || {}) })
}
/** v4.9.7 — src/lib/localDate.js asks for the canonical locale. */
export function getCanonicalLocale() {
    return 'en'
}
