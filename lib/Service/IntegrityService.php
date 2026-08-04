<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;

/**
 * Read-only code-integrity check for the Compliance tab.
 *
 * At build time, scripts/generate-integrity.js writes appinfo/integrity.json
 * containing SHA-256 hashes for every shipped file under a curated set of
 * directories (appinfo, lib, js, css, templates, img, l10n, sql, announcements).
 *
 * At runtime this service re-hashes each entry on disk and reports:
 *   - altered:    files whose current hash differs from the manifest
 *   - missing:    files listed in the manifest but no longer on disk
 *   - unexpected: files present on disk under a covered dir but absent
 *                 from the manifest (may signal an injected file)
 *
 * The verdict is "compliant" only when all three lists are empty AND the
 * manifest itself was found and parseable. Missing manifest returns
 * "manifest_missing" so operators can distinguish a genuinely tampered
 * install from an older build that predates this feature.
 */
class IntegrityService {

    public const STATUS_COMPLIANT       = 'compliant';
    public const STATUS_NOT_COMPLIANT   = 'not_compliant';
    public const STATUS_MANIFEST_MISSING = 'manifest_missing';

    /** Must match COVERED_DIRS in scripts/generate-integrity.js. */
    private const COVERED_DIRS = [
        'appinfo',
        'lib',
        'js',
        'css',
        'templates',
        'img',
        'l10n',
        'sql',
        'announcements',
    ];

    /** Manifest file itself is never included in the manifest. */
    private const MANIFEST_RELATIVE_PATH = 'appinfo/integrity.json';

    /**
     * Prefixes whose contents are IGNORED by the unexpected-file walk.
     *
     * The whole `js/` subtree is skipped for two overlapping reasons:
     *
     *   1. `js/chunks/` holds Vite's content-hashed code-split chunks.
     *      Nextcloud's app upgrade path does not purge the old app directory
     *      before writing the new one, so chunk filenames from prior versions
     *      persist on disk as orphans.
     *   2. Top-level `js/*.mjs` bundles that were dropped between releases
     *      (e.g. a retired standalone-page entry like `js/timeline.mjs`)
     *      linger for the same reason.
     *
     * In both cases the orphan is not referenced by any script tag we ship
     * — the PHP templates that load JS ARE in the manifest and hash-checked,
     * so an attacker can't quietly rewrite one to pull an injected file —
     * and a browser will never load a stray .mjs on its own. Treating these
     * as "unexpected" would report every install that was once an earlier
     * version as tampered.
     *
     * The `announcements/` subtree is skipped for the same orphan reason:
     * retiring an in-app announcement removes its registry.json entry but
     * leaves the old `.md` on disk after an NC upgrade, so it would read as
     * "unexpected" on every instance that had run the prior version. Skipping
     * it is safe because nothing renders an announcement that is not listed in
     * registry.json, and registry.json IS in the manifest and hash-checked —
     * an attacker cannot surface an injected `.md` without editing a covered
     * file (see AnnouncementService::loadRegistry / loadMarkdownBody).
     *
     * Files listed IN the manifest under these prefixes are still hash-
     * checked normally (altered/missing paths still work), so intentional
     * tampering with a currently-shipped bundle, chunk, or announcement is
     * caught. Only the "on disk but absent from manifest" path is short-
     * circuited.
     */
    private const UNEXPECTED_SKIP_PREFIXES = [
        'js/',
        'announcements/',
    ];

    /** Cap the returned lists so a wildly-mismatched install can't blow up
     *  the JSON response the admin UI has to render. Truncation is signalled
     *  by the *_truncated flags in the response. */
    private const MAX_LIST_ITEMS = 500;

    public function __construct(
        private IAppManager $appManager,
    ) {}

    /**
     * Runs the full integrity check.
     *
     * @return array{
     *     status: string,
     *     manifest_version: int|null,
     *     app_version: string|null,
     *     generated_at: string|null,
     *     algorithm: string|null,
     *     files_checked: int,
     *     altered: list<string>,
     *     missing: list<string>,
     *     unexpected: list<string>,
     *     altered_truncated: bool,
     *     missing_truncated: bool,
     *     unexpected_truncated: bool,
     *     checked_at: string
     * }
     */
    public function check(): array {
        $appPath = $this->appManager->getAppPath(Application::APP_ID);
        $manifestPath = $appPath . '/' . self::MANIFEST_RELATIVE_PATH;

        $base = [
            'status'               => self::STATUS_MANIFEST_MISSING,
            'manifest_version'     => null,
            'app_version'          => null,
            'generated_at'         => null,
            'algorithm'            => null,
            'files_checked'        => 0,
            'altered'              => [],
            'missing'              => [],
            'unexpected'           => [],
            'altered_truncated'    => false,
            'missing_truncated'    => false,
            'unexpected_truncated' => false,
            'checked_at'           => gmdate('c'),
        ];

        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return $base;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return $base;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
            return $base;
        }

