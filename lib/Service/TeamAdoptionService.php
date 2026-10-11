<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\BackgroundJob\TeamAdoptionProvisionJob;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Constants\CirclesMemberType;
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
 * ## The second candidate class — SCIM teams (v4.11.32)
 *
 * Nextcloud 35's Circles provisions a circle per SCIM group, and such a
 * circle is invisible to the rule above: `ScimService::createCircle()` writes
 * `source = Member::APP_CIRCLES` (**10001**), not 16. So the sweep looks at
 * both sources, with a second set of conditions for the new one:
 *
 *   - **`CFG_SCIM` is required, not optional.** The test instance already
 *     carries two `source = 10001` circles — Circles' own app identities,
 *     `app:circles:{singleId}` — and one of them has `config = 0`, which
 *     NOT_A_TEAM_BITS does not exclude. Keying on the source alone would have
 *     offered the Circles app's own identity circle as a team to adopt.
 *   - **The circle must be local.** A federated master's SCIM circles are
 *     cached on every slave; a slave adopting one would provision a second
 *     set of resources for a team it does not own.
 *
 * A SCIM circle also has **no human owner** (Circles makes `app:circles` its
 * owner at LEVEL_OWNER), which is why {@see route()} sends it straight to the
 * grid instead of through rules 1 to 3 — all three need a person.
 *
 * ## What happens to one — the customer's rule, not ours (v4.11.34)
 *
 * `teamAdoptionMode`, on Admin → TeamHub → Import/Export beside the grid,
 * with three values and `review` the default:
 *
 *   - **`accept`** — every team made elsewhere is accepted by itself, with
 *     the default template and that template's policy. Nobody is asked.
 *   - **`review`** — somebody is asked ({@see route()}, below).
 *   - **`decline`** — every team made elsewhere is declined by itself and
 *     stays a plain Nextcloud team. Nobody is asked.
 *
 * This **supersedes the first of Justin's four rules of 2026-09-26**
 * (DESIGN §2.149, revised by §2.168), which read "no team creator group:
 * anybody could have made the team in TeamHub, so it is accepted by
 * itself". That was a decision taken
 * for the customer about the customer's estate, and 2026-10-06 moved it to
 * the customer: an instance with no creator group that wants silent adoption
 * now says so by choosing *Accept it automatically*. The creator group no
 * longer enters the routing at all.
 *
 * Either automatic mode still writes an adoption row and still tells the
 * owner, so the grid's thirty-day list remains the record of what happened,
 * and a decline is as final as a decline made by hand.
 *
 * ## Who is asked in `review` mode ({@see route()}) — Justin, 2026-09-26
 *
 *   1. A team holds the Nextcloud services: a request in its queue.
 *   2. Otherwise, licensed: a task in the Nextcloud administrators' My Work.
 *   3. Unlicensed (no My Work, no service teams): the grid in Admin →
 *      TeamHub only, and a notification to the administrators.
 *
 * Routes 1 and 2 are one workflow (`TeamAdoptionDefinition`) with two
 * actors. The **grid** is where the decision is made in every route —
 * template and policy per team — and the requests point to it.
 *
 * A declined team is never offered again (Justin, 2026-09-26): the row
 * stays, and the sweep skips every team with a row.
 */
class TeamAdoptionService {

    /** Circles' source for a circle a user made (Contacts, Teams page, occ, API). */
    public const SOURCE_USER = 16;

    /**
     * Circles' source for a circle its own app identity made
     * (`Member::APP_CIRCLES`). **Not a team by itself** — see
     * {@see findCandidates()} — but it is what a circle provisioned from a
     * SCIM group carries, so it is the second door into the sweep. v4.11.32.
     */
    public const SOURCE_APP_CIRCLES = 10001;

    /** The two sources the sweep looks at. Everything else is not a team. */
    private const CANDIDATE_SOURCES = [self::SOURCE_USER, self::SOURCE_APP_CIRCLES];

    /** A circle somebody made: Contacts, the Teams page, occ, a provisioner. */
    public const KIND_USER = 'user';

    /** A circle Circles' SCIM integration made from a directory group. */
    public const KIND_SCIM = 'scim';

    /** How old a circle must be before the sweep looks at it. */
    public const MIN_AGE_SECONDS = 120;

