<template>
    <NcDialog v-if="open && celebration" :open="open"
        :name="t('teamhub', 'Congratulations, {name}!', { name: celebration.displayName })"
        size="normal" @update:open="onOpenChange">
        <div class="th-anniversary-party">
            <div class="th-anniversary-party__confetti" aria-hidden="true">
                <span v-for="piece in confetti" :key="piece" class="th-anniversary-party__piece"
                    :style="{ left: `${piece * 4}%`, animationDelay: `${(piece % 6) * .15}s` }" />
            </div>
            <PartyPopper :size="ICON_HERO" aria-hidden="true" />
            <h3>{{ t('teamhub', '{years} years with {team}!', { years: celebration.years, team: celebration.teamName }) }}</h3>
            <p>{{ t('teamhub', 'Today marks {years} years since you joined {team}. Thank you for everything you bring to your colleagues!', { years: celebration.years, team: celebration.teamName }) }}</p>
        </div>
        <template #actions>
            <NcButton variant="primary" @click="$emit('dismiss', celebration)">{{ t('teamhub', 'Close') }}</NcButton>
        </template>
    </NcDialog>
</template>
<script>
import { translate as t } from '@nextcloud/l10n'
import { NcDialog, NcButton } from '@nextcloud/vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import { ICON_HERO } from '../constants/uiTokens.js'
export default {
    name: 'AnniversaryPartyDialog',
    components: { NcDialog, NcButton, PartyPopper },
    props: {
        open: { type: Boolean, default: false },
        celebration: { type: Object, default: null },
    },
    emits: ['dismiss'],
    data() { return { ICON_HERO, confetti: Array.from({ length: 24 }, (_, index) => index) } },
    methods: {
        t,
        onOpenChange(open) { if (!open) this.$emit('dismiss', this.celebration) },
    },
}
</script>
<style scoped>
.th-anniversary-party { position: relative; padding: 32px; min-height: 180px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 16px; text-align: center; overflow: hidden; }
.th-anniversary-party h3 { font-size: var(--th-font-heading); }
.th-anniversary-party__confetti { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
.th-anniversary-party__piece { position: absolute; top: -20px; width: 7px; height: 13px; opacity: 0; background: var(--color-primary-element); animation: th-anniversary-fall 2.2s linear forwards; }
.th-anniversary-party__piece:nth-child(3n) { background: var(--color-success); }
.th-anniversary-party__piece:nth-child(3n + 1) { background: var(--color-warning); }
@keyframes th-anniversary-fall { 0% { opacity: 0; transform: translateY(0) rotate(0); } 15% { opacity: 1; } 100% { opacity: 0; transform: translateY(220px) rotate(420deg); } }
@media (prefers-reduced-motion: reduce) { .th-anniversary-party__confetti { display: none; } }
</style>
