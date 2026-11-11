<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Data access for `teamhub_integ_tmpl` — which team templates include which
 * integration packages (v4.13.23).
 *
 * ## Presence of the row is the whole decision
 *
 * A template either includes a package or it does not. There is no policy
 * column: `required`, `default_on`, `default_off` and `prohibited` are
 * deliberately not part of this model. Every capability of an included
 * package is active for teams of that template, all visual ones shown by
 * default, and a team administrator may hide individual ones — which is a
 * layout preference and lives in `teamhub_integ_vis`, not here.
 *
 * ## Inclusion is read live, never copied
 *
 * This differs from `teamhub_template.apps`/`.modules`, which `ModulesStep`
 * copies into per-team state at creation and `PolicyPropagationService` later
 * re-pushes. Package inclusion is evaluated from this table on every check,
 * so a team cannot drift from its template and reconciliation never has to
 * repair state — it only has to fire lifecycle hooks and report. That is the
 * objective's "template inclusion remains authoritative after team creation",
 * and it is why removing a package from a template takes effect for every
 * team of that template at once.
 *
 * ## An inclusion survives everything
 *
 * A row here is not deleted when the owning app is disabled, when the
 * administrator disables the integration, or when the package stops
 * registering. Those make the package ineffective; the template's decision
 * stands and takes effect again the moment the package is available and
 * enabled. Only an administrator removing it from the template deletes a row.
 */
class IntegrationTemplateMapper {

    private const TABLE = 'teamhub_integ_tmpl';

    public function __construct(
        private IDBConnection $db,
    ) {}

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Package ids one template includes.
     *
     * @return list<int>
     */
    public function packageIdsForTemplate(string $templateKey): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pkg_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)));

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (int)$row['pkg_id'];
        }
        $result->closeCursor();

        return array_values(array_unique($out));
    }

    /**
     * Template keys that include one package.
     *
     * The population of the impact preview an administrator sees before
     * disabling an integration.
     *
     * @return list<string>
     */
    public function templateKeysForPackage(int $pkgId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('template_key')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)))
            ->orderBy('template_key', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = (string)$row['template_key'];
        }
        $result->closeCursor();

        return array_values(array_unique($out));
    }

    public function isIncluded(string $templateKey, int $pkgId): bool {
        return $this->find($templateKey, $pkgId) !== null;
    }

    /** @return array<string,mixed>|null */
    public function find(string $templateKey, int $pkgId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)))
            ->andWhere($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every inclusion on the instance.
     *
     * @return list<array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('template_key', 'ASC')
            ->addOrderBy('pkg_id', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Inclusions grouped by template, for the template editor.
     *
     * @return array<string,list<int>>
     */
    public function allByTemplate(): array {
        $out = [];
        foreach ($this->findAll() as $row) {
            $out[(string)$row['template_key']][] = (int)$row['pkg_id'];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /** Include a package in a template. Idempotent. */
    public function includePackage(string $templateKey, int $pkgId, string $actor, int $now): void {
        if ($this->isIncluded($templateKey, $pkgId)) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'template_key' => $qb->createNamedParameter($templateKey),
                'pkg_id'       => $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT),
                'included_by'  => $qb->createNamedParameter($actor),
                'included_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
    }

    /**
     * Remove a package from a template.
     *
     * The only operation that deletes a row here, and it deletes nothing
     * else: per-team shown/hidden preferences and ordering for the package's
     * capabilities stay exactly as they were, so re-including it restores
     * every team's layout rather than resetting it.
     */
    public function removePackage(string $templateKey, int $pkgId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)))
            ->andWhere($qb->expr()->eq('pkg_id', $qb->createNamedParameter($pkgId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * v4.13.24 — drop every inclusion for a template that is being deleted.
     *
     * Called only from `PolicyService::deleteTemplate()`. This is the one
     * place the "nothing is deleted when an integration is switched off"
     * promise does not apply, and the distinction is the template key itself:
     * removing a package from a template keeps the per-team preferences
     * because the template is still there to re-include it into, whereas a
     * deleted template's key names nothing. An inclusion row left behind
     * would be unreachable until somebody created a template with the same
     * key, and would then switch integrations on for teams nobody asked.
     *
     * Per-team shown/hidden preferences and ordering are still untouched:
     * those hang off the capability and the team, not the template.
     *
     * @return int rows removed, for the audit entry
     */
    public function removeTemplate(string $templateKey): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)));

        return $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        return [
            'id'           => (int)$row['id'],
            'template_key' => (string)$row['template_key'],
            'pkg_id'       => (int)$row['pkg_id'],
            'included_by'  => (string)($row['included_by'] ?? ''),
            'included_at'  => (int)($row['included_at'] ?? 0),
        ];
    }
}
