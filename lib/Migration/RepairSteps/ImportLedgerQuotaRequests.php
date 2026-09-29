<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Move the quota requests off the ledger and onto the engine (v4.10.29,
 * `docs/workflowhub-architecture.md` §11 step 3).
 *
 * Until v4.10.29 a quota request lived in app config — `workflow_teamspace_quota`,
 * one JSON document keyed by team (`WorkflowLedgerService`) — and the
 * Nextcloud administrators answered it from My Work. It is now a Nextcloud
 * service on the engine, and the ledger code is gone, so whatever the ledger
 * still holds is imported here, once, and the key deleted.
 *
 * | Ledger status | Engine instance                                              |
 * |---|---|
 * | `pending`     | open; the service team's step available — in its queue, unclaimed |
 * | `approved`    | open; the desk step completed by the administrator who granted it, the requester's *close* available |
 * | `denied`      | rejected at the desk step, with the administrator's reason (archived on a licensed instance) |
 * | `closed`      | **not imported** — finished on both sides; the ledger no longer knew whether it had been granted or declined, and the team's audit log (`teamspace.quota_*`) keeps the record |
 *
 * A pending request on an instance where no team holds the Nextcloud
 * services keeps the actor it had: the Nextcloud administrators
 * (`group:admin`), so nobody's open request lands in a queue nobody works.
 *
 * Nobody is notified — each party was told when it happened. The old
 * notifications (`teamspace_quota` object) are withdrawn instead: they
 * point at My Work rows that no longer exist.
 *
 * Idempotent: a team that already has an open engine quota request is
 * skipped, and the ledger key is deleted only when every entry was
 * imported or deliberately skipped — a failure leaves it for the next
 * upgrade to try again. Registered under `<post-migration>` only: a fresh
 * install has no ledger. Runs after `CompleteNextcloudServicesBundle`, so a
 * holder of the bundle already offers the quota request when a pending one
 * looks for its queue.
 */
class ImportLedgerQuotaRequests implements IRepairStep {

    private const LEDGER_KEY = 'workflow_teamspace_quota';

    public function __construct(
        private IConfig                $config,
        private WorkflowEngine         $engine,
        private WorkflowInstanceMapper $instances,
        private INotificationManager   $notificationManager,
        private LoggerInterface        $logger,
    ) {}

    public function getName(): string {
        return 'Move TeamHub quota requests onto the workflow engine';
    }

    public function run(IOutput $output): void {
        $raw = $this->config->getAppValue('teamhub', self::LEDGER_KEY, '');
        if ($raw === '') {
            return;
        }
        $ledger = json_decode($raw, true);
        if (!is_array($ledger)) {
            $this->logger->warning('[TeamHub][ImportLedgerQuotaRequests] the ledger is not readable JSON; left in place', ['app' => 'teamhub']);
            return;
        }

        $imported = 0;
        $skipped  = 0;
        $failed   = 0;
        foreach ($ledger as $teamId => $entry) {
            $teamId = (string)$teamId;
            if (!is_array($entry) || $teamId === '') {
                $skipped++;
                continue;
            }
            try {
                if ($this->importOne($teamId, $entry)) {
                    $imported++;
                } else {
                    $skipped++;
                }
                $this->withdrawNotifications($teamId);
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('[TeamHub][ImportLedgerQuotaRequests] a quota request could not be imported', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => 'teamhub',
                ]);
            }
        }

        if ($failed === 0) {
            $this->config->deleteAppValue('teamhub', self::LEDGER_KEY);
        }
        $output->info(sprintf(
            'Quota requests: %d moved onto the workflow engine, %d finished ones left in the audit log, %d failed%s.',
            $imported, $skipped, $failed, $failed > 0 ? ' (the ledger is kept; the next upgrade tries again)' : '',
        ));
    }

    /**
     * @param array<string, mixed> $entry
     * @return bool true when an instance was written
     */
    private function importOne(string $teamId, array $entry): bool {
        $status = (string)($entry['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'denied'], true)) {
            return false; // closed: finished on both sides
        }
        if ($this->instances->findOpenForDefinitionAndTeam(QuotaRequestDefinition::KEY, $teamId, WorkflowStatus::OPEN) !== []) {
            return false; // a re-run, or a request started on the engine since
        }
        $requestedBy = (string)($entry['requestedBy'] ?? '');
        $requestedAt = (int)($entry['requestedAt'] ?? 0);
        if ($requestedBy === '' || $requestedAt <= 0) {
            return false;
        }

        $history = [
            QuotaRequestDefinition::STEP_SUBMIT => [
                'status' => WorkflowStepStatus::COMPLETED,
                'by'     => $requestedBy,
                'at'     => $requestedAt,
            ],
        ];
        if ($status !== 'pending') {
            $history[QuotaRequestDefinition::STEP_HANDLE] = [
                'status' => $status === 'approved' ? WorkflowStepStatus::COMPLETED : WorkflowStepStatus::REJECTED,
                'by'     => (string)($entry['decidedBy'] ?? ''),
                'at'     => (int)($entry['decidedAt'] ?? $requestedAt),
                'reason' => (string)($entry['decisionReason'] ?? ''),
            ];
        }

        $this->engine->importInstance(
            QuotaRequestDefinition::KEY,
            $teamId,
            $requestedBy,
            $requestedAt,
            [
                'requestedBytes' => (int)($entry['requestedBytes'] ?? 0),
                'reason'         => mb_substr(trim((string)($entry['reason'] ?? '')), 0, QuotaRequestDefinition::MAX_REASON),
                'currentBytes'   => (int)($entry['currentBytes'] ?? 0),
            ],
            $history,
            WorkflowActor::group('admin'),
        );
        return true;
    }

    private function withdrawNotifications(string $teamId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('teamhub')->setObject('teamspace_quota', $teamId);
            $this->notificationManager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][ImportLedgerQuotaRequests] old notifications not withdrawn', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }
}
