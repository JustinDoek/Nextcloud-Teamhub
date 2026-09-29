<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Constants\ServiceIcons;
use OCA\TeamHub\Db\TeamService;
use OCA\TeamHub\Db\TeamServiceMapper;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The service builder (v4.10.33, WorkflowHub phase 8a;
 * `docs/service-builder.md`) — what a service team's admins use to build,
 * publish and unpublish the services the team offers itself.
 *
 * ## The document
 *
 * A service is one JSON document, the one `TeamServiceDefinition` reads.
 * **`normalise()` is the only writer of its shape**: every save goes through
 * it, so a row never holds a key the definition does not know or a value it
 * would have to guard against.
 *
 *     title        the card's name and the request's title (required)
 *     description  what the card says under it
 *     category     one of the administrator's categories (v4.10.45,
 *                  `ServiceCategoryService`) — a team picks a heading, it
 *                  does not invent one (`/service-teams`)
 *     icon         v4.10.45, the card's icon: one of `ServiceIcons::ALLOWED`,
 *                  or '' for the default
 *     leadDays     *Usually within {n} working days*; 0 = none said
 *     askTeam      whether the form asks which team the request is for
 *                  (stored now; personal requests land in a later step)
 *     steps        [{kind: desk|requester, label, tasks}] — what runs
 *                  between the fixed *Request submitted* and *Requester
 *                  confirms*. A step is the team's or the requester's, and
 *                  holds one or more **tasks** done in parallel (v4.10.37,
 *                  `docs/service-builder.md` § 4):
 *                  [{label, role, nonBlocking, links}]. `role` and
 *                  `nonBlocking` mean something on a team task only.
 *                  `links` (v4.10.36, § 6.1) is [{label, url, kind:
 *                  link|form}]: on a team task the team's own material, on
 *                  a requester task the thing to do — clicking it is the
 *                  requester doing the task
 *     files        v4.10.38, the paperclip (§ 7): {allowed, edit, days} —
 *                  whether people on a request may attach files, whether
 *                  the other side may edit them, and for how many days they
 *                  are shared. Copied into each request when it starts
 *
 * **Older shapes are read, never written.** A step saved before v4.10.37
 * carried `role` and `links` itself; it is read as a step with one task
 * that has them. A `start: link` document (v4.10.36, withdrawn in v4.10.37:
 * Justin, *"We want 1 process"*) loses its `start` and `startUrl` here, and
 * `TeamServiceDefinition` keeps a published one off the Services page until
 * the team publishes it again.
 *
 * ## Draft and published
 *
 * The builder edits the **draft**. **Publishing** copies it to *published*,
 * raises `pub_version` and lists the service on the Services page; a request
 * copies the steps onto its own rows when it starts, so a later publish
 * never changes one that is running. A draft may be incomplete — no steps
 * yet, a step without a name — and `publishProblems()` says what stands
 * between it and publishing. A draft is still validated for what it
 * *holds*: lengths, kinds, categories, control characters.
 *
 * **Unpublishing** takes the card off the Services page at once; the
 * definition stays registered (dark), so its running requests finish.
 * **Deleting** is for a service that was never published: once requests
 * may reference it, the row is their history.
 *
 * ## Who
 *
 * The controller checks the role (a team admin of a Service-template team
 * to write, any member to read). This class checks the licence, that the
 * service belongs to the team named in the path, and the document.
 */
class TeamServiceBuilder {

    public const MAX_SERVICES    = 50;
    public const MAX_STEPS       = 10;
    public const MAX_TITLE       = 100;
    public const MAX_DESCRIPTION = 1000;
    public const MAX_STEP_LABEL  = 100;
    public const MAX_ROLE        = 64;
    public const MAX_LEAD_DAYS   = 60;
    public const MAX_LINKS       = 5;
    public const MAX_LINK_LABEL  = 100;
    public const MAX_URL         = 2000;
    public const MAX_TASKS       = 10;

    /**
     * What a link on a step is (v4.10.36): it decides the words and the icon
     * of its button. *Appointment* (gates G1–G3) and *a file in Nextcloud*
     * (G7) come later.
     */
    public const LINK_KIND_LINK = 'link';
    public const LINK_KIND_FORM = 'form';
    public const LINK_KINDS     = [self::LINK_KIND_LINK, self::LINK_KIND_FORM];

