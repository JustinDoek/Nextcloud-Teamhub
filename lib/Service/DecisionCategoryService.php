<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\DecisionCategory;
use OCA\TeamHub\Db\DecisionCategoryApprover;
use OCA\TeamHub\Db\DecisionCategoryApproverMapper;
use OCA\TeamHub\Db\DecisionCategoryMapper;
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
 */
class DecisionCategoryService {

    public function __construct(
        private DecisionCategoryMapper          $categoryMapper,
        private DecisionCategoryApproverMapper  $approverMapper,
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
    ): array {
        $name = $this->validateName($name);

        if ($this->categoryMapper->existsByName($teamId, $name)) {
            throw new \InvalidArgumentException('Category with this name already exists');
        }

        $icon        = $this->sanitiseIcon($icon);
        $description = $this->sanitiseDescription($description);

        $now = time();
        $cat = new DecisionCategory();
        $cat->setTeamId($teamId);
        $cat->setName($name);
        $cat->setIcon($icon);
        $cat->setDescription($description);
        $cat->setCreatedBy($createdBy);
        $cat->setCreatedAt($now);
        $cat->setUpdatedAt($now);

        /** @var DecisionCategory $saved */
        $saved = $this->categoryMapper->insert($cat);

        $resolved = $this->resolveApprovers($teamId, $approvers);
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
    ): array {
        $cat = $this->categoryMapper->findByIdForTeam($categoryId, $teamId);

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
            'teamId'     => $teamId,
            'categoryId' => $categoryId,
            'changed'    => $changed,
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

        $this->approverMapper->deleteAllForCategory($categoryId);
        $this->categoryMapper->delete($cat);

        $this->logger->info('[TeamHub][DecisionCategoryService] deleted category', [
            'teamId'     => $teamId,
            'categoryId' => $categoryId,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
            'created_by'  => $c->getCreatedBy(),
            'created_at'  => $c->getCreatedAt(),
            'updated_at'  => $c->getUpdatedAt(),
            'approvers'   => array_values($approvers),
        ];
    }
}
