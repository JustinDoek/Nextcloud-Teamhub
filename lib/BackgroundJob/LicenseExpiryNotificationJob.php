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
 * De-duplication per event:
 *   - Expiry:  marker = licenseId@paidUntil. Extending the license mints
 *              a new paidUntil, so the next approach fires its own single
 *              notification.
 *   - Seats:   marker = licenseId  (present while over, cleared when under).
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
