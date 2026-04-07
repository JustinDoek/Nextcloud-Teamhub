<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_team_integrations.
 *
 * Each row represents one integration enabled for one team.
 * Replaces both TeamWidgetMapper (sidebar widgets) and TeamAppMapper (Talk/Files/Cal/Deck).
 *
 */
class TeamIntegrationMapper {

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Return all enabled integrations for a team, joined with registry metadata.
     * Ordered by sort_order ASC, then enabled_at ASC as tiebreaker.
     *
     * @return array<int, array>
     */
    public function findEnabledForTeam(string $teamId): array {

        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'ti.id',
                'ti.registry_id',
                'ti.team_id',
                'ti.sort_order',
                'ti.enabled_at',
                'ir.app_id',
                'ir.integration_type',
                'ir.title',
                'ir.description',
                'ir.icon',
                'ir.php_class',
                'ir.iframe_url',
                'ir.is_builtin',
            )
            ->from('teamhub_team_integrations', 'ti')
            ->innerJoin(
                'ti',
                'teamhub_integration_registry',
                'ir',
                $qb->expr()->eq('ti.registry_id', 'ir.id')
            )
            ->where($qb->expr()->eq('ti.team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('ti.sort_order', 'ASC')
            ->addOrderBy('ti.enabled_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrateJoined($row);
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Return all enabled integrations of a specific type for a team.
     * Used to load only widgets (sidebar) or only menu_items (tab bar).
     *
     * @param string $type 'widget' | 'menu_item'
     * @return array<int, array>
     */
    public function findEnabledForTeamByType(string $teamId, string $type): array {

        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'ti.id',
                'ti.registry_id',
                'ti.team_id',
                'ti.sort_order',
                'ti.enabled_at',
                'ir.app_id',
                'ir.integration_type',
                'ir.title',
                'ir.description',
                'ir.icon',
                'ir.php_class',
                'ir.iframe_url',
                'ir.is_builtin',
            )
            ->from('teamhub_team_integrations', 'ti')
            ->innerJoin(
                'ti',
                'teamhub_integration_registry',
                'ir',
                $qb->expr()->eq('ti.registry_id', 'ir.id')
            )
            ->where($qb->expr()->eq('ti.team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('ir.integration_type', $qb->createNamedParameter($type)))
            ->orderBy('ti.sort_order', 'ASC')
            ->addOrderBy('ti.enabled_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrateJoined($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Return all registry entries annotated with enabled state and sort_order for a team.
     * Used by Manage Team → Integrations to render the full list with checkboxes.
     *
     * Single LEFT JOIN query — no N+1.
     * Columns prefixed ir_ are registry fields; ti_ are team-integration fields.
     *
     * @return array<int, array>  Each item includes: registry_id, app_id, integration_type,
     *                            title, description, icon, php_class,
     *                            iframe_url, is_builtin, enabled (bool), sort_order.
     */
    public function findAllWithEnabledStateForTeam(string $teamId): array {

        $qb = $this->db->getQueryBuilder();
        // Use selectAlias() for aliased columns to avoid trailing-space issues
        // that cause MySQL/MariaDB to reject 'ir.id   AS ir_id' as unknown column.
        $qb->select(
                'ir.app_id',
                'ir.integration_type',
                'ir.title',
                'ir.description',
                'ir.icon',
                'ir.php_class',
                'ir.iframe_url',
                'ir.is_builtin',
            )
            ->selectAlias('ir.id',         'ir_id')
            ->selectAlias('ir.created_at', 'ir_created_at')
            ->selectAlias('ti.sort_order', 'ti_sort_order')
            ->from('teamhub_integration_registry', 'ir')
            ->leftJoin(
                'ir',
                'teamhub_team_integrations',
                'ti',
                $qb->expr()->andX(
                    $qb->expr()->eq('ti.registry_id', 'ir.id'),
                    $qb->expr()->eq('ti.team_id', $qb->createNamedParameter($teamId))
                )
            )
            ->orderBy('ir.is_builtin', 'DESC')
            ->addOrderBy('ir.created_at', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $isEnabled = $row['ti_sort_order'] !== null;
            $out[] = [
                'registry_id'      => (int)$row['ir_id'],
                'app_id'           => (string)$row['app_id'],
                'integration_type' => (string)$row['integration_type'],
                'title'            => (string)$row['title'],
                'description'      => isset($row['description']) ? (string)$row['description'] : null,
                'icon'             => isset($row['icon'])        ? (string)$row['icon']        : null,
                'php_class'        => isset($row['php_class'])   ? (string)$row['php_class']   : null,
                'iframe_url'       => isset($row['iframe_url'])  ? (string)$row['iframe_url']  : null,
                'is_builtin'       => (bool)$row['is_builtin'],
                'enabled'          => $isEnabled,
                'sort_order'       => $isEnabled ? (int)$row['ti_sort_order'] : 0,
            ];
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Check whether a specific integration is enabled for a team.
     */
    public function isEnabled(int $registryId, string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('teamhub_team_integrations')
            ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return (bool)$row;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Enable an integration for a team. Idempotent.
     */
    public function enable(int $registryId, string $teamId): void {

        if ($this->isEnabled($registryId, $teamId)) {
            return;
        }

        $sortOrder = $this->getNextSortOrder($teamId);

        $qb  = $this->db->getQueryBuilder();
        $now = time();
        $qb->insert('teamhub_team_integrations')
            ->values([
                'registry_id' => $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT),
                'team_id'     => $qb->createNamedParameter($teamId),
                'sort_order'  => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
                'enabled_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
    }

    /**
     * Disable (remove) an integration from a team.
     */
    public function disable(int $registryId, string $teamId): void {

        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_team_integrations')
            ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }

    /**
     * Bulk-update sort_order for a team's integrations.
     * $orderedRegistryIds is an array of registry IDs in the desired display order.
     *
     * @param int[] $orderedRegistryIds
     */
    public function reorder(string $teamId, array $orderedRegistryIds): void {

        foreach ($orderedRegistryIds as $position => $registryId) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('teamhub_team_integrations')
                ->set('sort_order', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter((int)$registryId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
            $qb->executeStatement();
        }
    }

    /**
     * Remove all team integration rows that reference a given registry entry.
     * Called when an app deregisters its integration (cascade cleanup).
     */
    public function deleteByRegistryId(int $registryId): void {

        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_team_integrations')
            ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function getNextSortOrder(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('sort_order'))
            ->from('teamhub_team_integrations')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $max    = $result->fetchOne();
        $result->closeCursor();

        return $max !== false && $max !== null ? (int)$max + 1 : 0;
    }

    private function hydrateJoined(array $row): array {
        return [
            'id'               => (int)$row['id'],
            'registry_id'      => (int)$row['registry_id'],
            'team_id'          => (string)$row['team_id'],
            'sort_order'       => (int)$row['sort_order'],
            'enabled_at'       => (int)$row['enabled_at'],
            'app_id'           => (string)$row['app_id'],
            'integration_type' => (string)$row['integration_type'],
            'title'            => (string)$row['title'],
            'description'      => isset($row['description']) ? (string)$row['description'] : null,
            'icon'             => isset($row['icon'])        ? (string)$row['icon']        : null,
            'php_class'        => isset($row['php_class'])   ? (string)$row['php_class']   : null,
            'iframe_url'       => isset($row['iframe_url'])  ? (string)$row['iframe_url']  : null,
            'is_builtin'       => (bool)$row['is_builtin'],
        ];
    }
}
