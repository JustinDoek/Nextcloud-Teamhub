<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\TeamSpace;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\ServiceCatalogEntry;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Migration\RepairSteps\CompleteNextcloudServicesBundle;
use OCA\TeamHub\Migration\RepairSteps\ImportLedgerQuotaRequests;
use OCA\TeamHub\Service\ServiceTeam\QuotaTeamsService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The quota request's move from the ledger into the Nextcloud services
 * (v4.10.29): the holder of the bundle gains the seventh service, the
 * ledger's open requests are imported onto the engine, and the Services
 * page offers the card only to somebody with a team to ask for.
 */
class QuotaServiceMoveTest extends TestCase {

    // ── CompleteNextcloudServicesBundle ─────────────────────────────────

    private function entry(string $teamId, string $serviceKey, int $order): ServiceCatalogEntry {
        $e = new ServiceCatalogEntry();
        $e->setTeamId($teamId);
        $e->setServiceKey($serviceKey);
        $e->setDefinitionKey((string)ServiceCatalogue::definitionFor($serviceKey));
        $e->setEnabled($serviceKey === ServiceCatalogue::GENERAL ? 0 : 1);
        $e->setSortOrder($order);
        return $e;
    }

    /**
     * @param array<string, ServiceCatalogEntry[]> $catalogues
     * @return ServiceCatalogEntry[] what was inserted
     */
    /** @var list<string> v4.10.45 — the service keys of the rows the step removed. */
    private array $deleted = [];

