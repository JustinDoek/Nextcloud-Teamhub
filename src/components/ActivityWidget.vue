<template>
    <div class="activity-widget">
        <!-- Loading -->
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading…') }}</span>
        </div>

        <!-- Empty -->
        <div v-else-if="!activities.length" class="th-widget__state th-widget__state--empty">
            <span class="th-widget__state-text">{{ t('teamhub', 'No recent activity') }}</span>
        </div>

        <!-- Feed -->
        <ul v-else class="th-widget__rows">
            <li
                v-for="item in visibleActivities"
                :key="item.activity_id"
                class="th-widget__row activity-widget__item">
                <!-- App icon badge -->
                <div class="activity-widget__badge" :class="'activity-widget__badge--' + item.app">
                    <component :is="iconComponent(item.icon)" :size="14" />
                </div>

                <div class="activity-widget__body">
                    <div class="activity-widget__subject-row">
                        <NcAvatar
                            v-if="item.user"
                            :user="item.user"
                            :display-name="item.user"
                            :size="20"
                            :show-user-status="false"
                            :disable-menu="true"
                            class="activity-widget__avatar" />
                        <span class="activity-widget__subject">{{ item.subjectText }}</span>
                    </div>
                    <div class="activity-widget__meta">
                        <span>{{ appLabel(item.app) }}</span>
                        <span class="activity-widget__sep">·</span>
                        <span :title="formatAbsoluteTime(item.datetime)">
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

/**
 * Wrap a dynamic value for safe substitution into a translated string that
 * will be rendered via plain {{ }} text interpolation — never v-html.
 *
 * @nextcloud/l10n's t() HTML-escapes named placeholder values by default
 * (so the result is safe to insert via v-html elsewhere in NC). subjectText
 * here is always rendered as a Vue text node, which Vue already escapes on
 * its own — so without this wrapper, a card title or username containing a
 * literal `"` ends up as a visible `&quot;` instead of a quote mark (v3.78.7
 * bugfix). Numbers/booleans pass through t() unescaped already and don't
 * need this.
 */
