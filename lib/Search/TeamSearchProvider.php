<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Registers TeamHub teams with Nextcloud's unified search.
 *
 * Surfaces teams the searching user is a member of (direct OR inherited via
 * group/sub-team) whose name or description matches the search term, with a
 * deep link into the TeamHub app for that team. Complements the built-in
 * Contacts/Circles team result, which links to the Contacts app instead.
 *
 * Security contract:
 *   Only teams the user can already access are returned — membership is
 *   established via circles_member (direct) OR circles_membership (inherited),
 *   identical to TeamService::getUserTeams(). Non-member teams are never
 *   surfaced, so this provider cannot leak team existence.
 */
class TeamSearchProvider implements IProvider {

    public function __construct(
        private IDBConnection  $db,
        private IURLGenerator  $urlGenerator,
    ) {}

    public function getId(): string {
        return 'teamhub-teams';
    }

    public function getName(): string {
        return 'TeamHub Teams';
    }

    /**
     * 49 = just before MessageSearchProvider (50) and DecisionSearchProvider
     * (51), since a team is the container both messages and decisions live in.
     */
    public function getOrder(string $route, array $routeParameters): int {
        return 49;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term  = trim($query->getTerm());
        $limit = $query->getLimit();

        if ($term === '') {
            return SearchResult::complete($this->getName(), []);
        }

        $uid          = $user->getUID();
        $userSingleId = $this->resolveUserSingleId($uid);

        // apps.md R-1 note: kept as direct SELECT. Semantically equivalent
        // to CirclesManager::probeCircles() + PHP-side name/description
        // filter, but the DB path pushes the case-insensitive LIKE into
        // SQL. Search endpoints fire on every keystroke; PHP-side filtering
        // over probeCircles() results is measurably slower.
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.unique_id', 'c.name', 'c.description', 'c.display_name', 'c.sanitized_name')
            ->from('circles_circle', 'c')
            ->leftJoin(
                'c',
                'circles_member',
                'm',
                $qb->expr()->andX(
                    $qb->expr()->eq('m.circle_id',  'c.unique_id'),
                    $qb->expr()->eq('m.user_id',    $qb->createNamedParameter($uid)),
                    $qb->expr()->eq('m.user_type',  $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('m.status',     $qb->createNamedParameter('Member')),
                ),
            );

        if ($userSingleId !== null) {
            $qb->leftJoin(
                'c',
                'circles_membership',
                'ms',
                $qb->expr()->andX(
                    $qb->expr()->eq('ms.circle_id', 'c.unique_id'),
                    $qb->expr()->eq('ms.single_id', $qb->createNamedParameter($userSingleId)),
                ),
            );
        }

        $membershipCond = $userSingleId !== null
            ? $qb->expr()->orX(
                $qb->expr()->isNotNull('m.user_id'),
                $qb->expr()->isNotNull('ms.single_id'),
            )
            : $qb->expr()->isNotNull('m.user_id');

        // NC's QueryBuilder has no iLike — use LOWER(col) + LIKE for portable
        // case-insensitive search (works on MySQL/MariaDB and PostgreSQL).
        $likeTerm = '%' . $this->db->escapeLikeParameter(mb_strtolower($term)) . '%';
        $nameCond = $qb->expr()->orX(
            $qb->expr()->like($qb->createFunction('LOWER(c.name)'),         $qb->createNamedParameter($likeTerm)),
            $qb->expr()->like($qb->createFunction('LOWER(c.display_name)'), $qb->createNamedParameter($likeTerm)),
            $qb->expr()->like($qb->createFunction('LOWER(COALESCE(c.description, \'\'))'), $qb->createNamedParameter($likeTerm)),
        );

        $qb->where(
                $qb->expr()->eq('c.source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            )
            ->andWhere($membershipCond)
            ->andWhere($nameCond)
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit + 20);

        $res = $qb->executeQuery();

        // Exclude teams pending deletion so they don't appear in search after
        // an admin has scheduled removal — matches TeamService::getUserTeams.
        $pendingDeletionIds = $this->loadPendingDeletionIds();

        $entries = [];
        $seen    = [];
        while ($row = $res->fetch()) {
            $teamId = (string)$row['unique_id'];
            if (isset($seen[$teamId]) || isset($pendingDeletionIds[$teamId])) {
                continue;
            }
            $seen[$teamId] = true;

            $name = (string)$row['name'];
            // Skip system circles defensively — source=16 should already exclude
            // them, but legacy installs may have user-prefixed user-source rows.
            if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                continue;
            }

            $entries[] = $this->rowToEntry($row);
            if (count($entries) >= $limit) {
                break;
            }
        }
        $res->closeCursor();

        return SearchResult::complete($this->getName(), $entries);
    }

    private function resolveUserSingleId(string $userId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.single_id')
            ->from('circles_member', 'm')
            ->innerJoin('m', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'm.circle_id'))
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.source',    $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row && !empty($row['single_id']) ? (string)$row['single_id'] : null;
    }

    /** @return array<string, true> */
    private function loadPendingDeletionIds(): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('team_id')
                ->from('teamhub_pending_dels')
                ->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
                ->executeQuery();
            $ids = [];
            while ($row = $res->fetch()) {
                $ids[(string)$row['team_id']] = true;
            }
            $res->closeCursor();
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function rowToEntry(array $row): SearchResultEntry {
        $name = $row['display_name']
            ?? ($row['sanitized_name']
                ?? (str_starts_with((string)$row['name'], 'app:circles:')
                    ? substr((string)$row['name'], strlen('app:circles:'))
                    : (string)$row['name']));
        $name = (string)$name;

        $description = (string)($row['description'] ?? '');
        $subline     = mb_strlen($description) > 140
            ? mb_substr($description, 0, 139) . '…'
            : $description;

        $resourceUrl = $this->urlGenerator->linkToRoute('teamhub.page.index')
            . '#/team/' . urlencode((string)$row['unique_id']);

        return new SearchResultEntry(
            '',
            $name,
            $subline,
            $resourceUrl,
        );
    }
}
