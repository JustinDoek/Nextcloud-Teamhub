<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.77.1 — One-time enable: set decisions_module_enabled = '1' for all
 * installations that download this patch.
 *
 * BACKGROUND
 * ----------
 * The Decisions module was introduced with a global NC-admin toggle that
 * defaulted to OFF. In v3.75.4 the getAppValue() default was flipped to
 * '1' so *new* installations (where the key was never stored) now default
 * to ON. However, existing installations that had '0' explicitly stored in
 * oc_appconfig are not affected by that change — getAppValue() only applies
 * the fallback when the key is absent.
 *
 * This migration writes '1' unconditionally so every installation is on
 * after the upgrade. The NC admin can turn it back off via Admin Settings →
 * Integrations if desired. Per-team enablement remains off by default and
 * is unchanged — team admins still opt in per team.
 *
 * RATIONALE
 * ---------
 * The Decisions module is now mature and stable. Keeping it hidden behind
 * an NC-admin toggle that defaults to OFF means most installs never discover
 * the feature. Enabling it at NC-admin level costs nothing until a team
 * admin actively turns it on for their team.
 */
class Version000377010Date20260614000000 extends SimpleMigrationStep {

    public function __construct(
        private IConfig $config,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes — this migration only touches app config.
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $current = $this->config->getAppValue('teamhub', 'decisions_module_enabled', '');

        if ($current === '1') {
            // Already explicitly enabled — nothing to do.
            $output->info('Version000377010: decisions_module_enabled already set to 1 — no change needed');
            return;
        }

        $this->config->setAppValue('teamhub', 'decisions_module_enabled', '1');

        $previous = $current === '' ? '(unset — defaulted to 0)' : $current;
        $output->info(
            "Version000377010: decisions_module_enabled set to '1' (was: {$previous})"
        );
    }
}
