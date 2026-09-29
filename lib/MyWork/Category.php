<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The My Work categories (v4.5.21; simplified v4.10.20).
 *
 * These are TeamHub's vocabulary, not any provider's, and **the provider that
 * emits an item is the only thing that decides which one it lands in** — there
 * is no instance-wide status→category table any more (DESIGN.md §2.143).
 *
 * Order matters: ORDERED is the display order AND the urgency order used to
 * resolve an item that qualifies for more than one category. The spec's
 * "avoid duplicates with Action Required" rule is exactly this precedence —
 * an item that both requires action and is due today lands in ACTION_REQUIRED
 * and carries a "Today" label rather than appearing twice.
 *
 * Three of these are the whole model, and they are questions about the viewer,
 * not about the item:
 *
 *  - **ACTION_REQUIRED** — the next move is yours. This includes the work you
 *    have because of a role you hold: v4.10.20 folded the former TEAM_ADMIN
 *    category into it. A resource waiting to be reviewed is a task somebody
 *    assigned you by connecting it, and filing it below WAITING_FOR_OTHERS —
 *    below work you cannot act on at all — was telling a team admin their own
 *    queue was the least of it.
 *  - **WAITING_FOR_OTHERS** — you are in the workflow but the next move is
 *    somebody else's.
 *  - **COMPLETED** — it is over, and this is the record for the window.
 *
 * TODAY and UPCOMING are not a fourth and fifth state: they are the same open
 * work sorted by the clock, which is why TODAY is DERIVED below.
 */
final class Category {
    public const ACTION_REQUIRED    = 'action_required';
    public const TODAY              = 'today';
    public const UPCOMING           = 'upcoming';
    public const WAITING_FOR_OTHERS = 'waiting_for_others';
    public const COMPLETED          = 'completed';

    /**
     * Categories that no longer exist, and what they became (v4.10.20).
     *
     * A stored filter or a collapsed-section key written by an older client
     * still names `team_admin`; `migrate()` maps it forward so a saved view
     * does not quietly select nothing.
     */
    private const RETIRED = [
        'team_admin' => self::ACTION_REQUIRED,
    ];

    /** Display + urgency order. Index 0 is the most urgent. */
    public const ORDERED = [
        self::ACTION_REQUIRED,
        self::TODAY,
        self::UPCOMING,
        self::WAITING_FOR_OTHERS,
        self::COMPLETED,
    ];

    /**
     * Categories a provider may not assign directly, because TeamHub derives
     * them from the item's own dates rather than from source status.
     *
     * A provider says "this is upcoming"; whether it is actually due *today*
     * is a clock question, and the clock lives here so every provider agrees
     * on where the day boundary is.
     */
    public const DERIVED = [self::TODAY];

    public static function isValid(string $category): bool {
        return in_array($category, self::ORDERED, true);
    }

    /**
     * A category name as this version understands it: a retired one is
     * translated, anything else is returned unchanged for the caller to
     * validate.
     */
    public static function migrate(string $category): string {
        return self::RETIRED[$category] ?? $category;
    }

    /**
     * Lower is more urgent. Used to pick a winner when two categories both
     * apply, and to sort groups in the default "category and urgency" mode.
     */
    public static function rank(string $category): int {
        $i = array_search($category, self::ORDERED, true);
        return $i === false ? count(self::ORDERED) : (int)$i;
    }
}
