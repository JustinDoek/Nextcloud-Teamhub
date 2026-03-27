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

        $url = trim($url);
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new \Exception('URL must start with http:// or https://');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid URL provided');
        }

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

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid URL provided');
        }

        return $this->webLinkMapper->update($linkId, $title, $url, $sortOrder);
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
