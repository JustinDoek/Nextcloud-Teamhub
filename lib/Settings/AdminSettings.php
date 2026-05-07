<?php
declare(strict_types=1);

namespace OCA\TeamHub\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * TeamHub admin settings — implements IDelegatedSettings so that NC admins
 * can delegate TeamHub administration to specific groups via the standard
 * Administration privileges page (Settings → Administration → Administration privileges).
 *
 * Delegated group members pass the #[AuthorizedAdminSetting] check on all
 * TeamHub admin endpoints without needing full NC admin rights.
 *
 * getAuthorizedAppConfig() lists the IConfig app-value key patterns that
 * delegated admins are allowed to read/write. The regex '/.+/' covers all
 * TeamHub config keys (archiveMode, inviteTypes, pinMinLevel, etc.).
 * This is intentional — a TeamHub admin should be able to manage all
 * TeamHub settings, not a restricted subset.
 */
class AdminSettings implements IDelegatedSettings {

    public function getForm(): TemplateResponse {
        return new TemplateResponse('teamhub', 'admin', []);
    }

    public function getSection(): string {
        return 'teamhub';
    }

    public function getPriority(): int {
        return 50;
    }

    /**
     * Human-readable name shown in the Administration privileges page
     * alongside the TeamHub section entry.
     * Returning null because TeamHub has one settings class for the entire
     * app — the section name is sufficient label.
     */
    public function getName(): ?string {
        return null;
    }

    /**
     * Config key patterns a delegated admin may read/write.
     * All TeamHub IConfig keys are stored under app='teamhub'.
     * The pattern '/.+/' matches any non-empty key.
     *
     * @return array<string, list<string>>
     */
    public function getAuthorizedAppConfig(): array {
        return [
            'teamhub' => ['/.+/'],
        ];
    }
}
