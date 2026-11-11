<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\CirclesMemberType;
use OCA\TeamHub\Db\TeamRegistryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Teams this server does not own (v4.14.15, DESIGN §2.256).
 *
 * ## What a synced copy is, and why TeamHub could not see one
 *
 * When a team admin on another instance invites one of our users into a
 * TeamHub team, Circles' `RemoteService::syncRemoteCircle()` writes a copy of
 * that circle here: a `circles_circle` row with the owner's `source = 16`, the
 * owner's `config`, and a `circles_member` row per member, the local invitee
 * among them. That is why Nextcloud's Contacts app shows the team and its
 * people the moment the invitation is accepted.
 *
 * TeamHub showed nothing, and the reason is not federation: every
 * member-facing reader joins `teamhub_team_registry` through
 * {@see TeamRegistryMapper::joinRegistered()}, and a row is written there only
 * by `TeamService::createTeam()` and by adoption. A copy that arrived over
 * federation goes through neither, so the INNER JOIN dropped it for everybody
 * on this instance (reported by Justin, 2026-11-10, after reproducing it twice
 * between claudius.tldr.host and dev.aaenh.nl).
 *
 * This service is the registry's answer for that one case: **a circle with no
 * registry row, whose owner lives on another instance, is a TeamHub team on
 * that instance.** Nothing else is relaxed. A circle made locally in Contacts,
 * Collectives or occ still has no row and is still invisible, which is the
 * whole point of DESIGN §2.136.
 *
 * ## What it does *not* make available
 *
 * Every module keeps its data in TeamHub's own tables on the owning instance,
 * keyed by `team_id`. This instance holds none of it, so a synced copy can
 * offer the roster Circles synced and nothing more until the TeamHub-to-
 * TeamHub proxy API exists (Justin chose proxying the owner's modules over a
 * members-only shell on 2026-11-10; the four decisions under it are open and
 * recorded in HANDOFF).
 *
 * So the rows this service returns are **surface rows**, added in
 * `TeamService::getSidebarTeams()` and nowhere else, exactly where a `listed`
 * sub-team is added and for the same reason. They never pass through
 * `getUserTeams()`, which is this app's authorisation boundary for My Work,
 * the Files-app actions and file reviews; a remote team must not widen any of
 * those, because a write there would land in local tables that the owning
 * instance will never see. The frontend renders them through `RemoteTeamView`,
 * which mounts no module component at all.
 *
 * Two local writes are deliberately kept away from a remote row for the same
 * reason, and both are hidden in `TeamNavItem`: *Leave team*, because
 * `MemberService::leaveTeam()` deletes the `circles_member` row with raw SQL
 * and would make this instance disagree with the owner about who is in the
 * team, and *Invite*, which would write a member row the owner never asked
 * for. Leaving a federated team is the owner instance's business until the
 * proxy exists.
 *
 * A DI leaf: the database, the config, the user manager and the logger.
 */
class FederatedTeamService {

    /**
     * The administrator's switch, off by default. Canonical here since
     * v4.14.15 - it gates what TeamHub *shows* as well as the daily sweep in
     * {@see FederatedTeamCleanupService}, which keeps the const as an alias.
     */
    public const CONFIG_KEY = 'federatedTeamsEnabled';

    /** Circles' source for a circle a user made. A TeamHub team is one. */
    private const SOURCE_USER = 16;

    /** `Member::LEVEL_OWNER`. The owner row is the only one carrying the owning instance. */
    private const LEVEL_OWNER = 9;

    /**
     * The member type of a person holding a Nextcloud account: `TYPE_USER`,
     * and only that.
     *
     * **Not `[1, 4]`.** Circles has no federated member type: somebody on
     * another server is `TYPE_USER` with a remote `instance`, and type 4 is
     * `TYPE_MAIL`, an email invitee with no account anywhere. {@see
     * CirclesMemberType} has said so since v4.9.2; the first cut of this
     * service took DESIGN §2.31's older, wrong sentence instead and would have
     * listed a team's email invitees as its people.
     */
    private const MEMBER_TYPES = CirclesMemberType::PEOPLE_WITH_ACCOUNTS;

