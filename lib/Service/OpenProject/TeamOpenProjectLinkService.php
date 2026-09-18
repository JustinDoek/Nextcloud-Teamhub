<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\OpenProjectMeetingSyncMapper;
use OCA\TeamHub\Db\OpenProjectNewsMirrorMapper;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\TeamService;
use OCA\TeamHub\Service\TeamTypeService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The team ↔ OpenProject project link: read it, make it once (v4.9.3),
 * with the team or not at all (v4.9.4).
 *
 * ## Who may do what
 *
 * Reading the link is a member's right — every member sees the widget.
 * Making it is the team creator's, who is the owner by the time the wizard
 * gets here (`MemberService::requireAdminLevel`, level ≥ 8), checked **here**,
 * so no controller can forget it. Removing it is a Nextcloud administrator's
 * (`adminUnlink()`, from Admin → TeamHub → Maintenance) — the one override,
 * and deliberately not a team admin's: Manage team shows the link and never
 * changes it.
 *
 * ## The three rules (Justin, 2026-09-12)
 *
 * 1. **Only a team created from the `openproject` template can be linked.**
 *    OpenProject is a project *type*; the link, the widgets and the settings
 *    section exist for that type and no other.
 * 2. **Only a project the linking user administers in OpenProject, or a
 *    public one, can be linked.** OpenProject answers that per project (the
 *    `update` link on the resource, or `public: true`); a project the user
 *    can merely see is refused. The project is always read as the linking
 *    user first, so nothing is ever written from a project id alone.
 * 3. **One project links to one team.** A project another team already
 *    links is refused (`ProjectAlreadyLinkedException`, naming that team when
 *    the caller may know its name), and `th_opl_proj_uq` backs that up.
 *
 * ## When (revised 2026-09-12, v4.9.4)
 *
 * The link is made **once, as part of creating the team** — `POST /teams`
 * runs `assertLinkableForNewTeam()` before the circle exists and
 * `linkNewTeam()` right after, and a link that fails deletes the team it was
 * for. The first draft linked in a separate wizard step after the team
 * existed, so a project taken by another team produced an OpenProject team
 * with no project and no way to give it one; Justin's rule is that a refused
 * link is fatal to the creation, not a warning on its success screen. There
 * is no route to link an existing team.
 *
 * ## Removal
 *
 * `adminUnlink()` is the administrative tool; `deleteForTeamCascade()` runs
 * on team deletion. Removing a link deletes one TeamHub row and nothing in
 * OpenProject, and the project becomes linkable by a new team.
 */
class TeamOpenProjectLinkService {

    public function __construct(
        private TeamOpenProjectLinkMapper $mapper,
        // v4.9.7 — the news-mirror ledger goes with the team; v4.9.10 — so
        // does the meeting-sync ledger (the calendar copies stay: they are
        // the team's record, and a new link adopts them by URI).
        private OpenProjectNewsMirrorMapper $newsMirror,
        private OpenProjectMeetingSyncMapper $meetingSync,
        private PolicyObservationMapper   $circles,
        private OpenProjectClient         $client,
        private OpenProjectProjectService $projects,
        private OpenProjectCache          $cache,
        private MemberService             $memberService,
        private AuditService              $auditService,
        private TeamTypeService           $teamTypeService,
        private IUserSession              $userSession,
        private IGroupManager             $groupManager,
        // For the one rollback in `linkNewTeam()`. TeamService resolves this
        // service through the container for its delete cascade; a static
        // edge in either direction is a DI cycle waiting for a third class
        // (HANDOFF: v4.8.7), so this side goes through the container too.
        private ContainerInterface        $container,
        private LoggerInterface           $logger,
    ) {
    }

    /** The one template whose teams carry an OpenProject link. */
    public const TEMPLATE = 'openproject';

