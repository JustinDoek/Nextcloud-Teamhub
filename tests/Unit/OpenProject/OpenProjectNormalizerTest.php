<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Service\OpenProject\OpenProjectNormalizer;

/**
 * Raw OpenProject HAL → TeamHub arrays. Pure functions, recorded inputs.
 */
class OpenProjectNormalizerTest extends OpenProjectTestCase {

    public function testProjectMapsFieldsAndStripsMarkup(): void {
        $p = OpenProjectNormalizer::project(self::projectResponse());

        $this->assertSame(12, $p['id']);
        $this->assertSame('demo-project', $p['identifier']);
        $this->assertSame('Demo project', $p['name'], 'HTML is stripped from the name');
        $this->assertSame(['code' => 'on_track', 'label' => 'On track'], $p['status']);
        $this->assertSame('Heading Some bold text with a link.', $p['description'], 'Markdown decoration and link targets are gone');
        $this->assertSame('All good', $p['statusExplanation']);
        $this->assertTrue($p['canCreateWorkPackage']);
        $this->assertSame('2026-01-02T03:04:05Z', $p['createdAt']);
    }

    public function testProjectWithoutStatusOrCreateLinkReportsNullAndFalse(): void {
        $raw = self::projectResponse(canCreate: false);
        unset($raw['_links']['status']);
        $raw['description'] = null;

        $p = OpenProjectNormalizer::project($raw);

        $this->assertNull($p['status'], 'a missing status is null, not a guess');
        $this->assertNull($p['description']);
        $this->assertFalse($p['canCreateWorkPackage']);
    }

    public function testLinkableIsAdministeredOrPublic(): void {
        $admin   = OpenProjectNormalizer::project(self::projectResponse(canEdit: true, public: false));
        $public  = OpenProjectNormalizer::project(self::projectResponse(canEdit: false, public: true));
        $viewer  = OpenProjectNormalizer::project(self::projectResponse(canEdit: false, public: false));

        $this->assertTrue($admin['canEditProject']);
        $this->assertTrue($admin['linkable'], 'administered: linkable');
        $this->assertFalse($public['canEditProject']);
        $this->assertTrue($public['linkable'], 'public: linkable without administering');
        $this->assertFalse($viewer['canEditProject']);
        $this->assertFalse($viewer['linkable'], 'merely visible: not linkable');

        $summary = OpenProjectNormalizer::projectSummary(self::projectResponse(canEdit: false, public: false));
        $this->assertFalse($summary['linkable']);
    }

    public function testInvalidIdentifierIsNeverPassedThrough(): void {
        $raw = self::projectResponse(identifier: '../../etc');
        $this->assertSame('', OpenProjectNormalizer::project($raw)['identifier']);
        $this->assertSame('', OpenProjectNormalizer::identifier('Has Spaces'));
        $this->assertSame('ok_slug-1', OpenProjectNormalizer::identifier('ok_slug-1'));
    }

    public function testWorkPackageMapsLinksToPlainFields(): void {
        $wp = OpenProjectNormalizer::workPackage(self::workPackageResponse(77, [
            'subject' => 'Fix <script>alert(1)</script> login',
        ]));

        $this->assertSame(77, $wp['id']);
        $this->assertSame('Fix alert(1) login', $wp['subject']);
        $this->assertSame('Task', $wp['type']);
        $this->assertSame('In progress', $wp['status']);
        $this->assertSame('Normal', $wp['priority']);
        $this->assertSame('Alice Example', $wp['assignee']);
        $this->assertSame(['id' => 12, 'name' => 'Demo project'], $wp['project']);
        $this->assertSame('2026-09-20', $wp['dueDate']);
        $this->assertNull($wp['startDate']);
        $this->assertSame(40, $wp['percentageDone']);
        $this->assertArrayNotHasKey('_links', $wp, 'no HAL reaches the frontend');
    }

    public function testWorkPackageCarriesTheIdsBehindItsLinksAndItsAuthor(): void {
        $wp = OpenProjectNormalizer::workPackage(self::workPackageResponse(1, [
            '_links' => ['author' => ['href' => '/api/v3/users/9', 'title' => 'Carol']],
        ]));
        $this->assertSame(1, $wp['typeId']);
        $this->assertSame(7, $wp['statusId']);
        $this->assertSame(8, $wp['priorityId']);
        $this->assertSame(3, $wp['assigneeId']);
        $this->assertSame('Carol', $wp['author']);
    }

