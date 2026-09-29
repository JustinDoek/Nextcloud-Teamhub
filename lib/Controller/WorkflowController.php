<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\WorkflowRateLimitException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The WorkflowHub API (phase 2, v4.10.14; `docs/workflowhub-architecture.md` §7).
 *
 * Every method is `#[NoAdminRequired]` — workflows are for ordinary
 * members — and none carries `#[NoCSRFRequired]`: reads and writes alike
 * need Nextcloud's request token, which `@nextcloud/axios` sends. There is
 * no licence gate: the built-in workflows are usable on every instance.
 *
 * The controller performs no authorisation and changes no state. It
 * establishes one thing — **who is calling**, from the session — and hands
 * that uid to `WorkflowEngine`, which decides what this person may see or
 * do from the live roles (team level, group membership) and the
 * participant rows. Nothing in a request body names an actor, a
 * participant, a status or a step: the engine acts on the caller's own
 * active step, and a client that sent such a field would find it ignored.
 *
 * Errors: 403 not yours, 404 no such workflow or definition, 400 bad
 * input, 409 the step or workflow is no longer in a state that allows
 * this (the caller's view is stale — reload), 429 asked for a status
 * update too soon.
 *
 * ## The licence (phase 4, v4.10.16)
 *
 * Still no gate on the controller: the built-in workflows are usable on
 * every instance, and **no route refuses because a licence expired while
 * a workflow was running** — completing one is always possible. What the
 * licence decides is decided by `WorkflowEngine`, which refuses a
 * licensed-only capability with `LicenseGateException` → 403 +
 * `licenseGate: true` (the shape `MyWorkController` and the Budget/Time
 * controllers already use). Every read carries `tier` and `capabilities`
 * so the client hides what this instance does not have rather than
 * offering it and failing (CLAUDE.md § Permissions).
 */
class WorkflowController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                     $appName,
        IRequest                   $request,
        private WorkflowEngine     $engine,
        private WorkflowLicenceTier $tier,
        private IUserSession       $userSession,
        private LoggerInterface    $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/workflows?status=
     *
     * Every workflow the caller takes part in, most recently updated
     * first — as initiator, as a holder of a step's actor (by uid, group
     * or team role), or as somebody who acted. `status` narrows to one
     * instance status.
     */
    #[NoAdminRequired]
    public function index(string $status = ''): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'workflows'    => $this->engine->listForParticipant($uid, $status !== '' ? $status : null),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->workflowError($e, 'Failed to list workflows');
        }
    }

    /**
     * GET /api/v1/workflows/definitions
     *
     * The built-in workflows a client may offer to start, with their steps
     * and actors in the caller's language. Whether *this* caller may start
     * one on *a* team is decided at start.
     */
    #[NoAdminRequired]
    public function definitions(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'definitions'  => $this->engine->describeDefinitions(),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->workflowError($e, 'Failed to list workflow definitions');
        }
    }

    /**
     * GET /api/v1/workflows/{id}
     *
     * One workflow with its steps, participants, what the caller may do
     * next, and its history. Participants and Nextcloud administrators only.
     */
    #[NoAdminRequired]
    public function show(int $id, string $step = ''): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            // v4.10.37 — `?step=` is the task the caller opened it from.
            $workflow            = $this->engine->get($id, $uid, $step !== '' ? $step : null);
            $workflow['history'] = $this->engine->listEvents($id, $uid);
            $workflow['people']  = ($workflow['people'] ?? []) + $this->engine->describePeople(array_column($workflow['history'], 'actorUid'));
            return new JSONResponse([
                'workflow'     => $workflow,
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->workflowError($e, 'Failed to load the workflow', ['id' => $id]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/workflows/{definitionKey}
     * Body: { "data": { … } } — the definition's opening payload
     *       (team request: teamName, reason).
     *
     * Start a built-in workflow on a team. The definition decides who may
     * (team request: any member of the team) and validates the payload.
     * 201 with the new workflow; 409 when one of this kind is already open
     * on the team and the definition allows only one; 404 when the
     * definition is unknown or not startable on this instance.
     *
     * Rate-limited to ten a hour per user (v4.10.17). `team_request`'s
     * concurrency is `unbounded` — asking for several teams is legitimate
     * — and every start notifies the requesting team's owner and
     * moderators, so without a cap one member could ring their bell
     * arbitrarily often. `requestStatus` is capped for exactly that reason
     * and this is the stronger path of the two.
     *
     * @param array<string, mixed> $data
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 3600)]
    public function start(string $teamId, string $definitionKey, array $data = []): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(
                ['workflow' => $this->engine->create($definitionKey, $teamId, $uid, $data)],
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->workflowError($e, 'Failed to start the workflow', ['teamId' => $teamId, 'definition' => $definitionKey]);
        }
    }

    /**
     * POST /api/v1/workflows/{id}/complete   Body: { "note": "…" } (optional)
     *
     * Complete the caller's active step. A step that is merely available
     * is started and completed in one go. Completing the last step
     * completes the workflow.
     */
    #[NoAdminRequired]
    public function complete(int $id, string $note = '', string $step = '', array $fileIds = []): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->completeStep($id, $uid, $note, $this->stepKey($step), $fileIds), 'complete the step');
    }

    /**
     * POST /api/v1/workflows/{id}/reject   Body: { "reason": "…" } (required)
     *
     * Reject the caller's active step; the workflow is rejected and the
     * steps that were never reached are skipped.
     */
    #[NoAdminRequired]
    public function reject(int $id, string $reason = '', string $step = '', array $fileIds = []): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->rejectStep($id, $uid, $reason, $this->stepKey($step), $fileIds), 'reject the step');
    }

    /**
     * POST /api/v1/workflows/{id}/request-information   Body: { "note": "…" } (required)
     *
     * The caller — a holder of the active step — needs something before
     * they can go on; the workflow waits and the initiator is told.
     */
    #[NoAdminRequired]
    public function requestInformation(int $id, string $note = '', string $step = '', array $fileIds = []): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->requestInformation($id, $uid, $note, $this->stepKey($step), $fileIds), 'request information');
    }

    /**
     * POST /api/v1/workflows/{id}/provide-information   Body: { "note": "…" } (required)
     *
     * Any participant answers; the step goes back to in progress and its
     * holders are told.
     */
    #[NoAdminRequired]
    public function provideInformation(int $id, string $note = '', string $step = '', array $fileIds = []): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->provideInformation($id, $uid, $note, $this->stepKey($step), $fileIds), 'provide information');
    }

    /**
     * POST /api/v1/workflows/{id}/message   Body: { "note": "…" } (required), "fileIds" (optional)
     *
     * v4.11.0 — the requester and whoever claimed a service request write
     * to each other while it is open. Changes no status; the other side is
     * notified (nobody while the request is unclaimed). 403 for anybody
     * else, and for a workflow no service team handles. At most sixty an
     * hour per user at the edge: every message can ring somebody's bell.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 3600)]
    public function message(int $id, string $note = '', array $fileIds = []): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->postMessage($id, $uid, $note, $fileIds), 'send the message');
    }

    /**
     * POST /api/v1/workflows/{id}/cancel   Body: { "reason": "…" } (optional)
     *
     * Withdraw the workflow — the initiator, or a Nextcloud administrator.
     */
    #[NoAdminRequired]
    public function cancel(int $id, string $reason = ''): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->cancel($id, $uid, $reason), 'cancel the workflow');
    }

    /**
     * POST /api/v1/workflows/{id}/status-request   Body: { "note": "…" } (optional)
     *
     * A participant who is not responsible right now asks the responsible
     * actor where things stand. Records an event and notifies the holders
     * of the active step; changes nothing else. Once per participant per
     * step per 24 hours in the engine (429 otherwise), and at most ten
     * calls an hour per user at the edge, so a stuck client cannot ring
     * anybody's bell repeatedly.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 3600)]
    public function requestStatus(int $id, string $note = '', string $step = ''): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->requestStatus($id, $uid, $note, $this->stepKey($step)), 'request a status update');
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * v4.10.37 — the task a verb acts on, from the body's `step`; '' lets
     * the engine pick the caller's own task (`WorkflowEngine::pickStep()`).
     */
    private function stepKey(string $step): ?string {
        $step = trim($step);
        return $step !== '' ? $step : null;
    }

    /** @param callable(string): array<string, mixed> $call */
    private function act(int $id, callable $call, string $what): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(['workflow' => $call($uid)]);
        } catch (\Throwable $e) {
            return $this->workflowError($e, 'Failed to ' . $what, ['id' => $id]);
        }
    }

    /** @param array<string, mixed> $context */
    private function workflowError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
        if ($e instanceof LicenseGateException) {
            // Phase 4: the capability is licensed and this instance is not.
            // Never raised by an action that would move an existing workflow
            // forward — only by starting a licensed definition or by asking
            // for a status update.
            return new JSONResponse([
                'error'            => $e->getMessage(),
                'licenseGate'      => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        }
        if ($e instanceof WorkflowTransitionException) {
            return new JSONResponse(['error' => $e->getMessage(), 'conflict' => true], Http::STATUS_CONFLICT);
        }
        if ($e instanceof WorkflowRateLimitException) {
            $r = new JSONResponse(['error' => $e->getMessage(), 'retryAfter' => $e->retryAfterSeconds], Http::STATUS_TOO_MANY_REQUESTS);
            $r->addHeader('Retry-After', (string)$e->retryAfterSeconds);
            return $r;
        }
        return $this->exceptionResponse($e, $fallback, $context);
    }

    private function requireUser(): string|JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return $uid;
    }
}
