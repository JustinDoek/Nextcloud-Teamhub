<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationEnablementMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationTemplateMapper;
use OCA\TeamHub\Db\IntegrationVisibilityMapper;
use OCA\TeamHub\Db\TeamTypeMapper;
use OCA\TeamHub\Integration\CapabilityType;
use OCA\TeamHub\Integration\EffectiveState;
use OCA\TeamHub\Integration\IntegrationRegistry;
use Psr\Log\LoggerInterface;

/**
 * The one place that answers "may this integration run here" (v4.13.23).
 *
 * ## Why it is one place
 *
 * Six conditions decide whether a package is effective, and a seventh decides
 * whether a visual capability is on screen. Those conditions have to be
 * consulted by widget rendering, iframe metadata, My Work, the presentation
 * API, the template services, the administration screens and every frontend
 * payload. Seven copies of a seven-term conjunction is seven chances to drop
 * a term — and the term that gets dropped is always the one that only matters
 * in an unusual state, which is exactly when it matters.
 *
 * So nothing else computes this. Callers take an {@see EffectiveState} and
 * read it.
 *
 * ## The two questions, and why they are different methods
 *
 * {@see EffectiveState::isEffective()} is the six conditions. It is what a My
 * Work provider, a lifecycle hook and every future non-visual capability are
 * gated on. {@see EffectiveState::mayRender()} is those six **plus** the team
 * administrator's shown/hidden preference, and only a widget or an iframe tab
 * asks it.
 *
 * That split is the governance model's central promise made structural: a
 * team administrator hiding a widget changes `mayRender()` and cannot change
 * `isEffective()`, so it cannot stop My Work, cannot stop synchronisation and
 * cannot stop a lifecycle hook. There is no code path by which a visibility
 * row reaches a non-visual capability, because
 * {@see CapabilityType::isVisual()} gates the only place one is read.
 *
 * ## What is read live and what is read once
 *
 * App availability comes from Nextcloud's app manager on every call — a
 * stored copy would be wrong for however long it took something to refresh
 * it, and "the owning app was disabled a minute ago" is precisely the case
 * the availability level exists for. Registration comes from the in-process
 * {@see IntegrationRegistry}, not from the database, because the question is
 * whether the app registered *this request*.
 *
 * Everything else is a stored administrative decision, and those are cached
 * per request: the enablement map once, each template's inclusions once, each
 * team's template once. A workspace render asks about every package on the
 * instance and must not cost a query per package per question.
 *
 * ## Access verification
 *
 * `userMayAccessTeam` is a real condition and this service will establish it
 * itself. But most callers have already established it — a controller that
 * called `MemberService::requireMemberLevel()` three lines earlier, or My
 * Work, whose `WorkQuery::$teamIds` *is* the resolved membership set. Those
 * pass `accessAlreadyVerified: true` and the service takes their word, which
 * is sound because they proved it with the same `MemberService` this class
 * would have used. A caller that has not proved it omits the flag and pays
 * for the check. The flag is named for what it asserts so that a caller
 * passing it without having done the work is visibly wrong at the call site.
 */
class IntegrationStateService {

    /** @var array<int,bool>|null pkg_id => enabled, read once per request */
    private ?array $enablementCache = null;

    /** @var array<string,list<int>> template_key => pkg_ids */
    private array $inclusionCache = [];

    /** @var array<string,string> team_id => template_key ('' when none) */
    private array $templateCache = [];

    /** @var array<string,array<int,array{hidden: bool, sort_order: int}>> team_id => cap_id => preference */
    private array $visibilityCache = [];

    /** @var array<string,bool> "team_id\0user_id" => may access */
    private array $accessCache = [];

    public function __construct(
        private IntegrationPackageMapper    $packages,
        private IntegrationCapabilityMapper $capabilities,
        private IntegrationEnablementMapper $enablement,
        private IntegrationTemplateMapper   $inclusions,
        private IntegrationVisibilityMapper $visibility,
        private TeamTypeMapper              $teamTypes,
        private IntegrationPackageService   $packageService,
        private IntegrationRegistry         $registry,
        private MemberService               $memberService,
        private LoggerInterface             $logger,
    ) {}

