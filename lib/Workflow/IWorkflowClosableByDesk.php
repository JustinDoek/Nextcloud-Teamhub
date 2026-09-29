<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * A definition whose last step — the requester's confirmation — an admin of
 * the handling service team may complete in the requester's place
 * (v4.10.31, the service builder).
 *
 * Justin, 2026-09-24: the requester closing a request is preferable, *"but
 * there are examples that this will not work on all situations. The team
 * admins of the service should have an option to close it."* A requester
 * who never comes back would otherwise keep a finished request open in the
 * desk's statistics and in their own My Work.
 *
 * A marker: the engine decides the rest. It allows the close only on the
 * **last** step, only when that step is the **requester's**, and only to an
 * admin of the service team that handled the request (the service-agent
 * actor on the instance's own step rows, not the definition's current
 * team). The step is completed in the admin's name, so the tracker and the
 * timeline say who closed it.
 */
interface IWorkflowClosableByDesk {
}
