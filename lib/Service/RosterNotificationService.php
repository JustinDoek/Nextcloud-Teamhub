<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The roster tells the person it is about (v4.14.8, DESIGN §2.250).
 *
 * Until this release nothing in the roster reached anybody personally. *Notify
 * team* posts to the message stream and a hand-over goes to Talk, and both are
 * right for what they are; but being put on a shift, taken off one, or having
 * the week you were told about change under you reached nobody at all. The
 * last one matters beyond convenience: §2.233 records that a roster is a
 * communication with a notice period in several countries we sell into, and a
 * change to a communicated roster is a communication too.
 *
 * ## What is sent, and what deliberately is not
 *
 *   - **Only about published shifts.** A draft is not a communication
 *     (§2.233); a member is never served one, so they are never told about
 *     one either. Publishing is the moment that tells them, in one message
 *     for the whole week rather than one per shift.
 *   - **Never to the person who did it.** A team admin who assigns themselves
 *     already knows.
 *   - **Not a My Work row.** None of these asks anybody to do anything; they
 *     are notices about a decision already taken. `/mywork-workflows` is for
 *     a hand-over that waits on somebody, and nothing here waits.
 *
 * ## Replacing rather than piling up
 *
 * The week-level notices (published, back in draft) are keyed on the team and
 * the week's Monday, and each one withdraws the previous one for the same
 * person and week. A week published, pulled back and published again leaves
 * one notification saying what is true now, not three to read in order.
 *
 * Every string is built in the recipient's language by `Notifier`; this class
 * only records the facts.
 */
class RosterNotificationService {

    public const OBJECT_WEEK  = 'roster_week';
    public const OBJECT_SHIFT = 'roster_shift';

    public const PUBLISHED      = 'roster_published';
    public const WITHDRAWN      = 'roster_withdrawn';
    public const ASSIGNED       = 'roster_assigned';
    public const UNASSIGNED     = 'roster_unassigned';
    public const SHIFT_REMOVED  = 'roster_shift_removed';
    public const ROLE_CHANGED   = 'roster_role_changed';
    public const HANDOVER_TAKEN = 'roster_handover_taken';

    /** A week's notice lists at most this many shifts; the roster has the rest. */
    private const MAX_LISTED = 7;

    public function __construct(
        private INotificationManager $notificationManager,
        private IUserManager $userManager,
        private ITimeFactory $timeFactory,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A week went out. One notice per person on a newly published shift,
     * listing every published shift they have in the range.
     *
     * @param array<string, list<array{date: string, label: string, start: string, end: string}>> $shiftsByUser
     */
    public function weekPublished(string $teamId, string $from, string $to, array $shiftsByUser, string $actorUid): void {
        $teamName = $this->teamName($teamId);
        foreach ($shiftsByUser as $uid => $shifts) {
            if ($uid === '' || $uid === $actorUid || $shifts === []) {
                continue;
            }
            $this->withdrawWeek((string)$uid, $teamId, $from);
            $this->send((string)$uid, self::PUBLISHED, self::OBJECT_WEEK, $teamId . ':' . $from, [
                'teamId'   => $teamId,
                'teamName' => $teamName,
                'from'     => $from,
                'to'       => $to,
                'count'    => count($shifts),
                'shifts'   => array_slice($shifts, 0, self::MAX_LISTED),
            ]);
        }
    }

    /**
     * A published week went back to concept. Everybody who was on it is told
     * their shifts may still change, and the publish notice is withdrawn.
     *
     * @param list<string> $uids
     */
    public function weekWithdrawn(string $teamId, string $from, string $to, array $uids, string $actorUid): void {
        $teamName = $this->teamName($teamId);
        foreach (array_unique($uids) as $uid) {
            if ($uid === '' || $uid === $actorUid) {
                continue;
            }
            $this->withdrawWeek($uid, $teamId, $from);
            $this->send($uid, self::WITHDRAWN, self::OBJECT_WEEK, $teamId . ':' . $from, [
                'teamId'   => $teamId,
                'teamName' => $teamName,
                'from'     => $from,
                'to'       => $to,
            ]);
        }
    }

    /**
     * One person and one published shift: put on it, taken off it, the shift
     * removed, its role changed, or a hand-over they asked for taken.
     *
     * @param array{date: string, label: string, start: string, end: string, oldLabel?: string} $shift
     */
    public function aboutShift(string $subject, string $uid, string $teamId, int $shiftId, array $shift, string $actorUid): void {
        if ($uid === '' || $uid === $actorUid) {
            return;
        }
        $this->send($uid, $subject, self::OBJECT_SHIFT, (string)$shiftId, [
            'teamId'    => $teamId,
            'teamName'  => $this->teamName($teamId),
            'actorUid'  => $actorUid,
            'actorName' => $this->displayName($actorUid),
            'shift'     => $shift,
        ]);
    }

    /** @param array<string, mixed> $params */
    private function send(string $uid, string $subject, string $objectType, string $objectId, array $params): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime(new \DateTime('@' . $this->timeFactory->getTime()))
                ->setObject($objectType, $objectId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            // A notice that did not go out must never undo a roster change.
            $this->logger->warning('[TeamHub][RosterNotification] notification failed', [
                'subject' => $subject, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }

    private function withdrawWeek(string $uid, string $teamId, string $from): void {
        try {
            $old = $this->notificationManager->createNotification();
            $old->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setObject(self::OBJECT_WEEK, $teamId . ':' . $from);
            $this->notificationManager->markProcessed($old);
        } catch (\Throwable) {
            // Two notices for one week is untidy, not wrong.
        }
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        $name = $user !== null ? trim((string)$user->getDisplayName()) : '';
        return $name !== '' ? $name : $uid;
    }

    private function teamName(string $teamId): string {
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('name', 'display_name')
                ->from('circles_circle')
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            if ($row === false) {
                return '';
            }
            $display = trim((string)($row['display_name'] ?? ''));
            return $display !== '' ? $display : (string)($row['name'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
