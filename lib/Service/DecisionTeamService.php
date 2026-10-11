<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\DecisionTeamConfig;
use OCA\TeamHub\Db\DecisionTeamConfigMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Per-team Decisions module configuration.
 *
 * Reads/writes teamhub_decision_team rows. Absence of a row = all defaults
 * (decisions_enabled=0, decisions_level_enabled=0). Rows are created on first
 * write (same pattern as PresenceTeamService).
 *
 * v4.11.18 — switching the module **on** also gives the team its two
 * categories (General, Archive). It happens here rather than in the
 * controller because it is a property of the module being on, not of the
 * request that turned it on: the repair step for teams that pre-date this
 * version calls the same method, and neither caller has to know the rules.
 * Switching the module off leaves them alone — a team that turns Decisions
 * back on in a month should find its decisions where it left them.
 */
class DecisionTeamService {

    public function __construct(
        private DecisionTeamConfigMapper $configMapper,
        private DecisionCategoryService  $categoryService,
        private IConfig                  $config,
        private LoggerInterface          $logger,
    ) {}

    /**
     * The instance-wide Decisions switch (v4.11.42).
     *
     * The same app value `LayoutController` reads to send
     * `decisionsModuleEnabled` to the frontend, and it defaults to **on** —
     * Decisions is part of the app, not a licensed add-on, which is why there
     * is no licence check here and why `FileReviewService::isEnabledGlobally()`
     * is not a model for this method.
     *
     * It lives here because the Files-app entry needs the answer from a page
     * `LayoutController` never renders, and a second private copy of a
     * `getAppValue` default is how two surfaces end up disagreeing about
     * whether a module is on.
     */
    public function isModuleEnabledGlobally(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1';
    }

    /**
     * Can this team propose decisions at all? (v4.11.42)
     *
     * Both switches, in the order that makes the cheap one answer first. The
     * per-team flag is **off** unless a row says otherwise, so a team that has
     * never opened the module reads false — which is what hides TeamHub's
     * *Propose a decision* entry from the Files app for most teams.
     */
    public function isEnabledForTeam(string $teamId): bool {
        if ($teamId === '' || !$this->isModuleEnabledGlobally()) {
            return false;
        }

        return $this->getConfig($teamId)['decisions_enabled'];
    }

    /**
     * Return the per-team decisions config as a plain array.
     * Absent row = defaults (both flags = false).
     *
     * @return array{decisions_enabled: bool, decisions_level_enabled: bool}
     */
    public function getConfig(string $teamId): array {
        $row = $this->configMapper->findByTeam($teamId);
        return [
            'decisions_enabled'           => $row !== null && $row->getDecisionsEnabled() === 1,
            'decisions_level_enabled'     => $row !== null && $row->getDecisionsLevelEnabled() === 1,
            'decisions_action_min_level'  => $row !== null ? $row->getDecisionsActionMinLevel() : 1,
        ];
    }

    /**
     * Write config flags. Only keys present in $data are changed.
     * Creates the row on first write.
     *
     * @param array<string, mixed> $data  Keys: decisions_enabled (bool/int),
     *                                         decisions_level_enabled (bool/int)
     * @param string $actingUserId  v4.11.18 — recorded as `created_by` on the
     *                              categories seeded when the module is
     *                              switched on. Optional so the two existing
     *                              callers keep working; empty means "not
     *                              known", which is what the repair step
     *                              passes and what the column is prepared for.
     * @return array{decisions_enabled: bool, decisions_level_enabled: bool}
     */
    public function saveConfig(string $teamId, array $data, string $actingUserId = ''): array {
        $now = time();
        $row = $this->configMapper->findByTeam($teamId);
        // Read before the write, so "was it off and is it now on" is a fact
        // and not a guess. A missing row means off.
        $wasEnabled = $row !== null && $row->getDecisionsEnabled() === 1;

        if ($row === null) {
            $row = new DecisionTeamConfig();
            $row->setTeamId($teamId);
            $row->setDecisionsEnabled(0);
            $row->setDecisionsLevelEnabled(0);
            $row->setDecisionsActionMinLevel(4);
            $row->setCreatedAt($now);
            $row->setUpdatedAt($now);

            if (array_key_exists('decisions_enabled', $data)) {
                $row->setDecisionsEnabled((int)!!$data['decisions_enabled']);
            }
            if (array_key_exists('decisions_level_enabled', $data)) {
                $row->setDecisionsLevelEnabled((int)!!$data['decisions_level_enabled']);
            }
            if (array_key_exists('decisions_action_min_level', $data)) {
                $row->setDecisionsActionMinLevel((int)$data['decisions_action_min_level']);
            }

            /** @var DecisionTeamConfig $saved */
            $saved = $this->configMapper->insert($row);
        } else {
            if (array_key_exists('decisions_enabled', $data)) {
                $row->setDecisionsEnabled((int)!!$data['decisions_enabled']);
            }
            if (array_key_exists('decisions_level_enabled', $data)) {
                $row->setDecisionsLevelEnabled((int)!!$data['decisions_level_enabled']);
            }
            if (array_key_exists('decisions_action_min_level', $data)) {
                $row->setDecisionsActionMinLevel((int)$data['decisions_action_min_level']);
            }
            $row->setUpdatedAt($now);

            /** @var DecisionTeamConfig $saved */
            $saved = $this->configMapper->update($row);
        }

        $this->logger->info(sprintf(
            '[TeamHub][DecisionTeamService] saveConfig team=%s enabled=%d level_enabled=%d action_min_level=%d',
            $teamId,
            $saved->getDecisionsEnabled(),
            $saved->getDecisionsLevelEnabled(),
            $saved->getDecisionsActionMinLevel(),
        ));

        // v4.11.18 — the module coming on is what seeds the categories. Only
        // on the transition: a team admin saving the Level toggle for the
        // tenth time should not keep re-asking, and `ensureSeedCategories()`
        // being idempotent is a safety net rather than a licence to call it
        // on every write.
        //
        // After the config write and outside its failure path on purpose: a
        // failed seed must not leave the module off when the admin was told
        // it is on. The repair step and the next enable both pick it up, and
        // the retire paths refuse with a sentence when the archive is
        // missing rather than failing obscurely.
        if (!$wasEnabled && $saved->getDecisionsEnabled() === 1) {
            try {
                $made = $this->categoryService->ensureSeedCategories($teamId, $actingUserId);
                $this->logger->info('[TeamHub][DecisionTeamService] seeded decision categories', [
                    'teamId'  => $teamId,
                    'general' => $made['general'] !== null,
                    'archive' => $made['archive'] !== null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DecisionTeamService] seeding decision categories failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'decisions_enabled'           => $saved->getDecisionsEnabled() === 1,
            'decisions_level_enabled'     => $saved->getDecisionsLevelEnabled() === 1,
            'decisions_action_min_level'  => $saved->getDecisionsActionMinLevel(),
        ];
    }
}
