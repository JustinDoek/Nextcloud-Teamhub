<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Teams\ITeamManager;
use OCP\Teams\ITeamResourceProvider;
use Psr\Log\LoggerInterface;

/**
 * The administrator's side of the per-provider switch (v4.20.40): which team
 * resource providers exist, which are switched off, and switching one.
 *
 * Every method requires a full Nextcloud administrator (`requireNcAdmin()`),
 * as `IntegrationAdminService` does: what every team on the instance shows is
 * not a delegated decision. Read path and guarantees only; the admin settings
 * UI is a later step.
 */
class SharedResourceProviderAdminService {

    public function __construct(
        private ITeamManager                   $teamManager,
        private SharedResourceProviderSettings $settings,
        private IUserSession                   $userSession,
        private IGroupManager                  $groupManager,
        private LoggerInterface                $logger,
    ) {}

    /**
     * Every registered provider except TeamHub's own, plus any switched-off id
     * that is no longer registered (so it can be switched back on).
     *
     * @return array{providers: list<array{id: string, name: string, enabled: bool, registered: bool}>, unreadable: bool}
     *
     * @throws AccessDeniedException
     */
    public function listProviders(): array {
        $this->requireNcAdmin();

        $disabled   = $this->settings->disabledProviderIds();
        $unreadable = $disabled === null;
        $disabled   = $disabled ?? [];

        $rows = [];
        $providers = [];
        try {
            $providers = $this->teamManager->hasTeamSupport() ? $this->teamManager->getProviders() : [];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SharedResourceProviderAdminService] provider list failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        foreach ($providers as $provider) {
            if (!$provider instanceof ITeamResourceProvider) {
                continue;
            }
            try {
                $id   = $provider->getId();
                $name = $provider->getName();
            } catch (\Throwable $e) {
                continue;
            }
            if ($id === Application::APP_ID || isset($rows[$id])) {
                continue;
            }
            $rows[$id] = [
                'id'         => $id,
                'name'       => $name !== '' ? $name : $id,
                'enabled'    => !$unreadable && !in_array($id, $disabled, true),
                'registered' => true,
            ];
        }
        foreach ($disabled as $id) {
            if (!isset($rows[$id])) {
                $rows[$id] = ['id' => $id, 'name' => $id, 'enabled' => false, 'registered' => false];
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['providers' => $rows, 'unreadable' => $unreadable];
    }

    /**
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException for an invalid id, or TeamHub's own
     */
    public function setEnabled(string $providerId, bool $enabled): void {
        $this->requireNcAdmin();
        if ($providerId === Application::APP_ID) {
            throw new \InvalidArgumentException('TeamHub\'s own provider is never shown in TeamHub');
        }
        $this->settings->setEnabled($providerId, $enabled);
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
