<?php
/** @var \OCP\IL10N $l */
/** @var bool $presenceModuleEnabled */
/** @var bool $gettingStartedHint */
\OCP\Util::addScript('teamhub', 'personal');
// CSS extracted per-entry into the app css/ dir (see vite.config.mjs); load directly.
// widget-tokens carries the `--th-*` design tokens; see the manifest note in
// templates/main.php (v4.8.23).
\OCP\Util::addStyle('teamhub', 'vite-index.chunk');
\OCP\Util::addStyle('teamhub', 'vite-widget-tokens.chunk');
\OCP\Util::addStyle('teamhub', 'vite-personal.chunk');
?>
<div
    id="teamhub-personal-settings"
    data-presence-module-enabled="<?php echo $_['presenceModuleEnabled'] ? '1' : '0'; ?>"
    data-getting-started-hint="<?php echo $_['gettingStartedHint'] ? '1' : '0'; ?>">
</div>
