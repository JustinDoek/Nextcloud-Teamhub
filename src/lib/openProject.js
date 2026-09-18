/**
 * TeamHub — OpenProject integration helpers (v4.9.3, Phase 1).
 *
 * Pure functions shared by the two OpenProject widgets and the Manage Team
 * panel: error classification, the sentence for each error code, and the
 * small state derivations the templates branch on. Nothing here touches the
 * DOM, the store or axios, so `tests/js/openProject.test.mjs` can run it
 * under `node --test` without a browser.
 *
 * The error sentences are the **same source strings** as
 * `lib/Service/OpenProject/OpenProjectMessages.php` — Nextcloud serves one
 * `l10n/<lang>.json` to PHP and to this bundle alike, so one translation
 * covers both. Change one side, change the other.
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Error codes the backend answers with (`OpenProjectException` in PHP). */
export const CODES = Object.freeze({
    // v4.9.16 — TeamHub's own module, checked before anything about the
    // official app: unlicensed, or switched off in TeamHub administration.
    MODULE_UNLICENSED: 'module_unlicensed',
    MODULE_DISABLED: 'module_disabled',
    INTEGRATION_NOT_INSTALLED: 'integration_not_installed',
    INTEGRATION_DISABLED: 'integration_disabled',
    INTEGRATION_INCOMPATIBLE: 'integration_incompatible',
    HOST_NOT_CONFIGURED: 'host_not_configured',
    USER_NOT_CONNECTED: 'user_not_connected',
    AUTH_FAILED: 'auth_failed',
    PERMISSION_DENIED: 'permission_denied',
    PROJECT_NOT_FOUND: 'project_not_found',
    UNSUPPORTED_RESPONSE: 'unsupported_response',
    API_UNAVAILABLE: 'api_unavailable',
    TEMPORARY_FAILURE: 'temporary_failure',
    RATE_LIMITED: 'rate_limited',
    LINK_STALE: 'link_stale',
    PROJECT_ALREADY_LINKED: 'project_already_linked',
    // v4.9.6 — writes (Phase 2).
    VALIDATION_FAILED: 'validation_failed',
    JOB_FAILED: 'job_failed',
})

/** Codes that mean "nothing a team member can fix from here". */
const CONFIGURATION_CODES = new Set([
    CODES.MODULE_UNLICENSED,
    CODES.MODULE_DISABLED,
    CODES.INTEGRATION_NOT_INSTALLED,
    CODES.INTEGRATION_DISABLED,
    CODES.INTEGRATION_INCOMPATIBLE,
    CODES.HOST_NOT_CONFIGURED,
])

/** Codes that mean "this user needs to (re)connect their account". */
const CONNECTION_CODES = new Set([
    CODES.USER_NOT_CONNECTED,
    CODES.AUTH_FAILED,
])

/** Codes worth a "try again" button rather than a settings link. */
const TRANSIENT_CODES = new Set([
    CODES.API_UNAVAILABLE,
    CODES.TEMPORARY_FAILURE,
    CODES.RATE_LIMITED,
])

export function isConfigurationCode(code) {
    return CONFIGURATION_CODES.has(code)
}

export function isConnectionCode(code) {
    return CONNECTION_CODES.has(code)
}

export function isTransientCode(code) {
    return TRANSIENT_CODES.has(code)
}

/**
 * Codes that mean TeamHub's OpenProject module itself is off (v4.9.16) —
 * unlicensed or switched off by an administrator. Every OpenProject surface
 * hides on these rather than explaining itself: a module that is off is not
 * a broken module.
 *
 * @param {string} code
 * @return {boolean}
 */
export function isModuleCode(code) {
    return code === CODES.MODULE_UNLICENSED || code === CODES.MODULE_DISABLED
}

/**
 * Codes that mean the connection between this Nextcloud and OpenProject is
 * broken — nothing a team member can do about it, and an administrator can
 * (2026-09-14, Justin: the integration app lost its host and application
 * key, and every widget on the team home said so in its own words). The
 * Project info widget shows these as one "connection lost" picture with
 * "contact your administrator"; the other widgets stay silent. Not in the
 * set: the codes a member can act on (connect / reconnect their account)
 * and the ones about the project itself (no access, not found).
 *
 * @param {string} code
 * @return {boolean}
 */
