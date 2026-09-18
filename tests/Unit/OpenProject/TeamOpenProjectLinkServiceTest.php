<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Db\OpenProjectMeetingSyncMapper;
use OCA\TeamHub\Db\OpenProjectNewsMirrorMapper;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException;
use OCA\TeamHub\Service\OpenProject\OpenProjectCache;
use OCA\TeamHub\Service\OpenProject\OpenProjectProjectService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\TeamService;
use OCA\TeamHub\Service\TeamTypeService;
use OCA\TeamHub\Service\TimezoneService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Linking and unlinking: who may, what is proven first, what is written,
 * and what a stale or duplicate link does. v4.9.4: the pre-creation check,
 * the create-time link that deletes the team it cannot link, the
 * administrator's unlink, and the two batch readers.
 */
class TeamOpenProjectLinkServiceTest extends OpenProjectTestCase {

    /** @var TeamOpenProjectLinkMapper&MockObject */
    private TeamOpenProjectLinkMapper $mapper;
    /** @var MemberService&MockObject */
    private MemberService $members;
    /** @var AuditService&MockObject */
    private AuditService $audit;
    /** @var list<array> audit events written */
    private array $auditEvents = [];
    /** @var array<string, TeamOpenProjectLink> rows by team */
    private array $rows = [];
    private OpenProjectCache $cache;
    /** @var array<string, string> team → template */
    private array $types = ['team-a' => 'openproject', 'team-b' => 'openproject', 'team-c' => 'openproject'];
    /** @var list<string> teams TeamService::deleteTeam() was asked to delete */
    private array $deletedTeams = [];
    /** Make the mocked TeamService::deleteTeam() fail. */
    private bool $deleteFails = false;

