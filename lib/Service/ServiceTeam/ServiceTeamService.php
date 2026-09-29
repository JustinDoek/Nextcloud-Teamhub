<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesMemberType;
use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Constants\ServiceIcons;
use OCA\TeamHub\Db\ServiceCatalogEntry;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCA\TeamHub\Db\TeamServiceMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCA\TeamHub\Service\Workflow\WorkflowShareService;
use OCA\TeamHub\Workflow\WorkflowCapability;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Service Teams — who they are, who may work their queue, and what they
 * offer.
 *
 * A **service team** is a team created from the *Service* template that
 * answers requests from the rest of the organisation.
 *
 * ## v4.10.23 — the desk's people are the team's people
 *
 * Phase 5 (v4.10.20) gave a service team a roster of its own: a Nextcloud
 * administrator declared a team a service team, named one account its
 * *service owner*, and typed a list of *service agents*. None of that is
 * here any more (DESIGN §2.146). The mapping is now:
 *
 *   - **service owner → the team admins** (Circles level ≥ 8). Plural, and
 *     resolved live. They configure the desk, and they may take over an
 *     item another agent has claimed. Nobody is "the" owner, so nothing has
 *     to be handed over when a person leaves.
 *   - **service agents → the team's members and moderators.** Being in the
 *     team *is* being on the desk. An organisation that already keeps its
 *     service desk in a Nextcloud group adds that group to the team, where
 *     Circles resolves it as membership like any other group member — so
 *     "members, moderators or groups" is one mechanism, not three.
 *
 * A roster beside the membership could disagree with it, and the copy that
 * would have been wrong is the one nobody maintains. Removing somebody from
 * the team now takes them off the queue, with no second list to remember.
 *
 * ## The claim
 *
 * What a desk offers is still a row per service in `teamhub_service_catalog`
 * — but a team does not pick services one by one. It claims
 * **Nextcloud Services**, the six built-in workflows, as one bundle, in the
 * creation wizard or later on Manage team → Services. At most one team on
 * the instance holds it; everywhere else the control is greyed out and says
 * who has it. A service team may hold nothing at all, which is the state
 * every Service-template team starts in when somebody else already holds
 * the bundle.
 *
 * ## v4.10.33 — the services a team built itself
 *
 * A service team may also offer services it built (`TeamServiceBuilder`,
 * `docs/service-builder.md`). **Publishing one makes the team a desk**
 * (`activate()`), the same as claiming the bundle does, and the team stays
 * one for as long as it has ever published a service: an unpublished
 * service keeps its running requests, and they need somebody to answer
 * them. So releasing the bundle leaves such a team active, with an empty
 * bundle catalogue, and `describeCatalogue()` lists its published services
 * beside the bundle's.
 *
 * ## Availability — the licence and the administrator's switch
 *
 * Two things have to hold, and `isAvailable()` is the one predicate that
 * answers both.
 *
 *  1. **A licence.** Service Teams are licensed
 *     (`WorkflowCapability::SERVICE_TEAMS`). There is no per-tier
 *     degradation here the way there is for the workflow view, because a
 *     queue somebody cannot work is not a smaller feature, it is a broken
 *     one.
 *  2. **The administrator's switch** (v4.10.46) —
 *     `service_teams_module_enabled`, **default off**. Justin, 2026-09-25:
 *     service teams change how an organisation asks each other for things,
 *     so a client adopts them when its people are ready rather than because
 *     it upgraded. Presence and Decisions default on because every instance
 *     can use them on day one; this one needs somebody to run a desk first.
 *
 * **Every entry point that reads or writes a service team goes through
 * `requireLicence()`** — a name older than the switch, which now guards
 * both halves. With either half missing: nothing lists a service team,
 * nobody is eligible, the catalogue is empty, the creation wizard offers no
 * Service card, no workflow the bundle carries can be started, and every
 * route answers 403. `remove()` is the one deliberate exception, so a
 * deleted team is tidied up either way.
 *
 * **Switching off deletes nothing.** The rows, the claim, the published
 * services and the requests all stay, and switching back on brings every
 * surface back. What it does do is strand requests that are open at that
 * moment — their desk's queue, its widgets and the agents' My Work rows
 * disappear until the switch returns — so an instance that has taken
 * requests drains them before turning it off. Workflows that were already
 * running keep running; that rule is the engine's and is not touched here.
 *
 * ## What this class is not
 *
 * It does not know what a workflow is. It answers "is this person an agent
 * of that service team" and "which service team offers this definition";
 * `WorkflowActorResolver` and `WorkflowEngine` do the rest. That is what
 * keeps the engine free of service-team knowledge and this class free of
 * the engine — the two meet at `WorkflowActor::serviceAgent()`.
 */
