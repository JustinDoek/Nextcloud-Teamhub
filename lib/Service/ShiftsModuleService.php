<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Is Shifts available here? (v4.13.68, DESIGN §2.234)
 *
 * ## Three gates, and they are not interchangeable
 *
 * Shifts is **part of the People module**, not a module of its own (Justin,
 * 2026-11-01). So:
 *
 *   1. **People must be on instance-wide** — `presence_module_enabled`. There
 *      is deliberately no separate instance switch for Shifts: an
 *      administrator who has turned People off has already answered this.
 *   2. **The instance must be licensed.** Shifts is a licensed feature, so an
 *      unlicensed instance sees the control greyed out with the reason,
 *      rather than a feature that silently does nothing.
 *   3. **The team must have switched it on** — `teamhub_presence_team.shifts_enabled`,
 *      default 0. A team that has never been told about Shifts does not
 *      acquire a roster on upgrade.
 *
 * `refuse()` names **which** gate is shut, and the order is licence first.
 * That is `SubTeamModuleService`'s rule and it matters for the same reason: a
 * customer who is told "switch it on" and then finds they cannot has been sent
 * on an errand, where "this needs a licence" is an answer they can act on.
 *
 * The per-team flag is read and written through `PresenceTeamService`, which
 * owns that row; this class never touches the table.
 */
class ShiftsModuleService {

    public function __construct(
        private IConfig             $config,
        private LicenseService      $licenseService,
        private PresenceTeamService $teamPresence,
        private IL10N               $l,
    ) {}

    /**
     * The licence half. `hasLicenseKey()` first, mirroring
     * `SubTeamModuleService::isLicensed()`.
     */
    public function isLicensed(): bool {
        if (!$this->licenseService->hasLicenseKey()) {
            return false;
        }
        $level = $this->licenseService->getEnforcementLevel();
        return $level === 'none' || $level === 'grace';
    }

    /** The People module's instance switch. Shifts has none of its own. */
    public function isPeopleEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') === '1';
    }

    /**
     * The team's own switch, read as stored.
     *
     * Says nothing about the licence: a team that switched Shifts on while
     * licensed keeps the stored yes, so an instance whose licence lapses and
     * returns finds its teams as they were.
     */
    public function isEnabledForTeam(string $teamId): bool {
        return (bool)($this->teamPresence->getConfig($teamId)['shifts_enabled'] ?? false);
    }

    /**
     * Whether the team admin may even see the switch.
     *
     * People on and licensed. The switch is rendered greyed rather than
     * hidden when the licence is the missing half, because "you could have
     * this" is the honest state for a licensed feature — unlike a permission,
     * which is hidden (CLAUDE.md).
     */
    public function isOfferable(): bool {
        return $this->isPeopleEnabled() && $this->isLicensed();
    }

    /** All three gates. The one predicate a surface asks before drawing. */
    public function isAvailable(string $teamId): bool {
        return $this->isOfferable() && $this->isEnabledForTeam($teamId);
    }

    /**
     * Refuse, naming which half is missing. Licence before switch.
     *
     * @throws AccessDeniedException
     */
    public function requireAvailable(string $teamId): void {
        if (!$this->isPeopleEnabled()) {
            throw new AccessDeniedException($this->l->t('The People module is switched off for this instance.'));
        }
        if (!$this->isLicensed()) {
            throw new AccessDeniedException($this->l->t('Shifts needs a TeamHub licence.'));
        }
        if (!$this->isEnabledForTeam($teamId)) {
            throw new AccessDeniedException($this->l->t('Shifts is not switched on for this team.'));
        }
    }

    /**
     * Why the switch is greyed, or null when it is not.
     *
     * The shape `ManageTeamView` already uses for a licensed control:
     * 'unlicensed' or 'disabled', never a sentence the client has to parse.
     */
    public function unavailableReason(): ?string {
        if (!$this->isPeopleEnabled()) {
            return 'disabled';
        }
        if (!$this->isLicensed()) {
            return 'unlicensed';
        }
        return null;
    }
}
