<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamTemplates;
use OCA\TeamHub\Db\IdpGroupMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creating a TeamHub team for a Nextcloud group that came from a directory
 * (v4.11.32, DESIGN §2.15x).
 *
 * {@see IdpTeamService} decides *whether* a group should have a team. This
 * makes it, and nothing else does: every entry point — the hourly sweep, the
 * listener's queued job, an administrator clicking Create in the grid — ends
 * up in {@see provision()}.
 *
 * ## Unlicensed, and therefore its own path (Justin, 2026-10-06)
 *
 * The obvious reuse was `TeamImportService`, which already provisions many
 * teams from many rows. It is not used here because its entry point
 * (`validateRows()` → `requireBulkActor()`) requires
 * `LicenseService::allowsAdvancedCreation()`, and Justin's decision is that
 * directory provisioning is unlicensed: *"we want as many people in our app as
 * possible. When they land they should want to stay and there is where
 * licenses come around the corner."*
 *
 * **So the creation sequence now exists in two places**, and that is a known,
 * accepted cost rather than an oversight. The sibling is
 * `TeamImportService::provisionOne()`, whose inline comments explain *why*
 * each step sits where it does — read them before reordering anything here:
 *
 *   1. `createTeam()` — the only circle creation in the app
 *   2. `assignAtCreation()` **before** the privacy bitmask, because
 *      `updateTeamConfig()` overlays whatever the policy governs on top of the
 *      caller's value; the other way round, the template's preselection wins
 *      over the policy
 *   3. the template's privacy bitmask
 *   4. the team type, through the mapper rather than `TeamTypeService::setType()`
 *      (which re-checks a level we already hold)
 *   5. the template's app resources
 *   6. the group as a member — last, because it is the only step whose failure
 *      leaves a usable team behind
 *
 * A change to the ordering in either place belongs in both. DESIGN §2.15x
 * records the duplication so it is findable.
 *
 * ## Why a service account, and why impersonation
 *
 * `TeamService::createTeam()` opens a Circles session from
 * `IUserSession::getUser()`, and `ResourceService::createTeamResources()`
 * reads the session user to decide who owns the Talk room, the folder, the
 * calendar and the board. A background job has no session at all.
 *
 * The audit that produced this design found exactly three places TeamHub uses
 * a team's owner as an *actor* rather than as a gate — the archive
 * destination fallback, resource provisioning, and decision-approver seeding
 * — so one configured account covers all three. Impersonation is
 * `IUserSession::setUser()` in a `finally`, re-checking the account first,
 * which is the pattern `TeamImportJob` and `TeamAdoptionProvisionJob` already
 * implement.
 *
 * The permission gate is `MemberService::canUserBulkCreateTeams()`: stricter
 * than ordinary team creation (NC admins only when no creator group is set),
 * and deliberately *not* paired with the licence check here — its own
 * docblock notes that licence gating is a separate question asked by the
 * caller, and this caller does not ask it.
 */
class IdpTeamProvisionService {

    /** The heavy services, resolved lazily — see getters at the bottom. */
    private const C_TEAM     = TeamService::class;
    private const C_POLICY   = PolicyService::class;
    private const C_RESOURCE = ResourceService::class;
    private const C_MEMBER   = MemberService::class;
    private const C_REGISTRY = TeamRegistryService::class;

    /**
     * The audit events this service writes, all under
     * `AuditService::INSTANCE_SCOPE` (v4.11.39, DESIGN §2.172).
     *
     * A directory group is not a team and half of them never become one — a
     * declined group, and one that failed with `no_account`, never had a
     * `team_id` to file an event under. The instance scope is the audit log's
     * existing answer for an event that is about the installation rather than
     * one team (`PolicyService`, `BlueprintService` and `ProvisioningService`
     * already write there), so the directory history needs no schema change:
     * `target_type` is `group` and `target_id` the group id, and an accepted
     * group's *team* keeps getting its own team-keyed rows from
     * `TeamService::createTeam()` as before.
     */
    public const EV_FOUND     = 'directory.group_found';
    public const EV_ACCEPTED  = 'directory.group_accepted';
    public const EV_DECLINED  = 'directory.group_declined';
    public const EV_FAILED    = 'directory.group_failed';
    public const EV_ORPHANED  = 'directory.group_orphaned';
    public const EV_FORGOTTEN = 'directory.group_forgotten';

