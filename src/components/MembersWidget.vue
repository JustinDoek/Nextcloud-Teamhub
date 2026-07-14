<template>
    <div class="th-members-widget">

        <!--
            Tab bar — mirrors FilesWidget for visual consistency. Tomorrow is
            hidden when the presence module is unavailable (admin-disabled or
            team-disabled), so a team without presence sees only Members + Search.
        -->
        <div class="th-members-widget__tabs" role="tablist" :aria-label="t('teamhub', 'Member views')">
            <button
                v-for="tab in visibleTabs"
                :key="tab.id"
                :id="`th-members-tab-${tab.id}`"
                role="tab"
                :aria-selected="activeTab === tab.id ? 'true' : 'false'"
                :aria-controls="`th-members-panel-${tab.id}`"
                class="th-members-widget__tab"
                :class="{ 'th-members-widget__tab--active': activeTab === tab.id }"
                @click="setTab(tab.id)">
                <component :is="tab.icon" :size="14" aria-hidden="true" />
                {{ tab.label }}
            </button>
        </div>

        <!-- ──────────────────────────────────────── Members tab ── -->
        <div
            id="th-members-panel-members"
            role="tabpanel"
            :aria-labelledby="'th-members-tab-members'"
            :hidden="activeTab !== 'members'"
            class="th-members-widget__panel">

            <div v-if="membersLoading" class="th-members-widget__state">
                <NcLoadingIcon :size="20" />
            </div>

            <div v-else-if="!sortedMembers.length" class="th-members-widget__state">
                <AccountGroupIcon :size="36" class="th-members-widget__empty-icon" />
                <span>{{ t('teamhub', 'No members yet') }}</span>
            </div>

            <ul v-else class="th-members-widget__list">
                <MemberRow
                    v-for="m in sortedMembers"
                    :key="m.userId"
                    :member="m"
                    :talk-available="talkAvailable" />
            </ul>
        </div>

        <!-- ──────────────────────────────────────── Tomorrow tab ── -->
        <div
            v-if="tomorrowTabEnabled"
            id="th-members-panel-tomorrow"
            role="tabpanel"
            :aria-labelledby="'th-members-tab-tomorrow'"
            :hidden="activeTab !== 'tomorrow'"
            class="th-members-widget__panel">

            <div v-if="tomorrowLoading" class="th-members-widget__state">
                <NcLoadingIcon :size="20" />
            </div>

            <div v-else-if="!sortedMembers.length" class="th-members-widget__state">
                <AccountGroupIcon :size="36" class="th-members-widget__empty-icon" />
                <span>{{ t('teamhub', 'No members yet') }}</span>
            </div>

            <template v-else>
                <ul class="th-members-widget__list">
                    <MemberPresenceRow
                        v-for="m in sortedMembers"
                        :key="m.userId"
                        :member="m"
                        :morning="tomorrowSlotFor(m.userId, 0)"
                        :afternoon="tomorrowSlotFor(m.userId, 1)" />
                </ul>

                <!--
                    Footer link routes to the Presence tab on the team tab bar.
                    Parent (TeamWidgetGrid) re-emits as set-view='presence'.
                -->
                <div class="th-members-widget__footer">
                    <button
                        type="button"
                        class="th-members-widget__footer-link"
                        @click="$emit('view-presence-calendar')">
                        {{ t('teamhub', 'View Full Presence Calendar') }}
                    </button>
                </div>
            </template>
        </div>

        <!-- ──────────────────────────────────────── Search tab ── -->
        <div
            id="th-members-panel-search"
            role="tabpanel"
            :aria-labelledby="'th-members-tab-search'"
            :hidden="activeTab !== 'search'"
            class="th-members-widget__panel">

            <div class="th-members-widget__search-box">
                <label class="th-members-widget__search-label" for="th-members-search-input">
                    {{ t('teamhub', 'Search members') }}
                </label>
                <NcTextField
                    id="th-members-search-input"
                    ref="searchInput"
                    v-model="searchQuery"
                    :placeholder="t('teamhub', 'Search by name…')"
                    :label="t('teamhub', 'Search members')"
                    :label-visible="false" />
            </div>

            <div v-if="!searchQuery.trim()" class="th-members-widget__state th-members-widget__state--hint">
                <MagnifyIcon :size="24" class="th-members-widget__empty-icon" aria-hidden="true" />
                <span>{{ t('teamhub', 'Start typing to find a member.') }}</span>
            </div>

            <div v-else-if="!filteredMembers.length" class="th-members-widget__state">
                <span>{{ t('teamhub', 'No members match your search.') }}</span>
            </div>

            <ul v-else class="th-members-widget__list">
                <MemberRow
                    v-for="m in filteredMembers"
                    :key="m.userId"
                    :member="m"
                    :talk-available="talkAvailable" />
            </ul>
        </div>

    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapState } from 'vuex'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'

