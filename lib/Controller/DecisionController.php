<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\DecisionCategoryService;
use OCA\TeamHub\Service\DecisionLinkService;
use OCA\TeamHub\Service\DecisionService;
use OCA\TeamHub\Service\DecisionTaskService;
use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Decisions API controller.
 *
 * Gate enforcement (DESIGN.md §2.40):
 *  - Config endpoints (getConfig, saveConfig) call assertModuleEnabledGlobally().
 *    The per-team flag is the subject of these endpoints, so it must not
 *    be required here.
 *  - Feature endpoints call assertModuleEnabledForTeam() (inside the service
 *    method itself), which checks both the global flag and the per-team flag.
 *
 * Auth model:
 *  - Read endpoints require team membership (level ≥ 1).
 *  - Write endpoints (propose, link, unlink, mark, withdraw) require team
 *    membership; mark/withdraw additionally require either proposer-equality
 *    or admin level (≥ 8). The service enforces these — controllers just
 *    pass actingUserId.
 *
 * Error mapping:
 *  - \RuntimeException      → 404 (module off, decision not found, terminal,
 *                              duplicate)
 *  - \InvalidArgumentException → 400 (validation failures)
 *  - MemberService 'not a member'/'not authorized' → 403
 *  - else                   → 500
 *
 * The service throws \RuntimeException for both gate failures and "not found"
 * because both mean "this resource is not accessible from your scope" — the
 * frontend treats them the same way (route the user back to the team home).
 */
