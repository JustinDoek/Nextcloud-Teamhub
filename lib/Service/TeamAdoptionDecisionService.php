<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\TeamAdoptionProvisionJob;
use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The decision about a team made outside TeamHub — accept, decline,
 * withdraw — and who may take it (v4.10.50, DESIGN §2.149).
 *
 * Split from {@see TeamAdoptionService} for one reason: the workflow
 * definition (`TeamAdoptionDefinition`) calls this from inside the engine's
 * transaction, and the engine is what `TeamAdoptionService` calls to open
 * and answer requests. One class holding both would be a dependency cycle.
 * This one knows nothing about workflows.
 *
 * ## What accepting does
 *
 * At once, in the decider's request: the registry row (origin `adopted`,
 * `created_by` = the circle's owner) and the lock — from this moment the
 * team is a TeamHub team and Nextcloud's Teams page cannot delete it — then
 * the team type and the policy. The template's apps follow in
 * {@see TeamAdoptionProvisionJob}, run as the team's owner: resources are
 * created in the session user's name, and the person who decided (a desk
 * member, an administrator, or nobody for an automatic acceptance) is not on
 * the team.
 *
 * ## Who decides
 *
 * A Nextcloud administrator, always. A member of the team that holds the
 * Nextcloud services, while it holds this one. That second half **moves a
 * permission boundary**: which teams enter TeamHub is delegated to the desk
 * like the quota grant (DESIGN §2.148).
 */
class TeamAdoptionDecisionService {

    /** The template accepted teams get when nobody chose one. */
    public const DEFAULT_TEMPLATE = 'collaboration';

    /**
     * Templates the grid does not offer: `openproject` needs the provisioning
     * wizard (a workspace in another system), `service` is made in the
     * creation wizard where the Nextcloud services are claimed.
     */
    public const EXCLUDED_TEMPLATES = ['openproject', 'service'];

    public const MAX_REASON = 1000;

