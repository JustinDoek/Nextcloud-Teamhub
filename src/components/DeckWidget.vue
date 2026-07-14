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
        <div v-if="mergedTasks.length === 0" class="th-widget__state th-widget__state--empty">
            <CardTextIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'No upcoming tasks') }}</span>
        </div>

        <ul v-else class="th-widget__rows">
            <li
                v-for="task in mergedTasks"
                :key="task._key"
                class="th-widget__row">

                <!-- Source badge icon -->
                <div
                    class="th-deck__badge"
                    :class="task.source === 'deck' ? 'th-deck__badge--deck' : 'th-deck__badge--tasks'"
                    aria-hidden="true">
                    <CheckboxMarkedOutlineIcon v-if="task.source === 'deck'" :size="18" />
                    <ClipboardCheckOutlineIcon v-else :size="18" />
                </div>

                <!-- Main content -->
                <div class="th-deck__body">
                    <a
                        :href="task.url"
                        target="_blank"
                        class="th-deck__title"
                        :class="{ 'th-deck__title--overdue': task.overdue }">
                        {{ task.title }}
                    </a>
                    <div class="th-deck__meta">
                        <!-- Due date -->
                        <span
                            v-if="task.duedate"
                            :class="{ 'th-deck__meta--overdue': task.overdue }">
                            {{ formatDate(task.duedate) }}
                        </span>

                        <!-- Source pills: board name + app label.
                             Shared outline pill vocabulary. -->
                        <template v-if="task.source === 'deck'">
                            <span
                                v-if="task.boardName && resources.deck && resources.deck.length > 1"
                                class="th-widget__pill th-widget__pill--outline th-widget__pill--neutral th-deck__boardname"
                                :title="task.boardName">
                                {{ truncate(task.boardName, 20) }}
                            </span>
                            <span class="th-widget__pill th-widget__pill--outline th-widget__pill--primary">
                                {{ t('teamhub', 'Deck') }}
                            </span>
                        </template>
                        <span v-else class="th-widget__pill th-widget__pill--outline th-widget__pill--neutral">
                            {{ t('teamhub', 'Personal task') }}
                        </span>

                        <!-- Assignee avatars (Deck only) -->
                        <span v-if="task.source === 'deck' && assignees(task).length" class="th-deck__assignees">
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
/* Widget-specific only — shared classes from widget-tokens.css */

/* Source badge — distinct to Deck widget */
.th-deck__badge {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-large);
    border: 1px solid var(--color-border);
}
/* v3.100.14: neutral decorative tile per SKILLS.md — the primary-
   coloured icon carries the "deck" accent. Was
   --color-primary-element-light with a background-dark fallback that
   already conceded this should be neutral. */
.th-deck__badge--deck {
    background: var(--color-background-dark);
    color: var(--color-primary-element);
}
.th-deck__badge--tasks {
    background: var(--color-background-dark);
    color: var(--color-main-text);
}

.th-deck__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.th-deck__title {
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}
.th-deck__title:hover { color: var(--color-primary-element); }
/* v3.100.16: NC theme token (was --th-color-error hex). */
.th-deck__title--overdue { color: var(--color-error-text); }

.th-deck__meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}
.th-deck__meta--overdue { color: var(--color-error-text); }

.th-deck__boardname {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-deck__assignees {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-left: auto;
}

/* ─── Unassigned nudge — amber row, top of widget body ─── */

.th-unassigned {
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid var(--color-border);
}

/* v3.100.14: switched from the deprecated --th-color-warning-soft
   token to the SKILLS.md-standard --color-warning + --color-warning-text
   pair, so the unassigned-card row reads as an actual warning state at
   full saturation. The row separator becomes a translucent overlay of
   the text colour instead of a second warning tone. */
.th-unassigned__row {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    width: 100%;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    background: var(--color-warning);
    border: none;
    border-bottom: 1px solid var(--color-warning-text);
    color: var(--color-warning-text);
    font-size: var(--th-widget-row-meta-size);
    font-family: inherit;
    line-height: 1.3;
    transition: background 0.1s;
}

.th-unassigned__row:last-child {
    border-bottom: none;
}

.th-unassigned__row:hover {
    background: var(--color-warning-hover);
}

.th-unassigned__row:focus-visible {
    outline: 2px solid var(--color-warning-text);
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
