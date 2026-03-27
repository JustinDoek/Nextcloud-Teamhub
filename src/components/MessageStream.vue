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

        <!-- Empty state -->
        <NcEmptyContent
            v-else-if="messages.length === 0 && !showPostForm"
            :name="t('teamhub', 'No messages yet')"
            :description="t('teamhub', 'Be the first to post a message')">
            <template #icon><MessageOutline :size="64" /></template>
            <template #action>
                <NcButton type="primary" @click="showPostForm = true">
                    {{ t('teamhub', 'Post First Message') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <!-- Messages -->
        <TransitionGroup v-else name="msg-list" tag="div" class="message-stream__list">
            <MessageCard
                v-for="msg in messages"
                :key="msg.id"
                :message="msg" />
        </TransitionGroup>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessageCard from './MessageCard.vue'
import PostMessageForm from './PostMessageForm.vue'

export default {
    name: 'MessageStream',
    components: { NcButton, NcLoadingIcon, NcEmptyContent, Plus, MessageOutline, MessageCard, PostMessageForm },
    data() {
        return { showPostForm: false }
    },
    computed: {
        ...mapState(['messages', 'loading']),
    },
    methods: { t },
}
</script>

<style scoped>
.message-stream {
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
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
