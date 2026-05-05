<template>
    <div class="teamhub-team-view">

        <!-- ── Tab bar ─────────────────────────────────────────────── -->
        <TeamTabBar
            v-model="orderedTabs"
            :edit-mode="editMode"
            :is-mobile="isMobile"
            :is-tablet="isTablet"
            @tab-reorder="onTabReorder"
            @manage-links="showManageLinks = true"
            @toggle-edit-mode="toggleEditMode" />

        <!-- ── Content area ─────────────────────────────────────────── -->
        <div class="teamhub-content">

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
                @manage-team="openManageTeam"
                @copy-link="copyTeamLink"
                @invite="showInviteModal = true"
                @schedule-meeting="showScheduleMeeting = true"
                @add-event="showAddEvent = true"
                @team-meeting="showTeamMeeting = true"
                @add-deck-task="showAddTask = true"
                @add-personal-task="showAddPersonalTask = true"
                @create-page="openCreatePage"
                @delete-page="openDeletePage"
                @pages-loaded="onPagesLoaded"
                @set-view="setView"
                @widget-actions-loaded="onWidgetActionsLoaded"
                @leave-team="onLeaveTeam"
                @set-as-default="setAsDefault"
                @reset-to-default="resetToDefault" />

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
                v-if="(preloadedViews.has('files') || currentView === 'files') && resources.files"
                v-show="currentView === 'files'"
                :url="filesUrl"
                :label="t('teamhub', 'Files')" />
            <AppEmbed
                v-if="preloadedViews.has('calendar') || currentView === 'calendar'"
                v-show="currentView === 'calendar'"
                :url="calendarUrl"
                :label="t('teamhub', 'Calendar')" />
            <AppEmbed
                v-if="(preloadedViews.has('deck') || currentView === 'deck') && resources.deck"
                v-show="currentView === 'deck'"
                :url="deckUrl"
                :label="t('teamhub', 'Deck')" />

            <!-- External menu_item integrations — preloaded by registry_id -->
            <template v-for="menuItem in externalMenuItems">
                <AppEmbed
                    v-if="(preloadedViews.has('ext-' + menuItem.registry_id) || currentView === 'ext-' + menuItem.registry_id) && menuItem.iframe_url"
                    v-show="currentView === 'ext-' + menuItem.registry_id"
                    :key="'ext-canvas-' + menuItem.registry_id"
                    :url="menuItemUrl(menuItem)"
                    :label="menuItem.title" />
            </template>
        </div>

        <!-- ── Modals ─────────────────────────────────────────────── -->
        <ManageLinksModal v-if="showManageLinks" @close="showManageLinks = false" />

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
                <NcButton type="tertiary" @click="showCreatePage = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton type="primary" :disabled="!newPageTitle.trim() || creatingPage" @click="submitCreatePage">
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
                <NcButton type="tertiary" @click="showDeletePage = false">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton type="error" :disabled="!deletePageTarget || deletingPage" @click="submitDeletePage">
                    <template #icon><NcLoadingIcon v-if="deletingPage" :size="20" /></template>
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>

        <InviteMemberModal v-if="showInviteModal" :team-id="currentTeamId"
            @close="showInviteModal = false"
            @invited="$store.dispatch('fetchMembers', currentTeamId)" />

        <ScheduleMeetingModal v-if="showScheduleMeeting" :team-id="currentTeamId"
            @close="showScheduleMeeting = false; $store.dispatch('fetchMessages', currentTeamId); $refs.widgetGrid?.refreshCalendar()" />

        <AddEventModal v-if="showAddEvent" :team-id="currentTeamId"
            @close="showAddEvent = false; $refs.widgetGrid?.refreshCalendar()" />

        <TeamMeetingModal v-if="showTeamMeeting" :team-id="currentTeamId" :resources="resources"
            @close="showTeamMeeting = false; $refs.widgetGrid?.refreshCalendar()" />

        <AddTaskModal v-if="showAddTask"
            :board-id="resources.deck && resources.deck.board_id"
            @close="showAddTask = false"
            @created="$store.dispatch('fetchDeckTasks', resources.deck && resources.deck.board_id)" />

        <AddPersonalTaskModal v-if="showAddPersonalTask"
            :team-id="currentTeamId"
            @close="showAddPersonalTask = false"
            @created="$store.dispatch('fetchTeamTasks', currentTeamId)" />

    </div>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcDialog, NcTextField, NcLoadingIcon } from '@nextcloud/vue'

import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'

