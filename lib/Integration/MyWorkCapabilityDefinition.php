<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\MyWork\IWorkProvider;

/**
 * A My Work source contributed by an integration package (v4.13.23).
 *
 * ## It is the existing contract, not a second one
 *
 * {@see $providerClass} implements {@see IWorkProvider} — the same interface
 * the eleven built-in providers implement, with the same `WorkItem` model,
 * the same `ActionResult`, the same `fetchItems()` / `getItem()` /
 * `executeAction()` authorisation pairing and the same rule that a provider
 * may never return an item for a team outside `WorkQuery::$teamIds`. There is
 * deliberately no parallel interface for external sources: My Work's
 * grouping, filtering, sorting, snoozing, action handling and fault isolation
 * all key off that one contract, and a second one would have to reimplement
 * every one of them.
 *
 * ## What TeamHub adds around it
 *
 * The provider is wrapped by `OCA\TeamHub\MyWork\Provider\PackageWorkProvider`,
 * which narrows the query's team set to the teams where this package is
 * *effective* before delegating, and isolates the delegate's failures. So an
 * external provider that honours the contract automatically stops
 * contributing for a team whose template no longer includes the package,
 * without knowing that templates exist.
 *
 * ## Why this type is non-visual
 *
 * My Work is not part of a team's workspace, so a team administrator's
 * shown/hidden preference must not reach it: hiding a widget is a layout
 * decision and must never silence somebody's queue.
 * {@see CapabilityType::isVisual()} returns false for this type, which is how
 * that is enforced rather than remembered.
 *
 * `$sourceKey` is the stable My Work source id. It defaults to
 * `<app_id>_<capability_key>`, assigned by TeamHub at registration so two
 * apps cannot collide on it and no app can claim a built-in provider's id.
 */
final class MyWorkCapabilityDefinition extends CapabilityDefinition {

    public const MAX_PROVIDER_CLASS = 255;

    /**
     * @param string $providerClass FQN of an {@see IWorkProvider} implementation
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
                "My Work capability '{$key}' needs a providerClass — the fully-qualified name of "
                . 'a class implementing ' . IWorkProvider::class . '.',
            );
        }
        if (strlen($trimmed) > self::MAX_PROVIDER_CLASS) {
            throw new ValidationException("My Work capability '{$key}' providerClass exceeds " . self::MAX_PROVIDER_CLASS . ' characters.');
        }
    }

    public function type(): string {
        return CapabilityType::MY_WORK_PROVIDER;
    }

    public function providerClass(): ?string {
        return trim($this->providerClass);
    }

    /**
     * The stable My Work source id for this capability on a given app.
     *
     * Assigned by TeamHub, not by the registering app: a source key is
     * persisted in the snooze table and in administrator configuration, so an
     * app that could choose its own could claim `deck` and shadow a built-in
     * provider.
     */
    public static function sourceKeyFor(string $appId, string $capabilityKey): string {
        return $appId . '_' . $capabilityKey;
    }
}
