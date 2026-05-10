<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\ResourceDiscoveryService;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\AuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles pending resource accept / ignore / unignore actions.
 *
 * All endpoints require:
 *   - authenticated user (NoAdminRequired = NC authentication only, not NC admin)
 *   - team admin level (≥ 8) — verified inline before delegating to the service
 */
class ResourceStateController extends Controller {

    public function __construct(
        IRequest                              $request,
        private readonly ResourceDiscoveryService $discoveryService,
        private readonly ResourceService          $resourceService,
        private readonly AuditService             $auditService,
        private readonly IUserSession             $userSession,
        private readonly IDBConnection            $db,
        private readonly LoggerInterface          $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/teams/{teamId}/resources/panel
    // Returns all resource rows for the Settings panel (grouped by app).
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function getPanelData(string $teamId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $data = $this->discoveryService->getSettingsPanelData($teamId);
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] getPanelData failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/resources/{app}/{resourceId}/accept
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function acceptResource(string $teamId, string $app, string $resourceId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->discoveryService->acceptResource($teamId, $app, $resourceId, $uid);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            $this->logger->warning('[TeamHub][ResourceStateController] acceptResource error', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app_id' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] acceptResource failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/resources/{app}/{resourceId}/ignore
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function ignoreResource(string $teamId, string $app, string $resourceId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->discoveryService->ignoreResource($teamId, $app, $resourceId, $uid);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            $this->logger->warning('[TeamHub][ResourceStateController] ignoreResource error', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app_id' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] ignoreResource failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/teams/{teamId}/resources/{app}/{resourceId}/unignore
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function unignoreResource(string $teamId, string $app, string $resourceId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->discoveryService->unignoreResource($teamId, $app, $resourceId, $uid);
            return new JSONResponse(['status' => 'ok']);
        } catch (\RuntimeException $e) {
            $this->logger->warning('[TeamHub][ResourceStateController] unignoreResource error', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app_id' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] unignoreResource failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/teams/{teamId}/resources/{app}/{resourceId}/delete
    // Destroys the underlying NC resource and removes the registry row.
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function deleteResource(string $teamId, string $app, string $resourceId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $result = $this->resourceService->deleteSpecificResource($teamId, $app, $resourceId);
            if (!($result['success'] ?? false)) {
                return new JSONResponse(['error' => $result['error'] ?? 'Delete failed'], Http::STATUS_BAD_REQUEST);
            }
            $this->auditService->log(
                $teamId,
                'resource.deleted',
                $uid,
                'resource',
                "{$app}:{$resourceId}",
                ['app_id' => $app, 'resource_id' => $resourceId],
            );
            return new JSONResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] deleteResource failed', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/teams/{teamId}/resources/{app}/{resourceId}/remove
    // Strips ACL + deletes the registry row (§6.2 Remove team access).
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function removeAccess(string $teamId, string $app, string $resourceId): JSONResponse {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->isTeamAdmin($teamId, $uid)) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $result = $this->resourceService->removeTeamAccess($teamId, $app, $resourceId);
            if (!($result['success'] ?? false)) {
                return new JSONResponse(['error' => $result['error'] ?? 'Remove failed'], Http::STATUS_BAD_REQUEST);
            }
            $this->auditService->log(
                $teamId,
                'resource.access_removed',
                $uid,
                'resource',
                "{$app}:{$resourceId}",
                [
                    'app_id'          => $app,
                    'resource_id'     => $resourceId,
                    'shared_with_other_teams' => $result['sharedWithOther'] ?? false,
                    'other_team_count'        => $result['otherTeamCount']  ?? 0,
                ],
            );
            return new JSONResponse(['status' => 'ok', 'aclStripped' => $result['aclStripped'] ?? false]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ResourceStateController] removeAccess failed', [
                'teamId' => $teamId, 'app' => $app, 'resourceId' => $resourceId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function currentUid(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /**
     * Check whether $uid has level ≥ 8 (admin) on the team.
     * Direct membership only — indirect group membership does not grant admin rights.
     */
    private function isTeamAdmin(string $teamId, string $uid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('level')
                ->from('circles_member')
                ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('user_id',  $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            return $row && (int)$row['level'] >= 8;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ResourceStateController] isTeamAdmin check failed', [
                'teamId' => $teamId, 'uid' => $uid,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }
}
