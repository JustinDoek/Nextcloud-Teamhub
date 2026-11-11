<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\IntegrationRegistryMapper;
use OCA\TeamHub\Integration\CapabilityDefinition;
use OCA\TeamHub\Integration\IntegrationPackageDefinition;
use OCA\TeamHub\Integration\IntegrationRegistry;
use OCA\TeamHub\Integration\MenuItemCapabilityDefinition;
use OCA\TeamHub\Integration\WidgetCapabilityDefinition;
use Psr\Log\LoggerInterface;

/**
 * Turns a legacy `registerIntegration()` call into a package (v4.13.23).
 *
 * ## What it preserves
 *
 * `IntegrationService::registerIntegration()` keeps its signature, keeps its
 * validation, keeps writing `teamhub_integ_registry`, and keeps every old
 * render endpoint working. It additionally calls this adapter, so an app that
 * has not changed a line now also has a package — and therefore appears on
 * the administration screen, can be included in a template and gets a team's
 * shown/hidden preference like any other.
 *
 * ## The identity it chooses, and why that matters to app authors
 *
 * A legacy registration becomes:
 *
 *     app_id          the registering app
 *     integration_key 'legacy'
 *     capability_key  'widget' or 'menu_item' — the old integration_type
 *
 * The consequence worth stating plainly: an app that registered **both** a
 * widget and a menu item used to produce two unrelated registry rows that a
 * team administrator toggled separately. It now produces **one package with
 * two capabilities**, grouped under the app's name. That is the upgrade's main
 * visible gain and it happens without the app changing anything.
 *
 * The other consequence is the migration path. When an app adopts the new
 * API, registering with `integrationKey: 'legacy'` makes it **the same
 * package** — the administrator's enablement, the template inclusions and
 * every team's layout carry over, and `is_legacy` clears because the app is
 * no longer on the old API. Choosing any other key creates a second, separate
 * package that an administrator has to enable and include from scratch.
 * `developers.md` says so where an author will read it.
 *
 * ## Why the title is kept and the key is not derived from it
 *
 * The legacy row has no stable key of its own, which is the whole reason the
 * package model introduced one. `'widget'` and `'menu_item'` are the only
 * values the old model could distinguish, so they are the only honest keys to
 * reconstruct — and they are stable, which a translated title is not.
 */
class LegacyIntegrationAdapter {

    /** The integration key every adapted legacy registration lands under. */
    public const LEGACY_KEY = 'legacy';

    public function __construct(
        private IntegrationRegistry $registry,
        private LoggerInterface     $logger,
    ) {}

    /**
     * Adapt one legacy registration.
     *
     * Never throws. The legacy call has already succeeded by the time this
     * runs — the old registry row is written and the old endpoints work — so a
     * failure here must not turn a working registration into an error the
     * registering app sees. It is logged and the app keeps its legacy
     * behaviour.
     *
     * @param string      $integrationType `widget` or `menu_item`
     * @param string|null $phpClass        the legacy widget class, if any
     * @param string|null $iframeUrl       the legacy iframe URL, if any
     */
    public function adopt(
        string  $appId,
        string  $integrationType,
        string  $title,
        ?string $description,
        ?string $icon,
        ?string $phpClass,
        ?string $iframeUrl,
    ): void {
        try {
            $capability = $this->capabilityFor($integrationType, $title, $description, $icon, $phpClass, $iframeUrl);
            if ($capability === null) {
                return;
            }

            // An app registering a widget and then a menu item calls the
            // legacy method twice. Re-declare what the identity already holds
            // alongside the new capability, or the second call retires the
            // first one's capability — `persist()` marks everything it was not
            // told about unregistered, which is right for a real package
            // registration and wrong for two halves of one.
            $capabilities = [
                ...$this->existingCapabilities($appId, $capability->key),
                $capability,
            ];

            // The package carries the capability's own title: a legacy
            // registration described one thing, and that description is the
            // best name the package has. When the app later registers a real
            // package under this key it supplies a proper package title and
            // this is replaced.
            $this->registry->adoptLegacyPackage(new IntegrationPackageDefinition(
                appId:          $appId,
                integrationKey: self::LEGACY_KEY,
                title:          $title,
                capabilities:   $capabilities,
                description:    $description,
                icon:           $icon,
                metadata:       ['adapted_from' => 'registerIntegration'],
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][Integration] Could not adapt a legacy registration', [
                'app_id'           => $appId,
                'integration_type' => $integrationType,
                'error_class'      => get_class($e),
                'exception'        => $e,
                'app'              => Application::APP_ID,
            ]);
        }
    }

    /**
     * Merge a legacy registration into the package the app already has.
     *
     * An app registering a widget and then a menu item calls
     * `registerIntegration()` twice, and the second call must not retire the
     * capability the first one created — {@see IntegrationPackageService::persist()}
     * marks everything it was not told about unregistered. So the adapter
     * reads what the identity already holds and re-declares it alongside the
     * new capability.
     *
     * @return list<CapabilityDefinition>
     */
    private function existingCapabilities(string $appId, string $excludeKey): array {
        $existing = $this->registry->registeredPackage($appId, self::LEGACY_KEY);
        if ($existing === null) {
            return [];
        }

        return array_values(array_filter(
            $existing->capabilities,
            static fn (CapabilityDefinition $c): bool => $c->key !== $excludeKey,
        ));
    }

    private function capabilityFor(
        string  $integrationType,
        string  $title,
        ?string $description,
        ?string $icon,
        ?string $phpClass,
        ?string $iframeUrl,
    ): ?CapabilityDefinition {
        if ($integrationType === IntegrationRegistryMapper::TYPE_WIDGET) {
            if ($phpClass === null || trim($phpClass) === '') {
                return null;
            }

            return new WidgetCapabilityDefinition(
                key:           IntegrationRegistryMapper::TYPE_WIDGET,
                title:         $title,
                providerClass: $phpClass,
                description:   $description,
                icon:          $icon,
            );
        }

        if ($integrationType === IntegrationRegistryMapper::TYPE_MENU_ITEM) {
            if ($iframeUrl === null || trim($iframeUrl) === '') {
                return null;
            }

            return new MenuItemCapabilityDefinition(
                key:         IntegrationRegistryMapper::TYPE_MENU_ITEM,
                title:       $title,
                iframeUrl:   $iframeUrl,
                description: $description,
                icon:        $icon,
            );
        }

        return null;
    }
}
