<template>
    <!--
        TeamHub mobile single-canvas view.

        Shown only on viewports ≤ 768px (the parent TeamWidgetGrid decides).
        Replaces the desktop vue-grid-layout with one canvas that displays the
        message stream OR a single widget body, plus an icon bar at the bottom.

        State:
        - `activeWidget` (data) — which widget is currently shown in the canvas.
          Defaults to `'msgstream'`. Reset to `'msgstream'` on team switch.

        Layout:
        ┌─────────────────────┐
        │ Canvas (scrollable) │  ← message stream OR active widget
        ├─────────────────────┤
        │ Icon bar            │  ← Home + every available widget
        └─────────────────────┘
        Plus a FAB on message stream view only.
    -->
    <div class="teamhub-mobile-view">

        <!-- ── Canvas ───────────────────────────────────────────────── -->
        <div class="teamhub-mobile-canvas">

            <!-- Active widget header (everything except message stream gets a title) -->
            <div v-if="activeWidget !== 'msgstream'" class="teamhub-mobile-widget-header">
                <component :is="activeWidgetMeta.icon" :size="22" />
                <h2 class="teamhub-mobile-widget-title">{{ activeWidgetMeta.title }}</h2>
            </div>

            <!-- ─── Message stream (default) ──────────────────────── -->
            <div v-show="activeWidget === 'msgstream'" class="teamhub-mobile-canvas-body">
                <MessageStream ref="messageStream" :hide-header="true" />
            </div>

            <!-- ─── Team Info ─────────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-teaminfo'" class="teamhub-mobile-canvas-body teamhub-mobile-canvas-body--padded">
                <div class="teamhub-mobile-teaminfo">
                    <img
                        v-if="team.image_url"
                        :src="team.image_url"
                        :alt="team.name"
                        class="teamhub-mobile-teaminfo__logo" />
                    <p class="teamhub-mobile-teaminfo__description">
                        {{ team.description || t('teamhub', 'No description') }}
                    </p>
                    <div v-if="teamLabels.length" class="teamhub-mobile-teaminfo__labels" role="list" :aria-label="t('teamhub', 'Team type')">
                        <span
                            v-for="label in teamLabels"
                            :key="label.key"
                            :class="['teamhub-mobile-team-label', 'teamhub-mobile-team-label--' + label.tone]"
                            :title="label.tooltip"
                            role="listitem">
                            {{ label.text }}
                        </span>
                    </div>
                    <div v-if="teamOwner" class="teamhub-mobile-teaminfo__owner">
                        <span class="teamhub-mobile-teaminfo__owner-label">{{ t('teamhub', 'Owner') }}</span>
                        <div class="teamhub-mobile-teaminfo__owner-row">
                            <NcAvatar
                                v-if="teamOwner.userId"
                                :user="teamOwner.userId"
                                :display-name="teamOwner.displayName"
                                :show-user-status="false"
                                :size="26" />
                            <span>{{ teamOwner.displayName }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Members ───────────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-members'" class="teamhub-mobile-canvas-body teamhub-mobile-canvas-body--padded">
                <!-- Direct user avatars -->
                <div v-if="members.length" class="teamhub-mobile-avatar-stack">
                    <NcAvatar
                        v-for="member in members"
                        v-if="member.userId"
                        :key="member.userId"
                        :user="member.userId"
                        :display-name="member.displayName"
                        :show-user-status="false"
                        :disable-menu="false"
                        :size="32" />
                </div>

                <!-- Group / sub-team memberships -->
                <div v-if="memberships && memberships.length" class="teamhub-mobile-memberships">
                    <div
                        v-for="m in memberships"
                        :key="m.type + ':' + m.displayName"
                        class="teamhub-mobile-membership-row">
                        <div
                            class="teamhub-mobile-membership-icon"
                            :class="'teamhub-mobile-membership-icon--' + m.type">
                            <AccountGroup v-if="m.type === 'group'" :size="18" />
                            <AccountMultipleIcon v-else :size="18" />
                        </div>
                        <span class="teamhub-mobile-membership-name">{{ m.displayName }}</span>
                        <span
                            class="teamhub-mobile-membership-pill"
                            :class="'teamhub-mobile-membership-pill--' + m.type">
                            {{ m.type === 'group' ? t('teamhub', 'Group') : t('teamhub', 'Team') }}
                        </span>
                        <!-- TRANSLATORS: user count on a group/team membership pill, e.g. "1 user" or "6 users" -->
                        <span class="teamhub-mobile-membership-count">
                            {{ n('teamhub', '{n} user', '{n} users', m.memberCount, { n: m.memberCount }) }}
                        </span>
                    </div>
                </div>

                <!-- TRANSLATORS: button label showing total member count, e.g. "Show all 1 member" or "Show all 12 members" -->
                <NcButton
                    v-if="effectiveMemberCount > members.length"
                    type="secondary"
                    wide
                    class="teamhub-mobile-members-action"
                    @click="$emit('show-all-members')">
                    {{ n('teamhub', 'Show all {n} member', 'Show all {n} members', effectiveMemberCount, { n: effectiveMemberCount }) }}
                </NcButton>
            </div>

            <!-- ─── Calendar ──────────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-calendar'" class="teamhub-mobile-canvas-body">
                <CalendarWidget ref="calendarWidget" />
            </div>

            <!-- ─── Tasks ─────────────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-deck'" class="teamhub-mobile-canvas-body">
                <DeckWidget />
            </div>

            <!-- ─── Activity ──────────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-activity'" class="teamhub-mobile-canvas-body">
                <ActivityWidget @show-more="$emit('set-view', 'activity')" />
            </div>

            <!-- ─── Pages (Intravox) ──────────────────────────────── -->
            <div v-if="activeWidget === 'widget-pages'" class="teamhub-mobile-canvas-body">
                <IntravoxWidget
                    ref="intravoxWidget"
                    :can-act="isTeamModerator"
                    @pages-loaded="$emit('pages-loaded', $event)" />
            </div>

            <!-- ─── Files: Favourites ─────────────────────────────── -->
            <div v-if="activeWidget === 'widget-files-favorites'" class="teamhub-mobile-canvas-body">
                <FilesFavoritesWidget />
            </div>

            <!-- ─── Files: Recent ─────────────────────────────────── -->
            <div v-if="activeWidget === 'widget-files-recent'" class="teamhub-mobile-canvas-body">
                <FilesRecentWidget />
            </div>

            <!-- ─── Files: Shared with team ───────────────────────── -->
            <div v-if="activeWidget === 'widget-files-shared'" class="teamhub-mobile-canvas-body">
                <FilesSharedWidget />
            </div>

            <!-- ─── External integration widgets ──────────────────── -->
            <template v-for="ext in teamWidgets">
                <div
                    v-if="activeWidget === 'widget-int-' + ext.registry_id"
                    :key="'mobile-int-' + ext.registry_id"
                    class="teamhub-mobile-canvas-body">
                    <IntegrationWidget
                        :integration="ext"
                        :team-id="currentTeamId"
                        @actions-loaded="$emit('widget-actions-loaded', $event)" />
                </div>
            </template>
        </div>

        <!--
            Action FAB. Behaviour depends on the active widget's action set:
              0 actions → not rendered
              1 action  → tap fires the action directly
              2+ actions → tap toggles a small sheet listing each action

            One <button> handles both cases for visual consistency. The sheet
            is a sibling element controlled by `actionsMenuOpen` state and
            dismissed by:
              • tapping any action
              • tapping the backdrop
              • pressing Escape
        -->
        <button
            v-if="currentActions.length > 0"
            ref="fab"
            class="teamhub-mobile-fab"
            :class="{ 'teamhub-mobile-fab--active': actionsMenuOpen }"
            type="button"
            :disabled="currentActions.length === 1 && !!currentActions[0].disabled"
            :aria-label="fabAriaLabel"
            :aria-haspopup="currentActions.length > 1 ? 'menu' : null"
            :aria-expanded="currentActions.length > 1 ? String(actionsMenuOpen) : null"
            :title="fabTitle"
            @click="onFabClick">
            <Plus :size="28" />
        </button>

        <!-- Backdrop + sheet for the multi-action menu -->
        <div
            v-if="actionsMenuOpen && currentActions.length > 1"
            class="teamhub-mobile-fab-backdrop"
            @click="closeActionsMenu" />

        <div
            v-if="actionsMenuOpen && currentActions.length > 1"
            class="teamhub-mobile-fab-sheet"
            role="menu"
            :aria-label="t('teamhub', 'Widget actions')"
            @keydown.esc.stop="closeActionsMenu">
            <button
                v-for="action in currentActions"
                :key="action.key"
                role="menuitem"
                type="button"
                class="teamhub-mobile-fab-sheet__item"
                :disabled="!!action.disabled"
                :title="action.disabled && action.disabledTitle ? action.disabledTitle : ''"
                @click="onSheetItemClick(action)">
                <component :is="action.icon" :size="20" />
                <span class="teamhub-mobile-fab-sheet__label">{{ action.label }}</span>
            </button>
        </div>

        <!-- ── Icon bar ────────────────────────────────────────────── -->
        <nav
            class="teamhub-mobile-icon-bar"
            role="tablist"
            :aria-label="t('teamhub', 'Widgets')">
            <button
                v-for="item in availableWidgets"
                :key="item.key"
                role="tab"
                type="button"
                class="teamhub-mobile-icon-bar__item"
                :class="{ 'teamhub-mobile-icon-bar__item--active': activeWidget === item.key }"
                :aria-selected="activeWidget === item.key ? 'true' : 'false'"
                :aria-label="item.title"
                :title="item.title"
                @click="setActive(item.key)">
                <img
                    v-if="item.iconUrl"
                    :src="item.iconUrl"
                    :alt="''"
                    aria-hidden="true"
                    class="teamhub-mobile-icon-bar__app-icon"
                    @error="onIconError($event)" />
                <component :is="item.icon" v-else :size="22" />
                <span class="teamhub-mobile-icon-bar__label">{{ item.shortTitle || item.title }}</span>
            </button>
        </nav>
    </div>
</template>

<script>
import { mapState, mapGetters } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar, NcButton } from '@nextcloud/vue'

