<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Who changed which TeamHub setting, and when (v4.13.17, DESIGN §2.192).
 *
 * Justin, 2026-10-21: *we do need to log nextcloud admin - teamhub page
 * settings to our audit log so we can see who changed what setting at what
 * point in time.* Policy profiles, templates and the Teams-frontend switch
 * already audited themselves; the admin page's own settings did not, and they
 * are the ones that decide who may create a team, which invite types are
 * allowed and how long the audit trail is kept.
 *
 * ## Snapshot-diff, not an audit line per branch
 *
 * `TeamService::saveAdminSettings()` is three hundred lines of
 * `if (isset($settings[…]))` writing a different app value in each branch, and
 * the other tabs have their own writers. An audit call inside every branch
 * would be forty call sites, each of which a later edit can forget — and the
 * forgetting is invisible, because nothing fails.
 *
 * So instead: read the app's whole config namespace before the write, run the
 * write, read it again, and audit the difference. It records **what actually
 * changed in storage** rather than what the request claimed, which also means
 * a save that stores a clamped or validated value logs the stored value, and a
 * no-op save logs nothing at all.
 *
 * One row per changed key, not one per save: `instance.setting_changed` with
 * the key as `target_id`, so the Compliance tab can filter the history of a
 * single setting. `surface` in the metadata names which screen did it.
 *
 * ## Secrets are recorded as changed, never as values
 *
 * `roomvox_api_token`, the OpenProject client secret and anything else whose
 * key looks like a credential is logged with `***` on both sides. *That a
 * token was replaced, by whom and when* is exactly what an auditor needs; the
 * token itself in an audit row readable by every administrator is a second
 * copy of a secret in a place nobody expects one. {@see SENSITIVE_PATTERN}.
 *
 * ## Scope
 *
 * Only the `teamhub` app namespace. The Teams-frontend switch writes `circles`'
 * config and audits itself ({@see TeamsFrontendService}) — a diff service that
 * watched other apps' namespaces would log changes TeamHub did not make.
 */
class AdminSettingsAuditService {

    /**
     * Keys whose values never enter the audit log.
     *
     * Matched case-insensitively against the key name, not the value: a name
     * test is something the next person adding a setting cannot accidentally
     * opt out of, which a list of literal key names is.
     */
    public const SENSITIVE_PATTERN = '/(token|secret|password|passwd|credential|private_key|apikey|api_key)/i';

    /** What a redacted value reads as on both sides of the diff. */
    public const REDACTED = '***';

    /**
     * Keys that are machine state rather than settings, and are skipped.
     *
     * TeamHub keeps operational state in its own app-config namespace too —
     * ingestion cursors, the share snapshot, the team-space notice list, the
     * telemetry install marker, a per-team collective id. A save that happens
     * to trigger one of those writes in the same request would otherwise
     * produce an audit row saying an administrator "changed" it, which is both
     * noise and slightly untrue.
     *
     * **This list exists for noise, not for secrecy, and it fails in the safe
     * direction**: a key nobody thought to add here produces one extra audit
     * row, while forgetting to *add* an audit call — the per-branch design this
     * replaced — produces silence. If a real setting ever matches one of these
     * words, narrow the pattern rather than dropping the setting.
     */
    public const NOISE_PATTERN = '/^(audit_cursor_|audit_share_snapshot|audit_activity_missing|teamspace_notices|telemetry_uuid|telemetry_install_sent|suggest_wizard_uses|collective_id_|installed_version|types$)/i';

    /**
     * Values longer than this are recorded as their length rather than their
     * content. `wizardDescription` can hold a page of text, and an audit row
     * is a record of a change, not a revision store.
     */
    public const VALUE_CAP = 200;

    public function __construct(
        private IConfig         $config,
        private IUserSession    $userSession,
        private AuditService    $auditService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run an admin-settings write and audit whatever it changed.
     *
     * @template T
     * @param string   $surface a stable identifier for the screen that saved,
     *                          e.g. 'admin.settings' or 'admin.audit_retention'
     * @param callable():T $write
     * @return T
     */
    public function recordAround(string $surface, callable $write) {
        $before = $this->snapshot();

        try {
            $result = $write();
        } catch (\Throwable $e) {
            // A failed save may still have written some keys before it threw —
            // `saveAdminSettings()` validates per branch. So the diff runs even
            // on the way out, and then the exception continues on its way.
            $this->diffAndAudit($surface, $before);
            throw $e;
        }

        $this->diffAndAudit($surface, $before);

        return $result;
    }

    /**
     * The app's config namespace as a key => value map.
     *
     * @return array<string,string>
     */
    public function snapshot(): array {
        $out = [];
        try {
            foreach ($this->config->getAppKeys(Application::APP_ID) as $key) {
                $out[$key] = (string)$this->config->getAppValue(Application::APP_ID, $key, '');
            }
        } catch (\Throwable $e) {
            // Never break a save because the audit could not read the before
            // state. An empty snapshot means every key present afterwards
            // reads as newly set, which over-reports rather than hides.
            $this->logger->warning('[TeamHub][AdminSettingsAuditService] Could not snapshot app config', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }

        return $out;
    }

    /**
     * Audit every key that differs between $before and the current state.
     *
     * @param array<string,string> $before
     * @return int how many changes were recorded
     */
    public function diffAndAudit(string $surface, array $before): int {
        $after = $this->snapshot();
        $actor = $this->userSession->getUser()?->getUID();

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($keys);

        $recorded = 0;
        foreach ($keys as $key) {
            if (preg_match(self::NOISE_PATTERN, $key) === 1) {
                continue;
            }

            $from = $before[$key] ?? null;
            $to   = $after[$key] ?? null;
            if ($from === $to) {
                continue;
            }

            $this->auditService->log(
                AuditService::INSTANCE_SCOPE,
                'instance.setting_changed',
                $actor,
                'setting',
                $key,
                [
                    'surface' => $surface,
                    'from'    => $this->presentable($key, $from),
                    'to'      => $this->presentable($key, $to),
                ],
            );
            $recorded++;
        }

        if ($recorded > 0) {
            $this->logger->info('[TeamHub][AdminSettingsAuditService] Admin settings changed', [
                'surface' => $surface, 'changes' => $recorded, 'app' => Application::APP_ID,
            ]);
        }

        return $recorded;
    }

    /**
     * What goes in the audit row for one value.
     *
     * `null` stays null — "the key did not exist" and "the key held an empty
     * string" are different events, and a setting being cleared back to its
     * default is one of the more interesting ones.
     */
    private function presentable(string $key, ?string $value): ?string {
        if ($value === null) {
            return null;
        }
        if (preg_match(self::SENSITIVE_PATTERN, $key) === 1) {
            return self::REDACTED;
        }
        if (mb_strlen($value) > self::VALUE_CAP) {
            // The length, not a truncation: half a JSON document in an audit
            // row invites somebody to read it as the value.
            return '[' . mb_strlen($value) . ' characters]';
        }

        return $value;
    }
}
