<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCA\TeamHub\Service\PolicyPropagationService;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The second half of accepting a team made outside TeamHub (v4.10.50,
 * DESIGN §2.149): the chosen template's apps.
 *
 * Queued by `TeamAdoptionDecisionService::accept()`, one job per accepted
 * team. The team is already a TeamHub team when this runs — registered,
 * locked, typed and classified. What is left needs a session: resources are
 * created in the session user's name (the Talk room's owner, the Deck
 * board's owner, the calendar's principal), and Circles opens its own
 * session from `IUserSession`. So it runs **as the team's owner**
 * (`setUser()`, restored in `finally`), the pattern of
 * {@see ProvisioningJob} and {@see TeamImportJob}.
 *
 * In order:
 *   1. The policy's governed privacy bits, applied over the team's own
 *      (`updateTeamConfig()` with the current config: the owner's choices
 *      on ungoverned bits stay, the policy's win where it governs).
 *   2. Discovery (`ResourceDiscoveryService::reconcileTeam()`): a Deck
 *      board or Talk room the team already has is found before the template
 *      is applied, so it is not made twice. On Nextcloud 35 the team's space
 *      is linked the same way — `createTeamSpace()` is idempotent.
 *   3. The template (`PolicyPropagationService::applyTemplateToTeam()`),
 *      exactly as a template rollout adds apps to an existing team.
 *
 * The outcome goes on the adoption row, which the grid shows: `done`, or
 * `failed` with what needs a hand. Nothing here throws out of `run()`: a
 * failed job is reported, not retried in a loop.
 */
class TeamAdoptionProvisionJob extends QueuedJob {

    public function __construct(
        ITimeFactory                     $time,
        private TeamAdoptionMapper       $adoptions,
        private TeamService              $teamService,
        private ResourceDiscoveryService $discovery,
        private PolicyPropagationService $propagation,
        private IUserManager             $userManager,
        private IUserSession             $userSession,
        private IDBConnection            $db,
        private LoggerInterface          $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        $id  = (int)($argument['adoptionId'] ?? 0);
        $row = $id > 0 ? $this->adoptions->find($id) : null;
        if ($row === null
            || $row['status'] !== TeamAdoptionMapper::STATUS_ACCEPTED
            || $row['provisionStatus'] !== TeamAdoptionMapper::PROVISION_QUEUED
        ) {
            return;
        }

        $teamId = (string)$row['teamId'];
        $owner  = $this->userManager->get((string)$row['ownerUid']);
        if ($owner === null) {
            $this->finish($id, TeamAdoptionMapper::PROVISION_FAILED,
                'The team owner\'s account no longer exists, so the template\'s apps were not added. A team admin can add them from Manage team.');
            return;
        }

        $previous = $this->userSession->getUser();
        $notes    = [];
        try {
            $this->userSession->setUser($owner);

            $config = $this->readConfig($teamId);
            if ($config !== null) {
                try {
                    $this->teamService->updateTeamConfig($teamId, $config);
                } catch (\Throwable $e) {
                    $notes[] = 'privacy settings: ' . $e->getMessage();
                }
            }

            try {
                $this->discovery->reconcileTeam($teamId);
            } catch (\Throwable $e) {
                // Discovery is a courtesy here: without it the template may
                // add a second board next to one the team already had.
                $this->logger->warning('[TeamHub][TeamAdoptionProvisionJob] discovery failed before the template', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            $result = $this->propagation->applyTemplateToTeam($teamId, (string)$row['templateKey'], $owner->getUID());
            foreach ($result['manual'] as $app) {
                $notes[] = $app . ': add by hand';
            }
            foreach ($result['errors'] as $app) {
                $notes[] = $app . ': failed';
            }

            $this->finish(
                $id,
                $notes === [] ? TeamAdoptionMapper::PROVISION_DONE : TeamAdoptionMapper::PROVISION_FAILED,
                implode('; ', $notes),
            );
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamAdoptionProvisionJob] template could not be applied to an adopted team', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $notes[] = $e->getMessage();
            $this->finish($id, TeamAdoptionMapper::PROVISION_FAILED, implode('; ', $notes));
        } finally {
            $this->userSession->setUser($previous);
        }
    }

    private function finish(int $id, string $status, string $note): void {
        $this->adoptions->update($id, [
            'provision_status' => $status,
            'provision_note'   => mb_substr($note, 0, 1000),
        ]);
    }

    private function readConfig(string $teamId): ?int {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : (int)$row['config'];
    }
}
