<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowAttachmentMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The paperclip's own rules (v4.10.38, `docs/service-builder.md` § 7): what
 * a request's settings are, and when a share it makes ends — TeamHub applies
 * to a team share the rules Nextcloud applies to a user share (G4/G5).
 */
class WorkflowShareServiceTest extends TestCase {

    /** 2026-09-25 10:00:00 UTC */
    private const NOW = 1790330400;

    public function testSettingsDefaultToViewOnlyForTwoWeeks(): void {
        $this->assertSame(['allowed' => true, 'edit' => false, 'days' => 14], WorkflowShareService::settingsOf([]));
        $this->assertSame(['allowed' => false, 'edit' => true, 'days' => 3],
            WorkflowShareService::settingsOf(['fileSharing' => ['allowed' => false, 'edit' => true, 'days' => 3]]));
        $this->assertSame(365, WorkflowShareService::settingsOf(['fileSharing' => ['days' => 9999]])['days'], 'never past the maximum');
    }

    public function testAShareEndsAtTheLastSecondOfItsLastDay(): void {
        $expiry = $this->service(false, 0)->expiryFor(14);
        $this->assertSame('2026-10-09 23:59:59', $expiry->format('Y-m-d H:i:s'));
    }

    public function testTheAdministratorsEnforcedMaximumCapsIt(): void {
        $this->assertSame('2026-10-02 23:59:59', $this->service(true, 7)->expiryFor(30)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 23:59:59', $this->service(true, 7)->expiryFor(3)->format('Y-m-d H:i:s'), 'a shorter one stays');
    }

    public function testNothingPickedIsNothingToShare(): void {
        $this->assertSame([], $this->service(false, 0)->resolve('jaap', []));
        $this->assertSame([], $this->service(false, 0)->resolve('jaap', null));
    }

    public function testTooManyFilesOrAnythingButIdsIsRefused(): void {
        foreach ([range(1, WorkflowShareService::MAX_FILES + 1), 'abc', [['x']], ['1; drop']] as $bad) {
            try {
                $this->service(false, 0)->resolve('jaap', $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function service(bool $enforced, int $maxDays): WorkflowShareService {
        $shares = $this->createMock(IShareManager::class);
        $shares->method('shareApiInternalDefaultExpireDateEnforced')->willReturn($enforced);
        $shares->method('shareApiInternalDefaultExpireDays')->willReturn($maxDays);
        $tz = $this->createMock(IDateTimeZone::class);
        $tz->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $n): string => $n === 1 ? $one : $many);
        return new WorkflowShareService(
            $this->createMock(WorkflowAttachmentMapper::class),
            $this->createMock(IRootFolder::class),
            $shares,
            $tz,
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
        );
    }
}
