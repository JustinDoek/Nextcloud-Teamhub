<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `teamhub_team_service` (v4.10.31, the service builder).
 *
 * @extends QBMapper<TeamService>
 */
class TeamServiceMapper extends QBMapper {

    public const TABLE = 'teamhub_team_service';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, TeamService::class);
    }

    public function findById(int $id): ?TeamService {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * One service team's services, drafts included, in display order.
     *
     * @return TeamService[]
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Every service that was ever published, listed or not — what the
     * workflow registry registers. An unpublished service stays registered
     * (dark) so the requests that started on it keep their title and steps.
     *
     * @return TeamService[]
     */
    public function findEverPublished(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->gt('pub_version', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Every service any team built, drafts included (v4.10.45): what a
     * category's usage counts before an administrator may remove it.
     *
     * @return TeamService[]
     */
    public function findAllServices(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * The services on the Services page, across service teams.
     *
     * @return TeamService[]
     */
    public function findListed(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('listed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('pub_version', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('team_id', 'ASC')
            ->addOrderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Has this team ever published a service (v4.10.33)? While it has, it
     * stays a desk: an unpublished service keeps its running requests, and
     * they need somebody to answer them.
     */
    public function hasEverPublished(string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->gt('pub_version', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found  = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }

    /** The next `sort_order` for a new service of this team: after the last one. */
    public function nextSortOrder(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('sort_order'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $result = $qb->executeQuery();
        $max    = $result->fetchOne();
        $result->closeCursor();
        return ($max === false || $max === null) ? 1 : (int)$max + 1;
    }

    /**
     * Take every service of a team off the Services page (v4.10.33) — the
     * team is going away. The rows stay, so requests made on them keep their
     * title and steps.
     */
    public function unlistByTeam(string $teamId, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('listed', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('listed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function countByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'n'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }
}
