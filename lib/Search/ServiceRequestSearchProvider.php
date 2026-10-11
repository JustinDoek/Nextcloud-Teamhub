<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * The searcher's **own** service requests in unified search (v4.11.11).
 *
 * "What happened to that folder I asked for" is a search, and until now the
 * only way to answer it was to remember which page the request lived on.
 *
 * Security contract: `WorkflowEngine::listForParticipant()` is strictly
 * participation — it returns what this person takes part in and nothing
 * else — and this narrows that again to the requests they **started**. A
 * desk member's claimed work is not listed here: it is their work, not their
 * request, and it belongs to My Work and the team's queue. So no result can
 * name a request the searcher could not already open.
 *
 * With the licence or the administrator's module switch missing there is no
 * section at all, not an error.
 *
 * Open requests come before finished ones, and within each the most recently
 * touched first: the request somebody is searching for is far more often one
 * that is still running.
 */
class ServiceRequestSearchProvider implements IProvider {

    public function __construct(
        private WorkflowEngine     $engine,
        private ServiceTeamService $serviceTeams,
        private IURLGenerator      $urlGenerator,
        private IL10N              $l,
    ) {
    }

    public function getId(): string {
        return 'teamhub-service-requests';
    }

    public function getName(): string {
        return $this->l->t('My TeamHub requests');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 53;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '' || !$this->serviceTeams->isAvailable()) {
            return SearchResult::complete($this->getName(), []);
        }

        $uid    = $user->getUID();
        $needle = mb_strtolower($term);
        $limit  = max(1, $query->getLimit());

        try {
            $workflows = $this->engine->listForParticipant($uid);
        } catch (\Throwable) {
            // A search box is not the place to report that a list failed.
            return SearchResult::complete($this->getName(), []);
        }

        $rows = [];
        foreach ($workflows as $workflow) {
            if (($workflow['startedBy'] ?? '') !== $uid || !$this->isServiceRequest($workflow)) {
                continue;
            }
            if (!$this->matches($workflow, $needle)) {
                continue;
            }
            $rows[] = $workflow;
        }

        usort($rows, static function (array $a, array $b): int {
            $openA = empty($a['endedAt']) ? 1 : 0;
            $openB = empty($b['endedAt']) ? 1 : 0;
            return $openB <=> $openA
                ?: (int)($b['updatedAt'] ?? 0) <=> (int)($a['updatedAt'] ?? 0);
        });

        $entries = [];
        foreach (array_slice($rows, 0, $limit) as $workflow) {
            $entries[] = new SearchResultEntry(
                '',
                (string)($workflow['title'] ?? ''),
                $this->subline($workflow),
                // A request lives in My Work; there is no per-request deep
                // link yet, so this opens the page that holds it.
                $this->urlGenerator->linkToRoute('teamhub.page.index', ['mywork' => '1']),
                'icon-teamhub',
            );
        }

        return SearchResult::complete($this->getName(), $entries);
    }

    /** @param array<string, mixed> $workflow */
    private function isServiceRequest(array $workflow): bool {
        if (($workflow['responsible']['type'] ?? '') === WorkflowActor::TYPE_SERVICE_AGENT) {
            return true;
        }
        foreach ((array)($workflow['steps'] ?? []) as $step) {
            if (($step['actor']['type'] ?? '') === WorkflowActor::TYPE_SERVICE_AGENT) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $workflow */
    private function matches(array $workflow, string $needle): bool {
        $haystack = [(string)($workflow['title'] ?? ''), (string)($workflow['description'] ?? '')];
        foreach ((array)($workflow['data'] ?? []) as $value) {
            if (is_string($value)) {
                $haystack[] = $value;
            }
        }
        foreach ($haystack as $field) {
            if ($field !== '' && str_contains(mb_strtolower($field), $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $workflow */
    private function subline(array $workflow): string {
        $parts = [];
        $status = (string)($workflow['status'] ?? '');
        if ($status !== '') {
            $parts[] = $this->statusLabel($status);
        }
        $teamName = (string)($workflow['teamName'] ?? '');
        if ($teamName !== '' && empty($workflow['personal'])) {
            $parts[] = $teamName;
        }
        return implode(' · ', $parts);
    }

    /**
     * The statuses a service request can be in, in the searcher's language.
     * `WorkflowStatus` is the set; this names them, as the frontend's
     * `statusLabel()` does with the same words.
     */
    private function statusLabel(string $status): string {
        return match ($status) {
            'submitted'   => $this->l->t('Submitted'),
            'in_progress' => $this->l->t('In progress'),
            'waiting'     => $this->l->t('Waiting for information'),
            'blocked'     => $this->l->t('Blocked'),
            'cancelled'   => $this->l->t('Cancelled'),
            'rejected'    => $this->l->t('Rejected'),
            'completed'   => $this->l->t('Completed'),
            default       => $status,
        };
    }
}
