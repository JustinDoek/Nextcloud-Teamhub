<template>
    <HelpCircleOutline
        v-if="unknown"
        :size="ICON_INLINE"
        class="op-mark op-mark--unknown"
        :title="t('teamhub', 'Unknown')" />
    <CheckCircleOutline
        v-else-if="ok"
        :size="ICON_INLINE"
        class="op-mark op-mark--ok"
        :title="t('teamhub', 'OK')" />
    <CloseCircleOutline
        v-else
        :size="ICON_INLINE"
        class="op-mark op-mark--bad"
        :title="t('teamhub', 'Problem')" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import { ICON_INLINE } from '../constants/uiTokens.js'

/**
 * OpenProjectStatusMark (v4.9.3) — the ✓ / ✕ / ? glyph in front of each
 * status row of the OpenProject settings panel. Three states, one prop
 * each; `unknown` wins over `ok`, because "not tested" must never read as
 * "broken".
 */
export default {
    name: 'OpenProjectStatusMark',

    components: { CheckCircleOutline, CloseCircleOutline, HelpCircleOutline },

    props: {
        ok: { type: Boolean, default: false },
        unknown: { type: Boolean, default: false },
    },

    data() {
        return { ICON_INLINE }
    },

    methods: { t },
}
</script>

<style scoped>
.op-mark {
    flex-shrink: 0;
}

.op-mark--ok      { color: var(--color-success-text); }
.op-mark--bad     { color: var(--color-error-text); }
.op-mark--unknown { color: var(--color-text-maxcontrast); }
</style>
