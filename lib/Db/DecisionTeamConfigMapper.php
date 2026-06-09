<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_decision_team.
 *
 * One row per team. Absence of a row = decisions_enabled=0 (disabled).
 * The service layer creates rows on first write.
 */
class DecisionTeamConfigMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_decision_team', DecisionTeamConfig::class);
    }

    /**
     * Find config for a team, or null if no row exists (caller treats null
     * as all-defaults — decisions_enabled=0).
     */
    public function findByTeam(string $teamId): ?DecisionTeamConfig {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        try {
            /** @var DecisionTeamConfig */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Count teams that have decisions_enabled = 1.
     * Used for telemetry.
     */
    public function countEnabledTeams(): int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('decisions_enabled', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $val = $result->fetchOne();
        $result->closeCursor();
        return (int)$val;
    }
}
