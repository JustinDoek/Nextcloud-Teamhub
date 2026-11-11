<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data access for `teamhub_integ_vis` — one team's presentation of one
 * capability (v4.13.23).
 *
 * ## A missing row means shown
 *
 * The rule the whole default-presentation behaviour rests on. A team that has
 * never been touched has no rows, so every capability of every included
 * package is shown, at the capability's own `default_order`. And a *newly
 * registered* widget or tab appears by itself for every team whose template
 * already includes the package — no backfill, no write, nothing for an
 * administrator to do. An explicit row with `hidden = 1` is the only thing
 * that hides anything.
 *
 * ## Hidden and ordered are independent
 *
 * `hidden` and `sort_order` live in one row because they are the same
 * `(team, capability)` grain and every render needs both — but they are never
 * written together. {@see setHidden()} touches `hidden`; {@see setOrder()}
 * touches `sort_order`. So hiding a capability keeps the position it returns
 * to when it is shown again, and reordering a hidden capability does not
 * quietly unhide it.
 *
 * `sort_order = -1` is "no preference": use the capability's `default_order`.
 * A hide must not assign a position as a side effect, which is what a 0
 * default would have done.
 *
 * ## Visual capabilities only
 *
 * Nothing writes a row for a `my_work_provider` capability. The service layer
 * refuses it rather than storing a preference that would have no meaning —
 * see {@see \OCA\TeamHub\Service\IntegrationPresentationService}. That is how
 * "no visual preference can disable My Work" is enforced at the bottom of the
 * stack as well as the top.
 */
class IntegrationVisibilityMapper {

    private const TABLE = 'teamhub_integ_vis';

    /** `sort_order` meaning "use the capability's default_order". */
    public const NO_ORDER = -1;

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Every preference one team has expressed, keyed by capability id.
     *
     * One query per workspace render. Capabilities with no row are simply
     * absent from the result, which the caller reads as shown at default
     * order.
     *
     * @return array<int,array{hidden: bool, sort_order: int}>
     */
    public function findForTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('cap_id', 'hidden', 'sort_order')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[(int)$row['cap_id']] = [
                'hidden'     => (int)$row['hidden'] === 1,
                'sort_order' => (int)$row['sort_order'],
            ];
        }
        $result->closeCursor();

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $teamId, int $capId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('cap_id', $qb->createNamedParameter($capId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /** Is this capability hidden for this team? Absent row = shown. */
    public function isHidden(string $teamId, int $capId): bool {
        $row = $this->find($teamId, $capId);

        return $row !== null && $row['hidden'];
    }

    /**
     * Teams that have hidden one capability.
     *
     * Used by the impact preview: "this many teams have already hidden it",
     * which tells an administrator whether re-including a package will put
     * something back on screen or not.
     *
     * @return list<string>
     */
    public function teamsHiding(int $capId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('cap_id', $qb->createNamedParameter($capId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('hidden', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (string)$row['team_id'];
        }
        $result->closeCursor();

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * Show or hide one capability for one team.
     *
     * Writes `hidden` and nothing else. An insert gets
     * {@see NO_ORDER} so hiding never assigns a position.
     */
    public function setHidden(string $teamId, int $capId, bool $hidden, string $actor, int $now): void {
        $existing = $this->find($teamId, $capId);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('hidden', $qb->createNamedParameter($hidden ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('updated_by', $qb->createNamedParameter($actor))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            return;
        }

        $this->insert($teamId, $capId, $hidden, self::NO_ORDER, $actor, $now);
    }

    /**
     * Position one capability within its type for one team.
     *
     * Writes `sort_order` and nothing else — reordering a hidden capability
     * leaves it hidden, which is what lets a team administrator arrange the
     * list before revealing anything.
     */
    public function setOrder(string $teamId, int $capId, int $sortOrder, string $actor, int $now): void {
        $existing = $this->find($teamId, $capId);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('sort_order', $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT))
                ->set('updated_by', $qb->createNamedParameter($actor))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            return;
        }

        $this->insert($teamId, $capId, false, $sortOrder, $actor, $now);
    }

    /**
     * Both columns at once, for the migration backfill.
     *
     * Not exposed to the presentation API: a team administrator's hide and a
     * team administrator's reorder are two separate decisions and the API
     * keeps them separate. The backfill is reconstructing both from one legacy
     * row, which is the one caller that genuinely knows both at once.
     */
    public function setBoth(string $teamId, int $capId, bool $hidden, int $sortOrder, string $actor, int $now): void {
        $existing = $this->find($teamId, $capId);

        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('hidden', $qb->createNamedParameter($hidden ? 1 : 0, IQueryBuilder::PARAM_INT))
                ->set('sort_order', $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT))
                ->set('updated_by', $qb->createNamedParameter($actor))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            return;
        }

        $this->insert($teamId, $capId, $hidden, $sortOrder, $actor, $now);
    }

    /**
     * Drop one team's preferences.
     *
     * Called when a team is deleted — the rows reference a team that no longer
     * exists. Never called for an integration being disabled, an app being
     * removed or a package leaving a template: those must all preserve the
     * preferences so the team's layout comes back intact.
     */
    public function deleteForTeam(string $teamId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
        $qb->executeStatement();
    }

    private function insert(string $teamId, int $capId, bool $hidden, int $sortOrder, string $actor, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'team_id'    => $qb->createNamedParameter($teamId),
                'cap_id'     => $qb->createNamedParameter($capId, IQueryBuilder::PARAM_INT),
                'hidden'     => $qb->createNamedParameter($hidden ? 1 : 0, IQueryBuilder::PARAM_INT),
                'sort_order' => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
                'updated_by' => $qb->createNamedParameter($actor),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        return [
            'id'         => (int)$row['id'],
            'team_id'    => (string)$row['team_id'],
            'cap_id'     => (int)$row['cap_id'],
            'hidden'     => (int)($row['hidden'] ?? 0) === 1,
            'sort_order' => (int)($row['sort_order'] ?? self::NO_ORDER),
            'updated_by' => (string)($row['updated_by'] ?? ''),
            'updated_at' => (int)($row['updated_at'] ?? 0),
        ];
    }
}
