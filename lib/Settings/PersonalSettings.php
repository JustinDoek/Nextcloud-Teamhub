<?php
declare(strict_types=1);

namespace OCA\TeamHub\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * TeamHub personal settings page (NC Settings → Personal → TeamHub).
 *
 * Renders the `personal` template which mounts `MyPresencePanel.vue`
 * via the `personal.js` webpack entry point.
 *
 * This is the only TeamHub personal settings page. If future sessions add
 * more user-scoped settings (notification preferences, etc.), they go inside
 * `MyPresencePanel.vue` or alongside it in the same template — not as a new
 * ISettings registration.
 */
class PersonalSettings implements ISettings {

    public function getForm(): TemplateResponse {
        $config  = \OC::$server->get(\OCP\IConfig::class);
        $enabled = $config->getAppValue('teamhub', 'presence_module_enabled', '0') === '1';
        return new TemplateResponse('teamhub', 'personal', [
            'presenceModuleEnabled' => $enabled,
        ]);
    }

    public function getSection(): string {
        return 'teamhub';
    }

    public function getPriority(): int {
        return 50;
    }
}
