<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\License;

use OCA\TeamHub\Service\LicenseService;
use PHPUnit\Framework\TestCase;

/**
 * The grace window after `exp` (v4.10.12), as the Commercial Licence and
 * Support Agreement states it: fourteen days for a paid key (art. 13.4),
 * none for a trial (art. 14.3). Pins the boundary too — grace is exactly
 * fourteen × 24 h, and the old `>` comparison that gave a thirty-first day
 * must not come back.
 */
class LicenseGraceTest extends TestCase {

    private const DAY = 86400;
    private const EXP = 1_800_000_000;

    public function testAPaidKeyIsEntitledToFourteenDays(): void {
        $this->assertSame(14, LicenseService::PAID_GRACE_DAYS);
        $this->assertSame(14, LicenseService::graceDaysFor(['kind' => 'business']));
        $this->assertSame(14, LicenseService::graceDaysFor(['is_trial' => false]));
    }

    public function testATrialIsEntitledToNone(): void {
        $this->assertSame(0, LicenseService::graceDaysFor(['is_trial' => true]));
    }

    public function testTheJwtGraceDaysClaimIsNotConsulted(): void {
        // The agreement sets the number, not the key.
        $this->assertSame(14, LicenseService::graceDaysFor(['grace_days' => 30]));
        $this->assertSame(0, LicenseService::graceDaysFor(['is_trial' => true, 'grace_days' => 30]));
    }

    public function testAPaidKeyIsInGraceOnTheDayItExpires(): void {
        [$level, $left, $days] = LicenseService::expiredLevel([], self::EXP, self::EXP + 1);
        $this->assertSame('grace', $level);
        $this->assertSame(14, $left);
        $this->assertSame(14, $days);
    }

    public function testAPaidKeyCountsDownThroughTheThirteenthDay(): void {
        [$level, $left] = LicenseService::expiredLevel([], self::EXP, self::EXP + 13 * self::DAY);
        $this->assertSame('grace', $level);
        $this->assertSame(1, $left);
    }

    public function testAPaidKeyIsSoftLockedOnTheFourteenthDay(): void {
        [$level, $left] = LicenseService::expiredLevel([], self::EXP, self::EXP + 14 * self::DAY);
        $this->assertSame('soft-lock', $level, 'the boundary is inclusive: fourteen days, not a thirty-first');
        $this->assertSame(0, $left);
    }

    public function testAPaidKeyStaysSoftLockedLater(): void {
        [$level] = LicenseService::expiredLevel([], self::EXP, self::EXP + 30 * self::DAY);
        $this->assertSame('soft-lock', $level);
    }

    public function testATrialIsSoftLockedTheMomentItExpires(): void {
        [$level, $left, $days] = LicenseService::expiredLevel(['is_trial' => true], self::EXP, self::EXP + 1);
        $this->assertSame('soft-lock', $level);
        $this->assertSame(0, $left);
        $this->assertSame(0, $days);
    }

    public function testATrialNeverReachesGrace(): void {
        foreach ([1, self::DAY, 13 * self::DAY] as $past) {
            [$level] = LicenseService::expiredLevel(['is_trial' => true], self::EXP, self::EXP + $past);
            $this->assertSame('soft-lock', $level, "trial {$past}s past expiry");
        }
    }
}
