<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\DecisionReviewService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily pass that opens the periodic review of every approved decision whose
 * review period has come within two weeks (v4.11.18).
 *
 * Daily rather than hourly: the window is two weeks wide, so the finest
 * resolution that means anything here is a day, and twenty-four passes a day
 * over the same rows would be twenty-three queries nobody reads. The sweep
 * body lives in `DecisionReviewService::sweepDueReviews()`; this job is the
 * scheduler and the logger, like `MilestoneAutoPostJob`.
 *
 * Parallel runs are off. The service stamps `review_notified_at` in the same
 * iteration that opens the review, so two runners could only double-open a
 * decision caught between the read and that write — cheaper to forbid than
 * to lock a row over.
 *
 * Time-insensitive on purpose: after downtime the right behaviour is to
 * catch up, because a review the approvers were not told about is a review
 * that silently did not happen.
 */
class DecisionReviewJob extends TimedJob {

    public function __construct(
        ITimeFactory                           $time,
        private readonly DecisionReviewService $reviews,
        private readonly LoggerInterface       $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        $this->setAllowParallelRuns(false);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $result = $this->reviews->sweepDueReviews();
            if ($result['scanned'] > 0 || $result['errors'] > 0) {
                $this->logger->info('[TeamHub][DecisionReviewJob] sweep complete', array_merge($result, [
                    'app' => Application::APP_ID,
                ]));
            }
        } catch (\Throwable $e) {
            // The sweep catches per-decision failures itself; a throw here is
            // systemic — the database, or the wiring.
            $this->logger->error('[TeamHub][DecisionReviewJob] sweep failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }
}
