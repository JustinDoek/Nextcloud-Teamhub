<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.8.3 — Track F2a follow-up: expiry moves to templates, modes go away.
 *
 * A second migration rather than an edit to `Version000408002`, because that
 * one has already run: the instance reports `installed_version = 4.8.2` and
 * the tables exist with their seed. Amending an applied migration changes
 * nothing on an instance that has it recorded in `oc_migrations`.
 *
 * Two changes, both from Justin's review of the Policy screen on 2026-09-01:
 *
 * **1. `teamhub_policy_value.mode` is dropped.** The per-field
 * default-vs-locked distinction is gone from the product: a setting is either
 * governed by the profile or it is not, and a governed setting is locked. The
 * column had exactly two values and the UI control that set it was the thing
 * being objected to, so keeping the column "just in case" would leave a stored
 * distinction nothing can express.
 *
 * Note what this costs, since it is not free: `TRACK-F2-DESIGN.md` §4d had
 * per-field mode as the mechanism that let one design serve a governed posture
 * and a mid-market one — locking a couple of fields and merely suggesting the
 * rest. That middle setting is now expressed by governing fewer fields rather
 * than by governing many fields loosely.
 *
 * **2. `teamhub_template.expiry_default_days` is added.** Expiry stops being a
 * profile field and becomes a template one: a template says whether teams of
 * its kind can expire (`offer_expiry`, already present) and how long the
 * default period is. `expiry_policy` and `expiry_max_days` leave
 * `PolicyField`, and any rows an admin created for them in the few minutes
 * 4.8.2 was live are deleted below.
 *
 * **`offer_expiry` is deliberately NOT renamed** to match its new label
 * ("Enable team expiration"). Renaming a column on a deployed table buys
 * nothing a user can see and costs a copy-and-drop that can half-apply.
 */
class Version000408003Date20260901010000 extends SimpleMigrationStep {

    /** Profile fields that no longer exist. */
    private const RETIRED_FIELDS = ['expiry_policy', 'expiry_max_days'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('teamhub_template')) {
            $t = $schema->getTable('teamhub_template');
            if (!$t->hasColumn('expiry_default_days')) {
                // Whole days. 0 means "no default" — the wizard then falls back
                // to its own six-month picker default, which is what every
                // template does today, so adding the column changes nothing
                // until an admin sets a value.
                $t->addColumn('expiry_default_days', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 0,
                ]);
                $changed = true;
            }
        }

        if ($schema->hasTable('teamhub_policy_value')) {
            $v = $schema->getTable('teamhub_policy_value');
            if ($v->hasColumn('mode')) {
                $v->dropColumn('mode');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }

    /**
     * Drop values for the two retired fields.
     *
     * Defensive rather than expected: the 4.8.2 seed never wrote an expiry
     * field, so this deletes nothing on an instance where no admin built a
     * profile of their own in the window between the two versions. Leaving
     * them would be worse than a no-op delete — `PolicyField::isValid()` no
     * longer knows those keys, so `getProfile()` would hand the panel a field
     * it cannot render or cast.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('teamhub_policy_value')
            ->where($qb->expr()->in(
                'field_key',
                $qb->createNamedParameter(self::RETIRED_FIELDS, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
            ));
        $removed = $qb->executeStatement();

        if ($removed > 0) {
            $output->info('Version000408003: removed ' . $removed . ' retired expiry policy value(s)');
        }
    }
}
