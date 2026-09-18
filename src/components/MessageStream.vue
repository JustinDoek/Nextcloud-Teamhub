<template>
    <div class="message-stream" :class="{ 'message-stream--no-header': hideHeader }">
        <div v-if="!hideHeader" class="message-stream__header">
            <NcButton v-if="canPost" variant="primary" @click="showPostForm = true">
                <template #icon><Plus :size="20" /></template>
                {{ t('teamhub', 'Post Message') }}
            </NcButton>
        </div>

        <!-- Post form inline — only rendered when the user has the right to post -->
        <PostMessageForm v-if="showPostForm && canPost" @submitted="onMessagePosted" @cancel="showPostForm = false" />

        <!-- Loading -->
        <div v-if="loading.messages" class="message-stream__loading">
            <NcLoadingIcon :size="32" />
        </div>

        <template v-else>
            <!-- Pinned message — always shown above the stream when present -->
            <div v-if="pinnedMessage" class="message-stream__pinned-wrapper">
                <div class="message-stream__pinned-label">
                    <Pin :size="14" />
                    {{ t('teamhub', 'Pinned') }}
                </div>
                <MessageCard
                    :message="pinnedMessage"
                    :can-manage="canManageMessages"
                    :is-pinned-slot="true" />
            </div>

            <!-- Empty state -->
            <NcEmptyContent
                v-if="messages.length === 0 && !showPostForm && !pinnedMessage"
                :name="t('teamhub', 'No messages yet')"
                :description="t('teamhub', 'Be the first to post a message')">
                <template #icon><MessageOutline :size="64" /></template>
            </NcEmptyContent>

            <!-- Regular messages — direct-proposal decisions (sourceType='direct') are
                 excluded here; they live only in the Decisions tab. -->
            <TransitionGroup v-if="filteredMessages.length > 0" name="msg-list" tag="div" class="message-stream__list">
                <div
                    v-for="msg in filteredMessages"
                    :key="msg.id"
                    :data-message-id="msg.id"
                    class="message-stream__item"
                    :class="{ 'message-stream__item--highlighted': highlightedId === msg.id }">
                    <MessageCard
                        :message="msg"
                        :can-manage="canManageMessages"
                        :is-pinned-slot="false" />
                </div>
            </TransitionGroup>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="message-stream__pagination" role="navigation" :aria-label="t('teamhub', 'Message pages')">
                <NcButton
                    variant="tertiary"
                    :disabled="messagesPage <= 1"
                    :aria-label="t('teamhub', 'Previous page')"
                    @click="goToPage(messagesPage - 1)">
                    <template #icon><ChevronLeft :size="20" /></template>
                </NcButton>

                <span class="message-stream__pagination-info">
                    {{ t('teamhub', 'Page {page} of {total}', { page: messagesPage, total: totalPages }) }}
                </span>

                <NcButton
                    variant="tertiary"
                    :disabled="messagesPage >= totalPages"
                    :aria-label="t('teamhub', 'Next page')"
                    @click="goToPage(messagesPage + 1)">
                    <template #icon><ChevronRight :size="20" /></template>
                </NcButton>
            </div>
        </template>
    </div>
</template>

