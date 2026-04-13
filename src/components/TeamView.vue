<template>
    <div class="teamhub-team-view">

        <!-- ── Tab bar ─────────────────────────────────────────────── -->
        <TeamTabBar
            v-model="orderedTabs"
            :edit-mode="editMode"
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
                :pages-data="pagesData"
                :widget-dynamic-actions="widgetDynamicActions"
                @layout-updated="onLayoutUpdated"
                @manage-team="openManageTeam"
                @copy-link="copyTeamLink"
                @invite="showInviteModal = true"
                @schedule-meeting="showScheduleMeeting = true"
                @add-event="showAddEvent = true"
                @add-task="showAddTask = true"
                @create-page="openCreatePage"
                @delete-page="openDeletePage"
                @pages-loaded="onPagesLoaded"
                @set-view="setView"
                @widget-actions-loaded="onWidgetActionsLoaded" />

            <!-- Activity feed -->
            <ActivityFeedView v-if="currentView === 'activity'" />

            <!-- Embedded NC app views -->
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

            <!-- External menu_item integrations -->
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
                    {{ t('teamhub', 'Select a page to delete. This will also delete all child pages. This action cannot be undone.') }}
                </p>
                <div class="teamhub-page-delete-list">
                    <label
                        v-if="pagesData.teamPage"
                        class="teamhub-page-delete-option"
                        :class="{ 'teamhub-page-delete-option--selected': deletePageTarget && deletePageTarget.uniqueId === pagesData.teamPage.uniqueId }">
                        <input v-model="deletePageTarget" type="radio" :value="pagesData.teamPage" class="teamhub-page-delete-radio" />
                        <FileDocumentOutline :size="16" />
                        <span>{{ pagesData.teamPage.title }}</span>
                        <span class="teamhub-page-delete-hint">{{ t('teamhub', '(main team page + all sub-pages)') }}</span>
                    </label>
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
            @close="showScheduleMeeting = false; $store.dispatch('fetchMessages', currentTeamId)" />

        <AddEventModal v-if="showAddEvent" :team-id="currentTeamId" @close="showAddEvent = false" />

        <AddTaskModal v-if="showAddTask"
            :board-id="resources.deck && resources.deck.board_id"
            @close="showAddTask = false"
            @created="$store.dispatch('fetchDeckTasks', resources.deck && resources.deck.board_id)" />

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
import AddTaskModal from './AddTaskModal.vue'
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
        ScheduleMeetingModal, AddEventModal, AddTaskModal, AppEmbed,
    },

    data() {
        return {
            gridLayout: [],
            orderedTabs: [],
            editMode: false,
            layoutLoaded: false,
            _debouncedSave: null,
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
            showAddTask:         false,
            widgetDynamicActions: {},
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
        currentTeamId(newId) {
            if (newId) {
                this.gridLayout = []
                this.orderedTabs = []
                this.layoutLoaded = false
                this.editMode = false
                this.loadLayout(newId)
            }
        },
        webLinks() { this.syncLinkTabs() },
        externalMenuItems() { this.syncExtTabs() },
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

        setView(view) { this.SET_VIEW(view) },
        toggleEditMode() { this.editMode = !this.editMode },

        async loadLayout(teamId) {
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/layout`))
                this.gridLayout = Array.isArray(data.layout) ? data.layout : []
                this.buildOrderedTabs(Array.isArray(data.tabOrder) ? data.tabOrder : [])
                this.layoutLoaded = true
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
            } catch (err) {}
        },

        onLayoutUpdated(newLayout) {
            this.gridLayout = newLayout
            if (this.editMode && this.layoutLoaded) this._debouncedSave()
        },

        onTabReorder() {
            if (this.layoutLoaded) this._debouncedSave()
        },

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

        menuItemUrl(menuItem) {
            if (!menuItem.iframe_url) return ''
            const sep = menuItem.iframe_url.includes('?') ? '&' : '?'
            return menuItem.iframe_url + sep + 'teamId=' + encodeURIComponent(this.currentTeamId)
        },

        onWidgetActionsLoaded({ registryId, actions }) {
            this.$set(this.widgetDynamicActions, registryId, actions || [])
        },

        openManageTeam() { this.$emit('show-manage-team') },
        onPagesLoaded(data) { this.pagesData = data },
        openCreatePage() { this.newPageTitle = ''; this.showCreatePage = true },
        openDeletePage() { this.deletePageTarget = null; this.showDeletePage = true },

        async submitCreatePage() {
            const title = this.newPageTitle.trim()
            if (!title) return
            this.creatingPage = true
            try {
                const { teamhubRoot, teamPage } = this.pagesData
                const lang = teamhubRoot?.language || 'nl'
                const body = { id: this.toSlug(title), title, language: lang }

                // New pages must be created inside the team's own folder, not the
                // TeamHub root. parentPath must point to the team page so Intravox
                // places the file at: nl/teamhub/<team-slug>/<new-page>.json
                // Using teamhubRoot as parent (the previous behaviour) placed files
                // at nl/teamhub/<new-page>/ — a sibling folder, not a child.
                if (teamPage) {
                    // teamPage.id is the slug (e.g. "gemeentes-extern")
                    const rootId = teamhubRoot?.id || 'teamhub'
                    body.parentPath = `${lang}/${rootId}/${teamPage.id}`
                    console.log('[TeamHub][TeamView] submitCreatePage: parentPath set to team page folder', body.parentPath)
                } else if (teamhubRoot) {
                    // Fallback: no team page yet, parent to the root (shouldn't normally happen)
                    body.parentPath = `${lang}/${teamhubRoot.id || 'teamhub'}`
                    console.log('[TeamHub][TeamView] submitCreatePage: no teamPage found, falling back to root', body.parentPath)
                }

                console.log('[TeamHub][TeamView] submitCreatePage: posting page', body)
                await axios.post(generateUrl('/apps/intravox/api/pages'), body)
                showSuccess(t('teamhub', 'Page "{title}" created', { title }))
                this.showCreatePage = false
                this.$refs.widgetGrid?.refreshIntravox()
            } catch (e) {
                const msg = e?.response?.data?.message || e?.response?.data?.error || ''
                showError(t('teamhub', 'Failed to create page') + (msg ? ': ' + msg : ''))
            } finally {
                this.creatingPage = false
            }
        },

        async submitDeletePage() {
            if (!this.deletePageTarget) return
            const page = this.deletePageTarget
            this.deletingPage = true
            try {
                await axios.delete(generateUrl(`/apps/intravox/api/pages/${page.uniqueId}`))
                showSuccess(t('teamhub', 'Page "{title}" deleted', { title: page.title }))
                this.showDeletePage = false
                this.deletePageTarget = null
                this.$refs.widgetGrid?.refreshIntravox()
            } catch (e) {
                const msg = e?.response?.data?.message || e?.response?.data?.error || ''
                showError(t('teamhub', 'Failed to delete page') + (msg ? ': ' + msg : ''))
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
                navigator.clipboard.writeText(url).then(() => showSuccess(t('teamhub', 'Team link copied to clipboard'))).catch(() => this.fallbackCopy(url))
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
            try { document.execCommand('copy'); showSuccess(t('teamhub', 'Team link copied to clipboard')) } catch { showError(t('teamhub', 'Could not copy link')) }
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

.teamhub-page-delete-hint {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-left: 2px;
}
</style>

<style>
body[data-themes*="dark"] .teamhub-home-view {
    background: #000000;
}
</style>
