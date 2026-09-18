<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for `teamhub_resource_link` (v4.9.6, OpenProject
 * Phase 2) — the generic ledger of what TeamHub created or linked for a team.
 *
 * One row per (team, application, resource type, resource id). The row says
 * *how* the resource came to be the team's (`created` by TeamHub or `linked`
 * from something that already existed), *which* provisioning operation did
 * it, who, when, when it was last seen alive and in what health.
 *
 * **This is a ledger, not an access registry.** `teamhub_team_app_resources`
 * keeps deciding which Talk room, folder, calendar and board a team may open,
 * and `teamhub_openproject_link` keeps being the link; both are unchanged.
 * What this table adds is the one fact neither of them holds — was it made
 * for this team or borrowed — which is what a safe rollback needs, and the
 * shape a future application can join without a schema change.
 */
class ResourceLinkMapper {

    private const TABLE = 'teamhub_resource_link';

    public function __construct(private IDBConnection $db) {}

    /**
     * Insert or refresh a row. The unique index makes the upsert: on a
     * duplicate, the mode, operation and metadata are updated and the
     * original `created_at` / `created_by` kept — a retry that finds its
     * own resource records it once.
     *
     * @param array<string,mixed>|null $meta
     */
    public function upsert(
        string  $teamId,
        string  $appId,
        string  $resourceType,
        string  $resourceId,
        string  $mode,
        ?int    $operationId,
        string  $createdBy,
        ?string $resourceUrl = null,
        ?array  $meta = null,
        string  $health = 'ok',
    ): array {
        $existing = $this->find($teamId, $appId, $resourceType, $resourceId);
        $now      = time();

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('mode',              $qb->createNamedParameter($mode))
                ->set('operation_id',      $qb->createNamedParameter($operationId, $operationId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
                ->set('resource_url',      $qb->createNamedParameter($resourceUrl === null ? null : mb_substr($resourceUrl, 0, 2048)))
                ->set('last_validated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('health',            $qb->createNamedParameter($health))
                ->set('meta_json',         $qb->createNamedParameter($meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            return $this->find($teamId, $appId, $resourceType, $resourceId) ?? $existing;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'team_id'           => $qb->createNamedParameter($teamId),
            'app_id'            => $qb->createNamedParameter($appId),
            'resource_type'     => $qb->createNamedParameter($resourceType),
            'resource_id'       => $qb->createNamedParameter(mb_substr($resourceId, 0, 255)),
            'resource_url'      => $qb->createNamedParameter($resourceUrl === null ? null : mb_substr($resourceUrl, 0, 2048)),
            'mode'              => $qb->createNamedParameter($mode),
            'operation_id'      => $qb->createNamedParameter($operationId, $operationId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'created_by'        => $qb->createNamedParameter($createdBy),
            'created_at'        => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'last_validated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'health'            => $qb->createNamedParameter($health),
            'meta_json'         => $qb->createNamedParameter($meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)),
        ]);
        $qb->executeStatement();

        return $this->find($teamId, $appId, $resourceType, $resourceId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $teamId, string $appId, string $resourceType, string $resourceId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
            ->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter(mb_substr($resourceId, 0, 255))))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every link of a team, application first.
     *
     * @return list<array<string,mixed>>
     */
    public function findByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('app_id', 'ASC')
            ->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Every link one operation wrote.
     *
     * @return list<array<string,mixed>>
     */
    public function findByOperation(int $operationId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('operation_id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    public function setHealth(int $id, string $health, bool $validated = true): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('health', $qb->createNamedParameter($health))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        if ($validated) {
            $qb->set('last_validated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
        }
        $qb->executeStatement();
    }

    public function delete(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** Part of the team-delete cascade. */
    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);

        return [
            'id'              => (int)$row['id'],
            'teamId'          => (string)$row['team_id'],
            'appId'           => (string)$row['app_id'],
            'resourceType'    => (string)$row['resource_type'],
            'resourceId'      => (string)$row['resource_id'],
            'resourceUrl'     => $row['resource_url'] !== null ? (string)$row['resource_url'] : null,
            'mode'            => (string)$row['mode'],
            'operationId'     => $row['operation_id'] !== null ? (int)$row['operation_id'] : null,
            'createdBy'       => (string)$row['created_by'],
            'createdAt'       => (int)$row['created_at'],
            'lastValidatedAt' => $row['last_validated_at'] !== null ? (int)$row['last_validated_at'] : null,
            'health'          => (string)$row['health'],
            'meta'            => is_array($meta) ? $meta : [],
        ];
    }
}