class ServiceTeamService {

    /** Circles level at which a member owns the service (team admin). */
    public const OWNER_LEVEL = 8;

    /**
     * appconfig key of the administrator's switch (v4.10.46).
     * '1' = on; absent = off. Written by `TeamService::saveAdminSettings()`.
     */
    public const CONFIG_ENABLED = 'service_teams_module_enabled';

    /** @var array<string, bool> "uid|teamId" → eligible, memoised per request */
    private array $eligibleMemo = [];

    public function __construct(
        private ServiceTeamMapper         $teams,
        private ServiceCatalogEntryMapper $catalog,
        // v4.10.33 — the services the team built itself.
        private TeamServiceMapper         $builtServices,
        private WorkflowLicenceTier       $tier,
        private IDBConnection             $db,
        private MemberService             $memberService,
        private IGroupManager             $groupManager,
        private AuditService              $auditService,
        private ITimeFactory              $timeFactory,
        private IL10N                     $l,
        private LoggerInterface           $logger,
        // v4.10.45 — the administrator's categories, and whether teams are
        // archived before deletion (which retires the archiving service).
        private ServiceCategoryService    $categories,
        private IConfig                   $config,
    ) {
    }

    /**
     * Whether the catalog offers a Nextcloud service right now (v4.10.45).
     *
     *   - A retired service (`ServiceCatalogue::RETIRED`) never.
     *   - *Request team archiving* not while Settings → TeamHub → Archive
     *     archives every team before it is deleted (Justin, 2026-09-25):
     *     deleting is then archiving, so there is nothing to ask a desk for.
     *     Read with `ArchiveService`'s own default ('0').
     *
     * Built services are always offered; their switch is `listed`.
     */
    public function isServiceOffered(string $serviceKey): bool {
        if (in_array($serviceKey, ServiceCatalogue::RETIRED, true)) {
            return false;
        }
        if ($serviceKey === ServiceCatalogue::TEAM_ARCHIVE) {
            return $this->config->getAppValue(Application::APP_ID, 'archiveBeforeDelete', '0') !== '1';
        }
        return true;
    }

    // ──────────────────────────────────────────────────────────────────────
    // The licence gate
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Every public entry point calls this first.
     *
     * The licence before the switch: an unlicensed instance never sees the
     * switch, because the admin panel hides it until a licence is present,
     * so naming the licence first names what an administrator fixes first.
     * Both halves answer 403.
     *
     * @throws \OCA\TeamHub\Exception\LicenseGateException   no licence
     * @throws \OCA\TeamHub\Exception\AccessDeniedException  switched off
     */
    public function requireLicence(): void {
        $this->tier->require(
            WorkflowCapability::SERVICE_TEAMS,
            'Service Teams require an active TeamHub licence.',
        );
        if (!$this->isEnabledByAdmin()) {
            throw new AccessDeniedException('Service teams are switched off on this instance.');
        }
    }

