<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionTaskMapper;
use OCA\TeamHub\Db\DecisionMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for decision ↔ task links (Session B).
 */
class DecisionTaskService {

    private DecisionTaskMapper  $taskMapper;
    private DecisionMapper      $decisionMapper;
    private MemberService       $memberService;
    private DecisionTeamService $teamService;
    private DecisionAuditService $auditService;
    private DeckService         $deckService;
    private IDBConnection       $db;
    private IUserSession        $userSession;
    private LoggerInterface     $logger;

    public function __construct(
        DecisionTaskMapper   $taskMapper,
        DecisionMapper       $decisionMapper,
        MemberService        $memberService,
        DecisionTeamService  $teamService,
        DecisionAuditService $auditService,
        DeckService          $deckService,
        IDBConnection        $db,
        IUserSession         $userSession,
        LoggerInterface      $logger,
    ) {
        $this->taskMapper      = $taskMapper;
        $this->decisionMapper  = $decisionMapper;
        $this->memberService   = $memberService;
        $this->teamService     = $teamService;
        $this->auditService    = $auditService;
        $this->deckService     = $deckService;
        $this->db              = $db;
        $this->userSession     = $userSession;
        $this->logger          = $logger;
    }

    /**
     * List all task links for a decision, enriched with Deck card metadata.
     *
     * For each task whose path matches the pattern apps/deck/board/{b}/card/{c},
     * we look up the card in Deck and attach:
     *   - isDone  (bool): true when the card is archived or in a done stack
     *   - cardTitle (string|null): the Deck card title if available
     *
     * Non-Deck tasks (plain URLs, other paths) get isDone=null so the
     * frontend knows there is no status to display.
     *
     * Any team member can call this endpoint.
     */
    public function listForDecision(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);
        $d = $this->decisionMapper->findById($decisionId);
        if ($d === null || $d->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found in this team');
        }
        $rows = $this->taskMapper->findByDecision($decisionId);

        // Enrich Deck card rows with live done-status from Deck.
        $cardIds = [];
        foreach ($rows as $row) {
            $cardId = $this->extractDeckCardId($row['task_path']);
            if ($cardId !== null) {
                $cardIds[] = $cardId;
            }
        }

        $cardInfo = [];
        if (!empty($cardIds)) {
            $cardInfo = $this->deckService->getCardsByIds($cardIds);
        }

        foreach ($rows as &$row) {
            $cardId = $this->extractDeckCardId($row['task_path']);
            if ($cardId !== null && isset($cardInfo[$cardId])) {
                $info            = $cardInfo[$cardId];
                $row['isDone']    = $info['isDone'];
                $row['cardTitle'] = $info['title'];
            } else {
                // Not a Deck card — no status available.
                $row['isDone']    = null;
                $row['cardTitle'] = null;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Extract a Deck card ID from a task_path string.
     *
     * Recognises paths of the form:
     *   apps/deck/board/{boardId}/card/{cardId}
     *
     * Returns null for any path that does not match (plain URLs, other apps).
     */
    private function extractDeckCardId(string $taskPath): ?int {
        if (preg_match('#(?:apps/)?deck/board/\\d+/card/(\\d+)#', $taskPath, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Create a link from a decision to a task. Requires action min-level.
     */
    public function linkTask(
        string  $teamId,
        int     $decisionId,
        string  $taskPath,
        ?string $label,
        string  $actingUserId,
    ): array {
        $this->requireActionLevel($teamId);
        $d = $this->decisionMapper->findById($decisionId);
        if ($d === null || $d->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found in this team');
        }
        $taskPath = trim($taskPath);
        if ($taskPath === '') {
            throw new \InvalidArgumentException('task_path is required');
        }
        // Normalise: strip leading "/" or full URL prefix to keep it relative.
        $taskPath = preg_replace('#^https?://[^/]+/#', '', $taskPath);
        $taskPath = ltrim($taskPath, '/');

        $row = $this->taskMapper->insert($decisionId, $teamId, $taskPath, $label, $actingUserId);

        // Session C — audit the link event.
        $this->auditService->log($d, 'task_linked', $actingUserId, [
            'task_path' => $taskPath,
            'label'     => $label,
        ]);

        $this->logger->info('[TeamHub][DecisionTaskService] linkTask', [
            'decision_id' => $decisionId,
            'task_path'   => $taskPath,
            'label'       => $label,
            'by'          => $actingUserId,
        ]);
        return $row;
    }

    /**
     * Delete a task link. Requires action min-level.
     */
    public function deleteLink(string $teamId, int $linkId, string $actingUserId): void {
        $this->requireActionLevel($teamId);
        $row = $this->taskMapper->findById($linkId);
        if ($row === null || $row['team_id'] !== $teamId) {
            throw new \InvalidArgumentException('Task link not found in this team');
        }
        $this->taskMapper->delete($linkId);

        // Session C — audit the unlink event (best-effort; if the decision
        // row has vanished for any reason, skip rather than fail the delete).
        $d = $this->decisionMapper->findById((int)$row['decision_id']);
        if ($d !== null) {
            $this->auditService->log($d, 'task_unlinked', $actingUserId, [
                'task_path' => $row['task_path'] ?? null,
                'label'     => $row['label'] ?? null,
            ]);
        }

        $this->logger->info('[TeamHub][DecisionTaskService] deleteLink', [
            'link_id'     => $linkId,
            'decision_id' => $row['decision_id'],
            'by'          => $actingUserId,
        ]);
    }

    /**
     * Check the acting user holds at least the configured action min-level.
     *
     * @throws \RuntimeException  if the user is below the required level
     */
    private function requireActionLevel(string $teamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('Not authenticated');
        }
        $config   = $this->teamService->getConfig($teamId);
        $minLevel = (int)($config['decisions_action_min_level'] ?? 1);
        $actual   = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
        if ($actual < $minLevel) {
            throw new \RuntimeException('Insufficient permissions — your level (' . $actual . ') is below the required (' . $minLevel . ')');
        }
    }
}
