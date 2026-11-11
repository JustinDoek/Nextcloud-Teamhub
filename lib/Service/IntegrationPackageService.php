<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationCapabilityMapper;
use OCA\TeamHub\Db\IntegrationPackageMapper;
use OCA\TeamHub\Db\IntegrationRegistryMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Integration\CapabilityDefinition;
use OCA\TeamHub\Integration\CapabilityType;
use OCA\TeamHub\Integration\IframeUrl;
use OCA\TeamHub\Integration\IntegrationPackageDefinition;
use OCA\TeamHub\Integration\ITeamHubWidget;
use OCA\TeamHub\MyWork\IWorkProvider;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates and stores integration packages (v4.13.23).
 *
 * The write half of registration. {@see \OCA\TeamHub\Integration\IntegrationRegistry}
 * is the public front door and holds the in-memory view; this class decides
 * whether a definition is acceptable and makes the database agree with it.
 *
 * ## Validation is not availability
 *
 * Everything checked here is a property of the *definition*: does the owning
 * app exist and is it enabled, is the app id one an external package may
 * claim, does each provider class exist and implement the right interface, is
 * each iframe URL one TeamHub will load. None of it is a governance decision —
 * a package that passes every check is still disabled until an administrator
 * says otherwise.
 *
 * Compatibility is the one exception to "refuse what is invalid". A package
 * that declares support for a TeamHub range this instance is outside of is
 * **stored anyway**, with {@see isCompatible()} answering false for it ever
 * after. Refusing it would leave the administrator looking at nothing, unable
 * to tell an incompatible integration from an app that never registered. The
 * reason has to be visible, so the record has to exist.
 *
 * ## Idempotency
 *
 * {@see persist()} upserts the package by stable identity, upserts each
 * capability by key, and then marks every *other* capability of that package
 * unregistered. So re-registering on every boot updates metadata, adds new
 * capabilities, retires removed ones — and never creates a duplicate, never
 * touches the administrator's enablement, never removes a template inclusion
 * and never resets a team's shown/hidden preferences or ordering.
 */
class IntegrationPackageService {

    /**
     * App ids an external package may not claim.
     *
     * TeamHub itself, plus the four app ids TeamHub handles natively in the
     * tab bar. `IntegrationRegistryMapper::BUILTIN_APP_IDS` is the same list
     * the single-registration path refuses, referenced rather than copied so
     * the two paths cannot disagree about what "built-in" means.
     */
    public const RESERVED_APP_IDS = [Application::APP_ID, ...IntegrationRegistryMapper::BUILTIN_APP_IDS];

    /**
     * Capability types backed by a URL rather than a class (v4.13.24).
     *
     * `widget_iframe` joined `menu_item` here: a blank-canvas widget is the
     * same bargain as an iframe tab at card size, so it takes the same
     * `iframeUrl` and goes through the same `IframeUrl` rule. Named as a list
     * because the two conditions below — "supplies a URL and may not" and
     * "needs a URL and has none" — both have to agree about which types
     * these are, and a `=== MENU_ITEM` in one of them is how a new iframe
     * type gets refused at registration for no reason anybody can see.
     */
    private const IFRAME_TYPES = [
        CapabilityType::MENU_ITEM,
        CapabilityType::WIDGET_IFRAME,
    ];

    /** Which interface each provider-bearing capability type requires. */
    private const REQUIRED_INTERFACE = [
        CapabilityType::WIDGET           => ITeamHubWidget::class,
        CapabilityType::MY_WORK_PROVIDER => IWorkProvider::class,
    ];

    /** @var list<string>|null `getAllAppsInAppsFolders()`, cached per request. */
    private ?array $appsOnDisk = null;

