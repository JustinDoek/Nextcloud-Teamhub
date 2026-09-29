<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\ServiceCatalogEntry;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\ServiceTeam\ServiceCategoryService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Service Teams: eligibility, the claim and the routing (WorkflowHub
 * phase 5, v4.10.20; rewritten for v4.10.23).
 *
 * What is under test is the rule, not the storage: **who may work a
 * queue**, **which desk answers a service**, and **what the claim
 * refuses**. The two mappers are in-memory and the three Circles lookups
 * are overridden on a subclass; everything else is the real class.
 *
 * ## The roster these tests describe
 *
 * Desk `desk1` is a team with `admin1` as a team admin (level 8),
 * `agent1` and `agent2` as members (level 1), and the Nextcloud group
 * `helpdesk` — whose member is `grouper` — added to the team as a group
 * member. `outsider` is in no team, and `ncadmin` is a Nextcloud
 * administrator who is deliberately not in it.
 */
class ServiceTeamServiceTest extends TestCase {

    private const DESK = 'desk1';

    private InMemoryServiceTeamMapper $teams;
    private InMemoryServiceCatalogMapper $catalog;
    private InMemoryTeamServiceMapper $builtServices;
    /** v4.10.45 — Settings → TeamHub → Archive → archive before deletion. */
    private string $archiveBeforeDelete = '0';

    /** v4.10.46 — the administrator's switch. On, as an adopted instance has it. */
    private bool $moduleEnabled = true;
    private FakeLicenceTier $tier;

    /** @var array<string, array<string, int>> teamId → uid → Circles level */
    private array $levels = [self::DESK => ['admin1' => 8, 'agent1' => 1, 'agent2' => 1]];
    /** @var array<string, string[]> teamId → group ids that are members of the team */
    private array $teamGroups = [self::DESK => ['helpdesk']];
    /** @var array<string, string[]> group id → uids */
    private array $groups = ['helpdesk' => ['grouper']];

    protected function setUp(): void {
        $this->teams   = new InMemoryServiceTeamMapper();
        $this->catalog = new InMemoryServiceCatalogMapper();
        $this->builtServices = new InMemoryTeamServiceMapper();
        $this->tier    = new FakeLicenceTier('full');
    }

    // ── Eligibility ────────────────────────────────────────────────────

    public function testEveryMemberOfTheTeamWorksTheQueueAndNobodyElseDoes(): void {
        $service = $this->openDesk();

        $this->assertTrue($service->isEligibleAgent('admin1', self::DESK), 'a team admin');
        $this->assertTrue($service->isEligibleAgent('agent1', self::DESK), 'a member');
        $this->assertTrue($service->isEligibleAgent('grouper', self::DESK), 'a member through a group');
        $this->assertFalse($service->isEligibleAgent('outsider', self::DESK));
        $this->assertFalse($service->isEligibleAgent('agent1', 'another-team'), 'eligibility is per desk');
        $this->assertFalse($service->isEligibleAgent('', self::DESK));
    }

    public function testANextcloudAdministratorIsNotAnAgentJustForBeingOne(): void {
        $service = $this->openDesk();

        $this->assertFalse(
            $service->isEligibleAgent('ncadmin', self::DESK),
            'an administrator who should work the queue is put in the team',
        );
        $this->assertFalse($service->isServiceOwner('ncadmin', self::DESK));
    }

    /**
     * v4.10.23 — "service owner" is the team-admin role, resolved live.
     * It is what lets somebody take a request off another agent.
     */
    public function testTheTeamAdminsOwnTheService(): void {
        $service = $this->openDesk();

        $this->assertTrue($service->isServiceOwner('admin1', self::DESK));
        $this->assertFalse($service->isServiceOwner('agent1', self::DESK), 'a member does not own the service');
        $this->assertFalse($service->isServiceOwner('outsider', self::DESK));

        // Promoting somebody is the whole operation: no roster to update.
        $service->levels[self::DESK]['agent1'] = 8;
        $this->assertTrue($service->isServiceOwner('agent1', self::DESK));
    }

    public function testLeavingTheTeamTakesSomebodyOffTheQueue(): void {
        $service = $this->openDesk();
        $this->assertTrue($service->isEligibleAgent('agent2', self::DESK));

        unset($this->levels[self::DESK]['agent2']);
        // A fresh service, because eligibility is memoised per request.
        $this->assertFalse($this->service()->isEligibleAgent('agent2', self::DESK));
    }

