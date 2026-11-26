<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesMemberType;
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
 *   - Reporting is licence-derived, not a toggle: an instance with an active
 *     licence or trial never sends. See isEnabled() for the full rule and
 *     setEnabled() for why the old admin switch is a no-op.
 *   - Admin → TeamHub → Reporting shows an administrator every field below with
 *     its current value. A field added to collectStats() is added there too.
 *   - No hostname leaves the instance. Until v4.20.44 the payload carried the
 *     domains of teams' web links, which could include the organisation's own.
 *
 * Payload shape (POST JSON):
 * {
 *   "uuid":                 "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *   "app_version":          "3.8.0",
 *   "nc_version":           "32.0.4.1",
 *   "team_count":           42,
 *   "user_count":           180,
 *   "unique_team_members":  73,
 *   "member_total":         137,
 *   "message_count":        904,
 *   "integrations":         ["teamhublists", "otherapp"],
 *   "builtin_integrations": {"files": 30, "calendar": 20, "deck": 12, "talk": 8},
 *   "modules_enabled":      ["presence", "decisions"],
 *   "suggest_wizard_uses":  31,
 *   "announcement_popup":   "enabled" | "disabled",
 *   "event":                "daily_report" | "installed" | "uninstalled"
 * }
 */
class TelemetryService {

    /** The remote endpoint that receives reports. */
    public const REPORT_URL = 'https://teleserv.doekworks.eu/'; // since 4.20.43 (was https://tldr.host/teamhub/report/). Keep it redirect-free: a 301 turns the POST into a GET

    /**
     * IConfig key of the retired admin opt-out flag.
     *
     * Nothing reads it since v4.2.4, when reporting became licence-derived
     * (isEnabled()). Retained only so an upgraded instance's stored value
     * isn't mistaken for a free key name later; setEnabled() is a no-op.
     */
    private const KEY_ENABLED = 'telemetry_enabled';

    /** IConfig key: the persistent anonymous UUID. Auto-generated on first use. */
    private const KEY_UUID = 'telemetry_uuid';

    /** Module id in the payload => its instance switch. All default on. See getEnabledModules(). */
    private const MODULE_SWITCHES = [
        'presence'  => 'presence_module_enabled',
        'decisions' => 'decisions_module_enabled',
    ];

    /** HTTP timeout in seconds for the stats POST. */
    private const TIMEOUT = 10;

