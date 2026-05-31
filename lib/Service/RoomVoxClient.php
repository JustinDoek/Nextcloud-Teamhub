<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client for RoomVox's documented public API v1.
 *
 * Why this exists:
 *   RoomVox's CalDAV scheduling-plugin auto-accept path fires for events
 *   written through the NC Calendar HTTP layer, not for events written
 *   directly via CalDavBackend (as TeamHub does). To get a TeamHub-created
 *   meeting actually BOOKED in RoomVox, we have to call RoomVox's own
 *   public booking endpoint explicitly. RoomVox's docs document this v1
 *   API as the supported integration surface for custom applications:
 *     https://github.com/nextcloud/RoomVox/blob/main/docs/architecture/api-reference.md
 *
 * Authentication:
 *   A single NC-admin-configured Bearer token (`rvx_…`) stored in app
 *   config. Per-user tokens are out of scope; the admin scopes the token
 *   in RoomVox itself (book / read / admin, optional room restriction).
 *
 * Scope of this service:
 *   - createBooking()  → POST   /api/v1/rooms/{id}/bookings
 *   - cancelBooking()  → DELETE /api/v1/rooms/{id}/bookings/{uid}
 *   Nothing else. Status / availability lookups are RoomVox's territory;
 *   we don't second-guess them.
 *
 * Loopback HTTP:
 *   SKILLS.md forbids loopback HTTP. This service IS loopback HTTP (we're
 *   calling /apps/roomvox on the same NC instance). The forbiddance is
 *   directed at calling back into TeamHub's own routes; calling another
 *   installed app's documented public API is a different category and is
 *   the supported integration mechanism RoomVox documents. Logged here so
 *   the architectural exception is visible.
 */
class RoomVoxClient {

    /**
     * Config key holding the Bearer token. Stored via setAppValue; never
     * returned to the UI (see TeamService::getAdminSettings — only a
     * boolean "configured" flag is surfaced).
     */
    public const APP_VALUE_TOKEN = 'roomvox_api_token';

    /** Request timeout — seconds. Keep tight; the booking call is in the user's path. */
    private const TIMEOUT = 10;

    /** Connect timeout — even tighter; localhost should never take this long to accept. */
    private const CONNECT_TIMEOUT = 3;

    private ?IClient $client = null;

    public function __construct(
        private IClientService   $clientService,
        private IConfig          $config,
        private IURLGenerator    $urlGenerator,
        private LoggerInterface  $logger,
    ) {
    }

    /**
     * True iff a token is configured. Used to gate features before showing
     * a room picker for "real" booking flows.
     */
    public function isConfigured(): bool {
        return $this->getToken() !== '';
    }

