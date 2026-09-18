<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\ProvisioningMapper;
use OCA\TeamHub\Db\ResourceLinkMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\Provisioning\Step\StepInterface;
use OCA\TeamHub\Service\Provisioning\Step\TeamStep;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The provisioning operation (v4.9.6, OpenProject Phase 2): start it, run
 * its steps, report it, retry it, roll it back.
 *
 * ## How it runs
 *
 * Provisioning is a **resumable multi-step operation**, driven the way bulk
 * import is (v4.6.6): the creator's browser pumps it (`run()` executes
 * steps for up to a time budget and returns the state; the wizard calls
 * again until it is done), and {@see \OCA\TeamHub\BackgroundJob\ProvisioningJob}
 * adopts an operation whose pump went quiet. Both go through the same
 * lease (`ProvisioningMapper::claim()`), so a step never executes twice at
 * once, and both write a heartbeat, so a stuck operation is visible.
 *
 * Every step runs **as the creator** — the browser pump is their session,
 * the job impersonates them under the same re-checks the import job makes.
 * An administrator retrying somebody else's operation impersonates them
 * too, with an audit row; nothing is ever made in the administrator's name.
 *
 * ## State
 *
 * `teamhub_provisioning` holds the operation (status, current step, team
 * once it exists, lease, heartbeat, sanitised request); `teamhub_provisioning_step`
 * one row per step with status, resource, attempts, times, the last error
 * and whether a retry is safe. Statuses:
 *
 *   operation: pending → running → completed | attention | failed | rolled_back
 *   step:      pending → running → completed | skipped | attention | failed | rolled_back
 *
 * `attention` on a step does not stop the run (the workspace is usable);
 * `failed` does. An operation whose steps all ended but one of them needs
 * attention is `attention`, and the team page says so until a person has
 * retried or acknowledged it.
 *
 * ## Retry and rollback
 *
 * `retry()` resets one failed or attention step (only if the step said a
 * retry is safe) and runs on. Every step is idempotent — see
 * {@see StepInterface}. `rollback()` asks each completed step, in reverse,
 * what removing its resource would mean; if any resource may hold activity
 * it stops and reports until the caller confirms; then the team is deleted
 * through the normal cascade. An OpenProject project is never deleted;
 * linked resources are never deleted; a handed-over team is not rolled
 * back from here (Maintenance is the place).
 */
class ProvisioningService {

    /** A lease older than this may be taken over. */
    public const LEASE_SECONDS = 300;
    /** A `running` operation whose heartbeat is older than this is stalled. */
    public const STALE_AFTER_SECONDS = 300;
    /** Attempts of one step before it stops being retried automatically. */
    public const MAX_AUTO_ATTEMPTS = 5;
    /** Seconds one `run()` call may spend before handing back to the client. */
    public const DEFAULT_BUDGET = 20;
    public const MAX_MEMBERS = 500;

    private const OP_PENDING     = 'pending';
    private const OP_RUNNING     = 'running';
    private const OP_COMPLETED   = 'completed';
    private const OP_ATTENTION   = 'attention';
    private const OP_FAILED      = 'failed';
    private const OP_ROLLED_BACK = 'rolled_back';

