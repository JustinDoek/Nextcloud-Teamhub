<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamTemplates;
use OCA\TeamHub\Service\Provisioning\Blueprint;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * v4.10.3 — the team templates and the starter policy profiles, seeded where
 * a fresh install can actually get them.
 *
 * Nextcloud installs an app with `MigrationService::migrate('latest', true)`
 * — schema only. Every step's `changeSchema()` is folded into one in-memory
 * schema and applied once; `preSchemaChange()` / `postSchemaChange()` never
 * run, and the `<post-migration>` repair steps are skipped as well
 * (`Installer::installApp()`, gated on a previous version being present).
 * So the seeds in `Version000408002` (three templates, four profiles),
 * `Version000409004` (the `openproject` template) and `Version000409006` (its
 * blueprint) ran on every *upgrade* and on no *fresh install*: Admin →
 * TeamHub → Policy came up empty and `PolicyPropagationService` found no
 * template row for a new team. Found on the first run of the whole chain
 * against an empty Nextcloud 35 (2026-09-20).
 *
 * A repair step registered under both `<install>` and `<post-migration>` is
 * the mechanism Nextcloud provides for data that every instance must have.
 * The migrations keep their seeds — an upgrading instance is served by them
 * first and this step then finds the rows present.
 *
 * Rules, so that an administrator's edits survive every upgrade:
 *   - A template is inserted when its key is absent. Templates cannot be
 *     deleted, so an absent key is a template this instance never had. An
 *     existing row is never touched — label, apps, modules, defaults and
 *     blueprint are the administrator's.
 *   - The `openproject` template's blueprint is written only together with
 *     that row (a row that exists keeps whatever blueprint it has, including
 *     none — `Version000409006` owns the upgrade case).
 *   - Profiles CAN be deleted (`PolicyService::deleteProfile`), so absence is
 *     not proof they were never there. The four starter profiles are seeded
 *     only when the profile table is empty AND this step has never seeded on
 *     this instance (app-config marker) — at most once per instance, never
 *     undoing a deletion an administrator made after the instance had them.
 *
 * The rows themselves come from the live application (`TeamTemplates`,
 * `Blueprint::defaultsForOpenProject()`), unlike a migration, which freezes
 * literals (/migrations §2): a repair step runs with the code it ships with,
 * so live constants are exactly right here, and the templates a fresh install
 * gets are the ones the app describes today.
 */
class SeedTemplatesAndProfiles implements IRepairStep {

    /** App-config key: the profile seed has run once on this instance. */
    private const PROFILES_SEEDED_KEY = 'policy_profiles_seeded';

    /**
     * Per template: label, sort index, whether the wizard offers an expiry.
     * Apps, modules and the Circles preselection come from `TeamTemplates`.
     * Department teams are not eligible for an expiration date
     * (`TeamExpiryService::isEligible()` allows collaboration and project).
     */
    private const TEMPLATE_META = [
        'collaboration' => ['label' => 'Collaboration',       'sort' => 0,  'expiry' => 1],
        'project'       => ['label' => 'Project',             'sort' => 10, 'expiry' => 1],
        'openproject'   => ['label' => 'OpenProject project', 'sort' => 15, 'expiry' => 1],
        'department'    => ['label' => 'Department',          'sort' => 20, 'expiry' => 0],
        // v4.10.23 — a service desk. No expiry: a desk is not time-bound,
        // and a service that expires would take its queue with it.
        'service'       => ['label' => 'Service team',        'sort' => 30, 'expiry' => 0],
    ];

    /**
     * The starter profiles — one ordered sensitivity axis (DESIGN §2.105),
     * gaps of ten so an administrator can insert a level between two seeded
     * ones. Same rows as Version000408002 minus the `mode` column that
     * Version000408003 dropped.
     */
    private const PROFILES = [
        'public' => [
            'label' => 'Public', 'sort' => 0,
            'description' => 'Open to the whole instance. Nothing is locked.',
            'values' => ['cfg_visible' => true, 'cfg_open' => true, 'external_members' => true, 'public_messages' => true],
        ],
        'internal' => [
            'label' => 'Internal', 'sort' => 10,
            'description' => 'Discoverable inside the organisation, but nobody joins on their own.',
            'values' => ['cfg_visible' => true, 'cfg_open' => false, 'external_members' => false, 'public_messages' => false],
        ],
        'confidential' => [
            'label' => 'Confidential', 'sort' => 20,
            'description' => 'Not discoverable, invitation only, no external members.',
            'values' => ['cfg_visible' => false, 'cfg_open' => false, 'cfg_invite' => true, 'external_members' => false, 'public_messages' => false],
        ],
        'restricted' => [
            'label' => 'Restricted', 'sort' => 30,
            'description' => 'The most protected posture. Every governed setting is locked.',
            'values' => ['cfg_visible' => false, 'cfg_open' => false, 'cfg_invite' => true, 'cfg_protected' => true, 'external_members' => false, 'public_messages' => false],
        ],
    ];

