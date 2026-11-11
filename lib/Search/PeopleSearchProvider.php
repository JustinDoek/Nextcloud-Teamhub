<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\PeopleDirectoryService;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Collaboration profiles in Nextcloud's unified search (v4.13.63).
 *
 * ## Why this exists
 *
 * The People module has collected *Expertise*, *Ask me about*, *Languages* and
 * *Preferred contact* since 4.13.41 and rendered them on exactly one surface:
 * that person's own profile page. There was no way to ask **who knows
 * Postgres** or **who speaks Danish** -- the module gathered the data and gave
 * nobody a way to use it. Somebody looking for an expert types into the search
 * box, not into a page they first have to find (the same sentence
 * `ServiceSearchProvider` was written for).
 *
 * ## The security contract, and it is one gate
 *
 * Matching happens against `PeopleDirectoryService::profiles()`, which is the
 * **same call** the standalone page and the section on the Nextcloud profile
 * page go through. That gate owns the module switch, the signed-in viewer, the
 * enabled account and the `shareHobbies` veto, and `PeopleProfileService`'s
 * docblock requires that any people-directory feature use explicit field-level
 * disclosure rules rather than read the stored JSON itself. A second copy of
 * those rules here is exactly the drift §2.219 removed, so there is not one.
 *
 * **The SQL below is a prefilter, never the authorisation.** It matches the raw
 * preference JSON, which still holds a field whose owner has un-shared it. Such
 * a candidate reaches `profiles()`, comes back without that field, matches
 * nothing visible, and is dropped. The order matters: narrow in SQL, decide in
 * the gate.
 *
 * ## What is searchable, and what deliberately is not
 *
 * The free text a person typed about themselves. **Department and usual
 * workplace are rendered in the subline but are not search keys**: both are
 * stored as identifiers rather than names, and each is findable as itself --
 * a department *is* a team, which `TeamSearchProvider` already answers for.
 * Resolving every stored id on every keystroke to make "Finance" match forty
 * people would also be the wrong answer to that search.
 *
 * Display names are not matched either. Nextcloud core already searches users;
 * this section adds what core cannot see.
 */
class PeopleSearchProvider implements IProvider {

    /**
     * Candidates taken from the prefilter before the gate runs.
     *
     * Bounds the per-candidate work (one user lookup and one preference read
     * each) on an instance where a common word appears in thousands of
     * profiles. What the searcher sees is `ISearchQuery::getLimit()`; this is
     * only the size of the pool it is drawn from.
     */
    private const CANDIDATE_CAP = 500;

    /** Below this, a term matches most of the table and means nothing. */
    private const MIN_TERM_LENGTH = 2;

    public function __construct(
        private PeopleDirectoryService $directory,
        private IDBConnection          $db,
        private IURLGenerator          $urlGenerator,
        private IL10N                  $l,
    ) {
    }

    public function getId(): string {
        return 'teamhub-people';
    }

    public function getName(): string {
        return $this->l->t('TeamHub people');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 54;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return SearchResult::complete($this->getName(), []);
        }

        $needle = mb_strtolower($term);
        $limit  = max(1, $query->getLimit());

        // The gate. Returns an empty map when the People module is off or the
        // viewer is not signed in, so an instance with People disabled gets no
        // section at all rather than an error.
        $profiles = $this->directory->profiles($this->candidates($needle));

        $entries = [];
        foreach ($profiles as $userId => $profile) {
            $matched = $this->matchingFields($profile['fields'] ?? [], $needle);
            if ($matched === []) {
                continue;
            }
            $entries[] = new SearchResultEntry(
                '',                                        // thumbnailUrl
                (string)($profile['displayName'] ?? $userId), // title
                $this->subline($matched),                  // subline
                $this->urlGenerator->linkToRoute(
                    'teamhub.peoplePublic.profile',
                    ['userId' => $userId],
                ),
                'icon-teamhub',
            );
            if (count($entries) >= $limit) {
                break;
            }
        }

        // Never paginated: the candidate pool is read in one pass, so there is
        // no cursor that would mean anything on the next call.
        return SearchResult::complete($this->getName(), $entries);
    }

    /**
     * User ids whose stored profile *might* contain the term.
     *
     * A prefilter, not a decision -- see the class docblock. The table is
     * `oc_preferences` on every supported Nextcloud version; never probe for
     * `user_preferences`, which does not exist and whose failed query aborts a
     * Postgres transaction (HANDOFF, 2026-09-28).
     *
     * Protected rather than private so a test can substitute the pool and
     * assert the property that matters -- that the gate, not this query,
     * decides what comes back. Nothing in `tests/Unit` mocks a query builder
     * chain, and this is not the test that should be the first to.
     *
     * @return list<string>
     */
    protected function candidates(string $needle): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('userid')
            ->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('people_collaboration_profile_v1')))
            ->andWhere($qb->expr()->iLike(
                'configvalue',
                $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%'),
            ))
            ->setMaxResults(self::CANDIDATE_CAP);

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (string)$row['userid'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * The published fields whose value contains the term, as "Label: value".
     *
     * Only values are matched. Matching the label too would make a search for
     * "languages" return everybody who filled that field in.
     *
     * @param list<array{label: string, value: string}> $fields
     * @return list<string>
     */
    private function matchingFields(array $fields, string $needle): array {
        $matched = [];
        foreach ($fields as $field) {
            $value = (string)($field['value'] ?? '');
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                $matched[] = (string)($field['label'] ?? '') . ': ' . $value;
            }
        }
        return $matched;
    }

    /**
     * Why this person came back, in one line.
     *
     * Two fields at most: the subline is a line of a drawer, and a profile
     * with every field filled in would otherwise push the next result off the
     * screen.
     *
     * @param list<string> $matched
     */
    private function subline(array $matched): string {
        $shown = array_slice($matched, 0, 2);
        $line  = implode(' · ', $shown);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 159) . '…' : $line;
    }
}
