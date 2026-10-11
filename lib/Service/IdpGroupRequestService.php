<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IdpGroupMapper;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\Definition\IdpGroupAdoptionDefinition;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * The request behind a waiting directory group — step 4 of §2.172
 * (v4.11.39).
 *
 * ## Why this is not in `IdpTeamProvisionService`
 *
 * That service is deliberately **licence-free**: Justin, 2026-10-06,
 * *"directory provisioning is unlicensed"*, which is why it re-implements
 * the creation sequence instead of reusing `TeamImportService`. Workflows
 * are licensed. Putting the engine in there would have made the unlicensed
 * path depend on a licensed one, so the split is the same one
 * `TeamAdoptionService` (workflows) / `TeamAdoptionDecisionService` (no
 * workflows) already makes, for the same reason.
 *
 * ## The deeplink decides; the row withdraws itself
 *
 * Justin, 2026-10-09: the administrator's task carries *a deeplink to the
 * page where they can approve/deny*, and the task goes when the decision is
 * made. So {@see accept()} and {@see decline()} — the grid's two endpoints —
 * **decide on the row first and cancel the request afterwards**, rather than
 * driving the request and letting its hooks decide. That ordering is what
 * carries the template and policy chosen per row in the grid: a directory
 * group stores no pair (§2.171), so a decision taken through the request
 * could only ever apply the instance's configured defaults.
 *
 * The definition's hooks are still live, and still correct — an
 * administrator who presses Accept on the My Work row itself gets a team
 * with the configured default template and policy, which since v4.11.34 are
 * real values rather than nothing. They are the fallback, not the path.
 *
 * ## Opening is a reconcile, not an event
 *
 * {@see reconcile()} opens a request for every `pending` row that has none
 * and cancels every open request whose row is no longer pending or no longer
 * there. It is idempotent, it is the only writer, and it therefore also
 * picks up the rows that went pending before this version shipped — which an
 * open-on-detection hook never would.
 */
class IdpGroupRequestService {

