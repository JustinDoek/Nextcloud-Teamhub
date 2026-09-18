<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\MemberService;
use OCA\TeamHub\Service\OpenProject\OpenProjectCapabilityService;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectModuleService;
use OCA\TeamHub\Service\OpenProject\OpenProjectProvisioningService;
use OCA\TeamHub\Service\Provisioning\BlueprintService;
use OCA\TeamHub\Service\Provisioning\MembershipPlanService;
use OCA\TeamHub\Service\Provisioning\ProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OpenProject Phase 2 endpoints (v4.9.6): the wizard's options and
 * previews, the provisioning operation, blueprints, and membership drift.
 * See OPENPROJECT.md §5 and APIendpoints.md.
 *
 * ## Who may call what
 *
 * - *options*, *preview* and *start*: anybody who may create a team
 *   (`MemberService::canCurrentUserCreateTeam()`, checked here and again
 *   in the service). Everything OpenProject is asked is asked as them.
 * - *status*: the operation's creator, a Nextcloud administrator, or an
 *   admin of the team it produced.
 * - *run*, *retry*, *rollback*: the creator or a Nextcloud administrator;
 *   the service runs the steps as the creator either way.
 * - *team provisioning state*: any member of the team (it is what the
 *   banner on the team page reads).
 * - *drift* and *sync*: team admins.
 * - *blueprints* (write) and the operations list: Nextcloud administrators.
 *
 * Every OpenProject failure is answered with a status and a stable `code`
 * (`OpenProjectResponseTrait`); nothing here returns 200 with an error body.
 * `POST` routes keep Nextcloud's CSRF check; the two `NoCSRFRequired`
 * reads are GETs that mutate nothing.
 *
 * v4.9.16 — every member-facing route starts with the module gate (403 when
 * the OpenProject module is unlicensed or switched off). The administrator
 * routes do not: the blueprint editor and the operations list are how an
 * administrator prepares the module before switching it on and tidies up
 * after switching it off. `adminRoles` reaches OpenProject and so refuses
 * through the client with the same code — honestly, with the sentence that
 * says where the switch is.
 */