    /** Every event above, for the Compliance tab's filter vocabulary. */
    public const EVENTS = [
        self::EV_FOUND, self::EV_ACCEPTED, self::EV_DECLINED,
        self::EV_FAILED, self::EV_ORPHANED, self::EV_FORGOTTEN,
    ];

    public function __construct(
        private IdpGroupMapper      $groups,
        private IdpTeamService      $rule,
        private TeamTemplateMapper  $templates,
        private IConfig             $config,
        private IGroupManager       $groupManager,
        private IUserManager        $userManager,
        private IUserSession        $userSession,
        private ITimeFactory        $timeFactory,
        private AuditService        $audit,
        private IL10N               $l,
        private ContainerInterface  $container,
        private LoggerInterface     $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // The service account
    // ------------------------------------------------------------------

    /**
     * Why the configured service account cannot provision, or '' when it can.
     *
     * Returned as a reason rather than a bool so the admin panel can say what
     * is wrong, and so the job can record it on the row instead of failing
     * silently every hour.
     */
    public function serviceAccountProblem(): string {
        $uid = $this->rule->serviceAccount();
        if ($uid === '') {
            return 'no_account';
        }
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return 'no_such_account';
        }
        if (!$user->isEnabled()) {
            // A disabled account would also flip every resource it owns to
            // `owner_disabled` via UserStatusListener, so this is not merely
            // about the session.
            return 'account_disabled';
        }
        if (!$this->member()->canUserBulkCreateTeams($uid)) {
            return 'account_may_not_create_teams';
        }
        return '';
    }

    public function canProvision(): bool {
        return $this->serviceAccountProblem() === '';
    }

    // ------------------------------------------------------------------
    // Provisioning
    // ------------------------------------------------------------------

    /**
     * Create the team for one group, as the service account.
     *
     * @param string $groupId  the Nextcloud group
     * @param string $template '' to use the configured default
     * @param string $profile  '' to use the template's default
     * @param string $decidedBy uid of the administrator who chose, '' when automatic
     *
     * @return array{ok: bool, teamId: string, note: string}
     */
    public function provision(
        string $groupId,
        string $template = '',
        string $profile = '',
        string $decidedBy = '',
    ): array {
        $problem = $this->serviceAccountProblem();
        if ($problem !== '') {
            return $this->fail($groupId, $problem);
        }

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return $this->fail($groupId, 'group_gone');
        }

        // Re-check the rule at the moment of creation, not only at detection.
        // A row can sit pending in the grid for days while an administrator
        // changes the settings underneath it, and provisioning a group that no
        // longer qualifies would be the settings quietly not applying.
        if (!$this->rule->qualifiesGroup($group)) {
            return $this->fail($groupId, 'no_longer_qualifies');
        }

        $name = $this->teamName($group->getDisplayName() ?: $groupId);
        if ($name === '') {
            return $this->fail($groupId, 'unusable_name');
        }

        $template = $template !== '' ? $template : $this->rule->template();
        $profile  = $profile !== ''  ? $profile  : $this->rule->profile();

        $serviceUid = $this->rule->serviceAccount();
        $previous   = $this->userSession->getUser();
        $notes      = [];
        $teamId     = '';

