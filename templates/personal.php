<?php
/** @var \OCP\IL10N $l */
/** @var bool $presenceModuleEnabled */
\OCP\Util::addScript('teamhub', 'personal');
?>
<div
    id="teamhub-personal-settings"
    data-presence-module-enabled="<?php echo $_['presenceModuleEnabled'] ? '1' : '0'; ?>">
</div>
