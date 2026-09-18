/**
 * TeamHub — workspace provisioning helpers (v4.9.6, OpenProject Phase 2).
 *
 * Pure functions shared by the creation wizard, the team page's banner and
 * the administrator's diagnostics: the human-readable name of every step,
 * the sentence for every status, the review-screen classification of a
 * resource (new / linked / skipped / unavailable), the pump that drives an
 * operation to its end, and the request key that makes a double click one
 * workspace. Nothing here touches the DOM or the store; the pump takes its
 * transport as an argument so `tests/js/provisioning.test.mjs` runs it
 * under `node --test`.
 */
import { translate as t } from '@nextcloud/l10n'

/** Step keys, in the order the backend runs them (StepRegistry). */
export const STEP_KEYS = Object.freeze([
    'validate', 'openproject_project', 'team', 'project_folder', 'talk', 'calendar',
    'collectives', 'modules', 'membership', 'dashboard', 'handover', 'finalize',
])

/** Operation statuses (ProvisioningService). */
export const OP_STATUS = Object.freeze({
    PENDING: 'pending',
    RUNNING: 'running',
    COMPLETED: 'completed',
    ATTENTION: 'attention',
    FAILED: 'failed',
    ROLLED_BACK: 'rolled_back',
})

/** Step statuses (StepResult). */
export const STEP_STATUS = Object.freeze({
    PENDING: 'pending',
    RUNNING: 'running',
    COMPLETED: 'completed',
    SKIPPED: 'skipped',
    ATTENTION: 'attention',
    FAILED: 'failed',
    ROLLED_BACK: 'rolled_back',
})

/** The decisions a creator can take for a member without an OpenProject account. */
export const DECISIONS = Object.freeze({
    TEAMHUB_ONLY: 'teamhub_only',
    OMIT: 'omit',
})

/**
 * Human-readable step names. Keyed by the backend's step key; an unknown
 * key (a step added by a later phase) falls back to the key itself so the
 * UI never shows nothing.
 *
 * @param {string} key
 * @return {string}
 */
export function stepLabel(key) {
    switch (key) {
    case 'validate': return t('teamhub', 'Checking the request')
    case 'openproject_project': return t('teamhub', 'OpenProject project')
    case 'team': return t('teamhub', 'Team')
    case 'project_folder': return t('teamhub', 'Project files')
    case 'talk': return t('teamhub', 'Team conversation')
    case 'calendar': return t('teamhub', 'Project calendar')
    case 'collectives': return t('teamhub', 'Project knowledge')
    case 'modules': return t('teamhub', 'Team modules')
    case 'membership': return t('teamhub', 'Members and roles')
    case 'dashboard': return t('teamhub', 'Dashboard')
    case 'handover': return t('teamhub', 'Handing over the team')
    case 'finalize': return t('teamhub', 'Checking the workspace')
    default: return key
    }
}

/**
 * The sentence for a step status.
 *
 * @param {string} status
 * @return {string}
 */
export function stepStatusLabel(status) {
    switch (status) {
    case STEP_STATUS.PENDING: return t('teamhub', 'Waiting')
    case STEP_STATUS.RUNNING: return t('teamhub', 'In progress')
    case STEP_STATUS.COMPLETED: return t('teamhub', 'Done')
    case STEP_STATUS.SKIPPED: return t('teamhub', 'Skipped')
    case STEP_STATUS.ATTENTION: return t('teamhub', 'Needs attention')
    case STEP_STATUS.FAILED: return t('teamhub', 'Failed')
    case STEP_STATUS.ROLLED_BACK: return t('teamhub', 'Rolled back')
    default: return status
    }
}

/**
 * The sentence for an operation status.
 *
 * @param {string} status
 * @return {string}
 */
export function operationStatusLabel(status) {
    switch (status) {
    case OP_STATUS.PENDING: return t('teamhub', 'Not started')
    case OP_STATUS.RUNNING: return t('teamhub', 'Setting up the workspace')
    case OP_STATUS.COMPLETED: return t('teamhub', 'Workspace ready')
    case OP_STATUS.ATTENTION: return t('teamhub', 'Workspace needs attention')
    case OP_STATUS.FAILED: return t('teamhub', 'Setup stopped')
    case OP_STATUS.ROLLED_BACK: return t('teamhub', 'Setup rolled back')
    default: return status
    }
}

/** Is the operation still moving (the pump keeps calling). */
export function isActive(status) {
    return status === OP_STATUS.PENDING || status === OP_STATUS.RUNNING
}

/** Is the workspace usable — finished, or finished with something to look at. */
export function isUsable(status) {
    return status === OP_STATUS.COMPLETED || status === OP_STATUS.ATTENTION
}

/**
 * The review screen's classification of one component, from the options
 * endpoint's component row and the creator's selection.
 *
 * @param {{id: string, required: boolean, installed: boolean, action: string}} component
 * @param {boolean} selected whether the creator kept an optional component
 * @return {'new'|'link'|'skipped'|'unavailable'|'required-missing'}
 */
export function classifyComponent(component, selected) {
    if (!component.installed) {
        return component.required ? 'required-missing' : 'unavailable'
    }
    if (!component.required && !selected) {
        return 'skipped'
    }
    if (component.action === 'link') {
        return 'link'
    }
    if (component.action === 'none') {
        return 'skipped'
    }
    return 'new'
}

/**
 * The sentence for a review classification.
 *
 * @param {string} kind
 * @return {string}
 */
