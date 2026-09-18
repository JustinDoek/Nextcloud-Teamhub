<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;

/**
 * The adapter over the official app: environment detection, error
 * classification, URL hygiene, and what never reaches the log.
 */
class OpenProjectClientTest extends OpenProjectTestCase {

    // ── Environment ────────────────────────────────────────────────────

    public function testNotInstalledIsTheFirstProblem(): void {
        $client = $this->client(null, installed: false);
        $this->assertFalse($client->isIntegrationInstalled());
        $this->assertFalse($client->isIntegrationEnabled());
        $this->assertSame(OpenProjectException::INTEGRATION_NOT_INSTALLED, $client->compatibilityProblem());
    }

    public function testInstalledButDisabled(): void {
        $this->withHost();
        $client = $this->client($this->opService(null), enabled: false);
        $this->assertTrue($client->isIntegrationInstalled());
        $this->assertSame(OpenProjectException::INTEGRATION_DISABLED, $client->compatibilityProblem());
    }

    public function testServiceThatCannotBeResolvedIsIncompatible(): void {
        $this->withHost();
        $client = $this->client(null);
        $this->assertSame(OpenProjectException::INTEGRATION_INCOMPATIBLE, $client->compatibilityProblem());
    }

    public function testServiceWithoutRequestMethodIsIncompatible(): void {
        $this->withHost();
        $client = $this->client(new \stdClass());
        $this->assertSame(OpenProjectException::INTEGRATION_INCOMPATIBLE, $client->compatibilityProblem());
        $this->assertFalse($client->hasUsableService());
    }

    public function testMissingOrInvalidHostIsReported(): void {
        $client = $this->client($this->opService(null));
        $this->assertSame('', $client->getHost());
        $this->assertSame(OpenProjectException::HOST_NOT_CONFIGURED, $client->compatibilityProblem());

        $this->withHost('ftp://not-http');
        $this->assertSame('', $this->client($this->opService(null))->getHost());

        $this->withHost('https://op.example.test/');
        $this->assertSame('https://op.example.test', $this->client($this->opService(null))->getHost(), 'trailing slash removed');
    }

    public function testUsableEnvironmentHasNoProblem(): void {
        $this->withHost();
        $this->assertNull($this->client($this->opService(null))->compatibilityProblem());
    }

    // ── The module gate (v4.9.16) ──────────────────────────────────────

    public function testASwitchedOffModuleComesBeforeEverythingAboutTheApp(): void {
        $this->withHost();
        $this->withModuleOff();
        $client = $this->client($this->opService(null));

        $this->assertFalse($client->isModuleAvailable());
        $this->assertTrue($client->isIntegrationAppEnabled(), 'the app\'s own state is still reported honestly');
        $this->assertFalse($client->isIntegrationEnabled(), 'but the integration is not usable');
        $this->assertSame(OpenProjectException::MODULE_DISABLED, $client->compatibilityProblem());
        $this->assertFalse($client->hasUsableService(), 'a switched-off module never resolves the official app\'s service');
    }

    public function testAnUnlicensedModuleOutranksTheSwitchAndTheMissingApp(): void {
        $this->withUnlicensed();
        // Switch on, app not even installed: the licence is still what is reported.
        $client = $this->client(null, installed: false);
        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $client->compatibilityProblem());

