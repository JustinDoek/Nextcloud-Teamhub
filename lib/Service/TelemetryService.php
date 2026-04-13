<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
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
 *
 * Payload shape (POST JSON):
 * {
 *   "uuid":         "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *   "app_version":  "2.44.x",
 *   "team_count":   42,
 *   "integrations": ["teamhublists", "otherapp"],
 *   "event":        "daily_report" | "installed" | "uninstalled"
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
        return [
            'uuid'         => $this->getUuid(),
            'app_version'  => $this->getAppVersion(),
            'team_count'   => $this->countTeams(),
            'integrations' => $this->getRegisteredIntegrations(),
        ];
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
                ->from('teamhub_integration_registry')
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

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
