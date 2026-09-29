<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Workflow\IWorkflowClosableByDesk;

/**
 * A fixture whose requester's confirmation an admin of the handling desk may
 * close in their place (v4.10.31) — the shape of a service a service team
 * built (`TeamServiceDefinition`).
 */
class ClosableFixtureDefinition extends FixtureDefinition implements IWorkflowClosableByDesk {
}
