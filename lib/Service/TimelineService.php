<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\DecisionTaskMapper;
use OCA\TeamHub\Service\DecisionTaskService;
use OCA\TeamHub\Service\DbIntrospectionService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates date-anchored events from Calendar, Decisions, Deck, Messages,
 * and team-admin-authored Milestones for a given team and time window.
 *
 * Returned events all share the same normalized shape so the frontend
 * can render them without source-specific branching:
 *
 *   {
 *     id:      string   — unique across all sources ("cal-X", "dec-X-proposed", ...)
 *     source:  string   — "calendar" | "decisions" | "deck" | "messages" | "milestone"
 *     type:    string   — source-specific sub-type (event|proposed|decided|withdrawn|created|due|posted|milestone)
 *     title:   string
 *     date:    string   — ISO 8601 of the plotted moment
 *     endDate: string|null
 *     allDay:  bool
 *     url:     string|null
 *     meta:    object   — source-specific extras (calendarName, impact, boardName, …)
 *   }
 *
 * Decision events (option B, per Justin): each decision produces up to TWO
 * events — a "proposed" event at created_at and a "decided"/"withdrawn" event
 * at decided_at / withdrawn_at. Every decision event's meta also carries:
 *   - linkedCardIds (v3.78.5) — Deck card IDs linked via "Link tasks"
 *     (DecisionTaskService), resolved from task_path. Used by the frontend's
 *     opt-in "Decision ↔ task links" overlay to draw a connector arrow from
 *     the decision's "decided"/"withdrawn" outcome chip to each linked
 *     card's "created" chip.
 *   - sourceMessageId (v3.78.9) — the teamhub_messages row that announced
 *     this proposal (messageType='decision'). Used by the frontend's opt-in
 *     "Message ↔ decision" overlay to draw a connector arrow from that
 *     post's chip to this decision's "proposed" chip.
 *
 * Deck events: each card produces up to TWO events — a "created" event at
 * created_at and a "due" event at due_date (when set). meta also carries
 * blockedByCardIds (v3.78.8, NC 34 / Deck 1.18+ only) — the Deck card IDs
 * this card depends on, used by the frontend's opt-in "Deck card
 * dependencies" overlay.
 *
 * Milestone events: each dated milestone (lib/Service/MilestoneService)
 * produces exactly ONE event. The frontend renders these as a full-height
 * marker line rather than as a chip inside a source band — see
 * templates/timeline.php.
 *
 * All sources are independently try-caught. A missing Deck install or broken
 * calendar never prevents decisions from appearing.
 */
class TimelineService {

