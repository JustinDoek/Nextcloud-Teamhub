<template>
    <div class="deck-widget">
        <div v-if="deckTasks.length === 0" class="deck-widget__empty">
            {{ t('teamhub', 'No upcoming tasks') }}
        </div>

        <ul v-else class="deck-widget__list">
            <li v-for="card in deckTasks" :key="card.id" class="deck-card">
                <!-- Row 1: title + date -->
                <div class="deck-card__row1">
                    <a
                        :href="cardUrl(card)"
                        target="_blank"
                        class="deck-card__title"
                        :class="{ 'deck-card__title--overdue': card.overdue }">
                        {{ card.title }}
                    </a>
                    <span class="deck-card__date" :class="{ 'deck-card__date--overdue': card.overdue }">
                        {{ formatDate(card.duedate) }}
                    </span>
                </div>

                <!-- Row 2: assignee avatars -->
                <div v-if="card.assignedUsers && card.assignedUsers.length" class="deck-card__assignees">
                    <NcAvatar
                        v-for="u in card.assignedUsers"
                        v-if="u.participant && u.participant.uid"
                        :key="u.participant.uid"
                        :user="u.participant.uid"
                        :display-name="u.participant.displayname || u.participant.uid"
                        :show-user-status="false"
                        :disable-menu="false"
                        :size="22" />
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

export default {
    name: 'DeckWidget',
    components: { NcAvatar },
    computed: {
        ...mapState(['deckTasks', 'resources']),
        deckUrl() {
            return generateUrl('/apps/deck/board/' + (this.resources.deck?.board_id || ''))
        },
    },
    methods: {
        t,
        cardUrl(card) {
            return generateUrl(`/apps/deck/board/${card.boardId}/card/${card.id}`)
        },
        formatDate(duedate) {
            const d = new Date(duedate)
            const now = new Date()
            const sameYear = d.getFullYear() === now.getFullYear()
            return d.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                year: sameYear ? undefined : 'numeric',
            })
        },
    },
}
</script>

<style scoped>
.deck-widget {
    padding: 8px 16px 12px;
    border-bottom: 1px solid var(--color-border-dark);
}

.deck-widget__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.deck-widget__title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.deck-widget__empty {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    padding: 4px 0;
}

.deck-widget__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.deck-card__row1 {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 6px;
    min-width: 0;
}

.deck-card__title {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    color: var(--color-main-text);
}

.deck-card__title:hover { color: var(--color-primary-element); }

.deck-card__title--overdue { color: var(--color-error); }

.deck-card__date {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    flex-shrink: 0;
}

.deck-card__date--overdue { color: var(--color-error); }

.deck-card__assignees {
    display: flex;
    gap: 3px;
    margin-top: 4px;
}
</style>
