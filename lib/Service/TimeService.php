<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\ProjectMapper;
use OCA\TeamHub\Db\ProjectMemberMapper;
use OCA\TeamHub\Db\TimeLog;
use OCA\TeamHub\Db\TimeLogMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TimeService — Execution-phase Time investment page (v3.96.0, Track E Session 5).
 *
 * DATA MODEL
 * ----------
 *   - teamhub_project.time_view_min_level      — single project-level floor
 *     gating who sees the Time tab (1=member, 4=moderator+, 8=admin-only).
 *   - teamhub_project_member                   — one row per participating
 *     user with `available_minutes` (0 = uncapped). Presence in this table
 *     also unlocks the tab for users below the view floor — matches how the
 *     Budget arc treats named editors.
 *   - teamhub_time_log                         — one row per logged block of
 *     work, attached to a Deck card (card_id) inside a stack (stack_id,
 *     denormalised at write). `user_id` is the person the time was worked BY;
 *     `created_by` is the actual submitter (differs on admin-on-behalf).
 *
 * All time is integer minutes — no float drift, portable across MySQL/MariaDB/Postgres.
 * Same integer-first rule the Budget arc's BIGINT-minor-units treatment
 * established.
 *
 * AUTH MODEL
 * ---------
 *   - Tab visibility                           — role level ≥ time_view_min_level
 *     OR the user has a teamhub_project_member row on this team.
 *   - Project-level writes  (setProjectConfig) — team admin (≥8).
 *   - Member CRUD           (upsertMember, removeMember) — team admin (≥8).
 *   - Log CRUD              — the logger (`user_id`) must be a live Deck-card
 *     assignee for that card. Only the target user themselves can log time
 *     for themselves; an admin can log on behalf of any project member. Edit
 *     and delete: the row's `created_by` OR a team admin.
 *
 * Deck-card assignee lookup reuses the four-variant fallback pattern from
 * TimelineService (introduced in 3.86.1 to handle table/column drift across
 * Deck versions). See fetchCardAssignees below.
 *
 * Error contract for TimeController:
 *   \InvalidArgumentException → 400 (bad input / unknown user / negative minutes)
 *   \Exception thrown from MemberService or auth branches → 403
 */
class TimeService {

    /** Team role levels used as time_view_min_level. */
    public const VALID_LEVELS = [1, 4, 8];

    /** Description max length matches the STRING(255) migration column. */
    public const DESCRIPTION_MAX_LEN = 255;

    /** Sensible upper bound (INT range is 35k+ hours). 30 days = 43,200 min. */
    public const MAX_MINUTES_PER_LOG = 43200;

    public function __construct(
        private ProjectMapper       $projectMapper,
        private ProjectMemberMapper $memberMapper,
        private TimeLogMapper       $logMapper,
        private TimelineService     $timelineService,
        private MemberService       $memberService,
        private AuditService        $auditService,
        private LicenseService      $licenseService,
        private IDBConnection       $db,
        private IUserSession        $userSession,
        private IUserManager        $userManager,
        private LoggerInterface     $logger,
    ) {}

    // ── READ ────────────────────────────────────────────────────────────────

    /**
     * Full Time page payload for $teamId. Membership + tab-visibility gated.
     *
     * @return array{
     *   isProject: bool,
     *   timeViewMinLevel: int,
     *   totalAvailableMinutes: int,
     *   totalLoggedMinutes: int,
     *   members: array<int, array<string, mixed>>,
     *   lanes: array<int, array<string, mixed>>
     * }
     */
    public function getProjectTime(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null || $project->getMode() !== 'advanced') {
            return $this->emptyEnvelope();
        }

        $currentUid   = $this->userSession->getUser()?->getUID() ?? '';
        $currentLevel = $this->currentUserLevel($teamId);

        // Reconcile-on-read: every effective team member gets a
        // teamhub_project_member row so they can log time immediately.
        // Owner + newly-added members flow in without any admin action;
        // access control lives on time_view_min_level + the integration
        // toggle, NOT on membership curation. Missing rows are inserted
        // with available_minutes = 0 (uncapped — accumulate only) and can
        // be capped later by an admin. See Budget's syncLanes for the same
        // reconcile pattern applied to Deck stacks.
        $this->reconcileMembers($teamId);

        // Tab-visibility check: role floor is authoritative. Every team
        // member is auto-added to teamhub_project_member (reconcile-on-read
        // above), so a "user is a project member" fallback here would
        // silently cancel the floor for every regular member. The intended
        // way to restrict logging is to raise time_view_min_level or turn
        // off the integration toggle in Manage Team → Integrations.
        if ($currentLevel < $project->getTimeViewMinLevel()) {
            return $this->emptyEnvelope();
        }

