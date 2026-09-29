<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowParticipantMapper;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowActorResolver;
use OCA\TeamHub\Service\Workflow\WorkflowArchiveService;
use OCA\TeamHub\Service\Workflow\WorkflowEventService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The three archive tables and the service over them (WorkflowHub phase 6
 * tests).
 *
 * Every engine test has to build a `WorkflowArchiveService` now, because
 * `WorkflowEngine` archives a licensed ending. Rather than give four test
 * classes four slightly different copies of that wiring — which is how the
 * archive would end up being tested against a fixture nobody maintains —
 * they all build it here, over the real service with in-memory mappers
 * under it. The rules under test are therefore the ones that ship.
 *
 * The mappers are public: an archive assertion is usually "and this is
 * what landed in the table".
 */
class ArchiveHarness {

    public InMemoryWorkflowArchiveMapper $archives;
    public InMemoryWorkflowArchiveViewMapper $projections;
    public InMemoryWorkflowAttachmentMapper $attachments;
    public FakeWorkflowConfig $config;

    public function __construct(?FakeWorkflowConfig $config = null) {
        $this->archives    = new InMemoryWorkflowArchiveMapper();
        $this->projections = new InMemoryWorkflowArchiveViewMapper();
        $this->attachments = new InMemoryWorkflowAttachmentMapper();
        $this->config      = $config ?? new FakeWorkflowConfig();
    }

    public function service(
        WorkflowInstanceMapper     $instances,
        WorkflowStepMapper         $steps,
        WorkflowParticipantMapper  $participants,
        WorkflowEventService       $events,
        WorkflowActorResolver      $resolver,
        WorkflowDefinitionRegistry $registry,
        WorkflowLicenceTier        $tier,
        ServiceTeamService         $serviceTeams,
        AuditService               $audit,
        ITimeFactory               $time,
        IL10N                      $l,
        LoggerInterface            $logger,
    ): WorkflowArchiveService {
        return new WorkflowArchiveService(
            $this->archives,
            $this->projections,
            $instances,
            $steps,
            $participants,
            $this->attachments,
            $events,
            $resolver,
            $registry,
            $tier,
            $this->config,
            $serviceTeams,
            $audit,
            $time,
            $l,
            $logger,
        );
    }

    /** Every archive row, oldest first — the usual "what was recorded" assertion. */
    public function all(): array {
        return array_values($this->archives->rows);
    }

    /** The projections of one archive, keyed by audience. */
    public function projectionsOf(int $archiveId): array {
        $out = [];
        foreach ($this->projections->findByArchive($archiveId) as $row) {
            $out[$row->getAudience()] = $row;
        }
        return $out;
    }
}
