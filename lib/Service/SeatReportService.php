<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Twice-monthly effective-member report for metered licences (v4.13.30).
 *
 * Full design: docs/metered-licensing-architecture.md.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every licence before this one was banded: a 50-seat band, priced at the
 * band, with no outbound call of any kind. That shape exists for one customer
 * who cannot phone home, and everybody else pays for it — a partner holding a
 * 50-seat band pays for 50 whether the instance carries 2 members or 50.
 *
 * A metered licence has no seat cap and instead reports the instance's
 * effective member count twice a month, so the customer is charged for what
 * they actually use. Banded licences are unchanged and keep reporting nothing.
 *
 * THE INVARIANT THAT MATTERS MOST
 * -------------------------------
 * **Reporting is a property of the licence, not of the app.** There is no
 * "phone home" setting. `reportIfDue()` returns on its first condition unless
 * a *signed* JWT says `kind: "metered"`, so on a banded instance no config is
 * read, no count is taken and no network code is reachable. That ordering is
 * the design, not tidiness: the air-gapped pilot must be untouchable by this
 * class, and it is untouchable by construction rather than by care.
 *
 * NOT TELEMETRY, AND MUST NOT BECOME IT
 * -------------------------------------
 * TelemetryService looks like this class and is its opposite. Telemetry runs
 * *only when unlicensed*, is anonymous, carries a random UUID and stops the
 * moment a customer pays — that is a compliance promise made to the customer's
 * DPO (DESIGN §2.64), and the Compliance tab's "Telemetry: off" pill is the
 * assurance. A seat report runs *only when licensed*, is identified by licence
 * and instance, and is billing data. Folding either into the other would put
 * a "nothing leaves this box" pill on a panel of an instance that is sending.
 *
 * The one thing taken from TelemetryService is the number:
 * `countUniqueTeamMembers()` (DESIGN §2.34). The figure the customer reads on
 * their own Reporting tab is the figure we invoice. There is deliberately no
 * second counting rule for billing.
 *
 * THE PERIOD, AND WHY IT IS NOT AN INTERVAL
 * -----------------------------------------
 * The calendar month in halves: `H1` opens on the 1st, `H2` on the 16th, and
 * the period key is `YYYY-MM-H1` / `YYYY-MM-H2`. "Due" means the current half
 * has no successful report yet, so the job can tick every twelve hours, send
 * at most once per half, and catch up after an outage. A `setInterval(15 days)`
 * job would drift a day a month and silently slide out of the period it was
 * meant to cover.
 *
 * Only the *latest period* in a month is billed (the month's last snapshot).
 * The first report is the fallback: an instance that goes dark on the 20th
 * still has a figure for that month.
 *
 * PRIVACY
 * -------
 * Aggregate only. No hostname, no instance URL, no user ids, no team names,
 * no content — the bar TelemetryService holds itself to, and the reason the
 * payload is stored verbatim and printed back to the administrator on the
 * License tab. What is sent is exactly what is shown.
 */
class SeatReportService {

    /**
     * Where reports go.
     *
     * A constant and not a JWT claim, deliberately. The licence is the
     * artifact being validated; a destination URL carried inside it would be
     * a redirect that validates itself. Rotation is an app update — the same
     * contract LicenseService::PUBLIC_KEY_PEM already has, for the same
     * reason.
     *
     * **No `/portal/` segment, confirmed by Justin on 2026-10-26.** The
     * subdomain's document root *is* `public_html/portal`, so the receiver
     * lives at `public_html/portal/api/seat-report.php`. The portal's own
     * `portal_base_url` config value ends in `/portal/` and is misleading on
     * this point: it is used for links in notification mail, not for
     * resolving this path.
     *
     * **The extensionless URL requires a rewrite on the portal host, and that
     * rewrite is part of this contract** (2026-11-06). This docblock used to
     * assert that the `.php` file "answers at `/api/seat-report`". It does
     * not, by itself: the host has MultiViews off, so the clean URL 404s
     * unless `public_html/portal/api/.htaccess` maps it. That file now
     * carries the rewrite, and it must keep carrying it for as long as this
     * constant omits the extension. The alternative was putting `.php` in the
     * constant, which would have tied the published endpoint to the
     * implementation language and left every metered instance already in the
     * field pointing at a dead URL until it upgraded.
     *
     * Getting it wrong is silent: a refused report is recorded and retried
     * rather than raised, so a 404 would go unnoticed until the 45-day ladder
     * locked the first customer. That is exactly how long this one hid. If it
     * ever has to move, `seat_report_url` overrides this without a release.
     */
    public const REPORT_URL = 'https://portal.doekworks.eu/api/seat-report';

    /**
     * Override for our own staging runs against a test portal. Deliberately
     * undocumented for customers: it is not a feature, it is a test seam.
     * `occ config:app:set teamhub seat_report_url --value https://…`
     */
    private const CFG_URL_OVERRIDE = 'seat_report_url';

    /** HTTP timeout in seconds. */
    private const TIMEOUT = 10;

    public function __construct(
        private IConfig          $config,
        private IClientService   $clientService,
        private LicenseService   $licenseService,
        private TelemetryService $telemetryService,
        private LoggerInterface  $logger,
    ) {}

    // ── Period arithmetic (pure) ────────────────────────────────────

    /**
     * The reporting period a timestamp falls in: `YYYY-MM-H1` for days 1–15,
     * `YYYY-MM-H2` for day 16 onward.
     *
     * Static and pure so the period can be reasoned about in a unit test
     * without a container, a clock or a licence.
     */
    public static function periodFor(int $ts): string {
        $half = ((int)gmdate('j', $ts) <= 15) ? 'H1' : 'H2';
        return gmdate('Y-m', $ts) . '-' . $half;
    }

    /**
     * The first second of the period after the one $ts falls in — what the
     * admin panel renders as "next report due".
     *
     * `gmmktime`, not `mktime`: a period boundary has to be the same instant
     * on the instance and in the portal, and those are routinely in different
     * zones. Mixing a local-time boundary with periodFor()'s UTC one would put
     * a report in the wrong half of the month for up to a day either side of
     * the 1st and the 16th — which is precisely when reports are sent.
     * Month 13 is January of the next year, which gmmktime handles.
     */
    public static function nextPeriodStart(int $ts): int {
        $year  = (int)gmdate('Y', $ts);
        $month = (int)gmdate('n', $ts);
        return ((int)gmdate('j', $ts) <= 15)
            ? (int)gmmktime(0, 0, 0, $month, 16, $year)
            : (int)gmmktime(0, 0, 0, $month + 1, 1, $year);
    }

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Send this period's report if one is owed. Called from SeatReportJob.
     *
     * @return bool True when a report was sent and accepted.
     */
    public function reportIfDue(): bool {
        $status = $this->licenseService->getStatus();
        if (!$this->isMeteredAndHonoured($status)) {
            return false;
        }

        // Start the overdue clock the first time we see a metered licence on
        // this instance. saveKey() normally does this; doing it here too means
        // an instance that acquired the licence some other way (a restored
        // backup, a config import) cannot sit un-clocked and therefore
        // un-lockable — and equally cannot be locked for a window it never
        // had the chance to report in.
        $this->ensureClockStarted();

        $period = self::periodFor(time());
        if ($this->config->getAppValue(Application::APP_ID, LicenseService::CFG_SEAT_REPORT_LAST_PERIOD, '') === $period) {
            return false;
        }

        return $this->send($status, $period);
    }

    /**
     * Send a report now, whether or not this period already has one.
     *
     * Backs `occ teamhub:seat-report` (`Command\SendSeatReport`), which
     * exists because verifying the transport otherwise means waiting up to
     * twelve hours for a background job.
     *
     * **There is no HTTP endpoint for this, deliberately.** This docblock
     * named `POST /api/v1/admin/license/report` until 2026-11-06, and
     * `appinfo/routes.php` has never had such a route: the button it would
     * have backed was removed on 2026-10-26 (see `SendSeatReport`'s own
     * docblock for why a send is an operator action). Do not add the route to
     * make the sentence true.
     *
     * It skips only the already-reported check. The licence conditions still
     * apply, and that is safe because the receiver is
     * idempotent per (licence, period): a second send for a recorded period is
     * answered `already_recorded` and never revises a figure. "The month's
     * latest snapshot" means the latest *period*, not the latest POST.
     *
     * @return array The fresh licence status envelope.
     * @throws \RuntimeException When this licence may not report at all.
     */
    public function reportNow(): array {
        $status = $this->licenseService->getStatus();
        if (!$this->isMeteredAndHonoured($status)) {
            throw new \RuntimeException(
                'This licence does not report member counts. Only a metered licence does.',
            );
        }
        $this->ensureClockStarted();
        $this->send($status, self::periodFor(time()));
        // saveKey()/send() both invalidate the memo, so this is the new state.
        return $this->licenseService->getStatus();
    }

    // ── Internals ───────────────────────────────────────────────────

    /**
     * The gate, in one place and in this order.
     *
     * `kind` first: on a banded instance nothing below this line runs, which
     * is the invariant in the class docblock.
     *
     * **Then `valid` — the key's own validity — and deliberately NOT
     * `enforcementLevel`.** The difference is the whole recovery path. A
     * licence locked for silence (reportState `locked`) has
     * `enforcementLevel: 'soft-lock'` while its key is still perfectly valid,
     * so gating on the level would mean a locked instance could never report
     * again: the banner tells the administrator to restore access and send a
     * report, and the button would have refused. The firewall fix would then
     * have needed a support ticket and a freshly issued key, for an instance
     * that was paying all along.
     *
     * Reporting therefore heals the lock it caused — one accepted report sets
     * the clock, the ladder drops to `ok`, and enforcement returns to `none`
     * on the next request. What stays refused is an instance with nothing to
     * bill: unlicensed, or expired past its grace window (`valid` false and
     * not in grace).
     */
    private function isMeteredAndHonoured(array $status): bool {
        if (!LicenseService::isMetered($status['kind'] ?? null)) return false;
        if (empty($status['hasKey'])) return false;
        // A key inside its term, or one in the contractual grace window after
        // expiry — the customer is still entitled, so there is still something
        // to report.
        return !empty($status['valid'])
            || ($status['enforcementLevel'] ?? 'unlicensed') === 'grace';
    }

    private function ensureClockStarted(): void {
        $since = $this->config->getAppValue(Application::APP_ID, LicenseService::CFG_SEAT_REPORT_SINCE, '');
        if ($since === '' || (int)$since <= 0) {
            $this->config->setAppValue(
                Application::APP_ID,
                LicenseService::CFG_SEAT_REPORT_SINCE,
                (string)time(),
            );
        }
    }

    /**
     * Build, post and record. Returns true only on an accepted report.
     *
     * A failure is recorded as a readable reason and nothing else: the next
     * tick tries the same period again, and the ladder in LicenseService
     * measures the silence. This method never throws — a billing report must
     * not be able to take down a background-job runner.
     */
    private function send(array $status, string $period): bool {
        $payload = [
            'lic'               => (string)($status['licenseId'] ?? ''),
            'uuid'              => (string)($status['instanceUuid'] ?? ''),
            'period'            => $period,
            'counted_at'        => time(),
            'effective_members' => $this->telemetryService->countUniqueTeamMembers(),
            'app_version'       => $this->config->getAppValue(Application::APP_ID, 'installed_version', 'unknown'),
            'nc_version'        => (string)$this->config->getSystemValue('version', 'unknown'),
            'is_trial'          => !empty($status['isTrial']),
        ];

        try {
            $response = $this->clientService->newClient()->post($this->reportUrl(), [
                'timeout' => self::TIMEOUT,
                // Hand a 4xx/5xx back as a response instead of throwing.
                //
                // Guzzle's default is the opposite and Nextcloud's client
                // does not change it (`Client::buildRequestOptions()` merges
                // caller options over its own defaults and never touches this
                // one), so every refused report left through the catch below
                // and `reasonFor()` was unreachable: the whole
                // 401/403/422/5xx table was dead code, and what reached the
                // License tab was Guzzle's own sentence with the response
                // body inside it.
                //
                // Found on 2026-11-06, on the first report that ever went
                // over HTTP: it recorded 500 characters of a hosting error
                // page, `<!DOCTYPE html>` included, as its reason.
                'http_errors' => false,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    // The licence itself is the credential: possession of a
                    // JWT signed by us and bound to this instanceid. The
                    // receiver re-verifies the signature and requires the
                    // body's lic/uuid to match the claims, so a report can
                    // only ever describe the instance its own licence names.
                    'Authorization' => 'Bearer ' . $this->rawJwt(),
                ],
                'body'    => json_encode($payload),
            ]);
            $code        = $response->getStatusCode();
            $contentType = $response->getHeader('Content-Type');
        } catch (\Throwable $e) {
            // With `http_errors` off this is a genuine transport failure:
            // DNS, TLS, no route, or the timeout above. Never an HTTP status.
            $this->recordFailure($e->getMessage());
            return false;
        }

        // 200 = recorded, or recorded earlier and answered idempotently.
        if ($code === 200) {
            $this->recordSuccess($period, $payload);
            return true;
        }

        $this->recordFailure(self::reasonFor($code, $contentType, $this->reportUrl()));
        return false;
    }

    /**
     * Admin-facing reason for a refused report. Each one tells the
     * administrator which of the two parties has to act.
     *
     * Static and public so the whole mapping can be pinned in a unit test
     * without a container, a clock or a network, the way the reporting ladder
     * already is. It had never been executed once before 2026-11-06.
     *
     * **`$contentType` decides whether the status code can be believed at
     * all.** The receiver answers JSON for every refusal it issues, so a 403
     * that arrives as `text/html` did not come from the receiver: something in
     * front of the endpoint refused the request, and the licence is not in
     * question. Reading the code alone would tell that operator their licence
     * had been revoked and send them to support for a problem on the path.
     *
     * Not hypothetical, and the reason this argument exists. On 2026-11-06 a
     * stale `api/.htaccess` on the portal host answered every method with
     * Apache's own 403 page, and the corrected file then answered the
     * extensionless URL with a 404 page. Both would have been reported to the
     * operator as a verdict on their licence.
     */
    public static function reasonFor(int $code, string $contentType = '', string $url = ''): string {
        if (!self::looksLikeReceiver($contentType)) {
            return 'The reporting endpoint did not answer this report (HTTP ' . $code . ').'
                . ' The response did not come from the licensing service, so this licence is not in question.'
                . ($url !== '' ? ' Check that ' . $url . ' still reaches the receiver.' : '')
                . ' The report will be retried automatically.';
        }
        return match (true) {
            $code === 401 => 'The licensing service did not accept this licence key (HTTP 401). Replace the key with the one from your renewal email.',
            $code === 403 => 'The licensing service reports this licence as revoked (HTTP 403). Contact support@doekworks.eu.',
            $code === 422 => 'The licensing service rejected the report as invalid (HTTP 422). This is a bug on our side. Please report it.',
            $code >= 500  => 'The licensing service is unavailable (HTTP ' . $code . '). The report will be retried automatically.',
            default       => 'The report was refused (HTTP ' . $code . '). It will be retried automatically.',
        };
    }

    /**
     * Whether a response plausibly came from the receiver, which answers JSON
     * and nothing else.
     *
     * An empty content type gets the benefit of the doubt: a receiver that
     * one day answers 204 with no body must not be reported as a broken path.
     */
    private static function looksLikeReceiver(string $contentType): bool {
        return $contentType === '' || stripos($contentType, 'json') !== false;
    }

    private function recordSuccess(string $period, array $payload): void {
        $now = time();
        $this->config->setAppValue(Application::APP_ID, LicenseService::CFG_SEAT_REPORT_LAST_OK_AT, (string)$now);
        $this->config->setAppValue(Application::APP_ID, LicenseService::CFG_SEAT_REPORT_LAST_PERIOD, $period);
        $this->config->setAppValue(
            Application::APP_ID,
            LicenseService::CFG_SEAT_REPORT_LAST_PAYLOAD,
            (string)json_encode($payload),
        );
        $this->config->deleteAppValue(Application::APP_ID, LicenseService::CFG_SEAT_REPORT_LAST_ERROR);
        // The overdue ladder is computed from these keys inside getStatus(),
        // which memoises per request. Drop the memo so a caller that reads
        // the status after a report sees the report.
        $this->licenseService->forgetStatus();
    }

    private function recordFailure(string $reason): void {
        $reason = self::sanitiseReason($reason);
        $this->config->setAppValue(
            Application::APP_ID,
            LicenseService::CFG_SEAT_REPORT_LAST_ERROR,
            $reason,
        );
        $this->licenseService->forgetStatus();
        // Warning, not error: a missed report is expected on a flaky link and
        // recovers on its own. The ladder is what escalates.
        $this->logger->warning('[TeamHub] Seat report failed: ' . $reason, [
            'app' => Application::APP_ID,
        ]);
    }

    /**
     * One line of plain text, never markup (v4.14.5).
     *
     * A reason is rendered on the License tab and printed by
     * `occ teamhub:seat-report`, and the one thing that must never reach
     * either is a response body. On 2026-11-06 the panel held 500 characters
     * of a hosting error page, newlines and `<!DOCTYPE html>` included,
     * because Guzzle embeds the body in its exception message.
     *
     * `http_errors` is off now, so that particular route is closed at source.
     * This stays because a proxy's or a cURL wrapper's exception text is not
     * ours to trust either, and this is the one place every reason passes
     * through on its way to a human.
     *
     * Public for the same reason as `reasonFor()`: pure, and pinned by a test
     * that feeds it the actual 403 page this went wrong on.
     */
    public static function sanitiseReason(string $reason): string {
        $flat = preg_replace('/\s+/', ' ', strip_tags($reason));
        return mb_substr(trim($flat ?? $reason), 0, 500);
    }

    /**
     * The endpoint this instance will actually post to, override included.
     *
     * Public because `occ teamhub:seat-report` prints it, and printing the
     * constant instead is how an operator spends an afternoon reading
     * "Sending to .../api/seat-report" while the request goes somewhere else.
     * That happened on 2026-11-06, during the debugging of this very path.
     */
    public function reportUrl(): string {
        $override = trim($this->config->getAppValue(Application::APP_ID, self::CFG_URL_OVERRIDE, ''));
        if ($override !== '' && str_starts_with($override, 'https://')) {
            return $override;
        }
        return self::REPORT_URL;
    }

    private function rawJwt(): string {
        return $this->config->getAppValue(Application::APP_ID, LicenseService::CFG_JWT, '');
    }
}