        $members = $this->memberMapper->findByTeam($teamId);
        $logs    = $this->logMapper->findByTeam($teamId);
        $stacks  = $this->timelineService->getDeckStacks($teamId);

        // Roll up by user (report grid) and by stack (per-lane column).
        $loggedByUser  = [];
        $loggedByStack = [];
        $totalLogged   = 0;
        foreach ($logs as $log) {
            $loggedByUser[$log->getUserId()]  = ($loggedByUser[$log->getUserId()]  ?? 0) + $log->getMinutes();
            $loggedByStack[$log->getStackId()] = ($loggedByStack[$log->getStackId()] ?? 0) + $log->getMinutes();
            $totalLogged += $log->getMinutes();
        }

        // Member rows for the report grid. Available may be 0 = uncapped.
        $memberList = [];
        $displayNames = [];
        $totalAvailable = 0;
        foreach ($members as $m) {
            $uid = $m->getUserId();
            $available = $m->getAvailableMinutes();
            $logged    = $loggedByUser[$uid] ?? 0;
            $totalAvailable += $available;
            $user = $this->userManager->get($uid);
            $displayName = $user !== null ? $user->getDisplayName() : $uid;
            $displayNames[$uid] = $displayName;
            $memberList[] = [
                'userId'           => $uid,
                'displayName'      => $displayName,
                'availableMinutes' => $available,
                'loggedMinutes'    => $logged,
                'remainingMinutes' => $available > 0 ? ($available - $logged) : null,
            ];
        }

        // Lane list — one row per Deck stack, in Deck's `order` (already sorted
        // by getDeckStacks). Includes stacks with zero logs so an empty lane
        // still shows up (matches the Budget page's per-lane rendering).
        $laneList = [];
        $stackTitles = [];
        foreach ($stacks as $stack) {
            $stackId = $stack['stackId'];
            $stackTitles[$stackId] = $stack['stackTitle'];
            $laneList[] = [
                'stackId'       => $stackId,
                'stackTitle'    => $stack['stackTitle'],
                'stackOrder'    => $stack['order'],
                'boardId'       => $stack['boardId'],
                'boardTitle'    => $stack['boardTitle'],
                'loggedMinutes' => $loggedByStack[$stackId] ?? 0,
            ];
        }

        // Fetch card titles for every card that has a log — one query, keyed
        // by the log rows' card_ids. Needed for the two report views which
        // render "User / Activity / Hours" (per-lane view) and
        // "Activity / Lane / Hours" (per-member view).
        $cardTitles = $this->fetchCardTitles(array_unique(array_map(
            fn($l) => $l->getCardId(), $logs
        )));

        // Serialize every log with its resolved names so the frontend can
        // render both views without a second fetch.
        $logList = [];
        foreach ($logs as $log) {
            $logList[] = [
                'id'          => $log->getId(),
                'cardId'      => $log->getCardId(),
                'cardTitle'   => $cardTitles[$log->getCardId()] ?? ('#' . $log->getCardId()),
                'stackId'     => $log->getStackId(),
                'stackTitle'  => $stackTitles[$log->getStackId()] ?? '',
                'userId'      => $log->getUserId(),
                'displayName' => $displayNames[$log->getUserId()] ?? $log->getUserId(),
                'minutes'     => $log->getMinutes(),
                'description' => $log->getDescription(),
                'workedAt'    => $log->getWorkedAt(),
                'createdBy'   => $log->getCreatedBy(),
            ];
        }

