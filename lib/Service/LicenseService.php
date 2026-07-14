<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\TrialRequestException;
use OCA\TeamHub\Util\Jwt;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * LicenseService (v3.100.0, Track F — Licensing).
 *
 * TeamHub's Advanced-project features (Compass panel, Project-health widget,
 * closing artifact, timeline, budget/time write endpoints) require a valid
 * business license. This service is the single source of truth for:
 *
 *  - Whether a license is currently valid on this instance
 *  - What kind (connected / airgapped) and how many seats
 *  - Where in the enforcement lifecycle we are (none / grace / soft-lock)
 *  - How many "unique team members" the instance has (for telemetry + banners)
 *
 * LICENSE ARTIFACT
 * ----------------
 * A JWT signed with RS256 by our licensing back-end. Payload claims we care
 * about:
 *  - lic       — public license id (e.g. lic_2026_abc123)
 *  - uuid      — Nextcloud instanceid this license is bound to
 *  - kind      — "connected" | "airgapped"
 *  - seats     — int tier (25 | 100 | 250 | 999999 for unlimited) — null on airgapped
 *  - is_trial  — bool (Connected trials are still Connected licenses; this flag drives UI copy)
 *  - customer  — human-readable customer id, for admin display only
 *  - iat/nbf/exp — standard JWT time claims (seconds since epoch)
 *
 * The instance verifies the JWT offline against a hardcoded public key
 * (see PUBLIC_KEY_PEM). No runtime call is made to enforce validity —
 * everything happens in-process, once, cached in-request.
 *
 * TELEMETRY (Connected only)
 * --------------------------
 * SendTelemetryJob POSTs {uuid, license_id, teams_total, teams_advanced,
 * unique_team_members, reported_at} to TELEMETRY_URL once per day. That
 * response may include a "renewed_jwt" — a fresh JWT with an extended
 * exp claim, which the job persists silently. Air-gapped licenses never
 * send telemetry and never auto-refresh.
 *
 * ENFORCEMENT LEVELS
 * ------------------
 *  - 'none'      — valid license (paid or trial). Feature fully enabled.
 *  - 'grace'     — license expired ≤ GRACE_DAYS ago. Advanced creation is
 *                  blocked; existing Advanced teams still fully work; banner
 *                  in admin UI shows a countdown.
 *  - 'soft-lock' — > GRACE_DAYS past expiry. Existing Advanced teams
 *                  become read-only: Compass/health widget/timeline hidden,
 *                  Budget/Time/Milestone write endpoints reject. Non-Advanced
 *                  features untouched. Pasting a new JWT immediately restores.
 *  - 'unlicensed'— no JWT saved at all. Same effect as soft-lock for new
 *                  Advanced creation, but no data has ever been generated so
 *                  there's nothing to lock down.
 *
 * GRANDFATHERING RULE
 * -------------------
 * Enforcement fires on transitions (create Advanced team, invite member,
 * post expense/timelog/milestone), never on existing state. A team that
 * is already Advanced when a license lapses does not "un-become" Advanced.
 * Only its write endpoints stop accepting new data after grace.
 *
 * PUBLIC KEY
 * ----------
 * The PUBLIC_KEY_PEM constant is a placeholder in this file. To ship,
 * generate an RSA-2048 keypair on the licensing server and paste the
 * public half into PUBLIC_KEY_PEM. Rotate = ship an app update. The
 * private key never leaves the licensing server.
 */
class LicenseService {

    /**
     * Public half of the RS256 signing keypair used by the licensing
     * back-end. Ship-time value below is a PLACEHOLDER — every real
     * verification against it will fail signature check, which is the
     * correct behavior until the real key is pasted in. See docblock.
     *
     * Regenerate:
     *   openssl genrsa -out private.pem 2048
     *   openssl rsa -in private.pem -pubout -out public.pem
     * then paste the contents of public.pem below (including BEGIN/END).
     */
    public const PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\n"
		. "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA4VS5N+E08RpCTpZu2v+j\n"
		. "grwj2StCEiMmTnPTr/aKORqtSjgthcdG4NovqdBDRlowdBm2ofAdI7KJHYoqeV8L\n"
		. "VeA5gx5Q4FhJWpXMorDg4y9wi4goLs1aPqryeWX4wt0sASySxjNvrZy3WZdAzSbB\n"
		. "zBUnCn8U/+/mXCLRD9FXv+o4QIe6M+3dGUQuyL6MK0lXFhGFPA4VO1dzcto9rd+w\n"
		. "rvvcDibSCGGQpQXAGHx0HiYXO5OzLZaNXlo+6b/0b6j6/gPdKzWg3N7pM0ZT8KZD\n"
		. "+XGTsU8cTI07gDxi+oEVxijCiBh95ipTSRYrM6vAfkjATsw9yUo29u5H1IWhFGkn\n"
		. "VwIDAQAB\n"
        . "-----END PUBLIC KEY-----\n";

