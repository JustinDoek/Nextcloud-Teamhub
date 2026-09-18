/**
 * Minimal `@nextcloud/initial-state` for tests (v4.9.7): nothing is ever
 * injected, so every lookup answers the caller's own fallback.
 */
export function loadState(app, key, fallback = null) {
    return fallback
}
