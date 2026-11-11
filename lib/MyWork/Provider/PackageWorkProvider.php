<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork\Provider;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Integration\EffectiveState;
use OCA\TeamHub\MyWork\ActionResult;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCA\TeamHub\MyWork\WorkItem;
use OCA\TeamHub\MyWork\WorkItemPage;
use OCA\TeamHub\MyWork\WorkQuery;
use OCA\TeamHub\Service\IntegrationStateService;
use Psr\Log\LoggerInterface;

/**
 * An integration package's My Work source, governed (v4.13.23).
 *
 * ## Why a wrapper rather than a second My Work system
 *
 * The external app writes an ordinary {@see IWorkProvider} — the same
 * interface the eleven built-in providers implement, the same `WorkItem`
 * model, the same action pairing. It knows nothing about templates,
 * enablement or team-level presentation. This wrapper is the only thing that
 * does, and it sits between the registry and the delegate:
 *
 *   ProviderRegistry → PackageWorkProvider → the app's IWorkProvider
 *
 * Everything My Work already gives a source — grouping, filtering, sorting,
 * snoozing, the action handling, the wall-clock budget, the per-provider fault
 * isolation — applies unchanged, because as far as `MyWorkService` is
 * concerned this *is* a provider.
 *
 * ## What the wrapper adds
 *
 * **Team narrowing.** `fetchItems()` replaces the query's team set with the
 * teams where this package is effective, then delegates. So a team whose
 * template no longer includes the package contributes nothing, and the
 * delegate did not have to be told. Narrowing the query rather than filtering
 * the result also lets the delegate push the constraint into its own SQL.
 *
 * **The governance gate, minus one level.** The four levels plus registration
 * and compatibility, and deliberately **not** the team administrator's
 * shown/hidden preference. Hiding a widget is a layout decision and must never
 * silence somebody's queue; {@see IntegrationStateService::effectiveTeamIds()}
 * is `isEffective()`, never `mayRender()`, and that is where the promise is
 * kept.
 *
 * **A stable, namespaced source id.** `<app_id>_<capability_key>`, assigned by
 * TeamHub. An app cannot choose it, so it cannot claim `deck` and shadow a
 * built-in provider — and because it never changes, a snoozed item and an
 * administrator's per-source configuration survive the app being disabled and
 * re-enabled.
 *
 * **Failure isolation, twice.** `ProviderRegistry::fetchAll()` already catches
 * a provider that throws. This catches the delegate separately so the log line
 * names the package identity rather than just the wrapper's id, and so an
 * availability check that explodes reads as unavailable rather than taking the
 * registry's own try/catch.
 */
class PackageWorkProvider implements IWorkProvider {

    /** @var list<string>|null resolved once per request */
    private ?array $effectiveTeamCache = null;

    /**
     * @param array<string,mixed> $packageRow    as IntegrationPackageMapper hydrates it
     * @param array<string,mixed> $capabilityRow as IntegrationCapabilityMapper hydrates it
     */
    public function __construct(
        private IWorkProvider           $delegate,
        private array                   $packageRow,
        private array                   $capabilityRow,
        private string                  $sourceKey,
        private IntegrationStateService $stateService,
        private LoggerInterface         $logger,
    ) {}

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    /**
     * The stable source id.
     *
     * Persisted in the snooze table, in administrator configuration and in
     * filter URLs, so it must never change once shipped — which is why
     * TeamHub assigns it from the stable identity rather than letting the app
     * supply one.
     */
    public function getId(): string {
        return $this->sourceKey;
    }

    /** The capability's title — already translated by the registering app. */
    public function getName(): string {
        $title = trim((string)$this->capabilityRow['title']);
        if ($title !== '') {
            return $title;
        }

        return (string)$this->packageRow['title'];
    }

