<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\ValidationException;

/**
 * The in-memory list of apps that claim Team folders (v4.20.48).
 *
 * Registered as a shared service in `Application::register()`, for the same
 * reason as `IntegrationRegistry`: what apps hand it during boot() has to be
 * there when discovery asks later in the request. No dependencies and no
 * database, so registering is free. Asking is `ManagedFolderService`'s job.
 */
class ManagedFolderRegistry implements ITeamHubManagedFolderRegistry {

    /** Nextcloud's own app id length, which also fits `decided_by`. */
    private const APP_ID_PATTERN = '/^[a-z][a-z0-9_\-]{0,31}$/i';

    /** @var array<string, class-string<ITeamHubManagedFolderProvider>> app id => provider class */
    private array $providers = [];

    public function registerProvider(string $appId, string $providerClass): void {
        if (preg_match(self::APP_ID_PATTERN, $appId) !== 1) {
            throw new ValidationException('A managed folder provider needs a valid Nextcloud app id.');
        }
        if ($appId === Application::APP_ID) {
            throw new ValidationException('TeamHub does not claim folders through this registry.');
        }
        if (!is_subclass_of($providerClass, ITeamHubManagedFolderProvider::class)) {
            throw new ValidationException(
                'The managed folder provider of "' . $appId . '" must implement ' . ITeamHubManagedFolderProvider::class . '.',
            );
        }
        $this->providers[$appId] = $providerClass;
    }

    /** @return array<string, class-string<ITeamHubManagedFolderProvider>> */
    public function providers(): array {
        return $this->providers;
    }
}
