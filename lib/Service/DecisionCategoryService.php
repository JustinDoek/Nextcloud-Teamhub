<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionCategory;
use OCA\TeamHub\Db\DecisionCategoryApprover;
use OCA\TeamHub\Db\DecisionCategoryApproverMapper;
use OCA\TeamHub\Db\DecisionCategoryMapper;
use OCA\TeamHub\Db\DecisionMapper;
use OCA\TeamHub\Db\DecisionReviewMapper;
use OCA\TeamHub\Util\ReviewSchedule;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Per-team predefined Decisions categories and their approver lists.
 *
 * Category lifecycle:
 *  - createCategory()        — caller becomes created_by; team owner is auto-added
 *                              as the default approver. If the request specifies
 *                              an approver list, it replaces the default (but
 *                              must still be non-empty).
 *  - updateCategory()        — rename, change icon/description, or replace the
 *                              approver list (or any combination).
 *  - deleteCategory()        — removes the category and all its approver rows.
 *
 * Authorisation invariant:
 *  - All public methods require the caller to be a team admin (level >= 8) in
 *    the target team. Controllers must pre-check membership; this service
 *    additionally checks admin level before mutating.
 *
 * Empty-approver-list invariant:
 *  - A category never has zero approvers. If a caller submits an empty list,
 *    the team owner is substituted in.
 *  - **One exemption (v4.11.18): the archive category.** Nothing in it is
 *    ever approved — a decision arrives there already retired, and
 *    `is_archive` is what says so — so substituting an approver would name
 *    somebody as responsible for a queue that cannot exist.
 *
 * Seeded categories (v4.11.18):
 *  - `ensureSeedCategories()` runs when the Decisions module is switched on
 *    for a team, and again from the `SeedDecisionCategories` repair step for
 *    teams that had it on before this version. It is idempotent.
 *  - **General** — the team's admins and owner as approvers, review period
 *    0. An ordinary category otherwise: rename it, re-approve it, delete it.
 *  - **Archive** — `is_archive = 1`, no approvers, review period fixed at 0,
 *    and neither renamed nor deleted. The name is English for the same
 *    reason the team templates' labels are (`SeedTemplatesAndProfiles`): a
 *    category name is stored data read by every member of the team, not a
 *    per-viewer string, so there is one name and it is the one the seed
 *    writes.
 *
 * **Known, pre-existing, and worth stating here because the archive makes it
 * sharper:** `teamhub_decisions.category` holds a category *name*, so
 * renaming a category leaves its decisions pointing at a name no category
 * has, and `DecisionService::assertApproverFor` then falls through to
 * admin-only. That is why the archive category refuses a rename: its name is
 * also in `archived_from_category` on every row that passed through it.
 */
class DecisionCategoryService {

    /** v4.11.18 — the two categories a team gets with the module. */
    public const SEED_GENERAL = 'General';
    public const SEED_ARCHIVE = 'Archive';

    /**
     * v4.11.18 — a review period is in months and bounded. 120 months is ten
     * years: long enough for any policy a team would write down, short
     * enough that a typo cannot park a review past the lifetime of the
     * instance.
     */
    public const MAX_REVIEW_PERIOD_MONTHS = 120;

    public function __construct(
        private DecisionCategoryMapper          $categoryMapper,
        private DecisionCategoryApproverMapper  $approverMapper,
        // v4.11.18 — a change to a category's review period moves the due
        // date on every approved decision in it. Both are mappers and not
        // services on purpose: `DecisionService` depends on this class, so a
        // dependency back on it would be a cycle, and the arithmetic the two
        // share lives in `Util\ReviewSchedule` instead.
        private DecisionMapper                  $decisionMapper,
        private DecisionReviewMapper            $reviewMapper,
        private IDBConnection                   $db,
        private IUserManager                    $userManager,
        private \Psr\Container\ContainerInterface $container,
        private LoggerInterface                 $logger,
    ) {}

    // =========================================================================
    // Reads
    // =========================================================================

    /**
     * List all categories for a team, each with its current approver list.
     *
     * @return array<int, array{
     *     id: int,
     *     team_id: string,
     *     name: string,
     *     icon: ?string,
     *     description: ?string,
     *     created_by: string,
     *     created_at: int,
     *     updated_at: int,
     *     approvers: string[]
     * }>
     */
    public function listForTeam(string $teamId): array {
        $cats = $this->categoryMapper->findByTeam($teamId);
        if (!$cats) {
            return [];
        }

        $ids = array_map(fn(DecisionCategory $c) => $c->getId(), $cats);
        $approverMap = $this->approverMapper->findUserIdsByCategories($ids);

        $out = [];
        foreach ($cats as $c) {
            $out[] = $this->serialize($c, $approverMap[$c->getId()] ?? []);
        }
        return $out;
    }

