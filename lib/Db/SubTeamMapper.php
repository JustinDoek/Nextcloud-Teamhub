<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_sub_team (v4.12.2, DESIGN §2.175).
 *
 * One row per sub-team, holding the link to the main team that governs it and
 * nothing else. A sub-team is an ordinary TeamHub team otherwise — its own
 * circle, registry row, members, storage, modules and archive, all keyed on
 * its own `team_id` — so the row's *existence* is the whole fact of being a
 * sub-team, which is why `team_id` carries a unique index rather than this
 * being a column on `teamhub_team_registry`.
 *
 * **There is deliberately no `circles_member` row between a parent and a
 * child.** Circles' nesting propagates membership from the contained circle
 * up to the container, so a row making the sub-team a member of the main team
 * would make the sub-team's invited outsiders effective members of the main
 * team. The reasoning is written out in full in
 * {@see \OCA\TeamHub\Service\SubTeamService} and in the migration.
 *
 * A DI leaf — holds only IDBConnection — so any service may depend on it.
 * That matters here rather than being a style point: `MemberService` has to
 * ask "is this a sub-team, and who is its parent" to answer an admin-level
 * check, and it cannot depend on `SubTeamService`, which depends on
 * `MemberService`. Same split {@see TeamRegistryMapper} makes.
 */
class SubTeamMapper {

    public const TABLE = 'teamhub_sub_team';

    /** Invisible to members of the parent who are not in the sub-team. The default. */
    public const VIS_PRIVATE = 'private';
    /** Name visible under the parent to every parent member; still not openable. */
    public const VIS_LISTED  = 'listed';

    public const VISIBILITIES = [self::VIS_PRIVATE, self::VIS_LISTED];

    /** Alias {@see excludeSubTeams()} uses, so a caller can reference its columns. */
    public const JOIN_ALIAS = 'subt';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function isSubTeam(string $teamId): bool {
        return $this->parentOf($teamId) !== null;
    }

    /**
     * The id of the team that governs `$teamId`, or null when it is not a
     * sub-team. The hot path — every widened admin check calls it.
     */
    public function parentOf(string $teamId): ?string {
        $row = $this->find($teamId);
        return $row === null ? null : $row['parent_team_id'];
    }

