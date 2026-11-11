<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Integration\CapabilityType;
use OCA\TeamHub\Integration\IframeUrl;
use OCA\TeamHub\Integration\IntegrationRegistry;
use OCA\TeamHub\Integration\ITeamHubContextAware;
use OCA\TeamHub\Integration\ITeamHubWidget;
use OCA\TeamHub\Integration\TeamHubContextFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a package's widgets, in process and in isolation (v4.13.23).
 *
 * The package-model counterpart to `IntegrationService::fetchWidgetData()`,
 * which still serves the legacy `registryId` endpoints. Three things are new:
 * a package may have several widgets, each is addressed by its stable
 * capability key rather than a database id, and every call goes through
 * {@see IntegrationStateService} first.
 *
 * ## The response contract is unchanged
 *
 * The same `items` / `actions` shape, the same **20 items** and **10 actions**
 * limits, the same URL validation on an action, the same action-form field
 * types. Apps wrote against that contract and there is no reason to move it;
 * the limits are carried as constants here with the same values the legacy
 * service uses.
 *
 * ## Failure isolation
 *
 * Every provider call is wrapped. A widget that throws, cannot be
 * constructed, or returns nonsense yields an error shape for that one card —
 * the team home, the Activity view and every other widget are unaffected. The
 * log line carries app id, integration key, capability key, team id and error
 * class, and never the provider's data.
 *
 * ## In process, never over HTTP
 *
 * The provider is resolved from Nextcloud's DI container and called directly,
 * so it gets full constructor injection and TeamHub makes no loopback
 * request. The class name comes from the capability row, which only
 * in-process registration can write.
 */
class IntegrationWidgetService {

    /** Unchanged from the single-registration model. */
    public const MAX_ITEMS   = 20;
    public const MAX_ACTIONS = 10;

    /** Field types a native action form may use. */
    private const ALLOWED_FIELD_TYPES = ['text', 'textarea', 'email', 'checkbox', 'date'];

    public function __construct(
        private IntegrationPackageMapper    $packages,
        private IntegrationCapabilityMapper $capabilities,
        private IntegrationStateService     $stateService,
        private IntegrationRegistry         $registry,
        private TeamHubContextFactory       $contextFactory,
        private MemberService               $memberService,
        private ContainerInterface          $container,
        private LoggerInterface             $logger,
    ) {}

    // ------------------------------------------------------------------
    // Widget data
    // ------------------------------------------------------------------

    /**
     * One widget's data for one team.
     *
     * Never throws for a provider's fault — returns `['items' => [], 'error'
     * => …]` so one broken card cannot take the workspace down. It *does*
     * throw for a governance failure, because a caller asking for a widget the
     * team may not see is asking the wrong question and deserves a 403 rather
     * than an empty card.
     *
     * @return array<string,mixed>
     */
    public function widgetData(string $teamId, string $appId, string $integrationKey, string $capabilityKey): array {
        $resolved = $this->resolveRenderable($teamId, $appId, $integrationKey, $capabilityKey);
        $widget   = $resolved['provider'];
        $context  = $resolved['context'];

        try {
            // A widget that wants the verified context gets it; one that
            // implements only the original interface keeps the two-string
            // signature it was written against.
            $raw = $widget instanceof ITeamHubContextAware
                ? $widget->getWidgetDataFor($context)
                : $widget->getWidgetData($context->teamId, $context->userId);
        } catch (\Throwable $e) {
            $this->logProviderFailure('getWidgetData threw', $resolved, $e);

            return ['items' => [], 'error' => 'Widget data could not be loaded'];
        }

        if (!is_array($raw) || !isset($raw['items']) || !is_array($raw['items'])) {
            $this->logger->warning('[TeamHub][Integration] Widget returned an invalid response shape', [
                'app_id'          => $appId,
                'integration_key' => $integrationKey,
                'capability_key'  => $capabilityKey,
                'team_id'         => $teamId,
                'app'             => Application::APP_ID,
            ]);

            return ['items' => []];
        }

        $result = ['items' => $this->sanitiseItems($raw['items'])];

        $actions = $this->sanitiseActions($raw['actions'] ?? null);
        if ($actions !== []) {
            $result['actions'] = $actions;
        }

        return $result;
    }

