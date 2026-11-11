<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\RosterAssignment;
use OCA\TeamHub\Db\RosterAssignmentMapper;
use OCA\TeamHub\Db\RosterShift;
use OCA\TeamHub\Db\RosterShiftMapper;
use OCA\TeamHub\Db\ShiftRequest;
use OCA\TeamHub\Db\ShiftRequestMapper;
use OCA\TeamHub\Db\ShiftType;
use OCA\TeamHub\Db\ShiftTypeMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * What a year of the roster adds up to (v4.13.71, DESIGN §2.237).
 *
 * Two readings of the same numbers: one person's own, and the team's with a
 * line per member. Twelve months and a year total, for one calendar year.
 *
 * ## "Worked" means the shift is in the past, and that is a definition rather
 * ## than a measurement
 *
 * Nothing in TeamHub records that a shift was actually staffed — there is no
 * clock-in and no tick afterwards. So a shift whose date has passed counts as
 * worked and one still ahead counts as planned, which is exactly right for a
 * team that works its rota and wrong on the day somebody called in sick. The
 * Time module is no help here: its minutes hang off Deck cards in a project,
 * not off shifts, so they answer a different question.
 *
 * **The response says so in `workedBasis`**, so a client can label the column
 * honestly instead of implying a measurement nobody took. Justin's call,
 * 2026-11-01, over an after-the-fact tick that people forget.
 *
 * ## Only published shifts count
 *
 * A draft is a plan an admin has not committed to, so counting it would make
 * somebody's year move when a draft is edited. Statistics read what the team
 * was actually told.
 *
 * ## Hours come from the shift type, not from the clock
 *
 * `ShiftType::lengthMinutes()` already handles a shift running past midnight.
 * A roster says *Early*, so a year of Earlies is however long Early is today
 * — which means retiming a shift type changes history. That is a real
 * limitation and the alternative (stamping the length onto every shift at
 * creation) is a migration and a second source of truth; it is not worth it
 * until somebody asks.
 */
class RosterStatsService {

    public function __construct(
        private RosterShiftMapper      $shiftMapper,
        private RosterAssignmentMapper $assignMapper,
        private ShiftTypeMapper        $typeMapper,
        private ShiftRequestMapper     $requestMapper,
        private MemberService          $memberService,
        private IDBConnection          $db,
    ) {}