    /**
     * Where SendTelemetryJob POSTs its daily report.
     */
    public const TELEMETRY_URL = 'https://tldr.host/business/telemetry.php';

    /**
     * v3.100.2 — Where LicenseService::requestTrial() POSTs to auto-issue
     * a 14-day Connected trial. The URL is deliberately server-side
     * (never rendered in the browser) so the licensing back-end stays
     * hidden from end users. Same host as TELEMETRY_URL — different
     * script.
     */
    public const TRIAL_URL = 'https://tldr.host/business/trial.php';

    /** Days past exp before we escalate from grace to soft-lock. */
    private const GRACE_DAYS = 30;

    /** IConfig app key names. All namespaced under app='teamhub'. */
    private const CFG_JWT                    = 'license_jwt';
    private const CFG_LAST_TELEMETRY_AT      = 'license_last_telemetry_at';
    private const CFG_LAST_TELEMETRY_PAYLOAD = 'license_last_telemetry_payload';

    public function __construct(
        private IConfig          $config,
        private IDBConnection    $db,
        private IClientService   $clientService,
        private IUserSession     $userSession,
        // v3.100.6 — reuse the working query TelemetryService already
        // uses for the Statistics tab. countLicensedSeats() delegates so
        // the number the customer sees on their own Statistics page and
        // the number our licensing back-end sees via telemetry are
        // guaranteed to match.
        private TelemetryService $telemetryService,
        private LoggerInterface  $logger,
    ) {}

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Structured payload for the admin UI and enforcement gates.
     *
     * @return array{
     *   hasKey: bool,
     *   valid: bool,
     *   kind: 'connected'|'airgapped'|null,
     *   seats: int|null,
     *   isTrial: bool,
     *   licenseId: string|null,
     *   customer: string|null,
     *   uuid: string|null,
     *   expiresAt: int|null,
     *   daysRemaining: int|null,
     *   enforcementLevel: 'none'|'grace'|'soft-lock'|'unlicensed',
     *   graceRemaining: int|null,
     *   invalidReason: string|null,
     *   instanceUuid: string,
     *   seatsUsed: int,
     *   seatsOverBy: int,
     *   lastTelemetryAt: int|null,
     *   lastTelemetryPayload: array|null
     * }
     */
    public function getStatus(): array {
        $now = time();
        $instanceUuid = $this->getInstanceUuid();
        $seatsUsed    = $this->countLicensedSeats();
        $lastAt       = $this->getIntConfig(self::CFG_LAST_TELEMETRY_AT);
        $lastPayload  = $this->getJsonConfig(self::CFG_LAST_TELEMETRY_PAYLOAD);

        $rawJwt = $this->config->getAppValue(Application::APP_ID, self::CFG_JWT, '');
        if ($rawJwt === '') {
            return $this->emptyStatus($instanceUuid, $seatsUsed, $lastAt, $lastPayload);
        }

        // Attempt strict verification first. Any failure → surface as
        // unlicensed for enforcement, but include the reason for the UI
        // banner so admins know what to fix.
        try {
            $claims = $this->verifyJwt($rawJwt);
        } catch (\RuntimeException $e) {
            // If the token peek-decodes and only failed on exp, we can
            // still compute grace/soft-lock. That's the "expired but
            // recoverable" enforcement state.
            $peeked = Jwt::peek($rawJwt);
            $expiry = is_array($peeked['payload'] ?? null)
                ? ($peeked['payload']['exp'] ?? null)
                : null;
            if (is_int($expiry) && $expiry < $now
                && $this->peekUuidMatches($peeked, $instanceUuid)) {
                $daysPast     = intdiv($now - $expiry, 86400);
                $level        = $daysPast > self::GRACE_DAYS ? 'soft-lock' : 'grace';
                $graceLeft    = max(0, self::GRACE_DAYS - $daysPast);
                $p = $peeked['payload'];
                return [
                    'hasKey'               => true,
                    'valid'                => false,
                    'kind'                 => $p['kind']     ?? null,
                    'seats'                => $p['seats']    ?? null,
                    'isTrial'              => (bool)($p['is_trial'] ?? false),
                    'licenseId'            => $p['lic']      ?? null,
                    'customer'             => $p['customer'] ?? null,
                    'uuid'                 => $p['uuid']     ?? null,
                    'expiresAt'            => $expiry,
                    'daysRemaining'        => 0,
                    'enforcementLevel'     => $level,
                    'graceRemaining'       => $level === 'grace' ? $graceLeft : 0,
                    'invalidReason'        => $e->getMessage(),
                    'instanceUuid'         => $instanceUuid,
                    'seatsUsed'            => $seatsUsed,
                    'seatsOverBy'          => $this->overBy($seatsUsed, $p['seats'] ?? null),
                    'lastTelemetryAt'      => $lastAt,
                    'lastTelemetryPayload' => $lastPayload,
                ];
            }
            // Signature mismatch, UUID mismatch, malformed — treat as
            // unlicensed. Admin sees "invalid key: <reason>" and can
            // re-paste.
            return $this->emptyStatus($instanceUuid, $seatsUsed, $lastAt, $lastPayload, $e->getMessage());
        }

        $expiry = (int)$claims['exp'];
        $daysRem = max(0, intdiv($expiry - $now, 86400));

        return [
            'hasKey'               => true,
            'valid'                => true,
            'kind'                 => $claims['kind']     ?? null,
            'seats'                => $claims['seats']    ?? null,
            'isTrial'              => (bool)($claims['is_trial'] ?? false),
            'licenseId'            => $claims['lic']      ?? null,
            'customer'             => $claims['customer'] ?? null,
            'uuid'                 => $claims['uuid']     ?? null,
            'expiresAt'            => $expiry,
            'daysRemaining'        => $daysRem,
            'enforcementLevel'     => 'none',
            'graceRemaining'       => null,
            'invalidReason'        => null,
            'instanceUuid'         => $instanceUuid,
            'seatsUsed'            => $seatsUsed,
            'seatsOverBy'          => $this->overBy($seatsUsed, $claims['seats'] ?? null),
            'lastTelemetryAt'      => $lastAt,
            'lastTelemetryPayload' => $lastPayload,
        ];
    }