export function isBrokenConnectionCode(code) {
    return CONFIGURATION_CODES.has(code)
        || TRANSIENT_CODES.has(code)
        || code === CODES.LINK_STALE
        || code === CODES.UNSUPPORTED_RESPONSE
}

/**
 * The sentence for an error code. Mirrors `OpenProjectMessages::userMessage`.
 *
 * @param {string} code
 * @return {string}
 */
export function errorMessage(code) {
    switch (code) {
    case CODES.MODULE_UNLICENSED:
    case CODES.MODULE_DISABLED:
        return t('teamhub', 'OpenProject is not enabled in TeamHub. Ask your Nextcloud administrator.')
    case CODES.INTEGRATION_NOT_INSTALLED:
    case CODES.INTEGRATION_DISABLED:
    case CODES.INTEGRATION_INCOMPATIBLE:
    case CODES.HOST_NOT_CONFIGURED:
        return t('teamhub', 'OpenProject is not available on this Nextcloud. Ask your administrator to set up the OpenProject integration.')
    case CODES.USER_NOT_CONNECTED:
        return t('teamhub', 'Connect your OpenProject account in your personal settings to see this project.')
    case CODES.AUTH_FAILED:
        return t('teamhub', 'OpenProject no longer accepts your connection. Reconnect your OpenProject account in your personal settings.')
    case CODES.PERMISSION_DENIED:
        return t('teamhub', 'You do not have access to this in OpenProject.')
    case CODES.PROJECT_NOT_FOUND:
        return t('teamhub', 'The linked OpenProject project no longer exists or is not visible to you.')
    case CODES.UNSUPPORTED_RESPONSE:
        return t('teamhub', 'OpenProject answered in a way TeamHub does not understand. This OpenProject version may not be supported.')
    case CODES.API_UNAVAILABLE:
        return t('teamhub', 'OpenProject cannot be reached right now.')
    case CODES.TEMPORARY_FAILURE:
        return t('teamhub', 'OpenProject had a temporary problem. Try again in a moment.')
    case CODES.RATE_LIMITED:
        return t('teamhub', 'OpenProject is receiving too many requests. Try again in a moment.')
    case CODES.LINK_STALE:
        return t('teamhub', 'This team was linked to a different OpenProject instance. Ask your Nextcloud administrator.')
    case CODES.VALIDATION_FAILED:
        return t('teamhub', 'OpenProject did not accept this.')
    case CODES.JOB_FAILED:
        return t('teamhub', 'OpenProject could not finish copying the template.')
    default:
        return t('teamhub', 'OpenProject data could not be loaded.')
    }
}

/**
 * Classify an axios failure into `{ code, message, administratorMessage, status }`.
 *
 * The backend always sends `code` for an OpenProject failure. Anything else
 * (network drop, a 500 from the trait) becomes `temporary_failure` with the
 * server's `error` text when there is one, so the widget still has a
 * sentence — and never a stack trace.
 *
 * @param {any} e an axios error, or anything thrown
 * @return {{code: string, message: string, administratorMessage: string|null, status: number|null, teams?: Array}}
 */
export function classifyError(e) {
    const status = e?.response?.status ?? null
    const data = e?.response?.data
    if (data && typeof data === 'object' && typeof data.code === 'string') {
        return {
            code: data.code,
            message: typeof data.error === 'string' && data.error !== '' ? data.error : errorMessage(data.code),
            administratorMessage: typeof data.administratorMessage === 'string' ? data.administratorMessage : null,
            status,
            teams: Array.isArray(data.teams) ? data.teams : undefined,
        }
    }
    if (status === 403) {
        return { code: CODES.PERMISSION_DENIED, message: errorMessage(CODES.PERMISSION_DENIED), administratorMessage: null, status }
    }
    return {
        code: CODES.TEMPORARY_FAILURE,
        message: typeof data?.error === 'string' && data.error !== '' ? data.error : errorMessage(CODES.TEMPORARY_FAILURE),
        administratorMessage: null,
        status,
    }
}

/**
 * Where a user connects their OpenProject account: the official app's
 * personal settings section.
 */
export function personalSettingsUrl() {
    return generateUrl('/settings/user/openproject')
}

