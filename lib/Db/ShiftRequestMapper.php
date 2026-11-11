<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Mapper for teamhub_shift_request. */
class ShiftRequestMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_shift_request', ShiftRequest::class);
    }

    public function findById(int $id): ?ShiftRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var ShiftRequest */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Open requests on a set of shifts, keyed by shift id.
     *
     * The roster grid marks a shift somebody is trying to hand over, so this
     * is read once per week alongside the assignments.
     *
     * @param list<int> $shiftIds
     * @return array<int, ShiftRequest>
     */
    public function findOpenByShiftIds(array $shiftIds): array {
        if ($shiftIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'shift_id',
                $qb->createNamedParameter($shiftIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ShiftRequest::STATUS_OPEN)))
            ->orderBy('id', 'ASC');

        $out = [];
        /** @var ShiftRequest $row */
        foreach ($this->findEntities($qb) as $row) {
            $out[$row->getShiftId()] = $row;
        }
        return $out;
    }

    /**
     * Every request a team raised in a period, whatever became of it.
     *
     * The statistics count how often somebody needed to get out of a shift,
     * so a withdrawn request counts as much as one that found a taker — the
     * asking is the fact. Bounded by `created_at`, because that is when the
     * asking happened; the shift may be in another year entirely.
     *
     * @return ShiftRequest[]
     */
    public function findByTeamAndPeriod(string $teamId, int $from, int $to): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        /** @var ShiftRequest[] */
        return $this->findEntities($qb);
    }

    /**
     * One person's hand-over requests in a period, in every team. The
     * personal statistics count a person's year across all their teams
     * (v4.14.11, DESIGN §2.253).
     *
     * @return ShiftRequest[]
     */
    public function findByRequesterAndPeriod(string $uid, int $from, int $to): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('requester_uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        /** @var ShiftRequest[] */
        return $this->findEntities($qb);
    }

    /** The open request this person already has on this shift, if any. */
    public function findOpenFor(int $shiftId, string $uid): ?ShiftRequest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('requester_uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ShiftRequest::STATUS_OPEN)))
            ->setMaxResults(1);

        try {
            /** @var ShiftRequest */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Withdraw every open request on a shift.
     *
     * Called when the shift is removed or the roster goes back to concept: an
     * invitation to take over a shift that no longer exists is a dead link
     * sitting in a Talk conversation.
     */
    public function withdrawOpenByShift(int $shiftId, int $at): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter(ShiftRequest::STATUS_WITHDRAWN))
            ->set('resolved_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('shift_id', $qb->createNamedParameter($shiftId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ShiftRequest::STATUS_OPEN)));

        return $qb->executeStatement();
    }
}
