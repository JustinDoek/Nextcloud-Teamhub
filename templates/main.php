<?php
\OCP\Util::addScript('teamhub', 'teamhub');
// CSS extracted per-entry into the app css/ dir (see vite.config.mjs); load the
// chunk files directly. index = shared NC component styles; teamhub = app styles.
\OCP\Util::addStyle('teamhub', 'vite-index.chunk');
\OCP\Util::addStyle('teamhub', 'vite-teamhub.chunk');
?>

<div id="teamhub-app"></div>