        $this->withModuleOff();
        $this->assertSame(OpenProjectException::MODULE_UNLICENSED, $this->client(null, installed: false)->compatibilityProblem());
    }

    public function testASwitchedOffModuleNeverContactsOpenProject(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->withModuleOff();
        $called = false;
        $client = $this->client($this->opService(function () use (&$called) {
            $called = true;
            return ['_type' => 'User'];
        }));

        try {
            $client->get('alice', 'users/me');
            $this->fail('expected the module gate to refuse');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::MODULE_DISABLED, $e->getErrorCode());
            $this->assertTrue($e->isConfigurationProblem());
        }
        $this->assertFalse($called);
    }

    public function testUserConnectionIsReadFromTheOfficialAppsPreference(): void {
        $this->withHost();
        $client = $this->client($this->opService(null));
        $this->assertFalse($client->isUserConnected('alice'));
        $this->withConnectedUser('alice');
        $this->assertTrue($client->isUserConnected('alice'));
        $this->assertFalse($client->isUserConnected('bob'), 'one user\'s connection says nothing about another');
    }

    public function testOidcUsersAreConnectedByTheirLogin(): void {
        $this->withHost();
        $this->withAuthMethod('oidc');
        $this->assertTrue($this->client($this->opService(null, oidcUser: true))->isUserConnected('alice'));
        $this->assertFalse($this->client($this->opService(null, oidcUser: false))->isUserConnected('alice'));
    }

    // ── Requests ───────────────────────────────────────────────────────

    public function testGetRefusesBeforeContactingOpenProjectWhenNotConnected(): void {
        $this->withHost();
        $called = false;
        $client = $this->client($this->opService(function () use (&$called) {
            $called = true;
            return ['_type' => 'User'];
        }));

        try {
            $client->get('alice', 'users/me');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::USER_NOT_CONNECTED, $e->getErrorCode());
        }
        $this->assertFalse($called, 'no request is made for a user without a connection');
    }

    public function testGetRunsAsTheGivenUserAndReturnsTheBody(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $seen = null;
        $client = $this->client($this->opService(function (string $uid, string $endpoint, array $params, string $method) use (&$seen) {
            $seen = [$uid, $endpoint, $params, $method];
            return ['_type' => 'User', 'id' => 3, 'name' => 'Alice'];
        }));

        $body = $client->get('alice', 'users/me', ['pageSize' => 1]);

        $this->assertSame(['alice', 'users/me', ['pageSize' => 1], 'GET'], $seen);
        $this->assertSame('Alice', $body['name']);
    }

    /**
     * @dataProvider errorClassification
     */
    public function testErrorsAreClassifiedByStatus(array $result, string $expectedCode): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $client = $this->client($this->opService(fn () => $result));

        try {
            $client->get('alice', 'projects/1');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function errorClassification(): array {
        return [
            '401 → auth_failed'                 => [['error' => '{"message":"x"}', 'message' => 'x', 'statusCode' => 401], OpenProjectException::AUTH_FAILED],
            '403 → permission_denied'           => [['error' => '{}', 'message' => 'no', 'statusCode' => 403], OpenProjectException::PERMISSION_DENIED],
            '404 with message → not found'      => [['error' => '{}', 'message' => 'The requested resource could not be found.', 'statusCode' => 404], OpenProjectException::PROJECT_NOT_FOUND],
            '404 without message → unreachable' => [['error' => 'cURL error 6: Could not resolve host', 'statusCode' => 404], OpenProjectException::API_UNAVAILABLE],
            '429 → rate_limited'                => [['error' => '', 'message' => 'slow down', 'statusCode' => 429], OpenProjectException::RATE_LIMITED],
            '500 → temporary'                   => [['error' => 'boom', 'statusCode' => 500], OpenProjectException::TEMPORARY_FAILURE],
            '503 → temporary'                   => [['error' => '', 'message' => 'maintenance', 'statusCode' => 503], OpenProjectException::TEMPORARY_FAILURE],
            'invalid URL → host_not_configured' => [['error' => 'OpenProject URL is invalid', 'statusCode' => 500], OpenProjectException::HOST_NOT_CONFIGURED],
            // v4.9.6 — 422 is OpenProject's validation verdict and carries its sentence.
            '422 → validation_failed'           => [['error' => '', 'message' => 'bad filter', 'statusCode' => 422], OpenProjectException::VALIDATION_FAILED],
            '400 → unsupported_response'        => [['error' => '', 'message' => 'bad filter', 'statusCode' => 400], OpenProjectException::UNSUPPORTED_RESPONSE],
            'no status → unreachable'           => [['error' => 'something'], OpenProjectException::API_UNAVAILABLE],
        ];
    }

    public function testAThrowingServiceIsATemporaryFailureWithTheCauseAttached(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $client = $this->client($this->opService(function () {
            throw new \RuntimeException('timeout');
        }));

        try {
            $client->get('alice', 'projects/1');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::TEMPORARY_FAILURE, $e->getErrorCode());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testNonArrayBodyIsUnsupported(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $client = $this->client($this->opService(fn () => 'not json'));
        $this->expectException(OpenProjectException::class);
        $this->expectExceptionMessageMatches('/non-array/');
        $client->get('alice', 'projects/1');
    }

    public function testResponseBodiesAndQueryStringsNeverReachTheLog(): void {
        $this->withHost();
        $this->withConnectedUser('alice');
        $calls  = [];
        $secret = 'SECRET-PROJECT-NAME-' . bin2hex(random_bytes(4));
        $client = $this->client(
            $this->opService(fn () => ['error' => '{"message":"' . $secret . '"}', 'message' => $secret, 'statusCode' => 403]),
            logger: $this->recordingLogger($calls),
        );

        try {
            $client->get('alice', 'projects?filters=' . $secret);
        } catch (OpenProjectException $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage(), 'the exception message carries no body either');
        }

        $this->assertNotEmpty($calls, 'the failure is logged');
        foreach ($calls as $call) {
            $flat = $call['message'] . ' ' . json_encode($call['context']);
            $this->assertStringNotContainsString($secret, $flat, 'neither the body nor the query string is logged');
            $this->assertArrayNotHasKey('error', $call['context']);
        }
    }

    // ── URLs ───────────────────────────────────────────────────────────

    public function testDeepLinksAreBuiltFromTheConfiguredHostOnly(): void {
        $this->withHost();
        $client = $this->client($this->opService(null));

        $this->assertSame(self::HOST . '/projects/demo-project', $client->projectUrl('demo-project'));
        $this->assertSame(self::HOST . '/projects/demo-project/work_packages', $client->workPackagesUrl('demo-project'));
        $this->assertSame(self::HOST . '/projects/demo-project/work_packages/new', $client->newWorkPackageUrl('demo-project'));
        $this->assertSame(self::HOST . '/work_packages/77', $client->workPackageUrl(77));
        $this->assertNull($client->workPackageUrl(0));
        $this->assertSame(self::HOST . '/projects/a%2Fb', $client->projectUrl('a/b'), 'a slash in a ref is encoded, never a path segment');

        // v4.9.7 — the viewer's own open work packages of a project, as the
        // web UI's `query_props`: on the host, on the project, and the
        // filter JSON URL-encoded so nothing in it can start a new parameter.
        $mine = $client->myWorkPackagesUrl('demo-project');
        $this->assertStringStartsWith(self::HOST . '/projects/demo-project/work_packages?query_props=', $mine);
        $props = json_decode(rawurldecode(substr($mine, strpos($mine, '=') + 1)), true);
        $this->assertSame([['n' => 'assignee', 'o' => '=', 'v' => ['me']], ['n' => 'status', 'o' => 'o', 'v' => []]], $props['f']);
        $this->assertStringNotContainsString('&', $mine);
    }

    public function testNoHostMeansNoLinks(): void {
        $client = $this->client($this->opService(null));
        $this->assertNull($client->projectUrl('x'));
        $this->assertNull($client->absoluteUrl('/api/v3/storages/1/open'));
    }

    public function testAbsoluteUrlRefusesToLeaveTheHost(): void {
        $this->withHost();
        $client = $this->client($this->opService(null));

        $this->assertSame(self::HOST . '/api/v3/storages/1/open', $client->absoluteUrl('/api/v3/storages/1/open'));
        $this->assertSame(self::HOST . '/x', $client->absoluteUrl(self::HOST . '/x'));
        $this->assertNull($client->absoluteUrl('https://evil.example/x'), 'another origin');
        $this->assertNull($client->absoluteUrl('//evil.example/x'), 'protocol-relative');
        $this->assertNull($client->absoluteUrl('javascript:alert(1)'));
        $this->assertNull($client->absoluteUrl('https://op.example.test:8443/x'), 'a different port is a different origin');
        $this->assertNull($client->absoluteUrl(null));
        $this->assertNull($client->absoluteUrl('  '));
    }
}
