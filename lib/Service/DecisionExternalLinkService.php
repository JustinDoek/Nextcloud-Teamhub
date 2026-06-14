<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionExternalLink;
use OCA\TeamHub\Db\DecisionExternalLinkMapper;
use OCA\TeamHub\Db\DecisionMapper;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for external decision links — outbound URLs attached to a decision.
 *
 * Use cases:
 *   - This decision needs to be tracked further in another tool (e.g. an
 *     organisation-management system that the team escalates decisions to).
 *   - An earlier decision in another system led to creating the decision
 *     in this team; the team wants to keep an outbound trail back to it.
 *
 * One-way only — the other end is outside TeamHub by definition.
 *
 * Read is gated on team membership. Create/delete is gated on the team's
 * `decisions_action_min_level` (the same gate as Link Task / Link Decision).
 */
class DecisionExternalLinkService {

    private DecisionExternalLinkMapper $linkMapper;
    private DecisionMapper             $decisionMapper;
    private MemberService              $memberService;
    private DecisionTeamService        $teamService;
    private DecisionAuditService       $auditService;
    private IDBConnection              $db;
    private IUserSession               $userSession;
    private LoggerInterface            $logger;

    public function __construct(
        DecisionExternalLinkMapper $linkMapper,
        DecisionMapper             $decisionMapper,
        MemberService              $memberService,
        DecisionTeamService        $teamService,
        DecisionAuditService       $auditService,
        IDBConnection              $db,
        IUserSession               $userSession,
        LoggerInterface            $logger,
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
     * List all external links for a decision. Team-member read.
     *
     * @return array<int, array{id:int, url:string, label:string, createdBy:string, createdAt:int}>
     */
    public function listForDecision(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->decisionMapper->findById($decisionId);
        if (!$decision || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found');
        }

        $rows = $this->linkMapper->findByDecisionId($decisionId);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = $this->serialize($r);
        }
        return $out;
    }

    /**
     * Attach an external URL to a decision. Action-level gated.
     */
    public function linkExternal(
        string $teamId,
        int    $decisionId,
        string $url,
        string $label,
        string $actingUserId
    ): array {
        $this->requireActionLevel($teamId);

        $decision = $this->decisionMapper->findById($decisionId);
        if (!$decision || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found');
        }

        $url   = $this->validateUrl($url);
        $label = $this->normaliseLabel($label);

        $row = $this->linkMapper->insertLink($teamId, $decisionId, $url, $label, $actingUserId);

        // Audit log entry — host only, not the full URL, to keep the audit
        // trail readable. The full URL is in the row itself.
        $host = parse_url($url, PHP_URL_HOST) ?: '(unknown host)';
        $this->auditService->log($decision, 'external_link_added', $actingUserId, [
            'host'  => $host,
            'label' => $label,
        ]);

        $this->logger->info('[TeamHub][DecisionExternalLinkService] linkExternal', [
            'decision_id' => $decisionId,
            'host'        => $host,
            'by'          => $actingUserId,
        ]);

        return $this->serialize($row);
    }

    /**
     * Detach an external link. Action-level gated.
     */
    public function deleteLink(string $teamId, int $linkId, string $actingUserId): void {
        $this->requireActionLevel($teamId);

        $row = $this->linkMapper->findById($linkId);
        if (!$row || $row->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('External link not found');
        }

        // For the audit log, look up the host on the row before delete.
        $decision = $this->decisionMapper->findById($row->getDecisionId());
        $host     = parse_url($row->getUrl(), PHP_URL_HOST) ?: '(unknown host)';

        $this->linkMapper->deleteById($linkId);

        if ($decision) {
            $this->auditService->log($decision, 'external_link_removed', $actingUserId, [
                'host'  => $host,
                'label' => $row->getLabel(),
            ]);
        }

        $this->logger->info('[TeamHub][DecisionExternalLinkService] deleteLink', [
            'link_id' => $linkId,
            'host'    => $host,
            'by'      => $actingUserId,
        ]);
    }

    /**
     * Validate an external URL submitted by a user.
     *
     * Rules per SKILLS.md security standards:
     *   - Must be absolute https:// or http:// (no `javascript:`, `data:`,
     *     `file:`, etc.)
     *   - Trimmed and length-capped at 2048 to match the column width
     *   - parse_url() must produce a host
     *
     * Note: we accept http:// in addition to https:// because internal
     * intranet systems often run on http; rejecting them would block a
     * legitimate use case. Users are responsible for their own ecosystem.
     */
    private function validateUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('URL is required');
        }
        if (mb_strlen($url) > 2048) {
            throw new \InvalidArgumentException('URL too long (max 2048 characters)');
        }

        // Defensive lowercase-prefix check before parse_url so a leading
        // ` javascript:…` (with whitespace), tab-prefixed, etc. can't slip
        // past — trim() above strips ASCII whitespace; the scheme check
        // below rejects anything that isn't http(s) regardless.
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('URL must start with http:// or https://');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('URL is malformed');
        }
        // Final scheme check after parse_url (belt + suspenders).
        if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use http or https');
        }

        return $url;
    }

    private function normaliseLabel(string $label): string {
        $label = trim($label);
        if (mb_strlen($label) > 255) {
            $label = mb_substr($label, 0, 255);
        }
        return $label;
    }

    private function serialize(DecisionExternalLink $row): array {
        return [
            'id'         => $row->getId(),
            'url'        => $row->getUrl(),
            'label'      => $row->getLabel(),
            'createdBy'  => $row->getCreatedBy(),
            'createdAt'  => $row->getCreatedAt(),
        ];
    }

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
