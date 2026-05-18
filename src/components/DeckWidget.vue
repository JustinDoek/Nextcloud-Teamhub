<template>
    <div class="th-widget">

        <!-- Unassigned nudge — shown per board whenever unassigned > 0.
             Appears whether or not there are upcoming tasks. -->
        <div v-if="unassignedBoards.length" class="th-unassigned">
            <button
                v-for="b in unassignedBoards"
                :key="b.boardId"
                type="button"
                class="th-unassigned__row"
                :aria-label="n('teamhub', '{n} unassigned card in {board} — open board', '{n} unassigned cards in {board} — open board', b.count, { n: b.count, board: b.boardName || 'board' })"
                @click="openBoard(b)">
                <AlertCircleOutline :size="15" class="th-unassigned__icon" aria-hidden="true" />
                <span class="th-unassigned__text">
                    <template v-if="b.boardName">
                        <strong>{{ b.boardName }}</strong>{{ t('teamhub', ':') }}
                    </template>
                    {{ n('teamhub', '{n} unassigned card', '{n} unassigned cards', b.count, { n: b.count }) }}
                </span>
                <ChevronRightIcon :size="13" class="th-unassigned__ext" aria-hidden="true" />
            </button>
        </div>

        <!-- Empty state -->
        <div v-if="mergedTasks.length === 0" class="th-widget__state">
            <CardTextIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'No upcoming tasks') }}</span>
        </div>

        <ul v-else class="th-widget__list">
            <li
                v-for="task in mergedTasks"
                :key="task._key"
                class="th-widget__row">

                <!-- Source badge icon -->
                <div
                    class="th-widget__badge"
                    :class="task.source === 'deck' ? 'th-widget__badge--deck' : 'th-widget__badge--tasks'"
                    aria-hidden="true">
                    <CheckboxMarkedOutlineIcon v-if="task.source === 'deck'" :size="18" />
                    <ClipboardCheckOutlineIcon v-else :size="18" />
                </div>

                <!-- Main content -->
                <div class="th-widget__body">
                    <div class="th-widget__row-top">
                        <a
                            :href="task.url"
                            target="_blank"
                            class="th-widget__title th-widget__title--link"
                            :class="{ 'th-widget__title--overdue': task.overdue }">
                            {{ task.title }}
                        </a>
                    </div>
                    <div class="th-widget__row-bottom">
                        <!-- Due date -->
                        <span
                            v-if="task.duedate"
                            class="th-widget__meta"
                            :class="{ 'th-widget__meta--overdue': task.overdue }">
                            {{ formatDate(task.duedate) }}
                        </span>

                        <!-- Source pills: board name + app label -->
                        <template v-if="task.source === 'deck'">
                            <span
                                v-if="task.boardName && resources.deck && resources.deck.length > 1"
                                class="th-widget__source-pill th-widget__source-pill--board"
                                :title="task.boardName">
                                {{ truncate(task.boardName, 20) }}
                            </span>
                            <span class="th-widget__source-pill th-widget__source-pill--deck">
                                {{ t('teamhub', 'Deck') }}
                            </span>
                        </template>
                        <span v-else class="th-widget__source-pill th-widget__source-pill--tasks">
                            {{ t('teamhub', 'Personal task') }}
                        </span>

                        <!-- Assignee avatars (Deck only) -->
                        <span v-if="task.source === 'deck' && assignees(task).length" class="th-widget__assignees">
                            <NcAvatar
                                v-for="u in assignees(task)"
                                :key="u.uid"
                                :user="u.uid"
                                :display-name="u.displayname || u.uid"
                                :show-user-status="false"
                                :disable-menu="false"
                                :size="20" />
                        </span>
                    </div>
                </div>

            </li>
        </ul>
    </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar } from '@nextcloud/vue'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import ClipboardCheckOutlineIcon from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

