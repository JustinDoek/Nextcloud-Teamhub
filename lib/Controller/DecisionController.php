<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Service\DecisionCategoryService;
use OCA\TeamHub\Service\DecisionExternalLinkService;
use OCA\TeamHub\Service\DecisionLinkService;
use OCA\TeamHub\Service\DecisionMeetingService;
use OCA\TeamHub\Service\DecisionReviewService;
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
    use ExceptionResponseTrait;


    public function __construct(
        string $appName,
        IRequest $request,
        private DecisionService         $decisionService,
        private DecisionTeamService     $decisionTeamService,
        private DecisionCategoryService $categoryService,
        private DecisionTaskService     $taskService,
        private DecisionLinkService     $linkService,
        private DecisionMeetingService  $meetingService,
        private DecisionExternalLinkService $extLinkService,
        // v4.11.18 — the review and retirement cycle of an approved decision.
        private DecisionReviewService   $reviewService,
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
            // v4.11.18 — the acting admin is recorded as `created_by` on the
            // categories the service seeds when the module comes on.
            return new JSONResponse(
                $this->decisionTeamService->saveConfig($teamId, $data, $this->requireUser()),
            );
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

            // v4.5.42 — viewer passed for the audience gate on open proposals.
            $out = $this->decisionService->list($teamId, $filters, $sort, $before, $limit, $this->requireUser());
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
            // v4.5.42 — the viewer is passed explicitly so the service can
            // apply the audience gate on a restricted open proposal.
            return new JSONResponse(
                $this->decisionService->get($teamId, $decisionId, $this->requireUser())
            );
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
     * PUT /api/v1/teams/{teamId}/decisions/{decisionId}/proposal
     * Body: { question?: string, body?: string }
     *
     * v4.5.42 — edit an open proposal after feedback. Proposer only, open
     * only. Both fields are optional; omitting one leaves it unchanged.
     */
    #[NoAdminRequired]
    public function updateProposal(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            // Distinguished from empty string: null means "leave alone",
            // '' means "the client sent a blank", which updateProposal
            // refuses for the question and accepts for the body.
            $question = array_key_exists('question', $body) ? (string)$body['question'] : null;
            $text     = array_key_exists('body', $body)     ? (string)$body['body']     : null;

            if ($question === null && $text === null) {
                return new JSONResponse(
                    ['error' => 'Nothing to update — send question, body, or both'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            $out = $this->decisionService->updateProposal($teamId, $decisionId, $question, $text, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'updateProposal');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/finalize-proposal
     *
     * v4.5.42 — finalize an open proposal using its own body as the final
     * wording. The Talk-discussion counterpart to finalize(), which requires
     * a TeamHub comment id that a Talk discussion never produces.
     */
    #[NoAdminRequired]
    public function finalizeProposal(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $out = $this->decisionService->finalizeProposal($teamId, $decisionId, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'finalizeProposal');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/share
     * Body: { mode: 'selected'|'team', userIds?: string[] }
     *
     * v4.5.42 — open a proposal for discussion on a Talk surface. `selected`
     * creates a group conversation with the named people and restricts who can
     * see the proposal while it is open; `team` posts into the team's own
     * conversation and restricts nothing.
     *
     * Returns { decision, share } — `share.ok` is false when Talk could not be
     * reached, which is not a failure of the share itself: the proposal is
     * still open and still editable, it just has no conversation attached.
     */
    #[NoAdminRequired]
    public function share(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $mode = (string)($body['mode'] ?? '');
            $raw  = $body['userIds'] ?? [];
            if (!is_array($raw)) {
                return new JSONResponse(['error' => 'userIds must be an array'], Http::STATUS_BAD_REQUEST);
            }
            // Cast here so the service sees strings; it does the membership
            // filtering, which is the check that actually matters.
            $userIds = array_values(array_map(static fn ($u): string => (string)$u, $raw));

            $out = $this->decisionService->shareProposal($teamId, $decisionId, $mode, $userIds, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            // Logged here as well as mapped: `mapError` records the exception
            // but not what was being attempted, and the share path has three
            // failure surfaces (Talk, audience write, message read) that are
            // indistinguishable from the response alone.
            $this->logger->error('[TeamHub][DecisionController] share failed', [
                'teamId' => $teamId, 'decisionId' => $decisionId,
                'mode' => (string)($this->request->getParams()['mode'] ?? ''),
                'exception' => $e, 'app' => \OCA\TeamHub\AppInfo\Application::APP_ID,
            ]);
            return $this->mapError($e, 'share');
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
     * PUT /api/v1/teams/{teamId}/decisions/{decisionId}/public
     * Body: { public: bool }
     *
     * Publish a decision, or take it back into the team (v4.11.12, Justin
     * 2026-09-30). **Public means readable by every logged-in account on this
     * instance** — instance-wide, never anonymous: no route serving a
     * decision is a `#[PublicPage]` and there is no share token.
     *
     * Gated on the team's own `decisions_action_min_level`, the same gate as
     * *Link decision* and *Link task*, so a team that wants publishing to be
     * an admin's call raises that one setting rather than a second one.
     * RuntimeException is a 403 here, as it is on the link routes: the only
     * thing it reports is that gate.
     */
    #[NoAdminRequired]
    public function setPublic(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            if (!array_key_exists('public', $body)) {
                return new JSONResponse(['error' => 'public is required'], Http::STATUS_BAD_REQUEST);
            }
            $public = filter_var($body['public'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($public === null) {
                return new JSONResponse(['error' => 'public must be a boolean'], Http::STATUS_BAD_REQUEST);
            }
            return new JSONResponse($this->decisionService->setPublic($teamId, $decisionId, $public, $uid));
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'setPublic');
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
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
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
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
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
    // Review and retirement — v4.11.18
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/reviews
     *
     * Every review of a decision, newest first. Any team member can read;
     * the service checks membership and that the id is a decision of this
     * team.
     *
     * No `#[NoCSRFRequired]`: `@nextcloud/axios` sends the token on reads
     * too, so there is nothing to exempt (CLAUDE.md § PHP standards).
     */
    #[NoAdminRequired]
    public function listReviews(string $teamId, int $decisionId): JSONResponse {
        try {
            $this->requireUser();
            $items = $this->reviewService->listForDecision($teamId, $decisionId);
            return new JSONResponse(['items' => $items]);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Decision not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listReviews');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/reviews
     * Body: { motivation: string, proposed_question?: string,
     *         proposed_answer?: string, attachment_file_id?: int,
     *         attachment_name?: string }
     *
     * Any team member may ask the category's approvers to look at an
     * approved decision again. The motivation is required — the approver is
     * being asked to spend their attention.
     *
     * v4.11.30 — the request may carry the wording it is asking for and one
     * file. Both optional, and a request with neither is the 4.11.18 shape:
     * *look at this again*, with nothing proposed. `attachment_file_id` is a
     * file in the **requester's** own Nextcloud; the server copies it into
     * the decision's proposal folder, so a 400 here can mean the file could
     * not be read and a 404 can mean the team has no team folder to put it
     * in.
     *
     * 409 when a review is already open: the client should refresh and show
     * the one that is running rather than offer the button again.
     */
    #[NoAdminRequired]
    public function requestReview(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid        = $this->requireUser();
            $body       = $this->request->getParams();
            $motivation = isset($body['motivation']) ? (string)$body['motivation'] : '';
            // v4.11.30 — what the requester asks the decision to say, and
            // the file it should say it from. All optional: a request that
            // only asks for a look is still a review. Null and not '' for
            // the two wording fields — the service reads null as "nothing
            // was proposed", which is what decides whether the approver gets
            // a one-click Approve.
            $proposedQuestion = isset($body['proposed_question']) ? (string)$body['proposed_question'] : null;
            $proposedAnswer   = isset($body['proposed_answer']) ? (string)$body['proposed_answer'] : null;
            $attachmentFileId = isset($body['attachment_file_id']) && $body['attachment_file_id'] !== '' && $body['attachment_file_id'] !== null
                ? (int)$body['attachment_file_id'] : null;
            $attachmentName   = isset($body['attachment_name']) ? (string)$body['attachment_name'] : null;

            $out = $this->reviewService->request(
                $teamId,
                $decisionId,
                $motivation,
                $uid,
                $proposedQuestion,
                $proposedAnswer,
                $attachmentFileId,
                $attachmentName,
            );
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            // "already open" is a conflict, not a missing resource: the
            // request was well-formed and the state refused it.
            if (str_contains($e->getMessage(), 'already open')) {
                return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
            }
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'requestReview');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/reviews/complete
     * Body: { outcome: 'denied'|'accepted'|'kept'|'archived', reason: string,
     *         question?: string, answer?: string }
     *
     * Approver-gated. `denied` and `kept` leave the decision alone and
     * restart its review clock; `accepted` rewrites its wording in place and
     * restarts the clock; `archived` retires it into the team's archive
     * category. Returns the decision, because that is what the client
     * renders afterwards.
     *
     * v4.11.23 — the reason is **required** (`400` without it), as it is for
     * approve and deny. It was `note?` and optional until then.
     *
     * v4.11.26 — `question` and `answer` carry the new wording for
     * `accepted`, which no longer reopens the decision for redrafting. At
     * least one of them must be present **and** actually differ, or the call
     * is a `400`: an accept that alters nothing is a deny with the wrong
     * label. They are ignored for the other three outcomes rather than
     * refused — a client that sends them with `kept` is confused, not
     * dangerous, and the service writes nothing from them.
     */
    #[NoAdminRequired]
    public function completeReview(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid      = $this->requireUser();
            $body     = $this->request->getParams();
            $outcome  = isset($body['outcome']) ? (string)$body['outcome'] : '';
            $reason   = isset($body['reason']) ? (string)$body['reason'] : '';
            // Null, not '' — the service reads null as "leave this field
            // alone", and an absent key must not blank a decision's wording.
            $question = isset($body['question']) ? (string)$body['question'] : null;
            $answer   = isset($body['answer']) ? (string)$body['answer'] : null;

            $out = $this->reviewService->complete(
                $teamId,
                $decisionId,
                $outcome,
                $reason,
                $uid,
                $question,
                $answer,
            );
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'completeReview');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/archive
     * Body: { reason: string }
     *
     * v4.11.24 — retire an approved decision outright, with no review
     * behind it: the Actions-menu half of *archive*, where the periodic
     * review is the other half. Approver-gated, reason required (`400`),
     * and refused while a review is open (`404` with the reason) — that
     * review is where this gets decided.
     */
    #[NoAdminRequired]
    public function archive(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid    = $this->requireUser();
            $body   = $this->request->getParams();
            $reason = isset($body['reason']) ? (string)$body['reason'] : '';

            $out = $this->reviewService->archive($teamId, $decisionId, $reason, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'archive');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/category
     * Body: { category: string }
     *
     * v4.11.30 — move a decision into the category it should have been
     * proposed in. Gated on being an approver of the category it is in
     * **now**, which is the way round the problem comes: the people who find
     * a decision in their queue that does not belong to them are the ones
     * who need to push it out.
     *
     * 400 for a target that is not one of the team's categories, is the
     * archive (archiving has its own verb and its own mandatory reason), or
     * is the category the decision is already in. 404 for a retired decision
     * and while a review is open — answer the review first. 403 for anyone
     * who is not an approver of the current category.
     */
    #[NoAdminRequired]
    public function moveCategory(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid      = $this->requireUser();
            $body     = $this->request->getParams();
            $category = isset($body['category']) ? (string)$body['category'] : '';

            $out = $this->decisionService->moveToCategory($teamId, $decisionId, $category, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'moveCategory');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/supersede
     * Body: { supersededById: int }
     *
     * Approver-gated. Records that a newer approved decision overrules this
     * one and retires this one into the archive. The button is on the
     * decision being retired; the id in the body is the one replacing it.
     */
    #[NoAdminRequired]
    public function supersede(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();

            $replacement = isset($body['supersededById']) ? (int)$body['supersededById'] : 0;
            if ($replacement <= 0) {
                return new JSONResponse(
                    ['error' => 'supersededById is required'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            $out = $this->reviewService->supersede($teamId, $decisionId, $replacement, $uid);
            return new JSONResponse($out);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'supersede');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/supersede-options?q=
     *
     * The approved decisions of this team that could replace this one — the
     * picker behind *Supersede*. Read by any team member; the act itself is
     * approver-gated.
     *
     * v4.11.19 — searchable, and **at most four**. A picker is for
     * recognising the decision you already have in mind; the same number and
     * the same reasoning as the service desk's *Link a decision* picker.
     */
    #[NoAdminRequired]
    public function supersedeOptions(string $teamId, int $decisionId): JSONResponse {
        try {
            $this->requireUser();
            $term  = (string)($this->request->getParam('q', '') ?? '');
            $items = $this->reviewService->supersedeCandidates($teamId, $decisionId, $term);
            return new JSONResponse(['items' => $items]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'supersedeOptions');
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
            // v4.11.18 — months, 0 = no review. Absent means 0, which is what
            // every category created before this version carries. The range
            // is checked in the service, so a typo gets a sentence rather
            // than being clamped into something nobody asked for.
            $reviewMonths = isset($body['review_period_months'])
                ? (int)$body['review_period_months'] : 0;

            $created = $this->categoryService->createCategory(
                $teamId,
                $name,
                $user->getUID(),
                $approvers,
                $icon,
                $description,
                $reviewMonths,
                // `is_archive` is deliberately not settable over the API: the
                // archive is created with the module and there is exactly one
                // per team. A second one would give `archiveCategory()` two
                // answers.
                false,
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
            // v4.11.18 — null means "leave it", which is why this is keyed on
            // presence and not on a falsy check: 0 is a meaningful value here
            // ("stop reviewing this category's decisions").
            $reviewMonths = array_key_exists('review_period_months', $body)
                ? (int)$body['review_period_months'] : null;

            $updated = $this->categoryService->updateCategory(
                $teamId,
                $categoryId,
                $name,
                $approvers,
                $icon === false ? false : $icon,
                $description === false ? false : $description,
                $reviewMonths,
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
    // Approver meetings on a proposal (v3.74.10)
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/approvers
     *
     * Returns the list of approver UIDs (with display names) for the
     * decision's category. The frontend uses this to pre-fill the
     * "Schedule approver meeting" wizard's attendee step.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listApprovers(string $teamId, int $decisionId): JSONResponse {
        try {
            $result = $this->meetingService->listApproversForDecision($teamId, $decisionId);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listApprovers');
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/meetings
     *
     * Returns all approver meetings scheduled for this proposal.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listMeetings(string $teamId, int $decisionId): JSONResponse {
        try {
            return new JSONResponse([
                'items' => $this->meetingService->listForDecision($teamId, $decisionId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listMeetings');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/meetings
     * Body: { eventUid: string, meetingTitle: string, meetingStart: int (unix ts) }
     *
     * Called by the frontend after the suggest-meeting wizard has already
     * created the calendar event via POST /calendar/events. This records
     * the back-reference so the proposal detail panel can show the
     * "Scheduled meetings" section.
     */
    #[NoAdminRequired]
    public function createMeeting(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            $eventUid     = trim((string)($body['eventUid']     ?? ''));
            $meetingTitle = trim((string)($body['meetingTitle'] ?? ''));
            $meetingStart = isset($body['meetingStart']) ? (int)$body['meetingStart'] : 0;

            $row = $this->meetingService->recordMeeting(
                $teamId, $decisionId, $eventUid, $meetingTitle, $meetingStart, $uid
            );
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createMeeting');
        }
    }


    // =========================================================================
    // External decision links (v3.75.2)
    // =========================================================================

    /**
     * GET /api/v1/teams/{teamId}/decisions/{decisionId}/external-links
     * List external URL links for a decision. Team-member read.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listExternalLinks(string $teamId, int $decisionId): JSONResponse {
        try {
            return new JSONResponse([
                'items' => $this->extLinkService->listForDecision($teamId, $decisionId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'listExternalLinks');
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/decisions/{decisionId}/external-links
     * Body: { url: string (http/https, ≤2048), label?: string (≤255) }
     */
    #[NoAdminRequired]
    public function createExternalLink(string $teamId, int $decisionId): JSONResponse {
        try {
            $uid  = $this->requireUser();
            $body = $this->request->getParams();
            $url   = (string)($body['url']   ?? '');
            $label = (string)($body['label'] ?? '');

            $row = $this->extLinkService->linkExternal($teamId, $decisionId, $url, $label, $uid);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'createExternalLink');
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/decisions/{decisionId}/external-links/{linkId}
     */
    #[NoAdminRequired]
    public function deleteExternalLink(string $teamId, int $decisionId, int $linkId): JSONResponse {
        try {
            $uid = $this->requireUser();
            $this->extLinkService->deleteLink($teamId, $linkId, $uid);
            return new JSONResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->mapError($e, 'deleteExternalLink');
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
        return $this->exceptionResponse($e, ucfirst($context) . ' failed');
    }
}
