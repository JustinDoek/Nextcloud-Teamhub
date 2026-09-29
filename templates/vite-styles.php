<?php
declare(strict_types=1);

/**
 * Load a Vite entry's stylesheets from its manifest (v4.10.11).
 *
 * The css-entry-points-plugin in `@nextcloud/vite-config` writes one stub per
 * entry — `css/teamhub.css`, `css/admin.css`, `css/personal.css` — that is
 * nothing but `@import './vite-<chunk>.chunk.css';` lines, one per chunk the
 * entry statically imports. Nextcloud does not resolve those relative
 * imports, so the stub cannot be loaded as-is; until 4.10.11 each template
 * hand-copied its list into `Util::addStyle()` calls.
 *
 * Rollup names shared chunks after one of the modules in them, and regroups
 * them whenever an entry's import graph changes — so the names in that list
 * change under any build that touches an entry. Three releases lost styles
 * this way: 4.8.23 (widget-tokens hoisted into a chunk of its own, nothing
 * loaded it), 4.10.9 (`myWork` shared by admin and teamhub, unstyled picker
 * rows) and 4.10.11 (the shared NC-component CSS renamed from `index` to
 * `localDate`; the whole app lost its component styles). The manifest was
 * right every time and the copy was wrong.
 *
 * So the templates read the manifest. One file read per page render, of a
 * file the build wrote, with a fixed pattern; if it cannot be read the page
 * renders with the app's own bundle CSS missing, which is what an
 * unreadable app directory would have meant anyway.
 *
 * @param string $entry the build entry name: 'teamhub', 'admin' or 'personal'
 */
function teamhub_add_vite_styles(string $entry): void {
    $stub = __DIR__ . '/../css/' . $entry . '.css';
    $lines = @file($stub, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        // @import './vite-teamhub.chunk.css';  →  vite-teamhub.chunk
        if (preg_match("~@import\s+'\./(vite-[A-Za-z0-9_.-]+)\.css'~", $line, $m) === 1) {
            \OCP\Util::addStyle('teamhub', $m[1]);
        }
    }
}
