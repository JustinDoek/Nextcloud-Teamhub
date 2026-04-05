<template>
    <div class="teamhub-team-view">
        <!-- ── Tab bar ─────────────────────────────────────────────── -->
        <div class="teamhub-tab-bar">
            <!--
                Home tab is always first and excluded from the draggable set.
            -->
            <button
                class="teamhub-tab"
                :class="{ active: currentView === 'msgstream' }"
                @click="setView('msgstream')">
                <MessageOutline :size="16" />
                {{ t('teamhub', 'Home') }}
            </button>

            <!--
                vuedraggable wraps all other tabs.
                Each rendered tab shows a six-dot handle on hover.
            -->
            <draggable
                v-model="orderedTabs"
                :animation="150"
                ghost-class="teamhub-tab-ghost"
                drag-class="teamhub-tab-dragging"
                handle=".teamhub-tab-drag-handle"
                class="teamhub-tab-draggable"
                @end="onTabReorder">
                <template v-for="tab in orderedTabs">
                    <!-- Built-in: Talk -->
                    <button
                        v-if="tab.key === 'talk' && isBuiltinEnabled('spreed') && resources.talk && resources.talk.token"
                        :key="'tab-talk'"
                        class="teamhub-tab"
                        :class="{ active: currentView === 'talk' }"
                        @click="setView('talk')">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <Chat :size="16" />
                        {{ t('teamhub', 'Chat') }}
                    </button>

                    <!-- Built-in: Files -->
                    <button
                        v-else-if="tab.key === 'files' && isBuiltinEnabled('files') && resources.files && resources.files.path"
                        :key="'tab-files'"
                        class="teamhub-tab"
                        :class="{ active: currentView === 'files' }"
                        @click="setView('files')">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <Folder :size="16" />
                        {{ t('teamhub', 'Files') }}
                    </button>

                    <!-- Built-in: Calendar -->
                    <button
                        v-else-if="tab.key === 'calendar' && isBuiltinEnabled('calendar') && resources.calendar"
                        :key="'tab-calendar'"
                        class="teamhub-tab"
                        :class="{ active: currentView === 'calendar' }"
                        @click="setView('calendar')">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <Calendar :size="16" />
                        {{ t('teamhub', 'Calendar') }}
                    </button>

                    <!-- Built-in: Deck -->
                    <button
                        v-else-if="tab.key === 'deck' && isBuiltinEnabled('deck') && resources.deck && resources.deck.board_id"
                        :key="'tab-deck'"
                        class="teamhub-tab"
                        :class="{ active: currentView === 'deck' }"
                        @click="setView('deck')">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <CardText :size="16" />
                        {{ t('teamhub', 'Deck') }}
                    </button>

                    <!-- External app tabs -->
                    <button
                        v-else-if="tab.key.startsWith('ext-')"
                        :key="'tab-' + tab.key"
                        class="teamhub-tab"
                        :class="{ active: currentView === tab.key }"
                        @click="setView(tab.key)">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <component :is="resolveTabIcon(tab.icon)" :size="16" />
                        {{ tab.label }}
                    </button>

                    <!-- Web link tabs (open in new tab) -->
                    <a
                        v-else-if="tab.key.startsWith('link-')"
                        :key="'tab-' + tab.key"
                        :href="tab.url"
                        target="_blank"
                        rel="noopener"
                        class="teamhub-tab teamhub-tab--link">
                        <span class="teamhub-tab-drag-handle" :aria-label="t('teamhub', 'Drag to reorder')">⠿</span>
                        <OpenInNew :size="14" />
                        {{ tab.label }}
                    </a>
                </template>
            </draggable>

            <NcButton
                class="teamhub-tab-add"
                type="tertiary"
                :aria-label="t('teamhub', 'Manage links')"
                @click="showManageLinks = true">
                <template #icon><Plus :size="18" /></template>
            </NcButton>

            <!-- Edit layout toggle — shown only on Home view -->
            <NcButton
                v-if="currentView === 'msgstream'"
                class="teamhub-edit-layout-btn"
                :type="editMode ? 'primary' : 'tertiary'"
                :aria-label="editMode ? t('teamhub', 'Done editing layout') : t('teamhub', 'Edit layout')"
                @click="toggleEditMode">
                <template #icon><ViewDashboardEdit :size="18" /></template>
                {{ editMode ? t('teamhub', 'Done') : t('teamhub', 'Edit layout') }}
            </NcButton>
        </div>

        <!-- ── Content area ─────────────────────────────────────────── -->
        <div class="teamhub-content">

            <!-- Home view — unified drag grid -->
            <div
                v-show="currentView === 'msgstream'"
                class="teamhub-home-view"
                :class="{ 'teamhub-home-view--editing': editMode }">

                <!-- Edit mode hint banner -->
                <div v-if="editMode" class="teamhub-edit-banner">
                    <ViewDashboardEdit :size="16" />
                    {{ t('teamhub', 'Drag widgets to rearrange. Drag the bottom-right corner of the message stream to resize it.') }}
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

                    <!-- Message stream — resizable only -->
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
                                    <NcActionButton v-if="isTeamAdmin" @click="openManageTeam">
                                        <template #icon><Cog :size="20" /></template>
                                        {{ t('teamhub', 'Manage team') }}
                                    </NcActionButton>
                                    <NcActionButton @click="copyTeamLink">
                                        <template #icon><ContentCopy :size="20" /></template>
                                        {{ t('teamhub', 'Copy team link') }}
                                    </NcActionButton>
                                    <NcActionButton @click="inviteToTeam">
                                        <template #icon><AccountPlus :size="20" /></template>
                                        {{ t('teamhub', 'Invite user') }}
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
                                <p class="teamhub-team-description">{{ team.description || t('teamhub', 'No description') }}</p>
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
                                    <NcActionButton v-if="resources.talk" @click="showScheduleMeeting = true">
                                        <template #icon><VideoIcon :size="20" /></template>
                                        {{ t('teamhub', 'Schedule meeting') }}
                                    </NcActionButton>
                                    <NcActionButton @click="showAddEvent = true">
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
                                    <NcActionButton @click="showAddTask = true">
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
                                <ActivityWidget @show-more="setView('activity')" />
                            </div>
                        </div>
                    </grid-item>

                    <!-- Pages / Intravox widget -->
                    <grid-item
                        v-if="intravoxAvailable && getGridItem('widget-pages')"
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
                                <button
                                    class="teamhub-widget-collapse-btn"
                                    :aria-label="isCollapsed('widget-pages') ? t('teamhub', 'Expand') : t('teamhub', 'Collapse')"
                                    @click.stop="toggleCollapse('widget-pages')">
                                    <ChevronUp v-if="!isCollapsed('widget-pages')" :size="16" />
                                    <ChevronDown v-else :size="16" />
                                </button>
                            </div>
                            <div v-show="!isCollapsed('widget-pages')" class="teamhub-widget-content">
                                <IntravoxWidget />
                            </div>
                        </div>
                    </grid-item>

                    <!-- External integration widgets (dynamic) -->
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
                                <!-- App icon from the originating NC app; falls back to Puzzle -->
                                <img
                                    v-if="widget.app_id"
                                    :src="appIconUrl(widget.app_id)"
                                    :alt="widget.app_id"
                                    class="teamhub-widget-app-icon"
                                    @error="onAppIconError($event)" />
                                <Puzzle v-else :size="25" />
                                <span class="teamhub-widget-title">{{ widget.title }}</span>
                                <!-- Action button in header — triggers modal inside IntegrationWidget -->
                                <NcActions
                                    v-if="widget.action_url"
                                    class="teamhub-widget-actions">
                                    <NcActionButton
                                        @click="triggerWidgetAction(widget.registry_id)">
                                        <template #icon><Plus :size="20" /></template>
                                        {{ widget.action_label || t('teamhub', 'Action') }}
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
                                    :team-id="currentTeamId" />
                            </div>
                        </div>
                    </grid-item>

                </grid-layout>
            </div>

            <!-- Activity feed (full canvas) -->
            <ActivityFeedView v-if="currentView === 'activity'" />

            <!-- Embedded NC app views — full canvas, no grid -->
            <AppEmbed
                v-if="currentView === 'talk' && resources.talk"
                :url="talkUrl"
                :label="t('teamhub', 'Chat')" />
            <AppEmbed
                v-if="currentView === 'files' && resources.files"
                :url="filesUrl"
                :label="t('teamhub', 'Files')" />
            <AppEmbed
                v-if="currentView === 'calendar'"
                :url="calendarUrl"
                :label="t('teamhub', 'Calendar')" />
            <AppEmbed
                v-if="currentView === 'deck' && resources.deck"
                :url="deckUrl"
                :label="t('teamhub', 'Deck')" />

            <!-- External menu_item integrations — sandboxed iframe -->
            <template v-for="menuItem in externalMenuItems">
                <AppEmbed
                    v-if="currentView === 'ext-' + menuItem.registry_id && menuItem.iframe_url"
                    :key="'ext-canvas-' + menuItem.registry_id"
                    :url="menuItemUrl(menuItem)"
                    :label="menuItem.title" />
            </template>
        </div>

        <!-- ── Modals ─────────────────────────────────────────────── -->
        <ManageLinksModal v-if="showManageLinks" @close="showManageLinks = false" />

        <InviteMemberModal
            v-if="showInviteModal"
            :team-id="currentTeamId"
            @close="showInviteModal = false"
            @invited="$store.dispatch('fetchMembers', currentTeamId)" />

        <ScheduleMeetingModal
            v-if="showScheduleMeeting"
            :team-id="currentTeamId"
            @close="showScheduleMeeting = false; $store.dispatch('fetchMessages', currentTeamId)" />

        <AddEventModal
            v-if="showAddEvent"
            :team-id="currentTeamId"
            @close="showAddEvent = false" />

        <AddTaskModal
            v-if="showAddTask"
            :board-id="resources.deck && resources.deck.board_id"
            @close="showAddTask = false"
            @created="$store.dispatch('fetchDeckTasks', resources.deck && resources.deck.board_id)" />
    </div>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcAvatar, NcActionButton, NcActions } from '@nextcloud/vue'
