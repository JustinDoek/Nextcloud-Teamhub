<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * The team-creation templates.
 *
 * **v4.8.2 — renamed from `TeamTemplateProfiles`** (Track F2a,
 * `TRACK-F2-DESIGN.md` §6.1). The old name called this a *profile*, and that
 * word now means something else: a policy profile governs a team's settings,
 * while a template decides what a new team is provisioned *with*. Renamed
 * before the word was redefined around it.
 *
 * **v4.8.3 — the mirror is gone.** `CreateTeamView.vue` no longer holds a copy
 * of the per-template defaults: it fetches the whole set from
 * `GET /api/v1/templates` once when it mounts, so switching template stays a
 * local lookup and the round trip is not in the critical path — which was the
 * original objection to removing it. `TeamImportService` reads the same table.
 * There is no longer a second place to edit.
 *
 * **What this class is now, and all it is:**
 *
 *   1. **The vocabulary.** `TEMPLATES`, `APPS` and `MODULES` say which keys
 *      exist at all. Both the importer and `PolicyService` validate against
 *      them, and they are code-level truth rather than admin data — an admin
 *      who could add an app key could add one nothing provisions.
 *   2. **The seed.** `Version000408002` copied `PROFILES` into
 *      `teamhub_template` on upgrade. Nothing reads `PROFILES`,
 *      `forTemplate()`, `appsToCreate()`, `modules()` or `configBitmask()` at
 *      runtime any more — only that migration does, and only on an instance
 *      that has not run it yet.
 *
 * **So do not edit `PROFILES` expecting a behaviour change.** On any instance
 * past 4.8.2 the seed has already run and the live values are rows; edit them
 * on Admin → TeamHub → Policy. Changing them here only affects an instance
 * upgrading from before 4.8.2.
 *
 * What lives here is exactly what the wizard's profile object holds, minus the
 * two presentational keys (`subtitle`, `placeholder`) which have no server-side
 * meaning:
 *
 *   apps    — which app resources the template provisions. The wizard stores
 *             'create' | null per app; here it is a bool, because the importer
 *             never connects an existing resource (there is no picker in a CSV).
 *   config  — the Circles privacy bitmask, as named booleans. **Preselection
 *             only — this is not policy.** See `configBitmask()`.
 *   modules — the TeamHub feature toggles.
 *
 * Verification that the mirror is honest is manual and lives in the session's
 * test plan: create one team through the wizard and one through the importer
 * with identical inputs, then diff `teamhub_team_apps`, `teamhub_team_type`,
 * `teamhub_project`, the module config tables and `circles_circle.config`.
 */
final class TeamTemplates {

    /**
     * Template ids, in the order the wizard's team-type cards render them.
     * A subset of {@see \OCA\TeamHub\Service\TeamTypeService::ALLOWED} — kept
     * as its own constant so the CSV validator can name the allowed set
     * without depending on the service.
     */
    // v4.9.3 — 'openproject': a project whose engine is OpenProject rather
    // than TeamHub's own project module. Seeded by Version000409004; the
    // wizard requires an OpenProject project to be picked at creation.
    public const TEMPLATES = ['collaboration', 'project', 'department', 'openproject'];

    /** App resource keys a template may provision. */
    public const APPS = ['talk', 'files', 'calendar', 'deck'];

    /** Module keys a template may switch on. */
    public const MODULES = ['decisions', 'presence', 'timeline', 'messages', 'pages', 'wiki'];