    /**
     * @param array<string, mixed|callable> $routes
     * @param list<string> $adminOf teams the current user administers
     * @param list<string> $memberOf teams the current user is a member of
     * @param bool $ncAdmin whether the current user is a Nextcloud administrator
     */
    private function service(array $routes, array $adminOf = ['team-a'], array $memberOf = ['team-a'], bool $ncAdmin = false): TeamOpenProjectLinkService {
        $this->withHost();
        $this->withConnectedUser('alice');
        $this->auditEvents = [];
        $this->deletedTeams = [];
        $this->deleteFails = false;
        $this->cache = $this->cache();

        $op = $this->opService(function (string $uid, string $endpoint) use ($routes) {
            foreach ($routes as $prefix => $response) {
                if ($endpoint === $prefix) return $response;
            }
            return ['error' => '{}', 'message' => 'not found', 'statusCode' => 404];
        });
        $client = $this->client($op);

        $this->mapper = $this->createMock(TeamOpenProjectLinkMapper::class);
        $this->mapper->method('findByTeam')->willReturnCallback(fn (string $teamId) => $this->rows[$teamId] ?? null);
        $this->mapper->method('findByProject')->willReturnCallback(
            fn (int $pid) => array_values(array_filter($this->rows, fn (TeamOpenProjectLink $r) => $r->getProjectId() === $pid)),
        );
        $this->mapper->method('findByProjects')->willReturnCallback(function (array $pids): array {
            $out = [];
            foreach ($this->rows as $r) {
                if (in_array($r->getProjectId(), $pids, true)) {
                    $out[$r->getProjectId()][] = $r;
                }
            }
            return $out;
        });
        $this->mapper->method('findByTeams')->willReturnCallback(
            fn (array $ids) => array_filter($this->rows, fn (TeamOpenProjectLink $r) => in_array($r->getTeamId(), $ids, true)),
        );
        $this->mapper->method('insert')->willReturnCallback(function (TeamOpenProjectLink $row) {
            $row->setId(count($this->rows) + 1);
            $this->rows[$row->getTeamId()] = $row;
            return $row;
        });
        $this->mapper->method('update')->willReturnCallback(function (TeamOpenProjectLink $row) {
            $this->rows[$row->getTeamId()] = $row;
            return $row;
        });
        $this->mapper->method('deleteByTeam')->willReturnCallback(function (string $teamId): int {
            $had = isset($this->rows[$teamId]);
            unset($this->rows[$teamId]);
            return $had ? 1 : 0;
        });

        $circles = $this->createMock(PolicyObservationMapper::class);
        $circles->method('circlesByTeam')->willReturnCallback(
            fn (array $ids) => array_combine($ids, array_map(fn ($id) => ['config' => 0, 'name' => 'Team ' . $id], $ids)),
        );

        $this->members = $this->createMock(MemberService::class);
        $this->members->method('requireAdminLevel')->willReturnCallback(function (string $teamId) use ($adminOf): void {
            if (!in_array($teamId, $adminOf, true)) {
                throw new AccessDeniedException('Insufficient permissions. Admin or owner role required.');
            }
        });
        $this->members->method('requireMemberLevel')->willReturnCallback(function (string $teamId) use ($memberOf): void {
            if (!in_array($teamId, $memberOf, true)) {
                throw new AccessDeniedException('You are not a member of this team');
            }
        });

        $this->audit = $this->createMock(AuditService::class);
        $this->audit->method('log')->willReturnCallback(function (...$args): void {
            $this->auditEvents[] = $args;
        });

        $types = $this->createMock(TeamTypeService::class);
        $types->method('getType')->willReturnCallback(fn (string $teamId) => $this->types[$teamId] ?? null);
        $types->method('setType')->willReturnCallback(function (string $teamId, string $type) use ($adminOf): string {
            if (!in_array($teamId, $adminOf, true)) {
                throw new AccessDeniedException('Insufficient permissions. Admin or owner role required.');
            }
            $this->types[$teamId] = $type;
            return $type;
        });

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($ncAdmin);

        // The rollback's collaborator, resolved through the container.
        $teamService = $this->createMock(TeamService::class);
        $teamService->method('deleteTeam')->willReturnCallback(function (string $teamId): void {
            if ($this->deleteFails) {
                throw new \RuntimeException('Circles refused');
            }
            $this->deletedTeams[] = $teamId;
        });

        return new TeamOpenProjectLinkService(
            $this->mapper,
            $this->createMock(OpenProjectNewsMirrorMapper::class),
            $this->createMock(OpenProjectMeetingSyncMapper::class),
            $circles,
            $client,
            new OpenProjectProjectService($client, $this->cache, new TimezoneService($this->config())),
            $this->cache,
            $this->members,
            $this->audit,
            $types,
            $this->userSession('alice'),
            $groups,
            $this->container($teamService),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function existingRow(string $teamId, int $projectId, string $host = self::HOST): TeamOpenProjectLink {
        $row = new TeamOpenProjectLink();
        $row->setId(7);
        $row->setTeamId($teamId);
        $row->setProjectId($projectId);
        $row->setProjectIdentifier('old-slug');
        $row->setProjectName('Old name');
        $row->setHost($host);
        $row->setCreatedBy('carol');
        $row->setCreatedAt(1000);
        $row->setUpdatedAt(1000);
        $this->rows[$teamId] = $row;
        return $row;
    }

    // ── Permission ─────────────────────────────────────────────────────

    public function testNonAdminCannotLink(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: []);
        try {
            $service->link('team-a', 12);
            $this->fail('expected an exception');
        } catch (AccessDeniedException) {
        }
        $this->assertSame([], $this->rows, 'nothing written');
        $this->assertSame([], $this->auditEvents);
    }

    public function testATeamAdminCannotUnlinkOnlyANextcloudAdministratorCan(): void {
        // Alice administers team-a; that is not enough.
        $service = $this->service([], adminOf: ['team-a'], ncAdmin: false);
        $this->existingRow('team-a', 12);
        try {
            $service->adminUnlink('team-a');
            $this->fail('expected an exception');
        } catch (AccessDeniedException) {
        }
        $this->assertArrayHasKey('team-a', $this->rows, 'the link stays');
        $this->assertSame([], $this->auditEvents);
    }

    public function testAdminOfAnotherTeamCannotLinkThisOne(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-b']);
        $this->expectException(AccessDeniedException::class);
        $service->link('team-a', 12);
    }

    // ── Validation and proof ───────────────────────────────────────────

    public function testInvalidProjectIdIsRejected(): void {
        $service = $this->service([]);
        $this->expectException(ValidationException::class);
        $service->link('team-a', 0);
    }

    public function testAProjectTheAdminCannotReadIsNotLinked(): void {
        $service = $this->service([]); // every project 404s
        try {
            $service->link('team-a', 12);
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
        $this->assertSame([], $this->rows, 'an unreadable project is never written');
    }

    public function testALinkedTeamCannotBeLinkedAgain(): void {
        $service = $this->service(['projects/13' => self::projectResponse(13)]);
        $this->existingRow('team-a', 12);
        try {
            $service->link('team-a', 13);
            $this->fail('expected an exception');
        } catch (ValidationException) {
        }
        $this->assertSame(12, $this->rows['team-a']->getProjectId(), 'the existing link is untouched — there is no move');
        $this->assertSame([], $this->auditEvents);
    }

    public function testOnlyAnOpenProjectTemplateTeamCanBeLinked(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)]);
        $this->types['team-a'] = 'project';
        try {
            $service->link('team-a', 12);
            $this->fail('expected an exception');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('OpenProject template', $e->getMessage());
        }
        $this->assertSame([], $this->rows);

