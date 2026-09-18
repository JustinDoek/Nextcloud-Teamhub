<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Service\LicenseService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCache;
use OCA\TeamHub\Service\OpenProject\OpenProjectClient;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;
use OCA\TeamHub\Tests\Stubs\OpenProjectAPIService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared fakes for the OpenProject unit tests (v4.9.3).
 *
 * Every test builds the real `OpenProjectClient` over mocked Nextcloud
 * interfaces and a stub of the official app's service, so the code under
 * test is the code that ships — only the edges are doubles.
 */
abstract class OpenProjectTestCase extends TestCase {

    public const HOST = 'https://op.example.test';

    /** @var array<string, array<string, string>> app → key → value */
    protected array $appValues = [];
    /** @var array<string, array<string, array<string, string>>> uid → app → key → value */
    protected array $userValues = [];
    /**
     * v4.9.16 — the licence half of the module gate. `true` is the default
     * so every test written before the gate existed still exercises an
     * instance that has the module; `withUnlicensed()` flips it.
     */
    protected bool $licensed = true;

    protected function setUp(): void {
        parent::setUp();
        $this->appValues  = [];
        $this->userValues = [];
        $this->licensed   = true;
        // The module switch is on by default for the same reason as the
        // licence; `withModuleOff()` flips it. Real default is off.
        $this->appValues['teamhub'][OpenProjectModuleService::CONFIG_ENABLED] = '1';
    }

    // ── Environment knobs ──────────────────────────────────────────────

    protected function withHost(string $host = self::HOST): void {
        $this->appValues[OpenProjectClient::INTEGRATION_APP_ID]['openproject_instance_url'] = $host;
    }

    /** v4.9.16 — the administrator's switch, off. */
    protected function withModuleOff(): void {
        $this->appValues['teamhub'][OpenProjectModuleService::CONFIG_ENABLED] = '0';
    }

    /** v4.9.16 — no licence key at all. */
    protected function withUnlicensed(): void {
        $this->licensed = false;
    }

    protected function withAuthMethod(string $method): void {
        $this->appValues[OpenProjectClient::INTEGRATION_APP_ID]['authorization_method'] = $method;
    }

    protected function withConnectedUser(string $uid, string $opName = 'Alice Example'): void {
        $this->userValues[$uid][OpenProjectClient::INTEGRATION_APP_ID]['user_name'] = $opName;
    }

    // ── Doubles ────────────────────────────────────────────────────────