<script>
import { mapState, mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessageCard from './MessageCard.vue'
import PostMessageForm from './PostMessageForm.vue'

export default {
    name: 'MessageStream',
    components: {
        NcButton, NcLoadingIcon, NcEmptyContent,
        Plus, Pin, ChevronLeft, ChevronRight, MessageOutline,
        MessageCard, PostMessageForm,
    },
    props: {
        hideHeader: { type: Boolean, default: false },
    },
    data() {
        return {
            showPostForm: false,
            // Highlighted id, cleared on a timer so the glow is a hint rather
            // than a permanent state the user has to work out how to dismiss.
            highlightedId: null,
        }
    },
    computed: {
        ...mapState(['messages', 'pinnedMessage', 'loading', 'currentTeamId',
                     'messagesPage', 'messagesTotal', 'messagesLimit', 'messageTarget']),
        ...mapGetters(['canManageMessages', 'canPost']),

        totalPages() {
            if (!this.messagesTotal || !this.messagesLimit) return 1
            return Math.max(1, Math.ceil(this.messagesTotal / this.messagesLimit))
        },

        /**
         * Decisions created via the Compose modal (forceDecision=true) or the
         * "Propose decision" button are direct proposals — they bypass the
         * stream discussion phase and live only in the Decisions tab.
         * sourceType 'direct' is set by PostMessageForm when forceDecision=true.
         * These messages are excluded from the stream so the stream stays clean.
         */
        filteredMessages() {
            return (this.messages || []).filter(
                msg => !(msg.decision && msg.decision.sourceType === 'direct'),
            )
        },
    },
    watch: {
        /**
         * v4.5.26 — a deep link from "What's new" asking for one message.
         *
         * `immediate` because the target is usually set *before* this component
         * mounts (App.vue selects the team, the tab renders, then the mutation
         * lands) — without it the first deep link of a session does nothing.
         * The nonce on the payload is what makes opening the same message twice
         * re-fire this.
         */
        messageTarget: {
            immediate: true,
            handler(target) {
                if (target?.messageId) {
                    this.focusMessage(target.messageId)
                }
            },
        },
    },

    beforeUnmount() {
        // A pending highlight timer outliving the component would call
        // setState on something Vue has already torn down.
        if (this._highlightTimer) {
            clearTimeout(this._highlightTimer)
        }
    },

    methods: {
        t,

        openPostForm() {
            this.showPostForm = true
        },

        /**
         * Bring one message into view: load the page it lives on, scroll to it,
         * and glow briefly.
         *
         * The page comes from the server (`aroundMessageId`) rather than being
         * computed here — the stream's order and page size are the endpoint's
         * business. A message that has since been deleted resolves to no page,
         * the server returns the requested one, and nothing is highlighted;
         * that degrades to "you are on the team's stream", which is where the
         * user was trying to get to anyway.
         */
        async focusMessage(messageId) {
            const id = Number(messageId)
            if (!id || !this.currentTeamId) return

            const alreadyHere = (this.messages || []).some(m => Number(m.id) === id)
                || Number(this.pinnedMessage?.id) === id
            if (!alreadyHere) {
                await this.$store.dispatch('fetchMessages', {
                    teamId: this.currentTeamId,
                    aroundMessageId: id,
                })
            }

            // Consumed — clear it so switching teams and coming back doesn't
            // re-scroll to a message the user has moved on from.
            this.$store.commit('SET_MESSAGE_TARGET', null)

            this.$nextTick(() => {
                const el = this.$el?.querySelector(`[data-message-id="${id}"]`)
                if (!el) return
                el.scrollIntoView({ behavior: 'smooth', block: 'center' })
                this.highlightedId = id
                if (this._highlightTimer) clearTimeout(this._highlightTimer)
                this._highlightTimer = setTimeout(() => { this.highlightedId = null }, 2500)
            })
        },

        goToPage(page) {
            if (page < 1 || page > this.totalPages) return
            this.$store.dispatch('fetchMessages', { teamId: this.currentTeamId, page })
        },

        onMessagePosted() {
            this.showPostForm = false
            // After posting go to page 1 so user sees the new message at the top
            this.$store.dispatch('fetchMessages', { teamId: this.currentTeamId, page: 1 })
        },
    },
}
</script>

<style scoped>
.message-stream {
    padding: 20px;
    min-height: 100%;
}

.message-stream--no-header {
    padding: 12px;
}

.message-stream__header {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--color-border);
}

.message-stream__loading {
    display: flex;
    justify-content: center;
    padding: 60px 40px;
}

.message-stream__pinned-wrapper {
    margin-bottom: 16px;
}

.message-stream__pinned-label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: var(--th-font-micro);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-primary-element);
    margin-bottom: 6px;
    padding-left: 2px;
}

.message-stream__list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* v4.5.26 — deep-link landing glow. A ring rather than a background change so
   it reads on top of whatever the card itself is doing, and it fades out on
   its own after a couple of seconds. */
.message-stream__item {
    border-radius: var(--th-radius-card, var(--border-radius-large));
    transition: box-shadow 400ms ease-out;
}

.message-stream__item--highlighted {
    box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* The glow is decoration on top of a scroll that already moved the message
   into view; anyone who has asked for less motion still lands on the right
   card, just without the animated fade. */
@media (prefers-reduced-motion: reduce) {
    .message-stream__item {
        transition: none;
    }
}

.msg-list-enter-active, .msg-list-leave-active {
    transition: all 0.3s ease;
}

.msg-list-enter, .msg-list-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}

.message-stream__pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.message-stream__pagination-info {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    min-width: 100px;
    text-align: center;
}
</style>

