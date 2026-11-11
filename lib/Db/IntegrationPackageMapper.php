<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data access for `teamhub_integ_pkg` — one row per registered integration
 * package (v4.13.23).
 *
 * The natural key is `(app_id, integration_key)`; `id` is a surrogate that
 * every other integration table points at. That only works because of one
 * invariant this mapper enforces by omission:
 *
 * **A package row, once created, is never deleted.** There is no `delete()`
 * here. Withdrawing a registration is {@see setRegistered()} with false, which
 * keeps the row, its id, the administrator's enablement, the template
 * inclusions and every per-team preference pointing at its capabilities. The
 * old model's `deleteByAppId()` cascade-deleted team opt-ins, which is exactly
 * how an app update used to cost a team admin their configuration.
 *
 * Raw QueryBuilder rather than QBMapper, matching
 * {@see IntegrationRegistryMapper} and the other lightweight mappers here.
 */
class IntegrationPackageMapper {

    private const TABLE = 'teamhub_integ_pkg';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Every package, newest registration last.
     *
     * @return list<array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('app_id', 'ASC')
            ->addOrderBy('integration_key', 'ASC');

        return $this->rows($qb);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $rows = $this->rows($qb);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * By the stable identity. The lookup every governance check starts from.
     *
     * @return array<string,mixed>|null
     */
    public function findByIdentity(string $appId, string $integrationKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('integration_key', $qb->createNamedParameter($integrationKey)))
            ->setMaxResults(1);

        $rows = $this->rows($qb);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Every package one app registered.
     *
     * @return list<array<string,mixed>>
     */
    public function findByAppId(string $appId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->orderBy('integration_key', 'ASC');

        return $this->rows($qb);
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>> keyed by id
     */
    public function findByIds(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from(self::TABLE)
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

            foreach ($this->rows($qb) as $row) {
                $out[(int)$row['id']] = $row;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Insert or update by stable identity, and return the row id.
     *
     * The idempotency guarantee the registration API promises lives here:
     * a second call with the same `(app_id, integration_key)` updates the
     * metadata and leaves `id`, `created_at` and every record pointing at this
     * package untouched.
     *
     * @param array<string,mixed> $metadata stored as JSON, never interpreted
     */
    public function upsert(
        string  $appId,
        string  $integrationKey,
        string  $title,
        ?string $description,
        ?string $icon,
        string  $version,
        string  $minThVersion,
        string  $maxThVersion,
        bool    $isLegacy,
        bool    $registered,
        array   $metadata,
        int     $now,
    ): int {
        $existing = $this->findByIdentity($appId, $integrationKey);
        $json     = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('title', $qb->createNamedParameter($title))
                ->set('description', $qb->createNamedParameter($description))
                ->set('icon', $qb->createNamedParameter($icon))
                ->set('version', $qb->createNamedParameter($version))
                ->set('min_th_version', $qb->createNamedParameter($minThVersion))
                ->set('max_th_version', $qb->createNamedParameter($maxThVersion))
                // `is_legacy` is never upgraded from 1 to 0 here by a legacy
                // write, but a real package registration over a legacy row
                // does clear it: the app has adopted the new API and the
                // administration screen must stop calling it legacy.
                ->set('is_legacy', $qb->createNamedParameter($isLegacy ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('registered', $qb->createNamedParameter($registered ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('metadata', $qb->createNamedParameter($json))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));

            if ($registered) {
                $qb->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
            }

            $qb->executeStatement();

            return (int)$existing['id'];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'app_id'          => $qb->createNamedParameter($appId),
                'integration_key' => $qb->createNamedParameter($integrationKey),
                'title'           => $qb->createNamedParameter($title),
                'description'     => $qb->createNamedParameter($description),
                'icon'            => $qb->createNamedParameter($icon),
                'version'         => $qb->createNamedParameter($version),
                'min_th_version'  => $qb->createNamedParameter($minThVersion),
                'max_th_version'  => $qb->createNamedParameter($maxThVersion),
                'is_legacy'       => $qb->createNamedParameter($isLegacy ? 1 : 0, IQueryBuilder::PARAM_INT),
                'registered'      => $qb->createNamedParameter($registered ? 1 : 0, IQueryBuilder::PARAM_INT),
                'metadata'        => $qb->createNamedParameter($json),
                'created_at'      => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at'      => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'last_seen_at'    => $qb->createNamedParameter($registered ? $now : 0, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*' . self::TABLE);
    }

    /**
     * Mark a package registered or withdrawn.
     *
     * The row stays either way. This is the whole of "deregistration": the
     * package stops being effective and nothing it configured is lost.
     */
    public function setRegistered(int $id, bool $registered, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('registered', $qb->createNamedParameter($registered ? 1 : 0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($registered) {
            $qb->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
        }

        $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        $metadata = [];
        if (isset($row['metadata']) && (string)$row['metadata'] !== '') {
            $decoded  = json_decode((string)$row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return [
            'id'              => (int)$row['id'],
            'app_id'          => (string)$row['app_id'],
            'integration_key' => (string)$row['integration_key'],
            'title'           => (string)$row['title'],
            'description'     => isset($row['description']) ? (string)$row['description'] : null,
            'icon'            => isset($row['icon']) ? (string)$row['icon'] : null,
            'version'         => (string)($row['version'] ?? ''),
            'min_th_version'  => (string)($row['min_th_version'] ?? ''),
            'max_th_version'  => (string)($row['max_th_version'] ?? ''),
            'is_legacy'       => (int)($row['is_legacy'] ?? 0) === 1,
            'registered'      => (int)($row['registered'] ?? 0) === 1,
            'metadata'        => $metadata,
            'created_at'      => (int)($row['created_at'] ?? 0),
            'updated_at'      => (int)($row['updated_at'] ?? 0),
            'last_seen_at'    => (int)($row['last_seen_at'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(IQueryBuilder $qb): array {
        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }
}
