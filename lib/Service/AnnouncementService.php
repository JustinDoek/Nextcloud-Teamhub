<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * In-app announcements for unlicensed instances.
 *
 * Registry (source-controlled JSON at announcements/registry.json) maps a
 * markdown filename → { role, version }. A message is visible to a given
 * user iff (a) the instance is unlicensed, (b) the installed TeamHub
 * version equals the registry entry's version, (c) the user's role is
 * at least the entry's role, (d) the user has not dismissed it.
 *
 * The role scale is two-level: 'admin' → NC instance admin only,
 * 'everyone' → any authenticated user on this instance.
 *
 * Filename lookups go through the registry as a whitelist — see
 * loadMarkdownBody(). No user input touches the filesystem path.
 */
class AnnouncementService {

    private const REGISTRY_FILE = 'announcements/registry.json';
    private const CONTENT_DIR   = 'announcements';
    private const MAX_MD_BYTES  = 512 * 1024;

    public function __construct(
        private IAppManager     $appManager,
        private LicenseService  $licenseService,
        private IGroupManager   $groupManager,
        private IDBConnection   $db,
        private ITimeFactory    $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List announcements this user can currently see, minus the ones
     * already dismissed.
     *
     * @return list<array{filename:string,role:string,version:string}>
     */
    public function listVisibleFor(string $userId): array {
        if (!$this->isInstanceUnlicensed()) {
            return [];
        }

        $registry = $this->loadRegistry();
        if ($registry === []) {
            return [];
        }

        $currentVersion = $this->appManager->getAppVersion(Application::APP_ID);
        $isAdmin        = $this->groupManager->isAdmin($userId);
        $dismissed      = $this->loadDismissed($userId);

        $visible = [];
        foreach ($registry as $entry) {
            if ($entry['version'] !== $currentVersion) {
                continue;
            }
            if ($entry['role'] === 'admin' && !$isAdmin) {
                continue;
            }
            if (isset($dismissed[$entry['filename']])) {
                continue;
            }
            $visible[] = $entry;
        }
        return $visible;
    }

    /**
     * Full markdown body for a given announcement, or null if the user
     * is not allowed to read it (unknown filename, wrong version, wrong
     * role, licensed instance).
     */
    public function getBodyFor(string $userId, string $filename): ?string {
        $entry = $this->findVisibleEntry($userId, $filename);
        if ($entry === null) {
            return null;
        }
        return $this->loadMarkdownBody($entry['filename']);
    }

    public function dismiss(string $userId, string $filename): bool {
        $entry = $this->findVisibleEntry($userId, $filename);
        if ($entry === null) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('teamhub_announce_read')
            ->values([
                'user_id'      => $qb->createNamedParameter($userId),
                'filename'     => $qb->createNamedParameter($entry['filename']),
                'dismissed_at' => $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (\Throwable $e) {
            // Unique-key collision → already dismissed. Treat as success.
        }
        return true;
    }

    /**
     * Resolve a filename against the same visibility rules as
     * listVisibleFor(), but INCLUDING already-dismissed rows — needed
     * so the canvas view can render the body of a message the user
     * chose to re-open after dismissing.
     *
     * @return array{filename:string,role:string,version:string}|null
     */
    private function findVisibleEntry(string $userId, string $filename): ?array {
        if (!$this->isInstanceUnlicensed()) {
            return null;
        }

        $registry = $this->loadRegistry();
        if ($registry === []) {
            return null;
        }

        $currentVersion = $this->appManager->getAppVersion(Application::APP_ID);
        $isAdmin        = $this->groupManager->isAdmin($userId);

        foreach ($registry as $entry) {
            if ($entry['filename'] !== $filename) {
                continue;
            }
            if ($entry['version'] !== $currentVersion) {
                return null;
            }
            if ($entry['role'] === 'admin' && !$isAdmin) {
                return null;
            }
            return $entry;
        }
        return null;
    }

    private function isInstanceUnlicensed(): bool {
        try {
            $status = $this->licenseService->getStatus();
        } catch (\Throwable $e) {
            // Same fails-open posture as the frontend: if we cannot tell,
            // assume unlicensed so the announcement channel keeps working.
            return true;
        }
        return ($status['enforcementLevel'] ?? 'unlicensed') === 'unlicensed';
    }

    /**
     * @return list<array{filename:string,role:string,version:string}>
     */
    private function loadRegistry(): array {
        $path = $this->appManager->getAppPath(Application::APP_ID)
            . DIRECTORY_SEPARATOR . self::REGISTRY_FILE;
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['announcements']) || !is_array($decoded['announcements'])) {
            return [];
        }

        $entries = [];
        foreach ($decoded['announcements'] as $row) {
            if (!is_array($row)) continue;
            $filename = (string) ($row['filename'] ?? '');
            $role     = (string) ($row['role']     ?? '');
            $version  = (string) ($row['version']  ?? '');
            if ($filename === '' || $role === '' || $version === '') continue;
            // Defense in depth: filenames may never contain path separators or
            // parent-dir hops even though we always resolve against the
            // whitelisted registry key rather than user input.
            if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
                $this->logger->warning('[TeamHub][AnnouncementService] Rejected registry entry with suspicious filename', ['filename' => $filename]);
                continue;
            }
            if ($role !== 'admin' && $role !== 'everyone') continue;
            $entries[] = ['filename' => $filename, 'role' => $role, 'version' => $version];
        }
        return $entries;
    }

    private function loadMarkdownBody(string $filename): ?string {
        // Filename came from the registry (whitelist) but re-validate the
        // shape before touching the filesystem — cheap and prevents a bad
        // registry edit from turning into a traversal.
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return null;
        }
        $path = $this->appManager->getAppPath(Application::APP_ID)
            . DIRECTORY_SEPARATOR . self::CONTENT_DIR
            . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_MD_BYTES) {
            return null;
        }
        $body = @file_get_contents($path, false, null, 0, self::MAX_MD_BYTES);
        return $body === false ? null : $body;
    }

    /**
     * @return array<string,true> filename → true for every dismissal row
     */
    private function loadDismissed(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('filename')
            ->from('teamhub_announce_read')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $out = [];
        while ($row = $result->fetch()) {
            $out[(string) $row['filename']] = true;
        }
        $result->closeCursor();
        return $out;
    }
}
