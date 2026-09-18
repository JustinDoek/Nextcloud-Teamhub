<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Provisioning;

use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ResourceService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Reads, validates and saves template blueprints (v4.9.6, Phase 2), and
 * turns one into the component list the wizard's review shows.
 *
 * ## Who edits a blueprint
 *
 * A Nextcloud administrator, on Admin → TeamHub → Policy, inside the
 * template's own editor (the OpenProject section of it — one place per
 * template, Justin 2026-09-13). The wizard reads blueprints — every creator
 * needs the effective one for the template they picked — and never writes.
 *
 * ## What the wizard is told
 *
 * `componentsForTemplate()` answers, per application the template row
 * lists: installed or missing, and whether the workspace will *create* it or
 * *link* an existing thing (the OpenProject-managed project folder is linked,
 * never created — see {@see ProjectFolderStep}). The wizard has no apps
 * step; this feeds the review and the validate step. A required application
 * that is not installed is reported as `missing`; the provisioning service
 * refuses to start until an administrator installs it, and the wizard says so
 * before anything is made.
 */
class BlueprintService {

    public function __construct(
        private TeamTemplateMapper $templates,
        private ResourceService    $resources,
        private AuditService       $auditService,
        private IUserSession       $userSession,
        private IGroupManager      $groupManager,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Read
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The effective blueprint of a template — stored or derived.
     *
     * @throws NotFoundException
     */
    public function forTemplate(string $templateKey): Blueprint {
        $row = $this->templates->find($templateKey);
        if ($row === null) {
            throw new NotFoundException('No such template.');
        }
        return Blueprint::fromTemplateRow($row);
    }

    /**
     * The template row itself, for callers that need both.
     *
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function templateRow(string $templateKey): array {
        $row = $this->templates->find($templateKey);
        if ($row === null) {
            throw new NotFoundException('No such template.');
        }
        return $row;
    }

    /**
     * The blueprint as the admin editor shows it: the effective blueprint,
     * whether it is stored or derived, and the vocabulary to edit with.
     *
     * @return array<string,mixed>
     */
    public function describe(string $templateKey): array {
        $this->requireNcAdmin();
        $bp = $this->forTemplate($templateKey);

        return [
            'templateKey' => $templateKey,
            'stored'      => $bp->isStored(),
            'blueprint'   => $bp->toArray(),
            'vocabulary'  => [
                'apps'                => TeamApps::CANONICAL,
                'modules'             => TeamApps::FEATURE_MODULES,
                'widgets'             => Blueprint::WIDGET_IDS,
                'roleKeys'            => Blueprint::ROLE_KEYS,
                'folderBehaviors'     => Blueprint::FOLDER_BEHAVIORS,
                'talkBehaviors'       => Blueprint::TALK_BEHAVIORS,
                'calendarBehaviors'   => Blueprint::CALENDAR_BEHAVIORS,
                'collectiveBehaviors' => Blueprint::COLLECTIVE_BEHAVIORS,
                'openProjectModes'    => Blueprint::OPENPROJECT_MODES,
                'copyKeys'            => Blueprint::COPY_KEYS,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Write (NC admin)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate and store a blueprint. Refuses rather than repairs: an
     * administrator pasting a blueprint with an unknown application learns
     * which one.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed> the description after the save
     * @throws ValidationException
     */
    public function save(string $templateKey, array $raw): array {
        $actor = $this->requireNcAdmin();
        $row   = $this->templateRow($templateKey);

        $bp = Blueprint::fromArray($raw, $row);
        if ($bp->requiresOpenProject() && $templateKey !== 'openproject') {
            // Phase 1's rule: only the OpenProject template's teams carry a
            // link. A blueprint cannot widen that.
            throw new ValidationException('Only the OpenProject template can require an OpenProject project.');
        }

        $this->templates->updateBlueprint($templateKey, $bp->toArray(), $actor, time());
        $this->auditService->log(AuditService::INSTANCE_SCOPE, 'policy.blueprint_updated', $actor, 'template', $templateKey, [
            'requiredApps'      => $bp->requiredApps(),
            'optionalApps'      => $bp->optionalApps(),
            'approvedTemplates' => array_map(static fn (array $t): int => $t['id'], $bp->approvedTemplates()),
            'roleMapping'       => $bp->roleMapping(),
        ]);

        return $this->describe($templateKey);
    }

    /**
     * Drop the stored blueprint: the OpenProject template returns to the
     * shipped one, any other template to its derived one.
     *
     * @return array<string,mixed>
     */
    public function reset(string $templateKey): array {
        $actor = $this->requireNcAdmin();
        $this->templateRow($templateKey);

        $stored = $templateKey === 'openproject' ? Blueprint::defaultsForOpenProject()->toArray() : null;
        $this->templates->updateBlueprint($templateKey, $stored, $actor, time());
        $this->auditService->log(AuditService::INSTANCE_SCOPE, 'policy.blueprint_reset', $actor, 'template', $templateKey, null);

        return $this->describe($templateKey);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The wizard's component view
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The applications and modules of a template as the wizard's components
     * step lists them. Not admin-gated — every creator reads it.
     *
     * @return array{
     *   blueprint: array<string,mixed>,
     *   components: list<array{id:string, kind:string, label:string, required:bool,
     *     installed:bool, preselected:bool, action:string, missing:bool}>,
     *   missingRequired: list<string>
     * }
     */
    public function componentsForTemplate(string $templateKey): array {
        $row       = $this->templateRow($templateKey);
        $bp        = Blueprint::fromTemplateRow($row);
        $installed = $this->resources->checkInstalledApps();
        // Apps the template row lists preselect the optional ones — that is
        // what an administrator meant by putting them on the template.
        $rowApps   = TeamApps::observableList(array_merge($row['apps'] ?? [], $row['modules'] ?? []));
        $rowMods   = TeamApps::featureModules($row['modules'] ?? []);

        $components      = [];
        $missingRequired = [];

        foreach (array_merge(
            array_map(static fn (string $a): array => ['id' => $a, 'required' => true], $bp->requiredApps()),
            array_map(static fn (string $a): array => ['id' => $a, 'required' => false], $bp->optionalApps()),
        ) as $app) {
            $id        = $app['id'];
            $isInst    = $this->appInstalled($id, $installed);
            $action    = $this->actionForApp($id, $bp);
            if ($app['required'] && !$isInst && $action !== 'none') {
                $missingRequired[] = $id;
            }
            $components[] = [
                'id'          => $id,
                'kind'        => 'app',
                'required'    => $app['required'],
                'installed'   => $isInst,
                'missing'     => !$isInst,
                'preselected' => $app['required'] || in_array($id, $rowApps, true),
                'action'      => $action,
            ];
        }

        foreach (array_merge(
            array_map(static fn (string $m): array => ['id' => $m, 'required' => true], $bp->requiredModules()),
            array_map(static fn (string $m): array => ['id' => $m, 'required' => false], $bp->optionalModules()),
        ) as $module) {
            $id      = $module['id'];
            $enabled = $this->moduleEnabledInstanceWide($id, $installed);
            if ($module['required'] && !$enabled) {
                $missingRequired[] = $id;
            }
            $components[] = [
                'id'          => $id,
                'kind'        => 'module',
                'required'    => $module['required'],
                'installed'   => $enabled,
                'missing'     => !$enabled,
                'preselected' => $module['required'] || in_array($id, $rowMods, true),
                'action'      => 'enable',
            ];
        }

        return [
            'blueprint'       => $bp->toArray(),
            'components'      => $components,
            'missingRequired' => $missingRequired,
        ];
    }

    /**
     * The applications and modules a workspace of this template gets: the
     * template row's, every one of them (Justin, 2026-09-13: the template
     * decides; the wizard has no apps step and the request carries no
     * choice). Kept as one method so the provisioning service and the
     * review both read the same answer.
     *
     * @return array{apps: list<string>, modules: list<string>}
     */
    public function selectionForTemplate(Blueprint $bp): array {
        return [
            'apps'    => array_values(array_unique(array_merge($bp->requiredApps(), $bp->optionalApps()))),
            'modules' => array_values(array_unique(array_merge($bp->requiredModules(), $bp->optionalModules()))),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $installed */
    private function appInstalled(string $appId, array $installed): bool {
        return match ($appId) {
            'talk'        => (bool)($installed['talk'] ?? false),
            'files'       => true, // Files is core; group folders are optional
            'calendar'    => (bool)($installed['calendar'] ?? false),
            'deck'        => (bool)($installed['deck'] ?? false),
            'collectives' => (bool)($installed['collectives'] ?? false),
            'intravox'    => (bool)($installed['intravox'] ?? false),
            default       => false,
        };
    }

    /** @param array<string,mixed> $installed */
    private function moduleEnabledInstanceWide(string $module, array $installed): bool {
        return match ($module) {
            'presence'  => (bool)($installed['presenceModuleEnabled'] ?? true),
            'decisions' => (bool)($installed['decisionsModuleEnabled'] ?? true),
            default     => true,
        };
    }

    /** create | link | none — what the workspace does with this application. */
    private function actionForApp(string $appId, Blueprint $bp): string {
        return match ($appId) {
            'files'       => match ($bp->folderBehavior()) {
                'teamhub', 'both' => 'create',
                'openproject'     => 'link',
                default           => 'none',
            },
            'talk'        => $bp->talkBehavior() === 'create' ? 'create' : 'none',
            'calendar'    => $bp->calendarBehavior() === 'create' ? 'create' : 'none',
            'collectives' => $bp->collectiveBehavior() === 'none' ? 'none' : 'create',
            default       => 'create',
        };
    }

    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Only a Nextcloud administrator can edit blueprints');
        }
        return $user->getUID();
    }
}
