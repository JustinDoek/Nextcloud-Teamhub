<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * In-app announcements for unlicensed instances.
 *
 * Registry (source-controlled JSON at announcements/registry.json) maps a
 * markdown filename → { role, version }. A message is visible to a given
 * user iff (a) the instance is unlicensed, (b) the installed TeamHub
 * version matches the registry entry's version ({@see versionMatches()}),
 * (c) the user's role is at least the entry's role, (d) the user has not
 * dismissed it.
 *
 * The role scale is two-level: 'admin' → NC instance admin only,
 * 'everyone' → any authenticated user on this instance.
 *
 * Filename lookups go through the registry as a whitelist — see
 * loadMarkdownBody(). No user input touches the filesystem path.
 */
class AnnouncementService {

    /**
     * Whether a registry entry's version covers the running version (v4.20.0).
     *
     * A full version (`4.20.0`) matches only itself. A shorter one names a
     * release line: `4.20` matches `4.20`, `4.20.0`, `4.20.7`, and never
     * `4.2.x` or `4.200`, because the match is on whole dot-separated
     * segments. Justin's choice for the LTS release, so one announcement
     * keeps showing through its patch releases instead of vanishing at the
     * first one. Dismissal is keyed by filename, so somebody who closed it on
     * 4.20.0 does not see it again on 4.20.1.
     */
    public static function versionMatches(string $entryVersion, string $currentVersion): bool {
        $entryVersion = trim($entryVersion);
        if ($entryVersion === '' || $currentVersion === '') {
            return false;
        }
        return $currentVersion === $entryVersion
            || str_starts_with($currentVersion, $entryVersion . '.');
    }

    private const REGISTRY_FILE = 'announcements/registry.json';
    private const CONTENT_DIR   = 'announcements';
    private const MAX_MD_BYTES  = 512 * 1024;

    /**
     * App config key: the popup's counters, as JSON
     * `{ "<filename>": { "shown": n, "read": n, "ack": n, "optout": n } }`.
     *
     * v4.13.56 — read into the telemetry payload as `announcement_popup`.
     * Counts only, per announcement, no user and no content. The question it
     * exists to answer is Justin's: whether an in-app message to Nextcloud
     * administrators reaches anybody at all, which four years of zero replies
     * could not distinguish from four years of nobody looking.
     */
    public const KEY_POPUP_STATS = 'announce_popup_stats';

    /**
     * User preference key: which popup events this user has already been
     * counted for, as JSON `{ "<filename>": { "shown": true, … } }`.
     *
     * The counters above are incremented from a client POST, so without a
     * per-user ledger one administrator reloading the page twenty times would
     * read as twenty instances paying attention. Each user contributes at most
     * one of each event per announcement.
     */
    public const PREF_POPUP_SEEN = 'announce_popup_seen';

    /** The four things the popup reports. Anything else is a bad request. */
    public const POPUP_EVENTS = ['shown', 'read', 'ack', 'optout'];

    public function __construct(
        private IAppManager     $appManager,
        private LicenseService  $licenseService,
        private IGroupManager   $groupManager,
        private IDBConnection   $db,
        private ITimeFactory    $timeFactory,
        private IConfig         $config,
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
            if (!self::versionMatches($entry['version'], $currentVersion)) {
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
     * Record one popup event for one user, once.
     *
     * v4.13.56. Four events, in the order a reader produces them:
     *
     *  - `shown`  the popup was put in front of this administrator;
     *  - `read`   they scrolled it to the end, which is what unlocks the
     *             controls, so this is the one that means "it was read" rather
     *             than "it was displayed";
     *  - `ack`    they pressed OK, so it comes back at the next sign-in;
     *  - `optout` they ticked the box as well, so it never comes back.
     *
     * Returns false and counts nothing when the filename is not an
     * announcement this user may see, when the event is not one of the four,
     * or when this user has already been counted for it. The caller treats
     * all three the same: a counter is not worth an error path on a page the
     * administrator is trying to read.
     *
     * Deliberately not gated on the popup's own audience rule (admin-role
     * announcements only). The service decides what a user may *see*; which
     * surface shows it is the frontend's choice, and a second copy of that
     * rule here would be one more thing to keep in step.
     */
    public function recordPopupEvent(string $userId, string $filename, string $event): bool {
        if (!in_array($event, self::POPUP_EVENTS, true)) {
            return false;
        }
        if ($this->findVisibleEntry($userId, $filename) === null) {
            return false;
        }

        $seen = $this->loadPopupSeen($userId);
        if (!empty($seen[$filename][$event])) {
            return false;
        }

        $seen[$filename][$event] = true;
        try {
            $this->config->setUserValue(
                $userId,
                Application::APP_ID,
                self::PREF_POPUP_SEEN,
                json_encode($seen, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            // The ledger is what stops double counting, so a write we cannot
            // confirm must not be followed by an increment.
            $this->logger->warning('[TeamHub][AnnouncementService] Could not record a popup event for this user', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->bumpPopupCounter($filename, $event);
        return true;
    }

    /**
     * The popup counters, for the telemetry payload and the admin preview.
     *
     * @return array<string,array<string,int>> filename => event => count
     */
    public function popupStats(): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_POPUP_STATS, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Re-shaped on the way out rather than trusted: this value is written
        // by this class alone, but it is a config string and a hand edit on an
        // instance should not be able to put a non-integer into a telemetry
        // payload.
        $clean = [];
        foreach ($decoded as $filename => $events) {
            if (!is_string($filename) || !is_array($events)) {
                continue;
            }
            $row = [];
            foreach (self::POPUP_EVENTS as $event) {
                $row[$event] = (int) ($events[$event] ?? 0);
            }
            $clean[$filename] = $row;
        }
        return $clean;
    }

    /** @return array<string,array<string,bool>> */
    private function loadPopupSeen(string $userId): array {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, self::PREF_POPUP_SEEN, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function bumpPopupCounter(string $filename, string $event): void {
        try {
            $stats = $this->popupStats();
            $stats[$filename][$event] = (int) ($stats[$filename][$event] ?? 0) + 1;
            foreach (self::POPUP_EVENTS as $known) {
                $stats[$filename][$known] = (int) ($stats[$filename][$known] ?? 0);
            }
            $this->config->setAppValue(
                Application::APP_ID,
                self::KEY_POPUP_STATS,
                json_encode($stats, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            // Telemetry must never affect the request path — same posture as
            // TelemetryService::incrementSuggestWizardUses().
            $this->logger->warning('[TeamHub][AnnouncementService] Could not bump a popup counter', [
                'error' => $e->getMessage(),
            ]);
        }
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
            if (!self::versionMatches($entry['version'], $currentVersion)) {
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
