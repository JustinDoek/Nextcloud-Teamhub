<template>
    <div
        class="teamhub-home-view"
        :class="{ 'teamhub-home-view--editing': editMode }">

        <!-- Edit mode hint banner -->
        <div v-if="editMode" class="teamhub-edit-banner">
            <ViewDashboardEdit :size="16" />
            <span class="teamhub-edit-banner-text">{{ t('teamhub', 'Drag widgets to rearrange. Use the resize icon in the bottom-right corner of each widget to resize.') }}</span>
            <!-- Default layout actions — only shown when current layout differs from user default -->
            <div v-if="layoutDiffersFromDefault" class="teamhub-edit-banner-actions">
                <button
                    class="teamhub-layout-default-btn"
                    :title="t('teamhub', 'Save as my default layout for all teams')"
                    :aria-label="t('teamhub', 'Save as my default layout for all teams')"
                    @click="$emit('set-as-default')">
                    <ContentSaveAll :size="16" />
                </button>
                <button
                    class="teamhub-layout-default-btn"
                    :title="t('teamhub', 'Reset to my default layout')"
                    :aria-label="t('teamhub', 'Reset to my default layout')"
                    @click="$emit('reset-to-default')">
                    <Restore :size="16" />
                </button>
            </div>
        </div>

        <grid-layout
            v-if="layoutLoaded && gridLayout.length > 0"
            :layout.sync="gridLayout"
            :col-num="12"
            :row-height="80"
            :is-draggable="editMode"
            :is-resizable="editMode"
            :margin="[12, 12]"
            :use-css-transforms="true"
            :responsive="false"
            @layout-updated="onLayoutUpdated">

            <!-- Message stream -->
            <grid-item
                v-if="getGridItem('msgstream')"
                v-bind="getGridItem('msgstream')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card teamhub-widget-card--stream">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Message stream') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <MessageOutline :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Team Messages') }}</span>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('msgstream') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('msgstream')">
                            <ChevronUp v-if="!isCollapsed('msgstream')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <MessageStream v-show="!isCollapsed('msgstream')" class="teamhub-widget-content" />
                </div>
            </grid-item>

            <!-- Team Info -->
            <grid-item
                v-if="getGridItem('widget-teaminfo')"
                v-bind="getGridItem('widget-teaminfo')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Team info') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <InformationOutline :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Team Info') }}</span>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionButton v-if="isTeamAdmin" @click="$emit('manage-team')">
                                <template #icon><Cog :size="20" /></template>
                                {{ t('teamhub', 'Manage team') }}
                            </NcActionButton>
                            <NcActionButton @click="$emit('copy-link')">
                                <template #icon><ContentCopy :size="20" /></template>
                                {{ t('teamhub', 'Copy team link') }}
                            </NcActionButton>
                            <NcActionButton @click="$emit('invite')">
                                <template #icon><AccountPlus :size="20" /></template>
                                {{ t('teamhub', 'Invite user') }}
                            </NcActionButton>
                            <NcActionButton @click="onLeaveTeamClick">
                                <template #icon><LocationExit :size="20" /></template>
                                {{ t('teamhub', 'Leave team') }}
                            </NcActionButton>
                        </NcActions>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-teaminfo') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-teaminfo')">
                            <ChevronUp v-if="!isCollapsed('widget-teaminfo')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-teaminfo')" class="teamhub-widget-content teamhub-widget-content--teaminfo">
                        <div class="teamhub-teaminfo-body">
                            <img
                                v-if="team.image_url"
                                :src="team.image_url"
                                :alt="team.name"
                                class="teamhub-teaminfo-logo" />
                            <p class="teamhub-team-description">{{ team.description || t('teamhub', 'No description') }}</p>
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
                    </div>
                </div>
            </grid-item>

            <!-- Members -->
            <grid-item
                v-if="getGridItem('widget-members')"
                v-bind="getGridItem('widget-members')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Members') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <AccountGroup :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Members') }} ({{ members.length }})</span>
                        <button
                            v-if="isTeamModerator && !editMode"
                            class="teamhub-widget-invite-btn"
                            :aria-label="t('teamhub', 'Invite members')"
                            :title="t('teamhub', 'Invite members')"
                            @click.stop="$emit('invite')">
                            <AccountPlus :size="18" />
                        </button>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-members') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-members')">
                            <ChevronUp v-if="!isCollapsed('widget-members')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-members')" class="teamhub-widget-content">
                        <div class="teamhub-avatar-stack">
                            <NcAvatar
                                v-for="member in members.slice(0, 10)"
                                v-if="member.userId"
                                :key="member.userId"
                                :user="member.userId"
                                :display-name="member.displayName"
                                :show-user-status="false"
                                :disable-menu="false"
                                :size="32"
                                class="teamhub-stacked-avatar" />
                            <span v-if="members.length > 10" class="teamhub-more-members">
                                +{{ members.length - 10 }}
                            </span>
                        </div>
                    </div>
                </div>
            </grid-item>

            <!-- Calendar widget -->
            <grid-item
                v-if="resources.calendar && getGridItem('widget-calendar')"
                v-bind="getGridItem('widget-calendar')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Upcoming events') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <Calendar :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Upcoming Events') }}</span>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionButton v-if="resources.talk" @click="$emit('schedule-meeting')">
                                <template #icon><VideoIcon :size="20" /></template>
                                {{ t('teamhub', 'Schedule meeting') }}
                            </NcActionButton>
                            <NcActionButton @click="$emit('add-event')">
                                <template #icon><CalendarPlus :size="20" /></template>
                                {{ t('teamhub', 'Add agenda item') }}
                            </NcActionButton>
                        </NcActions>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-calendar') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-calendar')">
                            <ChevronUp v-if="!isCollapsed('widget-calendar')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-calendar')" class="teamhub-widget-content">
                        <CalendarWidget />
                    </div>
                </div>
            </grid-item>

            <!-- Deck widget -->
            <grid-item
                v-if="resources.deck && getGridItem('widget-deck')"
                v-bind="getGridItem('widget-deck')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Upcoming tasks') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <CardText :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Upcoming Tasks') }}</span>
                        <NcActions class="teamhub-widget-actions">
                            <NcActionButton @click="$emit('add-task')">
                                <template #icon><CheckboxMarkedOutline :size="20" /></template>
                                {{ t('teamhub', 'Add task') }}
                            </NcActionButton>
                        </NcActions>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-deck') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-deck')">
                            <ChevronUp v-if="!isCollapsed('widget-deck')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-deck')" class="teamhub-widget-content">
                        <DeckWidget />
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
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Team activity') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <ClockOutline :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Team Activity') }}</span>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-activity') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-activity')">
                            <ChevronUp v-if="!isCollapsed('widget-activity')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-activity')" class="teamhub-widget-content">
                        <ActivityWidget @show-more="$emit('set-view', 'activity')" />
                    </div>
                </div>
            </grid-item>

            <!-- Pages / Intravox widget -->
            <grid-item
                v-if="resources.intravox && getGridItem('widget-pages')"
                v-bind="getGridItem('widget-pages')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Pages') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <FileDocumentOutline :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Pages') }}</span>
                        <NcActions v-if="isTeamModerator && !editMode" class="teamhub-widget-actions">
                            <NcActionButton @click="$emit('create-page')">
                                <template #icon><FilePlus :size="20" /></template>
                                {{ t('teamhub', 'Create page') }}
                            </NcActionButton>
                            <NcActionButton
                                :disabled="!pagesData.teamPage"
                                @click="$emit('delete-page')">
                                <template #icon><TrashCan :size="20" /></template>
                                {{ t('teamhub', 'Delete page') }}
                            </NcActionButton>
                        </NcActions>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-pages') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-pages')">
                            <ChevronUp v-if="!isCollapsed('widget-pages')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-pages')" class="teamhub-widget-content">
                        <IntravoxWidget
                            ref="intravoxWidget"
                            :can-act="isTeamModerator"
                            @pages-loaded="$emit('pages-loaded', $event)" />
                    </div>
                </div>
            </grid-item>

            <!-- Files — Favourites widget -->
            <grid-item
                v-if="resources.files && getGridItem('widget-files-favorites')"
                v-bind="getGridItem('widget-files-favorites')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Favourite files') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <StarOutlineIcon :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Favourite Files') }}</span>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-files-favorites') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-files-favorites')">
                            <ChevronUp v-if="!isCollapsed('widget-files-favorites')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-files-favorites')" class="teamhub-widget-content">
                        <FilesFavoritesWidget />
                    </div>
                </div>
            </grid-item>

            <!-- Files — Recent widget -->
            <grid-item
                v-if="resources.files && getGridItem('widget-files-recent')"
                v-bind="getGridItem('widget-files-recent')"
                class="teamhub-grid-item"
                :class="{ 'teamhub-grid-item--editing': editMode }">
                <div class="teamhub-widget-card">
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ t('teamhub', 'Recently modified files') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <ClockOutlineIcon :size="25" />
                        <span class="teamhub-widget-title">{{ t('teamhub', 'Recently Modified') }}</span>
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-files-recent') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-files-recent')">
                            <ChevronUp v-if="!isCollapsed('widget-files-recent')" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
                    </div>
                    <div v-show="!isCollapsed('widget-files-recent')" class="teamhub-widget-content">
                        <FilesRecentWidget />
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
                    <div v-if="editMode" class="teamhub-widget-drag-handle">
                        <DragVariant :size="16" />
                        <span>{{ widget.title || t('teamhub', 'Widget') }}</span>
                    </div>
                    <div class="teamhub-widget-header">
                        <img
                            v-if="widget.app_id"
                            :src="appIconUrl(widget.app_id)"
                            :alt="widget.app_id"
                            class="teamhub-widget-app-icon"
                            @error="onAppIconError($event)" />
                        <Puzzle v-else :size="25" />
                        <span class="teamhub-widget-title">{{ widget.title }}</span>
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
                        <button
                            class="teamhub-widget-collapse-btn"
                            :aria-label="isCollapsed('widget-int-' + widget.registry_id) ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                            @click.stop="toggleCollapse('widget-int-' + widget.registry_id)">
                            <ChevronUp v-if="!isCollapsed('widget-int-' + widget.registry_id)" :size="16" />
                            <ChevronDown v-else :size="16" />
                        </button>
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
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { mapState, mapGetters } from 'vuex'
import { NcAvatar, NcActions, NcActionButton } from '@nextcloud/vue'
import { GridLayout, GridItem } from 'vue-grid-layout'

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
import Cog from 'vue-material-design-icons/Cog.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import ViewDashboardEdit from 'vue-material-design-icons/ViewDashboardEdit.vue'
import DragVariant from 'vue-material-design-icons/DragVariant.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import Bell from 'vue-material-design-icons/Bell.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
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
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'

