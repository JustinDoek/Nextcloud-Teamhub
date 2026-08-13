<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CalendarService — calendar creation and deletion for TeamHub teams.
 *
 * Extracted from ResourceService in v3.2.0.
 */
class CalendarService {

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create a CalDAV calendar for $uid, shared with the team circle.
     *
     * @param string      $teamId   Team / circle unique ID
     * @param string      $teamName Display name for the calendar
     * @param string      $uid      NC user ID of the team creator (calendar owner)
     * @param string|null $colour   6-char hex without '#' (e.g. '2eb52b').
     *                              The CalDAV property requires '#RRGGBB' format —
     *                              the '#' is prepended here. Defaults to NC blue.
     */
    public function createCalendar(string $teamId, string $teamName, string $uid, ?string $colour = null): array {

        if (!$this->appManager->isInstalled('calendar')) {
            return ['error' => 'Calendar app not installed'];
        }

        $colour       = $colour ?? '0082c9';
        $caldav       = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $principalUri = 'principals/users/' . $uid;
        $calendarUri  = strtolower(preg_replace('/[^a-z0-9]+/', '-', $teamName))
                       . '-' . substr(md5(uniqid()), 0, 6);

        $calendarId = $caldav->createCalendar($principalUri, $calendarUri, [
            '{DAV:}displayname'                        => $teamName,
            '{http://apple.com/ns/ical/}calendar-color' => '#' . $colour,
        ]);

        $db = $this->container->get(\OCP\IDBConnection::class);

        // Share with the circle via dav_shares (read-write, access=2)
        $circlePublicUri = 'teamhub-' . substr($teamId, 0, 8) . '-' . $calendarId;
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/circles/' . $teamId,
            'type'         => 'calendar',
            'access'       => 2,   // 2 = read-write
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $circlePublicUri,
        ], ['principaluri', 'resourceid']);

        // v4.6.20 — the public share is gone. Until now every team calendar got
        // a second `dav_shares` row with `access = 4` and a random token,
        // created for one reason: the Calendar tab iframed
        // `/apps/calendar/p/{token}`, and that public route was the only one in
        // NC Calendar that shows a single calendar. Its authenticated routes
        // take a view and a date and have no way to say *which* calendar.
        //
        // What that cost: the token page answered unauthenticated, and so did
        // `remote.php/dav/public-calendars/{token}/?export`, which hands back
        // the team's whole agenda as ICS. The token reached every member's
        // browser in the layout bundle, never expired, and had no revocation
        // path — so one screenshot or proxy log published a team's calendar
        // permanently.
        //
        // `TeamCalendarGrid` renders the tab now, from a membership-gated
        // endpoint, which is what makes "one calendar, authenticated" possible
        // without publishing anything. Version000406020 revokes the rows this
        // used to create.

        return ['calendar_id' => $calendarId, 'name' => $teamName];
    }

    /**
     * Create a Deck board and share it with the circle.
     *
     * All DB writes use the QueryBuilder (IDBConnection::insert() doesn't exist in NC32 —
     * only QueryBuilder and executeStatement are available on ConnectionAdapter).
     *
     * ACL type 7 = circle, per official Deck API docs.
     */

    /**
     * Suspend team access to the calendar by removing the dav_shares row for the circle.
     * The calendar and all its events remain under the owner's account.
     *
     * Returns {calendar_id, principal_uri} for resume, or null if not found.
     *
     * @return array{calendar_id: int, principal_uri: string}|null
     */
    public function suspendCalendarAccess(string $teamId, \OCP\IDBConnection $db): ?array {
        if (!$this->appManager->isInstalled('calendar')) {
            return null;
        }
        try {
            $principalUri = 'principals/circles/' . $teamId;
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('resourceid', 'principaluri')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return null;
            }

            $calendarId  = (int)$row['resourceid'];
            $ownerUri    = $this->resolveCalendarOwnerUri($calendarId, $db);

            // Delete the dav_shares row for the circle principal.
            $dqb = $db->getQueryBuilder();
            $dqb->delete('dav_shares')
                ->where($dqb->expr()->eq('type', $dqb->createNamedParameter('calendar')))
                ->andWhere($dqb->expr()->eq('principaluri', $dqb->createNamedParameter($principalUri)))
                ->executeStatement();

            $this->logger->debug('[TeamHub][CalendarService] suspendCalendarAccess: dav_shares row removed', [
                'teamId' => $teamId, 'calendarId' => $calendarId, 'app' => Application::APP_ID,
            ]);

            return [
                'calendar_id'   => $calendarId,
                'principal_uri' => $ownerUri,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] suspendCalendarAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Resume team access to the calendar by re-inserting the dav_shares row.
     */
    public function resumeCalendarAccess(int $calendarId, string $teamId, \OCP\IDBConnection $db): bool {
        if (!$this->appManager->isInstalled('calendar')) {
            return false;
        }
        try {
            $principalUri = 'principals/circles/' . $teamId;

            // Idempotency check — skip if the row already exists.
            $chk  = $db->getQueryBuilder();
            $cres = $chk->select($chk->func()->count('*', 'cnt'))
                ->from('dav_shares')
                ->where($chk->expr()->eq('type', $chk->createNamedParameter('calendar')))
                ->andWhere($chk->expr()->eq('principaluri', $chk->createNamedParameter($principalUri)))
                ->andWhere($chk->expr()->eq('resourceid', $chk->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            $exists = (int)$cres->fetchOne() > 0;
            $cres->closeCursor();

            if ($exists) {
                return true;
            }

            $iqb = $db->getQueryBuilder();
            $iqb->insert('dav_shares')
                ->setValue('principaluri', $iqb->createNamedParameter($principalUri))
                ->setValue('type',        $iqb->createNamedParameter('calendar'))
                ->setValue('access',      $iqb->createNamedParameter(2))  // 2 = read-write
                ->setValue('resourceid',  $iqb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->executeStatement();

            $this->logger->debug('[TeamHub][CalendarService] resumeCalendarAccess: dav_shares row re-inserted', [
                'teamId' => $teamId, 'calendarId' => $calendarId, 'app' => Application::APP_ID,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] resumeCalendarAccess failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Resolve the calendar owner's principaluri from oc_calendars.
     *
     * apps.md R-2 note: kept as direct SELECT. ICalendarManager exposes
     * calendars only by principal; there is no getCalendarById(). A
     * reverse lookup here would have to walk every principal in the
     * system, which is materially more expensive than an indexed SELECT.
     */
    private function resolveCalendarOwnerUri(int $calendarId, \OCP\IDBConnection $db): string {
        try {
            $qb  = $db->getQueryBuilder();
            $res = $qb->select('principaluri')
                ->from('calendars')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            return $row ? (string)$row['principaluri'] : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * List calendars owned by $uid that are eligible to be connected to a team.
     *
     * Returns user-owned calendars only (not contact birthday calendars,
     * not deleted calendars, not subscriptions). Caller should pass the current
     * NC user's UID — this method does no auth itself.
     *
     * @return array<int, array{id:int, name:string, color:string, uri:string}>
     */
    public function listOwnedCalendars(string $uid): array {
        if (!$this->appManager->isInstalled('calendar')) {
            return [];
        }

        // v3.100.8 (apps.md R-2) — resolve via ICalendarManager (fully
        // public OCP API); fall back to direct SELECT if the manager can't
        // resolve (older NC, unusual deployment). Exclude birthday
        // calendar; sort by display name.
        try {
            $calendarManager = $this->container->get(\OCP\Calendar\ICalendarManager::class);
            $principalUri = 'principals/users/' . $uid;
            $calendars = $calendarManager->getCalendarsForPrincipal($principalUri);
            $out = [];
            foreach ($calendars as $cal) {
                $uri = (string)$cal->getUri();
                if ($uri === 'contact_birthdays') {
                    continue;
                }
                $out[] = [
                    'id'    => (int)$cal->getKey(),
                    'uri'   => $uri,
                    'name'  => (string)($cal->getDisplayName() ?? $uri),
                    'color' => (string)($cal->getDisplayColor() ?? '#0082c9'),
                ];
            }
            usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            return $out;
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][CalendarService] listOwnedCalendars: ICalendarManager path unavailable — using DB fallback', [
                'uid' => $uid, 'reason' => $e->getMessage(),
                'app' => Application::APP_ID,
            ]);
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $principalUri = 'principals/users/' . $uid;
            $qb = $db->getQueryBuilder();

            // We select displayname and calendarcolor from oc_calendars where principaluri matches.
            // Exclude the contact birthday calendar (uri = 'contact_birthdays') and any soft-deleted
            // calendars (deleted_at IS NULL).
            $qb->select('id', 'uri', 'displayname', 'calendarcolor')
                ->from('calendars')
                ->where($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->andWhere($qb->expr()->neq('uri', $qb->createNamedParameter('contact_birthdays')));

            // deleted_at column may not exist on older NC versions — wrap in try/catch
            try {
                $qb->andWhere($qb->expr()->isNull('deleted_at'));
            } catch (\Throwable) {
                // Column doesn't exist — proceed without that filter
            }

            $qb->orderBy('displayname', 'ASC');

            $res = $qb->executeQuery();
            $rows = $res->fetchAll();
            $res->closeCursor();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'    => (int)$row['id'],
                    'uri'   => (string)$row['uri'],
                    'name'  => (string)($row['displayname'] ?? $row['uri']),
                    'color' => (string)($row['calendarcolor'] ?? '#0082c9'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] listOwnedCalendars failed', [
                'uid' => $uid, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Connect an existing calendar to a team by inserting a dav_shares row
     * granting the team's circle read-write access.
     *
     * SECURITY: Caller MUST verify the user has permission to enable team
     * integrations (team admin level). This method additionally verifies that
     * $uid actually owns the calendar — preventing forged resourceId attacks.
     *
     * If the team already has a calendar connected, returns an error rather
     * than connecting a second one (one calendar per team).
     *
     * @return array{success:bool, calendar_id?:int, name?:string, error?:string}
     */
    public function connectExistingCalendar(string $teamId, int $calendarId, string $uid): array {
        if (!$this->appManager->isInstalled('calendar')) {
            return ['success' => false, 'error' => 'Calendar app not installed'];
        }

        try {
            $db = $this->container->get(\OCP\IDBConnection::class);
            $principalUri = 'principals/users/' . $uid;

            // v3.100.8 (apps.md R-2) — SECURITY: verify the calendar
            // exists and is owned by this user via ICalendarManager (public
            // OCP API). Fall back to direct SELECT if the manager can't
            // resolve for older NC versions.
            $calendarName = null;
            try {
                $calendarManager = $this->container->get(\OCP\Calendar\ICalendarManager::class);
                $calendars = $calendarManager->getCalendarsForPrincipal($principalUri);
                foreach ($calendars as $cal) {
                    if ((int)$cal->getKey() === $calendarId) {
                        $calendarName = (string)($cal->getDisplayName() ?? $cal->getUri());
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][CalendarService] connectExistingCalendar: ICalendarManager check unavailable — using DB fallback', [
                    'uid' => $uid, 'calendarId' => $calendarId,
                    'reason' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }

            if ($calendarName === null) {
                $qb = $db->getQueryBuilder();
                $res = $qb->select('id', 'displayname', 'uri')
                    ->from('calendars')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                    ->setMaxResults(1)
                    ->executeQuery();
                $row = $res->fetch();
                $res->closeCursor();

                if (!$row) {
                    return ['success' => false, 'error' => 'Calendar not found or not owned by user'];
                }

                $calendarName = (string)($row['displayname'] ?? $row['uri']);
            }

            // Refuse only if this specific calendar is already connected to this team.
            // Multiple different calendars are allowed (multi-resource model).
            $circlePrincipal = 'principals/circles/' . $teamId;
            $chk = $db->getQueryBuilder();
            $cres = $chk->select('resourceid')
                ->from('dav_shares')
                ->where($chk->expr()->eq('type', $chk->createNamedParameter('calendar')))
                ->andWhere($chk->expr()->eq('principaluri', $chk->createNamedParameter($circlePrincipal)))
                ->andWhere($chk->expr()->eq('resourceid', $chk->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery();
            $existing = $cres->fetch();
            $cres->closeCursor();

            if ($existing) {
                return ['success' => false, 'error' => 'This calendar is already connected to this team'];
            }

            // Insert dav_shares row granting the circle read-write access (access=2).
            $circlePublicUri = 'teamhub-' . substr($teamId, 0, 8) . '-' . $calendarId;
            $db->insertIfNotExist('*PREFIX*dav_shares', [
                'principaluri' => $circlePrincipal,
                'type'         => 'calendar',
                'access'       => 2,
                'resourceid'   => $calendarId,
                'publicuri'    => $circlePublicUri,
            ], ['principaluri', 'resourceid']);

            // v4.6.20 — no public token. This used to mirror createCalendar()
            // and publish the calendar with an `access = 4` row so the Calendar
            // tab could iframe `/apps/calendar/p/{token}`. That route was the
            // only way to show a single calendar, and the price was a share
            // anyone on the internet could read: the page answered
            // unauthenticated, and so did `remote.php/dav/public-calendars/
            // {token}/?export`, which returns the whole agenda as ICS. Worse
            // here than in createCalendar() — this path publishes a calendar
            // the user already had, which they never asked to make public.
            //
            // The tab renders `TeamCalendarGrid` now, against a
            // membership-gated endpoint, so nothing needs the token.

            return [
                'success'     => true,
                'calendar_id' => $calendarId,
                'name'        => $calendarName,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] connectExistingCalendar failed', [
                'teamId' => $teamId, 'calendarId' => $calendarId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['success' => false, 'error' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Remove the team's circle access from a specific calendar (by ID).
     * Deletes only the dav_shares row for the circle principal.
     * The calendar itself and its events are preserved.
     */
    public function removeCalendarAccess(string $teamId, int $calendarId, \OCP\IDBConnection $db): bool {
        try {
            $principalUri = 'principals/circles/' . $teamId;
            $qb = $db->getQueryBuilder();
            $affected = $qb->delete('dav_shares')
                ->where($qb->expr()->eq('type',         $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->andWhere($qb->expr()->eq('resourceid',   $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logger->debug('[TeamHub][CalendarService] removeCalendarAccess: dav_shares row removed', [
                'teamId' => $teamId, 'calendarId' => $calendarId,
                'affected' => $affected, 'app' => Application::APP_ID,
            ]);
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] removeCalendarAccess failed', [
                'teamId' => $teamId, 'calendarId' => $calendarId,
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return false;
        }
    }

    /**
     * Delete a specific calendar by ID (multi-resource-aware).
     */
    public function deleteCalendarById(int $calendarId, \OCP\IDBConnection $db): array {
        try {
            // Delete via CalDavBackend (cascades events, attendees, alarms).
            try {
                $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
                $caldav->deleteCalendar($calendarId, true);
            } catch (\Throwable $e) {
                // Fallback: manual QB delete
                $this->logger->warning('[TeamHub][CalendarService] deleteCalendarById: CalDavBackend failed, using QB', [
                    'calendarId' => $calendarId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                foreach (['dav_shares', 'calendarobjects', 'calendars'] as $tbl) {
                    $col = ($tbl === 'dav_shares') ? 'resourceid' : (($tbl === 'calendars') ? 'id' : 'calendarid');
                    try {
                        $dqb = $db->getQueryBuilder();
                        $dqb->delete($tbl)
                            ->where($dqb->expr()->eq($col, $dqb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                            ->executeStatement();
                    } catch (\Throwable) {}
                }
            }
            $this->logger->info('[TeamHub][CalendarService] deleteCalendarById: calendar deleted', [
                'calendarId' => $calendarId, 'app' => Application::APP_ID,
            ]);
            return ['deleted' => true, 'calendar_id' => $calendarId];
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] deleteCalendarById failed', [
                'calendarId' => $calendarId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => $e->getMessage()];
        }
    }

    public function deleteCalendar(string $teamId, \OCP\IDBConnection $db): array {
        try {
            // Find the calendar via dav_shares: principaluri = principals/circles/{teamId}
            $principalUri = 'principals/circles/' . $teamId;
            $qb = $db->getQueryBuilder();
            $res = $qb->select('resourceid')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();

            if (!$row) {
                return ['deleted' => false, 'detail' => 'No calendar found for this team'];
            }

            $calendarId = (int)$row['resourceid'];

            // Delete via CalDavBackend (cascades events, attendees, alarms)
            try {
                $caldav = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
                $caldav->deleteCalendar($calendarId, true);
            } catch (\Throwable $e) {
                // Fallback: delete dav_shares row and calendarobjects manually
                $this->logger->warning('[TeamHub][CalendarService] deleteCalendar: CalDavBackend failed, using QB', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                foreach (['dav_shares', 'calendarobjects', 'calendars'] as $tbl) {
                    $col = ($tbl === 'dav_shares') ? 'resourceid' : 'calendarid';
                    if ($tbl === 'calendars') {
                        $col = 'id';
                    }
                    try {
                        $dqb = $db->getQueryBuilder();
                        $dqb->delete($tbl)
                            ->where($dqb->expr()->eq($col, $dqb->createNamedParameter($calendarId)))
                            ->executeStatement();
                    } catch (\Throwable $inner) {
                        $this->logger->warning('[TeamHub][CalendarService] deleteCalendar QB fallback failed', [
                            'table' => $tbl, 'error' => $inner->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                }
            }

            return ['deleted' => true, 'detail' => "Calendar {$calendarId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][CalendarService] deleteCalendar failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Delete the Deck board shared with this team circle.
     * Cascades: deck_board_acl/deck_acl, deck_cards, deck_stacks, deck_boards.
     */

    // =========================================================================
    // Ownership (v4.6.20)
    // =========================================================================

    /**
     * Move the team's own calendars to `$newOwnerUid` (v4.6.20).
     *
     * Called when team ownership transfers, so the calendar follows the team
     * rather than staying with whoever happened to run the create wizard.
     *
     * **Why this matters more than it looks.** A CalDAV calendar belongs to a
     * *person*: `RootCollection` mounts a calendar home for
     * `principals/users`, `principals/calendar-resources` and
     * `principals/calendar-rooms` and nothing else, so a calendar cannot be
     * owned by a circle — writing `principals/circles/{teamId}` would leave it
     * addressed by a principal that has no calendar home, invisible to the
     * Calendar app and to every client. A human owner is therefore unavoidable,
     * and the risk that comes with it is real: NC's `UserEventsListener`
     * collects `getUsersOwnCalendars()` on account deletion and deletes them,
     * so a team calendar left behind on a departing account is destroyed for
     * the whole team. Following the transfer is what keeps the owner someone
     * the team still has.
     *
     * **Only calendars TeamHub created.** `origin = 'teamhub_create'` in the
     * resource registry. A calendar the team merely connected belongs to the
     * user who already had it — moving that out of their account because a team
     * changed hands would be taking someone's personal calendar away.
     *
     * The `occ dav:move-calendar` command exists for this and does the same
     * `UPDATE`, but its safety checks live in the command rather than in
     * `CalDavBackend::moveCalendar()` — which is a bare two-column update that
     * silently matches nothing if the source is wrong. Those checks are
     * reproduced here: the destination must exist, a URI collision is resolved
     * rather than left to violate `calendars_index` (UNIQUE on
     * `principaluri, uri`), and the row count is verified afterwards.
     *
     * Sharees keep access — `dav_shares` rows are keyed on `resourceid`, not on
     * the owner — but their CalDAV URL changes, because a shared calendar is
     * addressed `…/calendars/{uid}/{uri}_shared_by_{owner}`. Anyone syncing the
     * team calendar in a desktop or mobile client has to re-add it. That is
     * inherent to moving a calendar in NC and is why the command warns about it
     * too; it is reported to the caller rather than hidden.
     *
     * @return array{moved: list<array{calendarId:int, from:string, to:string, renamedTo:?string}>, failed: list<array{calendarId:int, reason:string}>, sharedUrlsChanged: bool}
     */
    public function transferTeamCalendars(string $teamId, string $newOwnerUid): array {
        $moved  = [];
        $failed = [];

        $userManager = $this->container->get(\OCP\IUserManager::class);
        if (!$userManager->userExists($newOwnerUid)) {
            // Same refusal the occ command makes. Writing a principal for an
            // account that does not exist would orphan the calendar exactly the
            // way this method exists to prevent.
            throw new \InvalidArgumentException('Cannot move calendars to an account that does not exist.');
        }

        $db           = $this->container->get(\OCP\IDBConnection::class);
        $newPrincipal = 'principals/users/' . $newOwnerUid;

        foreach ($this->teamOwnedCalendarIds($db, $teamId) as $calendarId) {
            try {
                $current = $this->calendarRow($db, $calendarId);
                if ($current === null) {
                    // Registered but gone from oc_calendars — a stale registry
                    // row, not a failure worth reporting to the user.
                    continue;
                }
                if ($current['principaluri'] === $newPrincipal) {
                    continue; // Already there; the transfer is a no-op for it.
                }

                $targetUri = $this->freeCalendarUri($db, $newPrincipal, (string)$current['uri']);
                if ($targetUri === null) {
                    $failed[] = [
                        'calendarId' => $calendarId,
                        'reason'     => 'no free calendar name on the destination account',
                    ];
                    continue;
                }

                $qb       = $db->getQueryBuilder();
                $affected = $qb->update('calendars')
                    ->set('principaluri', $qb->createNamedParameter($newPrincipal))
                    ->set('uri', $qb->createNamedParameter($targetUri))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    // Scoped to the principal read a moment ago, so two
                    // concurrent transfers cannot both believe they moved it.
                    ->andWhere($qb->expr()->eq('principaluri', $qb->createNamedParameter($current['principaluri'])))
                    ->executeStatement();

                if ($affected === 0) {
                    // The update is WHERE-scoped, so "nothing matched" is a
                    // silent no-op rather than an error — it has to be checked
                    // for explicitly or a failed move reports as a success.
                    $failed[] = ['calendarId' => $calendarId, 'reason' => 'calendar was modified concurrently'];
                    continue;
                }

                $moved[] = [
                    'calendarId' => $calendarId,
                    'from'       => (string)$current['principaluri'],
                    'to'         => $newPrincipal,
                    'renamedTo'  => $targetUri === (string)$current['uri'] ? null : $targetUri,
                ];
            } catch (\Throwable $e) {
                $failed[] = ['calendarId' => $calendarId, 'reason' => $e->getMessage()];
                $this->logger->warning('[TeamHub][CalendarService] Calendar ownership move failed', [
                    'teamId' => $teamId, 'calendarId' => $calendarId,
                    'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }

        return [
            'moved'  => $moved,
            'failed' => $failed,
            // True whenever anything actually moved: every sharee's CalDAV URL
            // for that calendar now carries a different `_shared_by_` segment.
            'sharedUrlsChanged' => $moved !== [],
        ];
    }

    /**
     * Calendar ids this team owns *and* TeamHub created.
     *
     * @return list<int>
     */
    private function teamOwnedCalendarIds(\OCP\IDBConnection $db, string $teamId): array {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('resource_id')
            ->from('teamhub_team_app_resources')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
            ->andWhere($qb->expr()->eq('app_id', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter('teamhub_create')))
            ->executeQuery();

        $ids = [];
        while ($row = $res->fetch()) {
            $id = (int)$row['resource_id'];
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $res->closeCursor();

        return $ids;
    }

    /**
     * @return array{principaluri:string, uri:string}|null
     */
    private function calendarRow(\OCP\IDBConnection $db, int $calendarId): ?array {
        $qb  = $db->getQueryBuilder();
        $res = $qb->select('principaluri', 'uri')
            ->from('calendars')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        return $row === false ? null : [
            'principaluri' => (string)$row['principaluri'],
            'uri'          => (string)$row['uri'],
        ];
    }

    /**
     * A calendar URI free on `$principal`, starting from `$preferred`.
     *
     * `calendars_index` is UNIQUE on `(principaluri, uri)`, so moving a
     * calendar onto an account that already has one by that name would violate
     * it — the destination is very often a member of the same team, which is
     * exactly the account most likely to hold a similarly named calendar.
     * Suffixes are tried the way the occ command does; null means give up
     * rather than loop.
     */
    private function freeCalendarUri(\OCP\IDBConnection $db, string $principal, string $preferred): ?string {
        for ($i = 0; $i <= 20; $i++) {
            $candidate = $i === 0 ? $preferred : $preferred . '-' . $i;

            $qb  = $db->getQueryBuilder();
            $res = $qb->select('id')
                ->from('calendars')
                ->where($qb->expr()->eq('principaluri', $qb->createNamedParameter($principal)))
                ->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter($candidate)))
                ->setMaxResults(1)
                ->executeQuery();
            $taken = $res->fetch();
            $res->closeCursor();

            if ($taken === false) {
                return $candidate;
            }
        }

        return null;
    }

    // =========================================================================
    // Range reader for the team calendar grid (v4.6.20)
    // =========================================================================

    /**
     * NC's own ceiling for a never-ending series, mirrored from
     * `CalDavBackend::MAX_DATE` ('2038-01-01').
     *
     * An infinitely recurring event gets `lastoccurence` set to this timestamp
     * rather than null, so the prefilter below keeps working for it — but only
     * until that date, after which NC would exclude every infinite series from
     * its own range queries too. Their bound, inherited deliberately rather
     * than worked around: a local ceiling that disagreed with the one the
     * column was written against would be worse than the ceiling itself.
     */
    private const OCCURRENCE_MAX_DATE = '2038-01-01';

    /**
     * Hard cap on instances returned for one range request.
     *
     * A month of a busy team calendar is a few hundred; the cap exists so a
     * pathological series (a minutely RRULE, which is legal) cannot turn one
     * grid navigation into an unbounded response. Hitting it is reported in
     * the payload rather than silently truncating, so the frontend can say so.
     */
    private const MAX_RANGE_INSTANCES = 2000;

    /**
     * Every event instance on the team's calendars overlapping [$start, $end).
     *
     * This is the grid's data source, and it is deliberately **not** the same
     * reader as `ActivityService::getTeamCalendarEvents()`. That one answers
     * "what is coming up soon" for the home widget: it caps at a row count,
     * orders by `lastmodified`, and reads `DTSTART` off the master VEVENT. All
     * three are right for a widget and wrong for a grid — the third one most of
     * all, because it means a weekly standup created in March renders once, in
     * March, and never again. Consolidating the two is worth doing and is
     * logged in HANDOFF; doing it here would have meant changing the widget's
     * payload in the same session that introduced the grid.
     *
     * Four things this gets right that the widget reader does not:
     *
     * 1. **Recurrence is expanded.** `VCalendar::expand()` runs the RRULE and
     *    returns one VEVENT per instance in the window.
     * 2. **`RECURRENCE-ID` overrides are honoured** — a single moved or
     *    cancelled occurrence renders where it was moved to, because the whole
     *    object (master plus overrides) is handed to `expand()` together.
     * 3. **Range filtering is by overlap, not by start.** A conference running
     *    Thursday to Tuesday appears on Monday. `expand()` uses
     *    `isInTimeRange()` for one-offs and compares `getDTEnd() > $start` for
     *    series, so both halves are overlap-correct.
     * 4. **The window is narrowed in SQL first**, via `firstoccurence` /
     *    `lastoccurence`. Without it every navigation parses every event in the
     *    calendar; a year of standups is 250+ needless `Reader::read()` calls
     *    per arrow-click.
     *
     * **All times come back in UTC.** `expand()` converts everything and strips
     * VTIMEZONE, which is what an ISO-8601 wire format wants anyway. All-day
     * events keep `hasTime() === false` and are emitted as dates.
     *
     * @param int $start inclusive window start, Unix timestamp
     * @param int $end   exclusive window end, Unix timestamp
     * @return array{events: list<array<string,mixed>>, truncated: bool}
     */
    public function getTeamEventsInRange(string $teamId, int $start, int $end): array {
        if (!$this->userSession->getUser()) {
            throw new \Exception('User not authenticated');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('The range end must be later than its start.');
        }

        $db          = $this->container->get(\OCP\IDBConnection::class);
        $calendars   = $this->teamCalendars($db, $teamId);
        if ($calendars === []) {
            return ['events' => [], 'truncated' => false];
        }

        $rangeStart = (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('UTC'));
        $rangeEnd   = (new \DateTimeImmutable('@' . $end))->setTimezone(new \DateTimeZone('UTC'));

        $events    = [];
        $truncated = false;

        foreach ($calendars as $calendarId => $calendar) {
            $qb = $db->getQueryBuilder();
            $qb->select('co.id', 'co.uri', 'co.calendardata')
               ->from('calendarobjects', 'co')
               ->where($qb->expr()->eq('co.calendarid', $qb->createNamedParameter($calendarId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('co.componenttype', $qb->createNamedParameter('VEVENT')))
               // Same exclusion the widget reader uses — NC keeps tombstones as
               // `<uri>-deleted.ics` rows rather than removing them outright.
               ->andWhere($qb->expr()->notLike('co.uri', $qb->createNamedParameter('%-deleted.ics')))
               // The prefilter. NULL is admitted rather than excluded on both
               // sides: the columns are nullable, and an object whose bounds NC
               // could not compute must still be parsed and judged on its real
               // contents. Dropping it here would make an event invisible with
               // no way to tell from the payload that it had been skipped.
               ->andWhere($qb->expr()->orX(
                   $qb->expr()->isNull('co.lastoccurence'),
                   $qb->expr()->gte('co.lastoccurence', $qb->createNamedParameter($start, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
               ))
               ->andWhere($qb->expr()->orX(
                   $qb->expr()->isNull('co.firstoccurence'),
                   $qb->expr()->lte('co.firstoccurence', $qb->createNamedParameter($end, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
               ));

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                if (count($events) >= self::MAX_RANGE_INSTANCES) {
                    $truncated = true;
                    break;
                }
                foreach ($this->expandRow($row, $calendar, $rangeStart, $rangeEnd) as $instance) {
                    $events[] = $instance;
                }
            }
            $result->closeCursor();

            if ($truncated) {
                break;
            }
        }

        // Sorted here rather than in SQL: instances are produced per object, so
        // a series contributes its occurrences in its own order and the merged
        // list is not chronological until every object has been expanded.
        usort($events, static fn (array $a, array $b) => ($a['startTs'] <=> $b['startTs']) ?: strcmp($a['title'], $b['title']));

        return ['events' => $events, 'truncated' => $truncated];
    }

    /**
     * Read a `calendardata` value that may arrive as a stream.
     *
     * **This is a Postgres-only failure if you skip it.** `calendarobjects.
     * calendardata` is `bytea` on Postgres and a `LONGBLOB`/text-ish column on
     * MySQL, and NC's QueryBuilder hands back a *stream resource* for the
     * former. Casting that with `(string)` yields `"Resource id #12"`, which
     * Sabre rejects — so every event in the calendar is discarded as
     * unparseable and the grid renders empty, on Postgres only, while MySQL
     * works perfectly. Observed exactly that way: the endpoint logged
     * "Unparseable calendar object skipped" for every object on the instance.
     *
     * Mirrors `CalDavBackend::readBlob()`, which exists in NC for this reason
     * and is private, so it cannot be reused directly.
     */
    private static function readBlob(mixed $data): string {
        if (is_resource($data)) {
            return (string)stream_get_contents($data);
        }

        return (string)$data;
    }

    /**
     * Expand one `calendarobjects` row into its instances within the window.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $calendar
     * @return list<array<string,mixed>>
     */
    private function expandRow(
        array $row,
        array $calendar,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        try {
            $vcalendar = \Sabre\VObject\Reader::read(self::readBlob($row['calendardata']));
        } catch (\Throwable $e) {
            // One unreadable object must not empty the grid. Logged without the
            // calendar data itself, which is user content.
            $this->logger->warning('[TeamHub][CalendarService] Unparseable calendar object skipped', [
                'objectUri' => (string)$row['uri'], 'app' => Application::APP_ID,
            ]);
            return [];
        }

        if (!isset($vcalendar->VEVENT)) {
            return [];
        }

        // Read BEFORE expanding. Every VEVENT that comes back from expand()
        // carries a RECURRENCE-ID — including the plain instances of an
        // ordinary series — so after expansion there is no way to tell a
        // recurring event from a one-off, and no way to tell a genuine
        // override from a generated occurrence. The "repeats" flag has to be
        // taken from the master object while that distinction still exists.
        $recurring = false;
        foreach ($vcalendar->VEVENT as $candidate) {
            if (isset($candidate->RRULE) || isset($candidate->RDATE)) {
                $recurring = true;
                break;
            }
        }

        try {
            // expand() RETURNS a new VCalendar rather than mutating this one —
            // discarding the return value leaves the original, unexpanded and
            // unfiltered, which reads as "recurrence is broken" while actually
            // being a dropped assignment.
            $expanded = $vcalendar->expand($rangeStart, $rangeEnd, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CalendarService] Could not expand recurrence, object skipped', [
                'objectUri' => (string)$row['uri'],
                'error'     => $e->getMessage(),
                'app'       => Application::APP_ID,
            ]);
            return [];
        }

        // Null, not an empty list, when nothing in this object fell in the
        // window — `foreach` over it would raise a warning on every object the
        // range excludes, which is most of them.
        if (!isset($expanded->VEVENT)) {
            return [];
        }

        $out = [];
        foreach ($expanded->VEVENT as $vevent) {
            $instance = $this->mapInstance($vevent, $row, $calendar, $recurring);
            if ($instance !== null) {
                $out[] = $instance;
            }
        }

        return $out;
    }

    /**
     * Map one expanded VEVENT to the grid's wire shape.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $calendar
     * @return array<string,mixed>|null
     */
    private function mapInstance(
        \Sabre\VObject\Component $vevent,
        array $row,
        array $calendar,
        bool $recurring,
    ): ?array {
        if (!isset($vevent->DTSTART)) {
            return null;
        }

        $dtstart   = $vevent->DTSTART;
        $startTime = $dtstart->getDateTime();
        $allDay    = !$dtstart->hasTime();

        $endTime = null;
        if (isset($vevent->DTEND)) {
            $endTime = $vevent->DTEND->getDateTime();
        } elseif (isset($vevent->DURATION)) {
            $endTime = (clone $startTime)->add($vevent->DURATION->getDateInterval());
        }

        $startTs = $startTime->getTimestamp();

        $attendees = [];
        if (isset($vevent->ATTENDEE)) {
            foreach ($vevent->ATTENDEE as $attendee) {
                $address = (string)$attendee;
                $attendees[] = [
                    // The CN parameter is a display name; the value is a
                    // mailto: URI. Neither is guaranteed present.
                    'name'     => isset($attendee['CN']) ? (string)$attendee['CN'] : '',
                    'email'    => str_starts_with(strtolower($address), 'mailto:') ? substr($address, 7) : '',
                    'partstat' => isset($attendee['PARTSTAT']) ? strtoupper((string)$attendee['PARTSTAT']) : 'NEEDS-ACTION',
                ];
            }
        }

        return [
            // Unique per *instance*, not per object: a series shares one UID
            // and one uri across every occurrence, so keying a grid on either
            // makes Vue treat five standups as one row. The instance start is
            // what separates them.
            'id'            => (string)$row['uri'] . '#' . $startTs,
            'uid'           => isset($vevent->UID) ? (string)$vevent->UID : '',
            'uri'           => (string)$row['uri'],
            'title'         => isset($vevent->SUMMARY) ? (string)$vevent->SUMMARY : '',
            'start'         => $allDay ? $startTime->format('Y-m-d') : $startTime->format('c'),
            'end'           => $endTime === null ? null : ($allDay ? $endTime->format('Y-m-d') : $endTime->format('c')),
            'startTs'       => $startTs,
            'allDay'        => $allDay,
            'recurring'     => $recurring,
            'location'      => isset($vevent->LOCATION)    ? (string)$vevent->LOCATION    : null,
            'description'   => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : null,
            'status'        => isset($vevent->STATUS) ? strtoupper((string)$vevent->STATUS) : null,
            'organiser'     => isset($vevent->ORGANIZER) && isset($vevent->ORGANIZER['CN'])
                ? (string)$vevent->ORGANIZER['CN']
                : null,
            'attendees'     => $attendees,
            'calendarId'    => $calendar['id'],
            'calendarName'  => $calendar['name'],
            'calendarColor' => $calendar['color'],
            // Points at NC Calendar, which is where editing happens — the grid
            // itself is read-only. The recurrence id is this instance's own
            // start, so a series opens on the occurrence that was clicked
            // rather than on its first.
            'editUrl'       => $this->eventEditUrl($calendar, (string)$row['uri'], $startTs),
        ];
    }

    /**
     * Build the NC Calendar deep link for one event instance.
     *
     * The id is the base64 of the object's DAV path, which is how the Calendar
     * app addresses an event. **Standard base64 with padding, percent-encoded**
     * — the encoding detail is load-bearing and was established the hard way in
     * v4.5.x: the app derives this id as `btoa(davUrl)` and reads it back with
     * `atob()`, which rejects the base64url alphabet, so emitting '-' or '_'
     * made an event fail to resolve with "event does not exist" whenever the
     * encoding happened to produce a '+' or '/'. The percent-encoding is
     * because standard base64 can contain '/', which would otherwise split the
     * path segment the id occupies.
     *
     * Kept identical to `ActivityService`'s copy on purpose. It is duplicated
     * rather than shared because the two readers are slated to be consolidated
     * (see HANDOFF) and a shared helper extracted now would have to move again;
     * if they are still separate a version from now, extract it.
     *
     * @param array<string,mixed> $calendar
     */
    private function eventEditUrl(array $calendar, string $objectUri, int $recurrenceId): ?string {
        if ($calendar['owner'] === '' || $calendar['uri'] === '') {
            return null;
        }
        $davPath  = '/remote.php/dav/calendars/' . $calendar['owner'] . '/' . $calendar['uri'] . '/' . $objectUri;
        $objectId = rawurlencode(base64_encode($davPath));

        return '/apps/calendar/timeGridWeek/now/edit/sidebar/' . $objectId . '/' . $recurrenceId;
    }

    /**
     * Every calendar shared with this team's circle, keyed by calendar id.
     *
     * Resolved from `dav_shares` rather than from TeamHub's own resource
     * registry, so a calendar connected with `connectExistingCalendar()`
     * appears in the grid alongside the provisioned one. The registry knows
     * which calendars TeamHub *created*; `dav_shares` knows which ones the team
     * can actually see, and for reading events the second question is the one
     * that matters.
     *
     * @return array<int, array{id:int, name:string, uri:string, owner:string, color:string|null}>
     */
    private function teamCalendars(\OCP\IDBConnection $db, string $teamId): array {
        $qb = $db->getQueryBuilder();
        // `calendarcolor` is a column on oc_calendars, not a DAV property in
        // oc_properties — which is where the '{…ical/}calendar-color' name
        // passed to CalDavBackend::createCalendar() might suggest it lands.
        // oc_properties holds only what a client PROPPATCHed later (colour
        // order, mostly), and reading colour from there returns null for every
        // calendar TeamHub provisioned.
        $qb->select('c.id', 'c.displayname', 'c.uri', 'c.principaluri', 'c.calendarcolor')
           ->from('dav_shares', 's')
           ->innerJoin('s', 'calendars', 'c', $qb->expr()->eq('c.id', 's.resourceid'))
           ->where($qb->expr()->eq('s.type', $qb->createNamedParameter('calendar')))
           ->andWhere($qb->expr()->eq('s.principaluri', $qb->createNamedParameter('principals/circles/' . $teamId)));

        $result    = $qb->executeQuery();
        $calendars = [];
        while ($row = $result->fetch()) {
            $id    = (int)$row['id'];
            $parts = explode('/', (string)$row['principaluri']);
            $color = (string)($row['calendarcolor'] ?? '');

            $calendars[$id] = [
                'id'    => $id,
                'name'  => (string)($row['displayname'] ?: $row['uri']),
                'uri'   => (string)$row['uri'],
                'owner' => (string)end($parts),
                // Null rather than '' when unset, so the grid falls back to its
                // own default instead of rendering an empty colour.
                'color' => $color === '' ? null : $color,
            ];
        }
        $result->closeCursor();

        return $calendars;
    }
}
