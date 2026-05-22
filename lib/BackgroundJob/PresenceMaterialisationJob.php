<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\Service\PresenceMaterialisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly job: ensure every user with a week template has concrete slots
 * materialised through the rolling window (today → end of next year).
 *
 * Idempotent — re-running for already-materialised dates is a no-op.
 * Safe to run daily; actual work is proportional only to new dates added.
 *
 * Registration: appinfo/info.xml <background-jobs> block.
 */
class PresenceMaterialisationJob extends TimedJob {

    public function __construct(
        ITimeFactory                       $time,
        private PresenceMaterialisationService $materialisation,
        private LoggerInterface            $logger,
    ) {
        parent::__construct($time);
        // Once per 24 hours.
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $this->materialisation->materialiseAll();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[TeamHub][PresenceMaterialisationJob] Failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
