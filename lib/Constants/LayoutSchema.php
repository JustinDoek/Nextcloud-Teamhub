<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * What a home layout is allowed to contain, and how to clean one (v4.13.34).
 *
 * ── Why this file exists ─────────────────────────────────────────────────
 *
 * Until v4.13.34 exactly one thing wrote a layout — a member saving their own —
 * and `LayoutController` could own the question "is this a valid layout?"
 * privately. Phase 2 and 3 of issue #109 added three more writers: an instance
 * default, a default per kind of team, and a team's own. Four writers and one
 * reader of the same JSON, and the reader trusts what it finds.
 *
 * So the validation moved here rather than being called from the controller by
 * the new service, and certainly rather than being written a second time. A
 * widget id the controller accepts and the service does not is a card that can
 * be put in a default and then vanishes from every team that takes it; the
 * reverse is a card an administrator can place that no member's own save can
 * keep. Neither failure announces itself — this is the shape DESIGN §2.199 and
 * §2.200 are both about, found before it shipped rather than after.
 *
 * ── It is a cleaner, not a validator ────────────────────────────────────
 *
 * `sanitiseLayout()` drops what it does not recognise instead of refusing the
 * whole payload, and that is deliberate for the same reason it always was: a
 * client one version behind sends a widget this one has retired, and the right
 * answer is to keep the fourteen cards that are still real rather than to fail
 * the save and lose the member's arrangement. The one thing it will not do is
 * pass an unknown id through to storage.
 */
final class LayoutSchema {

    // ----------------------------------------------------------------
    // Default grid layout — 12 columns, 80 px row height.
    //
    // ── Heights are a rule now, not a number per widget (v4.13.33) ────
    //
    // Until this release each entry carried a height somebody had once
    // thought looked about right — Members 2, Calendar 3, File center 4 —
    // and an `autoFit` flag that was supposed to correct it by measurement
    // on first open. The measurement never worked (DESIGN §2.206), so what
    // shipped was the guesses: a team with four members opened with a
    // scrollbar inside a widget two thirds full of nothing.
    //
    // So: a widget that lists rows gets H_ROW, all of them the same, chosen
    // to hold five rows. A widget that is not a list gets the height its own
    // content needs, which is a number only because there is nothing to
    // derive it from.
    //
    // The x/y coordinates below still place a *saved* layout's new widgets
    // (mergeNewWidgets) and still describe the shipped arrangement. They are
    // not what a cascaded layout is drawn from any more: the client packs the
    // widgets the team actually shows into the grid in this array's order
    // (src/lib/packLayout.js), because which widgets those are is only known
    // there — see the comment on `isDefault` in getLayout().
    // ----------------------------------------------------------------

    /**
     * The height of every widget whose body is a list of rows.
     *
     * Five rows plus the card's own chrome. A grid row is 80 px with a 12 px
     * margin, so `h` rows of card is `92h - 12` px: 356 px at 4. A list row
     * is ~52 px (`MemberRow`: 16 px padding, a two-line body, a 1 px rule),
     * the card header is ~41 px, and a widget with its own tab bar — Members,
     * File center — spends another ~40 px on it. Five rows in the worst of
     * those is 341 px, which fits, and in the best of them six rows fit.
     *
     * Five because that is the number Justin asked for (2026-10-27) and it is
     * the right kind of number: enough to see that a list *is* a list and
     * what the top of it holds, without a widget that reaches the fold.
     */
    public const H_ROW = 4;

    /**
     * The message stream, which is not a list of rows but the page's main
     * column: nine rows (816 px) beside a right-hand column of two or three
     * cards.
     */
    public const H_STREAM = 9;

