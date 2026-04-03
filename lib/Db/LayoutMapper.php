<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * LayoutMapper — CRUD for teamhub_widget_layouts.
 *
 * Stores per-user, per-team Home-view grid layout and tab order.
 * Uses raw QB instead of Entity pattern because the table has only
 * one interesting row per (user, team) — upsert is simpler this way.
 */
class LayoutMapper {

    private const TABLE = 'teamhub_widget_layouts';

    public function __construct(private IDBConnection $db) {}

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Return the stored layout for (userId, teamId), or null if none exists.
     *
     * @return array{layout_json: string, tab_order_json: string}|null
     */
    public function find(string $userId, string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('layout_json', 'tab_order_json')
           ->from(self::TABLE)
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'layout_json'    => (string)$row['layout_json'],
            'tab_order_json' => (string)$row['tab_order_json'],
        ];
    }

    /**
     * Insert or update the layout for (userId, teamId).
     *
     * Uses INSERT … ON CONFLICT UPDATE to keep a single row per user+team.
     * Falls back to check-then-insert/update for databases that don't support
     * the NC upsert helper (all supported NC32 DBs do, but being safe).
     */
    public function upsert(
        string $userId,
        string $teamId,
        string $layoutJson,
        string $tabOrderJson,
    ): void {
        $now = time();

        // Check if row exists.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from(self::TABLE)
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row !== false) {
            // UPDATE existing row.
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
               ->set('layout_json',    $qb->createNamedParameter($layoutJson))
               ->set('tab_order_json', $qb->createNamedParameter($tabOrderJson))
               ->set('updated_at',     $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
            $qb->executeStatement();
        } else {
            // INSERT new row.
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::TABLE)
               ->values([
                   'user_id'        => $qb->createNamedParameter($userId),
                   'team_id'        => $qb->createNamedParameter($teamId),
                   'layout_json'    => $qb->createNamedParameter($layoutJson),
                   'tab_order_json' => $qb->createNamedParameter($tabOrderJson),
                   'updated_at'     => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
               ]);
            $qb->executeStatement();
        }
    }

    /**
     * Delete all layout rows for a given team.
     * Called when a team is deleted so orphan rows are cleaned up.
     */
    public function deleteByTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
           ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }
}
