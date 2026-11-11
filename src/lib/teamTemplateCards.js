/**
 * TeamHub — single source of truth for "which template cards the create-team
 * wizard offers, and in what order".
 *
 * Why this file exists
 * --------------------
 * `CreateTeamView.teamTypes()` was a literal array of five cards. The wizard
 * fetched the real template set from `GET /api/v1/templates` and used it for
 * every *value* a template carries — its apps, its modules, its expiry, its
 * default policy — but the cards themselves came from that array. So a
 * template outside the five had no card and could not be chosen.
 *
 * v4.13.24 gave administrators a **New template** button. The template it
 * created was invisible here: made, included in, documented, and unusable.
 * Justin found it within an hour of the walk-through (2026-10-24).
 *
 * That is the third surface in two releases to answer "what should I render?"
 * with an enumeration of what exists rather than a rule — after the home
 * widget grid and the team tab bar, both in DESIGN §2.199. The remedy is the
 * same one, and this file is the same shape as `activeWidgets.js` and
 * `teamTabs.js` for the same reason: **a pure function over server facts, with
 * a test on it.** A computed inside a 2,900-line SFC is where this kind of
 * rule goes to stop being checked.
 *
 * Any new rule about which templates are offered belongs here and nowhere
 * else.
 *
 * Added in v4.13.25.
 */

import { translate as t } from '@nextcloud/l10n'
import { templateDisplayName } from '../constants/policy.js'

/**
 * Presentation for the template keys TeamHub seeds — an icon, an accent and a
 * one-line description, none of which `teamhub_template` holds.
 *
 * **This map decides how a card looks, never whether it exists.** That is the
 * whole distinction the bug turned on. A key absent from here still gets a
 * card; it gets a neutral icon.
 *
 * The icon names resolve against the components `CreateTeamView` registers,
 * so adding one here means importing it there too.
 */
export const TEMPLATE_CARD_LOOK = {
    project: {
        icon: 'Briefcase',
        accent: 'project',
        description: () => t('teamhub', 'Time-bound work with clear goals'),
    },
    collaboration: {
        icon: 'AccountMultiple',
        accent: 'collaboration',
        description: () => t('teamhub', 'Ongoing team knowledge sharing'),
    },
    department: {
        icon: 'OfficeBuildingOutline',
        accent: 'department',
        description: () => t('teamhub', 'Organizational department or unit'),
    },
    service: {
        icon: 'LifebuoyIcon',
        accent: 'service',
        description: () => t('teamhub', 'Answers requests from the organisation'),
    },
    openproject: {
        icon: 'BriefcaseCheckOutline',
        accent: 'openproject',
        description: () => t('teamhub', 'A project managed in OpenProject'),
    },
}

/** What a template key nobody wrote presentation for looks like. */
export const TEMPLATE_CARD_FALLBACK = { icon: 'AccountGroup', accent: 'custom' }

/**
 * Whether a template gets a card at all.
 *
 * Two module gates, and both are **absent, not locked**: a module that is off
 * is hidden rather than explained (the Presence and Decisions rule, CLAUDE.md
 * § Permissions), and Service Teams have no reduced version, so a card
 * advertising what a licence would buy is the wrong shape.
 *
 * `serviceAvailable: null` means a status fetch is still in flight — "not yet
 * known" rather than "off", so the row of cards does not reflow when the
 * answer lands.
 *
 * Everything else is shown. A template exists because an administrator made
 * it, and that is reason enough.
 *
 * @param {string}   key                     template key
 * @param {object}   ctx
 * @param {?boolean} ctx.serviceAvailable    null while unknown
 * @param {boolean}  ctx.openProjectModuleOff
 * @return {boolean}
 */
export function templateCardApplies(key, { serviceAvailable = null, openProjectModuleOff = false } = {}) {
    if (key === 'service') {
        return serviceAvailable === null || !!serviceAvailable
    }
    if (key === 'openproject') {
        return !openProjectModuleOff
    }
    return true
}

/**
 * The cards, from the template set the server sent.
 *
 * Order is the server's, which orders by `sort_index` — the field the Policy
 * panel has always labelled "the order they appear when creating a team" and
 * which this screen did not honour until v4.13.25. It showed them in the order
 * someone had typed them into a computed.
 *
 * A seeded key keeps its translated label; a template an administrator created
 * reads as they wrote it (`templateDisplayName`). An administrator's own
 * description wins over the shipped one-liner.
 *
 * @param {Array<object>} templates          rows from GET /api/v1/templates
 * @param {object}        ctx
 * @param {?boolean}      ctx.serviceAvailable
 * @param {boolean}       ctx.openProjectModuleOff
 * @param {string}        ctx.openProjectLockedReason '' when it can be chosen
 * @return {Array<object>} card descriptors
 */
export function buildTemplateCards(templates, ctx = {}) {
    const { openProjectLockedReason = '' } = ctx

    return (templates || [])
        .filter(row => templateCardApplies(row.templateKey, ctx))
        .map(row => {
            const look = TEMPLATE_CARD_LOOK[row.templateKey] || TEMPLATE_CARD_FALLBACK
            const card = {
                id: row.templateKey,
                label: templateDisplayName(row.templateKey, row.label, row.isSeeded),
                description: row.description || (look.description ? look.description() : ''),
                icon: look.icon,
                accent: look.accent,
            }
            if (row.templateKey === 'openproject') {
                // v4.9.3 — locked, with the reason, when the creator cannot
                // use the integration: the project must be picked here, so a
                // card that cannot finish is a card that must say so before
                // it is clicked.
                card.locked = !!openProjectLockedReason
                card.lockedReason = openProjectLockedReason
            }
            return card
        })
}
