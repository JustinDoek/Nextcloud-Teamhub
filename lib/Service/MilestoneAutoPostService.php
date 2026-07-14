<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Milestone;
use OCA\TeamHub\Db\MilestoneMapper;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\ProjectMapper;
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
 */
class MilestoneAutoPostService {

    public function __construct(
        private MilestoneMapper $milestoneMapper,
        private MessageMapper   $messageMapper,
        private ProjectMapper   $projectMapper,
        private IUserManager    $userManager,
        private IFactory        $l10nFactory,
        private LoggerInterface $logger,
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

                $this->postForMilestone($milestone);
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
    private function postForMilestone(Milestone $milestone): void {
        $authorUid = $milestone->getCreatedBy();
        $lang      = $this->resolveUserLang($authorUid);
        $l10n      = $this->l10nFactory->get(Application::APP_ID, $lang);

        // TRANSLATORS: system-posted stream message subject when a milestone's date has passed
        $subject = $l10n->t('Milestone reached: %s', [$milestone->getLabel()]);
        $body    = $l10n->t(
            'The milestone “%s” was scheduled for today. Confirm progress with the team.',
            [$milestone->getLabel()]
        );

        $this->messageMapper->create(
            $milestone->getTeamId(),
            $authorUid,
            $subject,
            $body,
            'normal',
            'normal',
            null,
        );
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
