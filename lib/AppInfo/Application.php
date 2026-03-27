<?php
declare(strict_types=1);

namespace OCA\TeamHub\AppInfo;

use OCA\TeamHub\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'teamhub';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register notification notifier
        $context->registerNotifierService(Notifier::class);
        // Note: Admin settings panel is registered via appinfo/info.xml <settings> block.
        // Do NOT call $context->registerSettings() here — that method does not exist
        // on NC32's IRegistrationContext and causes a fatal on every page load.
    }

    public function boot(IBootContext $context): void {
        // Bootstrap the app
    }
}
