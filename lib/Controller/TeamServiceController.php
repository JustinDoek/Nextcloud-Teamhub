<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamTypeService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Manage team → Services — the team admin's side (v4.10.23).
 *
 * This is where a team claims **Nextcloud Services**: the six built-in
 * workflows, as one bundle, held by at most one team on the instance. The
 * creation wizard posts to the same `claim` method with the same checks, so
 * ticking the box during creation and ticking it a week later are one code
 * path and cannot drift.
 *
 * ## The two gates, in this order
 *
 *   1. **Team admin** (`MemberService::requireAdminLevel()`, Circles level
 *      ≥ 8) — the role that "service owner" now maps to. Not a member, not
 *      a moderator: claiming an instance-wide resource is the most
 *      consequential thing a team admin does here.
 *   2. **The Service template.** A team only sees this tab, and only
 *      answers on these routes, when it was created from the *Service*
 *      template. That is what makes "is this a service team" one answer
 *      rather than two: the template says what the team is for, the claim
 *      says what it currently answers.
 *
 * Both are re-checked server-side on every call. The frontend hides the tab
 * from everybody else — `CLAUDE.md` § Permissions — but "the frontend won't
 * call this" is not a security boundary.
 *
 * ## The licence
 *
 * Licensed. Every method calls `requireLicence()` through the service, and
 * an unlicensed instance answers 403 `licenseGate` — where the tab is not
 * rendered at all, so this is the backstop rather than the message.
 */
class TeamServiceController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                      $appName,
        IRequest                    $request,
        private ServiceTeamService  $serviceTeams,
        private MemberService       $memberService,
        private TeamTypeService     $teamTypes,
        private WorkflowLicenceTier $tier,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/teams/{teamId}/services
     *
     * What the Services tab renders: whether this team holds the bundle,
     * who holds it otherwise, the source group, and the six services the
     * bundle carries for the **?** panel.
     */
    #[NoAdminRequired]
    public function show(string $teamId): JSONResponse {
        try {
            $this->requireServiceTeamAdmin($teamId);
            return new JSONResponse([
                'services'          => $this->serviceTeams->describeForTeam($teamId),
                'availableServices' => $this->serviceTeams->describeAvailableServices(),
                'tier'              => $this->tier->tier(),
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to load the team services', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/services
     *
     * Claim Nextcloud Services for this team. Refused with 400 and a
     * sentence when another team holds it — the same answer the greyed-out
     * checkbox gives, for the caller who got there anyway.
     */
    #[NoAdminRequired]
    public function claim(string $teamId): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        try {
            $this->requireServiceTeamAdmin($teamId);
            $this->serviceTeams->claimNextcloudServices($teamId, $uid);
            $this->logger->info('[TeamHub][ServiceTeam] Nextcloud Services claimed', [
                'teamId' => $teamId, 'uid' => $uid, 'app' => 'teamhub',
            ]);
            return new JSONResponse(['services' => $this->serviceTeams->describeForTeam($teamId)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to claim the Nextcloud services', ['teamId' => $teamId]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/services
     *
     * Give the bundle back. The team stays a service team and may claim
     * again; what stops is answering requests.
     */
    #[NoAdminRequired]
    public function release(string $teamId): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        try {
            $this->requireServiceTeamAdmin($teamId);
            $this->serviceTeams->releaseNextcloudServices($teamId, $uid);
            return new JSONResponse(['services' => $this->serviceTeams->describeForTeam($teamId)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to release the Nextcloud services', ['teamId' => $teamId]);
        }
    }

    /**
     * Team admin of a team created from the Service template. Both halves
     * throw the same way a missing team does, so a caller learns nothing
     * about a team they may not administer.
     *
     * @throws AccessDeniedException
     */
    private function requireServiceTeamAdmin(string $teamId): void {
        $this->memberService->requireAdminLevel($teamId);
        if ($this->teamTypes->getType($teamId) !== 'service') {
            throw new AccessDeniedException('Team not found');
        }
    }

    /** @param array<string, mixed> $context */
    private function serviceError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
        if ($e instanceof LicenseGateException) {
            return new JSONResponse([
                'error'            => $e->getMessage(),
                'licenseGate'      => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        }
        return $this->exceptionResponse($e, $fallback, $context);
    }
}
