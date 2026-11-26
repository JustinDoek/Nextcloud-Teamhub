<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

/**
 * Reading and writing a meeting's notes file for the wrap-up (v4.20.19,
 * DESIGN §2.271). Pure and static: no Nextcloud, no strings to translate.
 *
 * The notes file is the Markdown `MeetingService` writes from its template:
 * a `**Date:**` line, `## Agenda`, an optional `## Proposals` list whose
 * links carry `&decision={id}`, `## Notes` and `## Action items`. Somebody
 * may have filled it in during the meeting, or nobody did; both are normal,
 * so every reader here answers "nothing found" rather than failing.
 *
 * The wrap-up writes its outcome back between two HTML comments, so a second
 * wrap-up (or a re-read) recognises its own block, replaces it instead of
 * appending another, and never reads its own output as action items.
 */
final class MeetingNotes {

    public const BLOCK_START = '<!-- teamhub:wrap-up -->';
    public const BLOCK_END   = '<!-- /teamhub:wrap-up -->';

    /** Headings, lower-cased, under which the action items are kept. */
    private const ACTION_HEADINGS = ['action items', 'actions', 'action points', 'to do', 'todo', 'actiepunten', 'acties'];

    /** Words before a due date that go with it: "by 20-11-2026". */
    private const DUE_WORDS = 'by|before|due|until|voor|uiterlijk|op|bis|avant|antes|entro|inden';

    /**
     * @return array{date: ?string, actionItems: list<string>, decisionIds: list<int>}
     */
    public static function parse(string $content): array {
        $content = self::withoutBlock(str_replace(["\r\n", "\r"], "\n", $content));

        $date = null;
        if (preg_match('/\*\*Date:\*\*\s*(\d{4}-\d{2}-\d{2})/', $content, $m)) {
            $date = $m[1];
        }

        $items = [];
        $inActions = false;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^#{1,6}\s+(.*?)\s*#*\s*$/', $line, $h)) {
                $inActions = in_array(mb_strtolower(trim($h[1])), self::ACTION_HEADINGS, true);
                continue;
            }
            if (!$inActions) {
                continue;
            }
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?(.+?)\s*$/', $line, $li)) {
                $text = trim($li[1]);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        $ids = [];
        if (preg_match_all('/[?&]decision=(\d+)/', $content, $dm)) {
            foreach ($dm[1] as $id) {
                $ids[(int)$id] = true;
            }
        }

        return ['date' => $date, 'actionItems' => $items, 'decisionIds' => array_keys($ids)];
    }

    /**
     * One action item, as somebody typed it, into what, who and by when.
     *
     * Who: `@uid`, a leading `Name:` or a trailing `(Name)`, matched against
     * the team's members by user id or display name, ignoring case. When
     * nothing matches, the text is left as it is and nobody is guessed.
     * When: an ISO date (2026-11-20) or a day-month-year date (20-11-2026,
     * 20/11/2026, 20.11.2026), with a word like "by" before it.
     *
     * @param list<array{userId: string, displayName: string}> $members
     * @return array{title: string, assignee: ?string, due: ?string}
     */
    public static function parseActionItem(string $text, array $members): array {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $due = null;

        $duePattern = '/(?:\b(?:' . self::DUE_WORDS . ')\s+)?(?:(\d{4})-(\d{2})-(\d{2})|(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4}))\b/iu';
        if (preg_match($duePattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            [$y, $mo, $d] = $m[1][0] !== ''
                ? [(int)$m[1][0], (int)$m[2][0], (int)$m[3][0]]
                : [(int)$m[6][0], (int)$m[5][0], (int)$m[4][0]];
            if (checkdate($mo, $d, $y)) {
                $due = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                $text = substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0]));
            }
        }

        $assignee = null;
        $byKey = [];
        foreach ($members as $member) {
            $byKey[mb_strtolower($member['userId'])] = $member['userId'];
            if ($member['displayName'] !== '') {
                $byKey[mb_strtolower($member['displayName'])] ??= $member['userId'];
            }
        }
        $match = static fn (string $who): ?string => $byKey[mb_strtolower(trim($who))] ?? null;

        if (preg_match('/@([^\s,;:()]+)/u', $text, $m, PREG_OFFSET_CAPTURE) && ($uid = $match($m[1][0])) !== null) {
            $assignee = $uid;
            $text = substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0]));
        } elseif (preg_match('/^([^:]{1,64}):\s*(.+)$/u', trim($text), $m) && ($uid = $match($m[1])) !== null) {
            $assignee = $uid;
            $text = $m[2];
        } elseif (preg_match('/\(([^()]{1,64})\)\s*$/u', trim($text), $m, PREG_OFFSET_CAPTURE) && ($uid = $match($m[1][0])) !== null) {
            $assignee = $uid;
            $text = substr(trim($text), 0, $m[0][1]);
        }

        $title = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $title = trim(preg_replace('/^[\s,;:\-]+|[\s,;:\-()]+$/u', '', $title) ?? $title);

        return ['title' => $title, 'assignee' => $assignee, 'due' => $due];
    }

    /**
     * The file name `MeetingService` gives a notes file it names itself.
     * The wrap-up uses it to find a later date's notes of a repeating meeting.
     */
    public static function defaultFilename(string $title, string $date): string {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? 'meeting');
        $slug = trim($slug, '-');
        return $date . '-' . substr($slug, 0, 60);
    }

    /**
     * Put the wrap-up block in the notes: in place of an earlier one, or at
     * the end.
     *
     * @param string $block Markdown without the markers
     */
    public static function withBlock(string $content, string $block): string {
        $wrapped = self::BLOCK_START . "\n" . rtrim($block) . "\n" . self::BLOCK_END . "\n";
        $start = strpos($content, self::BLOCK_START);
        $end = $start === false ? false : strpos($content, self::BLOCK_END, $start);
        if ($start !== false && $end !== false) {
            return substr($content, 0, $start) . $wrapped . ltrim(substr($content, $end + strlen(self::BLOCK_END)), "\n");
        }
        return rtrim($content) . "\n\n" . $wrapped;
    }

    /** Escape what would break a Markdown link label or list line. */
    public static function escape(string $s): string {
        return preg_replace('/([\\\\`*_{}\[\]()#+!|])/', '\\\\$1', $s) ?? $s;
    }

    private static function withoutBlock(string $content): string {
        $start = strpos($content, self::BLOCK_START);
        if ($start === false) {
            return $content;
        }
        $end = strpos($content, self::BLOCK_END, $start);
        return $end === false
            ? substr($content, 0, $start)
            : substr($content, 0, $start) . substr($content, $end + strlen(self::BLOCK_END));
    }
}
