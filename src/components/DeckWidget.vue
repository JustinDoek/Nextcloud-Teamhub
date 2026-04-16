<template>
    <div class="th-widget">
        <div v-if="deckTasks.length === 0" class="th-widget__state">
            <CardTextIcon :size="36" class="th-widget__empty-icon" />
            <span>{{ t('teamhub', 'No upcoming tasks') }}</span>
        </div>
        <ul v-else class="th-widget__list">
            <li v-for="card in deckTasks" :key="card.id" class="th-widget__row">

                <!-- Task icon badge -->
                <div class="th-widget__badge th-widget__badge--task" aria-hidden="true">
                    <CheckboxMarkedOutlineIcon :size="18" />
                </div>

                <!-- Main content -->
                <div class="th-widget__body">
                    <div class="th-widget__row-top">
                        <a
                            :href="cardUrl(card)"
                            target="_blank"
                            class="th-widget__title th-widget__title--link"
                            :class="{ 'th-widget__title--overdue': card.overdue }">
                            {{ card.title }}
                        </a>
                    </div>
                    <div class="th-widget__row-bottom">
                        <span class="th-widget__meta" :class="{ 'th-widget__meta--overdue': card.overdue }">
                            {{ formatDate(card.duedate) }}
                        </span>
                        <!-- Assignee avatars -->
                        <span v-if="assignees(card).length" class="th-widget__assignees">
                            <NcAvatar
                                v-for="u in assignees(card)"
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
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar } from '@nextcloud/vue'
import CardTextIcon from 'vue-material-design-icons/CardText.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'

export default {
    name: 'DeckWidget',

    components: { NcAvatar, CardTextIcon, CheckboxMarkedOutlineIcon },

    computed: {
        ...mapState(['deckTasks', 'resources']),
    },

    methods: {
        t,

        cardUrl(card) {
            return generateUrl(`/apps/deck/board/${card.boardId}/card/${card.id}`)
        },

        formatDate(duedate) {
            if (!duedate) return ''
            const d = new Date(duedate)
            const now = new Date()
            return d.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
            })
        },

        /**
         * Extracts a flat list of { uid, displayname } from the Deck card's
         * assignedUsers array. Handles both nested (participant.uid) and flat (uid) shapes.
         */
        assignees(card) {
            if (!card.assignedUsers || !card.assignedUsers.length) return []
            return card.assignedUsers
                .map(u => u.participant || u)
                .filter(p => p && p.uid)
        },
    },
}
</script>

<style scoped>
/* ----------------------------------------------------------------
   Shared th-widget design system — mirrors CalendarWidget.vue.
   All three content widgets (Calendar, Deck, Integration) use the
   same structural class names so the rows look identical.
---------------------------------------------------------------- */

.th-widget { padding: 0; }

/* Loading / empty state */
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

/* List */
.th-widget__list { list-style: none; padding: 0; margin: 0; }

.th-widget__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border);
}
.th-widget__row:last-child { border-bottom: none; }

/* Left badge — same dimensions as CalendarWidget date badge */
.th-widget__badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark, #f4f4f4);
    border: 1px solid var(--color-border);
}

.th-widget__badge--task {
    color: var(--color-primary-element);
}

/* Body */
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

/* Title */
.th-widget__title {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-widget__title--link {
    text-decoration: none;
}
.th-widget__title--link:hover { color: var(--color-primary-element); }
.th-widget__title--overdue { color: var(--color-error); }

/* Meta line */
.th-widget__meta {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}
.th-widget__meta--overdue { color: var(--color-error); }

/* Assignee avatars */
.th-widget__assignees {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-left: auto;
}
</style>
