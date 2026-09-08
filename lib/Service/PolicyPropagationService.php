<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\TeamAppPresenceMapper;
use OCA\TeamHub\Db\TeamPolicyMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Rolling a changed profile or template out to the teams that carry it
 * (v4.8.27), and reporting on every team that does.
 *
 * ── The shape of the feature, and why it is this shape ────────────────────
 *
 * An administrator edits a profile or a template and saves it. The save stores
 * the definition and **nothing else** — then it hands back a plan naming the
 * teams that carry it, split into the ones that still match what the definition
 * said before and the ones that have drifted. The administrator chooses: roll
 * out to the compliant ones, force it to all of them, or neither. This class
 * executes whichever they chose and reports per team.
 *
 * **The admin chooses; we do not choose for them.** That is Justin's call on
 * 2026-09-07 and it settles a tension the earlier design could not: applying
 * automatically would be a silent bulk state change of exactly the kind
 * `TRACK-F2-DESIGN.md` §4.3 forbids for a single team, while never applying
 * leaves an edited profile meaning nothing until somebody visits every team.
 * Offering both, with the count on screen, needs neither compromise.
 *
 * ── Why the caller names the teams ───────────────────────────────────────
 *
 * `propagate*()` takes team ids rather than re-deriving them. It has to:
 * compliance is measured against the definition's **previous** values, and by
 * the time this runs those are overwritten. The plan was computed inside the
 * save, between the read and the write, which is the only moment both existed.
 *
 * The ids are therefore not trusted for *authorisation* — every one is checked
 * to still carry the profile or template before anything is written, and the
 * caller is a Nextcloud administrator who could apply to any team by hand
 * anyway. What the list carries is the administrator's *decision*, which is not
 * something the server can recompute.
 *
 * ── What it does not do ──────────────────────────────────────────────────
 *
 * There is no durable run and no background job. The loop is synchronous and
 * capped; an estate larger than the cap is reported as such rather than
 * half-applied in silence. `TeamImportService`'s chunk pump is the pattern if
 * that ceiling is ever reached in practice — this is deliberately not that
 * until somebody needs it.
 */
class PolicyPropagationService {

    /**
     * Teams per run.
     *
     * Not a guess about the database — it is a guess about a request. Each
     * team costs a full `PolicyApplyService::apply()` (several writes and an
     * audit row), so this is the number that keeps one rollout inside a normal
     * request rather than a timeout. Exceeding it is reported, never silently
     * truncated.
     */
    private const MAX_TEAMS = 200;

    /**
     * Apps a rollout will destroy the resource of when the template drops them.
     *
     * **`files` is deliberately absent, and this is the only list in the app
     * where an omission is the safety property.** `deleteTeamResource()` with
     * `files` calls `GroupFolderService::deleteGroupFolder()`, which removes the
     * team's shared folder and everything in it. A template edit is one form
     * submission by one administrator; letting it delete the documents of every
     * team of that kind is not a rollout, it is an accident with a confirm
     * dialog in front of it. Nothing else here is reversible either, but a Talk
     * room, a Deck board and a calendar are the app's own data — a team folder
     * is the users'.
     *
     * A team folder the template no longer wants is reported instead, under
     * `manual`, so it is visible and an administrator can remove it from Manage
     * Team where the consequence is stated per team.
     */
    private const DESTRUCTIVE_ON_REMOVE = ['talk', 'calendar', 'deck', 'intravox', 'collectives'];

    public function __construct(
        private PolicyService         $policyService,
        private PolicyApplyService    $policyApplyService,
        private ResourceService       $resourceService,
        private CollectivesService    $collectivesService,
        // v4.8.32 — the four feature modules. Each has its own per-team
        // storage, so there is no single writer to reach for; these are the
        // services that own them, and `TeamImportService::applyModules()` uses
        // exactly the same three doors at creation time.
        private PresenceTeamService   $presenceTeamService,
        private DecisionTeamService   $decisionTeamService,
        private IConfig               $config,
        private TeamService           $teamService,
        private TeamPolicyMapper      $teamPolicyMapper,
        private TeamTypeMapper        $teamTypeMapper,
        private TeamAppPresenceMapper $appPresenceMapper,
        // v4.8.27b — team names come from here, not `TeamService::getTeam()`,
        // which gates on membership: a Nextcloud administrator rolling a
        // template out is not a member of most teams, so every one of them came
        // back "Team not found or access denied". The plan already used this
        // reader; the apply pass did not, and that was the whole failure.
        private PolicyObservationMapper $observationMapper,
        private AuditService          $auditService,
        private IUserSession          $userSession,
        private IGroupManager         $groupManager,
        private LoggerInterface       $logger,
    ) {
    }