export default {
    name: 'DeckWidget',

    components: {
        NcAvatar,
        CardTextIcon,
        CheckboxMarkedOutlineIcon,
        ClipboardCheckOutlineIcon,
        AlertCircleOutline,
        ChevronRightIcon,
    },

    computed: {
        ...mapState(['deckTasks', 'teamTasks', 'resources', 'deckUnassignedCounts']),

        /**
         * Boards with at least one unassigned non-overdue card, sorted by
         * count descending so the board needing most attention is first.
         */
        unassignedBoards() {
            return Object.entries(this.deckUnassignedCounts || {})
                .filter(([, b]) => b.count > 0)
                .map(([boardId, b]) => ({ boardId, count: b.count, boardName: b.boardName }))
                .sort((a, b) => b.count - a.count)
        },

        /**
         * Merge Deck cards and NC Tasks VTODOs into a single list sorted by
         * due date. Tasks without a due date sort last.
         * Each entry gets a `source` field ('deck' | 'tasks') and a unique `_key`.
         */
        mergedTasks() {
            const now = new Date()

            // Normalise Deck cards
            const deckItems = (this.deckTasks || []).map(card => ({
                _key:          'deck-' + card.id,
                source:        'deck',
                id:            card.id,
                title:         card.title,
                duedate:       card.duedate || null,
                overdue:       card.duedate ? new Date(card.duedate) < now : false,
                url:           generateUrl(`/apps/deck/board/${card.boardId}/card/${card.id}`),
                assignedUsers: card.assignedUsers || [],
                boardName:     card.boardName || '',
            }))

            // NC Tasks VTODOs — only shown when tasks app AND calendar are active.
            const showTasks = this.resources && this.resources.tasks && this.resources.calendar && this.resources.calendar.length > 0
            const taskItems = showTasks
                ? (this.teamTasks || []).map(task => ({
                    _key:    'task-' + task.id,
                    source:  'tasks',
                    id:      task.id,
                    title:   task.title,
                    duedate: task.duedate || null,
                    overdue: task.duedate ? new Date(task.duedate) < now : false,
                    url:     task.url || '/apps/tasks',
                }))
                : []

            const merged = [...deckItems, ...taskItems]

            // Sort by due date ascending; null due dates go last.
            merged.sort((a, b) => {
                if (!a.duedate && !b.duedate) return 0
                if (!a.duedate) return 1
                if (!b.duedate) return -1
                return new Date(a.duedate) - new Date(b.duedate)
            })


            return merged
        },
    },

    methods: {
        t,
        n,
        ...mapMutations(['SET_VIEW', 'SET_SELECTED_DECK_BOARD']),

        /**
         * Open the board in the TeamHub iframe (same as clicking the Deck tab).
         * With multiple boards, SET_SELECTED_DECK_BOARD pre-selects this board
         * so deckUrl in TeamView resolves to the right one.
         */
        openBoard(b) {
            this.SET_SELECTED_DECK_BOARD({ board_id: b.boardId, name: b.boardName })
            this.SET_VIEW('deck')
        },

        truncate(str, max) {
            if (!str) return ''
            return str.length > max ? str.slice(0, max) + '…' : str
        },

        formatDate(duedate) {
            if (!duedate) return ''
            const d = new Date(duedate)
            const now = new Date()
            return d.toLocaleString(undefined, {
                month:  'short',
                day:    'numeric',
                hour:   '2-digit',
                minute: '2-digit',
                year:   d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
            })
        },

        /**
         * Extract flat { uid, displayname } from Deck's assignedUsers.
         * Handles both nested (participant.uid) and flat (uid) shapes.
         */
        assignees(task) {
            if (!task.assignedUsers || !task.assignedUsers.length) return []
            return task.assignedUsers
                .map(u => u.participant || u)
                .filter(p => p && p.uid)
        },
    },
}
</script>

<style scoped>
/* ----------------------------------------------------------------
   Shared th-widget design system — mirrors CalendarWidget.vue
---------------------------------------------------------------- */

.th-widget { padding: 0; }

.th-widget__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 20px 16px;
    color: var(--color-text-maxcontrast);
    font-size: 15px;
    text-align: center;
}
.th-widget__empty-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}

.th-widget__list { list-style: none; padding: 0; margin: 0; }

.th-widget__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border);
}
.th-widget__row:last-child { border-bottom: none; }

/* Left badge */
.th-widget__badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-large);
    border: 1px solid var(--color-border);
}

/* Deck badge — primary blue */
.th-widget__badge--deck {
    background: var(--color-primary-element-light, var(--color-background-dark));
    color: var(--color-primary-element);
}

/* Tasks badge — muted teal, distinct from Deck's primary blue */
.th-widget__badge--tasks {
    background: var(--color-info-soft, rgba(14, 116, 144, 0.10));
    color: var(--color-info-text, var(--color-main-text));
}

.th-widget__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.th-widget__row-top {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.th-widget__row-bottom {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.th-widget__title {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-widget__title--link { text-decoration: none; }
.th-widget__title--link:hover { color: var(--color-primary-element); }
.th-widget__title--overdue { color: var(--color-error-text); }

.th-widget__meta {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}
.th-widget__meta--overdue { color: var(--color-error-text); }

/* Source pill */
.th-widget__source-pill {
    display: inline-flex;
    align-items: center;
    height: 18px;
    padding: 0 7px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.03em;
    white-space: nowrap;
    line-height: 1;
    flex-shrink: 0;
}

.th-widget__source-pill--deck {
    background: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
    color: var(--color-primary-element);
    border: 1px solid var(--color-primary-element-light, rgba(0, 130, 201, 0.25));
}

/* Board name pill — neutral, to distinguish from the app label pill */
.th-widget__source-pill--board {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    border: 1px solid var(--color-border);
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Personal task pill — muted teal, distinct from Deck's primary blue */
.th-widget__source-pill--tasks {
    background: var(--color-info-soft, rgba(14, 116, 144, 0.10));
    color: var(--color-info-text, var(--color-main-text));
    border: 1px solid var(--color-info-element-light, rgba(14, 116, 144, 0.28));
}

.th-widget__assignees {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-left: auto;
}

/* ----------------------------------------------------------------
   Unassigned nudge section — amber, top of widget body
---------------------------------------------------------------- */

.th-unassigned {
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid var(--color-border);
}

.th-unassigned__row {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    width: 100%;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    background: var(--color-warning-soft, rgba(245, 158, 11, 0.08));
    border: none;
    border-bottom: 1px solid var(--color-warning-element-light, rgba(245, 158, 11, 0.18));
    color: var(--color-warning-text, #92400e);
    font-size: 13px;
    font-family: inherit;
    line-height: 1.3;
    transition: background 0.1s;
}

.th-unassigned__row:last-child {
    border-bottom: none;
}

.th-unassigned__row:hover {
    background: var(--color-warning-soft, rgba(245, 158, 11, 0.15));
}

.th-unassigned__row:focus-visible {
    outline: 2px solid var(--color-warning, #f59e0b);
    outline-offset: -2px;
}

.th-unassigned__icon {
    flex-shrink: 0;
    opacity: 0.8;
}

.th-unassigned__text {
    flex: 1;
    min-width: 0;
}

.th-unassigned__ext {
    flex-shrink: 0;
    opacity: 0.5;
}
</style>
