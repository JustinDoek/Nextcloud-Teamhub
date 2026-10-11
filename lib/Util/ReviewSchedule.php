<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

/**
 * When a decision's next review falls due, and when its approvers are told
 * (v4.11.18).
 *
 * A pure helper with no dependencies, because three callers need the same
 * arithmetic and the alternative was a cycle: `DecisionCategoryService`
 * recomputes due dates when a category's review period changes,
 * `DecisionReviewService` moves the date on when a review is completed, and
 * `DecisionReviewJob` decides which decisions have come within the notice
 * window. The first two cannot depend on each other.
 *
 * **Calendar months, in UTC.** `P6M` on a `DateTimeImmutable` is what a team
 * admin means by "every six months" — 1 March plus six months is 1
 * September, not 1 September minus two days. UTC rather than the viewer's
 * zone, deliberately: the value is a shared deadline on a team's record,
 * months away, and resolving it per viewer would give two approvers of the
 * same category two different due dates.
 *
 * PHP's own overflow rule applies and is the right one: 31 January plus one
 * month is 3 March (or 2 March in a leap year). A review period is a policy
 * interval, not an anniversary, so a date that lands two days late once a
 * year costs nothing and special-casing it would cost a rule nobody could
 * predict.
 */
final class ReviewSchedule {

    /** How long before the due date the approvers are told. Two weeks. */
    public const NOTICE_SECONDS = 14 * 24 * 60 * 60;

    /**
     * The next review date, or null when the category asks for no review.
     *
     * @param int $anchor  The moment the clock starts: the approval, or the
     *                     completion of the previous review, whichever is
     *                     later. See `DecisionReviewService::anchorFor()`.
     * @param int $months  The category's review period. 0 = never.
     */
    public static function dueAt(int $anchor, int $months): ?int {
        if ($months <= 0 || $anchor <= 0) {
            return null;
        }

        try {
            return (new \DateTimeImmutable('@' . $anchor))
                ->add(new \DateInterval('P' . $months . 'M'))
                ->getTimestamp();
        } catch (\Throwable) {
            // An unparseable interval can only come from a month count that
            // validation already refuses. Falling back to "no review" fails
            // closed: nobody is asked to review something on a date we could
            // not compute.
            return null;
        }
    }

    /**
     * The moment the approvers should hear about a review due at $dueAt.
     *
     * Not clamped to the future on purpose: a decision whose due date is
     * already inside the window — a period shortened today, or a period set
     * for the first time on an old decision — should produce its notice on
     * the next pass rather than skip a cycle silently.
     */
    public static function noticeAt(int $dueAt): int {
        return $dueAt - self::NOTICE_SECONDS;
    }

    /** Is a review due at $dueAt within the notice window at $now? */
    public static function isWithinNotice(?int $dueAt, int $now): bool {
        return $dueAt !== null && $dueAt > 0 && $now >= self::noticeAt($dueAt);
    }
}
