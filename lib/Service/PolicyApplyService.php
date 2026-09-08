<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\PolicyField;
use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Db\PolicyObservationMapper;
use OCA\TeamHub\Db\TeamAppPresenceMapper;
use OCA\TeamHub\Db\TeamPolicyMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Applying a policy profile to a team that already exists (v4.8.16, Track F2b).
 *
 * `TRACK-F2-DESIGN.md` §4.3 is the specification. The short version, and it is
 * the decision the whole track turns on: **assignment is a write, not a label.**
 * An administrator who applies *Confidential* to a team that is visible to
 * everyone expects that team to stop being visible, and uniform rollout across an
 * existing estate is most of the value — unreachable if profiles only ever apply
 * to teams created after the feature shipped.
 *
 * ── Why this is its own service ──────────────────────────────────────────
 *
 * The obvious home was `PolicyService`, and it cannot go there. Step 3 of §4.3
 * requires the Circles-owned bits to be written through
 * `TeamService::updateTeamConfig()` rather than by a second raw `circles_circle`
 * writer — and `TeamService` already injects `PolicyService`, so injecting
 * `TeamService` back would close `TeamService → PolicyService → TeamService`.
 * That is not a lint failure, it is the whole app failing to construct on every
 * route: HANDOFF records v4.8.7 shipping exactly that shape.
 *
 * Nothing injects this class except its controller, so its own dependencies can
 * be as heavy as the job needs without a cycle being reachable. `npm run
 * check:di` is what proves that rather than this paragraph.
 *
 * ── What "override the current team settings" does and does not reach ────
 *
 * Every governed field is written **except `external_members`**, and that
 * exception is deliberate rather than unfinished. The other eight fields are
 * settings; that one is a statement about who is in the team, and enforcing it
 * would mean evicting people. Removing members is not a setting change, it is
 * destructive, and no part of the request asked for it. So a profile that forbids
 * external members, applied to a team that has some, leaves them in place and the
 * team is **reported as non-conformant immediately** — which is the honest
 * outcome and is exactly what the drift row and the Maintenance chip are for. The
 * preview says so before the administrator confirms, so a red chip straight after
 * an apply is never a surprise.
 */
class PolicyApplyService {

    /** Assignment source recorded on the row, distinct from `creation`. */
    private const SOURCE_ASSIGNMENT = 'assignment';

    public function __construct(
        private PolicyService           $policyService,
        private TeamService             $teamService,
        private MessageService          $messageService,
        // v4.8.24 — `confidential_tag`. Both are DI leaves; see PolicyService's
        // constructor for the same note and why it matters here.
        private ConfidentialFilesService $confidentialFiles,
        private GroupFolderService      $groupFolderService,
        private TeamPolicyMapper        $teamPolicyMapper,
        private PolicyObservationMapper $observationMapper,
        // v4.8.27 — see PolicyService's constructor. The preview's integration
        // reading has to be the scan's reading or the two disagree about the
        // same team, which is what "will this change anything" means.
        private TeamAppPresenceMapper $appPresenceMapper,
        private AuditService            $auditService,
        private IUserSession            $userSession,
        private IGroupManager           $groupManager,
        private LoggerInterface         $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Gate
    // -------------------------------------------------------------------------

    /**
     * Nextcloud administrator, returning their uid.
     *
     * The same idiom `PolicyService::requireNcAdmin()` and
     * `TeamImportService::requireNcAdmin()` use, copied rather than reached for
     * across a service boundary. HANDOFF §00sec's complaint is about six
     * *different* gate idioms making the security review unautomatable; a third
     * instance of one of them asks the identical question and adds no seventh.
     *
     * Applying a profile is an instance governance control: a team admin must
     * never be able to reclassify their own team, which is the bypass DESIGN
     * §2.103 records as the reason tags were removed.
     *
     * @throws AccessDeniedException
     */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Only a Nextcloud administrator can apply a policy profile.');
        }

