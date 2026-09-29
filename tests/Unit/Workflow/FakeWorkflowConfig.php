<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Service\Workflow\WorkflowConfigService;

/**
 * The workflow settings over settable values (WorkflowHub phase 6 tests).
 *
 * Only the two lookups the archive uses are replaced; `IConfig` and
 * `IGroupManager` are not part of these tests. `retentionDays` is public
 * so a test can say "this instance keeps archives for 30 days" in one
 * line and then read what that put on the record.
 */
class FakeWorkflowConfig extends WorkflowConfigService {

    public function __construct(
        public int    $retentionDays = 0,
        public string $teamRequestGroup = 'admin',
    ) {
        // No Nextcloud.
    }

    public function getArchiveRetentionDays(): int {
        return $this->retentionDays;
    }

    public function getTeamRequestGroup(): string {
        return $this->teamRequestGroup;
    }
}
