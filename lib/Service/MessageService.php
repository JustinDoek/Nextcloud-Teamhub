<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
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
        \OCA\TeamHub\Db\MessageAttachmentMapper $attachmentMapper
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
                        $teamId, $user->getUID(), $subject, $message, $priority, $messageType, $pollOptions
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
                    $teamId, $user->getUID(), $subject, $message, $priority, $messageType, $pollOptions
                );
            }

            // Get member UIDs from DB for notifications — no Circles API needed.
            $memberUids = $this->getTeamMemberUids($teamId);

            // Send new-message notifications to all members.
            $this->sendNotificationsWithName($teamId, $messageData['id'], $subject, $user->getDisplayName(), $teamName, $memberUids);

            // Send targeted mention notifications to @mentioned users.
            $this->sendMentionNotifications($teamId, $messageData['id'], $message, $user, $memberUids);

            // Send email to all members if priority message.
            if ($priority === 'priority') {
                $this->sendPriorityEmailsWithName($subject, $message, $user->getDisplayName(), $teamName, $memberUids);
            }

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
     */
    private function getMemberLevel(string $teamId, string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['level'] : 0;
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
                $uids[$row['user_id']] = true;
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
                    $uids[$row['user_id']] = true;
                }
            }
            $msRes->closeCursor();
        } catch (\Throwable $e) {
            // circles_membership may not exist on older Circles versions — non-fatal
            $this->logger->debug('[TeamHub][MessageService] getTeamMemberUids: circles_membership lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        return array_keys($uids);
    }

    private function sendMentionNotifications(string $teamId, int $messageId, string $body, \OCP\IUser $author, array $memberUids): void {
        try {
            preg_match_all('/@([a-zA-Z0-9._-]+)/', $body, $matches);
            $mentionedIds = array_unique($matches[1] ?? []);
            if (empty($mentionedIds)) {
                return;
            }

            $memberSet = array_flip($memberUids);
            $link = $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId);

            foreach ($mentionedIds as $mentionedId) {
                if (!isset($memberSet[$mentionedId]) || $mentionedId === $author->getUID()) {
                    continue;
                }
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
                } catch (\Exception $e) {
                    $this->logger->error('Failed to notify member - ', ['exception' => $e, 'app' => Application::APP_ID]);
                }
            }
        } catch (\Exception $e) {
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
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send priority email - ', ['exception' => $e, 'app' => Application::APP_ID]);
                }
            }
        } catch (\Exception $e) {
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
     */
    public function getMessageSettings(string $teamId): array {
        $pinSetting = $this->config->getAppValue(Application::APP_ID, 'pinMinLevel_' . $teamId, '');
        if ($pinSetting === '') {
            $pinSetting = $this->config->getAppValue(Application::APP_ID, 'pinMinLevel', 'moderator');
        }
        $postSetting = $this->config->getAppValue(Application::APP_ID, 'postMinLevel_' . $teamId, 'member');
        $linkSetting = $this->config->getAppValue(Application::APP_ID, 'linkMinLevel_' . $teamId, 'admin');
        return [
            'pinMinLevel'  => $pinSetting,
            'postMinLevel' => $postSetting,
            'linkMinLevel' => $linkSetting,
        ];
    }

    /**
     * Save per-team message settings.
     * Accepts pinMinLevel, postMinLevel, and linkMinLevel, all as strings: 'member'|'moderator'|'admin'.
     */
    public function saveMessageSettings(string $teamId, string $pinMinLevel, string $postMinLevel, string $linkMinLevel = 'admin'): void {
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
        $this->config->setAppValue(Application::APP_ID, 'pinMinLevel_'  . $teamId, $pinMinLevel);
        $this->config->setAppValue(Application::APP_ID, 'postMinLevel_' . $teamId, $postMinLevel);
        $this->config->setAppValue(Application::APP_ID, 'linkMinLevel_' . $teamId, $linkMinLevel);
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