class DecisionController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private DecisionService         $decisionService,
        private DecisionTeamService     $decisionTeamService,
        private DecisionCategoryService $categoryService,
        private DecisionTaskService     $taskService,
        private DecisionLinkService     $linkService,
        private MemberService           $memberService,
        private IUserSession            $userSession,
        private LoggerInterface         $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // =========================================================================
    // Config — Session A endpoints
    // =========================================================================

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getConfig(string $teamId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->decisionTeamService->getConfig($teamId));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'getConfig');
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveConfig(string $teamId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireAdminLevel($teamId);

            $body = $this->request->getParams();
            if (!array_key_exists('decisions_enabled', $body)
                && !array_key_exists('decisions_level_enabled', $body)
                && !array_key_exists('decisions_action_min_level', $body)
            ) {
                return new JSONResponse(['error' => 'At least one config key is required'], Http::STATUS_BAD_REQUEST);
            }

            $data = [];
            if (array_key_exists('decisions_enabled', $body)) {
                $data['decisions_enabled'] = (int)!!$body['decisions_enabled'];
            }
            if (array_key_exists('decisions_level_enabled', $body)) {
                $data['decisions_level_enabled'] = (int)!!$body['decisions_level_enabled'];
            }
            if (array_key_exists('decisions_action_min_level', $body)) {
                $val = (int)$body['decisions_action_min_level'];
                // Clamp to valid NC member levels: 1 (guest), 4 (member), 8 (moderator), 9 (admin)
                if (!in_array($val, [1, 4, 8, 9], true)) {
                    return new JSONResponse(['error' => 'decisions_action_min_level must be 1, 4, 8, or 9'], Http::STATUS_BAD_REQUEST);
                }
                $data['decisions_action_min_level'] = $val;
            }
            return new JSONResponse($this->decisionTeamService->saveConfig($teamId, $data));
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'saveConfig');
        }
    }

    // =========================================================================
    // Feature endpoints — Session B
    // =========================================================================

    /**
     * POST /api/v1/teams/{teamId}/decisions
     *
     * Body:
     *   message_id    (int, required)
     *   impact        (string, required: low|medium|high)
     *   category      (string, optional)
     *   supersedes_id (int, optional)
     *   source_type   (string, optional: message|document|external)
     *   source_ref    (string, optional)
     */
    #[NoAdminRequired]
    public function propose(string $teamId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $body = $this->request->getParams();

            if (!isset($body['message_id'])) {
                return new JSONResponse(['error' => 'message_id is required'], Http::STATUS_BAD_REQUEST);
            }
            if (!isset($body['impact'])) {
                return new JSONResponse(['error' => 'impact is required'], Http::STATUS_BAD_REQUEST);
            }

            $messageId   = (int)$body['message_id'];
            $impact      = (string)$body['impact'];
            $level       = isset($body['level']) && $body['level'] !== '' ? (string)$body['level'] : null;
            $category    = isset($body['category'])    ? (string)$body['category']    : null;
            $supersedes  = isset($body['supersedes_id']) && $body['supersedes_id'] !== '' && $body['supersedes_id'] !== null
                ? (int)$body['supersedes_id'] : null;
            $sourceType  = isset($body['source_type']) ? (string)$body['source_type'] : null;
            $sourceRef   = isset($body['source_ref'])  ? (string)$body['source_ref']  : null;

            if ($messageId <= 0) {
                return new JSONResponse(['error' => 'message_id must be a positive integer'], Http::STATUS_BAD_REQUEST);
            }

            $out = $this->decisionService->propose(
                $teamId,
                $messageId,
                $impact,
                $level,
                $category,
                $supersedes,
                $sourceType,
                $sourceRef,
                $uid,
            );
            return new JSONResponse($out, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'propose');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions
     *
     * Query params (all optional):
     *   status       (csv: proposed,decided,withdrawn)
     *   impact       (csv: low,medium,high)
     *   category     (csv)
     *   proposed_by  (csv of uids)
     *   q            (search string, max 200 chars)
     *   sort         ('recent' default, or 'created')
     *   before       (int cursor)
     *   limit        (int, default 25, max 100)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $teamId): JSONResponse {
        try {
            $params = $this->request->getParams();
            $filters = [
                'status'      => $this->splitCsv($params['status']      ?? null),
                'impact'      => $this->splitCsv($params['impact']      ?? null),
                'category'    => $this->splitCsv($params['category']    ?? null),
                'proposedBy'  => $this->splitCsv($params['proposed_by'] ?? null),
                'q'           => isset($params['q']) ? (string)$params['q'] : null,
            ];
            $sort   = isset($params['sort']) ? (string)$params['sort'] : 'recent';
            $before = isset($params['before']) && $params['before'] !== '' && $params['before'] !== null
                ? (int)$params['before'] : null;
            $limit  = isset($params['limit']) ? (int)$params['limit'] : 25;

            $out = $this->decisionService->list($teamId, $filters, $sort, $before, $limit);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'index');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}
     * Returns the decision with hydrated 'tasks'.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $teamId, int $decisionId): JSONResponse {
        try {
            return new JSONResponse($this->decisionService->get($teamId, $decisionId));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'show');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/categories
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function categories(string $teamId): JSONResponse {
        try {
            return new JSONResponse(['categories' => $this->decisionService->categories($teamId)]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'categories');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/sources
     *
     * Returns the list of files inside .proposals/{decisionId}/ — the
     * canonical proposal .md plus any attachments copied in at finalize
     * time. Used by the Decisions detail panel's Source heading.
     *
     * For legacy decisions finalized before v3.71.2 (flat layout), returns
     * the single .proposals/{decisionId}.md file.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sources(string $teamId, int $decisionId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            return new JSONResponse([
                'items' => $this->decisionService->listSourceFiles($teamId, $decisionId),
            ]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'sources');
        }
    }

    /**
     * GET /api/v1/files/{fileId}/content?download=1
     *
     * v3.71.10 — streams the raw bytes of a proposal source file by id.
     * Used by the in-app read-only viewer; supersedes the previous attempt
     * to fetch via NC's /f/{id} redirect (which returned an HTML shell, not
     * the file content).
     *
     * Authorisation is enforced in the service:
     *  - The user must be able to access the file via their own mount table
     *    (IRootFolder::getUserFolder → getById applies per-user ACLs).
     *  - The file must live inside a .proposals/ subtree, i.e. it's a
     *    proposal source — not arbitrary content lookup by id.
     *
     * When ?download=1, sets Content-Disposition: attachment so browsers
     * save the file. Otherwise serves inline for in-viewer display.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fileContent(int $fileId): DataDisplayResponse {
        try {
            $uid = $this->requireUser();
            $payload = $this->decisionService->getProposalSourceFileContent($fileId, $uid);
            if ($payload === null) {
                return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
            }
            $download = (string)($this->request->getParam('download', '')) !== '';
            $headers = [
                'Content-Type'    => $payload['mime'],
                'Content-Length'  => (string)strlen($payload['content']),
                // Cache for the session; the proposal .md is regenerated on
                // each transition but a short cache is fine for the viewer.
                'Cache-Control'   => 'private, max-age=30',
            ];
            if ($download) {
                // Strip newlines + quotes to prevent header injection via
                // filename; cap length defensively.
                $safeName = str_replace(['"', "\r", "\n"], '', $payload['name']);
                if (strlen($safeName) > 200) {
                    $safeName = substr($safeName, 0, 200);
                }
                $headers['Content-Disposition'] = 'attachment; filename="' . $safeName . '"';
            } else {
                $headers['Content-Disposition'] = 'inline';
            }
            return new DataDisplayResponse(
                $payload['content'],
                Http::STATUS_OK,
                $headers,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DecisionController] fileContent failed', [
                'fileId' => $fileId, 'error' => $e->getMessage(),
            ]);
            return new DataDisplayResponse('', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/finalize
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/finalize
     * Body: { comment_id: int }
     *
     * Session H: was markBest in older versions. The proposer's chosen
     * comment becomes the canonical final wording. Renamed to reflect the
     * new lifecycle (open → finalized).
     */
    #[NoAdminRequired]
    public function finalize(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $body = $this->request->getParams();
            if (!isset($body['comment_id'])) {
                return new JSONResponse(['error' => 'comment_id is required'], Http::STATUS_BAD_REQUEST);
            }
            $commentId = (int)$body['comment_id'];
            if ($commentId <= 0) {
                return new JSONResponse(['error' => 'comment_id must be a positive integer'], Http::STATUS_BAD_REQUEST);
            }
            $out = $this->decisionService->finalize($teamId, $decisionId, $commentId, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'finalize');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/refresh-proposal
     *
     * Session A — used by the compose modal AFTER it registers attachments
     * for a freshly auto-finalized decision, so the .proposals/{id}/ folder
     * picks up the newly-linked files. The initial writeProposalDocument
     * runs inside propose() but at that point no attachments are registered
     * yet (the frontend hasn't called the attachment endpoint).
     *
     * Idempotent. No body required.
     */
    #[NoAdminRequired]
    public function refreshProposal(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $out = $this->decisionService->refreshProposalDocument($teamId, $decisionId, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'refreshProposal');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/withdraw
     * Body: { reason: string }
     */
    #[NoAdminRequired]
    public function withdraw(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $body = $this->request->getParams();
            $reason = isset($body['reason']) ? (string)$body['reason'] : '';
            $out = $this->decisionService->withdraw($teamId, $decisionId, $reason, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'withdraw');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/approve
     * Body: {} — empty
     *
     * Approver-gated (m:n list from Session G). Finalized → approved (terminal).
     */
    #[NoAdminRequired]
    public function approve(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $body = $this->request->getParams();
            // v3.71.3 — approve now captures a mandatory rationale, mirroring deny.
            $reason = isset($body['reason']) ? (string)$body['reason'] : '';
            $out = $this->decisionService->approve($teamId, $decisionId, $uid, $reason);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'Not authorized')
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_NOT_FOUND;
            return new JSONResponse(['error' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'approve');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/deny
     * Body: { reason: string }
     *
     * Approver-gated. Finalized → denied (terminal, permanent per spec Q6).
     */
    #[NoAdminRequired]
    public function deny(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $body = $this->request->getParams();
            $reason = isset($body['reason']) ? (string)$body['reason'] : '';
            $out = $this->decisionService->deny($teamId, $decisionId, $reason, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'Not authorized')
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_NOT_FOUND;
            return new JSONResponse(['error' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deny');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/audit
     *
     * Session J — full audit trail for one decision, oldest first.
     * Any team member can read; controller checks membership.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function audit(string $teamId, int $decisionId): JSONResponse {
        try {
            $this->requireUser();
            $this->memberService->requireMemberLevel($teamId);
            $items = $this->decisionService->listAuditForDecision($teamId, $decisionId);
            return new JSONResponse(['items' => $items]);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Decision not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'audit');
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireUser(): string {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('Not authenticated');
        }
        return $user->getUID();
    }

    /**
     * Parse a CSV query string into an array of trimmed non-empty values.
     */
    private function splitCsv(?string $csv): array {
        if ($csv === null || $csv === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $csv));
        return array_values(array_filter($parts, fn($s) => $s !== ''));
    }

    // =========================================================================
    // Category management — Session G endpoints
    //
    // All four require:
    //   - Decisions module enabled globally (asserted)
    //   - Caller is a member of the team (asserted)
    //   - For mutations: caller is admin-level (level >= 8)
    //
    // The list endpoint is open to all team members because (a) the message
    // composer needs it to populate the NcSelect, and (b) the category names
    // are not sensitive.
    // =========================================================================

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listCategories(string $teamId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse([
                'items' => $this->categoryService->listForTeam($teamId),
            ]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listCategories');
        }
    }

    #[NoAdminRequired]
    public function createCategory(string $teamId, string $name, ?array $approvers = null): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireAdminLevel($teamId);

            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \RuntimeException('Not authenticated');
            }
            $body        = $this->request->getParams();
            $icon        = isset($body['icon'])        ? (string)$body['icon']        : null;
            $description = isset($body['description']) ? (string)$body['description'] : null;

            $created = $this->categoryService->createCategory(
                $teamId,
                $name,
                $user->getUID(),
                $approvers,
                $icon,
                $description,
            );
            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createCategory');
        }
    }

    #[NoAdminRequired]
    public function updateCategory(string $teamId, int $categoryId, ?string $name = null, ?array $approvers = null): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireAdminLevel($teamId);

            $body        = $this->request->getParams();
            $icon        = array_key_exists('icon', $body)        ? ($body['icon'] === '' ? null : (string)$body['icon']) : false;
            $description = array_key_exists('description', $body) ? ($body['description'] === '' ? null : (string)$body['description']) : false;

            $updated = $this->categoryService->updateCategory(
                $teamId,
                $categoryId,
                $name,
                $approvers,
                $icon === false ? false : $icon,
                $description === false ? false : $description,
            );
            return new JSONResponse($updated);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Category not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateCategory');
        }
    }

    #[NoAdminRequired]
    public function deleteCategory(string $teamId, int $categoryId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $this->memberService->requireAdminLevel($teamId);

            $this->categoryService->deleteCategory($teamId, $categoryId);
            return new JSONResponse(['ok' => true]);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Category not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteCategory');
        }
    }

    // =========================================================================
    // Task links — Session B
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/tasks
     * List all task links for a decision.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTasks(string $teamId, int $decisionId): JSONResponse {
        try {
            return new JSONResponse([
                'items' => $this->taskService->listForDecision($teamId, $decisionId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listTasks');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/tasks
     * Body: { task_path: string, label?: string }
     */
    #[NoAdminRequired]
    public function createTask(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            if (empty($body['task_path'])) {
                return new JSONResponse(['error' => 'task_path is required'], Http::STATUS_BAD_REQUEST);
            }
            $taskPath = (string)$body['task_path'];
            $label    = isset($body['label']) && $body['label'] !== '' ? (string)$body['label'] : null;
            $row      = $this->taskService->linkTask($teamId, $decisionId, $taskPath, $label, $uid);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createTask');
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/decisions/{decisionId}/tasks/{taskId}
     */
    #[NoAdminRequired]
    public function deleteTask(string $teamId, int $decisionId, int $taskId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $this->taskService->deleteLink($teamId, $taskId, $uid);
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteTask');
        }
    }


    // =========================================================================
    // Decision ↔ Decision links — Session C
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/links
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listDecisionLinks(string $teamId, int $decisionId): JSONResponse {
        try {
            $this->decisionService->assertModuleEnabledGlobally();
            $items = $this->linkService->listForDecision($teamId, $decisionId);
            return new JSONResponse(['items' => $items]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listDecisionLinks');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/links
     * Body: { target_decision_id: int }
     */
    #[NoAdminRequired]
    public function createDecisionLink(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            if (empty($body['target_decision_id']) || !is_numeric($body['target_decision_id'])) {
                return new JSONResponse(['error' => 'target_decision_id is required'], Http::STATUS_BAD_REQUEST);
            }
            $targetId = (int)$body['target_decision_id'];
            $row      = $this->linkService->createLink($teamId, $decisionId, $targetId, $uid);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createDecisionLink');
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/decisions/{decisionId}/links/{linkId}
     */
    #[NoAdminRequired]
    public function deleteDecisionLink(string $teamId, int $decisionId, int $linkId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $this->linkService->deleteLink($teamId, $linkId, $uid);
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteDecisionLink');
        }
    }

    private function mapError(\Throwable $e, string $context): JSONResponse {
        $this->logger->error(
            '[TeamHub][DecisionController] ' . $context . ' failed',
            ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
        );
        $msg = $e->getMessage();
        if (str_contains($msg, 'not a member') || str_contains($msg, 'not authorized') || str_contains($msg, 'Insufficient')) {
            return new JSONResponse(['error' => $msg], Http::STATUS_FORBIDDEN);
        }
        if (str_contains($msg, 'Not authenticated')) {
            return new JSONResponse(['error' => $msg], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
