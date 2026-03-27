<template>
    <div class="activity-widget">
        <!-- Loading -->
        <div v-if="loading" class="activity-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <!-- Empty -->
        <div v-else-if="!activities.length" class="activity-widget__empty">
            {{ t('teamhub', 'No recent activity') }}
        </div>

        <!-- Feed -->
        <ul v-else class="activity-widget__list">
            <li
                v-for="item in visibleActivities"
                :key="item.activity_id"
                class="activity-widget__item">
                <!-- App icon badge -->
                <div class="activity-widget__badge" :class="'activity-widget__badge--' + item.app">
                    <component :is="iconComponent(item.icon)" :size="14" />
                </div>

                <div class="activity-widget__body">
                    <div class="activity-widget__row">
                        <NcAvatar
                            v-if="item.user"
                            :user="item.user"
                            :display-name="item.user"
                            :size="20"
                            :show-user-status="false"
                            :disable-menu="true"
                            class="activity-widget__avatar" />
                        <span class="activity-widget__subject">{{ formatSubject(item) }}</span>
                    </div>
                    <div class="activity-widget__meta">
                        <span class="activity-widget__app-label">{{ appLabel(item.app) }}</span>
                        <span class="activity-widget__sep">·</span>
                        <span class="activity-widget__time" :title="formatAbsoluteTime(item.datetime)">
                            {{ formatRelativeTime(item.datetime) }}
                        </span>
                        <a
                            v-if="item.link"
                            :href="item.link"
                            target="_blank"
                            rel="noopener"
                            class="activity-widget__link">
                            <OpenInNew :size="11" />
                        </a>
                    </div>
                </div>
            </li>
        </ul>

        <!-- Footer: More link + refresh -->
        <div v-if="!loading && activities.length" class="activity-widget__footer">
            <button class="activity-widget__more" @click="$emit('show-more')">
                {{ t('teamhub', 'More activity') }} →
            </button>
            <button class="activity-widget__reload" @click="load" :title="t('teamhub', 'Refresh')">
                <Refresh :size="13" />
            </button>
        </div>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcAvatar } from '@nextcloud/vue'

// Icons
import AccountMultiple    from 'vue-material-design-icons/AccountMultiple.vue'
import File               from 'vue-material-design-icons/File.vue'
import FilePlus           from 'vue-material-design-icons/FilePlus.vue'
import FileEdit           from 'vue-material-design-icons/FileEdit.vue'
import FileRemove         from 'vue-material-design-icons/FileRemove.vue'
import CardText           from 'vue-material-design-icons/CardText.vue'
import Calendar           from 'vue-material-design-icons/Calendar.vue'
import Chat               from 'vue-material-design-icons/Chat.vue'
import Bell               from 'vue-material-design-icons/Bell.vue'
import OpenInNew          from 'vue-material-design-icons/OpenInNew.vue'
import Refresh            from 'vue-material-design-icons/Refresh.vue'

const ICON_MAP = {
    AccountMultiple,
    File,
    FilePlus,
    FileEdit,
    FileRemove,
    CardText,
    Calendar,
    Chat,
    Bell,
}

const APP_LABELS = {
    circles:       'Team',
    files:         'Files',
    files_sharing: 'Sharing',
    deck:          'Deck',
    calendar:      'Calendar',
    spreed:        'Talk',
    dav:           'Calendar',
}

