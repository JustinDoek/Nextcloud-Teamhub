<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.38 — the paperclip (`docs/service-builder.md` § 7, phase 8c).
 *
 * A file somebody attaches to what they write on a request is **shared**
 * with the other side for a limited time, never copied. The document row
 * that already records a file on a request (`teamhub_wf_attachment`, phase
 * 6) now also remembers the share TeamHub made for it:
 *
 *   share_id     - Nextcloud's full share id (`ocCircleShare:12`,
 *                  `ocinternal:34`); '' for a document attached without a
 *                  share, which is every row before this version.
 *   share_until  - when the share expires (epoch seconds); NULL without one.
 *   unshared_at  - when TeamHub removed the share: at the end of the request,
 *                  or by the daily job once it expired. NULL while it lasts.
 *
 * The index serves the daily job's one question — "which shares are past
 * their date and not yet removed" — named explicitly (`/migrations` rule 1).
 * All three are new columns on an existing table, so nullable (rule 1b); the
 * entity reads NULL as '' / null. No application constant is referenced here
 * (rule 2).
 */
class Version000410038Date20260925140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_wf_attachment')) {
            return null;
        }
        $t       = $schema->getTable('teamhub_wf_attachment');
        $changed = false;
        if (!$t->hasColumn('share_id')) {
            $t->addColumn('share_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
            $changed = true;
        }
        if (!$t->hasColumn('share_until')) {
            $t->addColumn('share_until', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $changed = true;
        }
        if (!$t->hasColumn('unshared_at')) {
            $t->addColumn('unshared_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
            $changed = true;
        }
        if (!$t->hasIndex('th_wfat_share_idx')) {
            $t->addIndex(['unshared_at', 'share_until'], 'th_wfat_share_idx');
            $changed = true;
        }
        return $changed ? $schema : null;
    }
}