    /**
     * Fetch one category by id (within a team) with its approver list.
     * Returns null if not found in this team.
     */
    public function getForTeam(string $teamId, int $categoryId): ?array {
        try {
            $cat = $this->categoryMapper->findByIdForTeam($categoryId, $teamId);
        } catch (DoesNotExistException) {
            return null;
        }
        $approvers = $this->approverMapper->findUserIdsByCategory($cat->getId());
        return $this->serialize($cat, $approvers);
    }

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * Create a new category for the team.
     *
     * @param string   $teamId       Team id (== circles_circle.unique_id).
     * @param string   $name         Category name (trimmed; 1–128 chars).
     * @param string   $createdBy    User id of the creating admin.
     * @param string[] $approvers    Optional approver list. Empty or null → team owner.
     * @param ?string  $icon         Optional emoji (≤8 bytes stored; validated).
     * @param ?string  $description  Optional description (≤500 chars).
     * @param int      $reviewPeriodMonths  v4.11.18 — 0 = no review.
     * @param bool     $isArchive    v4.11.18 — internal; only the seed sets it.
     *
     * @throws \InvalidArgumentException  on invalid name or duplicate
     */
    public function createCategory(
        string  $teamId,
        string  $name,
        string  $createdBy,
        ?array  $approvers = null,
        ?string $icon = null,
        ?string $description = null,
        int     $reviewPeriodMonths = 0,
        bool    $isArchive = false,
    ): array {
        $name = $this->validateName($name);

        if ($this->categoryMapper->existsByName($teamId, $name)) {
            throw new \InvalidArgumentException('Category with this name already exists');
        }

        $icon        = $this->sanitiseIcon($icon);
        $description = $this->sanitiseDescription($description);
        // The archive holds retired decisions; a review period on it would
        // schedule a review of a decision nobody can act on.
        $period      = $isArchive ? 0 : $this->validateReviewPeriod($reviewPeriodMonths);

        $now = time();
        $cat = new DecisionCategory();
        $cat->setTeamId($teamId);
        $cat->setName($name);
        $cat->setIcon($icon);
        $cat->setDescription($description);
        $cat->setCreatedBy($createdBy);
        $cat->setCreatedAt($now);
        $cat->setUpdatedAt($now);
        $cat->setReviewPeriodMonths($period);
        $cat->setIsArchive($isArchive ? 1 : 0);

        /** @var DecisionCategory $saved */
        $saved = $this->categoryMapper->insert($cat);

        // The archive is the one category with no approvers — see the class
        // docblock. Everything else gets the invariant.
        $resolved = $isArchive ? [] : $this->resolveApprovers($teamId, $approvers);
        $this->writeApprovers($saved->getId(), $resolved);

        $this->logger->info('[TeamHub][DecisionCategoryService] created category', [
            'teamId'     => $teamId,
            'categoryId' => $saved->getId(),
            'createdBy'  => $createdBy,
        ]);

        return $this->serialize($saved, $resolved);
    }

