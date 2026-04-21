<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\Mail\IMailer;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FeedbackService {

    private const RECIPIENT = 'teamhub@tldr.host';
    private const RECIPIENT_NAME = 'TeamHub Feedback';

    public function __construct(
        private IMailer $mailer,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {}

    /**
     * Send a feedback/feature-request email.
     *
     * @param string $type    'bug' | 'feature' | 'other'
     * @param string $subject User-supplied subject line (required, max 200 chars)
     * @param string $body    User-supplied description (required, max 5000 chars)
     * @param string $contact Optional reply-to address supplied by the user
     *
     * @throws \RuntimeException when the mailer reports a failure
     */
    public function submit(
        string $type,
        string $subject,
        string $body,
        string $contact,
    ): void {
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'anonymous';
        $displayName = $user ? $user->getDisplayName() : 'Unknown';

        error_log('[TeamHub][FeedbackService] submit called: type=' . $type . ', userId=' . $userId);

        $typeLabel = match ($type) {
            'bug'     => 'Bug report',
            'feature' => 'Feature request',
            default   => 'Other',
        };

        $emailSubject = '[TeamHub ' . $typeLabel . '] ' . $subject;

        // Build a plain-text body — avoids any HTML-injection risk.
        $lines = [
            'Type    : ' . $typeLabel,
            'From    : ' . $displayName . ' (' . $userId . ')',
            'Contact : ' . ($contact !== '' ? $contact : '(not provided)'),
            '',
            '--- Description ---',
            '',
            $body,
        ];
        $plainBody = implode("\n", $lines);

        $message = $this->mailer->createMessage();
        $message->setTo([self::RECIPIENT => self::RECIPIENT_NAME]);
        $message->setSubject($emailSubject);
        $message->setPlainBody($plainBody);

        // If the user provided a contact address, set it as reply-to.
        if ($contact !== '') {
            error_log('[TeamHub][FeedbackService] setting reply-to: ' . $contact);
            $message->setReplyTo([$contact]);
        }

        $failed = $this->mailer->send($message);

        if (!empty($failed)) {
            error_log('[TeamHub][FeedbackService] mailer reported failed recipients: ' . json_encode($failed));
            throw new \RuntimeException('Feedback email could not be delivered.');
        }

        error_log('[TeamHub][FeedbackService] email sent successfully for userId=' . $userId);
    }
}
