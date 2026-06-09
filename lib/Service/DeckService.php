<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DeckService — Deck kanban board creation and deletion for TeamHub teams.
 *
 * Extracted from ResourceService in v3.2.0.
 */
class DeckService {

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private DbIntrospectionService $dbIntrospection,
    ) {}

    /**
     * Create a Deck board owned by $uid, shared with the team circle, and with
     * edit permissions set for all circle members.
     *
     * @param string      $teamId   Team / circle unique ID
     * @param string      $teamName Board title
     * @param string      $uid      NC user ID of the team creator (board owner)
     * @param string|null $colour   6-char hex colour without '#' (e.g. '0082c9').
     *                              Defaults to Nextcloud blue when null.
     */
    public function createDeckBoard(string $teamId, string $teamName, string $uid, ?string $colour = null): array {

        if (!$this->appManager->isInstalled('deck')) {
            return ['error' => 'Deck app not installed'];
        }

        $colour = $colour ?? '0082c9';
        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Create board via Deck's ORM (preferred) ───────────────────────────
        $boardId = null;
        try {
            $boardMapper = $this->container->get(\OCA\Deck\Db\BoardMapper::class);
            $board = new \OCA\Deck\Db\Board();
            $board->setTitle($teamName);
            $board->setOwner($uid);
            $board->setColor($colour);
            $board   = $boardMapper->insert($board);
            $boardId = $board->getId();
        } catch (\Throwable $e) {
            $this->logger->warning('[DeckService] Deck BoardMapper failed, using QB fallback', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Fallback: QB insert into deck_boards ──────────────────────────────
        if ($boardId === null) {
            $boardCols = $this->dbIntrospection->getTableColumns('deck_boards');
            $qb = $db->getQueryBuilder();
            $qb->insert('deck_boards')->values([
                'title' => $qb->createNamedParameter($teamName),
                'owner' => $qb->createNamedParameter($uid),
                'color' => $qb->createNamedParameter($colour),
            ]);
            foreach (['archived' => 0, 'deleted_at' => 0, 'last_modified' => time(), 'settings' => ''] as $col => $val) {
                if (in_array($col, $boardCols, true)) {
                    $qb->setValue($col, $qb->createNamedParameter($val));
                }
            }
            $qb->executeStatement();
            $boardId = (int)$db->lastInsertId();
        }

        // ── Create default stacks via QB ──────────────────────────────────────
        $stackCols = $this->dbIntrospection->getTableColumns('deck_stacks');
        foreach (['To do', 'In progress', 'Done'] as $idx => $stackTitle) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->insert('deck_stacks')
                   ->setValue('title',    $qb->createNamedParameter($stackTitle))
                   ->setValue('board_id', $qb->createNamedParameter($boardId));
                foreach (['order' => $idx, 'deleted_at' => 0, 'last_modified' => time()] as $col => $val) {
                    if (in_array($col, $stackCols, true)) {
                        $qb->setValue($col, $qb->createNamedParameter($val));
                    }
                }
                $qb->executeStatement();
            } catch (\Throwable $e) {
                $this->logger->warning('[DeckService] Deck stack insert failed', [
                    'stack' => $stackTitle,
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }
        }

        // ── Share board with circle via Deck's AclMapper (preferred) ─────────
        // type 7 = circle (0=user, 1=group, 7=circle per Deck API docs)
        $circleAdded = false;
        try {
            $aclMapper = $this->container->get(\OCA\Deck\Db\AclMapper::class);
            $acl = new \OCA\Deck\Db\Acl();
            $acl->setBoardId($boardId);
            $acl->setType(7);
            $acl->setParticipant($teamId);

            // Deck 1.x used separate boolean setters; Deck 2.x (NC33+) uses a bitmask.
            // Try both — setters that don't exist will throw, caught below.
            if (method_exists($acl, 'setPermissionRead')) {
                $acl->setPermissionRead(true);
                $acl->setPermissionEdit(true);
                $acl->setPermissionManage(false);
            }
            if (method_exists($acl, 'setPermissions')) {
                // Deck 2.x bitmask: read=1, edit=2, manage=4 → 3 = read+edit
                $acl->setPermissions(3);
            }

            $aclMapper->insert($acl);
            $circleAdded = true;
        } catch (\Throwable $e) {
            $this->logger->warning('[DeckService] Deck AclMapper failed, trying PermissionService', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
                'app'   => Application::APP_ID,
            ]);
        }

        // ── Strategy 2: Deck PermissionService (Deck 2.x / NC33) ─────────────
        // Deck 2.x introduced PermissionService / BoardService::addAcl() as the
        // canonical way to share boards.
        if (!$circleAdded) {
            try {
                // Try BoardService::addAcl if available (Deck 2.x)
                $boardService = $this->container->get(\OCA\Deck\Service\BoardService::class);
                if (method_exists($boardService, 'addAcl')) {
                    $boardService->addAcl($boardId, 7, $teamId, true, true, false);
                    $circleAdded = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[DeckService] Deck BoardService::addAcl failed, trying QB fallback', [
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500),
                    'app'   => Application::APP_ID,
                ]);
            }
        }

        // ── Strategy 3: QB insert into deck_board_acl / deck_acl ─────────────
        // Handles both Deck 1.x (boolean permission columns) and
        // Deck 2.x (single `permissions` bitmask column, value 3 = read+edit).
        if (!$circleAdded) {
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                $aclCols = $this->dbIntrospection->getTableColumns($aclTable);
                if (empty($aclCols)) {
                    continue;
                }

                try {
                    $qb = $db->getQueryBuilder();
                    $qb->insert($aclTable)
                       ->setValue('board_id',    $qb->createNamedParameter($boardId))
                       ->setValue('type',        $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                       ->setValue('participant', $qb->createNamedParameter($teamId));

                    // Deck 1.x: separate boolean columns
                    foreach (['permission_read' => 1, 'permission_edit' => 1, 'permission_manage' => 0] as $col => $val) {
                        if (in_array($col, $aclCols, true)) {
                            $qb->setValue($col, $qb->createNamedParameter($val));
                        }
                    }

                    // Deck 2.x: single bitmask column (read=1, edit=2 → 3)
                    if (in_array('permissions', $aclCols, true)) {
                        $qb->setValue('permissions', $qb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                    }

                    $qb->executeStatement();
                    $circleAdded = true;
                    break;
                } catch (\Throwable $e) {
                    $this->logger->warning('[DeckService] Deck ACL QB insert failed', [
                        'table' => $aclTable,
                        'error' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 500),
                        'app'   => Application::APP_ID,
                    ]);
                }
            }
        }

        if (!$circleAdded) {
            $this->logger->error('[DeckService] Deck: all circle ACL strategies failed', [
                'boardId' => $boardId,
                'teamId'  => $teamId,
                'app'     => Application::APP_ID,
            ]);
        }

        // ── Enforce edit permissions via direct QB UPDATE ─────────────────────
        // All three ACL strategies above attempt to set edit rights, but ORM
        // quirks across Deck versions can cause the permission columns to be
        // written with their default (0 / false) even when the setter was called
        // (e.g. the entity does not mark the field as dirty, or the installed
        // Deck version uses different setter names). This UPDATE is the
        // authoritative write — it runs regardless of which strategy succeeded.
        if ($circleAdded) {
            $this->enforceAclEditPermissions($boardId, $teamId, $db);
        }

        return ['board_id' => $boardId, 'name' => $teamName, 'circle_added' => $circleAdded];
    }

    /**
     * List Deck boards owned by $uid that are eligible to be connected to a team.
     *
     * Excludes archived boards and soft-deleted boards. Caller should pass the
     * current NC user's UID — this method does no auth itself.
     *
     * @return array<int, array{id:int, name:string, color:string}>
     */
    public function listOwnedBoards(string $uid): array {
        if (!$this->appManager->isInstalled('deck')) {
            return [];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $boardCols = $this->dbIntrospection->getTableColumns('deck_boards');

            $qb = $db->getQueryBuilder();
            $qb->select('id', 'title', 'color')
                ->from('deck_boards')
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));

            // Exclude soft-deleted boards if the column exists (it does on all
            // supported Deck versions, but be defensive).
            if (in_array('deleted_at', $boardCols, true)) {
                $qb->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            }
            // Exclude archived boards.
            if (in_array('archived', $boardCols, true)) {
                $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            }

            $qb->orderBy('title', 'ASC');

            $res = $qb->executeQuery();
            $rows = $res->fetchAll();
            $res->closeCursor();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'    => (int)$row['id'],
                    'name'  => (string)($row['title'] ?? ''),
                    'color' => (string)($row['color'] ?? '0082c9'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] listOwnedBoards failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Connect an existing Deck board to a team by inserting a circle ACL row
     * granting the team's circle read+edit access.
     *
     * SECURITY: Caller MUST verify the user has team-admin level. This method
     * additionally verifies that $uid actually owns the board — preventing
     * forged boardId attacks.
     *
     * Refuses to connect if the team already has a board connected.
     *
     * @return array{success:bool, board_id?:int, name?:string, error?:string}
     */
    public function connectExistingBoard(string $teamId, int $boardId, string $uid): array {
        if (!$this->appManager->isInstalled('deck')) {
            return ['success' => false, 'error' => 'Deck app not installed'];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);

            // SECURITY: verify board exists, is owned by user, and not deleted.
            $qb = $db->getQueryBuilder();
            $res = $qb->select('id', 'title')
                ->from('deck_boards')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['success' => false, 'error' => 'Board not found or not owned by user'];
            }

            $boardName = (string)($row['title'] ?? '');;

            // Refuse only if this specific board is already connected to this team.
            // Multiple different boards are allowed (multi-resource model).
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $cols = $this->dbIntrospection->getTableColumns($aclTable);
                    if (empty($cols)) {
                        continue;
                    }
                    $chk = $db->getQueryBuilder();
                    $cres = $chk->select('board_id')
                        ->from($aclTable)
                        ->where($chk->expr()->eq('type', $chk->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->andWhere($chk->expr()->eq('participant', $chk->createNamedParameter($teamId)))
                        ->andWhere($chk->expr()->eq('board_id', $chk->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    $existing = $cres->fetch();
                    $cres->closeCursor();
                    if ($existing) {
                        return ['success' => false, 'error' => 'This board is already connected to this team'];
                    }
                } catch (\Throwable) {
                    // Table absent — try the next one
                }
            }

            // Insert circle ACL — multi-strategy, mirrors createDeckBoard.
            $circleAdded = false;

            // Strategy 1: AclMapper
            try {
                $aclMapper = $this->container->get(\OCA\Deck\Db\AclMapper::class);
                $acl = new \OCA\Deck\Db\Acl();
                $acl->setBoardId($boardId);
                $acl->setType(7);
                $acl->setParticipant($teamId);
                if (method_exists($acl, 'setPermissionRead')) {
                    $acl->setPermissionRead(true);
                    $acl->setPermissionEdit(true);
                    $acl->setPermissionManage(false);
                }
                if (method_exists($acl, 'setPermissions')) {
                    $acl->setPermissions(3);
                }
                $aclMapper->insert($acl);
                $circleAdded = true;
            } catch (\Throwable $e) {
                $this->logger->warning('[DeckService] connectExistingBoard: AclMapper failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            // Strategy 2: BoardService::addAcl (Deck 2.x)
            if (!$circleAdded) {
                try {
                    $boardService = $this->container->get(\OCA\Deck\Service\BoardService::class);
                    if (method_exists($boardService, 'addAcl')) {
                        $boardService->addAcl($boardId, 7, $teamId, true, true, false);
                        $circleAdded = true;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[DeckService] connectExistingBoard: BoardService::addAcl failed', [
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // Strategy 3: QB insert
            if (!$circleAdded) {
                foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                    $aclCols = $this->dbIntrospection->getTableColumns($aclTable);
                    if (empty($aclCols)) {
                        continue;
                    }
                    try {
                        $iqb = $db->getQueryBuilder();
                        $iqb->insert($aclTable)
                            ->setValue('board_id',    $iqb->createNamedParameter($boardId))
                            ->setValue('type',        $iqb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                            ->setValue('participant', $iqb->createNamedParameter($teamId));
                        foreach (['permission_read' => 1, 'permission_edit' => 1, 'permission_manage' => 0] as $col => $val) {
                            if (in_array($col, $aclCols, true)) {
                                $iqb->setValue($col, $iqb->createNamedParameter($val));
                            }
                        }
                        if (in_array('permissions', $aclCols, true)) {
                            $iqb->setValue('permissions', $iqb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                        }
                        $iqb->executeStatement();
                        $circleAdded = true;
                        break;
                    } catch (\Throwable $e) {
                        $this->logger->warning('[DeckService] connectExistingBoard: QB insert failed', [
                            'table' => $aclTable, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                }
            }

            if (!$circleAdded) {
                return ['success' => false, 'error' => 'Failed to grant team access to board'];
            }

            // Belt-and-braces: ensure edit permission flag is actually set.
            $this->enforceAclEditPermissions($boardId, $teamId, $db);


            return [
                'success'  => true,
                'board_id' => $boardId,
                'name'     => $boardName,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] connectExistingBoard failed', [
                'teamId' => $teamId, 'boardId' => $boardId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['success' => false, 'error' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Directly UPDATE the ACL row(s) for this board+circle to ensure edit
     * permission is granted, independent of ORM behaviour.
     *
     * Handles both Deck 1.x (separate boolean columns) and Deck 2.x
     * (single `permissions` bitmask, value 3 = read|edit):
     *
     *   Deck 1.x columns: permission_read, permission_edit, permission_manage
     *   Deck 2.x column:  permissions  (bitmask — PERMISSION_READ=1, PERMISSION_EDIT=2)
     *
     * Each column is set in its own try/catch so a missing column in one schema
     * version does not prevent the other from being written.
     *
     * @param int    $boardId The board ID just created
     * @param string $teamId  The circle/team unique ID used as the ACL participant
     * @param \OCP\IDBConnection $db Active DB connection
     */
    /**
     * Ensure edit permission is set on the circle ACL row for this board.
     *
     * DB confirmed schema (Deck 1.x / NC32):
     *   oc_deck_board_acl: id, board_id, type, participant,
     *                      permission_edit, permission_share, permission_manage
     *
     * We fire one targeted UPDATE per permission column, each independently
     * try/caught. No column detection: if a column doesn't exist the DB
     * throws and we catch it silently. This is more reliable than introspection
     * because it avoids cache/prefix bugs that caused a single combined UPDATE
     * to build with no SET clause and fail silently.
     *
     * Covers both Deck 1.x (individual boolean columns) and the hypothetical
     * Deck 2.x bitmask column (`permissions`), whichever is present.
     */
    private function enforceAclEditPermissions(int $boardId, string $teamId, \OCP\IDBConnection $db): void {
        foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {

            // Deck 1.x individual boolean columns — one UPDATE per column so a
            // missing column in one schema version never blocks the others.
            foreach (['permission_edit' => 1, 'permission_share' => 0, 'permission_manage' => 0] as $col => $val) {
                try {
                    $qb = $db->getQueryBuilder();
                    $qb->update($aclTable)
                       ->set($col, $qb->createNamedParameter($val, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                       ->where($qb->expr()->eq('board_id',    $qb->createNamedParameter($boardId,  \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                       ->andWhere($qb->expr()->eq('type',        $qb->createNamedParameter(7,        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                       ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)));
                    $qb->executeStatement();
                } catch (\Throwable $e) {
                    // Column doesn't exist in this Deck version — not an error.
                }
            }

            // Deck 2.x bitmask column — PERMISSION_READ(1) | PERMISSION_EDIT(2) = 3
            try {
                $qb = $db->getQueryBuilder();
                $qb->update($aclTable)
                   ->set('permissions', $qb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                   ->where($qb->expr()->eq('board_id',    $qb->createNamedParameter($boardId,  \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                   ->andWhere($qb->expr()->eq('type',        $qb->createNamedParameter(7,        \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                   ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // Column doesn't exist in this Deck version — not an error.
            }
        }
    }

    // -------------------------------------------------------------------------
    // DB schema introspection utility
    // -------------------------------------------------------------------------

    /**
     * Return the column names for an un-prefixed table name.
     *
     * Used internally (Deck QB fallbacks) and by MemberService (requestJoinTeam,
     * repairCircleMembership). Three strategies in descending preference:
     *
     *   1. DBAL SchemaManager via inner Doctrine connection (NC32 compatible)
     *   2. SELECT * LIMIT 1 — column names from result set metadata
     *   3. INFORMATION_SCHEMA query (MySQL/MariaDB/PostgreSQL fallback)
     *
     * Returns an empty array when the table doesn't exist — callers skip
     * optional columns gracefully.
     *
     * @param string $table Un-prefixed table name, e.g. 'circles_member'
     * @return string[]
     */
    /**
     * Suspend team access to the Deck board by removing the circle ACL row.
     * The board, stacks, and cards all remain intact.
     *
     * Returns {board_id, acl_table, type} for resume, or null if not found.
     *
     * @return array{board_id: int, acl_table: string, type: int}|null
     */
    public function suspendDeckAccess(string $teamId, \OCP\IDBConnection $db): ?array {
        if (!$this->appManager->isInstalled('deck')) {
            return null;
        }
        try {
            $boardId  = null;
            $aclTable = null;

            foreach (['deck_board_acl', 'deck_acl'] as $tbl) {
                try {
                    $qb  = $db->getQueryBuilder();
                    $res = $qb->select('board_id')
                        ->from($tbl)
                        ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                        ->andWhere($qb->expr()->eq('type',        $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    $row = $res->fetch();
                    $res->closeCursor();
                    if ($row) {
                        $boardId  = (int)$row['board_id'];
                        $aclTable = $tbl;
                        break;
                    }
                } catch (\Throwable) {}
            }

            if ($boardId === null || $aclTable === null) {
                return null;
            }

            // Remove only the circle ACL row — owner and user ACL rows stay.
            $dqb = $db->getQueryBuilder();
            $dqb->delete($aclTable)
                ->where($dqb->expr()->eq('board_id',    $dqb->createNamedParameter($boardId)))
                ->andWhere($dqb->expr()->eq('participant', $dqb->createNamedParameter($teamId)))
                ->andWhere($dqb->expr()->eq('type',        $dqb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->debug('[DeckService] suspendDeckAccess: circle ACL row removed', [
                'teamId' => $teamId, 'boardId' => $boardId, 'table' => $aclTable,
                'app' => Application::APP_ID,
            ]);

            return [
                'board_id'  => $boardId,
                'acl_table' => $aclTable,
                'type'      => 7,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] suspendDeckAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Resume team access to the Deck board by re-inserting the circle ACL row.
     * Idempotent — skips insert if the row already exists.
     *
     * Uses dbIntrospection to detect which permission columns exist, matching
     * the creation pattern in createDeckBoard(). Handles both:
     *   Deck 1.x: permission_edit, permission_share, permission_manage (boolean cols)
     *   Deck 2.x: permissions bitmask (read=1 | edit=2 = 3)
     */
    public function resumeDeckAccess(
        int $boardId,
        string $teamId,
        string $aclTable,
        \OCP\IDBConnection $db
    ): bool {
        if (!$this->appManager->isInstalled('deck')) {
            return false;
        }
        try {
            // Idempotency check.
            $chk  = $db->getQueryBuilder();
            $cres = $chk->select($chk->func()->count('*', 'cnt'))
                ->from($aclTable)
                ->where($chk->expr()->eq('board_id',    $chk->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($chk->expr()->eq('participant', $chk->createNamedParameter($teamId)))
                ->andWhere($chk->expr()->eq('type',        $chk->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $exists = (int)$cres->fetchOne() > 0;
            $cres->closeCursor();

            if ($exists) {
                return true;
            }

            // Detect actual columns so we match the Deck version on this instance.
            $columns = $this->dbIntrospection->getTableColumns($aclTable);
            if (empty($columns)) {
                // Table doesn't exist — try the other ACL table name.
                $fallback = ($aclTable === 'deck_board_acl') ? 'deck_acl' : 'deck_board_acl';
                $columns  = $this->dbIntrospection->getTableColumns($fallback);
                if (!empty($columns)) {
                    $aclTable = $fallback;
                }
            }

            $iqb = $db->getQueryBuilder();
            $iqb->insert($aclTable)
                ->setValue('board_id',    $iqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->setValue('type',        $iqb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->setValue('participant', $iqb->createNamedParameter($teamId));

            // Deck 1.x individual boolean permission columns.
            // permission_edit=1, permission_share=0, permission_manage=0
            // matches the original circle ACL row created by createDeckBoard().
            foreach ([
                'permission_read'   => 1,
                'permission_edit'   => 1,
                'permission_share'  => 0,
                'permission_manage' => 0,
            ] as $col => $val) {
                if (in_array($col, $columns, true)) {
                    $iqb->setValue($col, $iqb->createNamedParameter($val, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                }
            }

            // Deck 2.x single bitmask column: PERMISSION_READ(1) | PERMISSION_EDIT(2) = 3.
            if (in_array('permissions', $columns, true)) {
                $iqb->setValue('permissions', $iqb->createNamedParameter(3, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
            }

            // owner column present in some Deck versions — always 0 for circle ACL rows.
            if (in_array('owner', $columns, true)) {
                $iqb->setValue('owner', $iqb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
            }

            $iqb->executeStatement();

            // Run the same post-insert permission enforcement used on creation,
            // which fires individual UPDATEs per column to handle any column
            // that wasn't set cleanly by the INSERT.
            $this->enforceAclEditPermissions($boardId, $teamId, $db);

            $this->logger->debug('[DeckService] resumeDeckAccess: circle ACL row re-inserted', [
                'teamId' => $teamId, 'boardId' => $boardId, 'table' => $aclTable,
                'app' => Application::APP_ID,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] resumeDeckAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Remove the team's circle access from a specific Deck board (by board ID).
     * Deletes only the circle ACL row — owner and user ACL rows are untouched.
     * The board itself and its cards are preserved.
     */
    public function removeBoardAccess(string $teamId, int $boardId, \OCP\IDBConnection $db): bool {
        foreach (['deck_board_acl', 'deck_acl'] as $table) {
            try {
                $qb       = $db->getQueryBuilder();
                $affected = $qb->delete($table)
                    ->where($qb->expr()->eq('board_id',    $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                    ->andWhere($qb->expr()->eq('type',        $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                if ($affected > 0) {
                    $this->logger->debug('[DeckService] removeBoardAccess: circle ACL row removed', [
                        'teamId' => $teamId, 'boardId' => $boardId,
                        'table' => $table, 'app' => Application::APP_ID,
                    ]);
                    return true;
                }
            } catch (\Throwable) {
                // Table may not exist on this Deck version — try the next one.
            }
        }
        $this->logger->warning('[DeckService] removeBoardAccess: no ACL row found', [
            'teamId' => $teamId, 'boardId' => $boardId, 'app' => Application::APP_ID,
        ]);
        return false;
    }

    /**
     * Delete a specific board by ID (multi-resource-aware).
     * Delegates to the existing deleteDeckBoard logic after confirming the board exists.
     */
    public function deleteBoardById(int $boardId, \OCP\IDBConnection $db): array {
        // Verify the board exists first.
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('id')->from('deck_boards')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            return ['deleted' => false, 'detail' => "Board {$boardId} not found"];
        }

        // Reuse the full delete logic via a synthetic teamId lookup —
        // instead, inline the delete sequence directly using the known boardId.
        return $this->deleteBoardByIdInternal($boardId, $db);
    }

    private function deleteBoardByIdInternal(int $boardId, \OCP\IDBConnection $db): array {
        try {
            // Delete cards for each stack on this board.
            $sqb  = $db->getQueryBuilder();
            $sres = $sqb->select('id')->from('deck_stacks')
                ->where($sqb->expr()->eq('board_id', $sqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            while ($srow = $sres->fetch()) {
                $stackId = (int)$srow['id'];
                try {
                    $cqb = $db->getQueryBuilder();
                    $cqb->delete('deck_cards')
                        ->where($cqb->expr()->eq('stack_id', $cqb->createNamedParameter($stackId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                } catch (\Throwable) {}
            }
            $sres->closeCursor();

            // Delete stacks.
            $stqb = $db->getQueryBuilder();
            $stqb->delete('deck_stacks')
                ->where($stqb->expr()->eq('board_id', $stqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            // Delete ACL rows (both possible tables).
            foreach (['deck_board_acl', 'deck_acl'] as $tbl) {
                try {
                    $aqb = $db->getQueryBuilder();
                    $aqb->delete($tbl)
                        ->where($aqb->expr()->eq('board_id', $aqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->executeStatement();
                } catch (\Throwable) {}
            }

            // Delete the board itself.
            $bqb = $db->getQueryBuilder();
            $bqb->delete('deck_boards')
                ->where($bqb->expr()->eq('id', $bqb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->info('[DeckService] deleteBoardById: board deleted', [
                'boardId' => $boardId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'board_id' => $boardId];
        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] deleteBoardById failed', [
                'boardId' => $boardId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => $e->getMessage()];
        }
    }

    public function deleteDeckBoard(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find board_id via the circle ACL row (type=7 = circle)
            $boardId = null;
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $qb = $db->getQueryBuilder();
                    $res = $qb->select('board_id')
                        ->from($aclTable)
                        ->where($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                        ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                        ->setMaxResults(1)
                        ->executeQuery();
                    $row = $res->fetch();
                    $res->closeCursor();
                    if ($row) {
                        $boardId = (int)$row['board_id'];
                        break;
                    }
                } catch (\Throwable $e) {
                    // Table doesn't exist — try next
                }
            }

            if ($boardId === null) {
                return ['deleted' => false, 'detail' => 'No Deck board found for this team'];
            }

            // Delete in dependency order: cards → stacks → ACL → board
            foreach ([
                ['deck_cards',     'stack_id',  'deck_stacks', 'board_id', $boardId],
            ] as [$cardTbl, $cardCol, $stackTbl, $stackBoardCol, $bid]) {
                // Get stack IDs for this board, then delete their cards
                try {
                    $sqb = $db->getQueryBuilder();
                    $sres = $sqb->select('id')->from($stackTbl)
                        ->where($sqb->expr()->eq($stackBoardCol, $sqb->createNamedParameter($bid)))
                        ->executeQuery();
                    while ($srow = $sres->fetch()) {
                        $stackId = (int)$srow['id'];
                        $cqb = $db->getQueryBuilder();
                        $cqb->delete($cardTbl)
                            ->where($cqb->expr()->eq($cardCol, $cqb->createNamedParameter($stackId)))
                            ->executeStatement();
                    }
                    $sres->closeCursor();
                } catch (\Throwable $e) {
                    $this->logger->warning('[DeckService] deleteDeckBoard: card delete failed', [
                        'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // Delete stacks
            foreach (['deck_stacks'] as $tbl) {
                try {
                    $dqb = $db->getQueryBuilder();
                    $dqb->delete($tbl)
                        ->where($dqb->expr()->eq('board_id', $dqb->createNamedParameter($boardId)))
                        ->executeStatement();
                } catch (\Throwable $e) {
                    $this->logger->warning('[DeckService] deleteDeckBoard: stack delete failed', [
                        'table' => $tbl, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }

            // Delete ACL rows
            foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
                try {
                    $aqb = $db->getQueryBuilder();
                    $aqb->delete($aclTable)
                        ->where($aqb->expr()->eq('board_id', $aqb->createNamedParameter($boardId)))
                        ->executeStatement();
                } catch (\Throwable $e) {
                    // Table may not exist — not an error
                }
            }

            // Delete the board itself
            $bqb = $db->getQueryBuilder();
            $bqb->delete('deck_boards')
                ->where($bqb->expr()->eq('id', $bqb->createNamedParameter($boardId)))
                ->executeStatement();

            return ['deleted' => true, 'detail' => "Deck board {$boardId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[DeckService] deleteDeckBoard failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }


    /**
     * Create a Talk group room and add the circle as participant.
     *
     * Strategy (in order):
     *   1. Talk RoomService PHP API — cleanest, no HTTP involved.
     *   2. Talk Manager PHP API    — older Talk versions.
     *   3. Direct DB insert        — version-stable last resort; never triggers
     *                                Nextcloud local-access-rules blocks.
     *
    /**
     * The previous implementation used a loopback HTTP call to the OCS API which
     * fails on NC28+ when the server resolves to 127.0.0.1 / a private IP, because
     * Nextcloud blocks outgoing requests to local addresses by default.
     */

    /**
     * Fetch display metadata for a set of Deck card IDs in a single query.
     *
     * Used by the Decisions module to render "linked tasks" for a decision.
     *
     * Result shape, keyed by card id:
     *   [
     *     <cardId> => [
     *       'id'          => int,
     *       'title'       => string,
     *       'archived'    => bool,
     *       'deletedAt'   => int (0 if not deleted),
     *       'stackTitle'  => string,
     *       'boardId'     => int,
     *       'boardTitle'  => string,
     *       'boardColor'  => string,    // 6-char hex, no '#'
     *       'url'         => string,    // /apps/deck/board/<boardId>/card/<cardId>
     *     ]
     *   ]
     *
     * Cards that do not exist are simply absent from the result.
     *
     * ACL note (v1): we do not filter by per-user ACL here. The link table
     * only contains cards a user was permitted to link in the first place;
     * showing the title back to them later is harmless metadata. If they have
     * since lost access, Deck itself enforces ACL when they click through.
     *
     * @param int[] $cardIds
     * @return array<int, array<string, mixed>>
     */
    public function getCardsByIds(array $cardIds): array {
        if (empty($cardIds)) {
            return [];
        }
        if (!$this->appManager->isInstalled('deck')) {
            return [];
        }

        $db = $this->container->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();

        // JOIN deck_cards → deck_stacks → deck_boards.
        // We use createFunction for the table aliases since QB's join() handles
        // aliases inconsistently across DBAL versions; explicit aliases here
        // keep the SELECT list unambiguous on both MySQL and PostgreSQL.
        $qb->select(
            'c.id AS card_id',
            'c.title AS card_title',
            'c.archived AS card_archived',
            'c.deleted_at AS card_deleted_at',
            's.title AS stack_title',
            'b.id AS board_id',
            'b.title AS board_title',
            'b.color AS board_color',
        )
            ->from('deck_cards', 'c')
            ->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
            ->innerJoin('s', 'deck_boards', 'b', $qb->expr()->eq('s.board_id', 'b.id'))
            ->where($qb->expr()->in(
                'c.id',
                $qb->createNamedParameter($cardIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $out = [];
        try {
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $id = (int)$row['card_id'];
                $boardId = (int)$row['board_id'];
                $out[$id] = [
                    'id'         => $id,
                    'title'      => (string)$row['card_title'],
                    'archived'   => (bool)$row['card_archived'],
                    'deletedAt'  => (int)($row['card_deleted_at'] ?? 0),
                    'stackTitle' => (string)$row['stack_title'],
                    'boardId'    => $boardId,
                    'boardTitle' => (string)$row['board_title'],
                    'boardColor' => (string)($row['board_color'] ?? '0082c9'),
                    'url'        => '/apps/deck/board/' . $boardId . '/card/' . $id,
                ];
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[DeckService] getCardsByIds failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }
        return $out;
    }

    /**
     * Check whether a Deck card exists at all (regardless of ACL).
     * Used to validate linkTask() — we refuse to link non-existent cards.
     */
    public function cardExists(int $cardId): bool {
        if (!$this->appManager->isInstalled('deck')) {
            return false;
        }
        $db = $this->container->get(\OCP\IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->select('id')
            ->from('deck_cards')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            $r = $qb->executeQuery();
            $found = $r->fetch();
            $r->closeCursor();
            return $found !== false;
        } catch (\Throwable) {
            return false;
        }
    }

}
