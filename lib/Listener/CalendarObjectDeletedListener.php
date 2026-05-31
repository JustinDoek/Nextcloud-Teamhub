<?php

declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\RoomVoxClient;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Fires when a calendar object is deleted (any path — web UI, CalDAV
 * client, mobile app, TeamHub itself). If the deleted event was a
 * TeamHub-created RoomVox booking, cancels the booking in RoomVox so the
 * room becomes free again.
 *
 * We tag every RoomVox-backed event with two X- properties in the ical:
 *   X-TEAMHUB-ROOMVOX-BOOKING-UID:<rvx-booking-uid>
 *   X-TEAMHUB-ROOMVOX-ROOM-ID:<rvx-room-id>
 * Their presence is the signal that the event was created via TeamHub
 * and HAS a corresponding RoomVox reservation to cancel. Events created
 * outside TeamHub (NC Calendar's own native booking) don't carry these
 * properties — their cancellation goes through RoomVox's own scheduling
 * plugin and we should not touch it.
 *
 * Listener is wired up in Application.php for both
 *   OCA\DAV\Events\CalendarObjectDeletedEvent
 *   OCA\DAV\Events\CalendarObjectMovedToTrashEvent
 * because NC moved deletions through a trash bin in recent versions —
 * if we only listened on "deleted" we'd miss the common case.
 *
 * @template-implements IEventListener<Event>
 */
class CalendarObjectDeletedListener implements IEventListener {

    public function __construct(
        private readonly RoomVoxClient   $roomvox,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        // We don't strictly type-hint on the event class because we wire
        // up two parallel events (delete + move-to-trash). Duck-type by
        // method presence instead.
        if (!method_exists($event, 'getObjectData')) {
            return;
        }
        try {
            /** @var array $objectData */
            $objectData = $event->getObjectData();
        } catch (\Throwable $e) {
            return;
        }
        if (!is_array($objectData)) {
            return;
        }

        $calendarData = (string)($objectData['calendardata'] ?? '');
        if ($calendarData === '') {
            return;
        }

        // Cheap pre-check: bail without parsing if the marker isn't even
        // present in the raw ical. Avoids loading Sabre VObject for the
        // (vastly majority) case of non-TeamHub events.
        if (strpos($calendarData, 'X-TEAMHUB-ROOMVOX-BOOKING-UID') === false) {
            return;
        }

        // Extract the two X- properties by regex. We deliberately don't
        // parse the full VObject here — it's an event listener that runs
        // on every calendar delete, performance matters, and regex is
        // sufficient for our own well-formed X- lines.
        $bookingUid = '';
        $roomId     = '';
        if (preg_match('/^X-TEAMHUB-ROOMVOX-BOOKING-UID:(.+)$/m', $calendarData, $m)) {
            $bookingUid = trim($m[1]);
        }
        if (preg_match('/^X-TEAMHUB-ROOMVOX-ROOM-ID:(.+)$/m', $calendarData, $m)) {
            $roomId = trim($m[1]);
        }

        if ($bookingUid === '' || $roomId === '') {
            $this->logger->debug('[TeamHub][CalendarObjectDeletedListener] X- markers present but incomplete; skipping', [
                'hasUid'  => $bookingUid !== '',
                'hasRoom' => $roomId !== '',
                'app'     => Application::APP_ID,
            ]);
            return;
        }

        // Fire the cancellation. The client logs success/failure and
        // returns bool; never throws upward — at this point the calendar
        // event is already gone and there's nothing to abort.
        $ok = $this->roomvox->cancelBooking($roomId, $bookingUid);
        $this->logger->info('[TeamHub][CalendarObjectDeletedListener] RoomVox cancellation attempted', [
            'roomId'     => $roomId,
            'bookingUid' => $bookingUid,
            'ok'         => $ok,
            'app'        => Application::APP_ID,
        ]);
    }
}
