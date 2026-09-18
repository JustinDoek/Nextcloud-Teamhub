<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\MyWork;

use OCA\TeamHub\MyWork\Provider\ApprovalWorkProvider;
use OCA\TeamHub\MyWork\Provider\DeckWorkProvider;
use OCA\TeamHub\MyWork\Provider\FileReviewWorkProvider;
use OCA\TeamHub\MyWork\Provider\OpenProjectWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryAdminWorkProvider;
use OCA\TeamHub\MyWork\Provider\TeamExpiryTeamWorkProvider;
use OCA\TeamHub\MyWork\SourceGroup;
use PHPUnit\Framework\TestCase;

/**
 * The My Work source groups (v4.9.17): which provider belongs where, and how
 * a mixed list of group keys and provider ids expands.
 */
class SourceGroupTest extends TestCase {

    public function testTheGroupsNameTheShippedProviderIds(): void {
        // The members are spelled by their `ID` constants, so a renamed
        // provider fails here rather than silently leaving its group.
        $this->assertSame([ApprovalWorkProvider::ID, FileReviewWorkProvider::ID], SourceGroup::MEMBERS[SourceGroup::FILES]);
        $this->assertSame([TeamAdminWorkProvider::ID, TeamExpiryTeamWorkProvider::ID], SourceGroup::MEMBERS[SourceGroup::TEAMS]);
        $this->assertSame([TeamExpiryAdminWorkProvider::ID], SourceGroup::MEMBERS[SourceGroup::ADMINISTRATION]);
    }

    public function testOfAndIsGroup(): void {
        $this->assertSame(SourceGroup::FILES, SourceGroup::of('approval'));
        $this->assertSame(SourceGroup::FILES, SourceGroup::of('file_review'));
        $this->assertSame(SourceGroup::TEAMS, SourceGroup::of('teamadmin'));
        $this->assertSame(SourceGroup::ADMINISTRATION, SourceGroup::of('teamexpiry_admin'));
        $this->assertNull(SourceGroup::of(DeckWorkProvider::ID), 'Deck stays a single tab');
        $this->assertNull(SourceGroup::of(OpenProjectWorkProvider::ID), 'so does OpenProject');
        $this->assertNull(SourceGroup::of('files'), 'a group key is not a provider');

        $this->assertTrue(SourceGroup::isGroup('files'));
        $this->assertFalse(SourceGroup::isGroup('approval'));
    }

    public function testExpandMixesGroupsAndIdsKeepsOrderAndDropsDuplicates(): void {
        $this->assertSame(['approval', 'file_review'], SourceGroup::expand(['files']));
        $this->assertSame(['deck', 'approval', 'file_review', 'openproject'], SourceGroup::expand(['deck', 'files', 'openproject']));
        $this->assertSame(['approval', 'file_review'], SourceGroup::expand(['approval', 'files']), 'a member named beside its group appears once');
        $this->assertSame([], SourceGroup::expand([]));
        $this->assertSame(['unknown'], SourceGroup::expand(['unknown']), 'an unknown key passes through — the query then matches nothing, as before');
    }

    public function testAdministrationHoldsOnlyInstanceScopedProviders(): void {
        // The whole reason the group can be hidden from non-admins by leaving
        // its providers out of the viewer's list: every member declares
        // isInstanceScoped(). A team-scoped provider added here would be
        // hidden from the members who hold its work.
        foreach (SourceGroup::MEMBERS[SourceGroup::ADMINISTRATION] as $id) {
            $class = match ($id) {
                TeamExpiryAdminWorkProvider::ID => TeamExpiryAdminWorkProvider::class,
                default => $this->fail('Unknown Administration member ' . $id . ' — add its class here'),
            };
            $this->assertTrue(method_exists($class, 'isInstanceScoped'), $class . ' must declare isInstanceScoped()');
        }
    }
}
