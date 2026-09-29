<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\WorkflowCapability;
use PHPUnit\Framework\TestCase;

/**
 * The one decision point (WorkflowHub phase 4, v4.10.16): which of the two
 * tiers an enforcement level is, and what each tier may do.
 *
 * The ladder itself belongs to `LicenseService` (`LicenseGraceTest`): this
 * pins only the fold — a paid key inside its fourteen days of grace is
 * still a licensed instance, a soft-locked or keyless one is not — and the
 * capability table the product named, capability by capability.
 */
class WorkflowLicenceTierTest extends TestCase {

    private function tier(string $level): WorkflowLicenceTier {
        $license = $this->createMock(LicenseService::class);
        $license->method('getEnforcementLevel')->willReturn($level);
        return new WorkflowLicenceTier($license);
    }

    public function testAValidKeyAndAGracePeriodAreBothLicensed(): void {
        self::assertSame(WorkflowLicenceTier::FULL, $this->tier('none')->tier());
        self::assertSame(WorkflowLicenceTier::FULL, $this->tier('grace')->tier(), 'grace is still a licensed instance');
        self::assertTrue($this->tier('grace')->isFull());
    }

    public function testSoftLockAndNoKeyAreBothUnlicensed(): void {
        self::assertSame(WorkflowLicenceTier::BASIC, $this->tier('soft-lock')->tier());
        self::assertSame(WorkflowLicenceTier::BASIC, $this->tier('unlicensed')->tier());
        self::assertFalse($this->tier('soft-lock')->isFull());
    }

    public function testAnUnknownLevelIsTreatedAsUnlicensed(): void {
        // Fail closed: a level nobody recognises must not hand out the
        // licensed capabilities.
        self::assertSame(WorkflowLicenceTier::BASIC, $this->tier('something-new')->tier());
    }

    public function testTheUnlicensedInstanceHasExactlyTheSixProductCapabilities(): void {
        $tier = $this->tier('unlicensed');
        $has  = array_keys(array_filter($tier->capabilities()));
        self::assertEqualsCanonicalizing([
            WorkflowCapability::VIEW_ACTION_REQUIRED,
            WorkflowCapability::VIEW_WAITING_FOR_OTHERS,
            WorkflowCapability::VIEW_RESPONSIBLE_ACTOR,
            WorkflowCapability::VIEW_AVAILABLE_ACTIONS,
            WorkflowCapability::VIEW_TIMELINE,
            WorkflowCapability::START_BUILT_IN,
        ], $has);
    }

    public function testTheUnlicensedInstanceHasNoneOfTheLicensedCapabilities(): void {
        $tier = $this->tier('soft-lock');
        foreach ([
            WorkflowCapability::VIEW_CURRENT_STEP,
            WorkflowCapability::VIEW_STEP_PROGRESS,
            WorkflowCapability::REQUEST_STATUS_UPDATE,
            WorkflowCapability::COMPLETED_HISTORY,
            WorkflowCapability::MANAGE_DEFINITIONS,
            WorkflowCapability::SERVICE_TEAMS,
            WorkflowCapability::ANALYTICS,
            WorkflowCapability::AUDIT_EXPORT,
            WorkflowCapability::REOPEN_COMPLETED,
            WorkflowCapability::ARCHIVE_RESULTS,
        ] as $capability) {
            self::assertFalse($tier->can($capability), $capability);
        }
    }

    public function testALicensedInstanceHasEveryCapability(): void {
        $tier = $this->tier('none');
        foreach (WorkflowCapability::ALL as $capability) {
            self::assertTrue($tier->can($capability), $capability);
        }
    }

    public function testAnUnknownCapabilityIsRefusedOnBothTiers(): void {
        self::assertFalse($this->tier('none')->can('invent_something'));
        self::assertFalse($this->tier('unlicensed')->can('invent_something'));
        self::assertFalse(WorkflowCapability::isValid('invent_something'));
    }

    public function testRequireThrowsTheLicenceGateWithTheEnforcementLevel(): void {
        $tier = $this->tier('soft-lock');
        try {
            $tier->require(WorkflowCapability::REQUEST_STATUS_UPDATE, 'needs a licence');
            self::fail('expected a licence gate');
        } catch (LicenseGateException $e) {
            self::assertSame('needs a licence', $e->getMessage());
            self::assertSame('soft-lock', $e->getEnforcementLevel());
        }
        // And says nothing when the tier has it.
        $this->tier('none')->require(WorkflowCapability::REQUEST_STATUS_UPDATE, 'needs a licence');
        self::assertTrue(true);
    }

    public function testOnlyALicensedInstanceRetainsEndedWorkflows(): void {
        self::assertTrue($this->tier('none')->retainsEnded());
        self::assertTrue($this->tier('grace')->retainsEnded());
        self::assertFalse($this->tier('soft-lock')->retainsEnded());
        self::assertFalse($this->tier('unlicensed')->retainsEnded());
    }
}
