<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\Service\IntegrationService;
use OCA\TeamHub\Service\TelemetryService;
use OCA\TeamHub\AppInfo\Application;
use OCP\App\Events\AppDisabledEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for OCP\App\Events\AppDisabledEvent and auto-deregisters any
 * TeamHub integration that was registered by the disabled app.
 *
 * This fires for both:
 *   - php occ app:disable <appId>
 *   - Removing an app via the App Store UI
 *
 * The call to deregisterIntegration() uses calledInProcess: true because
 * this listener runs server-side with no web session available.
 * Built-in app IDs (spreed, files, calendar, deck) are safe — deregister
 * silently no-ops for them (they are protected inside IntegrationService).
 * Apps that never registered an integration are also silently skipped.
 *
 * @template-implements IEventListener<AppDisabledEvent>
 */
class AppDisabledListener implements IEventListener {

    public function __construct(
        private IntegrationService $integrationService,
        private TelemetryService   $telemetryService,
        private LoggerInterface    $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof AppDisabledEvent)) {
            return;
        }

        $appId = $event->getAppId();

        // Fire uninstall telemetry when TeamHub itself is disabled
        if ($appId === Application::APP_ID) {
            try {
                $this->telemetryService->sendUninstallEvent();
            } catch (\Throwable $e) {
                // Never let telemetry affect the disable flow
            }
        }

        try {
            // suspendIntegration() clears php_class/iframe_url but keeps the
            // registry row and all team opt-ins intact. The ID never changes.
            // When the app is re-enabled, boot() calls registerIntegration()
            // which upserts the class back in — team admins never need to
            // re-enable widgets after an app update or disable/enable cycle.
            $this->integrationService->suspendIntegration($appId);
        } catch (\Throwable $e) {
        }
    }
}