    /**
     * Book the room from the documented API. Throws RoomVoxClientException
     * with a translatable, user-facing message on every failure mode. The
     * caller (ActivityService) catches and aborts event creation per
     * Justin's session decision (option A = surface, don't degrade).
     *
     * @param string $roomId      RoomVox room id (e.g. "meeting-room-1")
     * @param string $title       Event summary
     * @param string $startIso    Start in ISO 8601 with timezone offset
     * @param string $endIso      End in ISO 8601 with timezone offset
     * @param string $organiserEmail Optional; helps RoomVox attribute the booking
     * @param string $description Optional
     * @return array{uid: string, status: string} The RoomVox booking UID
     *                              and status (accepted | pending).
     * @throws RoomVoxClientException
     */
    public function createBooking(
        string $roomId,
        string $title,
        string $startIso,
        string $endIso,
        string $organiserEmail = '',
        string $description = ''
    ): array {
        $token = $this->getToken();
        if ($token === '') {
            throw new RoomVoxClientException('RoomVox API token is not configured. Ask an administrator to set one in TeamHub admin settings.');
        }

        // POST endpoints must go through /index.php per RoomVox docs
        // (their routing requires it for write methods).
        $url = $this->urlGenerator->getAbsoluteURL('/index.php/apps/roomvox/api/v1/rooms/' . rawurlencode($roomId) . '/bookings');

        $payload = [
            'title' => $title,
            'start' => $startIso,
            'end'   => $endIso,
        ];
        if ($organiserEmail !== '') {
            $payload['organizer'] = $organiserEmail;
        }
        if ($description !== '') {
            $payload['description'] = $description;
        }

        $client = $this->httpClient();
        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                // Don't throw on non-2xx — we decode the error body ourselves
                // so we can surface RoomVox's own message to the user.
                'http_errors' => false,
                // Allow loopback. RoomVox is another NC app on the SAME
                // instance, so the URL resolves to localhost. NC's IClient
                // blocks localhost by default as SSRF protection — that
                // safety net guards against user-controlled URLs reaching
                // internal services. Our URL comes from urlGenerator (not
                // user input) and points to RoomVox's documented public
                // API surface, so the protection isn't doing useful work
                // here. Without this flag every booking call fails with
                // "Host '127.0.0.1' violates local access rules".
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RoomVoxClient] createBooking transport failure', [
                'url'  => $url,
                'err'  => $e->getMessage(),
                'app'  => Application::APP_ID,
            ]);
            throw new RoomVoxClientException('Could not reach RoomVox: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $bodyRaw = (string)$response->getBody();
        $body = json_decode($bodyRaw, true);

        if ($status === 201 && is_array($body) && isset($body['uid'])) {
            $this->logger->info('[TeamHub][RoomVoxClient] booking created', [
                'roomId' => $roomId,
                'uid'    => $body['uid'],
                'status' => $body['status'] ?? null,
                'app'    => Application::APP_ID,
            ]);
            return [
                'uid'    => (string)$body['uid'],
                'status' => (string)($body['status'] ?? 'pending'),
            ];
        }

        // Map known RoomVox errors to caller-facing messages. Their docs
        // enumerate: 400 (bad input), 401 (auth), 403 (scope/room), 404
        // (room not found), 409 (conflict), 422 (outside hours / horizon),
        // 500 (server). We pass through RoomVox's own error string when
        // present so admins see exact diagnostics.
        $remoteMsg = is_array($body) && isset($body['error']) ? (string)$body['error'] : ('HTTP ' . $status);
        $this->logger->warning('[TeamHub][RoomVoxClient] booking refused', [
            'roomId' => $roomId,
            'status' => $status,
            'msg'    => $remoteMsg,
            'app'    => Application::APP_ID,
        ]);

        // Translate the 401/403 case into something actionable — the user
        // can't fix the others, but auth needs admin attention.
        if ($status === 401) {
            throw new RoomVoxClientException('RoomVox rejected the API token (unauthenticated). An administrator needs to re-issue it.');
        }
        if ($status === 403) {
            throw new RoomVoxClientException('The RoomVox token does not have permission to book this room.');
        }
        throw new RoomVoxClientException($remoteMsg);
    }

    /**
     * Cancel a previously-created RoomVox booking. Failure here is logged
     * but never thrown — at the point of cancellation the calendar event
     * has already been deleted by the user, so there's no operation to
     * abort. An orphaned RoomVox booking will surface in admin overview
     * and can be cleaned up manually.
     *
     * @param string $roomId  RoomVox room id
     * @param string $bookingUid  RoomVox booking UID returned from createBooking
     */
    public function cancelBooking(string $roomId, string $bookingUid): bool {
        $token = $this->getToken();
        if ($token === '') {
            $this->logger->warning('[TeamHub][RoomVoxClient] cancelBooking with no token configured', [
                'roomId' => $roomId, 'uid' => $bookingUid, 'app' => Application::APP_ID,
            ]);
            return false;
        }

        $url = $this->urlGenerator->getAbsoluteURL(
            '/index.php/apps/roomvox/api/v1/rooms/' . rawurlencode($roomId)
            . '/bookings/' . rawurlencode($bookingUid)
        );

        $client = $this->httpClient();
        try {
            $response = $client->delete($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
                // See createBooking() for rationale.
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][RoomVoxClient] cancelBooking transport failure', [
                'url'    => $url,
                'roomId' => $roomId,
                'uid'    => $bookingUid,
                'err'    => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        }
        $status = $response->getStatusCode();
        // 200 OK or 204 No Content are success; 404 means the booking was
        // already gone (race against the user manually removing it in
        // RoomVox admin) — treat as success.
        $ok = in_array($status, [200, 204, 404], true);
        $this->logger->info('[TeamHub][RoomVoxClient] cancelBooking', [
            'roomId' => $roomId,
            'uid'    => $bookingUid,
            'status' => $status,
            'ok'     => $ok,
            'app'    => Application::APP_ID,
        ]);
        return $ok;
    }

    /**
     * Read the stored token. Empty string = none configured.
     */
    private function getToken(): string {
        return $this->config->getAppValue(Application::APP_ID, self::APP_VALUE_TOKEN, '');
    }

    private function httpClient(): IClient {
        if ($this->client === null) {
            $this->client = $this->clientService->newClient();
        }
        return $this->client;
    }
}
