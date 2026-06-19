<template>
    <div class="teamhub-tab-bar" role="tablist" :aria-label="t('teamhub', 'Team navigation')">
        <!--
            Home tab — always first, not reorderable.
        -->
        <button
            id="tab-msgstream"
            role="tab"
            class="teamhub-tab"
            :class="{ active: currentView === 'msgstream' }"
            :aria-selected="currentView === 'msgstream' ? 'true' : 'false'"
            @click="setView('msgstream')">
            <MessageOutline :size="16" />
            {{ t('teamhub', 'Home') }}
        </button>

        <!--
            Draggable tabs.
            Mouse: drag using the ⠿ handle.
            Keyboard: Tab/Shift+Tab to focus a tab, then Left/Right arrow to reorder.
        -->
        <draggable
            v-model="renderableTabs"
            :animation="150"
            ghost-class="teamhub-tab-ghost"
            drag-class="teamhub-tab-dragging"
            handle=".teamhub-tab-drag-handle"
            class="teamhub-tab-draggable"
            item-key="key"
            @end="$emit('tab-reorder', orderedTabs)">
            <template #item="{ element: tab, index: tabIndex }">
                <!-- Built-in: Talk -->
                <button
                    v-if="tab.key === 'talk'"
                    id="tab-talk"
                    :key="'tab-talk'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'talk' }"
                    :aria-selected="currentView === 'talk' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('talk')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Chat :size="16" />
                    {{ t('teamhub', 'Chat') }}
                </button>

                <!-- Built-in: Files -->
                <button
                    v-else-if="tab.key === 'files'"
                    id="tab-files"
                    :key="'tab-files'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'files' }"
                    :aria-selected="currentView === 'files' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('files')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Folder :size="16" />
                    {{ t('teamhub', 'Files') }}
                </button>

                <!-- Built-in: Calendar -->
                <button
                    v-else-if="tab.key === 'calendar'"
                    id="tab-calendar"
                    :key="'tab-calendar'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'calendar' }"
                    :aria-selected="currentView === 'calendar' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="onCalendarTabClick"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Calendar :size="16" />
                    {{ t('teamhub', 'Calendar') }}
                    <span v-if="resources.calendar.length > 1" class="teamhub-tab-count" :aria-label="t('teamhub', '{n} calendars', { n: resources.calendar.length })">{{ resources.calendar.length }}</span>
                </button>

                <!-- Built-in: Deck -->
                <button
                    v-else-if="tab.key === 'deck'"
                    id="tab-deck"
                    :key="'tab-deck'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'deck' }"
                    :aria-selected="currentView === 'deck' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="onDeckTabClick"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <CardText :size="16" />
                    {{ t('teamhub', 'Deck') }}
                    <span v-if="resources.deck.length > 1" class="teamhub-tab-count" :aria-label="t('teamhub', '{n} boards', { n: resources.deck.length })">{{ resources.deck.length }}</span>
                </button>

                <!-- Built-in: Presence -->
                <button
                    v-else-if="tab.key === 'presence'"
                    id="tab-presence"
                    :key="'tab-presence'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'presence' }"
                    :aria-selected="currentView === 'presence' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('presence')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <OfficeBuildingIcon :size="16" />
                    {{ t('teamhub', 'Presence') }}
                </button>

                <!-- Built-in: Decisions -->
                <button
                    v-else-if="tab.key === 'decisions'"
                    id="tab-decisions"
                    :key="'tab-decisions'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'decisions' }"
                    :aria-selected="currentView === 'decisions' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('decisions')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <GavelIcon :size="16" />
                    {{ t('teamhub', 'Decisions') }}
                </button>

                <!-- Timeline tab — unified visual timeline of Calendar, Deck and Decisions -->
                <button
                    v-else-if="tab.key === 'timeline'"
                    id="tab-timeline"
                    :key="'tab-timeline'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'timeline' }"
                    :aria-selected="currentView === 'timeline' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('timeline')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <TimelineIcon :size="16" />
                    {{ t('teamhub', 'Timeline') }}
                </button>

                <!-- External app tabs -->
                <button
                    v-else-if="tab.key.startsWith('ext-')"
                    :id="'tab-' + tab.key"
                    :key="'tab-' + tab.key"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === tab.key }"
                    :aria-selected="currentView === tab.key ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView(tab.key)"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <img
                        v-if="tab.appId"
                        :src="appIconUrl(tab.appId)"
                        :alt="tab.label"
                        class="teamhub-tab-app-icon"
                        @error="onTabIconError($event, tab)" />
                    <Puzzle v-else :size="16" />
                    {{ tab.label }}
                </button>

                <!-- NC-relative web link tabs — open in iframe, behave like built-in tabs -->
                <button
                    v-else-if="tab.key.startsWith('link-') && tab.isNcRelative"
                    :key="'tab-' + tab.key"
                    :id="'tab-' + tab.key"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === tab.key }"
                    :aria-selected="currentView === tab.key ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView(tab.key)"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Web :size="14" />
                    {{ tab.label }}
                </button>

                <!-- External web link tabs — open in new browser tab -->
                <a
                    v-else-if="tab.key.startsWith('link-')"
                    :key="'tab-' + tab.key"
                    :id="'tab-' + tab.key"
                    :href="tab.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="teamhub-tab teamhub-tab--link"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @keydown.left.prevent="moveTab(tab, -1)"
                    @keydown.right.prevent="moveTab(tab, 1)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <OpenInNew :size="14" />
                    {{ tab.label }}
                </a>
            </template>
        </draggable>

        <NcButton
            v-if="canManageLinks"
            class="teamhub-tab-add"
            variant="tertiary"
            :aria-label="t('teamhub', 'Manage links')"
            @click="$emit('manage-links')">
            <template #icon><Plus :size="18" /></template>
        </NcButton>

        <!-- Edit layout toggle — shown only on Home view, and only on
             non-mobile viewports. The mobile layout is fixed (one canvas
             per widget) and not user-arrangeable, so the button is hidden
             rather than disabled to keep the bar clean. -->
        <NcButton
            v-if="currentView === 'msgstream' && !isMobile && !isTablet"
            class="teamhub-edit-layout-btn"
            :variant="editMode ? 'primary' : 'tertiary'"
            :aria-label="editMode ? t('teamhub', 'Done editing layout') : t('teamhub', 'Edit layout')"
            @click="$emit('toggle-edit-mode')">
            <template #icon><ViewDashboardEdit :size="18" /></template>
            {{ editMode ? t('teamhub', 'Done') : t('teamhub', 'Edit layout') }}
        </NcButton>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { mapState } from 'vuex'
