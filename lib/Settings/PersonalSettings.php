<?php
declare(strict_types=1);

namespace OCA\TeamHub\Settings;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Controller\PreferencesController;
use OCA\TeamHub\Service\DateContextService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * TeamHub personal settings page (NC Settings → Personal → TeamHub).
 *
 * Renders the `personal` template which mounts `PersonalSettingsPanel.vue`
 * via the `personal.js` entry point.
 *
 * This is the only TeamHub personal settings page. If future sessions add
 * more user-scoped settings, they go inside `PersonalSettingsPanel.vue` —
 * not as a new ISettings registration.
 *
 * v4.4.12 — the panel now always renders. Until then the page mounted
 * `MyPresencePanel` directly and only when the Presence module was enabled
 * instance-wide, so an instance with Presence off showed a TeamHub entry in
 * the personal-settings sidebar that opened onto an empty page.
 */
class PersonalSettings implements ISettings {

    public function __construct(
        private IConfig             $config,
        private IUserSession        $userSession,
        private DateContextService  $dateContext,
    ) {}

    public function getForm(): TemplateResponse {
        $this->dateContext->provideInitialState();

        $enabled = $this->config->getAppValue('teamhub', 'presence_module_enabled', '1') === '1';

        $uid = $this->userSession->getUser()?->getUID();
        $gettingStarted = $uid !== null && $this->config->getUserValue(
            $uid,
            Application::APP_ID,
            PreferencesController::PREF_GETTING_STARTED,
            '1',
        ) === '1';

        return new TemplateResponse('teamhub', 'personal', [
            'presenceModuleEnabled' => $enabled,
            'gettingStartedHint'    => $gettingStarted,
        ]);
    }

    public function getSection(): string {
        return 'teamhub';
    }

    public function getPriority(): int {
        return 50;
    }
}
