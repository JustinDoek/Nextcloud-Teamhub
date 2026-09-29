<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\Definition\ServiceRequestDefinition;
use OCA\TeamHub\Workflow\Definition\TeamRequestDefinition;
use PHPUnit\Framework\TestCase;

/**
 * v4.10.44 — the Nextcloud services a request needs no team for. Asked
 * without one, the request is personal: recorded against the service team
 * that answers it, and anybody signed in may make it. Changing a team,
 * archiving it and its quota are about one team and still need it.
 */
class TeamOptionalServicesTest extends TestCase {

    private const DESK = 'desk1';

    /** v4.10.45 — whether teams are archived before deletion. */
    private bool $archivesBeforeDelete = false;

    private function teams(): ServiceTeamService {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('serviceTeamForDefinition')->willReturn(self::DESK);
        $teams->method('isServiceOffered')->willReturnCallback(
            fn (string $key): bool => !in_array($key, ServiceCatalogue::RETIRED, true)
                && !($key === ServiceCatalogue::TEAM_ARCHIVE && $this->archivesBeforeDelete),
        );
        return $teams;
    }

    private function resolver(): FakeActorResolver {
        $resolver = new FakeActorResolver();
        $resolver->levels['t1'] = ['member' => 1];
        return $resolver;
    }

    public function testTheOptionalServicesTakeAPersonalRequestFromAnybody(): void {
        foreach ([ServiceCatalogue::SHARED_FOLDER, ServiceCatalogue::EXTERNAL_ACCESS, ServiceCatalogue::GENERAL] as $service) {
            $definition = new ServiceRequestDefinition($this->teams(), $service);
            $this->assertTrue($definition->canStart('stranger', self::DESK, $this->resolver()), $service . ': personal');
            $this->assertFalse($definition->canStart('stranger', 't1', $this->resolver()), $service . ': asked from a team, its members only');
            $this->assertTrue($definition->canStart('member', 't1', $this->resolver()));
        }
        $this->assertTrue((new TeamRequestDefinition($this->teams()))->canStart('stranger', self::DESK, $this->resolver()), 'a new team: personal');
    }

    public function testTheServicesAboutOneTeamStillNeedIt(): void {
        foreach ([ServiceCatalogue::TEAM_ARCHIVE] as $service) {
            $definition = new ServiceRequestDefinition($this->teams(), $service);
            $this->assertFalse($definition->canStart('stranger', self::DESK, $this->resolver()), $service . ': no personal request');
            $this->assertTrue($definition->canStart('member', 't1', $this->resolver()));
        }
        $this->assertFalse(ServiceCatalogue::isTeamOptional(ServiceCatalogue::TEAM_QUOTA));
    }

    public function testTheNewTeamCardsFormIsUnderstood(): void {
        $data = (new TeamRequestDefinition($this->teams()))->validateStart(['summary' => 'Design lab', 'details' => 'For the redesign']);
        $this->assertSame(['teamName' => 'Design lab', 'reason' => 'For the redesign'], $data);
    }

    /**
     * v4.10.45 — team archiving is not asked while every team is archived
     * before deletion, and the retired team modification never.
     */
    public function testAServiceTheDeskDoesNotOfferCannotBeStarted(): void {
        $this->archivesBeforeDelete = true;
        $archive = new ServiceRequestDefinition($this->teams(), ServiceCatalogue::TEAM_ARCHIVE);
        $this->assertFalse($archive->isStartable());
        $this->assertFalse($archive->canStart('member', 't1', $this->resolver()));

        $this->archivesBeforeDelete = false;
        $this->assertTrue((new ServiceRequestDefinition($this->teams(), ServiceCatalogue::TEAM_ARCHIVE))->isStartable());

        $retired = new ServiceRequestDefinition($this->teams(), ServiceCatalogue::TEAM_CHANGE);
        $this->assertFalse($retired->isStartable());
        $this->assertFalse($retired->canStart('member', 't1', $this->resolver()));
    }
}