        $algorithm = is_string($manifest['algorithm'] ?? null) ? $manifest['algorithm'] : 'sha256';
        // Defensive: only accept algorithms hash_algos() supports and that
        // our generator can plausibly have written. sha256 is the only one
        // shipped today.
        if (!in_array($algorithm, hash_algos(), true)) {
            return $base;
        }

        $altered = [];
        $missing = [];

        foreach ($manifest['files'] as $relative => $expected) {
            if (!is_string($relative) || !is_string($expected)) {
                continue;
            }
            // Defence-in-depth: the manifest is trusted (we generate it and
            // ship it in-band with the app), but strip any traversal segment
            // just in case a broken build slipped one in.
            if (str_contains($relative, '..')) {
                continue;
            }
            $abs = $appPath . '/' . $relative;
            if (!is_file($abs)) {
                $missing[] = $relative;
                continue;
            }
            $actual = @hash_file($algorithm, $abs);
            if ($actual === false || !hash_equals($expected, $actual)) {
                $altered[] = $relative;
            }
        }

        $unexpected = $this->findUnexpected($appPath, $manifest['files']);

        [$altered, $alteredTruncated]       = $this->cap($altered);
        [$missing, $missingTruncated]       = $this->cap($missing);
        [$unexpected, $unexpectedTruncated] = $this->cap($unexpected);

        $isCompliant = empty($altered) && empty($missing) && empty($unexpected);

        return [
            'status'               => $isCompliant ? self::STATUS_COMPLIANT : self::STATUS_NOT_COMPLIANT,
            'manifest_version'     => is_int($manifest['manifest_version'] ?? null) ? $manifest['manifest_version'] : null,
            'app_version'          => is_string($manifest['app_version'] ?? null) ? $manifest['app_version'] : null,
            'generated_at'         => is_string($manifest['generated_at'] ?? null) ? $manifest['generated_at'] : null,
            'algorithm'            => $algorithm,
            'files_checked'        => count($manifest['files']),
            'altered'              => $altered,
            'missing'              => $missing,
            'unexpected'           => $unexpected,
            'altered_truncated'    => $alteredTruncated,
            'missing_truncated'    => $missingTruncated,
            'unexpected_truncated' => $unexpectedTruncated,
            'checked_at'           => gmdate('c'),
        ];
    }

    /**
     * Walk the covered directories and flag any file that isn't in the
     * expected-hash map. Excludes the manifest itself and the js/chunks/
     * subtree (see UNEXPECTED_SKIP_PREFIXES for why).
     *
     * @param string $appPath
     * @param array<string, mixed> $expected  keys are relative paths
     * @return list<string>
     */
    private function findUnexpected(string $appPath, array $expected): array {
        $unexpected = [];
        foreach (self::COVERED_DIRS as $dir) {
            $abs = $appPath . '/' . $dir;
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            /** @var \SplFileInfo $fileInfo */
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                // Skip dev artifacts the generator also skips, so a locally-
                // present sourcemap or Rollup license sidecar doesn't cause
                // a false-positive verdict.
                $basename = $fileInfo->getBasename();
                if ($basename === '' || $basename[0] === '.') {
                    continue;
                }
                if (str_ends_with($basename, '.map')) {
                    continue;
                }
                if (str_ends_with($basename, '.mjs.license')) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($appPath))), '/');
                if ($relative === self::MANIFEST_RELATIVE_PATH) {
                    continue;
                }
                foreach (self::UNEXPECTED_SKIP_PREFIXES as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        continue 2;
                    }
                }
                if (!array_key_exists($relative, $expected)) {
                    $unexpected[] = $relative;
                }
            }
        }
        sort($unexpected);
        return $unexpected;
    }

    /**
     * @param list<string> $items
     * @return array{0: list<string>, 1: bool}
     */
    private function cap(array $items): array {
        if (count($items) <= self::MAX_LIST_ITEMS) {
            return [$items, false];
        }
        return [array_slice($items, 0, self::MAX_LIST_ITEMS), true];
    }
}
