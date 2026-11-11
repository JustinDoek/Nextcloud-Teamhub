<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_roster_shift.
 *
 * Every read is "this team, this date range", which is the composite index
 * `th_rshift_team_idx` exists for.
 */
class RosterShiftMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_roster_shift', RosterShift::class);
    }

    /**
     * One team's shifts across an inclusive ISO date range.
     *
     * Dates are compared as strings. `YYYY-MM-DD` sorts and ranges correctly
     * as text, which is why DESIGN §2.4 stores floating dates that way, and it
     * keeps Postgres from being asked for an implicit cast (`/migrations` §3).
     *
     * @return RosterShift[]
     */
    public function findByTeamAndRange(string $teamId, string $fromDate, string $toDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->gte('shift_date', $qb->createNamedParameter($fromDate)))
            ->andWhere($qb->expr()->lte('shift_date', $qb->createNamedParameter($toDate)))
            ->orderBy('shift_date', 'ASC')
            ->addOrderBy('id', 'ASC');

        /** @var RosterShift[] */
        return $this->findEntities($qb);
    }

    public function findById(int $id): ?RosterShift {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        try {
            /** @var RosterShift */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The shifts one person is on across an inclusive date range.
     *
     * Two callers, and the arguments are what tells them apart:
     *
     *   - the **double-booking guard** asks across every team and includes
     *     drafts (`$teamId` null, `$publishedOnly` false), because somebody
     *     already planned onto Monday's Early in another team cannot also work
     *     Monday's Early here, whether or not that team has published yet;
     *   - the **calendar export** asks for one team's published week, because
     *     a member only ever copies what they were told (DESIGN §2.250).
     *
     * Joined rather than two queries: the date lives on the shift and the
     * person on the assignment.
     *
     * @return RosterShift[]
     */
    public function findAssignedToUser(
        string $userId,
        string $fromDate,
        string $toDate,
        ?string $teamId = null,
        bool $publishedOnly = false,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'teamhub_roster_assign', 'a', $qb->expr()->eq('a.shift_id', 's.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('s.shift_date', $qb->createNamedParameter($fromDate)))
            ->andWhere($qb->expr()->lte('s.shift_date', $qb->createNamedParameter($toDate)))
            ->orderBy('s.shift_date', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if ($teamId !== null) {
            $qb->andWhere($qb->expr()->eq('s.team_id', $qb->createNamedParameter($teamId)));
        }
        if ($publishedOnly) {
            $qb->andWhere($qb->expr()->gt('s.published_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }

        /** @var RosterShift[] */
        return $this->findEntities($qb);
    }

    /**
     * Does this team already have this shift on this day, in this room, for
     * this role?
     *
     * The service's duplicate guard. A team may well want two Early shifts on
     * the same day in two rooms, so the room is part of the question; what it
     * must not have is the same one twice, because the second would quietly
     * double the coverage target.
     *
     * v4.13.74 — **the role is part of the question too.** Since 4.13.70 the
     * role is the identity of a shift on the card (DESIGN §2.236): a Monday
     * with *Receptionist on Early* and *Barista on Early* is two different
     * things to cover, and the guard was refusing the second one as a
     * duplicate of the first. Only the same role on the same type and day is
     * the doubled target this check exists for. `$role` of null keeps the old
     * behaviour of ignoring the field, for a caller that genuinely means "any
     * shift of this type today".
     */
    public function findDuplicate(
        string $teamId,
        string $shiftDate,
        int $shiftTypeId,
        ?int $roomId,
        ?string $role = null,
    ): ?RosterShift {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('shift_date', $qb->createNamedParameter($shiftDate)))
            ->andWhere($qb->expr()->eq('shift_type_id', $qb->createNamedParameter($shiftTypeId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $qb->andWhere($roomId === null
            ? $qb->expr()->isNull('room_id')
            : $qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)));

        if ($role !== null) {
            $qb->andWhere($qb->expr()->eq('notes', $qb->createNamedParameter($role)));
        }

        try {
            /** @var RosterShift */
            return $this->findEntity($qb);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Publish every draft shift of one team in a date range.
     *
     * One statement rather than a loop: publishing a week is one act, and the
     * timestamp on every shift in it has to be the same one. Already-published
     * shifts are left alone, so re-publishing a week after editing one day
     * does not rewrite the date the rest of it was communicated — which is the
     * date that matters if anybody ever has to show the notice period was met.
     *
     * @return int rows published
     */
    public function publishRange(string $teamId, string $fromDate, string $toDate, string $publishedBy, int $at): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('published_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
            ->set('published_by', $qb->createNamedParameter($publishedBy))
            ->set('updated_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->gte('shift_date', $qb->createNamedParameter($fromDate)))
            ->andWhere($qb->expr()->lte('shift_date', $qb->createNamedParameter($toDate)))
            ->andWhere($qb->expr()->eq('published_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /** Every shift of a team, for the delete and archive cascades. */
    public function deleteByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        return $qb->executeStatement();
    }
}