import AccountGroupIcon  from 'vue-material-design-icons/AccountGroup.vue'
import AccountIcon       from 'vue-material-design-icons/Account.vue'
import CalendarIcon      from 'vue-material-design-icons/Calendar.vue'
import MagnifyIcon       from 'vue-material-design-icons/Magnify.vue'

import MemberRow         from './members/MemberRow.vue'
import MemberPresenceRow from './members/MemberPresenceRow.vue'

export default {
    name: 'MembersWidget',

    components: {
        NcLoadingIcon, NcTextField,
        AccountGroupIcon, AccountIcon, CalendarIcon, MagnifyIcon,
        MemberRow, MemberPresenceRow,
    },

    emits: ['view-presence-calendar'],

    data() {
        return {
            activeTab: 'members',
            // Search tab — local string, filtered against the same allEffectiveMembers list.
            searchQuery: '',
            // Tomorrow tab data — keyed by `${YYYY-MM-DD}_${0|1}` per the team-presence API.
            tomorrowSlots:    {},   // { userId: { 'YYYY-MM-DD_0': slot, 'YYYY-MM-DD_1': slot } }
            tomorrowLoading:  false,
            tomorrowLoadedFor: null, // teamId we last loaded tomorrow for
        }
    },

    computed: {
        ...mapState([
            'currentTeamId',
            'allEffectiveMembers',
            'allEffectiveMembersTalkAvailable',
            'presenceModuleEnabled',
            'presenceConfig',
        ]),

        talkAvailable() {
            return !!this.allEffectiveMembersTalkAvailable
        },

        /**
         * Whether the Tomorrow tab is shown for this team. Two gates:
         *  - presenceModuleEnabled — admin toggle (instance-wide)
         *  - presenceConfig.presence_enabled — per-team toggle
         */
        tomorrowTabEnabled() {
            return !!(this.presenceModuleEnabled && this.presenceConfig && this.presenceConfig.presence_enabled)
        },

        visibleTabs() {
            const tabs = [
                {
                    id: 'members',
                    // TRANSLATORS: tab label — list of team members with status and contact links
                    label: t('teamhub', 'Members'),
                    icon: 'AccountIcon',
                },
            ]
            if (this.tomorrowTabEnabled) {
                tabs.push({
                    id: 'tomorrow',
                    // TRANSLATORS: tab label — members' scheduled presence for tomorrow (morning / afternoon)
                    label: t('teamhub', 'Tomorrow'),
                    icon: 'CalendarIcon',
                })
            }
            tabs.push({
                id: 'search',
                // TRANSLATORS: tab label — search the full member list
                label: t('teamhub', 'Search'),
                icon: 'MagnifyIcon',
            })
            return tabs
        },

        /**
         * Members tab is "loading" only while the initial allEffectiveMembers
         * fetch is in flight AND we have no data yet. Once any data is in the
         * store, render rows immediately even if a refresh is pending.
         */
        membersLoading() {
            const flag = this.$store && this.$store.state && this.$store.state.loading
                ? !!this.$store.state.loading.members
                : false
            return flag && (this.allEffectiveMembers || []).length === 0
        },

        /**
         * Members sorted by merged-presence rank, then by display name.
         *
         * Mirrors the ranking previously used by the avatar stack:
         *   0  NC online           — live, actively available
         *   1  schedule "busy" type — scheduled-but-reachable (not used here
         *                              because we don't have today's schedule
         *                              in this tab — falls back to rank 4 for
         *                              members with no live status)
         *   2  NC dnd / busy        — live, do-not-disturb
         *   3  NC away              — live, stepped away
         *   4  no live status, name fallback
         */
        sortedMembers() {
            const list = (this.allEffectiveMembers || []).slice()
            list.sort((a, b) => {
                const ra = this.presenceRank(a)
                const rb = this.presenceRank(b)
                if (ra !== rb) return ra - rb
                return (a.displayName || '').localeCompare(b.displayName || '')
            })
            return list
        },

        filteredMembers() {
            const q = (this.searchQuery || '').trim().toLowerCase()
            if (!q) return []
            return this.sortedMembers.filter(m =>
                (m.displayName || '').toLowerCase().includes(q)
                || (m.userId || '').toLowerCase().includes(q)
            )
        },
    },

    watch: {
        /**
         * When the team changes:
         *  - reset local tab-bound state (search query, cached tomorrow slots)
         *  - if Tomorrow tab is currently active, refetch for the new team
         */
        currentTeamId: {
            immediate: true,
            handler(newId) {
                this.searchQuery = ''
                this.tomorrowSlots = {}
                this.tomorrowLoadedFor = null
                if (newId && this.activeTab === 'tomorrow' && this.tomorrowTabEnabled) {
                    this.loadTomorrowPresence(newId)
                }
            },
        },
        /**
         * If the team's presence-enabled toggle flips off while we're on the
         * Tomorrow tab, fall back to the Members tab so the user isn't stuck.
         */
        tomorrowTabEnabled(enabled) {
            if (!enabled && this.activeTab === 'tomorrow') {
                this.activeTab = 'members'
            }
        },
    },

    methods: {
        t,
        n,

        setTab(id) {
            this.activeTab = id
            // Lazy-load Tomorrow data on first activation per team
            if (id === 'tomorrow'
                && this.tomorrowTabEnabled
                && this.tomorrowLoadedFor !== this.currentTeamId) {
                this.loadTomorrowPresence(this.currentTeamId)
            }
            // Auto-focus the search input when opening the Search tab
            if (id === 'search') {
                this.$nextTick(() => {
                    const ref = this.$refs.searchInput
                    if (ref && typeof ref.focus === 'function') {
                        ref.focus()
                    } else if (ref?.$el) {
                        const input = ref.$el.querySelector('input')
                        if (input) input.focus()
                    }
                })
            }
        },

        /**
         * Rank one member for the sorted list. Uses live NC status only —
         * the Members tab does not display the schedule for today (the
         * Tomorrow tab covers schedule). For users with no live status this
         * collapses to a name-sorted bucket (rank 4).
         */
        presenceRank(m) {
            const s = m?.ncStatus?.status
            if (s === 'online') return 0
            if (s === 'dnd' || s === 'busy') return 2
            if (s === 'away') return 3
            return 4
        },

        /**
         * Format YYYY-MM-DD for tomorrow in the user's local time zone.
         * The team-presence API expects local-day boundaries.
         */
        tomorrowDate() {
            const d = new Date()
            d.setDate(d.getDate() + 1)
            const yyyy = d.getFullYear()
            const mm = String(d.getMonth() + 1).padStart(2, '0')
            const dd = String(d.getDate()).padStart(2, '0')
            return `${yyyy}-${mm}-${dd}`
        },

        /**
         * Look up the slot for a given member and half-day.
         * Returns the slot object ({ color, label, slug, is_busy, ... }) or null.
         */
        tomorrowSlotFor(userId, half) {
            const userSlots = this.tomorrowSlots[userId] || {}
            const key = `${this.tomorrowDate()}_${half}`
            return userSlots[key] || null
        },

        /**
         * Fetch tomorrow's presence slots for all team members in a single call.
         * The endpoint already supports arbitrary from/to ranges — we just pass
         * tomorrow's date for both.
         */
        async loadTomorrowPresence(teamId) {
            if (!teamId) return
            this.tomorrowLoading = true
            try {
                const day = this.tomorrowDate()
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/presence`),
                    { params: { from: day, to: day } },
                )
                this.tomorrowSlots = (data && data.slots && typeof data.slots === 'object')
                    ? data.slots
                    : {}
                this.tomorrowLoadedFor = teamId
            } catch (e) {
                // Non-fatal — pills render as "no schedule".
                this.tomorrowSlots = {}
            } finally {
                this.tomorrowLoading = false
            }
        },
    },
}
</script>

<style scoped>
.th-members-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

/* ── Tab bar — visually consistent with FilesWidget ───────────── */
.th-members-widget__tabs {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--color-border);
    padding: 0 4px;
    gap: 2px;
    flex-shrink: 0;
}

.th-members-widget__tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 10px 7px;
    font-size: var(--th-font-meta);
    font-weight: 500;
    color: var(--color-text-maxcontrast);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    white-space: nowrap;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
    line-height: 1;
}

.th-members-widget__tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.th-members-widget__tab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.th-members-widget__tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: 600;
}

/* ── Panels ── */
.th-members-widget__panel {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

[hidden] { display: none; }

/* ── States (empty / loading / hint) ── */
.th-members-widget__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 24px 16px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-body);
    text-align: center;
}
.th-members-widget__state--hint {
    padding-top: 28px;
}
.th-members-widget__empty-icon {
    opacity: 0.35;
    color: var(--color-primary-element);
}

/* ── Member list ── */
.th-members-widget__list {
    list-style: none;
    padding: 0;
    margin: 0;
}

/* ── Search box ── */
.th-members-widget__search-box {
    padding: 10px 12px 6px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-main-background);
    position: sticky;
    top: 0;
    z-index: 1;
}
.th-members-widget__search-label {
    /* Visually hidden but read by screen readers; the NcTextField shows
       a placeholder instead. WCAG 1.3.1 — input has a programmatic label. */
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* ── Tomorrow tab footer ── */
.th-members-widget__footer {
    padding: 10px 12px;
    border-top: 1px solid var(--color-border);
    text-align: center;
}
.th-members-widget__footer-link {
    background: transparent;
    border: none;
    padding: 4px 8px;
    color: var(--color-primary-element);
    font-size: 13px;
    cursor: pointer;
    border-radius: var(--border-radius);
}
.th-members-widget__footer-link:hover {
    background: var(--color-background-hover);
    text-decoration: underline;
}
.th-members-widget__footer-link:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
</style>
