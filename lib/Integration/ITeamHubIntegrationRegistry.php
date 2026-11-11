<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * TeamHub's public registration API for installed Nextcloud apps (v4.13.23).
 *
 * Resolve it from the server container in your own `Application::boot()` and
 * register one package describing your whole TeamHub integration:
 *
 * ```php
 * public function boot(IBootContext $context): void {
 *     try {
 *         $registry = $context->getServerContainer()->get(
 *             \OCA\TeamHub\Integration\ITeamHubIntegrationRegistry::class,
 *         );
 *
 *         $registry->registerPackage(
 *             new IntegrationPackageDefinition(
 *                 appId:          'myapp',
 *                 integrationKey: 'collaboration',
 *                 title:          'My App',
 *                 description:    'Team collaboration around My App',
 *                 icon:           'ViewDashboard',
 *                 version:        '1.0.0',
 *                 capabilities:   [
 *                     new WidgetCapabilityDefinition(
 *                         key:           'overview',
 *                         title:         'Overview',
 *                         providerClass: TeamOverviewWidget::class,
 *                     ),
 *                     new WidgetCapabilityDefinition(
 *                         key:           'open-items',
 *                         title:         'Open items',
 *                         providerClass: OpenItemsWidget::class,
 *                     ),
 *                     new MyWorkCapabilityDefinition(
 *                         key:           'assigned-work',
 *                         title:         'My App',
 *                         providerClass: MyAppWorkProvider::class,
 *                     ),
 *                     new MenuItemCapabilityDefinition(
 *                         key:       'team-view',
 *                         title:     'My App',
 *                         iframeUrl: '/apps/myapp/team-view',
 *                     ),
 *                 ],
 *             ),
 *             calledInProcess: true,
 *         );
 *     } catch (\Throwable $e) {
 *         // TeamHub is not installed, or is older than this package needs.
 *         // Never let that break your own app's boot.
 *     }
 * }
 * ```
 *
 * ## Registering is not being switched on
 *
 * A successful registration means TeamHub knows your package exists and what
 * is in it. It does **not** put it in front of anybody. A newly discovered
 * package is disabled; a Nextcloud administrator enables it, and then
 * includes it in the team templates that should have it. That is deliberate:
 * installing an app must never change what any existing team looks like.
 *
 * ## Idempotent
 *
 * Re-registering the same `app_id + integration_key` updates the stored
 * metadata and the capability rows. It does not create a duplicate, does not
 * touch the administrator's enablement, does not remove template inclusions
 * and does not reset per-team shown/hidden preferences or ordering. So
 * registering on every boot — which is what you should do — is free.
 *
 * ## `calledInProcess`, and why a provider class can only arrive this way
 *
 * A capability carrying a `providerClass` names a PHP class TeamHub will
 * resolve from the DI container and call. Accepting that over HTTP would mean
 * an authenticated request could choose which class TeamHub instantiates, so
 * it is refused: `registerPackage()` throws unless `calledInProcess` is true,
 * and the HTTP registration route cannot set it. Only server-side PHP running
 * in the same process can register a provider.
 */
interface ITeamHubIntegrationRegistry {

    /**
     * Register or update one integration package.
     *
     * Validated before anything is stored:
     *
     *   - `appId` names an installed **and enabled** Nextcloud app;
     *   - `appId` is not TeamHub itself and not one of the four built-in
     *     app ids TeamHub handles natively;
     *   - capability keys are unique within the package;
     *   - each `providerClass` exists and implements the interface its
     *     capability type requires;
     *   - each `iframeUrl` is a relative Nextcloud path or `https://`;
     *   - the package's declared TeamHub range admits this TeamHub.
     *
     * A package outside its declared TeamHub range is **stored and marked
     * incompatible** rather than refused, so an administrator sees why it is
     * not working instead of seeing nothing.
     *
     * @param bool $calledInProcess must be true; see the class docblock
     *
     * @throws ValidationException when the definition or the caller is invalid
     */
    public function registerPackage(
        IntegrationPackageDefinition $package,
        bool $calledInProcess = false,
    ): void;

    /**
     * Withdraw a package's registration.
     *
     * The package stops operating everywhere. Nothing is deleted: the
     * administrator's enablement, the template inclusions, the per-team
     * shown/hidden preferences, the ordering and any external mapping all
     * stay, so registering the same identity again restores the integration
     * exactly as it was.
     *
     * Call this only for a deliberate withdrawal. An app being disabled needs
     * no call at all — TeamHub reads app availability live.
     *
     * @throws ValidationException
     */
    public function deregisterPackage(
        string $appId,
        string $integrationKey,
        bool $calledInProcess = false,
    ): void;

    /**
     * Every package registered in this process, keyed `app_id/integration_key`.
     *
     * @return array<string, IntegrationPackageDefinition>
     */
    public function registeredPackages(): array;

    /**
     * One registered package, or null when nothing registered that identity
     * in this process.
     */
    public function registeredPackage(string $appId, string $integrationKey): ?IntegrationPackageDefinition;
}
