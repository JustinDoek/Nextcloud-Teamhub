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
                    :label="t('teamhub', 'Wizard introduction text')"
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
        </div>

        <!-- ── Tab: Invitations ───────────────────────────────────────────── -->
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
                        :checked="true"
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

        <!-- ── Tab: Messages ─────────────────────────────────────────────── -->
        <div
            v-show="activeTab === 'messages'"
            id="tab-panel-messages"
            role="tabpanel"
            class="teamhub-admin-panel">

            <NcSettingsSection
                :name="t('teamhub', 'Pin messages')"
                :description="t('teamhub', 'Minimum member role required to pin or unpin a message. One message can be pinned per team at a time.')">
                <div class="admin-select-row">
                    <label for="teamhub-pin-level" class="admin-select-label">
                        {{ t('teamhub', 'Minimum role to pin') }}
                    </label>
                    <select
                        id="teamhub-pin-level"
                        v-model="form.pinMinLevel"
                        class="admin-select">
                        <option value="member">{{ t('teamhub', 'Member') }}</option>
                        <option value="moderator">{{ t('teamhub', 'Moderator') }}</option>
                        <option value="admin">{{ t('teamhub', 'Admin / Owner') }}</option>
                    </select>
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
                        :checked="telemetry.enabled"
                        type="switch"
                        @update:checked="toggleTelemetry">
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
                    :checked="teamsOrphansOnly"
                    type="switch"
                    class="maint-orphan-toggle"
                    @update:checked="onOrphansToggle">
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
                                <NcButton type="tertiary" @click="cancelAssign">
                                    {{ t('teamhub', 'Cancel') }}
                                </NcButton>
                            </div>

                            <!-- Icon-only action buttons -->
                            <div v-else class="maint-row-actions">
                                <NcButton
                                    type="secondary"
                                    :disabled="assigningOwner"
                                    :aria-label="t('teamhub', 'Set owner for {name}', { name: team.name })"
                                    :title="t('teamhub', 'Set owner')"
                                    @click="startAssignOwner(team)">
                                    <template #icon><AccountEditIcon :size="18" /></template>
                                </NcButton>
                                <NcButton
                                    type="error"
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
                        type="tertiary"
                        :disabled="teamsPage_current <= 1"
                        @click="goToPage(1)">
                        «
                    </NcButton>
                    <NcButton
                        type="tertiary"
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
                        type="tertiary"
                        :disabled="teamsPage_current >= teamsTotalPages"
                        @click="goToPage(teamsPage_current + 1)">
                        ›
                    </NcButton>
                    <NcButton
                        type="tertiary"
                        :disabled="teamsPage_current >= teamsTotalPages"
                        @click="goToPage(teamsTotalPages)">
                        »
                    </NcButton>

                    <NcButton
                        type="tertiary"
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
                    type="primary"
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
                        {{ t('teamhub', 'Stale cache') }}: <strong>{{ membershipCheck.mismatched }}</strong>
                    </span>
                </div>

                <div v-if="membershipCheck.mismatched === 0" class="admin-empty">
                    {{ t('teamhub', 'All team membership caches are populated and consistent.') }}
                </div>

                <div v-else class="maint-integrity-list">
                    <div
                        v-for="issue in membershipCheck.issues"
                        :key="issue.id"
                        class="maint-integrity-row">
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
                            type="secondary"
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
                    </div>
                </div>
            </div>
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
                        :value.sync="auditRetentionInput"
                        type="number"
                        :min="auditRetention.min"
                        :max="auditRetention.max"
                        :label="t('teamhub', 'Days')"
                        :label-visible="false"
                        :disabled="auditRetentionSaving"
                        @input="auditRetentionInput = $event.target.value" />
                    <span class="audit-retention__suffix">{{ t('teamhub', 'days') }}</span>
                    <NcButton
                        type="primary"
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
                        type="tertiary"
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
                        type="secondary"
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
                        type="tertiary"
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
                        type="tertiary"
                        :disabled="auditEventsPage >= auditEventsTotalPages || auditEventsLoading"
                        @click="changeAuditPage(auditEventsPage + 1)">
                        {{ t('teamhub', 'Next') }} →
                    </NcButton>
                </div>
            </div>
        </NcSettingsSection>

        <!-- ── Save row — only for settings tabs, not statistics/maintenance/audit ─ -->
        <div v-show="!(['statistics','maintenance','audit'].includes(activeTab))" class="admin-save-row">
            <NcButton
                type="primary"
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
                <NcButton type="tertiary" @click="cancelDeleteOrphan">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    type="error"
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
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
    name: 'AdminSettings',
    components: {
        NcSettingsSection, NcButton, NcLoadingIcon,
        NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcDialog,
        ContentSave, AccountGroup, AccountPlusIcon, EmailSendIcon, MessageTextIcon, PuzzleIcon,
        ChartBarIcon, WrenchIcon, DeleteIcon, AccountEditIcon, ShieldCheckIcon, DownloadIcon, RefreshIcon,
        InformationOutline,
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
            },
            // Invite type toggles
            inviteGroup: true,
            inviteEmail: false,
            inviteFederated: false,
            // Group picker
            selectedGroups: [],
            groupQuery: '',
            groupResults: [],
            groupSearching: false,
            groupSearchTimer: null,
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
        }
    },
    computed: {
        tabs() {
            return [
                { id: 'creation',      label: this.t('teamhub', 'Team creation'), icon: 'AccountPlusIcon' },
                { id: 'invitations',   label: this.t('teamhub', 'Invitations'),   icon: 'EmailSendIcon'   },
                { id: 'messages',      label: this.t('teamhub', 'Messages'),       icon: 'MessageTextIcon' },
                { id: 'integrations',  label: this.t('teamhub', 'Integrations'),  icon: 'PuzzleIcon'      },
                { id: 'statistics',    label: this.t('teamhub', 'Statistics'),    icon: 'ChartBarIcon'    },
                { id: 'maintenance',   label: this.t('teamhub', 'Maintenance'),   icon: 'WrenchIcon'      },
                { id: 'audit',         label: this.t('teamhub', 'Audit'),          icon: 'ShieldCheckIcon' },
            ]
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
                this.form.wizardDescription  = data.wizardDescription  || ''
                this.form.pinMinLevel        = data.pinMinLevel         || 'moderator'
                this.form.intravoxParentPath = data.intravoxParentPath  || 'en/teamhub'

                const types = (data.inviteTypes || 'user,group').split(',').map(s => s.trim())
                this.inviteGroup     = types.includes('group')
                this.inviteEmail     = types.includes('email')
                this.inviteFederated = types.includes('federated')

                this.selectedGroups = Array.isArray(data.createTeamGroups) ? data.createTeamGroups : []
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
            }
            this.groupQuery   = ''
            this.groupResults = []
        },

        removeGroup(group) {
            this.selectedGroups = this.selectedGroups.filter(g => g.id !== group.id)
        },

        // ── Save ─────────────────────────────────────────────────────────

        async save() {
            this.saving    = true
            this.saved     = false
            this.saveError = null

            const types = ['user']
            if (this.inviteGroup)     types.push('group')
            if (this.inviteEmail)     types.push('email')
            if (this.inviteFederated) types.push('federated')

            const groupIds = JSON.stringify(this.selectedGroups.map(g => g.id))

            const params = new URLSearchParams()
            params.set('wizardDescription',  this.form.wizardDescription)
            params.set('intravoxParentPath', this.form.intravoxParentPath)
            params.set('createTeamGroup',    groupIds)
            params.set('pinMinLevel',        this.form.pinMinLevel)
            params.set('inviteTypes',        types.join(','))

            try {
                await axios.post(
                    generateUrl('/apps/teamhub/api/v1/admin/settings'),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                this.saved = true
                setTimeout(() => { this.saved = false }, 3000)
            } catch (e) {
                this.saveError = this.t('teamhub', 'Failed to save settings')
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
         * Format a MySQL datetime string (e.g. "2024-03-15 14:22:00") as a
         * localised short date. Returns '—' when value is null/empty.
         */
        formatDate(value) {
            if (!value) return '—'
            try {
                const d = new Date(value.replace(' ', 'T'))
                if (isNaN(d.getTime())) return value
                return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
            } catch (e) {
                return value
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

        async repairMembership(teamId) {
            this.$set(this.membershipRepairing, teamId, true)
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
                this.$set(this.membershipRepairing, teamId, false)
            }
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
    },
}
</script>

<style scoped>
/* ── Wrapper ─────────────────────────────────────────────────────────────── */
.teamhub-admin {
    display: flex;
    flex-direction: column;
}

/* ── Tab bar ─────────────────────────────────────────────────────────────── */
.teamhub-admin-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 0 16px 0;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 8px;
}

.teamhub-admin-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;       /* overlaps the tab bar border-bottom */
    cursor: pointer;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
    white-space: nowrap;
}

.teamhub-admin-tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.teamhub-admin-tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: 600;
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
    outline: none;
    border-color: var(--color-primary-element);
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
</style>

