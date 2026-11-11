<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationEnablementMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationTemplateMapper;
use OCA\TeamHub\Db\IntegrationVisibilityMapper;
use OCA\TeamHub\Db\TeamTemplateMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Integration\CapabilityType;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Nextcloud administrator's half of integration governance (v4.13.23).
 *
 * Two decisions live here and nothing else does: **is this integration
 * enabled on this instance**, and **what happens if I turn it off**. Template
 * inclusion is {@see IntegrationTemplateService}; per-team presentation is
 * {@see IntegrationPresentationService}; whether something may run is
 * {@see IntegrationStateService}. Keeping them apart is what stops the four
 * governance levels collapsing back into one flag.
 *
 * ## Enabling grants nothing
 *
 * `enable()` writes one row and changes no template and no team. That is the
 * point: an administrator who enables an integration has said "this may be
 * used here", not "put this in front of everybody". The integration becomes
 * selectable in the template editor and does nothing until a template
 * includes it.
 *
 * ## Disabling deletes nothing
 *
 * `disable()` writes one row. Every capability stops operating everywhere —
 * widgets stop rendering, tabs disappear, My Work providers stop
 * contributing, lifecycle behaviour stops — and the template inclusions, the
 * per-team shown/hidden preferences, the ordering and any external mapping
 * all stay exactly where they are. Re-enabling restores the whole
 * configuration with no reconstruction, because nothing was taken apart.
 *
 * **Disabling is not deregistration and not uninstallation.** It does not
 * touch the owning app, does not ask it to deregister, and never deletes
 * anything the owning app owns.
 *
 * ## Why there is an impact preview
 *
 * A disable is one click that can empty a widget grid and a tab bar across
 * every team of several templates. {@see previewDisable()} is what turns that
 * into a decision: which templates, how many teams, which widgets and tabs go
 * away, which My Work sources stop — and the explicit statement that no
 * configuration and no external data is deleted, because the honest reason an
 * administrator hesitates is not knowing that.
 */
class IntegrationAdminService {

    /** How many teams the preview names per template before summarising. */
    public const PREVIEW_TEAM_LIMIT = 25;

    public function __construct(
        private IntegrationPackageMapper    $packages,
        private IntegrationCapabilityMapper $capabilities,
        private IntegrationEnablementMapper $enablement,
        private IntegrationTemplateMapper   $inclusions,
        private IntegrationVisibilityMapper $visibility,
        private TeamTemplateMapper          $templates,
        private TeamTypeMapper              $teamTypes,
        private IntegrationStateService     $stateService,
        private AuditService                $auditService,
        private IDBConnection               $db,
        private IUserSession                $userSession,
        private IGroupManager               $groupManager,
        private LoggerInterface             $logger,
    ) {}

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    /**
     * Every integration package on the instance, with its governance state.
     *
     * The administration screen's whole payload. A package whose owning app is
     * currently uninstalled or disabled is **still listed**, with its reason —
     * that is the difference between an administrator seeing "the owning app
     * is disabled" and seeing nothing at all and assuming the integration was
     * removed.
     *
     * @return array{integrations: list<array<string,mixed>>, teamhub_version: string}
     */
    public function listIntegrations(): array {
        $this->requireNcAdmin();

        $templateLabels = $this->templateLabels();
        $out            = [];

        foreach ($this->packages->findAll() as $pkgRow) {
            $pkgId        = (int)$pkgRow['id'];
            $availability = $this->stateService->availability($pkgRow);
            $capabilities = $this->capabilities->findByPackage($pkgId);

            $templateKeys = $this->inclusions->templateKeysForPackage($pkgId);

            $out[] = [
                'id'              => $pkgId,
                'app_id'          => (string)$pkgRow['app_id'],
                'integration_key' => (string)$pkgRow['integration_key'],
                'title'           => (string)$pkgRow['title'],
                'description'     => $pkgRow['description'],
                'icon'            => $pkgRow['icon'],
                'version'         => (string)$pkgRow['version'],
                'min_th_version'  => (string)$pkgRow['min_th_version'],
                'max_th_version'  => (string)$pkgRow['max_th_version'],
                'is_legacy'       => (bool)$pkgRow['is_legacy'],
                'last_seen_at'    => (int)$pkgRow['last_seen_at'],
                // The governance vocabulary verbatim. No bare `enabled`.
                'app_available'       => $availability['app_available'],
                'app_installed'       => $availability['app_installed'],
                'registered'          => $availability['registered'],
                'compatible'          => $availability['compatible'],
                'integration_enabled' => $availability['integration_enabled'],
                'template_eligible'   => $availability['template_eligible'],
                'unavailable_reason'  => $availability['unavailable_reason'],
                'capability_summary'  => $this->capabilitySummary($capabilities),
                // Titles and types only. A provider class is an
                // implementation detail of the owning app and naming it on a
                // settings page tells an administrator nothing they can act on.
                'capabilities'        => array_map(
                    static fn (array $c): array => [
                        'capability_key' => (string)$c['capability_key'],
                        'cap_type'       => (string)$c['cap_type'],
                        'title'          => (string)$c['title'],
                        'description'    => $c['description'],
                        'icon'           => $c['icon'],
                        'registered'     => (bool)$c['registered'],
                    ],
                    $capabilities,
                ),
                'templates' => array_map(
                    static fn (string $key): array => [
                        'template_key' => $key,
                        'label'        => $templateLabels[$key] ?? $key,
                    ],
                    $templateKeys,
                ),
            ];
        }

        return [
            'integrations' => $out,
            // Shown beside an incompatible package's declared range, so an
            // administrator can see what it is being compared against.
            'teamhub_version' => $this->teamHubVersion(),
        ];
    }

