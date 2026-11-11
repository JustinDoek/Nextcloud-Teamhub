<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\AppNotAvailableException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Nextcloud Teams (Circles) frontend switch — one app config value of
 * another app, exposed to a Nextcloud administrator on TeamHub's Policy tab
 * (v4.13.16, DESIGN §2.190).
 *
 * ## What the switch does, and why it is not cosmetic
 *
 * Circles gates its **entire** user-facing surface on `circles`'
 * `frontend_enabled` app value, and says so itself in
 * `Application::registerNavigation()`: *"Register the Teams navigation entry,
 * but only when the frontend is enabled. Done here rather than in info.xml so
 * it stays in sync with the PageController and dashboard widget, which are
 * gated on the same setting."* Read off the Circles shipped with Nextcloud 35,
 * the gate sits on exactly five places:
 *
 *   - `Application::registerNavigation()` — no entry in the app menu,
 *   - `PageController::index()` — `/apps/circles/teams` answers **404**, so
 *     knowing the URL does not help,
 *   - `LocalController::setCurrentFederatedUser()` — every one of that
 *     controller's 26 endpoints throws `FrontendException('frontend disabled')`,
 *     which is also what Contacts' own team view (`/apps/contacts/…/circle/{id}`,
 *     where Nextcloud core's team links point) calls,
 *   - `TeamsDashboardController` and `TeamDashboardWidget::isEnabled()` — no
 *     Teams dashboard widget.
 *
 * So this is an enforcement boundary rather than a hidden icon, which is the
 * point for the compliance case it was asked for (Justin, 2026-10-21): teams
 * are administered in TeamHub, and Nextcloud's own Teams app stops being a
 * second, unaudited way in.
 *
 * **Nothing TeamHub needs is behind that gate.** `CirclesManager` (what all of
 * TeamHub uses), `ShareByCircleProvider`, the share-picker
 * `CollaboratorSearchPlugin`, DAV principals, memberships and the `circles_*`
 * tables are untouched, and Circles stays enabled — the app dependency is
 * intact. The one exception is Circles' per-team avatar OCS route, which lives
 * on `LocalController`: see `TeamService::isTeamsAvatarSupported()`, which
 * reads {@see isEnabled()} for that reason.
 *
 * ## Why `IConfig` and not `IAppConfig`
 *
 * Circles reads the value with `getAppValueString()`, i.e. typed
 * `IAppConfig::getValueString()`. Writing it as a typed bool would store type
 * `BOOL` and make **Circles' own read throw** `AppConfigTypeConflictException`
 * on every page load. `IConfig::setAppValue()` stores `VALUE_MIXED`, which a
 * typed read tolerates ("if type of stored value is set as mixed, we don't
 * filter"), and `IConfig::getAppValue()` asserts no type at all, so it also
 * reads a value an administrator set with `occ config:app:set`. Deprecated in
 * favour of `IAppConfig`, and deliberately so here: the compatible type is the
 * requirement, not the modern API.
 *
 * ## The resolution order is Circles', not ours
 *
 * `ConfigService::getAppValue()` resolves in three steps — the app value, then
 * the system value `circles.frontend_enabled` from `config.php`, then its own
 * default of `'1'`. {@see isEnabled()} mirrors that exactly, so the switch
 * reports what Circles will actually do. Note the consequence: the app value
 * wins over `config.php`, so this switch can turn the frontend back on for an
 * instance whose `config.php` turned it off. {@see status()} reports the system
 * value so the administrator can see that is what happened.
 */
class TeamsFrontendService {

    /** The app whose frontend this switches. Not TeamHub's own config. */
    private const CIRCLES_APP = 'circles';

    /** Circles' `ConfigService::FRONTEND_ENABLED`. */
    private const KEY = 'frontend_enabled';

    /** Circles' own `config.php` override, checked when the app value is unset. */
    private const SYSTEM_KEY = 'circles.frontend_enabled';

    /** Circles' own default, from `ConfigService::$defaults`. */
    private const DEFAULT_RAW = '1';

    public function __construct(
        private IConfig         $config,
        private IUserSession    $userSession,
        private IGroupManager   $groupManager,
        private IAppManager     $appManager,
        private AuditService    $auditService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether Circles' user-facing frontend is on.
     *
     * **Ungated on purpose.** It answers what the instance is configured to do,
     * not what the caller may change, and `TeamService` reads it per team
     * payload for the avatar capability flag. The writes are gated; this is not.
     */
    public function isEnabled(): bool {
        if (!$this->appManager->isInstalled(self::CIRCLES_APP)) {
            return false;
        }

        return (int)$this->rawValue() === 1;
    }

    /**
     * The switch's state for the admin UI.
     *
     * `decided` is the one field that is not about Circles' behaviour: it says
     * whether an administrator has ever made this call, i.e. whether the app
     * value is written at all. The setup checklist reads it, which is the whole
     * reason {@see setEnabled()} writes `'0'` instead of deleting the key - a
     * delete would put the row back to "not reviewed" after somebody reviewed
     * it and chose the default.
     *
     * @return array{
     *     available: bool,
     *     enabled: bool,
     *     decided: bool,
     *     systemValue: string,
     * }
     */
    public function status(): array {
        $this->requireNcAdmin();

        return [
            // Circles disabled altogether: the switch has nothing to switch,
            // and TeamHub does not work either. Reported rather than assumed.
            'available'   => $this->appManager->isInstalled(self::CIRCLES_APP),
            'enabled'     => $this->isEnabled(),
            'decided'     => $this->isDecided(),
            // '' when config.php says nothing, which is the normal case.
            'systemValue' => (string)$this->config->getSystemValue(self::SYSTEM_KEY, ''),
        ];
    }

    /**
     * Whether this instance has an explicit answer, as opposed to running on
     * Circles' default.
     *
     * A system value in `config.php` counts: somebody stated an intent there,
     * even though this switch would override it. Only "nothing anywhere" is
     * undecided.
     */
    public function isDecided(): bool {
        if ((string)$this->config->getAppValue(self::CIRCLES_APP, self::KEY, '') !== '') {
            return true;
        }

        return (string)$this->config->getSystemValue(self::SYSTEM_KEY, '') !== '';
    }

    /**
     * Turn Circles' frontend on or off.
     *
     * Writes `'1'` or `'0'` — never deletes the key back to "unset", because
     * unset means *fall through to `config.php`, then to Circles' default*, and
     * an administrator who switches this off is stating an intent that must not
     * be undone by a system value they cannot see from here.
     *
     * @return array{available: bool, enabled: bool, systemValue: string}
     */
    public function setEnabled(bool $enabled): array {
        $actor = $this->requireNcAdmin();

        if (!$this->appManager->isInstalled(self::CIRCLES_APP)) {
            throw new AppNotAvailableException('The Nextcloud Teams (Circles) app is not enabled.');
        }

        $before = $this->isEnabled();
        $this->config->setAppValue(self::CIRCLES_APP, self::KEY, $enabled ? '1' : '0');

        if ($before !== $enabled) {
            // Instance-scoped: this is about the server, not about a team. See
            // AuditService::INSTANCE_SCOPE.
            $this->auditService->log(
                AuditService::INSTANCE_SCOPE,
                'instance.teams_frontend_changed',
                $actor,
                'app',
                self::CIRCLES_APP,
                ['enabled' => $enabled, 'previous' => $before],
            );

            $this->logger->info('[TeamHub][TeamsFrontendService] Teams app frontend switched', [
                'enabled' => $enabled,
                'actor'   => $actor,
                'app'     => Application::APP_ID,
            ]);
        }

        return $this->status();
    }

    /**
     * Circles' three-step resolution, in Circles' order: app value, then the
     * system value, then its own default.
     */
    private function rawValue(): string {
        $value = (string)$this->config->getAppValue(self::CIRCLES_APP, self::KEY, '');
        if ($value !== '') {
            return $value;
        }

        $value = (string)$this->config->getSystemValue(self::SYSTEM_KEY, '');
        if ($value !== '') {
            return $value;
        }

        return self::DEFAULT_RAW;
    }

    /**
     * Full Nextcloud administrator, as every instance-governance control in
     * this app requires — the same gate `PolicyService` applies, and for the
     * same reason: a delegated TeamHub admin passes the controller attribute
     * and is refused here.
     */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }

        return $user->getUID();
    }
}