    public function testNewsIsPlainTextWithASummaryAndAnExcerpt(): void {
        $n = OpenProjectNormalizer::news([
            '_type' => 'News', 'id' => 5, 'title' => 'Release <b>1.0</b> shipped',
            'summary' => 'We **did** it',
            'description' => ['format' => 'markdown', 'raw' => "# Big
Lots of [detail](https://x.test) here.", 'html' => '<h1>x</h1>'],
            'createdAt' => '2026-09-14T08:00:00Z',
            '_links' => [
                'author'  => ['href' => '/api/v3/users/9', 'title' => 'Carol <i>C</i>'],
                'project' => ['href' => '/api/v3/projects/12', 'title' => 'Demo'],
            ],
        ]);
        $this->assertSame(5, $n['id']);
        $this->assertSame('Release 1.0 shipped', $n['title']);
        $this->assertSame('We did it', $n['summary']);
        $this->assertSame('Big Lots of detail here.', $n['excerpt']);
        $this->assertSame('Carol C', $n['author']);
        $this->assertSame(9, $n['authorId']);
        $this->assertSame(['id' => 12, 'name' => 'Demo'], $n['project']);
        $this->assertNull(OpenProjectNormalizer::news(['id' => 1, 'title' => 'x'])['summary']);
    }

    public function testMeetingCarriesTimesDurationAndAValidatedState(): void {
        $m = OpenProjectNormalizer::meeting([
            '_type' => 'Meeting', 'id' => 3, 'title' => 'Sprint <script>x</script>review', 'location' => 'Room <b>1</b>',
            'startTime' => '2026-09-15T09:00:00Z', 'endTime' => null, 'duration' => 'PT1H30M', 'state' => 'open',
            '_links' => ['author' => ['href' => '/api/v3/users/9', 'title' => 'Carol'], 'project' => ['href' => '/api/v3/projects/12', 'title' => 'Demo']],
        ]);
        $this->assertSame('Sprint xreview', $m['title']);
        $this->assertSame('Room 1', $m['location']);
        $this->assertSame('2026-09-15T09:00:00Z', $m['startTime']);
        $this->assertNull($m['endTime']);
        $this->assertSame(1.5, $m['durationHours']);
        $this->assertSame('open', $m['state']);
        $this->assertSame(['id' => 12, 'name' => 'Demo'], $m['project']);
        $this->assertNull(OpenProjectNormalizer::meeting(['state' => 'DROP TABLE'])['state'], 'an unknown state is null');
        $this->assertNull(OpenProjectNormalizer::meeting(['duration' => 'nonsense'])['durationHours']);
    }

    public function testWorkPackageWithUnassignedLinkHasNullAssignee(): void {
        $wp = OpenProjectNormalizer::workPackage(self::workPackageResponse(1, [
            '_links' => ['assignee' => ['href' => null]],
            'dueDate' => 'not-a-date',
        ]));
        $this->assertNull($wp['assignee']);
        $this->assertNull($wp['dueDate'], 'a malformed date is null, never passed through');
    }

    public function testCollectionRequiresEmbeddedElements(): void {
        $this->assertNull(OpenProjectNormalizer::collection(['_type' => 'Error']));
        $c = OpenProjectNormalizer::collection(self::collectionResponse([self::workPackageResponse(1), 'junk'], 42));
        $this->assertSame(42, $c['total']);
        $this->assertCount(1, $c['elements'], 'non-array elements are dropped');
    }

    public function testExcerptCutsOnAWordBoundary(): void {
        $long = str_repeat('word ', 100);
        $e = OpenProjectNormalizer::excerpt($long, 50);
        $this->assertLessThanOrEqual(51, mb_strlen($e));
        $this->assertStringEndsWith('…', $e);
        $this->assertStringNotContainsString('wor…', $e, 'no cut inside a word');
        $this->assertNull(OpenProjectNormalizer::excerpt('   '));
        $this->assertSame('short', OpenProjectNormalizer::excerpt('short'));
    }

    public function testLinkHelpers(): void {
        $raw = ['_links' => ['project' => ['href' => '/api/v3/projects/12/', 'title' => 'P']]];
        $this->assertSame(12, OpenProjectNormalizer::linkId($raw, 'project'));
        $this->assertNull(OpenProjectNormalizer::linkId($raw, 'missing'));
        $this->assertSame('on_track', OpenProjectNormalizer::trailingSegment('/api/v3/project_statuses/on_track'));
    }
}
