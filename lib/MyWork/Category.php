<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The My Work categories (v4.5.21; TEAM_ADMIN added v4.5.45).
 *
 * These are TeamHub's vocabulary, not any provider's. A provider maps its own
 * source statuses onto them (see MyWorkConfigService::getCategoryMappings for
 * the admin-configurable half of that mapping).
 *
 * Order matters: ORDERED is the display order AND the urgency order used to
 * resolve an item that qualifies for more than one category. The spec's
 * "avoid duplicates with Action Required" rule is exactly this precedence —
 * an item that both requires action and is due today lands in ACTION_REQUIRED
 * and carries a "Today" label rather than appearing twice.
 *
 * **TEAM_ADMIN sits below WAITING_FOR_OTHERS and above COMPLETED**, and it is
 * the one category that is not about the viewer's own work. It carries the
 * housekeeping a team admin owes their team — today, resources someone
 * connected that need reviewing. It ranks low deliberately: an unreviewed
 * resource is real work but it is never more urgent than a deadline, and
 * putting it above one would make the whole queue read as noise.
 */
final class Category {
    public const ACTION_REQUIRED    = 'action_required';
    public const TODAY              = 'today';
    public const UPCOMING           = 'upcoming';
    public const WAITING_FOR_OTHERS = 'waiting_for_others';
    /** v4.5.45 — admin housekeeping, not the viewer's own deliverables. */
    public const TEAM_ADMIN         = 'team_admin';
    public const COMPLETED          = 'completed';

    /** Display + urgency order. Index 0 is the most urgent. */
    public const ORDERED = [
        self::ACTION_REQUIRED,
        self::TODAY,
        self::UPCOMING,
        self::WAITING_FOR_OTHERS,
        self::TEAM_ADMIN,
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
     * Lower is more urgent. Used to pick a winner when two categories both
     * apply, and to sort groups in the default "category and urgency" mode.
     */
    public static function rank(string $category): int {
        $i = array_search($category, self::ORDERED, true);
        return $i === false ? count(self::ORDERED) : (int)$i;
    }
}
