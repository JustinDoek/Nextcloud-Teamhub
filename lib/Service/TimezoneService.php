<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\IConfig;

/**
 * Single source of truth for "what time is it for this user".
 *
 * The problem this exists to solve: Nextcloud pins PHP to UTC
 * (`date_default_timezone_set('UTC')` in base.php), so every bare `date()`,
 * `strtotime()` and `new \DateTime()` in the app runs in UTC — which is not
 * where the user is. On an instance whose accounts are all Europe/Amsterdam
 * that is a two-hour skew in summer, so a bare `date('Y-m-d')` names the
 * wrong day between local 00:00 and 02:00, and a wall-clock time parsed
 * without a zone lands two hours late.
 *
 * This is not a new convention. DESIGN.md already states it for the feed
 * period ("'Today' means the viewer's today; the server's timezone is not
 * theirs") and for the My Work snooze presets, which are resolved by the
 * browser for exactly this reason. What was missing was one place to ask
 * the question server-side — so `userTimezone()` had been copy-pasted into
 * MeetingSuggestionService and TimeslotSuggestionService and used nowhere
 * else. Both now delegate here.
 *
 * Where the zone comes from, in order:
 *   1. The user's own `core`/`timezone` preference. Nextcloud writes this
 *      from the browser on login, so it is populated for any account that
 *      has actually signed in.
 *   2. The instance's `default_timezone` system value, for accounts that
 *      have never logged in (cron, background jobs acting for someone).
 *   3. UTC, if the stored value is not a zone PHP recognises.
 *
 * What this class is deliberately NOT for:
 *   - iCalendar DTSTAMP, which RFC 5545 requires in UTC — keep `gmdate()`.
 *   - `circles_member.joined`, which must match what Circles itself writes.
 *   - Collision-avoidance filename prefixes, where only uniqueness matters.
 */
class TimezoneService {

	/**
	 * Memo per uid. Resolution is two config reads, and the suggestion
	 * services ask once per attendee inside a scoring loop; the request is
	 * short enough that a stale entry is not a concern.
	 *
	 * @var array<string, \DateTimeZone>
	 */
	private array $cache = [];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * The user's timezone, never null — falls back rather than throwing,
	 * because no caller has anything useful to do with the failure and
	 * every one of them would otherwise need the same try/catch.
	 */
	public function forUser(string $uid): \DateTimeZone {
		if (isset($this->cache[$uid])) {
			return $this->cache[$uid];
		}

		$tz = $uid !== ''
			? (string)$this->config->getUserValue($uid, 'core', 'timezone', '')
			: '';

		if ($tz === '') {
			// Matches the codebase convention of getSystemValue (the typed
			// getSystemValueString variant is not used elsewhere in TeamHub).
			$tz = (string)$this->config->getSystemValue('default_timezone', date_default_timezone_get());
		}

		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Throwable $e) {
			$zone = new \DateTimeZone('UTC');
		}

		return $this->cache[$uid] = $zone;
	}

	/**
	 * The calendar date it is *for this user* right now, ISO YYYY-MM-DD.
	 * Use this anywhere `date('Y-m-d')` meant "today".
	 */
	public function today(string $uid, ?int $now = null): string {
		return $this->formatTimestamp($now ?? time(), $uid, 'Y-m-d');
	}

	/**
	 * Render a stored instant (unix seconds) in the user's zone.
	 *
	 * Use for anything a person reads. Note that `date($fmt, $ts)` is the
	 * UTC-bound version of this call, not a shorter one.
	 */
	public function formatTimestamp(int $ts, string $uid, string $format = 'Y-m-d'): string {
		return (new \DateTimeImmutable('@' . $ts))
			->setTimezone($this->forUser($uid))
			->format($format);
	}

	/**
	 * Wall-clock date + time as the user meant it → absolute instant.
	 *
	 * `$date` is ISO YYYY-MM-DD and `$time` is HH:MM, both exactly as typed
	 * into the form. The whole point is the third argument: without a zone,
	 * PHP reads "14:00" as 14:00 UTC and the user's 14:00 meeting is stored
	 * two hours late.
	 *
	 * @throws \Exception if the date/time strings are not parseable — callers
	 *                    validate the shape first, so this is a bug, not input.
	 */
	public function wallClockToTimestamp(string $date, string $time, string $uid): int {
		return (new \DateTimeImmutable($date . ' ' . $time, $this->forUser($uid)))
			->getTimestamp();
	}

	/**
	 * Half-open [start, end) instants spanning the user's local calendar day.
	 *
	 * Built by adding one day rather than assuming 86400 seconds, because a
	 * DST transition makes the local day 23 or 25 hours long.
	 *
	 * @return array{0: int, 1: int}
	 * @throws \Exception if $isoDate is not parseable.
	 */
	public function dayBounds(string $isoDate, string $uid): array {
		$start = new \DateTimeImmutable($isoDate . ' 00:00:00', $this->forUser($uid));
		return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
	}
}
