<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.28.2 — Connected Resources: Session A.
 *
 * Creates `teamhub_team_app_resources` and grandfathers every resource that
 * is currently visible to TeamHub as an active row.
 *
 * Grandfather queries mirror the live discovery logic in ResourceService::getTeamResources().
 * All existing resources are inserted with:
 *   origin      = 'discovered_auto_accepted'
 *   status      = 'active'
 *   risk_status = 'none'
 *   decided_by  = '__migration__'
 *   decided_at  = <now>
 *
 * App assumptions:
 *   - Talk:     spreed installed, table talk_attendees exists.
 *   - Files:    core table oc_share; item_type='folder', share_type=7.
 *   - Calendar: calendar installed, tables dav_shares + calendars exist.
 *   - Deck:     deck installed, table deck_board_acl exists (NC32 / Deck 1.x+).
 *
 * Each app block is individually try/caught so one missing app or schema
 * variance does not abort the entire migration.
 */
class Version000328200Date20260508000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {}

    // -------------------------------------------------------------------------
    // Schema creation
    // -------------------------------------------------------------------------

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('teamhub_team_app_resources')) {
            $output->info('teamhub_team_app_resources already exists — skipping schema creation.');
            return null;
        }

        $table = $schema->createTable('teamhub_team_app_resources');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->addColumn('team_id', Types::STRING, [
            'length'  => 64,
            'notnull' => true,
        ]);
        $table->addColumn('app_id', Types::STRING, [
            'length'  => 32,
            'notnull' => true,
        ]);
        // resource_id stores board IDs, calendar IDs, file_source IDs, or Talk room tokens.
        // Stored as VARCHAR so integer IDs and string tokens can both be represented.
        $table->addColumn('resource_id', Types::STRING, [
            'length'  => 64,
            'notnull' => true,
        ]);
        // origin: teamhub_create | teamhub_connect | discovered_auto_accepted | discovered_pending
        $table->addColumn('origin', Types::STRING, [
            'length'  => 32,
            'notnull' => true,
        ]);
        // status: active | pending | ignored
        $table->addColumn('status', Types::STRING, [
            'length'  => 16,
            'notnull' => true,
            'default' => 'active',
        ]);
        // risk_status: none | owner_disabled | transfer_failed
        $table->addColumn('risk_status', Types::STRING, [
            'length'  => 32,
            'notnull' => true,
            'default' => 'none',
        ]);
        $table->addColumn('display_order', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        // UID of admin who accepted or ignored; '__migration__' for auto-grandfathered rows.
        $table->addColumn('decided_by', Types::STRING, [
            'length'  => 64,
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('decided_at', Types::INTEGER, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('risk_set_at', Types::INTEGER, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::INTEGER, [
            'notnull' => true,
        ]);

        // Explicit PK name required: auto-generated "teamhub_team_app_resources_pkey"
        // is 31 chars, exceeding NC DBAL's 30-char constraint-name limit. This caused
        // migration failures on MariaDB (NC 32.0.9+).
        $table->setPrimaryKey(['id'], 'th_tar_pk');

        // Uniqueness constraint: one row per (team, app, resource).
        $table->addUniqueIndex(['team_id', 'app_id', 'resource_id'], 'th_tar_team_app_res_uniq');

        // Primary query path: fetch active resources for a team + app.
        $table->addIndex(['team_id', 'app_id', 'status'], 'th_tar_team_app_status_idx');

        // Warning aggregation: count at-risk rows across all teams.
        $table->addIndex(['risk_status'], 'th_tar_risk_status_idx');

        return $schema;
    }

    // -------------------------------------------------------------------------
    // Data: grandfather existing resources
    // -------------------------------------------------------------------------

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $now = time();

        // Collect all team IDs from circles_circle.
        $teams = $this->fetchAllTeamIds();
        if (empty($teams)) {
            $output->info('No teams found — nothing to grandfather.');
            return;
        }
        $output->info(sprintf('Grandfathering resources for %d teams…', count($teams)));

        $inserted = 0;
        foreach ($teams as $teamId) {
            $inserted += $this->grandfatherTalk($teamId, $now);
            $inserted += $this->grandfatherFiles($teamId, $now);
            $inserted += $this->grandfatherCalendar($teamId, $now);
            $inserted += $this->grandfatherDeck($teamId, $now);
        }

        $output->info(sprintf('Connected Resources migration complete — %d resource row(s) inserted.', $inserted));
    }

    // -------------------------------------------------------------------------
    // Per-app grandfather helpers
    // -------------------------------------------------------------------------

    /**
     * Talk: find rooms where the circle is an attendee (actor_type='circles').
     * 1:1 — only the first room is taken (invariant preserved until Session C).
     */
    private function grandfatherTalk(string $teamId, int $now): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('a.room_id')
                ->from('talk_attendees', 'a')
                ->where($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('circles')))
                ->andWhere($qb->expr()->eq('a.actor_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                return 0;
            }

            // resource_id for Talk = room token (string), fetched from talk_rooms.
            $roomId = (int) $row['room_id'];
            $roomQb = $this->db->getQueryBuilder();
            $roomQb->select('token')
                ->from('talk_rooms')
                ->where($roomQb->expr()->eq('id', $roomQb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);
            $roomRes = $roomQb->executeQuery();
            $roomRow = $roomRes->fetch();
            $roomRes->closeCursor();

            if (!$roomRow) {
                return 0;
            }

            return $this->insertResourceRow($teamId, 'talk', (string) $roomRow['token'], $now);
        } catch (\Throwable $e) {
            // Table may not exist when spreed is not installed — non-fatal.
            return 0;
        }
    }

    /**
     * Files: find circle folder shares (share_type=7, item_type='folder').
     * resource_id = file_source (int cast to string).
     */
    private function grandfatherFiles(string $teamId, int $now): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_source')
                ->from('share')
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(7, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('item_type', $qb->createNamedParameter('folder')))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                return 0;
            }

            return $this->insertResourceRow($teamId, 'files', (string) $row['file_source'], $now);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Calendar: find calendar shares via dav_shares where principaluri = circles/<teamId>.
     * resource_id = calendar id (int cast to string).
     */
    private function grandfatherCalendar(string $teamId, int $now): int {
        try {
            $principalUri = 'principals/circles/' . $teamId;

            $qb = $this->db->getQueryBuilder();
            $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                return 0;
            }

            return $this->insertResourceRow($teamId, 'calendar', (string) $row['resourceid'], $now);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Deck: find boards where the circle has an ACL entry (type=7 = circle share).
     * Uses deck_board_acl (Deck 1.x / NC32+).
     * resource_id = board_id (int cast to string).
     */
    private function grandfatherDeck(string $teamId, int $now): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('board_id')
                ->from('deck_board_acl')
                ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(7, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                return 0;
            }

            return $this->insertResourceRow($teamId, 'deck', (string) $row['board_id'], $now);
        } catch (\Throwable $e) {
            // deck_board_acl does not exist — Deck not installed.
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Shared insert helper
    // -------------------------------------------------------------------------

    /**
     * Insert a grandfathered resource row. Skips silently on duplicate.
     * Returns 1 on insert, 0 on duplicate skip.
     */
    private function insertResourceRow(
        string $teamId,
        string $appId,
        string $resourceId,
        int    $now
    ): int {
        // Guard against duplicates (idempotent re-run).
        $checkQb = $this->db->getQueryBuilder();
        $checkQb->select($checkQb->func()->count('*', 'cnt'))
            ->from('teamhub_team_app_resources')
            ->where($checkQb->expr()->eq('team_id',     $checkQb->createNamedParameter($teamId)))
            ->andWhere($checkQb->expr()->eq('app_id',     $checkQb->createNamedParameter($appId)))
            ->andWhere($checkQb->expr()->eq('resource_id', $checkQb->createNamedParameter($resourceId)));
        $checkRes = $checkQb->executeQuery();
        $exists   = (int) $checkRes->fetchOne() > 0;
        $checkRes->closeCursor();

        if ($exists) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_team_app_resources')
            ->values([
                'team_id'       => $qb->createNamedParameter($teamId),
                'app_id'        => $qb->createNamedParameter($appId),
                'resource_id'   => $qb->createNamedParameter($resourceId),
                'origin'        => $qb->createNamedParameter('discovered_auto_accepted'),
                'status'        => $qb->createNamedParameter('active'),
                'risk_status'   => $qb->createNamedParameter('none'),
                'display_order' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'decided_by'    => $qb->createNamedParameter('__migration__'),
                'decided_at'    => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'risk_set_at'   => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                'created_at'    => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at'    => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
        return 1;
    }

    // -------------------------------------------------------------------------
    // Team list helper
    // -------------------------------------------------------------------------

    /**
     * Fetch all circle unique_ids from circles_circle.
     * source=1 are user-created circles (teams); we grandfather all of them.
     *
     * @return string[]
     */
    private function fetchAllTeamIds(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id')->from('circles_circle');
            $result = $qb->executeQuery();
            $ids    = [];
            while ($row = $result->fetch()) {
                if (!empty($row['unique_id'])) {
                    $ids[] = (string) $row['unique_id'];
                }
            }
            $result->closeCursor();
            return $ids;
        } catch (\Throwable $e) {
            // circles_circle does not exist — Circles app not installed.
            return [];
        }
    }
}
