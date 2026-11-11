<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

/**
 * Where {@see DirectTransport} gets a usable access token for one user
 * (v4.13.5).
 *
 * Two implementations, chosen by the administrator's configured
 * authorization method and resolved through
 * {@see OpenProjectTokenProviderResolver}:
 *
 *  - `oauth2` → {@see OpenProjectOAuth2}, which holds the user's own
 *    authorization-code grant and refreshes it.
 *  - `oidc` → the `user_oidc` event exchange. **Not built yet** — the
 *    resolver answers null for it, which reads as "this user has no usable
 *    credential" and sends them to reconnect rather than failing oddly.
 *
 * The split exists because the two acquire a token in completely different
 * ways — one stores a grant and refreshes it against OpenProject, the
 * other asks Nextcloud for a fresh one on every request and stores
 * nothing long-lived — but `DirectTransport` should not know which.
 */
interface IOpenProjectTokenProvider {

    /**
     * An access token that can be sent to OpenProject right now, or null
     * when this user has to (re)connect.
     *
     * The implementation is responsible for refreshing a token that has
     * expired. It must never throw for an ordinary "cannot authenticate"
     * outcome — a refused refresh is a null, logged, not an exception on
     * every widget.
     */
    public function accessTokenFor(string $userId): ?string;

    /**
     * Is this user in a position to get a token, as far as can be told
     * without asking for one (v4.13.8).
     *
     * The two methods answer this very differently, which is why it is on
     * the interface rather than read off the credential store. With OAuth2
     * it means "they have completed the authorization and we hold a
     * grant". With OIDC there is no grant to hold — they are connected
     * because of how they signed in — so a stored token is not the test
     * and asking for one would be an HTTP call on a page load.
     */
    public function isUserConnected(string $userId): bool;
}
