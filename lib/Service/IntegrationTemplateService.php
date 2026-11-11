<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationTemplateMapper;
use OCA\TeamHub\Db\IntegrationVisibilityMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Integration\CapabilityType;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Which templates include which integration packages (v4.13.23).
 *
 * A Nextcloud administrator's decision, and only theirs. There is no
 * team-administrator path to any method here, and the service checks that
 * itself rather than trusting the route.
 *
 * ## Inclusion is live, which is why reconciliation has nothing to repair
 *
 * `teamhub_template.apps` and `.modules` are copied into per-team state by
 * `ModulesStep` at creation, so teams drift and `PolicyPropagationService`
 * exists to push a changed template back out. Package inclusion works the
 * other way round: {@see IntegrationStateService} reads
 * `teamhub_integ_tmpl` on every check, so a team *cannot* drift from its
 * template and a template change takes effect for every team of that template
 * the moment it is saved.
 *
 * That has a consequence worth being explicit about, because it is the
 * opposite of what "reconcile" usually means here: **reconciliation does not
 * repair state, because there is no copied state to repair.** What
 * {@see reconcile()} does is notify — fire the lifecycle hooks for each
 * affected team — and record the outcome per team so the change is auditable
 * and retryable. A reconciliation that reports every team as `ok` and changed
 * nothing is the normal, correct result for a package with no lifecycle
 * provider.
 *
 * ## What a removal does not do
 *
 * Removing a package from a template makes every capability ineffective for
 * that template's teams and deletes nothing: not the per-team shown/hidden
 * preferences, not the ordering, not any mapping, not any external data. So
 * re-including it restores each team's layout exactly, which is the property
 * that makes an administrator willing to try a template change at all.
 */
class IntegrationTemplateService {

    /**
     * Teams per reconciliation run.
     *
     * The same ceiling and the same reasoning as
     * `PolicyPropagationService::MAX_TEAMS`: each team costs a lifecycle hook
     * round and an audit row, and this is the number that keeps one run inside
     * a normal request. Exceeding it is reported, never silently truncated —
     * and because the run is retryable with an explicit team list, an estate
     * above the ceiling is worked through in batches rather than half-applied.
     */
    public const MAX_TEAMS = 200;

