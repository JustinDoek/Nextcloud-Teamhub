<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.9.3 — OpenProject Phase 1: the team ↔ OpenProject project link.
 *
 * One row per team. The relationship is the numeric `project_id` — the one
 * identifier OpenProject never changes. `project_identifier` (the URL slug)
 * and `project_name` are display and deep-link snapshots: a rename in
 * OpenProject does not orphan the row, and the next successful read refreshes
 * both. Neither is ever used to find anything.
 *
 * ## Why `host` is stored
 *
 * The official integration app configures exactly one OpenProject instance,
 * and TeamHub never accepts a host from a team admin — that is the SSRF
 * boundary. `host` records which instance the link was made against so that
 * an administrator repointing the integration at another server is detected
 * (the overview reports the link as stale rather than showing another
 * instance's project with the same numeric id), and so a later phase can
 * carry more than one instance without a schema change.
 *
 * ## Why no boolean columns
 *
 * `Types::BOOLEAN` with `notnull => true` fails on MySQL at insert and on
 * Postgres at bind (HANDOFF, "Facts that are not derivable from this repo").
 * `last_validated_at` is a nullable timestamp for the same reason
 * `teamhub_file_review.completed_at` is: null means "never checked", a value
 * means "checked, at this moment".
 *
 * Identifier lengths: the table name is 24 characters, inside the guidance,
 * and every key is still named explicitly (`/migrations` §1).
 *
 * This file is self-contained — no `OCA\TeamHub\` constant or class is
 * referenced (`/migrations` §2, issue #98).
 */
class Version000409003Date20260911000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_openproject_link')) {
            return null;
        }

        $table = $schema->createTable('teamhub_openproject_link');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        // OpenProject's numeric project id. The identity of the link.
        $table->addColumn('project_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        // URL slug at link time — deep links only, refreshed on every read.
        $table->addColumn('project_identifier', Types::STRING, ['notnull' => true, 'length' => 255]);
        // Display snapshot, refreshed on every read. Never authoritative.
        $table->addColumn('project_name', Types::STRING, ['notnull' => true, 'length' => 255]);
        // The OpenProject instance URL the link was made against.
        $table->addColumn('host', Types::STRING, ['notnull' => true, 'length' => 1024]);
        $table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
        $table->addColumn('last_validated_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);

        $table->setPrimaryKey(['id'], 'th_opl_pk');
        // One link per team — the uniqueness rule and the lookup index in one.
        $table->addUniqueIndex(['team_id'], 'th_opl_team_uq');
        // "Which teams point at this project" — the duplicate-link warning at
        // link time, and the estate-wide view a later phase needs.
        $table->addIndex(['project_id'], 'th_opl_proj_idx');

        $output->info('Created teamhub_openproject_link table');

        return $schema;
    }
}