    /**
     * One person's year, **in every team** (v4.14.11, DESIGN §2.253).
     *
     * Until 4.14.11 this read only the team whose Roster tab the person
     * happened to open it from, so somebody working for two teams saw two
     * halves of their year and never the whole. A person's hours are one
     * person's hours: the reading now walks their own published shifts
     * wherever they are, and `teams` splits the total so they can still see
     * where it came from. It is their own data, so naming a team they have
     * since left is not a disclosure.
     *
     * @return array<string, mixed>
     */
    public function forUser(string $userId, int $year): array {
        $from  = sprintf('%04d-01-01', $year);
        $to    = sprintf('%04d-12-31', $year);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        $months = $this->emptyMonths();
        $total  = $this->emptyBucket();
        $byTeam = [];

        foreach ($this->shiftMapper->findAssignedToUser($userId, $from, $to, null, true) as $shift) {
            $minutes = ($types[$shift->getShiftTypeId()] ?? null)?->lengthMinutes() ?? 0;
            $month   = (int)substr($shift->getShiftDate(), 5, 2) - 1;
            $isPast  = $shift->getShiftDate() < $today;
            $teamId  = $shift->getTeamId();

            $byTeam[$teamId] ??= $this->emptyBucket();
            $this->add($months[$month], $minutes, $isPast);
            $this->add($total, $minutes, $isPast);
            $this->add($byTeam[$teamId], $minutes, $isPast);
        }

        $start = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year)))->getTimestamp();
        $end   = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year + 1)))->getTimestamp();
        foreach ($this->requestMapper->findByRequesterAndPeriod($userId, $start, $end) as $request) {
            /** @var ShiftRequest $request */
            $month  = (int)date('n', $request->getCreatedAt()) - 1;
            $teamId = $request->getTeamId();
            $byTeam[$teamId] ??= $this->emptyBucket();
            $months[$month]['handoverRequests']++;
            $total['handoverRequests']++;
            $byTeam[$teamId]['handoverRequests']++;
        }

        $names = $this->teamNames(array_keys($byTeam));
        $teams = [];
        foreach ($byTeam as $teamId => $bucket) {
            $teams[] = [
                'teamId'   => (string)$teamId,
                'teamName' => $names[$teamId] ?? '',
                'total'    => $bucket,
            ];
        }
        usort($teams, static fn (array $a, array $b): int => $b['total']['plannedMinutes'] <=> $a['total']['plannedMinutes']);

        return [
            'year'        => $year,
            'scope'       => 'user',
            'workedBasis' => 'past_shifts',
            'months'      => $months,
            'total'       => $total,
            'teams'       => $teams,
        ];
    }

    /**
     * Display names for a set of teams, in one query.
     *
     * @param list<string|int> $teamIds
     * @return array<string, string>
     */
    private function teamNames(array $teamIds): array {
        $teamIds = array_values(array_filter(array_map('strval', $teamIds)));
        if ($teamIds === []) {
            return [];
        }
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('unique_id', 'name', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->in('unique_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            $out = [];
            while ($row = $res->fetch()) {
                $display = trim((string)($row['display_name'] ?? ''));
                $out[(string)$row['unique_id']] = $display !== '' ? $display : (string)($row['name'] ?? '');
            }
            $res->closeCursor();
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The team's year, with a line per member.
     *
     * @return array<string, mixed>
     */
    public function forTeam(string $teamId, int $year): array {
        $members = $this->memberService->getAllEffectiveMembers($teamId);
        $uids    = array_values(array_filter(array_map(
            static fn (array $m): string => (string)($m['userId'] ?? ''),
            $members,
        )));

        $data = $this->collect($teamId, $year, $uids);

        $people = [];
        foreach ($members as $member) {
            $uid = (string)($member['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            $total = $data['totals'][$uid] ?? $this->emptyBucket();
            // Somebody with nothing all year is still a row: an empty line is
            // the finding when a roster is unevenly shared.
            $people[] = [
                'userId'      => $uid,
                'displayName' => (string)($member['displayName'] ?? $uid),
                'months'      => $data['months'][$uid] ?? $this->emptyMonths(),
                'total'       => $total,
            ];
        }

        usort($people, static fn (array $a, array $b): int => $b['total']['plannedShifts'] <=> $a['total']['plannedShifts']);

        return [
            'year'        => $year,
            'scope'       => 'team',
            'workedBasis' => 'past_shifts',
            'months'      => $data['teamMonths'],
            'total'       => $data['teamTotal'],
            'people'      => $people,
        ];
    }

    /**
     * Walk one year of published shifts once, bucketing per person per month.
     *
     * One pass rather than a query per month: a year is at most a few thousand
     * shifts for a team, and twelve round trips to draw one screen is the N+1
     * this would otherwise be reported for.
     *
     * @param list<string> $uids
     * @return array{months: array<string, list<array<string,mixed>>>, totals: array<string, array<string,mixed>>, teamMonths: list<array<string,mixed>>, teamTotal: array<string,mixed>}
     */
    private function collect(string $teamId, int $year, array $uids): array {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        $types = [];
        foreach ($this->typeMapper->findAll() as $type) {
            $types[$type->getId()] = $type;
        }

        $wanted = array_fill_keys($uids, true);
        $months = [];
        $totals = [];
        $teamMonths = $this->emptyMonths();
        $teamTotal  = $this->emptyBucket();

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $shifts = array_filter(
            $this->shiftMapper->findByTeamAndRange($teamId, $from, $to),
            static fn (RosterShift $s): bool => $s->isPublished(),
        );
        if ($shifts === []) {
            return $this->finish($months, $totals, $teamMonths, $teamTotal, $teamId, $year, $wanted);
        }

        $assignments = $this->assignMapper->findByShiftIds(
            array_map(static fn (RosterShift $s): int => $s->getId(), $shifts),
        );

        foreach ($shifts as $shift) {
            /** @var ShiftType|null $type */
            $type    = $types[$shift->getShiftTypeId()] ?? null;
            $minutes = $type?->lengthMinutes() ?? 0;
            $month   = (int)substr($shift->getShiftDate(), 5, 2) - 1;
            $isPast  = $shift->getShiftDate() < $today;

            foreach ($assignments[$shift->getId()] ?? [] as $row) {
                /** @var RosterAssignment $row */
                $uid = $row->getUserId();
                if (!isset($wanted[$uid])) {
                    continue;
                }
                $months[$uid] ??= $this->emptyMonths();
                $totals[$uid] ??= $this->emptyBucket();

                $this->add($months[$uid][$month], $minutes, $isPast);
                $this->add($totals[$uid], $minutes, $isPast);
                $this->add($teamMonths[$month], $minutes, $isPast);
                $this->add($teamTotal, $minutes, $isPast);
            }
        }

        return $this->finish($months, $totals, $teamMonths, $teamTotal, $teamId, $year, $wanted);
    }

    /**
     * Fold the hand-over requests in, which are counted by when they were
     * asked rather than by the shift's date: the point of the number is how
     * often somebody needed to get out of a shift.
     */
    private function finish(
        array $months, array $totals, array $teamMonths, array $teamTotal,
        string $teamId, int $year, array $wanted,
    ): array {
        $start = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year)))->getTimestamp();
        $end   = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year + 1)))->getTimestamp();

        foreach ($this->requestMapper->findByTeamAndPeriod($teamId, $start, $end) as $request) {
            /** @var ShiftRequest $request */
            $uid = $request->getRequesterUid();
            if (!isset($wanted[$uid])) {
                continue;
            }
            $month = (int)date('n', $request->getCreatedAt()) - 1;
            $months[$uid] ??= $this->emptyMonths();
            $totals[$uid] ??= $this->emptyBucket();

            $months[$uid][$month]['handoverRequests']++;
            $totals[$uid]['handoverRequests']++;
            $teamMonths[$month]['handoverRequests']++;
            $teamTotal['handoverRequests']++;
        }

        return [
            'months'     => $months,
            'totals'     => $totals,
            'teamMonths' => $teamMonths,
            'teamTotal'  => $teamTotal,
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function add(array &$bucket, int $minutes, bool $isPast): void {
        $bucket['plannedShifts']++;
        $bucket['plannedMinutes'] += $minutes;
        if ($isPast) {
            $bucket['workedShifts']++;
            $bucket['workedMinutes'] += $minutes;
        } else {
            $bucket['upcomingShifts']++;
            $bucket['upcomingMinutes'] += $minutes;
        }
    }

    /** @return list<array<string, mixed>> twelve buckets, January first */
    private function emptyMonths(): array {
        $out = [];
        for ($month = 0; $month < 12; $month++) {
            $bucket = $this->emptyBucket();
            $bucket['month'] = $month;
            $out[] = $bucket;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function emptyBucket(): array {
        return [
            'plannedShifts'    => 0,
            'plannedMinutes'   => 0,
            'workedShifts'     => 0,
            'workedMinutes'    => 0,
            'upcomingShifts'   => 0,
            'upcomingMinutes'  => 0,
            'handoverRequests' => 0,
        ];
    }
}
