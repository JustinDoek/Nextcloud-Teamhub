<?php
declare(strict_types=1);

namespace OCA\TeamHub\AppInfo;

use OCA\TeamHub\Listener\AppDisabledListener;
use OCA\TeamHub\Notification\Notifier;
use OCA\TeamHub\Service\IntegrationService;
use OCP\App\Events\AppDisabledEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCA\TeamHub\Search\MessageSearchProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'teamhub';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register notification notifier.
        $context->registerNotifierService(Notifier::class);

        // Auto-deregister any integration whose app is disabled or removed.
        // AppDisabledEvent fires for both occ app:disable and App Store removal.
        $context->registerEventListener(AppDisabledEvent::class, AppDisabledListener::class);

        // Register TeamHub messages with NC unified search.
        $context->registerSearchProvider(MessageSearchProvider::class);

        // Note: DailyReportJob is registered via appinfo/info.xml <background-jobs> block.
        // registerBackgroundJob() was removed from IRegistrationContext in NC 33.

        // Note: Admin settings panel is registered via appinfo/info.xml <settings> block.
        // Do NOT call $context->registerSettings() here — that method does not exist
        // on NC32's IRegistrationContext and causes a fatal on every page load.
    }

    public function boot(IBootContext $context): void {
        // Seed built-in integrations (Talk, Files, Calendar, Deck) into the
        // integration registry. Idempotent — safe to call on every boot.
        try {
            $container = $context->getAppContainer();
            /** @var IntegrationService $integrationService */
            $integrationService = $container->get(IntegrationService::class);
            $integrationService->seedBuiltins();
        } catch (\Throwable $e) {
            // Never let a seeding failure crash the entire app boot.
        }

        // Fire the install telemetry event once (guarded inside the service).
        try {
            $container = $context->getAppContainer();
            $telemetry  = $container->get(\OCA\TeamHub\Service\TelemetryService::class);
            $telemetry->sendInstallEvent();
        } catch (\Throwable $e) {
            // Never let telemetry affect boot.
        }
    }
}
