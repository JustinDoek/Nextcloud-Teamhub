<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\AuditIngestionService;
use OCA\TeamHub\Service\AuditService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly job that:
 *   1. Mirrors oc_activity (app=circles + app=files) into teamhub_audit_log.
 *   2. Snapshot-diffs oc_share to surface share.created/changed/deleted.
 *   3. Purges teamhub_audit_log rows older than the configured retention.
 *
 * Registered via appinfo/info.xml <background-jobs>. Runs via NC's standard
 * job runner (cron / webcron / ajax).
 *
 * Failures in any single phase are caught and logged — a broken share-snapshot
 * pass cannot prevent the purge from running.
 */
class AuditMirrorJob extends TimedJob {

    /** Retention key in IAppConfig under app='teamhub'. */
    public const CFG_RETENTION_DAYS = 'audit_retention_days';

    /** Default retention horizon, in days. Honoured when CFG_RETENTION_DAYS is unset. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** Floor and ceiling for the configurable retention horizon. */
    public const MIN_RETENTION_DAYS = 7;
    public const MAX_RETENTION_DAYS = 3650;

    public function __construct(
        ITimeFactory                    $time,
        private AuditIngestionService   $ingestion,
        private AuditService            $audit,
        private IAppConfig              $appConfig,
        private LoggerInterface         $logger,
    ) {
        parent::__construct($time);
        // Run once per hour.
        $this->setInterval(3600);
        // Allow run-on-resume after a server restart rather than waiting a full interval.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        // 1. Probe the activity app — sets the AuditTab banner flag for missing/disabled.
        $activityAvailable = $this->ingestion->checkActivityAvailable();

        $teamIds = [];
        try {
            $teamIds = $this->ingestion->listTeamIds();
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][AuditMirrorJob] Failed to list teams; skipping ingestion', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // 2. Mirror Circles activity (only when activity table is queryable).
        if ($activityAvailable && !empty($teamIds)) {
            try {
                $count = $this->ingestion->mirrorCircles($teamIds);
                $this->logger->info('[TeamHub][AuditMirrorJob] Circles mirrored', [
                    'inserted' => $count, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][AuditMirrorJob] Circles mirror failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // 3. Mirror Files activity scoped to team folders.
            try {
                $folders = $this->ingestion->buildTeamFolderMap($teamIds);
                $count = $this->ingestion->mirrorFiles($folders);
                $this->logger->info('[TeamHub][AuditMirrorJob] Files mirrored', [
                    'inserted' => $count, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][AuditMirrorJob] Files mirror failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        // 4. Snapshot-diff shares — independent of activity table availability.
        try {
            $teamIdMap = array_combine($teamIds, $teamIds);
            $count = $this->ingestion->snapshotShares(is_array($teamIdMap) ? $teamIdMap : []);
            $this->logger->info('[TeamHub][AuditMirrorJob] Share snapshot diffed', [
                'inserted' => $count, 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][AuditMirrorJob] Share snapshot failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // 5. Purge — runs every cycle regardless of upstream failures.
        try {
            $retention = $this->resolveRetentionDays();
            $purged = $this->audit->purgeOlderThan($retention);
            if ($purged > 0) {
                $this->logger->info('[TeamHub][AuditMirrorJob] Purged old rows', [
                    'purged' => $purged, 'retention_days' => $retention,
                    'app' => Application::APP_ID,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][AuditMirrorJob] Purge failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

    }

    /**
     * Read retention from app config, clamping to [MIN, MAX] and falling
     * back to DEFAULT when unset or unparseable.
     */
    private function resolveRetentionDays(): int {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::CFG_RETENTION_DAYS, '');
        if ($raw === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }
        $n = (int)$raw;
        if ($n < self::MIN_RETENTION_DAYS) {
            return self::MIN_RETENTION_DAYS;
        }
        if ($n > self::MAX_RETENTION_DAYS) {
            return self::MAX_RETENTION_DAYS;
        }
        return $n;
    }
}
