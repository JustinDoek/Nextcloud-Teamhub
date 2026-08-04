<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * A provider's answer to one WorkQuery (v4.5.21).
 *
 * `$truncated` is the honest signal that the provider hit `perProviderCap`
 * and stopped, rather than genuinely running out of work. The UI shows a
 * "showing the most urgent N" note instead of implying the list is complete —
 * silently truncating a personal work queue is how someone misses a deadline.
 */
final class WorkItemPage {

    /**
     * @param WorkItem[] $items
     * @param int        $total     provider's own count before TeamHub's paging
     * @param bool       $truncated provider stopped at the cap
     */
    public function __construct(
        public readonly array $items = [],
        public readonly int $total = 0,
        public readonly bool $truncated = false,
    ) {
    }

    public static function empty(): self {
        return new self([], 0, false);
    }
}
