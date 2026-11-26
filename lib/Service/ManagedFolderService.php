<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Integration\ITeamHubManagedFolderProvider;
use OCA\TeamHub\Integration\ManagedFolderRegistry;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asks the apps that registered for it which Team folders are theirs
 * (v4.20.48, DESIGN §2.283).
 *
 * One answer for every caller: discovery, accept, connect and the team-space
 * conversion. Each app is asked in its own try/catch, an administrator's
 * switch is honoured before the app is called, and the limits on a claim are
 * applied here rather than by the callers:
 *
 *  - never a folder TeamHub made, for any team (`origin = teamhub_create`);
 *  - never the team's own team space;
 *  - only ids that were asked about.
 *
 * A claim never widens anything. It grants no access and changes no share; it
 * only decides whether a team admin is asked to review the folder. It is not
 * a way to hide one either: Manage team lists every claimed folder with the
 * app's name.
 */
class ManagedFolderService {

    public function __construct(
        private ManagedFolderRegistry $registry,
        private ManagedFolderSettings $settings,
        private TeamAppResourceMapper $resourceMapper,
        private TeamSpaceService      $teamSpaceService,
        private IAppManager           $appManager,
        private IUserSession          $userSession,
        private ContainerInterface    $container,
        private LoggerInterface       $logger,
    ) {}

    /** Does any app register claims in this request? Cheap, for the fast path. */
    public function hasProviders(): bool {
        return $this->registry->providers() !== [];
    }

    /**
     * @param list<int> $folderIds
     * @return array{claims: array<int, string>, unanswered: list<string>}
     *         `claims` maps folder id to app id; `unanswered` lists apps that
     *         are registered and switched on but failed to answer
     */
    public function claims(string $teamId, array $folderIds): array {
        $asked = [];
        foreach ($folderIds as $id) {
            if ((int)$id > 0) {
                $asked[(int)$id] = true;
            }
        }
        if ($asked === []) {
            return ['claims' => [], 'unanswered' => []];
        }

        $claims     = [];
        $unanswered = [];
        foreach ($this->registry->providers() as $appId => $providerClass) {
            $appId = (string)$appId;
            if (!$this->settings->isEnabled($appId)) {
                continue;
            }
            try {
                $provider = $this->container->get($providerClass);
                if (!$provider instanceof ITeamHubManagedFolderProvider) {
                    throw new \RuntimeException('not an ITeamHubManagedFolderProvider');
                }
                $answer = $provider->filterManagedFolders(array_keys($asked));
            } catch (\Throwable $e) {
                $unanswered[] = $appId;
                $this->logger->warning('[TeamHub][ManagedFolderService] app did not answer which folders it manages', [
                    'claimingApp' => $appId, 'error_class' => get_class($e), 'error' => $e->getMessage(),
                    'app' => Application::APP_ID,
                ]);
                continue;
            }
            foreach ($answer as $id) {
                // Ids nobody asked about are ignored; the first app to claim a
                // folder keeps it. Some databases hand ids back as strings.
                $id = is_numeric($id) ? (int)$id : 0;
                if (isset($asked[$id])) {
                    $claims[$id] ??= $appId;
                }
            }
        }

        if ($claims !== []) {
            foreach ($this->notClaimable($teamId, array_keys($claims)) as $id) {
                unset($claims[$id]);
            }
        }

        return ['claims' => $claims, 'unanswered' => $unanswered];
    }

    /** The app managing one folder, or null. Null too when the app cannot answer. */
    public function managingApp(string $teamId, int $folderId): ?string {
        return $this->claims($teamId, [$folderId])['claims'][$folderId] ?? null;
    }

    /**
     * Should a folder stay managed by this app although the app did not claim
     * it just now?
     *
     * Yes when the app could not answer. Yes also when it did not register in
     * a request made by a person: an app limited to certain groups is not
     * booted for anybody else, and one viewer outside the group must not send
     * the folder back to review for everybody. The hourly job has no user and
     * boots every enabled app, so an app that really stopped registering is
     * noticed there. No when an administrator switched it off or the app is
     * disabled.
     *
     * @param list<string> $unanswered from claims()
     */
    public function keepsWithoutAnswer(string $appId, array $unanswered): bool {
        if (!$this->settings->isEnabled($appId)) {
            return false;
        }
        if (in_array($appId, $unanswered, true)) {
            return true;
        }
        if (isset($this->registry->providers()[$appId])) {
            return false;
        }
        return $this->userSession->getUser() !== null && $this->appManager->isInstalled($appId);
    }

    /** The app's own name, or its id. */
    public function appName(string $appId): string {
        try {
            $name = $this->appManager->getAppInfo($appId)['name'] ?? '';
            return is_string($name) && $name !== '' ? $name : $appId;
        } catch (\Throwable) {
            return $appId;
        }
    }

    /**
     * Claimed ids that are not an app's to claim. Fails closed: when a limit
     * cannot be checked, none of the claims stand and the folders stay where
     * they are.
     *
     * @param list<int> $folderIds
     * @return list<int>
     */
    private function notClaimable(string $teamId, array $folderIds): array {
        try {
            $refused     = [];
            $resourceIds = array_map(static fn (int $id): string => 'gf:' . $id, $folderIds);
            foreach ($this->resourceMapper->findTeamHubCreatedIds('files', $resourceIds) as $resourceId) {
                $refused[] = (int)substr($resourceId, 3);
            }
            if ($this->teamSpaceService->isAvailable()) {
                $space = $this->teamSpaceService->getTeamSpace($teamId);
                if ($space !== null) {
                    $refused[] = (int)$space['id'];
                }
            }
            return $refused;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][ManagedFolderService] claim limits could not be checked, no claim accepted', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return $folderIds;
        }
    }
}
