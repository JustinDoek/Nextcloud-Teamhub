<?php
/** @var \OCP\IL10N $l */
/** @var bool $presenceModuleEnabled */
\OCP\Util::addScript('teamhub', 'personal');
// CSS extracted per-entry into the app css/ dir (see vite.config.mjs); load directly.
\OCP\Util::addStyle('teamhub', 'vite-index.chunk');
\OCP\Util::addStyle('teamhub', 'vite-personal.chunk');
?>
<div
    id="teamhub-personal-settings"
    data-presence-module-enabled="<?php echo $_['presenceModuleEnabled'] ? '1' : '0'; ?>">
</div>
