<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

/**
 * Read a `calendardata` value, whatever shape the database hands it back in
 * (v4.11.16).
 *
 * **Skipping this is a Postgres-only failure, and a silent one.**
 * `calendarobjects.calendardata` is `bytea` on Postgres and a text-ish
 * `LONGBLOB` on MySQL/MariaDB, and NC's QueryBuilder hands back a *stream
 * resource* for the former. Casting that with `(string)` yields
 * `"Resource id #1156"`, which Sabre rejects with *This parser only supports
 * VCARD and VCALENDAR files* — so every object on the calendar is discarded as
 * unparseable, on Postgres only, while MySQL works perfectly.
 *
 * What makes it nasty is that almost every caller wraps the parse in a
 * `try`/`catch` that skips the object — correctly, because one corrupt object
 * must not empty a whole view. The result is not an error anybody sees: it is
 * an empty task list, or a My Work source that shows nothing. Exactly the
 * shape HANDOFF *§0-pg-swallowed-failures* describes.
 *
 * **The trap is the cast, not the column.** Sabre's `MimeDir` parser takes a
 * readable stream as happily as a string, so a caller that passes the raw
 * value through has always worked — by accident, and without knowing it. A
 * caller that writes `(string)` first has never worked on Postgres. Three did:
 * `TaskWorkProvider` (found v4.11.16, when a task assigned to a colleague
 * reached neither person's My Work), `MeetingWorkProvider`, and
 * `TaskService::completeTeamTask()`. `CalendarService` hit it in v4.6.20 and
 * fixed it privately in a `readBlob()` it did not share.
 *
 * So this class is not only a fix — it is the removal of the trap. Every read
 * of `calendardata` goes through it, which means the next person to add a cast
 * has nowhere to add one, and the behaviour is the same on both databases
 * rather than the same by luck.
 *
 * Mirrors `CalDavBackend::readBlob()`, which exists in Nextcloud for this
 * reason and is private, so it cannot be reused.
 */
final class CalendarBlob {

    /**
     * @param mixed $data the raw column value: a string, a stream, or null
     */
    public static function read(mixed $data): string {
        if (is_resource($data)) {
            $contents = stream_get_contents($data);
            return $contents === false ? '' : $contents;
        }
        if ($data === null) {
            return '';
        }
        if (is_string($data)) {
            return $data;
        }

        // Anything else (an object with __toString, a number) is not calendar
        // data; returning '' makes the caller's "unparseable, skip it" path do
        // the right thing instead of handing Sabre a surprise.
        return is_scalar($data) ? (string)$data : '';
    }
}
