<template>
    <!-- v4.20.14 (DESIGN §2.266) — the people a suggested meeting time leaves
         out, and why, behind one icon beside the suggestion. The server sends
         a name and a reason code only: never an event title or an absence type.
         Escape is handled on the trigger and in the panel, as in CalendarWidget:
         NcPopover 9.8.0 passes `fallBackFocus` (sic) to focus-trap, so a panel
         with nothing focusable in it never takes focus and never sees Escape. -->
    <NcPopover
        v-if="people.length > 0"
        popup-role="dialog"
        :shown="shown"
        @update:shown="v => (shown = v)">
        <template #trigger>
            <NcButton
                variant="tertiary"
                class="th-unavailable__trigger"
                :aria-label="triggerLabel"
                :title="triggerLabel"
                @keydown.esc="shown = false">
                <template #icon>
                    <CalendarAlertIcon :size="ICON_BODY" />
                </template>
            </NcButton>
        </template>
        <div class="th-unavailable" @keydown.esc="shown = false">
            <ul class="th-unavailable__list">
                <li v-for="person in people" :key="person.userId" class="th-unavailable__person">
                    {{ sentence(person) }}
                </li>
            </ul>
            <p v-if="hasUnchecked" class="th-unavailable__hint">
                {{ t('teamhub', 'Nextcloud checks a calendar through the account\'s email address. An account without one cannot be checked.') }}
            </p>
        </div>
    </NcPopover>
</template>

<script>
import { NcButton, NcPopover } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import CalendarAlertIcon from 'vue-material-design-icons/CalendarAlert.vue'
import { ICON_BODY } from '../constants/uiTokens.js'

export default {
    name: 'UnavailableAttendees',
    components: { NcButton, NcPopover, CalendarAlertIcon },
    props: {
        // [{ userId, displayName, reason: 'off' | 'unchecked' | 'calendar' }]
        people: { type: Array, default: () => [] },
    },
    data() {
        return {
            ICON_BODY,
            shown: false,
        }
    },
    computed: {
        hasUnchecked() {
            return this.people.some(p => p.reason === 'unchecked')
        },
        triggerLabel() {
            return n('teamhub',
                // TRANSLATORS: icon button beside a suggested meeting time; opens the list of people who cannot come and why
                'Show why %n person is not available',
                'Show why %n people are not available',
                this.people.length, { n: this.people.length })
        },
    },
    methods: {
        t,
        n,
        sentence(person) {
            const name = person.displayName || person.userId
            if (person.reason === 'unchecked') {
                // TRANSLATORS: {name} is a team member; Nextcloud gave no availability answer for their calendar
                return t('teamhub', 'Cannot check the calendar for {name}.', { name })
            }
            if (person.reason === 'off') {
                // TRANSLATORS: {name} is a team member whose presence says they do not work at the suggested time
                return t('teamhub', '{name} is not working at that time.', { name })
            }
            // TRANSLATORS: {name} is a team member whose calendar is busy at the suggested time
            return t('teamhub', '{name} has a calendar conflict.', { name })
        },
    },
}
</script>

<style scoped>
.th-unavailable__trigger {
    flex-shrink: 0;
}
.th-unavailable {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    max-inline-size: calc(var(--th-space-xxl) * 10);
    padding: var(--th-space-sm) var(--th-space-md);
}
.th-unavailable__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    margin: 0;
    padding: 0;
    list-style: none;
}
.th-unavailable__hint {
    margin: 0;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}
</style>
