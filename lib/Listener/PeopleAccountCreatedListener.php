<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserCreatedEvent;

/** @template-implements IEventListener<UserCreatedEvent> */
class PeopleAccountCreatedListener implements IEventListener {
    public function __construct(private IConfig $config) {}

    public function handle(Event $event): void {
        if (!$event instanceof UserCreatedEvent) {
            return;
        }
        $userId = $event->getUser()->getUID();
        $this->config->setUserValue($userId, Application::APP_ID, 'people_account_created_at', (string)time());
    }
}