    public function __construct(
        private IDBConnection   $db,
        private IConfig         $config,
        private IUserManager    $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /** Has the administrator switched teams from other servers on? */
    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '0') === '1';
    }

    /**
     * The instance that owns this team, or null when this server owns it (or
     * when there is no such circle, or no owner row to ask).
     *
     * The owner row carries the instance; the circle row does not. Same read
     * as {@see FederatedTeamCleanupService::syncedCopiesByInstance()}, one
     * team at a time.
     */
    public function ownerInstanceOf(string $teamId): ?string {
        if ($teamId === '') {
            return null;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('m.instance')
            ->from('circles_member', 'm')
            ->innerJoin('m', 'circles_circle', 'c', $qb->expr()->eq('c.unique_id', 'm.circle_id'))
            ->where($qb->expr()->eq('m.circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.level', $qb->createNamedParameter(self::LEVEL_OWNER, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.source', $qb->createNamedParameter(self::SOURCE_USER, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row === false) {
            return null;
        }
        // Circles stores a circle this instance owns with `instance = ''`, so
        // the empty-string test is the whole question: the same test every
        // other `isLocalInstance()` caller in the app makes, with no alias
        // list.
        $instance = trim((string)($row['instance'] ?? ''));
        return $instance === '' ? null : $instance;
    }

    /**
     * Is this team a synced copy the viewer may see, a remote-owned circle
     * with no registry row, with the feature switched on?
     *
     * The gate every remote-team read goes through. Returns the owning
     * instance so a caller that needs it does not ask twice.
     */
    public function visibleRemoteTeam(string $teamId): ?string {
        if (!$this->isEnabled()) {
            return null;
        }
        // A registry row means the normal path already handles this team;
        // relaxing anything for it would be a second answer to one question.
        if ($this->hasRegistryRow($teamId)) {
            return null;
        }
        return $this->ownerInstanceOf($teamId);
    }

    /**
     * The sidebar rows for every synced copy this user belongs to, in the
     * shape `TeamService::getSidebarTeams()` appends to its list.
     *
     * Membership is read exactly as `getUserTeams()` reads it: a direct member
     * row, or the `circles_membership` cache for access through a group or
     * another team. The viewer is a local account here whichever way round the
     * team is, so the type is `TYPE_USER` as everywhere else; what makes the
     * *team* remote is its owner's instance, not anybody's member type.
     *
     * @param string  $uid      the viewer
     * @param ?string $singleId the viewer's personal-circle single id, if resolved
     * @return list<array<string, mixed>>
     */
    public function sidebarRowsFor(string $uid, ?string $singleId): array {
        if (!$this->isEnabled() || $uid === '') {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.unique_id', 'c.name', 'c.display_name', 'c.description', 'c.config', 'o.instance')
                ->addSelect('m.level AS direct_level')
                ->from('circles_circle', 'c')
                // The owner row: a non-empty instance is what makes this a copy
                // of somebody else's team.
                ->innerJoin('c', 'circles_member', 'o', $qb->expr()->andX(
                    $qb->expr()->eq('o.circle_id', 'c.unique_id'),
                    $qb->expr()->eq('o.level', $qb->createNamedParameter(self::LEVEL_OWNER, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->neq('o.instance', $qb->createNamedParameter('')),
                ))
                // No registry row: that is the one case this service is for.
                ->leftJoin('c', TeamRegistryMapper::TABLE, 'r', $qb->expr()->eq('r.team_id', 'c.unique_id'))
                // The viewer's own direct row, when there is one.
                ->leftJoin('c', 'circles_member', 'm', $qb->expr()->andX(
                    $qb->expr()->eq('m.circle_id', 'c.unique_id'),
                    $qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)),
                    $qb->expr()->in('m.user_type', $qb->createNamedParameter(self::MEMBER_TYPES, IQueryBuilder::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('m.status', $qb->createNamedParameter('Member')),
                ))
                ->where($qb->expr()->eq('c.source', $qb->createNamedParameter(self::SOURCE_USER, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNull('r.team_id'));

            if ($singleId !== null && $singleId !== '') {
                $qb->addSelect('ms.single_id AS ms_single_id')
                    ->leftJoin('c', 'circles_membership', 'ms', $qb->expr()->andX(
                        $qb->expr()->eq('ms.circle_id', 'c.unique_id'),
                        $qb->expr()->eq('ms.single_id', $qb->createNamedParameter($singleId)),
                    ))
                    ->andWhere($qb->expr()->orX(
                        $qb->expr()->isNotNull('m.user_id'),
                        $qb->expr()->isNotNull('ms.single_id'),
                    ));
            } else {
                $qb->andWhere($qb->expr()->isNotNull('m.user_id'));
            }

            $qb->orderBy($qb->createFunction('LOWER(c.name)'), 'ASC');

            $res  = $qb->executeQuery();
            $rows = [];
            $ids  = [];
            while ($row = $res->fetch()) {
                $id = (string)($row['unique_id'] ?? '');
                if ($id === '' || isset($ids[$id])) {
                    continue;
                }
                $name = (string)($row['name'] ?? '');
                // Circles' own personal and group circles, skipped as
                // getUserTeams() skips them: the name is what tells them apart.
                if (str_starts_with($name, 'user:') || str_starts_with($name, 'group:')) {
                    continue;
                }
                $ids[$id] = true;
                $rows[]   = $row;
            }
            $res->closeCursor();

            if ($rows === []) {
                return [];
            }

            $counts = $this->memberCounts(array_keys($ids));

            $out = [];
            foreach ($rows as $row) {
                $id          = (string)$row['unique_id'];
                $displayName = trim((string)($row['display_name'] ?? ''));
                $out[] = [
                    'id'          => $id,
                    'name'        => $displayName !== '' ? $displayName : (string)$row['name'],
                    'description' => (string)($row['description'] ?? ''),
                    'members'     => $counts[$id] ?? 0,
                    'unread'      => 0,
                    'image_url'   => null,
                    'image_source' => null,
                    'nc_avatar_supported' => false,
                    'config'      => (int)($row['config'] ?? 0),
                    'level'       => $row['direct_level'] !== null ? (int)$row['direct_level'] : 0,
                    // v4.14.15 - the two fields the sidebar and the shell read:
                    // one to draw the globe and gate the row's actions, one to
                    // name the server that owns the team.
                    'is_remote'       => true,
                    'remote_instance' => (string)($row['instance'] ?? ''),
                    // A remote team is never a sub-team here and can never
                    // have one: both facts live in TeamHub tables on the
                    // owning instance. Stated so the sidebar's nesting and
                    // the Sub-teams tab read the same shape as on a local row.
                    'is_sub_team'          => false,
                    'parent_team_id'       => null,
                    'sub_team_visibility'  => null,
                    'can_have_sub_teams'   => false,
                    'sub_teams_unavailable_reason' => null,
                ];
            }
            return $out;

        } catch (\Throwable $e) {
            // A sidebar that loses its local teams because a federated query
            // failed would be a worse bug than the one this fixes.
            $this->logger->error('[TeamHub][FederatedTeamService] could not list teams owned by another server', [
                'uid' => $uid, 'exception' => $e, 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Everything the remote-team view shows: the team's own facts, the server
     * that owns it, and the roster Circles synced.
     *
     * Read-only and local: it asks the owning instance nothing. Every value
     * here is a row Circles already wrote on this server, which is the same
     * material Contacts draws from.
     *
     * @return array<string, mixed>|null null when the team is not a synced
     *         copy, the feature is off, or the viewer is not in it
     */
    public function describe(string $teamId, string $uid, ?string $singleId): ?array {
        $instance = $this->visibleRemoteTeam($teamId);
        if ($instance === null) {
            return null;
        }

        $row = $this->circleRow($teamId);
        if ($row === null) {
            return null;
        }

        $level = $this->viewerLevel($teamId, $uid, $singleId);
        if ($level === null) {
            return null;
        }

        $displayName = trim((string)($row['display_name'] ?? ''));

        return [
            'id'              => $teamId,
            'name'            => $displayName !== '' ? $displayName : (string)$row['name'],
            'description'     => (string)($row['description'] ?? ''),
            'remote_instance' => $instance,
            'is_remote'       => true,
            'level'           => $level,
            'members'         => $this->roster($teamId),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function hasRegistryRow(string $teamId): bool {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('team_id')
            ->from(TeamRegistryMapper::TABLE)
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row !== false;
    }

    /** @return array<string, mixed>|null */
    private function circleRow(string $teamId): ?array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('unique_id', 'name', 'display_name', 'description', 'config')
            ->from('circles_circle')
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        return $row === false ? null : $row;
    }

    /**
     * The viewer's level in a synced copy, or null when they are not in it.
     * 0 for access through a group or another team, as `getUserTeams()`
     * reports it.
     */
    private function viewerLevel(string $teamId, string $uid, ?string $singleId): ?int {
        if ($uid === '') {
            return null;
        }
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('level')
            ->from('circles_member')
            ->where($qb->expr()->eq('circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->in('user_type', $qb->createNamedParameter(self::MEMBER_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row !== false) {
            return (int)$row['level'];
        }

        if ($singleId === null || $singleId === '') {
            return null;
        }
        $mQb  = $this->db->getQueryBuilder();
        $mRes = $mQb->select('single_id')
            ->from('circles_membership')
            ->where($mQb->expr()->eq('circle_id', $mQb->createNamedParameter($teamId)))
            ->andWhere($mQb->expr()->eq('single_id', $mQb->createNamedParameter($singleId)))
            ->setMaxResults(1)
            ->executeQuery();
        $mRow = $mRes->fetch();
        $mRes->closeCursor();
        return $mRow === false ? null : 0;
    }

    /**
     * The team's people as Circles synced them: local accounts and the members
     * from other servers together.
     *
     * It does not reuse `MemberService::getTeamMembers()` because that one
     * gates on `requireMemberLevel()` and on the registry, neither of which
     * answers for a team this server does not own. The rows themselves are the
     * same shape: everybody with an account is `TYPE_USER`, and the ones on
     * the owning server carry its name in `instance`.
     *
     * @return list<array{userId: string, displayName: string, role: string, level: int, instance: string, is_remote: bool}>
     */
    private function roster(string $teamId): array {
        $qb  = $this->db->getQueryBuilder();
        $res = $qb->select('m.user_id', 'm.level', 'm.user_type', 'm.instance', 'm.cached_name')
            ->addSelect('u.displayname')
            ->from('circles_member', 'm')
            ->leftJoin('m', 'users', 'u', 'm.user_id = u.uid')
            ->where($qb->expr()->eq('m.circle_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter('Member')))
            ->andWhere($qb->expr()->in('m.user_type', $qb->createNamedParameter(self::MEMBER_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('m.level', 'DESC')
            ->addOrderBy('m.cached_name', 'ASC')
            ->executeQuery();

        $out = [];
        while ($row = $res->fetch()) {
            $userId   = (string)($row['user_id'] ?? '');
            $instance = trim((string)($row['instance'] ?? ''));
            $level    = (int)($row['level'] ?? 0);
            if ($userId === '') {
                continue;
            }

            // A local account's display name comes from the user manager, as
            // everywhere else in the app: the `users` column is often NULL on
            // a real install, and a user-set name lives in `oc_preferences`.
            // A member on another server has no local account to ask, so
            // Circles' own cached name is the only name there is.
            $displayName = '';
            if ($instance === '') {
                $displayName = (string)($this->userManager->get($userId)?->getDisplayName() ?? '');
                if ($displayName === '' && !empty($row['displayname'])) {
                    $displayName = (string)$row['displayname'];
                }
            }
            if ($displayName === '') {
                $displayName = trim((string)($row['cached_name'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = $userId;
            }

            $out[] = [
                'userId'      => $userId,
                'displayName' => $displayName,
                'level'       => $level,
                'role'        => match (true) {
                    $level >= 9 => 'owner',
                    $level >= 8 => 'admin',
                    $level >= 4 => 'moderator',
                    default     => 'member',
                },
                'instance'  => $instance,
                'is_remote' => $instance !== '',
            ];
        }
        $res->closeCursor();
        return $out;
    }

    /**
     * People per team: local accounts and members from other servers, the same
     * set {@see roster()} lists, so the sidebar's number matches the view's
     * list.
     *
     * @param string[] $teamIds
     * @return array<string, int>
     */
    private function memberCounts(array $teamIds): array {
        if ($teamIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($teamIds, 500) as $chunk) {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('circle_id')
                ->addSelect($qb->func()->count('*', 'cnt'))
                ->from('circles_member')
                ->where($qb->expr()->in('circle_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('Member')))
                ->andWhere($qb->expr()->in('user_type', $qb->createNamedParameter(self::MEMBER_TYPES, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('circle_id')
                ->executeQuery();
            while ($row = $res->fetch()) {
                $out[(string)$row['circle_id']] = (int)$row['cnt'];
            }
            $res->closeCursor();
        }
        return $out;
    }
}
