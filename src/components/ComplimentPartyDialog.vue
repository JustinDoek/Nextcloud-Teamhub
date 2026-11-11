<template>
    <!-- v4.13.2 — the party popup (DESIGN §2.186). Shown once, when somebody
         opens TeamHub and has compliments they have not been shown yet; the
         ids are marked seen only after this is on screen, so a tab closed
         before it paints does not swallow one. -->
    <NcDialog
        v-if="open"
        :open="open"
        :name="headline"
        size="normal"
        @update:open="onDialogUpdate">

        <div class="th-party">
            <!-- Decoration, and nothing else: aria-hidden, and gone entirely
                 for anybody who asked their system for less motion. -->
            <div class="th-party__confetti" aria-hidden="true">
                <span
                    v-for="piece in confetti"
                    :key="piece.key"
                    class="th-party__piece"
                    :style="piece.style"></span>
            </div>

            <div class="th-party__hero" aria-hidden="true">
                <PartyPopper :size="ICON_HERO" />
            </div>

            <p class="th-party__lead">{{ lead }}</p>

            <ul class="th-party__senders">
                <li v-for="sender in senders" :key="sender.uid" class="th-party__sender">
                    <!-- disable-menu="false" is the point: the avatar opens the
                         contacts menu, so the person who was complimented can
                         reach Talk, mail or a profile from here — and pay one
                         back, since the Compliment entry is in that same menu. -->
                    <NcAvatar
                        class="th-party__avatar"
                        :user="sender.uid"
                        :display-name="sender.displayName"
                        :size="AVATAR_LG"
                        :hide-status="true"
                        :disable-menu="false" />

                    <div class="th-party__sender-body">
                        <span class="th-party__sender-name">{{ sender.displayName }}</span>
                        <span v-if="sender.subline" class="th-party__sender-subline">{{ sender.subline }}</span>

                        <!-- The first note, then every later one under it. -->
                        <p
                            v-for="(entry, index) in sender.notes"
                            :key="index"
                            class="th-party__note">
                            {{ entry.note }}
                        </p>
                    </div>
                </li>
            </ul>

            <p v-if="senders.length" class="th-party__hint">
                {{ t('teamhub', 'Select a profile picture for contact options.') }}
            </p>
        </div>

        <template #actions>
            <NcButton variant="primary" @click="dismiss">
                {{ t('teamhub', 'Close') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcAvatar, NcButton, NcDialog } from '@nextcloud/vue'

import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import { AVATAR_LG, ICON_HERO } from '../constants/uiTokens.js'

/** How many confetti pieces. Enough to read as confetti, few enough to be free. */
const PIECE_COUNT = 24

export default {
    name: 'ComplimentPartyDialog',

    components: { NcAvatar, NcButton, NcDialog, PartyPopper },

    props: {
        open: { type: Boolean, default: false },
        /** Total compliments being shown, across all senders. */
        total: { type: Number, default: 0 },
        /**
         * [{ uid, displayName, subline, count, notes: [{ note, createdAt }] }]
         * in the order the first compliment from each person arrived.
         */
        senders: { type: Array, default: () => [] },
    },

    emits: ['dismiss'],

    data() {
        return {
            AVATAR_LG,
            ICON_HERO,
            /**
             * Generated once, in data rather than a computed: a computed
             * would re-roll every piece's position on any re-render and the
             * confetti would jump.
             */
            confetti: Array.from({ length: PIECE_COUNT }, (_, i) => ({
                key: i,
                style: {
                    insetInlineStart: `${Math.round((i / PIECE_COUNT) * 100 + (Math.random() * 6 - 3))}%`,
                    animationDelay: `${(Math.random() * 1.4).toFixed(2)}s`,
                    animationDuration: `${(2.2 + Math.random() * 1.6).toFixed(2)}s`,
                    background: `var(--th-party-piece-${(i % 5) + 1})`,
                },
            })),
        }
    },

    computed: {
        headline() {
            // TRANSLATORS: N is the number of compliments waiting for this person
            return n('teamhub', 'A compliment for you', '%n compliments for you', this.total, { n: this.total })
        },

        lead() {
            if (this.senders.length === 1) {
                return t('teamhub', '{name} sent you a compliment.', { name: this.senders[0].displayName })
            }
            // TRANSLATORS: N is the number of different people who sent a compliment
            return n(
                'teamhub',
                '%n person sent you a compliment.',
                '%n people sent you a compliment.',
                this.senders.length,
                { n: this.senders.length },
            )
        },
    },

    methods: {
        t,
        n,

        /** Closing by the X or the backdrop counts as having seen them. */
        onDialogUpdate(isOpen) {
            if (!isOpen) {
                this.dismiss()
            }
        },

        dismiss() {
            this.$emit('dismiss')
        },
    },
}
</script>

<style scoped>
.th-party {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--th-space-md);
    padding-block-start: var(--th-space-sm);
    /* The confetti is absolutely positioned against this box and falls past
       its bottom edge, so it must not widen the dialog. */
    overflow: hidden;
}

.th-party__hero {
    color: var(--color-primary-element);
}

.th-party__lead {
    margin: 0;
    text-align: center;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.th-party__senders {
    list-style: none;
    margin: 0;
    padding: 0;
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
}

.th-party__sender {
    display: flex;
    align-items: flex-start;
    gap: var(--th-space-md);
    padding: var(--th-space-md);
    background: var(--color-background-hover);
    border-radius: var(--border-radius-container);
}

.th-party__avatar {
    flex: 0 0 auto;
}

.th-party__sender-body {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    min-width: 0;
}

.th-party__sender-name {
    font-weight: var(--th-font-weight-semibold);
}

.th-party__sender-subline {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-party__note {
    margin: 0;
    font-size: var(--th-font-body);
    line-height: var(--th-line-height-body);
    /* Somebody who wrote two lines meant two lines; the service keeps single
       newlines and collapses longer runs. */
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.th-party__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* ── Confetti ─────────────────────────────────────────────────────────────
   Pure CSS, no dependency, and no layout cost: the pieces are absolutely
   positioned and `pointer-events: none`, so nothing underneath them changes
   behaviour. Colours come from NC's own element tokens, so the confetti
   follows the instance's theme instead of introducing a palette. */
.th-party__confetti {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}

.th-party__piece {
    position: absolute;
    inset-block-start: calc(-4 * var(--default-grid-baseline));
    width: calc(2 * var(--default-grid-baseline));
    height: calc(3 * var(--default-grid-baseline));
    border-radius: var(--border-radius-small);
    opacity: 0;
    animation-name: th-party-fall;
    animation-timing-function: linear;
    animation-iteration-count: 1;
    animation-fill-mode: forwards;
}

@keyframes th-party-fall {
    0%   { opacity: 0; transform: translateY(0) rotate(0deg); }
    10%  { opacity: 1; }
    90%  { opacity: 1; }
    100% { opacity: 0; transform: translateY(calc(90 * var(--default-grid-baseline))) rotate(540deg); }
}

/* WCAG 2.3.3 — animation from interactions. Somebody who asked their system
   for less motion gets the compliment and none of the falling paper. */
@media (prefers-reduced-motion: reduce) {
    .th-party__confetti {
        display: none;
    }
}
</style>
