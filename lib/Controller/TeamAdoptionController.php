<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\TeamAdoptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The grid of teams made outside TeamHub (v4.10.50, DESIGN §2.149).
 *
 * One grid, two homes: Admin → TeamHub (Import/Export) and a widget on the
 * team that holds the Nextcloud services. So every route is
 * `#[NoAdminRequired]` and the service decides who may use it —
 * `TeamAdoptionDecisionService::mayDecide()`: a Nextcloud administrator, or
 * a member of the holding team while it holds the service. Everybody else
 * gets 403, and the frontend hides the grid from them.
 *
 * Thin by design: input in, the service's answer out.
 */
class TeamAdoptionController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                      $appName,
        IRequest                    $request,
        private TeamAdoptionService $adoption,
        private IUserSession        $userSession,
        private LoggerInterface     $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/team-adoptions — the grid: pending teams, teams decided in
     * the last 30 days, and the templates and policies to choose from.
     */
    #[NoAdminRequired]
    public function index(): JSONResponse {
        return $this->run(fn (string $uid): array => $this->adoption->grid($uid), 'Failed to load teams made outside TeamHub');
    }

    /**
     * PUT /api/v1/team-adoptions/{id} — keep the template and policy chosen
     * for a pending team (the request's own *Accept* uses them).
     */
    #[NoAdminRequired]
    public function update(int $id, string $templateKey = '', string $profileKey = ''): JSONResponse {
        return $this->run(
            fn (string $uid): array => ['adoption' => $this->adoption->choose($id, $uid, $templateKey, $profileKey)],
            'Failed to save the choice',
        );
    }

    /** POST /api/v1/team-adoptions/{id}/accept */
    #[NoAdminRequired]
    public function accept(int $id, string $templateKey = '', string $profileKey = ''): JSONResponse {
        return $this->run(
            fn (string $uid): array => ['adoption' => $this->adoption->accept($id, $uid, $templateKey, $profileKey)],
            'Failed to accept the team',
        );
    }

    /** POST /api/v1/team-adoptions/{id}/decline — a reason is required. */
    #[NoAdminRequired]
    public function decline(int $id, string $reason = ''): JSONResponse {
        return $this->run(
            fn (string $uid): array => ['adoption' => $this->adoption->decline($id, $uid, $reason)],
            'Failed to decline the team',
        );
    }

    /**
     * @param callable(string): array<string,mixed> $action
     */
    private function run(callable $action, string $fallback): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new JSONResponse($action($uid));
        } catch (LicenseGateException $e) {
            return new JSONResponse([
                'error'            => $e->getMessage(),
                'licenseGate'      => true,
                'enforcementLevel' => $e->getEnforcementLevel(),
            ], Http::STATUS_FORBIDDEN);
        } catch (WorkflowTransitionException $e) {
            return new JSONResponse(['error' => $e->getMessage(), 'conflict' => true], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, $fallback);
        }
    }
}
