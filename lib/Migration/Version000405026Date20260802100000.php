<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.5.26 — add `teamhub_messages.is_system`.
 *
 * The redesigned "What's new" feed control offers a **System messages** toggle.
 * Nothing in the schema could answer it: `MilestoneAutoPostService` writes its
 * milestone announcements through `MessageMapper::create` with the milestone's
 * `created_by` as the author (DESIGN §2.47 Decision 5 — a real user on the
 * stream rather than a synthetic "TeamHub" account), so a system post is
 * byte-for-byte a normal post. A toggle with nothing behind it is worse than no
 * toggle, hence the column.
 *
 * **Default 0, and no backfill.** Every existing row is treated as
 * user-authored. Backfilling would mean pattern-matching old subjects against a
 * translated string — seven locales, and wrong the moment a member writes the
 * same sentence themselves. Milestone posts written before this upgrade stay
 * visible under the default (toggle on); only new ones can be filtered out.
 *
 * Postgres compatibility: SMALLINT with `notnull => true, default => 0`, the
 * same shape `pinned` / `is_public` / `poll_closed` already use on this table —
 * booleans are SMALLINT here because PARAM_BOOL cannot coerce 't'/'f' onto them
 * (DESIGN §2.4). No index: the column is only ever an extra AND on a WHERE that
 * is already narrowed by `team_id` / `is_public`, never filtered on alone. No
 * new constraint or index name, so nothing here approaches NC's 30-character
 * DBAL cap (SKILLS.md § Database identifier length).
 */
class Version000405026Date20260802100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_messages')) {
            return null;
        }

        $table = $schema->getTable('teamhub_messages');
        if ($table->hasColumn('is_system')) {
            return null;
        }

        $table->addColumn('is_system', Types::SMALLINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $output->info('Added is_system column to teamhub_messages');

        return $schema;
    }
}
