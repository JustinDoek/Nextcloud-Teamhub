<?php
declare(strict_types=1);

namespace OCA\TeamHub\Teams;

use OCA\TeamHub\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Teams\ITeamResourceProvider;
use OCP\Teams\TeamResource;
use Psr\Log\LoggerInterface;

/**
 * TeamHub as a team resource provider (v4.10.1).
 *
 * `OCP\Teams\ITeamResourceProvider` (since Nextcloud 29) is how Nextcloud
 * asks an app "what do you have that belongs to team X?". Core's
 * `GET /teams/{teamId}/resources` fans out to every registered provider, and
 * that endpoint is what Nextcloud's own Teams page, the team popover in the
 * contacts menu and — on 35 — the Teams dashboard widget render. Talk, Deck,
 * Calendar, Collectives and Team folders each answer with their resource;
 * TeamHub answers with the one thing it has for every team: the team home.
 *
 * One resource per team, whose id *is* the team id — so "which teams is this
 * resource shared with" is the team itself. Core establishes the caller's
 * membership before it asks (`TeamManager::getSharedWith()` probes
 * `mustBeMember()`, and `getTeamsForResource()` filters through the caller's
 * own teams), so nothing here widens what a user can see: a non-member gets
 * no answer from core to begin with.
 *
 * Nothing is stored and no frontend is involved; registered in
 * `Application::register()`.
 */
class TeamHubResourceProvider implements ITeamResourceProvider {

    public function __construct(
        private IDBConnection   $db,
        private IURLGenerator   $urlGenerator,
        private IL10N           $l,
        private LoggerInterface $logger,
    ) {}

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('TeamHub');
    }

    /**
     * The TeamHub mark as a single-colour glyph (`img/app-dark.svg`'s
     * geometry in `currentColor`, so it takes the surrounding text colour
     * wherever Nextcloud inlines it).
     */
    public function getIconSvg(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<g fill="currentColor">'
            . '<circle cx="36" cy="10" r="5.5"/>'
            . '<rect x="29" y="19" width="15" height="14" rx="7"/>'
            . '<circle cx="20" cy="14" r="8"/>'
            . '<rect x="8" y="25" width="24" height="24" rx="12"/>'
            . '<rect x="-4.25" y="29.25" width="76" height="9" rx="4.5" transform="rotate(-45 33.75 33.75)"/>'
            . '<g stroke="currentColor" stroke-width="3" stroke-linecap="round" fill="none">'
            . '<line x1="47" y1="48" x2="35" y2="57"/><line x1="47" y1="48" x2="57" y2="37"/><line x1="47" y1="48" x2="58" y2="59"/>'
            . '</g>'
            . '<circle cx="35" cy="57" r="3.8"/><circle cx="57" cy="37" r="3.8"/><circle cx="58" cy="59" r="3.8"/><circle cx="47" cy="48" r="6.5"/>'
            . '</g></svg>';
    }

    /**
     * The team's home in TeamHub, as one resource. An empty list when the
     * circle is unknown to us (deleted between core's lookup and ours).
     *
     * @return TeamResource[]
     */
    public function getSharedWith(string $teamId): array {
        $name = $this->teamName($teamId);
        if ($name === null) {
            return [];
        }
        return [
            new TeamResource(
                $this,
                $teamId,
                // TRANSLATORS: %s is a team name; the label of the team's TeamHub page on Nextcloud's own Teams page
                $this->l->t('%s in TeamHub', [$name]),
                $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?team=' . urlencode($teamId),
                iconSvg: $this->getIconSvg(),
            ),
        ];
    }

    public function isSharedWithTeam(string $teamId, string $resourceId): bool {
        return $resourceId === $teamId && $this->teamName($teamId) !== null;
    }

    /** @return string[] */
    public function getTeamsForResource(string $resourceId): array {
        return $this->teamName($resourceId) !== null ? [$resourceId] : [];
    }

    /**
     * The circle's display name, or null when there is no such circle — or
     * when it is not a TeamHub team (v4.10.6): a circle made in another app
     * has no home in TeamHub, so the Teams page gets no row for it.
     */
    protected function teamName(string $teamId): ?string {
        if ($teamId === '') {
            return null;
        }
        try {
            $qb  = $this->db->getQueryBuilder();
            $res = $qb->select('c.name', 'c.display_name', 'c.sanitized_name')
                ->from('circles_circle', 'c')
                ->innerJoin('c', 'teamhub_team_registry', 'treg', $qb->expr()->eq('treg.team_id', 'c.unique_id'))
                ->where($qb->expr()->eq('c.unique_id', $qb->createNamedParameter($teamId)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            if ($row === false) {
                return null;
            }
            $name = (string)($row['display_name'] ?? '');
            if ($name === '') {
                $name = (string)($row['sanitized_name'] ?? '');
            }
            if ($name === '') {
                $raw  = (string)($row['name'] ?? '');
                $name = str_starts_with($raw, 'app:circles:') ? substr($raw, strlen('app:circles:')) : $raw;
            }
            return $name !== '' ? $name : $teamId;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][TeamHubResourceProvider] team lookup failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }
}
