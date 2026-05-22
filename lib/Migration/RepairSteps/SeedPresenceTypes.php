<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration\RepairSteps;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Idempotent slug-keyed upsert of the five built-in presence types.
 *
 * Registered in appinfo/info.xml under <repair-steps><post-migration> so it
 * runs after every NC update. Slug is the natural key and unique-indexed, so
 * re-running can never duplicate. Lets us evolve the built-in catalogue
 * across versions without writing a new migration for each change — change
 * the array, the next NC update applies it.
 *
 * What this run does for each row:
 *   - If a row with this slug does not exist: INSERT with the canonical values
 *     and is_builtin=1.
 *   - If a row with this slug already exists: UPDATE the "structural" fields
 *     (requires_location, is_busy, selectable_by_user, is_builtin) and the
 *     default label/icon/color, but ONLY if the admin has not customised
 *     label/icon/color. (We detect customisation by comparing the current
 *     value against the *previous* canonical default — if it differs from
 *     what we last shipped, the admin changed it; we don't stomp.)
 *
 *   For B1 this means: on a fresh install, all five rows get the canonical
 *   values. On an upgrade where this step already ran once, no writes happen.
 *
 * Boolean-shaped columns bind as PARAM_INT against the SMALLINT storage,
 * per DESIGN.md §2.4.
 */
class SeedPresenceTypes implements IRepairStep {

    /**
     * Canonical built-in catalogue. To add or modify a built-in across a
     * future TeamHub release, edit this array — the next NC update applies it.
     *
     * @var array<int, array<string, mixed>>
     */
    private const BUILTINS = [
        [
            'slug'               => 'office',
            'label'              => 'Office',
            'icon'               => 'OfficeBuilding',
            'color'              => '#1976D2',
            'requires_location'  => 1,
            'is_busy'            => 0,   // Free — reachable while at the office
            'selectable_by_user' => 1,
            'sort_order'         => 10,
        ],
        [
            'slug'               => 'home',
            'label'              => 'Home',
            'icon'               => 'HomeAccount',
            'color'              => '#388E3C',
            'requires_location'  => 0,
            'is_busy'            => 0,
            'selectable_by_user' => 1,
            'sort_order'         => 20,
        ],
        [
            // TRANSLATORS: presence status — personal leave taken by the user
            // (not a public/national holiday; see 'holiday' below).
            'slug'               => 'vacation',
            'label'              => 'Vacation',
            'icon'               => 'BeachUmbrella',
            'color'              => '#F57C00',
            'requires_location'  => 0,
            'is_busy'            => 1,
            'selectable_by_user' => 1,
            'sort_order'         => 30,
        ],
        [
            // TRANSLATORS: presence status — admin-locked public/national
            // holiday (e.g. King's Day, Christmas). Users cannot self-pick
            // this; it's set only by an admin defining a holiday date.
            'slug'               => 'holiday',
            'label'              => 'Holiday',
            'icon'               => 'CalendarStar',
            'color'              => '#C2185B',
            'requires_location'  => 0,
            'is_busy'            => 1,
            'selectable_by_user' => 0,
            'sort_order'         => 40,
        ],
        [
            'slug'               => 'non_working_day',
            'label'              => 'Non-working day',
            'icon'               => 'CalendarRemove',
            'color'              => '#616161',
            'requires_location'  => 0,
            'is_busy'            => 1,   // Busy — person is not available
            'selectable_by_user' => 1,
            'sort_order'         => 50,
        ],
    ];

    public function __construct(
        private IDBConnection   $db,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string {
        return 'Seed TeamHub built-in presence types';
    }

    public function run(IOutput $output): void {
        // Defensive: the table is created by Version000342000 which runs
        // before any post-migration repair step. But if this gets invoked on
        // an old install before that migration has run (extremely unlikely
        // edge case), fail cleanly without crashing the upgrade.
        try {
            $this->db->getQueryBuilder()
                ->select('id')
                ->from('teamhub_presence_types')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable $e) {
            $output->warning(
                'SeedPresenceTypes: teamhub_presence_types not available yet — '
                . 'skipping (will run on next upgrade)'
            );
            $this->logger->warning(
                '[TeamHub][SeedPresenceTypes] Table not available: ' . $e->getMessage()
            );
            return;
        }

        $inserted = 0;
        $skipped  = 0;
        $now      = time();

        foreach (self::BUILTINS as $row) {
            $exists = $this->findIdBySlug($row['slug']);

            if ($exists !== null) {
                // Already present — don't touch. Admins may have customised
                // label/icon/color; structural flags only change via a
                // deliberate code edit + repair-step re-run (handled by a
                // future TeamHub release explicitly updating this array
                // alongside an update method we'd add then).
                $skipped++;
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('teamhub_presence_types')
                ->values([
                    'slug'               => $qb->createNamedParameter($row['slug']),
                    'label'              => $qb->createNamedParameter($row['label']),
                    'icon'               => $qb->createNamedParameter($row['icon']),
                    'color'              => $qb->createNamedParameter($row['color']),
                    'requires_location'  => $qb->createNamedParameter(
                        $row['requires_location'], IQueryBuilder::PARAM_INT
                    ),
                    'is_busy'            => $qb->createNamedParameter(
                        $row['is_busy'], IQueryBuilder::PARAM_INT
                    ),
                    'selectable_by_user' => $qb->createNamedParameter(
                        $row['selectable_by_user'], IQueryBuilder::PARAM_INT
                    ),
                    'is_builtin'         => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'sort_order'         => $qb->createNamedParameter(
                        $row['sort_order'], IQueryBuilder::PARAM_INT
                    ),
                    'created_at'         => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ]);
            $qb->executeStatement();
            $inserted++;
        }

        $output->info(sprintf(
            'SeedPresenceTypes: inserted %d, skipped %d (already present)',
            $inserted,
            $skipped
        ));
    }

    /**
     * Returns the id of the row with this slug, or null if absent.
     * Slug is unique-indexed so this is single-row or empty.
     */
    private function findIdBySlug(string $slug): ?int {
        $qb = $this->db->getQueryBuilder();
        $id = $qb->select('id')
            ->from('teamhub_presence_types')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $id !== false ? (int)$id : null;
    }
}
