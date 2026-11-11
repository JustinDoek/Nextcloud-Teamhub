<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * One capability inside an integration package (v4.13.23).
 *
 * Immutable, constructed by the registering app, validated here rather than
 * in the service: a definition that cannot be valid should not be
 * constructible, so a mistake surfaces at the registration call site in the
 * registering app's own `boot()` instead of three layers down in TeamHub.
 *
 * ## Stable identity
 *
 *     app_id + integration_key + capability_key
 *
 * `$key` is this object's third of that. It is what per-team shown/hidden
 * preferences and ordering survive on, so it must never change once an app
 * has shipped it. The title is translated, the provider class is refactorable
 * and the iframe URL is routable — none of the three is identity, and the old
 * model's use of a database id made a reinstall lose every preference.
 *
 * Subclasses add only what their type requires: {@see WidgetCapabilityDefinition}
 * a provider class, {@see MenuItemCapabilityDefinition} an iframe URL,
 * {@see MyWorkCapabilityDefinition} a provider class implementing a different
 * interface. The type itself is {@see type()}, implemented per subclass rather
 * than passed in, so a widget definition cannot claim to be a tab.
 */
abstract class CapabilityDefinition implements \JsonSerializable {

    public const MAX_KEY        = 64;
    public const MAX_TITLE      = 255;
    public const MAX_DESCRIPTION = 500;
    public const MAX_ICON       = 64;

    /** Capability keys: lowercase, digits, dash, underscore. */
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,63}$/';

    /**
     * @param string               $key          stable within the package, never translated
     * @param string               $title        shown to users; translate it in the registering app
     * @param string|null          $description  one sentence, shown in Manage Team and admin
     * @param string|null          $icon         a `vue-material-design-icons` name, e.g. 'ViewDashboard'
     * @param int                  $defaultOrder position among its own type when a team has no preference
     * @param list<string>         $surfaces     TeamHub surfaces this may appear on ([] = the type's default)
     * @param array<string,mixed>  $presentation optional type-specific render hints (e.g. a widget's size)
     *
     * @throws ValidationException
     */
    public function __construct(
        public readonly string  $key,
        public readonly string  $title,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly int     $defaultOrder = 0,
        public readonly array   $surfaces = [],
        public readonly array   $presentation = [],
    ) {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new ValidationException(
                "Capability key '{$key}' is invalid: use lowercase letters, digits, '-' and '_', "
                . 'starting with a letter or digit, at most ' . self::MAX_KEY . ' characters.',
            );
        }
        if (trim($title) === '') {
            throw new ValidationException("Capability '{$key}' needs a title.");
        }
        if (strlen($title) > self::MAX_TITLE) {
            throw new ValidationException("Capability '{$key}' title exceeds " . self::MAX_TITLE . ' characters.');
        }
        if ($description !== null && strlen($description) > self::MAX_DESCRIPTION) {
            throw new ValidationException("Capability '{$key}' description exceeds " . self::MAX_DESCRIPTION . ' characters.');
        }
        if ($icon !== null && strlen($icon) > self::MAX_ICON) {
            throw new ValidationException("Capability '{$key}' icon name exceeds " . self::MAX_ICON . ' characters.');
        }
    }

    /** One of {@see CapabilityType}'s constants. Fixed per subclass. */
    abstract public function type(): string;

    /**
     * The fully-qualified provider class, or null for a capability that has
     * none (an iframe tab). Never accepted over HTTP — see
     * {@see ITeamHubIntegrationRegistry}.
     */
    public function providerClass(): ?string {
        return null;
    }

    /** The iframe URL, or null for a capability that is not an iframe. */
    public function iframeUrl(): ?string {
        return null;
    }

    /**
     * Header link actions, or `[]` for a capability type that has none
     * (v4.13.29). Only {@see IframeWidgetCapabilityDefinition} declares any:
     * a row widget's actions come back from its provider at render time
     * instead, because they can depend on what the provider found.
     *
     * @return list<array{label: string, url: string, icon: ?string}>
     */
    public function actions(): array {
        return [];
    }

    /** Does a team administrator's shown/hidden preference apply? */
    public function isVisual(): bool {
        return CapabilityType::isVisual($this->type());
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array {
        return [
            'capability_key' => $this->key,
            'cap_type'       => $this->type(),
            'title'          => $this->title,
            'description'    => $this->description,
            'icon'           => $this->icon,
            'provider_class' => $this->providerClass(),
            'iframe_url'     => $this->iframeUrl(),
            'default_order'  => $this->defaultOrder,
            'surfaces'       => $this->surfaces,
            'presentation'   => $this->presentation,
            'actions'        => $this->actions(),
        ];
    }
}