import MessageStream from './MessageStream.vue'
import DeckWidget from './DeckWidget.vue'
import CalendarWidget from './CalendarWidget.vue'
import IntravoxWidget from './IntravoxWidget.vue'
import ActivityWidget from './ActivityWidget.vue'
import IntegrationWidget from './IntegrationWidget.vue'
import FilesFavoritesWidget from './FilesFavoritesWidget.vue'
import FilesRecentWidget from './FilesRecentWidget.vue'

export default {
    name: 'TeamWidgetGrid',

    components: {
        NcAvatar, NcActions, NcActionButton,
        GridLayout, GridItem,
        MessageOutline, Folder, Calendar, CalendarPlus, CardText,
        CheckboxMarkedOutline, InformationOutline, AccountGroup,
        ClockOutline, FileDocumentOutline, ContentCopy, AccountPlus,
        Cog, VideoIcon, Puzzle, ViewDashboardEdit, DragVariant,
        ChartBar, Bell, ViewDashboard, CheckCircle, FileDocument,
        ChevronUp, ChevronDown, Delete, AlertCircle, ArrowRight, LocationExit,
        FormatListBulleted, Minus, FilePlus, TrashCan,
        ContentSaveAll, Restore,
        StarOutlineIcon, ClockOutlineIcon,
        MessageStream, DeckWidget, CalendarWidget, IntravoxWidget,
        ActivityWidget, IntegrationWidget,
        FilesFavoritesWidget, FilesRecentWidget,
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
    },

    emits: [
        'layout-updated', 'manage-team', 'copy-link', 'invite',
        'schedule-meeting', 'add-event', 'add-task',
        'create-page', 'delete-page', 'pages-loaded', 'set-view',
        'widget-actions-loaded',
        'set-as-default',    // user clicked "Save as my default layout"
        'reset-to-default',  // user clicked "Reset to my default layout"
    ],

    computed: {
        ...mapState([
            'currentTeamId', 'resources', 'members',
            'intravoxAvailable', 'teamWidgets',
        ]),
        ...mapGetters(['currentTeam']),

        team() { return this.currentTeam || {} },

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

        isTeamModerator() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 4
        },
    },

    methods: {
        t,

        onLayoutUpdated(newLayout) {
            this.$emit('layout-updated', newLayout)
        },

        onLeaveTeamClick() {
            this.$emit('leave-team')
        },

        getGridItem(id) {
            return this.gridLayout.find(item => item.i === id) || null
        },

        getOrCreateIntegrationItem(registryId) {
            const id = 'widget-int-' + registryId
            const existing = this.gridLayout.find(item => item.i === id)
            if (existing) return existing

            const maxBottom = this.gridLayout.reduce(
                (acc, item) => Math.max(acc, (item.y || 0) + (item.h || 3)), 0,
            )
            const newItem = {
                i: id,
                x: 9, y: maxBottom,
                w: 3, h: 3,
                minW: 2, minH: 1,
                isResizable: true,
                collapsed: false,
                hSaved: 3,
            }
            this.gridLayout.push(newItem)
            return newItem
        },

        isCollapsed(id) {
            const item = this.gridLayout.find(g => g.i === id)
            return item ? !!item.collapsed : false
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
            this.$set(this.gridLayout, this.gridLayout.indexOf(item), { ...item })
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
    },
}
</script>

