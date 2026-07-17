<template>
    <div class="teamhub-team-view">

        <!-- ── Tab bar ─────────────────────────────────────────────── -->
        <TeamTabBar
            v-model="orderedTabs"
            :edit-mode="editMode"
            :is-mobile="isMobile"
            :is-tablet="isTablet"
            :can-manage-links="canManageLinks"
            @tab-reorder="onTabReorder"
            @manage-links="showManageLinks = true"
            @toggle-edit-mode="toggleEditMode"
            @show-picker="onShowPicker" />

        <!-- ── Content area ─────────────────────────────────────────── -->
        <div class="teamhub-content">

            <!-- Project phase stepper — advanced project teams only, on the Home view.
                 v3.103.5: hidden on mobile. On phones (isMobile) the stepper
                 stacks ABOVE MobileWidgetView inside .teamhub-content, which
                 has overflow:hidden — the stepper eats vertical space and
                 pushes the mobile icon-bar + FAB off-screen so users can't
                 reach the widget-picker or the Add-message button. Setup
                 flows belong on desktop; mobile is consumption. -->
            <ProjectPhaseStepper
                v-if="isAdvancedProject && !isMobile"
                v-show="currentView === 'msgstream'"
                :phase="project.phase"
                :can-manage="isTeamAdmin"
                @show-info="showProjectGuide = true" />

            <!-- Project Compass panel — v3.98.0, folds in the pre-3.98.0
                 auto-open ProjectPhaseGuide dialog behaviour. Advanced
                 project teams only, on Home view. Setup checklist + Next
                 up prompt; Walkthrough button opens the phase Guide on
                 demand.
                 v3.98.4 — moderator/admin only. Almost every checklist
                 item requires admin (advance phase, set dates, add
                 milestones, set budget total, define workstreams); a
                 regular member seeing a panel of buttons that all 403 is
                 friction, not guidance. Members still get the phase
                 stepper + health widget + message stream, which cover
                 read-only visibility. -->
            <!-- v3.103.5: also hidden on mobile — same reason as the phase
                 stepper above. The Compass is a substantial admin-only
                 setup surface (checklist + next-up prompt + jump-links to
                 Manage Team) that on a phone shoves the entire widget
                 icon-bar + FAB off-screen. Admins do project setup on
                 desktop; mobile users keep the raw MobileWidgetView. -->
            <ProjectCompassPanel
                v-if="isAdvancedProject && isTeamModerator && !isMobile"
                v-show="currentView === 'msgstream'"
                @open-manage-team-section="openManageTeam"
                @set-view="setView"
                @invite="showInviteModal = true"
                @advance-phase="onCompassAdvancePhase"
                @open-swimlanes-modal="showSwimlanesModal = true"
                @generate-closing-artifact="showClosingArtifactModal = true"
                @archive-team="showArchivePolicyModal = true"
                @open-add-meeting="showAddMeeting = true" />

            <!-- v3.99.0 — Closing artifact generator + archive-policy warning. -->
            <ClosingArtifactModal
                v-if="isAdvancedProject && isTeamAdmin"
                :open="showClosingArtifactModal"
                @update:open="showClosingArtifactModal = $event"
                @generated="onClosingArtifactGenerated" />

            <ArchivePolicyWarningModal
                v-if="isAdvancedProject"
                :open="showArchivePolicyModal"
                @update:open="showArchivePolicyModal = $event"
                @confirm="onArchivePolicyConfirmed" />

            <!-- v3.98.2 — Planning-phase "Define workstreams" modal.
                 Opens from the Compass; adds lanes to the team's Deck
                 board via POST /deck/stacks. Emits lanes-changed on
                 success so the Compass refetches readiness. -->
            <ProjectSwimlanesModal
                v-if="isAdvancedProject"
                :open="showSwimlanesModal"
                @update:open="showSwimlanesModal = $event"
                @lanes-changed="onLanesChanged" />

            <!-- Project phase Guide — v3.98.0 no longer auto-opens on team
                 creation. Kept as an on-demand walkthrough dialog, opened
                 from ProjectCompassPanel's Walkthrough button and from
                 the phase stepper info button. -->
            <ProjectPhaseGuide
                v-if="isAdvancedProject && isTeamAdmin"
                :open="showProjectGuide"
                :phase="project.phase"
                @update:open="showProjectGuide = $event"
                @open-project-settings="onOpenProjectSettingsFromGuide" />

            <!-- Home view — widget grid -->
            <TeamWidgetGrid
                v-show="currentView === 'msgstream'"
                ref="widgetGrid"
                :grid-layout="gridLayout"
                :layout-loaded="layoutLoaded"
                :edit-mode="editMode"
                :is-mobile="isMobile"
                :is-tablet="isTablet"
                :pages-data="pagesData"
                :widget-dynamic-actions="widgetDynamicActions"
                :layout-differs-from-default="layoutDiffersFromDefault"
                @layout-updated="onLayoutUpdated"
                @layout-autofit="onLayoutAutofit"
                @manage-team="openManageTeam"
                @copy-link="copyTeamLink"
                @invite="showInviteModal = true"
                @schedule-meeting="showAddEvent = true"
                @add-event="showAddEvent = true"
                @add-meeting="showAddMeeting = true"
                @add-deck-task="showAddTask = true"
                @add-personal-task="showAddPersonalTask = true"
                @create-page="openCreatePage"
                @delete-page="openDeletePage"
                @pages-loaded="onPagesLoaded"
                @set-view="setView"
                @widget-actions-loaded="onWidgetActionsLoaded"
                @leave-team="onLeaveTeam"
                @set-as-default="setAsDefault"
                @reset-to-default="resetToDefault"
                @propose-decision="openCompose" />

            <!-- Activity feed -->
            <ActivityFeedView v-if="currentView === 'activity'" />

            <!-- Embedded NC app views.
                 v-if  = render when this view is active OR has been preloaded.
                 v-show = only display when it IS the active view.
                 Together: iframes stay alive in the DOM once rendered (instant
                 tab switches), but aren't created until needed or preloaded. -->
            <AppEmbed
                v-if="(preloadedViews.has('talk') || currentView === 'talk') && resources.talk"
                v-show="currentView === 'talk'"
                :url="talkUrl"
                :label="t('teamhub', 'Chat')" />
            <AppEmbed
                v-if="((preloadedViews.has('files') || currentView === 'files') && resources.files) || filesEmbedFileUrl"
                v-show="currentView === 'files'"
                :url="filesUrl"
                :label="t('teamhub', 'Files')" />
            <AppEmbed
                v-if="preloadedViews.has('calendar') || currentView === 'calendar'"
                v-show="currentView === 'calendar'"
                ref="calendarEmbed"
                :url="calendarUrl"
                :label="t('teamhub', 'Calendar')"
                :embed-actions="calendarEmbedActions"
                :embed-selects="calendarEmbedSelects"
                @action="onCalendarEmbedAction"
                @select="onCalendarEmbedSelect" />
            <AppEmbed
                v-if="(preloadedViews.has('deck') || currentView === 'deck') && resources.deck && resources.deck.length > 0"
                v-show="currentView === 'deck'"
                :url="deckUrl"
                :label="t('teamhub', 'Deck')" />

            <!-- Presence tab — rendered when module is enabled globally AND for this team -->
            <TeamPresenceView
                v-if="currentView === 'presence' && presenceModuleEnabled && presenceConfig.presence_enabled"
                :team-id="currentTeamId"
                :hide-reasons="presenceConfig.hide_reasons" />

            <!-- Decisions tab — rendered when module is enabled globally AND for this team -->
            <TeamDecisionsView
                v-if="currentView === 'decisions' && decisionsModuleEnabled && decisionsConfig.decisions_enabled"
                @propose-decision="openCompose"
                @propose-decision-superseding="openDecisionCompose" />

            <!-- Timeline tab — Advanced-mode projects get the Planning-phase
                 swimlane view (Session 3): a native Vue component with Deck
                 stacks as workstream lanes, instead of the classic
                 source-banded Timeline iframe below. -->
            <ProjectSwimlaneView
                v-if="(preloadedViews.has('timeline') || currentView === 'timeline') && currentTeamId && isAdvancedProject"
                v-show="currentView === 'timeline'" />

            <!-- Timeline tab (Basic-mode teams) — visual timeline of Calendar, Deck and
                 Decisions events. Uses the same AppEmbed iframe pattern as
                 Talk/Files/Calendar/Deck, with controls (period nav, view-mode dropdown,
                 source toggles) rendered in the embed bar exactly like Calendar's
                 pattern — the iframe itself holds no control UI, only the rendered
                 timeline canvas.
                 preload=false: the page fetches its own data, no need to warm it up. -->
            <AppEmbed
                v-else-if="(preloadedViews.has('timeline') || currentView === 'timeline') && currentTeamId"
                v-show="currentView === 'timeline'"
                ref="timelineEmbed"
                :url="timelineUrl"
                :label="t('teamhub', 'Timeline')"
                :embed-actions="timelineEmbedActions"
                :embed-selects="timelineEmbedSelects"
                :embed-menu="timelineEmbedMenu"
                @action="onTimelineEmbedAction"
                @select="onTimelineEmbedSelect"
                @menu-toggle="onTimelineEmbedMenuToggle" />

            <!-- Budget tab — Advanced-mode projects only. Per-lane
                 view_min_level filters visible lanes server-side; the tab is
                 registered for every Advanced project (owner can toggle a
                 lane's visibility down to admin-only from Manage Team). -->
            <ProjectBudgetView
                v-if="(preloadedViews.has('budget') || currentView === 'budget') && currentTeamId && isAdvancedProject"
                v-show="currentView === 'budget'"
                @open-project-settings="openManageTeam" />

            <!-- Time investment tab (v3.96.0) — Advanced-mode projects only.
                 timeConfig.can_view_time is precomputed server-side (role
                 floor OR project-member row). -->
            <ProjectTimeView
                v-if="(preloadedViews.has('time') || currentView === 'time') && currentTeamId && isAdvancedProject"
                v-show="currentView === 'time'"
                @open-project-settings="openManageTeam" />

            <!-- External menu_item integrations — preloaded by registry_id -->
            <template v-for="menuItem in externalMenuItems">
                <AppEmbed
                    v-if="(preloadedViews.has('ext-' + menuItem.registry_id) || currentView === 'ext-' + menuItem.registry_id) && menuItem.iframe_url"
                    v-show="currentView === 'ext-' + menuItem.registry_id"
                    :key="'ext-canvas-' + menuItem.registry_id"
                    :url="menuItemUrl(menuItem)"
                    :label="menuItem.title" />
            </template>

            <!-- NC-relative web link tabs — shown in an iframe like built-in apps -->
            <template v-for="tab in ncRelativeLinkTabs">
                <AppEmbed
                    v-if="preloadedViews.has(tab.key) || currentView === tab.key"
                    v-show="currentView === tab.key"
                    :key="'link-canvas-' + tab.key"
                    :url="linkEmbedUrl(tab.url)"
                    :label="tab.label" />
            </template>
        </div>

        <!-- ── Modals ─────────────────────────────────────────────── -->
        <ManageLinksModal v-if="showManageLinks" @close="showManageLinks = false" />

        <!-- ── Multi-resource picker: Deck ──────────────────────────── -->
        <NcDialog
            v-if="showDeckPicker"
            :name="t('teamhub', 'Choose a board')"
            :open="showDeckPicker"
            @update:open="showDeckPicker = false">
            <div class="teamhub-resource-picker">
                <button
                    v-for="board in resources.deck"
                    :key="board.board_id"
                    class="teamhub-resource-picker__item"
                    @click="pickDeckBoard(board)">
                    <span
                        class="teamhub-resource-picker__color"
                        :style="{ background: board.color || 'var(--color-primary)' }"
                        aria-hidden="true" />
                    <span class="teamhub-resource-picker__name">{{ board.name }}</span>
                </button>
            </div>
        </NcDialog>

        <!-- ── Multi-resource picker: Calendar ──────────────────────── -->
        <NcDialog
            v-if="showCalendarPicker"
            :name="t('teamhub', 'Choose a calendar')"
            :open="showCalendarPicker"
            @update:open="showCalendarPicker = false">
            <div class="teamhub-resource-picker">
                <button
                    v-for="cal in resources.calendar"
                    :key="cal.id"
                    class="teamhub-resource-picker__item"
                    @click="pickCalendar(cal)">
                    <span class="teamhub-resource-picker__name">{{ cal.name }}</span>
                </button>
            </div>
        </NcDialog>

        <NcDialog
            v-if="showCreatePage"
            :name="t('teamhub', 'Create page')"
            :open="true"
            @update:open="showCreatePage = false">
            <template #default>
                <p style="margin: 0 0 12px; font-size: 13px; color: var(--color-text-maxcontrast);">
                    {{ t('teamhub', 'The new page will be created inside the team folder in Intravox.') }}
                </p>
                <NcTextField
                    v-model="newPageTitle"
                    :label="t('teamhub', 'Page title')"
                    :placeholder="t('teamhub', 'Enter a title for the new page')"
                    autofocus
                    @keyup.enter="submitCreatePage" />
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="showCreatePage = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="primary" :disabled="!newPageTitle.trim() || creatingPage" @click="submitCreatePage">
                    <template #icon><NcLoadingIcon v-if="creatingPage" :size="20" /></template>
                    {{ t('teamhub', 'Create') }}
                </NcButton>
            </template>
        </NcDialog>

        <NcDialog
            v-if="showDeletePage"
            :name="t('teamhub', 'Delete page')"
            :open="true"
            @update:open="showDeletePage = false">
            <template #default>
                <p style="margin: 0 0 12px; font-size: 13px; color: var(--color-text-maxcontrast);">
                    {{ t('teamhub', 'Select a sub-page to delete. This cannot be undone.') }}
                </p>
                <div class="teamhub-page-delete-list">
                    <p v-if="pagesData.subPages.length === 0" style="font-size:13px; color: var(--color-text-maxcontrast); margin: 0;">
                        {{ t('teamhub', 'No sub-pages to delete. The main team page can only be removed by disabling the Pages app for this team.') }}
                    </p>
                    <label
                        v-for="page in pagesData.subPages"
                        :key="page.uniqueId"
                        class="teamhub-page-delete-option"
                        :class="{ 'teamhub-page-delete-option--selected': deletePageTarget && deletePageTarget.uniqueId === page.uniqueId }">
                        <input v-model="deletePageTarget" type="radio" :value="page" class="teamhub-page-delete-radio" />
                        <FileDocumentOutline :size="16" />
                        <span>{{ page.title }}</span>
                    </label>
                </div>
            </template>
            <template #actions>
                <NcButton variant="tertiary" @click="showDeletePage = false">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton variant="error" :disabled="!deletePageTarget || deletingPage" @click="submitDeletePage">
                    <template #icon><NcLoadingIcon v-if="deletingPage" :size="20" /></template>
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>

        <InviteMemberModal v-if="showInviteModal" :team-id="currentTeamId"
            @close="showInviteModal = false"
            @invited="$store.dispatch('fetchMembers', currentTeamId)" />

        <AddEventModal v-if="showAddEvent"
            :team-id="currentTeamId"
            :calendars="resources.calendar || []"
            @close="showAddEvent = false; $refs.widgetGrid?.refreshCalendar(); $refs.calendarEmbed?.reload()" />

        <SuggestMeetingWizard v-if="showAddMeeting || showSuggestMeeting"
            :team-id="currentTeamId"
            :calendars="resources.calendar || []"
            :resources="resources"
            @created="$refs.widgetGrid?.refreshCalendar(); $refs.calendarEmbed?.reload()"
            @close="showAddMeeting = false; showSuggestMeeting = false" />

        <DeleteEventsModal v-if="showDeleteEvents"
            :team-id="currentTeamId"
            :calendars="resources.calendar || []"
            @close="showDeleteEvents = false"
            @deleted="$refs.widgetGrid?.refreshCalendar(); $refs.calendarEmbed?.reload()" />

        <AddTaskModal v-if="showAddTask"
            :boards="resources.deck || []"
            @close="showAddTask = false"
            @created="$store.dispatch('fetchDeckTasks', resources.deck || [])" />

        <AddPersonalTaskModal v-if="showAddPersonalTask"
            :team-id="currentTeamId"
            @close="showAddPersonalTask = false"
            @created="$store.dispatch('fetchTeamTasks', currentTeamId)" />

        <!-- Shared compose-decision modal — triggered by widget header `+`
             and by TeamDecisionsView's Propose button. Always renders proposals
             as `finalized` (auto-skip the open/discussion phase) since the
             proposer fills the entire proposal in the modal. -->
        <ComposeDecisionModal
            :open="composeDecisionOpen"
            @close="composeDecisionOpen = false"
            @decision-created="onDecisionCreatedFromCompose" />

    </div>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcDialog, NcTextField, NcLoadingIcon } from '@nextcloud/vue'

