<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * v3.39.1 — Fix Circles config bitmask encoding on every TeamHub-managed team.
 *
 * Background
 * ----------
 * TeamHub <= 3.39.0 wrote the wrong Circles config bit values when users
 * toggled checkboxes in the Manage Team settings panel:
 *
 *      TeamHub label        Wrong bit written   Real Circles bit
 *      ─────────────────    ─────────────────   ─────────────────
 *      Anyone can join             1            16 (CFG_OPEN)
 *      Members can invite          2            32 (CFG_INVITE)
 *      Requests need approval      4            64 (CFG_REQUEST)
 *      Enforce password protection 16          256 (CFG_PROTECTED)
 *      Visible to everyone        512            8 (CFG_VISIBLE)
 *      (always-on UI hint)       1024            — (no longer written)
 *
 * Because the same wrong values were used on both write and read, the
 * checkboxes round-tripped within TeamHub but the actual stored bits were
 * meaningless to every other consumer (the Circles app, the Contacts app,
 * Circles' own internal queries). The damage observed in production:
 *
 *   - Teams set "Anyone can join" → bit 1 = CFG_SINGLE → Circles tags
 *     them as personal identity circles → Contacts hides them.
 *   - Teams set "Visible to everyone" → bit 512 = CFG_NO_OWNER →
 *     Contacts refuses to edit (no resolvable owner).
 *   - The "Prevent sub-team membership" always-on checkbox set bit 1024
 *     = CFG_HIDDEN → teams disappear from listings.
 *
 * What this migration does
 * ------------------------
 * For every user-created team (source=16), decode the legacy bits into
 * admin intent, preserve every other bit verbatim (CFG_PERSONAL from
 * Circles' own default, any federation flags, etc.), and re-encode using
 * correct Circles bit values. The admin sees the same checkbox states
 * before and after — only the underlying encoding changes.
 *
 * Idempotency
 * -----------
 * This migration is a one-shot. Once it runs, the database is in correct
 * encoding and TeamHub 3.39.1+ writes correct bits, so re-running would
 * be a no-op for already-clean teams and harmful for any team an admin
 * may have re-edited after the upgrade. We therefore only run on teams
 * where at least one legacy-mangled bit is currently set (bits 1, 4,
 * 512, 1024 — the ones TeamHub <= 3.39.0 set but never legitimately).
 * Teams with config = 0, or with only Circles-default CFG_PERSONAL (2)
 * set, or with already-clean correct encoding, are left untouched.
 *
 * For any team whose new config still contains a system-flag bit
 * forbidden on user teams after migration (extremely unlikely — would
 * mean an external tool set it), we log a warning but do not modify
 * further. Admin can use the integrity check in admin settings to
 * repair these.
 */
class Version000339001Date20260518000000 extends SimpleMigrationStep {

    /** Bits TeamHub <= 3.39.0 was capable of writing through its wrong UI. */
    private const LEGACY_DAMAGE_BITS =
          1     // Wrongly thought to be CFG_OPEN (actually CFG_SINGLE)
        | 4     // Wrongly thought to be CFG_REQUEST (actually CFG_SYSTEM)
        | 512   // Wrongly thought to be CFG_VISIBLE (actually CFG_NO_OWNER)
        | 1024; // The always-on "singleMember" UI hint (actually CFG_HIDDEN)
    // = 1541

    public function __construct(
        private IDBConnection      $db,
        private LoggerInterface    $logger,
        private ContainerInterface $container,
    ) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes — data migration only.
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('[TeamHub 3.39.1] Scanning circles_circle for corrupted config bits…');
        $this->logger->info('[Migration 3.39.1] Starting config-bit migration', [
            'app' => Application::APP_ID,
        ]);

        // ── 1. Scan: find every source=16 team with any legacy-damage bit set ─
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('source', $qb->createNamedParameter(16, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere(
                $qb->expr()->gt(
                    $qb->createFunction('(config & ' . self::LEGACY_DAMAGE_BITS . ')'),
                    $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                )
            )
            ->executeQuery();

        $affected = [];
        while ($row = $res->fetch()) {
            $affected[] = [
                'id'        => (string)$row['unique_id'],
                'name'      => (string)($row['name'] ?? ''),
                'oldConfig' => (int)$row['config'],
            ];
        }
        $res->closeCursor();

        $count = count($affected);
        $output->info(sprintf('[TeamHub 3.39.1] Found %d team(s) with corrupted config bits', $count));

        if ($count === 0) {
            $this->logger->info('[Migration 3.39.1] No teams need migration', [
                'app' => Application::APP_ID,
            ]);
            return;
        }

        // ── 2. Transform each: decode legacy bits → real bits, preserve the rest
        foreach ($affected as $team) {
            $oldConfig = $team['oldConfig'];
            $newConfig = CirclesConfig::migrateLegacyConfig($oldConfig);

            // After translation, any forbidden system bit (CFG_SINGLE, CFG_SYSTEM,
            // CFG_NO_OWNER, CFG_HIDDEN, CFG_BACKEND, CFG_APP) should be cleared by
            // migrateLegacyConfig — but defence in depth: strip them again here.
            // Otherwise externally-set garbage would survive the migration.
            $newConfig = $newConfig & ~CirclesConfig::SYSTEM_BITS_FORBIDDEN_ON_USER_TEAMS;

            $updQb = $this->db->getQueryBuilder();
            $updQb->update('circles_circle')
                ->set('config', $updQb->createNamedParameter($newConfig, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($updQb->expr()->eq('unique_id', $updQb->createNamedParameter($team['id'])))
                ->executeStatement();

            $output->info(sprintf(
                '[TeamHub 3.39.1] Migrated team "%s" (%s): config %d → %d',
                $team['name'], $team['id'], $oldConfig, $newConfig
            ));
            $this->logger->info('[Migration 3.39.1] Team config migrated', [
                'teamId'    => $team['id'],
                'name'      => $team['name'],
                'oldConfig' => $oldConfig,
                'newConfig' => $newConfig,
                'app'       => Application::APP_ID,
            ]);

            // Write an audit log row so the change is visible in Manage Team → Activity.
            // Audit is best-effort — failure does not stop the migration.
            try {
                $auditService = $this->container->get(\OCA\TeamHub\Service\AuditService::class);
                $auditService->log(
                    $team['id'],
                    'team.config_migrated_3_39_1',
                    'system',
                    'team',
                    $team['id'],
                    ['oldConfig' => $oldConfig, 'newConfig' => $newConfig, 'reason' => 'v3.39.1 bit-encoding fix'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('[Migration 3.39.1] Audit log failed (non-fatal)', [
                    'teamId' => $team['id'], 'error' => $e->getMessage(),
                    'app'    => Application::APP_ID,
                ]);
            }
        }

        // ── 3. Bust Circles' APCu cache ──────────────────────────────────────
        if (function_exists('apcu_delete') && class_exists('APCUIterator')) {
            try {
                foreach (new \APCUIterator('/^(circles|NC__circles)/') as $item) {
                    apcu_delete($item['key']);
                }
                $output->info('[TeamHub 3.39.1] Cleared Circles APCu cache');
            } catch (\Throwable $e) {
                $this->logger->warning('[Migration 3.39.1] APCu cache clear failed (non-fatal)', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        $output->info(sprintf('[TeamHub 3.39.1] Config-bit migration complete — %d team(s) updated', $count));
        $this->logger->info('[Migration 3.39.1] Complete', [
            'teamsUpdated' => $count, 'app' => Application::APP_ID,
        ]);
    }
}
