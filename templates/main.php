<?php
\OCP\Util::addScript('teamhub', 'teamhub');
// CSS is extracted per entry into the app css/ dir (see vite.config.mjs).
// **`css/teamhub.css` is the manifest** — the @import stub the
// css-entry-points-plugin writes, listing exactly the chunks this entry needs.
// NC cannot load it as-is (it does not resolve the relative @imports), and
// until 4.10.11 its list was hand-copied into addStyle() calls here — which
// went stale every time Rollup regrouped or renamed a shared chunk (4.8.23:
// widget-tokens hoisted, unloaded; 4.10.9: the admin ⋂ teamhub `myWork`
// chunk, unloaded; 4.10.11: the NC-component CSS renamed `index` → `localDate`,
// the whole app unstyled). Now the manifest is read instead of copied; see
// templates/vite-styles.php.
require_once __DIR__ . '/vite-styles.php';
teamhub_add_vite_styles('teamhub');
?>

<div id="teamhub-app"></div>
