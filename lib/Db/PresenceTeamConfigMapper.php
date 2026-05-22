<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_presence_team.
 *
 * One row per team. Absence of a row = all defaults (presence_enabled=0,
 * hide_reasons=0). The service layer creates rows on first write.
 */
class PresenceTeamConfigMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_presence_team', PresenceTeamConfig::class);
    }

    /**
     * Find config for a team, or null if no row exists (caller treats null
     * as all-defaults).
     */
    public function findByTeam(string $teamId): ?PresenceTeamConfig {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1);

        try {
            /** @var PresenceTeamConfig */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
