<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCP\IL10N;

/**
 * Where the work of a request is actually done, when that is not the row
 * (v4.10.50). Optional.
 *
 * The team adoption is decided in a grid — template and policy chosen per
 * team, several teams at once — and the request in a queue or in My Work
 * points there rather than repeating it. The detail view renders the answer
 * as one link, generically: no definition gets frontend code of its own
 * (`/mywork-workflows`, *Frontend: nothing per workflow*).
 *
 * The answer depends on the viewer: a desk member is sent to the service
 * team's home, where the grid is a widget; an administrator to Admin →
 * TeamHub. Null for somebody who has nowhere to go (the requester).
 */
interface IWorkflowDefinitionReference {

    /**
     * @return array{label: string, url: string}|null
     */
    public function getReference(IL10N $l, WorkflowInstance $instance, string $viewerUid): ?array;
}
