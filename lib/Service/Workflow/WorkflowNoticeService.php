<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Workflow;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\SendServiceNoticeJob;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Db\WorkflowInstanceMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Service\ServiceTeam\RequestForm;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowEventType;
use OCA\TeamHub\Workflow\WorkflowStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The notices a service sends when a step is reached, done or rejected
 * (v4.20.62, phase 8i; `docs/service-builder.md` § 14.5).
 *
 * A notice is never a step: nothing in it acts on the request. It goes to
 * accounts on this server only, at the address in their account, and it
 * carries the team's own words plus the answers that were ticked. Never the
 * conversation, internal notes or files. A ticked answer is in the **email
 * only**: a notification keeps its text in a table until somebody dismisses
 * it (`docs/unlicensed-workflow-data-lifecycle.md`).
 *
 * Three moments. `plan()` runs inside the engine's transaction and says who a
 * notice goes to, for the history. `queue()` runs after the commit.
 * `deliver()` runs from cron (`SendServiceNoticeJob`) and reads the notice
 * from the step row, where it was copied when the request started: a service
 * published again does not change what a running request sends.
 */
class WorkflowNoticeService {

    public const OBJECT_TYPE = 'workflow_notice';
    public const SUBJECT     = 'service_notice';

    public const ON_REACHED  = 'reached';
    public const ON_DONE     = 'done';
    public const ON_REJECTED = 'rejected';
    public const MOMENTS     = [self::ON_REACHED, self::ON_DONE, self::ON_REJECTED];

    public const TO_REQUESTER = 'requester';
    /** Whoever has the task, or the approver of an approval step. */
    public const TO_TASK      = 'task';
    public const TO_USER      = 'user';
    public const TO_GROUP     = 'group';
    public const RECIPIENTS   = [self::TO_REQUESTER, self::TO_TASK, self::TO_USER, self::TO_GROUP];

    public const CHANNEL_BELL  = 'bell';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNELS      = [self::CHANNEL_BELL, self::CHANNEL_EMAIL];

    /** A notice to more people than this is not sent: it is not a newsletter. */
    public const MAX_RECIPIENTS = 100;

    /** Why a notice, or part of it, was not sent. */
    public const WHY_NOBODY    = 'nobody';
    public const WHY_TOO_MANY  = 'too_many';
    public const WHY_EMAIL_OFF = 'email_off';
    public const WHY_EMAIL     = 'email';

    /** Uids per job: a job's argument is at most 4000 characters of JSON. */
    private const PER_JOB = 8;

    public function __construct(
        private IJobList                   $jobs,
        private IUserManager               $userManager,
        private IGroupManager              $groupManager,
        private WorkflowActorResolver      $resolver,
        private ServiceTeamService         $serviceTeams,
        private WorkflowInstanceMapper     $instances,
        private WorkflowStepMapper         $steps,
        private WorkflowEventService       $events,
        private WorkflowDefinitionRegistry $definitions,
        private INotificationManager       $notifications,
        private IMailer                    $mailer,
        private IFactory                   $l10nFactory,
        private IURLGenerator              $urlGenerator,
        private ITimeFactory               $timeFactory,
        private LoggerInterface            $logger,
    ) {
    }

    // ── In the engine's transaction ─────────────────────────────────────

