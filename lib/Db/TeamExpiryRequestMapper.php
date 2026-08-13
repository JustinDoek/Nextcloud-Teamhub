<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * QBMapper for teamhub_expiry_request (v4.6.13).
 *
 * QBMapper here — unlike TeamExpiryMapper — because this table has a real
 * surrogate id, many rows per team, and an update path (deciding a request)
 * that wants to read the row, change two fields and write it back.
 *
 * @extends QBMapper<TeamExpiryRequest>
 */
class TeamExpiryRequestMapper extends QBMapper {

    /**
     * Every status that means "this request has been answered" — i.e.
     * `TeamExpiryRequest::STATUSES` minus PENDING.
     *
     * Restated rather than derived because a class constant cannot call
     * `array_diff`. **A status added to the entity must be added here too**,
     * or a decided request silently stops reaching My Work's Completed
     * category — the exact failure this constant exists to fix.
     *
     * @var list<string>
     */
    private const DECIDED_STATUSES = [
        TeamExpiryRequest::STATUS_APPROVED,
        TeamExpiryRequest::STATUS_DENIED,
        TeamExpiryRequest::STATUS_SUPERSEDED,
    ];

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_expiry_request', TeamExpiryRequest::class);
    }

    public function findById(int $id): ?TeamExpiryRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var TeamExpiryRequest */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * The team's open request, if it has one.
     *
     * A team may only have one request in flight at a time — the service
     * enforces that on the write path, and this is what it checks against.
     * Ordered newest-first so a table that somehow acquired two (a race between
     * two team admins submitting at once) resolves to the later one rather than
     * to whichever the storage engine happened to return.
     */
    public function findPendingByTeam(string $teamId): ?TeamExpiryRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq(
                'status',
                $qb->createNamedParameter(TeamExpiryRequest::STATUS_PENDING),
            ))
            ->orderBy('requested_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1);
        try {
            /** @var TeamExpiryRequest */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * The team's most recent request whatever its state — what the Manage team
     * tab renders so a denial and its note stay visible after the decision.
     */
    public function findLatestByTeam(string $teamId): ?TeamExpiryRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('requested_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1);
        try {
            /** @var TeamExpiryRequest */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every open request on the instance, oldest-first so the admin queue is
     * first-asked-first-served.
     *
     * @return TeamExpiryRequest[]
     */
    public function findAllPending(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'status',
                $qb->createNamedParameter(TeamExpiryRequest::STATUS_PENDING),
            ))
            ->orderBy('requested_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(max(1, $limit));
        return $this->findEntities($qb);
    }

    /**
     * Requests decided since a cut-off, newest decision first.
     *
     * The counterpart to findAllPending: that one feeds the admin's open queue,
     * this one feeds My Work's Completed category. Without it a decided request
     * simply stopped existing as far as the queue was concerned — approving an
     * extension moved the team's date out of the warning window as well, so the
     * row vanished from both providers at once and the person who asked never
     * saw an answer.
     *
     * Every non-pending status counts as decided, including SUPERSEDED: the
     * requester's question was answered either way, and "closed because the
     * date was changed another route" is a result they should see rather than
     * a row that disappears.
     *
     * `decided_at >= ?` also excludes pending rows on its own, since theirs is
     * NULL and NULL comparisons are never true on either engine — but the
     * status filter is stated explicitly rather than relied on implicitly, and
     * it is the half that uses `th_texr_status_idx`.
     *
     * @return TeamExpiryRequest[]
     */
    public function findDecidedSince(int $since, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'status',
                $qb->createNamedParameter(self::DECIDED_STATUSES, IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->andWhere($qb->expr()->gte(
                'decided_at',
                $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT),
            ))
            ->orderBy('decided_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, $limit));
        return $this->findEntities($qb);
    }

    /**
     * The same, narrowed to a page of teams — the team-scoped provider's read.
     *
     * Returns every matching row rather than one per team: a team can have had
     * a request denied and another approved inside the same window, and both
     * are things its admins should see.
     *
     * @param string[] $teamIds
     * @return TeamExpiryRequest[]
     */
    public function findDecidedSinceForTeams(array $teamIds, int $since, int $limit = 200): array {
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter(self::DECIDED_STATUSES, IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->andWhere($qb->expr()->gte(
                'decided_at',
                $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT),
            ))
            ->orderBy('decided_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, $limit));
        return $this->findEntities($qb);
    }

    /**
     * Batch open-request lookup for a page of teams.
     *
     * @param string[] $teamIds
     * @return array<string, TeamExpiryRequest> [teamId => newest pending request]
     */
    public function findPendingByTeams(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'team_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->andWhere($qb->expr()->eq(
                'status',
                $qb->createNamedParameter(TeamExpiryRequest::STATUS_PENDING),
            ))
            ->orderBy('requested_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            // Ascending order plus unconditional overwrite leaves the newest
            // row per team, matching findPendingByTeam's DESC + LIMIT 1.
            $out[$row->getTeamId()] = $row;
        }
        return $out;
    }

    /**
     * Close every open request for a team, marking them superseded.
     *
     * Called when the expiry changes by a route other than approving one of
     * these requests — extending from the All teams grid, or clearing the
     * expiry outright. Returns the number of rows closed.
     */
    public function supersedePendingForTeam(string $teamId, string $actorUid, int $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status',     $qb->createNamedParameter(TeamExpiryRequest::STATUS_SUPERSEDED))
            ->set('decided_by', $qb->createNamedParameter($actorUid))
            ->set('decided_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq(
                'status',
                $qb->createNamedParameter(TeamExpiryRequest::STATUS_PENDING),
            ));
        return $qb->executeStatement();
    }

    /** Remove every request row for a team. Returns rows deleted. */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        return $qb->executeStatement();
    }
}