    /**
     * Enforcement level as a short string. Cheap — reads from getStatus's
     * result. Callers should treat 'grace' and 'none' the same for
     * READ paths (both allow read) but distinct for the banner UI.
     */
    public function getEnforcementLevel(): string {
        return $this->getStatus()['enforcementLevel'];
    }

    /**
     * True iff this instance may create new Advanced teams and accept new
     * writes on existing Advanced-project surfaces (Budget expenses,
     * TimeLogs, Milestones). Also true during grace — grace only blocks
     * *creation of new Advanced teams*, existing teams keep writing until
     * soft-lock kicks in.
     */
    public function allowsAdvancedWrites(): bool {
        $level = $this->getEnforcementLevel();
        return $level === 'none' || $level === 'grace';
    }

    /**
     * One-shot guard for Budget/Time/Milestone write endpoints. If the
     * team is Advanced AND we're in soft-lock (or unlicensed), throw
     * with a user-facing message the controller can serialize to 403.
     * If the team is Basic, no-op — Basic teams don't have these
     * features at all so the check is trivially skipped.
     *
     * Grace still permits writes on existing Advanced teams — the whole
     * point of the grace window is giving a lapsed customer 30 days to
     * renew without disrupting existing operations.
     */
    public function gateAdvancedWrite(string $teamId): void {
        if ($this->allowsAdvancedWrites()) {
            return;
        }
        if (!$this->isAdvancedTeam($teamId)) {
            return;
        }
        throw new \OCA\TeamHub\Exception\LicenseGateException(
            $this->getEnforcementLevel(),
            'Your TeamHub Business license has lapsed. Renew to resume Budget, Time and Milestone updates on Advanced projects.',
        );
    }

