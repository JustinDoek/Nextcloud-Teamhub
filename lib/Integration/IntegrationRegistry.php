<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\IntegrationPackageService;
use Psr\Log\LoggerInterface;

/**
 * The in-process integration registry (v4.13.23).
 *
 * Registered as a singleton, so the definitions apps hand it during `boot()`
 * are still there when a controller asks later in the same request. That
 * matters: the in-memory map is what `registered` *means*. An app that is
 * installed and enabled but whose boot never reached `registerPackage()` is
 * absent here, and absent here is a distinct state from disabled — the old
 * model could not tell those apart and an administrator had no way to see the
 * difference.
 *
 * The database row is the durable shadow of this map. It exists so the
 * administration screen can name a package whose app is currently disabled
 * (and therefore registers nothing), and so every per-team preference has a
 * stable `cap_id` to point at. Nothing reads the row to decide whether a
 * package is registered *now*; that is this object's question.
 *
 * ## Why registration is never fatal
 *
 * `registerPackage()` throws a {@see ValidationException} for a definition it
 * will not accept, because the registering app's author needs to see the
 * mistake. But the app is expected to wrap the call in `try/catch` — the
 * documented pattern — so a TeamHub that is absent, older or unhappy cannot
 * break somebody else's boot.
 *
 * On TeamHub's side the same rule applies in the other direction: a
 * persistence failure during one app's registration is logged and swallowed,
 * never rethrown past the in-memory record. One app with a broken integration
 * must not take down the boot of every other app on the instance.
 */
class IntegrationRegistry implements ITeamHubIntegrationRegistry {

    /** @var array<string, IntegrationPackageDefinition> keyed `app_id/integration_key` */
    private array $packages = [];

    /**
     * Identities whose persistence failed this request.
     *
     * Kept so the registry does not report a package as registered when
     * nothing downstream can resolve its capability ids.
     *
     * @var array<string, true>
     */
    private array $unpersisted = [];

    public function __construct(
        private IntegrationPackageService $packageService,
        private LoggerInterface           $logger,
    ) {}

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    public function registerPackage(
        IntegrationPackageDefinition $package,
        bool $calledInProcess = false,
    ): void {
        // The single rule that keeps provider classes trustworthy. A
        // capability carrying a `providerClass` names a PHP class TeamHub will
        // instantiate; if an HTTP request could supply it, an authenticated
        // administrator — or anything that reached an administrator's session —
        // would be choosing what TeamHub executes. The HTTP route cannot set
        // this flag, so only server-side PHP in the same process gets here.
        if (!$calledInProcess) {
            throw new ValidationException(
                'An integration package must be registered in-process from your app\'s '
                . 'Application::boot(), with calledInProcess: true. TeamHub does not accept '
                . 'package registration over HTTP, because a package names provider classes.',
            );
        }

        $this->packageService->validate($package);

        $identity = $this->identity($package->appId, $package->integrationKey);

        // Last registration of an identity wins, consistent with
        // ProviderRegistry::register(). An app that registers twice in one
        // boot meant the second one.
        $this->packages[$identity] = $package;
        unset($this->unpersisted[$identity]);

        try {
            $this->packageService->persist($package);
        } catch (\Throwable $e) {
            // Logged with the identity and nothing of the package's own data.
            $this->unpersisted[$identity] = true;
            $this->logger->error('[TeamHub][Integration] Could not store a registered package', [
                'app_id'          => $package->appId,
                'integration_key' => $package->integrationKey,
                'error_class'     => get_class($e),
                'exception'       => $e,
                'app'             => Application::APP_ID,
            ]);
        }
    }

    public function deregisterPackage(
        string $appId,
        string $integrationKey,
        bool $calledInProcess = false,
    ): void {
        if (!$calledInProcess) {
            throw new ValidationException(
                'Deregistration must be called in-process from your own app, with calledInProcess: true.',
            );
        }

        $identity = $this->identity($appId, $integrationKey);
        unset($this->packages[$identity], $this->unpersisted[$identity]);

        try {
            $this->packageService->withdraw($appId, $integrationKey);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][Integration] Could not withdraw a package registration', [
                'app_id'          => $appId,
                'integration_key' => $integrationKey,
                'error_class'     => get_class($e),
                'exception'       => $e,
                'app'             => Application::APP_ID,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /** @return array<string, IntegrationPackageDefinition> */
    public function registeredPackages(): array {
        return array_diff_key($this->packages, $this->unpersisted);
    }

    public function registeredPackage(string $appId, string $integrationKey): ?IntegrationPackageDefinition {
        $identity = $this->identity($appId, $integrationKey);
        if (isset($this->unpersisted[$identity])) {
            return null;
        }

        return $this->packages[$identity] ?? null;
    }

    /**
     * Did this identity register in this process?
     *
     * The `registered` condition of the effective-state calculation, and the
     * reason that calculation does not read the database for it.
     */
    public function isRegistered(string $appId, string $integrationKey): bool {
        return $this->registeredPackage($appId, $integrationKey) !== null;
    }

    // ------------------------------------------------------------------
    // Compatibility
    // ------------------------------------------------------------------

    /**
     * Record a package TeamHub built for itself from a legacy registration.
     *
     * **Only for {@see \OCA\TeamHub\Service\LegacyIntegrationAdapter}.** It
     * bypasses the `calledInProcess` requirement, which is safe here and
     * nowhere else: the definition was assembled by TeamHub from the
     * arguments the legacy `registerIntegration()` call already validated, not
     * handed over by a caller. A legacy registration carrying a provider class
     * can only have arrived in-process — the HTTP route refuses one — so no
     * class name reaches this method from a request.
     *
     * Marked `is_legacy` in the database so an administrator can see which
     * integrations are still on the old API.
     */
    public function adoptLegacyPackage(IntegrationPackageDefinition $package): void {
        $identity = $this->identity($package->appId, $package->integrationKey);

        $this->packages[$identity] = $package;
        unset($this->unpersisted[$identity]);

        try {
            $this->packageService->persist($package, isLegacy: true);
        } catch (\Throwable $e) {
            $this->unpersisted[$identity] = true;
            $this->logger->error('[TeamHub][Integration] Could not store an adapted legacy package', [
                'app_id'          => $package->appId,
                'integration_key' => $package->integrationKey,
                'error_class'     => get_class($e),
                'exception'       => $e,
                'app'             => Application::APP_ID,
            ]);
        }
    }

    /**
     * One registered capability definition, or null.
     *
     * Lets a surface that is about to call a provider confirm, against the
     * live registration rather than a stored row, that the capability it is
     * about to invoke is one the app still declares.
     */
    public function registeredCapability(
        string $appId,
        string $integrationKey,
        string $capabilityKey,
    ): ?CapabilityDefinition {
        $package = $this->registeredPackage($appId, $integrationKey);
        if ($package === null) {
            return null;
        }
        foreach ($package->capabilities as $capability) {
            if ($capability->key === $capabilityKey) {
                return $capability;
            }
        }

        return null;
    }

    private function identity(string $appId, string $integrationKey): string {
        return $appId . '/' . $integrationKey;
    }
}
