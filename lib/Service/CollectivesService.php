<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CollectivesService — bind, list, and dispose of a team's Nextcloud
 * Collective. Parallels IntravoxService in shape so the two team-app
 * providers are swappable at the widget/toggle layer, but talks to a
 * cleaner API surface: Collectives exposes proper Service classes with
 * documented method signatures, so this file contains ZERO direct
 * `deck_*`-style DB writes. Everything routes through
 * \OCA\Collectives\Service\CollectiveService and PageService.
 *
 * Circle binding: a TeamHub team IS a Circle (source=16). Collectives'
 * CollectiveService::createCollective(userId, userLang, name) tries to
 * create a new Circle with that name; when one already exists it catches
 * CircleExistsException and rebinds to the existing Circle via
 * findCircle(name, userId, LEVEL_ADMIN). We rely on that fallback path —
 * the caller must be a team admin (level >= 8), which the controller
 * enforces via MemberService::requireAdminLevel before calling here.
 *
 * Toggle-off dispatch reads the admin-configured archive policy
 * (archiveBeforeDelete + archiveMode, same keys ArchiveService uses at
 * lib/Service/ArchiveService.php:50-54) and routes to the correct
 * Collectives call:
 *
 *   archive mode | disable action
 *   hard         | deleteCollective(id, uid, deleteCircle=FALSE)
 *   soft30/60    | trashCollective(id, uid)  (restorable from Collectives'
 *                                             own trash within its retention;
 *                                             hard-delete-after-grace
 *                                             scheduling is deferred)
 *
 * ⚠ deleteCircle MUST be false on every hard-delete — TeamHub owns the
 * Circle (it IS the team), so passing true would take the whole team
 * down as a side effect of the admin flipping one toggle. There is no
 * scenario where we want that.
 *
 * ⚠ Deferred (documented in CHANGELOG 4.3.3 known limitations):
 *   1. Archive-bundle export. When archiveBeforeDelete=true the collective
 *      is deleted without first being written into the team's archive
 *      ZIP — the archive extractor (ArchiveService::extractCollectivesData)
 *      lands in a follow-up patch. Skipping is logged as a WARN so it's
 *      visible in the NC log.
 *   2. Grace-period scheduled hard-delete. soft30/60 currently just
 *      trashes the collective; the promoted-to-hard-delete-after-N-days
 *      sweep is a new BackgroundJob and matching pending-row table that
 *      also land in the follow-up patch. In the interim, the collective
 *      sits in Collectives' own trash and is restorable by an NC admin.
 */
class CollectivesService {

    /** Local-only cache, mirrors IntravoxService pattern. */
    private \OCP\ICache $cache;

    /** appconfig key prefix — see class docblock. */
    private const CFG_ENABLED       = 'collectives_enabled_';
    private const CFG_COLLECTIVE_ID = 'collectives_collective_id_';