    /**
     * Nextcloud administrator, returning their uid.
     *
     * Same idiom as `PolicyService::requireNcAdmin()` and
     * `PolicyApplyService::requireNcAdmin()`, copied rather than reached for
     * across a service boundary — HANDOFF §00sec's complaint is about six
     * *different* gate idioms, and a fourth instance of one of them asks the
     * identical question.
     *
     * @throws AccessDeniedException
     */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Only a Nextcloud administrator can roll out a policy or template.');
        }

        return $user->getUID();
    }

    // -------------------------------------------------------------------------
    // Profiles
    // -------------------------------------------------------------------------

    /**
     * Apply the profile's current values to each named team.
     *
     * Reuses `PolicyApplyService::apply()` verbatim, which is the whole reason
     * this class is thin. A rollout is not a different kind of write from an
     * administrator applying the profile by hand — it is the same write, done
     * to several teams — and a second implementation would be a second set of
     * rules for what "applied" means. `reapply: true` is passed so the audit
     * trail records `team.policy_reapplied` and a rollout can be told from a
     * first classification.
     *
     * @param list<string> $teamIds
     * @return array<string,mixed>
     * @throws AccessDeniedException|NotFoundException
     */
    public function propagateProfile(string $profileKey, array $teamIds): array {
        $actor = $this->requireNcAdmin();

        if ($this->policyService->getProfileRow($profileKey) === null) {
            throw new NotFoundException('No such policy profile.');
        }

        [$teamIds, $overflow] = $this->cap($teamIds);
        $carrying = $this->teamPolicyMapper->findAllAssignments();
        // Names for every team in the run, including the ones that fail. Read
        // here rather than per team, and from the ungated reader: the report
        // names teams a Nextcloud administrator is not a member of.
        $names = $this->observationMapper->circlesByTeam($teamIds);

        $applied = [];
        $failed  = [];

        foreach ($teamIds as $teamId) {
            $teamName = $names[$teamId]['name'] ?? $teamId;

            // Still carrying the profile? A team reassigned between the save and
            // the confirmation must not be dragged back.
            if (($carrying[$teamId] ?? null) !== $profileKey) {
                $failed[] = [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'reason'   => 'not_carrying',
                ];
                continue;
            }

            try {
                $result = $this->policyApplyService->apply($teamId, $profileKey, true);
                $applied[] = [
                    'teamId'     => $teamId,
                    'teamName'   => $result['preview']['teamName'] ?? $teamName,
                    'applied'    => $result['applied'],
                    'notApplied' => $result['notApplied'],
                ];
            } catch (\Throwable $e) {
                // One team's failure must not abandon the rest of the estate —
                // a rollout that stops at the fifth of forty teams leaves an
                // administrator with no idea which thirty-five are done.
                $this->logger->warning('[TeamHub][PolicyPropagationService] profile rollout failed for one team', [
                    'teamId'     => $teamId,
                    'profileKey' => $profileKey,
                    'error'      => $e->getMessage(),
                    'app'        => Application::APP_ID,
                ]);
                $failed[] = [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'reason'   => 'error',
                    'error'    => $e->getMessage(),
                ];
            }
        }

        $this->auditInstance('policy.profile_propagated', $actor, $profileKey, [
            'requested' => count($teamIds),
            'applied'   => count($applied),
            'failed'    => count($failed),
        ]);

        return [
            'kind'     => 'profile',
            'key'      => $profileKey,
            'applied'  => $applied,
            'failed'   => $failed,
            'overflow' => $overflow,
        ];
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * Bring each named team's apps in line with the template's current list.
     *
     * Two halves, and they are not symmetrical:
     *
     * - **Added** apps are provisioned through `createTeamResources()`, the one
     *   path that creates a team's resources. Collectives is reported rather
     *   than created: it is observable, so a team missing it is a real finding,
     *   but it is provisioned by its own path and this must not pretend
     *   otherwise.
     * - **Removed** apps are switched off **only where TeamHub created them**.
     *   A Deck board somebody made in Deck and had auto-discovered onto the team
     *   is not the template's to take away, and a template edit is far too
     *   cheap an action to have that reach. The registry's `origin` column is
     *   what makes the distinction available.
     *
     * @param list<string> $teamIds
     * @return array<string,mixed>
     * @throws AccessDeniedException|NotFoundException
     */
    public function propagateTemplate(string $templateKey, array $teamIds): array {
        $actor    = $this->requireNcAdmin();
        $template = $this->policyService->getTemplateRow($templateKey);
        if ($template === null) {
            throw new NotFoundException('No such template.');
        }

        [$teamIds, $overflow] = $this->cap($teamIds);

        $wanted = TeamApps::observableList(array_merge(
            $template['apps'] ?? [],
            $template['modules'] ?? [],
        ));
        // v4.8.32 — the feature modules travel beside the resources rather than
        // being left out. They are the reason a template says "Decisions" at
        // all, and a rollout that silently skipped them would be the same
        // "applied but nothing happened" Justin found for apps in 4.8.28.
        $wantedModules = TeamApps::featureModules($template['modules'] ?? []);

        $types    = $this->teamTypeMapper->findTypesByTeams($teamIds);
        $presence = $this->appPresenceMapper->presenceForTeams($teamIds);
        $created  = $this->appPresenceMapper->teamHubCreatedForTeams($teamIds);
        $names    = $this->observationMapper->circlesByTeam($teamIds);

        $actorUid = $actor;
        $applied  = [];
        $failed   = [];

        foreach ($teamIds as $teamId) {
            $teamName = $names[$teamId]['name'] ?? $teamId;

            if (($types[$teamId] ?? null) !== $templateKey) {
                $failed[] = ['teamId' => $teamId, 'teamName' => $teamName, 'reason' => 'not_carrying'];
                continue;
            }

            $has = $presence[$teamId] ?? [];

            $toAdd = array_values(array_diff($wanted, $has));
            // Only what TeamHub put there, and only what the template no longer
            // wants. Everything else on the team stays.
            $toRemove = array_values(array_intersect(
                array_diff($has, $wanted),
                $created[$teamId] ?? [],
            ));

            $added   = [];
            $removed = [];
            $manual  = [];
            $errors  = [];

            try {
                foreach ($toAdd as $appId) {
                    if ($this->addApp($teamId, $teamName, $appId, $actorUid)) {
                        $added[] = $appId;
                    } else {
                        $manual[] = $appId;
                    }
                }

                foreach ($toRemove as $appId) {
                    if (!in_array($appId, self::DESTRUCTIVE_ON_REMOVE, true)) {
                        // The team folder. Reported, never deleted — see the
                        // constant's docblock.
                        $manual[] = $appId;
                        continue;
                    }
                    if ($this->removeApp($teamId, $appId, $actorUid)) {
                        $removed[] = $appId;
                    } else {
                        $errors[] = $appId;
                    }
                }

                // The stored toggle follows the resource rather than standing in
                // for it. Written after the resource work so a failed delete
                // does not leave the team reading as "off" with the resource
                // still live — the presence reader treats an explicit off as
                // authoritative, and that would hide a resource nobody could
                // find again.
                $rows = [];
                foreach ($added as $appId) {
                    $rows[] = ['app_id' => $appId, 'enabled' => true, 'config' => null];
                }
                foreach ($removed as $appId) {
                    $rows[] = ['app_id' => $appId, 'enabled' => false, 'config' => null];
                }
                if ($rows !== []) {
                    $this->teamService->updateTeamApps($teamId, $rows);
                }

                // Feature modules, **after** the `teamhub_team_apps` write and
                // deliberately not folded into `$rows`: they are not apps and a
                // `presence` row in that table would be a fifth answer to
                // "which apps does this team have", which is the thing v4.8.27
                // spent a whole version removing. Switches rather than
                // resources, so both directions apply without the origin bound
                // the resource half needs — nothing is created or destroyed.
                [$modulesOn, $modulesOff] = $this->syncFeatureModules($teamId, $wantedModules);

                $applied[] = [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'added'    => array_merge($added, $modulesOn),
                    'removed'  => array_merge($removed, $modulesOff),
                    // Named so the report can say a team still needs a hand,
                    // rather than reporting success and leaving a gap.
                    'manual'   => $manual,
                    'errors'   => $errors,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][PolicyPropagationService] template rollout failed for one team', [
                    'teamId'      => $teamId,
                    'templateKey' => $templateKey,
                    'error'       => $e->getMessage(),
                    'app'         => Application::APP_ID,
                ]);
                $failed[] = [
                    'teamId'   => $teamId,
                    'teamName' => $teamName,
                    'reason'   => 'error',
                    'error'    => $e->getMessage(),
                ];
            }
        }

        $this->auditInstance('policy.template_propagated', $actor, $templateKey, [
            'requested' => count($teamIds),
            'applied'   => count($applied),
            'failed'    => count($failed),
        ]);

        return [
            'kind'     => 'template',
            'key'      => $templateKey,
            'applied'  => $applied,
            'failed'   => $failed,
            'overflow' => $overflow,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Provision one app for one team. Returns false when it could not be done.
     *
     * Collectives goes through `CollectivesService::enableForTeam()` rather than
     * the resource switch, because that is its provisioning path — v4.8.27
     * reported it as needing a hand instead, which Justin correctly read as the
     * rollout not doing what it said. Everything else goes through
     * `createTeamResources()`, the one method every creation path reaches.
     */
    private function addApp(string $teamId, string $teamName, string $appId, string $actorUid): bool {
        try {
            if ($appId === 'collectives') {
                if (!$this->collectivesService->isInstalled()) {
                    return false;
                }
                if ($this->collectivesService->isEnabledForTeam($teamId)) {
                    return true;
                }
                $result = $this->collectivesService->enableForTeam($teamId, $teamName, $actorUid);

                return empty($result['error']);
            }

            if (!TeamApps::isProvisionable($appId)) {
                return false;
            }

            $results = $this->resourceService->createTeamResources($teamId, [$appId], $teamName);

            return empty($results[$appId]['error']);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PolicyPropagationService] could not add app', [
                'teamId' => $teamId,
                'appId'  => $appId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Destroy one app's resource for one team. Returns false on failure.
     *
     * **This deletes.** A Talk room takes its messages, a Deck board its cards,
     * a calendar its events. That is what "really apply it" means and it is why
     * the caller states the consequence before running, and why `files` is not
     * in `DESTRUCTIVE_ON_REMOVE`.
     */
    private function removeApp(string $teamId, string $appId, string $actorUid): bool {
        try {
            if ($appId === 'collectives') {
                if (!$this->collectivesService->isInstalled()) {
                    return false;
                }
                $result = $this->collectivesService->disableForTeam($teamId, $actorUid);

                return empty($result['error']);
            }

            $result = $this->resourceService->deleteTeamResource($teamId, $appId);

            return !empty($result['deleted']);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PolicyPropagationService] could not remove app', [
                'teamId' => $teamId,
                'appId'  => $appId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Trim to MAX_TEAMS, reporting how many were left.
     *
     * @param list<string> $teamIds
     * @return array{0: list<string>, 1: int}
     */
    /**
     * Bring one team's feature modules in line with `$wanted` (v4.8.32).
     *
     * Each module has its own per-team storage and its own idea of a default,
     * which is why this is four cases rather than a loop over one table:
     *
     *   - **presence / decisions** — a config row, absent meaning *off*. A team
     *     that never enabled Decisions has no row at all.
     *   - **timeline / messages** — appconfig, absent meaning **on**. The
     *     inverse default, and the reason switching one on *deletes* the stored
     *     `0` rather than writing a `1`: a stored `1` would work, but it turns
     *     an inherited default into a recorded decision, and the next person to
     *     change the default would find these teams silently exempt.
     *
     * Returns the modules actually switched, so the report says what changed
     * rather than what was asked for.
     *
     * @param list<string> $wanted
     * @return array{0: list<string>, 1: list<string>} [switched on, switched off]
     */
    private function syncFeatureModules(string $teamId, array $wanted): array {
        $on  = [];
        $off = [];

        foreach (TeamApps::FEATURE_MODULES as $module) {
            $shouldBeOn = in_array($module, $wanted, true);
            if ($shouldBeOn === $this->featureModuleEnabled($teamId, $module)) {
                continue;
            }

            try {
                $this->setFeatureModule($teamId, $module, $shouldBeOn);
                if ($shouldBeOn) {
                    $on[] = $module;
                } else {
                    $off[] = $module;
                }
            } catch (\Throwable $e) {
                // One module failing must not abandon the other three, nor the
                // resource work already done for this team.
                $this->logger->warning('[TeamHub][PolicyPropagationService] could not set feature module', [
                    'teamId' => $teamId,
                    'module' => $module,
                    'error'  => $e->getMessage(),
                    'app'    => Application::APP_ID,
                ]);
            }
        }

        return [$on, $off];
    }

    private function featureModuleEnabled(string $teamId, string $module): bool {
        return match ($module) {
            'presence'  => (bool)($this->presenceTeamService->getConfig($teamId)['presence_enabled'] ?? false),
            'decisions' => (bool)($this->decisionTeamService->getConfig($teamId)['decisions_enabled'] ?? false),
            // Absent means on — the same default `LayoutController` reads.
            'timeline'  => $this->config->getAppValue(Application::APP_ID, 'timeline_enabled_' . $teamId, '1') === '1',
            'messages'  => $this->config->getAppValue(Application::APP_ID, 'messages_enabled_' . $teamId, '1') === '1',
            default     => false,
        };
    }

    private function setFeatureModule(string $teamId, string $module, bool $enabled): void {
        switch ($module) {
            case 'presence':
                $this->presenceTeamService->saveConfig($teamId, ['presence_enabled' => $enabled ? 1 : 0]);
                return;
            case 'decisions':
                $this->decisionTeamService->saveConfig($teamId, ['decisions_enabled' => $enabled ? 1 : 0]);
                return;
            case 'timeline':
            case 'messages':
                $key = $module . '_enabled_' . $teamId;
                if ($enabled) {
                    $this->config->deleteAppValue(Application::APP_ID, $key);
                } else {
                    $this->config->setAppValue(Application::APP_ID, $key, '0');
                }
                return;
        }
    }

    private function cap(array $teamIds): array {
        $teamIds = array_values(array_unique(array_map('strval', $teamIds)));
        if (count($teamIds) <= self::MAX_TEAMS) {
            return [$teamIds, 0];
        }

        return [array_slice($teamIds, 0, self::MAX_TEAMS), count($teamIds) - self::MAX_TEAMS];
    }

    /**
     * An instance-scoped audit row.
     *
     * `AuditService::log()` is team-scoped; a rollout is an act on the instance,
     * so the target is the profile or template and the team column carries
     * `INSTANCE_SCOPE`, matching `PolicyService::auditInstance()`. Wrapped in a
     * try/catch because a failed audit write must not lose a rollout that has
     * already happened — the report is the administrator's record either way.
     *
     * @param array<string,mixed> $metadata
     */
    private function auditInstance(string $eventType, string $actor, string $targetId, array $metadata): void {
        try {
            $this->auditService->log(
                AuditService::INSTANCE_SCOPE,
                $eventType,
                $actor,
                'policy',
                $targetId,
                $metadata,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][PolicyPropagationService] audit write failed', [
                'eventType' => $eventType,
                'error'     => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
        }
    }
}