import TeamTabBar from './TeamTabBar.vue'
import TeamWidgetGrid from './TeamWidgetGrid.vue'
import ActivityFeedView from './ActivityFeedView.vue'
import ManageLinksModal from './ManageLinksModal.vue'
import InviteMemberModal from './InviteMemberModal.vue'
import ScheduleMeetingModal from './ScheduleMeetingModal.vue'
import AddEventModal from './AddEventModal.vue'
import TeamMeetingModal from './TeamMeetingModal.vue'
import AddTaskModal from './AddTaskModal.vue'
import AddPersonalTaskModal from './AddPersonalTaskModal.vue'
import AppEmbed from './AppEmbed.vue'

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
        NcButton, NcDialog, NcTextField, NcLoadingIcon,
        FileDocumentOutline,
        TeamTabBar, TeamWidgetGrid,
        ActivityFeedView, ManageLinksModal, InviteMemberModal,
        ScheduleMeetingModal, AddEventModal, AddTaskModal, AddPersonalTaskModal, AppEmbed,
        TeamMeetingModal,
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
            showScheduleMeeting: false,
            showAddEvent:        false,
            showTeamMeeting:     false,
            showAddTask:         false,
            showAddPersonalTask: false,
            widgetDynamicActions: {},
            // Set of view keys whose iframe has been rendered at least once.
            // Once a view is in this set the AppEmbed is kept in the DOM
            // (v-show) rather than destroyed, so tab switches are instant.
            preloadedViews: new Set(),
        }
    },

    computed: {
        ...mapState([
            'currentTeamId', 'currentView', 'resources', 'webLinks',
            'members', 'loading', 'intravoxAvailable', 'teamWidgets', 'teamMenuItems',
        ]),
        ...mapGetters(['currentTeam']),

        talkUrl() {
            const token = this.resources.talk?.token
            return token ? generateUrl('/call/' + token) : generateUrl('/apps/spreed')
        },
        filesUrl() {
            const path = this.resources.files?.path || '/'
            return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(path)
        },
        calendarUrl() {
            // Always use the full authenticated Calendar app so events are
            // editable. The public token URL (/apps/calendar/p/{token}) was
            // read-only by design — dropped in v3.18.3.
            return generateUrl('/apps/calendar')
        },
        deckUrl() {
            const id = this.resources.deck?.board_id
            return generateUrl('/apps/deck') + (id ? '/#/board/' + id : '/')
        },
        externalMenuItems() {
            return (this.teamMenuItems || []).filter(item => !item.is_builtin)
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
                if (cur.x !== d.x || cur.w !== d.w || cur.h !== d.h || cur.collapsed !== d.collapsed) {
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
                this.loadLayout(newId)
            }
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
        const builtinViews = ['talk', 'files', 'calendar', 'deck']
        builtinViews.forEach((view, i) => {
            setTimeout(() => {
                if (!this.preloadedViews.has(view)) {
                    const next = new Set(this.preloadedViews)
                    next.add(view)
                    this.preloadedViews = next
                }
            }, 1500 + i * 800)
        })
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
    },

    methods: {
        t,
        ...mapActions(['selectTeam']),
        ...mapMutations(['SET_VIEW']),

        setView(view) { this.SET_VIEW(view) },
        toggleEditMode() { this.editMode = !this.editMode },

        // ── Layout load / save ──────────────────────────────────────

        async loadLayout(teamId) {
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/layout`))
                this.gridLayout        = Array.isArray(data.layout)      ? data.layout      : []
                this.userDefaultLayout = Array.isArray(data.userDefault) ? data.userDefault : []
                this.buildOrderedTabs(Array.isArray(data.tabOrder) ? data.tabOrder : [])
                this.layoutLoaded = true
                this.applySnap()
            } catch (err) {
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
            }
        },

        onLayoutUpdated(newLayout) {
            this.gridLayout = newLayout
            if (this.editMode && this.layoutLoaded) this._debouncedSave()
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
            if (this.resources && this.resources.calendar) active.add('widget-calendar')
            // Tasks widget shows for Deck OR when Tasks app + calendar are both active.
            if (this.resources && (this.resources.deck || (this.resources.tasks && this.resources.calendar))) {
                active.add('widget-deck')
            }
            if (this.resources && this.resources.intravox) active.add('widget-pages')
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
            ;(this.teamMenuItems || []).filter(item => !item.is_builtin)
                .forEach(item => tabs.push({ key: 'ext-' + item.registry_id, label: item.title, icon: item.icon || 'Puzzle', appId: item.app_id || null }))
            ;(this.webLinks || []).forEach(link => tabs.push({ key: 'link-' + link.id, label: link.title, url: link.url }))
            return tabs
        },

        syncLinkTabs() {
            const linkTabs = (this.webLinks || []).map(link => ({ key: 'link-' + link.id, label: link.title, url: link.url }))
            this.orderedTabs = [...this.orderedTabs.filter(t => !t.key.startsWith('link-')), ...linkTabs]
        },

        syncExtTabs() {
            const extTabs = (this.teamMenuItems || []).filter(item => !item.is_builtin)
                .map(item => ({ key: 'ext-' + item.registry_id, label: item.title, icon: item.icon || 'Puzzle', appId: item.app_id || null }))
            const builtinKeys = new Set(['talk', 'files', 'calendar', 'deck'])
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
                console.warn('[TeamHub][TeamView] Rejected unsafe iframe_url for registry_id=' + menuItem.registry_id + ': scheme not allowed')
                return ''
            }

            const sep = raw.includes('?') ? '&' : '?'
            return raw + sep + 'teamId=' + encodeURIComponent(this.currentTeamId)
        },

        // ── Widget / team actions ───────────────────────────────────

        onWidgetActionsLoaded({ registryId, actions }) {
            this.$set(this.widgetDynamicActions, registryId, actions || [])
        },

        openManageTeam() { this.$emit('show-manage-team') },

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
            this.$set(this.pagesData, 'teamPage',    data.teamPage    || null)
            this.$set(this.pagesData, 'subPages',    data.subPages    || [])
            this.$set(this.pagesData, 'teamhubRoot', data.teamhubRoot || null)
            this.$set(this.pagesData, 'allPages',    data.allPages    || [])
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
    font-size: 14px;
    transition: border-color 0.15s, background 0.15s;
}

.teamhub-page-delete-option:hover {
    border-color: var(--color-primary-element);
    background: var(--color-background-hover);
}

.teamhub-page-delete-option--selected {
    border-color: var(--color-error);
    background: color-mix(in srgb, var(--color-error) 6%, transparent);
}

.teamhub-page-delete-radio { display: none; }
</style>

<style>
body[data-themes*="dark"] .teamhub-home-view {
    background: #000000;
}

/* Mobile keeps the standard NC theme background regardless of dark mode —
   the MobileWidgetView body itself already adapts to dark mode through
   var(--color-main-background). */
body[data-themes*="dark"] .teamhub-home-view--mobile {
    background: var(--color-main-background);
}
</style>
