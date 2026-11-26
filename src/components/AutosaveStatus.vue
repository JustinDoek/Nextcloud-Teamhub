<template>
    <!-- v4.20.12 (DESIGN §2.265) — the quiet status line of a section that
         saves itself, after the admin Services tab (§2.225): transient,
         aria-live so a screen reader hears the outcome of a change nobody
         pressed a button for, and holding its height when empty so nothing
         below shifts as saving and saved come and go. A failure stays until
         the next save and offers Retry, so it is never a dead end. -->
    <div class="th-autosave" role="status" aria-live="polite">
        <template v-if="saving">
            <NcLoadingIcon :size="ICON_INLINE" />
            <span>{{ t('teamhub', 'Saving…') }}</span>
        </template>
        <template v-else-if="error">
            <span class="th-autosave__error">{{ error }}</span>
            <NcButton variant="tertiary" @click="$emit('retry')">
                {{ t('teamhub', 'Retry') }}
            </NcButton>
        </template>
        <span v-else-if="saved" class="th-autosave__ok">{{ t('teamhub', 'Saved') }}</span>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { ICON_INLINE } from '../constants/uiTokens.js'

export default {
    name: 'AutosaveStatus',
    components: { NcButton, NcLoadingIcon },
    props: {
        saving: { type: Boolean, default: false },
        saved: { type: Boolean, default: false },
        error: { type: String, default: '' },
    },
    emits: ['retry'],
    data() {
        return { ICON_INLINE }
    },
    methods: { t },
}
</script>

<style scoped>
.th-autosave {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
    min-height: var(--default-clickable-area);
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-autosave__ok {
    color: var(--color-text-success);
}

.th-autosave__error {
    color: var(--color-text-error);
}
</style>