    public function __construct(
        private IUserSession        $userSession,
        private IAppManager         $appManager,
        private IConfig             $config,
        private ContainerInterface  $container,
        private LoggerInterface     $logger,
        ICacheFactory               $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createLocal();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Availability
    // ─────────────────────────────────────────────────────────────────────

    public function isInstalled(): bool {
        return $this->appManager->isInstalled('collectives');
    }

    /**
     * Per-team toggle state. Default OFF — teams opt in explicitly, same
     * shape as the messages / timeline / decisions toggles. Reading is
     * cheap (single appconfig call); no cache.
     */
    public function isEnabledForTeam(string $teamId): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0') === '1';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Toggle on — auto-create if the team has no bound collective yet
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Enable Collectives for a team. If the team has never had a collective
     * before, one is created and bound to the team-circle. If a collective
     * was previously created (cached id present), the flag is simply flipped
     * back on — no duplicate is created, and any content that was in the
     * collective before is left intact.
     *
     * @return array{
     *   ok: bool,
     *   collectiveId?: int,
     *   collectiveName?: string,
     *   created?: bool,
     *   error?: string,
     * }
     */
    public function enableForTeam(string $teamId, string $teamName, string $adminUid): array {
        if (!$this->isInstalled()) {
            return ['ok' => false, 'error' => 'Collectives app not installed'];
        }

        $existingId = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, '0');
        $created    = false;
        $collectiveId = $existingId > 0 ? $existingId : null;

        if ($collectiveId === null) {
            // Pre-check: does the admin already have a Collective bound to
            // this team's circle? Reuse it if so (a leftover from a prior
            // enable that we lost the cache pointer to, or a Collective
            // created by hand inside Collectives' UI). Cheaper than
            // calling createCollective and hitting the "name exists" path.
            $preExisting = $this->findExistingCollectiveByCircleId($teamId, $adminUid);
            if ($preExisting !== null) {
                $collectiveId = $preExisting;
            } else {
                try {
                    $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
                    // userLang drives which default landing-page template
                    // Collectives copies into the folder — see
                    // collectiveFolderManager->initializeFolder in Collectives'
                    // CollectiveService::createCollective body. We pick the
                    // admin's own NC language (same resolver DeckService uses
                    // for stack titles at lib/Service/DeckService.php:1351).
                    $userLang = $this->config->getUserValue($adminUid, 'core', 'lang', '');
                    if ($userLang === '') {
                        $userLang = $this->config->getSystemValue('default_language', 'en');
                    }
                    // Collectives sanitises the name via NodeHelper::sanitiseFilename.
                    // TeamHub team names are usually already safe; if the sanitised
                    // name diverges from the team-circle's name, findCircle() in
                    // Collectives' fallback path won't match and a fresh circle
                    // gets made — noisy but non-destructive to our team. Detected
                    // post-hoc by checking the returned Collective's circleId.
                    $result = $svc->createCollective($adminUid, $userLang, $teamName);
                    // Return shape is [Collective, string $info]; we care only
                    // about the Collective for now.
                    $collective = is_array($result) ? ($result[0] ?? null) : $result;
                    if ($collective === null) {
                        return ['ok' => false, 'error' => 'Collectives createCollective returned no collective'];
                    }
                    $collectiveId = (int)$collective->getId();
                    if ($collectiveId <= 0) {
                        // Guard against the id=0 cache-poisoning bug logged in
                        // 4.3.5 — a Collective object came back from getCollectives
                        // whose getId() returned null, we serialized with id=0,
                        // and every downstream findAll(0, uid) then failed with
                        // "Collective not found: 0" on subsequent widget reads.
                        // Fail the enable rather than caching a bogus id.
                        return ['ok' => false, 'error' => 'Collectives returned a collective with no id'];
                    }
                    $created = true;

                    // Sanity: warn if the collective landed on a DIFFERENT
                    // circle than our team. If it did, the admin's name
                    // sanitisation mismatched and Collectives made a new
                    // Circle — the admin needs to know rather than silently
                    // getting a collective bound to nothing.
                    // v4.3.10 — safeGetCircleId is __call-safe (see docblock).
                    $boundCircleId = $this->safeGetCircleId($collective);
                    if ($boundCircleId !== '' && $boundCircleId !== $teamId) {
                        $this->logger->warning('[TeamHub][CollectivesService] Collective bound to unexpected circle', [
                            'teamId'         => $teamId,
                            'expectedCircle' => $teamId,
                            'boundCircle'    => $boundCircleId,
                            'collectiveId'   => $collectiveId,
                            'app'            => Application::APP_ID,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    // Rewrite the "A team with that name exists" case
                    // (v4.3.6) — Collectives' createCollective binds by
                    // circle-NAME, not circle-id. When another circle on
                    // this instance has the same name AND the current
                    // admin isn't LEVEL_ADMIN of that other circle,
                    // Collectives' findCircle fallback throws this
                    // message. Surface the actionable variant so the
                    // admin knows what to do instead of debugging DI.
                    if (str_contains($msg, 'that name exists') || str_contains($msg, 'circle exists')) {
                        $this->logger->warning('[TeamHub][CollectivesService] Collective name collision with another circle on this instance', [
                            'teamId'   => $teamId,
                            'teamName' => $teamName,
                            'error'    => $msg,
                            'app'      => Application::APP_ID,
                        ]);
                        return [
                            'ok'    => false,
                            'error' => 'A Nextcloud Circle named "' . $teamName . '" already exists on this instance and is owned by someone else. Collectives binds by circle name, not by team id, so it cannot create a wiki for this team while the collision stands. Rename this team (or the other one) before enabling the Wiki.',
                        ];
                    }
                    // v4.3.11 — NC 33's typed IConfig throws this when
                    // Collectives (or Circles under it) writes a value
                    // that fails the new type/format constraints. Common
                    // trigger on TeamHub teams whose Circle config
                    // bitmask carries flags Collectives' path didn't
                    // expect. Intermittent because it depends on which
                    // internal write path fires — Justin reported hitting
                    // it once and having it work on retry. Surface an
                    // actionable "please retry" instead of the raw NC
                    // error so the admin doesn't chase the wrong lead.
                    // v4.3.14 — same recovery for "Collective already
                    // exists". When Collectives finds an existing collective
                    // for this circle it refuses createCollective with that
                    // message; we should just adopt the existing row
                    // instead of leaving the toggle stuck. Common trigger:
                    // team that had Wiki before, was disabled, appconfig
                    // id-cache cleared (or the row was renumbered), so our
                    // pre-check missed the still-present Collective.
                    if (str_contains($msg, 'Collective already exists')) {
                        $recoveryId = null;
                        try {
                            $recoveryMapper = $this->container->get(\OCA\Collectives\Db\CollectiveMapper::class);
                            $recoveryRow = $recoveryMapper->findByCircleId($teamId, /* includeTrash */ true);
                            if ($recoveryRow !== null) {
                                try { $recoveryId = (int)$recoveryRow->getId(); } catch (\Throwable) {}
                            }
                        } catch (\Throwable $recoveryE) {
                            $this->logger->debug('[TeamHub][CollectivesService] enableForTeam: already-exists recovery lookup failed: ' . $recoveryE->getMessage(), [
                                'app' => Application::APP_ID,
                            ]);
                        }
                        if ($recoveryId !== null && $recoveryId > 0) {
                            $this->logger->info('[TeamHub][CollectivesService] enableForTeam: adopting existing collective (createCollective refused as duplicate)', [
                                'teamId'      => $teamId,
                                'recoveredId' => $recoveryId,
                                'app'         => Application::APP_ID,
                            ]);
                            $collectiveId = $recoveryId;
                            $created      = false;
                            // Fall through to config-write below.
                        } else {
                            $this->logger->warning('[TeamHub][CollectivesService] Collectives refused createCollective as duplicate but the row could not be recovered', [
                                'teamId' => $teamId, 'error' => $msg,
                                'app'    => Application::APP_ID,
                            ]);
                            return [
                                'ok'    => false,
                                'error' => 'Collectives said this team already has a wiki but the corresponding Collective row could not be found in the database. Ask a Nextcloud admin to check for orphaned rows in oc_collectives_collectives with circle_unique_id="' . $teamId . '".',
                            ];
                        }
                    } elseif (str_contains($msg, 'Configuration value is not valid')) {
                        // v4.3.13 — the 4.3.12 trace pinned this to
                        // Collectives' CircleHelper::flagCircleAsAppManaged →
                        // CirclesManager::flagAsAppManaged, which writes a
                        // config flag on the Circle to lock it against
                        // user tampering. NC 33's typed IConfig rejects
                        // that write on some Circle bitmasks.
                        //
                        // Crucially, `flagAsAppManaged` runs AFTER
                        // Collectives has already inserted the Collective
                        // row (see CollectiveService::createCollective:
                        // create-then-flag). So a Collective row often
                        // exists on disk even though createCollective
                        // threw. Recover it via the DB mapper instead of
                        // failing the toggle.
                        $recoveryId = null;
                        try {
                            $recoveryMapper = $this->container->get(\OCA\Collectives\Db\CollectiveMapper::class);
                            $recoveryRow = $recoveryMapper->findByCircleId($teamId, /* includeTrash */ true);
                            if ($recoveryRow !== null) {
                                try { $recoveryId = (int)$recoveryRow->getId(); } catch (\Throwable) {}
                            }
                        } catch (\Throwable $recoveryE) {
                            $this->logger->debug('[TeamHub][CollectivesService] enableForTeam: recovery lookup failed: ' . $recoveryE->getMessage(), [
                                'app' => Application::APP_ID,
                            ]);
                        }

                        if ($recoveryId !== null && $recoveryId > 0) {
                            $this->logger->info('[TeamHub][CollectivesService] enableForTeam: flag-as-managed failed but Collective row was created — proceeding with recovered id', [
                                'teamId'         => $teamId,
                                'recoveredId'    => $recoveryId,
                                'ncTypedIConfig' => $msg,
                                'app'            => Application::APP_ID,
                            ]);
                            $collectiveId = $recoveryId;
                            $created      = true;
                            // Fall through to the config-write below —
                            // the Wiki works fine even if the Circle is
                            // not app-managed-flagged; that flag only
                            // affects whether users can manually tamper
                            // with the Circle from the Circles UI.
                        } else {
                            $this->logger->warning('[TeamHub][CollectivesService] Collectives write rejected by NC typed IConfig (no recovery row)', [
                                'teamId' => $teamId,
                                'error'  => $msg,
                                'trace'  => $e->getTraceAsString(),
                                'file'   => $e->getFile(),
                                'line'   => $e->getLine(),
                                'app'    => Application::APP_ID,
                            ]);
                            return [
                                'ok'    => false,
                                'error' => 'Collectives could not finish setting up this team\'s wiki because Nextcloud rejected one of its internal writes ("Configuration value is not valid") and no partial Collective row was left behind to recover. Try the toggle again; if it keeps failing, the NC log carries the full stack.',
                            ];
                        }
                    } else {
                        $this->logger->error('[TeamHub][CollectivesService] enableForTeam createCollective failed', [
                            'teamId' => $teamId, 'error' => $msg,
                            'app'    => Application::APP_ID,
                        ]);
                        return ['ok' => false, 'error' => 'Failed to create collective: ' . $msg];
                    }
                }
            }
        }

        // Commit both keys atomically-enough — appconfig is not
        // transactional but these two writes always succeed together in
        // practice; on the (extremely rare) partial-write case the widget
        // will show empty and the admin can re-toggle to re-run.
        $this->config->setAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, (string)$collectiveId);
        $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED       . $teamId, '1');
        $this->invalidateCache($teamId);

        return [
            'ok'             => true,
            'collectiveId'   => $collectiveId,
            'collectiveName' => $teamName,
            'created'        => $created,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Toggle off — dispatches on the admin archive policy
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Disable Collectives for a team. Reads the admin-set archive policy
     * (archiveBeforeDelete + archiveMode, same keys ArchiveService uses)
     * and dispatches to the Collectives call that matches. See class
     * docblock for the mode → action mapping and the two documented
     * deferred pieces (archive-bundle export, grace-period sweep).
     *
     * @return array{
     *   ok: bool,
     *   action: 'hard'|'trash'|'noop',
     *   archived: bool,
     *   collectiveId?: int,
     *   error?: string,
     * }
     */
    public function disableForTeam(string $teamId, string $adminUid): array {
        if (!$this->isInstalled()) {
            // Toggle off with the app not installed is still valid — just
            // flip the flag off, there's nothing external to clean up.
            $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0');
            $this->invalidateCache($teamId);
            return ['ok' => true, 'action' => 'noop', 'archived' => false];
        }

        $collectiveId = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, '0');
        if ($collectiveId === 0) {
            // Enabled but never bound — nothing to delete; just flip off.
            $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0');
            $this->invalidateCache($teamId);
            return ['ok' => true, 'action' => 'noop', 'archived' => false];
        }

        // Read the archive policy — same keys ArchiveService::getSettings
        // uses so a single admin decision applies to every team-deletion
        // channel, including per-resource toggle-off here.
        $archiveBeforeDelete = $this->config->getAppValue(Application::APP_ID, 'archiveBeforeDelete', '0') === '1';
        $archiveMode         = $this->config->getAppValue(Application::APP_ID, 'archiveMode', 'soft30');

        if ($archiveBeforeDelete) {
            // Deferred: archive-bundle export for a lone-collective disable.
            // Flagged clearly so it surfaces in the NC log; the delete
            // still fires so the toggle acts predictably.
            $this->logger->warning('[TeamHub][CollectivesService] archiveBeforeDelete is ON but per-collective archive export is not yet implemented — proceeding with delete WITHOUT archive', [
                'teamId'       => $teamId,
                'collectiveId' => $collectiveId,
                'archiveMode'  => $archiveMode,
                'app'          => Application::APP_ID,
            ]);
        }

        $action   = 'noop';
        $svcError = null;
        try {
            $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
            if ($archiveMode === 'hard') {
                // deleteCircle MUST be false — the Circle IS the team.
                // See class docblock's warning block.
                $svc->deleteCollective($collectiveId, $adminUid, /* deleteCircle */ false);
                $action = 'hard';
                // Clear the cached collective id — the row is gone.
                $this->config->deleteAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId);
            } else {
                // soft30 / soft60 → Collectives' own trash. Restorable by
                // an NC admin from Collectives' UI within its retention.
                // Grace-period hard-delete scheduling is the second
                // deferred piece (see class docblock).
                $svc->trashCollective($collectiveId, $adminUid);
                $action = 'trash';
                // Keep the cached collective id on trash — if the admin
                // re-enables during the grace window we can restore
                // in-place instead of creating a duplicate. (Restore path
                // itself lands with the grace-period sweep.)
            }
        } catch (\Throwable $e) {
            $svcError = $e->getMessage();
            $this->logger->error('[TeamHub][CollectivesService] disableForTeam ' . $action . ' failed', [
                'teamId'       => $teamId,
                'collectiveId' => $collectiveId,
                'error'        => $svcError,
                'app'          => Application::APP_ID,
            ]);
        }

        // Flip the flag off REGARDLESS of Collectives-side success. If the
        // remote call failed the collective is still there for the admin
        // to investigate manually, but the toggle should track intent.
        $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0');
        $this->invalidateCache($teamId);

        return [
            'ok'           => $svcError === null,
            'action'       => $action,
            'archived'     => false,
            'collectiveId' => $collectiveId,
            'error'        => $svcError,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Read paths (widget)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve the collective bound to $teamId, from the caller's own
     * viewpoint (ACL-enforced by Collectives). Returns null when the
     * team-app is off or when the caller can't see the collective.
     *
     * v4.3.10 — rewritten to try THREE lookup paths in order. Original
     * v4.3.5–.9 loop used `method_exists($c, 'getCircleId')` to gate the
     * circle-id comparison — but Collectives' entity class exposes
     * `getCircleUniqueId()` via NC ORM's `__call` magic method, which
     * `method_exists()` does NOT detect on some PHP/NC versions. On the
     * user's install the loop found the collective, saw method_exists()
     * return false for BOTH getter names, fell through with $circleId=''
     * and never matched, so `getTeamCollective` returned null even
     * though Contacts could see the binding just fine.
     *
     * Fixed by:
     *   1. Preferring a direct fetch via `getCollectiveWithShare($id, $uid)`
     *      when we have a cached id — that's an ACL-enforced by-id lookup,
     *      no getter guesswork required.
     *   2. Falling back to the `getCollectives($uid)` loop, but using
     *      `getCircleUniqueId()` inside a try/catch instead of gating on
     *      method_exists() (so the `__call` magic method actually fires).
     *   3. Adding a last-ditch DB-mapper lookup via
     *      `CollectiveMapper::findByCircleId` for the case where the user
     *      created the collective directly in Collectives' UI and neither
     *      TeamHub's appconfig id-cache nor the user's ACL view yields it.
     *
     * Debug logging surfaces which path won so a follow-up bug is easy
     * to trace next time.
     *
     * @return array{id:int, name:string, emoji:?string, url:string}|null
     */
    public function getTeamCollective(string $teamId, string $userId): ?array {
        if (!$this->isInstalled() || !$this->isEnabledForTeam($teamId)) {
            return null;
        }

        $cacheKey = 'teamhub_collectives_teamcollective_' . $teamId;
        $cached   = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached[$userId])) {
            return $cached[$userId] ?: null;
        }

        $out       = null;
        $matchedBy = 'none';
        $cachedId  = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, '0');

        // ── Path 1: direct fetch by cached id ─────────────────────────
        // getCollectiveWithShare(id, uid) is the by-id, ACL-enforced
        // lookup Collectives itself uses. Wins outright when we already
        // know the id from a prior enableForTeam.
        //
        // v4.3.14 — stale-id cleanup: when this fails with a "Collective
        // with ID X not found" pattern, the cached id points at a row
        // that no longer exists (row was deleted directly in Collectives,
        // an admin renumbered it, or the enable was mid-flight and
        // Collectives rolled back). Clear the cached id so the next
        // lookup goes straight to paths 2/3 instead of paying the same
        // NotFound cost every time.
        if ($cachedId > 0) {
            try {
                $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
                $collective = $svc->getCollectiveWithShare($cachedId, $userId);
                if ($collective !== null) {
                    $out = $this->serializeCollective($collective);
                    if ($out !== null) {
                        $matchedBy = 'cached_id_direct';
                    }
                }
            } catch (\Throwable $e) {
                $emsg = $e->getMessage();
                $isNotFound = str_contains($emsg, 'not found');
                if ($isNotFound) {
                    $this->logger->info('[TeamHub][CollectivesService] getTeamCollective: cached collective id is stale, clearing', [
                        'teamId' => $teamId, 'staleId' => $cachedId, 'error' => $emsg,
                        'app'    => Application::APP_ID,
                    ]);
                    $this->config->deleteAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId);
                    $cachedId = 0;
                } else {
                    // Not found for this user, or ACL denial — fall through
                    // to the next path rather than caching null immediately;
                    // another path might still resolve it.
                    $this->logger->debug('[TeamHub][CollectivesService] getTeamCollective path1 (cached_id direct) failed: ' . $emsg, [
                        'teamId' => $teamId, 'cachedId' => $cachedId, 'app' => Application::APP_ID,
                    ]);
                }
            }
        }