    /**
     * How long the grid listed decided teams, until v4.11.39 moved that
     * history to the Compliance tab's audit log (DESIGN §2.172). Kept because
     * `TeamAdoptionMapper::findDecidedSince()` is still the right read for
     * "what was decided recently" and the window is the project's answer to
     * how long that means.
     */
    public const DECIDED_WINDOW_SECONDS = 30 * 24 * 60 * 60;

    /** A provisioning job still queued after this long is queued again. */
    public const REQUEUE_AFTER_SECONDS = 60 * 60;

    /** Notification object for the administrators' notice (route 3). */
    public const NOTIFY_OBJECT = 'team_adoption';

    // ------------------------------------------------------------------
    // What happens to a team made outside TeamHub (v4.11.34)
    // ------------------------------------------------------------------

    /** Accept it by itself, with the default template and policy. */
    public const MODE_ACCEPT = 'accept';

    /** Ask somebody — the desk, My Work, or the grid. The default. */
    public const MODE_REVIEW = 'review';

    /** Decline it by itself. It stays a plain Nextcloud team. */
    public const MODE_DECLINE = 'decline';

    public const MODES = [self::MODE_ACCEPT, self::MODE_REVIEW, self::MODE_DECLINE];

    public const SETTING_MODE = 'teamAdoptionMode';

    /**
     * The template and policy profile an **automatically** accepted team is
     * given (Justin, 2026-10-06: *we need to apply the template and policy we
     * chose as default when we use automatic acceptance of teams*). Offered
     * on Import/Export only while the mode is `accept`: in `review` the grid
     * asks per team, and in `decline` nothing is ever accepted.
     */
    public const SETTING_TEMPLATE = 'teamAdoptionTemplate';
    public const SETTING_PROFILE  = 'teamAdoptionProfile';

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
    // The rule
    // ------------------------------------------------------------------

    /**
     * What this instance does with a team made outside TeamHub.
     *
     * `review` on an unset or unrecognised value: of the three, it is the
     * only one that takes no action on somebody's team without being told
     * to, and an unreadable setting must never read as "accept everything"
     * or as "refuse everything".
     */
    public function mode(): string {
        return self::normaliseMode($this->config->getAppValue(Application::APP_ID, self::SETTING_MODE, self::MODE_REVIEW));
    }

    /**
     * The same clamp, without this service's object graph.
     *
     * `TeamService::getAdminSettings()` needs the stored rule and nothing
     * else; constructing this class for it would pull in the workflow engine
     * and the service-team service, and a failure anywhere in that graph
     * would cost the admin panel every other value it returns. So the
     * vocabulary stays here and the raw read happens there.
     */
    /**
     * The template an automatically accepted team is built from: the
     * administrator's choice when it is still a template the grid offers,
     * otherwise `collaboration`.
     */
    public function defaultTemplate(): string {
        return $this->decisions->resolveTemplate(
            trim($this->config->getAppValue(Application::APP_ID, self::SETTING_TEMPLATE, '')),
        );
    }

