<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Which calendars belong to which team (v4.11.15).
 *
 * A team calendar is owned by a user and shared with the team's circle through
 * a `dav_shares` row — see `CalendarService::createCalendar()`. Finding a
 * team's calendars is therefore two indexed reads, and `MeetingWorkProvider`
 * carried them privately until `TaskWorkProvider` needed exactly the same
 * answer. One copy, here.
 *
 * apps.md R-2 note: direct SELECTs, deliberately. `OCP\Calendar\IManager`
 * hands out calendars *per principal* and says nothing about which team a
 * calendar is shared with — the `dav_shares` row is the only record of that,
 * and reading it is what this class is for. Read-only, no side effects.
 */
class TeamCalendarLocator {

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Calendars shared with any of these teams' circles.
     *
     * @param string[] $teamIds
     * @return array<int,array{id:int,teamId:string,name:string,owner:string,slug:string}>
     */
    public function forTeams(array $teamIds): array {
        $principals = [];
        foreach ($teamIds as $teamId) {
            $principals['principals/circles/' . $teamId] = $teamId;
        }
        if ($principals === []) {
            return [];
        }

        $shared = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('resourceid', 'principaluri')
                ->from('dav_shares')
                ->where($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
                ->andWhere($qb->expr()->in('principaluri', $qb->createNamedParameter(
                    array_keys($principals), IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $shared[(int)$row['resourceid']] = $principals[(string)$row['principaluri']] ?? '';
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamCalendarLocator] Share lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }

        if ($shared === []) {
            return [];
        }

        $calendars = [];
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('id', 'principaluri', 'uri', 'displayname')
                ->from('calendars')
                ->where($qb->expr()->in('id', $qb->createNamedParameter(
                    array_keys($shared), IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            while ($row = $res->fetch()) {
                $id    = (int)$row['id'];
                $parts = explode('/', (string)$row['principaluri']);
                $calendars[] = [
                    'id'     => $id,
                    'teamId' => $shared[$id] ?? '',
                    'name'   => (string)($row['displayname'] ?: $row['uri']),
                    'owner'  => (string)end($parts),
                    'slug'   => (string)$row['uri'],
                ];
            }
            $res->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamCalendarLocator] Calendar lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }

        return $calendars;
    }

    /**
     * The calendar's uri **as this viewer sees it**, which is what deep links
     * into the Calendar and Tasks apps are built from.
     *
     * `CalDavBackend::getCalendarsForUser()` returns a calendar you own under
     * its plain uri and one shared with you under `{uri}_shared_by_{owner}`.
     * A team calendar is both, depending on who is looking — the member who
     * created the team owns it, everybody else reaches it through the circle —
     * so a link built from the plain uri is dead for every other member.
     *
     * @param array{owner:string,slug:string} $calendar
     */
    public static function uriFor(array $calendar, string $viewerUid): string {
        $owner = (string)$calendar['owner'];
        $slug  = (string)$calendar['slug'];

        return $owner === $viewerUid ? $slug : $slug . '_shared_by_' . $owner;
    }
}
