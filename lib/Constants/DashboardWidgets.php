<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * Dashboard defaults a team gets before anybody has an opinion (v4.13.1).
 *
 * ── Why this file exists ─────────────────────────────────────────────────
 *
 * The team-wide "which widgets does this dashboard show" list lives in one
 * app-config value per team (`dashboard_hidden_<teamId>`), and it is read in
 * two places that must not drift: `LayoutController::dashboardConfigFor()`
 * (the layout bundle every team open fetches) and
 * `TeamController::readDashboardConfig()` (the Manage team screen's own
 * getter and the response of its setter). They were already documented as
 * "kept in sync"; this file is what makes that true rather than hoped for.
 *
 * ── The absent-versus-empty distinction, which is the whole point ────────
 *
 * `DEFAULT_HIDDEN` applies only while the app-config key has **never been
 * written**. The moment a team administrator touches any widget switch, the
 * full list is stored — including the empty list, which then means "this team
 * decided to show everything" and is honoured as such.
 *
 * So the read is deliberately against an **empty-string** default rather than
 * `'[]'`: `''` is "nobody has said", `'[]'` is "somebody said show them all".
 * A reader that passes `'[]'` as the default cannot tell those apart, and this
 * default would then silently reassert itself over an administrator's choice
 * every time they showed every widget.
 */
class DashboardWidgets {

    /**
     * Widget ids hidden on a team whose dashboard has never been configured.
     *
     * **Team info is hidden by default since v4.13.1** because the team banner
     * now carries the two things that widget existed to show — the team's name
     * and its description — above the grid and on every tab, and the three
     * warning strips that used to live inside its card (resource review, team
     * expiry, unfinished provisioning) moved out to `TeamNotices` so that
     * hiding it loses nothing. DESIGN §2.185.
     *
     * It is a default, not a removal: the widget is still in
     * `LayoutController::ALLOWED_WIDGET_IDS` and still listed in Manage team →
     * Settings → Widgets, where one switch brings it back for the whole team.
     */
    public const DEFAULT_HIDDEN = ['widget-teaminfo'];

    /**
     * The whole `dashboardConfig` payload, built in one place (v4.13.35).
     *
     * Two readers feed this to the **same** store value: the layout bundle
     * (`LayoutController::dashboardConfigFor()`) and the Manage team screen's
     * own getter and setter response (`TeamController::readDashboardConfig()`).
     * They were documented as "kept in sync" and this class's header says that
     * documenting it is what this file exists to replace — and then v4.13.34
     * added two fields to one of them and the screen that administers a team
     * layout reported there wasn't one.
     *
     * So the shape lives here now. A field added to the payload is added once;
     * a reader that cannot supply an argument cannot compile.
     *
     * @param string     $storedHidden The raw `dashboard_hidden_<teamId>` value
     * @param string     $defaultTab   The raw `dashboard_tab_<teamId>` value
     * @param array|null $teamLayout    `LayoutDefaultService::forTeam()`, or null
     *
     * @return array{hidden_widgets: string[], default_tab: string, team_layout: ?array, team_layout_mode: ?string}
     */
    public static function configFor(string $storedHidden, string $defaultTab, ?array $teamLayout, bool $anniversariesEnabled = true): array {
        return [
            'hidden_widgets' => self::hiddenFrom($storedHidden),
            'default_tab'    => $defaultTab,
            'anniversary_enabled' => $anniversariesEnabled,
            // Only how big it is and when it was set. The layout itself is
            // kilobytes and reaches the people it is for through the bundle's
            // `layout`; the screens that read this one administer it rather
            // than draw it.
            'team_layout'      => $teamLayout !== null
                ? ['widgets' => count($teamLayout['layout']), 'updatedAt' => $teamLayout['updatedAt']]
                : null,
            'team_layout_mode' => $teamLayout['mode'] ?? null,
        ];
    }

    /**
     * The stored hidden-widget list for a team, or the default when no
     * administrator has configured this team's dashboard.
     *
     * @param string $stored The raw app-config value (`''` when never written)
     *
     * @return string[]
     */
    public static function hiddenFrom(string $stored): array {
        if ($stored === '') {
            return self::DEFAULT_HIDDEN;
        }

        $decoded = json_decode($stored, true);
        // `array_is_list` and not merely `is_array`: a JSON *object* decodes to
        // an array too, and `{"widget-teaminfo":false}` would otherwise come
        // back as the single hidden widget `"false"` — a value that hides
        // nothing and reads, in the Manage team switches, as a team that chose
        // to show everything.
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return self::DEFAULT_HIDDEN;
        }

        return array_values(array_map('strval', $decoded));
    }
}