    private function isAdvancedTeam(string $teamId): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('mode')
                ->from('teamhub_project')
                ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1);
            $r    = $qb->executeQuery();
            $mode = $r->fetchOne();
            $r->closeCursor();
            return $mode === 'advanced';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True iff this instance may create a NEW Advanced team. Blocks in
     * grace as well: no new Advanced teams once expired, only the
     * existing ones grandfather.
     */
    public function allowsAdvancedCreation(): bool {
        return $this->getEnforcementLevel() === 'none';
    }

    /**
     * Save a fresh JWT after verifying it. Called from LicenseController
     * on PUT /license and from SendTelemetryJob when the licensing server
     * returns a renewed_jwt. Any verification failure throws.
     */
    public function saveKey(string $jwt): array {
        $jwt = trim($jwt);
        $claims = $this->verifyJwt($jwt);  // throws on any failure
        $this->config->setAppValue(Application::APP_ID, self::CFG_JWT, $jwt);
        return $claims;
    }

    /**
     * v3.100.7 — wipe the saved JWT from appconfig. Called only from
     * SendTelemetryJob when the licensing back-end signals `revoked:
     * true` (row deleted, uuid mismatch, or explicit revocation).
     *
     * Advanced projects created while the license was active stay
     * grandfathered per the enforcement-lifecycle docblock at the top
     * of this file — this method does NOT touch teamhub_project rows.
     */
    public function clearKey(): void {
        $this->config->deleteAppValue(Application::APP_ID, self::CFG_JWT);
    }

    /**
     * v3.100.2 — Request a 14-day Connected trial license from the
     * licensing back-end and install it silently. Called from the admin
     * License tab "Start 14-day trial" button.
     *
     * Server-to-server: this PHP process POSTs to TRIAL_URL, never
     * the browser. The back-end URL therefore stays hidden from the
     * customer — no redirects to a login page they can't use.
     *
     * On success, the returned JWT is verified against the same
     * PUBLIC_KEY_PEM as any manually pasted license and stored in
     * appconfig. Any failure throws \RuntimeException with a message
     * safe to surface to admins.
     */
    public function requestTrial(): array {
        $uuid = $this->getInstanceUuid();
        if ($uuid === '') {
            throw new \RuntimeException('This instance has no UUID — trial cannot be requested.');
        }
        // Best-effort acting-admin email so the licensing back-end can
        // upsert a real customer row instead of an anonymous placeholder.
        $email = '';
        $user  = $this->userSession->getUser();
        if ($user !== null) {
            $email = (string)($user->getEMailAddress() ?? '');
        }

        try {
            $client = $this->clientService->newClient();
            // v3.100.3 — use Guzzle's `json` option instead of `body` +
            // Content-Type header. Under some NC/Guzzle configurations
            // `body: <string>` gets sent as form-encoded (multipart) so
            // php://input on the receiving end is empty and json_decode
            // returns null → 400 "Invalid JSON body". `json` serializes
            // the value AND sets Content-Type: application/json in one
            // shot with no ambiguity.
            $response = $client->post(self::TRIAL_URL, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'TeamHub-Trial/3.100.4',
                ],
                'json' => ['uuid' => $uuid, 'email' => $email],
                // v3.100.4 — some NC/Guzzle stacks throw on 4xx by
                // default, which turns our meaningful 409 "already used"
                // into a generic ConnectException. Disable http_errors
                // so 4xx/5xx come back as normal responses and we can
                // read the {error} JSON below.
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            // v3.100.3 — surface the actual reason so admins can
            // diagnose. Reasons that hit this branch (never returned a
            // response): DNS failure, connection refused, TLS mismatch,
            // Guzzle throwing on 4xx/5xx (depends on NC's config), or
            // timeout. The message is admin-only (not leaked to
            // end-users creating projects) so leaking the URL + reason
            // is fine.
            $reason = $e->getMessage();
            $this->logger->warning(
                '[TeamHub][LicenseService] trial request failed for ' . self::TRIAL_URL . ': ' . $reason,
                ['app' => Application::APP_ID, 'exception' => $e],
            );
            throw new TrialRequestException(
                'Could not reach the licensing server (' . self::TRIAL_URL . '). '
                . 'Reason: ' . $reason,
                400,
            );
        }

        $status = $response->getStatusCode();
        $bodyStr = (string)$response->getBody();
        $decoded = json_decode($bodyStr, true);

        if ($status === 409) {
            // Already used — surface the server's message verbatim.
            $msg = is_array($decoded) && !empty($decoded['error'])
                ? (string)$decoded['error']
                : 'This instance has already used its trial.';
            throw new TrialRequestException($msg, 409);
        }
        if ($status === 429) {
            $msg = is_array($decoded) && !empty($decoded['error'])
                ? (string)$decoded['error']
                : 'Too many trial requests — please try again later.';
            throw new TrialRequestException($msg, 429);
        }
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $msg = is_array($decoded) && !empty($decoded['error'])
                ? (string)$decoded['error']
                : 'Trial request failed.';
            throw new TrialRequestException($msg, 400);
        }
        if (empty($decoded['jwt']) || !is_string($decoded['jwt'])) {
            throw new TrialRequestException('Trial response was missing the license key.', 400);
        }

        // Install it. saveKey re-verifies signature + UUID binding before
        // persisting; if the back-end signed with the wrong key or bound
        // to a different UUID, this throws and nothing is stored.
        return $this->saveKey($decoded['jwt']);
    }

    /**
     * Record what SendTelemetryJob just sent. Called from the job itself
     * so the "View last payload" modal in Admin UI can render exactly
     * what left the instance.
     */
    public function recordTelemetry(int $sentAt, array $payload): void {
        $this->config->setAppValue(Application::APP_ID, self::CFG_LAST_TELEMETRY_AT, (string)$sentAt);
        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_LAST_TELEMETRY_PAYLOAD,
            (string)json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }

    /** Nextcloud's install-time immutable instance identifier. */
    public function getInstanceUuid(): string {
        return (string)$this->config->getSystemValue('instanceid', '');
    }

    /**
     * Distinct Nextcloud user IDs across every team where
     * teamhub_project.mode = 'advanced'. This is the number the licensing
     * back-end meters against a Connected license's seat cap.
     *
     * Rules (see the "SEAT-COUNTING POLICY" section in HANDOFF.md):
     *  - Basic-mode teams don't count.
     *  - Direct + transitive members both count (matches how NC Circles
     *    exposes "who has access").
     *  - Guest/invite-only users without an NC user_id don't count.
     *  - Runs on-demand; caller decides caching.
     */
    public function countLicensedSeats(): int {
        // v3.100.6 — delegate to TelemetryService::countUniqueTeamMembers,
        // which is the same query the Statistics tab shows. The earlier
        // hand-rolled join against circles_membership/teamhub_project
        // was wrong (it filtered on Advanced-mode teams only, but the
        // schema doesn't guarantee circles_membership.circle_id matches
        // teamhub_project.team_id when the team is nested via a group).
        // Delegating means the seat count on the licensing back-end's
        // Telemetry page and the count on the customer's Statistics
        // page are guaranteed to be the same number.
        return $this->telemetryService->countUniqueTeamMembers();
    }

    // ── Internal helpers ────────────────────────────────────────────

    private function verifyJwt(string $jwt): array {
        $claims = Jwt::verifyRs256($jwt, self::PUBLIC_KEY_PEM);
        // UUID binding: license is tied to this specific instance.
        // Missing uuid claim = malformed license.
        $bound = $claims['uuid'] ?? null;
        if (!is_string($bound) || $bound === '') {
            throw new \RuntimeException('License key is missing an instance binding.');
        }
        if ($bound !== $this->getInstanceUuid()) {
            throw new \RuntimeException('License key is issued for a different instance.');
        }
        return $claims;
    }

    private function peekUuidMatches(?array $peeked, string $instanceUuid): bool {
        if (!is_array($peeked)) return false;
        $payload = $peeked['payload'] ?? null;
        if (!is_array($payload)) return false;
        return ($payload['uuid'] ?? null) === $instanceUuid;
    }

    private function overBy(int $used, ?int $seats): int {
        if ($seats === null || $seats <= 0) return 0;
        // v3.100.6 — unlimited tier (999999) can never be "over".
        if ($seats >= 999999) return 0;
        return max(0, $used - $seats);
    }

    private function emptyStatus(
        string $instanceUuid, int $seatsUsed, ?int $lastAt, ?array $lastPayload, ?string $reason = null,
    ): array {
        return [
            'hasKey'               => false,
            'valid'                => false,
            'kind'                 => null,
            'seats'                => null,
            'isTrial'              => false,
            'licenseId'            => null,
            'customer'             => null,
            'uuid'                 => null,
            'expiresAt'            => null,
            'daysRemaining'        => null,
            'enforcementLevel'     => 'unlicensed',
            'graceRemaining'       => null,
            'invalidReason'        => $reason,
            'instanceUuid'         => $instanceUuid,
            'seatsUsed'            => $seatsUsed,
            'seatsOverBy'          => 0,
            'lastTelemetryAt'      => $lastAt,
            'lastTelemetryPayload' => $lastPayload,
        ];
    }

    private function getIntConfig(string $key): ?int {
        $v = $this->config->getAppValue(Application::APP_ID, $key, '');
        return $v === '' ? null : (int)$v;
    }

    private function getJsonConfig(string $key): ?array {
        $v = $this->config->getAppValue(Application::APP_ID, $key, '');
        if ($v === '') return null;
        $d = json_decode($v, true);
        return is_array($d) ? $d : null;
    }
}
