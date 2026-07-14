<template>
    <div class="th-health-widget" :aria-busy="loading">
        <!-- Loading placeholder (first fetch only; subsequent refetches
             re-use the last payload silently) -->
        <div v-if="loading && !payload" class="th-health-widget__loading">
            {{ t('teamhub', 'Loading project health') }}
        </div>

        <!-- Error state — always shown for a hard fetch failure, even when we
             have a stale payload, so admins know the numbers may be stale -->
        <div v-else-if="fetchError" class="th-health-widget__error" role="alert">
            {{ t('teamhub', 'Could not load project health: {error}', { error: fetchError }) }}
        </div>

        <!-- Non-project / non-execution / gate-failure —
             this shouldn't render because the grid v-if hides us,
             but keep a defensive fallback -->
        <div v-else-if="!payload || !payload.canView" class="th-health-widget__empty">
            {{ t('teamhub', 'Project health is only available for Advanced projects.') }}
        </div>

        <!-- Real content — three tiles -->
        <ul v-else class="th-health-widget__tiles" role="list">
            <!-- Tile 1 — Budget & Time -->
            <li class="th-health-tile" :class="`th-health-tile--${budgetTimeStatus}`">
                <div class="th-health-tile__row">
                    <WalletOutline :size="16" aria-hidden="true" class="th-health-tile__icon" />
                    <span class="th-health-tile__label">{{ t('teamhub', 'Budget & Time') }}</span>
                </div>
                <div class="th-health-tile__signal">
                    <template v-if="budgetTimeStatus === 'ok'">
                        {{ t('teamhub', 'All within bounds.') }}
                    </template>
                    <template v-else>
                        <span v-if="payload.budgetTime.projectOverBudget" class="th-health-tile__chip th-health-tile__chip--red">
                            {{ t('teamhub', 'Project over budget') }}
                        </span>
                        <span v-if="payload.budgetTime.lanesOverBudget > 0" class="th-health-tile__chip th-health-tile__chip--red">
                            {{ n('teamhub', '{n} lane over budget', '{n} lanes over budget', payload.budgetTime.lanesOverBudget, { n: payload.budgetTime.lanesOverBudget }) }}
                        </span>
                        <span v-if="payload.budgetTime.membersOverHours > 0" class="th-health-tile__chip th-health-tile__chip--red">
                            {{ n('teamhub', '{n} member over hours', '{n} members over hours', payload.budgetTime.membersOverHours, { n: payload.budgetTime.membersOverHours }) }}
                        </span>
                    </template>
                </div>
                <div class="th-health-tile__actions">
                    <button type="button" class="th-health-tile__link" @click="openTab('budget')">
                        {{ t('teamhub', 'Open Budget') }}
                    </button>
                    <button type="button" class="th-health-tile__link" @click="openTab('time')">
                        {{ t('teamhub', 'Open Time') }}
                    </button>
                </div>
            </li>

            <!-- Tile 2 — Milestones -->
            <li class="th-health-tile" :class="`th-health-tile--${milestonesStatus}`">
                <div class="th-health-tile__row">
                    <FlagOutline :size="16" aria-hidden="true" class="th-health-tile__icon" />
                    <span class="th-health-tile__label">{{ t('teamhub', 'Milestones') }}</span>
                </div>
                <div class="th-health-tile__signal">
                    <template v-if="payload.milestones.total === 0">
                        {{ t('teamhub', 'No dated milestones yet.') }}
                    </template>
                    <template v-else>
                        <ul class="th-health-milestone-list" role="list">
                            <li
                                v-for="m in payload.milestones.upcoming"
                                :key="m.id"
                                class="th-health-milestone"
                                :class="`th-health-milestone--${m.status}`">
                                <span class="th-health-milestone__label">{{ m.label }}</span>
                                <span class="th-health-milestone__meta">
                                    <span class="th-health-milestone__date">{{ formatDate(m.date) }}</span>
                                    <span class="th-health-milestone__status">{{ milestoneStatusLabel(m) }}</span>
                                </span>
                            </li>
                        </ul>
                    </template>
                </div>
                <div class="th-health-tile__actions">
                    <button type="button" class="th-health-tile__link" @click="openTab('timeline')">
                        {{ t('teamhub', 'Open Timeline') }}
                    </button>
                    <!-- v3.99.8 — when a milestone is slipping because of a
                         pending decision (not a past-due Deck card), the
                         Timeline can't show the cause. Surface a second
                         link straight to Decisions so the user has a path
                         to the actual blocker. -->
                    <button v-if="hasMilestoneWithPendingDecision"
                        type="button"
                        class="th-health-tile__link"
                        @click="openDecisionsAwaitingApproval">
                        {{ t('teamhub', 'Open Decisions') }}
                    </button>
                </div>
            </li>

            <!-- Tile 3 — Quality -->
            <li class="th-health-tile" :class="`th-health-tile--${qualityStatus}`">
                <div class="th-health-tile__row">
                    <AlertOctagonOutline :size="16" aria-hidden="true" class="th-health-tile__icon" />
                    <span class="th-health-tile__label">{{ t('teamhub', 'Quality') }}</span>
                </div>
                <div class="th-health-tile__signal">
                    <template v-if="qualityStatus === 'ok'">
                        {{ t('teamhub', 'No open questions or decisions.') }}
                    </template>
                    <template v-else>
                        <span v-if="payload.quality.decisionsEnabled && payload.quality.openDecisions > 0" class="th-health-tile__chip th-health-tile__chip--amber">
                            {{ n('teamhub', '{n} open decision', '{n} open decisions', payload.quality.openDecisions, { n: payload.quality.openDecisions }) }}
                        </span>
                        <span v-if="payload.quality.unsolvedQuestions > 0" class="th-health-tile__chip th-health-tile__chip--amber">
                            {{ n('teamhub', '{n} unsolved question', '{n} unsolved questions', payload.quality.unsolvedQuestions, { n: payload.quality.unsolvedQuestions }) }}
                        </span>
                    </template>
                </div>
                <div class="th-health-tile__actions">
                    <!-- v3.99.8 — Quality's count today is 100% open
                         decisions (unsolvedQuestions was never wired up
                         end-to-end; the API always returns 0). Justin's
                         call: link straight to the Decisions tab so the
                         click lands on the actual list, not the empty
                         Messages "home" (which never resolved to a real
                         view key anyway — it emitted 'home' but no view
                         is registered under that key). -->
                    <button type="button" class="th-health-tile__link" @click="openDecisionsAwaitingApproval">
                        {{ t('teamhub', 'Open Decisions') }}
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import WalletOutline from 'vue-material-design-icons/WalletOutline.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'