        return $user->getUID();
    }

    // -------------------------------------------------------------------------
    // Preview
    // -------------------------------------------------------------------------

    /**
     * What applying `$profileKey` to `$teamId` would change (§4.3 step 1).
     *
     * **Writes nothing.** §4.3 makes the preview mandatory rather than optional:
     * "a silent bulk state change to somebody's teams is not acceptable even when
     * it is correct, and an admin assigning across an estate needs to see what
     * they are about to do."
     *
     * `changes` carries only fields that would actually differ. A governed field
     * the team already satisfies is in `unchanged` — worth showing, because it is
     * how an administrator sees the profile covers more than the three lines that
     * happen to differ today.
     *
     * @return array{
     *     teamId: string, teamName: string,
     *     profileKey: string, profileLabel: string, profileSeeded: bool,
     *     currentProfileKey: ?string, reassignment: bool,
     *     changes: list<array<string,mixed>>, unchanged: list<string>,
     *     governsNothing: bool
     * }
     * @throws AccessDeniedException|NotFoundException
     */
    public function preview(string $teamId, string $profileKey): array {
        $this->requireNcAdmin();

        return $this->buildPreview($teamId, $profileKey);
    }

    /**
     * The preview without the gate, for callers that have already established
     * who is asking.
     *
     * HANDOFF's 4.8.13 rule: a method that has already run its gate calls the
     * ungated reader. Two gates inside one call is not defence in depth — it is a
     * contradiction waiting for a second caller type.
     *
     * @throws NotFoundException
     */
    private function buildPreview(string $teamId, string $profileKey): array {
        $profile = $this->policyService->getProfileRow($profileKey);
        if ($profile === null) {
            throw new NotFoundException('No such policy profile.');
        }

        $circles = $this->observationMapper->circlesByTeam([$teamId]);
        if (!isset($circles[$teamId])) {
            throw new NotFoundException('No such team.');
        }
        $config = $circles[$teamId]['config'];

        $expected = $this->policyService->readableValuesFor($profileKey);
        $current  = $this->teamPolicyMapper->findByTeam($teamId);

        $changes   = [];
        $unchanged = [];

        // ── The six Circles config bits ──────────────────────────────────
        foreach (PolicyField::configBits() as $fieldKey => $bit) {
            if (!array_key_exists($fieldKey, $expected)) {
                continue;
            }
            $now  = ($config & $bit) !== 0;
            $next = (bool)$expected[$fieldKey];
            if ($now === $next) {
                $unchanged[] = $fieldKey;
                continue;
            }
            $changes[] = [
                'field'   => $fieldKey,
                'current' => $now,
                'next'    => $next,
                'applied' => true,
            ];
        }

        // ── External members — reported, never enforced. See the class
        //    docblock: enforcing it would evict people, which is not a setting
        //    change and was not asked for.
        if (array_key_exists(PolicyField::EXTERNAL_MEMBERS, $expected)) {
            $has  = isset($this->observationMapper->externalMemberTeams([$teamId])[$teamId]);
            $next = (bool)$expected[PolicyField::EXTERNAL_MEMBERS];
            if ($next === false && $has) {
                $changes[] = [
                    'field'   => PolicyField::EXTERNAL_MEMBERS,
                    'current' => true,
                    'next'    => false,
                    // The whole point of this flag. The UI must say that this
                    // one is not fixed by confirming, or the administrator will
                    // read the red chip afterwards as a bug.
                    'applied' => false,
                ];
            } else {
                $unchanged[] = PolicyField::EXTERNAL_MEMBERS;
            }
        }

        // ── Integrations — an allow-list, and the empty list is the field's
        //    own inert setting: it permits everything rather than nothing.
        $allowed = $expected[PolicyField::INTEGRATIONS_ALLOWED] ?? null;
        if (is_array($allowed) && $allowed !== []) {
            // v4.8.27 — both sides normalised before they are compared. The
            // allow-list is stored in whatever spelling the admin screen sent
            // (`wiki`, `pages`), the presence reader answers in the registry's
            // (`collectives`, `intravox`), and comparing them raw made every
            // team look like it was running an app the profile forbade.
            $allowed  = TeamApps::canonicalList($allowed);
            $enabled  = $this->appPresenceMapper->presenceForTeams([$teamId])[$teamId] ?? [];
            $disables = array_values(array_diff($enabled, $allowed));
            if ($disables !== []) {
                $changes[] = [
                    'field'    => PolicyField::INTEGRATIONS_ALLOWED,
                    'current'  => $enabled,
                    'next'     => $allowed,
                    'applied'  => true,
                    // Named separately from `next` because this is the
                    // destructive half and the preview has to show it as such:
                    // these apps get switched off on a team that is using them.
                    'disables' => $disables,
                ];
            } else {
                $unchanged[] = PolicyField::INTEGRATIONS_ALLOWED;
            }
        }

        // ── Public messages — the one enforced field, and ours alone to write.
        if (array_key_exists(PolicyField::PUBLIC_MESSAGES, $expected)) {
            $now  = $this->messageService->getAllowPublicMessages($teamId);
            $next = (bool)$expected[PolicyField::PUBLIC_MESSAGES];
            if ($now === $next) {
                $unchanged[] = PolicyField::PUBLIC_MESSAGES;
            } else {
                $changes[] = [
                    'field'    => PolicyField::PUBLIC_MESSAGES,
                    'current'  => $now,
                    'next'     => $next,
                    'applied'  => true,
                    'enforced' => true,
                ];
            }
        }

        // ── The team folder's classification tag (v4.8.24) ────────────────
        //
        // The one governed field whose target may not exist. A team with no
        // group folder has nowhere to carry a tag, and that is reported here as
        // not applicable rather than as a change that will happen — the
        // administrator is one click from applying, and finding out afterwards
        // that the classification went nowhere is the failure this preview
        // exists to prevent. The compliance scan takes the same case and reports
        // *nothing*, deliberately: informing somebody who is about to act is not
        // the same as flagging a team as non-conformant forever.
        $expectedTag = $expected[PolicyField::CONFIDENTIAL_TAG] ?? null;
        if (is_string($expectedTag) && $expectedTag !== '') {
            $rootId = $this->teamFolderRootId($teamId);
            // What the *outgoing profile* put there, not every tag on the
            // folder. Confidential files assigns its own by content and an
            // administrator may have tagged by hand; neither is a policy
            // classification and neither is ours to report or remove.
            //
            // **Computed here and carried in the change entry, never recomputed
            // during apply.** `apply()` writes the assignment row before any
            // setting — it has to, so `configOverlayForTeam()` reads the new
            // profile — so asking "what did the previous profile govern" after
            // that point returns the new profile's own tag and the old one is
            // never taken off. That is the same ordering trap 4.8.16 records for
            // the config overlay, one step further along.
            $superseded = $this->supersededTagIds($teamId, $profileKey);
            $currentTag = null;

            if ($rootId > 0) {
                $onFolder = $this->confidentialFiles->tagsForFiles([$rootId])[$rootId] ?? [];
                foreach ($superseded as $governed) {
                    if (in_array($governed, $onFolder, true)) {
                        $currentTag = $governed;
                        break;
                    }
                }
                if (in_array($expectedTag, $onFolder, true)) {
                    $currentTag = $expectedTag;
                }
            }

            if ($rootId <= 0) {
                $changes[] = [
                    'field'     => PolicyField::CONFIDENTIAL_TAG,
                    'current'   => null,
                    'next'      => $expectedTag,
                    'nextLabel' => $this->confidentialFiles->tagName($expectedTag),
                    'applied'   => false,
                    // Named so the panel can say *why* rather than rendering the
                    // same "not applied" chip external_members gets for an
                    // entirely different reason.
                    'reason'    => 'no_team_folder',
                ];
            } elseif ($currentTag === $expectedTag && $this->unmanagedTags($rootId, $expectedTag, $superseded) === []) {
                $unchanged[] = PolicyField::CONFIDENTIAL_TAG;
            } else {
                $changes[] = [
                    'field'        => PolicyField::CONFIDENTIAL_TAG,
                    'current'      => $currentTag,
                    'currentLabel' => $currentTag === null ? null : $this->confidentialFiles->tagName($currentTag),
                    'next'         => $expectedTag,
                    'nextLabel'    => $this->confidentialFiles->tagName($expectedTag),
                    'applied'      => true,
                    'rootId'       => $rootId,
                    'supersedes'   => $superseded,
                    // v4.8.28 — tags the folder carries that no profile governs
                    // and no classification label names. **Reported, never
                    // removed**: they are somebody's own tags and TeamHub has no
                    // basis for deciding they are classifications. But a tag
                    // sitting beside the one just applied is exactly what makes
                    // a classification not bite — an access rule keyed on it
                    // still matches — so leaving it unsaid is worse than either
                    // removing it or keeping it.
                    'otherTags'    => $this->unmanagedTags($rootId, $expectedTag, $superseded),
                ];
            }
        }

        return [
            'teamId'            => $teamId,
            'teamName'          => $circles[$teamId]['name'],
            'profileKey'        => $profileKey,
            'profileLabel'      => (string)$profile['label'],
            'profileSeeded'     => (bool)$profile['isSeeded'],
            'currentProfileKey' => $current['profileKey'] ?? null,
            'reassignment'      => $current !== null && $current['profileKey'] !== $profileKey,
            'changes'           => $changes,
            'unchanged'         => $unchanged,
            // A profile with no values governs nothing, so applying it classifies
            // the team and changes no setting. Legitimate, and worth saying out
            // loud on a screen whose whole job is to show what will change.
            'governsNothing'    => $expected === [],
        ];
    }

    // -------------------------------------------------------------------------
    // Apply
    // -------------------------------------------------------------------------

    /**
     * Assign the profile and write its values into the team (§4.3).
     *
     * **Order matters and is not arbitrary.** The assignment row is written
     * *first*, before any setting, because `TeamService::updateTeamConfig()`
     * overlays `PolicyService::configOverlayForTeam()` over whatever the caller
     * passes — so the row has to be in place for the overlay to be the new
     * profile's rather than the old one's or nothing at all. The same ordering
     * rule the bulk creator follows in `provisionRow()`, and for the same reason.
     *
     * The config write then passes the team's **current** config: every governed
     * bit is forced by the overlay, and every other managed bit is preserved
     * verbatim by `updateTeamConfig()`'s own mask. Nothing here needs to compute
     * a target bitmask, which is what keeps this off a second write path.
     *
     * @return array{applied: list<string>, notApplied: list<string>, preview: array<string,mixed>}
     * @throws AccessDeniedException|NotFoundException
     */
    public function apply(string $teamId, string $profileKey, bool $reapply = false): array {
        $actor   = $this->requireNcAdmin();
        $preview = $this->buildPreview($teamId, $profileKey);

        // 1. The assignment row, before any setting write.
        $this->teamPolicyMapper->assign($teamId, $profileKey, $actor, time(), self::SOURCE_ASSIGNMENT);

        $applied    = [];
        $notApplied = [];

        foreach ($preview['changes'] as $change) {
            if ($change['applied'] === false) {
                $notApplied[] = $change['field'];
                continue;
            }
            $applied[] = $change['field'];
        }

        // 2. Circles config bits, through the one writer that owns them.
        //    Called whenever the profile governs any bit at all, including when
        //    nothing differs: it is idempotent, and skipping it on a
        //    no-difference preview would make the write depend on a read taken
        //    moments earlier.
        if ($this->policyService->configOverlayForTeam($teamId)['mask'] !== 0) {
            $circles = $this->observationMapper->circlesByTeam([$teamId]);
            $this->teamService->updateTeamConfig($teamId, $circles[$teamId]['config'] ?? 0);
        }

        // 3. Public messages — enforced, and TeamHub is its only writer.
        $expected = $this->policyService->readableValuesFor($profileKey);
        if (array_key_exists(PolicyField::PUBLIC_MESSAGES, $expected)) {
            $this->messageService->setAllowPublicMessages(
                $teamId,
                (bool)$expected[PolicyField::PUBLIC_MESSAGES],
            );
        }

        // 4. Integrations outside the allow-list get switched off. The app's own
        //    data is untouched — a Deck board is not deleted, it stops being a
        //    team resource — which is what `enabled = 0` has always meant here.
        foreach ($preview['changes'] as $change) {
            if ($change['field'] !== PolicyField::INTEGRATIONS_ALLOWED) {
                continue;
            }
            // v4.8.27 — driven by the disable list, not by the rows that happen
            // to exist. This used to iterate `getTeamApps()` and switch off only
            // apps already carrying a row in `teamhub_team_apps` — a table
            // nothing writes for resource-backed apps, so the loop matched
            // nothing and **no integration was ever switched off**. The toggle
            // row is now written whether or not one existed; `upsert` inserts
            // when it does not, and an explicit `enabled = 0` is exactly what
            // the presence reader treats as "switched off" regardless of the
            // resource registry.
            $existing = [];
            foreach ($this->teamService->getTeamApps($teamId) as $row) {
                $existing[TeamApps::canonical((string)$row['app_id'])] = $row;
            }

            $rows = [];
            foreach ($change['disables'] as $appId) {
                $row = $existing[$appId] ?? null;
                $rows[] = [
                    'app_id'  => (string)$appId,
                    'enabled' => false,
                    // Preserved rather than nulled where a row exists: `upsert`
                    // writes this column unconditionally, so passing null would
                    // discard whatever the integration had stored there.
                    'config'  => ($row !== null && $row['config'] !== null) ? json_encode($row['config']) : null,
                ];
            }
            if ($rows !== []) {
                $this->teamService->updateTeamApps($teamId, $rows);
            }
        }

        // 5. The team folder's classification tag (v4.8.24). Uses the rootId and
        //    the superseded set computed in the preview above — see the note
        //    there for why recomputing either at this point reads the profile
        //    that step 1 has already written.
        //
        //    A failed tag write demotes the field from `applied` to
        //    `notApplied` rather than throwing. Every other setting has already
        //    been written by now, and unwinding them because a tag would not
        //    stick would trade a reported gap for a silent partial rollback. The
        //    scan reports the folder as missing its classification either way.
        foreach ($preview['changes'] as $change) {
            if ($change['field'] !== PolicyField::CONFIDENTIAL_TAG || $change['applied'] === false) {
                continue;
            }
            $ok = $this->confidentialFiles->applyTag(
                (int)($change['rootId'] ?? 0),
                (string)$change['next'],
                $change['supersedes'] ?? [],
            );
            if (!$ok) {
                $applied    = array_values(array_diff($applied, [PolicyField::CONFIDENTIAL_TAG]));
                $notApplied[] = PolicyField::CONFIDENTIAL_TAG;
            }
        }

        // 6. Audit. §8.1 — the evidence the rollout happened, and unlike
        //    scan-detected drift it has a real actor. `policy_reapplied` is a
        //    distinct event so a report can tell a first rollout from a
        //    remediation.
        $this->auditService->log(
            $teamId,
            $reapply ? 'team.policy_reapplied' : 'team.policy_applied',
            $actor,
            'policy',
            $profileKey,
            [
                'profileKey'        => $profileKey,
                'previousProfleKey' => $preview['currentProfileKey'],
                'changes'           => $preview['changes'],
                'applied'           => $applied,
                'notApplied'        => $notApplied,
            ],
        );

        $this->logger->info('[TeamHub][PolicyApplyService] policy applied', [
            'teamId'     => $teamId,
            'profileKey' => $profileKey,
            'applied'    => count($applied),
            'notApplied' => count($notApplied),
            'app'        => Application::APP_ID,
        ]);

        return [
            'applied'    => $applied,
            'notApplied' => $notApplied,
            'preview'    => $preview,
        ];
    }

    /**
     * Back to unclassified (§4.3, last paragraph).
     *
     * **Writes no setting, with one deliberate exception.** The team keeps every
     * value it has and simply stops being compared. Reverting on
     * declassification would be a second silent state change, and reverting to
     * *what* has no answer — the values before the profile was applied are not
     * recorded anywhere, and the team may have been created under it.
     *
     * The exception is `confidential_tag` (v4.8.24), and it is an exception
     * because the field is not like the others. Every other governed value is a
     * setting the team would still hold some version of if TeamHub had never
     * touched it; the classification tag exists *only* because a profile put it
     * there, and it stays load-bearing after declassification — a Files Access
     * Control rule keyed on that tag goes on restricting the folder of a team
     * nobody is classifying any more. Leaving it would mean the one field whose
     * effect outlives the policy is also the one field declassification does not
     * clear. Only the tag the cleared profile governed is removed, and only if
     * the folder is carrying it; anything else on the folder is untouched.
     *
     * @throws AccessDeniedException
     */
    public function clear(string $teamId): void {
        $actor    = $this->requireNcAdmin();
        $previous = $this->teamPolicyMapper->findByTeam($teamId);

        if ($previous === null) {
            // Already unclassified. Not an error — the caller wanted the team to
            // carry no profile and it does — but no audit row either, because
            // nothing happened.
            return;
        }

        // Read the tag before clearing the row: after it, there is no profile to
        // ask what was governed.
        $governedTag = $this->policyService->readableValuesFor($previous['profileKey'])[PolicyField::CONFIDENTIAL_TAG]
            ?? null;

        $this->teamPolicyMapper->clear($teamId);

        $tagRemoved = false;
        if (is_string($governedTag) && $governedTag !== '') {
            $rootId = $this->teamFolderRootId($teamId);
            if ($rootId > 0) {
                $tagRemoved = $this->confidentialFiles->removeTags($rootId, [$governedTag]);
            }
        }

        $this->auditService->log($teamId, 'team.policy_cleared', $actor, 'policy', $previous['profileKey'], [
            'previousProfileKey' => $previous['profileKey'],
            // Recorded because it is the one state change a clear makes. An
            // audit trail that showed declassification as a pure bookkeeping
            // event would not explain why a folder stopped matching a rule.
            'confidentialTagRemoved' => $tagRemoved ? $governedTag : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Team folder helpers (v4.8.24)
    // -------------------------------------------------------------------------

    /**
     * The fileid of the team's group folder root, or 0 when it has none.
     *
     * Goes through `GroupFolderService` rather than the observation mapper
     * because this is the single-team path and that class already owns the
     * availability check — the tables belong to GroupFolders and are absent on
     * an install without it.
     */
    private function teamFolderRootId(string $teamId): int {
        if (!$this->groupFolderService->isGroupFoldersAvailable()) {
            return 0;
        }

        $folder = $this->groupFolderService->findGroupFolderForCircle($teamId);

        return (int)($folder['root_id'] ?? 0);
    }

    /**
     * The classification tags the team's current profile governs, when that
     * profile is not the one being applied.
     *
     * Empty on a first assignment and on a re-apply of the same profile — in
     * both cases there is no earlier classification to supersede. The result
     * bounds what `ConfidentialFilesService::applyTag()` is allowed to remove,
     * which is what keeps a reassignment from stripping a tag TeamHub did not
     * put on the folder.
     *
     * @return list<string>
     */
    /**
     * Tags on the folder that the classification scheme does not account for.
     *
     * Everything the folder carries, minus the tag being applied and minus the
     * scheme's own set. On a healthy instance this is empty; when it is not, it
     * is the answer to "why is there still an Internal tag on this folder" —
     * because nothing has told TeamHub that Internal is a classification.
     * Governing it from a profile, or naming it from a Confidential files
     * label, is what moves it into `classificationTagIds()` and gets it
     * replaced on the next apply.
     *
     * @param list<string> $scheme
     * @return list<array{id: string, name: ?string}>
     */
    private function unmanagedTags(int $rootId, string $incoming, array $scheme): array {
        if ($rootId <= 0) {
            return [];
        }

        $onFolder = $this->confidentialFiles->tagsForFiles([$rootId])[$rootId] ?? [];
        $leftover = array_values(array_diff($onFolder, array_merge($scheme, [$incoming])));

        $out = [];
        foreach ($leftover as $tagId) {
            $out[] = ['id' => $tagId, 'name' => $this->confidentialFiles->tagName($tagId)];
        }

        return $out;
    }

    private function supersededTagIds(string $teamId, string $incomingProfileKey): array {
        // v4.8.28 — the whole classification set, not just the outgoing
        // profile's tag. Bounded by `classificationTagIds()`: a tag is part of
        // the scheme if a profile governs it or a Confidential files label names
        // it, and nothing else on the folder is touched.
        //
        // The narrower rule shipped in v4.8.24 and left a folder carrying two
        // classifications — Justin found `confidential` and `Internal` together
        // on the same team folder. Two is worse than the wrong one: an access
        // rule keyed on the weaker tag still matches, so the classification the
        // profile just applied does not actually narrow anything.
        //
        // `$teamId` and `$incomingProfileKey` are no longer read. Kept because
        // the caller's question is still "what does applying THIS profile to
        // THIS team supersede", and a set that stops depending on them is a
        // property of today's answer rather than of the question.
        return $this->policyService->classificationTagIds();
    }
}
