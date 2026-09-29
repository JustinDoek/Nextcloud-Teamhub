<template>
    <div class="th-files-widget">

        <!-- Tab bar -->
        <div class="th-files-widget__tabs" role="tablist" :aria-label="t('teamhub', 'File views')">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                role="tab"
                :aria-selected="activeTab === tab.id"
                :aria-controls="`th-files-panel-${tab.id}`"
                :id="`th-files-tab-${tab.id}`"
                class="th-files-widget__tab"
                :class="{ 'th-files-widget__tab--active': activeTab === tab.id }"
                @click="setTab(tab.id)">
                <component :is="tab.icon" :size="ICON_INLINE" aria-hidden="true" />
                {{ tab.label }}
            </button>
        </div>

        <!-- Tab panels -->
        <div
            id="th-files-panel-favorites"
            role="tabpanel"
            :aria-labelledby="'th-files-tab-favorites'"
            :hidden="activeTab !== 'favorites'">
            <FilesFavoritesWidget v-if="activeTab === 'favorites'" />
        </div>
        <div
            id="th-files-panel-recent"
            role="tabpanel"
            :aria-labelledby="'th-files-tab-recent'"
            :hidden="activeTab !== 'recent'">
            <FilesRecentWidget v-if="activeTab === 'recent'" />
        </div>
        <div
            id="th-files-panel-shared"
            role="tabpanel"
            :aria-labelledby="'th-files-tab-shared'"
            :hidden="activeTab !== 'shared'">
            <FilesSharedWidget v-if="activeTab === 'shared'" />
        </div>

    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { ICON_INLINE } from '../constants/uiTokens.js'

import FilesFavoritesWidget from './FilesFavoritesWidget.vue'
import FilesRecentWidget    from './FilesRecentWidget.vue'
import FilesSharedWidget    from './FilesSharedWidget.vue'

import StarOutlineIcon    from 'vue-material-design-icons/StarOutline.vue'
import ClockOutlineIcon   from 'vue-material-design-icons/ClockOutline.vue'
import ShareVariantIcon   from 'vue-material-design-icons/ShareVariant.vue'

export default {
    name: 'FilesWidget',

    components: {
        FilesFavoritesWidget,
        FilesRecentWidget,
        FilesSharedWidget,
        StarOutlineIcon,
        ClockOutlineIcon,
        ShareVariantIcon,
    },

    data() {
        return {
            ICON_INLINE,
            activeTab: 'recent',
        }
    },

    computed: {
        tabs() {
            return [
                {
                    id: 'favorites',
                    // TRANSLATORS: tab label — files the user has starred/favourited
                    label: t('teamhub', 'Favourite files'),
                    icon: 'StarOutlineIcon',
                },
                {
                    id: 'recent',
                    // TRANSLATORS: tab label — files most recently changed in the team folder
                    label: t('teamhub', 'Recently modified'),
                    icon: 'ClockOutlineIcon',
                },
                {
                    id: 'shared',
                    // TRANSLATORS: tab label — files shared directly with this team
                    label: t('teamhub', 'Shared files'),
                    icon: 'ShareVariantIcon',
                },
            ]
        },
    },

    methods: {
        t,

        setTab(id) {
            this.activeTab = id
        },
    },
}
</script>

<style scoped>
.th-files-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
}

/* ── Tab bar ── */
.th-files-widget__tabs {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--color-border);
    padding: 0 4px;
    gap: 4px;
    flex-shrink: 0;
}

.th-files-widget__tab {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 8px 8px 8px;
    /* Tokens — tabs use row-meta size at row-primary weight */
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-text-maxcontrast);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    border-radius: var(--border-radius-small) var(--border-radius-small) 0 0;
    white-space: nowrap;
    transition: color var(--animation-quick), border-color var(--animation-quick), background var(--animation-quick);
    line-height: 1;
}

.th-files-widget__tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.th-files-widget__tab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.th-files-widget__tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: var(--th-widget-title-weight);
}

/* ── Tab panels ── */
/* Hidden panels are removed from layout via hidden attribute */
[hidden] { display: none; }
</style>
