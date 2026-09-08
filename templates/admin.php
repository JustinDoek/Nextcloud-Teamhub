<?php
/** @var \OCP\IL10N $l */
\OCP\Util::addScript('teamhub', 'admin');
// CSS is extracted per-entry into the app css/ dir (see vite.config.mjs) and
// loaded directly (the @import-based entry stub is bypassed). index = shared
// NC component styles; admin = this page's own styles.
// widget-tokens carries the `--th-*` design tokens the admin panels read; see
// the manifest note in templates/main.php (v4.8.23).
\OCP\Util::addStyle('teamhub', 'vite-index.chunk');
\OCP\Util::addStyle('teamhub', 'vite-widget-tokens.chunk');
\OCP\Util::addStyle('teamhub', 'vite-admin.chunk');
?>
<div id="teamhub-admin-settings"></div>