import { GridLayout, GridItem } from 'vue-grid-layout'
import draggable from 'vuedraggable'

import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
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
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'

import MessageStream from './MessageStream.vue'
import DeckWidget from './DeckWidget.vue'
import CalendarWidget from './CalendarWidget.vue'
import IntravoxWidget from './IntravoxWidget.vue'
import ActivityWidget from './ActivityWidget.vue'
import IntegrationWidget from './IntegrationWidget.vue'
import ActivityFeedView from './ActivityFeedView.vue'
import ManageLinksModal from './ManageLinksModal.vue'
import InviteMemberModal from './InviteMemberModal.vue'
import ScheduleMeetingModal from './ScheduleMeetingModal.vue'
import AddEventModal from './AddEventModal.vue'
import AddTaskModal from './AddTaskModal.vue'
import AppEmbed from './AppEmbed.vue'

/** Debounce: call fn at most once per {delay}ms after the last invocation. */
function debounce(fn, delay) {
    let timer = null
    return function (...args) {
        clearTimeout(timer)
        timer = setTimeout(() => fn.apply(this, args), delay)
    }
}

export default {
    name: 'TeamView',

    components: {
        NcButton,
        NcAvatar,
        NcActionButton,
        NcActions,
        GridLayout,
        GridItem,
        draggable,
        MessageOutline, Chat, Folder, Calendar, CalendarPlus, CardText,
        CheckboxMarkedOutline, Plus, OpenInNew, InformationOutline,
        AccountGroup, ClockOutline, FileDocumentOutline,
        ContentCopy, AccountPlus, Cog, VideoIcon, Puzzle,
        ViewDashboardEdit, DragVariant, ChevronUp, ChevronDown,
        MessageStream, DeckWidget, CalendarWidget, IntravoxWidget,
        ActivityWidget, IntegrationWidget, ActivityFeedView,
        ManageLinksModal, InviteMemberModal, ScheduleMeetingModal,
        AddEventModal, AddTaskModal, AppEmbed,
    },

    data() {
        return {
            // ── Layout ────────────────────────────────────────────────
            /** vue-grid-layout layout array — each item: {i, x, y, w, h, minW, minH, isResizable, collapsed, hSaved} */
            gridLayout: [],
            /** Ordered tab descriptors: {key, label, icon?, url?} */
            orderedTabs: [],
            /** True while in rearrange mode */
            editMode: false,
            /** Guard: don't save until the initial load completes */
            layoutLoaded: false,
            /** Assigned in created() — prevents save-spam during drag */
            _debouncedSave: null,

            // ── Modals ────────────────────────────────────────────────
            showManageLinks:     false,
            showInviteModal:     false,
            showScheduleMeeting: false,
            showAddEvent:        false,
            showAddTask:         false,
        }
    },

    computed: {
        ...mapState([
            'currentTeamId', 'currentView', 'resources', 'webLinks',
            'members', 'loading', 'intravoxAvailable', 'teamWidgets', 'teamMenuItems',
        ]),
        ...mapGetters(['currentTeam']),

        team() { return this.currentTeam || {} },

        teamOwner() {
            if (!this.members || !Array.isArray(this.members)) return null
            return this.members.find(m => m.level >= 9) || null
        },

        isTeamAdmin() {
            if (!this.members || !Array.isArray(this.members) || !this.members.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 8
        },

        talkUrl() {
            const token = this.resources.talk?.token
            return token ? generateUrl('/call/' + token) : generateUrl('/apps/spreed')
        },
        filesUrl() {
            const path = this.resources.files?.path || '/'
            return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(path)
        },
        calendarUrl() {
            const cal = this.resources.calendar
            return cal?.public_token
                ? generateUrl('/apps/calendar/p/' + cal.public_token)
                : generateUrl('/apps/calendar')
        },
        deckUrl() {
            const id = this.resources.deck?.board_id
            return generateUrl('/apps/deck') + (id ? '/#/board/' + id : '/')
        },

        externalMenuItems() {
            return (this.teamMenuItems || []).filter(item => !item.is_builtin)
        },
    },

    watch: {
        /** Reload layout whenever the active team changes. */
        currentTeamId(newId) {
            if (newId) {
                this.gridLayout = []
                this.orderedTabs = []
                this.layoutLoaded = false
                this.editMode = false
                this.loadLayout(newId)
            }
        },
        /** Sync link tabs when web links change. */
        webLinks() {
            this.syncLinkTabs()
        },
        /** Sync external tabs when integrations change. */
        externalMenuItems() {
            this.syncExtTabs()
        },
    },

    created() {
        this._debouncedSave = debounce(this.saveLayout, 1200)
    },

    mounted() {
        this.$store.dispatch('checkIntravox')
        if (this.currentTeamId) {
            this.loadLayout(this.currentTeamId)
        }
    },

    methods: {
        t,
        ...mapActions(['selectTeam']),
        ...mapMutations(['SET_VIEW']),

        setView(view) {
            this.SET_VIEW(view)
        },

        // ── Edit mode ─────────────────────────────────────────────────

        toggleEditMode() {
            this.editMode = !this.editMode
        },

        // ── Layout load ───────────────────────────────────────────────

        async loadLayout(teamId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/layout`),
                )

                this.gridLayout = Array.isArray(data.layout) ? data.layout : []
                this.buildOrderedTabs(Array.isArray(data.tabOrder) ? data.tabOrder : [])
                this.layoutLoaded = true
            } catch (err) {
                console.error('[TeamHub] TeamView: loadLayout error', err)
                this.gridLayout = []
                this.buildOrderedTabs([])
                this.layoutLoaded = true
            }
        },

        // ── Layout save ───────────────────────────────────────────────

        async saveLayout() {
            if (!this.currentTeamId || !this.layoutLoaded) {
                return
            }
            const tabOrder = this.orderedTabs.map(t => t.key)
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/layout`),
                    { layout: this.gridLayout, tabOrder },
                )
            } catch (err) {
                console.error('[TeamHub] TeamView: saveLayout error', err)
            }
        },

        // ── Grid event handlers ───────────────────────────────────────

        onLayoutUpdated(newLayout) {
            this.gridLayout = newLayout
            if (this.editMode && this.layoutLoaded) {
                this._debouncedSave()
            }
        },

        // ── Tab reorder ───────────────────────────────────────────────

        onTabReorder() {
            if (this.layoutLoaded) {
                this._debouncedSave()
            }
        },

        // ── Grid item lookup / creation ───────────────────────────────

        /**
         * Return the layout item for a given widget id, or null.
         */
        getGridItem(id) {
            return this.gridLayout.find(item => item.i === id) || null
        },

        /**
         * Return the layout item for an integration widget, creating a default
         * position at the bottom of the grid if it doesn't exist yet.
         * This handles newly-enabled integrations gracefully.
         */
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

        // ── Collapse ──────────────────────────────────────────────────

        /**
         * Returns true when the widget with the given id is currently collapsed.
         */
        isCollapsed(id) {
            const item = this.gridLayout.find(g => g.i === id)
            return item ? !!item.collapsed : false
        },

        /**
         * Toggle collapsed state for a widget.
         * When collapsing: save current h to hSaved, force h to 1.
         * When expanding:  restore h from hSaved.
         * Persisted via the normal debounced save.
         */
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
            if (this.layoutLoaded) {
                this._debouncedSave()
            }
        },

        // ── Tab list helpers ──────────────────────────────────────────

        /**
         * Build orderedTabs from a saved key array.
         * Unknown saved keys are dropped; new keys not in the saved list are appended.
         */
        buildOrderedTabs(savedOrder) {
            const all = this.buildAllTabDescriptors()
            const allMap = Object.fromEntries(all.map(t => [t.key, t]))

            let ordered = []
            if (savedOrder.length > 0) {
                // Restore saved order, skipping stale keys.
                savedOrder.forEach(key => {
                    if (allMap[key]) ordered.push(allMap[key])
                })
                // Append any new tabs not yet in the saved list.
                all.forEach(tab => {
                    if (!ordered.find(t => t.key === tab.key)) ordered.push(tab)
                })
            } else {
                ordered = all
            }

            this.orderedTabs = ordered
        },

        buildAllTabDescriptors() {
            const tabs = []

            // Built-in tabs (always in the list even if conditionally hidden in template).
            ;[
                { key: 'talk',     label: t('teamhub', 'Chat'),     icon: 'Chat' },
                { key: 'files',    label: t('teamhub', 'Files'),    icon: 'Folder' },
                { key: 'calendar', label: t('teamhub', 'Calendar'), icon: 'Calendar' },
                { key: 'deck',     label: t('teamhub', 'Deck'),     icon: 'CardText' },
            ].forEach(b => tabs.push(b))

            // External integration tabs.
            ;(this.teamMenuItems || [])
                .filter(item => !item.is_builtin)
                .forEach(item => tabs.push({
                    key:   'ext-' + item.registry_id,
                    label: item.title,
                    icon:  item.icon || 'Puzzle',
                }))

            // Web link tabs.
            ;(this.webLinks || []).forEach(link => tabs.push({
                key:   'link-' + link.id,
                label: link.title,
                url:   link.url,
            }))

            return tabs
        },

        syncLinkTabs() {
            const linkTabs = (this.webLinks || []).map(link => ({
                key:   'link-' + link.id,
                label: link.title,
                url:   link.url,
            }))
            this.orderedTabs = [
                ...this.orderedTabs.filter(t => !t.key.startsWith('link-')),
                ...linkTabs,
            ]
        },

        syncExtTabs() {
            const extTabs = (this.teamMenuItems || [])
                .filter(item => !item.is_builtin)
                .map(item => ({
                    key:   'ext-' + item.registry_id,
                    label: item.title,
                    icon:  item.icon || 'Puzzle',
                }))
            this.orderedTabs = [
                ...this.orderedTabs.filter(t => !t.key.startsWith('ext-')),
                ...extTabs,
            ]
        },

        // ── isBuiltinEnabled ──────────────────────────────────────────

        isBuiltinEnabled(appId) {
            if (!this.teamMenuItems || !this.teamMenuItems.length) return true
            return this.teamMenuItems.some(item => item.app_id === appId && item.is_builtin)
        },

        resolveTabIcon(iconName) {
            const supported = [
                'Message', 'Folder', 'Calendar', 'CardText', 'ViewDashboard',
                'AccountGroup', 'ChartBar', 'Bell', 'FileDocument', 'Puzzle',
            ]
            return supported.includes(iconName) ? iconName : 'Puzzle'
        },

        menuItemUrl(menuItem) {
            if (!menuItem.iframe_url) return ''
            const sep = menuItem.iframe_url.includes('?') ? '&' : '?'
            return menuItem.iframe_url + sep + 'teamId=' + encodeURIComponent(this.currentTeamId)
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

        triggerWidgetAction(registryId) {
            const ref = this.$refs[`intWidget-${registryId}`]
            if (ref) {
                ref.openAction()
            } else {
                console.warn('[TeamView] triggerWidgetAction ref not found:', registryId)
            }
        },

        // ── Team actions ──────────────────────────────────────────────

        openManageTeam() {
            this.$emit('show-manage-team')
        },

        copyTeamLink() {
            const url = window.location.origin + generateUrl(`/apps/teamhub?team=${this.currentTeamId}`)
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(url)
                    .then(() => showSuccess(t('teamhub', 'Team link copied to clipboard')))
                    .catch(() => this.fallbackCopy(url))
            } else {
                this.fallbackCopy(url)
            }
        },

        fallbackCopy(text) {
            const ta = document.createElement('textarea')
            ta.value = text
            ta.style.cssText = 'position:fixed;left:-999999px'
            document.body.appendChild(ta)
            ta.select()
            try {
                document.execCommand('copy')
                showSuccess(t('teamhub', 'Team link copied to clipboard'))
            } catch {
                showError(t('teamhub', 'Could not copy link'))
            }
            document.body.removeChild(ta)
        },

        inviteToTeam() {
            this.showInviteModal = true
        },
    },
}
</script>

