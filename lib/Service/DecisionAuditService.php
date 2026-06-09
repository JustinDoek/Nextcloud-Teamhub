<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionAudit;
use OCA\TeamHub\Db\DecisionAuditMapper;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Decisions audit trail service (Session J).
 *
 * Append-only log of lifecycle transitions on a decision. Called from
 * inside DecisionService at each transition (propose/finalize/withdraw/
 * approve/deny) and from CommentController on comments to decision
 * messages.
 *
 * The transition vocabulary is closed:
 *   proposed | commented | finalized | withdrawn | approved | denied
 *
 * Any new transition added later goes through this same gate.
 *
 * Failure mode: logging is best-effort. A failed audit write must not
 * abort the originating action — the operational record is more valuable
 * than the audit guarantee. Failures are logged at warning level.
 */
class DecisionAuditService {

    public const TRANSITIONS = [
        'proposed', 'commented', 'finalized', 'withdrawn', 'approved', 'denied',
        // Session C — link/unlink events
        'task_linked', 'task_unlinked', 'decision_linked', 'decision_unlinked',
    ];

    private const MAX_COMMENT_EXCERPT_LEN = 200;

    public function __construct(
        private DecisionAuditMapper $auditMapper,
        private IUserManager        $userManager,
        private LoggerInterface     $logger,
    ) {}

    /**
     * Append an audit row. The payload is transition-specific (see
     * migration docblock). Pass null when the transition has no payload
     * (proposed, approved).
     *
     * @param array<string,mixed>|null $payload
     */
    public function log(Decision $decision, string $transition, string $actor, ?array $payload = null): void {
        if (!in_array($transition, self::TRANSITIONS, true)) {
            $this->logger->warning('[TeamHub][DecisionAuditService] Unknown transition rejected', [
                'transition'  => $transition,
                'decision_id' => $decision->getId(),
            ]);
            return;
        }

        try {
            $row = new DecisionAudit();
            $row->setTeamId($decision->getTeamId());
            $row->setDecisionId($decision->getId());
            $row->setTransition($transition);
            $row->setActor($actor);
            $row->setPayloadJson(
                $payload === null
                    ? null
                    : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $row->setCreatedAt(time());

            $this->auditMapper->insert($row);

            $this->logger->debug('[TeamHub][DecisionAuditService] logged', [
                'decision_id' => $decision->getId(),
                'transition'  => $transition,
                'actor'       => $actor,
            ]);
        } catch (\Throwable $e) {
            // Audit failure must NEVER undo the action that triggered it.
            $this->logger->warning('[TeamHub][DecisionAuditService] log failed (non-fatal)', [
                'decision_id' => $decision->getId(),
                'transition'  => $transition,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience for comment events — builds the standard payload from a
     * comment row and clips the excerpt to a manageable length.
     */
    public function logComment(Decision $decision, int $commentId, string $authorUid, string $commentText): void {
        $excerpt = trim($commentText);
        if (mb_strlen($excerpt) > self::MAX_COMMENT_EXCERPT_LEN) {
            $excerpt = mb_substr($excerpt, 0, self::MAX_COMMENT_EXCERPT_LEN) . '…';
        }
        $this->log($decision, 'commented', $authorUid, [
            'comment_id' => $commentId,
            'excerpt'    => $excerpt,
        ]);
    }

    /**
     * List all audit events for a decision, ordered oldest-first, in the
     * wire format consumed by the timeline UI.
     *
     * Each entry includes the actor's display name (resolved live — we
     * don't denormalise display names in the audit row because they can
     * change and the audit trail should reflect "who they are today" the
     * same way other parts of the app do).
     *
     * @return array<int, array{
     *   id: int,
     *   transition: string,
     *   actor: string,
     *   actorDisplayName: string,
     *   payload: array<string,mixed>|null,
     *   createdAt: int
     * }>
     */
    public function listForDecision(int $decisionId): array {
        $rows = $this->auditMapper->findByDecisionId($decisionId);
        $out  = [];
        foreach ($rows as $row) {
            $payload = null;
            $raw = $row->getPayloadJson();
            if ($raw !== null && $raw !== '') {
                try {
                    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $payload = null;
                }
            }
            $user = $this->userManager->get($row->getActor());
            $out[] = [
                'id'               => $row->getId(),
                'transition'       => $row->getTransition(),
                'actor'            => $row->getActor(),
                'actorDisplayName' => $user !== null ? ($user->getDisplayName() ?: $row->getActor()) : $row->getActor(),
                'payload'          => $payload,
                'createdAt'        => $row->getCreatedAt(),
            ];
        }
        return $out;
    }
}