        // ── Path 2: getCollectives() loop, __call-safe circle-id match ─
        if ($out === null) {
            try {
                $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
                foreach ($svc->getCollectives($userId) as $c) {
                    $cid = 0;
                    try { $cid = (int)$c->getId(); } catch (\Throwable) {}
                    $circleId = $this->safeGetCircleId($c);
                    if (($cachedId > 0 && $cid === $cachedId) || ($circleId !== '' && $circleId === $teamId)) {
                        $candidate = $this->serializeCollective($c);
                        if ($candidate === null) {
                            continue;
                        }
                        $out = $candidate;
                        $matchedBy = ($cachedId > 0 && $cid === $cachedId) ? 'cached_id_loop' : 'circle_id_loop';
                        if ($cachedId === 0 || $cachedId !== $cid) {
                            $this->config->setAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, (string)$cid);
                        }
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][CollectivesService] getTeamCollective path2 (getCollectives loop) failed: ' . $e->getMessage(), [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            }
        }

        // ── Path 3: direct DB via CollectiveMapper ────────────────────
        // Reaches Collectives beneath the ACL layer, so it can find a
        // collective the current user isn't formally a Collectives-member
        // of yet (fresh enable race, or created-in-UI-by-someone-else).
        if ($out === null) {
            try {
                $mapper = $this->container->get(\OCA\Collectives\Db\CollectiveMapper::class);
                // Method name is best-effort — CollectiveMapper exposes
                // findBy<Field> for its indexed columns. `findByCircleId`
                // is the current name; wrap in try/catch so a future
                // rename doesn't kill the whole resolver.
                $collective = $mapper->findByCircleId($teamId);
                if ($collective !== null) {
                    $out = $this->serializeCollective($collective);
                    if ($out !== null) {
                        $matchedBy = 'mapper_by_circle_id';
                        // Repair the cached id from this resolver — future
                        // reads will hit path 1 instead of falling through.
                        $repaired = (int)$out['id'];
                        if ($repaired > 0 && $repaired !== $cachedId) {
                            $this->config->setAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, (string)$repaired);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // findByCircleId either threw NotFound (row genuinely
                // absent) or the method's signature changed. Both are
                // non-fatal.
                $this->logger->debug('[TeamHub][CollectivesService] getTeamCollective path3 (mapper.findByCircleId) failed: ' . $e->getMessage(), [
                    'teamId' => $teamId, 'app' => Application::APP_ID,
                ]);
            }
        }

        $this->logger->debug('[TeamHub][CollectivesService] getTeamCollective resolved', [
            'teamId'    => $teamId,
            'userId'    => $userId,
            'cachedId'  => $cachedId,
            'matchedBy' => $matchedBy,
            'foundId'   => $out['id'] ?? null,
            'app'       => Application::APP_ID,
        ]);

        // Per-user cache — different users have different ACL views, so
        // one collective may resolve for user A and not for user B.
        // 5-minute TTL matches IntravoxService::getTeamPage cache.
        // v4.3.10 — only cache successful lookups: caching null (all
        // three paths missed) kept the widget silent for the full 5-min
        // TTL even after the underlying issue was resolved. A subsequent
        // failing read just re-runs the (still cheap) three paths.
        if ($out !== null) {
            $bucket = is_array($cached) ? $cached : [];
            $bucket[$userId] = $out;
            $this->cache->set($cacheKey, $bucket, 300);
        }

        return $out;
    }

    /**
     * __call-safe circle-id read (v4.3.10). Collectives' entity uses NC's
     * ORM magic-method getters — `method_exists()` does not detect those
     * on all NC versions, so calling into a try/catch is more reliable
     * than gating on method_exists first. Tries both the explicit
     * `getCircleId()` wrapper and the `getCircleUniqueId()` field getter;
     * whichever fires first wins.
     */
    private function safeGetCircleId(object $c): string {
        foreach (['getCircleUniqueId', 'getCircleId'] as $method) {
            try {
                $val = $c->{$method}();
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            } catch (\Throwable) {
                // Method missing or threw — try the next one.
            }
        }
        return '';
    }

    /**
     * All pages of the team's collective, flat and sorted by title, capped
     * at 20. Called by the Pages widget to render the "Wiki" section
     * beneath the header link.
     *
     * v4.3.9 — was filtering to `parentId === 0` (assumed to mean top-
     * level). In practice Collectives assigns every real page's `parentId`
     * to the fileId of its enclosing folder (the landing page's folder for
     * root-level pages), which is never 0 — so that filter dropped every
     * page and left the widget silently empty. The pragmatic fix is to
     * skip parent-level filtering entirely: users can navigate hierarchy
     * inside Collectives itself; the widget's job is just to show the
     * team's pages so they're easy to jump into.
     *
     * @return array<int, array{id:int, title:string, url:string}>
     */
    public function getSubPages(string $teamId, string $userId): array {
        if (!$this->isInstalled() || !$this->isEnabledForTeam($teamId)) {
            return [];
        }

        $collective = $this->getTeamCollective($teamId, $userId);
        if ($collective === null) {
            return [];
        }
        $collectiveId = (int)$collective['id'];
        if ($collectiveId <= 0) {
            // Defensive: getTeamCollective can only return a serialized
            // row with a positive id since v4.3.6 (see serializeCollective),
            // but keep the guard in case the cache still holds an older
            // shape from before that change.
            return [];
        }

        try {
            $svc   = $this->container->get(\OCA\Collectives\Service\PageService::class);
            $pages = $svc->findAll($collectiveId, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CollectivesService] getSubPages findAll failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return [];
        }

        // Flat list — no parentId filter (see docblock). We skip anything
        // that doesn't produce a positive id + non-empty title, sort by
        // title, cap at 20 so a very-large collective doesn't blow the
        // widget.
        //
        // v4.3.13 — use Collectives' getPageLink to build the URL so a
        // widget click lands on the specific page in the iframe instead
        // of the collective's landing (same fix as createPage in 4.3.12).
        $baseUrl = $collective['url'];
        $collectiveSlug = ltrim(str_replace('/apps/collectives/', '', $baseUrl), '/');
        $pageSvc = $this->container->get(\OCA\Collectives\Service\PageService::class);

        $out = [];
        foreach ($pages as $p) {
            try {
                // __call-safe getters (v4.3.10 — see serializeCollective).
                $id = 0;
                try { $id = (int)$p->getId(); } catch (\Throwable) {}
                $title = '';
                try { $title = (string)$p->getTitle(); } catch (\Throwable) {}
                if ($id <= 0 || $title === '') {
                    continue;
                }

                $pageLink = '';
                try {
                    $pageLink = (string)$pageSvc->getPageLink($collectiveSlug, $p, true);
                } catch (\Throwable) {}
                if ($pageLink !== '') {
                    if (str_starts_with($pageLink, '/apps/collectives/')) {
                        $url = $pageLink;
                    } elseif (str_starts_with($pageLink, 'apps/collectives/')) {
                        $url = '/' . $pageLink;
                    } else {
                        $url = '/apps/collectives/' . ltrim($pageLink, '/');
                    }
                } else {
                    // Fallback for a Collectives version without getPageLink.
                    $titleSlug = rawurlencode(trim((string)preg_replace('/\s+/', '-', $title)));
                    $url = rtrim($baseUrl, '/') . '/' . $titleSlug . '?fileId=' . $id;
                }

                $out[] = [
                    'id'    => $id,
                    'title' => $title,
                    'url'   => $url,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        usort($out, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
        if (count($out) > 20) {
            $out = array_slice($out, 0, 20);
        }
        return $out;
    }

    /**
     * Create a new page in the team's collective (v4.3.9).
     *
     * Resolves the target parent by taking the first page returned by
     * `PageService::findAll` and using its `parentId` — that's the
     * enclosing folder every root-level page in the collective shares.
     * When the collective is brand-new and has no `findAll` results yet,
     * we fall through with `parentId=0` and let Collectives decide what
     * to do (its own frontend does the same on first-page creates).
     *
     * @return array{ok:bool, id?:int, title?:string, url?:string, error?:string}
     */
    public function createPage(string $teamId, string $userId, string $title): array {
        if (!$this->isInstalled() || !$this->isEnabledForTeam($teamId)) {
            return ['ok' => false, 'error' => 'Wiki is not enabled for this team'];
        }

        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'error' => 'Title is required'];
        }

        $collective = $this->getTeamCollective($teamId, $userId);
        if ($collective === null) {
            return ['ok' => false, 'error' => 'This team has no collective bound yet'];
        }
        $collectiveId = (int)$collective['id'];
        if ($collectiveId <= 0) {
            return ['ok' => false, 'error' => 'Collective id is not resolved'];
        }

        try {
            $pageSvc = $this->container->get(\OCA\Collectives\Service\PageService::class);

            // Resolve a parent (v4.3.11).
            //
            // Collectives' PageService::create expects parentId to be the
            // fileId of an existing index-page file. When we pass 0 it
            // tries to fall back to `getIndexPageFile($collectiveFolder)`,
            // but that returns id=0 on a freshly-materialised collective —
            // the caller then sees "File not found: 0". So we try three
            // resolutions in order and only pass 0 as the last resort:
            //
            //   1. Any existing page's `parentId` > 0 — that's the
            //      collective's root folder fileId, shared by every
            //      root-level page.
            //   2. If every page reports parentId=0 (or none exist yet),
            //      fall back to the first existing page's own id. That
            //      creates the new page as a subpage of the landing page,
            //      which is a valid location and beats a hard error.
            //   3. Zero — Collectives will try its own fallback.
            //
            // Getters run inside try/catch for the same __call reason as
            // serializeCollective (v4.3.10).
            $parentId = 0;
            $existing = $pageSvc->findAll($collectiveId, $userId);
            if (!empty($existing)) {
                foreach ($existing as $p) {
                    try {
                        $pParent = (int)$p->getParentId();
                        if ($pParent > 0) {
                            $parentId = $pParent;
                            break;
                        }
                    } catch (\Throwable) {}
                }
                if ($parentId <= 0) {
                    foreach ($existing as $p) {
                        try {
                            $pId = (int)$p->getId();
                            if ($pId > 0) {
                                $parentId = $pId;
                                break;
                            }
                        } catch (\Throwable) {}
                    }
                }
            }

            $created = $pageSvc->create($collectiveId, $parentId, $title, null, $userId);
            $id = 0;
            try { $id = (int)$created->getId(); } catch (\Throwable) {}
            if ($id <= 0) {
                return ['ok' => false, 'error' => 'Collectives returned a page with no id'];
            }

            // Bust the widget's cache so the new page appears on next fetch.
            $this->invalidateCache($teamId);

            $createdTitle = $title;
            try { $createdTitle = (string)$created->getTitle(); } catch (\Throwable) {}

            // v4.3.12 — use Collectives' own getPageLink to build the
            // deep-link. Our ad-hoc "?fileId=" URL (v4.3.9) worked as a
            // fallback but landed on the collective's landing page instead
            // of the new page because Collectives' router keys off the
            // path segments first, treating fileId only as a
            // slug-resolution fallback. Canonical link builder handles
            // this correctly across every collective/page variant.
            $baseUrl = $collective['url']; // '/apps/collectives/{slug}'
            $collectiveSlug = ltrim(str_replace('/apps/collectives/', '', $baseUrl), '/');
            $pageLink = '';
            try {
                $pageLink = (string)$pageSvc->getPageLink($collectiveSlug, $created, true);
            } catch (\Throwable $e) {
                $this->logger->debug('[TeamHub][CollectivesService] createPage: getPageLink failed, falling back to fileId form: ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                ]);
            }
            if ($pageLink !== '') {
                // getPageLink returns a path relative to /apps/collectives/
                // (e.g. "marketing/Kickoff?fileId=42") — prepend the app
                // root ourselves. Guard against a future Collectives
                // version that returns the fully-qualified path so we
                // don't double the prefix.
                if (str_starts_with($pageLink, '/apps/collectives/')) {
                    $url = $pageLink;
                } elseif (str_starts_with($pageLink, 'apps/collectives/')) {
                    $url = '/' . $pageLink;
                } else {
                    $url = '/apps/collectives/' . ltrim($pageLink, '/');
                }
            } else {
                // Last-resort fallback: title-slug in path plus fileId in query.
                $titleSlug = rawurlencode(trim((string)preg_replace('/\s+/', '-', $createdTitle)));
                $url = rtrim($baseUrl, '/') . '/' . $titleSlug . '?fileId=' . $id;
            }

            return [
                'ok'    => true,
                'id'    => $id,
                'title' => $createdTitle,
                'url'   => $url,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CollectivesService] createPage failed', [
                'teamId' => $teamId, 'error' => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return ['ok' => false, 'error' => 'Failed to create page: ' . $e->getMessage()];
        }
    }

    /**
     * Bust the per-team, per-user resolve cache. Called by the toggle
     * endpoints and can be POSTed manually (subpages/cache DELETE) if
     * the widget looks stale.
     */
    public function invalidateCache(string $teamId): void {
        $this->cache->remove('teamhub_collectives_teamcollective_' . $teamId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Soft-delete integration — called from ArchiveService (v4.3.11)
    //
    // The other suspend/resume hooks in ArchiveService drop the team-circle
    // from each app's ACL row (Talk attendees, Files share, Calendar
    // dav_shares, Deck ACL) — the underlying resource stays intact so the
    // team owner still owns it, but non-owner team members lose access
    // during the grace window.
    //
    // Collectives is Circle-backed by design — there is no per-user ACL to
    // strip. `trashCollective` is the closest equivalent to what the other
    // suspends do: the collective goes into Collectives' own Trash where
    // an NC admin (and the team owner) can pull content back if they need
    // to, non-owner team members lose the widget/tab. `restoreCollective`
    // brings it back on restore. Hard-delete still happens via
    // deleteForTeamCascade below at team hard-delete time.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Suspend the team's collective as part of a team soft-delete
     * (v4.3.12 rewritten from the v4.3.11 trash-based approach).
     *
     * Behaviour requested by the user: during the grace window, the team
     * OWNER keeps admin-grade access to the collective as their own; the
     * TEAM members lose access. On restore the team gets access back.
     * On grace expiry (team hard-delete), the collective is removed via
     * `deleteForTeamCascade`.
     *
     * Collectives is Circle-backed by design — the collective's access set
     * IS the Circle's member set. To move ownership from the team-circle
     * to the owner personally, we rebind the collective's underlying
     * Circle identity to the owner's PERSONAL circle (each NC user has a
     * source=1 self-circle, its unique_id = the user's Circles `single_id`).
     *
     * Implementation: direct UPDATE on `oc_collectives_collectives.circle_unique_id`
     * (bypasses Collectives' own API — the app exposes no rebind method).
     * This is the same "reach into another app's table when the OCP surface
     * doesn't cover it" pattern TeamHub already uses for Deck reads/writes
     * (see APIendpoints.md § Deck). Documented as a targeted bypass.
     *
     * Fallback: if the personal circle can't be resolved (rare — user
     * without an initialised Circles account) or the UPDATE fails, we
     * fall through to `trashCollective` so the collective still leaves
     * team members' view. Owner then restores from Collectives → Trash
     * during the grace window.
     *
     * @return array{
     *   rebound?:bool, trashed?:bool,
     *   collectiveId:int,
     *   originalCircleId?:string, newCircleId?:string
     * }|null Metadata for resumeForTeam.
     */
    public function suspendForTeam(string $teamId, string $adminUid): ?array {
        if (!$this->isInstalled()) {
            return null;
        }
        $collectiveId = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, '0');
        if ($collectiveId <= 0) {
            return null;
        }

        $db = $this->container->get(\OCP\IDBConnection::class);

        // ── Path 1: rebind circle to owner's personal circle ───────────
        $personalCircleId = $this->findPersonalCircleId($db, $adminUid);
        if ($personalCircleId !== null && $personalCircleId !== $teamId) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->update('collectives_collectives')
                    ->set('circle_unique_id', $qb->createNamedParameter($personalCircleId))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($collectiveId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0');
                $this->invalidateCache($teamId);

                $this->logger->info('[TeamHub][CollectivesService] suspended: rebound collective to owner personal circle', [
                    'teamId'           => $teamId,
                    'collectiveId'     => $collectiveId,
                    'personalCircleId' => $personalCircleId,
                    'adminUid'         => $adminUid,
                    'app'              => Application::APP_ID,
                ]);
                return [
                    'rebound'          => true,
                    'collectiveId'     => $collectiveId,
                    'originalCircleId' => $teamId,
                    'newCircleId'      => $personalCircleId,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][CollectivesService] suspendForTeam rebind UPDATE failed, falling back to trash: ' . $e->getMessage(), [
                    'teamId' => $teamId, 'collectiveId' => $collectiveId,
                    'app'    => Application::APP_ID,
                ]);
            }
        } else if ($personalCircleId === null) {
            $this->logger->debug('[TeamHub][CollectivesService] suspendForTeam: no personal circle for owner, falling back to trash', [
                'teamId' => $teamId, 'adminUid' => $adminUid, 'app' => Application::APP_ID,
            ]);
        }

        // ── Path 2: fallback — trash the collective ────────────────────
        try {
            $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
            $svc->trashCollective($collectiveId, $adminUid);
            $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '0');
            $this->invalidateCache($teamId);
            $this->logger->info('[TeamHub][CollectivesService] suspended: trashed collective (rebind unavailable)', [
                'teamId' => $teamId, 'collectiveId' => $collectiveId, 'app' => Application::APP_ID,
            ]);
            return ['trashed' => true, 'collectiveId' => $collectiveId];
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CollectivesService] suspendForTeam trashCollective failed (non-fatal)', [
                'teamId' => $teamId, 'collectiveId' => $collectiveId,
                'error'  => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
            return null;
        }
    }

    /**
     * Restore the team's collective from soft-delete (v4.3.12). Mirrors
     * suspendForTeam's dispatch: if metadata says we rebound, reverse the
     * UPDATE; if metadata says we trashed, call restoreCollective.
     * Non-fatal in both branches — a failure just means the owner has to
     * fix up the binding manually.
     */
    public function resumeForTeam(string $teamId, string $adminUid, array $meta): void {
        if (!$this->isInstalled()) {
            return;
        }
        $collectiveId = (int)($meta['collectiveId'] ?? 0);
        if ($collectiveId <= 0) {
            return;
        }

        if (!empty($meta['rebound']) && !empty($meta['originalCircleId'])) {
            try {
                $db = $this->container->get(\OCP\IDBConnection::class);
                $qb = $db->getQueryBuilder();
                $qb->update('collectives_collectives')
                    ->set('circle_unique_id', $qb->createNamedParameter((string)$meta['originalCircleId']))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($collectiveId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '1');
                $this->invalidateCache($teamId);
                $this->logger->info('[TeamHub][CollectivesService] resumed: rebound collective to team circle', [
                    'teamId' => $teamId, 'collectiveId' => $collectiveId, 'app' => Application::APP_ID,
                ]);
                return;
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][CollectivesService] resumeForTeam rebind reverse failed', [
                    'teamId' => $teamId, 'collectiveId' => $collectiveId,
                    'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
                return;
            }
        }

        if (!empty($meta['trashed'])) {
            try {
                $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
                $svc->restoreCollective($collectiveId, $adminUid);
                $this->config->setAppValue(Application::APP_ID, self::CFG_ENABLED . $teamId, '1');
                $this->invalidateCache($teamId);
                $this->logger->info('[TeamHub][CollectivesService] resumed: restored collective from trash', [
                    'teamId' => $teamId, 'collectiveId' => $collectiveId, 'app' => Application::APP_ID,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[TeamHub][CollectivesService] resumeForTeam restoreCollective failed', [
                    'teamId' => $teamId, 'collectiveId' => $collectiveId,
                    'error'  => $e->getMessage(), 'app' => Application::APP_ID,
                ]);
            }
        }
    }