    /**
     * @var array<string, array{apps: array<string,bool>, config: array<string,bool>, modules: array<string,bool>}>
     */
    public const PROFILES = [
        // v3.99.6 — Talk is preselected for project teams; Advanced is the
        // default project mode and the wizard lets the user uncheck it, so the
        // preselection lives at profile level. Mirrored verbatim.
        'project' => [
            'apps'    => ['talk' => true, 'files' => true, 'calendar' => true,  'deck' => true],
            'config'  => ['open' => false, 'invite' => true,  'request' => false, 'visible' => false, 'protected' => false],
            'modules' => ['decisions' => true, 'presence' => false, 'timeline' => true,  'messages' => true, 'pages' => true, 'wiki' => false],
        ],
        'collaboration' => [
            'apps'    => ['talk' => true, 'files' => true, 'calendar' => false, 'deck' => false],
            'config'  => ['open' => false, 'invite' => true,  'request' => false, 'visible' => true,  'protected' => false],
            'modules' => ['decisions' => true, 'presence' => false, 'timeline' => false, 'messages' => true, 'pages' => true, 'wiki' => false],
        ],
        'department' => [
            'apps'    => ['talk' => true, 'files' => true, 'calendar' => true,  'deck' => false],
            'config'  => ['open' => false, 'invite' => false, 'request' => false, 'visible' => true,  'protected' => false],
            'modules' => ['decisions' => true, 'presence' => true,  'timeline' => false, 'messages' => true, 'pages' => true, 'wiki' => false],
        ],
        // v4.9.3 — OpenProject project. No Deck and no Timeline: work packages
        // and the Gantt live in OpenProject, and a second task board would be
        // the duplication the integration exists to avoid. Mirrored verbatim
        // into Version000409004's literal seed.
        'openproject' => [
            'apps'    => ['talk' => true, 'files' => true, 'calendar' => true,  'deck' => false],
            'config'  => ['open' => false, 'invite' => true,  'request' => false, 'visible' => false, 'protected' => false],
            'modules' => ['decisions' => true, 'presence' => false, 'timeline' => false, 'messages' => true, 'pages' => true, 'wiki' => false],
        ],
    ];

    /**
     * The full profile for a template, falling back to `collaboration` exactly
     * as the wizard's `profiles[this.form.teamType] || profiles.collaboration`
     * does. Callers that care about an unknown template validate it first.
     *
     * @return array{apps: array<string,bool>, config: array<string,bool>, modules: array<string,bool>}
     */
    public static function forTemplate(string $template): array {
        return self::PROFILES[$template] ?? self::PROFILES['collaboration'];
    }

    /**
     * App ids this template provisions, as the list
     * {@see \OCA\TeamHub\Service\ResourceService::createTeamResources()} takes.
     *
     * @return list<string>
     */
    public static function appsToCreate(string $template): array {
        $out = [];
        foreach (self::forTemplate($template)['apps'] as $app => $enabled) {
            if ($enabled) {
                $out[] = $app;
            }
        }
        return $out;
    }

    /**
     * The Circles config integer this template **preselects** in the wizard.
     *
     * **v4.8.2 — this is preselection, not policy.** Track F2's design review
     * (DESIGN §2.103) established that a per-team-type config bitmask was never
     * a control: a team admin can change any of these bits from Contacts, and
     * "project teams are invite-only" was a convention the app could not keep.
     * Policy over these bits now lives in a policy profile, which is per
     * *sensitivity* rather than per team type, and which is detected and
     * reported when it diverges.
     *
     * These five booleans survive because deleting them would change behaviour
     * on every instance — a Collaboration team opens the wizard preselected
     * invite + visible today, and Track F ships inert or it does not ship. They
     * are never locked, never scanned, and have no `PolicyField` entry. A
     * profile that governs the same bit overrides them.
     *
     * Bit order mirrors `CreateTeamView.vue`'s `configValue` computed exactly,
     * including its rule that system bits (CFG_SINGLE, CFG_SYSTEM, CFG_NO_OWNER,
     * CFG_HIDDEN, CFG_BACKEND) are never written by TeamHub — Circles manages
     * them and setting them on a user team corrupts it.
     */
    public static function configBitmask(string $template): int {
        $config = self::forTemplate($template)['config'];
        $value  = 0;
        if ($config['open'])      { $value |= CirclesConfig::CFG_OPEN; }
        if ($config['invite'])    { $value |= CirclesConfig::CFG_INVITE; }
        if ($config['request'])   { $value |= CirclesConfig::CFG_REQUEST; }
        if ($config['protected']) { $value |= CirclesConfig::CFG_PROTECTED; }
        if ($config['visible'])   { $value |= CirclesConfig::CFG_VISIBLE; }
        return $value;
    }

    /**
     * Module toggles for this template.
     *
     * @return array<string,bool>
     */
    public static function modules(string $template): array {
        return self::forTemplate($template)['modules'];
    }
}