import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import CalendarToday from 'vue-material-design-icons/CalendarToday.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import Printer from 'vue-material-design-icons/Printer.vue'

import TeamTabBar from './TeamTabBar.vue'
import TeamWidgetGrid from './TeamWidgetGrid.vue'
import ActivityFeedView from './ActivityFeedView.vue'
import ManageLinksModal from './ManageLinksModal.vue'
import InviteMemberModal from './InviteMemberModal.vue'
import AddEventModal from './AddEventModal.vue'
import SuggestMeetingWizard from './SuggestMeetingWizard.vue'
import DeleteEventsModal from './DeleteEventsModal.vue'
import AddTaskModal from './AddTaskModal.vue'
import AddPersonalTaskModal from './AddPersonalTaskModal.vue'
import AppEmbed from './AppEmbed.vue'
import ProjectSwimlaneView from './ProjectSwimlaneView.vue'
import ProjectBudgetView from './ProjectBudgetView.vue'
import ProjectTimeView from './ProjectTimeView.vue'
import TeamPresenceView   from './TeamPresenceView.vue'
import TeamDecisionsView  from './TeamDecisionsView.vue'
import ComposeDecisionModal from './ComposeDecisionModal.vue'
import ProjectPhaseStepper from './ProjectPhaseStepper.vue'
import ProjectPhaseGuide from './ProjectPhaseGuide.vue'
import ProjectCompassPanel from './ProjectCompassPanel.vue'
import ProjectSwimlanesModal from './ProjectSwimlanesModal.vue'
import ClosingArtifactModal from './ClosingArtifactModal.vue'
import ArchivePolicyWarningModal from './ArchivePolicyWarningModal.vue'

function debounce(fn, delay) {
    let timer = null
    return function (...args) {
        clearTimeout(timer)
        timer = setTimeout(() => fn.apply(this, args), delay)
    }
}

// ── Timeline date helpers (module-level — needed in data() before `this` exists) ──
function TeamView_startOfWeek(d) {
    const r = new Date(d)
    r.setHours(0, 0, 0, 0)
    r.setDate(r.getDate() - ((r.getDay() + 6) % 7)) // Monday
    return r
}
function TeamView_startOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1, 0, 0, 0, 0)
}
function TeamView_snapWindow(d, mode) {
    return mode === '1W' ? TeamView_startOfWeek(d) : TeamView_startOfMonth(d)
}
function TeamView_addPeriod(d, mode) {
    const r = new Date(d)
    if (mode === '1W') r.setDate(r.getDate() + 7)
    else if (mode === '1M') r.setMonth(r.getMonth() + 1)
    else if (mode === '3M') r.setMonth(r.getMonth() + 3)
    else r.setMonth(r.getMonth() + 6)
    return r
}
function TeamView_subPeriod(d, mode) {
    const r = new Date(d)
    if (mode === '1W') r.setDate(r.getDate() - 7)
    else if (mode === '1M') r.setMonth(r.getMonth() - 1)
    else if (mode === '3M') r.setMonth(r.getMonth() - 3)
    else r.setMonth(r.getMonth() - 6)
    return r
}

