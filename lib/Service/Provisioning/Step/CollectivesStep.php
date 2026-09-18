<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Service\CollectivesService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Step — the team's Collectives knowledge space, through the same
 * `CollectivesService::enableForTeam()` the Manage team toggle uses. That
 * method is already idempotent: it reuses a collective bound to the team's
 * circle before it creates one, so a retry cannot make a second.
 *
 * Rollback: a collective created here is removed with the team by the
 * delete cascade (`CollectivesService::deleteForTeamCascade()`); one that
 * pre-existed and was reused is a linked resource and is kept.
 */
class CollectivesStep implements StepInterface {

    public const KEY = 'collectives';

    public function __construct(
        private CollectivesService       $collectives,
        private ResourceDiscoveryService $discovery,
        private ResourceLinkMapper       $registry,
        private LoggerInterface          $logger,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return 'collective'; }
    public function rollbackPossible(): bool { return true; }

    public function applies(ProvisioningContext $ctx): bool {
        return $ctx->hasApp('collectives') && $ctx->blueprint->collectiveBehavior() !== 'none';
    }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        if (!$this->collectives->isInstalled()) {
            return StepResult::skipped('app_missing', ['app' => 'collectives']);
        }

        $result = $this->collectives->enableForTeam($ctx->teamId, $ctx->teamName(), $ctx->userId);
        if (empty($result['ok'])) {
            return StepResult::failed('collectives_enable', (string)($result['error'] ?? 'Could not enable Collectives'), true);
        }
        try {
            $this->discovery->reconcileTeam($ctx->teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CollectivesStep] registry reconcile failed (non-fatal)', [
                'teamId' => $ctx->teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $collectiveId = (int)($result['collectiveId'] ?? 0);
        $created      = (bool)($result['created'] ?? false);
        if ($collectiveId > 0) {
            $this->registry->upsert(
                $ctx->teamId, 'collectives', 'collective', (string)$collectiveId,
                $created ? 'created' : 'linked', $ctx->id(), $ctx->userId, null,
                ['name' => $result['collectiveName'] ?? null],
            );
        }
        $ctx->remember(self::KEY, 'collectiveId', $collectiveId > 0 ? $collectiveId : null);
        $ctx->remember(self::KEY, 'created', $created);
        return StepResult::completed($collectiveId > 0 ? (string)$collectiveId : null, $ctx->factsOf(self::KEY));
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        $id = $stepRow['externalId'] ?? null;
        if ($ctx->teamId === null || $id === null) {
            return StepResult::skipped('nothing_to_undo');
        }
        if (empty($stepRow['detail']['created'])) {
            return StepResult::skipped('linked_resource_kept', ['collectiveId' => $id]);
        }
        if ($confirm) {
            return StepResult::completed((string)$id, ['removedWithTeam' => true]);
        }
        return StepResult::attention(
            'confirm_required',
            'The knowledge space was created for the workspace and may already hold pages. Confirm to remove it with the team.',
            ['collectiveId' => $id],
            (string)$id,
        );
    }
}
