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

    public function createDeckBoard(string $teamId, string $teamName, string $uid): array {

        if (!$this->appManager->isInstalled('deck')) {
            return ['error' => 'Deck app not installed'];
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Create board via Deck's ORM (preferred) ───────────────────────────
        $boardId = null;
        try {
            $boardMapper = $this->container->get(\OCA\Deck\Db\BoardMapper::class);
            $board = new \OCA\Deck\Db\Board();
            $board->setTitle($teamName);
            $board->setOwner($uid);
            $board->setColor('0082c9');
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
                'color' => $qb->createNamedParameter('0082c9'),
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
                       ->setValue('type',        $qb->createNamedParameter(7))
                       ->setValue('participant', $qb->createNamedParameter($teamId));

                    // Deck 1.x: separate boolean columns
                    foreach (['permission_read' => 1, 'permission_edit' => 1, 'permission_manage' => 0] as $col => $val) {
                        if (in_array($col, $aclCols, true)) {
                            $qb->setValue($col, $qb->createNamedParameter($val));
                        }
                    }

                    // Deck 2.x: single bitmask column (read=1, edit=2 → 3)
                    if (in_array('permissions', $aclCols, true)) {
                        $qb->setValue('permissions', $qb->createNamedParameter(3));
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


        return ['board_id' => $boardId, 'name' => $teamName, 'circle_added' => $circleAdded];
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
                        ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(7)))
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
     * The previous implementation used a loopback HTTP call to the OCS API which
     * fails on NC28+ when the server resolves to 127.0.0.1 / a private IP, because
     * Nextcloud blocks outgoing requests to local addresses by default.
     */

}
