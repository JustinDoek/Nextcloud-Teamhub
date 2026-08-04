<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\Category;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\OpenTarget;
use OCA\TeamHub\MyWork\Priority;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\GroupFolderService;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * My Work provider for the Nextcloud Approval app (v4.5.21).
 *
 * ## Read this before changing anything here
 *
 * TeamHub does not own the Approval app's schema and this provider was written
 * **without a running instance of it to verify against**. SKILLS.md forbids
 * guessing at another app's API, so nothing here assumes: every table, every
 * column and every enum coding is probed at runtime, and any mismatch turns
 * the provider off with a reason an administrator can read on
 * Admin settings → TeamHub → My Work rather than producing an empty,
 * healthy-looking work queue. If a real install disagrees with the shapes
 * below, `describeSchema()` is the single place to correct.
 *
 * Two codings are accepted for `entity_type` on the approver/requester tables
 * because the app could plausibly use either and picking one would be the
 * guess this comment exists to avoid:
 *  - strings `user` / `group` / `circle`
 *  - integers `0` / `1` / `2`, with `7` also read as circle (Nextcloud's own
 *    share-type constant for circles)
 *
 * ## What it shows
 *
 *  - files whose pending tag is set and where the user is an approver on the
 *    matching rule → **Action Required**;
 *  - files the user themselves submitted and someone else must act on
 *    → **Waiting for Others**;
 *  - pending requests that have gone stale → still Action Required or Waiting
 *    for Others, but flagged `expiring` / `expired` in metadata and lifted to
 *    a higher priority;
 *  - recent approvals and rejections → **Completed**.
 *
 * ## Expiry is TeamHub policy, not an Approval fact
 *
 * The Approval app has no expiry concept. "Expired or about to expire" is
 * derived here from how long a request has been pending, against two
 * administrator-configurable thresholds. That is why the thresholds live in
 * TeamHub's config and are labelled as ours in the admin UI — an invented
 * deadline presented as the source app's would be a lie the user cannot check.
 *
 * ## Writes go through the app, never around it
 *
 * `approve` and `reject` call the Approval app's own service in-process. If
 * that service is not callable on this install, the action is reported
 * `unsupported` and the user is sent to the file. TeamHub deliberately does
 * **not** fall back to swapping the pending/approved system tags itself: those
 * tags are the app's state machine, and moving them behind its back would skip
 * its activity entries, notifications and any configured workflow, leaving a
 * file that looks approved to us and unapproved to it.
 */
class ApprovalWorkProvider implements IWorkProvider {

    public const ID = 'approval';

    public const RESOURCE_TYPE = 'file';

    // Source statuses. Administrators remap these to categories, so they are
    // part of the stored contract — do not rename without migrating the map.
    public const STATUS_REQUESTED = 'approval_requested';
    public const STATUS_SUBMITTED = 'approval_submitted';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';

    // The Approval app's own activity states.
    private const STATE_APPROVED = 1;
    private const STATE_PENDING  = 2;
    private const STATE_REJECTED = 3;

    private ?string $unavailableReason = null;

    /** @var array<string,mixed>|null memoised schema probe */
    private ?array $schema = null;

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IRootFolder $rootFolder,
        private TeamAppResourceMapper $resourceMapper,
        private GroupFolderService $groupFolderService,
        private MyWorkConfigService $config,
        private ICommentsManager $commentsManager,
        private ContainerInterface $container,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------------
    // Identity + capabilities
    // ---------------------------------------------------------------------

    public function getId(): string {
        return self::ID;
    }

    public function getName(): string {
        return $this->l->t('File approval');
    }

    public function getIcon(): string {
        return 'approval';
    }

    public function getCapabilities(): array {
        return [
            'actions' => [
                ActionType::OPEN,
                ActionType::APPROVE,
                ActionType::REJECT,
                ActionType::COMMENT,
                // REQUEST_CHANGES is deliberately absent: the Nextcloud
                // Approval app has approve and reject and nothing between
                // them. Declaring it would put a button on screen that
                // cannot work.
            ],
            'resourceTypes' => [self::RESOURCE_TYPE],
            'statuses'      => [
                self::STATUS_REQUESTED,
                self::STATUS_SUBMITTED,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
            ],
            'categories' => [
                Category::ACTION_REQUIRED,
                Category::WAITING_FOR_OTHERS,
                Category::COMPLETED,
            ],
            'pagination'  => false,
            'incremental' => false,
        ];
    }

    public function isAvailable(): bool {
        if (!$this->appManager->isInstalled('approval')) {
            $this->unavailableReason = $this->l->t('The Approval app is not installed or not enabled.');
            return false;
        }

        $schema = $this->describeSchema();
        if (!$schema['ok']) {
            $this->unavailableReason = $schema['reason'];
            return false;
        }

        $this->unavailableReason = null;
        return true;
    }

    public function getUnavailableReason(): ?string {
        return $this->unavailableReason;
    }

