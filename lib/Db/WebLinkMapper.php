<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class WebLinkMapper {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Find all web links for a team
     */
    public function findByTeamId(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select('*')
            ->from('teamhub_web_links')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('created_at', 'ASC');
        
        $result = $qb->executeQuery();
        $links = [];
        
        while ($row = $result->fetch()) {
            $links[] = [
                'id' => (int)$row['id'],
                'team_id' => $row['team_id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'sort_order' => (int)$row['sort_order'],
                'created_at' => (int)$row['created_at'],
            ];
        }
        
        $result->closeCursor();
        return $links;
    }

    /**
     * Create a new web link
     */
    public function create(string $teamId, string $title, string $url): array {
        $qb = $this->db->getQueryBuilder();
        
        // Get the next sort order
        $sortOrder = $this->getNextSortOrder($teamId);
        
        $qb->insert('teamhub_web_links')
            ->values([
                'team_id' => $qb->createNamedParameter($teamId),
                'title' => $qb->createNamedParameter($title),
                'url' => $qb->createNamedParameter($url),
                'sort_order' => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        
        $qb->executeStatement();
        $id = $qb->getLastInsertId();
        
        return [
            'id' => (int)$id,
            'team_id' => $teamId,
            'title' => $title,
            'url' => $url,
            'sort_order' => $sortOrder,
            'created_at' => time(),
        ];
    }

    /**
     * Update a web link
     */
    public function update(int $id, string $title, string $url, int $sortOrder): array {
        $qb = $this->db->getQueryBuilder();
        
        $qb->update('teamhub_web_links')
            ->set('title', $qb->createNamedParameter($title))
            ->set('url', $qb->createNamedParameter($url))
            ->set('sort_order', $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        $qb->executeStatement();
        
        // Fetch and return updated record
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_web_links')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        
        if (!$row) {
            throw new \Exception('Link not found');
        }
        
        return [
            'id' => (int)$row['id'],
            'team_id' => $row['team_id'],
            'title' => $row['title'],
            'url' => $row['url'],
            'sort_order' => (int)$row['sort_order'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    /**
     * Delete a web link
     */
    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete('teamhub_web_links')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        
        $qb->executeStatement();
    }

    /**
     * Get the next sort order for a team
     */
    private function getNextSortOrder(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select($qb->func()->max('sort_order'))
            ->from('teamhub_web_links')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        
        $result = $qb->executeQuery();
        $maxOrder = $result->fetchOne();
        $result->closeCursor();
        
        return $maxOrder ? (int)$maxOrder + 1 : 0;
    }
}