    public function __construct(
        private IDBConnection   $db,
        private IConfig         $config,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Seed TeamHub team templates and starter policy profiles';
    }

    public function run(IOutput $output): void {
        // Both tables come from Version000408002, which precedes every run of
        // this step. If the step is ever invoked earlier, fail soft — the next
        // upgrade runs it again.
        foreach (['teamhub_template', 'teamhub_policy_profile', 'teamhub_policy_value'] as $table) {
            if (!$this->tableReadable($table)) {
                $output->warning("SeedTemplatesAndProfiles: {$table} not available yet — skipping (will run on next upgrade)");
                return;
            }
        }

        $now = time();
        $this->seedTemplates($output, $now);
        $this->seedProfiles($output, $now);
    }

    private function seedTemplates(IOutput $output, int $now): void {
        $present = $this->presentKeys('teamhub_template', 'template_key');
        $inserted = 0;

        foreach (self::TEMPLATE_META as $key => $meta) {
            if (isset($present[$key])) {
                continue;
            }

            $profile = TeamTemplates::forTemplate($key);
            $apps    = array_keys(array_filter($profile['apps']));
            $modules = array_keys(array_filter($profile['modules']));
            $blueprint = $key === 'openproject'
                ? json_encode(Blueprint::defaultsForOpenProject()->toArray(), JSON_UNESCAPED_UNICODE)
                : null;

            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_template')->values([
                'template_key'     => $qb->createNamedParameter($key),
                'label'            => $qb->createNamedParameter($meta['label']),
                'description'      => $qb->createNamedParameter(null),
                'apps'             => $qb->createNamedParameter(implode(';', $apps)),
                'modules'          => $qb->createNamedParameter(implode(';', $modules)),
                'offer_expiry'     => $qb->createNamedParameter($meta['expiry'], IQueryBuilder::PARAM_INT),
                'preselect_config' => $qb->createNamedParameter(TeamTemplates::configBitmask($key), IQueryBuilder::PARAM_INT),
                'sort_index'       => $qb->createNamedParameter($meta['sort'], IQueryBuilder::PARAM_INT),
                'is_seeded'        => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'updated_by'       => $qb->createNamedParameter(null),
                'updated_at'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'blueprint_json'   => $qb->createNamedParameter($blueprint),
            ]);
            $qb->executeStatement();
            $inserted++;
        }

        $output->info(sprintf(
            'SeedTemplatesAndProfiles: templates — inserted %d, present %d',
            $inserted,
            count($present),
        ));
    }

    private function seedProfiles(IOutput $output, int $now): void {
        $present = $this->presentKeys('teamhub_policy_profile', 'profile_key');

        if ($present !== []) {
            $this->markProfilesSeeded();
            $output->info('SeedTemplatesAndProfiles: profiles — ' . count($present) . ' present, nothing to seed');
            return;
        }

        if ($this->config->getAppValue(Application::APP_ID, self::PROFILES_SEEDED_KEY, '') === '1') {
            $output->info('SeedTemplatesAndProfiles: profiles — none present, seeded before on this instance; leaving the administrator\'s deletion alone');
            return;
        }

        foreach (self::PROFILES as $key => $def) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_policy_profile')->values([
                'profile_key' => $qb->createNamedParameter($key),
                'label'       => $qb->createNamedParameter($def['label']),
                'description' => $qb->createNamedParameter($def['description']),
                'sort_index'  => $qb->createNamedParameter($def['sort'], IQueryBuilder::PARAM_INT),
                'is_seeded'   => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'created_by'  => $qb->createNamedParameter(''),
                'created_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_by'  => $qb->createNamedParameter(null),
                'updated_at'  => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
            $qb->executeStatement();

            foreach ($def['values'] as $fieldKey => $value) {
                $vb = $this->db->getQueryBuilder();
                $vb->insert('teamhub_policy_value')->values([
                    'profile_key' => $vb->createNamedParameter($key),
                    'field_key'   => $vb->createNamedParameter($fieldKey),
                    'field_value' => $vb->createNamedParameter($value ? '1' : '0'),
                ]);
                $vb->executeStatement();
            }
        }

        $this->markProfilesSeeded();
        $output->info('SeedTemplatesAndProfiles: profiles — seeded ' . count(self::PROFILES) . ', none assigned to any team');
    }

    private function markProfilesSeeded(): void {
        $this->config->setAppValue(Application::APP_ID, self::PROFILES_SEEDED_KEY, '1');
    }

    /** @return array<string,true> the values of $column, as a set */
    private function presentKeys(string $table, string $column): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($column)->from($table)->executeQuery();
        $keys = [];
        while (($row = $result->fetch()) !== false) {
            $keys[(string)$row[$column]] = true;
        }
        $result->closeCursor();
        return $keys;
    }

    private function tableReadable(string $table): bool {
        try {
            $this->db->getQueryBuilder()->select('*')->from($table)->setMaxResults(1)->executeQuery()->closeCursor();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SeedTemplatesAndProfiles] Table not available: ' . $table . ' — ' . $e->getMessage());
            return false;
        }
    }
}