export default {
    name: 'ActivityWidget',
    components: {
        NcLoadingIcon,
        NcAvatar,
        OpenInNew,
        Refresh,
        AccountMultiple, File, FilePlus, FileEdit, FileRemove,
        CardText, Calendar, Chat, Bell,
    },
    data() {
        return {
            activities: [],
            loading: false,
            error: null,
        }
    },
    computed: {
        ...mapState(['currentTeamId']),
        visibleActivities() {
            return this.activities.slice(0, 5)
        },
    },
    watch: {
        currentTeamId(newId) {
            if (newId) this.load()
        },
    },
    mounted() {
        if (this.currentTeamId) this.load()
    },
    methods: {
        t,

        async load() {
            if (!this.currentTeamId) return
            this.loading = true
            this.error   = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/activity?limit=20`)
                )
                this.activities = data.activities || []
            } catch (e) {
                this.error      = e.message
                this.activities = []
            } finally {
                this.loading = false
            }
        },

        iconComponent(iconName) {
            return ICON_MAP[iconName] || Bell
        },

        appLabel(app) {
            return APP_LABELS[app] || app
        },

        /**
         * Format the subject line. The raw subject from NC's activity table is a
         * machine-readable string like "created_by" with params stored separately.
         * We produce a friendly one-liner from the available fields.
         */
        formatSubject(item) {
            const s = item.subject || ''
            // Extract filename from the file path if available
            const filename = item.file ? item.file.split('/').pop() : (item.object_id || '')

            // Circles
            if (item.app === 'circles') {
                if (s.includes('member_join') || s.includes('joined'))  return `${item.user} joined the team`
                if (s.includes('member_left') || s.includes('left'))    return `${item.user} left the team`
                if (s.includes('member_add'))                           return `${item.user} was added`
                if (s.includes('member_remove'))                        return `${item.user} was removed`
                return item.user ? `${item.user}: ${s}` : s
            }
            // Files
            if (item.app === 'files' || item.app === 'files_sharing') {
                if (s.includes('created'))  return `${item.user} uploaded ${filename}`
                if (s.includes('changed'))  return `${item.user} edited ${filename}`
                if (s.includes('deleted'))  return `${item.user} deleted ${filename}`
                if (s.includes('restored')) return `${item.user} restored ${filename}`
                if (s.includes('shared'))   return `${item.user} shared ${filename}`
                return item.user ? `${item.user} · ${filename}` : filename
            }
            // Deck
            if (item.app === 'deck') {
                if (s.includes('card_created'))  return `${item.user} created a card`
                if (s.includes('card_updated'))  return `${item.user} updated a card`
                if (s.includes('card_deleted'))  return `${item.user} deleted a card`
                if (s.includes('card_assigned')) return `${item.user} was assigned a card`
                if (s.includes('board'))         return `${item.user} updated the board`
                return item.user ? `${item.user} · ${s}` : s
            }
            // Calendar
            if (item.app === 'calendar' || item.app === 'dav') {
                if (s.includes('event_created')) return `${item.user} created an event`
                if (s.includes('event_updated')) return `${item.user} updated an event`
                if (s.includes('event_deleted')) return `${item.user} deleted an event`
                return item.user ? `${item.user} · ${s}` : s
            }
            // Talk
            if (item.app === 'spreed') {
                if (s.includes('call'))    return `${item.user} started a call`
                if (s.includes('message')) return `${item.user} sent a message`
                return item.user ? `${item.user} · ${s}` : s
            }
            return item.user ? `${item.user} · ${s}` : s
        },

        formatRelativeTime(datetime) {
            const diff = Math.floor((Date.now() - new Date(datetime).getTime()) / 1000)
            if (diff < 60)   return t('teamhub', 'Just now')
            if (diff < 3600) return t('teamhub', '{n}m ago', { n: Math.floor(diff / 60) })
            if (diff < 86400)return t('teamhub', '{n}h ago', { n: Math.floor(diff / 3600) })
            if (diff < 604800) return t('teamhub', '{n}d ago', { n: Math.floor(diff / 86400) })
            return new Date(datetime).toLocaleDateString()
        },

        formatAbsoluteTime(datetime) {
            return new Date(datetime).toLocaleString()
        },
    },
}
</script>

<style scoped>
.activity-widget {
    padding: 4px 0 8px;
}

.activity-widget__loading,
.activity-widget__empty {
    padding: 12px 16px;
    text-align: left;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.activity-widget__list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.activity-widget__item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 7px 14px;
    border-bottom: 1px solid var(--color-border-dark);
    transition: background 0.1s;
}

.activity-widget__item:last-child {
    border-bottom: none;
}

.activity-widget__item:hover {
    background: var(--color-background-hover);
}

/* Coloured app badge */
.activity-widget__badge {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 2px;
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}

.activity-widget__badge--circles     { background: #e8f0fe; color: #3b5998; }
.activity-widget__badge--files,
.activity-widget__badge--files_sharing { background: #e6f4ea; color: #188038; }
.activity-widget__badge--deck        { background: #fce8e6; color: #c5221f; }
.activity-widget__badge--calendar,
.activity-widget__badge--dav         { background: #fef7e0; color: #b45309; }
.activity-widget__badge--spreed      { background: #e8f5e9; color: #1b5e20; }

.activity-widget__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.activity-widget__row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.activity-widget__avatar {
    flex-shrink: 0;
}

.activity-widget__subject {
    font-size: 12.5px;
    color: var(--color-main-text);
    line-height: 1.35;
    word-break: break-word;
}

.activity-widget__meta {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--color-text-maxcontrast);
}

.activity-widget__sep {
    opacity: 0.5;
}

.activity-widget__link {
    display: inline-flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
    opacity: 0.7;
}

.activity-widget__link:hover {
    opacity: 1;
    color: var(--color-primary-element);
}

.activity-widget__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 14px 4px;
    border-top: 1px solid var(--color-border-dark);
}

.activity-widget__more {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 12px;
    color: var(--color-primary-element);
    padding: 2px 0;
    font-weight: 500;
    transition: opacity 0.15s;
}

.activity-widget__more:hover {
    opacity: 0.75;
}

.activity-widget__reload {
    display: flex;
    align-items: center;
    padding: 4px;
    color: var(--color-text-maxcontrast);
    background: transparent;
    border: none;
    cursor: pointer;
    border-radius: var(--border-radius);
    transition: color 0.15s, background 0.15s;
}

.activity-widget__reload:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}
</style>