    public function __construct(
        private IntegrationPackageMapper    $packages,
        private IntegrationCapabilityMapper $capabilities,
        private IAppManager                 $appManager,
        private ContainerInterface          $container,
        private LoggerInterface             $logger,
    ) {}

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Everything that must be true before a package is stored.
     *
     * Compatibility is deliberately not checked here — see the class
     * docblock.
     *
     * @throws ValidationException
     */
    public function validate(IntegrationPackageDefinition $package): void {
        $appId = $package->appId;

        if (in_array($appId, self::RESERVED_APP_IDS, true)) {
            throw new ValidationException(
                "App '{$appId}' cannot register an integration package: TeamHub handles it natively.",
            );
        }

        // Installed *and* enabled. `isEnabledForAnyone()` rather than the
        // deprecated `isInstalled()`, which NC 32 deprecated precisely because
        // its name says installed and its behaviour says enabled.
        if (!$this->appManager->isEnabledForAnyone($appId)) {
            $onDisk = $this->isOnDisk($appId);
            throw new ValidationException($onDisk
                ? "App '{$appId}' is installed but not enabled on this instance."
                : "App '{$appId}' is not installed on this instance.",
            );
        }

        foreach ($package->capabilities as $capability) {
            $this->validateCapability($package, $capability);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateCapability(IntegrationPackageDefinition $package, CapabilityDefinition $capability): void {
        $type  = $capability->type();
        $where = $package->identity() . '/' . $capability->key;

        if (CapabilityType::isFuture($type)) {
            throw new ValidationException(
                "Capability '{$where}' declares type '{$type}', which TeamHub reserves but does not "
                . 'accept yet. It will be accepted once a reusable TeamHub contract exists behind it.',
            );
        }
        if (!CapabilityType::isSupported($type)) {
            throw new ValidationException(
                "Capability '{$where}' declares unknown type '{$type}'. Supported types: "
                . implode(', ', CapabilityType::SUPPORTED) . '.',
            );
        }

        $providerClass = $capability->providerClass();
        if ($providerClass !== null) {
            $required = self::REQUIRED_INTERFACE[$type] ?? null;
            if ($required === null) {
                throw new ValidationException(
                    "Capability '{$where}' supplies a provider class, but type '{$type}' takes none.",
                );
            }

            // Resolvable from the container, or at least autoloadable: some
            // classes are auto-wireable without an explicit binding, which is
            // the normal case for an app's own service.
            if (!$this->container->has($providerClass) && !class_exists($providerClass)) {
                throw new ValidationException(
                    "Capability '{$where}' names provider class '{$providerClass}', which is not "
                    . 'autoloadable and is not registered in the container.',
                );
            }
            if (!is_a($providerClass, $required, true)) {
                throw new ValidationException(
                    "Capability '{$where}' provider class '{$providerClass}' must implement {$required}.",
                );
            }
        }

        $iframeUrl = $capability->iframeUrl();
        if ($iframeUrl !== null) {
            if (!in_array($type, self::IFRAME_TYPES, true)) {
                throw new ValidationException(
                    "Capability '{$where}' supplies an iframe URL, but type '{$type}' takes none.",
                );
            }
            // Validated at construction already; re-checked here because a
            // definition can reach this service from the legacy adapter, which
            // builds it from a stored row rather than from a constructor call
            // the app author saw fail.
            IframeUrl::require($iframeUrl, "Capability '{$where}' iframeUrl");
        }

        if (in_array($type, self::IFRAME_TYPES, true) && $iframeUrl === null) {
            throw new ValidationException(
                "Capability '{$where}' is backed by an iframe and needs an iframeUrl.",
            );
        }
        if (isset(self::REQUIRED_INTERFACE[$type]) && $providerClass === null) {
            throw new ValidationException(
                "Capability '{$where}' is a '{$type}' and needs a providerClass implementing "
                . self::REQUIRED_INTERFACE[$type] . '.',
            );
        }
    }

    // ------------------------------------------------------------------
    // Compatibility
    // ------------------------------------------------------------------

    /**
     * Does this package's declared range admit the running TeamHub?
     *
     * An empty bound is unbounded on that end, so a package that declares
     * neither is always compatible — which is the right default for an app
     * that has not thought about it.
     */
    public function isCompatible(?string $minVersion, ?string $maxVersion): bool {
        $current = $this->teamHubVersion();
        if ($current === '') {
            // The app manager could not tell us our own version. Refusing
            // every package on that basis would turn one unreadable value into
            // a dead integration layer; treating it as compatible keeps the
            // instance working and the administrator sees the declared range.
            return true;
        }

        $min = trim((string)$minVersion);
        $max = trim((string)$maxVersion);

        if ($min !== '' && version_compare($current, $min, '<')) {
            return false;
        }
        if ($max !== '' && version_compare($current, $max, '>')) {
            return false;
        }

        return true;
    }

    /** The running TeamHub version, or '' when it cannot be read. */
    public function teamHubVersion(): string {
        try {
            return (string)$this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Integration] Could not read the TeamHub app version', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);

            return '';
        }
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    /** Is the owning app installed and enabled? Read live, never stored. */
    public function isAppAvailable(string $appId): bool {
        try {
            return $this->appManager->isEnabledForAnyone($appId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Integration] App availability check failed', [
                'app_id' => $appId, 'exception' => $e, 'app' => Application::APP_ID,
            ]);

            return false;
        }
    }

