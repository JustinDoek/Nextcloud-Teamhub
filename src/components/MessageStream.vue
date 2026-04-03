<template>
    <div class="message-stream">
        <div class="message-stream__header">
            <h2 class="message-stream__title">{{ t('teamhub', 'Team Messages') }}</h2>
            <NcButton type="primary" @click="showPostForm = true">
                <template #icon><Plus :size="20" /></template>
                {{ t('teamhub', 'Post Message') }}
            </NcButton>
        </div>

        <!-- Post form inline -->
        <PostMessageForm v-if="showPostForm" @submitted="showPostForm = false" @cancel="showPostForm = false" />

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
                <template #action>
                    <NcButton type="primary" @click="showPostForm = true">
                        {{ t('teamhub', 'Post First Message') }}
                    </NcButton>
                </template>
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
        </template>
    </div>
</template>

<script>
import { mapState, mapGetters } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessageCard from './MessageCard.vue'
import PostMessageForm from './PostMessageForm.vue'

export default {
    name: 'MessageStream',
    components: { NcButton, NcLoadingIcon, NcEmptyContent, Plus, Pin, MessageOutline, MessageCard, PostMessageForm },
    data() {
        return { showPostForm: false }
    },
    computed: {
        ...mapState(['messages', 'pinnedMessage', 'loading']),
        ...mapGetters(['canPin']),
    },
    methods: { t },
}
</script>

<style scoped>
.message-stream {
    padding: 20px;
    min-height: 100%;
}

.message-stream__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--color-border);
}

.message-stream__title {
    font-size: 1.5em;
    font-weight: 700;
    margin: 0;
    color: var(--color-main-text);
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
</style>
