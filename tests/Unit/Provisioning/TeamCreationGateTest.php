<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Provisioning;

use OCA\TeamHub\Controller\TeamController;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;

/**
 * `POST /api/v1/teams` enforces the creation restriction itself (v4.10.26).
 *
 * Until then `createTeamGroup` was only asked by the sidebar button, the
 * OpenProject search and the provisioning routes, so a member outside the
 * group — the very person "Request a new team" exists for — could create a
 * team with one POST. The gate is in the controller, before anything is
 * read or written, so a refusal can never leave a half-made circle behind.
 *
 * TeamController has twenty-odd collaborators and this test needs two, so
 * it is built without its constructor and handed only those two: if the
 * gate let the call through to anything else, the test would fail on an
 * uninitialised property rather than pass by accident.
 */
class TeamCreationGateTest extends TestCase {

    public function testAMemberOutsideTheCreationGroupIsRefusedBeforeAnythingIsCreated(): void {
        $teams = $this->createMock(TeamService::class);
        $teams->expects($this->never())->method('createTeam');

        $response = $this->controller(false, $teams)->createTeam('Sneaky team');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testAMemberInsideTheCreationGroupReachesTheService(): void {
        $teams = $this->createMock(TeamService::class);
        $teams->expects($this->once())->method('createTeam')->with('Allowed team')->willReturn(['id' => '']);

        $response = $this->controller(true, $teams)->createTeam('Allowed team');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    private function controller(bool $mayCreate, TeamService $teams): TeamController {
        $members = $this->createMock(MemberService::class);
        $members->method('canCurrentUserCreateTeam')->willReturn($mayCreate);

        $rc         = new \ReflectionClass(TeamController::class);
        $controller = $rc->newInstanceWithoutConstructor();
        foreach (['memberService' => $members, 'teamService' => $teams] as $name => $value) {
            $prop = $rc->getProperty($name);
            $prop->setValue($controller, $value);
        }
        return $controller;
    }
}