    public function __construct(
        private IntegrationPackageMapper     $packages,
        private IntegrationCapabilityMapper  $capabilities,
        private IntegrationTemplateMapper    $inclusions,
        private IntegrationVisibilityMapper  $visibility,
        private TeamTemplateMapper           $templates,
        private TeamTypeMapper               $teamTypes,
        private IntegrationStateService      $stateService,
        private IntegrationLifecycleService  $lifecycle,
        private AuditService                 $auditService,
        private IUserSession                 $userSession,
        private IGroupManager                $groupManager,
        private LoggerInterface              $logger,
    ) {}

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    /**
     * The inclusion matrix for the template editor.
     *
     * Per template: the packages it includes, and separately the packages it
     * *could* include. The selector offers only packages that are owned by an
     * installed and enabled app, currently registered, compatible and enabled
     * under administration settings — `template_eligible` from the state
     * service, so the editor cannot disagree with what the server would
     * accept.
     *
     * A template that references a package which has since become unavailable
     * keeps the reference and reports it as `included_but_unavailable` with
     * the reason. Silently dropping a saved reference is the behaviour this
     * explicitly refuses: an administrator whose integration stopped working
     * needs to see that the template still asks for it.
     *
     * @return array<string,mixed>
     */
    public function listTemplateInclusions(): array {
        $this->requireNcAdmin();

        $packageRows = $this->packages->findAll();
        $byId        = [];
        $eligible    = [];

        foreach ($packageRows as $pkgRow) {
            $pkgId        = (int)$pkgRow['id'];
            $availability = $this->stateService->availability($pkgRow);
            $summary      = $this->capabilitySummaryFor($pkgId);

            $descriptor = [
                'id'                  => $pkgId,
                'app_id'              => (string)$pkgRow['app_id'],
                'integration_key'     => (string)$pkgRow['integration_key'],
                'title'               => (string)$pkgRow['title'],
                'description'         => $pkgRow['description'],
                'icon'                => $pkgRow['icon'],
                'version'             => (string)$pkgRow['version'],
                'is_legacy'           => (bool)$pkgRow['is_legacy'],
                'app_available'       => $availability['app_available'],
                'app_installed'       => $availability['app_installed'],
                'registered'          => $availability['registered'],
                'compatible'          => $availability['compatible'],
                'integration_enabled' => $availability['integration_enabled'],
                'template_eligible'   => $availability['template_eligible'],
                'unavailable_reason'  => $availability['unavailable_reason'],
                'capability_summary'  => $summary,
            ];

            $byId[$pkgId] = $descriptor;
            if ($availability['template_eligible']) {
                $eligible[] = $descriptor;
            }
        }

        $templates = [];
        foreach ($this->templates->findAll() as $template) {
            $templateKey = (string)$template['templateKey'];
            $includedIds = $this->inclusions->packageIdsForTemplate($templateKey);

            $included = [];
            foreach ($includedIds as $pkgId) {
                $descriptor = $byId[$pkgId] ?? null;
                if ($descriptor === null) {
                    // An inclusion pointing at a package row that is gone.
                    // Package rows are never deleted, so this should not
                    // happen; reported rather than hidden, because if it does
                    // the administrator is looking at a template that asks for
                    // something nothing can name.
                    $included[] = [
                        'id'                        => $pkgId,
                        'title'                     => '',
                        'included_but_unavailable'  => true,
                        'unavailable_reason'        => 'package_record_missing',
                    ];
                    continue;
                }

                $included[] = $descriptor + [
                    'included_but_unavailable' => !$descriptor['template_eligible'],
                ];
            }

            $templates[] = [
                'template_key' => $templateKey,
                'label'        => (string)$template['label'],
                'team_count'   => count($this->teamTypes->findTeamsByType($templateKey)),
                'included'     => $included,
            ];
        }

        return [
            'templates' => $templates,
            // What the selector may offer. Deliberately a separate list from
            // the full set: an administrator must not be able to pick
            // something the server would refuse.
            'eligible'  => $eligible,
        ];
    }

    // ------------------------------------------------------------------
    // Change
    // ------------------------------------------------------------------

    /**
     * Include a package in a template.
     *
     * Refuses a package that is not eligible. A template may only gain a
     * package that is available, registered, compatible and enabled — an
     * inclusion recorded against a package nobody enabled would be a decision
     * with no visible effect, and the administrator would have no way to tell
     * it from one that is working.
     *
     * An existing inclusion whose package has *since* become unavailable is a
     * different case and is kept; this rule is about adding a new one.
     *
     * @return array<string,mixed>
     */
    public function includeInTemplate(string $templateKey, int $pkgId): array {
        $actor    = $this->requireNcAdmin();
        $template = $this->template($templateKey);
        $pkgRow   = $this->package($pkgId);

        $availability = $this->stateService->availability($pkgRow);
        if (!$availability['template_eligible']) {
            throw new ValidationException($this->ineligibleMessage($availability));
        }

        if ($this->inclusions->isIncluded($templateKey, $pkgId)) {
            // Idempotent, and reported as such rather than as a change.
            return [
                'template_key' => $templateKey,
                'pkg_id'       => $pkgId,
                'included'     => true,
                'changed'      => false,
            ];
        }

        $this->inclusions->includePackage($templateKey, $pkgId, $actor, time());
        $this->stateService->resetCaches();

        $teamIds = $this->teamTypes->findTeamsByType($templateKey);

        $this->audit($templateKey, $pkgRow, 'integration_template_added', $actor, [
            'template_key'   => $templateKey,
            'template_label' => (string)$template['label'],
            'teams_affected' => count($teamIds),
        ]);

        return [
            'template_key'   => $templateKey,
            'pkg_id'         => $pkgId,
            'included'       => true,
            'changed'        => true,
            'teams_affected' => count($teamIds),
            // Every capability is live for those teams already — inclusion is
            // read live. Reconciliation is only needed to notify lifecycle
            // providers, so the caller is told whether there is anything to
            // notify rather than being sent to reconcile unconditionally.
            'reconcile_recommended' => $this->lifecycle->hasLifecycleProviders($pkgRow),
        ];
    }

