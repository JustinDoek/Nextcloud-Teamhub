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
 *
 * `$warnings` (v4.9.7) is the other honest signal: the provider answered,
 * but not fully or not cleanly — a source that needs the viewer to
 * reconnect an account (`auth_required`), a source that could read some of
 * its projects and not others (`partial`), a source whose answer came from
 * a cache because the live read failed (`stale`). Each is a code the
 * frontend turns into one notice, and none of them is an error: the rows
 * that did arrive are real. A provider with nothing to say leaves it empty.
 */
final class WorkItemPage {

    /** The viewer's source account is refused; they have to reconnect. */
    public const WARN_AUTH_REQUIRED = 'auth_required';
    /** Some of the source's scopes (projects) could not be read. */
    public const WARN_PARTIAL = 'partial';
    /** The provider stopped early to keep inside its time budget. */
    public const WARN_BUDGET = 'budget';
    /** The viewer has not connected the source at all (informational). */
    public const WARN_NOT_CONNECTED = 'not_connected';

    /**
     * @param WorkItem[]   $items
     * @param int          $total     provider's own count before TeamHub's paging
     * @param bool         $truncated provider stopped at the cap
     * @param list<string> $warnings  WARN_* codes, deduplicated
     */
    public function __construct(
        public readonly array $items = [],
        public readonly int $total = 0,
        public readonly bool $truncated = false,
        public readonly array $warnings = [],
    ) {
    }

    public static function empty(): self {
        return new self([], 0, false, []);
    }
}
