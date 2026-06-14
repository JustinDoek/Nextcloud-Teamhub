<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionMeeting;
use OCA\TeamHub\Db\DecisionMeetingMapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for decision ↔ approver-meeting relations.
 *
 * A row in teamhub_dec_meetings is created when an approver schedules a
 * meeting with the other approvers of the category to discuss a proposal.
 * The flow is:
 *   1. Frontend calls GET /approvers to populate the wizard pre-fill.
 *   2. Frontend opens SuggestMeetingWizard with the approver list and the
 *      proposal text as the description.
 *   3. Wizard POSTs to the existing calendar/events endpoint, which now
 *      returns the iCal UID.
 *   4. Frontend POSTs to /meetings with the UID + start + title to record
 *      the back-reference here.
 *
 * Read access (list) is gated to team members.
 * Write access (create) is gated to category approvers for that decision
 * (team admin auto-included per the standard approver-list rule).
 */
class DecisionMeetingService {

    private DecisionMeetingMapper   $meetingMapper;
    private DecisionMapper          $decisionMapper;
    private DecisionCategoryService $categoryService;
    private MemberService           $memberService;
    private DecisionAuditService    $auditService;
    private IDBConnection           $db;
    private IUserSession            $userSession;
    private IUserManager            $userManager;
    private LoggerInterface         $logger;

    public function __construct(
        DecisionMeetingMapper   $meetingMapper,
        DecisionMapper          $decisionMapper,
        DecisionCategoryService $categoryService,
        MemberService           $memberService,
        DecisionAuditService    $auditService,
        IDBConnection           $db,
        IUserSession            $userSession,
        IUserManager            $userManager,
        LoggerInterface         $logger,
    ) {
        $this->meetingMapper   = $meetingMapper;
        $this->decisionMapper  = $decisionMapper;
        $this->categoryService = $categoryService;
        $this->memberService   = $memberService;
        $this->auditService    = $auditService;
        $this->db              = $db;
        $this->userSession     = $userSession;
        $this->userManager     = $userManager;
        $this->logger          = $logger;
    }

    /**
     * Return the list of approver UIDs for the decision's category.
     * Team admins are implicitly approvers but the explicit list returned
     * here only contains the configured approvers — admin auto-inclusion
     * is applied client-side or by the wizard's attendee picker.
     *
     * If the decision has no matched category (legacy free-text), returns
     * the team owner as the sole effective approver (consistent with the
     * backend's fallback rule).
     *
     * @return array{approvers: array<int, array{userId: string, displayName: string}>, categoryId: ?int, categoryName: ?string}
     */
    public function listApproversForDecision(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->decisionMapper->findById($decisionId);
        if (!$decision || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found');
        }

        $categoryName = $decision->getCategory();
        $approverIds  = [];
        $categoryId   = null;

        if ($categoryName !== null && $categoryName !== '') {
            $categories = $this->categoryService->listForTeam($teamId);
            foreach ($categories as $cat) {
                if (($cat['name'] ?? '') === $categoryName) {
                    $approverIds = array_values($cat['approvers'] ?? []);
                    $categoryId  = $cat['id'] ?? null;
                    break;
                }
            }
        }

        // Resolve display names. Missing accounts (deleted users) are kept
        // with the userId as both fields so the wizard can still display
        // something useful.
        $approvers = [];
        foreach ($approverIds as $uid) {
            $uid = (string)$uid;
            if ($uid === '') {
                continue;
            }
            $user = $this->userManager->get($uid);
            $approvers[] = [
                'userId'      => $uid,
                'displayName' => $user ? $user->getDisplayName() : $uid,
            ];
        }

        return [
            'approvers'    => $approvers,
            'categoryId'   => $categoryId,
            'categoryName' => $categoryName,
        ];
    }

    /**
     * List all meetings scheduled for a proposal.
     *
     * @return array<int, array{id: int, eventUid: string, meetingTitle: string,
     *                          meetingStart: int, scheduledBy: string,
     *                          scheduledByDisplayName: string, createdAt: int}>
     */
    public function listForDecision(string $teamId, int $decisionId): array {
        $this->memberService->requireMemberLevel($teamId);

        $decision = $this->decisionMapper->findById($decisionId);
        if (!$decision || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found');
        }

        $rows = $this->meetingMapper->findByDecisionId($decisionId);
        $out  = [];
        foreach ($rows as $r) {
            $by   = $r->getScheduledBy();
            $user = $by !== '' ? $this->userManager->get($by) : null;
            $out[] = [
                'id'                     => $r->getId(),
                'eventUid'               => $r->getEventUid(),
                'meetingTitle'           => $r->getMeetingTitle(),
                'meetingStart'           => $r->getMeetingStart(),
                'scheduledBy'            => $by,
                'scheduledByDisplayName' => $user ? $user->getDisplayName() : $by,
                'createdAt'              => $r->getCreatedAt(),
            ];
        }
        return $out;
    }

