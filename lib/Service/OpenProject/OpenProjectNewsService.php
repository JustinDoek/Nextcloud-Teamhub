<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Db\OpenProjectNewsMirrorMapper;
use OCA\TeamHub\Db\TeamOpenProjectLink;
use OCA\TeamHub\Db\TeamOpenProjectLinkMapper;
use OCA\TeamHub\Exception\OpenProjectException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * OpenProject news in What's new, and mirrored into the team's stream
 * (v4.9.7, Phase 3 — Justin's review, 2026-09-14).
 *
 * "What's new is for news and messages." The first draft put work-package
 * edits there; this service replaces them with what OpenProject itself
 * calls news: the announcements a project manager writes for the whole
 * project. Two things happen with a news item, both triggered by a
 * connected member reading — TeamHub holds no OpenProject token (§3.2), so
 * nothing runs in the background:
 *
 *   1. **It appears in the feed**, read live as the viewer (`GET
 *      /api/v3/news`, `project_id = [id]`, newest first), one card per
 *      item, the same source status block as every other feed source.
 *   2. **It is mirrored once into the team's message stream** as a System
 *      post — subject "OpenProject news: {title}", the summary, and a
 *      footer naming OpenProject and the author with the link — so members
 *      without an OpenProject account read it too, and it stays. The
 *      ledger (`teamhub_op_news_mirror`) makes "once" true across members
 *      and across loads; the author is the OpenProject author's Nextcloud
 *      account when they have connected one, otherwise the team's creator
 *      (the milestone auto-post's rule, DESIGN §2.47). Only news written
 *      **after the team was linked** and inside MIRROR_MAX_AGE_DAYS is
 *      mirrored, so linking a project with years of news does not drop
 *      years of posts into the stream.
 *
 * Every read runs as the viewer; a project the viewer cannot see costs
 * nothing and mirrors nothing — the copy is made by somebody OpenProject
 * showed the item to. Text is plain (`OpenProjectNormalizer::news()`),
 * bounded, and interpolated as text everywhere it lands.
 */
class OpenProjectNewsService {

    public const SOURCE = 'openproject';

    /** Most linked projects one feed load reads. */
    public const PROJECT_CAP = 10;
    /** This source's own wall-clock budget per feed load, in milliseconds. */
    public const BUDGET_MS = 2500;
    /** "All time" in the feed means this many days for OpenProject. */
    public const MAX_WINDOW_DAYS = 90;
    /** Most news items read per project per load. */
    public const NEWS_LIMIT = 25;
    /** Only news younger than this is mirrored into the stream. */
    public const MIRROR_MAX_AGE_DAYS = 30;
    /** Most news items mirrored per team per load. */
    public const MIRROR_BATCH = 10;

    public function __construct(
        private OpenProjectClient           $client,
        private OpenProjectCache            $cache,
        private TeamOpenProjectLinkMapper   $linkMapper,
        private OpenProjectNewsMirrorMapper $mirrorMapper,
        private MessageMapper               $messageMapper,
        private OpenProjectSyncHealth       $health,
        private OpenProjectMessages         $messages,
        private IConfig                     $config,
        private IUserManager                $userManager,
        private IFactory                    $l10nFactory,
        private LoggerInterface             $logger,
    ) {
    }

    /**
     * Is the source worth asking at all for this viewer — cheap, no HTTP.
     * `null` when it is; otherwise the status block the feed reports.
     *
     * @return ?array{state: string, code: ?string, message: ?string}
     */
    public function availability(string $userId): ?array {
        if (!$this->client->isIntegrationEnabled() || $this->client->getHost() === '') {
            return ['state' => 'unavailable', 'code' => null, 'message' => null];
        }
        if (!$this->client->isUserConnected($userId)) {
            return ['state' => 'not_connected', 'code' => OpenProjectException::USER_NOT_CONNECTED, 'message' => null];
        }
        return null;
    }

    /**
     * The news of the projects the viewer's teams are linked to, inside
     * `[$from, $to]` (unix seconds; 0 = unbounded, which for OpenProject
     * means the last MAX_WINDOW_DAYS), as feed rows — and, as a side
     * effect of being read, mirrored into each team's stream when
     * `$mirror` is on.
     *
     * @param string[] $teamIds    the viewer's membership set — the authorisation boundary
     * @param string[] $projectIds restrict to these OpenProject project ids ([] = all)
     * @return array{
     *   items: list<array<string, mixed>>,
     *   status: array{state: string, code: ?string, message: ?string, covered: int, skipped: int},
     *   projects: list<array{id: string, name: string, teamId: string, count: int}>
     * }
     */
    public function feed(string $userId, array $teamIds, int $from, int $to, int $limit, array $projectIds = [], bool $mirror = true): array {
        $empty = static fn (array $status): array => ['items' => [], 'status' => $status + ['covered' => 0, 'skipped' => 0], 'projects' => []];

        if ($teamIds === []) {
            return $empty(['state' => 'skipped', 'code' => null, 'message' => null]);
        }
        $unavailable = $this->availability($userId);
        if ($unavailable !== null) {
            return $empty($unavailable);
        }

        $now   = time();
        $floor = $now - self::MAX_WINDOW_DAYS * 86400;
        $from  = max($from, $floor);
        if ($to > 0 && $to < $from) {
            return $empty(['state' => 'ok', 'code' => null, 'message' => null]);
        }
        $limit = max(1, min($limit, self::NEWS_LIMIT));

        $links = $this->usableLinks($teamIds);
        if ($links === []) {
            return $empty(['state' => 'ok', 'code' => null, 'message' => null]);
        }

        $startedAt = microtime(true);
        $items     = [];
        $covered   = 0;
        $skipped   = 0;
        $codes     = [];
        $budgetHit = false;
        $seen      = 0;

        foreach ($links as $teamId => $link) {
            if ($seen >= self::PROJECT_CAP) {
                break;
            }
            if ((microtime(true) - $startedAt) * 1000 >= self::BUDGET_MS) {
                $budgetHit = true;
                break;
            }
            $seen++;

            try {
                $news = $this->forProject($userId, (string)$teamId, $link, $limit);
            } catch (OpenProjectException $e) {
                $codes[] = $e->getErrorCode();
                $skipped++;
                $this->logger->debug('[TeamHub][WhatsNew][OpenProject] news read skipped', [
                    'code' => $e->getErrorCode(), 'app' => Application::APP_ID,
                ]);
                if ($e->getErrorCode() === OpenProjectException::AUTH_FAILED) {
                    break;
                }
                continue;
            } catch (\Throwable $e) {
                $codes[] = OpenProjectException::TEMPORARY_FAILURE;
                $skipped++;
                $this->logger->warning('[TeamHub][WhatsNew][OpenProject] news read failed', [
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                continue;
            }
            $covered++;

            if ($mirror) {
                $this->mirror($userId, (string)$teamId, $link, $news);
            }

            foreach ($news as $n) {
                $row = $this->row((string)$teamId, $link, $n, $from, $to);
                if ($row !== null) {
                    $items[$row['id']] = $row;
                }
            }
        }

        $this->health->recordRun(OpenProjectSyncHealth::CHANNEL_ACTIVITY, $covered, $skipped, $codes, $budgetHit);

        // Facets before the project narrowing, so the rail's project list
        // still offers a project the reader has just filtered out.
        $projects = [];
        foreach ($items as $row) {
            $pid = (string)$row['project']['id'];
            if (!isset($projects[$pid])) {
                $projects[$pid] = ['id' => $pid, 'name' => (string)$row['project']['name'], 'teamId' => (string)$row['team_id'], 'count' => 0];
            }
            $projects[$pid]['count']++;
        }
        usort($projects, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        if ($projectIds !== []) {
            $items = array_filter($items, static fn (array $row): bool => in_array((string)$row['project']['id'], $projectIds, true));
        }
        $items = array_values($items);
        usort($items, static fn (array $a, array $b): int => ($b['created_at'] <=> $a['created_at']) ?: strcmp($a['id'], $b['id']));

        $state = 'ok';
        $code  = null;
        if (in_array(OpenProjectException::AUTH_FAILED, $codes, true)) {
            $state = 'auth_required';
            $code  = OpenProjectException::AUTH_FAILED;
        } elseif ($covered === 0 && $skipped > 0) {
            $state = 'error';
            $code  = (string)end($codes);
        } elseif ($skipped > 0 || $budgetHit) {
            $state = 'partial';
            $code  = $codes !== [] ? (string)end($codes) : null;
        }

        return [
            'items'    => $items,
            'status'   => [
                'state'   => $state,
                'code'    => $code,
                'message' => $code !== null ? $this->messages->userMessage($code) : null,
                'covered' => $covered,
                'skipped' => $skipped,
            ],
            'projects' => array_values($projects),
        ];
    }

    /**
     * Mirror one team's news the team home's read found (the Project info
     * widget calls this so a team whose feed nobody opens still gets its
     * posts). Never throws.
     *
     * @return int how many messages were written
     */
    public function mirrorForTeam(string $userId, string $teamId): int {
        try {
            $links = $this->usableLinks([$teamId]);
            $link  = $links[$teamId] ?? null;
            if ($link === null) {
                return 0;
            }
            $news = $this->forProject($userId, $teamId, $link, self::NEWS_LIMIT);
            return $this->mirror($userId, $teamId, $link, $news);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][OpenProjectNews] mirrorForTeam skipped', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The project's news, newest first, as the viewer. Cached per user for
     * TTL_MY_WORK.
     *
     * @param array{projectId: int, host: string, projectIdentifier: string, projectName: string, createdAt: int, createdBy: string} $link
     * @return list<array<string, mixed>> normalised, each with `url`
     * @throws OpenProjectException
     */
    public function forProject(string $userId, string $teamId, array $link, int $limit = self::NEWS_LIMIT): array {
        $limit     = max(1, min($limit, self::NEWS_LIMIT));
        $projectId = (int)$link['projectId'];
        $host      = (string)$link['host'];

        $key    = $this->cache->key('news:' . $limit, $userId, $teamId, $projectId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->client->get($userId, 'news', [
            'filters'  => json_encode([
                ['project_id' => ['operator' => '=', 'values' => [(string)$projectId]]],
            ], JSON_THROW_ON_ERROR),
            'sortBy'   => json_encode([['created_at', 'desc']], JSON_THROW_ON_ERROR),
            'pageSize' => $limit,
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'news did not return a collection');
        }

        $items = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'News') {
                continue;
            }
            $n = OpenProjectNormalizer::news($element);
            if ($n['id'] <= 0) {
                continue;
            }
            // OpenProject filtered by project; the belt keeps a foreign row
            // out should the filter be ignored by a version we do not know.
            if (($n['project']['id'] ?? $projectId) !== $projectId) {
                continue;
            }
            $n['url'] = $this->client->newsUrl($n['id']);
            $items[]  = $n;
        }

        $this->cache->set($key, $items, OpenProjectCache::TTL_MY_WORK);
        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mirroring
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Copy the news items not yet in the team's stream, oldest first, at
     * most MIRROR_BATCH per call. Only news written after the link was made
     * and inside MIRROR_MAX_AGE_DAYS. Never throws: a copy that fails is
     * logged, its slot released, and the next read tries again.
     *
     * @param array<string, mixed> $link
     * @param list<array<string, mixed>> $news
     * @return int messages written
     */
    private function mirror(string $userId, string $teamId, array $link, array $news): int {
        $connection = md5((string)$link['host']);
        $linkedAt   = (int)($link['createdAt'] ?? 0);
        $notBefore  = max($linkedAt, time() - self::MIRROR_MAX_AGE_DAYS * 86400);

        $candidates = [];
        foreach ($news as $n) {
            $createdAt = $n['createdAt'] !== null ? (int)strtotime((string)$n['createdAt']) : 0;
            if ($createdAt <= 0 || $createdAt < $notBefore) {
                continue;
            }
            $candidates[(int)$n['id']] = $n + ['createdTs' => $createdAt];
        }
        if ($candidates === []) {
            return 0;
        }

        try {
            $done = $this->mirrorMapper->mirroredIds($teamId, $connection, array_keys($candidates));
        } catch (\Throwable $e) {
            // A missing table (upgrade not yet applied) costs the copy, not
            // the feed.
            $this->logger->warning('[TeamHub][OpenProjectNews] mirror ledger unreadable', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return 0;
        }
        foreach ($done as $id) {
            unset($candidates[$id]);
        }
        if ($candidates === []) {
            return 0;
        }
        uasort($candidates, static fn (array $a, array $b): int => $a['createdTs'] <=> $b['createdTs']);
        $candidates = array_slice($candidates, 0, self::MIRROR_BATCH, true);

        $written = 0;
        foreach ($candidates as $newsId => $n) {
            $ledgerId = null;
            try {
                $ledgerId = $this->mirrorMapper->claim($teamId, $connection, (int)$newsId, $userId);
                if ($ledgerId === null) {
                    continue; // somebody else got there first
                }
                $message = $this->messageMapper->create(
                    $teamId,
                    $this->authorFor($n, $link),
                    $this->subjectFor($n, $link),
                    $this->bodyFor($n),
                    'normal',
                    'normal',
                    null,
                    false,
                    true,
                );
                $this->mirrorMapper->attachMessage($ledgerId, (int)($message['id'] ?? 0));
                $written++;
            } catch (\Throwable $e) {
                if ($ledgerId !== null) {
                    try {
                        $this->mirrorMapper->release($ledgerId);
                    } catch (\Throwable) {
                        // The slot stays claimed with message_id 0; the row
                        // is harmless and visible to an administrator.
                    }
                }
                $this->logger->warning('[TeamHub][OpenProjectNews] news could not be mirrored', [
                    'teamId' => $teamId, 'newsId' => (int)$newsId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
        return $written;
    }

    /**
     * The mirrored post's author: the OpenProject author's Nextcloud account
     * when they have connected one (the official app stores their OpenProject
     * user id per account), otherwise whoever linked the team.
     *
     * @param array<string, mixed> $n
     * @param array<string, mixed> $link
     */
    private function authorFor(array $n, array $link): string {
        $opUserId = $n['authorId'] ?? null;
        if ($opUserId !== null && (int)$opUserId > 0) {
            try {
                $uids = $this->config->getUsersForUserValue(OpenProjectClient::INTEGRATION_APP_ID, 'user_id', (string)(int)$opUserId);
                foreach ($uids as $uid) {
                    if ($this->userManager->userExists((string)$uid)) {
                        return (string)$uid;
                    }
                }
            } catch (\Throwable) {
                // Fall through to the link's creator.
            }
        }
        return (string)$link['createdBy'];
    }

    /** @param array<string, mixed> $n */
    private function subjectFor(array $n, array $link): string {
        $l = $this->l10nFor($this->authorFor($n, $link));
        // TRANSLATORS: subject of a team message that mirrors an OpenProject news item; %s is the news title
        return mb_substr($l->t('OpenProject news: %s', [(string)$n['title']]), 0, 255);
    }

    /**
     * Summary or excerpt — the news item's own words and nothing else.
     * Markdown, which is what the stream renders; the text itself is plain
     * and any `*` or `_` in it is OpenProject's, already stripped by the
     * normaliser.
     *
     * The first draft closed with two footer lines ("Posted in OpenProject
     * by … in the project …", "Read it in OpenProject: …"). Justin's review
     * (2026-09-14) replaced them with a *Source: OpenProject* pill the card
     * draws from the ledger (`stampOrigins()`), so the body carries no
     * provenance of its own — a URL in the text would be a second link to
     * the same place, and one that outlives a host change.
     *
     * @param array<string, mixed> $n
     */
    private function bodyFor(array $n): string {
        $summary = (string)($n['summary'] ?? '');
        return $summary !== '' ? $summary : (string)($n['excerpt'] ?? '');
    }

    /**
     * Mark the mirrored posts in a page of message rows: `origin` becomes
     * `{kind: 'openproject', newsId, url}` on each row the ledger knows,
     * and the card renders it as *Source: OpenProject* with the pill linking
     * to the item (v4.9.9). The URL is built from the configured host and
     * the numeric id, never from stored text — and only while the ledger's
     * connection is the current host: a post mirrored from a previous
     * OpenProject keeps its label and loses its link, rather than pointing
     * at a page that is not there.
     *
     * Rows without an `id`, or from a page the ledger cannot answer for
     * (upgrade not yet applied), are left as they are; the stamp is a
     * decoration, never a condition for showing the message.
     *
     * @param array<int, array<string, mixed>> $rows message rows, by reference
     */
    public function stampOrigins(array &$rows): void {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        try {
            $origins = $this->mirrorMapper->findByMessageIds($ids);
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][OpenProjectNews] mirror ledger unreadable for origins', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return;
        }
        if ($origins === []) {
            return;
        }
        $current = md5($this->client->getHost());
        foreach ($rows as &$row) {
            $origin = $origins[(int)($row['id'] ?? 0)] ?? null;
            if ($origin === null) {
                continue;
            }
            $row['origin'] = [
                'kind'   => 'openproject',
                'newsId' => $origin['newsId'],
                'url'    => $origin['connection'] === $current ? $this->client->newsUrl($origin['newsId']) : null,
            ];
        }
        unset($row);
    }

    private function l10nFor(string $uid): \OCP\IL10N {
        $lang = 'en';
        try {
            $user = $this->userManager->get($uid);
            if ($user !== null) {
                $lang = $this->l10nFactory->getUserLanguage($user);
            }
        } catch (\Throwable) {
            // English.
        }
        return $this->l10nFactory->get(Application::APP_ID, $lang);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rows + links
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One news item as a feed row, or null when outside the window.
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $n
     * @return ?array<string, mixed>
     */
    private function row(string $teamId, array $link, array $n, int $from, int $to): ?array {
        $createdAt = $n['createdAt'] !== null ? (int)strtotime((string)$n['createdAt']) : 0;
        if ($createdAt <= 0 || $createdAt < $from || ($to > 0 && $createdAt > $to)) {
            return null;
        }
        $projectRef = (string)($link['projectIdentifier'] ?? '') !== ''
            ? (string)$link['projectIdentifier']
            : (string)(int)$link['projectId'];
        $connection = substr(md5((string)$link['host']), 0, 12);

        return [
            'id'           => 'op:' . $connection . ':' . (int)$link['projectId'] . ':news:' . (int)$n['id'],
            'source'       => self::SOURCE,
            'activityType' => 'news',
            'subject'      => (string)$n['title'],
            // The card shows the summary as plain text; a news item is
            // written for the whole project to read.
            'message'      => (string)(($n['summary'] ?? '') !== '' ? $n['summary'] : ($n['excerpt'] ?? '')),
            'created_at'   => $createdAt,
            'team_id'      => $teamId,
            // Not a Nextcloud account — the card must not draw an avatar.
            'author_id'    => '',
            'actor_name'   => $n['author'] ?? null,
            'project'      => [
                'id'   => (string)(int)$link['projectId'],
                'name' => (string)($link['projectName'] ?? ($n['project']['name'] ?? '')),
                'url'  => $this->client->projectUrl($projectRef),
            ],
            'news'         => [
                'id'  => (int)$n['id'],
                'url' => $n['url'] ?? null,
            ],
            'opens_externally' => true,
        ];
    }

    /**
     * The links of the viewer's teams that point at the configured host, in
     * a deterministic order (team id).
     *
     * @param string[] $teamIds
     * @return array<string, array{projectId: int, projectIdentifier: string, projectName: string, host: string, createdAt: int, createdBy: string}>
     */
    private function usableLinks(array $teamIds): array {
        $host = $this->client->getHost();
        $out  = [];
        try {
            foreach ($this->linkMapper->findByTeams($teamIds) as $teamId => $row) {
                if ($host === '' || rtrim($row->getHost(), '/') !== $host) {
                    continue;
                }
                $out[(string)$teamId] = self::linkArray($row);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][OpenProjectNews] link lookup failed', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array{projectId: int, projectIdentifier: string, projectName: string, host: string, createdAt: int, createdBy: string} */
    private static function linkArray(TeamOpenProjectLink $row): array {
        return [
            'projectId'         => $row->getProjectId(),
            'projectIdentifier' => $row->getProjectIdentifier(),
            'projectName'       => $row->getProjectName(),
            'host'              => $row->getHost(),
            'createdAt'         => $row->getCreatedAt(),
            'createdBy'         => $row->getCreatedBy(),
        ];
    }
}
