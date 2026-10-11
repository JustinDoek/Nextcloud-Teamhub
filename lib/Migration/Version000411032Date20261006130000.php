<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.11.32 — teams provisioned from an identity provider's groups
 * (DESIGN §2.15x).
 *
 * One row per Nextcloud group TeamHub has considered for a team. The row is
 * both the decision and the provenance, and it has to be explicit for a
 * reason that is easy to miss: the *current* group → team relationship is
 * already derivable from Circles' tables (a team holds the hidden
 * `group:{gid}` circle as a member, which is what GroupMirrorSyncService
 * reads), but it **disappears the moment the group does** — Circles' own
 * GroupDeleted listener removes the copy. Without a stored row there would be
 * nothing left to say "this team came from a directory group that has now
 * gone", which is precisely the moment a decision is needed.
 *
 *   group_id     the Nextcloud group id; unique — one decision per group
 *   team_id      the team provisioned for it; '' until one exists
 *   status       pending | created | declined | orphaned | failed
 *   backend      the group's backend when it was found (LDAP, Database, …)
 *   group_dn     the LDAP DN when it was found; '' for a group that has none
 *   template_key the template used ('' = the configured default)
 *   profile_key  the policy profile used ('' = the template's default)
 *   decided_by   uid; '' for an automatic creation
 *   decided_at   epoch seconds; NULL while pending
 *   note         why it was declined, or what failed, in the words the grid shows
 *   detected_at  epoch seconds
 *
 * `backend` and `group_dn` are recorded as found rather than looked up later
 * on purpose: once the group is gone, neither can be read again, and an
 * administrator deciding about an orphaned team wants to know where it came
 * from.
 *
 * Rule compliance (`/migrations`): a brand-new table, so `notnull` with a
 * `''` default is correct and rule 1b does not apply. The name is 17
 * characters (`teamhub_idp_group_pkey` = 22, under the 30 cap) and every key
 * is still named explicitly, per rule 1. No `OCA\TeamHub\` constant or class
 * is referenced (rule 2) — the status values above are the literals the code
 * writes. No seed data, so rule 2a does not apply.
 */
class Version000411032Date20261006130000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_idp_group')) {
            return null;
        }

        $t = $schema->createTable('teamhub_idp_group');
        $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        $t->addColumn('group_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $t->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
        $t->addColumn('backend', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('group_dn', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
        $t->addColumn('template_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('profile_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('decided_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('decided_at', Types::BIGINT, ['notnull' => false, 'length' => 8, 'default' => null]);
        $t->addColumn('note', Types::STRING, ['notnull' => true, 'length' => 1000, 'default' => '']);
        $t->addColumn('detected_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

        $t->setPrimaryKey(['id'], 'th_idpg_pk');
        $t->addUniqueIndex(['group_id'], 'th_idpg_group_uq');
        // The sweep asks "what is still pending"; the reconcile job walks the
        // created rows looking for a group that has gone.
        $t->addIndex(['status'], 'th_idpg_status_idx');
        // The orphan path and the grid both resolve a row from its team.
        $t->addIndex(['team_id'], 'th_idpg_team_idx');

        return $schema;
    }
}
