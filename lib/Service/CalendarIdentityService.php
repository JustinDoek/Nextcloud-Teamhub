<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Who a calendar address belongs to (v4.11.15).
 *
 * An ORGANIZER or ATTENDEE line names a person by *address*, and Nextcloud
 * writes three different addresses for the same user depending on which path
 * the object arrived by. `MeetingWorkProvider` worked that out for VEVENTs and
 * kept it to itself; tasks need the identical answer, and a second copy of
 * identity matching is the kind of duplication that drifts into two different
 * ideas of who you are. So it lives here, and the meeting provider asks too.
 *
 * ## Reading: three addresses, one person
 *
 * `addressesFor()` returns every form that means a given user — their email,
 * the synthetic `uid@host` that `ActivityService` falls back to when a profile
 * has no email, and the `principal:principals/users/uid` form the DAV layer
 * uses internally.
 *
 * ## Writing: an address plus the uid
 *
 * Address matching alone has a flaw that does not matter much for a meeting
 * and matters a great deal for a task: a meeting is over in an hour, while a
 * task can sit in a queue for weeks, and the day an administrator changes
 * somebody's email address every task assigned to them stops being theirs.
 * Nothing warns anybody; the rows simply become the team's.
 *
 * So TeamHub writes the uid alongside the address, as the `X-NC-USER-ID`
 * parameter on the ORGANIZER/ATTENDEE property. A custom `X-` parameter is
 * ordinary iCalendar (RFC 5545 §3.2), Sabre preserves it untouched, and other
 * clients ignore it — while `uidFromProperty()` reads it back exactly, with no
 * string matching at all. The `mailto:` address is still written, and still
 * matched, so a task assigned from Nextcloud Calendar or any other client is
 * read correctly too. The parameter is an optimisation on top of the standard,
 * never a replacement for it.
 */
class CalendarIdentityService {

    /** The parameter carrying the Nextcloud user id. See the class docblock. */
    public const UID_PARAM = 'X-NC-USER-ID';

    /** @var array<string,string[]> lower-cased addresses that mean one uid */
    private array $addressCache = [];

    private ?string $host = null;

    public function __construct(
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
    ) {
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * Every address that means this user, lower-cased and `mailto:`-stripped.
     *
     * @return string[]
     */
    public function addressesFor(string $userId): array {
        if (isset($this->addressCache[$userId])) {
            return $this->addressCache[$userId];
        }

        $addresses = [];
        $user      = $this->userManager->get($userId);

        if ($user !== null) {
            $email = (string)($user->getEMailAddress() ?? '');
            if ($email !== '') {
                $addresses[] = strtolower($email);
            }
        }

        // Both synthetic forms. The space-collapsed one is what `mailtoFor()`
        // writes today; the raw one is what everything written before v4.11.16
        // carries, and what `ActivityService` still writes for an event. A uid
        // with no whitespace produces the same string twice and dedupes below.
        $addresses[] = strtolower($userId . '@' . $this->host());
        $addresses[] = strtolower($this->localPart($userId) . '@' . $this->host());
        $addresses[] = strtolower('principal:principals/users/' . $userId);

        $this->addressCache[$userId] = array_values(array_unique($addresses));
        return $this->addressCache[$userId];
    }

    /**
     * Does this ORGANIZER/ATTENDEE property name the given user?
     *
     * The uid parameter decides it when present, because it cannot be wrong;
     * otherwise the address is compared against every form that means them.
     *
     * @param mixed $property a Sabre VObject property, or null
     */
    public function propertyIsUser(mixed $property, string $userId): bool {
        if ($property === null) {
            return false;
        }

        $stamped = $this->uidFromProperty($property);
        if ($stamped !== null) {
            return $stamped === $userId;
        }

        return in_array(
            self::normalise((string)$property),
            $this->addressesFor($userId),
            true,
        );
    }

    /**
     * The uid TeamHub stamped on this property, when it wrote it.
     *
     * @param mixed $property a Sabre VObject property, or null
     */
    public function uidFromProperty(mixed $property): ?string {
        if ($property === null) {
            return null;
        }
        try {
            $uid = (string)($property[self::UID_PARAM] ?? '');
        } catch (\Throwable) {
            return null;
        }
        return $uid !== '' ? $uid : null;
    }

    /** Lower-cased, `mailto:` stripped, trimmed. */
    public static function normalise(string $raw): string {
        return strtolower(preg_replace('/^mailto:/i', '', trim($raw)) ?? '');
    }

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * The `mailto:` value to write for this user: their own email when they
     * have one, else the synthetic `uid@host` the rest of the app falls back
     * to, so a user with no email address is still addressable.
     */
    public function mailtoFor(string $userId): string {
        $user  = $this->userManager->get($userId);
        $email = $user !== null ? (string)($user->getEMailAddress() ?? '') : '';
        if ($email === '') {
            // A Nextcloud uid may contain spaces ("Lieke Adm"), and a space in
            // a mailto: is a malformed address — observed on the test instance
            // as `mailto:Lieke Adm@claudius.tldr.host`. Nothing in TeamHub
            // minds, because the uid parameter is what matches, but it is a
            // broken address in a file other clients read. Collapsed to a dot,
            // the way a display name is usually turned into a local part.
            $email = $this->localPart($userId) . '@' . $this->host();
        }
        return 'mailto:' . $email;
    }

    /** A uid as the local part of an address: whitespace collapsed to a dot. */
    private function localPart(string $userId): string {
        return preg_replace('/\s+/', '.', trim($userId)) ?? $userId;
    }

    /** The display name to put in `CN`, falling back to the uid. */
    public function displayNameFor(string $userId): string {
        $user = $this->userManager->get($userId);
        $name = $user !== null ? trim((string)$user->getDisplayName()) : '';
        return $name !== '' ? $name : $userId;
    }

    /**
     * Parameters for an ORGANIZER/ATTENDEE property naming this user: the
     * display name, and the uid that makes the match exact.
     *
     * @return array<string,string>
     */
    public function propertyParamsFor(string $userId): array {
        return [
            'CN'             => $this->displayNameFor($userId),
            self::UID_PARAM  => $userId,
        ];
    }

    /**
     * The host used in the synthetic address. Same one `ActivityService` and
     * `MeetingWorkProvider` use, so an object either of them wrote matches.
     */
    private function host(): string {
        if ($this->host !== null) {
            return $this->host;
        }
        try {
            $this->host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST) ?: 'localhost';
        } catch (\Throwable) {
            $this->host = 'localhost';
        }
        return $this->host;
    }
}