    public function __construct(
        private IDBConnection      $db,
        private IAppManager        $appManager,
        private IUserSession       $userSession,
        private ContainerInterface $container,
        private LoggerInterface    $logger,
        private DbIntrospectionService $dbIntrospection,
        private TeamAppResourceMapper $resourceMapper,
        private MilestoneMapper $milestoneMapper,
        private DecisionTaskMapper $decisionTaskMapper,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Return all timeline events for $teamId whose anchor date falls within
     * [$from, $to] (Unix timestamps, inclusive).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(string $teamId, int $from, int $to): array {
        $this->logger->debug('[TimelineService] getEvents teamId=' . $teamId . ' from=' . $from . ' to=' . $to, ['app' => Application::APP_ID]);

        $events = [];

        // Each source is independently wrapped so a failure in one never
        // blocks the others.
        try {
            $events = array_merge($events, $this->fetchCalendarEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TimelineService] calendar fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchDecisionEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TimelineService] decisions fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchDeckEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TimelineService] deck fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchMessageEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TimelineService] messages fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchMilestoneEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TimelineService] milestones fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Sort ascending by anchor date so the frontend receives a stable order.
        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

        $this->logger->debug('[TimelineService] getEvents: returning ' . count($events) . ' events', ['app' => Application::APP_ID]);
        return $events;
    }

    // =========================================================================
    // Calendar
    // =========================================================================

    /**
     * Fetch VEVENT objects from calendarobjects whose DTSTART falls within
     * [$from, $to]. Adapted from ActivityService::getTeamCalendarEvents but
     * parameterised on the date range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarEvents(string $teamId, int $from, int $to): array {
        if (!$this->appManager->isInstalled('calendar')) {
            return [];
        }

        // Locate calendars shared with this team's circle.
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('resourceid')
            ->from('dav_shares')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('principaluri',
                $qb->createNamedParameter('principals/circles/' . $teamId)))
            ->executeQuery();

        $calendarIds = [];
        while ($row = $res->fetch()) {
            $calendarIds[] = (int)$row['resourceid'];
        }
        $res->closeCursor();

        if (empty($calendarIds)) {
            return [];
        }

        $events = [];

        foreach ($calendarIds as $calendarId) {
            // Calendar owner for building edit URLs.
            $calQb  = $this->db->getQueryBuilder();
            $calRes = $calQb->select('principaluri', 'uri', 'displayname')
                ->from('calendars')
                ->where($calQb->expr()->eq('id',
                    $calQb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $calRow   = $calRes->fetch();
            $calRes->closeCursor();

            $calOwner = '';
            $calSlug  = '';
            $calName  = '';
            if ($calRow) {
                $parts    = explode('/', $calRow['principaluri']);
                $calOwner = end($parts);
                $calSlug  = $calRow['uri'];
                $calName  = $calRow['displayname'] ?: $calRow['uri'];
            }

            // Fetch all VEVENTs for this calendar (no DB-side date filter because
            // recurring-event expansion requires reading all objects and filtering
            // in PHP via Sabre).
            $evQb  = $this->db->getQueryBuilder();
            $evRes = $evQb->select('co.id', 'co.uri', 'co.calendardata')
                ->from('calendarobjects', 'co')
                ->where($evQb->expr()->eq('co.calendarid',
                    $evQb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
                ->andWhere($evQb->expr()->eq('co.componenttype',
                    $evQb->createNamedParameter('VEVENT')))
                ->andWhere($evQb->expr()->notLike('co.uri',
                    $evQb->createNamedParameter('%-deleted.ics')))
                ->executeQuery();

            while ($row = $evRes->fetch()) {
                try {
                    $vcalendar = \Sabre\VObject\Reader::read($row['calendardata']);
                    if (!isset($vcalendar->VEVENT)) {
                        continue;
                    }
                    $vevent    = $vcalendar->VEVENT;
                    if (!isset($vevent->DTSTART)) {
                        continue;
                    }

                    $dtstart   = $vevent->DTSTART;
                    $startTime = $dtstart->getDateTime();
                    $startTs   = $startTime->getTimestamp();

                    if ($startTs < $from || $startTs > $to) {
                        continue;
                    }

                    $endTime = null;
                    if (isset($vevent->DTEND)) {
                        $endTime = $vevent->DTEND->getDateTime();
                    } elseif (isset($vevent->DURATION)) {
                        $endTime = clone $startTime;
                        $endTime->add($vevent->DURATION->getDateInterval());
                    }

                    $editUrl = null;
                    if ($calOwner !== '' && $calSlug !== '') {
                        $davPath  = '/remote.php/dav/calendars/' . $calOwner . '/' . $calSlug . '/' . $row['uri'];
                        $objectId = rtrim(strtr(base64_encode($davPath), '+/', '-_'), '=');
                        $editUrl  = '/apps/calendar/timeGridWeek/now/edit/sidebar/' . $objectId . '/' . $startTs;
                    }

                    $events[] = [
                        'id'      => 'cal-' . (string)($vevent->UID ?? $row['uri']),
                        'source'  => 'calendar',
                        'type'    => 'event',
                        'title'   => (string)($vevent->SUMMARY ?? t('teamhub', 'Untitled')),
                        'date'    => $startTime->format('c'),
                        'endDate' => $endTime?->format('c'),
                        'allDay'  => !$dtstart->hasTime(),
                        'url'     => $editUrl,
                        'meta'    => [
                            'calendarName' => $calName,
                            'location'     => isset($vevent->LOCATION) ? (string)$vevent->LOCATION : null,
                        ],
                    ];

                } catch (\Throwable $e) {
                    $this->logger->debug('[TimelineService] skipped calendar object', [
                        'uri' => $row['uri'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                    ]);
                }
            }
            $evRes->closeCursor();
        }

        return $events;
    }

    // =========================================================================
    // Decisions
    // =========================================================================

    /**
     * Each decision produces up to two events:
     *  - "proposed"  at created_at  (always, when created_at is in range)
     *  - "decided"   at decided_at  (when approved/denied, decided_at in range)
     *  - "withdrawn" at withdrawn_at (when withdrawn, withdrawn_at in range)
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchDecisionEvents(string $teamId, int $from, int $to): array {
        $qb  = $this->db->getQueryBuilder();

        // Fetch decisions that have at least one anchor date in the range.
        $res = $qb->select('*')
            ->from('teamhub_decisions')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->orX(
                // Proposed event falls in range
                $qb->expr()->andX(
                    $qb->expr()->gte('created_at',
                        $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->lte('created_at',
                        $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
                ),
                // Decided event falls in range
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('decided_at'),
                    $qb->expr()->gte('decided_at',
                        $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->lte('decided_at',
                        $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
                ),
                // Withdrawn event falls in range
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('withdrawn_at'),
                    $qb->expr()->gte('withdrawn_at',
                        $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->lte('withdrawn_at',
                        $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
                )
            ))
            ->orderBy('created_at', 'ASC')
            ->executeQuery();

        $rows = [];
        while ($row = $res->fetch()) {
            $rows[] = $row;
        }
        $res->closeCursor();

        // Bulk-fetch decision↔task links for every decision in this window
        // in one query, then resolve each task_path to a Deck card ID (when
        // it is one — plain URLs and non-Deck paths resolve to null and are
        // dropped). This powers the "Decision ↔ task links" connector
        // overlay (v3.78.5) — see templates/timeline.php's drawConnectors().
        $decisionIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $linkedCardIdsByDecision = [];
        foreach ($this->decisionTaskMapper->findByDecisionIds($decisionIds) as $link) {
            $cardId = DecisionTaskService::extractDeckCardId((string)$link['task_path']);
            if ($cardId === null) {
                continue;
            }
            $decId = (int)$link['decision_id'];
            if (!isset($linkedCardIdsByDecision[$decId])) {
                $linkedCardIdsByDecision[$decId] = [];
            }
            if (!in_array($cardId, $linkedCardIdsByDecision[$decId], true)) {
                $linkedCardIdsByDecision[$decId][] = $cardId;
            }
        }

        $events = [];

        foreach ($rows as $row) {
            $id       = (int)$row['id'];
            $question = (string)$row['question'];
            $status   = (string)$row['status'];
            $impact   = (string)$row['impact'];
            $category = (string)($row['category'] ?? '');
            $level    = (string)($row['level'] ?? '');

            $meta = [
                'status'        => $status,
                'impact'        => $impact,
                'category'      => $category !== '' ? $category : null,
                'level'         => $level !== '' ? $level : null,
                'decisionId'    => $id,
                'linkedCardIds' => $linkedCardIdsByDecision[$id] ?? [],
                // The stream post that announced this proposal (v3.78.9).
                // Every decision is created alongside a messageType='decision'
                // post in teamhub_messages — see MessageService::createMessage.
                // Used by the frontend's "Message ↔ decision proposal" overlay
                // to draw an arrow from that post's chip to this decision's
                // "proposed" chip. 0 for any malformed/legacy row with no
                // linked post; the frontend skips those.
                'sourceMessageId' => (int)($row['message_id'] ?? 0),
            ];

            $createdAt = (int)$row['created_at'];

            // Deep-link URL to open the decision inside TeamHub. The frontend
            // (TeamDecisionsView via App.vue) already reads ?team=…&decision=…
            // and scrolls to that proposal on load — same pattern used by the
            // meeting invite "Link:" body. Built as an app-relative path so
            // generateUrl()/the iframe pick up the right NC web-root.
            $decisionUrl = '/apps/teamhub?team=' . rawurlencode($teamId) . '&decision=' . $id;

            // Event 1 — "Proposed" at created_at
            if ($createdAt >= $from && $createdAt <= $to) {
                $events[] = [
                    'id'      => 'dec-' . $id . '-proposed',
                    'source'  => 'decisions',
                    'type'    => 'proposed',
                    'title'   => $question,
                    'date'    => (new \DateTimeImmutable('@' . $createdAt))->format('c'),
                    'endDate' => null,
                    'allDay'  => false,
                    'url'     => $decisionUrl,
                    'meta'    => array_merge($meta, ['eventRole' => 'proposed']),
                ];
            }

            // Event 2a — "Decided" at decided_at (approved / denied / finalized)
            if (!empty($row['decided_at'])) {
                $decidedAt = (int)$row['decided_at'];
                if ($decidedAt >= $from && $decidedAt <= $to) {
                    $events[] = [
                        'id'      => 'dec-' . $id . '-decided',
                        'source'  => 'decisions',
                        'type'    => 'decided',
                        'title'   => $question,
                        'date'    => (new \DateTimeImmutable('@' . $decidedAt))->format('c'),
                        'endDate' => null,
                        'allDay'  => false,
                        'url'     => $decisionUrl,
                        'meta'    => array_merge($meta, ['eventRole' => 'decided']),
                    ];
                }
            }

            // Event 2b — "Withdrawn" at withdrawn_at
            if (!empty($row['withdrawn_at'])) {
                $withdrawnAt = (int)$row['withdrawn_at'];
                if ($withdrawnAt >= $from && $withdrawnAt <= $to) {
                    $events[] = [
                        'id'      => 'dec-' . $id . '-withdrawn',
                        'source'  => 'decisions',
                        'type'    => 'withdrawn',
                        'title'   => $question,
                        'date'    => (new \DateTimeImmutable('@' . $withdrawnAt))->format('c'),
                        'endDate' => null,
                        'allDay'  => false,
                        'url'     => $decisionUrl,
                        'meta'    => array_merge($meta, ['eventRole' => 'withdrawn']),
                    ];
                }
            }
        }

        return $events;
    }

    // =========================================================================
    // Deck
    // =========================================================================

    /**
     * Fetch Deck cards connected to this team. Each card produces up to two
     * events:
     *  - "created"  at card created_at (when in range)
     *  - "due"      at card due_date   (when set and in range)
     *
     * Board lookup follows the same ACL-table fallback as DeckService:
     * deck_board_acl → deck_acl, type=7 (circle) where participant=teamId.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchDeckEvents(string $teamId, int $from, int $to): array {
        if (!$this->appManager->isInstalled('deck')) {
            $this->logger->debug('[TimelineService] Deck: app not installed, skipping', ['app' => Application::APP_ID]);
            return [];
        }

        $this->logger->debug('[TimelineService] Deck: fetching for teamId=' . $teamId, ['app' => Application::APP_ID]);

        // ── 1. Find board IDs for this team ───────────────────────────────────
        // PRIMARY: TeamHub's own registry (teamhub_team_app_resources). This is
        // the source of truth used by ResourceService for the Deck tab — boards
        // that were connected to the team via TeamHub's flow are here. The Deck
        // tab works because of this table, so the timeline must use it too.
        //
        // FALLBACK: scan the Deck ACL tables. Useful only on installs where the
        // ACL row exists but the registry doesn't yet (corruption, or future
        // discovery flows). Won't hurt when both are present — we union.
        $boardIds = [];

        // ── 1a. Registry (primary)
        try {
            $registryRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'deck');
            foreach ($registryRows as $row) {
                $boardIds[(int)$row->getResourceId()] = true;
            }
            $this->logger->debug('[TimelineService] Deck: registry yielded ' . count($registryRows) . ' board(s) for team ' . $teamId, ['app' => Application::APP_ID]);
        } catch (\Throwable $e) {
            $this->logger->debug('[TimelineService] Deck: registry lookup failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
        }

        // ── 1b. ACL fallback
        foreach (['deck_board_acl', 'deck_acl'] as $aclTable) {
            try {
                $aqb  = $this->db->getQueryBuilder();
                $ares = $aqb->select('board_id')
                    ->from($aclTable)
                    ->where($aqb->expr()->eq('type',
                        $aqb->createNamedParameter(7, IQueryBuilder::PARAM_INT))) // 7 = circle
                    ->andWhere($aqb->expr()->eq('participant',
                        $aqb->createNamedParameter($teamId)))
                    ->executeQuery();
                $count = 0;
                while ($arow = $ares->fetch()) {
                    $boardIds[(int)$arow['board_id']] = true;
                    $count++;
                }
                $ares->closeCursor();
                $this->logger->debug('[TimelineService] Deck: ACL ' . $aclTable . ' yielded ' . $count . ' rows', ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->debug('[TimelineService] Deck: ACL ' . $aclTable . ' not present (' . $e->getMessage() . ')', ['app' => Application::APP_ID]);
            }
        }

        if (empty($boardIds)) {
            $this->logger->debug('[TimelineService] Deck: no boards found for team ' . $teamId, ['app' => Application::APP_ID]);
            return [];
        }

        $boardIdList = array_keys($boardIds);
        $this->logger->debug('[TimelineService] Deck: total ' . count($boardIdList) . ' board(s): ' . implode(',', $boardIdList), ['app' => Application::APP_ID]);

        // ── 2. Fetch stacks for those boards ─────────────────────────────────
        // Deliberately DO NOT filter b.archived=0 anymore. The user's two
        // boards came back from the registry, which already encodes "active"
        // state for the team. Re-filtering on Deck's own archived flag risks
        // dropping boards that are perfectly visible in the Deck app on this
        // install (and was a silent-kill candidate). Soft-deleted stacks (with
        // deleted_at) ARE filtered out if the column exists, since those are
        // stacks the user has actually removed from view.
        $stackCols          = $this->dbIntrospection->getTableColumns('deck_stacks');
        $stackHasDeletedAt  = in_array('deleted_at', $stackCols, true);

        $sqb = $this->db->getQueryBuilder();
        $sqb->select('s.id', 's.title', 's.board_id', 'b.title AS board_title')
            ->from('deck_stacks', 's')
            ->leftJoin('s', 'deck_boards', 'b', $sqb->expr()->eq('s.board_id', 'b.id'))
            ->where($sqb->expr()->in('s.board_id',
                $sqb->createNamedParameter($boardIdList, IQueryBuilder::PARAM_INT_ARRAY)));

        if ($stackHasDeletedAt) {
            // deleted_at is an int Unix-ts or 0/NULL. Active stacks have 0 or NULL.
            $sqb->andWhere($sqb->expr()->orX(
                $sqb->expr()->isNull('s.deleted_at'),
                $sqb->expr()->eq('s.deleted_at',
                    $sqb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ));
        }

        $sres = $sqb->executeQuery();

        $stacks = [];
        while ($srow = $sres->fetch()) {
            $stacks[(int)$srow['id']] = [
                'board_id'    => (int)$srow['board_id'],
                'board_title' => (string)($srow['board_title'] ?? ''),
                'title'       => (string)$srow['title'],
            ];
        }
        $sres->closeCursor();

        $this->logger->debug('[TimelineService] Deck: found ' . count($stacks) . ' stack(s) across ' . count($boardIdList) . ' board(s)', ['app' => Application::APP_ID]);

        if (empty($stacks)) {
            return [];
        }

        $stackIds = array_keys($stacks);

        // ── 3. Fetch cards. Use SELECT * to bypass column introspection
        // entirely. Earlier code introspected `deck_cards` and built a select
        // list of optional columns (last_modified, duedate, deleted_at,
        // archived) — but on at least one production install DbIntrospection's
        // strategies all failed and returned [] for this table, so the query
        // ran with only id/title/stack_id and Deck events came out empty.
        // SELECT * sidesteps that whole class of failure: whatever columns
        // exist on this Deck version come back, and the per-row reads below
        // use ?? null fallbacks so missing columns degrade gracefully.
        $cqb = $this->db->getQueryBuilder();
        $cres = $cqb->select('*')
            ->from('deck_cards')
            ->where($cqb->expr()->in('stack_id',
                $cqb->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();

        $events  = [];
        $totalCards    = 0;
        $skippedDeleted = 0;
        $skippedArchived = 0;
        $skippedNoDates  = 0;
        $skippedOutOfRange = 0;
        $loggedRowKeys   = false;
        // Every card ID seen this pass, regardless of whether it emitted an
        // event — used by the post-loop card-dependency fetch below so a
        // dependency edge isn't missed just because one side's own dates
        // happen to fall outside [from, to]. v3.78.8.
        $allCardIds = [];

        while ($crow = $cres->fetch()) {
            $totalCards++;

            // One-shot diagnostic: log the actual column names + first-row
            // sample values so future debugging doesn't have to guess what
            // Deck's schema looks like on this install.
            if (!$loggedRowKeys) {
                $loggedRowKeys = true;
                $this->logger->debug('[TimelineService] Deck: deck_cards row keys = ' . implode(',', array_keys($crow))
                    . ' | sample: last_modified=' . var_export($crow['last_modified'] ?? '<missing>', true)
                    . ' duedate=' . var_export($crow['duedate'] ?? '<missing>', true),
                    ['app' => Application::APP_ID]);
            }

            if (!empty($crow['deleted_at'])) { $skippedDeleted++; continue; }
            if (!empty($crow['archived']) && (int)$crow['archived'] === 1) { $skippedArchived++; continue; }

            $cardId    = (int)$crow['id'];
            $allCardIds[] = $cardId;
            $stackId   = (int)$crow['stack_id'];
            $stack     = $stacks[$stackId] ?? ['board_title' => '', 'title' => '', 'board_id' => 0];
            $title     = (string)$crow['title'];

            $meta = [
                'boardName' => $stack['board_title'],
                'stackName' => $stack['title'],
                'cardId'    => $cardId,
            ];

            $deckCardUrl = '/apps/deck/board/' . $stack['board_id'] . '/card/' . $cardId;

            $emittedEither = false;

            // Detect "completed" state. Deck's done column is a datetime/Unix
            // timestamp when the card was marked complete (null/0 otherwise).
            $doneRaw    = $crow['done'] ?? null;
            $completedTs = null;
            if ($doneRaw !== null && $doneRaw !== '' && $doneRaw !== 0 && $doneRaw !== '0') {
                $completedTs = $this->parseTimestamp($doneRaw);
            }
            $isCompleted = ($completedTs !== null);
            $meta['completed'] = $isCompleted;
            if ($completedTs !== null) {
                $meta['completedAt'] = (new \DateTimeImmutable('@' . $completedTs))->format('c');
            }

            // Event 1 — "Created" anchored at last_modified.
            // Deck has no created_at column; last_modified is the closest
            // available proxy. For cards that haven't been touched since
            // creation this is exact; for edited cards it reflects the latest
            // modification — still a meaningful timeline anchor.
            $createdRaw = $crow['last_modified'] ?? null;
            if ($createdRaw !== null && $createdRaw !== '' && $createdRaw !== 0 && $createdRaw !== '0') {
                $createdTs = $this->parseTimestamp($createdRaw);
                if ($createdTs !== null && $createdTs >= $from && $createdTs <= $to) {
                    $events[] = [
                        'id'      => 'deck-' . $cardId . '-created',
                        'source'  => 'deck',
                        'type'    => 'created',
                        'title'   => $title,
                        'date'    => (new \DateTimeImmutable('@' . $createdTs))->format('c'),
                        'endDate' => null,
                        'allDay'  => true,
                        'url'     => $deckCardUrl,
                        'meta'    => array_merge($meta, ['eventRole' => 'created']),
                    ];
                    $emittedEither = true;
                }
            }

            // Event 2 — "Due" at due date. Deck stores this as an integer Unix
            // timestamp in 'duedate' (NOT 'due_date'). 0 is the sentinel for
            // "no due date". Still emitted for completed cards — the frontend
            // uses the `completed` meta flag to choose which to show by default.
            $dueRaw = $crow['duedate'] ?? null;
            if ($dueRaw !== null && $dueRaw !== '' && $dueRaw !== 0 && $dueRaw !== '0') {
                $dueTs = $this->parseTimestamp($dueRaw);
                if ($dueTs !== null && $dueTs >= $from && $dueTs <= $to) {
                    $now     = time();
                    // A card is "overdue" only if it's not completed and the
                    // due date has passed. Completed cards never show overdue,
                    // even if completed after the due date.
                    $overdue = (!$isCompleted) && $dueTs < $now;
                    $events[] = [
                        'id'      => 'deck-' . $cardId . '-due',
                        'source'  => 'deck',
                        'type'    => 'due',
                        'title'   => $title,
                        'date'    => (new \DateTimeImmutable('@' . $dueTs))->format('c'),
                        'endDate' => null,
                        'allDay'  => true,
                        'url'     => $deckCardUrl,
                        'meta'    => array_merge($meta, ['eventRole' => 'due', 'overdue' => $overdue]),
                    ];
                    $emittedEither = true;
                }
            }

            // Event 3 — "Completed" at the done timestamp.
            if ($completedTs !== null && $completedTs >= $from && $completedTs <= $to) {
                $events[] = [
                    'id'      => 'deck-' . $cardId . '-completed',
                    'source'  => 'deck',
                    'type'    => 'completed',
                    'title'   => $title,
                    'date'    => (new \DateTimeImmutable('@' . $completedTs))->format('c'),
                    'endDate' => null,
                    'allDay'  => true,
                    'url'     => $deckCardUrl,
                    'meta'    => array_merge($meta, ['eventRole' => 'completed']),
                ];
                $emittedEither = true;
            }

            if (!$emittedEither) {
                $hasNoDates = ($createdRaw === null || $createdRaw === '' || $createdRaw === 0 || $createdRaw === '0')
                           && ($dueRaw === null     || $dueRaw === ''     || $dueRaw === 0     || $dueRaw === '0')
                           && ($doneRaw === null    || $doneRaw === ''    || $doneRaw === 0    || $doneRaw === '0');
                if ($hasNoDates) {
                    $skippedNoDates++;
                } else {
                    $skippedOutOfRange++;
                }
            }
        }
        $cres->closeCursor();

        // ── Card dependencies (v3.78.8) ─────────────────────────────────────
        // NC 34 / Deck 1.18+ only — deck_dependent_cards is a brand-new table
        // (confirmed against Deck v1.18.0-beta.3 source: lib/Migration/
        // Version11002Date20260410000000.php), absent on older Deck installs.
        // Detected purely via DbIntrospectionService, exactly like the
        // done_status/deleted_at column checks above — never assumed present.
        //
        // Direction, per Deck's own CardMapper::addDependency($cardId,
        // $dependentCardId) and the "Assign dependent cards" UI (which lets
        // you mark the dependent card done from inside the host card's
        // sidebar — a prerequisite-checklist pattern): a row (card_id=A,
        // dependent_card_id=B) means A depends on B, i.e. B blocks A. We
        // expose this on A's "created" event as meta.blockedByCardIds = [B].
        // The frontend draws the connector arrow B → A (prerequisite →
        // blocked), the same predecessor→successor convention used for the
        // decision→task connector.
        $depCols = $this->dbIntrospection->getTableColumns('deck_dependent_cards');
        if (!empty($depCols) && !empty($allCardIds)) {
            try {
                $dqb  = $this->db->getQueryBuilder();
                $dres = $dqb->select('card_id', 'dependent_card_id')
                    ->from('deck_dependent_cards')
                    ->where($dqb->expr()->in('card_id',
                        $dqb->createNamedParameter(array_unique($allCardIds), IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeQuery();

                $blockedByMap = []; // card_id (blocked) => [dependent_card_id (blocker), ...]
                $depRowCount  = 0;
                while ($drow = $dres->fetch()) {
                    $blockedByMap[(int)$drow['card_id']][] = (int)$drow['dependent_card_id'];
                    $depRowCount++;
                }
                $dres->closeCursor();

                if (!empty($blockedByMap)) {
                    foreach ($events as &$ev) {
                        if ($ev['source'] === 'deck'
                            && ($ev['meta']['eventRole'] ?? null) === 'created'
                            && isset($blockedByMap[$ev['meta']['cardId']])
                        ) {
                            $ev['meta']['blockedByCardIds'] = $blockedByMap[$ev['meta']['cardId']];
                        }
                    }
                    unset($ev);
                }

                $this->logger->debug('[TimelineService] Deck: card dependencies — '
                    . $depRowCount . ' row(s) across ' . count($blockedByMap) . ' blocked card(s)',
                    ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->debug('[TimelineService] Deck: dependency fetch failed: ' . $e->getMessage(),
                    ['app' => Application::APP_ID]);
            }
        }

        $this->logger->debug('[TimelineService] Deck: window=[' . $from . ',' . $to . '] cardsTotal=' . $totalCards
            . ' deleted=' . $skippedDeleted . ' archived=' . $skippedArchived
            . ' noDates=' . $skippedNoDates . ' outOfRange=' . $skippedOutOfRange
            . ' eventsEmitted=' . count($events), ['app' => Application::APP_ID]);

        return $events;
    }

    /**
     * Whether this NC/Deck install supports card dependencies — i.e.
     * whether deck_dependent_cards exists. NC 34 / Deck 1.18+ only.
     *
     * Exposed so the frontend can hide the "Deck card dependencies"
     * connector toggle entirely on installs that don't have the feature,
     * rather than showing a control that can never do anything (v3.78.8).
     */
    public function isCardDependencySupported(): bool {
        return !empty($this->dbIntrospection->getTableColumns('deck_dependent_cards'));
    }

    // =========================================================================
    // Messages
    // =========================================================================

    /**
     * Fetch message stream posts for this team whose created_at falls within
     * the requested window. Each row becomes one timeline event anchored at
     * its post time.
     *
     * Source = 'messages'; type = 'posted'. The event URL deep-links back to
     * TeamHub's home tab for this team (the message stream lives there).
     * No per-message scroll-to anchor exists yet, so the URL just opens the
     * team's home view — sufficient to find the post by date.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchMessageEvents(string $teamId, int $from, int $to): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id', 'subject', 'author_id', 'created_at', 'pinned')
            ->from('teamhub_messages')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->gte('created_at',
                $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('created_at',
                $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->executeQuery();

        $events  = [];
        // Deeplink back to the home tab; the stream lives there. No per-row
        // anchor exists yet, but the date alignment makes it obvious which
        // post the chip represents once the user lands on the home view.
        $homeUrl = '/apps/teamhub?team=' . rawurlencode($teamId);

        while ($row = $res->fetch()) {
            $postedAt = (int)$row['created_at'];
            $subject  = (string)($row['subject'] ?? '');
            // Subject can legitimately be empty for messages with body-only
            // content; fall back to a generic label so the chip still reads.
            if ($subject === '') {
                $subject = '(post)';
            }
            $events[] = [
                'id'      => 'msg-' . (int)$row['id'],
                'source'  => 'messages',
                'type'    => 'posted',
                'title'   => $subject,
                'date'    => (new \DateTimeImmutable('@' . $postedAt))->format('c'),
                'endDate' => null,
                'allDay'  => false,
                'url'     => $homeUrl,
                'meta'    => [
                    'authorId'  => (string)($row['author_id'] ?? ''),
                    'pinned'    => !empty($row['pinned']),
                    'messageId' => (int)$row['id'],
                ],
            ];
        }
        $res->closeCursor();

        $this->logger->debug('[TimelineService] Messages: window=[' . $from . ',' . $to . '] events=' . count($events), ['app' => Application::APP_ID]);
        return $events;
    }

    // =========================================================================
    // Milestones (v3.78.2)
    // =========================================================================

    /**
     * Fetch dated Timeline milestones for this team within [$from, $to].
     * Each milestone produces exactly one event, source='milestone',
     * type='milestone'. Unlike the other four sources, milestone events
     * are not rendered as chips inside a section band — the frontend
     * (templates/timeline.php) renders them as a full-height red marker
     * line spanning every band, similar to the "Today" line.
     *
     * Undated milestones (milestoneDate === null) are never returned here
     * — there is no x-position to plot them at. They remain visible in
     * Manage Team → Integration settings until an admin sets a date.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchMilestoneEvents(string $teamId, int $from, int $to): array {
        $rows = $this->milestoneMapper->findByTeam($teamId);

        $events = [];
        foreach ($rows as $row) {
            $ts = $row->getMilestoneDate();
            if ($ts === null || $ts < $from || $ts > $to) {
                continue;
            }
            $events[] = [
                'id'      => 'ms-' . $row->getId(),
                'source'  => 'milestone',
                'type'    => 'milestone',
                'title'   => $row->getLabel(),
                'date'    => (new \DateTimeImmutable('@' . $ts))->format('c'),
                'endDate' => null,
                'allDay'  => true,
                'url'     => null,
                'meta'    => [
                    'milestoneId' => $row->getId(),
                ],
            ];
        }

        $this->logger->debug('[TimelineService] Milestones: window=[' . $from . ',' . $to . '] events=' . count($events), ['app' => Application::APP_ID]);
        return $events;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Parse a mixed date value into a Unix timestamp.
     * Deck stores dates as ISO 8601 strings; created_at may be an int or string.
     * Returns null on failure.
     */
    private function parseTimestamp(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        // ISO 8601 string
        try {
            $dt = new \DateTimeImmutable((string)$value);
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
