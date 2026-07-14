<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * IntravoxService — create and delete IntraVox pages for TeamHub teams.
 *
 * Uses IntraVox's own PageService in-process via NC's DI container.
 * No HTTP calls — avoids the NC28+ loopback block entirely.
 *
 * Confirmed IntraVox PageService signatures (from PHP reflection on installed version):
 *   listPages(): array
 *   getPage(string $id): array
 *   createPage(array $data, ?string $parentPath = null): array
 *   deletePage(string $id): void   — $id is the slug, NOT uniqueId
 *   pageExistsByUniqueId(string $uniqueId): bool
 *   createPageFromTemplate(string $templateId, string $pageTitle, ?string $parentPath = null): array
 *   getPageTree(?string $currentPageId = null, ?string $language = null): array
 */
class IntravoxService {

    private \OCP\ICache $cache;

    public function __construct(
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createLocal();
    }

    public function isInstalled(): bool {
        return $this->appManager->isInstalled('intravox');
    }

    /**
     * Shared helper: read the admin-configured parentPath and derive the slug.
     */
    private function getPathConfig(string $teamName): array {
        $ncConfig   = $this->container->get(\OCP\IConfig::class);
        $parentPath = $ncConfig->getAppValue('teamhub', 'intravoxParentPath', 'en/teamhub');
        $slug       = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $teamName), '-'));
        return ['parentPath' => $parentPath, 'slug' => $slug];
    }

    /**
     * Create an IntraVox page for a newly enabled team.
     *
     * Confirmed signature: createPage(array $data, ?string $parentPath = null): array
     * parentPath is the SECOND argument — NOT inside $data.
     *
     * $projectMode (Project Teams, v3.88.x-3.90.x): when 'advanced', the page is
     * titled "Contract" (not the team name) and seeded with the 9-element
     * project-definition contract (buildProjectContractLayout). Confirmed
     * empirically (via the intravox-diagnostic write-path probe) that
     * createPage()'s $data['layout'] is silently ignored — the page is created
     * with an empty layout regardless. Content must be set via a FOLLOW-UP
     * updatePage($pageId, $data) call. updatePage() rebuilds the whole page from
     * $data rather than partially merging — its internal validateAndSanitizePage()
     * unconditionally calls sanitizeText($data['title']) with no null-check, so
     * $data MUST include 'title' or it throws a TypeError. Any other mode (null
     * or 'basic') keeps today's blank-page behaviour exactly — no regression.
     */
    public function createPage(string $teamId, string $teamName, ?string $projectMode = null): array {

        if (!$this->appManager->isInstalled('intravox')) {
            return ['skipped' => true, 'detail' => 'IntraVox not installed'];
        }

        try {
            ['parentPath' => $parentPath, 'slug' => $slug] = $this->getPathConfig($teamName);


            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);

            $isAdvanced = $projectMode === 'advanced';
            $l = $isAdvanced ? $this->resolveTranslator($this->userSession->getUser()?->getUID() ?? '') : null;
            // TRANSLATORS: title of the IntraVox page auto-created for an Advanced
            // project team — the project-definition/agreement document, not a legal contract.
            $title = $isAdvanced ? (string)$l->t('Contract') : $teamName;

            $data = ['id' => $slug, 'title' => $title];

            $result = $pageService->createPage($data, $parentPath);

            $pageId = $result['id'] ?? $result['uniqueId'] ?? null;

            if ($isAdvanced && $pageId) {
                $layout = $this->buildProjectContractLayout($l);
                $pageService->updatePage($pageId, ['title' => $title, 'layout' => $layout]);
            }

            return ['page_created' => true, 'page_id' => $pageId];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IntravoxService] createPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['error' => 'IntraVox page creation failed: ' . $e->getMessage()];
        }
    }

    /**
     * "Contract" in every locale TeamHub ships (v3.90.x) — kept here rather than
     * resolved live because listPages() (unlike getPage()) returns no `path`,
     * only `title`/`uniqueId`, so there's no cheaper way to recognise an
     * Advanced project's own page than matching its title against every
     * language it could have been created in. Keep in sync with the "Contract"
     * msgid's l10n/*.json values whenever a locale is added or re-translated.
     */
    private const CONTRACT_TITLES = ['contract', 'vertrag', 'contrat', 'kontrakt', 'contrato', 'contratto'];

    /**
     * True when $title is this team's own IntraVox page — either titled after
     * the team (non-project / Basic-project pages, and Advanced pages created
     * before this rename) or "Contract" in any supported language (Advanced
     * project pages, v3.90.x+). See CONTRACT_TITLES.
     */
    private function isTeamPageTitle(string $title, string $teamName): bool {
        $lower = strtolower($title);
        return $lower === strtolower($teamName) || in_array($lower, self::CONTRACT_TITLES, true);
    }

    /**
     * Find this team's own IntraVox page, disambiguating by PATH rather than
     * title alone (v3.90.x). Title-only matching (isTeamPageTitle) was safe
     * when every page was titled after its team (inherently unique) — but
     * since Advanced projects can all share the literal title "Contract"
     * (translated), title alone is now ambiguous across teams. Confirmed in
     * testing: with two Advanced project teams, a title-only match on the
     * frontend returned whichever "Contract" page came first in listPages(),
     * regardless of which team was being viewed.
     *
     * Path is the one identifier guaranteed unique per team (folder-based:
     * {parentPath}/{slug}), but — per the same listPages() limitation noted
     * on getSubPages() — only available via getPage() (single-page fetch),
     * not listPages() (bulk, title+uniqueId only). So: shortlist by title via
     * listPages(), then confirm each candidate's real path via getPage().
     * Candidate count is bounded by how many teams share a title, typically
     * small — not a performance concern.
     *
     * @return array|null The full getPage() payload for this team's page, or null.
     */
    public function getTeamPage(string $teamId, string $teamName): ?array {
        if (!$this->appManager->isInstalled('intravox')) {
            return null;
        }

        // Cached for 5 minutes, same TTL/key style as getSubPages() — this method
        // now does up to K extra getPage() round-trips (K = candidates sharing a
        // title) that the old client-side bulk-match never paid, and it's called
        // on every IntravoxWidget mount (via the team-page endpoint) as well as
        // from getSubPages() on its own cache miss. Invalidated alongside the
        // sub-pages cache in invalidateSubPagesCache() below.
        $cacheKey = 'teamhub_intravox_teampage_' . $teamId;
        // TEMP (v3.90.1): cache READ disabled while diagnosing the path-format
        // bug below — a stale cached null from before the diagnostic logging
        // existed would otherwise return early here and silently skip it every
        // time, exactly what happened on the first retest. Cache WRITE stays
        // on so behaviour is otherwise unchanged. Re-enable the read once the
        // real path format is confirmed and getTeamPage() reliably resolves.
        $cached = null;
        if ($cached !== null) {
            // Cached value is always valid JSON we wrote ourselves — "null"
            // (no page found) or a page object — so a plain decode is safe.
            return json_decode($cached, true);
        }

        try {
            ['parentPath' => $parentPath, 'slug' => $slug] = $this->getPathConfig($teamName);
            $expectedPath = rtrim($parentPath, '/') . '/' . $slug;

            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);
            $pages = $pageService->listPages();

            $found = null;
            foreach ($pages as $page) {
                $uniqueId = $page['uniqueId'] ?? '';
                if ($uniqueId === '' || str_starts_with($uniqueId, 'template-')) {
                    continue;
                }
                if (!$this->isTeamPageTitle($page['title'] ?? '', $teamName)) {
                    continue;
                }
                try {
                    $full = $pageService->getPage($uniqueId);
                } catch (\Throwable) {
                    continue;
                }
                if (($full['path'] ?? null) === $expectedPath) {
                    $found = $full;
                    break;
                }
                // TEMP diagnostic (v3.90.1, remove once the real path format is
                // confirmed) — logs every title-matching candidate whose path
                // didn't match our computed guess, so the real getPage() 'path'
                // shape can be read from the log rather than guessed again.
                $this->logger->warning('[TeamHub][IntravoxService] getTeamPage: title matched but path did not', [
                    'teamId' => $teamId, 'teamName' => $teamName, 'uniqueId' => $uniqueId,
                    'expectedPath' => $expectedPath, 'actualPath' => $full['path'] ?? '(missing)',
                    'fullKeys' => array_keys($full), 'app' => Application::APP_ID,
                ]);
            }

            if ($found === null) {
                $this->logger->warning('[TeamHub][IntravoxService] getTeamPage: no match found', [
                    'teamId' => $teamId, 'teamName' => $teamName, 'expectedPath' => $expectedPath,
                    'candidateTitles' => array_column($pages, 'title'), 'app' => Application::APP_ID,
                ]);
            }

            $this->cache->set($cacheKey, json_encode($found), 300);
            return $found;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IntravoxService] getTeamPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Per-user language resolution — same pattern as
     * DeckService::translateDefaultStackTitles: user's NC language, falling
     * back to the instance default, then English.
     */
    private function resolveTranslator(string $uid): \OCP\IL10N {
        $config      = $this->container->get(\OCP\IConfig::class);
        $l10nFactory = $this->container->get(\OCP\L10N\IFactory::class);

        $language = $config->getUserValue($uid, 'core', 'lang', '');
        if ($language === '') {
            $language = $config->getSystemValue('default_language', 'en');
        }
        return $l10nFactory->get(Application::APP_ID, $language);
    }

    /**
     * Build the IntraVox `layout` payload for the Project-team contract page
     * (v3.88.x-3.90.x, Advanced projects only) — the 9-element project-definition
     * structure from the PMC (Projectmatig Creëren) methodology, each as a
     * collapsible FAQ-style section with a guiding question the project owner
     * fills in. $l is resolved once by the caller (createPage) and reused here
     * so the page title and its content are never in two different languages.
     *
     * Row/widget ids only need to be unique within this one freshly-created
     * page — sequential numbering is sufficient (confirmed against a real
     * IntraVox page's ids via the diagnostic probe: they need not be globally
     * unique or gapless).
     */
    private function buildProjectContractLayout(\OCP\IL10N $l): array {
        // TRANSLATORS: the 9 elements of the PMC project-definition contract — each
        // becomes a collapsible section heading, paired with a guiding question the
        // project owner answers. Source: https://pmc-online.nl/structuur/projectdefinitie/
        $elements = [
            [
                'title'    => (string)$l->t('Challenge or Problem'),
                'question' => (string)$l->t('What is the core issue this project addresses? Is this an opportunity to seize or a problem to solve? Would something genuinely go wrong if we did not act?'),
            ],
            [
                'title'    => (string)$l->t('Urgency'),
                'question' => (string)$l->t('Why now? How time-critical is this?'),
            ],
            [
                'title'    => (string)$l->t('Objective'),
                'question' => (string)$l->t('What underlying ambition does this project serve? Multiple objectives are fine, as long as they do not contradict each other.'),
            ],
            [
                'title'    => (string)$l->t('Result'),
                'question' => (string)$l->t('What will the project team concretely deliver? It must be tangible and visible — something you can hand over when the project is done.'),
            ],
            [
                'title'    => (string)$l->t('Scope'),
                'question' => (string)$l->t('What does this project explicitly not deliver? Framed negatively, to manage expectations.'),
            ],
            [
                'title'    => (string)$l->t('Effects'),
                'question' => (string)$l->t('What consequences might this project have — intended (tied to the objective), unintended-negative (risks), and unintended-positive (unplanned benefits)?'),
            ],
            [
                'title'    => (string)$l->t('Users of the end result'),
                'question' => (string)$l->t('Who benefits from this project, and who might be adversely affected? Who are the stakeholders?'),
            ],
            [
                'title'    => (string)$l->t('Constraints'),
                'question' => (string)$l->t('What non-negotiable requirements has the sponsor set — quality, budget, or schedule?'),
            ],
            [
                'title'    => (string)$l->t('Relationship to other projects and programmes'),
                'question' => (string)$l->t('Does this project depend on, or need to coordinate with, other work — to avoid duplication or increase effectiveness?'),
            ],
        ];

        $rows = [];
        $rows[] = [
            'id'              => 'row-1',
            'columns'         => 1,
            'backgroundColor' => 'var(--color-primary-element-light)',
            'widgets'         => [
                [
                    'type' => 'heading', 'column' => 1, 'order' => 1, 'id' => 'widget-1',
                    'content' => (string)$l->t('Project Contract'), 'level' => 1,
                ],
                [
                    'type' => 'text', 'column' => 1, 'order' => 2, 'id' => 'widget-2',
                    'content' => (string)$l->t('Answer the nine questions below to define this project. Each answer becomes part of the project\'s shared understanding — update them as the project evolves.'),
                ],
            ],
        ];

        // IMPORTANT: none of the title strings above may contain an apostrophe.
        // sectionTitle (like title) goes through IntraVox's sanitizeText(), which
        // HTML-encodes apostrophes to &apos; — but IntraVox's frontend renders
        // sectionTitle as plain text, so it displays the literal "&apos;" rather
        // than decoding it back to "'". Confirmed empirically (nl "programma's"
        // and fr "d'autres" both rendered broken). Question widgets are unaffected
        // (they go through sanitizeHtml(), which leaves apostrophes untouched —
        // confirmed via nl "risico's" rendering correctly) — only titles need this
        // care. Rephrase to avoid an apostrophe rather than try to escape it.
        $rowNum = 2;
        $widgetNum = 3;
        foreach ($elements as $i => $el) {
            // Numeric "N. " prefix carries no translatable words (Arabic-numeral +
            // period list numbering is the same convention in all supported
            // locales) — %1$s/%2$s used anyway per SKILLS.md's no-concatenation
            // rule, but this format string needs no l10n/*.json entry of its own.
            $sectionTitle = $l->t('%1$s. %2$s', [(string)($i + 1), $el['title']]);
            $rows[] = [
                'id'                => 'row-' . $rowNum,
                'columns'           => 1,
                'backgroundColor'   => '',
                'collapsible'       => true,
                'defaultCollapsed'  => false,
                'sectionTitle'      => (string)$sectionTitle,
                'widgets'           => [
                    [
                        'type' => 'text', 'column' => 1, 'order' => 1, 'id' => 'widget-' . $widgetNum,
                        'content' => $el['question'],
                    ],
                ],
            ];
            $rowNum++;
            $widgetNum++;
        }

        return [
            'columns'     => 1,
            'rows'        => $rows,
            'sideColumns' => [
                'left'  => ['enabled' => false, 'backgroundColor' => '', 'widgets' => []],
                'right' => ['enabled' => false, 'backgroundColor' => '', 'widgets' => []],
            ],
            'headerRow'   => ['enabled' => false, 'backgroundColor' => '', 'widgets' => []],
        ];
    }

    /**
     * Delete the IntraVox page associated with a team when the integration is disabled.
     *
     * Confirmed signature: deletePage(string $id): void
     * $id is the page SLUG (e.g. "flexiwings"), not uniqueId.
     *
     * listPages() returns pages with uniqueId and title but NOT id or path.
     * deletePage() accepts the uniqueId (page-{uuid} format).
     */
    public function deletePage(string $teamId, string $teamName): array {

        if (!$this->appManager->isInstalled('intravox')) {
            return ['deleted' => false, 'detail' => 'IntraVox not installed'];
        }

        try {
            ['parentPath' => $parentPath, 'slug' => $slug] = $this->getPathConfig($teamName);

            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);

            $pages = $pageService->listPages();

            // Match by title to confirm the page exists, but pass the SLUG to deletePage().
            // Confirmed from testing: deletePage(string $id) expects the slug (e.g. "flexiwings"),
            // NOT the uniqueId (page-{uuid}). pageExistsByUniqueId() is the separate uniqueId lookup.
            $matchedUniqueId = null;
            foreach ($pages as $page) {
                $pageUniqueId = $page['uniqueId'] ?? '';
                $pageTitle    = $page['title'] ?? '';

                if ($this->isTeamPageTitle($pageTitle, $teamName) && !str_starts_with($pageUniqueId, 'template-')) {
                    $matchedUniqueId = $pageUniqueId;
                    $this->logger->warning('[TeamHub][IntravoxService] deletePage: matched by title', [
                        'title'    => $page['title'],
                        'uniqueId' => $matchedUniqueId,
                        'slug'     => $slug,
                        'app'      => Application::APP_ID,
                    ]);
                    break;
                }
            }

            if ($matchedUniqueId === null) {
                $this->logger->warning('[TeamHub][IntravoxService] deletePage: no matching page found for team', [
                    'teamName' => $teamName, 'app' => Application::APP_ID,
                ]);
                return ['deleted' => true, 'detail' => 'No IntraVox page found for this team'];
            }

            // deletePage() expects the slug, not the uniqueId.
            // We generate the slug from the team name — same logic as createPage().
            $this->logger->warning('[TeamHub][IntravoxService] deletePage: calling deletePage(slug=' . $slug . ')', [
                'app' => Application::APP_ID,
            ]);
            $pageService->deletePage($slug);
            $this->logger->warning('[TeamHub][IntravoxService] deletePage: success', ['app' => Application::APP_ID]);

            return ['deleted' => true, 'detail' => 'IntraVox page ' . $slug . ' deleted'];

        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][IntravoxService] deletePage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['deleted' => false, 'detail' => 'Failed: ' . $e->getMessage()];
        }
    }

    /**
     * Return all sub-pages under the team's IntraVox page.
     *
     * Uses getBreadcrumb per page to find descendants — this is accurate but
     * makes N PHP calls. Results are cached in NC's local cache (APCu/Redis)
     * for 5 minutes per team so repeat loads within the same session are instant.
     *
     * Cache is invalidated automatically after 300s, or explicitly when a page
     * is created/deleted via the widget.
     *
     * Returns [{uniqueId, title, id}]
     */
    public function getSubPages(string $teamId, string $teamName): array {

        if (!$this->appManager->isInstalled('intravox')) {
            return [];
        }

        // Cache key is per-team — different teams have different sub-pages
        $cacheKey = 'teamhub_intravox_subpages_' . $teamId;
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        try {
            // Resolve the team's own page unambiguously by path (see getTeamPage()
            // docblock) rather than the old title-only first-match, which picked
            // the wrong team's page once multiple teams shared a title (e.g. every
            // Advanced project's IntraVox page is titled "Contract").
            $teamPage     = $this->getTeamPage($teamId, $teamName);
            $teamUniqueId = $teamPage['uniqueId'] ?? null;
            if (!$teamUniqueId) {
                return [];
            }

            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);
            $pages       = $pageService->listPages();

            // For each non-template page, check if team page is in its breadcrumb
            $result = [];
            foreach ($pages as $page) {
                $uid = $page['uniqueId'] ?? '';
                if (!$uid || $uid === $teamUniqueId || str_starts_with($uid, 'template-')) {
                    continue;
                }

                try {
                    $crumbs = $pageService->getBreadcrumb($uid);
                    if (!is_array($crumbs)) continue;

                    foreach ($crumbs as $crumb) {
                        if (($crumb['uniqueId'] ?? '') === $teamUniqueId) {
                            $result[] = [
                                'uniqueId' => $uid,
                                'title'    => $page['title'] ?? '',
                                'id'       => strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $page['title'] ?? ''), '-')),
                            ];
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    // skip this page
                }
            }

            // Cache for 5 minutes
            $this->cache->set($cacheKey, json_encode($result), 300);

            return $result;

        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][IntravoxService] getSubPages failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Invalidate the sub-pages cache (and the team-page cache, since a create/
     * delete can change which page getTeamPage() resolves to) for a team.
     * Call this after creating or deleting a page so the next load is fresh.
     */
    public function invalidateSubPagesCache(string $teamId): void {
        $this->cache->remove('teamhub_intravox_subpages_' . $teamId);
        $this->cache->remove('teamhub_intravox_teampage_' . $teamId);
    }


    /**
     * Flatten all descendants of skipUniqueId from a tree.
     * Handles two cases:
     *   A) tree IS the subtree rooted at the team page (getPageTree returned focused subtree)
     *   B) tree is the full IntraVox tree — find the team node first, then flatten its children
     */
    private function flattenTree(array $tree, string $skipUniqueId, array &$result): void {
        // If tree is a single node (associative with 'uniqueId' key)
        $nodes = isset($tree['uniqueId']) ? [$tree] : (array)$tree;

        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $uid = $node['uniqueId'] ?? '';

            if ($uid === $skipUniqueId) {
                // This IS the team page — flatten its children only
                foreach ($node['children'] ?? [] as $child) {
                    $this->flattenTree($child, '', $result);
                }
                return;
            }

            if ($uid && $uid !== $skipUniqueId) {
                $result[] = [
                    'uniqueId' => $uid,
                    'title'    => $node['title'] ?? '',
                    'id'       => $node['id'] ?? strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $node['title'] ?? ''), '-')),
                ];
                // Recurse into children
                foreach ($node['children'] ?? [] as $child) {
                    $this->flattenTree($child, '', $result);
                }
            } else {
                // No uid match yet — keep searching deeper
                foreach ($node['children'] ?? [] as $child) {
                    $this->flattenTree($child, $skipUniqueId, $result);
                }
            }
        }
    }
}
