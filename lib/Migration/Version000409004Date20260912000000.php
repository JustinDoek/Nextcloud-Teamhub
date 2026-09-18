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
 * v4.9.3 — OpenProject Phase 1, second step (Justin's review of the first):
 *
 *   1. **One OpenProject project links to one team.** `project_id` becomes
 *      unique (`th_opl_proj_uq`, replacing the plain `th_opl_proj_idx`). The
 *      service refuses first; this is the backstop against a race. Note the
 *      index is on `project_id` alone: the official integration app supports
 *      exactly one OpenProject instance, so ids cannot collide across hosts
 *      today. A multi-instance phase adds a host discriminator here.
 *
 *   2. **The `openproject` team template.** OpenProject is a project *type*
 *      of its own — an administrator configures its apps and modules on
 *      Admin → TeamHub → Policy like the other three, and the OpenProject
 *      link, widgets and settings section exist only on teams created from
 *      it. Seeded with literal values (`/migrations` §2: a migration must not
 *      read the present). The row mirrors `TeamTemplates::PROFILES['openproject']`
 *      at the time of writing: Talk, Files and Calendar; no Deck and no
 *      Timeline, because work packages and the Gantt live in OpenProject;
 *      invite-only and not visible, like the Project template;
 *      `preselect_config` 32 = Circles' invite bit. Expiration is offered —
 *      a project ends.
 *
 * Both halves are guarded so the step is safe to re-run on an instance where
 * part of it already exists (a dev machine that ran an earlier draft).
 */
class Version000409004Date20260912000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    /**
     * The unique index cannot be created over duplicates. The first draft of
     * Phase 1 allowed a project on several teams after a confirmation, so a
     * dev instance may hold some; the oldest link per project stays, the
     * later ones go, and each removal is reported. Nothing in OpenProject is
     * touched — these are TeamHub rows only.
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_openproject_link')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'team_id', 'project_id')
            ->from('teamhub_openproject_link')
            ->orderBy('project_id', 'ASC')
            ->addOrderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();

        $seen    = [];
        $remove  = [];
        while ($row = $result->fetch()) {
            $pid = (int)$row['project_id'];
            if (isset($seen[$pid])) {
                $remove[] = ['id' => (int)$row['id'], 'team' => (string)$row['team_id'], 'project' => $pid];
                continue;
            }
            $seen[$pid] = true;
        }
        $result->closeCursor();

        foreach ($remove as $r) {
            $del = $this->db->getQueryBuilder();
            $del->delete('teamhub_openproject_link')
                ->where($del->expr()->eq('id', $del->createNamedParameter($r['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            $output->warning(sprintf(
                'teamhub_openproject_link: removed the link of team %s to OpenProject project %d — that project is already linked to another team',
                $r['team'],
                $r['project'],
            ));
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_openproject_link')) {
            return null;
        }

        $table   = $schema->getTable('teamhub_openproject_link');
        $changed = false;

        if ($table->hasIndex('th_opl_proj_idx')) {
            $table->dropIndex('th_opl_proj_idx');
            $changed = true;
        }
        if (!$table->hasIndex('th_opl_proj_uq')) {
            $table->addUniqueIndex(['project_id'], 'th_opl_proj_uq');
            $output->info('teamhub_openproject_link: project_id is now unique');
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('teamhub_template')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('template_key')
            ->from('teamhub_template')
            ->where($qb->expr()->eq('template_key', $qb->createNamedParameter('openproject')))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $exists = $result->fetch() !== false;
        $result->closeCursor();
        if ($exists) {
            return;
        }

        $now = time();
        $qb  = $this->db->getQueryBuilder();
        $qb->insert('teamhub_template')->values([
            'template_key'     => $qb->createNamedParameter('openproject'),
            'label'            => $qb->createNamedParameter('OpenProject project'),
            'description'      => $qb->createNamedParameter(null),
            'apps'             => $qb->createNamedParameter('talk;files;calendar'),
            'modules'          => $qb->createNamedParameter('decisions;messages;pages'),
            'offer_expiry'     => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            // 32 = Circles CFG_INVITE. Preselection only, not policy.
            'preselect_config' => $qb->createNamedParameter(32, IQueryBuilder::PARAM_INT),
            'sort_index'       => $qb->createNamedParameter(15, IQueryBuilder::PARAM_INT),
            'is_seeded'        => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'updated_by'       => $qb->createNamedParameter(null),
            'updated_at'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();

        $output->info('Version000409004: seeded the openproject team template');
    }
}
