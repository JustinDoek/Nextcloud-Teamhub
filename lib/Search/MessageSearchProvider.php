<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\Db\MessageMapper;
use OCA\TeamHub\Mentions\MentionParser;
use OCA\TeamHub\Service\MessageService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Registers TeamHub messages with Nextcloud's unified search.
 *
 * Security contract (v4.20.27):
 *   The query only ever reads teams from `MessageService::getMessageSearchTeamIds()`:
 *   the searcher's teams, direct and through a group or a sub-team, minus
 *   teams pending deletion and teams with Messages switched off. The team list
 *   is a WHERE clause, not a filter over fetched rows. Until 4.20.27 it was the
 *   latter, with a direct `circles_member` lookup per team, which returned
 *   nothing to a member through a group and could return an empty page while
 *   matches existed further on.
 *
 * Extensibility notes:
 *   - To add comment search, add a second IProvider (e.g. CommentSearchProvider)
 *     and register it separately. Do NOT add comment logic to this class.
 *   - NC passes the next page's offset back as $query->getCursor().
 */
class MessageSearchProvider implements IProvider {

    /** Characters of body text shown under a result. */
    private const SNIPPET_LENGTH = 120;

    public function __construct(
        private MessageMapper  $messageMapper,
        private MessageService $messageService,
        private IUserManager   $userManager,
        private IURLGenerator  $urlGenerator,
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
        $term   = trim($query->getTerm());
        $limit  = max(1, $query->getLimit());
        $cursor = $query->getCursor();
        $offset = is_numeric($cursor) ? max(0, (int)$cursor) : 0;

        if ($term === '') {
            return SearchResult::complete($this->getName(), []);
        }

        $teamIds = $this->messageService->getMessageSearchTeamIds($user->getUID());
        if ($teamIds === []) {
            return SearchResult::complete($this->getName(), []);
        }

        $rows    = $this->messageMapper->search($term, $teamIds, $limit, $offset);
        $entries = array_map(fn (array $row): SearchResultEntry => $this->rowToEntry($row), $rows);

        // Every row is readable, so a full page means there may be another.
        if (count($rows) >= $limit) {
            return SearchResult::paginated($this->getName(), $entries, $offset + count($rows));
        }
        return SearchResult::complete($this->getName(), $entries);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a raw message DB row to a SearchResultEntry.
     *
     * - title    — the subject, or the start of the body when there is none
     * - subline  — "Author · Team · body", the body as plain text
     * - resource — deep link into TeamHub opening the team at the message
     * - icon     — `icon-teamhub` (css/search.css via UnifiedSearchStyleListener)
     */
    private function rowToEntry(array $row): SearchResultEntry {
        $subject  = trim((string)($row['subject'] ?? ''));
        $authorId = (string)($row['author_id'] ?? '');
        $teamId   = (string)($row['team_id'] ?? '');
        $teamName = (string)($row['team_name'] ?? '');
        $snippet  = $this->snippet($this->plainText((string)($row['message'] ?? '')));

        // v4.20.27 — the author's name, not their uid: on an instance whose
        // uids are e-mail addresses the subline showed an address.
        $author = $authorId !== '' ? ($this->userManager->getDisplayName($authorId) ?? $authorId) : '';

        $parts = array_filter([$author, $teamName], static fn (string $p): bool => $p !== '');
        if ($subject !== '' && $snippet !== '') {
            $parts[] = $snippet;
        }
        $title = $subject !== '' ? $subject : $snippet;

        // Deep link: /apps/teamhub?team={teamId}&message={id} — the shape
        // App.vue's consumeDeepLink() reads (v4.8.7 added `message`, so the
        // reader lands on the thread). v4.10.4: until then this was the
        // `#/team/` hash, which nothing consumes.
        $resourceUrl = $this->urlGenerator->linkToRoute('teamhub.page.index', [
            'team'    => $teamId,
            'message' => (string)($row['id'] ?? ''),
        ]);

        return new SearchResultEntry(
            '',
            $title,
            implode(' · ', $parts),
            $resourceUrl,
            'icon-teamhub',
        );
    }

    /**
     * A message body as one line of plain text: mentions as `@Display name`,
     * markdown decoration, images and HTML gone, whitespace collapsed.
     *
     * v4.20.27 — the subline used to be the stored markdown, so a result read
     * `@"jdoek@example.org" **Kick-off** ![](…)`.
     */
    private function plainText(string $body): string {
        if ($body === '') {
            return '';
        }

        // Mentions first, while their quotes are still there. Longest
        // candidate first so `@jan` cannot eat the start of `@jansen`.
        $candidates = MentionParser::candidates($body);
        usort($candidates, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($candidates as $candidate) {
            $name = $this->userManager->getDisplayName($candidate);
            if ($name === null || $name === '') {
                continue;
            }
            $body = str_replace('@"' . $candidate . '"', '@' . $name, $body);
            $body = (string)preg_replace(
                '/(?<![\w@])@' . preg_quote($candidate, '/') . '(?![\w@\-])/u',
                '@' . addcslashes($name, '\\$'),
                $body
            );
        }

        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Images go entirely; links keep their text.
        $text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $text);
        $text = (string)preg_replace('/\[([^\]]*)\]\((?:[^()]*|\([^()]*\))*\)/u', '$1', $text);
        // Heading, quote and list markers at the start of a line.
        $text = (string)preg_replace('/^[ \t]*(?:#{1,6}|>|[-*+]|\d+\.)[ \t]+/mu', '', $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function snippet(string $text): string {
        if (mb_strlen($text) <= self::SNIPPET_LENGTH) {
            return $text;
        }
        $cut   = mb_substr($text, 0, self::SNIPPET_LENGTH);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int)(self::SNIPPET_LENGTH * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " \t\n\r.,;:") . '…';
    }
}