import { NcButton } from '@nextcloud/vue'
import draggable from 'vuedraggable'

import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CardText from 'vue-material-design-icons/CardText.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import ViewDashboardEdit from 'vue-material-design-icons/ViewDashboardEdit.vue'
import Web from 'vue-material-design-icons/Web.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import GavelIcon          from 'vue-material-design-icons/Gavel.vue'
import TimelineIcon       from 'vue-material-design-icons/TimelineCheckOutline.vue'

export default {
    name: 'TeamTabBar',

    components: {
        NcButton,
        draggable,
        MessageOutline, Chat, Folder, Calendar, CardText,
        OpenInNew, Plus, Puzzle, ViewDashboardEdit, Web, OfficeBuildingIcon, GavelIcon,
        TimelineIcon,
    },

    props: {
        modelValue: { type: Array, required: true },
        editMode: { type: Boolean, default: false },
        // True when the viewport ≤ 768px or tablet portrait — hides Edit layout button.
        isMobile: { type: Boolean, default: false },
        // True when tablet landscape (≤1200px landscape) — also hides Edit layout button.
        isTablet: { type: Boolean, default: false },
        // Whether the current user can manage (add/edit/delete) links.
        // Controlled by the per-team linkMinLevel setting.
        canManageLinks: { type: Boolean, default: false },
    },

    emits: ['update:modelValue', 'tab-reorder', 'manage-links', 'toggle-edit-mode', 'show-picker'],

    computed: {
        ...mapState(['currentView', 'resources']),

        orderedTabs: {
            get() { return this.modelValue },
            set(val) { this.$emit('update:modelValue', val) },
        },

        /**
         * The subset of orderedTabs that can actually render, in order.
         * vuedraggable v4's #item slot must produce exactly one node per
         * item, so resource-gated tabs (Talk without a token, Files without
         * a path, empty Calendar/Deck) are filtered out here rather than via
         * v-if inside the slot. The getter applies the same conditions the
         * template chain used in Vue 2; the setter maps a drag-reordered
         * renderable list back onto the full orderedTabs (preserving the
         * relative position of any hidden tabs).
         */
        renderableTabs: {
            get() {
                return this.modelValue.filter(tab => this.isTabRenderable(tab))
            },
            set(reordered) {
                // Rebuild the full list: keep non-renderable tabs in place,
                // apply the new order to the renderable ones.
                const renderableKeys = new Set(reordered.map(t => t.key))
                const result = []
                let ri = 0
                for (const tab of this.modelValue) {
                    if (renderableKeys.has(tab.key)) {
                        result.push(reordered[ri++])
                    } else {
                        result.push(tab)
                    }
                }
                this.$emit('update:modelValue', result)
            },
        },
    },

    methods: {
        t,

        /**
         * Whether a tab can render, mirroring the resource conditions the
         * Vue 2 template chain checked inline. Built-in tabs are gated on
         * their backing resource; presence, external app tabs and link tabs
         * always render.
         * @param {object} tab - a tab descriptor from orderedTabs
         * @returns {boolean}
         */
        isTabRenderable(tab) {
            switch (tab.key) {
            case 'talk':
                return !!(this.resources.talk && this.resources.talk.token)
            case 'files':
                return !!(this.resources.files && this.resources.files.path)
            case 'calendar':
                return !!(this.resources.calendar && this.resources.calendar.length > 0)
            case 'deck':
                return !!(this.resources.deck && this.resources.deck.length > 0)
            case 'presence':
                return true
            case 'decisions':
                return true
            case 'timeline':
                return true
            default:
                // ext-* and link-* tabs always render
                return tab.key.startsWith('ext-') || tab.key.startsWith('link-')
            }
        },

        setView(view) {
            // Clicking the Files tab always returns to the team folder, even if
            // a file widget had embedded a specific file. (SET_VIEW only clears
            // the override when switching to a *different* view, so clicking the
            // already-active Files tab would otherwise keep showing the file.)
            if (view === 'files') {
                this.$store.commit('SET_FILES_EMBED_FILE_URL', null)
            }
            this.$store.commit('SET_VIEW', view)
        },

        onCalendarTabClick() {
            if (this.resources.calendar && this.resources.calendar.length > 1) {
                this.$emit('show-picker', 'calendar')
            } else {
                this.setView('calendar')
            }
        },

        onDeckTabClick() {
            if (this.resources.deck && this.resources.deck.length > 1) {
                this.$emit('show-picker', 'deck')
            } else {
                this.setView('deck')
            }
        },

        /**
         * Move focused tab one position left.
         * Triggered by Left arrow keydown (WCAG 2.5.7).
         */
        /**
         * Move a tab one position left (dir=-1) or right (dir=+1) within the
         * visible (renderable) tab order. WCAG 2.5.7 keyboard alternative to
         * drag reordering. Operates by key on renderableTabs so the index
         * always matches what the user sees, then writes back via the
         * renderableTabs setter (which maps onto the full orderedTabs).
         * @param {object} tab - the focused tab descriptor
         * @param {number} dir - -1 for left, +1 for right
         */
        moveTab(tab, dir) {
            const list = [...this.renderableTabs]
            const index = list.findIndex(t => t.key === tab.key)
            const target = index + dir
            if (index === -1 || target < 0 || target >= list.length) return
            ;[list[index], list[target]] = [list[target], list[index]]
            this.renderableTabs = list
            this.$emit('tab-reorder', this.orderedTabs)
            // Restore focus to the moved tab after Vue re-renders the list
            this.$nextTick(() => {
                const el = document.getElementById('tab-' + tab.key)
                if (el) el.focus()
            })
        },

        appIconUrl(appId) {
            return generateUrl(`/apps/${appId}/img/app.svg`)
        },

        onTabIconError(event, tab) {
            const img = event.target
            if (img.src.endsWith('.svg')) {
                img.src = img.src.replace('.svg', '.png')
            } else {
                img.style.display = 'none'
                tab.appId = null
            }
        },
    },
}
</script>

<style scoped>
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

/* Draggable wrapper is invisible to the tab bar's flex layout */
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

/* Keyboard focus ring (WCAG 2.4.7) */
.teamhub-tab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.teamhub-tab--link {
    opacity: 0.85;
    border: 1px solid var(--color-border);
}

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

.teamhub-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 8px;
    background: var(--color-primary);
    color: var(--color-primary-text);
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    flex-shrink: 0;
}

.teamhub-tab-app-icon {
    width: 16px;
    height: 16px;
    object-fit: contain;
    flex-shrink: 0;
}

.teamhub-edit-layout-btn {
    flex-shrink: 0;
    margin-left: auto;
    white-space: nowrap;
}
</style>