export default {
    name: 'TeamView',

    components: {
        NcButton, NcDialog, NcTextField, NcLoadingIcon,
        FileDocumentOutline, CalendarPlus, CalendarRemove, CalendarClock,
        ChevronLeft, ChevronRight, CalendarToday,
        CalendarIcon, GavelIcon, CardTextIcon, FilterVariant, Printer,
        TeamTabBar, TeamWidgetGrid,
        ActivityFeedView, ManageLinksModal, InviteMemberModal,
        AddEventModal, SuggestMeetingWizard, DeleteEventsModal, AddTaskModal, AddPersonalTaskModal, AppEmbed, ProjectSwimlaneView, ProjectBudgetView, ProjectTimeView,
        TeamPresenceView,
        TeamDecisionsView,
        ComposeDecisionModal,
        ProjectPhaseStepper,
        ProjectPhaseGuide,
        ProjectCompassPanel,
        ProjectSwimlanesModal,
        ClosingArtifactModal,
        ArchivePolicyWarningModal,
    },

    data() {
        return {
            gridLayout: [],
            userDefaultLayout: [],
            orderedTabs: [],
            editMode: false,
            layoutLoaded: false,
            _debouncedSave: null,
            // ── Viewport flags ────────────────────────────────────────
            // isMobile: phone (≤768px any) OR tablet portrait (≤1024px portrait)
            //   → single-canvas layout with icon bar
            // isTablet: landscape ≤1200px AND NOT mobile
            //   → 60/40 split: message stream left, widget column right
            // Neither flag true → full desktop grid layout (unchanged)
            isMobile: false,
            isTablet: false,
            _mobileMql: null,
            _mobileMqlHandler: null,
            _tabletMql: null,
            _tabletMqlHandler: null,
            showManageLinks:     false,
            pagesData:          { teamPage: null, subPages: [], teamhubRoot: null, allPages: [] },
            showCreatePage:     false,
            newPageTitle:       '',
            creatingPage:       false,
            showDeletePage:     false,
            deletingPage:       false,
            deletePageTarget:   null,
            showInviteModal:     false,
            showAddEvent:        false,
            // showSuggestMeeting is the legacy approver-meeting flow
            // (TeamDecisionsView opens the wizard with lockAttendees=true).
            // showAddMeeting is the new widget action (full Add Meeting wizard).
            // Both render the same SuggestMeetingWizard component.
            showSuggestMeeting:  false,
            showAddMeeting:      false,
            showDeleteEvents:    false,
            calendarView:        'dayGridMonth',  // current calendar view mode
            calendarDate:        new Date(),      // current navigation date for the calendar iframe

            // Timeline tab state — mirrors the calendar pattern above. The
            // iframe itself holds no control state; all of it lives here and
            // drives timelineUrl, which the AppEmbed reload() call re-navigates to.
            timelineViewMode:    '1W',             // '1W' | '1M' | '3M' | '6M'
            timelineWindowStart: TeamView_startOfWeek(new Date()),
            timelineSources:     { calendar: true, decisions: true, deck: true, messages: true },
            // Connector overlays — all enabled by default (v3.78.9, per
            // Justin): showing every connector line alongside every source
            // and sub-filter maximizes how many dependencies/relationships
            // are visible on the timeline at once.
            //
            // Decision↔task connector (v3.78.5).
            timelineShowLinks:   true,
            // Deck card-dependency connector (v3.78.8) — NC 34 / Deck 1.18+
            // only. Harmless to default true on older installs: the toggle
            // itself only ever appears in the filter menu when
            // cardDependenciesSupported is true (see timelineEmbedMenu), and
            // with no meta.blockedByCardIds present anywhere there's simply
            // nothing for it to draw.
            timelineShowDepLinks: true,
            // Message↔decision connector (v3.78.9) — arrow from a
            // messageType='decision' post's chip to the decision's
            // "proposed" chip it announced.
            timelineShowMsgLinks: true,
            // Per-source sub-filter selections. All enabled by default
            // (v3.78.9, per Justin) — showing every lifecycle event
            // maximizes how many connector lines/dependencies are visible
            // at once. When both ends of a card's or decision's lifecycle
            // are visible the chips are connected with a thin bar so it
            // reads like a Gantt chart.
            timelineSubFilters: {
                deck:      { created: true, due: true, completed: true },
                decisions: { proposed: true, decided: true },
            },
            showAddTask:         false,
            // Multi-resource picker state (§10.1)
            showDeckPicker:      false,
            showCalendarPicker:  false,
            selectedCalendar:    null,   // { id, name } — set when picker chooses
            showAddPersonalTask: false,
            // Compose-decision modal (Session A) — opened by widget header `+`
            // and by TeamDecisionsView's Propose button. Single instance.
            composeDecisionOpen: false,
            // Project-owner onboarding (v3.90.x) — controls ProjectPhaseGuide visibility.
            showProjectGuide: false,
            // v3.98.2 — Planning-phase workstreams modal (opened from Compass).
            showSwimlanesModal: false,
            // v3.99.0 — Closing-phase modals (opened from Compass).
            showClosingArtifactModal: false,
            showArchivePolicyModal: false,
            widgetDynamicActions: {},
            // Set of view keys whose iframe has been rendered at least once.
            // Once a view is in this set the AppEmbed is kept in the DOM
            // (v-show) rather than destroyed, so tab switches are instant.
            preloadedViews: new Set(),
            // Presence module — per-team config loaded from store (B3/B4)
        }
    },

    computed: {
        ...mapState([
            'currentTeamId', 'currentView', 'resources', 'webLinks', 'filesEmbedFileUrl',
            'members', 'loading', 'intravoxAvailable', 'teamWidgets', 'teamMenuItems',
            'selectedDeckBoard', 'presenceConfig', 'presenceModuleEnabled',
            'decisionsConfig', 'decisionsModuleEnabled',
            'timelineConfig', 'messagesConfig', 'budgetConfig', 'timeConfig', 'project', 'teamType',
        ]),
        ...mapGetters(['currentTeam', 'canManageLinks']),

        /**
         * Project Teams (v3.88.0) — true only for teams created from the Project
         * template in Advanced mode. Gates the phase stepper on the Home view.
         * Basic projects (and non-project teams) never show lifecycle UI.
         */
        isAdvancedProject() {
            return !!this.project?.isProject && this.project?.mode === 'advanced'
        },

        /**
         * Project-owner onboarding (v3.90.x) — same level-≥8 check as
         * TeamWidgetGrid's isTeamAdmin. Gates the phase-guide info button and
         * auto-popup: regular members can't act on "advance a phase" (that's
         * admin-gated server-side too), so showing them the guide would be
         * confusing rather than useful.
         */
        isTeamAdmin() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 8
        },

        /**
         * v3.98.4 — level ≥ 4. Gate for the Project Compass panel — almost
         * every checklist item is admin-only, but Invite works from
         * moderator upward and this component's showInviteModal handler
         * is what the Compass emits into. Keeping the audience at
         * moderator+ instead of admin-only means Invite retains a useful
         * home for people at that level.
         */
        isTeamModerator() {
            if (!this.members?.length) return false
            const uid = getCurrentUser()?.uid
            if (!uid) return false
            const m = this.members.find(m => m.userId === uid)
            return m && m.level >= 4
        },

        /**
         * NC 34 / Deck 1.18+ only — whether deck_dependent_cards exists on
         * this install. Arrives bundled in timelineConfig (loaded once via
         * loadLayout(), well before the Timeline tab is even opened) rather
         * than a separate round-trip. Gates whether the "Deck card
         * dependencies" connector toggle appears in timelineEmbedMenu at
         * all (v3.78.8).
         */
        cardDependenciesSupported() {
            return !!this.timelineConfig?.card_dependencies_supported
        },

        talkUrl() {
            const token = this.resources.talk?.token
            return token ? generateUrl('/call/' + token) : generateUrl('/apps/spreed')
        },
        filesUrl() {
            // A file widget may have requested a specific file be embedded
            // (opened in-app rather than a new tab). When set, that wins; it is
            // cleared by SET_VIEW when the user leaves the files view.
            if (this.filesEmbedFileUrl) {
                return this.filesEmbedFileUrl
            }
            const path = this.resources.files?.path || '/'
            return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(path)
        },
        calendarUrl() {
            // NC Calendar app requires the full path including view and date suffix.
            // calendarView is user-selectable from the embed bar dropdown.
            // calendarDate drives prev/next/today navigation.
            const cal = this.selectedCalendar || (this.resources.calendar && this.resources.calendar[0])
            const dateStr = this.calendarDateIso
            if (cal?.public_token) {
                return generateUrl('/apps/calendar/p/' + cal.public_token + '/' + this.calendarView + '/' + dateStr)
            }
            return generateUrl('/apps/calendar/' + this.calendarView + '/' + dateStr)
        },

        /** ISO date string (YYYY-MM-DD) for the NC Calendar URL. */
        calendarDateIso() {
            const d = this.calendarDate
            const pad = n => String(n).padStart(2, '0')
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
        },

        /**
         * Human-readable label shown in the embed bar between prev/next buttons.
         * Format varies by view:
         *   Month / list-month  → "June 2026"
         *   Week / list-week    → "2–8 Jun 2026"
         *   Day / list-day      → "Mon 2 Jun 2026"
         */
        calendarDateLabel() {
            const d = this.calendarDate
            const view = this.calendarView
            const locale = document.documentElement.lang || 'en'
            if (view === 'dayGridMonth' || view === 'listMonth') {
                return d.toLocaleDateString(locale, { month: 'long', year: 'numeric' })
            }
            if (view === 'timeGridDay' || view === 'listDay') {
                return d.toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
            }
            // Week views — show Mon–Sun range
            const startOfWeek = new Date(d)
            const dow = d.getDay() // 0=Sun
            const diff = (dow === 0 ? -6 : 1 - dow) // shift to Monday
            startOfWeek.setDate(d.getDate() + diff)
            const endOfWeek = new Date(startOfWeek)
            endOfWeek.setDate(startOfWeek.getDate() + 6)
            const startFmt = startOfWeek.toLocaleDateString(locale, { day: 'numeric', month: 'short' })
            const endFmt   = endOfWeek.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' })
            return `${startFmt} – ${endFmt}`
        },

        /**
         * Action buttons injected into the calendar AppEmbed bar.
         * Prev / date label / next / Today, then Add event, Delete events, optionally Suggest.
         */
        calendarEmbedActions() {
            // Each action provides:
            //   label      — full descriptive name; used for tooltip + aria-label
            //   shortLabel — one-verb visible label under the icon (3.81.10).
            //                Defaults to label when not set (Previous/Next/Today —
            //                already single words).
            return [
                {
                    id:    'cal-prev',
                    // TRANSLATORS: button to navigate to the previous month/week/day in the calendar embed
                    label: t('teamhub', 'Previous'),
                    icon:  ChevronLeft,
                },
                {
                    id:      'cal-date-label',
                    label:   this.calendarDateLabel,
                    icon:    null,
                    isLabel: true,
                },
                {
                    id:    'cal-next',
                    // TRANSLATORS: button to navigate to the next month/week/day in the calendar embed
                    label: t('teamhub', 'Next'),
                    icon:  ChevronRight,
                },
                {
                    id:    'cal-today',
                    // TRANSLATORS: button to jump back to today in the calendar embed
                    label: t('teamhub', 'Today'),
                    icon:  CalendarToday,
                },
                {
                    id:         'add-event',
                    // TRANSLATORS: tooltip / accessible name for the add-event button in the calendar toolbar
                    label:      t('teamhub', 'Add event'),
                    // TRANSLATORS: short visible label under the add-event icon; single verb (full label is on hover/screen-reader)
                    shortLabel: t('teamhub', 'Add'),
                    icon:       CalendarPlus,
                },
                {
                    id:         'delete-events',
                    // TRANSLATORS: tooltip / accessible name for the delete-events button in the calendar toolbar
                    label:      t('teamhub', 'Delete events'),
                    // TRANSLATORS: short visible label under the delete-events icon; single verb (full label is on hover/screen-reader)
                    shortLabel: t('teamhub', 'Remove'),
                    icon:       CalendarRemove,
                },
                ...(this.presenceModuleEnabled && this.presenceConfig.presence_enabled
                    ? [{
                        id:         'suggest-meeting',
                        // TRANSLATORS: tooltip / accessible name for the suggest-meeting-times button in the calendar toolbar
                        label:      t('teamhub', 'Suggest meeting times'),
                        // TRANSLATORS: short visible label under the suggest-meeting-times icon; single verb (full label is on hover/screen-reader)
                        shortLabel: t('teamhub', 'Suggest'),
                        icon:       CalendarClock,
                    }]
                    : []),
            ]
        },

        /**
         * Select dropdowns injected into the calendar AppEmbed bar.
         * Allows the user to switch between calendar views.
         */
        calendarEmbedSelects() {
            return [
                {
                    id:    'calendar-view',
                    // TRANSLATORS: aria-label for the calendar view selector dropdown in the embed bar
                    label: t('teamhub', 'Calendar view'),
                    value: this.calendarView,
                    options: [
                        { value: 'dayGridMonth', label: t('teamhub', 'Month') },
                        { value: 'timeGridWeek', label: t('teamhub', 'Week') },
                        { value: 'timeGridDay',  label: t('teamhub', 'Day') },
                        { value: 'listMonth',    label: t('teamhub', 'List (month)') },
                        { value: 'listWeek',     label: t('teamhub', 'List (week)') },
                        { value: 'listDay',      label: t('teamhub', 'List (day)') },
                    ],
                },
            ]
        },
        deckUrl() {
            const board = this.selectedDeckBoard || (this.resources.deck && this.resources.deck[0])
            return generateUrl('/apps/deck') + (board ? '/#/board/' + board.board_id : '/')
        },

        /**
         * URL of the standalone timeline iframe page served by PageController::timeline().
         * Encodes the current view mode, window start, and active sources as query
         * params so the page (and its external script, see vite entry 'timeline')
         * can render the correct window without needing any control UI of its own —
         * all controls live in the AppEmbed bar, exactly like the Calendar tab.
         */
        timelineUrl() {
            if (!this.currentTeamId) return ''
            const fromTs = Math.floor(this.timelineWindowStart.getTime() / 1000)
            const activeSources = Object.keys(this.timelineSources).filter(k => this.timelineSources[k])

            // Compute active sub-filters as a flat comma list of "<source>:<type>"
            // pairs. The iframe parses this into the same nested boolean
            // structure we have here and filters chips client-side.
            const subPairs = []
            for (const [src, types] of Object.entries(this.timelineSubFilters)) {
                for (const [type, on] of Object.entries(types)) {
                    if (on) subPairs.push(src + ':' + type)
                }
            }

            const params = new URLSearchParams({
                view: this.timelineViewMode,
                from: String(fromTs),
                sources: activeSources.join(','),
                sub: subPairs.join(','),
                links: this.timelineShowLinks ? '1' : '0',
                depLinks: this.timelineShowDepLinks ? '1' : '0',
                msgLinks: this.timelineShowMsgLinks ? '1' : '0',
            })
            return generateUrl('/apps/teamhub/timeline/' + this.currentTeamId) + '?' + params.toString()
        },

        /** Human-readable period label, mirroring calendarDateLabel's pattern. */
        timelinePeriodLabel() {
            const start = this.timelineWindowStart
            const end   = new Date(TeamView_addPeriod(start, this.timelineViewMode).getTime() - 1)
            const locale = document.documentElement.lang || 'en'
            if (this.timelineViewMode === '1W') {
                const startFmt = start.toLocaleDateString(locale, { day: 'numeric', month: 'short' })
                const endFmt   = end.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' })
                return `${startFmt} – ${endFmt}`
            }
            if (this.timelineViewMode === '1M') {
                return start.toLocaleDateString(locale, { month: 'long', year: 'numeric' })
            }
            const startFmt = start.toLocaleDateString(locale, { month: 'short', year: 'numeric' })
            const endFmt   = end.toLocaleDateString(locale, { month: 'short', year: 'numeric' })
            return `${startFmt} – ${endFmt}`
        },

        /** Prev / date label / next / Today — identical pattern to calendarEmbedActions. */
        timelineEmbedActions() {
            return [
                {
                    id:    'tl-prev',
                    // TRANSLATORS: button to navigate to the previous period in the timeline embed
                    label: t('teamhub', 'Previous'),
                    icon:  ChevronLeft,
                },
                {
                    id:      'tl-date-label',
                    label:   this.timelinePeriodLabel,
                    icon:    null,
                    isLabel: true,
                },
                {
                    id:    'tl-next',
                    // TRANSLATORS: button to navigate to the next period in the timeline embed
                    label: t('teamhub', 'Next'),
                    icon:  ChevronRight,
                },
                {
                    id:    'tl-today',
                    // TRANSLATORS: button to jump back to today in the timeline embed
                    label: t('teamhub', 'Today'),
                    icon:  CalendarToday,
                },
                {
                    id:    'tl-print',
                    // TRANSLATORS: button to print the timeline view only (without surrounding NC/TeamHub chrome)
                    label: t('teamhub', 'Print timeline'),
                    icon:  Printer,
                },
            ]
        },

        /** View-mode dropdown — same UI pattern as calendarEmbedSelects. */
        timelineEmbedSelects() {
            return [
                {
                    id:    'timeline-view',
                    // TRANSLATORS: aria-label for the timeline period-length selector dropdown in the embed bar
                    label: t('teamhub', 'Timeline period'),
                    value: this.timelineViewMode,
                    options: [
                        { value: '1W', label: t('teamhub', '1 week') },
                        { value: '1M', label: t('teamhub', '1 month') },
                        { value: '3M', label: t('teamhub', '3 months') },
                        { value: '6M', label: t('teamhub', '6 months') },
                    ],
                },
            ]
        },

        /**
         * Filter dropdown — single button in the embed bar that opens an
         * NcActions popover. Two-level structure: top-level source toggles,
         * each followed by sub-filter checkboxes for that source's event
         * types. Sub-filters are visually grouped under an NcActionCaption
         * showing the source name. Disabled when the parent source is off.
         *
         * Default sub-filter state (set in data()) emits only the "resolved"
         * events: Deck due+completed, Decisions decided. Enabling created or
         * proposed adds a second chip per item and the timeline draws a
         * connecting bar between the two for Gantt-style readability.
         *
         * Each checkbox toggle emits 'menu-toggle' with the item id;
         * onTimelineEmbedMenuToggle dispatches based on prefix
         * (deck-*, decisions-*, or bare source name).
         */
        timelineEmbedMenu() {
            const sub = this.timelineSubFilters
            const items = [
                // Deck + its sub-filters
                { id: 'deck', label: t('teamhub', 'Deck'), active: this.timelineSources.deck },
                {
                    id: 'cap-deck', isCaption: true,
                    // TRANSLATORS: caption above the sub-filter checkboxes for Deck in the timeline filter menu
                    label: t('teamhub', '  Deck events'),
                },
                {
                    id: 'deck-created',
                    // TRANSLATORS: sub-filter — show "created" event chips for Deck cards on the timeline
                    label: t('teamhub', '  Created date'),
                    active: sub.deck.created,
                    disabled: !this.timelineSources.deck,
                },
                {
                    id: 'deck-due',
                    // TRANSLATORS: sub-filter — show "due" event chips for Deck cards on the timeline
                    label: t('teamhub', '  Due date'),
                    active: sub.deck.due,
                    disabled: !this.timelineSources.deck,
                },
                {
                    id: 'deck-completed',
                    // TRANSLATORS: sub-filter — show "completed" event chips for Deck cards on the timeline
                    label: t('teamhub', '  Completed date'),
                    active: sub.deck.completed,
                    disabled: !this.timelineSources.deck,
                },

                // Decisions + its sub-filters
                { id: 'decisions', label: t('teamhub', 'Decisions'), active: this.timelineSources.decisions },
                {
                    id: 'cap-decisions', isCaption: true,
                    // TRANSLATORS: caption above the sub-filter checkboxes for Decisions in the timeline filter menu
                    label: t('teamhub', '  Decisions events'),
                },
                {
                    id: 'decisions-proposed',
                    // TRANSLATORS: sub-filter — show "proposed" event chips for decisions on the timeline
                    label: t('teamhub', '  Proposed date'),
                    active: sub.decisions.proposed,
                    disabled: !this.timelineSources.decisions,
                },
                {
                    id: 'decisions-decided',
                    // TRANSLATORS: sub-filter — show "decided/approved" event chips for decisions on the timeline
                    label: t('teamhub', '  Approved date'),
                    active: sub.decisions.decided,
                    disabled: !this.timelineSources.decisions,
                },

                // Top-level source toggles — Messages and Calendar have no
                // sub-filters of their own (every post / every event always
                // shows), so they're bare checkboxes.
                { id: 'messages',  label: t('teamhub', 'Messages'),  active: this.timelineSources.messages  },
                { id: 'calendar',  label: t('teamhub', 'Calendar'),  active: this.timelineSources.calendar  },

                // Connector overlays — all on by default (v3.78.9). None of
                // these filter which chips appear; each draws a line between
                // two related chips that are already visible.
                {
                    id: 'cap-links', isCaption: true,
                    // TRANSLATORS: caption above the connector-overlay checkboxes in the timeline filter menu
                    label: t('teamhub', '  Connections'),
                },
                {
                    id: 'links',
                    // TRANSLATORS: toggle — draws an arrow between a decision and any Deck cards linked to it as tasks
                    label: t('teamhub', '  Decision ↔ task links'),
                    active: this.timelineShowLinks,
                },
                {
                    id: 'msgLinks',
                    // TRANSLATORS: toggle — draws an arrow from a decision-proposal stream post to the decision it announced
                    label: t('teamhub', '  Message ↔ decision links'),
                    active: this.timelineShowMsgLinks,
                },
                // Deck card-dependency connector overlay (v3.78.8) — NC 34 /
                // Deck 1.18+ only. Entirely absent from the menu (not just
                // disabled) on installs where Deck doesn't have this field,
                // so there's never a dead toggle to wonder about.
                ...(this.cardDependenciesSupported ? [{
                    id: 'depLinks',
                    // TRANSLATORS: toggle — draws an arrow from a Deck card to other cards it depends on (a Deck 1.18+ feature)
                    label: t('teamhub', '  Deck card dependencies'),
                    active: this.timelineShowDepLinks,
                }] : []),
            ]
            return {
                id:    'timeline-filter',
                // TRANSLATORS: button label in the timeline embed bar — opens a dropdown of source filter checkboxes
                label: t('teamhub', 'Filter'),
                icon:  FilterVariant,
                items,
            }
        },
        externalMenuItems() {
            return (this.teamMenuItems || []).filter(item => !item.is_builtin)
        },

        /** Link tabs whose URL is NC-relative — these open in an iframe. */
        ncRelativeLinkTabs() {
            return (this.orderedTabs || []).filter(t => t.key.startsWith('link-') && t.isNcRelative)
        },

        /**
         * True when the current team's layout differs from the user's personal default.
         * Compares sizes and column placement per widget — ignores y (determined by snap).
         * Controls visibility of "Set as default" / "Reset to default" buttons.
         */
        layoutDiffersFromDefault() {
            if (!this.userDefaultLayout || !this.userDefaultLayout.length || !this.gridLayout.length) {
                return false
            }

            const normalize = layout => {
                const map = {}
                layout.forEach(item => {
                    map[item.i] = {
                        x: item.x,
                        // y is included: vertical reordering is a genuine user preference
                        // (snap only closes gaps from inactive widgets; active order is
                        // intentional). Previously omitted, which hid the buttons when
                        // users changed only widget order — the most common edit.
                        y: item.y,
                        w: item.w,
                        h: item.h,
                        collapsed: !!item.collapsed,
                    }
                })
                return map
            }

            const current = normalize(this.gridLayout)
            const def     = normalize(this.userDefaultLayout)

            for (const id of Object.keys(def)) {
                const cur = current[id]
                if (!cur) continue // widget inactive in this team — skip
                const d = def[id]
                if (cur.x !== d.x || cur.y !== d.y || cur.w !== d.w || cur.h !== d.h || cur.collapsed !== d.collapsed) {
                    return true
                }
            }
            return false
        },
    },

    watch: {
        currentTeamId(newId) {
            if (newId) {
                this.gridLayout = []
                this.orderedTabs = []
                this.layoutLoaded = false
                this.editMode = false
                this.preloadedViews = new Set()
                this.SET_PRESENCE_CONFIG({ presence_enabled: false, hide_reasons: false })
                this.SET_DECISIONS_CONFIG({ decisions_enabled: false })
                // loadLayout now includes presenceConfig in its response,
                // so a single request is all we need. No race conditions.
                this.loadLayout(newId)
            }
        },
        // Project-owner onboarding (v3.90.x) — members (and thus isTeamAdmin)
        // resolve independently of loadLayout's project fact; whichever finishes
        // last is the one that actually triggers the auto-popup (idempotent).
        isTeamAdmin() {
            this.maybeShowProjectGuideForNewTeam()
        },
        // When ManageTeamView updates presenceConfig in the store (e.g. enabling
        // the presence tab toggle), rebuild the tab list immediately so the
        // Presence tab appears/disappears without a page reload.
        presenceConfig: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        // Same pattern for decisionsConfig — rebuild tabs when per-team toggle changes.
        decisionsConfig: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        timelineConfig: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        // Budget tab appears once (a) the project fact confirms Advanced mode
        // AND (b) budget_enabled is true. Both arrive with the layout bundle,
        // but project may commit slightly before budgetConfig, and the tab
        // list is initially built while `project` still says isProject=false.
        // Rebuild on either change so the tab shows up as soon as the flag
        // that finally unlocks it flips.
        budgetConfig: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        // Same rebuild-on-flip pattern as budgetConfig — the Time tab appears
        // once (a) project fact confirms Advanced mode AND (b) time_enabled
        // is true AND (c) can_view_time is true. All arrive with the layout
        // bundle but may commit slightly out of order.
        timeConfig: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        project: {
            deep: true,
            handler() {
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            },
        },
        webLinks() { this.syncLinkTabs() },
        externalMenuItems() { this.syncExtTabs() },

        /**
         * Re-apply snap when resources change (widget enabled/disabled).
         * Skipped during edit mode to avoid disrupting drag interactions.
         */
        resources: {
            deep: true,
            handler() {
                if (this.layoutLoaded && !this.editMode) {
                    this.applySnap()
                }
            },
        },

        /**
         * When the user exits edit mode (true → false), apply snap to close any
         * gaps left from inactive widgets, then immediately save the resulting
         * layout so the server has the correct positions. Without this, a user
         * who drags quickly and exits edit mode before the 1.2 s debounce fires
         * would not have their final arrangement persisted.
         */
        editMode(newVal, oldVal) {
            if (!newVal && oldVal) {
                // Exiting edit mode — snap first, then save the snapped layout.
                this.applySnap()
                this.$nextTick(() => {
                    this.saveLayout()
                })
            }
        },
    },

    created() {
        this._debouncedSave = debounce(this.saveLayout, 1200)
    },

    async mounted() {
        if (typeof window !== 'undefined' && window.matchMedia) {
            // Mobile: phone portrait/landscape (≤768px) OR tablet portrait (≤1024px portrait)
            const mobileQuery = '(max-width: 768px), (max-width: 1024px) and (orientation: portrait)'
            this._mobileMql = window.matchMedia(mobileQuery)
            this.isMobile = this._mobileMql.matches
            this._mobileMqlHandler = (e) => {
                this.isMobile = e.matches
                // isTablet is the middle zone — recalculate when mobile changes
                this.isTablet = !e.matches && this._tabletMql?.matches
            }
            if (typeof this._mobileMql.addEventListener === 'function') {
                this._mobileMql.addEventListener('change', this._mobileMqlHandler)
            } else if (typeof this._mobileMql.addListener === 'function') {
                this._mobileMql.addListener(this._mobileMqlHandler)
            }

            // Tablet landscape: ≤1200px landscape AND not already mobile
            const tabletQuery = '(max-width: 1200px) and (orientation: landscape)'
            this._tabletMql = window.matchMedia(tabletQuery)
            this.isTablet = !this.isMobile && this._tabletMql.matches
            this._tabletMqlHandler = (e) => {
                this.isTablet = !this.isMobile && e.matches
            }
            if (typeof this._tabletMql.addEventListener === 'function') {
                this._tabletMql.addEventListener('change', this._tabletMqlHandler)
            } else if (typeof this._tabletMql.addListener === 'function') {
                this._tabletMql.addListener(this._tabletMqlHandler)
            }
        }

        await this.$store.dispatch('checkIntravox')
        if (this.currentTeamId) {
            this.loadLayout(this.currentTeamId)
        }
        const builtinViews = ['talk', 'files', 'calendar', 'deck', 'timeline']
        builtinViews.forEach((view, i) => {
            setTimeout(() => {
                if (!this.preloadedViews.has(view)) {
                    const next = new Set(this.preloadedViews)
                    next.add(view)
                    this.preloadedViews = next
                }
            }, 1500 + i * 800)
        })

        // Timeline crowding-badge navigation (v3.78.4) — the timeline.php
        // iframe has no navigation state of its own (view mode + window
        // start live here, in timelineViewMode/timelineWindowStart, exactly
        // like the Calendar tab). When a count-badge inside the iframe is
        // clicked, it can't just navigate itself; it posts a message up to
        // this window instead, and we react by switching the view here —
        // which then flows back down through the timelineUrl computed
        // property and reloads the iframe at the new view/window.
        this._onTimelineMessage = (event) => {
            if (event.origin !== window.location.origin) return
            const data = event.data
            if (!data || data.app !== 'teamhub' || data.type !== 'timeline-navigate') return
            if (typeof data.from !== 'number') return
            this.timelineViewMode = '1W'
            this.timelineWindowStart = TeamView_snapWindow(new Date(data.from * 1000), '1W')
        }
        window.addEventListener('message', this._onTimelineMessage)
    },

    beforeDestroy() {
        for (const key of ['_mobileMql', '_tabletMql']) {
            const mql = this[key]
            const handler = this[key.replace('Mql', 'MqlHandler')]
            if (mql && handler) {
                if (typeof mql.removeEventListener === 'function') {
                    mql.removeEventListener('change', handler)
                } else if (typeof mql.removeListener === 'function') {
                    mql.removeListener(handler)
                }
            }
            this[key] = null
            this[key.replace('Mql', 'MqlHandler')] = null
        }
        if (this._onTimelineMessage) {
            window.removeEventListener('message', this._onTimelineMessage)
            this._onTimelineMessage = null
        }
    },

    methods: {
        t,
        ...mapActions(['selectTeam']),
        ...mapMutations(['SET_VIEW', 'SET_PRESENCE_CONFIG', 'SET_PRESENCE_MODULE_ENABLED', 'SET_DECISIONS_CONFIG', 'SET_DECISIONS_MODULE_ENABLED', 'SET_DECISIONS_TARGET', 'SET_TIMELINE_CONFIG', 'SET_MESSAGES_CONFIG', 'SET_TEAM_TYPE', 'SET_BUDGET_CONFIG', 'SET_TIME_CONFIG', 'SET_PROJECT', 'SET_PROJECT_TAB_FOCUS']),

        setView(view) { this.SET_VIEW(view) },
        toggleEditMode() { this.editMode = !this.editMode },

        /**
         * Navigate to the message stream and pre-set the compose form to
         * open in decision mode. supersedesId is the decision id being
         * superseded (null for a fresh proposal).
         *
         * The compose form reads window.__teamhubDecisionCompose on mount /
         * next stream activation and pre-fills accordingly, then clears it.
         */
        openDecisionCompose(supersedesId) {
            window.__teamhubDecisionCompose = { supersedesId: supersedesId || null }
            this.SET_VIEW('msgstream')
        },

        // Session A — open the focused compose modal (no supersedes — for
        // supersedes the legacy handshake to msgstream remains, since the
        // form needs the prior decision context loaded into the inline form).
        openCompose() {
            this.composeDecisionOpen = true
        },

        // Fired by ComposeDecisionModal after a successful proposal.
        // The payload is the new message object with .decision embedded.
        // We navigate to the Decisions tab and select the new decision
        // so the user lands on its detail view immediately.
        async onDecisionCreatedFromCompose(message) {
            const decision = message?.decision || null
            this.composeDecisionOpen = false
            // Switch to the Decisions view.
            this.SET_VIEW('decisions')
            // Hand the messageId off via the store mutation so TeamDecisionsView's
            // watcher picks it up and scrolls/selects on next render.
            if (decision && decision.messageId) {
                this.$nextTick(() => {
                    this.SET_DECISIONS_TARGET(decision.messageId)
                })
            }
        },

        // ── Layout load / save ──────────────────────────────────────

        async loadLayout(teamId) {
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/layout`))
                this.gridLayout        = Array.isArray(data.layout)      ? data.layout      : []
                this.userDefaultLayout = Array.isArray(data.userDefault) ? data.userDefault : []
                // Presence module flag and per-team config both arrive with layout — no race.
                if (typeof data.presenceModuleEnabled === 'boolean') {
                    this.SET_PRESENCE_MODULE_ENABLED(data.presenceModuleEnabled)
                }
                if (data.presenceConfig) {
                    this.SET_PRESENCE_CONFIG(data.presenceConfig)
                }
                // Decisions module flag — default off until getTeam confirms it's on.
                if (typeof data.decisionsModuleEnabled === 'boolean') {
                    this.SET_DECISIONS_MODULE_ENABLED(data.decisionsModuleEnabled)
                }
                if (data.decisionsConfig) {
                    this.SET_DECISIONS_CONFIG(data.decisionsConfig)
                }
                if (data.timelineConfig) {
                    this.SET_TIMELINE_CONFIG(data.timelineConfig)
                }
                // Messages integration (v3.104.1) — per-team toggle rides along
                // with the layout, same pattern as timelineConfig.
                if (data.messagesConfig) {
                    this.SET_MESSAGES_CONFIG(data.messagesConfig)
                }
                // Team template label (v4.0.2) — always emitted (null for
                // legacy teams). Reset the store so switching from a labelled
                // team to a legacy one clears the badge.
                this.SET_TEAM_TYPE(data.teamType ?? null)
                // Budget integration (v3.92.0) — per-team toggle rides along
                // with the layout, same pattern as timelineConfig.
                if (data.budgetConfig) {
                    this.SET_BUDGET_CONFIG(data.budgetConfig)
                }
                // Time investment integration (v3.96.0) — same pattern.
                if (data.timeConfig) {
                    this.SET_TIME_CONFIG(data.timeConfig)
                }
                // Project Teams (v3.88.0) — project fact rides along with the layout.
                if (data.project) {
                    this.SET_PROJECT(data.project)
                }
                // Project-owner onboarding (v3.90.x) — project is ready now; members
                // may or may not be (fetchMembers resolves independently via the
                // store's selectTeam action) — the isTeamAdmin watcher below covers
                // that ordering. Idempotent, so calling from both places is safe.
                this.maybeShowProjectGuideForNewTeam()
                this.buildOrderedTabs(Array.isArray(data.tabOrder) ? data.tabOrder : [])
                this.layoutLoaded = true
                this.applySnap()
            } catch (err) {
                console.warn('[TeamHub][TeamView] loadLayout: failed', err?.message)
                this.gridLayout = []
                this.buildOrderedTabs([])
                this.layoutLoaded = true
            }
        },

        async saveLayout() {
            if (!this.currentTeamId || !this.layoutLoaded) return
            const tabOrder = this.orderedTabs.map(t => t.key)
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/layout`),
                    { layout: this.gridLayout, tabOrder },
                )
            } catch (err) {
                console.warn('[TeamHub][TeamView] saveLayout: failed', err?.response?.status, err?.message)
            }
        },

        onLayoutUpdated(newLayout) {
            this.gridLayout = newLayout
            if (this.editMode && this.layoutLoaded) this._debouncedSave()
        },

        /**
         * v4.0.8 — auto-fit pass finished growing widgets to fit content.
         * Persist immediately, regardless of editMode, so the flag doesn't
         * survive across loads and the fitted heights become the user's saved
         * layout from now on.
         */
        onLayoutAutofit(newLayout) {
            this.gridLayout = newLayout
            if (this.layoutLoaded) this.saveLayout()
        },

        onTabReorder() {
            if (this.layoutLoaded) this._debouncedSave()
        },

        // ── Snap / reflow ───────────────────────────────────────────

        /**
         * Returns the Set of widget IDs that are currently active
         * (i.e., their v-if condition in TeamWidgetGrid would be true).
         */
        getActiveWidgetIds() {
            const active = new Set()
            // Always-active widgets.
            active.add('msgstream')
            active.add('widget-teaminfo')
            active.add('widget-members')
            active.add('widget-activity')
            // Resource-gated widgets.
            if (this.resources && this.resources.calendar && this.resources.calendar.length > 0) active.add('widget-calendar')
            // Tasks widget shows for Deck OR when Tasks app + calendar are both active.
            if (this.resources && ((this.resources.deck && this.resources.deck.length > 0) || (this.resources.tasks && this.resources.calendar && this.resources.calendar.length > 0))) {
                active.add('widget-deck')
            }
            if (this.resources && this.resources.intravox) active.add('widget-pages')
            // Files widget — active whenever the team has a files resource.
            // Mirrors v-if="resources.files && ..." in TeamWidgetGrid.
            if (this.resources && this.resources.files) active.add('widget-files-center')
            // Decisions widget — active when the module is enabled globally AND for this team.
            // Mirrors showDecisionsWidget computed in TeamWidgetGrid.
            if (this.decisionsModuleEnabled && this.decisionsConfig && this.decisionsConfig.decisions_enabled) {
                active.add('widget-decisions')
            }
            // v3.97.3 — Project Health widget. Same gate as
            // TeamWidgetGrid.showProjectHealthWidget. Without this entry,
            // applySnap() treats the widget as inactive and parks it at
            // y=9999 (off-screen), which is what Justin was seeing after
            // 3.97.0–.2. Missing this method was the root cause, not the
            // DEFAULT_LAYOUT position or the merge logic.
            const phase = this.project && this.project.phase
            if (this.project && this.project.isProject
                && this.project.mode === 'advanced'
                && (phase === 'planning' || phase === 'execution')
                && this.budgetConfig && this.budgetConfig.can_view_budget
                && this.timeConfig && this.timeConfig.can_view_time) {
                active.add('widget-project-health')
            }
            // Dynamic integration widgets.
            ;(this.teamWidgets || []).forEach(w => active.add('widget-int-' + w.registry_id))
            return active
        },

        /**
         * Snap all widgets upward within their column to close gaps left by
         * inactive (hidden) widgets.
         *
         * Strategy:
         *  - Group widgets by their x position (each unique x = one column).
         *  - Within each column, sort active widgets by their current y.
         *  - Repack from y=0 with no gaps between active widgets.
         *  - Park inactive widgets at y=9999 so they don't take up space.
         *    (They are already hidden by v-if in TeamWidgetGrid.)
         *
         * This handles any layout — single column, two column, user-rearranged.
         * Applied on load and when resources change; never during edit mode.
         */
        applySnap() {
            if (!this.layoutLoaded || !this.gridLayout.length) return

            const activeIds = this.getActiveWidgetIds()
            const PARK_Y = 9999

            // Build a map of x → [items in that column].
            const columns = {}
            for (const item of this.gridLayout) {
                const col = item.x
                if (!columns[col]) columns[col] = []
                columns[col].push(item)
            }

            const snapped = []
            for (const col of Object.keys(columns)) {
                const items = columns[col]

                const active   = items.filter(item => activeIds.has(item.i))
                const inactive = items.filter(item => !activeIds.has(item.i))

                // Sort active by current y to preserve user-defined ordering.
                active.sort((a, b) => a.y - b.y)

                let nextY = 0
                for (const item of active) {
                    snapped.push({ ...item, y: nextY })
                    // A collapsed widget occupies h=1 in the grid.
                    nextY += item.collapsed ? 1 : item.h
                }

                // Park inactive items — v-if hides them but they must not occupy space.
                for (const item of inactive) {
                    snapped.push({ ...item, y: PARK_Y })
                }
            }

            this.gridLayout = snapped
        },

        // ── Default layout actions ──────────────────────────────────

        /**
         * Save the current layout as the user's personal default.
         * Called from TeamWidgetGrid's "Set as default" button.
         */
        async setAsDefault() {
            try {
                const tabOrder = this.orderedTabs.map(t => t.key)
                await axios.put(
                    generateUrl('/apps/teamhub/api/v1/layout/default'),
                    { layout: this.gridLayout, tabOrder },
                )
                // Update local reference so layoutDiffersFromDefault recomputes to false.
                this.userDefaultLayout = this.gridLayout.map(item => ({ ...item }))
                showSuccess(t('teamhub', 'Default layout saved'))
            } catch (err) {
                showError(t('teamhub', 'Failed to save default layout'))
            }
        },

        /**
         * Reset the current team's layout to the user's personal default.
         * Applies snap, then immediately saves the team layout.
         * Called from TeamWidgetGrid's "Reset to default" button.
         */
        async resetToDefault() {
            if (!this.userDefaultLayout || !this.userDefaultLayout.length) return

            // Copy default into current layout, then snap for this team's active widgets.
            this.gridLayout = this.userDefaultLayout.map(item => ({ ...item }))
            this.applySnap()

            // Immediately persist the reset so the debounce doesn't race.
            try {
                const tabOrder = this.orderedTabs.map(t => t.key)
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/layout`),
                    { layout: this.gridLayout, tabOrder },
                )
            } catch (err) {
                showError(t('teamhub', 'Failed to reset layout'))
            }
        },

        // ── Tab management ──────────────────────────────────────────

        buildOrderedTabs(savedOrder) {
            const all = this.buildAllTabDescriptors()
            const allMap = Object.fromEntries(all.map(t => [t.key, t]))
            let ordered = []
            if (savedOrder.length > 0) {
                savedOrder.forEach(key => { if (allMap[key]) ordered.push(allMap[key]) })
                all.forEach(tab => { if (!ordered.find(t => t.key === tab.key)) ordered.push(tab) })
            } else {
                ordered = all
            }
            this.orderedTabs = ordered
        },

        buildAllTabDescriptors() {
            const tabs = []
            ;[
                { key: 'talk',     label: t('teamhub', 'Chat'),     icon: 'Chat' },
                { key: 'files',    label: t('teamhub', 'Files'),    icon: 'Folder' },
                { key: 'calendar', label: t('teamhub', 'Calendar'), icon: 'Calendar' },
                { key: 'deck',     label: t('teamhub', 'Deck'),     icon: 'CardText' },
            ].forEach(b => tabs.push(b))
            // Presence tab — only when the NC admin has enabled the module
            // AND the team admin has enabled it for this specific team.
            if (this.presenceModuleEnabled && this.presenceConfig && this.presenceConfig.presence_enabled) {
                tabs.push({ key: 'presence', label: t('teamhub', 'Presence'), icon: 'OfficeBuilding' })
            }
            // Decisions tab — same double-gate pattern.
            if (this.decisionsModuleEnabled && this.decisionsConfig && this.decisionsConfig.decisions_enabled) {
                tabs.push({ key: 'decisions', label: t('teamhub', 'Decisions'), icon: 'Gavel' })
            }
            // Timeline tab — per-team toggle (managed in Manage Team →
            // Integrations → Internal). Default is on; admins can disable it
            // for teams that don't need a timeline view. Empty state inside
            // the iframe handles the no-data case when enabled but no source
            // has events yet.
            if (this.timelineConfig && this.timelineConfig.timeline_enabled !== false) {
                tabs.push({ key: 'timeline', label: t('teamhub', 'Timeline'), icon: 'TimelineCheckOutline' })
            }
            // Budget tab — Advanced-mode projects only. Three gates:
            //  (a) the per-team on/off toggle in Manage Team → Integrations
            //      (default on).
            //  (b) the project-level view floor — a caller below the floor
            //      doesn't see the tab UNLESS
            //  (c) they are a named editor on any of the project's lanes.
            // (b)+(c) are precomputed server-side as budgetConfig.can_view_budget.
            if (this.isAdvancedProject
                && this.budgetConfig
                && this.budgetConfig.budget_enabled !== false
                && this.budgetConfig.can_view_budget !== false) {
                tabs.push({ key: 'budget', label: t('teamhub', 'Budget'), icon: 'WalletOutline' })
            }
            // Time tab — Advanced-mode projects only. Same three-gate pattern
            // as Budget. can_view_time is precomputed server-side (role floor
            // OR named project-participant row).
            if (this.isAdvancedProject
                && this.timeConfig
                && this.timeConfig.time_enabled !== false
                && this.timeConfig.can_view_time !== false) {
                tabs.push({ key: 'time', label: t('teamhub', 'Time'), icon: 'ClockOutline' })
            }
            ;(this.teamMenuItems || []).filter(item => !item.is_builtin)
                .forEach(item => tabs.push({ key: 'ext-' + item.registry_id, label: item.title, icon: item.icon || 'Puzzle', appId: item.app_id || null }))
            ;(this.webLinks || []).forEach(link => tabs.push({ key: 'link-' + link.id, label: link.title, url: link.url, isNcRelative: this.isNcRelativeUrl(link.url) }))
            return tabs
        },

        async loadPresenceConfig(teamId) {
            if (!teamId) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/presence/config`)
                )
                this.SET_PRESENCE_CONFIG(data)
                this.buildOrderedTabs(this.orderedTabs.map(t => t.key))
            } catch (err) {
                this.SET_PRESENCE_CONFIG({ presence_enabled: false, hide_reasons: false })
            }
        },

        syncLinkTabs() {
            const linkTabs = (this.webLinks || []).map(link => ({ key: 'link-' + link.id, label: link.title, url: link.url, isNcRelative: this.isNcRelativeUrl(link.url) }))
            this.orderedTabs = [...this.orderedTabs.filter(t => !t.key.startsWith('link-')), ...linkTabs]
        },

        syncExtTabs() {
            const extTabs = (this.teamMenuItems || []).filter(item => !item.is_builtin)
                .map(item => ({ key: 'ext-' + item.registry_id, label: item.title, icon: item.icon || 'Puzzle', appId: item.app_id || null }))
            const builtinKeys = new Set(['talk', 'files', 'calendar', 'deck', 'presence', 'decisions', 'timeline', 'budget', 'time'])
            this.orderedTabs = [
                ...this.orderedTabs.filter(t => builtinKeys.has(t.key)),
                ...extTabs,
                ...this.orderedTabs.filter(t => t.key.startsWith('link-')),
            ]
        },

        // ── URLs ────────────────────────────────────────────────────

        menuItemUrl(menuItem) {
            if (!menuItem.iframe_url) return ''
            const raw = String(menuItem.iframe_url).trim()

            // Defence in depth: backend already validates at registration time,
            // but never trust stored data on the way out either. We accept only:
            //   - https:// absolute URLs
            //   - /apps/... or /index.php/... NC-relative paths
            // Anything else (javascript:, data:, http://, file://, //evil.com)
            // is rejected outright. Empty string causes <iframe> to render with
            // no src and AppEmbed shows the loading skeleton without ever
            // navigating — visible failure mode rather than silent risk.
            const isHttps    = raw.startsWith('https://')
            const isAppRel   = raw.startsWith('/apps/')
            const isIndexRel = raw.startsWith('/index.php/')
            if (!isHttps && !isAppRel && !isIndexRel) {
                return ''
            }

            const sep = raw.includes('?') ? '&' : '?'
            return raw + sep + 'teamId=' + encodeURIComponent(this.currentTeamId)
        },

        // ── Widget / team actions ───────────────────────────────────

        onWidgetActionsLoaded({ registryId, actions }) {
            this.widgetDynamicActions[registryId] = actions || []
        },

        openManageTeam() { this.$emit('show-manage-team') },

        /**
         * Project-owner onboarding (v3.90.x) — one-shot auto-popup right after an
         * Advanced project team is created. Called from both loadLayout (once
         * `project` is ready) and the isTeamAdmin watcher (once `members` is
         * ready) since the two resolve independently and whichever finishes
         * last is the one that actually shows the guide. Idempotent: no-ops
         * unless the store's justCreatedAdvancedProjectTeamId flag matches this
         * exact team, and clears the flag on success so it never fires again.
         */
        /**
         * v3.98.0 — auto-opening of the ProjectPhaseGuide dialog is folded
         * into the ProjectCompassPanel. Landing on the Compass gives the
         * owner the persistent setup checklist immediately; the phase
         * walkthrough is still reachable on demand via the Compass
         * "Walkthrough" button and the stepper's info button, but no
         * longer pops up unprompted. This method still clears the
         * one-shot flag so it doesn't linger in the store — kept as a
         * no-op-except-cleanup for backward compat with legacy state.
         */
        maybeShowProjectGuideForNewTeam() {
            const flagTeamId = this.$store.state.justCreatedAdvancedProjectTeamId
            if (flagTeamId && flagTeamId === this.currentTeamId) {
                this.$store.commit('SET_JUST_CREATED_ADVANCED_PROJECT', null)
            }
        },

        /**
         * Compass "Advance phase" CTA — fires the setPhase endpoint with
         * the next phase. Frontend gates the button on admin already, but
         * the backend still requires admin level (defence-in-depth).
         */
        async onCompassAdvancePhase(nextPhase) {
            if (!nextPhase) return
            if (!this.isTeamAdmin) return
            try {
                const { data } = await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/phase`),
                    { phase: nextPhase },
                )
                this.SET_PROJECT(data)
            } catch (e) {
                showError(t('teamhub', 'Failed to advance phase: {error}', {
                    error: e?.response?.data?.error || e?.message || t('teamhub', 'Unknown error'),
                }))
            }
        },

        /**
         * v3.98.2 — a workstream lane was created via ProjectSwimlanesModal.
         * Re-commit the current project fact so the Compass's watch on
         * `project` fires and it refetches the readiness envelope; the
         * workstreams_defined item flips to done once the stack count > 1.
         * Cheaper than plumbing a dedicated "refresh compass" signal —
         * the store already recomputes on project mutation.
         */
        onLanesChanged() {
            this.SET_PROJECT({ ...this.project })
        },

        /**
         * v3.99.0 — the Closing artifact was just generated. Update the
         * project fact so closing_artifact_at reflects the fresh time,
         * which flips the closing_artifact readiness item to done. The
         * modal itself shows the file path — we don't need to open it.
         */
        onClosingArtifactGenerated(data) {
            this.SET_PROJECT({
                ...this.project,
                closingArtifactAt: data.generatedAt,
            })
        },

        /**
         * v3.99.0 — user confirmed team archival via the policy warning
         * modal. Fires the existing archive endpoint. On success, App.vue
         * will clear the current team on the next fetch.
         */
        async onArchivePolicyConfirmed() {
            this.showArchivePolicyModal = false
            try {
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/archive`),
                )
                showSuccess(t('teamhub', 'Team archive started'))
                // Bounce the user out of the team view — the team is
                // gone (or soft-deleted). Emitting team-left tells
                // App.vue to clear currentTeamId and show the sidebar.
                this.$emit('team-left')
            } catch (e) {
                showError(t('teamhub', 'Failed to archive team: {error}', {
                    error: e?.response?.data?.error || e?.message || t('teamhub', 'Unknown error'),
                }))
            }
        },

        /**
         * "Open Project settings" clicked inside ProjectPhaseGuide — reuses the
         * same deep-link-to-a-tab pattern as the resource-warning strip's
         * "Open settings →" (SET_RESOURCE_WARNING_FOCUS), but for the Project tab.
         */
        onOpenProjectSettingsFromGuide() {
            this.SET_PROJECT_TAB_FOCUS(true)
            this.showProjectGuide = false
            this.$emit('show-manage-team')
        },

        /**
         * Returns true when a stored link URL is a Nextcloud-relative path
         * that should open in an iframe rather than a new browser tab.
         * Mirrors the normalisation in WebLinkService::normaliseUrl().
         */
        isNcRelativeUrl(url) {
            if (!url) return false
            return url.startsWith('/apps/') || url.startsWith('/index.php/')
        },

        /**
         * Build the final iframe src for an NC-relative link tab.
         * The URL is already normalised (leading slash guaranteed by the backend).
         * We prepend the NC base path via generateUrl to handle sub-directory installs.
         */
        linkEmbedUrl(url) {
            // generateUrl('/apps/foo') → /nextcloud/apps/foo (handles sub-dir installs)
            // Strip the leading slash from our stored path before passing to generateUrl
            // so we don't end up with a double slash.
            const path = url.startsWith('/') ? url.slice(1) : url
            return generateUrl('/' + path)
        },

        onCalendarEmbedAction(actionId) {
            if (actionId === 'add-event') {
                this.showAddEvent = true
            } else if (actionId === 'delete-events') {
                this.showDeleteEvents = true
            } else if (actionId === 'suggest-meeting') {
                this.showSuggestMeeting = true
            } else if (actionId === 'cal-today') {
                this.calendarDate = new Date()
                this.$nextTick(() => this.$refs.calendarEmbed?.reload())
            } else if (actionId === 'cal-prev' || actionId === 'cal-next') {
                const dir = actionId === 'cal-next' ? 1 : -1
                const d = new Date(this.calendarDate)
                const view = this.calendarView
                if (view === 'dayGridMonth' || view === 'listMonth') {
                    // Advance by one calendar month, keeping day=1 to avoid
                    // Feb-28→Mar-31 overshoot when navigating back.
                    d.setDate(1)
                    d.setMonth(d.getMonth() + dir)
                } else if (view === 'timeGridDay' || view === 'listDay') {
                    d.setDate(d.getDate() + dir)
                } else {
                    // Week views: advance by 7 days
                    d.setDate(d.getDate() + dir * 7)
                }
                this.calendarDate = d
                this.$nextTick(() => this.$refs.calendarEmbed?.reload())
            }
        },

        onCalendarEmbedSelect({ id, value }) {
            if (id === 'calendar-view') {
                this.calendarView = value
                this.calendarDate = new Date()
                // calendarUrl recomputes automatically; reload the iframe with the new URL.
                this.$nextTick(() => this.$refs.calendarEmbed?.reload())
            }
        },

        onTimelineEmbedAction(actionId) {
            if (actionId === 'tl-today') {
                this.timelineWindowStart = TeamView_snapWindow(new Date(), this.timelineViewMode)
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
            } else if (actionId === 'tl-prev' || actionId === 'tl-next') {
                // Step by one unit at a time, regardless of view width:
                //   1W view → step 1 week
                //   1M / 3M / 6M views → step 1 month
                // This makes the wider views feel like a sliding window
                // ("show me what was happening one month earlier/later") rather
                // than jumping by the full view span (which would skip 3 or 6
                // months in one click).
                const stepMode = (this.timelineViewMode === '1W') ? '1W' : '1M'
                this.timelineWindowStart = actionId === 'tl-next'
                    ? TeamView_addPeriod(this.timelineWindowStart, stepMode)
                    : TeamView_subPeriod(this.timelineWindowStart, stepMode)
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
            } else if (actionId === 'tl-print') {
                // Print the iframe's own document, not the parent NC window.
                // The iframe is same-origin (we serve it from /apps/teamhub/
                // ourselves), so contentWindow is fully accessible. Calling
                // print() on the iframe window respects its own @media print
                // rules, which we use in templates/timeline.php to hide
                // section labels' tinted backgrounds for ink saving and to
                // make the canvas span its full natural width regardless of
                // the current iframe viewport.
                try {
                    const frame = this.$refs.timelineEmbed?.$el?.querySelector('iframe')
                    if (frame && frame.contentWindow) {
                        frame.contentWindow.focus()
                        frame.contentWindow.print()
                    } else {
                        console.warn('[TeamHub][TeamView] timeline print: iframe not ready')
                    }
                } catch (err) {
                    console.error('[TeamHub][TeamView] timeline print failed', err)
                }
            }
        },

        onTimelineEmbedSelect({ id, value }) {
            if (id === 'timeline-view') {
                this.timelineViewMode    = value
                this.timelineWindowStart = TeamView_snapWindow(new Date(), value)
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
            }
        },

        /**
         * Filter checkbox toggled. Two id shapes are handled:
         *   • bare source name ('calendar', 'deck', ...) → toggles top-level
         *   • '<source>-<type>' ('deck-due', 'decisions-decided') → sub-filter
         * Iframe is reloaded with the updated URL each time.
         */
        onTimelineEmbedMenuToggle(itemId) {
            if (itemId === 'links') {
                this.timelineShowLinks = !this.timelineShowLinks
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
                return
            }
            if (itemId === 'depLinks') {
                this.timelineShowDepLinks = !this.timelineShowDepLinks
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
                return
            }
            if (itemId === 'msgLinks') {
                this.timelineShowMsgLinks = !this.timelineShowMsgLinks
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
                return
            }
            if (itemId in this.timelineSources) {
                this.timelineSources[itemId] = !this.timelineSources[itemId]
                this.$nextTick(() => this.$refs.timelineEmbed?.reload())
                return
            }
            const dash = itemId.indexOf('-')
            if (dash > 0) {
                const src  = itemId.slice(0, dash)
                const type = itemId.slice(dash + 1)
                if (this.timelineSubFilters[src] && type in this.timelineSubFilters[src]) {
                    this.timelineSubFilters[src][type] = !this.timelineSubFilters[src][type]
                    this.$nextTick(() => this.$refs.timelineEmbed?.reload())
                }
            }
        },

        onShowPicker(app) {
            if (app === 'deck') {
                this.showDeckPicker = true
            } else if (app === 'calendar') {
                this.showCalendarPicker = true
            }
        },

        pickDeckBoard(board) {
            this.$store.commit('SET_SELECTED_DECK_BOARD', board)
            this.showDeckPicker = false
            this.$store.commit('SET_VIEW', 'deck')
        },

        pickCalendar(cal) {
            this.selectedCalendar = cal
            this.showCalendarPicker = false
            this.$store.commit('SET_VIEW', 'calendar')
        },

        async onLeaveTeam() {
            try {
                await axios.post(generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/leave`), {})
                showSuccess(t('teamhub', 'You have left the team'))
                this.$store.commit('SET_CURRENT_TEAM', null)
                await this.$store.dispatch('fetchTeams')
                this.$emit('team-left')
            } catch (error) {
                const msg = error.response?.data?.error || ''
                showError(msg || t('teamhub', 'Failed to leave team'))
            }
        },

        onPagesLoaded(data) {
            this.pagesData.teamPage    = data.teamPage    || null
            this.pagesData.subPages    = data.subPages    || []
            this.pagesData.teamhubRoot = data.teamhubRoot || null
            this.pagesData.allPages    = data.allPages    || []
        },

        openCreatePage() { this.newPageTitle = ''; this.showCreatePage = true },
        openDeletePage() { this.deletePageTarget = null; this.showDeletePage = true },

        async submitCreatePage() {
            const title = this.newPageTitle.trim()
            if (!title) return
            this.creatingPage = true
            try {
                const intravoxParentPath = this.$store.state.intravoxParentPath || 'en/teamhub'
                const teamName = this.currentTeam?.name || ''
                const teamSlug = this.toSlug(teamName)
                const teamPagePath = intravoxParentPath + '/' + teamSlug

                if (!teamSlug || !teamName) {
                    showError(t('teamhub', 'Cannot create page: team name not available'))
                    return
                }

                const body = { id: this.toSlug(title), title, parentPath: teamPagePath }
                await axios.post(generateUrl('/apps/intravox/api/pages'), body)
                showSuccess(t('teamhub', 'Page "{title}" created', { title }))
                this.showCreatePage = false
                this.$refs.widgetGrid?.refreshIntravox()
            } catch (e) {
                const msg = e?.response?.data?.message || e?.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to create page: {error}', { error: msg }) : t('teamhub', 'Failed to create page'))
            } finally {
                this.creatingPage = false
            }
        },

        async submitDeletePage() {
            if (!this.deletePageTarget) return
            const page = this.deletePageTarget
            this.deletingPage = true
            try {
                const deleteId = page.id
                    || (page.title || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/, '')
                    || page.uniqueId
                await axios.delete(generateUrl(`/apps/intravox/api/pages/${deleteId}`))
                showSuccess(t('teamhub', 'Page "{title}" deleted', { title: page.title }))
                this.showDeletePage = false
                this.deletePageTarget = null
                this.$refs.widgetGrid?.refreshIntravox()
            } catch (e) {
                const msg = e?.response?.data?.message || e?.response?.data?.error || ''
                showError(msg ? t('teamhub', 'Failed to delete page: {error}', { error: msg }) : t('teamhub', 'Failed to delete page'))
            } finally {
                this.deletingPage = false
            }
        },

        toSlug(text) {
            return (text || '').toLowerCase().replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-') || 'page'
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
    },
}
</script>

<style scoped>
/* Resource picker modal */
.teamhub-resource-picker {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 0;
    min-width: 260px;
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
    transition: background 0.15s;
}
.teamhub-resource-picker__item:hover {
    background: var(--color-primary-light);
}
.teamhub-resource-picker__item:focus-visible {
    outline: 2px solid var(--color-primary);
}
.teamhub-resource-picker__color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.teamhub-resource-picker__name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-team-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

.teamhub-content {
    flex: 1;
    overflow: hidden;
    min-height: 0;
    position: relative;
}

.teamhub-page-delete-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 260px;
    overflow-y: auto;
}

.teamhub-page-delete-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    border-radius: var(--border-radius-large);
    border: 2px solid var(--color-border);
    cursor: pointer;
    font-size: var(--th-font-body);
    transition: border-color 0.15s, background 0.15s;
}

.teamhub-page-delete-option:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}

.teamhub-page-delete-option--selected {
    border-color: var(--color-error);
    background: var(--color-error);
    color: var(--color-error-text);
}

.teamhub-page-delete-radio { display: none; }
</style>

<style>
/* v3.100.16: the dark-mode background override was retired.
   .teamhub-home-view now uses --color-background-dark which is already
   theme-aware (dark in dark mode, light-grey in light mode), so the
   explicit #000000 override became redundant. The mobile override
   remains because MobileWidgetView owns its own canvas and shouldn't
   inherit the parent backdrop. */
body[data-themes*="dark"] .teamhub-home-view--mobile {
    background: var(--color-main-background);
}
</style>
