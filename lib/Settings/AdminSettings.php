<?php
declare(strict_types=1);

namespace OCA\TeamHub\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('teamhub', 'admin', []);
    }

    public function getSection(): string {
        return 'teamhub';
    }

    public function getPriority(): int {
        return 50;
    }
}
