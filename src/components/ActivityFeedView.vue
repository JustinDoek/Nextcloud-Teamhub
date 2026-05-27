<template>
    <div class="activity-feed">
        <div class="activity-feed__header">
            <h2 class="activity-feed__title">{{ t('teamhub', 'Team Activity') }}</h2>
            <span class="activity-feed__subtitle">{{ t('teamhub', 'Past 30 days') }}</span>
            <button class="activity-feed__refresh"
                :aria-label="t('teamhub', 'Refresh activity')"
                :title="t('teamhub', 'Refresh activity')"
                @click="load"
                :disabled="loading">
                <Refresh :size="16" aria-hidden="true" />
            </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="activity-feed__loading">
            <NcLoadingIcon :size="32" />
        </div>

        <!-- Empty -->
        <NcEmptyContent
            v-else-if="!grouped.length"
            :name="t('teamhub', 'No activity this week')"
            :description="t('teamhub', 'Activity from files, calendar, tasks and team changes will appear here')">
            <template #icon><ClockOutline :size="48" /></template>
        </NcEmptyContent>

        <!-- Grouped by day -->
        <div v-else class="activity-feed__days">
            <div
                v-for="group in grouped"
                :key="group.label"
                class="activity-feed__day">
                <div class="activity-feed__day-label">{{ group.label }}</div>
                <ul class="activity-feed__list">
                    <li
                        v-for="item in group.items"
                        :key="item.activity_id"
                        class="activity-feed__item">
                        <!-- Badge -->
                        <div class="activity-feed__badge" :class="'activity-feed__badge--' + item.app">
                            <component :is="iconComponent(item.icon)" :size="15" />
                        </div>
                        <!-- Body -->
                        <div class="activity-feed__body">
                            <div class="activity-feed__row">
                                <NcAvatar
                                    v-if="item.user"
                                    :user="item.user"
                                    :display-name="item.user"
                                    :size="22"
                                    :show-user-status="false"
                                    :disable-menu="true"
                                    class="activity-feed__avatar" />
                                <span class="activity-feed__subject">{{ item.subjectText }}</span>
                            </div>
                            <div class="activity-feed__meta">
                                <span class="activity-feed__app-label">{{ appLabel(item.app) }}</span>
                                <span class="activity-feed__sep">·</span>
                                <span class="activity-feed__time">{{ formatTime(item.datetime) }}</span>
                                <a
                                    v-if="item.link"
                                    :href="item.link"
                                    target="_blank"
                                    rel="noopener"
                                    class="activity-feed__link">
                                    <OpenInNew :size="11" />
                                </a>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcEmptyContent, NcAvatar } from '@nextcloud/vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import File            from 'vue-material-design-icons/File.vue'
import FilePlus        from 'vue-material-design-icons/FilePlus.vue'
import FileEdit        from 'vue-material-design-icons/FileEdit.vue'
import FileRemove      from 'vue-material-design-icons/FileRemove.vue'
import CardText        from 'vue-material-design-icons/CardText.vue'
import Calendar        from 'vue-material-design-icons/Calendar.vue'
import Chat            from 'vue-material-design-icons/Chat.vue'
import Bell            from 'vue-material-design-icons/Bell.vue'
import OpenInNew       from 'vue-material-design-icons/OpenInNew.vue'
import Refresh         from 'vue-material-design-icons/Refresh.vue'
import ClockOutline    from 'vue-material-design-icons/ClockOutline.vue'

const ICON_MAP = { AccountMultiple, File, FilePlus, FileEdit, FileRemove, CardText, Calendar, Chat, Bell }
const APP_LABELS = {
    circles: 'Team', files: 'Files', files_sharing: 'Sharing',
    deck: 'Deck', calendar: 'Calendar', spreed: 'Talk', dav: 'Calendar',
}

function dayLabel(dateStr) {
    const d   = new Date(dateStr)
    const now = new Date()
    const today     = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const yesterday = new Date(today - 86400000)
    const day       = new Date(d.getFullYear(), d.getMonth(), d.getDate())
    if (day.getTime() === today.getTime())     return 'Today'
    if (day.getTime() === yesterday.getTime()) return 'Yesterday'
    return day.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })
}

