<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCA\TeamHub\Service\DecisionCategoryService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Give every team that already uses Decisions its Archive category, and a
 * General one if it has no categories at all (v4.11.18).
 *
 * From this version a team gets both when the module is switched on
 * (`DecisionTeamService::saveConfig()`). Teams whose module was switched on
 * before it have neither, and the archive is not optional: superseding or
 * archiving a decision moves it into the archive category, so a team without
 * one cannot retire a decision at all.
 *
 * **Why a repair step and not the migration.** A fresh install runs the
 * migrations schema-only — `postSchemaChange()` never fires and
 * `<post-migration>` steps are skipped (migrations skill, rule 2a) — so
 * rows seeded in a migration reach every upgrading instance and no new one.
 * This step is registered under both `<install>` and `<post-migration>`. On
 * a fresh install it finds no team with the module on and does nothing,
 * which is correct: the first team to switch it on gets its categories from
 * the service.
 *
 * **Idempotent, and it never undoes an edit.** `ensureSeedCategories()` is
 * the same method the service calls: Archive is created only when the team
 * has no category carrying the flag, and General only when the team has no
 * categories whatsoever. A team that renamed, re-approved or deleted
 * General does not find it back tomorrow morning — the rules are in that
 * method's docblock, which is the one place they live.
 *
 * `created_by` is empty: a repair step has no session, and naming an
 * administrator who did not do this would put a false author on the row. The
 * column is display-only.
 *
 * This is application code rather than a migration, so it may use live
 * constants — that is the point of a repair step: it runs with the code it
 * ships with.
 */
class SeedDecisionCategories implements IRepairStep {

    public function __construct(
        private IDBConnection           $db,
        private DecisionCategoryService $categoryService,
        private LoggerInterface         $logger,
    ) {}

    public function getName(): string {
        return 'Seed TeamHub Decisions categories (General, Archive) for teams already using Decisions';
    }

    public function run(IOutput $output): void {
        try {
            $teamIds = $this->teamsWithDecisionsOn();
            if ($teamIds === []) {
                return;
            }

            $general = 0;
            $archive = 0;
            $failed  = 0;
            $reasons = [];

            foreach ($teamIds as $teamId) {
                try {
                    $made = $this->categoryService->ensureSeedCategories($teamId, '');
                    if ($made['general'] !== null) {
                        $general++;
                    }
                    if ($made['archive'] !== null) {
                        $archive++;
                    }
                    // v4.11.22 — a per-category failure never reached this
                    // counter, because `ensureSeedCategories()` caught it and
                    // returned null, which also means "already there". So the
                    // step printed *0 created, 0 skipped* on an instance where
                    // every one of 54 teams had failed, and the only trace was
                    // a warning nobody had a reason to look for.
                    foreach ($made['failed'] as $why) {
                        $failed++;
                        $reasons[$why] = ($reasons[$why] ?? 0) + 1;
                    }
                } catch (\Throwable $e) {
                    // One team's failure must not cost the other teams their
                    // archive. Counted and logged, never thrown: an upgrade
                    // does not fail over a seed.
                    $failed++;
                    $reasons[$e->getMessage()] = ($reasons[$e->getMessage()] ?? 0) + 1;
                    $this->logger->warning('[TeamHub][SeedDecisionCategories] team skipped', [
                        'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => 'teamhub',
                    ]);
                }
            }

            $output->info(sprintf(
                'Decisions categories: %d team(s) with the module on — General created for %d, Archive for %d, %d failed.',
                count($teamIds),
                $general,
                $archive,
                $failed,
            ));

            // Say *why*, once per distinct reason. 54 identical failures are
            // one fact, and printing it is the difference between a step that
            // reports and a step that merely runs.
            foreach ($reasons as $why => $count) {
                $output->warning(sprintf('  %d x %s', $count, $why));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SeedDecisionCategories] could not seed categories', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }

    /**
     * Teams whose Decisions module is on.
     *
     * A missing row means the module is off (`DecisionTeamService`), so the
     * table is the whole answer and there is nothing to reconcile against
     * `circles_circle`: a team that no longer exists has nothing to seed and
     * `ensureSeedCategories()` would simply write two rows nobody reads. The
     * module-config table is cleaned up with the team elsewhere.
     *
     * @return string[]
     */
    private function teamsWithDecisionsOn(): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('team_id')
                ->from('teamhub_decision_team')
                ->where($qb->expr()->eq('decisions_enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            $out = [];
            while ($row = $res->fetch()) {
                $id = (string)$row['team_id'];
                if ($id !== '' && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            // The table not being there means no instance has ever
            // configured Decisions, which is not a failure.
            $this->logger->debug('[TeamHub][SeedDecisionCategories] module-config table unreadable', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
            return [];
        }
    }
}
