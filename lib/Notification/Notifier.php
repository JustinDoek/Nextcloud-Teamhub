<?php
declare(strict_types=1);

namespace OCA\TeamHub\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator
    ) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'teamhub';
    }

    public function getName(): string {
        return $this->l10nFactory->get('teamhub')->t('TeamHub');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'teamhub') {
            throw new UnknownNotificationException('Unknown app');
        }

        switch ($notification->getSubject()) {
            case 'new_message':
                $params = $notification->getSubjectParameters();
                $authorName = $params['author']  ?? 'Someone';
                $teamName   = $params['team']    ?? 'a team';
                $subject    = $params['subject'] ?? '';

                // setRichSubject replaces {placeholder} tokens with the rich objects.
                // This is the correct NC API — $l->t() with {foo} syntax does NOT interpolate.
                $notification->setRichSubject(
                    'New message from {author} in {team}',
                    [
                        'author' => [
                            'type'  => 'user',
                            'id'    => $params['authorId'] ?? $authorName,
                            'name'  => $authorName,
                        ],
                        'team' => [
                            'type'  => 'highlight',
                            'id'    => $params['teamId'] ?? $teamName,
                            'name'  => $teamName,
                        ],
                    ]
                );

                // Fallback plain text for clients that don't render rich subjects
                $notification->setParsedSubject(
                    'New message from ' . $authorName . ' in ' . $teamName
                );

                // Show the message subject as the notification body
                if ($subject !== '') {
                    $notification->setRichMessage('{subject}', [
                        'subject' => ['type' => 'highlight', 'id' => 'subject', 'name' => $subject],
                    ]);
                    $notification->setParsedMessage($subject);
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));

                // Link is set by MessageService with ?team= param — use it if present,
                // otherwise fall back to the app root.
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }

                return $notification;

            case 'join_request':
                $params        = $notification->getSubjectParameters();
                $requesterName = $params['requesterName'] ?? ($params['requestingUid'] ?? 'Someone');
                $teamName      = $params['teamName']      ?? 'a team';
                $teamId        = $params['teamId']        ?? '';

                $notification->setRichSubject(
                    '{requester} wants to join {team}',
                    [
                        'requester' => [
                            'type' => 'user',
                            'id'   => $params['requestingUid'] ?? $requesterName,
                            'name' => $requesterName,
                        ],
                        'team' => [
                            'type' => 'highlight',
                            'id'   => $teamId,
                            'name' => $teamName,
                        ],
                    ]
                );
                $notification->setParsedSubject(
                    $requesterName . ' wants to join ' . $teamName
                );
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }
    }
}