/**
 * ProjectHealthWidget (v3.97.0, Track E Session 6).
 *
 * Draggable widget in the team layout grid. Only rendered when the viewer
 * is an Advanced-project member currently in execution phase AND both
 * can_view_budget and can_view_time are true (gate lives in TeamWidgetGrid
 * — this component trusts the parent to have gated it).
 *
 * Fetches GET /api/v1/teams/{teamId}/project/health on mount, on team
 * change, and on window focus / tab visibility change (mirrors
 * ProjectBudgetView pattern). No polling.
 *
 * Read-only — every tile has quick-jump links to the deep-dive tabs.
 */
export default {
    name: 'ProjectHealthWidget',

    components: { WalletOutline, FlagOutline, AlertOctagonOutline },

    data() {
        return {
            payload: null,
            loading: false,
            fetchError: null,
        }
    },

    computed: {
        ...mapState(['currentTeamId']),

        budgetTimeStatus() {
            if (!this.payload) return 'ok'
            const bt = this.payload.budgetTime
            if (bt.projectOverBudget || bt.lanesOverBudget > 0 || bt.membersOverHours > 0) {
                return 'red'
            }
            return 'ok'
        },

        milestonesStatus() {
            if (!this.payload || this.payload.milestones.upcoming.length === 0) return 'ok'
            const worst = this.payload.milestones.upcoming.reduce((acc, m) => {
                if (m.status === 'slipping') return 'red'
                if (m.status === 'at-risk' && acc !== 'red') return 'amber'
                return acc
            }, 'ok')
            return worst
        },

        qualityStatus() {
            if (!this.payload) return 'ok'
            const q = this.payload.quality
            const openCount = (q.decisionsEnabled ? q.openDecisions : 0) + q.unsolvedQuestions
            return openCount > 0 ? 'amber' : 'ok'
        },

        /**
         * v3.99.8 — true when at least one milestone in the list has
         * pending decisions attached. Drives the "Open Decisions"
         * shortcut in the Milestones tile — surfacing it unconditionally
         * would be noise on projects that don't use decision-linked
         * milestones at all.
         */
        hasMilestoneWithPendingDecision() {
            if (!this.payload) return false
            return (this.payload.milestones.upcoming || [])
                .some(m => (m.pendingDecisions || 0) > 0)
        },
    },

    watch: {
        currentTeamId(newId) {
            if (newId) this.fetchHealth()
        },
    },

    mounted() {
        this.fetchHealth()
        this._onVisibility = () => {
            if (document.visibilityState === 'visible') this.fetchHealth()
        }
        this._onFocus = () => this.fetchHealth()
        document.addEventListener('visibilitychange', this._onVisibility)
        window.addEventListener('focus', this._onFocus)
    },

    beforeDestroy() {
        if (this._onVisibility) {
            document.removeEventListener('visibilitychange', this._onVisibility)
            this._onVisibility = null
        }
        if (this._onFocus) {
            window.removeEventListener('focus', this._onFocus)
            this._onFocus = null
        }
    },

    methods: {
        t, n,

        async fetchHealth() {
            if (!this.currentTeamId) return
            this.loading = true
            this.fetchError = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/health`),
                )
                this.payload = data
            } catch (e) {
                this.fetchError = e?.response?.data?.error || e?.message || t('teamhub', 'Unknown error')
            } finally {
                this.loading = false
            }
        },

        milestoneStatusLabel(m) {
            switch (m.status) {
            case 'slipping':
                // TRANSLATORS: milestone status — some owned Deck cards are past their due date and still open
                return t('teamhub', 'Slipping')
            case 'at-risk':
                // TRANSLATORS: milestone status — some owned Deck cards are still open but not yet overdue
                return t('teamhub', 'At risk')
            case 'on-track':
            default:
                // TRANSLATORS: milestone status — all owned Deck cards are done
                return t('teamhub', 'On track')
            }
        },

        formatDate(iso) {
            if (!iso) return ''
            try {
                return new Date(iso + 'T00:00:00Z').toLocaleDateString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric',
                })
            } catch (e) {
                return iso
            }
        },

        ...mapMutations(['SET_DECISIONS_PRESELECT_STATUS']),

        openTab(key) {
            // Emit up so TeamWidgetGrid / TeamView can route to the tab.
            this.$emit('open-tab', key)
        },

        /**
         * v3.99.8 — Justin: "Can we go to the all decisions page and
         * apply the Awaits approval filter?" The Milestones and Quality
         * "Open Decisions" links both route through here so the click
         * lands on the pressing subset (status = 'finalized', chip label
         * "Awaits approval") instead of the Decisions landing page.
         * TeamDecisionsView consumes and clears decisionsPreselectStatus
         * on mount.
         */
        openDecisionsAwaitingApproval() {
            this.SET_DECISIONS_PRESELECT_STATUS('finalized')
            this.openTab('decisions')
        },
    },
}
</script>

<style scoped>
.th-health-widget {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 8px 12px 12px;
    font-size: 13px;
}

.th-health-widget__loading,
.th-health-widget__empty,
.th-health-widget__error {
    padding: 8px 4px;
    color: var(--color-text-maxcontrast);
}

.th-health-widget__error {
    color: var(--color-error-text);
    background: var(--color-error);
    border-radius: var(--border-radius);
    padding: 8px 12px;
}

.th-health-widget__tiles {
    display: flex;
    flex-direction: column;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.th-health-tile {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 10px;
    border-radius: var(--border-radius);
    background: var(--color-background-hover);
    border-left: 3px solid transparent;
}

.th-health-tile--ok    { border-left-color: var(--color-success);     }
.th-health-tile--amber { border-left-color: var(--color-warning);     }
.th-health-tile--red   { border-left-color: var(--color-error);       }

.th-health-tile__row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.th-health-tile__icon {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-health-tile__label {
    font-weight: 600;
}

.th-health-tile__signal {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    color: var(--color-main-text);
}

.th-health-tile__chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: var(--th-font-meta);
    font-weight: 600;
    line-height: 1.4;
}

.th-health-tile__chip--red {
    background: var(--color-error);
    color: var(--color-error-text);
}

.th-health-tile__chip--amber {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.th-health-tile__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.th-health-tile__link {
    background: transparent;
    border: none;
    padding: 2px 4px;
    font-size: var(--th-font-meta);
    font-weight: 500;
    color: var(--color-primary-element);
    cursor: pointer;
    text-decoration: underline;
}

/* v3.100.14: previously the hover+focus rule set outline: none and the
   :focus-visible rule below re-added the ring — same specificity, source
   order made it work but the intent read as "no focus ring". Split so
   :hover clears the outline explicitly and :focus-visible owns the ring. */
.th-health-tile__link:hover {
    color: var(--color-primary-element-hover);
}

.th-health-tile__link:focus-visible {
    color: var(--color-primary-element-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: 3px;
}

.th-health-milestone-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    list-style: none;
    padding: 0;
    margin: 0;
    width: 100%;
}

.th-health-milestone {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    padding: 2px 0;
}

.th-health-milestone__label {
    font-weight: 500;
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.th-health-milestone__meta {
    display: flex;
    gap: 6px;
    align-items: baseline;
    font-size: var(--th-font-meta);
    flex-shrink: 0;
}

.th-health-milestone__date {
    color: var(--color-text-maxcontrast);
}

.th-health-milestone__status {
    padding: 1px 6px;
    border-radius: 10px;
    font-weight: 600;
    font-size: var(--th-font-micro);
}

.th-health-milestone--on-track .th-health-milestone__status {
    background: var(--color-success);
    color: var(--color-success-text);
}

.th-health-milestone--at-risk .th-health-milestone__status {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

/* v3.99.8 — Justin: "make the milestones a hard red instead of soft."
   Slipping is the terminal state where something has already broken
   (past-due card or a decision at the milestone edge), so it reads
   as a solid red badge rather than the soft chip we use for warnings. */
.th-health-milestone--slipping .th-health-milestone__status {
    background: var(--color-error);
    color: var(--color-error-text);
}
</style>