/**
 * The state a widget is in, from the store facts and its own fetch state.
 *
 *   unlinked     the team has no OpenProject link (the grid normally hides
 *                the widget; this is the defensive fallback)
 *   loading      first fetch in flight, nothing to show yet
 *   error        a fetch failed and there is no payload to fall back on
 *   stale-error  a fetch failed but an earlier payload is still on screen
 *   ready        a payload is on screen
 *
 * @param {{ linked?: boolean } | null} cfg   store `openProjectConfig`
 * @param {object|null} payload
 * @param {object|null} error   from classifyError
 * @param {boolean} loading
 * @return {'unlinked'|'loading'|'error'|'stale-error'|'ready'}
 */
export function widgetState(cfg, payload, error, loading) {
    if (!cfg || !cfg.linked) return 'unlinked'
    if (error && payload) return 'stale-error'
    if (error) return 'error'
    if (loading && !payload) return 'loading'
    if (!payload) return 'loading'
    return 'ready'
}

/**
 * A due date relative to the viewer's today (both ISO YYYY-MM-DD).
 *
 * @param {string|null} dueDate
 * @param {string} today
 * @return {'overdue'|'today'|'soon'|'later'|null}
 */
export function dueState(dueDate, today) {
    if (!dueDate || !today) return null
    if (dueDate < today) return 'overdue'
    if (dueDate === today) return 'today'
    const due = new Date(dueDate + 'T00:00:00Z')
    const now = new Date(today + 'T00:00:00Z')
    const days = Math.round((due - now) / 86400000)
    return days <= 7 ? 'soon' : 'later'
}

/**
 * A project status code (`on_track`, `at_risk`, `off_track`, …) → the
 * state-colour role the chip uses. Unknown codes are neutral.
 *
 * @param {string|null|undefined} code
 * @return {'success'|'warning'|'error'|'neutral'}
 */
export function projectStatusTone(code) {
    switch (code) {
    case 'on_track': return 'success'
    case 'at_risk': return 'warning'
    case 'off_track': return 'error'
    default: return 'neutral'
    }
}

/**
 * Append a page of items to a list without repeating an id — a work package
 * that moved between pages while the user paged must not appear twice.
 * (The Upcoming tasks widget's OpenProject rows page this way since v4.9.5;
 * the "My OpenProject work" widget that first used it is gone.)
 *
 * @param {Array<{id: number}>} existing
 * @param {Array<{id: number}>} incoming
 * @return {Array<{id: number}>}
 */
export function mergeItems(existing, incoming) {
    const seen = new Set(existing.map(i => i.id))
    const out = existing.slice()
    for (const item of incoming) {
        if (!seen.has(item.id)) {
            seen.add(item.id)
            out.push(item)
        }
    }
    return out
}

/**
 * The body `POST …/openproject/work-packages` takes, from the create form's
 * state (v4.9.15). Only what was filled in travels: the backend treats an
 * absent assignee, due date or description as "none", and OpenProject
 * would refuse an empty string where it expects a date.
 *
 * @param {{ subject?: string, type?: {id: number}|null, assignee?: {id: number}|null, dueDate?: string, description?: string }} form
 * @return {{ subject: string, typeId: number, assigneeId?: number, dueDate?: string, description?: string }}
 */
export function buildWorkPackagePayload(form) {
    const payload = {
        subject: String(form?.subject ?? '').trim(),
        typeId: Number(form?.type?.id) || 0,
    }
    const assigneeId = Number(form?.assignee?.id) || 0
    if (assigneeId > 0) {
        payload.assigneeId = assigneeId
    }
    const dueDate = String(form?.dueDate ?? '').trim()
    if (/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) {
        payload.dueDate = dueDate
    }
    const description = String(form?.description ?? '').trim()
    if (description !== '') {
        payload.description = description
    }
    return payload
}

/**
 * The store facts for `openProjectConfig` built from a link the API
 * returned — the same shape `LayoutController` emits. A link only exists
 * on an OpenProject-template team, so `eligible` is true whenever there is
 * one.
 *
 * @param {object|null} link  the `link` object from the API, or null
 * @param {boolean} available capabilities say the integration is usable
 * @return {{available: boolean, eligible: boolean, linked: boolean, stale: boolean, project: object|null}}
 */
export function configFromLink(link, available) {
    if (!link) {
        return { available, eligible: false, linked: false, stale: false, project: null }
    }
    return {
        available,
        eligible: true,
        linked: true,
        stale: !!link.stale,
        project: {
            id: link.projectId,
            identifier: link.projectIdentifier,
            name: link.projectName,
            url: link.urls?.project ?? null,
        },
    }
}
