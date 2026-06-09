<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.72.4 — Widen teamhub_dec_categories.icon from VARCHAR(8) to VARCHAR(64).
 *
 * The original 3.72.1 migration created the column as VARCHAR(8), assuming
 * we'd store emoji (1–2 Unicode code points). The product decision shifted
 * to MDI icon names in PascalCase (e.g. 'FileDocumentOutline', 'AccountGroup')
 * which can be up to ~30 chars. 64 gives comfortable headroom.
 *
 * Existing installations that already ran 3.72.1 have an 8-char column and
 * cannot store these names — they hit "Data too long for column 'icon'" on
 * insert. This migration widens the column for those installs.
 *
 * The base migration (3.72.1) was also updated in-place to use 64 directly,
 * so fresh installs get the right shape immediately and this ALTER is a
 * no-op there (changeColumn to the same length is idempotent on both
 * MySQL/MariaDB and PostgreSQL).
 */
class Version000372030Date20260608100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_dec_categories')) {
            $table = $schema->getTable('teamhub_dec_categories');
            if ($table->hasColumn('icon')) {
                $col = $table->getColumn('icon');
                // Only widen — never shrink.
                if ((int)$col->getLength() < 64) {
                    $col->setLength(64);
                }
            }
        }

        return $schema;
    }
}
