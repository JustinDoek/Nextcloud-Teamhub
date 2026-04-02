<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data-access layer for teamhub_team_widgets.
 *
 * Each row represents a single widget being enabled for a single team.
 * Joined with teamhub_widget_registry when rendering the sidebar or
 * the Manage Team → Widgets tab.
 */
class TeamWidgetMapper {

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Return all enabled widgets for a team, joined with registry metadata.
     * Ordered by sort_order ASC so the sidebar respects admin drag-order.
     *
     * @return array<int, array{id:int, registry_id:int, team_id:string, sort_order:int, enabled_at:int, app_id:string, title:string, description:string|null, icon:string|null, iframe_url:string}>
     */
    public function findEnabledForTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'tw.id',
                'tw.registry_id',
                'tw.team_id',
                'tw.sort_order',
                'tw.enabled_at',
                'wr.app_id',
                'wr.title',
                'wr.description',
                'wr.icon',
                'wr.iframe_url',
            )
            ->from('teamhub_team_widgets', 'tw')
            ->innerJoin('tw', 'teamhub_widget_registry', 'wr', $qb->expr()->eq('tw.registry_id', 'wr.id'))
            ->where($qb->expr()->eq('tw.team_id', $qb->createNamedParameter($teamId)))
            ->orderBy('tw.sort_order', 'ASC')
            ->addOrderBy('tw.enabled_at', 'ASC');

        $result = $qb->executeQuery();
        $rows   = [];
        while ($row = $result->fetch()) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();
        return $rows;
    }

    /**
     * Return all registry entries annotated with whether they are enabled for
     * this team. Used by the Manage Team → Widgets tab to show the full list
     * with checkboxes.
     *
     * @return array<int, array{registry_id:int, app_id:string, title:string, description:string|null, icon:string|null, iframe_url:string, enabled:bool, sort_order:int}>
     */
    public function findAllWithEnabledStateForTeam(string $teamId): array {
        // Fetch all registry entries.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'app_id', 'title', 'description', 'icon', 'iframe_url')
            ->from('teamhub_widget_registry')
            ->orderBy('created_at', 'ASC');

        $result   = $qb->executeQuery();
        $registry = [];
        while ($row = $result->fetch()) {
            $registry[(int)$row['id']] = $row;
        }
        $result->closeCursor();

        if (empty($registry)) {
            return [];
        }

        // Fetch enabled rows for this team.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('registry_id', 'sort_order')
            ->from('teamhub_team_widgets')
            ->where($qb2->expr()->eq('team_id', $qb2->createNamedParameter($teamId)));

        $result2 = $qb2->executeQuery();
        $enabled = [];
        while ($row = $result2->fetch()) {
            $enabled[(int)$row['registry_id']] = (int)$row['sort_order'];
        }
        $result2->closeCursor();

        // Merge.
        $out = [];
        foreach ($registry as $regId => $reg) {
            $isEnabled  = isset($enabled[$regId]);
            $out[] = [
                'registry_id' => $regId,
                'app_id'      => (string)$reg['app_id'],
                'title'       => (string)$reg['title'],
                'description' => isset($reg['description']) ? (string)$reg['description'] : null,
                'icon'        => isset($reg['icon']) ? (string)$reg['icon'] : null,
                'iframe_url'  => (string)$reg['iframe_url'],
                'enabled'     => $isEnabled,
                'sort_order'  => $isEnabled ? $enabled[$regId] : 0,
            ];
        }

        return $out;
    }

    /**
     * Check whether a specific widget is enabled for a team.
     */
    public function isEnabled(int $registryId, string $teamId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('teamhub_team_widgets')
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
     * Enable a widget for a team (insert if not already present).
     * Returns the full joined row.
     */
    public function enable(int $registryId, string $teamId): array {
        // Idempotent — silently skip if already enabled.
        if ($this->isEnabled($registryId, $teamId)) {
            $rows = $this->findEnabledForTeam($teamId);
            foreach ($rows as $r) {
                if ($r['registry_id'] === $registryId) {
                    return $r;
                }
            }
        }

        $sortOrder = $this->getNextSortOrder($teamId);

        $qb  = $this->db->getQueryBuilder();
        $now = time();
        $qb->insert('teamhub_team_widgets')
            ->values([
                'registry_id' => $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT),
                'team_id'     => $qb->createNamedParameter($teamId),
                'sort_order'  => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
                'enabled_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        // Return with merged registry data.
        $rows = $this->findEnabledForTeam($teamId);
        foreach ($rows as $r) {
            if ($r['registry_id'] === $registryId) {
                return $r;
            }
        }

        // Fallback (should never reach here).
        return ['registry_id' => $registryId, 'team_id' => $teamId, 'sort_order' => $sortOrder];
    }

    /**
     * Disable (remove) a widget from a team.
     */
    public function disable(int $registryId, string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_team_widgets')
            ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }

    /**
     * Bulk-update sort_order for a team's widgets.
     * $orderedRegistryIds is an array of registry IDs in the desired display order.
     */
    public function reorder(string $teamId, array $orderedRegistryIds): void {
        foreach ($orderedRegistryIds as $position => $registryId) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('teamhub_team_widgets')
                ->set('sort_order', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter((int)$registryId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
            $qb->executeStatement();
        }
    }

    /**
     * Remove all team_widget rows that reference a given registry entry.
     * Called when an app deregisters its widget (cascade cleanup).
     */
    public function deleteByRegistryId(int $registryId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_team_widgets')
            ->where($qb->expr()->eq('registry_id', $qb->createNamedParameter($registryId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function getNextSortOrder(string $teamId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('sort_order'))
            ->from('teamhub_team_widgets')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $max    = $result->fetchOne();
        $result->closeCursor();

        return $max !== false && $max !== null ? (int)$max + 1 : 0;
    }

    private function hydrate(array $row): array {
        return [
            'id'          => (int)$row['id'],
            'registry_id' => (int)$row['registry_id'],
            'team_id'     => (string)$row['team_id'],
            'sort_order'  => (int)$row['sort_order'],
            'enabled_at'  => (int)$row['enabled_at'],
            'app_id'      => (string)$row['app_id'],
            'title'       => (string)$row['title'],
            'description' => isset($row['description']) ? (string)$row['description'] : null,
            'icon'        => isset($row['icon']) ? (string)$row['icon'] : null,
            'iframe_url'  => (string)$row['iframe_url'],
        ];
    }
}
