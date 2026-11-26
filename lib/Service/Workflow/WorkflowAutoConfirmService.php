<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Requests whose requester did not confirm in time (v4.20.54,
 * `docs/service-builder.md` § 14.3): finds the ones that wait for a
 * confirmation and lets the engine decide, one by one, whether the service
 * they were made on confirms them by itself.
 *
 * Nothing happens while the licence or the administrator's switch is off:
 * the module is absent then, and a request is not ended behind it.
 */
class WorkflowAutoConfirmService {

    public function __construct(
        private WorkflowStepMapper $steps,
        private WorkflowEngine     $engine,
        private ServiceTeamService $serviceTeams,
        private ITimeFactory       $timeFactory,
        private LoggerInterface    $logger,
    ) {
    }

    /** @return int how many requests were confirmed */
    public function confirmDue(): int {
        if (!$this->serviceTeams->isAvailable()) {
            return 0;
        }
        // A day is the shortest a service may set, so anything younger is
        // not a candidate whatever its setting.
        $before = $this->timeFactory->getTime() - 86400;
        $done   = 0;
        foreach ($this->steps->findInstanceIdsWaitingOn(TeamServiceDefinition::STEP_CONFIRM, $before) as $id) {
            try {
                $done += $this->engine->confirmAutomatically($id) ? 1 : 0;
            } catch (\Throwable $e) {
                // One request that cannot be confirmed must not keep the rest
                // waiting; tomorrow's run tries it again.
                $this->logger->error('[TeamHub][Workflow] request could not be confirmed automatically', [
                    'instance' => $id, 'exception' => $e, 'app' => Application::APP_ID,
                ]);
            }
        }
        return $done;
    }
}
