<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_service_catalog` (WorkflowHub phase 5, v4.10.20).
 *
 * @extends QBMapper<ServiceCatalogEntry>
 */
class ServiceCatalogEntryMapper extends QBMapper {

    public const TABLE = 'teamhub_service_catalog';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, ServiceCatalogEntry::class);
    }

    /**
     * One service team's catalogue, in display order.
     *
     * @return ServiceCatalogEntry[]
     */
    public function findByTeam(string $teamId, bool $enabledOnly = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('service_key', 'ASC');
        if ($enabledOnly) {
            $qb->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        }
        return $this->findEntities($qb);
    }

    public function findEntry(string $teamId, string $serviceKey): ?ServiceCatalogEntry {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('service_key', $qb->createNamedParameter($serviceKey)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Every enabled entry for one workflow definition, across service teams.
     *
     * This is the lookup that decides which service team handles a request:
     * a definition offered by exactly one active service team resolves to
     * it. The caller settles what to do when two teams offer the same one.
     *
     * @return ServiceCatalogEntry[]
     */
    public function findEnabledForDefinition(string $definitionKey): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('definition_key', $qb->createNamedParameter($definitionKey)))
            ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('team_id', 'ASC');
        return $this->findEntities($qb);
    }

    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