    public function __construct(
        private IConfig                   $config,
        private IDBConnection             $db,
        private IClientService            $clientService,
        private IUserManager              $userManager,
        private LoggerInterface           $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Whether telemetry (anonymous usage statistics) is currently sending.
     *
     * v4.2.4 — derived from license state instead of an explicit admin
     * toggle. Rule: any customer with a currently-honoured license (Active,
     * Trial, or Grace) is a known paying customer and does not need to be
     * counted. Instances that are Unlicensed or Soft-locked send telemetry
     * so Justin can see the free-tier usage footprint.
     *
     * Implementation note: we read the JWT config value directly and peek-
     * decode it, rather than calling `LicenseService::getEnforcementLevel()`.
     * LicenseService already depends on TelemetryService (via
     * `countLicensedSeats`, v3.100.6), so injecting it back here would be a
     * circular constructor dependency that NC's DI can't resolve — the
     * initial attempt at that broke both the License tab (LicenseController
     * → LicenseService construction) and the Reporting KPIs
     * (MaintenanceController → TelemetryService construction). Peek-decode
     * is enough for this decision: we only need to know if a JWT is
     * installed and not too-far past its exp; signature verification is
     * LicenseService's job and doesn't affect "should we send stats".
     */
    public function isEnabled(): bool {
        $rawJwt = $this->config->getAppValue(Application::APP_ID, 'license_jwt', '');
        if ($rawJwt === '') {
            return true;   // no license at all — free tier, keep telemetry on
        }
        try {
            $peeked = \OCA\TeamHub\Util\Jwt::peek($rawJwt);
        } catch (\Throwable) {
            return true;   // malformed JWT is effectively unlicensed
        }
        $payload = $peeked['payload'] ?? null;
        if (!is_array($payload)) {
            return true;
        }
        $exp = $payload['exp'] ?? null;
        // Grace window: LicenseService::graceDaysFor() — fourteen days past
        // exp for a paid key, none for a trial (Commercial Licence and Support
        // Agreement art. 13.4 / 14.3 / 17.4; v4.10.12, was a flat 30 days).
        // Anything within [not-yet-expired, exp + grace) counts as
        // "licensed". From the cutoff on, soft-lock — telemetry resumes. The
        // static helper keeps this the same rule LicenseService applies,
        // without the circular dependency described above.
        if (!is_int($exp)) {
            return true;
        }
        $graceCutoff = $exp + LicenseService::graceDaysFor($payload) * 86400;
        return time() >= $graceCutoff;
    }

    /**
     * @deprecated v4.3.0 — telemetry is derived from license state; no
     *   longer settable. The MaintenanceController endpoint that used to
     *   call this is kept as a legacy no-op for API back-compat.
     */
    public function setEnabled(bool $enabled): void {
        // Intentional no-op. Kept only so the (now vestigial) admin PUT
        // endpoint doesn't fatal. isEnabled() ignores the stored value.
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
     *
     * Called from TelemetryReportJob (daily TimedJob, registered in
     * appinfo/info.xml).
     *
     * History: v4.3.20 deleted the job that called this and marked the method
     * deprecated, describing the endpoint as retired. Both parts were wrong in
     * effect — REPORT_URL is live and still receiving reports from instances on
     * pre-4.3.20 builds. What actually happened is that the policy in
     * isEnabled() survived while its only transport was removed, so unlicensed
     * instances stopped reporting without anything indicating so. Restored in
     * v4.4.7.
     */
    public function sendDailyReport(): void {
        if (!$this->isEnabled()) {
            return;
        }
        $this->send('daily_report');
    }

    /**
     * Send an 'installed' event, once per installation.
     *
     * NOT CURRENTLY CALLED. The boot-time ping was removed in v4.3.20 for the
     * air-gapped-only licensing model and deliberately not restored in v4.4.7
     * — see the explicit comment in Application::boot(). Kept because the
     * daily report gives the same population signal a day later without
     * touching the request path on first load. Wire it up only if install-time
     * timing genuinely matters; the isEnabled() gate already applies.
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
     * Send an 'uninstalled' event.
     *
     * NOT CURRENTLY CALLED. AppDisabledListener stopped invoking this in
     * v4.3.20 and only suspends integrations now. Left in place alongside
     * sendInstallEvent() for symmetry; an instance that disables the app
     * simply stops appearing in the daily reports.
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
    /**
     * Increment the monotonic counter of successful meeting-suggestion wizard
     * runs. Anonymous and aggregate — just a count, no content, no user. Read
     * back into the telemetry payload as 'suggest_wizard_uses'.
     */
    public function incrementSuggestWizardUses(): void {
        try {
            $current = (int)$this->config->getAppValue(Application::APP_ID, 'suggest_wizard_uses', '0');
            $this->config->setAppValue(Application::APP_ID, 'suggest_wizard_uses', (string)($current + 1));
        } catch (\Throwable $e) {
            // Telemetry must never affect the request path.
        }
    }

    public function collectStats(): array {
        $stats = [
            'uuid'                 => $this->getUuid(),
            'app_version'          => $this->getAppVersion(),
            'nc_version'           => $this->getNcVersion(),
            'team_count'           => $this->countTeams(),
            'user_count'           => $this->countUsers(),
            'unique_team_members'  => $this->countUniqueTeamMembers(),
            'member_total'         => $this->countMembersTotal(),
            'message_count'        => $this->countMessages(),
            'integrations'         => $this->getRegisteredIntegrations(),
            'builtin_integrations' => $this->getBuiltinIntegrationUsage(),
            'modules_enabled'      => $this->getEnabledModules(),
            'suggest_wizard_uses'  => (int)$this->config->getAppValue(Application::APP_ID, 'suggest_wizard_uses', '0'),
            'announcement_popup'   => $this->getAnnouncementPopupState(),
        ];
        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Whether an administrator has turned an announcement popup off (v4.20.44):
     * 'disabled' once anybody ticked "Do not show this message again", else
     * 'enabled'. No counts, no announcement names and no user.
     *
     * The choice is per message and per administrator, and there is no switch
     * for all of them, so 'disabled' means "at least one administrator opted
     * out of at least one message" and stays that way for later messages.
     *
     * Read from the config value rather than through AnnouncementService: that
     * service constructs LicenseService, and this one cannot inject
     * LicenseService without a cycle (see isEnabled()).
     */
    private function getAnnouncementPopupState(): string {
        $raw = $this->config->getAppValue(Application::APP_ID, 'announce_popup_stats', '');
        $decoded = $raw === '' ? null : json_decode($raw, true);
        foreach (is_array($decoded) ? $decoded : [] as $events) {
            $optOuts = is_array($events) ? ($events['optout'] ?? 0) : 0;
            if (is_numeric($optOuts) && (int)$optOuts > 0) {
                return 'disabled';
            }
        }
        return 'enabled';
    }

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

    /**
     * Total team count (TeamHub / NC Teams — source=16 only).
     *
     * Public since v4.3.20; retained here for any future in-app UI that
     * wants to show the number. Not currently used by another service.
     */
    public function countTeams(): int {
        try {
            $qb = $this->db->getQueryBuilder();
            // source=16 → user-created circles (TeamHub / NC Teams).
            // source=1  → personal user circles; source=2 → group-backed circles.
            // Counting all rows inflates the number by every user and group on
            // the instance, so we must filter to source=16 only.
            $result = $qb->select($qb->func()->count('*', 'cnt'))
                ->from('circles_circle')
                ->where($qb->expr()->eq('source', $qb->createNamedParameter(16, IQueryBuilder::PARAM_INT)))
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
     * Ids of the modules switched on for the whole instance (v4.20.44, replacing
     * the `presence_module` and `decisions_module` booleans). 'presence' is the
     * module the interface calls People.
     *
     * Only modules the instance can actually use. OpenProject, service teams and
     * sub-teams also need a licence, and a licensed instance never reports, so
     * they cannot appear: reading their stored switches would claim sub-teams
     * for every instance, because that switch defaults to on and is inert
     * without a licence. Messages, Timeline, Budget and Time are per team and
     * have no instance switch.
     *
     * @return list<string>
     */
    private function getEnabledModules(): array {
        $enabled = [];
        foreach (self::MODULE_SWITCHES as $module => $key) {
            if ($this->config->getAppValue(Application::APP_ID, $key, '1') === '1') {
                $enabled[] = $module;
            }
        }
        return $enabled;
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
     * Count distinct EFFECTIVE users across all TeamHub teams (source=16
     * circles). Walks circles_membership — the denormalised cache — so a
     * user reaching a team via an attached group or sub-team is counted once,
     * and a user who is a direct member of multiple teams is also counted
     * once. Counts TYPE_USER rows only, which covers local AND federated
     * accounts — federation lives in circles_member.instance, not in the type.
     *
     * v4.9.2 — this filtered on user_type IN (1, 4) in the belief that 4 added
     * federated users. Circles' type 4 is TYPE_MAIL: an email address invited
     * into a team, holding no account on any server. So the count included
     * mail invitees as seats, and federated users were already inside the 1.
     * See CirclesMemberType::PEOPLE_WITH_ACCOUNTS.
     *
     * This is the metric a per-seat licence would key off: how many distinct
     * humans actually have access to at least one TeamHub team on this
     * instance.
     */
    public function countUniqueTeamMembers(): int {
        try {
            // SELECT DISTINCT user_id over the join, deduplicate in PHP via
            // an associative array. Avoids needing COUNT(DISTINCT col) which
            // doesn't have a portable IFunctionBuilder shortcut in NC's
            // QueryBuilder. Membership rows for one user across multiple
            // teams collapse to a single key; result size is the answer.
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('m.user_id')
                ->from('circles_membership', 'ms')
                ->innerJoin('ms', 'circles_circle', 'c',
                    $qb->expr()->eq('c.unique_id', 'ms.circle_id'))
                ->innerJoin('ms', 'circles_member', 'm', $qb->expr()->andX(
                    $qb->expr()->eq('m.circle_id', 'ms.single_id'),
                    $qb->expr()->in('m.user_type', $qb->createNamedParameter(
                        CirclesMemberType::PEOPLE_WITH_ACCOUNTS, IQueryBuilder::PARAM_INT_ARRAY
                    )),
                ))
                ->where($qb->expr()->eq('c.source',
                    $qb->createNamedParameter(16, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $seen = [];
            while ($row = $result->fetch()) {
                $uid = (string)($row['user_id'] ?? '');
                if ($uid !== '') {
                    $seen[$uid] = true;
                }
            }
            $result->closeCursor();
            return count($seen);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][TelemetryService] countUniqueTeamMembers failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
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
     * How many teams have each builtin NC-app integration enabled.
     *
     * teamhub_team_apps only contains rows when a user has *explicitly* changed
     * the default.  Most teams therefore have no rows at all, which means a
     * simple COUNT(enabled=1) massively undercounts.
     *
     * Correct logic per app:
     *   • Default-enabled apps  (files, calendar, deck, spreed, intravox):
     *       count = total_teams − teams_with_explicit_disabled_row
     *
     * The `shared_files` slot was removed in 3.86.2 — the Shared-files widget
     * was folded into the Filecenter widget as an always-on tab and no longer
     * has a per-team enable/disable toggle. Legacy rows for that app_id in
     * teamhub_team_apps are simply not counted.
     *
     * The app IDs and defaults below mirror ManageTeamView.vue's appDefinitions().
     *
     * Example return:
     *   ['files' => 30, 'calendar' => 28, 'deck' => 18, 'spreed' => 25,
     *    'intravox' => 12]
     */
    private function getBuiltinIntegrationUsage(): array {
        // Apps where the default is enabled — absence of a row means enabled.
        $defaultEnabled = ['files', 'calendar', 'deck', 'spreed', 'intravox'];

        try {
            $totalTeams = $this->countTeams();

            // Fetch counts of explicit enabled and disabled rows per app.
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('app_id', 'enabled')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->in(
                    'app_id',
                    $qb->createNamedParameter($defaultEnabled, IQueryBuilder::PARAM_STR_ARRAY)
                ))
                ->groupBy('app_id', 'enabled')
                ->executeQuery();

            $explicitDisabled = [];
            while ($row = $result->fetch()) {
                if ((int)$row['enabled'] !== 1) {
                    $explicitDisabled[(string)$row['app_id']] = (int)$row['cnt'];
                }
            }
            $result->closeCursor();

            $usage = [];

            // Default-enabled: total minus those explicitly turned off.
            foreach ($defaultEnabled as $app) {
                $count = max(0, $totalTeams - ($explicitDisabled[$app] ?? 0));
                if ($count > 0) {
                    $usage[$app] = $count;
                }
            }

            return $usage;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
