<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Builds the archive ZIP in a temp directory and commits it atomically.
 *
 * Responsibilities:
 *   - Create and manage the .partial working directory.
 *   - Write JSON files with consistent formatting.
 *   - Compute per-file sha256 hashes for the manifest.
 *   - Render the static index.html viewer.
 *   - Produce the final ZIP atomically (write to .tmp, rename to .zip).
 *   - Clean up temp files on success or failure.
 *
 * This class is intentionally stateless between writeFile() calls —
 * all state lives in $workDir and the in-memory $manifest accumulator
 * passed back to ArchiveService.
 */
class ArchiveBundleWriter {

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Create the .partial working directory and return its path.
     * The caller is responsible for cleanup via cleanup().
     */
    public function createWorkDir(string $tempBase, string $teamName): string {
        $slug    = $this->slugify($teamName);
        $workDir = rtrim($tempBase, '/') . '/' . $slug . '-' . time() . '.partial';

        if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            throw new \RuntimeException("Could not create work dir: {$workDir}");
        }

        foreach (['teamhub', 'circles', 'apps'] as $subdir) {
            mkdir($workDir . '/' . $subdir, 0700, true);
        }

        $this->logger->debug('[TeamHub][ArchiveBundleWriter] Work dir created', [
            'workDir' => $workDir,
            'app'     => Application::APP_ID,
        ]);

