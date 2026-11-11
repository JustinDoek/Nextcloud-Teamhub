<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

use OCP\IConfig;

/**
 * The keyed digests behind the tamper-evident audit trail (v4.13.17,
 * DESIGN §2.191).
 *
 * A pure helper with static methods, for the reason {@see ReviewSchedule} is
 * one: `AuditLogMapper` hashes a row as it inserts it and
 * `AuditIntegrityService` re-hashes the same row to verify it, and a mapper
 * must not depend on a service. Both call these.
 *
 * ## Why keyed, and which key
 *
 * An unkeyed hash makes an edit detectable only by somebody who does not have
 * what the attacker has. If the digest is a plain `sha256` of the row, then
 * anybody who can write the row can recompute the digest, rewrite it, and walk
 * forward recomputing every anchor — the chain proves nothing against the one
 * attacker it exists for.
 *
 * So the digests are `hash_hmac('sha256', …, $key)` where `$key` is derived
 * from Nextcloud's **`secret` in `config.php`** — the one piece of instance
 * state that is deliberately *not* in the database. An attacker with SQL write
 * access alone cannot forge an anchor. One with the config file as well can,
 * and no scheme implemented inside the application could stop them: at that
 * point they can also change the code that verifies. This is tamper-**evident**,
 * not tamper-proof, and the integrity report says exactly that.
 *
 * ## Key rotation is diagnosable, not silent
 *
 * Rotate `secret` and every existing digest stops matching. That must not read
 * as tampering, so each anchor records {@see fingerprint()} — a short digest of
 * a fixed string under the same key — and the verifier reports
 * *signed with a different key* for an anchor whose fingerprint does not match
 * the current one. An instance that rotates its secret loses the ability to
 * verify what came before, which is a fact about key rotation rather than a
 * defect, and the report names it.
 *
 * ## No secret at all
 *
 * `secret` is written by Nextcloud's own installer, so it is effectively always
 * present. If it is somehow empty, {@see keyFrom()} returns `''` and
 * {@see isAvailable()} is false: rows are then written with **no** `row_hash`
 * rather than with a digest keyed on nothing, which would look like protection
 * and be none.
 */
final class AuditHash {

    /**
     * Domain separator. Mixed into the key so the audit digests cannot be
     * replayed against anything else on this instance that also keys on
     * `secret`.
     */
    private const DOMAIN = 'teamhub:audit:v1';

    /** The field separator inside a canonical row string. ASCII unit separator. */
    private const SEP = "\x1f";

    /** The instance's signing key, or '' when there is none. */
    public static function keyFrom(IConfig $config): string {
        $secret = (string)$config->getSystemValue('secret', '');
        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', self::DOMAIN, $secret);
    }

    public static function isAvailable(string $key): bool {
        return $key !== '';
    }

    /**
     * The digest of one audit row's content.
     *
     * **`id` is deliberately not part of it.** The id is assigned by the
     * database as the row is inserted, so hashing it would need a second write
     * to the row that was just written — an update path on a table whose whole
     * contract is that it has none. Position is bound by the anchor instead: an
     * anchor hashes its range's row digests **in id order**, so moving a row
     * breaks the anchor even though the row's own digest still matches.
     *
     * `$metadataJson` is the exact string stored in the column, not a re-encode
     * of the array: a verifier must hash the bytes that are there, and two
     * `json_encode()` calls on equal arrays are only equal by convention.
     */
    public static function rowHash(
        string  $key,
        string  $teamId,
        string  $eventType,
        ?string $actorUid,
        ?string $targetType,
        ?string $targetId,
        ?string $metadataJson,
        int     $createdAt,
    ): ?string {
        if (!self::isAvailable($key)) {
            return null;
        }

        $canonical = implode(self::SEP, [
            $teamId,
            $eventType,
            $actorUid ?? '',
            $targetType ?? '',
            $targetId ?? '',
            $metadataJson ?? '',
            (string)$createdAt,
        ]);

        return hash_hmac('sha256', $canonical, $key);
    }

    /**
     * The digest over a range's row digests, in id order.
     *
     * The row **count** is mixed in as well as the digests themselves. Hashing
     * only the concatenation would let a deletion at the end of a range pass as
     * a shorter range, and the count is what makes "this range used to hold
     * eleven rows and now holds ten" a provable statement rather than an
     * observation about a number stored beside it.
     *
     * @param list<string> $rowHashes in id order; a NULL row_hash is passed as ''
     */
    public static function rowsHash(string $key, int $fromId, int $toId, array $rowHashes): string {
        $canonical = implode(self::SEP, [
            (string)$fromId,
            (string)$toId,
            (string)count($rowHashes),
            implode('', $rowHashes),
        ]);

        return hash_hmac('sha256', $canonical, $key);
    }

    /** This anchor's link: the previous anchor's chain digest plus this range's. */
    public static function chainHash(string $key, string $previousChainHash, string $rowsHash): string {
        return hash_hmac('sha256', $previousChainHash . self::SEP . $rowsHash, $key);
    }

    /**
     * A short, non-secret marker of which key signed an anchor. Sixteen hex
     * characters of a digest of a constant: enough to tell two keys apart,
     * and it discloses nothing about either.
     */
    public static function fingerprint(string $key): string {
        if (!self::isAvailable($key)) {
            return '';
        }

        return substr(hash_hmac('sha256', 'fingerprint', $key), 0, 16);
    }
}