    public function getIcon(): string {
        $icon = (string)($this->capabilityRow['icon'] ?? '');
        if ($icon !== '') {
            return $icon;
        }
        $packageIcon = (string)($this->packageRow['icon'] ?? '');

        // `Puzzle` is the integration default throughout TeamHub, and a source
        // with no icon at all renders as a gap in the filter list.
        return $packageIcon !== '' ? $packageIcon : 'Puzzle';
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    /**
     * Is this source usable at all right now?
     *
     * Two conditions. The package has to be instance-level usable — app
     * available, registered, compatible, administrator-enabled — and the
     * delegate has to say it is available. Template inclusion is deliberately
     * **not** checked here: `isAvailable()` has no team in it, and a package
     * that is in no template yet is correctly described as available-but-
     * contributing-nothing rather than unavailable. The per-team answer is
     * `fetchItems()`'s job.
     *
     * Never throws. A delegate whose availability check explodes is not a
     * delegate we are going to ask for data.
     */
    public function isAvailable(): bool {
        if (!$this->packageUsable()) {
            return false;
        }

        try {
            return $this->delegate->isAvailable();
        } catch (\Throwable $e) {
            $this->logFailure('isAvailable threw', $e);

            return false;
        }
    }

    public function getUnavailableReason(): ?string {
        $availability = $this->availability();
        if (!$availability['template_eligible']) {
            return match ($availability['unavailable_reason']) {
                EffectiveState::REASON_APP_NOT_INSTALLED => 'The owning app is not installed.',
                EffectiveState::REASON_APP_DISABLED      => 'The owning app is disabled.',
                EffectiveState::REASON_NOT_REGISTERED    => 'The integration did not register with TeamHub.',
                EffectiveState::REASON_INCOMPATIBLE      => 'The integration is not compatible with this version of TeamHub.',
                EffectiveState::REASON_NOT_ENABLED       => 'A Nextcloud administrator has not enabled this integration.',
                default                                  => 'This integration is not available.',
            };
        }

        try {
            return $this->delegate->getUnavailableReason();
        } catch (\Throwable $e) {
            $this->logFailure('getUnavailableReason threw', $e);

            return 'This integration could not report its state.';
        }
    }

    // ------------------------------------------------------------------
    // Fetch
    // ------------------------------------------------------------------

    /**
     * The viewer's work from this source, in the teams where the package is
     * effective.
     *
     * The query's team set is already the viewer's resolved membership —
     * `MyWorkService` computed it — so narrowing it against the package's
     * effective teams composes membership with governance without this class
     * resolving membership itself.
     */
    public function fetchItems(WorkQuery $query): WorkItemPage {
        $teamIds = $this->effectiveTeamIds($query->teamIds);
        if ($teamIds === []) {
            // Not an error and not a warning: a viewer none of whose teams
            // have this integration has nothing from it, and saying so with an
            // empty page is the contract's "never throw for nothing to do".
            return WorkItemPage::empty();
        }

        try {
            return $this->delegate->fetchItems($query->withTeamIds($teamIds));
        } catch (\Throwable $e) {
            $this->logFailure('fetchItems threw', $e);

            // Rethrown so ProviderRegistry marks this one source `error` and
            // shows the non-blocking notice. Swallowing it here would show the
            // viewer an empty queue, which for My Work is the worst possible
            // lie — see IWorkProvider's rule 3.
            throw $e;
        }
    }

    /**
     * One item, re-read from source. The authorisation path before any action.
     *
     * The allowed-team list is narrowed the same way, so an item in a team the
     * package is no longer effective for cannot be re-read and therefore
     * cannot be acted on.
     *
     * @param string[] $allowedTeamIds
     */
    public function getItem(string $userId, string $providerItemId, array $allowedTeamIds): ?WorkItem {
        $teamIds = $this->effectiveTeamIds($allowedTeamIds);
        if ($teamIds === []) {
            return null;
        }

        try {
            return $this->delegate->getItem($userId, $providerItemId, $teamIds);
        } catch (\Throwable $e) {
            $this->logFailure('getItem threw', $e);

            return null;
        }
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * What this user may do on this item right now.
     *
     * An item whose team is no longer effective offers no actions at all —
     * the row may still be on a cached page, and a template change must take
     * its controls away rather than leaving them to fail.
     *
     * @return string[]
     */
    public function getAvailableActions(string $userId, WorkItem $item): array {
        if (!$this->itemTeamIsEffective($item)) {
            return [];
        }

        try {
            return $this->delegate->getAvailableActions($userId, $item);
        } catch (\Throwable $e) {
            $this->logFailure('getAvailableActions threw', $e);

            return [];
        }
    }

    /**
     * Perform the action against the external app.
     *
     * Re-checks the item's team, because the action list the frontend holds is
     * a rendering hint and nothing more — the same rule the interface states
     * for the delegate, applied to the governance layer.
     *
     * @param array<string,mixed> $params
     */
    public function executeAction(string $userId, WorkItem $item, string $action, array $params): ActionResult {
        if (!$this->itemTeamIsEffective($item)) {
            return ActionResult::forbidden('This integration is no longer active for that team.');
        }

        try {
            return $this->delegate->executeAction($userId, $item, $action, $params);
        } catch (\Throwable $e) {
            $this->logFailure('executeAction threw', $e);

            // The delegate's message is not passed through: it may name
            // internals of the owning app, and the viewer can do nothing with
            // it.
            return ActionResult::failure('The action could not be completed.');
        }
    }

    // ------------------------------------------------------------------
    // Descriptors
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function getCapabilities(): array {
        try {
            $capabilities = $this->delegate->getCapabilities();

            return is_array($capabilities) ? $capabilities : [];
        } catch (\Throwable $e) {
            $this->logFailure('getCapabilities threw', $e);

            return [];
        }
    }

    /** @return string[] */
    public function getSupportedFilters(): array {
        try {
            return $this->delegate->getSupportedFilters();
        } catch (\Throwable $e) {
            $this->logFailure('getSupportedFilters threw', $e);

            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function getConfigSchema(): array {
        try {
            return $this->delegate->getConfigSchema();
        } catch (\Throwable $e) {
            $this->logFailure('getConfigSchema threw', $e);

            return [];
        }
    }

    /**
     * Diagnostics for the administrator's My Work status page.
     *
     * Not part of {@see IWorkProvider} — `ProviderRegistry` finds it with
     * `method_exists`. Reports the package's own governance state, which is
     * the first thing to check when an integration's queue is empty, plus the
     * delegate's own diagnostics when it has any.
     *
     * @return array<int,array{label: string, value: string}>
     */
    public function getDiagnostics(): array {
        $availability = $this->availability();

        $out = [
            ['label' => 'Integration', 'value' => (string)$this->packageRow['app_id'] . '/' . (string)$this->packageRow['integration_key']],
            ['label' => 'Owning app available', 'value' => $availability['app_available'] ? 'yes' : 'no'],
            ['label' => 'Registered', 'value' => $availability['registered'] ? 'yes' : 'no'],
            ['label' => 'Compatible', 'value' => $availability['compatible'] ? 'yes' : 'no'],
            ['label' => 'Enabled by administrator', 'value' => $availability['integration_enabled'] ? 'yes' : 'no'],
        ];

        if (method_exists($this->delegate, 'getDiagnostics')) {
            try {
                foreach ((array)$this->delegate->getDiagnostics() as $row) {
                    if (is_array($row) && isset($row['label'], $row['value'])) {
                        $out[] = ['label' => (string)$row['label'], 'value' => (string)$row['value']];
                    }
                }
            } catch (\Throwable $e) {
                $out[] = ['label' => 'Integration diagnostics', 'value' => get_class($e)];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Governance
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function availability(): array {
        return $this->stateService->availability($this->packageRow);
    }

    private function packageUsable(): bool {
        return $this->availability()['template_eligible'];
    }

    /**
     * Of these teams, the ones where this package is effective.
     *
     * Cached for the request: `fetchItems()` and then `getAvailableActions()`
     * for each row would otherwise re-resolve the same template lookups per
     * item.
     *
     * @param string[] $candidates
     * @return list<string>
     */
    private function effectiveTeamIds(array $candidates): array {
        $candidates = array_values(array_map('strval', $candidates));

        if ($this->effectiveTeamCache !== null) {
            return array_values(array_intersect($this->effectiveTeamCache, $candidates));
        }

        try {
            $effective = $this->stateService->effectiveTeamIds((int)$this->packageRow['id'], $candidates);
        } catch (\Throwable $e) {
            // Failing closed: a governance question that cannot be answered
            // must not be answered "yes".
            $this->logFailure('effective team resolution failed', $e);

            return [];
        }

        $this->effectiveTeamCache = $effective;

        return $effective;
    }

    private function itemTeamIsEffective(WorkItem $item): bool {
        if ($item->teamId === '') {
            return false;
        }

        return $this->effectiveTeamIds([$item->teamId]) !== [];
    }

    private function logFailure(string $what, \Throwable $e): void {
        // Identity, capability and error class. Never the provider's items —
        // a work queue is full of somebody's task titles.
        $this->logger->error('[TeamHub][MyWork] Integration provider failed: ' . $what, [
            'app_id'          => (string)$this->packageRow['app_id'],
            'integration_key' => (string)$this->packageRow['integration_key'],
            'capability_key'  => (string)$this->capabilityRow['capability_key'],
            'source'          => $this->sourceKey,
            'error_class'     => get_class($e),
            'exception'       => $e,
            'app'             => Application::APP_ID,
        ]);
    }
}
