<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCP\App\IAppManager;
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

    public function __construct(
        MessageMapper $messageMapper,
        IUserSession $userSession,
        INotificationManager $notificationManager,
        IAppManager $appManager,
        IMailer $mailer,
        IUserManager $userManager,
        ContainerInterface $container,
        LoggerInterface $logger
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
     * Get messages for a specific team with comment counts
     */
    public function getTeamMessages(string $teamId, int $limit = 50, int $offset = 0): array {
        return $this->messageMapper->findByTeamId($teamId, $limit, $offset);
    }

    /**
     * Create a new message with priority, poll, and question support
     */
    public function createMessage(string $teamId, string $subject, string $message, string $priority = 'normal', string $messageType = 'normal', ?array $pollOptions = null): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        if (!in_array($priority, ['normal', 'priority'])) {
            $priority = 'normal';
        }
        
        if (!in_array($messageType, ['normal', 'poll', 'question'])) {
            $messageType = 'normal';
        }

        $circlesManager = $this->getCirclesManager();

        try {
            $federatedUser = $circlesManager->getFederatedUser($user->getUID(), 1);
            $circlesManager->startSession($federatedUser);

            $probeResult = $circlesManager->probeCircles();
            $circles = is_array($probeResult) ? $probeResult : ($probeResult ? $probeResult->getCircles() : []);

            $circle = null;
            foreach ($circles as $c) {
                $cId = method_exists($c, 'getSingleId') ? $c->getSingleId() : $c->getId();
                if ($cId === $teamId) {
                    $circle = $c;
                    break;
                }
            }

            if (!$circle) {
                throw new \Exception('Team not found or access denied');
            }

            $messageData = $this->messageMapper->create($teamId, $user->getUID(), $subject, $message, $priority, $messageType, $pollOptions);

            // Send notifications to all members
            $this->sendNotifications($teamId, $messageData['id'], $subject, $user->getDisplayName(), $circle);

            // Send email to all members if priority message
            if ($priority === 'priority') {
                $this->sendPriorityEmails($subject, $message, $user->getDisplayName(), $circle);
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
    public function updateMessage(int $messageId, string $subject, string $message): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $existing = $this->messageMapper->find($messageId);
        if ($existing['author_id'] !== $user->getUID()) {
            throw new \Exception('Only the author can edit this message');
        }
        return $this->messageMapper->update($messageId, $subject, $message);
    }

    /**
     * Delete a message
     */
    public function deleteMessage(int $messageId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }
        $existing = $this->messageMapper->find($messageId);
        if ($existing['author_id'] !== $user->getUID()) {
            throw new \Exception('Only the author can delete this message');
        }
        $this->messageMapper->delete($messageId);
    }

    /**
     * Get aggregated messages
     */
    public function getAggregatedMessages(): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
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
     * Send in-app notifications to all members
     */
    private function sendNotifications(string $teamId, int $messageId, string $subject, string $authorName, $circle): void {
        try {
            $members = $circle->getMembers();
            $currentUser = $this->userSession->getUser();

            foreach ($members as $member) {
                try {
                    $userId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                    if (!$userId || $userId === $currentUser->getUID()) continue;

                    $notification = $this->notificationManager->createNotification();
                    $notification->setApp('teamhub')
                        ->setUser($userId)
                        ->setDateTime(new \DateTime())
                        ->setObject('message', (string)$messageId)
                        ->setSubject('new_message', [
                            'author' => $authorName,
                            'subject' => $subject,
                            'team' => $circle->getDisplayName(),
                        ]);
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
     * Send priority emails to all members
     */
    private function sendPriorityEmails(string $subject, string $message, string $authorName, $circle): void {
        try {
            $members = $circle->getMembers();
            $currentUser = $this->userSession->getUser();

            foreach ($members as $member) {
                try {
                    $userId = method_exists($member, 'getUserId') ? $member->getUserId() : null;
                    if (!$userId || $userId === $currentUser->getUID()) continue;

                    $ncUser = $this->userManager->get($userId);
                    if (!$ncUser) continue;

                    $email = $ncUser->getEMailAddress();
                    if (!$email) continue;

                    $mail = $this->mailer->createMessage();
                    $mail->setSubject('[TeamHub] ' . $subject);
                    $mail->setTo([$email => $ncUser->getDisplayName()]);
                    $mail->setPlainBody(
                        "New priority message from {$authorName} in team {$circle->getDisplayName()}:\n\n" .
                        "Subject: {$subject}\n\n" .
                        $message
                    );
                    $mail->setHtmlBody(
                        "<p><strong>New priority message from {$authorName}</strong><br>" .
                        "Team: {$circle->getDisplayName()}</p>" .
                        "<h3>{$subject}</h3>" .
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
            ->execute();
        
        // Insert new vote
        $qb = $db->getQueryBuilder();
        $qb->insert('teamhub_poll_votes')
            ->values([
                'message_id' => $qb->createNamedParameter($messageId),
                'user_id' => $qb->createNamedParameter($user->getUID()),
                'option_index' => $qb->createNamedParameter($optionIndex),
                'created_at' => $qb->createNamedParameter(time()),
            ])
            ->execute();
        
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
            ->execute();
        
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
                ->execute();
            
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
        
        $this->logger->debug('Attempting to close poll', [
            'messageId' => $messageId,
            'userId' => $user->getUID(),
            'authorId' => $message['author_id'],
            'messageType' => $message['messageType'] ?? 'missing',
            'app' => Application::APP_ID
        ]);
        
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
        $this->logger->info('Poll closed successfully', ['messageId' => $messageId, 'app' => Application::APP_ID]);
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
        
        $this->logger->debug('Attempting to mark question as solved', [
            'messageId' => $messageId,
            'commentId' => $commentId,
            'userId' => $user->getUID(),
            'authorId' => $message['author_id'],
            'messageType' => $message['messageType'] ?? 'missing',
            'app' => Application::APP_ID
        ]);
        
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
        $this->logger->info('Question marked as solved', [
            'messageId' => $messageId, 
            'commentId' => $commentId,
            'app' => Application::APP_ID
        ]);
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
}
