<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Db\TeamAppResourceMapper;
use OCA\TeamHub\Exception\AccessDeniedException;
use OCA\TeamHub\Exception\LicenseGateException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Teams\ITeamManager;
use OCP\Teams\ITeamResourceProvider;
use OCP\Teams\TeamResource;
use Psr\Log\LoggerInterface;

/**
 * What other apps have shared with a team, read from Nextcloud's Teams API
 * (v4.20.39, the "Shared with team" card).
 *
 * TeamHub has published its team home through `ITeamResourceProvider` since
 * v4.10.1 (`TeamHubResourceProvider`). This is the other direction: every
 * provider's answer for one team, for the person looking at it.
 *
 * ── Why this does not simply call `ITeamManager::getSharedWith()` ────────
 *
 * Core's `TeamManager::getSharedWith()` (stable34 and stable35) checks team
 * support and membership and then loops over every provider **without a
 * try/catch**, so one third-party provider that throws takes every other
 * app's resources down with it. The card has to degrade to "that one app is
 * missing". So the membership answer comes from core (`getTeamsForUser()`,
 * Circles-backed, the same rule core's own probe applies) and the five-line
 * provider loop is ours, with each provider in its own try/catch. Nothing
 * about *who may see* is reimplemented: a non-member is refused before any
 * provider is asked, and a provider is asked exactly what core asks it.
 *
 * `getSharedWithList()` is not used. Core's implementation never reads its
 * `$userId` and checks no membership at all, and no view renders resources
 * for more than one team. A future one must feed it only team ids from
 * `getTeamsForUser()` for the current user, and still isolate providers.
 *
 * ── The provider contract: a provider filters its own output ─────────────
 *
 * `ITeamResourceProvider::getSharedWith(string $teamId)` receives no user id.
 * Core fans out with the team alone, so **a provider is responsible for
 * filtering what it returns for the current user** (from the session it runs
 * in), and TeamHub renders exactly what it is given. A provider whose records
 * carry their own ACLs (case files, say) must apply them there; TeamHub
 * cannot evaluate another app's ACLs and does not pretend to. Written down
 * for integrators in `developers.md` and the docs site's
 * `developer/integrations.md`.
 *
 * What TeamHub guarantees instead is that it never widens a provider's answer:
 *
 *  - the only user it ever acts for is the authenticated session user. No uid
 *    is accepted from a route, a request body, a stored row or the team's
 *    owner, and `forTeam()` takes none. No session, empty list: no guess, no
 *    fallback;
 *  - nothing is cached or memoised, per team or otherwise. Every request asks
 *    every provider afresh; the endpoint answers `Cache-Control: no-store`.
 *    If a cache is ever added, its key must include the uid and its TTL must
 *    be short;
 *  - a Nextcloud administrator can switch an individual provider off
 *    (`SharedResourceProviderSettings`), and a switched-off provider is not
 *    called at all. That is the remedy for a provider that over-shares.
 *
 * ── Read-only ────────────────────────────────────────────────────────────
 *
 * The registry (`teamhub_team_app_resources`) is read, never written, to
 * leave out two kinds of row (v4.20.42):
 *
 *  - `active`: TeamHub already shows it in its own cards;
 *  - `ignored`: a team admin decided in Manage team's resource review that it
 *    does not belong to the team. The share still exists in Nextcloud, so the
 *    provider still returns it, but the card honours the team's decision.
 *
 * Pending rows are left in, so a resource awaiting a team admin's review
 * appears here, exactly as it does on Nextcloud's own Teams page.
 *
 * ── Third-party content ──────────────────────────────────────────────────
 *
 * Labels and icons come from other apps. A URL must be relative to this
 * server, https, or http on this server's own host; anything else drops the
 * row. SVG is capped in size and the frontend draws it as an `<img>` data
 * URI, where an SVG cannot run script.
 */
class SharedTeamResourceService {