    public function __construct(
        private TeamAdoptionMapper   $adoptions,
        private TeamRegistryService  $registry,
        private TeamTypeMapper       $teamTypes,
        private TeamTemplateMapper   $templates,
        private PolicyService        $policy,
        private AuditService         $audit,
        private ServiceTeamService   $serviceTeams,
        private IGroupManager        $groupManager,
        private IJobList             $jobList,
        private IDBConnection        $db,
        private ITimeFactory         $timeFactory,
        private IL10N                $l,
        private LoggerInterface      $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Who
    // ------------------------------------------------------------------

    /** The team that holds the adoption service, or null. */
    public function holdingTeam(): ?string {
        return $this->serviceTeams->serviceTeamForDefinition(
            (string)ServiceCatalogue::definitionFor(ServiceCatalogue::TEAM_ADOPTION),
        );
    }

    /** May this person accept or decline teams? */
    public function mayDecide(string $uid): bool {
        if ($uid === '') {
            return false;
        }
        if ($this->groupManager->isAdmin($uid)) {
            return true;
        }
        $holder = $this->holdingTeam();
        return $holder !== null && $this->serviceTeams->isEligibleAgent($uid, $holder);
    }

    // ------------------------------------------------------------------
    // Templates and policies the grid offers
    // ------------------------------------------------------------------

    /**
     * @return list<array{templateKey: string, label: string, defaultProfileKey: ?string}>
     */
    public function offeredTemplates(): array {
        $out = [];
        foreach ($this->templates->findAll() as $row) {
            $key = (string)$row['templateKey'];
            if (in_array($key, self::EXCLUDED_TEMPLATES, true)) {
                continue;
            }
            $out[] = [
                'templateKey'       => $key,
                'label'             => (string)$row['label'],
                'defaultProfileKey' => $this->policy->defaultProfileForTemplate($key),
            ];
        }
        return $out;
    }

    /** The template key to use: the chosen one if offered, else the default. */
    public function resolveTemplate(string $chosen): string {
        $offered = array_column($this->offeredTemplates(), 'templateKey');
        if ($chosen !== '' && in_array($chosen, $offered, true)) {
            return $chosen;
        }
        if (in_array(self::DEFAULT_TEMPLATE, $offered, true)) {
            return self::DEFAULT_TEMPLATE;
        }
        return $offered[0] ?? self::DEFAULT_TEMPLATE;
    }

    // ------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------

    /**
     * Accept a pending team. `$deciderUid` is '' for an automatic
     * acceptance. Returns the row as it is afterwards.
     *
     * Idempotent for a row already accepted (the engine's hook and the grid
     * can both arrive here for one decision); a row declined or withdrawn
     * is a conflict.
     *
     * @return array<string,mixed>
     * @throws WorkflowTransitionException
     */
    public function accept(array $row, string $deciderUid): array {
        $id = (int)$row['id'];
        $current = $this->adoptions->find($id);
        if ($current === null) {
            throw new WorkflowTransitionException($this->l->t('This team is no longer waiting for a decision.'));
        }
        if ($current['status'] === TeamAdoptionMapper::STATUS_ACCEPTED) {
            return $current;
        }
        if ($current['status'] !== TeamAdoptionMapper::STATUS_PENDING) {
            throw new WorkflowTransitionException($this->l->t('This team is no longer waiting for a decision.'));
        }

        $teamId = (string)$current['teamId'];
        if (!$this->circleExists($teamId)) {
            throw new WorkflowTransitionException($this->l->t('This team no longer exists in Nextcloud.'));
        }

        $template = $this->resolveTemplate((string)$current['templateKey']);
        $profile  = (string)$current['profileKey'];
        $now      = $this->timeFactory->getTime();

        if (!$this->adoptions->decideIfPending($id, TeamAdoptionMapper::STATUS_ACCEPTED, [
            'template_key'     => $template,
            'decided_by'       => $deciderUid,
            'decided_at'       => $now,
            'provision_status' => TeamAdoptionMapper::PROVISION_QUEUED,
        ])) {
            // Somebody else decided in the same moment.
            $again = $this->adoptions->find($id);
            if ($again !== null && $again['status'] === TeamAdoptionMapper::STATUS_ACCEPTED) {
                return $again;
            }
            throw new WorkflowTransitionException($this->l->t('This team is no longer waiting for a decision.'));
        }

        // The actor recorded on the team's own facts: the decider, or the
        // owner when TeamHub accepted it by itself.
        $actor = $deciderUid !== '' ? $deciderUid : (string)$current['ownerUid'];

        $this->registry->registerAdopted($teamId, (string)$current['ownerUid']);
        $this->teamTypes->upsert($teamId, $template, $actor);
        try {
            $this->policy->assignAtCreation($teamId, $profile !== '' ? $profile : null, $template, $actor);
        } catch (\Throwable $e) {
            // The team is in; an unassigned policy is what every team had
            // before Track F and an administrator can classify it later.
            $this->logger->warning('[TeamHub][TeamAdoption] policy could not be assigned to an adopted team', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $this->audit->log($teamId, 'team.adopted', $deciderUid !== '' ? $deciderUid : null, 'team', $teamId, [
            'name'      => (string)$current['teamName'],
            'owner'     => (string)$current['ownerUid'],
            'template'  => $template,
            'profile'   => $profile,
            'route'     => (string)$current['route'],
            'automatic' => $deciderUid === '',
        ]);

        $this->jobList->add(TeamAdoptionProvisionJob::class, ['adoptionId' => $id]);

        return $this->adoptions->find($id) ?? $current;
    }

    /**
     * Decline a pending team. Final: the team is never offered again. The
     * team itself is left as it is in Nextcloud.
     *
     * @return array<string,mixed>
     * @throws ValidationException|WorkflowTransitionException
     */
    public function decline(array $row, string $deciderUid, string $reason): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException($this->l->t('A reason is required.'));
        }
        return $this->close($row, TeamAdoptionMapper::STATUS_DECLINED, $deciderUid, $reason, 'team.adoption_declined');
    }

    /**
     * Withdraw a pending team: its circle is gone, it was registered some
     * other way, or its request was withdrawn. Idempotent.
     *
     * @return array<string,mixed>
     */
    public function withdraw(array $row, string $uid, string $reason): array {
        try {
            return $this->close($row, TeamAdoptionMapper::STATUS_WITHDRAWN, $uid, $reason, 'team.adoption_withdrawn');
        } catch (WorkflowTransitionException) {
            return $this->adoptions->find((int)$row['id']) ?? $row;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function close(array $row, string $status, string $uid, string $reason, string $auditEvent): array {
        $id      = (int)$row['id'];
        $current = $this->adoptions->find($id);
        if ($current !== null && $current['status'] === $status) {
            return $current;
        }
        if ($current === null || $current['status'] !== TeamAdoptionMapper::STATUS_PENDING
            || !$this->adoptions->decideIfPending($id, $status, [
                'decided_by' => $uid,
                'decided_at' => $this->timeFactory->getTime(),
                'reason'     => mb_substr($reason, 0, self::MAX_REASON),
            ])
        ) {
            throw new WorkflowTransitionException($this->l->t('This team is no longer waiting for a decision.'));
        }
        $this->audit->log((string)$current['teamId'], $auditEvent, $uid !== '' ? $uid : null, 'team', (string)$current['teamId'], [
            'name'  => (string)$current['teamName'],
            'owner' => (string)$current['ownerUid'],
            'route' => (string)$current['route'],
        ]);
        return $this->adoptions->find($id) ?? $current;
    }

    // ------------------------------------------------------------------

    public function circleExists(string $teamId): bool {
        if ($teamId === '') {
            return false;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row !== false;
    }
}
