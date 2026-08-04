<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionTeamConfigMapper;
use OCA\TeamHub\Db\Milestone;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\Project;
use OCA\TeamHub\Db\ProjectMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * MilestoneAutoPostService (v3.97.0, Track E Session 6).
 *
 * Walks every milestone whose date has passed and that has not yet been
 * announced, posts "Milestone reached: {label}" to the team's message
 * stream, and stamps posted_at so subsequent sweeps skip it.
 *
 * WHY THIS BYPASSES MessageService
 * --------------------------------
 * MessageService::createMessage requires \OCP\IUserSession::getUser() — it
 * expects an authenticated request context. This service runs from a
 * TimedJob (no session), so it writes directly through MessageMapper.
 * Author attribution is the milestone's `created_by` — the admin who set
 * up the milestone in the first place — so the post carries a real user
 * on the stream rather than a synthetic "system" account.
 *
 * SCOPE / GATING
 * --------------
 * - Only Advanced projects get auto-posts. Milestones on non-project or
 *   Basic-project teams are stamped posted_at without a message write so
 *   they don't linger in the "pending" set forever.
 * - No membership check: the milestone's team_id is authoritative — it
 *   was created by an admin of that team, so posting to the same team is
 *   trivially allowed.
 * - Silent-degrade on any per-milestone failure: log the error, continue
 *   the sweep. One bad milestone must not stop the batch.
 *
 * BODY ENRICHMENT (v4.4.11)
 * -------------------------
 * The body used to be a single "…was scheduled for today. Confirm progress
 * with the team." line, which was too little for a team catching up on
 * activity. It now also summarises what actually happened in the window
 * this milestone owns:
 *
 *   - Deck tasks whose duedate falls in (previousMilestone.date, thisMs.date]
 *     — total, done, still open. Same ownership convention as the
 *     ProjectHealthService "milestones" pillar so a "reached" message and
 *     the widget don't disagree on which cards belong to which milestone.
 *   - Decisions (when the module is on for the team) proposed in the same
 *     window — total, still awaiting a decision, decided (approved/denied).
 *     Withdrawn decisions are counted separately in the "awaiting" bucket
 *     so the three sub-counts always sum to "total".
 *
 * When there is no previous dated milestone, the window is
 * (project.startDate, thisMs.date]; if the project has no start date, the
 * window is everything up to and including thisMs.date.
 */
class MilestoneAutoPostService {

    // Decision status buckets — mirrors ProjectHealthService's constants but
    // kept local so the two services can evolve their reporting choices
    // independently.
    private const STATUS_OPEN     = ['open', 'finalized'];
    private const STATUS_DECIDED  = ['approved', 'denied'];

    public function __construct(
        private MilestoneMapper           $milestoneMapper,
        private MessageMapper             $messageMapper,
        private ProjectMapper             $projectMapper,
        private DecisionMapper            $decisionMapper,
        private DecisionTeamConfigMapper  $decisionConfigMapper,
        private TimelineService           $timelineService,
        private IConfig                   $config,
        private IDBConnection             $db,
        private IUserManager              $userManager,
        private IFactory                  $l10nFactory,
        private LoggerInterface           $logger,
    ) {}

