<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Service\MemberService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Registers TeamHub messages with Nextcloud's unified search.
 *
 * Security contract:
 *   A search result is only returned when the searching user has a non-zero
 *   level in the circles_member table for the message's team_id. This is
 *   enforced per-result after the DB query, using the same fast indexed
 *   lookup that all other TeamHub endpoints use (MemberService::getMemberLevelFromDb).
 *
 * Extensibility notes:
 *   - To add comment search, add a second IProvider (e.g. CommentSearchProvider)
 *     and register it separately. Do NOT add comment logic to this class.
 *   - To support pagination, NC passes $query->getCursor() — this is already
 *     wired up below via $offset.
 *   - To add team-name display in the result subtitle, join circles_circle
 *     in MessageMapper::search() and pass it through here.
 */
class MessageSearchProvider implements IProvider {

    public function __construct(
        private MessageMapper  $messageMapper,
        private MemberService  $memberService,
        private IDBConnection  $db,
        private IURLGenerator  $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // IProvider contract
    // -------------------------------------------------------------------------

    public function getId(): string {
        return 'teamhub-messages';
    }

    public function getName(): string {
        return 'TeamHub Messages';
    }

    /**
     * The order weight relative to other providers.
     * NC uses this to sort providers in the search results UI.
     * 50 = neutral (same as most built-in NC providers).
     */
    public function getOrder(string $route, array $routeParameters): int {
        return 50;
    }

    /**
     * Run the search and return only results the user is authorised to see.
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term   = $query->getTerm();
        $limit  = $query->getLimit();
        $cursor = $query->getCursor();
        $offset = is_numeric($cursor) ? (int)$cursor : 0;

        if (trim($term) === '') {
            return SearchResult::complete($this->getName(), []);
        }

        // ── 1. Query matching messages from the DB ────────────────────────────
        // We fetch a slightly larger set than $limit so we can filter by
        // membership without always hitting the DB twice. If after filtering
        // we still have $limit results, there may be more — return a cursor.
        $fetchLimit = $limit + 20;
        $rows = $this->messageMapper->search($term, $fetchLimit, $offset);

        // ── 2. Filter by membership — only keep teams the user belongs to ─────
        // getMemberLevelFromDb() is a direct indexed query — cheap per call.
        // We cache per team_id within this request to avoid redundant queries
        // when multiple messages belong to the same team.
        $membershipCache = [];
        $entries         = [];

        foreach ($rows as $row) {
            $teamId = $row['team_id'];

            if (!array_key_exists($teamId, $membershipCache)) {
                $level = $this->memberService->getMemberLevelFromDb($this->db, $teamId, $user->getUID());
                $membershipCache[$teamId] = $level > 0;
            }

            if (!$membershipCache[$teamId]) {
                continue; // user is not a member of this team — skip
            }

            $entries[] = $this->rowToEntry($row);

            if (count($entries) >= $limit) {
                break;
            }
        }

        // ── 3. Return result with or without a next-page cursor ───────────────
        // If we collected $limit entries and the raw DB returned at least that
        // many rows, there could be more — pass a cursor for the next page.
        // If we got fewer, we've exhausted the results.
        $hasMore = count($rows) >= $fetchLimit && count($entries) >= $limit;

        if ($hasMore) {
            $nextCursor = (string)($offset + count($rows));
            return SearchResult::paginated($this->getName(), $entries, $nextCursor);
        }

        return SearchResult::complete($this->getName(), $entries);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a raw message DB row to a SearchResultEntry.
     *
     * SearchResultEntry constructor: (string $thumbnailUrl, string $title,
     *   string $subline, string $resourceUrl, string $icon, bool $rounded)
     *
     * - $thumbnailUrl  — empty string (messages have no thumbnail)
     * - $title         — message subject
     * - $subline       — author + truncated body
     * - $resourceUrl   — deep link into TeamHub opening the correct team
     * - $icon          — icon CSS class shown next to the result
     * - $rounded       — false (not an avatar/person result)
     */
    private function rowToEntry(array $row): SearchResultEntry {
        $subject  = $row['subject'] ?? '';
        $body     = $row['message'] ?? '';
        $authorId = $row['author_id'] ?? '';
        $teamId   = $row['team_id'] ?? '';
        $teamName = $row['team_name'] ?? '';

        // Subline: "Author in Team Name — first 120 chars of body"
        $bodySnippet = mb_strlen($body) > 120
            ? mb_substr($body, 0, 119) . '…'
            : $body;

        $subline = $authorId;
        if ($teamName) {
            $subline .= ' · ' . $teamName;
        }
        if ($bodySnippet) {
            $subline .= ' — ' . $bodySnippet;
        }

        // Deep link: /apps/teamhub#team={teamId}
        // The hash route is how TeamHub's Vue router navigates to a team.
        $resourceUrl = $this->urlGenerator->linkToRoute('teamhub.page.index')
            . '#/team/' . urlencode($teamId);

        return new SearchResultEntry(
            '',          // thumbnailUrl — no thumbnail for messages
            $subject,    // title
            $subline,    // subline
            $resourceUrl // resourceUrl
        );
    }
}