    /**
     * Every shown widget's data for a team, in one call.
     *
     * What a workspace render wants: one request, one membership check, one
     * effective-state pass, and a per-widget error rather than a failed page.
     * A hidden widget's provider is never constructed, let alone called.
     *
     * @return list<array<string,mixed>>
     */
    public function allWidgetData(string $teamId): array {
        $this->memberService->requireMemberLevel($teamId);

        $out = [];
        foreach ($this->renderableWidgetRows($teamId) as $row) {
            $out[] = [
                'app_id'          => $row['app_id'],
                'integration_key' => $row['integration_key'],
                'capability_key'  => $row['capability_key'],
                'title'           => $row['title'],
                'icon'            => $row['icon'],
                'sort_order'      => $row['sort_order'],
                'data'            => $this->widgetData(
                    $teamId,
                    $row['app_id'],
                    $row['integration_key'],
                    $row['capability_key'],
                ),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Action forms
    // ------------------------------------------------------------------

    /**
     * The form definition for a named widget action.
     *
     * An empty `fields` array means "no native form" and the frontend falls
     * back to opening the action's URL, exactly as in the legacy model.
     *
     * @return array<string,mixed>
     */
    public function actionForm(
        string $teamId,
        string $appId,
        string $integrationKey,
        string $capabilityKey,
        string $actionId,
    ): array {
        if (trim($actionId) === '') {
            throw new ValidationException('actionId is required.');
        }

        $resolved = $this->resolveRenderable($teamId, $appId, $integrationKey, $capabilityKey);
        $widget   = $resolved['provider'];

        try {
            $form = $widget->getActionForm($actionId, $resolved['context']->teamId, $resolved['context']->userId);
        } catch (\Throwable $e) {
            $this->logProviderFailure('getActionForm threw', $resolved, $e);

            // A form that cannot be loaded falls back to the URL rather than
            // erroring: the action may well still work in a new tab.
            return ['fields' => []];
        }

        if (!is_array($form) || !isset($form['fields']) || !is_array($form['fields'])) {
            return ['fields' => []];
        }

        $fields = [];
        foreach ($form['fields'] as $field) {
            if (!is_array($field) || empty($field['name']) || empty($field['label']) || empty($field['type'])) {
                continue;
            }
            $sanitised = [
                'name'     => (string)$field['name'],
                'label'    => (string)$field['label'],
                'type'     => in_array($field['type'], self::ALLOWED_FIELD_TYPES, true) ? (string)$field['type'] : 'text',
                'required' => !empty($field['required']),
            ];
            if (isset($field['value'])) {
                $sanitised['value'] = is_scalar($field['value']) ? $field['value'] : null;
            }
            if (isset($field['placeholder'])) {
                $sanitised['placeholder'] = (string)$field['placeholder'];
            }
            $fields[] = $sanitised;
        }

        return [
            'title'        => isset($form['title']) ? (string)$form['title'] : null,
            'submit_label' => isset($form['submit_label']) ? (string)$form['submit_label'] : null,
            'fields'       => $fields,
        ];
    }

    /**
     * Submit a completed action form.
     *
     * TeamHub applies its own authorisation — membership, and the full
     * effective-state check — and then hands the provider scalar field values
     * only. Whether *this* user may perform *that* action on the provider's
     * own records is the provider's question; TeamHub has established who is
     * asking and about which team.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function submitAction(
        string $teamId,
        string $appId,
        string $integrationKey,
        string $capabilityKey,
        string $actionId,
        array  $fields,
    ): array {
        if (trim($actionId) === '') {
            throw new ValidationException('actionId is required.');
        }

        $resolved = $this->resolveRenderable($teamId, $appId, $integrationKey, $capabilityKey);
        $widget   = $resolved['provider'];

        // Scalars only. A nested array reaching a provider that expects a
        // string is the shape of a type-confusion bug in somebody else's code.
        $clean = [];
        foreach ($fields as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $clean[$key] = $value;
            }
        }

        try {
            $result = $widget->handleAction($actionId, $clean, $resolved['context']->teamId, $resolved['context']->userId);
        } catch (\Throwable $e) {
            $this->logProviderFailure('handleAction threw', $resolved, $e);

            // The provider's message is deliberately not passed through: it
            // may name internals, and the user can do nothing with it.
            return ['success' => false, 'message' => null, 'refresh' => false];
        }

        if (!is_array($result) || !isset($result['success'])) {
            return ['success' => false, 'message' => null, 'refresh' => false];
        }

        return [
            'success' => (bool)$result['success'],
            'message' => isset($result['message']) ? (string)$result['message'] : null,
            'refresh' => !empty($result['refresh']),
        ];
    }

    // ------------------------------------------------------------------
    // Resolution
    // ------------------------------------------------------------------

    /**
     * Membership, governance, registration and construction — in that order.
     *
     * @return array{provider: ITeamHubWidget, context: \OCA\TeamHub\Integration\TeamHubContext, app_id: string, integration_key: string, capability_key: string, team_id: string}
     *
     * @throws AccessDeniedException|NotFoundException
     */
    private function resolveRenderable(
        string $teamId,
        string $appId,
        string $integrationKey,
        string $capabilityKey,
    ): array {
        // 1. Membership. Before anything else, and before any provider class
        //    is even looked up.
        $this->memberService->requireMemberLevel($teamId);
        $userId = $this->contextFactory->forTeam($teamId, $appId, $integrationKey, $capabilityKey)->userId;

        // 2. The package and the capability.
        $pkgRow = $this->packages->findByIdentity($appId, $integrationKey);
        if ($pkgRow === null) {
            throw new NotFoundException('No such integration.');
        }
        $capability = $this->capabilities->findByKey((int)$pkgRow['id'], $capabilityKey);
        if ($capability === null) {
            throw new NotFoundException('No such integration capability.');
        }
        // Deliberately `!== WIDGET` and not `isGridCard()`: a blank-canvas
        // widget is a card on the same grid, but it has no provider class and
        // nothing here to call. Its body is a sandboxed frame the browser
        // fetches from the owning app, so asking this endpoint for its data is
        // a mistake worth naming rather than an empty 200. (v4.13.24)
        if ((string)$capability['cap_type'] === CapabilityType::WIDGET_IFRAME) {
            throw new NotFoundException(
                'That capability draws its own card in an iframe. It has no widget data to fetch.',
            );
        }
        if ((string)$capability['cap_type'] !== CapabilityType::WIDGET) {
            throw new NotFoundException('That capability is not a widget.');
        }

        // 3. Governance. `mayRender()`, not `isEffective()`: a hidden widget's
        //    provider must not be called even by a direct request, which is
        //    both the performance property and the "frontend filtering is not
        //    a boundary" rule.
        $state = $this->stateService->forTeam($pkgRow, $teamId, $userId, accessAlreadyVerified: true)
            ->withShownForTeam($this->stateService->isCapabilityShown($teamId, $capability));

        if (!$state->mayRender()) {
            throw new AccessDeniedException('This widget is not available for this team.');
        }

        // 4. The live registration. A capability row can outlive the app's
        //    declaration of it — rows are never deleted — so the row alone is
        //    not permission to call a class.
        if ($this->registry->registeredCapability($appId, $integrationKey, $capabilityKey) === null) {
            throw new AccessDeniedException('This integration is not registered in this process.');
        }

        $providerClass = (string)($capability['provider_class'] ?? '');
        if ($providerClass === '') {
            throw new NotFoundException('This widget has no provider configured.');
        }

        $context = $this->contextFactory->forTeam($teamId, $appId, $integrationKey, $capabilityKey);

        try {
            $provider = $this->container->get($providerClass);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][Integration] Widget provider could not be constructed', [
                'app_id'          => $appId,
                'integration_key' => $integrationKey,
                'capability_key'  => $capabilityKey,
                'team_id'         => $teamId,
                'error_class'     => get_class($e),
                'exception'       => $e,
                'app'             => Application::APP_ID,
            ]);
            throw new NotFoundException('This widget could not be loaded.');
        }

        if (!$provider instanceof ITeamHubWidget) {
            throw new NotFoundException('This widget does not implement the TeamHub widget contract.');
        }

        return [
            'provider'        => $provider,
            'context'         => $context,
            'app_id'          => $appId,
            'integration_key' => $integrationKey,
            'capability_key'  => $capabilityKey,
            'team_id'         => $teamId,
        ];
    }