    public function __construct(
        private IdpGroupMapper          $groups,
        private IdpTeamProvisionService $provisioning,
        private WorkflowEngine          $engine,
        private WorkflowInstanceMapper  $instances,
        private WorkflowLicenceTier     $tier,
        private IGroupManager           $groupManager,
        private LoggerInterface         $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Opening and closing
    // ------------------------------------------------------------------

    /**
     * Bring the requests in line with the rows.
     *
     * @return array{opened: int, closed: int}
     */
    public function reconcile(): array {
        $out = ['opened' => 0, 'closed' => 0];
        if (!$this->tier->isFull()) {
            // No My Work on the basic tier, so no task to open. Anything
            // already open is left alone: the licence lapsing is not a
            // decision about these groups.
            return $out;
        }

        $pending = [];
        foreach ($this->groups->findByStatus(IdpGroupMapper::STATUS_PENDING) as $row) {
            $groupId = (string)($row['group_id'] ?? '');
            if ($groupId === '') {
                continue;
            }
            $pending[$groupId] = $row;
            if ($this->openIdFor($groupId) === null && $this->open($row) !== null) {
                $out['opened']++;
            }
        }

        // The other direction: a request whose row has been decided in the
        // grid, or forgotten because its group went (v4.11.37). Without this
        // a group deleted while its task was open would leave an
        // administrator a task about a group that no longer exists.
        foreach ($this->instances->findOpenForDefinition(
            IdpGroupAdoptionDefinition::KEY,
            WorkflowStatus::OPEN,
        ) as $instance) {
            $groupId = (string)$instance->getTeamId();
            if ($groupId === '' || isset($pending[$groupId])) {
                continue;
            }
            if ($this->cancel((int)$instance->getId(), IdpGroupAdoptionDefinition::systemActor(), 'decided')) {
                $out['closed']++;
            }
        }

        return $out;
    }

    /**
     * Open the request for one `pending` row. Returns the instance id, or
     * null when there is nothing to open — unlicensed, already open, or the
     * engine refused.
     *
     * @param array<string,mixed> $row
     */
    public function open(array $row): ?int {
        if (!$this->tier->isFull()) {
            return null;
        }
        $groupId = (string)($row['group_id'] ?? '');
        if ($groupId === '' || (string)($row['status'] ?? '') !== IdpGroupMapper::STATUS_PENDING) {
            return null;
        }
        $group = $this->groupManager->get($groupId);

        try {
            // The group id is the `team_id` — see the definition's docblock.
            // That is what makes the engine's one-open-per-team rule mean
            // one open request per group, with no new concurrency mode.
            $view = $this->engine->createForSystem(
                IdpGroupAdoptionDefinition::KEY,
                $groupId,
                IdpGroupAdoptionDefinition::systemActor(),
                [
                    'groupId'     => $groupId,
                    'displayName' => $group !== null ? ($group->getDisplayName() ?: $groupId) : $groupId,
                    'idpGroupId'  => (int)($row['id'] ?? 0),
                ],
            );
            return (int)($view['id'] ?? 0) ?: null;
        } catch (\Throwable $e) {
            // A request that cannot be opened must not stop the group being
            // decided: the grid is still there, and this runs in a job.
            $this->logger->warning('[TeamHub][IdpGroupRequest] could not open the request', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Open the request for one group by name, if it is waiting and has none.
     *
     * The detection path's entry point: `IdpGroupConsiderJob` has a group id
     * and no row. {@see reconcile()} would do the same job, but it scans
     * every pending row, and a bulk directory sync queues one of these jobs
     * per group.
     */
    public function openForGroup(string $groupId): ?int {
        if ($groupId === '' || !$this->tier->isFull()) {
            return null;
        }
        if ($this->openIdFor($groupId) !== null) {
            return null;
        }
        $row = $this->groups->findByGroup($groupId);
        return $row !== null ? $this->open($row) : null;
    }

    /** The open request for a group, or null. */
    public function openIdFor(string $groupId): ?int {
        if ($groupId === '') {
            return null;
        }
        try {
            foreach ($this->instances->findOpenForDefinitionAndTeam(
                IdpGroupAdoptionDefinition::KEY,
                $groupId,
                WorkflowStatus::OPEN,
            ) as $instance) {
                return (int)$instance->getId();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IdpGroupRequest] could not read the request', [
                'groupId' => $groupId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return null;
    }

    // ------------------------------------------------------------------
    // The grid's two decisions
    // ------------------------------------------------------------------

    /**
     * Accept one waiting group from the grid: make the team with the
     * template and policy chosen there, then withdraw the administrators'
     * task.
     *
     * @return array{ok: bool, teamId: string, note: string}
     */
    public function accept(int $id, string $actorUid, string $template = '', string $profile = ''): array {
        $groupId = $this->groupIdOf($id);
        $result  = $this->provisioning->acceptPending($id, $actorUid, $template, $profile);
        $this->withdraw($groupId, $actorUid, 'accepted');
        return $result;
    }

    /** Decline one waiting group from the grid, and withdraw the task. */
    public function decline(int $id, string $actorUid, string $reason = ''): void {
        $groupId = $this->groupIdOf($id);
        $this->provisioning->declinePending($id, $actorUid, $reason);
        $this->withdraw($groupId, $actorUid, 'declined');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * End the open request for a group, because the decision was taken
     * outside it. Never fatal: the decision is already recorded, and a task
     * that outlives it is tidied by the next {@see reconcile()}.
     */
    private function withdraw(string $groupId, string $actorUid, string $reason): void {
        $instanceId = $this->openIdFor($groupId);
        if ($instanceId === null) {
            return;
        }
        // v4.11.41 — **always** as the system actor, never as the
        // administrator who decided. The definition refuses withdrawal by a
        // person (`IWorkflowDefinitionActionPolicy`) and the engine exempts
        // only the initiator, which is TeamHub. It also reads truer: the
        // decision is audited against whoever made it, and retiring the task
        // afterwards is TeamHub's bookkeeping, not a second act of theirs.
        $this->cancel($instanceId, IdpGroupAdoptionDefinition::systemActor(), $reason);
    }

    private function cancel(int $instanceId, string $actorUid, string $reason): bool {
        try {
            $this->engine->cancel($instanceId, $actorUid, $reason);
            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][IdpGroupRequest] request already closed', [
                'instance' => $instanceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * The group a row is about, read **before** the decision, because
     * declining or provisioning may rewrite the row.
     */
    private function groupIdOf(int $id): string {
        $row = $this->groups->find($id);
        return $row !== null ? (string)($row['group_id'] ?? '') : '';
    }
}