        try {
            $this->userSession->setUser($this->userManager->get($serviceUid));

            // ── 1. The team (fatal) ──────────────────────────────────────
            $team   = $this->team()->createTeam($name);
            $teamId = (string)($team['id'] ?? $team['unique_id'] ?? '');
            if ($teamId === '') {
                throw new \RuntimeException('Team was created but no id was returned.');
            }

            // ── 2. Policy, before the bitmask ────────────────────────────
            $this->bestEffort($notes, 'policy', fn () => $this->policy()->assignAtCreation($teamId, $profile, $template));

            // ── 3. The template's privacy bits ───────────────────────────
            $row     = $template !== '' ? $this->templates->find($template) : null;
            $bitmask = (int)($row['preselectConfig'] ?? 0);
            if ($bitmask !== 0) {
                $this->bestEffort($notes, 'privacy settings', fn () => $this->team()->updateTeamConfig($teamId, $bitmask));
            }

            // ── 4. The team type ─────────────────────────────────────────
            if ($template !== '') {
                $this->bestEffort($notes, 'team type', fn () => $this->container
                    ->get(\OCA\TeamHub\Db\TeamTypeMapper::class)
                    ->upsert($teamId, $template, $serviceUid));
            }

            // ── 5. The template's app resources ──────────────────────────
            $apps = $row !== null && is_array($row['apps'] ?? null)
                ? array_values(array_intersect($row['apps'], TeamTemplates::APPS))
                : [];
            if ($apps !== []) {
                $this->bestEffort($notes, 'app resources', function () use ($teamId, $apps, $name) {
                    $results = $this->resource()->createTeamResources($teamId, $apps, $name, [], null);
                    $failed  = [];
                    foreach ($results as $app => $result) {
                        if (!empty($result['error'])) {
                            $failed[] = (string)$app;
                        }
                    }
                    if ($failed !== []) {
                        throw new \RuntimeException('failed for ' . implode(', ', $failed));
                    }
                });
            }

            // ── 6. The group, last ───────────────────────────────────────
            //
            // Last because every other step can fail and still leave a team an
            // administrator can finish by hand, whereas a team with no group is
            // the one outcome that silently does nothing: the whole point is
            // that the roster tracks the directory.
            $this->bestEffort($notes, 'group membership', function () use ($teamId, $groupId) {
                $results = $this->member()->inviteMembers($teamId, [['id' => $groupId, 'type' => 'group']]);
                $outcome = $results[$groupId] ?? null;
                if (is_string($outcome) && str_starts_with($outcome, 'failed')) {
                    throw new \RuntimeException(trim(substr($outcome, strlen('failed:'))));
                }
                if (!array_key_exists($groupId, $results)) {
                    // inviteMembers() reads every Circles bad-request as
                    // "already invited" and reports success, so silence can be
                    // a real refusal. Silence is the one outcome nobody can act
                    // on, so it is reported.
                    throw new \RuntimeException('no result reported');
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IdpTeams] could not provision a team for a group', [
                'groupId' => $groupId,
                'teamId'  => $teamId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
            return $this->fail($groupId, 'creation_failed: ' . $e->getMessage(), $teamId);
        } finally {
            $this->userSession->setUser($previous);
        }

        $this->record($groupId, IdpGroupMapper::STATUS_CREATED, [
            'team_id'      => $teamId,
            'template_key' => $template,
            'profile_key'  => $profile,
            'decided_by'   => $decidedBy,
            'decided_at'   => $this->timeFactory->getTime(),
            'note'         => implode('; ', $notes),
        ]);

        $this->auditGroup(self::EV_ACCEPTED, $groupId, $decidedBy, [
            'teamId'      => $teamId,
            'teamName'    => $name,
            'templateKey' => $template,
            'profileKey'  => $profile,
            'mode'        => $decidedBy !== '' ? 'by_hand' : $this->rule->mode(),
            // What a best-effort step could not finish. An accepted group
            // with a half-built team is the case an administrator most needs
            // to find later, and `note` is the only place it is recorded.
            'note'        => implode('; ', $notes),
        ]);

        $this->logger->info('[TeamHub][IdpTeams] team provisioned from a group', [
            'groupId' => $groupId,
            'teamId'  => $teamId,
            'notes'   => $notes,
            'app'     => Application::APP_ID,
        ]);

        return ['ok' => true, 'teamId' => $teamId, 'note' => implode('; ', $notes)];
    }

    // ------------------------------------------------------------------
    // Detection
    // ------------------------------------------------------------------

    /**
     * Decide what to do about one group. The single entry point for both
     * detection paths — the listener's queued job and the hourly sweep.
     *
     * A group that already has a row is never reconsidered: that row is the
     * decision, including a decline, which Justin's rule for the adoption
     * grid already establishes as permanent.
     *
     * @return string what happened, for the log: '' when nothing did
     */
    public function consider(string $groupId): string {
        if ($groupId === '' || !$this->rule->isEnabled()) {
            return '';
        }
        if ($this->groups->findByGroup($groupId) !== null) {
            return '';
        }
        $group = $this->groupManager->get($groupId);
        if ($group === null || !$this->rule->qualifiesGroup($group)) {
            return '';
        }

        if ($this->rule->mode() === IdpTeamService::MODE_REVIEW) {
            if ($this->noteSeen($groupId, IdpGroupMapper::STATUS_PENDING) === null) {
                return '';
            }
            $this->auditGroup(self::EV_FOUND, $groupId, '', [
                'backend'     => implode(',', $group->getBackendNames()),
                'memberCount' => $group->count(''),
                'mode'        => IdpTeamService::MODE_REVIEW,
            ]);
            return 'pending';
        }

        // MODE_AUTO. noteSeen() first, so two runs cannot both create a team
        // for the same group: the unique index on group_id is the lock, and
        // the loser gets null and stops here.
        if ($this->noteSeen($groupId, IdpGroupMapper::STATUS_PENDING) === null) {
            return '';
        }
        // No `EV_FOUND` on this path: in auto mode the group is found and
        // answered in the same breath, and two rows a second apart saying
        // "found" and "accepted" is noise, not a history. The accepted event
        // below carries `mode: auto`, which is the fact worth keeping.
        return $this->provision($groupId)['ok'] ? 'created' : 'failed';
    }

    /**
     * The hourly backstop: every qualifying group that has no row yet.
     *
     * The listener carries the normal case — a group newly discovered by LDAP,
     * or newly provisioned at an OIDC login, fires UserAddedEvent for each of
     * its members, so a team is considered on the next cron tick. This catches
     * what no event can signal: a qualifying group with no members at all, an
     * event that fired while TeamHub was disabled, and a settings change that
     * makes previously-ignored groups qualify.
     *
     * @return array{considered: int, pending: int, created: int, failed: int}
     */
    public function sweep(): array {
        $out = ['considered' => 0, 'pending' => 0, 'created' => 0, 'failed' => 0];
        if (!$this->rule->isEnabled()) {
            return $out;
        }

        $known = $this->groups->allGroupStatuses();
        foreach ($this->rule->qualifyingGroupIds() as $groupId) {
            if (isset($known[$groupId])) {
                continue;
            }
            $out['considered']++;
            try {
                $result = $this->consider($groupId);
                if ($result !== '' && isset($out[$result])) {
                    $out[$result]++;
                }
            } catch (\Throwable $e) {
                // One broken group must not stop the sweep.
                $out['failed']++;
                $this->logger->warning('[TeamHub][IdpTeams] could not consider a group', [
                    'groupId' => $groupId,
                    'error'   => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // The review surface
    // ------------------------------------------------------------------

    /**
     * What the administrator sees in review mode: the groups waiting for a
     * decision. What has already been decided is in the audit log (v4.11.39).
     *
     * The waiting rows are re-checked against the current rule before they
     * are shown. A row recorded days ago under different settings is not a
     * decision anybody still wants offered, and offering it would make the
     * settings look like they had not applied.
     *
     * @return array{mode: string, pending: list<array<string,mixed>>, serviceAccountProblem: string}
     */
    public function reviewQueue(): array {
        $pending = [];
        foreach ($this->groups->findByStatus(IdpGroupMapper::STATUS_PENDING) as $row) {
            $groupId = (string)($row['group_id'] ?? '');
            $group   = $groupId !== '' ? $this->groupManager->get($groupId) : null;
            if ($group === null || !$this->rule->qualifiesGroup($group)) {
                continue;
            }
            $pending[] = [
                'id'          => (int)($row['id'] ?? 0),
                'groupId'     => $groupId,
                'displayName' => $group->getDisplayName() ?: $groupId,
                'backend'     => (string)($row['backend'] ?? ''),
                'groupDn'     => (string)($row['group_dn'] ?? ''),
                'memberCount' => $group->count(''),
                'detectedAt'  => (int)($row['detected_at'] ?? 0),
            ];
        }

        // v4.11.39 — no `decided`. Everything this service decides is in the
        // Compliance tab's audit log now (DESIGN §2.172), under
        // `AuditService::INSTANCE_SCOPE`, where it is kept for the retention
        // window rather than listed for thirty days and can be exported. The
        // grid was this list's only reader.
        return [
            'mode'                  => $this->rule->mode(),
            'pending'               => $pending,
            'serviceAccountProblem' => $this->serviceAccountProblem(),
        ];
    }

    /**
     * Accept one waiting group: make the team now.
     *
     * The row is moved out of pending first, with `decideIfPending()`, so two
     * administrators in the same grid cannot both provision the same group —
     * the loser is told somebody else decided rather than creating a second
     * team.
     *
     * @return array{ok: bool, teamId: string, note: string}
     */
    public function acceptPending(int $id, string $actorUid, string $template = '', string $profile = ''): array {
        $row = $this->groups->find($id);
        if ($row === null) {
            throw new NotFoundException('No such group.');
        }
        $groupId = (string)($row['group_id'] ?? '');
        if ((string)($row['status'] ?? '') !== IdpGroupMapper::STATUS_PENDING) {
            throw new ValidationException('This group has already been decided.');
        }
        // Claim it. provision() writes the real outcome over this.
        if (!$this->groups->decideIfPending($id, IdpGroupMapper::STATUS_CREATED, [
            'decided_by' => $actorUid,
            'decided_at' => $this->timeFactory->getTime(),
        ])) {
            throw new ValidationException('This group has already been decided.');
        }

        return $this->provision($groupId, $template, $profile, $actorUid);
    }

    /**
     * Decline one waiting group. Final, like a declined adoption: the row
     * stays and the sweep skips every group that has one.
     */
    public function declinePending(int $id, string $actorUid, string $reason = ''): void {
        $row = $this->groups->find($id);
        if ($row === null) {
            throw new NotFoundException('No such group.');
        }
        if (!$this->groups->decideIfPending($id, IdpGroupMapper::STATUS_DECLINED, [
            'decided_by' => $actorUid,
            'decided_at' => $this->timeFactory->getTime(),
            'note'       => mb_substr(trim($reason), 0, 1000),
        ])) {
            throw new ValidationException('This group has already been decided.');
        }
        $this->auditGroup(self::EV_DECLINED, (string)($row['group_id'] ?? ''), $actorUid, [
            'reason' => mb_substr(trim($reason), 0, 1000),
        ]);
    }

    // ------------------------------------------------------------------
    // The group disappears
    // ------------------------------------------------------------------

    /**
     * Teams whose source group has left the directory.
     *
     * What happens is the customer's setting: `keep_and_report` (the default,
     * and the cautious one) marks the row orphaned and leaves the team alone
     * for an administrator; `archive_then_delete` hands it to TeamHub's own
     * deletion path, which honours the Archive settings — archive first if
     * archiving is on, then the hard delete the directory implies.
     *
     * **Nothing is deleted when the backends report no groups at all.** An
     * unreachable LDAP or OIDC answers an empty list, and taking that at its
     * word would delete every directory-provisioned team on the instance at
     * once. This is `GroupMirrorSyncService`'s existing lesson, and the reason
     * `GroupMirrorSyncServiceTest` has a test named after it.
     *
     * @return array{checked: int, orphaned: int, deleted: int, skipped: bool}
     */
    public function reconcileOrphans(): array {
        $out  = ['checked' => 0, 'orphaned' => 0, 'deleted' => 0, 'forgotten' => 0, 'relinked' => 0, 'skipped' => false];
        $rows = $this->groups->findAll();
        if ($rows === []) {
            return $out;
        }

        if ($this->backendsLookEmpty()) {
            $out['skipped'] = true;
            $this->logger->warning('[TeamHub][IdpTeams] the group backends report nothing; reconcile skipped', [
                'rows' => count($rows),
                'app'  => Application::APP_ID,
            ]);
            return $out;
        }

        $mode = $this->rule->onGroupGone();
        foreach ($rows as $row) {
            $groupId = (string)($row['group_id'] ?? '');
            if ($groupId === '') {
                continue;
            }
            $out['checked']++;

            $status = (string)($row['status'] ?? '');
            $teamId = (string)($row['team_id'] ?? '');
            $hasTeam = $teamId !== '' && $this->registry()->isTeamHubTeam($teamId);

            // ---- The group is still there -----------------------------
            if ($this->groupManager->get($groupId) !== null) {
                // A row saying `orphaned` about a group that exists is stale:
                // the group came back between two runs, or its team was
                // removed by hand before the cleanup could finish. Either way
                // the row is describing a world that is no longer the case.
                if ($status === IdpGroupMapper::STATUS_ORPHANED) {
                    if ($hasTeam) {
                        // The team outlived the gap, so the pair is intact
                        // again. Relink rather than delete and build a second
                        // team beside the first.
                        $this->groups->update((int)$row['id'], [
                            'status' => IdpGroupMapper::STATUS_CREATED,
                            'note'   => '',
                        ]);
                        $out['relinked']++;
                    } else {
                        $this->groups->deleteByGroup($groupId);
                        $this->auditGroup(self::EV_FORGOTTEN, $groupId, '', [
                            'reason' => 'orphan_row_without_team',
                        ]);
                        $out['forgotten']++;
                    }
                }
                continue;
            }

            // ---- The group is gone ------------------------------------
            //
            // v4.11.37, Justin 2026-10-09: *we need to have only active
            // groups in that list. So if a group gets deleted the record is
            // also deleted and a new group with the same name has a new
            // chance of getting a team.* A row outliving its group meant a
            // group deleted and recreated a year later was skipped forever,
            // silently, in review mode as much as in auto.
            //
            // Nothing to clean up — a row with no team, or one whose team
            // has already been removed — so the row simply goes.
            if (!$hasTeam) {
                $this->groups->deleteByGroup($groupId);
                // The record is gone, so from here on the audit log is the
                // *only* trace that this group was ever considered — which is
                // the whole reason 4.11.39 writes these events.
                $this->auditGroup(self::EV_FORGOTTEN, $groupId, '', [
                    'reason'         => 'group_gone',
                    'previousStatus' => $status,
                ]);
                $out['forgotten']++;
                continue;
            }

            try {
                if ($status !== IdpGroupMapper::STATUS_ORPHANED) {
                    $this->groups->update((int)$row['id'], [
                        'status' => IdpGroupMapper::STATUS_ORPHANED,
                        // Stored translated, the same convention as
                        // TeamAdoptionService's `reason`: the grid renders the
                        // column verbatim, and this runs in a job with no
                        // user locale, so it lands in the instance language.
                        'note'   => $this->l->t('The group this team was provisioned from no longer exists.'),
                    ]);
                    $this->auditGroup(self::EV_ORPHANED, $groupId, '', [
                        'teamId'         => $teamId,
                        'previousStatus' => $status,
                        'onGroupGone'    => $mode,
                    ]);
                    $out['orphaned']++;
                }

                // Both modes delete; the mode decides whether an archive is
                // produced first (v4.11.33, Justin: the only two choices are
                // *archive the team, then delete it* and *delete it*).
                //
                // The row stays while this keeps failing: it is the only
                // record that a team still needs removing. The next run
                // retries, and once the team is gone the branch above forgets
                // the row.
                if ($this->deleteOrphanedTeam($teamId, $mode)) {
                    $this->groups->deleteByGroup($groupId);
                    $this->auditGroup(self::EV_FORGOTTEN, $groupId, '', [
                        'reason'      => 'orphaned_team_removed',
                        'teamId'      => $teamId,
                        'onGroupGone' => $mode,
                    ]);
                    $out['deleted']++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][IdpTeams] could not handle an orphaned team', [
                    'groupId' => $groupId,
                    'teamId'  => $teamId,
                    'error'   => $e->getMessage(),
                    'app'     => Application::APP_ID,
                ]);
            }
        }

        return $out;
    }

    /**
     * Would every group look gone right now? The guard above, isolated so a
     * test can pin it.
     */
    protected function backendsLookEmpty(): bool {
        foreach ($this->groupManager->search('', 1) as $unused) {
            return false;
        }
        return true;
    }

    /**
     * Delete one orphaned team through TeamHub's own path, as the service
     * account, so the Archive settings are honoured.
     *
     * `TeamService::deleteTeam()` already clears the `CFG_APP` lock before
     * destroying the circle, and already routes through the Archive settings
     * — archive on means the contents are preserved first, and a soft mode
     * means the team is unlinked and finalised later by `PendingDeletionJob`.
     * None of that is re-implemented here.
     */
    protected function deleteOrphanedTeam(string $teamId, string $mode): bool {
        $serviceUid = $this->rule->serviceAccount();
        $actor      = $serviceUid !== '' ? $this->userManager->get($serviceUid) : null;
        if ($actor === null || !$actor->isEnabled()) {
            $this->logger->warning('[TeamHub][IdpTeams] orphaned team left standing: no usable service account', [
                'teamId' => $teamId,
                'app'    => Application::APP_ID,
            ]);
            return false;
        }

        $previous = $this->userSession->getUser();
        try {
            $this->userSession->setUser($actor);
            if ($mode === IdpTeamService::GONE_ARCHIVE_THEN_DELETE) {
                // Archive, then register the pending deletion that
                // PendingDeletionJob finalises according to the retention
                // mode. **Not `TeamService::deleteTeam()`** — that is the hard
                // destroy and produces no archive at all, which is what this
                // method did in 4.11.32 while claiming otherwise.
                $this->archive()->produceTeamArchive($teamId);
            } else {
                $this->deleteWithoutArchive($teamId);
            }
            $this->logger->info('[TeamHub][IdpTeams] orphaned team removed', [
                'teamId' => $teamId,
                'mode'   => $mode,
                'app'    => Application::APP_ID,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IdpTeams] orphaned team could not be removed', [
                'teamId' => $teamId,
                'mode'   => $mode,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        } finally {
            $this->userSession->setUser($previous);
        }
    }

    /**
     * Delete with no archive, the way `ArchiveController` routes it: a soft
     * retention mode keeps a pending-deletion row so the team is restorable
     * for its grace period, and only `hard` goes straight to the destroy.
     * `softDeleteTeamWithoutArchive()`'s own docblock says it must not be
     * called for `hard`.
     */
    protected function deleteWithoutArchive(string $teamId): void {
        // Same key and same default as ArchiveService's own read.
        $mode = $this->config->getAppValue(Application::APP_ID, 'archiveMode', 'soft30');
        if ($mode === 'hard') {
            $this->team()->deleteTeam($teamId);
            return;
        }
        $this->archive()->softDeleteTeamWithoutArchive($teamId);
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    /**
     * Note a group as seen, so the sweep stops reconsidering it. Returns the
     * row id, or null when it already had one.
     */
    public function noteSeen(string $groupId, string $status): ?int {
        $group   = $this->groupManager->get($groupId);
        $backend = $group !== null ? implode(',', $group->getBackendNames()) : '';
        $dn      = $this->rule->groupDn($groupId) ?? '';

        return $this->groups->insert($groupId, $status, $backend, $dn, $this->timeFactory->getTime());
    }

    /** @return array{ok: bool, teamId: string, note: string} */
    private function fail(string $groupId, string $note, string $teamId = ''): array {
        $this->record($groupId, IdpGroupMapper::STATUS_FAILED, [
            'team_id' => $teamId,
            'note'    => mb_substr($note, 0, 1000),
        ]);
        // A failure is the half of the directory history the decided list
        // used to be the only record of, and the half that blocks its group
        // until somebody fixes the configuration (HANDOFF 0-idp-orphan-owner).
        $this->auditGroup(self::EV_FAILED, $groupId, '', [
            'teamId' => $teamId,
            'reason' => mb_substr($note, 0, 1000),
        ]);
        return ['ok' => false, 'teamId' => $teamId, 'note' => $note];
    }

    /**
     * One directory-group event into the Compliance audit log (v4.11.39).
     *
     * `AuditService::log()` never throws, so there is no try/catch here and
     * no outcome that a failed audit write can undo.
     *
     * @param array<string,mixed> $meta
     */
    private function auditGroup(string $event, string $groupId, string $actorUid, array $meta = []): void {
        $this->audit->log(
            AuditService::INSTANCE_SCOPE,
            $event,
            // '' means TeamHub itself decided: the sweep, the listener, the
            // reconcile pass. ACTOR_SYSTEM is the uid 4.11.36 established for
            // anything the app classifies with nobody involved, so the
            // Compliance tab reads `app:teamhub` rather than an empty cell.
            $actorUid !== '' ? $actorUid : PolicyService::ACTOR_SYSTEM,
            'group',
            $groupId,
            $meta === [] ? null : $meta,
        );
    }

    /** @param array<string,string|int|null> $fields */
    private function record(string $groupId, string $status, array $fields): void {
        try {
            $row = $this->groups->findByGroup($groupId);
            if ($row === null) {
                $this->noteSeen($groupId, $status);
                $row = $this->groups->findByGroup($groupId);
            }
            if ($row === null) {
                return;
            }
            $this->groups->update((int)$row['id'], $fields + ['status' => $status]);
        } catch (\Throwable $e) {
            // The team exists either way; losing the bookkeeping must not undo
            // it. The reconcile job re-derives what it can.
            $this->logger->warning('[TeamHub][IdpTeams] could not record a provisioning outcome', [
                'groupId' => $groupId,
                'error'   => $e->getMessage(),
                'app'     => Application::APP_ID,
            ]);
        }
    }

    /**
     * A team name from a group's display name.
     *
     * `TeamService::createTeam()` refuses a path-shaped name and a duplicate,
     * so the characters that make a name path-shaped are replaced rather than
     * left to throw. The length cap matches the column.
     */
    private function teamName(string $raw): string {
        $name = trim(str_replace(['/', '\\'], '-', $raw));
        $name = (string)preg_replace('/\s+/u', ' ', $name);
        return mb_substr($name, 0, 255);
    }

    /**
     * Run one optional step; record what failed and carry on.
     *
     * @param list<string> $notes
     */
    private function bestEffort(array &$notes, string $label, callable $step): void {
        try {
            $step();
        } catch (\Throwable $e) {
            $notes[] = $label . ': ' . $e->getMessage();
            $this->logger->warning('[TeamHub][IdpTeams] provisioning step failed', [
                'step'  => $label,
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Lazy dependencies
    //
    // Resolved rather than injected: this service is built by a listener that
    // runs on every group change on the instance and almost never provisions
    // anything, and TeamService / MemberService / ResourceService are the
    // heaviest objects in the app.
    // ------------------------------------------------------------------

    protected function team(): TeamService {
        return $this->container->get(self::C_TEAM);
    }

    protected function policy(): PolicyService {
        return $this->container->get(self::C_POLICY);
    }

    protected function resource(): ResourceService {
        return $this->container->get(self::C_RESOURCE);
    }

    protected function member(): MemberService {
        return $this->container->get(self::C_MEMBER);
    }

    protected function archive(): ArchiveService {
        return $this->container->get(ArchiveService::class);
    }

    protected function registry(): TeamRegistryService {
        return $this->container->get(self::C_REGISTRY);
    }
}
