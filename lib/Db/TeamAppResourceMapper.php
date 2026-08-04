<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for teamhub_team_app_resources.
 *
 * Each row records one (team, app, resource) association plus its lifecycle
 * state. See TeamAppResource for status/origin/risk_status enum values.
 */
class TeamAppResourceMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'teamhub_team_app_resources', TeamAppResource::class);
    }

    // -------------------------------------------------------------------------
    // Core lookups
    // -------------------------------------------------------------------------

    /**
     * All active resources for a team + app combination, ordered by display_order.
     *
     * @return TeamAppResource[]
     */
    public function findActiveByTeamAndApp(string $teamId, string $appId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',  $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('status',  $qb->createNamedParameter('active')))
            ->orderBy('display_order', 'ASC')
            ->addOrderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Count of active resources for a team + app.
     * Used to derive whether an app tab should be shown.
     */
    public function countActiveByTeamAndApp(string $teamId, string $appId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',  $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('status',  $qb->createNamedParameter('active')));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * All resources for a team across all apps and statuses.
     * Used by discovery reconciliation and settings panel.
     *
     * @return TeamAppResource[]
     */
    public function findAllByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('app_id', 'ASC')
            ->addOrderBy('display_order', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Resources awaiting an admin's accept/ignore decision (v4.5.45).
     *
     * Its own query rather than a filter over findAllByTeam(): My Work asks
     * this per team on every render, and pending rows are a small minority of
     * the table on a settled instance.
     *
     * @return TeamAppResource[]
     */
    public function findPendingByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
            ->orderBy('app_id', 'ASC')
            ->addOrderBy('display_order', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * All resources for a team + app regardless of status.
     * Used by discovery reconciliation to compare against live ACL state.
     *
     * @return TeamAppResource[]
     */
    public function findAllByTeamAndApp(string $teamId, string $appId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',  $qb->createNamedParameter($appId)))
            ->orderBy('display_order', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find a specific (team, app, resource_id) row or null.
     */
    public function findByTeamAppResource(
        string $teamId,
        string $appId,
        string $resourceId
    ): ?TeamAppResource {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id',     $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id',     $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)))
            ->setMaxResults(1);

        try {
            /** @var TeamAppResource */
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Risk-status queries (used by Teaminfo widget warning block, Session B+)
    // -------------------------------------------------------------------------

    /**
     * Count rows for a team where risk_status is not 'none'.
     * Used by the Teaminfo widget to surface the warning block count.
     */
    public function countAtRiskByTeam(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id',     $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->neq('risk_status', $qb->createNamedParameter('none')));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    // -------------------------------------------------------------------------
    // Write helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a new resource row and return the persisted entity.
     */
    public function insertResource(
        string  $teamId,
        string  $appId,
        string  $resourceId,
        string  $origin,
        string  $status     = 'active',
        string  $riskStatus = 'none',
        int     $displayOrder = 0,
        ?string $decidedBy  = null,
        ?int    $decidedAt  = null,
        ?string $ownerUid   = null,
    ): TeamAppResource {
        $now    = time();
        $entity = new TeamAppResource();
        $entity->setTeamId($teamId);
        $entity->setAppId($appId);
        $entity->setResourceId($resourceId);
        $entity->setOrigin($origin);
        $entity->setStatus($status);
        $entity->setRiskStatus($riskStatus);
        $entity->setDisplayOrder($displayOrder);
        $entity->setDecidedBy($decidedBy);
        $entity->setDecidedAt($decidedAt);
        $entity->setRiskSetAt(null);
        $entity->setOwnerUid($ownerUid);
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);

        /** @var TeamAppResource */
        return $this->insert($entity);
    }

    /**
     * Find all resource rows owned by a given NC user, across all teams and apps.
     * Used by UserStatusListener and UserDeletedListener.
     *
     * @return TeamAppResource[]
     */
    public function findByOwnerUid(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)));

        return $this->findEntities($qb);
    }

    /**
     * Update risk_status and risk_set_at for all resource rows owned by a given uid.
     * Used by UserStatusListener when a user is disabled or re-enabled.
     */
    public function updateRiskStatusByOwner(string $ownerUid, string $riskStatus): void {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('risk_status', $qb->createNamedParameter($riskStatus))
            ->set('risk_set_at', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->set('updated_at',  $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));

        $qb->executeStatement();
    }

    /**
     * Update owner_uid for a single resource row.
     * Used after a successful ownership transfer (BeforeUserDeletedEvent).
     */
    public function updateOwnerUid(int $id, ?string $ownerUid): void {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('owner_uid', $qb->createNamedParameter($ownerUid))
            ->set('updated_at', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
    }

    /**
     * Update status (and optionally decided_by / decided_at) on an existing row.
     */
    public function updateStatus(
        int     $id,
        string  $status,
        ?string $decidedBy = null,
        ?int    $decidedAt = null
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status',     $qb->createNamedParameter($status))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));

        if ($decidedBy !== null) {
            $qb->set('decided_by', $qb->createNamedParameter($decidedBy));
        }
        if ($decidedAt !== null) {
            $qb->set('decided_at', $qb->createNamedParameter($decidedAt, IQueryBuilder::PARAM_INT));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Update risk_status and risk_set_at on a row.
     */
    public function updateRiskStatus(int $id, string $riskStatus): void {
        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('risk_status', $qb->createNamedParameter($riskStatus))
            ->set('risk_set_at', $qb->createNamedParameter(
                $riskStatus === 'none' ? null : $now,
                $riskStatus === 'none' ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT
            ))
            ->set('updated_at',  $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete a resource row by its primary key.
     */
    public function deleteById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all resource rows for a team.
     * Called when a team is fully deleted (hard delete path).
     */
    public function deleteAllByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }
}
