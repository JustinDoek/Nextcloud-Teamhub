<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\PolicyField;
use OCA\TeamHub\Db\MemberReviewMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Util\ReviewSchedule;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Membership recertification — is this team's member list still right?
 * (v4.13.17, DESIGN §2.193)
 *
 * The compliance finding this closes: TeamHub had everything to *grant*
 * membership and nothing to *recertify* it. ISO 27001 A.5.18 expects the asset
 * owner to review access rights at planned intervals, and an auditor asks for
 * evidence per team per period. Decisions have had a periodic review since
 * v4.11.18; membership had none.
 *
 * ## Who sets the interval: the policy profile
 *
 * {@see PolicyField::MEMBER_REVIEW_MONTHS}, so a Nextcloud administrator says
 * *Confidential teams every 3 months, Public teams never* once, and every team
 * inherits it from its classification. Not an instance-wide number (a social
 * team and a board team are not the same risk) and not the team admin's own
 * setting (the person being reviewed would set the frequency). An ungoverned
 * field, an unclassified team and a period of 0 all mean **no review**, so an
 * untouched install is unchanged — Track F's inertness rule.
 *
 * ## Who does it, and what "doing it" is
 *
 * The team's own admins, in Manage team → Members, where the roster is. They
 * remove anyone who should go — the ordinary member-removal path, nothing new —
 * and then attest that the list is correct. One action, one row, one audit
 * event, with the member count at that moment and how many had been removed
 * since the previous review.
 *
 * Per-member keep/remove was considered and rejected: on a forty-person team it
 * is forty clicks, and a review that is tedious is a review that gets
 * rubber-stamped or abandoned. What an access review is evidenced by is *a
 * named person stating, on a date, that they examined the list* — which is
 * exactly this row, and the roster now carries each member's joined date so
 * that statement can be a considered one.
 *
 * ## The clock
 *
 * {@see ReviewSchedule}, the same helper the decision reviews use: calendar
 * months in UTC, and the notice window opens two weeks before the due date. The
 * anchor is the last completed review, or — for a team that has never had one —
 * **the moment its policy profile was assigned**, not the team's creation date.
 * A team classified today has not been overdue since 2019.
 *
 * `next_due_at` is **stored** on the completed review rather than recomputed
 * from today's policy. Shortening a profile's period moves the *next* due date
 * when the next review completes; it does not rewrite when past reviews were
 * due, which is a record and not a projection.
 *
 * ## No bell, deliberately
 *
 * The My Work row is the workflow (`/mywork-workflows`), and a review that is
 * due is not somebody waiting on somebody else — it is a standing obligation
 * with weeks of notice. Adding a notification is one `Notifier` subject and one
 * call in {@see listDue()} if Justin wants it; it is left out rather than
 * guessed at, the same call compliments made on 2026-10-16.
 */
class MemberReviewService {

    /** Note length, matching the column. */
    public const NOTE_MAX = 500;

    /** How many members a stored snapshot will hold. */
    public const SNAPSHOT_CAP = 200;