    /**
     * Run one sweep. Returns counters for the job's log line.
     *
     * @return array{scanned:int, posted:int, skipped:int, errors:int}
     */
    public function sweep(): array {
        $now = time();
        $due = $this->milestoneMapper->findDueUnposted($now);

        $scanned = count($due);
        $posted  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($due as $milestone) {
            try {
                $project = $this->projectMapper->findByTeam($milestone->getTeamId());
                // Non-Advanced or non-project teams: stamp posted_at without
                // posting — otherwise the milestone stays in the pending set
                // forever, and we do not want to auto-post to Basic-mode
                // teams anyway.
                if ($project === null || $project->getMode() !== 'advanced') {
                    $milestone->setPostedAt($now);
                    $this->milestoneMapper->update($milestone);
                    $skipped++;
                    continue;
                }

                $this->postForMilestone($milestone, $project);
                $milestone->setPostedAt($now);
                $this->milestoneMapper->update($milestone);
                $posted++;
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning('[TeamHub][MilestoneAutoPostService] milestone auto-post failed', [
                    'milestoneId' => $milestone->getId(),
                    'teamId'      => $milestone->getTeamId(),
                    'error'       => $e->getMessage(),
                    'app'         => Application::APP_ID,
                ]);
            }
        }

        return ['scanned' => $scanned, 'posted' => $posted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Post one milestone-reached message. The message is a "normal"-type post
     * so it shows up in the stream, activity feed, notifications, and email
     * digest like any other announcement — but is written directly through
     * the mapper (not MessageService), so no session is needed.
     *
     * String is translated to the creator's Nextcloud language via
     * IFactory — this matches how Deck stack titles are localised at team
     * creation and how MeetingService::postAgendaRequest picks the
     * organizer's language.
     */
    private function postForMilestone(Milestone $milestone, Project $project): void {
        $authorUid = $milestone->getCreatedBy();
        $lang      = $this->resolveUserLang($authorUid);
        $l10n      = $this->l10nFactory->get(Application::APP_ID, $lang);

        // TRANSLATORS: system-posted stream message subject when a milestone's date has passed
        $subject = $l10n->t('Milestone reached: %s', [$milestone->getLabel()]);
        $body    = $this->buildBody($milestone, $project, $l10n);

        $this->messageMapper->create(
            $milestone->getTeamId(),
            $authorUid,
            $subject,
            $body,
            'normal',
            'normal',
            null,
            false,  // isPublic — a milestone announcement stays inside the team
            // v4.5.26 — isSystem. The author is still the milestone's creator
            // (DESIGN §2.47 Decision 5); this flag is the only thing that tells
            // "What's new" a post was written by the hourly job rather than by
            // that person, so its System-messages switch can filter it.
            true,
        );
    }

    /**
     * Compose the multi-paragraph body: header line, "since" context line,
     * Deck-tasks paragraph, and (if decisions are on) decisions paragraph.
     * Each paragraph is a single localised string with positional %d/%s
     * arguments so translators can reorder the numbers within their
     * language's sentence structure.
     */
    private function buildBody(Milestone $milestone, Project $project, IL10N $l10n): string {
        $paras = [];

        $paras[] = $l10n->t(
            'The milestone "%s" was scheduled for today. Confirm progress with the team.',
            [$milestone->getLabel()],
        );

        // Compute the window. "prev" is the most recent dated milestone
        // whose date is strictly earlier than this one. If none, fall back
        // to the project start date; if that is also unset, the window is
        // open-ended on the left.
        [$prevMilestone, $windowFrom, $windowTo] = $this->resolveWindow($milestone, $project);

        // Context line — tells the reader what "in this period" refers to.
        if ($prevMilestone !== null) {
            $paras[] = $l10n->t(
                // TRANSLATORS: %1$s is the label of the previous milestone; %2$s is its localised date. Introduces the counts that follow.
                'Since the previous milestone "%1$s" (%2$s):',
                [$prevMilestone->getLabel(), $this->formatDate($prevMilestone->getMilestoneDate(), $l10n)],
            );
        } elseif ($project->getStartDate() !== null) {
            $paras[] = $l10n->t(
                // TRANSLATORS: %s is the project start date, localised. Introduces the counts that follow when there is no previous milestone.
                'Since the project started (%s):',
                [$this->formatDate($project->getStartDate(), $l10n)],
            );
        } else {
            $paras[] = $l10n->t('Up to this milestone date:');
        }

        // Deck tasks in the window. "isDone" mirrors ProjectHealthService:
        // deck_cards.done is a datetime/timestamp column, non-empty = done.
        $deck = $this->countDeckCardsInWindow($milestone->getTeamId(), $windowFrom, $windowTo);
        if ($deck['total'] > 0) {
            $paras[] = $l10n->t(
                // TRANSLATORS: %1$d total tasks in this period, %2$d completed, %3$d still open. Counted against Deck cards with a due date inside the milestone's window.
                'Tasks due in this period: %1$d in total — %2$d completed, %3$d still open.',
                [$deck['total'], $deck['done'], $deck['open']],
            );
        } else {
            $paras[] = $l10n->t('No tasks with a due date in this period.');
        }

        // Decisions in the window, only when the module is on for this team.
        if ($this->isDecisionsEnabled($milestone->getTeamId())) {
            $dec = $this->countDecisionsInWindow($milestone->getTeamId(), $windowFrom, $windowTo);
            if ($dec['total'] > 0) {
                $paras[] = $l10n->t(
                    // TRANSLATORS: %1$d decisions proposed in this period, %2$d decided (approved/denied), %3$d still awaiting a decision. Withdrawn decisions are counted in "still awaiting".
                    'Decisions proposed in this period: %1$d in total — %2$d decided (approved or denied), %3$d still awaiting a decision.',
                    [$dec['total'], $dec['decided'], $dec['open']],
                );
            } else {
                $paras[] = $l10n->t('No decisions proposed in this period.');
            }
        }

        return implode("\n\n", $paras);
    }

    /**
     * Determine the ownership window for this milestone. The convention
     * matches ProjectHealthService::computeMilestoneHealth so a "milestone
     * reached" post and the Project Compass widget describe the same set
     * of cards.
     *
     * Semantics:
     *   - previous = most recent dated milestone with date strictly < this.date
     *   - windowFrom = previous.date if present; else project.startDate; else null
     *   - windowTo   = this.date (inclusive on the deck-card / decision side)
     *
     * @return array{0:?Milestone, 1:?int, 2:int}
     */
    private function resolveWindow(Milestone $milestone, Project $project): array {
        $thisTs = (int)$milestone->getMilestoneDate();

        $siblings = $this->milestoneMapper->findByTeam($milestone->getTeamId());
        $prev = null;
        foreach ($siblings as $m) {
            $mts = $m->getMilestoneDate();
            if ($mts === null) continue;               // undated milestones don't own a window
            if ($m->getId() === $milestone->getId()) continue;
            if ((int)$mts >= $thisTs) continue;
            if ($prev === null || (int)$mts > (int)$prev->getMilestoneDate()) {
                $prev = $m;
            }
        }

        $windowFrom = $prev !== null
            ? (int)$prev->getMilestoneDate()
            : ($project->getStartDate() ?? null);

        return [$prev, $windowFrom, $thisTs];
    }

    /**
     * Deck-cards summary for one window.
     *
     * Ownership: card.duedate > windowFrom AND card.duedate <= windowTo.
     * "Done" mirrors ProjectHealthService: deck_cards.done is a datetime
     * / timestamp column — any non-empty value counts as completed.
     * Soft-deleted cards (deleted_at set) are skipped.
     *
     * @return array{total:int, done:int, open:int}
     */
    private function countDeckCardsInWindow(string $teamId, ?int $windowFrom, int $windowTo): array {
        $stacks = $this->timelineService->getDeckStacks($teamId);
        $stackIds = array_column($stacks, 'stackId');
        if (empty($stackIds)) {
            return ['total' => 0, 'done' => 0, 'open' => 0];
        }

        $cards = $this->fetchDeckCards($stackIds);

        $total = 0; $done = 0; $open = 0;
        foreach ($cards as $c) {
            $due = (int)($c['duedate'] ?? 0);
            if ($due <= 0) continue;
            if ($windowFrom !== null && $due <= $windowFrom) continue;
            if ($due > $windowTo) continue;

            $total++;
            $isDone = !empty($c['done']) && $c['done'] !== '0';
            if ($isDone) {
                $done++;
            } else {
                $open++;
            }
        }
        return ['total' => $total, 'done' => $done, 'open' => $open];
    }

    /**
     * Same query pattern as ProjectHealthService::fetchDeckCards, kept
     * local so a single service owns the milestone-reached body. Includes
     * the deleted_at column fallback for older Deck installs.
     *
     * @param int[] $stackIds
     * @return array<int, array{id:int, stack_id:int, duedate:?int, done:mixed, deleted_at:mixed}>
     */
    private function fetchDeckCards(array $stackIds): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'stack_id', 'duedate', 'done', 'deleted_at')
                ->from('deck_cards')
                ->where($qb->expr()->in(
                    'stack_id',
                    $qb->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY),
                ));
            $r = $qb->executeQuery();
            $out = [];
            while ($row = $r->fetch()) {
                if (!empty($row['deleted_at']) && $row['deleted_at'] !== 0 && $row['deleted_at'] !== '0') {
                    continue;
                }
                $out[] = $row;
            }
            $r->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MilestoneAutoPostService] deck_cards fetch failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('id', 'stack_id', 'duedate', 'done')
                    ->from('deck_cards')
                    ->where($qb->expr()->in(
                        'stack_id',
                        $qb->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY),
                    ));
                $r = $qb->executeQuery();
                $out = [];
                while ($row = $r->fetch()) {
                    $row['deleted_at'] = null;
                    $out[] = $row;
                }
                $r->closeCursor();
                return $out;
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * Decisions proposed inside the window, bucketed by status. Uses
     * created_at as the "in this period" date so the deck-cards and
     * decisions paragraphs describe the same reader-visible window.
     *
     * Buckets:
     *   - open    : status in {open, finalized, withdrawn}. Withdrawn
     *               proposals were not decided on; grouping them here
     *               keeps "open + decided = total" true, whereas the
     *               UI-visible "still awaiting" label glosses over the
     *               withdrawn distinction. Callers wanting the two apart
     *               should query the mapper directly.
     *   - decided : status in {approved, denied}.
     *
     * @return array{total:int, open:int, decided:int}
     */
    private function countDecisionsInWindow(string $teamId, ?int $windowFrom, int $windowTo): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('status')
                ->from('teamhub_decisions')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->andWhere($qb->expr()->lte(
                    'created_at',
                    $qb->createNamedParameter($windowTo, IQueryBuilder::PARAM_INT),
                ));
            if ($windowFrom !== null) {
                $qb->andWhere($qb->expr()->gt(
                    'created_at',
                    $qb->createNamedParameter($windowFrom, IQueryBuilder::PARAM_INT),
                ));
            }
            $r = $qb->executeQuery();
            $total = 0; $open = 0; $decided = 0;
            while ($row = $r->fetch()) {
                $total++;
                $s = (string)$row['status'];
                if (in_array($s, self::STATUS_DECIDED, true)) {
                    $decided++;
                } else {
                    // open, finalized, withdrawn — everything not decided
                    $open++;
                }
            }
            $r->closeCursor();
            return ['total' => $total, 'open' => $open, 'decided' => $decided];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MilestoneAutoPostService] decisions count failed: ' . $e->getMessage(), [
                'teamId' => $teamId, 'app' => Application::APP_ID,
            ]);
            return ['total' => 0, 'open' => 0, 'decided' => 0];
        }
    }

    /**
     * Combined global + per-team check. Mirrors DecisionService::isModuleActiveForTeam
     * without pulling that service's full dependency graph into a background
     * job — we only need two reads.
     */
    private function isDecisionsEnabled(string $teamId): bool {
        $global = $this->config->getAppValue(
            Application::APP_ID,
            'decisions_module_enabled',
            '1',
        ) === '1';
        if (!$global) {
            return false;
        }
        try {
            $row = $this->decisionConfigMapper->findByTeam($teamId);
            return $row !== null && $row->getDecisionsEnabled() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function formatDate(int $ts, IL10N $l10n): string {
        // IL10N::l returns a localised date/time string based on the target
        // language; falls back to ISO on failure so the message still ships.
        try {
            return (string)$l10n->l('date', new \DateTime('@' . $ts));
        } catch (\Throwable) {
            return date('Y-m-d', $ts);
        }
    }

    private function resolveUserLang(string $uid): string {
        // Best-effort resolve — fall back to English on any lookup miss. The
        // NC config lookup is not on IUserManager, but IL10N/IFactory does
        // the right thing when passed a non-existent locale (falls back to
        // English internally).
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return 'en';
        }
        // NC 20+: getEMailAddress / getUID etc. exist, but there's no
        // getLang(). We use the user config indirectly by asking l10n
        // factory to resolve a language for this user id.
        return $this->l10nFactory->getUserLanguage($user);
    }
}
