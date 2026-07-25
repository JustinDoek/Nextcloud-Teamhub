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
 * Decision events (option B): each decision produces up to TWO
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
 * Deck events: each card produces up to FOUR events — "created" at
 * last_modified (Deck has no created_at column, so this is a proxy), "start"
 * at startdate (Session 3, NC 34 / Deck 1.16+ only — deck_cards.startdate,
 * a nullable `datetime` column; absent entirely on older Deck, never
 * backfilled with a proxy the way "created" is), "due" at due_date (when
 * set), and "completed" at the done timestamp. meta also carries
 * blockedByCardIds (v3.78.8, NC 34 / Deck 1.18+ only) — the Deck card IDs
 * this card depends on, used by the frontend's opt-in "Deck card
 * dependencies" overlay. stackId/stackOrder (Session 3, Planning-phase
 * swimlane view) identify the card's Deck stack so the frontend can group
 * events into lanes without string-matching stackName, which isn't
 * guaranteed unique across boards. See getDeckStacks() for the full,
 * date-independent lane list (including empty lanes). The swimlane view's
 * Gantt bar spans a card's "start" and "due" events when both exist, and
 * falls back to a single due-only marker on installs/cards without a start
 * date.
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
        $this->logger->debug('[TeamHub][TimelineService] getEvents teamId=' . $teamId . ' from=' . $from . ' to=' . $to, ['app' => Application::APP_ID]);

        $events = [];

        // Each source is independently wrapped so a failure in one never
        // blocks the others.
        try {
            $events = array_merge($events, $this->fetchCalendarEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TimelineService] calendar fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchDecisionEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TimelineService] decisions fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchDeckEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TimelineService] deck fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchMessageEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TimelineService] messages fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        try {
            $events = array_merge($events, $this->fetchMilestoneEvents($teamId, $from, $to));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TimelineService] milestones fetch failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        // Sort ascending by anchor date so the frontend receives a stable order.
        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Resolve any raw UIDs in meta to display names so the popup can show
        // "Jan Janssen" instead of "jjanssen". One batched lookup pass —
        // IUserManager::get() hits an in-process cache for repeated UIDs.
        $this->resolveDisplayNames($events);

        $this->logger->debug('[TeamHub][TimelineService] getEvents: returning ' . count($events) . ' events', ['app' => Application::APP_ID]);
        return $events;
    }

    /**
     * Truncate free-form text to a popup-friendly length, normalising
     * whitespace (CRLF, tabs, runs of spaces) so the popup doesn't show
     * raw layout artefacts. Appends an ellipsis when truncated.
     */
    private function truncateForPopup(string $text, int $max = 280): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Walk every event and add `*Name` display-name companions for any
     * known UID-bearing meta keys. Lookups go through IUserManager which
     * keeps its own per-request cache, so repeat UIDs across events are
     * cheap. Missing/deleted users keep the raw UID as the display name.
     *
     * @param array<int, array<string, mixed>> $events  Mutated in place
     */
    private function resolveDisplayNames(array &$events): void {
        try {
            $userManager = $this->container->get(\OCP\IUserManager::class);
        } catch (\Throwable) {
            return; // No user manager → leave raw UIDs in place.
        }

        $cache = [];
        $resolve = function (string $uid) use (&$cache, $userManager): string {
            if ($uid === '') {
                return '';
            }
            if (isset($cache[$uid])) {
                return $cache[$uid];
            }
            try {
                $u = $userManager->get($uid);
                $name = $u ? (string)$u->getDisplayName() : $uid;
            } catch (\Throwable) {
                $name = $uid;
            }
            return $cache[$uid] = $name;
        };

        // (uid-meta-key => display-name-meta-key) — extend here as new
        // event types start surfacing actors in their meta.
        $uidKeys = [
            'authorId'   => 'authorName',
            'proposedBy' => 'proposedByName',
            'decidedBy'  => 'decidedByName',
            'createdBy'  => 'createdByName',
        ];

        foreach ($events as &$ev) {
            if (!isset($ev['meta']) || !is_array($ev['meta'])) {
                continue;
            }
            foreach ($uidKeys as $uidKey => $nameKey) {
                if (!empty($ev['meta'][$uidKey])) {
                    $ev['meta'][$nameKey] = $resolve((string)$ev['meta'][$uidKey]);
                }
            }
            // Array-of-UIDs companions (Deck card assignees).
            if (!empty($ev['meta']['assignees']) && is_array($ev['meta']['assignees'])) {
                $names = [];
                foreach ($ev['meta']['assignees'] as $uid) {
                    if (is_string($uid) && $uid !== '') {
                        $names[] = $resolve($uid);
                    }
                }
                $ev['meta']['assigneeNames'] = $names;
            }
        }
        unset($ev);
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

                    // Rich popup fields (v3.85.8): pulled from the same VEVENT
                    // so no extra query. Description is truncated for popup
                    // display; attendees/organizer give context without
                    // requiring the user to open the calendar app.
                    $description = isset($vevent->DESCRIPTION)
                        ? $this->truncateForPopup((string)$vevent->DESCRIPTION)
                        : null;

                    $organizer = null;
                    if (isset($vevent->ORGANIZER)) {
                        $cn = (string)($vevent->ORGANIZER['CN'] ?? '');
                        if ($cn !== '') {
                            $organizer = $cn;
                        } else {
                            $mailto = (string)$vevent->ORGANIZER;
                            $organizer = preg_replace('/^mailto:/i', '', $mailto);
                        }
                    }

                    $attendeeCount = 0;
                    if (isset($vevent->ATTENDEE)) {
                        foreach ($vevent->ATTENDEE as $_) {
                            $attendeeCount++;
                        }
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
                            'calendarName'  => $calName,
                            'location'      => isset($vevent->LOCATION) ? (string)$vevent->LOCATION : null,
                            'description'   => $description,
                            'organizer'     => $organizer,
                            'attendeeCount' => $attendeeCount,
                        ],
                    ];

                } catch (\Throwable $e) {
                    $this->logger->debug('[TeamHub][TimelineService] skipped calendar object', [
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
                // Popup actors (v3.85.8). Raw UIDs; resolveDisplayNames()
                // (called from getEvents) will add *Name companions.
                'proposedBy'    => (string)($row['proposed_by'] ?? ''),
                'decidedBy'     => (string)($row['answered_by'] ?? ''),
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
     * Resolve the Deck board(s) connected to $teamId and every non-deleted
     * stack on them. Shared by fetchDeckEvents() (date-windowed card fetch)
     * and getDeckStacks() (the full, date-independent lane list used by the
     * Planning-phase swimlane view — a stack with zero cards in the current
     * window still needs to render as an empty lane).
     *
     * PRIMARY board source: TeamHub's own registry (teamhub_team_app_resources).
     * FALLBACK: scan the Deck ACL tables (deck_board_acl → deck_acl, type=7
     * =circle, participant=teamId). Won't hurt when both are present — union.
     *
     * @return array{stacks: array<int, array{board_id:int, board_title:string, title:string, order:int|null}>, boardIds: array<int, int>}
     */
    private function resolveTeamDeckStacks(string $teamId): array {
        $boardIds = [];

        // ── 1a. Registry (primary)
        try {
            $registryRows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'deck');
            foreach ($registryRows as $row) {
                $boardIds[(int)$row->getResourceId()] = true;
            }
            $this->logger->debug('[TeamHub][TimelineService] Deck: registry yielded ' . count($registryRows) . ' board(s) for team ' . $teamId, ['app' => Application::APP_ID]);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TimelineService] Deck: registry lookup failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
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
                $this->logger->debug('[TeamHub][TimelineService] Deck: ACL ' . $aclTable . ' yielded ' . $count . ' rows', ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TimelineService] Deck: ACL ' . $aclTable . ' not present (' . $e->getMessage() . ')', ['app' => Application::APP_ID]);
            }
        }

        if (empty($boardIds)) {
            $this->logger->debug('[TeamHub][TimelineService] Deck: no boards found for team ' . $teamId, ['app' => Application::APP_ID]);
            return ['stacks' => [], 'boardIds' => []];
        }

        $boardIdList = array_keys($boardIds);
        $this->logger->debug('[TeamHub][TimelineService] Deck: total ' . count($boardIdList) . ' board(s): ' . implode(',', $boardIdList), ['app' => Application::APP_ID]);

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

        // Deck's stack-ordering column is literally named `order` (a reserved
        // word in most SQL dialects — safe here since it's only ever
        // referenced through IQueryBuilder, never raw SQL, which handles
        // identifier quoting on both MySQL/MariaDB and Postgres). Some
        // installs may have stacks with order IS NULL (predate the
        // BackfillDeckStackOrder repair step) — sorted deterministically in
        // getDeckStacks() rather than relying on DB-default NULL ordering.
        $stackHasOrder = in_array('order', $stackCols, true);

        $sqb        = $this->db->getQueryBuilder();
        $selectCols = ['s.id', 's.title', 's.board_id', 'b.title AS board_title'];
        if ($stackHasOrder) {
            $selectCols[] = 's.order';
        }
        $sqb->select(...$selectCols)
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
                'order'       => isset($srow['order']) ? (int)$srow['order'] : null,
            ];
        }
        $sres->closeCursor();

        $this->logger->debug('[TeamHub][TimelineService] Deck: found ' . count($stacks) . ' stack(s) across ' . count($boardIdList) . ' board(s)', ['app' => Application::APP_ID]);

        return ['stacks' => $stacks, 'boardIds' => $boardIdList];
    }

    /**
     * Ordered list of Deck stacks (workstreams) for $teamId's connected
     * board(s), for the Planning-phase swimlane view. Independent of any
     * date window — a stack with zero cards in the currently-viewed range
     * still needs to render as an (empty) lane, so this doesn't reuse
     * fetchDeckEvents()'s card-filtered result.
     *
     * Sorted by Deck's own stack order (NULLs last, tie-broken by stackId)
     * so lane order matches the order stacks appear in the Deck app itself
     * — including the "Project management" stack (v3.90.0), which has no
     * special marker beyond its order position and title.
     *
     * @return array<int, array{stackId:int, boardId:int, boardTitle:string, stackTitle:string, order:int|null}>
     */
    public function getDeckStacks(string $teamId): array {
        if (!$this->appManager->isInstalled('deck')) {
            return [];
        }

        $resolved = $this->resolveTeamDeckStacks($teamId);

        $list = [];
        foreach ($resolved['stacks'] as $stackId => $stack) {
            $list[] = [
                'stackId'    => $stackId,
                'boardId'    => $stack['board_id'],
                'boardTitle' => $stack['board_title'],
                'stackTitle' => $stack['title'],
                'order'      => $stack['order'],
            ];
        }

        usort($list, function (array $a, array $b): int {
            if ($a['order'] === null && $b['order'] !== null) return 1;
            if ($a['order'] !== null && $b['order'] === null) return -1;
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return $a['stackId'] <=> $b['stackId'];
        });

        return $list;
    }

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
            $this->logger->debug('[TeamHub][TimelineService] Deck: app not installed, skipping', ['app' => Application::APP_ID]);
            return [];
        }

        $this->logger->debug('[TeamHub][TimelineService] Deck: fetching for teamId=' . $teamId, ['app' => Application::APP_ID]);

        $resolved    = $this->resolveTeamDeckStacks($teamId);
        $stacks      = $resolved['stacks'];
        $boardIdList = $resolved['boardIds'];

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
                $this->logger->debug('[TeamHub][TimelineService] Deck: deck_cards row keys = ' . implode(',', array_keys($crow))
                    . ' | sample: last_modified=' . var_export($crow['last_modified'] ?? '<missing>', true)
                    . ' duedate=' . var_export($crow['duedate'] ?? '<missing>', true)
                    . ' startdate=' . var_export($crow['startdate'] ?? '<missing>', true),
                    ['app' => Application::APP_ID]);
            }

            if (!empty($crow['deleted_at'])) { $skippedDeleted++; continue; }
            if (!empty($crow['archived']) && (int)$crow['archived'] === 1) { $skippedArchived++; continue; }

            $cardId    = (int)$crow['id'];
            $allCardIds[] = $cardId;
            $stackId   = (int)$crow['stack_id'];
            $stack     = $stacks[$stackId] ?? ['board_title' => '', 'title' => '', 'board_id' => 0];
            $title     = (string)$crow['title'];

            // Deck stores card descriptions in `description` (TEXT, Markdown).
            // Truncate for popup display; full content is one click away via
            // the "Open in Deck" action.
            $description = isset($crow['description']) && $crow['description'] !== ''
                ? $this->truncateForPopup((string)$crow['description'])
                : null;

            $meta = [
                'boardName'   => $stack['board_title'],
                'stackName'   => $stack['title'],
                'stackId'     => $stackId,
                'stackOrder'  => $stack['order'] ?? null,
                'cardId'      => $cardId,
                'description' => $description,
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

            // Event 2 — "Start" at the card's planned start date (Session 3,
            // Planning-phase swimlane view). NC 34 / Deck 1.16+ only —
            // deck_cards.startdate, added via Deck migration
            // Version11002Date20260312000000.php as a nullable `datetime`
            // column — unlike duedate, which is a plain integer column.
            // parseTimestamp() already handles both raw ints and datetime
            // strings, so no extra parsing branch is needed here. Absent
            // entirely on older Deck installs (SELECT * simply has no
            // 'startdate' key there, same degrade-gracefully convention as
            // every other optional column in this method) — deliberately NOT
            // backfilled with a proxy the way `created` uses last_modified,
            // by design: don't invent a concept newer Deck already
            // owns. The frontend's Gantt bar falls back to a due-only marker
            // when no start event exists for a card.
            $startRaw = $crow['startdate'] ?? null;
            if ($startRaw !== null && $startRaw !== '' && $startRaw !== 0 && $startRaw !== '0') {
                $startTs = $this->parseTimestamp($startRaw);
                if ($startTs !== null && $startTs >= $from && $startTs <= $to) {
                    $events[] = [
                        'id'      => 'deck-' . $cardId . '-start',
                        'source'  => 'deck',
                        'type'    => 'start',
                        'title'   => $title,
                        'date'    => (new \DateTimeImmutable('@' . $startTs))->format('c'),
                        'endDate' => null,
                        'allDay'  => true,
                        'url'     => $deckCardUrl,
                        'meta'    => array_merge($meta, ['eventRole' => 'start']),
                    ];
                    $emittedEither = true;
                }
            }

            // Event 3 — "Due" at due date. Deck stores this as an integer Unix
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

            // Event 4 — "Completed" at the done timestamp.
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
                           && ($startRaw === null   || $startRaw === ''   || $startRaw === 0   || $startRaw === '0')
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

        // ── Card assignees (v3.85.9; hardened v3.85.11) ─────────────────────
        // Deck has shipped two table names and two participant-column names
        // for the same data over its history:
        //   newer (≈Deck 1.6+):  deck_card_assigned_users.participant_uid
        //   older:               deck_assigned_users.participant
        // Earlier this fetch hard-coded the newer pair, so installs running
        // either older Deck — or even current Deck where the column simply
        // hasn't been renamed yet — silently returned no assignees and the
        // popover never showed an "Assigned to" row. Detect both via
        // DbIntrospectionService and use whichever exists. If a `type`
        // column is present (0=user, 1=group, 2=circle on Deck's convention)
        // filter to user assignments only so group/circle labels don't get
        // rendered as if they were people.
        if (!empty($allCardIds)) {
            // Don't gate on DbIntrospectionService — it silently returns [] for
            // tables that exist-but-are-empty when Strategy 2 (SELECT * LIMIT 1)
            // returns no rows AND Strategy 3 (INFORMATION_SCHEMA) is restricted
            // or fails. That swallowed the lookup on at least one production
            // install (see log: 8 cards, no "Deck assignees" line emitted at all).
            //
            // Instead, try every plausible (table, participantColumn) pair
            // directly; the same pattern fetchDeckEvents already uses for the
            // ACL tables. A SQLSTATE[42S02] "Base table or view not found" just
            // bumps us to the next variant. The Upcoming Tasks widget gets the
            // same data via Deck's REST API (/apps/deck/api/v1.0/boards/{id}/
            // stacks → card.assignedUsers), but server-side loopback HTTP is
            // forbidden per SKILLS.md, so a direct DB read is the way.
            $variants = [
                ['deck_card_assigned_users', 'participant_uid'],
                ['deck_card_assigned_users', 'participant'],
                ['deck_assigned_users',      'participant_uid'],
                ['deck_assigned_users',      'participant'],
            ];

            $this->logger->debug('[TeamHub][TimelineService] Deck assignees: starting lookup'
                . ' cards=' . count(array_unique($allCardIds)),
                ['app' => Application::APP_ID]);

            $assigneesByCard = [];
            $hit = null;
            foreach ($variants as [$aTable, $partCol]) {
                try {
                    // Try WITH a type filter first (Deck convention: 0=user,
                    // 1=group, 2=circle). If the type column doesn't exist on
                    // this version, the query throws and we retry without it.
                    $bound = $this->fetchDeckAssigneesForVariant(
                        $aTable, $partCol, array_unique($allCardIds), true
                    );
                } catch (\Throwable $e) {
                    // Two failure modes: missing table (try next variant) or
                    // missing `type` column (retry same variant unfiltered).
                    $msg = $e->getMessage();
                    if (str_contains($msg, '42S02') || str_contains($msg, 'not found')) {
                        $this->logger->debug('[TeamHub][TimelineService] Deck assignees: ' . $aTable . ' not present, trying next variant',
                            ['app' => Application::APP_ID]);
                        continue;
                    }
                    try {
                        $bound = $this->fetchDeckAssigneesForVariant(
                            $aTable, $partCol, array_unique($allCardIds), false
                        );
                    } catch (\Throwable $e2) {
                        $this->logger->debug('[TeamHub][TimelineService] Deck assignees: ' . $aTable . '/' . $partCol
                            . ' failed both with and without type filter: ' . $e2->getMessage(),
                            ['app' => Application::APP_ID]);
                        continue;
                    }
                }

                $assigneesByCard = $bound;
                $hit = $aTable . '/' . $partCol;
                break;
            }

            $this->logger->debug('[TeamHub][TimelineService] Deck assignees: lookup done'
                . ' hit=' . ($hit ?: '<none>')
                . ' cardsWithAssignees=' . count($assigneesByCard),
                ['app' => Application::APP_ID]);

            if (!empty($assigneesByCard)) {
                foreach ($events as &$ev) {
                    if ($ev['source'] === 'deck'
                        && isset($ev['meta']['cardId'])
                        && isset($assigneesByCard[$ev['meta']['cardId']])
                    ) {
                        $ev['meta']['assignees'] = $assigneesByCard[$ev['meta']['cardId']];
                    }
                }
                unset($ev);
            }
        }

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
        // v4.3.18 — dropped the DbIntrospectionService::getTableColumns
        // pre-check that used to gate this whole block. On NC 34 that
        // helper returned [] even when the table existed (its strategies
        // all failed against NC 34's schema layer), so the dep-line
        // pipeline silently skipped and no metadata reached the
        // frontend. Just attempt the SELECT directly — a missing table
        // throws SQLSTATE[42S02] and we swallow it, same pattern
        // fetchDeckEvents uses for its `SELECT *` from `deck_cards`.
        if (!empty($allCardIds)) {
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
                    // v4.3.15 — attach blockedByCardIds to EVERY event
                    // for a card that has dependencies, not just the
                    // 'created' event. Previously the frontend only
                    // rendered dep lines when a card's 'created' event
                    // fell inside the visible time window, so a card
                    // created weeks ago with dependencies never showed
                    // a connector line even when its due/start bar was
                    // clearly visible on the current-week view. The
                    // frontend now dedupes by cardId, so multiple events
                    // carrying the same meta don't produce duplicate
                    // edges.
                    foreach ($events as &$ev) {
                        if ($ev['source'] === 'deck'
                            && isset($blockedByMap[$ev['meta']['cardId']])
                        ) {
                            $ev['meta']['blockedByCardIds'] = $blockedByMap[$ev['meta']['cardId']];
                        }
                    }
                    unset($ev);
                }

                $this->logger->debug('[TeamHub][TimelineService] Deck: card dependencies — '
                    . $depRowCount . ' row(s) across ' . count($blockedByMap) . ' blocked card(s)',
                    ['app' => Application::APP_ID]);
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][TimelineService] Deck: dependency fetch failed: ' . $e->getMessage(),
                    ['app' => Application::APP_ID]);
            }
        }

        $this->logger->debug('[TeamHub][TimelineService] Deck: window=[' . $from . ',' . $to . '] cardsTotal=' . $totalCards
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
        // v4.3.18 — DbIntrospection returned [] for deck_dependent_cards
        // on NC 34 even when the table existed. Probe with a real
        // SELECT LIMIT 0 instead: table absent → SQLSTATE[42S02],
        // table present → returns no rows but succeeds.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('card_id')
                ->from('deck_dependent_cards')
                ->setMaxResults(0);
            $r = $qb->executeQuery();
            $r->closeCursor();
            return true;
        } catch (\Throwable) {
            return false;
        }
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
        $res = $qb->select('id', 'subject', 'message', 'author_id', 'created_at', 'pinned')
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
            $body    = (string)($row['message'] ?? '');
            $snippet = $body !== '' ? $this->truncateForPopup($body) : null;
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
                    'snippet'   => $snippet,
                ],
            ];
        }
        $res->closeCursor();

        $this->logger->debug('[TeamHub][TimelineService] Messages: window=[' . $from . ',' . $to . '] events=' . count($events), ['app' => Application::APP_ID]);
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
                    'createdBy'   => method_exists($row, 'getCreatedBy') ? (string)$row->getCreatedBy() : '',
                ],
            ];
        }

        $this->logger->debug('[TeamHub][TimelineService] Milestones: window=[' . $from . ',' . $to . '] events=' . count($events), ['app' => Application::APP_ID]);
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

    /**
     * Run the Deck-assignees query for one (table, participantColumn) variant.
     * Throws on missing-table or missing-column SQL errors so the caller can
     * decide whether to bump variants or retry without the type filter.
     *
     * @param int[] $cardIds
     * @return array<int, string[]>  card_id => list of participant identifiers
     */
    private function fetchDeckAssigneesForVariant(
        string $table,
        string $participantColumn,
        array  $cardIds,
        bool   $withTypeFilter
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('card_id', $participantColumn)
            ->from($table)
            ->where($qb->expr()->in('card_id',
                $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));
        if ($withTypeFilter) {
            // Deck convention: type=0 user, 1 group, 2 circle. Filter so a
            // group label isn't rendered as if it were a person.
            $qb->andWhere($qb->expr()->eq('type',
                $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        $res = $qb->executeQuery();
        $out = [];
        while ($row = $res->fetch()) {
            $uid = (string)($row[$participantColumn] ?? '');
            if ($uid !== '') {
                $out[(int)$row['card_id']][] = $uid;
            }
        }
        $res->closeCursor();
        return $out;
    }
}
