<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Util\Jwt;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 *  - lic         — public license id (e.g. lic_2026_abc123)
 *  - uuid        — Nextcloud instanceid this license is bound to
 *  - kind        — "airgapped" (only kind we issue from v4.3.20 on;
 *                  historical "connected" rows still verify)
 *  - seats       — null on airgapped (kept in the claim shape for legacy
 *                  Connected rows still in the wild)
 *  - is_trial    — bool; drives UI copy + reset-marker enforcement
 *  - customer    — human-readable customer id, for admin display only
 *  - paid_until  — unix ts of the moment paid entitlement ends (added
 *                  v4.3.20 for grace-period rendering; older JWTs without
 *                  this claim are treated as paid_until = exp)
 *  - grace_days  — grace-period length in days (added v4.3.20; older JWTs
 *                  without this claim default to 0)
 *  - iat/nbf/exp — standard JWT time claims (seconds since epoch)
 *
 * The instance verifies the JWT offline against a hardcoded public key
 * (see PUBLIC_KEY_PEM). No runtime call is made to enforce validity —
 * everything happens in-process, once, cached in-request.
 *
 * NO OUTBOUND CALLS
 * -----------------
 * Fully air-gapped, fully manual (v4.3.22+). The instance never contacts
 * the licensing back-end for anything: no trials, no daily check-ins, no
 * install/uninstall pings, no renewed-JWT downloads. Trials and paid
 * licenses are issued by hand from the licensing dashboard in response
 * to email, and the customer pastes the JWT into the License tab.
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

    /** Days past exp before we escalate from grace to soft-lock. */
    private const GRACE_DAYS = 30;

    /** IConfig app key names. All namespaced under app='teamhub'. */
    private const CFG_JWT                    = 'license_jwt';
    private const CFG_LAST_TELEMETRY_AT      = 'license_last_telemetry_at';
    private const CFG_LAST_TELEMETRY_PAYLOAD = 'license_last_telemetry_payload';

    public function __construct(
        private IConfig          $config,
        private IDBConnection    $db,
        private IUserSession     $userSession,
        // Reuse the working queries TelemetryService already exposes for
        // the Statistics tab. countLicensedSeats() delegates to
        // countUniqueTeamMembers() for the seat-cap comparison.
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
     *   paidUntil: int|null,
     *   paidDaysRemaining: int|null,
     *   graceDays: int,
     *   enforcementLevel: 'none'|'grace'|'soft-lock'|'unlicensed',
     *   graceRemaining: int|null,
     *   invalidReason: string|null,
     *   instanceUuid: string,
     *   seatsUsed: int,
     *   seatsOverBy: int,
     *   seatEnforcement: 'none'|'over-warn'|'over-lock',
     *   seatCap: int|null,
     *   seatLockAt: int|null,
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
                    // Legacy JWTs (issued before the air-gapped-tiers
                    // migration) don't carry paid_until / grace_days.
                    // Fall back to exp so callers can still reason about
                    // "when did paid entitlement end".
                    'paidUntil'            => (int)($p['paid_until'] ?? $expiry),
                    'paidDaysRemaining'    => max(0, intdiv(((int)($p['paid_until'] ?? $expiry)) - $now, 86400)),
                    'graceDays'            => (int)($p['grace_days'] ?? 0),
                    'enforcementLevel'     => $level,
                    'graceRemaining'       => $level === 'grace' ? $graceLeft : 0,
                    'invalidReason'        => $e->getMessage(),
                    'instanceUuid'         => $instanceUuid,
                    'seatsUsed'            => $seatsUsed,
                    'seatsOverBy'          => $this->overBy($seatsUsed, $p['seats'] ?? null),
                    ...$this->computeSeatEnforcement($seatsUsed, $p['seats'] ?? null),
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
            // Legacy JWTs (issued before the air-gapped-tiers migration)
            // don't carry paid_until / grace_days. Fall back to exp so
            // callers can still reason about "when does paid entitlement end".
            'paidUntil'            => (int)($claims['paid_until'] ?? $expiry),
            'paidDaysRemaining'    => max(0, intdiv(((int)($claims['paid_until'] ?? $expiry)) - $now, 86400)),
            'graceDays'            => (int)($claims['grace_days'] ?? 0),
            'enforcementLevel'     => 'none',
            'graceRemaining'       => null,
            'invalidReason'        => null,
            'instanceUuid'         => $instanceUuid,
            'seatsUsed'            => $seatsUsed,
            'seatsOverBy'          => $this->overBy($seatsUsed, $claims['seats'] ?? null),
            ...$this->computeSeatEnforcement($seatsUsed, $claims['seats'] ?? null),
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
     * TimeLogs, Milestones).
     *
     * Temporal: 'none' and 'grace' allow writes; 'soft-lock' and
     * 'unlicensed' don't.
     * Seat: 'over-lock' blocks writes too (customer went past 120% of
     * their cap). 'over-warn' does not block — banner only.
     */
    public function allowsAdvancedWrites(): bool {
        $status = $this->getStatus();
        $level  = $status['enforcementLevel'] ?? 'unlicensed';
        if ($level !== 'none' && $level !== 'grace') return false;
        if (($status['seatEnforcement'] ?? 'none') === 'over-lock') return false;
        return true;
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
     * existing ones grandfather. Same policy for seat over-lock — once
     * the customer is >120% of their seat cap, no new Advanced teams.
     * over-warn (100–120% of cap) does not block creation.
     */
    public function allowsAdvancedCreation(): bool {
        $status = $this->getStatus();
        if (($status['enforcementLevel'] ?? 'unlicensed') !== 'none') return false;
        if (($status['seatEnforcement']   ?? 'none') === 'over-lock') return false;
        return true;
    }

    /**
     * Save a fresh JWT after verifying it. Called from LicenseController
     * on PUT /license. Any verification failure throws.
     */
    public function saveKey(string $jwt): array {
        $jwt = trim($jwt);
        $claims = $this->verifyJwt($jwt);  // throws on any failure
        $this->config->setAppValue(Application::APP_ID, self::CFG_JWT, $jwt);
        return $claims;
    }

    /**
     * Wipe the saved JWT from appconfig. Kept as a utility — no automated
     * caller wires it up under the air-gapped model, but a future "reset
     * license" affordance in the admin UI can hit it.
     *
     * Advanced projects created while the license was active stay
     * grandfathered per the enforcement-lifecycle docblock at the top
     * of this file — this method does NOT touch teamhub_project rows.
     */
    public function clearKey(): void {
        $this->config->deleteAppValue(Application::APP_ID, self::CFG_JWT);
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

    /**
     * Compute seat-enforcement state for the status envelope.
     *
     *   none      — no cap (unlicensed / legacy JWT / unlimited), or under cap.
     *   over-warn — count > cap but within 20% grace.
     *   over-lock — count > 120% of cap.
     *
     * Returned as a fragment ready to spread into the status array so all
     * three envelope paths (valid, grace-peek, empty) can share it.
     *
     * @return array{seatEnforcement: 'none'|'over-warn'|'over-lock', seatCap: int|null, seatLockAt: int|null}
     */
    private function computeSeatEnforcement(int $used, ?int $seats): array {
        // No cap to compare against, or unlimited tier — nothing to enforce.
        if ($seats === null || $seats <= 0 || $seats >= 999999) {
            return ['seatEnforcement' => 'none', 'seatCap' => $seats, 'seatLockAt' => null];
        }
        $lockAt = (int)ceil($seats * 1.2);
        if ($used > $lockAt) {
            return ['seatEnforcement' => 'over-lock', 'seatCap' => $seats, 'seatLockAt' => $lockAt];
        }
        if ($used > $seats) {
            return ['seatEnforcement' => 'over-warn', 'seatCap' => $seats, 'seatLockAt' => $lockAt];
        }
        return ['seatEnforcement' => 'none', 'seatCap' => $seats, 'seatLockAt' => $lockAt];
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
            'paidUntil'            => null,
            'paidDaysRemaining'    => null,
            'graceDays'            => 0,
            'enforcementLevel'     => 'unlicensed',
            'graceRemaining'       => null,
            'invalidReason'        => $reason,
            'instanceUuid'         => $instanceUuid,
            'seatsUsed'            => $seatsUsed,
            'seatsOverBy'          => 0,
            'seatEnforcement'      => 'none',
            'seatCap'              => null,
            'seatLockAt'           => null,
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
