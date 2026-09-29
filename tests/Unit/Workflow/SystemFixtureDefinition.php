<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Workflow\IWorkflowDefinitionEndHook;
use OCA\TeamHub\Workflow\IWorkflowDefinitionReference;
use OCA\TeamHub\Workflow\IWorkflowSystemStarted;
use OCP\IL10N;

/**
 * A definition TeamHub starts itself (v4.10.50): the shape of the team
 * adoption, with the end hook and the reference link it carries, recording
 * what the engine tells it.
 */
class SystemFixtureDefinition extends FixtureDefinition implements
    IWorkflowSystemStarted,
    IWorkflowDefinitionEndHook,
    IWorkflowDefinitionReference {

    /** @var array<int, array{0: string, 1: string, 2: ?string}> outcome, uid, reason */
    public array $ended = [];

    public function onEnded(WorkflowInstance $instance, string $outcome, string $uid, ?string $reason): void {
        $this->ended[] = [$outcome, $uid, $reason];
    }

    public function getReference(IL10N $l, WorkflowInstance $instance, string $viewerUid): ?array {
        return $viewerUid === 'ncadmin'
            ? ['label' => 'Open the grid', 'url' => '/settings/admin/teamhub#team-adoption']
            : null;
    }
}