    /**
     * The notices this row sends at this moment: to whom and how, or why not.
     *
     * @param WorkflowStep[] $stage the rows of the step `$row` is in
     * @return list<array{index: int, uids: list<string>, channels: list<string>, why: string, payload: array<string, mixed>}>
     */
    public function plan(WorkflowInstance $instance, WorkflowStep $row, array $stage, string $on): array {
        if (!$this->serviceTeams->isAvailable()) {
            return [];
        }
        $out = [];
        foreach (array_values((array)($row->configMap()['notify'] ?? [])) as $index => $notice) {
            if (!is_array($notice) || ($notice['on'] ?? '') !== $on) {
                continue;
            }
            $uids     = $this->recipients($instance, $notice, $stage, $on);
            $channels = array_values(array_intersect(self::CHANNELS, (array)($notice['channels'] ?? [])));
            if (!$this->serviceTeams->mayEmail()) {
                $channels = array_values(array_diff($channels, [self::CHANNEL_EMAIL]));
            }
            $why = '';
            if ($uids === []) {
                $why = self::WHY_NOBODY;
            } elseif (count($uids) > self::MAX_RECIPIENTS) {
                $why  = self::WHY_TOO_MANY;
                $uids = [];
            } elseif ($channels === []) {
                $why = self::WHY_EMAIL_OFF;
            }
            $out[] = [
                'index'    => $index,
                'uids'     => $uids,
                'channels' => $channels,
                'why'      => $why,
                'payload'  => [
                    'on'       => $on,
                    'subject'  => (string)($notice['subject'] ?? ''),
                    'uids'     => $uids,
                    'channels' => $channels,
                ] + ($why !== '' ? ['why' => $why] : []),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $notice
     * @param WorkflowStep[] $stage
     * @return list<string>
     */
    private function recipients(WorkflowInstance $instance, array $notice, array $stage, string $on): array {
        $to = is_array($notice['to'] ?? null) ? $notice['to'] : [];
        $id = (string)($to['id'] ?? '');
        switch ((string)($to['type'] ?? '')) {
            case self::TO_REQUESTER:
                $uids = [$instance->getStartedBy()];
                break;
            case self::TO_TASK:
                $uids = $this->holdersOf($instance, $stage, $on);
                break;
            case self::TO_USER:
                $uids = [$id];
                break;
            case self::TO_GROUP:
                $uids = [];
                // One more than may be told, which is how "too many" shows.
                foreach ($this->groupManager->get($id)?->searchUsers('', self::MAX_RECIPIENTS + 1) ?? [] as $user) {
                    if ($user instanceof IUser) {
                        $uids[] = $user->getUID();
                    }
                }
                break;
            default:
                return [];
        }
        return array_values(array_unique(array_filter(
            $uids,
            fn (string $uid): bool => $uid !== '' && ($this->userManager->get($uid)?->isEnabled() ?? false),
        )));
    }

    /**
     * Whoever has the step: the people who worked on it once it is answered,
     * and whoever may take it up until then. Never the requester on an
     * approval, which they cannot answer.
     *
     * @param WorkflowStep[] $stage
     * @return list<string>
     */
    private function holdersOf(WorkflowInstance $instance, array $stage, string $on): array {
        $uids = [];
        foreach ($stage as $row) {
            $own = array_filter([$row->getAssignee(), $on === self::ON_REACHED ? '' : (string)$row->getCompletedBy()]);
            if ($own === []) {
                $own = $this->resolver->holdersOf(WorkflowActor::of($row->getActorType(), $row->getActorId()), $instance->getTeamId());
            }
            foreach ($own as $uid) {
                if (!$row->isApproval() || $uid !== $instance->getStartedBy()) {
                    $uids[] = $uid;
                }
            }
        }
        return $uids;
    }

    // ── After the commit ────────────────────────────────────────────────

    /**
     * @param list<string> $uids
     * @param list<string> $channels
     */
    public function queue(int $instanceId, string $stepKey, int $index, array $uids, array $channels): void {
        foreach (array_chunk($uids, self::PER_JOB) as $chunk) {
            $this->jobs->add(SendServiceNoticeJob::class, [
                'instance' => $instanceId,
                'step'     => $stepKey,
                'notice'   => $index,
                'uids'     => $chunk,
                'channels' => $channels,
            ]);
        }
    }

    /** The request is gone: its notices leave the bell too. */
    public function withdraw(int $instanceId): void {
        try {
            $n = $this->notifications->createNotification();
            $n->setApp(Application::APP_ID)->setObject(self::OBJECT_TYPE, (string)$instanceId);
            $this->notifications->markProcessed($n);
        } catch (\Throwable $e) {
            $this->warn('notices could not be withdrawn', $instanceId, $e);
        }
    }

    // ── From cron ───────────────────────────────────────────────────────

    /**
     * @param list<string> $uids
     * @param list<string> $channels
     * @param callable(string): bool $mayOpen whether this person may open the request
     */
    public function deliver(int $instanceId, string $stepKey, int $index, array $uids, array $channels, callable $mayOpen): void {
        $instance = $this->instances->findById($instanceId);
        if ($instance === null || !$this->serviceTeams->isAvailable()) {
            return;
        }
        $step = null;
        foreach ($this->steps->findByInstance($instanceId) as $row) {
            if ($row->getStepKey() === $stepKey) {
                $step = $row;
            }
        }
        $notice = $step !== null ? ($step->configMap()['notify'][$index] ?? null) : null;
        if ($step === null || !is_array($notice)) {
            return;
        }
        // The switch may have gone off since.
        $email  = in_array(self::CHANNEL_EMAIL, $channels, true) && $this->serviceTeams->mayEmail();
        $bell   = in_array(self::CHANNEL_BELL, $channels, true);
        $failed = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            if ($user === null || !$user->isEnabled()) {
                continue;
            }
            $open = $mayOpen($uid);
            if ($bell) {
                $this->bell($instance, $notice, $uid, $open);
            }
            if ($email && !$this->email($instance, $step, $notice, $user, $open)) {
                $failed[] = $uid;
            }
        }
        if ($failed === []) {
            return;
        }
        if (!WorkflowStatus::isOpen($instance->getStatus())) {
            // Nothing is written to a request that has ended: its record is sealed.
            $this->logger->warning('[TeamHub][Workflow] a notice could not be emailed', [
                'instance' => $instanceId, 'people' => count($failed), 'app' => Application::APP_ID,
            ]);
            return;
        }
        $this->events->record($instanceId, WorkflowEventType::NOTICE_FAILED, null, $step, [
            'on'      => (string)($notice['on'] ?? ''),
            'subject' => (string)($notice['subject'] ?? ''),
            'uids'    => $failed,
            'why'     => self::WHY_EMAIL,
        ], WorkflowEventType::VISIBILITY_INTERNAL);
    }

    /** @param array<string, mixed> $notice */
    private function bell(WorkflowInstance $instance, array $notice, string $uid, bool $open): void {
        try {
            $n = $this->notifications->createNotification();
            $n->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject(self::OBJECT_TYPE, (string)$instance->getId())
                ->setSubject(self::SUBJECT, [
                    'service' => $this->serviceName($instance),
                    'subject' => self::oneLine((string)($notice['subject'] ?? '')),
                    'text'    => (string)($notice['text'] ?? ''),
                    'open'    => $open ? '1' : '0',
                ]);
            $this->notifications->notify($n);
        } catch (\Throwable $e) {
            $this->warn('a notice could not be notified', (int)$instance->getId(), $e);
        }
    }

    /** @param array<string, mixed> $notice */
    private function email(WorkflowInstance $instance, WorkflowStep $step, array $notice, IUser $user, bool $open): bool {
        $address = (string)$user->getEMailAddress();
        if ($address === '' || !$this->mailer->validateMailAddress($address)) {
            return false;
        }
        try {
            $language = $this->l10nFactory->getUserLanguage($user);
            $l        = $this->l10nFactory->get(Application::APP_ID, $language);
            $service  = $this->serviceName($instance);
            $subject  = self::oneLine((string)($notice['subject'] ?? ''));

            $template = $this->mailer->createEMailTemplate('teamhub.serviceNotice');
            // TRANSLATORS: %1$s is the name of a service, %2$s the requester's own one-line summary
            $template->setSubject(self::oneLine($l->t('%1$s: %2$s', [$service, $subject])));
            $template->addHeader();
            $template->addHeading($subject);
            // Every text goes in without a plain version of its own: that is
            // what makes the template escape it.
            foreach (preg_split('/\n+/', trim((string)($notice['text'] ?? ''))) ?: [] as $line) {
                if (trim($line) !== '') {
                    $template->addBodyText(trim($line));
                }
            }
            foreach ($this->facts($l, $instance, $step, $notice, $service) as [$label, $text]) {
                // TRANSLATORS: %1$s is the name of a field on a request form, %2$s what was filled in
                // No detail beside it: Nextcloud prints an empty "()" after
                // every plain-text line unless told there is none (v4.20.65).
                $template->addBodyListItem($l->t('%1$s: %2$s', [$label, $text]), '', '', '', false);
            }
            if ($open) {
                // TRANSLATORS: button in an email about a service request
                $template->addBodyButton($l->t('Open the request'),
                    $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index', ['mywork' => '1']));
            }
            // TRANSLATORS: last line of an email; %s is the name of a service
            $template->addBodyText($l->t('You get this email because the team that offers "%s" asked TeamHub to tell you about this step.', [$service]));
            $template->addFooter('', $language);

            $message = $this->mailer->createMessage();
            $message->setTo([$address => $user->getDisplayName()]);
            $message->useTemplate($template);
            // No out-of-office replies to it.
            $message->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);
            return $this->mailer->send($message) === [];
        } catch (\Throwable $e) {
            $this->warn('a notice could not be emailed', (int)$instance->getId(), $e);
            return false;
        }
    }

    /**
     * What the email lists: the service, the step, and what the team ticked.
     *
     * @param array<string, mixed> $notice
     * @return list<array{0: string, 1: string}>
     */
    private function facts(IL10N $l, WorkflowInstance $instance, WorkflowStep $step, array $notice, string $service): array {
        $stepName = $step->getStageLabel() !== '' ? $step->getStageLabel() : $step->getLabel();
        $facts    = [
            [$l->t('Service'), $service],
            [$l->t('Step'), $stepName],
        ];
        if (!empty($notice['requester'])) {
            $uid     = $instance->getStartedBy();
            $facts[] = [$l->t('Requested by'), $this->userManager->get($uid)?->getDisplayName() ?? $uid];
        }
        $form = $instance->getData()['form'] ?? [];
        return array_merge($facts, RequestForm::answersFor($l, is_array($form) ? $form : [], array_map('strval', (array)($notice['fields'] ?? []))));
    }

    /** The service as the request was made under. */
    private function serviceName(WorkflowInstance $instance): string {
        $title = trim((string)($instance->getData()['serviceTitle'] ?? ''));
        return $title !== '' ? $title : ($this->definitions->get($instance->getDefinitionKey())?->getName() ?? $instance->getDefinitionKey());
    }

    /** No line breaks or other control characters: this becomes a mail header. */
    private static function oneLine(string $text): string {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    private function warn(string $what, int $instanceId, \Throwable $e): void {
        $this->logger->warning('[TeamHub][Workflow] ' . $what, [
            'instance' => $instanceId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
        ]);
    }
}
