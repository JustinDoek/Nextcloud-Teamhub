<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * A team-aware iframe tab (v4.13.23).
 *
 * One tab in the team's tab bar, alongside Talk, Files, Calendar and Deck.
 * TeamHub loads {@see $iframeUrl} in a sandboxed iframe with its own verified
 * team id appended as `?teamId=` — the compatibility the previous model
 * established and every registered app reads.
 *
 * A package may register several of these; each gets its own stable key, its
 * own position in the tab bar and its own shown/hidden preference per team.
 * That is the whole difference from the old `menu_item` registration, which
 * allowed exactly one per app because the type was the identity.
 *
 * The URL is validated by {@see IframeUrl} at construction and validated
 * again when it is rendered, because a stored row can predate a tightening of
 * the rule.
 */
final class MenuItemCapabilityDefinition extends CapabilityDefinition {

    /**
     * @param string $iframeUrl `/apps/…`, `/index.php/…` or `https://…`
     *
     * @throws ValidationException
     */
    public function __construct(
        string                 $key,
        string                 $title,
        public readonly string $iframeUrl,
        ?string                $description = null,
        ?string                $icon = null,
        int                    $defaultOrder = 0,
        array                  $surfaces = [],
        array                  $presentation = [],
    ) {
        parent::__construct($key, $title, $description, $icon, $defaultOrder, $surfaces, $presentation);

        IframeUrl::require($iframeUrl, "Menu item capability '{$key}' iframeUrl");
    }

    public function type(): string {
        return CapabilityType::MENU_ITEM;
    }

    public function iframeUrl(): ?string {
        return trim($this->iframeUrl);
    }
}