    /**
     * Provider id → TeamHub registry app id, for leaving out what TeamHub
     * already shows. Ids not listed here are never matched, so the worst a
     * wrong entry does is show a duplicate.
     *
     * Verified on claudius (NC 35, 2026-11-20): `talk` (resource id = room
     * token) and `files` (resource id = file id, the registry's file_source
     * for a share that is not a group folder) match the registry; the team
     * folder provider (`groupfolders`, "Team spaces") is recognised by its
     * interface instead, see registryKeyFor(). Registered there but switched
     * off at the time, so unverified: `deck`, `collectives`. No calendar
     * provider is registered there; `spreed`, `calendar` and `dav` are kept
     * as guesses that cost nothing if wrong.
     */
    public const PROVIDER_APPS = [
        'deck'        => 'deck',
        'talk'        => 'talk',
        'spreed'      => 'talk',
        'calendar'    => 'calendar',
        'dav'         => 'calendar',
        'collectives' => 'collectives',
        'files'       => 'files',
    ];

    /** Registry statuses whose resources the card leaves out (see the class docblock). */
    public const LEFT_OUT_STATUSES = ['active', 'ignored'];

    /** An SVG icon larger than this is dropped (the row keeps its label). */
    public const MAX_ICON_SVG_BYTES = 16384;

    /** Resources kept per provider; the card shows three until expanded. */
    public const MAX_PER_PROVIDER = 50;

    public function __construct(
        private ITeamManager          $teamManager,
        private IUserSession          $userSession,
        private TeamAppResourceMapper $resourceMapper,
        private IURLGenerator         $urlGenerator,
        private LoggerInterface       $logger,
        private SharedResourceProviderSettings $providerSettings,
        // v4.21.1 — the card is licensed (Justin, 2026-11-26).
        private ?LicenseService $licenseService = null,
    ) {}

    /**
     * For the authenticated session user, and nobody else: deliberately no
     * `$userId` parameter (see the class docblock).
     *
     * @return array{available: bool, providers: list<array>, failed: list<array{id: string, name: string}>}
     *
     * @throws AccessDeniedException when the session user is not a member
     */
    public function forTeam(string $teamId): array {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null || $uid === '') {
            return ['available' => true, 'providers' => [], 'failed' => []];
        }

        if (!$this->teamManager->hasTeamSupport()) {
            return ['available' => false, 'providers' => [], 'failed' => []];
        }

        if (!$this->isMember($teamId, $uid)) {
            throw new AccessDeniedException('You are not a member of this team');
        }
        // v4.21.1 — licensed. After membership, so a non-member learns
        // nothing about the instance's licence from the answer.
        if ($this->licenseService !== null && !$this->licenseService->isLicensed()) {
            throw new LicenseGateException($this->licenseService->getEnforcementLevel(), 'Shared with team requires an active TeamHub licence.');
        }

