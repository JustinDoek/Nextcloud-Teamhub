<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * The one vocabulary for "which app is this" (v4.8.27).
 *
 * ── Why this file exists ─────────────────────────────────────────────────
 *
 * Four surfaces named the same apps four different ways, and each had its own
 * private translation or none at all:
 *
 *   - `teamhub_team_app_resources.app_id` — `talk`, `files`, `calendar`,
 *     `deck`, `collectives`. The registry, and the only record of what a team
 *     actually has.
 *   - `ManageTeamView.vue`'s definitions — `spreed` for Talk, translated by a
 *     private `appIdToResourceKey()` in `TeamController` and nowhere else.
 *   - `teamhub_template.apps` / `.modules` — `talk`…`deck`, plus `wiki` for
 *     Collectives and `pages` for Intravox.
 *   - `teamhub_team_apps.app_id` — `intravox`, the toggle-driven form of
 *     `pages`.
 *
 * So `wiki`, `pages`, `collectives`, `intravox` and `spreed` were five names
 * for three apps, and nothing could compare a template against a team without
 * silently getting it wrong. That is the reason a template has never had a
 * compliance check and the policy integration allow-list never found anything.
 *
 * **The registry's spelling is canonical**, because it is the one that records
 * reality rather than intent.
 */
final class TeamApps {

    /**
     * Every app a team can have, in the registry's spelling.
     *
     * `intravox` is here despite having no registry row — see TOGGLE_ONLY.
     */
    public const CANONICAL = ['talk', 'files', 'calendar', 'deck', 'collectives', 'intravox'];

    /**
     * Every other spelling in the codebase, mapped to the canonical one.
     *
     * Add to this map rather than translating in a caller. `TeamController`'s
     * private `appIdToResourceKey()` was exactly such a caller-local
     * translation, and it handled `spreed` alone because it only ever had to.
     */
    public const ALIASES = [
        // Manage Team's app definitions, and Nextcloud's own app id for Talk.
        'spreed' => 'talk',
        // Template module keys. Both provision a resource, which is why
        // `RESOURCE_MODULES` in src/constants/policy.js groups them under Apps
        // even though they are stored as modules.
        'wiki'   => 'collectives',
        'pages'  => 'intravox',
    ];

    /**
     * Apps whose presence is a stored toggle rather than a provisioned resource.
     *
     * Everything else is present because `teamhub_team_app_resources` holds an
     * active row for it. Intravox has no such row — `TeamController` calls it a
     * "toggle-driven app" and writes it to `teamhub_team_apps` — so it is the
     * one app whose absence from the registry means nothing.
     */
    public const TOGGLE_ONLY = ['intravox'];

    /** The registry origin meaning TeamHub provisioned this resource itself. */
    public const ORIGIN_CREATED = 'teamhub_create';

    /**
     * Normalise any spelling to the canonical one.
     *
     * An unknown id is returned unchanged rather than dropped: a resource
     * registered by another app through the integration API is a real app on a
     * real team, and silently losing it would understate what the team has.
     */
    public static function canonical(string $appId): string {
        return self::ALIASES[$appId] ?? $appId;
    }

    /**
     * Normalise a list, de-duplicating what the aliases collapse together.
     *
     * A template naming both `pages` (a module) and `intravox` would otherwise
     * produce the same app twice and make an exact comparison fail against a
     * team that has it once.
     *
     * @param list<string> $appIds
     * @return list<string>
     */
    public static function canonicalList(array $appIds): array {
        $out = [];
        foreach ($appIds as $appId) {
            $appId = self::canonical(trim((string)$appId));
            // Deduplicated by value, not by array key. App ids are not numeric
            // today, but `ConfidentialFilesService::cleanIds()` shipped a bug in
            // v4.8.24 doing exactly that and it is not worth repeating for the
            // sake of a shorter loop.
            if ($appId !== '' && !in_array($appId, $out, true)) {
                $out[] = $appId;
            }
        }

        return $out;
    }

    /** Is this app's presence a toggle rather than a provisioned resource. */
    public static function isToggleOnly(string $appId): bool {
        return in_array(self::canonical($appId), self::TOGGLE_ONLY, true);
    }

    /**
     * Keep only the ids whose presence on a team can actually be observed.
     *
     * **The filter that keeps template compliance honest.** A template's
     * `modules` list mixes two different kinds of thing: `pages` and `wiki`
     * provision a resource, while `decisions`, `presence`, `timeline` and
     * `messages` are feature switches that leave nothing on the team to look
     * at. Comparing a team against the whole list would find `decisions`
     * missing from every team on the instance and report all of them as
     * drifted — a check that fails universally is worse than no check.
     *
     * @param list<string> $appIds
     * @return list<string>
     */
    public static function observableList(array $appIds): array {
        return array_values(array_filter(
            self::canonicalList($appIds),
            static fn (string $appId): bool => in_array($appId, self::CANONICAL, true),
        ));
    }

    /**
     * Apps `ResourceService::createTeamResources()` can provision.
     *
     * Collectives is deliberately absent: it is canonical and observable, so a
     * team missing it is a real finding, but enabling it runs its own
     * provisioning path rather than the resource switch. Propagation reports it
     * rather than pretending it can create one.
     */
    public const PROVISIONABLE = ['talk', 'files', 'calendar', 'deck', 'intravox'];

    public static function isProvisionable(string $appId): bool {
        return in_array(self::canonical($appId), self::PROVISIONABLE, true);
    }

    /**
     * Template modules that are a per-team feature switch, not a resource
     * (v4.8.32).
     *
     * These are the four that `observableList()` filters out, because they
     * leave no row in the resource registry — but they are not unobservable.
     * Each has its own per-team storage:
     *
     *   `presence`   `teamhub_presence_config.presence_enabled`
     *   `decisions`  the Decisions team-config row
     *   `timeline`   appconfig `timeline_enabled_<teamId>`, absent means on
     *   `messages`   appconfig `messages_enabled_<teamId>`, absent means on
     *
     * `pages` and `wiki` are deliberately absent: they provision Intravox and
     * Collectives, so they are already canonical apps and travel the resource
     * path with everything else.
     */
    public const FEATURE_MODULES = ['decisions', 'presence', 'timeline', 'messages'];

    /**
     * Keep only the feature modules from a template's module list.
     *
     * @param list<string> $moduleIds
     * @return list<string>
     */
    public static function featureModules(array $moduleIds): array {
        return array_values(array_filter(
            array_map(static fn ($id): string => trim((string)$id), $moduleIds),
            static fn (string $id): bool => in_array($id, self::FEATURE_MODULES, true),
        ));
    }
}
