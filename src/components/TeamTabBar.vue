<template>
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
            @end="$emit('tab-reorder', orderedTabs)">
            <template v-for="tab in orderedTabs">
                <!-- Built-in: Talk -->
                <button
                    v-if="tab.key === 'talk' && resources.talk && resources.talk.token"
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
                    v-else-if="tab.key === 'files' && resources.files && resources.files.path"
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
                    v-else-if="tab.key === 'calendar' && resources.calendar"
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
                    v-else-if="tab.key === 'deck' && resources.deck && resources.deck.board_id"
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
                    <img
                        v-if="tab.appId"
                        :src="appIconUrl(tab.appId)"
                        :alt="tab.label"
                        class="teamhub-tab-app-icon"
                        @error="onTabIconError($event, tab)" />
                    <Puzzle v-else :size="16" />
                    {{ tab.label }}
                </button>

                <!-- Web link tabs -->
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
        /** Ordered tab descriptors from parent's layout state */
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
