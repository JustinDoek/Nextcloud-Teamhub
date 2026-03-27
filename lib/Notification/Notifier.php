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

        $l = $this->l10nFactory->get('teamhub', $languageCode);

        switch ($notification->getSubject()) {
            case 'new_message':
                $params = $notification->getSubjectParameters();
                $notification->setParsedSubject(
                    $l->t('New message from {author} in {team}', [
                        'author' => $params['author'],
                        'team' => $params['team'],
                    ])
                );
                $notification->setParsedMessage($params['subject']);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                    'teamhub.page.index'
                ));
                return $notification;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }
    }
}
