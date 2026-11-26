<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * Where an app says that some Team folders are its own (v4.20.48).
 *
 * ```php
 * public function boot(IBootContext $context): void {
 *     try {
 *         $context->getServerContainer()
 *             ->get(\OCA\TeamHub\Integration\ITeamHubManagedFolderRegistry::class)
 *             ->registerProvider('myapp', MyManagedFolders::class);
 *     } catch (\Throwable $e) {
 *         // TeamHub is not installed, or is older than 4.20.48.
 *     }
 * }
 * ```
 *
 * Separate from `ITeamHubIntegrationRegistry` on purpose. A package is off
 * until an administrator enables it and adds it to templates, because it puts
 * something in front of people. A claim takes a question away and shows less,
 * so it works at once; an administrator can switch an app's claims off.
 *
 * In-process only. There is no HTTP route that reaches this, and the class
 * name is never stored: a claim exists for as long as your app registers it.
 */
interface ITeamHubManagedFolderRegistry {

    /**
     * Register on every boot. One provider per app; the last one wins.
     *
     * @param string $appId         your own app id
     * @param string $providerClass a class implementing ITeamHubManagedFolderProvider
     *
     * @throws ValidationException when the app id is empty, invalid or
     *         TeamHub's own, or the class does not implement the interface
     */
    public function registerProvider(string $appId, string $providerClass): void;
}
