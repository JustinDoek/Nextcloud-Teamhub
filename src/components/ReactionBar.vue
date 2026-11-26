<template>
    <!-- v4.20.27 — emoji reactions on a message or a comment.
         Each chip toggles the viewer's own reaction; its title names who
         reacted. The add control is absent, not disabled, for a reader who
         may not react (the server refuses them either way). Nothing renders
         when there are no reactions and the reader may not add one. -->
    <div v-if="items.length > 0 || canReact" class="reaction-bar" :class="{ 'reaction-bar--compact': compact }">
        <template v-if="canReact">
            <NcButton
                v-for="r in items"
                :key="r.emoji"
                size="small"
                :variant="r.reacted ? 'secondary' : 'tertiary'"
                class="reaction-bar__chip"
                :class="{ 'reaction-bar__chip--mine': r.reacted }"
                :aria-pressed="r.reacted ? 'true' : 'false'"
                :aria-label="chipLabel(r)"
                :title="chipTitle(r)"
                :disabled="busy"
                @click="toggle(r.emoji)">
                <span class="reaction-bar__emoji" aria-hidden="true">{{ r.emoji }}</span>
                <span class="reaction-bar__count" aria-hidden="true">{{ r.count }}</span>
            </NcButton>
        </template>
        <!-- A reader who may not react sees the counts, not a control. -->
        <template v-else>
            <span
                v-for="r in items"
                :key="r.emoji"
                class="reaction-bar__static"
                :title="chipTitle(r)">
                <span class="reaction-bar__emoji" aria-hidden="true">{{ r.emoji }}</span>
                <span class="reaction-bar__count" aria-hidden="true">{{ r.count }}</span>
                <span class="hidden-visually">{{ chipTitle(r) }}</span>
            </span>
        </template>

        <NcEmojiPicker
            v-if="canReact"
            :closeOnSelect="true"
            @select="onPick">
            <NcButton
                size="small"
                variant="tertiary"
                :disabled="busy"
                :aria-label="t('teamhub', 'Add reaction')"
                :title="t('teamhub', 'Add reaction')">
                <template #icon>
                    <EmoticonPlusOutline :size="ICON_INLINE" />
                </template>
            </NcButton>
        </NcEmojiPicker>
    </div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmojiPicker } from '@nextcloud/vue'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import { ICON_INLINE } from '../constants/uiTokens.js'
import { applyReactionToggle } from '../lib/reactions.js'

export default {
    name: 'ReactionBar',

    components: {
        NcButton,
        NcEmojiPicker,
        EmoticonPlusOutline,
    },

    props: {
        /** 'message' or 'comment' */
        targetType: {
            type: String,
            required: true,
            validator: (v) => ['message', 'comment'].includes(v),
        },

        targetId: {
            type: Number,
            required: true,
        },

        /**
         * The server's summary for this target. A row returned by an edit,
         * a pin or a vote carries no `reactions`; `undefined` therefore means
         * "unchanged" and keeps what the bar already shows.
         */
        reactions: {
            type: Array,
            default: undefined,
        },

        canReact: {
            type: Boolean,
            default: true,
        },

        /** Tighter spacing, for a comment. */
        compact: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            ICON_INLINE,
            items: Array.isArray(this.reactions) ? this.reactions.map((r) => ({ ...r })) : [],
            busy: false,
        }
    },

    watch: {
        reactions(next) {
            if (Array.isArray(next) && !this.busy) {
                this.items = next.map((r) => ({ ...r }))
            }
        },
    },

    methods: {
        t,
        n,

        chipLabel(r) {
            // TRANSLATORS: accessible name of an emoji reaction chip under a team message. {emoji} is the emoji itself, %n how many people gave it. Selecting the chip adds or removes the reader's own reaction.
            return r.reacted
                ? n('teamhub', 'Remove your {emoji} reaction. %n person reacted', 'Remove your {emoji} reaction. %n people reacted', r.count, { emoji: r.emoji })
                : n('teamhub', 'React with {emoji}. %n person reacted', 'React with {emoji}. %n people reacted', r.count, { emoji: r.emoji })
        },

        chipTitle(r) {
            const names = (r.names || []).join(', ')
            if ((r.more || 0) > 0) {
                // TRANSLATORS: tooltip of an emoji reaction chip. {names} is a comma-separated list of the first ten people, %n how many more reacted. Always more than ten people in total.
                return n('teamhub', '{names} and %n other reacted with {emoji}', '{names} and %n others reacted with {emoji}', r.more, { names, emoji: r.emoji })
            }
            // TRANSLATORS: tooltip of an emoji reaction chip. {names} is a comma-separated list of one to ten people, so the verb may need a form that suits both one and several.
            return t('teamhub', '{names} reacted with {emoji}', { names, emoji: r.emoji })
        },

        onPick(emoji) {
            if (!emoji) {
                return
            }
            const existing = this.items.find((r) => r.emoji === emoji)
            // Picking an emoji you already gave is a no-op, not a removal:
            // the picker is the "add" control. The chip is where you take one back.
            if (existing && existing.reacted) {
                return
            }
            this.toggle(emoji)
        },

        /**
         * Optimistic: the chip moves at once, then takes the server's answer.
         * On failure the bar goes back to what it showed before.
         *
         * @param emoji
         */
        async toggle(emoji) {
            if (this.busy || !this.canReact) {
                return
            }
            const before = this.items.map((r) => ({ ...r }))
            const existing = this.items.find((r) => r.emoji === emoji)
            const add = !(existing && existing.reacted)
            this.items = applyReactionToggle(this.items, emoji, add)
            this.busy = true
            try {
                const reactions = await this.$store.dispatch('setReaction', {
                    targetType: this.targetType,
                    targetId: this.targetId,
                    emoji,
                    add,
                })
                if (Array.isArray(reactions)) {
                    this.items = reactions.map((r) => ({ ...r }))
                }
            } catch {
                this.items = before
                showError(add
                    ? t('teamhub', 'Could not add the reaction')
                    : t('teamhub', 'Could not remove the reaction'))
            } finally {
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.reaction-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--th-space-xs);
}

.reaction-bar--compact {
    margin-block-start: var(--th-space-xs);
}

/* NcButton owns padding, colour and focus; this is layout only. */
.reaction-bar__chip {
    flex: 0 0 auto;
}

.reaction-bar__static {
    display: inline-flex;
    align-items: center;
    padding-block: var(--th-space-xs);
    padding-inline: var(--th-space-sm);
    border-radius: var(--border-radius-pill);
    background: var(--color-background-hover);
}

.reaction-bar__emoji {
    font-size: var(--th-font-body);
    line-height: 1;
}

.reaction-bar__count {
    margin-inline-start: var(--th-space-xs);
    font-size: var(--th-font-meta);
    font-variant-numeric: tabular-nums;
}

/* The viewer's own reaction carries a weight as well as a fill, so it is
   not told apart by colour alone. */
.reaction-bar__chip--mine .reaction-bar__count {
    font-weight: 600;
}
</style>