function raw(value) {
    return { value: value ?? '', escape: false }
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
            // Precompute the formatted subject once per visible item (perf pass
            // V6) instead of calling {{ formatSubject(item) }} in the template,
            // which re-ran the branch ladder on every render.
            return this.activities.slice(0, 5).map(item => ({ ...item, subjectText: this.formatSubject(item) }))
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
            const user = item.user || ''
            const file = item.file ? item.file.split('/').pop() : (item.object_id || '')
            const detail = s.replace(/_/g, ' ')
            // TRANSLATORS: fallback activity line, e.g. "alice · card moved". {user} is a name, {detail} is a machine-generated description.
            const fallback = user ? t('teamhub', '{user} · {detail}', { user: raw(user), detail: raw(detail) }) : detail

            // Circles — member events
            if (item.app === 'circles') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_joined' || s.includes('member_join') || s.includes('joined')) return t('teamhub', '{user} joined the team', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_left'   || s.includes('member_left') || s.includes('left'))   return t('teamhub', '{user} left the team', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_added'  || s.includes('member_add'))                          return t('teamhub', '{user} was added to the team', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'circle_member_removed'|| s.includes('member_remove'))                       return t('teamhub', '{user} was removed from the team', { user: raw(user) })
                return fallback
            }

            // Files
            if (item.app === 'files' || item.app === 'files_sharing') {
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('created'))  return t('teamhub', '{user} uploaded {file}', { user: raw(user), file: raw(file) })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('changed'))  return t('teamhub', '{user} edited {file}', { user: raw(user), file: raw(file) })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('deleted'))  return t('teamhub', '{user} deleted {file}', { user: raw(user), file: raw(file) })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('restored')) return t('teamhub', '{user} restored {file}', { user: raw(user), file: raw(file) })
                // TRANSLATORS: activity line — {user} is a display name, {file} is a filename
                if (s.includes('shared'))   return t('teamhub', '{user} shared {file}', { user: raw(user), file: raw(file) })
                return user ? t('teamhub', '{user} · {detail}', { user: raw(user), detail: raw(file) }) : file
            }

            // Deck — exact subject strings from oc_activity.
            // {card} and {board} are optional decorated fragments (e.g. ' "Title"',
            // ' — Board'); they are empty strings when absent. Kept as named
            // placeholders so translators can reposition them within the sentence.
            if (item.app === 'deck') {
                const board = item.board_name ? ` — ${item.board_name}` : ''
                const card  = item.card_title  ? ` "${item.card_title}"` : ''
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_create')             return t('teamhub', '{user} created card{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {board} optional ' — board name'
                if (s === 'card_update_title')       return t('teamhub', '{user} renamed a card{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_update_description') return t('teamhub', '{user} updated card description{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_update_duedate')     return t('teamhub', '{user} set due date on{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_update_archive')     return t('teamhub', '{user} archived{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {board} optional ' — board name'
                if (s === 'card_delete')             return t('teamhub', '{user} deleted a card{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_user_assign')        return t('teamhub', '{user} assigned{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'card_user_unassign')      return t('teamhub', '{user} unassigned{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "list" = a Deck column/stack; {board} optional ' — board name'
                if (s === 'stack_create')            return t('teamhub', '{user} created a list{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "list" = a Deck column/stack; {board} optional ' — board name'
                if (s === 'stack_update')            return t('teamhub', '{user} renamed a list{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "list" = a Deck column/stack; {board} optional ' — board name'
                if (s === 'stack_delete')            return t('teamhub', '{user} deleted a list{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "board" = a Deck board; {board} optional ' — board name'
                if (s === 'board_create')            return t('teamhub', '{user} created the board{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "board" = a Deck board; {board} optional ' — board name'
                if (s === 'board_update')            return t('teamhub', '{user} updated the board{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "board" = a Deck board; {board} optional ' — board name'
                if (s === 'board_delete')            return t('teamhub', '{user} deleted the board{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; "board" = a Deck board; {board} optional ' — board name'
                if (s === 'board_share')             return t('teamhub', '{user} shared the board{board}', { user: raw(user), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'label_assign')            return t('teamhub', '{user} added a label{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                // TRANSLATORS: activity line — {user} a name; {card} optional ' "card title"'; {board} optional ' — board name'
                if (s === 'label_unassign')          return t('teamhub', '{user} removed a label{card}{board}', { user: raw(user), card: raw(card), board: raw(board) })
                return user ? t('teamhub', '{user} · {detail}{board}', { user: raw(user), detail: raw(detail), board: raw(board) }) : detail
            }

            // Calendar / DAV — real subject strings from oc_activity
            if (item.app === 'calendar' || item.app === 'dav') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('add_event') || s.includes('created')) return t('teamhub', '{user} created an event', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('update_event') || s.includes('updated')) return t('teamhub', '{user} updated an event', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('delete_event') || s.includes('deleted')) return t('teamhub', '{user} deleted an event', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_add_self' || s.includes('calendar_add')) return t('teamhub', '{user} added a calendar', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_update_self' || s.includes('calendar_update')) return t('teamhub', '{user} updated a calendar', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s === 'calendar_delete_self' || s.includes('calendar_delete')) return t('teamhub', '{user} deleted a calendar', { user: raw(user) })
                const calDetail = s.replace(/_self$|_by$/g, '').replace(/_/g, ' ')
                return user ? t('teamhub', '{user} · {detail}', { user: raw(user), detail: raw(calDetail) }) : calDetail
            }

            // Talk
            if (item.app === 'spreed') {
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('call'))    return t('teamhub', '{user} started a call', { user: raw(user) })
                // TRANSLATORS: activity line — {user} is a display name
                if (s.includes('message')) return t('teamhub', '{user} sent a message', { user: raw(user) })
                return fallback
            }

            return fallback
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
/* Widget-specific only — shared classes (.th-widget__row, .th-widget__state,
   .th-widget__spinner, .th-widget__rows) come from widget-tokens.css. */

/* Row override: activity items use flex-start (top-aligned) because the
   subject text may wrap to multiple lines while the badge stays put. */
.activity-widget__item {
    align-items: flex-start;
    padding: 7px 14px;
    gap: 8px;
}

/* App-coloured badge — hex values are deliberate identifiers per app,
   not status; they stay as-is rather than going through brand tokens. */
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

.activity-widget__subject-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.activity-widget__avatar { flex-shrink: 0; }

/* Subject — primary line.
   Override: weight 400 (regular). The rest of the app uses medium (500)
   for the primary line, but activity subjects are sentences with embedded
   entity names rather than titles, so regular weight reads more naturally
   here. Size still carries the hierarchy. */
.activity-widget__subject {
    font-size: var(--th-widget-row-primary-size);
    font-weight: 400;
    color: var(--color-main-text);
    line-height: 1.35;
    word-break: break-word;
}

/* Meta line — smaller, regular weight */
.activity-widget__meta {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
}

.activity-widget__sep { opacity: 0.5; }

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

/* Footer */
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
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-primary-element);
    padding: 2px 0;
    transition: opacity 0.15s;
}
.activity-widget__more:hover { opacity: 0.75; }

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
