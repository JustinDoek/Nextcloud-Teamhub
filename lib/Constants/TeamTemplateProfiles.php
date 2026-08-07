<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * Server-side mirror of the team-creation wizard's template profiles.
 *
 * ⚠ MIRROR — KEEP IN SYNC WITH `src/components/CreateTeamView.vue`
 *   → the `templateProfile()` computed property.
 *
 * Same convention as `src/constants/uiTokens.js` ↔ `src/styles/widget-tokens.css`
 * (SKILLS.md § Design tokens): two files hold the same values because two
 * runtimes need them, and each one names the other so a change to one is a
 * visible obligation to change the other.
 *
 * Why a mirror rather than one source? The wizard applies its profile in the
 * browser before the team exists, so it cannot read a server value without an
 * extra round-trip on every template switch; the bulk importer (v4.6.6) runs
 * entirely server-side and has no wizard to ask. Rewiring the wizard to fetch
 * this is logged as a HANDOFF.md follow-up — it is a wizard change, not an
 * importer change, and doing it here would have put a network call in the
 * critical path of the flow every team creation goes through.
 *
 * What lives here is exactly what the wizard's profile object holds, minus the
 * two presentational keys (`subtitle`, `placeholder`) which have no server-side
 * meaning:
 *
 *   apps    — which app resources the template provisions. The wizard stores
 *             'create' | null per app; here it is a bool, because the importer
 *             never connects an existing resource (there is no picker in a CSV).
 *   config  — the Circles privacy bitmask, as named booleans. `configBitmask()`
 *             folds them into the integer `TeamService::updateTeamConfig()` takes,
 *             using the same bit order as the wizard's `configValue` computed.
 *   modules — the TeamHub feature toggles.
 *
 * Verification that the mirror is honest is manual and lives in the session's
 * test plan: create one team through the wizard and one through the importer
 * with identical inputs, then diff `teamhub_team_apps`, `teamhub_team_type`,
 * `teamhub_project`, the module config tables and `circles_circle.config`.
 */
final class TeamTemplateProfiles {

    /**
     * Template ids, in the order the wizard's team-type cards render them.
     * A subset of {@see \OCA\TeamHub\Service\TeamTypeService::ALLOWED} — kept
     * as its own constant so the CSV validator can name the allowed set
     * without depending on the service.
     */
    public const TEMPLATES = ['collaboration', 'project', 'department'];

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
     * The Circles config integer for this template.
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
