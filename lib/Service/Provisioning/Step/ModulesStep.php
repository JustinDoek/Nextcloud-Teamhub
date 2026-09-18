<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\IntravoxService;
use OCA\TeamHub\Service\PresenceTeamService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\ResourceService;
use OCA\TeamHub\Service\TeamService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Step — TeamHub's own modules for the team: the feature switches
 * (Messages, Decisions, Presence, Timeline) exactly as the wizard's
 * `saveModuleConfig()` writes them, the Intravox page when the workspace
 * has Pages, and the toggle-app rows Manage team reads.
 *
 * Every write is a set-to-value, so running twice is running once. The
 * Intravox page is looked up before it is created. Nothing here holds
 * user data at creation time, so rollback has nothing to protect: the team
 * cascade removes the page and the config keys.
 */
class ModulesStep implements StepInterface {

    public const KEY = 'modules';

    public function __construct(
        private TeamService         $teams,
        private ResourceService     $resources,
        private DecisionTeamService $decisions,
        private PresenceTeamService $presence,
        private IntravoxService     $intravox,
        private IConfig             $config,
        private LoggerInterface     $logger,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return null; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        if ($ctx->teamId === null) {
            return StepResult::failed('no_team', 'The team does not exist yet.', true);
        }
        $teamId    = $ctx->teamId;
        $installed = $this->resources->checkInstalledApps();
        $notes     = [];
        $applied   = [];

        // ── Feature switches ─────────────────────────────────────────────
        // Messages and Timeline default on; only an explicit "off" is written.
        $this->config->setAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, $ctx->hasModule('messages') ? '1' : '0');
        $applied['messages'] = $ctx->hasModule('messages');
        $this->config->setAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, $ctx->hasModule('timeline') ? '1' : '0');
        $applied['timeline'] = $ctx->hasModule('timeline');

        if ($ctx->hasModule('decisions') && !empty($installed['decisionsModuleEnabled'])) {
            try {
                $this->decisions->saveConfig($teamId, ['decisions_enabled' => 1]);
                $applied['decisions'] = true;
            } catch (\Throwable $e) {
                $notes['decisions'] = $e->getMessage();
            }
        }
        if ($ctx->hasModule('presence') && !empty($installed['presenceModuleEnabled'])) {
            try {
                $this->presence->saveConfig($teamId, ['presence_enabled' => 1]);
                $applied['presence'] = true;
            } catch (\Throwable $e) {
                $notes['presence'] = $e->getMessage();
            }
        }

        // ── Pages (Intravox): a toggle row plus the page ─────────────────
        $wantsPages = $ctx->hasApp('intravox') && !empty($installed['intravox']);
        try {
            $this->teams->updateTeamApps($teamId, [['app_id' => 'intravox', 'enabled' => $wantsPages, 'config' => null]]);
        } catch (\Throwable $e) {
            $notes['intravox_toggle'] = $e->getMessage();
        }
        if ($wantsPages) {
            try {
                $existing = $this->intravox->getTeamPage($teamId, $ctx->teamName());
                if ($existing === null) {
                    $result = $this->intravox->createPage($teamId, $ctx->teamName());
                    if (isset($result['error'])) {
                        $notes['intravox'] = (string)$result['error'];
                    } else {
                        $applied['intravox'] = $result['page_id'] ?? true;
                    }
                    $this->intravox->invalidateSubPagesCache($teamId);
                } else {
                    $applied['intravox'] = $existing['id'] ?? true;
                }
            } catch (\Throwable $e) {
                $notes['intravox'] = $e->getMessage();
            }
        }

        $ctx->remember(self::KEY, 'applied', $applied);
        if ($notes !== []) {
            $this->logger->info('[TeamHub][ModulesStep] some modules were not applied', ['teamId' => $teamId, 'notes' => array_keys($notes), 'app' => Application::APP_ID]);
            return StepResult::attention('modules_partial', 'Some modules could not be configured: ' . implode(', ', array_keys($notes)), ['applied' => $applied, 'failed' => $notes]);
        }
        return StepResult::completed(null, ['applied' => $applied]);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return StepResult::skipped('removed_with_team');
    }
}