    /**
     * Update a category.
     *
     * icon and description use a tri-state:
     *   false  = do not touch the field (omitted from request)
     *   null   = clear the field (empty string sent by frontend)
     *   string = set to that value
     *
     * @param string[]         $approvers    Replacement approver list.
     * @param string|false|null $icon
     * @param string|false|null $description
     * @param ?int             $reviewPeriodMonths  v4.11.18 — null = leave it.
     *
     * @throws DoesNotExistException     when categoryId is not in team
     * @throws \InvalidArgumentException on validation failures
     */
    public function updateCategory(
        string $teamId,
        int    $categoryId,
        ?string $name = null,
        ?array  $approvers = null,
        mixed   $icon = false,
        mixed   $description = false,
        ?int    $reviewPeriodMonths = null,
    ): array {
        $cat = $this->categoryMapper->findByIdForTeam($categoryId, $teamId);

        // v4.11.18 — the archive category keeps its name, its empty approver
        // list and its 0 review period. Icon and description stay editable:
        // nothing stores them, so a team may describe its archive however it
        // likes. Refusing silently would be worse than refusing loudly —
        // a team admin who renames it and finds the name back tomorrow
        // learns nothing.
        if ($cat->isArchive()) {
            if ($name !== null && $name !== $cat->getName()) {
                throw new \InvalidArgumentException('The archive category cannot be renamed');
            }
            if ($approvers !== null && $approvers !== []) {
                throw new \InvalidArgumentException('The archive category has no approvers');
            }
            if ($reviewPeriodMonths !== null && $reviewPeriodMonths !== 0) {
                throw new \InvalidArgumentException('The archive category cannot have a review period');
            }
            $name               = null;
            $approvers          = null;
            $reviewPeriodMonths = null;
        }

        $changed = false;

        if ($name !== null) {
            $name = $this->validateName($name);
            if ($this->categoryMapper->existsByName($teamId, $name, $categoryId)) {
                throw new \InvalidArgumentException('Another category with this name already exists');
            }
            if ($cat->getName() !== $name) {
                $cat->setName($name);
                $changed = true;
            }
        }

        if ($icon !== false) {
            $cleanIcon = $this->sanitiseIcon(is_string($icon) ? $icon : null);
            if ($cat->getIcon() !== $cleanIcon) {
                $cat->setIcon($cleanIcon);
                $changed = true;
            }
        }

        if ($description !== false) {
            $cleanDesc = $this->sanitiseDescription(is_string($description) ? $description : null);
            if ($cat->getDescription() !== $cleanDesc) {
                $cat->setDescription($cleanDesc);
                $changed = true;
            }
        }

        // v4.11.18 — the review period, and the due dates that follow from
        // it. Recomputed before the row is saved so a failure leaves both
        // the category and its decisions on the old period rather than the
        // category on the new one and the decisions on the old.
        $periodChanged = false;
        if ($reviewPeriodMonths !== null) {
            $period = $this->validateReviewPeriod($reviewPeriodMonths);
            if ($cat->reviewPeriod() !== $period) {
                $this->applyReviewPeriod($teamId, $cat->getName(), $period);
                $cat->setReviewPeriodMonths($period);
                $changed       = true;
                $periodChanged = true;
            }
        }

        if ($changed) {
            $cat->setUpdatedAt(time());
            $this->categoryMapper->update($cat);
        }

        if ($approvers !== null) {
            $resolved = $this->resolveApprovers($teamId, $approvers);
            $this->writeApprovers($categoryId, $resolved);
            $currentApprovers = $resolved;
        } else {
            $currentApprovers = $this->approverMapper->findUserIdsByCategory($categoryId);
        }

        $this->logger->info('[TeamHub][DecisionCategoryService] updated category', [
            'teamId'        => $teamId,
            'categoryId'    => $categoryId,
            'changed'       => $changed,
            'periodChanged' => $periodChanged,
        ]);

        return $this->serialize($cat, $currentApprovers);
    }

    /**
     * Delete a category and its approver rows.
     *
     * @throws DoesNotExistException  when categoryId is not in team
     */
    public function deleteCategory(string $teamId, int $categoryId): void {
        $cat = $this->categoryMapper->findByIdForTeam($categoryId, $teamId);

        // v4.11.18 — deleting the archive would leave every retired decision
        // pointing at a category that no longer exists, and the next
        // supersede with nowhere to put its decision.
        if ($cat->isArchive()) {
            throw new \InvalidArgumentException('The archive category cannot be deleted');
        }

        $this->approverMapper->deleteAllForCategory($categoryId);
        $this->categoryMapper->delete($cat);

        $this->logger->info('[TeamHub][DecisionCategoryService] deleted category', [
            'teamId'     => $teamId,
            'categoryId' => $categoryId,
        ]);
    }

    // =========================================================================
    // Seeding (v4.11.18)
    // =========================================================================

