<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\SubTeamMapper;
use OCA\TeamHub\Db\TeamAppMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Sub-teams — a hand-picked selection of a main team's people, with its own
 * members, storage and modules, governed entirely from the main team
 * (v4.12.2, DESIGN §2.175).
 *
 * ## The parent link is this app's own row, never Circles' nesting
 *
 * Circles can hold one circle inside another (`circles_member.user_type = 16`)
 * and TeamHub already uses it for groups. Reaching for it here is the obvious
 * move and it is **exactly backwards**: in Circles a *contained* circle's
 * members become effective members of the **container**, through
 * `circles_membership`, which both `MemberService::isEffectiveMember()` and
 * `MemberService::requireMemberLevel()` honour.
 *
 * A sub-team may invite people from outside the main team. A row making the
 * sub-team a member of the main team would therefore hand every one of those
 * outsiders the main team's files, Talk room and calendar — silently, and
 * without appearing anywhere in the main team's member list as a person.
 *
 * **So no `circles_member` row ever links a parent and a child.** The
 * relationship lives in `teamhub_sub_team` and nowhere else. The Members tab's
 * attached-teams section is consequently empty of sub-teams, which is correct
 * and is commented as such where it renders.
 *
 * ## What governs a sub-team
 *
 * A sub-team has no administration of its own. Its members, name, visibility
 * and deletion are managed from the parent's Manage team, so the parent's
 * admins are the only administrators it has. Circles levels do not inherit —
 * every `require*Level()` gate reads a direct `circles_member` row — so that
 * authority is two explicit checks and nothing else:
 * `MemberService::requireAdminLevel()` / `requireModeratorLevel()` fall back
 * to the parent, and the three owner-only level reads consult
 * {@see isParentAdmin()}.
 *
 * ## Depth is one
 *
 * A sub-team cannot have sub-teams. That is {@see assertNotSubTeam()} and not
 * a Circles bit: `CFG_ROOT` constrains a circle as a *member* of another, not
 * as a *container*, so it cannot express this rule — and it is both a
 * `MANAGED_BIT` and a `PolicyField`, so forcing it on would fight a policy
 * profile.
 *
 * ## The module gate is on the three entry points, and nowhere else (v4.12.3)
 *
 * Sub-teams are a licensed module with an administrator's switch
 * ({@see SubTeamModuleService}, DESIGN §2.177). `requireAvailable()` opens
 * {@see listForParent()}, {@see candidates()} and {@see createSubTeam()} —
 * the three things that read or write the module's own surfaces.
 *
 * It deliberately does **not** open {@see isSubTeam()}, {@see parentOf()},
 * {@see isParentAdmin()}, {@see assertSubTeamAuthority()} or
 * {@see assertNotSubTeam()}. Those answer what a team *is*, not what a person
 * may see, and `MemberService`'s fall-back to a parent admin is built on the
 * same facts through `SubTeamMapper`. A sub-team whose module is switched off
 * is still a sub-team: its parent's admins are still its only
 * administrators, and it still may not be transferred, made a service desk,
 * filed into a sidebar group or bulk-imported into. Gating these would leave
 * an existing sub-team with no administrator at all and lift every one of its
 * exclusions — a security regression dressed as a feature switch.
 */
class SubTeamService {

    /** The visibility a sub-team is created with when the caller names none. */
    public const DEFAULT_VISIBILITY = SubTeamMapper::VIS_PRIVATE;

    /**
     * Separator between the parent's and the child's name in the storage
     * folder's name. An en dash with spaces, because the folder name is what a
     * person reads in Files and "Marketing - Design" is not it.
     *
     * Sub-team names are unique per parent rather than instance-wide, so two
     * parents may each have a "Design"; prefixing the folder is what keeps
     * those from colliding into "Design" and "Design (1)".
     */
    private const FOLDER_SEPARATOR = ' – ';

