<?php
/** @var \OCP\IL10N $l */
/** @var bool $presenceModuleEnabled */
/** @var bool $gettingStartedHint */
\OCP\Util::addScript('teamhub', 'personal');
// Stylesheets come from this entry's manifest, css/personal.css — see the note
// in templates/main.php and the helper in templates/vite-styles.php (v4.10.11).
require_once __DIR__ . '/vite-styles.php';
teamhub_add_vite_styles('personal');
?>
<div
    id="teamhub-personal-settings"
    data-presence-module-enabled="<?php echo $_['presenceModuleEnabled'] ? '1' : '0'; ?>"
    data-getting-started-hint="<?php echo $_['gettingStartedHint'] ? '1' : '0'; ?>">
</div>
