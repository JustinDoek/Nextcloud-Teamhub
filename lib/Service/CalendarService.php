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

    public function createCalendar(string $teamId, string $teamName, string $uid): array {

        if (!$this->appManager->isInstalled('calendar')) {
            return ['error' => 'Calendar app not installed'];
        }

        $caldav       = $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $principalUri = 'principals/users/' . $uid;
        $calendarUri  = strtolower(preg_replace('/[^a-z0-9]+/', '-', $teamName))
                       . '-' . substr(md5(uniqid()), 0, 6);

        $calendarId = $caldav->createCalendar($principalUri, $calendarUri, [
            '{DAV:}displayname'                        => $teamName,
            '{http://apple.com/ns/ical/}calendar-color' => '#0082c9',
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

        // Create a public share so the calendar can be embedded via /apps/calendar/p/{token}
        // NC calendar public shares use a random token as publicuri with access=4
        $publicToken = bin2hex(random_bytes(16)); // 32-char hex token
        $db->insertIfNotExist('*PREFIX*dav_shares', [
            'principaluri' => 'principals/users/' . $uid,
            'type'         => 'calendar',
            'access'       => 4,   // 4 = public read-only
            'resourceid'   => (int)$calendarId,
            'publicuri'    => $publicToken,
        ], ['publicuri']);


        return ['calendar_id' => $calendarId, 'name' => $teamName, 'public_token' => $publicToken];
    }

    /**
     * Create a Deck board and share it with the circle.
     *
     * All DB writes use the QueryBuilder (IDBConnection::insert() doesn't exist in NC32 —
     * only QueryBuilder and executeStatement are available on ConnectionAdapter).
     *
     * ACL type 7 = circle, per official Deck API docs.
     */
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
                $this->logger->warning('[CalendarService] deleteCalendar: CalDavBackend failed, using QB', [
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
                        $this->logger->warning('[CalendarService] deleteCalendar QB fallback failed', [
                            'table' => $tbl, 'error' => $inner->getMessage(), 'app' => Application::APP_ID,
                        ]);
                    }
                }
            }

            return ['deleted' => true, 'detail' => "Calendar {$calendarId} deleted"];

        } catch (\Throwable $e) {
            $this->logger->error('[CalendarService] deleteCalendar failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Operation failed — see server log for details'];
        }
    }

    /**
     * Delete the Deck board shared with this team circle.
     * Cascades: deck_board_acl/deck_acl, deck_cards, deck_stacks, deck_boards.
     */

}
