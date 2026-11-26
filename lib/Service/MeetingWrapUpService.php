<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\Decision;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\MeetingWrapUpMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\MyWork\Provider\MeetingWorkProvider;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Meeting wrap-up (v4.20.19, DESIGN §2.271).
 *
 * After a meeting made with Add meeting, its organiser gets a "Wrap up" row in
 * My Work (`MeetingWorkProvider`). The wrap-up does three things:
 *
 *  1. **Action items become team tasks**, each with the person and the due
 *     date, through `TaskService::createTeamTask`, which notifies the person
 *     and puts the task in their My Work. Justin, 2026-11-17: team tasks.
 *  2. **Proposals discussed get a link to the notes in their audit stream**
 *     (`meeting_discussed`). Nothing is decided here: the approvers still
 *     approve or deny in Decisions themselves, so a meeting cannot be used to
 *     push a proposal past them. Justin, 2026-11-17.
 *  3. **The outcome is written back into the notes file**, between markers,
 *     so the notes are the record of what came out of the meeting.
 *
 * Somebody may have taken notes during the meeting, or nobody did. `prepare()`
 * reads the notes' Action items and Proposals into the form when they are
 * there, and the organiser types what is missing; when the notes cannot be
 * found or read, the form still works and only the write-back is skipped.
 */
class MeetingWrapUpService {

    public function __construct(
        private MeetingWorkProvider $meetings,
        private MeetingWrapUpMapper $wrapUps,
        private MemberService $memberService,
        private TaskService $taskService,
        private DecisionMapper $decisionMapper,
        private DecisionService $decisionService,
        private DecisionAuditService $auditService,
        private TimezoneService $timezoneService,
        private IRootFolder $rootFolder,
        private IShareManager $shareManager,
        private IUserSession $userSession,
        private ICacheFactory $cacheFactory,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * What the wrap-up dialog needs.
     *
     * @return array<string,mixed>
     */
    public function prepare(string $teamId, int $objectId, int $start): array {
        $uid = $this->requireUser();
        $meeting = $this->loadMeeting($teamId, $uid, $objectId, $start);
        $members = $this->members($teamId);

        $notes = $this->findNotes($uid, $meeting);
        $parsed = $notes !== null
            ? MeetingNotes::parse($this->readSafely($notes))
            : ['date' => null, 'actionItems' => [], 'decisionIds' => []];

        $actionItems = array_map(
            static fn (string $line): array => MeetingNotes::parseActionItem($line, $members),
            $parsed['actionItems'],
        );

        $record = $this->wrapUps->find($teamId, $meeting['uid'], $meeting['start']);

        return [
            'meeting' => [
                'title'    => $meeting['title'],
                'start'    => $meeting['start'],
                'end'      => $meeting['end'],
                'notesUrl' => $meeting['notesUrl'],
            ],
            'notesFound'     => $notes !== null,
            'actionItems'    => $actionItems,
            'proposals'      => $this->proposals($teamId, $uid, $parsed['decisionIds']),
            'members'        => $members,
            'tasksAvailable' => $this->taskService->isTasksAppAvailable(),
            'wrapped'        => $record === null ? null : [
                'status' => $record['status'],
                'at'     => $record['created_at'],
            ],
        ];
    }

    /**
     * Wrap the meeting up.
     *
     * @param list<array{title?: mixed, assignee?: mixed, due?: mixed}> $tasks
     * @param list<mixed> $proposalIds
     * @return array{tasks: int, linked: int, notesUpdated: bool, failedTasks: list<string>}
     */
    public function complete(string $teamId, int $objectId, int $start, array $tasks, array $proposalIds): array {
        $uid = $this->requireUser();
        $meeting = $this->loadMeeting($teamId, $uid, $objectId, $start);

        if ($this->wrapUps->find($teamId, $meeting['uid'], $meeting['start']) !== null) {
            throw new ValidationException('This meeting was already wrapped up.');
        }

        // Validate everything before writing anything.
        $members = [];
        foreach ($this->members($teamId) as $m) {
            $members[$m['userId']] = $m['displayName'];
        }
        $clean = [];
        foreach (array_slice($tasks, 0, 50) as $task) {
            $title = trim((string)($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (mb_strlen($title) > 255) {
                throw new ValidationException('A task title can be at most 255 characters.');
            }
            $assignee = trim((string)($task['assignee'] ?? ''));
            if ($assignee !== '' && !isset($members[$assignee])) {
                throw new ValidationException('That person is not a member of this team.');
            }
            $due = trim((string)($task['due'] ?? ''));
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                throw new ValidationException('A due date must be a date.');
            }
            $clean[] = ['title' => $title, 'assignee' => $assignee, 'due' => $due];
        }
        if ($clean !== [] && !$this->taskService->isTasksAppAvailable()) {
            throw new ValidationException('Tasks need the Tasks app, which is not installed.');
        }

        $decisions = [];
        $wanted = array_values(array_unique(array_filter(array_map('intval', $proposalIds), static fn (int $id) => $id > 0)));
        foreach (array_slice($wanted, 0, 100) as $id) {
            $decision = $this->decisionMapper->findById($id);
            if ($decision === null || $decision->getTeamId() !== $teamId
                || !in_array($decision->getStatus(), ['open', 'finalized'], true)
                || !$this->decisionService->canViewDecision($decision, $uid)) {
                throw new ValidationException('A proposal you picked is not open in this team.');
            }
            $decisions[] = $decision;
        }

        $date = $this->localDate($uid, $meeting['start']);
        $description = $this->l->t('From the meeting %1$s on %2$s.', [$meeting['title'], $date])
            . ($meeting['notesUrl'] !== null ? "\n" . $this->l->t('Meeting notes: %1$s', [$meeting['notesUrl']]) : '');

        // 1. Tasks. One that fails does not stop the others; the answer names it.
        $made = [];
        $failed = [];
        foreach ($clean as $task) {
            $dueIso = null;
            if ($task['due'] !== '') {
                $dueIso = gmdate('Y-m-d\TH:i:s\Z', $this->timezoneService->wallClockToTimestamp($task['due'], '17:00', $uid));
            }
            try {
                $this->taskService->createTeamTask(
                    $teamId, $task['title'], $dueIso, $description, null,
                    $task['assignee'] !== '' ? $task['assignee'] : 'team',
                );
                $made[] = $task;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MeetingWrapUpService] task could not be created', [
                    'teamId' => $teamId, 'exception' => get_class($e), 'app' => Application::APP_ID,
                ]);
                $failed[] = $task['title'];
            }
        }

