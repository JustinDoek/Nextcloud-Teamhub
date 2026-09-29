<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Db\WorkflowInstance;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Exception\WorkflowTransitionException;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCA\TeamHub\Service\TeamExpiryService;
use OCA\TeamHub\Workflow\Definition\TeamExpiryRequestDefinition;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * v4.10.45 — *Request more time for a team*: a team admin asks the service
 * team for a later expiration date, and granting sets it.
 */
class TeamExpiryRequestTest extends TestCase {

    private const DESK = 'desk1';

    /** The team's current date, or null for a team without one. */
    private ?string $current = '2027-01-31';
    private bool $pendingLedger = false;
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $extended = [];

    private function definition(?string $desk = self::DESK): TeamExpiryRequestDefinition {
        $teams = $this->createMock(ServiceTeamService::class);
        $teams->method('serviceTeamForDefinition')->willReturn($desk);

        $expiry = $this->createMock(TeamExpiryService::class);
        $expiry->method('isEligible')->willReturn(true);
        $expiry->method('getExpiry')->willReturnCallback(fn (): ?array => $this->current === null ? null : [
            'expiresAt' => strtotime($this->current . ' 23:59:59 UTC'),
            'expiresOn' => $this->current,
        ]);
        $expiry->method('hasPendingRequest')->willReturnCallback(fn (): bool => $this->pendingLedger);
        $expiry->method('parseDate')->willReturnCallback(static fn (string $date): int => strtotime($date . ' 23:59:59 UTC'));
        $expiry->method('extendByServiceTeam')->willReturnCallback(function (string $teamId, string $on, string $uid): void {
            $this->extended[] = [$teamId, $on, $uid];
        });

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

        return new TeamExpiryRequestDefinition($teams, $expiry, $l);
    }

    private function resolver(): FakeActorResolver {
        $resolver = new FakeActorResolver();
        $resolver->levels['t1'] = ['admin' => 8, 'member' => 1];
        return $resolver;
    }

    public function testATeamAdminAsksWhileADeskOffersIt(): void {
        $this->assertTrue($this->definition()->canStart('admin', 't1', $this->resolver()));
        $this->assertFalse($this->definition()->canStart('member', 't1', $this->resolver()), 'a member cannot');
        $this->assertFalse($this->definition(null)->canStart('admin', 't1', $this->resolver()), 'no desk, no service');
        $this->assertFalse($this->definition(null)->isStartable());
    }

    public function testTheFormNeedsADateAndAReason(): void {
        $data = $this->definition()->validateStart(['proposedOn' => '2027-06-30', 'reason' => ' Still running ', 'extra' => 'x']);
        $this->assertSame(['proposedOn' => '2027-06-30', 'reason' => 'Still running'], $data);

        foreach ([['proposedOn' => '', 'reason' => 'x'], ['proposedOn' => '30-06-2027', 'reason' => 'x'], ['proposedOn' => '2027-06-30', 'reason' => '  ']] as $bad) {
            try {
                $this->definition()->validateStart($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheDateMustBeLaterAndTheCurrentOneIsRecorded(): void {
        $data = $this->definition()->validateForTeam('t1', ['proposedOn' => '2027-06-30', 'reason' => 'x']);
        $this->assertSame('2027-01-31', $data['currentOn']);

        $this->expectException(ValidationException::class);
        $this->definition()->validateForTeam('t1', ['proposedOn' => '2027-01-31', 'reason' => 'x']);
    }

    public function testATeamWithoutADateOrWithALedgerRequestCannotAsk(): void {
        $this->pendingLedger = true;
        try {
            $this->definition()->validateForTeam('t1', ['proposedOn' => '2027-06-30', 'reason' => 'x']);
            $this->fail('a second request beside the ledger one');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->pendingLedger = false;
        $this->current = null;
        $this->expectException(ValidationException::class);
        $this->definition()->validateForTeam('t1', ['proposedOn' => '2027-06-30', 'reason' => 'x']);
    }

    public function testGrantingSetsTheDateItWasAskedFor(): void {
        $instance = new WorkflowInstance();
        $instance->setTeamId('t1');
        $instance->setData(['proposedOn' => '2027-06-30', 'reason' => 'x', 'currentOn' => '2027-01-31']);

        $this->definition()->onStepCompleted($instance, TeamExpiryRequestDefinition::STEP_SUBMIT, 'admin');
        $this->assertSame([], $this->extended, 'sending the request sets nothing');

        $this->definition()->onStepCompleted($instance, TeamExpiryRequestDefinition::STEP_HANDLE, 'agent');
        $this->assertSame([['t1', '2027-06-30', 'agent']], $this->extended);
    }

    public function testADateThatMovedOnMakesTheDeskDecline(): void {
        $instance = new WorkflowInstance();
        $instance->setTeamId('t1');
        $instance->setData(['proposedOn' => '2027-06-30', 'reason' => 'x']);

        $this->current = '2027-12-31';
        try {
            $this->definition()->onStepCompleted($instance, TeamExpiryRequestDefinition::STEP_HANDLE, 'agent');
            $this->fail('granted a date earlier than the current one');
        } catch (WorkflowTransitionException) {
            $this->addToAssertionCount(1);
        }
        $this->current = null;
        $this->expectException(WorkflowTransitionException::class);
        $this->definition()->onStepCompleted($instance, TeamExpiryRequestDefinition::STEP_HANDLE, 'agent');
    }
}
