/** See register.mjs. Maps two browser-only packages to test stubs. */
const STUBS = {
    '@nextcloud/l10n': new URL('./stubs/l10n.mjs', import.meta.url).href,
    '@nextcloud/router': new URL('./stubs/router.mjs', import.meta.url).href,
    '@nextcloud/initial-state': new URL('./stubs/initial-state.mjs', import.meta.url).href,
}

export async function resolve(specifier, context, next) {
    if (specifier in STUBS) {
        return { url: STUBS[specifier], shortCircuit: true }
    }
    return next(specifier, context)
}
