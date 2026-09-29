<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\MyWork;

use OCA\TeamHub\MyWork\Category;
use PHPUnit\Framework\TestCase;

/**
 * The My Work category vocabulary after v4.10.20, which retired TEAM_ADMIN
 * and folded it into ACTION_REQUIRED (DESIGN.md 2.143).
 */
class CategoryTest extends TestCase {

    public function testTeamAdminIsNotACategoryAnyMore(): void {
        $this->assertFalse(
            Category::isValid('team_admin'),
            'the retired name must not validate, or it would reach a group heading with no label',
        );
        $this->assertNotContains('team_admin', Category::ORDERED);
        $this->assertFalse(defined(Category::class . '::TEAM_ADMIN'));
    }

    public function testTheOrderIsTheFiveRemainingCategories(): void {
        $this->assertSame([
            Category::ACTION_REQUIRED,
            Category::TODAY,
            Category::UPCOMING,
            Category::WAITING_FOR_OTHERS,
            Category::COMPLETED,
        ], Category::ORDERED);
    }

    public function testActionRequiredNowOutranksWaitingForOthers(): void {
        // The point of the merge: role work used to rank *below* work the
        // viewer could not act on at all.
        $this->assertLessThan(
            Category::rank(Category::WAITING_FOR_OTHERS),
            Category::rank(Category::ACTION_REQUIRED),
        );
    }

    public function testARetiredNameMigratesForward(): void {
        $this->assertSame(Category::ACTION_REQUIRED, Category::migrate('team_admin'));
        // Anything else passes through untouched for the caller to validate.
        $this->assertSame(Category::COMPLETED, Category::migrate(Category::COMPLETED));
        $this->assertSame('nonsense', Category::migrate('nonsense'));
    }
}
