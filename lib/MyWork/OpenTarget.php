<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * How a My Work row opens (v4.5.24).
 *
 * §2.71 recorded the one thing the provider contract did not generalise: a
 * Deck card and a file open through the team's embed, but a decision is a
 * TeamHub tab, so opening one needed its own `else if` in `App.vue`. Every
 * future TeamHub-native provider would have needed another.
 *
 * The fix is to let the provider declare **which mechanism** opens its item
 * rather than letting the frontend infer it from `resourceType`. The set of
 * mechanisms below is closed and belongs to the TeamHub shell — a provider
 * picks one, it does not invent one. That is the whole difference: the
 * frontend now has one branch per *way of opening*, of which there are four
 * and always will be until the shell itself grows a fifth, instead of one
 * branch per provider, of which there is no limit.
 *
 * `TEAMHUB_VIEW` is deliberately not validated against a list of views here.
 * Which tabs exist is the frontend's fact (`TeamView.buildAllTabDescriptors()`
 * is the registry, and `TEAMHUB_VIEW_TARGETS` in `src/constants/myWork.js`
 * says which of them can pre-select a row). Duplicating that list server-side
 * would give it two homes and one of them would go stale. A view the shell
 * does not know falls back to the external link rather than doing nothing.
 *
 * Omitting `openTarget` entirely is legal and means EXTERNAL — a provider
 * written against the 4.5.21 contract keeps working, it just opens its items
 * in their own app.
 */
final class OpenTarget {

    /** The team's Deck tab, with the card open. Keys: `boardId`, `cardId`, `boardName`. */
    public const DECK_CARD = 'deck_card';

    /** The team's Files tab, with the file open. Key: `fileId`. */
    public const FILE = 'file';

    /** A TeamHub tab, optionally pre-selecting a row. Keys: `view`, `targetId`. */
    public const TEAMHUB_VIEW = 'teamhub_view';

    /**
     * The team's Calendar tab, with the event open (v4.5.25). Keys: `url` (the
     * backend's own event URL) and `calendarId`, which is the shape
     * `openEventInEmbed` has taken since v4.5.15 — the tab needs to know which
     * agenda to return to when the event is closed.
     *
     * The fifth mechanism, added by the meeting provider. §2.72 predicted this:
     * the set grows when the *shell* grows a new way to show something, which
     * is a different and much rarer event than adding a source.
     */
    public const CALENDAR_EVENT = 'calendar_event';

    /**
     * Manage team, at a tab and optionally a section (v4.5.45). Keys: `tab`,
     * `section`.
     *
     * The sixth mechanism, and it exists for the same reason CALENDAR_EVENT
     * did: the shell grew a place a row needs to point at. Team administration
     * is not a team *tab* — it is a different screen with its own tab set —
     * so TEAMHUB_VIEW could not describe it. It maps onto the store's existing
     * `manageTeamDeepLink` (v3.98.0), which the Project Compass already uses
     * to route into a specific Manage-team section.
     */
    public const MANAGE_TEAM = 'manage_team';

    /** Anything TeamHub cannot host: `resourceUrl` in a new browser tab. */
    public const EXTERNAL = 'external';

    public const KINDS = [
        self::DECK_CARD,
        self::FILE,
        self::TEAMHUB_VIEW,
        self::CALENDAR_EVENT,
        self::MANAGE_TEAM,
        self::EXTERNAL,
    ];

    /**
     * @param string      $tab     Manage-team tab key, e.g. `integrations`
     * @param string|null $section optional section within it
     * @return array<string,mixed>
     */
    public static function manageTeam(string $tab, ?string $section = null): array {
        return [
            'kind'    => self::MANAGE_TEAM,
            'tab'     => $tab,
            'section' => $section,
        ];
    }

    /**
     * @param string $boardName shown while the board loads, so the tab is not
     *                          blank during the round-trip
     * @return array<string,mixed>
     */
    public static function deckCard(int $boardId, int $cardId, string $boardName = ''): array {
        return [
            'kind'      => self::DECK_CARD,
            'boardId'   => $boardId,
            'cardId'    => $cardId,
            'boardName' => $boardName,
        ];
    }