    public const DEFAULT_LAYOUT = [
        [
            'i'           => 'msgstream',
            'x'           => 0, 'y' => 0,
            'w'           => 9, 'h' => self::H_STREAM,
            'minW'        => 3, 'minH' => 3,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_STREAM,
        ],
        // Not a list: a name, a description and a line or two of meta. Three
        // rows rather than the two it had — at 184 px a description of any
        // length scrolled inside its own card. Hidden by default since
        // v4.13.1 (DashboardWidgets::DEFAULT_HIDDEN), which is why this was
        // never the complaint.
        [
            'i'           => 'widget-teaminfo',
            'x'           => 9, 'y' => 0,
            'w'           => 3, 'h' => 3,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 3,
        ],
        [
            'i'           => 'widget-members',
            'x'           => 9, 'y' => 6,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        // Department-only birthday card (three rows; packing removes it for
        // other templates, and for departments whose admin hides it).
        [
            'i'           => 'widget-birthdays',
            'x'           => 9, 'y' => 10,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-calendar',
            'x'           => 9, 'y' => 8,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-deck',
            'x'           => 9, 'y' => 11,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-activity',
            'x'           => 9, 'y' => 14,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-pages',
            'x'           => 9, 'y' => 17,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 1,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-files-center',
            // v4.3.7 — restored to y=20 after removing the standalone
            // widget-collectives (Wiki now renders inside widget-pages).
            'x'           => 9, 'y' => 20,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        [
            'i'           => 'widget-decisions',
            // v4.3.7 — restored to y=24 (see widget-files-center note).
            'x'           => 9, 'y' => 24,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        // v3.97.0 — Project Health widget. Only rendered for Advanced-project
        // members in Planning or Execution phase who can see both Budget and
        // Time (gated in TeamWidgetGrid). Positioned near the top of the
        // right column — this is a high-signal cockpit view an admin wants
        // above the fold. For existing users whose saved layout predates
        // this widget, mergeNewWidgets inserts it at y=2 and shifts every
        // other x=9 widget down by 4 (see the widget-project-health branch
        // there); for new users, this DEFAULT_LAYOUT ordering places it
        // naturally.
        //
        // Not a list: a roll-up of figures and bars, so it keeps a height of
        // its own rather than H_ROW.
        [
            'i'           => 'widget-project-health',
            'x'           => 9, 'y' => 2,
            'w'           => 3, 'h' => 4,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 4,
        ],
        // v4.9.3 — OpenProject Phase 1: the Project info widget, rendered
        // only when the team is linked to an OpenProject project (gated in
        // src/lib/activeWidgets.js on openProjectConfig.linked). Appended at
        // the bottom of the right column; mergeNewWidgets does the same for
        // existing users, so nobody's saved arrangement moves.
        //
        // v4.9.5 — the second widget of 4.9.3, `widget-openproject-work`, is
        // gone: the viewer's own work packages moved to My Work and the
        // project's went into the Upcoming tasks widget. Its id is pruned
        // from saved layouts in mergeNewWidgets like the legacy files ones.
        //
        // Not a list: project identity, status and dates.
        [
            'i'           => 'widget-openproject',
            'x'           => 9, 'y' => 28,
            'w'           => 3, 'h' => 5,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 5,
        ],
        // v4.10.27 — the service team's queue (`/service-teams`). Rendered
        // only on an active service team, for its members (gated in
        // src/lib/activeWidgets.js). It is the desk's main work surface, so
        // it goes where Project health goes — top of the right column, right
        // under Team info — and mergeNewWidgets inserts it there for saved
        // layouts too (TOP_INSERTED_WIDGETS). On every other team it is not
        // rendered and vertical compaction closes the gap.
        //
        // v4.13.33 — a list of requests, so H_ROW like every other list. It
        // held six rows before, and losing two of them is the price of the
        // rule: a desk that wants its queue taller resizes it once and that
        // is saved. Five requests is still the whole of most queues.
        [
            'i'           => 'widget-service-queue',
            'x'           => 9, 'y' => 2,
            'w'           => 3, 'h' => self::H_ROW,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => self::H_ROW,
        ],
        // v4.10.27 — the service team's request statistics. Appended at the
        // bottom of the right column: useful, but not what a desk looks at
        // first. Not a list — counts and a breakdown.
        [
            'i'           => 'widget-service-stats',
            'x'           => 9, 'y' => 33,
            'w'           => 3, 'h' => 5,
            'minW'        => 2, 'minH' => 2,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 5,
        ],
        // v4.10.50 — the grid of teams made outside TeamHub, on the team that
        // holds the adoption service. Full width at the bottom: it is a table,
        // which is a list that needs every column it has, so it keeps its own
        // height rather than H_ROW.
        [
            'i'           => 'widget-team-adoption',
            'x'           => 0, 'y' => 40,
            'w'           => 12, 'h' => 7,
            'minW'        => 6, 'minH' => 3,
            'isResizable' => true,
            'collapsed'   => false,
            'hSaved'      => 7,
        ],
        // v4.10.34–43 placed `widget-services` here; v4.10.44 retired it —
        // the team's own services and the builder live on the *Services*
        // tab. Pruned on GET with the other legacy ids.
    ];

    /**
     * Widgets mergeNewWidgets inserts at their DEFAULT_LAYOUT position,
     * shifting the rest of their column down, instead of appending them at
     * the bottom — the top-of-column cockpit widgets (v3.97.0 for Project
     * health; generalised in v4.10.27 for the service queue).
     */
    public const TOP_INSERTED_WIDGETS = ['widget-project-health', 'widget-service-queue'];

    public const DEFAULT_TAB_ORDER = ['home', 'talk', 'files', 'calendar', 'deck', 'collectives', 'timeline'];

    // Maximum allowed JSON payload size in bytes (64 KB).


    // v4.6.1 — any saved y at or above this is a leftover parking position from
    // the removed TeamView.applySnap(), which parked inactive widgets at
    // y=9999. Kept in sync with PARK_Y_THRESHOLD in TeamWidgetGrid.vue.
    public const PARK_Y_THRESHOLD = 1000;


    /**
     * Maximum stored size of a layout plus its tab order, in bytes.
     *
     * A full layout is about 3 KB. 64 KB is room for every widget TeamHub and
     * its integrations could plausibly register, and small enough that a
     * crafted payload cannot be used to fill a table.
     */
    public const MAX_PAYLOAD_BYTES = 65536;

    /**
     * Allowed static widget ids. Dynamic integration ids are matched by
     * pattern in {@see isAllowedWidgetId()}.
     *
     * Legacy ids (widget-files-favorites, widget-files-recent,
     * widget-files-shared, widget-services, widget-openproject-work) are
     * accepted on save for backward compatibility with an in-flight client and
     * stripped from stored layouts on every read by
     * `LayoutController::mergeNewWidgets()`.
     */
    public const ALLOWED_WIDGET_IDS = [
        'msgstream',
        'widget-teaminfo',
        'widget-members',
        'widget-birthdays',
        'widget-calendar',
        'widget-deck',
        'widget-activity',
        'widget-pages',
        'widget-files-center',
        'widget-decisions',
        'widget-project-health',
        // v4.9.3 — OpenProject Phase 1. Gates on the team's link.
        'widget-openproject',
        // v4.10.27 — the service team's queue and statistics.
        'widget-service-queue',
        'widget-service-stats',
        // v4.10.50 — teams made outside TeamHub.
        'widget-team-adoption',
        // Legacy — kept so saves from old clients are not rejected mid-migration.
        // v4.10.44 — the retired Services widget (now the Services tab).
        'widget-services',
        'widget-files-favorites',
        'widget-files-recent',
        'widget-files-shared',
        // v4.9.5 — the removed My OpenProject work widget; pruned on GET.
        'widget-openproject-work',
    ];

    /** Allowed built-in tab keys. Dynamic ones are matched by pattern. */
    public const ALLOWED_TAB_KEYS = [
        'home', 'talk', 'files', 'calendar', 'deck', 'collectives',
        'presence', 'decisions', 'timeline',
    ];

    /**
     * Whether a widget id may be stored in a layout.
     */
    public static function isAllowedWidgetId(string $id): bool {
        if (in_array($id, self::ALLOWED_WIDGET_IDS, true)) {
            return true;
        }
        // A legacy integration registration's widget.
        if (preg_match('/^widget-int-\d+$/', $id) === 1) {
            return true;
        }
        // v4.13.23 — an integration package's widget capability, keyed by
        // `teamhub_integ_cap.id`. A separate pattern rather than widening the
        // one above, because `widget-int-cap-7` and `widget-int-7` are two
        // different widgets and a pattern loose enough to accept both would
        // let one team's grid position land on the other.
        //
        // Capability rows are never deleted, so a position saved against one
        // survives the owning app being disabled, upgraded and reinstalled —
        // which is the same reason the per-team shown/hidden preferences hang
        // off this id.
        if (preg_match('/^widget-int-cap-\d+$/', $id) === 1) {
            return true;
        }
        return false;
    }

    /** Whether a tab key may be stored in a tab order. */
    public static function isAllowedTabKey(string $key): bool {
        if (in_array($key, self::ALLOWED_TAB_KEYS, true)) {
            return true;
        }
        if (preg_match('/^ext-\d+$/', $key) === 1) {
            return true;
        }
        // v4.13.23 — an integration package's iframe tab, keyed by
        // `teamhub_integ_cap.id`.
        if (preg_match('/^pkgtab-\d+$/', $key) === 1) {
            return true;
        }
        if (preg_match('/^link-\d+$/', $key) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Clean a layout array: keep the recognised items, normalise every field
     * on them, drop the rest.
     *
     * Bounds are the grid's own — 12 columns, and a height of at most 50 rows,
     * which is four thousand pixels and well past any card anybody wants.
     *
     * @param mixed $layout whatever arrived in the request body
     *
     * @return array<int, array<string, mixed>> never null; an empty array when
     *         nothing in the input was usable
     */
    public static function sanitiseLayout(mixed $layout): array {
        if (!is_array($layout)) {
            return [];
        }

        $clean = [];
        foreach ($layout as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['i'] ?? '');
            if (!self::isAllowedWidgetId($id)) {
                continue;
            }
            $clean[] = [
                'i'           => $id,
                'x'           => max(0, (int)($item['x'] ?? 0)),
                'y'           => max(0, (int)($item['y'] ?? 0)),
                'w'           => max(1, min(12, (int)($item['w'] ?? 4))),
                'h'           => max(1, min(50, (int)($item['h'] ?? 3))),
                'minW'        => (int)($item['minW'] ?? 1),
                'minH'        => (int)($item['minH'] ?? 1),
                'isResizable' => (bool)($item['isResizable'] ?? false),
                'collapsed'   => (bool)($item['collapsed'] ?? false),
                'hSaved'      => max(1, min(50, (int)($item['hSaved'] ?? (int)($item['h'] ?? 3)))),
            ];
            // v4.13.33 — `autoFit` is deliberately not carried through. See
            // the note in LayoutController::validateAndClean and DESIGN §2.206.
        }

        return $clean;
    }

    /**
     * Clean a tab order: the recognised keys, in the order given, once each.
     *
     * De-duplicated, which the per-member path never had to do because the
     * client built the array from a rendered tab bar. A default can be posted
     * by anything an administrator can reach.
     *
     * @param mixed $tabOrder whatever arrived in the request body
     *
     * @return string[]
     */
    public static function sanitiseTabOrder(mixed $tabOrder): array {
        if (!is_array($tabOrder)) {
            return [];
        }

        $clean = [];
        foreach ($tabOrder as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = (string)$key;
            if (self::isAllowedTabKey($key) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return $clean;
    }

    /**
     * Whether a layout and a tab order fit inside MAX_PAYLOAD_BYTES once
     * encoded. Checked on the cleaned arrays, so a rejected payload is one
     * that is genuinely too big rather than one padded with junk.
     */
    public static function fitsPayloadLimit(array $layout, array $tabOrder): bool {
        try {
            $bytes = strlen(json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                + strlen(json_encode($tabOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            return false;
        }

        return $bytes <= self::MAX_PAYLOAD_BYTES;
    }
}