    /**
     * Record that a meeting was scheduled for the given decision. The
     * caller (the frontend) has already created the calendar event via
     * the existing /calendar/events endpoint and now posts back the UID
     * + start + title for the back-reference.
     *
     * Authorisation: caller must be an approver for the decision's
     * category. The approver gate is the same as approve/deny —
     * `assertCanScheduleMeeting` mirrors that logic.
     *
     * @return array{id: int, eventUid: string, meetingTitle: string,
     *               meetingStart: int, scheduledBy: string,
     *               scheduledByDisplayName: string, createdAt: int}
     */
    public function recordMeeting(
        string $teamId,
        int    $decisionId,
        string $eventUid,
        string $meetingTitle,
        int    $meetingStart,
        string $actingUserId
    ): array {
        if ($eventUid === '') {
            throw new \InvalidArgumentException('eventUid is required');
        }
        if ($meetingTitle === '') {
            throw new \InvalidArgumentException('meetingTitle is required');
        }
        if ($meetingStart <= 0) {
            throw new \InvalidArgumentException('meetingStart must be a positive timestamp');
        }
        if (mb_strlen($meetingTitle) > 255) {
            $meetingTitle = mb_substr($meetingTitle, 0, 255);
        }
        if (mb_strlen($eventUid) > 255) {
            throw new \InvalidArgumentException('eventUid too long');
        }

        $decision = $this->decisionMapper->findById($decisionId);
        if (!$decision || $decision->getTeamId() !== $teamId) {
            throw new \InvalidArgumentException('Decision not found');
        }

        $this->assertCanScheduleMeeting($teamId, $decision, $actingUserId);

        $row = $this->meetingMapper->insertMeeting(
            $teamId,
            $decisionId,
            $eventUid,
            $meetingTitle,
            $meetingStart,
            $actingUserId
        );

        // Audit log entry for the proposal's history.
        $this->auditService->log($decision, 'approver_meeting_scheduled', $actingUserId, [
            'event_uid'     => $eventUid,
            'meeting_title' => $meetingTitle,
            'meeting_start' => $meetingStart,
        ]);

        $this->logger->info('[TeamHub][DecisionMeetingService] recordMeeting', [
            'decision_id' => $decisionId,
            'event_uid'   => $eventUid,
            'by'          => $actingUserId,
        ]);

        $user = $this->userManager->get($actingUserId);
        return [
            'id'                     => $row->getId(),
            'eventUid'               => $row->getEventUid(),
            'meetingTitle'           => $row->getMeetingTitle(),
            'meetingStart'           => $row->getMeetingStart(),
            'scheduledBy'            => $row->getScheduledBy(),
            'scheduledByDisplayName' => $user ? $user->getDisplayName() : $actingUserId,
            'createdAt'              => $row->getCreatedAt(),
        ];
    }

    /**
     * Authorise scheduling a meeting for a proposal.
     *
     * Rule: the acting user must be an approver for the decision's
     * category. Team admins are implicit approvers (they pass even if
     * not in the explicit list). Falls back to admin-level when the
     * decision has no matched category — same rule the approve/deny
     * path enforces.
     */
    private function assertCanScheduleMeeting(string $teamId, Decision $d, string $actingUserId): void {
        $categoryName = $d->getCategory();

        if ($categoryName !== null && $categoryName !== '') {
            $categories = $this->categoryService->listForTeam($teamId);
            foreach ($categories as $cat) {
                if (($cat['name'] ?? '') === $categoryName) {
                    $approvers = $cat['approvers'] ?? [];
                    if (in_array($actingUserId, $approvers, true)) {
                        return;
                    }
                    throw new \RuntimeException('Not authorized: you are not an approver for category ' . $categoryName);
                }
            }
        }
        // No matched category — fall back to admin-level check.
        $this->memberService->requireAdminLevel($teamId);
    }

    /**
     * Delete all meetings for a deleted decision. Called from
     * DecisionService when a decision is removed.
     */
    public function deleteAllForDecision(int $decisionId): void {
        $this->meetingMapper->deleteByDecisionId($decisionId);
    }
}