class ProvisioningController extends Controller {
    use ExceptionResponseTrait;
    use OpenProjectResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private OpenProjectModuleService       $module,
        private ProvisioningService            $provisioning,
        private BlueprintService               $blueprints,
        private MembershipPlanService          $membership,
        private OpenProjectProvisioningService $op,
        private OpenProjectCapabilityService   $capabilities,
        private OpenProjectMessages            $messages,
        private MemberService                  $memberService,
        private IUserSession                   $userSession,
        private LoggerInterface                $logger,
    ) {
        parent::__construct($appName, $request);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Wizard
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/provisioning/options?templateKey=openproject
     *
     * Everything the wizard's OpenProject and components steps need, for
     * this creator: the effective blueprint, the components with their
     * required/optional/missing/link-or-create state, the capabilities
     * (probed), the OpenProject templates this user may copy (narrowed to
     * the approved ones), the role mapping resolved against the live roles,
     * and the host. Nothing is promised that the probe did not confirm.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function options(string $templateKey = 'openproject'): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            if (!$this->memberService->canCurrentUserCreateTeam()) {
                return new JSONResponse(['error' => 'You are not allowed to create teams'], Http::STATUS_FORBIDDEN);
            }
            $components = $this->blueprints->componentsForTemplate($templateKey);
            $bp         = $this->blueprints->forTemplate($templateKey);
            $out        = [
                'templateKey'     => $templateKey,
                'blueprint'       => $components['blueprint'],
                'components'      => $components['components'],
                'missingRequired' => $components['missingRequired'],
                'capabilities'    => null,
                'templates'       => [],
                'approvedOnly'    => $bp->approvedTemplates() !== [],
                'roles'           => [],
                'roleMapping'     => [],
                'canCreate'       => false,
                'canLink'         => in_array('link', $bp->openProjectModes(), true),
                'modes'           => $bp->openProjectModes(),
                'allowParent'     => $bp->allowsParent(),
                'warnings'        => [],
            ];
            if (!$bp->requiresOpenProject()) {
                return new JSONResponse($out);
            }

            $caps = $this->capabilities->getCapabilities(true, false);
            $out['capabilities'] = $caps;
            if ($caps['errorCode'] !== null) {
                return new JSONResponse($out);
            }
            $uid = $this->currentUserId();
            $out['canCreate'] = in_array('create', $bp->openProjectModes(), true) && (bool)$caps['provisioningAvailable'];

            if ($out['canCreate']) {
                try {
                    $templates = $this->op->listTemplates($uid);
                    foreach ($templates as &$t) {
                        $t['approved'] = $bp->isTemplateApproved($t['id']);
                    }
                    unset($t);
                    $out['templates'] = array_values(array_filter($templates, static fn (array $t): bool => $t['approved']));
                    $out['templatesTotal'] = count($templates);
                } catch (OpenProjectException $e) {
                    $out['warnings'][] = 'templates:' . $e->getErrorCode();
                }
            }
            try {
                $roles = $this->op->listRoles($uid);
                $out['roles']       = $roles;
                $out['roleMapping'] = $this->membership->resolveMapping($bp, $roles);
            } catch (OpenProjectException $e) {
                $out['warnings'][] = 'roles:' . $e->getErrorCode();
            }
            return new JSONResponse($out);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not load the provisioning options');
        }
    }

    /**
     * GET /api/v1/provisioning/parents?q=
     *
     * OpenProject projects the creator may create a new project under.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function parents(string $q = ''): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            if (!$this->memberService->canCurrentUserCreateTeam()) {
                return new JSONResponse(['error' => 'You are not allowed to create teams'], Http::STATUS_FORBIDDEN);
            }
            $uid = $this->capabilities->requireReadable();
            return new JSONResponse(['projects' => $this->op->listParentCandidates($uid, $q)]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not list parent projects');
        }
    }

    /**
     * GET /api/v1/provisioning/identifier?identifier=&name=
     *
     * Is this identifier valid and free; and, given a name, what would be
     * suggested. Asked while the creator types.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function identifier(string $identifier = '', string $name = ''): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            if (!$this->memberService->canCurrentUserCreateTeam()) {
                return new JSONResponse(['error' => 'You are not allowed to create teams'], Http::STATUS_FORBIDDEN);
            }
            $uid        = $this->capabilities->requireReadable();
            $identifier = mb_strtolower(trim($identifier));
            if ($identifier === '' && $name !== '') {
                $identifier = OpenProjectProvisioningService::suggestIdentifier($name);
            }
            $valid = OpenProjectProvisioningService::isValidIdentifier($identifier);
            return new JSONResponse([
                'identifier' => $identifier,
                'valid'      => $valid,
                'available'  => $valid ? $this->op->isIdentifierAvailable($uid, $identifier) : false,
                'suggested'  => $name !== '' ? OpenProjectProvisioningService::suggestIdentifier($name) : null,
            ]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not check the identifier');
        }
    }

    /**
     * POST /api/v1/provisioning/preview
     * Body: { templateKey, mode, members: [{id, type, level, displayName, decision?}], openProject: {projectId?} }
     *
     * The membership preview: every member with their TeamHub role, the
     * OpenProject role the mapping gives them, the OpenProject user they
     * match, and who needs a decision. Read-only; nothing is created.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function preview(string $templateKey = 'openproject', string $mode = 'create', array $members = [], array $openProject = []): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            if (!$this->memberService->canCurrentUserCreateTeam()) {
                return new JSONResponse(['error' => 'You are not allowed to create teams'], Http::STATUS_FORBIDDEN);
            }
            $uid = $this->capabilities->requireReadable();
            $bp  = $this->blueprints->forTemplate($templateKey);
            if (count($members) > ProvisioningService::MAX_MEMBERS) {
                throw new ValidationException('Too many members');
            }
            $roles    = $this->op->listRoles($uid);
            $resolved = $this->membership->resolveMapping($bp, $roles);
            $existing = [];
            $projectId = (int)($openProject['projectId'] ?? 0);
            if ($mode === 'link' && $projectId > 0) {
                try {
                    $existing = $this->op->listMemberships($uid, $projectId);
                } catch (OpenProjectException $e) {
                    if ($e->getErrorCode() !== OpenProjectException::PERMISSION_DENIED) {
                        throw $e;
                    }
                }
            }
            // The creator: the owner unless somebody else was appointed, in
            // which case they leave the team at the end unless they named
            // themselves — and, on a project this operation creates, leave
            // the project too (HandoverStep). The preview says so rather than
            // listing them as a second owner.
            $displayName = $this->userSession->getUser()?->getDisplayName() ?? $uid;
            $appointed   = null;
            $namedSelf   = null;
            foreach ($members as $m) {
                if (!is_array($m)) {
                    continue;
                }
                if (($m['type'] ?? 'user') === 'user' && (string)($m['id'] ?? '') === $uid) {
                    $namedSelf = $m;
                } elseif ((int)($m['level'] ?? 1) === 9 && ($m['type'] ?? 'user') !== 'group') {
                    $appointed = $m;
                }
            }
            $list = $members;
            if ($appointed === null) {
                $list = array_merge([['id' => $uid, 'type' => 'user', 'level' => 9, 'displayName' => $displayName, 'decision' => 'teamhub_only']], $members);
            }
            $plan = $this->membership->plan($uid, $list, $resolved, $existing);
            if ($appointed !== null && $namedSelf === null) {
                $plan['entries'][] = [
                    'id' => $uid, 'type' => 'user', 'displayName' => $displayName, 'level' => 9, 'teamRole' => 'owner',
                    'openProjectRole' => null, 'principal' => null, 'matchedBy' => null,
                    'status' => 'leaves', 'decision' => null, 'reason' => null,
                    // Mode A: OpenProject makes the creator a project admin at
                    // creation; the handover removes them again.
                    'leavesProject' => $mode === 'create',
                ];
            }
            return new JSONResponse($plan + ['roleMapping' => $resolved, 'roles' => $roles]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not build the membership preview');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The operation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/provisioning
     *
     * Record the operation. Answers 201 with its status; the client then
     * calls `run` until `status` is no longer `running`/`pending`. The same
     * `idempotencyKey` from the same creator returns the existing operation
     * (200) — a double click makes one workspace.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function start(): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $body   = $this->request->getParams();
            $status = $this->provisioning->start(is_array($body) ? $body : []);
            $isNew  = ($status['status'] ?? '') === 'pending' && ($status['startedAt'] ?? null) === null;
            return new JSONResponse($status, $isNew ? Http::STATUS_CREATED : Http::STATUS_OK);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not start provisioning');
        }
    }

    /** GET /api/v1/provisioning/{id} */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(int $id): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->status($id));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not read the provisioning status', ['id' => $id]);
        }
    }

    /** GET /api/v1/provisioning — the caller's own unfinished operations. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function mine(): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse(['operations' => $this->provisioning->listMine()]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not list provisioning operations');
        }
    }

    /**
     * POST /api/v1/provisioning/{id}/run
     *
     * Execute pending steps for up to `budget` seconds (capped at 60) and
     * return the state. `pollAfter` in the answer asks the client to wait
     * before calling again (an OpenProject copy job in flight).
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function run(int $id, int $budget = ProvisioningService::DEFAULT_BUDGET): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->run($id, $budget));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Provisioning could not continue', ['id' => $id]);
        }
    }

    /** POST /api/v1/provisioning/{id}/retry  Body: { step?: key } */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function retry(int $id, string $step = ''): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->retry($id, $step !== '' ? $step : null));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'The step could not be retried', ['id' => $id]);
        }
    }

    /**
     * POST /api/v1/provisioning/{id}/rollback  Body: { confirm?: bool }
     *
     * Without `confirm`, a dry run when any created resource may hold
     * activity: answers `status: confirm_required` and the list. With it,
     * the team is removed through the normal cascade. An OpenProject project
     * is never deleted; linked resources are never deleted.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function rollback(int $id, bool $confirm = false): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->rollback($id, $confirm));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'The rollback could not run', ['id' => $id]);
        }
    }

    /**
     * GET /api/v1/teams/{teamId}/provisioning
     *
     * The team's provisioning state, for the banner. Members only; the
     * shape is the summary (no request payload).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function forTeam(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            $this->memberService->requireMemberLevel($teamId);
            return new JSONResponse(['provisioning' => $this->provisioning->latestForTeam($teamId)]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not read the provisioning state', ['teamId' => $teamId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Membership afterwards
    // ─────────────────────────────────────────────────────────────────────

    /** GET /api/v1/teams/{teamId}/openproject/membership-drift — team admins. */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function membershipDrift(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->membershipDrift($teamId));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not compare memberships', ['teamId' => $teamId]);
        }
    }

    /** POST /api/v1/teams/{teamId}/openproject/membership-sync — team admins; adds, never removes. */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 5, period: 60)]
    public function membershipSync(string $teamId): JSONResponse {
        $gate = $this->openProjectModuleGate();
        if ($gate !== null) {
            return $gate;
        }
        try {
            return new JSONResponse($this->provisioning->syncMembership($teamId));
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not synchronise memberships', ['teamId' => $teamId]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Administration
    // ─────────────────────────────────────────────────────────────────────

    /** GET /api/v1/admin/provisioning — recent operations, newest first. */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function adminList(int $limit = 25): JSONResponse {
        try {
            return new JSONResponse(['operations' => $this->provisioning->listRecent($limit)]);
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not list provisioning operations');
        }
    }

    /** GET /api/v1/admin/policy/templates/{templateKey}/blueprint */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    public function getBlueprint(string $templateKey): JSONResponse {
        try {
            return new JSONResponse($this->blueprints->describe($templateKey));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not load the blueprint', ['templateKey' => $templateKey]);
        }
    }

    /** PUT /api/v1/admin/policy/templates/{templateKey}/blueprint  Body: { blueprint: {...} } */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function saveBlueprint(string $templateKey, array $blueprint = []): JSONResponse {
        try {
            return new JSONResponse($this->blueprints->save($templateKey, $blueprint));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not save the blueprint', ['templateKey' => $templateKey]);
        }
    }

    /** DELETE /api/v1/admin/policy/templates/{templateKey}/blueprint — back to the shipped/derived one. */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    public function resetBlueprint(string $templateKey): JSONResponse {
        try {
            return new JSONResponse($this->blueprints->reset($templateKey));
        } catch (\Throwable $e) {
            return $this->exceptionResponse($e, 'Could not reset the blueprint', ['templateKey' => $templateKey]);
        }
    }

    /**
     * GET /api/v1/admin/policy/openproject-roles
     *
     * The OpenProject roles as the administrator's own connected account
     * sees them, for the blueprint editor's role-mapping picker.
     */
    #[AuthorizedAdminSetting(settings: \OCA\TeamHub\Settings\AdminSettings::class)]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function adminRoles(): JSONResponse {
        try {
            $uid = $this->capabilities->requireReadable();
            return new JSONResponse(['roles' => $this->op->listRoles($uid), 'templates' => $this->op->listTemplates($uid)]);
        } catch (\Throwable $e) {
            return $this->openProjectFailure($e, 'Could not read OpenProject roles');
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    protected function openProjectMessages(): OpenProjectMessages {
        return $this->messages;
    }

    /** For `OpenProjectResponseTrait::openProjectModuleGate()` (v4.9.16). */
    protected function openProjectModuleCode(): ?string {
        return $this->module->unavailableCode();
    }

    private function currentUserId(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }
}