    /**
     * What this provider actually found on this install, for the admin page.
     *
     * Not part of `IWorkProvider` — `ProviderRegistry` picks it up via
     * `method_exists` so a provider can offer it without every future
     * provider having to. It exists because this provider is the one written
     * against a schema we could not verify, and "it returns nothing" is not a
     * diagnosis an administrator can act on. Everything here is structural
     * (table and column names, row counts); no file names, no user ids, no
     * document content.
     *
     * @return array<int, array{label:string, value:string}>
     */
    public function getDiagnostics(): array {
        $schema = $this->describeSchema();

        $rows = [
            ['label' => $this->l->t('Rules table'),     'value' => $schema['ok'] ? 'approval_rules' : $this->l->t('not readable')],
            ['label' => $this->l->t('Approver table'),  'value' => (string)($schema['approverTable']  ?? $this->l->t('not found'))],
            ['label' => $this->l->t('Requester table'), 'value' => (string)($schema['requesterTable'] ?? $this->l->t('not found'))],
            ['label' => $this->l->t('Entity columns'),  'value' => $schema['entityTypeColumn'] !== null
                ? $schema['entityTypeColumn'] . ' / ' . $schema['entityIdColumn']
                : $this->l->t('not found')],
            ['label' => $this->l->t('Activity table'),  'value' => $schema['hasActivity']
                ? $this->l->t('readable')
                : $this->l->t('not readable — requester and timing are unknown, so Waiting for others falls back to rule membership')],
        ];

        try {
            $rules = $this->loadRules();
            $rows[] = ['label' => $this->l->t('Approval rules configured'), 'value' => (string)count($rules)];
        } catch (\Throwable) {
            $rows[] = ['label' => $this->l->t('Approval rules configured'), 'value' => $this->l->t('could not be read')];
        }

        // The service call is the other half of the integration and fails
        // independently of the schema, so report it separately.
        $class = '\OCA\Approval\Service\ApprovalService';
        if (!class_exists($class)) {
            $rows[] = ['label' => $this->l->t('Approve / reject'), 'value' => $this->l->t('ApprovalService class not found')];
            return $rows;
        }
        try {
            $service = $this->container->get($class);
            $shapes  = [];
            foreach (['approve', 'reject'] as $method) {
                if (!method_exists($service, $method)) {
                    $shapes[] = $method . '(): ' . $this->l->t('missing');
                    continue;
                }
                $params = [];
                foreach ((new \ReflectionMethod($service, $method))->getParameters() as $p) {
                    $type = $p->getType();
                    $params[] = ($type instanceof \ReflectionNamedType ? $type->getName() . ' ' : '') . '$' . $p->getName();
                }
                $shapes[] = $method . '(' . implode(', ', $params) . ')';
            }
            $rows[] = ['label' => $this->l->t('Approve / reject'), 'value' => implode('  ·  ', $shapes)];
        } catch (\Throwable $e) {
            $rows[] = ['label' => $this->l->t('Approve / reject'), 'value' => $this->l->t('service could not be built: {error}', ['error' => $e->getMessage()])];
        }

        return $rows;
    }

    public function getSupportedFilters(): array {
        return ['teamIds', 'completedSince'];
    }