    public function testNobodyIsEligibleOnADeskThatHoldsNothing(): void {
        $service = $this->service();

        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK));
        $this->assertFalse($service->isEligibleAgent('admin1', self::DESK));
        $this->assertSame([], $service->eligibleAgents(self::DESK));
    }

    public function testNobodyIsEligibleWithoutALicence(): void {
        $this->openDesk();
        $this->tier->set('basic');
        $service = $this->service();

        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK));
        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->get(self::DESK));
        $this->assertSame([], $service->serviceTeamsForAgent('agent1'));
    }

    /**
     * The agent list is what a notification is addressed to, so the members
     * of a group that is a member of the team have to be on it — unlike
     * every other team-relative actor, which resolves direct members only.
     */
    public function testTheAgentListExpandsTheTeamsOwnGroups(): void {
        $service = $this->openDesk();

        $this->assertSame(
            ['admin1', 'agent1', 'agent2', 'grouper'],
            $service->eligibleAgents(self::DESK),
        );
    }

    // ── Routing ────────────────────────────────────────────────────────

    public function testEveryServiceOfTheBundleRoutesToTheTeamThatClaimedIt(): void {
        $service = $this->openDesk();

        foreach (ServiceCatalogue::SERVICES as $key) {
            $this->assertSame(
                self::DESK,
                $service->serviceTeamForDefinition((string)ServiceCatalogue::definitionFor($key)),
                $key . ' must route to the desk that holds the bundle',
            );
        }
        $this->assertNull($service->serviceTeamForDefinition('a_definition_nobody_offers'));
        $this->assertNull($service->serviceTeamForDefinition(''));
    }

    public function testADeskThatHasReleasedTheServicesRoutesNothing(): void {
        $service = $this->openDesk();
        $service->releaseNextcloudServices(self::DESK, 'admin1');

        foreach (ServiceCatalogue::SERVICES as $key) {
            $this->assertNull(
                $service->serviceTeamForDefinition((string)ServiceCatalogue::definitionFor($key)),
            );
        }
        $this->assertNull($service->holderOfNextcloudServices());
    }

    // ── The claim ──────────────────────────────────────────────────────

    public function testClaimingTakesTheWholeBundleAndOpensTheDesk(): void {
        $service = $this->service();
        $team    = $service->claimNextcloudServices(self::DESK, 'admin1');

        $this->assertTrue($team->isActive());
        $this->assertSame(self::DESK, $service->holderOfNextcloudServices());
        $this->assertSame(
            ServiceCatalogue::SERVICES,
            array_column($service->describeCatalogue(self::DESK), 'serviceKey'),
            'one checkbox claims all six, in the catalogue order',
        );
    }

    public function testOnlyOneTeamOnTheInstanceMayHoldTheServices(): void {
        $service = $this->openDesk();

        $this->expectException(ValidationException::class);
        $service->claimNextcloudServices('desk2', 'admin1');
    }

    public function testTheTeamThatHoldsThemMayClaimAgain(): void {
        $service = $this->openDesk();

        // A stale tab or a double submission re-affirms the claim rather
        // than failing at somebody who already has what they asked for.
        $service->claimNextcloudServices(self::DESK, 'admin1');
        $this->assertSame(self::DESK, $service->holderOfNextcloudServices());
        $this->assertCount(count(ServiceCatalogue::SERVICES), $service->describeCatalogue(self::DESK));
    }

    public function testReleasingStopsTheQueueAndLeavesTheTeamAServiceTeam(): void {
        $service = $this->openDesk();
        $service->releaseNextcloudServices(self::DESK, 'admin1');

        $described = $service->describeForTeam(self::DESK);
        $this->assertFalse($described['holdsServices']);
        $this->assertFalse($described['claimedByOther'], 'nobody holds them, so nobody is in the way');
        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK));

        // And it can take them back.
        $service->claimNextcloudServices(self::DESK, 'admin1');
        $this->assertTrue($service->describeForTeam(self::DESK)['holdsServices']);
    }

    public function testReleasingATeamThatNeverHeldThemIsRefused(): void {
        $service = $this->service();

        $this->expectException(NotFoundException::class);
        $service->releaseNextcloudServices('desk2', 'admin1');
    }

    /**
     * What the wizard's checkbox and the Services tab read. The holding
     * team's name is disclosed deliberately: a control that greys out
     * without saying who has it sends somebody to an administrator.
     */
    public function testAnotherTeamsAdminIsToldWhoHoldsThem(): void {
        $service = $this->openDesk();

        $described = $service->describeForTeam('desk2');
        $this->assertFalse($described['holdsServices']);
        $this->assertTrue($described['claimedByOther']);
        $this->assertSame(self::DESK, $described['holderTeamId']);
        $this->assertSame('Team ' . self::DESK, $described['holderName']);
    }

    public function testRemovingUndoesTheRowAsWell(): void {
        $service = $this->openDesk();

        $service->remove(self::DESK, 'admin1');
        $this->assertFalse($service->describeForTeam(self::DESK)['holdsServices']);
        $this->assertSame([], $service->describeCatalogue(self::DESK));
        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK));
    }

    public function testEveryWriteIsLicensed(): void {
        $service = $this->service();
        $this->tier->set('basic');

        foreach ([
            fn (): mixed => $service->claimNextcloudServices(self::DESK, 'admin1'),
            fn (): mixed => $service->releaseNextcloudServices(self::DESK, 'admin1'),
            fn (): mixed => $service->listAll(),
        ] as $index => $call) {
            try {
                $call();
                $this->fail('a service team write must not work without a licence');
            } catch (LicenseGateException $e) {
                $this->assertSame('unlicensed', $e->getEnforcementLevel(), 'call ' . $index);
            }
        }
    }

    /**
     * v4.10.46 — the administrator's switch is the second half of
     * availability, and it closes exactly the doors the licence closes: a
     * licensed instance whose administrator has not switched service teams
     * on has no desk, no catalogue and no eligible agent. A client that is
     * not ready for service teams sees none of it.
     */
    public function testTheAdministratorsSwitchClosesEverything(): void {
        $this->openDesk();
        $this->moduleEnabled = false;
        $service = $this->service();

        $this->assertFalse($service->isEnabledByAdmin());
        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->get(self::DESK));
        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK));
        $this->assertSame([], $service->serviceTeamsForAgent('agent1'));
        $this->assertSame([], $service->describeCatalogue(self::DESK));

        $this->expectException(AccessDeniedException::class);
        $service->listAll();
    }

    /**
     * v4.10.46 — and the switch is not destructive: everything the desk
     * held is still there when it comes back on. This is what lets an
     * administrator try service teams, turn them off again, and turn them
     * on for real later without losing the claim.
     */
    public function testSwitchingTheModuleBackOnRestoresTheDesk(): void {
        $this->openDesk();

        $this->moduleEnabled = false;
        $this->assertNull($this->service()->get(self::DESK));

        $this->moduleEnabled = true;
        $service = $this->service();
        $this->assertNotNull($service->get(self::DESK));
        $this->assertSame(self::DESK, $service->holderOfNextcloudServices());
        $this->assertTrue($service->isEligibleAgent('agent1', self::DESK));
    }

    /**
     * v4.10.23 — `remove()` is the one deliberate exception, and it is an
     * exception on purpose: it runs when the *team* is deleted, and
     * housekeeping that depends on a licence leaves rows behind for ever
     * on an instance whose licence lapsed. Since the Nextcloud services
     * are one instance-wide claim, such a row goes on holding them for
     * everybody — which is exactly what was found on the test instance.
     */
    public function testRemovingAfterATeamIsDeletedNeedsNoLicence(): void {
        $service = $this->openDesk();
        $this->assertSame(self::DESK, $service->holderOfNextcloudServices());

        $this->tier->set('basic');
        $this->service()->remove(self::DESK, 'admin1');

        $this->tier->set('full');
        $this->assertNull(
            $this->service()->holderOfNextcloudServices(),
            'the claim must be free again once the team that held it is gone',
        );
    }

    // ── The catalogue vocabulary ───────────────────────────────────────

    public function testEveryServiceHasADefinitionAndEveryDefinitionOneService(): void {
        foreach (ServiceCatalogue::SERVICES as $key) {
            $definition = ServiceCatalogue::definitionFor($key);
            $this->assertNotNull($definition, $key . ' has no workflow behind it');
            $this->assertSame($key, ServiceCatalogue::serviceForDefinition((string)$definition));
        }
        // v4.10.45 — a retired service keeps its definition, for its requests.
        $this->assertSame(
            count(ServiceCatalogue::SERVICES) + count(ServiceCatalogue::RETIRED),
            count(array_unique(ServiceCatalogue::DEFINITIONS)),
            'two services sharing a definition would make one queue answer both',
        );
        $this->assertFalse(ServiceCatalogue::isValid('not_a_service'));
    }

    /** v4.10.25 — the catalogue page files every service and prints a lead time. */
    public function testEveryServiceIsFiledUnderACategoryThePageCanShow(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

        foreach (ServiceCatalogue::SERVICES as $key) {
            $category = ServiceCatalogue::categoryFor($key);
            $this->assertContains($category, ServiceCatalogue::CATEGORIES, $key . ' is filed under no category');
            $this->assertNotSame('', ServiceCatalogue::categoryLabel($l, $category), $category . ' has no heading');
            // v4.10.50 — a service with no card has no page to promise on.
            if (!ServiceCatalogue::hasCard($key)) {
                continue;
            }
            $this->assertNotSame('', ServiceCatalogue::leadTime($l, $key), $key . ' promises nothing about how long it takes');
        }

        $this->assertNotContains(
            ServiceCatalogue::CATEGORY_APPS,
            array_values(ServiceCatalogue::CATEGORY_OF),
            'apps and tools is empty until a team defines a service, and an empty category is not shown',
        );

        // A key this table has never heard of must land somewhere rather
        // than dropping off the page.
        $this->assertSame(ServiceCatalogue::CATEGORY_SUPPORT, ServiceCatalogue::categoryFor('invented'));
        $this->assertSame('', ServiceCatalogue::leadTime($l, 'invented'));
        $this->assertSame('printing', ServiceCatalogue::categoryLabel($l, 'printing'));
    }

    public function testACatalogueRowCarriesWhatTheCardPrints(): void {
        $rows = $this->openDesk()->describeCatalogue(self::DESK);
        $row  = $rows[0];

        foreach (['serviceKey', 'definitionKey', 'label', 'description', 'category', 'categoryLabel', 'leadTime'] as $field) {
            $this->assertArrayHasKey($field, $row, 'the card reads ' . $field . ' off the row');
        }
        $this->assertSame(ServiceCatalogue::categoryFor($row['serviceKey']), $row['category']);
        $this->assertNotSame('', $row['categoryLabel'], 'the tile label is the server translation, not a second copy in the client');
    }

    // ── Services the team built itself (v4.10.33) ──────────────────────

    public function testPublishingAServiceMakesATeamWithoutTheBundleADesk(): void {
        $service = $this->service();
        $this->assertFalse($service->isEligibleAgent('agent1', self::DESK), 'holds nothing yet');

        $service->activate(self::DESK, 'admin1');

        $this->assertTrue($service->isActiveServiceTeam(self::DESK));
        $this->assertTrue($service->isEligibleAgent('agent1', self::DESK), 'its members answer its services');
        $this->assertNull($service->holderOfNextcloudServices(), 'activating is not claiming the bundle');
    }

    public function testActivatingKeepsTheBundleOfATeamThatHoldsIt(): void {
        $service = $this->openDesk();
        $service->activate(self::DESK, 'admin1');
        $this->assertSame(self::DESK, $service->holderOfNextcloudServices());
    }

    public function testReleasingTheBundleLeavesATeamWithItsOwnServicesADesk(): void {
        $service = $this->openDesk();
        $this->builtServices->insert($this->builtService(listed: true, version: 1));

        $service->releaseNextcloudServices(self::DESK, 'admin1');

        $this->assertNull($service->holderOfNextcloudServices());
        $this->assertTrue($service->isActiveServiceTeam(self::DESK), 'its own services still need answering');
    }

    public function testAnUnpublishedServiceStillKeepsTheDeskForItsRunningRequests(): void {
        $service = $this->openDesk();
        $this->builtServices->insert($this->builtService(listed: false, version: 2));

        $service->releaseNextcloudServices(self::DESK, 'admin1');

        $this->assertTrue($service->isActiveServiceTeam(self::DESK));
    }

    public function testADraftNeverPublishedDoesNotKeepTheDesk(): void {
        $service = $this->openDesk();
        $this->builtServices->insert($this->builtService(listed: false, version: 0));

        $service->releaseNextcloudServices(self::DESK, 'admin1');

        $this->assertFalse($service->isActiveServiceTeam(self::DESK));
    }

    public function testTheCatalogueListsPublishedServicesAfterTheBundle(): void {
        $service = $this->openDesk();
        $listed  = $this->builtServices->insert($this->builtService(listed: true, version: 1));
        $this->builtServices->insert($this->builtService(listed: false, version: 1, title: 'Unpublished'));
        $this->builtServices->insert($this->builtService(listed: false, version: 0, title: 'Draft'));

        $rows  = $service->describeCatalogue(self::DESK);
        $built = array_values(array_filter($rows, static fn (array $r): bool => !empty($r['builtService'])));

        $this->assertCount(count(ServiceCatalogue::SERVICES) + 1, $rows, 'the bundle, then the one listed service');
        $this->assertCount(1, $built);
        $this->assertSame('team_service_' . $listed->getId(), $built[0]['definitionKey']);
        $this->assertSame('Application intake', $built[0]['label']);
        $this->assertSame(ServiceCatalogue::CATEGORY_APPS, $built[0]['category']);
        $this->assertSame('Usually within 3 working days', $built[0]['leadTime']);
    }

    public function testNoLeadTimeSaysNothing(): void {
        $service = $this->service();
        $this->assertSame('', $service->leadTimeInDays(0));
        $this->assertSame('Usually within 1 working day', $service->leadTimeInDays(1));
    }

    public function testRemovingTheTeamTakesItsServicesOffTheServicesPage(): void {
        $service = $this->openDesk();
        $row = $this->builtServices->insert($this->builtService(listed: true, version: 1));

        $service->remove(self::DESK, 'admin1');

        $kept = $this->builtServices->findById((int)$row->getId());
        $this->assertNotNull($kept, 'the row stays: requests made on it keep their title and steps');
        $this->assertFalse($kept->isListed());
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function builtService(bool $listed, int $version, string $title = 'Application intake'): \OCA\TeamHub\Db\TeamService {
        $doc = json_encode([
            'title' => $title, 'description' => '', 'category' => ServiceCatalogue::CATEGORY_APPS,
            'leadDays' => 3, 'askTeam' => false,
            'steps' => [['kind' => 'desk', 'label' => 'Install', 'role' => '']],
        ]);
        $row = new \OCA\TeamHub\Db\TeamService();
        $row->setTeamId(self::DESK);
        $row->setDraft($doc);
        $row->setPublished($version > 0 ? $doc : null);
        $row->setPubVersion($version);
        $row->setListed($listed ? 1 : 0);
        return $row;
    }

    /** A desk that holds the whole bundle, and the service that built it. */
    private function openDesk(): TestableServiceTeamService {
        $service = $this->service();
        $service->claimNextcloudServices(self::DESK, 'admin1');
        return $service;
    }

    private function service(): TestableServiceTeamService {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturnCallback(function (string $gid): ?IGroup {
            if (!isset($this->groups[$gid])) {
                return null;
            }
            $users = [];
            foreach ($this->groups[$gid] as $uid) {
                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn($uid);
                $users[] = $user;
            }
            $group = $this->createMock(IGroup::class);
            $group->method('getUsers')->willReturn($users);
            return $group;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(3_000_000);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(
            static fn (string $one, string $many, int $count, array $params = []): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many),
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => match ($key) {
                'archiveBeforeDelete'              => $this->archiveBeforeDelete,
                ServiceTeamService::CONFIG_ENABLED => $this->moduleEnabled ? '1' : '0',
                default                            => $default,
            },
        );

        $service = new TestableServiceTeamService(
            $this->teams,
            $this->catalog,
            $this->builtServices,
            $this->tier,
            $this->createMock(IDBConnection::class),
            $this->createMock(MemberService::class),
            $groupManager,
            $this->createMock(AuditService::class),
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
            // v4.10.45 — the built-in categories (nothing stored), and the
            // archive setting.
            new ServiceCategoryService(
                $appConfig,
                $this->teams,
                $this->catalog,
                $this->builtServices,
                $l,
                $this->createMock(LoggerInterface::class),
            ),
            $config,
        );
        $service->levels     = $this->levels;
        $service->teamGroups = $this->teamGroups;
        return $service;
    }
}

/**
 * The real service with the three Circles lookups replaced, the same
 * shape as `FakeActorResolver`: the rules under test are the ones that
 * ship, and no test needs a database to exercise them.
 */
class TestableServiceTeamService extends ServiceTeamService {

    /** @var array<string, array<string, int>> teamId → uid → level */
    public array $levels = [];
    /** @var array<string, string[]> teamId → group ids that are team members */
    public array $teamGroups = [];

    protected function memberLevel(string $uid, string $teamId): int {
        return $this->levels[$teamId][$uid] ?? 0;
    }

    protected function directMembers(string $teamId): array {
        return array_keys($this->levels[$teamId] ?? []);
    }

    protected function memberGroups(string $teamId): array {
        return $this->teamGroups[$teamId] ?? [];
    }

    /**
     * `isEligibleAgent()` asks MemberService, which is mocked; effective
     * membership here is "a direct level, or reachable through one of the
     * team's group members".
     */
    public function isEligibleAgent(string $uid, string $teamId): bool {
        if ($uid === '' || $teamId === '' || !$this->isActiveServiceTeam($teamId)) {
            return false;
        }
        return in_array($uid, $this->eligibleAgents($teamId), true);
    }

    public function teamName(string $teamId): string {
        return $teamId === '' ? '' : 'Team ' . $teamId;
    }
}

/** `teamhub_service_team` in an array. */
class InMemoryServiceTeamMapper extends ServiceTeamMapper {

    /** @var array<int, ServiceTeam> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function update(Entity $entity): Entity {
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findByTeam(string $teamId): ?ServiceTeam {
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId) {
                return clone $row;
            }
        }
        return null;
    }

    public function findAll(bool $activeOnly = false): array {
        $out = [];
        foreach ($this->rows as $row) {
            if (!$activeOnly || $row->isActive()) {
                $out[] = clone $row;
            }
        }
        return $out;
    }

    public function findByTeams(array $teamIds): array {
        $out = [];
        foreach ($teamIds as $teamId) {
            $row = $this->findByTeam((string)$teamId);
            if ($row !== null) {
                $out[(string)$teamId] = $row;
            }
        }
        return $out;
    }

    public function deleteByTeam(string $teamId): void {
        foreach ($this->rows as $id => $row) {
            if ($row->getTeamId() === $teamId) {
                unset($this->rows[$id]);
            }
        }
    }
}

/** `teamhub_service_catalog` in an array. */
class InMemoryServiceCatalogMapper extends ServiceCatalogEntryMapper {

    /** @var array<int, ServiceCatalogEntry> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct() {
        // No database.
    }

    public function insert(Entity $entity): Entity {
        $entity->setId($this->nextId++);
        $this->rows[(int)$entity->getId()] = clone $entity;
        return $entity;
    }

    public function findByTeam(string $teamId, bool $enabledOnly = false): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId && (!$enabledOnly || $row->isEnabled())) {
                $out[] = clone $row;
            }
        }
        usort($out, static fn (ServiceCatalogEntry $a, ServiceCatalogEntry $b): int => $a->getSortOrder() <=> $b->getSortOrder());
        return $out;
    }

    public function findEntry(string $teamId, string $serviceKey): ?ServiceCatalogEntry {
        foreach ($this->rows as $row) {
            if ($row->getTeamId() === $teamId && $row->getServiceKey() === $serviceKey) {
                return clone $row;
            }
        }
        return null;
    }

    public function findEnabledForDefinition(string $definitionKey): array {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->getDefinitionKey() === $definitionKey && $row->isEnabled()) {
                $out[] = clone $row;
            }
        }
        return $out;
    }

    public function deleteByTeam(string $teamId): void {
        foreach ($this->rows as $id => $row) {
            if ($row->getTeamId() === $teamId) {
                unset($this->rows[$id]);
            }
        }
    }
}
