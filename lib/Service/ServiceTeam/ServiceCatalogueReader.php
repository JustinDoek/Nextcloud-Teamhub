<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCA\TeamHub\Workflow\Definition\TeamExpiryRequestDefinition;

/**
 * What the Services page offers **one viewer** (v4.11.11).
 *
 * The loop lived in `ServiceTeamController::catalogue()` until the unified
 * search needed the same answer. Two readings of "which services may this
 * person ask for" would have been two places to get a per-viewer rule wrong,
 * and the two per-viewer rules here — the quota card and the more-time card
 * — are exactly the kind that rots quietly when copied.
 *
 * **Not on `ServiceTeamService`**, where it would otherwise belong:
 * `ExpiryTeamsService` depends on that class, so taking a dependency on it
 * from there is a constructor cycle (`npm run check:migrations`' sibling,
 * `npm run check:di`, refuses it). Nothing depends on this class, so it is
 * free to depend on all three.
 *
 * The licence and the administrator's switch are the caller's to check —
 * `requireLicence()` — because the two callers report them differently: the
 * page answers 403, and a search provider that is off returns no section at
 * all rather than an error in the search drawer.
 */
class ServiceCatalogueReader {

    public function __construct(
        private ServiceTeamService  $serviceTeams,
        private QuotaTeamsService   $quotaTeams,
        private ExpiryTeamsService  $expiryTeams,
    ) {
    }

    /**
     * Every service this person may start, each with the team that offers it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(string $uid): array {
        $out         = [];
        $mayAskQuota = null;
        $mayAskTime  = null;

        foreach ($this->serviceTeams->listAll(true) as $team) {
            $teamName = $this->serviceTeams->teamName($team->getTeamId());
            foreach ($this->serviceTeams->describeCatalogue($team->getTeamId()) as $entry) {
                // v4.10.50 — a service TeamHub starts itself has no card.
                $serviceKey = ServiceCatalogue::serviceForDefinition((string)$entry['definitionKey']);
                if ($serviceKey !== null && !ServiceCatalogue::hasCard($serviceKey)) {
                    continue;
                }
                // v4.10.29 — only for somebody who administers a team with a
                // team space. Asked once, and only if a quota card is there.
                if ($entry['definitionKey'] === QuotaRequestDefinition::KEY) {
                    $mayAskQuota ??= $this->quotaTeams->forUser($uid) !== [];
                    if (!$mayAskQuota) {
                        continue;
                    }
                }
                // v4.10.45 — only for somebody who administers a team with an
                // expiration date.
                if ($entry['definitionKey'] === TeamExpiryRequestDefinition::KEY) {
                    $mayAskTime ??= $this->expiryTeams->forUser($uid) !== [];
                    if (!$mayAskTime) {
                        continue;
                    }
                }
                $out[] = $entry + [
                    'serviceTeamId'   => $team->getTeamId(),
                    'serviceTeamName' => $teamName,
                ];
            }
        }
        return $out;
    }
}
