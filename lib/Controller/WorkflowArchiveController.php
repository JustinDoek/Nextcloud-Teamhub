<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\Workflow\WorkflowArchiveService;
use OCA\TeamHub\Service\Workflow\WorkflowAttachmentService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\WorkflowArchiveAudience;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The workflow archive API (WorkflowHub phase 6, v4.10.21;
 * `docs/workflow-archiving.md`).
 *
 * Every method is `#[NoAdminRequired]` — an archive belongs to the team
 * that asked and the desk that answered, not to the server's
 * administrators — and none carries `#[NoCSRFRequired]`: reads and writes
 * alike need Nextcloud's request token.
 *
 * The controller performs **no authorisation** and applies **no filter**.
 * It establishes who is calling from the session, passes the audience
 * through as a word, and hands both to `WorkflowArchiveService`, which
 * decides from the live roles what this person may read and renders only
 * that. Nothing in a request body names a team, a participant, a
 * visibility or a role: a client that sent one would find it ignored.
 *
 * In particular, **`audience` is a request, not a grant**. Asking for the
 * `service_team` view of a record is how a desk's own agent reads the
 * internal half; asking for it as anybody else is a 403, and asking for
 * the `requesting_team` view never returns an internal note to anybody at
 * all, agent or administrator, because that filter belongs to the audience
 * rather than to the viewer.
 *
 * Errors: 403 not yours (or `licenseGate` on an unlicensed instance), 404
 * no such archive, 400 bad input, 409 the workflow has ended and its
 * documents are closed.
 */
class WorkflowArchiveController extends Controller {

    use ExceptionResponseTrait;

    public function __construct(
        string                            $appName,
        IRequest                          $request,
        private WorkflowArchiveService    $archive,
        private WorkflowAttachmentService $documents,
        private WorkflowLicenceTier       $tier,
        private IUserSession              $userSession,
        private LoggerInterface           $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/archive/workflows
     *     ?audience=&q=&outcome=&definitionKey=&serviceKey=&from=&to=&limit=&offset=
     *
     * Search the archives this caller may read. With no `audience` both are
     * searched and each result says which one it came from. The scope — the
     * teams and the desks — is resolved from the caller's live roles, never
     * from the query.
     */
    #[NoAdminRequired]
    public function index(
        string $audience = '',
        string $q = '',
        string $outcome = '',
        string $definitionKey = '',
        string $serviceKey = '',
        int    $from = 0,
        int    $to = 0,
        int    $limit = 25,
        int    $offset = 0,
    ): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $found = $this->archive->search($uid, [
                'audience'      => $audience,
                'q'             => $q,
                'outcome'       => $outcome,
                'definitionKey' => $definitionKey,
                'serviceKey'    => $serviceKey,
                'from'          => $from,
                'to'            => $to,
                'limit'         => $limit,
                'offset'        => $offset,
            ]);
            return new JSONResponse($found + [
                'audiences'    => WorkflowArchiveAudience::ALL,
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to search the workflow archive');
        }
    }

    /**
     * GET /api/v1/archive/workflows/{id}?audience=
     *
     * One archive projection. `requesting_team` by default — the reading
     * every party to a request has, and the one that never carries an
     * internal note.
     */
    #[NoAdminRequired]
    public function show(int $id, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'archive'      => $this->archive->get($id, $uid, $audience),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to load the archived workflow', ['id' => $id]);
        }
    }

    /**
     * GET /api/v1/archive/workflows/{id}/record?audience=
     *
     * **The link from a projection to the authoritative record**: which
     * workflow this is a reading of, on which version of which definition
     * it was created, and whether its sealed history still verifies. The
     * history comes back filtered for the audience that asked — a link to
     * the record is not a way around the filter.
     */
    #[NoAdminRequired]
    public function record(int $id, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'record'       => $this->archive->authoritativeRecord($id, $uid, $audience),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to load the workflow record', ['id' => $id]);
        }
    }

    /**
     * GET /api/v1/archive/workflows/by-instance/{instanceId}?audience=
     *
     * The archive of one workflow — what a finished row in My Work links
     * to, without the client having to learn a second id.
     */
    #[NoAdminRequired]
    public function byInstance(int $instanceId, string $audience = WorkflowArchiveAudience::REQUESTING_TEAM): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse([
                'archive'      => $this->archive->getByInstance($instanceId, $uid, $audience),
                'tier'         => $this->tier->tier(),
                'capabilities' => $this->tier->capabilities(),
            ]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to load the archived workflow', ['instanceId' => $instanceId]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Documents on a live workflow — what the archive will later project
    // ──────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/workflows/{id}/attachments
     *
     * The documents of a live workflow, as this caller may see them.
     */
    #[NoAdminRequired]
    public function attachments(int $id): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(['attachments' => $this->documents->listFor($id, $uid)]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to list the workflow documents', ['id' => $id]);
        }
    }

    /**
     * POST /api/v1/workflows/{id}/attachments
     * Body: { "fileId": 123, "visibility": "requester" | "internal", "stepKey": "…" }
     *
     * Attach a document. `visibility` is **required** and has no default:
     * a document whose classification was never stated is not stored. The
     * file is resolved in the caller's own Nextcloud, so attaching grants
     * nobody access to anything.
     */
    #[NoAdminRequired]
    public function attach(int $id, int $fileId = 0, string $visibility = '', string $stepKey = ''): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            return new JSONResponse(
                ['attachment' => $this->documents->attach($id, $uid, $fileId, $visibility, $stepKey !== '' ? $stepKey : null)],
                Http::STATUS_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to attach the document', ['id' => $id]);
        }
    }

    /**
     * DELETE /api/v1/workflows/{id}/attachments/{attachmentId}
     *
     * Detach a document while the workflow is still open. 409 once it has
     * ended: the record is closed, documents included.
     */
    #[NoAdminRequired]
    public function detach(int $id, int $attachmentId): JSONResponse {
        $uid = $this->requireUser();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }
        try {
            $this->documents->remove($attachmentId, $uid);
            return new JSONResponse(['removed' => true]);
        } catch (\Throwable $e) {
            return $this->archiveError($e, 'Failed to remove the document', ['id' => $id, 'attachmentId' => $attachmentId]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    private function archiveError(\Throwable $e, string $fallback, array $context = []): JSONResponse {
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