    private function completeBundle(array $catalogues): array {
        $teams = $this->createMock(ServiceTeamMapper::class);
        $teams->method('findAll')->willReturn(array_map(static function (string $id): ServiceTeam {
            $t = new ServiceTeam();
            $t->setTeamId($id);
            return $t;
        }, array_keys($catalogues)));

        $inserted = [];
        $this->deleted = [];
        $catalog  = $this->createMock(ServiceCatalogEntryMapper::class);
        $catalog->method('delete')->willReturnCallback(function (ServiceCatalogEntry $e): ServiceCatalogEntry {
            $this->deleted[] = $e->getServiceKey();
            return $e;
        });
        $catalog->method('findByTeam')->willReturnCallback(fn (string $id): array => $catalogues[$id] ?? []);
        $catalog->method('insert')->willReturnCallback(function (ServiceCatalogEntry $e) use (&$inserted): ServiceCatalogEntry {
            $inserted[] = $e;
            return $e;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_700_000_000);

        (new CompleteNextcloudServicesBundle($teams, $catalog, $time, $this->createMock(LoggerInterface::class)))
            ->run($this->createMock(IOutput::class));
        return $inserted;
    }

    public function testTheHolderOfTheSixGainsTheQuotaRequest(): void {
        $six = [];
        $order = 0;
        foreach ([ServiceCatalogue::NEW_TEAM, ServiceCatalogue::TEAM_CHANGE, ServiceCatalogue::EXTERNAL_ACCESS,
                  ServiceCatalogue::SHARED_FOLDER, ServiceCatalogue::TEAM_ARCHIVE, ServiceCatalogue::GENERAL] as $key) {
            $six[] = $this->entry('desk', $key, ++$order);
        }

        $inserted = $this->completeBundle(['desk' => $six]);

        // v4.10.45 — and *Request more time for a team* in the place of the
        // retired *Request a team modification*, which is removed.
        // v4.10.50 — and the eighth, accepting teams made outside TeamHub.
        self::assertCount(3, $inserted);
        self::assertSame(ServiceCatalogue::TEAM_EXPIRY, $inserted[0]->getServiceKey());
        self::assertSame('team_expiry', $inserted[0]->getDefinitionKey());
        self::assertSame(ServiceCatalogue::TEAM_QUOTA, $inserted[1]->getServiceKey());
        self::assertSame('teamspace_quota', $inserted[1]->getDefinitionKey());
        self::assertSame('desk', $inserted[1]->getTeamId());
        self::assertTrue($inserted[1]->isEnabled());
        self::assertSame(8, $inserted[1]->getSortOrder(), 'after the rows it already has');
        self::assertSame(ServiceCatalogue::TEAM_ADOPTION, $inserted[2]->getServiceKey());
        self::assertSame('team_adoption', $inserted[2]->getDefinitionKey());
        self::assertSame([ServiceCatalogue::TEAM_CHANGE], $this->deleted);
    }

    /** v4.10.45 — a holder whose only row is the retired service still holds the bundle. */
    public function testAHolderOfOnlyTheRetiredServiceGetsTheWholeBundle(): void {
        $inserted = $this->completeBundle(['desk' => [$this->entry('desk', ServiceCatalogue::TEAM_CHANGE, 1)]]);
        self::assertSame(ServiceCatalogue::SERVICES, array_map(static fn (ServiceCatalogEntry $e): string => $e->getServiceKey(), $inserted));
        self::assertSame([ServiceCatalogue::TEAM_CHANGE], $this->deleted);
    }

    public function testACompleteBundleAndANonHolderAreLeftAlone(): void {
        $all = [];
        foreach (ServiceCatalogue::SERVICES as $i => $key) {
            $all[] = $this->entry('desk', $key, $i + 1);
        }
        self::assertSame([], $this->completeBundle(['desk' => $all, 'other' => []]));
    }

    // ── ImportLedgerQuotaRequests ────────────────────────────────────────

    /** @var array<int, array<string, mixed>> importInstance calls */
    private array $imports = [];
    private bool $ledgerDeleted = false;
    /** @var string[] teams whose old notifications were withdrawn */
    private array $withdrawn = [];

    /** @param array<string, array<string, mixed>> $ledger */
    private function import(array $ledger, array $openTeams = [], bool $engineFails = false): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $key === 'workflow_teamspace_quota' ? json_encode($ledger) : $default,
        );
        $config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
            if ($key === 'workflow_teamspace_quota') {
                $this->ledgerDeleted = true;
            }
        });

        $engine = $this->createMock(WorkflowEngine::class);
        $engine->method('importInstance')->willReturnCallback(function (...$args) use ($engineFails): array {
            if ($engineFails) {
                throw new \RuntimeException('boom');
            }
            $this->imports[] = [
                'key' => $args[0], 'team' => $args[1], 'by' => $args[2], 'at' => $args[3],
                'data' => $args[4], 'history' => $args[5], 'fallback' => $args[6] ?? null,
            ];
            return [];
        });

        $instances = $this->createMock(WorkflowInstanceMapper::class);
        $instances->method('findOpenForDefinitionAndTeam')->willReturnCallback(
            fn (string $key, string $teamId): array => in_array($teamId, $openTeams, true) ? [new WorkflowInstance()] : [],
        );

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setObject')->willReturnCallback(function (string $type, string $id) use ($notification): INotification {
            $this->withdrawn[] = $id;
            return $notification;
        });
        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturn($notification);

        (new ImportLedgerQuotaRequests($config, $engine, $instances, $notifications, $this->createMock(LoggerInterface::class)))
            ->run($this->createMock(IOutput::class));
    }

    /** @return array<string, mixed> */
    private function ledgerEntry(string $status): array {
        $decided = $status !== 'pending';
        return [
            'status' => $status, 'requestedBytes' => 20 * 1024 ** 3, 'currentBytes' => 5 * 1024 ** 3,
            'reason' => 'We archive video now', 'requestedBy' => 'inge', 'requestedByName' => 'Inge NC', 'requestedAt' => 1_699_999_600,
            'decidedBy' => $decided ? 'lieke' : null, 'decidedAt' => $decided ? 1_699_999_700 : null,
            'decisionReason' => $decided ? 'Fine.' : null,
            'closedAt' => $status === 'closed' ? 1_699_999_800 : null, 'closedBy' => $status === 'closed' ? 'inge' : null,
        ];
    }

    public function testThePendingApprovedAndDeniedAreImportedAndTheClosedAreNot(): void {
        $this->import([
            'pending'  => $this->ledgerEntry('pending'),
            'approved' => $this->ledgerEntry('approved'),
            'denied'   => $this->ledgerEntry('denied'),
            'closed'   => $this->ledgerEntry('closed'),
        ]);

        self::assertSame(['pending', 'approved', 'denied'], array_column($this->imports, 'team'));
        foreach ($this->imports as $i) {
            self::assertSame('teamspace_quota', $i['key']);
            self::assertSame('inge', $i['by']);
            self::assertSame(1_699_999_600, $i['at']);
            self::assertSame(['requestedBytes' => 20 * 1024 ** 3, 'reason' => 'We archive video now', 'currentBytes' => 5 * 1024 ** 3], $i['data']);
            self::assertEquals(WorkflowActor::group('admin'), $i['fallback'], 'no desk: it stays with the administrators');
            self::assertSame(WorkflowStepStatus::COMPLETED, $i['history']['submit']['status']);
        }
        self::assertArrayNotHasKey('handle', $this->imports[0]['history'], 'pending: the desk step is the open one');
        self::assertSame(WorkflowStepStatus::COMPLETED, $this->imports[1]['history']['handle']['status']);
        self::assertSame('lieke', $this->imports[1]['history']['handle']['by']);
        self::assertSame(WorkflowStepStatus::REJECTED, $this->imports[2]['history']['handle']['status']);
        self::assertSame('Fine.', $this->imports[2]['history']['handle']['reason']);

        self::assertTrue($this->ledgerDeleted);
        self::assertSame(['pending', 'approved', 'denied', 'closed'], $this->withdrawn, 'every old notification is withdrawn');
    }

    public function testARerunSkipsATeamThatAlreadyHasAnOpenRequest(): void {
        $this->import(['t1' => $this->ledgerEntry('pending')], ['t1']);
        self::assertSame([], $this->imports);
        self::assertTrue($this->ledgerDeleted);
    }

    public function testAFailureKeepsTheLedgerForTheNextUpgrade(): void {
        $this->import(['t1' => $this->ledgerEntry('pending')], [], true);
        self::assertFalse($this->ledgerDeleted);
    }

    // ── QuotaTeamsService ────────────────────────────────────────────────

    public function testOnlyTeamsTheUserAdministersWithASpaceAreOffered(): void {
        $resolver = $this->createMock(WorkflowActorResolver::class);
        $resolver->method('teamsOf')->willReturn(['sales' => 9, 'hr' => 8, 'ops' => 4, 'nospace' => 8]);
        $resolver->method('teamName')->willReturnCallback(static fn (string $id): string => ucfirst($id));

        $spaces = $this->createMock(TeamSpaceService::class);
        $spaces->method('isAvailable')->willReturn(true);
        $spaces->method('getTeamSpace')->willReturnCallback(
            static fn (string $id): ?array => $id === 'nospace' ? null : ['id' => 1, 'mount_point' => $id, 'quota' => 5 * 1024 ** 3],
        );

        $open = new WorkflowInstance();
        $open->setId(42);
        $open->setData(['requestedBytes' => 20 * 1024 ** 3]);
        $instances = $this->createMock(WorkflowInstanceMapper::class);
        $instances->method('findOpenForDefinitionAndTeam')->willReturnCallback(
            static fn (string $key, string $teamId): array => $teamId === 'hr' ? [$open] : [],
        );

        $teams = (new QuotaTeamsService($resolver, $spaces, $instances))->forUser('inge');

        self::assertSame(['Hr', 'Sales'], array_column($teams, 'teamName'), 'admins only, with a space, by name');
        self::assertSame(['workflowId' => 42, 'requestedBytes' => 20 * 1024 ** 3], $teams[0]['openRequest']);
        self::assertNull($teams[1]['openRequest']);
        self::assertSame(5 * 1024 ** 3, $teams[1]['quota']);
    }

    public function testWithoutTeamSpacesNobodyIsOffered(): void {
        $spaces = $this->createMock(TeamSpaceService::class);
        $spaces->method('isAvailable')->willReturn(false);
        $service = new QuotaTeamsService($this->createMock(WorkflowActorResolver::class), $spaces, $this->createMock(WorkflowInstanceMapper::class));
        self::assertSame([], $service->forUser('inge'));
    }
}
