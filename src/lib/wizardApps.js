/**
 * TeamHub — which apps the create-team wizard may provision for a new team.
 *
 * Why this file exists
 * --------------------
 * The wizard has had no Applications step since v4.8.5 (DESIGN §2.110): the
 * template decides the apps. What was left of that step was one computed,
 * `appOptions`, which `applyTemplateDefaults()` read to drop the apps that
 * are not installed or that the classification does not permit. Nothing in
 * the template rendered it any more, so between 4.13.0 and 4.20.0 it was
 * deleted as dead code.
 *
 * The call stayed. `this.appOptions.map(...)` threw a TypeError inside
 * `mounted()`, so no app was ever preselected, the wizard sent
 * `apps: []`, and every team made in 4.20.0 got no Talk room, no team
 * folder, no calendar and no board (found on claudius 2026-11-11, team
 * "booyah"). The same throw skipped the rest of `mounted()`: the policy
 * context, the licence and bulk entitlements, the OpenProject capabilities
 * and the service claim. `check:vue-methods` only checks call sites
 * (`this.foo(`), so a property read on an undeclared name went through.
 *
 * Same remedy as `teamTemplateCards.js`: a pure function over server facts,
 * with a test on it, so the rule cannot look unused again.
 *
 * Added in v4.20.1.
 */

/** Every app a template may provision, in the order the wizard sends them. */
export const WIZARD_APPS = ['talk', 'files', 'calendar', 'deck']

/**
 * The app ids a new team may get.
 *
 * An app is offered when it is installed and the classification permits it.
 * Files is always installed: without Team folders the team gets a shared
 * folder instead, which `ResourceService::createTeamResources()` decides.
 *
 * The classification filter is the courtesy half; the server filters the
 * same list (DESIGN §2.103 on why the UI is never the gate).
 *
 * @param {{talk?: boolean, calendar?: boolean, deck?: boolean}} installed
 *        what `GET /api/v1/apps/check` reported
 * @param {string[]|null} allowed the classification's `integrations_allowed`,
 *        or null when it does not restrict (an empty list means the same)
 * @return {string[]}
 */
export function availableWizardApps(installed, allowed) {
    const isInstalled = {
        talk: !!installed?.talk,
        files: true,
        calendar: !!installed?.calendar,
        deck: !!installed?.deck,
    }
    const restricts = Array.isArray(allowed) && allowed.length > 0
    return WIZARD_APPS
        .filter(id => isInstalled[id])
        .filter(id => !restricts || allowed.includes(id))
}