    /**
     * Resolve $adminUid's personal circle unique_id (their "single_id" in
     * NC Circles). Every NC user has one; used to rebind a collective's
     * owning circle during soft-delete so the user keeps admin access
     * while the team-circle is stripped from the collective's ACL.
     *
     * Reads from `circles_membership` — the denormalised membership cache
     * where every row carries the member's `single_id`. Joins to
     * `circles_member` with user_type=1 (direct user row) to translate NC
     * uid → single_id. Same table-shape ArchiveService::captureEffectiveUsers
     * already uses (see its comment for the join rationale).
     */
    private function findPersonalCircleId(\OCP\IDBConnection $db, string $uid): ?string {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('ms.single_id')
                ->from('circles_membership', 'ms')
                ->innerJoin(
                    'ms',
                    'circles_member',
                    'm',
                    $qb->expr()->andX(
                        $qb->expr()->eq('m.circle_id', 'ms.single_id'),
                        $qb->expr()->eq('m.user_type',
                            $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    )
                )
                ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)))
                ->setMaxResults(1);
            $res = $qb->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            if ($row !== false && !empty($row['single_id'])) {
                return (string)$row['single_id'];
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][CollectivesService] findPersonalCircleId failed: ' . $e->getMessage(), [
                'uid' => $uid, 'app' => Application::APP_ID,
            ]);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cascade-delete — called from TeamService::deleteTeam
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Delete the team's collective as part of a team-wide cascade. Fires
     * regardless of the toggle state — a team being deleted takes every
     * bound resource with it. Runs unconditional hard-delete (not
     * archive-mode-aware): team deletion has its own archive path in
     * ArchiveService that already runs before this cascade, so the
     * collective's rows in the archive bundle would come from THERE, not
     * from this call. Best-effort — a failure here is logged, never
     * thrown, so the team's own delete completes.
     */
    public function deleteForTeamCascade(string $teamId, string $adminUid): void {
        if (!$this->isInstalled()) {
            return;
        }
        $collectiveId = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId, '0');
        if ($collectiveId === 0) {
            return;
        }
        try {
            $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
            // deleteCircle=false — the team's own cascade handles the
            // Circle itself; passing true here would race with that.
            $svc->deleteCollective($collectiveId, $adminUid, /* deleteCircle */ false);
            $this->logger->info('[TeamHub][CollectivesService] cascade-deleted collective for team', [
                'teamId' => $teamId, 'collectiveId' => $collectiveId,
                'app'    => Application::APP_ID,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][CollectivesService] cascade delete failed (non-fatal)', [
                'teamId' => $teamId, 'collectiveId' => $collectiveId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
        }
        $this->config->deleteAppValue(Application::APP_ID, self::CFG_COLLECTIVE_ID . $teamId);
        $this->config->deleteAppValue(Application::APP_ID, self::CFG_ENABLED       . $teamId);
        $this->invalidateCache($teamId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Serialize a Collective entity to the wire shape our controllers
     * emit. Kept in one place so the widget contract stays stable if
     * Collectives changes getters between versions. Returns null when
     * the object doesn't yield a positive integer id — see the id=0
     * cache-poisoning fix in enableForTeam (v4.3.6).
     *
     * v4.3.10 — every getter is called inside try/catch instead of
     * gated on method_exists(): Collectives' entity exposes fields via
     * NC ORM's __call magic method, which method_exists() does not
     * detect on all PHP/NC versions. Same failure mode as the circle-id
     * lookup — a match found in the Db, filtered out at the getter step,
     * and null returned to the widget.
     */
    private function serializeCollective(object $c): ?array {
        $id = 0;
        try { $id = (int)$c->getId(); } catch (\Throwable) {}
        if ($id <= 0) {
            $this->logger->debug('[TeamHub][CollectivesService] serializeCollective skipped: no positive id', [
                'app' => Application::APP_ID,
            ]);
            return null;
        }
        $name  = '';
        try { $name = (string)$c->getName(); } catch (\Throwable) {}
        $emoji = null;
        try { $emoji = $c->getEmoji(); } catch (\Throwable) {}
        // Prefer the slug where present — Collectives' own URL layer
        // does the same. Falls back to the sanitised name.
        $slug = '';
        try { $slug = (string)$c->getSlug(); } catch (\Throwable) {}
        $urlSegment = $slug !== '' ? $slug : rawurlencode($name);
        $url = '/apps/collectives/' . $urlSegment;
        return [
            'id'    => $id,
            'name'  => $name,
            'emoji' => $emoji !== null ? (string)$emoji : null,
            'url'   => $url,
        ];
    }

    /**
     * Look up an existing Collective bound to $teamId (circle-id).
     * Called from enableForTeam as a pre-check before createCollective —
     * reusing a pre-existing Collective avoids the "A team with that name
     * exists" collision when the admin has previously enabled Wiki,
     * disabled it, and lost the cached collective id in the meantime.
     *
     * v4.3.10 — same rewrite as getTeamCollective: getCollectives()
     * loop uses safeGetCircleId (Collectives' getter is via __call,
     * method_exists can miss it), plus a CollectiveMapper::findByCircleId
     * fallback for the case where the admin isn't yet a Collectives-
     * visible member of the circle (fresh circle-add race). Returns null
     * on no match.
     */
    private function findExistingCollectiveByCircleId(string $teamId, string $userId): ?int {
        try {
            $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
            foreach ($svc->getCollectives($userId) as $c) {
                $circleId = $this->safeGetCircleId($c);
                if ($circleId !== $teamId) {
                    continue;
                }
                $id = 0;
                try { $id = (int)$c->getId(); } catch (\Throwable) {}
                if ($id > 0) {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][CollectivesService] findExistingCollectiveByCircleId path1 (getCollectives) failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        // Fallback: DB mapper — bypasses Collectives' per-user ACL.
        // v4.3.14 — check trashed rows too. Common on old teams where
        // the collective got trashed during a previous soft-delete or
        // disable cycle; without includeTrash the pre-check misses it
        // and enableForTeam falls into createCollective → "Collective
        // already exists". Restoring inline is cleaner UX: the admin
        // clicks "Enable Wiki" and the collective comes back with its
        // content intact.
        try {
            $mapper = $this->container->get(\OCA\Collectives\Db\CollectiveMapper::class);
            $collective = $mapper->findByCircleId($teamId, /* includeTrash */ true);
            if ($collective !== null) {
                $id = 0;
                try { $id = (int)$collective->getId(); } catch (\Throwable) {}
                $trashTs = 0;
                try { $trashTs = (int)$collective->getTrashTimestamp(); } catch (\Throwable) {}
                if ($id > 0) {
                    if ($trashTs > 0) {
                        try {
                            $svc = $this->container->get(\OCA\Collectives\Service\CollectiveService::class);
                            $svc->restoreCollective($id, $userId);
                            $this->logger->info('[TeamHub][CollectivesService] findExistingCollectiveByCircleId: restored trashed collective on enable', [
                                'teamId' => $teamId, 'collectiveId' => $id,
                                'app'    => Application::APP_ID,
                            ]);
                        } catch (\Throwable $restoreE) {
                            $this->logger->warning('[TeamHub][CollectivesService] findExistingCollectiveByCircleId: could not restore trashed collective (using anyway)', [
                                'teamId' => $teamId, 'collectiveId' => $id,
                                'error'  => $restoreE->getMessage(),
                                'app'    => Application::APP_ID,
                            ]);
                        }
                    }
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[TeamHub][CollectivesService] findExistingCollectiveByCircleId path2 (mapper) failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        return null;
    }
}