    /**
     * The administrator's switch, read as stored (v4.10.46). Says nothing
     * about the licence — a stored '1' on an unlicensed instance is inert,
     * which is what lets an instance that licenses later find the module
     * already on if that is what its administrator chose.
     */
    public function isEnabledByAdmin(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, '0') === '1';
    }

    /** Whether this instance has Service Teams at all, without throwing. */
    public function isAvailable(): bool {
        return $this->tier->can(WorkflowCapability::SERVICE_TEAMS)
            && $this->isEnabledByAdmin();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────

    /** The service team record for a team, or null when that team is not one. */
    public function get(string $teamId): ?ServiceTeam {
        if ($teamId === '' || !$this->isAvailable()) {
            return null;
        }
        return $this->teams->findByTeam($teamId);
    }

    /**
     * @return ServiceTeam[]
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function listAll(bool $activeOnly = false): array {
        $this->requireLicence();
        return $this->teams->findAll($activeOnly);
    }

    /**
     * Is this an active service team? Everything that serves a queue asks
     * this first — a team that holds no service is not a desk yet.
     */
    public function isActiveServiceTeam(string $teamId): bool {
        $team = $this->get($teamId);
        return $team !== null && $team->isActive();
    }

    /**
     * **The eligibility rule**, and the only one: a member of the team,
     * directly, through a group or through another team.
     *
     * Nextcloud administrators are deliberately *not* eligible by virtue of
     * being administrators: administering a server is not the same as being
     * on the desk, and an administrator who should work the queue is put in
     * the team.
     *
     * Fails closed: an inactive service team, an unlicensed instance or a
     * lookup error is "not eligible".
     */
    public function isEligibleAgent(string $uid, string $teamId): bool {
        if ($uid === '' || $teamId === '') {
            return false;
        }
        $key = $uid . '|' . $teamId;
        if (isset($this->eligibleMemo[$key])) {
            return $this->eligibleMemo[$key];
        }

        $eligible = false;
        try {
            $team = $this->get($teamId);
            if ($team !== null && $team->isActive()) {
                $eligible = $this->memberService->isEffectiveMember($teamId, $uid, $this->db);
            }
        } catch (\Throwable $e) {
            $this->warn('eligibility lookup failed', $e);
            $eligible = false;
        }

        $this->eligibleMemo[$key] = $eligible;
        return $eligible;
    }

    /**
     * Is this person answerable for the service — i.e. an admin of the
     * service team?
     *
     * Named for the role it fills rather than for the level it reads: this
     * is what "service owner" means since v4.10.23. It is the check behind
     * configuring the desk and behind taking over an item another agent has
     * claimed (`WorkflowEngine::mayActOnClaimed()`).
     *
     * Deliberately does **not** require the desk to be active: claiming
     * Nextcloud Services is what makes it active, and the person who claims
     * it has to pass this first.
     */
    public function isServiceOwner(string $uid, string $teamId): bool {
        if ($uid === '' || $teamId === '' || !$this->isAvailable()) {
            return false;
        }
        return $this->memberLevel($uid, $teamId) >= self::OWNER_LEVEL;
    }

    /**
     * Every eligible agent of a service team, for a notification or an
     * assignment picker.
     *
     * The team's direct members first, then the members of every Nextcloud
     * group that is itself a member of the team, deduplicated and order
     * preserved.
     *
     * **The groups are expanded here, unlike every other team-relative
     * actor in the WorkflowHub.** Those resolve direct members only, because
     * Circles does not enumerate indirect members cheaply and a missed
     * notification is the lesser wrong. A service desk is the case where it
     * is not: putting a department's group on the queue is the documented
     * way to staff one, and a desk whose staff are never told a request
     * arrived is not a desk. The cost is bounded — a team has a handful of
     * group members, not a handful per user.
     *
     * @return string[] uids
     */
    public function eligibleAgents(string $teamId): array {
        $team = $this->get($teamId);
        if ($team === null || !$team->isActive()) {
            return [];
        }
        $out = [];
        try {
            foreach ($this->directMembers($teamId) as $uid) {
                $out[$uid] = true;
            }
            foreach ($this->memberGroups($teamId) as $gid) {
                $group = $this->groupManager->get($gid);
                if ($group === null) {
                    continue;
                }
                foreach ($group->getUsers() as $user) {
                    $out[$user->getUID()] = true;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('agent list lookup failed', $e);
        }
        return array_keys($out);
    }

    /**
     * The active service teams this person may work the queue of.
     *
     * @return string[] team ids
     */
    public function serviceTeamsForAgent(string $uid): array {
        if ($uid === '' || !$this->isAvailable()) {
            return [];
        }
        $out = [];
        try {
            foreach ($this->teams->findAll(true) as $team) {
                if ($this->isEligibleAgent($uid, $team->getTeamId())) {
                    $out[] = $team->getTeamId();
                }
            }
        } catch (\Throwable $e) {
            $this->warn('service team list lookup failed', $e);
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // The catalogue
    // ──────────────────────────────────────────────────────────────────────

    /**
     * One service team's catalogue as rows a client can render.
     *
     * v4.10.25 — every row also carries the category it is filed under and
     * the lead time the card prints. Both are derived from the service key
     * rather than stored: they are code while the services are code
     * (`docs/service-catalogue.md` § 3), and a client that renders them
     * needs them translated in the viewer's language, which only the
     * server can do.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeCatalogue(string $teamId, bool $enabledOnly = true): array {
        if (!$this->isActiveServiceTeam($teamId)) {
            return [];
        }
        $out = [];
        foreach ($this->catalog->findByTeam($teamId, $enabledOnly) as $entry) {
            // v4.10.45 — a retired service, or team archiving while teams
            // are archived before deletion, is not on the catalog.
            if (!$this->isServiceOffered($entry->getServiceKey())) {
                continue;
            }
            $described = ServiceCatalogue::describe($this->l, $entry->getServiceKey());
            // v4.10.45 — the administrator's categories: the service's own,
            // or the first when an administrator removed it.
            $category  = $this->categories->describe($this->categories->resolve(ServiceCatalogue::categoryFor($entry->getServiceKey())));
            $out[] = [
                'serviceKey'    => $entry->getServiceKey(),
                'definitionKey' => $entry->getDefinitionKey(),
                'enabled'       => $entry->isEnabled(),
                'label'         => $described['label'],
                'description'   => $described['description'],
                'category'      => $category['key'],
                'categoryLabel' => $category['label'],
                'categoryIcon'  => $category['icon'],
                'leadTime'      => ServiceCatalogue::leadTime($this->l, $entry->getServiceKey()),
                // v4.10.44 — whether the form's team field may stay empty.
                'teamOptional'  => ServiceCatalogue::isTeamOptional($entry->getServiceKey()),
            ];
        }
        // v4.10.33 — the services the team built itself, published and
        // listed, after the bundle's. Their words are the team's own and are
        // not translated; the category and the lead time are the app's.
        // `enabledOnly` is the bundle's switch; a built service's is `listed`.
        foreach ($this->builtServices->findByTeam($teamId) as $row) {
            if (!$row->isListed() || $row->getPubVersion() < 1) {
                continue;
            }
            $doc      = $row->publishedDocument();
            // v4.10.37 — published as a link service (v4.10.36, withdrawn):
            // nothing to request until the team publishes it again.
            if (($doc['start'] ?? '') === 'link') {
                continue;
            }
            $key      = TeamServiceDefinition::keyFor((int)$row->getId());
            $category = $this->categories->describe($this->categories->resolve((string)($doc['category'] ?? '')));
            $icon     = (string)($doc['icon'] ?? '');
            $out[] = [
                'serviceKey'    => $key,
                'definitionKey' => $key,
                'enabled'       => true,
                'label'         => (string)($doc['title'] ?? ''),
                'description'   => (string)($doc['description'] ?? ''),
                'category'      => $category['key'],
                'categoryLabel' => $category['label'],
                'categoryIcon'  => $category['icon'],
                // v4.10.45 — the icon the team picked in the builder.
                'icon'          => ServiceIcons::isAllowed($icon) ? $icon : ServiceIcons::DEFAULT,
                'leadTime'      => $this->leadTimeInDays((int)($doc['leadDays'] ?? 0)),
                'builtService'  => true,
                'teamOptional'  => true,
                // v4.10.38 — what the request form's paperclip may do.
                'fileSharing'   => WorkflowShareService::settingsOf(['fileSharing' => $doc['files'] ?? []]),
            ];
        }
        return $out;
    }

    /**
     * The card's lead time for a service a team built (v4.10.33): *Usually
     * within {n} working days*, or nothing when the team set none.
     */
    public function leadTimeInDays(int $days): string {
        if ($days < 1) {
            return '';
        }
        // TRANSLATORS: indication of how long a service request usually takes to answer; %n is a number of working days the service team chose
        return $this->l->n('Usually within %n working day', 'Usually within %n working days', $days);
    }

    /**
     * Which active service team handles a request of this definition, or
     * null when none does.
     *
     * **One desk per definition.** When two offer the same service the
     * lowest team id wins and the collision is logged: a request must land
     * in exactly one queue, and silently splitting an organisation's
     * requests between two desks is worse than picking one and saying so.
     * The claim refuses the second team up front, so this is the
     * belt-and-braces half.
     */
    public function serviceTeamForDefinition(string $definitionKey): ?string {
        if ($definitionKey === '' || !$this->isAvailable()) {
            return null;
        }
        try {
            $candidates = [];
            foreach ($this->catalog->findEnabledForDefinition($definitionKey) as $entry) {
                if ($this->isActiveServiceTeam($entry->getTeamId())) {
                    $candidates[] = $entry->getTeamId();
                }
            }
            if ($candidates === []) {
                return null;
            }
            if (count($candidates) > 1) {
                $this->logger->warning('[TeamHub][ServiceTeam] more than one service team offers a definition', [
                    'definition' => $definitionKey, 'teams' => $candidates, 'app' => Application::APP_ID,
                ]);
            }
            sort($candidates);
            return $candidates[0];
        } catch (\Throwable $e) {
            $this->warn('definition owner lookup failed', $e);
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Nextcloud Services — the instance-wide claim
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The team that holds Nextcloud Services, or null when nobody does.
     *
     * "Holds the bundle" is "offers any of its six services": the claim
     * writes all six together and releasing removes all six, so any one of
     * them is a faithful answer, and asking this way stays true for a row
     * left behind by a phase-5 instance that offered only some.
     */
    public function holderOfNextcloudServices(): ?string {
        foreach (ServiceCatalogue::SERVICES as $serviceKey) {
            $holder = $this->serviceTeamForDefinition((string)ServiceCatalogue::definitionFor($serviceKey));
            if ($holder !== null) {
                return $holder;
            }
        }
        return null;
    }

    /**
     * Claim Nextcloud Services for a team — the whole bundle, at once.
     *
     * Called from the creation wizard (by the team's creator, who is its
     * owner) and from Manage team → Services (by a team admin). Both
     * callers check the role; this checks the licence and the claim.
     *
     * Idempotent for the team that already holds it: a double submission or
     * a stale tab re-affirms the claim rather than failing.
     *
     * @throws ValidationException when another team holds it
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function claimNextcloudServices(string $teamId, string $byUid): ServiceTeam {
        $this->requireLicence();

        if ($teamId === '') {
            throw new ValidationException($this->l->t('Pick the team that will answer requests.'));
        }

        $holder = $this->holderOfNextcloudServices();
        if ($holder !== null && $holder !== $teamId) {
            throw new ValidationException($this->l->t('Another team already answers the Nextcloud services.'));
        }

        $now  = $this->timeFactory->getTime();
        $team = $this->teams->findByTeam($teamId);
        if ($team === null) {
            $team = new ServiceTeam();
            $team->setTeamId($teamId);
            $team->setCreatedAt($now);
            $team->setCreatedBy($byUid);
        }
        $team->setActive(1);
        $team->setUpdatedAt($now);
        $team = $team->getId() === null ? $this->teams->insert($team) : $this->teams->update($team);

        // The catalogue is replaced wholesale rather than diffed: the claim
        // is a state, and all six rows are what it means.
        $this->catalog->deleteByTeam($teamId);
        $order = 0;
        foreach (ServiceCatalogue::SERVICES as $serviceKey) {
            $order++;
            $entry = new ServiceCatalogEntry();
            $entry->setTeamId($teamId);
            $entry->setServiceKey($serviceKey);
            $entry->setDefinitionKey((string)ServiceCatalogue::definitionFor($serviceKey));
            $entry->setEnabled(1);
            $entry->setSortOrder($order);
            $entry->setUpdatedAt($now);
            $this->catalog->insert($entry);
        }

        $this->eligibleMemo = [];
        $this->audit($teamId, 'service_team.claimed', $byUid, ['services' => ServiceCatalogue::SERVICES]);

        return $team;
    }

    /**
     * Give Nextcloud Services back — the team stops answering requests.
     *
     * The team itself is untouched: it stays a Service-template team, keeps
     * its source group and can claim again. What goes is the catalogue and
     * the `active` flag, which together are what made it a desk.
     *
     * **Open requests keep their `service_agent` step actor**, and that
     * actor resolves to nobody once the desk is inactive. That is why both
     * callers — the team's own admins and a Nextcloud administrator from
     * the setup checklist — are told the size of the queue before they are
     * offered this.
     *
     * @throws NotFoundException
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function releaseNextcloudServices(string $teamId, string $byUid): void {
        $this->requireLicence();
        $team = $this->teams->findByTeam($teamId);
        if ($team === null) {
            throw new NotFoundException($this->l->t('That team does not answer requests.'));
        }
        $this->catalog->deleteByTeam($teamId);
        // v4.10.33 — a team that has published a service of its own stays a
        // desk: those services (and their running requests) are still its.
        $team->setActive($this->builtServices->hasEverPublished($teamId) ? 1 : 0);
        $team->setUpdatedAt($this->timeFactory->getTime());
        $this->teams->update($team);
        $this->eligibleMemo = [];
        $this->audit($teamId, 'service_team.released', $byUid, []);
    }

    /**
     * Make a team a desk without the bundle (v4.10.33): what publishing a
     * service the team built does. The bundle catalogue is not touched, so a
     * team that also holds the Nextcloud services keeps them.
     *
     * The caller has checked the role; this checks the licence.
     *
     * @throws \OCA\TeamHub\Exception\LicenseGateException
     */
    public function activate(string $teamId, string $byUid): ServiceTeam {
        $this->requireLicence();
        $now  = $this->timeFactory->getTime();
        $team = $this->teams->findByTeam($teamId);
        if ($team === null) {
            $team = new ServiceTeam();
            $team->setTeamId($teamId);
            $team->setCreatedAt($now);
            $team->setCreatedBy($byUid);
        } elseif ($team->isActive()) {
            return $team;
        }
        $team->setActive(1);
        $team->setUpdatedAt($now);
        $team = $team->getId() === null ? $this->teams->insert($team) : $this->teams->update($team);
        $this->eligibleMemo = [];
        $this->audit($teamId, 'service_team.activated', $byUid, []);
        return $team;
    }

    /**
     * Remove a service team's configuration entirely — the row as well.
     *
     * Used when the team itself is going away. `releaseNextcloudServices()`
     * is the reversible half and is what the two release buttons call.
     *
     * **Deliberately not licensed.** Every other entry point on this class
     * goes through `requireLicence()`; this one must not, because it is
     * housekeeping for a team that no longer exists. A licence that lapsed
     * would otherwise leave the rows behind for ever — and since the
     * Nextcloud services are one instance-wide claim, a row left behind by
     * a deleted team goes on holding them for everybody, greying the
     * checkbox out with an empty team name. That is exactly what was found
     * on the test instance on 2026-09-23.
     */
    public function remove(string $teamId, string $byUid): void {
        if ($teamId === '') {
            return;
        }
        $this->catalog->deleteByTeam($teamId);
        $this->teams->deleteByTeam($teamId);
        // v4.10.33 — its own services come off the Services page at once.
        // The rows stay: requests made on them keep their title and steps.
        try {
            $this->builtServices->unlistByTeam($teamId, $this->timeFactory->getTime());
        } catch (\Throwable $e) {
            $this->warn('unlisting built services failed', $e);
        }
        $this->eligibleMemo = [];
        $this->audit($teamId, 'service_team.removed', $byUid, []);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Descriptions
    // ──────────────────────────────────────────────────────────────────────

    /**
     * What Manage team → Services renders for one team, and what the
     * wizard's checkbox reads to decide whether it is greyed out.
     *
     * `claimedByOther` is the whole gate; `holderName` is what the greyed
     * control says. The holding team's *name* is disclosed to any team
     * admin who asks, deliberately: a control that greys out without saying
     * who has it sends somebody to an administrator to find out, and the
     * name of a team that is visibly answering the organisation's requests
     * is not a secret.
     *
     * @return array<string, mixed>
     */
    public function describeForTeam(string $teamId): array {
        $team   = $this->teams->findByTeam($teamId);
        $holder = $this->holderOfNextcloudServices();

        return [
            'teamId'         => $teamId,
            'holdsServices'  => $holder !== null && $holder === $teamId,
            'claimedByOther' => $holder !== null && $holder !== $teamId,
            'holderTeamId'   => $holder ?? '',
            'holderName'     => $holder !== null ? $this->teamName($holder) : '',
            'agentCount'     => $team !== null && $team->isActive() ? count($this->eligibleAgents($teamId)) : 0,
            'updatedAt'      => $team?->getUpdatedAt() ?? 0,
        ];
    }

    /**
     * The catalogue vocabulary: every service the bundle carries, with its
     * words and its definition. This is what the **?** button beside
     * *Nextcloud Services* opens, and what the request picker labels its
     * cards with.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeAvailableServices(): array {
        $out = [];
        foreach (ServiceCatalogue::SERVICES as $key) {
            if (!$this->isServiceOffered($key)) {
                continue;
            }
            $described = ServiceCatalogue::describe($this->l, $key);
            $out[] = [
                'serviceKey'    => $key,
                'definitionKey' => (string)ServiceCatalogue::definitionFor($key),
                'label'         => $described['label'],
                'description'   => $described['description'],
            ];
        }
        return $out;
    }

    /**
     * The team's display name. Read from the circle row directly:
     * `TeamService::getTeam()` gates on membership, and the team asking who
     * holds the bundle is usually not a member of the team that does.
     */
    public function teamName(string $teamId): string {
        if ($teamId === '') {
            return '';
        }
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            return $row ? (string)$row['name'] : '';
        } catch (\Throwable $e) {
            $this->warn('team name lookup failed', $e);
            return '';
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Direct Circles level of the user on the team; 0 when not a direct
     * member.
     *
     * `protected`, like the two lookups below, so a test can replace the
     * three places this class reads Circles without a database —
     * `FakeActorResolver` is the same shape, and for the same reason: what
     * is worth testing here is the rule, not the SELECT.
     */
    protected function memberLevel(string $uid, string $teamId): int {
        try {
            return $this->memberService->getMemberLevelFromDb($this->db, $teamId, $uid);
        } catch (\Throwable $e) {
            $this->warn('member level lookup failed', $e);
            return 0;
        }
    }

    /**
     * Direct user members of the team, any level.
     *
     * @return string[] uids
     */
    protected function directMembers(string $teamId): array {
        if ($teamId === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->andWhere($qb->expr()->gte('level', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $out = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $uid = (string)$row['user_id'];
            if ($uid !== '') {
                $out[$uid] = true;
            }
        }
        $res->closeCursor();
        return array_keys($out);
    }

    /**
     * The Nextcloud groups that are members of the team
     * (`CirclesMemberType::TYPE_GROUP`). Circles stores a group member's id
     * in the same `user_id` column as a user's.
     *
     * @return string[] group ids
     */
    protected function memberGroups(string $teamId): array {
        if ($teamId === '') {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(CirclesMemberType::TYPE_GROUP, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')));
        $out = [];
        $res = $qb->executeQuery();
        while ($row = $res->fetch()) {
            $gid = (string)$row['user_id'];
            if ($gid !== '') {
                $out[$gid] = true;
            }
        }
        $res->closeCursor();
        return array_keys($out);
    }

    /** @param array<string, mixed> $meta */
    private function audit(string $teamId, string $event, string $uid, array $meta): void {
        try {
            $this->auditService->log($teamId, $event, $uid, 'service_team', $teamId, $meta);
        } catch (\Throwable $e) {
            // An audit line must never be the reason a service desk cannot be saved.
            $this->warn('audit line failed', $e);
        }
    }

    private function warn(string $what, \Throwable $e): void {
        $this->logger->warning('[TeamHub][ServiceTeam] ' . $what, [
            'error' => $e->getMessage(), 'app' => Application::APP_ID,
        ]);
    }
}
