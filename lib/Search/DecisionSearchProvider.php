<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Service\MemberService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Registers TeamHub decisions with Nextcloud's unified search.
 *
 * Security contract:
 *   Results are only returned when the searching user has a non-zero
 *   membership level for the decision's team_id — same enforcement as
 *   MessageSearchProvider.
 */
class DecisionSearchProvider implements IProvider {

    public function __construct(
        private DecisionMapper  $decisionMapper,
        private MemberService   $memberService,
        private IDBConnection   $db,
        private IURLGenerator   $urlGenerator,
    ) {}

    public function getId(): string {
        return 'teamhub-decisions';
    }

    public function getName(): string {
        return 'TeamHub Decisions';
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 51;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term   = $query->getTerm();
        $limit  = $query->getLimit();
        $cursor = $query->getCursor();
        $offset = is_numeric($cursor) ? (int)$cursor : 0;

        if (trim($term) === '') {
            return SearchResult::complete($this->getName(), []);
        }

        $fetchLimit = $limit + 20;
        $rows = $this->decisionMapper->search($term, $fetchLimit, $offset);

        $membershipCache = [];
        $entries         = [];

        foreach ($rows as $row) {
            $teamId = $row['team_id'];

            if (!array_key_exists($teamId, $membershipCache)) {
                $level = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
                $membershipCache[$teamId] = $level > 0;
            }

            if (!$membershipCache[$teamId]) {
                continue;
            }

            $entries[] = $this->rowToEntry($row);

            if (count($entries) >= $limit) {
                break;
            }
        }

        $hasMore = count($rows) >= $fetchLimit && count($entries) >= $limit;

        if ($hasMore) {
            $nextCursor = (string)($offset + count($rows));
            return SearchResult::paginated($this->getName(), $entries, $nextCursor);
        }

        return SearchResult::complete($this->getName(), $entries);
    }

    private function rowToEntry(array $row): SearchResultEntry {
        $question = $row['question'] ?? '';
        $status   = $row['status'] ?? '';
        $impact   = $row['impact'] ?? '';
        $teamName = $row['team_name'] ?? '';

        $subline = ucfirst($status);
        if ($impact) {
            $subline .= ' · ' . ucfirst($impact) . ' impact';
        }
        if ($teamName) {
            $subline .= ' · ' . $teamName;
        }

        $resourceUrl = $this->urlGenerator->linkToRoute('teamhub.page.index')
            . '#/team/' . urlencode($row['team_id'] ?? '');

        return new SearchResultEntry(
            '',
            $question,
            $subline,
            $resourceUrl
        );
    }
}
