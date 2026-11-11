<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * LayoutDefaultMapper — CRUD for teamhub_layout_default (v4.13.34).
 *
 * The default home layout at three widths: the instance, a kind of team, one
 * team. See Version000413034 for why they share a table.
 *
 * Raw QueryBuilder rather than the Entity pattern, like LayoutMapper beside it
 * and for the same reason: one row per scope, read by a single composite key,
 * and an upsert is simpler this way.
 */
class LayoutDefaultMapper {

    private const TABLE = 'teamhub_layout_default';

    public const SCOPE_INSTANCE = 'instance';
    public const SCOPE_TEMPLATE = 'template';
    public const SCOPE_TEAM     = 'team';

    /**
     * What a team's own default does to the members of that team.
     *
     * MODE_SUGGEST    — offered, nothing changed. The member is told a team
     *                   layout exists and may take it.
     * MODE_APPLY_ONCE — what a member sees until they arrange their own home.
     *                   Sits above their personal default, below their own
     *                   arrangement.
     * MODE_ENFORCE    — what every member sees, full stop. Above a member's own
     *                   arrangement, and the home cannot be edited while it is
     *                   in force.
     */
    public const MODE_SUGGEST    = 'suggest';
    public const MODE_APPLY_ONCE = 'apply_once';
    public const MODE_ENFORCE    = 'enforce';

    public function __construct(private IDBConnection $db) {}

    /**
     * The row for one scope, or null.
     *
     * @return array{layout_json: string, tab_order_json: string, mode: string, updated_by: ?string, updated_at: ?int}|null
     */
    public function find(string $scope, string $scopeId = ''): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('layout_json', 'tab_order_json', 'mode', 'updated_by', 'updated_at')
           ->from(self::TABLE)
           ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
           ->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'layout_json'    => (string)$row['layout_json'],
            'tab_order_json' => (string)$row['tab_order_json'],
            'mode'           => (string)$row['mode'],
            'updated_by'     => $row['updated_by'] === null ? null : (string)$row['updated_by'],
            'updated_at'     => $row['updated_at'] === null ? null : (int)$row['updated_at'],
        ];
    }

    /** Insert or replace the row for one scope. */
    public function upsert(
        string $scope,
        string $scopeId,
        string $layoutJson,
        string $tabOrderJson,
        string $mode,
        string $actor,
    ): void {
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from(self::TABLE)
           ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
           ->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row !== false) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
               ->set('layout_json',    $qb->createNamedParameter($layoutJson))
               ->set('tab_order_json', $qb->createNamedParameter($tabOrderJson))
               ->set('mode',           $qb->createNamedParameter($mode))
               ->set('updated_by',     $qb->createNamedParameter($actor))
               ->set('updated_at',     $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
               ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
               ->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)));
            $qb->executeStatement();
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
           ->values([
               'scope'          => $qb->createNamedParameter($scope),
               'scope_id'       => $qb->createNamedParameter($scopeId),
               'layout_json'    => $qb->createNamedParameter($layoutJson),
               'tab_order_json' => $qb->createNamedParameter($tabOrderJson),
               'mode'           => $qb->createNamedParameter($mode),
               'updated_by'     => $qb->createNamedParameter($actor),
               'updated_at'     => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
           ]);
        $qb->executeStatement();
    }

    /**
     * Change only the mode of a team row. Returns false when there is no row
     * to change — a mode with no layout behind it means nothing, and inserting
     * one would create an enforced default of no widgets at all.
     */
    public function setMode(string $scopeId, string $mode): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
           ->set('mode', $qb->createNamedParameter($mode))
           ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
           ->where($qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_TEAM)))
           ->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)));

        return $qb->executeStatement() > 0;
    }

    /** Remove one scope's default. */
    public function delete(string $scope, string $scopeId = ''): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
           ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
           ->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)));
        $qb->executeStatement();
    }
}