    public function __construct(
        private MemberReviewMapper $reviews,
        private PolicyService      $policyService,
        private MemberService      $memberService,
        private AuditService       $auditService,
        private IUserSession       $userSession,
        private LoggerInterface    $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // The clock
    // -------------------------------------------------------------------------

    /**
     * The review period this team's classification asks for, in months.
     *
     * 0 for an unclassified team, an ungoverned field, or an explicit 0 — all
     * three mean no review, and no caller has ever needed to tell them apart.
     */
    public function periodMonths(string $teamId): int {
        $governance = $this->policyService->governanceFor($teamId, PolicyField::MEMBER_REVIEW_MONTHS);
        $value      = $governance['value'] ?? null;

        return max(0, (int)$value);
    }

    /**
     * Where this team stands.
     *
     * Ungated on purpose, like `PolicyService::governanceFor()`: every caller
     * has already established who is asking. The controller gates on team
     * admin, the My Work provider on the teams the viewer administers, and the
     * daily pass has no user at all.
     *
     * @return array{
     *     required: bool, periodMonths: int, dueAt: int|null, overdue: bool,
     *     withinNotice: bool, lastReviewedAt: int|null, lastReviewedBy: string,
     *     memberCount: int|null,
     * }
     */
    public function status(string $teamId, ?int $now = null): array {
        $now    = $now ?? time();
        $months = $this->periodMonths($teamId);
        $last   = $this->reviews->latestForTeam($teamId);

        if ($months < 1) {
            return [
                'required'       => false,
                'periodMonths'   => 0,
                'dueAt'          => null,
                'overdue'        => false,
                'withinNotice'   => false,
                'lastReviewedAt' => $last === null ? null : $last['reviewedAt'],
                'lastReviewedBy' => $last === null ? '' : $last['reviewedBy'],
                'memberCount'    => $last === null ? null : $last['memberCount'],
            ];
        }

        $dueAt = $this->dueAt($teamId, $months, $last, $now);

        return [
            'required'       => true,
            'periodMonths'   => $months,
            'dueAt'          => $dueAt,
            'overdue'        => $dueAt !== null && $now >= $dueAt,
            'withinNotice'   => ReviewSchedule::isWithinNotice($dueAt, $now),
            'lastReviewedAt' => $last === null ? null : $last['reviewedAt'],
            'lastReviewedBy' => $last === null ? '' : $last['reviewedBy'],
            'memberCount'    => $last === null ? null : $last['memberCount'],
        ];
    }

    /**
     * Every team of the given set whose review is within the notice window,
     * newest-due first. What the My Work provider lists.
     *
     * One policy read and one review read per team; the review reads are a
     * single query for the whole set. The policy read is per team because
     * `governanceFor()` is, and a team's classification is cached behind it.
     *
     * @param list<string> $teamIds
     * @return list<array{teamId: string, dueAt: int, overdue: bool, periodMonths: int, lastReviewedAt: int|null, lastReviewedBy: string}>
     */
    public function listDue(array $teamIds, ?int $now = null): array {
        if ($teamIds === []) {
            return [];
        }

        $now     = $now ?? time();
        $latest  = $this->reviews->latestForTeams($teamIds);
        $out     = [];

        foreach ($teamIds as $teamId) {
            $months = $this->periodMonths($teamId);
            if ($months < 1) {
                continue;
            }

            $last  = $latest[$teamId] ?? null;
            $dueAt = $this->dueAt($teamId, $months, $last, $now);
            if ($dueAt === null || !ReviewSchedule::isWithinNotice($dueAt, $now)) {
                continue;
            }

            $out[] = [
                'teamId'         => $teamId,
                'dueAt'          => $dueAt,
                'overdue'        => $now >= $dueAt,
                'periodMonths'   => $months,
                'lastReviewedAt' => $last === null ? null : $last['reviewedAt'],
                'lastReviewedBy' => $last === null ? '' : $last['reviewedBy'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['dueAt'] <=> $b['dueAt']);

        return $out;
    }

    // -------------------------------------------------------------------------
    // The attestation
    // -------------------------------------------------------------------------

    /**
     * Record that an administrator has examined this team's member list and
     * confirms it is correct.
     *
     * Gated on **team admin** — `requireAdminLevel()`, which carries the
     * parent-admin fallback, so an admin of a main team can attest one of its
     * sub-teams. Deliberately not Nextcloud-admin-only: the asset owner
     * performs the review, and a server administrator attesting a list they do
     * not know is the shape of a control that exists only on paper.
     *
     * @param string $note optional words from the reviewer, kept with the row
     * @return array<string,mixed> the new status
     */
    public function attest(string $teamId, string $note = '', ?int $now = null): array {
        // The gate first, then the actor: `requireAdminLevel()` returns void
        // and throws, so the session read below is only reached by somebody who
        // passed it.
        $this->memberService->requireAdminLevel($teamId);
        $actor = $this->userSession->getUser()?->getUID() ?? '';
        if ($actor === '') {
            throw new ValidationException('An attestation needs a named reviewer.');
        }
        $now = $now ?? time();

        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new ValidationException('The note is too long.');
        }

        $roster      = $this->roster($teamId);
        $memberCount = count($roster);

        $last = $this->reviews->latestForTeam($teamId);
        // How many of the previously attested people are no longer on the list.
        // Computed from the stored snapshot: it is the only record of who was
        // there last time, and a count difference would call a team that
        // removed two and added two "unchanged".
        $removed = 0;
        if ($last !== null && is_array($last['snapshot'] ?? null)) {
            $before  = array_column((array)$last['snapshot'], 'userId');
            $nowUids = array_column($roster, 'userId');
            $removed = count(array_diff($before, $nowUids));
        }

        $months  = $this->periodMonths($teamId);
        $nextDue = $months > 0 ? (ReviewSchedule::dueAt($now, $months) ?? 0) : 0;

        $this->reviews->insert(
            $teamId,
            $actor,
            $now,
            $memberCount,
            $removed,
            $nextDue,
            $note,
            array_slice($roster, 0, self::SNAPSHOT_CAP),
        );

        // Both, and for different reasons: the audit row carries the actor into
        // the trail an auditor reads chronologically, and the table is the
        // record that outlives the audit retention horizon. See
        // MemberReviewMapper's docblock.
        $this->auditService->log(
            $teamId,
            'member.review_completed',
            $actor,
            'team',
            $teamId,
            [
                'member_count'   => $memberCount,
                'removed_since'  => $removed,
                'period_months'  => $months,
                'next_due_at'    => $nextDue,
                'note'           => $note,
            ],
        );

        $this->logger->info('[TeamHub][MemberReviewService] Membership recertified', [
            'teamId' => $teamId, 'actor' => $actor, 'members' => $memberCount,
            'app' => Application::APP_ID,
        ]);

        return $this->status($teamId, $now);
    }

    /**
     * One team's review history — the evidence, newest first.
     *
     * Team-admin gated like the attestation: the people who perform the review
     * are the people who should be able to show it was performed.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $teamId, int $limit = 50): array {
        $this->memberService->requireAdminLevel($teamId);

        return $this->reviews->historyForTeam($teamId, $limit);
    }

    /** Called from the team's deletion path. */
    public function forgetTeam(string $teamId): void {
        try {
            $this->reviews->deleteForTeam($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MemberReviewService] Could not remove a deleted team\'s reviews', [
                'teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * The two-step tracker every My Work row of this workflow carries.
     *
     * Two steps and not three: nobody hands this over and nobody closes it
     * afterwards. The clock opens it and the team admin finishes it, which is
     * the whole workflow — and a tracker that invented a third step would
     * describe a procedure that does not exist.
     *
     * @param list<string> $labels [due, confirmed] translated by the provider
     * @return array{steps: list<array<string,mixed>>, current: int}
     */
    public function workflowSteps(array $labels, ?int $dueAt, ?int $lastReviewedAt, string $lastReviewedBy): array {
        return [
            'steps' => [
                [
                    'label' => $labels[0] ?? '',
                    'state' => 'done',
                    'actor' => null,
                    'at'    => $dueAt,
                ],
                [
                    'label' => $labels[1] ?? '',
                    'state' => 'current',
                    'actor' => $lastReviewedBy !== '' ? $lastReviewedBy : null,
                    'at'    => $lastReviewedAt,
                ],
            ],
            'current' => 2,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * When this team's next review falls due.
     *
     * The anchor for a team that has never been reviewed is **when its profile
     * was assigned**. Using the team's creation date instead would make every
     * existing team overdue the moment an administrator sets a review period,
     * which turns a new control into a backlog nobody asked for and teaches
     * people to ignore the row.
     *
     * @param array<string,mixed>|null $last
     */
    private function dueAt(string $teamId, int $months, ?array $last, int $now): ?int {
        if ($last !== null) {
            // The stored due date wins when there is one: it is what the
            // reviewer was told at the time. Recomputed only when it is absent
            // (0), which a row written with no period cannot avoid.
            if ((int)$last['nextDueAt'] > 0) {
                return (int)$last['nextDueAt'];
            }

            return ReviewSchedule::dueAt((int)$last['reviewedAt'], $months);
        }

        $anchor = $this->classifiedAt($teamId);
        if ($anchor === null) {
            // Classified with no recorded moment — a row written before the
            // assignment carried a timestamp. Start the clock now rather than
            // declaring it overdue: the first cycle is a month late at worst,
            // and the alternative accuses an administrator of a backlog they
            // never had.
            return ReviewSchedule::dueAt($now, $months);
        }

        return ReviewSchedule::dueAt($anchor, $months);
    }

    /** When this team's policy profile was assigned, if that is recorded. */
    private function classifiedAt(string $teamId): ?int {
        try {
            return $this->policyService->classifiedAtFor($teamId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The direct members of a team, as the attestation records them.
     *
     * Direct rows only, which is the same list Manage team → Members shows and
     * attests. A group attached to the team is one membership decision, not
     * forty, and expanding it would make the snapshot a copy of the group's own
     * roster — maintained elsewhere, reviewed elsewhere.
     *
     * @return list<array{userId: string, displayName: string, level: int, joinedAt: int}>
     */
    private function roster(string $teamId): array {
        $payload = $this->memberService->getMembersForManage($teamId);
        $out = [];
        foreach ((array)($payload['direct'] ?? []) as $member) {
            $out[] = [
                'userId'      => (string)($member['userId'] ?? ''),
                'displayName' => (string)($member['displayName'] ?? ''),
                'level'       => (int)($member['level'] ?? 0),
                'joinedAt'    => (int)($member['joinedAt'] ?? 0),
            ];
        }

        return $out;
    }
}
