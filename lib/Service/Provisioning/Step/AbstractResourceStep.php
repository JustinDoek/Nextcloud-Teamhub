<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\TeamAppResource;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceService;
use Psr\Log\LoggerInterface;

/**
 * The shape shared by the steps that make one Nextcloud resource for the
 * team through {@see ResourceService::createTeamResources()} — the same
 * method the wizard, the CSV importer and Manage team use, so a
 * provisioned workspace gets exactly what a hand-made team gets.
 *
 * ## Idempotency
 *
 * `createTeamResources()` writes the `teamhub_team_app_resources` row in
 * the same request that makes the room, folder, calendar or board. So "do
 * we already have one" is one registry read, and a retry that finds an
 * active row records it and moves on. The window in which a resource exists
 * without its row is the request dying between the two calls inside
 * `createTeamResources()` — a few milliseconds; the discovery job
 * (`ResourceDiscoveryJob`) then finds the orphan by its ACL and offers it,
 * which is the existing answer for that case.
 *
 * ## Rollback
 *
 * A resource this operation *created* is removed with the team by the
 * normal delete cascade — {@see TeamStep::rollback()} — which already knows
 * how to destroy or detach each kind. The step itself therefore only
 * answers *whether* that is safe: created and still empty → yes; created
 * and used since → needs the caller's confirmation; linked → never.
 */
abstract class AbstractResourceStep implements StepInterface {

    public function __construct(
        protected ResourceService       $resources,
        protected TeamAppResourceMapper $appResources,
        protected ResourceLinkMapper    $registry,
        protected LoggerInterface       $logger,
    ) {
    }

    /** The registry app id (`talk`, `files`, `calendar`). */
    abstract protected function appId(): string;

    /** The ledger's resource type (`conversation`, `folder`, `calendar`). */
    abstract protected function ledgerType(): string;

    public function rollbackPossible(): bool { return true; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $appId = $this->appId();

        // Already there — from an earlier attempt, or from a step that made
        // it alongside something else.
        $existing = $this->appResources->findActiveByTeamAndApp($ctx->teamId, $appId);
        if ($existing !== []) {
            return $this->record($ctx, $existing[0], ['adopted' => true]);
        }

        $results = $this->resources->createTeamResources($ctx->teamId, [$appId], $ctx->teamName());
        $result  = $results[$appId] ?? null;
        if (!is_array($result) || isset($result['error'])) {
            $message = is_array($result) && isset($result['error']) ? (string)$result['error'] : 'Not created';
            // The policy filter drops apps the team's profile forbids — that
            // is a skip with a reason, not a failure.
            if ($result === null) {
                return StepResult::skipped('refused_by_policy', ['app' => $appId]);
            }
            return StepResult::failed($appId . '_create', $message, true);
        }

        $rows = $this->appResources->findActiveByTeamAndApp($ctx->teamId, $appId);
        if ($rows === []) {
            return StepResult::failed($appId . '_create', 'The resource was created but not registered.', true);
        }
        return $this->record($ctx, $rows[0]);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        $externalId = $stepRow['externalId'] ?? null;
        if ($ctx->teamId === null || $externalId === null) {
            return StepResult::skipped('nothing_to_undo');
        }
        if (!empty($stepRow['detail']['adopted']) && ($stepRow['detail']['origin'] ?? '') !== 'teamhub_create') {
            return StepResult::skipped('linked_resource_kept', ['resourceId' => $externalId]);
        }
        // Removal itself is the team cascade's; here only the verdict.
        if ($confirm) {
            return StepResult::completed((string)$externalId, ['removedWithTeam' => true]);
        }
        return StepResult::attention(
            'confirm_required',
            'This resource was created for the workspace and may already hold content. Confirm to remove it with the team.',
            ['resourceId' => $externalId],
            (string)$externalId,
        );
    }

    /** @param array<string,mixed> $extra */
    protected function record(ProvisioningContext $ctx, TeamAppResource $row, array $extra = []): StepResult {
        $mode = $row->getOrigin() === 'teamhub_create' ? 'created' : 'linked';
        $this->registry->upsert(
            (string)$ctx->teamId, $this->appId(), $this->ledgerType(), $row->getResourceId(),
            $mode, $ctx->id(), $ctx->userId, null, ['origin' => $row->getOrigin()],
        );
        $detail = ['resourceId' => $row->getResourceId(), 'origin' => $row->getOrigin(), 'mode' => $mode] + $extra;
        $ctx->remember($this->key(), 'resourceId', $row->getResourceId());
        $this->logger->debug('[TeamHub][Provisioning] ' . $this->key() . ' recorded', ['teamId' => $ctx->teamId, 'app' => Application::APP_ID]);
        return StepResult::completed($row->getResourceId(), $detail);
    }
}
