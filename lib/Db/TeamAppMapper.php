<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TeamAppMapper {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Find all app configurations for a team
     */
    public function findByTeamId(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select('*')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('app_id', 'ASC');
        
        $result = $qb->executeQuery();
        $apps = [];
        
        while ($row = $result->fetch()) {
            $apps[] = [
                'id' => (int)$row['id'],
                'team_id' => $row['team_id'],
                'app_id' => $row['app_id'],
                'enabled' => (bool)$row['enabled'],
                'config' => $row['config'] ? json_decode($row['config'], true) : null,
            ];
        }
        
        $result->closeCursor();
        return $apps;
    }

    /**
     * Insert or update app configuration for a team
     */
    public function upsert(string $teamId, string $appId, bool $enabled, ?string $config): void {
        // Check if exists
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        
        if ($row) {
            // Update
            $qb = $this->db->getQueryBuilder();
            $qb->update('teamhub_team_apps')
                ->set('enabled', $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL))
                ->set('config', $qb->createNamedParameter($config))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        } else {
            // Insert
            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_team_apps')
                ->values([
                    'team_id' => $qb->createNamedParameter($teamId),
                    'app_id' => $qb->createNamedParameter($appId),
                    'enabled' => $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL),
                    'config' => $qb->createNamedParameter($config),
                ]);
            $qb->executeStatement();
        }
    }

    /**
     * Delete an app configuration
     */
    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete('teamhub_team_apps')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        $qb->executeStatement();
    }
}
