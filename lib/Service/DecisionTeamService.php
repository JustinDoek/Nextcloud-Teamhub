<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionTeamConfig;
use OCA\TeamHub\Db\DecisionTeamConfigMapper;
use Psr\Log\LoggerInterface;

/**
 * Per-team Decisions module configuration.
 *
 * Reads/writes teamhub_decision_team rows. Absence of a row = all defaults
 * (decisions_enabled=0, decisions_level_enabled=0). Rows are created on first
 * write (same pattern as PresenceTeamService).
 */
class DecisionTeamService {

    public function __construct(
        private DecisionTeamConfigMapper $configMapper,
        private LoggerInterface          $logger,
    ) {}

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
     * @return array{decisions_enabled: bool, decisions_level_enabled: bool}
     */
    public function saveConfig(string $teamId, array $data): array {
        $now = time();
        $row = $this->configMapper->findByTeam($teamId);

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

        return [
            'decisions_enabled'           => $saved->getDecisionsEnabled() === 1,
            'decisions_level_enabled'     => $saved->getDecisionsLevelEnabled() === 1,
            'decisions_action_min_level'  => $saved->getDecisionsActionMinLevel(),
        ];
    }
}