    public function __construct(
        private TeamService        $teamService,
        private MemberService      $memberService,
        private ResourceService    $resourceService,
        private PolicyService      $policyService,
        private TeamTypeService    $teamTypeService,
        private AuditService       $auditService,
        private SubTeamMapper      $subTeams,
        // v4.12.3 - the licence and the administrator's switch. A leaf
        // (IConfig + LicenseService), so injecting it here and into
        // TeamService creates no cycle.
        private SubTeamModuleService $subTeamModule,
        // v4.12.5 - the line under a person's name that says *which* person
        // this is, for both member pickers. A DI leaf, and the same service
        // every other picker in the app reads, so the administrator's
        // "Telling people apart" fields mean one thing everywhere.
        private PersonSublineService $sublines,
        private TeamTemplateMapper $templates,
        private TeamAppMapper      $teamApps,
        // v4.12.6 - changing the template or the policy of a sub-team that
        // already exists (DESIGN 2.180). The two services an administrator's
        // own rollout goes through, reached through their ungated twins:
        // `applyProfileToTeam()` and `applyTemplateToTeam()`, both of which
        // exist for a caller that has already established its authority.
        // Neither injects this class, so the graph stays acyclic - which
        // `npm run check:di` is what proves, not this comment.
        private PolicyApplyService $policyApply,
        private PolicyPropagationService $policyPropagation,
        // v4.12.9 - the sub-team's own expiry date, set from the panel
        // (DESIGN 2.183). Reached through setExpiryForSubTeam(), the ungated
        // twin, because the authority here is admin of the governing team.
        private TeamExpiryService $expiry,
        // Reads a circle's `config` **without** a membership gate, which
        // `TeamService::getTeam()` cannot: a parent admin who did not create
        // the sub-team has no row in it (v4.12.5), and `closeJoinBits()` was
        // reading through a door closed to exactly that person.
        private PolicyObservationMapper $circles,
        private IUserSession       $userSession,
        private IDBConnection      $db,
        private LoggerInterface    $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // The relationship
    // ------------------------------------------------------------------

    public function parentOf(string $teamId): ?string {
        return $this->subTeams->parentOf($teamId);
    }

    public function isSubTeam(string $teamId): bool {
        return $this->subTeams->isSubTeam($teamId);
    }

    /**
     * Refuse anything that must not happen to or from a sub-team.
     *
     * **Its only caller today is the depth rule** inside {@see createSubTeam()}
     * — a sub-team may not be a parent.
     *
     * DESIGN §2.175 names four more refusals, and **none of them calls this
     * method** — each tests `SubTeamMapper::isSubTeam()` where its own gate
     * already is, which is why they are listed here rather than being
     * discoverable from this method's callers:
     *
     *  - transferring ownership — `MemberService::requireOwnerLevel()`, before
     *    the level, because the sub-team's creator *is* its owner (v4.12.5);
     *  - becoming a service desk — `ServiceTeamService::refuseSubTeam()` on
     *    both entry points (v4.12.5), **and** the Service template, which is
     *    what actually sets `teamhub_team_type.type` and was the second door
     *    into the same room (`assertTemplateAllowed()`, v4.12.6);
     *  - CSV bulk import — `TeamImportService::resolveMemberToken()` (v4.12.5);
     *  - being filed into a personal sidebar group —
     *    `TeamGroupService::assignTeam()`, which has been real since 4.12.2.
     *
     * The paragraph this replaces said none of the first three was enforced
     * anywhere. That was true when it was written on 2026-10-12 and stopped
     * being true later the same day, in 4.12.5 — which is §2.179's own lesson
     * arriving from the other side: a comment describing the code is a claim
     * with no gate behind it, and it rots in both directions. If a fifth
     * refusal is added, add it to this list **and** to §2.175.
     *
     * @throws \InvalidArgumentException
     */
    public function assertNotSubTeam(string $teamId, string $because = ''): void {
        if (!$this->subTeams->isSubTeam($teamId)) {
            return;
        }
        throw new \InvalidArgumentException(
            $because !== '' ? $because : 'This is a sub-team, which does not support that action.',
        );
    }

    /**
     * Is `$userId` an admin (level >= 8) of the team that governs `$teamId`?
     *
     * The authority behind every owner-only action on a sub-team: deleting it,
     * archiving it, and producing its archive bundle. Those three sites read a
     * raw level and compare against 9, so widening
     * `MemberService::requireOwnerLevel()` would have been the wrong tool —
     * it also guards transferring ownership, which a sub-team refuses outright.
     *
     * One hop only. A sub-team cannot have sub-teams, so there is no chain.
     */
    public function isParentAdmin(string $teamId, string $userId): bool {
        $parentId = $this->subTeams->parentOf($teamId);
        if ($parentId === null || $userId === '') {
            return false;
        }
        return $this->memberService->getMemberLevelFromDb($this->db, $parentId, $userId) >= 8;
    }

    /**
     * @throws AccessDeniedException when the current user does not administer
     *         the team that governs `$subTeamId`.
     */
    public function assertSubTeamAuthority(string $subTeamId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        if (!$this->isParentAdmin($subTeamId, $user->getUID())) {
            throw new AccessDeniedException('Only an administrator of the main team can manage its sub-teams.');
        }
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * The sub-teams of one team, for the parent's Manage team → Sub-teams tab.
     *
     * Requires admin of the parent — this is the administrative view, and it
     * deliberately shows things the sub-team's own members do not see: every
     * sub-team including the private ones, and the creating admin in the
     * member count.
     *
     * @return list<array<string, mixed>>
     */
    public function listForParent(string $parentTeamId): array {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);

        $rows = $this->subTeams->childrenOf($parentTeamId);
        if ($rows === []) {
            return [];
        }

        // v4.12.6 — which template and which policy each one actually
        // carries, resolved for the whole list in three queries rather than
        // two per row. The panel's row shows both, because a choice made in a
        // dialog that then closes is a choice nobody can check (DESIGN §2.180).
        $childIds = array_values(array_map(static fn (array $r): string => (string)$r['team_id'], $rows));

        $classification = $this->policyService->labelledClassificationFor($childIds);
        $descriptions   = $this->descriptionsFor($childIds);
        // v4.12.9 - the date and whether the template allows one at all, both
        // batched. `eligibility` is the template's rule (Collaboration and
        // Project only), so the dialog can offer the field or say why not
        // rather than letting the server refuse a date somebody just typed.
        $expiry         = $this->expiry->getExpiryForTeams($childIds);
        $expiryEligible = $this->expiry->eligibilityForTeams($childIds);

        $out = [];
        foreach ($rows as $row) {
            $teamId = $row['team_id'];
            $class  = $classification[$teamId] ?? [];
            $out[] = [
                'id'          => $teamId,
                'name'        => $this->teamName($teamId),
                // v4.12.8 — so the manage dialog can show what it is for, and
                // change it. One query for the whole list, where the name
                // above still costs one per row; that read predates this and
                // is left alone rather than widened.
                'description' => $descriptions[$teamId] ?? '',
                'visibility'  => $row['visibility'],
                'members'     => $this->memberService->getEffectiveMemberCount($teamId, $this->db),
                // `templateKey` still answers what the type row holds, so it
                // is the same field 4.12.2 shipped — `labelledClassificationFor()`
                // reads it through `TeamTypeMapper::findTypesByTeams()`, the
                // batch twin of the `getType()` this line used to call once per
                // row. The label beside it is new, and falls back to the key
                // for a template an administrator has since removed.
                'templateKey'    => $class['templateKey'] ?? null,
                'templateLabel'  => $class['templateLabel'] ?? null,
                'templateSeeded' => $class['templateSeeded'] ?? false,
                // Null for an unclassified sub-team, which the row renders as
                // *No policy* rather than as a blank: the question it answers
                // is which one got applied, and "none" is an answer.
                'profileKey'     => $class['profileKey'] ?? null,
                'profileLabel'   => $class['profileLabel'] ?? null,
                'profileSeeded'  => $class['profileSeeded'] ?? false,
                // v4.12.9 - null when it has no date, which is the normal
                // case and not an omission.
                'expiresOn'       => $expiry[$teamId]['expiresOn'] ?? null,
                'expiryEligible'  => $expiryEligible[$teamId] ?? false,
                // Who made it, and therefore who owns it. Shown here and not
                // in the sub-team's own member list — see createSubTeam()'s
                // note on the creating admin.
                'createdBy'   => $row['created_by'],
                'createdAt'   => $row['created_at'],
            ];
        }
        return $out;
    }

    /**
     * The parent's roster, as candidates for a sub-team's member picker.
     *
     * Everyone who reaches the parent counts, directly or through a group, so
     * this is the effective member set rather than the direct rows: a person
     * who is in the main team through an LDAP group is in the main team.
     *
     * Each row carries its `subline` (v4.12.5). `getAllEffectiveMembers()`
     * does not add one — it is the members-widget shape, which has email,
     * phone and live status instead — so the two sub-team pickers were the
     * only person pickers in the app showing a bare display name, which is
     * exactly the "which Jari Feet is this" problem `PersonSublineService`
     * exists to answer (DESIGN §2.139). Resolved in one call for the whole
     * roster rather than per row.
     *
     * `adminContext: false` deliberately — a team admin is not a Nextcloud
     * administrator, so a profile field its owner marked private stays
     * private, the same rule the invite picker and the members widget apply.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(string $parentTeamId): array {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);

        $rows = $this->memberService->getAllEffectiveMembers($parentTeamId);

        $uids = [];
        foreach ($rows as $row) {
            $uid = (string)($row['userId'] ?? '');
            if ($uid !== '') {
                $uids[] = $uid;
            }
        }
        // Empty when the administrator configured no fields at all, which is a
        // real answer ("show nothing") and not a failure.
        $sublines = $this->sublines->sublinesFor($uids, false);

        foreach ($rows as $i => $row) {
            $uid = (string)($row['userId'] ?? '');
            $rows[$i]['subline'] = $uid === '' ? '' : ($sublines[$uid] ?? '');
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * Create a sub-team of `$parentTeamId`.
     *
     * @param array{
     *     name: string,
     *     description?: string,
     *     templateKey?: string,
     *     profileKey?: string,
     *     visibility?: string,
     *     members?: list<string>,
     *     outsiders?: list<array{id: string, type?: string}>,
     * } $spec
     *
     * @return array<string, mixed> the created team, as TeamService describes it
     */
    public function createSubTeam(string $parentTeamId, array $spec): array {
        // 0. The module. Before authentication even: whether sub-teams exist
        //    on this instance does not depend on who is asking.
        $this->subTeamModule->requireAvailable();

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new AccessDeniedException('User not authenticated');
        }
        $actor = $user->getUID();

        // 1. Authority — a **direct** admin row on the parent. Deliberately
        //    not requireAdminLevel(), whose v4.12.2 fallback would let a
        //    sub-team's administrator create a grandchild.
        $parentLevel = $this->memberService->getMemberLevelFromDb($this->db, $parentTeamId, $actor);
        if ($parentLevel < 8) {
            throw new AccessDeniedException('Only an administrator of the main team can create a sub-team.');
        }

        // 2. Depth. A sub-team cannot have sub-teams.
        $this->assertNotSubTeam(
            $parentTeamId,
            'A sub-team cannot have sub-teams of its own.',
        );

        $name        = trim((string)($spec['name'] ?? ''));
        $description = trim((string)($spec['description'] ?? ''));
        $templateKey = (string)($spec['templateKey'] ?? '');
        $profileKey  = (string)($spec['profileKey'] ?? '');
        $visibility  = (string)($spec['visibility'] ?? self::DEFAULT_VISIBILITY);
        if (!in_array($visibility, SubTeamMapper::VISIBILITIES, true)) {
            $visibility = self::DEFAULT_VISIBILITY;
        }

        // 3. The name rules. Shape is instance-wide; uniqueness is scoped to
        //    the siblings, and createTeam() applies that itself when it is
        //    handed a parent.
        $this->teamService->assertValidTeamName($name);

        // v4.12.6 — and the one template a sub-team may not have. Before
        // anything is created, because the alternative is a service desk
        // nobody can reach, built out of a refusal that was never enforced
        // here. See assertTemplateAllowed().
        $this->assertTemplateAllowed($templateKey);

        $parentName = $this->teamName($parentTeamId);

        // 4. Validate the two member lists against the product rule *before*
        //    creating anything, so a bad list does not leave a stray team
        //    behind. The frontend's picker is a convenience, not a boundary.
        [$members, $outsiders] = $this->partitionMembers(
            $parentTeamId,
            array_values(array_filter(array_map('strval', (array)($spec['members'] ?? [])))),
            (array)($spec['outsiders'] ?? []),
        );

        // 5. The circle, the registry row and the CFG_APP lock, through the
        //    one method every creation path reaches.
        $team   = $this->teamService->createTeam($name, $parentTeamId);
        $teamId = (string)($team['id'] ?? '');
        if ($teamId === '') {
            throw new \RuntimeException('Failed to create the sub-team.');
        }

        // 6. The parent link, immediately — before the policy, the type, the
        //    members or the storage. A crash between step 5 and here leaves an
        //    ordinary visible team an admin can find and delete; the other
        //    order would leave a row pointing at a circle that does not exist.
        $this->subTeams->insert($teamId, $parentTeamId, $visibility, $actor);

        // Everything past this point is best-effort and logged: the sub-team
        // exists and is governed, and losing it because a Deck board failed
        // would be the worse outcome. Same stance as the team wizard's own
        // provisioning.
        try {
            if ($description !== '') {
                $this->teamService->updateTeamDescription($teamId, $description);
            }

            // 7. Policy, then the config bits it governs.
            $governed = $this->policyService->assignAtCreation($teamId, $profileKey, $templateKey, $actor);
            if ($governed !== []) {
                $this->teamService->applyPolicyConfig($teamId);
            }

            // 8. The template label.
            if ($templateKey !== '') {
                $this->teamTypeService->setType($teamId, $templateKey);
            }

            // 9. Close the joining doors. A template's `preselect_config` may
            //    carry CFG_VISIBLE or CFG_OPEN, and either would make a
            //    hand-picked sub-team browsable or joinable by anyone — which
            //    is the one thing a sub-team must not be.
            $this->closeJoinBits($teamId);

            // 10. Members. The creating admin is already the owner, because
            //     Circles makes whoever creates a circle its owner and there
            //     is no service-account path. They are filtered out of the
            //     roster the sub-team's own members see (MemberService) and
            //     shown to the parent's admins in listForParent() — hidden
            //     from the people in the room, never from the accountable.
            if ($members !== [] || $outsiders !== []) {
                $this->memberService->inviteMembers($teamId, array_merge($members, $outsiders));
            }

            // 11. Storage and the rest of the template's apps. The mount point
            //     carries the parent's name; createTeamFolder() already makes
            //     a root-level space / group folder / shared folder, so
            //     "beside the parent, not inside it" needs no new code.
            $apps = $this->templateApps($templateKey);
            if ($apps !== []) {
                $this->resourceService->createTeamResources(
                    $teamId,
                    $apps,
                    $parentName . self::FOLDER_SEPARATOR . $name,
                );
            }

            // 12. The template's feature modules. A sub-team's modules come
            //     from its template and from nothing else (DESIGN §2.175) —
            //     there is deliberately no per-sub-team toggle in the UI.
            foreach ($this->templateModules($templateKey) as $moduleId) {
                $this->teamApps->upsert($teamId, $moduleId, true, null);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][SubTeamService] Sub-team created but provisioning failed', [
                'teamId'   => $teamId,
                'parentId' => $parentTeamId,
                'exception' => $e,
                'app'      => Application::APP_ID,
            ]);
        }

        // 13. Two audit lines. The child's own trail records its birth; the
        //     parent's records that its structure changed, which is the one an
        //     administrator reads when asking what this team is made of.
        $this->auditService->log($teamId, 'subteam.created', $actor, 'team', $teamId, [
            'name'   => $name,
            'parent' => $parentTeamId,
        ]);
        $this->auditService->log($parentTeamId, 'subteam.added', $actor, 'team', $teamId, [
            'name' => $name,
        ]);

        $team['is_sub_team']         = true;
        $team['parent_team_id']      = $parentTeamId;
        $team['sub_team_visibility'] = $visibility;
        $team['can_have_sub_teams']  = false;

        return $team;
    }

    // ------------------------------------------------------------------
    // Manage (Ship 2, v4.12.5)
    //
    // Every method here opens the same way: the module, then admin of the
    // **parent**, then {@see assertChildOf()}. The third is not ceremony. The
    // first two together say "you administer some main team", and without the
    // third an admin of team A could pass team B's sub-team id and manage a
    // team they have nothing to do with — the parent id in the path is the
    // authority, so it has to be proven to be *this* child's parent.
    // ------------------------------------------------------------------

    /**
     * One sub-team's roster, for the parent's Sub-teams tab.
     *
     * `getMembersForManage()` rather than `getAllEffectiveMembers()`: the
     * latter gates on `requireMemberLevel()`, which has no parent-admin
     * fall-back and would therefore refuse the very administrator this panel
     * is built for. The former gates on `requireAdminLevel()`, which does.
     *
     * Each person is marked `fromParent` — picked out of the main team — or
     * not, which is the only distinction the panel needs to make and the one
     * the add form is built around. It is computed from the parent's effective
     * roster read once, not per person.
     *
     * @return array<string, mixed>
     */
    public function members(string $parentTeamId, string $subTeamId): array {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $roster = $this->memberService->getMembersForManage($subTeamId);

        $onParent = [];
        foreach ($this->memberService->getAllEffectiveMembers($parentTeamId) as $person) {
            $uid = (string)($person['userId'] ?? '');
            if ($uid !== '') {
                $onParent[$uid] = true;
            }
        }

        // Sublines here as well as on candidates() (v4.12.5), so a person does
        // not read as "Jaap Tel · Finance" while you pick them and plain
        // "Jaap Tel" the moment they are in. Collected across both buckets and
        // resolved in one call.
        $uids = [];
        foreach (['direct', 'inherited'] as $bucket) {
            foreach ($roster[$bucket] ?? [] as $person) {
                $uid = (string)($person['userId'] ?? '');
                if ($uid !== '') {
                    $uids[] = $uid;
                }
            }
        }
        $sublines = $this->sublines->sublinesFor($uids, false);

        foreach (['direct', 'inherited'] as $bucket) {
            foreach ($roster[$bucket] ?? [] as $i => $person) {
                $uid = (string)($person['userId'] ?? '');
                $roster[$bucket][$i]['fromParent'] = $uid !== '' && isset($onParent[$uid]);
                $roster[$bucket][$i]['subline']    = $uid === '' ? '' : ($sublines[$uid] ?? '');
            }
        }

        return $roster;
    }

    /**
     * Add people to an existing sub-team.
     *
     * The same two-list rule creation applies, through the same
     * {@see partitionMembers()}: `members` are picked from the parent's
     * roster, `outsiders` are people who are not on it. A sub-team's
     * membership is the thing it exists to express, so the rule does not
     * loosen once the team is made.
     *
     * @param list<string> $members
     * @param array<int, array<string, mixed>> $outsiders
     * @return array<int|string, mixed> the per-entry results `inviteMembers()` returns
     */
    public function addMembers(string $parentTeamId, string $subTeamId, array $members, array $outsiders): array {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        [$picked, $guests] = $this->partitionMembers(
            $parentTeamId,
            array_values(array_filter(array_map('strval', $members))),
            $outsiders,
        );
        if ($picked === [] && $guests === []) {
            return [];
        }

        // inviteMembers() gates on requireModeratorLevel(), which falls back to
        // an admin of the parent (v4.12.2), so a parent admin with no row on
        // the sub-team reaches it. Circles' own addMember() is a different
        // matter — it authorises against the initiator's rights on the circle
        // — which is why the per-entry results are returned rather than
        // swallowed: a parent admin who is not on the sub-team may see entries
        // fail, and the panel has to be able to say so.
        $results = $this->memberService->inviteMembers($subTeamId, array_merge($picked, $guests));

        $this->auditService->log($subTeamId, 'subteam.members_added', $this->actorUid(), 'team', $subTeamId, [
            'members'   => count($picked),
            'outsiders' => count($guests),
        ]);

        return $results;
    }

    /**
     * Remove one person from a sub-team.
     *
     * Straight through `MemberService::removeMember()`, which is the point:
     * leaving a team has a tick list — the Talk/resource membership reconcile,
     * the message subscriptions, the outstanding file reviews — and a second
     * removal path would be a second place for that list to fall out of date.
     * It also refuses to remove an owner, which is what protects the admin who
     * created the sub-team from being removed out of their own team.
     */
    public function removeMember(string $parentTeamId, string $subTeamId, string $userId): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('No member was named.');
        }

        $this->memberService->removeMember($subTeamId, $userId, 1);

        $this->auditService->log($subTeamId, 'subteam.member_removed', $this->actorUid(), 'user', $userId, []);
    }

    /**
     * Rename a sub-team.
     *
     * Uniqueness is among its siblings, not across the instance — two parents
     * may each have a "Design" (DESIGN §2.175) — and the sub-team itself is
     * excluded from the sibling set so re-saving the same name is not a
     * duplicate.
     *
     * **The Files folder keeps the name it was created with.** It is
     * `{parent} – {child}`, and TeamHub cannot rename a team folder, a Talk
     * room, a Deck board or a collective; see
     * {@see TeamService::renameTeam()}. The dialog says so beside the field
     * rather than leaving it to be discovered.
     */
    public function rename(string $parentTeamId, string $subTeamId, string $name): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $trimmed = trim($name);
        $this->teamService->assertValidTeamName($trimmed);
        $this->teamService->assertSubTeamNameAvailable($parentTeamId, $trimmed, $subTeamId);

        // As in destroy(): the old name is the audit line's label, so a read
        // that fails must not be what stops a rename.
        $before = '';
        try {
            $before = $this->teamName($subTeamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SubTeamService] Could not read a sub-team name before renaming it', [
                'subTeamId' => $subTeamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        $this->teamService->renameTeam($subTeamId, $trimmed);

        // On the parent's trail too: "what is this team made of" is the
        // question an administrator reads that log to answer, and a child
        // changing its name is part of the answer. Same pairing as creation.
        $this->auditService->log($parentTeamId, 'subteam.renamed', $this->actorUid(), 'team', $subTeamId, [
            'changed' => ['name' => ['old' => $before, 'new' => $trimmed]],
        ]);
    }

    /**
     * Change who can see a sub-team under its parent.
     *
     * `private` adds nothing — `getUserTeams()`'s query is membership-gated,
     * so a sub-team the viewer is not in never arrives. `listed` is the only
     * value that *adds* rows, and only in `getSidebarTeams()`: a listed
     * sub-team shows its name to every parent member and stays unopenable,
     * because every team-scoped endpoint still runs `requireMemberLevel()`.
     * So this is one column and no gate (DESIGN §2.175).
     */
    public function setVisibility(string $parentTeamId, string $subTeamId, string $visibility): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        if (!in_array($visibility, SubTeamMapper::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('That is not a visibility a sub-team can have.');
        }

        $before = $this->subTeams->find($subTeamId)['visibility'] ?? '';
        if ($before === $visibility) {
            return;
        }

        $this->subTeams->setVisibility($subTeamId, $visibility);

        $this->auditService->log($parentTeamId, 'subteam.visibility_changed', $this->actorUid(), 'team', $subTeamId, [
            'changed' => ['visibility' => ['old' => $before, 'new' => $visibility]],
        ]);
    }

    /**
     * Change a sub-team's description (v4.12.8, DESIGN §2.182).
     *
     * It was set at creation and then unreachable: `ManageSubTeamModal` had no
     * field for it, and `TeamService::updateTeamDescription()` read a raw
     * `level >= 4` row on the sub-team itself, which a parent admin who did not
     * create it does not have. So the one person who could change it was its
     * author, through an endpoint nothing called. Both halves are fixed — that
     * gate goes through `requireModeratorLevel()` now, which accepts an admin
     * of the governing team.
     *
     * The audit line goes on the parent as well, as `rename()` does: "what is
     * this team made of" is the question an administrator reads that log to
     * answer, and `updateTeamDescription()` writes its own diff on the child.
     */
    public function setDescription(string $parentTeamId, string $subTeamId, string $description): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $this->teamService->updateTeamDescription($subTeamId, trim($description));

        $this->auditService->log($parentTeamId, 'subteam.description_changed', $this->actorUid(), 'team', $subTeamId, []);
    }

    /**
     * Set, change or clear a sub-team's expiry date (v4.12.9, DESIGN §2.183).
     *
     * §2.175 left open whether a sub-team may carry an expiry of its own and
     * recommended skipping it — *they die with the parent*. §2.181 answered
     * **yes** and needed no code, because every door already resolved: an
     * expiry is set by a Nextcloud administrator from Maintenance, and
     * `requestExtension()` already accepts an admin of the governing team.
     * This is the next step Justin asked for on the same day: the date is set
     * from the **Sub-teams panel**, by the parent's administrators, because a
     * sub-team has no Manage team of its own and routing its admins through a
     * Nextcloud administrator for the one field they cannot reach is the
     * situation §2.180 already rejected for the template and the policy.
     *
     * Eligibility is unchanged and is the template's, not ours:
     * `TeamExpiryService::isEligible()` refuses anything but Collaboration and
     * Project. Passing `null` or `''` clears the date, which is a change and
     * not an omission — the controller tests with `array_key_exists()`.
     *
     * @param string|null $expiresOn 'YYYY-MM-DD', or null to clear.
     * @throws AccessDeniedException|\InvalidArgumentException
     */
    public function setExpiry(string $parentTeamId, string $subTeamId, ?string $expiresOn): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $before = $this->expiry->getExpiry($subTeamId);

        // The ungated twin. The authority is the two lines above: admin of the
        // governing team, and this sub-team is one of its children.
        $this->expiry->setExpiryForSubTeam($subTeamId, $expiresOn, $this->actorUid());

        // On the parent's trail, like every other change made from the panel.
        // The child keeps `team.expiry_set` / `_extended` / `_cleared`, which
        // `TeamExpiryService` writes where every other expiry change writes it.
        $this->auditService->log($parentTeamId, 'subteam.expiry_changed', $this->actorUid(), 'team', $subTeamId, [
            'changed' => ['expiry' => [
                'old' => $before['expiresOn'] ?? null,
                'new' => $expiresOn === null || trim($expiresOn) === '' ? null : trim($expiresOn),
            ]],
        ]);
    }

    /**
     * Change the template, the policy profile, or both, on a sub-team that
     * already exists (v4.12.6, DESIGN §2.180).
     *
     * ## Why a team administrator may do this at all
     *
     * `PolicyApplyService::apply()` is Nextcloud-administrator-gated, and its
     * own docblock says a team admin must never reclassify their own team —
     * the bypass DESIGN §2.103 records as the reason tags were removed, and
     * the boundary §2.110 draws as "choosing at creation is the creator's,
     * changing it afterwards is an administrator's".
     *
     * **A sub-team is the exception, and Justin decided it on 2026-10-12.**
     * The reasoning, recorded in §2.180:
     *
     *  - The parent admin already picks both at creation, from the full set.
     *    Everything they reach here they could reach by deleting the sub-team
     *    and making it again, so this grants no authority they did not have —
     *    it saves them the deletion.
     *  - A sub-team has no Manage team of its own and **no module switch
     *    anywhere** (§2.175): its modules come from its template and from
     *    nothing else. Without this method, a template picked wrongly in a
     *    dialog is permanent.
     *  - What it costs: a Nextcloud administrator who reclassifies a sub-team
     *    can be overruled by its parent's admin. That is the price of the
     *    decision, §2.180 states it, and it is why the assignment row records
     *    `sub_team` as its source rather than `assignment`.
     *
     * ## Order, and what each half does
     *
     * The template first, because it is the half that provisions: the type row
     * is written before the apps are brought in line, so a rollout of the same
     * template afterwards finds this team carrying it — `propagateTemplate()`
     * skips a team whose type row says otherwise. Then the profile, which
     * writes the team settings through `PolicyApplyService` and nothing else:
     * `applyPolicyConfig()` is **not** called here, because `apply` already
     * writes the Circles bits through `TeamService::updateTeamConfig()`, the
     * one writer that owns them.
     *
     * `closeJoinBits()` runs last, for the reason it runs at creation: a
     * template's `preselect_config` may carry `CFG_VISIBLE` or `CFG_OPEN`, and
     * a sub-team must not be browsable or joinable. It does **not** win against
     * a profile that governs those bits — `updateTeamConfig()` overlays the
     * profile over every caller, deliberately — which is the open question
     * HANDOFF and DESIGN §3 both carry. Left open here rather than quietly
     * decided: this method reproduces creation's behaviour, whatever that
     * behaviour turns out to be.
     *
     * A template whose apps fail to provision does not throw. The label and the
     * assignment are already written and correct, and losing them because a
     * Deck board would not create is the worse outcome — the same stance
     * `createSubTeam()` takes past its step 6. The failure travels back in
     * `errors`, and the dialog shows it.
     *
     * @param string      $parentTeamId the governing team, which is the authority
     * @param string      $subTeamId    a child of it, or this refuses
     * @param string|null $templateKey  null or '' leaves the template alone
     * @param string|null $profileKey   null or '' leaves the policy alone
     *
     * @return array{
     *     templateKey: ?string, profileKey: ?string,
     *     added: list<string>, removed: list<string>,
     *     manual: list<string>, errors: list<string>
     * }
     * @throws AccessDeniedException|\InvalidArgumentException
     */
    public function setClassification(
        string $parentTeamId,
        string $subTeamId,
        ?string $templateKey = null,
        ?string $profileKey = null,
    ): array {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        $templateKey = $templateKey === null ? '' : trim($templateKey);
        $profileKey  = $profileKey  === null ? '' : trim($profileKey);

        // Both keys are validated before either is written, so a request
        // naming a real template and a profile that does not exist changes
        // nothing at all. A bad key is a 400 rather than a silent skip: it
        // arrives from a select whose options this instance served, so a key
        // that does not resolve means the client is wrong and should hear so.
        if ($templateKey !== '' && $this->policyService->getTemplateRow($templateKey) === null) {
            throw new \InvalidArgumentException('That is not a template on this instance.');
        }
        $this->assertTemplateAllowed($templateKey);
        if ($profileKey !== '' && !$this->policyService->profileExists($profileKey)) {
            throw new \InvalidArgumentException('That is not a policy profile on this instance.');
        }

        $current = $this->policyService->labelledClassificationFor([$subTeamId])[$subTeamId] ?? [];
        $actor   = $this->actorUid();

        $result = [
            'templateKey' => $current['templateKey'] ?? null,
            'profileKey'  => $current['profileKey'] ?? null,
            'added'       => [],
            'removed'     => [],
            'manual'      => [],
            'errors'      => [],
        ];
        $changed = [];

        if ($templateKey !== '' && $templateKey !== ($current['templateKey'] ?? null)) {
            $this->teamTypeService->setType($subTeamId, $templateKey);
            $changed['template']   = ['old' => $current['templateKey'] ?? null, 'new' => $templateKey];
            $result['templateKey'] = $templateKey;

            try {
                $apps = $this->policyPropagation->applyTemplateToTeam($subTeamId, $templateKey, $actor);
                $result['added']   = $apps['added']   ?? [];
                $result['removed'] = $apps['removed'] ?? [];
                // A team folder the new template no longer wants is reported,
                // never deleted (`DESTRUCTIVE_ON_REMOVE`), and so is an app
                // TeamHub did not create. The dialog says that in words rather
                // than leaving a list of app ids to interpret.
                $result['manual']  = $apps['manual']  ?? [];
                $result['errors']  = $apps['errors']  ?? [];
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][SubTeamService] Template changed but its apps did not follow', [
                    'subTeamId'   => $subTeamId,
                    'templateKey' => $templateKey,
                    'exception'   => $e,
                    'app'         => Application::APP_ID,
                ]);
                $result['errors'][] = $templateKey;
            }
        }

        if ($profileKey !== '' && $profileKey !== ($current['profileKey'] ?? null)) {
            $this->policyApply->applyProfileToTeam($subTeamId, $profileKey, $actor);
            $changed['policy']    = ['old' => $current['profileKey'] ?? null, 'new' => $profileKey];
            $result['profileKey'] = $profileKey;
        }

        if ($changed === []) {
            // Neither differed from what the sub-team already carries. Not an
            // error — it is what a dialog submitted with no edits sends — and
            // no audit line either, because nothing happened.
            return $result;
        }

        $this->closeJoinBits($subTeamId);

        // On the parent's trail, as every other structural change to a child
        // is: "what is this team made of" is the question an administrator
        // reads that log to answer. The child keeps its own `team.policy_*`
        // line, which `PolicyApplyService` writes where every other policy
        // application writes it.
        $this->auditService->log($parentTeamId, 'subteam.classification_changed', $actor, 'team', $subTeamId, [
            'changed' => $changed,
        ]);

        return $result;
    }

    /**
     * Delete a sub-team, with everything it holds.
     *
     * `TeamService::deleteTeam()` does the work and owns the cascade; it
     * accepts an admin of the governing team since v4.12.5, which is what lets
     * a parent admin who is not the sub-team's creator delete it. The link row
     * goes with it, there as well — this method adds authority and an audit
     * line, not a second destroy path.
     */
    public function destroy(string $parentTeamId, string $subTeamId): void {
        $this->subTeamModule->requireAvailable();
        $this->memberService->requireAdminLevel($parentTeamId);
        $this->assertChildOf($parentTeamId, $subTeamId);

        // A label for the audit line, and nothing more — so a read that fails
        // must not be what stops a team being deleted.
        $name = '';
        try {
            $name = $this->teamName($subTeamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SubTeamService] Could not read a sub-team name before deleting it', [
                'subTeamId' => $subTeamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }

        $this->teamService->deleteTeam($subTeamId);

        // On the parent only. The child's own audit rows are keyed on a team
        // that no longer exists and nothing will ever read them again, which
        // is exactly why the structural line belongs to the parent — the same
        // reason creation writes `subteam.added` there.
        $this->auditService->log($parentTeamId, 'subteam.removed', $this->actorUid(), 'team', $subTeamId, [
            'name' => $name,
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Refuse a template a sub-team may not have (v4.12.6).
     *
     * One template, and it is a permission boundary rather than a preference.
     * `teamhub_team_type.type === 'service'` is what **makes** a team a service
     * desk — `LayoutController` reveals the Services tab on it, and
     * `ServiceBuilderController` and `TeamServiceController` both gate on it —
     * and §2.175 refuses a sub-team becoming a desk outright: a desk answers
     * requests from the whole organisation, and a team most of it cannot see
     * is not that.
     *
     * `ServiceTeamService::refuseSubTeam()` guards its own two entry points,
     * but the type row is a **second door into the same room**, and it was
     * open: `createSubTeam()` has passed its `templateKey` to
     * `TeamTypeService::setType()` since 4.12.2 with no check, and the create
     * dialog offered `service` in the picker. Nothing has run it — 4.12.2 has
     * never been deployed, the bundle has been stale since 4.12.1 — so there
     * is no instance where a sub-team carries it. Closed here rather than only
     * in the new path, because a refusal with one door shut is not a refusal.
     *
     * @throws \InvalidArgumentException
     */
    private function assertTemplateAllowed(string $templateKey): void {
        if ($templateKey === 'service') {
            throw new \InvalidArgumentException(
                'A sub-team cannot be a service team. Create a service team of its own instead.',
            );
        }
    }

    /**
     * Refuse a sub-team that is not a child of `$parentTeamId`.
     *
     * The authority proven by the methods above is "admin of this main team".
     * Without this, that authority would reach any sub-team on the instance,
     * because the sub-team id arrives from the caller. A 404-shaped message
     * on purpose: an administrator of another team learns nothing about
     * whether the id they guessed exists.
     *
     * @throws \InvalidArgumentException
     */
    private function assertChildOf(string $parentTeamId, string $subTeamId): void {
        if ($subTeamId === '' || $this->subTeams->parentOf($subTeamId) !== $parentTeamId) {
            throw new \InvalidArgumentException('That sub-team does not belong to this team.');
        }
    }

    /** The acting user's uid, for the audit lines above. */
    private function actorUid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /**
     * Split the requested membership into parent members and outsiders, and
     * refuse a list that contradicts itself.
     *
     * The product rule is that `members` are picked from the parent's roster
     * and `outsiders` are people who are **not** on it. Enforced here, on the
     * server, because "the frontend only offers the right ones" is not a
     * boundary — and because a uid that silently changed category would make a
     * main team member arrive as a guest, or a stranger arrive as a colleague.
     *
     * @param list<string> $members
     * @param array<int, array<string, mixed>> $outsiders
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function partitionMembers(string $parentTeamId, array $members, array $outsiders): array {
        // Resolve the roster question for every uid involved, once each, then
        // hand the rule itself to the pure function below.
        $onRoster = [];
        foreach ($members as $uid) {
            if ($uid !== '' && !isset($onRoster[$uid])) {
                $onRoster[$uid] = $this->isOnParentRoster($parentTeamId, $uid);
            }
        }
        foreach ($outsiders as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ''));
            if ($id !== '' && (string)($entry['type'] ?? 'user') === 'user' && !isset($onRoster[$id])) {
                $onRoster[$id] = $this->isOnParentRoster($parentTeamId, $id);
            }
        }

        return self::splitMembership($members, $outsiders, $onRoster);
    }

    /**
     * The membership rule, with the roster lookups left outside so it can be
     * tested (v4.12.2). Pure: same inputs, same output, no I/O.
     *
     * `members` are picked from the parent's roster and `outsiders` are people
     * who are **not** on it. Enforced on the server because "the frontend only
     * offers the right ones" is not a boundary — and because a uid that
     * silently changed category would make a main team member arrive as a
     * guest, or a stranger arrive as a colleague.
     *
     * An email or federated invitee cannot be on a roster by definition, so
     * the outsider test applies only to the local-user type.
     *
     * @param list<string> $members
     * @param array<int, array<string, mixed>> $outsiders
     * @param array<string, bool> $onRoster  uid => is on the parent's roster
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     * @throws \InvalidArgumentException
     */
    public static function splitMembership(array $members, array $outsiders, array $onRoster): array {
        $picked = [];
        foreach (array_unique($members) as $uid) {
            $uid = (string)$uid;
            if ($uid === '') {
                continue;
            }
            if (($onRoster[$uid] ?? false) !== true) {
                throw new \InvalidArgumentException(
                    'Members of a sub-team are picked from the main team. Invite anyone else as a guest instead.',
                );
            }
            $picked[] = ['id' => $uid, 'type' => 'user'];
        }

        $guests = [];
        $seen   = [];
        foreach ($outsiders as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id   = trim((string)($entry['id'] ?? ''));
            $type = (string)($entry['type'] ?? 'user');
            if ($id === '' || isset($seen[$type . ':' . $id])) {
                continue;
            }
            if ($type === 'user' && ($onRoster[$id] ?? false) === true) {
                throw new \InvalidArgumentException(
                    'This person is already in the main team — add them as a member rather than a guest.',
                );
            }
            $seen[$type . ':' . $id] = true;
            $guests[] = ['id' => $id, 'type' => $type];
        }

        return [$picked, $guests];
    }

    /** Is this uid in the parent team, directly or through a group? */
    private function isOnParentRoster(string $parentTeamId, string $uid): bool {
        if ($this->memberService->getMemberLevelFromDb($this->db, $parentTeamId, $uid) > 0) {
            return true;
        }
        return $this->memberService->isEffectiveMember($parentTeamId, $uid, $this->db);
    }

    /**
     * Clear CFG_VISIBLE and CFG_OPEN, preserving every other bit.
     *
     * `updateTeamConfig()` masks to `MANAGED_BITS` and keeps the rest, so this
     * reads the current value and hands back the same thing minus two bits.
     * `browseAllTeams()` excludes sub-teams independently of this, so a
     * failure here is not a hole — but the bits themselves are what a user
     * would see on the Settings tab, and leaving them on would be a lie.
     *
     * **This is the trigger, not the rule** (v4.12.7, DESIGN §2.181). Since
     * the sub-team clamp went into `updateTeamConfig()` the two bits cannot
     * survive *any* write, including one a policy profile asserts — so what
     * this method still does is make a write happen at the two moments when
     * nothing else would: after creation, where a template's
     * `preselect_config` may have set them, and after a classification change.
     * Removing it would leave those bits standing until the next unrelated
     * settings save.
     */
    private function closeJoinBits(string $teamId): void {
        try {
            // v4.12.6 — read through `PolicyObservationMapper`, not
            // `TeamService::getTeam()`, which gates on `requireMemberLevel()`
            // and has **no** parent-admin fallback: only `requireAdminLevel()`
            // grew one in 4.12.2. At creation the caller is the sub-team's own
            // owner, so the gated read passed; `setClassification()` is reached
            // by any admin of the parent, and for one who did not create the
            // sub-team this read threw and the join bits were quietly left
            // open. The same reader `PolicyPropagationService` reaches for, and
            // the note in its constructor is the same note.
            $circle  = $this->circles->circlesByTeam([$teamId])[$teamId] ?? null;
            $config  = (int)($circle['config'] ?? 0);
            $cleared = $config & ~(CirclesConfig::CFG_VISIBLE | CirclesConfig::CFG_OPEN);
            if ($cleared !== $config) {
                $this->teamService->updateTeamConfig($teamId, $cleared);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SubTeamService] Could not clear the join bits on a sub-team', [
                'teamId'    => $teamId,
                'exception' => $e,
                'app'       => Application::APP_ID,
            ]);
        }
    }

    /** @return list<string> the provisionable apps a template asks for */
    private function templateApps(string $templateKey): array {
        if ($templateKey === '') {
            return [];
        }
        $row = $this->templates->find($templateKey);
        return $row === null ? [] : array_values((array)($row['apps'] ?? []));
    }

    /** @return list<string> the feature modules a template asks for */
    private function templateModules(string $templateKey): array {
        if ($templateKey === '') {
            return [];
        }
        $row = $this->templates->find($templateKey);
        return $row === null ? [] : array_values((array)($row['modules'] ?? []));
    }

    /**
     * A team's display name, or '' when the circle has gone.
     *
     * Reads the row directly rather than through `getTeam()`, which applies a
     * membership gate: this is called while acting with the parent admin's
     * authority over a team they may not be a member of.
     */
    /**
     * The descriptions of a set of teams, in one query (v4.12.8).
     *
     * Read here rather than through `TeamService::getTeam()`, which gates on
     * membership and would refuse every sub-team the reading administrator is
     * not in — the same trap `closeJoinBits()` fell into (DESIGN §2.180).
     * The caller has already required admin of the governing team.
     *
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function descriptionsFor(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'description')
            ->from('circles_circle')
            ->where($qb->expr()->in(
                'unique_id',
                $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY),
            ))
            ->executeQuery();

        $out = [];
        while ($row = $res->fetch()) {
            $out[(string)$row['unique_id']] = (string)($row['description'] ?? '');
        }
        $res->closeCursor();

        return $out;
    }

    private function teamName(string $teamId): string {
        if ($teamId === '') {
            return '';
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('name', 'display_name')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row === false) {
            return '';
        }
        // `display_name` is nullable with a '' default while `name` is NOT
        // NULL, so neither alone is safe to trust as *the* name.
        $name = trim((string)($row['display_name'] ?? ''));
        return $name !== '' ? $name : (string)$row['name'];
    }
}