    public function __construct(
        private TeamServiceMapper  $mapper,
        private ServiceTeamService $serviceTeams,
        private AuditService       $auditService,
        private ITimeFactory       $timeFactory,
        private IL10N              $l,
        private LoggerInterface    $logger,
        // v4.10.45 — the categories a Nextcloud administrator set.
        private ServiceCategoryService $categories,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The team's services, drafts included, in display order.
     *
     * @return array<int, array<string, mixed>>
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function listForTeam(string $teamId): array {
        $this->serviceTeams->requireLicence();
        return array_map(fn (TeamService $row): array => $this->describe($row), $this->mapper->findByTeam($teamId));
    }

    /**
     * What the builder's pickers offer and the limits it enforces, so the
     * dialog can say them before the server refuses.
     *
     * @return array<string, mixed>
     */
    public function options(): array {
        $categories = [];
        foreach ($this->categories->list() as $category) {
            $categories[] = ['key' => $category['key'], 'label' => $category['label']];
        }
        return [
            'categories' => $categories,
            // v4.10.45 — the icons the builder offers.
            'icons'      => ServiceIcons::ALLOWED,
            'limits'     => [
                'services'    => self::MAX_SERVICES,
                'steps'       => self::MAX_STEPS,
                'title'       => self::MAX_TITLE,
                'description' => self::MAX_DESCRIPTION,
                'stepLabel'   => self::MAX_STEP_LABEL,
                'role'        => self::MAX_ROLE,
                'leadDays'    => self::MAX_LEAD_DAYS,
                'links'       => self::MAX_LINKS,
                'linkLabel'   => self::MAX_LINK_LABEL,
                'url'         => self::MAX_URL,
                'tasks'       => self::MAX_TASKS,
                'fileDays'    => WorkflowShareService::MAX_DAYS,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Writes
    // ──────────────────────────────────────────────────────────────────────

    /**
     * A new service, as a draft. Nothing is published or listed.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(string $teamId, array $input, string $uid): array {
        $this->serviceTeams->requireLicence();
        if ($this->mapper->countByTeam($teamId) >= self::MAX_SERVICES) {
            throw new ValidationException($this->l->n(
                'A team can offer at most %n service.',
                'A team can offer at most %n services.',
                self::MAX_SERVICES,
            ));
        }
        $doc = $this->normalise($input);
        $now = $this->timeFactory->getTime();

        $row = new TeamService();
        $row->setTeamId($teamId);
        $row->setDraft($this->encode($doc));
        $row->setPublished(null);
        $row->setPubVersion(0);
        $row->setListed(0);
        $row->setSortOrder($this->mapper->nextSortOrder($teamId));
        $row->setCreatedBy($uid);
        $row->setCreatedAt($now);
        $row->setUpdatedAt($now);
        $row = $this->mapper->insert($row);

        $this->audit($teamId, 'service_team.service_created', $uid, $row, ['title' => $doc['title']]);
        return $this->describe($row);
    }

    /**
     * Save the draft. A published service keeps answering on its published
     * version until the draft is published.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws NotFoundException|ValidationException
     */
    public function saveDraft(string $teamId, int $serviceId, array $input, string $uid): array {
        $this->serviceTeams->requireLicence();
        $row = $this->load($teamId, $serviceId);
        $row->setDraft($this->encode($this->normalise($input)));
        $row->setUpdatedAt($this->timeFactory->getTime());
        $row = $this->mapper->update($row);
        return $this->describe($row);
    }

    /**
     * Publish the draft: it becomes what requests start from, the version
     * goes up, the card goes on the Services page, and the team becomes a
     * desk if it was not one yet.
     *
     * @return array<string, mixed>
     * @throws NotFoundException|ValidationException
     */
    public function publish(string $teamId, int $serviceId, string $uid): array {
        $this->serviceTeams->requireLicence();
        $row = $this->load($teamId, $serviceId);
        // Re-normalised rather than trusted: the draft may predate a rule.
        $doc      = $this->normalise($row->draftDocument());
        $problems = $this->publishProblems($doc);
        if ($problems !== []) {
            throw new ValidationException(implode(' ', $problems));
        }

        // The desk first: a listed service whose team is not active would be
        // a card nobody can answer (`TeamServiceDefinition::isStartable()`).
        $this->serviceTeams->activate($teamId, $uid);

        $now = $this->timeFactory->getTime();
        $row->setDraft($this->encode($doc));
        $row->setPublished($this->encode($doc));
        $row->setPubVersion($row->getPubVersion() + 1);
        $row->setListed(1);
        $row->setPublishedBy($uid);
        $row->setPublishedAt($now);
        $row->setUpdatedAt($now);
        $row = $this->mapper->update($row);

        $this->audit($teamId, 'service_team.service_published', $uid, $row, [
            'title'   => $doc['title'],
            'version' => $row->getPubVersion(),
        ]);
        return $this->describe($row);
    }

    /**
     * Take the card off the Services page. Running requests finish on the
     * version they started on; the team stays a desk for them.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function unpublish(string $teamId, int $serviceId, string $uid): array {
        $this->serviceTeams->requireLicence();
        $row = $this->load($teamId, $serviceId);
        if (!$row->isListed()) {
            return $this->describe($row);
        }
        $row->setListed(0);
        $row->setUpdatedAt($this->timeFactory->getTime());
        $row = $this->mapper->update($row);

        $this->audit($teamId, 'service_team.service_unpublished', $uid, $row, [
            'title' => (string)($row->publishedDocument()['title'] ?? ''),
        ]);
        return $this->describe($row);
    }

    /**
     * Delete a service that was never published. A published one is
     * unpublished instead: requests may reference it, and the row is what
     * gives them their title and steps.
     *
     * @throws NotFoundException|ValidationException
     */
    public function delete(string $teamId, int $serviceId, string $uid): void {
        $this->serviceTeams->requireLicence();
        $row = $this->load($teamId, $serviceId);
        if ($row->getPubVersion() > 0) {
            throw new ValidationException($this->l->t('A service that has been published cannot be deleted. Unpublish it instead.'));
        }
        $title = (string)($row->draftDocument()['title'] ?? '');
        $this->mapper->delete($row);
        $this->audit($teamId, 'service_team.service_deleted', $uid, $row, ['title' => $title]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The document
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The document's one shape. Unknown keys are dropped; a value of the
     * wrong kind is refused with a sentence rather than coerced into
     * something the team did not write.
     *
     * @param array<string, mixed> $input
     * @return array{title: string, description: string, category: string, icon: string, leadDays: int, askTeam: bool, steps: array<int, array{kind: string, label: string, tasks: array<int, array{label: string, role: string, nonBlocking: bool, links: array<int, array{label: string, url: string, kind: string}>}>}>}
     * @throws ValidationException
     */
    public function normalise(array $input): array {
        $title = $this->line($input['title'] ?? '', self::MAX_TITLE,
            // TRANSLATORS: %s is a number of characters
            $this->l->t('The name of the service is at most %s characters.', [self::MAX_TITLE]));
        if ($title === '') {
            throw new ValidationException($this->l->t('Give the service a name.'));
        }

        $description = $this->text($input['description'] ?? '', self::MAX_DESCRIPTION,
            // TRANSLATORS: %s is a number of characters
            $this->l->t('The description is at most %s characters.', [self::MAX_DESCRIPTION]));

        $category = (string)($input['category'] ?? '');
        if ($category === '') {
            $category = $this->categories->resolve(ServiceCatalogue::CATEGORY_SUPPORT);
        }
        if (!$this->categories->exists($category)) {
            throw new ValidationException($this->l->t('Pick one of the categories.'));
        }

        // v4.10.45 — '' is the default icon; anything else must be offered.
        $icon = trim((string)($input['icon'] ?? ''));
        if ($icon !== '' && !ServiceIcons::isAllowed($icon)) {
            throw new ValidationException($this->l->t('Pick one of the icons.'));
        }

        $leadDays = $input['leadDays'] ?? 0;
        if ($leadDays === null || $leadDays === '') {
            $leadDays = 0;
        }
        if (!is_int($leadDays) && !(is_string($leadDays) && ctype_digit($leadDays))) {
            throw new ValidationException($this->l->t('The lead time is a whole number of working days.'));
        }
        $leadDays = (int)$leadDays;
        if ($leadDays < 0 || $leadDays > self::MAX_LEAD_DAYS) {
            // TRANSLATORS: %s is the largest number of working days a service may promise
            throw new ValidationException($this->l->t('Set a lead time of at most %s working days, or leave it empty.', [self::MAX_LEAD_DAYS]));
        }

        $rawSteps = $input['steps'] ?? [];
        if (!is_array($rawSteps) || !array_is_list($rawSteps)) {
            throw new ValidationException($this->l->t('The steps could not be read.'));
        }
        if (count($rawSteps) > self::MAX_STEPS) {
            throw new ValidationException($this->l->n(
                'A service has at most %n step.',
                'A service has at most %n steps.',
                self::MAX_STEPS,
            ));
        }
        $steps = [];
        foreach ($rawSteps as $raw) {
            if (!is_array($raw)) {
                throw new ValidationException($this->l->t('The steps could not be read.'));
            }
            $kind = (string)($raw['kind'] ?? '');
            if (!in_array($kind, [TeamServiceDefinition::KIND_DESK, TeamServiceDefinition::KIND_REQUESTER], true)) {
                throw new ValidationException($this->l->t('A step is either for the team or for the requester.'));
            }
            $label = $this->line($raw['label'] ?? '', self::MAX_STEP_LABEL,
                // TRANSLATORS: %s is a number of characters
                $this->l->t('The name of a step is at most %s characters.', [self::MAX_STEP_LABEL]));
            $steps[] = ['kind' => $kind, 'label' => $label, 'tasks' => $this->tasks($kind, $raw)];
        }

        return [
            'title'       => $title,
            'description' => $description,
            'category'    => $category,
            'icon'        => $icon,
            'leadDays'    => $leadDays,
            // Strict: the string "false" is not a yes.
            'askTeam'     => in_array($input['askTeam'] ?? false, [true, 1, '1', 'true'], true),
            'steps'       => $steps,
            'files'       => $this->files($input['files'] ?? []),
        ];
    }

    /**
     * What stands between a draft and publishing it, in the viewer's
     * language; empty when nothing does.
     *
     * @param array<string, mixed> $doc a normalised document
     * @return string[]
     */
    public function publishProblems(array $doc): array {
        $problems    = [];
        $steps       = (array)($doc['steps'] ?? []);
        $hasDesk     = false;
        $unnamed     = false;
        $unnamedTask = false;
        $allOptional = false;
        $badLink     = false;
        foreach ($steps as $step) {
            $hasDesk = $hasDesk || ($step['kind'] ?? '') === TeamServiceDefinition::KIND_DESK;
            $unnamed = $unnamed || trim((string)($step['label'] ?? '')) === '';
            $tasks   = (array)($step['tasks'] ?? []);
            $required = 0;
            foreach ($tasks as $task) {
                // One task may borrow the step's name; with several, each
                // needs its own, or the queue lists them all alike.
                $unnamedTask = $unnamedTask || (count($tasks) > 1 && trim((string)($task['label'] ?? '')) === '');
                $required   += empty($task['nonBlocking']) ? 1 : 0;
                foreach ((array)($task['links'] ?? []) as $link) {
                    $badLink = $badLink
                        || trim((string)($link['label'] ?? '')) === ''
                        || trim((string)($link['url'] ?? '')) === '';
                }
            }
            // A step whose every task may be left open would never be waited
            // for: the request would pass it the moment it arrived.
            $allOptional = $allOptional || $required === 0;
        }
        if (!$hasDesk) {
            $problems[] = $this->l->t('Add at least one step for the team.');
        }
        if ($unnamed) {
            $problems[] = $this->l->t('Give every step a name.');
        }
        if ($unnamedTask) {
            $problems[] = $this->l->t('Give every task a name when a step has more than one.');
        }
        if ($allOptional) {
            $problems[] = $this->l->t('Every step needs at least one task the request waits for.');
        }
        if ($badLink) {
            $problems[] = $this->l->t('Give every link a name and an address.');
        }
        // v4.10.39 — a service may start with the requester (Justin,
        // 2026-09-25: a form to fill in first). v4.10.35 had refused it
        // because such a request did not show on the team; since then the
        // queue's *With requester* tab lists it, and the request form hands
        // the requester their first task straight after sending.
        // A requester action is something done *before the team continues*
        // (`docs/service-builder.md` § 2), so the team has a step after it.
        $last = $steps === [] ? null : $steps[array_key_last($steps)];
        if ($last !== null && ($last['kind'] ?? '') === TeamServiceDefinition::KIND_REQUESTER) {
            $problems[] = $this->l->t('The last step is for the team, not the requester.');
        }
        return $problems;
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * One service as the builder renders it.
     *
     * @return array<string, mixed>
     */
    private function describe(TeamService $row): array {
        $draft     = $row->draftDocument();
        $published = $row->getPubVersion() > 0 ? $row->publishedDocument() : null;
        try {
            $problems = $this->publishProblems($this->normalise($draft));
        } catch (ValidationException $e) {
            $problems = [$e->getMessage()];
        }
        return [
            'id'            => (int)$row->getId(),
            'teamId'        => $row->getTeamId(),
            'definitionKey' => TeamServiceDefinition::keyFor((int)$row->getId()),
            'draft'         => $draft,
            'published'     => $published,
            'version'       => $row->getPubVersion(),
            'listed'        => $row->isListed(),
            // The draft differs from what requests start from: publishing
            // would change something. Always true for a never-published one.
            'hasChanges'    => $published === null || $this->encode($draft) !== $this->encode($published),
            'publishProblems' => $problems,
            'createdBy'     => $row->getCreatedBy(),
            'createdAt'     => $row->getCreatedAt(),
            'updatedAt'     => $row->getUpdatedAt(),
            'publishedBy'   => $row->getPublishedBy(),
            'publishedAt'   => $row->getPublishedAt(),
        ];
    }

    /** @throws NotFoundException the service does not exist, or belongs to another team */
    private function load(string $teamId, int $serviceId): TeamService {
        $row = $this->mapper->findById($serviceId);
        if ($row === null || $row->getTeamId() !== $teamId) {
            throw new NotFoundException($this->l->t('Service not found'));
        }
        return $row;
    }

    /**
     * A one-line field: trimmed, no line breaks or other control characters,
     * at most $max characters. `$tooLong` is the field's own sentence.
     */
    private function line(mixed $value, int $max, string $tooLong): string {
        if (!is_string($value) && !is_int($value)) {
            throw new ValidationException($this->l->t('The service could not be read.'));
        }
        $value = trim((string)$value);
        if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new ValidationException($this->l->t('Names and roles cannot contain line breaks or special characters.'));
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationException($tooLong);
        }
        return $value;
    }

    /**
     * What the paperclip may do on this service's requests (v4.10.38): allowed
     * unless switched off, view only unless switched to edit, shared for a
     * number of days (default 14, at most `WorkflowShareService::MAX_DAYS`).
     * The administrator's enforced maximum still caps a share when it is made.
     *
     * @return array{allowed: bool, edit: bool, days: int}
     */
    private function files(mixed $raw): array {
        if (!is_array($raw)) {
            throw new ValidationException($this->l->t('The file settings could not be read.'));
        }
        $days = $raw['days'] ?? WorkflowShareService::DEFAULT_DAYS;
        if ($days === '' || $days === null) {
            $days = WorkflowShareService::DEFAULT_DAYS;
        }
        if (!is_int($days) && !(is_string($days) && ctype_digit($days))) {
            throw new ValidationException($this->l->t('Share files for a whole number of days.'));
        }
        $days = (int)$days;
        if ($days < 1 || $days > WorkflowShareService::MAX_DAYS) {
            // TRANSLATORS: %s is the largest number of days a file may be shared for
            throw new ValidationException($this->l->t('Share files for 1 to %s days.', [WorkflowShareService::MAX_DAYS]));
        }
        return [
            'allowed' => !in_array($raw['allowed'] ?? true, [false, 0, '0', 'false'], true),
            'edit'    => in_array($raw['edit'] ?? false, [true, 1, '1', 'true'], true),
            'days'    => $days,
        ];
    }

    /**
     * The tasks of one step (v4.10.37). A step saved before tasks existed
     * carried its role and links itself; it is read as one task that has
     * them, named after nothing (the step's name stands for it). A step
     * always holds at least one task.
     *
     * @param array<string, mixed> $raw the step as sent
     * @return array<int, array{label: string, role: string, nonBlocking: bool, links: array<int, array{label: string, url: string, kind: string}>}>
     */
    private function tasks(string $kind, array $raw): array {
        $rawTasks = $raw['tasks'] ?? null;
        if ($rawTasks === null) {
            $rawTasks = [['label' => '', 'role' => $raw['role'] ?? '', 'links' => $raw['links'] ?? []]];
        }
        if (!is_array($rawTasks) || !array_is_list($rawTasks)) {
            throw new ValidationException($this->l->t('The tasks could not be read.'));
        }
        if (count($rawTasks) > self::MAX_TASKS) {
            throw new ValidationException($this->l->n(
                'A step has at most %n task.',
                'A step has at most %n tasks.',
                self::MAX_TASKS,
            ));
        }
        if ($rawTasks === []) {
            $rawTasks = [['label' => '']];
        }
        $desk = $kind === TeamServiceDefinition::KIND_DESK;
        $out  = [];
        foreach ($rawTasks as $task) {
            if (!is_array($task)) {
                throw new ValidationException($this->l->t('The tasks could not be read.'));
            }
            $out[] = [
                'label'       => $this->line($task['label'] ?? '', self::MAX_STEP_LABEL,
                    // TRANSLATORS: %s is a number of characters
                    $this->l->t('The name of a task is at most %s characters.', [self::MAX_STEP_LABEL])),
                // A role and "may be left open" only mean something on the
                // team's own tasks; the requester's are always waited for.
                'role'        => $desk
                    // TRANSLATORS: %s is a number of characters; a role is the organisation's name for who does a task, e.g. "Privacy officer"
                    ? $this->line($task['role'] ?? '', self::MAX_ROLE, $this->l->t('A role is at most %s characters.', [self::MAX_ROLE]))
                    : '',
                'nonBlocking' => $desk && in_array($task['nonBlocking'] ?? false, [true, 1, '1', 'true'], true),
                'links'       => $this->links($task['links'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * The links on one task (v4.10.36). A draft may hold a link that has no
     * name or no address yet — `publishProblems()` says so — but never one
     * whose address is not `https://`.
     *
     * @return array<int, array{label: string, url: string, kind: string}>
     */
    private function links(mixed $raw): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ValidationException($this->l->t('The links could not be read.'));
        }
        if (count($raw) > self::MAX_LINKS) {
            throw new ValidationException($this->l->n(
                'A step has at most %n link.',
                'A step has at most %n links.',
                self::MAX_LINKS,
            ));
        }
        $out = [];
        foreach ($raw as $link) {
            if (!is_array($link)) {
                throw new ValidationException($this->l->t('The links could not be read.'));
            }
            $kind = (string)($link['kind'] ?? self::LINK_KIND_LINK);
            if (!in_array($kind, self::LINK_KINDS, true)) {
                throw new ValidationException($this->l->t('A link opens a page or a form.'));
            }
            $out[] = [
                'label' => $this->line($link['label'] ?? '', self::MAX_LINK_LABEL,
                    // TRANSLATORS: %s is a number of characters
                    $this->l->t('The name of a link is at most %s characters.', [self::MAX_LINK_LABEL])),
                'url'   => $this->url($link['url'] ?? ''),
                'kind'  => $kind,
            ];
        }
        return $out;
    }

    /**
     * An address a link opens (v4.10.36): '' or an absolute `https://` URL
     * with a host — never `http:`, `javascript:`, `data:` or a relative
     * path (CLAUDE.md § Vue). The client checks the same again before it
     * renders one.
     */
    private function url(mixed $value): string {
        if (!is_string($value)) {
            throw new ValidationException($this->l->t('The service could not be read.'));
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) > self::MAX_URL) {
            // TRANSLATORS: %s is a number of characters
            throw new ValidationException($this->l->t('An address is at most %s characters.', [self::MAX_URL]));
        }
        $parts = parse_url($value);
        if (preg_match('/[\x00-\x20\x7F]/', $value)
            || !str_starts_with(strtolower($value), 'https://')
            || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || (string)($parts['host'] ?? '') === '') {
            throw new ValidationException($this->l->t('An address starts with https:// and has no spaces.'));
        }
        return $value;
    }

    /** A multi-line field: line breaks and tabs allowed, other control characters not. */
    private function text(mixed $value, int $max, string $tooLong): string {
        if (!is_string($value)) {
            throw new ValidationException($this->l->t('The service could not be read.'));
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new ValidationException($this->l->t('The description contains characters that are not allowed.'));
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationException($tooLong);
        }
        return $value;
    }

    /** @param array<string, mixed> $doc */
    private function encode(array $doc): string {
        return json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $meta */
    private function audit(string $teamId, string $event, string $uid, TeamService $row, array $meta): void {
        try {
            $this->auditService->log($teamId, $event, $uid, 'team_service', (string)$row->getId(), $meta);
        } catch (\Throwable $e) {
            // An audit line must never be the reason a service cannot be saved.
            $this->logger->warning('[TeamHub][ServiceBuilder] audit line failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