    /**
     * Give a team the two categories that come with the Decisions module.
     *
     * Called from `DecisionTeamService::saveConfig()` when the module is
     * switched on, and from the `SeedDecisionCategories` repair step for the
     * teams that had it on before this version.
     *
     * **Idempotent, and never undoes an edit.** Each category is created
     * only when the team has none with that name — so a team that renamed
     * General, gave it different approvers or deleted it does not find it
     * back. Archive is keyed on the flag rather than the name, because the
     * flag is its identity.
     *
     * **General is seeded only into a team with no categories at all.** A
     * team that already organises its decisions has said what its categories
     * are, and adding a *General* tile to its landing grid would be this
     * version editing somebody's taxonomy. Archive goes to every team,
     * because the archive model requires it.
     *
     * Best-effort per category: a failure is logged and the other category
     * is still attempted. Switching the module on must not fail because a
     * seed did.
     *
     * @param string $createdBy  The acting admin, or '' from the repair step
     *                           (no session). Display-only on the row.
     * **A failure is reported, not just logged** (v4.11.22). It used to
     * return null for "already there" and null for "it blew up", so the
     * repair step counted both as nothing-to-do and printed *0 created, 0
     * skipped* while failing on all 54 teams of an instance. A count that
     * cannot distinguish success from failure is not a count.
     *
     * @return array{general: ?array<string,mixed>, archive: ?array<string,mixed>, failed: string[]}
     *         the categories created by *this* call; null where one already
     *         existed **or** could not be created, with the reason for each
     *         failure in `failed`.
     */
    public function ensureSeedCategories(string $teamId, string $createdBy = ''): array {
        $existing = $this->categoryMapper->findByTeam($teamId);

        $out = ['general' => null, 'archive' => null, 'failed' => []];

        $hasArchive = false;
        foreach ($existing as $cat) {
            if ($cat->isArchive()) {
                $hasArchive = true;
                break;
            }
        }

        if ($existing === []) {
            try {
                $out['general'] = $this->createCategory(
                    $teamId,
                    self::SEED_GENERAL,
                    $createdBy,
                    $this->findTeamAdminUids($teamId),
                    'Tag',
                    null,
                    0,
                    false,
                );
            } catch (\Throwable $e) {
                $out['failed'][] = self::SEED_GENERAL . ': ' . $e->getMessage();
                $this->logger->warning('[TeamHub][DecisionCategoryService] seeding General failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$hasArchive && !$this->categoryMapper->existsByName($teamId, self::SEED_ARCHIVE)) {
            try {
                $out['archive'] = $this->createCategory(
                    $teamId,
                    self::SEED_ARCHIVE,
                    $createdBy,
                    [],
                    'ArchiveOutline',
                    null,
                    0,
                    true,
                );
            } catch (\Throwable $e) {
                $out['failed'][] = self::SEED_ARCHIVE . ': ' . $e->getMessage();
                $this->logger->warning('[TeamHub][DecisionCategoryService] seeding Archive failed', [
                    'teamId' => $teamId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $out;
    }

    /**
     * The team's archive category, or null when it has none.
     *
     * Null is a real answer and callers must handle it: a team whose module
     * was enabled before v4.11.18 has no archive until the repair step runs,
     * and a seed is best-effort. Retiring a decision in a team without one
     * refuses rather than inventing a category mid-write.
     */
    public function archiveCategory(string $teamId): ?DecisionCategory {
        foreach ($this->categoryMapper->findByTeam($teamId) as $cat) {
            if ($cat->isArchive()) {
                return $cat;
            }
        }
        return null;
    }

    /**
     * Does this category name belong to the team's archive? (v4.11.30)
     *
     * Justin, 2026-10-05: *we don't want a proposal to be made in the
     * archive category — this category should be for archival only.* The
     * archive holds decisions that have stopped governing; a proposal
     * created there would be born retired, in a category with no approvers
     * and no review period, so nobody could ever approve it. The composer
     * hides it and `DecisionService::propose()` refuses it, and this is the
     * one place that decides which name is the archive's.
     *
     * **Compared case-insensitively**, unlike `assertApproverFor()`, which
     * matches the stored case. The difference is deliberate: category names
     * are unique case-insensitively (`existsByName()` lowercases both
     * sides), so a request naming `archive` means the row called `Archive`
     * and nothing else. A guard that let `archive` through on a casing
     * technicality would be a guard in name only — and here, refusing too
     * much costs a 400 on a category the UI never offers, while refusing too
     * little costs an unapprovable decision in the archive.
     */
    public function isArchiveName(string $teamId, ?string $name): bool {
        $name = trim((string)$name);
        if ($name === '') {
            return false;
        }
        $archive = $this->archiveCategory($teamId);
        if ($archive === null) {
            return false;
        }
        return mb_strtolower($archive->getName()) === mb_strtolower($name);
    }

    /**
     * The review period of the category a decision was decided under, in
     * months. 0 when the category asks for no review, has been renamed away
     * from under the decision, or never existed (a legacy free-text
     * category) — in all three cases the honest answer is "nobody has asked
     * for this to be reviewed".
     */
    public function reviewPeriodForCategory(string $teamId, ?string $categoryName): int {
        $categoryName = trim((string)$categoryName);
        if ($categoryName === '') {
            return 0;
        }
        foreach ($this->categoryMapper->findByTeam($teamId) as $cat) {
            if ($cat->getName() === $categoryName) {
                return $cat->reviewPeriod();
            }
        }
        return 0;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * v4.11.18 — recompute `review_due_at` on every approved decision of one
     * category after its review period changed.
     *
     * The anchor is the decision's own clock: the completion of its last
     * review if it has had one, otherwise the moment it was approved. So
     * shortening a period does not punish a decision that was reviewed last
     * month, and lengthening one does not hide a decision that is already
     * overdue — `ReviewSchedule::dueAt` returns a date in the past and the
     * daily pass picks it up on its next run.
     *
     * `review_notified_at` is cleared alongside: the stored value refers to
     * a cycle that no longer exists, and leaving it would suppress the
     * notice for the new one.
     *
     * Writes through the mapper one row at a time rather than one UPDATE,
     * because each row's due date is computed from its own anchor. Capped by
     * the mapper's own limit; a category with more approved decisions than
     * that gets the rest on the next change, and the daily pass is what
     * actually produces the work either way.
     */
    private function applyReviewPeriod(string $teamId, string $categoryName, int $months): void {
        $decisions = $this->decisionMapper->findApprovedByTeamCategory($teamId, $categoryName);
        if ($decisions === []) {
            return;
        }

        $ids       = array_map(static fn ($d): int => (int)$d->getId(), $decisions);
        $lastReview = $this->reviewMapper->lastCompletedAtByDecisions($ids);

        $touched = 0;
        foreach ($decisions as $decision) {
            $anchor = max(
                (int)($decision->getResolvedAt() ?? $decision->getDecidedAt() ?? $decision->getCreatedAt()),
                (int)($lastReview[(int)$decision->getId()] ?? 0),
            );
            $due = ReviewSchedule::dueAt($anchor, $months);

            if ($decision->getReviewDueAt() === $due) {
                continue;
            }
            $decision->setReviewDueAt($due);
            $decision->setReviewNotifiedAt(null);
            $this->decisionMapper->update($decision);
            $touched++;
        }

        $this->logger->info('[TeamHub][DecisionCategoryService] applied review period', [
            'teamId' => $teamId, 'category' => $categoryName,
            'months' => $months, 'touched' => $touched,
        ]);
    }

    /**
     * v4.11.18 — months, 0–120. Rejects rather than clamps: a team admin who
     * typed 1200 meant something, and silently storing 120 would be this
     * code deciding what.
     */
    private function validateReviewPeriod(int $months): int {
        if ($months < 0 || $months > self::MAX_REVIEW_PERIOD_MONTHS) {
            throw new \InvalidArgumentException(
                'review_period_months must be between 0 and ' . self::MAX_REVIEW_PERIOD_MONTHS,
            );
        }
        return $months;
    }

    private function validateName(string $name): string {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Category name cannot be empty');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Category name is too long (max 128 characters)');
        }
        return $name;
    }

    /**
     * Validate an MDI icon name.
     * Must be a PascalCase alphanumeric string matching one of the allowed icons
     * (e.g. 'Briefcase', 'Cog', 'AccountGroup'). Max 64 chars.
     * Null/empty → null (no icon). Unknown names are rejected to prevent
     * arbitrary string injection into the icon field.
     */
    private function sanitiseIcon(?string $icon): ?string {
        if ($icon === null || trim($icon) === '') {
            return null;
        }
        $icon = trim($icon);
        // Allow only PascalCase alphanumeric (letters and digits, starts with uppercase).
        // This matches vue-material-design-icons component names.
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $icon)) {
            throw new \InvalidArgumentException('icon must be a valid MDI component name (e.g. Briefcase, Cog)');
        }
        return $icon;
    }

    /**
     * Sanitise a description value.
     * Null/empty → null. Non-null: trim, cap at 500 chars.
     */
    private function sanitiseDescription(?string $description): ?string {
        if ($description === null || trim($description) === '') {
            return null;
        }
        $description = trim($description);
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Category description is too long (max 500 characters)');
        }
        return $description;
    }

    /**
     * Build the final approver list.
     * @param string[]|null $requested
     * @return string[] non-empty array of user ids
     */
    private function resolveApprovers(string $teamId, ?array $requested): array {
        $clean = [];
        foreach ($requested ?? [] as $uid) {
            $uid = (string)$uid;
            if ($uid === '') continue;
            $user = $this->userManager->get($uid);
            if ($user !== null && !in_array($uid, $clean, true)) {
                $clean[] = $uid;
            }
        }

        if ($clean) {
            return $clean;
        }

        $owner = $this->findTeamOwnerUid($teamId);
        if ($owner !== null) {
            return [$owner];
        }

        throw new \RuntimeException('Cannot determine team owner; category has no approvers');
    }

    /**
     * v4.11.18 — the team's admins and owner, as the seeded General
     * category's approvers.
     *
     * **A snapshot of user ids, not the role.** `teamhub_dec_cat_apprs`
     * stores uids, so somebody promoted to team admin next month is not
     * automatically an approver of General and somebody demoted stays one.
     * That is how every category already works, and the list is editable
     * like any other — it is stated here because "the team admins" sounds
     * like a role and is not one.
     *
     * Same resolution path as `findTeamOwnerUid()`: CirclesManager first,
     * then a direct SELECT, because this also runs from a repair step with
     * no session. Levels 8 (admin) and 9 (owner) on the Circles scale, and
     * `status = 'Member'` — a pending invitation is not an admin, which is
     * the distinction `MemberService::getMemberLevelFromDb` exists to make
     * (v4.5.25).
     *
     * @return string[] may be empty; the caller's `resolveApprovers()` then
     *                  falls back to the owner, which is the right answer
     *                  for a team whose admin list cannot be read.
     */
    private function findTeamAdminUids(string $teamId): array {
        try {
            $circlesMgr = $this->container->get(\OCA\Circles\CirclesManager::class);
            $circle     = $circlesMgr->getCircle($teamId);
            $uids       = [];
            foreach ($circle->getMembers() as $member) {
                if ($member->getLevel() >= 8) {
                    $uid = $member->getUserId();
                    if (is_string($uid) && $uid !== '' && !in_array($uid, $uids, true)) {
                        $uids[] = $uid;
                    }
                }
            }
            if ($uids !== []) {
                return $uids;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][DecisionCategoryService] findTeamAdminUids: CirclesManager path unavailable — using DB fallback', [
                'teamId' => $teamId, 'reason' => $e->getMessage(),
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('level', $qb->createNamedParameter(8, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')));

        $result = $qb->executeQuery();
        $uids   = [];
        while ($row = $result->fetch()) {
            $uid = (string)$row['user_id'];
            if ($uid !== '' && !in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }
        $result->closeCursor();
        return $uids;
    }

    private function findTeamOwnerUid(string $teamId): ?string {
        // v3.100.8 (apps.md R-1) — resolve via CirclesManager first;
        // fall back to a direct SELECT when the API is unavailable
        // (background job with no session, hidden circle, missing Circles).
        try {
            $circlesMgr = $this->container->get(\OCA\Circles\CirclesManager::class);
            $circle = $circlesMgr->getCircle($teamId);
            $owner = $circle->getOwner();
            $uid = $owner?->getUserId();
            if (is_string($uid) && $uid !== '') {
                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][DecisionCategoryService] findTeamOwnerUid: CirclesManager path unavailable — using DB fallback', [
                'teamId' => $teamId, 'reason' => $e->getMessage(),
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('level',     $qb->createNamedParameter(9, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return $row !== false ? (string)$row['user_id'] : null;
    }

    /**
     * @param string[] $userIds
     */
    private function writeApprovers(int $categoryId, array $userIds): void {
        $this->approverMapper->deleteAllForCategory($categoryId);
        $now = time();
        foreach ($userIds as $uid) {
            $row = new DecisionCategoryApprover();
            $row->setCategoryId($categoryId);
            $row->setUserId($uid);
            $row->setCreatedAt($now);
            $this->approverMapper->insert($row);
        }
    }

    private function serialize(DecisionCategory $c, array $approvers): array {
        return [
            'id'          => $c->getId(),
            'team_id'     => $c->getTeamId(),
            'name'        => $c->getName(),
            'icon'        => $c->getIcon(),
            'description' => $c->getDescription(),
            // v4.11.22 — normalised: a seeded category has no author, and
            // the API should say '' rather than null for a string field.
            'created_by'  => $c->createdBy(),
            'created_at'  => $c->getCreatedAt(),
            'updated_at'  => $c->getUpdatedAt(),
            'approvers'   => array_values($approvers),
            // v4.11.18. `is_archive` carries the whole lock: the form reads
            // it to disable the name, the approver picker and the review
            // period, and the landing grid reads it to sort the tile last.
            // One flag rather than three `locked_*` keys, because they can
            // never disagree.
            'review_period_months' => $c->reviewPeriod(),
            'is_archive'           => $c->isArchive(),
        ];
    }
}
