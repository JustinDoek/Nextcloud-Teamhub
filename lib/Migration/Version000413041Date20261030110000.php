<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.41 — the user-facing Presence module becomes People and defaults to
 * enabled for newly created teams.
 *
 * Previous releases treated a missing team-config row as OFF. Before the new
 * default becomes active, persist those old implicit OFF values so upgrading
 * never unexpectedly starts sharing presence schedules in an existing team.
 * All names are historical schema names: do NOT rename the tables/keys or old
 * third-party code and existing installations would lose their preferences.
 *
 * No schema change; this step is intentionally an upgrade-time data repair.
 */
class Version000413041Date20261030110000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_team_registry') || !$schema->hasTable('teamhub_presence_team')) {
            return;
        }
        $existing = [];
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')->from('teamhub_presence_team')->executeQuery();
        while ($row = $res->fetch()) {
            $existing[(string)$row['team_id']] = true;
        }
        $res->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')->from('teamhub_team_registry')->executeQuery();
        $teamIds = [];
        while ($row = $res->fetch()) {
            $teamIds[] = (string)$row['team_id'];
        }
        $res->closeCursor();
        $now = time();
        $count = 0;
        foreach ($teamIds as $teamId) {
            if ($teamId === '' || isset($existing[$teamId])) {
                continue;
            }
            $insert = $this->db->getQueryBuilder();
            $insert->insert('teamhub_presence_team')->values([
                'team_id' => $insert->createNamedParameter($teamId),
                'presence_enabled' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'hide_reasons' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $count++;
        }
        $output->info("People: preserved the previous disabled setting on {$count} existing team(s).");
    }
}
