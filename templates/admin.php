<?php
/** @var \OCP\IL10N $l */
\OCP\Util::addScript('teamhub', 'admin');
?>
<div id="teamhub-admin-settings" class="section">
    <h2><?php p($l->t('TeamHub')); ?></h2>

    <!-- Wizard description -->
    <div class="teamhub-admin-block">
        <h3><?php p($l->t('Team creation wizard')); ?></h3>
        <p class="hint">
            <?php p($l->t('This text appears at the top of the "Create new team" dialog. Leave empty to show no description.')); ?>
        </p>
        <textarea
            id="teamhub-wizard-description"
            rows="3"
            style="width:100%;max-width:600px;padding:8px;box-sizing:border-box;font-family:inherit;"
            placeholder="<?php p($l->t('e.g. Fill in the details below to create a new team.')); ?>"></textarea>
    </div>

    <!-- Invite types -->
    <div class="teamhub-admin-block">
        <h3><?php p($l->t('Allowed invite types')); ?></h3>
        <p class="hint">
            <?php p($l->t('Choose which types of accounts team admins can invite. Note: email and federated invites require Circles federation to be enabled and configured on this instance.')); ?>
        </p>
        <div class="teamhub-admin-checkboxes">
            <div class="teamhub-admin-check">
                <input type="checkbox" id="teamhub-invite-user" value="user" checked disabled />
                <label for="teamhub-invite-user">
                    <?php p($l->t('Local users')); ?>
                    <em><?php p($l->t('Always enabled — local Nextcloud accounts')); ?></em>
                </label>
            </div>
            <div class="teamhub-admin-check">
                <input type="checkbox" id="teamhub-invite-group" value="group" />
                <label for="teamhub-invite-group">
                    <?php p($l->t('Groups')); ?>
                    <em><?php p($l->t('Add all members of a Nextcloud group at once')); ?></em>
                </label>
            </div>
            <div class="teamhub-admin-check">
                <input type="checkbox" id="teamhub-invite-email" value="email" />
                <label for="teamhub-invite-email">
                    <?php p($l->t('Email addresses')); ?>
                    <em><?php p($l->t('Invite external people by email (requires Circles federation)')); ?></em>
                </label>
            </div>
            <div class="teamhub-admin-check">
                <input type="checkbox" id="teamhub-invite-federated" value="federated" />
                <label for="teamhub-invite-federated">
                    <?php p($l->t('Federated users')); ?>
                    <em><?php p($l->t('Invite users from other Nextcloud instances (requires Circles federation)')); ?></em>
                </label>
            </div>
        </div>
    </div>

    <!-- Save -->
    <div style="margin-top:16px;display:flex;align-items:center;gap:16px;">
        <button id="teamhub-save-btn" class="button primary">
            <?php p($l->t('Save settings')); ?>
        </button>
        <span id="teamhub-save-ok" style="display:none;color:var(--color-success);">
            &#10003; <?php p($l->t('Saved')); ?>
        </span>
        <span id="teamhub-save-err" style="display:none;color:var(--color-error);"></span>
    </div>
</div>

<style>
.teamhub-admin-block { margin-bottom: 24px; max-width: 640px; }
.teamhub-admin-block h3 { font-size: 15px; font-weight: 600; margin: 0 0 4px; }
.teamhub-admin-checkboxes { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
.teamhub-admin-check { display: flex; align-items: flex-start; gap: 8px; }
.teamhub-admin-check input[type="checkbox"] { margin-top: 3px; flex-shrink: 0; width: 16px; height: 16px; cursor: pointer; accent-color: var(--color-primary); }
.teamhub-admin-check label { cursor: pointer; font-weight: 500; font-size: 14px; line-height: 1.4; }
.teamhub-admin-check label em { font-style: normal; font-size: 12px; color: var(--color-text-maxcontrast); display: block; }
</style>
