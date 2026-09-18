<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\LicenseService;
use OCP\IConfig;

/**
 * Whether the OpenProject module exists on this instance at all (v4.9.16).
 *
 * Two things have to hold, and they are checked in the order an administrator
 * would fix them:
 *
 *  1. **A licence.** The module is a licensed TeamHub feature, exactly like
 *     File reviews: `LicenseService::hasLicenseKey()` first (one appconfig
 *     read, so an unlicensed instance pays nothing on any of the hot paths
 *     below), then an enforcement level of `none` or `grace` — the same two
 *     levels My Work admits, and for the same reason: a lapsed licence inside
 *     its window must not make a team's project disappear overnight.
 *  2. **The administrator's switch.** `openproject_module_enabled`, **default
 *     off** (Justin, 2026-09-15: an administrator enables it when they have an
 *     OpenProject environment and leaves it off when they do not). Presence and
 *     Decisions default on because every instance can use them; this one is
 *     useless without an OpenProject to talk to, so off is the honest default.
 *
 * ## Where it is consulted
 *
 * `OpenProjectClient::isIntegrationEnabled()` and `compatibilityProblem()`
 * ask this first, so every surface that already asks the client — the My Work
 * provider, the news and meetings services, the capability probe, the layout
 * bundle's facts, and the client's own `send()` — follows without knowing the
 * module exists. A switched-off module can therefore never reach OpenProject.
 * The controllers gate explicitly on top (`moduleGate()`), because "the
 * frontend will not call it" is not a boundary.
 *
 * ## What switching off does not do
 *
 * Nothing is deleted. Links, ledgers, provisioning records and the template
 * row stay; widgets, rows, tabs and the wizard card disappear; switching back
 * on brings all of it back. The Maintenance unlink and the administrator's
 * template editor keep working, so an instance can be tidied with the module
 * off.
 */
class OpenProjectModuleService {

    /** appconfig key of the administrator's switch. '1' = on; absent = off. */
    public const CONFIG_ENABLED = 'openproject_module_enabled';

    public function __construct(
        private IConfig        $config,
        private LicenseService $licenseService,
    ) {
    }

    /**
     * The licence half. `hasLicenseKey()` first — see the class docblock and
     * `FileReviewService::isEnabledGlobally()`, which this mirrors.
     */
    public function isLicensed(): bool {
        if (!$this->licenseService->hasLicenseKey()) {
            return false;
        }
        $level = $this->licenseService->getEnforcementLevel();
        return $level === 'none' || $level === 'grace';
    }

    /** The administrator's switch, read as stored. Says nothing about the licence. */
    public function isEnabledByAdmin(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '0') === '1';
    }

    public function setEnabledByAdmin(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::CONFIG_ENABLED, $enabled ? '1' : '0');
    }

    /** Both halves. The one predicate the rest of the integration asks. */
    public function isAvailable(): bool {
        return $this->unavailableCode() === null;
    }

    /**
     * Why the module is unavailable, as an `OpenProjectException` code, or
     * null when it is available. Licence before switch: a switched-on
     * unlicensed module is unlicensed, and the switch is not shown until a
     * licence is present.
     */
    public function unavailableCode(): ?string {
        if (!$this->isLicensed()) {
            return OpenProjectException::MODULE_UNLICENSED;
        }
        if (!$this->isEnabledByAdmin()) {
            return OpenProjectException::MODULE_DISABLED;
        }
        return null;
    }

    /**
     * The three facts for the admin panel and the capability envelope.
     *
     * @return array{licensed: bool, enabled: bool, available: bool}
     */
    public function describe(): array {
        $licensed = $this->isLicensed();
        $enabled  = $this->isEnabledByAdmin();
        return [
            'licensed'  => $licensed,
            'enabled'   => $enabled,
            'available' => $licensed && $enabled,
        ];
    }
}
