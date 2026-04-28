<template>
    <NcAppNavigationItem
        :name="widget.title"
        :allow-collapse="true"
        :open="true"
        class="teamhub-widget-item teamhub-external-widget">
        <!-- Icon: use the registered MDI icon name when set, otherwise fall back to Widgets -->
        <template #icon>
            <component :is="resolvedIcon" :size="20" />
        </template>

        <template #default>
            <div class="teamhub-external-widget__body">
                <!-- Loading state shown while iframe is initialising -->
                <div v-if="loading" class="teamhub-external-widget__loading">
                    <NcLoadingIcon :size="24" />
                </div>

                <iframe
                    v-show="!loading"
                    :src="iframeSrc"
                    :title="widget.title"
                    class="teamhub-external-widget__iframe"
                    sandbox="allow-scripts allow-forms allow-popups"
                    referrerpolicy="strict-origin-when-cross-origin"
                    @load="onLoad"
                    @error="onError" />

                <p v-if="loadError" class="teamhub-external-widget__error">
                    {{ t('teamhub', 'Widget failed to load') }}
                </p>
            </div>
        </template>
    </NcAppNavigationItem>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppNavigationItem, NcLoadingIcon } from '@nextcloud/vue'

// Fallback icon used when the registered icon name is empty or not resolvable.
import WidgetsIcon from 'vue-material-design-icons/Widgets.vue'

export default {
    name: 'ExternalWidgetItem',

    components: {
        NcAppNavigationItem,
        NcLoadingIcon,
        WidgetsIcon,
    },

    props: {
        /**
         * A single row from GET /api/v1/teams/{teamId}/widgets.
         * Shape: { registry_id, team_id, app_id, title, icon, iframe_url, sort_order }
         */
        widget: {
            type: Object,
            required: true,
        },
        teamId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            loading: true,
            loadError: false,
        }
    },

    computed: {
        /**
         * Append ?teamId=<id> to the registered iframe_url.
         * The external app uses this to scope its content to the correct team.
         */
        iframeSrc() {
            if (!this.widget.iframe_url) {
                return ''
            }
            const base = this.widget.iframe_url
            const separator = base.includes('?') ? '&' : '?'
            return `${base}${separator}teamId=${encodeURIComponent(this.teamId)}`
        },

        /**
         * Resolve the icon component by name.
         * The registered icon field is an MDI component name (e.g. 'Widgets').
         * We only support a curated set that we can statically import to keep
         * the bundle size predictable. Unknown names fall back to WidgetsIcon.
         *
         * Extend this map when new widget icon options are needed.
         */
        resolvedIcon() {
            const iconMap = {
                Widgets: 'WidgetsIcon',
            }
            const iconName = this.widget.icon
            if (iconName && iconMap[iconName]) {
                return iconMap[iconName]
            }
            return 'WidgetsIcon'
        },
    },

    methods: {
        t,

        onLoad() {
            this.loading   = false
            this.loadError = false
        },

        onError() {
            this.loading   = false
            this.loadError = true
        },
    },
}
</script>

<style scoped>
.teamhub-external-widget__body {
    position: relative;
    width: 100%;
}

.teamhub-external-widget__loading {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.teamhub-external-widget__iframe {
    width: 100%;
    /* Compact default height — external apps should be designed for this. */
    height: 200px;
    border: none;
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    display: block;
}

.teamhub-external-widget__error {
    font-size: 13px;
    color: var(--color-error-text);
    padding: 8px 0;
    margin: 0;
}
</style>
