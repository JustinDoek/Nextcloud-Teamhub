<?php
declare(strict_types=1);

namespace OCA\TeamHub\Mentions;

/**
 * One definition of "what a mention looks like" (v4.5.33).
 *
 * Lifted out of `MessageService` once Talk chat messages became a second thing
 * that has to be scanned for them. The PHP mirror of `src/lib/mentions.js` —
 * change one, change the other, or the Mentions tab and the Mentions count will
 * disagree about the same message.
 *
 * **The grammar is Nextcloud's own**, copied from `@nextcloud/vue`'s
 * `mixins/richEditor/index.js` rather than invented here, because that is the
 * code that *writes* mentions:
 *
 *   MENTION_START  = /(?=[a-z0-9_\-@.'])\B/
 *   MENTION_SIMPLE = /(@[a-z0-9_\-@.']+)/
 *
 * Three things it gets right that hand-rolled patterns did not, each of which
 * cost a round of this session:
 *
 *  - **`@` is inside the character class.** On an instance whose usernames are
 *    e-mail addresses, `@JDoek@aaenhunze.nl` is a single token. A
 *    `[a-zA-Z0-9._-]+` class stops at the second `@` and matches nobody.
 *  - **The class stops at `/` and `)`.** `[^\s"]+` ran to the next space, so a
 *    link containing an `@` parsed as a mention of
 *    `example.org/Folder/file.md)`.
 *  - **`\B` refuses a mid-word `@`.** That is what keeps a plain e-mail address
 *    written in a sentence from reading as a mention of its domain: the
 *    position before the `@` in `mail jdoek@example.com` sits between a word
 *    character and a non-word one, which is a word boundary.
 */
final class MentionParser {

    /**
     * Trailing characters that may end a bare mention rather than belong to it.
     *
     * `.` is in here *and* is legal inside a uid, which is why candidates()
     * offers progressively shorter readings instead of picking one: `@jdoek.`
     * ending a sentence and `@jdoek.nl` as a whole uid are indistinguishable
     * without knowing which uid you are looking for. The caller compares
     * against a known set, so offering both is safe and dropping either is not.
     */
    private const TRAILING = '.,;:!?)]}>\'';

    /** @see the class docblock — this is Nextcloud's, not ours. */
    private const BARE = '/(?=[a-z0-9_\-@.\'])\B@([a-z0-9_\-@.\']+)/i';

    /** The quoted form, which NcRichContenteditable writes for ids containing a space, colon or slash. */
    private const QUOTED = '/@"([^"]+)"/';

    /**
     * Every uid a body could be mentioning.
     *
     * @return string[] deduplicated, in order of appearance
     */
    public static function candidates(string $body): array {
        if ($body === '') {
            return [];
        }

        $out = [];

        // Quoted form first, and its matches are removed from the body so the
        // bare pass cannot re-read the same mention without its quotes.
        if (preg_match_all(self::QUOTED, $body, $quoted)) {
            foreach ($quoted[1] as $id) {
                $out[$id] = true;
            }
            $body = preg_replace(self::QUOTED, ' ', $body) ?? $body;
        }

        if (preg_match_all(self::BARE, $body, $bare)) {
            foreach ($bare[1] as $token) {
                $candidate = $token;
                while ($candidate !== '') {
                    $out[$candidate] = true;
                    if (strpos(self::TRAILING, substr($candidate, -1)) === false) {
                        break;
                    }
                    $candidate = substr($candidate, 0, -1);
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Does $body mention $uid?
     *
     * **Case-insensitive on purpose.** The id written into a body comes from
     * the mention picker, fed from `circles_member.user_id`; the id compared
     * against comes from Nextcloud's session. Those are not guaranteed to agree
     * on case, and two uids differing only in case are never two different
     * people on an instance where both can exist.
     */
    public static function mentions(string $body, string $uid): bool {
        if ($body === '' || $uid === '') {
            return false;
        }
        $needle = mb_strtolower($uid);
        foreach (self::candidates($body) as $candidate) {
            if (mb_strtolower($candidate) === $needle) {
                return true;
            }
        }
        return false;
    }
}
