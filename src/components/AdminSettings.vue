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

            <NcSettingsSection
                :name="t('teamhub', 'Team creation wizard')"
                :description="t('teamhub', 'This text is shown at the top of the Create new team dialog. Leave empty to show no description.')">
                <NcTextArea
                    v-model="form.wizardDescription"
                    :placeholder="t('teamhub', 'e.g. Fill in the details below to create a new team.')"
                    :rows="3" />
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
                            ×
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

            <!-- ── Group Folders integration status ────────────────────────── -->
            <NcSettingsSection
                :name="t('teamhub', 'Group Folders integration')"
                :description="t('teamhub', 'When Group Folders is installed and properly configured, TeamHub will automatically create a Group Folder for each new team instead of a shared personal folder. Group Folders are owned by the server, not by individual users.')">

                <div class="admin-gf-status">
                    <div class="admin-gf-status__row">
                        <span
                            class="admin-gf-status__indicator"
                            :class="gfDelegation.groupFoldersInstalled ? 'admin-gf-status__indicator--ok' : 'admin-gf-status__indicator--warn'"
                            aria-hidden="true">
                            {{ gfDelegation.groupFoldersInstalled ? '✓' : '✗' }}
                        </span>
                        <span class="admin-gf-status__label">
                            {{ t('teamhub', 'Group Folders app installed') }}
                        </span>
                        <span v-if="!gfDelegation.groupFoldersInstalled" class="admin-gf-status__hint">
                            {{ t('teamhub', 'Install the Group Folders app to enable automatic team folder creation.') }}
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
                        {{ t('teamhub', 'Group Folders is correctly configured. New teams will automatically get a Group Folder.') }}
                    </p>
                    <p
                        v-else-if="gfDelegation.groupFoldersInstalled"
                        class="admin-gf-status__summary admin-gf-status__summary--warn">
                        {{ t('teamhub', 'Group Folders is installed but not fully configured. New teams will fall back to shared personal folders until the issues above are resolved.') }}
                    </p>
                    <p
                        v-else
                        class="admin-gf-status__summary">
                        {{ t('teamhub', 'Group Folders is not installed. New teams will use shared personal folders.') }}
                    </p>
                </div>
            </NcSettingsSection>
        </div>
        <div
            v-show="activeTab === 'invitations'"
            id="tab-panel-invitations"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Allowed invite types')"
                :description="t('teamhub', 'Choose which types of accounts team admins can invite to a team.')">
                <div class="admin-checks">
                    <NcCheckboxRadioSwitch
                        :model-value="true"
                        :disabled="true"
                        type="checkbox">
                        {{ t('teamhub', 'Local users') }}
                        <template #description>{{ t('teamhub', 'Always enabled — local Nextcloud accounts') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteGroup"
                        type="checkbox">
                        {{ t('teamhub', 'Groups') }}
                        <template #description>{{ t('teamhub', 'Add all members of a Nextcloud group at once') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteCircle"
                        type="checkbox">
                        {{ t('teamhub', 'Teams') }}
                        <template #description>{{ t('teamhub', 'Add another team as a sub-team member — all its members gain access') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteEmail"
                        type="checkbox">
                        {{ t('teamhub', 'Email addresses') }}
                        <template #description>{{ t('teamhub', 'Invite external people by email (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        v-model="inviteFederated"
                        type="checkbox">
                        {{ t('teamhub', 'Federated users') }}
                        <template #description>{{ t('teamhub', 'Invite users from other Nextcloud instances (requires Circles federation)') }}</template>
                    </NcCheckboxRadioSwitch>
                </div>
            </NcSettingsSection>
        </div>

        <!-- ── Tab: Integrations ─────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'integrations'"
            id="tab-panel-integrations"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Presence module')"
                :description="t('teamhub', 'When enabled, team admins can activate a Presence tab for their team and members can set their weekly schedule. When disabled, all presence UI is hidden across the app.')">
                <NcCheckboxRadioSwitch
                    :model-value="form.presenceModuleEnabled"
                    type="switch"
                    :aria-label="t('teamhub', 'Enable presence module for all teams')"
                    @update:model-value="form.presenceModuleEnabled = $event; if (!$event && activeTab === 'presence') { activeTab = 'integrations' } save()">
                    {{ form.presenceModuleEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                </NcCheckboxRadioSwitch>
            </NcSettingsSection>

            <NcSettingsSection
                :name="t('teamhub', 'IntraVox integration')"
                :description="t('teamhub', 'When IntraVox is enabled for a team, TeamHub creates a page at this path inside IntraVox. Use the format language/folder (e.g. en/teamhub or nl/teamhub). The folder must already exist in IntraVox.')">
                <div class="admin-select-row">
                    <NcTextField
                        v-model="form.intravoxParentPath"
                        :label="t('teamhub', 'IntraVox parent path')"
                        :placeholder="t('teamhub', 'e.g. en/teamhub')"
                        style="max-width: 300px;" />
                </div>
                <p class="admin-section-hint">
                    {{ t('teamhub', 'Team pages will be created at: IntraVox / {path} / team-name', { path: form.intravoxParentPath || 'en/teamhub' }) }}
                </p>
            </NcSettingsSection>

            <NcSettingsSection
                :name="t('teamhub', 'RoomVox integration')"
                :description="t('teamhub', 'Paste a RoomVox API token (rvx_...) here to let TeamHub book meeting rooms when a user picks one in the meeting wizard. Create the token in RoomVox under Settings → API Tokens; it needs the “book” scope. The token is stored encrypted in app configuration and never returned to the browser.')">
                <div class="admin-select-row">
                    <NcTextField
                        v-model="form.roomvoxApiToken"
                        type="password"
                        :label="t('teamhub', 'RoomVox API token')"
                        :placeholder="form.roomvoxTokenConfigured ? t('teamhub', '••••••••• (configured — leave empty to keep)') : 'rvx_…'"
                        style="max-width: 400px;" />
                </div>
                <p class="admin-section-hint" v-if="form.roomvoxTokenConfigured">
                    {{ t('teamhub', 'A token is currently configured. Leave the field empty to keep it, paste a new value to replace it, or type __CLEAR__ to remove it.') }}
                </p>
                <p class="admin-section-hint" v-else>
                    {{ t('teamhub', 'No token configured yet. Without one the meeting wizard cannot book rooms via RoomVox even if RoomVox is installed.') }}
                </p>
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
                :name="t('teamhub', 'Usage statistics')"
                :description="t('teamhub', 'TeamHub can send anonymous usage data to help improve the app. No URLs, hostnames, or user data are ever included — only an anonymous UUID and aggregate counts.')">

                <div v-if="telemetryLoading" class="admin-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <template v-else>
                    <NcCheckboxRadioSwitch
                        :model-value="telemetry.enabled"
                        type="switch"
                        @update:model-value="toggleTelemetry">
                        {{ t('teamhub', 'Send daily anonymous usage report') }}
                    </NcCheckboxRadioSwitch>

                    <div v-if="telemetry.enabled" class="admin-telemetry-details">
                        <p class="admin-section-hint">
                            {{ t('teamhub', 'Reports are sent once per day to:') }}
                            <code>{{ telemetry.report_url }}</code>
                        </p>
                        <p class="admin-section-hint">{{ t('teamhub', 'Preview of what will be sent:') }}</p>
                        <pre class="admin-telemetry-preview">{{ JSON.stringify(telemetry.preview, null, 2) }}</pre>
                    </div>
                    <p v-else class="admin-section-hint">
                        {{ t('teamhub', 'Usage reporting is disabled. No data is sent.') }}
                    </p>
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
                    {{ t('teamhub', 'Scans every user-created team for system bits that should never appear on a user team (CFG_SINGLE, CFG_SYSTEM, CFG_NO_OWNER, CFG_HIDDEN, CFG_BACKEND, CFG_APP). These bits make Circles treat the team as system-managed, hiding it from listings and blocking edits. Use Repair to reset a team\'s config to clean defaults.') }}
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
             Audit tab
             ───────────────────────────────────────────────────────────────── -->
        <NcSettingsSection
            v-show="activeTab === 'audit'"
            id="tab-panel-audit"
            role="tabpanel"
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
                <div class="audit-retention__controls">
                    <NcTextField
                        v-model="auditRetentionInput"
                        type="number"
                        :min="auditRetention.min"
                        :max="auditRetention.max"
                        :label="t('teamhub', 'Days')"
                        :label-visible="false"
                        :disabled="auditRetentionSaving"
                        @input="auditRetentionInput = $event.target.value" />
                    <span class="audit-retention__suffix">{{ t('teamhub', 'days') }}</span>
                    <NcButton
                        variant="primary"
                        :disabled="auditRetentionSaving || !canSaveRetention"
                        @click="saveAuditRetention">
                        <template #icon>
                            <NcLoadingIcon v-if="auditRetentionSaving" :size="18" />
                            <ContentSave v-else :size="18" />
                        </template>
                        {{ t('teamhub', 'Save') }}
                    </NcButton>
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

                <!-- Save button for archive settings -->
                <div class="archive-admin__actions">
                    <NcButton
                        variant="primary"
                        :disabled="archiveSettingsSaving"
                        @click="saveArchiveSettings">
                        <template #icon>
                            <NcLoadingIcon v-if="archiveSettingsSaving" :size="18" />
                            <ContentSave v-else :size="18" />
                        </template>
                        {{ archiveSettingsSaving ? t('teamhub', 'Saving…') : t('teamhub', 'Save archive settings') }}
                    </NcButton>
                    <span v-if="archiveSettingsSaved" class="archive-admin__ok">
                        ✓ {{ t('teamhub', 'Archive settings saved') }}
                    </span>
                    <span v-if="archiveSettingsError" class="archive-admin__err">
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
                            <td>{{ row.archivedBy }}</td>
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

        <!-- ── Save row — only for settings tabs, not statistics/maintenance/audit/archive ─ -->
        <div v-show="!(['statistics','maintenance','audit','archive'].includes(activeTab))" class="admin-save-row">
            <NcButton
                variant="primary"
                :disabled="saving"
                @click="save">
                <template #icon>
                    <NcLoadingIcon v-if="saving" :size="18" />
                    <ContentSave v-else :size="18" />
                </template>
                {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save settings') }}
            </NcButton>
            <span v-if="saved" class="admin-save-ok">✓ {{ t('teamhub', 'Settings saved') }}</span>
            <span v-if="saveError" class="admin-save-err">{{ saveError }}</span>
        </div>

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
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
    NcSettingsSection, NcButton, NcLoadingIcon,
    NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
} from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import EmailSendIcon from 'vue-material-design-icons/EmailArrowRight.vue'
import MessageTextIcon from 'vue-material-design-icons/MessageText.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AccountEditIcon from 'vue-material-design-icons/AccountEdit.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import AccountOffIcon from 'vue-material-design-icons/AccountOff.vue'
import AccountRemoveIcon from 'vue-material-design-icons/AccountRemove.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'

// Presence module — Session B1 / v3.42.0 admin sub-panels.
import PresenceTypesManager     from './PresenceTypesManager.vue'
import PresenceLocationsManager from './PresenceLocationsManager.vue'
import PresenceHolidaysManager  from './PresenceHolidaysManager.vue'

export default {
    name: 'AdminSettings',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon,
        NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
        ContentSave, AccountGroup, AccountPlusIcon, EmailSendIcon, MessageTextIcon, PuzzleIcon,
        ChartBarIcon, WrenchIcon, DeleteIcon, AccountEditIcon, ShieldCheckIcon, DownloadIcon, RefreshIcon, RestoreIcon,
        InformationOutline, ArchiveIcon, AccountOffIcon, AccountRemoveIcon, MagnifyIcon,
        OfficeBuildingIcon,
        PresenceTypesManager, PresenceLocationsManager, PresenceHolidaysManager,
    },
    data() {
        return {
            activeTab: 'creation',
            loading: true,
            saving: false,
            saved: false,
            saveError: null,
            form: {
                wizardDescription: '',
                pinMinLevel: 'moderator',
                intravoxParentPath: 'en/teamhub',
                presenceModuleEnabled: false,
                // RoomVox: token is write-only. roomvoxTokenConfigured
                // reflects whether one is currently stored (returned from
                // the load endpoint as a boolean); roomvoxApiToken is the
                // write buffer for the input field — empty means "don't
                // change the stored value".
                roomvoxApiToken: '',
                roomvoxTokenConfigured: false,
            },
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
            // Group Folders delegation status (loaded from admin settings API)
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
            // Config integrity scan
            configCheck: null,         // { issues: [{id, name, config, badBits}] }
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
                'member.joined', 'member.left', 'member.removed', 'member.level_changed',
                'invite.sent',
                'join.requested', 'join.approved', 'join.rejected',
                'file.created', 'file.edited', 'file.deleted',
                'share.created', 'share.permissions_changed', 'share.deleted',
            ],
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
                { id: 'invitations',   label: this.t('teamhub', 'Invitations'),   icon: 'EmailSendIcon'   },
                { id: 'integrations',  label: this.t('teamhub', 'Integrations'),  icon: 'PuzzleIcon'      },
                { id: 'statistics',    label: this.t('teamhub', 'Statistics'),    icon: 'ChartBarIcon'    },
                { id: 'maintenance',   label: this.t('teamhub', 'Maintenance'),   icon: 'WrenchIcon'      },
                { id: 'audit',         label: this.t('teamhub', 'Audit'),          icon: 'ShieldCheckIcon' },
                { id: 'archive',       label: this.t('teamhub', 'Archive'),        icon: 'ArchiveIcon'     },
            ]
            // Presence module tab only visible when the module is enabled.
            if (this.form.presenceModuleEnabled) {
                list.splice(5, 0, { id: 'presence', label: this.t('teamhub', 'Presence module'), icon: 'OfficeBuildingIcon' })
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

        /**
         * Only external (non-builtin) integrations.
         * Built-in NC apps (Talk, Files, Calendar, Deck) are seeded automatically
         * into the registry as is_builtin=true. They did NOT register via the
         * integration API and must not appear in this admin list.
         */
        externalIntegrations() {
            return this.integrations.filter(i => !i.is_builtin)
        },

        teamsTotalPages() {
            return Math.max(1, Math.ceil(this.teamsTotal / this.teamsPerPage))
        },

        auditEventsTotalPages() {
            return Math.max(1, Math.ceil(this.auditEventsTotal / this.auditEventsPerPage))
        },

        canSaveRetention() {
            const n = parseInt(this.auditRetentionInput, 10)
            if (isNaN(n)) return false
            if (n < this.auditRetention.min || n > this.auditRetention.max) return false
            return n !== this.auditRetention.retention_days
        },
    },
    watch: {
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
            if (tab === 'audit') {
                if (!this.auditRetentionLoaded) {
                    this.loadAuditRetention()
                }
                if (!this.auditTeamsLoading && this.auditTeams.length === 0 && !this.auditTeamsError) {
                    this.loadAuditTeams()
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
        },
    },
    mounted() {
        this.load()
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
                this.form.roomvoxTokenConfigured = !!data.roomvoxTokenConfigured
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
            params.set('presenceModuleEnabled', this.form.presenceModuleEnabled ? '1' : '0')
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
                return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
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
            this.assignTeamId = team.id
            this.ownerQuery   = ''
            this.ownerResults = []
        },

        cancelAssign() {
            this.assignTeamId = null
            this.ownerQuery   = ''
            this.ownerResults = []
        },

        onOwnerSearch() {
            clearTimeout(this.ownerSearchTimer)
            if (this.ownerQuery.length < 1) {
                this.ownerResults = []
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
                    this.ownerResults = []
                } finally {
                    this.ownerSearching = false
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
                const date = new Date().toISOString().slice(0, 10)
                link.download = `teamhub-audit-${slug || 'team'}-${date}.zip`
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
            const d = new Date(ts * 1000)
            return d.toLocaleString()
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
            const d = new Date(unixTs * 1000)
            if (isNaN(d.getTime())) return '—'
            return d.toLocaleDateString(undefined, {
                year: 'numeric', month: 'short', day: 'numeric',
            })
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
    font-size: 14px;
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

.admin-group-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: var(--color-primary-element-light);
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

.admin-group-chip__remove {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    color: var(--color-text-maxcontrast);
    padding: 0 2px;
    margin-left: 2px;
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
    font-size: 14px;
    font-weight: 500;
    flex: 1;
}

.admin-group-result__id {
    font-size: 12px;
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
.admin-checks {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
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
    font-size: 14px;
    font-weight: 500;
    min-width: 180px;
}

.admin-select {
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border-maxcontrast);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
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
    font-size: 14px;
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
    font-size: 14px;
    font-weight: 600;
}

.admin-integration-row__appid {
    font-size: 12px;
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
    font-size: 12px;
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

.admin-integration-row__badge--widget {
    background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
    color: var(--color-primary-element);
}

.admin-integration-row__badge--menu_item,
.admin-integration-row__badge--tab {
    background: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success-text);
}
.admin-save-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 16px 24px;
    border-top: 1px solid var(--color-border);
    margin-top: 8px;
}

.admin-save-ok  { font-size: 14px; color: var(--color-success-text); font-weight: 500; }
.admin-save-err { font-size: 14px; color: var(--color-error-text); }
/* ── Statistics tab ────────────────────────────────────────────── */
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
    font-size: 12px;
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
    font-size: 20px;
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
    grid-template-columns:
        minmax(120px, 1.5fr)   /* name */
        minmax(100px, 2fr)     /* description */
        52px                   /* members — narrow, number only */
        minmax(140px, 1.6fr)   /* owner */
        100px                  /* created — fixed, date is short */
        260px;                 /* actions — wide enough for assign form */
    align-items: start;
}

.maint-grid__head {
    background: var(--color-background-dark);
    border-bottom: 2px solid var(--color-border);
    font-size: 11px;
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

/* ── Cell content ────────────────────────────────────────────────── */
.maint-team-name {
    font-weight: 600;
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.maint-no-owner {
    color: var(--color-warning-text);
    font-weight: 500;
    font-size: 12px;
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
    font-size: 12px;
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
    font-size: 14px;
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
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
}

.maint-integrity-row__detail {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
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
    font-size: 12px;
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
    font-size: 12px;
    color: var(--color-main-text);
}

.audit-table__target {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: monospace;
    font-size: 12px;
}

.audit-table__details {
    max-width: 360px;
}

.audit-table__details code {
    font-size: 11px;
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
    font-size: 16px;
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
    font-size: 14px;
}

.archive-admin__input--short {
    max-width: 140px;
}

.archive-admin__help {
    font-size: 12px;
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
    color: var(--color-success-text, #2d7d46);
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
    font-size: 12px;
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
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.archive-admin__status--pending {
    background: var(--color-warning-bg, #fff3cd);
    color: var(--color-warning-text, #7d5a00);
}

.archive-admin__status--completed {
    background: var(--color-success-bg, #d4edda);
    color: var(--color-success-text, #1a5e2e);
}

.archive-admin__status--restored {
    background: var(--color-info-bg, #d1ecf1);
    color: var(--color-info-text, #0c5460);
}

.archive-admin__status--failed {
    background: var(--color-error-bg, #fff3f3);
    color: var(--color-error-text, #c62828);
}

/* Inline error detail row */
.archive-admin__error-row td {
    padding: 0;
    border-bottom: 2px solid #c62828;
}

.archive-admin__error-panel {
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: #ffebee;
    border-left: 4px solid #c62828;
    padding: 14px 16px;
    font-size: 13px;
    color: #7f0000;
}

.archive-admin__error-panel strong {
    font-size: 14px;
    color: #7f0000;
}

.archive-admin__error-reason {
    display: block;
    font-family: monospace;
    font-size: 11px;
    background: rgba(0, 0, 0, 0.06);
    border-radius: 3px;
    padding: 6px 8px;
    word-break: break-word;
    color: #4a0000;
}

.archive-admin__error-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}

/* ── Group Folders delegation status ──────────────────────────────────────── */
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
    font-size: 14px;
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
    font-size: 11px;
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

</style>

