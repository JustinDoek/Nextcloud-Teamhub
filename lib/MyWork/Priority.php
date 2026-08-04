<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * Work-item priority (v4.5.21).
 *
 * Deliberately a small closed set rather than a number. Providers have wildly
 * different priority models (Deck has none at all; Approval has none either),
 * so a numeric scale would invent precision that does not exist. Four buckets
 * are enough to sort by and enough to render as a badge.
 *
 * `URGENT` is reserved for overdue work and expiring approvals — it is derived
 * by TeamHub from dates, not claimed by a provider, so "urgent" means the same
 * thing on every row.
 */
final class Priority {
    public const URGENT = 'urgent';
    public const HIGH   = 'high';
    public const NORMAL = 'normal';
    public const LOW    = 'low';

    /** Most urgent first — this is the sort order, not just a list. */
    public const ORDERED = [self::URGENT, self::HIGH, self::NORMAL, self::LOW];

    public static function isValid(string $priority): bool {
        return in_array($priority, self::ORDERED, true);
    }

    public static function rank(string $priority): int {
        $i = array_search($priority, self::ORDERED, true);
        return $i === false ? count(self::ORDERED) : (int)$i;
    }
}