import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FilePlus from 'vue-material-design-icons/FilePlus.vue'
import LocationExit from 'vue-material-design-icons/LocationExit.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'

import MessageStream from './MessageStream.vue'
import CalendarWidget from './CalendarWidget.vue'
import DeckWidget from './DeckWidget.vue'
import ActivityWidget from './ActivityWidget.vue'
import IntravoxWidget from './IntravoxWidget.vue'
import IntegrationWidget from './IntegrationWidget.vue'
import FilesFavoritesWidget from './FilesFavoritesWidget.vue'
import FilesRecentWidget from './FilesRecentWidget.vue'
import FilesSharedWidget from './FilesSharedWidget.vue'

export default {
    name: 'MobileWidgetView',

    components: {
        NcAvatar, NcButton,
        // icons used in the icon bar / canvas headers — registered here so
        // <component :is="..."> resolves them
        MessageOutline, InformationOutline, AccountGroup, AccountMultipleIcon,
        AccountPlus, Calendar, CalendarPlus, CardText, CheckboxMarkedOutline,
        ClipboardPlusOutline, ClockOutline, Cog, ContentCopy,
        FileDocumentOutline, FilePlus, LocationExit, Plus, Puzzle,
        ShareVariantIcon, StarOutlineIcon, TrashCan, VideoIcon,
        // widget bodies
        MessageStream, CalendarWidget, DeckWidget, ActivityWidget,
        IntravoxWidget, IntegrationWidget,
        FilesFavoritesWidget, FilesRecentWidget, FilesSharedWidget,
    },

    props: {
        // Data needed to render the team-info widget
        teamLabels:   { type: Array,  default: () => [] },
        teamOwner:    { type: Object, default: null },
        isTeamAdmin:  { type: Boolean, default: false },
        isTeamModerator: { type: Boolean, default: false },
        // Whether to show the Tasks widget at all (Deck OR Tasks resource)
        showTasksWidget: { type: Boolean, default: false },
        // Pages widget data
        pagesData: { type: Object, default: () => ({ teamPage: null, subPages: [], teamhubRoot: null, allPages: [] }) },
    },

    emits: [
        'manage-team', 'copy-link', 'invite', 'leave-team',
        'schedule-meeting', 'add-event', 'team-meeting',
        'add-deck-task', 'add-personal-task',
        'create-page', 'delete-page', 'pages-loaded',
        'set-view',
        'show-all-members',
        'widget-actions-loaded',
    ],

    data() {
        return {
            activeWidget: 'msgstream',
            // Open/closed state for the multi-action FAB sheet. Reset to
            // false whenever the active widget changes (a stale-open menu
            // from a previous widget would otherwise show wrong actions).
            actionsMenuOpen: false,
        }
    },

    computed: {
        ...mapState([
            'currentTeamId', 'resources', 'members', 'memberships',
            'effectiveMemberCount',
            'intravoxAvailable', 'teamWidgets', 'isCurrentUserDirectMember',
        ]),
        ...mapGetters(['currentTeam']),

        team() { return this.currentTeam || {} },

        /**
         * The full list of widgets the current user has access to.
         *
         * Mirrors the same v-if gates the desktop grid uses, so "available"
         * here means exactly what the desktop layout shows. Missing
         * resources => widget is hidden from the icon bar.
         *
         * Order is fixed (not user-orderable on mobile in v1).
         */
        availableWidgets() {
            const list = []

            // Home / message stream — always first
            list.push({
                key: 'msgstream',
                title: t('teamhub', 'Messages'),
                shortTitle: t('teamhub', 'Home'),
                icon: 'MessageOutline',
            })

            list.push({
                key: 'widget-teaminfo',
                title: t('teamhub', 'Team info'),
                shortTitle: t('teamhub', 'Info'),
                icon: 'InformationOutline',
            })

            list.push({
                key: 'widget-members',
                title: t('teamhub', 'Members'),
                icon: 'AccountGroup',
            })

            if (this.resources.calendar) {
                list.push({
                    key: 'widget-calendar',
                    title: t('teamhub', 'Upcoming events'),
                    shortTitle: t('teamhub', 'Events'),
                    icon: 'Calendar',
                })
            }

            if (this.showTasksWidget) {
                list.push({
                    key: 'widget-deck',
                    title: t('teamhub', 'Upcoming tasks'),
                    shortTitle: t('teamhub', 'Tasks'),
                    icon: 'CardText',
                })
            }

            list.push({
                key: 'widget-activity',
                title: t('teamhub', 'Team activity'),
                shortTitle: t('teamhub', 'Activity'),
                icon: 'ClockOutline',
            })

            if (this.resources.intravox) {
                list.push({
                    key: 'widget-pages',
                    title: t('teamhub', 'Pages'),
                    icon: 'FileDocumentOutline',
                })
            }

            if (this.resources.files) {
                list.push({
                    key: 'widget-files-favorites',
                    title: t('teamhub', 'Favourite files'),
                    shortTitle: t('teamhub', 'Favourites'),
                    icon: 'StarOutlineIcon',
                })
                list.push({
                    key: 'widget-files-recent',
                    title: t('teamhub', 'Recently modified'),
                    shortTitle: t('teamhub', 'Recent'),
                    icon: 'ClockOutline',
                })
            }

            if (this.resources.shared_files) {
                list.push({
                    key: 'widget-files-shared',
                    title: t('teamhub', 'Shared files'),
                    shortTitle: t('teamhub', 'Shared'),
                    icon: 'ShareVariantIcon',
                })
            }

            // External integration widgets — registry-driven
            ;(this.teamWidgets || []).forEach(w => {
                list.push({
                    key: 'widget-int-' + w.registry_id,
                    title: w.title || t('teamhub', 'Widget'),
                    shortTitle: w.title || t('teamhub', 'Widget'),
                    icon: 'Puzzle',
                    iconUrl: w.app_id ? this.appIconUrl(w.app_id) : null,
                })
            })

            return list
        },

        /**
         * The list of actions for the currently active widget.
         *
         * Each action is a plain object:
         *   {
         *     key:           string  — stable identity for v-for
         *     label:         string  — translated, shown in tooltip + menu
         *     icon:          string  — registered component name (e.g. 'Plus')
         *     handler:       Function (called from invokeAction)
         *     disabled:      boolean (optional)
         *     disabledTitle: string  (optional, tooltip while disabled)
         *   }
         *
         * Empty list ⇒ no FAB rendered.
         * One entry  ⇒ FAB fires it directly on tap.
         * Two+       ⇒ FAB opens a custom action sheet listing them.
         *
         * Gating mirrors the desktop grid exactly — same isTeamAdmin /
         * resources.* / isTeamModerator checks, so the user sees on mobile
         * exactly the actions they would have access to on desktop.
         */
        currentActions() {
            switch (this.activeWidget) {
                case 'msgstream':
                    return [{
                        key: 'post-message',
                        label: t('teamhub', 'Post message'),
                        icon: 'Plus',
                        handler: () => {
                            const stream = this.$refs.messageStream
                            if (stream && typeof stream.openPostForm === 'function') {
                                stream.openPostForm()
                            }
                        },
                    }]

                case 'widget-teaminfo': {
                    const actions = [
                        {
                            key: 'copy-link',
                            label: t('teamhub', 'Copy team link'),
                            icon: 'ContentCopy',
                            handler: () => this.$emit('copy-link'),
                        },
                        {
                            key: 'invite',
                            label: t('teamhub', 'Invite user'),
                            icon: 'AccountPlus',
                            handler: () => this.$emit('invite'),
                        },
                    ]
                    if (this.isTeamAdmin) {
                        actions.push({
                            key: 'manage-team',
                            label: t('teamhub', 'Manage team'),
                            icon: 'Cog',
                            handler: () => this.$emit('manage-team'),
                        })
                    }
                    actions.push({
                        key: 'leave-team',
                        label: t('teamhub', 'Leave team'),
                        icon: 'LocationExit',
                        disabled: !this.isCurrentUserDirectMember,
                        disabledTitle: this.isCurrentUserDirectMember
                            ? ''
                            : t('teamhub', 'You were added via a group or team. Ask your administrator to remove you.'),
                        handler: () => {
                            if (this.isCurrentUserDirectMember) {
                                this.$emit('leave-team')
                            }
                        },
                    })
                    return actions
                }

                case 'widget-members':
                    if (!this.isTeamModerator) return []
                    return [{
                        key: 'invite-members',
                        label: t('teamhub', 'Invite members'),
                        icon: 'AccountPlus',
                        handler: () => this.$emit('invite'),
                    }]

                case 'widget-calendar':
                    return [
                        {
                            key: 'add-event',
                            label: t('teamhub', 'Add event'),
                            icon: 'CalendarPlus',
                            handler: () => this.$emit('add-event'),
                        },
                        {
                            key: 'schedule-meeting',
                            label: t('teamhub', 'Schedule meeting'),
                            icon: 'VideoIcon',
                            handler: () => this.$emit('schedule-meeting'),
                        },
                        {
                            key: 'team-meeting',
                            label: t('teamhub', 'Team meeting'),
                            icon: 'AccountGroup',
                            handler: () => this.$emit('team-meeting'),
                        },
                    ]

                case 'widget-deck': {
                    const actions = []
                    if (this.resources.deck) {
                        actions.push({
                            key: 'add-deck-task',
                            label: t('teamhub', 'Create Deck task'),
                            icon: 'CheckboxMarkedOutline',
                            handler: () => this.$emit('add-deck-task'),
                        })
                    }
                    if (this.resources.tasks && this.resources.calendar) {
                        actions.push({
                            key: 'add-personal-task',
                            label: t('teamhub', 'Create personal task'),
                            icon: 'ClipboardPlusOutline',
                            handler: () => this.$emit('add-personal-task'),
                        })
                    }
                    return actions
                }

                case 'widget-pages':
                    if (!this.isTeamModerator) return []
                    return [
                        {
                            key: 'create-page',
                            label: t('teamhub', 'Create page'),
                            icon: 'FilePlus',
                            handler: () => this.$emit('create-page'),
                        },
                        {
                            key: 'delete-page',
                            label: t('teamhub', 'Delete page'),
                            icon: 'TrashCan',
                            disabled: !this.pagesData.teamPage,
                            handler: () => {
                                if (this.pagesData.teamPage) {
                                    this.$emit('delete-page')
                                }
                            },
                        },
                    ]

                // Widgets without actions: activity, files-*, integration widgets.
                // (Integration widgets may register dynamic actions per registry_id,
                //  but those are already surfaced via the desktop NcActions menu inside
                //  the widget body and don't have a clean handler shape on mobile yet.)
                default:
                    return []
            }
        },

        /**
         * Metadata for whichever widget is currently active.
         * Used to render the canvas-top title bar (icon + title) for any
         * widget except the message stream.
         */
        activeWidgetMeta() {
            return this.availableWidgets.find(w => w.key === this.activeWidget) || { title: '', icon: 'MessageOutline' }
        },

        /**
         * Accessible label for the FAB.
         * - 1 action: the action's label is the most informative thing.
         * - 2+ actions: a generic "actions menu" label, since the FAB is
         *   really a menu trigger at that point.
         */
        fabAriaLabel() {
            if (this.currentActions.length === 1) {
                return this.currentActions[0].label
            }
            return t('teamhub', 'Widget actions')
        },

        /**
         * Tooltip title for the FAB. Shows the disabled-reason when the
         * action is disabled (single-action case only).
         */
        fabTitle() {
            if (this.currentActions.length === 1) {
                const a = this.currentActions[0]
                if (a.disabled && a.disabledTitle) return a.disabledTitle
                return a.label
            }
            return t('teamhub', 'Widget actions')
        },
    },

    watch: {
        /**
         * Reset to message stream whenever the team switches, so we never
         * land on a stale widget that may not exist in the new team.
         */
        currentTeamId() {
            this.activeWidget = 'msgstream'
            this.actionsMenuOpen = false
        },

        /**
         * Whenever the active widget changes, close any open action sheet —
         * its contents would otherwise belong to the previous widget.
         */
        activeWidget() {
            this.actionsMenuOpen = false
        },

        /**
         * If the active widget becomes unavailable (e.g. an integration was
         * unregistered, or a resource was disabled), fall back to the
         * message stream rather than rendering an empty canvas.
         */
        availableWidgets(newList) {
            if (!newList.find(w => w.key === this.activeWidget)) {
                this.activeWidget = 'msgstream'
            }
        },
    },

    methods: {
        t,
        n,

        setActive(key) {
            if (this.activeWidget === key) return
            this.activeWidget = key
            // Scroll the new canvas to the top — otherwise the previous
            // widget's scroll offset bleeds through and looks like a render glitch.
            this.$nextTick(() => {
                const canvas = this.$el?.querySelector('.teamhub-mobile-canvas')
                if (canvas) canvas.scrollTop = 0
            })
        },

        /**
         * FAB click handler. Branches on action count:
         *   1 action  → fire it directly
         *   2+ actions → toggle the sheet open/closed
         */
        onFabClick() {
            if (this.currentActions.length === 1) {
                this.invokeAction(this.currentActions[0])
            } else if (this.currentActions.length > 1) {
                this.actionsMenuOpen = !this.actionsMenuOpen
            }
        },

        /**
         * Sheet item handler — fires the action then dismisses the sheet.
         * Disabled items are no-ops but still close the sheet for tap-feedback
         * consistency? No — leave the sheet open if the tap was a no-op so
         * the user can choose a different one.
         */
        onSheetItemClick(action) {
            if (!action || action.disabled) return
            this.invokeAction(action)
            this.closeActionsMenu()
        },

        /**
         * Close the sheet. Called from the backdrop tap, sheet item taps,
         * Escape keypress, and (defensively) when the active widget changes.
         */
        closeActionsMenu() {
            this.actionsMenuOpen = false
        },

        /**
         * Fire a single action object (from currentActions). Honours the
         * `disabled` flag — disabled actions still get rendered with a
         * tooltip explaining why, but tapping them is a no-op.
         */
        invokeAction(action) {
            if (!action) return
            if (action.disabled) return
            if (typeof action.handler === 'function') {
                action.handler()
            }
        },

        /**
         * Resolve a Nextcloud app icon URL. Mirrors the helper used in the
         * desktop grid for external integration widgets — same URL pattern
         * keeps icon caching consistent across views.
         */
        appIconUrl(appId) {
            if (!appId) return ''
            return generateUrl('/svg/' + encodeURIComponent(appId) + '/app-dark?color=000000')
        },

        /**
         * If an external app's icon 404s (app uninstalled or icon missing),
         * silently swap to the generic puzzle-piece fallback already used
         * on the desktop grid.
         */
        onIconError(event) {
            event.target.style.display = 'none'
        },
    },
}
</script>

