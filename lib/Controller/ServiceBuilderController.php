<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\ServiceTeam\TeamServiceBuilder;
use OCA\TeamHub\Service\TeamTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The service builder's API (v4.10.33, WorkflowHub phase 8a;
 * `docs/service-builder.md`): the services a service team builds, publishes
 * and unpublishes itself, from the *Services* widget on the team's home.
 *
 *   GET    /api/v1/teams/{teamId}/built-services                      list, with the pickers' options
 *   POST   /api/v1/teams/{teamId}/built-services                      a new draft
 *   PUT    /api/v1/teams/{teamId}/built-services/{serviceId}          save the draft
 *   DELETE /api/v1/teams/{teamId}/built-services/{serviceId}          a never-published draft only
 *   POST   /api/v1/teams/{teamId}/built-services/{serviceId}/publish
 *   POST   /api/v1/teams/{teamId}/built-services/{serviceId}/unpublish
 *
 * Not `/services`: that path is the Nextcloud-services bundle
 * (`TeamServiceController`), a different thing with a different gate.
 *
 * ## The gates, re-checked on every call
 *
 *   1. **The Service template.** Only a team created from it builds
 *      services. Any other team answers 403 *Team not found*, the way
 *      `TeamServiceController` does, so the route discloses nothing.
 *   2. **Reading:** any member of the team, directly or through a group —
 *      the team is the desk, and its members answer these services.
 *      `canEdit` tells the client whether to offer the builder.
 *   3. **Writing:** a team admin (Circles level ≥ 8), the role that builds
 *      and publishes (`/service-teams`).
 *
 * Licensed: `TeamServiceBuilder` calls `requireLicence()` first, and an
 * unlicensed instance answers 403 `licenseGate`.
 */
class ServiceBuilderController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                     $appName,
        IRequest                   $request,
        private TeamServiceBuilder $builder,
        private ServiceTeamService $serviceTeams,
        private MemberService      $memberService,
        private TeamTypeService    $teamTypes,
        private IUserSession       $userSession,
        private LoggerInterface    $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/teams/{teamId}/built-services */
    #[NoAdminRequired]
    public function index(string $teamId): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, false);
            return new JSONResponse([
                'services' => $this->builder->listForTeam($teamId),
                'canEdit'  => $this->serviceTeams->isServiceOwner($uid, $teamId),
            ] + $this->builder->options());
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to load the services', ['teamId' => $teamId]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/built-services
     *
     * @param array<string, mixed> $service the document (`TeamServiceBuilder::normalise()`)
     */
    #[NoAdminRequired]
    public function create(string $teamId, array $service = []): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, true);
            return new JSONResponse(['service' => $this->builder->create($teamId, $service, $uid)], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to create the service', ['teamId' => $teamId]);
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/built-services/{serviceId}
     *
     * @param array<string, mixed> $service the document (`TeamServiceBuilder::normalise()`)
     */
    #[NoAdminRequired]
    public function update(string $teamId, int $serviceId, array $service = []): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, true);
            return new JSONResponse(['service' => $this->builder->saveDraft($teamId, $serviceId, $service, $uid)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to save the service', ['teamId' => $teamId, 'serviceId' => $serviceId]);
        }
    }

    /** DELETE /api/v1/teams/{teamId}/built-services/{serviceId} */
    #[NoAdminRequired]
    public function destroy(string $teamId, int $serviceId): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, true);
            $this->builder->delete($teamId, $serviceId, $uid);
            return new JSONResponse(['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to delete the service', ['teamId' => $teamId, 'serviceId' => $serviceId]);
        }
    }

    /** POST /api/v1/teams/{teamId}/built-services/{serviceId}/publish */
    #[NoAdminRequired]
    public function publish(string $teamId, int $serviceId): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, true);
            $service = $this->builder->publish($teamId, $serviceId, $uid);
            $this->logger->info('[TeamHub][ServiceBuilder] Service published', [
                'teamId' => $teamId, 'serviceId' => $serviceId, 'version' => $service['version'], 'app' => 'teamhub',
            ]);
            return new JSONResponse(['service' => $service]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to publish the service', ['teamId' => $teamId, 'serviceId' => $serviceId]);
        }
    }

    /** POST /api/v1/teams/{teamId}/built-services/{serviceId}/unpublish */
    #[NoAdminRequired]
    public function unpublish(string $teamId, int $serviceId): JSONResponse {
        try {
            $uid = $this->requireServiceTeam($teamId, true);
            return new JSONResponse(['service' => $this->builder->unpublish($teamId, $serviceId, $uid)]);
        } catch (\Throwable $e) {
            return $this->serviceError($e, 'Failed to unpublish the service', ['teamId' => $teamId, 'serviceId' => $serviceId]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * The caller's uid, once they pass the gates: a member (or, to write, a
     * team admin) of a team created from the Service template.
     *
     * @throws AccessDeniedException
     */
    private function requireServiceTeam(string $teamId, bool $write): string {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        if ($uid === '') {
            throw new AccessDeniedException('User not authenticated');
        }
        if ($write) {
            $this->memberService->requireAdminLevel($teamId);
        } else {
            $this->memberService->requireMemberLevel($teamId);
        }
        if ($this->teamTypes->getType($teamId) !== 'service') {
            throw new AccessDeniedException('Team not found');
        }
        return $uid;
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
