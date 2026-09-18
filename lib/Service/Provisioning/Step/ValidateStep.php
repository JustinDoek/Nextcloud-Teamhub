<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning\Step;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\OpenProject\TeamOpenProjectLinkService;
use OCA\TeamHub\Service\Provisioning\BlueprintService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningContext;
use OCA\TeamHub\Service\Provisioning\StepResult;
use OCA\TeamHub\Service\TeamService;

/**
 * Step 1 — validate the request and the creator's permissions, against the
 * live state, before anything is made.
 *
 * Everything here was checked once when the wizard collected it; it is
 * checked again because time passed: another team may have taken the name,
 * another project the identifier, an administrator may have removed the
 * creator from the team-creator group. A failure here costs nothing to
 * retry, and leaves nothing behind.
 */
class ValidateStep implements StepInterface {

    public const KEY = 'validate';

    public function __construct(
        private MemberService                  $members,
        private TeamService                    $teams,
        private OpenProjectCapabilityService   $capabilities,
        private OpenProjectProvisioningService $op,
        private TeamOpenProjectLinkService     $links,
        private BlueprintService               $blueprints,
        private MembershipPlanService          $membership,
    ) {
    }

    public function key(): string { return self::KEY; }
    public function resourceType(): ?string { return null; }
    public function applies(ProvisioningContext $ctx): bool { return true; }
    public function rollbackPossible(): bool { return false; }

    public function run(ProvisioningContext $ctx): StepResult {
        $bp  = $ctx->blueprint;
        $uid = $ctx->userId;

        // ── Who ──────────────────────────────────────────────────────────
        if (!$this->members->canCurrentUserCreateTeam()) {
            return StepResult::failed('not_allowed', 'You are not allowed to create teams.', false);
        }

        // ── The team name ────────────────────────────────────────────────
        try {
            $this->teams->assertValidTeamName($ctx->teamName());
            if ($ctx->teamId === null) {
                $this->teams->assertTeamNameAvailable($ctx->teamName());
            }
        } catch (\Throwable $e) {
            return StepResult::failed('invalid_name', $e->getMessage(), true);
        }

        // ── Required applications present ────────────────────────────────
        $components = $this->blueprints->componentsForTemplate($ctx->operation['templateKey']);
        if ($components['missingRequired'] !== []) {
            return StepResult::failed(
                'missing_apps',
                'Required applications are not installed: ' . implode(', ', $components['missingRequired']),
                true,
                ['missing' => $components['missingRequired']],
            );
        }

        // ── OpenProject ──────────────────────────────────────────────────
        if (!$bp->requiresOpenProject()) {
            return StepResult::completed(null, ['openProject' => false]);
        }

        $caps = $this->capabilities->getCapabilities(true, true);
        if ($caps['errorCode'] !== null) {
            return StepResult::failed($caps['errorCode'], (string)$caps['userMessage'], true);
        }

        $opReq = $ctx->field('openProject', []);
        try {
            if ($ctx->isCreateMode()) {
                if (!in_array('create', $bp->openProjectModes(), true)) {
                    return StepResult::failed('mode_not_allowed', 'This template does not allow creating a new OpenProject project.', false);
                }
                if (!$this->op->canCreateProjects($uid, true)) {
                    return StepResult::failed(OpenProjectException::PERMISSION_DENIED, 'OpenProject does not let you create projects.', false);
                }
                $identifier = (string)($opReq['identifier'] ?? '');
                if (!OpenProjectProvisioningService::isValidIdentifier($identifier)) {
                    return StepResult::failed('invalid_identifier', 'The project identifier is not valid.', false);
                }
                // A retry after the project was made must find it, not refuse it.
                $alreadyOurs = $ctx->fact('openproject_project', 'projectId') !== null;
                if (!$alreadyOurs && !$this->op->isIdentifierAvailable($uid, $identifier)) {
                    return StepResult::failed('identifier_taken', 'That project identifier is already taken in OpenProject.', false);
                }
                $templateId = (int)($opReq['templateId'] ?? 0);
                if ($templateId > 0) {
                    if (!$bp->isTemplateApproved($templateId)) {
                        return StepResult::failed('template_not_approved', 'That OpenProject template is not approved for this kind of team.', false);
                    }
                    $usable = array_filter($this->op->listTemplates($uid), static fn (array $t): bool => $t['id'] === $templateId);
                    if ($usable === []) {
                        return StepResult::failed('template_inaccessible', 'That OpenProject template is not available to you.', false);
                    }
                }
                $parentId = (int)($opReq['parentId'] ?? 0);
                if ($parentId > 0 && !$bp->allowsParent()) {
                    return StepResult::failed('parent_not_allowed', 'This template does not allow a parent project.', false);
                }
            } else {
                if (!in_array('link', $bp->openProjectModes(), true)) {
                    return StepResult::failed('mode_not_allowed', 'This template does not allow connecting an existing OpenProject project.', false);
                }
                $projectId = (int)($opReq['projectId'] ?? 0);
                // Phase 1's rule, unchanged: administered or public, and not
                // another team's. Thrown as the classified exceptions.
                $this->links->assertLinkableForNewTeam(TeamOpenProjectLinkService::TEMPLATE, $projectId);
            }

            // ── Roles ────────────────────────────────────────────────────
            $roles    = $this->op->listRoles($uid);
            $resolved = $this->membership->resolveMapping($bp, $roles);
            $plan     = $this->membership->plan($uid, $this->membersIncludingCreator($ctx), $resolved);
            $this->membership->assertMappingUsable($resolved, $plan['usedRoleKeys']);
            if ($plan['needsDecision'] !== []) {
                return StepResult::failed(
                    'unmatched_members',
                    'Some members have no OpenProject account and need a decision.',
                    false,
                    ['needsDecision' => $plan['needsDecision']],
                );
            }
        } catch (ValidationException $e) {
            return StepResult::failed('validation', $e->getMessage(), false);
        } catch (\OCA\TeamHub\Exception\AccessDeniedException $e) {
            return StepResult::failed(OpenProjectException::PERMISSION_DENIED, $e->getMessage(), false);
        } catch (\OCA\TeamHub\Service\OpenProject\ProjectAlreadyLinkedException $e) {
            return StepResult::failed('project_already_linked', $e->getMessage(), false, ['teams' => $e->getTeams()]);
        } catch (OpenProjectException $e) {
            return StepResult::failed($e->getErrorCode(), $e->getUpstreamMessage() ?? $e->getMessage(), true);
        }

        return StepResult::completed(null, ['openProject' => true, 'checkedAt' => time()]);
    }

    public function rollback(ProvisioningContext $ctx, array $stepRow, bool $confirm): StepResult {
        return StepResult::skipped('nothing_to_undo');
    }

    /**
     * The members whose roles the mapping must cover: the wizard's list plus
     * the creator, who is the owner unless somebody else was appointed.
     *
     * @return list<array<string,mixed>>
     */
    private function membersIncludingCreator(ProvisioningContext $ctx): array {
        $members = (array)$ctx->field('members', []);
        $owner   = (string)$ctx->field('ownerUid', '');
        $hasSelf = false;
        foreach ($members as $m) {
            if (($m['type'] ?? 'user') === 'user' && ($m['id'] ?? '') === $ctx->userId) {
                $hasSelf = true;
                break;
            }
        }
        if (!$hasSelf && ($owner === '' || $owner === $ctx->userId)) {
            $members[] = ['id' => $ctx->userId, 'type' => 'user', 'level' => 9, 'decision' => 'teamhub_only'];
        }
        return $members;
    }
}
