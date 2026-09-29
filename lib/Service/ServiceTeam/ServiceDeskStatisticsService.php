<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\WorkflowEventMapper;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * A service team's request statistics (WorkflowHub phase B, v4.10.27) —
 * the numbers behind the statistics widget on the team's home.
 *
 * Justin, 2026-09-24: counts per period, time to first claim, time to close,
 * and all of it per service; every member of the team sees it, because the
 * team *is* the desk and the numbers are about its shared work.
 *
 * **Read only, and read from the rows the engine already writes.** Nothing
 * here is stored or cached: a desk's steps are found by the same
 * `th_wfs_actor_idx` the queue uses, and the only other reads are the
 * instances (for which service a request was) and the claim events (for when
 * it was first claimed). The window is capped at 90 days so the cost stays
 * proportional to a quarter of one desk's work.
 *
 * ## What each number means
 *
 * - **received** — requests that arrived in the queue in the period.
 * - **claimed** — requests first claimed (or first assigned) in the period.
 * - **closed** — requests whose desk step was answered or rejected in the
 *   period. The desk's point of view: an answered request is closed for the
 *   desk while the requester has yet to confirm it.
 * - **withdrawn** — requests the requester cancelled while the desk had them.
 * - **open** / **unclaimed** — right now, not in the period.
 * - **time to first claim** — median seconds from arriving in the queue to
 *   the first claim, over requests *received* in the period and claimed.
 *   From the event log, not the step row: a release clears `started_at`.
 * - **time to close** — median seconds from arriving in the queue to the
 *   desk's answer, over requests *closed* in the period. Measured from the
 *   queue rather than from the moment the requester asked, because that is
 *   the part the desk controls and what the card's lead time promises; for
 *   the built-in services the two only differ on *Request a new team*,
 *   whose approval step comes before the desk.
 *
 * Medians, not means: one request left over a holiday would otherwise set
 * the number for the whole period. Null when there is nothing to measure.
 */
class ServiceDeskStatisticsService {

    /** The periods the widget offers, in days. Anything else is refused. */
    public const PERIODS = [7, 30, 90];

    private const CLAIM_EVENTS = [WorkflowEventType::STEP_CLAIMED, WorkflowEventType::STEP_ASSIGNED];

    public function __construct(
        private ServiceTeamService          $serviceTeams,
        private WorkflowStepMapper          $steps,
        private WorkflowInstanceMapper      $instances,
        private WorkflowEventMapper         $events,
        private WorkflowDefinitionRegistry  $registry,
        private ITimeFactory                $timeFactory,
        private IL10N                       $l,
    ) {
    }

    /**
     * @return array{days: int, since: int, totals: array<string, int|null>, services: array<int, array<string, mixed>>}
     * @throws AccessDeniedException not a member of the service team
     * @throws ValidationException   a period the widget does not offer
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function forDesk(string $serviceTeamId, string $uid, int $days): array {
        $this->serviceTeams->requireLicence();
        if (!in_array($days, self::PERIODS, true)) {
            throw new ValidationException($this->l->t('Choose a period of 7, 30 or 90 days.'));
        }
        if (!$this->serviceTeams->isEligibleAgent($uid, $serviceTeamId)) {
            throw new AccessDeniedException($this->l->t('You are not an agent of this service team.'));
        }

        $since = $this->timeFactory->getTime() - $days * 86400;
        $type  = WorkflowActor::TYPE_SERVICE_AGENT;

        /** @var array<int, WorkflowStep> $byId step id → step, so an active step is not counted twice */
        $byId = [];
        foreach ($this->steps->findForActorSince($type, $serviceTeamId, $since) as $step) {
            $byId[(int)$step->getId()] = $step;
        }
        foreach ($this->steps->findActiveForActor($type, $serviceTeamId) as $step) {
            $byId[(int)$step->getId()] = $step;
        }

        $instanceIds = array_values(array_unique(array_map(static fn (WorkflowStep $s): int => $s->getInstanceId(), $byId)));
        $serviceOf   = [];
        $openIds     = [];
        foreach ($this->instances->findByIds($instanceIds) as $instance) {
            $key = ServiceCatalogue::serviceForDefinition($instance->getDefinitionKey());
            // A desk step on a definition no catalogue entry names is still the
            // desk's work; it is counted under its definition key rather than
            // dropped, so the totals and the breakdown always add up.
            $serviceOf[(int)$instance->getId()] = $key ?? $instance->getDefinitionKey();
            if (WorkflowStatus::isOpen($instance->getStatus())) {
                $openIds[(int)$instance->getId()] = true;
            }
        }
        $firstClaims = $this->events->findFirstEventsOfTypes($instanceIds, self::CLAIM_EVENTS);