    // ------------------------------------------------------------------
    // Instance level — no team involved
    // ------------------------------------------------------------------

    /**
     * The instance-level state of one package: availability, registration,
     * compatibility and the administrator's decision.
     *
     * What the administration screen renders. There is no team in it and
     * therefore no `included_in_template` and no `shown_for_team` — a package
     * is not "effective" at instance level, it is available and enabled or it
     * is not.
     *
     * @param array<string,mixed> $pkgRow as {@see IntegrationPackageMapper} hydrates it
     * @return array<string,mixed>
     */
    public function availability(array $pkgRow): array {
        $appId          = (string)$pkgRow['app_id'];
        $integrationKey = (string)$pkgRow['integration_key'];

        $appAvailable = $this->packageService->isAppAvailable($appId);
        $appInstalled = $appAvailable || $this->packageService->isOnDisk($appId);
        $registered   = $this->registry->isRegistered($appId, $integrationKey);
        $compatible   = $this->packageService->isCompatible(
            (string)($pkgRow['min_th_version'] ?? ''),
            (string)($pkgRow['max_th_version'] ?? ''),
        );
        $enabled = $this->isEnabled((int)$pkgRow['id']);

        return [
            'app_available'       => $appAvailable,
            'app_installed'       => $appInstalled,
            'registered'          => $registered,
            'compatible'          => $compatible,
            'integration_enabled' => $enabled,
            // "Could an administrator put this in a template right now?" —
            // available, registered, compatible and enabled. Deliberately
            // separate from `integration_enabled`: a disabled package that is
            // otherwise fine is selectable the moment it is enabled, and one
            // whose app is gone is not selectable whatever the administrator
            // does.
            'template_eligible'   => $appAvailable && $registered && $compatible && $enabled,
            'unavailable_reason'  => $this->instanceReason($appAvailable, $appInstalled, $registered, $compatible, $enabled),
        ];
    }