    /**
     * How many of each capability type, for the administrator's summary.
     *
     * Counts registered capabilities only: a capability the app no longer
     * declares is not something the integration supplies, even though its row
     * survives to hold per-team preferences.
     *
     * @param list<array<string,mixed>> $capabilities
     * @return array{widgets: int, menu_items: int, my_work_providers: int, other: int}
     */
    private function capabilitySummary(array $capabilities): array {
        $summary = ['widgets' => 0, 'menu_items' => 0, 'my_work_providers' => 0, 'other' => 0];

        foreach ($capabilities as $capability) {
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

    // ------------------------------------------------------------------
    // Enable / disable
    // ------------------------------------------------------------------

    /**
     * Enable an available integration.
     *
     * Refuses a package that is not available: enabling one whose owning app
     * is gone would record a decision that cannot take effect and would read,
     * on the screen, as an integration that is on and broken rather than one
     * whose app needs installing.
     *
     * @return array<string,mixed> the package's new state
     */
    public function enable(int $pkgId): array {
        $actor  = $this->requireNcAdmin();
        $pkgRow = $this->package($pkgId);

        $availability = $this->stateService->availability($pkgRow);
        if (!$availability['app_available']) {
            throw new ValidationException(
                $availability['app_installed']
                    ? 'The owning app is disabled. Enable it in Nextcloud\'s app settings first.'
                    : 'The owning app is not installed on this instance.',
            );
        }
        if (!$availability['registered']) {
            throw new ValidationException(
                'This integration has not registered with TeamHub in this process. '
                . 'Its owning app is enabled but did not register — check the Nextcloud log.',
            );
        }
        if (!$availability['compatible']) {
            throw new ValidationException(
                'This integration is not compatible with TeamHub ' . $this->teamHubVersion() . '.',
            );
        }

        $this->enablement->setEnabled($pkgId, true, $actor, time());
        $this->stateService->resetCaches();

        $this->audit($pkgRow, 'integration_enabled', $actor, [
            'app_id'          => (string)$pkgRow['app_id'],
            'integration_key' => (string)$pkgRow['integration_key'],
        ]);

        // Stated in the return so the frontend can say it without inventing
        // the claim: enabling changed no template and no team.
        return [
            'id'              => $pkgId,
            'state'           => $this->stateService->availability($pkgRow),
            'templates_added' => 0,
            'teams_changed'   => 0,
        ];
    }

    /**
     * Disable an integration.
     *
     * Writes one row. Nothing else changes, and nothing is deleted — see the
     * class docblock.
     *
     * @return array<string,mixed>
     */
    public function disable(int $pkgId): array {
        $actor  = $this->requireNcAdmin();
        $pkgRow = $this->package($pkgId);

        // Computed before the write, because this is what the audit record has
        // to carry: afterwards the capabilities are ineffective and a reader
        // cannot tell what stopped.
        $impact = $this->buildDisableImpact($pkgRow);

        $this->enablement->setEnabled($pkgId, false, $actor, time());
        $this->stateService->resetCaches();

        $this->audit($pkgRow, 'integration_disabled', $actor, [
            'app_id'              => (string)$pkgRow['app_id'],
            'integration_key'     => (string)$pkgRow['integration_key'],
            'templates_affected'  => count($impact['templates']),
            'teams_affected'      => $impact['teams_affected'],
            'widgets_hidden'      => count($impact['widgets']),
            'tabs_hidden'         => count($impact['menu_items']),
            'my_work_stopped'     => count($impact['my_work_providers']),
            // The record states it, because "did disabling delete anything"
            // is the question somebody will ask of this row later.
            'configuration_kept'  => true,
        ]);

        return [
            'id'     => $pkgId,
            'state'  => $this->stateService->availability($pkgRow),
            'impact' => $impact,
        ];
    }

    // ------------------------------------------------------------------
    // Impact preview
    // ------------------------------------------------------------------

    /**
     * What disabling this integration would do, before doing it.
     *
     * @return array<string,mixed>
     */
    public function previewDisable(int $pkgId): array {
        $this->requireNcAdmin();

        return $this->buildDisableImpact($this->package($pkgId));
    }

    /**
     * @param array<string,mixed> $pkgRow
     * @return array<string,mixed>
     */
    private function buildDisableImpact(array $pkgRow): array {
        $pkgId          = (int)$pkgRow['id'];
        $templateKeys   = $this->inclusions->templateKeysForPackage($pkgId);
        $templateLabels = $this->templateLabels();
        $capabilities   = array_values(array_filter(
            $this->capabilities->findByPackage($pkgId),
            static fn (array $c): bool => (bool)$c['registered'],
        ));

        $byType = [
            CapabilityType::WIDGET           => [],
            CapabilityType::MENU_ITEM        => [],
            CapabilityType::MY_WORK_PROVIDER => [],
        ];
        $other = [];
        foreach ($capabilities as $capability) {
            $capType = (string)$capability['cap_type'];
            $entry   = [
                'capability_key' => (string)$capability['capability_key'],
                'title'          => (string)$capability['title'],
                'icon'           => $capability['icon'],
            ];

            // For a visual capability, how many teams have already hidden it.
            // The honest answer to "will disabling this actually take
            // something off anybody's screen" — a widget every team already
            // hid is not an impact, and saying so stops the preview
            // overstating what is about to happen.
            if (CapabilityType::isVisual($capType)) {
                try {
                    $entry['teams_already_hiding'] = count($this->visibility->teamsHiding((int)$capability['id']));
                } catch (\Throwable $e) {
                    $this->logger->warning('[TeamHub][Integration] Could not count teams hiding a capability', [
                        'capability_key' => (string)$capability['capability_key'],
                        'exception' => $e, 'app' => Application::APP_ID,
                    ]);
                }
            }

            // v4.13.24 — a blank-canvas widget lands in the widget bucket.
            // The preview's job is to name what disappears from a team's
            // screen; "widget" is what the thing is called there, and a
            // separate "canvas widgets" heading would ask the administrator
            // to care which side of the frame TeamHub draws.
            $bucket = CapabilityType::isGridCard($capType) ? CapabilityType::WIDGET : $capType;
            if (array_key_exists($bucket, $byType)) {
                $byType[$bucket][] = $entry;
            } else {
                $other[] = $entry + ['cap_type' => $capType];
            }
        }

        $templates     = [];
        $teamsAffected = 0;
        foreach ($templateKeys as $templateKey) {
            $teamIds = $this->teamTypes->findTeamsByType($templateKey);
            $teamsAffected += count($teamIds);

            $named = array_slice($teamIds, 0, self::PREVIEW_TEAM_LIMIT);
            $names = $this->teamNames($named);

            $templates[] = [
                'template_key' => $templateKey,
                'label'        => $templateLabels[$templateKey] ?? $templateKey,
                'team_count'   => count($teamIds),
                'teams'        => array_map(
                    static fn (string $id): array => ['team_id' => $id, 'name' => $names[$id] ?? $id],
                    $named,
                ),
                'teams_truncated' => count($teamIds) > count($named),
            ];
        }

        return [
            'app_id'            => (string)$pkgRow['app_id'],
            'integration_key'   => (string)$pkgRow['integration_key'],
            'title'             => (string)$pkgRow['title'],
            'templates'         => $templates,
            'teams_affected'    => $teamsAffected,
            'widgets'           => $byType[CapabilityType::WIDGET],
            'menu_items'        => $byType[CapabilityType::MENU_ITEM],
            'my_work_providers' => $byType[CapabilityType::MY_WORK_PROVIDER],
            'other_capabilities' => $other,
            // Not decoration. These four are the facts that make the decision
            // reversible, and an administrator who does not know them treats a
            // disable as destructive and never uses it.
            'template_inclusions_kept'  => true,
            'team_preferences_kept'     => true,
            'ordering_kept'             => true,
            'external_data_kept'        => true,
        ];
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

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

    /** @return array<string,string> template_key => label */
    private function templateLabels(): array {
        $out = [];
        try {
            foreach ($this->templates->findAll() as $template) {
                $out[(string)$template['templateKey']] = (string)$template['label'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Integration] Could not read the team templates', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }

        return $out;
    }

    private function teamHubVersion(): string {
        return $this->stateService->teamHubVersion();
    }

    /**
     * Display names for a set of team ids.
     *
     * A direct read of `circles_circle`, the same as
     * `IntegrationRegisterService::teamNames()`, `MaintenanceController::listTeams()`
     * and `AuditController::exportTeam()`: an administrator's impact preview
     * has to name teams they are not a member of, which every gated reader
     * refuses by design.
     *
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function teamNames(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $out = [];
        try {
            foreach (array_chunk($teamIds, 500) as $chunk) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('unique_id', 'name', 'display_name')
                    ->from('circles_circle')
                    ->where($qb->expr()->in(
                        'unique_id',
                        $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY),
                    ));

                $result = $qb->executeQuery();
                while ($row = $result->fetch()) {
                    $name = (string)($row['display_name'] ?? '');
                    if ($name === '') {
                        $name = (string)($row['name'] ?? '');
                    }
                    $out[(string)$row['unique_id']] = $name;
                }
                $result->closeCursor();
            }
        } catch (\Throwable $e) {
            // A preview that shows team ids instead of names is still a
            // useful preview.
            $this->logger->warning('[TeamHub][Integration] Could not read team names for the impact preview', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }

        return $out;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(array $pkgRow, string $eventType, string $actor, array $metadata): void {
        // Instance scope: an integration being enabled or disabled is not one
        // team's event, and filing it against a team would hide it from the
        // only stream that should carry it.
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
