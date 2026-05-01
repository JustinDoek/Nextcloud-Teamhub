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
            v-model="orderedTabs"
            :animation="150"
            ghost-class="teamhub-tab-ghost"
            drag-class="teamhub-tab-dragging"
            handle=".teamhub-tab-drag-handle"
            class="teamhub-tab-draggable"
            @end="$emit('tab-reorder', orderedTabs)">
            <template v-for="(tab, tabIndex) in orderedTabs">
                <!-- Built-in: Talk -->
                <button
                    v-if="tab.key === 'talk' && resources.talk && resources.talk.token"
                    id="tab-talk"
                    :key="'tab-talk'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'talk' }"
                    :aria-selected="currentView === 'talk' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('talk')"
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Chat :size="16" />
                    {{ t('teamhub', 'Chat') }}
                </button>

                <!-- Built-in: Files -->
                <button
                    v-else-if="tab.key === 'files' && resources.files && resources.files.path"
                    id="tab-files"
                    :key="'tab-files'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'files' }"
                    :aria-selected="currentView === 'files' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('files')"
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Folder :size="16" />
                    {{ t('teamhub', 'Files') }}
                </button>

                <!-- Built-in: Calendar -->
                <button
                    v-else-if="tab.key === 'calendar' && resources.calendar"
                    id="tab-calendar"
                    :key="'tab-calendar'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'calendar' }"
                    :aria-selected="currentView === 'calendar' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('calendar')"
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <Calendar :size="16" />
                    {{ t('teamhub', 'Calendar') }}
                </button>

                <!-- Built-in: Deck -->
                <button
                    v-else-if="tab.key === 'deck' && resources.deck && resources.deck.board_id"
                    id="tab-deck"
                    :key="'tab-deck'"
                    role="tab"
                    class="teamhub-tab"
                    :class="{ active: currentView === 'deck' }"
                    :aria-selected="currentView === 'deck' ? 'true' : 'false'"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @click="setView('deck')"
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <CardText :size="16" />
                    {{ t('teamhub', 'Deck') }}
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
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
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

                <!-- Web link tabs — navigate externally, no role="tab" -->
                <a
                    v-else-if="tab.key.startsWith('link-')"
                    :key="'tab-' + tab.key"
                    :id="'tab-' + tab.key"
                    :href="tab.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="teamhub-tab teamhub-tab--link"
                    :title="t('teamhub', 'Press left/right arrow to reorder')"
                    @keydown.left.prevent="moveTabLeft(tabIndex)"
                    @keydown.right.prevent="moveTabRight(tabIndex)">
                    <span class="teamhub-tab-drag-handle" aria-hidden="true">⠿</span>
                    <OpenInNew :size="14" />
                    {{ tab.label }}
                </a>
            </template>
        </draggable>

        <NcButton
            class="teamhub-tab-add"
            type="tertiary"
            :aria-label="t('teamhub', 'Manage links')"
            @click="$emit('manage-links')">
            <template #icon><Plus :size="18" /></template>
        </NcButton>

        <!-- Edit layout toggle — shown only on Home view -->
        <NcButton
            v-if="currentView === 'msgstream'"
            class="teamhub-edit-layout-btn"
            :type="editMode ? 'primary' : 'tertiary'"
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

export default {
    name: 'TeamTabBar',

    components: {
        NcButton,
        draggable,
        MessageOutline, Chat, Folder, Calendar, CardText,
        OpenInNew, Plus, Puzzle, ViewDashboardEdit,
    },

    props: {
        value: { type: Array, required: true },
        editMode: { type: Boolean, default: false },
    },

    emits: ['input', 'tab-reorder', 'manage-links', 'toggle-edit-mode'],

    computed: {
        ...mapState(['currentView', 'resources']),

        orderedTabs: {
            get() { return this.value },
            set(val) { this.$emit('input', val) },
        },
    },

    methods: {
        t,

        setView(view) {
            this.$store.commit('SET_VIEW', view)
        },

        /**
         * Move focused tab one position left.
         * Triggered by Left arrow keydown (WCAG 2.5.7).
         */
        moveTabLeft(index) {
            if (index === 0) return
            const movedKey = this.orderedTabs[index].key
            const tabs = [...this.orderedTabs]
            ;[tabs[index - 1], tabs[index]] = [tabs[index], tabs[index - 1]]
            this.orderedTabs = tabs
            this.$emit('tab-reorder', tabs)
            // Restore focus to the moved tab after Vue re-renders the list
            this.$nextTick(() => {
                const el = document.getElementById('tab-' + movedKey)
                if (el) el.focus()
            })
        },

        /**
         * Move focused tab one position right.
         * Triggered by Right arrow keydown (WCAG 2.5.7).
         */
        moveTabRight(index) {
            if (index >= this.orderedTabs.length - 1) return
            const movedKey = this.orderedTabs[index].key
            const tabs = [...this.orderedTabs]
            ;[tabs[index], tabs[index + 1]] = [tabs[index + 1], tabs[index]]
            this.orderedTabs = tabs
            this.$emit('tab-reorder', tabs)
            // Restore focus to the moved tab after Vue re-renders the list
            this.$nextTick(() => {
                const el = document.getElementById('tab-' + movedKey)
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
                this.$set(tab, 'appId', null)
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