    /** Is this team of the OpenProject template. */
    public function isEligibleTeam(string $teamId): bool {
        try {
            return $this->teamTypeService->getType($teamId) === self::TEMPLATE;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Read
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The team's link as the frontend sees it, or null when unlinked.
     * Membership is the caller's check; this method does no HTTP.
     *
     * @return ?array<string, mixed>
     */
    public function getLink(string $teamId): ?array {
        $row = $this->mapper->findByTeam($teamId);
        return $row === null ? null : $this->toArray($row);
    }

    /**
     * The cheap facts the layout bundle carries so the widgets can gate
     * themselves without a request: is there a link, to what, and can the
     * integration be used at all right now.
     *
     * @return array{
     *   moduleAvailable: bool, available: bool, eligible: bool, linked: bool, stale: bool,
     *   project: ?array{id: int, identifier: string, name: string, url: ?string}
     * }
     */
    public function factsForBundle(string $teamId): array {
        // v4.9.16 — the module before anything else. Switched off or
        // unlicensed, an OpenProject team reports itself as not eligible and
        // not linked: the widgets, the ⋯ actions, the calendar's OpenProject
        // rows and the Manage team panel all gate on those two facts, so one
        // answer here hides every surface without a frontend branch per
        // widget. The row stays; switching back on brings it all back. An
        // integration-level problem (app disabled, no host) is deliberately
        // *not* treated this way — the widgets render and say what is wrong,
        // which is how 4.9.13's "connection lost" picture reaches a member.
        if (!$this->client->isModuleAvailable()) {
            return ['moduleAvailable' => false, 'available' => false, 'eligible' => false, 'linked' => false, 'stale' => false, 'project' => null];
        }
        // The light check, deliberately: this runs on every layout load for
        // every member, and resolving the official app's service (which
        // `compatibilityProblem()` does) would build its whole dependency
        // graph each time. "Enabled and has a host" is what decides whether
        // the widgets are worth rendering; the widgets' own request answers
        // the rest.
        $available = $this->client->isIntegrationEnabled() && $this->client->getHost() !== '';
        $eligible  = $this->isEligibleTeam($teamId);
        if (!$eligible) {
            // Not the OpenProject template: no link is consulted, none is
            // reported, and the widgets never render — whatever rows exist.
            return ['moduleAvailable' => true, 'available' => $available, 'eligible' => false, 'linked' => false, 'stale' => false, 'project' => null];
        }
        try {
            $row = $this->mapper->findByTeam($teamId);
        } catch (\Throwable $e) {
            // A layout bundle must never fail because of this block.
            $this->logger->warning('[TeamHub][TeamOpenProjectLinkService] factsForBundle failed', [
                'teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            $row = null;
        }
        if ($row === null) {
            return ['moduleAvailable' => true, 'available' => $available, 'eligible' => true, 'linked' => false, 'stale' => false, 'project' => null];
        }
        $ref = $row->getProjectIdentifier() !== '' ? $row->getProjectIdentifier() : (string)$row->getProjectId();
        return [
            'moduleAvailable' => true,
            'available' => $available,
            'eligible'  => true,
            'linked'    => true,
            'stale'     => $this->isStale($row),
            'project'   => [
                'id'         => $row->getProjectId(),
                'identifier' => $row->getProjectIdentifier(),
                'name'       => $row->getProjectName(),
                'url'        => $this->client->projectUrl($ref),
            ],
        ];
    }

    /**
     * The link the data endpoints need: the row, with the stale check applied.
     * Throws when the team is not linked, or linked to another instance.
     *
     * @throws OpenProjectException
     */
    public function requireLink(string $teamId): TeamOpenProjectLink {
        if (!$this->isEligibleTeam($teamId)) {
            throw new OpenProjectException(OpenProjectException::PROJECT_NOT_FOUND, 'Team is not an OpenProject team');
        }
        $row = $this->mapper->findByTeam($teamId);
        if ($row === null) {
            throw new OpenProjectException(OpenProjectException::PROJECT_NOT_FOUND, 'Team is not linked to an OpenProject project');
        }
        if ($this->isStale($row)) {
            throw new OpenProjectException(OpenProjectException::LINK_STALE, 'Link was made against another OpenProject host');
        }
        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Write
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Everything a link refuses that can be known **before the team exists**
     * (v4.9.4): the template is the OpenProject one and names a project; the
     * project can be read as the creator; the creator administers it or it is
     * public; no other team links it. `TeamController::createTeam` runs this
     * before the circle is made, so a project that cannot be linked never
     * produces a team — the wizard returns to the picker with nothing to
     * clean up.
     *
     * @return array<string, mixed> the project, normalised
     * @throws ValidationException            not the OpenProject template, or no project named
     * @throws AccessDeniedException          not allowed to link this project
     * @throws OpenProjectException           the project cannot be read as this user
     * @throws ProjectAlreadyLinkedException  another team already links this project
     */
    public function assertLinkableForNewTeam(string $templateKey, int $projectId): array {
        if ($templateKey !== self::TEMPLATE) {
            throw new ValidationException('Only a team created from the OpenProject template can be linked to an OpenProject project');
        }
        if ($projectId <= 0) {
            throw new ValidationException('An OpenProject team needs its OpenProject project');
        }
        return $this->assertLinkable($this->currentUserId(), $projectId, '');
    }

    /**
     * The creation wizard's link (v4.9.4): type the new team as OpenProject
     * and link it, or leave no team behind. Called by `TeamController` right
     * after the circle exists and `assertLinkableForNewTeam()` has passed, so
     * failing here takes either the race `th_opl_proj_uq` catches or
     * OpenProject changing its answer between the two reads. Both delete the
     * team that was just created and rethrow: the wizard shows the reason and
     * returns the creator to the project picker, and no OpenProject team
     * without a project exists — there is no route that could repair one.
     *
     * @return array<string, mixed> the link, as `getLink()` returns it
     * @throws \Throwable whatever `link()` threw, after the rollback
     */
    public function linkNewTeam(string $teamId, int $projectId): array {
        try {
            $this->teamTypeService->setType($teamId, self::TEMPLATE);
            return $this->link($teamId, $projectId);
        } catch (\Throwable $e) {
            $this->rollBackNewTeam($teamId, $e);
            throw $e;
        }
    }

    /**
     * Link the team to `$projectId`. Once per team; see the class docblock.
     *
     * @return array<string, mixed> the link, as `getLink()` returns it
     * @throws AccessDeniedException          not a team admin, or not allowed to link this project
     * @throws ValidationException            bad id, wrong template, or the team is already linked
     * @throws OpenProjectException           the project cannot be read as this user
     * @throws ProjectAlreadyLinkedException  another team already links this project
     */
    public function link(string $teamId, int $projectId): array {
        $this->memberService->requireAdminLevel($teamId);
        $userId = $this->currentUserId();

        if ($projectId <= 0) {
            throw new ValidationException('Invalid project id');
        }
        if (!$this->isEligibleTeam($teamId)) {
            throw new ValidationException('Only a team created from the OpenProject template can be linked to an OpenProject project');
        }
        if ($this->mapper->findByTeam($teamId) !== null) {
            throw new ValidationException('This team is already linked to an OpenProject project');
        }

        $project = $this->assertLinkable($userId, $projectId, $teamId);
        $host    = $this->client->getHost();

        $now = time();
        $row = new TeamOpenProjectLink();
        $row->setTeamId($teamId);
        $row->setCreatedBy($userId);
        $row->setCreatedAt($now);
        $row->setProjectId($projectId);
        $row->setProjectIdentifier($project['identifier']);
        $row->setProjectName($project['name']);
        $row->setHost($host);
        $row->setUpdatedAt($now);
        $row->setLastValidatedAt($now);

        try {
            $row = $this->mapper->insert($row);
        } catch (\OCP\DB\Exception $e) {
            // `th_opl_proj_uq` — somebody linked the same project between the
            // check above and this insert. Same answer as the check.
            throw new ProjectAlreadyLinkedException($this->otherTeamsLinkedTo($projectId, $teamId), $e);
        }

        $this->cache->invalidateTeam($teamId);
        $this->auditService->log($teamId, 'openproject.linked', $userId, 'app', 'openproject', [
            'projectId'         => $projectId,
            'projectIdentifier' => $project['identifier'],
            'projectName'       => $project['name'],
            'host'              => $host,
        ]);

        return $this->toArray($row);
    }

    /**
     * A Nextcloud administrator removes a team's link, from Admin → TeamHub →
     * Maintenance (v4.9.4). The one route to unlinking: Manage team is
     * read-only by decision, and a link a team admin cannot move is a link
     * they cannot move by mistake. Gated on instance admin, not on team
     * membership — an administrator is in almost none of the teams they
     * maintain (HANDOFF: the `getTeam()` lesson). Idempotent. Nothing in
     * OpenProject changes; the project becomes linkable by a new team.
     * Audited on the team as `openproject.unlinked_by_admin`.
     *
     * @return ?array<string, mixed> the link that was removed, or null when there was none
     * @throws AccessDeniedException not a Nextcloud administrator
     */
    public function adminUnlink(string $teamId): ?array {
        $userId = $this->requireNcAdmin();

        $existing = $this->mapper->findByTeam($teamId);
        if ($existing === null) {
            return null;
        }
        $removed = $this->toArray($existing);
        $this->mapper->deleteByTeam($teamId);
        $this->cache->invalidateTeam($teamId);
        $this->auditService->log($teamId, 'openproject.unlinked_by_admin', $userId, 'app', 'openproject', [
            'projectId'         => $existing->getProjectId(),
            'projectIdentifier' => $existing->getProjectIdentifier(),
            'projectName'       => $existing->getProjectName(),
            'host'              => $existing->getHost(),
        ]);
        return $removed;
    }

    /**
     * Part of the team-delete cascade: no permission check (the caller has
     * already proven ownership by getting this far), no audit (the team's
     * `team.deleted` row is the record).
     */
    public function deleteForTeamCascade(string $teamId): void {
        try {
            $this->mapper->deleteByTeam($teamId);
            $this->newsMirror->deleteByTeam($teamId);
            $this->meetingSync->deleteByTeam($teamId);
            $this->cache->forgetTeam($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamOpenProjectLinkService] Cascade delete failed', [
                'teamId' => $teamId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * After a successful project read, refresh the display snapshot and the
     * validation time. Silent on failure — a stale name is cosmetic.
     *
     * @param array<string, mixed> $project a normalised project
     */
    public function recordSuccessfulRead(TeamOpenProjectLink $row, array $project): void {
        try {
            $changed = false;
            if (($project['identifier'] ?? '') !== '' && $project['identifier'] !== $row->getProjectIdentifier()) {
                $row->setProjectIdentifier((string)$project['identifier']);
                $changed = true;
            }
            if (($project['name'] ?? '') !== '' && $project['name'] !== $row->getProjectName()) {
                $row->setProjectName((string)$project['name']);
                $changed = true;
            }
            $row->setLastValidatedAt(time());
            if ($changed) {
                $row->setUpdatedAt(time());
            }
            $this->mapper->update($row);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TeamOpenProjectLinkService] recordSuccessfulRead skipped', [
                'teamId' => $row->getTeamId(), 'app' => Application::APP_ID,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Rules 2 and 3, as OpenProject and the link table answer them for one
     * user and one project: read as that user, administered or public, and
     * not linked by a team other than `$exceptTeamId`. Shared by `link()` and
     * `assertLinkableForNewTeam()` so the pre-creation check and the write
     * cannot drift apart.
     *
     * @return array<string, mixed> the project, normalised
     * @throws AccessDeniedException          not allowed to link this project
     * @throws OpenProjectException           the project cannot be read as this user
     * @throws ProjectAlreadyLinkedException  another team already links this project
     */
    private function assertLinkable(string $userId, int $projectId, string $exceptTeamId): array {
        // Authorisation by OpenProject: read as the linking user, and that
        // user must administer the project or the project must be public.
        $project = $this->projects->getProject($userId, $projectId);
        if (!$project['linkable']) {
            throw new AccessDeniedException('You must be an administrator of this project in OpenProject, or the project must be public');
        }
        $others = $this->otherTeamsLinkedTo($projectId, $exceptTeamId);
        if ($others !== []) {
            throw new ProjectAlreadyLinkedException($others);
        }
        return $project;
    }

    /**
     * Delete the team `linkNewTeam()` could not link. The creator is still
     * its owner at this point (the wizard hands over last), which is what
     * `TeamService::deleteTeam()` requires. A rollback that itself fails is
     * logged at error level and the original failure still goes up: the
     * team then shows in Maintenance without a link, where an administrator
     * can delete it.
     */
    private function rollBackNewTeam(string $teamId, \Throwable $cause): void {
        try {
            $this->container->get(TeamService::class)->deleteTeam($teamId);
            $this->logger->info('[TeamHub][TeamOpenProjectLinkService] Deleted a new team whose OpenProject link was refused', [
                'teamId' => $teamId, 'reason' => $cause->getMessage(), 'app' => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TeamOpenProjectLinkService] Could not delete the new team whose OpenProject link was refused', [
                'teamId' => $teamId, 'reason' => $cause->getMessage(), 'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * The links of the teams on one Maintenance page, keyed by team id, in
     * the grid's shape (v4.9.4). No membership check — the caller is the
     * NC-admin-gated maintenance read. A link on a team no longer of the
     * OpenProject template is still reported: it still holds the project.
     *
     * @param string[] $teamIds
     * @return array<string, array{projectId: int, projectIdentifier: string, projectName: string, host: string, stale: bool, url: ?string}>
     */
    public function linksForTeams(array $teamIds): array {
        $out = [];
        foreach ($this->mapper->findByTeams($teamIds) as $teamId => $row) {
            $ref = $row->getProjectIdentifier() !== '' ? $row->getProjectIdentifier() : (string)$row->getProjectId();
            $out[$teamId] = [
                'projectId'         => $row->getProjectId(),
                'projectIdentifier' => $row->getProjectIdentifier(),
                'projectName'       => $row->getProjectName(),
                'host'              => $row->getHost(),
                'stale'             => $this->isStale($row),
                'url'               => $this->client->projectUrl($ref),
            ];
        }
        return $out;
    }

    /**
     * Which of several projects are already linked, and by whom — the
     * picker's annotation (v4.9.4), so a taken project is shown as taken
     * before anybody builds a team around it. Same naming rule as
     * `otherTeamsLinkedTo()`: a team is named only to its own members.
     *
     * @param int[] $projectIds
     * @return array<int, list<array{teamId: string, name: ?string}>> keyed by project id; unlinked projects absent
     */
    public function linkedTeamsByProject(array $projectIds): array {
        $byProject = $this->mapper->findByProjects($projectIds);
        if ($byProject === []) {
            return [];
        }
        $ids = [];
        foreach ($byProject as $rows) {
            foreach ($rows as $row) {
                $ids[] = $row->getTeamId();
            }
        }
        $named = $this->nameTeamsForCurrentUser(array_values(array_unique($ids)));
        $out   = [];
        foreach ($byProject as $projectId => $rows) {
            foreach ($rows as $row) {
                $out[$projectId][] = ['teamId' => $row->getTeamId(), 'name' => $named[$row->getTeamId()] ?? null];
            }
        }
        return $out;
    }

    /**
     * Other teams linked to the same project — at most one, once
     * `th_opl_proj_uq` is in place. Names are included only for teams the
     * current user is a member of; the rest are counted, not named — a team
     * admin is not entitled to the names of teams they are not in.
     *
     * @return list<array{teamId: string, name: ?string}>
     */
    public function otherTeamsLinkedTo(int $projectId, string $exceptTeamId): array {
        $rows = array_values(array_filter(
            $this->mapper->findByProject($projectId),
            static fn (TeamOpenProjectLink $r): bool => $r->getTeamId() !== $exceptTeamId,
        ));
        if ($rows === []) {
            return [];
        }
        $ids   = array_map(static fn (TeamOpenProjectLink $r): string => $r->getTeamId(), $rows);
        $named = $this->nameTeamsForCurrentUser($ids);
        $out   = [];
        foreach ($ids as $teamId) {
            $out[] = ['teamId' => $teamId, 'name' => $named[$teamId] ?? null];
        }
        return $out;
    }

    /**
     * Team names, for the teams among `$teamIds` the current user is a
     * member of; the rest are absent. `PolicyObservationMapper::circlesByTeam`
     * rather than `TeamService::getTeam()`, which gates on membership and
     * would throw rather than answer (HANDOFF).
     *
     * @param string[] $teamIds
     * @return array<string, string>
     */
    private function nameTeamsForCurrentUser(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $circles = $this->circles->circlesByTeam($teamIds);
        $out     = [];
        foreach ($teamIds as $teamId) {
            try {
                $this->memberService->requireMemberLevel($teamId);
            } catch (\Throwable) {
                // Not a member: counted, not named.
                continue;
            }
            $name = $circles[$teamId]['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[$teamId] = $name;
            }
        }
        return $out;
    }

    /**
     * @throws AccessDeniedException
     */
    private function requireNcAdmin(): string {
        $userId = $this->currentUserId();
        if (!$this->groupManager->isAdmin($userId)) {
            throw new AccessDeniedException('Only a Nextcloud administrator can unlink an OpenProject project');
        }
        return $userId;
    }

    /** A link made against a host other than the one now configured. */
    public function isStale(TeamOpenProjectLink $row): bool {
        $host = $this->client->getHost();
        return $host !== '' && rtrim($row->getHost(), '/') !== $host;
    }

    /** @return array<string, mixed> */
    private function toArray(TeamOpenProjectLink $row): array {
        $ref = $row->getProjectIdentifier() !== '' ? $row->getProjectIdentifier() : (string)$row->getProjectId();
        return [
            'projectId'         => $row->getProjectId(),
            'projectIdentifier' => $row->getProjectIdentifier(),
            'projectName'       => $row->getProjectName(),
            'host'              => $row->getHost(),
            'stale'             => $this->isStale($row),
            'createdBy'         => $row->getCreatedBy(),
            'createdAt'         => $row->getCreatedAt(),
            'updatedAt'         => $row->getUpdatedAt(),
            'lastValidatedAt'   => $row->getLastValidatedAt(),
            'urls'              => [
                'project'      => $this->client->projectUrl($ref),
                'workPackages' => $this->client->workPackagesUrl($ref),
            ],
        ];
    }

    private function currentUserId(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('User not authenticated');
        }
        return $user->getUID();
    }
}
