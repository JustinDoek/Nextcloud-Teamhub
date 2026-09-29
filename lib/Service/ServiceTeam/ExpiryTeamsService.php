<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Workflow\Definition\TeamExpiryRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowStatus;

/**
 * The teams a person may ask more time for (v4.10.45) — the counterpart of
 * `QuotaTeamsService` for *Request more time for a team*.
 *
 * The teams where the person is an admin or the owner (Circles level ≥ 8,
 * the gate `TeamExpiryRequestDefinition::canStart()` applies) **and** which
 * have an expiration date. A team that already has a request open — on the
 * workflow, or still on the old ledger — is listed with it, so the form can
 * say so instead of letting the engine refuse the second one. An empty list
 * hides the card on the service catalog.
 *
 * Read only. The level is the direct Circles level, like `canStart()`.
 */
class ExpiryTeamsService {

    public function __construct(
        private WorkflowActorResolver  $resolver,
        private TeamExpiryService      $expiry,
        private WorkflowInstanceMapper $instances,
        private ServiceTeamService     $serviceTeams,
    ) {
    }

    /** Whether a service team answers requests for more time now. */
    public function isOffered(): bool {
        return $this->serviceTeams->serviceTeamForDefinition(TeamExpiryRequestDefinition::KEY) !== null;
    }

    /**
     * @return array<int, array{teamId: string, teamName: string, expiresOn: string, openRequest: array{workflowId: int, proposedOn: string}|null}>
     */
    public function forUser(string $uid): array {
        if ($uid === '') {
            return [];
        }
        $admin = [];
        foreach ($this->resolver->teamsOf($uid) as $teamId => $level) {
            if ($level >= 8) {
                $admin[] = (string)$teamId;
            }
        }
        if ($admin === []) {
            return [];
        }
        $dates = $this->expiry->getExpiryForTeams($admin);
        $eligible = $this->expiry->eligibilityForTeams(array_keys($dates));
        $out = [];
        foreach ($dates as $teamId => $expiry) {
            $teamId = (string)$teamId;
            if (!($eligible[$teamId] ?? false)) {
                continue;
            }
            $out[] = [
                'teamId'      => $teamId,
                'teamName'    => $this->resolver->teamName($teamId),
                'expiresOn'   => (string)($expiry['expiresOn'] ?? ''),
                'openRequest' => $this->openRequest($teamId),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['teamName'], $b['teamName']));
        return $out;
    }

    /**
     * The team's open request for more time, if any: the workflow one, or
     * a ledger one still waiting for an administrator (`workflowId` 0).
     *
     * @return array{workflowId: int, proposedOn: string}|null
     */
    public function openRequest(string $teamId): ?array {
        $open = $this->instances->findOpenForDefinitionAndTeam(TeamExpiryRequestDefinition::KEY, $teamId, WorkflowStatus::OPEN);
        if ($open !== []) {
            return [
                'workflowId' => (int)$open[0]->getId(),
                'proposedOn' => (string)($open[0]->getData()['proposedOn'] ?? ''),
            ];
        }
        if ($this->expiry->hasPendingRequest($teamId)) {
            return ['workflowId' => 0, 'proposedOn' => ''];
        }
        return null;
    }
}
