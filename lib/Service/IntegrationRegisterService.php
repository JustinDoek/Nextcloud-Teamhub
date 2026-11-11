<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\TeamApps;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Where does this instance's team data go? (v4.13.17, DESIGN §2.194)
 *
 * The compliance finding this answers: integrations are per-team switches, and
 * there was no instance-level view of which teams send data to which external
 * system. ISO 27001 A.5.19 (supplier relationships) and A.5.14 (information
 * transfer) both start from a register, and an auditor asks for it before
 * anything else. Everything needed was already stored — this reads it.
 *
 * **It reads, and it is read-only.** No new table, no new column, no write
 * path. A register that had its own storage would be a second copy of facts
 * the switches already hold, and a second copy is a thing that goes stale.
 *
 * ## The three sources, and why all three
 *
 *   `teamhub_team_apps`          the per-team switch — *intent*. A team with
 *                                Talk switched on, whether or not a room
 *                                exists yet.
 *   `teamhub_team_app_resources` the registry — *reality*. The active rows are
 *                                the resources a team actually has.
 *   `teamhub_resource_link`      the provisioning ledger — *history*. Who
 *                                connected what, when, and whether it still
 *                                validates.
 *
 * Intent without reality is a team that was offered an app and never got one;
 * reality without intent is a resource discovered in its own app rather than
 * created here (`ResourceDiscoveryService`). Both are worth seeing, and a
 * register that reported one number would hide whichever it did not pick.
 *
 * ## Off-server is the column that matters
 *
 * Talk, Files, Calendar, Deck, Collectives and IntraVox are Nextcloud apps on
 * this instance: nothing leaves the server, so they are an access question.
 * OpenProject and RoomVox are other systems, with their own operators and
 * their own addresses — and `teamhub_openproject_link.host` records the exact
 * one per team, which is what a supplier register has to name.
 * {@see TeamApps::OFF_SERVER}.
 *
 * Modules (Decisions, Presence, Timeline, Messages, Budget, Time) are left out
 * entirely. They are TeamHub's own code and TeamHub's own tables; listing them
 * as places data "goes" would pad the register with ourselves.
 */
class IntegrationRegisterService {

    /** How many teams one integration's detail view lists. */
    public const DETAIL_LIMIT = 500;

