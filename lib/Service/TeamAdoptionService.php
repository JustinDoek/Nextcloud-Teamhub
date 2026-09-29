<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\TeamAdoptionProvisionJob;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Db\TeamAdoptionMapper;
use OCA\TeamHub\Db\TeamRegistryMapper;
use OCA\TeamHub\Db\WorkflowStep;
use OCA\TeamHub\Db\WorkflowStepMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\Workflow\WorkflowEngine;
use OCA\TeamHub\Service\Workflow\WorkflowLicenceTier;
use OCA\TeamHub\Workflow\Definition\TeamAdoptionDefinition;
use OCA\TeamHub\Workflow\WorkflowStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Teams made outside TeamHub: finding them, asking the right people, and the
 * grid they are decided in (v4.10.50, DESIGN §2.149).
 *
 * Since 4.10.6 TeamHub shows only the teams it created (the registry). A
 * team made in Contacts, on Nextcloud's Teams page, by occ or by a
 * provisioning tool stayed invisible, and there was no way in. This is the
 * way in.
 *
 * ## Finding them — a sweep, not a listener
 *
 * {@see sweep()} runs every five minutes (`TeamAdoptionSweepJob`). A
 * candidate is a user-made circle (`source = 16`) that is not registered,
 * has no adoption row, is not app-managed (`CFG_APP` — a collective's
 * circle), is not personal/single/system, and is more than two minutes old.
 * The age is what keeps TeamHub's own `createTeam()` out: its circle exists
 * for a moment before the registry row does. A sweep also catches what an
 * event would miss — a provisioner that writes Circles' tables directly, an
 * event that fired while TeamHub was disabled.
 *
 * Every circle the 4.10.6 upgrade found was grandfathered into the registry,
 * so "unregistered" is exactly "made since 4.10.6" (Justin, 2026-09-26: all
 * of those are offered). The first sweep after an upgrade offers the backlog.
 *
 * ## Who is asked ({@see route()}) — Justin's rules, 2026-09-26
 *
 *   1. No team creator group: anybody could have made the team in TeamHub,
 *      so it is **accepted by itself** with the default template and policy.
 *   2. A team holds the Nextcloud services: a request in its queue.
 *   3. Otherwise, licensed: a task in the Nextcloud administrators' My Work.
 *   4. Unlicensed (no My Work, no service teams): the grid in Admin →
 *      TeamHub only, and a notification to the administrators.
 *
 * Routes 2 and 3 are one workflow (`TeamAdoptionDefinition`) with two
 * actors. The **grid** is where the decision is made in every route —
 * template and policy per team — and the requests point to it.
 *
 * A declined team is never offered again (Justin, 2026-09-26): the row
 * stays, and the sweep skips every team with a row.
 */
class TeamAdoptionService {

    /** Circles' source for a circle a user made (Contacts, Teams page, occ, API). */
    public const SOURCE_USER = 16;

    /** How old a circle must be before the sweep looks at it. */
    public const MIN_AGE_SECONDS = 120;

    /** How long the grid lists decided teams. */
    public const DECIDED_WINDOW_SECONDS = 30 * 24 * 60 * 60;

    /** A provisioning job still queued after this long is queued again. */
    public const REQUEUE_AFTER_SECONDS = 60 * 60;

    /** Notification object for the administrators' notice (route 4). */
    public const NOTIFY_OBJECT = 'team_adoption';

    /** Config bits that make a circle something other than a team. */
    private const NOT_A_TEAM_BITS = CirclesConfig::CFG_SINGLE
        | CirclesConfig::CFG_PERSONAL
        | CirclesConfig::CFG_SYSTEM
        | CirclesConfig::CFG_APP;