    /**
     * The policy profile an automatically accepted team is classified with:
     * the administrator's choice when that profile still exists, otherwise
     * `internal`, otherwise — if somebody deleted even that — whatever the
     * template sets, which may be nothing.
     */
    public function defaultProfile(): string {
        $chosen = trim($this->config->getAppValue(Application::APP_ID, self::SETTING_PROFILE, ''));
        foreach ([$chosen, TeamAdoptionDecisionService::DEFAULT_PROFILE] as $candidate) {
            if ($candidate !== '' && $this->policy->profileExists($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    public static function normaliseMode(string $raw): string {
        $mode = trim($raw);
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_REVIEW;
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
     * Record one candidate and send it where the instance's rule says.
     * Returns false when another sweep recorded it first.
     *
     * @param array{teamId: string, name: string, owner: string, kind?: string} $candidate
     */
    public function route(array $candidate, int $now): bool {
        $teamId = $candidate['teamId'];
        $owner  = $candidate['owner'];
        $isScim = ($candidate['kind'] ?? self::KIND_USER) === self::KIND_SCIM;
        $mode   = $this->mode();

        // ── The two automatic modes (v4.11.34) ─────────────────
        //
        // Both decide at once and before anything else, the SCIM branch
        // below included: the administrator has said what happens to a team
        // made elsewhere, and that answer does not depend on which door the
        // team came through. Both still write the row, so the grid's
        // thirty-day list stays the record of what was done.
        if ($mode === self::MODE_ACCEPT) {
            return $this->decideByItself($candidate, $now, true);
        }
        if ($mode === self::MODE_DECLINE) {
            return $this->decideByItself($candidate, $now, false);
        }

        // ── `review`: somebody is asked ────────────────────────

        // v4.11.32 — a SCIM team skips rules 1 and 2 and is always decided
        // in the grid. Both open a request *for the owner*, and a SCIM
        // circle's owner is the `app:circles` identity at LEVEL_OWNER, so
        // there is nobody to stand in for and nobody to notify.
        if ($isScim) {
            $id = $this->adoptions->insert($teamId, $candidate['name'], '', TeamAdoptionMapper::ROUTE_GRID, $now);
            if ($id === null) {
                return false;
            }
            $this->notifyAdmins($teamId, $candidate['name']);
            return true;
        }

        // Rules 1 and 2 need the licence (My Work and service teams are
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

        // Rule 3: the grid only.
        $id = $this->adoptions->insert($teamId, $candidate['name'], $owner, TeamAdoptionMapper::ROUTE_GRID, $now);
        if ($id === null) {
            return false;
        }
        $this->notifyAdmins($teamId, $candidate['name']);
        return true;
    }

    /**
     * `accept` and `decline` mode: record the team and decide it in the same
     * breath, with no decider and nobody asked (v4.11.34).
     *
     * `ROUTE_AUTO` on both, which is what the grid already reads to say the
     * decision was automatic rather than naming a person. A failure to
     * decide leaves the row pending, and a pending row with no request is
     * exactly what the grid is for — so the team is never lost, it waits for
     * a hand, and the administrators are told it is waiting.
     *
     * @param array{teamId: string, name: string, owner: string, kind?: string} $candidate
     */
    private function decideByItself(array $candidate, int $now, bool $accept): bool {
        $teamId = $candidate['teamId'];
        $owner  = $candidate['owner'];

        $id = $this->adoptions->insert($teamId, $candidate['name'], $owner, TeamAdoptionMapper::ROUTE_AUTO, $now);
        if ($id === null) {
            return false;
        }
        $row = $this->adoptions->find($id);
        if ($row === null) {
            return true;
        }

        // v4.11.35 — the configured pair is written onto the row before
        // the decision, exactly as the grid writes the pair a person chose.
        // `accept()` then has nothing special to know about an automatic
        // acceptance, and the row shows what was applied.
        if ($accept) {
            $this->adoptions->update($id, [
                'template_key' => $this->defaultTemplate(),
                'profile_key'  => $this->defaultProfile(),
            ]);
            $row = $this->adoptions->find($id) ?? $row;
        }

        try {
            if ($accept) {
                $this->decisions->accept($row, '');
                $this->notifyOwner($owner, $teamId, 'team_adoption_accepted', $candidate['name']);
                return true;
            }
            // Stored on the row and shown to the owner, so it has to say why
            // without naming anybody: nobody decided this.
            $reason = $this->l->t('This instance does not add teams made outside TeamHub.');
            $this->decisions->decline($row, '', $reason);
            $this->notifyOwner($owner, $teamId, 'team_adoption_declined', $candidate['name'], $reason);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamAdoption] a team could not be decided automatically; it waits in the grid', [
                'teamId' => $teamId, 'accept' => $accept, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            $this->notifyAdmins($teamId, $candidate['name']);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // The grid
    // ------------------------------------------------------------------

    /**
     * Everything the grid renders: the pending teams, the teams decided in
     * the last thirty days, the templates and policies to choose from, and
     * the instance's rule.
     *
     * The rule rides along (v4.11.34) so the empty state can tell the truth
     * in both of the grid's homes. "No teams are waiting, they appear here
     * within a few minutes" is right in `review` mode and wrong in the other
     * two, where nothing ever waits; the widget on the holding team has no
     * other way to know, because the setting is an administrator's.
     *
     * @return array<string,mixed>
     * @throws AccessDeniedException
     */
    public function grid(string $uid): array {
        $this->requireDecider($uid);

        $templates = $this->decisions->offeredTemplates();
        $profiles  = array_map(
            static fn (array $p): array => ['profileKey' => (string)$p['profileKey'], 'label' => (string)$p['label']],
            $this->policy->creationContext()['profiles'] ?? [],
        );

        // v4.11.39 — no `decided`. The thirty-day history is in the audit
        // log under Compliance now (DESIGN §2.172), and the grid was its only
        // reader, so computing it here was a query nobody looked at.
        return [
            'mode'            => $this->mode(),
            'pending'         => array_map(fn (array $r): array => $this->describe($r, $uid), $this->adoptions->findPending()),
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
    /**
     * Is one `circles_circle` row a candidate, and of which kind?
     *
     * Static and separate from the query on purpose (v4.11.32): this is where
     * every rule about "what counts as an adoptable team" lives, it is the
     * part that has been wrong before, and a unit test can reach it without a
     * database. The query above only decides which rows are worth asking
     * about.
     *
     * @param array<string,mixed> $row
     * @return array{teamId: string, name: string, owner: string, kind: string}|null
     */
    public static function candidateFromRow(array $row, int $now): ?array {
        $config = (int)($row['config'] ?? 0);
        $source = (int)($row['source'] ?? 0);

        if ($source === self::SOURCE_APP_CIRCLES) {
            // ── The SCIM class ───────────────────────────────────────────
            //
            // `CFG_SCIM` is not optional here, and the test instance is why:
            // it already carries two `source = 10001` circles — Circles' own
            // app identities, named `app:circles:{singleId}` — and one of them
            // has **`config = 0`**, which NOT_A_TEAM_BITS does not exclude.
            // Keying on the source alone would have offered the Circles app's
            // own identity circle to administrators as a team to adopt.
            if (($config & CirclesConfig::CFG_SCIM) === 0) {
                return null;
            }
            // Only the instance that owns the circle may adopt it. On a
            // federated estate the master's SCIM circles are cached on every
            // slave, and without this a slave would adopt the master's team
            // and provision a *second* folder, Talk room, calendar and board
            // for it.
            //
            // No alias list, like every other `isLocalInstance()` caller in
            // the app: Circles stores a circle this instance owns with
            // `instance = ''` and a remote one with the remote's name, so the
            // empty-string test is the whole question here. (Verified on the
            // test instance: every circle on it carries `''`.)
            if (!CirclesMemberType::isLocalInstance((string)($row['instance'] ?? ''))) {
                return null;
            }
        } elseif ($source === self::SOURCE_USER) {
            if (($config & self::NOT_A_TEAM_BITS) !== 0) {
                return null;
            }
        } else {
            // Any other source is not a team at all. Reached only if the
            // query is ever widened; stated rather than assumed.
            return null;
        }

        // Circles writes `creation` in UTC (Nextcloud runs PHP in UTC). The
        // age guard is what keeps TeamHub's own createTeam() out: its circle
        // exists for a moment before the registry row does.
        $created = isset($row['creation']) && $row['creation'] !== null
            ? strtotime((string)$row['creation'] . ' UTC')
            : false;
        if ($created !== false && $now - $created < self::MIN_AGE_SECONDS) {
            return null;
        }

        $teamId = (string)($row['unique_id'] ?? '');
        if ($teamId === '') {
            return null;
        }
        $name = trim((string)($row['display_name'] ?? '')) !== ''
            ? (string)$row['display_name']
            : (string)($row['name'] ?? '');

        return [
            'teamId' => $teamId,
            'name'   => $name,
            'owner'  => '',
            // Which door it came through. A `scim` candidate has no human
            // owner at all — Circles makes `app:circles` its owner at
            // LEVEL_OWNER — so route() treats it differently.
            'kind'   => $source === self::SOURCE_APP_CIRCLES ? self::KIND_SCIM : self::KIND_USER,
        ];
    }

    private function findCandidates(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.unique_id', 'c.name', 'c.display_name', 'c.config', 'c.creation', 'c.source', 'c.instance')
            ->from('circles_circle', 'c')
            ->leftJoin('c', TeamRegistryMapper::TABLE, 'r', $qb->expr()->eq('r.team_id', 'c.unique_id'))
            ->where($qb->expr()->in('c.source', $qb->createNamedParameter(
                self::CANDIDATE_SOURCES,
                IQueryBuilder::PARAM_INT_ARRAY,
            )))
            ->andWhere($qb->expr()->isNull('r.team_id'));
        $res = $qb->executeQuery();

        $out = [];
        while ($row = $res->fetch()) {
            $candidate = self::candidateFromRow($row, $now);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
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

    private function displayName(string $uid): string {
        if ($uid === '') {
            return '';
        }
        return $this->userManager->getDisplayName($uid) ?? $uid;
    }

    // ------------------------------------------------------------------
    // Notifications — the fallback, never the workflow
    // ------------------------------------------------------------------

    /** Route 3: every administrator hears that a team waits in the grid. */
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
