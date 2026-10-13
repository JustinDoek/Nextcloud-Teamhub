<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCP\IConfig;

/**
 * Whether the Sub-teams module exists on this instance at all (v4.12.3).
 *
 * Two things have to hold, and they are checked in the order an administrator
 * would fix them:
 *
 *  1. **A licence.** Sub-teams are a licensed TeamHub module, exactly like
 *     OpenProject and File reviews: `LicenseService::hasLicenseKey()` first
 *     (one appconfig read, so an unlicensed instance pays nothing on the hot
 *     paths below), then an enforcement level of `none` or `grace` — the same
 *     two levels My Work admits, and for the same reason: a lapsed licence
 *     inside its window must not make a team's structure rearrange itself
 *     overnight.
 *  2. **The administrator's switch.** `sub_teams_module_enabled`, **default
 *     on** (Justin, 2026-10-12). Unlike OpenProject, which needs an
 *     OpenProject to talk to, and unlike Service teams, which change how an
 *     organisation asks each other for things, a sub-team needs nothing but a
 *     team — so a licensed instance has them from the moment it upgrades, and
 *     an administrator who does not want that shape switches them off.
 *
 * ## Why this is its own class and not a method on `SubTeamService`
 *
 * `SubTeamService` depends on `TeamService`, and `TeamService` needs this
 * gate too — for `can_have_sub_teams` on both team payloads and for the
 * sidebar nesting. A method on `SubTeamService` would therefore mean
 * `TeamService` depending on `SubTeamService`, which is the cycle
 * `SubTeamMapper`'s docblock already warns about. This class injects
 * `IConfig` and `LicenseService` and nothing else, so it is a leaf both can
 * reach. `OpenProjectModuleService` exists for the same reason.
 *
 * ## What "unavailable" hides, and what it deliberately does not
 *
 * Justin's call, 2026-10-12, taking the Service-teams shape over a
 * creation-only gate: **the surfaces go, the rows stay.**
 *
 * Hidden or refused while unavailable:
 *
 *   - every sub-team row in the sidebar — nested ones and the `listed`
 *     siblings alike (`TeamService::getSidebarTeams()`);
 *   - the Manage team → Sub-teams tab, because `can_have_sub_teams` is false
 *     on every team (the frontend hides the tab on exactly that field);
 *   - creating one, listing a parent's own, and the candidate picker — all
 *     three routes answer 403 (`SubTeamController`, `SubTeamService`).
 *
 * **Not** touched, on purpose:
 *
 *   - `getUserTeams()` keeps every sub-team in its flat list. It documents
 *     itself as the single authorisation boundary for My Work, and
 *     `FileActionController` / `FileReviewController` scope the Files-app
 *     actions off the same call. Filtering there would not hide a surface; it
 *     would strand a member's open work items and reviews, and a switch an
 *     administrator can flip back must not lose anything.
 *   - the structural predicates — `SubTeamMapper::isSubTeam()`,
 *     `parentOf()`, `MemberService`'s fall-back to a parent admin,
 *     `SubTeamService::assertNotSubTeam()`. A sub-team whose module is off is
 *     still a sub-team: its parent's admins are still its only
 *     administrators, and it still may not be transferred, made a service
 *     desk, filed into a sidebar group or bulk-imported into. Gating those
 *     would leave an existing sub-team with no administrator at all and its
 *     exclusions silently lifted — a security regression dressed as a
 *     feature switch.
 *
 * **The accepted consequence** (stated when the choice was made, DESIGN
 * §2.177): somebody who was invited into a private sub-team from outside the
 * main team, and is in no other team, opens TeamHub to an empty sidebar while
 * the module is off. Their team is intact and comes back with the switch;
 * nothing of theirs is deleted.
 *
 * Nothing is deleted either way — the `teamhub_sub_team` rows, the storage,
 * the members and the messages all stay, and switching back on brings every
 * surface back exactly as it was.
 */
class SubTeamModuleService {

    /** appconfig key of the administrator's switch. '0' = off; absent = on. */
    public const CONFIG_ENABLED = 'sub_teams_module_enabled';

    public function __construct(
        private IConfig        $config,
        private LicenseService $licenseService,
    ) {
    }

    /**
     * The licence half. `hasLicenseKey()` first — see the class docblock and
     * `OpenProjectModuleService::isLicensed()`, which this mirrors.
     */
    public function isLicensed(): bool {
        if (!$this->licenseService->hasLicenseKey()) {
            return false;
        }
        $level = $this->licenseService->getEnforcementLevel();
        return $level === 'none' || $level === 'grace';
    }

    /**
     * The administrator's switch, read as stored. Says nothing about the
     * licence — the stored default of on is inert on an unlicensed instance,
     * which is what lets an instance that licenses later find the module
     * already on, as a default-on module should be.
     */
    public function isEnabledByAdmin(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '1') === '1';
    }

    public function setEnabledByAdmin(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::CONFIG_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Both halves. The one predicate every surface asks, and the only one
     * that may be used to decide whether to *show* something — never to
     * decide who administers an existing sub-team (class docblock).
     */
    public function isAvailable(): bool {
        return $this->isLicensed() && $this->isEnabledByAdmin();
    }

    /**
     * Refuse, naming which half is missing. Licence before switch: a
     * switched-on unlicensed module is unlicensed, and the admin panel does
     * not show the switch until a licence is present, so naming the licence
     * first names what an administrator fixes first.
     *
     * Both answer 403 — `LicenseGateException` carries `licenseGate: true` so
     * the client can offer "renew" rather than a validation error.
     *
     * @throws LicenseGateException   no licence, or one outside its window
     * @throws AccessDeniedException  licensed, switched off
     */
    public function requireAvailable(): void {
        if (!$this->isLicensed()) {
            throw new LicenseGateException(
                $this->licenseService->getEnforcementLevel(),
                'Sub-teams require an active TeamHub license.',
            );
        }
        if (!$this->isEnabledByAdmin()) {
            throw new AccessDeniedException('Sub-teams are switched off on this instance.');
        }
    }

    /** Reasons {@see unavailableReason()} answers with. */
    public const UNAVAILABLE_UNLICENSED = 'unlicensed';
    public const UNAVAILABLE_DISABLED   = 'disabled';

    /**
     * *Why* the module is unavailable, or null when it is not (v4.12.5).
     *
     * The team payload carries this so Manage team can grey its Sub-teams tab
     * and say which of the two it is — Justin's call, 2026-10-12: a tab that
     * is simply absent teaches a team admin nothing, and the two cases need
     * different sentences. An unlicensed instance is a sales conversation; a
     * switched-off one is a conversation with their own administrator.
     *
     * Licence first, the same precedence {@see requireAvailable()} uses: a
     * switched-on unlicensed module is unlicensed, and naming the licence
     * first names what gets fixed first.
     *
     * **Still not a substitute for `isAvailable()`.** This says what to tell
     * somebody; `isAvailable()` decides whether a surface exists at all, and
     * neither may be used to decide who administers an existing sub-team (see
     * the class docblock).
     */
    public function unavailableReason(): ?string {
        if (!$this->isLicensed()) {
            return self::UNAVAILABLE_UNLICENSED;
        }
        if (!$this->isEnabledByAdmin()) {
            return self::UNAVAILABLE_DISABLED;
        }
        return null;
    }

    /**
     * The three facts for the admin panel and any capability envelope.
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