    public function __construct(
        private TeamAdoptionMapper          $adoptions,
        private TeamAdoptionDecisionService $decisions,
        private WorkflowEngine              $engine,
        private WorkflowStepMapper          $steps,
        private WorkflowLicenceTier         $tier,
        private ServiceTeamService          $serviceTeams,
        private PolicyService               $policy,
        private IDBConnection               $db,
        private IConfig                     $config,
        private IGroupManager               $groupManager,
        private IUserManager                $userManager,
        private IJobList                    $jobList,
        private INotificationManager        $notificationManager,
        private ITimeFactory                $timeFactory,
        private IL10N                       $l,
        private LoggerInterface             $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // The sweep
    // ------------------------------------------------------------------

    /**
     * Find new candidates and route each; withdraw rows whose team is gone
     * or registered some other way; re-queue lost provisioning jobs.
     *
     * @return array{found: int, withdrawn: int, requeued: int}
     */
    public function sweep(): array {
        $now   = $this->timeFactory->getTime();
        $known = $this->adoptions->allTeamIds();
        $found = 0;

        foreach ($this->findCandidates($now) as $candidate) {
            if (isset($known[$candidate['teamId']])) {
                continue;
            }
            try {
                if ($this->route($candidate, $now)) {
                    $found++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][TeamAdoption] could not route a team made outside TeamHub', [
                    'teamId' => $candidate['teamId'], 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $withdrawn = 0;
        foreach ($this->adoptions->findPending() as $row) {
            $gone       = !$this->decisions->circleExists((string)$row['teamId']);
            $registered = !$gone && $this->isRegistered((string)$row['teamId']);
            if (!$gone && !$registered) {
                continue;
            }
            $reason = $gone
                ? $this->l->t('This team no longer exists in Nextcloud.')
                : $this->l->t('The team was added to TeamHub another way.');
            $this->closeRequest($row, (string)$row['ownerUid'], $reason);
            $this->decisions->withdraw($row, '', $reason);
            $this->withdrawNotices((string)$row['teamId']);
            $withdrawn++;
        }

        $requeued = 0;
        foreach ($this->adoptions->findQueuedBefore($now - self::REQUEUE_AFTER_SECONDS) as $row) {
            $argument = ['adoptionId' => (int)$row['id']];
            if (!$this->jobList->has(TeamAdoptionProvisionJob::class, $argument)) {
                $this->jobList->add(TeamAdoptionProvisionJob::class, $argument);
                $requeued++;
            }
        }

        return ['found' => $found, 'withdrawn' => $withdrawn, 'requeued' => $requeued];
    }

    /**
     * Record one candidate and send it where the rules say. Returns false
     * when another sweep recorded it first.
     *
     * @param array{teamId: string, name: string, owner: string} $candidate
     */
    public function route(array $candidate, int $now): bool {
        $teamId = $candidate['teamId'];
        $owner  = $candidate['owner'];

        // Rule 1: no creator group — accepted by itself.
        if ($this->creatorGroup() === '') {
            $id = $this->adoptions->insert($teamId, $candidate['name'], $owner, TeamAdoptionMapper::ROUTE_AUTO, $now);
            if ($id === null) {
                return false;
            }
            $row = $this->adoptions->find($id);
            if ($row !== null) {
                $this->decisions->accept($row, '');
                $this->notifyOwner($owner, $teamId, 'team_adoption_accepted', $candidate['name']);
            }
            return true;
        }

        // Rules 2 and 3 need the licence (My Work and service teams are
        // licensed) and an owner to open the request for.
        if ($this->tier->isFull() && $owner !== '') {
            $route = $this->decisions->holdingTeam() !== null
                ? TeamAdoptionMapper::ROUTE_DESK
                : TeamAdoptionMapper::ROUTE_ADMIN;
            $id = $this->adoptions->insert($teamId, $candidate['name'], $owner, $route, $now);
            if ($id === null) {
                return false;
            }
            try {
                $view = $this->engine->createForSystem(TeamAdoptionDefinition::KEY, $teamId, $owner, [
                    'teamName'   => $candidate['name'],
                    'adoptionId' => $id,
                ]);
                $this->adoptions->update($id, ['workflow_id' => (int)($view['id'] ?? 0) ?: null]);
                return true;
            } catch (\Throwable $e) {
                // The row is recorded; without a request it falls back to
                // the grid, which is where the decision is made anyway.
                $this->logger->warning('[TeamHub][TeamAdoption] request could not be opened; the team waits in the grid', [
                    'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $this->adoptions->update($id, ['route' => TeamAdoptionMapper::ROUTE_GRID]);
                $this->notifyAdmins($teamId, $candidate['name']);
                return true;
            }
        }

        // Rule 4: the grid only.
        $id = $this->adoptions->insert($teamId, $candidate['name'], $owner, TeamAdoptionMapper::ROUTE_GRID, $now);
        if ($id === null) {
            return false;
        }
        $this->notifyAdmins($teamId, $candidate['name']);
        return true;
    }

    // ------------------------------------------------------------------
    // The grid
    // ------------------------------------------------------------------

    /**
     * Everything the grid renders: the pending teams, the teams decided in
     * the last thirty days, and the templates and policies to choose from.
     *
     * @return array<string,mixed>
     * @throws AccessDeniedException
     */
    public function grid(string $uid): array {
        $this->requireDecider($uid);
        $now = $this->timeFactory->getTime();

        $templates = $this->decisions->offeredTemplates();
        $profiles  = array_map(
            static fn (array $p): array => ['profileKey' => (string)$p['profileKey'], 'label' => (string)$p['label']],
            $this->policy->creationContext()['profiles'] ?? [],
        );

        return [
            'pending'         => array_map(fn (array $r): array => $this->describe($r, $uid), $this->adoptions->findPending()),
            'decided'         => array_map(fn (array $r): array => $this->describe($r, $uid), $this->adoptions->findDecidedSince($now - self::DECIDED_WINDOW_SECONDS)),
            'templates'       => $templates,
            'profiles'        => $profiles,
            'defaultTemplate' => $this->decisions->resolveTemplate(''),
        ];
    }

    /**
     * Keep the template and policy chosen for a pending team, so the
     * request's own *Accept* uses them too.
     *
     * @return array<string,mixed>
     */
    public function choose(int $id, string $uid, string $templateKey, string $profileKey): array {
        $this->requireDecider($uid);
        $row = $this->requirePending($id);
        [$templateKey, $profileKey] = $this->validateChoice($templateKey, $profileKey);
        $this->adoptions->update($id, ['template_key' => $templateKey, 'profile_key' => $profileKey]);
        return $this->describe($this->adoptions->find($id) ?? $row, $uid);
    }

    /**
     * Accept from the grid, with the template and policy chosen there.
     *
     * @return array<string,mixed>
     */
    public function accept(int $id, string $uid, string $templateKey, string $profileKey): array {
        $this->requireDecider($uid);
        $row = $this->requirePending($id);
        [$templateKey, $profileKey] = $this->validateChoice($templateKey, $profileKey);
        $this->adoptions->update($id, ['template_key' => $templateKey, 'profile_key' => $profileKey]);
        $row = $this->adoptions->find($id) ?? $row;

        if ($this->answerThroughRequest($row, $uid, 'accept', '')) {
            $this->withdrawNotices((string)$row['teamId']);
            return $this->describe($this->adoptions->find($id) ?? $row, $uid);
        }

        $row = $this->decisions->accept($row, $uid);
        $this->closeRequest($row, $uid, $this->l->t('Accepted by a Nextcloud administrator in Admin → TeamHub.'));
        if ($row['workflowId'] === null) {
            $this->notifyOwner((string)$row['ownerUid'], (string)$row['teamId'], 'team_adoption_accepted', (string)$row['teamName']);
        }
        $this->withdrawNotices((string)$row['teamId']);
        return $this->describe($row, $uid);
    }

    /**
     * Decline from the grid. Final.
     *
     * @return array<string,mixed>
     */
    public function decline(int $id, string $uid, string $reason): array {
        $this->requireDecider($uid);
        $row    = $this->requirePending($id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException($this->l->t('A reason is required.'));
        }

        if ($this->answerThroughRequest($row, $uid, 'decline', $reason)) {
            $this->withdrawNotices((string)$row['teamId']);
            return $this->describe($this->adoptions->find($id) ?? $row, $uid);
        }

        $row = $this->decisions->decline($row, $uid, $reason);
        $this->closeRequest($row, $uid, $this->l->t('Declined by a Nextcloud administrator in Admin → TeamHub.'));
        if ($row['workflowId'] === null) {
            $this->notifyOwner((string)$row['ownerUid'], (string)$row['teamId'], 'team_adoption_declined', (string)$row['teamName'], $reason);
        }
        $this->withdrawNotices((string)$row['teamId']);
        return $this->describe($row, $uid);
    }

    /** Whether the viewer may use the grid at all. */
    public function mayDecide(string $uid): bool {
        return $this->decisions->mayDecide($uid);
    }

    /** The team whose home carries the grid as a widget, or null. */
    public function holdingTeam(): ?string {
        return $this->decisions->holdingTeam();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Answer through the open request when the decider holds its step:
     * a member of the holding team claims it (the engine's
     * claim-before-act rule) and completes or rejects it; an administrator
     * on the administrators' route completes or rejects it directly. The
     * definition's hooks then record the decision on the row.
     *
     * Returns false when the request is not theirs to answer — an
     * administrator deciding a request that sits in a desk's queue — and
     * the caller decides directly and closes the request instead.
     */
    private function answerThroughRequest(array $row, string $uid, string $action, string $reason): bool {
        $workflowId = $row['workflowId'];
        if ($workflowId === null) {
            return false;
        }
        $decide = $this->decideStep((int)$workflowId);
        if ($decide === null) {
            return false;
        }

        $route  = (string)$row['route'];
        $holder = $this->decisions->holdingTeam();
        if ($route === TeamAdoptionMapper::ROUTE_DESK) {
            if ($holder === null || !$this->serviceTeams->isEligibleAgent($uid, $holder)) {
                return false;
            }
            $assignee = $decide->getAssignee();
            if ($assignee !== '' && $assignee !== $uid) {
                if ($this->groupManager->isAdmin($uid)) {
                    return false;
                }
                throw new WorkflowTransitionException($this->l->t('Another member of the service team has this request.'));
            }
            if ($assignee === '') {
                $this->engine->claimStep((int)$workflowId, $uid, TeamAdoptionDefinition::STEP_DECIDE);
            }
        } elseif ($route === TeamAdoptionMapper::ROUTE_ADMIN) {
            if (!$this->groupManager->isInGroup($uid, TeamAdoptionDefinition::ADMIN_GROUP)) {
                return false;
            }
        } else {
            return false;
        }

        if ($action === 'accept') {
            $this->engine->completeStep((int)$workflowId, $uid, null, TeamAdoptionDefinition::STEP_DECIDE);
        } else {
            $this->engine->rejectStep((int)$workflowId, $uid, $reason, TeamAdoptionDefinition::STEP_DECIDE);
        }
        return true;
    }

    /** The open request's decide step, or null when the request has ended. */
    private function decideStep(int $workflowId): ?WorkflowStep {
        try {
            foreach ($this->steps->findByInstance($workflowId) as $step) {
                if ($step->getStepKey() === TeamAdoptionDefinition::STEP_DECIDE
                    && in_array($step->getStepStatus(), WorkflowStepStatus::ACTIVE, true)) {
                    return $step;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamAdoption] could not read the request', [
                'workflowId' => $workflowId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return null;
    }

    /**
     * End a row's request, if it has an open one, as withdrawn. Used when the
     * decision was taken outside the request. `cancel()` allows the person
     * who started it (the owner, in whose name TeamHub opened it) and any
     * Nextcloud administrator.
     */
    private function closeRequest(array $row, string $uid, string $reason): void {
        $workflowId = $row['workflowId'];
        if ($workflowId === null) {
            return;
        }
        $actor = ($uid !== '' && $this->groupManager->isAdmin($uid)) ? $uid : (string)$row['ownerUid'];
        if ($actor === '') {
            return;
        }
        try {
            $this->engine->cancel((int)$workflowId, $actor, $reason);
        } catch (\Throwable $e) {
            // Already ended — nothing to close.
            $this->logger->debug('[TeamHub][TeamAdoption] request already closed', [
                'workflowId' => $workflowId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} the template and policy to store
     * @throws ValidationException
     */
    private function validateChoice(string $templateKey, string $profileKey): array {
        $offered = array_column($this->decisions->offeredTemplates(), 'templateKey');
        if ($templateKey !== '' && !in_array($templateKey, $offered, true)) {
            throw new ValidationException($this->l->t('This template cannot be chosen here.'));
        }
        if ($profileKey !== '' && !$this->policy->profileExists($profileKey)) {
            throw new ValidationException($this->l->t('This policy does not exist.'));
        }
        return [$templateKey, $profileKey];
    }

    /** @throws AccessDeniedException */
    private function requireDecider(string $uid): void {
        if (!$this->decisions->mayDecide($uid)) {
            throw new AccessDeniedException($this->l->t('You cannot decide which teams join TeamHub.'));
        }
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException|WorkflowTransitionException
     */
    private function requirePending(int $id): array {
        $row = $this->adoptions->find($id);
        if ($row === null) {
            throw new NotFoundException($this->l->t('This team is not waiting for a decision.'));
        }
        if ($row['status'] !== TeamAdoptionMapper::STATUS_PENDING) {
            throw new WorkflowTransitionException($this->l->t('This team is no longer waiting for a decision.'));
        }
        return $row;
    }

    /**
     * One grid row. The template and policy come back resolved (the
     * defaults filled in) so the grid's pickers show what *Accept* will do.
     *
     * @return array<string,mixed>
     */
    private function describe(array $row, string $viewerUid): array {
        $pending  = $row['status'] === TeamAdoptionMapper::STATUS_PENDING;
        $template = $pending ? $this->decisions->resolveTemplate((string)$row['templateKey']) : (string)$row['templateKey'];
        $profile  = (string)$row['profileKey'];
        if ($pending && $profile === '') {
            $profile = (string)($this->policy->defaultProfileForTemplate($template) ?? '');
        }
        $assignee = '';
        if ($pending && $row['workflowId'] !== null) {
            $step = $this->decideStep((int)$row['workflowId']);
            $assignee = $step !== null ? $step->getAssignee() : '';
        }
        return [
            'id'              => (int)$row['id'],
            'teamId'          => (string)$row['teamId'],
            'teamName'        => (string)$row['teamName'],
            'ownerUid'        => (string)$row['ownerUid'],
            'ownerName'       => $this->displayName((string)$row['ownerUid']),
            'status'          => (string)$row['status'],
            'route'           => (string)$row['route'],
            'workflowId'      => $row['workflowId'],
            'templateKey'     => $template,
            'profileKey'      => $profile,
            'decidedBy'       => (string)$row['decidedBy'],
            'decidedByName'   => $this->displayName((string)$row['decidedBy']),
            'decidedAt'       => $row['decidedAt'],
            'reason'          => (string)$row['reason'],
            'provisionStatus' => (string)$row['provisionStatus'],
            'provisionNote'   => (string)$row['provisionNote'],
            'detectedAt'      => (int)$row['detectedAt'],
            // Somebody on the desk has claimed the request: only they, or an
            // administrator, can answer it from here.
            'claimedBy'       => $assignee,
            'claimedByName'   => $this->displayName($assignee),
            'claimedByOther'  => $assignee !== '' && $assignee !== $viewerUid && !$this->groupManager->isAdmin($viewerUid),
        ];
    }

    /**
     * @return list<array{teamId: string, name: string, owner: string}>
     */
    private function findCandidates(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.unique_id', 'c.name', 'c.display_name', 'c.config', 'c.creation')
            ->from('circles_circle', 'c')
            ->leftJoin('c', TeamRegistryMapper::TABLE, 'r', $qb->expr()->eq('r.team_id', 'c.unique_id'))
            ->where($qb->expr()->eq('c.source', $qb->createNamedParameter(self::SOURCE_USER, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('r.team_id'));
        $res = $qb->executeQuery();

        $out = [];
        while ($row = $res->fetch()) {
            $config = (int)($row['config'] ?? 0);
            if (($config & self::NOT_A_TEAM_BITS) !== 0) {
                continue;
            }
            // Circles writes `creation` in UTC (Nextcloud runs PHP in UTC).
            $created = isset($row['creation']) && $row['creation'] !== null
                ? strtotime((string)$row['creation'] . ' UTC')
                : false;
            if ($created !== false && $now - $created < self::MIN_AGE_SECONDS) {
                continue;
            }
            $teamId = (string)$row['unique_id'];
            $name   = trim((string)($row['display_name'] ?? '')) !== '' ? (string)$row['display_name'] : (string)$row['name'];
            $out[] = ['teamId' => $teamId, 'name' => $name, 'owner' => ''];
        }
        $res->closeCursor();

        if ($out === []) {
            return [];
        }
        $owners = $this->ownersOf(array_column($out, 'teamId'));
        foreach ($out as &$candidate) {
            $candidate['owner'] = $owners[$candidate['teamId']] ?? '';
        }
        unset($candidate);
        return $out;
    }

    /**
     * The local user who owns each circle (level 9, a user member).
     *
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function ownersOf(array $teamIds): array {
        $owners = [];
        foreach (array_chunk($teamIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('circle_id', 'user_id')
                ->from('circles_member')
                ->where($qb->expr()->in('circle_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('level', $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $owners[(string)$row['circle_id']] = (string)$row['user_id'];
            }
            $res->closeCursor();
        }
        return $owners;
    }

    private function isRegistered(string $teamId): bool {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('id')
            ->from(TeamRegistryMapper::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row !== false;
    }

    private function creatorGroup(): string {
        return trim($this->config->getAppValue(Application::APP_ID, 'createTeamGroup', ''));
    }

    private function displayName(string $uid): string {
        if ($uid === '') {
            return '';
        }
        return $this->userManager->getDisplayName($uid) ?? $uid;
    }

    // ------------------------------------------------------------------
    // Notifications — the fallback, never the workflow
    // ------------------------------------------------------------------

    /** Route 4: every administrator hears that a team waits in the grid. */
    private function notifyAdmins(string $teamId, string $teamName): void {
        $group = $this->groupManager->get('admin');
        if ($group === null) {
            return;
        }
        foreach ($group->getUsers() as $admin) {
            $this->send($admin->getUID(), $teamId, 'team_adoption_pending', ['teamName' => $teamName]);
        }
    }

    /** The owner hears the answer when no request carried it. */
    private function notifyOwner(string $ownerUid, string $teamId, string $subject, string $teamName, string $reason = ''): void {
        if ($ownerUid === '') {
            return;
        }
        $this->send($ownerUid, $teamId, $subject, ['teamName' => $teamName, 'reason' => $reason]);
    }

    /** @param array<string,string> $params */
    private function send(string $uid, string $teamId, string $subject, array $params): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                ->setObject(self::NOTIFY_OBJECT, $teamId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamAdoption] notification failed', [
                'subject' => $subject, 'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** The administrators' "waits in the grid" notices, once decided. */
    private function withdrawNotices(string $teamId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setObject(self::NOTIFY_OBJECT, $teamId)
                ->setSubject('team_adoption_pending');
            $this->notificationManager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamAdoption] could not withdraw notifications', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
