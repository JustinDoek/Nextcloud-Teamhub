<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

/**
 * Circles' member ids, and the rule TeamHub spent four writes breaking
 * (v4.14.7, DESIGN §2.249).
 *
 * `circles_member.member_id` is the member row's own identity — not the uid,
 * not the single id. Circles generates a 31-character token for it and
 * validates every write in `CoreRequestBuilder::confirmValidId()`:
 *
 * ```php
 * if (strlen($id) < 14) {
 *     throw new InvalidIdException();
 * }
 * ```
 *
 * `MemberRequest::save()` and `::update()` both run that over
 * `[circleId, singleId, id]` before any SQL, so Circles itself can never
 * store a short one.
 *
 * TeamHub could, because four of its paths insert `circles_member` rows by
 * raw SQL and set `member_id` to the bare uid — a convention copied from
 * `MemberService::requestJoinTeam()` into `assignOwner()`,
 * `deleteOrphanedTeam()` and (by omission) `repairMissingOwner()`. Those
 * writes bypass `MemberRequest`, so nothing complained and nothing broke
 * locally.
 *
 * **It breaks across federation.** A remote instance receiving an event for
 * the team runs `RemoteDownstreamService::verifyCircle()` →
 * `RemoteService::syncRemoteCircle()`, which does:
 *
 * ```php
 * $this->circleRequest->insertOrUpdate($circle);
 * $this->memberRequest->insertOrUpdate($circle->getOwner());   // not guarded
 * $this->syncRemoteMembers($circle);                           // catches per member
 * ```
 *
 * The owner call is the one with no `catch (InvalidIdException)`. A uid
 * shorter than 14 characters therefore throws there, escapes into
 * `verifyCircle()`'s bare `catch (Exception) { return false; }`, and the
 * remote answers the event with `FederatedEventDSyncException` 409 *"Could
 * not verify Team"* — with the real exception discarded and never logged.
 * Diagnosed against a live pair of instances on 2026-11-09: a team whose
 * owner row carried `member_id = 'Lieke Adm'` (9 characters) could not
 * federate, while one created through `CirclesManager::createCircle()` in the
 * same session could.
 *
 * So: never the uid. Use {@see generate()} for a new row and
 * {@see isValid()} to recognise a damaged one.
 *
 * **Not the single id either.** It is tempting, since the row already has a
 * valid 31-character one to hand, and it would pass. Circles keeps them
 * distinct — a healthy owner row reads `member_id = 4rUIEGZrTQPAOk9IIX92SdgpRCbUdY9`
 * against `single_id = Ew5DvhTXImquFX5KFcGVQtZSTelj6EJ` — and `member_id` is a
 * lookup key in its own right (`MemberRequest::getMemberById()`,
 * `::updateLevel()`), so collapsing the two would make one row answer to both.
 * Reusing a value that happens to validate is exactly how `member_id = $uid`
 * became a convention.
 *
 * **A repair must carry `circles_token` with it.** `circles_token.member_id`
 * stores a copy of this value and is part of that table's unique index
 * `sicisimit` (`share_id, circle_id, single_id, member_id, token`). There is
 * no foreign key, so the database will not complain — the rows simply stop
 * matching. Any code that rewrites a `member_id` has to update the token rows
 * that referenced the old one; see
 * `MaintenanceService::repairInvalidMemberIds()`.
 *
 * Static, like {@see AuditHash}: both a service and a migration-era repair
 * need it, and neither should have to own the other.
 */
final class CirclesMemberId {

    /**
     * Circles' own threshold, from `CoreRequestBuilder::confirmValidId()`.
     * A minimum, not a length — there is no upper bound and no pattern check.
     */
    public const MIN_LENGTH = 14;

    /**
     * What Circles actually generates, and the width of the column
     * (`circles_member.member_id` and `circles_token.member_id` are both
     * `varchar(31)`). Generating anything longer would be silently truncated.
     */
    public const LENGTH = 31;

    /** Mixed-case alphanumerics, matching the shape of Circles' own tokens. */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * A fresh member id: 31 characters, always valid.
     *
     * `random_int()` rather than `rand()` — this is an identifier that ends up
     * in a unique index, and the cost of a CSPRNG here is irrelevant next to
     * the insert it accompanies.
     */
    public static function generate(): string {
        $alphabet = self::ALPHABET;
        $max      = strlen($alphabet) - 1;
        $id       = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $id .= $alphabet[random_int(0, $max)];
        }

        return $id;
    }

    /**
     * Would Circles accept this id?
     *
     * Null and the empty string are the `repairMissingOwner()` case — it never
     * set the column at all — and both are invalid, which is the answer the
     * caller wants either way.
     */
    public static function isValid(?string $id): bool {
        return $id !== null && strlen($id) >= self::MIN_LENGTH;
    }
}
