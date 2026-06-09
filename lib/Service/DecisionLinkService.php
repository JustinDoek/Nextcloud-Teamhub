<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionLink;
use OCA\TeamHub\Db\DecisionLinkMapper;
use OCA\TeamHub\Db\DecisionMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for bidirectional decision ↔ decision links (Session C).
 *
 * One row per link in teamhub_dec_links.
 * Canonical ordering: decision_id_a < decision_id_b always.
 * Both directions are served from that one row via OR queries.
 */
class DecisionLinkService {

    private DecisionLinkMapper $linkMapper;
    private DecisionMapper     $decisionMapper;
    private MemberService      $memberService;
    private DecisionTeamService $teamService;
    private DecisionAuditService $auditService;
    private IDBConnection      $db;
    private IUserSession       $userSession;
    private LoggerInterface    $logger;

    public function __construct(
        DecisionLinkMapper   $linkMapper,
        DecisionMapper       $decisionMapper,
        MemberService        $memberService,
        DecisionTeamService  $teamService,
        DecisionAuditService $auditService,
        IDBConnection        $db,
        IUserSession         $userSession,
        LoggerInterface      $logger,
    ) {
        $this->linkMapper     = $linkMapper;
        $this->decisionMapper = $decisionMapper;
        $this->memberService  = $memberService;
        $this->teamService    = $teamService;
        $this->auditService   = $auditService;
        $this->db             = $db;
        $this->userSession    = $userSession;
        $this->logger         = $logger;
    }

    /**
     * List all decision-links for a decision, returning the peer decision's
     * summary data alongside link metadata.
     *
     * Any team member can read.
     *
     * Performance note: N+1 query — one peer-decision lookup per link.
     * Acceptable here because (a) typical link counts are 0–5 per decision,
     * (b) each peer lookup is a primary-key hit (O(1)). Revisit with a
     * batched IN-query if real installs accumulate >20 links per decision.
     *
     * @return array<int, array{id: int, peer_id: int, peer_title: string, peer_status: string, peer_impact: string, peer_level: string, created_by: string, created_at: int}>
     */
    public function listForDecision(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);
        $this->assertDecisionBelongsToTeam($decisionId, $teamId);

        $links = $this->linkMapper->findByDecisionId($decisionId);
        $result = [];

        foreach ($links as $link) {
            // Determine which side is the peer.
            $peerId = ($link->getDecisionIdA() === $decisionId)
                ? $link->getDecisionIdB()
                : $link->getDecisionIdA();

            $peer = $this->decisionMapper->findById($peerId);
            if ($peer === null || $peer->getTeamId() !== $teamId) {
                // Orphaned link — skip silently. A future cleanup could remove these.
                $this->logger->warning('[TeamHub][DecisionLinkService] orphaned link', [
                    'link_id'     => $link->getId(),
                    'decision_id' => $decisionId,
                    'peer_id'     => $peerId,
                ]);
                continue;
            }

            $result[] = [
                'id'          => $link->getId(),
                'peer_id'     => $peerId,
                'peer_title'  => $peer->getQuestion(),
                'peer_status' => $peer->getStatus(),
                'peer_impact' => $peer->getImpact(),
                'peer_level'  => $peer->getLevel() ?? 'operational',
                'created_by'  => $link->getCreatedBy(),
                'created_at'  => $link->getCreatedAt(),
            ];
        }