    public function __construct(
        private ProvisioningMapper             $mapper,
        private ResourceLinkMapper             $registry,
        private StepRegistry                   $steps,
        private BlueprintService               $blueprints,
        private MembershipPlanService          $membership,
        private OpenProjectProvisioningService $op,
        private TeamOpenProjectLinkService     $links,
        private MemberService                  $members,
        private AuditService                   $audit,
        private IUserSession                   $userSession,
        private IUserManager                   $userManager,
        private IGroupManager                  $groupManager,
        private IConfig                        $config,
        private IDBConnection                  $db,
        private LoggerInterface                $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Start
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate and record a new operation. Nothing is made yet; the first
     * `run()` does that. The same idempotency key from the same creator
     * returns the operation already started with it — the replay guard.
     *
     * @param array<string,mixed> $raw the wizard's payload
     * @return array<string,mixed> the operation's status
     * @throws ValidationException|AccessDeniedException
     */
    public function start(array $raw): array {
        $uid = $this->currentUserId();
        if (!$this->members->canCurrentUserCreateTeam()) {
            throw new AccessDeniedException('You are not allowed to create teams');
        }

        $key = trim((string)($raw['idempotencyKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new ValidationException('A request key is required');
        }
        $existing = $this->mapper->findByIdempotencyKey($uid, $key);
        if ($existing !== null) {
            return $this->status($existing['id']);
        }

        $request = $this->sanitiseRequest($raw);
        $bp      = $this->blueprints->forTemplate($request['templateKey']);
        if ($bp->requiresOpenProject()) {
            if (!in_array($request['mode'], $bp->openProjectModes(), true)) {
                throw new ValidationException('This template does not allow that mode');
            }
        }
        // The template decides the applications and modules; the request
        // carries no choice (Justin, 2026-09-13).
        $request['components'] = $this->blueprints->selectionForTemplate($bp);

        $id = $this->mapper->createOperation($uid, $key, $request['templateKey'], $request['mode'], $request);
        $ctx = new ProvisioningContext(
            ['id' => $id, 'request' => $request, 'mode' => $request['mode'], 'templateKey' => $request['templateKey']],
            [], $bp, $uid, null,
        );
        $rows = [];
        foreach ($this->steps->forContext($ctx) as $step) {
            $rows[] = ['key' => $step->key(), 'resourceType' => $step->resourceType(), 'rollbackPossible' => $step->rollbackPossible()];
        }
        $this->mapper->createSteps($id, $rows);

        $this->audit->log(AuditService::INSTANCE_SCOPE, 'provisioning.started', $uid, 'provisioning', (string)$id, [
            'templateKey' => $request['templateKey'], 'mode' => $request['mode'], 'name' => $request['name'],
        ]);

        return $this->status($id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Read
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The operation and its steps, for its creator, a Nextcloud
     * administrator, or an admin of the team it produced.
     *
     * @return array<string,mixed>
     * @throws NotFoundException|AccessDeniedException
     */
    public function status(int $id): array {
        $op = $this->requireReadable($id);
        return $this->describe($op);
    }

    /**
     * The newest operation of a team, reduced to what the team page needs:
     * is provisioning finished, and if not, where it stands. Membership is
     * the caller's check.
     *
     * @return ?array<string,mixed>
     */
    public function latestForTeam(string $teamId): ?array {
        return $this->mapper->latestSummaryForTeam($teamId);
    }

    /**
     * Recent operations for the administrator's diagnostics.
     *
     * @return list<array<string,mixed>>
     */
    public function listRecent(int $limit = 25): array {
        $this->requireNcAdmin();
        $out = [];
        foreach ($this->mapper->findRecent($limit) as $op) {
            $out[] = $this->summary($op);
        }
        return $out;
    }

    /**
     * The creator's own recent operations (the wizard's "continue where I
     * left off").
     *
     * @return list<array<string,mixed>>
     */
    public function listMine(int $limit = 5): array {
        $uid = $this->currentUserId();
        $out = [];
        foreach ($this->mapper->findRecent($limit, $uid) as $op) {
            if (!in_array($op['status'], [self::OP_COMPLETED, self::OP_ROLLED_BACK], true)) {
                $out[] = $this->summary($op);
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Run
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Execute pending steps for up to `$budgetSeconds`, then return the
     * state. The client calls again while `status` is `running`; a step
     * waiting on OpenProject asks for a pause (`pollAfter`).
     *
     * @return array<string,mixed>
     * @throws NotFoundException|AccessDeniedException
     */
    public function run(int $id, int $budgetSeconds = self::DEFAULT_BUDGET): array {
        $op = $this->requireRunnable($id);
        if (in_array($op['status'], [self::OP_COMPLETED, self::OP_ROLLED_BACK], true)) {
            return $this->describe($op);
        }
        return $this->asCreator($op, fn () => $this->execute($op, max(1, min(60, $budgetSeconds))));
    }

    /**
     * Reset a failed or attention step and run on. Without a key, the
     * failed step (or every attention step) is reset.
     *
     * @return array<string,mixed>
     */
    public function retry(int $id, ?string $stepKey = null): array {
        $op    = $this->requireRunnable($id);
        $steps = $this->mapper->findSteps($id);
        $reset = 0;
        foreach ($steps as $s) {
            $target = $stepKey === null
                ? in_array($s['status'], ['failed', 'attention'], true)
                : $s['key'] === $stepKey;
            if (!$target) {
                continue;
            }
            if (!in_array($s['status'], ['failed', 'attention'], true)) {
                throw new ValidationException('Only a failed step or one that needs attention can be retried');
            }
            if (!$s['retrySafe']) {
                throw new ValidationException('Retrying this step is not safe; it needs a person');
            }
            $this->mapper->updateStep($id, $s['key'], [
                'status' => 'pending', 'error_code' => null, 'error_message' => null, 'completed_at' => null,
            ]);
            $reset++;
        }
        if ($reset === 0) {
            throw new ValidationException('Nothing to retry');
        }
        $this->mapper->updateOperation($id, ['status' => self::OP_RUNNING, 'error_code' => null, 'error_message' => null, 'completed_at' => null]);
        $this->audit->log(AuditService::INSTANCE_SCOPE, 'provisioning.retried', $this->currentUserId(), 'provisioning', (string)$id, ['step' => $stepKey, 'reset' => $reset]);

        return $this->run($id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rollback
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Undo what the operation made, per resource policy. Without `$confirm`
     * this is a dry run when any created resource may hold activity: it
     * answers with what would be removed and what needs confirming. An
     * OpenProject project is never deleted; a linked resource never; a team
     * that was handed over is refused.
     *
     * @return array<string,mixed>
     */
    public function rollback(int $id, bool $confirm = false): array {
        $op = $this->requireRunnable($id);
        if (in_array($op['status'], [self::OP_COMPLETED, self::OP_ROLLED_BACK], true)) {
            throw new ValidationException('A finished workspace is removed by deleting the team, not by rolling provisioning back');
        }
        return $this->asCreator($op, function () use ($op, $confirm): array {
            $steps = $this->indexSteps($this->mapper->findSteps($op['id']));
            $ctx   = $this->context($op, $steps);

            $handover = $steps['handover'] ?? null;
            if ($handover !== null && $handover['status'] === 'completed') {
                throw new ValidationException('The team has been handed over to its owner; remove it from Admin → TeamHub → Maintenance instead');
            }

            $verdicts = [];
            $needsConfirm = [];
            // Ask every step but the team's what removal means (reverse order).
            foreach (array_reverse($this->steps->forContext($ctx)) as $step) {
                $row = $steps[$step->key()] ?? null;
                if ($row === null || !in_array($row['status'], ['completed', 'attention'], true) || $step->key() === TeamStep::KEY) {
                    continue;
                }
                $verdict = $step->rollback($ctx, $row, $confirm);
                $verdicts[$step->key()] = $this->resultToArray($verdict);
                if ($verdict->status === StepResult::ATTENTION && $verdict->errorCode === 'confirm_required') {
                    $needsConfirm[] = $step->key();
                }
            }
            if ($needsConfirm !== [] && !$confirm) {
                return [
                    'status'       => 'confirm_required',
                    'needsConfirm' => $needsConfirm,
                    'verdicts'     => $verdicts,
                ];
            }

            // Now the team, through the cascade — which removes every
            // resource it created and detaches the linked ones.
            $teamStep = $this->steps->byKey(TeamStep::KEY);
            $teamRow  = $steps[TeamStep::KEY] ?? null;
            if ($teamStep !== null && $teamRow !== null && $ctx->teamId !== null) {
                $verdict = $teamStep->rollback($ctx, $teamRow, true);
                $verdicts[TeamStep::KEY] = $this->resultToArray($verdict);
                if ($verdict->status === StepResult::FAILED) {
                    $this->mapper->updateOperation($op['id'], ['status' => self::OP_ATTENTION, 'error_code' => 'rollback_failed', 'error_message' => $verdict->errorMessage]);
                    return ['status' => 'failed', 'verdicts' => $verdicts];
                }
            }

            foreach ($steps as $key => $row) {
                if (in_array($row['status'], ['completed', 'attention', 'failed', 'running'], true)) {
                    $this->mapper->updateStep($op['id'], $key, ['status' => 'rolled_back', 'detail' => ($row['detail'] ?? []) + ['rollback' => $verdicts[$key] ?? null]]);
                }
            }
            $this->mapper->updateOperation($op['id'], [
                'status' => self::OP_ROLLED_BACK, 'completed_at' => time(),
                'result' => ['rollback' => $verdicts],
            ]);
            $this->audit->log(AuditService::INSTANCE_SCOPE, 'provisioning.rolled_back', $this->currentUserId(), 'provisioning', (string)$op['id'], [
                'teamId' => $ctx->teamId, 'confirmed' => $confirm,
            ]);
            return ['status' => 'rolled_back', 'verdicts' => $verdicts] + $this->describe($this->mapper->findOperation($op['id']) ?? $op);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Membership afterwards
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The drift between the team and its OpenProject project, as the caller
     * sees the project. Team admins only.
     *
     * @return array<string,mixed>
     * @throws OpenProjectException
     */
    public function membershipDrift(string $teamId): array {
        $this->members->requireAdminLevel($teamId);
        $uid  = $this->currentUserId();
        $link = $this->links->requireLink($teamId);
        $bp   = $this->blueprints->forTemplate(TeamOpenProjectLinkService::TEMPLATE);
        $resolved = $this->membership->resolveMapping($bp, $this->op->listRoles($uid));
        return $this->membership->drift($uid, $teamId, $link->getProjectId(), $resolved)
            + ['roleMapping' => $resolved, 'projectId' => $link->getProjectId()];
    }

    /**
     * The one explicit, one-way synchronisation: add to the project the
     * team members who are missing there, with their mapped role. Nothing
     * is removed, no role is changed. Team admins only; every membership is
     * created as them.
     *
     * @return array<string,mixed>
     * @throws OpenProjectException
     */
    public function syncMembership(string $teamId): array {
        $this->members->requireAdminLevel($teamId);
        $uid   = $this->currentUserId();
        $drift = $this->membershipDrift($teamId);
        $added = $refused = [];
        foreach ($drift['missingInOpenProject'] as $entry) {
            $roleId = $entry['openProjectRole']['id'] ?? null;
            if ($roleId === null) {
                $refused[$entry['id']] = 'role_missing';
                continue;
            }
            try {
                $this->op->createMembership($uid, (int)$drift['projectId'], (int)$entry['principal']['id'], [(int)$roleId]);
                $added[] = $entry['id'];
            } catch (OpenProjectException $e) {
                $refused[$entry['id']] = $e->getErrorCode();
            }
        }
        $this->audit->log($teamId, 'openproject.membership_synced', $uid, 'app', 'openproject', [
            'added' => $added, 'refused' => array_keys($refused), 'projectId' => $drift['projectId'],
        ]);
        return ['added' => $added, 'refused' => $refused, 'drift' => $this->membershipDrift($teamId)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Background job hooks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Operations the background job may adopt: running, heartbeat stale.
     *
     * @return list<array<string,mixed>>
     */
    public function findStalled(int $limit = 3): array {
        return $this->mapper->findStalledRunning(time() - self::STALE_AFTER_SECONDS, $limit);
    }

    /**
     * Run one operation as the session user the job has set. No permission
     * check beyond the job's own (it impersonates the creator after
     * re-checking the account).
     *
     * @return array<string,mixed>
     */
    public function runAsJob(int $id, int $budgetSeconds = 30): array {
        $op = $this->mapper->findOperation($id);
        if ($op === null) {
            throw new NotFoundException('No such operation');
        }
        return $this->execute($op, $budgetSeconds);
    }

    /** @return list<int> */
    public function findPrunable(int $before, int $limit = 50): array {
        return $this->mapper->findFinishedBefore($before, $limit);
    }

    public function prune(int $id): void {
        $this->mapper->deleteOperation($id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The runner
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function execute(array $op, int $budgetSeconds): array {
        $id    = $op['id'];
        $token = bin2hex(random_bytes(16));
        if (!$this->mapper->claim($id, $token, time() - self::LEASE_SECONDS)) {
            // Somebody else is running it right now — report the state.
            return $this->describe($this->mapper->findOperation($id) ?? $op) + ['busy' => true];
        }

        $deadline = time() + $budgetSeconds;
        try {
            $op = $this->mapper->findOperation($id) ?? $op;
            if ($op['status'] === self::OP_PENDING) {
                $this->mapper->updateOperation($id, ['status' => self::OP_RUNNING, 'started_at' => time()]);
            }

            while (true) {
                $steps = $this->indexSteps($this->mapper->findSteps($id));
                $op    = $this->mapper->findOperation($id) ?? $op;
                $ctx   = $this->context($op, $steps);
                $next  = $this->nextStep($ctx, $steps);

                if ($next === null) {
                    if (in_array($op['status'], [self::OP_PENDING, self::OP_RUNNING], true)) {
                        $this->finish($id, $steps);
                    }
                    break;
                }
                [$step, $row] = $next;
                if ($row['status'] === 'failed') {
                    // Stopped at a failed step — a retry resets it.
                    break;
                }
                if ($row['attempts'] >= self::MAX_AUTO_ATTEMPTS && $row['status'] !== 'running') {
                    $this->mapper->updateStep($id, $step->key(), ['status' => 'attention', 'error_code' => 'too_many_attempts', 'error_message' => 'This step was attempted too often; a person needs to look.']);
                    $this->mapper->updateOperation($id, ['status' => self::OP_ATTENTION, 'current_step' => $step->key()]);
                    break;
                }

                $this->mapper->updateOperation($id, ['current_step' => $step->key()]);
                $this->mapper->updateStep($id, $step->key(), ['status' => 'running', 'started_at' => $row['startedAt'] ?? time(), 'bumpAttempts' => true]);
                $this->mapper->touchHeartbeat($id);

                $result = $this->runStep($step, $ctx);
                $this->recordResult($id, $step, $ctx, $result);

                if ($result->status === StepResult::FAILED) {
                    $this->mapper->updateOperation($id, ['status' => self::OP_FAILED, 'error_code' => $result->errorCode, 'error_message' => $result->errorMessage]);
                    $this->audit->log(AuditService::INSTANCE_SCOPE, 'provisioning.step_failed', $ctx->userId, 'provisioning', (string)$id, ['step' => $step->key(), 'code' => $result->errorCode]);
                    break;
                }
                if ($result->status === StepResult::RUNNING) {
                    // Waiting on OpenProject: hand back so the client can pause.
                    $described = $this->describe($this->mapper->findOperation($id) ?? $op);
                    $described['pollAfter'] = $result->pollAfter;
                    return $described;
                }
                if (time() >= $deadline) {
                    break;
                }
            }
        } finally {
            $this->mapper->release($id, $token);
        }

        return $this->describe($this->mapper->findOperation($id) ?? $op);
    }

    private function runStep(StepInterface $step, ProvisioningContext $ctx): StepResult {
        try {
            return $step->run($ctx);
        } catch (OpenProjectException $e) {
            return StepResult::failed($e->getErrorCode(), $e->getUpstreamMessage() ?? $e->getMessage(), true);
        } catch (ValidationException | AccessDeniedException $e) {
            return StepResult::failed('validation', $e->getMessage(), false);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ProvisioningService] Step threw', [
                'operation' => $ctx->id(), 'step' => $step->key(), 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return StepResult::failed('unexpected', 'The step failed unexpectedly; the log has the details.', true);
        }
    }

    private function recordResult(int $id, StepInterface $step, ProvisioningContext $ctx, StepResult $result): void {
        $fields = [
            'status'        => $result->status,
            'error_code'    => $result->errorCode,
            'error_message' => $result->errorMessage,
            'retry_safe'    => $result->retrySafe,
            'detail'        => $result->detail + $ctx->factsOf($step->key()),
        ];
        if ($result->externalId !== null) {
            $fields['external_id'] = $result->externalId;
        }
        if ($result->status === StepResult::RUNNING) {
            $fields['external_ref'] = $result->externalRef;
        } else {
            $fields['external_ref'] = null;
        }
        if ($result->isTerminalSuccess() || $result->status === StepResult::ATTENTION) {
            $fields['completed_at'] = time();
        }
        if ($result->rollbackPossible !== null) {
            $fields['rollback_possible'] = $result->rollbackPossible;
        }
        $this->mapper->updateStep($id, $step->key(), $fields);
    }

    /**
     * @param array<string, array<string,mixed>> $steps
     * @return ?array{0: StepInterface, 1: array<string,mixed>}
     */
    private function nextStep(ProvisioningContext $ctx, array $steps): ?array {
        foreach ($this->steps->forContext($ctx) as $step) {
            $row = $steps[$step->key()] ?? null;
            if ($row === null) {
                // A step the operation did not have when it was created (a
                // blueprint edit since): added as pending so it runs.
                $this->mapper->createSteps($ctx->id(), [['key' => $step->key(), 'resourceType' => $step->resourceType(), 'rollbackPossible' => $step->rollbackPossible()]]);
                $row = $this->mapper->findStep($ctx->id(), $step->key());
                if ($row === null) {
                    continue;
                }
                $ctx->steps[$step->key()] = $row;
            }
            if (in_array($row['status'], ['completed', 'skipped', 'attention', 'rolled_back'], true)) {
                continue;
            }
            return [$step, $row];
        }
        return null;
    }

    /** @param array<string, array<string,mixed>> $steps */
    private function finish(int $id, array $steps): void {
        $attention = false;
        foreach ($steps as $row) {
            if ($row['status'] === 'attention') {
                $attention = true;
            }
        }
        $op = $this->mapper->findOperation($id);
        $this->mapper->updateOperation($id, [
            'status'       => $attention ? self::OP_ATTENTION : self::OP_COMPLETED,
            'current_step' => null,
            'completed_at' => time(),
            'result'       => $this->buildResult($op ?? [], $steps),
        ]);
        $this->audit->log(AuditService::INSTANCE_SCOPE, $attention ? 'provisioning.needs_attention' : 'provisioning.completed', $op['createdBy'] ?? null, 'provisioning', (string)$id, [
            'teamId' => $op['teamId'] ?? null,
        ]);
    }

    /**
     * The workspace summary stored on completion: the team, the project,
     * every resource from the ledger, the membership report.
     *
     * @param array<string,mixed> $op
     * @param array<string, array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    private function buildResult(array $op, array $steps): array {
        $teamId = $op['teamId'] ?? null;
        return [
            'teamId'     => $teamId,
            'name'       => $op['request']['name'] ?? null,
            'project'    => $steps['openproject_project']['detail'] ?? null,
            'resources'  => $teamId !== null ? array_map(static fn (array $r): array => [
                'app' => $r['appId'], 'type' => $r['resourceType'], 'id' => $r['resourceId'], 'mode' => $r['mode'], 'url' => $r['resourceUrl'], 'health' => $r['health'],
            ], $this->registry->findByTeam($teamId)) : [],
            'membership' => $steps['membership']['detail'] ?? null,
            'handover'   => $steps['handover']['detail'] ?? null,
            'dashboard'  => $steps['dashboard']['detail'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shapes
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $op */
    private function describe(array $op): array {
        $steps = $this->mapper->findSteps($op['id']);
        $out   = $this->summary($op);
        $out['request'] = $this->publicRequest($op['request']);
        $out['steps']   = array_map(fn (array $s): array => [
            'key'              => $s['key'],
            'status'           => $s['status'],
            'resourceType'     => $s['resourceType'],
            'externalId'       => $s['externalId'],
            'attempts'         => $s['attempts'],
            'startedAt'        => $s['startedAt'],
            'completedAt'      => $s['completedAt'],
            'errorCode'        => $s['errorCode'],
            'errorMessage'     => $s['errorMessage'],
            'retrySafe'        => $s['retrySafe'],
            'rollbackPossible' => $s['rollbackPossible'],
            'detail'           => $s['detail'],
        ], $steps);
        $out['result'] = $op['result'];
        return $out;
    }

    /** @param array<string,mixed> $op */
    private function summary(array $op): array {
        return [
            'id'          => $op['id'],
            'teamId'      => $op['teamId'],
            'templateKey' => $op['templateKey'],
            'mode'        => $op['mode'],
            'status'      => $op['status'],
            'currentStep' => $op['currentStep'],
            'name'        => $op['request']['name'] ?? null,
            'createdBy'   => $op['createdBy'],
            'createdAt'   => $op['createdAt'],
            'updatedAt'   => $op['updatedAt'],
            'startedAt'   => $op['startedAt'],
            'completedAt' => $op['completedAt'],
            'heartbeatAt' => $op['heartbeatAt'],
            'stalled'     => $op['status'] === self::OP_RUNNING && ($op['heartbeatAt'] ?? 0) < time() - self::STALE_AFTER_SECONDS,
            'errorCode'   => $op['errorCode'],
            'errorMessage' => $op['errorMessage'],
        ];
    }

    /**
     * The request as the status UI may see it — already sanitised on the
     * way in, minus nothing; kept as a method so a later field that must
     * not travel has one place to be dropped.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function publicRequest(array $request): array {
        return $request;
    }

    private function resultToArray(StepResult $r): array {
        return ['status' => $r->status, 'code' => $r->errorCode, 'message' => $r->errorMessage, 'detail' => $r->detail, 'externalId' => $r->externalId];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Context
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $op
     * @param array<string, array<string,mixed>> $steps
     */
    private function context(array $op, array $steps): ProvisioningContext {
        $bp  = $this->blueprints->forTemplate($op['templateKey']);
        $ctx = new ProvisioningContext($op, $steps, $bp, $op['createdBy'], $op['teamId']);
        $id  = $op['id'];
        $ctx->onTeamCreated = function (string $teamId) use ($id): void {
            $this->mapper->updateOperation($id, ['team_id' => $teamId]);
        };
        return $ctx;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string, array<string,mixed>>
     */
    private function indexSteps(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Request sanitising
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     * @throws ValidationException
     */
    private function sanitiseRequest(array $raw): array {
        $templateKey = trim((string)($raw['templateKey'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $templateKey)) {
            throw new ValidationException('Invalid template');
        }
        $this->blueprints->templateRow($templateKey); // throws NotFound

        $mode = trim((string)($raw['mode'] ?? 'link'));
        if (!in_array($mode, Blueprint::OPENPROJECT_MODES, true)) {
            throw new ValidationException('Invalid mode');
        }
        $name = trim((string)($raw['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ValidationException('A team name is required');
        }
        $description = mb_substr(trim((string)($raw['description'] ?? '')), 0, 4000);

        $visibility = (string)($raw['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new ValidationException('Invalid visibility');
        }
        $startDate = $this->dateOrEmpty((string)($raw['startDate'] ?? ''), 'start date');
        $endDate   = $this->dateOrEmpty((string)($raw['endDate'] ?? ''), 'end date');
        if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
            throw new ValidationException('The end date must be after the start date');
        }
        $category = mb_substr(trim(strip_tags((string)($raw['category'] ?? ''))), 0, 100);

        $ownerUid = trim((string)($raw['ownerUid'] ?? ''));
        if ($ownerUid !== '' && $this->userManager->get($ownerUid) === null) {
            throw new ValidationException('The appointed owner does not exist');
        }
        $profileKey = mb_substr(trim((string)($raw['profileKey'] ?? '')), 0, 64);
        $preselect  = max(0, (int)($raw['preselectConfig'] ?? 0));

        $op   = is_array($raw['openProject'] ?? null) ? $raw['openProject'] : [];
        $opOut = [
            'projectId'  => max(0, (int)($op['projectId'] ?? 0)),
            'templateId' => max(0, (int)($op['templateId'] ?? 0)),
            'parentId'   => max(0, (int)($op['parentId'] ?? 0)),
            'identifier' => mb_strtolower(trim((string)($op['identifier'] ?? ''))),
        ];
        if ($mode === 'create') {
            if ($opOut['identifier'] === '') {
                $opOut['identifier'] = OpenProjectProvisioningService::suggestIdentifier($name);
            }
            if (!OpenProjectProvisioningService::isValidIdentifier($opOut['identifier'])) {
                throw new ValidationException('The project identifier may only contain lowercase letters, digits, dashes and underscores, and must start with a letter or digit');
            }
        } elseif ($opOut['projectId'] <= 0 && $this->blueprints->forTemplate($templateKey)->requiresOpenProject()) {
            throw new ValidationException('Choose the OpenProject project to connect');
        }

        $members = [];
        foreach ((array)($raw['members'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $id   = trim((string)($m['id'] ?? ''));
            $type = (string)($m['type'] ?? 'user');
            if ($id === '' || mb_strlen($id) > 255 || !in_array($type, ['user', 'group', 'federated', 'email'], true)) {
                continue;
            }
            $level = (int)($m['level'] ?? 1);
            if (!in_array($level, [1, 4, 8, 9], true)) {
                $level = 1;
            }
            $decision = trim((string)($m['decision'] ?? ''));
            if ($decision !== '' && !in_array($decision, MembershipPlanService::DECISIONS, true)) {
                throw new ValidationException('Unknown decision for ' . $id);
            }
            $members[] = [
                'id'          => $id,
                'type'        => $type,
                'level'       => $type === 'group' && $level === 9 ? 8 : $level,
                'displayName' => mb_substr(trim(strip_tags((string)($m['displayName'] ?? $id))), 0, 255),
                'decision'    => $decision !== '' ? $decision : null,
            ];
            if (count($members) > self::MAX_MEMBERS) {
                throw new ValidationException('Too many members');
            }
        }

        return [
            'templateKey'     => $templateKey,
            'mode'            => $mode,
            'name'            => $name,
            'description'     => $description,
            'visibility'      => $visibility,
            'startDate'       => $startDate,
            'endDate'         => $endDate,
            'category'        => $category,
            'ownerUid'        => $ownerUid,
            'profileKey'      => $profileKey,
            'preselectConfig' => $preselect,
            'openProject'     => $opOut,
            // Filled by start() from the template; never from the request.
            'components'      => ['apps' => [], 'modules' => []],
            'members'         => $members,
        ];
    }

    private function dateOrEmpty(string $value, string $label): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))) {
            throw new ValidationException('Invalid ' . $label);
        }
        return $value;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Permissions and impersonation
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireReadable(int $id): array {
        $op = $this->mapper->findOperation($id);
        if ($op === null) {
            throw new NotFoundException('No such provisioning operation');
        }
        $uid = $this->currentUserId();
        if ($op['createdBy'] === $uid || $this->isNcAdmin($uid)) {
            return $op;
        }
        if ($op['teamId'] !== null && $this->members->getMemberLevelFromDb($this->db, $op['teamId'], $uid) >= 8) {
            return $op;
        }
        throw new AccessDeniedException('You cannot see this provisioning operation');
    }

    /** Creator or Nextcloud administrator. @return array<string,mixed> */
    private function requireRunnable(int $id): array {
        $op  = $this->mapper->findOperation($id);
        if ($op === null) {
            throw new NotFoundException('No such provisioning operation');
        }
        $uid = $this->currentUserId();
        if ($op['createdBy'] !== $uid && !$this->isNcAdmin($uid)) {
            throw new AccessDeniedException('Only the person who started this provisioning, or an administrator, can act on it');
        }
        return $op;
    }

    /**
     * Run `$fn` with the session user set to the operation's creator. A
     * no-op for the creator; for an administrator an impersonation with the
     * same re-checks the background job makes, restored in `finally`.
     *
     * @template T
     * @param array<string,mixed> $op
     * @param callable(): T $fn
     * @return T
     */
    private function asCreator(array $op, callable $fn): mixed {
        $current = $this->userSession->getUser();
        $creator = (string)$op['createdBy'];
        if ($current !== null && $current->getUID() === $creator) {
            return $fn();
        }
        $account = $this->userManager->get($creator);
        if ($account === null) {
            throw new ValidationException('The account that started this provisioning no longer exists');
        }
        if (!$this->members->canUserBulkCreateTeams($creator) && !$this->canUserCreateTeam($creator)) {
            throw new ValidationException('The account that started this provisioning may no longer create teams');
        }
        $this->audit->log(AuditService::INSTANCE_SCOPE, 'provisioning.run_as_creator', $current?->getUID(), 'provisioning', (string)$op['id'], ['creator' => $creator]);
        try {
            $this->userSession->setUser($account);
            return $fn();
        } finally {
            $this->userSession->setUser($current);
        }
    }

    /** `MemberService::canCurrentUserCreateTeam()` for a stored uid. */
    private function canUserCreateTeam(string $uid): bool {
        $rawGroup = trim($this->config->getAppValue(Application::APP_ID, 'createTeamGroup', ''));
        if ($rawGroup === '') {
            return true;
        }
        foreach (array_filter(array_map('trim', explode(',', $rawGroup))) as $gid) {
            if ($this->groupManager->isInGroup($uid, $gid)) {
                return true;
            }
        }
        return false;
    }

    private function isNcAdmin(string $uid): bool {
        return $uid !== '' && $this->groupManager->isAdmin($uid);
    }

    private function requireNcAdmin(): string {
        $uid = $this->currentUserId();
        if (!$this->isNcAdmin($uid)) {
            throw new AccessDeniedException('Only a Nextcloud administrator can do this');
        }
        return $uid;
    }

    private function currentUserId(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated');
        }
        return $user->getUID();
    }
}
