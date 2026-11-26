<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Integration\ManagedFolderRegistry;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The administrator's side of claimed Team folders (v4.20.48): which apps
 * claim folders, and switching one off. A full Nextcloud administrator only,
 * as in `SharedResourceProviderAdminService`.
 */
class ManagedFolderAdminService {

    public function __construct(
        private ManagedFolderRegistry $registry,
        private ManagedFolderSettings $settings,
        private ManagedFolderService  $managedFolders,
        private IUserSession          $userSession,
        private IGroupManager         $groupManager,
    ) {}

    /**
     * Every app that claims folders, plus any switched-off app that no longer
     * registers (so it can be switched back on).
     *
     * @return array{apps: list<array{id: string, name: string, enabled: bool, registered: bool}>, unreadable: bool}
     *
     * @throws AccessDeniedException
     */
    public function listApps(): array {
        $this->requireNcAdmin();

        $disabled   = $this->settings->disabledProviderIds();
        $unreadable = $disabled === null;
        $registered = array_map('strval', array_keys($this->registry->providers()));

        $apps = [];
        foreach (array_unique([...$registered, ...($disabled ?? [])]) as $id) {
            $apps[] = [
                'id'         => $id,
                'name'       => $this->managedFolders->appName($id),
                'enabled'    => !$unreadable && !in_array($id, $disabled ?? [], true),
                'registered' => in_array($id, $registered, true),
            ];
        }
        usort($apps, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['apps' => $apps, 'unreadable' => $unreadable];
    }

    /**
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException for an id that cannot be an app id
     */
    public function setEnabled(string $appId, bool $enabled): void {
        $this->requireNcAdmin();
        $this->settings->setEnabled($appId, $enabled);
    }

    /** @throws AccessDeniedException */
    private function requireNcAdmin(): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }
    }
}
