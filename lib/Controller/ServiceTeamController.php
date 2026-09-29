<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\WorkflowRateLimitException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\QuotaTeamsService;
use OCA\TeamHub\Service\ServiceTeam\ExpiryTeamsService;
use OCA\TeamHub\Service\ServiceTeam\ServiceCategoryService;
use OCA\TeamHub\Workflow\Definition\TeamExpiryRequestDefinition;
use OCA\TeamHub\Service\ServiceTeam\ServiceDeskStatisticsService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Service Team API — the agent's side (WorkflowHub phase 5, v4.10.20).
 *
 * Every method is `#[NoAdminRequired]`: a service agent is an ordinary
 * member with a job, not an administrator. None carries
 * `#[NoCSRFRequired]`. The **setup** half — which team is a service team,
 * who owns it, who the agents are — is `ServiceTeamAdminController`, a
 * separate file so the Nextcloud-administrator gate is a property of the
 * class and not of a habit.
 *
 * The controller establishes one thing, **who is calling**, and hands that
 * uid to `ServiceTeamService` and `WorkflowEngine`. Eligibility, the
 * licence, who may claim what and who may read an internal note are all
 * decided there. Nothing in a request body names an agent except the
 * target of an assignment, and that target is checked for eligibility
 * server-side before it is written.
 *
 * ## The licence
 *
 * Service Teams are licensed and there is no reduced version of them: every
 * route here answers 403 + `licenseGate: true` on an unlicensed instance,
 * raised by `ServiceTeamService::requireLicence()` before anything is read.
 * Workflows that are already running are not affected — moving one forward
 * never reads the licence (`docs/unlicensed-workflow-data-lifecycle.md`) —
 * but they can no longer be worked *as a queue*.
 */