<style scoped>
/* ── Outer shell ─────────────────────────────────────────────────── */
.teamhub-team-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

/* ── Tab bar ─────────────────────────────────────────────────────── */
.teamhub-tab-bar {
    display: flex;
    gap: 4px;
    padding: 8px 16px 8px 44px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
    flex-shrink: 0;
    align-items: center;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
}

.teamhub-tab-bar::-webkit-scrollbar { display: none; }

/* The draggable wrapper renders as inline content, not a block. */
.teamhub-tab-draggable {
    display: contents;
}

.teamhub-tab {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 14px;
    border-radius: var(--border-radius-pill);
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}

.teamhub-tab:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

.teamhub-tab.active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.teamhub-tab--link {
    opacity: 0.85;
    border: 1px solid var(--color-border);
}

/* Six-dot drag handle — only visible on hover */
.teamhub-tab-drag-handle {
    cursor: grab;
    opacity: 0;
    transition: opacity 0.12s;
    font-size: 13px;
    line-height: 1;
    color: var(--color-text-maxcontrast);
    user-select: none;
}

.teamhub-tab:hover .teamhub-tab-drag-handle {
    opacity: 0.55;
}

.teamhub-tab-ghost {
    opacity: 0.35;
    background: var(--color-background-hover);
}

.teamhub-tab-dragging {
    cursor: grabbing;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    border-radius: var(--border-radius-pill);
}

