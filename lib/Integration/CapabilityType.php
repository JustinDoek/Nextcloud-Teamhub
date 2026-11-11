<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * The capability types an integration package may register (v4.13.23).
 *
 * ## Why a type, and why only three of them implemented
 *
 * The old model had `integration_type` on the registration row itself, so
 * "which app is this" and "what does it do" were the same fact and an app
 * could own exactly one of each. Moving the type down to the capability is
 * what lets one package carry two widgets and a tab. The type is stored as a
 * short string rather than an enum column so a later version can add a value
 * without a migration — `SUPPORTED` below is the gate, not the schema.
 *
 * Four types ship: {@see WIDGET}, {@see WIDGET_IFRAME}, {@see MENU_ITEM} and
 * {@see MY_WORK_PROVIDER}. Each has an existing TeamHub contract behind it
 * (`ITeamHubWidget`, the sandboxed iframe, `OCA\TeamHub\MyWork\IWorkProvider`),
 * which is the condition for exposing one at all: a capability type with no
 * contract behind it is a promise TeamHub cannot keep.
 *
 * {@see FUTURE} names the types the model is shaped for and deliberately does
 * not accept yet. They are listed so the shape is on the record — and so a
 * registration that names one gets told "not yet" rather than "unknown",
 * which is the difference between a developer waiting and a developer
 * guessing.
 *
 * ## Visual versus non-visual
 *
 * The distinction the whole governance model turns on. A **visual** capability
 * occupies space in a team's workspace, so a team administrator may hide it.
 * A **non-visual** capability contributes somewhere else entirely — My Work,
 * activity, a lifecycle hook — and a team-level hide must have no effect on
 * it whatsoever. {@see isVisual()} is the one place that answers this, so the
 * rule cannot drift between the widget path, the iframe path and My Work.
 */
final class CapabilityType {

    /** A card in the team's widget grid, backed by an `ITeamHubWidget`. */
    public const WIDGET = 'widget';

    /** A tab in the team's tab bar, backed by an iframe URL. */
    public const MENU_ITEM = 'menu_item';

    /**
     * A card in the team's widget grid whose body the owning app draws
     * itself, backed by an iframe URL (v4.13.24).
     *
     * ## Why a fourth type rather than a flag on WIDGET
     *
     * {@see WIDGET} has exactly one content shape: `getWidgetData()` returns
     * `items`, and every row is flattened to a label, a value, an icon and a
     * URL, capped at twenty, with up to ten actions. That is a good contract
     * for a list and the only contract there was — so an app whose card is a
     * chart, a board, a form or a floor plan had no way to say so. It could
     * register a *tab* and draw anything it liked on a whole canvas, or a
     * widget and emit rows. Nothing in between.
     *
     * This is the in-between: TeamHub owns the card — its place in the grid,
     * its header, its title, its collapse control, the team's shown/hidden
     * preference — and the app owns the inside of it. The same bargain the
     * tab already makes, at card size.
     *
     * It is its own type because the two differ in what TeamHub *does*, not
     * in how a card looks: a `widget` names a PHP class TeamHub constructs
     * and calls on the critical path of the team's home view, and a
     * `widget_iframe` names a URL the browser fetches. Different validation
     * (a class that must exist and must never arrive over HTTP, against a URL
     * the `IframeUrl` rule has to accept twice), different failure mode (an
     * error card against a frame that does not load), different cost. A flag
     * on one type would have made `providerClass` and `iframeUrl` each
     * conditionally required and `getWidgetData()` conditionally called,
     * which is two types wearing one name.
     */
    public const WIDGET_IFRAME = 'widget_iframe';

    /** A My Work source, backed by an `OCA\TeamHub\MyWork\IWorkProvider`. */
    public const MY_WORK_PROVIDER = 'my_work_provider';

    /** Types registration accepts today. */
    public const SUPPORTED = [
        self::WIDGET,
        self::WIDGET_IFRAME,
        self::MENU_ITEM,
        self::MY_WORK_PROVIDER,
    ];

    /**
     * Types the model is shaped for and does not accept yet.
     *
     * Each needs a reusable TeamHub contract before it can be honoured; until
     * then accepting one would register a provider class nothing ever calls.
     */
    public const FUTURE = [
        'whats_new_provider',
        'activity_provider',
        'timeline_provider',
        'upcoming_events_provider',
        'provisioning_provider',
        'lifecycle_provider',
    ];

    /** Types that occupy space in a team's workspace. */
    public const VISUAL = [
        self::WIDGET,
        self::WIDGET_IFRAME,
        self::MENU_ITEM,
    ];

    /**
     * Types that render as a card in the team's widget grid (v4.13.24).
     *
     * Both widget types share a grid position, a collapse state and the
     * team's shown/hidden preference, so anything that asks "is this a card
     * on the home view" asks here rather than testing `=== WIDGET` and
     * missing the iframe one — which is the mistake that kept the package
     * widgets out of the grid in the first place.
     */
    public const GRID_CARD = [
        self::WIDGET,
        self::WIDGET_IFRAME,
    ];

    /**
     * Does a team administrator's shown/hidden preference apply to this type?
     *
     * The single answer. A `false` here is what guarantees that hiding a
     * widget cannot stop a My Work provider — see
     * {@see \OCA\TeamHub\Service\IntegrationStateService}.
     */
    public static function isVisual(string $type): bool {
        return in_array($type, self::VISUAL, true);
    }

    public static function isSupported(string $type): bool {
        return in_array($type, self::SUPPORTED, true);
    }

    /** Does this type render as a card in the team's widget grid? */
    public static function isGridCard(string $type): bool {
        return in_array($type, self::GRID_CARD, true);
    }

    public static function isFuture(string $type): bool {
        return in_array($type, self::FUTURE, true);
    }
}
