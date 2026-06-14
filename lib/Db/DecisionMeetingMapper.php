<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DecisionMeetingMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_dec_meetings', DecisionMeeting::class);
    }

    public function findById(int $id): ?DecisionMeeting {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var DecisionMeeting */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All scheduled meetings for a single proposal, ordered by scheduled
     * start time ascending (next-upcoming first).
     *
     * @return DecisionMeeting[]
     */
    public function findByDecisionId(int $decisionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('meeting_start', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function insertMeeting(
        string $teamId,
        int    $decisionId,
        string $eventUid,
        string $meetingTitle,
        int    $meetingStart,
        string $scheduledBy
    ): DecisionMeeting {
        $m = new DecisionMeeting();
        $m->setTeamId($teamId);
        $m->setDecisionId($decisionId);
        $m->setEventUid($eventUid);
        $m->setMeetingTitle($meetingTitle);
        $m->setMeetingStart($meetingStart);
        $m->setScheduledBy($scheduledBy);
        $m->setCreatedAt(time());
        /** @var DecisionMeeting */
        return $this->insert($m);
    }

    /**
     * Delete all meetings for a team — used when a team is deleted.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Delete all meetings for a specific decision — called when the
     * decision is deleted.
     */
    public function deleteByDecisionId(int $decisionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('decision_id', $qb->createNamedParameter($decisionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
