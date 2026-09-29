<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;

/**
 * The licence tier over a settable word (WorkflowHub phase 4 tests).
 *
 * Only the lookup is replaced — `LicenseService` and its JWT are not part
 * of these tests, `LicenseGraceTest` owns that ladder. Everything built on
 * the tier (`can()`, `require()`, `retainsEnded()`, `capabilities()`) is
 * inherited, so the rules under test are the ones that ship.
 *
 * `set()` changes the answer mid-test: that is a licence being activated
 * or expiring while a workflow is running, which is exactly what the
 * transition rules are about.
 */
class FakeLicenceTier extends WorkflowLicenceTier {

    public function __construct(
        private string $current = self::FULL,
    ) {
        // No LicenseService.
    }

    public function set(string $tier): void {
        $this->current = $tier;
    }

    public function tier(): string {
        return $this->current;
    }

    public function enforcementLevel(): string {
        return $this->current === self::FULL ? 'none' : 'unlicensed';
    }
}
