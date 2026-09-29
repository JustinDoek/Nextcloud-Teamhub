<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesConfig;
use OCA\TeamHub\Db\TeamRegistryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Which circles are TeamHub's, and the lock that keeps them TeamHub's (v4.10.6).
 *
 * Two facts about a team live here, written together in `createTeam()` and
 * removed together in `deleteTeam()`:
 *
 *  1. **The registry row** ({@see TeamRegistryMapper}) — this circle was
 *     created through TeamHub. Every member-facing reader shows registered
 *     teams only; a circle created in Contacts, Collectives, occ or another
 *     app is invisible in TeamHub. There is no adoption flow in this version
 *     (Justin, 2026-09-20).
 *
 *  2. **The lock** — Circles' `CFG_APP` bit ("managed by an app") on the
 *     circle. `CircleDestroy::verify()` refuses to destroy a circle carrying
 *     it, so Nextcloud's Teams page cannot delete a TeamHub team; only
 *     TeamHub can, by clearing the bit right before its own destroy. Exactly
 *     what Collectives does with its circles. `CircleConfig::verify()`
 *     re-applies the bit's current value on every non-super-session config
 *     write, so a team admin toggling privacy settings in Contacts cannot
 *     drop it.
 *
 * **The bit is written to the row, not through `CirclesManager::flagAsAppManaged()`.**
 * Circles' API runs the change through `CircleConfig::verify()`, which
 * refuses the whole update for a circle carrying a core-filter bit
 * (`CFG_SINGLE`/`CFG_PERSONAL`/`CFG_SYSTEM`, DESIGN §2.78) — a legacy team
 * that would be forever unlockable — and needs a super session opened and
 * closed around the user's own. `TeamService::updateTeamConfig()` has written
 * every config bit to the row for the same reason since 3.39; the 4.10.6
 * migration backfill has to as well. One write path, one behaviour.
 *
 * **The bit is shared with Collectives.** A team's collective (Manage team →
 * Wiki) is bound to the team's own circle, and Collectives sets the same bit
 * on it — and clears it when the collective is purged
 * (`deleteCollective(deleteCircle: false)` → `unflagCircleAsAppManaged()`).
 * `CollectivesService::disableForTeam()` therefore re-locks after a hard
 * purge, and {@see relockAll()} is the hourly backstop for every other way
 * the bit can go missing.
 *
 * A DI leaf: IDBConnection, the mapper and the logger only.
 */
class TeamRegistryService {

    public function __construct(
        private TeamRegistryMapper $mapper,
        private IDBConnection      $db,
        private LoggerInterface    $logger,
    ) {}

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    /** Is this circle a TeamHub team? The gate every member-facing reader uses. */
    public function isTeamHubTeam(string $teamId): bool {
        return $this->mapper->exists($teamId);
    }

    /**
     * Restrict a list query over `circles_circle` (aliased `$circleAlias`) to
     * TeamHub teams. See {@see TeamRegistryMapper::joinRegistered()}.
     */
    public function restrictToTeamHubTeams(IQueryBuilder $qb, string $circleAlias): void {
        $this->mapper->joinRegistered($qb, $circleAlias);
    }

    /**
     * Register a freshly created team and lock it. Called from
     * `TeamService::createTeam()` — the one place every creation path (wizard,
     * CSV import, OpenProject provisioning) runs through.
     *
     * The row is the fact and never fails silently; the lock is best-effort
     * here, because a team that exists but can be deleted from the Teams page
     * is a lesser wrong than a team that does not exist — {@see relockAll()}
     * picks it up within the hour.
     */
    public function registerCreated(string $teamId, string $createdBy): void {
        $this->mapper->insert($teamId, TeamRegistryMapper::ORIGIN_TEAMHUB, $createdBy);
        try {
            $this->lock($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamRegistryService] team registered but could not be locked — the hourly re-lock will retry', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /**
     * Register a team made outside TeamHub that was accepted into it
     * (v4.10.50, {@see \OCA\TeamHub\Service\TeamAdoptionService}), and lock
     * it — from here on it is a TeamHub team like any other. Same
     * best-effort lock as {@see registerCreated()}.
     */
    public function registerAdopted(string $teamId, string $ownerUid): void {
        $this->mapper->insert($teamId, TeamRegistryMapper::ORIGIN_ADOPTED, $ownerUid !== '' ? $ownerUid : null);
        try {
            $this->lock($teamId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamRegistryService] adopted team registered but could not be locked — the hourly re-lock will retry', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    /** Remove the row. After the circle is destroyed; nothing to unlock by then. */
    public function unregister(string $teamId): void {
        $this->mapper->delete($teamId);
    }

    // ------------------------------------------------------------------
    // Lock
    // ------------------------------------------------------------------

    /** Set `CFG_APP` on the circle. Idempotent. */
    public function lock(string $teamId): void {
        $this->setLockBit($teamId, true);
    }

    /**
     * Clear `CFG_APP` so Circles will destroy the circle. Only from TeamHub's
     * own delete paths, immediately before the destroy — a team left unlocked
     * is deletable from the Teams page until {@see relockAll()} runs.
     */
    public function unlock(string $teamId): void {
        $this->setLockBit($teamId, false);
    }

    public function isLocked(string $teamId): bool {
        $config = $this->readConfig($teamId);
        return $config !== null && ($config & CirclesConfig::CFG_APP) !== 0;
    }

    /**
     * Re-lock every registered team whose bit went missing. Hourly, from
     * `TeamSpaceReconcileJob`; returns how many were re-locked.
     *
     * Teams pending deletion are re-locked too: the deletion job unlocks
     * right before it destroys, so the bit being present until then is right.
     */
    public function relockAll(): int {
        $ids = $this->mapper->allTeamIds();
        if ($ids === []) {
            return 0;
        }

        $relocked = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->readConfigs($chunk) as $teamId => $config) {
                if (($config & CirclesConfig::CFG_APP) !== 0) {
                    continue;
                }
                $this->writeConfig($teamId, $config | CirclesConfig::CFG_APP);
                $relocked++;
            }
        }

        if ($relocked > 0) {
            $this->logger->info('[TeamHub][TeamRegistryService] re-locked teams whose app-managed bit had been cleared', [
                'count' => $relocked, 'app' => Application::APP_ID,
            ]);
        }
        return $relocked;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function setLockBit(string $teamId, bool $on): void {
        $config = $this->readConfig($teamId);
        if ($config === null) {
            throw new \RuntimeException('Circle not found: ' . $teamId);
        }
        $new = $on ? ($config | CirclesConfig::CFG_APP) : ($config & ~CirclesConfig::CFG_APP);
        if ($new === $config) {
            return;
        }
        $this->writeConfig($teamId, $new);
    }

    // The three row accessors are `protected` so a unit test can hold the
    // circles in memory (the pattern TeamHubResourceProviderTest uses).

    /** The circle's config, or null when there is no such circle. */
    protected function readConfig(string $teamId): ?int {
        if ($teamId === '') {
            return null;
        }
        $rows = $this->readConfigs([$teamId]);
        return $rows[$teamId] ?? null;
    }

    /**
     * Config per circle for the ids that exist.
     *
     * @param string[] $teamIds
     * @return array<string,int> unique_id => config
     */
    protected function readConfigs(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->in('unique_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery();
        $out = [];
        while ($row = $res->fetch()) {
            $out[(string)$row['unique_id']] = (int)($row['config'] ?? 0);
        }
        $res->closeCursor();
        return $out;
    }

    protected function writeConfig(string $teamId, int $config): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('circles_circle')
            ->set('config', $qb->createNamedParameter($config, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->executeStatement();
    }
}
