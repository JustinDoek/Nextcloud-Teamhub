<template>
    <div class="teamhub-admin">

        <!-- Tab bar -->
        <div class="teamhub-admin-tabs" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                role="tab"
                class="teamhub-admin-tab"
                :class="{ 'teamhub-admin-tab--active': activeTab === tab.id }"
                :aria-selected="activeTab === tab.id"
                :aria-controls="'tab-panel-' + tab.id"
                @click="activeTab = tab.id">
                <component :is="tab.icon" :size="18" />
                {{ tab.label }}
            </button>
        </div>

        <!-- ── Tab: Team creation ─────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'creation'"
            id="tab-panel-creation"
            role="tabpanel"
            class="teamhub-admin-panel">

            <!-- v4.6.2 — this tab autosaves; the Save row it used to share
                 with the Presence tab is gone. Same status region as the
                 Integrations tab: transient only, so a screen reader hears the
                 outcome of a change it did not press a button for, and the
                 container holds its height when empty so nothing shifts as
                 saving/saved appears and clears. -->
            <div class="admin-autosave" role="status" aria-live="polite">
                <template v-if="saving">
                    <NcLoadingIcon :size="16" />
                    <span>{{ t('teamhub', 'Saving…') }}</span>
                </template>
                <span v-else-if="saveError" class="admin-autosave__err">{{ saveError }}</span>
                <span v-else-if="saved" class="admin-autosave__ok">✓ {{ t('teamhub', 'Settings saved') }}</span>
            </div>

            <!-- v4.4.4 — first-run setup checklist (onboarding plan § 3.2).
                 Deliberately NOT a wizard with stored steps: every row is
                 derived from live config on each render, so it can never
                 claim something is configured when it isn't. That also means
                 it stays useful after first run as a standing summary.
                 Dismissal is instance-level (appconfig), not per-user —
                 "this instance has been reviewed" is a fact about the
                 instance. Dismissing is reversible via the button below, so
                 an admin can't lose the checklist permanently. -->
            <div v-if="!form.onboardingChecklistDismissed" class="admin-setup">
                <div class="admin-setup__head">
                    <h3 class="admin-setup__title">{{ t('teamhub', 'Setup checklist') }}</h3>
                    <!-- TRANSLATORS: button label — hides the setup checklist
                         from this instance's admin settings. "Dismiss" here
                         means hide/close, not reject or decline. -->
                    <NcButton
                        variant="tertiary"
                        :aria-label="t('teamhub', 'Dismiss setup checklist')"
                        @click="setChecklistDismissed(true)">
                        {{ t('teamhub', 'Dismiss') }}
                    </NcButton>
                </div>
                <p class="admin-setup__intro">
                    {{ t('teamhub', 'Current state of the settings that most affect how TeamHub behaves on this server. These rows read live configuration, so they always reflect what is set right now.') }}
                </p>

                <div class="admin-setup__rows">
                    <div v-for="row in setupChecklist" :key="row.id" class="admin-setup__row">
                        <span
                            class="admin-setup__indicator"
                            :class="'admin-setup__indicator--' + row.state"
                            aria-hidden="true">
                            {{ row.glyph }}
                        </span>
                        <span class="admin-setup__label">{{ row.label }}</span>
                        <span class="admin-setup__value">{{ row.value }}</span>
                        <span v-if="row.hint" class="admin-setup__hint">{{ row.hint }}</span>
                    </div>
                </div>
            </div>
            <div v-else class="admin-setup__restore">
                <NcButton variant="tertiary" @click="setChecklistDismissed(false)">
                    {{ t('teamhub', 'Show setup checklist') }}
                </NcButton>
            </div>

            <NcSettingsSection
                :name="t('teamhub', 'Team creation wizard')"
                :description="t('teamhub', 'This text is shown at the top of the Create new team dialog. Leave empty to show no description.')">
                <!-- v4.6.2 — debounced autosave, mirroring the IntraVox path
                     field on the Integrations tab. The value is not a secret
                     and the write is idempotent, so saving once the admin
                     stops typing is safe. -->
                <NcTextArea
                    v-model="form.wizardDescription"
                    :placeholder="t('teamhub', 'e.g. Fill in the details below to create a new team.')"
                    :rows="3"
                    @update:model-value="onWizardDescriptionInput" />
            </NcSettingsSection>

            <!-- v4.6.13 — team expiration. Lives on this tab because the date
                 itself is chosen in the create wizard; this is the one knob
                 that governs it instance-wide. -->
            <NcSettingsSection
                :name="t('teamhub', 'Team expiration')"
                :description="t('teamhub', 'Collaboration and Project teams can be given an optional expiration date in the create-team wizard. Departments cannot — they are not time-bound. Nothing is deleted when the date passes: the team keeps working, and its administrators plus every Nextcloud administrator get a reminder in My Work that they can act on.')">
                <div class="admin-inline-field">
                    <label for="admin-expiry-warning-days" class="admin-inline-field__label">
                        {{ t('teamhub', 'Warn this many days before the date') }}
                    </label>
                    <input
                        id="admin-expiry-warning-days"
                        v-model.number="form.expiryWarningDays"
                        type="number"
                        class="admin-number-input"
                        :min="expiryWarningDaysMin"
                        :max="expiryWarningDaysMax"
                        aria-describedby="admin-expiry-warning-hint"
                        @change="onExpiryWarningDaysInput" />
                </div>
                <p id="admin-expiry-warning-hint" class="admin-section-hint">
                    {{ t('teamhub', 'Between {min} and {max}. A value outside that range is stored as the nearest allowed one.', { min: expiryWarningDaysMin, max: expiryWarningDaysMax }) }}
                </p>
            </NcSettingsSection>

            <NcSettingsSection
                :name="t('teamhub', 'Creation permissions')"
                :description="t('teamhub', 'Only members of the selected groups can create teams. Leave empty to allow all users.')">

                <!-- Selected group chips -->
                <div v-if="selectedGroups.length" class="admin-group-chips">
                    <span
                        v-for="g in selectedGroups"
                        :key="g.id"
                        class="admin-group-chip">
                        <AccountGroup :size="14" />
                        {{ g.displayName }}
                        <button
                            class="admin-group-chip__remove"
                            :aria-label="t('teamhub', 'Remove {name}', { name: g.displayName })"
                            @click="removeGroup(g)">
                            <CloseIcon :size="14" />
                        </button>
                    </span>
                </div>

                <!-- Group typeahead search -->
                <div class="admin-group-search">
                    <NcTextField
                        v-model="groupQuery"
                        :label="t('teamhub', 'Search for a group')"
                        :placeholder="t('teamhub', 'Type to search groups…')"
                        @input="onGroupSearch" />

                    <ul v-if="groupResults.length" class="admin-group-results">
                        <li
                            v-for="g in groupResults"
                            :key="g.id"
                            class="admin-group-result"
                            @mousedown.prevent="addGroup(g)">
                            <AccountGroup :size="18" />
                            <span class="admin-group-result__name">{{ g.displayName }}</span>
                            <span class="admin-group-result__id">{{ g.id }}</span>
                        </li>
                    </ul>
                    <p v-else-if="groupSearching" class="admin-group-hint">
                        <NcLoadingIcon :size="16" /> {{ t('teamhub', 'Searching…') }}
                    </p>
                    <p v-else-if="groupQuery.length >= 1 && !groupSearching" class="admin-group-hint">
                        {{ t('teamhub', 'No groups found') }}
                    </p>
                </div>
            </NcSettingsSection>

            <!-- ── Allowed invite types (moved here from the Invitations tab
                 in v4.4.13) ────────────────────────────────────────────────
                 Sits directly under Creation permissions: who may create a
                 team and whom they may then invite is one decision, read top
                 to bottom. Laid out as a compact grid — five booleans do not
                 need five full-width rows. -->
            <NcSettingsSection
                :name="t('teamhub', 'Allowed invite types')"
                :description="t('teamhub', 'Choose which types of accounts team admins can invite to a team.')">
                <!-- v4.6.2 — autosave on change. Written as :model-value +
                     an explicit handler rather than v-model, matching the
                     module switches on the Integrations tab: it makes the
                     assignment-then-save order unambiguous instead of relying
                     on how Vue merges v-model's generated listener with a
                     second @update:model-value on the same element. -->
                <div class="admin-invite-types">
                    <NcCheckboxRadioSwitch
                        :model-value="true"
                        :disabled="true"
                        type="checkbox">
                        {{ t('teamhub', 'Local users') }}
                        <template #description>{{ t('teamhub', 'Always enabled — local Nextcloud accounts') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="inviteGroup"
                        type="checkbox"
                        @update:model-value="inviteGroup = $event; save()">
                        {{ t('teamhub', 'Groups') }}
                        <template #description>{{ t('teamhub', 'Add all members of a Nextcloud group at once') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="inviteCircle"
                        type="checkbox"
                        @update:model-value="inviteCircle = $event; save()">
                        {{ t('teamhub', 'Teams') }}
                        <template #description>{{ t('teamhub', 'Add another team as a sub-team member — all its members gain access') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="inviteEmail"
                        type="checkbox"
                        @update:model-value="inviteEmail = $event; save()">
                        {{ t('teamhub', 'Email addresses') }}
                        <template #description>{{ t('teamhub', 'Invite external people by email (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="inviteFederated"
                        type="checkbox"
                        @update:model-value="inviteFederated = $event; save()">
                        {{ t('teamhub', 'Federated users') }}
                        <template #description>{{ t('teamhub', 'Invite users from other Nextcloud instances (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                </div>
            </NcSettingsSection>

            <!-- ── Team Folders integration status ────────────────────────── -->
            <NcSettingsSection
                :name="t('teamhub', 'Team Folders integration')"
                :description="t('teamhub', 'When Team Folders is installed and properly configured, TeamHub will automatically create a Team Folder for each new team instead of a shared personal folder. Team Folders are owned by the server, not by individual users.')">

                <div class="admin-gf-status">
                    <div class="admin-gf-status__row">
                        <span
                            class="admin-gf-status__indicator"
                            :class="gfDelegation.groupFoldersInstalled ? 'admin-gf-status__indicator--ok' : 'admin-gf-status__indicator--warn'"
                            aria-hidden="true">
                            {{ gfDelegation.groupFoldersInstalled ? '✓' : '✗' }}
                        </span>
                        <span class="admin-gf-status__label">
                            {{ t('teamhub', 'Team Folders app installed') }}
                        </span>
                        <span v-if="!gfDelegation.groupFoldersInstalled" class="admin-gf-status__hint">
                            {{ t('teamhub', 'Install the Team Folders app to enable automatic team folder creation.') }}
                        </span>
                    </div>

                    <div class="admin-gf-status__row">
                        <span
                            class="admin-gf-status__indicator"
                            :class="gfDelegation.teamCreatorGroupsConfigured ? 'admin-gf-status__indicator--ok' : 'admin-gf-status__indicator--warn'"
                            aria-hidden="true">
                            {{ gfDelegation.teamCreatorGroupsConfigured ? '✓' : '⚠' }}
                        </span>
                        <span class="admin-gf-status__label">
                            {{ t('teamhub', 'Team-creator group configured above') }}
                        </span>
                        <span v-if="!gfDelegation.teamCreatorGroupsConfigured" class="admin-gf-status__hint">
                            {{ t('teamhub', 'Set a team-creator group above. Without one, group creation permissions cannot be verified.') }}
                        </span>
                    </div>

                    <p
                        v-if="gfDelegation.groupFoldersInstalled && gfDelegation.teamCreatorGroupsConfigured"
                        class="admin-gf-status__summary admin-gf-status__summary--ok">
                        {{ t('teamhub', 'Team Folders is correctly configured. New teams will automatically get a Team Folder.') }}
                    </p>
                    <p
                        v-else-if="gfDelegation.groupFoldersInstalled"
                        class="admin-gf-status__summary admin-gf-status__summary--warn">
                        {{ t('teamhub', 'Team Folders is installed but not fully configured. New teams will fall back to shared personal folders until the issues above are resolved.') }}
                    </p>
                    <p
                        v-else
                        class="admin-gf-status__summary">
                        {{ t('teamhub', 'Team Folders is not installed. New teams will use shared personal folders.') }}
                    </p>
                </div>
            </NcSettingsSection>

        </div>

        <!-- ─────────────────────────────────────────────────────────────────
             Import / Export tab (v4.6.10)

             Was a section on Team creation (v4.6.6), on the reasoning that
             bulk creation obeys the team-creation policy configured above it.
             That reasoning still holds — it just lost to volume. The panel
             carries an upload control, a format reference table, a preview
             grid, a progress pump and a results download; as the last section
             of a tab that already had four, the tab stopped being readable.

             `v-if` (not `v-show`) so the panel does not mount — and does not
             fetch its run history — while another tab is open. Same lazy
             pattern as MyWorkAdminSettings.

             Consequence worth knowing: switching tabs mid-import unmounts the
             panel, so its beforeunload warning goes with it. Harmless by
             design — the run is durable server-side and TeamImportJob finishes
             an abandoned one — but it is why that warning is a courtesy, not
             the thing protecting the run.
             ───────────────────────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'importexport'"
            id="tab-panel-importexport"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Bulk team import')"
                :description="t('teamhub', 'Create many teams at once from a CSV file, each with its own owner and team admins.')">
                <TeamImportPanel v-if="activeTab === 'importexport'" />
            </NcSettingsSection>

            <!-- v4.6.14 — export sits below import deliberately: the two share
                 one CSV contract, and the column reference the importer renders
                 is the documentation for both.

                 v4.6.28 — export is a licensed feature; import is not. Getting
                 data *in* must keep working on a lapsed instance, and a customer
                 who cannot export is a customer who can still leave — the CSV is
                 the same file either way, so the gate is on the side that is a
                 convenience, not the side that is a lock-in. The banner rather
                 than a hidden section: this is the admin who buys the license,
                 and telling them the feature exists is the point (SKILLS.md's
                 hide-it rule is about roles, not licenses). -->
            <NcSettingsSection
                :name="t('teamhub', 'Bulk team export')"
                :description="t('teamhub', 'Write teams out to a CSV file in the importer’s own format, either all of them or a selection.')">
                <TeamExportPanel v-if="licenseActive && activeTab === 'importexport'" />
                <div v-else-if="!licenseActive" class="integrity-banner integrity-banner--info">
                    <InformationOutline :size="18" />
                    <span>
                        {{ t('teamhub', 'Bulk team export requires an active TeamHub license. Add or renew a license in the License tab to unlock it.') }}
                    </span>
                </div>
            </NcSettingsSection>
        </div>
        <!-- ── Tab: Integrations ─────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'integrations'"
            id="tab-panel-integrations"
            role="tabpanel"
            class="teamhub-admin-panel">

            <!-- v4.4.14 — the idle "changes are saved automatically" line
                 was removed. The transient states remain so a screen reader
                 (aria-live) hears the outcome of a change it did not click a
                 button for; the container reserves its height when empty so
                 nothing shifts as saving/saved appears and clears. -->
            <div class="admin-autosave" role="status" aria-live="polite">
                <template v-if="saving">
                    <NcLoadingIcon :size="16" />
                    <span>{{ t('teamhub', 'Saving…') }}</span>
                </template>
                <span v-else-if="saveError" class="admin-autosave__err">{{ saveError }}</span>
                <span v-else-if="saved" class="admin-autosave__ok">✓ {{ t('teamhub', 'Settings saved') }}</span>
            </div>

            <!-- v4.4.13 — four full NcSettingsSections (two toggles, two text
                 fields) became two sections of compact rows. Each item is now
                 one row: name + description on the left, control on the
                 right, instead of a section header, a paragraph, a control
                 and a hint stacked per setting. -->
            <NcSettingsSection :name="t('teamhub', 'Modules')">
                <div class="admin-compact-rows">
                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'Presence module') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'When enabled, team admins can activate a Presence tab for their team and members can set their weekly schedule. When disabled, all presence UI is hidden across the app.') }}</span>
                        </div>
                        <NcCheckboxRadioSwitch
                            class="admin-compact-row__control"
                            :model-value="form.presenceModuleEnabled"
                            type="switch"
                            :aria-label="t('teamhub', 'Enable presence module for all teams')"
                            @update:model-value="form.presenceModuleEnabled = $event; if (!$event && activeTab === 'presence') { activeTab = 'integrations' } save()">
                            {{ form.presenceModuleEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                        </NcCheckboxRadioSwitch>
                    </div>

                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'Decisions module') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'When enabled, team admins can activate Decisions for their team. Members can record and track decisions through the message stream. When disabled, all Decisions UI is hidden across the app.') }}</span>
                        </div>
                        <NcCheckboxRadioSwitch
                            class="admin-compact-row__control"
                            :model-value="form.decisionsModuleEnabled"
                            type="switch"
                            :aria-label="t('teamhub', 'Enable Decisions module for all teams')"
                            @update:model-value="form.decisionsModuleEnabled = $event; save()">
                            {{ form.decisionsModuleEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                        </NcCheckboxRadioSwitch>
                    </div>

                    <!-- v4.8.31 — File reviews had a switch here and no longer
                         does. A review is requested from the Files app and then
                         lives entirely in My Work: that is where a reviewer
                         finds it, completes it, and where the requester closes
                         it. My Work is licensed, so a switched-on unlicensed
                         instance could create reviews nobody could ever see —
                         a broken state an administrator had to opt into and
                         could not diagnose. It follows the licence now. -->
                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'File reviews') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'Members can ask teammates to review a file from its menu in the Files app. Reviews are carried out in My Work, so they follow the license: available while one is active, and absent otherwise. There is nothing to switch here.') }}</span>
                        </div>
                        <span class="admin-compact-row__control admin-compact-row__derived">
                            {{ licenseActive ? t('teamhub', 'Available') : t('teamhub', 'Needs a license') }}
                        </span>
                    </div>

                    <!-- v4.9.16 — OpenProject is a licensed module with a switch:
                         licensed like File reviews, but off by default because it
                         is useless without an OpenProject to talk to, so the
                         administrator who has one opts in. The switch is shown
                         only while a licence is active; unlicensed, the row reads
                         "Needs a license" like the one above. -->
                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'OpenProject module') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'When enabled, teams can be created from the OpenProject project template, with the project on the team home and its work packages in My Work. Needs the OpenProject Integration app configured with an OpenProject host. When disabled, all OpenProject UI is hidden across the app; existing teams keep their link.') }}</span>
                        </div>
                        <NcCheckboxRadioSwitch
                            v-if="licenseActive"
                            class="admin-compact-row__control"
                            :model-value="form.openProjectModuleEnabled"
                            type="switch"
                            :aria-label="t('teamhub', 'Enable OpenProject module for all teams')"
                            @update:model-value="form.openProjectModuleEnabled = $event; save()">
                            {{ form.openProjectModuleEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                        </NcCheckboxRadioSwitch>
                        <span v-else class="admin-compact-row__control admin-compact-row__derived">
                            {{ t('teamhub', 'Needs a license') }}
                        </span>
                    </div>
                </div>
            </NcSettingsSection>

            <NcSettingsSection :name="t('teamhub', 'App integrations')">
                <div class="admin-compact-rows">
                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'IntraVox integration') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'When IntraVox is enabled for a team, TeamHub creates a page at this path inside IntraVox. Use the format language/folder (e.g. en/teamhub or nl/teamhub). The folder must already exist in IntraVox.') }}</span>
                            <span class="admin-compact-row__desc">
                                {{ t('teamhub', 'Team pages will be created at: IntraVox / {path} / team-name', { path: form.intravoxParentPath || 'en/teamhub' }) }}
                            </span>
                        </div>
                        <!-- Debounced autosave: the path is not a secret and
                             writing it repeatedly is idempotent, so saving as
                             the admin stops typing is safe. -->
                        <NcTextField
                            class="admin-compact-row__control admin-compact-row__control--field"
                            v-model="form.intravoxParentPath"
                            :label="t('teamhub', 'IntraVox parent path')"
                            :placeholder="t('teamhub', 'e.g. en/teamhub')"
                            @update:model-value="onIntravoxPathInput" />
                    </div>

                    <div class="admin-compact-row">
                        <div class="admin-compact-row__text">
                            <span class="admin-compact-row__name">{{ t('teamhub', 'RoomVox integration') }}</span>
                            <span class="admin-compact-row__desc">{{ t('teamhub', 'Paste a RoomVox API token (rvx_...) here to let TeamHub book meeting rooms when a user picks one in the meeting wizard. Create the token in RoomVox under Settings → API Tokens; it needs the “book” scope. The token is stored encrypted in app configuration and never returned to the browser.') }}</span>
                            <span class="admin-compact-row__desc">
                                {{ form.roomvoxTokenConfigured
                                    ? t('teamhub', 'A token is currently configured. Leave the field empty to keep it, paste a new value to replace it, or type __CLEAR__ to remove it.')
                                    : t('teamhub', 'No token configured yet. Without one the meeting wizard cannot book rooms via RoomVox even if RoomVox is installed.') }}
                            </span>
                        </div>
                        <!-- Saved on blur, NOT debounced: a debounce mid-typing
                             would POST partial API tokens to the server and
                             leave truncated secrets in the request log. Blur
                             means "I am done with this field". -->
                        <NcTextField
                            class="admin-compact-row__control admin-compact-row__control--field"
                            v-model="form.roomvoxApiToken"
                            type="password"
                            :label="t('teamhub', 'RoomVox API token')"
                            :placeholder="form.roomvoxTokenConfigured ? t('teamhub', '••••••••• (configured — leave empty to keep)') : 'rvx_…'"
                            @blur="onRoomvoxTokenBlur" />
                    </div>
                </div>
            </NcSettingsSection>

            <NcSettingsSection
                :name="t('teamhub', 'Registered integrations')"
                :description="t('teamhub', 'Integrations registered by installed apps via the TeamHub API. Registration and deregistration require NC admin access and are done via the REST API or the app\'s own settings.')">

                <div v-if="integrationsLoading" class="admin-integrations-loading">
                    <NcLoadingIcon :size="24" />
                    <span>{{ t('teamhub', 'Loading integrations…') }}</span>
                </div>

                <div v-else-if="integrationsError" class="admin-integrations-error">
                    {{ integrationsError }}
                </div>

                <!--
                    Only show EXTERNAL (non-builtin) integrations.
                    Built-in NC apps (Talk, Files, Calendar, Deck) are seeded into
                    the registry automatically and did not register via the API.
                    They are not third-party integrations and must not appear here.
                -->
                <div v-else-if="externalIntegrations.length === 0" class="admin-integrations-empty">
                    {{ t('teamhub', 'No third-party integrations registered yet.') }}
                </div>

                <div v-else class="admin-integrations-list">
                    <div
                        v-for="item in externalIntegrations"
                        :key="item.id"
                        class="admin-integration-row">

                        <div class="admin-integration-row__body">
                            <div class="admin-integration-row__header">
                                <!-- App icon — svg → png → hide fallback -->
                                <img
                                    :src="appIconUrl(item.app_id)"
                                    :alt="item.app_id"
                                    class="admin-integration-row__icon"
                                    @error="onAppIconError($event, item)" />
                                <span class="admin-integration-row__title">{{ item.title }}</span>
                                <span class="admin-integration-row__appid">{{ item.app_id }}</span>
                                <span
                                    class="admin-integration-row__badge"
                                    :class="'admin-integration-row__badge--' + item.integration_type">
                                    {{ item.integration_type === 'widget' ? t('teamhub', 'Widget') : t('teamhub', 'Tab') }}
                                </span>
                            </div>
                            <div v-if="item.description" class="admin-integration-row__desc">
                                {{ item.description }}
                            </div>
                            <div class="admin-integration-row__urls">
                                <span v-if="item.data_url">
                                    <strong>{{ t('teamhub', 'Data URL:') }}</strong> {{ item.data_url }}
                                </span>
                                <span v-if="item.iframe_url">
                                    <strong>{{ t('teamhub', 'iFrame URL:') }}</strong> {{ item.iframe_url }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Statistics ───────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'statistics'"
            id="tab-panel-statistics"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Instance summary')"
                :description="t('teamhub', 'Aggregate counts for this Nextcloud instance. Unique team members counts every distinct person who has access to at least one team — directly, via a group, or via a sub-team — and is the metric per-seat licensing keys off.')">

                <div v-if="telemetryLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <div v-else class="admin-stat-grid">
                    <div class="admin-stat-card">
                        <div class="admin-stat-card__value">{{ telemetry.preview && telemetry.preview.team_count != null ? telemetry.preview.team_count : '—' }}</div>
                        <div class="admin-stat-card__label">{{ t('teamhub', 'Teams') }}</div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-card__value">{{ telemetry.preview && telemetry.preview.unique_team_members != null ? telemetry.preview.unique_team_members : '—' }}</div>
                        <div class="admin-stat-card__label">{{ t('teamhub', 'Unique team members') }}</div>
                    </div>
                </div>
            </NcSettingsSection>

            <!-- ── Find teams for a user (moved from Compliance in v4.3.0) ── -->
            <NcSettingsSection
                :name="t('teamhub', 'Find teams for a user')"
                :description="t('teamhub', 'Search for a Nextcloud user to see every team they belong to and their role in each. Useful when a colleague changes jobs and you need to remove them from — or add them to — the right teams. Direct memberships can be removed from here; memberships inherited through a group or sub-team must be removed from that source.')">

                <!-- User search -->
                <div class="audit-user-lookup">
                    <NcTextField
                        v-model="audit_userQuery"
                        :label="t('teamhub', 'Search user')"
                        :placeholder="t('teamhub', 'Type a username or display name…')"
                        class="audit-user-lookup__search"
                        @input="onAuditUserQueryInput"
                        @keydown.enter.prevent="runAuditUserSearchNow">
                        <template #icon>
                            <MagnifyIcon :size="18" />
                        </template>
                    </NcTextField>

                    <ul v-if="audit_userResults.length" class="admin-owner-results audit-user-lookup__results">
                        <li
                            v-for="u in audit_userResults"
                            :key="u.uid"
                            class="admin-owner-result"
                            @mousedown.prevent="selectAuditUser(u)">
                            {{ u.displayName }}
                            <span class="admin-owner-result__uid">({{ u.uid }})</span>
                        </li>
                    </ul>
                    <p v-else-if="audit_userSearching" class="admin-section-hint">
                        <NcLoadingIcon :size="14" /> {{ t('teamhub', 'Searching…') }}
                    </p>
                </div>

                <!-- Selected user header + clear -->
                <div v-if="audit_selectedUser" class="audit-user-selected">
                    <span class="audit-user-selected__label">{{ t('teamhub', 'Showing teams for:') }}</span>
                    <strong>{{ audit_selectedUser.displayName }}</strong>
                    <span class="admin-owner-result__uid">({{ audit_selectedUser.uid }})</span>
                    <NcButton
                        variant="tertiary"
                        :aria-label="t('teamhub', 'Clear selected user')"
                        @click="clearAuditUser">
                        {{ t('teamhub', 'Clear') }}
                    </NcButton>
                </div>

                <!-- Loading -->
                <div v-if="audit_teamsLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                    <span>{{ t('teamhub', 'Loading teams…') }}</span>
                </div>

                <!-- Error -->
                <div v-else-if="audit_teamsError" class="admin-error">
                    {{ audit_teamsError }}
                </div>

                <!-- Empty state for the selected user -->
                <div v-else-if="audit_selectedUser && audit_teamRows.length === 0" class="admin-empty">
                    {{ t('teamhub', 'This user is not a member of any team.') }}
                </div>

                <!-- Result table -->
                <template v-else-if="audit_selectedUser && audit_teamRows.length">
                    <div
                        class="maint-grid audit-user-grid"
                        role="table"
                        :aria-label="t('teamhub', 'Teams this user belongs to')"
                        aria-live="polite">

                        <!-- Header row -->
                        <div class="maint-grid__head" role="row">
                            <div class="maint-grid__cell audit-user-grid__cell--check" role="columnheader">
                                <input
                                    type="checkbox"
                                    :checked="audit_allRemovableSelected"
                                    :indeterminate.prop="audit_someRemovableSelected && !audit_allRemovableSelected"
                                    :disabled="!audit_anyRemovable"
                                    :aria-label="t('teamhub', 'Select all removable teams')"
                                    @change="toggleSelectAllRemovable">
                            </div>
                            <div class="maint-grid__cell" role="columnheader">{{ t('teamhub', 'Team') }}</div>
                            <!-- TRANSLATORS: column header showing the user's role in a team (Owner, Admin, Moderator, Member) -->
                            <div class="maint-grid__cell" role="columnheader">{{ t('teamhub', 'Role') }}</div>
                            <div class="maint-grid__cell" role="columnheader">{{ t('teamhub', 'Owner') }}</div>
                            <!-- TRANSLATORS: column header explaining how the user got into this team (direct, via group, via sub-team) -->
                            <div class="maint-grid__cell" role="columnheader">{{ t('teamhub', 'Membership') }}</div>
                        </div>

                        <!-- Data rows -->
                        <div
                            v-for="row in audit_teamRows"
                            :key="row.teamId"
                            class="maint-grid__row"
                            role="row">

                            <!-- Checkbox cell -->
                            <div class="maint-grid__cell audit-user-grid__cell--check" role="cell">
                                <input
                                    type="checkbox"
                                    :checked="audit_selectedTeamIds.includes(row.teamId)"
                                    :disabled="!row.removable"
                                    :aria-label="t('teamhub', 'Select {team}', { team: row.teamName })"
                                    @change="toggleAuditRow(row)">
                            </div>

                            <!-- Team name -->
                            <div class="maint-grid__cell" role="cell">
                                <span class="maint-team-name">{{ row.teamName }}</span>
                                <div v-if="row.teamDescription" class="audit-user-grid__desc">
                                    {{ row.teamDescription }}
                                </div>
                            </div>

                            <!-- Role chip -->
                            <div class="maint-grid__cell" role="cell">
                                <span
                                    class="audit-user-grid__role"
                                    :class="`audit-user-grid__role--${row.role.toLowerCase()}`">
                                    {{ auditRoleLabel(row.role) }}
                                </span>
                            </div>

                            <!-- Owner -->
                            <div class="maint-grid__cell" role="cell">
                                <span v-if="row.ownerUid" class="maint-owner-name">
                                    {{ row.ownerDisplayName || row.ownerUid }}
                                    <span class="maint-owner-uid">({{ row.ownerUid }})</span>
                                </span>
                                <span v-else class="maint-no-owner">{{ t('teamhub', 'No owner') }}</span>
                            </div>

                            <!-- Membership source / blocking reason -->
                            <div class="maint-grid__cell" role="cell">
                                <template v-if="row.isOwner">
                                    <!-- TRANSLATORS: shown next to an Owner row to explain why the remove checkbox is disabled -->
                                    <span class="audit-user-grid__note audit-user-grid__note--warn">
                                        {{ t('teamhub', 'Owner — reassign in the Maintenance tab first') }}
                                    </span>
                                </template>
                                <template v-else-if="row.source === 'direct'">
                                    <!-- TRANSLATORS: badge meaning the user was added to this team individually, not via a group or sub-team -->
                                    <span class="audit-user-grid__source audit-user-grid__source--direct">
                                        {{ t('teamhub', 'Direct') }}
                                    </span>
                                </template>
                                <template v-else-if="row.source === 'group'">
                                    <span class="audit-user-grid__source audit-user-grid__source--inherited">
                                        {{ t('teamhub', 'Via group: {name}', { name: row.sourceName || '?' }) }}
                                    </span>
                                </template>
                                <template v-else-if="row.source === 'team'">
                                    <span class="audit-user-grid__source audit-user-grid__source--inherited">
                                        {{ t('teamhub', 'Via team: {name}', { name: row.sourceName || '?' }) }}
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="audit-user-grid__source audit-user-grid__source--inherited">
                                        {{ t('teamhub', 'Inherited (source unknown)') }}
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Action bar -->
                    <div class="audit-user-actions">
                        <span class="audit-user-actions__summary">
                            {{ n('teamhub', '{n} team selected', '{n} teams selected', audit_selectedTeamIds.length, { n: audit_selectedTeamIds.length }) }}
                        </span>
                        <NcButton
                            variant="error"
                            :disabled="audit_selectedTeamIds.length === 0 || audit_removeBusy"
                            @click="openAuditRemoveConfirm">
                            <template #icon>
                                <NcLoadingIcon v-if="audit_removeBusy" :size="18" />
                                <AccountRemoveIcon v-else :size="18" />
                            </template>
                            {{ t('teamhub', 'Remove from selected teams') }}
                        </NcButton>
                    </div>
                </template>

                <!-- Confirm dialog -->
                <NcDialog
                    v-if="audit_removeConfirmOpen"
                    :name="t('teamhub', 'Remove user from teams?')"
                    :buttons="auditRemoveDialogButtons"
                    size="normal"
                    @closing="audit_removeConfirmOpen = false">
                    <p>
                        {{ n('teamhub',
                              'You are about to remove {user} from {n} team. This cannot be undone.',
                              'You are about to remove {user} from {n} teams. This cannot be undone.',
                              audit_selectedTeamIds.length,
                              { user: audit_selectedUser ? audit_selectedUser.displayName : '', n: audit_selectedTeamIds.length }) }}
                    </p>
                </NcDialog>
            </NcSettingsSection>

            <!-- ── Telemetry contents (restored 4.3.1; reordered + license-gated 4.3.4)
                 Human-readable overview of every field the daily anonymous
                 report would include. NO toggle — telemetry is either off
                 (license present) or on (unlicensed / soft-lock); the
                 admin-settable checkbox was retired in v4.3.0 in favour of
                 the license-derived rule.

                 On LICENSED instances (telemetry.enabled === false) we render
                 only the status banner and stop — no point showing an admin
                 what would be sent when nothing IS being sent. On unlicensed
                 (or soft-locked) instances the full grouped field list
                 renders under the banner. -->
            <NcSettingsSection
                :name="t('teamhub', 'Telemetry contents')"
                :description="t('teamhub', 'The fields listed here are everything TeamHub would include in an anonymous daily usage report. An active license (paid or trial) disables all telemetry — nothing on this list leaves the instance while your license is active. Unlicensed and soft-locked instances contribute this aggregate for capacity planning; no user IDs, no message bodies, no custom-link URLs (only bare hostnames).')">

                <div v-if="telemetryLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <template v-else>
                    <div
                        class="telemetry-status-note"
                        :class="{ 'telemetry-status-note--off': !telemetry.enabled }">
                        <strong v-if="telemetry.enabled">{{ t('teamhub', 'Telemetry is ON') }}</strong>
                        <strong v-else>{{ t('teamhub', 'Telemetry is OFF') }}</strong>
                        <span>
                            {{ telemetry.enabled
                                ? t('teamhub', 'This instance has no active license, so the fields below are collected daily and sent to {url}.', { url: telemetry.report_url })
                                : t('teamhub', 'An active license was detected — no data on this list is being sent.') }}
                        </span>
                    </div>

                    <template v-if="telemetry.enabled">
                        <div
                            v-for="group in telemetryContentGroups"
                            :key="group.title"
                            class="telemetry-group">
                            <h4 class="telemetry-group__title">{{ group.title }}</h4>
                            <dl class="telemetry-group__list">
                                <template v-for="field in group.fields" :key="field.key">
                                    <dt class="telemetry-group__label">{{ field.label }}</dt>
                                    <dd class="telemetry-group__value">
                                        <span v-if="field.type === 'scalar'">{{ formatTelemetryScalar(field.value) }}</span>
                                        <ul v-else-if="field.type === 'list'" class="telemetry-group__inline-list">
                                            <li v-if="!field.value || field.value.length === 0" class="telemetry-group__empty">{{ t('teamhub', 'None') }}</li>
                                            <li v-for="v in field.value" v-else :key="v">{{ v }}</li>
                                        </ul>
                                        <ul v-else-if="field.type === 'map'" class="telemetry-group__inline-list">
                                            <li v-if="!field.value || Object.keys(field.value).length === 0" class="telemetry-group__empty">{{ t('teamhub', 'None') }}</li>
                                            <li v-for="(count, key) in field.value" v-else :key="key">
                                                <span class="telemetry-group__map-key">{{ key }}</span>
                                                <span class="telemetry-group__map-count">{{ count }}</span>
                                            </li>
                                        </ul>
                                    </dd>
                                </template>
                            </dl>
                        </div>
                    </template>
                </template>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Maintenance ──────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'maintenance'"
            id="tab-panel-maintenance"
            role="tabpanel"
            class="teamhub-admin-panel">

            <!-- Title + description outside NcSettingsSection so the grid below is full-width -->
            <div class="maint-header">
                <h2 class="maint-header__title">{{ t('teamhub', 'All teams') }}</h2>
                <p class="maint-header__desc">
                    {{ t('teamhub', 'All user-created teams on this Nextcloud instance. You can assign a new owner or delete any team.') }}
                </p>
            </div>

            <!-- ── Toolbar ─────────────────────────────────────────────── -->
            <div class="maint-toolbar">
                <NcTextField
                    v-model="teamsSearch"
                    :label="t('teamhub', 'Search teams')"
                    :placeholder="t('teamhub', 'Search by name…')"
                    class="maint-search"
                    @input="onTeamsSearchInput" />

                <NcCheckboxRadioSwitch
                    :model-value="teamsOrphansOnly"
                    type="switch"
                    class="maint-orphan-toggle"
                    @update:model-value="onOrphansToggle">
                    {{ t('teamhub', 'Show only teams without an owner') }}
                </NcCheckboxRadioSwitch>

                <div class="maint-perpage">
                    <label for="maint-perpage-select" class="maint-perpage-label">
                        {{ t('teamhub', 'Per page:') }}
                    </label>
                    <select
                        id="maint-perpage-select"
                        v-model="teamsPerPage"
                        class="admin-select"
                        @change="reloadTeams">
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </div>
            </div>

            <!-- ── Waiting extension requests (v4.6.16) ─────────────────
                 The decisions themselves are made in the row popout in the
                 table below — this only says which teams are waiting, because
                 the table is paginated and a request three pages in would
                 otherwise be invisible. Each name searches the table down to
                 that team, which is where the request is decided. -->
            <div v-if="expiryRequests.length" class="maint-req-banner" role="status">
                <CalendarClockIcon :size="18" aria-hidden="true" />
                <span class="maint-req-banner__text">
                    {{ n('teamhub',
                         '{n} team is waiting for a decision on its expiration date:',
                         '{n} teams are waiting for a decision on their expiration date:',
                         expiryRequests.length,
                         { n: expiryRequests.length }) }}
                </span>
                <NcButton
                    v-for="req in expiryRequests"
                    :key="req.id"
                    variant="tertiary"
                    :aria-label="t('teamhub', 'Find {name} in the table below', { name: req.teamName })"
                    @click="jumpToTeamRequest(req)">
                    {{ req.teamName }}
                </NcButton>
            </div>
            <div v-else-if="expiryReqError" class="admin-error">{{ expiryReqError }}</div>

            <!-- ── Loading / error / empty states ─────────────────────── -->
            <div v-if="teamsLoading" class="admin-loading">
                <NcLoadingIcon :size="24" />
                <span>{{ t('teamhub', 'Loading teams…') }}</span>
            </div>
            <div v-else-if="teamsError" class="admin-error">
                {{ teamsError }}
            </div>
            <div v-else-if="teamsTotal === 0" class="admin-empty">
                {{ teamsOrphansOnly
                    ? t('teamhub', 'No teams without an owner found.')
                    : t('teamhub', 'No teams found.') }}
            </div>

            <!-- ── Grid ────────────────────────────────────────────────── -->
            <template v-else>
                <div class="maint-grid" role="table" :aria-label="t('teamhub', 'Teams')">

                    <!-- header row -->
                    <div class="maint-grid__head" role="row">
                        <div class="maint-grid__cell maint-grid__cell--name" role="columnheader">{{ t('teamhub', 'Team name') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--desc" role="columnheader">{{ t('teamhub', 'Description') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--members" role="columnheader">{{ t('teamhub', 'Members') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--owner" role="columnheader">{{ t('teamhub', 'Owner') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--created" role="columnheader">{{ t('teamhub', 'Created') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--expires" role="columnheader">{{ t('teamhub', 'Expires') }}</div>
                        <div class="maint-grid__cell maint-grid__cell--actions" role="columnheader">{{ t('teamhub', 'Actions') }}</div>
                    </div>

                    <!-- data rows -->
                    <div
                        v-for="team in teamsPage"
                        :key="team.id"
                        class="maint-grid__row"
                        role="row">

                        <!-- Name -->
                        <div class="maint-grid__cell maint-grid__cell--name" role="cell">
                            <span class="maint-team-name">{{ team.name }}</span>

                            <!-- v4.8.15 — the team's template and profile, under
                                 the name. The slot the v4.8.0 tag chips used and
                                 4.8.1 emptied: the grid is a fixed seven-column
                                 template and an eighth column would re-tune every
                                 width for a field many rows leave blank.

                                 The PROFILE chip is coloured by conformance; the
                                 TEMPLATE chip is never coloured, because a
                                 template leaves nothing on the team to compare
                                 against and a green template chip would claim a
                                 check that does not exist (DESIGN §2.104).

                                 Colour is never the only signal — WCAG 1.4.1.
                                 Each profile chip carries a glyph, and the whole
                                 group an aria-label naming the state in words. -->
                            <ul v-if="team.classification" class="maint-team-tags">
                                <li v-if="team.classification.templateKey">
                                    <span
                                        class="maint-class-chip"
                                        :title="t('teamhub', 'Created from the {template} template', { template: templateChipLabel(team) })">
                                        {{ templateChipLabel(team) }}
                                    </span>
                                </li>
                                <li v-if="team.classification.profileKey">
                                    <!-- A green chip has nothing to open, so only
                                         the red one is a control. Raw <button>
                                         rather than NcButton for the reason the
                                         chip-remove carve-out gives in SKILLS.md
                                         § "NcButton is the default": NcButton's
                                         44 px touch-target minimum would make
                                         this a button with a chip in it rather
                                         than a chip. It carries its own
                                         :focus-visible ring below. -->
                                    <button
                                        v-if="team.classification.compliant === false"
                                        type="button"
                                        class="maint-class-chip maint-class-chip--err maint-class-chip--button"
                                        :aria-label="profileChipAria(team)"
                                        :title="t('teamhub', 'Show the settings that no longer match')"
                                        @click="openDriftDialog(team)">
                                        <span class="maint-class-chip__glyph" aria-hidden="true">⚠</span>
                                        {{ profileChipLabel(team) }}
                                    </button>
                                    <span
                                        v-else
                                        class="maint-class-chip maint-class-chip--ok"
                                        :aria-label="profileChipAria(team)"
                                        :title="profileChipAria(team)">
                                        <span class="maint-class-chip__glyph" aria-hidden="true">✓</span>
                                        {{ profileChipLabel(team) }}
                                    </span>
                                </li>
                            </ul>

                            <!-- v4.9.4 — the OpenProject project this team is
                                 linked to, under the chips. The Unlink action
                                 for it sits in the Actions column; a team
                                 without a link shows nothing here and gets no
                                 button (§ Permissions applied to an affordance).
                                 The name links to OpenProject in a new tab —
                                 every OpenProject hand-off does (DESIGN §2.120).
                                 A stale link (made against another host) is
                                 said in words, not only in tone. -->
                            <p v-if="team.openproject" class="maint-op-link">
                                <LinkVariantIcon :size="14" aria-hidden="true" />
                                <span class="maint-op-link__label">{{ t('teamhub', 'OpenProject:') }}</span>
                                <a
                                    v-if="team.openproject.url"
                                    :href="team.openproject.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="maint-op-link__name"
                                    :title="t('teamhub', 'Open {name} in OpenProject', { name: team.openproject.projectName || team.openproject.projectIdentifier })">
                                    {{ team.openproject.projectName || team.openproject.projectIdentifier || team.openproject.projectId }}
                                </a>
                                <span v-else class="maint-op-link__name">
                                    {{ team.openproject.projectName || team.openproject.projectIdentifier || team.openproject.projectId }}
                                </span>
                                <span
                                    v-if="team.openproject.stale"
                                    class="maint-op-link__stale"
                                    :title="t('teamhub', 'Linked against {host}, which is no longer the configured OpenProject instance.', { host: team.openproject.host })">
                                    {{ t('teamhub', 'Other instance') }}
                                </span>
                            </p>
                        </div>

                        <!-- Description -->
                        <div class="maint-grid__cell maint-grid__cell--desc" role="cell">
                            <span class="maint-team-desc">{{ team.description || '—' }}</span>
                        </div>

                        <!-- Members -->
                        <div class="maint-grid__cell maint-grid__cell--members" role="cell">
                            {{ team.member_count }}
                        </div>

                        <!-- Owner -->
                        <div class="maint-grid__cell maint-grid__cell--owner" role="cell">
                            <span v-if="team.owner" class="maint-owner-name">
                                {{ team.owner_display_name || team.owner }}
                                <span class="maint-owner-uid">({{ team.owner }})</span>
                            </span>
                            <span v-else class="maint-no-owner">{{ t('teamhub', 'No owner') }}</span>
                        </div>

                        <!-- Created -->
                        <div class="maint-grid__cell maint-grid__cell--created" role="cell">
                            <span :title="team.creation">{{ formatDate(team.creation) }}</span>
                        </div>

                        <!-- Expires (v4.6.13) — Collaboration and Project teams
                             only. A Department shows an em dash and no control,
                             which is the § Permissions rule applied to a field
                             rather than an action: what cannot be set is not
                             offered. -->
                        <div class="maint-grid__cell maint-grid__cell--expires" role="cell">
                            <template v-if="!team.expiry_eligible">
                                <span
                                    class="maint-expiry maint-expiry--na"
                                    :title="t('teamhub', 'Only Collaboration and Project teams can have an expiration date.')">—</span>
                            </template>

                            <!-- Inline date editor -->
                            <div v-else-if="expiryEditTeamId === team.id" class="maint-expiry-form">
                                <input
                                    :id="'maint-expiry-' + team.id"
                                    v-model="expiryEditValue"
                                    type="date"
                                    class="maint-date-input"
                                    :min="tomorrowIso"
                                    :aria-label="t('teamhub', 'Expiration date for {name}', { name: team.name })"
                                    @keydown.enter.prevent="saveTeamExpiry(team)"
                                    @keydown.esc.prevent="cancelExpiryEdit" />
                                <!-- v4.6.17 — icon buttons in a row, not three
                                     labelled ones stacked. The Expires column
                                     is narrow enough that full-width text
                                     buttons wrapped onto three lines and pushed
                                     the row taller than the date field they
                                     belong to. Each carries both :title and
                                     :aria-label, per the icon-only rule the
                                     Actions column already follows. -->
                                <div class="maint-expiry-form__actions">
                                    <NcButton
                                        variant="primary"
                                        :disabled="expirySaving"
                                        :aria-label="t('teamhub', 'Save the expiration date for {name}', { name: team.name })"
                                        :title="t('teamhub', 'Save')"
                                        @click="saveTeamExpiry(team)">
                                        <template #icon>
                                            <NcLoadingIcon v-if="expirySaving" :size="18" />
                                            <ContentSave v-else :size="18" />
                                        </template>
                                    </NcButton>
                                    <NcButton
                                        v-if="team.expiry"
                                        variant="tertiary"
                                        :disabled="expirySaving"
                                        :aria-label="t('teamhub', 'Remove the expiration date for {name}', { name: team.name })"
                                        :title="t('teamhub', 'Remove the expiration date entirely')"
                                        @click="clearTeamExpiry(team)">
                                        <template #icon><CalendarRemoveIcon :size="18" /></template>
                                    </NcButton>
                                    <NcButton
                                        variant="tertiary"
                                        :disabled="expirySaving"
                                        :aria-label="t('teamhub', 'Cancel editing the expiration date')"
                                        :title="t('teamhub', 'Cancel')"
                                        @click="cancelExpiryEdit">
                                        <template #icon><CloseIcon :size="18" /></template>
                                    </NcButton>
                                </div>
                            </div>

                            <!-- Read state -->
                            <button
                                v-else
                                type="button"
                                class="maint-expiry-btn"
                                :class="expiryToneClass(team)"
                                :aria-label="team.expiry
                                    ? t('teamhub', 'Change the expiration date for {name}', { name: team.name })
                                    : t('teamhub', 'Set an expiration date for {name}', { name: team.name })"
                                :title="expiryTitle(team)"
                                @click="startExpiryEdit(team)">
                                <!-- Never colour alone (WCAG 1.4.1): an expiring
                                     or expired date carries an icon and words
                                     as well as a tone. -->
                                <AlertCircleOutlineIcon
                                    v-if="team.expiry && (team.expiry.expired || team.expiry.warning)"
                                    :size="14"
                                    aria-hidden="true" />
                                <span>{{ expiryLabel(team) }}</span>
                            </button>

                            <!-- Pending request — the decision is made from this
                                 row rather than a second table further down the
                                 page: the badge was a pointer to a queue you had
                                 to go and find, and the team you are deciding
                                 about is this row.
                                 v4.6.17 — it opens a dialog rather than the
                                 popout it was until now. The popout was absolutely
                                 positioned inside a grid cell, so the row's own
                                 action buttons drew on top of it and the table's
                                 horizontal scroll clipped it. A decision with four
                                 fields is not a popout. -->
                            <div v-if="team.expiry_request_pending" class="maint-expiry-req">
                                <button
                                    type="button"
                                    class="maint-expiry-pending maint-expiry-pending--btn"
                                    :aria-label="t('teamhub', 'Decide the extension request for {name}', { name: team.name })"
                                    @click="openExpiryRequest(team)">
                                    <span>{{ t('teamhub', 'Extension requested') }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="maint-grid__cell maint-grid__cell--actions" role="cell">

                            <!-- Inline assign-owner form -->
                            <div v-if="assignTeamId === team.id" class="maint-assign-form">
                                <NcTextField
                                    v-model="ownerQuery"
                                    :label="t('teamhub', 'Search user')"
                                    :placeholder="t('teamhub', 'Type a username…')"
                                    @input="onOwnerSearch" />
                                <ul v-if="ownerResults.length" class="admin-owner-results">
                                    <li
                                        v-for="u in ownerResults"
                                        :key="u.uid"
                                        class="admin-owner-result"
                                        @mousedown.prevent="confirmAssignOwner(team, u)">
                                        {{ u.displayName }}
                                        <span class="admin-owner-result__uid">({{ u.uid }})</span>
                                    </li>
                                </ul>
                                <p v-else-if="ownerSearching" class="admin-section-hint">
                                    <NcLoadingIcon :size="14" /> {{ t('teamhub', 'Searching…') }}
                                </p>
                                <!-- v4.8.35 — until now this form rendered
                                     nothing at all unless it had results: a
                                     failed request and "no such account" were
                                     the same blank space, which is why "I type
                                     a name and nothing happens" could not be
                                     told apart from "that name does not
                                     match". Both states are named now. -->
                                <p v-else-if="ownerError" class="admin-error">
                                    {{ ownerError }}
                                </p>
                                <p v-else-if="ownerSearched" class="admin-section-hint">
                                    {{ t('teamhub', 'No match. Only existing accounts can be used.') }}
                                </p>
                                <NcButton variant="tertiary" @click="cancelAssign">
                                    {{ t('teamhub', 'Cancel') }}
                                </NcButton>
                            </div>

                            <!-- Icon-only action buttons -->
                            <div v-else class="maint-row-actions">
                                <NcButton
                                    variant="secondary"
                                    :disabled="assigningOwner"
                                    :aria-label="t('teamhub', 'Set owner for {name}', { name: team.name })"
                                    :title="t('teamhub', 'Set owner')"
                                    @click="startAssignOwner(team)">
                                    <template #icon><AccountEditIcon :size="18" /></template>
                                </NcButton>
                                <!-- v4.6.17 — write to the team's owner, in the
                                     admin's own mail client. Present only when
                                     the server resolved an address: a team with
                                     no owner, or an owner with none on their
                                     account, gets no button rather than one
                                     that opens an empty compose window. -->
                                <NcButton
                                    v-if="team.owner_mailto"
                                    variant="secondary"
                                    :href="team.owner_mailto"
                                    target="_blank"
                                    :aria-label="t('teamhub', 'Email the owner of {name}', { name: team.name })"
                                    :title="t('teamhub', 'Email owner ({owner})', { owner: team.owner_display_name })">
                                    <template #icon><EmailOutlineIcon :size="18" /></template>
                                </NcButton>
                                <!-- v4.8.16 — apply a policy profile to a team
                                     that already exists (Track F2b).

                                     **This is the only route to it, and that is
                                     deliberate.** Applying a profile is an
                                     instance governance control, so it lives in
                                     admin settings and nowhere near Manage Team:
                                     a team admin who could reclassify their own
                                     team would be able to lift every restriction
                                     placed on it, which is the bypass DESIGN
                                     §2.103 records as the reason tags were
                                     removed. The button is only half of that —
                                     PolicyApplyService::requireNcAdmin() is the
                                     boundary, per SKILLS.md's rule that the
                                     frontend is never a security boundary. -->
                                <NcButton
                                    variant="secondary"
                                    :aria-label="t('teamhub', 'Apply a policy profile to {name}', { name: team.name })"
                                    :title="t('teamhub', 'Apply a policy profile')"
                                    @click="openAssignPolicy(team)">
                                    <template #icon><ShieldLockOutlineIcon :size="18" /></template>
                                </NcButton>
                                <NcButton
                                    variant="secondary"
                                    :disabled="resettingConfigTeamId === team.id"
                                    :aria-label="t('teamhub', 'Reset config bitmask for {name}', { name: team.name })"
                                    :title="t('teamhub', 'Reset config to clean defaults — clears any corrupted bits set on this team')"
                                    @click="confirmResetTeamConfig(team)">
                                    <template #icon>
                                        <NcLoadingIcon v-if="resettingConfigTeamId === team.id" :size="18" />
                                        <RestoreIcon v-else :size="18" />
                                    </template>
                                </NcButton>
                                <!-- v4.9.4 — remove the team's OpenProject
                                     link. Only on a linked row, and this is the
                                     only place it exists: the wizard links once
                                     at creation and Manage team is read-only,
                                     so an instance administrator is the one who
                                     can free a project for another team. -->
                                <NcButton
                                    v-if="team.openproject"
                                    variant="secondary"
                                    :disabled="unlinkingOpenProjectTeamId === team.id"
                                    :aria-label="t('teamhub', 'Unlink the OpenProject project from {name}', { name: team.name })"
                                    :title="t('teamhub', 'Unlink OpenProject project')"
                                    @click="confirmUnlinkOpenProject(team)">
                                    <template #icon>
                                        <NcLoadingIcon v-if="unlinkingOpenProjectTeamId === team.id" :size="18" />
                                        <LinkOffIcon v-else :size="18" />
                                    </template>
                                </NcButton>
                                <NcButton
                                    variant="error"
                                    :disabled="deletingTeam === team.id"
                                    :aria-label="t('teamhub', 'Delete {name}', { name: team.name })"
                                    :title="t('teamhub', 'Delete team')"
                                    @click="confirmDeleteTeamRow(team)">
                                    <template #icon>
                                        <NcLoadingIcon v-if="deletingTeam === team.id" :size="18" />
                                        <DeleteIcon v-else :size="18" />
                                    </template>
                                </NcButton>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Pagination ──────────────────────────────────── -->
                <div class="maint-pagination" role="navigation" :aria-label="t('teamhub', 'Pagination')">
                    <NcButton
                        variant="tertiary"
                        :disabled="teamsPage_current <= 1"
                        @click="goToPage(1)">
                        «
                    </NcButton>
                    <NcButton
                        variant="tertiary"
                        :disabled="teamsPage_current <= 1"
                        @click="goToPage(teamsPage_current - 1)">
                        ‹
                    </NcButton>

                    <span class="maint-page-info">
                        {{ t('teamhub', 'Page {page} of {total}', { page: teamsPage_current, total: teamsTotalPages }) }}
                        <!-- TRANSLATORS: total team count shown in admin pagination, e.g. "1 team" or "42 teams" -->
                        · {{ n('teamhub', '{n} team', '{n} teams', teamsTotal, { n: teamsTotal }) }}
                    </span>

                    <NcButton
                        variant="tertiary"
                        :disabled="teamsPage_current >= teamsTotalPages"
                        @click="goToPage(teamsPage_current + 1)">
                        ›
                    </NcButton>
                    <NcButton
                        variant="tertiary"
                        :disabled="teamsPage_current >= teamsTotalPages"
                        @click="goToPage(teamsTotalPages)">
                        »
                    </NcButton>

                    <NcButton
                        variant="tertiary"
                        :disabled="teamsLoading"
                        @click="reloadTeams">
                        {{ t('teamhub', 'Refresh') }}
                    </NcButton>
                </div>
            </template>

            <!-- ── Membership integrity ─────────────────────────────────── -->
            <div class="maint-divider"></div>
            <div class="maint-header">
                <h2 class="maint-header__title">{{ t('teamhub', 'Membership cache integrity') }}</h2>
                <p class="maint-header__desc">
                    {{ t('teamhub', 'Checks that each team\'s membership cache (circles_membership) is populated. A stale/empty cache means users added via groups or other teams won\'t appear in share pickers for Files, Calendar, Deck, etc. Run Repair to rebuild the cache.') }}
                </p>
            </div>

            <div class="maint-integrity-actions">
                <NcButton
                    variant="primary"
                    :disabled="membershipCheckLoading"
                    @click="runMembershipCheck">
                    <template #icon>
                        <NcLoadingIcon v-if="membershipCheckLoading" :size="18" />
                        <WrenchIcon v-else :size="18" />
                    </template>
                    {{ membershipCheckLoading
                        ? t('teamhub', 'Scanning…')
                        : t('teamhub', 'Run integrity check') }}
                </NcButton>
            </div>

            <div v-if="membershipCheckError" class="admin-error">
                {{ membershipCheckError }}
            </div>

            <div v-if="membershipCheck" class="maint-integrity-result">
                <div class="maint-integrity-summary">
                    <span class="maint-integrity-summary__item">
                        {{ t('teamhub', 'Total teams scanned') }}: <strong>{{ membershipCheck.total_teams }}</strong>
                    </span>
                    <span class="maint-integrity-summary__item maint-integrity-summary__item--ok">
                        {{ t('teamhub', 'Healthy') }}: <strong>{{ membershipCheck.healthy }}</strong>
                    </span>
                    <span
                        class="maint-integrity-summary__item"
                        :class="{ 'maint-integrity-summary__item--bad': membershipCheck.mismatched > 0 }">
                        {{ t('teamhub', 'Issues') }}: <strong>{{ membershipCheck.mismatched }}</strong>
                    </span>
                </div>

                <div v-if="membershipCheck.mismatched === 0" class="admin-empty">
                    {{ t('teamhub', 'All team membership caches are populated and consistent.') }}
                </div>

                <div v-else class="maint-integrity-list">
                    <div
                        v-for="issue in membershipCheck.issues"
                        :key="issue.id + (issue.nested_team_id || '')"
                        class="maint-integrity-row"
                        :class="{ 'maint-integrity-row--nested': issue.issue_type === 'nested_team' }">

                        <!-- Nested team issue -->
                        <template v-if="issue.issue_type === 'nested_team'">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                    {{ t('teamhub', 'Team "{child}" is a member of this team, but has "Prevent from being a member of another team" enabled — remove the membership or update {child}\'s settings.', { child: issue.nested_team_name }) }}
                                </span>
                            </div>
                            <NcButton
                                variant="error"
                                :disabled="!!membershipRepairing[issue.id + '_nested']"
                                @click="removeNestedTeam(issue)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id + '_nested']" :size="18" />
                                    <AccountRemoveIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id + '_nested']
                                    ? t('teamhub', 'Removing…')
                                    : t('teamhub', 'Remove nested team') }}
                            </NcButton>
                        </template>

                        <!-- Wrong display_name (owner name instead of team name) -->
                        <template v-else-if="issue.issue_type === 'wrong_display_name'">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                    {{ t('teamhub', 'Display name is incorrectly set to "{wrong}" instead of "{correct}". This can cause Circles to hide the team.', { wrong: issue.name, correct: issue.correct_name }) }}
                                </span>
                            </div>
                            <NcButton
                                variant="secondary"
                                :disabled="!!membershipRepairing[issue.id + '_dn']"
                                @click="fixDisplayName(issue)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id + '_dn']" :size="18" />
                                    <WrenchIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id + '_dn']
                                    ? t('teamhub', 'Fixing…')
                                    : t('teamhub', 'Fix display name') }}
                            </NcButton>
                        </template>

                        <!-- No owner -->
                        <template v-else-if="issue.issue_type === 'no_owner'">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                    <span v-if="issue.has_members">
                                        {{ t('teamhub', 'This team has no owner. The highest-level member will be promoted to owner.') }}
                                    </span>
                                    <span v-else>
                                        {{ t('teamhub', 'This team has no owner and no members. You will be assigned as owner.') }}
                                    </span>
                                </span>
                            </div>
                            <NcButton
                                variant="secondary"
                                :disabled="!!membershipRepairing[issue.id + '_noowner']"
                                @click="assignOwner(issue)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id + '_noowner']" :size="18" />
                                    <WrenchIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id + '_noowner']
                                    ? t('teamhub', 'Assigning…')
                                    : t('teamhub', 'Assign owner') }}
                            </NcButton>
                        </template>

                        <!-- Duplicate member rows (same user_id twice in same circle) -->
                        <template v-else-if="issue.issue_type === 'duplicate_member'">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                    {{ t('teamhub', '{uid} appears {n} times in this team\'s membership. The highest-level row will be kept.', { uid: issue.duplicate_uid, n: issue.row_count }) }}
                                </span>
                            </div>
                            <NcButton
                                variant="secondary"
                                :disabled="!!membershipRepairing[issue.id + '_' + issue.duplicate_uid]"
                                @click="repairDuplicateMember(issue)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id + '_' + issue.duplicate_uid]" :size="18" />
                                    <WrenchIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id + '_' + issue.duplicate_uid]
                                    ? t('teamhub', 'Repairing…')
                                    : t('teamhub', 'Remove duplicate rows') }}
                            </NcButton>
                        </template>

                        <!-- CFG_SINGLE wrongly set — team hidden from Circles API -->
                        <template v-else-if="issue.issue_type === 'cfg_single_set'">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                    {{ t('teamhub', 'This team has been incorrectly marked as a personal circle (bit 1024 set) and is hidden from Nextcloud Teams. Repair to restore visibility.') }}
                                </span>
                            </div>
                            <NcButton
                                variant="warning"
                                :disabled="!!membershipRepairing[issue.id + '_cfgsingle']"
                                @click="clearCfgSingle(issue)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id + '_cfgsingle']" :size="18" />
                                    <WrenchIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id + '_cfgsingle']
                                    ? t('teamhub', 'Repairing…')
                                    : t('teamhub', 'Repair visibility') }}
                            </NcButton>
                        </template>

                        <!-- Stale cache issue -->
                        <template v-else>
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ issue.name }}</span>
                                <span class="maint-integrity-row__detail">
                                    {{ t('teamhub', 'Direct members: {m} — Effective cache: {c} (stale)', {
                                        m: issue.direct_count,
                                        c: issue.effective_count,
                                    }) }}
                                </span>
                            </div>
                            <NcButton
                                variant="secondary"
                                :disabled="!!membershipRepairing[issue.id]"
                                @click="repairMembership(issue.id)">
                                <template #icon>
                                    <NcLoadingIcon v-if="membershipRepairing[issue.id]" :size="18" />
                                    <WrenchIcon v-else :size="18" />
                                </template>
                                {{ membershipRepairing[issue.id]
                                    ? t('teamhub', 'Repairing…')
                                    : t('teamhub', 'Repair') }}
                            </NcButton>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ── Config bitmask integrity ─────────────────────────────── -->
            <div class="maint-divider" style="margin-top: 40px;"></div>
            <div class="maint-header">
                <h2 class="maint-header__title">{{ t('teamhub', 'Team config bitmask integrity') }}</h2>
                <p class="maint-header__desc">
                    {{ t('teamhub', 'Scans every user-created team for system bits that should never appear on a user team (CFG_SINGLE, CFG_PERSONAL, CFG_SYSTEM, CFG_NO_OWNER, CFG_HIDDEN, CFG_BACKEND). Nextcloud Circles refuses any config update on a team carrying one of these, so apps can no longer change it. Use Repair to reset a team\'s config to clean defaults.') }}
                </p>
            </div>

            <div class="maint-integrity-actions">
                <NcButton
                    variant="primary"
                    :disabled="configCheckLoading"
                    @click="runConfigCheck">
                    <template #icon>
                        <NcLoadingIcon v-if="configCheckLoading" :size="18" />
                        <ShieldCheckIcon v-else :size="18" />
                    </template>
                    {{ configCheckLoading
                        ? t('teamhub', 'Scanning…')
                        : t('teamhub', 'Run config check') }}
                </NcButton>
            </div>

            <div v-if="configCheckError" class="admin-error">
                {{ configCheckError }}
            </div>

            <div v-if="configCheck" class="maint-integrity-result">
                <div class="maint-integrity-summary">
                    <span
                        class="maint-integrity-summary__item"
                        :class="{
                            'maint-integrity-summary__item--ok':  configCheck.issues.length === 0,
                            'maint-integrity-summary__item--bad': configCheck.issues.length > 0,
                        }">
                        {{ t('teamhub', 'Teams with corrupted config') }}: <strong>{{ configCheck.issues.length }}</strong>
                    </span>
                </div>

                <div v-if="configCheck.issues.length === 0" class="admin-empty">
                    {{ t('teamhub', 'All team config bitmasks are clean.') }}
                </div>

                <div v-else class="maint-integrity-list">
                    <div
                        v-for="issue in configCheck.issues"
                        :key="issue.id"
                        class="maint-integrity-row">
                        <div class="maint-integrity-row__info">
                            <span class="maint-integrity-row__name">{{ issue.name || issue.id }}</span>
                            <span class="maint-integrity-row__detail maint-integrity-row__detail--warn">
                                {{ t(
                                    'teamhub',
                                    'Current config: {config}. Bad bits: {badBits}',
                                    { config: issue.config, badBits: issue.badBits },
                                ) }}
                            </span>
                        </div>
                        <NcButton
                            variant="primary"
                            :disabled="resettingConfigTeamId === issue.id"
                            @click="repairConfigIssue(issue)">
                            <template #icon>
                                <NcLoadingIcon v-if="resettingConfigTeamId === issue.id" :size="18" />
                                <RestoreIcon v-else :size="18" />
                            </template>
                            {{ resettingConfigTeamId === issue.id
                                ? t('teamhub', 'Repairing…')
                                : t('teamhub', 'Repair') }}
                        </NcButton>
                    </div>
                </div>

                <!-- App-claimed teams (v4.5.37) — informational, never an issue.
                     CFG_APP is set by other Nextcloud apps (Collectives does it
                     via flagCircleAsAppManaged) to mark a circle as theirs. It
                     used to be counted as corruption, which reported healthy
                     teams as broken and offered a Repair that would have
                     stripped the other app's claim. No button here on purpose:
                     the flag is not TeamHub's to remove. -->
                <div v-if="configCheck.appClaimed && configCheck.appClaimed.length > 0" class="maint-integrity-claimed">
                    <h3 class="maint-integrity-claimed__title">
                        <!-- {n}, not %n: AdminSettings defines t/n as inline
                             methods (SKILLS.md § Exposing t and n) and the
                             window.n-absent fallback only substitutes {…}. -->
                        {{ n('teamhub',
                             '{n} team is claimed by another app',
                             '{n} teams are claimed by another app',
                             configCheck.appClaimed.length,
                             { n: configCheck.appClaimed.length }) }}
                    </h3>
                    <p class="maint-integrity-claimed__desc">
                        {{ t('teamhub', 'These teams carry the CFG_APP flag, which a Nextcloud app sets to mark the team as managed by it — enabling a Collective does this. It is not corruption and needs no repair. The flag stays behind if the app that set it is later removed.') }}
                    </p>
                    <div class="maint-integrity-list">
                        <div
                            v-for="team in configCheck.appClaimed"
                            :key="team.id"
                            class="maint-integrity-row">
                            <div class="maint-integrity-row__info">
                                <span class="maint-integrity-row__name">{{ team.name || team.id }}</span>
                                <span class="maint-integrity-row__detail">
                                    {{ t(
                                        'teamhub',
                                        'Current config: {config}. App flag: {appBits}',
                                        { config: team.config, appBits: team.appBits },
                                    ) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Ghost member cleanup ──────────────────────────────────── -->
            <div class="maint-header" style="margin-top: 40px;">
                <h2 class="maint-header__title">{{ t('teamhub', 'Deleted users in teams') }}</h2>
                <p class="maint-header__desc">
                    {{ t('teamhub', 'These users have been deleted from Nextcloud but are still listed as members in one or more teams. Removing them cleans up the team membership without affecting any other data.') }}
                </p>
            </div>

            <div class="maint-toolbar">
                <NcTextField
                    v-model="ghostSearch"
                    :label="t('teamhub', 'Search by user ID')"
                    :placeholder="t('teamhub', 'Filter by user ID…')"
                    class="maint-search"
                    @input="onGhostSearchInput" />
                <NcButton
                    variant="secondary"
                    :disabled="ghostLoading"
                    :aria-label="t('teamhub', 'Scan for deleted users')"
                    @click="loadGhostMembers">
                    <template #icon>
                        <NcLoadingIcon v-if="ghostLoading" :size="18" />
                        <MagnifyIcon v-else :size="18" />
                    </template>
                    {{ t('teamhub', 'Scan') }}
                </NcButton>
            </div>

            <div v-if="ghostLoading" class="admin-loading">
                <NcLoadingIcon :size="24" />
                <span>{{ t('teamhub', 'Scanning team memberships…') }}</span>
            </div>
            <div v-else-if="ghostError" class="admin-error">{{ ghostError }}</div>
            <div v-else-if="!ghostScanned" class="admin-empty">
                {{ t('teamhub', 'Click "Scan" to search for deleted users still listed in teams.') }}
            </div>
            <div v-else-if="ghostMembers.length === 0" class="admin-empty">
                {{ t('teamhub', 'No deleted users found in any team. All memberships look clean.') }}
            </div>

            <template v-else>
                <p class="ghost-result-summary">
                    {{ n('teamhub', '{n} deleted user found in team memberships.', '{n} deleted users found in team memberships.', ghostMembers.length, { n: ghostMembers.length }) }}
                </p>
                <div class="ghost-grid" role="table" :aria-label="t('teamhub', 'Deleted users')">
                    <div class="ghost-grid__head" role="row">
                        <div class="ghost-grid__cell ghost-grid__cell--uid" role="columnheader">{{ t('teamhub', 'User ID') }}</div>
                        <div class="ghost-grid__cell ghost-grid__cell--teams" role="columnheader">{{ t('teamhub', 'Teams') }}</div>
                        <div class="ghost-grid__cell ghost-grid__cell--actions" role="columnheader">{{ t('teamhub', 'Actions') }}</div>
                    </div>
                    <div
                        v-for="ghost in ghostMembers"
                        :key="ghost.userId"
                        class="ghost-grid__row"
                        role="row">
                        <div class="ghost-grid__cell ghost-grid__cell--uid" role="cell">
                            <span class="ghost-uid">{{ ghost.userId }}</span>
                            <span class="ghost-deleted-badge">{{ t('teamhub', 'Deleted') }}</span>
                        </div>
                        <div class="ghost-grid__cell ghost-grid__cell--teams" role="cell">
                            <ul class="ghost-team-list">
                                <li v-for="team in ghost.teams" :key="team.teamId" class="ghost-team-item">
                                    <span class="ghost-team-name">{{ team.teamName }}</span>
                                    <NcButton
                                        variant="tertiary"
                                        :aria-label="removeFromTeamLabel(ghost.userId, team.teamName)"
                                        :disabled="ghostRemoving[ghost.userId + ':' + team.teamId]"
                                        @click="removeGhostFromTeam(ghost, team)">
                                        <template #icon>
                                            <NcLoadingIcon v-if="ghostRemoving[ghost.userId + ':' + team.teamId]" :size="16" />
                                            <AccountRemoveIcon v-else :size="16" />
                                        </template>
                                        {{ t('teamhub', 'Remove from this team') }}
                                    </NcButton>
                                </li>
                            </ul>
                        </div>
                        <div class="ghost-grid__cell ghost-grid__cell--actions" role="cell">
                            <NcButton
                                variant="error"
                                :aria-label="removeFromAllLabel(ghost.userId)"
                                :disabled="ghostRemoving[ghost.userId + ':all']"
                                @click="removeGhostFromAll(ghost)">
                                <template #icon>
                                    <NcLoadingIcon v-if="ghostRemoving[ghost.userId + ':all']" :size="16" />
                                    <DeleteIcon v-else :size="16" />
                                </template>
                                {{ t('teamhub', 'Remove from all teams') }}
                            </NcButton>
                        </div>
                    </div>
                </div>
            </template>

            <!-- v4.9.6 — Phase 2: every workspace provisioning operation, with
                 retry, continue and rollback. `v-if` so its GET fires when the
                 tab is opened, like the other child panels. -->
            <ProvisioningAdminPanel v-if="activeTab === 'maintenance'" />
        </div>

        <!-- ─────────────────────────────────────────────────────────────────
             Presence module tab (Session B1 / v3.42.0)
             Admin-only foundation: catalogues + per-team toggle schema.
             User-facing UI lands in B2/B3.
             ───────────────────────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'presence'"
            id="tab-panel-presence"
            role="tabpanel"
            class="teamhub-admin-panel">

            <PresenceTypesManager v-if="activeTab === 'presence'" />
            <PresenceLocationsManager v-if="activeTab === 'presence'" />
            <PresenceHolidaysManager v-if="activeTab === 'presence'" />
        </div>

        <!-- ─────────────────────────────────────────────────────────────────
             My Work tab (v4.5.21) — sources, time windows, performance and
             category mapping for the personal cross-team work queue.

             The whole panel is a self-contained child component with its own
             endpoints and its own autosave, so it is excluded from the shared
             Save row below. `v-if` rather than `v-show` on the child: it
             fetches on mount, and an admin who never opens this tab should
             not pay for two requests.

             v4.9.19 — gated on the licence like the Compliance tab. My Work
             is licensed (every `MyWorkController` method answers 403 with
             `licenseGate` otherwise), and a settings page for a feature no
             member can open showed on every unlicensed instance. The banner
             rather than a hidden tab: this is the admin who buys the license,
             and telling them the feature exists is the point. The endpoints
             behind the page stay admin-only and ungated.
             ───────────────────────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'mywork'"
            id="tab-panel-mywork"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                v-if="!licenseActive"
                :name="t('teamhub', 'My Work')">
                <div class="integrity-banner integrity-banner--info">
                    <InformationOutline :size="18" aria-hidden="true" />
                    <span>
                        {{ t('teamhub', 'My Work requires an active TeamHub license. Add or renew a license in the License tab to unlock it.') }}
                    </span>
                </div>
            </NcSettingsSection>

            <MyWorkAdminSettings v-else-if="activeTab === 'mywork'" />
        </div>

        <!-- ─────────────────────────────────────────────────────────────────
             Policy tab (v4.8.2, Track F2a) — templates and classification
             profiles. `v-if` on the child so its four GETs fire when the tab
             is opened rather than on every admin-settings mount; `v-show` on
             the wrapper keeps the panel plumbing identical to every other tab.
             ───────────────────────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'policy'"
            id="tab-panel-policy"
            role="tabpanel"
            class="teamhub-admin-panel">

            <PolicyAdminPanel v-if="activeTab === 'policy'" />
        </div>

        <!-- ─────────────────────────────────────────────────────────────────
             Compliance tab (id kept as 'audit' for scoped CSS + panel plumbing)
             Whole panel — including Code integrity, Telemetry AND Audit log —
             is gated on the license. Unlicensed / soft-locked instances see
             one banner explaining why.
             ───────────────────────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'audit'"
            id="tab-panel-audit"
            role="tabpanel"
            class="teamhub-admin-panel">

            <!-- License-required state: single banner replaces every section
                 on this tab when no active/trial/grace license is installed. -->
            <NcSettingsSection
                v-if="!complianceUnlocked"
                :name="t('teamhub', 'Compliance')">
                <div class="integrity-banner integrity-banner--info">
                    <InformationOutline :size="18" />
                    <span>
                        {{ t('teamhub', 'Compliance tab requires an active TeamHub license. Add or renew a license in the License tab to unlock them.') }}
                    </span>
                </div>
            </NcSettingsSection>

            <template v-else>
            <!-- ── Compliance checks (v4.3.0) — compact pills + i-button ───── -->
            <NcSettingsSection
                :name="t('teamhub', 'Compliance checks')"
                :description="t('teamhub', 'At-a-glance compliance state for this TeamHub installation. Click the info icon on a row for details.')">

                <!-- v4.4.14 — export the compliance rows to a printable /
                     Save-as-PDF report. Client-side: the report is a
                     dedicated window populated from the same state the pills
                     read, then window.print() is invoked so the admin uses
                     the browser's own PDF export. No server-side PDF
                     generator, no extra deps. -->
                <div class="compliance-export">
                    <NcButton variant="secondary" @click="openComplianceReport">
                        <template #icon><FileDocumentOutlineIcon :size="18" /></template>
                        {{ t('teamhub', 'Save compliance report as PDF') }}
                    </NcButton>
                </div>

                <div class="compliance-rows">
                    <!-- Row: Code integrity -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Code integrity')">
                        <div v-if="integrity.loading" class="integrity-loading">
                            <NcLoadingIcon :size="18" />
                            <span>{{ t('teamhub', 'Verifying code integrity…') }}</span>
                        </div>
                        <div v-else-if="integrity.error" class="admin-save-err">
                            {{ integrity.error }}
                        </div>
                        <template v-else>
                            <span class="compliance-row__label">{{ t('teamhub', 'Code:') }}</span>
                            <span class="integrity-pill" :class="'integrity-pill--' + integrityPillLevel" role="status" aria-live="polite">
                                <span class="integrity-pill__dot" />
                                {{ integrityStatusLabel }}
                            </span>
                            <span class="compliance-row__spacer" />
                            <div class="compliance-row__actions">
                                <NcActions
                                    :aria-label="t('teamhub', 'Code integrity details')"
                                    :title="t('teamhub', 'Code integrity details')">
                                    <template #icon>
                                        <InformationOutline :size="18" />
                                    </template>
                                    <NcActionText>
                                        <template #icon><InformationOutline :size="18" /></template>
                                        {{ t('teamhub', 'Verifies shipped files against a SHA-256 manifest generated at build time.') }}
                                    </NcActionText>
                                    <NcActionText v-if="complianceControlsByCheck['Code integrity']">
                                        {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Code integrity'] }) }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.app_version">
                                        {{ t('teamhub', 'Manifest app version') }}: {{ integrity.report.app_version }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.generated_at">
                                        {{ t('teamhub', 'Manifest generated') }}: {{ formatIntegrityTimestamp(integrity.report.generated_at) }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report">
                                        {{ t('teamhub', 'Files checked') }}: {{ integrity.report.files_checked }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.checked_at">
                                        {{ t('teamhub', 'Last verified') }}: {{ formatIntegrityTimestamp(integrity.report.checked_at) }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.altered.length">
                                        {{ n('teamhub',
                                            '{n} altered file',
                                            '{n} altered files',
                                            integrity.report.altered.length,
                                            { n: integrity.report.altered.length }) }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.missing.length">
                                        {{ n('teamhub',
                                            '{n} missing file',
                                            '{n} missing files',
                                            integrity.report.missing.length,
                                            { n: integrity.report.missing.length }) }}
                                    </NcActionText>
                                    <NcActionText v-if="integrity.report && integrity.report.unexpected.length">
                                        {{ n('teamhub',
                                            '{n} unexpected file',
                                            '{n} unexpected files',
                                            integrity.report.unexpected.length,
                                            { n: integrity.report.unexpected.length }) }}
                                    </NcActionText>
                                </NcActions>
                                <div class="compliance-row__refresh-slot">
                                    <NcButton
                                        variant="tertiary-no-background"
                                        :aria-label="t('teamhub', 'Re-run integrity check')"
                                        :title="t('teamhub', 'Re-run integrity check')"
                                        :disabled="integrity.loading"
                                        @click="loadIntegrity">
                                        <template #icon>
                                            <RefreshIcon :size="18" />
                                        </template>
                                    </NcButton>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Row: Telemetry (auto-derived from license state) -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Telemetry')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Telemetry:') }}</span>
                        <span
                            class="integrity-pill"
                            :class="'integrity-pill--' + (telemetryEnabledDerived ? 'warn' : 'ok')"
                            role="status"
                            aria-live="polite">
                            <span class="integrity-pill__dot" />
                            {{ telemetryEnabledDerived ? t('teamhub', 'On') : t('teamhub', 'Off') }}
                        </span>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Telemetry details')"
                                :title="t('teamhub', 'Telemetry details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ telemetryEnabledDerived
                                        ? t('teamhub', 'Anonymous usage statistics are sent daily. This instance has no active license, so telemetry is enabled.')
                                        : t('teamhub', 'No usage data leaves this instance. An active license disables telemetry automatically.') }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Telemetry']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Telemetry'] }) }}
                                </NcActionText>
                            </NcActions>
                            <!-- Fixed-width refresh slot kept EMPTY on this row
                                 so the `i` icon lines up vertically with the
                                 Code row's `i` icon. Reserving the space costs
                                 nothing; removing it would break the alignment. -->
                            <div class="compliance-row__refresh-slot" aria-hidden="true" />
                        </div>
                    </div>

                    <!-- Row: Invite types (v4.4.14) — pulls double duty as
                         a governance surface. Warn state fires only when
                         team admins can invite people from outside the
                         server (email or federation), which is the case an
                         admin most often turns on without meaning to. -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Allowed invite types')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Invite types:') }}</span>
                        <span
                            class="integrity-pill"
                            :class="'integrity-pill--' + (invitesExternalReach ? 'warn' : 'ok')"
                            role="status"
                            aria-live="polite">
                            <span class="integrity-pill__dot" />
                            {{ invitesExternalReach ? t('teamhub', 'External reach') : t('teamhub', 'Local only') }}
                        </span>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Allowed invite types details')"
                                :title="t('teamhub', 'Allowed invite types details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ invitesExternalReach
                                        ? t('teamhub', 'Team admins can invite people from outside this server by email or federated Nextcloud account. Change this on the Team creation tab under Allowed invite types.')
                                        : t('teamhub', 'Invitations are restricted to local Nextcloud accounts and groups. Team admins cannot reach outside this server.') }}
                                </NcActionText>
                                <NcActionText v-if="inviteEmail">
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Email invitations: enabled') }}
                                </NcActionText>
                                <NcActionText v-if="inviteFederated">
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Federated Nextcloud invitations: enabled') }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Allowed invite types']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Allowed invite types'] }) }}
                                </NcActionText>
                            </NcActions>
                            <div class="compliance-row__refresh-slot" aria-hidden="true" />
                        </div>
                    </div>

                    <!-- Row: Ghost memberships (v4.2.10) — deleted NC users
                         still listed as team members. Backed by
                         MaintenanceService::findGhostMembers, remediated on
                         the Maintenance tab. -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Ghost memberships')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Ghost memberships:') }}</span>
                        <template v-if="complianceSummary.loading">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Checking…') }}
                            </span>
                        </template>
                        <template v-else-if="complianceSummary.error">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Unavailable') }}
                            </span>
                        </template>
                        <template v-else>
                            <span
                                class="integrity-pill"
                                :class="'integrity-pill--' + (ghostCount > 0 ? 'err' : 'ok')"
                                role="status"
                                aria-live="polite">
                                <span class="integrity-pill__dot" />
                                {{ ghostCount }}
                            </span>
                        </template>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Ghost memberships details')"
                                :title="t('teamhub', 'Ghost memberships details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Deleted Nextcloud users still listed as team members. Clean them up under Maintenance → Deleted users in teams.') }}
                                </NcActionText>
                                <NcActionText v-if="complianceSummary.report && complianceSummary.report.ghost_memberships.sample_uid">
                                    {{ t('teamhub', 'Example: {uid}', { uid: complianceSummary.report.ghost_memberships.sample_uid }) }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Ghost memberships']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Ghost memberships'] }) }}
                                </NcActionText>
                            </NcActions>
                            <div class="compliance-row__refresh-slot">
                                <NcButton
                                    variant="tertiary-no-background"
                                    :aria-label="t('teamhub', 'Re-scan compliance summary')"
                                    :title="t('teamhub', 'Re-scan compliance summary')"
                                    :disabled="complianceSummary.loading"
                                    @click="loadComplianceSummary">
                                    <template #icon>
                                        <RefreshIcon :size="18" />
                                    </template>
                                </NcButton>
                            </div>
                        </div>
                    </div>

                    <!-- Row: Orphan teams (v4.2.10) — teams with no owner.
                         Backed by MaintenanceService::getOrphanedTeams; the
                         Maintenance tab has the reassign-owner flow. -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Orphan teams')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Orphan teams:') }}</span>
                        <template v-if="complianceSummary.loading">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Checking…') }}
                            </span>
                        </template>
                        <template v-else-if="complianceSummary.error">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Unavailable') }}
                            </span>
                        </template>
                        <template v-else>
                            <span
                                class="integrity-pill"
                                :class="'integrity-pill--' + (orphanCount > 0 ? 'err' : 'ok')"
                                role="status"
                                aria-live="polite">
                                <span class="integrity-pill__dot" />
                                {{ orphanCount }}
                            </span>
                        </template>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Orphan teams details')"
                                :title="t('teamhub', 'Orphan teams details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Teams with no live owner. Assign a new owner under Maintenance → All teams to restore governance.') }}
                                </NcActionText>
                                <NcActionText v-if="complianceSummary.report && complianceSummary.report.orphan_teams.sample_name">
                                    {{ t('teamhub', 'Example: {team}', { team: complianceSummary.report.orphan_teams.sample_name }) }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Orphan teams']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Orphan teams'] }) }}
                                </NcActionText>
                            </NcActions>
                            <div class="compliance-row__refresh-slot" aria-hidden="true" />
                        </div>
                    </div>

                    <!-- Row: Team profile compliance (v4.8.15, Track F) — of
                         the teams carrying a policy profile, how many still
                         match it. Only classified teams are read: an
                         unclassified team has no expectation to depart from.
                         The pill has a fourth state ("None") for the instance
                         where nothing is classified, because a green 0 there
                         would claim a clean result from a check that ran
                         against nothing — TRACK-F2-DESIGN §5.3. -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Team profile compliance')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Team profiles:') }}</span>
                        <template v-if="complianceSummary.loading">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Checking…') }}
                            </span>
                        </template>
                        <template v-else-if="complianceSummary.error || profileComplianceState === 'unknown'">
                            <span class="integrity-pill integrity-pill--unknown">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'Unavailable') }}
                            </span>
                        </template>
                        <template v-else-if="profileComplianceState === 'none'">
                            <span
                                class="integrity-pill integrity-pill--unknown"
                                role="status"
                                aria-live="polite">
                                <span class="integrity-pill__dot" />
                                {{ t('teamhub', 'No teams classified') }}
                            </span>
                        </template>
                        <template v-else>
                            <!-- The count is the DRIFTED one, matching every
                                 other row on this tab: the number in the pill
                                 is always what needs attention, never what is
                                 healthy. The conformant and classified figures
                                 are in the info menu beside it. -->
                            <span
                                class="integrity-pill"
                                :class="'integrity-pill--' + profileComplianceState"
                                role="status"
                                aria-live="polite">
                                <span class="integrity-pill__dot" />
                                {{ profileDriftCount }}
                            </span>
                        </template>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Team profile compliance details')"
                                :title="t('teamhub', 'Team profile compliance details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <!-- v4.8.15 — the "detected, not prevented"
                                     caveat used to be here and now points at the
                                     Maintenance tab instead. It is not lost: the
                                     printed compliance report still carries it in
                                     full, which is where an auditor reads it, and
                                     this menu is where an admin decides what to
                                     do next. -->
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Teams whose settings no longer match the policy profile they have applied. Check the maintenance tab for the non-compliant teams.') }}
                                </NcActionText>
                                <NcActionText v-if="profileCompliance">
                                    <!-- TRANSLATORS: {n} teams carry a policy profile, of which {ok} still match it -->
                                    {{ n('teamhub', '{n} team classified, {ok} still matching', '{n} teams classified, {ok} still matching', profileClassifiedCount, { n: profileClassifiedCount, ok: profileCompliance.conformant }) }}
                                </NcActionText>
                                <NcActionText v-if="profileComplianceState === 'none'">
                                    {{ t('teamhub', 'No team carries a policy profile yet, so nothing was compared. Teams take a profile when they are created; assign profiles under Policy.') }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Team profile compliance']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Team profile compliance'] }) }}
                                </NcActionText>
                            </NcActions>
                            <div class="compliance-row__refresh-slot" aria-hidden="true" />
                        </div>
                    </div>

                    <!-- Row: Audit log retention (v4.8.0) — the record-keeping
                         half of the Audit panel below, surfaced here because a
                         retention window is what an auditor asks for and the
                         panel is where you change it, not where you read it. -->
                    <div class="compliance-row" role="group" :aria-label="t('teamhub', 'Audit log retention')">
                        <span class="compliance-row__label">{{ t('teamhub', 'Audit log:') }}</span>
                        <span
                            class="integrity-pill"
                            :class="'integrity-pill--' + (auditRetentionLoaded ? 'ok' : 'unknown')"
                            role="status"
                            aria-live="polite">
                            <span class="integrity-pill__dot" />
                            <!-- auditRetention carries a 90-day default before
                                 the fetch lands; showing it would report an
                                 assumption as a finding. -->
                            {{ auditRetentionLoaded
                                ? n('teamhub', '{n} day retention', '{n} days retention', auditRetention.retention_days, { n: auditRetention.retention_days })
                                : t('teamhub', 'Unavailable') }}
                        </span>
                        <span class="compliance-row__spacer" />
                        <div class="compliance-row__actions">
                            <NcActions
                                :aria-label="t('teamhub', 'Audit log retention details')"
                                :title="t('teamhub', 'Audit log retention details')">
                                <template #icon>
                                    <InformationOutline :size="18" />
                                </template>
                                <NcActionText>
                                    <template #icon><InformationOutline :size="18" /></template>
                                    {{ t('teamhub', 'Events are appended and purged in bulk after the retention window. No code path updates or deletes an individual row, so a record cannot be rewritten before it expires.') }}
                                </NcActionText>
                                <NcActionText v-if="complianceControlsByCheck['Audit log retention']">
                                    {{ t('teamhub', 'ISO 27001: {controls}', { controls: complianceControlsByCheck['Audit log retention'] }) }}
                                </NcActionText>
                            </NcActions>
                            <div class="compliance-row__refresh-slot" aria-hidden="true" />
                        </div>
                    </div>
                </div>
            </NcSettingsSection>

            <!-- ── Audit log (existing) ────────────────────────────────────── -->
            <NcSettingsSection
                :name="t('teamhub', 'Audit log')"
                :description="t('teamhub', 'Per-team activity log capturing membership, file, and share events for governance and compliance.')">

            <!-- Always-visible info banner: explains hourly cadence -->
            <div class="audit-banner audit-banner--info">
                <div class="audit-banner__head">
                    <InformationOutline :size="18" />
                    <strong>{{ t('teamhub', 'Audit log updates hourly') }}</strong>
                </div>
                <span>{{ t('teamhub', 'External activity (member, file, and share events) is mirrored from Nextcloud once per hour by a background job. New events may take up to an hour to appear here. TeamHub-internal actions (team creation, join requests) are recorded immediately.') }}</span>
            </div>

            <!-- Activity-app-missing banner -->
            <div v-if="auditActivityMissing" class="audit-banner audit-banner--warn">
                <strong>{{ t('teamhub', 'Activity app disabled') }}</strong>
                <span>{{ t('teamhub', 'The Nextcloud Activity app is disabled. Audit logs will only contain TeamHub-internal events until it is re-enabled.') }}</span>
            </div>

            <!-- Retention setting -->
            <div class="audit-retention">
                <label class="audit-retention__label">
                    {{ t('teamhub', 'Retention period') }}
                    <span class="admin-section-hint">
                        {{ t('teamhub', 'Audit rows older than this are automatically purged. Allowed range: {min}–{max} days.', { min: auditRetention.min, max: auditRetention.max }) }}
                    </span>
                </label>
                <!-- v4.6.2 — autosaves on a debounce; the Save button is gone.
                     canSaveRetention already gates on "parses, in range, and
                     different from what is stored", so an out-of-range or
                     half-typed number never reaches the server. Typing a
                     three-digit value does pass through a valid two-digit one
                     (365 → 36), which the 1200 ms debounce swallows unless the
                     admin pauses mid-number; the write is idempotent and the
                     final value wins, the same trade-off already accepted for
                     the IntraVox path field. -->
                <div class="audit-retention__controls">
                    <NcTextField
                        v-model="auditRetentionInput"
                        type="number"
                        :min="auditRetention.min"
                        :max="auditRetention.max"
                        :label="t('teamhub', 'Days')"
                        :label-visible="false"
                        :disabled="auditRetentionSaving"
                        @input="auditRetentionInput = $event.target.value; onAuditRetentionInput()" />
                    <span class="audit-retention__suffix">{{ t('teamhub', 'days') }}</span>
                    <NcLoadingIcon v-if="auditRetentionSaving" :size="18" />
                </div>
            </div>

            <!-- Team picker + filters -->
            <div class="audit-controls">
                <div class="audit-controls__row">
                    <label class="audit-controls__label" for="audit-team-select">
                        {{ t('teamhub', 'Team') }}
                    </label>
                    <select
                        id="audit-team-select"
                        v-model="auditSelectedTeamId"
                        class="audit-controls__team-select"
                        :disabled="auditTeamsLoading"
                        @change="onAuditTeamChanged">
                        <option value="">— {{ t('teamhub', 'Select a team') }} —</option>
                        <option
                            v-for="t in auditTeams"
                            :key="t.team_id"
                            :value="t.team_id">
                            {{ t.display_name }} ({{ t.event_count }})
                        </option>
                    </select>
                    <NcButton
                        variant="tertiary"
                        :disabled="auditTeamsLoading"
                        :aria-label="t('teamhub', 'Reload teams')"
                        @click="loadAuditTeams">
                        <template #icon>
                            <NcLoadingIcon v-if="auditTeamsLoading" :size="18" />
                            <RefreshIcon v-else :size="18" />
                        </template>
                    </NcButton>
                </div>

                <div v-if="auditTeamsError" class="admin-save-err">{{ auditTeamsError }}</div>

                <div v-if="auditSelectedTeamId" class="audit-controls__row">
                    <label class="audit-controls__label" for="audit-event-filter">
                        {{ t('teamhub', 'Event types') }}
                    </label>
                    <select
                        id="audit-event-filter"
                        v-model="auditEventTypeFilter"
                        class="audit-controls__filter-select"
                        @change="resetAndLoadAuditEvents">
                        <option value="">{{ t('teamhub', 'All events') }}</option>
                        <option
                            v-for="ev in auditEventCatalogue"
                            :key="ev"
                            :value="ev">
                            {{ ev }}
                        </option>
                    </select>
                </div>

                <div v-if="auditSelectedTeamId" class="audit-controls__row">
                    <label class="audit-controls__label" for="audit-from">
                        {{ t('teamhub', 'From') }}
                    </label>
                    <input
                        id="audit-from"
                        v-model="auditFromDate"
                        type="date"
                        class="audit-controls__date"
                        @change="resetAndLoadAuditEvents">
                    <label class="audit-controls__label" for="audit-to">
                        {{ t('teamhub', 'To') }}
                    </label>
                    <input
                        id="audit-to"
                        v-model="auditToDate"
                        type="date"
                        class="audit-controls__date"
                        @change="resetAndLoadAuditEvents">
                    <NcButton
                        variant="secondary"
                        :disabled="auditExporting || !auditSelectedTeamId"
                        @click="exportAuditTeam">
                        <template #icon>
                            <NcLoadingIcon v-if="auditExporting" :size="18" />
                            <DownloadIcon v-else :size="18" />
                        </template>
                        {{ auditExporting ? t('teamhub', 'Exporting…') : t('teamhub', 'Download ZIP') }}
                    </NcButton>
                </div>
            </div>

            <!-- Empty state when no team selected -->
            <div v-if="!auditSelectedTeamId && !auditTeamsLoading" class="audit-empty">
                <ShieldCheckIcon :size="40" />
                <p>{{ t('teamhub', 'Select a team to view its audit log.') }}</p>
            </div>

            <!-- Events table -->
            <div v-if="auditSelectedTeamId" class="audit-events">
                <div v-if="auditEventsLoading" class="audit-events__loading">
                    <NcLoadingIcon :size="32" />
                </div>
                <div v-else-if="auditEventsError" class="admin-save-err">{{ auditEventsError }}</div>
                <div v-else-if="auditEvents.length === 0" class="audit-empty">
                    <p>{{ t('teamhub', 'No events recorded for the selected filters.') }}</p>
                </div>
                <table v-else class="audit-table">
                    <thead>
                        <tr>
                            <th>{{ t('teamhub', 'When') }}</th>
                            <th>{{ t('teamhub', 'Event') }}</th>
                            <th>{{ t('teamhub', 'Actor') }}</th>
                            <th>{{ t('teamhub', 'Target') }}</th>
                            <th>{{ t('teamhub', 'Details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ev in auditEvents" :key="ev.id">
                            <td class="audit-table__when">{{ formatAuditTimestamp(ev.created_at) }}</td>
                            <td class="audit-table__event">{{ ev.event_type }}</td>
                            <td>{{ ev.actor_uid || '—' }}</td>
                            <td class="audit-table__target">
                                <span v-if="ev.target_type">{{ ev.target_type }}: </span>
                                {{ ev.target_id || '—' }}
                            </td>
                            <td class="audit-table__details">
                                <code v-if="ev.metadata">{{ summariseAuditMetadata(ev.metadata) }}</code>
                                <span v-else>—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="auditEvents.length > 0" class="maint-pagination">
                    <NcButton
                        variant="tertiary"
                        :disabled="auditEventsPage <= 1 || auditEventsLoading"
                        @click="changeAuditPage(auditEventsPage - 1)">
                        ← {{ t('teamhub', 'Previous') }}
                    </NcButton>
                    <span class="maint-page-info">
                        {{ t('teamhub', 'Page {p} of {n} ({total} events)', {
                            p: auditEventsPage,
                            n: auditEventsTotalPages,
                            total: auditEventsTotal,
                        }) }}
                    </span>
                    <NcButton
                        variant="tertiary"
                        :disabled="auditEventsPage >= auditEventsTotalPages || auditEventsLoading"
                        @click="changeAuditPage(auditEventsPage + 1)">
                        {{ t('teamhub', 'Next') }} →
                    </NcButton>
                </div>
            </div>
            </NcSettingsSection>
            </template>
        </div>

        <!-- ──────────────────────────────────────────────────────────────────
             Archive tab
             ───────────────────────────────────────────────────────────────── -->
        <NcSettingsSection
            v-show="activeTab === 'archive'"
            id="tab-panel-archive"
            role="tabpanel"
            :name="t('teamhub', 'Archive')"
            :description="t('teamhub', 'Configure how teams are archived when deleted and view teams pending deletion.')">

            <!-- Archive settings card -->
            <div class="archive-admin">

                <h3 class="archive-admin__heading">{{ t('teamhub', 'Archive policy') }}</h3>

                <!-- Archive-before-delete master toggle -->
                <div class="archive-admin__field">
                    <NcCheckboxRadioSwitch
                        v-model="archiveSettings.archiveBeforeDelete"
                        type="checkbox">
                        {{ t('teamhub', 'Archive teams before deletion') }}
                    </NcCheckboxRadioSwitch>
                    <p class="archive-admin__help">
                        {{ t('teamhub', 'When enabled, deleting a team produces an archive ZIP first, then applies the deletion mode below. When disabled, teams are deleted directly without producing an archive.') }}
                    </p>
                </div>

                <!-- Deletion mode -->
                <fieldset class="archive-admin__fieldset">
                    <legend class="archive-admin__legend">{{ t('teamhub', 'Deletion mode') }}</legend>
                    <NcCheckboxRadioSwitch
                        v-model="archiveSettings.archiveMode"
                        value="soft30"
                        name="archive_mode"
                        type="radio">
                        {{ t('teamhub', 'Soft delete — 30 day grace period') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="archiveSettings.archiveMode"
                        value="soft60"
                        name="archive_mode"
                        type="radio">
                        {{ t('teamhub', 'Soft delete — 60 day grace period') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="archiveSettings.archiveMode"
                        value="hard"
                        name="archive_mode"
                        type="radio">
                        {{ t('teamhub', 'Hard delete (no grace period)') }}
                    </NcCheckboxRadioSwitch>
                    <p class="archive-admin__help">
                        {{ t('teamhub', 'Soft delete hides the team immediately and permanently deletes it after the grace period. Administrators can restore the team before the deadline.') }}
                    </p>
                </fieldset>

                <!-- Archive storage location — single field -->
                <div class="archive-admin__field" :class="{ 'archive-admin__field--disabled': !archiveSettings.archiveBeforeDelete }">
                    <label class="archive-admin__label" for="archive-location">
                        {{ t('teamhub', 'Archive location (Team Folder)') }}
                    </label>
                    <input
                        id="archive-location"
                        v-model="archiveSettings.archiveLocation"
                        type="text"
                        class="archive-admin__input"
                        :disabled="!archiveSettings.archiveBeforeDelete"
                        :placeholder="t('teamhub', 'Leave empty to use each team owner\'s Files')" />
                    <p class="archive-admin__help">
                        {{ t('teamhub', 'Paste the internal link of a Team Folder (e.g. /f/150770 from the URL bar). Leave empty to save archives in each team owner\'s Files under "TeamHub Archives".') }}
                    </p>
                </div>

                <!-- Max archive size -->
                <div class="archive-admin__field" :class="{ 'archive-admin__field--disabled': !archiveSettings.archiveBeforeDelete }">
                    <label class="archive-admin__label" for="archive-max-mb">
                        {{ t('teamhub', 'Maximum archive size (MB)') }}
                    </label>
                    <input
                        id="archive-max-mb"
                        v-model.number="archiveSettings.archiveMaxMb"
                        type="number"
                        min="1"
                        max="51200"
                        :disabled="!archiveSettings.archiveBeforeDelete"
                        class="archive-admin__input archive-admin__input--short" />
                    <p class="archive-admin__help">
                        {{ t('teamhub', 'If the estimated archive size exceeds this limit, the archiving is refused. Default: 5120 MB (5 GB).') }}
                    </p>
                </div>

                <!-- Pseudonymize -->
                <div class="archive-admin__field" :class="{ 'archive-admin__field--disabled': !archiveSettings.archiveBeforeDelete }">
                    <NcCheckboxRadioSwitch
                        v-model="archiveSettings.anonymizeData"
                        type="checkbox"
                        :disabled="!archiveSettings.archiveBeforeDelete">
                        {{ t('teamhub', 'Pseudonymize personal identifiers') }}
                    </NcCheckboxRadioSwitch>
                    <p class="archive-admin__help">
                        {{ t('teamhub', 'Replaces user identifiers (UIDs) in the archive with stable aliases. Message and comment text is preserved as-is — names mentioned within content are not removed. The archive remains personal data under GDPR but with reduced linkability.') }}
                    </p>
                </div>

                <!-- v4.0.3 — auto-save on change (see watch.archiveSettings).
                     Explicit Save button removed; only surface an error message
                     when the auto-save round-trip fails. -->
                <div v-if="archiveSettingsError" class="archive-admin__actions">
                    <span class="archive-admin__err">
                        {{ archiveSettingsError }}
                    </span>
                </div>

                <!-- Pending deletions table -->
                <h3 class="archive-admin__heading archive-admin__heading--mt">
                    {{ t('teamhub', 'Archived teams') }}
                </h3>

                <div class="archive-admin__toolbar">
                    <NcButton
                        variant="secondary"
                        :disabled="pendingDelsLoading"
                        :aria-label="t('teamhub', 'Refresh archived teams list')"
                        @click="loadPendingDeletions">
                        <template #icon>
                            <NcLoadingIcon v-if="pendingDelsLoading" :size="18" />
                            <RefreshIcon v-else :size="18" />
                        </template>
                        {{ t('teamhub', 'Refresh') }}
                    </NcButton>
                </div>

                <p v-if="visiblePendingDels.length === 0 && !pendingDelsLoading" class="archive-admin__empty">
                    {{ t('teamhub', 'No archived teams.') }}
                </p>

                <table v-else class="archive-admin__table" :aria-label="t('teamhub', 'Archived teams')">
                    <caption class="archive-admin__table-caption">
                        {{ t('teamhub', 'Teams pending deletion or with a failed archive attempt') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t('teamhub', 'Team') }}</th>
                            <th scope="col">{{ t('teamhub', 'Archived by') }}</th>
                            <th scope="col">{{ t('teamhub', 'Archived') }}</th>
                            <th scope="col">{{ t('teamhub', 'Deletes in') }}</th>
                            <th scope="col">{{ t('teamhub', 'Size') }}</th>
                            <th scope="col">{{ t('teamhub', 'Status') }}</th>
                            <th scope="col">{{ t('teamhub', 'Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="row in visiblePendingDels" :key="row.id">
                        <tr>
                            <td>{{ row.teamName }}</td>
                            <td :title="row.archivedBy">{{ row.archivedByDisplayName || row.archivedBy }}</td>
                            <td>{{ formatUnixDate(row.archivedAt) }}</td>
                            <td>
                                <span v-if="row.status === 'pending'">
                                    <!-- TRANSLATORS: {n} is the number of days remaining before deletion -->
                                    {{ n('teamhub', '{n} day', '{n} days', row.daysRemaining, { n: row.daysRemaining }) }}
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td>{{ formatBytes(row.archiveBytes) }}</td>
                            <td>
                                <span :class="'archive-admin__status archive-admin__status--' + row.status">
                                    {{ row.status }}
                                </span>
                            </td>
                            <td class="archive-admin__row-actions">
                                <!-- pending: Restore + Force delete -->
                                <template v-if="row.status === 'pending'">
                                    <NcButton
                                        variant="tertiary"
                                        size="small"
                                        :aria-label="t('teamhub', 'Restore team {name}', { name: row.teamName })"
                                        @click="restorePendingDeletion(row.id)">
                                        {{ t('teamhub', 'Restore') }}
                                    </NcButton>
                                    <NcButton
                                        variant="error"
                                        size="small"
                                        :aria-label="t('teamhub', 'Force delete team {name} immediately', { name: row.teamName })"
                                        @click="purgePendingDeletion(row.id)">
                                        {{ t('teamhub', 'Force delete') }}
                                    </NcButton>
                                </template>
                                <!-- failed: View error button toggles inline error panel -->
                                <template v-else-if="row.status === 'failed'">
                                    <NcButton
                                        variant="tertiary"
                                        size="small"
                                        :aria-label="t('teamhub', 'View error for team {name}', { name: row.teamName })"
                                        @click="toggleFailedDetail(row.id)">
                                        {{ failedDetailId === row.id ? t('teamhub', 'Hide error') : t('teamhub', 'View error') }}
                                    </NcButton>
                                </template>
                            </td>
                        </tr>
                        <!-- Failed detail row — inline error panel with Retry + Cancel -->
                        <tr v-if="row.status === 'failed' && failedDetailId === row.id" class="archive-admin__error-row">
                            <td colspan="7">
                                <div class="archive-admin__error-panel" role="alert">
                                    <strong>{{ t('teamhub', 'Archive failed') }}</strong>
                                    <code v-if="row.failureReason" class="archive-admin__error-reason">{{ row.failureReason }}</code>
                                    <div class="archive-admin__error-actions">
                                        <NcButton
                                            variant="primary"
                                            size="small"
                                            :aria-label="t('teamhub', 'Retry archive for team {name}', { name: row.teamName })"
                                            @click="retryArchive(row.id)">
                                            {{ t('teamhub', 'Retry') }}
                                        </NcButton>
                                        <NcButton
                                            variant="secondary"
                                            size="small"
                                            :aria-label="t('teamhub', 'Cancel failed archive for team {name} and make team usable again', { name: row.teamName })"
                                            @click="discardFailedArchive(row.id)">
                                            {{ t('teamhub', 'Cancel — make team usable again') }}
                                        </NcButton>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div>

        </NcSettingsSection>

        <!-- ──────────────────────────────────────────────────────────────────
             License tab (v3.100.0, Track F)
             Presents license status + a paste field for the JWT + instance
             UUID with copy button + telemetry status (Connected only). Its
             own save flow (PUT /license) so the global "Save settings"
             row is hidden while this tab is active.
             ───────────────────────────────────────────────────────────── -->
        <NcSettingsSection
            v-show="activeTab === 'license'"
            id="tab-panel-license"
            role="tabpanel"
            :name="t('teamhub', 'License')">

            <div v-if="license.loading" class="license-loading">
                <NcLoadingIcon :size="18" /> {{ t('teamhub', 'Loading license status…') }}
            </div>

            <template v-else-if="license.status">
                <!-- Status pill -->
                <div class="license-status-row">
                    <span class="license-pill" :class="'license-pill--' + licensePillLevel">
                        <span class="license-pill__dot" />
                        {{ licenseStatusLabel }}
                    </span>
                    <span v-if="license.status.isTrial && license.status.valid" class="license-trial-flag">
                        {{ t('teamhub', 'Trial') }}
                    </span>
                </div>

                <!-- Seat-overage banner. Warn state = still functional;
                     lock state = new Advanced blocked + writes locked.
                     Text spells out where the numbers come from so the
                     admin knows exactly what to change. -->
                <div
                    v-if="license.status.seatEnforcement === 'over-warn'"
                    class="license-seat-banner license-seat-banner--warn"
                    role="status">
                    <strong>{{ t('teamhub', 'Over your licensed seats: {used} of {cap} used.', {
                        used: license.status.seatsUsed,
                        cap: license.status.seatCap,
                    }) }}</strong>
                    <span>
                        {{ t('teamhub', 'You can grow to {lockAt} users before Advanced-team creation and writes lock. Upgrade the license or reduce unique team members.', {
                            lockAt: license.status.seatLockAt,
                        }) }}
                    </span>
                </div>
                <div
                    v-else-if="license.status.seatEnforcement === 'over-lock'"
                    class="license-seat-banner license-seat-banner--lock"
                    role="alert">
                    <strong>{{ t('teamhub', 'Seat cap exceeded — Advanced features locked: {used} of {cap} used (max {lockAt}).', {
                        used: license.status.seatsUsed,
                        cap: license.status.seatCap,
                        lockAt: license.status.seatLockAt,
                    }) }}</strong>
                    <span>
                        {{ t('teamhub', 'Existing Advanced teams still work read-only. Creating new Advanced teams and updating Budget/Time/Milestone data is blocked. Upgrade the license or reduce unique team members to unlock.') }}
                    </span>
                </div>

                <!-- v4.10.0 — the case for a licence, shown only while none is
                     honoured (Justin, 2026-09-18, for the company launch of
                     2026-09-22): what a licence adds, in the words of the public
                     licensing model, and one click to ask for a quote. Gone the
                     moment a licence is active, trial or grace — an admin who
                     has paid is not sold to. No prices here: the model page
                     carries them and says they may change, and a translated
                     string in fourteen files cannot follow a price list. The
                     quote mail is pre-filled with the two facts a quote is
                     priced on, the instance UUID and the seats in use. -->
                <section
                    v-if="!licenseActive"
                    class="license-pitch"
                    aria-labelledby="license-pitch-title">
                    <h3 id="license-pitch-title" class="license-pitch__title">
                        {{ t('teamhub', 'What a TeamHub license adds') }}
                    </h3>
                    <ul class="license-pitch__list">
                        <li>
                            <strong>{{ t('teamhub', 'Every licensed module') }}</strong>
                            <span class="license-pitch__sep" aria-hidden="true">—</span>
                            <span>{{ t('teamhub', 'Advanced projects, What’s new, My Work with file reviews, the OpenProject module, bulk team creation and export.') }}</span>
                        </li>
                        <li>
                            <strong>{{ t('teamhub', 'The Compliance tab') }}</strong>
                            <span class="license-pitch__sep" aria-hidden="true">—</span>
                            <span>{{ t('teamhub', 'Code-integrity check, policy compliance and governance signals in one place.') }}</span>
                        </li>
                        <li>
                            <!-- TRANSLATORS: "Quiet mode" is the licensing model's own term for an
                                 instance without TeamHub branding, prompts or telemetry -->
                            <strong>{{ t('teamhub', 'Quiet mode') }}</strong>
                            <span class="license-pitch__sep" aria-hidden="true">—</span>
                            <span>{{ t('teamhub', 'No TeamHub branding, no license prompts, no telemetry.') }}</span>
                        </li>
                        <li>
                            <strong>{{ t('teamhub', 'Development support') }}</strong>
                            <span class="license-pitch__sep" aria-hidden="true">—</span>
                            <span>{{ t('teamhub', 'A direct line to the maintainer, priority on bug reports, and a say in the roadmap.') }}</span>
                        </li>
                        <li>
                            <!-- TRANSLATORS: heading of the bullet on how seats are counted -->
                            <strong>{{ t('teamhub', 'Fair seats') }}</strong>
                            <span class="license-pitch__sep" aria-hidden="true">—</span>
                            <span>{{ t('teamhub', 'One seat per unique member across all your teams — a person in five teams counts once — and no connection to a license server, ever.') }}</span>
                        </li>
                    </ul>
                    <div class="license-pitch__actions">
                        <!-- TRANSLATORS: button; opens the admin's mail client to ask
                             for a commercial price quote -->
                        <NcButton variant="primary" :href="requestQuoteMailto">
                            <template #icon>
                                <EmailOutlineIcon :size="18" />
                            </template>
                            {{ t('teamhub', 'Request a quote') }}
                        </NcButton>
                        <a
                            href="https://tldr.host/teamhub/licensing.html"
                            class="license-pitch__link"
                            target="_blank"
                            rel="noopener">
                            {{ t('teamhub', 'See the licensing model') }} →
                        </a>
                    </div>
                    <p class="license-pitch__hint">
                        {{ t('teamhub', 'Opens your mail client with this instance’s UUID and seats in use filled in. Send it from the address that should receive the quote.') }}
                    </p>
                </section>

                <!-- Detail table (only when there IS a key installed) -->
                <dl v-if="license.status.hasKey" class="license-detail">
                    <template v-if="license.status.customer">
                        <dt>{{ t('teamhub', 'Customer') }}</dt>
                        <dd>{{ license.status.customer }}</dd>
                    </template>
                    <template v-if="license.status.kind">
                        <dt>{{ t('teamhub', 'Type') }}</dt>
                        <dd>{{ licenseKindLabel }}</dd>
                    </template>
                    <template v-if="license.status.seats">
                        <dt>{{ t('teamhub', 'Seats') }}</dt>
                        <dd>
                            {{ license.status.seatsUsed }}
                            /
                            <template v-if="license.status.seats >= 999999">
                                {{ t('teamhub', 'Unlimited') }}
                            </template>
                            <template v-else>
                                {{ license.status.seats }}
                            </template>
                            <span v-if="license.status.seatsOverBy > 0" class="license-over">
                                ({{ n('teamhub', '{n} over', '{n} over', license.status.seatsOverBy, { n: license.status.seatsOverBy }) }})
                            </span>
                        </dd>
                    </template>
                    <template v-if="license.status.expiresAt">
                        <dt>{{ t('teamhub', 'Expires') }}</dt>
                        <dd>
                            {{ formatLicenseDate(license.status.expiresAt) }}
                            <span v-if="license.status.daysRemaining !== null && license.status.valid">
                                ({{ n('teamhub', '{n} day left', '{n} days left', license.status.daysRemaining, { n: license.status.daysRemaining }) }})
                            </span>
                            <span v-else-if="license.status.enforcementLevel === 'grace'" class="license-over">
                                ({{ n('teamhub', 'grace: {n} day left', 'grace: {n} days left', license.status.graceRemaining, { n: license.status.graceRemaining }) }})
                            </span>
                        </dd>
                    </template>
                    <template v-if="license.status.invalidReason">
                        <dt>{{ t('teamhub', 'Problem') }}</dt>
                        <dd class="license-over">{{ license.status.invalidReason }}</dd>
                    </template>
                </dl>

                <!-- Instance UUID (always shown so admins can copy it into
                     a purchase form or email). -->
                <div class="license-uuid">
                    <div class="license-uuid__label">
                        {{ t('teamhub', 'Instance UUID') }}
                    </div>
                    <div class="license-uuid__value">
                        <code>{{ license.status.instanceUuid }}</code>
                        <NcButton
                            variant="tertiary"
                            :aria-label="t('teamhub', 'Copy UUID')"
                            @click="copyUuid">
                            <template #icon>
                                <ContentCopyIcon :size="16" />
                            </template>
                        </NcButton>
                        <span v-if="license.uuidCopied" class="license-copied">
                            {{ t('teamhub', 'Copied!') }}
                        </span>
                    </div>
                    <p class="license-uuid__hint">
                        {{ t('teamhub', 'Send this UUID with your license request. Licenses are bound to a single instance UUID.') }}
                    </p>
                </div>

                <!-- Paste-or-replace key -->
                <div class="license-key-row">
                    <label class="license-key-row__label" for="teamhub-license-key">
                        {{ license.status.hasKey ? t('teamhub', 'Replace license key') : t('teamhub', 'Paste license key') }}
                    </label>
                    <textarea
                        id="teamhub-license-key"
                        v-model="license.pendingKey"
                        class="license-key-row__input"
                        rows="4"
                        spellcheck="false"
                        autocomplete="off"
                        :placeholder="t('teamhub', 'Paste the JWT from your license email here')" />
                    <div class="license-key-row__actions">
                        <NcButton
                            variant="primary"
                            :disabled="!license.pendingKey.trim() || license.saving"
                            @click="saveLicenseKey">
                            <template #icon>
                                <NcLoadingIcon v-if="license.saving" :size="18" />
                                <ContentSave v-else :size="18" />
                            </template>
                            {{ license.saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save license key') }}
                        </NcButton>
                        <span v-if="license.saveError" class="admin-save-err">{{ license.saveError }}</span>
                    </div>
                </div>

                <!-- Licensing info → public marketing/licensing page.
                     Request-by-email → mailto with UUID prefilled. We issue
                     every trial and every paid license by hand from the
                     licensing dashboard and reply with the JWT. There is
                     no automated trial endpoint. -->
                <div class="license-links">
                    <a href="https://tldr.host/teamhub/licensing.html" target="_blank" rel="noopener">
                        {{ t('teamhub', 'Licensing info') }} →
                    </a>
                    <!-- TRANSLATORS: opens the admin's mail client to request
                         a trial license. Pre-fills subject + UUID. -->
                    <a
                        :href="requestTrialMailto"
                        class="license-trial-mailto">
                        {{ t('teamhub', 'Request trial by email') }}
                    </a>
                </div>
                <p class="license-trial-hint">
                    {{ t('teamhub', 'Send the request from the address that should receive the license key — we reply to the sender.') }}
                </p>
            </template>
        </NcSettingsSection>

        <!-- v4.6.2 — the shared "Save settings" row is gone. It was rendered
             for every tab NOT in an exclusion list, and by this version the
             list had grown to cover all of them but two: Team creation, whose
             controls now write on change, and Presence, which is three child
             managers with their own endpoints that the row never saved
             anything for in the first place. A button that is either redundant
             or inert is worse than no button — it implies changes are pending
             when they are already stored. Per-tab autosave status regions
             replace it. -->

        <!-- ── Delete orphan confirmation dialog ─────────────────────── -->
        <NcDialog
            v-if="confirmDeleteDialog && confirmDeleteTeam"
            :name="t('teamhub', 'Delete team')"
            :open="confirmDeleteDialog"
            @update:open="cancelDeleteOrphan">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Delete "{name}" and all its data? This cannot be undone.', { name: confirmDeleteTeam.name || confirmDeleteTeam.id }) }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelDeleteOrphan">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="error"
                    :disabled="deletingTeam === confirmDeleteTeam.id"
                    @click="executeDeleteOrphan">
                    <template #icon>
                        <NcLoadingIcon v-if="deletingTeam === confirmDeleteTeam.id" :size="18" />
                        <DeleteIcon v-else :size="18" />
                    </template>
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Apply a policy profile to an existing team (v4.8.16) ───────
             TRACK-F2-DESIGN §4.3. Two stages in one dialog: pick a profile,
             then read the field-by-field diff before confirming. The preview is
             not skippable — "a silent bulk state change to somebody's teams is
             not acceptable even when it is correct", and an admin rolling a
             profile across an estate needs to see what they are about to do. -->
        <NcDialog
            v-if="assignPolicyTeam"
            :name="t('teamhub', 'Apply a policy profile')"
            :open="!!assignPolicyTeam"
            size="normal"
            @update:open="closeAssignPolicy">
            <template #default>
                <p class="assign-policy__lead">
                    {{ t('teamhub', 'Choose the profile to apply to {team}. Its settings will be written to the team.', { team: assignPolicyTeam.name }) }}
                </p>

                <p v-if="assignPolicyError" class="assign-policy__error" role="alert">
                    {{ assignPolicyError }}
                </p>

                <NcLoadingIcon v-if="assignPolicyProfilesLoading" :size="20" />

                <fieldset v-else class="assign-policy__choices">
                    <legend class="assign-policy__legend">{{ t('teamhub', 'Policy profile') }}</legend>
                    <NcCheckboxRadioSwitch
                        v-for="p in assignPolicyProfiles"
                        :key="p.profileKey"
                        :model-value="assignPolicyKey"
                        :value="p.profileKey"
                        name="assign-policy-profile"
                        type="radio"
                        @update:model-value="selectAssignPolicy">
                        {{ profileName(p) }}
                        <span v-if="p.profileKey === assignPolicyTeam.classification?.profileKey" class="assign-policy__current">
                            {{ t('teamhub', '(applied now)') }}
                        </span>
                    </NcCheckboxRadioSwitch>
                </fieldset>

                <!-- ── The diff ─────────────────────────────────────────── -->
                <div v-if="assignPolicyPreviewLoading" class="assign-policy__preview">
                    <NcLoadingIcon :size="20" /> {{ t('teamhub', 'Working out what would change…') }}
                </div>

                <div v-else-if="assignPolicyPreview" class="assign-policy__preview">
                    <p v-if="assignPolicyPreview.governsNothing" class="assign-policy__note">
                        {{ t('teamhub', 'This profile governs no settings, so the team is classified and nothing is changed.') }}
                    </p>
                    <p v-else-if="!assignPolicyPreview.changes.length" class="assign-policy__note">
                        {{ t('teamhub', 'This team already matches the profile. Applying it changes no setting.') }}
                    </p>

                    <div v-if="assignPolicyPreview.changes.length" class="drift-dialog__grid" role="table" :aria-label="t('teamhub', 'What will change')">
                        <div class="drift-dialog__head" role="row">
                            <div role="columnheader">{{ t('teamhub', 'Setting') }}</div>
                            <div role="columnheader">{{ t('teamhub', 'Now') }}</div>
                            <div role="columnheader">{{ t('teamhub', 'After applying') }}</div>
                        </div>
                        <div
                            v-for="c in assignPolicyPreview.changes"
                            :key="c.field"
                            class="drift-dialog__row"
                            role="row">
                            <div role="cell">
                                {{ fieldLabel(c.field) }}
                                <!-- The one field confirming does NOT fix.
                                     Said here rather than only in the result,
                                     so a red chip straight after an apply is
                                     never a surprise. -->
                                <!-- v4.8.24 — `applied: false` now has two
                                     causes and they need different sentences.
                                     Branch on the reason first: a team with no
                                     team folder is not "members are never
                                     removed", and it is explicitly NOT reported
                                     as non-compliant afterwards, so the external
                                     members wording would be wrong twice. -->
                                <span v-if="c.reason === 'no_team_folder'" class="drift-dialog__note">
                                    {{ t('teamhub', 'This team has no team folder, so there is nothing to tag. Nothing is reported for it — create a team folder and apply the profile again.') }}
                                </span>
                                <span v-else-if="c.applied === false" class="drift-dialog__note">
                                    {{ t('teamhub', 'Not changed by applying — existing members are never removed. The team will be reported as non-compliant until they are.') }}
                                </span>
                                <span v-else-if="c.disables && c.disables.length" class="drift-dialog__note">
                                    {{ t('teamhub', 'These integrations are switched off for the team: {apps}. Their own data is not deleted.', { apps: c.disables.map(appLabelFor).join(', ') }) }}
                                </span>
                                <!-- v4.8.28 — a tag the folder carries that no
                                     profile governs and no classification label
                                     names. Left in place, because TeamHub has no
                                     basis for calling somebody's own tag a
                                     classification — but said out loud, because
                                     a second tag beside the applied one is what
                                     stops the classification biting. -->
                                <span v-if="c.otherTags && c.otherTags.length" class="drift-dialog__note">
                                    {{ t('teamhub', 'The folder also carries {tags}, which no profile governs. It stays — govern it from a profile, or name it from a Confidential files label, and it will be replaced next time.', { tags: c.otherTags.map(tg => tg.name || tg.id).join(', ') }) }}
                                </span>
                            </div>
                            <div role="cell">{{ driftValueLabel(c.field, c.current, c.currentLabel) }}</div>
                            <div class="drift-dialog__observed" role="cell">{{ driftValueLabel(c.field, c.next, c.nextLabel) }}</div>
                        </div>
                    </div>

                    <p v-if="assignPolicyPreview.unchanged.length" class="assign-policy__note">
                        {{ n('teamhub', 'The profile also governs {n} setting this team already matches.', 'The profile also governs {n} settings this team already matches.', assignPolicyPreview.unchanged.length, { n: assignPolicyPreview.unchanged.length }) }}
                    </p>
                    <p v-if="assignPolicyPreview.reassignment" class="assign-policy__note">
                        {{ t('teamhub', 'This replaces the profile the team carries now.') }}
                    </p>
                </div>
            </template>
            <template #actions>
                <!-- Clearing writes no setting, with one exception since
                     v4.8.24: the classification tag is taken back off the team
                     folder. It is the only governed value that exists solely
                     because a profile put it there and that keeps working after
                     declassification — an access-control rule keyed on it would
                     go on restricting a team nobody is classifying. Offered only
                     when there is something to clear. -->
                <NcButton
                    v-if="assignPolicyTeam.classification && assignPolicyTeam.classification.profileKey"
                    variant="tertiary"
                    :disabled="assignPolicySaving"
                    :title="t('teamhub', 'The team keeps its current settings and stops being checked against a profile. Any classification tag the profile put on the team folder is removed.')"
                    @click="clearAssignPolicy">
                    {{ t('teamhub', 'Remove the profile') }}
                </NcButton>
                <NcButton variant="tertiary" :disabled="assignPolicySaving" @click="closeAssignPolicy">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="!assignPolicyPreview || assignPolicySaving"
                    @click="confirmAssignPolicy">
                    <template #icon>
                        <NcLoadingIcon v-if="assignPolicySaving" :size="18" />
                        <ShieldLockOutlineIcon v-else :size="18" />
                    </template>
                    {{ t('teamhub', 'Apply the profile') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Profile drift detail (v4.8.15) ─────────────────────────────
             What a red chip on the Maintenance grid opens. Read-only: it says
             what differs, and every route to changing it back is somewhere
             else — the profile editor on the Policy tab, or the team's own
             settings. A "fix it" button here would be the bulk re-apply that
             TRACK-F2-DESIGN §5.3 keeps deliberately out of scope. -->
        <NcDialog
            v-if="driftDialogTeam"
            :name="t('teamhub', 'Settings that no longer match')"
            :open="!!driftDialogTeam"
            @update:open="driftDialogTeam = null">
            <template #default>
                <p class="drift-dialog__lead">
                    {{ t('teamhub', '{team} has the {profile} profile applied. These settings differ from what it defines:', {
                        team: driftDialogTeam.name,
                        profile: profileChipLabel(driftDialogTeam),
                    }) }}
                </p>
                <div class="drift-dialog__grid" role="table" :aria-label="t('teamhub', 'Settings that no longer match')">
                    <div class="drift-dialog__head" role="row">
                        <!-- TRANSLATORS: column header — the name of one team
                             setting a policy profile governs -->
                        <div role="columnheader">{{ t('teamhub', 'Setting') }}</div>
                        <!-- TRANSLATORS: column header — the value the policy
                             profile defines for this setting -->
                        <div role="columnheader">{{ t('teamhub', 'Profile says') }}</div>
                        <!-- TRANSLATORS: column header — the value the team
                             actually has right now, which differs -->
                        <div role="columnheader">{{ t('teamhub', 'Team has') }}</div>
                    </div>
                    <div
                        v-for="f in driftDialogTeam.classification.driftedFields"
                        :key="f.field"
                        class="drift-dialog__row"
                        role="row">
                        <div role="cell">
                            {{ fieldLabel(f.field) }}
                            <!-- The one enforced field. Worth saying out loud:
                                 a difference here cannot be somebody editing
                                 around us in Contacts, because nothing outside
                                 TeamHub writes it. -->
                            <span v-if="f.enforced" class="drift-dialog__note">
                                {{ t('teamhub', 'Set before the profile was applied — nothing outside TeamHub can change this.') }}
                            </span>
                        </div>
                        <div role="cell">{{ driftValueLabel(f.field, f.expected, f.expectedLabel) }}</div>
                        <div class="drift-dialog__observed" role="cell">{{ driftValueLabel(f.field, f.observed) }}</div>
                    </div>
                </div>
                <p class="drift-dialog__foot">
                    {{ t('teamhub', 'Most of these can also be changed in Contacts or the Teams app, which report nothing back — so a difference is detected here, not prevented, and cannot name who made it.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="driftDialogTeam = null">
                    {{ t('teamhub', 'Close') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Reset team config confirmation dialog ─────────────────── -->
        <NcDialog
            v-if="confirmResetConfigDialog && confirmResetConfigTeam"
            :name="t('teamhub', 'Reset team config')"
            :open="confirmResetConfigDialog"
            @update:open="cancelResetTeamConfig">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Reset all user-managed and system-flag bits on "{name}" to clean defaults?', { name: confirmResetConfigTeam.name || confirmResetConfigTeam.id }) }}
                </p>
                <p style="margin: 0; color: var(--color-text-maxcontrast);">
                    {{ t('teamhub', 'This clears any corrupted bits set by older versions of TeamHub. The team owner will need to reconfigure its checkbox settings afterwards.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelResetTeamConfig">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="resettingConfigTeamId === confirmResetConfigTeam.id"
                    @click="executeResetTeamConfig">
                    <template #icon>
                        <NcLoadingIcon v-if="resettingConfigTeamId === confirmResetConfigTeam.id" :size="18" />
                        <RestoreIcon v-else :size="18" />
                    </template>
                    {{ t('teamhub', 'Reset config') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Unlink OpenProject project confirmation (v4.9.4) ─────────
             Not destructive in OpenProject — one TeamHub row goes — but it
             is one-way for the team: nothing can link it again, so the
             dialog says exactly what the team keeps and what it loses. -->
        <NcDialog
            v-if="confirmUnlinkOpenProjectTeam"
            :name="t('teamhub', 'Unlink OpenProject project')"
            :open="!!confirmUnlinkOpenProjectTeam"
            @update:open="cancelUnlinkOpenProject">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Unlink "{project}" from "{name}"?', {
                        project: confirmUnlinkOpenProjectTeam.openproject.projectName || confirmUnlinkOpenProjectTeam.openproject.projectIdentifier,
                        name: confirmUnlinkOpenProjectTeam.name || confirmUnlinkOpenProjectTeam.id,
                    }) }}
                </p>
                <p style="margin: 0; color: var(--color-text-maxcontrast);">
                    {{ t('teamhub', 'The team keeps everything else and loses its OpenProject widgets. Nothing changes in OpenProject, and the project can then be linked by a new team. A team cannot be linked again afterwards.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelUnlinkOpenProject">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="unlinkingOpenProjectTeamId === confirmUnlinkOpenProjectTeam.id"
                    @click="executeUnlinkOpenProject">
                    <template #icon>
                        <NcLoadingIcon v-if="unlinkingOpenProjectTeamId === confirmUnlinkOpenProjectTeam.id" :size="18" />
                        <LinkOffIcon v-else :size="18" />
                    </template>
                    <!-- TRANSLATORS: confirm button — remove a team's link to its OpenProject project -->
                    {{ t('teamhub', 'Unlink') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- ── Extension request decision (v4.6.17) ──────────────────────
             Was a popout anchored to the table row. Two things made that
             untenable and neither was cosmetic: the row's action buttons are
             painted over it, and the teams table scrolls horizontally, which
             clips anything positioned out of a cell. A dialog owns its own
             stacking context and its own focus trap, so the date field and the
             note are reachable by keyboard without fighting the grid. -->
        <NcDialog
            v-if="expiryReqTeam"
            :name="t('teamhub', 'Extension request for {name}', { name: expiryReqTeam.name || expiryReqTeam.id })"
            :open="!!expiryReqTeam"
            size="normal"
            @update:open="closeExpiryRequest">
            <template #default>
                <template v-if="expiryReqActive">
                    <dl class="maint-expiry-req__facts">
                        <dt>{{ t('teamhub', 'Requested by') }}</dt>
                        <dd>{{ expiryReqActive.requestedName }}</dd>
                        <dt>{{ t('teamhub', 'Requested until') }}</dt>
                        <dd><strong>{{ expiryReqActive.proposedOn }}</strong></dd>
                        <dt>{{ t('teamhub', 'Reason') }}</dt>
                        <dd>{{ expiryReqActive.reason || '—' }}</dd>
                    </dl>

                    <!-- Prefilled with the requested date so the common case is
                         one click, while granting something shorter stays
                         possible without a second screen. -->
                    <label
                        class="maint-expiry-req__label"
                        :for="'expiry-req-date-' + expiryReqActive.id">
                        {{ t('teamhub', 'Date to grant') }}
                    </label>
                    <input
                        :id="'expiry-req-date-' + expiryReqActive.id"
                        v-model="expiryReqGrant[expiryReqActive.id]"
                        type="date"
                        class="maint-date-input"
                        :min="tomorrowIso" />
                    <NcTextField
                        v-model="expiryReqNote[expiryReqActive.id]"
                        class="maint-expiry-req__note"
                        :label="t('teamhub', 'Note to the requester')"
                        :placeholder="t('teamhub', 'Optional — required reading if you deny')" />
                </template>

                <!-- The row says a request is pending but the request list does
                     not have it: somebody else decided it, or the list has not
                     landed yet. Say so rather than showing an empty dialog. -->
                <p v-else class="maint-expiry-req__missing">
                    {{ expiryReqLoading
                        ? t('teamhub', 'Loading the request…')
                        : t('teamhub', 'This request is no longer waiting for a decision. Refresh to update the list.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="closeExpiryRequest">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <template v-if="expiryReqActive">
                    <NcButton
                        variant="error"
                        :disabled="expiryReqBusy === expiryReqActive.id"
                        @click="denyExpiryRequest(expiryReqActive)">
                        {{ t('teamhub', 'Deny') }}
                    </NcButton>
                    <NcButton
                        variant="primary"
                        :disabled="expiryReqBusy === expiryReqActive.id"
                        @click="approveExpiryRequest(expiryReqActive)">
                        <template #icon>
                            <NcLoadingIcon
                                v-if="expiryReqBusy === expiryReqActive.id"
                                :size="18" />
                        </template>
                        {{ t('teamhub', 'Approve') }}
                    </NcButton>
                </template>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { todayIso, shiftToday, formatDate as fmtDate, formatDateTime as fmtDateTime } from '../lib/localDate.js'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
    NcSettingsSection, NcButton, NcLoadingIcon,
    NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
    NcActions, NcActionText,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import MessageTextIcon from 'vue-material-design-icons/MessageText.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AccountEditIcon from 'vue-material-design-icons/AccountEdit.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
// v4.8.2 — Track F2a. Distinct from ShieldCheck (Compliance): Policy is where
// the control is configured, Compliance is where the evidence is read.
import ShieldLockOutlineIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
// v4.6.13 — marks an expiring or expired team in the All teams grid, so the
// state is not carried by colour alone (WCAG 1.4.1).
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import AccountOffIcon from 'vue-material-design-icons/AccountOff.vue'
import AccountRemoveIcon from 'vue-material-design-icons/AccountRemove.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import ClipboardCheckIcon from 'vue-material-design-icons/ClipboardCheck.vue'
// v4.6.16 — the extension-request banner and the row's decision popout.
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
// v4.6.17 — expiry editor's Clear, and the Email owner button.
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
// v3.100.14: MDI icon for the group-chip remove button — replaces the
// × multiplication-sign character (gui.md § 13). Cross-font consistency.
import CloseIcon from 'vue-material-design-icons/Close.vue'
// v4.9.4 — the OpenProject link under a team's name, and its Unlink action.
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import LinkOffIcon from 'vue-material-design-icons/LinkOff.vue'
// v4.2.0: Compliance tab — code-integrity status icons.
import AlertOctagonIcon from 'vue-material-design-icons/AlertOctagon.vue'
// v4.4.14: Compliance report PDF export button.
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

// Presence module — Session B1 / v3.42.0 admin sub-panels.
import PresenceTypesManager     from './PresenceTypesManager.vue'
import PresenceLocationsManager from './PresenceLocationsManager.vue'
import PresenceHolidaysManager  from './PresenceHolidaysManager.vue'
import MyWorkAdminSettings     from './mywork/MyWorkAdminSettings.vue'
// v4.6.6 — bulk team import. Its own file (and its own admin/ directory,
// following the mywork/ precedent) because this file is already ~6 700 lines.
import TeamImportPanel         from './admin/TeamImportPanel.vue'
import TeamExportPanel         from './admin/TeamExportPanel.vue'
// v4.8.2 — Track F2a. Templates and classification profiles. Its own file for
// the same reason the two above are: this one is already ~7 900 lines.
import PolicyAdminPanel        from './admin/PolicyAdminPanel.vue'
// v4.9.6 — Phase 2: the provisioning operations list on the Maintenance tab.
import ProvisioningAdminPanel  from './admin/ProvisioningAdminPanel.vue'
// v4.8.0 — ISO 27001 control mapping for the Compliance tab and its report.
import { isoControlLabel, buildControlCoverage } from '../constants/isoControls.js'
// v4.8.15 — the compliance row names the field a team has drifted on. The
// backend sends the field *key*; the label is translated here, because
// `check:l10n` scans src/ only and a name defined in PHP is outside its reach.
import { FIELD, appLabel, fieldLabel, profileDisplayName, templateDisplayName } from '../constants/policy.js'

export default {
    name: 'AdminSettings',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon,
        NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
        NcActions, NcActionText,
        ContentSave, AccountGroup, AccountPlusIcon, SwapHorizontalIcon, MessageTextIcon, PuzzleIcon,
        ChartBarIcon, WrenchIcon, DeleteIcon, AccountEditIcon, ShieldCheckIcon, DownloadIcon, RefreshIcon, RestoreIcon,
        InformationOutline, ArchiveIcon, AccountOffIcon, AccountRemoveIcon, MagnifyIcon, AlertCircleOutlineIcon,
        OfficeBuildingIcon,
        KeyIcon, ContentCopyIcon, CloseIcon, LinkVariantIcon, LinkOffIcon,
        AlertOctagonIcon, FileDocumentOutlineIcon,
        PresenceTypesManager, PresenceLocationsManager, PresenceHolidaysManager,
        ClipboardCheckIcon, MyWorkAdminSettings,
        TeamImportPanel, TeamExportPanel,
        CalendarClockIcon, CalendarRemoveIcon, EmailOutlineIcon,
        PolicyAdminPanel, ShieldLockOutlineIcon,
        ProvisioningAdminPanel,
    },
    data() {
        return {
            activeTab: 'creation',

            // v3.100.0 — Track F licensing tab state. All under one
            // object so unrelated tabs don't collide with 'saving'/'saved'.
            license: {
                loading:         false,
                status:          null,
                pendingKey:      '',
                saving:          false,
                saveError:       null,
                refreshing:      false,
                showPayload:     false,
                uuidCopied:      false,
            },
            loading: true,
            saving: false,
            saved: false,
            saveError: null,
            form: {
                wizardDescription: '',
                pinMinLevel: 'moderator',
                intravoxParentPath: 'en/teamhub',
                presenceModuleEnabled: false,
                decisionsModuleEnabled: false,
                // v4.9.16 — the OpenProject module's switch (off by default).
                openProjectModuleEnabled: false,
                // RoomVox: token is write-only. roomvoxTokenConfigured
                // reflects whether one is currently stored (returned from
                // the load endpoint as a boolean); roomvoxApiToken is the
                // write buffer for the input field — empty means "don't
                // change the stored value".
                roomvoxApiToken: '',
                roomvoxTokenConfigured: false,
                // v4.4.4 — instance-level dismissal of the first-run setup
                // checklist. Starts true so a fresh page load doesn't flash
                // the checklist before load() reports the stored value.
                onboardingChecklistDismissed: true,
                // v4.6.13 — days before a team's expiration date that the
                // warning reaches My Work. Server clamps to [min, max].
                expiryWarningDays: 7,
                // 'YYYY-MM-DD', six months out, computed server-side. Read-only
                // here — it prefills date pickers, it is never saved back.
                expiryDefaultDate: '',
            },
            // Bounds for the warning-window field, delivered by the settings
            // endpoint so the input's own validation and the server's agree.
            expiryWarningDaysMin: 1,
            expiryWarningDaysMax: 365,
            // ── Maintenance tab — expiration dates (v4.6.13) ───────────────
            expiryEditTeamId: null,   // team id whose inline date editor is open
            expiryEditValue: '',      // 'YYYY-MM-DD' in that editor
            expirySaving: false,
            expiryRequests: [],       // pending extension requests, oldest first
            expiryReqLoading: false,
            expiryReqError: null,
            expiryReqBusy: null,      // request id currently being decided
            expiryReqGrant: {},       // requestId → date to grant
            expiryReqNote: {},        // requestId → note back to the requester
            // v4.6.17 — the team whose extension-request dialog is open, or
            // null. Holds the team row rather than its id (which is what the
            // popout this replaced kept) because the dialog renders outside the
            // v-for and needs the team's name for its title.
            expiryReqTeam: null,
            // Invite type toggles
            inviteGroup: true,
            inviteCircle: false,
            inviteEmail: false,
            inviteFederated: false,
            // Group picker
            selectedGroups: [],
            groupQuery: '',
            groupResults: [],
            groupSearching: false,
            groupSearchTimer: null,
            // Team Folders delegation status (loaded from admin settings API)
            gfDelegation: {
                groupFoldersInstalled:       false,
                teamCreatorGroupsConfigured: false,
            },
            // Integrations tab
            integrations: [],
            integrationsLoading: false,
            integrationsError: null,
            // Statistics tab
            telemetry: { enabled: true, report_url: '', preview: {} },
            telemetryLoading: false,
            telemetrySaving: false,
            // Maintenance — teams grid
            teamsPage: [],
            teamsTotal: 0,
            teamsPage_current: 1,
            teamsPerPage: 20,
            teamsSearch: '',
            teamsOrphansOnly: false,
            teamsLoading: false,
            teamsError: null,
            teamsSearchTimer: null,
            deletingTeam: null,
            // Reset team config (per-team action + integrity repair)
            resettingConfigTeamId: null,
            confirmResetConfigDialog: false,
            confirmResetConfigTeam: null,
            // v4.9.4 — unlink OpenProject project (per-team action). The
            // team row whose dialog is open, or null; the id being unlinked.
            confirmUnlinkOpenProjectTeam: null,
            unlinkingOpenProjectTeamId: null,
            // Config integrity scan
            // { issues: [{id, name, config, badBits}],
            //   appClaimed: [{id, name, config, appBits}] }
            // Two independent findings — see MaintenanceService::checkConfigIntegrity.
            configCheck: null,
            configCheckLoading: false,
            configCheckError: null,
            // Delete confirmation dialog
            confirmDeleteDialog: false,
            confirmDeleteTeam: null,
            // Owner assignment
            assignTeamId: null,
            ownerQuery: '',
            ownerResults: [],
            ownerSearching: false,
            ownerSearchTimer: null,
            // v4.8.35 — so the picker can say why it found nothing.
            ownerError: '',
            ownerSearched: false,
            assigningOwner: false,
            // Membership integrity
            membershipCheck: null,     // { total_teams, healthy, mismatched, issues }
            membershipCheckLoading: false,
            membershipCheckError: null,
            membershipRepairing: {},   // { teamId: bool }
            // ── Ghost member cleanup tab ────────────────────────────────────
            ghostMembers: [],          // [{ userId, displayName, teams: [{ teamId, teamName }] }]
            ghostLoading: false,
            ghostError: null,
            ghostScanned: false,
            ghostSearch: '',
            ghostSearchTimer: null,
            ghostRemoving: {},         // { 'userId:teamId': bool, 'userId:all': bool }
            // ── Compliance tab — code integrity (v4.2.0) ───────────────
            //   loaded: whether an initial check has been run
            //   loading: request in flight
            //   report: full JSON envelope from GET /api/v1/admin/integrity
            //   error: user-facing error string, if any
            integrity: {
                loaded: false,
                loading: false,
                error: null,
                report: null,
            },

            // v4.2.10 — Aggregated governance-risk summary shown as extra
            // Compliance pills (ghost memberships + orphan teams). One-shot
            // load per tab open, refreshed via loadComplianceSummary().
            complianceSummary: {
                loaded: false,
                loading: false,
                error: null,
                report: null,
            },

            // v4.8.15 — counts behind the setup checklist's two Track F rows.
            // Loaded on mount rather than on tab open, because the checklist
            // lives on the *first* tab and a row that renders "0 templates"
            // for a moment and then corrects itself is worse than one that
            // says "Checking…" once. Counts only; the editor is the Policy tab.
            policySummary: {
                loaded: false,
                loading: false,
                error: null,
                report: null,
            },

            // v4.8.15 — the team row whose drift dialog is open, or null. The
            // row itself rather than its id: everything the dialog renders is
            // already on it, and holding the object means the dialog cannot
            // outlive a page change and show a team that is no longer listed.
            driftDialogTeam: null,

            // v4.8.16 — applying a policy profile to an existing team.
            // `assignPolicyProfiles` is fetched once per page load and kept:
            // the list is four rows on a stock instance and an admin rolling a
            // profile across an estate opens this dialog repeatedly.
            assignPolicyTeam: null,
            assignPolicyProfiles: [],
            assignPolicyProfilesLoading: false,
            assignPolicyKey: '',
            assignPolicyPreview: null,
            assignPolicyPreviewLoading: false,
            assignPolicySaving: false,
            assignPolicyError: null,

            // ── Audit tab ──────────────────────────────────────────────
            auditTeams: [],            // [{ team_id, display_name, event_count, last_event_at }]
            auditTeamsLoading: false,
            auditTeamsError: null,
            auditActivityMissing: false,
            auditSelectedTeamId: '',
            auditEvents: [],
            auditEventsTotal: 0,
            auditEventsPage: 1,
            auditEventsPerPage: 50,
            auditEventsLoading: false,
            auditEventsError: null,
            auditEventTypeFilter: '',  // comma-separated list, empty = all
            auditFromDate: '',          // YYYY-MM-DD
            auditToDate: '',            // YYYY-MM-DD
            auditExporting: false,
            auditRetention: { retention_days: 90, min: 7, max: 3650, default: 90 },
            auditRetentionInput: 90,
            auditRetentionSaving: false,
            auditRetentionLoaded: false,
            // Catalogue of known event types — feeds the multi-select filter
            auditEventCatalogue: [
                'team.created', 'team.deleted', 'team.config_changed',
                'team.owner_transferred', 'team.app_enabled', 'team.app_disabled',
                // v4.6.13 — team expiration dates. Mirrors the event strings
                // TeamExpiryService writes; this list is the filter's
                // vocabulary, so a missing entry means the event is logged but
                // cannot be filtered for.
                'team.expiry_set', 'team.expiry_extended', 'team.expiry_cleared',
                'team.expiry_extension_requested', 'team.expiry_extension_approved',
                'team.expiry_extension_denied', 'team.expiry_extension_withdrawn',
                // v4.6.14 — one row per team written into a downloaded CSV.
                // Member lists leaving the instance as a file is exactly the
                // kind of disclosure the compliance tab exists to show.
                'team.exported',
                'member.joined', 'member.left', 'member.removed', 'member.removed_by_admin', 'member.level_changed',
                // v4.7.5 — the message stream. `comment.deleted` has been
                // written since 4.5.x but was never added here, so it was
                // logged and un-filterable; the four new ones ship with their
                // entries rather than repeating that.
                'message.created', 'message.updated', 'message.deleted',
                'comment.updated', 'comment.deleted',
                'invite.sent',
                'join.requested', 'join.approved', 'join.rejected',
                'file.created', 'file.edited', 'file.deleted',
                'share.created', 'share.permissions_changed', 'share.deleted',
            ],
            // ── Audit tab — Find teams for a user ──────────────────────────
            audit_userQuery: '',
            audit_userResults: [],
            audit_userSearching: false,
            audit_userSearchTimer: null,
            audit_selectedUser: null,    // { uid, displayName }
            audit_teamRows: [],          // rows from listTeamsForUser
            audit_teamsLoading: false,
            audit_teamsError: null,
            audit_selectedTeamIds: [],
            audit_removeBusy: false,
            audit_removeConfirmOpen: false,
            // ── Archive tab ────────────────────────────────────────────────
            archiveSettings: {
                archiveBeforeDelete: false,
                archiveMode:     'soft30',
                archiveLocation: '',
                archiveMaxMb:    5120,
                anonymizeData:   false,
            },
            archiveSettingsSaving: false,
            archiveSettingsSaved: false,
            archiveSettingsError: null,
            archiveSettingsLoaded: false,
            pendingDels: [],
            pendingDelsTotal: 0,
            pendingDelsLoading: false,
            pendingDelsError: null,
            failedDetailId: null,   // row.id of the failed row whose error panel is open
        }
    },
    computed: {
        tabs() {
            const list = [
                { id: 'creation',      label: this.t('teamhub', 'Team creation'), icon: 'AccountPlusIcon' },
                // v4.8.2 — Track F2a. Next to Team creation because that is
                // what a template and a profile decide: what a new team is
                // made of, and what its settings are set to. Deliberately NOT
                // folded into Compliance — that tab is evidence for an
                // auditor, and putting the editor on it makes the evidence
                // editable from the page that presents it.
                { id: 'policy',        label: this.t('teamhub', 'Policy'),        icon: 'ShieldLockOutlineIcon' },
                // v4.6.10 — bulk import left Team creation for its own tab.
                // Sits next to it because that is what it does (create teams),
                // and because the policy it obeys is configured one tab left.
                { id: 'importexport',  label: this.t('teamhub', 'Import/Export'), icon: 'SwapHorizontalIcon' },
                // v4.4.13 — the Invitations tab held a single section (Allowed
                // invite types) and now lives under Team creation: who may
                // create a team and whom they may then invite are one decision,
                // and a tab per boolean-set is not worth its own tab stop.
                { id: 'integrations',  label: this.t('teamhub', 'Integrations'),  icon: 'PuzzleIcon'      },
                // v4.5.21 — My Work sits next to Integrations because that is
                // what it configures: which sources feed the personal queue.
                { id: 'mywork',        label: this.t('teamhub', 'My Work'),       icon: 'ClipboardCheckIcon' },
                { id: 'statistics',    label: this.t('teamhub', 'Reporting'),     icon: 'ChartBarIcon'    },
                { id: 'maintenance',   label: this.t('teamhub', 'Maintenance'),   icon: 'WrenchIcon'      },
                { id: 'audit',         label: this.t('teamhub', 'Compliance'),     icon: 'ShieldCheckIcon' },
                { id: 'archive',       label: this.t('teamhub', 'Archive'),        icon: 'ArchiveIcon'     },
                // v3.100.0 — Track F. License tab always visible; state
                // pill inside shows whether a valid key is installed.
                { id: 'license',       label: this.t('teamhub', 'License'),        icon: 'KeyIcon'         },
            ]
            // Presence module tab only visible when the module is enabled.
            // v4.5.21 — anchored to the Archive tab by id rather than by the
            // hard-coded index 5 it used before. Inserting the My Work tab
            // above shifted that index and silently moved Presence in front of
            // Compliance; anchoring means the next tab added cannot repeat it.
            if (this.form.presenceModuleEnabled) {
                const archiveIndex = list.findIndex(tab => tab.id === 'archive')
                const at = archiveIndex === -1 ? list.length : archiveIndex
                list.splice(at, 0, { id: 'presence', label: this.t('teamhub', 'Presence module'), icon: 'OfficeBuildingIcon' })
            }
            return list
        },

        /**
         * Only show rows that still need admin attention: pending and failed.
         * Restored and completed rows are removed from the admin view immediately
         * after the action completes (spliced from pendingDels in the method).
         * This computed acts as a final guard in case any slip through.
         */
        visiblePendingDels() {
            return this.pendingDels.filter(r => r.status === 'pending' || r.status === 'failed')
        },

        // ── v3.100.0 Licensing computeds ────────────────────────────
        licensePillLevel() {
            const s = this.license.status
            if (!s) return 'ok'
            if (s.enforcementLevel === 'none')       return 'ok'
            if (s.enforcementLevel === 'grace')      return 'warn'
            return 'err'   // 'unlicensed' | 'soft-lock'
        },

        /**
         * v4.6.28 — the enforcement ladder, in one place.
         *
         * Every licensed feature in the app reads the same two levels:
         * `none` (Active or Trial) and `grace` are honoured, `soft-lock` and
         * `unlicensed` are not. It matches `MessageController::getPersonalFeed`
         * and `TeamExportController::licenceGate()` on the server, which are
         * the actual boundaries — this computed only decides what to draw.
         *
         * **A license that has not loaded yet reads as inactive.** `status` is
         * null until `loadLicense()` resolves (fired on mount) and stays null
         * if that request failed, so a licensed admin can see a "license
         * required" banner for the width of one round trip. That is the
         * behaviour the Compliance tab has shipped with since 4.3.0; the
         * alternative — treating unknown as licensed — would show a paid
         * feature to an instance that has not paid for it, every time the
         * license endpoint is slow.
         */
        licenseActive() {
            const level = this.license.status?.enforcementLevel
            return level === 'none' || level === 'grace'
        },

        // ── v4.3.0 Compliance-tab computeds ─────────────────────────
        /**
         * The Compliance checks (Code integrity, Telemetry) are a licensed
         * feature. Unlocked whenever the customer has a currently-honoured
         * license — Active, Trial, or Grace. Soft-lock and Unlicensed hide
         * the checks and surface a "License required" banner instead. The
         * Audit log section on the same tab is not gated.
         */
        complianceUnlocked() {
            return this.licenseActive
        },

        /**
         * Whether TeamHub is currently sending anonymous usage telemetry.
         * Derived: if the license is active/trial/grace the customer is
         * paying and we don't gather usage stats; otherwise (unlicensed or
         * soft-locked) telemetry is on so Justin can see who is running
         * TeamHub without a license. Mirrors TelemetryService::isEnabled()
         * on the backend so the pill matches what the server is doing.
         */
        telemetryEnabledDerived() {
            return !this.complianceUnlocked
        },

        /**
         * v4.4.14 — true when the current Allowed-invite-types selection
         * lets team admins reach beyond this server. Drives both the
         * Compliance-tab pill (warn vs ok) and the phrasing of its info
         * text, so the label and the details always agree.
         */
        invitesExternalReach() {
            return this.inviteEmail || this.inviteFederated
        },

        // v4.2.10 — governance-risk counts for the extra Compliance pills.
        // Default to 0 while the summary is loading / errored so the pill
        // still renders (in "unknown" state via the surrounding v-if).
        ghostCount() {
            return this.complianceSummary.report?.ghost_memberships?.count ?? 0
        },
        orphanCount() {
            return this.complianceSummary.report?.orphan_teams?.count ?? 0
        },

        /**
         * v4.8.15 — the profile-conformance block, or null before it loads.
         * `{ classified, conformant, drifted, findings, findingsTruncated,
         *    checked_at }`
         */
        profileCompliance() {
            return this.complianceSummary.report?.profile_compliance ?? null
        },
        profileDriftCount() {
            return this.profileCompliance?.drifted ?? 0
        },
        profileClassifiedCount() {
            return this.profileCompliance?.classified ?? 0
        },
        /**
         * The pill's state, and the reason this row is not a plain count.
         *
         * `none` exists because **zero drifted and zero classified are not the
         * same answer** and a green 0 would say they were. On an instance where
         * no team carries a profile there is nothing being checked, and
         * TRACK-F2-DESIGN §5.3 names a drift count of zero in that situation as
         * the most dangerous false statement this feature can make — it reads as
         * "no deviations" to exactly the person who would act on that.
         *
         * @return {'unknown'|'none'|'err'|'ok'}
         */
        profileComplianceState() {
            if (!this.profileCompliance) return 'unknown'
            if (this.profileClassifiedCount === 0) return 'none'
            return this.profileDriftCount > 0 ? 'err' : 'ok'
        },
        /**
         * v4.8.0 — check name → its ISO controls, as display strings.
         *
         * Derived from `buildComplianceReportChecks()` rather than repeated
         * in the template, so the tab and the PDF can never disagree about
         * which control a check evidences. The rows in the template are
         * hand-written for layout reasons; only the control mapping is
         * shared, and it has exactly one home.
         */
        complianceControlsByCheck() {
            const out = {}
            for (const c of this.buildComplianceReportChecks()) {
                if (Array.isArray(c.controls) && c.controls.length) {
                    out[c.name] = c.controls.map(isoControlLabel).join(' · ')
                }
            }
            return out
        },
        /**
         * v4.4.4 — rows for the first-run setup checklist (onboarding plan
         * § 3.2). Derived from live state on every render rather than from a
         * stored wizard position, so a row can never claim something is
         * configured after an admin changes it back.
         *
         * Three states, each carrying its meaning in TEXT as well as colour
         * (WCAG 1.4.1 — no information by colour alone); the glyph itself is
         * aria-hidden and adds nothing a screen reader needs.
         *   ok   — decision made, nothing to do
         *   warn — a default is in force that has real consequences
         *   info — worth knowing, not a problem
         */
        setupChecklist() {
            const rows = []

            // ── Who can create teams ─────────────────────────────────────
            // The single highest-consequence default in the app: an empty
            // creator group means EVERY user can create teams, and each team
            // provisions a Talk room, a Deck board, a calendar and a folder.
            // An untouched install is indistinguishable from a deliberate
            // choice, which is exactly what this row exists to fix.
            const groupCount = this.selectedGroups.length
            rows.push(groupCount === 0
                ? {
                    id:    'creation',
                    state: 'warn',
                    glyph: '⚠',
                    label: this.t('teamhub', 'Who can create teams'),
                    value: this.t('teamhub', 'Everyone on this server'),
                    hint:  this.t('teamhub', 'Creation permissions are empty, and empty means unrestricted — not admins only. Every new team provisions a Talk room, a Deck board, a calendar and a folder. Set a group under Creation permissions below to restrict it.'),
                }
                : {
                    id:    'creation',
                    state: 'ok',
                    glyph: '✓',
                    label: this.t('teamhub', 'Who can create teams'),
                    value: this.n('teamhub', 'Restricted to {n} group', 'Restricted to {n} groups', groupCount, { n: groupCount }),
                    hint:  '',
                })

            // v4.4.14 helper: rows that are neither problems nor "unresolved"
            // display • when the value is at the shipped default and ✓ when
            // an admin has moved it off. State + glyph in lockstep so a
            // screen reader hears the same signal the eye does.
            const preferenceRow = (id, label, value, changed) => ({
                id,
                state: changed ? 'ok' : 'info',
                glyph: changed ? '✓' : '•',
                label,
                value,
                hint: '',
            })

            // ── Templates and policy profiles (v4.8.15, Track F) ─────────
            // Two rows rather than one. A template decides what a new team is
            // made of; a profile decides what its settings are set to. They are
            // configured on the same tab and are still separate decisions —
            // folding them into "Policy: configured" would let an admin who has
            // tuned three templates read the row as covering profiles too.
            //
            // Neither row is a warning. Both ship seeded and both work
            // untouched, which is Track F's inertness rule: leave every seeded
            // template and profile alone and TeamHub behaves exactly as it did
            // before the feature existed. So "not adjusted" is • (a fact worth
            // knowing), never ⚠ (a default with consequences) — the state the
            // "Who can create teams" row above is reserved for.
            const policy = this.policySummary.report
            if (!policy) {
                // One row, not two, while the fetch is in flight or after it
                // failed. Two "Checking…" lines for one request reads as two
                // things being slow.
                rows.push({
                    id: 'policy', state: 'info', glyph: '•',
                    label: this.t('teamhub', 'Templates and policy profiles'),
                    value: this.policySummary.error
                        ? this.t('teamhub', 'Unavailable')
                        : this.t('teamhub', 'Checking…'),
                    hint: this.policySummary.error || '',
                })
            } else {
                rows.push({
                    id:    'templates',
                    state: policy.templatesAdjusted > 0 ? 'ok' : 'info',
                    glyph: policy.templatesAdjusted > 0 ? '✓' : '•',
                    label: this.t('teamhub', 'Team templates'),
                    value: policy.templatesAdjusted > 0
                        // TRANSLATORS: {n} of {total} team templates have been edited by an
                        // administrator. t() not n(): there is no noun in the string to inflect,
                        // so a plural pair would be two identical forms.
                        ? this.t('teamhub', '{n} of {total} adjusted', { n: policy.templatesAdjusted, total: policy.templates })
                        : this.n('teamhub', '{n} template, none adjusted', '{n} templates, none adjusted', policy.templates, { n: policy.templates }),
                    hint:  policy.templatesAdjusted > 0
                        ? ''
                        : this.t('teamhub', 'Templates decide which apps and modules a new team is given, and how long it may live. Adjust them under Policy → Team templates.'),
                })

                // The profiles row carries the assignment count as well as the
                // definition count, because a profile nothing carries governs
                // nothing — the same distinction the Compliance tab's row makes
                // between "defined" and "in force".
                rows.push({
                    id:    'profiles',
                    state: policy.teamsClassified > 0 ? 'ok' : 'info',
                    glyph: policy.teamsClassified > 0 ? '✓' : '•',
                    label: this.t('teamhub', 'Policy profiles'),
                    value: policy.teamsClassified > 0
                        // TRANSLATORS: {total} policy profiles exist and {n} teams currently carry one
                        ? this.n('teamhub', '{total} defined, {n} team classified', '{total} defined, {n} teams classified', policy.teamsClassified, { n: policy.teamsClassified, total: policy.profiles })
                        : this.n('teamhub', '{n} profile, no team classified', '{n} profiles, no team classified', policy.profiles, { n: policy.profiles }),
                    hint:  policy.teamsClassified > 0
                        ? ''
                        // "Policy → Profiles" is the on-screen path verbatim:
                        // the tab is "Policy" and its second heading is
                        // "Profiles", not "Policy profiles". The row's own label
                        // is the longer form because on a checklist beside "Team
                        // templates" the bare word would not say profiles of what.
                        : this.t('teamhub', 'A profile sets a team’s privacy settings and which integrations it may use. Teams take one when they are created; nothing is applied until then. Review them under Policy → Profiles.'),
                })
            }

            // ── Allowed invite types ─────────────────────────────────────
            // Counted rather than listed: composing "Local users, Groups,
            // Teams" from translated fragments is the concatenation
            // SKILLS.md § Translation standards forbids — separators and
            // word order don't survive translation. A count carries the
            // useful signal (how open is invitation) without that.
            // 'Local users' is always on, so the count is never 0.
            //
            // v4.4.14 — description dropped. Justin flagged that toggling
            // federated off shrank the count but the hint still claimed
            // federation was on; keeping only the count avoids the hint
            // drifting from what the row's number says. Shipped default is
            // {user, group} = 2 types; anything else counts as changed.
            const inviteCount = 1
                + (this.inviteGroup     ? 1 : 0)
                + (this.inviteCircle    ? 1 : 0)
                + (this.inviteEmail     ? 1 : 0)
                + (this.inviteFederated ? 1 : 0)
            const invitesDefault = this.inviteGroup
                && !this.inviteCircle
                && !this.inviteEmail
                && !this.inviteFederated
            rows.push(preferenceRow(
                'invites',
                this.t('teamhub', 'Allowed invite types'),
                this.n('teamhub', '{n} type allowed', '{n} types allowed', inviteCount, { n: inviteCount }),
                !invitesDefault,
            ))

            // ── Licence ──────────────────────────────────────────────────
            // license.status is null until loadLicense() resolves (fired on
            // mount alongside load()), so the unresolved case gets its own
            // neutral row rather than briefly rendering as "unlicensed".
            const status = this.license.status
            if (!status) {
                rows.push({
                    id: 'license', state: 'info', glyph: '•',
                    label: this.t('teamhub', 'License'),
                    value: this.t('teamhub', 'Checking…'),
                    hint:  '',
                })
            } else {
                const level = status.enforcementLevel
                const warn  = level === 'grace' || level === 'soft-lock'
                rows.push({
                    id:    'license',
                    state: level === 'unlicensed' ? 'info' : (warn ? 'warn' : 'ok'),
                    glyph: level === 'unlicensed' ? '•' : (warn ? '⚠' : '✓'),
                    label: this.t('teamhub', 'License'),
                    value: this.licenseStatusLabel,
                    hint:  level === 'unlicensed'
                        ? this.t('teamhub', 'TeamHub runs without a license. Advanced projects and the What’s new feed stay unavailable until one is installed, on the License tab.')
                        : (warn ? this.t('teamhub', 'Renew on the License tab to keep Advanced features available.') : ''),
                })
            }

            // ── Optional modules ─────────────────────────────────────────
            // v4.4.15 — modules read positively: enabled is the "healthy"
            // state (feature is on, users can use it), disabled is the
            // suppressed state. Independent of the shipped default: an
            // admin looking at the checklist wants ✓ next to features
            // that are working, not next to ones they turned off.
            // One row per module rather than a single composed line —
            // "Presence {x} · Decisions {y}" out of translated on/off
            // fragments would be exactly the concatenation SKILLS.md
            // § Translation standards forbids.
            rows.push(preferenceRow(
                'presence',
                this.t('teamhub', 'Presence module'),
                this.form.presenceModuleEnabled ? this.t('teamhub', 'Enabled') : this.t('teamhub', 'Disabled'),
                this.form.presenceModuleEnabled,
            ))
            rows.push(preferenceRow(
                'decisions',
                this.t('teamhub', 'Decisions module'),
                this.form.decisionsModuleEnabled ? this.t('teamhub', 'Enabled') : this.t('teamhub', 'Disabled'),
                this.form.decisionsModuleEnabled,
            ))
            // v4.8.31 — derived from the licence, not a stored preference, so
            // the checklist reports it as a state rather than a setting an
            // administrator forgot to turn on.
            rows.push(preferenceRow(
                'file_reviews',
                this.t('teamhub', 'File reviews'),
                this.licenseActive ? this.t('teamhub', 'Available') : this.t('teamhub', 'Needs a license'),
                this.licenseActive,
            ))
            // v4.9.16 — licence and switch both; the value names whichever is
            // missing, the mark is ✓ only when the module actually exists.
            rows.push(preferenceRow(
                'openproject',
                this.t('teamhub', 'OpenProject module'),
                !this.licenseActive
                    ? this.t('teamhub', 'Needs a license')
                    : (this.form.openProjectModuleEnabled ? this.t('teamhub', 'Enabled') : this.t('teamhub', 'Disabled')),
                this.licenseActive && this.form.openProjectModuleEnabled,
            ))

            // ── App integrations (v4.4.13) ───────────────────────────────
            // v4.4.14 — ✓ when set / configured, • when empty (the shipped
            // default). Hints retired here too: the "Not configured" value
            // already tells an admin the state, and the setup checklist
            // exists to summarise rather than teach.
            rows.push(preferenceRow(
                'intravox',
                this.t('teamhub', 'IntraVox integration'),
                this.form.intravoxParentPath
                    ? this.form.intravoxParentPath
                    : this.t('teamhub', 'Not configured'),
                !!this.form.intravoxParentPath,
            ))
            rows.push(preferenceRow(
                'roomvox',
                this.t('teamhub', 'RoomVox integration'),
                this.form.roomvoxTokenConfigured
                    ? this.t('teamhub', 'Configured')
                    : this.t('teamhub', 'Not configured'),
                this.form.roomvoxTokenConfigured,
            ))

            // Registered third-party integrations. externalIntegrations is
            // populated by loadIntegrations(); an empty list before it
            // resolves reads as 0, which is also the correct steady state on
            // most instances, so no separate "checking" row is warranted.
            const integrationCount = this.externalIntegrations.length
            rows.push(preferenceRow(
                'integrations',
                this.t('teamhub', 'Registered integrations'),
                this.n('teamhub', '{n} app', '{n} apps', integrationCount, { n: integrationCount }),
                integrationCount > 0,
            ))

            return rows
        },

        licenseStatusLabel() {
            const s = this.license.status
            if (!s) return ''
            switch (s.enforcementLevel) {
            case 'none':       return s.isTrial ? this.t('teamhub', 'Trial active') : this.t('teamhub', 'Active')
            case 'grace':      return this.t('teamhub', 'Grace — renew soon')
            case 'soft-lock':  return this.t('teamhub', 'Expired — Advanced features locked')
            case 'unlicensed': return this.t('teamhub', 'No license installed')
            default:           return this.t('teamhub', 'Unknown')
            }
        },
        licenseKindLabel() {
            switch (this.license.status?.kind) {
            case 'connected': return this.t('teamhub', 'Connected (metered)')
            case 'airgapped': return this.t('teamhub', 'Air-gapped (no telemetry)')
            default:          return this.t('teamhub', 'Unknown')
            }
        },

        /**
         * mailto: link for "Request trial by email". Opens the admin's mail
         * client with subject + UUID pre-filled so Justin can issue the JWT
         * manually. Used when the instance can't reach the licensing server
         * (proxy, air-gap, corporate firewall).
         *
         * The body is intentionally minimal — the reply-to address on the
         * message they send IS the address that will receive the key, so
         * we don't ask for it explicitly.
         */
        requestTrialMailto() {
            const uuid = this.license.status?.instanceUuid || ''
            const subject = 'Request trial key'
            const body = `UUID: ${uuid}\n\n(This email was generated by TeamHub. Please reply from the address that should receive the license key.)`
            return `mailto:teamhub@tldr.host?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
        },

        /**
         * v4.10.0 — mailto: for "Request a quote" on the unlicensed License
         * tab. The subject is the one Justin's inbox sorts on ("Supporter
         * quote"). The body is the quote form: three lines the admin
         * completes (company name, country, contact e-mail — the same three
         * the 4.10.0 announcement asks for) and the two facts a quote is
         * priced on, filled in — the instance UUID the licence will be bound
         * to and the seats in use. English on purpose: we read it.
         */
        requestQuoteMailto() {
            const uuid  = this.license.status?.instanceUuid || ''
            const seats = this.license.status?.seatsUsed
            const lines = [
                'Company name: ',
                'Country: ',
                'Contact e-mail: ',
                '',
                `UUID: ${uuid}`,
            ]
            if (Number.isInteger(seats)) {
                lines.push(`Seats in use: ${seats}`)
            }
            lines.push('', '(This email was generated by TeamHub. Please complete the three lines above and send it from the address that should receive the quote.)')
            return `mailto:teamhub@tldr.host?subject=${encodeURIComponent('Supporter quote')}&body=${encodeURIComponent(lines.join('\n'))}`
        },

        /**
         * Only external (non-builtin) integrations.
         * Built-in NC apps (Talk, Files, Calendar, Deck) are seeded automatically
         * into the registry as is_builtin=true. They did NOT register via the
         * integration API and must not appear in this admin list.
         */
        externalIntegrations() {
            return this.integrations.filter(i => !i.is_builtin)
        },

        /**
         * Reporting → Telemetry contents (v4.3.1). Groups the flat `telemetry.preview`
         * payload from GET /admin/telemetry into human-readable sections so admins
         * can see exactly what would be sent — no toggle here, the on/off decision
         * is license-derived (backend TelemetryService::isEnabled + inline note above).
         *
         * `type` drives the template renderer:
         *   'scalar' — string/number/bool, single value cell
         *   'list'   — array of strings (or empty), rendered as an inline list
         *   'map'    — object of key→count, rendered as key/count rows
         *
         * Field order within each group matches the payload docblock in
         * TelemetryService.php so the two stay reviewable side-by-side.
         */
        telemetryContentGroups() {
            const p = this.telemetry?.preview || {}
            const t = (s) => this.t('teamhub', s)
            return [
                {
                    title: t('Instance identity'),
                    fields: [
                        { key: 'uuid',        label: t('Anonymous UUID'),     type: 'scalar', value: p.uuid },
                        { key: 'app_version', label: t('TeamHub version'),    type: 'scalar', value: p.app_version },
                        { key: 'nc_version',  label: t('Nextcloud version'),  type: 'scalar', value: p.nc_version },
                    ],
                },
                {
                    title: t('Teams & members'),
                    fields: [
                        { key: 'team_count',          label: t('Teams'),                          type: 'scalar', value: p.team_count },
                        { key: 'user_count',          label: t('Instance users'),                 type: 'scalar', value: p.user_count },
                        { key: 'unique_team_members', label: t('Unique team members (seats)'),    type: 'scalar', value: p.unique_team_members },
                        { key: 'member_total',        label: t('Membership row total'),           type: 'scalar', value: p.member_total },
                        { key: 'message_count',       label: t('Messages posted (all teams)'),    type: 'scalar', value: p.message_count },
                    ],
                },
                {
                    title: t('Modules & feature adoption'),
                    fields: [
                        { key: 'presence_module',              label: t('Presence module enabled'),         type: 'scalar', value: p.presence_module },
                        { key: 'decisions_module',             label: t('Decisions module enabled'),        type: 'scalar', value: p.decisions_module },
                        { key: 'teams_with_decisions_enabled', label: t('Teams with Decisions enabled'),    type: 'scalar', value: p.teams_with_decisions_enabled },
                        { key: 'decisions_count',              label: t('Decisions recorded'),              type: 'scalar', value: p.decisions_count },
                        { key: 'decisions_by_status',          label: t('Decisions by status'),             type: 'map',    value: p.decisions_by_status },
                        { key: 'decision_categories_count',    label: t('Decision categories defined'),     type: 'scalar', value: p.decision_categories_count },
                        { key: 'suggest_wizard_uses',          label: t('Meeting-suggestion wizard runs'),  type: 'scalar', value: p.suggest_wizard_uses },
                    ],
                },
                {
                    title: t('Integrations'),
                    fields: [
                        { key: 'integrations',         label: t('Registered third-party integrations'), type: 'list', value: p.integrations },
                        { key: 'builtin_integrations', label: t('Built-in NC apps in use (per team)'),  type: 'map',  value: p.builtin_integrations },
                    ],
                },
                {
                    title: t('Custom-link domains'),
                    fields: [
                        // Only bare hostnames — no paths, ports, query strings, or IPs. See TelemetryService::getLinkDomains.
                        { key: 'link_domains', label: t('Domains linked from message bodies'), type: 'map', value: p.link_domains },
                    ],
                },
            ]
        },

        teamsTotalPages() {
            return Math.max(1, Math.ceil(this.teamsTotal / this.teamsPerPage))
        },

        /**
         * v4.6.13 — earliest date any expiry picker on this page will accept.
         * The server rejects anything at or before now; letting the browser
         * refuse it first saves a round trip to be told so.
         */
        tomorrowIso() {
            return shiftToday({ days: 1 })
        },

        /**
         * v4.6.17 — the request the open dialog is deciding, or null when the
         * team's row says one is pending but the loaded list does not have it
         * (someone else decided it, or the list has not landed yet).
         *
         * Derived rather than copied into `expiryReqTeam`: approving or denying
         * rewrites `expiryRequests`, and a snapshot taken at open time would let
         * the dialog keep showing a decision that has already been made.
         */
        expiryReqActive() {
            return this.expiryReqTeam
                ? this.requestForTeam(this.expiryReqTeam.id)
                : null
        },

        auditEventsTotalPages() {
            return Math.max(1, Math.ceil(this.auditEventsTotal / this.auditEventsPerPage))
        },

        // ── v4.2.0 Compliance — code integrity ─────────────────────
        integrityPillLevel() {
            const s = this.integrity.report?.status
            if (s === 'compliant') return 'ok'
            if (s === 'not_compliant') return 'err'
            return 'unknown'   // manifest_missing OR no report loaded yet
        },
        integrityStatusLabel() {
            const s = this.integrity.report?.status
            switch (s) {
            case 'compliant':        return this.t('teamhub', 'Compliant')
            case 'not_compliant':    return this.t('teamhub', 'Not compliant')
            case 'manifest_missing': return this.t('teamhub', 'No manifest')
            default:                 return this.t('teamhub', 'Unknown')
            }
        },

        canSaveRetention() {
            const n = parseInt(this.auditRetentionInput, 10)
            if (isNaN(n)) return false
            if (n < this.auditRetention.min || n > this.auditRetention.max) return false
            return n !== this.auditRetention.retention_days
        },

        // ── Audit tab — Find teams for a user ────────────────────────────
        audit_removableRows() {
            return this.audit_teamRows.filter(r => r.removable)
        },
        audit_anyRemovable() {
            return this.audit_removableRows.length > 0
        },
        audit_allRemovableSelected() {
            return this.audit_anyRemovable
                && this.audit_removableRows.every(r => this.audit_selectedTeamIds.includes(r.teamId))
        },
        audit_someRemovableSelected() {
            return this.audit_removableRows.some(r => this.audit_selectedTeamIds.includes(r.teamId))
        },
        auditRemoveDialogButtons() {
            return [
                {
                    label: this.t('teamhub', 'Cancel'),
                    type: 'secondary',
                    callback: () => { this.audit_removeConfirmOpen = false },
                },
                {
                    label: this.t('teamhub', 'Remove'),
                    type: 'error',
                    callback: () => { this.executeAuditRemove() },
                },
            ]
        },
    },
    watch: {
        /**
         * v4.0.3 — auto-save archive settings on any change. Gated on
         * archiveSettingsLoaded so the initial hydration from the server
         * (which mutates archiveSettings) doesn't immediately POST the same
         * values back. Debounced to coalesce burst changes (e.g. typing in
         * the archive-location field character by character).
         */
        archiveSettings: {
            deep: true,
            handler() {
                if (!this.archiveSettingsLoaded) return
                clearTimeout(this._archiveAutoSaveTimer)
                this._archiveAutoSaveTimer = setTimeout(() => {
                    this.saveArchiveSettings()
                }, 400)
            },
        },
        activeTab(tab) {
            if (tab === 'integrations' && this.integrations.length === 0 && !this.integrationsLoading) {
                this.loadIntegrations()
            }
            if (tab === 'statistics' && !this.telemetryLoading && !this.telemetry.preview.uuid) {
                this.loadTelemetry()
            }
            if (tab === 'maintenance' && !this.teamsLoading && this.teamsPage.length === 0 && !this.teamsError) {
                this.loadTeams()
            }
            if (tab === 'maintenance' && !this.expiryReqLoading && this.expiryRequests.length === 0 && !this.expiryReqError) {
                // Separate condition from the teams load above: the request
                // queue is its own section and must still populate on an
                // instance whose teams grid is empty or errored.
                this.loadExpiryRequests()
            }
            if (tab === 'audit') {
                if (!this.auditRetentionLoaded) {
                    this.loadAuditRetention()
                }
                if (!this.auditTeamsLoading && this.auditTeams.length === 0 && !this.auditTeamsError) {
                    this.loadAuditTeams()
                }
                if (!this.integrity.loaded && !this.integrity.loading) {
                    this.loadIntegrity()
                }
                if (!this.complianceSummary.loaded && !this.complianceSummary.loading) {
                    this.loadComplianceSummary()
                }
            }
            if (tab === 'archive') {
                if (!this.archiveSettingsLoaded) {
                    this.loadArchiveSettings()
                }
                if (!this.pendingDelsLoading && this.pendingDels.length === 0 && !this.pendingDelsError) {
                    this.loadPendingDeletions()
                }
            }
            // v3.100.0 — refetch license status when the user opens the tab.
            if (tab === 'license') {
                this.loadLicense()
            }
        },
    },
    mounted() {
        this.load()
        this.loadLicense()
        // v4.8.15 — the setup checklist is on the tab that opens first, so its
        // Track F rows cannot wait for the Policy tab to be visited.
        this.loadPolicySummary()
    },
    beforeUnmount() {
        // v4.4.13 — a pending IntraVox autosave must not fire after the
        // settings page is torn down.
        clearTimeout(this._intravoxSaveTimer)
        // v4.6.2 — same for the two debounced autosaves added with the
        // removal of the Save button.
        clearTimeout(this._wizardSaveTimer)
        clearTimeout(this._auditRetentionSaveTimer)
    },
    methods: {
        t(app, str, vars) {
            if (window.t) return window.t(app, str, vars)
            if (vars) return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
            return str
        },
        n(app, singular, plural, count, vars) {
            if (window.n) return window.n(app, singular, plural, count, vars)
            const str = count === 1 ? singular : plural
            if (vars) return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
            return str
        },

        async load() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/settings'))
                this.form.wizardDescription    = data.wizardDescription     || ''
                this.form.pinMinLevel          = data.pinMinLevel            || 'moderator'
                this.form.intravoxParentPath   = data.intravoxParentPath     || 'en/teamhub'
                this.form.presenceModuleEnabled = !!data.presenceModuleEnabled
                this.form.decisionsModuleEnabled = !!data.decisionsModuleEnabled
                this.form.openProjectModuleEnabled = !!data.openProjectModuleEnabled
                this.form.roomvoxTokenConfigured = !!data.roomvoxTokenConfigured
                this.form.onboardingChecklistDismissed = !!data.onboardingChecklistDismissed
                // v4.6.13 — expiration settings. `expiryDefaultDate` is the
                // six-months-out date the create wizard's picker opens on;
                // reused here to prefill the inline editor for a team that has
                // no date yet, so both places suggest the same thing.
                this.form.expiryWarningDays  = Number(data.expiryWarningDays) || 7
                this.form.expiryDefaultDate  = data.expiryDefaultDate || ''
                this.expiryWarningDaysMin    = Number(data.expiryWarningDaysMin) || 1
                this.expiryWarningDaysMax    = Number(data.expiryWarningDaysMax) || 365
                // Reset the token write field on each load — never echo
                // back a stored token.
                this.form.roomvoxApiToken = ''
                // If we're on the presence tab but module is now off, switch away.
                if (!this.form.presenceModuleEnabled && this.activeTab === 'presence') {
                    this.activeTab = 'integrations'
                }

                const types = (data.inviteTypes || 'user,group').split(',').map(s => s.trim())
                this.inviteGroup     = types.includes('group')
                this.inviteCircle    = types.includes('circle')
                this.inviteEmail     = types.includes('email')
                this.inviteFederated = types.includes('federated')

                this.selectedGroups = Array.isArray(data.createTeamGroups) ? data.createTeamGroups : []

                if (data.groupFoldersDelegation && typeof data.groupFoldersDelegation === 'object') {
                    this.gfDelegation = {
                        groupFoldersInstalled:       !!data.groupFoldersDelegation.groupFoldersInstalled,
                        teamCreatorGroupsConfigured: !!data.groupFoldersDelegation.teamCreatorGroupsConfigured,
                    }
                }
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to load settings')
            } finally {
                this.loading = false
            }
        },

        // ── Integrations tab ──────────────────────────────────────────────

        async loadIntegrations() {
            this.integrationsLoading = true
            this.integrationsError = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/ext/integrations'))
                this.integrations = Array.isArray(data) ? data : []
            } catch (e) {
                const msg = e?.response?.data?.error || e.message || 'unknown error'
                this.integrationsError = this.t('teamhub', 'Failed to load integrations: {error}', { error: msg })
            } finally {
                this.integrationsLoading = false
            }
        },

        /**
         * NC app icon URL — /apps/{app_id}/img/app.svg
         * Mirrors TeamView.appIconUrl() and IntegrationWidget.appIconUrl().
         */
        appIconUrl(appId) {
            return generateUrl(`/apps/${appId}/img/app.svg`)
        },

        /**
         * Fallback: svg → png → hide.
         * We store the app_id on the img via data attribute so we can track
         * which fallback stage we are in without extra component state.
         */
        onAppIconError(event, item) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = generateUrl(`/apps/${item.app_id}/img/app.png`)
            } else {
                // Both svg and png failed — hide the img entirely
                img.style.display = 'none'
            }
        },

        // ── Group picker ──────────────────────────────────────────────────

        onGroupSearch() {
            clearTimeout(this.groupSearchTimer)
            this.groupResults = []
            if (this.groupQuery.length < 1) {
                this.groupSearching = false
                return
            }
            this.groupSearching = true
            this.groupSearchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/admin/groups/search'),
                        { params: { q: this.groupQuery } }
                    )
                    const selectedIds = new Set(this.selectedGroups.map(g => g.id))
                    this.groupResults = (Array.isArray(data) ? data : [])
                        .filter(g => !selectedIds.has(g.id))
                } catch {
                    this.groupResults = []
                } finally {
                    this.groupSearching = false
                }
            }, 250)
        },

        addGroup(group) {
            if (!this.selectedGroups.find(g => g.id === group.id)) {
                this.selectedGroups.push(group)
                this.save()
            }
            this.groupQuery   = ''
            this.groupResults = []
        },

        removeGroup(group) {
            this.selectedGroups = this.selectedGroups.filter(g => g.id !== group.id)
            this.save()
        },

        // ── Save ─────────────────────────────────────────────────────────

        /**
         * v4.4.4 — persist the setup-checklist dismissal on its own.
         *
         * Deliberately not routed through save(): that posts every field on
         * the tab, so a stray dismiss click would re-save the wizard text,
         * creator groups and module toggles as a side effect. saveAdminSettings
         * guards each key with isset()/array_key_exists(), so a single-key
         * POST leaves everything else untouched.
         *
         * Applied optimistically — the flag is cosmetic, and blocking the
         * collapse on a round-trip makes the button feel broken. Reverted if
         * the write fails.
         */
        async setChecklistDismissed(dismissed) {
            const previous = this.form.onboardingChecklistDismissed
            this.form.onboardingChecklistDismissed = dismissed
            try {
                const params = new URLSearchParams()
                params.set('onboardingChecklistDismissed', dismissed ? '1' : '0')
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/settings'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
            } catch (e) {
                this.form.onboardingChecklistDismissed = previous
                this.saveError = this.t('teamhub', 'Failed to save settings')
            }
        },

        /**
         * v4.4.13 — Integrations-tab autosave for the IntraVox parent path.
         * Debounced so a save fires once the admin stops typing rather than
         * per keystroke. Safe to repeat: the value is not a secret and the
         * write is idempotent.
         */
        onIntravoxPathInput() {
            clearTimeout(this._intravoxSaveTimer)
            this._intravoxSaveTimer = setTimeout(() => { this.save() }, 1200)
        },

        /**
         * v4.6.2 — Team-creation-tab autosave for the wizard description.
         * Same debounce and rationale as the IntraVox path above: free text,
         * not a secret, idempotent write.
         */
        onWizardDescriptionInput() {
            clearTimeout(this._wizardSaveTimer)
            this._wizardSaveTimer = setTimeout(() => { this.save() }, 1200)
        },

        /**
         * v4.6.13 — saves on `change` (blur or stepper click) rather than on
         * every keystroke, unlike the debounced text fields above. A number
         * field passes through nonsense on the way to a good value — typing
         * "30" produces "3" first — and the server clamps, so a per-keystroke
         * save would store 3 and then correct it, with the field visibly
         * jumping. `load()` after the save re-renders whatever was kept.
         */
        onExpiryWarningDaysInput() {
            this.save()
        },

        /**
         * v4.6.2 — Compliance-tab autosave for the audit retention period.
         *
         * Guarded by canSaveRetention rather than saved unconditionally: that
         * computed rejects a value which does not parse, falls outside
         * min–max, or already equals what is stored, so a partially typed
         * number cannot reach the endpoint and an unchanged one cannot
         * generate a pointless write plus a "Retention saved" toast.
         */
        onAuditRetentionInput() {
            clearTimeout(this._auditRetentionSaveTimer)
            this._auditRetentionSaveTimer = setTimeout(() => {
                if (this.canSaveRetention) {
                    this.saveAuditRetention()
                }
            }, 1200)
        },

        /**
         * v4.4.13 — Integrations-tab autosave for the RoomVox token.
         *
         * Deliberately on blur rather than debounced-while-typing: a debounce
         * would POST partial tokens as the admin pastes or types, scattering
         * truncated secrets through the server request log. Blur is the point
         * at which the field's value is intentional.
         *
         * No-op on an empty buffer — empty means "keep the stored token" per
         * the backend contract, so there is nothing to write.
         */
        onRoomvoxTokenBlur() {
            if (this.form.roomvoxApiToken === '') {
                return
            }
            this.save()
        },

        async save() {
            this.saving    = true
            this.saved     = false
            this.saveError = null

            const types = ['user']
            if (this.inviteGroup)     types.push('group')
            if (this.inviteCircle)    types.push('circle')
            if (this.inviteEmail)     types.push('email')
            if (this.inviteFederated) types.push('federated')

            const groupIds = JSON.stringify(this.selectedGroups.map(g => g.id))

            const params = new URLSearchParams()
            params.set('wizardDescription',    this.form.wizardDescription)
            params.set('intravoxParentPath',   this.form.intravoxParentPath)
            params.set('createTeamGroup',      groupIds)
            params.set('pinMinLevel',          this.form.pinMinLevel)
            params.set('inviteTypes',          types.join(','))
            params.set('presenceModuleEnabled',  this.form.presenceModuleEnabled ? '1' : '0')
            params.set('decisionsModuleEnabled', this.form.decisionsModuleEnabled ? '1' : '0')
            params.set('openProjectModuleEnabled', this.form.openProjectModuleEnabled ? '1' : '0')
            params.set('expiryWarningDays',      String(this.form.expiryWarningDays ?? 7))
            // Only send the token if the user actually typed something —
            // an empty buffer means "keep the stored value unchanged" per
            // backend contract (see TeamService::saveAdminSettings).
            if (this.form.roomvoxApiToken !== '') {
                params.set('roomvoxApiToken', this.form.roomvoxApiToken)
            }

            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/settings'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.saved = true
                setTimeout(() => { this.saved = false }, 3000)
                // Refresh state so the RoomVox "configured" flag and the
                // empty write-buffer reflect what's now stored.
                this.load()
            } catch (e) {
                // Surface backend validation messages (e.g. malformed token)
                // when present, falling back to a generic message.
                const remote = e?.response?.data?.error
                this.saveError = remote
                    ? this.t('teamhub', 'Failed to save settings: {error}', { error: remote })
                    : this.t('teamhub', 'Failed to save settings')
            } finally {
                this.saving = false
            }
        },

        // ------------------------------------------------------------------
        // Statistics / telemetry
        // ------------------------------------------------------------------

        async loadTelemetry() {
            this.telemetryLoading = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/telemetry'))
                this.telemetry = data
            } catch (e) {
            } finally {
                this.telemetryLoading = false
            }
        },

        /**
         * Render a scalar payload value for the Telemetry contents overview.
         * null/undefined → em-dash; booleans → localized Yes/No; strings /
         * numbers → toString. Objects and arrays should NEVER reach this
         * function — telemetryContentGroups routes them to the 'list' /
         * 'map' branches. Kept intentionally narrow so future payload
         * additions that don't fit force a review of the group definition.
         */
        formatTelemetryScalar(value) {
            if (value === null || value === undefined) return '—'
            if (typeof value === 'boolean') {
                return value ? this.t('teamhub', 'Yes') : this.t('teamhub', 'No')
            }
            return String(value)
        },

        async toggleTelemetry(enabled) {
            this.telemetrySaving = true
            try {
                const params = new URLSearchParams()
                params.set('enabled', enabled ? '1' : '0')
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/admin/telemetry'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.telemetry.enabled = enabled
            } catch (e) {
            } finally {
                this.telemetrySaving = false
            }
        },

        // ------------------------------------------------------------------
        // Maintenance — teams grid
        // ------------------------------------------------------------------

        /**
         * Load a page of teams from the server.
         * Called on: tab activate, page change, search, perPage change, orphan toggle, refresh.
         */
        async loadTeams() {
            this.teamsLoading = true
            this.teamsError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/teams'),
                    {
                        params: {
                            page:         this.teamsPage_current,
                            per_page:     this.teamsPerPage,
                            search:       this.teamsSearch,
                            orphans_only: this.teamsOrphansOnly ? 1 : 0,
                        },
                    }
                )
                this.teamsPage  = Array.isArray(data.teams) ? data.teams : []
                this.teamsTotal = typeof data.total === 'number' ? data.total : 0
            } catch (e) {
                this.teamsError = this.t('teamhub', 'Failed to load teams')
            } finally {
                this.teamsLoading = false
            }
        },

        /** Reload from page 1 — used after filter/perPage changes. */
        reloadTeams() {
            this.teamsPage_current = 1
            this.loadTeams()
        },

        /** Debounced search input handler. */
        onTeamsSearchInput() {
            clearTimeout(this.teamsSearchTimer)
            this.teamsSearchTimer = setTimeout(() => {
                this.reloadTeams()
            }, 300)
        },

        /** Orphans-only toggle handler. */
        onOrphansToggle(val) {
            this.teamsOrphansOnly = val
            this.reloadTeams()
        },

        /** Navigate to a specific page. */
        goToPage(page) {
            const clamped = Math.max(1, Math.min(page, this.teamsTotalPages))
            if (clamped === this.teamsPage_current) return
            this.teamsPage_current = clamped
            this.loadTeams()
        },

        // ------------------------------------------------------------------
        // Team expiration dates (v4.6.13)
        // ------------------------------------------------------------------

        /** Label for the Expires cell in its read state. */
        expiryLabel(team) {
            if (!team.expiry) {
                // TRANSLATORS: shown in the admin All teams table for a team
                // that has no expiration date. It is a button — clicking it
                // sets one — so this reads as an invitation, not a value.
                return this.t('teamhub', 'Set date')
            }
            if (team.expiry.expired) {
                return this.t('teamhub', 'Expired {date}', { date: team.expiry.expiresOn })
            }
            return team.expiry.expiresOn
        },

        expiryTitle(team) {
            if (!team.expiry) {
                return this.t('teamhub', 'No expiration date. Click to set one.')
            }
            if (team.expiry.expired) {
                return this.t('teamhub', 'This date has passed. The team still works normally — nothing is deleted automatically. Click to set a new date.')
            }
            return this.n('teamhub',
                'Expires in {n} day. Click to change or clear the date.',
                'Expires in {n} days. Click to change or clear the date.',
                team.expiry.daysRemaining,
                { n: team.expiry.daysRemaining })
        },

        /**
         * Tone class for the Expires cell. Colour is never the only signal —
         * the button also carries an alert icon and the word "Expired"
         * (WCAG 1.4.1).
         */
        expiryToneClass(team) {
            if (!team.expiry) return 'maint-expiry-btn--none'
            if (team.expiry.expired) return 'maint-expiry-btn--expired'
            if (team.expiry.warning) return 'maint-expiry-btn--warning'
            return ''
        },

        startExpiryEdit(team) {
            this.expiryEditTeamId = team.id
            this.expiryEditValue = team.expiry ? team.expiry.expiresOn : (this.form.expiryDefaultDate || '')
        },

        cancelExpiryEdit() {
            this.expiryEditTeamId = null
            this.expiryEditValue = ''
        },

        async saveTeamExpiry(team) {
            if (!this.expiryEditValue) {
                showError(this.t('teamhub', 'Pick a date, or use Clear to remove the expiration date.'))
                return
            }
            await this.writeTeamExpiry(team, this.expiryEditValue)
        },

        async clearTeamExpiry(team) {
            await this.writeTeamExpiry(team, '')
        },

        /**
         * One writer for set / extend / clear — the endpoint treats an empty
         * date as "remove", so the three verbs are the same call and the UI
         * does not need three error paths.
         */
        async writeTeamExpiry(team, expiresOn) {
            this.expirySaving = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/teams/${team.id}/expiry`),
                    { expiresOn }
                )
                // Patch the row in place rather than reloading the page: the
                // admin may be part-way through another row's assign-owner form.
                team.expiry = data.expiry || null
                team.expiry_request_pending = false
                showSuccess(expiresOn
                    ? this.t('teamhub', 'Expiration date saved.')
                    : this.t('teamhub', 'Expiration date removed.'))
                this.cancelExpiryEdit()
                // A write here can supersede an open request, so the queue
                // below may have changed.
                this.loadExpiryRequests()
            } catch (e) {
                const remote = e?.response?.data?.error
                showError(remote
                    ? this.t('teamhub', 'Failed to save the expiration date: {error}', { error: remote })
                    : this.t('teamhub', 'Failed to save the expiration date'))
            } finally {
                this.expirySaving = false
            }
        },

        async loadExpiryRequests() {
            this.expiryReqLoading = true
            this.expiryReqError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/expiry-requests')
                )
                this.expiryRequests = Array.isArray(data.requests) ? data.requests : []
                // Prefill each row's date with what was asked for, so approving
                // as requested is one click.
                const grants = {}
                for (const r of this.expiryRequests) {
                    grants[r.id] = r.proposedOn
                }
                this.expiryReqGrant = grants
            } catch (e) {
                this.expiryReqError = this.t('teamhub', 'Failed to load extension requests')
            } finally {
                this.expiryReqLoading = false
            }
        },

        /**
         * The pending request for a team, from the list already loaded for the
         * banner (v4.6.16). Matching client-side keeps the teams endpoint as it
         * is: the row already carries `expiry_request_pending`, and the request
         * behind it is in a list this tab loads anyway.
         */
        requestForTeam(teamId) {
            return this.expiryRequests.find(r => r.teamId === teamId) || null
        },

        /** Open the decision dialog for one team's pending request. */
        openExpiryRequest(team) {
            this.expiryReqTeam = team
            const req = this.requestForTeam(team.id)
            // Prefill with what was asked for, so approving as requested is one
            // click and granting something shorter is an edit rather than an
            // empty field to work out from scratch.
            if (req && !this.expiryReqGrant[req.id]) {
                this.expiryReqGrant[req.id] = req.proposedOn
            }
        },

        /**
         * Close it without deciding. Deliberately leaves `expiryReqGrant` and
         * `expiryReqNote` alone: an administrator who closes the dialog to go
         * and check something should find their edits still there on reopening.
         */
        closeExpiryRequest() {
            this.expiryReqTeam = null
        },

        /**
         * Search the table down to the team a banner entry names, then open its
         * decision popout once the row is on screen. The table is server-paged,
         * so the row may well not be in the current page.
         */
        async jumpToTeamRequest(req) {
            this.teamsSearch = req.teamName
            this.teamsPage_current = 1
            await this.loadTeams()
            const row = this.teamsPage.find(t => t.id === req.teamId)
            if (row) {
                this.openExpiryRequest(row)
            }
        },

        async approveExpiryRequest(req) {
            this.expiryReqBusy = req.id
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/expiry-requests/${req.id}/approve`),
                    {
                        grantedOn: this.expiryReqGrant[req.id] || '',
                        note:      this.expiryReqNote[req.id] || '',
                    }
                )
                showSuccess(this.t('teamhub', 'Extension approved. {team} now expires on {date}.', {
                    team: req.teamName,
                    date: this.expiryReqGrant[req.id] || req.proposedOn,
                }))
                this.expiryReqTeam = null
                await this.loadExpiryRequests()
                this.loadTeams()
            } catch (e) {
                const remote = e?.response?.data?.error
                showError(remote
                    ? this.t('teamhub', 'Failed to approve the request: {error}', { error: remote })
                    : this.t('teamhub', 'Failed to approve the request'))
            } finally {
                this.expiryReqBusy = null
            }
        },

        async denyExpiryRequest(req) {
            this.expiryReqBusy = req.id
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/expiry-requests/${req.id}/deny`),
                    { note: this.expiryReqNote[req.id] || '' }
                )
                showSuccess(this.t('teamhub', 'Extension denied. {name} has been notified.', {
                    name: req.requestedName,
                }))
                this.expiryReqTeam = null
                await this.loadExpiryRequests()
                this.loadTeams()
            } catch (e) {
                const remote = e?.response?.data?.error
                showError(remote
                    ? this.t('teamhub', 'Failed to deny the request: {error}', { error: remote })
                    : this.t('teamhub', 'Failed to deny the request'))
            } finally {
                this.expiryReqBusy = null
            }
        },

        /**
         * Format a MySQL datetime string (e.g. "2024-03-15 14:22:00") or ISO
         * string as a localised short date. Also tolerates a numeric Unix
         * timestamp (seconds) as a fallback. Returns '—' when value is empty.
         */
        formatDate(value) {
            if (!value && value !== 0) return '—'
            try {
                let d
                if (typeof value === 'number' || /^\d+$/.test(String(value))) {
                    // Unix timestamp in seconds
                    d = new Date(Number(value) * 1000)
                } else {
                    // "YYYY-MM-DD HH:MM:SS" → make it ISO-parseable
                    d = new Date(String(value).replace(' ', 'T'))
                }
                if (isNaN(d.getTime())) return '—'
                return fmtDate(d, { year: 'numeric', month: 'short', day: 'numeric' })
            } catch (e) {
                return '—'
            }
        },

        // ── Delete team ───────────────────────────────────────────────────

        confirmDeleteTeamRow(team) {
            this.confirmDeleteTeam   = team
            this.confirmDeleteDialog = true
        },

        cancelDeleteOrphan() {
            this.confirmDeleteDialog = false
            this.confirmDeleteTeam   = null
        },

        async executeDeleteOrphan() {
            if (!this.confirmDeleteTeam) return
            const team = this.confirmDeleteTeam
            this.deletingTeam = team.id
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/orphaned-teams/${team.id}`)
                )
                this.cancelDeleteOrphan()
                showSuccess(this.t('teamhub', 'Team deleted successfully'))
                // Reload current page — it may now have fewer items
                await this.loadTeams()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Failed to delete team: {error}', { error: msg }) : this.t('teamhub', 'Failed to delete team'))
            } finally {
                this.deletingTeam = null
            }
        },

        // ── Unlink OpenProject project (v4.9.4) ───────────────────────────

        confirmUnlinkOpenProject(team) {
            this.confirmUnlinkOpenProjectTeam = team
        },

        cancelUnlinkOpenProject() {
            this.confirmUnlinkOpenProjectTeam = null
        },

        async executeUnlinkOpenProject() {
            const team = this.confirmUnlinkOpenProjectTeam
            if (!team) return
            this.unlinkingOpenProjectTeamId = team.id
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/teams/${team.id}/openproject-link`)
                )
                this.cancelUnlinkOpenProject()
                showSuccess(this.t('teamhub', 'OpenProject project unlinked from {name}', { name: team.name }))
                // The row's link and its Unlink button go with the reload.
                await this.loadTeams()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? this.t('teamhub', 'Failed to unlink the OpenProject project: {error}', { error: msg })
                    : this.t('teamhub', 'Failed to unlink the OpenProject project'))
            } finally {
                this.unlinkingOpenProjectTeamId = null
            }
        },

        // ── Reset team config (clears corrupted bitmask) ──────────────────

        confirmResetTeamConfig(team) {
            this.confirmResetConfigTeam   = team
            this.confirmResetConfigDialog = true
        },

        cancelResetTeamConfig() {
            this.confirmResetConfigDialog = false
            this.confirmResetConfigTeam   = null
        },

        async executeResetTeamConfig() {
            if (!this.confirmResetConfigTeam) return
            const team = this.confirmResetConfigTeam
            this.resettingConfigTeamId = team.id
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/reset-team-config/${team.id}`)
                )
                this.cancelResetTeamConfig()
                showSuccess(this.t(
                    'teamhub',
                    'Team config reset: {oldConfig} → {newConfig}',
                    { oldConfig: data?.oldConfig ?? '?', newConfig: data?.newConfig ?? '?' },
                ))
                // Reload the integrity scan if it was previously run
                if (this.configCheck) {
                    await this.runConfigCheck()
                }
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? this.t('teamhub', 'Failed to reset team config: {error}', { error: msg })
                    : this.t('teamhub', 'Failed to reset team config'))
            } finally {
                this.resettingConfigTeamId = null
            }
        },

        async runConfigCheck() {
            this.configCheckLoading = true
            this.configCheckError   = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/config-check')
                )
                this.configCheck = data
            } catch (e) {
                this.configCheckError = e?.response?.data?.error || this.t('teamhub', 'Config integrity check failed')
                this.configCheck = null
            } finally {
                this.configCheckLoading = false
            }
        },

        async repairConfigIssue(issue) {
            this.resettingConfigTeamId = issue.id
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/reset-team-config/${issue.id}`)
                )
                showSuccess(this.t('teamhub', 'Team config repaired'))
                await this.runConfigCheck()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? this.t('teamhub', 'Failed to repair team config: {error}', { error: msg })
                    : this.t('teamhub', 'Failed to repair team config'))
            } finally {
                this.resettingConfigTeamId = null
            }
        },

        // ── Assign owner ──────────────────────────────────────────────────

        startAssignOwner(team) {
            this.assignTeamId  = team.id
            this.ownerQuery    = ''
            this.ownerResults  = []
            this.ownerError    = ''
            this.ownerSearched = false
        },

        cancelAssign() {
            this.assignTeamId  = null
            this.ownerQuery    = ''
            this.ownerResults  = []
            this.ownerError    = ''
            this.ownerSearched = false
        },

        onOwnerSearch() {
            clearTimeout(this.ownerSearchTimer)
            this.ownerError = ''
            if (this.ownerQuery.length < 1) {
                this.ownerResults  = []
                this.ownerSearched = false
                return
            }
            this.ownerSearching = true
            this.ownerSearchTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.get(
                        generateUrl('/apps/teamhub/api/v1/admin/users/search'),
                        { params: { q: this.ownerQuery } }
                    )
                    this.ownerResults = Array.isArray(data) ? data : []
                } catch (e) {
                    // v4.8.35 — the reason, not silence. This catch used to
                    // clear the list and say nothing, so a 403, a 404 and a
                    // genuine no-match were one indistinguishable blank. The
                    // status code is worth carrying: it is the difference
                    // between "the route is not there" and "you may not".
                    this.ownerResults = []
                    const status = e?.response?.status
                    const detail = e?.response?.data?.error || e?.message || ''
                    this.ownerError = this.t('teamhub', 'Search failed: {error}', {
                        error: status ? status + ' ' + detail : detail,
                    })
                } finally {
                    this.ownerSearching = false
                    this.ownerSearched  = true
                }
            }, 300)
        },

        async confirmAssignOwner(team, user) {
            this.ownerResults   = []
            this.assigningOwner = true
            try {
                const params = new URLSearchParams()
                params.set('userId', user.uid)
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/orphaned-teams/${team.id}/assign-owner`),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.cancelAssign()
                showSuccess(this.t('teamhub', 'Owner assigned successfully'))
                // Reload so the owner column reflects the change
                await this.loadTeams()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Failed to assign owner: {error}', { error: msg }) : this.t('teamhub', 'Failed to assign owner'))
            } finally {
                this.assigningOwner = false
            }
        },

        // ------------------------------------------------------------------
        // Audit tab — Find teams for a user
        // ------------------------------------------------------------------

        auditRoleLabel(role) {
            switch (role) {
                // TRANSLATORS: a team role — the user who owns/controls the team
                case 'Owner':     return this.t('teamhub', 'Owner')
                // TRANSLATORS: a team role with administrator privileges within the team
                case 'Admin':     return this.t('teamhub', 'Admin')
                // TRANSLATORS: a team role that can moderate but not administer
                case 'Moderator': return this.t('teamhub', 'Moderator')
                // TRANSLATORS: a regular team member with no special privileges
                case 'Member':    return this.t('teamhub', 'Member')
                default:          return role
            }
        },

        onAuditUserQueryInput() {
            // v-model has already updated audit_userQuery; just (re)schedule the
            // debounced search. Mirrors maintenance-tab onOwnerSearch.
            clearTimeout(this.audit_userSearchTimer)
            if (!this.audit_userQuery || this.audit_userQuery.length < 1) {
                this.audit_userResults = []
                this.audit_userSearching = false
                return
            }
            this.audit_userSearching = true
            this.audit_userSearchTimer = setTimeout(() => this.fetchAuditUserResults(), 300)
        },

        // Enter-key flush: cancel any pending debounce and search immediately.
        runAuditUserSearchNow() {
            clearTimeout(this.audit_userSearchTimer)
            if (!this.audit_userQuery || this.audit_userQuery.length < 1) {
                return
            }
            this.audit_userSearching = true
            this.fetchAuditUserResults()
        },

        async fetchAuditUserResults() {
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/users/search'),
                    { params: { q: this.audit_userQuery } },
                )
                this.audit_userResults = Array.isArray(data) ? data : []
            } catch (e) {
                this.audit_userResults = []
            } finally {
                this.audit_userSearching = false
            }
        },

        async selectAuditUser(user) {
            this.audit_selectedUser     = { uid: user.uid, displayName: user.displayName }
            this.audit_userQuery        = ''
            this.audit_userResults      = []
            this.audit_selectedTeamIds  = []
            this.audit_teamRows         = []
            this.audit_teamsError       = null
            await this.loadAuditTeamsForUser()
        },

        async loadAuditTeamsForUser() {
            if (!this.audit_selectedUser) return
            this.audit_teamsLoading = true
            this.audit_teamsError   = null
            try {
                const uid = encodeURIComponent(this.audit_selectedUser.uid)
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/users/${uid}/teams`),
                )
                this.audit_teamRows = Array.isArray(data?.teams) ? data.teams : []
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                this.audit_teamsError = msg
                    ? this.t('teamhub', 'Failed to load teams: {error}', { error: msg })
                    : this.t('teamhub', 'Failed to load teams')
            } finally {
                this.audit_teamsLoading = false
            }
        },

        clearAuditUser() {
            this.audit_selectedUser    = null
            this.audit_teamRows        = []
            this.audit_selectedTeamIds = []
            this.audit_teamsError      = null
        },

        toggleAuditRow(row) {
            if (!row.removable) return
            const idx = this.audit_selectedTeamIds.indexOf(row.teamId)
            if (idx >= 0) {
                this.audit_selectedTeamIds.splice(idx, 1)
            } else {
                this.audit_selectedTeamIds.push(row.teamId)
            }
        },

        toggleSelectAllRemovable() {
            if (this.audit_allRemovableSelected) {
                this.audit_selectedTeamIds = []
            } else {
                this.audit_selectedTeamIds = this.audit_removableRows.map(r => r.teamId)
            }
        },

        openAuditRemoveConfirm() {
            if (this.audit_selectedTeamIds.length === 0) return
            this.audit_removeConfirmOpen = true
        },

        async executeAuditRemove() {
            this.audit_removeConfirmOpen = false
            if (!this.audit_selectedUser || this.audit_selectedTeamIds.length === 0) return
            this.audit_removeBusy = true
            try {
                const uid    = encodeURIComponent(this.audit_selectedUser.uid)
                const params = new URLSearchParams()
                this.audit_selectedTeamIds.forEach(id => params.append('teamIds[]', id))
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/users/${uid}/remove-from-teams`),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } },
                )
                const results = Array.isArray(data?.results) ? data.results : []
                const ok   = results.filter(r => r.ok)
                const fail = results.filter(r => !r.ok)

                if (ok.length > 0) {
                    showSuccess(
                        this.n('teamhub',
                            'Removed from {n} team',
                            'Removed from {n} teams',
                            ok.length,
                            { n: ok.length }),
                    )
                }
                fail.forEach(r => {
                    const teamRow = this.audit_teamRows.find(t => t.teamId === r.teamId)
                    const name    = teamRow ? teamRow.teamName : r.teamId
                    showError(this.t('teamhub', 'Failed to remove from {team}: {error}', {
                        team:  name,
                        error: r.error || this.t('teamhub', 'unknown error'),
                    }))
                })

                // Reload so the table reflects the change
                this.audit_selectedTeamIds = []
                await this.loadAuditTeamsForUser()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg
                    ? this.t('teamhub', 'Failed to remove: {error}', { error: msg })
                    : this.t('teamhub', 'Failed to remove'))
            } finally {
                this.audit_removeBusy = false
            }
        },

        // ------------------------------------------------------------------
        // Membership integrity
        // ------------------------------------------------------------------

        async runMembershipCheck() {
            this.membershipCheckLoading = true
            this.membershipCheckError   = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/membership-check')
                )
                this.membershipCheck = data
            } catch (e) {
                this.membershipCheckError = e?.response?.data?.error || 'Check failed'
                this.membershipCheck      = null
            } finally {
                this.membershipCheckLoading = false
            }
        },

        async removeNestedTeam(issue) {
            const key = issue.id + '_nested'
            this.membershipRepairing[key] = true
            try {
                await axios.delete(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/nested-team'),
                    { data: { parentTeamId: issue.id, childTeamId: issue.nested_team_id } }
                )
                this.membershipCheck.issues = this.membershipCheck.issues.filter(
                    i => !(i.id === issue.id && i.nested_team_id === issue.nested_team_id)
                )
                this.membershipCheck.mismatched = this.membershipCheck.issues.length
                showSuccess(this.t('teamhub', 'Nested team removed. The team should now be visible again.'))
            } catch (e) {
                showError(this.t('teamhub', 'Failed to remove nested team: {error}', {
                    error: e?.response?.data?.error || e.message,
                }))
            } finally {
                this.membershipRepairing[key] = false
            }
        },

        async fixDisplayName(issue) {
            const key = issue.id + '_dn'
            this.membershipRepairing[key] = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/fix-display-name/${issue.id}`)
                )
                this.membershipCheck.issues = this.membershipCheck.issues.filter(
                    i => !(i.id === issue.id && i.issue_type === 'wrong_display_name')
                )
                this.membershipCheck.mismatched = this.membershipCheck.issues.length
                showSuccess(this.t('teamhub', 'Display name fixed to "{name}"', { name: data.newName }))
            } catch (e) {
                showError(this.t('teamhub', 'Failed to fix display name: {error}', {
                    error: e?.response?.data?.error || e.message,
                }))
            } finally {
                this.membershipRepairing[key] = false
            }
        },

        async assignOwner(issue) {
            const key = issue.id + '_noowner'
            this.membershipRepairing[key] = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/assign-owner/${issue.id}`)
                )
                this.membershipCheck.issues = this.membershipCheck.issues.filter(
                    i => !(i.id === issue.id && i.issue_type === 'no_owner')
                )
                this.membershipCheck.mismatched = this.membershipCheck.issues.length
                showSuccess(this.t('teamhub', 'Owner assigned: {uid}', { uid: data.newOwner }))
            } catch (e) {
                showError(this.t('teamhub', 'Failed to assign owner: {error}', {
                    error: e?.response?.data?.error || e.message,
                }))
            } finally {
                this.membershipRepairing[key] = false
            }
        },

        async repairDuplicateMember(issue) {
            const key = issue.id + '_' + issue.duplicate_uid
            this.membershipRepairing[key] = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/repair-duplicate-member/${issue.id}`),
                    { userId: issue.duplicate_uid }
                )
                this.membershipCheck.issues = this.membershipCheck.issues.filter(
                    i => !(i.id === issue.id && i.issue_type === 'duplicate_member' && i.duplicate_uid === issue.duplicate_uid)
                )
                this.membershipCheck.mismatched = this.membershipCheck.issues.length
                showSuccess(this.t('teamhub', 'Duplicate member rows removed.'))
            } catch (e) {
                showError(this.t('teamhub', 'Failed to repair: {error}', {
                    error: e?.response?.data?.error || e.message,
                }))
            } finally {
                this.membershipRepairing[key] = false
            }
        },

        async clearCfgSingle(issue) {
            const key = issue.id + '_cfgsingle'
            this.membershipRepairing[key] = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/clear-cfg-single/${issue.id}`)
                )
                this.membershipCheck.issues = this.membershipCheck.issues.filter(
                    i => !(i.id === issue.id && i.issue_type === 'cfg_single_set')
                )
                this.membershipCheck.mismatched = this.membershipCheck.issues.length
                showSuccess(this.t('teamhub', 'Team visibility restored. Run occ circles:maintenance to rebuild caches.'))
            } catch (e) {
                showError(this.t('teamhub', 'Failed to repair team: {error}', {
                    error: e?.response?.data?.error || e.message,
                }))
            } finally {
                this.membershipRepairing[key] = false
            }
        },

        async repairMembership(teamId) {
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/membership-repair/${teamId}`)
                )
                showSuccess(this.t('teamhub', 'Membership cache rebuilt'))
                // Re-run the check so the repaired row disappears from the list
                await this.runMembershipCheck()
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Repair failed: {error}', { error: msg }) : this.t('teamhub', 'Repair failed'))
            } finally {
                this.membershipRepairing[teamId] = false
            }
        },

        // ── Ghost member cleanup tab ────────────────────────────────────────

        onGhostSearchInput() {
            clearTimeout(this.ghostSearchTimer)
            this.ghostSearchTimer = setTimeout(() => this.loadGhostMembers(), 400)
        },

        async loadGhostMembers() {
            this.ghostLoading = true
            this.ghostError = null
            try {
                const { data } = await axios.get(
                    generateUrl('/apps/teamhub/api/v1/admin/maintenance/ghost-members'),
                    { params: { search: this.ghostSearch } }
                )
                this.ghostMembers = data.ghosts || []
                this.ghostScanned = true
            } catch (e) {
                this.ghostError = e?.response?.data?.error || this.t('teamhub', 'Scan failed')
            } finally {
                this.ghostLoading = false
            }
        },

        async removeGhostFromTeam(ghost, team) {
            const key = ghost.userId + ':' + team.teamId
            this.ghostRemoving[key] = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/ghost-members/${encodeURIComponent(ghost.userId)}`),
                    { data: { teamId: team.teamId } }
                )
                // Remove that team from this ghost's list; if empty, remove ghost entirely
                ghost.teams = ghost.teams.filter(t => t.teamId !== team.teamId)
                if (ghost.teams.length === 0) {
                    this.ghostMembers = this.ghostMembers.filter(g => g.userId !== ghost.userId)
                }
                showSuccess(this.t('teamhub', '{user} removed from {team}', { user: ghost.userId, team: team.teamName }))
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Remove failed: {error}', { error: msg }) : this.t('teamhub', 'Remove failed'))
            } finally {
                this.ghostRemoving[key] = false
            }
        },

        async removeGhostFromAll(ghost) {
            const key = ghost.userId + ':all'
            this.ghostRemoving[key] = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/admin/maintenance/ghost-members/${encodeURIComponent(ghost.userId)}`)
                )
                this.ghostMembers = this.ghostMembers.filter(g => g.userId !== ghost.userId)
                showSuccess(this.t('teamhub', '{user} removed from all teams', { user: ghost.userId }))
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Remove failed: {error}', { error: msg }) : this.t('teamhub', 'Remove failed'))
            } finally {
                this.ghostRemoving[key] = false
            }
        },

        removeFromTeamLabel(userId, teamName) {
            return this.t('teamhub', 'Remove {user} from {team}', { user: userId, team: teamName })
        },

        removeFromAllLabel(userId) {
            return this.t('teamhub', 'Remove {user} from all teams', { user: userId })
        },

        // ── Compliance tab — code integrity (v4.2.0) ─────────────────

        async loadIntegrity() {
            this.integrity.loading = true
            this.integrity.error   = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/integrity'))
                this.integrity.report = data
                this.integrity.loaded = true
            } catch (e) {
                this.integrity.error = e?.response?.data?.error
                    || e?.message
                    || this.t('teamhub', 'Could not load integrity check.')
            } finally {
                this.integrity.loading = false
            }
        },

        /**
         * v4.2.10 — Aggregated governance-risk summary for the ghost-
         * memberships and orphan-teams Compliance pills. Cheap enough to
         * refetch on every tab open. Errors surface into the row like the
         * integrity loader does.
         */
        async loadComplianceSummary() {
            this.complianceSummary.loading = true
            this.complianceSummary.error   = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/compliance/summary'))
                this.complianceSummary.report = data
                this.complianceSummary.loaded = true
            } catch (e) {
                this.complianceSummary.error = e?.response?.data?.error
                    || e?.message
                    || this.t('teamhub', 'Could not load compliance summary.')
            } finally {
                this.complianceSummary.loading = false
            }
        },

        /**
         * v4.8.15 — the Maintenance grid's classification chips.
         *
         * Labels follow the rule `constants/policy.js` owns: a seeded key is
         * translated, a renamed one is shown as the admin typed it.
         */
        templateChipLabel(team) {
            const c = team.classification
            return templateDisplayName(c.templateKey, c.templateLabel, c.templateSeeded)
        },
        profileChipLabel(team) {
            const c = team.classification
            return profileDisplayName(c.profileKey, c.profileLabel, c.profileSeeded)
        },
        /**
         * The state in words, for a screen reader and for the tooltip.
         *
         * The chip's colour and glyph are the quick signal; this is the one that
         * has to stand alone, because a reader who cannot see the green does not
         * get a second chance at it. WCAG 1.4.1.
         */
        profileChipAria(team) {
            const label = this.profileChipLabel(team)
            return team.classification.compliant
                ? this.t('teamhub', '{profile} — settings match this profile', { profile: label })
                : this.t('teamhub', '{profile} — settings no longer match this profile', { profile: label })
        },
        // The drift dialog names each setting from its key. Exposed as a method
        // because a Vue 3 Options-API template can only reach `methods`,
        // `data` and `computed` — a module-scope import is invisible to it, the
        // same rule SKILLS.md states for `t` and `n`.
        fieldLabel,

        /** Open the drift detail for one team. Red chips only — see the markup. */
        openDriftDialog(team) {
            this.driftDialogTeam = team
        },

        // ── Applying a policy profile to an existing team (v4.8.16) ───────

        /** App id → its display name, for the "these get switched off" note. */
        appLabelFor(appId) {
            return appLabel(appId)
        },

        /** A profile row's display name, seeded-vs-renamed rule applied. */
        profileName(p) {
            return profileDisplayName(p.profileKey, p.label, p.isSeeded)
        },

        async openAssignPolicy(team) {
            this.assignPolicyTeam    = team
            this.assignPolicyError   = null
            this.assignPolicyPreview = null
            // Preselect what the team carries, so the dialog opens showing the
            // status quo rather than an empty form the admin has to re-derive.
            this.assignPolicyKey     = team.classification?.profileKey || ''

            if (!this.assignPolicyProfiles.length) {
                this.assignPolicyProfilesLoading = true
                try {
                    const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/policy/profiles'))
                    this.assignPolicyProfiles = data.profiles || []
                } catch (e) {
                    this.assignPolicyError = e?.response?.data?.error
                        || this.t('teamhub', 'Could not load the policy profiles.')
                } finally {
                    this.assignPolicyProfilesLoading = false
                }
            }

            if (this.assignPolicyKey) {
                this.loadAssignPreview()
            }
        },

        selectAssignPolicy(key) {
            this.assignPolicyKey = key
            this.loadAssignPreview()
        },

        /**
         * Fetch the diff for the selected profile.
         *
         * The Apply button stays disabled until this resolves — confirming
         * without a preview on screen is the silent state change §4.3 forbids,
         * and a disabled button is a cheaper guarantee of that than a rule
         * somebody has to remember.
         */
        async loadAssignPreview() {
            if (!this.assignPolicyTeam || !this.assignPolicyKey) return
            this.assignPolicyPreviewLoading = true
            this.assignPolicyPreview        = null
            this.assignPolicyError          = null
            try {
                const url = generateUrl('/apps/teamhub/api/v1/admin/policy/teams/{teamId}/preview', {
                    teamId: this.assignPolicyTeam.id,
                })
                const { data } = await axios.get(url, { params: { profileKey: this.assignPolicyKey } })
                this.assignPolicyPreview = data
            } catch (e) {
                this.assignPolicyError = e?.response?.data?.error
                    || this.t('teamhub', 'Could not work out what applying this profile would change.')
            } finally {
                this.assignPolicyPreviewLoading = false
            }
        },

        async confirmAssignPolicy() {
            if (!this.assignPolicyTeam || !this.assignPolicyKey) return
            this.assignPolicySaving = true
            this.assignPolicyError  = null
            try {
                const url = generateUrl('/apps/teamhub/api/v1/admin/policy/teams/{teamId}', {
                    teamId: this.assignPolicyTeam.id,
                })
                const { data } = await axios.post(url, { profileKey: this.assignPolicyKey })

                // The server says which fields it could not write — today only
                // external members, which applying never fixes. Reporting it as
                // a warning rather than a success keeps the toast honest about
                // a team that is about to show a red chip.
                const stuck = (data.notApplied || []).length
                if (stuck) {
                    showError(this.n('teamhub', 'Profile applied. {n} setting could not be changed and is reported instead — see the team’s profile chip.', 'Profile applied. {n} settings could not be changed and are reported instead — see the team’s profile chip.', stuck, { n: stuck }))
                } else {
                    showSuccess(this.t('teamhub', 'Profile applied to {team}.', { team: this.assignPolicyTeam.name }))
                }
                this.closeAssignPolicy()
                // The grid's chips and the Compliance counts both changed.
                await this.loadTeams()
                this.complianceSummary.loaded = false
            } catch (e) {
                this.assignPolicyError = e?.response?.data?.error
                    || this.t('teamhub', 'Could not apply the profile.')
            } finally {
                this.assignPolicySaving = false
            }
        },

        async clearAssignPolicy() {
            if (!this.assignPolicyTeam) return
            this.assignPolicySaving = true
            this.assignPolicyError  = null
            try {
                const url = generateUrl('/apps/teamhub/api/v1/admin/policy/teams/{teamId}', {
                    teamId: this.assignPolicyTeam.id,
                })
                await axios.delete(url)
                showSuccess(this.t('teamhub', 'The team no longer carries a policy profile. Its settings are unchanged.'))
                this.closeAssignPolicy()
                await this.loadTeams()
                this.complianceSummary.loaded = false
            } catch (e) {
                this.assignPolicyError = e?.response?.data?.error
                    || this.t('teamhub', 'Could not remove the profile.')
            } finally {
                this.assignPolicySaving = false
            }
        },

        closeAssignPolicy() {
            this.assignPolicyTeam    = null
            this.assignPolicyPreview = null
            this.assignPolicyKey     = ''
            this.assignPolicyError   = null
        },

        /**
         * A governed value as a person reads it (v4.8.15).
         *
         * Three shapes reach this: a boolean for the six config bits, external
         * members and public messages; a list of app ids for the integration
         * allow-list. `fieldLabel` is not used here — this renders the *value*,
         * and the setting's own name is the column beside it.
         *
         * An empty list renders as an em dash rather than "none": on the
         * observed side it means the team has no extra app, and on the expected
         * side an empty allow-list means "everything allowed" — a word that
         * carried both meanings would be wrong half the time. The empty case
         * cannot actually reach here, because a field only appears when it
         * differs, but rendering it as a dash keeps that true by construction.
         */
        driftValueLabel(fieldKey, value, label = null) {
            // v4.8.24 — a fourth shape: an opaque system tag id, which is
            // meaningless on screen. The payload carries the resolved name
            // beside it because only the server can look one up, and an absent
            // tag reads as absent rather than as "Off" — the folder does not
            // hold the classification, which is not the same as holding a
            // setting that is switched off.
            if (fieldKey === FIELD.CONFIDENTIAL_TAG) {
                if (!value) {
                    return this.t('teamhub', 'Not on the folder')
                }
                // Falls back to the raw id only when the tag has been deleted
                // since the value was stored — better than a blank cell, and it
                // is the id an administrator would search for.
                return label || value
            }
            if (Array.isArray(value)) {
                return value.length ? value.map(appLabel).join(', ') : '—'
            }
            return value ? this.t('teamhub', 'On') : this.t('teamhub', 'Off')
        },

        /**
         * v4.8.15 — Template and profile counts for the setup checklist.
         *
         * Its own endpoint rather than the Policy tab's two list calls: those
         * return every profile's full value set and every template's app list,
         * which is a lot of payload to fetch on every admin-settings page load
         * for five integers. An error leaves both rows in their "Checking…"
         * state — see setupChecklist — rather than asserting zero, because a
         * failed fetch and an unconfigured instance must not look the same.
         */
        async loadPolicySummary() {
            this.policySummary.loading = true
            this.policySummary.error   = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/policy/summary'))
                this.policySummary.report = data
                this.policySummary.loaded = true
            } catch (e) {
                this.policySummary.error = e?.response?.data?.error
                    || e?.message
                    || this.t('teamhub', 'Could not load the policy summary.')
            } finally {
                this.policySummary.loading = false
            }
        },

        /**
         * v4.4.14 — Save-as-PDF for the Compliance-checks panel.
         *
         * Client-side: build a print-ready HTML document from the same
         * state the on-screen pills read, open it in a new window, and
         * invoke window.print() so the admin uses the browser's own PDF
         * export. No server-side generator, no extra dependencies.
         *
         * "Screenshot" per the ask is met by carrying every check row —
         * name, status, value, description — into the printed page. The
         * printout is a data-driven capture of the panel at click time,
         * not a raster image, so it survives copy/paste and screen
         * readers where a bitmap would not.
         *
         * The report includes the report generation time and the app
         * version so an auditor can pin the sample to a build.
         */
        async openComplianceReport() {
            // Make sure the summary state is loaded before printing. On a
            // fresh tab open the pills briefly show "Checking…"; the report
            // should never do the same.
            if (!this.complianceSummary.loaded && !this.complianceSummary.loading) {
                await this.loadComplianceSummary()
            }

            // v4.8.0 — the Audit log row reads the retention window, which
            // is otherwise only fetched when the Audit panel is opened. A
            // reader can print the report without ever going there, and the
            // row would then say "Unavailable" on an instance that has a
            // perfectly good retention setting.
            if (!this.auditRetentionLoaded) {
                await this.loadAuditRetention()
            }

            const generatedAt = fmtDateTime(new Date())
            const appVersion = this.integrity.report?.app_version || ''
            const customer = this.license.status?.customer || ''

            // Each check pairs a state with a description. Descriptions
            // live here rather than in the row's info-menu because the PDF
            // is the one place a reader may not have the app in front of
            // them and needs the "what does this check even mean" line.
            const checks = this.buildComplianceReportChecks()

            // v4.4.14 note: PDF content is deliberately English-only.
            // Compliance reports are typically shared with auditors and
            // regulators, who expect a stable canonical language, and the
            // localised t('teamhub', key-variable) pattern the scanner uses
            // cannot extract dynamic keys anyway. Keeping the strings plain
            // English keeps the extraction check honest and the report
            // reproducible across locales.
            const esc = (s) => String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')

            // Render the checks table.
            const rowsHtml = checks.map((c) => `
                <tr class="row row--${c.state}">
                    <td class="col-name">${esc(c.name)}</td>
                    <td class="col-status">
                        <span class="pill pill--${c.state}">${esc(c.statusLabel)}</span>
                    </td>
                    <td class="col-value">${esc(c.value)}</td>
                    <td class="col-controls">${(c.controls || []).map(esc).join('<br>')}</td>
                    <td class="col-desc">${esc(c.description)}</td>
                </tr>
            `).join('')

            // v4.8.0 — the control table is the inverse of the one above:
            // every control in scope, including the ones nothing evidences.
            // Naming a gap is the point — an auditor reading a matrix with no
            // empty rows cannot tell thorough coverage from a short list.
            const coverageHtml = buildControlCoverage(checks).map((ctl) => `
                <tr class="row row--${ctl.checks.length ? 'ok' : 'info'}">
                    <td class="col-control-id">${esc(ctl.id)}</td>
                    <td class="col-control-title">${esc(ctl.title)}</td>
                    <td class="col-control-checks">${
                        ctl.checks.length
                            ? esc(ctl.checks.join(', '))
                            : '<span class="not-evidenced">Not evidenced</span>'
                    }</td>
                </tr>
            `).join('')

            // Full print document. Styles inlined so the popup doesn't
            // depend on any of the app's CSS being loaded.
            const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TeamHub compliance report</title>
<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: #222;
        margin: 32px;
        line-height: 1.45;
    }
    h1 {
        margin: 0 0 4px;
        font-size: 22px;
    }
    .meta {
        color: #666;
        font-size: 13px;
        margin-bottom: 24px;
    }
    .meta-row { margin: 2px 0; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    th, td {
        text-align: left;
        vertical-align: top;
        padding: 8px 10px;
        border-bottom: 1px solid #e0e0e0;
    }
    th {
        background: #f5f5f5;
        font-weight: 600;
    }
    .col-name { width: 18%; font-weight: 600; }
    .col-status { width: 13%; white-space: nowrap; }
    .col-value { width: 18%; }
    /* v4.8.0 — control ids stack one per line rather than wrapping mid-id;
       "A.5.12" broken across a line break is unreadable in a printed matrix. */
    .col-controls { width: 11%; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .col-desc { width: 40%; color: #444; }
    .col-control-id { width: 12%; font-weight: 600; white-space: nowrap; }
    .col-control-title { width: 38%; }
    .col-control-checks { width: 50%; color: #444; }
    h2 {
        margin: 32px 0 4px;
        font-size: 17px;
    }
    .section-note {
        color: #666;
        font-size: 12px;
        margin: 0 0 12px;
    }
    /* Not a failure state — see the note above the table. Grey, not red. */
    .not-evidenced {
        color: #666;
        font-style: italic;
    }
    .pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid transparent;
    }
    /* Print-safe pill colours: solid + border so the state reads even
       when a black-and-white printer strips the fill. */
    .pill--ok      { background: #e6f4ea; color: #1e6b34; border-color: #1e6b34; }
    .pill--warn    { background: #fff4e5; color: #7a4a00; border-color: #7a4a00; }
    .pill--err     { background: #fce8e6; color: #8a1a12; border-color: #8a1a12; }
    .pill--info,
    .pill--unknown { background: #eef1f4; color: #384a5c; border-color: #384a5c; }
    .footer {
        margin-top: 32px;
        color: #888;
        font-size: 11px;
        border-top: 1px solid #e0e0e0;
        padding-top: 10px;
    }
    @media print {
        body { margin: 12mm; }
    }
</style>
</head>
<body>
    <!-- v4.4.15 — Print / Close buttons retired. The auto-triggered
         window.print() below fires the browser's own Save-as-PDF dialog;
         cross-origin restrictions on this document-written blank tab
         blocked the inline onclick handlers, and a broken button is worse
         than none. If the print dialog is dismissed, the reader can still
         use the browser's own File → Print / Save as PDF menu. -->

    <h1>TeamHub compliance report</h1>
    <div class="meta">
        <div class="meta-row"><strong>Generated:</strong> ${esc(generatedAt)}</div>
        ${appVersion ? `<div class="meta-row"><strong>TeamHub version:</strong> ${esc(appVersion)}</div>` : ''}
        ${customer ? `<div class="meta-row"><strong>Customer:</strong> ${esc(customer)}</div>` : ''}
    </div>

    <table>
        <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Value</th>
                <th>Controls</th>
                <th>What this check means</th>
            </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
    </table>

    <h2>ISO/IEC 27001:2022 control coverage</h2>
    <p class="section-note">
        Which of the controls in scope are evidenced by a check above. A control
        marked <em>Not evidenced</em> is not a finding against this instance — it
        means TeamHub does not currently observe anything about that control, and
        evidence for it has to come from elsewhere in your ISMS.
    </p>
    <table>
        <thead>
            <tr>
                <th>Control</th>
                <th>Title</th>
                <th>Evidenced by</th>
            </tr>
        </thead>
        <tbody>${coverageHtml}</tbody>
    </table>

    <p class="footer">
        This report captures the compliance state at the moment it was generated.
        Rerun the checks in TeamHub → Admin settings → Compliance for the current state.
    </p>
</body>
</html>`

            // Open in a new tab. If the browser blocks the pop-up the
            // user sees a notification and can retry — better than a
            // silent no-op.
            const win = window.open('', '_blank')
            if (!win) {
                this.saveError = this.t('teamhub', 'The compliance report was blocked by the browser’s pop-up filter. Allow pop-ups for this site and try again.')
                return
            }
            win.document.open()
            win.document.write(html)
            win.document.close()
            // Trigger the browser's own print / Save-as-PDF dialog once
            // the document has painted. A small tick delay lets Chromium
            // finish layout before print() so the on-screen "Print or
            // save as PDF" button paints once.
            win.setTimeout(() => { try { win.print() } catch (e) { /* user closed the tab */ } }, 250)
        },

        /**
         * v4.4.14 — Build the row objects the compliance PDF renders.
         * Kept next to openComplianceReport so the two evolve together.
         * Each row is { name, state, statusLabel, value, description }.
         */
        buildComplianceReportChecks() {
            // v4.4.14 — deliberately English-only, see note on
            // openComplianceReport for the reasoning.
            const rows = []

            // Code integrity
            const integ = this.integrity.report
            const integState = this.integrityPillLevel === 'error'
                ? 'err'
                : this.integrityPillLevel
            rows.push({
                name:        'Code integrity',
                state:       integState || 'unknown',
                statusLabel: this.integrityStatusLabel || 'Unknown',
                value:       integ ? `${integ.files_checked ?? '?'} files checked` : '',
                description: 'Verifies shipped files against a SHA-256 manifest generated at build time. Alerts when a file is altered, missing or unexpected — the signature that a customer install has been tampered with or a partial upgrade failed.',
                controls:    ['A.8.16'],
            })

            // Telemetry — derived from license state
            const telOn = this.telemetryEnabledDerived
            rows.push({
                name:        'Telemetry',
                state:       telOn ? 'warn' : 'ok',
                statusLabel: telOn ? 'On' : 'Off',
                value:       telOn ? 'Sending daily' : 'Not transmitting',
                description: telOn
                    ? 'This instance has no active TeamHub license, so anonymous daily usage statistics are sent to help capacity planning. An active license disables telemetry automatically.'
                    : 'An active license is installed, so no usage data leaves this instance. The daily telemetry job runs but returns early on the license check.',
                controls:    ['A.5.34'],
            })

            // Invite types
            const reach = this.invitesExternalReach
            rows.push({
                name:        'Allowed invite types',
                state:       reach ? 'warn' : 'ok',
                statusLabel: reach ? 'External reach' : 'Local only',
                value:       reach
                    ? [this.inviteEmail ? 'Email' : '', this.inviteFederated ? 'Federated' : ''].filter(Boolean).join(', ')
                    : 'Local users, groups',
                description: reach
                    ? 'Team admins can invite people from outside this server. Email invitations create shares by email address; federated invitations pull users from other Nextcloud instances. Both bypass local user provisioning.'
                    : 'Invitations are restricted to local Nextcloud accounts and groups. No mechanism lets a team admin reach outside this server.',
                controls:    ['A.5.15'],
            })

            // Ghost memberships
            const ghostReady = this.complianceSummary.loaded
            const ghost = this.ghostCount
            rows.push({
                name:        'Ghost memberships',
                state:       !ghostReady ? 'unknown' : (ghost > 0 ? 'err' : 'ok'),
                statusLabel: !ghostReady ? 'Unavailable' : String(ghost),
                value:       ghost > 0 ? `${ghost} ghost row${ghost === 1 ? '' : 's'}` : 'None',
                description: 'Deleted Nextcloud users still listed as team members. Left in place they can appear in share pickers and audit lists. Remediated on the Maintenance tab under Deleted users in teams.',
                controls:    ['A.5.18'],
            })

            // Orphan teams
            const orphan = this.orphanCount
            rows.push({
                name:        'Orphan teams',
                state:       !ghostReady ? 'unknown' : (orphan > 0 ? 'err' : 'ok'),
                statusLabel: !ghostReady ? 'Unavailable' : String(orphan),
                value:       orphan > 0 ? `${orphan} orphan team${orphan === 1 ? '' : 's'}` : 'None',
                description: 'Teams with no live owner. Governance actions (adding admins, transferring ownership, deleting the team) require an owner; without one the team is only reachable through admin maintenance flows.',
                // A.5.3 as well as A.5.18: an ownerless team has nobody in the
                // role that approves membership changes, so the separation
                // between requesting and granting access has collapsed.
                controls:    ['A.5.18', 'A.5.3'],
            })

            // Team profile compliance (v4.8.15, Track F)
            //
            // The controls are the point of this row as much as the count.
            // A.5.12 and A.5.13 have read "Not evidenced" in every report since
            // 4.8.1 removed tags — a policy profile is literally a
            // classification applied to a team and a label carried by it, so
            // this check is what answers them. A.8.16 as well, because the
            // answer is produced by monitoring rather than by prevention.
            //
            // `none` maps to the report's `info` state, not `ok`: an auditor
            // reading "OK — 0 drifted" against an instance that classified
            // nothing would be reading a pass mark for a test that never ran.
            const pc      = this.profileCompliance
            const pcState = this.profileComplianceState
            rows.push({
                name:        'Team profile compliance',
                state:       pcState === 'unknown' ? 'unknown' : (pcState === 'none' ? 'info' : pcState),
                statusLabel: pcState === 'unknown'
                    ? 'Unavailable'
                    : (pcState === 'none' ? 'No teams classified' : String(this.profileDriftCount)),
                value:       pc && pcState !== 'unknown' && pcState !== 'none'
                    ? `${pc.conformant} of ${pc.classified} teams match their profile`
                    : (pcState === 'none' ? 'No team carries a policy profile' : ''),
                description: 'Teams carrying a policy profile, compared against the settings that profile defines. Eight of the nine governed settings can also be changed in Contacts or the Teams app, which dispatch no event doing so — this check therefore detects and reports a difference, it does not prevent one, and it cannot attribute it to an actor. Unclassified teams are not compared and are not counted.',
                controls:    ['A.5.12', 'A.5.13', 'A.8.16'],
            })

            // Audit log (v4.8.0)
            // Gated on the loaded flag, not on the value: `auditRetention`
            // holds a 90-day default from `data()` that would otherwise be
            // printed as though it had been read from the instance.
            const retentionDays = this.auditRetentionLoaded
                ? this.auditRetention?.retention_days
                : null
            rows.push({
                name:        'Audit log retention',
                state:       retentionDays ? 'ok' : 'unknown',
                statusLabel: retentionDays ? `${retentionDays} day retention` : 'Unavailable',
                value:       retentionDays ? `Purged after ${retentionDays} days` : '',
                description: 'Per-team log of membership, file, share, resource and configuration events. The service exposes only append and bulk purge — no code path updates or deletes an individual row — so a record cannot be silently rewritten before its retention window expires.',
                controls:    ['A.8.15', 'A.5.33'],
            })

            return rows
        },

        /**
         * Formats an ISO-8601 timestamp using the browser locale, degrading
         * gracefully to the raw string if the input is not parseable.
         */
        formatIntegrityTimestamp(iso) {
            if (!iso) return ''
            const d = new Date(iso)
            if (isNaN(d.getTime())) return iso
            return fmtDateTime(d)
        },

        // ── Audit tab ──────────────────────────────────────────────────

        async loadAuditRetention() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/audit/retention'))
                this.auditRetention = data
                this.auditRetentionInput = data.retention_days
                this.auditRetentionLoaded = true
            } catch (e) {
                // Non-fatal — keep defaults.
                this.auditRetentionLoaded = true
            }
        },

        async saveAuditRetention() {
            const n = parseInt(this.auditRetentionInput, 10)
            if (isNaN(n)) return
            this.auditRetentionSaving = true
            try {
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/admin/audit/retention'),
                    { retentionDays: n },
                )
                this.auditRetention.retention_days = n
                showSuccess(this.t('teamhub', 'Retention saved'))
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Failed to save retention: {error}', { error: msg }) : this.t('teamhub', 'Failed to save retention'))
            } finally {
                this.auditRetentionSaving = false
            }
        },

        async loadAuditTeams() {
            this.auditTeamsLoading = true
            this.auditTeamsError = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/audit/teams'))
                this.auditTeams = Array.isArray(data.teams) ? data.teams : []
                this.auditActivityMissing = !!data.activity_missing
            } catch (e) {
                this.auditTeamsError = e?.response?.data?.error || this.t('teamhub', 'Failed to load teams')
                this.auditTeams = []
            } finally {
                this.auditTeamsLoading = false
            }
        },

        onAuditTeamChanged() {
            this.auditEventsPage = 1
            this.auditEvents = []
            this.auditEventsTotal = 0
            if (this.auditSelectedTeamId) {
                this.loadAuditEvents()
            }
        },

        resetAndLoadAuditEvents() {
            this.auditEventsPage = 1
            this.loadAuditEvents()
        },

        changeAuditPage(p) {
            if (p < 1 || p > this.auditEventsTotalPages) return
            this.auditEventsPage = p
            this.loadAuditEvents()
        },

        async loadAuditEvents() {
            if (!this.auditSelectedTeamId) return
            this.auditEventsLoading = true
            this.auditEventsError = null
            try {
                const params = {
                    page: this.auditEventsPage,
                    perPage: this.auditEventsPerPage,
                }
                if (this.auditEventTypeFilter) {
                    params.eventTypes = this.auditEventTypeFilter
                }
                if (this.auditFromDate) {
                    params.from = Math.floor(new Date(this.auditFromDate + 'T00:00:00').getTime() / 1000)
                }
                if (this.auditToDate) {
                    params.to = Math.floor(new Date(this.auditToDate + 'T23:59:59').getTime() / 1000)
                }
                const url = generateUrl(
                    `/apps/teamhub/api/v1/admin/audit/teams/${encodeURIComponent(this.auditSelectedTeamId)}/events`
                )
                const { data } = await axios.get(url, { params })
                this.auditEvents = Array.isArray(data.rows) ? data.rows : []
                this.auditEventsTotal = data.total || 0
            } catch (e) {
                this.auditEventsError = e?.response?.data?.error || this.t('teamhub', 'Failed to load events')
                this.auditEvents = []
                this.auditEventsTotal = 0
            } finally {
                this.auditEventsLoading = false
            }
        },

        async exportAuditTeam() {
            if (!this.auditSelectedTeamId) return
            this.auditExporting = true
            try {
                const url = generateUrl(
                    `/apps/teamhub/api/v1/admin/audit/teams/${encodeURIComponent(this.auditSelectedTeamId)}/export`
                )
                const response = await axios.get(url, { responseType: 'blob' })
                // Trigger a download in the browser without leaving the page.
                const blob = new Blob([response.data], { type: 'application/zip' })
                const link = document.createElement('a')
                link.href = window.URL.createObjectURL(blob)
                // Filename comes from server Content-Disposition; fall back to a default.
                const team = this.auditTeams.find(t => t.team_id === this.auditSelectedTeamId)
                const slug = team
                    ? team.display_name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
                    : 'team'
                link.download = `teamhub-audit-${slug || 'team'}-${todayIso()}.zip`
                document.body.appendChild(link)
                link.click()
                document.body.removeChild(link)
                window.URL.revokeObjectURL(link.href)
            } catch (e) {
                const msg = e?.response?.data?.error || ''
                showError(msg ? this.t('teamhub', 'Export failed: {error}', { error: msg }) : this.t('teamhub', 'Export failed'))
            } finally {
                this.auditExporting = false
            }
        },

        formatAuditTimestamp(ts) {
            if (!ts) return ''
            return fmtDateTime(ts * 1000)
        },

        summariseAuditMetadata(meta) {
            if (!meta || typeof meta !== 'object') return ''
            // Compact representation — first two top-level keys, truncated.
            const entries = Object.entries(meta).slice(0, 3)
            const parts = entries.map(([k, v]) => {
                let s
                if (typeof v === 'object' && v !== null) {
                    s = JSON.stringify(v)
                } else {
                    s = String(v)
                }
                if (s.length > 80) s = s.slice(0, 80) + '…'
                return `${k}=${s}`
            })
            return parts.join(' · ')
        },

        // ── Archive tab methods ──────────────────────────────────────────────

        async loadArchiveSettings() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/archive/settings'))
                this.archiveSettings = {
                    archiveBeforeDelete: !!data.archiveBeforeDelete,
                    archiveMode:     data.archiveMode     ?? 'soft30',
                    archiveLocation: data.archiveLocation ?? '',
                    archiveMaxMb:    data.archiveMaxMb    ?? 5120,
                    anonymizeData:   !!data.anonymizeData,
                }
                this.archiveSettingsLoaded = true
            } catch (err) {
                this.archiveSettingsError = this.t('teamhub', 'Failed to load archive settings: {error}', { error: err.message })
            }
        },

        async saveArchiveSettings() {
            this.archiveSettingsSaving = true
            this.archiveSettingsSaved  = false
            this.archiveSettingsError  = null
            try {
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/admin/archive/settings'),
                    this.archiveSettings,
                )
                this.archiveSettingsSaved = true
                setTimeout(() => { this.archiveSettingsSaved = false }, 3000)
            } catch (err) {
                this.archiveSettingsError = this.t('teamhub', 'Failed to save archive settings: {error}', { error: err.response?.data?.error || err.message })
            } finally {
                this.archiveSettingsSaving = false
            }
        },

        async loadPendingDeletions() {
            this.pendingDelsLoading = true
            this.pendingDelsError   = null
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/archive/pending'))
                this.pendingDels      = data.rows  ?? []
                this.pendingDelsTotal = data.total ?? 0
            } catch (err) {
                this.pendingDelsError = this.t('teamhub', 'Failed to load archived teams: {error}', { error: err.message })
            } finally {
                this.pendingDelsLoading = false
            }
        },

        async restorePendingDeletion(id) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/admin/archive/pending/${id}/restore`))
                // Remove from view immediately — restored teams need no further admin action.
                this.pendingDels = this.pendingDels.filter(r => r.id !== id)
                this.failedDetailId = null
            } catch (err) {
                this.pendingDelsError = this.t('teamhub', 'Failed to restore team: {error}', { error: err.response?.data?.error || err.message })
            }
        },

        async purgePendingDeletion(id) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/admin/archive/pending/${id}/purge`))
                this.pendingDels = this.pendingDels.filter(r => r.id !== id)
                this.failedDetailId = null
            } catch (err) {
                this.pendingDelsError = this.t('teamhub', 'Failed to purge team: {error}', { error: err.response?.data?.error || err.message })
            }
        },

        toggleFailedDetail(id) {
            this.failedDetailId = this.failedDetailId === id ? null : id
        },

        async retryArchive(id) {
            try {
                const { data } = await axios.post(generateUrl(`/apps/teamhub/api/v1/admin/archive/pending/${id}/retry`))
                // Replace the failed row with the new pending row returned by the retry.
                const idx = this.pendingDels.findIndex(r => r.id === id)
                if (idx !== -1) {
                    this.pendingDels.splice(idx, 1, data)
                } else {
                    this.pendingDels.unshift(data)
                }
                this.failedDetailId = null
            } catch (err) {
                this.pendingDelsError = this.t('teamhub', 'Retry failed: {error}', { error: err.response?.data?.error || err.message })
            }
        },

        async discardFailedArchive(id) {
            try {
                await axios.delete(generateUrl(`/apps/teamhub/api/v1/admin/archive/pending/${id}`))
                // Remove from view — team is usable again, no further action needed.
                this.pendingDels = this.pendingDels.filter(r => r.id !== id)
                this.failedDetailId = null
            } catch (err) {
                this.pendingDelsError = this.t('teamhub', 'Failed to discard archive: {error}', { error: err.response?.data?.error || err.message })
            }
        },

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '—'
            if (bytes < 1024) return bytes + ' B'
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
            if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB'
            return (bytes / 1073741824).toFixed(2) + ' GB'
        },

        /**
         * Format a Unix timestamp (seconds) as a localised short date.
         * Used by the archive table (archivedAt is a Unix timestamp integer).
         */
        formatUnixDate(unixTs) {
            if (!unixTs) return '—'
            return fmtDate(unixTs * 1000, {
                year: 'numeric', month: 'short', day: 'numeric',
            }) || '—'
        },

        // ── v3.100.0 Licensing methods ──────────────────────────────
        async loadLicense() {
            this.license.loading = true
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/license'))
                this.license.status = data
            } catch (err) {
                this.license.status = null
                showError(this.t('teamhub', 'Could not load license status'))
            } finally {
                this.license.loading = false
            }
        },
        async saveLicenseKey() {
            const jwt = this.license.pendingKey.trim()
            if (!jwt) return
            this.license.saving    = true
            this.license.saveError = null
            try {
                const { data } = await axios.put(
                    generateUrl('/apps/teamhub/api/v1/admin/license'),
                    { jwt },
                )
                this.license.status     = data
                this.license.pendingKey = ''
                showSuccess(this.t('teamhub', 'License key saved'))
            } catch (err) {
                this.license.saveError = err?.response?.data?.error
                    || this.t('teamhub', 'Could not save license key')
            } finally {
                this.license.saving = false
            }
        },
        async copyUuid() {
            const uuid = this.license.status?.instanceUuid
            if (!uuid) return
            try {
                await navigator.clipboard.writeText(uuid)
                this.license.uuidCopied = true
                setTimeout(() => { this.license.uuidCopied = false }, 1500)
            } catch (err) {
                showError(this.t('teamhub', 'Could not copy UUID'))
            }
        },
        formatLicenseDate(unixTs) {
            return this.formatUnixDate(unixTs)
        },
    },
}
</script>

<style scoped>
/* ── Wrapper ─────────────────────────────────────────────────────────────── */
.teamhub-admin {
    display: flex;
    flex-direction: column;
}

/* ── Tab bar ─────────────────────────────────────────────────────────────────
   Classic folder-tab style: tabs butt together (no gap) and sit on a shared
   baseline. Inactive tabs read as a flat white strip; the active tab is filled
   in the primary tint and "breaks" the baseline beneath it (its own background
   covers the bar's bottom border) so it visually connects to the panel below. */
.teamhub-admin-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0;                                  /* tabs butt directly together */
    padding: 0 16px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 16px;
}

.teamhub-admin-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: var(--th-font-body);
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    background: var(--color-main-background); /* all tabs white by default */
    border: 1px solid var(--color-border);
    border-bottom: none;                      /* baseline is owned by the bar */
    margin-bottom: -1px;                      /* overlap the bar's 1px border  */
    margin-right: -1px;                       /* collapse the shared side border */
    cursor: pointer;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    transition: color 0.15s, background 0.15s;
    white-space: nowrap;
    position: relative;
}

.teamhub-admin-tab:first-child {
    border-top-left-radius: var(--border-radius);
}

/* No hover styling on the tab bar at all — hover added a transient z-index/seam
   repaint that looked wrong when moving the pointer off the active tab. Tabs
   stay plain white until active; only the active tab gets the hard primary fill. */

.teamhub-admin-tab--active {
    color: var(--color-primary-element-text);
    background: var(--color-primary-element); /* filled active tab            */
    border-color: var(--color-primary-element);
    font-weight: 600;
    z-index: 2;                               /* sit above neighbours + baseline */
}

/* Break the baseline directly under the active tab so it reads as connected
   to the panel below (folder-tab look). The active tab's own background sits
   over the bar's bottom border via the negative margin + z-index above; this
   pseudo-element guarantees the seam is covered cleanly at any zoom. */
.teamhub-admin-tab--active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 1px;
    background: var(--color-primary-element);
}

/* The active tab must stay hard-green in every interaction state. NC's global
   button styles paint a background on :focus, which (because the clicked tab
   keeps focus until you click elsewhere) was repainting the active tab light
   green until focus moved away. Re-assert the active colours for focus/
   focus-visible/active and override the global focus background with
   !important. Keep the visible focus ring via box-shadow for keyboard a11y. */
.teamhub-admin-tab--active:focus,
.teamhub-admin-tab--active:focus-visible,
.teamhub-admin-tab--active:active {
    background: var(--color-primary-element) !important;
    color: var(--color-primary-element-text) !important;
    border-color: var(--color-primary-element);
}

/* Inactive tabs: don't pick up NC's soft focus background — stay white. Keep a
   keyboard focus ring (focus-visible only) so tab-navigation remains visible.
   MUST exclude the active tab: it matches both this selector and the active
   override above at equal specificity, and being later in source order this
   white background was winning — producing white text (from the active rule's
   !important colour) on a white background until focus moved away. */
.teamhub-admin-tab:not(.teamhub-admin-tab--active):focus,
.teamhub-admin-tab:not(.teamhub-admin-tab--active):focus-visible,
.teamhub-admin-tab:not(.teamhub-admin-tab--active):active {
    background: var(--color-main-background) !important;
    color: var(--color-main-text);
}

.teamhub-admin-tab:not(.teamhub-admin-tab--active):focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

/* ── Tab panels ──────────────────────────────────────────────────────────── */
.teamhub-admin-panel {
    padding-top: 8px;
}

/* ── Group chips ─────────────────────────────────────────────────────────── */
.admin-group-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 10px;
}

/* v3.100.14: selected-group chip — full-saturation state per SKILLS.md
   § "State-coloured backgrounds" (was --color-primary-element-light). */
.admin-group-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: 1px solid var(--color-primary-element);
    border-radius: var(--border-radius-pill);
    font-size: 13px;
    font-weight: 500;
}

/* Keep the leading group icon inline with the label. The material-design-icon
   wrapper can render as a block when the library's own icon CSS isn't present,
   which floats the glyph onto its own line above the chip; pin it to an inline
   flex box so it always sits beside the text. */
.admin-group-chip .material-design-icon {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}

/* v3.100.14: was a text-only × button; now hosts an MDI CloseIcon.
   font-size no longer sizes the glyph (icon uses the :size prop).
   inline-flex centres the SVG in the button box. */
.admin-group-chip__remove {
    background: none;
    border: none;
    cursor: pointer;
    line-height: 1;
    color: var(--color-text-maxcontrast);
    padding: 0 2px;
    margin-left: 2px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.admin-group-chip__remove:hover {
    color: var(--color-error-text);
}

/* ── Group typeahead ─────────────────────────────────────────────────────── */
.admin-group-search {
    position: relative;
    max-width: 400px;
}

.admin-group-results {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 100;
    list-style: none;
    padding: 4px 0;
    margin: 0;
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius-large);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    max-height: 220px;
    overflow-y: auto;
}

.admin-group-result {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.admin-group-result:hover {
    background: var(--color-background-hover);
}

.admin-group-result__name {
    font-size: var(--th-font-body);
    font-weight: 500;
    flex: 1;
}

.admin-group-result__id {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.admin-group-hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 0;
    margin: 0;
}

/* ── Invite type checkboxes ──────────────────────────────────────────────── */
/* ── Allowed invite types (v4.4.13) ──────────────────────────────────────
   Five booleans laid out 2-up instead of five full-width stacked rows,
   which roughly halves the section height. auto-fit rather than a fixed
   count so it collapses to one column in the narrow admin-settings pane
   without a media query. */
.admin-invite-types {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 4px 24px;
    margin-top: 4px;
}

/* ── Compact setting rows (v4.4.13) ──────────────────────────────────────
   Name + description on the left, control on the right. Replaces the
   pattern of one NcSettingsSection per setting, which spent a heading and
   a full-width paragraph on every individual toggle. */
.admin-compact-rows {
    display: flex;
    flex-direction: column;
    margin-top: 4px;
}
.admin-compact-row {
    display: flex;
    align-items: flex-start;
    gap: 24px;
    padding: 10px 0;
    border-bottom: 1px solid var(--color-border);
}
.admin-compact-row:last-child {
    border-bottom: none;
}
.admin-compact-row__text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1 1 auto;
    min-width: 0;
}
/* v4.4.14 — the explicit --th-font-body (14 px) and --th-font-meta (12 px)
   were retired here. NcSettingsSection descriptions elsewhere on the admin
   settings page inherit the ~15 px base font, so setting a smaller size
   just to be compact made this tab read as demoted. Weight and colour
   still distinguish name from description. */
.admin-compact-row__name {
    font-weight: var(--th-font-weight-semibold);
}
.admin-compact-row__desc {
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}
.admin-compact-row__control {
    flex: 0 0 auto;
    align-self: center;
}
.admin-compact-row__control--field {
    width: 260px;
    max-width: 40%;
}
/* v4.8.31 — a row whose state is derived rather than set. Deliberately not a
   disabled switch: a greyed toggle invites an administrator to look for the
   permission that would let them flip it, when there is no such permission and
   nothing to flip. Reads as a status, because it is one. */
.admin-compact-row__derived {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    align-self: center;
    white-space: nowrap;
}
/* Narrow panes: stack the control under the text rather than squeezing
   both into a column each. */
@media (max-width: 700px) {
    .admin-compact-row {
        flex-direction: column;
        gap: 8px;
    }
    .admin-compact-row__control {
        align-self: flex-start;
    }
    .admin-compact-row__control--field {
        width: 100%;
        max-width: none;
    }
}

/* ── Integrations-tab autosave status (v4.4.13) ─────────────────────────── */
.admin-autosave {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 24px;
    padding: 0 16px 4px;
    font-size: var(--th-font-meta);
}
.admin-autosave__idle {
    color: var(--color-text-maxcontrast);
}
.admin-autosave__ok {
    color: var(--color-success-text);
}
.admin-autosave__err {
    color: var(--color-error-text);
}

/* ── Pin level select ────────────────────────────────────────────────────── */
.admin-select-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.admin-select-label {
    font-size: var(--th-font-body);
    font-weight: 500;
    min-width: 180px;
}

.admin-select {
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border-maxcontrast);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    min-width: 180px;
    cursor: pointer;
}

.admin-select:focus {
    border-color: var(--color-primary-element);
}

.admin-select:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* ── Integrations list ───────────────────────────────────────────────────── */
.admin-integrations-loading,
.admin-integrations-error,
.admin-integrations-empty {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-body);
    color: var(--color-text-maxcontrast);
    padding: 8px 0;
}

.admin-integrations-error { color: var(--color-error-text); }

.admin-integrations-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

.admin-integration-row {
    padding: 12px 14px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}

.admin-integration-row__body {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.admin-integration-row__header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* App icon — inline before the title, same size as a small avatar */
.admin-integration-row__icon {
    width: 22px;
    height: 22px;
    object-fit: contain;
    flex-shrink: 0;
}

.admin-integration-row__title {
    font-size: var(--th-font-body);
    font-weight: 600;
}

.admin-integration-row__appid {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.admin-integration-row__desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.admin-integration-row__urls {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    word-break: break-all;
}

.admin-integration-row__badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    border-radius: var(--border-radius-pill);
    padding: 1px 7px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* v3.100.14: full-saturation category badges per SKILLS.md
   (were 15% color-mix() soft tints). */
.admin-integration-row__badge--widget {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.admin-integration-row__badge--menu_item,
.admin-integration-row__badge--tab {
    background: var(--color-success);
    color: var(--color-success-text);
}
/* v4.6.2 — .admin-save-row and .admin-save-ok went with the shared Save row.
   .admin-save-err stays: it is still the error style for the integrity panel,
   the audit team/event lists and the license key field. */
.admin-save-err { font-size: var(--th-font-body); color: var(--color-error-text); }
/* ── Statistics tab ────────────────────────────────────────────── */
.admin-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 8px;
}

.admin-stat-card {
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.admin-stat-card__value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.1;
    color: var(--color-main-text);
    font-variant-numeric: tabular-nums;
}

.admin-stat-card__label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

/* ── Reporting → Telemetry contents (restored 4.3.1) ─────────────── */
.telemetry-status-note {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
    padding: 10px 14px;
    margin: 8px 0 16px;
    border-radius: var(--border-radius-large);
    background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-primary-element) 30%, transparent);
    font-size: 13px;
    line-height: 1.4;
}
.telemetry-status-note--off {
    background: color-mix(in srgb, var(--color-success) 10%, transparent);
    border-color: color-mix(in srgb, var(--color-success) 40%, transparent);
}
.telemetry-status-note strong { flex-shrink: 0; }

.telemetry-group {
    margin-top: 20px;
}
.telemetry-group__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-main-text);
    margin: 0 0 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--color-border);
}
.telemetry-group__list {
    display: grid;
    grid-template-columns: minmax(220px, max-content) 1fr;
    column-gap: 24px;
    row-gap: 6px;
    margin: 0;
    font-size: 13px;
}
.telemetry-group__label {
    color: var(--color-text-maxcontrast);
    padding: 4px 0;
    font-weight: normal;
}
.telemetry-group__value {
    color: var(--color-main-text);
    padding: 4px 0;
    margin: 0;
    font-variant-numeric: tabular-nums;
    word-break: break-word;
}
.telemetry-group__inline-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.telemetry-group__inline-list li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}
.telemetry-group__map-key {
    color: var(--color-main-text);
}
.telemetry-group__map-count {
    color: var(--color-text-maxcontrast);
    font-variant-numeric: tabular-nums;
}
.telemetry-group__empty {
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

.admin-telemetry-details {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.admin-telemetry-preview {
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    padding: 12px;
    font-size: var(--th-font-meta);
    font-family: monospace;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
    color: var(--color-main-text);
    max-height: 260px;
    overflow-y: auto;
}

/* ── Maintenance tab ───────────────────────────────────────────── */
.admin-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.admin-error {
    color: var(--color-error-text);
    font-size: 13px;
    padding: 8px 0;
}

.admin-empty {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    padding: 8px 0;
}

/* ── Maintenance panel padding ───────────────────────────────────── */
#tab-panel-maintenance {
    padding: 10px;
}

/* ── Header (replaces NcSettingsSection title) ───────────────────── */
.maint-header {
    margin-bottom: 16px;
}

.maint-header__title {
    font-size: var(--th-font-heading-lg);
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--color-main-text);
}

.maint-header__desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* ── Toolbar ─────────────────────────────────────────────────────── */
.maint-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.maint-search {
    flex: 1;
    min-width: 200px;
    max-width: 300px;
}

.maint-orphan-toggle {
    flex-shrink: 0;
}

.maint-perpage {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    margin-left: auto;
}

.maint-perpage-label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

/* ── Grid ────────────────────────────────────────────────────────── */
.maint-grid {
    width: 100%;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    margin-bottom: 12px;
    font-size: 13px;
}

.maint-grid__head,
.maint-grid__row {
    display: grid;
    /* v4.6.13 — seven columns; `expires` was added between created and
       actions. Description gives up the width: it is the one cell that
       degrades gracefully, because it already ellipsises. */
    grid-template-columns:
        minmax(120px, 1.5fr)   /* name */
        minmax(90px, 1.4fr)    /* description */
        52px                   /* members — narrow, number only */
        minmax(140px, 1.5fr)   /* owner */
        100px                  /* created — fixed, date is short */
        150px                  /* expires — fits a date plus its editor */
        260px;                 /* actions — wide enough for assign form */
    align-items: start;
}

/* An open expiry editor makes the row taller, same as the assign-owner form. */
.maint-grid__row:has(.maint-expiry-form) {
    align-items: start;
}

.maint-grid__head {
    background: var(--color-background-dark);
    border-bottom: 2px solid var(--color-border);
    font-size: var(--th-font-micro);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-maxcontrast);
    align-items: center;
}

.maint-grid__row {
    border-bottom: 1px solid var(--color-border);
    transition: background 0.1s;
    align-items: center;
}

/* When the assign form is open the row needs to stretch to fit it */
.maint-grid__row:has(.maint-assign-form) {
    align-items: start;
}

.maint-grid__row:last-child {
    border-bottom: none;
}

.maint-grid__row:hover {
    background: var(--color-background-hover);
}

/* All cells — header and data — share the same padding so columns align */
.maint-grid__head .maint-grid__cell,
.maint-grid__row .maint-grid__cell {
    padding: 10px 12px;
    overflow: hidden;
}

.maint-grid__cell--members {
    text-align: center;
    padding-left: 4px;
    padding-right: 4px;
}

.maint-grid__cell--actions {
    padding: 6px 8px;
}

/* ── Expiration column (v4.6.13) ─────────────────────────────────── */
.maint-grid__cell--expires {
    padding: 6px 8px;
}

.maint-expiry--na {
    color: var(--color-text-maxcontrast);
}

/* Raw <button> rather than NcButton: this is a table cell that behaves as a
   control, sized to its text and aligned with the plain-text cells beside it.
   NcButton's 44 px minimum would make the Expires column twice the height of
   every other row. SKILLS.md § NcButton is the default lists exactly this
   kind of bespoke row control as a carve-out. */
.maint-expiry-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    max-width: 100%;
    padding: 4px 6px;
    border: 1px solid transparent;
    border-radius: var(--th-radius-control);
    background: transparent;
    color: var(--color-main-text);
    font-size: 13px;
    text-align: start;
    cursor: pointer;
}

.maint-expiry-btn:hover {
    background: var(--color-background-hover);
    border-color: var(--color-border);
}

/* Hover and focus are split so the keyboard ring is never silenced —
   SKILLS.md § Focus visibility standard, "the trap to avoid". */
.maint-expiry-btn:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.maint-expiry-btn--none {
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

/* --color-*-text rather than --color-* per the NC design guideline in
   SKILLS.md: the plain token is a background colour and fails contrast as
   foreground text. */
.maint-expiry-btn--warning {
    color: var(--color-warning-text, #b45309);
    font-weight: 600;
}

.maint-expiry-btn--expired {
    color: var(--color-error-text, var(--color-error));
    font-weight: 600;
}

.maint-expiry-pending {
    display: block;
    margin-top: 2px;
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

.maint-expiry-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

/* One row under the date field. v4.6.17 — was `flex-wrap: wrap` with three
   labelled buttons, which in a column this narrow put each on its own line and
   made the row taller than the rest of the table. Icon-only buttons are ~44px
   each, so three fit; `nowrap` plus `flex: 0 0 auto` is what keeps them there —
   NcButton is a block-level flex box and will otherwise grow. */
.maint-expiry-form__actions {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 4px;
}

/* No :deep() needed: NcButton's root element IS the button, and Vue stamps this
   component's scope attribute onto a child component's root. */
.maint-expiry-form__actions > button {
    flex: 0 0 auto;
}

/* Shared by the All teams date editor and the extension-request popout. */
.maint-date-input {
    width: 100%;
    min-height: 34px;
    padding: 0 8px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--th-radius-control);
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}

.maint-date-input:focus {
    /* NC form-field convention: no outline, primary border on focus, with a
       :focus-visible ring on top. */
    outline: none;
    border-color: var(--color-primary-element);
}

.maint-date-input:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* ── Extension requests (v4.6.16) ────────────────────────────────── */
/* The v4.6.13 queue was a second table below this one. Its grid-template
   override and cell styles went with it; what is left is the banner that
   says who is waiting, and the popout that decides it on the team's own row. */

.maint-req-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
    padding: 8px 12px;
    border-radius: var(--th-radius-card);
    background-color: var(--color-background-hover);
    /* Icon + words carry the state; the tint is not the only signal. */
    color: var(--color-main-text);
}

.maint-req-banner__text {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
}

/* The badge is the control that opens the decision. It keeps the badge's quiet
   type — it is still a status first — and gains a pointer, a hover surface and
   a focus ring. (v4.6.17: no longer a positioning context; the panel it used to
   anchor is a dialog now.) */
.maint-expiry-pending--btn {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-top: 2px;
    padding: 1px 4px;
    border: none;
    border-radius: var(--th-radius-chip);
    background: transparent;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
    cursor: pointer;
}

.maint-expiry-pending--btn:hover {
    background-color: var(--color-background-hover);
    color: var(--color-main-text);
}

.maint-expiry-pending--btn:focus-visible {
    background-color: var(--color-background-hover);
    color: var(--color-main-text);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

/* Dialog body. Sizes step up from the micro type the popout used — that was
   sized to sit inside a table cell, and nothing in a dialog has to. */
.maint-expiry-req__facts {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 6px 16px;
    margin: 0 0 16px;
    font-size: var(--th-font-body);
}

.maint-expiry-req__facts dt {
    color: var(--color-text-maxcontrast);
}

.maint-expiry-req__facts dd {
    margin: 0;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.maint-expiry-req__label {
    display: block;
    margin-bottom: 4px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.maint-expiry-req__note {
    margin-top: 12px;
}

.maint-expiry-req__missing {
    margin: 0;
    font-size: var(--th-font-body);
    color: var(--color-text-maxcontrast);
}

/* ── Cell content ────────────────────────────────────────────────── */
.maint-team-name {
    font-weight: 600;
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* v4.8.0 — tag chips under the team name in the All teams grid. Wraps
   rather than ellipsing: a truncated classification is worse than a taller
   row, and only tagged teams pay the height. */
.maint-team-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
    margin: 3px 0 0;
    padding: 0;
    list-style: none;
}

/* v4.9.4 — the OpenProject project under the name, one line. */
.maint-op-link {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-xs);
    margin: var(--th-space-xs) 0 0;
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

.maint-op-link__name {
    color: var(--color-main-text);
    font-weight: var(--th-font-weight-medium);
}

a.maint-op-link__name:hover,
a.maint-op-link__name:focus-visible {
    text-decoration: underline;
}

a.maint-op-link__name:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--th-radius-chip);
}

/* Words, not only tone (WCAG 1.4.1): the label says "Other instance". */
.maint-op-link__stale {
    padding: 0 var(--th-space-xs);
    border-radius: var(--th-radius-chip);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-weight: var(--th-font-weight-semibold);
}

/* v4.8.15 — .maint-team-tag folded into .maint-class-chip below. It was the
   last remnant of the v4.8.0 tag chips, which 4.8.1 removed; the classification
   chips are the only thing in this row now, so the shape lives on their own
   class rather than on one they inherit from a feature that is gone. */

/* v4.8.15 — .maint-team-tag__dot removed with the last markup that used it.
   The tag chips it belonged to went in 4.8.1 (Track F1 reverted); the <li>
   rendering them was still in the template until this version, guarded by a
   `team.tags` the payload had stopped carrying. The classification chips below
   reuse the .maint-team-tags row, which is what that slot was shaped for. */

/* ── Template and profile chips (v4.8.15, Track F) ───────────────────────
   The base chip is the TEMPLATE's appearance, uncoloured on purpose: a template
   has no per-team state to compare against, so there is no conformance to
   report and a coloured template chip would assert one. Only the two profile
   modifiers below carry colour, and each is paired with a glyph in the markup
   plus a worded aria-label, so nothing here is signalled by colour alone
   (WCAG 1.4.1). Text colours use the *-text tokens per SKILLS.md § NC design
   guidelines; the tinted backgrounds stay under 10% so the chip reads as a
   chip rather than as a filled badge. */
.maint-class-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: var(--th-font-micro);
    line-height: var(--th-line-height-tight);
    font-weight: var(--th-font-weight-medium);
    padding: 0 6px;
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-pill);
    color: var(--color-text-maxcontrast);
    background-color: transparent;
}

/* The red chip is a real button. Everything here is undoing NcButton-adjacent
   global button styling so a control still looks like a chip — the 44 px
   min-height in particular, which is why this is not an NcButton. */
.maint-class-chip--button {
    min-height: 0;
    min-width: 0;
    height: auto;
    margin: 0;
    cursor: pointer;
    font-family: inherit;
    text-align: left;
}

.maint-class-chip--button:hover {
    background-color: color-mix(in srgb, var(--color-error-text) 18%, transparent);
}

/* Split from :hover deliberately — SKILLS.md § Focus visibility standard. A
   shared rule silences the keyboard ring, which is the trap that rule exists
   for and which cost six sites in v4.101.0. */
.maint-class-chip--button:focus-visible {
    background-color: color-mix(in srgb, var(--color-error-text) 18%, transparent);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.maint-class-chip--ok {
    color: var(--color-success-text);
    border-color: var(--color-success-text);
    background-color: color-mix(in srgb, var(--color-success-text) 8%, transparent);
}

.maint-class-chip--err {
    color: var(--color-error-text);
    border-color: var(--color-error-text);
    background-color: color-mix(in srgb, var(--color-error-text) 8%, transparent);
}

.maint-class-chip__glyph {
    flex: 0 0 auto;
    font-weight: var(--th-font-weight-bold);
}

/* ── Profile drift dialog (v4.8.15) ──────────────────────────────────────
   Three columns: what the setting is, what the profile expects, what the team
   has. The observed column is the one that differs, so it carries the weight —
   but it is emphasis on the value that changed, not a second error colour, and
   the reader already knows this is the non-matching list from the title. */
.drift-dialog__lead {
    margin: 0 0 12px;
}

.drift-dialog__grid {
    /* Wide content scrolls inside its own box rather than pushing the dialog
       sideways — the same rule the maint grid follows. */
    overflow-x: auto;
}

.drift-dialog__head,
.drift-dialog__row {
    display: grid;
    grid-template-columns: minmax(160px, 2fr) minmax(80px, 1fr) minmax(80px, 1fr);
    gap: 8px;
    padding: 6px 0;
    align-items: start;
}

.drift-dialog__head {
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-text-maxcontrast);
    border-bottom: 1px solid var(--color-border);
}

.drift-dialog__row + .drift-dialog__row {
    border-top: 1px solid var(--color-border);
}

.drift-dialog__observed {
    font-weight: var(--th-font-weight-semibold);
}

.drift-dialog__note {
    display: block;
    margin-top: 2px;
    font-size: var(--th-font-micro);
    line-height: var(--th-line-height-body);
    color: var(--color-text-maxcontrast);
}

.drift-dialog__foot {
    margin: 16px 0 0;
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
    color: var(--color-text-maxcontrast);
}

.maint-team-desc {
    color: var(--color-text-maxcontrast);
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.maint-owner-name {
    display: flex;
    flex-direction: column;
    gap: 1px;
    overflow: hidden;
}

.maint-owner-uid {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.maint-no-owner {
    color: var(--color-warning-text);
    font-weight: 500;
    font-size: var(--th-font-meta);
}

/* ── Row actions — icon-only buttons ─────────────────────────────── */
.maint-row-actions {
    display: flex;
    gap: 4px;
    align-items: center;
}

/* ── Assign-owner inline form ────────────────────────────────────── */
.maint-assign-form {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 4px 0;
}

/* ── Owner results dropdown ──────────────────────────────────────── */
.admin-owner-results {
    list-style: none;
    margin: 0;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    max-height: 180px;
    overflow-y: auto;
    position: relative;
    z-index: 10;
}

.admin-owner-result {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid var(--color-border-dark);
}

.admin-owner-result:last-child {
    border-bottom: none;
}

.admin-owner-result:hover {
    background: var(--color-background-hover);
}

.admin-owner-result__uid {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    margin-left: 4px;
}

/* ── Pagination ──────────────────────────────────────────────────── */
.maint-pagination {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    padding: 4px 0 8px;
}

.maint-page-info {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    padding: 0 8px;
    white-space: nowrap;
}

.admin-section-hint {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 4px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ── Inline label + number field (v4.6.13) ───────────────────────── */
.admin-inline-field {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.admin-inline-field__label {
    font-size: var(--th-font-body);
}

.admin-number-input {
    width: 96px;
    min-height: 34px;
    padding: 0 8px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--th-radius-control);
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
}

.admin-number-input:focus {
    /* NC form-field convention — see the note on .maint-date-input. */
    outline: none;
    border-color: var(--color-primary-element);
}

.admin-number-input:focus-visible {
    box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* ── Membership integrity ─────────────────────────────────────────── */
.maint-divider {
    height: 1px;
    background: var(--color-border);
    margin: 40px 0 24px;
}

.maint-integrity-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
    padding: 0 16px;
}

.maint-integrity-result {
    padding: 0 16px;
}

.maint-integrity-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding: 12px 16px;
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    margin-bottom: 16px;
    font-size: var(--th-font-body);
}

.maint-integrity-summary__item--ok strong {
    color: var(--color-success-text);
}

.maint-integrity-summary__item--bad strong {
    color: var(--color-error-text);
}

.maint-integrity-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.maint-integrity-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 16px;
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-warning);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
}

.maint-integrity-row__info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
}

.maint-integrity-row__name {
    font-size: var(--th-font-body);
    font-weight: 500;
    color: var(--color-main-text);
}

.maint-integrity-row__detail {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

/* App-claimed teams (v4.5.37) — information, not a warning. The rows
   deliberately drop .maint-integrity-row's amber left edge: these teams are
   healthy, and an alert colour is what made twelve of them look broken. */
.maint-integrity-claimed {
    margin-top: 24px;
}

.maint-integrity-claimed__title {
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
    margin: 0 0 4px;
}

.maint-integrity-claimed__desc {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 0 0 12px;
    max-width: 70ch;
}

.maint-integrity-claimed .maint-integrity-row {
    border-left: 3px solid var(--color-border);
}

/* ─────────────────────────────────────────────────────────────────
   Audit tab
   ───────────────────────────────────────────────────────────────── */

.audit-banner {
    border-radius: var(--border-radius);
    padding: 10px 14px;
    margin-bottom: 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
}

.audit-banner--warn {
    background: var(--color-warning);
    color: var(--color-main-background);
}

.audit-banner--info {
    background: var(--color-background-hover);
    border: 1px solid var(--color-border);
    color: var(--color-main-text);
}

.audit-banner__head {
    display: flex;
    align-items: center;
    gap: 6px;
}

.audit-retention {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    padding: 14px 16px;
    margin-bottom: 18px;
    background: var(--color-background-hover);
}

.audit-retention__label {
    font-weight: 600;
    display: block;
    margin-bottom: 8px;
}

.audit-retention__controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.audit-retention__controls .input-field,
.audit-retention__controls .input-field input {
    max-width: 120px;
}

.audit-retention__suffix {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    padding-right: 6px;
}

.audit-controls {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 18px;
}

.audit-controls__row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.audit-controls__label {
    min-width: 100px;
    font-size: 13px;
    font-weight: 600;
}

.audit-controls__team-select,
.audit-controls__filter-select {
    min-width: 280px;
    max-width: 400px;
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}

.audit-controls__date {
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}

.audit-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 40px 20px;
    color: var(--color-text-maxcontrast);
}

.audit-events__loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.audit-events {
    margin-top: 8px;
}

.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.audit-table thead th {
    text-align: left;
    padding: 10px 12px;
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-hover);
}

.audit-table tbody td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}

.audit-table tbody tr:hover {
    background: var(--color-background-hover);
}

.audit-table__when {
    white-space: nowrap;
    color: var(--color-text-maxcontrast);
    font-variant-numeric: tabular-nums;
}

.audit-table__event {
    font-family: monospace;
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
}

.audit-table__target {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: monospace;
    font-size: var(--th-font-meta);
}

.audit-table__details {
    max-width: 360px;
}

.audit-table__details code {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    word-break: break-word;
}

/* ── Archive tab ─────────────────────────────────────────────────────────── */
.archive-admin {
    display: flex;
    flex-direction: column;
    gap: 20px;
    max-width: 720px;
}

.archive-admin__heading {
    font-size: var(--th-font-heading);
    font-weight: 500;
    margin: 0;
    color: var(--color-main-text);
}

.archive-admin__heading--mt {
    margin-top: 8px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border);
}

.archive-admin__fieldset {
    border: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.archive-admin__legend {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    margin-bottom: 8px;
}

.archive-admin__field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.archive-admin__field--disabled {
    opacity: 0.5;
}

.archive-admin__label {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-main-text);
}

.archive-admin__input {
    width: 100%;
    max-width: 480px;
    padding: 8px 12px;
    border: 1px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
}

.archive-admin__input--short {
    max-width: 140px;
}

.archive-admin__help {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
    line-height: 1.4;
}

.archive-admin__actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.archive-admin__ok {
    font-size: 13px;
    color: var(--color-success-text);
}

.archive-admin__err {
    font-size: 13px;
    color: var(--color-error-text);
}

.archive-admin__toolbar {
    display: flex;
    gap: 8px;
    align-items: center;
}

.archive-admin__empty {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    padding: 8px 0;
}

.archive-admin__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.archive-admin__table-caption {
    text-align: left;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-bottom: 6px;
    caption-side: top;
}

.archive-admin__table th {
    text-align: left;
    padding: 8px 10px;
    font-weight: 600;
    border-bottom: 2px solid var(--color-border);
    white-space: nowrap;
}

.archive-admin__table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
}

.archive-admin__row-actions {
    display: flex;
    gap: 6px;
}

.archive-admin__status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: var(--th-font-micro);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* v3.100.16: status pills use NC theme fill + matching -text token
   per SKILLS.md § "State-coloured backgrounds" (were --color-*-bg
   with hardcoded hex fallbacks; --color-*-bg is not a canonical NC
   token, only ever fell back to the pinned hex, and the pinned hex
   didn't follow the dark theme). */
.archive-admin__status--pending {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.archive-admin__status--completed {
    background: var(--color-success);
    color: var(--color-success-text);
}

.archive-admin__status--restored {
    background: var(--color-info);
    color: var(--color-info-text);
}

.archive-admin__status--failed {
    background: var(--color-error);
    color: var(--color-error-text);
}

/* Inline error detail row — v3.100.16: NC theme tokens (was raw hex). */
.archive-admin__error-row td {
    padding: 0;
    border-bottom: 2px solid var(--color-error);
}

.archive-admin__error-panel {
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: var(--color-error);
    border-left: 4px solid var(--color-error);
    padding: 14px 16px;
    font-size: 13px;
    color: var(--color-error-text);
}

.archive-admin__error-panel strong {
    font-size: var(--th-font-body);
    color: var(--color-error-text);
}

.archive-admin__error-reason {
    display: block;
    font-family: monospace;
    font-size: var(--th-font-micro);
    background: rgba(0, 0, 0, 0.06);
    border-radius: 3px;
    padding: 6px 8px;
    word-break: break-word;
    color: var(--color-error-text);
}

.archive-admin__error-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}

/* ── Team Folders delegation status ──────────────────────────────────────── */
.admin-gf-status {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}

.admin-gf-status__row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    flex-wrap: wrap;
}

.admin-gf-status__indicator {
    flex-shrink: 0;
    font-weight: 700;
    font-size: var(--th-font-body);
    width: 20px;
    text-align: center;
    margin-top: 1px;
}

.admin-gf-status__indicator--ok   { color: var(--color-success-text); }
.admin-gf-status__indicator--warn { color: var(--color-warning-text); }

.admin-gf-status__label {
    font-weight: 500;
    color: var(--color-main-text);
}

.admin-gf-status__hint {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    width: 100%;
    padding-left: 28px;
    margin-top: 2px;
}

.admin-gf-status__summary {
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: var(--border-radius);
    font-size: 13px;
}

.admin-gf-status__summary--ok {
    background-color: var(--color-success-background);
    color: var(--color-success-text);
    border: 1px solid var(--color-success);
}

.admin-gf-status__summary--warn {
    background-color: var(--color-warning-background);
    color: var(--color-warning-text);
    border: 1px solid var(--color-warning);
}

/* ── First-run setup checklist (v4.4.4) ──
   Mirrors .admin-gf-status conventions so the two read as one system: same
   20px indicator gutter, same 28px hint indent, same --color-*-text tokens
   per SKILLS.md § NC design guidelines. */
.admin-setup {
    margin: 0 0 24px;
    padding: 16px 20px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, var(--border-radius));
    background-color: var(--color-background-hover);
}

.admin-setup__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.admin-setup__title {
    margin: 0;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
    color: var(--color-main-text);
}

.admin-setup__intro {
    margin: 4px 0 14px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    max-width: 68ch;
}

.admin-setup__rows {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.admin-setup__row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    flex-wrap: wrap;
}

.admin-setup__indicator {
    flex-shrink: 0;
    font-weight: 700;
    font-size: var(--th-font-body);
    width: 20px;
    text-align: center;
    margin-top: 1px;
}

.admin-setup__indicator--ok   { color: var(--color-success-text); }
.admin-setup__indicator--warn { color: var(--color-warning-text); }
.admin-setup__indicator--info { color: var(--color-text-maxcontrast); }

.admin-setup__label {
    font-weight: 500;
    color: var(--color-main-text);
}

.admin-setup__value {
    color: var(--color-text-maxcontrast);
}

.admin-setup__hint {
    width: 100%;
    padding-left: 28px;
    margin-top: 2px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    max-width: 76ch;
}

.admin-setup__restore {
    margin: 0 0 16px;
}

/* ── Ghost member cleanup tab ── */
.ghost-result-summary {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin: 0 0 16px;
}

.ghost-grid {
    display: grid;
    grid-template-columns: 200px 1fr auto;
    gap: 0;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    overflow: hidden;
}

.ghost-grid__head {
    display: contents;
}

.ghost-grid__head .ghost-grid__cell {
    background-color: var(--color-background-dark);
    font-weight: 600;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border);
}

.ghost-grid__row {
    display: contents;
}

.ghost-grid__row:last-child .ghost-grid__cell {
    border-bottom: none;
}

.ghost-grid__cell {
    padding: 12px 14px;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: flex-start;
    gap: 8px;
    flex-wrap: wrap;
}

.ghost-grid__cell--uid {
    align-items: center;
    flex-wrap: nowrap;
}

.ghost-uid {
    font-family: var(--font-face-monospace, monospace);
    font-size: 13px;
    font-weight: 500;
}

.ghost-deleted-badge {
    display: inline-block;
    background-color: var(--color-error-background);
    color: var(--color-error-text);
    font-size: var(--th-font-micro);
    padding: 1px 6px;
    border-radius: 10px;
    border: 1px solid var(--color-error);
    white-space: nowrap;
    flex-shrink: 0;
}

.ghost-team-list {
    list-style: none;
    margin: 0;
    padding: 0;
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ghost-team-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}

.ghost-team-name {
    font-size: 13px;
    flex: 1;
}

/* ─────────────────────────────────────────────────────────────────
   Audit tab — Find teams for a user
   ───────────────────────────────────────────────────────────────── */

.audit-user-lookup {
    position: relative;
    margin-bottom: 14px;
    max-width: 480px;
}

.audit-user-lookup__results {
    margin-top: 4px;
}

.audit-user-selected {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 10px 14px;
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    border: 1px solid var(--color-border);
    margin-bottom: 14px;
}

.audit-user-selected__label {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

/* Override the base maint-grid 6-column template (which would otherwise
   give Role the 52px "members" slot and Membership the 100px "created"
   slot, squashing chip content). Target __head and __row directly because
   that's where the parent .maint-grid CSS sets display: grid. */
.audit-user-grid.maint-grid > .maint-grid__head,
.audit-user-grid.maint-grid > .maint-grid__row {
    grid-template-columns:
        44px                    /* checkbox */
        minmax(160px, 2.2fr)    /* team name + optional description */
        110px                   /* role chip */
        minmax(170px, 1.4fr)    /* owner */
        minmax(150px, 1.8fr);   /* membership / source chip */
}

.audit-user-grid__cell--check {
    display: flex;
    align-items: center;
    justify-content: center;
}

.audit-user-grid__desc {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    margin-top: 2px;
}

.audit-user-grid__role {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: var(--th-font-meta);
    font-weight: 600;
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

.audit-user-grid__role--owner {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.audit-user-grid__role--admin {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.audit-user-grid__source {
    display: inline-block;
    font-size: var(--th-font-meta);
    padding: 2px 8px;
    border-radius: 12px;
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

.audit-user-grid__source--direct {
    background: var(--color-success);
    color: var(--color-success-text);
}

.audit-user-grid__source--inherited {
    background: var(--color-background-darker, var(--color-background-dark));
    color: var(--color-text-maxcontrast);
}

.audit-user-grid__note--warn {
    color: var(--color-warning-text);
    background: var(--color-warning);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: var(--th-font-meta);
}

.audit-user-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 14px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
}

.audit-user-actions__summary {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

/* ── v4.3.0 Compliance tab — compact rows ───────────────────────────── */
/* v4.2.9 — label (subject) is plain text OUTSIDE the pill; only the result
   ("Compliant", "Off") sits inside the coloured pill. Fixed-width actions
   area at the row end guarantees the `i` icon lines up across rows even
   when a row doesn't have a refresh button. Refresh sits AFTER the i in
   the actions area, in its own fixed 44 px slot that's reserved (but empty)
   on rows without a refresh action. */
/* v4.4.14 — right-aligned "Save as PDF" button above the rows. Sits in
   its own line rather than crowding the section heading, so a long
   translated section title never has to compete with a control. */
.compliance-export {
    display: flex;
    justify-content: flex-end;
    margin: 0 0 12px;
}
.compliance-rows {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.compliance-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 2px 0;
}
.compliance-row__label {
    font-size: var(--th-font-body, 14px);
    font-weight: 500;
    color: var(--color-main-text);
    flex: 0 0 auto;
    min-width: 170px;
}
.compliance-row__spacer {
    flex: 1 1 auto;
}
.compliance-row__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
}
.compliance-row__refresh-slot {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 44px;
}
.integrity-pill--warn {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

/* ── v4.2.0 Compliance tab — code integrity ─────────────────────────── */
.integrity-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    color: var(--color-text-maxcontrast);
}
.integrity-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.integrity-status-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.integrity-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
}
.integrity-pill__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.integrity-pill--ok {
    background: var(--color-success);
    color: var(--color-success-text);
}
.integrity-pill--err {
    background: var(--color-error);
    color: var(--color-error-text);
}
.integrity-pill--unknown {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
/* v4.8.0 — "nothing to report", as distinct from --unknown's "could not
   find out". Used by the classification row on an instance that has simply
   not adopted tagging: a state to notice, not a failure to fix. */
.integrity-pill--info {
    background: var(--color-background-dark);
    color: var(--color-main-text);
}
.integrity-detail {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 16px;
    margin: 0;
    font-size: var(--th-font-body);
}
.integrity-detail dt {
    color: var(--color-text-maxcontrast);
    font-weight: 500;
}
.integrity-detail dd {
    margin: 0;
    word-break: break-word;
}
.integrity-banner {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
    border-radius: var(--th-radius-card, 10px);
    font-size: var(--th-font-body);
}
.integrity-banner--info {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
.integrity-banner--err {
    background: var(--color-error);
    color: var(--color-error-text);
}
.integrity-list summary {
    cursor: pointer;
    padding: 6px 0;
    font-weight: 600;
}
.integrity-list summary:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: 4px;
}
.integrity-list__trunc {
    color: var(--color-text-maxcontrast);
    font-weight: 400;
    margin-left: 6px;
}
.integrity-list ul {
    list-style: none;
    padding: 4px 0 4px 8px;
    margin: 0;
    max-height: 220px;
    overflow-y: auto;
    font-family: var(--font-face-monospace, monospace);
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
}
.integrity-list li {
    padding: 2px 0;
    word-break: break-all;
}

/* ── v3.100.0 Licensing tab ─────────────────────────────────────────── */
.license-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    color: var(--color-text-maxcontrast);
}
.license-status-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.license-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
}
.license-pill__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.license-pill--ok {
    background: var(--color-success);
    color: var(--color-success-text);
}
/* v3.100.16: NC theme tokens (were --color-warning-text with a #fff
   fallback and --color-error-text with color: #fff). Full-saturation
   error fill + matching -text pair per SKILLS.md. */
.license-pill--warn {
    background: var(--color-warning);
    color: var(--color-warning-text);
}
.license-pill--err {
    background: var(--color-error);
    color: var(--color-error-text);
}
.license-trial-flag {
    padding: 2px 8px;
    border-radius: 10px;
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.license-detail {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 6px 16px;
    margin: 8px 0 20px;
    font-size: var(--th-font-body);
}
.license-detail dt {
    color: var(--color-text-maxcontrast);
    font-weight: 500;
}
.license-detail dd {
    margin: 0;
}
.license-over {
    color: var(--color-error-text);
    font-weight: 600;
}
.license-uuid,
.license-key-row {
    margin: 16px 0;
    padding: 12px 14px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}
.license-uuid__label,
.license-key-row__label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    font-size: 13px;
}
.license-uuid__value {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.license-uuid__value code {
    background: var(--color-main-background);
    padding: 3px 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
}
.license-uuid__hint {
    margin: 6px 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}
.license-copied {
    color: var(--color-success-text);
    font-size: var(--th-font-meta);
    font-weight: 600;
}
.license-key-row__input {
    width: 100%;
    min-height: 90px;
    padding: 8px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-family: monospace;
    font-size: var(--th-font-meta);
    resize: vertical;
    box-sizing: border-box;
}
.license-key-row__actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}
.license-links {
    display: flex;
    gap: 16px;
    margin-top: 12px;
    font-size: var(--th-font-body);
}
.license-links a {
    color: var(--color-primary-element);
    text-decoration: none;
}
.license-links a:hover {
    text-decoration: underline;
}
/* v3.100.2 — button styled to sit visually next to the "Buy" link but
   read as an action. Disabled when a license is already installed. */
.license-trial-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--color-primary-element);
    background: transparent;
    border: 0;
    padding: 0;
    font: inherit;
    cursor: pointer;
}
.license-trial-button:hover:not(:disabled) {
    text-decoration: underline;
}
.license-trial-button:disabled {
    color: var(--color-text-maxcontrast);
    cursor: not-allowed;
}
/* mailto affordance: same visual weight as the anchor links in
   .license-links so the three actions read as siblings, not primary +
   secondary. */
.license-trial-mailto {
    color: var(--color-primary-element);
    text-decoration: none;
}
.license-trial-mailto:hover { text-decoration: underline; }
.license-trial-hint {
    margin-top: 4px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* v4.10.0 — the case for a licence, unlicensed instances only. A quiet card
   (the same ground the info banner uses) so it reads as information beside
   the status pill, not as an alert; the one primary button on the tab is the
   quote request, which is the action we want from an unlicensed admin. */
.license-pitch {
    margin-top: var(--th-space-md);
    padding: var(--th-space-md) var(--th-space-lg);
    border: 1px solid var(--color-border);
    border-radius: var(--th-radius-card);
    background: var(--color-background-hover);
}
.license-pitch__title {
    margin: 0 0 var(--th-space-sm);
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-bold);
}
.license-pitch__list {
    margin: 0;
    padding-inline-start: var(--th-space-lg);
    font-size: var(--th-font-body);
}
.license-pitch__list li {
    margin-bottom: var(--th-space-xs);
}
/* The label and its sentence sit on one line; the dash between them is an
   aria-hidden span in the template — a layout glyph, not a word — so a
   translator never has to place it. */
.license-pitch__sep {
    margin: 0 var(--th-space-xxs);
    color: var(--color-text-maxcontrast);
}
.license-pitch__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-md);
    margin-top: var(--th-space-md);
}
/* The plain link only. NcButton with `href` renders as an <a> too, and a bare
   `a` selector here painted its label in the primary colour on the primary
   fill — the button read as an empty pill (Justin, 2026-09-18). */
.license-pitch__link {
    color: var(--color-primary-element);
    text-decoration: none;
}
.license-pitch__link:hover,
.license-pitch__link:focus-visible {
    text-decoration: underline;
}
.license-pitch__hint {
    margin: var(--th-space-xs) 0 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Seat-overage banner. Sits directly under the status pill so it's the
   first thing an admin sees on the tab. Warn = orange tint; lock =
   red tint. Both keep enough contrast on dark theme via NC vars. */
.license-seat-banner {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 14px;
    border-radius: var(--border-radius);
    margin: 12px 0;
    font-size: var(--th-font-body);
    line-height: 1.4;
    border: 1px solid transparent;
}
.license-seat-banner strong { font-weight: 600; }
.license-seat-banner--warn {
    background: var(--color-warning-hover);
    color: var(--color-warning-text);
    border-color: var(--color-warning);
}
.license-seat-banner--lock {
    background: var(--color-error-hover);
    color: var(--color-error-text);
    border-color: var(--color-error);
}

</style>