class ServiceTeamController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                      $appName,
        IRequest                    $request,
        private ServiceTeamService  $serviceTeams,
        private WorkflowEngine      $engine,
        // Only to put a team's name beside its id in the picker. Read from
        // the circle row directly, because an agent of a service team is not
        // necessarily a member of it.
        private WorkflowActorResolver $resolver,
        private WorkflowLicenceTier $tier,
        // v4.10.27 — the statistics widget on the service team's home.
        private ServiceDeskStatisticsService $statistics,
        // v4.10.29 — which teams the caller may ask more storage for; also
        // what decides whether the quota card is on their Services page.
        private QuotaTeamsService   $quotaTeams,
        // v4.10.45 — the same for more time before a team's expiration
        // date, and the administrator's categories and links.
        private ExpiryTeamsService  $expiryTeams,
        private ServiceCategoryService $categories,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/service-teams
     *
     * The service teams the caller may work the queue of, with their
     * catalogues. Empty on an instance with no service teams and for
     * anybody who is not an agent — which is not an error: most people are
     * not on a service desk, and the client hides the whole surface when
     * this comes back empty.
     */
    #[NoAdminRequired]
    public function index(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $out = [];
            foreach ($this->serviceTeams->serviceTeamsForAgent($uid) as $teamId) {
                $out[] = [
                    'teamId'         => $teamId,
                    'teamName'       => $this->resolver->teamName($teamId),
                    // v4.10.23 — an admin of the service team. What it gates
                    // is unchanged: taking over an item another agent holds.
                    'isServiceOwner' => $this->serviceTeams->isServiceOwner($uid, $teamId),
                    'catalogue'      => $this->serviceTeams->describeCatalogue($teamId),
                    // v4.10.27 — the badge behind the team's name in the
                    // navigation, like unread messages (`/service-teams`).
                    'unclaimedCount' => $this->engine->unclaimedCount($teamId),
                ];
            }
            return new JSONResponse([
                'serviceTeams' => $out,
                'available'    => $this->serviceTeams->isAvailable(),
                'tier'         => $this->tier->tier(),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to list service teams');
        }
    }

    /**
     * GET /api/v1/service-teams/{teamId}/queue
     *
     * The incoming queue: unclaimed, mine, and what other agents have.
     * Eligible agents of that service team only.
     */
    #[NoAdminRequired]
    public function queue(string $teamId): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'queue'        => $this->engine->listQueue($teamId, $uid),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to load the queue', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/service-teams/{teamId}/statistics?days=30
     *
     * The statistics widget on a service team's home (v4.10.27): counts for
     * the period, time to first claim, time to close, and the same per
     * service. Every member of the service team may read it — the team is
     * the desk (Justin, 2026-09-24) — and nobody else; `days` is one of
     * 7, 30 or 90, anything else is a 400.
     */
    #[NoAdminRequired]
    public function statistics(string $teamId, int $days = 30): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(['statistics' => $this->statistics->forDesk($teamId, $uid, $days)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to load the service statistics', ['teamId' => $teamId]);
        }
    }

    /**
     * GET /api/v1/service-teams/catalogue
     *
     * Every service any active service team offers — the catalogue page,
     * and the form behind each of its cards.
     *
     * **No longer flat** (v4.10.25, DESIGN §2.147): every entry carries the
     * offering team's id *and* its name, because the page groups by team
     * and prints the team on every card in the A–Z view. Two desks may
     * offer services with similar names, and the offering team is then the
     * only thing on the card that tells them apart. The name is resolved
     * here rather than in the client: the client has no list of teams it is
     * not a member of, and a service desk that answers the organisation's
     * requests is not a secret (the same reasoning as `claim-status`).
     *
     * One query per active service team, and there is at most a handful of
     * those — the bundle is exclusive and a team offering its own services
     * is one row per team.
     *
     * **The quota card is per viewer** (v4.10.29): it is listed only for
     * somebody who administers a team with a team space
     * (`QuotaTeamsService`). Every other service may be asked by any member
     * of any team; this one only by a team admin, about a space, and a card
     * whose form would have nothing to offer is hidden rather than shown and
     * refused.
     */
    #[NoAdminRequired]
    public function catalogue(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $this->serviceTeams->requireLicence();
            $out = [];
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
                    if ($entry['definitionKey'] === QuotaRequestDefinition::KEY) {
                        $mayAskQuota ??= $this->quotaTeams->forUser($uid) !== [];
                        if (!$mayAskQuota) {
                            continue;
                        }
                    }
                    // v4.10.45 — only for somebody who administers a team
                    // with an expiration date.
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
            // v4.10.45 — the categories in the administrator's order (the
            // tiles follow it), and the links under the catalog.
            $categories = array_map(
                static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'icon' => $c['icon']],
                $this->categories->list(),
            );
            return new JSONResponse([
                'catalogue'  => $out,
                'categories' => $categories,
                'links'      => $this->categories->links(),
                'tier'       => $this->tier->tier(),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to load the service catalogue');
        }
    }

    /**
     * GET /api/v1/service-teams/quota-teams
     *
     * The teams the caller may ask more storage for (v4.10.29): the ones
     * they administer that have a team space, each with its current quota
     * and any request already open. What the quota card's form offers as its
     * team picker. Empty is an answer, not an error; the catalogue already
     * hides the card for a caller this is empty for.
     */
    #[NoAdminRequired]
    public function quotaTeams(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $this->serviceTeams->requireLicence();
            return new JSONResponse(['teams' => $this->quotaTeams->forUser($uid)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to list the teams a quota can be requested for');
        }
    }

    /**
     * GET /api/v1/service-teams/expiry-teams
     *
     * The teams the caller may ask more time for (v4.10.45): the ones they
     * administer that have an expiration date, each with the date and any
     * request already open. What the card's form offers as its team picker.
     */
    #[NoAdminRequired]
    public function expiryTeams(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $this->serviceTeams->requireLicence();
            return new JSONResponse(['teams' => $this->expiryTeams->forUser($uid)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to list the teams more time can be requested for');
        }
    }

    /**
     * GET /api/v1/service-teams/claim-status
     *
     * Whether the Nextcloud services are available on this instance and
     * whether a team already holds them — what the creation wizard's
     * *Nextcloud Services* checkbox reads before the team it would belong
     * to exists.
     *
     * Readable by any signed-in account, because anybody who may create a
     * team may see whether the box is free. It discloses the holding
     * team's **name** and nothing else: a control that greys out without
     * saying who has it sends somebody to an administrator to find out,
     * and a team that is visibly answering the organisation's requests is
     * not a secret. No membership, no roster, no queue.
     *
     * `available: false` — an unlicensed instance — is the answer that
     * hides the card, and is not an error.
     */
    #[NoAdminRequired]
    public function claimStatus(): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            if (!$this->serviceTeams->isAvailable()) {
                return new JSONResponse(['available' => false, 'claimed' => false, 'holderName' => '']);
            }
            $holder = $this->serviceTeams->holderOfNextcloudServices();
            return new JSONResponse([
                'available'         => true,
                'claimed'           => $holder !== null,
                'holderName'        => $holder !== null ? $this->serviceTeams->teamName($holder) : '',
                'availableServices' => $this->serviceTeams->describeAvailableServices(),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to read the service claim');
        }
    }

    /**
     * POST /api/v1/workflows/{id}/claim
     *
     * Take an unclaimed request out of the queue. 409 when somebody else
     * already has it — the caller's view is stale, and reloading shows who.
     */
    #[NoAdminRequired]
    public function claim(int $id, string $step = ''): JSONResponse {
        return $this->act($id, fn (string $uid): array => $this->engine->claimStep($id, $uid, $step !== '' ? $step : null), 'claim the request');
    }

    /**
     * POST /api/v1/workflows/{id}/assign
     * Body: { "uid": "…" } — an eligible agent of the handling service team.
     *
     * Hand the request to somebody, or take it over by naming yourself.
     * 400 when the target is not an eligible agent.
     */
    #[NoAdminRequired]
    public function assign(int $id, string $uid = '', string $step = ''): JSONResponse {
        return $this->act($id, fn (string $caller): array => $this->engine->assignStep($id, $caller, $uid, $step !== '' ? $step : null), 'assign the request');
    }

    /**
     * POST /api/v1/workflows/{id}/release
     * Body: { "reason": "…" } — optional.
     *
     * Put a claimed request back in the queue. The assignee or the service
     * owner only.
     */
    #[NoAdminRequired]
    public function release(int $id, string $reason = '', string $step = ''): JSONResponse {
        return $this->act(
            $id,
            fn (string $caller): array => $this->engine->releaseStep($id, $caller, $reason !== '' ? $reason : null, $step !== '' ? $step : null),
            'release the request',
        );
    }

    /**
     * POST /api/v1/workflows/{id}/internal-note
     * Body: { "note": "…" } — required.
     *
     * A note the service team keeps to itself. Never returned to the
     * requester; see `WorkflowEngine::listEvents()`.
     */
    #[NoAdminRequired]
    public function internalNote(int $id, string $note = '', string $step = '', array $fileIds = []): JSONResponse {
        return $this->act(
            $id,
            fn (string $caller): array => $this->engine->addInternalNote($id, $caller, $note, $step !== '' ? $step : null, $fileIds),
            'add the internal note',
        );
    }

    /**
     * POST /api/v1/workflows/{id}/close-request
     * Body: { "note": "…" } — optional.
     *
     * v4.10.31 — an admin of the service team closes a request, at any
     * step. At the requester's confirmation it completes that step; before
     * it, the request ends *closed* with the steps not done marked skipped.
     * The admin rule is `WorkflowEngine::mayCloseForDesk()`; a member who is
     * not an admin gets 403.
     */
    #[NoAdminRequired]
    public function close(int $id, string $note = '', array $fileIds = []): JSONResponse {
        return $this->act(
            $id,
            fn (string $caller): array => $this->engine->closeRequest($id, $caller, $note !== '' ? $note : null, $fileIds),
            'close the request',
        );
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @param callable(string): array<string, mixed> $call */
    private function act(int $id, callable $call, string $what): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(['workflow' => $call($uid)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to ' . $what, ['id' => $id]);
        }
    }

    /**
     * The same mapping `WorkflowController` uses, so a client handles one
     * error shape for both halves of a workflow.
     *
     * @param array<string, mixed> $context
     */
    private function serviceError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
        if ($e instanceof LicenseGateException) {
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