        $this->types['team-a'] = null;
        $this->expectException(ValidationException::class);
        $service->link('team-a', 12);
    }

    public function testAProjectTheUserMerelySeesCannotBeLinked(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12, canEdit: false, public: false)]);
        try {
            $service->link('team-a', 12);
            $this->fail('expected an exception');
        } catch (AccessDeniedException $e) {
            $this->assertStringContainsString('administrator of this project', $e->getMessage());
        }
        $this->assertSame([], $this->rows, 'nothing written');
    }

    public function testAPublicProjectCanBeLinkedWithoutAdministeringIt(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12, canEdit: false, public: true)]);
        $link = $service->link('team-a', 12);
        $this->assertSame(12, $link['projectId']);
    }

    // ── Write ──────────────────────────────────────────────────────────

    public function testLinkWritesTheSnapshotAuditsAndInvalidates(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12, 'demo-project')]);
        $before  = $this->cache->key('overview', 'alice', 'team-a', 12, self::HOST);

        $link = $service->link('team-a', 12);

        $this->assertSame(12, $link['projectId']);
        $this->assertSame('demo-project', $link['projectIdentifier']);
        $this->assertSame('Demo project', $link['projectName']);
        $this->assertSame(self::HOST, $link['host']);
        $this->assertFalse($link['stale']);
        $this->assertSame('alice', $link['createdBy']);
        $this->assertIsInt($link['lastValidatedAt']);
        $this->assertSame(self::HOST . '/projects/demo-project', $link['urls']['project']);

        $this->assertArrayHasKey('team-a', $this->rows);
        $this->assertCount(1, $this->auditEvents);
        $this->assertSame('openproject.linked', $this->auditEvents[0][1]);
        $this->assertSame('alice', $this->auditEvents[0][2]);
        $this->assertNotSame($before, $this->cache->key('overview', 'alice', 'team-a', 12, self::HOST), 'cache generation bumped');
    }

    public function testAProjectLinkedElsewhereIsRefused(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-a'], memberOf: ['team-a', 'team-b']);
        $this->existingRow('team-b', 12);

        try {
            $service->link('team-a', 12);
            $this->fail('expected a refusal');
        } catch (ProjectAlreadyLinkedException $e) {
            $this->assertSame([['teamId' => 'team-b', 'name' => 'Team team-b']], $e->getTeams(), 'named: Alice is a member of team-b');
        }
        $this->assertArrayNotHasKey('team-a', $this->rows);
        $this->assertSame([], $this->auditEvents);
    }

    public function testTheOtherTeamIsNotNamedToANonMember(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-a'], memberOf: ['team-a']);
        $this->existingRow('team-c', 12);
        try {
            $service->link('team-a', 12);
            $this->fail('expected a refusal');
        } catch (ProjectAlreadyLinkedException $e) {
            $this->assertSame([['teamId' => 'team-c', 'name' => null]], $e->getTeams());
        }
    }

    public function testAdminUnlinkRemovesTheRowAuditsAndIsIdempotent(): void {
        // An NC admin who is in none of the teams — the case that matters.
        $service = $this->service([], adminOf: [], memberOf: [], ncAdmin: true);
        $this->existingRow('team-a', 12);
        $before = $this->cache->key('overview', 'alice', 'team-a', 12, self::HOST);

        $removed = $service->adminUnlink('team-a');
        $this->assertSame(12, $removed['projectId']);
        $this->assertArrayNotHasKey('team-a', $this->rows);
        $this->assertSame('openproject.unlinked_by_admin', $this->auditEvents[0][1]);
        $this->assertSame('alice', $this->auditEvents[0][2]);
        $this->assertNotSame($before, $this->cache->key('overview', 'alice', 'team-a', 12, self::HOST), 'cache generation bumped');

        $this->assertNull($service->adminUnlink('team-a'));
        $this->assertCount(1, $this->auditEvents, 'a second unlink writes nothing');
    }

    // ── Creating a team with its project (v4.9.4) ──────────────────────

    public function testTheNewTeamCheckRefusesTheWrongTemplateAndAMissingProject(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)]);
        try {
            $service->assertLinkableForNewTeam('collaboration', 12);
            $this->fail('expected a refusal');
        } catch (ValidationException) {
        }
        try {
            $service->assertLinkableForNewTeam('openproject', 0);
            $this->fail('expected a refusal');
        } catch (ValidationException) {
        }
        $this->assertSame([], $this->rows, 'nothing written by a refused check');
    }

    public function testTheNewTeamCheckRefusesWhatLinkWouldRefuse(): void {
        // Not administered, not public: refused before any team exists.
        $service = $this->service(['projects/12' => self::projectResponse(12, canEdit: false, public: false)]);
        try {
            $service->assertLinkableForNewTeam('openproject', 12);
            $this->fail('expected a refusal');
        } catch (AccessDeniedException) {
        }

        // Taken by another team: refused, and the team is named to a member.
        $service = $this->service(['projects/12' => self::projectResponse(12)], memberOf: ['team-a', 'team-b']);
        $this->existingRow('team-b', 12);
        try {
            $service->assertLinkableForNewTeam('openproject', 12);
            $this->fail('expected a refusal');
        } catch (ProjectAlreadyLinkedException $e) {
            $this->assertSame([['teamId' => 'team-b', 'name' => 'Team team-b']], $e->getTeams());
        }

        // Unreadable: the OpenProject error goes up unchanged.
        $service = $this->service([]);
        $this->expectException(OpenProjectException::class);
        $service->assertLinkableForNewTeam('openproject', 12);
    }

    public function testTheNewTeamCheckReturnsTheProjectWhenEverythingHolds(): void {
        $service = $this->service(['projects/12' => self::projectResponse(12)]);
        $project = $service->assertLinkableForNewTeam('openproject', 12);
        $this->assertSame(12, $project['id']);
        $this->assertSame([], $this->rows, 'nothing written by a check');
        $this->assertSame([], $this->deletedTeams);
    }

    public function testLinkNewTeamTypesTheTeamAndLinksIt(): void {
        $this->types = [];
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-new']);

        $link = $service->linkNewTeam('team-new', 12);

        $this->assertSame('openproject', $this->types['team-new'], 'typed before linking');
        $this->assertSame(12, $link['projectId']);
        $this->assertSame(12, $this->rows['team-new']->getProjectId());
        $this->assertSame('openproject.linked', $this->auditEvents[0][1]);
        $this->assertSame([], $this->deletedTeams, 'nothing rolled back');
    }

    public function testLinkNewTeamDeletesTheTeamWhenTheProjectWasTakenMeanwhile(): void {
        $this->types = [];
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-new'], memberOf: ['team-new']);
        // Between the pre-creation check and the link, another team took it.
        $this->existingRow('team-c', 12);

        try {
            $service->linkNewTeam('team-new', 12);
            $this->fail('expected a refusal');
        } catch (ProjectAlreadyLinkedException $e) {
            $this->assertSame([['teamId' => 'team-c', 'name' => null]], $e->getTeams());
        }
        $this->assertSame(['team-new'], $this->deletedTeams, 'the team that could not be linked is gone');
        $this->assertArrayNotHasKey('team-new', $this->rows);
        $this->assertArrayHasKey('team-c', $this->rows, 'the other team is untouched');
    }

    public function testLinkNewTeamDeletesTheTeamWhenTheInsertLosesTheRace(): void {
        $this->types = [];
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-new']);
        // The unique index fires although the pre-check saw nothing.
        $this->mapper = $this->mapperThatRefusesInsert($service);

        try {
            $service->linkNewTeam('team-new', 12);
            $this->fail('expected a refusal');
        } catch (ProjectAlreadyLinkedException) {
        }
        $this->assertSame(['team-new'], $this->deletedTeams);
    }

    public function testAFailedRollbackStillReportsTheOriginalRefusal(): void {
        $this->types = [];
        $service = $this->service(['projects/12' => self::projectResponse(12)], adminOf: ['team-new']);
        $this->existingRow('team-c', 12);
        $this->deleteFails = true;

        $this->expectException(ProjectAlreadyLinkedException::class);
        $service->linkNewTeam('team-new', 12);
    }

    // ── Batch readers (v4.9.4) ─────────────────────────────────────────

    public function testLinkedTeamsByProjectNamesOnlyTheTeamsTheUserIsIn(): void {
        $service = $this->service([], memberOf: ['team-a', 'team-b']);
        $this->existingRow('team-b', 12);
        $this->existingRow('team-c', 13);

        $linked = $service->linkedTeamsByProject([12, 13, 14]);

        $this->assertSame([['teamId' => 'team-b', 'name' => 'Team team-b']], $linked[12]);
        $this->assertSame([['teamId' => 'team-c', 'name' => null]], $linked[13], 'counted, not named');
        $this->assertArrayNotHasKey(14, $linked, 'an unlinked project is absent');
        $this->assertSame([], $service->linkedTeamsByProject([]));
    }

    public function testLinksForTeamsIsTheGridShapeAndReportsStaleness(): void {
        $service = $this->service([]);
        $this->existingRow('team-a', 12);
        $this->existingRow('team-b', 13, 'https://elsewhere.example');

        $links = $service->linksForTeams(['team-a', 'team-b', 'team-c']);

        $this->assertSame(12, $links['team-a']['projectId']);
        $this->assertSame('Old name', $links['team-a']['projectName']);
        $this->assertFalse($links['team-a']['stale']);
        $this->assertSame(self::HOST . '/projects/old-slug', $links['team-a']['url']);
        $this->assertTrue($links['team-b']['stale']);
        $this->assertArrayNotHasKey('team-c', $links);
    }

    /**
     * A mapper whose insert throws the DB exception the unique index
     * produces, swapped into the service under test by reflection: the
     * service's other reads keep working against `$this->rows`.
     */
    private function mapperThatRefusesInsert(TeamOpenProjectLinkService $service): TeamOpenProjectLinkMapper {
        $mapper = $this->createMock(TeamOpenProjectLinkMapper::class);
        $mapper->method('findByTeam')->willReturnCallback(fn (string $teamId) => $this->rows[$teamId] ?? null);
        $mapper->method('findByProject')->willReturnCallback(
            fn (int $pid) => array_values(array_filter($this->rows, fn (TeamOpenProjectLink $r) => $r->getProjectId() === $pid)),
        );
        $mapper->method('insert')->willThrowException(
            new \OCP\DB\Exception('SQLSTATE[23505]: Unique violation: th_opl_proj_uq'),
        );
        $prop = new \ReflectionProperty(TeamOpenProjectLinkService::class, 'mapper');
        $prop->setValue($service, $mapper);
        return $mapper;
    }

    // ── Read ───────────────────────────────────────────────────────────

    public function testGetLinkAndBundleFactsForAnUnlinkedTeam(): void {
        $service = $this->service([]);
        $this->assertNull($service->getLink('team-a'));
        $this->assertSame(
            ['moduleAvailable' => true, 'available' => true, 'eligible' => true, 'linked' => false, 'stale' => false, 'project' => null],
            $service->factsForBundle('team-a'),
        );
    }

    /**
     * v4.9.16 — with the module off, a linked OpenProject team reports itself
     * as neither eligible nor linked: that is the pair every widget gates on,
     * so one answer hides them all. The row itself is untouched.
     */
    public function testBundleFactsHideEverythingWhileTheModuleIsOff(): void {
        $service = $this->service([]);
        $this->existingRow('team-a', 12);
        $this->withModuleOff();

        $this->assertSame(
            ['moduleAvailable' => false, 'available' => false, 'eligible' => false, 'linked' => false, 'stale' => false, 'project' => null],
            $service->factsForBundle('team-a'),
        );
        $this->assertNotNull($service->getLink('team-a'), 'the link row is kept, not deleted');
    }

    public function testBundleFactsForANonOpenProjectTeamNeverReportALink(): void {
        $service = $this->service([]);
        $this->existingRow('team-a', 12);
        $this->types['team-a'] = 'collaboration';

        $facts = $service->factsForBundle('team-a');

        $this->assertFalse($facts['eligible']);
        $this->assertFalse($facts['linked'], 'a stray row on a team of another template is not a link');
        $this->assertNull($facts['project']);
        try {
            $service->requireLink('team-a');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testBundleFactsForALinkedTeam(): void {
        $service = $this->service([]);
        $this->existingRow('team-a', 12);
        $facts = $service->factsForBundle('team-a');

        $this->assertTrue($facts['eligible']);
        $this->assertTrue($facts['linked']);
        $this->assertFalse($facts['stale']);
        $this->assertSame(12, $facts['project']['id']);
        $this->assertSame('Old name', $facts['project']['name']);
        $this->assertSame(self::HOST . '/projects/old-slug', $facts['project']['url']);
    }

    public function testALinkMadeAgainstAnotherHostIsStale(): void {
        $service = $this->service([]);
        $this->existingRow('team-a', 12, 'https://previous.example');

        $this->assertTrue($service->getLink('team-a')['stale']);
        $this->assertTrue($service->factsForBundle('team-a')['stale']);
        try {
            $service->requireLink('team-a');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::LINK_STALE, $e->getErrorCode());
        }
    }

    public function testRequireLinkOnAnUnlinkedTeamIsProjectNotFound(): void {
        $service = $this->service([]);
        try {
            $service->requireLink('team-a');
            $this->fail('expected an exception');
        } catch (OpenProjectException $e) {
            $this->assertSame(OpenProjectException::PROJECT_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testRecordSuccessfulReadRefreshesTheSnapshot(): void {
        $service = $this->service([]);
        $row = $this->existingRow('team-a', 12);

        $service->recordSuccessfulRead($row, ['identifier' => 'renamed', 'name' => 'Renamed']);

        $this->assertSame('renamed', $this->rows['team-a']->getProjectIdentifier());
        $this->assertSame('Renamed', $this->rows['team-a']->getProjectName());
        $this->assertNotNull($this->rows['team-a']->getLastValidatedAt());
    }

    public function testCascadeDeleteNeedsNoPermissionAndWritesNoAudit(): void {
        $service = $this->service([], adminOf: []);
        $this->existingRow('team-a', 12);
        $service->deleteForTeamCascade('team-a');
        $this->assertArrayNotHasKey('team-a', $this->rows);
        $this->assertSame([], $this->auditEvents);
    }
}