export default {
    name: 'ActivityFeedView',
    components: {
        NcLoadingIcon, NcEmptyContent, NcAvatar,
        OpenInNew, Refresh, ClockOutline,
        AccountMultiple, File, FilePlus, FileEdit, FileRemove,
        CardText, Calendar, Chat, Bell,
    },
    data() {
        return { activities: [], loading: false }
    },
    computed: {
        ...mapState(['currentTeamId']),
        grouped() {
            // Group by calendar day, newest day first. The formatted subject is
            // computed here (once per activity, when `activities` changes) rather
            // than via an in-template {{ formatSubject(item) }} call that re-ran
            // the full branch ladder for every item on every render (perf pass V6).
            const map = new Map()
            for (const item of this.activities) {
                const label = dayLabel(item.datetime)
                if (!map.has(label)) map.set(label, [])
                map.get(label).push({ ...item, subjectText: this.formatSubject(item) })
            }
            return Array.from(map.entries()).map(([label, items]) => ({ label, items }))
        },
    },
    watch: {
        currentTeamId(id) { if (id) this.load() },
    },
    mounted() {
        if (this.currentTeamId) this.load()
    },
    methods: {
        t,
        async load() {
            if (!this.currentTeamId) return
            this.loading = true
            try {
                // Fetch last 7 days: request 100 items; backend sorts newest-first
                // Fetch past 30 days server-side using since parameter
                const monthAgo = Math.floor((Date.now() - 30 * 24 * 60 * 60 * 1000) / 1000)
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/activity?limit=100&since=${monthAgo}`)
                )
                this.activities = data.activities || []
            } catch (e) {
                this.activities = []
            } finally {
                this.loading = false
            }
        },
        iconComponent(name) { return ICON_MAP[name] || Bell },
        appLabel(app) { return APP_LABELS[app] || app },
        formatSubject(item) {
            const s = item.subject || ''
            const user = item.user || ''
            // Fallback wrapper for any subject we don't map explicitly. The detail
            // value is machine-derived (raw subject, underscores -> spaces), so it
            // is data, not translatable copy — only the wrapper is translated.
            const detail = s.replace(/_/g, ' ')
            // TRANSLATORS: fallback activity line, e.g. "alice · card moved". {user} is a name, {detail} is a machine-generated description.
            const fallback = user ? t('teamhub', '{user} · {detail}', { user, detail }) : detail

            // Circles — member events
            if (item.app === 'circles') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_joined' || s.includes('member_join') || s.includes('joined')) return t('teamhub', '{user} joined the team', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_left'   || s.includes('member_left') || s.includes('left'))   return t('teamhub', '{user} left the team', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_added'  || s.includes('member_add'))                          return t('teamhub', '{user} was added to the team', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_removed'|| s.includes('member_remove'))                       return t('teamhub', '{user} was removed from the team', { user })
                return fallback
            }

            // Files
            if (item.app === 'files' || item.app === 'files_sharing') {
                const file = item.file ? item.file.split('/').pop() : (item.object_id || '')
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('created'))  return t('teamhub', '{user} uploaded {file}', { user, file })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('changed'))  return t('teamhub', '{user} edited {file}', { user, file })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('deleted'))  return t('teamhub', '{user} deleted {file}', { user, file })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('restored')) return t('teamhub', '{user} restored {file}', { user, file })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('shared'))   return t('teamhub', '{user} shared a file', { user })
                return user ? t('teamhub', '{user} · {detail}', { user, detail: file }) : file
            }

            // Deck — exact subject strings from oc_activity
            if (item.app === 'deck') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_create')             return t('teamhub', '{user} created a card', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_update_title')       return t('teamhub', '{user} renamed a card', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_update_description') return t('teamhub', '{user} updated a card description', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_update_duedate')     return t('teamhub', '{user} set a card due date', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_update_archive')     return t('teamhub', '{user} archived a card', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_delete')             return t('teamhub', '{user} deleted a card', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_user_assign')        return t('teamhub', '{user} assigned a card', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'card_user_unassign')      return t('teamhub', '{user} unassigned a card', { user })
                // TRANSLATORS: activity line — {user} is a display name; "list" = a Deck column/stack, not a bullet list
                if (s === 'stack_create')            return t('teamhub', '{user} created a list', { user })
                // TRANSLATORS: activity line — {user} is a display name; "list" = a Deck column/stack, not a bullet list
                if (s === 'stack_update')            return t('teamhub', '{user} renamed a list', { user })
                // TRANSLATORS: activity line — {user} is a display name; "list" = a Deck column/stack, not a bullet list
                if (s === 'stack_delete')            return t('teamhub', '{user} deleted a list', { user })
                // TRANSLATORS: activity line — {user} is a display name; "board" = a Deck board
                if (s === 'board_create')            return t('teamhub', '{user} created the board', { user })
                // TRANSLATORS: activity line — {user} is a display name; "board" = a Deck board
                if (s === 'board_update')            return t('teamhub', '{user} updated the board', { user })
                // TRANSLATORS: activity line — {user} is a display name; "board" = a Deck board
                if (s === 'board_delete')            return t('teamhub', '{user} deleted the board', { user })
                // TRANSLATORS: activity line — {user} is a display name; "board" = a Deck board
                if (s === 'board_share')             return t('teamhub', '{user} shared the board', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'label_assign')            return t('teamhub', '{user} added a label', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'label_unassign')          return t('teamhub', '{user} removed a label', { user })
                return fallback
            }

            // Calendar / DAV — real subject strings from oc_activity
            if (item.app === 'calendar' || item.app === 'dav') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('add_event') || s.includes('created')) return t('teamhub', '{user} created an event', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('update_event') || s.includes('updated')) return t('teamhub', '{user} updated an event', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('delete_event') || s.includes('deleted')) return t('teamhub', '{user} deleted an event', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_add_self' || s.includes('calendar_add')) return t('teamhub', '{user} added a calendar', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_update_self' || s.includes('calendar_update')) return t('teamhub', '{user} updated a calendar', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_delete_self' || s.includes('calendar_delete')) return t('teamhub', '{user} deleted a calendar', { user })
                const calDetail = s.replace(/_self$|_by$/g, '').replace(/_/g, ' ')
                return user ? t('teamhub', '{user} · {detail}', { user, detail: calDetail }) : calDetail
            }

            // Talk
            if (item.app === 'spreed') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('call'))    return t('teamhub', '{user} started a call', { user })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('message')) return t('teamhub', '{user} sent a message', { user })
                return fallback
            }

            return fallback
        },
        formatTime(datetime) {
            return new Date(datetime).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
        },
    },
}
</script>

<style scoped>
.activity-feed {
    padding: 24px;
    max-width: 720px;
    height: 100%;
    overflow-y: auto;
    box-sizing: border-box;
}

.activity-feed__header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
}

.activity-feed__title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}

.activity-feed__subtitle {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin-right: auto;
}

.activity-feed__refresh {
    background: transparent;
    border: none;
    cursor: pointer;
    color: var(--color-text-maxcontrast);
    padding: 4px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    transition: color 0.15s, background 0.15s;
}

.activity-feed__refresh:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.activity-feed__loading {
    display: flex;
    justify-content: center;
    padding: 40px;
}

.activity-feed__days {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.activity-feed__day-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-maxcontrast);
    padding-bottom: 8px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 4px;
}

.activity-feed__list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.activity-feed__item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border-dark);
}

.activity-feed__item:last-child {
    border-bottom: none;
}

.activity-feed__badge {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 1px;
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}

.activity-feed__badge--circles        { background: #e8f0fe; color: #3b5998; }
.activity-feed__badge--files,
.activity-feed__badge--files_sharing  { background: #e6f4ea; color: #188038; }
.activity-feed__badge--deck           { background: #fce8e6; color: #c5221f; }
.activity-feed__badge--calendar,
.activity-feed__badge--dav            { background: #fef7e0; color: #b45309; }
.activity-feed__badge--spreed         { background: #e8f5e9; color: #1b5e20; }

.activity-feed__body {
    flex: 1;
    min-width: 0;
}

.activity-feed__row {
    display: flex;
    align-items: center;
    gap: 7px;
}

.activity-feed__subject {
    font-size: 13.5px;
    color: var(--color-main-text);
    line-height: 1.4;
}

.activity-feed__meta {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
    font-size: 11.5px;
    color: var(--color-text-maxcontrast);
}

.activity-feed__sep { opacity: 0.5; }

.activity-feed__link {
    display: inline-flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
    opacity: 0.6;
    transition: opacity 0.15s;
}

.activity-feed__link:hover { opacity: 1; color: var(--color-primary-element); }
</style>
