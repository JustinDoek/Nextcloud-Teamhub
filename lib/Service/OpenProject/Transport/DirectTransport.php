<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject\Transport;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * OpenProject over TeamHub's own HTTP client and its own credentials
 * (v4.13.4).
 *
 * The second transport. It exists for the two reasons the interface
 * docblock gives — independence from `integration_openproject`'s release
 * train, and **PATCH**, which upstream's `rawRequest()` refuses outright —
 * and it is used only where an administrator has configured it.
 *
 * ## Deliberately the same wire conventions as the official app
 *
 * `OpenProjectClient` encodes a body once and classifies an error once, for
 * both transports. That only works if this one speaks the same shapes:
 *
 *  - GET: `$params` become the query string, array values repeated as
 *    `key[]=` — which is how OpenProject's API v3 reads multi-valued
 *    filters.
 *  - Everything else: the body is `$params['body']`, an already-encoded
 *    JSON string, sent with `Content-Type: application/json`.
 *  - A 204 answers `['success' => true]`.
 *  - A failure answers `['error' => …, 'statusCode' => …, 'message' => …]`.
 *
 * ## One place it is deliberately *better* than the official app
 *
 * Upstream reports a connection failure as `statusCode 404` with no
 * `message` key, and a real 404 from OpenProject as 404 *with* one — so
 * telling "unreachable" from "not found" depends on the absence of an
 * array key. `OpenProjectClient::classifyError()` has to honour that quirk
 * for the other transport. Here it is not reproduced:
 *
 *  - a transport-level failure answers `statusCode => null`, which the
 *    classifier reads as `api_unavailable`;
 *  - every real HTTP response carries a `message` key, even an empty one,
 *    so a genuine 404 can never be mistaken for an unreachable host.
 *
 * ## Security
 *
 * The host comes from {@see OpenProjectCredentialStore}, which reads it
 * from appconfig and nowhere else — no user input ever becomes a host
 * (DESIGN §2.120). Nextcloud's own SSRF protection stays **on**:
 * `allow_local_address` is not set, because unlike RoomVox (another app on
 * the same instance) OpenProject is genuinely remote, and an admin-set URL
 * pointing at the loopback interface is a mistake worth failing on.
 *
 * The Authorization header is built here and never logged, never returned,
 * and never put in an exception message. What is logged is the endpoint's
 * path (never its query string) and the status.
 */
class DirectTransport implements IOpenProjectTransport {

    /** Seconds to wait for the response, and for the connection. */
    public const TIMEOUT = 20;
    public const CONNECT_TIMEOUT = 10;

    /** Everything OpenProject's API v3 needs — PATCH included. */
    private const SUPPORTED_METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    private ?IClient $client = null;

    public function __construct(
        private IClientService                   $clientService,
        private OpenProjectCredentialStore       $credentials,
        // v4.13.5 — where a usable token comes from, which differs per
        // authorization method. Resolving it here rather than reading the
        // store directly is what lets a token be *refreshed* mid-request
        // without this class knowing how.
        private OpenProjectTokenProviderResolver $tokens,
        private LoggerInterface                  $logger,
    ) {
    }

    public function id(): string {
        return self::ID_DIRECT;
    }

    /**
     * Has an administrator set this transport up at all — a host, plus
     * whatever the chosen method needs (v4.13.5).
     *
     * Deliberately separate from `unavailableCode() === null`. "Set up"
     * and "usable" are different questions, and the selector needs the
     * first: a transport that is configured but broken must still be
     * *chosen*, so that its own accurate error reaches the user instead of
     * a fallback's message about an app the administrator never meant to
     * use.
     */
    public function isConfigured(): bool {
        return $this->credentials->isConfigured();
    }

    public function unavailableCode(): ?string {
        if ($this->credentials->getHost() === '') {
            return OpenProjectException::HOST_NOT_CONFIGURED;
        }
        if (!$this->credentials->isConfigured()) {
            // A host but no usable client credentials for the configured
            // method. From an administrator's point of view the OpenProject
            // connection is half-set-up, which is the same thing to fix.
            return OpenProjectException::HOST_NOT_CONFIGURED;
        }
        // v4.13.5 — configured, but with a method TeamHub cannot perform
        // (today: `oidc`). Its own code, because "no host is configured"
        // would send an administrator to the wrong field.
        if (!$this->tokens->isMethodSupported()) {
            return OpenProjectException::AUTH_METHOD_UNSUPPORTED;
        }
        return null;
    }

    public function getHost(): string {
        return $this->credentials->getHost();
    }

    public function getAuthMethod(): string {
        return $this->credentials->getAuthMethod();
    }

