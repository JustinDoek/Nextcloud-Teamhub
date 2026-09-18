<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;

/**
 * Step — validate the finished workspace. Reads only: every ledger row this
 * operation wrote is checked against what actually exists (the link row,
 * the app-resource registry) and marked healthy or missing. Nothing here
 * needs more than membership, which matters because a handover may just
 * have taken the creator's owner level away.
 *
 * A required resource that is missing makes the operation `attention`, not
 * `completed`: the team page keeps saying the workspace is not finished
 * until a person has looked.
 */
class FinalizeStep implements StepInterface {

    public const KEY = 'finalize';

    public function __construct(
        private ResourceLinkMapper         $registry,
        private TeamAppResourceMapper      $appResources,
        private TeamOpenProjectLinkService $links,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return null; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $teamId  = $ctx->teamId;
        $checked = [];
        $missing = [];

        foreach ($this->registry->findByTeam($teamId) as $row) {
            $ok = match ($row['appId'] . '/' . $row['resourceType']) {
                'openproject/project' => ($this->links->getLink($teamId)['projectId'] ?? null) === (int)$row['resourceId'],
                'talk/conversation', 'files/folder', 'calendar/calendar' =>
                    $this->appResources->findByTeamAppResource($teamId, $row['appId'], $row['resourceId']) !== null,
                // Linked things outside the registry (the OpenProject-managed
                // folder, a collective) are validated by their own step; here
                // they are simply carried.
                default => true,
            };
            $this->registry->setHealth($row['id'], $ok ? 'ok' : 'missing');
            $checked[] = ['app' => $row['appId'], 'type' => $row['resourceType'], 'id' => $row['resourceId'], 'ok' => $ok, 'mode' => $row['mode']];
            if (!$ok) {
                $missing[] = $row['appId'] . '/' . $row['resourceType'];
            }
        }

        if ($ctx->blueprint->requiresOpenProject() && $this->links->getLink($teamId) === null) {
            $missing[] = 'openproject/link';
        }

        $ctx->remember(self::KEY, 'checked', $checked);
        if ($missing !== []) {
            return StepResult::attention('workspace_incomplete', 'Some resources of this workspace could not be found: ' . implode(', ', $missing), ['checked' => $checked, 'missing' => $missing]);
        }
        return StepResult::completed($teamId, ['checked' => $checked]);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return StepResult::skipped('nothing_to_undo');
    }
}
