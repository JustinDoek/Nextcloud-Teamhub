<template>
    <div class="th-birthdays">
        <!-- Empty departments remain visually empty. Do not disclose privacy or eligibility diagnostics in a shared widget. -->
        <ul v-if="!loading && !error && !unavailable && birthdays.length" class="th-birthdays__list">
            <li v-for="person in birthdays" :key="person.userId" class="th-birthdays__person">
                <NcAvatar :user="person.userId" :display-name="person.displayName" :size="AVATAR_MD" :hide-status="true" />
                <span class="th-birthdays__name">{{ person.displayName }}</span>
                <span class="th-birthdays__date">
                    {{ formatMonthDay(person.monthDay) }}
                    <span v-if="person.today" class="th-birthdays__today">{{ t('teamhub', 'Today') }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { NcAvatar } from '@nextcloud/vue'
import { mapState } from 'vuex'
import { AVATAR_MD } from '../constants/uiTokens.js'

export default {
    name: 'BirthdaysWidget',
    components: { NcAvatar },
    data() { return { AVATAR_MD, loading: false, error: false, unavailable: false, birthdays: [] } },
    computed: { ...mapState(['currentTeamId']) },
    watch: { currentTeamId: { immediate: true, handler() { this.load() } } },
    methods: {
        t,
        formatMonthDay(monthDay) {
            if (!/^\d{2}-\d{2}$/.test(monthDay || '')) return ''
            const [month, day] = monthDay.split('-').map(Number)
            // The constant year is a leap year; the UI deliberately never
            // includes any person's actual birth year or age.
            return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', timeZone: 'UTC' })
                .format(new Date(Date.UTC(2000, month - 1, day)))
        },
        async load() {
            const teamId = this.currentTeamId
            this.birthdays = []
            this.unavailable = false
            this.error = false
            if (!teamId) return
            this.loading = true
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/teams/${encodeURIComponent(teamId)}/people/birthdays`))
                if (teamId === this.currentTeamId) {
                    this.birthdays = data?.birthdays || []
                    this.unavailable = data?.unavailable === true
                }
            } catch (e) {
                if (teamId === this.currentTeamId) this.error = true
            } finally {
                if (teamId === this.currentTeamId) this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.th-birthdays { padding: var(--th-space-sm, 12px); }
.th-birthdays__list { list-style: none; margin: 0; padding: 0; }
.th-birthdays__person { display: flex; align-items: center; gap: var(--th-space-sm, 12px); padding-block: 8px; }
.th-birthdays__name { font-weight: 600; min-width: 0; overflow-wrap: anywhere; flex: 1; }
.th-birthdays__date { color: var(--color-text-maxcontrast); white-space: nowrap; }
.th-birthdays__today { margin-inline-start: 6px; color: var(--color-primary-element); font-weight: 600; }
.th-birthdays__notice { margin: 0; color: var(--color-text-maxcontrast); }
</style>