<style scoped>
.teamhub-mobile-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    width: 100%;
    overflow: hidden;
    background: var(--color-main-background);
    position: relative;
}

/* ─── Canvas ───────────────────────────────────────────────── */

.teamhub-mobile-canvas {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
}

.teamhub-mobile-widget-header {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: var(--color-main-background);
    border-bottom: 1px solid var(--color-border);
}

.teamhub-mobile-widget-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

.teamhub-mobile-canvas-body {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.teamhub-mobile-canvas-body--padded {
    padding: 16px;
    gap: 16px;
}

/* ─── Team Info ────────────────────────────────────────────── */

.teamhub-mobile-teaminfo {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.teamhub-mobile-teaminfo__logo {
    width: 64px;
    height: 64px;
    border-radius: var(--border-radius-large);
    object-fit: cover;
    align-self: flex-start;
}

.teamhub-mobile-teaminfo__description {
    margin: 0;
    font-size: 14px;
    line-height: 1.5;
    color: var(--color-main-text);
}

.teamhub-mobile-teaminfo__labels {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.teamhub-mobile-team-label {
    font-size: 12px;
    padding: 3px 8px;
    border-radius: var(--border-radius-pill, 999px);
    background: var(--color-background-dark);
    color: var(--color-main-text);
}
.teamhub-mobile-team-label--info    { background: color-mix(in srgb, var(--color-primary-element) 14%, transparent); color: var(--color-primary-element); }
.teamhub-mobile-team-label--warn    { background: color-mix(in srgb, var(--color-warning) 14%, transparent); color: var(--color-warning); }
.teamhub-mobile-team-label--success { background: color-mix(in srgb, var(--color-success) 14%, transparent); color: var(--color-success); }

.teamhub-mobile-teaminfo__owner {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.teamhub-mobile-teaminfo__owner-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--color-text-maxcontrast);
    letter-spacing: 0.05em;
}

.teamhub-mobile-teaminfo__owner-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

/* ─── Members ──────────────────────────────────────────────── */

.teamhub-mobile-avatar-stack {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.teamhub-mobile-memberships {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.teamhub-mobile-membership-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-hover);
}

.teamhub-mobile-membership-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    flex-shrink: 0;
}

.teamhub-mobile-membership-icon--group {
    background: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
    color: var(--color-primary-element);
}
.teamhub-mobile-membership-icon--team {
    background: color-mix(in srgb, var(--color-success) 14%, transparent);
    color: var(--color-success);
}

.teamhub-mobile-membership-name {
    flex: 1 1 auto;
    font-size: 14px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.teamhub-mobile-membership-pill {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: var(--border-radius-pill, 999px);
    flex-shrink: 0;
}
.teamhub-mobile-membership-pill--group {
    background: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
    color: var(--color-primary-element);
}
.teamhub-mobile-membership-pill--team {
    background: color-mix(in srgb, var(--color-success) 18%, transparent);
    color: var(--color-success);
}

.teamhub-mobile-membership-count {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.teamhub-mobile-members-action {
    margin-top: 8px;
}

/* ─── FAB ──────────────────────────────────────────────────── */

.teamhub-mobile-fab {
    position: absolute;
    /* Sit above the icon bar (~64px tall) with breathing room */
    bottom: calc(72px + env(safe-area-inset-bottom, 0px));
    right: 16px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
    cursor: pointer;
    z-index: 11;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.teamhub-mobile-fab:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.22);
}

.teamhub-mobile-fab--active {
    /* Subtle 45° rotation hints "menu opened" without being a full close-X */
    transform: rotate(45deg);
}

.teamhub-mobile-fab--active:hover:not(:disabled) {
    transform: rotate(45deg) translateY(-1px);
}

.teamhub-mobile-fab:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}

.teamhub-mobile-fab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 3px;
}

