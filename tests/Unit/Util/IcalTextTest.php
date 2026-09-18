<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Util;

use OCA\TeamHub\Util\IcalText;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5545 §3.3.11 TEXT escaping (v4.9.11), shared by ActivityService and
 * OpenProjectMeetingMirrorService. Pins the order bug that shipped in
 * ActivityService up to 4.9.10: with the backslash pair applied last, a
 * comma became `\\,` and the calendar showed "Plan\, review".
 */
class IcalTextTest extends TestCase {

    public function testACommaIsEscapedOnceNotTwice(): void {
        $this->assertSame('Plan\\, review', IcalText::escape('Plan, review'));
        $this->assertStringNotContainsString('\\\\,', IcalText::escape('Plan, review'), 'the 4.9.10 double-escape');
    }

    public function testASemicolonIsEscapedOnce(): void {
        $this->assertSame('Plan\\; review', IcalText::escape('Plan; review'));
    }

    public function testEveryLineBreakFlavourBecomesABackslashN(): void {
        $this->assertSame('a\\nb\\nc\\nd', IcalText::escape("a\r\nb\nc\rd"));
        $this->assertStringNotContainsString('\\\\n', IcalText::escape("a\nb"), 'the 4.9.10 double-escape');
    }

    public function testALiteralBackslashIsDoubled(): void {
        $this->assertSame('C:\\\\Users', IcalText::escape('C:\\Users'));
    }

    public function testABackslashNextToACommaIsEscapedIndependently(): void {
        // "\," in the input is a backslash followed by a comma: two
        // characters, two escapes — `\\` then `\,` — never a fused `\\,`.
        $this->assertSame('a\\\\\\,b', IcalText::escape('a\\,b'));
    }

    public function testTheRfcExampleSurvives(): void {
        // RFC 5545 §3.3.11 example, verbatim.
        $this->assertSame(
            'Project XYZ Final Review\\nConference Room - 3B\\nCome Prepared.',
            IcalText::escape("Project XYZ Final Review\nConference Room - 3B\nCome Prepared."),
        );
    }

    public function testTextWithoutSpecialsIsUntouched(): void {
        $this->assertSame('Weekly stand-up 09:30', IcalText::escape('Weekly stand-up 09:30'));
        $this->assertSame('', IcalText::escape(''));
    }

    public function testColonAndQuotesAreNotEscaped(): void {
        // Only backslash, semicolon, comma and newline are escaped in TEXT
        // values; a colon or double quote passes through (RFC 5545 §3.3.11).
        $this->assertSame('Re: "Budget"', IcalText::escape('Re: "Budget"'));
    }
}
