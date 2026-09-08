<?php
\OCP\Util::addScript('teamhub', 'teamhub');
// CSS extracted per-entry into the app css/ dir (see vite.config.mjs); load the
// chunk files directly. index = shared NC component styles; teamhub = app
// styles; widget-tokens = the design tokens every widget's scoped CSS reads.
//
// **`css/teamhub.css` is the manifest.** It is the @import stub the
// css-entry-points-plugin writes, it is bypassed at runtime (NC does not
// resolve its relative @imports), and it lists exactly the chunks this entry
// needs. After any change to the entry list in vite.config.mjs, diff that stub
// against the calls below — v4.8.18's fourth entry made Rollup hoist
// widget-tokens into a chunk of its own, nothing loaded it, and every widget
// lost its `--th-*` variables at once (v4.8.23).
\OCP\Util::addStyle('teamhub', 'vite-index.chunk');
\OCP\Util::addStyle('teamhub', 'vite-widget-tokens.chunk');
\OCP\Util::addStyle('teamhub', 'vite-teamhub.chunk');
?>

<div id="teamhub-app"></div>
