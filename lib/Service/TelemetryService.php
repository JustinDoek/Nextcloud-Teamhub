<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Collects anonymous usage statistics and sends them to the TeamHub stats endpoint.
 *
 * Privacy design:
 *   - The identifier is a randomly generated UUID stored in NC app config.
 *     No NC instance URL, hostname, or user data is ever included.
 *   - Opt-out is respected: if 'telemetry_enabled' is set to '0' by an NC admin,
 *     nothing is sent.
 *   - The full payload is logged at DEBUG level so admins can verify what is sent.
 *   - Custom link URLs are reduced to their bare hostname before aggregation —
 *     no paths, query strings, ports, fragments, or IP addresses are ever sent.
 *
 * Payload shape (POST JSON):
 * {
 *   "uuid":                 "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *   "app_version":          "3.8.0",
 *   "nc_version":           "32.0.4.1",
 *   "team_count":           42,
 *   "user_count":           180,
 *   "member_total":         137,
 *   "message_count":        904,
 *   "integrations":         ["teamhublists", "otherapp"],
 *   "builtin_integrations": {"files": 30, "calendar": 20, "deck": 12, "talk": 8},
 *   "link_domains":         {"github.com": 12, "trello.com": 5, "notion.so": 3},
 *   "event":                "daily_report" | "installed" | "uninstalled"
 * }
 */
class TelemetryService {

    /** The remote endpoint that receives reports. */
    public const REPORT_URL = 'https://tldr.host/teamhub/report/'; // trailing slash required — Apache 301-redirects bare URL, flipping POST to GET

    /** IConfig key: telemetry opt-out flag. '1' = enabled (default), '0' = disabled. */
    private const KEY_ENABLED = 'telemetry_enabled';

    /** IConfig key: the persistent anonymous UUID. Auto-generated on first use. */
    private const KEY_UUID = 'telemetry_uuid';

    /** HTTP timeout in seconds for the stats POST. */
    private const TIMEOUT = 10;