        return [
            'isProject'             => true,
            'timeViewMinLevel'      => $project->getTimeViewMinLevel(),
            'totalAvailableMinutes' => $totalAvailable,
            'totalLoggedMinutes'    => $totalLogged,
            'members'               => $memberList,
            'lanes'                 => $laneList,
            'logs'                  => $logList,
        ];
    }

    /**
     * Log drill-down for one project member. Returns the raw log rows plus a
     * per-card summary. Membership + tab-visibility gated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogsForMember(string $teamId, string $userId): array {
        $this->memberService->requireMemberLevel($teamId);
        $currentUid = $this->userSession->getUser()?->getUID() ?? '';

        // A non-admin caller can only see their own drill-down. Admins see anyone.
        $currentLevel = $this->currentUserLevel($teamId);
        if ($userId !== $currentUid && $currentLevel < 8) {
            throw new AccessDeniedException('You can only see your own time logs');
        }

        $logs = $this->logMapper->findByTeamAndUser($teamId, $userId);
        $out = [];
        foreach ($logs as $log) {
            $out[] = $this->serializeLog($log);
        }
        return $out;
    }

    /**
     * Cards the given user can log time on — i.e. Deck cards they are
     * currently assigned to inside this team's boards. Powers the "log time"
     * picker in ProjectTimeView.vue.
     *
     * @return array<int, array{cardId:int, cardTitle:string, stackId:int, stackTitle:string, boardTitle:string}>
     */
    public function loggableCardsForUser(string $teamId, string $userId): array {
        $this->memberService->requireMemberLevel($teamId);
        $currentUid = $this->userSession->getUser()?->getUID() ?? '';
        $currentLevel = $this->currentUserLevel($teamId);
        // Non-admins can only ask about themselves.
        if ($userId !== $currentUid && $currentLevel < 8) {
            throw new AccessDeniedException('You can only query your own loggable cards');
        }

        $stacks = $this->timelineService->getDeckStacks($teamId);
        if (empty($stacks)) {
            return [];
        }
        $stackMeta = [];
        foreach ($stacks as $s) {
            $stackMeta[$s['stackId']] = $s;
        }

        // Fetch cards in this team's stacks — same SELECT * approach as
        // TimelineService::fetchDeckEvents (bypasses introspection failures).
        $qb = $this->db->getQueryBuilder();
        $res = $qb->select('*')
            ->from('deck_cards')
            ->where($qb->expr()->in('stack_id',
                $qb->createNamedParameter(array_keys($stackMeta), IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery();

        $cards = [];
        while ($row = $res->fetch()) {
            if (!empty($row['deleted_at'])) continue;
            if (!empty($row['archived']) && (int)$row['archived'] === 1) continue;
            $cards[(int)$row['id']] = [
                'title'   => (string)($row['title'] ?? ''),
                'stackId' => (int)($row['stack_id'] ?? 0),
            ];
        }
        $res->closeCursor();
        if (empty($cards)) {
            return [];
        }

        $assigneesByCard = $this->fetchCardAssignees(array_keys($cards));

        $out = [];
        foreach ($cards as $cardId => $c) {
            $uids = $assigneesByCard[$cardId] ?? [];
            if (!in_array($userId, $uids, true)) continue;
            $stack = $stackMeta[$c['stackId']] ?? null;
            if ($stack === null) continue;
            $out[] = [
                'cardId'     => $cardId,
                'cardTitle'  => $c['title'],
                'stackId'    => $c['stackId'],
                'stackTitle' => $stack['stackTitle'],
                'boardTitle' => $stack['boardTitle'],
            ];
        }

        // Alphabetical by card title for a stable picker order.
        usort($out, fn($a, $b) => strnatcasecmp($a['cardTitle'], $b['cardTitle']));
        return $out;
    }

    /**
     * Lightweight "does this user see the Time tab?" for LayoutController.
     * Never throws — returns false on any failure so it's safe on the warm
     * layout-fetch path.
     *
     * Auth model (v3.96.0, revised): the role floor is authoritative.
     * Every team member is auto-added to teamhub_project_member via
     * reconcile-on-read in getProjectTime, so a project-member fallback
     * here would defeat the floor for every regular member. The way to
     * restrict logging is to raise time_view_min_level or disable the
     * integration. Named editors (Budget's mechanism) do not apply here —
     * time-tracking has no per-lane editor override.
     */
    public function canUserViewTimeTab(string $teamId, string $userId): bool {
        try {
            $project = $this->projectMapper->findByTeam($teamId);
            if ($project === null || $project->getMode() !== 'advanced') {
                return false;
            }
            $level = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $userId);
            if ($level <= 0) {
                return false;
            }
            return $level >= $project->getTimeViewMinLevel();
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TimeService] canUserViewTimeTab error: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    // ── WRITE: project config ───────────────────────────────────────────────

    public function setProjectConfig(string $teamId, int $timeViewMinLevel, string $actingUserId): array {
        $this->memberService->requireAdminLevel($teamId);

        $project = $this->projectMapper->findByTeam($teamId);
        if ($project === null) {
            throw new \InvalidArgumentException('This team is not a project');
        }
        if ($project->getMode() !== 'advanced') {
            throw new \InvalidArgumentException('Time investment applies to advanced projects only');
        }
        $this->validateLevel($timeViewMinLevel, 'timeViewMinLevel');

        $old = ['timeViewMinLevel' => $project->getTimeViewMinLevel()];
        $project->setTimeViewMinLevel($timeViewMinLevel);
        $project->setUpdatedAt(time());
        $this->projectMapper->update($project);

        $diff = $this->auditService->buildDiff($old, ['timeViewMinLevel' => $timeViewMinLevel]);
        if ($diff !== null) {
            $this->auditService->log(
                $teamId, 'project.time_config_changed', $actingUserId,
                'project', (string)$project->getId(), $diff,
            );
        }
        return $this->getProjectTime($teamId);
    }

    // ── WRITE: project members ──────────────────────────────────────────────

    public function upsertMember(string $teamId, string $userId, int $availableMinutes, string $actingUserId): array {
        $this->memberService->requireAdminLevel($teamId);
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        if ($this->userManager->get($userId) === null) {
            throw new \InvalidArgumentException('Unknown user: ' . $userId);
        }
        if ($availableMinutes < 0) {
            throw new \InvalidArgumentException('Available minutes cannot be negative');
        }

        $existing = $this->memberMapper->findByTeamAndUser($teamId, $userId);
        if ($existing === null) {
            $this->memberMapper->insertMember($teamId, $userId, $availableMinutes);
            $this->auditService->log(
                $teamId, 'project.time_member_added', $actingUserId,
                'project_member', $userId,
                ['availableMinutes' => $availableMinutes],
            );
        } else {
            $old = ['availableMinutes' => $existing->getAvailableMinutes()];
            $existing->setAvailableMinutes($availableMinutes);
            $existing->setUpdatedAt(time());
            $this->memberMapper->update($existing);
            $diff = $this->auditService->buildDiff($old, ['availableMinutes' => $availableMinutes]);
            if ($diff !== null) {
                $this->auditService->log(
                    $teamId, 'project.time_member_changed', $actingUserId,
                    'project_member', $userId, $diff,
                );
            }
        }
        return $this->getProjectTime($teamId);
    }

    public function removeMember(string $teamId, string $userId, string $actingUserId): array {
        $this->memberService->requireAdminLevel($teamId);
        // Row deletion only — the user's time logs remain (audit trail).
        $this->memberMapper->deleteByTeamAndUser($teamId, $userId);
        $this->auditService->log(
            $teamId, 'project.time_member_removed', $actingUserId,
            'project_member', $userId, [],
        );
        return $this->getProjectTime($teamId);
    }

    // ── WRITE: time logs ────────────────────────────────────────────────────

    public function addLog(
        string $teamId,
        int    $cardId,
        string $forUserId,
        int    $minutes,
        string $description,
        int    $workedAt,
        string $actingUserId
    ): array {
        // v3.100.0 — Track F soft-lock gate.
        $this->licenseService->gateAdvancedWrite($teamId);
        $this->memberService->requireMemberLevel($teamId);
        $forUserId   = trim($forUserId);
        $actingLevel = $this->currentUserLevel($teamId);

        if ($forUserId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        if ($this->userManager->get($forUserId) === null) {
            throw new \InvalidArgumentException('Unknown user: ' . $forUserId);
        }
        // On-behalf is admin-only. Members can only log for themselves.
        if ($forUserId !== $actingUserId && $actingLevel < 8) {
            throw new AccessDeniedException('Only admins can log time on behalf of another member');
        }
        $this->validateMinutes($minutes);
        $description = $this->normalizeDescription($description, /*required*/ false);
        $workedAt    = $this->normalizeWorkedAt($workedAt);

        // Look up the card's current stack + assignees.
        [$stackId, $assignees] = $this->fetchCardStackAndAssignees($cardId);
        if ($stackId === 0) {
            throw new \InvalidArgumentException('Deck card not found');
        }
        // Verify the card belongs to one of this team's stacks — refuses
        // cross-team log injection.
        $teamStackIds = array_column($this->timelineService->getDeckStacks($teamId), 'stackId');
        if (!in_array($stackId, $teamStackIds, true)) {
            throw new \InvalidArgumentException('Card is not part of this project');
        }
        // The person the time is FOR must be a live assignee of that card.
        // Not the acting user — an admin logging on behalf still needs the
        // *forUser* to actually be assigned.
        if (!in_array($forUserId, $assignees, true)) {
            throw new \InvalidArgumentException('User is not an assignee of that card');
        }

        $log = $this->logMapper->insertLog(
            $teamId, $cardId, $stackId, $forUserId, $minutes, $description, $workedAt, $actingUserId
        );
        $this->auditService->log(
            $teamId, 'project.time_log_added', $actingUserId,
            'time_log', (string)$log->getId(),
            ['cardId' => $cardId, 'forUserId' => $forUserId, 'minutes' => $minutes],
        );
        return $this->getProjectTime($teamId);
    }

    public function updateLog(
        string $teamId,
        int    $logId,
        int    $minutes,
        string $description,
        int    $workedAt,
        string $actingUserId
    ): array {
        $log = $this->requireOwnLogOrAdmin($teamId, $logId, $actingUserId);
        $this->validateMinutes($minutes);
        $description = $this->normalizeDescription($description, /*required*/ false);
        $workedAt    = $this->normalizeWorkedAt($workedAt);

        $old = [
            'minutes'     => $log->getMinutes(),
            'description' => $log->getDescription(),
            'workedAt'    => $log->getWorkedAt(),
        ];
        $log->setMinutes($minutes);
        $log->setDescription($description);
        $log->setWorkedAt($workedAt);
        $log->setUpdatedAt(time());
        $this->logMapper->update($log);

        $diff = $this->auditService->buildDiff($old, [
            'minutes'     => $minutes,
            'description' => $description,
            'workedAt'    => $workedAt,
        ]);
        if ($diff !== null) {
            $this->auditService->log(
                $teamId, 'project.time_log_updated', $actingUserId,
                'time_log', (string)$log->getId(), $diff,
            );
        }
        return $this->getProjectTime($teamId);
    }

    public function deleteLog(string $teamId, int $logId, string $actingUserId): array {
        $log = $this->requireOwnLogOrAdmin($teamId, $logId, $actingUserId);
        $this->logMapper->delete($log);
        $this->auditService->log(
            $teamId, 'project.time_log_deleted', $actingUserId,
            'time_log', (string)$logId, [],
        );
        return $this->getProjectTime($teamId);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Reconcile teamhub_project_member rows against the team's effective
     * membership. Anyone with team access — direct, via a group, or via a
     * sub-team — gets a project_member row so they can log time
     * immediately. Existing rows are left untouched (available_minutes is
     * an admin-managed value). Deletions of ex-members are NOT performed
     * here — their historic logs stay attributable and the row is harmless
     * (they no longer show in the UI because their `existsForUser` check
     * still returns true from an admin perspective but the tab-visibility
     * gate stops them accessing anything).
     *
     * Silent-degrades on any DB error — reconcile is a nice-to-have, not a
     * correctness requirement (admin can always add members manually).
     */
    private function reconcileMembers(string $teamId): void {
        try {
            $existing = [];
            foreach ($this->memberMapper->findByTeam($teamId) as $m) {
                $existing[$m->getUserId()] = true;
            }
            $effective = $this->memberService->getAllEffectiveMembers($teamId);
            foreach ($effective as $member) {
                $uid = $member['userId'] ?? '';
                if ($uid === '' || isset($existing[$uid])) {
                    continue;
                }
                try {
                    $this->memberMapper->insertMember($teamId, $uid, 0);
                    $existing[$uid] = true;
                } catch (\Throwable $e) {
                    // Race with a parallel request; next fetch fixes it.
                    $this->logger->debug('[TeamHub][TimeService] reconcileMembers: insert skipped: ' . $e->getMessage(), [
                        'app' => Application::APP_ID,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TimeService] reconcileMembers: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Fetch card titles for a set of Deck card ids in one query. Returns
     * `[cardId => title]`. Missing rows drop out; caller falls back to
     * "#{id}" for orphans (card deleted since log was written).
     *
     * @param int[] $cardIds
     * @return array<int, string>
     */
    private function fetchCardTitles(array $cardIds): array {
        if (empty($cardIds)) {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $res = $qb->select('id', 'title')
                ->from('deck_cards')
                ->where($qb->expr()->in('id',
                    $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            $out = [];
            while ($row = $res->fetch()) {
                $out[(int)$row['id']] = (string)($row['title'] ?? '');
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function requireOwnLogOrAdmin(string $teamId, int $logId, string $actingUserId): TimeLog {
        $log = $this->logMapper->findById($logId);
        if ($log === null || $log->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Time log not found in this team');
        }
        $this->memberService->requireMemberLevel($teamId);
        // Row's created_by (the actual submitter) can always edit their own.
        // Otherwise the caller must be a team admin.
        if ($log->getCreatedBy() === $actingUserId) {
            return $log;
        }
        if ($this->currentUserLevel($teamId) < 8) {
            throw new AccessDeniedException('You can only edit time logs you created');
        }
        return $log;
    }

    /**
     * Look up a card's stack and its assignee UIDs. Returns [0, []] on miss.
     *
     * @return array{0:int, 1:array<int,string>}
     */
    private function fetchCardStackAndAssignees(int $cardId): array {
        $qb = $this->db->getQueryBuilder();
        try {
            $res = $qb->select('*')
                ->from('deck_cards')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
        } catch (\Throwable $e) {
            return [0, []];
        }
        if ($row === false) {
            return [0, []];
        }
        if (!empty($row['deleted_at'])) {
            return [0, []];
        }
        $stackId  = (int)($row['stack_id'] ?? 0);
        $byCard   = $this->fetchCardAssignees([$cardId]);
        $assignees = $byCard[$cardId] ?? [];
        return [$stackId, $assignees];
    }

    /**
     * Batched card→assignee-UIDs lookup. Mirrors TimelineService's
     * four-variant fallback (3.86.1) so we work across Deck's historical
     * table+column drift without gating on DbIntrospectionService.
     *
     * @param int[] $cardIds
     * @return array<int, string[]>  keyed by card_id, values are UID lists
     */
    private function fetchCardAssignees(array $cardIds): array {
        if (empty($cardIds)) {
            return [];
        }
        $variants = [
            ['deck_card_assigned_users', 'participant_uid'],
            ['deck_card_assigned_users', 'participant'],
            ['deck_assigned_users',      'participant_uid'],
            ['deck_assigned_users',      'participant'],
        ];
        foreach ($variants as [$table, $participantColumn]) {
            try {
                return $this->tryFetchCardAssignees($table, $participantColumn, $cardIds, /*withType*/ true);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, '42S02') || str_contains($msg, 'not found')) {
                    continue;
                }
                try {
                    return $this->tryFetchCardAssignees($table, $participantColumn, $cardIds, /*withType*/ false);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        $this->logger->debug('[TeamHub][TimeService] fetchCardAssignees: no variant hit', [
            'app' => Application::APP_ID,
        ]);
        return [];
    }

    private function tryFetchCardAssignees(string $table, string $participantColumn, array $cardIds, bool $withType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('card_id', $participantColumn)
            ->from($table)
            ->where($qb->expr()->in('card_id',
                $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));
        if ($withType) {
            $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
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

    private function currentUserLevel(string $teamId): int {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('User not authenticated');
        }
        return $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
    }

    private function serializeLog(TimeLog $log): array {
        return [
            'id'          => $log->getId(),
            'cardId'      => $log->getCardId(),
            'stackId'     => $log->getStackId(),
            'userId'      => $log->getUserId(),
            'minutes'     => $log->getMinutes(),
            'description' => $log->getDescription(),
            'workedAt'    => $log->getWorkedAt(),
            'createdBy'   => $log->getCreatedBy(),
            'createdAt'   => $log->getCreatedAt(),
            'updatedAt'   => $log->getUpdatedAt(),
        ];
    }

    private function emptyEnvelope(): array {
        return [
            'isProject'             => false,
            'timeViewMinLevel'      => 1,
            'totalAvailableMinutes' => 0,
            'totalLoggedMinutes'    => 0,
            'members'               => [],
            'lanes'                 => [],
        ];
    }

    private function validateLevel(int $level, string $field): void {
        if (!in_array($level, self::VALID_LEVELS, true)) {
            throw new \InvalidArgumentException(
                $field . ' must be one of: ' . implode(', ', self::VALID_LEVELS)
            );
        }
    }

    private function validateMinutes(int $minutes): void {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Minutes must be greater than zero');
        }
        if ($minutes > self::MAX_MINUTES_PER_LOG) {
            throw new \InvalidArgumentException(
                'Single log cannot exceed ' . self::MAX_MINUTES_PER_LOG . ' minutes'
            );
        }
    }

    private function normalizeDescription(string $description, bool $required): string {
        $description = trim($description);
        if ($required && $description === '') {
            throw new \InvalidArgumentException('Description is required');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LEN) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_LEN);
        }
        return $description;
    }

    /**
     * Normalise to UTC-midnight of the day the client sent. Matches the
     * incurred_at convention on teamhub_expense.
     */
    private function normalizeWorkedAt(int $workedAt): int {
        if ($workedAt <= 0) {
            throw new \InvalidArgumentException('workedAt is required');
        }
        return (int)(floor($workedAt / 86400) * 86400);
    }
}
