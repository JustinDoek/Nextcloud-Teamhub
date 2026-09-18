<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\OpenProject;

use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\OpenProjectNewsMirrorMapper;
use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Service\OpenProject\OpenProjectMessages;
use OCA\TeamHub\Service\OpenProject\OpenProjectNewsService;
use OCA\TeamHub\Service\OpenProject\OpenProjectSyncHealth;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * OpenProject news in What's new and mirrored into the stream (v4.9.7):
 * one bounded read per linked project, rows keyed apart, the window;
 * the mirror writes once, only news since the link and inside the age
 * limit, as the right author, with the source named — and never twice.
 */
class OpenProjectNewsServiceTest extends OpenProjectTestCase {

    /** Noon UTC, 2026-09-14. */
    private const NOW = 1789387200;

    /** @var list<array{0: string, 1: array, 2: string, 3: string}> */
    private array $requests = [];
    /** @var list<array<string, mixed>> messages written: [teamId, author, subject, body, isSystem] */
    private array $written = [];
    /** @var array<string, bool> ledger: "team|connection|news" */
    private array $ledger = [];
    private bool $ledgerBroken = false;
    /** @var array<int, array{newsId: int, connection: string}> message id → ledger row, for stampOrigins() */
    private array $origins = [];
    /** @var array<string, string> OpenProject user id → NC uid */
    private array $connectedAccounts = [];

