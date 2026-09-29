<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamSpaceService;
use OCA\TeamHub\Workflow\Definition\QuotaRequestDefinition;
use OCA\TeamHub\Workflow\WorkflowActor;
use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
use OCA\TeamHub\Workflow\WorkflowStepDefinition;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The definition registry validates on registration, and the built-in
 * quota definition is well-formed (WorkflowHub phase 1, v4.10.13; the
 * quota request as a desk service since v4.10.29).
 */
class WorkflowDefinitionRegistryTest extends TestCase {

    /** @var array<string, mixed>|null the team space the fake TeamSpaceService answers */
    private ?array $space = ['id' => 7, 'mount_point' => 'Sales', 'quota' => 5 * 1024 ** 3];
    private ?string $desk = 'desk';
    /** @var array<int, array{0: string, 1: int}> updateQuota calls */
    private array $quotaWrites = [];

    /** The quota definition over a fake desk and a fake team space (v4.10.29). */
    private function quota(): QuotaRequestDefinition {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('serviceTeamForDefinition')->willReturnCallback(fn (string $key): ?string => $key === QuotaRequestDefinition::KEY ? $this->desk : null);
        $spaces = $this->createMock(TeamSpaceService::class);
        $spaces->method('isAvailable')->willReturn(true);
        $spaces->method('getTeamSpace')->willReturnCallback(fn (): ?array => $this->space);
        $spaces->method('updateQuota')->willReturnCallback(function (string $teamId, int $bytes): array {
            $this->quotaWrites[] = [$teamId, $bytes];
            return [];
        });
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $s, array $p = []): string => vsprintf($s, $p));
        return new QuotaRequestDefinition($teams, $spaces, $l);
    }

    private function instance(int $requestedBytes): WorkflowInstance {
        $i = new WorkflowInstance();
        $i->setTeamId('t1');
        $i->setData(['requestedBytes' => $requestedBytes, 'reason' => 'video', 'currentBytes' => 5 * 1024 ** 3]);
        return $i;
    }

    /**
     * v4.10.29 — the quota request is the seventh Nextcloud service: the
     * requester submits, the desk grants or declines, the requester closes.
     */
    public function testTheQuotaDefinitionIsADeskService(): void {
        $registry = new WorkflowDefinitionRegistry();
        $registry->register($this->quota());

        $def = $registry->get(QuotaRequestDefinition::KEY);
        self::assertNotNull($def);
        self::assertSame(2, $def->getVersion());
        self::assertSame(['submit', 'handle', 'confirm'], array_map(static fn (WorkflowStepDefinition $s): string => $s->key, $def->getSteps()));
        self::assertTrue($def->getSteps()[0]->autoCompleteOnCreate, 'sending the request is the first step');
        self::assertTrue($def->getSteps()[1]->actor->isUnresolved(), 'the desk is resolved at creation');
        $desk = $def->resolveActor($def->getSteps()[1], 't1', []);
        self::assertSame(WorkflowActor::TYPE_SERVICE_AGENT, $desk->type);
        self::assertSame('desk', $desk->id);
        self::assertTrue($def->getSteps()[2]->actor->isInitiator());
        self::assertSame(WorkflowDefinitionRegistry::CONCURRENCY_ONE_OPEN_PER_TEAM, $def->getConcurrency());
        self::assertFalse($def->allowsUnlicensedUse(), 'a desk service is licensed');
        self::assertSame(['teamspace_quota'], array_keys($registry->all()));

        self::assertSame('teamspace_quota', ServiceCatalogue::definitionFor(ServiceCatalogue::TEAM_QUOTA));
        self::assertTrue(ServiceCatalogue::hasOwnDefinition(ServiceCatalogue::TEAM_QUOTA));
        self::assertSame(ServiceCatalogue::CATEGORY_FILES, ServiceCatalogue::categoryFor(ServiceCatalogue::TEAM_QUOTA));
    }

    public function testWithoutADeskTheQuotaRequestIsDark(): void {
        $this->desk = null;
        $def      = $this->quota();
        $resolver = new FakeActorResolver();
        $resolver->levels['t1'] = ['owner' => 9];
        self::assertFalse($def->isStartable());
        self::assertFalse($def->canStart('owner', 't1', $resolver));
        self::assertNull($def->resolveActor($def->getSteps()[1], 't1', []));
    }

    /** The team's side: a space to enlarge, a real increase, and the current quota from the server. */
    public function testTheQuotaDefinitionChecksTheTeamAndRecordsTheCurrentQuota(): void {
        $def = $this->quota();
        self::assertSame(
            ['requestedBytes' => 20 * 1024 ** 3, 'reason' => 'video', 'currentBytes' => 5 * 1024 ** 3],
            $def->validateForTeam('t1', ['requestedBytes' => 20 * 1024 ** 3, 'reason' => 'video']),
        );

        try {
            $def->validateForTeam('t1', ['requestedBytes' => 5 * 1024 ** 3, 'reason' => 'x']);
            self::fail('accepted a size that is not an increase');
        } catch (ValidationException) {
            // expected
        }

        $this->space = null;
        $this->expectException(WorkflowTransitionException::class);
        $this->quota()->validateForTeam('t1', ['requestedBytes' => 20 * 1024 ** 3, 'reason' => 'x']);
    }

    /** Granting is completing the desk's step; the quota is written then, and only then. */
    public function testGrantingWritesTheQuota(): void {
        $def = $this->quota();
        $def->onStepCompleted($this->instance(20 * 1024 ** 3), QuotaRequestDefinition::STEP_SUBMIT, 'inge');
        $def->onStepCompleted($this->instance(20 * 1024 ** 3), QuotaRequestDefinition::STEP_CONFIRM, 'inge');
        self::assertSame([], $this->quotaWrites, 'submitting and closing write nothing');

        $def->onStepCompleted($this->instance(20 * 1024 ** 3), QuotaRequestDefinition::STEP_HANDLE, 'jaap');
        self::assertSame([['t1', 20 * 1024 ** 3]], $this->quotaWrites);
    }

    /** A space that has meanwhile grown past the request is not shrunk back by a grant. */
    public function testAGrantNeverShrinksASpace(): void {
        $this->space = ['id' => 7, 'mount_point' => 'Sales', 'quota' => 50 * 1024 ** 3];
        try {
            $this->quota()->onStepCompleted($this->instance(20 * 1024 ** 3), QuotaRequestDefinition::STEP_HANDLE, 'jaap');
            self::fail('a grant shrank the space');
        } catch (WorkflowTransitionException) {
            // expected
        }
        self::assertSame([], $this->quotaWrites);
    }

    public function testTheDeskStepSaysGrantAndDecline(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $def = $this->quota();
        self::assertSame(['complete' => 'Grant', 'reject' => 'Decline'], $def->getActionLabels($l, QuotaRequestDefinition::STEP_HANDLE));
        self::assertSame(['complete' => 'Close'], $def->getActionLabels($l, QuotaRequestDefinition::STEP_CONFIRM));
        self::assertSame([], $def->getActionLabels($l, QuotaRequestDefinition::STEP_SUBMIT));
    }

    public function testTheQuotaDefinitionGatesStartOnTeamAdminLevel(): void {
        $def      = $this->quota();
        $resolver = new FakeActorResolver();
        $resolver->levels['t1'] = ['owner' => 9, 'admin8' => 8, 'mod' => 4, 'member' => 1];
        self::assertTrue($def->canStart('owner', 't1', $resolver));
        self::assertTrue($def->canStart('admin8', 't1', $resolver));
        self::assertFalse($def->canStart('mod', 't1', $resolver));
        self::assertFalse($def->canStart('member', 't1', $resolver));
        self::assertFalse($def->canStart('owner', 't2', $resolver));
    }

    public function testTheQuotaDefinitionValidatesItsPayload(): void {
        $def = $this->quota();
        self::assertSame(
            ['requestedBytes' => 5, 'reason' => 'more room'],
            // A client-sent currentBytes is dropped: the server records it.
            $def->validateStart(['requestedBytes' => '5', 'reason' => '  more room ', 'junk' => 1, 'currentBytes' => 1]),
        );
        self::assertSame(['team_space', 't1'], $def->subjectOf('t1', []));

        foreach ([['requestedBytes' => 0, 'reason' => 'x'], ['requestedBytes' => 5], ['requestedBytes' => QuotaRequestDefinition::MAX_BYTES + 1, 'reason' => 'x']] as $bad) {
            try {
                $def->validateStart($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                // expected
            }
        }
    }

    public function testRegistrationRefusesMalformedDefinitions(): void {
        $registry = new WorkflowDefinitionRegistry();
        $ok       = new WorkflowStepDefinition('a', 'A', WorkflowActor::team());

        foreach ([
            new FixtureDefinition('Bad Key', 1, [$ok]),
            new FixtureDefinition('zero', 0, [$ok]),
            new FixtureDefinition('empty', 1, []),
            new FixtureDefinition('dup', 1, [$ok, new WorkflowStepDefinition('a', 'Again', WorkflowActor::teamOwner())]),
            new FixtureDefinition('conc', 1, [$ok], null, 'whenever'),
        ] as $bad) {
            try {
                $registry->register($bad);
                self::fail('registered ' . $bad->getKey());
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame([], $registry->all());
    }

    public function testStepsAndActorsValidateTheirOwnShape(): void {
        try {
            new WorkflowStepDefinition('Nope', 'x', WorkflowActor::team());
            self::fail('a bad step key was accepted');
        } catch (\InvalidArgumentException) {
        }
        try {
            WorkflowActor::of('robot', 'r2');
            self::fail('an unknown actor type was accepted');
        } catch (\InvalidArgumentException) {
        }
        try {
            WorkflowActor::of(WorkflowActor::TYPE_USER, '');
            self::fail('a user actor without an id was accepted');
        } catch (\InvalidArgumentException) {
        }
        // Team-relative actors carry no id, whatever was stored.
        self::assertSame('', WorkflowActor::of(WorkflowActor::TYPE_TEAM_OWNER, 'ignored')->id);
        self::assertSame('group:admin', WorkflowActor::group('admin')->key());
        self::assertTrue(WorkflowActor::team()->isTeamRelative());
        self::assertFalse(WorkflowActor::user('x')->isTeamRelative());
    }
}
