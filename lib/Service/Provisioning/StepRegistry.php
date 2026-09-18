<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

use OCA\TeamHub\Service\Provisioning\Step\CalendarStep;
use OCA\TeamHub\Service\Provisioning\Step\CollectivesStep;
use OCA\TeamHub\Service\Provisioning\Step\DashboardStep;
use OCA\TeamHub\Service\Provisioning\Step\FinalizeStep;
use OCA\TeamHub\Service\Provisioning\Step\HandoverStep;
use OCA\TeamHub\Service\Provisioning\Step\MembershipStep;
use OCA\TeamHub\Service\Provisioning\Step\ModulesStep;
use OCA\TeamHub\Service\Provisioning\Step\OpenProjectProjectStep;
use OCA\TeamHub\Service\Provisioning\Step\ProjectFolderStep;
use OCA\TeamHub\Service\Provisioning\Step\StepInterface;
use OCA\TeamHub\Service\Provisioning\Step\TalkStep;
use OCA\TeamHub\Service\Provisioning\Step\TeamStep;
use OCA\TeamHub\Service\Provisioning\Step\ValidateStep;

/**
 * The steps of a provisioning operation, in the order they run (v4.9.6).
 *
 * The order is the one that is safest for the APIs involved, and it differs
 * from the brief's suggestion in one place on purpose: the **OpenProject
 * project comes before the team**. Phase 1's rule (v4.9.4) is that an
 * OpenProject team exists with its project or not at all — so the project
 * has to exist first, and the team step makes the team and the link in one
 * go, exactly as `POST /api/v1/teams` does.
 *
 * Membership runs after every resource exists (so the Talk room and the
 * folder pick the members up), the dashboard after that, the handover last
 * of all writes (it takes the creator's owner level away), and the
 * read-only validation at the very end.
 *
 * The extension point for a new component: a class implementing
 * {@see StepInterface}, added here at the right place.
 */
class StepRegistry {

    /** @var list<StepInterface> */
    private array $steps;

    public function __construct(
        ValidateStep           $validate,
        OpenProjectProjectStep $project,
        TeamStep               $team,
        ProjectFolderStep      $folder,
        TalkStep               $talk,
        CalendarStep           $calendar,
        CollectivesStep        $collectives,
        ModulesStep            $modules,
        MembershipStep         $membership,
        DashboardStep          $dashboard,
        HandoverStep           $handover,
        FinalizeStep           $finalize,
    ) {
        $this->steps = [
            $validate, $project, $team, $folder, $talk, $calendar,
            $collectives, $modules, $membership, $dashboard, $handover, $finalize,
        ];
    }

    /** @return list<StepInterface> every step, in order */
    public function all(): array {
        return $this->steps;
    }

    /**
     * The steps this operation has, in order.
     *
     * @return list<StepInterface>
     */
    public function forContext(ProvisioningContext $ctx): array {
        return array_values(array_filter($this->steps, static fn (StepInterface $s): bool => $s->applies($ctx)));
    }

    public function byKey(string $key): ?StepInterface {
        foreach ($this->steps as $step) {
            if ($step->key() === $key) {
                return $step;
            }
        }
        return null;
    }
}