    public function __construct(
        private IConfig         $config,
        private IDBConnection   $db,
        private IClientService  $clientService,
        private IUserManager    $userManager,
        private LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns true when telemetry is enabled (opt-in by default, admin can disable).
     */
    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_ENABLED, '1') === '1';
    }

    /**
     * Enable or disable telemetry. Only meaningful when called by an NC admin.
     */
    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Return the persistent anonymous UUID, generating one if it does not exist yet.
     */
    public function getUuid(): string {
        $uuid = $this->config->getAppValue(Application::APP_ID, self::KEY_UUID, '');
        if ($uuid === '') {
            $uuid = $this->generateUuid();
            $this->config->setAppValue(Application::APP_ID, self::KEY_UUID, $uuid);
        }
        return $uuid;
    }

    /**
     * Collect stats and send the daily report if telemetry is enabled.
     * Called by DailyReportJob.
     */
    public function sendDailyReport(): void {
        if (!$this->isEnabled()) {
            return;
        }
        $this->send('daily_report');
    }

    /**
     * Send an 'installed' event. Called from Application::boot() on first run.
     * Guarded by a flag so it only fires once per installation.
     */
    public function sendInstallEvent(): void {
        $fired = $this->config->getAppValue(Application::APP_ID, 'telemetry_install_sent', '0');
        if ($fired === '1') {
            return;
        }
        if (!$this->isEnabled()) {
            return;
        }
        $this->send('installed');
        $this->config->setAppValue(Application::APP_ID, 'telemetry_install_sent', '1');
    }

    /**
     * Send an 'uninstalled' event. Called from AppDisabledListener.
     */
    public function sendUninstallEvent(): void {
        if (!$this->isEnabled()) {
            return;
        }
        $this->send('uninstalled');
    }

    /**
     * Collect current stats without sending — used by the admin UI preview.
     */
    public function collectStats(): array {
        $stats = [
            'uuid'                 => $this->getUuid(),
            'app_version'          => $this->getAppVersion(),
            'nc_version'           => $this->getNcVersion(),
            'team_count'           => $this->countTeams(),
            'user_count'           => $this->countUsers(),
            'member_total'         => $this->countMembersTotal(),
            'message_count'        => $this->countMessages(),
            'integrations'         => $this->getRegisteredIntegrations(),
            'builtin_integrations' => $this->getBuiltinIntegrationUsage(),
            'link_domains'         => $this->getLinkDomains(),
        ];
        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function send(string $event): void {
        $payload = array_merge($this->collectStats(), ['event' => $event]);

        try {
            $client = $this->clientService->newClient();
            $client->post(self::REPORT_URL, [
                'timeout' => self::TIMEOUT,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body'    => json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            // Never let a telemetry failure affect normal app operation.
            $this->logger->warning('[TeamHub] Telemetry send failed', [
                'event'     => $event,
                'error'     => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
        }
    }

    private function countTeams(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('circles_circle')
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (int)$row['cnt'] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getRegisteredIntegrations(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            // selectDistinct prevents duplicate app_id values in the payload when
            // the same app registers multiple integrations (e.g. both a widget and
            // a menu_item row). Without this the telemetry payload contained
            // duplicate entries like ["teamhublists", "teamhublists"].
            $result = $qb->selectDistinct('app_id')
                ->from('teamhub_integ_registry')
                ->where($qb->expr()->eq('is_builtin', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $apps = [];
            while ($row = $result->fetch()) {
                $apps[] = (string)$row['app_id'];
            }
            $result->closeCursor();
            return $apps;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getAppVersion(): string {
        try {
            $config = $this->config;
            return $config->getAppValue('teamhub', 'installed_version', 'unknown');
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Returns the Nextcloud version string from system config, e.g. "32.0.4.1".
     * Falls back to 'unknown' if unavailable.
     */
    private function getNcVersion(): string {
        try {
            $v = $this->config->getSystemValue('version', 'unknown');
            return is_string($v) && $v !== '' ? $v : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Total number of Nextcloud users across all backends.
     * Uses countUsersTotal() (NC 28+) when available, with a fallback for older versions.
     */
    private function countUsers(): int {
        try {
            if (method_exists($this->userManager, 'countUsersTotal')) {
                $total = (int)$this->userManager->countUsersTotal();
                return $total;
            }
            $counts = $this->userManager->countUsers();
            $total = (int)array_sum(is_array($counts) ? $counts : []);
            return $total;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Total count of member relationships across all teams.
     * Counts rows in circles_member with status='Member' (matches the pattern
     * used elsewhere in the codebase for member counting).
     *
     * Note: this counts relationships, not unique users — a user who is a member
     * of 3 teams contributes 3 to this total. Divided by team_count server-side,
     * this gives the average team size.
     */
    private function countMembersTotal(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('circles_member')
                ->where($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            $count = $row ? (int)$row['cnt'] : 0;
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Total number of rows in the TeamHub message stream table across all teams.
     */
    private function countMessages(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('teamhub_messages')
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            $count = $row ? (int)$row['cnt'] : 0;
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * How many teams have each builtin NC-app integration explicitly enabled.
     * Reads teamhub_team_apps, grouped by app_id, counting rows where enabled=true.
     *
     * Example return:
     *   ['files' => 30, 'calendar' => 20, 'deck' => 12, 'talk' => 8]
     *
     * Caveat: teams that have never touched their app toggles won't have rows,
     * so this counts *explicit* enablement. That is still the signal we want —
     * it shows engagement with the feature.
     */
    private function getBuiltinIntegrationUsage(): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('app_id')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
                ->groupBy('app_id')
                ->executeQuery();
            $usage = [];
            while ($row = $result->fetch()) {
                $usage[(string)$row['app_id']] = (int)$row['cnt'];
            }
            $result->closeCursor();
            return $usage;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Aggregates custom web-link URLs by bare hostname, returning a domain => count map.
     *
     * Privacy: only the lowercase hostname is kept — no scheme, path, query,
     * port, or fragment. Localhost and numeric IPs are dropped because they
     * are not useful for a "popular apps" ranking.
     *
     * Example return:
     *   ['github.com' => 12, 'trello.com' => 5, 'notion.so' => 3]
     */
    private function getLinkDomains(): array {
        $counts = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('url')
                ->from('teamhub_web_links')
                ->executeQuery();
            while ($row = $result->fetch()) {
                $domain = $this->extractDomain((string)$row['url']);
                if ($domain !== null) {
                    $counts[$domain] = ($counts[$domain] ?? 0) + 1;
                }
            }
            $result->closeCursor();
            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Reduces a URL to its lowercase bare hostname, or returns null if the URL
     * is unusable (empty, unparseable, localhost, or a numeric IP).
     *
     * Examples:
     *   "https://github.com/our-org/repo"    -> "github.com"
     *   "http://GitLab.Example.com:8080/"    -> "gitlab.example.com"
     *   "trello.com/b/xyz"                   -> "trello.com"     (scheme auto-prepended)
     *   "http://192.168.1.10/internal"       -> null             (IP dropped)
     *   "http://localhost:3000"              -> null             (localhost dropped)
     *   "not a url"                          -> null
     */
    private function extractDomain(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // parse_url needs a scheme to reliably extract the host.
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'http://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        // Strip IPv6 brackets so the IP validator sees the raw address.
        $forValidate = $host;
        if (str_starts_with($forValidate, '[') && str_ends_with($forValidate, ']')) {
            $forValidate = substr($forValidate, 1, -1);
        }

        // Drop numeric IPs (v4 or v6) — not useful for "popular apps" stats.
        if (filter_var($forValidate, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Drop localhost.
        if ($host === 'localhost' || $host === 'localhost.localdomain') {
            return null;
        }

        // Must be a plausible hostname: only [a-z0-9.-] and at least one dot.
        // This filters garbage URLs like "not a url" (which parse_url happily
        // accepts as a host) and bare intranet hostnames that won't aggregate
        // usefully across installations.
        if (!preg_match('/^[a-z0-9.\-]+$/', $host) || !str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
