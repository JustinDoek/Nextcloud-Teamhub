<?php
declare(strict_types=1);

namespace OCA\TeamHub\BackgroundJob;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Daily check: notify NC admins about license-lifecycle events.
 *
 * Two independent checks per tick:
 *
 * 1. Expiry approaching
 *    - Trial licenses: fire 7 days before paid_until (== exp for trials).
 *    - Paid  licenses: fire 14 days before paid_until (the moment the
 *                      customer's entitlement ends; grace still runs after).
 *
 * 2. Seat overage (fires only once on transition under → over cap)
 *    - When the app's countUniqueTeamMembers() exceeds the licensed seat
 *      cap for the first time. Doesn't fire again while still over; if
 *      count drops back under cap the marker is cleared and a later
 *      re-transition fires again.
 *
 * 3. Member reporting overdue (v4.13.30, metered licences only)
 *    - A metered licence reports its effective member count twice a month.
 *      One notification per rung of the ladder — due (21 days of silence),
 *      overdue (32) and locked (45) — because each rung is new information
 *      and the last says Advanced features have stopped. A banded licence
 *      and a trial never reach `locked`; a banded licence is never nagged
 *      at all.
 *
 * De-duplication per event:
 *   - Expiry:  marker = licenseId@paidUntil. Extending the license mints
 *              a new paidUntil, so the next approach fires its own single
 *              notification.
 *   - Seats:   marker = licenseId  (present while over, cleared when under).
 *   - Report:  marker = licenseId@reportState (cleared once a report lands,
 *              so a later lapse warns again from the bottom rung).
 *
 * Recipient set:
 *   Full NC admins only ('admin' group members). Delegated TeamHub
 *   admins (per AdminSettings.php) can install a license but are a NC-
 *   permissions edge case; the built-in admin group is the always-right
 *   target for "your business license needs attention".
 *
 * Registration: appinfo/info.xml <background-jobs>.
 */
class LicenseExpiryNotificationJob extends TimedJob {

    /** Days before paid_until at which we fire for trial licenses. */
    private const TRIAL_DAYS_THRESHOLD = 7;

    /** Days before paid_until at which we fire for paid licenses. */
    private const PAID_DAYS_THRESHOLD = 14;

    /** appconfig key holding the last (licenseId@paidUntil) we notified for. */
    private const CFG_LAST_NOTIFIED = 'license_expiry_notified_for';

    /** appconfig key holding the licenseId we've flagged as over-seats. */
    private const CFG_OVER_SEATS_FLAG = 'license_over_seats_notified_for';

    /**
     * appconfig key holding the (licenseId@reportState) we last notified for
     * on the member-reporting ladder (v4.13.30).
     */
    private const CFG_REPORT_NOTIFIED = 'license_report_notified_for';

    public function __construct(
        ITimeFactory                 $time,
        private LicenseService       $licenseService,
        private INotificationManager $notificationManager,
        private IGroupManager        $groupManager,
        private IConfig              $config,
        private LoggerInterface      $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void {
        try {
            $status = $this->licenseService->getStatus();

            // Nothing on either axis to check without an installed license.
            // Unlicensed instances render their own UI banner; a notification
            // on top of that would be noise.
            if (empty($status['hasKey'])) {
                return;
            }

            $this->checkExpiry($status);
            $this->checkSeatOverage($status);
            $this->checkSeatReporting($status);
        } catch (\Throwable $e) {
            // Never let a notifier hiccup crash the background-job runner.
            $this->logger->error(
                '[TeamHub] LicenseExpiryNotificationJob failed: ' . $e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e],
            );
        }
    }

    private function checkExpiry(array $status): void {
        $paidUntil = isset($status['paidUntil']) ? (int)$status['paidUntil'] : null;
        if ($paidUntil === null || $paidUntil <= 0) {
            return;
        }

        $isTrial   = !empty($status['isTrial']);
        $threshold = $isTrial ? self::TRIAL_DAYS_THRESHOLD : self::PAID_DAYS_THRESHOLD;

        // ceil() rounds up so a license 6d 23h out registers as 7 days.
        // Prevents an off-by-one where a trial expiring in ~7 days never
        // trips the "≤ 7 days" gate before it hits ~6 days.
        $now           = time();
        $daysRemaining = (int)ceil(($paidUntil - $now) / 86400);

        // Past expiry — nothing helpful to say via notification; the
        // in-app banner already covers grace / soft-lock messaging.
        if ($daysRemaining < 0) return;
        if ($daysRemaining > $threshold) return;

        // Debounce per (license, expiry-timestamp).
        $licenseId  = (string)($status['licenseId'] ?? '');
        $marker     = $licenseId . '@' . $paidUntil;
        $lastMarker = $this->config->getAppValue(
            Application::APP_ID,
            self::CFG_LAST_NOTIFIED,
            '',
        );
        if ($lastMarker === $marker) {
            return;
        }

        $this->notifyExpiryAdmins($isTrial, $daysRemaining, $paidUntil, $licenseId);

        // Record AFTER a successful notify — a failure inside
        // notifyExpiryAdmins() falls through the outer try/catch and we retry
        // next tick with the marker still empty.
        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_LAST_NOTIFIED,
            $marker,
        );
    }

    private function checkSeatOverage(array $status): void {
        $enforcement = (string)($status['seatEnforcement'] ?? 'none');
        $licenseId   = (string)($status['licenseId'] ?? '');

        $flag = $this->config->getAppValue(
            Application::APP_ID,
            self::CFG_OVER_SEATS_FLAG,
            '',
        );

        // Currently under cap → clear any stale flag so the next crossing
        // (or a new license) can fire again.
        if ($enforcement === 'none') {
            if ($flag !== '') {
                $this->config->deleteAppValue(Application::APP_ID, self::CFG_OVER_SEATS_FLAG);
            }
            return;
        }

        // over-warn OR over-lock both count as "the admin needs to know".
        // Debounce on licenseId so the notification fires ONCE per license
        // per "over" episode (over-warn escalating to over-lock still stays
        // under the same flag — same conversation with the admin).
        if ($flag === $licenseId) {
            return;
        }

        $this->notifyOverSeatsAdmins(
            (int)($status['seatsUsed']  ?? 0),
            (int)($status['seatCap']    ?? 0),
            (int)($status['seatLockAt'] ?? 0),
            $licenseId,
        );

        $this->config->setAppValue(
            Application::APP_ID,
            self::CFG_OVER_SEATS_FLAG,
            $licenseId,
        );
    }

    /**
     * The member-reporting ladder (v4.13.30, DESIGN §2.203).
     *
     * **This is the half of the ladder that is not in the admin panel, and
     * with the lock at 45 days it is the half that matters.** An instance
     * that cannot reach the licensing service is very often one whose
     * administrator has no reason to open Admin → TeamHub → License, and the
     * ladder gives them 24 days' notice from the first missed report. A
     * banner nobody looks at is not a warning.
     *
     * One notification per rung, debounced on (licence, state): climbing
     * due → overdue → locked notifies three times, because each rung is new
     * information and the last one says features have stopped. Dropping back
     * to `ok` — which one accepted report does — clears the marker, so a
     * later lapse warns again.
     */
    private function checkSeatReporting(array $status): void {
        $state     = (string)($status['reportState'] ?? 'not-required');
        $licenseId = (string)($status['licenseId'] ?? '');
        $flag      = $this->config->getAppValue(Application::APP_ID, self::CFG_REPORT_NOTIFIED, '');

        // A banded licence reports nothing and is warned about nothing; an
        // instance that has caught up has nothing outstanding. Both clear the
        // marker so the next lapse is announced from the bottom rung again.
        if ($state === 'not-required' || $state === 'ok') {
            if ($flag !== '') {
                $this->config->deleteAppValue(Application::APP_ID, self::CFG_REPORT_NOTIFIED);
            }
            return;
        }

        $marker = ($licenseId !== '' ? $licenseId : 'lic') . '@' . $state;
        if ($flag === $marker) {
            return;
        }

        $this->notifyReportAdmins(
            $state,
            (int)($status['reportOverdueDays'] ?? 0),
            isset($status['reportLockAt']) ? (int)$status['reportLockAt'] : 0,
            $licenseId,
        );

        // Recorded only after a successful notify, like checkExpiry(): a
        // failure inside notifyReportAdmins() falls through the outer
        // try/catch and is retried on the next tick with the marker unset.
        $this->config->setAppValue(Application::APP_ID, self::CFG_REPORT_NOTIFIED, $marker);
    }

    private function notifyReportAdmins(string $state, int $overdueDays, int $lockAt, string $licenseId): void {
        $adminGroup = $this->adminGroupOrLog();
        if ($adminGroup === null) return;

        // The state is in the object-id so each rung files as its own
        // notification rather than re-firing the previous one (NC
        // deduplicates on app + user + object + subject).
        $objectId = ($licenseId !== '' ? $licenseId : 'lic') . '@report-' . $state;

        foreach ($adminGroup->getUsers() as $admin) {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($admin->getUID())
                ->setDateTime(new \DateTime('@' . time()))
                ->setObject('license', $objectId)
                ->setSubject('license_report_overdue', [
                    'reportState'  => $state,
                    'overdueDays'  => $overdueDays,
                    'lockAt'       => $lockAt,
                ]);
            $this->notificationManager->notify($notification);
        }
    }

    private function notifyExpiryAdmins(bool $isTrial, int $daysRemaining, int $paidUntil, string $licenseId): void {
        $adminGroup = $this->adminGroupOrLog();
        if ($adminGroup === null) return;

        $subject = $isTrial ? 'license_expiring_trial' : 'license_expiring_paid';
        // The object-id must be unique per (license, expiry) so an
        // extended license produces a NEW notification (not a re-fire
        // of the old one). NC deduplicates on (app, user, object_type,
        // object_id, subject).
        $objectId = ($licenseId !== '' ? $licenseId : 'lic') . '@' . $paidUntil;

        foreach ($adminGroup->getUsers() as $admin) {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($admin->getUID())
                ->setDateTime(new \DateTime('@' . time()))
                ->setObject('license', $objectId)
                ->setSubject($subject, [
                    'daysRemaining' => $daysRemaining,
                    'paidUntil'     => $paidUntil,
                ]);
            $this->notificationManager->notify($notification);
        }
    }

    private function notifyOverSeatsAdmins(int $seatsUsed, int $seatCap, int $seatLockAt, string $licenseId): void {
        $adminGroup = $this->adminGroupOrLog();
        if ($adminGroup === null) return;

        // Object-id includes the licenseId + a marker so a subsequent
        // over-seats event after a license upgrade files as a distinct
        // notification (NC deduplicates on the object-id).
        $objectId = ($licenseId !== '' ? $licenseId : 'lic') . '@overseats';

        foreach ($adminGroup->getUsers() as $admin) {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($admin->getUID())
                ->setDateTime(new \DateTime('@' . time()))
                ->setObject('license', $objectId)
                ->setSubject('license_over_seats', [
                    'seatsUsed'  => $seatsUsed,
                    'seatCap'    => $seatCap,
                    'seatLockAt' => $seatLockAt,
                ]);
            $this->notificationManager->notify($notification);
        }
    }

    private function adminGroupOrLog(): ?\OCP\IGroup {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            // Should never happen on a real NC install — 'admin' is a
            // built-in group — but log and move on rather than crashing.
            $this->logger->warning(
                '[TeamHub] LicenseExpiryNotificationJob: admin group missing; no recipients',
                ['app' => Application::APP_ID],
            );
        }
        return $adminGroup;
    }
}