.teamhub-tab-add {
    flex-shrink: 0;
}

.teamhub-edit-layout-btn {
    flex-shrink: 0;
    margin-left: auto;
    white-space: nowrap;
}

/* ── Content area ────────────────────────────────────────────────── */
.teamhub-content {
    flex: 1;
    overflow: hidden;
    min-height: 0;
    position: relative;
}

/* ── Home view (grid) ────────────────────────────────────────────── */
.teamhub-home-view {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px;
    box-sizing: border-box;
    background: #f4f4f4;
}

/* Subtle row-grid hint in edit mode */
.teamhub-home-view--editing {
    background-image: repeating-linear-gradient(
        180deg,
        transparent 0px,
        transparent 79px,
        var(--color-border) 79px,
        var(--color-border) 80px
    );
}

/* Edit mode hint banner */
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

/* ── Grid items ──────────────────────────────────────────────────── */
.teamhub-grid-item {
    touch-action: none; /* required for vue-grid-layout touch support */
}

.teamhub-grid-item--editing {
    cursor: move;
}

/* ── Widget cards ────────────────────────────────────────────────── */
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

/* Drag handle bar at top of card — shown only in edit mode (v-if) */
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

.teamhub-widget-drag-handle:active {
    cursor: grabbing;
}

/* Widget header: icon + title + actions — no background, primary colour text */
.teamhub-widget-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

