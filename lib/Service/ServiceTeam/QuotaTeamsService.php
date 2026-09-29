<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Service\TeamSpaceService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowStatus;

/**
 * The teams a person may ask more storage for (v4.10.29).
 *
 * The quota request is a Nextcloud service with a card on the Services
 * page, and that card has to ask *which team* — something Manage team never
 * had to, because it is already on one. The answer is the teams where the
 * person is an admin or the owner (Circles level ≥ 8, the gate
 * `QuotaRequestDefinition::canStart()` applies) **and** which have a team
 * space to enlarge. A team that already has a request open is listed with
 * it, so the form can say so instead of letting the engine refuse the
 * second one.
 *
 * The same list decides whether the card is shown at all: a person for whom
 * it is empty has nothing to request, and the Services page hides an action
 * a person cannot take (CLAUDE.md § Permissions).
 *
 * Read only. The level is the direct Circles level, like `canStart()`: an
 * admin through a group is not an admin of the team.
 */
class QuotaTeamsService {

    public function __construct(
        private WorkflowActorResolver  $resolver,
        private TeamSpaceService       $teamSpaces,
        private WorkflowInstanceMapper $instances,
    ) {
    }

    /**
     * @return array<int, array{teamId: string, teamName: string, quota: int, openRequest: array{workflowId: int, requestedBytes: int}|null}>
     */
    public function forUser(string $uid): array {
        if ($uid === '' || !$this->teamSpaces->isAvailable()) {
            return [];
        }
        $out = [];
        foreach ($this->resolver->teamsOf($uid) as $teamId => $level) {
            if ($level < 8) {
                continue;
            }
            $space = $this->teamSpaces->getTeamSpace((string)$teamId);
            if ($space === null) {
                continue;
            }
            $open = $this->instances->findOpenForDefinitionAndTeam(QuotaRequestDefinition::KEY, (string)$teamId, WorkflowStatus::OPEN);
            $out[] = [
                'teamId'      => (string)$teamId,
                'teamName'    => $this->resolver->teamName((string)$teamId),
                'quota'       => (int)($space['quota'] ?? 0),
                'openRequest' => $open !== [] ? [
                    'workflowId'     => (int)$open[0]->getId(),
                    'requestedBytes' => (int)($open[0]->getData()['requestedBytes'] ?? 0),
                ] : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['teamName'], $b['teamName']));
        return $out;
    }
}
