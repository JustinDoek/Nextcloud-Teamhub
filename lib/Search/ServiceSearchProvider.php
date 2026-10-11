<?php
declare(strict_types=1);

namespace OCA\TeamHub\Search;

use OCA\TeamHub\Service\ServiceTeam\ServiceCatalogueReader;
use OCA\TeamHub\Service\ServiceTeam\ServiceTeamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * The service catalog in Nextcloud's unified search (v4.11.11).
 *
 * Somebody who needs a shared folder types "shared folder" into the search
 * box, not into a page they first have to find.
 *
 * Security contract: the entries are `ServiceCatalogueReader::forUser()`'s,
 * which is the same list the Services page renders for this person — the
 * per-viewer cards (the quota request, more time for a team) are already
 * filtered there. Matching happens **after** that, never against services
 * the searcher may not start.
 *
 * With the licence or the administrator's module switch missing there is no
 * section at all, not an error: the feature is absent, not explained.
 *
 * Filtering in PHP rather than in SQL is deliberate. A catalog is tens of
 * entries, it is already built per viewer by the rules above, and a query
 * would have to reproduce those rules to stay honest.
 */
class ServiceSearchProvider implements IProvider {

    public function __construct(
        private ServiceCatalogueReader $catalogue,
        private ServiceTeamService     $serviceTeams,
        private IURLGenerator          $urlGenerator,
        private IL10N                  $l,
    ) {
    }

    public function getId(): string {
        return 'teamhub-services';
    }

    public function getName(): string {
        return $this->l->t('TeamHub services');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 52;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());
        if ($term === '' || !$this->serviceTeams->isAvailable()) {
            return SearchResult::complete($this->getName(), []);
        }

        $needle  = mb_strtolower($term);
        $limit   = max(1, $query->getLimit());
        $entries = [];

        foreach ($this->catalogue->forUser($user->getUID()) as $service) {
            if (!$this->matches($service, $needle)) {
                continue;
            }
            $entries[] = new SearchResultEntry(
                '',
                (string)($service['label'] ?? ''),
                $this->subline($service),
                // The catalog is where a service is started; the card is not
                // separately addressable, so the link opens the page.
                $this->urlGenerator->linkToRoute('teamhub.page.index', ['services' => '1']),
                'icon-teamhub',
            );
            if (count($entries) >= $limit) {
                break;
            }
        }

        // Never paginated: the whole catalog is read in one pass, so there is
        // no cursor that would mean anything on the next call.
        return SearchResult::complete($this->getName(), $entries);
    }

    /** @param array<string, mixed> $service */
    private function matches(array $service, string $needle): bool {
        foreach (['label', 'description', 'serviceTeamName', 'categoryLabel'] as $field) {
            $value = (string)($service[$field] ?? '');
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $service */
    private function subline(array $service): string {
        $parts = array_filter([
            (string)($service['serviceTeamName'] ?? ''),
            (string)($service['categoryLabel'] ?? ''),
            (string)($service['leadTime'] ?? ''),
        ], static fn (string $part): bool => $part !== '');
        return implode(' · ', $parts);
    }
}
