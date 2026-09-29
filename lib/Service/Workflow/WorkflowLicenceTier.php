<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Workflow\WorkflowCapability;

/**
 * The one place the workflow code asks about the licence (WorkflowHub
 * phase 4, v4.10.16; `docs/workflowhub-architecture.md` §10,
 * `docs/unlicensed-workflow-data-lifecycle.md`).
 *
 * The instance is either licensed or unlicensed, never per user, team or
 * workflow. `tier()` folds `LicenseService`'s enforcement ladder into the
 * two words the engine understands: `full` while a key is valid or a paid
 * key is inside its grace window (`none` / `grace`), `basic` otherwise
 * (`soft-lock`, `unlicensed`). The ladder itself — fourteen days for a
 * paid key, none for a trial (DESIGN §2.140) — is `LicenseService`'s;
 * nothing here re-implements it.
 *
 * The answer is memoised by `LicenseService::getStatus()` per request, so
 * the engine may ask on every call. It is *not* memoised across requests:
 * a licence change takes effect on the next request, and an instance's
 * tier is evaluated at the moment of each read or write, never stored on
 * the instance. That is what lets a workflow started on one tier finish
 * on the other (the transition rules in the lifecycle document).
 *
 * `WorkflowEngine` is the only enforcement point; the controller only
 * reports `tier()` and `capabilities()` so the client can hide what is not
 * available.
 */
class WorkflowLicenceTier {

    public const BASIC = 'basic';
    public const FULL  = 'full';

    public function __construct(
        private LicenseService $license,
    ) {
    }

    /** `full` or `basic`, for this request. */
    public function tier(): string {
        $level = $this->license->getEnforcementLevel();
        return ($level === 'none' || $level === 'grace') ? self::FULL : self::BASIC;
    }

    public function isFull(): bool {
        return $this->tier() === self::FULL;
    }

    /** Whether this tier has the capability (`WorkflowCapability::*`). */
    public function can(string $capability): bool {
        return WorkflowCapability::forTier($this->tier())[$capability] ?? false;
    }

    /**
     * Refuse with the licence-gate exception the controllers map to
     * 403 + `licenseGate: true` when the tier lacks the capability.
     *
     * @throws LicenseGateException
     */
    public function require(string $capability, string $message): void {
        if (!$this->can($capability)) {
            throw new LicenseGateException($this->enforcementLevel(), $message);
        }
    }

    /**
     * Whether an ended workflow's rows stay. On the unlicensed tier they
     * do not: completion is the last thing the workflow does, and its rows
     * are removed in the same transaction (the lifecycle document).
     */
    public function retainsEnded(): bool {
        return $this->can(WorkflowCapability::COMPLETED_HISTORY);
    }

    /** @return array<string, bool> capability → allowed, for the client */
    public function capabilities(): array {
        return WorkflowCapability::forTier($this->tier());
    }

    /** The raw enforcement level, for the gate exception and diagnostics. */
    public function enforcementLevel(): string {
        return $this->license->getEnforcementLevel();
    }
}
