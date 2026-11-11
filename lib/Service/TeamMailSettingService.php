<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\IConfig;

/**
 * Whether a team offers "Email all members" (v4.20.0).
 *
 * A team admin switches it off under Manage team → Settings → Permissions;
 * it is on unless they do. Off hides the sidebar action for everyone in the
 * team, team admins included, and the compose endpoint refuses with 403, so
 * a stale page or a hand-made request cannot reach the address list either.
 *
 * Storage: NC app-config keyed `member_mail_enabled_<teamId>` = "1"|"0",
 * the same single-boolean-per-team shape as `timeline_enabled_<teamId>`.
 * App-config values are loaded once per request, so asking for every team in
 * the sidebar costs no query per team.
 *
 * A DI leaf (IConfig only) on purpose: TeamService needs it for the sidebar
 * rows and TeamController for the endpoints, and neither may pull the other's
 * graph in behind it.
 */
class TeamMailSettingService {

    public const KEY_PREFIX = 'member_mail_enabled_';

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function isEnabled(string $teamId): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_PREFIX . $teamId, '1') !== '0';
    }

    public function setEnabled(string $teamId, bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_PREFIX . $teamId, $enabled ? '1' : '0');
    }

    /**
     * The caller must already have passed the membership check; this only
     * says whether the team allows the action at all.
     *
     * @throws AccessDeniedException when the team has switched it off
     */
    public function requireEnabled(string $teamId): void {
        if (!$this->isEnabled($teamId)) {
            throw new AccessDeniedException('Emailing all members is switched off for this team');
        }
    }
}
