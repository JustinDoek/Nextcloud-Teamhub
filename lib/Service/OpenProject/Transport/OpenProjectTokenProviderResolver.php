<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

/**
 * Picks the token provider for the administrator's configured
 * authorization method (v4.13.5).
 *
 * One line of logic, but its own class for two reasons: it is the seam the
 * OIDC provider slots into without `DirectTransport` changing, and it is
 * the one place that answers "is this authorization method actually
 * implemented yet", which the capability envelope and the settings page
 * both need to say something honest.
 */
class OpenProjectTokenProviderResolver {

    public function __construct(
        private OpenProjectCredentialStore $credentials,
        private OpenProjectOAuth2          $oauth2,
        private OpenProjectOidc            $oidc,
    ) {
    }

    /**
     * The provider for the configured method, or null when that method has
     * no implementation on this version.
     *
     * Null is a real answer, not an error: `DirectTransport` reads it as
     * "no usable credential" and the user is told to connect, which is
     * the least misleading thing to say to somebody whose administrator
     * has configured a method TeamHub cannot yet perform.
     */
    public function provider(): ?IOpenProjectTokenProvider {
        return match ($this->credentials->getAuthMethod()) {
            'oauth2' => $this->oauth2,
            // v4.13.8 — null when `user_oidc` is absent, disabled or too
            // old to publish the events we dispatch. That reads as
            // `auth_method_unsupported`, which names the real problem;
            // returning the provider anyway would give every user a
            // "reconnect" they can do nothing about.
            'oidc'   => $this->oidc->isUsable() ? $this->oidc : null,
            default  => null,
        };
    }

    /** Is the configured authorization method one TeamHub can perform. */
    public function isMethodSupported(): bool {
        return $this->provider() !== null;
    }
}
