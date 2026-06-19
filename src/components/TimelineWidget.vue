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

.th-tl__iframe {
    flex: 1;
    width: 100%;
    border: none;
    display: block;
    min-height: 320px;
    background: #fff;
}

.th-tl__empty {
    padding: 24px 12px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    text-align: center;
}
</style>
