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
     */
    public function createPage(string $teamId, string $teamName): array {

        if (!$this->appManager->isInstalled('intravox')) {
            return ['skipped' => true, 'detail' => 'IntraVox not installed'];
        }

        try {
            ['parentPath' => $parentPath, 'slug' => $slug] = $this->getPathConfig($teamName);


            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);

            $data   = ['id' => $slug, 'title' => $teamName];

            $result = $pageService->createPage($data, $parentPath);

            $pageId = $result['id'] ?? $result['uniqueId'] ?? null;

            return ['page_created' => true, 'page_id' => $pageId];

        } catch (\Throwable $e) {
            $this->logger->error('[IntravoxService] createPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['error' => 'IntraVox page creation failed: ' . $e->getMessage()];
        }
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
                $pageTitle    = strtolower($page['title'] ?? '');

                if ($pageTitle === strtolower($teamName) && !str_starts_with($pageUniqueId, 'template-')) {
                    $matchedUniqueId = $pageUniqueId;
                    $this->logger->warning('[IntravoxService] deletePage: matched by title', [
                        'title'    => $page['title'],
                        'uniqueId' => $matchedUniqueId,
                        'slug'     => $slug,
                        'app'      => Application::APP_ID,
                    ]);
                    break;
                }
            }

            if ($matchedUniqueId === null) {
                $this->logger->warning('[IntravoxService] deletePage: no matching page found for team', [
                    'teamName' => $teamName, 'app' => Application::APP_ID,
                ]);
                return ['deleted' => true, 'detail' => 'No IntraVox page found for this team'];
            }

            // deletePage() expects the slug, not the uniqueId.
            // We generate the slug from the team name — same logic as createPage().
            $this->logger->warning('[IntravoxService] deletePage: calling deletePage(slug=' . $slug . ')', [
                'app' => Application::APP_ID,
            ]);
            $pageService->deletePage($slug);
            $this->logger->warning('[IntravoxService] deletePage: success', ['app' => Application::APP_ID]);

            return ['deleted' => true, 'detail' => 'IntraVox page ' . $slug . ' deleted'];

        } catch (\Throwable $e) {
            $this->logger->error('[IntravoxService] deletePage failed', [
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
            $pageService = $this->container->get(\OCA\IntraVox\Service\PageService::class);
            $pages       = $pageService->listPages();

            // Find the team page uniqueId by title
            $teamUniqueId = null;
            foreach ($pages as $page) {
                if (strtolower($page['title'] ?? '') === strtolower($teamName)
                    && !str_starts_with($page['uniqueId'] ?? '', 'template-')) {
                    $teamUniqueId = $page['uniqueId'];
                    break;
                }
            }

            if (!$teamUniqueId) {
                return [];
            }

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
            $this->logger->warning('[IntravoxService] getSubPages failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return [];
        }
    }

    /**
     * Invalidate the sub-pages cache for a team.
     * Call this after creating or deleting a page so the next load is fresh.
     */
    public function invalidateSubPagesCache(string $teamId): void {
        $this->cache->remove('teamhub_intravox_subpages_' . $teamId);
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
