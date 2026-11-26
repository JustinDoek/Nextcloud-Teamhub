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
use OCA\TeamHub\Service\Workflow\StepDeadlineService;
use OCA\TeamHub\Service\Workflow\WorkflowNoticeService;
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
 *     request      v4.20.54, the request form (`RequestForm`, § 14.3):
 *                  {askTeam, titleField, fields}. Not sent is the standard
 *                  form, which is what every document before it had
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
 *                  Any step may carry **notices** (v4.20.62, § 14.5):
 *                  `notify` [{on: reached|done|rejected, to: {type:
 *                  requester|task|user|group, id, name}, channels:
 *                  [bell|email], subject, text, requester, fields}]
 *                  A step may carry a **condition** (v4.20.66, § 14.6):
 *                  `when` {field, op, value}; see `StepCondition`.
 *                  And a **deadline** (v4.20.68): `dueDays`, working days.
 *     confirm      v4.20.54, who confirms the result: {mode: requester |
 *                  auto | none, days}
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
    /** v4.20.62 — the notices of one step (§ 14.5). */
    public const MAX_NOTICES       = 5;
    public const MAX_NOTICE_SUBJECT = 100;
    public const MAX_NOTICE_TEXT   = 1000;
    /** v4.20.58 — the words on an approval's two buttons. */
    public const MAX_VERB        = 40;

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
        // v4.20.54 — the request form.
        private RequestForm        $requestForm,
        // v4.20.58 — who may approve, and who may be named as an approver.
        private ApproverResolver      $approvers,
        private RequestPersonResolver $people,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The team's services in display order: drafts and unpublished ones
     * included for somebody who builds them; for any other member only what
     * is on the Services page, in its published words (v4.20.57 — before,
     * the tab hid the rest in the browser only).
     *
     * @return array<int, array<string, mixed>>
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function listForTeam(string $teamId, bool $mayBuild): array {
        $this->serviceTeams->requireLicence();
        $rows = $this->mapper->findByTeam($teamId);
        if ($mayBuild) {
            return array_map(fn (TeamService $row): array => $this->describe($row), $rows);
        }
        $listed = array_filter($rows, static fn (TeamService $row): bool => $row->isListed() && $row->getPubVersion() > 0);
        return array_values(array_map(fn (TeamService $row): array => $this->describePublished($row), $listed));
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
                // v4.20.54 — the request form and the confirmation.
                'fields'      => RequestForm::MAX_FIELDS,
                'verb'        => self::MAX_VERB,
                // v4.20.62 — notices, and whether they may be emailed here.
                'notices'       => self::MAX_NOTICES,
                'noticeSubject' => self::MAX_NOTICE_SUBJECT,
                'noticeText'    => self::MAX_NOTICE_TEXT,
                'noticeEmail'   => $this->serviceTeams->mayEmail(),
                // v4.20.68 — the longest deadline a step may have.
                'dueDays'       => StepDeadlineService::MAX_DAYS,
                'fieldLabel'  => RequestForm::MAX_LABEL,
                'fieldHelp'   => RequestForm::MAX_HELP,
                'options'     => RequestForm::MAX_OPTIONS,
                'confirmDays' => TeamServiceDefinition::MAX_CONFIRM_DAYS,
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
        $doc = $this->withNames($doc, []);
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
     * The default issue process, seeded on a team that has just become a
     * service team (v4.11.6, Justin 2026-09-30).
     *
     * *"A simple requester requests, a user in the service team claims it and
     * they use chat in the service request to communicate, then the claimer
     * closes it and the requester acknowledges the result."* That is the
     * *issue* of `docs/service-builder.md` § 1a — the structured process with
     * **one** desk step — so it needs no new machinery: the fixed `submit`
     * and `confirm` steps are the engine's, the conversation is on every
     * request already, and closing is completing the one step.
     *
     * **A draft, never published.** A team created from the Service template
     * is not yet a desk that has decided to answer the organisation, so this
     * puts the process in the team's *Services* tab with one *Publish* click
     * left. Publishing is what calls `activate()`, puts the card on the
     * Services page and makes the queue and statistics widgets appear — that
     * remains a team admin's act (Justin, 2026-09-30).
     *
     * **Once, and never a second copy**: a team that already has a service of
     * its own is left alone, so re-saving the type or deleting the draft and
     * setting the type again does not breed duplicates.
     *
     * **Absent, not explained**: with the licence or the administrator's
     * switch missing there is no seed and no error — the same rule the rest
     * of the module follows.
     *
     * The words are the **creating user's** language, not a per-recipient
     * lookup: they are not a message sent to somebody, they are content
     * written into the team's own draft, which the team then owns and edits.
     * A team's own service is never translated afterwards (`getStepLabel()`
     * answers null for it), so seeding it in the language of the person
     * setting the team up is the closest thing to right.
     *
     * @return array<string, mixed>|null the new draft, or null when nothing
     *                                   was seeded
     */
    public function seedDefault(string $teamId, string $uid): ?array {
        if ($teamId === '' || !$this->serviceTeams->isAvailable()) {
            return null;
        }
        if ($this->mapper->countByTeam($teamId) > 0) {
            return null;
        }

        $teamName = $this->serviceTeams->teamName($teamId);
        // TRANSLATORS: the name of a service team's default request, e.g. "Facilities support request". %s is the team's name.
        $title = $teamName === '' ? $this->l->t('Support request') : $this->l->t('%s support request', [$teamName]);
        if (mb_strlen($title) > self::MAX_TITLE) {
            $title = mb_substr($title, 0, self::MAX_TITLE);
        }

        return $this->create($teamId, [
            'title'       => $title,
            'description' => $this->l->t('Ask this team for help. Describe what you need and a team member will pick it up.'),
            // '' is the support category: `normalise()` resolves it against
            // the administrator's own list, which may have renamed it.
            'category'    => '',
            'icon'        => '',
            // No lead time: a desk promises one when it knows what it can keep.
            'leadDays'    => 0,
            // The standard form: the team the request is for is asked, and
            // may be left empty — an issue is as often personal as a team's.
            'request'     => null,
            'steps'       => [[
                'kind'  => TeamServiceDefinition::KIND_DESK,
                // TRANSLATORS: the single step of the default issue process — the team member who claimed the request does the work and completes this step
                'label' => $this->l->t('Handle the request'),
                'tasks' => [['label' => '', 'role' => '', 'nonBlocking' => false, 'links' => []]],
            ]],
            'files'       => [],
        ], $uid);
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
        $doc = $this->normalise($input);
        $doc = $this->withNames($doc, $row->draftDocument());
        $row->setDraft($this->encode($doc));
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
     * @return array{title: string, description: string, category: string, icon: string, leadDays: int, request: array<string, mixed>, confirm: array{mode: string, days: int}, files: array{allowed: bool, edit: bool, days: int}, steps: array<int, array{kind: string, label: string, tasks: array<int, array{label: string, role: string, nonBlocking: bool, links: array<int, array{label: string, url: string, kind: string}>}>}>}
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
        $request = $this->requestForm->normalise($input['request'] ?? null);
        $fields  = array_column($request['fields'], 'key');
        // v4.20.71 — every field of the service, the request form's and the
        // tasks', by key: one key names one field, wherever it is asked.
        $taken   = array_fill_keys($fields, true);
        $types   = array_column($request['fields'], 'type', 'key');
        $steps = [];
        foreach ($rawSteps as $raw) {
            if (!is_array($raw)) {
                throw new ValidationException($this->l->t('The steps could not be read.'));
            }
            $kind = (string)($raw['kind'] ?? '');
            if (!in_array($kind, TeamServiceDefinition::KINDS, true)) {
                throw new ValidationException($this->l->t('A step is for the team, the requester or an approver.'));
            }
            $label = $this->line($raw['label'] ?? '', self::MAX_STEP_LABEL,
                // TRANSLATORS: %s is a number of characters
                $this->l->t('The name of a step is at most %s characters.', [self::MAX_STEP_LABEL]));
            if ($kind === TeamServiceDefinition::KIND_APPROVAL) {
                // v4.20.58 — one question to one approver: no tasks to share out.
                $steps[] = ['kind' => $kind, 'label' => $label, 'tasks' => $this->tasks($kind, ['tasks' => [['label' => '']]]), 'approval' => $this->approval($raw['approval'] ?? [])] + $this->notify($raw['notify'] ?? [], $fields) + $this->when($raw['when'] ?? null, $types) + $this->dueDays($raw['dueDays'] ?? 0);
                continue;
            }
            $tasks = $this->withTaskFields($this->tasks($kind, $raw), $raw, $taken, $types);
            $steps[] = ['kind' => $kind, 'label' => $label, 'tasks' => $tasks] + $this->notify($raw['notify'] ?? [], $fields) + $this->when($raw['when'] ?? null, $types) + $this->dueDays($raw['dueDays'] ?? 0);
        }

        return [
            'title'       => $title,
            'description' => $description,
            'category'    => $category,
            'icon'        => $icon,
            'leadDays'    => $leadDays,
            'request'     => $request,
            'steps'       => $steps,
            'confirm'     => $this->confirm($input['confirm'] ?? []),
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
        if ($last !== null && ($last['kind'] ?? '') === TeamServiceDefinition::KIND_APPROVAL) {
            $problems[] = $this->l->t('The last step is for the team, not an approver.');
        }
        $problems = array_merge($problems, $this->approvalProblems($doc), $this->noticeProblems($doc), $this->conditionProblems($doc), $this->taskFieldProblems($doc));
        return array_merge($problems, $this->requestForm->publishProblems((array)($doc['request'] ?? [])));
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

    /**
     * A published service for a member who does not build: no draft, nothing
     * about what would change it, nobody's name.
     *
     * @return array<string, mixed>
     */
    private function describePublished(TeamService $row): array {
        return [
            'id'              => (int)$row->getId(),
            'teamId'          => $row->getTeamId(),
            'definitionKey'   => TeamServiceDefinition::keyFor((int)$row->getId()),
            'draft'           => null,
            'published'       => $row->publishedDocument(),
            'version'         => $row->getPubVersion(),
            'listed'          => true,
            'hasChanges'      => false,
            'publishProblems' => [],
            'publishedAt'     => $row->getPublishedAt(),
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
     * Who approves, in which words, and what a rejection does (v4.20.58,
     * `docs/service-builder.md` § 14.4). A draft may leave the approver open;
     * `approvalProblems()` says so before it is published.
     *
     * @return array{approver: array{source: string, id: string, name: string, fallback: array{id: string, name: string}}, approveLabel: string, rejectLabel: string, onReject: string}
     */
    private function approval(mixed $raw): array {
        if (!is_array($raw)) {
            throw new ValidationException($this->l->t('The approval could not be read.'));
        }
        $approver = $raw['approver'] ?? [];
        $source   = is_array($approver) ? ($approver['source'] ?? ApproverResolver::SOURCE_USER) : null;
        if (!is_string($source) || !in_array($source, ApproverResolver::SOURCES, true)) {
            throw new ValidationException($this->l->t('Pick who approves.'));
        }
        $onReject = $raw['onReject'] ?? TeamServiceDefinition::REJECT_ENDS;
        if (!is_string($onReject) || !in_array($onReject, TeamServiceDefinition::REJECT_OUTCOMES, true)) {
            throw new ValidationException($this->l->t('Pick what happens when the approver rejects.'));
        }
        $named    = $this->named($approver);
        $fallback = $this->named(is_array($approver['fallback'] ?? null) ? $approver['fallback'] : []);
        if ($source === ApproverResolver::SOURCE_MANAGER) {
            $named = ['id' => '', 'name' => ''];
        }
        // TRANSLATORS: %s is a number of characters
        $tooLong = $this->l->t('The text of a button is at most %s characters.', [self::MAX_VERB]);
        return [
            'approver'     => ['source' => $source, 'id' => $named['id'], 'name' => $named['name'], 'fallback' => $fallback],
            'approveLabel' => $this->line($raw['approveLabel'] ?? '', self::MAX_VERB, $tooLong),
            'rejectLabel'  => $this->line($raw['rejectLabel'] ?? '', self::MAX_VERB, $tooLong),
            'onReject'     => $onReject,
        ];
    }

    /**
     * The notices of one step (v4.20.62, `docs/service-builder.md` § 14.5). A
     * draft may leave one unfinished; `noticeProblems()` says so before it is
     * published. A step without notices has no key at all, so a document from
     * before them reads the same.
     *
     * @param list<string> $fields the keys of the request form
     * @return array{notify?: list<array<string, mixed>>}
     */
    private function notify(mixed $raw, array $fields): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ValidationException($this->l->t('The notices could not be read.'));
        }
        if (count($raw) > self::MAX_NOTICES) {
            throw new ValidationException($this->l->n(
                'A step has at most %n notice.',
                'A step has at most %n notices.',
                self::MAX_NOTICES,
            ));
        }
        $out = [];
        foreach ($raw as $notice) {
            $to   = is_array($notice) ? ($notice['to'] ?? null) : null;
            $on   = is_array($notice) ? ($notice['on'] ?? null) : null;
            $type = is_array($to) ? ($to['type'] ?? null) : null;
            if (!in_array($on, WorkflowNoticeService::MOMENTS, true)
                || !in_array($type, WorkflowNoticeService::RECIPIENTS, true)
                || !is_array($notice['channels'] ?? null) || !is_array($notice['fields'] ?? [])) {
                throw new ValidationException($this->l->t('The notices could not be read.'));
            }
            $named = in_array($type, [WorkflowNoticeService::TO_USER, WorkflowNoticeService::TO_GROUP], true)
                ? $this->named($to, $this->l->t('The notices could not be read.'))
                : ['id' => '', 'name' => ''];
            $out[] = [
                'on'        => $on,
                'to'        => ['type' => $type] + $named,
                'channels'  => array_values(array_intersect(WorkflowNoticeService::CHANNELS, $notice['channels'])),
                'subject'   => $this->line($notice['subject'] ?? '', self::MAX_NOTICE_SUBJECT,
                    // TRANSLATORS: %s is a number of characters
                    $this->l->t('The subject of a notice is at most %s characters.', [self::MAX_NOTICE_SUBJECT])),
                'text'      => $this->text($notice['text'] ?? '', self::MAX_NOTICE_TEXT,
                    // TRANSLATORS: %s is a number of characters
                    $this->l->t('The text of a notice is at most %s characters.', [self::MAX_NOTICE_TEXT])),
                'requester' => in_array($notice['requester'] ?? false, [true, 1, '1', 'true'], true),
                // A field that is not on the form any more is not sent.
                'fields'    => array_values(array_intersect($fields, array_filter($notice['fields'] ?? [], 'is_string'))),
            ];
        }
        return $out === [] ? [] : ['notify' => $out];
    }

    /**
     * A step's condition (v4.20.66, `docs/service-builder.md` § 14.6): a field
     * of the request form, an operator its type allows, and a value. A draft
     * may hold one unfinished; `conditionProblems()` says so.
     *
     * @param array<string, string> $types every field of the service so far, key → type
     * @return array{when?: array{field: string, op: string, value: string|bool}}
     */
    private function when(mixed $raw, array $types): array {
        if ($raw === null || $raw === []) {
            return [];
        }
        $op = is_array($raw) ? ($raw['op'] ?? null) : null;
        if (!in_array($op, StepCondition::OPS, true) || !is_string($raw['field'] ?? null)) {
            throw new ValidationException($this->l->t('A condition could not be read.'));
        }
        $type  = (string)($types[$raw['field']] ?? '');
        $value = $raw['value'] ?? '';
        if ($type === RequestForm::TYPE_YESNO) {
            $value = in_array($value, [true, 1, '1', 'true', 'yes'], true);
        } elseif ($op === StepCondition::OP_FILLED) {
            $value = '';
        } else {
            // TRANSLATORS: %s is a number of characters
            $value = $this->line(is_bool($value) ? '' : $value, StepCondition::MAX_VALUE, $this->l->t('The value of a condition is at most %s characters.', [StepCondition::MAX_VALUE]));
        }
        return ['when' => ['field' => $raw['field'], 'op' => $op, 'value' => $value]];
    }

    /**
     * The fields a task asks to fill in (v4.20.71, `docs/service-builder.md`
     * § 14.6): the request form's field types, checked by the same rules. On
     * a requester task they are filled in to complete it, on a team task by
     * whoever completes it. An approval asks none. A key already used
     * anywhere in the service is replaced, so a condition names one field.
     *
     * @param list<array<string, mixed>> $tasks what `tasks()` returned
     * @param array<string, mixed> $raw the step as sent
     * @param array<string, bool> $taken keys used so far
     * @param array<string, string> $types key → type so far
     * @return list<array<string, mixed>>
     */
    private function withTaskFields(array $tasks, array $raw, array &$taken, array &$types): array {
        $rawTasks = is_array($raw['tasks'] ?? null) ? array_values($raw['tasks']) : [];
        foreach ($tasks as $i => $task) {
            $rawFields = is_array($rawTasks[$i] ?? null) ? ($rawTasks[$i]['fields'] ?? []) : [];
            if ($rawFields === [] || $rawFields === null) {
                continue;
            }
            $fields = $this->requestForm->normalise(['askTeam' => false, 'fields' => $rawFields])['fields'];
            foreach ($fields as $f => $field) {
                while (isset($taken[$field['key']])) {
                    $field['key'] = 'f' . bin2hex(random_bytes(4));
                }
                $taken[$field['key']] = true;
                $types[$field['key']] = $field['type'];
                $fields[$f] = $field;
            }
            $tasks[$i]['fields'] = $fields;
        }
        return $tasks;
    }

    /**
     * What stands between the tasks' fields and publishing (v4.20.71).
     *
     * @param array<string, mixed> $doc
     * @return list<string>
     */
    private function taskFieldProblems(array $doc): array {
        $problems = [];
        foreach ((array)($doc['steps'] ?? []) as $step) {
            foreach ((array)($step['tasks'] ?? []) as $task) {
                foreach ((array)($task['fields'] ?? []) as $field) {
                    if (trim((string)($field['label'] ?? '')) === '') {
                        $problems[] = $this->l->t('Give every field of a task a name.');
                    }
                    if (in_array($field['type'] ?? '', [RequestForm::TYPE_CHOICE, RequestForm::TYPE_MULTI], true) && count((array)($field['options'] ?? [])) < 2) {
                        $problems[] = $this->l->t('Give every field with choices at least two options.');
                    }
                }
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Within how many working days a step is due (v4.20.68): none, or 1 to
     * `StepDeadlineService::MAX_DAYS`.
     *
     * @return array{dueDays?: int}
     */
    private function dueDays(mixed $raw): array {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return [];
        }
        if ((!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) || (int)$raw < 1 || (int)$raw > StepDeadlineService::MAX_DAYS) {
            // TRANSLATORS: %s is the largest number of working days a step may be given
            throw new ValidationException($this->l->t('Give a step 1 to %s working days, or no deadline.', [StepDeadlineService::MAX_DAYS]));
        }
        return ['dueDays' => (int)$raw];
    }

    /**
     * What stands between the conditions and publishing (v4.20.66).
     *
     * @param array<string, mixed> $doc
     * @return list<string>
     */
    private function conditionProblems(array $doc): array {
        // v4.20.71 — a step may use the request form and the fields of the
        // steps before it, never its own or a later one.
        $types = [];
        foreach ((array)($doc['request']['fields'] ?? []) as $field) {
            $types[(string)$field['key']] = (string)$field['type'];
        }
        $steps    = array_values((array)($doc['steps'] ?? []));
        $later    = [];
        foreach ($steps as $step) {
            foreach ((array)($step['tasks'] ?? []) as $task) {
                foreach ((array)($task['fields'] ?? []) as $field) {
                    $later[(string)$field['key']] = (string)$field['type'];
                }
            }
        }
        $problems = [];
        $always   = false;
        $desk     = false;
        foreach ($steps as $i => $step) {
            $when = $step['when'] ?? null;
            $desk = $desk || ($step['kind'] ?? '') === TeamServiceDefinition::KIND_DESK;
            $mine = [];
            foreach ((array)($step['tasks'] ?? []) as $task) {
                foreach ((array)($task['fields'] ?? []) as $field) {
                    $mine[(string)$field['key']] = (string)$field['type'];
                }
            }
            if (!is_array($when)) {
                $always = $always || ($step['kind'] ?? '') === TeamServiceDefinition::KIND_DESK;
                $types += $mine;
                continue;
            }
            $type = $types[(string)$when['field']] ?? null;
            if ($type === null && isset($later[(string)$when['field']])) {
                $problems[] = $this->l->t('A condition can only use a field of the request form or of a step before it.');
            } elseif ($type === null) {
                $problems[] = $this->l->t('A condition uses a field that is not on the request form any more.');
            } elseif (!in_array($when['op'], StepCondition::OPS_BY_TYPE[$type] ?? [], true)) {
                $problems[] = $this->l->t('A condition asks something its field cannot answer. Pick another comparison.');
            } elseif ($when['op'] !== StepCondition::OP_FILLED && $type !== RequestForm::TYPE_YESNO && trim((string)$when['value']) === '') {
                $problems[] = $this->l->t('Give every condition a value.');
            }
            if ($i === count($steps) - 1) {
                $problems[] = $this->l->t('The last step runs on every request. Remove its condition.');
            }
            $types += $mine;
        }
        // Without any team step the problem is already said, once.
        if ($desk && !$always) {
            $problems[] = $this->l->t('At least one team step runs on every request. Remove the condition from one.');
        }
        return array_values(array_unique($problems));
    }

    /**
     * What stands between the notices and publishing (v4.20.62).
     *
     * @param array<string, mixed> $doc
     * @return list<string>
     */
    private function noticeProblems(array $doc): array {
        $problems = [];
        foreach ((array)($doc['steps'] ?? []) as $step) {
            foreach ((array)($step['notify'] ?? []) as $notice) {
                $type = (string)($notice['to']['type'] ?? '');
                $id   = (string)($notice['to']['id'] ?? '');
                if (trim((string)($notice['subject'] ?? '')) === '') {
                    $problems[] = $this->l->t('Give every notice a subject.');
                }
                if (in_array($type, [WorkflowNoticeService::TO_USER, WorkflowNoticeService::TO_GROUP], true)) {
                    if ($id === '') {
                        $problems[] = $this->l->t('Say for every notice who gets it.');
                    } elseif ($type === WorkflowNoticeService::TO_USER ? !$this->approvers->personExists($id) : !$this->approvers->groupExists($id)) {
                        $problems[] = $this->l->t('A person or group that gets a notice does not exist any more. Pick somebody else.');
                    }
                }
                $channels = (array)($notice['channels'] ?? []);
                if ($channels === []) {
                    $problems[] = $this->l->t('Say for every notice how it is sent.');
                } elseif ($channels === [WorkflowNoticeService::CHANNEL_EMAIL] && !$this->serviceTeams->mayEmail()) {
                    $problems[] = $this->l->t('Services cannot send email on this server. Send the notice as a notification.');
                }
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{id: string, name: string}
     */
    private function named(array $raw, ?string $tooLong = null): array {
        $tooLong ??= $this->l->t('The approver could not be read.');
        return [
            'id'   => $this->line($raw['id'] ?? '', 64, $tooLong),
            'name' => $this->line($raw['name'] ?? '', 255, $tooLong),
        ];
    }

    /**
     * What stands between the approvals and publishing (v4.20.58).
     *
     * @param array<string, mixed> $doc
     * @return list<string>
     */
    private function approvalProblems(array $doc): array {
        $people = [];
        foreach ((array)($doc['request']['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === RequestForm::TYPE_PERSON) {
                $people[(string)$field['key']] = true;
            }
        }
        $problems = [];
        foreach ((array)($doc['steps'] ?? []) as $step) {
            if (($step['kind'] ?? '') !== TeamServiceDefinition::KIND_APPROVAL) {
                continue;
            }
            $approver = (array)($step['approval']['approver'] ?? []);
            $source   = (string)($approver['source'] ?? '');
            $id       = (string)($approver['id'] ?? '');
            $fallback = (string)($approver['fallback']['id'] ?? '');
            $missing  = $source !== ApproverResolver::SOURCE_MANAGER && $id === '';
            if ($missing || ($source === ApproverResolver::SOURCE_FIELD && !isset($people[$id]))) {
                $problems[] = $this->l->t('Say for every approval who approves.');
            } elseif (($source === ApproverResolver::SOURCE_USER && !$this->approvers->personExists($id))
                || ($source === ApproverResolver::SOURCE_GROUP && !$this->approvers->groupExists($id))
                || ($fallback !== '' && !$this->approvers->personExists($fallback))) {
                $problems[] = $this->l->t('An approver does not exist any more. Pick somebody else.');
            }
            if ($fallback === '' && in_array($source, ApproverResolver::NEEDS_FALLBACK, true)) {
                $problems[] = $this->l->t('Say who approves when there is no manager or nobody was picked.');
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Who the document names as an approver, or to get a notice, under the name Nextcloud knows
     * them by — never under what the browser sent: a picker shows a job title
     * or an email address beside the name, and the document is read by the
     * whole team (v4.20.59, found on the instance).
     *
     * A team admin names only somebody they could share a file with — unless
     * the draft named them already: another admin of the team may have put
     * them there, and saving must not undo that.
     *
     * @param array<string, mixed> $doc a normalised document
     * @param array<string, mixed> $before the draft as it was
     * @return array<string, mixed>
     */
    private function withNames(array $doc, array $before): array {
        $known = [];
        foreach ((array)($before['steps'] ?? []) as $step) {
            $approver = is_array($step) ? ($step['approval']['approver'] ?? null) : null;
            if (!is_array($approver)) {
                continue;
            }
            $known[($approver['source'] ?? '') . ':' . ($approver['id'] ?? '')] = (string)($approver['name'] ?? '');
            $known['user:' . ($approver['fallback']['id'] ?? '')] = (string)($approver['fallback']['name'] ?? '');
        }
        foreach ((array)($before['steps'] ?? []) as $step) {
            foreach (is_array($step) ? (array)($step['notify'] ?? []) : [] as $notice) {
                $to = is_array($notice) ? (array)($notice['to'] ?? []) : [];
                $known[($to['type'] ?? '') . ':' . ($to['id'] ?? '')] = (string)($to['name'] ?? '');
            }
        }
        $name = function (string $source, string $id, string $refusal) use ($known): string {
            $found = $source === ApproverResolver::SOURCE_GROUP ? $this->people->groupNameFor($id) : $this->people->nameFor($id);
            if ($found === null && !isset($known[$source . ':' . $id])) {
                throw new ValidationException($refusal);
            }
            return $found ?? $known[$source . ':' . $id];
        };
        $noApprover = $this->l->t('You cannot name this approver. Pick somebody else.');
        $noRecipient = $this->l->t('You cannot send a notice to this person or group. Pick somebody else.');
        foreach ($doc['steps'] as $i => $step) {
            // v4.20.62 — whoever gets a notice is named by the same rule.
            foreach ($step['notify'] ?? [] as $n => $notice) {
                if ($notice['to']['id'] !== '') {
                    $doc['steps'][$i]['notify'][$n]['to']['name'] = $name($notice['to']['type'], $notice['to']['id'], $noRecipient);
                }
            }
            if (!isset($step['approval'])) {
                continue;
            }
            $approver = $step['approval']['approver'];
            $named    = in_array($approver['source'], [ApproverResolver::SOURCE_USER, ApproverResolver::SOURCE_GROUP], true);
            $approver['name'] = $named && $approver['id'] !== '' ? $name($approver['source'], $approver['id'], $noApprover) : '';
            $fallback = $approver['fallback']['id'];
            $approver['fallback']['name'] = $fallback !== '' ? $name(ApproverResolver::SOURCE_USER, $fallback, $noApprover) : '';
            $doc['steps'][$i]['approval']['approver'] = $approver;
        }
        return $doc;
    }

    /**
     * Who confirms the result (v4.20.54, `docs/service-builder.md` § 14.3):
     * the requester unless said otherwise.
     *
     * @return array{mode: string, days: int}
     */
    private function confirm(mixed $raw): array {
        if (!is_array($raw)) {
            throw new ValidationException($this->l->t('The confirmation could not be read.'));
        }
        $mode = $raw['mode'] ?? TeamServiceDefinition::CONFIRM_REQUESTER;
        if (!is_string($mode) || !in_array($mode, TeamServiceDefinition::CONFIRM_MODES, true)) {
            throw new ValidationException($this->l->t('Pick how a request is confirmed.'));
        }
        $days = $raw['days'] ?? TeamServiceDefinition::CONFIRM_DAYS;
        if ($days === '' || $days === null) {
            $days = TeamServiceDefinition::CONFIRM_DAYS;
        }
        if ((!is_int($days) && !(is_string($days) && ctype_digit($days)))
            || (int)$days < 1 || (int)$days > TeamServiceDefinition::MAX_CONFIRM_DAYS) {
            // TRANSLATORS: %s is the largest number of days after which a request confirms itself
            throw new ValidationException($this->l->t('Confirm automatically after 1 to %s days.', [TeamServiceDefinition::MAX_CONFIRM_DAYS]));
        }
        return ['mode' => $mode, 'days' => (int)$days];
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
