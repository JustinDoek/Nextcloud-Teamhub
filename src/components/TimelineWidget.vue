<template>
    <div class="th-tl">
        <iframe
            v-if="currentTeamId"
            :src="timelineUrl"
            class="th-tl__iframe"
            frameborder="0"
            :title="t('teamhub', 'Team Timeline')"
            referrerpolicy="same-origin"
            sandbox="allow-same-origin allow-scripts" />
        <div v-else class="th-tl__empty">
            {{ t('teamhub', 'Select a team to view its timeline') }}
        </div>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl }    from '@nextcloud/router'
import { mapState }       from 'vuex'

export default {
    name: 'TimelineWidget',

    computed: {
        ...mapState(['currentTeamId']),

        /**
         * URL of the standalone timeline page served by PageController::timeline().
         * Changing the teamId causes the iframe to reload with the new team's data.
         */
        timelineUrl() {
            if (!this.currentTeamId) return ''
            return generateUrl(`/apps/teamhub/timeline/${this.currentTeamId}`)
        },
    },

    methods: { t },
}
</script>

<style scoped>
.th-tl {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 320px;
}

/* v3.100.16: iframe wrapper background — theme-safe token (was #fff,
   which pinned white even in dark mode). The timeline.php content
   inside still has its own opaque bg, so this only shows in the split
   second before the iframe paints — but keeping it aligned with the
   NC theme prevents a flash of light on a dark-mode canvas. */
.th-tl__iframe {
    flex: 1;
    width: 100%;
    border: none;
    display: block;
    min-height: 320px;
    background: var(--color-main-background);
}

.th-tl__empty {
    padding: 24px 12px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    text-align: center;
}
</style>
