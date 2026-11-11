<?php
/** @var array<string,mixed>|null $profile */
\OCP\Util::addStyle('teamhub', 'people-profile');
?>
<div class="teamhub-people-profile">
    <?php if ($profile === null): ?>
        <h1><?php p($l->t('Collaboration profile unavailable')); ?></h1>
        <p><?php p($l->t('This collaboration profile is not available. Sign in, or ask your administrator whether the People module is enabled.')); ?></p>
    <?php else: ?>
        <h1><?php p($l->t('People profile')); ?></h1>
        <h2><?php p($profile['displayName']); ?></h2>
        <?php if (empty($profile['fields'])): ?>
            <p><?php p($l->t('No collaboration details have been shared yet.')); ?></p>
        <?php else: ?>
            <?php /* Labels arrive translated from PeopleDirectoryService, so
                     this page and the section on the Nextcloud profile cannot
                     drift apart over what a field is called. */ ?>
            <dl class="teamhub-people-profile__fields">
                <?php foreach ($profile['fields'] as $field): ?>
                    <div class="teamhub-people-profile__field">
                        <dt><?php p($field['label']); ?></dt>
                        <dd><?php p($field['value']); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    <?php endif; ?>
</div>