export function classificationLabel(kind) {
    switch (kind) {
    case 'new': return t('teamhub', 'New')
    case 'link': return t('teamhub', 'Existing, will be linked')
    case 'skipped': return t('teamhub', 'Skipped')
    case 'unavailable': return t('teamhub', 'Not available')
    case 'required-missing': return t('teamhub', 'Required, not installed')
    default: return kind
    }
}

/**
 * The display name of an application or module key.
 *
 * @param {string} id
 * @return {string}
 */
export function componentLabel(id) {
    switch (id) {
    case 'talk': return t('teamhub', 'Talk conversation')
    case 'files': return t('teamhub', 'Project files')
    case 'calendar': return t('teamhub', 'Calendar')
    case 'deck': return t('teamhub', 'Deck board')
    case 'collectives': return t('teamhub', 'Collectives knowledge space')
    case 'intravox': return t('teamhub', 'Pages')
    case 'messages': return t('teamhub', 'Messages')
    case 'decisions': return t('teamhub', 'Decisions')
    case 'presence': return t('teamhub', 'Presence')
    case 'timeline': return t('teamhub', 'Timeline')
    case 'dashboard': return t('teamhub', 'TeamHub dashboard')
    default: return id
    }
}

/**
 * The display name of a TeamHub role key.
 *
 * @param {string} key
 * @return {string}
 */
export function roleLabel(key) {
    switch (key) {
    case 'owner': return t('teamhub', 'Team owner')
    case 'admin': return t('teamhub', 'Team admin')
    case 'moderator': return t('teamhub', 'Moderator')
    case 'member': return t('teamhub', 'Member')
    // TRANSLATORS: the role key for members who are not local accounts (federated, e-mail invitees)
    case 'guest': return t('teamhub', 'Guest')
    default: return key
    }
}

/**
 * A request key for one wizard submission: random, URL-safe, within the
 * backend's `[A-Za-z0-9_-]{8,64}`. Generated once when the review step is
 * reached, so a double click on "Create workspace" reuses it.
 *
 * @return {string}
 */
export function newIdempotencyKey() {
    const bytes = new Uint8Array(24)
    if (typeof globalThis.crypto?.getRandomValues === 'function') {
        globalThis.crypto.getRandomValues(bytes)
    } else {
        for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256)
    }
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'
    let out = ''
    for (const b of bytes) out += alphabet[b & 63]
    return out
}

/**
 * Drive an operation until it stops moving.
 *
 * `run()` is the transport: it calls the backend's `run` endpoint and
 * resolves with the operation's state. The pump calls it again while the
 * operation is active, waiting `pollAfter` seconds when the backend asks
 * for a pause (an OpenProject copy job in flight), a short beat otherwise,
 * and backing off when the backend reports the operation busy under
 * somebody else's lease. `onState` sees every state. Resolves with the
 * final state; rejects only when the transport throws.
 *
 * @param {() => Promise<object>} run
 * @param {(state: object) => void} onState
 * @param {{sleep?: (ms: number) => Promise<void>, maxRounds?: number, signal?: {aborted: boolean}}} [opts]
 * @return {Promise<object>}
 */
export async function pumpOperation(run, onState, opts = {}) {
    const sleep = opts.sleep || (ms => new Promise(r => setTimeout(r, ms)))
    const maxRounds = opts.maxRounds ?? 600
    let state = null
    let busyRounds = 0
    for (let round = 0; round < maxRounds; round++) {
        if (opts.signal?.aborted) break
        state = await run()
        onState(state)
        if (!isActive(state?.status)) break
        let waitMs = 800
        if (state.busy) {
            busyRounds++
            waitMs = Math.min(10000, 1500 * busyRounds)
        } else {
            busyRounds = 0
            if (state.pollAfter) waitMs = Math.max(1000, state.pollAfter * 1000)
        }
        await sleep(waitMs)
    }
    return state
}

/**
 * The steps of an operation reduced to what a progress list shows.
 *
 * @param {object} state the operation as the backend returns it
 * @return {Array<{key: string, label: string, status: string, statusLabel: string, errorMessage: string|null, retrySafe: boolean, detail: object}>}
 */
export function progressRows(state) {
    const steps = Array.isArray(state?.steps) ? state.steps : []
    return steps.map(s => ({
        key: s.key,
        label: stepLabel(s.key),
        status: s.status,
        statusLabel: stepStatusLabel(s.status),
        errorCode: s.errorCode ?? null,
        errorMessage: s.errorMessage ?? null,
        retrySafe: !!s.retrySafe,
        attempts: s.attempts ?? 0,
        detail: s.detail && typeof s.detail === 'object' ? s.detail : {},
        externalId: s.externalId ?? null,
    }))
}

/**
 * The links a finished operation offers: the team, the OpenProject project,
 * and every ledger resource with a URL.
 *
 * @param {object} state
 * @return {Array<{label: string, url: string|null, teamId?: string}>}
 */
export function resourceLinks(state) {
    const out = []
    const result = state?.result || {}
    if (result.project?.url) {
        out.push({ label: t('teamhub', 'Open project in OpenProject'), url: result.project.url })
    }
    for (const r of Array.isArray(result.resources) ? result.resources : []) {
        if (r.url && r.type === 'openproject_folder') {
            out.push({ label: t('teamhub', 'OpenProject project folder'), url: r.url })
        }
    }
    return out
}
