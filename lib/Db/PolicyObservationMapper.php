<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The **observed** side of the profile-compliance comparison (v4.8.15, Track F2c).
 *
 * `PolicyValueMapper` reads what a profile says a team should be; this class
 * reads what the team actually is. Split into its own mapper rather than added
 * to `PolicyService` because the queries cross into tables the policy layer
 * otherwise never touches — `circles_circle`, `circles_member` — and mixing a
 * raw `IDBConnection` into a service whose every other read goes through a
 * mapper is how the next reader loses track of where the queries live.
 *
 * **Set queries, never per team.** `TRACK-F2-DESIGN.md` §5.2 is explicit: the
 * sweep is a fixed number of queries for the whole instance, not one per team,
 * because cadence must not be bounded by instance size. Every method here takes
 * the full team-id list and returns a map.
 *
 * Nothing here is gated. The caller is `PolicyService`, which is NC-admin gated
 * throughout — the same division `PolicyProfileMapper`'s docblock records.
 */
class PolicyObservationMapper {

    /**
     * Team ids per `IN` clause.
     *
     * Postgres tolerates far more than this and MySQL's own ceiling is 65,535
     * placeholders, so the number is not a limit either engine imposes — it is
     * headroom. An instance with 500+ classified teams pays one extra round
     * trip per chunk, which is cheaper than discovering the ceiling in
     * production on the one install large enough to reach it.
     */
    private const CHUNK = 500;

    public function __construct(private IDBConnection $db) {}

    /**
     * `config` and display name for each team, keyed by team id.
     *
     * A team id with no row means Circles no longer has that circle — an
     * assignment row outliving its team. The caller decides what that means;
     * this class reports absence by omission rather than inventing a zero,
     * because `config = 0` is a real and common value (see HANDOFF §0's note
     * that every working team on the test instance carries exactly that).
     *
     * @param list<string> $teamIds
     * @return array<string, array{config: int, name: string}>
     */
    public function circlesByTeam(array $teamIds): array {
        $out = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id', 'config', 'name')
                ->from('circles_circle')
                ->where($qb->expr()->in(
                    'unique_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $out[(string)$row['unique_id']] = [
                    'config' => (int)$row['config'],
                    'name'   => (string)($row['name'] ?? ''),
                ];
            }
            $result->closeCursor();
        }

        return $out;
    }

    /**
     * Which teams carry at least one member who is not a local account.
     *
     * `user_type` 4 is a mail member and 8 a contact — Circles' own encoding,
     * and the two `PolicyField::EXTERNAL_MEMBERS` names as its source. Type 1
     * (user), 2 (group) and 16 (circle) all resolve to local principals and are
     * deliberately not counted.
     *
     * Returned as a presence map rather than a count: the field is a boolean, so
     * one external member and forty are the same finding, and carrying a number
     * nothing reads would invite somebody to render it as severity.
     *
     * @param list<string> $teamIds
     * @return array<string, true>
     */
    public function externalMemberTeams(array $teamIds): array {
        $out = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('circle_id')
                ->from('circles_member')
                ->where($qb->expr()->in(
                    'circle_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                ))
                ->andWhere($qb->expr()->in(
                    'user_type',
                    $qb->createNamedParameter([4, 8], IQueryBuilder::PARAM_INT_ARRAY),
                ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $out[(string)$row['circle_id']] = true;
            }
            $result->closeCursor();
        }

        return $out;
    }

    /**
     * Removed in v4.8.27. Use `TeamAppPresenceMapper::presenceForTeams()`.
     *
     * This read `teamhub_team_apps` with `enabled = 1` and was the observed
     * side of `integrations_allowed` from v4.8.15. **It never returned
     * anything**, because resource-backed apps stopped being written to that
     * table when resources became registry-driven — so the allow-list reported
     * every team conformant and `PolicyApplyService` never switched an
     * integration off. The truth is `teamhub_team_app_resources`, and it now
     * has exactly one reader.
     */

    /**
     * The fileid of each team's group folder root, keyed by team id (v4.8.24).
     *
     * `group_folders.root_id` is the fileid of the folder's `files` node, which
     * is the object a system tag is assigned to. GroupFolders stores it on the
     * folder row, so this needs no `filecache` join — verified on the test
     * instance, where `root_id` 195 resolves to storage 10, path `files`.
     *
     * A team with no group folder is **absent from the result**, not present
     * with a zero. That distinction is the whole of `confidential_tag`'s
     * not-applicable rule: a team with nowhere to carry a tag is not drifting
     * from a profile that asks for one, and inventing an id here would make the
     * caller unable to tell the two apart.
     *
     * **Only call this when GroupFolders is available.** These are another app's
     * tables and they do not exist on an install without it; `PolicyService` and
     * `PolicyApplyService` both check first rather than letting a missing table
     * surface as a query error. The mapper stays a mapper.
     *
     * @param list<string> $teamIds
     * @return array<string, int>
     */
    public function teamFolderRootsByTeam(array $teamIds): array {
        $out = [];

        foreach (array_chunk($teamIds, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gfg.circle_id', 'gf.root_id')
                ->from('group_folders_groups', 'gfg')
                ->innerJoin(
                    'gfg',
                    'group_folders',
                    'gf',
                    $qb->expr()->eq('gfg.folder_id', 'gf.folder_id'),
                )
                ->where($qb->expr()->in(
                    'gfg.circle_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                ))
                // A circle can be attached to more than one group folder, and
                // TeamHub has never had a rule for which is "the" team folder.
                // Lowest folder_id wins, and
                // `GroupFolderService::findGroupFolderForCircle()` was given the
                // same ORDER BY in this version so the two cannot disagree. Left
                // unordered they would both have taken whatever the engine
                // returned first, which is not stable and would let the apply
                // tag one folder while the scan read another.
                ->orderBy('gf.folder_id', 'ASC');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $rootId = (int)$row['root_id'];
                if ($rootId <= 0) {
                    // GroupFolders has the folder but has not materialised its
                    // root yet. Same treatment as no folder at all — there is
                    // nothing to tag.
                    continue;
                }
                $out[(string)$row['circle_id']] ??= $rootId;
            }
            $result->closeCursor();
        }

        return $out;
    }
}