        return $result;
    }

    /**
     * Create a link between two decisions. Requires action min-level.
     *
     * @throws \InvalidArgumentException on bad input or duplicate
     * @throws \RuntimeException on permission failure
     */
    public function createLink(
        string $teamId,
        int    $decisionId,
        int    $targetDecisionId,
        string $actingUserId,
    ): array {
        $this->requireActionLevel($teamId);

        if ($decisionId === $targetDecisionId) {
            throw new \InvalidArgumentException('A decision cannot be linked to itself');
        }

        $this->assertDecisionBelongsToTeam($decisionId, $teamId);
        $this->assertDecisionBelongsToTeam($targetDecisionId, $teamId);

        // Canonical ordering: smaller id is always 'a'.
        [$aId, $bId] = $decisionId < $targetDecisionId
            ? [$decisionId, $targetDecisionId]
            : [$targetDecisionId, $decisionId];

        // Duplicate check.
        if ($this->linkMapper->findPair($aId, $bId) !== null) {
            throw new \InvalidArgumentException('These decisions are already linked');
        }

        $link = $this->linkMapper->insertLink($teamId, $aId, $bId, $actingUserId);

        $this->logger->info('[TeamHub][DecisionLinkService] createLink', [
            'decision_id_a' => $aId,
            'decision_id_b' => $bId,
            'by'            => $actingUserId,
        ]);

        // Return the full peer summary so the frontend can render immediately
        // without a second round-trip.
        $peerId = ($aId === $decisionId) ? $bId : $aId;
        $peer   = $this->decisionMapper->findById($peerId);
        $source = $this->decisionMapper->findById($decisionId);

        // Session C — audit the link event on BOTH decisions so each one
        // surfaces "linked to <other>" in its own audit trail.
        if ($source !== null) {
            $this->auditService->log($source, 'decision_linked', $actingUserId, [
                'peer_id'    => $peerId,
                'peer_title' => $peer ? $peer->getQuestion() : '',
            ]);
        }
        if ($peer !== null) {
            $this->auditService->log($peer, 'decision_linked', $actingUserId, [
                'peer_id'    => $decisionId,
                'peer_title' => $source ? $source->getQuestion() : '',
            ]);
        }

        return [
            'id'          => $link->getId(),
            'peer_id'     => $peerId,
            'peer_title'  => $peer ? $peer->getQuestion() : '',
            'peer_status' => $peer ? $peer->getStatus() : '',
            'peer_impact' => $peer ? $peer->getImpact() : '',
            'peer_level'  => $peer ? ($peer->getLevel() ?? 'operational') : 'operational',
            'created_by'  => $link->getCreatedBy(),
            'created_at'  => $link->getCreatedAt(),
        ];
    }

    /**
     * Delete a link by id. Requires action min-level.
     * Validates that the link actually belongs to this team.
     *
     * @throws \InvalidArgumentException if link not found or not on this team
     * @throws \RuntimeException on permission failure
     */
    public function deleteLink(string $teamId, int $linkId, string $actingUserId): void {
        $this->requireActionLevel($teamId);

        $link = $this->linkMapper->findById($linkId);
        if ($link === null || $link->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision link not found in this team');
        }

        $this->linkMapper->deleteById($linkId);

        // Session C — audit the unlink event on BOTH decisions.
        $a = $this->decisionMapper->findById($link->getDecisionIdA());
        $b = $this->decisionMapper->findById($link->getDecisionIdB());
        if ($a !== null) {
            $this->auditService->log($a, 'decision_unlinked', $actingUserId, [
                'peer_id'    => $link->getDecisionIdB(),
                'peer_title' => $b ? $b->getQuestion() : '',
            ]);
        }
        if ($b !== null) {
            $this->auditService->log($b, 'decision_unlinked', $actingUserId, [
                'peer_id'    => $link->getDecisionIdA(),
                'peer_title' => $a ? $a->getQuestion() : '',
            ]);
        }

        $this->logger->info('[TeamHub][DecisionLinkService] deleteLink', [
            'link_id'       => $linkId,
            'decision_id_a' => $link->getDecisionIdA(),
            'decision_id_b' => $link->getDecisionIdB(),
            'by'            => $actingUserId,
        ]);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Assert a decision exists and belongs to the given team.
     *
     * @throws \InvalidArgumentException
     */
    private function assertDecisionBelongsToTeam(int $decisionId, string $teamId): void {
        $d = $this->decisionMapper->findById($decisionId);
        if ($d === null || $d->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException("Decision {$decisionId} not found in team {$teamId}");
        }
    }

    /**
     * Check the acting user holds at least the configured action min-level.
     *
     * @throws \RuntimeException
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
            throw new \RuntimeException(
                'Insufficient permissions — your level (' . $actual . ') is below the required (' . $minLevel . ')'
            );
        }
    }
}