/* ─── FAB action sheet ─────────────────────────────────────── */

.teamhub-mobile-fab-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.18);
    z-index: 9;
    /* Don't block scrolling underneath when sheet is open — the only goal
       is to capture the dismiss tap. */
    cursor: default;
}

.teamhub-mobile-fab-sheet {
    position: absolute;
    /* Anchor just above the FAB. FAB bottom = 72 + safe-area; sheet sits
       72 + 56 + 8 = 136px (+ safe-area) above the bottom edge so it doesn't
       overlap the FAB itself. */
    bottom: calc(140px + env(safe-area-inset-bottom, 0px));
    right: 16px;
    min-width: 200px;
    max-width: calc(100vw - 32px);
    background: var(--color-main-background);
    border-radius: var(--border-radius-large);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
    border: 1px solid var(--color-border);
    padding: 6px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    z-index: 12;
    /* Slide-up entrance animation */
    animation: teamhub-mobile-sheet-in 0.15s ease-out;
}

@keyframes teamhub-mobile-sheet-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.teamhub-mobile-fab-sheet__item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: transparent;
    border: none;
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    text-align: left;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.12s ease;
}

.teamhub-mobile-fab-sheet__item:hover:not(:disabled) {
    background: var(--color-background-hover);
}

.teamhub-mobile-fab-sheet__item:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.teamhub-mobile-fab-sheet__item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.teamhub-mobile-fab-sheet__label {
    flex: 1;
}

/* ─── Icon bar ─────────────────────────────────────────────── */

.teamhub-mobile-icon-bar {
    flex: 0 0 auto;
    display: flex;
    overflow-x: auto;
    overflow-y: hidden;
    background: var(--color-main-background);
    border-top: 1px solid var(--color-border);
    padding-bottom: env(safe-area-inset-bottom, 0px);
    scrollbar-width: none;
}

.teamhub-mobile-icon-bar::-webkit-scrollbar {
    display: none;
}

.teamhub-mobile-icon-bar__item {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-width: 64px;
    padding: 8px 10px;
    background: transparent;
    border: none;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
}

.teamhub-mobile-icon-bar__item--active {
    color: var(--color-primary-element);
    background: color-mix(in srgb, var(--color-primary-element) 8%, transparent);
}

.teamhub-mobile-icon-bar__item:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.teamhub-mobile-icon-bar__app-icon {
    width: 22px;
    height: 22px;
    object-fit: contain;
}

.teamhub-mobile-icon-bar__label {
    font-size: 11px;
    font-weight: 500;
    line-height: 1.1;
    max-width: 64px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