    /** @return array<string,mixed> */
    public static function file(int $fileId): array {
        return ['kind' => self::FILE, 'fileId' => $fileId];
    }

    /**
     * @param string   $view     the shell's view key, e.g. `decisions`
     * @param int|null $targetId row to pre-select within that view; null just
     *                           opens the tab
     * @return array<string,mixed>
     */
    public static function teamHubView(string $view, ?int $targetId = null): array {
        return [
            'kind'     => self::TEAMHUB_VIEW,
            'view'     => $view,
            'targetId' => $targetId !== null && $targetId > 0 ? $targetId : null,
        ];
    }

    /**
     * @param string   $url        root-relative event URL, as the backend builds it
     * @param int|null $calendarId agenda to return to once the event is closed
     * @return array<string,mixed>
     */
    public static function calendarEvent(string $url, ?int $calendarId = null): array {
        return [
            'kind'       => self::CALENDAR_EVENT,
            'url'        => $url,
            'calendarId' => $calendarId !== null && $calendarId > 0 ? $calendarId : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function external(): array {
        return ['kind' => self::EXTERNAL];
    }

    public static function isValidKind(string $kind): bool {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * Normalise whatever a provider handed over.
     *
     * Same rule as every other field on WorkItem: a provider bug produces a
     * row that opens in the wrong place, never a broken response. An unknown
     * or malformed kind degrades to EXTERNAL, which always has somewhere to
     * go because `resourceUrl` is mandatory.
     *
     * @return array<string,mixed>|null null when the provider said nothing
     */
    public static function normalize(mixed $value): ?array {
        if (!is_array($value)) {
            return null;
        }
        $kind = (string)($value['kind'] ?? '');
        if (!self::isValidKind($kind)) {
            return self::external();
        }

        return match ($kind) {
            self::DECK_CARD => self::deckCard(
                (int)($value['boardId'] ?? 0),
                (int)($value['cardId'] ?? 0),
                (string)($value['boardName'] ?? ''),
            ),
            self::FILE => self::file((int)($value['fileId'] ?? 0)),
            // v4.6.18 — MANAGE_TEAM was in KINDS but had no arm here, so every
            // `manageTeam()` target passed `isValidKind()` above and then fell
            // through to the `default` below and went out on the wire as
            // EXTERNAL. The reader saw the row switch to the team and then open
            // the team's own URL in a second browser tab, which is what
            // `openMyWorkItemExternally` does — never Manage team. Three
            // providers were affected: the expiry row (`danger`/`expiry`) and
            // both TeamAdmin rows (`integrations`, `members`).
            //
            // An empty tab degrades to EXTERNAL rather than passing through.
            // Unlike TEAMHUB_VIEW — where the frontend checks the view against
            // `TEAMHUB_VIEW_TARGETS` and falls back on its own — nothing
            // downstream validates this one: `ManageTeamView` assigns
            // `activeTab = payload.tab` verbatim, so '' renders a Manage-team
            // screen with no tab selected. This class promises a row that opens
            // in the wrong place over one that opens broken.
            self::MANAGE_TEAM => (string)($value['tab'] ?? '') !== ''
                ? self::manageTeam(
                    (string)($value['tab'] ?? ''),
                    isset($value['section']) && (string)$value['section'] !== ''
                        ? (string)$value['section']
                        : null,
                )
                : self::external(),
            self::TEAMHUB_VIEW => self::teamHubView(
                (string)($value['view'] ?? ''),
                isset($value['targetId']) ? (int)$value['targetId'] : null,
            ),
            self::CALENDAR_EVENT => self::calendarEvent(
                (string)($value['url'] ?? ''),
                isset($value['calendarId']) ? (int)$value['calendarId'] : null,
            ),
            default => self::external(),
        };
    }
}
