<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\FilesService;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\MessageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class MessageController extends Controller {
    use ExceptionResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private MessageService $messageService,
        private MemberService $memberService,
        private FilesService $filesService,
        private LicenseService $licenseService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Returns { pinned: object|null, messages: array, total: int, page: int, limit: int }
     * Query params: page (1-based, default 1), limit (default 5, max 50)
     * SEC: membership enforced — non-members receive 403.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listMessages(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            $page  = max(1, (int)$this->request->getParam('page', 1));
            $limit = min(50, max(1, (int)$this->request->getParam('limit', 5)));

            // v4.5.26 — deep link from "What's new". The caller knows which
            // message it wants to land on, not which page that message is on;
            // the stream's order and page size are this endpoint's business, so
            // it answers rather than making the client walk pages looking.
            // Overrides `page` when it resolves; an unknown or deleted id (or
            // the pinned message, which has no page) falls back to the
            // requested page rather than erroring.
            $around = (int)$this->request->getParam('aroundMessageId', 0);
            if ($around > 0) {
                $position = $this->messageService->findMessageStreamPosition($teamId, $around);
                if ($position >= 0) {
                    $page = intdiv($position, $limit) + 1;
                }
            }
            $offset = ($page - 1) * $limit;

            $this->logger->debug('[TeamHub][MessageController] listMessages', [
                'teamId' => $teamId, 'page' => $page, 'limit' => $limit, 'offset' => $offset,
                'app'    => \OCA\TeamHub\AppInfo\Application::APP_ID,
            ]);

            $result = $this->messageService->getTeamMessages($teamId, $limit, $offset);
            return new JSONResponse([
                'pinned'   => $result['pinned'],
                'messages' => $result['messages'],
                'total'    => $result['total'],
                'page'     => $page,
                'limit'    => $limit,
            ]);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MessageController] listMessages failed', [
                'teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load messages'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function createMessage(
        string $teamId,
        string $subject,
        string $message,
        string $priority = 'normal',
        string $messageType = 'normal',
        ?array $pollOptions = null,
        ?array $decision = null,
        bool $isPublic = false,
    ): JSONResponse {
        try {
            $newMessage = $this->messageService->createMessage(
                $teamId, $subject, $message, $priority, $messageType, $pollOptions, $decision, $isPublic,
            );
            return new JSONResponse($newMessage, Http::STATUS_CREATED);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (ValidationException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            // v4.5.47 — through the trait, so the response carries the same
            // correlation id the log line gets. This block used to log and
            // return a bare message, which is why the ref was missing from the
            // one 500 that actually needed diagnosing.
            return $this->exceptionResponse($e, 'Failed to create message', [
                'teamId' => $teamId, 'messageType' => $messageType,
            ]);
        }
    }

    #[NoAdminRequired]
    public function updateMessage(string $teamId, int $messageId, string $subject, string $message): JSONResponse {
        try {
            $updatedMessage = $this->messageService->updateMessage($teamId, $messageId, $subject, $message);
            return new JSONResponse($updatedMessage);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (ValidationException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update message', [
                'teamId' => $teamId, 'messageId' => $messageId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to update message'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function deleteMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $this->messageService->deleteMessage($teamId, $messageId);
            return new JSONResponse(['success' => true]);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete message', [
                'teamId' => $teamId, 'messageId' => $messageId,
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to delete message'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAggregatedMessages(): JSONResponse {
        try {
            $messages = $this->messageService->getAggregatedMessages();
            return new JSONResponse($messages);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * v4.2.11 — GET /api/v1/messages/public
     *
     * Returns the most recent messages flagged is_public across every team
     * on this NC instance. Any authenticated user may call it; no per-team
     * membership check applies, since a message the poster marked public
     * has effectively opted out of team-scope confidentiality.
     *
     * Query params:
     *   limit           int, 1-100, default 20
     *   offset          int, ≥0,   default 0
     *   excludeTeamIds  comma-separated list of team ids to exclude
     *                   (the personal feed will pass the caller's own
     *                   team memberships so a message doesn't render twice).
     *
     * Response 200:
     *   { messages: [ { id, team_id, team_name, author_id, author_display_name,
     *                   subject, message, ... , isPublic: true, created_at, ... } ],
     *     limit, offset, count }
     */
    /**
     * v4.2.12 — GET /api/v1/messages/feed
     *
     * The personal "What's happening" feed. Combines team messages from
     * every team the caller is a member of with public messages from
     * teams they aren't in. One paginated call, chronological.
     *
     * Query params:
     *   includeTeam    "1"/"0"/true/false (default "1")
     *   includePublic  "1"/"0"/true/false (default "1")
     *   limit          int, 1-100, default 20
     *   offset         int, ≥0,   default 0
     *
     * Response 200:
     *   {
     *     items:   [ { id, team_id, team_name, source: 'team'|'public',
     *                   isPublic, author_id, author_display_name,
     *                   subject, message, created_at, ... } ],
     *     hasMore: bool,
     *     total:   int,       // v4.2.13 — total rows across all pages
     *     limit:   int,
     *     offset:  int
     *   }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPersonalFeed(): JSONResponse {
        try {
            // v4.3.0 — "What’s new" is a licensed feature. Same enforcement
            // ladder as Compliance: `none` / `grace` = active, everything
            // else refuses with 403 + licenseGate so the frontend can
            // surface the "License required" state without treating the
            // response as a generic error. Frontend also hides the sidebar
            // item in the unlicensed case; the server check is the actual
            // boundary (SKILLS §Security standards).
            $level = $this->licenseService->getEnforcementLevel();
            if ($level !== 'none' && $level !== 'grace') {
                return new JSONResponse([
                    'error'            => "What’s new requires an active TeamHub license.",
                    'licenseGate'      => true,
                    'enforcementLevel' => $level,
                ], Http::STATUS_FORBIDDEN);
            }

            $includeTeam   = $this->parseBoolParam('includeTeam',   true);
            $includePublic = $this->parseBoolParam('includePublic', true);
            // v4.2.14 — Talk polls + threads from rooms connected to the
            // user's team memberships. Default on so the feed matches the
            // "shows everything relevant" expectation out of the box; the
            // Feed control widget persists the user's off-preference.
            $includeTalk   = $this->parseBoolParam('includeTalk',   true);
            $limit  = min(100, max(1, (int)$this->request->getParam('limit', 20)));
            $offset = max(0, (int)$this->request->getParam('offset', 0));

            $result = $this->messageService->getPersonalFeed(
                $includeTeam,
                $includePublic,
                $limit,
                $offset,
                $includeTalk,
                $this->parseFeedFilters(),
            );
            return new JSONResponse($result);
        } catch (AccessDeniedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MessageController] getPersonalFeed failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load feed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function parseBoolParam(string $name, bool $default): bool {
        $raw = $this->request->getParam($name, null);
        if ($raw === null) return $default;
        if (is_bool($raw)) return $raw;
        $s = strtolower((string)$raw);
        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    /**
     * v4.5.26 — the Feed control rail's PERIOD / TEAMS / TYPES sections and its
     * System-messages and Mentions-only switches, validated into the shape
     * `MessageService::getPersonalFeed` expects.
     *
     * Validation here is about keeping the query bounded and well-typed, not
     * about access: the team list narrows a WHERE that is already scoped to the
     * viewer's memberships plus public posts, so an unknown or foreign team id
     * can only ever shrink the result (MessageMapper::buildFeedWhere says so at
     * the point it is ANDed on). The type list is checked against the enum the
     * schema actually stores so a hand-crafted value cannot turn into an
     * unbounded IN clause.
     *
     * @return array<string,mixed>
     */
    private function parseFeedFilters(): array {
        // Mirrors MessageMapper::create's $messageType values. An unknown type
        // is dropped rather than rejected — a client one version ahead should
        // degrade to a wider feed, not to an error.
        $allowedTypes = ['normal', 'question', 'poll', 'decision'];

        $from = max(0, (int)$this->request->getParam('from', 0));
        $to   = max(0, (int)$this->request->getParam('to', 0));
        // A reversed range would return nothing with no way to tell why.
        if ($from > 0 && $to > 0 && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from'          => $from,
            'to'            => $to,
            // 100 is the widest the rail can produce (it only offers teams that
            // appear in the feed) and caps the IN clause on a hand-built call.
            'teamIds'       => $this->parseListParam('teamIds', null, 100),
            'messageTypes'  => $this->parseListParam('types', $allowedTypes, 10),
            'includeSystem' => $this->parseBoolParam('includeSystem', true),
            // v4.5.28 — an inclusion switch, defaulting on, like every other
            // row in the rail's Show section. It replaced `mentionsOnly`, a
            // lens that hid everything else; showing only mentions is the
            // Mentions tab's job. Nothing outside TeamHub calls this endpoint,
            // so the old parameter is simply gone rather than aliased.
            'includeMentions' => $this->parseBoolParam('includeMentions', true),
            // v4.5.29 — decisions get their own Show row, and only open ones
            // are listed. See MessageService::applyDecisionFilter().
            'includeDecisions' => $this->parseBoolParam('includeDecisions', true),
        ];
    }

    /**
     * Read a repeatable list parameter, accepting either `?x[]=a&x[]=b` or a
     * comma-separated `?x=a,b`. Trims, drops empties, de-duplicates, optionally
     * restricts to an allow-list, and caps the length.
     *
     * @param string[]|null $allowed null = any non-empty string
     * @return string[]
     */
    private function parseListParam(string $name, ?array $allowed, int $cap): array {
        $raw = $this->request->getParam($name, null);
        if ($raw === null || $raw === '') {
            return [];
        }
        $parts = is_array($raw) ? $raw : explode(',', (string)$raw);

        $out = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $value = trim((string)$part);
            if ($value === '') {
                continue;
            }
            if ($allowed !== null && !in_array($value, $allowed, true)) {
                continue;
            }
            $out[$value] = true;
            if (count($out) >= $cap) {
                break;
            }
        }
        return array_keys($out);
    }

    /**
     * v4.5.26 — read the viewer's saved Feed control defaults.
     *
     * Personal preferences, so no licence gate and no team scope: this returns
     * the caller's own stored view state and nothing else. Same storage shape
     * as My Work's personal preferences (`oc_preferences` under the teamhub app
     * id) rather than a table — it is one small JSON blob per user.
     */
    // No #[NoCSRFRequired]: @nextcloud/axios sends the request token on GETs
    // too, so the framework's CSRF check passes on its own. SKILLS.md § CSRF
    // asks for a documented reason before adding the exemption, and there
    // isn't one here — LayoutController::getDefaultLayout is the same shape
    // and does without it.
    #[NoAdminRequired]
    public function getFeedPreferences(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            return new JSONResponse($this->messageService->getFeedPreferences($user->getUID()));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MessageController] getFeedPreferences failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load feed preferences'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * v4.5.26 — "Save as default" on the Feed control rail.
     *
     * Every field is re-validated in the service before it is stored, so a
     * hand-crafted body cannot put a value in there that the read path would
     * then hand back as trusted.
     */
    #[NoAdminRequired]
    public function saveFeedPreferences(): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }
            $body = $this->request->getParams();
            unset($body['_route']);
            return new JSONResponse($this->messageService->saveFeedPreferences($user->getUID(), $body));
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MessageController] saveFeedPreferences failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to save feed preferences'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listPublicMessages(): JSONResponse {
        try {
            $limit  = min(100, max(1, (int)$this->request->getParam('limit', 20)));
            $offset = max(0, (int)$this->request->getParam('offset', 0));

            $excludeRaw = trim((string)$this->request->getParam('excludeTeamIds', ''));
            $excludeTeamIds = [];
            if ($excludeRaw !== '') {
                // Split, trim, drop empties, cap the list size to keep the
                // NOT IN clause tractable on very large team memberships.
                $excludeTeamIds = array_slice(
                    array_values(array_filter(array_map('trim', explode(',', $excludeRaw)), fn($v) => $v !== '')),
                    0,
                    500,
                );
            }

            $messages = $this->messageService->getPublicMessages($limit, $offset, $excludeTeamIds);
            return new JSONResponse([
                'messages' => $messages,
                'limit'    => $limit,
                'offset'   => $offset,
                'count'    => count($messages),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MessageController] listPublicMessages failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load public messages'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------------
    // Pin
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function pinMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $message = $this->messageService->pinMessage($teamId, $messageId);
            return new JSONResponse($message);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function unpinMessage(string $teamId, int $messageId): JSONResponse {
        try {
            $message = $this->messageService->unpinMessage($teamId, $messageId);
            return new JSONResponse($message);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------------------------
    // Polls
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function votePoll(int $messageId, int $optionIndex): JSONResponse {
        try {
            $results = $this->messageService->votePoll($messageId, $optionIndex);
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getPollResults(int $messageId): JSONResponse {
        try {
            $results = $this->messageService->getPollResults($messageId);
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function closePoll(int $messageId): JSONResponse {
        try {
            $updatedMessage = $this->messageService->closePoll($messageId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Close poll failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // -------------------------------------------------------------------------
    // Questions
    // -------------------------------------------------------------------------

    #[NoAdminRequired]
    public function markQuestionSolved(int $messageId, int $commentId): JSONResponse {
        try {
            $updatedMessage = $this->messageService->markQuestionSolved($messageId, $commentId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Mark question solved failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'commentId' => $commentId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function unmarkQuestionSolved(int $messageId): JSONResponse {
        try {
            $updatedMessage = $this->messageService->unmarkQuestionSolved($messageId);
            return new JSONResponse($updatedMessage);
        } catch (\Exception $e) {
            $this->logger->error('Unmark question solved failed in controller', [
                'exception' => $e->getMessage(),
                'messageId' => $messageId,
                'app'       => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * POST /api/v1/messages/{messageId}/attachments
     *
     * Body JSON: { file_id: int, file_name: string }
     *
     * Registers an uploaded file as an attachment of the given message.
     * Authorization is enforced inside the service (caller must be author).
     * Idempotent on (message_id, file_id).
     */
    #[NoAdminRequired]
    public function registerAttachment(int $messageId, int $file_id, string $file_name): JSONResponse {
        try {
            $row = $this->messageService->registerAttachment($messageId, $file_id, $file_name);
            return new JSONResponse($row, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to register attachment', [
                'messageId' => $messageId, 'file_id' => $file_id,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Message image cache
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/teams/{teamId}/messages/cache-image
     *
     * Copies a file from the requesting user's personal Files into the team
     * folder's hidden image cache (.teamhub-cache). Returns the fileId of the
     * cached copy so the frontend can build a /core/preview URL that is
     * accessible to all team members (the team folder is circle-shared).
     *
     * Body JSON: { teamFolderId: int, sourcePath: string }
     *   teamFolderId — numeric NC fileId of the team folder root
     *   sourcePath   — path inside the user's DAV root, e.g. /Photos/cat.png
     *
     * SEC: membership enforced. sourcePath is resolved via the user's own
     * folder — the user cannot cache a file they do not have access to.
     */
    #[NoAdminRequired]
    public function cacheImage(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);

            $uid = $this->userSession->getUser()?->getUID();
            if ($uid === null) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $body         = $this->request->getParams();
            $teamFolderId = isset($body['teamFolderId']) ? (int) $body['teamFolderId'] : 0;
            $sourcePath   = trim((string) ($body['sourcePath'] ?? ''));

            if ($teamFolderId <= 0 || $sourcePath === '') {
                return new JSONResponse(['error' => 'teamFolderId and sourcePath are required'], Http::STATUS_BAD_REQUEST);
            }

            // Reject obvious path traversal attempts
            if (str_contains($sourcePath, '..')) {
                return new JSONResponse(['error' => 'Invalid sourcePath'], Http::STATUS_BAD_REQUEST);
            }

            $this->logger->debug('[TeamHub][MessageController] cacheImage', [
                'teamId'       => $teamId,
                'teamFolderId' => $teamFolderId,
                'sourcePath'   => $sourcePath,
                'uid'          => $uid,
                'app'          => Application::APP_ID,
            ]);

            $result = $this->filesService->cacheImageInTeamFolder($teamFolderId, $sourcePath, $uid);

            return new JSONResponse([
                'fileId'    => $result['fileId'],
                'cachePath' => $result['cachePath'],
            ]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to cache image', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * DELETE /api/v1/teams/{teamId}/messages/image-cache
     *
     * Deletes all files inside the .teamhub-cache folder in the team folder,
     * without removing the folder itself. Requires team admin level.
     *
     * Body JSON: { teamFolderId: int }
     */
    #[NoAdminRequired]
    public function clearImageCache(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);

            $body         = $this->request->getParams();
            $teamFolderId = isset($body['teamFolderId']) ? (int) $body['teamFolderId'] : 0;

            if ($teamFolderId <= 0) {
                return new JSONResponse(['error' => 'teamFolderId is required'], Http::STATUS_BAD_REQUEST);
            }

            $this->logger->debug('[TeamHub][MessageController] clearImageCache', [
                'teamId'       => $teamId,
                'teamFolderId' => $teamFolderId,
                'app'          => Application::APP_ID,
            ]);

            $deleted = $this->filesService->clearImageCache($teamFolderId);

            return new JSONResponse(['deleted' => $deleted]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to clear image cache', [
                'teamId' => $teamId,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Message settings
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/teams/{teamId}/messages/settings
     * Returns per-team message settings: pinMinLevel and postMinLevel.
     * Accessible by any team member.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getMessageSettings(string $teamId): JSONResponse {
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse($this->messageService->getMessageSettings($teamId));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to load message settings', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * POST /api/v1/teams/{teamId}/messages/settings
     * Saves per-team message settings. Requires team admin level (8).
     * Body: { pinMinLevel: 'member'|'moderator'|'admin', postMinLevel: 'member'|'moderator'|'admin' }
     */
    #[NoAdminRequired]
    public function saveMessageSettings(string $teamId): JSONResponse {
        try {
            $this->memberService->requireAdminLevel($teamId);
            $body    = $this->request->getParams();
            $pin     = trim((string)($body['pinMinLevel']     ?? 'moderator'));
            $post    = trim((string)($body['postMinLevel']    ?? 'member'));
            $link    = trim((string)($body['linkMinLevel']    ?? 'admin'));
            $comment = trim((string)($body['commentMinLevel'] ?? 'member'));
            // v4.2.11 — admin toggle for the Public checkbox on the compose
            // form. Accept common JSON representations (true/false, 1/0,
            // "1"/"0"); anything else is treated as off.
            $allowPublicRaw = $body['allowPublicMessages'] ?? false;
            $allowPublic    = $allowPublicRaw === true
                || $allowPublicRaw === 1
                || $allowPublicRaw === '1'
                || $allowPublicRaw === 'true';
            // v4.5.38 — per-message-type comment switches. Absent means "leave
            // the stored value alone" (null), not "enable everything": a
            // pre-4.5.38 client that PUTs the settings form it knows about must
            // not wipe a policy it never rendered.
            $commentsEnabled = $this->parseCommentsEnabled($body['commentsEnabled'] ?? null);
            $this->messageService->saveMessageSettings($teamId, $pin, $post, $link, $allowPublic, $comment, $commentsEnabled);
            $this->logger->debug('[TeamHub][MessageController] saveMessageSettings', [
                'teamId' => $teamId, 'pin' => $pin, 'post' => $post, 'link' => $link, 'comment' => $comment,
                'allowPublicMessages' => $allowPublic,
                'commentsEnabled' => $commentsEnabled,
                'app'    => Application::APP_ID,
            ]);
            return new JSONResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Failed to save message settings', [
                'teamId' => $teamId,
            ]);
        }
    }

    /**
     * v4.5.38 — normalise the `commentsEnabled` body field into a type => bool
     * map, or null when the caller did not send one.
     *
     * Accepts the same loose truthiness the `allowPublicMessages` toggle has
     * accepted since 4.2.11, because the two arrive from the same form and a
     * JSON `false` and a form-encoded `"0"` are the same intent. Keys that are
     * not commentable types are dropped rather than rejected — the service
     * filters again on read, and a 400 over a stray key would break a caller
     * that merely sent one field too many.
     *
     * @return array<string,bool>|null
     */
    private function parseCommentsEnabled(mixed $raw): ?array {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            // Form-encoded bodies arrive as a JSON string rather than an array.
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (MessageService::COMMENTABLE_TYPES as $type) {
            if (!array_key_exists($type, $raw)) {
                continue;
            }
            $v = $raw[$type];
            $out[$type] = !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === '');
        }
        return $out === [] ? null : $out;
    }
}