    /**
     * The widget capability rows a team shows, in order.
     *
     * @return list<array<string,mixed>>
     */
    private function renderableWidgetRows(string $teamId): array {
        $userId = $this->contextFactory->forTeam($teamId, '', '', '')->userId;

        $out = [];
        foreach ($this->stateService->effectivePackagesForTeam($teamId, $userId, accessAlreadyVerified: true) as $entry) {
            $pkgRow = $entry['package'];

            foreach ($this->stateService->capabilitiesForTeam($pkgRow, $teamId, $entry['state']) as $row) {
                $capability = $row['capability'];
                // `!== WIDGET`, so a blank-canvas widget is skipped rather
                // than reported as an error card: it has no provider and
                // nothing to batch. The grid gets its URL from
                // `IntegrationPresentationService` instead. (v4.13.24)
                if ((string)$capability['cap_type'] !== CapabilityType::WIDGET) {
                    continue;
                }
                if (!$row['state']->mayRender()) {
                    continue;
                }

                $out[] = [
                    'app_id'          => (string)$pkgRow['app_id'],
                    'integration_key' => (string)$pkgRow['integration_key'],
                    'capability_key'  => (string)$capability['capability_key'],
                    'title'           => (string)$capability['title'],
                    'icon'            => $capability['icon'] ?? $pkgRow['icon'],
                    'sort_order'      => (int)$capability['effective_order'],
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int =>
            [$a['sort_order'], $a['app_id'], $a['capability_key']]
            <=> [$b['sort_order'], $b['app_id'], $b['capability_key']]);

        return $out;
    }

    // ------------------------------------------------------------------
    // Sanitisation
    // ------------------------------------------------------------------

    /**
     * @param array<mixed> $raw
     * @return list<array<string,mixed>>
     */
    private function sanitiseItems(array $raw): array {
        $items = [];
        foreach (array_slice($raw, 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? trim((string)$item['url']) : '';

            $items[] = [
                'label' => isset($item['label']) ? (string)$item['label'] : '',
                'value' => isset($item['value']) ? (string)$item['value'] : '',
                'icon'  => isset($item['icon']) ? (string)$item['icon'] : null,
                // An item's URL goes through the same rule as an iframe's. A
                // provider that returns `javascript:` for a list row is as
                // dangerous as one that registers it as a tab, and the legacy
                // path validated the actions but not the items.
                'url'   => ($url !== '' && IframeUrl::isAcceptable($url)) ? $url : null,
            ];
        }

        return $items;
    }

    /**
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function sanitiseActions(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }

        $actions = [];
        foreach (array_slice($raw, 0, self::MAX_ACTIONS) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $label = isset($action['label']) ? trim((string)$action['label']) : '';
            if ($label === '') {
                continue;
            }
            $actionId = isset($action['actionId']) ? trim((string)$action['actionId']) : '';

            if ($actionId !== '') {
                // A native-modal action. TeamHub never navigates to a URL for
                // one, so the optional url is carried only as a fallback and
                // is validated all the same.
                $url = isset($action['url']) ? trim((string)$action['url']) : '';
                $actions[] = [
                    'label'    => $label,
                    'icon'     => isset($action['icon']) ? (string)$action['icon'] : null,
                    'actionId' => $actionId,
                    'url'      => ($url !== '' && IframeUrl::isAcceptable($url)) ? $url : null,
                ];
                continue;
            }

            $url = isset($action['url']) ? trim((string)$action['url']) : '';
            if (!IframeUrl::isAcceptable($url)) {
                continue;
            }
            $actions[] = [
                'label' => $label,
                'icon'  => isset($action['icon']) ? (string)$action['icon'] : null,
                'url'   => $url,
            ];
        }

        return $actions;
    }

    /**
     * @param array{app_id: string, integration_key: string, capability_key: string, team_id: string} $resolved
     */
    private function logProviderFailure(string $what, array $resolved, \Throwable $e): void {
        // Identity, team, error class. Never the provider's payload — a widget
        // can be holding somebody's work-package titles and a log line is the
        // wrong place for them.
        $this->logger->error('[TeamHub][Integration] Widget provider failed: ' . $what, [
            'app_id'          => $resolved['app_id'],
            'integration_key' => $resolved['integration_key'],
            'capability_key'  => $resolved['capability_key'],
            'team_id'         => $resolved['team_id'],
            'error_class'     => get_class($e),
            'exception'       => $e,
            'app'             => Application::APP_ID,
        ]);
    }
}
