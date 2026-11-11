<template>
    <NcDialog v-if="open" :open="open" :name="t('teamhub', 'Happy birthday, {name}!', { name: displayName })"
        size="normal" @update:open="onOpenChange">
        <div class="th-birthday-party">
            <div class="th-birthday-party__confetti" aria-hidden="true">
                <span v-for="piece in confetti" :key="piece" class="th-birthday-party__piece"
                    :style="{ left: `${piece * 4}%`, animationDelay: `${(piece % 6) * .15}s` }" />
            </div>
            <PartyPopper :size="ICON_HERO" aria-hidden="true" />
            <p>{{ t('teamhub', 'Wishing you a wonderful day from everyone at TeamHub!') }}</p>
        </div>
        <template #actions>
            <NcButton variant="primary" @click="$emit('dismiss')">{{ t('teamhub', 'Close') }}</NcButton>
        </template>
    </NcDialog>
</template>
<script>
import { translate as t } from '@nextcloud/l10n'
import { NcDialog, NcButton } from '@nextcloud/vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import { ICON_HERO } from '../constants/uiTokens.js'
export default {
    name: 'BirthdayPartyDialog',
    components: { NcDialog, NcButton, PartyPopper },
    props: { open: { type: Boolean, default: false }, displayName: { type: String, default: '' } },
    emits: ['dismiss'],
    data() { return { ICON_HERO, confetti: Array.from({ length: 24 }, (_, index) => index) } },
    methods: {
        t,
        onOpenChange(open) { if (!open) this.$emit('dismiss') },
    },
}
</script>
<style scoped>
.th-birthday-party { position: relative; padding: 32px; min-height: 180px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 16px; text-align: center; overflow: hidden; }
.th-birthday-party__confetti { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
.th-birthday-party__piece { position: absolute; top: -20px; width: 7px; height: 13px; opacity: 0; background: var(--color-primary-element); animation: th-birthday-fall 2.2s linear forwards; }
.th-birthday-party__piece:nth-child(3n) { background: var(--color-success); }
.th-birthday-party__piece:nth-child(3n + 1) { background: var(--color-warning); }
@keyframes th-birthday-fall { 0% { opacity: 0; transform: translateY(0) rotate(0); } 15% { opacity: 1; } 100% { opacity: 0; transform: translateY(220px) rotate(420deg); } }
@media (prefers-reduced-motion: reduce) { .th-birthday-party__confetti { display: none; } }
</style>
