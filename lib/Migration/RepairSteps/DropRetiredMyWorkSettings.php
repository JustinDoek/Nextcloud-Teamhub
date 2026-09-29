<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Remove the retired My Work source-status -> category map (v4.10.20).
 *
 * The map let an administrator override the category a provider assigned an
 * item, keyed on `{provider}.{status}`. That key can never be right for both
 * parties of a workflow -- the same status is "action required" for whoever
 * must act and "waiting for others" for whoever asked -- so the provider is
 * now the only authority and the setting is gone. See DESIGN.md 2.143.
 *
 * The stored value would otherwise sit in `oc_appconfig` forever, read by
 * nothing. Deleting it also means an instance that downgrades and upgrades
 * again does not resurrect overrides nobody remembers making.
 *
 * Literal strings rather than application constants, like every file under
 * `lib/Migration/` (`npm run check:migrations`).
 */
class DropRetiredMyWorkSettings implements IRepairStep {

    private const APP_ID = 'teamhub';
    private const KEY    = 'mywork_category_map';

    public function __construct(
        private IConfig         $config,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Remove the retired TeamHub My Work category mapping';
    }

    public function run(IOutput $output): void {
        try {
            if ($this->config->getAppValue(self::APP_ID, self::KEY, '') === '') {
                return;
            }
            $this->config->deleteAppValue(self::APP_ID, self::KEY);
            $output->info('Retired My Work category mapping removed; sources now decide their own categories.');
        } catch (\Throwable $e) {
            // Never fail an upgrade over a value nothing reads any more.
            $this->logger->warning('[TeamHub][DropRetiredMyWorkSettings] could not remove the retired mapping', [
                'error' => $e->getMessage(), 'app' => 'teamhub',
            ]);
        }
    }
}
