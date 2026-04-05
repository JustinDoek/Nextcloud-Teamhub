<template>
    <div class="deck-widget">
        <div v-if="deckTasks.length === 0" class="deck-widget__empty">
            <CardTextIcon :size="36" class="deck-widget__empty-icon" />
            <span>{{ t('teamhub', 'No upcoming tasks') }}</span>
        </div>
        <ul v-else class="deck-widget__list">
            <li v-for="card in deckTasks" :key="card.id" class="deck-card">
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
import CardTextIcon from 'vue-material-design-icons/CardText.vue'

export default {
    name: 'DeckWidget',
    components: { NcAvatar, CardTextIcon },
    computed: {
        ...mapState(['deckTasks', 'resources']),
    },
    methods: {
        t,
        cardUrl(card) {
            return generateUrl(`/apps/deck/board/${card.boardId}/card/${card.id}`)
        },
        formatDate(duedate) {
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
    },
}
</script>

<style scoped>
.deck-widget { padding: 0 0 4px; }
.deck-widget__empty {
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
.deck-widget__empty-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}
.deck-widget__list { list-style: none; padding: 0; margin: 0; }
.deck-card {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border-dark);
}
.deck-card:last-child { border-bottom: none; }
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
    font-size: 15px;
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
.deck-card__assignees { display: flex; gap: 3px; margin-top: 4px; }
</style>