    /** @return IConfig&MockObject */
    protected function config(): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->appValues[$app][$key] ?? $default,
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, $value): void {
                $this->appValues[$app][$key] = (string)$value;
            },
        );
        $config->method('deleteAppValue')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->appValues[$app][$key]);
            },
        );
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, $default = '') => $this->userValues[$uid][$app][$key] ?? $default,
        );
        $config->method('getSystemValue')->willReturnCallback(
            fn (string $key, $default = '') => $key === 'default_timezone' ? 'UTC' : $default,
        );
        return $config;
    }

    /** @return IAppManager&MockObject */
    protected function appManager(bool $installed = true, bool $enabled = true): IAppManager {
        $manager = $this->createMock(IAppManager::class);
        if ($installed) {
            $manager->method('getAppPath')->willReturn('/apps/integration_openproject');
        } else {
            $manager->method('getAppPath')->willThrowException(new AppPathNotFoundException());
        }
        $manager->method('isEnabledForUser')->willReturn($installed && $enabled);
        return $manager;
    }

    /** @return ContainerInterface&MockObject */
    protected function container(?object $service): ContainerInterface {
        $container = $this->createMock(ContainerInterface::class);
        if ($service === null) {
            $container->method('get')->willThrowException(new \RuntimeException('not registered'));
        } else {
            $container->method('get')->willReturn($service);
        }
        return $container;
    }

    /** @return IUserSession&MockObject */
    protected function userSession(?string $uid): IUserSession {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }
        return $session;
    }

    /**
     * A logger that records every call so a test can assert what never
     * reached the log (a response body, for one).
     *
     * @param list<array{level: string, message: string, context: array}> $calls
     * @return LoggerInterface&MockObject
     */
    protected function recordingLogger(array &$calls): LoggerInterface {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string|\Stringable $message, array $context = []) use ($level, &$calls): void {
                    $calls[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
                },
            );
        }
        $logger->method('log')->willReturnCallback(
            function ($level, string|\Stringable $message, array $context = []) use (&$calls): void {
                $calls[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
            },
        );
        return $logger;
    }

    /**
     * The official app's service, played by a closure:
     *   fn(string $userId, string $endpoint, array $params, string $method): mixed
     */
    protected function opService(?callable $handler, bool $oidcUser = false): OpenProjectAPIService {
        return new OpenProjectAPIService($handler, $oidcUser);
    }

    /**
     * v4.9.16 — the module gate over the shared appconfig fake and a
     * LicenseService double that answers from `$this->licensed`. Reads the
     * knobs at call time, so `withModuleOff()` / `withUnlicensed()` may be
     * called before or after the client is built.
     */
    protected function moduleService(): OpenProjectModuleService {
        $license = $this->createMock(LicenseService::class);
        $license->method('hasLicenseKey')->willReturnCallback(fn (): bool => $this->licensed);
        $license->method('getEnforcementLevel')->willReturnCallback(fn (): string => $this->licensed ? 'none' : 'unlicensed');
        return new OpenProjectModuleService($this->config(), $license);
    }

    protected function client(
        ?object $service,
        ?string $uid = 'alice',
        bool $installed = true,
        bool $enabled = true,
        ?LoggerInterface $logger = null,
    ): OpenProjectClient {
        return new OpenProjectClient(
            $this->appManager($installed, $enabled),
            $this->config(),
            $this->container($service),
            $this->userSession($uid),
            $this->moduleService(),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    /** A real cache over Nextcloud's in-memory ArrayCache. */
    protected function cache(): OpenProjectCache {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        return new OpenProjectCache($factory, $this->config());
    }

    // ── Recorded OpenProject responses ─────────────────────────────────

    /** @return array<string, mixed> */
    protected static function projectResponse(
        int $id = 12,
        string $identifier = 'demo-project',
        bool $canCreate = true,
        bool $canEdit = true,
        bool $public = false,
    ): array {
        $links = [
            'self'   => ['href' => '/api/v3/projects/' . $id, 'title' => 'Demo project'],
            'status' => ['href' => '/api/v3/project_statuses/on_track', 'title' => 'On track'],
        ];
        if ($canCreate) {
            $links['createWorkPackage'] = ['href' => '/api/v3/projects/' . $id . '/work_packages/form'];
        }
        if ($canEdit) {
            // OpenProject includes these only for users with "edit project".
            $links['update']            = ['href' => '/api/v3/projects/' . $id . '/form'];
            $links['updateImmediately'] = ['href' => '/api/v3/projects/' . $id];
        }
        return [
            '_type'             => 'Project',
            'id'                => $id,
            'identifier'        => $identifier,
            'name'              => 'Demo <b>project</b>',
            'active'            => true,
            'public'            => $public,
            'description'       => ['format' => 'markdown', 'raw' => "# Heading\nSome **bold** text with a [link](https://x.test).", 'html' => '<p>x</p>'],
            'statusExplanation' => ['format' => 'markdown', 'raw' => 'All good', 'html' => '<p>All good</p>'],
            'createdAt'         => '2026-01-02T03:04:05Z',
            'updatedAt'         => '2026-09-01T00:00:00Z',
            '_links'            => $links,
        ];
    }

    /** @return array<string, mixed> */
    protected static function workPackageResponse(int $id, array $overrides = []): array {
        return array_replace_recursive([
            '_type'          => 'WorkPackage',
            'id'             => $id,
            'subject'        => 'Work package ' . $id,
            'startDate'      => null,
            'dueDate'        => '2026-09-20',
            'date'           => null,
            'percentageDone' => 40,
            'createdAt'      => '2026-08-01T00:00:00Z',
            'updatedAt'      => '2026-09-10T12:00:00Z',
            '_links'         => [
                'type'     => ['href' => '/api/v3/types/1', 'title' => 'Task'],
                'status'   => ['href' => '/api/v3/statuses/7', 'title' => 'In progress'],
                'priority' => ['href' => '/api/v3/priorities/8', 'title' => 'Normal'],
                'assignee' => ['href' => '/api/v3/users/3', 'title' => 'Alice Example'],
                'project'  => ['href' => '/api/v3/projects/12', 'title' => 'Demo project'],
            ],
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    protected static function collectionResponse(array $elements, ?int $total = null, string $type = 'WorkPackageCollection'): array {
        return [
            '_type'     => $type,
            'total'     => $total ?? count($elements),
            'count'     => count($elements),
            'pageSize'  => 10,
            'offset'    => 1,
            '_embedded' => ['elements' => $elements],
        ];
    }
}
