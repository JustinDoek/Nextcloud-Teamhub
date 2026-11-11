<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * An external app's complete TeamHub integration (v4.13.23).
 *
 * ## The level the old model did not have
 *
 * Before this, one `registerIntegration()` call produced one row that *was*
 * one widget or one tab, keyed `(app_id, integration_type)`. An app could own
 * one widget and one tab and nothing more — a second widget collided on the
 * unique index — and a team administrator saw unrelated switches with no sign
 * that they came from the same app. A package is the missing level: the app's
 * integration as a thing, with capabilities inside it.
 *
 * ## Stable identity
 *
 *     app_id + integration_key
 *
 * `integration_key` lets one app register more than one package (a large app
 * with two genuinely separate TeamHub integrations) and gives the pair a name
 * that is not a translated title, not a database id, not a class name and not
 * a URL — the four things that moved underneath the old model and lost
 * configuration when they did.
 *
 * Every durable decision hangs off this identity: the administrator's
 * enablement, which templates include the package, and the per-team
 * shown/hidden preferences of its capabilities. So an app that is disabled,
 * upgraded, uninstalled and reinstalled finds its configuration again as long
 * as it registers the same two strings.
 *
 * ## Version fields
 *
 * `$version` is the registering app's own integration version, recorded and
 * shown to administrators; TeamHub never branches on it. `$minTeamHubVersion`
 * and `$maxTeamHubVersion` are what TeamHub does branch on: a package outside
 * the range registers, is stored, is shown to the administrator as
 * incompatible, and is not effective anywhere. Refusing the registration
 * outright would be worse — the administrator would see nothing at all and
 * have no way to tell a missing app from an incompatible one.
 */
final class IntegrationPackageDefinition implements \JsonSerializable {

    public const MAX_APP_ID      = 64;
    public const MAX_KEY         = 64;
    public const MAX_TITLE       = 255;
    public const MAX_DESCRIPTION = 500;
    public const MAX_ICON        = 64;
    public const MAX_VERSION     = 32;

    /** App ids as Nextcloud spells them. */
    private const APP_ID_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/';

    /** Integration keys: the same shape as a capability key. */
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/';

    /** @var list<CapabilityDefinition> */
    public readonly array $capabilities;

    /**
     * @param string                    $appId             the registering Nextcloud app's id
     * @param string                    $integrationKey    stable within the app, never translated
     * @param string                    $title             shown to users; translate it in the registering app
     * @param list<CapabilityDefinition> $capabilities      at least one
     * @param string|null               $description        one sentence
     * @param string|null               $icon              a `vue-material-design-icons` name
     * @param string|null               $version           the registering app's integration version
     * @param string|null               $minTeamHubVersion lowest TeamHub this package supports
     * @param string|null               $maxTeamHubVersion highest TeamHub this package supports
     * @param array<string,mixed>       $metadata          recorded verbatim, shown to administrators
     *
     * @throws ValidationException
     */
    public function __construct(
        public readonly string  $appId,
        public readonly string  $integrationKey,
        public readonly string  $title,
        array                   $capabilities,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly ?string $version = null,
        public readonly ?string $minTeamHubVersion = null,
        public readonly ?string $maxTeamHubVersion = null,
        public readonly array   $metadata = [],
    ) {
        if (!preg_match(self::APP_ID_PATTERN, $appId)) {
            throw new ValidationException(
                "appId '{$appId}' is invalid: use the Nextcloud app id — lowercase letters, digits, '-' and '_'.",
            );
        }
        if (!preg_match(self::KEY_PATTERN, $integrationKey)) {
            throw new ValidationException(
                "integrationKey '{$integrationKey}' is invalid: use lowercase letters, digits, '-' and '_', "
                . 'at most ' . self::MAX_KEY . ' characters.',
            );
        }
        if (trim($title) === '') {
            throw new ValidationException("Package '{$appId}/{$integrationKey}' needs a title.");
        }
        if (strlen($title) > self::MAX_TITLE) {
            throw new ValidationException("Package title exceeds " . self::MAX_TITLE . ' characters.');
        }
        if ($description !== null && strlen($description) > self::MAX_DESCRIPTION) {
            throw new ValidationException('Package description exceeds ' . self::MAX_DESCRIPTION . ' characters.');
        }
        if ($icon !== null && strlen($icon) > self::MAX_ICON) {
            throw new ValidationException('Package icon name exceeds ' . self::MAX_ICON . ' characters.');
        }
        foreach (['version' => $version, 'minTeamHubVersion' => $minTeamHubVersion, 'maxTeamHubVersion' => $maxTeamHubVersion] as $name => $value) {
            if ($value !== null && strlen($value) > self::MAX_VERSION) {
                throw new ValidationException("Package {$name} exceeds " . self::MAX_VERSION . ' characters.');
            }
        }

        if ($capabilities === []) {
            throw new ValidationException(
                "Package '{$appId}/{$integrationKey}' registers no capabilities. "
                . 'A package with nothing in it has nothing TeamHub can show or run.',
            );
        }

        $seen = [];
        $list = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof CapabilityDefinition) {
                throw new ValidationException(
                    "Package '{$appId}/{$integrationKey}' passed something that is not a "
                    . CapabilityDefinition::class . '.',
                );
            }
            // Duplicate keys are refused rather than deduplicated: two
            // capabilities with one key means one of them silently never
            // appears, and the app author cannot tell which.
            if (isset($seen[$capability->key])) {
                throw new ValidationException(
                    "Package '{$appId}/{$integrationKey}' registers capability key "
                    . "'{$capability->key}' twice. Capability keys must be unique within a package.",
                );
            }
            $seen[$capability->key] = true;
            $list[] = $capability;
        }

        $this->capabilities = $list;
    }

    /** `app_id + integration_key`, for logs and messages. */
    public function identity(): string {
        return $this->appId . '/' . $this->integrationKey;
    }

    /** @return list<CapabilityDefinition> */
    public function capabilitiesOfType(string $type): array {
        return array_values(array_filter(
            $this->capabilities,
            static fn (CapabilityDefinition $c): bool => $c->type() === $type,
        ));
    }

    /**
     * How many capabilities of each type, for the administrator's capability
     * summary.
     *
     * @return array<string,int>
     */
    public function capabilityCounts(): array {
        $out = [];
        foreach ($this->capabilities as $capability) {
            $type = $capability->type();
            $out[$type] = ($out[$type] ?? 0) + 1;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array {
        return [
            'app_id'              => $this->appId,
            'integration_key'     => $this->integrationKey,
            'title'               => $this->title,
            'description'         => $this->description,
            'icon'                => $this->icon,
            'version'             => $this->version,
            'min_teamhub_version' => $this->minTeamHubVersion,
            'max_teamhub_version' => $this->maxTeamHubVersion,
            'metadata'            => $this->metadata,
            'capabilities'        => array_map(
                static fn (CapabilityDefinition $c): array => $c->jsonSerialize(),
                $this->capabilities,
            ),
        ];
    }
}
