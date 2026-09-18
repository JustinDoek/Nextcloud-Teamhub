<template>
    <div
        class="teamhub-home-view"
        :class="{
            'teamhub-home-view--editing': editMode,
            'teamhub-home-view--mobile': isMobile || isTablet,
        }">

        <!-- Edit mode hint banner
             v3.100.15: the two Default-layout icon buttons now render as
             NcButton (variant="tertiary") so they inherit NC's hover /
             focus / circular sizing behaviour. The custom
             .teamhub-layout-default-btn CSS block was retired. -->
        <div v-if="editMode && !isMobile && !isTablet" class="teamhub-edit-banner">
            <ViewDashboardEdit :size="16" />
            <span class="teamhub-edit-banner-text">{{ t('teamhub', 'Drag widgets to rearrange. Use the resize icon in the bottom-right corner of each widget to resize.') }}</span>
            <!-- Default layout actions — always shown in edit mode so they are always discoverable -->
            <div class="teamhub-edit-banner-actions">
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Save as my default layout for all teams')"
                    :aria-label="t('teamhub', 'Save as my default layout for all teams')"
                    @click="$emit('set-as-default')">
                    <template #icon>
                        <ContentSaveAll :size="16" />
                    </template>
                </NcButton>
                <NcButton
                    variant="tertiary"
                    :title="t('teamhub', 'Reset to my default layout')"
                    :aria-label="t('teamhub', 'Reset to my default layout')"
                    @click="$emit('reset-to-default')">
                    <template #icon>
                        <Restore :size="16" />
                    </template>
                </NcButton>
            </div>
        </div>

        <!-- v4.6.1 — vertical-compact is back ON. It was previously false, with
             TeamView.applySnap() standing in as a hand-rolled reflow. That
             reflow only ran on load and on resource change (never during a
             drag) and grouped columns by exact x equality, so a w=3 widget
             dragged from x=9 to x=8 became its own "column" and repacked to
             y=0 on top of the x=9 stack. The library's own compaction runs
             during the drag and resolves overlapping x-ranges correctly.
             applySnap has been deleted; this prop replaces it. -->
        <grid-layout
            v-if="!isMobile && !isTablet && layoutLoaded && gridLayout.length > 0"
            :layout="visibleLayout"
            :col-num="12"
            :row-height="80"
            :is-draggable="editMode"
            :is-resizable="editMode"
            :margin="[12, 12]"
            :use-css-transforms="true"
            :responsive="false"
            :vertical-compact="true"
            @update:layout="onLayoutUpdated">

            <!-- Message stream -->
            <grid-item
                v-if="showMessagesWidget && getGridItem('msgstream')"
                v-bind="getGridItem('msgstream')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card teamhub-widget-card--stream">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Message stream') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('msgstream', 'up')"
                        @keydown.down.prevent="moveWidget('msgstream', 'down')"
                        @keydown.left.prevent="moveWidget('msgstream', 'left')"
                        @keydown.right.prevent="moveWidget('msgstream', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Message stream') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <MessageOutline :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Team Messages') }}</h2>
                        <!-- v4.0.0 — Post-message action moved out of the
                             stream's first row and into the widget header so
                             it matches File Center / Decisions header icons.
                             Only rendered for users who meet the team's
                             post-role minimum (canPost getter). -->
                        <button
                            v-if="canPost"
                            class="teamhub-widget-header-btn"
                            :aria-label="t('teamhub', 'Post message')"
                            :title="t('teamhub', 'Post message')"
                            @click="openMessagePostForm">
                            <PlusIcon :size="18" aria-hidden="true" />
                        </button>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('msgstream')"
                            :widget-name="t('teamhub', 'Team Messages')"
                            @toggle="toggleCollapse('msgstream')" />
                    </div>
                    <MessageStream
                        ref="msgstream"
                        v-show="!isCollapsed('msgstream')"
                        :hide-header="true"
                        class="teamhub-widget-content" />
                </div>
            </grid-item>

            <!-- Team Info -->
            <grid-item
                v-if="getGridItem('widget-teaminfo')"
                v-bind="getGridItem('widget-teaminfo')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Team info') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-teaminfo', 'up')"
                        @keydown.down.prevent="moveWidget('widget-teaminfo', 'down')"
                        @keydown.left.prevent="moveWidget('widget-teaminfo', 'left')"
                        @keydown.right.prevent="moveWidget('widget-teaminfo', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Team info') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <InformationOutline :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Team Info') }}</h2>
                        <!-- Actions (Manage team / Copy link / Invite / Leave)
                             moved to the sidebar team-item 3-dot menu so the
                             Team info widget can be hidden by the owner without
                             stranding them. -->
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-teaminfo')"
                            :widget-name="t('teamhub', 'Team Info')"
                            @toggle="toggleCollapse('widget-teaminfo')" />
                    </div>

                    <!-- Resource warning strip — directly under header, matches DeckWidget unassigned pattern -->
                    <div
                        v-if="isTeamAdmin && resourceWarningTotal > 0"
                        class="teamhub-resource-warning"
                        role="alert"
                        aria-live="polite">
                        <AlertCircle :size="15" class="teamhub-resource-warning__icon" aria-hidden="true" />
                        <span class="teamhub-resource-warning__text">
                            <!-- TRANSLATORS: N is the number of connected resources that need review -->
                            {{ n('teamhub', '%n resource needs review.', '%n resources need review.', resourceWarningTotal, { n: resourceWarningTotal }) }}
                        </span>
                        <button
                            type="button"
                            class="teamhub-resource-warning__link"
                            :aria-label="t('teamhub', 'Open modules & integrations to review resources')"
                            :title="t('teamhub', 'Open modules & integrations to review resources')"
                            @click="openSettingsAtRisk">
                            <ChevronRightIcon :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <!-- v4.6.13 — expiration strip. Same shape as the resource
                         warning above, and admin-only for the same reason: the
                         only thing to do about it is request an extension, and
                         that is a team-admin action (SKILLS.md § Permissions —
                         hide what a member cannot do rather than disable it).
                         Only appears inside the warning window or after the
                         date has passed; a team expiring in eight months has
                         nothing to say here. -->
                    <div
                        v-if="isTeamAdmin && expiryBannerVisible"
                        class="teamhub-resource-warning"
                        :class="{ 'teamhub-resource-warning--expired': teamExpiry.expired }"
                        role="status"
                        aria-live="polite">
                        <AlertCircle :size="15" class="teamhub-resource-warning__icon" aria-hidden="true" />
                        <span class="teamhub-resource-warning__text">
                            <template v-if="teamExpiry.expired">
                                {{ t('teamhub', 'This team passed its expiration date on {date}.', { date: teamExpiry.expiresOn }) }}
                            </template>
                            <template v-else>
                                <!-- TRANSLATORS: N is a whole number of days until this team's expiration date -->
                                {{ n('teamhub',
                                     'This team expires in %n day.',
                                     'This team expires in %n days.',
                                     teamExpiry.daysRemaining,
                                     { n: teamExpiry.daysRemaining }) }}
                            </template>
                        </span>
                        <button
                            type="button"
                            class="teamhub-resource-warning__link"
                            :aria-label="t('teamhub', 'Open team maintenance to request an extension')"
                            :title="t('teamhub', 'Open team maintenance to request an extension')"
                            @click="openMaintenanceForExpiry">
                            <ChevronRightIcon :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <!-- v4.9.6 — a workspace whose provisioning is not finished says
                         so here, to every member; the details and the actions are
                         for the creator, an administrator and the team's admins.
                         Never hidden while a required step failed. -->
                    <ProvisioningBanner
                        v-if="provisioning && !provisioning.complete"
                        :provisioning="provisioning"
                        :is-team-admin="isTeamAdmin"
                        @changed="$emit('provisioning-changed')" />

                    <div v-show="!isCollapsed('widget-teaminfo')" class="teamhub-widget-content teamhub-widget-content--teaminfo">
                        <div class="teamhub-teaminfo-body">
                            <img
                                v-if="team.image_url"
                                :src="team.image_url"
                                :alt="team.name"
                                class="teamhub-teaminfo-logo" />
                            <p class="teamhub-team-description">{{ team.description || t('teamhub', 'No description') }}</p>
                        </div>
                        <div v-if="teamLabels.length" class="teamhub-team-labels" role="list" :aria-label="t('teamhub', 'Team type')">
                            <span
                                v-for="label in teamLabels"
                                :key="label.key"
                                :class="['teamhub-team-label', 'teamhub-team-label--' + label.tone]"
                                :title="label.tooltip"
                                role="listitem">
                                {{ label.text }}
                            </span>
                        </div>
                        <div v-if="teamOwner" class="teamhub-team-owner">
                            <span class="teamhub-info-label">{{ t('teamhub', 'Owner') }}</span>
                            <div class="teamhub-owner-row">
                                <NcAvatar
                                    v-if="teamOwner.userId"
                                    :user="teamOwner.userId"
                                    :display-name="teamOwner.displayName"
                                    :show-user-status="false"
                                    :size="22" />
                                <span class="teamhub-owner-name">{{ teamOwner.displayName }}</span>
                            </div>
                        </div>
                    </div><!-- end teamhub-widget-content -->
                </div><!-- end teamhub-widget-card -->
            </grid-item>

            <!-- Members -->
            <grid-item
                v-if="getGridItem('widget-members')"
                v-bind="getGridItem('widget-members')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Members') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-members', 'up')"
                        @keydown.down.prevent="moveWidget('widget-members', 'down')"
                        @keydown.left.prevent="moveWidget('widget-members', 'left')"
                        @keydown.right.prevent="moveWidget('widget-members', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Members') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <AccountGroup :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Members') }} ({{ effectiveMemberCount }})</h2>
                        <button
                            v-if="isTeamModerator && !editMode"
                            class="teamhub-widget-invite-btn"
                            :aria-label="t('teamhub', 'Invite members')"
                            :title="t('teamhub', 'Invite members')"
                            @click.stop="$emit('invite')">
                            <AccountPlus :size="18" />
                        </button>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-members')"
                            :widget-name="t('teamhub', 'Members')"
                            @toggle="toggleCollapse('widget-members')" />
                    </div>
                    <div v-show="!isCollapsed('widget-members')" class="teamhub-widget-content teamhub-widget-content--notoppad">
                        <MembersWidget @view-presence-calendar="$emit('set-view', 'presence')" />
                    </div>
                </div>
            </grid-item>

            <!-- Calendar widget -->
            <grid-item
                v-if="resources.calendar && resources.calendar.length > 0 && getGridItem('widget-calendar')"
                v-bind="getGridItem('widget-calendar')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Upcoming events') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-calendar', 'up')"
                        @keydown.down.prevent="moveWidget('widget-calendar', 'down')"
                        @keydown.left.prevent="moveWidget('widget-calendar', 'left')"
                        @keydown.right.prevent="moveWidget('widget-calendar', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Upcoming events') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <Calendar :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Upcoming Events') }}</h2>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionButton @click="$emit('add-event')">
                                <template #icon><CalendarPlus :size="20" /></template>
                                {{ t('teamhub', 'Add event') }}
                            </NcActionButton>
                            <NcActionButton @click="$emit('add-meeting')">
                                <template #icon><AccountGroup :size="20" /></template>
                                {{ t('teamhub', 'Add Meeting') }}
                            </NcActionButton>
                        </NcActions>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-calendar')"
                            :widget-name="t('teamhub', 'Upcoming Events')"
                            @toggle="toggleCollapse('widget-calendar')" />
                    </div>
                    <div v-show="!isCollapsed('widget-calendar')" class="teamhub-widget-content">
                        <CalendarWidget ref="calendarWidget" />
                    </div>
                </div>
            </grid-item>

            <!-- Upcoming Tasks widget (Deck cards + NC Tasks VTODOs) -->
            <grid-item
                v-if="showTasksWidget && getGridItem('widget-deck')"
                v-bind="getGridItem('widget-deck')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Upcoming tasks') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-deck', 'up')"
                        @keydown.down.prevent="moveWidget('widget-deck', 'down')"
                        @keydown.left.prevent="moveWidget('widget-deck', 'left')"
                        @keydown.right.prevent="moveWidget('widget-deck', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Upcoming tasks') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <CardText :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Upcoming Tasks') }}</h2>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionButton v-if="resources.deck && resources.deck.length > 0" @click="$emit('add-deck-task')">
                                <template #icon><CheckboxMarkedOutline :size="20" /></template>
                                {{ t('teamhub', 'Create Deck task') }}
                            </NcActionButton>
                            <NcActionButton v-if="resources.tasks && resources.calendar && resources.calendar.length > 0" @click="$emit('add-personal-task')">
                                <template #icon><ClipboardPlusOutline :size="20" /></template>
                                {{ t('teamhub', 'Create personal task') }}
                            </NcActionButton>
                            <!-- v4.9.15 — only when OpenProject grants "add work
                                 packages" to this viewer (hidden, not disabled). -->
                            <NcActionButton v-if="openProjectWorkActions.canCreate" @click="$emit('add-openproject-work-package')">
                                <template #icon><BriefcaseOutline :size="20" /></template>
                                {{ t('teamhub', 'Create work package') }}
                            </NcActionButton>
                        </NcActions>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-deck')"
                            :widget-name="t('teamhub', 'Upcoming Tasks')"
                            @toggle="toggleCollapse('widget-deck')" />
                    </div>
                    <div v-show="!isCollapsed('widget-deck')" class="teamhub-widget-content">
                        <DeckWidget ref="deckWidget" @openproject-actions="openProjectWorkActions = $event" />
                    </div>
                </div>
            </grid-item>

            <!-- Activity widget -->
            <grid-item
                v-if="getGridItem('widget-activity')"
                v-bind="getGridItem('widget-activity')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Team activity') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-activity', 'up')"
                        @keydown.down.prevent="moveWidget('widget-activity', 'down')"
                        @keydown.left.prevent="moveWidget('widget-activity', 'left')"
                        @keydown.right.prevent="moveWidget('widget-activity', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Team activity') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <ClockOutline :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Team Activity') }}</h2>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-activity')"
                            :widget-name="t('teamhub', 'Team Activity')"
                            @toggle="toggleCollapse('widget-activity')" />
                    </div>
                    <div v-show="!isCollapsed('widget-activity')" class="teamhub-widget-content">
                        <ActivityWidget @show-more="$emit('set-view', 'activity')" />
                    </div>
                </div>
            </grid-item>

            <!-- Pages widget (v4.3.7) — unified surface for Intranet
                 (Intravox) + Wiki (Collectives). Renders whichever
                 section(s) are enabled and have content. Grid gate
                 activates when EITHER provider is on for the team. -->
            <grid-item
                v-if="(resources.intravox || collectivesConfig?.collectives_enabled) && getGridItem('widget-pages')"
                v-bind="getGridItem('widget-pages')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Pages') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-pages', 'up')"
                        @keydown.down.prevent="moveWidget('widget-pages', 'down')"
                        @keydown.left.prevent="moveWidget('widget-pages', 'left')"
                        @keydown.right.prevent="moveWidget('widget-pages', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Pages') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <FileDocumentOutline :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Pages') }}</h2>
                        <!-- Actions menu (v4.3.9). Intranet actions
                             (Create/Delete page) show when the Intranet
                             team-app is on; the Wiki create action shows
                             when the Wiki team-app is on. Both are
                             visible when both are on. Wiki management
                             beyond create still belongs in Collectives'
                             own UI — one click on a page link opens the
                             full editor there. -->
                        <NcActions v-if="isTeamModerator && !editMode && (resources.intravox || collectivesConfig?.collectives_enabled)" class="teamhub-widget-actions">
                            <NcActionButton v-if="resources.intravox" @click="$emit('create-page')">
                                <template #icon><FilePlus :size="20" /></template>
                                {{ t('teamhub', 'Create Intranet page') }}
                            </NcActionButton>
                            <NcActionButton
                                v-if="resources.intravox"
                                :disabled="!pagesData.teamPage"
                                @click="$emit('delete-page')">
                                <template #icon><TrashCan :size="20" /></template>
                                {{ t('teamhub', 'Delete Intranet page') }}
                            </NcActionButton>
                            <NcActionButton v-if="collectivesConfig?.collectives_enabled" @click="$emit('create-wiki-page')">
                                <template #icon><FilePlus :size="20" /></template>
                                {{ t('teamhub', 'Create Collectives page') }}
                            </NcActionButton>
                        </NcActions>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-pages')"
                            :widget-name="t('teamhub', 'Pages')"
                            @toggle="toggleCollapse('widget-pages')" />
                    </div>
                    <div v-show="!isCollapsed('widget-pages')" class="teamhub-widget-content">
                        <IntravoxWidget
                            ref="intravoxWidget"
                            :can-act="isTeamModerator"
                            @pages-loaded="$emit('pages-loaded', $event)"
                            @collective-loaded="$emit('collective-loaded', $event)" />
                    </div>
                </div>
            </grid-item>

            <!-- Files Center widget — Favourite / Recent / Shared in one tabbed widget -->
            <grid-item
                v-if="resources.files && getGridItem('widget-files-center')"
                v-bind="getGridItem('widget-files-center')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Files') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-files-center', 'up')"
                        @keydown.down.prevent="moveWidget('widget-files-center', 'down')"
                        @keydown.left.prevent="moveWidget('widget-files-center', 'left')"
                        @keydown.right.prevent="moveWidget('widget-files-center', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Files') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <Folder :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'File Center') }}</h2>
                        <!-- v4.6.25 — was an <a target="_blank"> to /apps/files,
                             which left TeamHub for a new browser tab. Every
                             other file affordance in this app opens in the
                             Files tab; this one did not, and the + read as
                             "create a file somewhere else". Raw <button> per
                             SKILLS' widget-header carve-out, matching the
                             Decisions header button directly below. -->
                        <button
                            v-if="resources.files && resources.files.path"
                            class="teamhub-widget-header-btn"
                            :aria-label="t('teamhub', 'Open team folder in Files')"
                            @click="openTeamFolder">
                            <PlusIcon :size="18" aria-hidden="true" />
                        </button>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-files-center')"
                            :widget-name="t('teamhub', 'File Center')"
                            @toggle="toggleCollapse('widget-files-center')" />
                    </div>
                    <div v-show="!isCollapsed('widget-files-center')" class="teamhub-widget-content teamhub-widget-content--notoppad">
                        <FilesWidget />
                    </div>
                </div>
            </grid-item>

            <!-- Decisions widget — gated on module + team-level enable flag -->
            <grid-item
                v-if="showDecisionsWidget && getGridItem('widget-decisions')"
                v-bind="getGridItem('widget-decisions')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Decisions') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-decisions', 'up')"
                        @keydown.down.prevent="moveWidget('widget-decisions', 'down')"
                        @keydown.left.prevent="moveWidget('widget-decisions', 'left')"
                        @keydown.right.prevent="moveWidget('widget-decisions', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Decisions') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <GavelIcon :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Decisions') }}</h2>
                        <button
                            class="teamhub-widget-header-btn"
                            :aria-label="t('teamhub', 'Propose new decision')"
                            @click="$emit('propose-decision')">
                            <PlusIcon :size="18" aria-hidden="true" />
                        </button>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-decisions')"
                            :widget-name="t('teamhub', 'Decisions')"
                            @toggle="toggleCollapse('widget-decisions')" />
                    </div>
                    <div v-show="!isCollapsed('widget-decisions')" class="teamhub-widget-content teamhub-widget-content--notoppad">
                        <DecisionsWidget />
                    </div>
                </div>
            </grid-item>

            <!-- Project Health widget (v3.97.0, Track E Session 6) — gated on
                 Advanced-project + Execution phase + both Budget and Time
                 tab visibility. Payload gated server-side too. -->
            <grid-item
                v-if="showProjectHealthWidget && getGridItem('widget-project-health')"
                v-bind="getGridItem('widget-project-health')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Project health') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-project-health', 'up')"
                        @keydown.down.prevent="moveWidget('widget-project-health', 'down')"
                        @keydown.left.prevent="moveWidget('widget-project-health', 'left')"
                        @keydown.right.prevent="moveWidget('widget-project-health', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Project health') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <ViewDashboard :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Project health') }}</h2>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-project-health')"
                            :widget-name="t('teamhub', 'Project health')"
                            @toggle="toggleCollapse('widget-project-health')" />
                    </div>
                    <div v-show="!isCollapsed('widget-project-health')" class="teamhub-widget-content teamhub-widget-content--notoppad">
                        <ProjectHealthWidget @open-tab="$emit('set-view', $event)" />
                    </div>
                </div>
            </grid-item>

            <!-- Project info (v4.9.3, Phase 1; renamed and given its action
                 menu in v4.9.5) — gated on the team's OpenProject link
                 (src/lib/activeWidgets.js). Data is fetched by the widget
                 itself, as the viewer; it reports the links OpenProject
                 granted through `actions`, and the header menu shows exactly
                 those. Every link leaves TeamHub for OpenProject in a new tab
                 (OpenProject refuses to be framed) and says so. -->
            <grid-item
                v-if="showOpenProjectWidgets && getGridItem('widget-openproject')"
                v-bind="getGridItem('widget-openproject')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="t('teamhub', 'Project info') + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-openproject', 'up')"
                        @keydown.down.prevent="moveWidget('widget-openproject', 'down')"
                        @keydown.left.prevent="moveWidget('widget-openproject', 'left')"
                        @keydown.right.prevent="moveWidget('widget-openproject', 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ t('teamhub', 'Project info') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <BriefcaseOutline :size="25" />
                        <h2 class="teamhub-widget-title">{{ t('teamhub', 'Project info') }}</h2>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionLink
                                v-if="openProjectActions.projectUrl"
                                :href="openProjectActions.projectUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><OpenInNew :size="20" /></template>
                                {{ t('teamhub', 'Open project') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.workPackagesUrl"
                                :href="openProjectActions.workPackagesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><FormatListChecks :size="20" /></template>
                                {{ t('teamhub', 'Work packages') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.newWorkPackageUrl"
                                :href="openProjectActions.newWorkPackageUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><PlusBoxOutline :size="20" /></template>
                                {{ t('teamhub', 'New work package') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.filesUrl"
                                :href="openProjectActions.filesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><FolderOutline :size="20" /></template>
                                {{ t('teamhub', 'Project files') }}
                            </NcActionLink>
                            <NcActionButton @click="refreshOpenProject">
                                <template #icon><Refresh :size="20" /></template>
                                {{ t('teamhub', 'Refresh project data') }}
                            </NcActionButton>
                        </NcActions>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-openproject')"
                            :widget-name="t('teamhub', 'Project info')"
                            @toggle="toggleCollapse('widget-openproject')" />
                    </div>
                    <div v-show="!isCollapsed('widget-openproject')" class="teamhub-widget-content teamhub-widget-content--notoppad">
                        <OpenProjectOverviewWidget ref="openProjectWidget" @actions="openProjectActions = $event" />
                    </div>
                </div>
            </grid-item>

            <!-- External integration widgets -->
            <grid-item
                v-for="widget in teamWidgets"
                :key="'grid-int-' + widget.registry_id"
                v-bind="getOrCreateIntegrationItem(widget.registry_id)"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div
                        v-if="editMode"
                        class="teamhub-widget-drag-handle"
                        tabindex="0"
                        :aria-label="(widget.title || t('teamhub', 'Widget')) + ' — ' + t('teamhub', 'use arrow keys to move')"
                        @keydown.up.prevent="moveWidget('widget-int-' + widget.registry_id, 'up')"
                        @keydown.down.prevent="moveWidget('widget-int-' + widget.registry_id, 'down')"
                        @keydown.left.prevent="moveWidget('widget-int-' + widget.registry_id, 'left')"
                        @keydown.right.prevent="moveWidget('widget-int-' + widget.registry_id, 'right')">
                        <DragVariant :size="16" />
                        <span aria-hidden="true">{{ widget.title || t('teamhub', 'Widget') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <img
                            v-if="widget.app_id"
                            :src="appIconUrl(widget.app_id)"
                            :alt="widget.app_id"
                            class="teamhub-widget-app-icon"
                            @error="onAppIconError($event)" />
                        <Puzzle v-else :size="25" />
                        <h2 class="teamhub-widget-title">{{ widget.title }}</h2>
                        <NcActions
                            v-if="widgetDynamicActions[widget.registry_id] && widgetDynamicActions[widget.registry_id].length"
                            class="teamhub-widget-actions">
                            <NcActionButton
                                v-for="action in widgetDynamicActions[widget.registry_id]"
                                :key="action.label"
                                @click="triggerWidgetAction(widget.registry_id, action)">
                                <template #icon>
                                    <component
                                        :is="resolveWidgetActionIcon(action.icon)"
                                        :size="20" />
                                </template>
                                {{ action.label }}
                            </NcActionButton>
                        </NcActions>
                        <WidgetCollapseButton
                            :collapsed="isCollapsed('widget-int-' + widget.registry_id)"
                            :widget-name="widget.title"
                            @toggle="toggleCollapse('widget-int-' + widget.registry_id)" />
                    </div>
                    <div v-show="!isCollapsed('widget-int-' + widget.registry_id)" class="teamhub-widget-content">
                        <IntegrationWidget
                            :ref="'intWidget-' + widget.registry_id"
                            :integration="widget"
                            :team-id="currentTeamId"
                            @actions-loaded="onWidgetActionsLoaded" />
                    </div>
                </div>
            </grid-item>

        </grid-layout>

        <!--
            Tablet landscape layout (≤1200px landscape).
            60/40 split: message stream left, widget column right.
            Uses the same widget data and collapse state as the desktop
            grid — the gridLayout array drives which widgets appear and in
            what order (top to bottom). Each widget is collapsible.
            Edit mode is not available (button hidden in TeamTabBar).
        -->
        <div
            v-if="isTablet && layoutLoaded"
            class="teamhub-tablet-layout">

            <!-- ── Left: message stream (60%) ───────────────────── -->
            <!-- v3.104.1 — tablet layout drops the stream column entirely
                 when Messages is disabled for the team so widgets fill the
                 space rather than leaving a blank slab. -->
            <div v-if="showMessagesWidget" class="teamhub-tablet-stream">
                <MessageStream />
            </div>

            <!-- ── Right: widget column (40%, or 100% when stream is off) ── -->
            <div class="teamhub-tablet-widgets" :class="{ 'teamhub-tablet-widgets--full': !showMessagesWidget }">

                <!-- Team info -->
                <div v-if="getGridItem('widget-teaminfo')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-teaminfo')">
                            <InformationOutline :size="18" />
                            <span>{{ t('teamhub', 'Team info') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-teaminfo') }" />
                        </button>
                        <!-- Actions moved to the sidebar team-item 3-dot menu.
                             See the desktop grid variant above for the note. -->
                    </div>
                    <div v-if="!isCollapsed('widget-teaminfo')" class="teamhub-tablet-widget__body">
                        <div class="teamhub-tablet-teaminfo">
                            <img v-if="team.image_url" :src="team.image_url" :alt="team.name" class="teamhub-tablet-teaminfo__logo" />
                            <p class="teamhub-tablet-teaminfo__description">{{ team.description || t('teamhub', 'No description') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Members -->
                <div v-if="getGridItem('widget-members')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-members')">
                            <AccountGroup :size="18" />
                            <span>{{ t('teamhub', 'Members') }} ({{ effectiveMemberCount }})</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-members') }" />
                        </button>
                        <button
                            v-if="isTeamModerator"
                            type="button"
                            class="teamhub-tablet-widget__action-icon"
                            :aria-label="t('teamhub', 'Invite members')"
                            :title="t('teamhub', 'Invite members')"
                            @click="$emit('invite')">
                            <AccountPlus :size="18" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-members')" class="teamhub-tablet-widget__body teamhub-tablet-widget__body--notoppad">
                        <MembersWidget @view-presence-calendar="$emit('set-view', 'presence')" />
                    </div>
                </div>

                <!-- Calendar -->
                <div v-if="getGridItem('widget-calendar') && resources.calendar && resources.calendar.length > 0" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-calendar')">
                            <Calendar :size="18" />
                            <span>{{ t('teamhub', 'Upcoming events') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-calendar') }" />
                        </button>
                        <NcActions class="teamhub-tablet-widget__actions">
                            <NcActionButton @click="$emit('add-event')">
                                <template #icon><CalendarPlus :size="20" /></template>
                                {{ t('teamhub', 'Add event') }}
                            </NcActionButton>
                            <NcActionButton @click="$emit('add-meeting')">
                                <template #icon><AccountGroup :size="20" /></template>
                                {{ t('teamhub', 'Add Meeting') }}
                            </NcActionButton>
                        </NcActions>
                    </div>
                    <div v-if="!isCollapsed('widget-calendar')" class="teamhub-tablet-widget__body">
                        <CalendarWidget ref="calendarWidgetTablet" />
                    </div>
                </div>

                <!-- Tasks / Deck -->
                <div v-if="getGridItem('widget-deck') && showTasksWidget" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-deck')">
                            <CardText :size="18" />
                            <span>{{ t('teamhub', 'Upcoming tasks') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-deck') }" />
                        </button>
                        <NcActions class="teamhub-tablet-widget__actions">
                            <NcActionButton v-if="resources.deck && resources.deck.length > 0" @click="$emit('add-deck-task')">
                                <template #icon><CheckboxMarkedOutline :size="20" /></template>
                                {{ t('teamhub', 'Create Deck task') }}
                            </NcActionButton>
                            <NcActionButton v-if="resources.tasks && resources.calendar && resources.calendar.length > 0" @click="$emit('add-personal-task')">
                                <template #icon><ClipboardPlusOutline :size="20" /></template>
                                {{ t('teamhub', 'Create personal task') }}
                            </NcActionButton>
                            <!-- v4.9.15 — only when OpenProject grants "add work
                                 packages" to this viewer (hidden, not disabled). -->
                            <NcActionButton v-if="openProjectWorkActions.canCreate" @click="$emit('add-openproject-work-package')">
                                <template #icon><BriefcaseOutline :size="20" /></template>
                                {{ t('teamhub', 'Create work package') }}
                            </NcActionButton>
                        </NcActions>
                    </div>
                    <div v-if="!isCollapsed('widget-deck')" class="teamhub-tablet-widget__body">
                        <DeckWidget ref="deckWidgetTablet" @openproject-actions="openProjectWorkActions = $event" />
                    </div>
                </div>

                <!-- Activity — no actions -->
                <div v-if="getGridItem('widget-activity')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-activity')">
                            <ClockOutline :size="18" />
                            <span>{{ t('teamhub', 'Team activity') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-activity') }" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-activity')" class="teamhub-tablet-widget__body">
                        <ActivityWidget @show-more="$emit('set-view', 'activity')" />
                    </div>
                </div>

                <!-- Pages (Intranet + Wiki) — tablet (v4.3.7 unified) -->
                <div v-if="getGridItem('widget-pages') && (resources.intravox || collectivesConfig?.collectives_enabled)" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-pages')">
                            <FileDocumentOutline :size="18" />
                            <span>{{ t('teamhub', 'Pages') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-pages') }" />
                        </button>
                        <!-- Actions menu (v4.3.9) — same shape as desktop grid. -->
                        <NcActions v-if="isTeamModerator && (resources.intravox || collectivesConfig?.collectives_enabled)" class="teamhub-tablet-widget__actions">
                            <NcActionButton v-if="resources.intravox" @click="$emit('create-page')">
                                <template #icon><FilePlus :size="20" /></template>
                                {{ t('teamhub', 'Create Intranet page') }}
                            </NcActionButton>
                            <NcActionButton
                                v-if="resources.intravox"
                                :disabled="!pagesData.teamPage"
                                @click="pagesData.teamPage && $emit('delete-page')">
                                <template #icon><TrashCan :size="20" /></template>
                                {{ t('teamhub', 'Delete Intranet page') }}
                            </NcActionButton>
                            <NcActionButton v-if="collectivesConfig?.collectives_enabled" @click="$emit('create-wiki-page')">
                                <template #icon><FilePlus :size="20" /></template>
                                {{ t('teamhub', 'Create Collectives page') }}
                            </NcActionButton>
                        </NcActions>
                    </div>
                    <div v-if="!isCollapsed('widget-pages')" class="teamhub-tablet-widget__body">
                        <IntravoxWidget
                            :can-act="isTeamModerator"
                            @pages-loaded="$emit('pages-loaded', $event)"
                            @collective-loaded="$emit('collective-loaded', $event)" />
                    </div>
                </div>

                <!-- Files Center — tabbed widget -->
                <div v-if="getGridItem('widget-files-center') && resources.files" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-files-center')">
                            <Folder :size="18" />
                            <span>{{ t('teamhub', 'File Center') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-files-center') }" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-files-center')" class="teamhub-tablet-widget__body teamhub-tablet-widget__body--notoppad">
                        <FilesWidget />
                    </div>
                </div>

                <!-- Decisions widget — tablet layout -->
                <div v-if="showDecisionsWidget && getGridItem('widget-decisions')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-decisions')">
                            <GavelIcon :size="18" />
                            <span>{{ t('teamhub', 'Decisions') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-decisions') }" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-decisions')" class="teamhub-tablet-widget__body teamhub-tablet-widget__body--notoppad">
                        <DecisionsWidget />
                    </div>
                </div>

                <!-- Project Health widget — tablet layout (v3.97.0) -->
                <div v-if="showProjectHealthWidget && getGridItem('widget-project-health')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-project-health')">
                            <ViewDashboard :size="18" />
                            <span>{{ t('teamhub', 'Project health') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-project-health') }" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-project-health')" class="teamhub-tablet-widget__body teamhub-tablet-widget__body--notoppad">
                        <ProjectHealthWidget @open-tab="$emit('set-view', $event)" />
                    </div>
                </div>

                <!-- Project info — tablet layout (v4.9.3; menu v4.9.5). Same
                     links as the desktop header, from the same event. -->
                <div v-if="showOpenProjectWidgets && getGridItem('widget-openproject')" class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-openproject')">
                            <BriefcaseOutline :size="18" />
                            <span>{{ t('teamhub', 'Project info') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-openproject') }" />
                        </button>
                        <NcActions class="teamhub-tablet-widget__actions">
                            <NcActionLink
                                v-if="openProjectActions.projectUrl"
                                :href="openProjectActions.projectUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><OpenInNew :size="20" /></template>
                                {{ t('teamhub', 'Open project') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.workPackagesUrl"
                                :href="openProjectActions.workPackagesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><FormatListChecks :size="20" /></template>
                                {{ t('teamhub', 'Work packages') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.newWorkPackageUrl"
                                :href="openProjectActions.newWorkPackageUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><PlusBoxOutline :size="20" /></template>
                                {{ t('teamhub', 'New work package') }}
                            </NcActionLink>
                            <NcActionLink
                                v-if="openProjectActions.filesUrl"
                                :href="openProjectActions.filesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="t('teamhub', 'Opens in OpenProject')">
                                <template #icon><FolderOutline :size="20" /></template>
                                {{ t('teamhub', 'Project files') }}
                            </NcActionLink>
                            <NcActionButton @click="refreshOpenProject">
                                <template #icon><Refresh :size="20" /></template>
                                {{ t('teamhub', 'Refresh project data') }}
                            </NcActionButton>
                        </NcActions>
                    </div>
                    <div v-if="!isCollapsed('widget-openproject')" class="teamhub-tablet-widget__body teamhub-tablet-widget__body--notoppad">
                        <OpenProjectOverviewWidget ref="openProjectWidgetTablet" @actions="openProjectActions = $event" />
                    </div>
                </div>

                <!-- External integration widgets — no standard actions -->
                <div
                    v-for="ext in teamWidgets"
                    :key="'tablet-int-' + ext.registry_id"
                    class="teamhub-tablet-widget">
                    <div class="teamhub-tablet-widget__header">
                        <button type="button" class="teamhub-tablet-widget__collapse" @click="toggleCollapse('widget-int-' + ext.registry_id)">
                            <Puzzle :size="18" />
                            <span>{{ ext.title || t('teamhub', 'Widget') }}</span>
                            <ChevronDown :size="16" class="teamhub-tablet-widget__chevron" :class="{ 'teamhub-tablet-widget__chevron--collapsed': isCollapsed('widget-int-' + ext.registry_id) }" />
                        </button>
                    </div>
                    <div v-if="!isCollapsed('widget-int-' + ext.registry_id)" class="teamhub-tablet-widget__body">
                        <IntegrationWidget
                            :integration="ext"
                            :team-id="currentTeamId"
                            @actions-loaded="$emit('widget-actions-loaded', $event)" />
                    </div>
                </div>

            </div>
        </div>

        <!--
            Mobile single-canvas view. Active when the parent passes
            isMobile=true (viewport ≤ 768px). All event handlers below
            re-emit upward so TeamView (which owns the modals & navigation)
            handles them exactly the same way it does for the desktop grid.
        -->
        <MobileWidgetView
            v-if="isMobile && layoutLoaded"
            ref="mobileView"
            :team-labels="teamLabels"
            :team-owner="teamOwner"
            :is-team-admin="isTeamAdmin"
            :is-team-moderator="isTeamModerator"
            :show-tasks-widget="showTasksWidget"
            :pages-data="pagesData"
            @manage-team="$emit('manage-team')"
            @copy-link="$emit('copy-link')"
            @invite="$emit('invite')"
            @leave-team="onLeaveTeamClick"
            @schedule-meeting="$emit('schedule-meeting')"
            @add-event="$emit('add-event')"
            @add-meeting="$emit('add-meeting')"
            @add-deck-task="$emit('add-deck-task')"
            @add-personal-task="$emit('add-personal-task')"
            @add-openproject-work-package="$emit('add-openproject-work-package')"
            @create-page="$emit('create-page')"
            @delete-page="$emit('delete-page')"
            @create-wiki-page="$emit('create-wiki-page')"
            @pages-loaded="$emit('pages-loaded', $event)"
            @collective-loaded="$emit('collective-loaded', $event)"
            @set-view="$emit('set-view', $event)"
            @widget-actions-loaded="$emit('widget-actions-loaded', $event)" />

    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { mapState, mapGetters, mapMutations } from 'vuex'
import { NcAvatar, NcActions, NcActionButton, NcActionLink, NcButton } from '@nextcloud/vue'
import { GridLayout, GridItem } from 'grid-layout-plus'
import { computeActiveWidgetIds, isProjectHealthActive } from '../lib/activeWidgets.js'

/**
 * v4.6.1 — any saved y at or above this is a leftover parking position from
 * the removed applySnap(), not a real coordinate. Kept in sync with
 * LayoutController::PARK_Y_THRESHOLD, which unparks these on read.
 */
const PARK_Y_THRESHOLD = 1000

import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import PlusIcon    from 'vue-material-design-icons/Plus.vue'

// Canonical Circles config bit values — see src/constants/circlesConfig.js
import {
    CFG_VISIBLE,
    CFG_OPEN,
    CFG_INVITE,
    CFG_REQUEST,
    CFG_PROTECTED,
} from '../constants/circlesConfig.js'
import Cog from 'vue-material-design-icons/Cog.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import ViewDashboardEdit from 'vue-material-design-icons/ViewDashboardEdit.vue'
import DragVariant from 'vue-material-design-icons/DragVariant.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import Bell from 'vue-material-design-icons/Bell.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
// v3.100.14: ChevronUp import removed — the 12 desktop collapse buttons
// that used it were extracted into WidgetCollapseButton. ChevronDown stays
// because it is still used inline in the tablet layout below.
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import LocationExit from 'vue-material-design-icons/LocationExit.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import FilePlus from 'vue-material-design-icons/FilePlus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import ContentSaveAll from 'vue-material-design-icons/ContentSaveAll.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
// v4.9.5 — the Project info header menu: the links into OpenProject.
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import PlusBoxOutline from 'vue-material-design-icons/PlusBoxOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import MessageStream from './MessageStream.vue'
import DeckWidget from './DeckWidget.vue'
import CalendarWidget from './CalendarWidget.vue'
import IntravoxWidget from './IntravoxWidget.vue'
import ActivityWidget from './ActivityWidget.vue'
import IntegrationWidget from './IntegrationWidget.vue'
import FilesWidget          from './FilesWidget.vue'
import DecisionsWidget      from './DecisionsWidget.vue'
import MembersWidget        from './MembersWidget.vue'
import ProjectHealthWidget  from './ProjectHealthWidget.vue'
// v4.9.3 — OpenProject Phase 1: the Project info widget. (The My OpenProject
// work widget of 4.9.3 is gone in 4.9.5 — its rows are in My Work now.)
import OpenProjectOverviewWidget from './OpenProjectOverviewWidget.vue'
import MobileWidgetView from './MobileWidgetView.vue'
// v3.100.14: shared chevron toggle for every desktop widget header.
// See gui.md § 6 — this replaces 12 copies of the same 5-line button.
import WidgetCollapseButton from './WidgetCollapseButton.vue'
// v4.9.6 — the incomplete-provisioning strip in the Team info widget.
import ProvisioningBanner from './ProvisioningBanner.vue'

export default {
    name: 'TeamWidgetGrid',

    components: {
        NcAvatar, NcActions, NcActionButton, NcActionLink, NcButton,
        GridLayout, GridItem,
        MessageOutline, Folder, Calendar, CalendarPlus, CardText,
        CheckboxMarkedOutline, InformationOutline, AccountGroup,
        ClockOutline, FileDocumentOutline, ContentCopy, AccountPlus, PlusIcon,
        Cog, Puzzle, GavelIcon, ViewDashboardEdit, DragVariant,
        ChartBar, Bell, ViewDashboard, CheckCircle, FileDocument,
        ChevronDown, ChevronRightIcon, Delete, AlertCircle, ArrowRight, LocationExit,
        FormatListBulleted, Minus, FilePlus, TrashCan,
        ContentSaveAll, Restore,
        ClipboardPlusOutline, BriefcaseOutline,
        OpenInNew, FormatListChecks, PlusBoxOutline, FolderOutline, Refresh,
        MessageStream, DeckWidget, CalendarWidget, IntravoxWidget,
        ActivityWidget, IntegrationWidget,
        FilesWidget, DecisionsWidget, MembersWidget,
        ProjectHealthWidget,
        OpenProjectOverviewWidget,
        MobileWidgetView,
        WidgetCollapseButton,
        ProvisioningBanner,
    },

    props: {
        gridLayout:    { type: Array,   required: true },
        layoutLoaded:  { type: Boolean, default: false },
        editMode:      { type: Boolean, default: false },
        pagesData:     { type: Object,  default: () => ({ teamPage: null, subPages: [], teamhubRoot: null, allPages: [] }) },
        widgetDynamicActions: { type: Object, default: () => ({}) },
        // True when the current team's layout differs from the user's personal default.
        // Controls visibility of the "Set as default" / "Reset to default" buttons.
        layoutDiffersFromDefault: { type: Boolean, default: false },
        // True when the viewport is ≤ 768px. Switches rendering to the
        // MobileWidgetView (single canvas + icon bar) instead of the
        // vue-grid-layout. Edit-mode banner is also hidden in this mode.
        isMobile: { type: Boolean, default: false },
        // True when tablet landscape (≤1200px landscape). Switches rendering
        // to the 60/40 split view (message stream left, widget column right).
        // Edit-mode is suppressed in this mode.
        isTablet: { type: Boolean, default: false },
    },

    emits: [
        'layout-updated', 'layout-autofit', 'manage-team', 'copy-link', 'invite',
        'schedule-meeting', 'add-event', 'add-meeting', 'add-deck-task', 'add-personal-task',
        // v4.9.15 — the Upcoming tasks header's "Create work package" (labelled without "OpenProject" since v4.9.18).
        'add-openproject-work-package',
        'create-page', 'delete-page', 'create-wiki-page', 'pages-loaded', 'collective-loaded', 'set-view',
        'widget-actions-loaded',
        'set-as-default',
        'reset-to-default',
        'propose-decision',
        // v4.9.6 — a provisioning operation reached a resting state from the
        // banner's dialog; the view reloads the bundle.
        'provisioning-changed',
    ],

    data() {
        return {
            // v4.9.5 — the links the Project info widget currently has, as
            // it reports them (`actions` event); the header menu renders
            // exactly these. All null until the widget's first payload.
            openProjectActions: { projectUrl: null, workPackagesUrl: null, newWorkPackageUrl: null, filesUrl: null },
            // v4.9.15 — what the Upcoming tasks widget reported about the
            // viewer's OpenProject rights (`openproject-actions` event):
            // the header's create item shows exactly when this is true.
            openProjectWorkActions: { canCreate: false },
        }
    },

    computed: {
        ...mapState([
            'currentTeamId', 'resources', 'members',
            'effectiveMemberCount',
            'teamWidgets', 'isCurrentUserDirectMember',
            'resourceWarnings',
            'messageTarget',
            'presenceModuleEnabled', 'presenceConfig',
            'decisionsModuleEnabled', 'decisionsConfig',
            // v3.104.1 — per-team Messages toggle; drives the msgstream widget
            // and tablet-mode stream column so a team without messages doesn't
            // render either surface.
            'messagesConfig',
            // v4.3.5 — per-team Wiki (Collectives) toggle. Contributes to
            // the widget-pages gate (unified Pages widget renders when
            // EITHER Intranet or Wiki is enabled). `collectives_installed`
            // is a separate flag consumed by the Manage Team toggle to
            // render "Not installed" instead of erroring when the NC app
            // is absent.
            'collectivesConfig',
            // Team-wide dashboard customization — hidden_widgets removes widgets
            // from every member's grid; default_tab is consumed on team open.
            'dashboardConfig',
            // v4.0.2 — team template label from CreateTeamView. Renders as the
            // leading badge in the Team info widget's labels row.
            'teamType',
            // v4.6.13 — optional expiration date; drives the Team info strip.
            // Null for every team that has none, which is most of them.
            'teamExpiry',
            // v3.97.0 — gate for the project-health widget. Both flags are
            // precomputed on the layout bundle; project.mode + phase come
            // with the same bundle. The widget also self-checks the payload,
            // but gating here avoids a needless fetch on non-eligible views.
            'budgetConfig', 'timeConfig', 'project',
            // v4.9.3 — OpenProject link facts; gate for both OpenProject widgets.
            'openProjectConfig',
            // v4.9.6 — is this workspace's provisioning finished; the banner reads it.
            'provisioning',
        ]),
        ...mapGetters(['currentTeam', 'canPost']),

        team() { return this.currentTeam || {} },

        /**
         * Show the Upcoming Tasks widget when Deck is active for the team
         * OR when the NC Tasks app is installed and the team has a calendar.
         * The widget renders whichever subset of tasks is available.
         */
        showTasksWidget() {
            // v4.9.5 — or an OpenProject team: the widget then lists the
            // project's work packages by due date (DeckWidget's third source).
            return !!((this.resources.deck && this.resources.deck.length > 0)
                || (this.resources.tasks && this.resources.calendar && this.resources.calendar.length > 0)
                || this.showOpenProjectWidgets)
        },

        /**
         * Show the Decisions widget when the module is globally enabled
         * AND enabled for this specific team.
         */
        showDecisionsWidget() {
            return !!(this.decisionsModuleEnabled && this.decisionsConfig && this.decisionsConfig.decisions_enabled)
        },

        /**
         * v3.104.1 — per-team Messages toggle. Default true, so a team that
         * never touches the setting keeps its stream.
         */
        showMessagesWidget() {
            return this.messagesConfig?.messages_enabled !== false
        },

        /**
         * Show the Project Health widget for Advanced projects currently in
         * Planning or Execution phase, where the viewer sees BOTH the Budget
         * and Time tabs. Both flags are precomputed on the layout bundle
         * (LayoutController.getLayout), so this is a synchronous store read.
         * Widget content is also gated server-side; the frontend gate just
         * prevents an unnecessary fetch on non-eligible views.
         *
         * Planning included (v3.97.0 tweak): admins want the same
         * budget/time/milestone overview while they're still setting up the
         * work, not only once execution starts.
         */
        showProjectHealthWidget() {
            return isProjectHealthActive(this)
        },

        /**
         * v4.9.3 — the Project info widget renders exactly when the team is
         * of the OpenProject template and linked. Not gated on the
         * integration being usable for this viewer: the widget itself
         * explains what is missing (see activeWidgets.js). Also what puts
         * OpenProject rows into the Upcoming tasks widget (v4.9.5).
         */
        showOpenProjectWidgets() {
            return !!(this.openProjectConfig?.eligible && this.openProjectConfig?.linked)
        },

        /**
         * The set of widget IDs that are currently active (their v-if would be
         * true). Gating rules live in src/lib/activeWidgets.js — the single
         * source of truth shared with TeamView. Do not inline a rule here.
         *
         * applyHidden defaults to true: the grid must not render a widget the
         * admin has hidden.
         */
        activeWidgetIds() {
            return computeActiveWidgetIds(this)
        },

        /**
         * The layout slice passed to <grid-layout>.
         * Contains only active widgets — inactive ones are kept in gridLayout
         * (the full prop) for position memory but excluded here so VGL's total
         * height calculation stays reasonable and the scrollbar stays accurate.
         */
        visibleLayout() {
            return this.gridLayout.filter(item => this.activeWidgetIds.has(item.i))
        },

        /**
         * v4.1.0 — stable string key over the SET of currently-visible widget
         * ids. Sorted so the value only changes when membership changes, not
         * when order shuffles inside the array. Consumed by the auto-fit
         * watcher so a widget that becomes visible mid-session (e.g. Project
         * Health after phase advance) gets its one-shot fit pass.
         */
        visibleWidgetIds() {
            return this.visibleLayout.map(item => item.i).sort().join(',')
        },

        // v4.6.25 — `teamFolderUrl()` removed with the <a target="_blank"> it
        // fed. The team folder now opens in TeamHub's own Files tab, whose URL
        // `TeamView::filesUrl` already builds; a second copy of that
        // construction here was the thing the two had to be kept "in step".

        /**
         * Human-readable labels derived from the Circles config bitmask (team.config).
         *
         * Uses the canonical Circles bit values (imported from circlesConfig.js).
         * Real meanings:
         *   8   CFG_VISIBLE   — discoverable (public listing)
         *   16  CFG_OPEN      — anyone can join
         *   32  CFG_INVITE    — members can invite others
         *   64  CFG_REQUEST   — join requests need moderator approval (only with OPEN)
         *   256 CFG_PROTECTED — password-protected file shares
         *
         * Strategy: conditional — we only surface a label when it tells the member
         * something meaningful. `CFG_VISIBLE=0` (hidden) and `CFG_INVITE=0` (no
         * member invitations) are the defaults and get no label. `CFG_OPEN` is
         * shown in both states because "open to join" vs "invite-only" is a
         * first-class "what kind of team is this" fact.
         *
         * tone → CSS class → colour:
         *   success (green)  — welcoming / openness
         *   primary (blue)   — informational / neutral-positive state
         *   warning (amber)  — requires attention / friction
         *   neutral (grey)   — default / restrictive / niche
         */
        teamLabels() {
            const config = Number(this.team?.config || 0)
            if (!this.currentTeamId) return []

            const labels = []

            // v4.0.2 — template label from CreateTeamView. Leading position
            // so it reads as the team's identity. Legacy teams (teamType=null)
            // render no template badge — legacyFallback picked by Justin.
            if (this.teamType === 'collaboration') {
                labels.push({
                    key: 'type-collab',
                    text: t('teamhub', 'Collaboration'),
                    tooltip: t('teamhub', 'Created from the Collaboration template.'),
                    tone: 'primary',
                })
            } else if (this.teamType === 'project') {
                labels.push({
                    key: 'type-project',
                    text: t('teamhub', 'Project'),
                    tooltip: t('teamhub', 'Created from the Project template.'),
                    tone: 'primary',
                })
            } else if (this.teamType === 'department') {
                labels.push({
                    key: 'type-department',
                    text: t('teamhub', 'Department'),
                    tooltip: t('teamhub', 'Created from the Department template.'),
                    tone: 'primary',
                })
            } else if (this.teamType === 'openproject') {
                labels.push({
                    key: 'type-openproject',
                    text: t('teamhub', 'OpenProject project'),
                    tooltip: t('teamhub', 'Created from the OpenProject project template.'),
                    tone: 'primary',
                })
            }

            // Join mode — always shown (either state is informative)
            if (config & CFG_OPEN) {
                labels.push({
                    key: 'open',
                    text: t('teamhub', 'Open to join'),
                    tooltip: t('teamhub', 'Anyone can join this team without an invitation.'),
                    tone: 'success',
                })
            } else {
                labels.push({
                    key: 'invite-only',
                    text: t('teamhub', 'Invite-only'),
                    tooltip: t('teamhub', 'Only invited users can become members of this team.'),
                    tone: 'neutral',
                })
            }

            // Approval required — only meaningful together with OPEN
            if ((config & CFG_OPEN) && (config & CFG_REQUEST)) {
                labels.push({
                    key: 'request',
                    text: t('teamhub', 'Approval required'),
                    tooltip: t('teamhub', 'Join requests must be approved by a moderator before membership is granted.'),
                    tone: 'warning',
                })
            }

            // Member-driven invitations
            if (config & CFG_INVITE) {
                labels.push({
                    key: 'invite',
                    text: t('teamhub', 'Members can invite'),
                    tooltip: t('teamhub', 'Any member can invite other users to join this team.'),
                    tone: 'primary',
                })
            }

            // Discoverability
            if (config & CFG_VISIBLE) {
                labels.push({
                    key: 'visible',
                    text: t('teamhub', 'Public'),
                    tooltip: t('teamhub', 'This team is visible to everyone in the Browse Teams list.'),
                    tone: 'primary',
                })
            }

            // Password-protected shares
            if (config & CFG_PROTECTED) {
                labels.push({
                    key: 'protected',
                    text: t('teamhub', 'Password-protected'),
                    tooltip: t('teamhub', 'Files shared with this team are protected by a password.'),
                    tone: 'warning',
                })
            }

            // Note: no "No nested teams" label any more. That restriction is enforced
            // server-side for all teams via the invite-members controller — it is not
            // a per-team toggle and was previously misrepresented as CFG_SINGLE.

            return labels
        },

        teamOwner() {
            if (!this.members || !Array.isArray(this.members)) return null
            return this.members.find(m => m.level >= 9) || null
        },

        isTeamAdmin() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 8
        },

        /**
         * Combined count of pending + at-risk resources.
         * Used by the Teaminfo warning block (admin-only).
         */
        resourceWarningTotal() {
            const w = this.resourceWarnings || {}
            return (w.pending || 0) + (w.atRisk || 0)
        },

        /**
         * v4.6.13 — the expiration strip appears only once the date is close
         * or has passed. `warning` is computed server-side against the
         * instance's configured window, so the banner, the My Work item and
         * the admin table all agree on what "close" means.
         */
        expiryBannerVisible() {
            const e = this.teamExpiry
            return !!e && (e.expired || e.warning)
        },

        isTeamModerator() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 4
        },
    },

    watch: {
        /**
         * v4.5.26 — a deep link is about to scroll to a message. If the stream
         * widget is collapsed the target is `display: none` and the scroll
         * silently does nothing, so open it first. MessageStream itself does
         * the loading and highlighting; this only clears its way.
         */
        messageTarget(target) {
            if (target?.messageId) {
                this.expandWidget('msgstream')
            }
        },

        /**
         * v4.0.8 — first time the layout is fully hydrated after team open,
         * run the auto-fit pass so any widget flagged autoFit (DEFAULT_LAYOUT
         * items on a fresh team, or newly-added widgets) grows h to fit its
         * rendered content instead of showing a scrollbar. immediate:true
         * catches the case where layoutLoaded was already true when this
         * component mounted (e.g. a team switch while the previous layout
         * was cached).
         */
        layoutLoaded: {
            immediate: true,
            handler(newVal) {
                if (newVal) {
                    this.$nextTick(() => this.runAutoFitPass())
                }
            },
        },

        /**
         * v4.1.0 — also re-run the auto-fit pass whenever the visible-widget
         * set changes. Fixes widgets that were hidden on team open (e.g.
         * Project Health only shows in Planning/Execution) — layoutLoaded had
         * already flipped to true by the time they appeared, so the original
         * watcher never fired for them. The pass itself is idempotent (skips
         * items without autoFit), so extra invocations from unrelated widget
         * toggles are cheap.
         *
         * Watching a joined id string rather than the array reference so we
         * fire only when the SET of visible widgets actually changes — Vue's
         * default deep-watch on visibleLayout would fire on every h/w mutation
         * from the grid library itself and spam the pass during drag/resize.
         */
        visibleWidgetIds: {
            handler() {
                this.$nextTick(() => this.runAutoFitPass())
            },
        },
    },

    methods: {
        /**
         * v4.9.5 — the Project info header menu's Refresh. Whichever copy
         * of the widget is mounted (desktop grid or tablet column) owns the
         * request; the other ref is absent.
         */
        refreshOpenProject() {
            const widget = this.$refs.openProjectWidget || this.$refs.openProjectWidgetTablet
            if (widget && typeof widget.refresh === 'function') widget.refresh()
        },

        /**
         * v4.9.15 — re-read the Upcoming tasks widget's OpenProject rows
         * (TeamView calls this after a work package was created), on
         * whichever of the desktop, tablet or mobile widgets is mounted.
         */
        refreshOpenProjectWork() {
            const widget = this.$refs.deckWidget || this.$refs.deckWidgetTablet
                || this.$refs.mobileView?.$refs?.deckWidget
            if (widget && typeof widget.refreshOpenProject === 'function') widget.refreshOpenProject()
        },

        t, n,

        ...mapMutations(['SET_RESOURCE_WARNING_FOCUS', 'SET_MANAGE_TEAM_DEEP_LINK']),

        /**
         * Called from the warning block's chevron button.
         *
         * Order matters and is load-bearing: the flag is committed first, then
         * the emit makes App.vue render ManageTeamView, which reads the flag in
         * its own mounted() hook (v4.5.36). Committing after the emit would be
         * too late.
         */
        openSettingsAtRisk() {
            this.SET_RESOURCE_WARNING_FOCUS(true)
            this.$emit('manage-team')
        },

        /**
         * v4.6.13 — open Manage team on the Maintenance tab, where the
         * expiration panel and the request form live. Uses the same deep-link
         * mutation the Project Compass uses, so ManageTeamView lands on the
         * right tab whether it is already mounted or not.
         */
        openMaintenanceForExpiry() {
            this.SET_MANAGE_TEAM_DEEP_LINK({ tab: 'danger', section: 'expiry' })
            this.$emit('manage-team')
        },

        /**
         * v4.0.0 — desktop Messages widget header + button. Delegates to
         * MessageStream's public openPostForm() method so the composer opens
         * inside the stream (same behaviour the removed row-1 button had).
         * The ref is stable across collapse toggles because MessageStream
         * uses v-show, not v-if.
         */
        openMessagePostForm() {
            const stream = this.$refs.msgstream
            if (stream && typeof stream.openPostForm === 'function') {
                stream.openPostForm()
            }
        },

        onLayoutUpdated(newLayout) {
            // VGL only knows about visibleLayout (active items). Merge their
            // updated positions back into the full gridLayout so inactive
            // items keep their remembered position instead of being dropped.
            // (v4.6.1 — inactive items are no longer parked at y=9999; they
            // simply aren't in visibleLayout. The merge is still required.)
            const updatedMap = {}
            newLayout.forEach(item => { updatedMap[item.i] = item })

            const merged = this.gridLayout.map(item =>
                updatedMap[item.i] ? { ...updatedMap[item.i] } : item,
            )
            // Defensive: if VGL added a brand-new item not yet in gridLayout, include it.
            newLayout.forEach(item => {
                if (!merged.find(m => m.i === item.i)) merged.push({ ...item })
            })

            this.$emit('layout-updated', merged)
        },

        onLeaveTeamClick() {
            this.$emit('leave-team')
        },

        getGridItem(id) {
            // v4.2.3 — source from visibleLayout, not gridLayout. visibleLayout
            // already drops widgets whose resource gate is false OR that the
            // admin hid via dashboardConfig.hidden_widgets. Every widget's
            // v-if in this template calls getGridItem, so switching the source
            // makes toggling a widget off in Manage Team → Settings → Widgets
            // actually unmount the widget instead of just removing it from
            // the layout prop while the grid-item stayed rendered.
            return this.visibleLayout.find(item => item.i === id) || null
        },

        getOrCreateIntegrationItem(registryId) {
            const id = 'widget-int-' + registryId
            const existing = this.gridLayout.find(item => item.i === id)
            if (existing) return existing

            // v4.6.1 — ignore parked rows when finding the bottom edge. Layouts
            // saved before this version may still hold items at y=9999 (the old
            // applySnap parking position); one of those would put a new widget
            // at y≈10002, which is the "new widgets land far off the grid"
            // symptom. The server unparks on read, so this is belt-and-braces
            // for a layout already in memory when the fix ships.
            const maxBottom = this.gridLayout.reduce(
                (acc, item) => {
                    const y = item.y || 0
                    if (y >= PARK_Y_THRESHOLD) return acc
                    return Math.max(acc, y + (item.h || 3))
                }, 0,
            )
            const newItem = {
                i: id,
                x: 9, y: maxBottom,
                w: 3, h: 3,
                minW: 2, minH: 1,
                isResizable: true,
                collapsed: false,
                hSaved: 3,
                // v4.0.8 — auto-fit on next mount so the newly-appearing widget
                // opens with all content visible instead of a scrollbar. Cleared
                // by runAutoFitPass once the fit runs.
                autoFit: true,
            }
            this.gridLayout.push(newItem)
            this.$nextTick(() => this.runAutoFitPass())
            return newItem
        },

        /**
         * v4.0.8 — walk any item flagged autoFit, measure its rendered content
         * vs allocated grid space, and grow h until content fits (capped at 8
         * rows to keep runaway lists from stretching the whole page). After
         * fitting, strip the autoFit flag and persist.
         *
         * Triggers:
         *  1. Watcher on layoutLoaded — first team open with DEFAULT_LAYOUT.
         *  2. getOrCreateIntegrationItem — user enables an integration in
         *     Manage Team and its widget shows up on the home for the first time.
         *
         * Idempotent: items without autoFit are skipped; a re-run does nothing.
         */
        async runAutoFitPass() {
            // Two nextTicks + a small settle timeout give the browser time to
            // finish the initial layout AND for widget bodies that fetch data
            // asynchronously (calendar, deck, files) to have their first paint.
            // We accept that widgets whose content lands after the settle
            // window will still show a scrollbar the first time — the flag is
            // cleared so they won't grow spuriously on later loads.
            await this.$nextTick()
            await new Promise(r => setTimeout(r, 250))

            const rowHeight = 80
            const margin    = 12
            const maxH      = 8
            let changed     = false

            // Match rendered .vue-grid-item nodes to visibleLayout by index —
            // vue-grid-layout renders items in the same order the array holds.
            const nodes = this.$el?.querySelectorAll?.('.vue-grid-item') || []
            const visible = this.visibleLayout
            if (nodes.length !== visible.length) return

            visible.forEach((visItem, idx) => {
                const fullItem = this.gridLayout.find(g => g.i === visItem.i)
                if (!fullItem || !fullItem.autoFit) return

                const card = nodes[idx].querySelector('.teamhub-widget-card')
                if (!card) { delete fullItem.autoFit; changed = true; return }

                // scrollHeight reflects the space the content wants; clientHeight
                // is what the grid gave us. 4 px tolerance avoids single-pixel
                // rounding growth.
                const needed    = card.scrollHeight
                const allocated = card.clientHeight
                if (needed > allocated + 4) {
                    const extraPx     = needed - allocated
                    const rowStridePx = rowHeight + margin
                    const extraRows   = Math.ceil(extraPx / rowStridePx)
                    const newH        = Math.min(maxH, (fullItem.h || 3) + extraRows)
                    if (newH !== fullItem.h) {
                        fullItem.h      = newH
                        fullItem.hSaved = newH
                        this.gridLayout[this.gridLayout.indexOf(fullItem)] = { ...fullItem, autoFit: undefined }
                    }
                }
                delete fullItem.autoFit
                changed = true
            })

            if (changed) {
                // Route through onLayoutUpdated so inactive items are preserved,
                // then emit a distinct event that TeamView saves unconditionally
                // (regular layout-updated is gated on editMode).
                this.$emit('layout-autofit', this.gridLayout)
            }
        },

        isCollapsed(id) {
            const item = this.gridLayout.find(g => g.i === id)
            return item ? !!item.collapsed : false
        },

        /**
         * v4.6.25 — File Center header button. The store action clears any
         * pinned file first, so this lands on the team folder rather than
         * whatever file was last opened from a widget.
         */
        openTeamFolder() {
            this.$store.dispatch('openTeamFolderInEmbed')
        },

        /**
         * v4.5.26 — expand a widget that a deep link is about to scroll into.
         *
         * The message stream can be collapsed to its header, and `v-show`
         * leaves it in the DOM with `display: none` — where `scrollIntoView()`
         * does nothing and the landing highlight is invisible. So "Open" from
         * "What's new" would silently appear to do nothing for anyone who had
         * collapsed the stream. Unlike toggleCollapse() this only ever opens.
         */
        expandWidget(id) {
            const item = this.gridLayout.find(g => g.i === id)
            if (!item || !item.collapsed) return
            this.toggleCollapse(id)
        },

        toggleCollapse(id) {
            const item = this.gridLayout.find(g => g.i === id)
            if (!item) return
            if (item.collapsed) {
                item.h = item.hSaved || 3
                item.collapsed = false
            } else {
                item.hSaved = item.h
                item.h = 1
                item.collapsed = true
            }
            this.gridLayout[this.gridLayout.indexOf(item)] = { ...item } // Vue 3: direct assignment
            this.$emit('layout-updated', this.gridLayout)
        },

        /**
         * Move a widget one step in the given direction.
         * WCAG 2.5.7: keyboard/pointer alternative to drag-and-drop reordering.
         *
         * Grid is 12 columns (col-num=12). x is clamped to [0, 12-w].
         * y is adjusted by ±1; vue-grid-layout resolves vertical collisions
         * automatically by pushing other items down.
         *
         * @param {string} id       - The widget's gridLayout i-key
         * @param {'up'|'down'|'left'|'right'} direction
         */
        moveWidget(id, direction) {
            const idx = this.gridLayout.findIndex(g => g.i === id)
            if (idx === -1) return
            const item = { ...this.gridLayout[idx] }
            const cols = 12

            if (direction === 'left') {
                // Horizontal nudge — not affected by vertical compaction
                if (item.x <= 0) return
                this.gridLayout[idx] = { ...item, x: Math.max(0, item.x - 1) } // Vue 3

            } else if (direction === 'right') {
                if (item.x + item.w >= cols) return
                this.gridLayout[idx] = { ...item, x: Math.min(cols - item.w, item.x + 1) } // Vue 3

            } else {
                // Up / down: simple y nudge is cancelled by vue-grid-layout's vertical
                // compaction. Instead we sort all items by their current y (then x) to
                // get a logical reading order and swap positions with the neighbour.
                const sorted = [...this.gridLayout].sort((a, b) =>
                    a.y !== b.y ? a.y - b.y : a.x - b.x,
                )
                const sortedIdx = sorted.findIndex(g => g.i === id)
                const neighbourSortedIdx = direction === 'up' ? sortedIdx - 1 : sortedIdx + 1

                if (neighbourSortedIdx < 0 || neighbourSortedIdx >= sorted.length) return

                const neighbour = sorted[neighbourSortedIdx]
                const neighbourIdx = this.gridLayout.findIndex(g => g.i === neighbour.i)

                // Swap both x and y so the two widgets exchange grid positions exactly
                this.gridLayout[idx] = { ...item, x: neighbour.x, y: neighbour.y } // Vue 3
                this.gridLayout[neighbourIdx] = { ...neighbour, x: item.x, y: item.y } // Vue 3
            }

            this.$emit('layout-updated', this.gridLayout)
        },

        appIconUrl(appId) {
            return generateUrl(`/apps/${appId}/img/app.svg`)
        },

        onAppIconError(event) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = img.src.replace('.svg', '.png')
            } else {
                img.style.display = 'none'
            }
        },

        triggerWidgetAction(registryId, action) {
            if (!action) return
            const refKey = 'intWidget-' + registryId
            const widgetRefs = this.$refs[refKey]
            const widget = Array.isArray(widgetRefs) ? widgetRefs[0] : widgetRefs
            if (widget && typeof widget.openAction === 'function') {
                widget.openAction(action)
            } else if (action.url) {
                window.open(action.url, '_blank', 'noopener')
            }
        },

        onWidgetActionsLoaded({ registryId, actions }) {
            this.$emit('widget-actions-loaded', { registryId, actions })
        },

        resolveWidgetActionIcon(iconName) {
            const ICONS = {
                Puzzle: Puzzle, CalendarMonth: Calendar,
                ViewDashboard, AccountGroup, ChartBar, Bell,
                FileDocument, CheckCircle, AlertCircle,
                Message: MessageOutline, Folder, Plus: AccountPlus,
                ArrowRight, FormatListBulleted, Delete, Minus,
            }
            return (iconName && ICONS[iconName]) ? ICONS[iconName] : Puzzle
        },

        /** Expose intravoxWidget ref so parent can call refresh() and await it */
        refreshIntravox() {
            return this.$refs.intravoxWidget?.refresh() || Promise.resolve()
        },

        /** Expose calendarWidget ref so parent can reload events after an event is created */
        refreshCalendar() {
            return this.$refs.calendarWidget?.refresh() || Promise.resolve()
        },
    },
}
</script>

<style scoped>
/* v3.100.16: theme-safe canvas backdrop (was #f4f4f4 — a light-grey
   pinned in raw hex that made the home view read as a bright rectangle
   in dark mode). */
.teamhub-home-view {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px;
    box-sizing: border-box;
    background: var(--color-background-dark);
}

/*
 * Mobile mode: the MobileWidgetView component owns its own canvas, FAB
 * placement, and background. The wrapper just needs to give it the full
 * viewport — no padding, no grey backdrop. overflow:hidden because the
 * mobile view manages its own scrolling internally.
 */
.teamhub-home-view--mobile {
    padding: 0;
    background: var(--color-main-background);
    overflow: hidden;
}

.teamhub-home-view--editing {
    background-image: repeating-linear-gradient(
        180deg,
        transparent 0px,
        transparent 79px,
        var(--color-border) 79px,
        var(--color-border) 80px
    );
}

.teamhub-edit-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    margin-bottom: 8px;
    background: var(--color-background-info, var(--color-background-hover));
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    font-size: 13px;
    color: var(--color-main-text);
}

.teamhub-edit-banner-text {
    flex: 1;
}

.teamhub-edit-banner-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}

/* v3.100.15: .teamhub-layout-default-btn was the CSS for the two raw
   <button>s in the edit-mode banner that are now NcButtons; NcButton
   owns the sizing, hover, focus, and cursor behaviour, so the block is
   retired. */

.teamhub-grid-item { touch-action: none; }
.teamhub-grid-item--editing { cursor: move; }

.teamhub-widget-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    box-sizing: border-box;
}

.teamhub-widget-drag-handle {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: var(--color-background-hover);
    border-bottom: 1px solid var(--color-border);
    cursor: grab;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    user-select: none;
}

.teamhub-widget-drag-handle:active { cursor: grabbing; }

/* Keyboard focus ring — Tab to reach the handle, then arrow keys to move (WCAG 2.5.7) */
.teamhub-widget-drag-handle:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.teamhub-widget-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.teamhub-widget-header :deep(svg) {
    color: var(--color-primary-element);
    fill: var(--color-primary-element);
}

.teamhub-widget-title {
    /* Reset browser h2 defaults — element changed from span to h2 for WCAG 1.3.1 */
    margin: 0;
    padding: 0;
    font-weight: 600;
    font-size: 18px;
    color: var(--color-primary-element);
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.teamhub-widget-actions { margin-left: auto; flex-shrink: 0; }

.teamhub-widget-app-icon {
    width: 25px;
    height: 25px;
    object-fit: contain;
    flex-shrink: 0;
}

.teamhub-widget-collapse-btn,
.teamhub-widget-invite-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--color-primary-element);
    cursor: pointer;
    border-radius: var(--border-radius);
    opacity: 0.8;
    transition: opacity 0.15s, background 0.15s;
    flex-shrink: 0;
}

.teamhub-widget-invite-btn { margin-right: 2px; }

.teamhub-widget-collapse-btn:hover,
.teamhub-widget-invite-btn:hover {
    opacity: 1;
    background: rgba(255, 255, 255, 0.15);
}

.teamhub-widget-content {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

/* Consolidated widgets (e.g. Files Center) manage their own internal padding
   via the tab bar — no extra padding needed at the content wrapper level. */
.teamhub-widget-content--notoppad { padding-top: 0; }

.teamhub-widget-content--teaminfo { padding: 0 12px 10px; }

/* Small icon-button in widget header (e.g. "open in Files" on Files Center) */
.teamhub-widget-header-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: var(--border-radius);
    color: var(--color-text-maxcontrast);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    margin-left: auto;
    flex-shrink: 0;
    text-decoration: none;
}
.teamhub-widget-header-btn:hover {
    background: var(--color-background-hover);
    color: var(--color-primary-element);
}

.teamhub-team-description {
    padding: 8px 0 4px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin: 0;
}

/* Config-bitmask-derived "team type" labels (CFG_OPEN, CFG_VISIBLE, etc.) */
.teamhub-team-labels {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
    padding: 2px 0;
}

.teamhub-team-label {
    display: inline-flex;
    align-items: center;
    height: 22px;
    padding: 0 10px;
    border-radius: 11px;
    font-size: var(--th-font-micro);
    font-weight: 500;
    line-height: 1;
    white-space: nowrap;
    cursor: help;
    border: 1px solid transparent;
    user-select: none;
}

/* v3.100.16: dropped the #1a1a1a hex fallbacks on the -text tokens —
   NC always defines --color-success-text / --color-warning-text, so
   the fallback was dead code that pinned a colour regardless of theme. */
.teamhub-team-label--success {
    background-color: var(--color-success);
    color: var(--color-success-text);
    border-color: var(--color-success);
}

.teamhub-team-label--primary {
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-color: var(--color-primary-element);
}

.teamhub-team-label--warning {
    background-color: var(--color-warning);
    color: var(--color-warning-text);
    border-color: var(--color-warning);
}

.teamhub-team-label--neutral {
    background-color: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    border-color: var(--color-border);
}

.teamhub-info-label {
    display: block;
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    margin-bottom: 4px;
    letter-spacing: 0.04em;
}

.teamhub-team-owner { margin-top: 12px; }

.teamhub-owner-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.teamhub-owner-name { font-size: 13px; color: var(--color-main-text); }

/* Resource warning strip — directly under widget header, matches DeckWidget
   unassigned pattern.
   v3.100.16: full-saturation warning per SKILLS.md § "State-coloured
   backgrounds" (was --color-warning-soft with rgba fallback — neither is
   an NC canonical token; the fallback fired because --color-warning-soft
   doesn't exist). --color-warning-element-light was similar; both are
   retired in favour of the fill + border pair using --color-warning /
   --color-warning-text. Also dropped the raw hex fallbacks on the -text
   / fill tokens (NC always defines the canonical variants). */
.teamhub-resource-warning {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    background: var(--color-warning);
    border-bottom: 1px solid var(--color-warning-text);
    font-size: 13px;
    color: var(--color-warning-text);
    line-height: 1.3;
}
.teamhub-resource-warning__icon {
    flex-shrink: 0;
    color: var(--color-warning-text);
}
/* v4.6.13 — a date that has already passed escalates the same strip from
   warning to error. The wording changes too ("passed its expiration date"),
   so the colour is reinforcement rather than the signal (WCAG 1.4.1). */
.teamhub-resource-warning--expired {
    background: var(--color-error);
    border-bottom-color: var(--color-error-text);
    color: var(--color-error-text);
}
.teamhub-resource-warning--expired .teamhub-resource-warning__icon,
.teamhub-resource-warning--expired .teamhub-resource-warning__link {
    color: var(--color-error-text);
}
.teamhub-resource-warning__text {
    flex: 1;
    min-width: 0;
}
.teamhub-resource-warning__link {
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    display: flex;
    align-items: center;
    color: var(--color-warning-text);
    cursor: pointer;
    opacity: 0.75;
    flex-shrink: 0;
}
.teamhub-resource-warning__link:hover { opacity: 1; }
.teamhub-resource-warning__link:focus-visible {
    outline: 2px solid var(--color-warning);
    outline-offset: 2px;
    border-radius: 2px;
}

.teamhub-teaminfo-body {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding-top: 10px;
}

.teamhub-teaminfo-logo {
    width: 56px;
    height: 56px;
    border-radius: var(--border-radius-large);
    object-fit: cover;
    border: 1px solid var(--color-border);
    flex-shrink: 0;
}

:deep(.vgl-item__resizer) { display: none; }

/* grid-layout-plus draws a default corner triangle via ::before — hide it,
   we render our own diagonal-arrows icon via ::after below. */
.teamhub-home-view--editing :deep(.vgl-item__resizer)::before {
    display: none;
}

/* Resize handle — small corner icon, bottom-right of each widget card. */
.teamhub-home-view--editing :deep(.vgl-item__resizer) {
    display: block;
    position: absolute;
    width: 28px;
    height: 28px;
    bottom: 4px;
    right: 4px;
    left: auto;
    background-image: none;
    border: none;
    background: transparent;
    cursor: se-resize;
    z-index: 10;
    border-radius: var(--border-radius);
    transition: background 0.15s;
}

.teamhub-home-view--editing :deep(.vgl-item__resizer:hover) {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

/* The diagonal-arrows icon rendered as an inline SVG via ::after. */
.teamhub-home-view--editing :deep(.vgl-item__resizer)::after {
    content: '';
    position: absolute;
    inset: 0;
    /* Diagonal expand arrows — matches the two-arrow icon in the design spec. */
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23555' d='M5 3h4V1H3a2 2 0 0 0-2 2v6h2V5.4l5.3 5.3 1.4-1.4L4.4 4H5V3zm14 16h-.6l-5.3-5.3-1.4 1.4 5.3 5.3V19h2v-6h-2v4zM19 3h-.6l-5.3 5.3 1.4 1.4L19.6 4H21V3h-4V1h6v6h-2V3zM5 19v-4H3v6h6v-2H5.4l5.3-5.3-1.4-1.4L4 19.6V19H5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 16px 16px;
    opacity: 0.55;
    transition: opacity 0.15s;
    pointer-events: none;
}

.teamhub-home-view--editing :deep(.vgl-item__resizer:hover)::after {
    opacity: 1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23ffffff' d='M5 3h4V1H3a2 2 0 0 0-2 2v6h2V5.4l5.3 5.3 1.4-1.4L4.4 4H5V3zm14 16h-.6l-5.3-5.3-1.4 1.4 5.3 5.3V19h2v-6h-2v4zM19 3h-.6l-5.3 5.3 1.4 1.4L19.6 4H21V3h-4V1h6v6h-2V3zM5 19v-4H3v6h6v-2H5.4l5.3-5.3-1.4-1.4L4 19.6V19H5z'/%3E%3C/svg%3E");
}

:deep(.vgl-item--placeholder) {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: 2px dashed var(--color-primary-element);
    border-radius: var(--border-radius-large);
    opacity: 0.4;
}

/* ─── Tablet landscape layout ──────────────────────────────── */

/*
 * 60/40 side-by-side layout for landscape viewports ≤ 1200px.
 * The .teamhub-home-view--mobile class already zeroes padding and
 * sets overflow:hidden on the wrapper — the tablet layout then fills
 * that space with a flex row.
 */
.teamhub-tablet-layout {
    display: flex;
    flex-direction: row;
    height: 100%;
    width: 100%;
    overflow: hidden;
    background: var(--color-main-background);
}

.teamhub-tablet-stream {
    flex: 0 0 60%;
    min-width: 0;
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    border-right: 1px solid var(--color-border);
}

.teamhub-tablet-widgets {
    flex: 0 0 40%;
    min-width: 0;
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 8px;
    box-sizing: border-box;
}

/* v3.104.1 — take the full width when the message stream column is hidden
   because Messages is disabled for the team. */
.teamhub-tablet-widgets--full {
    flex: 1 1 100%;
}

/* ─── Individual tablet widget card ───────────────────────── */

.teamhub-tablet-widget {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    flex-shrink: 0;
    overflow: hidden;
    background: var(--color-main-background);
}

/* Header is now a flex row div — collapse button on left, NcActions on right */
.teamhub-tablet-widget__header {
    display: flex;
    align-items: center;
    border-bottom: 1px solid transparent; /* space placeholder — visible when expanded */
}

/* The collapse button takes all remaining width */
.teamhub-tablet-widget__collapse {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    user-select: none;
    background: transparent;
    border: none;
    text-align: left;
    color: var(--color-main-text);
    min-width: 0;
    transition: background 0.12s ease;
}

.teamhub-tablet-widget__collapse:hover {
    background: var(--color-background-hover);
}

.teamhub-tablet-widget__collapse:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.teamhub-tablet-widget__collapse span {
    flex: 1 1 auto;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* NcActions sits flush to the right of the header */
.teamhub-tablet-widget__actions {
    flex-shrink: 0;
    margin-right: 4px;
}

/* Single icon-button action (e.g. members invite) — matches NcActions visual weight */
.teamhub-tablet-widget__action-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    margin-right: 4px;
    border: none;
    border-radius: var(--border-radius);
    background: transparent;
    color: var(--color-main-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.12s ease;
}

.teamhub-tablet-widget__action-icon:hover {
    background: var(--color-background-hover);
}

.teamhub-tablet-widget__action-icon:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.teamhub-tablet-widget__chevron {
    transition: transform 0.15s ease;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.teamhub-tablet-widget__chevron--collapsed {
    transform: rotate(-90deg);
}

.teamhub-tablet-widget__body {
    padding: 0 0 8px;
    border-top: 1px solid var(--color-border);
}
/* FilesWidget manages its own top chrome (tab bar) — no extra top border needed */
.teamhub-tablet-widget__body--notoppad {
    border-top: none;
}

/* ─── Tablet inline widget content ────────────────────────── */

.teamhub-tablet-teaminfo {
    padding: 0 14px 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.teamhub-tablet-teaminfo__logo {
    width: 40px;
    height: 40px;
    border-radius: var(--border-radius-large);
    object-fit: cover;
}

.teamhub-tablet-teaminfo__description {
    margin: 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--color-text-maxcontrast);
}
</style>
