<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data access for `teamhub_integ_cap` — one row per capability inside a
 * package (v4.13.23).
 *
 * Same invariant as {@see IntegrationPackageMapper}, and for a sharper
 * reason: **a capability row is never deleted.** A per-team shown/hidden
 * preference and its ordering point at `cap_id`, so a capability row that
 * came and went would take a team administrator's decision with it. A package
 * that stops registering a capability leaves the row behind with
 * `registered = 0`; registering it again makes the same row effective, with
 * every preference intact.
 *
 * That is why {@see markUnregisteredExcept()} exists instead of a delete: a
 * re-registration says "these are my capabilities now", and everything else
 * this package once had becomes unregistered rather than gone.
 */
class IntegrationCapabilityMapper {

    private const TABLE = 'teamhub_integ_cap';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Every capability of one package, in render order.
     *
     * Ordered by type then `default_order` then key: the key is the
     * tiebreaker so two capabilities that forgot to set an order still come
     * back in the same sequence on every request, rather than in whatever
     * order the database happens to return.
     *
     * @return list<array<string,mixed>>
     */
    public function findByPackage(int $pkgId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)))
            ->orderBy('cap_type', 'ASC')
            ->addOrderBy('default_order', 'ASC')
            ->addOrderBy('capability_key', 'ASC');

        return $this->rows($qb);
    }

    /**
     * Capabilities of several packages at once.
     *
     * The workspace render reads every effective package's capabilities in
     * one query rather than one per package.
     *
     * @param list<int> $pkgIds
     * @return array<int,list<array<string,mixed>>> keyed by pkg_id
     */
    public function findByPackages(array $pkgIds): array {
        $pkgIds = array_values(array_unique(array_map('intval', $pkgIds)));
        if ($pkgIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($pkgIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from(self::TABLE)
                ->where($qb->expr()->in('pkg_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('cap_type', 'ASC')
                ->addOrderBy('default_order', 'ASC')
                ->addOrderBy('capability_key', 'ASC');

            foreach ($this->rows($qb) as $row) {
                $out[(int)$row['pkg_id']][] = $row;
            }
        }

        return $out;
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

    /** @return array<string,mixed>|null */
    public function findByKey(int $pkgId, string $capabilityKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('capability_key', $qb->createNamedParameter($capabilityKey)))
            ->setMaxResults(1);

        $rows = $this->rows($qb);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Every registered capability of one type across the instance, with its
     * package's identity joined on.
     *
     * How the My Work provider registry discovers external sources at boot:
     * one query on the `cap_type` index rather than a walk over every
     * capability on the instance.
     *
     * @return list<array<string,mixed>>
     */
    public function findRegisteredByType(string $capType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'c.id',
                'c.pkg_id',
                'c.capability_key',
                'c.cap_type',
                'c.title',
                'c.description',
                'c.icon',
                'c.provider_class',
                'c.iframe_url',
                'c.default_order',
                'c.surfaces',
                'c.presentation',
                'c.actions',
                'c.registered',
                'c.created_at',
                'c.updated_at',
            )
            ->selectAlias('p.app_id', 'app_id')
            ->selectAlias('p.integration_key', 'integration_key')
            ->selectAlias('p.title', 'package_title')
            ->selectAlias('p.icon', 'package_icon')
            ->selectAlias('p.registered', 'package_registered')
            ->from(self::TABLE, 'c')
            ->innerJoin('c', 'teamhub_integ_pkg', 'p', $qb->expr()->eq('c.pkg_id', 'p.id'))
            ->where($qb->expr()->eq('c.cap_type', $qb->createNamedParameter($capType)))
            ->andWhere($qb->expr()->eq('c.registered', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            // The package has to be registered too, not just the capability.
            // Found on the fresh-database run: the legacy backfill writes
            // capability rows for a package it deliberately leaves
            // `registered = 0` (reconstructing rows is not registering them),
            // so a capability alone passing this filter made
            // `Application::register()` construct a provider class for an app
            // that may not even be installed — one logged error per boot, for
            // a provider nothing would ever have called. A capability of an
            // unregistered package is not a registered capability.
            ->andWhere($qb->expr()->eq('p.registered', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('p.app_id', 'ASC')
            ->addOrderBy('p.integration_key', 'ASC')
            ->addOrderBy('c.capability_key', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $hydrated = $this->hydrate($row);
            $hydrated['app_id']             = (string)$row['app_id'];
            $hydrated['integration_key']    = (string)$row['integration_key'];
            $hydrated['package_title']      = (string)$row['package_title'];
            $hydrated['package_icon']       = isset($row['package_icon']) ? (string)$row['package_icon'] : null;
            $hydrated['package_registered'] = (int)$row['package_registered'] === 1;
            $out[] = $hydrated;
        }
        $result->closeCursor();

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Insert or update by `(pkg_id, capability_key)`, and return the row id.
     *
     * @param list<string>                                           $surfaces
     * @param array<string,mixed>                                    $presentation
     * @param list<array{label: string, url: string, icon: ?string}> $actions
     */
    public function upsert(
        int     $pkgId,
        string  $capabilityKey,
        string  $capType,
        string  $title,
        ?string $description,
        ?string $icon,
        ?string $providerClass,
        ?string $iframeUrl,
        int     $defaultOrder,
        array   $surfaces,
        array   $presentation,
        int     $now,
        array   $actions = [],
    ): int {
        $existing     = $this->findByKey($pkgId, $capabilityKey);
        $surfaceList  = implode(';', array_values(array_filter(array_map('strval', $surfaces), static fn (string $s): bool => $s !== '')));
        $presentJson  = $presentation === [] ? null : json_encode($presentation, JSON_UNESCAPED_UNICODE);
        // v4.13.29 — NULL rather than '[]' for a capability that declares
        // none, so "declares nothing" and "declares an empty list" are one
        // row shape rather than two.
        $actionsJson  = $actions === [] ? null : json_encode(array_values($actions), JSON_UNESCAPED_UNICODE);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                // The type may legitimately change if an app rebuilds a
                // capability under the same key. The preferences follow the
                // key, which is what the app promised not to move.
                ->set('cap_type', $qb->createNamedParameter($capType))
                ->set('title', $qb->createNamedParameter($title))
                ->set('description', $qb->createNamedParameter($description))
                ->set('icon', $qb->createNamedParameter($icon))
                ->set('provider_class', $qb->createNamedParameter($providerClass))
                ->set('iframe_url', $qb->createNamedParameter($iframeUrl))
                ->set('default_order', $qb->createNamedParameter($defaultOrder, IQueryBuilder::PARAM_INT))
                ->set('surfaces', $qb->createNamedParameter($surfaceList))
                ->set('presentation', $qb->createNamedParameter($presentJson))
                ->set('actions', $qb->createNamedParameter($actionsJson))
                ->set('registered', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            return (int)$existing['id'];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'pkg_id'         => $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT),
                'capability_key' => $qb->createNamedParameter($capabilityKey),
                'cap_type'       => $qb->createNamedParameter($capType),
                'title'          => $qb->createNamedParameter($title),
                'description'    => $qb->createNamedParameter($description),
                'icon'           => $qb->createNamedParameter($icon),
                'provider_class' => $qb->createNamedParameter($providerClass),
                'iframe_url'     => $qb->createNamedParameter($iframeUrl),
                'default_order'  => $qb->createNamedParameter($defaultOrder, IQueryBuilder::PARAM_INT),
                'surfaces'       => $qb->createNamedParameter($surfaceList),
                'presentation'   => $qb->createNamedParameter($presentJson),
                'actions'        => $qb->createNamedParameter($actionsJson),
                'registered'     => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'created_at'     => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at'     => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return (int)$this->db->lastInsertId('*PREFIX*' . self::TABLE);
    }

    /**
     * Mark every capability of a package unregistered except the named keys.
     *
     * Called at the end of a registration: what the package just declared
     * stays registered, and anything it used to declare becomes unregistered
     * rather than deleted. Pass `[]` to withdraw the whole package.
     *
     * @param list<string> $keepKeys
     */
    public function markUnregisteredExcept(int $pkgId, array $keepKeys, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('registered', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)));

        $keepKeys = array_values(array_unique(array_filter(array_map('strval', $keepKeys), static fn (string $k): bool => $k !== '')));
        if ($keepKeys !== []) {
            $qb->andWhere($qb->expr()->notIn(
                'capability_key',
                $qb->createNamedParameter($keepKeys, IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }

        $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        $presentation = [];
        if (isset($row['presentation']) && (string)$row['presentation'] !== '') {
            $decoded      = json_decode((string)$row['presentation'], true);
            $presentation = is_array($decoded) ? $decoded : [];
        }

        // v4.13.29 — the declared header links. Anything that is not a
        // well-formed entry is skipped: the column is written only by a
        // validated registration, so a row that disagrees is damage, and a
        // card with no menu is a better answer than a menu with a dead item.
        $actions = [];
        if (isset($row['actions']) && (string)$row['actions'] !== '') {
            $decoded = json_decode((string)$row['actions'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $action) {
                    if (!is_array($action)) {
                        continue;
                    }
                    $label = trim((string)($action['label'] ?? ''));
                    $url   = trim((string)($action['url'] ?? ''));
                    if ($label === '' || $url === '') {
                        continue;
                    }
                    $icon      = trim((string)($action['icon'] ?? ''));
                    $actions[] = [
                        'label' => $label,
                        'url'   => $url,
                        'icon'  => $icon === '' ? null : $icon,
                    ];
                }
            }
        }

        $surfaces = (string)($row['surfaces'] ?? '');

        return [
            'id'             => (int)$row['id'],
            'pkg_id'         => (int)$row['pkg_id'],
            'capability_key' => (string)$row['capability_key'],
            'cap_type'       => (string)$row['cap_type'],
            'title'          => (string)$row['title'],
            'description'    => isset($row['description']) ? (string)$row['description'] : null,
            'icon'           => isset($row['icon']) ? (string)$row['icon'] : null,
            'provider_class' => isset($row['provider_class']) ? (string)$row['provider_class'] : null,
            'iframe_url'     => isset($row['iframe_url']) ? (string)$row['iframe_url'] : null,
            'default_order'  => (int)($row['default_order'] ?? 0),
            'surfaces'       => $surfaces === '' ? [] : explode(';', $surfaces),
            'presentation'   => $presentation,
            'actions'        => $actions,
            'registered'     => (int)($row['registered'] ?? 0) === 1,
            'created_at'     => (int)($row['created_at'] ?? 0),
            'updated_at'     => (int)($row['updated_at'] ?? 0),
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
