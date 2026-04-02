<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_widget_registry.
 *
 * One row per external app registration. An app registers its widget once;
 * team admins then opt individual teams into it via TeamWidgetMapper.
 */
class WidgetRegistryMapper {

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Return all registered widgets, ordered by creation date.
     *
     * @return array<int, array{id:int, app_id:string, title:string, description:string|null, icon:string|null, iframe_url:string, created_at:int}>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_widget_registry')
            ->orderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Find a single registry entry by its primary key.
     *
     * @throws \Exception When no row exists for the given ID.
     */
    public function findById(int $id): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_widget_registry')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception("Widget registry entry not found: {$id}");
        }
        return $this->hydrate($row);
    }

    /**
     * Find a registry entry by the registering app's ID.
     * Returns null when the app has not registered a widget.
     */
    public function findByAppId(string $appId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('teamhub_widget_registry')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row ? $this->hydrate($row) : null;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Insert a new registry entry.
     *
     * @return array The newly created row.
     */
    public function create(
        string  $appId,
        string  $title,
        ?string $description,
        ?string $icon,
        string  $iframeUrl,
    ): array {
        $qb  = $this->db->getQueryBuilder();
        $now = time();

        $qb->insert('teamhub_widget_registry')
            ->values([
                'app_id'      => $qb->createNamedParameter($appId),
                'title'       => $qb->createNamedParameter($title),
                'description' => $qb->createNamedParameter($description),
                'icon'        => $qb->createNamedParameter($icon),
                'iframe_url'  => $qb->createNamedParameter($iframeUrl),
                'created_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);

        $qb->executeStatement();
        $id = (int)$qb->getLastInsertId();

        return [
            'id'          => $id,
            'app_id'      => $appId,
            'title'       => $title,
            'description' => $description,
            'icon'        => $icon,
            'iframe_url'  => $iframeUrl,
            'created_at'  => $now,
        ];
    }

    /**
     * Update an existing registry entry (upsert pattern — called by register endpoint
     * when the app already has a row).
     */
    public function update(
        int     $id,
        string  $title,
        ?string $description,
        ?string $icon,
        string  $iframeUrl,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update('teamhub_widget_registry')
            ->set('title',       $qb->createNamedParameter($title))
            ->set('description', $qb->createNamedParameter($description))
            ->set('icon',        $qb->createNamedParameter($icon))
            ->set('iframe_url',  $qb->createNamedParameter($iframeUrl))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->findById($id);
    }

    /**
     * Delete a registry entry by app ID.
     * The service layer is responsible for cascading into teamhub_team_widgets first.
     */
    public function deleteByAppId(string $appId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_widget_registry')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function hydrate(array $row): array {
        return [
            'id'          => (int)$row['id'],
            'app_id'      => (string)$row['app_id'],
            'title'       => (string)$row['title'],
            'description' => isset($row['description']) ? (string)$row['description'] : null,
            'icon'        => isset($row['icon']) ? (string)$row['icon'] : null,
            'iframe_url'  => (string)$row['iframe_url'],
            'created_at'  => (int)$row['created_at'],
        ];
    }
}
