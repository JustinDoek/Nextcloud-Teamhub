<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Raw QueryBuilder mapper for teamhub_template (v4.8.2, Track F2a).
 *
 * The live, admin-adjustable template set. Seeded from
 * {@see \OCA\TeamHub\Constants\TeamTemplates} by Version000408002.
 *
 * **In F2a this table is written and read by the Policy admin tab only.** The
 * wizard, the importer and the exporter still read the constants class; F2b
 * moves them, and until then editing a template here changes what the admin
 * screen shows and not yet what a new team gets. `PolicyService::listTemplates`
 * flags that divergence so the UI can say so rather than quietly lying.
 *
 * Raw rather than QBMapper: `template_key` is the primary key, no surrogate id.
 */
class TeamTemplateMapper {

    private const TABLE = 'teamhub_template';

    public function __construct(private IDBConnection $db) {}

    /**
     * Every template, in wizard-card order.
     *
     * @return list<array<string,mixed>>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('sort_index', 'ASC')
            ->addOrderBy('template_key', 'ASC');

        $result = $qb->executeQuery();
        $out    = [];
        while ($row = $result->fetch()) {
            $out[] = $this->hydrate($row);
        }
        $result->closeCursor();

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $templateKey): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Update the editable fields of a template.
     *
     * `template_key` is never updated: `teamhub_team_type.type` holds it for
     * every team ever created, and there is no migration behind a rename.
     *
     * `preselect_config` is editable but is **not policy** — see
     * `TeamTemplates::configBitmask()`. The caller masks it to
     * `CirclesConfig::MANAGED_BITS` before it arrives here.
     *
     * @param list<string> $apps
     * @param list<string> $modules
     */
    public function update(
        string  $templateKey,
        string  $label,
        ?string $description,
        array   $apps,
        array   $modules,
        bool    $expiryEnabled,
        int     $expiryDefaultDays,
        int     $preselectConfig,
        int     $sortIndex,
        ?string $defaultProfileKey,
        string  $actor,
        int     $now,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('label', $qb->createNamedParameter($label))
            ->set('description', $qb->createNamedParameter($description))
            ->set('apps', $qb->createNamedParameter(implode(';', $apps)))
            ->set('modules', $qb->createNamedParameter(implode(';', $modules)))
            // Column still named `offer_expiry`; the label it carries is now
            // "Enable team expiration". Not renamed on a deployed table for a
            // wording change — Version000408003 has the reasoning.
            // SMALLINT bound as PARAM_INT, never PARAM_BOOL — DESIGN §2.4.
            ->set('offer_expiry', $qb->createNamedParameter($expiryEnabled ? 1 : 0, IQueryBuilder::PARAM_INT))
            ->set('expiry_default_days', $qb->createNamedParameter($expiryDefaultDays, IQueryBuilder::PARAM_INT))
            ->set('preselect_config', $qb->createNamedParameter($preselectConfig, IQueryBuilder::PARAM_INT))
            ->set('sort_index', $qb->createNamedParameter($sortIndex, IQueryBuilder::PARAM_INT))
            // v4.8.5 — the policy new teams of this kind start from. Null is a
            // real state: no default, nothing preselected in the wizard.
            ->set('default_profile_key', $qb->createNamedParameter($defaultProfileKey))
            ->set('updated_by', $qb->createNamedParameter($actor))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)));
        $qb->executeStatement();
    }

    /**
     * v4.9.6 — write the template's blueprint (Phase 2). Its own method
     * rather than a parameter of `update()`: the blueprint is edited on its
     * own admin surface, and the general edit must not clear it.
     *
     * @param array<string,mixed>|null $blueprint null clears it — the template
     *        is then read exactly as a pre-4.9.6 row (derived blueprint)
     */
    public function updateBlueprint(string $templateKey, ?array $blueprint, string $actor, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('blueprint_json', $qb->createNamedParameter(
                $blueprint === null ? null : json_encode($blueprint, JSON_UNESCAPED_UNICODE),
            ))
            ->set('updated_by', $qb->createNamedParameter($actor))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter($templateKey)));
        $qb->executeStatement();
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array {
        $apps    = (string)($row['apps'] ?? '');
        $modules = (string)($row['modules'] ?? '');
        // v4.9.6 — the declarative blueprint, or null for a template that has
        // none (every pre-Phase-2 row). The column may be absent on an
        // instance that has not run Version000409006 yet; the isset guards
        // that too.
        $blueprint = null;
        if (isset($row['blueprint_json']) && (string)$row['blueprint_json'] !== '') {
            $decoded   = json_decode((string)$row['blueprint_json'], true);
            $blueprint = is_array($decoded) ? $decoded : null;
        }

        return [
            'templateKey'       => (string)$row['template_key'],
            'label'             => (string)$row['label'],
            'description'       => $row['description'] !== null ? (string)$row['description'] : null,
            'apps'              => $apps === '' ? [] : explode(';', $apps),
            'modules'           => $modules === '' ? [] : explode(';', $modules),
            'expiryEnabled'     => (int)$row['offer_expiry'] === 1,
            // 0 means "no default" — the wizard falls back to its own picker
            // default rather than opening on today.
            'expiryDefaultDays' => (int)($row['expiry_default_days'] ?? 0),
            'preselectConfig'   => (int)$row['preselect_config'],
            'sortIndex'         => (int)$row['sort_index'],
            'defaultProfileKey' => ($row['default_profile_key'] ?? '') !== ''
                ? (string)$row['default_profile_key']
                : null,
            'isSeeded'          => (int)$row['is_seeded'] === 1,
            'updatedBy'         => $row['updated_by'] !== null ? (string)$row['updated_by'] : null,
            'updatedAt'         => $row['updated_at'] !== null ? (int)$row['updated_at'] : null,
            'blueprint'         => $blueprint,
        ];
    }
}
