<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCA\TeamHub\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.8.5 — the default policy moves from the instance to the template.
 *
 * v4.8.4 put "which policy do new teams get" in one instance-wide appconfig
 * value. Justin's answer on 2026-09-01 is that it belongs **per template**: a
 * Project team and a Department team are different kinds of thing and start
 * from different postures, which is the same reasoning that moved expiry onto
 * the template in 4.8.3. The creator can still pick a different one in the
 * wizard, where the field is required.
 *
 * So: one column added, one appconfig value deleted.
 *
 * `default_profile_key` is nullable and ships null on every seeded template,
 * which keeps Track F inert — a template with no default offers the creator
 * nothing pre-selected and the wizard behaves as it did.
 */
class Version000408005Date20260901020000 extends SimpleMigrationStep {

    /** The v4.8.4 instance-wide setting this replaces. */
    private const RETIRED_CONFIG_KEY = 'policy_default_profile';

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_template')) {
            return null;
        }

        $table = $schema->getTable('teamhub_template');
        if ($table->hasColumn('default_profile_key')) {
            return null;
        }

        // Nullable rather than defaulted: "this template has no default
        // policy" is a real state and is what every template ships with.
        // A default of '' would make the empty string mean two things.
        $table->addColumn('default_profile_key', Types::STRING, [
            'notnull' => false,
            'length'  => 32,
        ]);

        return $schema;
    }

    /**
     * Drop the retired instance-wide default.
     *
     * Deleted rather than migrated onto every template: it was live for one
     * version, an instance that set it did so as a stand-in for the per-template
     * setting that did not exist yet, and copying one value onto three templates
     * would guess at an intent nobody expressed.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $existing = $this->config->getAppValue(Application::APP_ID, self::RETIRED_CONFIG_KEY, '');
        if ($existing !== '') {
            $output->info(
                'Version000408005: the instance-wide default policy ("' . $existing . '") is retired; '
                . 'set a default per template on Admin → TeamHub → Policy.',
            );
        }
        $this->config->deleteAppValue(Application::APP_ID, self::RETIRED_CONFIG_KEY);
    }
}