        $buckets = ['' => $this->emptyBucket()];
        foreach ($byId as $step) {
            $instanceId = $step->getInstanceId();
            if (!isset($serviceOf[$instanceId])) {
                continue; // the instance was purged between the two reads
            }
            $service = $serviceOf[$instanceId];
            $buckets[$service] ??= $this->emptyBucket();

            $entered    = $step->getEnteredAt();
            $completed  = $step->getCompletedAt();
            $firstClaim = $firstClaims[$instanceId][$step->getStepKey()] ?? null;
            $status     = $step->getStepStatus();

            foreach (['', $service] as $b) {
                if ($entered !== null && $entered >= $since) {
                    $buckets[$b]['received']++;
                    if ($firstClaim !== null && $firstClaim >= $entered) {
                        $buckets[$b]['claimWaits'][] = $firstClaim - $entered;
                    }
                }
                if ($firstClaim !== null && $firstClaim >= $since) {
                    $buckets[$b]['claimed']++;
                }
                if ($completed !== null && $completed >= $since) {
                    // v4.10.31 — SKIPPED with a completion time: an admin of
                    // the team closed the request while this step was open.
                    if ($status === WorkflowStepStatus::COMPLETED || $status === WorkflowStepStatus::REJECTED || $status === WorkflowStepStatus::SKIPPED) {
                        $buckets[$b]['closed']++;
                        if ($entered !== null && $completed >= $entered) {
                            $buckets[$b]['closeTimes'][] = $completed - $entered;
                        }
                    } elseif ($status === WorkflowStepStatus::CANCELLED) {
                        $buckets[$b]['withdrawn']++;
                    }
                }
                if (WorkflowStepStatus::isActive($status) && isset($openIds[$instanceId])) {
                    $buckets[$b]['open']++;
                    if ($step->getAssignee() === '') {
                        $buckets[$b]['unclaimed']++;
                    }
                }
            }
        }

        $totals = $this->finishBucket($buckets['']);
        unset($buckets['']);

        $services = [];
        foreach ($buckets as $serviceKey => $bucket) {
            $label = ServiceCatalogue::isValid((string)$serviceKey)
                ? ServiceCatalogue::describe($this->l, (string)$serviceKey)['label']
                : $this->definitionLabel((string)$serviceKey);
            $services[] = ['serviceKey' => (string)$serviceKey, 'label' => $label] + $this->finishBucket($bucket);
        }
        // Busiest first — the question the breakdown answers is "what drives
        // our work" — and by name among equals, so the order is stable.
        usort($services, static fn (array $a, array $b): int =>
            [$b['received'] + $b['open'], $a['label']] <=> [$a['received'] + $a['open'], $b['label']]);

        return ['days' => $days, 'since' => $since, 'totals' => $totals, 'services' => $services];
    }

    /** @return array<string, mixed> */
    private function emptyBucket(): array {
        return [
            'received' => 0, 'claimed' => 0, 'closed' => 0, 'withdrawn' => 0,
            'open' => 0, 'unclaimed' => 0,
            'claimWaits' => [], 'closeTimes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $bucket
     * @return array<string, int|null>
     */
    private function finishBucket(array $bucket): array {
        return [
            'received'           => $bucket['received'],
            'claimed'            => $bucket['claimed'],
            'closed'             => $bucket['closed'],
            'withdrawn'          => $bucket['withdrawn'],
            'open'               => $bucket['open'],
            'unclaimed'          => $bucket['unclaimed'],
            'medianFirstClaim'   => self::median($bucket['claimWaits']),
            'medianClose'        => self::median($bucket['closeTimes']),
        ];
    }

    private function definitionLabel(string $definitionKey): string {
        $definition = $this->registry->get($definitionKey);
        return $definition !== null ? $definition->getName() : $definitionKey;
    }

    /**
     * The median of a list of seconds, or null for an empty list.
     *
     * @param int[] $values
     */
    public static function median(array $values): ?int {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : intdiv($values[$mid - 1] + $values[$mid], 2);
    }
}
