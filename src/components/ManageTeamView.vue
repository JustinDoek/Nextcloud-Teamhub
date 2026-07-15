<template>
    <div class="manage-team-view">
        <div class="manage-team-header">
            <h2>{{ t('teamhub', 'Manage Team') }}</h2>
            <p class="manage-team-subtitle">{{ team.name }}</p>
        </div>

        <!-- Tab bar -->
        <div class="manage-tabs">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="manage-tab"
                :class="{ 'manage-tab--active': activeTab === tab.key, 'manage-tab--danger': tab.key === 'danger' }"
                @click="activeTab = tab.key">
                <component :is="tab.icon" :size="18" />
                {{ tab.label }}
            </button>
        </div>

        <!-- TAB: Description -->
        <div v-if="activeTab === 'description'" class="manage-tab-content">

            <!-- Team Image section -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Team Image') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Upload a custom image to represent this team. Shown on the team home view. Maximum 200×200 px, 2 MB.') }}
                </p>

                <!-- Current image preview -->
                <div class="team-image-preview-row">
                    <div class="team-image-preview" :class="{ 'team-image-preview--empty': !imagePreviewUrl }">
                        <img
                            v-if="imagePreviewUrl"
                            :src="imagePreviewUrl"
                            :alt="t('teamhub', 'Team image')"
                            class="team-image-preview__img" />
                        <ImageIcon v-else :size="48" class="team-image-preview__placeholder" />
                    </div>

                    <div class="team-image-actions">
                        <!-- Upload button — triggers hidden file input -->
                        <NcButton
                            variant="secondary"
                            :disabled="imageUploading || imageRemoving"
                            @click="$refs.teamImageInput.click()">
                            <template #icon>
                                <NcLoadingIcon v-if="imageUploading" :size="20" />
                                <UploadIcon v-else :size="20" />
                            </template>
                            {{ imagePreviewUrl ? t('teamhub', 'Replace image') : t('teamhub', 'Upload image') }}
                        </NcButton>

                        <!-- Remove button — only shown when an image exists -->
                        <NcButton
                            v-if="imagePreviewUrl"
                            variant="error"
                            :disabled="imageUploading || imageRemoving"
                            @click="removeTeamImage">
                            <template #icon>
                                <NcLoadingIcon v-if="imageRemoving" :size="20" />
                                <TrashCanOutline v-else :size="20" />
                            </template>
                            {{ t('teamhub', 'Remove image') }}
                        </NcButton>

                        <!-- Hidden file input -->
                        <input
                            ref="teamImageInput"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            class="team-image-hidden-input"
                            @change="onTeamImageSelected" />
                    </div>
                </div>
            </div>

            <div class="manage-section">
                <h3>{{ t('teamhub', 'Team Description') }}</h3>
                <div class="manage-description-form">
                    <NcTextArea
                        v-model="editedDescription"
                        :label="t('teamhub', 'Description')"
                        :placeholder="t('teamhub', 'Enter team description...')"
                        :rows="4" />
                    <div class="manage-description-actions">
                        <NcButton
                            variant="primary"
                            :disabled="(editedDescription === (team.description || '')) || saving"
                            @click="saveDescription">
                            <template #icon>
                                <NcLoadingIcon v-if="saving" :size="20" />
                                <ContentSave v-else :size="20" />
                            </template>
                            {{ t('teamhub', 'Save Description') }}
                        </NcButton>
                    </div>
                </div>
            </div>

        </div>

        <!-- TAB: Settings -->
        <div v-else-if="activeTab === 'settings'" class="manage-tab-content">
            <!-- Circle Settings -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Circle Settings') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'These settings control how people can join and interact with this team.') }}
                </p>
                <div v-if="loadingConfig" class="section-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <div v-else class="manage-settings">
                    <div class="manage-settings-group">
                        <h4>{{ t('teamhub', 'Invitations') }}</h4>
                        <NcCheckboxRadioSwitch
                            v-for="opt in invitationOptions"
                            :key="opt.key"
                            v-model="circleConfig[opt.key]"
                            type="checkbox"
                            @update:model-value="saveConfig">
                            {{ opt.label }}
                        </NcCheckboxRadioSwitch>
                    </div>
                    <div class="manage-settings-group">
                        <h4>{{ t('teamhub', 'Membership') }}</h4>
                        <!-- CFG_ROOT (8192): same bit Contacts uses for "Prevent teams from being
                             a member of another team". Checked = prevention is active (CFG_ROOT set). -->
                        <NcCheckboxRadioSwitch
                            v-model="circleConfig.preventSubMembership"
                            type="checkbox"
                            @update:model-value="saveConfig">
                            {{ t('teamhub', 'Prevent this team from being a member of another team') }}
                        </NcCheckboxRadioSwitch>
                    </div>
                    <div class="manage-settings-group">
                        <h4>{{ t('teamhub', 'Privacy') }}</h4>
                        <NcCheckboxRadioSwitch
                            v-for="opt in privacyOptions"
                            :key="opt.key"
                            v-model="circleConfig[opt.key]"
                            type="checkbox"
                            @update:model-value="saveConfig">
                            {{ opt.label }}
                        </NcCheckboxRadioSwitch>
                    </div>
                    <p v-if="configSaved" class="manage-settings-saved">
                        <CheckCircle :size="14" />{{ t('teamhub', 'Settings saved') }}
                    </p>
                </div>
            </div>

            <!-- Permissions (v3.104.7) — merged Meeting + Custom links rows
                 under one section using the compact manage-section__row
                 pattern from Integration Settings → Messages. Auto-save on
                 change; the shared endpoint (message/settings) drives Custom
                 links, saveMeetingSettings drives the Meeting row. -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Permissions') }}</h3>

                <!-- Meeting min-role -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Team meetings') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Who can create a team meeting.') }}</span>
                    </div>
                    <select
                        v-model="meetingMinLevel"
                        :disabled="loadingMeetingSettings"
                        class="teamhub-dec-level-select"
                        :aria-label="t('teamhub', 'Who can create a team meeting.')"
                        @change="saveMeetingSettings">
                        <option :value="1">{{ t('teamhub', 'Any member') }}</option>
                        <option :value="4">{{ t('teamhub', 'Moderator or above') }}</option>
                        <option :value="8">{{ t('teamhub', 'Admin or above') }}</option>
                    </select>
                </div>

                <!-- Custom links min-role -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Custom links') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Who can add, edit, or delete custom links in the team tab bar.') }}</span>
                    </div>
                    <select
                        v-model="messageSettingsForm.linkMinLevel"
                        :disabled="savingMessageSettings"
                        class="teamhub-dec-level-select"
                        :aria-label="t('teamhub', 'Who can add, edit, or delete custom links in the team tab bar.')"
                        @change="saveMessageSettingsAuto">
                        <option value="member">{{ t('teamhub', 'Any member') }}</option>
                        <option value="moderator">{{ t('teamhub', 'Moderator or above') }}</option>
                        <option value="admin">{{ t('teamhub', 'Admin or above') }}</option>
                    </select>
                </div>

                <p v-if="meetingSettingsError" class="manage-settings-error">
                    {{ meetingSettingsError }}
                </p>
            </div>

        </div>

        <!-- TAB: Members -->
        <div v-else-if="activeTab === 'members'" class="manage-tab-content">

            <!-- Direct members -->
            <div class="manage-section">
                <div class="manage-section__header">
                    <h3>{{ t('teamhub', 'Direct Members') }} ({{ manageMembers.direct.length }})</h3>
                    <NcButton
                        v-if="isAdminOrOwner"
                        variant="secondary"
                        :aria-label="t('teamhub', 'Invite members to this team')"
                        @click="showInviteModal = true">
                        <template #icon><AccountPlusIcon :size="18" /></template>
                        {{ t('teamhub', 'Invite members') }}
                    </NcButton>
                </div>
                <div v-if="loadingMembers" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>
                <div v-else-if="manageMembers.direct.length === 0" class="no-pending">
                    {{ t('teamhub', 'No direct members') }}
                </div>
                <div v-else class="members-list">
                    <div
                        v-for="member in manageMembers.direct"
                        :key="member.userId"
                        class="member-item">
                        <NcAvatar
                            v-if="member.userId"
                            :user="member.userId"
                            :display-name="member.displayName"
                            :size="32"
                            :show-user-status="false" />
                        <div v-else class="member-avatar-fallback">
                            {{ (member.displayName || '?').charAt(0).toUpperCase() }}
                        </div>
                        <div class="member-info">
                            <span class="member-name">{{ member.displayName }}</span>
                        </div>
                        <select
                            v-if="canChangeLevel(member)"
                            :value="member.level"
                            :disabled="changingLevel === member.userId"
                            class="member-level-select"
                            :aria-label="t('teamhub', 'Change role for {name}', { name: member.displayName })"
                            @change="changeLevel(member, Number($event.target.value))">
                            <option :value="1">{{ t('teamhub', 'Member') }}</option>
                            <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                            <option v-if="currentUserIsOwner" :value="8">{{ t('teamhub', 'Admin') }}</option>
                        </select>
                        <span v-else class="member-role-static">{{ getMemberRoleLabel(member.level) }}</span>
                        <NcButton
                            v-if="canRemoveMember(member)"
                            variant="error"
                            :aria-label="t('teamhub', 'Remove member')"
                            @click="removeMember(member.userId, 'user')">
                            <template #icon><AccountRemove :size="20" /></template>
                            {{ t('teamhub', 'Remove') }}
                        </NcButton>
                    </div>
                </div>
            </div>

            <!-- Groups -->
            <div v-if="!loadingMembers && (manageMembers.groups.length > 0 || manageMembers.circles.length > 0)" class="manage-section">
                <h3>{{ t('teamhub', 'Groups & Teams') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'These groups and teams have been added as members. All their users count towards the total effective membership of {count}.', { count: manageMembers.effective_count }) }}
                </p>

                <!-- Groups -->
                <div v-if="manageMembers.groups.length > 0" class="group-circle-list">
                    <div class="group-circle-section-label">{{ t('teamhub', 'Groups') }}</div>
                    <div
                        v-for="group in manageMembers.groups"
                        :key="'group-' + group.groupId"
                        class="group-circle-item">
                        <div class="group-circle-icon group-circle-icon--group">
                            <AccountGroup :size="20" />
                        </div>
                        <div class="group-circle-info">
                            <span class="group-circle-name">{{ group.displayName }}</span>
                            <span class="group-circle-count">
                                {{
                                    // TRANSLATORS: member count of a group, e.g. "1 user" or "12 users"
                                    n('teamhub', '{n} user', '{n} users', group.memberCount, { n: group.memberCount })
                                }}
                            </span>
                        </div>
                        <NcButton
                            variant="error"
                            :aria-label="t('teamhub', 'Remove group {name}', { name: group.displayName })"
                            @click="removeMember(group.singleId, group.userType === 16 ? 'circle' : 'group')">
                            <template #icon><AccountRemove :size="20" /></template>
                            {{ t('teamhub', 'Remove') }}
                        </NcButton>
                    </div>
                </div>

                <!-- Teams / Circles -->
                <div v-if="manageMembers.circles.length > 0" class="group-circle-list" :class="{ 'group-circle-list--spaced': manageMembers.groups.length > 0 }">
                    <div class="group-circle-section-label">{{ t('teamhub', 'Teams') }}</div>
                    <div
                        v-for="circle in manageMembers.circles"
                        :key="'circle-' + circle.circleId"
                        class="group-circle-item">
                        <div class="group-circle-icon group-circle-icon--circle">
                            <AccountMultipleIcon :size="20" />
                        </div>
                        <div class="group-circle-info">
                            <span class="group-circle-name">{{ circle.displayName }}</span>
                            <span class="group-circle-count">
                                {{
                                    // TRANSLATORS: member count of a sub-team, e.g. "1 user" or "8 users"
                                    n('teamhub', '{n} user', '{n} users', circle.memberCount, { n: circle.memberCount })
                                }}
                            </span>
                        </div>
                        <NcButton
                            variant="error"
                            :aria-label="t('teamhub', 'Remove team {name}', { name: circle.displayName })"
                            @click="removeMember(circle.singleId, 'circle')">
                            <template #icon><AccountRemove :size="20" /></template>
                            {{ t('teamhub', 'Remove') }}
                        </NcButton>
                    </div>
                </div>
            </div>

            <!-- Effective count summary -->
            <div v-if="!loadingMembers" class="manage-section manage-section--summary">
                <span class="effective-count-label">
                    {{ t('teamhub', 'Total users with access to this team:') }}
                    <strong>{{ manageMembers.effective_count }}</strong>
                </span>
            </div>

            <!-- Pending join requests -->
            <div class="manage-section manage-section--pending">
                <h3>{{ t('teamhub', 'Pending Join Requests') }}</h3>
                <div v-if="loadingPending" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>
                <div v-else-if="pendingRequests.length === 0" class="no-pending">
                    {{ t('teamhub', 'No pending requests') }}
                </div>
                <div v-else class="pending-list">
                    <div v-for="req in pendingRequests" :key="req.userId" class="pending-item">
                        <NcAvatar
                            v-if="req.userId"
                            :user="req.userId"
                            :display-name="req.displayName"
                            :size="32"
                            :show-user-status="false" />
                        <div v-else class="member-avatar-fallback">
                            {{ (req.displayName || '?').charAt(0).toUpperCase() }}
                        </div>
                        <div class="pending-info">
                            <span class="pending-name">{{ req.displayName }}</span>
                            <span class="pending-date">{{ req.userId }}</span>
                        </div>
                        <div class="pending-actions">
                            <NcButton variant="primary" @click="approve(req)">
                                <template #icon><Check :size="20" /></template>
                                {{ t('teamhub', 'Approve') }}
                            </NcButton>
                            <NcButton variant="error" @click="reject(req)">
                                <template #icon><Close :size="20" /></template>
                                {{ t('teamhub', 'Reject') }}
                            </NcButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- InviteMemberModal — triggered from Members tab -->
        <InviteMemberModal
            v-if="showInviteModal"
            :team-id="team.id"
            @close="showInviteModal = false"
            @invited="onMembersInvited" />

        <!-- TAB: Integrations -->
        <div v-else-if="activeTab === 'integrations'" class="manage-tab-content">
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Integrations') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Enable or disable integrations for this team. Internal integrations are built into TeamHub; third-party integrations are registered by other Nextcloud apps. Widgets appear on the Home view; tab integrations add a tab to the tab bar.') }}
                </p>

                <!-- ── Internal integrations (built into TeamHub) ──────────── -->
                <!-- v3.104.1 — parent gate loosened to isTeamAdmin alone.
                     Messages/Timeline/Budget/Time are always available (no
                     instance-wide module gate) so the subsection must render
                     even when neither Presence nor Decisions is on. -->
                <div v-if="isTeamAdmin" class="integrations-subsection">
                    <h4 class="integrations-subsection__title">{{ t('teamhub', 'Internal integrations') }}</h4>
                    <div class="widgets-list">
                        <!-- Presence row — only shown when presence module is on -->
                        <div
                            v-if="presenceModuleEnabled"
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': presenceEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    {{ t('teamhub', 'Presence') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                    <span class="widget-badge widget-badge--tab">
                                        {{ t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Show a Presence tab on the team home so members can see each other\'s schedules.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="presenceEnabled"
                                :disabled="savingPresenceConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Presence tab for this team')"
                                @update:model-value="setPresenceEnabled($event)">
                                {{ presenceEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Sub-option: only shown when presence is enabled -->
                        <div
                            v-if="presenceModuleEnabled && presenceEnabled"
                            class="widget-item widget-item--internal widget-item--sub">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">{{ t('teamhub', 'Hide status details') }}</span>
                                <span class="widget-description">{{ t('teamhub', 'Members see busy / free / off only — not the specific status or location.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="presenceHideReasons"
                                :disabled="savingPresenceConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Hide status details from team members')"
                                @update:model-value="setPresenceHideReasons($event)">
                                {{ presenceHideReasons ? t('teamhub', 'Hidden') : t('teamhub', 'Visible') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Decisions row — only shown when decisions module is on -->
                        <div
                            v-if="decisionsModuleEnabled"
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': decisionsEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    <!-- TRANSLATORS: Name of the Decisions feature/module (a TeamHub built-in integration that lets teams record decisions) -->
                                    {{ t('teamhub', 'Decisions') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                    <span class="widget-badge widget-badge--tab">
                                        {{ t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Allow team members to propose, discuss, and record decisions in the message stream and Decisions tab.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="decisionsEnabled"
                                :disabled="savingDecisionsConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Decisions for this team')"
                                @update:model-value="setDecisionsEnabled($event)">
                                {{ decisionsEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Messages row (v3.104.1) — per-team toggle. Default
                             on: most teams communicate via the message stream.
                             Disabling hides the message stream widget, the
                             PostMessageForm, and every message surface. -->
                        <div
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': messagesEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    <!-- TRANSLATORS: Name of the Messages feature (a TeamHub built-in integration providing the team message stream, pins, polls, and posts) -->
                                    {{ t('teamhub', 'Messages') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Enable the team message stream — posts, questions, polls, and pinned messages. Role limits live under Integration settings.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="messagesEnabled"
                                :disabled="savingMessagesConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Messages for this team')"
                                @update:model-value="setMessagesEnabled($event)">
                                {{ messagesEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Budget row — Advanced projects only. Per-team toggle.
                             Default on so newly-created Advanced projects show the
                             tab out of the box; admins can hide the tab from
                             here for projects that don't need budget tracking. -->
                        <div
                            v-if="project.mode === 'advanced'"
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': budgetEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    <!-- TRANSLATORS: Name of the Budget feature (a TeamHub built-in integration that adds an Execution-phase budget page to Advanced projects) -->
                                    {{ t('teamhub', 'Budget') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                    <span class="widget-badge widget-badge--tab">
                                        {{ t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Show a Budget tab with per-workstream allocations and expenses. Available for Advanced projects only.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="budgetEnabled"
                                :disabled="savingBudgetConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Budget for this team')"
                                @update:model-value="setBudgetEnabled($event)">
                                {{ budgetEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Time investment row — Advanced projects only (v3.96.0).
                             Same shape as the Budget row: per-team toggle, default
                             on so newly-created Advanced projects show the tab
                             out of the box. -->
                        <div
                            v-if="project.mode === 'advanced'"
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': timeEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    <!-- TRANSLATORS: Name of the Time investment feature (a TeamHub built-in integration that adds an Execution-phase per-member time-logging page to Advanced projects) -->
                                    {{ t('teamhub', 'Time investment') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                    <span class="widget-badge widget-badge--tab">
                                        {{ t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Show a Time tab with per-member available hours and per-card time logs. Available for Advanced projects only.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="timeEnabled"
                                :disabled="savingTimeConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Time investment for this team')"
                                @update:model-value="setTimeEnabled($event)">
                                {{ timeEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                        <!-- Timeline row — per-team toggle. No global module gate
                             because the timeline is purely a read-only visual
                             aggregation; nothing to disable at the system level. -->
                        <div
                            class="widget-item widget-item--internal"
                            :class="{ 'widget-item--enabled': timelineEnabled }">
                            <span class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    <!-- TRANSLATORS: Name of the Timeline feature (a TeamHub built-in integration that shows calendar/deck/decisions/messages on a visual timeline) -->
                                    {{ t('teamhub', 'Timeline') }}
                                    <span class="widget-badge widget-badge--internal">
                                        {{ t('teamhub', 'Built-in') }}
                                    </span>
                                    <span class="widget-badge widget-badge--tab">
                                        {{ t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span class="widget-description">{{ t('teamhub', 'Show a visual timeline tab combining calendar events, Deck cards, decisions, and message posts.') }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="timelineEnabled"
                                :disabled="savingTimelineConfig"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable Timeline for this team')"
                                @update:model-value="setTimelineEnabled($event)">
                                {{ timelineEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>

                    </div>
                </div>

                <!-- ── Third-party integrations (registered by other apps) ── -->
                <h4 v-if="isTeamAdmin" class="integrations-subsection__title">{{ t('teamhub', 'Third-party integrations') }}</h4>
                <div v-if="loadingWidgets" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>
                <template v-else>
                    <!--
                        Only EXTERNAL (non-builtin) integrations appear here.
                        Built-in NC apps (Talk, Files, Calendar, Deck) are managed
                        in the Settings tab under Team Apps. They are seeded into
                        the registry as is_builtin=true and did NOT register via
                        the integration API — so they must not appear here.
                    -->
                    <div v-if="externalIntegrations.length === 0" class="no-pending">
                        {{ t('teamhub', 'No third-party integrations available. Install a compatible app to add integrations to this team.') }}
                    </div>
                    <div v-else class="widgets-list">
                        <div
                            v-for="integration in externalIntegrations"
                            :key="integration.registry_id"
                            class="widget-item"
                            :class="{ 'widget-item--enabled': integration.enabled }">
                            <span
                                v-if="integration.enabled"
                                class="widget-drag-handle"
                                :draggable="true"
                                :aria-label="t('teamhub', 'Drag to reorder')"
                                @dragstart="onDragStart($event, integration)"
                                @dragover.prevent
                                @drop="onDrop($event, integration)">
                                <DragVertical :size="18" />
                            </span>
                            <span v-else class="widget-drag-handle widget-drag-handle--placeholder" />
                            <div class="widget-info">
                                <span class="widget-title">
                                    {{ integration.title }}
                                    <span
                                        class="widget-badge"
                                        :class="integration.integration_type === 'widget' ? 'widget-badge--widget' : 'widget-badge--tab'">
                                        {{ integration.integration_type === 'widget' ? t('teamhub', 'Widget') : t('teamhub', 'Menu item') }}
                                    </span>
                                </span>
                                <span v-if="integration.description" class="widget-description">{{ integration.description }}</span>
                                <span v-if="integration.app_id" class="widget-app-id">{{ integration.app_id }}</span>
                            </div>
                            <NcCheckboxRadioSwitch
                                :model-value="integration.enabled"
                                :disabled="togglingWidget === integration.registry_id"
                                type="switch"
                                :aria-label="t('teamhub', 'Enable {title}', { title: integration.title })"
                                @update:model-value="toggleIntegration(integration, $event)">
                                {{ integration.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                            </NcCheckboxRadioSwitch>
                        </div>
                    </div>
                </template>
            </div>
            <!-- Team Apps — per-app resource lists for resource-backed apps -->
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Team Apps') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Manage connected resources for each app. Resources appear as tabs on the team home view.') }}
                </p>
                <div v-if="loadingApps" class="section-loading">
                    <NcLoadingIcon :size="24" />
                </div>
                <div v-else class="team-apps-list">

                    <!-- Pending resources — shown at top before app rows so admins see them first -->
                    <div v-if="normalPendingResources.length > 0 || dualFolderPendingRow" class="manage-section manage-section--pending manage-section--pending-inline">
                        <div class="team-app-section__header team-app-section__header--info">
                            <span aria-hidden="true">ℹ</span>
                            <span class="team-app-section__name">{{ t('teamhub', 'Resources pending review') }}</span>
                        </div>

                        <!-- Dual-folder informational notice — no action button -->
                        <div v-if="dualFolderPendingRow" class="dual-folder-notice">
                            <div class="dual-folder-notice__header">
                                <FolderIcon :size="18" aria-hidden="true" />
                                <strong>{{ t('teamhub', 'Group folder connect') }}</strong>
                            </div>
                            <p class="dual-folder-notice__body">
                                <!-- TRANSLATORS: {groupFolder} and {sharedFolder} are folder names -->
                                {{ t('teamhub', 'The group folder "{groupFolder}" is available for this team alongside the existing shared folder "{sharedFolder}". Use the + connect group folder to attach the group folder. Migrate files from your shared folder before you disconnect or delete the shared folder.', {
                                    groupFolder: dualFolderPendingRow.displayName || dualFolderPendingRow.resourceId,
                                    sharedFolder: dualFolderSharedRow ? (dualFolderSharedRow.displayName || dualFolderSharedRow.resourceId) : t('teamhub', 'shared folder'),
                                }) }}
                            </p>
                        </div>

                        <p v-if="normalPendingResources.length > 0" class="manage-section-desc manage-section-desc--inline">
                            {{ t('teamhub', 'These resources are connected to this team in Nextcloud but were not added through TeamHub. Review each one and choose to accept or ignore it.') }}
                        </p>
                        <div v-if="loadingPendingResources" class="section-loading">
                            <NcLoadingIcon :size="24" />
                        </div>
                        <div v-else class="pending-resources-list">
                            <div
                                v-for="resource in normalPendingResources"
                                :key="resource.id"
                                class="pending-resource-item">
                                <div class="pending-resource-info">
                                    <span class="pending-resource-app">{{ appLabel(resource.appId) }}</span>
                                    <span class="pending-resource-name" :title="resource.resourceId">
                                        {{ resource.displayName || resource.resourceId }}
                                    </span>
                                </div>
                                <div class="pending-resource-actions">
                                    <NcButton
                                        variant="primary"
                                        :disabled="resource._loading"
                                        :aria-label="t('teamhub', 'Accept resource {id}', { id: resource.resourceId })"
                                        @click="acceptResource(resource)">
                                        {{ t('teamhub', 'Accept') }}
                                    </NcButton>
                                    <NcButton
                                        variant="tertiary"
                                        :disabled="resource._loading"
                                        :aria-label="t('teamhub', 'Ignore resource {id}', { id: resource.resourceId })"
                                        @click="ignoreResource(resource)">
                                        {{ t('teamhub', 'Ignore') }}
                                    </NcButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- At-risk resources — shown at top so admins see problems first -->
                    <div v-if="atRiskResources.length > 0" class="manage-section--atrisk manage-section--atrisk-inline">
                        <div class="team-app-section__header team-app-section__header--warning">
                            <span aria-hidden="true">⚠</span>
                            <span class="team-app-section__name">{{ t('teamhub', 'Resources at risk') }}</span>
                        </div>
                        <div v-for="resource in atRiskResources" :key="resource.id" class="atrisk-resource-item atrisk-resource-item--inline">
                            <span class="atrisk-resource-icon" aria-hidden="true">⚠</span>
                            <div class="atrisk-resource-info">
                                <span class="atrisk-resource-name">
                                    {{ appLabel(resource.appId) }} — {{ resource.displayName || resource.resourceId }}
                                </span>
                                <span class="atrisk-resource-reason">
                                    {{ riskLabel(resource.riskStatus) }}
                                    <template v-if="resource.ownerUid">
                                        · {{ t('teamhub', 'Owner: {uid}', { uid: resource.ownerUid }) }}
                                    </template>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Talk — 1:1, no multi-resource -->
                    <div v-if="installedApps.talk" class="team-app-section">
                        <div class="team-app-section__header">
                            <MessageIcon :size="18" aria-hidden="true" />
                            <span class="team-app-section__name">{{ t('teamhub', 'Talk') }}</span>
                        </div>
                        <div v-for="row in activeResourcesByApp('talk')" :key="row.id" class="resource-row">
                            <span class="resource-row__name">{{ row.displayName || row.resourceId }}</span>
                            <div class="resource-row__actions">
                                <NcButton
                                    variant="tertiary"
                                    :aria-label="t('teamhub', 'Disconnect Talk room')"
                                    @click="removeResource(row)">
                                    {{ t('teamhub', 'Disconnect') }}
                                </NcButton>
                            </div>
                        </div>
                        <div v-if="activeResourcesByApp('talk').length === 0" class="resource-row resource-row--empty">
                            <span class="resource-row__empty-label">{{ t('teamhub', 'No Talk room connected') }}</span>
                        </div>
                        <div v-if="activeResourcesByApp('talk').length === 0" class="resource-row resource-row--actions">
                            <NcButton variant="tertiary" @click="openConnectPicker('talk')">
                                {{ t('teamhub', '+ Connect existing') }}
                            </NcButton>
                            <NcButton variant="tertiary" @click="createResource('talk')">
                                {{ t('teamhub', '+ Create new') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- Files — 1:1 -->
                    <div class="team-app-section">
                        <div class="team-app-section__header">
                            <FolderIcon :size="18" aria-hidden="true" />
                            <span class="team-app-section__name">{{ t('teamhub', 'Files') }}</span>
                        </div>
                        <div v-for="row in activeResourcesByApp('files')" :key="row.id" class="resource-row">
                            <span class="resource-row__name">{{ row.displayName || row.resourceId }}</span>
                            <span
                                class="resource-type-badge"
                                :class="row.resourceId.startsWith('gf:') ? 'resource-type-badge--gf' : 'resource-type-badge--shared'"
                                :aria-label="row.resourceId.startsWith('gf:') ? t('teamhub', 'Group folder') : t('teamhub', 'Shared folder')">
                                {{ row.resourceId.startsWith('gf:') ? t('teamhub', 'Group folder') : t('teamhub', 'Shared folder') }}
                            </span>
                            <div class="resource-row__actions">
                                <NcButton
                                    variant="tertiary"
                                    :aria-label="t('teamhub', 'Disconnect Files folder')"
                                    @click="removeResource(row)">
                                    {{ t('teamhub', 'Disconnect') }}
                                </NcButton>
                            </div>
                        </div>
                        <div v-if="activeResourcesByApp('files').length === 0" class="resource-row resource-row--empty">
                            <span class="resource-row__empty-label">{{ t('teamhub', 'No shared folder connected') }}</span>
                        </div>
                        <!-- Show add buttons when no GF is active.
                             When a shared folder is active, these trigger the migration flow.
                             When a GF is active, no buttons shown (already on the best option). -->
                        <div v-if="!activeFilesIsGf" class="resource-row resource-row--actions">
                            <NcButton variant="tertiary" @click="openConnectPicker('files')">
                                {{ activeFilesIsShared
                                    ? t('teamhub', '+ Connect group folder')
                                    : t('teamhub', '+ Connect existing') }}
                            </NcButton>
                            <NcButton
                                v-if="installedApps.groupfolders"
                                variant="tertiary"
                                @click="createResource('files')">
                                {{ t('teamhub', '+ Create new group folder') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- Calendar — multi-resource -->
                    <div v-if="installedApps.calendar" class="team-app-section">
                        <div class="team-app-section__header">
                            <CalendarIcon :size="18" aria-hidden="true" />
                            <span class="team-app-section__name">{{ t('teamhub', 'Calendar') }}</span>
                        </div>
                        <div v-for="row in activeResourcesByApp('calendar')" :key="row.id" class="resource-row">
                            <span class="resource-row__name">{{ row.displayName || row.resourceId }}</span>
                            <div class="resource-row__actions">
                                <NcButton
                                    variant="tertiary"
                                    :disabled="row._loading"
                                    :aria-label="t('teamhub', 'Disconnect calendar — removes team access, calendar is preserved')"
                                    @click="removeResource(row)">
                                    {{ t('teamhub', 'Disconnect') }}
                                </NcButton>
                                <NcButton
                                    variant="error"
                                    :disabled="row._loading"
                                    :aria-label="t('teamhub', 'Delete calendar permanently')"
                                    @click="confirmDeleteResource(row)">
                                    {{ t('teamhub', 'Delete') }}
                                </NcButton>
                            </div>
                        </div>
                        <div class="resource-row resource-row--actions">
                            <NcButton variant="tertiary" @click="openConnectPicker('calendar')">
                                {{ t('teamhub', '+ Connect existing') }}
                            </NcButton>
                            <NcButton variant="tertiary" @click="createResource('calendar')">
                                {{ t('teamhub', '+ Create new') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- Deck — multi-resource -->
                    <div v-if="installedApps.deck" class="team-app-section">
                        <div class="team-app-section__header">
                            <CardTextIcon :size="18" aria-hidden="true" />
                            <span class="team-app-section__name">{{ t('teamhub', 'Deck') }}</span>
                        </div>
                        <div v-for="row in activeResourcesByApp('deck')" :key="row.id" class="resource-row">
                            <span class="resource-row__name">{{ row.displayName || row.resourceId }}</span>
                            <div class="resource-row__actions">
                                <NcButton
                                    variant="tertiary"
                                    :disabled="row._loading"
                                    :aria-label="t('teamhub', 'Disconnect board — removes team access, board is preserved')"
                                    @click="removeResource(row)">
                                    {{ t('teamhub', 'Disconnect') }}
                                </NcButton>
                                <NcButton
                                    variant="error"
                                    :disabled="row._loading"
                                    :aria-label="t('teamhub', 'Delete board permanently')"
                                    @click="confirmDeleteResource(row)">
                                    {{ t('teamhub', 'Delete') }}
                                </NcButton>
                            </div>
                        </div>
                        <div class="resource-row resource-row--actions">
                            <NcButton variant="tertiary" @click="openConnectPicker('deck')">
                                {{ t('teamhub', '+ Connect existing') }}
                            </NcButton>
                            <NcButton variant="tertiary" @click="createResource('deck')">
                                {{ t('teamhub', '+ Create new') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- Shared Files + Intravox — toggle-driven, unchanged -->
                    <div
                        v-for="app in toggleApps"
                        :key="app.id"
                        class="team-app-item">
                        <div class="team-app-icon">
                            <component :is="app.icon" :size="22" />
                        </div>
                        <div class="team-app-info">
                            <span class="team-app-name">{{ app.label }}</span>
                            <span class="team-app-desc">{{ app.description }}</span>
                        </div>
                        <NcCheckboxRadioSwitch
                            :model-value="app.enabled"
                            :disabled="togglingApp === app.id || !app.installed"
                            type="switch"
                            :aria-label="t('teamhub', 'Enable {name}', { name: app.label })"
                            @update:model-value="toggleApp(app, $event)">
                            {{ app.installed ? (app.enabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled')) : t('teamhub', 'Not installed') }}
                        </NcCheckboxRadioSwitch>
                    </div>

                </div>
            </div>

            <!-- Connect existing resource picker (inline) -->
            <NcDialog
                v-if="connectPickerApp"
                :name="connectPickerTitle"
                :open="!!connectPickerApp"
                @update:open="connectPickerApp = null">
                <div class="teamhub-resource-picker">
                    <div v-if="loadingConnectPicker" class="section-loading">
                        <NcLoadingIcon :size="24" />
                    </div>
                    <div v-else-if="connectPickerItems.length === 0" class="resource-picker-empty">
                        {{ t('teamhub', 'No resources available to connect') }}
                    </div>
                    <button
                        v-else
                        v-for="item in connectPickerItems"
                        :key="item.id"
                        class="teamhub-resource-picker__item"
                        @click="connectExisting(item)">
                        <span
                            v-if="item.type === 'group_folder'"
                            class="teamhub-resource-picker__badge teamhub-resource-picker__badge--gf"
                            aria-label="Group Folder">
                            {{ t('teamhub', 'Group Folder') }}
                        </span>
                        <span
                            v-else-if="item.type === 'shared_folder'"
                            class="teamhub-resource-picker__badge teamhub-resource-picker__badge--shared"
                            aria-label="Shared folder">
                            {{ t('teamhub', 'Shared') }}
                        </span>
                        <span class="teamhub-resource-picker__name">{{ item.name }}</span>
                    </button>
                </div>
            </NcDialog>

            <!-- Create new resource — name input dialog -->
            <NcDialog
                v-if="createResourceApp"
                :name="createResourceDialogTitle"
                :open="!!createResourceApp"
                @update:open="createResourceApp = null">
                <div class="create-resource-form">
                    <NcTextArea
                        v-model="createResourceName"
                        :label="t('teamhub', 'Name')"
                        :placeholder="createResourceNamePlaceholder"
                        :rows="1"
                        :disabled="creatingResource"
                        @keydown.enter.prevent="confirmCreateResource" />
                    <div class="create-resource-form__actions">
                        <NcButton
                            variant="tertiary"
                            :disabled="creatingResource"
                            @click="createResourceApp = null">
                            {{ t('teamhub', 'Cancel') }}
                        </NcButton>
                        <NcButton
                            variant="primary"
                            :disabled="!createResourceName.trim() || creatingResource"
                            :aria-label="t('teamhub', 'Create {name}', { name: createResourceName })"
                            @click="confirmCreateResource">
                            <NcLoadingIcon v-if="creatingResource" :size="16" />
                            {{ t('teamhub', 'Create') }}
                        </NcButton>
                    </div>
                </div>
            </NcDialog>

            <!-- Delete resource confirmation dialog -->
            <NcDialog
                v-if="pendingDeleteResource"
                :name="t('teamhub', 'Delete resource permanently?')"
                :open="!!pendingDeleteResource"
                @update:open="pendingDeleteResource = null">
                <div class="delete-resource-confirm">
                    <p>
                        {{ t('teamhub', 'This will permanently delete "{name}". This cannot be undone. All data in this {app} will be lost.', {
                            name: pendingDeleteResource.displayName || pendingDeleteResource.resourceId,
                            app: appLabel(pendingDeleteResource.appId),
                        }) }}
                    </p>
                    <div class="delete-resource-confirm__actions">
                        <NcButton variant="tertiary" @click="pendingDeleteResource = null">
                            {{ t('teamhub', 'Cancel') }}
                        </NcButton>
                        <NcButton
                            variant="error"
                            :disabled="deletingResource"
                            @click="executeDeleteResource">
                            <NcLoadingIcon v-if="deletingResource" :size="16" />
                            {{ t('teamhub', 'Delete permanently') }}
                        </NcButton>
                    </div>
                </div>
            </NcDialog>



            <!-- Ignored resources (collapsed, reversible) -->
            <div v-if="ignoredResources.length > 0" class="manage-section">
                <button
                    type="button"
                    class="manage-section-toggle"
                    :aria-expanded="showIgnored"
                    @click="showIgnored = !showIgnored">
                    <ChevronRight v-if="!showIgnored" :size="16" aria-hidden="true" />
                    <ChevronDown v-else :size="16" aria-hidden="true" />
                    <!-- TRANSLATORS: label for a collapsible section listing resources hidden from the team view -->
                    {{ n('teamhub', 'Show %n ignored resource', 'Show %n ignored resources', ignoredResources.length, { n: ignoredResources.length }) }}
                </button>
                <div v-if="showIgnored" class="pending-resources-list pending-resources-list--ignored">
                    <div
                        v-for="resource in ignoredResources"
                        :key="resource.id"
                        class="pending-resource-item">
                        <div class="pending-resource-info">
                            <span class="pending-resource-app">{{ appLabel(resource.appId) }}</span>
                            <span class="pending-resource-name" :title="resource.resourceId">
                                {{ resource.displayName || resource.resourceId }}
                            </span>
                        </div>
                        <NcButton
                            variant="tertiary"
                            :disabled="resource._loading"
                            :aria-label="t('teamhub', 'Un-ignore resource {id}', { id: resource.resourceId })"
                            @click="unignoreResource(resource)">
                            <!-- TRANSLATORS: button label to restore a previously ignored resource back to active status -->
                            {{ t('teamhub', 'Un-ignore') }}
                        </NcButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: Integration settings (Decisions block + Timeline/Milestones block) -->
        <div v-else-if="activeTab === 'integration-settings'" class="manage-tab-content">

            <!-- ── Messages block (v3.104.1) — always rendered. When the
                 Messages integration is off, shows a hint pointing at the
                 Integrations tab. When on, renders the compact role rows +
                 image-cache action. Auto-saves on change (no Save button). -->
            <div v-if="!messagesEnabled" class="manage-section" data-section="messages">
                <h3>{{ t('teamhub', 'Messages') }}</h3>
                <p class="manage-section-desc manage-section-desc--inline">
                    {{ t('teamhub', 'Messages are disabled for this team. Enable the module under the Integrations tab to configure pin and post role limits.') }}
                </p>
            </div>

            <div v-if="messagesEnabled" class="manage-section" data-section="messages">
                <h3>{{ t('teamhub', 'Messages') }}</h3>

                <!-- Pin min-role -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Minimum role to pin') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Who can pin or unpin a message. One message can be pinned at a time.') }}</span>
                    </div>
                    <select
                        v-model="messageSettingsForm.pinMinLevel"
                        :disabled="savingMessageSettings"
                        class="teamhub-dec-level-select"
                        :aria-label="t('teamhub', 'Minimum role to pin')"
                        @change="saveMessageSettingsAuto">
                        <option value="member">{{ t('teamhub', 'Member') }}</option>
                        <option value="moderator">{{ t('teamhub', 'Moderator') }}</option>
                        <option value="admin">{{ t('teamhub', 'Admin / Owner') }}</option>
                    </select>
                </div>

                <!-- Post min-role -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Minimum role to post') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Who can post new messages, questions, and polls.') }}</span>
                    </div>
                    <select
                        v-model="messageSettingsForm.postMinLevel"
                        :disabled="savingMessageSettings"
                        class="teamhub-dec-level-select"
                        :aria-label="t('teamhub', 'Minimum role to post')"
                        @change="saveMessageSettingsAuto">
                        <option value="member">{{ t('teamhub', 'Member') }}</option>
                        <option value="moderator">{{ t('teamhub', 'Moderator') }}</option>
                        <option value="admin">{{ t('teamhub', 'Admin / Owner') }}</option>
                    </select>
                </div>

                <!-- Image cache -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Message image cache') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Images inserted from personal files are copied to a hidden cache folder in the team folder so all members can view them. Clearing shows broken images in existing posts.') }}</span>
                    </div>
                    <NcButton
                        variant="error"
                        :disabled="clearingImageCache || !teamFilesFolderId"
                        :title="!teamFilesFolderId ? t('teamhub', 'No Files folder connected — image cache is not available.') : ''"
                        @click="clearImageCache">
                        <template #icon>
                            <NcLoadingIcon v-if="clearingImageCache" :size="18" />
                            <TrashCan v-else :size="18" aria-hidden="true" />
                        </template>
                        {{ clearingImageCache ? t('teamhub', 'Clearing…') : t('teamhub', 'Clear image cache') }}
                    </NcButton>
                </div>
            </div>

            <!-- ── Decisions block — only rendered at all when the Decisions
                 module is available instance-wide. Within that, shows a hint
                 when this team hasn't toggled it on yet, or the full settings
                 + categories once it has. ──────────────────────────────── -->
            <template v-if="decisionsModuleEnabled">
                <div v-if="!decisionsEnabled" class="manage-section">
                    <h3>{{ t('teamhub', 'Decisions') }}</h3>
                    <p class="manage-section-desc manage-section-desc--inline">
                        {{ t('teamhub', 'Decisions are disabled for this team. Enable the module under the Integrations tab to start managing categories.') }}
                    </p>
                </div>

            <!-- Category management — only when decisions is enabled -->
            <div v-if="decisionsEnabled" class="manage-section">
                <h3>{{ t('teamhub', 'Decisions') }}</h3>

                <!-- Decision level field toggle -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Decision level field') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'Show Operational / Tactical / Strategic level on decisions. Useful for teams that work with strategic taxonomy.') }}</span>
                    </div>
                    <NcCheckboxRadioSwitch
                        :model-value="decisionsLevelEnabled"
                        :disabled="savingDecisionsConfig"
                        type="switch"
                        :aria-label="t('teamhub', 'Enable the Level field for decisions')"
                        @update:model-value="setDecisionsLevelEnabled($event)">
                        {{ decisionsLevelEnabled ? t('teamhub', 'Enabled') : t('teamhub', 'Disabled') }}
                    </NcCheckboxRadioSwitch>
                </div>

                <!-- Minimum role for actions -->
                <div class="manage-section__row">
                    <div class="manage-section__row-info">
                        <span class="manage-section__row-title">{{ t('teamhub', 'Minimum role for actions') }}</span>
                        <span class="manage-section__row-desc">{{ t('teamhub', 'The minimum team role required to link tasks, create tasks, and link decisions on any decision.') }}</span>
                    </div>
                    <select
                        :value="decisionsActionMinLevel"
                        :disabled="savingDecisionsConfig"
                        class="teamhub-dec-level-select"
                        :aria-label="t('teamhub', 'Minimum role for decision actions')"
                        @change="setDecisionsActionMinLevel(Number($event.target.value))">
                        <!-- NC Circles levels: 1=member, 4=moderator, 8=admin -->
                        <option :value="1">{{ t('teamhub', 'Member') }}</option>
                        <option :value="4">{{ t('teamhub', 'Moderator') }}</option>
                        <option :value="8">{{ t('teamhub', 'Admin') }}</option>
                    </select>
                </div>

                <!-- Categories sub-section (v3.104.6 — merged into the parent
                     Decisions section so Integration Settings has one block
                     per integration rather than two adjacent Decisions blocks). -->
                <h4 class="manage-section__subhead">{{ t('teamhub', 'Categories') }}</h4>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Define the categories proposers can choose from. Each category has one or more approvers — the people who can finalize decisions in that category. If you leave the approvers field empty, the team owner is used as the default approver.') }}
                </p>

                <!-- Loading -->
                <div v-if="loadingDecCategories" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>

                <!-- Category list -->
                <template v-else>
                    <ul v-if="decCategories.length" class="teamhub-dec-cats__list teamhub-dec-cats__list--standalone">
                        <li
                            v-for="cat in decCategories"
                            :key="cat.id"
                            class="teamhub-dec-cats__row">


                            <!-- Read mode -->
                            <template v-if="decCatEditing !== cat.id">
                                <div class="teamhub-dec-cats__row-main">
                                    <span v-if="cat.icon && categoryIconMap[cat.icon]" class="teamhub-dec-cats__row-icon" aria-hidden="true">
                                        <component :is="categoryIconMap[cat.icon]" :size="20" />
                                    </span>
                                    <div class="teamhub-dec-cats__row-text">
                                        <span class="teamhub-dec-cats__row-name">{{ cat.name }}</span>
                                        <span v-if="cat.description" class="teamhub-dec-cats__row-desc">{{ cat.description }}</span>
                                    </div>
                                    <span class="teamhub-dec-cats__row-approvers">
                                        {{ formatApprovers(cat.approvers) }}
                                    </span>
                                </div>
                                <div class="teamhub-dec-cats__row-actions">
                                    <NcButton
                                        variant="tertiary"
                                        :aria-label="t('teamhub', 'Edit category {name}', { name: cat.name })"
                                        @click="startEditDecCategory(cat)">
                                        <template #icon><PencilIcon :size="16" /></template>
                                    </NcButton>
                                    <NcButton
                                        variant="tertiary"
                                        :aria-label="t('teamhub', 'Delete category {name}', { name: cat.name })"
                                        @click="confirmDeleteDecCategory(cat)">
                                        <template #icon><Delete :size="16" /></template>
                                    </NcButton>
                                </div>
                            </template>

                            <!-- Edit mode -->
                            <template v-else>
                                <div class="teamhub-dec-cats__edit">
                                    <div class="teamhub-dec-cats__edit-row">
                                        <div class="teamhub-dec-cats__edit-icon-wrap">
                                            <label class="teamhub-dec-cats__edit-label">{{ t('teamhub', 'Icon') }}</label>
                                            <div class="teamhub-dec-cats__icon-picker-wrap">
                                                <button
                                                    type="button"
                                                    class="teamhub-dec-cats__icon-btn"
                                                    :aria-label="t('teamhub', 'Choose category icon')"
                                                    :aria-expanded="decCatIconPickerOpen"
                                                    @click.stop="decCatIconPickerOpen = !decCatIconPickerOpen">
                                                    <component v-if="decCatForm.icon && categoryIconMap[decCatForm.icon]" :is="categoryIconMap[decCatForm.icon]" :size="20" aria-hidden="true" />
                                                    <span v-else class="teamhub-dec-cats__icon-btn-placeholder" aria-hidden="true">—</span>
                                                </button>
                                                <div v-if="decCatIconPickerOpen" class="teamhub-dec-cats__icon-popover" @click.stop>
                                                    <!-- Search inside picker -->
                                                    <div class="teamhub-dec-cats__icon-search-wrap">
                                                        <input
                                                            v-model="catIconSearch"
                                                            type="search"
                                                            class="teamhub-dec-cats__icon-search"
                                                            :placeholder="t('teamhub', 'Search icons…')"
                                                            :aria-label="t('teamhub', 'Search icons')"
                                                            autofocus>
                                                        <button
                                                            type="button"
                                                            class="teamhub-dec-cats__icon-clear-btn"
                                                            :aria-label="t('teamhub', 'Remove icon')"
                                                            @click.stop="clearCatIcon">
                                                            {{ t('teamhub', 'None') }}
                                                        </button>
                                                    </div>
                                                    <!-- Scrollable grouped grid -->
                                                    <div class="teamhub-dec-cats__icon-scroll" role="listbox" :aria-label="t('teamhub', 'Select an icon')">
                                                        <div
                                                            v-for="group in filteredIconGroups"
                                                            :key="group.name"
                                                            class="teamhub-dec-cats__icon-group">
                                                            <div class="teamhub-dec-cats__icon-group-hdr">{{ group.name }}</div>
                                                            <div class="teamhub-dec-cats__icon-group-grid">
                                                                <button
                                                                    v-for="ic in group.icons"
                                                                    :key="ic.name"
                                                                    type="button"
                                                                    class="teamhub-dec-cats__icon-grid-btn"
                                                                    :class="{ 'teamhub-dec-cats__icon-grid-btn--selected': decCatForm.icon === ic.name }"
                                                                    :aria-label="ic.label"
                                                                    :title="ic.label"
                                                                    role="option"
                                                                    :aria-selected="decCatForm.icon === ic.name"
                                                                    @click.stop="selectCatIcon(ic.name)">
                                                                    <component :is="categoryIconMap[ic.name]" :size="20" aria-hidden="true" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <p v-if="!filteredIconGroups.length" class="teamhub-dec-cats__icon-no-results">
                                                            {{ t('teamhub', 'No icons match "{q}"', { q: catIconSearch }) }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="teamhub-dec-cats__edit-name-wrap">
                                            <label class="teamhub-dec-cats__edit-label" :for="`dec-cat-edit-name-${cat.id}`">{{ t('teamhub', 'Name') }}</label>
                                            <input
                                                :id="`dec-cat-edit-name-${cat.id}`"
                                                v-model="decCatForm.name"
                                                type="text"
                                                maxlength="128"
                                                class="teamhub-dec-cats__edit-input">
                                        </div>
                                    </div>

                                    <label class="teamhub-dec-cats__edit-label" :for="`dec-cat-edit-desc-${cat.id}`">{{ t('teamhub', 'Description') }}</label>
                                    <input
                                        :id="`dec-cat-edit-desc-${cat.id}`"
                                        v-model="decCatForm.description"
                                        type="text"
                                        maxlength="500"
                                        class="teamhub-dec-cats__edit-input"
                                        :placeholder="t('teamhub', 'Optional short description shown in the category grid')">

                                    <label class="teamhub-dec-cats__edit-label">{{ t('teamhub', 'Approvers') }}</label>
                                    <p class="teamhub-dec-cats__edit-hint">{{ t('teamhub', 'Leave empty to default to the team owner.') }}</p>
                                    <NcSelect
                                        v-model="decCatForm.approvers"
                                        :options="memberUserOptions"
                                        :multiple="true"
                                        :close-on-select="false"
                                        :clearable="true"
                                        label="displayName"
                                        track-by="userId"
                                        :placeholder="t('teamhub', 'Pick one or more members')"
                                        :aria-label="t('teamhub', 'Approver users')" />

                                    <div class="teamhub-dec-cats__edit-actions">
                                        <NcButton variant="secondary" @click="cancelEditDecCategory">{{ t('teamhub', 'Cancel') }}</NcButton>
                                        <NcButton
                                            variant="primary"
                                            :disabled="savingDecCategory || !decCatForm.name.trim()"
                                            @click="saveDecCategory">
                                            {{ savingDecCategory ? t('teamhub', 'Saving…') : t('teamhub', 'Save') }}
                                        </NcButton>
                                    </div>

                                    <p v-if="decCatFormError" class="teamhub-dec-cats__edit-error" role="alert">
                                        {{ decCatFormError }}
                                    </p>
                                </div>
                            </template>
                        </li>
                    </ul>

                    <p v-else-if="!decCatEditing" class="teamhub-dec-cats__empty-text">
                        {{ t('teamhub', 'No categories yet. Add the first one below.') }}
                    </p>

                    <!-- Add-new form -->
                    <div class="teamhub-dec-cats__add-area">
                        <NcButton
                            v-if="decCatEditing !== 'new'"
                            variant="secondary"
                            @click="startCreateDecCategory">
                            <template #icon><PlusIcon :size="16" /></template>
                            {{ t('teamhub', 'Add category') }}
                        </NcButton>

                        <div v-else class="teamhub-dec-cats__edit">
                                    <div class="teamhub-dec-cats__edit-row">
                                        <div class="teamhub-dec-cats__edit-icon-wrap">
                                            <label class="teamhub-dec-cats__edit-label">{{ t('teamhub', 'Icon') }}</label>
                                            <div class="teamhub-dec-cats__icon-picker-wrap">
                                                <button
                                                    type="button"
                                                    class="teamhub-dec-cats__icon-btn"
                                                    :aria-label="t('teamhub', 'Choose category icon')"
                                                    :aria-expanded="decCatIconPickerOpen"
                                                    @click.stop="decCatIconPickerOpen = !decCatIconPickerOpen">
                                                    <component v-if="decCatForm.icon && categoryIconMap[decCatForm.icon]" :is="categoryIconMap[decCatForm.icon]" :size="20" aria-hidden="true" />
                                                    <span v-else class="teamhub-dec-cats__icon-btn-placeholder" aria-hidden="true">—</span>
                                                </button>
                                                <div v-if="decCatIconPickerOpen" class="teamhub-dec-cats__icon-popover" @click.stop>
                                                    <!-- Search inside picker -->
                                                    <div class="teamhub-dec-cats__icon-search-wrap">
                                                        <input
                                                            v-model="catIconSearch"
                                                            type="search"
                                                            class="teamhub-dec-cats__icon-search"
                                                            :placeholder="t('teamhub', 'Search icons…')"
                                                            :aria-label="t('teamhub', 'Search icons')"
                                                            autofocus>
                                                        <button
                                                            type="button"
                                                            class="teamhub-dec-cats__icon-clear-btn"
                                                            :aria-label="t('teamhub', 'Remove icon')"
                                                            @click.stop="clearCatIcon">
                                                            {{ t('teamhub', 'None') }}
                                                        </button>
                                                    </div>
                                                    <!-- Scrollable grouped grid -->
                                                    <div class="teamhub-dec-cats__icon-scroll" role="listbox" :aria-label="t('teamhub', 'Select an icon')">
                                                        <div
                                                            v-for="group in filteredIconGroups"
                                                            :key="group.name"
                                                            class="teamhub-dec-cats__icon-group">
                                                            <div class="teamhub-dec-cats__icon-group-hdr">{{ group.name }}</div>
                                                            <div class="teamhub-dec-cats__icon-group-grid">
                                                                <button
                                                                    v-for="ic in group.icons"
                                                                    :key="ic.name"
                                                                    type="button"
                                                                    class="teamhub-dec-cats__icon-grid-btn"
                                                                    :class="{ 'teamhub-dec-cats__icon-grid-btn--selected': decCatForm.icon === ic.name }"
                                                                    :aria-label="ic.label"
                                                                    :title="ic.label"
                                                                    role="option"
                                                                    :aria-selected="decCatForm.icon === ic.name"
                                                                    @click.stop="selectCatIcon(ic.name)">
                                                                    <component :is="categoryIconMap[ic.name]" :size="20" aria-hidden="true" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <p v-if="!filteredIconGroups.length" class="teamhub-dec-cats__icon-no-results">
                                                            {{ t('teamhub', 'No icons match "{q}"', { q: catIconSearch }) }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="teamhub-dec-cats__edit-name-wrap">
                                            <label class="teamhub-dec-cats__edit-label" for="dec-cat-name-new-standalone">{{ t('teamhub', 'Name') }}</label>
                                            <input
                                                id="dec-cat-name-new-standalone"
                                                v-model="decCatForm.name"
                                                type="text"
                                                maxlength="128"
                                                class="teamhub-dec-cats__edit-input"
                                                :placeholder="t('teamhub', 'e.g. Architecture, Hiring, Policy')">
                                        </div>
                                    </div>

                                    <label class="teamhub-dec-cats__edit-label" for="dec-cat-desc-new-standalone">{{ t('teamhub', 'Description') }}</label>
                                    <input
                                        id="dec-cat-desc-new-standalone"
                                        v-model="decCatForm.description"
                                        type="text"
                                        maxlength="500"
                                        class="teamhub-dec-cats__edit-input"
                                        :placeholder="t('teamhub', 'Optional short description shown in the category grid')">

                                    <label class="teamhub-dec-cats__edit-label">{{ t('teamhub', 'Approvers') }}</label>
                            <p class="teamhub-dec-cats__edit-hint">{{ t('teamhub', 'Leave empty to default to the team owner.') }}</p>
                            <NcSelect
                                v-model="decCatForm.approvers"
                                :options="memberUserOptions"
                                :multiple="true"
                                :close-on-select="false"
                                :clearable="true"
                                label="displayName"
                                track-by="userId"
                                :placeholder="t('teamhub', 'Pick one or more members')"
                                :aria-label="t('teamhub', 'Approver users')" />

                            <div class="teamhub-dec-cats__edit-actions">
                                <NcButton variant="secondary" @click="cancelEditDecCategory">{{ t('teamhub', 'Cancel') }}</NcButton>
                                <NcButton
                                    variant="primary"
                                    :disabled="savingDecCategory || !decCatForm.name.trim()"
                                    @click="saveDecCategory">
                                    {{ savingDecCategory ? t('teamhub', 'Saving…') : t('teamhub', 'Create') }}
                                </NcButton>
                            </div>

                            <p v-if="decCatFormError" class="teamhub-dec-cats__edit-error" role="alert">
                                {{ decCatFormError }}
                            </p>
                        </div>
                    </div>
                </template>
            </div>
            </template>

            <!-- Milestones management moved to Manage Team → Project (v3.97.4).
                 Milestones own Deck-card ownership intervals for the
                 project-health widget, so they belong with the project's
                 other lifecycle tools. -->
        </div>

        <!-- TAB: Project — only for teams created from the Project template
             (project.isProject). Basic projects can be upgraded to Advanced;
             Advanced projects expose the phase control. Its own tab because a
             project isn't an integration that can be enabled/disabled. -->
        <div v-else-if="activeTab === 'project'" class="manage-tab-content">
            <div class="manage-section">
                <h3>{{ t('teamhub', 'Project') }}</h3>

                <template v-if="project.mode === 'advanced'">
                    <p class="manage-section-desc">
                        {{ t('teamhub', 'Move the project through its lifecycle. The current phase is shown on the team home.') }}
                    </p>
                    <label class="teamhub-project__label" for="project-phase-select">{{ t('teamhub', 'Current phase') }}</label>
                    <select
                        id="project-phase-select"
                        class="teamhub-project__select"
                        :value="project.phase"
                        :disabled="savingProject"
                        @change="changeProjectPhase($event.target.value)">
                        <option
                            v-for="opt in projectPhaseOptions"
                            :key="opt.id"
                            :value="opt.id">
                            {{ opt.label }}
                        </option>
                    </select>
                </template>

                <template v-else>
                    <!-- TRANSLATORS: the four parenthesised phase names should match
                         the translations used for the Initiation/Planning/Execution/
                         Closing labels shown on the project phase stepper. -->
                    <p class="manage-section-desc">
                        {{ t('teamhub', 'This is a Basic project. Upgrade to Advanced to guide it through the project lifecycle (Initiation, Planning, Execution, Closing) and unlock project tools.') }}
                    </p>
                    <NcButton
                        variant="primary"
                        :disabled="savingProject"
                        @click="upgradeProjectToAdvanced">
                        {{ savingProject ? t('teamhub', 'Upgrading…') : t('teamhub', 'Upgrade to Advanced') }}
                    </NcButton>
                </template>
            </div>

            <!-- Project dates — Advanced projects only (v3.98.1). Start
                 and target-end dates anchor the timeline so milestones
                 and the health widget have a range to report against. The
                 backend has always accepted these fields (ProjectController::save
                 with start_date / target_end); this section is the first
                 time they're exposed in the UI, added because the Project
                 Compass's "Set project start and target end dates" item
                 pointed at a screen with no dates form. -->
            <div v-if="project.mode === 'advanced'" class="manage-section" data-section="dates">
                <h3>{{ t('teamhub', 'Project dates') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Anchors the timeline so milestones and the project-health widget have a range to report against. Both fields are optional but recommended.') }}
                </p>
                <div class="teamhub-project__dates">
                    <label class="teamhub-project__label" for="project-start-date">{{ t('teamhub', 'Start date') }}</label>
                    <input
                        id="project-start-date"
                        v-model="projectDatesForm.startDate"
                        type="date"
                        class="teamhub-project__date-input"
                        :disabled="savingProjectDates">

                    <label class="teamhub-project__label" for="project-target-end">{{ t('teamhub', 'Target end date') }}</label>
                    <input
                        id="project-target-end"
                        v-model="projectDatesForm.targetEnd"
                        type="date"
                        class="teamhub-project__date-input"
                        :disabled="savingProjectDates">
                </div>
                <div class="teamhub-project__dates-actions">
                    <NcButton
                        variant="primary"
                        :disabled="savingProjectDates || !projectDatesDirty"
                        @click="saveProjectDates">
                        {{ savingProjectDates ? t('teamhub', 'Saving') : t('teamhub', 'Save dates') }}
                    </NcButton>
                </div>
                <p v-if="projectDatesError" class="teamhub-project__dates-error" role="alert">
                    {{ projectDatesError }}
                </p>
            </div>

            <!-- Milestones — moved here from Integration Settings (v3.97.4).
                 Milestones own the Deck-card ownership intervals the
                 project-health widget's Milestones pillar reads. Timeline
                 also renders them as red marker lines when enabled. -->
            <div class="manage-section" data-section="milestones">
                <h3>{{ t('teamhub', 'Milestones') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Milestones mark key project dates such as launches or deadlines. Every Deck card whose due date falls between a milestone and the previous one belongs to that milestone — the project-health widget uses this to flag work at risk of slipping. When Timeline is enabled, they also appear as red marker lines there.') }}
                </p>

                <p v-if="!timelineEnabled" class="manage-section-desc manage-section-desc--inline">
                    {{ t('teamhub', 'Timeline is disabled for this team — milestones you add here still drive the project-health widget, but will not appear on the Timeline tab until Timeline is enabled under Integration Settings.') }}
                </p>

                <div v-if="loadingMilestones" class="section-loading">
                    <NcLoadingIcon :size="32" />
                </div>

                <template v-else>
                    <ul v-if="milestones.length" class="teamhub-milestones__list" aria-live="polite">
                        <li
                            v-for="m in milestones"
                            :key="m.id"
                            class="teamhub-milestones__row">

                            <!-- Read mode -->
                            <template v-if="milestoneEditing !== m.id">
                                <div class="teamhub-milestones__row-main">
                                    <span class="teamhub-milestones__row-name">{{ m.label }}</span>
                                    <span
                                        class="teamhub-milestones__row-date"
                                        :class="{ 'teamhub-milestones__row-date--unset': !m.date }">
                                        {{ m.date ? formatMilestoneDate(m.date) : t('teamhub', 'No date set — not shown on Timeline') }}
                                    </span>
                                </div>
                                <div class="teamhub-milestones__row-actions">
                                    <NcButton
                                        variant="tertiary"
                                        :aria-label="t('teamhub', 'Edit milestone {name}', { name: m.label })"
                                        @click="startEditMilestone(m)">
                                        <template #icon><PencilIcon :size="16" /></template>
                                    </NcButton>
                                    <NcButton
                                        variant="tertiary"
                                        :aria-label="t('teamhub', 'Delete milestone {name}', { name: m.label })"
                                        @click="confirmDeleteMilestone(m)">
                                        <template #icon><Delete :size="16" /></template>
                                    </NcButton>
                                </div>
                            </template>

                            <!-- Edit mode -->
                            <template v-else>
                                <div class="teamhub-milestones__edit">
                                    <label class="teamhub-milestones__edit-label" :for="`milestone-edit-name-${m.id}`">{{ t('teamhub', 'Name') }}</label>
                                    <input
                                        :id="`milestone-edit-name-${m.id}`"
                                        v-model="milestoneForm.label"
                                        type="text"
                                        maxlength="255"
                                        class="teamhub-milestones__edit-input">

                                    <label class="teamhub-milestones__edit-label" :for="`milestone-edit-date-${m.id}`">{{ t('teamhub', 'Date (optional)') }}</label>
                                    <input
                                        :id="`milestone-edit-date-${m.id}`"
                                        v-model="milestoneForm.date"
                                        type="date"
                                        class="teamhub-milestones__edit-input">

                                    <div class="teamhub-milestones__edit-actions">
                                        <NcButton variant="secondary" @click="cancelEditMilestone">{{ t('teamhub', 'Cancel') }}</NcButton>
                                        <NcButton
                                            variant="primary"
                                            :disabled="savingMilestone || !milestoneForm.label.trim()"
                                            @click="saveMilestone">
                                            {{ savingMilestone ? t('teamhub', 'Saving') : t('teamhub', 'Save') }}
                                        </NcButton>
                                    </div>

                                    <p v-if="milestoneFormError" class="teamhub-milestones__edit-error" role="alert">
                                        {{ milestoneFormError }}
                                    </p>
                                </div>
                            </template>
                        </li>
                    </ul>

                    <p v-else-if="milestoneEditing !== 'new'" class="teamhub-milestones__empty-text">
                        {{ t('teamhub', 'No milestones yet. Add the first one below.') }}
                    </p>

                    <!-- Add-new form -->
                    <div class="teamhub-milestones__add-area">
                        <NcButton
                            v-if="milestoneEditing !== 'new'"
                            variant="secondary"
                            @click="startCreateMilestone">
                            <template #icon><PlusIcon :size="16" /></template>
                            {{ t('teamhub', 'Add milestone') }}
                        </NcButton>

                        <div v-else class="teamhub-milestones__edit">
                            <label class="teamhub-milestones__edit-label" for="milestone-new-name">{{ t('teamhub', 'Name') }}</label>
                            <input
                                id="milestone-new-name"
                                v-model="milestoneForm.label"
                                type="text"
                                maxlength="255"
                                class="teamhub-milestones__edit-input"
                                :placeholder="t('teamhub', 'e.g. Beta launch')">

                            <label class="teamhub-milestones__edit-label" for="milestone-new-date">{{ t('teamhub', 'Date (optional)') }}</label>
                            <input
                                id="milestone-new-date"
                                v-model="milestoneForm.date"
                                type="date"
                                class="teamhub-milestones__edit-input">

                            <div class="teamhub-milestones__edit-actions">
                                <NcButton variant="secondary" @click="cancelEditMilestone">{{ t('teamhub', 'Cancel') }}</NcButton>
                                <NcButton
                                    variant="primary"
                                    :disabled="savingMilestone || !milestoneForm.label.trim()"
                                    @click="saveMilestone">
                                    {{ savingMilestone ? t('teamhub', 'Saving') : t('teamhub', 'Create') }}
                                </NcButton>
                            </div>

                            <p v-if="milestoneFormError" class="teamhub-milestones__edit-error" role="alert">
                                {{ milestoneFormError }}
                            </p>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Budget config — Advanced projects only (v3.92.0, Track E Session 4).
                 Total + currency at project scope; per-lane allocation +
                 view/edit min-level settings underneath. -->
            <div v-if="project.mode === 'advanced'" class="manage-section" data-section="budget">
                <h3>{{ t('teamhub', 'Budget') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Set the project total, then allocate a share to each workstream (Deck stack). Per-workstream view and edit permissions decide who sees the lane and who can add or change expenses.') }}
                </p>

                <div v-if="loadingBudget" class="teamhub-budget-cfg__status">
                    <NcLoadingIcon :size="24" />
                </div>

                <template v-else-if="budgetCfg">
                    <div class="teamhub-budget-cfg__totals">
                        <label class="teamhub-budget-cfg__label">
                            {{ t('teamhub', 'Total budget') }}
                            <input
                                v-model.number="budgetCfg.totalMajor"
                                type="number"
                                step="0.01"
                                min="0"
                                class="teamhub-budget-cfg__input"
                                :placeholder="t('teamhub', 'e.g. 10000')" />
                        </label>
                        <label class="teamhub-budget-cfg__label">
                            {{ t('teamhub', 'Currency') }}
                            <select v-model="budgetCfg.currency" class="teamhub-budget-cfg__select">
                                <option value="">{{ t('teamhub', 'Not set') }}</option>
                                <option v-for="c in currencyOptions" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </label>
                        <label class="teamhub-budget-cfg__label">
                            <span class="teamhub-budget-cfg__label-head">
                                {{ t('teamhub', 'Who sees the Budget tab') }}
                                <button
                                    type="button"
                                    class="teamhub-budget-cfg__help"
                                    :aria-label="t('teamhub', 'Explain who sees the Budget tab')"
                                    @click="showPermInfo()">?</button>
                            </span>
                            <select v-model.number="budgetCfg.budgetViewMinLevel" class="teamhub-budget-cfg__select">
                                <option v-for="opt in laneLevelOptions" :key="'bv-' + opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </label>
                        <NcButton
                            variant="primary"
                            :disabled="savingBudgetTotal"
                            @click="saveBudgetTotal">
                            {{ savingBudgetTotal ? t('teamhub', 'Saving') : t('teamhub', 'Save project settings') }}
                        </NcButton>
                    </div>

                    <p v-if="!budgetCfg.lanes.length" class="manage-section-desc" style="margin-top: 12px;">
                        {{ t('teamhub', 'No Deck stacks found yet. Once a workstream (stack) exists on the project\'s Deck board, it appears here.') }}
                    </p>

                    <table v-else class="teamhub-budget-cfg__lanes">
                        <thead>
                            <tr>
                                <th scope="col">{{ t('teamhub', 'Workstream') }}</th>
                                <th scope="col">{{ t('teamhub', 'Allocated') }}</th>
                                <th scope="col">
                                    {{ t('teamhub', 'Edit from') }}
                                    <button
                                        type="button"
                                        class="teamhub-budget-cfg__help"
                                        :aria-label="t('teamhub', 'Explain edit permission')"
                                        @click="showPermInfo()">?</button>
                                </th>
                                <th scope="col">
                                    {{ t('teamhub', 'Additional editors') }}
                                    <button
                                        type="button"
                                        class="teamhub-budget-cfg__help"
                                        :aria-label="t('teamhub', 'Explain additional editors')"
                                        @click="showPermInfo()">?</button>
                                </th>
                                <th scope="col"><span class="hidden-visually">{{ t('teamhub', 'Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="lane in budgetCfg.lanes" :key="lane.laneId">
                                <td>{{ lane.stackTitle }}</td>
                                <td>
                                    <input
                                        v-model.number="lane.allocatedMajor"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="teamhub-budget-cfg__input teamhub-budget-cfg__input--narrow"
                                        :placeholder="t('teamhub', 'Not set')" />
                                </td>
                                <td>
                                    <select v-model.number="lane.editMinLevel" class="teamhub-budget-cfg__select">
                                        <option v-for="opt in laneLevelOptions" :key="'e-' + opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="teamhub-budget-cfg__editors">
                                        <span v-for="e in lane.editors" :key="e.uid" class="teamhub-budget-cfg__editor-chip">
                                            {{ e.displayName }}
                                            <button
                                                type="button"
                                                class="teamhub-budget-cfg__editor-remove"
                                                :aria-label="t('teamhub', 'Remove {name}', { name: e.displayName })"
                                                @click="removeEditor(lane, e.uid)">
                                                <Close :size="14" />
                                            </button>
                                        </span>
                                        <div class="teamhub-budget-cfg__editor-picker">
                                            <input
                                                v-model="lane.editorSearch"
                                                type="text"
                                                class="teamhub-budget-cfg__input teamhub-budget-cfg__input--narrow"
                                                :placeholder="t('teamhub', 'Add editor')"
                                                @input="onEditorSearchInput(lane)" />
                                            <ul v-if="lane.editorSuggestions && lane.editorSuggestions.length" class="teamhub-budget-cfg__editor-suggestions">
                                                <li
                                                    v-for="s in lane.editorSuggestions"
                                                    :key="'s-' + lane.laneId + '-' + s.uid"
                                                    class="teamhub-budget-cfg__editor-suggestion"
                                                    @mousedown.prevent="addEditor(lane, s)">
                                                    {{ s.displayName }}
                                                    <span class="teamhub-budget-cfg__editor-suggestion-uid">{{ s.uid }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <NcButton
                                        variant="secondary"
                                        :disabled="lane.saving"
                                        @click="saveBudgetLane(lane)">
                                        {{ lane.saving ? t('teamhub', 'Saving') : t('teamhub', 'Save') }}
                                    </NcButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <NcDialog v-if="permInfoOpen"
                        :name="t('teamhub', 'Who can view and edit the Budget')"
                        :open="permInfoOpen"
                        @update:open="permInfoOpen = false">
                        <template #default>
                            <p style="margin: 0 0 8px;">
                                <strong>{{ t('teamhub', 'Who sees the Budget tab') }}</strong> —
                                {{ t('teamhub', 'a single project-level role. A member sees the Budget tab when their team role is at or above this level. Any member who is a named additional editor on ANY workstream also automatically sees the tab, regardless of their role.') }}
                            </p>
                            <p style="margin: 0 0 8px;">
                                <strong>{{ t('teamhub', 'Edit from') }}</strong> —
                                {{ t('teamhub', 'per workstream: the minimum team role that can add, change or remove expenses in that workstream.') }}
                            </p>
                            <p style="margin: 0;">
                                <strong>{{ t('teamhub', 'Additional editors') }}</strong> —
                                {{ t('teamhub', 'per workstream: named members who can edit that workstream regardless of their team role. Being an additional editor on any workstream also unlocks the Budget tab for that member.') }}
                            </p>
                        </template>
                        <template #actions>
                            <NcButton variant="primary" @click="permInfoOpen = false">{{ t('teamhub', 'Got it') }}</NcButton>
                        </template>
                    </NcDialog>
                </template>
            </div>

            <!-- Time investment config — Advanced projects only (v3.96.0, Track E Session 5).
                 Project-level view floor + per-member available-minutes grid.
                 Same rhythm as the Budget section above. -->
            <div v-if="project.mode === 'advanced'" class="manage-section" data-section="time">
                <h3>{{ t('teamhub', 'Time investment') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Add project members with an available-hours budget. Members log time against Deck cards they are assigned to; the Time tab shows logged vs available per person.') }}
                </p>

                <div v-if="loadingTime" class="teamhub-budget-cfg__status">
                    <NcLoadingIcon :size="24" />
                </div>

                <template v-else-if="timeCfg">
                    <div class="teamhub-budget-cfg__totals">
                        <label class="teamhub-budget-cfg__label">
                            <span class="teamhub-budget-cfg__label-head">
                                {{ t('teamhub', 'Who sees the Time tab') }}
                                <button
                                    type="button"
                                    class="teamhub-budget-cfg__help"
                                    :aria-label="t('teamhub', 'Explain who sees the Time tab')"
                                    @click="timePermInfoOpen = true">?</button>
                            </span>
                            <select v-model.number="timeCfg.timeViewMinLevel" class="teamhub-budget-cfg__select">
                                <option v-for="opt in laneLevelOptions" :key="'tv-' + opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </label>
                        <NcButton
                            variant="primary"
                            :disabled="savingTimeCfg"
                            @click="saveTimeConfig">
                            {{ savingTimeCfg ? t('teamhub', 'Saving') : t('teamhub', 'Save project settings') }}
                        </NcButton>
                    </div>

                    <!-- Per-member grid: name / available hours / save.
                         Every team member is automatically a project member —
                         they show up here as soon as they belong to the team,
                         so there is nothing to add or remove from this list.
                         Restrict logging by raising the view floor above or
                         by disabling the Time investment integration entirely. -->
                    <p v-if="!timeCfg.members.length" class="manage-section-desc" style="margin-top: 12px;">
                        {{ t('teamhub', 'No team members found yet. Once a team member exists, they appear here automatically and can log time immediately.') }}
                    </p>
                    <table v-else class="teamhub-budget-cfg__lanes">
                        <thead>
                            <tr>
                                <th scope="col">{{ t('teamhub', 'Member') }}</th>
                                <th scope="col">{{ t('teamhub', 'Available hours') }}</th>
                                <th scope="col"><span class="hidden-visually">{{ t('teamhub', 'Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in timeCfg.members" :key="m.userId">
                                <td>{{ m.displayName }}</td>
                                <td>
                                    <input
                                        v-model.number="m.availableHours"
                                        type="number"
                                        step="0.5"
                                        min="0"
                                        class="teamhub-budget-cfg__input teamhub-budget-cfg__input--narrow"
                                        :placeholder="t('teamhub', 'Uncapped')" />
                                </td>
                                <td>
                                    <NcButton
                                        variant="secondary"
                                        :disabled="m.saving"
                                        @click="saveTimeMember(m)">
                                        {{ m.saving ? t('teamhub', 'Saving') : t('teamhub', 'Save') }}
                                    </NcButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <NcDialog v-if="timePermInfoOpen"
                        :name="t('teamhub', 'Who can view the Time tab')"
                        :open="timePermInfoOpen"
                        @update:open="timePermInfoOpen = false">
                        <template #default>
                            <p style="margin: 0 0 8px;">
                                {{ t('teamhub', 'A single project-level role. A member sees the Time tab when their team role is at or above this level.') }}
                            </p>
                            <p style="margin: 0;">
                                {{ t('teamhub', 'Any team member added to the project (with a row in the grid above) also automatically sees the tab, regardless of their role — being a project participant implies view access.') }}
                            </p>
                        </template>
                        <template #actions>
                            <NcButton variant="primary" @click="timePermInfoOpen = false">{{ t('teamhub', 'Got it') }}</NcButton>
                        </template>
                    </NcDialog>
                </template>
            </div>
        </div>

        <!-- TAB: Danger Zone -->
        <div v-else-if="activeTab === 'danger'" class="manage-tab-content">

            <!-- Set Owner section — only visible to current owner -->
            <div v-if="currentUserIsOwner" class="manage-section">
                <h3>{{ t('teamhub', 'Transfer ownership') }}</h3>
                <p class="manage-section-desc">
                    {{ t('teamhub', 'Assign a new owner from the current team members. The new owner will receive full ownership rights. You will be demoted to admin.') }}
                </p>

                <!-- Member search -->
                <div class="manage-owner-search">
                    <input
                        v-model="ownerSearch"
                        type="text"
                        class="manage-owner-input"
                        :placeholder="t('teamhub', 'Search team members…')"
                        autocomplete="off"
                        @input="onOwnerSearchInput" />

                    <!-- Suggestions dropdown -->
                    <ul v-if="ownerSuggestions.length" class="manage-owner-suggestions">
                        <li
                            v-for="u in ownerSuggestions"
                            :key="u.id"
                            class="manage-owner-suggestion"
                            @mousedown.prevent="selectOwnerSuggestion(u)">
                            <NcAvatar :user="u.id" :display-name="u.displayName" :size="24" :show-user-status="false" :disable-menu="true" />
                            <span class="manage-owner-suggestion__name">{{ u.displayName }}</span>
                            <span class="manage-owner-suggestion__uid">{{ u.id }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Selected user + confirm button -->
                <div v-if="selectedOwner" class="manage-owner-selected">
                    <NcAvatar :user="selectedOwner.id" :display-name="selectedOwner.displayName" :size="28" :show-user-status="false" :disable-menu="true" />
                    <span class="manage-owner-selected__name">{{ selectedOwner.displayName }}</span>
                    <NcButton
                        variant="warning"
                        :disabled="transferringOwner"
                        @click="confirmTransferOwner">
                        <template #icon>
                            <NcLoadingIcon v-if="transferringOwner" :size="20" />
                            <AccountArrowRight v-else :size="20" />
                        </template>
                        {{ t('teamhub', 'Set as owner') }}
                    </NcButton>
                    <NcButton
                        variant="tertiary"
                        :aria-label="t('teamhub', 'Clear selection')"
                        @click="clearOwnerSelection">
                        <template #icon><Close :size="20" /></template>
                    </NcButton>
                </div>
            </div>

            <!-- Danger Zone: Delete (always archives first per admin policy) -->
            <div class="manage-section manage-section--danger">
                <h3>{{ t('teamhub', 'Danger Zone') }}</h3>

                <!-- Pending deletion — team is hidden, grace period is running -->
                <div
                    v-if="archiveStatusRow && archiveStatusRow.status === 'pending'"
                    class="manage-archive-notice manage-archive-notice--pending"
                    role="status">
                    <strong>{{ t('teamhub', 'This team is pending deletion') }}</strong>
                    <span>
                        {{ n('teamhub',
                            'The team will be permanently deleted in {n} day. Administrators can restore it.',
                            'The team will be permanently deleted in {n} days. Administrators can restore it.',
                            archiveStatusRow.daysRemaining,
                            { n: archiveStatusRow.daysRemaining }) }}
                    </span>
                </div>

                <!-- Failed archive — previous attempt failed, retry is available -->
                <div
                    v-if="archiveStatusRow && archiveStatusRow.status === 'failed'"
                    class="manage-archive-notice manage-archive-notice--failed"
                    role="alert">
                    <strong>{{ t('teamhub', 'Previous archive attempt failed') }}</strong>
                    <span v-if="archiveStatusRow.failureReason" class="manage-archive-notice__reason">
                        {{ archiveStatusRow.failureReason }}
                    </span>
                    <span>{{ t('teamhub', 'You can retry below. The previous failed attempt will be cleared automatically.') }}</span>
                </div>

                <div class="manage-danger-row">
                    <div class="manage-danger-info">
                        <span class="manage-danger-title">{{ t('teamhub', 'Delete team') }}</span>
                        <span class="manage-danger-desc">{{ deleteTeamDescription }}</span>
                        <span class="manage-danger-warning" role="note">
                            <AlertIcon :size="16" aria-hidden="true" />
                            <span>{{ t('teamhub', 'Heads up: any app resource connected to this team (calendar, folder, board, conversation) will be permanently deleted along with the team — even if it was created outside TeamHub. To preserve a connected resource, remove this team from its sharing permissions before deleting the team.') }}</span>
                        </span>
                    </div>
                    <NcButton
                        variant="error"
                        :disabled="archiving || deleting || archiveStatusLoading || (archiveStatusRow && archiveStatusRow.status === 'pending')"
                        :aria-label="t('teamhub', 'Delete this team')"
                        @click="onDeleteTeamClicked">
                        <template #icon>
                            <NcLoadingIcon v-if="archiving || archiveStatusLoading || deleting" :size="20" />
                            <Delete v-else :size="20" />
                        </template>
                        {{ archiveStatusRow && archiveStatusRow.status === 'failed'
                            ? t('teamhub', 'Retry delete')
                            : t('teamhub', 'Delete team') }}
                    </NcButton>
                </div>
            </div>

            <!-- Archive modal (only used when admin enabled archive-before-delete) -->
            <ArchiveTeamModal
                :show="showArchiveModal"
                :team-id="team.id"
                :archive-settings="archiveSettings"
                @close="showArchiveModal = false"
                @archived="onTeamArchived" />

            <!-- No-archive delete confirmation dialog -->
            <NcDialog
                v-if="pendingNoArchiveDelete"
                :name="t('teamhub', 'Delete team {name}?', { name: team.name })"
                :open="true"
                @update:open="cancelNoArchiveDelete">
                <template #default>
                    <p style="margin: 0 0 12px;">{{ noArchiveDeleteMessage }}</p>
                    <p style="margin: 0; font-weight: 600; color: var(--color-error-text);">
                        {{ t('teamhub', 'This action cannot be undone.') }}
                    </p>
                </template>
                <template #actions>
                    <NcButton variant="tertiary" @click="cancelNoArchiveDelete">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton variant="error" :disabled="deleting" @click="confirmNoArchiveDelete">
                        <template #icon>
                            <NcLoadingIcon v-if="deleting" :size="20" />
                            <Delete v-else :size="20" />
                        </template>
                        {{ t('teamhub', 'Yes, delete team') }}
                    </NcButton>
                </template>
            </NcDialog>

            <!-- Transfer ownership confirmation dialog -->
            <NcDialog
                v-if="pendingOwnerTransfer"
                :name="t('teamhub', 'Transfer ownership?')"
                :open="true"
                @update:open="pendingOwnerTransfer = null">
                <template #default>
                    <p style="margin: 0 0 12px;">
                        {{ t('teamhub', 'Are you sure you want to make {name} the new owner of this team? You will be demoted to admin.', { name: pendingOwnerTransfer.displayName }) }}
                    </p>
                    <p style="margin: 0; font-weight: 600; color: var(--color-warning-text);">
                        {{ t('teamhub', 'This action cannot be easily undone.') }}
                    </p>
                </template>
                <template #actions>
                    <NcButton variant="tertiary" @click="pendingOwnerTransfer = null">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton variant="warning" @click="executeTransferOwner">
                        <template #icon>
                            <AccountArrowRight :size="20" />
                        </template>
                        {{ t('teamhub', 'Yes, transfer ownership') }}
                    </NcButton>
                </template>
            </NcDialog>
        </div>

        <!-- Hard-delete confirmation dialog -->
        <NcDialog
            v-if="pendingDisableApp"
            :name="t('teamhub', 'Permanently delete {name} data?', { name: pendingDisableApp.label })"
            :open="true"
            @update:open="cancelDisableApp">
            <template #default>
                <p style="margin: 0 0 8px;">
                    {{ t('teamhub', 'Disabling {name} will permanently delete all data associated with this team:', { name: pendingDisableApp.label }) }}
                </p>
                <ul style="margin: 0 0 12px; padding-left: 20px;">
                    <li v-if="pendingDisableApp.id === 'spreed'">{{ t('teamhub', 'The Talk chat room and all messages') }}</li>
                    <li v-if="pendingDisableApp.id === 'files'">{{ t('teamhub', 'The shared team folder and all files inside it') }}</li>
                    <li v-if="pendingDisableApp.id === 'calendar'">{{ t('teamhub', 'The team calendar and all events') }}</li>
                    <li v-if="pendingDisableApp.id === 'deck'">{{ t('teamhub', 'The Deck board and all cards') }}</li>
                    <li v-if="pendingDisableApp.id === 'intravox'">{{ t('teamhub', 'The Intravox team page') }}</li>
                </ul>
                <p style="margin: 0; font-weight: 600; color: var(--color-error-text);">
                    {{ t('teamhub', 'This action cannot be undone.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelDisableApp">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" @click="confirmDisableApp">
                    {{ t('teamhub', 'Yes, permanently delete') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Enable-mode dialog: choose between Create new and Connect existing -->
        <NcDialog
            v-if="pendingEnableApp"
            :name="t('teamhub', 'Enable {name} for this team', { name: pendingEnableApp.label })"
            :open="true"
            @update:open="cancelEnableApp">
            <template #default>
                <p style="margin: 0 0 12px;">
                    {{ t('teamhub', 'How would you like to enable {name}?', { name: pendingEnableApp.label }) }}
                </p>
                <div class="enable-app-modes">
                    <label class="enable-app-mode">
                        <input
                            v-model="enableAppMode"
                            type="radio"
                            value="create"
                            name="enable-app-mode" />
                        <span>{{ t('teamhub', 'Create a new resource for this team') }}</span>
                    </label>
                    <label class="enable-app-mode">
                        <input
                            v-model="enableAppMode"
                            type="radio"
                            value="connect"
                            name="enable-app-mode" />
                        <span>{{ t('teamhub', 'Connect an existing resource I already own') }}</span>
                    </label>
                    <div v-if="enableAppMode === 'connect'" class="enable-app-picker">
                        <ResourcePicker
                            :app="pendingEnableApp.id === 'spreed' ? 'talk' : pendingEnableApp.id"
                            v-model="enableAppResourceId" />
                    </div>
                </div>
                <p style="margin: 12px 0 0; font-size: var(--th-font-meta); color: var(--color-text-maxcontrast);">
                    {{ t('teamhub', 'Note: connected resources stay shared with the team. If the team is deleted, the resource is deleted too. To preserve the resource, remove the team from its sharing permissions before deleting the team.') }}
                </p>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="cancelEnableApp">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    variant="primary"
                    :disabled="enableAppMode === 'connect' && !enableAppResourceId"
                    @click="confirmEnableApp">
                    {{ enableAppMode === 'connect' ? t('teamhub', 'Connect') : t('teamhub', 'Create') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { mapState, mapMutations } from 'vuex'
import { NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch, NcDialog, NcSelect } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import AccountRemove from 'vue-material-design-icons/AccountRemove.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import MessageIcon from 'vue-material-design-icons/Message.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import TextIcon from 'vue-material-design-icons/Text.vue'
import TuneIcon from 'vue-material-design-icons/Tune.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import PuzzleIcon from 'vue-material-design-icons/Puzzle.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import ArchiveTeamModal from './ArchiveTeamModal.vue'
import ResourcePicker from './ResourcePicker.vue'
import InviteMemberModal from './InviteMemberModal.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import BriefcaseIcon from 'vue-material-design-icons/Briefcase.vue'
import { CATEGORY_ICONS, CATEGORY_ICON_MAP } from '../lib/decisionCategoryIcons.js'

// Circles config bitmask constants — canonical values from circlesConfig.js.
// These MUST match OCA\Circles\Model\Circle::CFG_* in the Circles app.
// DO NOT re-define them locally; the pre-3.39.1 values here caused the
// CFG_SINGLE corruption bug (wrote bit 1 for "Anyone can join").
import {
    CFG_OPEN,
    CFG_INVITE,
    CFG_REQUEST,
    CFG_VISIBLE,
    CFG_PROTECTED,
    CFG_ROOT,
} from '../constants/circlesConfig.js'

export default {
    name: 'ManageTeamView',
    components: {
        NcButton, NcLoadingIcon, NcAvatar, NcTextArea, NcCheckboxRadioSwitch, NcDialog, NcSelect,
        ContentSave, AccountRemove, Check, Close, CheckCircle, Delete, DragVertical,
        MessageIcon, FolderIcon, CalendarIcon, CardTextIcon, FileDocumentOutlineIcon,
        ChevronRight, ChevronDown,
        ImageIcon, TrashCanOutline, TrashCan, UploadIcon, AccountArrowRight,
        TextIcon, TuneIcon, AccountMultipleIcon, PuzzleIcon, AlertIcon,
        ArchiveTeamModal,
        ResourcePicker,
        InviteMemberModal,
        AccountPlusIcon, PencilIcon, PlusIcon, GavelIcon, BriefcaseIcon,
    },
    props: {
        team: { type: Object, required: true },
    },
    emits: ['description-updated', 'team-deleted'],
    data() {
        return {
            activeTab: 'description',
            editedDescription: this.team.description || '',
            // Structured member data from /members/manage endpoint
            manageMembers: { direct: [], groups: [], circles: [], effective_count: 0 },
            pendingRequests: [],
            loadingMembers: false,
            loadingPending: false,
            loadingConfig: false,
            saving: false,
            configSaved: false,
            deleting: false,
            archiving: false,
            showArchiveModal: false,
            showInviteModal: false,
            archiveSettings: {},
            changingLevel: null,
            circleConfig: {
                open: false,
                invite: false,
                request: false,
                visible: false,
                protected: false,
                preventSubMembership: false,
            },
            integrationRegistry: [],
            loadingWidgets: false,
            togglingWidget: null,
            // Message settings
            messageSettingsForm: { pinMinLevel: 'moderator', postMinLevel: 'member', linkMinLevel: 'admin' },
            loadingMessageSettings: false,
            savingMessageSettings: false,
            messageSettingsSaved: false,
            clearingImageCache: false,
            dragSourceWidget: null,
            teamApps: [],
            installedApps: {},
            loadingApps: false,
            togglingApp: null,
            // Pending / ignored resources (discovered externally)
            pendingResourceRows: [],   // raw rows from /resources/panel grouped by app, flattened
            loadingPendingResources: false,
            showIgnored: false,
            // Connect existing picker
            connectPickerApp: null,
            connectPickerItems: [],
            loadingConnectPicker: false,
            // Create new resource name dialog
            createResourceApp: null,
            createResourceName: '',
            creatingResource: false,
            // Delete resource confirmation
            pendingDeleteResource: null,
            deletingResource: false,
            pendingDisableApp: null,
            // No-archive delete confirmation dialog
            pendingNoArchiveDelete: false,
            // Enable-mode dialog (Create new vs Connect existing)
            pendingEnableApp: null,
            enableAppMode: 'create',
            enableAppResourceId: null,
            // Team image
            imageUploading: false,
            imageRemoving: false,
            imagePreviewUrl: this.team.image_url || null,
            // Meeting permissions
            loadingMeetingSettings: false,
            meetingMinLevel: 1,
            meetingSettingsSaved: false,
            meetingSettingsError: null,
            // Owner transfer
            ownerSearch: '',
            ownerSuggestions: [],
            ownerSearchTimer: null,
            selectedOwner: null,
            transferringOwner: false,
            pendingOwnerTransfer: null,
            // Archive status (fetched when danger tab opens)
            archiveStatusRow: null,
            archiveStatusLoading: false,
            // Presence config (B3) — loaded when settings tab opens
            presenceEnabled:     false,
            presenceHideReasons: false,
            savingPresenceConfig: false,
            // Decisions config (Session A) — loaded when settings tab opens
            decisionsEnabled:      false,
            decisionsLevelEnabled: false,
            decisionsActionMinLevel: 1,  // Session B: default = Member
            savingDecisionsConfig: false,
            // Timeline config (v3.77.20) — per-team enable flag. Default true
            // matches the backend default so first paint doesn't flicker the
            // toggle off before loadTimelineConfig() resolves.
            timelineEnabled:       true,
            // Messages integration toggle (v3.104.1). Default true matches the
            // backend default so first paint doesn't flicker the Disabled state
            // before loadMessagesConfig resolves.
            messagesEnabled:       true,
            savingMessagesConfig:  false,
            // Budget integration toggle (v3.92.0) — Advanced projects only.
            // Row is v-if'd on project.mode === 'advanced'; the flag defaults
            // true so the toggle off before loadBudgetConfig resolves doesn't
            // briefly show a Disabled switch.
            budgetEnabled:         true,
            savingBudgetConfig:    false,
            savingTimelineConfig:  false,

            // Decision categories — Session G
            decCategories:          [],
            loadingDecCategories:   false,
            decCatEditing:          null,
            decCatForm:             { name: '', icon: '', description: '', approvers: [] },
            decCatIconPickerOpen:   false,   // controls the MDI icon picker popover
            catIconSearch:          '',      // search filter inside the icon picker
            savingDecCategory:      false,
            decCatFormError:        '',

            // Timeline Milestones (v3.78.2) — admin-managed marker lines
            // shown on the Timeline tab. Mirrors the decCategories pattern.
            milestones:             [],
            loadingMilestones:      false,
            milestoneEditing:       null,   // null | 'new' | <id>
            milestoneForm:          { label: '', date: '' },
            savingMilestone:        false,
            milestoneFormError:     '',
            savingProject:          false,
            // v3.98.1 — Project dates form. Populated from `project` state
            // on tab enter; two ISO 'YYYY-MM-DD' strings or empty.
            projectDatesForm:       { startDate: '', targetEnd: '' },
            savingProjectDates:     false,
            projectDatesError:      '',
            // Budget config (v3.92.0, Track E Session 4). budgetCfg is null
            // until the Project tab loads. lanes' saving flags live on the
            // lane objects themselves so multiple rows can save independently.
            loadingBudget:          false,
            budgetCfg:              null, // { totalMajor, currency, lanes: [{ laneId, stackTitle, allocatedMajor, viewMinLevel, editMinLevel, editors, editorSearch, editorSuggestions, saving }] }
            savingBudgetTotal:      false,
            // "?" popup explaining the permission model. Section is a string
            // marker, kept simple since the dialog body renders all three
            // sections at once — no per-section state needed.
            permInfoOpen:           false,

            // Time investment integration toggle (v3.96.0) — same pattern
            // as budget. Advanced projects only.
            timeEnabled:            true,
            savingTimeConfig:       false,
            // Time config (v3.96.0). Same shape/rhythm as budgetCfg. Members
            // are auto-populated from the team roster on the backend
            // (reconcile-on-read), so this UI only sets available-hours per
            // person; there is no add or remove.
            loadingTime:            false,
            timeCfg:                null, // { timeViewMinLevel, members: [{ userId, displayName, availableHours, availableMinutes, loggedMinutes, saving }] }
            savingTimeCfg:          false,
            timePermInfoOpen:       false,
        }
    },
    computed: {
        ...mapState(['intravoxAvailable', 'resourceWarningFocus', 'presenceModuleEnabled', 'decisionsModuleEnabled', 'project', 'projectTabFocus', 'manageTeamDeepLink']),

        /** Ordered PMC phases — must match ProjectService::PHASES on the backend. */
        projectPhaseOptions() {
            return [
                { id: 'initiation', label: t('teamhub', 'Initiation') },
                { id: 'planning',   label: t('teamhub', 'Planning') },
                { id: 'execution',  label: t('teamhub', 'Execution') },
                { id: 'closing',    label: t('teamhub', 'Closing') },
            ]
        },

        /**
         * v3.98.1 — the dates form values differ from what's persisted in
         * the store's project fact. Drives the Save button disabled state.
         */
        projectDatesDirty() {
            const toIso = ts => (ts && Number.isFinite(ts))
                ? new Date(ts * 1000).toISOString().slice(0, 10)
                : ''
            return this.projectDatesForm.startDate !== toIso(this.project?.startDate)
                || this.projectDatesForm.targetEnd !== toIso(this.project?.targetEnd)
        },

        /** Budget config — currency picker options. Must match BudgetService::KNOWN_CURRENCIES. */
        currencyOptions() {
            return ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'DKK', 'SEK', 'NOK', 'CAD', 'AUD']
        },

        /** Budget config — per-lane view/edit min-level dropdown options. */
        laneLevelOptions() {
            return [
                { value: 1, label: t('teamhub', 'Every member') },
                { value: 4, label: t('teamhub', 'Moderators + admins') },
                { value: 8, label: t('teamhub', 'Admins only') },
            ]
        },

        // Category icon helpers — used by the MDI icon picker and list rendering
        categoryIconList() { return CATEGORY_ICONS },
        categoryIconMap()  { return CATEGORY_ICON_MAP },

        // Group + filter icons for the picker UI.
        // Returns [{ name, icons: [...] }, ...] for groups that have matches.
        filteredIconGroups() {
            const q = this.catIconSearch.trim().toLowerCase()
            const groups = new Map()
            for (const ic of CATEGORY_ICONS) {
                if (q && !ic.label.toLowerCase().includes(q) && !ic.name.toLowerCase().includes(q)) {
                    continue
                }
                if (!groups.has(ic.group)) groups.set(ic.group, [])
                groups.get(ic.group).push(ic)
            }
            return [...groups.entries()].map(([name, icons]) => ({ name, icons }))
        },

        /** Numeric fileId of the team folder root, or null if none connected.
         *  Used by the image cache clear button. */
        teamFilesFolderId() {
            return this.$store.state.resources?.files?.folder_id || null
        },

        /** True when the current user holds admin or owner level on this team. */
        isTeamAdmin() {
            // ManageTeamView is only rendered for admins/owners; this is an
            // extra guard for the presence toggles which are admin-only.
            return true
        },
        activeResourcesByApp() {
            return (appId) => this.pendingResourceRows.filter(r => r.appId === appId && r.status === 'active')
        },

        /** All active files resource rows. */
        activeFilesRows() {
            return this.pendingResourceRows.filter(r => r.appId === 'files' && r.status === 'active')
        },

        /**
         * The "primary" active files row.
         * Prefers the GF row so that dual-folder state (shared + GF both active during manual
         * migration) correctly reports the GF as the leading resource.
         */
        activeFilesRow() {
            const rows = this.activeFilesRows
            if (!rows.length) return null
            return rows.find(r => r.resourceId.startsWith('gf:')) || rows[0]
        },

        /** True when any active files resource is a legacy shared folder. */
        activeFilesIsShared() {
            return this.activeFilesRows.some(r => !r.resourceId.startsWith('gf:'))
        },

        /** True when any active files resource is a group folder. */
        activeFilesIsGf() {
            return this.activeFilesRows.some(r => r.resourceId.startsWith('gf:'))
        },

        /** Toggle-driven apps (not resource-backed) — Intravox only. Shared Files is always-on via the File Center widget. */
        toggleApps() {
            return (this.teamAppsList || []).filter(a => ['intravox'].includes(a.id))
        },

        /** Title for the connect picker dialog. */
        connectPickerTitle() {
            const labels = {
                talk:     t('teamhub', 'Connect a Talk room'),
                files:    t('teamhub', 'Connect a shared folder'),
                calendar: t('teamhub', 'Connect a calendar'),
                deck:     t('teamhub', 'Connect a Deck board'),
            }
            return labels[this.connectPickerApp] || t('teamhub', 'Connect resource')
        },

        /** Title for the create new dialog. */
        createResourceDialogTitle() {
            const labels = {
                talk:     t('teamhub', 'Create a Talk room'),
                calendar: t('teamhub', 'Create a calendar'),
                deck:     t('teamhub', 'Create a Deck board'),
            }
            return labels[this.createResourceApp] || t('teamhub', 'Create resource')
        },

        /** Placeholder for the name input in the create dialog. */
        createResourceNamePlaceholder() {
            const labels = {
                talk:     t('teamhub', 'e.g. Team Chat'),
                calendar: t('teamhub', 'e.g. Team Schedule'),
                deck:     t('teamhub', 'e.g. Sprint Board'),
            }
            return labels[this.createResourceApp] || ''
        },

        /** Flat list of pending resource rows (status=pending). */
        pendingResources() {
            return this.pendingResourceRows.filter(r => r.status === 'pending')
        },

        /** Flat list of ignored resource rows (status=ignored). */
        ignoredResources() {
            return this.pendingResourceRows.filter(r => r.status === 'ignored')
        },

        /** Active resources with a risk flag set. */
        atRiskResources() {
            return this.pendingResourceRows.filter(r => r.riskStatus && r.riskStatus !== 'none')
        },

        /** The pending gf: row tagged isDualFolderPending by the backend. */
        dualFolderPendingRow() {
            return this.pendingResourceRows.find(r => r.isDualFolderPending) || null
        },

        /** The active legacy shared-folder row during dual-folder state. */
        dualFolderSharedRow() {
            if (!this.dualFolderPendingRow) return null
            return this.pendingResourceRows.find(
                r => r.appId === 'files' && r.status === 'active' && !r.resourceId.startsWith('gf:')
            ) || null
        },

        /** Pending rows that are NOT the dual-folder gf: row. */
        normalPendingResources() {
            return this.pendingResourceRows.filter(r => r.status === 'pending' && !r.isDualFolderPending)
        },

        // Description text under "Delete team" — depends on archive setting + mode
        deleteTeamDescription() {
            if (this.archiveSettings.archiveBeforeDelete) {
                return t('teamhub', 'Export all team data to an archive file, then delete the team. An administrator can restore the team during the grace period.')
            }
            // No archive — text reflects the chosen deletion mode
            switch (this.archiveSettings.archiveMode) {
            case 'soft30':
                return t('teamhub', 'Delete the team without producing an archive. The team is hidden immediately and permanently deleted after 30 days. An administrator can restore it before then.')
            case 'soft60':
                return t('teamhub', 'Delete the team without producing an archive. The team is hidden immediately and permanently deleted after 60 days. An administrator can restore it before then.')
            case 'hard':
            default:
                return t('teamhub', 'Permanently delete the team and all its data immediately. No archive is produced. This action cannot be undone.')
            }
        },

        // Confirmation message in the no-archive dialog — mirrors the description
        noArchiveDeleteMessage() {
            switch (this.archiveSettings.archiveMode) {
            case 'soft30':
                return t('teamhub', 'The team will be hidden immediately and permanently deleted in 30 days. An administrator can restore it before then. No archive will be produced.')
            case 'soft60':
                return t('teamhub', 'The team will be hidden immediately and permanently deleted in 60 days. An administrator can restore it before then. No archive will be produced.')
            case 'hard':
            default:
                return t('teamhub', 'The team and all its data will be permanently deleted immediately. No archive will be produced.')
            }
        },

        tabs() {
            const list = [
                { key: 'description',  label: t('teamhub', 'General'),  icon: 'TextIcon' },
                { key: 'settings',     label: t('teamhub', 'Settings'),     icon: 'TuneIcon' },
                { key: 'members',      label: t('teamhub', 'Members'),      icon: 'AccountMultipleIcon' },
            ]
            // Project tab — only for teams created from the Project template.
            // A project isn't an integration that can be enabled/disabled, so
            // it gets its own tab rather than living under Integration settings.
            if (this.project && this.project.isProject) {
                list.push({ key: 'project', label: t('teamhub', 'Project'), icon: 'BriefcaseIcon' })
            }
            list.push({ key: 'integrations', label: t('teamhub', 'Integrations'), icon: 'PuzzleIcon' })
            // Integration settings tab — Decisions config (when the module is
            // enabled) plus Timeline Milestones (always available, gated only
            // by the per-team Timeline toggle inside the tab itself). Unlike
            // the old Decisions-only tab, this one is always shown.
            list.push({ key: 'integration-settings', label: t('teamhub', 'Integration settings'), icon: 'GavelIcon' })
            list.push({ key: 'danger', label: t('teamhub', 'Maintenance'), icon: 'AlertIcon' })
            return list
        },

        invitationOptions() {
            return [
                { key: 'open',    label: t('teamhub', 'Anyone can join (no invitation needed)') },
                { key: 'invite',  label: t('teamhub', 'Members can invite others') },
                { key: 'request', label: t('teamhub', 'Membership requests must be approved by a Moderator (requires "Anyone can join")') },
            ]
        },
        privacyOptions() {
            return [
                { key: 'visible',   label: t('teamhub', 'Visible to everyone') },
                { key: 'protected', label: t('teamhub', 'Enforce password protection on files shared with this team') },
            ]
        },
        configValue() {
            let v = 0
            if (this.circleConfig.open)         v |= CFG_OPEN
            if (this.circleConfig.invite)        v |= CFG_INVITE
            if (this.circleConfig.request)       v |= CFG_REQUEST
            if (this.circleConfig.visible)       v |= CFG_VISIBLE
            if (this.circleConfig.protected)     v |= CFG_PROTECTED
            if (this.circleConfig.preventSubMembership) v |= CFG_ROOT
            // CFG_SINGLE (1) intentionally omitted — managed internally by Circles
            return v
        },
        currentUserId() {
            return getCurrentUser()?.uid
        },
        currentUserLevel() {
            const me = this.manageMembers.direct.find(m => m.userId === this.currentUserId)
            return me ? (me.level || 1) : 1
        },
        currentUserIsOwner() {
            return this.currentUserLevel >= 9
        },
        isAdminOrOwner() {
            return this.currentUserLevel >= 8
        },

        /**
         * Only EXTERNAL (non-builtin) integrations.
         *
         * Built-in NC apps (Talk, Files, Calendar, Deck) are seeded into the
         * registry as is_builtin=true by seedBuiltins() in IntegrationService.
         * They are managed under "Team Apps" (Settings tab). They did NOT
         * register via the external integration API and must not appear here.
         */
        externalIntegrations() {
            return this.integrationRegistry.filter(i => !i.is_builtin)
        },

        teamAppsList() {
            const definitions = [
                {
                    id: 'spreed',
                    label: t('teamhub', 'Talk'),
                    description: t('teamhub', 'Team chat and video calls'),
                    icon: MessageIcon,
                    installed: !!this.installedApps.talk,
                },
                {
                    id: 'files',
                    label: t('teamhub', 'Files'),
                    description: t('teamhub', 'Shared team folder'),
                    icon: FolderIcon,
                    installed: true,
                },
                {
                    id: 'calendar',
                    label: t('teamhub', 'Calendar'),
                    description: t('teamhub', 'Team calendar and events'),
                    icon: CalendarIcon,
                    installed: !!this.installedApps.calendar,
                },
                {
                    id: 'deck',
                    label: t('teamhub', 'Deck'),
                    description: t('teamhub', 'Kanban task board'),
                    icon: CardTextIcon,
                    installed: !!this.installedApps.deck,
                },
                {
                    id: 'intravox',
                    label: t('teamhub', 'Pages'),
                    description: t('teamhub', 'Team wiki and pages (Intravox)'),
                    icon: FileDocumentOutlineIcon,
                    installed: !!this.intravoxAvailable,
                },
            ]
            return definitions
                .filter(def => def.installed)
                .map(def => {
                    const row = this.teamApps.find(a => a.app_id === def.id)
                    const enabled = row ? row.enabled : true
                    return { ...def, enabled }
                })
        },

        /**
         * Members shaped for NcSelect (multiselect of approvers).
         * v3.71.9 — switched from manageMembers.direct → allEffectiveMembers
         * so members added indirectly (via a group or sub-team) appear in
         * the approver picker too. The label falls back to the UID only
         * when displayName is missing, so we never render an empty option.
         * MUST live in `computed`, not `watch` — it returns a value.
         */
        memberUserOptions() {
            const list = this.$store.state.allEffectiveMembers || []
            return list
                .filter(m => m.userId)
                .map(m => ({
                    userId:      m.userId,
                    displayName: m.displayName && m.displayName.trim() ? m.displayName : m.userId,
                }))
        },
    },
    watch: {
        'team.id'() {
            this.editedDescription = this.team.description || ''
            this.activeTab = 'description'
            this.archiveStatusRow = null
            this.loadAll()
        },
        // When the warning block button is clicked, auto-switch to settings tab.
        resourceWarningFocus(focused) {
            if (focused && this.activeTab !== 'settings') {
                this.activeTab = 'settings'
                // The activeTab watcher handles the scroll + flag clear.
            }
        },
        // "Open Project settings" in ProjectPhaseGuide (v3.90.x) — same
        // deep-link pattern as resourceWarningFocus, but no sub-section to
        // scroll to, so the flag is cleared right here.
        projectTabFocus(focused) {
            if (focused) {
                this.activeTab = 'project'
                this.SET_PROJECT_TAB_FOCUS(false)
            }
        },
        // v3.98.0 — Project Compass deep-link. Switch to the requested tab,
        // then on the next tick scroll to the named section (data-section
        // anchor). Clears the store payload so subsequent identical clicks
        // still fire the watcher (nonce on the payload guarantees identity
        // change even when tab+section are unchanged).
        manageTeamDeepLink(payload) {
            if (!payload) return
            this.activeTab = payload.tab
            const section = payload.section
            this.$nextTick(() => {
                if (section && section !== 'top') {
                    const el = this.$el.querySelector(`[data-section="${section}"]`)
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' })
                        el.classList.add('manage-section--highlight')
                        setTimeout(() => el.classList.remove('manage-section--highlight'), 1800)
                    }
                }
                this.$store.commit('SET_MANAGE_TEAM_DEEP_LINK', null)
            })
        },
        activeTab(tab) {
            if (tab === 'danger') {
                this.loadArchiveStatus()
                this.loadArchiveSettings()
            }
            if (tab === 'integrations') {
                this.loadPresenceConfig()
                this.loadDecisionsConfig()
                this.loadTimelineConfig()
                this.loadMessagesConfig()
                this.loadBudgetConfig()
                this.loadTimeConfig()
            }
            if (tab === 'integration-settings') {
                // Refresh decisions config and categories when switching to
                // this tab.
                this.loadDecisionsConfig()
                // v3.104.1 — Messages settings (pin/post role, image cache)
                // moved here from the Permissions tab. Refresh on tab entry so
                // an admin toggling messages off/on elsewhere sees fresh state.
                this.loadMessagesConfig()
                this.loadMessageSettings()
                // v3.71.9 — ensure the approver picker has the full effective
                // member list (incl. indirect via groups/sub-teams).
                this.$store.dispatch('fetchAllEffectiveMembers', this.team.id)
            }
            if (tab === 'project') {
                this.loadBudget()
                this.loadTime()
                // v3.97.4 — milestone management moved here from Integration Settings.
                this.loadMilestones()
                // v3.98.1 — dates form appears here now; hydrate it from
                // the store's project fact on every enter (project may
                // have been updated on another tab).
                this.hydrateProjectDatesForm()
            }
            // If the warning block sent us here with focus flag set, scroll to at-risk section.
            if (tab === 'settings' && this.resourceWarningFocus) {
                this.$nextTick(() => {
                    const el = this.$el.querySelector('.manage-section--atrisk-inline') ||
                               this.$el.querySelector('.manage-section--atrisk')
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' })
                        el.classList.add('manage-section--highlight')
                        setTimeout(() => el.classList.remove('manage-section--highlight'), 1800)
                    }
                    this.SET_RESOURCE_WARNING_FOCUS(false)
                })
            }
        },
    },
    mounted() {
        this.loadAll()
        this._iconPickerOutside = (e) => {
            if (this.decCatIconPickerOpen && !e.target.closest('.teamhub-dec-cats__icon-picker-wrap')) {
                this.decCatIconPickerOpen = false
            }
        }
        document.addEventListener('click', this._iconPickerOutside)

        // v3.98.1 — pick up a deep-link that was committed to the store
        // BEFORE this component mounted. That's the common Compass flow:
        // Compass writes SET_MANAGE_TEAM_DEEP_LINK then emits show-manage-team,
        // App.vue mounts ManageTeamView, so the watcher on the deep-link
        // never fires (the value was already there at mount time). Applying
        // it here in mounted() closes that race.
        if (this.manageTeamDeepLink) {
            const payload = this.manageTeamDeepLink
            this.activeTab = payload.tab
            const section = payload.section
            this.$nextTick(() => {
                if (section && section !== 'top') {
                    const el = this.$el.querySelector(`[data-section="${section}"]`)
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'start' })
                        el.classList.add('manage-section--highlight')
                        setTimeout(() => el.classList.remove('manage-section--highlight'), 1800)
                    }
                }
                this.$store.commit('SET_MANAGE_TEAM_DEEP_LINK', null)
            })
        }
    },

    beforeDestroy() {
        document.removeEventListener('click', this._iconPickerOutside)
    },
    methods: {
        t, n,

        ...mapMutations(['SET_RESOURCE_WARNING_FOCUS', 'SET_PRESENCE_CONFIG', 'SET_DECISIONS_CONFIG', 'SET_TIMELINE_CONFIG', 'SET_MESSAGES_CONFIG', 'SET_BUDGET_CONFIG', 'SET_TIME_CONFIG', 'SET_PROJECT_TAB_FOCUS']),

        loadAll() {
            this.loadMembers()
            this.loadPendingRequests()
            this.loadConfig()
            this.loadTeamApps()
            this.loadIntegrationRegistry()
            this.loadMeetingSettings()
            this.loadPresenceConfig()
            this.loadDecisionsConfig()
            this.loadTimelineConfig()
            this.loadMessagesConfig()
            // Message role settings (pin/post/link) moved from the Permissions
            // tab in v3.104.1 — pin/post live in Integration Settings → Messages,
            // link lives in Settings → Custom links. Load once so both tabs see
            // populated selects on first render.
            this.loadMessageSettings()
        },

        getMemberRoleLabel(level) {
            if (level >= 9) return t('teamhub', 'Owner')
            if (level >= 8) return t('teamhub', 'Admin')
            if (level >= 4) return t('teamhub', 'Moderator')
            return t('teamhub', 'Member')
        },

        canChangeLevel(member) {
            if (this.currentUserLevel < 8) return false
            if (member.userId === this.currentUserId) return false
            if (member.level >= 9) return false
            return true
        },

        canRemoveMember(member) {
            return member.userId !== this.currentUserId && member.level < 9
        },

        async changeLevel(member, newLevel) {
            if (newLevel === member.level) return
            this.changingLevel = member.userId
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${member.userId}/level`),
                    { level: newLevel }
                )
                showSuccess(t('teamhub', 'Role updated'))
                await this.loadMembers()
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to update role: {error}', { error: msg }) : t('teamhub', 'Failed to update role'))
                await this.loadMembers()
            } finally {
                this.changingLevel = null
            }
        },

        async saveDescription() {
            this.saving = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/description`),
                    { description: this.editedDescription }
                )
                showSuccess(t('teamhub', 'Description updated'))
                this.$emit('description-updated', this.editedDescription)
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to update description: {error}', { error: msg }) : t('teamhub', 'Failed to update description'))
            } finally {
                this.saving = false
            }
        },

        async loadConfig() {
            this.loadingConfig = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`)
                )
                const v = data.config || 0
                this.circleConfig.open         = !!(v & CFG_OPEN)
                this.circleConfig.invite        = !!(v & CFG_INVITE)
                this.circleConfig.request       = !!(v & CFG_REQUEST)
                this.circleConfig.visible       = !!(v & CFG_VISIBLE)
                this.circleConfig.protected     = !!(v & CFG_PROTECTED)
                this.circleConfig.preventSubMembership = !!(v & CFG_ROOT)
                // CFG_SINGLE (1) not read — it is managed internally by Circles
            } catch (e) {
            } finally {
                this.loadingConfig = false
            }
        },

        async saveConfig() {
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/config`),
                    { config: this.configValue }
                )
                this.configSaved = true
                setTimeout(() => { this.configSaved = false }, 2000)
            } catch (error) {
                showError(t('teamhub', 'Failed to save settings'))
            }
        },

        /**
         * Called when InviteMemberModal finishes — reload the member list
         * so newly invited users appear immediately.
         */
        onMembersInvited() {
            this.showInviteModal = false
            this.loadMembers()
            // Refresh the full effective member list in the store so @mention
            // autocomplete immediately includes users from any newly added group.
            this.$store.dispatch('fetchAllEffectiveMembers', this.team.id)
        },

        async loadMembers() {
            this.loadingMembers = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/manage`)
                )
                this.manageMembers = {
                    direct:          Array.isArray(data.direct)  ? data.direct  : [],
                    groups:          Array.isArray(data.groups)  ? data.groups  : [],
                    circles:         Array.isArray(data.circles) ? data.circles : [],
                    effective_count: data.effective_count || 0,
                }
            } catch (e) {
                showError(t('teamhub', 'Failed to load members'))
            } finally {
                this.loadingMembers = false
            }
        },
        async loadPendingRequests() {
            this.loadingPending = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/pending-requests`)
                )
                this.pendingRequests = Array.isArray(data) ? data : []
            } catch (e) {
                this.pendingRequests = []
            } finally {
                this.loadingPending = false
            }
        },

        async removeMember(userId, type = 'user') {
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/members/${userId}`),
                    { params: { type } }
                )
                const typeLabel = type === 'group'
                    ? t('teamhub', 'Group removed')
                    : type === 'circle'
                        ? t('teamhub', 'Team removed')
                        : t('teamhub', 'Member removed')
                showSuccess(typeLabel)
                await this.loadMembers()
            } catch (e) {
                showError(t('teamhub', 'Failed to remove member'))
            }
        },

        async approve(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/approve/${req.userId}`))
                showSuccess(t('teamhub', '{name} has been approved', { name: req.displayName }))
                await Promise.all([this.loadMembers(), this.loadPendingRequests()])
            } catch (e) {
                showError(t('teamhub', 'Failed to approve request'))
            }
        },

        async reject(req) {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/reject/${req.userId}`))
                showSuccess(t('teamhub', 'Request rejected'))
                await this.loadPendingRequests()
            } catch (e) {
                showError(t('teamhub', 'Failed to reject request'))
            }
        },

        // ------------------------------------------------------------------
        // Owner transfer
        // ------------------------------------------------------------------

        onOwnerSearchInput() {
            clearTimeout(this.ownerSearchTimer)
            this.selectedOwner = null
            const q = this.ownerSearch.trim().toLowerCase()
            if (q.length < 1) {
                this.ownerSuggestions = []
                return
            }
            // Team owners can only transfer ownership to an existing direct member.
            const matches = this.manageMembers.direct
                .filter(m => m.userId && m.userId !== this.currentUserId)
                .filter(m => {
                    const name = (m.displayName || '').toLowerCase()
                    const uid  = (m.userId || '').toLowerCase()
                    return name.includes(q) || uid.includes(q)
                })
                .slice(0, 10)
                .map(m => ({
                    id:          m.userId,
                    displayName: m.displayName || m.userId,
                }))
            this.ownerSuggestions = matches
        },

        selectOwnerSuggestion(user) {
            this.selectedOwner   = user
            this.ownerSearch     = user.displayName
            this.ownerSuggestions = []
        },

        clearOwnerSelection() {
            this.selectedOwner    = null
            this.ownerSearch      = ''
            this.ownerSuggestions = []
        },

        confirmTransferOwner() {
            if (!this.selectedOwner) return
            this.pendingOwnerTransfer = this.selectedOwner
        },

        async executeTransferOwner() {
            const target = this.pendingOwnerTransfer
            this.pendingOwnerTransfer = null
            if (!target) return

            this.transferringOwner = true
            try {
                const params = new URLSearchParams()
                params.set('userId', target.id)
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/transfer-owner`),
                    params.toString(),
                    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
                )
                showSuccess(t('teamhub', '{name} is now the team owner', { name: target.displayName }))
                this.clearOwnerSelection()
                // Reload members so level badges update
                await this.loadMembers()
            } catch (e) {
                const msg = e.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to transfer ownership: {error}', { error: msg }) : t('teamhub', 'Failed to transfer ownership'))
            } finally {
                this.transferringOwner = false
            }
        },

        async loadArchiveStatus() {
            this.archiveStatusLoading = true
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/archive/status`))
                this.archiveStatusRow = data.pending || null
            } catch (err) {
                // Non-fatal — just don't show a stale failed banner
                this.archiveStatusRow = null
            } finally {
                this.archiveStatusLoading = false
            }
        },

        async loadArchiveSettings() {
            try {
                const { data } = await axios.get(generateUrl('/apps/teamhub/api/v1/admin/archive/settings'))
                this.archiveSettings = data
            } catch (err) {
                this.archiveSettings = {}
            }
        },

        // Decide the delete-team flow based on admin's archive setting:
        //   archiveBeforeDelete=true  → open the existing archive modal
        //   archiveBeforeDelete=false → open the no-archive confirmation dialog
        async onDeleteTeamClicked() {
            // Refresh archive settings before deciding so we honour the latest admin policy.
            await this.loadArchiveSettings()

            if (this.archiveSettings.archiveBeforeDelete) {
                this.showArchiveModal = true
            } else {
                this.pendingNoArchiveDelete = true
            }
        },

        cancelNoArchiveDelete() {
            this.pendingNoArchiveDelete = false
        },

        async confirmNoArchiveDelete() {
            const mode = this.archiveSettings.archiveMode
            this.deleting = true
            try {
                if (mode === 'soft30' || mode === 'soft60') {
                    // Soft delete without archive — pending row + grace period.
                    const { data } = await axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/soft-delete`)
                    )
                    this.archiveStatusRow = data
                    showSuccess(t('teamhub', 'Team scheduled for deletion'))
                    this.$emit('team-deleted')
                } else {
                    // Hard delete without archive — straight DELETE.
                    await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}`))
                    showSuccess(t('teamhub', 'Team deleted'))
                    this.$emit('team-deleted')
                }
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg
                    ? t('teamhub', 'Failed to delete team: {error}', { error: msg })
                    : t('teamhub', 'Failed to delete team')
                )
            } finally {
                this.deleting = false
                this.pendingNoArchiveDelete = false
            }
        },

        async openArchiveModal() {
            // Fetch archive settings so the modal can display mode and path.
            await this.loadArchiveSettings()
            this.showArchiveModal = true
        },

        onTeamArchived(pendingRow) {
            this.archiveStatusRow = pendingRow
            showSuccess(t('teamhub', 'Team maintenance: Action executed.'))
            this.$emit('team-deleted')
        },

        async confirmDeleteTeam() {
            this.deleting = true
            try {
                await axios.delete(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}`))
                showSuccess(t('teamhub', 'Team deleted'))
                this.$emit('team-deleted')
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to delete team: {error}', { error: msg }) : t('teamhub', 'Failed to delete team'))
            } finally {
                this.deleting = false
            }
        },

        // ------------------------------------------------------------------
        // Team apps
        // ------------------------------------------------------------------

        async loadTeamApps() {
            this.loadingApps = true
            try {
                const [appsRes, installedRes] = await Promise.all([
                    axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/apps`)),
                    axios.get(generateUrl('/apps/teamhub/api/v1/apps/check')),
                ])
                this.teamApps      = Array.isArray(appsRes.data) ? appsRes.data : []
                this.installedApps = installedRes.data || {}
            } catch (e) {
                this.teamApps      = []
                this.installedApps = {}
            } finally {
                this.loadingApps = false
            }
            // Also load pending/ignored resources when the settings tab is opened.
            this.loadPendingResources()
        },

        // ------------------------------------------------------------------
        // Pending / ignored resource management
        // ------------------------------------------------------------------

        async loadMessageSettings() {
            this.loadingMessageSettings = true
            try {
                const resp = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/settings`)
                )
                this.messageSettingsForm = {
                    pinMinLevel:  resp.data.pinMinLevel  || 'moderator',
                    postMinLevel: resp.data.postMinLevel || 'member',
                    linkMinLevel: resp.data.linkMinLevel || 'admin',
                }
            } catch (e) {
                showError(t('teamhub', 'Failed to load message settings'))
            } finally {
                this.loadingMessageSettings = false
            }
        },

        async saveMessageSettings() {
            this.savingMessageSettings = true
            this.messageSettingsSaved = false
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/settings`),
                    this.messageSettingsForm
                )
                // Update the store so PostMessageForm and canPost/canPin reflect the new settings immediately
                this.$store.dispatch('fetchMessageSettings', this.team.id)
                this.messageSettingsSaved = true
                setTimeout(() => { this.messageSettingsSaved = false }, 2500)
            } catch (e) {
                showError(t('teamhub', 'Failed to save message settings'))
            } finally {
                this.savingMessageSettings = false
            }
        },

        /**
         * Auto-save variant (v3.104.1) used by the Integration Settings
         * Messages block and the Settings tab Custom-links row. Same POST as
         * saveMessageSettings but silent on success — no toast, no "Saved"
         * badge — because it fires on every select change and would spam.
         * Errors still surface via showError.
         */
        async saveMessageSettingsAuto() {
            this.savingMessageSettings = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/settings`),
                    this.messageSettingsForm
                )
                this.$store.dispatch('fetchMessageSettings', this.team.id)
            } catch (e) {
                showError(t('teamhub', 'Failed to save message settings'))
            } finally {
                this.savingMessageSettings = false
            }
        },

        async clearImageCache() {
            const folderId = this.teamFilesFolderId
            if (!folderId) return
            this.clearingImageCache = true
            try {
                const { data } = await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/image-cache`),
                    { data: { teamFolderId: folderId } }
                )
                showSuccess(n('teamhub', 'Image cache cleared (%n file removed)', 'Image cache cleared (%n files removed)', n, { n }))
            } catch (e) {
                showError(t('teamhub', 'Failed to clear image cache'))
            } finally {
                this.clearingImageCache = false
            }
        },

        async loadPendingResources() {
            this.loadingPendingResources = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/panel`)
                )
                // data is grouped by app_id — flatten to a single list with _loading flag.
                const flat = []
                for (const [, rows] of Object.entries(data || {})) {
                    for (const row of rows) {
                        flat.push({ ...row, _loading: false })
                    }
                }
                this.pendingResourceRows = flat
            } catch (e) {
                this.pendingResourceRows = []
            } finally {
                this.loadingPendingResources = false
            }
        },

        async acceptResource(resource) {
            resource._loading = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resource.appId}/${resource.resourceId}/accept`)
                )
                // Update status in-place to keep UI reactive without a full reload.
                resource.status = 'active'
            } catch (e) {
            } finally {
                resource._loading = false
            }
        },

        async ignoreResource(resource) {
            resource._loading = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resource.appId}/${resource.resourceId}/ignore`)
                )
                resource.status = 'ignored'
            } catch (e) {
            } finally {
                resource._loading = false
            }
        },

        async unignoreResource(resource) {
            resource._loading = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resource.appId}/${resource.resourceId}/unignore`)
                )
                resource.status = 'active'
            } catch (e) {
            } finally {
                resource._loading = false
            }
        },

        /**
         * Human-readable label for a risk_status value.
         */
        riskLabel(riskStatus) {
            const labels = {
                // TRANSLATORS: risk status shown when the resource owner's NC account has been disabled
                owner_disabled:   t('teamhub', 'Owner disabled'),
                // TRANSLATORS: risk status shown when automatic ownership transfer failed after account deletion
                transfer_failed:  t('teamhub', 'Transfer failed'),
            }
            return labels[riskStatus] || riskStatus
        },

        // ------------------------------------------------------------------
        // Per-app resource list actions
        // ------------------------------------------------------------------

        async removeResource(resource) {
            resource._loading = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resource.appId}/${resource.resourceId}/remove`)
                )
                const idx = this.pendingResourceRows.findIndex(r => r.id === resource.id)
                if (idx !== -1) this.pendingResourceRows.splice(idx, 1)
                this.$store.dispatch('fetchResources', this.team.id)
            } catch (e) {
                resource._loading = false
            }
        },

        confirmDeleteResource(resource) {
            this.pendingDeleteResource = resource
        },

        async executeDeleteResource() {
            if (!this.pendingDeleteResource) return
            const resource = this.pendingDeleteResource
            this.deletingResource = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resource.appId}/${resource.resourceId}/delete`)
                )
                const idx = this.pendingResourceRows.findIndex(r => r.id === resource.id)
                if (idx !== -1) this.pendingResourceRows.splice(idx, 1)
                this.$store.dispatch('fetchResources', this.team.id)
                this.pendingDeleteResource = null
            } catch (e) {
                // keep dialog open on error
            } finally {
                this.deletingResource = false
            }
        },

        async openConnectPicker(appId) {
            this.connectPickerApp = appId
            this.connectPickerItems = []
            this.loadingConnectPicker = true
            try {
                const urlMap = {
                    talk:     '/apps/teamhub/api/v1/pickers/talk',
                    calendar: '/apps/teamhub/api/v1/pickers/calendar',
                    deck:     '/apps/teamhub/api/v1/pickers/deck',
                    files:    `/apps/teamhub/api/v1/pickers/files?teamId=${encodeURIComponent(this.team.id)}&activeFilesType=${this.activeFilesIsShared ? 'shared' : this.activeFilesIsGf ? 'gf' : 'none'}`,
                }
                const { data } = await axios.get(generateUrl(urlMap[appId]))
                this.connectPickerItems = Array.isArray(data.resources) ? data.resources : []
            } catch (e) {
                this.connectPickerItems = []
            } finally {
                this.loadingConnectPicker = false
            }
        },

        async connectExisting(item) {
            const appId = this.connectPickerApp
            // Close picker immediately so it doesn't look stuck
            this.connectPickerApp = null
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${appId}/connect`),
                    { resourceId: item.id }
                )
                await this.loadPendingResources()
                this.$store.dispatch('fetchResources', this.team.id)
            } catch (e) {
                // Avoid logging the full response body — a 500 returns an HTML error page
                // which floods the console. Extract only what's useful.
                const status  = e?.response?.status ?? 'network error'
                const errData = e?.response?.data
                const errMsg  = (errData && typeof errData === 'object')
                    ? (errData.error ?? JSON.stringify(errData))
                    : `HTTP ${status}`
                showError(errData?.error
                    ? t('teamhub', 'Failed to connect resource: {error}', { error: errData.error })
                    : t('teamhub', 'Failed to connect resource')
                )
            }
        },

        createResource(appId) {
            // Open the name dialog — actual creation happens in confirmCreateResource.
            this.createResourceApp = appId
            this.createResourceName = ''
        },

        async confirmCreateResource() {
            const name = this.createResourceName.trim()
            if (!name || !this.createResourceApp) return
            this.creatingResource = true
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/create-resources`),
                    { apps: [this.createResourceApp], names: { [this.createResourceApp]: name } }
                )
                this.createResourceApp = null
                this.createResourceName = ''
                await this.loadPendingResources()
                this.$store.dispatch('fetchResources', this.team.id)
            } catch (e) {
                // keep dialog open on error
            } finally {
                this.creatingResource = false
            }
        },

        /**
         * Human-readable app label for a resource panel row.
         */
        appLabel(appId) {
            const labels = {
                talk:     t('teamhub', 'Talk'),
                files:    t('teamhub', 'Files'),
                calendar: t('teamhub', 'Calendar'),
                deck:     t('teamhub', 'Deck'),
            }
            return labels[appId] || appId
        },

        // ── Presence config (B3) ────────────────────────────────────

        async loadPresenceConfig() {
            if (!this.team?.id) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/presence/config`)
                )
                this.presenceEnabled     = !!data.presence_enabled
                this.presenceHideReasons = !!data.hide_reasons
                // Sync to store so TeamView's tab list stays in sync.
                this.SET_PRESENCE_CONFIG(data)
            } catch (err) {
                // Non-fatal — defaults stay at false.
            }
        },

        async setPresenceEnabled(val) {
            this.savingPresenceConfig = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/presence/config`),
                    { presence_enabled: val ? 1 : 0 }
                )
                this.presenceEnabled = val
                // Commit to store → TeamView watcher rebuilds tabs immediately.
                this.SET_PRESENCE_CONFIG({
                    presence_enabled: val,
                    hide_reasons: this.presenceHideReasons,
                })
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingPresenceConfig = false
            }
        },

        async setPresenceHideReasons(val) {
            this.savingPresenceConfig = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/presence/config`),
                    { hide_reasons: val ? 1 : 0 }
                )
                this.presenceHideReasons = val
                // Commit to store → TeamPresenceView re-reads hide_reasons.
                this.SET_PRESENCE_CONFIG({
                    presence_enabled: this.presenceEnabled,
                    hide_reasons: val,
                })
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingPresenceConfig = false
            }
        },

        // ── Decisions config (Session A) ────────────────────────────

        async loadDecisionsConfig() {
            if (!this.team?.id) return
            if (!this.decisionsModuleEnabled) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/config`)
                )
                this.decisionsEnabled      = !!data.decisions_enabled
                this.decisionsLevelEnabled = !!data.decisions_level_enabled
                this.decisionsActionMinLevel = data.decisions_action_min_level ?? 4
                // Sync to store so TeamView's tab list and PostMessageForm stay in sync.
                this.SET_DECISIONS_CONFIG(data)
                if (this.decisionsEnabled) {
                    this.loadDecCategories()
                }
            } catch (err) {
                // Non-fatal — defaults stay at false.
            }
        },

        async setDecisionsEnabled(val) {
            this.savingDecisionsConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/config`),
                    { decisions_enabled: val ? 1 : 0 }
                )
                this.decisionsEnabled      = !!data.decisions_enabled
                this.decisionsLevelEnabled = !!data.decisions_level_enabled
                this.decisionsActionMinLevel = data.decisions_action_min_level ?? 4
                this.SET_DECISIONS_CONFIG(data)
                if (this.decisionsEnabled) {
                    this.loadDecCategories()
                }
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingDecisionsConfig = false
            }
        },

        /**
         * Timeline per-team toggle (v3.77.20). Mirrors the decisions pattern —
         * fetch GET ?team/timeline/config, then PUT same URL with a 0/1 body.
         * Store sync via SET_TIMELINE_CONFIG keeps TeamView's tab gate reactive
         * so the Timeline tab appears/disappears immediately when the admin
         * flips the switch (no team reload needed).
         */
        async loadTimelineConfig() {
            if (!this.team?.id) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/timeline/config`)
                )
                this.timelineEnabled = !!data.timeline_enabled
                this.SET_TIMELINE_CONFIG(data)
            } catch (err) {
                // Non-fatal — default of true stays in place.
            }
        },

        async setTimelineEnabled(val) {
            this.savingTimelineConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/timeline/config`),
                    { timeline_enabled: val ? 1 : 0 }
                )
                this.timelineEnabled = !!data.timeline_enabled
                this.SET_TIMELINE_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingTimelineConfig = false
            }
        },

        /**
         * Messages per-team toggle (v3.104.1). Mirrors the timeline pattern.
         * Store sync via SET_MESSAGES_CONFIG keeps TeamWidgetGrid and
         * MobileWidgetView's message-stream gating reactive so the widget
         * appears/disappears immediately when the admin flips the switch.
         */
        async loadMessagesConfig() {
            if (!this.team?.id) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/config`)
                )
                this.messagesEnabled = !!data.messages_enabled
                this.SET_MESSAGES_CONFIG(data)
            } catch (err) {
                // Non-fatal — default of true stays in place.
            }
        },

        async setMessagesEnabled(val) {
            this.savingMessagesConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/messages/config`),
                    { messages_enabled: val ? 1 : 0 }
                )
                this.messagesEnabled = !!data.messages_enabled
                this.SET_MESSAGES_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingMessagesConfig = false
            }
        },

        /**
         * Budget integration toggle (v3.92.0) — Advanced projects only. Same
         * NC-app-config-backed pattern as timeline/decisions. Commits into the
         * store on save so the Budget tab appears/disappears on the team home
         * without a page reload.
         */
        async loadBudgetConfig() {
            if (!this.team?.id) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/budget/config`)
                )
                this.budgetEnabled = !!data.budget_enabled
                this.SET_BUDGET_CONFIG(data)
            } catch (err) {
                // Non-fatal — default of true stays in place.
            }
        },

        async setBudgetEnabled(val) {
            this.savingBudgetConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/budget/config`),
                    { budget_enabled: val ? 1 : 0 }
                )
                this.budgetEnabled = !!data.budget_enabled
                this.SET_BUDGET_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingBudgetConfig = false
            }
        },

        // ── Time investment (v3.96.0, Track E Session 5) ────────────────────
        // Same NC-app-config-backed toggle pattern as budget/timeline/decisions.

        async loadTimeConfig() {
            if (!this.team?.id) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/time/config`)
                )
                this.timeEnabled = !!data.time_enabled
                this.SET_TIME_CONFIG(data)
            } catch (err) {
                // Non-fatal — default of true stays in place.
            }
        },

        async setTimeEnabled(val) {
            this.savingTimeConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/time/config`),
                    { time_enabled: val ? 1 : 0 }
                )
                this.timeEnabled = !!data.time_enabled
                this.SET_TIME_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingTimeConfig = false
            }
        },

        async loadTime() {
            if (!this.team?.id || this.project?.mode !== 'advanced') {
                this.timeCfg = null
                return
            }
            this.loadingTime = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/time`)
                )
                this.timeCfg = {
                    timeViewMinLevel: data.timeViewMinLevel ?? 1,
                    members: (data.members || []).map(m => ({
                        userId:           m.userId,
                        displayName:      m.displayName,
                        availableMinutes: m.availableMinutes,
                        availableHours:   m.availableMinutes > 0 ? (m.availableMinutes / 60) : '',
                        loggedMinutes:    m.loggedMinutes,
                        saving:           false,
                    })),
                }
            } catch (err) {
                this.timeCfg = null
                showError(t('teamhub', 'Failed to load time settings: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.loadingTime = false
            }
        },

        async saveTimeConfig() {
            if (!this.timeCfg) return
            this.savingTimeCfg = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/time`),
                    { time_view_min_level: this.timeCfg.timeViewMinLevel }
                )
                showSuccess(t('teamhub', 'Time settings saved'))
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingTimeCfg = false
            }
        },

        async saveTimeMember(m) {
            m.saving = true
            const minutes = m.availableHours === '' || m.availableHours === null || !Number.isFinite(Number(m.availableHours))
                ? 0
                : Math.max(0, Math.round(Number(m.availableHours) * 60))
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/time/members/${encodeURIComponent(m.userId)}`),
                    { available_minutes: minutes }
                )
                m.availableMinutes = minutes
                showSuccess(t('teamhub', '{name} saved', { name: m.displayName }))
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                m.saving = false
            }
        },


        /**
         * Project Teams (v3.88.0) — upgrade a Basic project to Advanced. The
         * store action re-commits SET_PROJECT, so the phase stepper on the team
         * home appears immediately without a reload.
         */
        async upgradeProjectToAdvanced() {
            if (this.savingProject) return
            this.savingProject = true
            try {
                await this.$store.dispatch('saveProjectMode', { mode: 'advanced' })
                showSuccess(t('teamhub', 'Project upgraded to Advanced'))
            } catch (err) {
                const msg = err?.response?.data?.error
                showError(msg
                    ? t('teamhub', 'Failed to upgrade project: {error}', { error: msg })
                    : t('teamhub', 'Failed to upgrade project'))
            } finally {
                this.savingProject = false
            }
        },

        /**
         * v3.98.1 — hydrate the dates form from the store's project fact.
         * Called on tab enter and when the project state changes underneath.
         * Converts Unix timestamps (backend) to ISO 'YYYY-MM-DD' (date input).
         */
        hydrateProjectDatesForm() {
            const toIso = ts => (ts && Number.isFinite(ts))
                ? new Date(ts * 1000).toISOString().slice(0, 10)
                : ''
            this.projectDatesForm.startDate = toIso(this.project?.startDate)
            this.projectDatesForm.targetEnd = toIso(this.project?.targetEnd)
            this.projectDatesError = ''
        },

        /**
         * v3.98.1 — persist the project dates via the existing
         * ProjectController::save endpoint (mode required, start/target
         * optional). Reuses the store's saveProjectMode action so
         * SET_PROJECT re-commits and the Compass refetches.
         */
        async saveProjectDates() {
            if (this.savingProjectDates) return
            const toTs = iso => {
                if (!iso) return null
                // Interpret picker date as UTC midnight to match the
                // teamhub_project.startDate / targetEnd convention.
                const t = Date.parse(iso + 'T00:00:00Z')
                return Number.isFinite(t) ? Math.floor(t / 1000) : null
            }
            const startDate = toTs(this.projectDatesForm.startDate)
            const targetEnd = toTs(this.projectDatesForm.targetEnd)

            // Sanity check: if both set, start must not be after end.
            if (startDate !== null && targetEnd !== null && startDate > targetEnd) {
                this.projectDatesError = t('teamhub', 'Target end date must be on or after the start date.')
                return
            }

            this.savingProjectDates = true
            this.projectDatesError = ''
            try {
                await this.$store.dispatch('saveProjectMode', {
                    mode: this.project.mode,
                    startDate,
                    targetEnd,
                })
                showSuccess(t('teamhub', 'Project dates saved'))
            } catch (err) {
                const msg = err?.response?.data?.error
                this.projectDatesError = msg
                    ? t('teamhub', 'Failed to save dates: {error}', { error: msg })
                    : t('teamhub', 'Failed to save project dates')
                showError(this.projectDatesError)
            } finally {
                this.savingProjectDates = false
            }
        },

        /**
         * Project Teams (v3.88.0) — set the lifecycle phase of an advanced
         * project. No-ops if the phase is unchanged.
         */
        async changeProjectPhase(phase) {
            if (this.savingProject || phase === this.project.phase) return
            this.savingProject = true
            try {
                await this.$store.dispatch('setProjectPhase', phase)
                showSuccess(t('teamhub', 'Project phase updated'))
            } catch (err) {
                const msg = err?.response?.data?.error
                showError(msg
                    ? t('teamhub', 'Failed to update phase: {error}', { error: msg })
                    : t('teamhub', 'Failed to update phase'))
            } finally {
                this.savingProject = false
            }
        },

        /**
         * Budget config (v3.92.0, Track E Session 4) — fetch the project's
         * budget envelope and hydrate `budgetCfg` for the Project tab. Server
         * returns minor units; convert to major (2-decimal) for the UI. Admin
         * sees every lane (viewMinLevel gate doesn't apply to them).
         */
        async loadBudget() {
            if (!this.team?.id || this.project?.mode !== 'advanced') {
                this.budgetCfg = null
                return
            }
            this.loadingBudget = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/budget`)
                )
                this.budgetCfg = {
                    totalMajor:          data.totalMinor !== null ? data.totalMinor / 100 : '',
                    currency:            data.currency || '',
                    budgetViewMinLevel:  data.budgetViewMinLevel ?? 1,
                    lanes:               (data.lanes || []).map(lane => ({
                        laneId:            lane.laneId,
                        stackTitle:        lane.stackTitle,
                        allocatedMajor:    lane.allocatedMinor !== null ? lane.allocatedMinor / 100 : '',
                        editMinLevel:      lane.editMinLevel,
                        editors:           Array.isArray(lane.editors) ? [...lane.editors] : [],
                        editorSearch:      '',
                        editorSuggestions: [],
                        editorSearchTimer: null,
                        saving:            false,
                    })),
                }
            } catch (err) {
                showError(t('teamhub', 'Could not load budget settings'))
                this.budgetCfg = null
            } finally {
                this.loadingBudget = false
            }
        },

        parseMinor(value) {
            if (value === '' || value === null || value === undefined) return null
            const n = Number(value)
            if (!Number.isFinite(n) || n < 0) return null
            return Math.round(n * 100)
        },

        async saveBudgetTotal() {
            if (!this.budgetCfg || this.savingBudgetTotal) return
            this.savingBudgetTotal = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/budget`),
                    {
                        total_minor:            this.parseMinor(this.budgetCfg.totalMajor),
                        currency:               this.budgetCfg.currency || null,
                        budget_view_min_level:  this.budgetCfg.budgetViewMinLevel,
                    }
                )
                showSuccess(t('teamhub', 'Budget settings saved'))
            } catch (err) {
                const msg = err?.response?.data?.error
                showError(msg
                    ? t('teamhub', 'Could not save: {error}', { error: msg })
                    : t('teamhub', 'Could not save'))
            } finally {
                this.savingBudgetTotal = false
            }
        },

        async saveBudgetLane(lane) {
            if (lane.saving) return
            lane.saving = true
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/budget/lanes/${lane.laneId}`),
                    {
                        allocated_minor: this.parseMinor(lane.allocatedMajor),
                        edit_min_level:  lane.editMinLevel,
                        editor_uids:     lane.editors.map(e => e.uid),
                    }
                )
                showSuccess(t('teamhub', 'Workstream saved'))
            } catch (err) {
                const msg = err?.response?.data?.error
                showError(msg
                    ? t('teamhub', 'Could not save workstream: {error}', { error: msg })
                    : t('teamhub', 'Could not save workstream'))
            } finally {
                lane.saving = false
            }
        },

        /**
         * Additional-editors picker per lane. Uses the same direct-member list
         * as the owner search — non-members can't be additional editors
         * because the lane's underlying team-membership check would then fail
         * server-side anyway. Local state (editorSearch / editorSuggestions /
         * editorSearchTimer) lives on the lane object.
         */
        onEditorSearchInput(lane) {
            clearTimeout(lane.editorSearchTimer)
            lane.editorSearchTimer = setTimeout(() => {
                const q = (lane.editorSearch || '').trim().toLowerCase()
                if (q.length < 1) {
                    lane.editorSuggestions = []
                    return
                }
                const already = new Set(lane.editors.map(e => e.uid))
                lane.editorSuggestions = this.manageMembers.direct
                    .filter(m => m.userId && !already.has(m.userId))
                    .filter(m => {
                        const name = (m.displayName || '').toLowerCase()
                        const uid  = (m.userId || '').toLowerCase()
                        return name.includes(q) || uid.includes(q)
                    })
                    .slice(0, 8)
                    .map(m => ({
                        uid:         m.userId,
                        displayName: m.displayName || m.userId,
                    }))
            }, 200)
        },

        addEditor(lane, suggestion) {
            if (lane.editors.some(e => e.uid === suggestion.uid)) return
            lane.editors.push({ uid: suggestion.uid, displayName: suggestion.displayName })
            lane.editorSearch = ''
            lane.editorSuggestions = []
        },

        removeEditor(lane, uid) {
            lane.editors = lane.editors.filter(e => e.uid !== uid)
        },

        showPermInfo() {
            this.permInfoOpen = true
        },

        /**
         * Timeline Milestones (v3.78.2) — admin-managed marker lines shown
         * on the Timeline tab. CRUD mirrors the decision-categories pattern
         * (loadX / startCreateX / startEditX / cancelEditX / saveX /
         * confirmDeleteX) for consistency within this file.
         */
        async loadMilestones() {
            if (!this.team?.id) return
            this.loadingMilestones = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/milestones`)
                )
                this.milestones = Array.isArray(data.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] loadMilestones error:', err)
            } finally {
                this.loadingMilestones = false
            }
        },

        startCreateMilestone() {
            this.milestoneEditing = 'new'
            this.milestoneForm = { label: '', date: '' }
            this.milestoneFormError = ''
        },

        startEditMilestone(m) {
            this.milestoneEditing = m.id
            this.milestoneForm = { label: m.label, date: m.date || '' }
            this.milestoneFormError = ''
        },

        cancelEditMilestone() {
            this.milestoneEditing = null
            this.milestoneFormError = ''
        },

        async saveMilestone() {
            const label = this.milestoneForm.label.trim()
            if (!label) {
                this.milestoneFormError = t('teamhub', 'Name is required')
                return
            }
            this.savingMilestone = true
            this.milestoneFormError = ''
            try {
                const payload = { label, date: this.milestoneForm.date || null }
                if (this.milestoneEditing === 'new') {
                    await axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/milestones`),
                        payload
                    )
                } else {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/milestones/${this.milestoneEditing}`),
                        payload
                    )
                }
                this.milestoneEditing = null
                await this.loadMilestones()
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] saveMilestone error:', err)
                this.milestoneFormError = err?.response?.data?.error || err.message
            } finally {
                this.savingMilestone = false
            }
        },

        async confirmDeleteMilestone(m) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(t('teamhub', 'Delete milestone "{name}"? This cannot be undone.', { name: m.label }))) {
                return
            }
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/milestones/${m.id}`)
                )
                await this.loadMilestones()
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] deleteMilestone error:', err)
                showError(t('teamhub', 'Failed to delete milestone: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        /** dateStr is 'YYYY-MM-DD' (as returned by MilestoneService::serialize). */
        formatMilestoneDate(dateStr) {
            try {
                const d = new Date(dateStr + 'T00:00:00')
                const locale = document.documentElement.lang || 'en'
                return d.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' })
            } catch (err) {
                return dateStr
            }
        },

        async setDecisionsLevelEnabled(val) {
            this.savingDecisionsConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/config`),
                    { decisions_level_enabled: val ? 1 : 0 }
                )
                this.decisionsLevelEnabled = !!data.decisions_level_enabled
                this.decisionsActionMinLevel = data.decisions_action_min_level ?? 4
                this.SET_DECISIONS_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingDecisionsConfig = false
            }
        },

        async setDecisionsActionMinLevel(val) {
            this.savingDecisionsConfig = true
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/config`),
                    { decisions_action_min_level: val }
                )
                this.decisionsLevelEnabled = !!data.decisions_level_enabled
                this.decisionsActionMinLevel = data.decisions_action_min_level ?? 4
                this.SET_DECISIONS_CONFIG(data)
            } catch (err) {
                showError(t('teamhub', 'Failed to save: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.savingDecisionsConfig = false
            }
        },

        // ─── Decision categories (Session G) ──────────────────────────────────

        async loadDecCategories() {
            if (!this.decisionsModuleEnabled || !this.decisionsEnabled) return
            this.loadingDecCategories = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/manage/categories`)
                )
                this.decCategories = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] loadDecCategories error:', err)
                showError(t('teamhub', 'Failed to load decision categories: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            } finally {
                this.loadingDecCategories = false
            }
        },

        // ─── Category icon picker ──────────────────────────────────────────

        catIconComponent(name) {
            return CATEGORY_ICON_MAP[name] || null
        },

        selectCatIcon(name) {
            this.decCatForm.icon      = name
            this.decCatIconPickerOpen = false
            this.catIconSearch        = ''
        },

        clearCatIcon() {
            this.decCatForm.icon      = ''
            this.decCatIconPickerOpen = false
            this.catIconSearch        = ''
        },

        startCreateDecCategory() {
            const owner = this.memberUserOptions.find(m => {
                const direct = this.manageMembers?.direct || []
                const row = direct.find(d => d.userId === m.userId)
                return row && row.level >= 9
            }) || null
            this.decCatEditing   = 'new'
            this.decCatForm      = { name: '', icon: '', description: '', approvers: owner ? [owner] : [] }
            this.decCatFormError = ''
        },

        startEditDecCategory(cat) {
            const opts = this.memberUserOptions
            const approverObjs = (cat.approvers || []).map(uid => {
                const found = opts.find(o => o.userId === uid)
                return found || { userId: uid, displayName: uid }
            })
            this.decCatEditing   = cat.id
            this.decCatForm      = { name: cat.name, icon: cat.icon || '', description: cat.description || '', approvers: approverObjs }
            this.decCatFormError = ''
        },

        cancelEditDecCategory() {
            this.decCatEditing        = null
            this.decCatIconPickerOpen = false
            this.decCatForm           = { name: '', icon: '', description: '', approvers: [] }
            this.decCatFormError      = ''
        },

        async saveDecCategory() {
            const name = this.decCatForm.name.trim()
            if (!name) {
                this.decCatFormError = t('teamhub', 'Name cannot be empty')
                return
            }
            const approverIds = (this.decCatForm.approvers || []).map(a => a.userId).filter(Boolean)
            const icon        = this.decCatForm.icon?.trim() || null
            const description = this.decCatForm.description?.trim() || null
            const isNew       = this.decCatEditing === 'new'

            this.savingDecCategory = true
            this.decCatFormError   = ''
            try {
                if (isNew) {
                    await axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/manage/categories`),
                        { name, icon, description, approvers: approverIds }
                    )
                } else {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/manage/categories/${this.decCatEditing}`),
                        { name, icon, description, approvers: approverIds }
                    )
                }
                this.cancelEditDecCategory()
                await this.loadDecCategories()
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] saveDecCategory error:', err)
                const apiErr = err?.response?.data?.error || err.message
                this.decCatFormError = apiErr
            } finally {
                this.savingDecCategory = false
            }
        },

        async confirmDeleteDecCategory(cat) {
            // eslint-disable-next-line no-alert
            if (!window.confirm(t('teamhub', 'Delete category "{name}"? Existing decisions will keep their text but lose the link to this category.', { name: cat.name }))) {
                return
            }
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/decisions/manage/categories/${cat.id}`)
                )
                await this.loadDecCategories()
            } catch (err) {
                console.error('[TeamHub][ManageTeamView] deleteDecCategory error:', err)
                showError(t('teamhub', 'Failed to delete category: {error}', {
                    error: err?.response?.data?.error || err.message,
                }))
            }
        },

        formatApprovers(approverUids) {
            if (!Array.isArray(approverUids) || !approverUids.length) {
                return t('teamhub', '(no approvers)')
            }
            const opts = this.memberUserOptions
            const names = approverUids.map(uid => {
                const found = opts.find(o => o.userId === uid)
                return found ? found.displayName : uid
            })
            // Translation-safe join: leave the comma+space to translators if
            // they want, but in practice it's identical across our supported
            // locales (nl/de/fr/da/en).
            return names.join(', ')
        },

        async toggleApp(app, enabled) {
            if (!app.installed) return
            if (!enabled) {
                this.pendingDisableApp = app
                return
            }
            // For the four connectable apps, ask whether to Create or Connect.
            // For any other app (intravox, ...) just enable directly.
            const connectable = ['spreed', 'files', 'calendar', 'deck']
            if (connectable.includes(app.id)) {
                this.pendingEnableApp = app
                this.enableAppMode = 'create'
                this.enableAppResourceId = null
                return
            }
            await this._executeToggleApp(app, true)
        },

        cancelEnableApp() {
            // Roll back optimistic state if the switch had already flipped on.
            const existing = this.teamApps.find(a => a.app_id === this.pendingEnableApp?.id)
            if (existing) existing.enabled = false
            this.pendingEnableApp = null
            this.enableAppMode = 'create'
            this.enableAppResourceId = null
        },

        async confirmEnableApp() {
            const app = this.pendingEnableApp
            const mode = this.enableAppMode
            const resourceId = this.enableAppResourceId
            // Capture then clear so the dialog closes immediately.
            this.pendingEnableApp = null
            if (!app) return

            if (mode === 'create') {
                await this._executeToggleApp(app, true)
                this.enableAppMode = 'create'
                this.enableAppResourceId = null
                return
            }

            // Connect path
            if (!resourceId) {
                showError(t('teamhub', 'No resource selected.'))
                return
            }
            this.togglingApp = app.id
            try {
                // Map app_id to the resource key used by the connect endpoint.
                const resourceKey = app.id === 'spreed' ? 'talk' : app.id
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/resources/${resourceKey}/connect`),
                    { resourceId }
                )
                // Reflect enabled state locally.
                const existing = this.teamApps.find(a => a.app_id === app.id)
                if (existing) {
                    existing.enabled = true
                } else {
                    this.teamApps.push({ app_id: app.id, enabled: true })
                }
                showSuccess(t('teamhub', '{name} connected to this team', { name: app.label }))
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg
                    // TRANSLATORS: error shown when connecting an existing resource fails. {name} is the app name (e.g. "Calendar"), {error} is the detail.
                    ? t('teamhub', 'Failed to connect {name}: {error}', { name: app.label, error: msg })
                    : t('teamhub', 'Failed to connect {name}', { name: app.label })
                )
                await this.loadTeamApps()
            } finally {
                this.togglingApp = null
                this.enableAppMode = 'create'
                this.enableAppResourceId = null
            }
        },

        async confirmDisableApp() {
            const app = this.pendingDisableApp
            this.pendingDisableApp = null
            if (!app) return
            await this._executeToggleApp(app, false)
        },

        cancelDisableApp() {
            const existing = this.teamApps.find(a => a.app_id === this.pendingDisableApp?.id)
            if (existing) existing.enabled = true
            this.pendingDisableApp = null
        },

        async _executeToggleApp(app, enabled) {
            this.togglingApp = app.id
            const existing = this.teamApps.find(a => a.app_id === app.id)
            if (existing) {
                existing.enabled = enabled
            } else {
                this.teamApps.push({ app_id: app.id, enabled })
            }
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/apps`),
                    { apps: [{ app_id: app.id, enabled }] }
                )
                if (enabled) {
                    showSuccess(t('teamhub', '{name} enabled for this team', { name: app.label }))
                } else {
                    showSuccess(t('teamhub', '{name} and all its data have been removed from this team', { name: app.label }))
                }
            } catch (error) {
                if (existing) {
                    existing.enabled = !enabled
                } else {
                    this.teamApps = this.teamApps.filter(a => a.app_id !== app.id)
                }
                const msg = error.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to update {name}: {error}', { name: app.label, error: msg }) : t('teamhub', 'Failed to update {name}', { name: app.label }))
                await this.loadTeamApps()
            } finally {
                this.togglingApp = null
            }
        },

        // ------------------------------------------------------------------
        // Meeting permissions
        // ------------------------------------------------------------------

        async loadMeetingSettings() {
            this.loadingMeetingSettings = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/meetings/settings`)
                )
                this.meetingMinLevel = data.minLevel ?? 1
            } catch (e) {
                // Non-admin users get 403 — silently ignore, section won't be visible to them
            } finally {
                this.loadingMeetingSettings = false
            }
        },

        async saveMeetingSettings() {
            this.meetingSettingsSaved = false
            this.meetingSettingsError = null
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/meetings/settings`),
                    { minLevel: this.meetingMinLevel }
                )
                this.meetingSettingsSaved = true
                setTimeout(() => { this.meetingSettingsSaved = false }, 3000)
            } catch (e) {
                const msg = e?.response?.data?.error || t('teamhub', 'Failed to save')
                this.meetingSettingsError = msg
            }
        },

        // External integrations
        // ------------------------------------------------------------------

        async loadIntegrationRegistry() {
            this.loadingWidgets = true
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/registry`)
                )
                this.integrationRegistry = Array.isArray(data) ? data : []
            } catch (e) {
                this.integrationRegistry = []
            } finally {
                this.loadingWidgets = false
            }
        },

        async toggleIntegration(integration, enabled) {
            this.togglingWidget = integration.registry_id
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/${integration.registry_id}/toggle`),
                    { enable: enabled }
                )
                this.integrationRegistry = Array.isArray(data) ? data : this.integrationRegistry
                await this.$store.dispatch('fetchTeamIntegrations', this.team.id)
                showSuccess(
                    enabled
                        ? t('teamhub', '{title} enabled for this team', { title: integration.title })
                        : t('teamhub', '{title} disabled for this team', { title: integration.title })
                )
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to update integration: {error}', { error: msg }) : t('teamhub', 'Failed to update integration'))
            } finally {
                this.togglingWidget = null
            }
        },

        onDragStart(event, integration) {
            this.dragSourceWidget = integration
            event.dataTransfer.effectAllowed = 'move'
        },

        // ------------------------------------------------------------------
        // Team image
        // ------------------------------------------------------------------

        async onTeamImageSelected(event) {
            const file = event.target.files?.[0]
            if (!file) return

            // Client-side size guard (2 MB)
            if (file.size > 2 * 1024 * 1024) {
                showError(t('teamhub', 'Image too large. Maximum size is 2 MB.'))
                return
            }

            this.imageUploading = true
            try {
                const formData = new FormData()
                formData.append('image', file)

                const resp = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/image`),
                    formData,
                    { headers: { 'Content-Type': 'multipart/form-data' } }
                )

                // Append cache-buster so the browser reloads the new image
                this.imagePreviewUrl = resp.data.image_url
                    ? resp.data.image_url + '?t=' + Date.now()
                    : null

                // Propagate to Vuex so TeamView reflects the change immediately
                this.$store.commit('UPDATE_TEAM_IMAGE', {
                    teamId: this.team.id,
                    imageUrl: resp.data.image_url || null,
                })

                showSuccess(t('teamhub', 'Team image updated'))
            } catch (e) {
                const msg = e?.response?.data?.error || e.message || ''
                showError(msg ? t('teamhub', 'Failed to upload image: {error}', { error: msg }) : t('teamhub', 'Failed to upload image'))
            } finally {
                this.imageUploading = false
                // Reset so the same file can be re-selected if needed
                if (this.$refs.teamImageInput) {
                    this.$refs.teamImageInput.value = ''
                }
            }
        },

        async removeTeamImage() {
            this.imageRemoving = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/image`)
                )
                this.imagePreviewUrl = null

                this.$store.commit('UPDATE_TEAM_IMAGE', {
                    teamId: this.team.id,
                    imageUrl: null,
                })

                showSuccess(t('teamhub', 'Team image removed'))
            } catch (e) {
                showError(t('teamhub', 'Failed to remove image'))
            } finally {
                this.imageRemoving = false
            }
        },

        async onDrop(event, targetIntegration) {
            event.preventDefault()
            if (!this.dragSourceWidget || this.dragSourceWidget.registry_id === targetIntegration.registry_id) {
                this.dragSourceWidget = null
                return
            }
            if (this.dragSourceWidget.integration_type !== targetIntegration.integration_type) {
                this.dragSourceWidget = null
                return
            }

            const enabled = this.externalIntegrations
                .filter(i => i.enabled && i.integration_type === this.dragSourceWidget.integration_type)
                .map(i => i.registry_id)

            const srcIdx = enabled.indexOf(this.dragSourceWidget.registry_id)
            const tgtIdx = enabled.indexOf(targetIntegration.registry_id)

            if (srcIdx === -1 || tgtIdx === -1) {
                this.dragSourceWidget = null
                return
            }

            enabled.splice(srcIdx, 1)
            enabled.splice(tgtIdx, 0, this.dragSourceWidget.registry_id)
            this.dragSourceWidget = null

            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.team.id}/integrations/reorder`),
                    { order: enabled }
                )
                if (Array.isArray(data)) {
                    const sortMap = {}
                    data.forEach(i => { sortMap[i.registry_id] = i.sort_order })
                    this.integrationRegistry = this.integrationRegistry.map(i =>
                        i.enabled && sortMap[i.registry_id] !== undefined
                            ? { ...i, sort_order: sortMap[i.registry_id] }
                            : i
                    )
                    this.integrationRegistry.sort((a, b) => {
                        if (a.enabled && b.enabled) return a.sort_order - b.sort_order
                        if (a.enabled) return -1
                        if (b.enabled) return 1
                        return 0
                    })
                }
            } catch (e) {
                showError(t('teamhub', 'Failed to save order'))
                await this.loadIntegrationRegistry()
            }
        },
    },
}
</script>

<style scoped>
.manage-team-view {
    padding: 32px 40px;
    /* v3.71.10 — was max-width: 900px; dropped so the manage view uses the
       full available column. Centering removed alongside since there's
       nothing to center anymore. */
    margin: 0;
}

.manage-team-header {
    margin-bottom: 24px;
}
.manage-team-header h2 {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 4px;
}
.manage-team-subtitle {
    color: var(--color-text-maxcontrast);
    margin: 0;
}

/* ── Tab bar ─────────────────────────────────────────────────── */
.manage-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 28px;
    flex-wrap: wrap;
}

.manage-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border: none;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
    font-weight: 500;
    cursor: pointer;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
    margin-bottom: -2px;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
    white-space: nowrap;
}

.manage-tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.manage-tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    background: transparent;
}

/* v3.100.14: hover feedback on the already-active tab — the active
   state is carried by the primary-coloured text + underline. Neutral
   hover feedback per SKILLS.md (was a 6% color-mix soft tint). */
.manage-tab--active:hover {
    background: var(--color-background-hover);
}

/* Danger tab styling */
.manage-tab--danger:hover {
    color: var(--color-error-text);
}
.manage-tab--danger.manage-tab--active {
    color: var(--color-error-text);
    border-bottom-color: var(--color-error-text);
}

/* ── Sections ─────────────────────────────────────────────────── */
.manage-tab-content {
    display: flex;
    flex-direction: column;
}

.manage-section {
    margin-bottom: 36px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--color-border);
}
.manage-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}
.manage-section h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 16px;
}
.manage-section__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.manage-section__header h3 {
    margin: 0;
}

.manage-section-desc {
    /* v3.104.9 — off-scale 13px replaced by --th-font-meta (12px) so section
       descs match the row-desc used under Permissions / Integration Settings. */
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: -8px 0 16px;
}

.section-loading {
    padding: 12px 0;
}

/* Project block — phase selector */
.teamhub-project__label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}
.teamhub-project__select {
    min-width: 220px;
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}
/* v3.98.1 — Project dates form. Two date inputs stacked, then Save. */
.teamhub-project__dates {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 8px 12px;
    align-items: center;
    max-width: 480px;
    margin: 8px 0 12px;
}
.teamhub-project__dates .teamhub-project__label {
    margin-bottom: 0;
}
.teamhub-project__date-input {
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    min-width: 200px;
}
.teamhub-project__date-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}
.teamhub-project__dates-actions {
    margin-top: 4px;
}
.teamhub-project__dates-error {
    margin-top: 8px;
    color: var(--color-error-text);
    background: var(--color-error);
    padding: 6px 10px;
    border-radius: var(--border-radius);
    font-size: var(--th-font-meta);
}

.teamhub-project__select:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

/* Budget config — v3.92.0 */
.teamhub-budget-cfg__status {
    display: flex;
    justify-content: center;
    padding: 16px;
}
.teamhub-budget-cfg__totals {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.teamhub-budget-cfg__label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    font-weight: 600;
}
.teamhub-budget-cfg__input,
.teamhub-budget-cfg__select {
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    min-width: 160px;
}
.teamhub-budget-cfg__input--narrow {
    min-width: 100px;
    width: 120px;
}
.teamhub-budget-cfg__input:focus-visible,
.teamhub-budget-cfg__select:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}
.teamhub-budget-cfg__lanes {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}
.teamhub-budget-cfg__lanes th,
.teamhub-budget-cfg__lanes td {
    text-align: left;
    padding: 6px 8px;
    border-bottom: 1px solid var(--color-border);
    font-size: 13px;
    vertical-align: middle;
}
.teamhub-budget-cfg__lanes th {
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    font-size: var(--th-font-micro);
    letter-spacing: 0.4px;
}
.teamhub-budget-cfg__lanes .hidden-visually {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

/* Editors picker */
/* Header row inside a flex-column label — text + `?` inline, so the `?`
   sits next to the label, not on its own line under it. */
.teamhub-budget-cfg__label-head {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.teamhub-budget-cfg__help {
    /* SKILLS.md "UI shapes: circles, not ellipses" — mirrors the phase-stepper
       info button pattern from v3.90.0. `min-width` alone is NOT enough;
       NC's global button reset also applies min-height (44px touch target),
       and the button can flex-grow inside a header cell. The three-lock is:
         box-sizing: border-box  — border doesn't bleed the target size
         width + min-width + max-width  — horizontal size pinned
         height + min-height + max-height  — vertical size pinned
         flex: 0 0 auto  — no flex-grow inside a flex container
         padding: 0     — no content-driven inflation
       Without max-height, the button was still rendering as an oval on the
       manage tab, hence 3.94.2. */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 18px;
    height: 18px;
    min-width: 18px;
    min-height: 18px;
    max-width: 18px;
    max-height: 18px;
    padding: 0;
    margin: 0 0 0 4px;
    border: 1px solid var(--color-border-dark);
    border-radius: 50%;
    background: var(--color-main-background);
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
    line-height: 1;
    cursor: pointer;
}
.teamhub-budget-cfg__help:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}
.teamhub-budget-cfg__editors {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    min-width: 200px;
}
.teamhub-budget-cfg__editor-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    background: var(--color-background-hover);
    border-radius: 12px;
    font-size: var(--th-font-meta);
    color: var(--color-main-text);
}
/* v3.100.14: was a text × button; the button now hosts an MDI Close
   icon sized via the :size prop, so the font-size rule is dropped. */
.teamhub-budget-cfg__editor-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px; height: 16px;
    background: transparent;
    border: none;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    padding: 0;
    line-height: 1;
}
.teamhub-budget-cfg__editor-remove:hover {
    color: var(--color-error-text);
}
.teamhub-budget-cfg__editor-picker {
    position: relative;
    flex: 1 0 100%;
    margin-top: 4px;
}
.teamhub-budget-cfg__editor-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 20;
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius, 4px);
    margin: 2px 0 0;
    padding: 0;
    list-style: none;
    max-height: 200px;
    overflow-y: auto;
}
.teamhub-budget-cfg__editor-suggestion {
    padding: 4px 8px;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.teamhub-budget-cfg__editor-suggestion:hover,
.teamhub-budget-cfg__editor-suggestion:focus {
    background: var(--color-background-hover);
}
.teamhub-budget-cfg__editor-suggestion-uid {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
}

/* Description */
.manage-description-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.manage-description-actions {
    display: flex;
    justify-content: flex-end;
}

/* Settings */
.manage-settings {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.manage-settings-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    /* v4.0.0 — NcCheckboxRadioSwitch labels inherit their font-size from
       NC's global button reset (~16-18px), which overpowered the row text
       in Circle Settings. Pin the whole group to body scale so labels
       render at 14px like every other content string on the page. */
    font-size: var(--th-font-body);
}
.manage-settings-group :deep(.checkbox-radio-switch__label),
.manage-settings-group :deep(.checkbox-radio-switch label),
.manage-settings-group :deep(label span) {
    font-size: var(--th-font-body);
}
.manage-settings-group h4 {
    font-size: var(--th-font-meta);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin: 0 0 4px;
}
.manage-settings-saved {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-success-text);
    margin: 4px 0 0;
}

.manage-settings-error {
    font-size: 13px;
    color: var(--color-error-text);
    margin: 4px 0 0;
}

/* Meeting permissions */
.manage-meeting-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    margin-bottom: 8px;
}

.manage-meeting-select {
    display: block;
    width: 100%;
    max-width: 320px;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    font-family: inherit;
    cursor: pointer;
}

.manage-meeting-select:focus {
    border-color: var(--color-primary-element);
}

.manage-meeting-select:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}


.team-apps-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.team-app-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}
.team-app-section-title {
    font-size: var(--th-font-meta);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-maxcontrast);
    margin: 16px 0 6px;
    padding: 0 4px;
}
.team-app-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--border-radius);
    /* v3.100.14: neutral surface for the decorative app-icon tile
       (the primary-coloured MDI glyph inside is the accent). Was
       --color-primary-element-light — a state token per SKILLS.md. */
    background: var(--color-background-dark);
    color: var(--color-primary-element);
    flex-shrink: 0;
}
.team-app-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.team-app-name {
    font-size: var(--th-font-body);
    font-weight: 500;
}
.team-app-desc {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* At-risk resource panel
   v3.100.14: full-saturation error panel per SKILLS.md § "State-coloured
   backgrounds" (was 8% color-mix soft tints on both panel variants and
   a 12% color-mix on the header). Inner rows now use --color-error-text
   for the border separator and text so they read cleanly on the fill. */
.manage-section--atrisk {
    border: 1px solid var(--color-error);
    border-radius: var(--border-radius-large);
    padding: 12px 16px;
    background: var(--color-error);
    color: var(--color-error-text);
    transition: box-shadow 0.3s ease;
}
/* Inline variant — inside team-apps-list, styled as a section item */
.manage-section--atrisk-inline {
    border: 1px solid var(--color-error);
    border-radius: var(--border-radius-large);
    margin-bottom: 10px;
    overflow: hidden;
    background: var(--color-error);
    color: var(--color-error-text);
}
.team-app-section__header--warning {
    background: var(--color-error);
    color: var(--color-error-text);
}
.team-app-section__header--warning span[aria-hidden] {
    color: var(--color-error-text);
}
.atrisk-resource-item--inline {
    border-top: 1px solid var(--color-error-text);
    padding: 8px 14px;
    background: transparent;
}
.atrisk-resource-item--inline .atrisk-resource-name {
    color: var(--color-error-text);
}
.atrisk-resource-item--inline .atrisk-resource-reason {
    color: var(--color-error-text);
}
/* Highlight ring — deliberate soft focus glow via color-mix; kept per
   SKILLS.md (focus indicators are not state backgrounds). */
.manage-section--atrisk.manage-section--highlight,
.manage-section--atrisk-inline.manage-section--highlight {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-error) 40%, transparent);
}
.atrisk-resources-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}
.atrisk-resource-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}
.atrisk-resource-icon {
    font-size: 15px;
    flex-shrink: 0;
    margin-top: 1px;
}
.atrisk-resource-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.atrisk-resource-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-main-text);
}
.atrisk-resource-reason {
    font-size: var(--th-font-micro);
    color: var(--color-error-text);
}

/* Pending / ignored resource panels
   v3.100.14: full-saturation state per SKILLS.md (were 6-8% color-mix). */
.manage-section--pending {
    border: 1px solid var(--color-warning);
    border-radius: var(--border-radius-large);
    padding: 12px 16px;
    background: var(--color-warning);
    color: var(--color-warning-text);
}

/* Inline variant — inside team-apps-list, above app rows */
.manage-section--pending-inline {
    margin-bottom: 4px;
    border-color: var(--color-info);
    background: var(--color-info);
    color: var(--color-info-text);
}

.manage-section--pending-inline .team-app-section__header--info {
    color: var(--color-info-text);
}

/* Dual-folder migration notice — informational, full-saturation primary. */
.dual-folder-notice {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 12px;
    margin-bottom: 8px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: 1px solid var(--color-primary-element);
    border-radius: var(--border-radius);
}

.dual-folder-notice__header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--color-main-text);
}

.dual-folder-notice__body {
    margin: 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    line-height: 1.5;
}

.manage-section-desc--inline {
    margin: 4px 0 8px 28px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.team-app-section__header--info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 13px;
    color: var(--color-info-text, var(--color-main-text));
    padding: 8px 0 4px;
}


.pending-resources-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}
.pending-resources-list--ignored {
    opacity: 0.75;
}
.pending-resource-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}
.pending-resource-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.pending-resource-app {
    font-size: 13px;
    font-weight: 500;
}
.pending-resource-name {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pending-resource-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}
.manage-section-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 13px;
    color: var(--color-main-text);
    padding: 4px 0;
}
.manage-section-toggle:hover {
    color: var(--color-primary);
}
.manage-section-toggle:focus-visible {
    outline: 2px solid var(--color-primary);
    border-radius: var(--border-radius);
}

/* Per-app resource list */
.team-app-section {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    margin-bottom: 10px;
    overflow: hidden;
}
.team-app-section__header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--color-background-dark);
    font-weight: 500;
    font-size: 13px;
}
.team-app-section__name {
    flex: 1;
}
.resource-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 14px;
    border-top: 1px solid var(--color-border);
    min-height: 44px;
}
.resource-row--empty {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}
.resource-row--actions {
    gap: 6px;
    justify-content: flex-start;
}
.resource-row__name {
    font-size: 13px;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.resource-type-badge {
    flex-shrink: 0;
    font-size: var(--th-font-micro);
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 10px;
    margin: 0 8px;
    white-space: nowrap;
}
/* v3.100.14: full-saturation resource-type badges per SKILLS.md
   (were 10-30% color-mix soft tints on fill + border). --shared uses
   the neutral --color-background-dark surface because it's a neutral
   "shared" type indicator, not a state colour. */
.resource-type-badge--gf {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: 1px solid var(--color-primary-element);
}
.resource-type-badge--shared {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    border: 1px solid var(--color-border-dark);
}
.resource-row__empty-label {
    flex: 1;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}
.resource-row__actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}
.resource-picker-empty {
    padding: 16px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    text-align: center;
}
.create-resource-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 4px 0;
    min-width: 280px;
}
.create-resource-form__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.delete-resource-confirm {
    padding: 4px 0;
    min-width: 300px;
}
.delete-resource-confirm p {
    margin-bottom: 16px;
    font-size: var(--th-font-body);
    line-height: 1.5;
    color: var(--color-main-text);
}
.delete-resource-confirm__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.teamhub-resource-picker {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 0;
    min-width: 240px;
}
.teamhub-resource-picker__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    cursor: pointer;
    text-align: left;
    font-size: var(--th-font-body);
    color: var(--color-main-text);
}
.teamhub-resource-picker__item:hover {
    background: var(--color-primary-light);
}
.teamhub-resource-picker__item:focus-visible {
    outline: 2px solid var(--color-primary);
}
.teamhub-resource-picker__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-resource-picker__badge {
    flex-shrink: 0;
    font-size: var(--th-font-micro);
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 10px;
    margin-right: 6px;
    white-space: nowrap;
}

/* v3.100.14: group-folder badge is a state indicator — full saturation
   per SKILLS.md (was --color-primary-element-light). */
.teamhub-resource-picker__badge--gf {
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.teamhub-resource-picker__badge--shared {
    background-color: var(--color-background-darker);
    color: var(--color-text-maxcontrast);
}

/* Team Apps toggle items (intravox) */
.members-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.member-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}
.member-avatar-fallback {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--th-font-body);
    font-weight: 700;
    flex-shrink: 0;
}
.member-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.member-name {
    font-size: var(--th-font-body);
    font-weight: 500;
}
.member-level-select {
    padding: 5px 8px;
    border-radius: var(--border-radius);
    border: 1px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    cursor: pointer;
    min-width: 110px;
}
.member-level-select:disabled {
    opacity: 0.6;
    cursor: wait;
}
.member-role-static {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    min-width: 110px;
    text-align: center;
}

/* Pending requests */
.no-pending {
    font-size: var(--th-font-body);
    color: var(--color-text-maxcontrast);
}
.pending-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.pending-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}
.pending-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.pending-name {
    font-size: var(--th-font-body);
    font-weight: 500;
}
.pending-date {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}
.pending-actions {
    display: flex;
    gap: 8px;
}

/* Integrations */
.widgets-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.widget-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    transition: background 0.15s ease;
}
/* v3.100.14: dropped the 6% primary tint. The enabled/disabled state
   is already communicated by the toggle switch in the row, so the row
   background is just visual separation (neutral --color-background-dark
   per SKILLS.md § "State-coloured backgrounds"). */
.widget-item--enabled {
    background: var(--color-background-dark);
}
.widget-drag-handle {
    display: flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    width: 20px;
}
.widget-drag-handle:not(.widget-drag-handle--placeholder) {
    cursor: grab;
}
.widget-drag-handle:not(.widget-drag-handle--placeholder):active {
    cursor: grabbing;
}
.widget-drag-handle--placeholder {
    pointer-events: none;
    opacity: 0;
}
.widget-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.widget-title {
    font-size: var(--th-font-body);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.widget-description {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.widget-app-id {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    opacity: 0.7;
}
.widget-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    border-radius: var(--border-radius-pill);
    padding: 1px 6px;
    margin-left: 6px;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
/* v3.100.14: full-saturation category badges per SKILLS.md
   (were 15% color-mix soft tints). */
.widget-badge--widget {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}
.widget-badge--tab {
    background: var(--color-success);
    color: var(--color-success-text);
}
.widget-badge--internal {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

/* Integrations tab — internal vs third-party subsections */
.integrations-subsection {
    margin-bottom: 16px;
}
.integrations-subsection__title {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin: 12px 0 8px;
}
.widget-item--internal {
    border: 1px solid var(--color-border);
}
.widget-item--sub {
    margin-left: 24px;
    background: var(--color-background-hover);
}

.widget-item--sub-option {
    margin-left: 32px;
    border-left: 2px solid var(--color-border);
    padding-left: 12px;
    background: transparent;
    font-size: 0.9em;
}

/* Danger zone — a large panel; the 3px error border-left and the
   error-text heading carry the "danger" signal so the background stays
   neutral (v3.100.14: was a 5% color-mix soft tint; SKILLS.md permits
   neutral surfaces where the state is already communicated by another
   channel — here the border and heading). */
.manage-section--danger {
    border: 1px solid var(--color-border);
    border-left: 3px solid var(--color-error);
    border-radius: var(--border-radius-large);
    padding: 20px 24px;
    background: var(--color-main-background);
}
.manage-section--danger h3 {
    color: var(--color-error-text);
}
.manage-danger-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.manage-danger-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}
.manage-danger-title {
    font-size: var(--th-font-body);
    font-weight: 500;
}
.manage-danger-desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

/* Archive status notices in the danger zone */
.manage-archive-notice {
    display: flex;
    flex-direction: column;
    gap: 6px;
    border-radius: var(--border-radius);
    padding: 12px 14px;
    font-size: 13px;
    margin-bottom: 16px;
}

/* v3.100.16: full-saturation state notices per SKILLS.md
   (was raw hex — #fff8e1/#f9a825/#3e2a00 for pending, #ffebee/#c62828
   /#7f0000 for failed — none of which followed the dark theme). */
.manage-archive-notice--pending {
    background: var(--color-warning);
    border: 2px solid var(--color-warning);
    color: var(--color-warning-text);
}

.manage-archive-notice--pending strong {
    color: var(--color-warning-text);
    font-size: var(--th-font-body);
}

.manage-archive-notice--failed {
    background: var(--color-error);
    border: 2px solid var(--color-error);
    color: var(--color-error-text);
}

.manage-archive-notice--failed strong {
    color: var(--color-error-text);
    font-size: var(--th-font-body);
}

.manage-archive-notice__reason {
    font-family: monospace;
    font-size: var(--th-font-micro);
    background: rgba(0, 0, 0, 0.07);
    border-radius: 3px;
    padding: 4px 6px;
    word-break: break-word;
    color: inherit;
}

/* ── Team image ────────────────────────────────────────────────── */
.team-image-preview-row {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.team-image-preview {
    width: 100px;
    height: 100px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-background-dark);
    flex-shrink: 0;
}

.team-image-preview--empty {
    border-style: dashed;
}

.team-image-preview__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.team-image-preview__placeholder {
    color: var(--color-text-maxcontrast);
    opacity: 0.4;
}

.team-image-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.team-image-hidden-input {
    display: none;
}

/* ── Owner transfer ─────────────────────────────────────────────── */
.manage-owner-search {
    position: relative;
    max-width: 400px;
    margin-bottom: 12px;
}

.manage-owner-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-body);
    box-sizing: border-box;
}

.manage-owner-input:focus {
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.manage-owner-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.manage-owner-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 100;
    list-style: none;
    margin: 4px 0 0;
    padding: 4px 0;
    background: var(--color-main-background);
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    max-height: 220px;
    overflow-y: auto;
}

.manage-owner-suggestion {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.manage-owner-suggestion:hover {
    background: var(--color-background-hover);
}

.manage-owner-suggestion__name {
    font-size: var(--th-font-body);
    font-weight: 500;
    flex: 1;
}

.manage-owner-suggestion__uid {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.manage-owner-selected {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    max-width: 500px;
}

.manage-owner-selected__name {
    font-size: var(--th-font-body);
    font-weight: 500;
    flex: 1;
}

/* ── Pending section spacing ─────────────────────────────────────── */
.manage-section--pending {
    margin-top: 24px;
}

/* ── Effective count summary ─────────────────────────────────────── */
.manage-section--summary {
    border: none;
    margin-bottom: 0;
    padding: 10px 14px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    display: flex;
    align-items: center;
}
.effective-count-label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}
.effective-count-label strong {
    color: var(--color-main-text);
    margin-left: 4px;
}

/* ── Groups & Circles list ───────────────────────────────────────── */
.group-circle-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.group-circle-list--spaced {
    margin-top: 16px;
}
.group-circle-section-label {
    font-size: var(--th-font-micro);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-maxcontrast);
    margin-bottom: 4px;
}
.group-circle-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
}
.group-circle-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--border-radius);
    flex-shrink: 0;
}
/* v3.100.14: decorative avatar tiles — the icon carries the group vs
   circle accent. Neutral surface per SKILLS.md § "State-coloured
   backgrounds" (were 18-22% color-mix soft tints). */
.group-circle-icon--group {
    background: var(--color-background-dark);
    color: var(--color-primary-element);
}
.group-circle-icon--circle {
    background: var(--color-background-dark);
    color: var(--color-warning-text);
}
.group-circle-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.group-circle-name {
    font-size: var(--th-font-body);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.group-circle-count {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Enable-mode dialog (Create new vs Connect existing) */
.enable-app-modes {
    display: flex;
    flex-direction: column;
    margin-top: 4px;
}
.enable-app-mode {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-body);
    cursor: pointer;
}
.enable-app-mode input[type="radio"] {
    accent-color: var(--color-primary-element);
    cursor: pointer;
}
.enable-app-picker {
    margin-left: 24px;
    margin-top: 4px;
    max-width: 360px;
}
/* Connected-resource deletion warning under "Delete team"
   v3.100.14: full-saturation warning per SKILLS.md § "State-coloured
   backgrounds" (was --color-warning-hover — a hover token used as state
   bg, which washed the banner out against the canvas). */
.manage-danger-warning {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 8px;
    padding: 8px 10px;
    border-left: 3px solid var(--color-warning);
    background: var(--color-warning);
    border-radius: 0 var(--border-radius) var(--border-radius) 0;
    font-size: var(--th-font-meta);
    line-height: 1.4;
    color: var(--color-warning-text);
}
.manage-danger-warning :first-child {
    flex-shrink: 0;
    margin-top: 1px;
    color: var(--color-warning-text);
}

/* Message settings tab */
.manage-setting-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 10px;
}

.manage-setting-label {
    font-size: 13px;
    font-weight: 500;
    min-width: 180px;
    color: var(--color-main-text);
}

.manage-setting-select {
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    font-size: 13px;
    cursor: pointer;
}

.manage-setting-select:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.manage-saved-indicator {
    font-size: 13px;
    color: var(--color-success-text);
    margin-left: 10px;
}

.manage-section__desc {
    /* v3.104.9 — same token alignment as .manage-section-desc above. */
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin: 4px 0 0;
}

/* v3.104.6 — sub-heading inside a manage-section (e.g. "Categories" under
   the Decisions section). Visually lighter than the section h3 so the
   parent section still reads as one block, with a small top margin to
   separate it from the preceding row group. */
.manage-section__subhead {
    margin: 18px 0 0;
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
}
/* ── Decision categories sub-panel (Session G) ── */
.teamhub-dec-cats__info {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
}

.teamhub-dec-cats__loading {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.teamhub-dec-cats__empty {
    padding: 8px 0;
}

.teamhub-dec-cats__empty-text {
    margin: 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

.teamhub-dec-cats__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.teamhub-dec-cats__row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    transition: background 0.1s;
}

.teamhub-dec-cats__row:hover {
    background: var(--color-background-hover);
}

.teamhub-dec-cats__row-main {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.teamhub-dec-cats__row-icon {
    font-size: var(--th-font-heading-lg);
    line-height: 1;
    flex-shrink: 0;
}

.teamhub-dec-cats__row-text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.teamhub-dec-cats__row-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
}

.teamhub-dec-cats__row-desc {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-dec-cats__row-approvers {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex-shrink: 0;
    max-width: 40%;
}

.teamhub-dec-cats__row-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.teamhub-dec-cats__edit {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    padding: 12px;
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}

/* Icon + name on same row */
.teamhub-dec-cats__edit-row {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.teamhub-dec-cats__edit-icon-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 0 0 52px;
    position: relative;
}

/* The icon trigger button */
.teamhub-dec-cats__icon-btn {
    width: 52px;
    height: 40px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--color-main-text);
    transition: border-color 0.15s, background 0.15s;
}
.teamhub-dec-cats__icon-btn:hover { border-color: var(--color-primary-element); }
.teamhub-dec-cats__icon-btn:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.teamhub-dec-cats__icon-btn-placeholder { color: var(--color-text-maxcontrast); font-size: var(--th-font-heading); }

/* Icon picker wrap — anchor for the popover and target for click-outside detection */
.teamhub-dec-cats__icon-picker-wrap { position: relative; }

/* Min-level select (Session B) */
.teamhub-dec-level-select {
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    min-width: 120px;
    cursor: pointer;
}

/* Decisions tab settings rows */
.manage-section__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 0;
    border-bottom: 1px solid var(--color-border);
}
.manage-section__row:last-child { border-bottom: none; }
.manage-section__row-info { flex: 1; min-width: 0; }
.manage-section__row-title {
    display: block;
    font-size: var(--th-font-body);
    font-weight: 600;
    color: var(--color-main-text);
}
.manage-section__row-desc {
    display: block;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
}

/* Icon picker popover — searchable, grouped, scrollable */
.teamhub-dec-cats__icon-popover {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 200;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    box-shadow: 0 6px 24px rgba(0,0,0,0.18);
    width: 320px;
    max-width: 90vw;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.teamhub-dec-cats__icon-search-wrap {
    display: flex;
    gap: 6px;
    padding: 8px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-hover);
}

.teamhub-dec-cats__icon-search {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: var(--th-font-meta);
    outline: none;
}
.teamhub-dec-cats__icon-search:focus {
    border-color: var(--color-primary-element);
}

.teamhub-dec-cats__icon-clear-btn {
    padding: 0 10px;
    font-size: var(--th-font-micro);
    background: transparent;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}
.teamhub-dec-cats__icon-clear-btn:hover { background: var(--color-background-dark); }

.teamhub-dec-cats__icon-scroll {
    max-height: 380px;
    overflow-y: auto;
    padding: 8px;
}

.teamhub-dec-cats__icon-group {
    margin-bottom: 8px;
}
.teamhub-dec-cats__icon-group:last-child {
    margin-bottom: 0;
}

.teamhub-dec-cats__icon-group-hdr {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--color-text-maxcontrast);
    letter-spacing: 0.5px;
    padding: 6px 4px 4px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 4px;
}

.teamhub-dec-cats__icon-group-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}

.teamhub-dec-cats__icon-no-results {
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    padding: 20px 0;
}

.teamhub-dec-cats__icon-grid-btn {
    width: 36px;
    height: 36px;
    border: 1px solid transparent;
    border-radius: var(--border-radius);
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--color-main-text);
    transition: background 0.1s, border-color 0.1s;
}
.teamhub-dec-cats__icon-grid-btn:hover { background: var(--color-background-hover); }
.teamhub-dec-cats__icon-grid-btn:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; }
/* v3.100.14: full-saturation selected state per SKILLS.md
   (was a 14% color-mix soft tint). */
.teamhub-dec-cats__icon-grid-btn--selected {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
}

.teamhub-dec-cats__edit-name-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.teamhub-dec-cats__edit-label {
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

.teamhub-dec-cats__edit-hint {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    font-style: italic;
    margin: -4px 0 0 0;
}

.teamhub-dec-cats__edit-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}

.teamhub-dec-cats__edit-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.teamhub-dec-cats__edit-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 4px;
}

.teamhub-dec-cats__edit-error {
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
    margin: 0;
}

.teamhub-dec-cats__add-area {
    padding-top: 4px;
}

/* Standalone variant — used in the dedicated Decisions tab (not nested inside widget-item) */
.teamhub-dec-cats__list--standalone {
    margin-bottom: 12px;
}

/* ── Timeline Milestones (v3.78.2) — mirrors .teamhub-dec-cats__* above,
   simplified (no icon picker, no approvers select). ──────────────────── */
.teamhub-milestones__empty-text {
    margin: 0;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

.teamhub-milestones__list {
    list-style: none;
    margin: 0 0 12px 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.teamhub-milestones__row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    transition: background 0.1s;
}
.teamhub-milestones__row:hover { background: var(--color-background-hover); }

.teamhub-milestones__row-main {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.teamhub-milestones__row-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-milestones__row-date {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    white-space: nowrap;
}
/* v3.100.16: NC theme token (was --th-color-warning hex). */
.teamhub-milestones__row-date--unset {
    color: var(--color-warning-text);
}

.teamhub-milestones__row-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.teamhub-milestones__edit {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    padding: 12px;
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}

.teamhub-milestones__edit-label {
    font-size: var(--th-font-meta);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    margin: 0;
}

.teamhub-milestones__edit-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}
.teamhub-milestones__edit-input:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.teamhub-milestones__edit-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 4px;
}

.teamhub-milestones__edit-error {
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
    margin: 0;
}

.teamhub-milestones__add-area {
    padding-top: 4px;
}

</style>