    /**
     * Is the owning app present on disk, whether or not it is enabled?
     *
     * The difference between "disabled" and "gone", which is the difference
     * between an administrator re-enabling an app and an administrator
     * installing one.
     */
    public function isOnDisk(string $appId): bool {
        if ($this->appsOnDisk === null) {
            try {
                $this->appsOnDisk = $this->appManager->getAllAppsInAppsFolders();
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][Integration] Could not list the apps folders', [
                    'exception' => $e, 'app' => Application::APP_ID,
                ]);
                $this->appsOnDisk = [];
            }
        }

        return in_array($appId, $this->appsOnDisk, true);
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * Store a validated package and its capabilities. Returns the package id.
     *
     * Not wrapped in a transaction on purpose. Each statement is an upsert
     * keyed on a unique index, so a half-completed run is re-run safely by the
     * next boot — and registration happens during `boot()`, where holding a
     * transaction open across a dozen statements on every request of every app
     * on the instance is a cost nobody asked for.
     */
    public function persist(IntegrationPackageDefinition $package, bool $isLegacy = false): int {
        $now = time();

        $pkgId = $this->packages->upsert(
            appId:          $package->appId,
            integrationKey: $package->integrationKey,
            title:          $package->title,
            description:    $package->description,
            icon:           $package->icon,
            version:        (string)($package->version ?? ''),
            minThVersion:   (string)($package->minTeamHubVersion ?? ''),
            maxThVersion:   (string)($package->maxTeamHubVersion ?? ''),
            isLegacy:       $isLegacy,
            registered:     true,
            metadata:       $package->metadata,
            now:            $now,
        );

        $keys = [];
        foreach ($package->capabilities as $capability) {
            $this->capabilities->upsert(
                pkgId:         $pkgId,
                capabilityKey: $capability->key,
                capType:       $capability->type(),
                title:         $capability->title,
                description:   $capability->description,
                icon:          $capability->icon,
                providerClass: $capability->providerClass(),
                iframeUrl:     $capability->iframeUrl(),
                defaultOrder:  $capability->defaultOrder,
                surfaces:      $capability->surfaces,
                presentation:  $capability->presentation,
                now:           $now,
                // v4.13.29 — `[]` for every capability type but the canvas
                // widget; the base definition answers that.
                actions:       $capability->actions(),
            );
            $keys[] = $capability->key;
        }

        // Anything this package used to declare and no longer does becomes
        // unregistered. Never deleted: a per-team preference points at the
        // capability row, and a capability that comes back must find its
        // team's decisions intact.
        $this->capabilities->markUnregisteredExcept($pkgId, $keys, $now);

        return $pkgId;
    }

    /**
     * Withdraw a package's registration.
     *
     * The package and every capability stay in the database with
     * `registered = 0`. The administrator's enablement, the template
     * inclusions and every per-team preference are untouched — which is what
     * makes "if the app is later enabled and registers the same stable
     * identifiers, restore its previous configuration" true without any
     * restore code at all.
     *
     * @return bool false when nothing was registered under that identity
     */
    public function withdraw(string $appId, string $integrationKey): bool {
        $row = $this->packages->findByIdentity($appId, $integrationKey);
        if ($row === null) {
            return false;
        }

        $now = time();
        $this->packages->setRegistered((int)$row['id'], false, $now);
        $this->capabilities->markUnregisteredExcept((int)$row['id'], [], $now);

        return true;
    }
}
