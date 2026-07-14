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
        // v3.99.4 — lanes → decisions categories. DecisionCategoryService
        // and DecisionTeamConfigMapper have narrow deps (no MemberService
        // transitively, no ResourceService), so injecting them here
        // doesn't reintroduce the MemberService → ResourceService →
        // DeckService cycle we already fixed once.
        private DecisionCategoryService     $decisionCategoryService,
        private \OCA\TeamHub\Db\DecisionTeamConfigMapper $decisionTeamConfigMapper,
        private \OCP\IConfig $config,
    ) {}

    /**
     * v3.98.2 — create a new Deck stack on the team's board. Called from the
     * ProjectSwimlanesModal (Planning-phase "Define workstreams" activity)
     * so admins can add lanes as they're known, without being forced to
     * provide them upfront at team creation.
     *
     * AUTH: the caller (TeamController::createDeckStack) enforces admin
     * level via MemberService::requireAdminLevel. We deliberately do NOT
     * inject MemberService here because MemberService transitively depends
     * on ResourceService, which depends on DeckService — the cycle blows
     * up NC's DI container at first construct. Same pattern the other
     * DeckService methods already follow (createDeckBoard, deleteDeckBoard,
     * etc.): controller gates, service does the work.
     *
     * Resolves the team's board via `deck_board_acl` / `deck_acl` (same
     * fallback pattern used by TimelineService). If more than one board is
     * shared with the team the FIRST is used — Advanced projects always
     * have exactly one team-board, so this branch is effectively picking
     * the only candidate for the case that matters.
     *
     * Order is computed as MAX(existing order) + 1 for that board so new
     * stacks always land at the right edge in Deck.
     *
     * @return array{stackId:int, title:string, boardId:int, order:int}|array{error:string}
     */
    public function createStackOnTeamBoard(string $teamId, string $title): array {
        if (!$this->appManager->isInstalled('deck')) {
            return ['error' => 'Deck app not installed'];
        }

        $title = trim($title);
        if ($title === '') {
            return ['error' => 'title is required'];
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Resolve the team's board — registry first, ACL fallback ──────
        $boardId = null;
        foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->select('board_id')
                    ->from($aclTable)
                    ->where($qb->expr()->eq('type', $qb->createNamedParameter(7, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($teamId)))
                    ->setMaxResults(1);
                $res = $qb->executeQuery();
                $row = $res->fetch();
                $res->closeCursor();
                if ($row && !empty($row['board_id'])) {
                    $boardId = (int)$row['board_id'];
                    break;
                }
            } catch (\Throwable $e) {
                // Table absent on this install — try the next one.
            }
        }

        if ($boardId === null) {
            return ['error' => 'No Deck board is shared with this team'];
        }

        // ── Compute next order value on this board ──────────────────────
        // `order` is a reserved word in every supported RDBMS. NC's
        // QueryBuilder auto-quotes identifiers via helpQuote() so the
        // string `'order'` passed here becomes `` `order` `` on MySQL /
        // `"order"` on Postgres in the emitted SQL. We still compute
        // MAX in PHP (rather than SQL-side MAX(`order`)) because
        // aggregate expressions do NOT go through helpQuote() and would
        // need explicit `->createFunction()` wrapping per platform.
        // Same rationale as BackfillDeckStackOrder.
        $nextOrder = 0;
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('order')
                ->from('deck_stacks')
                ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNotNull('order'));
            $res = $qb->executeQuery();
            $max = -1;
            while ($r = $res->fetch()) {
                $v = $r['order'] ?? null;
                if (is_numeric($v) && (int)$v > $max) $max = (int)$v;
            }
            $res->closeCursor();
            $nextOrder = $max + 1;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][DeckService] createStackOnTeamBoard order lookup failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }

        // ── Insert via Deck's StackMapper (preferred) ────────────────────
        $stackId = null;
        try {
            $stackMapper = $this->container->get(\OCA\Deck\Db\StackMapper::class);
            $stack = new \OCA\Deck\Db\Stack();
            $stack->setTitle($title);
            $stack->setBoardId($boardId);
            $stack->setOrder($nextOrder);
            $stack = $stackMapper->insert($stack);
            $stackId = (int)$stack->getId();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][DeckService] StackMapper insert failed, using QB fallback: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }

        // ── Fallback: direct QB insert (same shape createDeckBoard uses) ─
        if ($stackId === null) {
            try {
                $stackCols = $this->dbIntrospection->getTableColumns('deck_stacks');
                $qb = $db->getQueryBuilder();
                $qb->insert('deck_stacks')
                    ->setValue('title',    $qb->createNamedParameter($title))
                    ->setValue('board_id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('order',    $qb->createNamedParameter($nextOrder, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('last_modified', $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                if (in_array('deleted_at', $stackCols, true)) {
                    $qb->setValue('deleted_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                }
                $qb->executeStatement();
                $stackId = (int)$db->lastInsertId();
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DeckService] createStackOnTeamBoard fallback insert failed: ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                ]);
                return ['error' => 'Failed to create stack'];
            }
        }

        // v3.99.1 — bump the parent board's last_modified so Deck's UI
        // cache invalidates and the new stack appears on next refresh.
        // Deck's frontend keys off board.last_modified when deciding
        // whether to refetch the stack list; without this bump, an
        // unchanged board stamp meant the newly inserted stack stayed
        // invisible until an unrelated write (or a hard reload) forced
        // a resync.
        //
        // apps.md W-7 note: not an OCP-gap workaround — Deck's cache
        // contract is the column value, not a method call. `BoardService::update`
        // does the same UPDATE but also fires BoardUpdatedEvent, which
        // triggers a spurious "user renamed board" activity entry — wrong
        // for a stack-insert. Raw UPDATE is the correct pattern here.
        try {
            $qb = $db->getQueryBuilder();
            $qb->update('deck_boards')
                ->set('last_modified', $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        } catch (\Throwable $e) {
            // Non-fatal — the stack is already saved. Log for diagnosis
            // if the UI-refresh regression comes back.
            $this->logger->debug('[TeamHub][DeckService] createStackOnTeamBoard: bumping board.last_modified failed: ' . $e->getMessage(), [
                'boardId' => $boardId,
                'app'     => Application::APP_ID,
            ]);
        }

        // v3.99.4 — mirror the lane title into a Decisions category so
        // proposals about this workstream can be filed there. Only fires
        // when Decisions is enabled globally AND for this team. Non-fatal:
        // the stack write already succeeded; a category failure just
        // means the admin has to create it manually later.
        $this->maybeSeedCategoryForStack($teamId, $title);

        $this->logger->info('[TeamHub][DeckService] createStackOnTeamBoard', [
            'teamId'  => $teamId,
            'boardId' => $boardId,
            'stackId' => $stackId,
            'title'   => $title,
            'order'   => $nextOrder,
            'app'     => Application::APP_ID,
        ]);

        return [
            'stackId' => $stackId,
            'title'   => $title,
            'boardId' => $boardId,
            'order'   => $nextOrder,
        ];
    }

    /**
     * v3.99.4 — best-effort mirror of a new Deck lane into a Decisions
     * category. Silent skip when:
     *   - Decisions module isn't globally enabled
     *   - Decisions isn't enabled for this team
     *   - A category with the same name already exists (createCategory
     *     throws InvalidArgumentException on duplicate — swallowed)
     *   - Any other failure — the primary stack write is done, we just
     *     lose the mirror
     */
    private function maybeSeedCategoryForStack(string $teamId, string $title): void {
        try {
            $globalEnabled = $this->config->getAppValue(Application::APP_ID, 'decisions_module_enabled', '1') === '1';
            if (!$globalEnabled) {
                return;
            }
            $teamRow = $this->decisionTeamConfigMapper->findByTeam($teamId);
            if ($teamRow === null || $teamRow->getDecisionsEnabled() !== 1) {
                return;
            }

            $uid = $this->userSession->getUser()?->getUID() ?? '';
            if ($uid === '') {
                return;
            }

            $this->decisionCategoryService->createCategory($teamId, $title, $uid);
            $this->logger->info('[TeamHub][DeckService] createStackOnTeamBoard: mirrored lane into Decisions category', [
                'teamId' => $teamId, 'title' => $title, 'app' => Application::APP_ID,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Duplicate — no-op.
            $this->logger->debug('[TeamHub][DeckService] createStackOnTeamBoard: category mirror skipped (duplicate): ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DeckService] createStackOnTeamBoard: category mirror failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Create a Deck board owned by $uid, shared with the team circle, and with
     * edit permissions set for all circle members.
     *
     * @param string      $teamId      Team / circle unique ID
     * @param string      $teamName    Board title
     * @param string      $uid         NC user ID of the team creator (board owner)
     * @param string|null $colour      6-char hex colour without '#' (e.g. '0082c9').
     *                                 Defaults to Nextcloud blue when null.
     * @param string|null $projectMode Project Teams (v3.90.x) — when 'advanced', a
     *                                 4th "Project management" stack is added with 4
     *                                 starter cards, each due 7 days out and assigned
     *                                 to $uid, to give a rolling start. Any other value
     *                                 (null or 'basic') keeps today's 3-stack, no-card
     *                                 behaviour exactly — no regression.
     */
    public function createDeckBoard(string $teamId, string $teamName, string $uid, ?string $colour = null, ?string $projectMode = null): array {

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
            $this->logger->warning('[TeamHub][DeckService] Deck BoardMapper failed, using QB fallback', [
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

        // ── Default stacks (Basic teams only) ────────────────────────────
        // v3.98.2 — Advanced projects no longer receive the To do / In
        // progress / Done starter stacks. The Compass "Define workstreams"
        // Planning-phase activity walks admins through defining their real
        // project lanes via ProjectSwimlanesModal; forcing a set of default
        // stacks upfront was the exact wrong model for a lifecycle-driven
        // project. Basic teams (no lifecycle,
        // no Compass) keep the classic Deck defaults so nothing regresses
        // for them.
        //
        // Reuses Deck's existing translation catalogue for the three
        // strings — they've been in Deck since v1.0 — rather than
        // duplicating them into TeamHub's PO files.
        if ($projectMode !== 'advanced') {
            $stackTitles = $this->translateDefaultStackTitles($uid);

            // ── Create default stacks via Deck's StackMapper (preferred) ─
            $stacksCreated = false;
            try {
                $stackMapper = $this->container->get(\OCA\Deck\Db\StackMapper::class);
                foreach ($stackTitles as $idx => $stackTitle) {
                    $stack = new \OCA\Deck\Db\Stack();
                    $stack->setTitle($stackTitle);
                    $stack->setBoardId($boardId);
                    $stack->setOrder($idx);
                    $stackMapper->insert($stack);
                }
                $stacksCreated = true;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][DeckService] Deck StackMapper failed, using QB fallback', [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ]);
            }

            // ── Fallback: QB insert into deck_stacks ─────────────────────
            if (!$stacksCreated) {
                $stackCols = $this->dbIntrospection->getTableColumns('deck_stacks');
                foreach ($stackTitles as $idx => $stackTitle) {
                    try {
                        $qb = $db->getQueryBuilder();
                        $qb->insert('deck_stacks')
                           ->setValue('title',    $qb->createNamedParameter($stackTitle))
                           ->setValue('board_id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                           ->setValue('order',    $qb->createNamedParameter($idx, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                           ->setValue('last_modified', $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                        if (in_array('deleted_at', $stackCols, true)) {
                            $qb->setValue('deleted_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
                        }
                        $qb->executeStatement();
                    } catch (\Throwable $e) {
                        $this->logger->warning('[TeamHub][DeckService] Deck stack insert failed', [
                            'stack' => $stackTitle,
                            'error' => $e->getMessage(),
                            'app'   => Application::APP_ID,
                        ]);
                    }
                }
            }
        }

        // ── Project Teams (v3.90.x, revised 3.98.2) — "Project management"
        //    stack + starter cards for Advanced projects. Now the ONLY
        //    starter stack for Advanced projects; it lands at order=0
        //    since no default stacks precede it. Failure here never aborts
        //    board creation — a missing starter stack is recoverable, an
        //    unshared/unusable board is not.
        if ($projectMode === 'advanced') {
            $this->seedProjectManagementStack($boardId, 0, $uid, $db);
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
            $this->logger->warning('[TeamHub][DeckService] Deck AclMapper failed, trying PermissionService', [
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
                $this->logger->warning('[TeamHub][DeckService] Deck BoardService::addAcl failed, trying QB fallback', [
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
                    $this->logger->warning('[TeamHub][DeckService] Deck ACL QB insert failed', [
                        'table' => $aclTable,
                        'error' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 500),
                        'app'   => Application::APP_ID,
                    ]);
                }
            }
        }

        if (!$circleAdded) {
            $this->logger->error('[TeamHub][DeckService] Deck: all circle ACL strategies failed', [
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
            $this->logger->error('[TeamHub][DeckService] listOwnedBoards failed', [
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
                $this->logger->warning('[TeamHub][DeckService] connectExistingBoard: AclMapper failed', [
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
                    $this->logger->warning('[TeamHub][DeckService] connectExistingBoard: BoardService::addAcl failed', [
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
                        $this->logger->warning('[TeamHub][DeckService] connectExistingBoard: QB insert failed', [
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
            $this->logger->error('[TeamHub][DeckService] connectExistingBoard failed', [
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

            $this->logger->debug('[TeamHub][DeckService] suspendDeckAccess: circle ACL row removed', [
                'teamId' => $teamId, 'boardId' => $boardId, 'table' => $aclTable,
                'app' => Application::APP_ID,
            ]);

            return [
                'board_id'  => $boardId,
                'acl_table' => $aclTable,
                'type'      => 7,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][DeckService] suspendDeckAccess failed', [
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

            $this->logger->debug('[TeamHub][DeckService] resumeDeckAccess: circle ACL row re-inserted', [
                'teamId' => $teamId, 'boardId' => $boardId, 'table' => $aclTable,
                'app' => Application::APP_ID,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][DeckService] resumeDeckAccess failed', [
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
                    $this->logger->debug('[TeamHub][DeckService] removeBoardAccess: circle ACL row removed', [
                        'teamId' => $teamId, 'boardId' => $boardId,
                        'table' => $table, 'app' => Application::APP_ID,
                    ]);
                    return true;
                }
            } catch (\Throwable) {
                // Table may not exist on this Deck version — try the next one.
            }
        }
        $this->logger->warning('[TeamHub][DeckService] removeBoardAccess: no ACL row found', [
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

            $this->logger->info('[TeamHub][DeckService] deleteBoardById: board deleted', [
                'boardId' => $boardId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'board_id' => $boardId];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][DeckService] deleteBoardById failed', [
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
                    $this->logger->warning('[TeamHub][DeckService] deleteDeckBoard: card delete failed', [
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
                    $this->logger->warning('[TeamHub][DeckService] deleteDeckBoard: stack delete failed', [
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
            $this->logger->error('[TeamHub][DeckService] deleteDeckBoard failed', [
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

        // Detect whether deck_stacks has a done_status column (added in Deck ~1.9).
        // We use this for accurate "done" detection; fall back to title heuristic otherwise.
        $stackCols    = $this->dbIntrospection->getTableColumns('deck_stacks');
        $hasDoneStatus = in_array('done_status', $stackCols, true);

        $qb = $db->getQueryBuilder();

        // JOIN deck_cards → deck_stacks → deck_boards.
        $selectCols = [
            'c.id AS card_id',
            'c.title AS card_title',
            'c.archived AS card_archived',
            'c.deleted_at AS card_deleted_at',
            's.title AS stack_title',
            'b.id AS board_id',
            'b.title AS board_title',
            'b.color AS board_color',
        ];
        if ($hasDoneStatus) {
            $selectCols[] = 's.done_status AS stack_done_status';
        }

        $qb->select(...$selectCols)
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
                $id      = (int)$row['card_id'];
                $boardId = (int)$row['board_id'];
                $archived = (bool)$row['card_archived'];

                // Determine "done" status:
                // 1. done_status column (preferred — explicit Deck done flag).
                // 2. Stack title match against "done" (case-insensitive fallback).
                // 3. Archived cards are always considered done.
                if ($hasDoneStatus) {
                    $isDone = $archived || (int)($row['stack_done_status'] ?? 0) === 1;
                } else {
                    $isDone = $archived || strtolower(trim((string)$row['stack_title'])) === 'done';
                }

                $out[$id] = [
                    'id'         => $id,
                    'title'      => (string)$row['card_title'],
                    'archived'   => $archived,
                    'deletedAt'  => (int)($row['card_deleted_at'] ?? 0),
                    'stackTitle' => (string)$row['stack_title'],
                    'isDone'     => $isDone,
                    'boardId'    => $boardId,
                    'boardTitle' => (string)$row['board_title'],
                    'boardColor' => (string)($row['board_color'] ?? '0082c9'),
                    'url'        => '/apps/deck/board/' . $boardId . '/card/' . $id,
                ];
            }
            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DeckService] getCardsByIds failed', [
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

    /**
     * Return the three default stack titles ('To do', 'In progress', 'Done')
     * translated into the creator's NC language using TeamHub's own
     * translation catalogue.
     *
     * Earlier (3.85.5) this pulled from Deck's catalogue on the assumption
     * its .po files carried the strings. In practice Deck's translation
     * coverage for these three is uneven — Dutch had 'Done' → 'Klaar' but
     * not 'To do' / 'In progress' — leaving columns half-translated. Owning
     * the strings in TeamHub gives us deterministic coverage across all six
     * shipped locales.
     *
     * Falls back to English when language resolution fails — IL10N::t()
     * returns the source string when its catalogue lacks an entry, which is
     * the correct visible default.
     *
     * @param string $uid Board owner UID
     * @return string[] Three titles in order: index 0 = To do, 1 = In progress, 2 = Done
     */
    private function translateDefaultStackTitles(string $uid): array {
        $defaults = ['To do', 'In progress', 'Done'];
        try {
            $config      = $this->container->get(\OCP\IConfig::class);
            $l10nFactory = $this->container->get(\OCP\L10N\IFactory::class);

            $language = $config->getUserValue($uid, 'core', 'lang', '');
            if ($language === '') {
                $language = $config->getSystemValue('default_language', 'en');
            }

            $l = $l10nFactory->get(Application::APP_ID, $language);
            return [
                // TRANSLATORS: default first column ("backlog") of a new Deck kanban board
                (string)$l->t('To do'),
                // TRANSLATORS: default middle column of a new Deck kanban board — work currently being worked on
                (string)$l->t('In progress'),
                // TRANSLATORS: default last column of a new Deck kanban board — completed work
                (string)$l->t('Done'),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DeckService] Could not resolve translated stack titles, falling back to English', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return $defaults;
        }
    }

    /**
     * Project Teams (v3.90.x) — "Project management" stack + 4 starter cards
     * for Advanced projects, to give a rolling start. Each card is assigned to
     * $uid (the team creator) and due 7 days out.
     *
     * Card creation confirmed empirically via the admin-only deck-diagnostic
     * probe (reflection on live Deck classes, no precedent existed anywhere in
     * TeamHub before this — AddTaskModal.vue calls Deck's own OCS API from the
     * frontend and never touches this backend):
     *   OCA\Deck\Service\CardService::create(string $title, int $stackId,
     *     string $type, int $order, string $owner, string $description, mixed $duedate)
     *   OCA\Deck\Service\AssignmentService::assignUser(int $cardId, string $userId, int $type)
     *     — $type=0 for a plain user assignment, confirmed against the SAME
     *     convention already used for board ACLs above (0=user, 1=group, 7=circle)
     *     and TimelineService's existing deck_card_assigned_users read filter.
     *
     * Non-fatal throughout — a missing starter stack/card is recoverable
     * (the owner can add it manually), so failures are logged, never thrown.
     */
    private function seedProjectManagementStack(int $boardId, int $order, string $uid, \OCP\IDBConnection $db): void {
        try {
            $extras = $this->translateProjectManagementExtras($uid);

            $stackMapper = $this->container->get(\OCA\Deck\Db\StackMapper::class);
            $stack = new \OCA\Deck\Db\Stack();
            $stack->setTitle($extras['stackTitle']);
            $stack->setBoardId($boardId);
            $stack->setOrder($order);
            $stack = $stackMapper->insert($stack);
            $stackId = $stack->getId();

            $cardService = $this->container->get(\OCA\Deck\Service\CardService::class);
            $dueDate = new \DateTime('@' . (time() + 7 * 86400));

            foreach ($extras['cardTitles'] as $idx => $cardTitle) {
                try {
                    // CardService::create() returns OCA\Deck\Model\CardDetails,
                    // whose accessor for the new card's id could not be confirmed
                    // (getId() doesn't exist on it, and reflection can't reveal a
                    // return value's runtime shape — confirmed via the
                    // deck-diagnostic probe and a follow-up trace: the same
                    // "Cannot use object of type CardDetails as array" error
                    // persisted through several accessor attempts and did not
                    // originate from TeamHub's own extraction code). Sidestepped
                    // entirely by looking the card up directly afterwards — the
                    // same "fall back to direct DB access when Deck's own layer
                    // proves unreliable" precedent TimelineService already
                    // established for reading assignees (see its docblock).
                    $cardService->create($cardTitle, $stackId, 'plain', $idx, $uid, '', $dueDate);

                    $cardId = $this->findCardIdByStackAndTitle($db, $stackId, $cardTitle);
                    if ($cardId !== null) {
                        $this->assignCardToUser($db, $cardId, $uid);
                    } else {
                        $this->logger->warning('[TeamHub][DeckService] Project management starter card: could not find card id after creation', [
                            'card' => $cardTitle, 'stackId' => $stackId, 'app' => Application::APP_ID,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][DeckService] Project management starter card failed', [
                        'card' => $cardTitle, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DeckService] Project management stack seeding failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Look up a just-created card's id by (stack_id, title) — sidesteps
     * CardService::create()'s CardDetails return value entirely (see
     * seedProjectManagementStack). Picks the most recently created match;
     * safe here because the stack is freshly created moments earlier, so
     * there is no pre-existing card that could share the title.
     */
    private function findCardIdByStackAndTitle(\OCP\IDBConnection $db, int $stackId, string $title): ?int {
        $qb = $db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('deck_cards')
            ->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Assign $uid to $cardId — direct DB write, mirroring the exact
     * (table, participantColumn) variant detection TimelineService already
     * uses for READING assignees (deck_card_assigned_users vs
     * deck_assigned_users; participant_uid vs participant — confirmed to
     * differ across Deck versions/installs, see TimelineService's docblock).
     * Bypasses AssignmentService::assignUser(), which threw an unexplained
     * "Cannot use object of type CardDetails as array" on every call in
     * testing — likely triggered by one of its own injected dependencies
     * (NotificationHelper/ActivityManager), not by TeamHub's input, and not
     * worth chasing further given this codebase's own established fallback.
     *
     * Does NOT gate on DbIntrospectionService::getTableColumns() — per
     * TimelineService's own hard-won lesson (3.86.1), introspection can
     * silently return [] for a table that genuinely exists but is low-row-
     * count or where INFORMATION_SCHEMA access is restricted. Tries the
     * INSERT directly per variant instead; a missing table throws
     * SQLSTATE[42S02] and moves to the next variant. A missing `type` column
     * retries the same (table, participantColumn) pair without it.
     */
    private function assignCardToUser(\OCP\IDBConnection $db, int $cardId, string $uid): void {
        $variants = [
            ['deck_card_assigned_users', 'participant_uid'],
            ['deck_card_assigned_users', 'participant'],
            ['deck_assigned_users',      'participant_uid'],
            ['deck_assigned_users',      'participant'],
        ];
        foreach ($variants as [$table, $participantColumn]) {
            if ($this->tryInsertAssignment($db, $table, $participantColumn, $cardId, $uid, true)) {
                return;
            }
        }
        $this->logger->warning('[TeamHub][DeckService] assignCardToUser: no working table variant found', [
            'cardId' => $cardId, 'app' => Application::APP_ID,
        ]);
    }

    /**
     * One INSERT attempt for a single (table, participantColumn) variant.
     * Mirrors TimelineService::fetchDeckAssigneesForVariant's two-failure-mode
     * handling: a missing table (SQLSTATE[42S02]) returns false so the caller
     * moves to the next variant; a missing `type` column retries the same
     * table/column pair once without it, since `type` isn't present on every
     * Deck version's assignee table.
     */
    private function tryInsertAssignment(\OCP\IDBConnection $db, string $table, string $participantColumn, int $cardId, string $uid, bool $withType): bool {
        try {
            $qb = $db->getQueryBuilder();
            $qb->insert($table)
                ->setValue('card_id', $qb->createNamedParameter($cardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->setValue($participantColumn, $qb->createNamedParameter($uid));
            if ($withType) {
                // Deck convention: 0=user, 1=group, 2=circle (confirmed via
                // TimelineService's existing read-side type filter).
                $qb->setValue('type', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT));
            }
            $qb->executeStatement();
            return true;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($withType && !str_contains($msg, '42S02') && !str_contains($msg, 'not found')) {
                // Table exists but rejected the `type` column — retry without it.
                return $this->tryInsertAssignment($db, $table, $participantColumn, $cardId, $uid, false);
            }
            $this->logger->debug('[TeamHub][DeckService] assignCardToUser variant failed, trying next', [
                'table' => $table, 'participantColumn' => $participantColumn,
                'error' => $msg, 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Translated "Project management" stack title + 4 starter-card titles for
     * Advanced projects. Same per-user language resolution as
     * translateDefaultStackTitles, kept as its own independent method rather
     * than refactored to share code with that already-shipped (3.85.5) method,
     * to avoid any risk of regressing its tested fallback behaviour.
     */
    private function translateProjectManagementExtras(string $uid): array {
        $defaults = [
            'stackTitle' => 'Project management',
            'cardTitles' => [
                'Invite project members',
                'Create project contract',
                'Add project milestones',
                'Schedule the planning kickoff meeting',
            ],
        ];
        try {
            $config      = $this->container->get(\OCP\IConfig::class);
            $l10nFactory = $this->container->get(\OCP\L10N\IFactory::class);

            $language = $config->getUserValue($uid, 'core', 'lang', '');
            if ($language === '') {
                $language = $config->getSystemValue('default_language', 'en');
            }

            $l = $l10nFactory->get(Application::APP_ID, $language);
            return [
                // TRANSLATORS: 4th Deck stack (kanban column) on an Advanced project's board, always present alongside To do/In progress/Done
                'stackTitle' => (string)$l->t('Project management'),
                'cardTitles' => [
                    (string)$l->t('Invite project members'),
                    (string)$l->t('Create project contract'),
                    (string)$l->t('Add project milestones'),
                    (string)$l->t('Schedule the planning kickoff meeting'),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][DeckService] Could not resolve translated project management titles, falling back to English', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return $defaults;
        }
    }

}
