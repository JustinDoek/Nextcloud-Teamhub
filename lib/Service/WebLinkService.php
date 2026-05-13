<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\Db\WebLinkMapper;
use OCP\IUserSession;

class WebLinkService {
    private WebLinkMapper $webLinkMapper;
    private IUserSession $userSession;

    public function __construct(
        WebLinkMapper $webLinkMapper,
        IUserSession $userSession
    ) {
        $this->webLinkMapper = $webLinkMapper;
        $this->userSession = $userSession;
    }

    /**
     * Get all web links for a team
     */
    public function getTeamLinks(string $teamId): array {
        return $this->webLinkMapper->findByTeamId($teamId);
    }

    /**
     * Create a new web link
     */
    public function createLink(string $teamId, string $title, string $url): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $url = $this->normaliseUrl(trim($url));

        if (empty(trim($title))) {
            throw new \Exception('Title cannot be empty');
        }

        return $this->webLinkMapper->create($teamId, trim($title), $url);
    }

    /**
     * Update a web link
     */
    public function updateLink(int $linkId, string $title, string $url, int $sortOrder): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $url = $this->normaliseUrl(trim($url));

        return $this->webLinkMapper->update($linkId, $title, $url, $sortOrder);
    }

    /**
     * Normalise and validate a URL entered by the user.
     *
     * Accepted inputs:
     *   - https://...              kept as-is (external link, opens in new tab)
     *   - http://...               kept as-is (external link)
     *   - /apps/...                kept as-is (NC-relative, opens in iframe)
     *   - /index.php/...           kept as-is (NC-relative, opens in iframe)
     *   - apps/collectives/s5      normalised to /apps/collectives/s5 (leading slash added)
     *   - index.php/...            normalised to /index.php/... (leading slash added)
     *
     * Rejected:
     *   - javascript:, data:, file:// and any other scheme
     *   - empty string
     *
     * @throws \Exception if the URL format is not acceptable
     */
    private function normaliseUrl(string $url): string {
        if ($url === '') {
            throw new \Exception('URL cannot be empty');
        }

        // Already absolute with a known safe scheme.
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \Exception('Invalid URL provided');
            }
            return $url;
        }

        // Already a rooted NC-relative path.
        if (str_starts_with($url, '/apps/') || str_starts_with($url, '/index.php/')) {
            return $url;
        }

        // Relative NC path without leading slash — add it.
        if (str_starts_with($url, 'apps/') || str_starts_with($url, 'index.php/')) {
            return '/' . $url;
        }

        throw new \Exception('URL must start with https://, http://, /apps/, or apps/');
    }

    /**
     * Delete a web link
     */
    public function deleteLink(int $linkId): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('User not authenticated');
        }

        $this->webLinkMapper->delete($linkId);
    }
}
