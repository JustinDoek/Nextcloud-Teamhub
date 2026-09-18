<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;

/**
 * The module gate itself (v4.9.16): the default, the order of the two
 * checks, and what each half reports.
 */
class OpenProjectModuleServiceTest extends OpenProjectTestCase {

    private function service(bool $hasKey = true, string $level = 'none'): OpenProjectModuleService {
        $license = $this->createMock(LicenseService::class);
        $license->method('hasLicenseKey')->willReturn($hasKey);
        $license->method('getEnforcementLevel')->willReturn($level);
        return new OpenProjectModuleService($this->config(), $license);
    }

    public function testTheSwitchIsOffByDefault(): void {
        unset($this->appValues['teamhub'][OpenProjectModuleService::CONFIG_ENABLED]);
        $service = $this->service();

        $this->assertFalse($service->isEnabledByAdmin());
        $this->assertFalse($service->isAvailable());
        $this->assertSame(OpenProjectException::MODULE_DISABLED, $service->unavailableCode());
    }

    public function testLicensedAndSwitchedOnIsAvailable(): void {
        $service = $this->service();
        $this->assertTrue($service->isAvailable());
        $this->assertNull($service->unavailableCode());
        $this->assertSame(['licensed' => true, 'enabled' => true, 'available' => true], $service->describe());
    }

    public function testGraceStillCounts(): void {
        $this->assertTrue($this->service(level: 'grace')->isLicensed());
    }

    public function testSoftLockAndUnlicensedDoNot(): void {
        $this->assertFalse($this->service(level: 'soft-lock')->isLicensed());
        $this->assertFalse($this->service(level: 'unlicensed')->isLicensed());
    }

    public function testNoKeyIsAnsweredWithoutTheEnforcementCheck(): void {
        $license = $this->createMock(LicenseService::class);
        $license->method('hasLicenseKey')->willReturn(false);
        $license->expects($this->never())->method('getEnforcementLevel');
        $service = new OpenProjectModuleService($this->config(), $license);

        $this->assertFalse($service->isLicensed());
        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $service->unavailableCode());
    }

    public function testTheLicenceIsReportedBeforeTheSwitch(): void {
        $this->withModuleOff();
        $service = $this->service(hasKey: false);

        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $service->unavailableCode());
        $this->assertSame(['licensed' => false, 'enabled' => false, 'available' => false], $service->describe());
    }

    public function testTheSwitchIsWrittenAndReadBack(): void {
        $service = $this->service();
        $service->setEnabledByAdmin(false);
        $this->assertSame('0', $this->appValues['teamhub'][OpenProjectModuleService::CONFIG_ENABLED]);
        $this->assertFalse($service->isEnabledByAdmin());

        $service->setEnabledByAdmin(true);
        $this->assertTrue($service->isEnabledByAdmin());
        $this->assertTrue($service->isAvailable());
    }
}