<style scoped>
.teamhub-home-view {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px;
    box-sizing: border-box;
    background: #f4f4f4;
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

.teamhub-layout-default-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--color-border);
    background: var(--color-main-background);
    color: var(--color-main-text);
    cursor: pointer;
    border-radius: var(--border-radius);
    opacity: 0.85;
    transition: opacity 0.15s, background 0.15s, border-color 0.15s;
    flex-shrink: 0;
}

.teamhub-layout-default-btn:hover {
    opacity: 1;
    background: var(--color-background-hover);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element);
}

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
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    user-select: none;
}

.teamhub-widget-drag-handle:active { cursor: grabbing; }

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

.teamhub-widget-content--teaminfo { padding: 0 12px 10px; }

.teamhub-team-description {
    padding: 8px 0 4px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    margin: 0;
}

.teamhub-info-label {
    display: block;
    font-size: 11px;
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

.teamhub-avatar-stack {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
}

.teamhub-stacked-avatar { border: 2px solid var(--color-main-background); }
.teamhub-more-members { font-size: 12px; color: var(--color-text-maxcontrast); }

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

:deep(.vue-resizable-handle) { display: none; }

/* Resize handle — small corner icon, bottom-right of each widget card. */
.teamhub-home-view--editing :deep(.vue-resizable-handle) {
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

.teamhub-home-view--editing :deep(.vue-resizable-handle:hover) {
    background: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
}

/* The diagonal-arrows icon rendered as an inline SVG via ::after. */
.teamhub-home-view--editing :deep(.vue-resizable-handle)::after {
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

.teamhub-home-view--editing :deep(.vue-resizable-handle:hover)::after {
    opacity: 1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%230082c9' d='M5 3h4V1H3a2 2 0 0 0-2 2v6h2V5.4l5.3 5.3 1.4-1.4L4.4 4H5V3zm14 16h-.6l-5.3-5.3-1.4 1.4 5.3 5.3V19h2v-6h-2v4zM19 3h-.6l-5.3 5.3 1.4 1.4L19.6 4H21V3h-4V1h6v6h-2V3zM5 19v-4H3v6h6v-2H5.4l5.3-5.3-1.4-1.4L4 19.6V19H5z'/%3E%3C/svg%3E");
}

:deep(.vue-grid-placeholder) {
    background: var(--color-primary-element-light, var(--color-background-hover));
    border: 2px dashed var(--color-primary-element);
    border-radius: var(--border-radius-large);
    opacity: 0.4;
}
</style>