        // 2. Proposals: a line in each one's audit stream, nothing decided.
        foreach ($decisions as $decision) {
            $this->auditService->log($decision, 'meeting_discussed', $uid, [
                'meeting_title' => mb_substr($meeting['title'], 0, 255),
                'meeting_start' => $meeting['start'],
                'notes_url'     => $meeting['notesUrl'],
            ]);
        }

        // 3. The notes.
        $notesUpdated = false;
        $notes = $this->findNotes($uid, $meeting);
        if ($notes !== null) {
            try {
                $block = $this->renderBlock($uid, $made, $decisions, $members, $teamId);
                $notes->putContent(MeetingNotes::withBlock($this->readSafely($notes), $block));
                $notesUpdated = true;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][MeetingWrapUpService] notes could not be updated', [
                    'teamId' => $teamId, 'exception' => get_class($e), 'app' => Application::APP_ID,
                ]);
            }
        }

        $this->wrapUps->insert(
            $teamId, $meeting['uid'], $meeting['start'], $uid,
            MeetingWrapUpMapper::STATUS_DONE, count($made), count($decisions),
        );
        MyWorkService::bumpNonce($this->cacheFactory, $uid);

        return [
            'tasks'        => count($made),
            'linked'       => count($decisions),
            'notesUpdated' => $notesUpdated,
            'failedTasks'  => $failed,
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * The meeting as the calendar has it now, checked: the viewer is a
     * member, organised it, it has ended and it was made with Add meeting.
     *
     * @return array{uid: string, title: string, start: int, end: int, notesUrl: ?string}
     */
    private function loadMeeting(string $teamId, string $uid, int $objectId, int $start): array {
        $this->memberService->requireMemberLevel($teamId);

        $item = $this->meetings->getItem($uid, 'wrapup:' . $objectId . ':' . $start, [$teamId]);
        if ($item === null) {
            // Not found, not organised by this person, not over, or not made
            // with Add meeting: the provider builds no wrap-up row for any of
            // those, and none of them is this person's to wrap up.
            throw new NotFoundException('This meeting has no wrap-up for you.');
        }
        if (!($item->metadata['isOrganiser'] ?? false)) {
            throw new AccessDeniedException('Only the organiser can wrap up this meeting.');
        }

        return [
            'uid'      => (string)($item->metadata['eventUid'] ?? ''),
            // The row's title is "Wrap up {title}"; the dialog wants the meeting's own.
            'title'    => (string)($item->metadata['meetingTitle'] ?? $item->title),
            'start'    => (int)($item->metadata['startsAt'] ?? $start),
            'end'      => (int)($item->metadata['endsAt'] ?? $start),
            'notesUrl' => is_string($item->metadata['agendaUrl'] ?? null) ? $item->metadata['agendaUrl'] : null,
        ];
    }

    /**
     * The notes file of this occurrence, through the organiser's own files.
     *
     * The event's description links the notes of its first date. A later
     * date of a repeating meeting has its own file, made by the series job
     * under the default name in the same folder, so when the linked file is
     * for another day that file is looked up instead.
     *
     * @param array{uid: string, title: string, start: int, end: int, notesUrl: ?string} $meeting
     */
    private function findNotes(string $uid, array $meeting): ?File {
        if ($meeting['notesUrl'] === null || !preg_match('#/s/([A-Za-z0-9]+)#', $meeting['notesUrl'], $m)) {
            return null;
        }
        try {
            $share = $this->shareManager->getShareByToken($m[1]);
            $nodes = $this->rootFolder->getUserFolder($uid)->getById($share->getNodeId());
            $file = $nodes[0] ?? null;
            if (!$file instanceof File) {
                return null;
            }
            $date = $this->localDate($uid, $meeting['start']);
            $parsed = MeetingNotes::parse($this->readSafely($file));
            if ($parsed['date'] === null || $parsed['date'] === $date) {
                return $file;
            }
            $folder = $file->getParent();
            $base = MeetingNotes::defaultFilename($meeting['title'], $date);
            foreach ([$base . '.md', $base . '-1.md', $base . '-2.md'] as $name) {
                if ($folder->nodeExists($name)) {
                    $candidate = $folder->get($name);
                    if ($candidate instanceof File) {
                        return $candidate;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('[TeamHub][MeetingWrapUpService] notes not found', [
                'exception' => get_class($e), 'app' => Application::APP_ID,
            ]);
        }
        return null;
    }

    private function readSafely(File $file): string {
        try {
            return (string)$file->getContent();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<array{userId: string, displayName: string}>
     */
    private function members(string $teamId): array {
        $out = [];
        foreach ($this->memberService->getAllEffectiveMembers($teamId) as $m) {
            $id = (string)($m['userId'] ?? '');
            if ($id !== '') {
                $out[] = ['userId' => $id, 'displayName' => (string)($m['displayName'] ?? $id)];
            }
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));
        return $out;
    }

    /**
     * The team's open proposals the viewer can see; those listed in the
     * notes come first and are marked.
     *
     * @param list<int> $inNotes
     * @return list<array{id: int, question: string, status: string, inNotes: bool}>
     */
    private function proposals(string $teamId, string $uid, array $inNotes): array {
        if (!$this->decisionService->isModuleActiveForTeam($teamId)) {
            return [];
        }
        try {
            $rows = $this->decisionMapper->list($teamId, ['status' => ['open', 'finalized']], 'recent', null, 100);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $d) {
            if (!$this->decisionService->canViewDecision($d, $uid)) {
                continue;
            }
            $out[] = [
                'id'       => (int)$d->getId(),
                'question' => (string)$d->getQuestion(),
                'status'   => (string)$d->getStatus(),
                'inNotes'  => in_array((int)$d->getId(), $inNotes, true),
            ];
        }
        usort($out, static fn ($a, $b) => ((int)$b['inNotes'] <=> (int)$a['inNotes']));
        return $out;
    }

    /**
     * The block written into the notes, in the organiser's language.
     *
     * @param list<array{title: string, assignee: string, due: string}> $tasks
     * @param list<Decision> $decisions
     * @param array<string,string> $members uid => display name
     */
    private function renderBlock(string $uid, array $tasks, array $decisions, array $members, string $teamId): string {
        $who = $members[$uid] ?? $uid;
        $lines = [
            '## ' . $this->l->t('Wrap-up'),
            $this->l->t('Wrapped up by %1$s on %2$s.', [MeetingNotes::escape($who), $this->localDate($uid, time())]),
            '',
            '### ' . $this->l->t('Tasks'),
        ];
        if ($tasks === []) {
            $lines[] = $this->l->t('No tasks were handed out.');
        }
        foreach ($tasks as $task) {
            $name = $task['assignee'] !== '' ? ($members[$task['assignee']] ?? $task['assignee']) : $this->l->t('the team');
            $lines[] = '- ' . ($task['due'] !== ''
                ? $this->l->t('%1$s (%2$s, due %3$s)', [MeetingNotes::escape($task['title']), MeetingNotes::escape($name), $task['due']])
                : $this->l->t('%1$s (%2$s)', [MeetingNotes::escape($task['title']), MeetingNotes::escape($name)]));
        }
        if ($decisions !== []) {
            $lines[] = '';
            $lines[] = '### ' . $this->l->t('Proposals discussed');
            $lines[] = $this->l->t('The approvers decide on these in Decisions.');
            foreach ($decisions as $d) {
                $lines[] = '- ' . MeetingNotes::escape((string)$d->getQuestion());
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private function localDate(string $uid, int $ts): string {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone($this->timezoneService->forUser($uid))
            ->format('Y-m-d');
    }

    private function requireUser(): string {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            throw new AccessDeniedException('Not signed in.');
        }
        return $uid;
    }
}
