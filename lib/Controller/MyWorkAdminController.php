<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\MyWork\ActionType;
use OCA\TeamHub\MyWork\ProviderRegistry;
use OCA\TeamHub\Service\MyWorkConfigService;
use OCA\TeamHub\Service\MyWorkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Administrator endpoints for My Work (v4.5.21).
 *
 * **Every method in this class is instance-admin only.** That is enforced by
 * the absence of `#[NoAdminRequired]` — Nextcloud's App Framework requires an
 * admin session for any controller method that does not carry it. Kept in a
 * separate controller from `MyWorkController` precisely so that the gate is a
 * property of the file rather than of each individual method: adding a method
 * here cannot accidentally be member-callable.
 *
 * Deliberately **not** licence-gated. An administrator on an instance whose
 * licence has lapsed still needs to see why My Work is dark and to reconfigure
 * it before renewing; hiding the settings page behind the same gate as the
 * feature would be a trap.
 */
class MyWorkAdminController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private MyWorkConfigService $configService,
        private MyWorkService $myWorkService,
        private ProviderRegistry $registry,
        // v4.5.45 — for the Talk threading diagnostic on getStatus(). See the
        // comment there for why it lives on this endpoint.
        private \OCA\TeamHub\Service\TalkService $talkService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/admin/mywork/config
     *
     * Horizons, cache and budget settings, the effective category map, and the
     * shipped defaults so the UI can offer "reset to default" per row.
     */
    public function getConfig(): JSONResponse {
        try {
            return new JSONResponse($this->configService->getAdminConfig());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkAdmin] getConfig failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /api/v1/admin/mywork/config
     * Body: any subset of { upcomingDays, completedDays, cacheTtl, budgetMs,
     *                       approvalStaleDays, approvalWarnDays, categoryMap }
     *
     * Out-of-range numbers are clamped rather than rejected — the bounds are
     * published on the GET so the UI can constrain its inputs, and an admin who
     * types 10000 gets the maximum instead of a validation dead end.
     */
    public function saveConfig(): JSONResponse {
        $body = $this->request->getParams();
        unset($body['_route']);

        try {
            $this->configService->saveAdminConfig($body);
            return new JSONResponse($this->configService->getAdminConfig());
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkAdmin] saveConfig failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to save configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/v1/admin/mywork/status
     *
     * Every registered provider with its enabled flag, availability, the reason
     * it is unavailable if so, its capabilities, the actions currently
     * permitted, last successful sync, and last error.
     */
    public function getStatus(): JSONResponse {
        try {
            return new JSONResponse([
                'providers' => $this->myWorkService->describeProviders(),
                // Only the restrictable ones. Native actions touch no source
                // app and navigation actions only open a link the user could
                // already click — neither is a permission to withhold.
                'actions'   => array_values(array_diff(
                    ActionType::ALL, ActionType::NATIVE, ActionType::NAVIGATION,
                )),
                // v4.5.45 — what TeamHub can see of this Talk's thread API.
                //
                // Nothing to do with My Work; it is here because this is the
                // one admin-gated status endpoint that already exists, and a
                // diagnostic nobody can reach is not a diagnostic. Decision
                // proposals shared with the whole team try to open a thread,
                // and TeamHub was written against an API it could not test —
                // this reports the real method signatures on the instance.
                'talkThreading' => $this->talkService->getThreadingDiagnostics(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkAdmin] getStatus failed', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to load provider status'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /api/v1/admin/mywork/providers/{providerId}
     * Body: { enabled?: bool, actions?: string[] }
     *
     * `actions` is the allow-list of source-mutating actions. Native actions
     * (snooze, follow) are not restrictable and are silently dropped from the
     * submitted list — they touch no source application, so there is nothing
     * for an administrator to govern.
     */
    public function saveProvider(string $providerId): JSONResponse {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $providerId)) {
            return new JSONResponse(['error' => 'Invalid providerId'], Http::STATUS_BAD_REQUEST);
        }

        $provider = $this->registry->get($providerId);
        if ($provider === null) {
            return new JSONResponse(['error' => 'Unknown provider'], Http::STATUS_NOT_FOUND);
        }

        try {
            $enabled = $this->request->getParam('enabled', null);
            if ($enabled !== null) {
                $val = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($val === null) {
                    return new JSONResponse(['error' => 'enabled must be a boolean'], Http::STATUS_BAD_REQUEST);
                }
                $this->configService->setProviderEnabled($providerId, $val);
            }

            $actions = $this->request->getParam('actions', null);
            if ($actions !== null) {
                if (!is_array($actions)) {
                    return new JSONResponse(['error' => 'actions must be an array'], Http::STATUS_BAD_REQUEST);
                }
                $this->configService->setAllowedActions($providerId, $actions);
            }

            return new JSONResponse([
                'id'             => $providerId,
                'enabled'        => $this->configService->isProviderEnabled($providerId),
                'allowedActions' => $this->configService->getAllowedActions($providerId, $provider),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][MyWorkAdmin] saveProvider failed', [
                'provider' => $providerId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return new JSONResponse(['error' => 'Failed to save provider'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
