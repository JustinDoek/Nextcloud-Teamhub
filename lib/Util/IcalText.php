<?php
declare(strict_types=1);

namespace OCA\TeamHub\Util;

/**
 * RFC 5545 §3.3.11 TEXT escaping, shared by every VEVENT writer in the app.
 *
 * One copy on purpose: ActivityService (4.9.10 and earlier) had its own
 * escaper that listed the backslash pair last, so "Plan, review" was
 * stored as `Plan\\, review` and shown as "Plan\, review" in the calendar.
 */
final class IcalText {

    /**
     * Escape a value for a TEXT property (SUMMARY, DESCRIPTION, LOCATION,
     * CATEGORIES, X-*).
     *
     * The backslash goes first: str_replace applies its pairs in order on
     * the changing string, so escaping it after the comma / semicolon /
     * newline pairs would double the backslashes those pairs just inserted.
     */
    public static function escape(string $text): string {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ',', ';'],
            ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'],
            $text,
        );
    }
}
