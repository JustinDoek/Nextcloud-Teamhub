<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Capability detection: the ordered list of things that can be wrong, the
 * probe, and what is cached.
 */
class OpenProjectCapabilityServiceTest extends OpenProjectTestCase {

    private function messages(): OpenProjectMessages {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s) => $s);
        return new OpenProjectMessages($l);
    }

    private function service(?object $op, ?string $uid = 'alice', bool $installed = true, bool $enabled = true): OpenProjectCapabilityService {
        $client = $this->client($op, $uid, $installed, $enabled);
        $cache  = $this->cache();
        return new OpenProjectCapabilityService(
            $client,
            $this->moduleService(),
            $cache,
            $this->messages(),
            // v4.9.6 — the second probe ("may create projects") rides the same client.
            new OpenProjectProvisioningService($client, $cache, $this->createMock(LoggerInterface::class)),
            $this->userSession($uid),
        );
    }

    public function testNotInstalledReportsEveryFlagFalseAndTheCode(): void {
        $caps = $this->service(null, installed: false)->getCapabilities();

        $this->assertFalse($caps['integrationAppInstalled']);
        $this->assertFalse($caps['integrationAppEnabled']);
        $this->assertFalse($caps['hostConfigured']);
        $this->assertFalse($caps['userConnected']);
        $this->assertFalse($caps['projectReadAvailable']);
        $this->assertFalse($caps['provisioningAvailable']);
        $this->assertSame(OpenProjectException::INTEGRATION_NOT_INSTALLED, $caps['errorCode']);
        $this->assertNotEmpty($caps['userMessage']);
        $this->assertNotEmpty($caps['administratorMessage']);
        $this->assertNull($caps['host']);
    }

    /**
     * v4.9.16 — the module's facts lead the envelope, the app's are reported
     * raw beside them, and the code is the module's.
     */
    public function testASwitchedOffModuleIsTheFirstThingReported(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->withModuleOff();

        $caps = $this->service($this->opService(null))->getCapabilities();

        $this->assertTrue($caps['moduleLicensed']);
        $this->assertFalse($caps['moduleEnabled']);
        $this->assertFalse($caps['moduleAvailable']);
        $this->assertTrue($caps['integrationAppInstalled']);
        $this->assertTrue($caps['integrationAppEnabled'], 'the app is on; only the module is off');
        $this->assertFalse($caps['userConnected'], 'nothing past the module is consulted');
        $this->assertSame(OpenProjectException::MODULE_DISABLED, $caps['errorCode']);
        $this->assertNotEmpty($caps['administratorMessage']);
    }

    public function testAnUnlicensedModuleReportsTheLicence(): void {
        $this->withHost();
        $this->withUnlicensed();

        $caps = $this->service($this->opService(null))->getCapabilities();

        $this->assertFalse($caps['moduleLicensed']);
        $this->assertTrue($caps['moduleEnabled'], 'the switch is reported as stored');
        $this->assertFalse($caps['moduleAvailable']);
        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $caps['errorCode']);
    }

    public function testDisabledAndUnconfiguredHostComeInOrder(): void {
        $caps = $this->service($this->opService(null), enabled: false)->getCapabilities();
        $this->assertTrue($caps['integrationAppInstalled']);
        $this->assertSame(OpenProjectException::INTEGRATION_DISABLED, $caps['errorCode']);

        $caps = $this->service($this->opService(null))->getCapabilities();
        $this->assertTrue($caps['integrationAppEnabled']);
        $this->assertSame(OpenProjectException::HOST_NOT_CONFIGURED, $caps['errorCode']);
    }

    public function testUnconnectedUserIsReportedWithoutAProbe(): void {
        $this->withHost();
        $called = false;
        $caps = $this->service($this->opService(function () use (&$called) {
            $called = true;
            return ['_type' => 'User'];
        }))->getCapabilities(probe: true);

        $this->assertTrue($caps['hostConfigured']);
        $this->assertSame(self::HOST, $caps['host']);
        $this->assertFalse($caps['userConnected']);
        $this->assertSame(OpenProjectException::USER_NOT_CONNECTED, $caps['errorCode']);
        $this->assertFalse($called, 'no request is made for an unconnected user');
    }

    public function testConnectedUserWithoutProbeIsOptimistic(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $caps = $this->service($this->opService(null))->getCapabilities(probe: false);

        $this->assertTrue($caps['userConnected']);
        $this->assertNull($caps['apiReachable'], 'not probed: unknown, not false');
        $this->assertTrue($caps['projectReadAvailable']);
        $this->assertNull($caps['errorCode']);
        $this->assertFalse($caps['probed']);
    }

    public function testProbeProvesTheConnection(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $caps = $this->service($this->opService(fn (string $uid, string $ep, array $params, string $method) => $ep === 'users/me'
            ? ['_type' => 'User', 'id' => 3, 'name' => 'Alice <i>E</i>']
            : ($ep === 'projects/form' && $method === 'POST'
                ? ['_type' => 'Form']
                : ['error' => 'unexpected', 'statusCode' => 500])))->getCapabilities(probe: true);

        $this->assertTrue($caps['probed']);
        $this->assertTrue($caps['provisioningAvailable'], 'v4.9.6 — the creation form was offered');
        $this->assertTrue($caps['apiReachable']);
        $this->assertTrue($caps['userConnected']);
        $this->assertSame(['id' => 3, 'name' => 'Alice E'], $caps['openProjectUser']);
        $this->assertNull($caps['errorCode']);
    }

    public function testProbeAuthFailureFlipsUserConnected(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $caps = $this->service($this->opService(fn () => ['error' => '', 'message' => 'x', 'statusCode' => 401]))
            ->getCapabilities(probe: true);

        $this->assertSame(OpenProjectException::AUTH_FAILED, $caps['errorCode']);
        $this->assertTrue($caps['apiReachable'], 'OpenProject answered — it just said no');
        $this->assertFalse($caps['userConnected']);
        $this->assertFalse($caps['projectReadAvailable']);
    }

    public function testProbeConnectionFailureMarksApiUnreachable(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $caps = $this->service($this->opService(fn () => ['error' => 'cURL error 28', 'statusCode' => 404]))
            ->getCapabilities(probe: true);

        $this->assertSame(OpenProjectException::API_UNAVAILABLE, $caps['errorCode']);
        $this->assertFalse($caps['apiReachable']);
        $this->assertTrue($caps['userConnected'], 'a stored connection is not disproved by a network failure');
    }

    public function testProbeResultIsCachedUntilForced(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $calls = 0;
        $service = $this->service($this->opService(function (string $uid, string $ep) use (&$calls) {
            if ($ep === 'users/me') {
                $calls++;
            }
            return $ep === 'users/me'
                ? ['_type' => 'User', 'id' => 1, 'name' => 'A']
                : ['error' => '', 'message' => 'no', 'statusCode' => 403];
        }));

        $service->getCapabilities(probe: true);
        $service->getCapabilities(probe: true);
        $this->assertSame(1, $calls, 'second probe served from cache');

        $service->getCapabilities(probe: true, force: true);
        $this->assertSame(2, $calls, 'force bypasses the cache');
    }

    public function testRequireReadableThrowsTheEnvironmentProblem(): void {
        $service = $this->service($this->opService(null), installed: false);
        $this->expectException(OpenProjectException::class);
        $service->requireReadable();
    }

    public function testRequireReadableReturnsTheUser(): void {
        $this->withHost();
        $this->assertSame('alice', $this->service($this->opService(null))->requireReadable());
    }
}
