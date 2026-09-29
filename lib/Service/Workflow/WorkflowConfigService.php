<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IConfig;

/**
 * Instance-wide settings of the built-in workflows (WorkflowHub phase 2,
 * v4.10.14).
 *
 * **v4.10.23 — the group that processes team requests is gone.** It was the
 * actor of `TeamRequestDefinition`'s `process` step, a Nextcloud group with
 * `admin` as its default, back when a team request was answered by whoever
 * an administrator nominated. Requesting a new team is now one of the six
 * services in the Nextcloud Services bundle and the service team that holds
 * the bundle does that step, so the setting had nothing left to decide
 * (DESIGN §2.146). `DropRetiredWorkflowSettings` deletes the stored value.
 *
 * **v4.10.29 — the quota engine switch is gone too.** `workflow_engine_quota`
 * (v4.10.17) kept `QuotaRequestDefinition` dark while the ledger owned the
 * live quota request. The quota request now runs on the engine as a
 * Nextcloud service and the ledger version is imported and removed, so
 * there is no second path to switch back to; `DropRetiredWorkflowSettings`
 * deletes the stored value.
 */
class WorkflowConfigService {

    /**
     * How many days a completed workflow's archive record is kept, as
     * *metadata* (v4.10.21). `0` — the default — means keep indefinitely.
     *
     * **Nothing deletes on this value in this phase.** It is written onto
     * every archive record as `retention_until` so a record can say what its
     * window is, and the pass that would act on it is deferred
     * (`docs/workflow-archiving.md` § Deferred). The default is 0 on
     * purpose: an archiving feature whose first version silently started
     * deleting records after n days would be the wrong way round.
     */
    public const KEY_ARCHIVE_RETENTION_DAYS = 'workflow_archive_retention_days';

    /** The policy name written on a record that is kept indefinitely. */
    public const RETENTION_KEEP = 'keep';

    /** Ten years. An upper bound on the setting, not a recommendation. */
    public const MAX_RETENTION_DAYS = 3650;

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * The archive retention window in days; 0 = keep indefinitely
     * (v4.10.21).
     *
     * Clamped on read as well as on write: a value that was put in the
     * app config by hand must not produce a `retention_until` in the past
     * on every record it touches.
     */
    public function getArchiveRetentionDays(): int {
        $raw = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_ARCHIVE_RETENTION_DAYS, '0');
        if ($raw <= 0) {
            return 0;
        }
        return min($raw, self::MAX_RETENTION_DAYS);
    }

    /**
     * Store the window. 0 restores "keep indefinitely".
     *
     * @throws ValidationException outside 0 … MAX_RETENTION_DAYS
     */
    public function setArchiveRetentionDays(int $days): void {
        if ($days < 0 || $days > self::MAX_RETENTION_DAYS) {
            throw new ValidationException('The retention window must be between 0 and ' . self::MAX_RETENTION_DAYS . ' days.');
        }
        if ($days === 0) {
            $this->config->deleteAppValue(Application::APP_ID, self::KEY_ARCHIVE_RETENTION_DAYS);
            return;
        }
        $this->config->setAppValue(Application::APP_ID, self::KEY_ARCHIVE_RETENTION_DAYS, (string)$days);
    }

}