        return $workDir;
    }

    /**
     * Write $rows as a pretty-printed JSON file.
     * Applies $pseudonymizer to each row if provided.
     *
     * @param array<mixed>         $rows
     * @param string[]             $uidFields  fields to pseudonymize in each row
     * @param ArchivePseudonymizer|null $ps
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function writeJson(
        string $workDir,
        string $relativePath,
        array $rows,
        array $uidFields = [],
        ?ArchivePseudonymizer $ps = null
    ): array {
        if ($ps !== null && !empty($uidFields)) {
            $rows = array_map(fn($r) => $ps->process($r, $uidFields), $rows);
        }

        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("JSON encode failed for {$relativePath}");
        }

        return $this->writeRaw($workDir, $relativePath, $json);
    }

    /**
     * Write a single-object JSON file (not an array of rows).
     *
     * @param array<string, mixed> $data
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function writeJsonObject(string $workDir, string $relativePath, array $data): array {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("JSON encode failed for {$relativePath}");
        }
        return $this->writeRaw($workDir, $relativePath, $json);
    }

    /**
     * Write raw string content.
     *
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function writeRaw(string $workDir, string $relativePath, string $content): array {
        $fullPath = $workDir . '/' . ltrim($relativePath, '/');
        $dir      = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $bytes = file_put_contents($fullPath, $content);
        if ($bytes === false) {
            throw new \RuntimeException("Could not write: {$relativePath}");
        }

        $hash = hash('sha256', $content);

        $this->logger->debug('[TeamHub][ArchiveBundleWriter] Wrote file', [
            'path'   => $relativePath,
            'bytes'  => $bytes,
            'app'    => Application::APP_ID,
        ]);

        return [
            'path'   => $relativePath,
            'bytes'  => $bytes,
            'sha256' => $hash,
        ];
    }

    /**
     * Render the static index.html viewer and write it to the work dir.
     *
     * The viewer loads manifest.json at runtime via fetch() and renders
     * a browsable overview of the archive contents. It has no external
     * dependencies — it must work offline from a local filesystem.
     *
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function writeIndexHtml(string $workDir, string $teamName, bool $anonymized): array {
        $escapedTeamName = htmlspecialchars($teamName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $anonymizedBanner = $anonymized
            ? '<div class="banner banner--warn">This archive is pseudonymized. User identifiers have been replaced with aliases. Message and comment content is preserved unchanged.</div>'
            : '';
        $anonymizedNote = $anonymized ? 'true' : 'false';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TeamHub Archive — {$escapedTeamName}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:15px;line-height:1.6;color:#1a1a1a;background:#f5f5f5;padding:24px}
.card{background:#fff;border-radius:8px;border:1px solid #e0e0e0;padding:24px;margin-bottom:16px;max-width:900px;margin-left:auto;margin-right:auto}
h1{font-size:22px;font-weight:500;margin-bottom:4px}
h2{font-size:16px;font-weight:500;margin-bottom:12px;color:#444}
.meta{color:#666;font-size:13px;margin-bottom:8px}
.banner{border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;max-width:900px;margin-left:auto;margin-right:auto}
.banner--warn{background:#fff8e1;border:1px solid #f9a825;color:#5d4037}
.file-list{list-style:none}
.file-list li{padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px;display:flex;justify-content:space-between;align-items:baseline;gap:8px}
.file-list li:last-child{border-bottom:none}
.file-path{font-family:monospace;color:#333}
.file-size{color:#888;white-space:nowrap}
.section-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#999;margin-bottom:8px}
a{color:#0066cc;text-decoration:none}a:hover{text-decoration:underline}
.error{color:#c62828;padding:16px;background:#fff3f3;border-radius:6px;border:1px solid #ffcdd2}
</style>
</head>
<body>
{$anonymizedBanner}
<div id="root"><div class="card"><p class="meta">Loading archive manifest…</p></div></div>
<script>
const ANONYMIZED = {$anonymizedNote};
function fmt(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/1048576).toFixed(1) + ' MB';
}
function ts(unix) {
  return new Date(unix * 1000).toLocaleString(undefined, {dateStyle:'medium',timeStyle:'short'});
}
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
function section(label, items) {
  if (!items || items.length === 0) return '';
  return '<div class="section-label">' + esc(label) + '</div><ul class="file-list">'
    + items.map(f => '<li><span class="file-path"><a href="' + esc(f.path) + '">' + esc(f.path) + '</a></span><span class="file-size">' + fmt(f.bytes) + '</span></li>').join('')
    + '</ul>';
}
fetch('./manifest.json').then(r => r.json()).then(m => {
  const t = m.team;
  const r = document.getElementById('root');

  // Group apps entries by their top-level subfolder (e.g. apps/calendar, apps/files)
  function groupApps(items) {
    if (!items || items.length === 0) return '';
    const groups = {};
    items.forEach(f => {
      const parts = f.path.split('/');
      const group = parts.length >= 3 ? parts[0] + '/' + parts[1] : f.path;
      if (!groups[group]) groups[group] = { count: 0, bytes: 0, files: [] };
      groups[group].count++;
      groups[group].bytes += (f.bytes || 0);
      groups[group].files.push(f);
    });
    return Object.entries(groups).map(([grp, info]) => {
      const label = grp.replace('apps/', '').toUpperCase();
      // For large groups (many files) show a summary; for small groups show all.
      if (info.files.length > 10) {
        return '<div class="section-label">' + esc(label) + ' (' + info.count + ' files, ' + fmt(info.bytes) + ')</div>'
          + '<ul class="file-list">'
          + info.files.slice(0,5).map(f => '<li><span class="file-path"><a href="' + esc(f.path) + '">' + esc(f.path) + '</a></span><span class="file-size">' + fmt(f.bytes) + '</span></li>').join('')
          + '<li><span class="file-path" style="color:#888">… and ' + (info.files.length - 5) + ' more files — see apps/files/index.json</span></li>'
          + '</ul>';
      }
      return '<div class="section-label">' + esc(label) + '</div><ul class="file-list">'
        + info.files.map(f => '<li><span class="file-path"><a href="' + esc(f.path) + '">' + esc(f.path) + '</a></span><span class="file-size">' + fmt(f.bytes) + '</span></li>').join('')
        + '</ul>';
    }).join('');
  }

  r.innerHTML =
    '<div class="card">'
      + '<h1>' + esc(t.team_name) + '</h1>'
      + '<p class="meta">Archived ' + ts(t.archived_at) + ' by ' + esc(ANONYMIZED ? '(pseudonymized)' : t.archived_by) + '</p>'
      + (m.incomplete ? '<p style="color:#c62828">⚠ Archive is incomplete: ' + esc(m.incomplete_reason) + '</p>' : '')
    + '</div>'
    + '<div class="card"><h2>Team data</h2>' + section('TeamHub', m.contents.teamhub) + '</div>'
    + '<div class="card"><h2>Team membership</h2>' + section('Circles', m.contents.circles) + '</div>'
    + (m.contents.apps && m.contents.apps.length
        ? '<div class="card"><h2>App data</h2>' + groupApps(m.contents.apps) + '</div>'
        : '')
    + '<div class="card"><p class="meta">Format v' + esc(m.format_version)
      + ' · Produced by TeamHub v' + esc(m.produced_by.app_version)
      + ' on Nextcloud v' + esc(m.produced_by.nc_version)
      + ' · Total ' + fmt(m.total_bytes) + '</p></div>';
}).catch(e => {
  document.getElementById('root').innerHTML = '<div class="card"><p class="error">Could not load manifest.json: ' + esc(String(e)) + '</p></div>';
});
</script>
</body>
</html>
HTML;

        return $this->writeRaw($workDir, 'index.html', $html);
    }

    /**
     * Produce the final ZIP atomically.
     *
     * Writes to $zipTmpPath first. On success, renames to $zipFinalPath.
     * If ZipArchive fails, throws without creating the final file.
     *
     * @param array{path: string, bytes: int, sha256: string}[] $manifestEntries
     *        The list of entries to include in the zip (relative paths).
     */
    public function produceZip(string $workDir, string $zipTmpPath, string $zipFinalPath): void {
        $zip = new \ZipArchive();
        $res = $zip->open($zipTmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new \RuntimeException("ZipArchive::open failed with code {$res} for {$zipTmpPath}");
        }

        $this->addDirToZip($zip, $workDir, $workDir);

        $zip->close();

        if (!rename($zipTmpPath, $zipFinalPath)) {
            @unlink($zipTmpPath);
            throw new \RuntimeException("Could not rename zip from {$zipTmpPath} to {$zipFinalPath}");
        }

        $this->logger->debug('[TeamHub][ArchiveBundleWriter] ZIP produced', [
            'path'  => $zipFinalPath,
            'bytes' => filesize($zipFinalPath),
            'app'   => Application::APP_ID,
        ]);
    }

    /**
     * Recursively add directory contents to an open ZipArchive.
     */
    private function addDirToZip(\ZipArchive $zip, string $baseDir, string $currentDir): void {
        $items = scandir($currentDir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath    = $currentDir . '/' . $item;
            $relativePath = ltrim(substr($fullPath, strlen($baseDir)), '/');
            if (is_dir($fullPath)) {
                $zip->addEmptyDir($relativePath);
                $this->addDirToZip($zip, $baseDir, $fullPath);
            } else {
                $zip->addFile($fullPath, $relativePath);
            }
        }
    }

    /**
     * Delete the working directory and any temp files.
     * Called on both success (after zip is committed) and failure.
     */
    public function cleanup(string $workDir): void {
        if (!is_dir($workDir)) {
            return;
        }
        $this->rmDir($workDir);
        $this->logger->debug('[TeamHub][ArchiveBundleWriter] Work dir cleaned up', [
            'workDir' => $workDir,
            'app'     => Application::APP_ID,
        ]);
    }

    private function rmDir(string $dir): void {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Convert a team name into a filesystem-safe slug.
     */
    private function slugify(string $name): string {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? 'team';
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 64, 'UTF-8');
        return $slug !== '' ? $slug : 'team';
    }
}