    /**
     * The first condition that fails, at instance level.
     *
     * One reason rather than a list: an administrator fixes them in this
     * order anyway, and a row that reports four problems at once reads as
     * broken rather than as "install the app".
     */
    private function instanceReason(
        bool $appAvailable,
        bool $appInstalled,
        bool $registered,
        bool $compatible,
        bool $enabled,
    ): ?string {
        if (!$appAvailable) {
            return $appInstalled
                ? EffectiveState::REASON_APP_DISABLED
                : EffectiveState::REASON_APP_NOT_INSTALLED;
        }
        if (!$registered) {
            return EffectiveState::REASON_NOT_REGISTERED;
        }
        if (!$compatible) {
            return EffectiveState::REASON_INCOMPATIBLE;
        }
        if (!$enabled) {
            return EffectiveState::REASON_NOT_ENABLED;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Team level
    // ------------------------------------------------------------------

    /**
     * The full state of one package for one team and one viewer.
     *
     * @param array<string,mixed> $pkgRow
     */
    public function forTeam(
        array  $pkgRow,
        string $teamId,
        string $userId,
        bool   $accessAlreadyVerified = false,
    ): EffectiveState {
        $appId          = (string)$pkgRow['app_id'];
        $integrationKey = (string)$pkgRow['integration_key'];
        $pkgId          = (int)$pkgRow['id'];

        $appAvailable = $this->packageService->isAppAvailable($appId);
        $appInstalled = $appAvailable || $this->packageService->isOnDisk($appId);
        $registered   = $this->registry->isRegistered($appId, $integrationKey);
        $compatible   = $this->packageService->isCompatible(
            (string)($pkgRow['min_th_version'] ?? ''),
            (string)($pkgRow['max_th_version'] ?? ''),
        );
        $enabled      = $this->isEnabled($pkgId);
        $templateKey  = $this->templateKeyFor($teamId);
        $included     = $templateKey !== '' && in_array($pkgId, $this->packageIdsFor($templateKey), true);
        $mayAccess    = $accessAlreadyVerified || $this->userMayAccessTeam($teamId, $userId);

        return new EffectiveState(
            appId:              $appId,
            integrationKey:     $integrationKey,
            appAvailable:       $appAvailable,
            appInstalled:       $appInstalled,
            registered:         $registered,
            compatible:         $compatible,
            integrationEnabled: $enabled,
            includedInTemplate: $included,
            userMayAccessTeam:  $mayAccess,
            // Package level: a package is shown unless a capability says
            // otherwise. The per-capability answer is stamped on by
            // EffectiveState::withShownForTeam().
            shownForTeam:       true,
            unavailableReason:  $this->teamReason(
                $appAvailable, $appInstalled, $registered, $compatible,
                $enabled, $templateKey, $included, $mayAccess,
            ),
            templateKey:        $templateKey,
        );
    }

    private function teamReason(
        bool   $appAvailable,
        bool   $appInstalled,
        bool   $registered,
        bool   $compatible,
        bool   $enabled,
        string $templateKey,
        bool   $included,
        bool   $mayAccess,
    ): ?string {
        $instance = $this->instanceReason($appAvailable, $appInstalled, $registered, $compatible, $enabled);
        if ($instance !== null) {
            return $instance;
        }
        if (!$mayAccess) {
            return EffectiveState::REASON_NO_TEAM_ACCESS;
        }
        // A team with no template row cannot inherit an inclusion from
        // anywhere. Reported as its own reason rather than as "not included",
        // because the fix is different: a team created before templates
        // existed needs adopting, not a template edit.
        if ($templateKey === '') {
            return EffectiveState::REASON_NO_TEMPLATE;
        }
        if (!$included) {
            return EffectiveState::REASON_NOT_IN_TEMPLATE;
        }

        return null;
    }

    /**
     * Every package's state for one team, keyed by package id.
     *
     * The batch a workspace render and the Manage Team presentation screen
     * both want. Reads the enablement map once, the team's template once and
     * its inclusions once, whatever the number of packages.
     *
     * @return array<int,EffectiveState>
     */
    public function forTeamBatch(
        string $teamId,
        string $userId,
        bool   $accessAlreadyVerified = false,
    ): array {
        $out = [];
        foreach ($this->packages->findAll() as $pkgRow) {
            $out[(int)$pkgRow['id']] = $this->forTeam($pkgRow, $teamId, $userId, $accessAlreadyVerified);
        }

        return $out;
    }

    /**
     * The packages that are effective for one team, with their rows.
     *
     * @return list<array{package: array<string,mixed>, state: EffectiveState}>
     */
    public function effectivePackagesForTeam(
        string $teamId,
        string $userId,
        bool   $accessAlreadyVerified = false,
    ): array {
        $out = [];
        foreach ($this->packages->findAll() as $pkgRow) {
            $state = $this->forTeam($pkgRow, $teamId, $userId, $accessAlreadyVerified);
            if ($state->isEffective()) {
                $out[] = ['package' => $pkgRow, 'state' => $state];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Capability visibility
    // ------------------------------------------------------------------

    /**
     * Is this capability shown for this team?
     *
     * **Always true for a non-visual capability.** Not as a convenience — as
     * the enforcement point. A My Work capability has no visual state, so
     * asking whether it is shown is a category error, and answering anything
     * but true would let a stray visibility row silence somebody's queue.
     * Nothing writes such a row (the presentation service refuses), and if one
     * existed anyway it would be ignored here.
     *
     * A missing row means shown. That is what makes a newly registered widget
     * appear by itself for every team whose template already includes its
     * package.
     *
     * @param array<string,mixed> $capRow as {@see IntegrationCapabilityMapper} hydrates it
     */
    public function isCapabilityShown(string $teamId, array $capRow): bool {
        if (!CapabilityType::isVisual((string)$capRow['cap_type'])) {
            return true;
        }

        $preferences = $this->visibilityFor($teamId);
        $preference  = $preferences[(int)$capRow['id']] ?? null;

        return $preference === null || !$preference['hidden'];
    }

    /**
     * This team's effective order for one capability.
     *
     * The team's preference when it has expressed one, the capability's
     * `default_order` otherwise — which is how a capability registered after
     * a team arranged its others lands in the position its app intended
     * rather than at the top.
     *
     * @param array<string,mixed> $capRow
     */
    public function capabilityOrder(string $teamId, array $capRow): int {
        $preferences = $this->visibilityFor($teamId);
        $preference  = $preferences[(int)$capRow['id']] ?? null;

        if ($preference === null || $preference['sort_order'] === IntegrationVisibilityMapper::NO_ORDER) {
            return (int)($capRow['default_order'] ?? 0);
        }

        return $preference['sort_order'];
    }

    /**
     * The capabilities of one effective package, each with the team's
     * presentation applied, sorted as the team will see them.
     *
     * Visual capabilities carry a per-capability {@see EffectiveState} whose
     * `shownForTeam` is the team's answer; non-visual ones carry the package
     * state unchanged.
     *
     * @param array<string,mixed> $pkgRow
     * @return list<array{capability: array<string,mixed>, state: EffectiveState}>
     */
    public function capabilitiesForTeam(array $pkgRow, string $teamId, EffectiveState $packageState): array {
        $rows = array_values(array_filter(
            $this->capabilities->findByPackage((int)$pkgRow['id']),
            static fn (array $row): bool => (bool)$row['registered'],
        ));

        $out = [];
        foreach ($rows as $row) {
            $shown = $this->isCapabilityShown($teamId, $row);
            $out[] = [
                'capability' => $row + ['effective_order' => $this->capabilityOrder($teamId, $row)],
                'state'      => $packageState->withShownForTeam($shown),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $typeCompare = strcmp((string)$a['capability']['cap_type'], (string)$b['capability']['cap_type']);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }
            $orderCompare = $a['capability']['effective_order'] <=> $b['capability']['effective_order'];
            if ($orderCompare !== 0) {
                return $orderCompare;
            }

            // Stable tiebreaker: two capabilities with the same order must
            // come back in the same sequence on every request.
            return strcmp((string)$a['capability']['capability_key'], (string)$b['capability']['capability_key']);
        });

        return $out;
    }

    // ------------------------------------------------------------------
    // My Work
    // ------------------------------------------------------------------

    /**
     * Of these teams, the ones where this package is effective.
     *
     * The filter `PackageWorkProvider` applies to `WorkQuery::$teamIds` before
     * delegating. The candidate list is the viewer's resolved membership set,
     * so access is already established and this adds the four governance
     * levels on top of it — never the visual one, which does not apply to a
     * My Work capability at all.
     *
     * @param list<string> $candidateTeamIds
     * @return list<string>
     */
    public function effectiveTeamIds(int $pkgId, array $candidateTeamIds): array {
        $candidateTeamIds = array_values(array_unique(array_filter(
            array_map('strval', $candidateTeamIds),
            static fn (string $id): bool => $id !== '',
        )));
        if ($candidateTeamIds === []) {
            return [];
        }

        $pkgRow = $this->packages->findById($pkgId);
        if ($pkgRow === null) {
            return [];
        }

        // The package-level conditions are the same for every team; resolve
        // them once rather than per team.
        $availability = $this->availability($pkgRow);
        if (!$availability['template_eligible']) {
            return [];
        }

        $templates = $this->templateKeysForTeams($candidateTeamIds);

        $out = [];
        foreach ($candidateTeamIds as $teamId) {
            $templateKey = $templates[$teamId] ?? '';
            if ($templateKey === '') {
                continue;
            }
            if (in_array($pkgId, $this->packageIdsFor($templateKey), true)) {
                $out[] = $teamId;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Cached reads
    // ------------------------------------------------------------------

    private function isEnabled(int $pkgId): bool {
        if ($this->enablementCache === null) {
            $this->enablementCache = [];
            try {
                foreach ($this->enablement->findAll() as $id => $row) {
                    $this->enablementCache[$id] = (bool)$row['enabled'];
                }
            } catch (\Throwable $e) {
                // An unreadable enablement table means no package is enabled.
                // Failing closed is the only safe direction: the alternative
                // is every registered integration running because a query
                // failed.
                $this->logger->error('[TeamHub][Integration] Could not read integration enablement', [
                    'error_class' => get_class($e), 'exception' => $e, 'app' => Application::APP_ID,
                ]);
            }
        }

        return $this->enablementCache[$pkgId] ?? false;
    }

    /** @return list<int> */
    private function packageIdsFor(string $templateKey): array {
        if (!array_key_exists($templateKey, $this->inclusionCache)) {
            try {
                $this->inclusionCache[$templateKey] = $this->inclusions->packageIdsForTemplate($templateKey);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][Integration] Could not read template inclusions', [
                    'template_key' => $templateKey, 'error_class' => get_class($e),
                    'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $this->inclusionCache[$templateKey] = [];
            }
        }

        return $this->inclusionCache[$templateKey];
    }

    /** The team's template key, or '' when it has no row. */
    private function templateKeyFor(string $teamId): string {
        if (!array_key_exists($teamId, $this->templateCache)) {
            try {
                $this->templateCache[$teamId] = (string)($this->teamTypes->findTypeByTeam($teamId) ?? '');
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][Integration] Could not read a team\'s template', [
                    'team_id' => $teamId, 'error_class' => get_class($e),
                    'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $this->templateCache[$teamId] = '';
            }
        }

        return $this->templateCache[$teamId];
    }

    /**
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function templateKeysForTeams(array $teamIds): array {
        $missing = array_values(array_filter(
            $teamIds,
            fn (string $id): bool => !array_key_exists($id, $this->templateCache),
        ));

        if ($missing !== []) {
            try {
                $found = $this->teamTypes->findTypesByTeams($missing);
            } catch (\Throwable $e) {
                $this->logger->error('[TeamHub][Integration] Could not read team templates', [
                    'error_class' => get_class($e), 'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $found = [];
            }
            foreach ($missing as $teamId) {
                $this->templateCache[$teamId] = (string)($found[$teamId] ?? '');
            }
        }

        $out = [];
        foreach ($teamIds as $teamId) {
            $out[$teamId] = $this->templateCache[$teamId] ?? '';
        }

        return $out;
    }

    /** @return array<int,array{hidden: bool, sort_order: int}> */
    private function visibilityFor(string $teamId): array {
        if (!array_key_exists($teamId, $this->visibilityCache)) {
            try {
                $this->visibilityCache[$teamId] = $this->visibility->findForTeam($teamId);
            } catch (\Throwable $e) {
                // An unreadable preference table means no preferences, which
                // means everything shown. Failing open is right here and
                // failing closed is right for enablement: a lost layout
                // preference is a cosmetic regression, a lost governance
                // decision is a security one.
                $this->logger->error('[TeamHub][Integration] Could not read team presentation preferences', [
                    'team_id' => $teamId, 'error_class' => get_class($e),
                    'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $this->visibilityCache[$teamId] = [];
            }
        }

        return $this->visibilityCache[$teamId];
    }

    /**
     * Is this user entitled to see this team?
     *
     * Uses the same `MemberService` gate every controller uses, so there is
     * one definition of membership in the app. Cached per request because a
     * workspace render asks it once per package and the answer cannot change
     * mid-request.
     */
    private function userMayAccessTeam(string $teamId, string $userId): bool {
        $key = $teamId . "\0" . $userId;
        if (array_key_exists($key, $this->accessCache)) {
            return $this->accessCache[$key];
        }

        $allowed = false;
        try {
            $this->memberService->requireMemberLevel($teamId);
            $allowed = true;
        } catch (\Throwable) {
            // Not a member, not a TeamHub team, or no session. All three mean
            // the same thing here, and none of them is worth a log line on a
            // path that is reached legitimately whenever somebody opens a team
            // they have just left.
            $allowed = false;
        }

        $this->accessCache[$key] = $allowed;

        return $allowed;
    }

    /**
     * The running TeamHub version, for a screen that shows what an
     * incompatible package's declared range was compared against.
     */
    public function teamHubVersion(): string {
        return $this->packageService->teamHubVersion();
    }

    /**
     * Forget everything cached for one request.
     *
     * For the services that change a governance decision and then immediately
     * report its effect — an enable, a template inclusion, a reconciliation.
     * Without this they would report the state they read before their own
     * write.
     */
    public function resetCaches(): void {
        $this->enablementCache = null;
        $this->inclusionCache  = [];
        $this->templateCache   = [];
        $this->visibilityCache = [];
        $this->accessCache     = [];
    }
}