    /**
     * The whole row, or null.
     *
     * @return array{team_id:string, parent_team_id:string, visibility:string, created_by:string, created_at:int}|null
     */
    public function find(string $teamId): ?array {
        if ($teamId === '') {
            return null;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id', 'parent_team_id', 'visibility', 'created_by', 'created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The sub-teams of one parent, oldest first.
     *
     * @return list<array{team_id:string, parent_team_id:string, visibility:string, created_by:string, created_at:int}>
     */
    public function childrenOf(string $parentTeamId): array {
        if ($parentTeamId === '') {
            return [];
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id', 'parent_team_id', 'visibility', 'created_by', 'created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('parent_team_id', $qb->createNamedParameter($parentTeamId)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery();
        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $res->closeCursor();
        return $rows;
    }

    /**
     * The sub-teams of many parents in one query, keyed by parent id.
     *
     * The sidebar needs every child of every team the viewer can see, and
     * doing that one parent at a time is an N+1 on the first payload every
     * page load asks for.
     *
     * @param string[] $parentTeamIds
     * @return array<string, list<array{team_id:string, parent_team_id:string, visibility:string, created_by:string, created_at:int}>>
     */
    public function childrenOfMany(array $parentTeamIds): array {
        $ids = array_values(array_unique(array_filter($parentTeamIds, static fn ($id) => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $out = [];
        // Chunked because the id list is as long as the viewer's team list and
        // some databases cap the number of bound parameters.
        foreach (array_chunk($ids, 500) as $chunk) {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('team_id', 'parent_team_id', 'visibility', 'created_by', 'created_at')
                ->from(self::TABLE)
                ->where($qb->expr()->in('parent_team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('created_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->executeQuery();
            while ($row = $res->fetch()) {
                $hydrated = $this->hydrate($row);
                $out[$hydrated['parent_team_id']][] = $hydrated;
            }
            $res->closeCursor();
        }
        return $out;
    }

    /**
     * The parent link for many teams in one query, keyed by the sub-team's id.
     *
     * This is the lookup the teams-list payload uses to answer `is_sub_team`
     * and `parent_team_id` for every row at once.
     *
     * @param string[] $teamIds
     * @return array<string, array{parent_team_id:string, visibility:string, created_by:string}>
     */
    public function linksFor(array $teamIds): array {
        $ids = array_values(array_unique(array_filter($teamIds, static fn ($id) => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('team_id', 'parent_team_id', 'visibility', 'created_by')
                ->from(self::TABLE)
                ->where($qb->expr()->in('team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $out[(string)$row['team_id']] = [
                    'parent_team_id' => (string)$row['parent_team_id'],
                    'visibility'     => (string)$row['visibility'],
                    'created_by'     => (string)$row['created_by'],
                ];
            }
            $res->closeCursor();
        }
        return $out;
    }

    /**
     * The names a parent's sub-teams already use, lowercased.
     *
     * Sub-team names are unique per parent rather than instance-wide, so the
     * duplicate check needs the sibling set. Resolving the names means joining
     * out to Circles, which is `SubTeamService`'s job — this returns the ids
     * for it to resolve.
     *
     * @return string[] the sibling team ids, excluding `$exceptTeamId`
     */
    public function siblingIds(string $parentTeamId, string $exceptTeamId = ''): array {
        $ids = [];
        foreach ($this->childrenOf($parentTeamId) as $row) {
            if ($row['team_id'] !== $exceptTeamId) {
                $ids[] = $row['team_id'];
            }
        }
        return $ids;
    }

    /**
     * Exclude sub-teams from a query over `circles_circle` (aliased
     * `$circleAlias`), with a LEFT JOIN and a NULL test.
     *
     * Used by the browse/join surface: a sub-team's membership is hand-picked
     * by the parent's admin, so it must never appear in a list anyone can
     * join from — which a template whose `preselect_config` carries
     * CFG_VISIBLE or CFG_OPEN would otherwise cause. Mirrors
     * {@see TeamRegistryMapper::joinRegistered()}, in the negative.
     */
    public function excludeSubTeams(IQueryBuilder $qb, string $circleAlias): void {
        $qb->leftJoin(
            $circleAlias,
            self::TABLE,
            self::JOIN_ALIAS,
            $qb->expr()->eq(self::JOIN_ALIAS . '.team_id', $circleAlias . '.unique_id'),
        );
        $qb->andWhere($qb->expr()->isNull(self::JOIN_ALIAS . '.team_id'));
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Link a team to its parent. Idempotent — a second call for the same
     * sub-team is a no-op, so a retried creation cannot trip the unique index.
     */
    public function insert(string $teamId, string $parentTeamId, string $visibility, string $createdBy): void {
        if ($teamId === '' || $parentTeamId === '' || $this->isSubTeam($teamId)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'        => $qb->createNamedParameter($teamId),
                'parent_team_id' => $qb->createNamedParameter($parentTeamId),
                'visibility'     => $qb->createNamedParameter(
                    in_array($visibility, self::VISIBILITIES, true) ? $visibility : self::VIS_PRIVATE,
                ),
                'created_by'     => $qb->createNamedParameter($createdBy),
                'created_at'     => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();
    }

    public function setVisibility(string $teamId, string $visibility): void {
        if ($teamId === '' || !in_array($visibility, self::VISIBILITIES, true)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('visibility', $qb->createNamedParameter($visibility))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }

    public function delete(string $teamId): void {
        if ($teamId === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }

    /**
     * Drop every link under one parent. The cascade's backstop — the children
     * themselves are destroyed first, one at a time, so this only cleans up
     * rows a failed destroy might have left behind.
     */
    public function deleteByParent(string $parentTeamId): void {
        if ($parentTeamId === '') {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('parent_team_id', $qb->createNamedParameter($parentTeamId)))
            ->executeStatement();
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array{team_id:string, parent_team_id:string, visibility:string, created_by:string, created_at:int}
     */
    private function hydrate(array $row): array {
        return [
            'team_id'        => (string)$row['team_id'],
            'parent_team_id' => (string)$row['parent_team_id'],
            'visibility'     => (string)$row['visibility'],
            'created_by'     => (string)$row['created_by'],
            'created_at'     => (int)$row['created_at'],
        ];
    }
}
