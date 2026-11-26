<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Which team resource providers may appear in TeamHub (v4.20.40).
 *
 * The escape hatch for a provider that over-shares: a Nextcloud administrator
 * stops one provider's resources appearing in the Shared with team card
 * without waiting for a TeamHub release. Default on, so the stored value is
 * the list of **disabled** provider ids, and a provider installed later is on
 * until somebody says otherwise.
 *
 * Fails closed. A value that is present but cannot be read is not "nothing
 * disabled": it is an administrator's decision we can no longer honour, so
 * every provider is treated as disabled until the value is saved again, and
 * the read is logged at error. Widening what TeamHub shows because a config
 * value broke would defeat the switch.
 *
 * A DI leaf (IAppConfig and the logger only), read by
 * `SharedTeamResourceService` on every request and written by
 * `SharedResourceProviderAdminService`.
 */
class SharedResourceProviderSettings {

    public const CFG_DISABLED = 'shared_resources_disabled_providers';

    /** What a provider id may look like; anything else is refused on write. */
    public const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

    public function __construct(
        private IAppConfig      $appConfig,
        private LoggerInterface $logger,
    ) {}

    public function isEnabled(string $providerId): bool {
        $disabled = $this->disabledProviderIds();
        return $disabled !== null && !in_array($providerId, $disabled, true);
    }

    /**
     * The disabled provider ids, or null when the stored value is unreadable
     * (callers treat null as "every provider disabled").
     *
     * @return list<string>|null
     */
    public function disabledProviderIds(): ?array {
        $raw = $this->appConfig->getValueString(Application::APP_ID, static::CFG_DISABLED, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            $this->logger->error('[TeamHub][SharedResourceProviderSettings] unreadable list, every entry treated as disabled', [
                'key' => static::CFG_DISABLED, 'app' => Application::APP_ID,
            ]);
            return null;
        }
        return array_values(array_unique(array_map('strval', $decoded)));
    }

    /**
     * Switch one provider on or off. An unreadable stored value is replaced,
     * starting from nothing disabled plus this decision.
     *
     * @throws \InvalidArgumentException for an id that is not a provider id
     */
    public function setEnabled(string $providerId, bool $enabled): void {
        if (preg_match(self::ID_PATTERN, $providerId) !== 1) {
            throw new \InvalidArgumentException('Invalid provider id');
        }
        $disabled = $this->disabledProviderIds() ?? [];
        $disabled = array_values(array_filter($disabled, static fn (string $id): bool => $id !== $providerId));
        if (!$enabled) {
            $disabled[] = $providerId;
        }
        sort($disabled);
        if ($disabled === []) {
            $this->appConfig->deleteKey(Application::APP_ID, static::CFG_DISABLED);
            return;
        }
        $this->appConfig->setValueString(Application::APP_ID, static::CFG_DISABLED, json_encode($disabled));
    }
}
