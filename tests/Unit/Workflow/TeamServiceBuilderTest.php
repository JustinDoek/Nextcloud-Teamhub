<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Exception\LicenseGateException;
use OCA\TeamHub\Exception\NotFoundException;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\AuditService;
use OCA\TeamHub\Service\ServiceTeam\ServiceCategoryService;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\ServiceTeam\TeamServiceBuilder;
use OCA\TeamHub\Workflow\Definition\TeamServiceDefinition;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The service builder (v4.10.33, phase 8a): the document's one shape, what
 * a draft may hold, what publishing needs and does, and what may be
 * deleted. The team-admin gate is the controller's and is not under test
 * here; the desk rules `activate()` sets are `ServiceTeamServiceTest`'s.
 */
class TeamServiceBuilderTest extends TestCase {

    private const DESK = 'desk1';

    private const SERVICE = [
        'title'       => 'Application intake',
        'description' => "Ask for a new application.\nSay what it is for.",
        'category'    => 'apps_tools',
        'leadDays'    => 3,
        'askTeam'     => true,
        'steps'       => [
            ['kind' => 'desk',      'label' => 'Assess the request', 'role' => 'Functional admin'],
            ['kind' => 'requester', 'label' => 'Sign the agreement', 'role' => 'ignored'],
            ['kind' => 'desk',      'label' => 'Install the app',    'role' => ''],
        ],
    ];

    private InMemoryTeamServiceMapper $mapper;
    /** @var string[] team ids `activate()` was called for */
    private array $activated = [];
    private bool $licensed = true;

    protected function setUp(): void {
        $this->mapper = new InMemoryTeamServiceMapper();
    }

    // ── The document ───────────────────────────────────────────────────

    public function testTheDocumentHasOneShape(): void {
        $doc = $this->builder()->normalise(self::SERVICE + ['unknown' => 'dropped']);

        $this->assertSame(['title', 'description', 'category', 'icon', 'leadDays', 'askTeam', 'steps', 'files'], array_keys($doc));
        $this->assertSame(['kind', 'label', 'tasks'], array_keys($doc['steps'][0]));
        $this->assertSame(['label', 'role', 'nonBlocking', 'links'], array_keys($doc['steps'][0]['tasks'][0]));
        $this->assertSame('', $doc['steps'][1]['tasks'][0]['role'], 'a role only means something on the team\'s own task');
        $this->assertSame('Functional admin', $doc['steps'][0]['tasks'][0]['role'], 'an old step\'s role becomes its one task\'s');
        $this->assertTrue($doc['askTeam']);
    }

    public function testTheDocumentIsWhatTheDefinitionReads(): void {
        $doc = $this->builder()->normalise(self::SERVICE);
        $definition = new TeamServiceDefinition($this->createMock(ServiceTeamService::class), 7, self::DESK, 1, true, $doc);
        $keys = array_map(static fn ($s): string => $s->key, $definition->getSteps());
        $this->assertSame(['submit', 'step_1', 'step_2', 'step_3', 'confirm'], $keys);
        $this->assertSame('Functional admin', $definition->getSteps()[1]->roleLabel);
    }

    public function testDefaultsForWhatWasLeftOut(): void {
        $doc = $this->builder()->normalise(['title' => '  Laptop  ']);
        $this->assertSame('Laptop', $doc['title']);
        $this->assertSame(ServiceCatalogue::CATEGORY_SUPPORT, $doc['category']);
        $this->assertSame(0, $doc['leadDays'], 'no lead time is said');
        $this->assertFalse($doc['askTeam']);
        $this->assertSame([], $doc['steps']);
    }

    public function testTheStringFalseIsNotAYes(): void {
        $this->assertFalse($this->builder()->normalise(['title' => 'x', 'askTeam' => 'false'])['askTeam']);
    }