/* Icons inside the header use the primary colour */
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

.teamhub-widget-actions {
    margin-left: auto;
    flex-shrink: 0;
}

/* NC app icon in integration widget header */
.teamhub-widget-app-icon {
    width: 25px;
    height: 25px;
    object-fit: contain;
    flex-shrink: 0;
    /* NC dark-mode inversion handled by NC itself for its own app icons */
}

/* Collapse toggle button */
.teamhub-widget-collapse-btn {
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

.teamhub-widget-collapse-btn:hover {
    opacity: 1;
    background: rgba(255, 255, 255, 0.15);
}

/* Widget body — scrollable */
.teamhub-widget-content {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

.teamhub-widget-content--teaminfo {
    padding: 0 12px 10px;
}

/* ── Team info internals ─────────────────────────────────────────── */
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

.teamhub-owner-name {
    font-size: 13px;
    color: var(--color-main-text);
}

/* ── Members widget ──────────────────────────────────────────────── */
.teamhub-avatar-stack {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
}

.teamhub-stacked-avatar {
    border: 2px solid var(--color-main-background);
}

.teamhub-more-members {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

/* ── vue-grid-layout overrides ───────────────────────────────────── */
/*
 * Resize handle — vue-grid-layout 2.4.x always renders .vue-resizable-handle
 * in the DOM regardless of :is-resizable. We hide it by default and only
 * show it when the parent has the --editing modifier class (i.e. editMode).
 * This ensures the handle cannot be seen or interacted with outside edit mode.
 */
:deep(.vue-resizable-handle) {
    /* Hidden by default — only visible inside the editing wrapper */
    display: none;
}

/*
 * Inside edit mode: override the default bottom-right corner bracket with a
 * prominent centred pill indicator. The pill sits at the bottom-centre of the
 * card and contains a horizontal double-arrow SVG (←→).
 *
 * The native hit-target is widened to span the full card width so clicking
 * anywhere along the bottom edge triggers the resize.
 */
.teamhub-home-view--editing :deep(.vue-resizable-handle) {
    display: block;

    /* Span the full width, positioned at the bottom */
    width: 100%;
    height: 22px;
    bottom: 0;
    right: 0;
    left: 0;

    /* Reset default image + border */
    background-image: none;
    border: none;
    border-radius: 0 0 var(--border-radius-large) var(--border-radius-large);

    /* Subtle hover zone */
    background: transparent;
    cursor: ns-resize;
    transition: background 0.15s;
    z-index: 10;
}

.teamhub-home-view--editing :deep(.vue-resizable-handle:hover) {
    background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

/*
 * The visible pill indicator sits in the centre of the handle via a
 * pseudo-element. It contains a double-arrow SVG encoded inline.
 */
.teamhub-home-view--editing :deep(.vue-resizable-handle)::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: 3px;
    transform: translateX(-50%);

    /* Pill size */
    width: 36px;
    height: 14px;

    /* Primary-colour pill background */
    background-color: var(--color-primary-element);
    border-radius: var(--border-radius-pill);

    /* Double-arrow SVG (horizontal ↔, white) encoded inline */
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='7 16 3 12 7 8'/%3E%3Cpolyline points='17 8 21 12 17 16'/%3E%3Cline x1='3' y1='12' x2='21' y2='12'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 20px 10px;

    opacity: 0.9;
    transition: opacity 0.15s, transform 0.15s;
    pointer-events: none;
}

.teamhub-home-view--editing :deep(.vue-resizable-handle:hover)::after {
    opacity: 1;
    transform: translateX(-50%) scaleX(1.08);
}

/* Drop placeholder */
:deep(.vue-grid-placeholder) {
    background: var(--color-primary-element-light, var(--color-background-hover));
    border: 2px dashed var(--color-primary-element);
    border-radius: var(--border-radius-large);
    opacity: 0.4;
}
</style>
