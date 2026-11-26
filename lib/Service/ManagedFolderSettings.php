<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

/**
 * Which apps may claim Team folders as their own (v4.20.48).
 *
 * The same switch as `SharedResourceProviderSettings`, on its own key: default
 * on, the stored value lists the app ids switched **off**, and an unreadable
 * value switches every app off. Here that direction means every claimed folder
 * goes back to the team admins' review, which is what TeamHub did before
 * claims existed.
 */
class ManagedFolderSettings extends SharedResourceProviderSettings {

    public const CFG_DISABLED = 'managed_folders_disabled_apps';
}
