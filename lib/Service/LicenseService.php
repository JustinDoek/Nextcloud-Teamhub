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
 *  - grace_days  — grace-period length in days (added v4.3.20). Informational
 *                  only since v4.10.12: the grace window is fixed by the
 *                  Commercial Licence and Support Agreement (art. 13.4 / 14.3),
 *                  see PAID_GRACE_DAYS and graceDaysFor().
 *  - iat/nbf/exp — standard JWT time claims (seconds since epoch)
 *
 * The instance verifies the JWT offline against a hardcoded public key
 * (see PUBLIC_KEY_PEM). No runtime call is made to enforce validity —
 * everything happens in-process, once, cached in-request.
 *
 * OUTBOUND CALLS — NONE FOR VALIDATION, ONE FOR METERED BILLING
 * -------------------------------------------------------------
 * **Validation is still entirely offline, for every licence kind.** The
 * signature is checked in-process against PUBLIC_KEY_PEM; nothing is ever
 * fetched to decide whether a key is good. There are no trials fetched, no
 * daily check-ins, no install/uninstall pings and no renewed-JWT downloads.
 * Keys are issued by hand from the portal and pasted into the License tab.
 *
 * **What changed in v4.13.30** (docs/metered-licensing-architecture.md): a
 * licence whose `kind` claim is `metered` has no seat cap and instead reports
 * its effective member count twice a month, so the customer is billed for
 * what they use rather than for a 50-seat band. That report is the app's only
 * licensing-related outbound call, it is made by SeatReportService and never
 * from this class, and it is **a property of the licence, not of the app**:
 * a banded key makes no outbound call on any app version, which is what the
 * air-gapped pilot depends on.
 *
 * Before v4.13.30 this section read "fully air-gapped, fully manual… the
 * instance never contacts the licensing back-end for anything". That is now
 * true of a banded licence only, and `docs/nextcloud-admin/licensing.md` said
 * the same thing to customers; both had to be rewritten rather than quietly
 * left to rot.
 *
 * ENFORCEMENT LEVELS
 * ------------------
 *  - 'none'      — valid license (paid or trial). Feature fully enabled.
 *  - 'grace'     — paid license expired less than PAID_GRACE_DAYS ago.
 *                  Advanced creation is blocked; existing Advanced teams
 *                  still fully work; banner in admin UI shows a countdown.
 *                  A trial never enters this state (no grace, art. 14.3).
 *  - 'soft-lock' — PAID_GRACE_DAYS or more past expiry (a trial: at expiry).
 *                  Existing Advanced teams
 *                  become read-only: Compass/health widget/timeline hidden,
 *                  Budget/Time/Milestone write endpoints reject. Non-Advanced
 *                  features untouched. Pasting a new JWT immediately restores.
 *  - 'unlicensed'— no JWT saved at all. Same effect as soft-lock for new
 *                  Advanced creation, but no data has ever been generated so
 *                  there's nothing to lock down.
 *
 * A metered licence has a **second** way into soft-lock, independent of its
 * dates: REPORT_LOCK_DAYS of silence (reportStateFor()). It is the only thing
 * in the app where reporting state changes what is allowed, it fires on a key
 * that is otherwise perfectly valid, and `invalidReason` says so in words so
 * an administrator is never left guessing why a current licence stopped
 * working. A banded licence and a trial can never reach it.
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
     * Public half of the RS256 signing keypair the licensing portal signs
     * with.
     *
     * **This is the real key, and the comment that used to say otherwise was
     * wrong.** It read "Ship-time value below is a PLACEHOLDER — every real
     * verification against it will fail signature check", which would mean no
     * licence has ever verified on any instance. The pilot's working licence
     * was the clue.
     *
     * Checked on 2026-10-26 against `portal-private/storage/keys/public.pem`
     * on the portal host: the key material is identical (the file itself has
     * CRLF line endings, so the two are not byte-equal — compare the base64,
     * not the bytes). Proven the way that actually matters: a licence signed
     * by that keypair's private half verifies against this constant through
     * `Jwt::verifyRs256()`.
     *
     * The private half lives only on the portal host, at
     * `portal-private/storage/keys/private.pem`, and is read by
     * `lic_sign_version()` through the `private_key_path` config value.
     *
     * Rotating it means shipping an app update, and every licence in the field
     * has to be reissued from the new key — so it is a release, not a task.
     *   openssl genrsa -out private.pem 2048
     *   openssl rsa -in private.pem -pubout -out public.pem
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
     * Grace window of a *paid* key, in days after `exp`, before grace
     * escalates to soft-lock (v4.10.12, was 30). The number is the
     * Commercial Licence and Support Agreement's, art. 13.4: "a Grace Period
     * of fourteen days immediately following the expiry of the Licence
     * Period". A trial has none (art. 14.3) — see graceDaysFor().
     */
    public const PAID_GRACE_DAYS = 14;

    /**
     * Days of silence from a metered licence at which each rung of the
     * reporting ladder is reached (v4.13.30, docs/metered-licensing-architecture.md
     * §6.5). A period is at most sixteen days, so twenty is slack for a cron
     * gap rather than a grace.
     *
     * Forty-five is the floor Justin chose over a recommended seventy-five,
     * which moves the risk onto the two warnings: an instance that misses its
     * 1st-of-month report has 24 days of notice, not two months. Shortening
     * these further leaves no room for a holiday period, and the first
     * customer locked out by a proxy change is a refund and a reputation.
     */
    public const REPORT_WARN_DAYS     = 21;
    public const REPORT_ESCALATE_DAYS = 32;
    public const REPORT_LOCK_DAYS     = 45;

    /** The licence `kind` claim that carries a reporting obligation. */
    public const KIND_METERED = 'metered';

    /**
     * IConfig app key names. All namespaced under app='teamhub'.
     *
     * The CFG_SEAT_REPORT_* keys are **written by SeatReportService and read
     * here**, and that direction is load-bearing: this service already
     * depends on TelemetryService and SeatReportService depends on this one,
     * so a dependency back on SeatReportService would be a constructor cycle
     * NC's DI cannot resolve (the same trap TelemetryService::isEnabled()
     * records). The ladder is therefore a static pure function plus five
     * config reads — the shape §2.140 used for graceDaysFor().
     */
    public const  CFG_JWT                      = 'license_jwt';
    public const  CFG_SEAT_REPORT_LAST_OK_AT   = 'license_seat_report_last_ok_at';
    public const  CFG_SEAT_REPORT_LAST_PERIOD  = 'license_seat_report_last_period';
    public const  CFG_SEAT_REPORT_LAST_PAYLOAD = 'license_seat_report_last_payload';
    public const  CFG_SEAT_REPORT_LAST_ERROR   = 'license_seat_report_last_error';
    public const  CFG_SEAT_REPORT_SINCE        = 'license_seat_report_since';

    /**
     * `getStatus()`'s result for the rest of this request (v4.8.31).
     *
     * It is not a cheap call — it verifies an RS256 signature and runs
     * `countLicensedSeats()`, which is a database aggregate over
     * `circles_membership` — and it is uncached, so every caller in a request
     * paid the whole thing again. Nothing depended on that; the licence cannot
     * change mid-request except through `saveKey()` / `clearKey()`, and both
     * clear this.
     */
    private ?array $statusMemo = null;

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
     *   reportState: 'not-required'|'ok'|'due'|'overdue'|'locked',
     *   reportOverdueDays: int,
     *   reportLastOkAt: int|null,
     *   reportLastPeriod: string|null,
     *   reportNextDueAt: int|null,
     *   reportLockAt: int|null,
     *   reportLastPayload: array|null,
     *   reportLastError: string|null
     * }
     *
     * v4.13.30 — `lastTelemetryAt` and `lastTelemetryPayload` are **gone**.
     * They were in this envelope from the connected-licensing model and
     * **nothing ever wrote them**: both were null on every instance, for
     * every release, and no caller in `src/` ever read them. They are replaced
     * rather than repurposed, because a seat report is not telemetry (see
     * SeatReportService's docblock) and a field named for the thing we promise
     * *not* to do on a licensed instance is the wrong place to record what we
     * now do.
     */
    public function getStatus(): array {
        if ($this->statusMemo !== null) {
            return $this->statusMemo;
        }

        return $this->statusMemo = $this->computeStatus();
    }

    /**
     * True when a licence key is stored at all — one appconfig read, no
     * signature check and no database query (v4.8.31).
     *
     * **Not an authorisation check and must never be used as one.** It says
     * nothing about whether the key is valid, bound to this instance, or
     * expired past its grace window; a soft-locked instance answers true.
     *
     * It exists for one job: letting a hot path skip `getStatus()` on the
     * instances where the answer is certain. `FilesScriptsListener` runs on
     * every Files-app page load for every user, including users in no team,
     * and its contract is that an instance not using file reviews pays nothing
     * for them. An instance with no key can be answered from a single config
     * read; one with a key pays the real check, and is the only kind that could
     * have the feature anyway.
     */
    public function hasLicenseKey(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_JWT, '') !== '';
    }

    private function computeStatus(): array {
        $now = time();
        $instanceUuid = $this->getInstanceUuid();
        $seatsUsed    = $this->countLicensedSeats();

        $rawJwt = $this->config->getAppValue(Application::APP_ID, self::CFG_JWT, '');
        if ($rawJwt === '') {
            return $this->emptyStatus($instanceUuid, $seatsUsed);
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
                $p = $peeked['payload'];
                [$level, $graceLeft, $graceDays] = self::expiredLevel($p, $expiry, $now);
                $report = $this->reportFragment($p, $now);
                if ($report['reportState'] === 'locked') {
                    // Expired *and* silent. Grace is for a customer who is
                    // late renewing, not for one we cannot bill at all.
                    $level     = 'soft-lock';
                    $graceLeft = 0;
                }
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
                    'graceDays'            => $graceDays,
                    'enforcementLevel'     => $level,
                    'graceRemaining'       => $level === 'grace' ? $graceLeft : 0,
                    'invalidReason'        => $e->getMessage(),
                    'instanceUuid'         => $instanceUuid,
                    'seatsUsed'            => $seatsUsed,
                    'seatsOverBy'          => $this->overBy($seatsUsed, $p['seats'] ?? null),
                    ...$this->computeSeatEnforcement($seatsUsed, $p['seats'] ?? null),
                    ...$report,
                ];
            }
            // Signature mismatch, UUID mismatch, malformed — treat as
            // unlicensed. Admin sees "invalid key: <reason>" and can
            // re-paste.
            return $this->emptyStatus($instanceUuid, $seatsUsed, $e->getMessage());
        }

        $expiry = (int)$claims['exp'];
        $daysRem = max(0, intdiv($expiry - $now, 86400));

        // Hoisted so enforcement can read it: a metered licence that has gone
        // silent past REPORT_LOCK_DAYS is soft-locked even though its key is
        // still inside its term. This is the only place reporting state
        // changes what the app allows — see the ladder on reportStateFor().
        $report = $this->reportFragment($claims, $now);

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
            'graceDays'            => self::graceDaysFor($claims),
            'enforcementLevel'     => $report['reportState'] === 'locked' ? 'soft-lock' : 'none',
            'graceRemaining'       => null,
            'invalidReason'        => $report['reportState'] === 'locked'
                ? 'This licence requires a twice-monthly member-count report, and none has reached the licensing service for '
                    . $report['reportOverdueDays'] . ' days.'
                : null,
            'instanceUuid'         => $instanceUuid,
            'seatsUsed'            => $seatsUsed,
            'seatsOverBy'          => $this->overBy($seatsUsed, $claims['seats'] ?? null),
            ...$this->computeSeatEnforcement($seatsUsed, $claims['seats'] ?? null),
            ...$report,
        ];
    }

    /**
     * Drop the per-request memo so the next `getStatus()` recomputes.
     *
     * `saveKey()` and `clearKey()` do this for themselves. This is for
     * SeatReportService, which writes the five CFG_SEAT_REPORT_* keys this
     * service reads: without it, a request that sends a report and then
     * renders the panel would show the state from before the send.
     */
    public function forgetStatus(): void {
        $this->statusMemo = null;
    }

    /**
     * Whether a licence `kind` claim carries a reporting obligation.
     *
     * **An explicit claim, deliberately, rather than inferring "metered" from
     * an unlimited seat count.** An unlimited licence has been issuable since
     * v3.100.6 — `computeSeatEnforcement()` uncaps at 999999 — so inferring
     * the obligation from the cap would retroactively convert any unlimited
     * licence already in the field into a reporting one, and then, at
     * REPORT_LOCK_DAYS, soft-lock it. A licence's obligations must not change
     * because the app was upgraded.
     */
    public static function isMetered(?string $kind): bool {
        return $kind === self::KIND_METERED;
    }

    /**
     * Where a licence sits on the reporting ladder (v4.13.30).
     *
     *   not-required — a banded licence. Never reports, never nagged.
     *   ok           — reported within REPORT_WARN_DAYS.
     *   due          — a banner, and the admin notification.
     *   overdue      — the banner names the date the instance will lock.
     *   locked       — enforcement becomes soft-lock.
     *
     * **A trial never reaches `locked`** (Justin, 2026-10-26). It reports and
     * the figure is recorded, but the ladder stops at `overdue`: a trial ends
     * by itself at `exp`, and `thp_trial_registry` on the portal is unique per
     * instance for ever — so a wrongly locked trial cannot be re-requested and
     * the prospect is simply gone. **If a later edit "simplifies" the trial arm
     * away as redundant, that is the regression**; the test for it names this.
     *
     * Static and pure so the whole ladder is testable without a container,
     * a clock or a licence — and so the numbers live in exactly one place.
     *
     * @param array|null $claims    The verified or peeked JWT payload, null when none.
     * @param int|null   $clockFrom Last successful report, else when the key
     *                              was first seen. Null = never measured.
     * @return array{0: 'not-required'|'ok'|'due'|'overdue'|'locked', 1: int}
     *         The state, and days since $clockFrom.
     */
    public static function reportStateFor(?array $claims, ?int $clockFrom, int $now): array {
        if ($claims === null || !self::isMetered(is_string($claims['kind'] ?? null) ? $claims['kind'] : null)) {
            return ['not-required', 0];
        }
        // Nothing to measure yet: a licence pasted five minutes ago has not
        // missed anything. SeatReportService::ensureClockStarted() writes the
        // start, so this is only the window between saving a key and the next
        // job tick.
        if ($clockFrom === null || $clockFrom <= 0) {
            return ['ok', 0];
        }
        $days = intdiv(max(0, $now - $clockFrom), 86400);

        if ($days >= self::REPORT_LOCK_DAYS && empty($claims['is_trial'])) {
            return ['locked', $days];
        }
        if ($days >= self::REPORT_ESCALATE_DAYS) {
            return ['overdue', $days];
        }
        if ($days >= self::REPORT_WARN_DAYS) {
            return ['due', $days];
        }
        return ['ok', $days];
    }

    /**
     * The reporting half of the status envelope, ready to spread into all
     * three paths (valid, expired-peek, empty) the way
     * `computeSeatEnforcement()` already is.
     *
     * @param array|null $claims The verified or peeked payload, null when none.
     */
    private function reportFragment(?array $claims, int $now): array {
        // Kind first, before any config read: a banded licence pays nothing
        // for a mechanism it is not subject to, and must not be shown a
        // countdown it can never reach.
        if ($claims === null || !self::isMetered(is_string($claims['kind'] ?? null) ? $claims['kind'] : null)) {
            return [
                'reportState'       => 'not-required',
                'reportOverdueDays' => 0,
                'reportLastOkAt'    => null,
                'reportLastPeriod'  => null,
                'reportNextDueAt'   => null,
                'reportLockAt'      => null,
                'reportLastPayload' => null,
                'reportLastError'   => null,
            ];
        }

        $lastOkAt  = $this->getIntConfig(self::CFG_SEAT_REPORT_LAST_OK_AT);
        $since     = $this->getIntConfig(self::CFG_SEAT_REPORT_SINCE);
        $clockFrom = $lastOkAt ?? $since;
        [$state, $days] = self::reportStateFor($claims, $clockFrom, $now);

        $error  = $this->config->getAppValue(Application::APP_ID, self::CFG_SEAT_REPORT_LAST_ERROR, '');
        $period = $this->config->getAppValue(Application::APP_ID, self::CFG_SEAT_REPORT_LAST_PERIOD, '');

        return [
            'reportState'       => $state,
            'reportOverdueDays' => $days,
            'reportLastOkAt'    => $lastOkAt,
            'reportLastPeriod'  => $period !== '' ? $period : null,
            'reportNextDueAt'   => SeatReportService::nextPeriodStart($now),
            // The date the instance locks, so the banner can name it rather
            // than leaving the administrator to do the arithmetic. Null for a
            // trial, which never locks.
            'reportLockAt'      => (!empty($claims['is_trial']) || $clockFrom === null || $clockFrom <= 0)
                ? null
                : $clockFrom + self::REPORT_LOCK_DAYS * 86400,
            'reportLastPayload' => $this->getJsonConfig(self::CFG_SEAT_REPORT_LAST_PAYLOAD),
            'reportLastError'   => $error !== '' ? $error : null,
        ];
    }

    /**
     * The grace window a key is entitled to, in days after `exp`: the
     * contract's fourteen for a paid key, none for a trial (art. 14.3: "has no
     * Grace Period"). The JWT's own `grace_days` claim is not consulted — the
     * agreement sets the number, not the key.
     *
     * Static and pure on purpose: TelemetryService applies the same rule from
     * a peeked payload and cannot inject this service (circular dependency,
     * see its isEnabled()).
     *
     * @param array $claims The (verified or peeked) JWT payload.
     */
    public static function graceDaysFor(array $claims): int {
        return !empty($claims['is_trial']) ? 0 : self::PAID_GRACE_DAYS;
    }

    /**
     * Enforcement of a key whose `exp` has passed.
     *
     * Grace runs for exactly graceDaysFor() × 24 h from `exp`: on the
     * fourteenth day a paid key is soft-locked, and a trial — zero days —
     * is soft-locked the moment it expires. Before v4.10.12 the comparison was
     * `>`, which quietly gave a thirty-first day.
     *
     * @param array $claims The (verified or peeked) JWT payload.
     * @param int   $expiry `exp`, seconds since epoch — already < $now.
     * @param int   $now    Seconds since epoch.
     * @return array{0: 'grace'|'soft-lock', 1: int, 2: int} level, grace days
     *         left (0 once soft-locked), grace days the key is entitled to.
     */
    public static function expiredLevel(array $claims, int $expiry, int $now): array {
        $graceDays = self::graceDaysFor($claims);
        $daysPast  = intdiv(max(0, $now - $expiry), 86400);
        if ($daysPast >= $graceDays) {
            return ['soft-lock', 0, $graceDays];
        }
        return ['grace', $graceDays - $daysPast, $graceDays];
    }

    /**
     * Enforcement level as a short string.
     *
     * Callers should treat 'grace' and 'none' the same for READ paths (both
     * allow read) but distinct for the banner UI.
     *
     * **This used to say "cheap".** It is not: it reads `getStatus()`, which
     * verifies an RS256 signature and counts licensed seats through a database
     * aggregate. Since v4.8.31 that result is memoised per request, so calling
     * this more than once in a request is free — but the first call is not, and
     * a hot path that runs on every page load of somebody else's app should
     * gate on `hasLicenseKey()` before reaching for it.
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
     * point of the grace window is giving a lapsed paid customer fourteen
     * days to renew without disrupting existing operations. A trial has no
     * grace: it is soft-locked at expiry.
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
        $this->adoptReportingClock($claims);
        // The request that saves a key goes on to render the admin panel from
        // the new state; a memo from before the write would show the old one.
        $this->statusMemo = null;
        return $claims;
    }

    /**
     * Point the reporting clock at the key that was just saved.
     *
     * Three cases, and the distinction between the last two is what stops the
     * soft-lock being a formality:
     *
     * - **A banded key** clears every reporting value. A customer moving from
     *   metered back to banded must not keep an overdue clock for an
     *   obligation their new key does not carry.
     * - **A different metered key** (a renewal, an upgrade, a replacement
     *   issued after a billing conversation) starts a fresh clock. This is
     *   what makes "pasting a new key immediately restores" true for a
     *   reporting lock as well as an expiry one — and it is deliberately the
     *   *only* way back, because it requires us to have issued something.
     * - **The same metered key again** changes nothing. Re-pasting the key you
     *   already have is not a renewal, and if it reset the clock the ladder
     *   would be a button the locked instance could press itself.
     */
    private function adoptReportingClock(array $claims): void {
        $keys = [
            self::CFG_SEAT_REPORT_LAST_OK_AT,
            self::CFG_SEAT_REPORT_LAST_PERIOD,
            self::CFG_SEAT_REPORT_LAST_PAYLOAD,
            self::CFG_SEAT_REPORT_LAST_ERROR,
            self::CFG_SEAT_REPORT_SINCE,
        ];

        if (!self::isMetered(is_string($claims['kind'] ?? null) ? $claims['kind'] : null)) {
            foreach ($keys as $key) {
                $this->config->deleteAppValue(Application::APP_ID, $key);
            }
            return;
        }

        $incoming = (string)($claims['lic'] ?? '');
        $lastPayload = $this->getJsonConfig(self::CFG_SEAT_REPORT_LAST_PAYLOAD);
        $reportedFor = is_array($lastPayload) ? (string)($lastPayload['lic'] ?? '') : '';
        $since       = $this->getIntConfig(self::CFG_SEAT_REPORT_SINCE);

        // Same licence id as the one we last reported for → leave the clock
        // exactly where it is.
        if ($incoming !== '' && $incoming === $reportedFor) {
            return;
        }

        // A new metered licence, or the first one on this instance.
        if ($incoming !== '' && $reportedFor !== '') {
            foreach ([
                self::CFG_SEAT_REPORT_LAST_OK_AT,
                self::CFG_SEAT_REPORT_LAST_PERIOD,
                self::CFG_SEAT_REPORT_LAST_PAYLOAD,
                self::CFG_SEAT_REPORT_LAST_ERROR,
            ] as $key) {
                $this->config->deleteAppValue(Application::APP_ID, $key);
            }
            $this->config->setAppValue(Application::APP_ID, self::CFG_SEAT_REPORT_SINCE, (string)time());
            return;
        }

        if ($since === null || $since <= 0) {
            $this->config->setAppValue(Application::APP_ID, self::CFG_SEAT_REPORT_SINCE, (string)time());
        }
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
        // No key, no reporting obligation and nothing to measure silence
        // against. Leaving the clock behind would mean a later metered key
        // inherited the gap before it was issued.
        foreach ([
            self::CFG_SEAT_REPORT_LAST_OK_AT,
            self::CFG_SEAT_REPORT_LAST_PERIOD,
            self::CFG_SEAT_REPORT_LAST_PAYLOAD,
            self::CFG_SEAT_REPORT_LAST_ERROR,
            self::CFG_SEAT_REPORT_SINCE,
        ] as $key) {
            $this->config->deleteAppValue(Application::APP_ID, $key);
        }
        $this->statusMemo = null;
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
        string $instanceUuid, int $seatsUsed, ?string $reason = null,
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
            ...$this->reportFragment(null, time()),
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
