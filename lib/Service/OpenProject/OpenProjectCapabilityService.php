<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCP\IUserSession;

/**
 * "Can this user use OpenProject from TeamHub, and for what" (v4.9.3).
 *
 * The answer is a flat, structured array — every flag a boolean the frontend
 * can branch on, plus one `errorCode` naming the first thing that is wrong,
 * in the order a person would fix them: module licensed → module switched on
 * (v4.9.16) → app installed → app enabled → host configured → user connected →
 * API reachable. Nothing in it is a secret:
 * the host is the URL users are sent to anyway, and the OpenProject user is
 * the name they see in OpenProject's own header.
 *
 * Two modes. Without `$probe` it is free — appconfig and preferences only,
 * safe to call on every page load. With `$probe` it asks OpenProject for
 * `users/me`, which is the only way to know that a stored connection still
 * works; that answer is cached briefly per user, and "Test connection"
 * bypasses the cache.
 *
 * `workPackageCreateAvailable` stays conservative: creation is a per-project
 * permission, answered per project by the overview. `provisioningAvailable`
 * (v4.9.6, Phase 2) is OpenProject's own answer to "may this user create
 * projects" — the project creation form offered or refused — asked only
 * when probing, and only after `users/me` succeeded.
 */
class OpenProjectCapabilityService {

    public function __construct(
        private OpenProjectClient              $client,
        private OpenProjectModuleService       $module,
        private OpenProjectCache               $cache,
        private OpenProjectMessages            $messages,
        private OpenProjectProvisioningService $provisioning,
        private IUserSession                   $userSession,
    ) {
    }

    /**
     * @return array{
     *   moduleLicensed: bool, moduleEnabled: bool, moduleAvailable: bool,
     *   integrationAppInstalled: bool, integrationAppEnabled: bool, hostConfigured: bool,
     *   host: ?string, authMethod: string, userConnected: bool, apiReachable: ?bool,
     *   projectReadAvailable: bool, workPackageReadAvailable: bool,
     *   workPackageCreateAvailable: bool, provisioningAvailable: bool,
     *   openProjectUser: ?array{id: ?int, name: string},
     *   errorCode: ?string, userMessage: ?string, administratorMessage: ?string,
     *   probed: bool, checkedAt: int
     * }
     */
    public function getCapabilities(bool $probe = false, bool $force = false): array {
        $user   = $this->userSession->getUser();
        $userId = $user?->getUID() ?? '';

        // v4.9.16 — the module's three facts travel beside the app's, and the
        // app's are reported raw (`isIntegrationAppEnabled()`): the wizard
        // hides its card on `moduleAvailable`, and an administrator reading
        // the envelope must be able to tell "module off" from "app off".
        $module    = $this->module->describe();
        $installed = $this->client->isIntegrationInstalled();
        $enabled   = $installed && $this->client->isIntegrationAppEnabled();
        $host      = $enabled ? $this->client->getHost() : '';

        $caps = [
            'moduleLicensed'             => $module['licensed'],
            'moduleEnabled'              => $module['enabled'],
            'moduleAvailable'            => $module['available'],
            'integrationAppInstalled'    => $installed,
            'integrationAppEnabled'      => $enabled,
            'hostConfigured'             => $host !== '',
            'host'                       => $host !== '' ? $host : null,
            'authMethod'                 => $this->client->getAuthMethod(),
            'userConnected'              => false,
            'apiReachable'               => null,
            'projectReadAvailable'       => false,
            'workPackageReadAvailable'   => false,
            'workPackageCreateAvailable' => false,
            'provisioningAvailable'      => false,
            'openProjectUser'            => null,
            'errorCode'                  => null,
            'userMessage'                => null,
            'administratorMessage'       => null,
            'probed'                     => false,
            'checkedAt'                  => time(),
        ];

        $problem = $this->client->compatibilityProblem();
        if ($problem !== null) {
            return $this->withError($caps, $problem);
        }
        if ($userId === '') {
            return $this->withError($caps, OpenProjectException::USER_NOT_CONNECTED);
        }

        $caps['userConnected'] = $this->client->isUserConnected($userId);
        if (!$caps['userConnected']) {
            return $this->withError($caps, OpenProjectException::USER_NOT_CONNECTED);
        }

        if (!$probe) {
            // Optimistic: connected as far as the stored state says. The
            // first real read will say otherwise if it must.
            $caps['projectReadAvailable']     = true;
            $caps['workPackageReadAvailable'] = true;
            return $caps;
        }

        $key = $this->cache->userKey('capabilities', $userId, $host);
        if (!$force) {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $caps['probed'] = true;
        try {
            $me = $this->client->get($userId, 'users/me');
            if (($me['_type'] ?? null) !== 'User') {
                throw new OpenProjectException(
                    OpenProjectException::UNSUPPORTED_RESPONSE,
                    'users/me did not return a User resource',
                );
            }
            $caps['apiReachable']             = true;
            $caps['projectReadAvailable']     = true;
            $caps['workPackageReadAvailable'] = true;
            $caps['openProjectUser']          = [
                'id'   => isset($me['id']) && is_numeric($me['id']) ? (int)$me['id'] : null,
                'name' => OpenProjectNormalizer::text((string)($me['name'] ?? '')),
            ];
            // v4.9.6 — may this user create projects. A failure of this
            // second probe alone leaves the reads available and the flag
            // false: not being able to ask is not being allowed.
            try {
                $caps['provisioningAvailable'] = $this->provisioning->canCreateProjects($userId, $force);
            } catch (OpenProjectException) {
                $caps['provisioningAvailable'] = false;
            }
        } catch (OpenProjectException $e) {
            $caps = $this->withError($caps, $e->getErrorCode());
            $caps['apiReachable'] = match ($e->getErrorCode()) {
                OpenProjectException::API_UNAVAILABLE,
                OpenProjectException::TEMPORARY_FAILURE => false,
                OpenProjectException::AUTH_FAILED,
                OpenProjectException::PERMISSION_DENIED,
                OpenProjectException::RATE_LIMITED,
                OpenProjectException::UNSUPPORTED_RESPONSE => true,
                default => null,
            };
            if ($e->getErrorCode() === OpenProjectException::AUTH_FAILED) {
                $caps['userConnected'] = false;
            }
        }

        $this->cache->set($key, $caps, OpenProjectCache::TTL_CAPABILITIES);
        return $caps;
    }

    /**
     * The capability array, or an exception when the integration cannot be
     * used for reads at all. What the data services call first.
     *
     * @throws OpenProjectException
     */
    public function requireReadable(): string {
        $problem = $this->client->compatibilityProblem();
        if ($problem !== null) {
            throw new OpenProjectException($problem, 'OpenProject integration is not usable: ' . $problem);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OpenProjectException(OpenProjectException::USER_NOT_CONNECTED, 'No user session');
        }
        return $user->getUID();
    }

    /** @param array<string, mixed> $caps */
    private function withError(array $caps, string $code): array {
        $caps['errorCode']            = $code;
        $caps['userMessage']          = $this->messages->userMessage($code);
        $caps['administratorMessage'] = $this->messages->administratorMessage($code);
        return $caps;
    }
}