    /** @dataProvider refused */
    public function testWhatADraftMayNotHold(array $patch): void {
        $this->expectException(ValidationException::class);
        $this->builder()->normalise(array_replace(self::SERVICE, $patch));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function refused(): array {
        return [
            'no title'             => [['title' => '   ']],
            'a title too long'     => [['title' => str_repeat('a', TeamServiceBuilder::MAX_TITLE + 1)]],
            'a line break'         => [['title' => "Two\nlines"]],
            'an invented category' => [['category' => 'printing']],
            // v4.10.45 — an icon the builder does not offer.
            'an invented icon'     => [['icon' => 'Skull']],
            'an icon path'         => [['icon' => '../App.vue']],
            'a lead time too long' => [['leadDays' => TeamServiceBuilder::MAX_LEAD_DAYS + 1]],
            'a negative lead time' => [['leadDays' => -1]],
            'a fractional one'     => [['leadDays' => 1.5]],
            'steps as a map'       => [['steps' => ['a' => ['kind' => 'desk', 'label' => 'x']]]],
            'an unknown kind'      => [['steps' => [['kind' => 'manager', 'label' => 'x']]]],
            'too many steps'       => [['steps' => array_fill(0, TeamServiceBuilder::MAX_STEPS + 1, ['kind' => 'desk', 'label' => 'x'])]],
            'a role too long'      => [['steps' => [['kind' => 'desk', 'label' => 'x', 'tasks' => [['role' => str_repeat('r', TeamServiceBuilder::MAX_ROLE + 1)]]]]]],
            'tasks as a map'       => [['steps' => [['kind' => 'desk', 'label' => 'x', 'tasks' => ['a' => ['label' => 't']]]]]],
            'too many tasks'       => [['steps' => [['kind' => 'desk', 'label' => 'x', 'tasks' => array_fill(0, TeamServiceBuilder::MAX_TASKS + 1, ['label' => 't'])]]]],
            'a task name too long' => [['steps' => [['kind' => 'desk', 'label' => 'x', 'tasks' => [['label' => str_repeat('t', TeamServiceBuilder::MAX_STEP_LABEL + 1)]]]]]],
            'a control character'  => [['description' => "bell\x07"]],
            'a title as an array'  => [['title' => ['x']]],
            // v4.10.36 — links (the old shape, on the step, is still read).
            'a javascript: link'   => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => 'a', 'url' => 'javascript:alert(1)']]]]]],
            'a relative link'      => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => 'a', 'url' => '/apps/files']]]]]],
            'a url with a space'   => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => 'a', 'url' => 'https://exa mple.org']]]]]],
            'a url without host'   => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => 'a', 'url' => 'https://']]]]]],
            'an unknown link kind' => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => 'a', 'url' => 'https://x.org', 'kind' => 'appointment']]]]]],
            'links as a map'       => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => ['a' => ['label' => 'a', 'url' => 'https://x.org']]]]]],
            'too many links'       => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => array_fill(0, TeamServiceBuilder::MAX_LINKS + 1, ['label' => 'a', 'url' => 'https://x.org'])]]]],
            'a link name too long' => [['steps' => [['kind' => 'desk', 'label' => 'x', 'links' => [['label' => str_repeat('a', TeamServiceBuilder::MAX_LINK_LABEL + 1), 'url' => 'https://x.org']]]]]],
        ];
    }

    /** v4.10.36 — links, now on tasks (v4.10.37), `docs/service-builder.md` § 6.1. */
    public function testLinksOnATaskAreKeptAsWritten(): void {
        $doc = $this->builder()->normalise(['title' => 'x', 'steps' => [[
            'kind'  => 'requester',
            'label' => 'Fill in the intake',
            'tasks' => [[
                'label' => 'Intake',
                'links' => [
                    ['label' => ' Intake form ', 'url' => ' https://forms.example.org/i?a=1 ', 'kind' => 'form'],
                    ['label' => 'Guide', 'url' => 'https://wiki.example.org/guide'],
                ],
            ]],
        ]]]);
        $this->assertSame([
            ['label' => 'Intake form', 'url' => 'https://forms.example.org/i?a=1', 'kind' => 'form'],
            ['label' => 'Guide', 'url' => 'https://wiki.example.org/guide', 'kind' => 'link'],
        ], $doc['steps'][0]['tasks'][0]['links'], 'trimmed, and a page unless said otherwise');
    }

    public function testAStepAlwaysHoldsATask(): void {
        $doc = $this->builder()->normalise(['title' => 'x', 'steps' => [['kind' => 'desk', 'label' => 'a', 'tasks' => []]]]);
        $this->assertCount(1, $doc['steps'][0]['tasks']);
        $this->assertSame('', $doc['steps'][0]['tasks'][0]['label'], 'one task borrows the step\'s name');
    }

    /** A step saved before v4.10.37 carried its role and links itself. */
    public function testAnOldStepIsReadAsOneTask(): void {
        $doc = $this->builder()->normalise(['title' => 'x', 'steps' => [[
            'kind' => 'desk', 'label' => 'Assess', 'role' => 'Functional admin',
            'links' => [['label' => 'Checklist', 'url' => 'https://wiki.example.org/c']],
        ]]]);
        $this->assertSame([[
            'label' => '', 'role' => 'Functional admin', 'nonBlocking' => false,
            'links' => [['label' => 'Checklist', 'url' => 'https://wiki.example.org/c', 'kind' => 'link']],
        ]], $doc['steps'][0]['tasks']);
    }

    public function testOnlyATeamTaskHasARoleOrMayBeLeftOpen(): void {
        $doc = $this->builder()->normalise(['title' => 'x', 'steps' => [
            ['kind' => 'desk', 'label' => 'a', 'tasks' => [['label' => 'p', 'role' => 'CISO', 'nonBlocking' => true]]],
            ['kind' => 'requester', 'label' => 'b', 'tasks' => [['label' => 'q', 'role' => 'CISO', 'nonBlocking' => true]]],
        ]]);
        $this->assertSame(['CISO', true], [$doc['steps'][0]['tasks'][0]['role'], $doc['steps'][0]['tasks'][0]['nonBlocking']]);
        $this->assertSame(['', false], [$doc['steps'][1]['tasks'][0]['role'], $doc['steps'][1]['tasks'][0]['nonBlocking']]);
        $this->assertFalse($this->builder()->normalise(['title' => 'x', 'steps' => [
            ['kind' => 'desk', 'label' => 'a', 'tasks' => [['label' => 'p', 'nonBlocking' => 'false']]],
        ]])['steps'][0]['tasks'][0]['nonBlocking'], 'the string "false" is not a yes');
    }

    public function testADraftMayHoldAnUnfinishedLinkButPublishingMayNot(): void {
        $b = $this->builder();
        foreach ([['label' => '', 'url' => 'https://x.org'], ['label' => 'Guide', 'url' => '']] as $link) {
            $doc = $b->normalise(['title' => 'x', 'steps' => [['kind' => 'desk', 'label' => 'a', 'tasks' => [['links' => [$link]]]]]]);
            $this->assertSame(['Give every link a name and an address.'], $b->publishProblems($doc));
        }
    }

    public function testSeveralTasksEachNeedAName(): void {
        $b = $this->builder();
        $doc = $b->normalise(['title' => 'x', 'steps' => [['kind' => 'desk', 'label' => 'Assess', 'tasks' => [['label' => 'Privacy'], ['label' => '']]]]]);
        $this->assertSame(['Give every task a name when a step has more than one.'], $b->publishProblems($doc));
    }

    public function testAStepMustHaveATaskTheRequestWaitsFor(): void {
        $b = $this->builder();
        $doc = $b->normalise(['title' => 'x', 'steps' => [['kind' => 'desk', 'label' => 'Assess', 'tasks' => [['label' => 'Privacy', 'nonBlocking' => true]]]]]);
        $this->assertSame(['Every step needs at least one task the request waits for.'], $b->publishProblems($doc));
    }

    public function testTheTasksBecomeTheDefinitionsSteps(): void {
        $doc = $this->builder()->normalise(['title' => 'x', 'steps' => [
            ['kind' => 'desk', 'label' => 'Assess', 'tasks' => [
                ['label' => 'Privacy check', 'role' => 'Privacy officer', 'links' => [['label' => 'Checklist', 'url' => 'https://wiki.example.org/c']]],
                ['label' => 'Security check', 'role' => 'CISO', 'nonBlocking' => true],
            ]],
            ['kind' => 'requester', 'label' => 'Sign', 'tasks' => [['label' => '']]],
            ['kind' => 'desk', 'label' => 'Install'],
        ]]);
        $steps = (new TeamServiceDefinition($this->createMock(ServiceTeamService::class), 7, self::DESK, 1, true, $doc))->getSteps();

        $this->assertSame(['submit', 'step_1_1', 'step_1_2', 'step_2', 'step_3', 'confirm'], array_map(static fn ($s): string => $s->key, $steps));
        $this->assertSame([null, 'step_1', 'step_1', 'step_2', 'step_3', null], array_map(static fn ($s): ?string => $s->stage, $steps));
        $this->assertSame('Privacy check', $steps[1]->label);
        $this->assertSame('Assess', $steps[1]->stageLabel);
        $this->assertSame('Privacy officer', $steps[1]->roleLabel);
        $this->assertSame('https://wiki.example.org/c', $steps[1]->links[0]['url']);
        $this->assertFalse($steps[1]->nonBlocking);
        $this->assertTrue($steps[2]->nonBlocking);
        $this->assertSame('Sign', $steps[3]->label, 'one unnamed task borrows the step\'s name');
        $this->assertSame('', $steps[3]->stageLabel);
    }

    /** v4.10.38 — the paperclip's settings: allowed, view only, 14 days unless said otherwise. */
    public function testTheFileSettingsDefaultAndAreChecked(): void {
        $b = $this->builder();
        $this->assertSame(['allowed' => true, 'edit' => false, 'days' => 14], $b->normalise(['title' => 'x'])['files']);
        $this->assertSame(['allowed' => false, 'edit' => true, 'days' => 30],
            $b->normalise(['title' => 'x', 'files' => ['allowed' => 'false', 'edit' => true, 'days' => '30']])['files']);
        foreach ([0, 366, 'soon', 1.5] as $days) {
            try {
                $b->normalise(['title' => 'x', 'files' => ['days' => $days]]);
                $this->fail('days ' . var_export($days, true));
            } catch (ValidationException $e) {
            }
        }
    }

    public function testARequestCarriesTheFileSettingsItStartedWith(): void {
        $teams = $this->createMock(ServiceTeamService::class);
        $doc   = $this->builder()->normalise(self::SERVICE + ['files' => ['edit' => true, 'days' => 7]]);
        $data  = (new TeamServiceDefinition($teams, 7, self::DESK, 1, true, $doc))->validateStart(['summary' => 'Need it', 'details' => 'Please']);
        $this->assertSame(['allowed' => true, 'edit' => true, 'days' => 7], $data['fileSharing']);
    }

    /**
     * v4.10.39 — the requesting team is optional: a request asked from no team
     * is recorded against the service team itself, and anybody signed in may
     * make one. Asked from a team, only its members may.
     */
    public function testAPersonalRequestIsAskedOfTheServiceTeamByAnybody(): void {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('isActiveServiceTeam')->willReturn(true);
        $resolver = new FakeActorResolver();
        $resolver->levels['t1'] = ['member' => 1];
        $definition = new TeamServiceDefinition($teams, 7, self::DESK, 1, true, $this->builder()->normalise(self::SERVICE));

        $this->assertTrue($definition->canStart('stranger', self::DESK, $resolver), 'personal: anybody');
        $this->assertFalse($definition->canStart('', self::DESK, $resolver), 'but somebody');
        $this->assertTrue($definition->canStart('member', 't1', $resolver));
        $this->assertFalse($definition->canStart('stranger', 't1', $resolver), 'asked from a team: its members only');
    }

    /** v4.10.36 published `start: link`; v4.10.37 withdrew it (*"We want 1 process"*). */
    public function testAServicePublishedAsALinkIsNeverStartable(): void {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('isActiveServiceTeam')->willReturn(true);
        $legacy = new TeamServiceDefinition($teams, 7, self::DESK, 1, true, ['title' => 'x', 'start' => 'link', 'startUrl' => 'https://x.org', 'steps' => []]);
        $this->assertTrue($legacy->isLegacyLinkService());
        $this->assertFalse($legacy->isStartable());
        $form = new TeamServiceDefinition($teams, 8, self::DESK, 1, true, $this->builder()->normalise(self::SERVICE));
        $this->assertTrue($form->isStartable());
        $this->assertArrayNotHasKey('start', $this->builder()->normalise(['title' => 'x', 'start' => 'link', 'startUrl' => 'https://x.org']),
            'the next save drops it');
    }

    public function testAnEmptyLeadTimeIsNone(): void {
        $this->assertSame(0, $this->builder()->normalise(['title' => 'x', 'leadDays' => ''])['leadDays']);
        $this->assertSame(0, $this->builder()->normalise(['title' => 'x', 'leadDays' => null])['leadDays']);
        $this->assertSame(5, $this->builder()->normalise(['title' => 'x', 'leadDays' => '5'])['leadDays']);
    }

    // ── What publishing needs ──────────────────────────────────────────

    public function testACompleteServiceHasNothingInTheWay(): void {
        $b = $this->builder();
        $this->assertSame([], $b->publishProblems($b->normalise(self::SERVICE)));
    }

    public function testWhatStandsBetweenADraftAndPublishing(): void {
        $b = $this->builder();
        $this->assertCount(1, $b->publishProblems($b->normalise(['title' => 'x'])), 'no step for the team');
        $this->assertCount(1, $b->publishProblems($b->normalise([
            'title' => 'x', 'steps' => [['kind' => 'desk', 'label' => '']],
        ])), 'a step without a name');
        $this->assertCount(2, $b->publishProblems($b->normalise([
            'title' => 'x', 'steps' => [['kind' => 'requester', 'label' => 'Sign']],
        ])), 'no team step, and a requester step with nothing after it');
    }

    /** v4.10.39 — a service may start with the requester: a form to fill in first (the v4.10.35 rule is lifted). */
    public function testAServiceMayStartWithTheRequester(): void {
        $b = $this->builder();
        $this->assertSame([], $b->publishProblems($b->normalise([
            'title' => 'x', 'steps' => [
                ['kind' => 'requester', 'label' => 'Fill in the intake form', 'tasks' => [['links' => [['label' => 'Intake', 'url' => 'https://forms.example.org/i', 'kind' => 'form']]]]],
                ['kind' => 'desk', 'label' => 'Assess'],
            ],
        ])));
    }

    // ── The lifecycle ──────────────────────────────────────────────────

    public function testANewServiceIsADraftNobodyCanRequest(): void {
        $service = $this->builder()->create(self::DESK, self::SERVICE, 'admin1');

        $this->assertSame(0, $service['version']);
        $this->assertFalse($service['listed']);
        $this->assertNull($service['published']);
        $this->assertTrue($service['hasChanges']);
        $this->assertSame([], $service['publishProblems']);
        $this->assertSame('team_service_' . $service['id'], $service['definitionKey']);
        $this->assertSame([], $this->activated, 'a draft does not make the team a desk');
    }

    public function testPublishingListsItRaisesTheVersionAndMakesTheTeamADesk(): void {
        $b  = $this->builder();
        $id = $b->create(self::DESK, self::SERVICE, 'admin1')['id'];

        $service = $b->publish(self::DESK, $id, 'admin1');

        $this->assertSame(1, $service['version']);
        $this->assertTrue($service['listed']);
        $this->assertSame($service['draft'], $service['published']);
        $this->assertFalse($service['hasChanges']);
        $this->assertSame('admin1', $service['publishedBy']);
        $this->assertSame([self::DESK], $this->activated);
    }

    public function testARunningVersionIsNotChangedByEditingTheDraft(): void {
        $b  = $this->builder();
        $id = $b->create(self::DESK, self::SERVICE, 'admin1')['id'];
        $b->publish(self::DESK, $id, 'admin1');

        $service = $b->saveDraft(self::DESK, $id, ['title' => 'Renamed'] + self::SERVICE, 'admin1');

        $this->assertSame('Renamed', $service['draft']['title']);
        $this->assertSame('Application intake', $service['published']['title'], 'requests still start from the published version');
        $this->assertTrue($service['hasChanges']);
        $this->assertSame(1, $service['version']);

        $this->assertSame(2, $b->publish(self::DESK, $id, 'admin1')['version']);
    }

    public function testAnIncompleteDraftIsNotPublished(): void {
        $b  = $this->builder();
        $id = $b->create(self::DESK, ['title' => 'Half done'], 'admin1')['id'];

        try {
            $b->publish(self::DESK, $id, 'admin1');
            $this->fail('published without a step for the team');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $this->mapper->findById($id)->getPubVersion());
        $this->assertSame([], $this->activated);
    }

    public function testUnpublishingTakesTheCardOffAndKeepsTheVersion(): void {
        $b  = $this->builder();
        $id = $b->create(self::DESK, self::SERVICE, 'admin1')['id'];
        $b->publish(self::DESK, $id, 'admin1');

        $service = $b->unpublish(self::DESK, $id, 'admin1');

        $this->assertFalse($service['listed']);
        $this->assertSame(1, $service['version'], 'still registered, so its running requests finish');
        $this->assertNotNull($service['published']);
    }

    public function testOnlyANeverPublishedServiceIsDeleted(): void {
        $b     = $this->builder();
        $draft = $b->create(self::DESK, self::SERVICE, 'admin1')['id'];
        $b->delete(self::DESK, $draft, 'admin1');
        $this->assertNull($this->mapper->findById($draft));

        $live = $b->create(self::DESK, self::SERVICE, 'admin1')['id'];
        $b->publish(self::DESK, $live, 'admin1');
        $b->unpublish(self::DESK, $live, 'admin1');
        $this->expectException(ValidationException::class);
        $b->delete(self::DESK, $live, 'admin1');
    }

    public function testAnotherTeamsServiceIsNotFound(): void {
        $b  = $this->builder();
        $id = $b->create('other-desk', self::SERVICE, 'admin9')['id'];

        foreach ([
            fn () => $b->saveDraft(self::DESK, $id, self::SERVICE, 'admin1'),
            fn () => $b->publish(self::DESK, $id, 'admin1'),
            fn () => $b->unpublish(self::DESK, $id, 'admin1'),
            fn () => $b->delete(self::DESK, $id, 'admin1'),
        ] as $call) {
            try {
                $call();
                $this->fail('reached a service of another team through this one');
            } catch (NotFoundException) {
            }
        }
        $this->assertSame([], $this->activated);
        $this->assertSame([], $b->listForTeam(self::DESK));
    }

    public function testATeamOffersAtMostSoManyServices(): void {
        $b = $this->builder();
        for ($i = 0; $i < TeamServiceBuilder::MAX_SERVICES; $i++) {
            $b->create(self::DESK, ['title' => 'S' . $i], 'admin1');
        }
        $this->expectException(ValidationException::class);
        $b->create(self::DESK, ['title' => 'One too many'], 'admin1');
    }

    public function testNewServicesGoAfterTheLastOne(): void {
        $b = $this->builder();
        $b->create(self::DESK, ['title' => 'First'], 'admin1');
        $b->create(self::DESK, ['title' => 'Second'], 'admin1');
        $this->assertSame(['First', 'Second'], array_map(static fn (array $s): string => $s['draft']['title'], $b->listForTeam(self::DESK)));
    }

    public function testEverythingIsLicensed(): void {
        $this->licensed = false;
        $b = $this->builder();
        foreach ([
            fn () => $b->listForTeam(self::DESK),
            fn () => $b->create(self::DESK, self::SERVICE, 'admin1'),
            fn () => $b->saveDraft(self::DESK, 1, self::SERVICE, 'admin1'),
            fn () => $b->publish(self::DESK, 1, 'admin1'),
            fn () => $b->unpublish(self::DESK, 1, 'admin1'),
            fn () => $b->delete(self::DESK, 1, 'admin1'),
        ] as $call) {
            try {
                $call();
                $this->fail('reached the builder without a licence');
            } catch (LicenseGateException) {
            }
        }
        $this->assertSame([], $this->mapper->rows);
    }

    public function testTheOptionsNameEveryCategory(): void {
        $options = $this->builder()->options();
        $this->assertSame(ServiceCatalogue::CATEGORIES, array_column($options['categories'], 'key'));
        $this->assertSame(TeamServiceBuilder::MAX_STEPS, $options['limits']['steps']);
        $this->assertContains('Lifebuoy', $options['icons']);
    }

    /** v4.10.45 — a service keeps the icon its team picked; none is the default. */
    public function testAServiceKeepsItsIcon(): void {
        $this->assertSame('Printer', $this->builder()->normalise(['icon' => 'Printer'] + self::SERVICE)['icon']);
        $this->assertSame('', $this->builder()->normalise(self::SERVICE)['icon']);
    }

    /** v4.10.45 — a category an administrator added is one a team may pick. */
    public function testACategoryAnAdministratorAddedMayBePicked(): void {
        $this->extraCategories = ['c_1a2b3c4d'];
        $this->assertSame('c_1a2b3c4d', $this->builder()->normalise(['category' => 'c_1a2b3c4d'] + self::SERVICE)['category']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @var list<string> v4.10.45 — categories an administrator added. */
    private array $extraCategories = [];

    private function builder(): TeamServiceBuilder {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('requireLicence')->willReturnCallback(function (): void {
            if (!$this->licensed) {
                throw new LicenseGateException('unlicensed', 'Service Teams require an active TeamHub licence.');
            }
        });
        $teams->method('activate')->willReturnCallback(function (string $teamId): \OCA\TeamHub\Db\ServiceTeam {
            $this->activated[] = $teamId;
            return new \OCA\TeamHub\Db\ServiceTeam();
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(3_000_000);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(
            static fn (string $one, string $many, int $count, array $params = []): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many),
        );

        // v4.10.45 — the administrator's categories: the built-ins, plus any
        // a test adds.
        $keys = array_merge(ServiceCatalogue::CATEGORIES, $this->extraCategories);
        $categories = $this->createMock(ServiceCategoryService::class);
        $categories->method('list')->willReturn(array_map(
            static fn (string $key): array => ['key' => $key, 'label' => $key, 'icon' => 'HelpCircleOutline', 'builtIn' => true, 'customLabel' => ''],
            $keys,
        ));
        $categories->method('exists')->willReturnCallback(static fn (string $key): bool => in_array($key, $keys, true));
        $categories->method('resolve')->willReturnCallback(static fn (string $key): string => in_array($key, $keys, true) ? $key : $keys[0]);

        return new TeamServiceBuilder(
            $this->mapper,
            $teams,
            $this->createMock(AuditService::class),
            $time,
            $l,
            $this->createMock(LoggerInterface::class),
            $categories,
        );
    }
}
