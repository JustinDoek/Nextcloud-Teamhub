<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\ShiftAvailability;
use OCA\TeamHub\Db\ShiftAvailabilityMapper;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * When somebody is available to be rostered (v4.13.71, DESIGN §2.237).
 *
 * A grid of seven weekdays by two half-days that a person fills in once, in
 * Personal settings, for every team they are in. **An absent row means
 * available**, so somebody who never opens the screen stays rosterable.
 *
 * ## This says nothing about where they are
 *
 * `teamhub_presence_template` is the workplace pattern — *Tuesday mornings I
 * am at the office*. This is consent to be rostered — *Tuesday mornings I
 * cannot work a shift*. A person can be at the office on a morning they
 * cannot take a shift, and anybody who works nights is the reverse. The two
 * are different facts and conflating them would make one of them a lie.
 *
 * ## It is advice, not a lock
 *
 * A team admin **sees** who is unavailable and is not stopped from rostering
 * them anyway: somebody who says they cannot do Saturdays may still agree to
 * one, and a rota that refuses the conversation is a rota people work around.
 * Booked time off is different and still refuses, because that is a fact about
 * the day rather than a standing preference (`RosterService::absenceBlocking`).
 */
class ShiftAvailabilityService {

    private const DAYS = [0, 1, 2, 3, 4, 5, 6];
    private const HALVES = [0, 1];

    public function __construct(
        private ShiftAvailabilityMapper $mapper,
        private IDBConnection           $db,
        private IL10N                   $l,
    ) {}

    /**
     * One person's week, every cell present, defaulting to available.
     *
     * The screen always gets fourteen answers rather than only the stored
     * exceptions, so the client never has to know the default.
     *
     * @return array{cells: list<array{dayOfWeek: int, halfDay: int, available: bool}>}
     */
    public function get(string $userId): array {
        $stored = [];
        foreach ($this->mapper->findByUser($userId) as $row) {
            $stored[$row->getDayOfWeek() . ':' . $row->getHalfDay()] = $row->getAvailable() === 1;
        }

        $cells = [];
        foreach (self::DAYS as $day) {
            foreach (self::HALVES as $half) {
                $cells[] = [
                    'dayOfWeek' => $day,
                    'halfDay'   => $half,
                    'available' => $stored[$day . ':' . $half] ?? true,
                ];
            }
        }

        return ['cells' => $cells];
    }

    /**
     * Replace a person's week.
     *
     * **Only the exceptions are written.** A cell they left available stores
     * no row, so the table holds what somebody actually said no to and an
     * instance where nobody has opinions keeps an empty table.
     *
     * @param list<array{dayOfWeek: int|string, halfDay: int|string, available: bool}> $cells
     * @throws \InvalidArgumentException
     * @return array{cells: list<array{dayOfWeek: int, halfDay: int, available: bool}>}
     */
    public function save(string $userId, array $cells): array {
        $unavailable = [];
        foreach ($cells as $cell) {
            $day  = (int)($cell['dayOfWeek'] ?? -1);
            $half = (int)($cell['halfDay'] ?? -1);

            if (!in_array($day, self::DAYS, true)) {
                throw new \InvalidArgumentException($this->l->t('A weekday must be 0 (Monday) to 6 (Sunday).'));
            }
            if (!in_array($half, self::HALVES, true)) {
                throw new \InvalidArgumentException($this->l->t('A half-day must be 0 (morning) or 1 (afternoon).'));
            }
            if (($cell['available'] ?? true) === false) {
                $unavailable[$day . ':' . $half] = [$day, $half];
            }
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            $this->mapper->deleteByUser($userId);
            foreach ($unavailable as [$day, $half]) {
                $row = new ShiftAvailability();
                $row->setUserId($userId);
                $row->setDayOfWeek($day);
                $row->setHalfDay($half);
                $row->setAvailable(0);
                $row->setUpdatedAt($now);
                $this->mapper->insert($row);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->get($userId);
    }

    /**
     * Of these people, who has said they cannot work this half-day.
     *
     * @param list<string> $userIds
     * @return array<string, true>
     */
    public function unavailableOn(array $userIds, int $dayOfWeek, int $halfDay): array {
        return $this->mapper->findUnavailable($userIds, $dayOfWeek, $halfDay);
    }

    /** Everything a person has said no to, as the one row the picker needs. */
    public function deleteForUser(string $userId): void {
        $this->mapper->deleteByUser($userId);
    }
}