        try {
            $providers = $this->teamManager->getProviders();
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][SharedTeamResourceService] provider list failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return ['available' => false, 'providers' => [], 'failed' => []];
        }

        $leftOut = $this->registryKeys($teamId);

        $groups = [];
        $failed = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof ITeamResourceProvider) {
                continue;
            }
            try {
                $providerId = $provider->getId();
            } catch (\Throwable $e) {
                continue;
            }
            if ($providerId === Application::APP_ID) {
                continue;
            }
            // Switched off by an administrator: not called, not listed as failed.
            if (!$this->providerSettings->isEnabled($providerId)) {
                continue;
            }

            try {
                $name      = $provider->getName();
                $resources = $provider->getSharedWith($teamId);
                $group     = $this->group($provider, $providerId, $name, $resources, $leftOut);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][SharedTeamResourceService] provider failed', [
                    'teamId' => $teamId, 'provider' => $providerId,
                    'error' => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                $failed[] = ['id' => $providerId, 'name' => $this->safeName($provider, $providerId)];
                continue;
            }
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        usort($groups, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['available' => true, 'providers' => $groups, 'failed' => $failed];
    }

    /**
     * Core's own membership answer for the current user. Circles decides,
     * including membership through a group or a sub-team.
     */
    private function isMember(string $teamId, string $uid): bool {
        foreach ($this->teamManager->getTeamsForUser($uid) as $team) {
            if ($team->getId() === $teamId) {
                return true;
            }
        }
        return false;
    }

    /**
     * `app\0resource` for every resource the card leaves out: what TeamHub
     * shows (`active`) and what a team admin ignored (`ignored`).
     *
     * @return array<string, true>
     */
    private function registryKeys(string $teamId): array {
        $keys = [];
        try {
            foreach ($this->resourceMapper->findAllByTeam($teamId) as $row) {
                if (in_array($row->getStatus(), self::LEFT_OUT_STATUSES, true)) {
                    $keys[$row->getAppId() . "\0" . $row->getResourceId()] = true;
                }
            }
        } catch (\Throwable $e) {
            // Showing a duplicate, or an ignored resource the provider still
            // returns, is better than showing nothing.
            $this->logger->warning('[TeamHub][SharedTeamResourceService] registry read failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
        return $keys;
    }

    /**
     * The registry key a provider's resource would have, or null when the
     * provider is not one TeamHub manages resources for.
     */
    private function registryKeyFor(ITeamResourceProvider $provider, string $providerId, string $resourceId): ?string {
        // Nextcloud 35's team folder provider. The interface does not exist on
        // 34, where `instanceof` is simply false.
        if ($provider instanceof \OCP\Teams\ITeamFolderProvider) {
            return 'files' . "\0" . 'gf:' . $resourceId;
        }
        $app = self::PROVIDER_APPS[$providerId] ?? null;
        return $app === null ? null : $app . "\0" . $resourceId;
    }

    /**
     * One provider's resources as a plain array, or null when nothing is left
     * to show.
     *
     * @param array<mixed>        $resources      the provider's answer
     * @param array<string, true> $leftOut registryKeys()
     */
    private function group(ITeamResourceProvider $provider, string $providerId, string $name, array $resources, array $leftOut): ?array {
        $rows = [];
        foreach ($resources as $resource) {
            if (!$resource instanceof TeamResource) {
                continue;
            }
            $resourceId = $resource->getId();
            $key = $this->registryKeyFor($provider, $providerId, $resourceId);
            error_log('[TeamHub][SharedTeamResourceService] resource: ' . json_encode([
                'provider' => $providerId, 'resourceId' => $resourceId,
                'registryKey' => $key === null ? null : str_replace("\0", ':', $key),
                'leftOut' => $key !== null && isset($leftOut[$key]),
            ]));
            if ($key !== null && isset($leftOut[$key])) {
                continue;
            }
            $url = $this->safeUrl($resource->getUrl());
            $label = trim($resource->getLabel());
            if ($url === null || $label === '') {
                continue;
            }
            $rows[] = [
                'id'    => $resourceId,
                'label' => $label,
                'url'   => $url,
                'icon'  => $this->icon($resource->getIconEmoji(), $resource->getIconSvg(), $resource->getIconURL()),
            ];
            if (count($rows) >= self::MAX_PER_PROVIDER) {
                break;
            }
        }
        if ($rows === []) {
            return null;
        }
        return [
            'id'        => $providerId,
            'name'      => $name !== '' ? $name : $providerId,
            'icon'      => $this->icon(null, $provider->getIconSvg(), null),
            'resources' => $rows,
        ];
    }

    private function safeName(ITeamResourceProvider $provider, string $providerId): string {
        try {
            $name = $provider->getName();
            return $name !== '' ? $name : $providerId;
        } catch (\Throwable $e) {
            return $providerId;
        }
    }

    /**
     * The first usable icon in core's own order (emoji, svg, url), or null.
     *
     * @return array{type: string, value: string}|null
     */
    private function icon(?string $emoji, ?string $svg, ?string $url): ?array {
        $emoji = trim((string)$emoji);
        if ($emoji !== '' && mb_strlen($emoji) <= 8) {
            return ['type' => 'emoji', 'value' => $emoji];
        }
        $svg = trim((string)$svg);
        if ($svg !== '' && strlen($svg) <= self::MAX_ICON_SVG_BYTES && preg_match('/^(<\?xml[^>]*>\s*)?<svg[\s>]/i', $svg) === 1) {
            return ['type' => 'svg', 'value' => $svg];
        }
        $url = $this->safeUrl($url);
        if ($url !== null) {
            return ['type' => 'url', 'value' => $url];
        }
        return null;
    }

    /**
     * A link the browser may follow: relative to this server, https, or http
     * on this server's own host. Everything else (javascript:, data:, a
     * protocol-relative or backslash path) is null.
     */
    private function safeUrl(?string $url): ?string {
        $url = trim((string)$url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }
        if ($url[0] === '/') {
            return (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) ? null : $url;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host   = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme === 'http') {
            $ownHost = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);
            return (is_string($ownHost) && strcasecmp($ownHost, $host) === 0) ? $url : null;
        }
        return null;
    }
}