    /**
     * v4.13.8 — the provider answers this, not the credential store. With
     * OAuth2 the two agree (a grant is held or it is not); with OIDC they
     * do not, because a user is connected by how they signed in and may
     * have no stored token at all.
     */
    public function isUserConnected(string $userId): bool {
        $provider = $this->tokens->provider();
        return $provider !== null
            ? $provider->isUserConnected($userId)
            : $this->credentials->hasCredential($userId);
    }

    public function supportsMethod(string $method): bool {
        return in_array(strtoupper($method), self::SUPPORTED_METHODS, true);
    }

    public function request(string $userId, string $endpoint, array $params, string $method): array {
        $method = strtoupper($method);
        $host   = $this->getHost();
        $path   = TransportUrl::safePath($endpoint);

        if ($host === '') {
            return ['error' => 'no host configured', 'statusCode' => null];
        }
        if (!$this->supportsMethod($method)) {
            return ['error' => 'unsupported method', 'statusCode' => null];
        }

        // v4.13.5 — this may refresh an expired grant, so it is a possible
        // HTTP call of its own before the real one.
        $token = $this->tokens->provider()?->accessTokenFor($userId);
        if ($token === null) {
            // No usable credential, or a refresh that could not be made.
            // Reported as a 401 so it classifies as `auth_failed` and the
            // user is told to reconnect — which is what they have to do.
            return ['error' => 'no usable credential', 'statusCode' => 401, 'message' => ''];
        }

        $url     = $host . '/api/v3/' . ltrim($endpoint, '/');
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'Nextcloud TeamHub',
            ],
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            // Decode non-2xx ourselves: OpenProject's own sentence on a 422
            // is the thing a user has to read to fix their input.
            'http_errors'     => false,
        ];

        if ($method === 'GET') {
            $query = $this->buildQuery($params);
            if ($query !== '') {
                $url .= '?' . $query;
            }
        } elseif (isset($params['body']) && is_string($params['body'])) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body']                    = $params['body'];
        }

        try {
            $response = $this->httpClient()->request($method, $url, $options);
        } catch (\Throwable $e) {
            // Transport level: DNS, TLS, timeout, refused. Never the host
            // or the exception message — a misconfigured URL can carry
            // credentials in its userinfo part.
            $this->logger->warning('[TeamHub][DirectTransport] Request failed at transport level', [
                'endpoint' => $path,
                'method'   => $method,
                'class'    => get_class($e),
                'app'      => Application::APP_ID,
            ]);
            return ['error' => 'transport failure', 'statusCode' => null, 'cause' => $e];
        }

        $status = $response->getStatusCode();
        $raw    = (string)$response->getBody();

        if ($status === 204 || ($raw === '' && $status >= 200 && $status < 300)) {
            return ['success' => true];
        }

        $decoded = json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($decoded)) {
                return ['error' => 'non-JSON body', 'statusCode' => $status, 'message' => ''];
            }
            return $decoded;
        }

        // OpenProject's API v3 error bodies are
        // `{_type: Error, errorIdentifier: …, message: …}`. The message is
        // the only part carried, and only `OpenProjectClient` decides
        // whether a given code is allowed to show it to anyone.
        $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : '';

        $this->logger->info('[TeamHub][DirectTransport] OpenProject answered an error', [
            'endpoint' => $path,
            'method'   => $method,
            'status'   => $status,
            'app'      => Application::APP_ID,
        ]);

        return ['error' => 'http ' . $status, 'statusCode' => $status, 'message' => $message];
    }

    /**
     * The query string, **byte-for-byte what the official app sends**.
     *
     * Not tidied, deliberately. Upstream's `rawRequest()` does this:
     *
     *     foreach ($params as $key => $value)
     *         if (is_array($value))
     *             foreach ($value as $v) $out .= $key . '[]=' . urlencode($v) . '&';
     *     $out .= http_build_query($params);   // the scalars that are left
     *
     * — so the brackets are literal, the key is not encoded in the array
     * case, and spaces are `+` rather than `%20`. Every filter grammar
     * above this layer (`OpenProjectWorkPackageService::query()` and the
     * documented date-range operators §2.120 settled on) was proven against
     * exactly those bytes. Reproducing them means a query that works on one
     * transport cannot subtly fail on the other, which is worth more than
     * a tidier encoder.
     *
     * @param array<string, mixed> $params
     */
    private function buildQuery(array $params): string {
        $out = '';
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $out .= $key . '[]=' . urlencode((string)$item) . '&';
                }
                unset($params[$key]);
            }
        }
        return $out . http_build_query($params);
    }

    private function httpClient(): IClient {
        if ($this->client === null) {
            $this->client = $this->clientService->newClient();
        }
        return $this->client;
    }
}
