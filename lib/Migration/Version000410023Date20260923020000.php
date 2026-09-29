<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.23 — a service team's people are the team's people.
 *
 * Phase 5 gave a service team a roster of its own: one named owner, a table
 * of named agents, and a rule for what happened to a new request. All three
 * are gone, because a service team is now a team created from the *Service*
 * template and its roles are the team's own — the admins are answerable for
 * the service, the members and moderators work the queue, and an optional
 * Nextcloud group joins them (DESIGN §2.146). A second roster beside the
 * membership could disagree with it, and the one that would have been wrong
 * is the one nobody maintains.
 *
 * Four drops, each guarded so the step is safe to re-run:
 *
 *   - `teamhub_service_agent` — the roster table.
 *   - `teamhub_service_team.owner_uid` — with its index, which has to go
 *     first: a column under an index cannot be dropped while the index
 *     names it.
 *   - `teamhub_service_team.assign_mode` — a new request now always waits in
 *     the queue. "Assign to the service owner" had no target left once the
 *     owner became a role rather than a person.
 *   - `teamhub_service_team.source_group` — the desk's extra Nextcloud
 *     group. A group that should work the queue is added to the *team*, on
 *     the Members tab, where Circles already resolves it as membership; a
 *     second group named only here would have been a second way to say the
 *     same thing, and the two could disagree.
 *
 * Nothing is seeded here. The `service` template row is
 * `SeedTemplatesAndProfiles`' — a fresh install never runs a post step
 * (`/migrations` §2a), and that repair step is registered under both
 * `<install>` and `<post-migration>`.
 *
 * `teamhub_service_catalog` is deliberately untouched: which services a desk
 * holds is still a row per service, which is what lets one checkbox claim
 * all six today and a service team add its own workflows later.
 */
class Version000410023Date20260923020000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('teamhub_service_agent')) {
            $schema->dropTable('teamhub_service_agent');
            $output->info('Dropped teamhub_service_agent — a service team\'s agents are its members');
            $changed = true;
        }

        if ($schema->hasTable('teamhub_service_team')) {
            $t = $schema->getTable('teamhub_service_team');

            if ($t->hasIndex('th_svt_owner_idx')) {
                $t->dropIndex('th_svt_owner_idx');
                $changed = true;
            }
            if ($t->hasColumn('owner_uid')) {
                $t->dropColumn('owner_uid');
                $output->info('Dropped teamhub_service_team.owner_uid — the team admins own the service');
                $changed = true;
            }
            if ($t->hasColumn('assign_mode')) {
                $t->dropColumn('assign_mode');
                $output->info('Dropped teamhub_service_team.assign_mode — a new request waits in the queue');
                $changed = true;
            }
            if ($t->hasColumn('source_group')) {
                $t->dropColumn('source_group');
                $output->info('Dropped teamhub_service_team.source_group — add the group to the team instead');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
