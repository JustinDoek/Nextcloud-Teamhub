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
                    :can-pin="canPin"
                    :is-pinned-slot="true" />
            </div>

            <!-- Empty state -->
            <NcEmptyContent
                v-if="messages.length === 0 && !showPostForm && !pinnedMessage"
                :name="t('teamhub', 'No messages yet')"
                :description="t('teamhub', 'Be the first to post a message')">
                <template #icon><MessageOutline :size="64" /></template>
            </NcEmptyContent>

            <!-- Regular messages -->
            <TransitionGroup v-if="messages.length > 0" name="msg-list" tag="div" class="message-stream__list">
                <MessageCard
                    v-for="msg in messages"
                    :key="msg.id"
                    :message="msg"
                    :can-pin="canPin"
                    :is-pinned-slot="false" />
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
        return { showPostForm: false }
    },
    computed: {
        ...mapState(['messages', 'pinnedMessage', 'loading', 'currentTeamId',
                     'messagesPage', 'messagesTotal', 'messagesLimit']),
        ...mapGetters(['canPin', 'canPost']),

        totalPages() {
            if (!this.messagesTotal || !this.messagesLimit) return 1
            return Math.max(1, Math.ceil(this.messagesTotal / this.messagesLimit))
        },
    },
    methods: {
        t,

        openPostForm() {
            this.showPostForm = true
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
    font-size: 11px;
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

