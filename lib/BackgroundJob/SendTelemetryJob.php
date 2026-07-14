<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * SendTelemetryJob (v3.100.0, Track F — Licensing).
 *
 * Daily POST from Connected-licensed instances to the licensing back-end.
 * Payload is intentionally minimal — everything in this job's docblock is
 * the disclosure story we show admins in the "View last payload" modal,
 * so nothing here should change without the disclosure updating too.
 *
 *   POST TELEMETRY_URL
 *   {
 *     "license_id":           "lic_2026_abc123",
 *     "uuid":                 "<Nextcloud instanceid>",
 *     "teams_total":          N,
 *     "teams_advanced":       N,
 *     "unique_team_members":  N,          // seat count — see LicenseService::countLicensedSeats
 *     "reported_at":          "2026-07-09T12:00:00Z"
 *   }
 *
 * Response body may include:
 *   { "ok": true, "renewed_jwt": "<optional new JWT with extended exp>" }
 *
 * When renewed_jwt is present, we verify and save it silently. This is
 * the auto-renewal mechanism (customer paid, back-end extends exp, next
 * telemetry POST returns the new JWT). Air-gapped licenses never run this
 * job so they never auto-renew — that is a deliberate tradeoff.
 *
 * SKIP CONDITIONS (silent, non-error):
 *  - No license saved.
 *  - License is Air-gapped.
 *  - License is expired past soft-lock (nothing useful to report; back-end
 *    won't renew a fully lapsed license without payment anyway).
 *
 * FAILURE HANDLING:
 *  - Network / non-2xx: log at info (not error) and move on. Next sweep
 *    tries again. Never blocks other jobs.
 *  - Invalid renewed_jwt: log warning, keep the old JWT (the current
 *    license is still valid until its own exp).
 */
class SendTelemetryJob extends TimedJob {

    /** 24 hours between sweeps. */
    private const INTERVAL = 86400;

    /** HTTP timeout for the POST. Short — this is fire-and-forget. */
    private const HTTP_TIMEOUT = 10;

    public function __construct(
        ITimeFactory                     $time,
        private readonly LicenseService  $licenseService,
        private readonly IClientService  $clientService,
        private readonly IDBConnection   $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
        // Never run two sweeps in parallel — duplicate reports would confuse
        // the back-end's "last seen" tracking.
        $this->setAllowParallelRuns(false);
        // Fine to catch up after a downtime — telemetry is idempotent per-day.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        $status = $this->licenseService->getStatus();
        if (!$status['hasKey']) {
            return;
        }
        if (($status['kind'] ?? null) === 'airgapped') {
            return;
        }
        if ($status['enforcementLevel'] === 'soft-lock') {
            // Deeply expired — back-end won't renew without payment.
            return;
        }

        $payload = [
            'license_id'          => $status['licenseId'],
            'uuid'                => $status['instanceUuid'],
            'teams_total'         => $this->countTeams(false),
            'teams_advanced'      => $this->countTeams(true),
            'unique_team_members' => $status['seatsUsed'],
            'reported_at'         => gmdate('c'),
        ];

        // Persist what we're about to send BEFORE the POST — the "View
        // last payload" modal should reflect intent even if the POST
        // fails, so admins can see what would leave the instance.
        $this->licenseService->recordTelemetry(time(), $payload);

        try {
            $client = $this->clientService->newClient();
            // v3.100.3 — use the `json` option so NC's HTTP client sets
            // Content-Type and serialization consistently. `body:
            // <string>` was arriving form-encoded on some hosts,
            // producing empty php://input on the receiver.
            $response = $client->post(LicenseService::TELEMETRY_URL, [
                'timeout' => self::HTTP_TIMEOUT,
                'headers' => [
                    'User-Agent' => 'TeamHub-Telemetry/' . $this->appVersion(),
                ],
                'json' => $payload,
                // v3.100.4 — same reasoning as LicenseService: some hosts
                // throw on 4xx, which would silently drop a "server told
                // us to stop reporting" response.
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->info(
                '[TeamHub][SendTelemetryJob] telemetry POST failed (non-fatal): ' . $e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->info(
                '[TeamHub][SendTelemetryJob] telemetry POST non-2xx: ' . $status,
                ['app' => Application::APP_ID],
            );
            return;
        }

        // Auto-renewal path OR revocation signal.
        try {
            $body    = (string)$response->getBody();
            $decoded = json_decode($body, true);
            // v3.100.7 — revocation-via-telemetry. If the licensing
            // back-end says the license was deleted, revoked or bound
            // to a different UUID, drop our saved JWT. The client's
            // enforcementLevel flips to 'unlicensed' on the next
            // getStatus() call.
            if (is_array($decoded) && !empty($decoded['revoked'])) {
                $reason = (string)($decoded['reason'] ?? 'revoked');
                $this->licenseService->clearKey();
                $this->logger->warning(
                    '[TeamHub][SendTelemetryJob] license revoked by back-end (reason: ' . $reason . ')',
                    ['app' => Application::APP_ID],
                );
                return;
            }
            if (is_array($decoded) && !empty($decoded['renewed_jwt']) && is_string($decoded['renewed_jwt'])) {
                $this->licenseService->saveKey($decoded['renewed_jwt']);
                $this->logger->info(
                    '[TeamHub][SendTelemetryJob] license auto-renewed',
                    ['app' => Application::APP_ID],
                );
            }
        } catch (\Throwable $e) {
            // Invalid renewed_jwt — keep the current one, log for review.
            $this->logger->warning(
                '[TeamHub][SendTelemetryJob] auto-renewal rejected: ' . $e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }
    }

    private function countTeams(bool $advancedOnly): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) AS c'))
                ->from('teamhub_project');
            if ($advancedOnly) {
                $qb->where($qb->expr()->eq('mode', $qb->createNamedParameter('advanced')));
            }
            $r = $qb->executeQuery();
            $c = (int)$r->fetchOne();
            $r->closeCursor();
            return $c;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function appVersion(): string {
        // Read from info.xml on request, cached by NC's own AppManager
        // could be used, but a static-ish fallback avoids an extra DI dep
        // for a value that only appears in the User-Agent.
        return '3.100.0';
    }
}
