<?php
declare(strict_types=1);

namespace OCA\TeamHub\Integration;

/**
 * The verified context TeamHub hands a provider (v4.13.23).
 *
 * ## Why this object exists at all
 *
 * `ITeamHubWidget::getWidgetData(string $teamId, string $userId)` passes two
 * bare strings, which is enough to be useful and not enough to be safe: a
 * provider cannot tell whether the user id it was given is the authenticated
 * session's or something that arrived in a query parameter, and it has no way
 * to render in the user's own language or time zone. Every provider then
 * re-derives what it needs from the request, which is the one place it must
 * not come from.
 *
 * So TeamHub builds this, once, after the membership check, and a provider
 * receives facts rather than parameters.
 *
 * ## Where every field comes from
 *
 *   teamId          the route parameter, **after** MemberService has
 *                   confirmed the caller's membership of it
 *   userId          IUserSession — never request input, never a provider
 *                   argument, never a value echoed back by the client
 *   level           the caller's Circles level in this team, as
 *                   MemberService resolved it
 *   locale/timezone the user's own Nextcloud settings
 *   appId /
 *   integrationKey /
 *   capabilityKey   which capability is being called, so a provider serving
 *                   several can branch without string-matching a title
 *   teamConfig      the package's per-team configuration, when the surface
 *                   calling the provider has it; `[]` otherwise
 *
 * ## What it does not do
 *
 * It does not authorise the provider's own records. TeamHub states who is
 * asking and about which team; whether *this* user may see *that* work
 * package, card or document is the external app's question and only the
 * external app can answer it. A provider that treats this object as
 * permission to return everything for the team has misread it.
 *
 * Immutable by construction: `readonly` throughout and no setters, so a
 * provider cannot hand a mutated context to something downstream and widen
 * its own scope.
 */
final class TeamHubContext implements \JsonSerializable {

    /**
     * @param string              $teamId         Circles unique_id of the team, membership already verified
     * @param string              $userId         authenticated Nextcloud user id
     * @param int                 $level          Circles member level (1 member, 4 moderator, 8 admin, 9 owner)
     * @param string              $locale         the user's language code, e.g. 'nl'
     * @param string              $timezone       the user's time zone, e.g. 'Europe/Amsterdam'
     * @param string              $appId          owning app of the capability being called
     * @param string              $integrationKey package key
     * @param string              $capabilityKey  capability key
     * @param array<string,mixed> $teamConfig     per-team configuration, or []
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $userId,
        public readonly int    $level,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly string $appId,
        public readonly string $integrationKey,
        public readonly string $capabilityKey,
        public readonly array  $teamConfig = [],
    ) {
    }

    /** Circles levels, named so a provider does not have to know the numbers. */
    public const LEVEL_MEMBER    = 1;
    public const LEVEL_MODERATOR = 4;
    public const LEVEL_ADMIN     = 8;
    public const LEVEL_OWNER     = 9;

    public function isTeamAdmin(): bool {
        return $this->level >= self::LEVEL_ADMIN;
    }

    public function isTeamModerator(): bool {
        return $this->level >= self::LEVEL_MODERATOR;
    }

    /** `app_id/integration_key/capability_key`, for logs. */
    public function capabilityIdentity(): string {
        return $this->appId . '/' . $this->integrationKey . '/' . $this->capabilityKey;
    }

    /**
     * The same context pointing at a different capability of the same package.
     *
     * Used when one surface calls several capabilities in a row — the verified
     * half is identical and must not be rebuilt from scratch each time, which
     * is how a rebuild starts reading the request again.
     */
    public function forCapability(string $capabilityKey): self {
        return new self(
            $this->teamId,
            $this->userId,
            $this->level,
            $this->locale,
            $this->timezone,
            $this->appId,
            $this->integrationKey,
            $capabilityKey,
            $this->teamConfig,
        );
    }

    /**
     * The same context with the package's per-team configuration attached.
     */
    public function withTeamConfig(array $teamConfig): self {
        return new self(
            $this->teamId,
            $this->userId,
            $this->level,
            $this->locale,
            $this->timezone,
            $this->appId,
            $this->integrationKey,
            $this->capabilityKey,
            $teamConfig,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array {
        return [
            'team_id'         => $this->teamId,
            'user_id'         => $this->userId,
            'level'           => $this->level,
            'locale'          => $this->locale,
            'timezone'        => $this->timezone,
            'app_id'          => $this->appId,
            'integration_key' => $this->integrationKey,
            'capability_key'  => $this->capabilityKey,
        ];
    }
}
