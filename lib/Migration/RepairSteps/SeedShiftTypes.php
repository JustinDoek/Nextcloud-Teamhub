<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Idempotent slug-keyed seed of the three built-in shift types (v4.13.67).
 *
 * A repair step rather than a seed inside `Version000413067`, for the reason
 * `/migrations` rule 2a records: Nextcloud installs an app with
 * `migrate('latest', schemaOnly: true)`, so `postSchemaChange()` never runs on
 * a fresh install and a migration-seeded catalogue reaches every upgrading
 * instance and no new one. That is how 4.9.1 shipped without presence types.
 * Registered in `appinfo/info.xml` under both `<install>` and
 * `<post-migration>`, the shape `SeedShiftTypes`' sibling `SeedPresenceTypes`
 * established.
 *
 * **Insert-when-absent, never update.** An administrator may rename *Early*,
 * recolour it or switch it off, and a later upgrade must not undo that. Slug
 * is the unique key, so re-running cannot duplicate. Changing a built-in's
 * canonical values across a release means editing this array *and* writing the
 * update deliberately, not relying on this step to stomp.
 *
 * Three is the whole catalogue on purpose. An organisation that works a
 * two-shift day should not have to delete four rows before it can start, and
 * one that needs *Twilight* adds it in one screen.
 */
class SeedShiftTypes implements IRepairStep {

    /**
     * The canonical built-ins. Times are wall clock; `Night` ends before it
     * starts, which is how a shift says it runs into the next day.
     *
     * Colours are the Nextcloud-neutral set the presence types already use,
     * chosen to stay distinguishable from the workplace palette: a roster cell
     * and a workplace cell sit in the same grid.
     *
     * @var array<int, array<string, mixed>>
     */
    private const BUILTINS = [
        [
            'slug'       => 'early',
            'label'      => 'Early',
            'start_time' => '07:00',
            'end_time'   => '15:00',
            'color'      => '#0082C9',
            'icon'       => 'WeatherSunsetUp',
            'sort_order' => 10,
        ],
        [
            'slug'       => 'late',
            'label'      => 'Late',
            'start_time' => '15:00',
            'end_time'   => '23:00',
            'color'      => '#E8A33D',
            'icon'       => 'WeatherSunsetDown',
            'sort_order' => 20,
        ],
        [
            'slug'       => 'night',
            'label'      => 'Night',
            'start_time' => '23:00',
            'end_time'   => '07:00',
            'color'      => '#6D4AFF',
            'icon'       => 'WeatherNight',
            'sort_order' => 30,
        ],
    ];

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Seed TeamHub built-in shift types';
    }

    public function run(IOutput $output): void {
        // The table is created by Version000413067, which runs before any
        // post-migration repair step. If this is somehow invoked before that,
        // fail cleanly rather than aborting the upgrade for the whole
        // instance — the same defence SeedPresenceTypes carries.
        try {
            $this->db->getQueryBuilder()
                ->select('id')
                ->from('teamhub_shift_type')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable $e) {
            $output->warning(
                'SeedShiftTypes: teamhub_shift_type not available yet — '
                . 'skipping (will run on next upgrade)'
            );
            $this->logger->warning(
                '[TeamHub][SeedShiftTypes] Table not available: ' . $e->getMessage()
            );
            return;
        }

        $inserted = 0;
        $skipped  = 0;
        $now      = time();

        foreach (self::BUILTINS as $row) {
            if ($this->findIdBySlug($row['slug']) !== null) {
                $skipped++;
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_shift_type')->values([
                'slug'       => $qb->createNamedParameter($row['slug']),
                'label'      => $qb->createNamedParameter($row['label']),
                'start_time' => $qb->createNamedParameter($row['start_time']),
                'end_time'   => $qb->createNamedParameter($row['end_time']),
                'color'      => $qb->createNamedParameter($row['color']),
                'icon'       => $qb->createNamedParameter($row['icon']),
                'sort_order' => $qb->createNamedParameter($row['sort_order'], IQueryBuilder::PARAM_INT),
                'is_active'  => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'is_builtin' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $inserted++;
        }

        $output->info(sprintf(
            'SeedShiftTypes: inserted %d, skipped %d (already present)',
            $inserted,
            $skipped
        ));
    }

    /** The id of the row with this slug, or null. Slug is unique-indexed. */
    private function findIdBySlug(string $slug): ?int {
        $qb = $this->db->getQueryBuilder();
        $id = $qb->select('id')
            ->from('teamhub_shift_type')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $id !== false ? (int)$id : null;
    }
}
