<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\PresenceTeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Team-facing presence endpoints. Every endpoint:
 *   - Requires an authenticated NC session (#[NoAdminRequired]).
 *   - Requires the caller to be a team member (requireMemberLevel).
 *   - Config writes additionally require team admin (requireAdminLevel ≥ 8).
 *
 * Error mapping:
 *   InvalidArgumentException → 400
 *   \Exception (not member)  → 403
 *   else                     → 500
 */
class PresenceTeamController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MemberService        $memberService,
        private PresenceTeamService  $teamPresence,
        private LoggerInterface      $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Grid — /api/v1/teams/{teamId}/presence
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/presence?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Returns the team presence grid for the given date range.
     * Privacy filter (hide_reasons) applied server-side.
     *
     * SEC: team membership required.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getTeamGrid(string $teamId, string $from = '', string $to = ''): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse(
                $this->teamPresence->getTeamGrid($teamId, $from, $to)
            );
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getTeamGrid');
        }
    }

    // =========================================================================
    // Config — /api/v1/teams/{teamId}/presence/config
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/presence/config
     * Returns { presence_enabled: bool, hide_reasons: bool }.
     *
     * SEC: team membership required (members need to know presence_enabled to
     * decide whether to show the tab).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getConfig(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->teamPresence->getConfig($teamId));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getConfig');
        }
    }

    /**
     * PUT /api/v1/teams/{teamId}/presence/config
     * Body: { presence_enabled?: bool|int, hide_reasons?: bool|int }
     * Only keys present in the body are written; missing keys are unchanged.
     *
     * SEC: team admin (level ≥ 8) required.
     */
    #[NoAdminRequired]
    public function saveConfig(
        string $teamId,
        ?int $presence_enabled = null,
        ?int $hide_reasons     = null,
    ): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $data = [];
            if ($presence_enabled !== null) {
                $data['presence_enabled'] = $presence_enabled;
            }
            if ($hide_reasons !== null) {
                $data['hide_reasons'] = $hide_reasons;
            }

            if (count($data) === 0) {
                return new JSONResponse(
                    ['error' => 'No fields provided'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            return new JSONResponse($this->teamPresence->saveConfig($teamId, $data));
        } catch (\Throwable $e) {
            return $this->mapError($e, 'saveConfig');
        }
    }

    // =========================================================================
    // Error mapper
    // =========================================================================

    private function mapError(\Throwable $e, string $action): JSONResponse {
        $msg = $e->getMessage();

        if ($e instanceof \InvalidArgumentException) {
            return new JSONResponse(['error' => $msg], Http::STATUS_BAD_REQUEST);
        }

        // MemberService throws plain \Exception for auth/membership failures.
        if (str_contains($msg, 'not a member') || str_contains($msg, 'not authenticated')
            || str_contains($msg, 'not have admin') || str_contains($msg, 'Insufficient')
        ) {
            return new JSONResponse(['error' => $msg], Http::STATUS_FORBIDDEN);
        }

        $this->logger->error(sprintf(
            '[TeamHub][PresenceTeamController] %s: %s', $action, $msg
        ), ['exception' => $e]);

        return new JSONResponse(['error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
