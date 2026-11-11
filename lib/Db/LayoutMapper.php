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
     * Who arranged a stored layout (v4.13.33). See the `origin` column's
     * docblock in Version000413033 for why the distinction exists.
     *
     * ORIGIN_AUTO — TeamHub wrote this row by itself. It is a cache of a
     *               computed arrangement and may be recomputed or replaced.
     * ORIGIN_USER — a person arranged this team. A later type-default rollout
     *               may explicitly return it to the automatic cascade.
     * ORIGIN_TEMPLATE — user explicitly reset just this team to its template.
     *
     * A row with neither (NULL) predates the column and is classified on
     * first read by LayoutController.
     */
    public const ORIGIN_AUTO = 'auto';
    public const ORIGIN_USER = 'user';
    public const ORIGIN_TEMPLATE = 'tmpl'; // user explicitly reset this team to template

    /**
     * Return the stored layout for (userId, teamId), or null if none exists.
     *
     * `origin` is null for a row written before v4.13.33 — unclassified, not
     * a fourth state. The caller classifies it.
     *
     * `updated_at` is when the member last arranged this home, and v4.13.35
     * compares it against when a default was last set: a default applies until
     * the member arranges *after* it, and from then on theirs does. See
     * `LayoutController::resolveTeamLayout()`.
     *
     * @return array{layout_json: string, tab_order_json: string, origin: ?string, updated_at: int}|null
     */
    public function find(string $userId, string $teamId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('layout_json', 'tab_order_json', 'origin', 'updated_at')
           ->from(self::TABLE)
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        $origin = $row['origin'] ?? null;

        return [
            'layout_json'    => (string)$row['layout_json'],
            'tab_order_json' => (string)$row['tab_order_json'],
            'origin'         => $origin === null ? null : (string)$origin,
            // 0 for a row written before the column carried a time, which
            // reads as "arranged before any default was set" — the safe way
            // round, since the alternative would have a default silently lose
            // to a row nobody can date.
            'updated_at'     => (int)($row['updated_at'] ?? 0),
        ];
    }

    /**
     * Insert or update the layout for (userId, teamId).
     *
     * Uses INSERT … ON CONFLICT UPDATE to keep a single row per user+team.
     * Falls back to check-then-insert/update for databases that don't support
     * the NC upsert helper (all supported NC32 DBs do, but being safe).
     *
     * ## `origin` is promoted, never demoted (v4.13.33)
     *
     * A save that carries ORIGIN_USER marks the row as a person's arrangement
     * and it stays marked. A save that carries ORIGIN_AUTO — or no origin at
     * all — leaves an already-`user` row `user`. That asymmetry is what lets
     * one endpoint serve both a drag (which is a person arranging their home)
     * and a tab reorder (which says nothing about the grid): neither has to
     * know what the other decided, and no write can quietly take a member's
     * layout off a rollout's reach or put it back on.
     */
    public function upsert(
        string $userId,
        string $teamId,
        string $layoutJson,
        string $tabOrderJson,
        string $origin = self::ORIGIN_AUTO,
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
            // Promotion only — see the docblock. An `auto` save leaves a row
            // that is already `user` alone, which also means it leaves an
            // unclassified (NULL) row unclassified for the controller to
            // decide on its next read.
            if ($origin === self::ORIGIN_USER || $origin === self::ORIGIN_TEMPLATE) {
                $qb->set('origin', $qb->createNamedParameter($origin));
            }
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
                   'origin'         => $qb->createNamedParameter($origin),
               ]);
            $qb->executeStatement();
        }
    }

    /**
     * Save only a user's tab order. Critically, do not advance updated_at or
     * change the origin of a stored grid: moving a tab cannot be treated as a
     * new personal layout after a team admin publishes a default.
     */
    public function saveTabOrder(string $userId, string $teamId, string $tabOrderJson): void {
        $existing = $this->find($userId, $teamId);
        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('tab_order_json', $qb->createNamedParameter($tabOrderJson))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->executeStatement();
            return;
        }
        // A navigation-only row must never count as a user-arranged grid.
        // The cascade ignores ORIGIN_AUTO and still serves template/team
        // defaults; the row merely stores the user's preferred tab order.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'user_id'        => $qb->createNamedParameter($userId),
            'team_id'        => $qb->createNamedParameter($teamId),
            'layout_json'    => $qb->createNamedParameter('[]'),
            'tab_order_json' => $qb->createNamedParameter($tabOrderJson),
            'updated_at'     => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'origin'         => $qb->createNamedParameter(self::ORIGIN_AUTO),
        ])->executeStatement();
    }

    /**
     * Drop one member's arrangement of one team (v4.13.33).
     *
     * What "Reset to the default layout" does. A delete rather than a write of
     * the default's contents, because the two are not the same thing: a row
     * holding a copy of today's default is still a row, and it would keep
     * every later change to that default from ever reaching this member. No
     * row is how the cascade is re-entered.
     */
    public function deleteFor(string $userId, string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }

    /**
     * Only a member's existing saved layouts need invalidating when their
     * personal type default changes. Teams without a row already cascade.
     * Fetch this user's IDs first instead of reading every team's admin
     * preset to discover that the user has never visited it.
     *
     * @return list<string>
     */
    public function findTeamIdsForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $teamIds = [];
        while ($row = $result->fetch()) {
            $teamIds[] = (string)$row['team_id'];
        }
        $result->closeCursor();
        return $teamIds;
    }

    /**
     * Let a newly saved personal type default replace the user's previously
     * arranged grids in matching, *unlocked* teams. Preserve each row (and
     * its tab order); ORIGIN_AUTO makes the normal cascade supply the grid.
     * Team IDs are server-resolved and checked for enforcement by the caller.
     * Batches avoid database parameter limits on large installations.
     *
     * @param list<string> $teamIds
     */
    public function releasePersonalGrids(string $userId, array $teamIds): void {
        foreach (array_chunk(array_values(array_unique($teamIds)), 200) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('origin', $qb->createNamedParameter(self::ORIGIN_AUTO))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeStatement();
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