    /**
     * Remove a package from a template.
     *
     * Deletes one row and nothing else — see the class docblock.
     *
     * @return array<string,mixed>
     */
    public function removeFromTemplate(string $templateKey, int $pkgId): array {
        $actor    = $this->requireNcAdmin();
        $template = $this->template($templateKey);
        $pkgRow   = $this->package($pkgId);

        if (!$this->inclusions->isIncluded($templateKey, $pkgId)) {
            return [
                'template_key' => $templateKey,
                'pkg_id'       => $pkgId,
                'included'     => false,
                'changed'      => false,
            ];
        }

        $teamIds = $this->teamTypes->findTeamsByType($templateKey);

        $this->inclusions->removePackage($templateKey, $pkgId);
        $this->stateService->resetCaches();

        $this->audit($templateKey, $pkgRow, 'integration_template_removed', $actor, [
            'template_key'          => $templateKey,
            'template_label'        => (string)$template['label'],
            'teams_affected'        => count($teamIds),
            'team_preferences_kept' => true,
            'external_data_kept'    => true,
        ]);

        return [
            'template_key'          => $templateKey,
            'pkg_id'                => $pkgId,
            'included'              => false,
            'changed'               => true,
            'teams_affected'        => count($teamIds),
            'reconcile_recommended' => $this->lifecycle->hasLifecycleProviders($pkgRow),
        ];
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    /**
     * What a template change would do, before doing it.
     *
     * @param bool $including true for "what if I add it", false for "remove"
     * @return array<string,mixed>
     */
    public function previewTemplateChange(string $templateKey, int $pkgId, bool $including): array {
        $this->requireNcAdmin();

        $template = $this->template($templateKey);
        $pkgRow   = $this->package($pkgId);

        $availability = $this->stateService->availability($pkgRow);
        $teamIds      = $this->teamTypes->findTeamsByType($templateKey);
        $capabilities = array_values(array_filter(
            $this->capabilities->findByPackage($pkgId),
            static fn (array $c): bool => (bool)$c['registered'],
        ));

        $widgets   = [];
        $menuItems = [];
        $myWork    = [];
        $other     = [];

        foreach ($capabilities as $capability) {
            $capType = (string)$capability['cap_type'];
            $entry   = [
                'capability_key' => (string)$capability['capability_key'],
                'title'          => (string)$capability['title'],
                'icon'           => $capability['icon'],
            ];

            if (CapabilityType::isVisual($capType)) {
                // Teams that already hold a hidden preference for this
                // capability. On an *add* these are the teams where it will
                // NOT appear, because a preserved hidden preference wins over
                // the shown-by-default rule — which is the single most
                // surprising thing about re-adding a package, so the preview
                // says it rather than letting an administrator discover it.
                try {
                    $hiding = $this->visibility->teamsHiding((int)$capability['id']);
                } catch (\Throwable $e) {
                    $hiding = [];
                    $this->logger->warning('[TeamHub][Integration] Could not read hidden preferences for the preview', [
                        'exception' => $e, 'app' => Application::APP_ID,
                    ]);
                }
                $inThisTemplate = array_values(array_intersect($hiding, $teamIds));

                $entry['teams_with_preserved_hidden'] = count($inThisTemplate);
                $entry['will_appear_for_teams']       = $including
                    ? max(0, count($teamIds) - count($inThisTemplate))
                    : 0;
            }

            match (true) {
                // v4.13.24 — both widget types are cards on the team's home
                // view, which is what this preview is naming.
                CapabilityType::isGridCard($capType)          => $widgets[]   = $entry,
                $capType === CapabilityType::MENU_ITEM        => $menuItems[] = $entry,
                $capType === CapabilityType::MY_WORK_PROVIDER => $myWork[]    = $entry,
                default                                       => $other[]     = $entry + ['cap_type' => $capType],
            };
        }

        return [
            'template_key'   => $templateKey,
            'template_label' => (string)$template['label'],
            'pkg_id'         => $pkgId,
            'app_id'         => (string)$pkgRow['app_id'],
            'title'          => (string)$pkgRow['title'],
            'action'         => $including ? 'add' : 'remove',
            'teams_affected' => count($teamIds),
            'exceeds_run_limit' => count($teamIds) > self::MAX_TEAMS,
            // The verbs are from the administrator's side of the change, so
            // the same payload reads correctly for an add and for a remove.
            'widgets_appearing'    => $including ? $widgets : [],
            'widgets_disappearing' => $including ? [] : $widgets,
            'tabs_appearing'       => $including ? $menuItems : [],
            'tabs_disappearing'    => $including ? [] : $menuItems,
            'my_work_starting'     => $including ? $myWork : [],
            'my_work_stopping'     => $including ? [] : $myWork,
            'other_capabilities'   => $other,
            // Dependencies that would stop the change taking effect.
            'unavailable'          => !$availability['template_eligible'],
            'unavailable_reason'   => $availability['unavailable_reason'],
            'team_preferences_kept' => true,
            'ordering_kept'         => true,
            'external_data_kept'    => true,
            'lifecycle_providers'   => $this->lifecycle->hasLifecycleProviders($pkgRow),
        ];
    }

    // ------------------------------------------------------------------
    // Reconciliation
    // ------------------------------------------------------------------

    /**
     * Notify a package's lifecycle providers about each affected team, and
     * report per team.
     *
     * Retryable by design: pass `$teamIds` to work a subset, and run it again
     * for the teams that failed. Nothing it does is destructive and every hook
     * is required to be idempotent, so a second run over the same teams is
     * safe.
     *
     * Runs **after** the inclusion change has been committed, never inside a
     * transaction — see {@see \OCA\TeamHub\Integration\ILifecycleProvider}'s
     * rule 3. The hooks are the only irreversible external work here, and they
     * must not be able to run against a template change that is then rolled
     * back.
     *
     * @param string            $action  'added' or 'removed'
     * @param list<string>|null $teamIds null means every team of the template
     * @return array<string,mixed>
     */
    public function reconcile(string $templateKey, int $pkgId, string $action, ?array $teamIds = null): array {
        $actor    = $this->requireNcAdmin();
        $template = $this->template($templateKey);
        $pkgRow   = $this->package($pkgId);

        if ($action !== 'added' && $action !== 'removed') {
            throw new ValidationException("action must be 'added' or 'removed'.");
        }

        $allTeams = $this->teamTypes->findTeamsByType($templateKey);

        if ($teamIds === null) {
            $targets = $allTeams;
        } else {
            // The caller names teams, and every one is still checked to belong
            // to this template before anything is fired. What the list carries
            // is which teams to work, never the authorisation to work them —
            // the same distinction `PolicyPropagationService` documents.
            $requested = array_values(array_unique(array_map('strval', $teamIds)));
            $targets   = array_values(array_intersect($requested, $allTeams));
        }

        $truncated = false;
        if (count($targets) > self::MAX_TEAMS) {
            $targets   = array_slice($targets, 0, self::MAX_TEAMS);
            $truncated = true;
        }

        $this->audit($templateKey, $pkgRow, 'integration_reconcile_started', $actor, [
            'template_key' => $templateKey,
            'action'       => $action,
            'teams'        => count($targets),
        ]);

        $hook = $action === 'added'
            ? 'onTemplateIntegrationAdded'
            : 'onTemplateIntegrationRemoved';

        $results  = [];
        $failures = 0;

        foreach ($targets as $teamId) {
            $outcome = $this->lifecycle->fire($pkgRow, $teamId, $actor, $hook);

            // A team whose owning app is unavailable is reported as degraded
            // rather than failed: nothing went wrong, the integration simply
            // cannot operate there yet, and an administrator retrying will not
            // change that.
            $state     = $this->stateService->forTeam($pkgRow, $teamId, $actor, accessAlreadyVerified: true);
            $effective = $state->isEffective();

            $status = match (true) {
                $outcome['failed'] > 0                       => 'failed',
                $action === 'added' && !$effective           => 'degraded',
                default                                      => 'ok',
            };
            if ($status === 'failed') {
                $failures++;
            }

            $results[] = [
                'team_id'            => $teamId,
                'status'             => $status,
                'hooks_fired'        => $outcome['fired'],
                'hooks_failed'       => $outcome['failed'],
                'errors'             => $outcome['errors'],
                'effective'          => $effective,
                'unavailable_reason' => $state->unavailableReason,
            ];
        }

        $this->audit(
            $templateKey,
            $pkgRow,
            $failures > 0 ? 'integration_reconcile_failed' : 'integration_reconcile_completed',
            $actor,
            [
                'template_key' => $templateKey,
                'action'       => $action,
                'teams'        => count($results),
                'failed'       => $failures,
                'truncated'    => $truncated,
            ],
        );

        return [
            'template_key'   => $templateKey,
            'template_label' => (string)$template['label'],
            'pkg_id'         => $pkgId,
            'action'         => $action,
            'teams'          => $results,
            'failed'         => $failures,
            // Above the ceiling the run stops rather than half-applying.
            // Retry with an explicit teamIds list for the rest.
            'truncated'      => $truncated,
            'remaining'      => $truncated ? max(0, count($allTeams) - count($results)) : 0,
        ];
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /** @return array{widgets: int, menu_items: int, my_work_providers: int, other: int} */
    private function capabilitySummaryFor(int $pkgId): array {
        $summary = ['widgets' => 0, 'menu_items' => 0, 'my_work_providers' => 0, 'other' => 0];

        foreach ($this->capabilities->findByPackage($pkgId) as $capability) {
            if (!$capability['registered']) {
                continue;
            }
            $summary[match ((string)$capability['cap_type']) {
                // v4.13.24 — a blank-canvas widget counts as a widget
                // here. An administrator reading "2 widgets" before a disable
                // is counting cards that will vanish from a home view, and
                // which of them TeamHub draws is not their question.
                CapabilityType::WIDGET,
                CapabilityType::WIDGET_IFRAME    => 'widgets',
                CapabilityType::MENU_ITEM        => 'menu_items',
                CapabilityType::MY_WORK_PROVIDER => 'my_work_providers',
                default                          => 'other',
            }]++;
        }

        return $summary;
    }

    /** @param array<string,mixed> $availability */
    private function ineligibleMessage(array $availability): string {
        return match ($availability['unavailable_reason']) {
            'app_not_installed' => 'The owning app is not installed on this instance.',
            'app_disabled'      => 'The owning app is disabled.',
            'not_registered'    => 'This integration has not registered with TeamHub.',
            'incompatible'      => 'This integration is not compatible with this version of TeamHub.',
            'integration_not_enabled' => 'Enable this integration under Administration → TeamHub → Integrations first.',
            default             => 'This integration cannot be included in a template right now.',
        };
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function template(string $templateKey): array {
        $row = $this->templates->find($templateKey);
        if ($row === null) {
            throw new NotFoundException('No such team template.');
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function package(int $pkgId): array {
        $row = $this->packages->findById($pkgId);
        if ($row === null) {
            throw new NotFoundException('No such integration package.');
        }

        return $row;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(string $templateKey, array $pkgRow, string $eventType, string $actor, array $metadata): void {
        // Instance scope: a template decision is about every team of that
        // kind, present and future, so it does not belong in one team's
        // stream.
        $this->auditService->log(
            AuditService::INSTANCE_SCOPE,
            $eventType,
            $actor,
            'integration',
            (string)$pkgRow['app_id'] . '/' . (string)$pkgRow['integration_key'],
            $metadata,
        );
    }

    /** @throws AccessDeniedException */
    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }

        return $user->getUID();
    }
}
