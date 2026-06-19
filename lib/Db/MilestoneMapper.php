<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MilestoneMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_milestones', Milestone::class);
    }

    public function findById(int $id): ?Milestone {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var Milestone */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All milestones for a team, oldest-created-first. Final display
     * ordering (dated-ascending, undated last) is applied in
     * MilestoneService — NULL ordering on milestone_date differs between
     * MySQL (NULLS FIRST by default on ASC) and PostgreSQL (NULLS LAST by
     * default on ASC), so we sort in PHP rather than rely on either DB's
     * default to stay cross-database consistent (DESIGN.md §1).
     *
     * @return Milestone[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    public function insertMilestone(
        string $teamId,
        string $label,
        ?int   $milestoneDate,
        string $createdBy
    ): Milestone {
        $row = new Milestone();
        $row->setTeamId($teamId);
        $row->setLabel($label);
        $row->setMilestoneDate($milestoneDate);
        $row->setCreatedBy($createdBy);
        $row->setCreatedAt(time());
        /** @var Milestone */
        return $this->insert($row);
    }

    /**
     * Delete all milestones for a team — for parity with the other
     * teamhub_dec_* mappers' deleteByTeamId() helpers, available for a
     * future team-deletion cleanup pass. Not currently called anywhere
     * (no teamhub_* table is purged on team deletion today — see
     * TeamService::deleteTeam, which only clears teamhub_team_apps and
     * provisioned NC app resources). Kept here so milestones aren't a
     * special case if/when that gap is closed.
     */
    public function deleteByTeamId(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
