<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;

/**
 * A widget capability — one card in a team's widget grid (v4.13.23).
 *
 * The provider class implements {@see ITeamHubWidget}, unchanged from the
 * single-registration model: the same `getWidgetData()` / `getActionForm()` /
 * `handleAction()` contract, the same item and action limits, the same
 * response shape. What changes is that a package may now carry several of
 * these, each with its own stable key, its own position and its own
 * shown/hidden preference per team.
 *
 * A widget that wants the verified team context rather than the two bare
 * strings `ITeamHubWidget` passes may additionally implement
 * {@see ITeamHubContextAware}; TeamHub prefers that method when it is there.
 */
final class WidgetCapabilityDefinition extends CapabilityDefinition {

    public const MAX_PROVIDER_CLASS = 255;

    /**
     * @param string               $providerClass FQN of an {@see ITeamHubWidget} implementation
     * @param array<string,mixed>  $presentation  optional render hints; `size` ∈ small|medium|large
     *
     * @throws ValidationException
     */
    public function __construct(
        string                 $key,
        string                 $title,
        public readonly string $providerClass,
        ?string                $description = null,
        ?string                $icon = null,
        int                    $defaultOrder = 0,
        array                  $surfaces = [],
        array                  $presentation = [],
    ) {
        parent::__construct($key, $title, $description, $icon, $defaultOrder, $surfaces, $presentation);

        $trimmed = trim($providerClass);
        if ($trimmed === '') {
            throw new ValidationException(
                "Widget capability '{$key}' needs a providerClass — the fully-qualified name of "
                . 'a class implementing ' . ITeamHubWidget::class . '.',
            );
        }
        if (strlen($trimmed) > self::MAX_PROVIDER_CLASS) {
            throw new ValidationException("Widget capability '{$key}' providerClass exceeds " . self::MAX_PROVIDER_CLASS . ' characters.');
        }
    }

    public function type(): string {
        return CapabilityType::WIDGET;
    }

    public function providerClass(): ?string {
        return trim($this->providerClass);
    }
}
