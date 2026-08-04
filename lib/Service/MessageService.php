<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Mentions\MentionParser;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MessageService {
    /**
     * v4.5.26 — "What's new" Feed control defaults, stored per user in
     * `oc_preferences`. One key holding a JSON blob; see getFeedPreferences().
     */
    private const FEED_PREFS_KEY = 'feed_prefs';

    /**
     * PERIOD section of the Feed control rail. Resolved to a from/to pair by
     * the **browser**, not here — 'today' means the viewer's today, and the
     * server's timezone is not theirs. Same reasoning as the My Work snooze
     * presets in 4.5.24; this list exists so a stored preference can be
     * validated, not so the server can compute the range.
     */
    private const FEED_PERIODS = ['all', 'today', 'week', 'month', 'custom'];

    /**
     * Decision statuses that count as "still open" for the feed.
     *
     * **Two names for one state, and both are live.** `DecisionService::STATUSES`
     * declares `open`, but rows written by earlier versions carry `proposed` —
     * `TeamDecisionsView` tests for both wherever it decides whether a proposal
     * can still be acted on (`status === 'open' || status === 'proposed'`), and
     * so must this. v4.5.29 checked only `open` and Justin's open decisions
     * never appeared.
     *
     * Everything else — `finalized` (legacy `decided`), `approved`, `denied`,
     * `withdrawn` — is a record rather than something happening, and belongs in
     * the Decisions tab, not a feed.
     */
    private const DECISION_OPEN_STATUSES = ['open', 'proposed'];

    // The message-type allow-list moved out with the rail's TYPES section in
    // v4.5.29. `MessageController::parseFeedFilters()` still validates the
    // API-only `types` param against its own copy, which is the right home for
    // it — this service no longer reads or stores the value.

    /** Page sizes the feed offers. The controller clamps at 100 regardless. */
    private const FEED_PER_PAGE_OPTIONS = [20, 50, 100];

    /**
     * v4.5.38 — the message types a team admin can switch commenting off for.
     *
     * Mirrors the composer's own type list in `PostMessageForm.vue` and the
     * allow-list `postMessage()` validates against below. A type missing from
     * here is treated as always-commentable, which is the safe direction: a
     * future type nobody remembered to add here keeps working.
     */
    public const COMMENTABLE_TYPES = ['normal', 'poll', 'question', 'decision'];

    /**
     * Types whose comment thread cannot be switched off.
     *
     * A question exists to be answered — its comments *are* the feature, and the
     * answer-marking flow (`markQuestionSolved`) has no meaning without them. The
     * admin UI renders no checkbox for it and this list is the server's copy of
     * that rule, so an API caller cannot disable what the UI won't offer.
     *
     * **`decision` joined it because the switch stopped meaning anything.** Since
     * v4.5.42 a decision is not a type you can pick in the composer — every
     * proposal starts from the Decisions page — so a control sitting in *message*
     * settings governed something nobody could create there. Removing the
     * checkbox alone would have stranded any team that had already unticked it:
     * a stored `false` with no control left to clear it, which is the trap
     * v4.5.29 and v4.5.31 both hit. Listing it here is what makes the stored
     * value inert — `getCommentDisabledTypes()` filters this list on **read** as
     * well as write, precisely so a type becoming always-on retroactively frees
     * the threads an older setting was hiding.
     */
    public const COMMENTS_ALWAYS_ON_TYPES = ['question', 'decision'];

    private MessageMapper $messageMapper;
    private IUserSession $userSession;
    private $circlesManager;
    private INotificationManager $notificationManager;
    private IAppManager $appManager;
    private IMailer $mailer;
    private IUserManager $userManager;
    private ContainerInterface $container;
    private LoggerInterface $logger;
    private IDBConnection $db;
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private MemberService $memberService;
    private DecisionService $decisionService;
    private \OCA\TeamHub\Db\MessageAttachmentMapper $attachmentMapper;
    private TalkService $talkService;
    public function __construct(
        MessageMapper $messageMapper,
        IUserSession $userSession,
        INotificationManager $notificationManager,
        IAppManager $appManager,
        IMailer $mailer,
        IUserManager $userManager,
        ContainerInterface $container,
        LoggerInterface $logger,
        IDBConnection $db,
        IConfig $config,
        IURLGenerator $urlGenerator,
        MemberService $memberService,
        DecisionService $decisionService,
        \OCA\TeamHub\Db\MessageAttachmentMapper $attachmentMapper,
        TalkService $talkService,
    ) {
        $this->messageMapper = $messageMapper;
        $this->userSession = $userSession;
        $this->notificationManager = $notificationManager;
        $this->appManager = $appManager;
        $this->mailer = $mailer;
        $this->userManager = $userManager;
        $this->container = $container;
        $this->circlesManager = null;
        $this->logger = $logger;
        $this->db = $db;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->memberService = $memberService;
        $this->decisionService = $decisionService;
        $this->attachmentMapper = $attachmentMapper;
        $this->talkService = $talkService;
    }

    private function getCirclesManager() {
        if ($this->circlesManager === null) {
            if (!$this->appManager->isEnabledForUser('circles')) {
                throw new \Exception('Nextcloud Teams (Circles) app is not enabled.');
            }
            $this->circlesManager = $this->container->get(\OCA\Circles\CirclesManager::class);
        }
        return $this->circlesManager;
    }

    /**
     * Get messages for a team. Returns ['pinned' => array|null, 'messages' => array, 'total' => int].
     * total is the count of non-pinned messages, used for pagination.
     */
    /**
     * v4.5.26 — 0-based position of a message in its team's stream, or -1.
     *
     * Thin pass-through so `MessageController::listMessages` can turn a
     * "land on this message" request into a page number. Membership is checked
     * by the controller before this is reached; the mapper scopes the lookup to
     * the team as well, so a message id from another team returns -1 rather
     * than leaking that it exists.
     */
    public function findMessageStreamPosition(string $teamId, int $messageId): int {
        if ($messageId <= 0) {
            return -1;
        }
        try {
            return $this->messageMapper->findStreamPosition($teamId, $messageId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageService] findMessageStreamPosition failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return -1;
        }
    }

    public function getTeamMessages(string $teamId, int $limit = 50, int $offset = 0): array {
        $pinned = $this->messageMapper->findPinnedByTeamId($teamId);
        $messages = $this->messageMapper->findByTeamId($teamId, $limit, $offset);

        // Hydrate decisions onto decision-typed messages in a single batch.
        // Module-off teams have no decision rows so the batch comes back
        // empty — no per-team gate check needed here.
        $messageIds = [];
        foreach ($messages as $m) {
            if (($m['messageType'] ?? '') === 'decision') {
                $messageIds[] = (int)$m['id'];
            }
        }
        if ($pinned && ($pinned['messageType'] ?? '') === 'decision') {
            $messageIds[] = (int)$pinned['id'];
        }
        $decisions = $this->decisionService->hydrateForMessages($messageIds);

        // v4.5.42 — the stream is the second place a decision is rendered, so
        // it needs the same audience rule the feed got. A proposal shared with
        // a selected group is dropped from the stream entirely rather than
        // being shown unstamped: the message body IS the proposal text, so
        // leaving the row and removing only the decision payload would still
        // disclose it.
        $viewerUid = $this->userSession->getUser()?->getUID() ?? '';
        $messages  = array_values(array_filter(
            $messages,
            fn (array $m): bool => $this->streamRowVisible($m, $decisions, $viewerUid),
        ));
        if ($pinned !== null && !$this->streamRowVisible($pinned, $decisions, $viewerUid)) {
            $pinned = null;
        }

        foreach ($messages as &$m) {
            $mid = (int)$m['id'];
            if (isset($decisions[$mid])) {
                $m['decision'] = $decisions[$mid];
            }
        }
        unset($m);
        if ($pinned && isset($decisions[(int)$pinned['id']])) {
            $pinned['decision'] = $decisions[(int)$pinned['id']];
        }

        // v4.0.0 — resolve author display names in one pass so the frontend
        // shows real names in message cards instead of raw account UIDs.
        // IUserManager's in-process cache dedupes repeat UIDs; missing or
        // deleted users fall back to the raw UID. Same pattern as
        // TimelineService::resolveDisplayNames.
        $uids = [];
        foreach ($messages as $m) {
            if (!empty($m['author_id'])) {
                $uids[$m['author_id']] = true;
            }
        }
        if ($pinned && !empty($pinned['author_id'])) {
            $uids[$pinned['author_id']] = true;
        }
        $nameMap = [];
        foreach (array_keys($uids) as $uid) {
            try {
                $u = $this->userManager->get($uid);
                $nameMap[$uid] = $u ? (string)$u->getDisplayName() : $uid;
            } catch (\Throwable) {
                $nameMap[$uid] = $uid;
            }
        }
        foreach ($messages as &$m) {
            $m['author_display_name'] = $nameMap[$m['author_id'] ?? ''] ?? ($m['author_id'] ?? '');
        }
        unset($m);
        if ($pinned) {
            $pinned['author_display_name'] = $nameMap[$pinned['author_id'] ?? ''] ?? ($pinned['author_id'] ?? '');
        }

        return [
            'pinned'   => $pinned,
            'messages' => $messages,
            'total'    => $this->messageMapper->countByTeamId($teamId),
        ];
    }

    /**
     * Create a new message with priority, poll, question, and decision support.
     *
     * For messageType='decision', also creates a row in teamhub_decisions in
     * the same transaction. If the decision insert fails, the message insert
     * is rolled back so we never end up with an "orphan" decision-typed message
     * that has no decision row.
     *
     * @param array{impact?:string, category?:?string, supersedesId?:?int, sourceType?:?string, sourceRef?:?string} $decisionData
     *        Required when messageType='decision'. 'impact' is mandatory; all others are optional.
     */
    public function createMessage(
        string $teamId,
        string $subject,
        string $message,
        string $priority = 'normal',
        string $messageType = 'normal',
        ?array $pollOptions = null,
        ?array $decisionData = null,
        bool $isPublic = false,
    ): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }

        if (!in_array($priority, ['normal', 'priority'])) {
            $priority = 'normal';
        }

        if (!in_array($messageType, ['normal', 'poll', 'question', 'decision'])) {
            $messageType = 'normal';
        }

        // Public visibility is admin-gated per-team. If the team's toggle is
        // off, force-strip is_public regardless of what the client sent — the
        // frontend hides the checkbox but the service layer is the security
        // boundary (SKILLS §Security standards, "The frontend is not a
        // security boundary"). Only 'normal' messages can be public; polls,
        // questions and decisions are always team-scoped.
        if ($isPublic && ($messageType !== 'normal' || !$this->getAllowPublicMessages($teamId))) {
            $isPublic = false;
        }

        // Decision-type guard: if the caller asked for a decision but the
        // module isn't active for this team, refuse rather than silently
        // downgrade to 'normal'. The frontend should never get here when
        // the gate is off, but be defensive.
        if ($messageType === 'decision' && !$this->decisionService->isModuleActiveForTeam($teamId)) {
            throw new \Exception('Decisions module is not enabled for this team');
        }

        // Check whether the user meets the team's minimum post level.
        $postRequired = $this->getPostMinLevel($teamId);
        if ($postRequired > 1) {
            $callerLevel = $this->getMemberLevel($teamId, $user->getUID());
            if ($callerLevel < $postRequired) {
                throw new \Exception('Insufficient permissions to post messages in this team');
            }
        }

        try {
            // Resolve team name via direct DB query — avoids probeCircles() which
            // silently drops teams with non-zero config bitmasks (architectural decision #1).
            $teamName = $this->getTeamNameById($teamId);
            if ($teamName === null) {
                throw new \Exception('Team not found');
            }

            // Verify membership via direct DB — avoids getCircle() which fails on
            // non-zero config bitmasks and on PostgreSQL with Circles API issues.
            $accessLevel = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
            if ($accessLevel < 1) {
                if (!$this->memberService->isEffectiveMember($teamId, $user->getUID(), $this->db)) {
                    throw new \Exception('Team not found or access denied');
                }
            }

            // ── Transactional create for decision-typed messages ──────────────
            // The decision row must exist iff the message row exists.
            // If DecisionService::propose throws (e.g. invalid impact, bad
            // supersedes target), roll back the message row so the user can
            // retry without a leftover orphan.
            if ($messageType === 'decision') {
                $this->db->beginTransaction();
                try {
                    $messageData = $this->messageMapper->create(
                        $teamId, $user->getUID(), $subject, $message, $priority, $messageType, $pollOptions, $isPublic
                    );
                    // Required field check happens inside propose(), but we
                    // pre-validate impact so we fail fast.
                    $impact = isset($decisionData['impact']) ? (string)$decisionData['impact'] : '';
                    if ($impact === '') {
                        throw new \InvalidArgumentException('impact is required for decision-type messages');
                    }
                    $decisionRow = $this->decisionService->propose(
                        $teamId,
                        (int)$messageData['id'],
                        $impact,
                        // v3.72.1 — level field. Optional; defaults to
                        // 'operational' inside propose() when null/empty.
                        // The frontend always sends a value (PostMessageForm
                        // initialises decisionLevel = 'operational'), but
                        // legacy/external callers may omit it.
                        isset($decisionData['level']) && $decisionData['level'] !== ''
                            ? (string)$decisionData['level'] : null,
                        isset($decisionData['category']) ? (string)$decisionData['category'] : null,
                        isset($decisionData['supersedesId']) && $decisionData['supersedesId'] !== '' && $decisionData['supersedesId'] !== null
                            ? (int)$decisionData['supersedesId'] : null,
                        isset($decisionData['sourceType']) ? (string)$decisionData['sourceType'] : null,
                        isset($decisionData['sourceRef'])  ? (string)$decisionData['sourceRef']  : null,
                        $user->getUID(),
                        // Session A: compose-modal proposals carry autoFinalize=true
                        // so they bypass the open/discussion phase and go straight
                        // to 'finalized'. Inline stream proposals remain 'open'.
                        !empty($decisionData['autoFinalize']),
                        // v3.97.5 — optional milestone linkage for Advanced
                        // project teams. propose() validates that the milestone
                        // exists in this team and that the team is Advanced;
                        // non-Advanced teams passing this get a 400.
                        isset($decisionData['milestoneId']) && $decisionData['milestoneId'] !== '' && $decisionData['milestoneId'] !== null
                            ? (int)$decisionData['milestoneId'] : null,
                    );
                    $this->db->commit();
                    // Attach the decision payload so the frontend can render
                    // immediately without a follow-up GET.
                    $messageData['decision'] = $decisionRow;
                } catch (\Throwable $e) {
                    try { $this->db->rollBack(); } catch (\Throwable) {}
                    throw $e;
                }
            } else {
                $messageData = $this->messageMapper->create(
                    $teamId, $user->getUID(), $subject, $message, $priority, $messageType, $pollOptions, $isPublic
                );
            }

            // Notifications are best-effort — the message row is already committed.
            // v4.0.5 — the notification path used to fail the whole POST with 400
            // when a member had a numeric UID because Notification::setUser() is
            // strict-string and PHP array-key coercion made it int. We now catch
            // Throwable per-recipient and around the whole notification block so
            // no notification-side error can retroactively fail a message that
            // was already stored.
            try {
                $memberUids = $this->getTeamMemberUids($teamId);
                $this->sendNotificationsWithName($teamId, $messageData['id'], $subject, $user->getDisplayName(), $teamName, $memberUids);
                $this->sendMentionNotifications($teamId, $messageData['id'], $message, $user, $memberUids);
                if ($priority === 'priority') {
                    $this->sendPriorityEmailsWithName($subject, $message, $user->getDisplayName(), $teamName, $memberUids);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MessageService] notification dispatch failed after message committed', [
                    'teamId' => $teamId, 'messageId' => $messageData['id'] ?? null,
                    'exception' => $e, 'app' => Application::APP_ID,
                ]);
            }

            // v4.0.0 — include the author's display name so MessageStream
            // renders the fresh post with the same author label the listing
            // endpoint returns (avoids a flash of the raw UID before the
            // next page refresh).
            $messageData['author_display_name'] = $user->getDisplayName();

            return $messageData;
        } catch (\Exception $e) {
            $this->logger->error('Error creating message - ', ['exception' => $e, 'app' => Application::APP_ID]);
            throw new \Exception('Failed to create message: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing message
     */
    public function updateMessage(string $teamId, int $messageId, string $subject, string $message): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        $existing = $this->messageMapper->find($messageId);
        // M-1 — enforce that the route's teamId matches the message's team.
        // Without this check, the URL teamId is trusted for nothing today
        // but any future refactor that starts trusting it would create a
        // team-scope mismatch. Cheap defence-in-depth.
        if ((string)($existing['team_id'] ?? '') !== $teamId) {
            throw new NotFoundException('Message not found in this team');
        }
        if ($existing['author_id'] !== $user->getUID()) {
            throw new AccessDeniedException('Only the author can edit this message');
        }
        $updated = $this->messageMapper->update($messageId, $subject, $message);

        // Re-send mention notifications for the updated body.
        // We don't re-notify the whole team — only newly mentioned users.
        $teamId = $existing['team_id'];
        try {
            $memberUids = $this->getTeamMemberUids($teamId);
            $this->sendMentionNotifications($teamId, $messageId, $message, $user, $memberUids);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageService] Could not send mention notifications on update', [
                'messageId' => $messageId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }

        return $updated;
    }

    /**
     * Delete a message
     */
    public function deleteMessage(string $teamId, int $messageId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        $existing = $this->messageMapper->find($messageId);
        if ((string)($existing['team_id'] ?? '') !== $teamId) {
            throw new NotFoundException('Message not found in this team');
        }

        // Author can always delete their own message.
        // Team admin/owner can delete any message (moderation).
        if ($existing['author_id'] !== $user->getUID()) {
            $this->memberService->requireAdminLevel($teamId);
        }

        $this->messageMapper->delete($messageId);
    }

    /**
     * Get aggregated messages
     */
    public function getAggregatedMessages(): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        try {
            $circlesManager = $this->getCirclesManager();
            $federatedUser = $circlesManager->getFederatedUser($user->getUID(), 1);
            $circlesManager->startSession($federatedUser);
            $probe = $circlesManager->probeCircles();
            $circles = is_array($probe) ? $probe : $probe->getCircles();
            $teamIds = [];
            foreach ($circles as $circle) {
                $teamIds[] = $circle->getSingleId();
            }
            if (empty($teamIds)) {
                return [];
            }
            return $this->messageMapper->findAggregated($teamIds, 10);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load aggregated messages - ', ['exception' => $e, 'app' => Application::APP_ID]);
            return [];
        }
    }

    /**
     * Resolve a team display name from circles_circle directly (avoids probeCircles).
     */
    private function getTeamNameById(string $teamId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('display_name')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (string)$row['display_name'] : null;
    }

    /**
     * Get the Circles member level integer for a user in a team (0 = not a member).
     * Levels: 1 = member, 4 = moderator, 8 = admin, 9 = owner.
     *
     * **v4.5.47 — an indirect member is a member, level 1.**
     *
     * `circles_member` only has a row for someone who joined a team *directly*.
     * Anyone who is in it through a group or a nested team has no row, so this
     * returned 0 for them — and 0 does not mean "ordinary member", it means
     * "not in this team". Every gate built on this method therefore refused
     * them: posting, pinning, linking, commenting.
     *
     * The symptom Justin hit was `POST /messages` → "Insufficient permissions
     * to post messages in this team" on a team whose post level is above
     * member, which surfaced as a 500 when creating a decision proposal. The
     * frontend had the rule right all along — `canPost` in the Vuex store
     * comments "Indirect members (via group/team) have no direct level row —
     * default to 1" — and the backend, which is the boundary that matters,
     * did not.
     *
     * Fixed here rather than at the six call sites so the definition of
     * "member level" has one home. Same two-step `MemberService` already uses
     * a few hundred lines up in this file, and the same one `requireMemberLevel`
     * has always used.
     *
     * Level 1 and not more: indirect membership confers no moderator or admin
     * rights. Those gates keep requiring a direct row with the level on it,
     * which is what `requireAdminLevel` enforces.
     */
    private function getMemberLevel(string $teamId, string $userId): int {
        $direct = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId);
        if ($direct > 0) {
            return $direct;
        }

        return $this->memberService->isEffectiveMember($teamId, $userId, $this->db) ? 1 : 0;
    }

    /**
     * Parse @userId mentions from a message body and send a targeted
     * `message_mention` notification to each mentioned user who is a team member.
     * The author is never notified of their own mentions.
     */
    /**
     * Get all effective member UIDs for a team directly from DB.
     * Replaces $circle->getMembers() to avoid the Circles API entirely.
     * Returns UIDs of user_type=1 (direct) members with status=Member,
     * plus UIDs resolved from group/circle memberships via circles_membership.
     *
     * @return string[] list of NC user IDs
     */
    private function getTeamMemberUids(string $teamId): array {
        // v4.0.5 — dedup by value, not by array-key. PHP silently coerces any
        // string array-key that matches ^-?[1-9][0-9]*$ into an int, so UIDs
        // like "1724022" (common on LDAP/SAML instances that use the numeric
        // subject as the internal username) came back as ints and blew up
        // Notification::setUser() (strict string type) — the whole message
        // POST returned 400 even though the message had already been written
        // to the DB. Building a list of stringified values and running
        // array_unique on it keeps every UID a string end-to-end.
        $uids = [];

        // Direct members (user_type=1, status=Member)
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id',  $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status',    $qb->createNamedParameter('Member')))
            ->executeQuery();
        while ($row = $res->fetch()) {
            if (!empty($row['user_id'])) {
                $uids[] = (string)$row['user_id'];
            }
        }
        $res->closeCursor();

        // Effective members via circles_membership (group/sub-team members).
        // single_id maps back to the user's personal circle; resolve to uid via
        // circles_member where user_type=1 and level=9 (owner of personal circle).
        try {
            $msQb = $this->db->getQueryBuilder();
            $msRes = $msQb->select('cm.user_id')
                ->from('circles_membership', 'ms')
                ->innerJoin('ms', 'circles_member', 'cm',
                    $msQb->expr()->andX(
                        $msQb->expr()->eq('cm.single_id', 'ms.single_id'),
                        $msQb->expr()->eq('cm.user_type', $msQb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                        $msQb->expr()->eq('cm.level',     $msQb->createNamedParameter(9, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    )
                )
                ->where($msQb->expr()->eq('ms.circle_id', $msQb->createNamedParameter($teamId)))
                ->executeQuery();
            while ($row = $msRes->fetch()) {
                if (!empty($row['user_id'])) {
                    $uids[] = (string)$row['user_id'];
                }
            }
            $msRes->closeCursor();
        } catch (\Throwable $e) {
            // circles_membership may not exist on older Circles versions — non-fatal
            $this->logger->debug('[TeamHub][MessageService] getTeamMemberUids: circles_membership lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return array_values(array_unique($uids));
    }

    private function sendMentionNotifications(string $teamId, int $messageId, string $body, \OCP\IUser $author, array $memberUids): void {
        try {
            // v4.5.26 — was `/@([a-zA-Z0-9._-]+)/` inline here, which never
            // matched a uid containing '@'. On an instance where uids are
            // e-mail addresses that is *every* uid, so mention notifications
            // had never fired at all. Shared with the feed's Mentions filter so
            // the two can only ever agree. See parseMentionCandidates().
            $mentionedIds = $this->parseMentionCandidates($body);
            if (empty($mentionedIds)) {
                return;
            }

            // v4.0.5 — array_flip on a list that happens to contain int-typed
            // UIDs produces int keys that don't match the string $mentionedId
            // in a strict isset lookup. Force the flip target to strings.
            //
            // v4.5.27 — keyed on the lower-cased uid, mapping back to the real
            // one. The candidate comes out of the message body and the member
            // uid out of Circles; those need not agree on case, and notifying
            // the wrong case is the same as notifying nobody.
            $memberSet = [];
            foreach ($memberUids as $memberUid) {
                $memberSet[mb_strtolower((string)$memberUid)] = (string)$memberUid;
            }
            $link = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);

            $notified = [];
            foreach ($mentionedIds as $candidate) {
                // Resolve the token to the member's real uid — the body may
                // spell it in a different case, and NC needs the canonical one.
                $mentionedId = $memberSet[mb_strtolower((string)$candidate)] ?? null;
                if ($mentionedId === null || $mentionedId === $author->getUID()) {
                    continue;
                }
                // parseMentionCandidates offers several readings of one token
                // ('jdoek.' and 'jdoek'), so the same person can resolve twice.
                if (isset($notified[$mentionedId])) {
                    continue;
                }
                $notified[$mentionedId] = true;
                try {
                    $notification = $this->notificationManager->createNotification();
                    $notification->setApp('teamhub')
                        ->setUser($mentionedId)
                        ->setDateTime(new \DateTime())
                        ->setObject('message', (string)$messageId)
                        ->setSubject('message_mention', [
                            'author'   => $author->getDisplayName(),
                            'authorId' => $author->getUID(),
                            'teamId'   => $teamId,
                        ])
                        ->setLink($link);
                    $this->notificationManager->notify($notification);
                    $this->logger->debug('[TeamHub][MessageService] Sent mention notification', [
                        'to' => $mentionedId, 'messageId' => $messageId, 'app' => Application::APP_ID,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('[TeamHub][MessageService] Failed to send mention notification', [
                        'to' => $mentionedId, 'exception' => $e, 'app' => Application::APP_ID,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageService] sendMentionNotifications failed', [
                'messageId' => $messageId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Send in-app notifications to all members.
     * Uses a pre-resolved $teamName string so we never need probeCircles().
     */
    /**
     * Send in-app notifications to all members.
     * Uses pre-resolved $memberUids — no Circles API needed.
     */
    private function sendNotificationsWithName(string $teamId, int $messageId, string $subject, string $authorName, string $teamName, array $memberUids): void {
        try {
            $currentUser = $this->userSession->getUser();
            $link = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);

            foreach ($memberUids as $userId) {
                try {
                    // v4.0.5 — defensive cast. getTeamMemberUids now returns
                    // strings, but any future caller could feed us a raw DB
                    // list where numeric UIDs still come back as ints. One
                    // Throwable-catching pass here means a bad element skips
                    // its notification instead of failing the whole POST.
                    $userId = (string)$userId;
                    if ($userId === $currentUser->getUID()) continue;

                    $notification = $this->notificationManager->createNotification();
                    $notification->setApp('teamhub')
                        ->setUser($userId)
                        ->setDateTime(new \DateTime())
                        ->setObject('message', (string)$messageId)
                        ->setSubject('new_message', [
                            'author'   => $authorName,
                            'authorId' => $currentUser->getUID(),
                            'subject'  => $subject,
                            'team'     => $teamName,
                            'teamId'   => $teamId,
                        ])
                        ->setLink($link);
                    $this->notificationManager->notify($notification);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to notify member - ', ['exception' => $e, 'app' => Application::APP_ID]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send notifications - ', ['exception' => $e, 'app' => Application::APP_ID]);
        }
    }

    /**
     * Send priority emails to all members.
     * Uses pre-resolved $memberUids — no Circles API needed.
     */
    private function sendPriorityEmailsWithName(string $subject, string $message, string $authorName, string $teamName, array $memberUids): void {
        try {
            $currentUser = $this->userSession->getUser();

            foreach ($memberUids as $userId) {
                try {
                    // v4.0.5 — same defensive cast as sendNotificationsWithName.
                    $userId = (string)$userId;
                    if ($userId === $currentUser->getUID()) continue;

                    $ncUser = $this->userManager->get($userId);
                    if (!$ncUser) continue;

                    $email = $ncUser->getEMailAddress();
                    if (!$email) continue;

                    $mail = $this->mailer->createMessage();
                    $mail->setSubject('[TeamHub] ' . $subject);
                    $mail->setTo([$email => $ncUser->getDisplayName()]);
                    $mail->setPlainBody(
                        "New priority message from {$authorName} in team {$teamName}:\n\n" .
                        "Subject: {$subject}\n\n" .
                        $message
                    );
                    $mail->setHtmlBody(
                        "<p><strong>New priority message from {$authorName}</strong><br>" .
                        "Team: " . htmlspecialchars($teamName) . "</p>" .
                        "<h3>" . htmlspecialchars($subject) . "</h3>" .
                        "<p>" . nl2br(htmlspecialchars($message)) . "</p>"
                    );
                    $this->mailer->send($mail);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send priority email - ', ['exception' => $e, 'app' => Application::APP_ID]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send priority emails - ', ['exception' => $e, 'app' => Application::APP_ID]);
        }
    }

    public function pinMessage(string $teamId, int $messageId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }

        // Verify the message belongs to this team
        $existing = $this->messageMapper->find($messageId);
        if ($existing['team_id'] !== $teamId) {
            throw new \Exception('Message does not belong to this team');
        }

        // Check caller's member level against the per-team (or global) threshold
        $requiredLevel = $this->getPinMinLevel($teamId);
        $callerLevel = $this->getMemberLevel($teamId, $user->getUID());
        if ($callerLevel < $requiredLevel) {
            throw new \Exception('Insufficient permissions to pin messages');
        }

        // Unpin any existing pin, then pin the new one
        $this->messageMapper->unpinAllForTeam($teamId);
        return $this->messageMapper->pin($messageId);
    }

    /**
     * Unpin a message. Same level requirement as pinning.
     */
    public function unpinMessage(string $teamId, int $messageId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }

        $existing = $this->messageMapper->find($messageId);
        if ($existing['team_id'] !== $teamId) {
            throw new \Exception('Message does not belong to this team');
        }

        $requiredLevel = $this->getPinMinLevel($teamId);
        $callerLevel = $this->getMemberLevel($teamId, $user->getUID());
        if ($callerLevel < $requiredLevel) {
            throw new \Exception('Insufficient permissions to unpin messages');
        }

        return $this->messageMapper->unpin($messageId);
    }

    /**
     * Return the pinned message for a team, or null.
     */
    public function getPinnedMessage(string $teamId): ?array {
        return $this->messageMapper->findPinnedByTeamId($teamId);
    }

    /**
     * Return the minimum Circles level required to pin for a specific team.
     * Reads per-team key first, falls back to global app-level setting.
     * Levels: member=1, moderator=4, admin=8.
     */
    private function getPinMinLevel(string $teamId = ''): int {
        $setting = $teamId
            ? $this->config->getAppValue(Application::APP_ID, 'pinMinLevel_' . $teamId, '')
            : '';
        if ($setting === '') {
            $setting = $this->config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator');
        }
        return match($setting) {
            'member' => 1,
            'admin'  => 8,
            default  => 4,
        };
    }

    /**
     * Return the minimum Circles level required to post messages for a team.
     * Default: member (1) — everyone can post unless overridden per team.
     */
    private function getPostMinLevel(string $teamId): int {
        $setting = $this->config->getAppValue(Application::APP_ID, 'postMinLevel_' . $teamId, 'member');
        return match($setting) {
            'moderator' => 4,
            'admin'     => 8,
            default     => 1,
        };
    }

    /**
     * Return message settings for a team (pin level + post level) as strings.
     * v4.2.11 — also returns allowPublicMessages (bool) so the frontend can
     * decide whether to render the Public checkbox in the compose form.
     * v4.3.1 — also returns commentMinLevel: the per-team floor for who can
     * post comments on a message. Default 'member' — any team member.
     */
    public function getMessageSettings(string $teamId): array {
        $pinSetting = $this->config->getAppValue(Application::APP_ID, 'pinMinLevel_' . $teamId, '');
        if ($pinSetting === '') {
            $pinSetting = $this->config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator');
        }
        $postSetting    = $this->config->getAppValue(Application::APP_ID, 'postMinLevel_'    . $teamId, 'member');
        $linkSetting    = $this->config->getAppValue(Application::APP_ID, 'linkMinLevel_'    . $teamId, 'admin');
        $commentSetting = $this->config->getAppValue(Application::APP_ID, 'commentMinLevel_' . $teamId, 'member');
        return [
            'pinMinLevel'         => $pinSetting,
            'postMinLevel'        => $postSetting,
            'linkMinLevel'        => $linkSetting,
            'commentMinLevel'     => $commentSetting,
            'commentsEnabled'     => $this->getCommentsEnabledMap($teamId),
            'allowPublicMessages' => $this->getAllowPublicMessages($teamId),
        ];
    }

    /**
     * Save per-team message settings.
     * Accepts pinMinLevel, postMinLevel, linkMinLevel, and commentMinLevel,
     * all as strings: 'member'|'moderator'|'admin'.
     * v4.2.11 — also accepts allowPublicMessages (bool) — admin-only toggle
     * for whether the compose form exposes the Public checkbox.
     * v4.3.1 — added commentMinLevel to gate CommentController::createComment.
     * v4.5.38 — added $commentsEnabled: a type => bool map switching the whole
     * comment thread off per message type. Null leaves the stored value alone,
     * so a pre-4.5.38 caller that omits the field does not silently re-enable
     * everything an admin has turned off.
     *
     * @param array<string,bool>|null $commentsEnabled
     */
    public function saveMessageSettings(string $teamId, string $pinMinLevel, string $postMinLevel, string $linkMinLevel = 'admin', bool $allowPublicMessages = false, string $commentMinLevel = 'member', ?array $commentsEnabled = null): void {
        $valid = ['member', 'moderator', 'admin'];
        if (!in_array($pinMinLevel, $valid, true)) {
            throw new \InvalidArgumentException('Invalid pinMinLevel: ' . $pinMinLevel);
        }
        if (!in_array($postMinLevel, $valid, true)) {
            throw new \InvalidArgumentException('Invalid postMinLevel: ' . $postMinLevel);
        }
        if (!in_array($linkMinLevel, $valid, true)) {
            throw new \InvalidArgumentException('Invalid linkMinLevel: ' . $linkMinLevel);
        }
        if (!in_array($commentMinLevel, $valid, true)) {
            throw new \InvalidArgumentException('Invalid commentMinLevel: ' . $commentMinLevel);
        }
        $this->config->setAppValue(Application::APP_ID, 'pinMinLevel_'     . $teamId, $pinMinLevel);
        $this->config->setAppValue(Application::APP_ID, 'postMinLevel_'    . $teamId, $postMinLevel);
        $this->config->setAppValue(Application::APP_ID, 'linkMinLevel_'    . $teamId, $linkMinLevel);
        $this->config->setAppValue(Application::APP_ID, 'commentMinLevel_' . $teamId, $commentMinLevel);
        if ($commentsEnabled !== null) {
            $this->saveCommentsEnabled($teamId, $commentsEnabled);
        }
        $this->config->setAppValue(
            Application::APP_ID,
            'allowPublicMessages_' . $teamId,
            $allowPublicMessages ? '1' : '0',
        );
    }

    /**
     * Persist the per-type comment switches.
     *
     * **The stored value is the DISABLED set, not the enabled one.** An unset
     * key and an empty string then both mean "nothing disabled", which is
     * exactly the behaviour every team had before this setting existed — so the
     * upgrade needs no migration and no write for teams that never touch it. The
     * enabled set could not do that: an empty string would be indistinguishable
     * from "everything off", and every existing team would have to be written to
     * on upgrade to avoid losing its comments.
     *
     * @param array<string,bool> $commentsEnabled type => enabled
     */
    private function saveCommentsEnabled(string $teamId, array $commentsEnabled): void {
        $disabled = [];
        foreach (self::COMMENTABLE_TYPES as $type) {
            if (in_array($type, self::COMMENTS_ALWAYS_ON_TYPES, true)) {
                continue;
            }
            // Absent means enabled — a caller sending a partial map turns
            // nothing off by omission.
            if (array_key_exists($type, $commentsEnabled) && $commentsEnabled[$type] === false) {
                $disabled[] = $type;
            }
        }
        $this->config->setAppValue(
            Application::APP_ID,
            'commentDisabledTypes_' . $teamId,
            implode(',', $disabled),
        );
    }

    /**
     * The message types this team has switched commenting off for.
     *
     * Filtered against both allow-lists on read, not just on write: a value that
     * predates a type being made always-on, or names a type that no longer
     * exists, must not be able to hide a thread that should be visible.
     *
     * @return string[]
     */
    public function getCommentDisabledTypes(string $teamId): array {
        $raw = $this->config->getAppValue(Application::APP_ID, 'commentDisabledTypes_' . $teamId, '');
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $type) {
            $type = trim($type);
            if ($type === ''
                || !in_array($type, self::COMMENTABLE_TYPES, true)
                || in_array($type, self::COMMENTS_ALWAYS_ON_TYPES, true)) {
                continue;
            }
            $out[] = $type;
        }
        return array_values(array_unique($out));
    }

    /**
     * The setting as the API exposes it: every commentable type => bool.
     *
     * A map rather than the stored list because that is what a checkbox column
     * renders from, and because it states the always-on types explicitly instead
     * of leaving the client to know which ones they are.
     *
     * @return array<string,bool>
     */
    public function getCommentsEnabledMap(string $teamId): array {
        $disabled = $this->getCommentDisabledTypes($teamId);
        $map = [];
        foreach (self::COMMENTABLE_TYPES as $type) {
            $map[$type] = !in_array($type, $disabled, true);
        }
        return $map;
    }

    /**
     * Whether this team takes comments on messages of this type.
     *
     * An unknown or empty type reads as 'normal' — that is what
     * `postMessage()` coerces an unrecognised type to, so the thread follows
     * the message rather than escaping the setting.
     */
    public function areCommentsEnabledForType(string $teamId, string $messageType): bool {
        $type = $messageType !== '' ? $messageType : 'normal';
        if (!in_array($type, self::COMMENTABLE_TYPES, true)) {
            $type = 'normal';
        }
        return !in_array($type, $this->getCommentDisabledTypes($teamId), true);
    }

    /**
     * Refuse a comment write when the team has switched the thread off for this
     * message's type.
     *
     * Deliberately **not** applied to reads or deletes. Turning the switch off
     * is a "no new comments here" policy, not a retraction of what people have
     * already said: existing rows stay listable so flipping it back is lossless,
     * and a delete stays available so an admin can still clean up. Only writes —
     * create and edit — are refused. The frontend stops rendering the thread
     * entirely, which is what makes it look off; this is what makes it be off.
     */
    public function enforceCommentsEnabledForType(string $teamId, string $messageType): void {
        if (!$this->areCommentsEnabledForType($teamId, $messageType)) {
            throw new AccessDeniedException('Comments are disabled for this message type in this team');
        }
    }

    /**
     * Return the minimum Circles level required to post comments for a team.
     * Default: member (1) — everyone can comment unless overridden per team.
     * Levels: member=1, moderator=4, admin=8.
     */
    public function getCommentMinLevel(string $teamId): int {
        $setting = $this->config->getAppValue(Application::APP_ID, 'commentMinLevel_' . $teamId, 'member');
        return match($setting) {
            'moderator' => 4,
            'admin'     => 8,
            default     => 1,
        };
    }

    /**
     * Enforce the per-team commentMinLevel for the current user. Called from
     * CommentController::createComment. Membership itself is enforced
     * separately by MemberService::requireMemberLevel; this only guards the
     * level floor on top.
     *
     * Indirect members (via a group or sub-team) have no direct row, so
     * getMemberLevel returns 0 for them — when the floor is above 'member'
     * they are refused. Same behaviour as the postMinLevel enforcement at
     * postMessage() above.
     */
    public function enforceCommentMinLevel(string $teamId): void {
        $required = $this->getCommentMinLevel($teamId);
        if ($required <= 1) {
            return;
        }
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        $level = $this->getMemberLevel($teamId, $user->getUID());
        if ($level < $required) {
            throw new AccessDeniedException('Insufficient permissions to post comments in this team');
        }
    }

    /**
     * Whether the team admin has enabled the Public checkbox for members
     * composing normal messages. Default off — public visibility is opt-in.
     */
    public function getAllowPublicMessages(string $teamId): bool {
        return $this->config->getAppValue(Application::APP_ID, 'allowPublicMessages_' . $teamId, '0') === '1';
    }

    /**
     * v4.2.12 — Return the current user's "What's happening" feed page.
     *
     * Combines team messages from every team the caller is a member of
     * (direct + effective via groups) with public messages from teams they
     * are NOT in. One paginated DB query — see MessageMapper::findFeed for
     * the OR-branch semantics.
     *
     * Each returned row carries a synthetic `source` field ('team'|'public')
     * so the frontend can badge public posts without re-doing the
     * team-membership lookup client-side.
     *
     * v4.5.26 — additionally returns `sourceCounts` (the tab bar's numbers,
     * computed before the active tab narrows anything) and `facets` (the TEAMS
     * and TYPES lists the Feed control rail offers, derived from what the
     * viewer can actually see). Each row is stamped with what the viewer may do
     * with it — see stampInteractionRights().
     *
     * @param array<string,mixed> $filters See MessageController::parseFeedFilters().
     * @return array{items:array, hasMore:bool, total:int, limit:int, offset:int, sourceCounts:array<string,int>, facets:array}
     */
    public function getPersonalFeed(bool $includeTeam, bool $includePublic, int $limit, int $offset, bool $includeTalk = false, array $filters = []): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        // Deliberately not named $uid — the hydration loops further down reuse
        // that name for the row's author.
        $viewerUid = $user->getUID();

        $userTeamIds = $this->getCurrentUserTeamIds();

        // v4.5.26 — Feed control rail. Everything the rail can set arrives in
        // $filters already validated by the controller; the mapper turns the
        // message-side ones into SQL and the Talk-side ones are applied below,
        // because Talk rows come from another app's tables and cannot join.
        // v4.5.28 — an exclusion, not a lens. `includeMentions = false` drops
        // messages that mention the viewer; everything else is untouched.
        // Applied in PHP rather than SQL because the exact mention test is a
        // tokeniser, and the mapper's LIKE is only ever a pre-filter — using it
        // to *exclude* would throw away rows that merely look like a mention.
        $excludeMentions = array_key_exists('includeMentions', $filters)
            && !$filters['includeMentions'];
        $includeSystem = $filters['includeSystem'] ?? true;
        // v4.5.29 — decisions get their own Show switch. Default on.
        $includeDecisions = $filters['includeDecisions'] ?? true;
        $from          = (int)($filters['from'] ?? 0);
        $to            = (int)($filters['to'] ?? 0);
        $teamFilter    = is_array($filters['teamIds'] ?? null) ? array_values($filters['teamIds']) : [];
        $typeFilter    = is_array($filters['messageTypes'] ?? null) ? array_values($filters['messageTypes']) : [];

        $mapperFilters = [
            'from'          => $from,
            'to'            => $to,
            'teamIds'       => $teamFilter,
            'messageTypes'  => $typeFilter,
            'includeSystem' => $includeSystem,
            // v4.5.31 — decisions get their own branch in the WHERE, so the
            // Open decisions switch governs them on its own instead of needing
            // Team messages on as well.
            'includeDecisions' => $includeDecisions,
            // v4.5.26 — see resolveHiddenPublicTeamIds(). The team branch of
            // the feed is already safe (getCurrentUserTeamIds drops teams
            // pending deletion, and a hard-deleted team has no membership rows
            // left); the `is_public = 1` branch matched *any* team and so kept
            // showing posts from teams that had been archived or deleted.
            'excludeTeamIds' => $this->resolveHiddenPublicTeamIds(),
        ];

        // v4.2.14 — Talk items (polls + thread starters) from rooms
        // connected to the user's teams. Fetched separately from
        // teamhub_messages because the schema lives in Talk's own tables;
        // merged with messages by created_at DESC after the fact.
        //
        // Strategy: fetch up to (limit + offset) items from each source
        // (messages + polls + threads), merge in memory, slice to the
        // page window. Wasteful for very deep offsets but correct — a
        // recent-activity feed is not typically browsed past page ~5,
        // and the alternative (cursor-based multi-source pagination) is
        // significantly more code for negligible real-world benefit.
        // v4.5.33 — the room lookup is no longer gated on `includeTalk`. Talk
        // *mentions* are governed by the Mentions switch (a message naming you
        // is a mention first), so the rooms have to be resolved whenever either
        // switch wants them.
        $needRooms  = $includeTalk || !$excludeMentions;
        $rooms      = $needRooms ? $this->talkService->listRoomsForTeams($userTeamIds) : [];

        // v4.5.45 — proposal rooms the team boundary cannot reach.
        //
        // A proposal shared with a *selected* audience gets its own Talk
        // conversation, which is deliberately not a team resource (registering
        // it would drop every proposal room into the team's resource-review
        // queue). Without this the discussion is invisible in What's New to
        // exactly the people who were invited to it.
        //
        // `openProposalTalkRooms` applies the audience rule itself, so a room
        // only arrives here for a viewer entitled to it. The team id it
        // reports is stitched into $roomTeamMap below, because the ordinary
        // circle-attendee mapping has nothing to say about these rooms.
        $proposalRoomTeams = [];
        if ($needRooms) {
            try {
                $proposalRooms = $this->decisionService->openProposalTalkRooms($userTeamIds, $viewerUid);
                if ($proposalRooms !== []) {
                    $tokenTeam = [];
                    foreach ($proposalRooms as $pr) {
                        $tokenTeam[$pr['token']] = $pr['teamId'];
                    }
                    foreach ($this->talkService->listRoomsByTokens(array_keys($tokenTeam)) as $row) {
                        $rooms[] = $row;
                        $proposalRoomTeams[(int)$row['id']] = $tokenTeam[$row['token']] ?? '';
                    }
                }
            } catch (\Throwable $e) {
                // A proposal room that cannot be resolved costs that one
                // discussion in the feed, not the feed.
                $this->logger->warning('[TeamHub][MessageService] proposal Talk rooms could not be resolved', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $roomIds    = array_map(static fn($r) => (int)$r['id'], $rooms);
        $roomIndex  = [];
        foreach ($rooms as $r) {
            $roomIndex[(int)$r['id']] = $r;
        }
        $roomTeamMap = !empty($roomIds)
            ? $this->buildRoomTeamMap($roomIds, $userTeamIds)
            : [];
        // Proposal rooms have no circle attendee, so buildRoomTeamMap returns
        // nothing for them. Added after, never overwriting a real mapping.
        // The map is roomId => teamId (a string, first match per room wins).
        foreach ($proposalRoomTeams as $roomId => $teamId) {
            if ($teamId !== '' && !isset($roomTeamMap[$roomId])) {
                $roomTeamMap[$roomId] = $teamId;
            }
        }

        // Fetch enough per source to serve any page in the window. One extra
        // row so `hasMore` can be answered by looking at the merged list
        // rather than by comparing against an estimated total (v4.5.26 — the
        // old comparison was against a total that summed an exact message
        // COUNT with two fetched-list lengths, so "Next" could be offered on
        // an empty page).
        $fetchCap = $limit + $offset + 1;
        $talkPolls   = ($includeTalk && !empty($roomIds))
            ? $this->talkService->findRecentPolls($roomIds, $fetchCap, 0)
            : [];
        $talkThreads = ($includeTalk && !empty($roomIds))
            ? $this->talkService->findRecentThreads($roomIds, $fetchCap, 0)
            : [];

        // v4.5.33 — Talk chat messages that name the viewer.
        //
        // Gated on the **Mentions** switch, not the Talk one: a message that
        // says your name is a mention first and a chat message second, and
        // hiding it because you turned off polls and threads would be the wrong
        // reading of both switches. Fetched whenever mentions are included, so
        // rooms are resolved even with `includeTalk` off — see $rooms above,
        // which is why that lookup is no longer conditional on it.
        $talkMentions = (!$excludeMentions && !empty($roomIds))
            ? $this->talkService->findRecentMentions($roomIds, $viewerUid, $fetchCap)
            : [];

        // Talk rows carry no message_type and are never system posts, so the
        // TYPES and System switches exclude them wholesale rather than
        // filtering within them. PERIOD and TEAMS do apply and are enforced
        // in PHP — Talk's tables are in another app and cannot be joined.
        // Talk rows have no message_type, so the TYPES switch excludes them
        // wholesale rather than filtering within them. They are never scanned
        // for mentions either, so the Mentions switch leaves them alone —
        // excluding them there would hide conversations for a reason that does
        // not apply to them.
        $talkPolls    = $this->filterTalkRows($talkPolls,    $from, $to, $teamFilter, $roomTeamMap, $typeFilter);
        $talkThreads  = $this->filterTalkRows($talkThreads,  $from, $to, $teamFilter, $roomTeamMap, $typeFilter);
        $talkMentions = $this->filterTalkRows($talkMentions, $from, $to, $teamFilter, $roomTeamMap, $typeFilter);

        $messageRows = $this->messageMapper->findFeed(
            $userTeamIds,
            $includeTeam,
            $includePublic,
            $fetchCap,
            0,
            $mapperFilters,
        );

        if ($excludeMentions) {
            $messageRows = array_values(array_filter(
                $messageRows,
                fn(array $m) => !$this->mentionsUser((string)($m['message'] ?? ''), $viewerUid),
            ));
        }

        // v4.5.29 — decisions are their own row in the rail's Show list, and
        // only **open** ones belong in a feed. A finalized or approved decision
        // is a record, not something happening; the Decisions tab is where the
        // history lives. Applied here rather than in SQL because the status is
        // in `teamhub_decisions` and the feed's WHERE is already a two-branch
        // visibility clause that a join would complicate for no gain — the set
        // being filtered is one page's worth.
        $rowsBeforeDecisionFilter = count($messageRows);
        $messageRows = $this->applyDecisionFilter($messageRows, $includeDecisions, $viewerUid);
        $decisionsRemoved = $rowsBeforeDecisionFilter !== count($messageRows);

        // Message count. With mentions excluded the verified list *is* the
        // answer — the mapper cannot express the exclusion, so a COUNT over its
        // WHERE would over-report. Talk counts stay best-effort fetched-list
        // lengths; we don't run COUNT queries against another app's tables on
        // every visit.
        $messageTotal = ($excludeMentions || $decisionsRemoved)
            ? count($messageRows)
            : $this->messageMapper->countFeed($userTeamIds, $includeTeam, $includePublic, $mapperFilters);
        $total = $messageTotal + count($talkPolls) + count($talkThreads) + count($talkMentions);

        // Tag messages so the merge stage can identify by source cheaply.
        // Talk items already carry `source` set by TalkService.
        // We defer the message-side source classification (team vs public)
        // until after the merge, per prior behaviour.

        $merged = array_merge(
            array_map(static fn($m) => $m + ['__kind' => 'message'], $messageRows),
            array_map(static function ($p) use ($roomIndex, $roomTeamMap) {
                $p['__kind']   = 'talk';
                $p['team_id']  = $roomTeamMap[$p['room_id']] ?? '';
                $p['room_name'] = $roomIndex[$p['room_id']]['name']  ?? '';
                $p['room_token'] = $roomIndex[$p['room_id']]['token'] ?? '';
                return $p;
            }, $talkPolls),
            array_map(static function ($t) use ($roomIndex, $roomTeamMap) {
                $t['__kind']   = 'talk';
                $t['team_id']  = $roomTeamMap[$t['room_id']] ?? '';
                $t['room_name'] = $roomIndex[$t['room_id']]['name']  ?? '';
                $t['room_token'] = $roomIndex[$t['room_id']]['token'] ?? '';
                return $t;
            }, $talkThreads),
            array_map(static function ($m) use ($roomIndex, $roomTeamMap) {
                $m['__kind']   = 'talk';
                $m['team_id']  = $roomTeamMap[$m['room_id']] ?? '';
                $m['room_name'] = $roomIndex[$m['room_id']]['name']  ?? '';
                $m['room_token'] = $roomIndex[$m['room_id']]['token'] ?? '';
                return $m;
            }, $talkMentions),
        );
        usort($merged, static fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        // Per-source counts for the tab bar. Computed on the merged list
        // *before* the source tab narrows it, so picking one tab leaves the
        // others' numbers intact — the same rule My Work's sourceCounts and
        // category counts have followed since 4.5.21.
        $sourceCounts = $this->buildSourceCounts($merged, $userTeamIds, $viewerUid);

        // Apply pagination window on the merged, sorted list.
        $rows = array_slice($merged, $offset, $limit);
        $hasMore = count($merged) > ($offset + $limit);

        // Classify source per row. Talk items carry their own source
        // ('talk-poll' / 'talk-thread') from TalkService; message rows are
        // classified now as 'team' when the team is in the user's set, else
        // 'public'. `isPublic` (from the mapper) drives the badge — a public
        // own-team message still gets the badge but has source='team'.
        $teamIdSet = array_flip($userTeamIds);
        foreach ($rows as &$m) {
            if (($m['__kind'] ?? '') === 'talk') {
                // Talk rows already have source set. Nothing to add here.
                continue;
            }
            $m['source'] = isset($teamIdSet[$m['team_id'] ?? '']) ? 'team' : 'public';
        }
        unset($m);

        // Hydrate author display names in one pass — works for both
        // message rows (author_id) and Talk rows (actor_id). Two loops
        // to build the UID set then a single IUserManager pass.
        $uids = [];
        foreach ($rows as $m) {
            $uid = $m['author_id'] ?? ($m['actor_id'] ?? '');
            if ($uid !== '') {
                $uids[$uid] = true;
            }
        }
        $nameMap = [];
        foreach (array_keys($uids) as $uid) {
            try {
                $u = $this->userManager->get($uid);
                $nameMap[$uid] = $u ? (string)$u->getDisplayName() : $uid;
            } catch (\Throwable) {
                $nameMap[$uid] = $uid;
            }
        }

        // Hydrate team display names in one circles_circle SELECT.
        $teamIds = [];
        foreach ($rows as $m) {
            if (!empty($m['team_id'])) {
                $teamIds[$m['team_id']] = true;
            }
        }
        $teamNameMap = [];
        if (!empty($teamIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->in(
                    'unique_id',
                    $qb->createNamedParameter(array_keys($teamIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ));
            $r = $qb->executeQuery();
            while ($row = $r->fetch()) {
                $teamNameMap[(string)$row['unique_id']] = (string)($row['display_name'] ?? '');
            }
            $r->closeCursor();
        }

        foreach ($rows as &$m) {
            $uid = $m['author_id'] ?? ($m['actor_id'] ?? '');
            $m['author_display_name'] = $nameMap[$uid] ?? $uid;
            // Keep author_id populated for Talk rows too so the frontend
            // has one field to read for avatar / display purposes.
            if (!isset($m['author_id']) && isset($m['actor_id'])) {
                $m['author_id'] = $m['actor_id'];
            }
            $m['team_name'] = $teamNameMap[$m['team_id'] ?? ''] ?? ($m['team_name'] ?? '');
            // Internal sort helper — drop it from the response.
            unset($m['__kind']);
        }
        unset($m);

        // v4.5.26 — everything the feed's comment / reply / vote affordances
        // need in order to render only what the server would actually accept.
        $this->stampInteractionRights($rows, $userTeamIds, $viewerUid);

        $payload = [
            'items'        => $rows,
            'hasMore'      => $hasMore,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
            'sourceCounts' => $sourceCounts,
            'facets'       => $this->buildFeedFacets($merged, $teamNameMap),
        ];

        return $payload;
    }

    /**
     * v4.5.26 — teams whose public messages must not appear in anyone's feed.
     *
     * **The bug this closes.** The feed's WHERE is `team_id IN (my teams) OR
     * is_public = 1`. The left branch was always safe: `getCurrentUserTeamIds`
     * drops teams pending deletion, and a hard-deleted team has no
     * `circles_member` rows left to match. The right branch had no team
     * condition at all, so a public post kept showing — with its team name
     * resolving to nothing — long after the team was archived or deleted.
     * Justin found it, and it is the more serious kind of bug: content
     * outliving the boundary that was supposed to contain it.
     *
     * Two ways a team stops being viewable, and both are checked:
     *   - **Archived / pending deletion** — a `teamhub_pending_dels` row with
     *     status `pending`. Archiving registers one (`ArchiveService`), so this
     *     covers archived teams as well as ones queued for hard deletion. Same
     *     rule `TeamService::getUserTeams` and `getCurrentUserTeamIds` apply.
     *   - **Gone** — no `circles_circle` row. The delete cascade does not
     *     always reach `teamhub_messages`, so orphaned rows survive; without
     *     this they are visible to everyone, forever.
     *
     * Returns an **exclusion** list rather than an allow-list on purpose: it is
     * empty on a healthy instance, so the common case adds no WHERE term at
     * all, and it cannot accidentally hide a team it simply failed to see.
     *
     * @return string[]
     */
    private function resolveHiddenPublicTeamIds(): array {
        try {
            // Only teams that actually have public messages can leak, and that
            // is a far smaller set than "all teams". Capped so a pathological
            // instance cannot turn this into an unbounded IN clause; the cap
            // failing open is the right direction — see the catch below.
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('team_id')
                ->from('teamhub_messages')
                ->where($qb->expr()->eq('is_public', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(2000);
            $res = $qb->executeQuery();
            $candidates = [];
            while ($row = $res->fetch()) {
                $id = (string)($row['team_id'] ?? '');
                if ($id !== '') {
                    $candidates[$id] = true;
                }
            }
            $res->closeCursor();

            if (empty($candidates)) {
                return [];
            }
            $ids = array_keys($candidates);

            // Which of those still exist as a team.
            $alive = [];
            $cQb = $this->db->getQueryBuilder();
            $cRes = $cQb->select('unique_id')
                ->from('circles_circle')
                ->where($cQb->expr()->in(
                    'unique_id',
                    $cQb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->executeQuery();
            while ($row = $cRes->fetch()) {
                $alive[(string)$row['unique_id']] = true;
            }
            $cRes->closeCursor();

            $hidden = [];
            foreach ($ids as $id) {
                if (!isset($alive[$id])) {
                    $hidden[$id] = true;
                }
            }

            // …and which of the survivors are archived or queued for deletion.
            $pQb = $this->db->getQueryBuilder();
            $pRes = $pQb->select('team_id')
                ->from('teamhub_pending_dels')
                ->where($pQb->expr()->in(
                    'team_id',
                    $pQb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->andWhere($pQb->expr()->eq('status', $pQb->createNamedParameter('pending')))
                ->executeQuery();
            while ($row = $pRes->fetch()) {
                $hidden[(string)$row['team_id']] = true;
            }
            $pRes->closeCursor();

            return array_keys($hidden);
        } catch (\Throwable $e) {
            // Fail **closed is not an option** — an empty list here means the
            // feed behaves as it did before this fix, which is wrong but
            // functional. Returning something else would risk emptying the feed
            // on an instance whose `teamhub_pending_dels` table predates this
            // schema. Logged at warning so it is visible rather than silent.
            $this->logger->warning('[TeamHub][MessageService] hidden-team resolution failed — public posts from removed teams may be visible', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * v4.5.26 — resolve a Talk room token to the team that makes it visible to
     * the current user, or throw.
     *
     * This is the authorisation boundary for the feed's Talk interactions. It
     * deliberately reuses `buildRoomTeamMap` — the same mapping that decided
     * the row belonged in this user's feed in the first place — so there is
     * exactly one definition of "this conversation is reachable through one of
     * my teams" rather than a second one that can drift out of step with it.
     *
     * A token for a room the user can reach some other way (a private chat, a
     * conversation they were invited to directly) is refused: reachable is not
     * the same as reachable *through TeamHub*, and this endpoint only exists to
     * serve rows the feed rendered.
     *
     * @return string the team id
     * @throws AccessDeniedException
     */
    public function resolveFeedRoomTeam(string $token): string {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        if (trim($token) === '') {
            throw new AccessDeniedException('No conversation specified');
        }

        $userTeamIds = $this->getCurrentUserTeamIds();
        if (empty($userTeamIds)) {
            throw new AccessDeniedException('You are not a member of any team');
        }

        $roomId = $this->talkService->findRoomIdByToken($token);
        if ($roomId <= 0) {
            throw new AccessDeniedException('Conversation not found');
        }

        $map = $this->buildRoomTeamMap([$roomId], $userTeamIds);
        $teamId = $map[$roomId] ?? '';
        if ($teamId !== '') {
            return $teamId;
        }

        // v4.5.45 — the second way a room can legitimately be in this user's
        // feed: it belongs to an open proposal shared with them.
        //
        // These rooms have no circle attendee, so the mapping above cannot see
        // them, and refusing here would have rendered a reply box that 403s on
        // the very discussion the person was invited to. The **same** call the
        // feed uses decides it — `openProposalTalkRooms` applies the audience
        // rule itself — so there is still exactly one definition of "reachable
        // through TeamHub", which is the property this method exists to hold.
        foreach ($this->decisionService->openProposalTalkRooms($userTeamIds, $user->getUID()) as $room) {
            if ($room['token'] === $token) {
                return $room['teamId'];
            }
        }

        throw new AccessDeniedException('This conversation is not connected to one of your teams');
    }

    /**
     * v4.5.26 — the viewer's saved Feed control defaults ("Save as default").
     *
     * Stored as one JSON blob in `oc_preferences` under the teamhub app id,
     * mirroring `MyWorkConfigService`'s personal-preferences pattern — this is
     * a handful of scalars and two short lists, which does not earn a table.
     *
     * **Validated on read, not just on write.** The stored blob is a value the
     * user controls; a preference file edited by hand (or written by an older
     * version whose vocabulary has since changed) must not be able to widen a
     * query. Anything that fails validation falls back to the default for that
     * field alone, so one bad key cannot cost the user the rest of their setup.
     *
     * @return array<string,mixed>
     */
    public function getFeedPreferences(string $uid): array {
        $stored = [];
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::FEED_PREFS_KEY, '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        $period = (string)($stored['period'] ?? 'all');
        if (!in_array($period, self::FEED_PERIODS, true)) {
            $period = 'all';
        }

        $perPage = (int)($stored['perPage'] ?? 20);
        if (!in_array($perPage, self::FEED_PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        return [
            'includeTeam'   => (bool)($stored['includeTeam']   ?? true),
            'includePublic' => (bool)($stored['includePublic'] ?? true),
            'includeTalk'   => (bool)($stored['includeTalk']   ?? true),
            // v4.5.31 — no `includeSystem`. Its switch was removed from the
            // rail, so a stored `false` would hide milestone auto-posts with
            // nothing left to turn them back on. Ignored on read, dropped on
            // the next save; the query parameter itself still works for API
            // callers.
            // v4.5.28 — defaults **on**, and a blob saved before the rename
            // simply falls back to that default rather than being migrated:
            // the old key meant the opposite thing, so carrying its value over
            // would turn "show me only mentions" into "hide my mentions".
            'includeMentions' => (bool)($stored['includeMentions'] ?? true),
            'includeDecisions' => (bool)($stored['includeDecisions'] ?? true),
            'period'        => $period,
            // Only meaningful when period === 'custom'; kept regardless so
            // switching away and back doesn't lose the dates.
            'customFrom'    => max(0, (int)($stored['customFrom'] ?? 0)),
            'customTo'      => max(0, (int)($stored['customTo']   ?? 0)),
            'teamIds'       => $this->sanitiseStringList($stored['teamIds'] ?? [], null, 100),
            // `types` is intentionally absent — see saveFeedPreferences(). A
            // blob written before v4.5.29 still has the key; it is ignored
            // rather than migrated, and is dropped on the next save.
            'perPage'       => $perPage,
        ];
    }

    /**
     * Persist the viewer's Feed control defaults. Returns the stored state as
     * the read path would hand it back, so the caller never has to guess what
     * survived validation.
     *
     * @param array<string,mixed> $prefs
     * @return array<string,mixed>
     */
    public function saveFeedPreferences(string $uid, array $prefs): array {
        $period = (string)($prefs['period'] ?? 'all');
        if (!in_array($period, self::FEED_PERIODS, true)) {
            $period = 'all';
        }

        $perPage = (int)($prefs['perPage'] ?? 20);
        if (!in_array($perPage, self::FEED_PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        $from = max(0, (int)($prefs['customFrom'] ?? 0));
        $to   = max(0, (int)($prefs['customTo'] ?? 0));
        if ($from > 0 && $to > 0 && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $clean = [
            'includeTeam'   => !empty($prefs['includeTeam']),
            'includePublic' => !empty($prefs['includePublic']),
            'includeTalk'   => !empty($prefs['includeTalk']),
            'includeMentions' => !array_key_exists('includeMentions', $prefs)
                || !empty($prefs['includeMentions']),
            'includeDecisions' => !array_key_exists('includeDecisions', $prefs)
                || !empty($prefs['includeDecisions']),
            'period'        => $period,
            'customFrom'    => $from,
            'customTo'      => $to,
            'teamIds'       => $this->sanitiseStringList($prefs['teamIds'] ?? [], null, 100),
            // v4.5.29 — no `types`. The rail's TYPES section is gone: message
            // type and the Show switches were two controls for one decision,
            // and Show is the one that reads as a control. Deliberately dropped
            // from the stored blob as well as the UI — a saved `types` value
            // with no control left to clear it would filter the feed forever.
            'perPage'       => $perPage,
        ];

        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::FEED_PREFS_KEY,
            json_encode($clean, JSON_THROW_ON_ERROR),
        );

        return $clean;
    }

    /**
     * Trim / de-duplicate / cap a list of strings arriving from a request body
     * or a stored preference blob, optionally restricted to an allow-list.
     *
     * @param mixed $value
     * @param string[]|null $allowed
     * @return string[]
     */
    private function sanitiseStringList($value, ?array $allowed, int $cap): array {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string)$item);
            if ($s === '') {
                continue;
            }
            if ($allowed !== null && !in_array($s, $allowed, true)) {
                continue;
            }
            // Guards the stored blob as well as the query: a team id is a
            // Circles single-id, never anything like this long.
            if (strlen($s) > 128) {
                continue;
            }
            $out[$s] = true;
            if (count($out) >= $cap) {
                break;
            }
        }
        return array_keys($out);
    }

    /**
     * v4.5.29 — decide which decision rows belong in the feed, and stamp the
     * survivors with their decision so the card can render its state.
     *
     * Two rules, both from Justin:
     *
     *  - The **Decisions** switch in the rail owns them. Off means no decision
     *    rows at all, regardless of the Team / Public switches that would
     *    otherwise carry them — a decision is a message, but it is not the kind
     *    of message those switches are about.
     *  - Only **open** decisions are listed. A finalized, approved, denied or
     *    withdrawn decision is a record rather than something happening, and
     *    the Decisions tab is where records live.
     *
     * A decision-typed message with no decision row is dropped too: that is a
     * half-written proposal (the transaction in `postMessage` exists to prevent
     * it, but a legacy row could still exist), and it has no state to show.
     *
     * **v4.5.42 — a third rule, and it reverses part of the second.** An open
     * proposal shared with a `selected` audience is visible only to its
     * proposer and that audience. Every other open proposal is unchanged.
     *
     * That makes the hydration fallback below a disclosure rather than a
     * kindness: "keep them, unstamped" renders the proposal text as a plain
     * message, so on a hydration failure a restricted proposal would reach the
     * whole team. **Decisions are now dropped when hydration fails.** The
     * v4.5.29 reasoning — "none shown reads as there are none" — was written
     * when every open decision was team-visible; it does not survive a rule
     * where some are not. Failing closed beats failing informative when the
     * failure mode is a leak.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applyDecisionFilter(array $rows, bool $includeDecisions, string $viewerUid): array {
        $decisionIds = [];
        foreach ($rows as $row) {
            if (($row['messageType'] ?? '') === 'decision') {
                $decisionIds[] = (int)$row['id'];
            }
        }
        if (empty($decisionIds)) {
            return $rows;
        }
        if (!$includeDecisions) {
            return array_values(array_filter(
                $rows,
                static fn(array $r): bool => ($r['messageType'] ?? '') !== 'decision',
            ));
        }

        $decisions = [];
        try {
            $decisions = $this->decisionService->hydrateForMessages($decisionIds);
        } catch (\Throwable $e) {
            // v4.5.42 — drop rather than show unfiltered. Without the hydrated
            // row there is no shareMode to test, and an unstamped decision row
            // still renders its text. See the docblock.
            $this->logger->warning('[TeamHub][MessageService] decision hydration failed — decisions dropped from the feed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return array_values(array_filter(
                $rows,
                static fn(array $r): bool => ($r['messageType'] ?? '') !== 'decision',
            ));
        }

        $out = [];
        foreach ($rows as $row) {
            if (($row['messageType'] ?? '') !== 'decision') {
                $out[] = $row;
                continue;
            }
            $decision = $decisions[(int)$row['id']] ?? null;
            if ($decision === null || !in_array((string)($decision['status'] ?? ''), self::DECISION_OPEN_STATUSES, true)) {
                continue;
            }
            if (!$this->viewerMaySeeOpenDecision($decision, $viewerUid)) {
                continue;
            }
            $row['decision'] = $decision;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Stream visibility for one row (v4.5.42).
     *
     * Non-decision rows and decision rows with no hydrated payload are left
     * alone — this rule is only about restricted open proposals, and a missing
     * payload here means the module is off for the team, which already means
     * there are no proposals to restrict.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $decisions keyed by message id
     */
    private function streamRowVisible(array $row, array $decisions, string $viewerUid): bool {
        if (($row['messageType'] ?? '') !== 'decision') {
            return true;
        }
        $decision = $decisions[(int)$row['id']] ?? null;
        if ($decision === null) {
            return true;
        }
        if (!in_array((string)($decision['status'] ?? ''), self::DECISION_OPEN_STATUSES, true)) {
            return true;
        }
        return $this->viewerMaySeeOpenDecision($decision, $viewerUid);
    }

    /**
     * Audience check for an open decision in the feed (v4.5.42).
     *
     * Mirrors `DecisionService::canViewDecision()` against the already-hydrated
     * payload, so the feed does not run one query per row. The authoritative
     * gate is the service's — this one only decides what appears in a list, and
     * `DecisionController::show()` re-checks before handing over a single row.
     *
     * Fails closed by construction: `audience` is `[]` when the lookup failed,
     * and an empty audience admits nobody but the proposer.
     *
     * @param array<string,mixed> $decision
     */
    private function viewerMaySeeOpenDecision(array $decision, string $viewerUid): bool {
        if (($decision['shareMode'] ?? '') !== 'selected') {
            return true;
        }
        if ((string)($decision['proposedBy'] ?? '') === $viewerUid) {
            return true;
        }
        $audience = $decision['audience'] ?? [];
        return is_array($audience) && in_array($viewerUid, $audience, true);
    }

    /**
     * v4.5.26 — PERIOD / TEAMS filtering for Talk rows.
     *
     * Talk polls and threads live in another app's tables, so the mapper's
     * WHERE cannot reach them; the same filters are applied here instead. The
     * TYPES filter has no Talk equivalent — a poll has no `message_type` — so
     * when the user narrows to specific message types, Talk rows drop out
     * entirely rather than being silently exempt from a filter the user set.
     *
     * The Mentions switch deliberately does **not** reach them: TeamHub does
     * not scan Talk bodies for mentions, so a Talk row is neither a mention nor
     * not-a-mention, and hiding it for a reason that does not apply to it would
     * be worse than leaving it alone.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $teamFilter
     * @param array<int,string> $roomTeamMap
     * @param string[] $typeFilter
     * @return array<int,array<string,mixed>>
     */
    private function filterTalkRows(
        array $rows,
        int $from,
        int $to,
        array $teamFilter,
        array $roomTeamMap,
        array $typeFilter,
    ): array {
        if (!empty($typeFilter)) {
            return [];
        }
        if ($from <= 0 && $to <= 0 && empty($teamFilter)) {
            return $rows;
        }
        $teamSet = empty($teamFilter) ? null : array_flip($teamFilter);

        return array_values(array_filter($rows, static function (array $r) use ($from, $to, $teamSet, $roomTeamMap) {
            $ts = (int)($r['created_at'] ?? 0);

            // v4.5.26 — a row whose date Talk's schema could not give us
            // (`date_unknown`, see TalkService::findRecentPolls) is dropped as
            // soon as *any* date bound is set. It cannot be shown to satisfy a
            // window we have no way to test it against — that is precisely the
            // bug the fabricated timestamps caused, where "Today" returned
            // polls from weeks back.
            if (($from > 0 || $to > 0) && !empty($r['date_unknown'])) {
                return false;
            }
            if ($from > 0 && $ts < $from) {
                return false;
            }
            if ($to > 0 && $ts > $to) {
                return false;
            }
            if ($teamSet !== null) {
                $teamId = $roomTeamMap[(int)($r['room_id'] ?? 0)] ?? '';
                if ($teamId === '' || !isset($teamSet[$teamId])) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * v4.5.33 — both of these moved to `Mentions\MentionParser` once Talk chat
     * messages became a second thing that has to be scanned for mentions.
     * These thin wrappers are all that is left, so every caller in this class
     * keeps reading the way it did.
     *
     * @return string[]
     */
    private function parseMentionCandidates(string $body): array {
        return MentionParser::candidates($body);
    }

    private function mentionsUser(string $body, string $uid): bool {
        return MentionParser::mentions($body, $uid);
    }

    /**
     * v4.5.26 — counts for the feed's tab bar.
     *
     * Computed on the merged list before the active tab narrows it, so
     * selecting one tab leaves every other tab's number intact. Same rule the
     * My Work source bar follows (§2.73): a control's own data cannot be
     * filtered by the thing it navigates to.
     *
     * @param array<int,array<string,mixed>> $merged
     * @param string[] $userTeamIds
     * @return array<string,int>
     */
    private function buildSourceCounts(array $merged, array $userTeamIds, string $viewerUid): array {
        $teamIdSet = array_flip($userTeamIds);
        $counts = ['all' => 0, 'team' => 0, 'public' => 0, 'talk' => 0, 'mentions' => 0, 'decisions' => 0];

        foreach ($merged as $row) {
            $counts['all']++;
            $source = (string)($row['source'] ?? '');
            // v4.5.33 — a Talk mention counts under Mentions and nowhere else.
            // It is not a poll or a thread, so it does not belong under Talk;
            // it has no team-message identity, so it does not belong under Team
            // or Public. One tab covers both kinds of mention, which is what
            // "who wanted me" should mean.
            if ($source === 'talk-mention') {
                $counts['mentions']++;
                continue;
            }
            if ($source === 'talk-poll' || $source === 'talk-thread') {
                $counts['talk']++;
                continue;
            }
            if (isset($teamIdSet[(string)($row['team_id'] ?? '')])) {
                $counts['team']++;
            } else {
                $counts['public']++;
            }
            // Mentions and decisions overlap the team/public split rather than
            // partitioning it — they are lenses over the same rows, so these
            // six numbers deliberately do not sum to `all`.
            if ($this->mentionsUser((string)($row['message'] ?? ''), $viewerUid)) {
                $counts['mentions']++;
            }
            if ((string)($row['messageType'] ?? '') === 'decision') {
                $counts['decisions']++;
            }
        }

        return $counts;
    }

    /**
     * v4.5.26 — the TEAMS and TYPES lists the Feed control rail offers.
     *
     * Derived from the rows the viewer can actually see rather than from the
     * full team list: a rail that offers a team with nothing in it produces an
     * empty feed and no explanation. Counts come from the same merged,
     * pre-slice list as the tab bar.
     *
     * @param array<int,array<string,mixed>> $merged
     * @param array<string,string> $teamNameMap
     * @return array{teams:array<int,array{id:string,name:string,count:int}>,types:array<int,array{id:string,count:int}>}
     */
    private function buildFeedFacets(array $merged, array $teamNameMap): array {
        $teamCounts = [];
        $typeCounts = [];

        foreach ($merged as $row) {
            $teamId = (string)($row['team_id'] ?? '');
            if ($teamId !== '') {
                $teamCounts[$teamId] = ($teamCounts[$teamId] ?? 0) + 1;
            }
            // Talk rows have no message_type — they are typed by `source`
            // instead, which the tab bar already covers.
            $type = (string)($row['messageType'] ?? '');
            if ($type !== '') {
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
        }

        // Names for teams the page's own hydration pass didn't resolve (a team
        // present in the merged list but not in the sliced page). Falls back to
        // the id, which is what the row itself renders when a name is missing.
        $missing = array_values(array_diff(array_keys($teamCounts), array_keys($teamNameMap)));
        if (!empty($missing)) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('unique_id', 'display_name')
                    ->from('circles_circle')
                    ->where($qb->expr()->in(
                        'unique_id',
                        $qb->createNamedParameter($missing, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                    ));
                $r = $qb->executeQuery();
                while ($row = $r->fetch()) {
                    $teamNameMap[(string)$row['unique_id']] = (string)($row['display_name'] ?? '');
                }
                $r->closeCursor();
            } catch (\Throwable $e) {
                // Non-fatal — the rail falls back to raw ids for these.
                $this->logger->warning('[TeamHub][MessageService] feed facet team-name lookup failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $teams = [];
        foreach ($teamCounts as $id => $count) {
            $teams[] = [
                'id'    => (string)$id,
                // ?? before ?: — a team whose name lookup found nothing has no
                // key at all here, and `?:` alone raises "Undefined array key"
                // on exactly the rows this fallback exists for.
                'name'  => ($teamNameMap[(string)$id] ?? '') ?: (string)$id,
                'count' => $count,
            ];
        }
        usort($teams, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $types = [];
        foreach ($typeCounts as $id => $count) {
            $types[] = ['id' => (string)$id, 'count' => $count];
        }
        usort($types, static fn($a, $b) => strcmp($a['id'], $b['id']));

        return ['teams' => $teams, 'types' => $types];
    }

    /**
     * v4.5.26 — stamp each row with what the viewer may do with it.
     *
     * The feed spans many teams, and comment permission is per-team
     * (`commentMinLevel`) plus per-message (the decision lock). The frontend
     * has no way to work that out — its `canComment` getter reads the
     * *current* team's settings, which is meaningless on a cross-team page —
     * so the server answers per row and the UI renders only what it is told.
     *
     * This is presentation, not enforcement. `CommentController` re-checks
     * membership, the level floor and the lock on every write; a client that
     * ignores these flags gets a 403, not a comment.
     *
     * Three flags per message row:
     *   can_view_comments — viewer is a member of the row's team AND the team
     *                       takes comments on this message's type. False for a
     *                       public post from a team they are not in, which is
     *                       exactly what `CommentController::listComments`
     *                       would refuse.
     *   can_comment       — additionally passes the team's commentMinLevel and
     *                       the message is not decision-locked.
     *   comment_count     — filled for every row the viewer may read.
     *
     * v4.5.38 — the per-type switch lands on `can_view_comments` rather than
     * getting a flag of its own, because the two mean the same thing to the
     * card: whether to render a thread at all. Dropping it here also drops the
     * count, since `comment_count` is only filled where that flag is true — and
     * "you see the message only" is the whole point of the setting. The role
     * floor is the other case and stays separate: it leaves the thread readable
     * and only disables the composer.
     *
     * @param array<int,array<string,mixed>> $rows  Modified in place.
     * @param string[] $userTeamIds
     */
    private function stampInteractionRights(array &$rows, array $userTeamIds, string $viewerUid): void {
        $teamIdSet = array_flip($userTeamIds);

        // Per-team, resolved once each — a page can hold 100 rows from a
        // handful of teams and all three lookups hit appconfig / the DB.
        $minLevelCache     = [];
        $myLevelCache      = [];
        $disabledTypeCache = [];

        $readableMessageIds = [];
        // Talk's answer is per room, and a page holds many rows from few
        // rooms — each miss builds a Room and a Participant through Talk.
        $canPostCache = [];
        $pollIds = [];

        foreach ($rows as &$row) {
            $source = (string)($row['source'] ?? '');
            $teamId = (string)($row['team_id'] ?? '');

            if ($source === 'talk-poll' || $source === 'talk-thread' || $source === 'talk-mention') {
                // Talk's permissions decide here, not TeamHub's — a member of
                // the team can still be absent from the room, or the room can
                // be read-only. Two gates, both required: the room has to be
                // reachable through a team the viewer is in (so the feed never
                // becomes a way to reach a conversation you found another way)
                // and Talk has to accept them as a participant who may post.
                $inTeam = $teamId !== '' && isset($teamIdSet[$teamId]);
                $token  = (string)($row['room_token'] ?? '');

                $canPost = false;
                if ($inTeam && $token !== '') {
                    if (!array_key_exists($token, $canPostCache)) {
                        $canPostCache[$token] = $this->talkService->canPostToRoom($token, $viewerUid);
                    }
                    $canPost = $canPostCache[$token];
                }

                // A mention is replied to the same way a thread is — the reply
                // is threaded onto the message that named you.
                // v4.5.39 — a Talk row is only ever resolved through a room the
                // viewer's own teams are connected to, so this is true by
                // construction. Stamped anyway, from the same variable, so the
                // card has one flag to read for every source.
                $row['can_open_team'] = $inTeam;

                $row['can_reply'] = ($source === 'talk-thread' || $source === 'talk-mention') && $canPost;
                $row['can_vote']  = $source === 'talk-poll'
                    && $canPost
                    // Talk codes an open poll as status 0; anything else is
                    // closed. Reading it as "not 0" rather than "=== 1" keeps
                    // a future third state on the safe side.
                    && (int)($row['status'] ?? 0) === 0;

                if ($source === 'talk-poll') {
                    $pollIds[] = (int)($row['id'] ?? 0);
                    // Filled in below from one query over the page's polls.
                    $row['my_votes'] = [];
                }
                continue;
            }

            $isMember = $teamId !== '' && isset($teamIdSet[$teamId]);

            // v4.5.38 — the team may have switched this message type's thread
            // off entirely. Only resolved for teams the viewer is in, so the
            // cache is not built for rows that are about to be cut anyway.
            $typeAllowed = true;
            if ($isMember) {
                if (!array_key_exists($teamId, $disabledTypeCache)) {
                    $disabledTypeCache[$teamId] = $this->getCommentDisabledTypes($teamId);
                }
                $type = (string)($row['messageType'] ?? '');
                if ($type === '' || !in_array($type, self::COMMENTABLE_TYPES, true)) {
                    $type = 'normal';
                }
                $typeAllowed = !in_array($type, $disabledTypeCache[$teamId], true);
            }

            // v4.5.39 — whether the card may offer a way into the team.
            //
            // It cannot be inferred from `can_view_comments` any more: since
            // 4.5.38 that flag is false for a *member* of a team that has
            // switched this message type's thread off, and such a member can
            // open their team perfectly well. Membership needs to be said in
            // its own right.
            $row['can_open_team'] = $isMember;

            $row['can_view_comments'] = $isMember && $typeAllowed;

            if (!$isMember || !$typeAllowed) {
                // Either a public post from a team you are not in — the thread
                // is not readable — or a type this team takes no comments on.
                // Neither advertises a count: the card ends at its body.
                $row['can_comment']   = false;
                $row['comment_count'] = 0;
                continue;
            }

            $readableMessageIds[] = (int)($row['id'] ?? 0);

            if (!array_key_exists($teamId, $minLevelCache)) {
                $minLevelCache[$teamId] = $this->getCommentMinLevel($teamId);
            }
            $required = $minLevelCache[$teamId];

            if ($required > 1) {
                if (!array_key_exists($teamId, $myLevelCache)) {
                    // getMemberLevel, not the effective-membership check —
                    // deliberately the same direct-row lookup
                    // enforceCommentMinLevel() uses, so an indirect member sees
                    // the box disabled rather than getting a 403 on submit.
                    $myLevelCache[$teamId] = $this->getMemberLevel($teamId, $viewerUid);
                }
                $canComment = $myLevelCache[$teamId] >= $required;
            } else {
                $canComment = true;
            }

            // The decision lock is per message, so it cannot be cached per
            // team. Only decision rows can be locked, and they are a small
            // minority of any page — checking every row would cost two queries
            // each for nothing.
            if ($canComment && (string)($row['messageType'] ?? '') === 'decision') {
                try {
                    if ($this->decisionService->isCommentLocked($teamId, (int)$row['id'])) {
                        $canComment = false;
                        $row['comments_locked'] = true;
                    }
                } catch (\Throwable $e) {
                    // A lock we cannot read is treated as locked — refusing a
                    // comment that would have been allowed is recoverable;
                    // offering one the server will reject is not.
                    $canComment = false;
                    $row['comments_locked'] = true;
                }
            }

            $row['can_comment'] = $canComment;
        }
        unset($row);

        if (!empty($pollIds)) {
            $ownVotes = $this->talkService->findOwnPollVotes(
                array_values(array_unique(array_filter($pollIds))),
                $viewerUid,
            );
            if (!empty($ownVotes)) {
                foreach ($rows as &$row) {
                    if (($row['source'] ?? '') === 'talk-poll') {
                        $row['my_votes'] = $ownVotes[(int)($row['id'] ?? 0)] ?? [];
                    }
                }
                unset($row);
            }
        }

        if (empty($readableMessageIds)) {
            return;
        }

        $counts = $this->messageMapper->countCommentsForMessages(array_values(array_unique($readableMessageIds)));
        foreach ($rows as &$row) {
            if (!empty($row['can_view_comments'])) {
                $row['comment_count'] = $counts[(int)($row['id'] ?? 0)] ?? 0;
            }
        }
        unset($row);
    }

    /**
     * v4.2.14 — resolve a Talk room → team_id mapping, restricted to teams
     * the current user is in. Used when building the feed so a Talk item
     * gets attributed to a team the click-through can actually open.
     *
     * A room may have multiple circle-attendee rows (in theory), so a room
     * can map to more than one team; we pick the first team from the
     * caller's own $userTeamIds that matches. Deterministic — the query
     * orders by attendee id so the pick is stable across calls.
     *
     * @return array<int, string>  room_id → team_id
     */
    private function buildRoomTeamMap(array $roomIds, array $userTeamIds): array {
        if (empty($roomIds) || empty($userTeamIds)) {
            return [];
        }
        $out = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('room_id', 'actor_id')
                ->from('talk_attendees')
                ->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->in(
                    'room_id',
                    $qb->createNamedParameter($roomIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
                ))
                ->andWhere($qb->expr()->in(
                    'actor_id',
                    $qb->createNamedParameter($userTeamIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->orderBy('id', 'ASC');
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $rid = (int)$row['room_id'];
                if (!isset($out[$rid])) {
                    // First match per room wins — deterministic thanks to
                    // the ORDER BY above.
                    $out[$rid] = (string)$row['actor_id'];
                }
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MessageService] buildRoomTeamMap failed', [
                'rooms' => count($roomIds), 'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }
        return $out;
    }

    /**
     * Resolve the current user's team ids (direct + effective via groups /
     * sub-teams). Reads directly from Circles' own tables — mirrors the
     * approach in TeamService::getUserTeams because probeCircles() on this
     * instance filters out teams with non-zero config (DESIGN §2.1).
     *
     * Returns a plain string array of `circles_circle.unique_id` values.
     *
     * @return string[]
     */
    private function getCurrentUserTeamIds(): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            return [];
        }
        $uid = $user->getUID();

        $ids = [];

        // Direct membership: circles_member rows with user_type=1, status=Member.
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('circle_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->executeQuery();
        while ($row = $res->fetch()) {
            if (!empty($row['circle_id'])) {
                $ids[(string)$row['circle_id']] = true;
            }
        }
        $res->closeCursor();

        // Indirect membership: circles_membership entries keyed on the
        // user's personal-circle single_id (added via a group or a
        // sub-team). Silently skipped on Circles versions that don't have
        // this table.
        try {
            $singleId = $this->memberService->resolveUserSingleId($uid, $this->db);
            if ($singleId) {
                $msQb  = $this->db->getQueryBuilder();
                $msRes = $msQb->select('circle_id')
                    ->from('circles_membership')
                    ->where($msQb->expr()->eq('single_id', $msQb->createNamedParameter($singleId)))
                    ->executeQuery();
                while ($row = $msRes->fetch()) {
                    if (!empty($row['circle_id'])) {
                        $ids[(string)$row['circle_id']] = true;
                    }
                }
                $msRes->closeCursor();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MessageService] getCurrentUserTeamIds: circles_membership lookup skipped', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // v4.2.16 — filter out personal (`user:...`) and group (`group:...`)
        // circles the same way TeamService::getUserTeams does. Without this
        // filter, users appear to have 44 "teams" when they actually have
        // 18 real teams — noisy for the feed and confusing in debug logs.
        $idList = array_keys($ids);
        if (!empty($idList)) {
            try {
                $nQb  = $this->db->getQueryBuilder();
                $nRes = $nQb->select('unique_id', 'name')
                    ->from('circles_circle')
                    ->where($nQb->expr()->in(
                        'unique_id',
                        $nQb->createNamedParameter($idList, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                    ))
                    ->executeQuery();
                $keep = [];
                while ($row = $nRes->fetch()) {
                    $name = (string)($row['name'] ?? '');
                    if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                        continue;
                    }
                    $keep[(string)$row['unique_id']] = true;
                }
                $nRes->closeCursor();
                $ids = $keep;
            } catch (\Throwable $e) {
                // Fall through with the unfiltered list on introspection
                // failure — better to over-include than to lock the feed.
            }
        }

        // Exclude teams pending deletion — same rule TeamService::getUserTeams
        // applies. A pending-deletion team is hidden from all member queries
        // except the admin pending-deletion endpoint.
        $idList = array_keys($ids);
        if (!empty($idList)) {
            try {
                $pdQb  = $this->db->getQueryBuilder();
                $pdRes = $pdQb->select('team_id')
                    ->from('teamhub_pending_dels')
                    ->where($pdQb->expr()->in(
                        'team_id',
                        $pdQb->createNamedParameter($idList, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                    ))
                    ->andWhere($pdQb->expr()->eq('status', $pdQb->createNamedParameter('pending')))
                    ->executeQuery();
                while ($row = $pdRes->fetch()) {
                    unset($ids[(string)$row['team_id']]);
                }
                $pdRes->closeCursor();
            } catch (\Throwable $e) {
                // Table may not exist on older TeamHub schema versions —
                // fall through with the unfiltered list.
            }
        }

        return array_keys($ids);
    }

    /**
     * Return the most recent public messages across all teams on this NC
     * instance. Used by GET /api/v1/messages/public — the personal
     * aggregated feed will call this in addition to the caller's own
     * team-messages so posts from teams the user is not in also surface.
     *
     * Author display names are hydrated in a single IUserManager pass so the
     * feed renders "Jane Doe" rather than raw UIDs; team display names are
     * hydrated in one circles_circle SELECT for the same reason.
     *
     * @param int   $limit          max rows to return (caller-clamped to sane bounds)
     * @param int   $offset         pagination offset
     * @param array $excludeTeamIds team ids to skip — callers pass the user's
     *                              own team memberships so a post doesn't
     *                              appear once as "team" and once as "public"
     */
    public function getPublicMessages(int $limit = 20, int $offset = 0, array $excludeTeamIds = []): array {
        $messages = $this->messageMapper->findPublic($limit, $offset, $excludeTeamIds);

        // Resolve author display names in one pass.
        $uids = [];
        foreach ($messages as $m) {
            if (!empty($m['author_id'])) {
                $uids[$m['author_id']] = true;
            }
        }
        $nameMap = [];
        foreach (array_keys($uids) as $uid) {
            try {
                $u = $this->userManager->get($uid);
                $nameMap[$uid] = $u ? (string)$u->getDisplayName() : $uid;
            } catch (\Throwable) {
                $nameMap[$uid] = $uid;
            }
        }

        // Resolve team display names in one pass over the distinct team_ids.
        $teamIds = [];
        foreach ($messages as $m) {
            if (!empty($m['team_id'])) {
                $teamIds[$m['team_id']] = true;
            }
        }
        $teamNameMap = [];
        if (!empty($teamIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->in(
                    'unique_id',
                    $qb->createNamedParameter(array_keys($teamIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
                ));
            $r = $qb->executeQuery();
            while ($row = $r->fetch()) {
                $teamNameMap[(string)$row['unique_id']] = (string)($row['display_name'] ?? '');
            }
            $r->closeCursor();
        }

        foreach ($messages as &$m) {
            $m['author_display_name'] = $nameMap[$m['author_id'] ?? ''] ?? ($m['author_id'] ?? '');
            $m['team_name']           = $teamNameMap[$m['team_id'] ?? ''] ?? '';
        }
        unset($m);

        return $messages;
    }

    /**
     * Return the configured linkMinLevel as an integer for permission checks.
     * 'member'=1, 'moderator'=4, 'admin'=8. Default: 8 (admin).
     */
    public function getLinkMinLevelInt(string $teamId): int {
        $setting = $this->config->getAppValue(Application::APP_ID, 'linkMinLevel_' . $teamId, 'admin');
        return match($setting) {
            'member'    => 1,
            'moderator' => 4,
            default     => 8,
        };
    }

    /**
     * Return the configured pinMinLevel string (for the admin API response).
     * Still reads the global setting for the admin panel.
     */
    public function getPinMinLevelSetting(): string {
        return $this->config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator');
    }

    /**
     * Mark that the current user has seen a team's messages right now.
     */
    public function markTeamSeen(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }
        $userId = $user->getUID();
        $now = time();

        // UPSERT: update if exists, insert if not
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('user_id')
            ->from('teamhub_last_seen')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $exists = (bool)$result->fetch();
        $result->closeCursor();

        if ($exists) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('teamhub_last_seen')
                ->set('last_seen_at', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->executeStatement();
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_last_seen')
                ->values([
                    'user_id'      => $qb->createNamedParameter($userId),
                    'team_id'      => $qb->createNamedParameter($teamId),
                    'last_seen_at' => $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        }
    }

    /**
     * Return the last-seen timestamp for the current user + team (0 if never seen).
     */
    public function getTeamLastSeen(string $teamId): int {
        $user = $this->userSession->getUser();
        if (!$user) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('last_seen_at')
            ->from('teamhub_last_seen')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['last_seen_at'] : 0;
    }
    
    /**
     * Vote on a poll
     */
    public function votePoll(int $messageId, int $optionIndex): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }

        $db = $this->container->get(\OCP\IDBConnection::class);
        
        // Delete existing vote
        $qb = $db->getQueryBuilder();
        $qb->delete('teamhub_poll_votes')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
            ->executeStatement();
        
        // Insert new vote
        $qb = $db->getQueryBuilder();
        $qb->insert('teamhub_poll_votes')
            ->values([
                'message_id' => $qb->createNamedParameter($messageId),
                'user_id' => $qb->createNamedParameter($user->getUID()),
                'option_index' => $qb->createNamedParameter($optionIndex),
                'created_at' => $qb->createNamedParameter(time()),
            ])
            ->executeStatement();
        
        return $this->getPollResults($messageId);
    }
    
    /**
     * Get poll results
     */
    public function getPollResults(int $messageId): array {
        $db = $this->container->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        
        // Get vote counts per option
        $result = $qb->select('option_index')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'vote_count')
            ->from('teamhub_poll_votes')
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
            ->groupBy('option_index')
            ->executeQuery();
        
        $votes = [];
        while ($row = $result->fetch()) {
            $votes[(int)$row['option_index']] = (int)$row['vote_count'];
        }
        $result->closeCursor();
        
        // Get current user's vote
        $user = $this->userSession->getUser();
        $userVote = null;
        if ($user) {
            $qb = $db->getQueryBuilder();
            $voteResult = $qb->select('option_index')
                ->from('teamhub_poll_votes')
                ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
                ->executeQuery();
            
            if ($row = $voteResult->fetch()) {
                $userVote = (int)$row['option_index'];
            }
            $voteResult->closeCursor();
        }
        
        return [
            'votes' => $votes,
            'userVote' => $userVote,
            'totalVotes' => array_sum($votes),
        ];
    }

    /**
     * Close a poll to prevent further voting
     */
    public function closePoll(int $messageId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->error('Close poll failed: User not authenticated', ['app' => Application::APP_ID]);
            throw new \Exception('Not authenticated');
        }

        $message = $this->messageMapper->find($messageId);
        
        
        if ($message['author_id'] !== $user->getUID()) {
            $this->logger->error('Close poll failed: User is not author', [
                'messageId' => $messageId,
                'userId' => $user->getUID(),
                'authorId' => $message['author_id'],
                'app' => Application::APP_ID
            ]);
            throw new \Exception('Only the poll author can close it');
        }

        if (!isset($message['messageType']) || $message['messageType'] !== 'poll') {
            $this->logger->error('Close poll failed: Not a poll', [
                'messageId' => $messageId,
                'messageType' => $message['messageType'] ?? 'missing',
                'app' => Application::APP_ID
            ]);
            throw new \Exception('This is not a poll');
        }

        $result = $this->messageMapper->closePoll($messageId);
        return $result;
    }

    /**
     * Mark a question as solved with a specific comment
     */
    public function markQuestionSolved(int $messageId, int $commentId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->error('Mark question solved failed: User not authenticated', ['app' => Application::APP_ID]);
            throw new \Exception('Not authenticated');
        }

        $message = $this->messageMapper->find($messageId);
        
        
        if ($message['author_id'] !== $user->getUID()) {
            $this->logger->error('Mark solved failed: User is not author', [
                'messageId' => $messageId,
                'userId' => $user->getUID(),
                'authorId' => $message['author_id'],
                'app' => Application::APP_ID
            ]);
            throw new \Exception('Only the question author can mark it as solved');
        }

        if (!isset($message['messageType']) || $message['messageType'] !== 'question') {
            $this->logger->error('Mark solved failed: Not a question', [
                'messageId' => $messageId,
                'messageType' => $message['messageType'] ?? 'missing',
                'app' => Application::APP_ID
            ]);
            throw new \Exception('This is not a question');
        }

        $result = $this->messageMapper->markQuestionSolved($messageId, $commentId);
        return $result;
    }

    /**
     * Unmark a question as solved
     */
    public function unmarkQuestionSolved(int $messageId): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }

        $message = $this->messageMapper->find($messageId);
        if ($message['author_id'] !== $user->getUID()) {
            throw new \Exception('Only the question author can unmark it');
        }

        return $this->messageMapper->unmarkQuestionSolved($messageId);
    }

    // ── Attachments (v3.71.2) ─────────────────────────────────────────────

    /**
     * Register a file as an attachment of a message. Used by the message
     * compose form: after upload + share, the client reports the resulting
     * file_id here so the sidecar table records the link. The link enables
     * the Decisions module to copy attachments into .proposals/{decisionId}/
     * on finalize.
     *
     * Authorization: caller must be the message author. We don't store
     * arbitrary user-supplied team_id; we derive it from the message row.
     *
     * @return array{id:int, message_id:int, file_id:int, file_name:string, uploaded_by:string, created_at:int}
     */
    public function registerAttachment(int $messageId, int $fileId, string $fileName): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('Not authenticated');
        }
        if ($fileId <= 0) {
            throw new \InvalidArgumentException('Invalid file_id');
        }
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new \InvalidArgumentException('file_name is required');
        }
        if (mb_strlen($fileName) > 255) {
            $fileName = mb_substr($fileName, 0, 255);
        }

        $message = $this->messageMapper->find($messageId);
        // SEC: only the author of the message may register attachments on it.
        if ($message['author_id'] !== $user->getUID()) {
            throw new \Exception('Only the message author can register attachments');
        }

        $entity = new \OCA\TeamHub\Db\MessageAttachment();
        $entity->setMessageId($messageId);
        $entity->setFileId($fileId);
        $entity->setFileName($fileName);
        $entity->setUploadedBy($user->getUID());
        $entity->setCreatedAt(time());

        try {
            $saved = $this->attachmentMapper->insert($entity);
        } catch (\OCP\DB\Exception $e) {
            // Unique violation = same (message_id, file_id) registered twice.
            // Treat as idempotent: find and return the existing row.
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                foreach ($this->attachmentMapper->findByMessageId($messageId) as $row) {
                    if ($row->getFileId() === $fileId) {
                        return [
                            'id'          => $row->getId(),
                            'message_id'  => $row->getMessageId(),
                            'file_id'     => $row->getFileId(),
                            'file_name'   => $row->getFileName(),
                            'uploaded_by' => $row->getUploadedBy(),
                            'created_at'  => $row->getCreatedAt(),
                        ];
                    }
                }
            }
            throw $e;
        }

        $this->logger->debug('[TeamHub][MessageService] registerAttachment', [
            'message_id' => $messageId, 'file_id' => $fileId,
            'file_name'  => $fileName, 'uploaded_by' => $user->getUID(),
        ]);

        return [
            'id'          => $saved->getId(),
            'message_id'  => $saved->getMessageId(),
            'file_id'     => $saved->getFileId(),
            'file_name'   => $saved->getFileName(),
            'uploaded_by' => $saved->getUploadedBy(),
            'created_at'  => $saved->getCreatedAt(),
        ];
    }

    /**
     * List attachments registered against a message. Used internally by the
     * Decisions module (finalize-time copy into .proposals/{decisionId}/).
     *
     * @return array<int, array{file_id:int, file_name:string, uploaded_by:string}>
     */
    public function listAttachmentsForMessage(int $messageId): array {
        $out = [];
        foreach ($this->attachmentMapper->findByMessageId($messageId) as $row) {
            $out[] = [
                'file_id'     => $row->getFileId(),
                'file_name'   => $row->getFileName(),
                'uploaded_by' => $row->getUploadedBy(),
            ];
        }
        return $out;
    }
}