    public function __construct(
        private IDBConnection   $db,
        private IUserSession    $userSession,
        private IGroupManager   $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * One row per integration this instance actually uses.
     *
     * @return array{generatedAt: int, rows: list<array<string,mixed>>, hosts: list<array<string,mixed>>}
     */
    public function register(): array {
        $this->requireNcAdmin();

        $enabled   = $this->enabledTeamCounts();
        $resources = $this->resourceCounts();
        $ledger    = $this->ledgerFacts();

        $appIds = array_unique(array_merge(
            array_keys($enabled),
            array_keys($resources),
            array_keys($ledger),
        ));

        $rows = [];
        foreach ($appIds as $appId) {
            if (!TeamApps::isIntegration($appId)) {
                // A module, or an app id the integration API registered that
                // nothing here knows. Both are deliberately out: see the
                // class docblock.
                continue;
            }

            $rows[] = [
                'appId'          => $appId,
                'offServer'      => TeamApps::isOffServer($appId),
                'teamsEnabled'   => $enabled[$appId]['teams'] ?? 0,
                'teamsWithData'  => $resources[$appId]['teams'] ?? 0,
                'resources'      => $resources[$appId]['resources'] ?? 0,
                'firstLinkedAt'  => $ledger[$appId]['first'] ?? null,
                'lastLinkedAt'   => $ledger[$appId]['last'] ?? null,
                'unhealthy'      => $ledger[$appId]['unhealthy'] ?? 0,
            ];
        }

        // Off-server first, then by how many teams are affected: the register's
        // reading order is "who should I have a contract with", not alphabetical.
        usort($rows, static function (array $a, array $b): int {
            if ($a['offServer'] !== $b['offServer']) {
                return $a['offServer'] ? -1 : 1;
            }
            if ($a['teamsEnabled'] !== $b['teamsEnabled']) {
                return $b['teamsEnabled'] <=> $a['teamsEnabled'];
            }
            return strcmp($a['appId'], $b['appId']);
        });

        return [
            'generatedAt' => time(),
            'rows'        => $rows,
            'hosts'       => $this->offServerHosts(),
        ];
    }

    /**
     * Which teams use one integration, for the row's detail.
     *
     * @return array{appId: string, teams: list<array<string,mixed>>, truncated: bool}
     */
    public function detail(string $appId): array {
        $this->requireNcAdmin();

        $appId = TeamApps::canonical(trim($appId));
        if ($appId === '' || !TeamApps::isIntegration($appId)) {
            throw new \InvalidArgumentException('Not an integration this instance registers.');
        }

        // Intent, reality and history per team, assembled in PHP rather than in
        // one query: the three tables disagree by design (see the class
        // docblock), and a join would silently drop a team that appears in only
        // one of them — which is exactly the case worth seeing.
        $teams = [];

        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        foreach ($this->column($qb) as $teamId) {
            $teams[$teamId]['enabled'] = true;
        }

        // The switch is also stored under a non-canonical spelling for two
        // apps ('wiki' for Collectives, 'pages' for IntraVox), which is the
        // whole reason TeamApps::ALIASES exists.
        foreach (array_keys(TeamApps::ALIASES, $appId, true) as $alias) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('team_id')
                ->from('teamhub_team_apps')
                ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($alias)))
                ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
            foreach ($this->column($qb) as $teamId) {
                $teams[$teamId]['enabled'] = true;
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->selectAlias($qb->func()->count('*'), 'n')
            ->from('teamhub_team_app_resources')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->groupBy('team_id');
        foreach ($this->rows($qb) as $row) {
            $teams[(string)$row['team_id']]['resources'] = (int)$row['n'];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->selectAlias($qb->func()->min('created_at'), 'first_at')
            ->selectAlias($qb->func()->max('created_at'), 'last_at')
            ->from('teamhub_resource_link')
            ->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId)))
            ->groupBy('team_id');
        foreach ($this->rows($qb) as $row) {
            $teams[(string)$row['team_id']]['firstLinkedAt'] = (int)$row['first_at'];
            $teams[(string)$row['team_id']]['lastLinkedAt']  = (int)$row['last_at'];
        }

        if ($appId === 'openproject') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('team_id', 'host', 'project_name', 'created_at')
                ->from('teamhub_openproject_link');
            foreach ($this->rows($qb) as $row) {
                $teamId = (string)$row['team_id'];
                $teams[$teamId]['host']    = (string)$row['host'];
                $teams[$teamId]['project'] = (string)$row['project_name'];
                $teams[$teamId]['firstLinkedAt'] ??= (int)$row['created_at'];
            }
        }

        $truncated = count($teams) > self::DETAIL_LIMIT;
        $teams     = array_slice($teams, 0, self::DETAIL_LIMIT, true);
        $names     = $this->teamNames(array_keys($teams));

        $out = [];
        foreach ($teams as $teamId => $facts) {
            $out[] = [
                'teamId'        => (string)$teamId,
                'name'          => $names[$teamId] ?? (string)$teamId,
                'enabled'       => (bool)($facts['enabled'] ?? false),
                'resources'     => (int)($facts['resources'] ?? 0),
                'firstLinkedAt' => $facts['firstLinkedAt'] ?? null,
                'lastLinkedAt'  => $facts['lastLinkedAt'] ?? null,
                'host'          => $facts['host'] ?? '',
                'project'       => $facts['project'] ?? '',
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['appId' => $appId, 'teams' => $out, 'truncated' => $truncated];
    }

    // -------------------------------------------------------------------------
    // The three sources
    // -------------------------------------------------------------------------

    /** @return array<string,array{teams: int}> */
    private function enabledTeamCounts(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('app_id')
            // One row per (team, app) — `teamhub_apps_team_idx` is unique on
            // that pair, so a row count *is* a team count.
            ->selectAlias($qb->func()->count('*'), 'n')
            ->from('teamhub_team_apps')
            ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->groupBy('app_id');

        $out = [];
        foreach ($this->rows($qb) as $row) {
            // Canonicalise *after* grouping, and add: 'wiki' and 'collectives'
            // are the same integration counted under two spellings.
            $appId = TeamApps::canonical((string)$row['app_id']);
            $out[$appId]['teams'] = ($out[$appId]['teams'] ?? 0) + (int)$row['n'];
        }

        return $out;
    }

    /** @return array<string,array{teams: int, resources: int}> */
    private function resourceCounts(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('app_id', 'team_id')
            ->from('teamhub_team_app_resources')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        $out = [];
        $seen = [];
        foreach ($this->rows($qb) as $row) {
            $appId  = TeamApps::canonical((string)$row['app_id']);
            $teamId = (string)$row['team_id'];
            $out[$appId]['resources'] = ($out[$appId]['resources'] ?? 0) + 1;
            if (!isset($seen[$appId][$teamId])) {
                $seen[$appId][$teamId] = true;
                $out[$appId]['teams'] = ($out[$appId]['teams'] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @return array<string,array{first: int, last: int, unhealthy: int}> */
    private function ledgerFacts(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('app_id', 'created_at', 'health')
            ->from('teamhub_resource_link');

        $out = [];
        foreach ($this->rows($qb) as $row) {
            $appId     = TeamApps::canonical((string)$row['app_id']);
            $createdAt = (int)$row['created_at'];
            $health    = (string)$row['health'];

            $out[$appId]['first'] = isset($out[$appId]['first'])
                ? min($out[$appId]['first'], $createdAt)
                : $createdAt;
            $out[$appId]['last'] = isset($out[$appId]['last'])
                ? max($out[$appId]['last'], $createdAt)
                : $createdAt;
            // 'unknown' is not unhealthy — it means nothing has validated it
            // yet, which is the state every row starts in.
            if ($health !== 'ok' && $health !== 'unknown') {
                $out[$appId]['unhealthy'] = ($out[$appId]['unhealthy'] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * The external hosts this instance's teams are connected to, with how many
     * teams each holds. The supplier list, in other words.
     *
     * @return list<array{appId: string, host: string, teams: int}>
     */
    private function offServerHosts(): array {
        $out = [];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('host')
                ->selectAlias($qb->func()->count('*'), 'n')
                ->from('teamhub_openproject_link')
                ->groupBy('host');
            foreach ($this->rows($qb) as $row) {
                $out[] = [
                    'appId' => 'openproject',
                    'host'  => (string)$row['host'],
                    'teams' => (int)$row['n'],
                ];
            }
        } catch (\Throwable $e) {
            // The table exists in every supported version; a failure here is
            // worth a log line and not worth an empty register.
            $this->logger->warning('[TeamHub][IntegrationRegisterService] Could not read the OpenProject hosts', [
                'exception' => $e, 'app' => Application::APP_ID,
            ]);
        }

        usort($out, static fn (array $a, array $b): int => $b['teams'] <=> $a['teams']);

        return $out;
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function rows(IQueryBuilder $qb): array {
        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();

        return $rows;
    }

    /** @return list<string> */
    private function column(IQueryBuilder $qb): array {
        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[] = (string)reset($row);
        }
        $result->closeCursor();

        return $out;
    }

    /**
     * Display names for a set of team ids.
     *
     * A direct read of `circles_circle`, like `MaintenanceController::listTeams()`
     * and `AuditController::exportTeam()`: an administrator's register must name
     * teams they are not a member of, which every gated reader refuses.
     *
     * @param list<string> $teamIds
     * @return array<string,string>
     */
    private function teamNames(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($teamIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('unique_id', 'name', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->in('unique_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));

            foreach ($this->rows($qb) as $row) {
                $name = (string)($row['display_name'] ?? '');
                if ($name === '') {
                    $name = (string)($row['name'] ?? '');
                }
                $out[(string)$row['unique_id']] = $name;
            }
        }

        return $out;
    }

    private function requireNcAdmin(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AccessDeniedException('Not authenticated.');
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            throw new AccessDeniedException('Nextcloud administrator privilege required.');
        }

        return $user->getUID();
    }
}