    public function getConfigSchema(): array {
        return [
            [
                'key'     => 'approvalStaleDays',
                'type'    => 'int',
                'label'   => $this->l->t('Treat a pending approval as expired after (days)'),
                'hint'    => $this->l->t('The Approval app has no expiry of its own. This is a TeamHub display rule.'),
                'default' => 14,
                'min'     => 1,
                'max'     => 365,
            ],
            [
                'key'     => 'approvalWarnDays',
                'type'    => 'int',
                'label'   => $this->l->t('Warn this many days beforehand'),
                'default' => 3,
                'min'     => 1,
                'max'     => 364,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Schema probe
    // ---------------------------------------------------------------------

    /**
     * Establish what this install's Approval schema actually looks like.
     *
     * @return array{ok:bool, reason:?string, approverTable:?string, requesterTable:?string,
     *                entityTypeColumn:?string, entityIdColumn:?string, hasActivity:bool}
     */
    private function describeSchema(): array {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $result = [
            'ok'               => false,
            'reason'           => null,
            'approverTable'    => null,
            'requesterTable'   => null,
            'entityTypeColumn' => null,
            'entityIdColumn'   => null,
            'hasActivity'      => false,
        ];

        // The rules table is the one thing this provider cannot work without —
        // it is where the pending/approved/rejected tag ids come from.
        if (!$this->tableReadable('approval_rules', ['id', 'tag_pending', 'tag_approved', 'tag_rejected'])) {
            $result['reason'] = $this->l->t(
                'The Approval app is installed but its rules table is not in the expected shape. My Work cannot read approvals on this instance.',
            );
            return $this->schema = $result;
        }

        // Approver/requester link tables. Names and column names are probed
        // rather than assumed.
        foreach ([
            ['approval_rule_approvers', 'approverTable'],
            ['approval_rule_requesters', 'requesterTable'],
        ] as [$table, $slot]) {
            foreach ([['entity_type', 'entity_id'], ['type', 'entity_id'], ['entity_type', 'participant']] as [$typeCol, $idCol]) {
                if ($this->tableReadable($table, ['rule_id', $typeCol, $idCol])) {
                    $result[$slot] = $table;
                    // First match wins. The approver table is probed first and
                    // is the one that matters; letting the requester table
                    // overwrite these would break approver resolution on any
                    // install where the two tables differ.
                    if ($result['entityTypeColumn'] === null) {
                        $result['entityTypeColumn'] = $typeCol;
                        $result['entityIdColumn']   = $idCol;
                    }
                    break;
                }
            }
        }

        if ($result['approverTable'] === null) {
            $result['reason'] = $this->l->t(
                'The Approval app is installed but its approver table is not in the expected shape. My Work cannot tell who may approve what.',
            );
            return $this->schema = $result;
        }

        // The system-tag mapping is Nextcloud core, not the Approval app — if
        // this is missing the instance has bigger problems, but check anyway
        // so the failure names itself.
        if (!$this->tableReadable('systemtag_object_mapping', ['objecttype', 'objectid', 'systemtagid'])) {
            $result['reason'] = $this->l->t('Nextcloud system tags are unavailable, so approval state cannot be read.');
            return $this->schema = $result;
        }

        // Activity is optional. Without it, requester and timing are unknown:
        // the queue still works, it just cannot say who asked or how long ago.
        $result['hasActivity'] = $this->tableReadable('approval_activity', ['file_id', 'user_id', 'new_state', 'timestamp']);
        $result['ok']          = true;

        return $this->schema = $result;
    }

    /**
     * True when every named column can be selected from the table.
     *
     * A failing SELECT is unambiguous where `DbIntrospectionService` is not —
     * it has been observed returning `[]` for tables that exist (DESIGN.md
     * §2.68), and a false negative here would disable the provider on a
     * perfectly good install.
     *
     * Table and column names here are SQL *identifiers*, which QueryBuilder
     * cannot parameterise. Every caller passes a compile-time constant, and
     * the guard below makes sure it stays that way — a future edit that
     * routed request input here would fail loudly instead of building a
     * query out of it (SKILLS.md § Security standards, defensive programming).
     *
     * @param string[] $columns
     */
    private function tableReadable(string $table, array $columns): bool {
        foreach ([$table, ...$columns] as $identifier) {
            if (!preg_match('/^[a-z0-9_]+$/', $identifier)) {
                $this->logger->error('[TeamHub][MyWork][Approval] Refusing a non-literal SQL identifier', [
                    'identifier' => $identifier, 'app' => Application::APP_ID,
                ]);
                return false;
            }
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(...$columns)
                ->from($table)
                ->setMaxResults(1);
            $res = $qb->executeQuery();
            $res->closeCursor();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    public function fetchItems(WorkQuery $query): WorkItemPage {
        if (!$this->isAvailable() || $query->teamIds === []) {
            return WorkItemPage::empty();
        }

        $rules = $this->loadRules();
        if ($rules === []) {
            // No approval rules configured — nothing to show, and that is a
            // legitimate empty rather than a fault.
            return WorkItemPage::empty();
        }

        // Which rules can this user approve on, and which can they request on?
        $approverRuleIds  = $this->rulesForUser($query->userId, 'approver');
        $requesterRuleIds = $this->rulesForUser($query->userId, 'requester');

        // Every tag that means "pending", mapped back to its rule.
        $pendingTagToRule = [];
        $closingTagToRule = [];
        foreach ($rules as $ruleId => $rule) {
            if ($rule['tagPending'] > 0) {
                $pendingTagToRule[$rule['tagPending']] = $ruleId;
            }
            if ($rule['tagApproved'] > 0) {
                $closingTagToRule[$rule['tagApproved']] = [$ruleId, self::STATUS_APPROVED];
            }
            if ($rule['tagRejected'] > 0) {
                $closingTagToRule[$rule['tagRejected']] = [$ruleId, self::STATUS_REJECTED];
            }
        }

        $wantedTags = array_keys($pendingTagToRule);
        if ($query->includeCompleted) {
            $wantedTags = array_merge($wantedTags, array_keys($closingTagToRule));
        }
        if ($wantedTags === []) {
            return WorkItemPage::empty();
        }

        // file id => [tag ids]
        $taggedFiles = $this->filesWithTags($wantedTags, $query->perProviderCap);
        if ($taggedFiles === []) {
            return WorkItemPage::empty();
        }

        // Which of those files sit inside a team the user belongs to? This is
        // the authorisation boundary and it also throws away most candidates,
        // so it runs before any further per-file work.
        $fileTeams = $this->mapFilesToTeams($query, array_keys($taggedFiles));
        if ($fileTeams === []) {
            return WorkItemPage::empty();
        }

        $activity  = $this->loadActivity(array_keys($fileTeams));
        $staleDays = $this->config->getApprovalStaleDays();
        $warnDays  = $this->config->getApprovalWarnDays();

        $items     = [];
        $truncated = count($taggedFiles) >= $query->perProviderCap;

        foreach ($fileTeams as $fileId => $teamInfo) {
            $tags = $taggedFiles[$fileId] ?? [];
            $node = $teamInfo['node'];

            // ── Pending? ────────────────────────────────────────────────
            $pendingRuleId = null;
            foreach ($tags as $tagId) {
                if (isset($pendingTagToRule[$tagId])) {
                    $pendingRuleId = $pendingTagToRule[$tagId];
                    break;
                }
            }

            if ($pendingRuleId !== null) {
                $item = $this->buildPendingItem(
                    $query, $fileId, $node, $teamInfo, $rules[$pendingRuleId],
                    $pendingRuleId, in_array($pendingRuleId, $approverRuleIds, true),
                    in_array($pendingRuleId, $requesterRuleIds, true),
                    $activity[$fileId] ?? null, $staleDays, $warnDays,
                );
                if ($item !== null) {
                    $items[] = $item;
                }
                continue;
            }

            // ── Recently closed? ────────────────────────────────────────
            if (!$query->includeCompleted) {
                continue;
            }
            foreach ($tags as $tagId) {
                if (!isset($closingTagToRule[$tagId])) {
                    continue;
                }
                [$ruleId, $status] = $closingTagToRule[$tagId];
                $item = $this->buildClosedItem(
                    $query, $fileId, $node, $teamInfo, $rules[$ruleId],
                    $status, $activity[$fileId] ?? null,
                );
                if ($item !== null) {
                    $items[] = $item;
                }
                break;
            }
        }

        return new WorkItemPage($items, count($items), $truncated);
    }

    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        if (!$this->isAvailable() || $allowedTeamIds === []) {
            return null;
        }
        $fileId = (int)$providerItemId;
        if ($fileId <= 0) {
            return null;
        }

        // A single-item query re-runs the same pipeline against one file, so
        // the authorisation path and the list path cannot drift.
        $query = new WorkQuery(
            userId: $userId,
            teamIds: $allowedTeamIds,
            now: time(),
            perProviderCap: 1,
        );

        $rules = $this->loadRules();
        if ($rules === []) {
            return null;
        }

        $fileTeams = $this->mapFilesToTeams($query, [$fileId]);
        if (!isset($fileTeams[$fileId])) {
            return null;
        }

        // Cap is a row limit, not a file limit — one file can carry several of
        // these tags, so asking for 1 would return an arbitrary single tag and
        // the item would resolve to the wrong state (or to nothing).
        $tags     = $this->filesWithTags($this->allRuleTags($rules), 50, [$fileId])[$fileId] ?? [];
        $activity = $this->loadActivity([$fileId])[$fileId] ?? null;
        $approver = $this->rulesForUser($userId, 'approver');

        foreach ($rules as $ruleId => $rule) {
            if ($rule['tagPending'] > 0 && in_array($rule['tagPending'], $tags, true)) {
                return $this->buildPendingItem(
                    $query, $fileId, $fileTeams[$fileId]['node'], $fileTeams[$fileId], $rule, $ruleId,
                    in_array($ruleId, $approver, true),
                    in_array($ruleId, $this->rulesForUser($userId, 'requester'), true),
                    $activity,
                    $this->config->getApprovalStaleDays(),
                    $this->config->getApprovalWarnDays(),
                );
            }
            foreach ([[$rule['tagApproved'], self::STATUS_APPROVED], [$rule['tagRejected'], self::STATUS_REJECTED]] as [$tag, $status]) {
                if ($tag > 0 && in_array($tag, $tags, true)) {
                    return $this->buildClosedItem(
                        $query, $fileId, $fileTeams[$fileId]['node'], $fileTeams[$fileId],
                        $rule, $status, $activity,
                    );
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getAvailableActions(string $userId, WorkItem $item): array {
        $actions = [];

        if ($item->resourceUrl !== '') {
            $actions[] = ActionType::OPEN;
        }

        // Approve / reject only while pending AND only for an approver. Both
        // facts were resolved server-side when the item was built.
        if ($item->status === self::STATUS_REQUESTED) {
            if ($item->permissions['canApprove'] ?? false) {
                $actions[] = ActionType::APPROVE;
            }
            if ($item->permissions['canReject'] ?? false) {
                $actions[] = ActionType::REJECT;
            }
        }

        if ($item->permissions['canComment'] ?? false) {
            $actions[] = ActionType::COMMENT;
        }

        return $actions;
    }

    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!in_array($action, $this->getAvailableActions($userId, $item), true)) {
            return ActionResult::forbidden(
                $this->l->t('You do not have permission to perform this action on this file.'),
            );
        }

        $fileId = (int)$item->providerItemId;
        if ($fileId <= 0) {
            return ActionResult::gone($this->l->t('This file no longer exists.'));
        }

        return match ($action) {
            ActionType::APPROVE => $this->callApprovalService($fileId, $userId, 'approve', $this->resolveEtag($fileId, $userId)),
            ActionType::REJECT  => $this->callApprovalService($fileId, $userId, 'reject', $this->resolveEtag($fileId, $userId)),
            ActionType::COMMENT => $this->postComment($fileId, $userId, (string)($params['message'] ?? '')),
            default             => ActionResult::unsupported($this->l->t('File approval does not support this action.')),
        };
    }

    /**
     * Call `OCA\Approval\Service\ApprovalService::approve|reject`.
     *
     * No direct-tag fallback, by design — see the class docblock.
     *
     * **Argument order and arity are discovered by reflection, not assumed.**
     * v4.5.21 shipped a hard-coded `$service->$method($fileId, $userId)` and
     * Justin's smoke test got "could not complete this action" on both verbs,
     * which is exactly what a signature mismatch looks like from the outside.
     * Rather than guess a second time, the parameter list is now read off the
     * method and filled by name: an `int`-ish first parameter gets the file id,
     * a parameter named like a user gets the uid, and anything optional we
     * cannot identify is left to its default. If the method genuinely takes
     * only the file id — plausible, since the app can read the session user
     * itself — that now works instead of throwing on an extra argument.
     *
     * The real exception message is also surfaced to the caller. A generic
     * "could not complete" on an integration we cannot test locally is a dead
     * end for whoever is holding the smoke test.
     */
    private function callApprovalService(int $fileId, string $userId, string $method, string $etag): ActionResult {
        $class = '\OCA\Approval\Service\ApprovalService';
        if (!class_exists($class)) {
            return ActionResult::unsupported($this->l->t(
                'The Approval app is not available to act on this file. Open the file to approve or reject it there.',
            ));
        }

        try {
            $service = $this->container->get($class);
        } catch (\Throwable $e) {
            // The Approval app's service could not be built from TeamHub's
            // container — a distinct failure from the call itself, and one an
            // administrator can act on, so name it.
            $this->logger->error('[TeamHub][MyWork][Approval] Could not resolve ApprovalService', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t(
                'The Approval app could not be reached: {error}', ['error' => $e->getMessage()],
            ));
        }

        if (!method_exists($service, $method)) {
            return ActionResult::unsupported($this->l->t(
                'This version of the Approval app cannot be driven from My Work. Open the file to approve or reject it there.',
            ));
        }

        try {
            $args = $this->buildServiceArgs($service, $method, $fileId, $userId, $etag);
            $service->$method(...$args);

            return ActionResult::success(
                $method === 'approve'
                    ? $this->l->t('File approved.')
                    : $this->l->t('File rejected.'),
                null,
                true,
            );
        } catch (\Throwable $e) {
            $cls = get_class($e);
            if (str_contains($cls, 'NoPermission') || str_contains($cls, 'Forbidden')) {
                return ActionResult::forbidden(
                    $this->l->t('The Approval app refused this action: you are not an approver for this file.'),
                );
            }
            if (str_contains($cls, 'NotFound') || str_contains($cls, 'DoesNotExist')) {
                return ActionResult::gone($this->l->t('This file no longer exists.'));
            }

            $this->logger->error('[TeamHub][MyWork][Approval] Approval service call failed', [
                'method' => $method, 'fileId' => $fileId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t(
                'The Approval app could not complete this action: {error}',
                ['error' => $e->getMessage()],
            ));
        }
    }

    /**
     * The file's current ETag, which `ApprovalService::approve|reject` takes
     * as a required third argument on the shipping version of the app.
     *
     * The Approval app records *which revision* of a file was approved, so
     * that editing it afterwards can invalidate the approval. Passing the
     * live ETag is therefore the correct value and not a formality: it means
     * "I approved the file as it is right now".
     *
     * Empty string when the node cannot be resolved. That is preferable to
     * refusing the action outright — the app decides what an unknown revision
     * means, and it is better placed to than we are.
     */
    private function resolveEtag(int $fileId, string $userId): string {
        try {
            $node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
            return $node !== null ? (string)$node->getEtag() : '';
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][MyWork][Approval] Could not resolve file ETag', [
                'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return '';
        }
    }

    /**
     * Positional arguments for an Approval service method, matched by
     * reflection against its declared parameters.
     *
     * v4.5.22 shipped this without an `$etag` case and Justin's install threw
     * `expects a parameter "$etag" that My Work cannot supply` — the real
     * signature is `approve(int $fileId, string $userId, string $etag)`. The
     * throw did its job: it named the missing parameter precisely instead of
     * failing somewhere downstream with a mystery. Keep that behaviour for
     * the next unknown one.
     *
     * Stops at the first optional parameter it cannot identify, so a method
     * with extra optional arguments is called with its own defaults rather
     * than with something invented here.
     *
     * @return array<int, mixed>
     */
    private function buildServiceArgs(
        object $service,
        string $method,
        int $fileId,
        string $userId,
        string $etag,
    ): array {
        $params = (new \ReflectionMethod($service, $method))->getParameters();
        $args   = [];

        foreach ($params as $param) {
            $name = strtolower($param->getName());
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            if (str_contains($name, 'file') || ($typeName === 'int' && $args === [])) {
                $args[] = $fileId;
                continue;
            }
            if (str_contains($name, 'user') || str_contains($name, 'uid')) {
                $args[] = $userId;
                continue;
            }
            if (str_contains($name, 'etag')) {
                $args[] = $etag;
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                // Unknown but optional — stop here and let the app decide.
                break;
            }
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            // A required parameter we cannot fill: better to throw naming it
            // than to pass a plausible-looking wrong value into another app's
            // state machine.
            throw new \RuntimeException(sprintf(
                'ApprovalService::%s expects a parameter "$%s" that My Work cannot supply',
                $method,
                $param->getName(),
            ));
        }

        return $args;
    }

    /**
     * Post a comment on the file through Nextcloud's own comments API — a
     * stable OCP interface, so no probing needed and no app-specific coupling.
     */
    private function postComment(int $fileId, string $userId, string $message): ActionResult {
        $message = trim($message);
        if ($message === '') {
            return ActionResult::failure($this->l->t('Enter a comment before sending.'), 'failed');
        }
        // Nextcloud rejects comments over 1000 characters at the storage layer;
        // refusing here gives a message the user can act on.
        if (mb_strlen($message) > 1000) {
            return ActionResult::failure($this->l->t('Comments are limited to 1000 characters.'), 'failed');
        }

        try {
            $comment = $this->commentsManager->create('users', $userId, 'files', (string)$fileId);
            $comment->setMessage($message);
            $comment->setVerb('comment');
            $this->commentsManager->save($comment);

            return ActionResult::success($this->l->t('Comment posted.'));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Approval] Comment failed', [
                'fileId' => $fileId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ActionResult::failure($this->l->t('The comment could not be posted.'));
        }
    }

    // ---------------------------------------------------------------------
    // Source reads
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array{id:int,tagPending:int,tagApproved:int,tagRejected:int,description:string}>
     */
    private function loadRules(): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('id', 'tag_pending', 'tag_approved', 'tag_rejected')
                ->from('approval_rules')
                ->executeQuery();

            $out = [];
            while ($row = $res->fetch()) {
                $id = (int)$row['id'];
                $out[$id] = [
                    'id'          => $id,
                    'tagPending'  => (int)($row['tag_pending'] ?? 0),
                    'tagApproved' => (int)($row['tag_approved'] ?? 0),
                    'tagRejected' => (int)($row['tag_rejected'] ?? 0),
                    'description' => '',
                ];
            }
            $res->closeCursor();

            // `description` is selected separately because it is the column
            // most likely to differ between versions, and losing the label is
            // cosmetic where losing the tag ids would be fatal.
            try {
                $dqb = $this->db->getQueryBuilder();
                $dres = $dqb->select('id', 'description')->from('approval_rules')->executeQuery();
                while ($row = $dres->fetch()) {
                    $id = (int)$row['id'];
                    if (isset($out[$id])) {
                        $out[$id]['description'] = (string)($row['description'] ?? '');
                    }
                }
                $dres->closeCursor();
            } catch (\Throwable) {
                // No description column on this version — labels fall back to
                // a generic "Approval" in the UI.
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][Approval] Rule load failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            throw $e;
        }
    }

    /** @param array<int, array<string,mixed>> $rules @return int[] */
    private function allRuleTags(array $rules): array {
        $tags = [];
        foreach ($rules as $rule) {
            foreach (['tagPending', 'tagApproved', 'tagRejected'] as $key) {
                if ((int)$rule[$key] > 0) {
                    $tags[] = (int)$rule[$key];
                }
            }
        }
        return array_values(array_unique($tags));
    }

    /**
     * Rule ids on which this user is an approver (or a requester).
     *
     * Resolves `user`, `group` and `circle` entity types, accepting either the
     * string or the integer coding — see the class docblock for why both.
     *
     * @param 'approver'|'requester' $role
     * @return int[]
     */
    private function rulesForUser(string $userId, string $role): array {
        $schema = $this->describeSchema();
        $table  = $role === 'approver' ? $schema['approverTable'] : $schema['requesterTable'];
        if ($table === null) {
            return [];
        }

        $typeCol = (string)$schema['entityTypeColumn'];
        $idCol   = (string)$schema['entityIdColumn'];

        $user = $this->userManager->get($userId);
        $groupIds  = $user !== null ? $this->groupManager->getUserGroupIds($user) : [];
        $circleIds = $this->userCircleIds($userId);

        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('rule_id')
                ->addSelect($typeCol)
                ->addSelect($idCol)
                ->from($table)
                ->executeQuery();

            $out = [];
            while ($row = $res->fetch()) {
                $type = $this->normaliseEntityType($row[$typeCol] ?? null);
                $ref  = (string)($row[$idCol] ?? '');
                if ($ref === '') {
                    continue;
                }

                $match = match ($type) {
                    'user'   => $ref === $userId,
                    'group'  => in_array($ref, $groupIds, true),
                    'circle' => in_array($ref, $circleIds, true),
                    default  => false,
                };

                if ($match) {
                    $out[(int)$row['rule_id']] = true;
                }
            }
            $res->closeCursor();

            return array_map('intval', array_keys($out));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Approval] Could not resolve rules for user', [
                'role' => $role, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Accepts both the string coding (`user`) and the integer coding
     * (0/1/2, with 7 also read as circle — Nextcloud's own share-type value).
     */
    private function normaliseEntityType(mixed $raw): string {
        if (is_string($raw) && !is_numeric($raw)) {
            $s = strtolower(trim($raw));
            return in_array($s, ['user', 'group', 'circle'], true) ? $s : '';
        }
        return match ((int)$raw) {
            0       => 'user',
            1       => 'group',
            2, 7    => 'circle',
            default => '',
        };
    }

    /**
     * Circle unique ids the user belongs to, via the Circles membership cache.
     * Same table `TeamService::getUserTeams` uses, so "member of a circle"
     * means the same thing across TeamHub.
     *
     * @return string[]
     */
    private function userCircleIds(string $userId): array {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->selectDistinct('ms.circle_id')
                ->from('circles_membership', 'ms')
                ->innerJoin('ms', 'circles_member', 'm', $qb->expr()->andX(
                    $qb->expr()->eq('m.single_id', 'ms.single_id'),
                    $qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)),
                    $qb->expr()->eq('m.user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
                ))
                ->executeQuery();

            $ids = [];
            while ($row = $res->fetch()) {
                $ids[] = (string)$row['circle_id'];
            }
            $res->closeCursor();
            return array_values(array_unique($ids));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * file id => tag ids, for the given system tags.
     *
     * @param int[] $tagIds
     * @param int[] $restrictToFileIds narrow to these files (single-item path)
     * @return array<int, int[]>
     */
    private function filesWithTags(array $tagIds, int $cap, array $restrictToFileIds = []): array {
        if ($tagIds === []) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('objectid', 'systemtagid')
                ->from('systemtag_object_mapping')
                ->where($qb->expr()->eq('objecttype', $qb->createNamedParameter('files')))
                ->andWhere($qb->expr()->in('systemtagid',
                    $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->setMaxResults(max(1, $cap));

            if ($restrictToFileIds !== []) {
                // objectid is a string column in core's schema; bind as strings
                // so the comparison is portable across MySQL and Postgres.
                $qb->andWhere($qb->expr()->in('objectid', $qb->createNamedParameter(
                    array_map('strval', $restrictToFileIds), IQueryBuilder::PARAM_STR_ARRAY)));
            }

            $res = $qb->executeQuery();
            $out = [];
            while ($row = $res->fetch()) {
                $fileId = (int)$row['objectid'];
                if ($fileId <= 0) {
                    continue;
                }
                $out[$fileId][] = (int)$row['systemtagid'];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWork][Approval] Tag lookup failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            throw $e;
        }
    }

    /**
     * Decide which team each file belongs to, and drop everything else.
     *
     * A file belongs to a team when it sits inside one of that team's
     * registered Files resources. Resolution happens **in the calling user's
     * own file tree**, so a file the user cannot see resolves to nothing and
     * is dropped — the source app's authorisation and TeamHub's team check
     * both have to pass, which is exactly the specification's rule.
     *
     * @param int[] $fileIds
     * @return array<int, array{teamId:string, others:string[], node:Node}>
     */
    private function mapFilesToTeams(WorkQuery $query, array $fileIds): array {
        if ($fileIds === []) {
            return [];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($query->userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][MyWork][Approval] No user folder', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }

        // teamId => list of folder paths, resolved once.
        $teamFolders = [];
        foreach ($query->teamIds as $teamId) {
            foreach ($this->teamFolderPaths($teamId, $query->userId, $userFolder) as $path) {
                $teamFolders[$teamId][] = $path;
            }
        }
        if ($teamFolders === []) {
            return [];
        }

        $out = [];
        foreach ($fileIds as $fileId) {
            try {
                $node = $userFolder->getFirstNodeById($fileId);
            } catch (\Throwable) {
                $node = null;
            }
            if ($node === null) {
                // Not reachable by this user: a deleted or revoked resource.
                // Silently dropping it is the correct handling of the
                // specification's "correctly handle deleted or revoked
                // resources" requirement.
                continue;
            }

            $path    = rtrim($node->getPath(), '/');
            $primary = null;
            $others  = [];

            foreach ($teamFolders as $teamId => $paths) {
                foreach ($paths as $folderPath) {
                    if ($path === $folderPath || str_starts_with($path . '/', $folderPath . '/')) {
                        if ($primary === null) {
                            $primary = (string)$teamId;
                        } elseif ($primary !== (string)$teamId && !in_array((string)$teamId, $others, true)) {
                            $others[] = (string)$teamId;
                        }
                        break;
                    }
                }
            }

            if ($primary === null) {
                continue;
            }

            $out[$fileId] = ['teamId' => $primary, 'others' => $others, 'node' => $node];
        }

        return $out;
    }

    /**
     * Absolute paths of a team's registered Files resources, in the calling
     * user's own view of the file tree.
     *
     * @return string[]
     */
    private function teamFolderPaths(string $teamId, string $userId, Folder $userFolder): array {
        $paths = [];

        try {
            $rows = $this->resourceMapper->findActiveByTeamAndApp($teamId, 'files');
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $resourceId = (string)$row->getResourceId();

            // Group folder: 'gf:{folderId}'. Resolve the mount point, then the
            // node — group folders are mounted at the root of every member's
            // tree under that name.
            if (str_starts_with($resourceId, 'gf:')) {
                $meta = $this->groupFolderService->resolveGroupFolderResourceId($resourceId);
                if ($meta === null) {
                    continue;
                }
                try {
                    if ($userFolder->nodeExists($meta['mount_point'])) {
                        $paths[] = rtrim($userFolder->get($meta['mount_point'])->getPath(), '/');
                    }
                } catch (\Throwable) {
                    // Not mounted for this user — they are not in the folder.
                }
                continue;
            }

            $fileId = (int)$resourceId;
            if ($fileId <= 0) {
                continue;
            }
            try {
                $node = $userFolder->getFirstNodeById($fileId);
                if ($node !== null) {
                    $paths[] = rtrim($node->getPath(), '/');
                }
            } catch (\Throwable) {
                // Folder gone or not shared with this user.
            }
        }

        return $paths;
    }

    /**
     * Latest activity row per file: who moved it into which state, and when.
     *
     * @param int[] $fileIds
     * @return array<int, array{state:int, userId:string, timestamp:int, requester:?string, requestedAt:?int}>
     */
    private function loadActivity(array $fileIds): array {
        if ($fileIds === [] || !$this->describeSchema()['hasActivity']) {
            return [];
        }

        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('file_id', 'user_id', 'new_state', 'timestamp')
                ->from('approval_activity')
                ->where($qb->expr()->in('file_id',
                    $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('timestamp', 'ASC')
                ->executeQuery();

            $out = [];
            while ($row = $res->fetch()) {
                $fileId = (int)$row['file_id'];
                $state  = (int)$row['new_state'];
                $ts     = (int)$row['timestamp'];
                $uid    = (string)$row['user_id'];

                // Ascending order means the last row wins for "current", while
                // the first PENDING row is the request that started this cycle.
                $prev = $out[$fileId] ?? ['requester' => null, 'requestedAt' => null];
                if ($state === self::STATE_PENDING) {
                    $prev['requester']   = $uid;
                    $prev['requestedAt'] = $ts;
                }
                $out[$fileId] = [
                    'state'       => $state,
                    'userId'      => $uid,
                    'timestamp'   => $ts,
                    'requester'   => $prev['requester'],
                    'requestedAt' => $prev['requestedAt'],
                ];
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable $e) {
            // Activity is enrichment, not correctness — a failure here costs
            // the requester name and the age, not the item.
            $this->logger->debug('[TeamHub][MyWork][Approval] Activity read failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // Item construction
    // ---------------------------------------------------------------------

    /**
     * @param array{teamId:string, others:string[], node:Node} $teamInfo
     * @param array{id:int,tagPending:int,tagApproved:int,tagRejected:int,description:string} $rule
     * @param array{state:int,userId:string,timestamp:int,requester:?string,requestedAt:?int}|null $activity
     */
    private function buildPendingItem(
        WorkQuery $query,
        int $fileId,
        Node $node,
        array $teamInfo,
        array $rule,
        int $ruleId,
        bool $isApprover,
        bool $isRuleRequester,
        ?array $activity,
        int $staleDays,
        int $warnDays,
    ): ?WorkItem {
        $requester   = $activity['requester']   ?? null;
        $requestedAt = $activity['requestedAt'] ?? null;

        // Who counts as "the person waiting on this".
        //
        // The activity log is the precise answer — it records who actually
        // submitted this request. But it is optional (`hasActivity`), and on
        // Justin's install nothing appeared under Waiting for others at all
        // (v4.5.21 smoke test), which is exactly what an unreadable or
        // differently-shaped activity table produces: no requester is ever
        // resolved, so no non-approver ever passes the gate below.
        //
        // So: prefer the activity log, and fall back to "this user is a
        // designated requester on the matching rule" when it has told us
        // nothing. The fallback is broader — any requester on the rule sees
        // any pending file under it — but it is still bounded by team
        // membership and by the user's own file access, and a slightly wide
        // Waiting-for-others is far better than a permanently empty one.
        $isRequester = $requester !== null
            ? $requester === $query->userId
            : $isRuleRequester;

        // Neither an approver nor the requester: this pending file is somebody
        // else's business and must not appear in this user's queue, even
        // though they can see the file.
        if (!$isApprover && !$isRequester) {
            return null;
        }

        // Expiry — TeamHub's own derivation, see the class docblock.
        $ageDays  = $requestedAt !== null ? (int)floor(($query->now - $requestedAt) / 86400) : null;
        $expired  = $ageDays !== null && $ageDays >= $staleDays;
        $expiring = !$expired && $ageDays !== null && $ageDays >= ($staleDays - $warnDays);

        if ($isApprover) {
            $category = Category::ACTION_REQUIRED;
            $priority = $expired ? Priority::URGENT : ($expiring ? Priority::HIGH : Priority::NORMAL);
            $reason   = $this->l->t('You have been designated as an approver');
            $waiting  = null;
        } else {
            $category = Category::WAITING_FOR_OTHERS;
            $priority = $expired ? Priority::HIGH : Priority::NORMAL;
            $reason   = $this->l->t('You requested approval and are waiting for a response');
            $waiting  = $this->approverParty($ruleId);
        }

        if ($expired) {
            // IL10N::n, not ::t — the count drives plural selection and `%n`
            // is the placeholder NC substitutes it into.
            $reason = $isApprover
                ? $this->l->n(
                    'You are an approver and this request has been waiting %n day',
                    'You are an approver and this request has been waiting %n days',
                    (int)$ageDays,
                )
                : $this->l->n(
                    'Your approval request has been waiting %n day',
                    'Your approval request has been waiting %n days',
                    (int)$ageDays,
                );
        }

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => (string)$fileId,
            'teamId'         => $teamInfo['teamId'],
            'teamName'       => $query->teamName($teamInfo['teamId']),
            'category'       => $category,
            // The title is the action, the subtitle is the document — the
            // layout the specification's own example uses.
            'title'          => $isApprover
                ? $this->l->t('Review %s', [$node->getName()])
                : $this->l->t('Awaiting approval of %s', [$node->getName()]),
            'subtitle'       => $node->getName(),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$fileId,
            'resourceUrl'    => '/f/' . $fileId,
            // v4.5.24 — the row opens inside the team's own Files tab.
            'openTarget'     => OpenTarget::file($fileId),
            'priority'       => $priority,
            'status'         => $isApprover ? self::STATUS_REQUESTED : self::STATUS_SUBMITTED,
            'reason'         => $reason,
            'createdAt'      => $requestedAt,
            'updatedAt'      => $activity['timestamp'] ?? null,
            // An approval request has no due date of its own. The derived
            // expiry moment is exposed as `dueAt` so it sorts and groups with
            // real deadlines, and metadata records that it is derived so the
            // UI can label it honestly.
            'dueAt'          => $requestedAt !== null ? $requestedAt + ($staleDays * 86400) : null,
            'completedAt'    => null,
            'assignee'       => $isApprover
                ? ['uid' => $query->userId, 'displayName' => $this->displayName($query->userId)]
                : null,
            'waitingFor'     => $waiting,
            'availableActions' => [],
            'metadata'       => [
                'ruleId'          => $ruleId,
                'ruleDescription' => $rule['description'],
                'workflowStatus'  => 'pending',
                'requester'       => $requester !== null
                    ? ['uid' => $requester, 'displayName' => $this->displayName($requester)]
                    : null,
                'requestedAt'     => $requestedAt,
                'ageDays'         => $ageDays,
                'expired'         => $expired,
                'expiring'        => $expiring,
                'dueAtIsDerived'  => true,
                'filePath'        => $this->relativePath($node, $query->userId),
                'additionalTeamIds' => $teamInfo['others'],
            ],
            'permissions'    => [
                'canOpen'    => true,
                'canApprove' => $isApprover,
                'canReject'  => $isApprover,
                'canComment' => true,
            ],
        ]);
    }

    /**
     * @param array{teamId:string, others:string[], node:Node} $teamInfo
     * @param array{id:int,tagPending:int,tagApproved:int,tagRejected:int,description:string} $rule
     * @param array{state:int,userId:string,timestamp:int,requester:?string,requestedAt:?int}|null $activity
     */
    private function buildClosedItem(
        WorkQuery $query,
        int $fileId,
        Node $node,
        array $teamInfo,
        array $rule,
        string $status,
        ?array $activity,
    ): ?WorkItem {
        $completedAt = $activity['timestamp'] ?? null;
        if ($completedAt === null || $completedAt < $query->completedSince()) {
            // Either too old for the Completed window, or we cannot date it —
            // an undatable "recently completed" item would sit in the section
            // forever.
            return null;
        }

        // Only show closures the user was actually part of.
        $requester = $activity['requester'] ?? null;
        $actor     = $activity['userId'] ?? null;
        if ($requester !== $query->userId && $actor !== $query->userId) {
            return null;
        }

        $approved = $status === self::STATUS_APPROVED;

        return WorkItem::make([
            'providerId'     => self::ID,
            'providerItemId' => (string)$fileId,
            'teamId'         => $teamInfo['teamId'],
            'teamName'       => $query->teamName($teamInfo['teamId']),
            'category'       => Category::COMPLETED,
            'title'          => $approved
                ? $this->l->t('%s was approved', [$node->getName()])
                : $this->l->t('%s was rejected', [$node->getName()]),
            'subtitle'       => $node->getName(),
            'resourceType'   => self::RESOURCE_TYPE,
            'resourceId'     => (string)$fileId,
            'resourceUrl'    => '/f/' . $fileId,
            // v4.5.24 — the row opens inside the team's own Files tab.
            'openTarget'     => OpenTarget::file($fileId),
            'priority'       => Priority::LOW,
            'status'         => $status,
            'reason'         => $actor === $query->userId
                ? ($approved ? $this->l->t('You approved this file') : $this->l->t('You rejected this file'))
                : $this->l->t('You requested this approval'),
            'createdAt'      => $activity['requestedAt'] ?? null,
            'updatedAt'      => $completedAt,
            'dueAt'          => null,
            'completedAt'    => $completedAt,
            'assignee'       => $actor !== null
                ? ['uid' => $actor, 'displayName' => $this->displayName($actor)]
                : null,
            'waitingFor'     => null,
            'availableActions' => [],
            'metadata'       => [
                'ruleId'          => $rule['id'],
                'ruleDescription' => $rule['description'],
                'workflowStatus'  => $approved ? 'approved' : 'rejected',
                'requester'       => $requester !== null
                    ? ['uid' => $requester, 'displayName' => $this->displayName($requester)]
                    : null,
                'filePath'        => $this->relativePath($node, $query->userId),
                'additionalTeamIds' => $teamInfo['others'],
            ],
            'permissions'    => [
                'canOpen'    => true,
                'canApprove' => false,
                'canReject'  => false,
                'canComment' => true,
            ],
        ]);
    }

    /**
     * Who is expected to act, for a Waiting-for-Others row.
     *
     * Reports the first approver entity on the rule. Naming every approver
     * would be more complete but the row has one line for it; the count
     * travels in metadata if a future UI wants it.
     *
     * @return array{type:string,id:string,displayName:string}|null
     */
    private function approverParty(int $ruleId): ?array {
        $schema = $this->describeSchema();
        if ($schema['approverTable'] === null) {
            return null;
        }

        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select($schema['entityTypeColumn'], $schema['entityIdColumn'])
                ->from($schema['approverTable'])
                ->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if ($row === false) {
                return null;
            }

            $type = $this->normaliseEntityType($row[$schema['entityTypeColumn']] ?? null);
            $ref  = (string)($row[$schema['entityIdColumn']] ?? '');
            if ($type === '' || $ref === '') {
                return null;
            }

            return [
                'type'        => $type,
                'id'          => $ref,
                'displayName' => $type === 'user' ? $this->displayName($ref) : $ref,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    /** @var array<string,string> */
    private array $nameCache = [];

    private function displayName(string $uid): string {
        if (!isset($this->nameCache[$uid])) {
            $this->nameCache[$uid] = $this->userManager->get($uid)?->getDisplayName() ?? $uid;
        }
        return $this->nameCache[$uid];
    }

    /**
     * Path relative to the user's home, for display. Absolute internal paths
     * (`/alice/files/…`) leak the storage layout and must not reach a response
     * (SKILLS.md § Security standards — no internal paths in API responses).
     */
    private function relativePath(Node $node, string $userId): string {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $relative   = $userFolder->getRelativePath($node->getPath());
            return $relative !== null ? ltrim($relative, '/') : $node->getName();
        } catch (\Throwable) {
            return $node->getName();
        }
    }
}
