<?php
/** @var \OCP\IL10N $l */
\OCP\Util::addScript('teamhub', 'admin');
// Stylesheets come from this entry's manifest, css/admin.css — see the note
// in templates/main.php and the helper in templates/vite-styles.php (v4.10.11).
require_once __DIR__ . '/vite-styles.php';
teamhub_add_vite_styles('admin');
?>
<div id="teamhub-admin-settings"></div>