    /**
     * @param array<string, array{projectId: int, host?: string, createdAt?: int, createdBy?: string}> $linked
     */
    private function service(array $linked, ?callable $handler = null, bool $connected = true, bool $enabled = true): OpenProjectNewsService {
        $this->withHost();
        if ($connected) {
            $this->withConnectedUser('alice');
            $this->withConnectedUser('bob', 'Bob');
        }
        $this->requests = [];
        $this->written  = [];
        $this->ledger   = [];

        $op = $this->opService(function (string $uid, string $endpoint, array $params, string $method) use ($handler) {
            $this->requests[] = [$endpoint, $params, $method, $uid];
            return $handler ? $handler($endpoint, $params, $uid) : self::collectionResponse([], 0, 'Collection');
        });
        $client = $this->client($op, enabled: $enabled);

        $links = $this->createMock(TeamOpenProjectLinkMapper::class);
        $links->method('findByTeams')->willReturnCallback(function (array $teamIds) use ($linked): array {
            $out = [];
            foreach ($teamIds as $id) {
                if (!isset($linked[$id])) {
                    continue;
                }
                $row = new TeamOpenProjectLink();
                $row->setTeamId($id);
                $row->setProjectId($linked[$id]['projectId']);
                $row->setProjectIdentifier('p' . $linked[$id]['projectId']);
                $row->setProjectName('Project ' . $linked[$id]['projectId']);
                $row->setHost($linked[$id]['host'] ?? self::HOST);
                $row->setCreatedAt($linked[$id]['createdAt'] ?? (self::NOW - 10 * 86400));
                $row->setCreatedBy($linked[$id]['createdBy'] ?? 'creator');
                $out[$id] = $row;
            }
            return $out;
        });

        $mirror = $this->createMock(OpenProjectNewsMirrorMapper::class);
        $mirror->method('mirroredIds')->willReturnCallback(function (string $teamId, string $connection, array $ids): array {
            if ($this->ledgerBroken) {
                throw new \RuntimeException('no such table');
            }
            return array_values(array_filter($ids, fn (int $id) => isset($this->ledger[$teamId . '|' . $connection . '|' . $id])));
        });
        $mirror->method('claim')->willReturnCallback(function (string $teamId, string $connection, int $newsId): ?int {
            $key = $teamId . '|' . $connection . '|' . $newsId;
            if (isset($this->ledger[$key])) {
                return null;
            }
            $this->ledger[$key] = true;
            return count($this->ledger);
        });
        $mirror->method('release')->willReturnCallback(function (int $ledgerId): void {
            array_pop($this->ledger);
        });
        $mirror->method('findByMessageIds')->willReturnCallback(function (array $ids): array {
            if ($this->ledgerBroken) {
                throw new \RuntimeException('no such table');
            }
            $out = [];
            foreach ($ids as $id) {
                if (isset($this->origins[(int)$id])) {
                    $out[(int)$id] = $this->origins[(int)$id];
                }
            }
            return $out;
        });

        $messages = $this->createMock(MessageMapper::class);
        $messages->method('create')->willReturnCallback(function (string $teamId, string $author, string $subject, string $body, string $prio, string $type, $poll, bool $isPublic, bool $isSystem): array {
            if ($subject === 'OpenProject news: boom') {
                throw new \RuntimeException('database gone');
            }
            $this->written[] = ['teamId' => $teamId, 'author' => $author, 'subject' => $subject, 'body' => $body, 'isPublic' => $isPublic, 'isSystem' => $isSystem];
            return ['id' => count($this->written)];
        });

        $config = $this->config();
        $config->method('getUsersForUserValue')->willReturnCallback(
            fn (string $app, string $key, string $value): array => isset($this->connectedAccounts[$value]) ? [$this->connectedAccounts[$value]] : [],
        );

        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturnCallback(fn (string $uid): bool => $uid !== 'ghost');
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            return $u;
        });

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $s, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $s), $p));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $factory->method('getUserLanguage')->willReturn('en');

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn(new \OC\Memcache\ArrayCache());
        $health = new OpenProjectSyncHealth($cacheFactory, $this->config(), $this->createMock(LoggerInterface::class));

        return new OpenProjectNewsService(
            $client,
            $this->cache(),
            $links,
            $mirror,
            $messages,
            $health,
            new OpenProjectMessages($l),
            $config,
            $users,
            $factory,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array<string, mixed> */
    private function news(int $id, string $createdAt, array $overrides = []): array {
        return array_replace_recursive([
            '_type'       => 'News',
            'id'          => $id,
            'title'       => 'News ' . $id,
            'summary'     => 'Summary of ' . $id,
            'description' => ['format' => 'markdown', 'raw' => 'Body of ' . $id, 'html' => '<p>x</p>'],
            'createdAt'   => $createdAt,
            '_links'      => [
                'author'  => ['href' => '/api/v3/users/9', 'title' => 'Carol'],
                'project' => ['href' => '/api/v3/projects/12', 'title' => 'Demo'],
            ],
        ], $overrides);
    }

    private function answering(array $elements): callable {
        return fn () => self::collectionResponse($elements, count($elements), 'Collection');
    }

    // ── Reading ────────────────────────────────────────────────────────

    public function testOneReadPerLinkedProjectFilteredByProjectNewestFirst(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13, 'host' => 'https://other.test']]);
        $s->feed('alice', ['team-a', 'team-b'], 0, 0, 20);

        $reads = array_values(array_filter($this->requests, static fn (array $r) => $r[0] === 'news'));
        $this->assertCount(1, $reads, 'the stale link costs nothing');
        $filters = json_decode($reads[0][1]['filters'], true);
        $this->assertSame(['project_id' => ['operator' => '=', 'values' => ['12']]], $filters[0]);
        $this->assertSame([['created_at', 'desc']], json_decode($reads[0][1]['sortBy'], true));
        $this->assertSame(20, $reads[0][1]['pageSize']);
    }

    public function testRowsAreShapedForTheFeedAndKeyedApart(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->news(5, '2026-09-14T08:00:00Z', ['title' => 'Release <b>1.0</b>']),
            $this->news(4, '2026-09-13T08:00:00Z', ['summary' => '']),
            ['_type' => 'Junk'],
        ]));

        $r = $s->feed('alice', ['team-a'], 0, 0, 20, mirror: false);

        $this->assertSame('ok', $r['status']['state']);
        $this->assertCount(2, $r['items']);
        $row = $r['items'][0];
        $this->assertSame('op:' . substr(md5(self::HOST), 0, 12) . ':12:news:5', $row['id']);
        $this->assertSame('openproject', $row['source']);
        $this->assertSame('news', $row['activityType']);
        $this->assertSame('Release 1.0', $row['subject']);
        $this->assertSame('Summary of 5', $row['message']);
        $this->assertSame(strtotime('2026-09-14T08:00:00Z'), $row['created_at']);
        $this->assertSame('', $row['author_id']);
        $this->assertSame('Carol', $row['actor_name']);
        $this->assertSame(self::HOST . '/news/5', $row['news']['url']);
        $this->assertSame(self::HOST . '/projects/p12', $row['project']['url']);
        $this->assertSame('Body of 4', $r['items'][1]['message'], 'no summary: the body excerpt');
        $this->assertSame([['id' => '12', 'name' => 'Project 12', 'teamId' => 'team-a', 'count' => 2]], $r['projects']);
    }

    public function testTheWindowAndTheProjectFilterApply(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], function (string $endpoint, array $params): array {
            $filters = json_decode($params['filters'] ?? '[]', true);
            $pid = (int)($filters[0]['project_id']['values'][0] ?? 0);
            return self::collectionResponse([
                $this->news($pid * 10 + 1, '2026-09-14T08:00:00Z', ['_links' => ['project' => ['href' => '/api/v3/projects/' . $pid, 'title' => 'P']]]),
                $this->news($pid * 10 + 2, '2026-09-01T08:00:00Z', ['_links' => ['project' => ['href' => '/api/v3/projects/' . $pid, 'title' => 'P']]]),
            ], 2, 'Collection');
        });

        $r = $s->feed('alice', ['team-a', 'team-b'], self::NOW - 7 * 86400, self::NOW, 20, ['13'], mirror: false);

        $ids = array_map(static fn (array $row) => $row['news']['id'], $r['items']);
        $this->assertSame([131], $ids, 'inside the week, project 13 only');
        $this->assertCount(2, $r['projects'], 'the facet still offers both projects');
    }

    public function testAForeignProjectRowIsDroppedByTheBelt(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->news(1, '2026-09-14T08:00:00Z', ['_links' => ['project' => ['href' => '/api/v3/projects/99', 'title' => 'Other']]]),
        ]));
        $this->assertSame([], $s->feed('alice', ['team-a'], 0, 0, 20, mirror: false)['items']);
    }

    public function testAForbiddenProjectIsPartialAndAnUnconnectedViewerIsNotAnError(): void {
        $s = $this->service(['team-a' => ['projectId' => 12], 'team-b' => ['projectId' => 13]], function (string $endpoint, array $params): array {
            $filters = json_decode($params['filters'] ?? '[]', true);
            if (($filters[0]['project_id']['values'][0] ?? '') === '13') {
                return ['error' => 'Forbidden', 'statusCode' => 403, 'message' => 'no'];
            }
            return self::collectionResponse([$this->news(1, '2026-09-14T08:00:00Z')], 1, 'Collection');
        });
        $r = $s->feed('alice', ['team-a', 'team-b'], 0, 0, 20, mirror: false);
        $this->assertSame('partial', $r['status']['state']);
        $this->assertSame('permission_denied', $r['status']['code']);
        $this->assertCount(1, $r['items']);

        $this->userValues = [];
        $s = $this->service(['team-a' => ['projectId' => 12]], connected: false);
        $this->assertSame('not_connected', $s->feed('alice', ['team-a'], 0, 0, 20)['status']['state']);
        $this->assertSame([], $this->requests);
    }

    public function testHtmlNeverReachesARow(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([
            $this->news(1, '2026-09-14T08:00:00Z', ['title' => '<img src=x onerror=alert(1)>Hi', 'summary' => 'See [here](javascript:alert(1))']),
        ]));
        $row = $s->feed('alice', ['team-a'], 0, 0, 20, mirror: false)['items'][0];
        $this->assertSame('Hi', $row['subject']);
        $this->assertSame('See here', $row['message']);
        $this->assertStringNotContainsString('<', json_encode($row));
    }

    // ── Mirroring ──────────────────────────────────────────────────────

    public function testNewsSinceTheLinkIsMirroredOnceAsASystemPostWithTheSummaryAlone(): void {
        // The mirror's age rule runs on the real clock, so these are relative.
        $stamp = static fn (int $daysAgo): string => gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400);
        $s = $this->service(['team-a' => ['projectId' => 12, 'createdAt' => time() - 5 * 86400, 'createdBy' => 'creator']], $this->answering([
            $this->news(5, $stamp(1)),                                     // after the link: mirrored
            $this->news(4, $stamp(20)),                                    // before the link: not mirrored
        ]));

        $r = $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertCount(2, $r['items'], 'the feed still shows both');
        $this->assertCount(1, $this->written);
        $m = $this->written[0];
        $this->assertSame('team-a', $m['teamId']);
        $this->assertSame('creator', $m['author'], 'the OpenProject author has no Nextcloud account: the team creator posts');
        $this->assertSame('OpenProject news: News 5', $m['subject']);
        // v4.9.9 — the summary and nothing else: the source is the card's
        // pill, from the ledger, not two lines of body text.
        $this->assertSame('Summary of 5', $m['body']);
        $this->assertStringNotContainsString('OpenProject', $m['body']);
        $this->assertFalse($m['isPublic']);
        $this->assertTrue($m['isSystem']);

        // A second read, by another member, writes nothing more.
        $s->feed('bob', ['team-a'], 0, 0, 20);
        $this->assertCount(1, $this->written);
    }

    public function testTheOpenProjectAuthorsOwnAccountPostsWhenConnected(): void {
        $this->connectedAccounts = ['9' => 'carol'];
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([$this->news(5, gmdate('Y-m-d\TH:i:s\Z', time() - 86400))]));
        $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame('carol', $this->written[0]['author']);
    }

    public function testAMissingMappedAccountFallsBackToTheCreator(): void {
        $this->connectedAccounts = ['9' => 'ghost'];
        $s = $this->service(['team-a' => ['projectId' => 12, 'createdBy' => 'creator']], $this->answering([$this->news(5, gmdate('Y-m-d\TH:i:s\Z', time() - 86400))]));
        $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame('creator', $this->written[0]['author']);
    }

    public function testOldNewsIsNotMirroredEvenIfNewerThanTheLink(): void {
        $s = $this->service(['team-a' => ['projectId' => 12, 'createdAt' => self::NOW - 400 * 86400]], $this->answering([
            $this->news(5, gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400)),   // 40 days old: past MIRROR_MAX_AGE_DAYS
            $this->news(6, gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 86400)),    // fresh
        ]));
        $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertCount(1, $this->written);
        $this->assertSame('OpenProject news: News 6', $this->written[0]['subject']);
    }

    public function testAFailedWriteReleasesTheSlotSoTheNextReadTriesAgain(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([$this->news(7, gmdate('Y-m-d\TH:i:s\Z', time() - 86400), ['title' => 'boom'])]));
        $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertSame([], $this->written);
        $this->assertSame([], $this->ledger, 'the claim was released');
    }

    public function testAnUnreadableLedgerCostsTheCopyNotTheFeed(): void {
        $this->ledgerBroken = true;
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([$this->news(5, gmdate('Y-m-d\TH:i:s\Z', time() - 86400))]));
        $r = $s->feed('alice', ['team-a'], 0, 0, 20);
        $this->assertCount(1, $r['items']);
        $this->assertSame([], $this->written);
    }

    public function testMirrorForTeamWritesWithoutReturningRows(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]], $this->answering([$this->news(5, gmdate('Y-m-d\TH:i:s\Z', time() - 86400))]));
        $this->assertSame(1, $s->mirrorForTeam('alice', 'team-a'));
        $this->assertSame(0, $s->mirrorForTeam('alice', 'team-a'), 'idempotent');
        $this->assertSame(0, $s->mirrorForTeam('alice', 'team-z'), 'no link, nothing');
    }

    // ── The Source pill (v4.9.9) ───────────────────────────────────────

    public function testMirroredMessagesAreStampedWithTheirOriginAndItsLink(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]]);
        $this->origins = [
            41 => ['newsId' => 5, 'connection' => md5(self::HOST)],
            42 => ['newsId' => 6, 'connection' => md5('https://previous.test')],
        ];
        $rows = [['id' => 41, 'message' => 'x'], ['id' => 42, 'message' => 'y'], ['id' => 43, 'message' => 'z'], ['message' => 'no id']];

        $s->stampOrigins($rows);

        $this->assertSame(['kind' => 'openproject', 'newsId' => 5, 'url' => self::HOST . '/news/5'], $rows[0]['origin']);
        // Mirrored from another host: the label stays, the link does not.
        $this->assertSame(['kind' => 'openproject', 'newsId' => 6, 'url' => null], $rows[1]['origin']);
        $this->assertArrayNotHasKey('origin', $rows[2]);
        $this->assertArrayNotHasKey('origin', $rows[3]);
    }

    public function testAnUnreadableLedgerLeavesTheRowsAlone(): void {
        $s = $this->service(['team-a' => ['projectId' => 12]]);
        $this->origins      = [41 => ['newsId' => 5, 'connection' => md5(self::HOST)]];
        $this->ledgerBroken = true;
        $rows = [['id' => 41, 'message' => 'x']];
        $s->stampOrigins($rows);
        $this->assertSame([['id' => 41, 'message' => 'x']], $rows);
    }
}
