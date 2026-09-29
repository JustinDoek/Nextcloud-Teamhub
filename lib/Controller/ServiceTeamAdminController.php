<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * What a Nextcloud administrator may still do about Service Teams —
 * administrators only.
 *
 * **The gate is the absence of `#[NoAdminRequired]` on every method**, and
 * this is a separate controller from `ServiceTeamController` and
 * `TeamServiceController` precisely so that gate is a property of the file
 * rather than of remembering. Same shape as `MyWorkAdminController`.
 *
 * ## v4.10.23 — the setup flow is gone, two reads and one write are left
 *
 * A service team used to be declared here: an administrator picked a team,
 * named its owner and agents, chose services and switched it on. That whole
 * flow moved to the people who run the team — the *Service* template in the
 * creation wizard and Manage team → Services (DESIGN §2.146) — and
 * `ServiceTeamSetup.vue` went with it.
 *
 * What an administrator keeps is the instance-wide view of a claim that is
 * instance-wide: **which team holds Nextcloud Services**, on the Setup
 * checklist, and the ability to **take it back**. Without that, a bundle
 * claimed by the first team to tick the box could only ever be released by
 * that team's own admins, and an organisation that put it in the wrong
 * place would have no way home. It is the one service-team write an
 * administrator has, and it is deliberately a release and not a reassign:
 * where the desk belongs instead is the next team's decision to make, in
 * their own Services tab.
 *
 * ## The licence
 *
 * Licensed, like every other half. An unlicensed instance gets 403 +
 * `licenseGate: true` from both routes, and the checklist row is absent
 * rather than empty.
 */
class ServiceTeamAdminController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                      $appName,
        IRequest                    $request,
        private ServiceTeamService  $serviceTeams,
        private WorkflowLicenceTier $tier,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/admin/service-teams
     *
     * Who holds Nextcloud Services, for the Setup checklist row. A licensed
     * instance with no holder answers `holder: ''` — which is the row's
     * "nothing set up yet" state, not an error.
     */
    public function index(): JSONResponse {
        try {
            $this->serviceTeams->requireLicence();
            $holder = $this->serviceTeams->holderOfNextcloudServices();

            return new JSONResponse([
                'holderTeamId'      => $holder ?? '',
                'holderName'        => $holder !== null ? $this->serviceTeams->teamName($holder) : '',
                'agentCount'        => $holder !== null ? count($this->serviceTeams->eligibleAgents($holder)) : 0,
                'availableServices' => $this->serviceTeams->describeAvailableServices(),
                'tier'              => $this->tier->tier(),
            ]);
        } catch (\Throwable $e) {
            return $this->adminError($e, 'Failed to read the service desk');
        }
    }

    /**
     * DELETE /api/v1/admin/service-teams/{teamId}
     *
     * Take Nextcloud Services back from a team. The team itself is
     * untouched — it stays a Service team and may claim again — and so is
     * every workflow already running, which keeps the step actor it was
     * created with and stops being claimable until some team holds the
     * bundle again. The confirmation in front of this button is where that
     * is said; this is the write.
     */
    public function destroy(string $teamId): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        try {
            $this->serviceTeams->releaseNextcloudServices($teamId, $uid);
            $this->logger->info('[TeamHub][ServiceTeam] Nextcloud Services released by an administrator', [
                'teamId' => $teamId, 'uid' => $uid, 'app' => 'teamhub',
            ]);
            return new JSONResponse(['released' => true]);
        } catch (\Throwable $e) {
            return $this->adminError($e, 'Failed to release the Nextcloud services', ['teamId' => $teamId]);
        }
    }

    /** @param array<string, mixed> $context */
    private function adminError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
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
