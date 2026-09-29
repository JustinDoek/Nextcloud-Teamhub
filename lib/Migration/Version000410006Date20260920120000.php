<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.10.6 — the team registry: which circles are TeamHub's.
 *
 * Until now every user-created circle (`circles_circle.source = 16`) a user
 * belonged to was a TeamHub team, whoever made it — Contacts, Collectives,
 * occ, another app. Such a team bypasses TeamHub's templates, policy profiles
 * and every other rule that only runs on the way through `createTeam()`.
 * From this version TeamHub shows only the teams it created itself (Justin,
 * 2026-09-20: "we don't show it in TeamHub"; no adoption flow in this
 * version). This table is that list: one row per team TeamHub created,
 * written by `TeamService::createTeam()`, removed with the team.
 *
 * `postSchemaChange()` grandfathers every team that exists at upgrade time
 * (origin `grandfathered`): the registry is empty at that moment and TeamHub
 * cannot tell which existing circles it made, so nothing a member sees today
 * disappears. The rule only bites for circles created after the upgrade.
 * A fresh install has no circles, so the schema-only install path needing no
 * post step is correct here (`/migrations` §2a) — this is a data repair on
 * rows an upgrade left behind, not seed data.
 *
 * The same post step sets Circles' `CFG_APP` bit (131072, "managed by an
 * app") on every grandfathered team. Circles' `CircleDestroy::verify()`
 * refuses to destroy a circle carrying that bit, which is how a team created
 * in TeamHub becomes undeletable from Nextcloud's own Teams page; TeamHub
 * clears the bit right before its own destroy. Written to the row directly,
 * as `TeamService::updateTeamConfig()` writes every config bit: Circles'
 * `CircleConfig::verify()` refuses the whole update for a circle carrying a
 * core-filter bit (DESIGN §2.78), and a legacy team must be lockable too.
 *
 * Identifier lengths: the table name is 21 characters; every key is named
 * explicitly (`/migrations` §1). Self-contained — no `OCA\TeamHub\`
 * constant or class is referenced (`/migrations` §2, issue #98); `16`,
 * `131072` and `grandfathered` are the literal values this step wrote.
 */
class Version000410006Date20260920120000 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_team_registry')) {
            return null;
        }

        $table = $schema->createTable('teamhub_team_registry');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
        // circles_circle.unique_id
        $table->addColumn('team_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        // 'teamhub' (created through TeamService::createTeam) or 'grandfathered' (existed at the 4.10.6 upgrade).
        $table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'teamhub']);
        // The creator's uid; null for a grandfathered row.
        $table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8, 'default' => 0]);

        $table->setPrimaryKey(['id'], 'th_treg_pk');
        // One row per team — the rule and the lookup in one.
        $table->addUniqueIndex(['team_id'], 'th_treg_team_uq');

        return $schema;
    }

    /**
     * Grandfather every existing team and lock it against deletion from the
     * Teams page. Runs once, on the upgrade that creates the table.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('circles_circle') || !$schema->hasTable('teamhub_team_registry')) {
            return;
        }

        $known = [];
        $qb    = $this->db->getQueryBuilder();
        $res   = $qb->select('team_id')->from('teamhub_team_registry')->executeQuery();
        while ($row = $res->fetch()) {
            $known[(string)$row['team_id']] = true;
        }
        $res->closeCursor();

        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('source', $qb->createNamedParameter(16, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $teams = [];
        while ($row = $res->fetch()) {
            $teams[(string)$row['unique_id']] = (int)($row['config'] ?? 0);
        }
        $res->closeCursor();

        $now        = time();
        $registered = 0;
        $locked     = 0;
        foreach ($teams as $teamId => $config) {
            if ($teamId === '') {
                continue;
            }
            if (!isset($known[$teamId])) {
                $ins = $this->db->getQueryBuilder();
                $ins->insert('teamhub_team_registry')
                    ->values([
                        'team_id'    => $ins->createNamedParameter($teamId),
                        'origin'     => $ins->createNamedParameter('grandfathered'),
                        'created_by' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                        'created_at' => $ins->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    ])
                    ->executeStatement();
                $registered++;
            }
            if (($config & 131072) === 0) {
                $upd = $this->db->getQueryBuilder();
                $upd->update('circles_circle')
                    ->set('config', $upd->createNamedParameter($config | 131072, IQueryBuilder::PARAM_INT))
                    ->where($upd->expr()->eq('unique_id', $upd->createNamedParameter($teamId)))
                    ->executeStatement();
                $locked++;
            }
        }

        $output->info(sprintf(
            'TeamHub team registry: %d existing team(s) grandfathered, %d locked against deletion from the Teams page.',
            $registered,
            $locked,
        ));
    }
}
