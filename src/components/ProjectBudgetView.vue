<template>
    <div class="th-budget">
        <!-- v3.101.1: the Budget iframe is now composed from three
             IframeWidgetCard widgets (Overview / Utilisation / Workstream
             lanes) so each block has its own header + action row and the
             view reads as a stack of dashboard-style cards instead of one
             long scrolling section. See gui.md follow-up. -->

        <!-- Widget 1: Budget overview (KPI row).
             v3.102.1: settings button now carries its label ("Budget
             settings") alongside the icon so admins can see at a glance
             where the button leads (Manage Team → Project → Budget). Gated
             on isTeamAdmin — only users with manage-team access see it,
             matching the target section's own permission gate. -->
        <IframeWidgetCard :title="t('teamhub', 'Budget overview')">
            <template #icon><WalletOutline :size="18" /></template>
            <template #actions>
                <!-- v3.103.3: switched to primary variant so this button
                     reads as the same colour rank as the widget-header
                     Add-expense / Log-time buttons (all are primary
                     actions of their widget). Also now deep-links to
                     the Budget config section of Manage Team → Project
                     via SET_MANAGE_TEAM_DEEP_LINK, the same mutation
                     ProjectCompassPanel uses — so clicking it lands you
                     on the scrolled + highlighted Budget block rather
                     than the top of Manage Team. -->
                <NcButton v-if="isTeamAdmin"
                    variant="primary"
                    :title="t('teamhub', 'Open Manage Team — Project — Budget')"
                    @click="openBudgetSettings">
                    <template #icon><CogOutline :size="16" /></template>
                    {{ t('teamhub', 'Budget settings') }}
                </NcButton>
            </template>
            <div class="th-budget__kpis">
                <div class="th-budget__kpi th-budget__kpi--total">
                    <div class="th-budget__kpi-head">
                        <span class="th-budget__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Project budget') }}
                    </div>
                    <div class="th-budget__kpi-value">
                        {{ budget.totalMinor !== null ? formatMoney(budget.totalMinor) : t('teamhub', 'Not set') }}
                    </div>
                    <div class="th-budget__kpi-sub">{{ t('teamhub', 'Total approved') }}</div>
                </div>

                <div class="th-budget__kpi th-budget__kpi--allocated">
                    <div class="th-budget__kpi-head">
                        <span class="th-budget__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Allocated') }}
                    </div>
                    <div class="th-budget__kpi-value">{{ formatMoney(budget.allocatedMinor) }}</div>
                    <div class="th-budget__kpi-sub">
                        <template v-if="budget.totalMinor && budget.totalMinor > 0">
                            {{ t('teamhub', '{pct}% of total', { pct: allocatedPct }) }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'no project total set') }}
                        </template>
                    </div>
                </div>

                <div class="th-budget__kpi th-budget__kpi--spent">
                    <div class="th-budget__kpi-head">
                        <span class="th-budget__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Spent (projected)') }}
                    </div>
                    <div class="th-budget__kpi-value">{{ formatMoney(budget.spentProjectedMinor) }}</div>
                    <div class="th-budget__kpi-sub">
                        {{ t('teamhub', 'Real spent: {v}', { v: formatMoney(budget.spentRealMinor) }) }}
                    </div>
                </div>

                <div class="th-budget__kpi" :class="remainingKpiClass">
                    <div class="th-budget__kpi-head">
                        <span class="th-budget__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Remaining (real)') }}
                    </div>
                    <div class="th-budget__kpi-value">
                        <template v-if="budget.totalMinor !== null">
                            {{ formatMoney(budget.totalMinor - budget.spentRealMinor) }}
                        </template>
                        <template v-else>—</template>
                    </div>
                    <div class="th-budget__kpi-sub">
                        <template v-if="budget.totalMinor && budget.totalMinor > 0">
                            {{ t('teamhub', '{pct}% remaining', { pct: remainingPct }) }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'no project total set') }}
                        </template>
                    </div>
                </div>
            </div>
        </IframeWidgetCard>

        <!-- v3.102.1: widgets 2 + 3 sit in a two-column row on wide viewports
             so Utilisation and Workstream lanes fill the available width side
             by side instead of stacking full-width. On narrow viewports they
             fall back to a single column. If Utilisation is hidden (no chart
             data) the Workstream-lanes widget takes the full row via the
             --single modifier. -->
        <div class="th-budget__widget-row"
            :class="{ 'th-budget__widget-row--single': !hasChartData }">

        <!-- Widget 2: Utilisation charts — donut + per-workstream bars.
             Hidden when there's nothing to plot (no allocations set AND no
             expenses recorded) since the KPI row above already carries the
             zeros — a chart of zeros is just visual noise. -->
        <IframeWidgetCard v-if="hasChartData" :title="t('teamhub', 'Utilisation')">
            <template #icon><ChartArc :size="18" /></template>
            <div class="th-budget__charts">
            <!-- Donut: total spent as % of total allocated. Colour follows
                 the same over/under/equal semantics as the "real" figures. -->
            <div class="th-budget__chart-card th-budget__chart-card--donut" role="img"
                :aria-label="t('teamhub', 'Budget utilisation. {v}% spent.', { v: donut.pctLabel })">
                <div class="th-budget__chart-card-title">{{ t('teamhub', 'Budget utilisation') }}</div>
                <svg :viewBox="`0 0 ${donut.size} ${donut.size}`" class="th-budget__donut" width="180" height="180">
                    <circle
                        :cx="donut.cx" :cy="donut.cy" :r="donut.r"
                        fill="none"
                        :stroke-width="donut.stroke"
                        class="th-budget__donut-track" />
                    <circle
                        v-if="donut.pct > 0"
                        :cx="donut.cx" :cy="donut.cy" :r="donut.r"
                        fill="none"
                        :stroke-width="donut.stroke"
                        :stroke-dasharray="donut.dashArray"
                        :stroke-dashoffset="donut.dashOffset"
                        stroke-linecap="round"
                        :class="['th-budget__donut-arc', donut.stateClass]"
                        :transform="`rotate(-90 ${donut.cx} ${donut.cy})`" />
                    <text :x="donut.cx" :y="donut.cy - 4" text-anchor="middle" class="th-budget__donut-pct"
                        :class="donut.stateClass">{{ donut.pctLabel }}%</text>
                    <text :x="donut.cx" :y="donut.cy + 20" text-anchor="middle" class="th-budget__donut-sub">
                        {{ t('teamhub', 'of allocated') }}
                    </text>
                </svg>
                <div class="th-budget__donut-legend">
                    <div><span class="th-budget__donut-swatch th-budget__donut-swatch--allocated" />{{ t('teamhub', 'Allocated') }}: <strong>{{ formatMoney(budget.allocatedMinor) }}</strong></div>
                    <div><span :class="['th-budget__donut-swatch', donut.stateClass]" />{{ t('teamhub', 'Real spent') }}: <strong>{{ formatMoney(budget.spentRealMinor) }}</strong></div>
                </div>
            </div>

            <!-- Horizontal bar chart per workstream. One row per lane: name
                 (fixed left column), then a track that goes from 0 to the
                 chart's max value. Allocated is drawn as a light track; real
                 spent overlays as a coloured fill (state class). Numbers to
                 the right. -->
            <div class="th-budget__chart-card th-budget__chart-card--bars" role="img"
                :aria-label="t('teamhub', 'Per-workstream budget comparison')">
                <div class="th-budget__chart-card-title">{{ t('teamhub', 'Per lane') }}</div>
                <div class="th-budget__bars">
                    <div v-for="row in barRows" :key="'br-' + row.laneId" class="th-budget__bar-row">
                        <div class="th-budget__bar-name" :title="row.name">
                            <span class="th-budget__bar-lane-swatch"
                                :style="{ background: row.laneColor }"
                                aria-hidden="true" />
                            <span class="th-budget__bar-name-text">{{ row.name }}</span>
                        </div>
                        <div class="th-budget__bar-track">
                            <div class="th-budget__bar-allocated" :style="{ width: row.allocPct + '%' }" />
                            <div :class="['th-budget__bar-real', row.stateClass]" :style="{ width: row.realPct + '%' }" />
                            <!-- v3.103.4: vertical marker at the projected
                                 position so the bar shows real (fill) AND
                                 projected (line) — replaces the retired
                                 per-lane budget bar in the workstream widget. -->
                            <div v-if="row.projected > 0"
                                class="th-budget__bar-marker"
                                :style="{ left: row.projPct + '%' }"
                                :aria-label="t('teamhub', 'Projected: {v}', { v: formatMoney(row.projected) })"
                                :title="t('teamhub', 'Projected: {v}', { v: formatMoney(row.projected) })" />
                        </div>
                        <div class="th-budget__bar-values">
                            <span :class="row.stateClass">{{ formatMoney(row.real) }}</span>
                            <span class="th-budget__bar-values-sub">{{ t('teamhub', 'of {a}', { a: formatMoney(row.allocated) }) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </IframeWidgetCard>

        <!-- Widget 3: Workstream lanes — this is where the loading /
             error / empty / list states render inside a card so the chrome
             stays consistent whatever the state.
             v3.102.1: consolidated the per-lane "Add expense" buttons into
             one widget-header button. The user picks the lane in the modal
             instead — see the required "Lane" select in the form below. -->
        <IframeWidgetCard
            :title="t('teamhub', 'Workstream lanes')"
            :badge="budget.lanes.length || null">
            <template #icon><FormatListBulletedIcon :size="18" /></template>
            <template #actions>
                <NcButton v-if="canAddAnyExpense"
                    variant="primary"
                    @click="openAddExpense(null)">
                    <template #icon><Plus :size="16" /></template>
                    {{ t('teamhub', 'Add expense') }}
                </NcButton>
            </template>

            <div v-if="loading" class="th-budget__status">
                <NcLoadingIcon :size="32" />
            </div>

            <NcEmptyContent v-else-if="error"
                :name="t('teamhub', 'Could not load the budget')"
                :description="error">
                <template #icon><AlertCircleOutline :size="48" /></template>
                <template #action>
                    <NcButton @click="fetchBudget">{{ t('teamhub', 'Retry') }}</NcButton>
                </template>
            </NcEmptyContent>

            <NcEmptyContent v-else-if="!budget.lanes.length"
                :name="t('teamhub', 'No visible workstreams')"
                :description="t('teamhub', 'This project has no Deck stacks yet, or you do not have permission to view any of its budget lanes.')">
                <template #icon><WalletOutline :size="48" /></template>
            </NcEmptyContent>

            <!-- v3.103.3: lane sections rebuilt to mirror the Time-report
                 per-lane layout — coloured swatch + lane title + real-spent
                 total on the header row, then a table with Member /
                 Description-and-date / Projected / Real / Actions columns.
                 The old per-lane stats block (Allocated / Projected / Real /
                 Remaining) and the budget bar were retired — the Utilisation
                 widget's Per-lane bar chart already carries that signal for
                 the whole project, so repeating it per lane inside the
                 workstream widget was duplicative. -->
            <div v-else class="th-budget__lanes">
            <section v-for="(lane, idx) in budget.lanes"
                :key="lane.laneId"
                class="th-budget__lane"
                :style="{ '--th-budget-lane-color': laneColour(lane.stackOrder, lane.deckStackId, idx) }">
                <header class="th-budget__lane-head">
                    <span class="th-budget__lane-swatch" aria-hidden="true" />
                    <h3 class="th-budget__lane-title">{{ lane.stackTitle }}</h3>
                    <span class="th-budget__lane-total"
                        :class="realColorClass(lane.spentRealMinor, lane.spentProjectedMinor)">
                        {{ formatMoney(lane.spentRealMinor) }}
                    </span>
                </header>

                <div v-if="lane.expenses.length === 0" class="th-budget__lane-empty">
                    {{ t('teamhub', 'No expenses in this lane yet.') }}
                </div>
                <table v-else class="th-budget__expenses" role="grid">
                    <thead>
                        <tr>
                            <th scope="col">{{ t('teamhub', 'Member') }}</th>
                            <th scope="col">{{ t('teamhub', 'Description') }}</th>
                            <th scope="col" class="th-budget__col-num">{{ t('teamhub', 'Projected') }}</th>
                            <th scope="col" class="th-budget__col-num">{{ t('teamhub', 'Real') }}</th>
                            <th v-if="lane.canEdit" scope="col" class="th-budget__col-actions">
                                <span class="hidden-visually">{{ t('teamhub', 'Actions') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="expense in lane.expenses" :key="expense.id">
                            <td :title="expense.createdBy">{{ expense.createdByName || expense.createdBy }}</td>
                            <td>
                                <div class="th-budget__expense-title">{{ expense.description }}</div>
                                <div class="th-budget__expense-meta">{{ formatDate(expense.incurredAt) }}</div>
                            </td>
                            <td class="th-budget__col-num">{{ formatMoney(expense.projectedMinor) }}</td>
                            <td class="th-budget__col-num" :class="realColorClass(expense.realMinor, expense.projectedMinor)">
                                {{ expense.realMinor !== null ? formatMoney(expense.realMinor) : t('teamhub', '—') }}
                            </td>
                            <td v-if="lane.canEdit" class="th-budget__col-actions">
                                <NcActions>
                                    <NcActionButton @click="openEditExpense(lane, expense)">
                                        <template #icon><Pencil :size="20" /></template>
                                        {{ t('teamhub', 'Edit') }}
                                    </NcActionButton>
                                    <NcActionButton @click="confirmDeleteExpense(lane, expense)">
                                        <template #icon><Delete :size="20" /></template>
                                        {{ t('teamhub', 'Delete') }}
                                    </NcActionButton>
                                </NcActions>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
            </div>
        </IframeWidgetCard>

        </div>
        <!-- /.th-budget__widget-row -->

        <!-- Add / edit expense modal -->
        <NcDialog v-if="expenseModalOpen"
            :name="editingExpense ? t('teamhub', 'Edit expense') : t('teamhub', 'Add expense')"
            :open="expenseModalOpen"
            @update:open="closeExpenseModal">
            <template #default>
                <!-- v3.103.2: modal form rebuilt for a clean, consistent
                     appearance. Dropped NcTextField in favour of one
                     styled `.th-budget__input` class across every field
                     (select, text, number, date) so each row reads as a
                     sibling — the previous mix of NcTextField chrome and
                     bare <input>s looked half-styled. Labels sit above
                     fields with a stable single-line height. -->
                <form class="th-budget__form" @submit.prevent="submitExpense">
                    <div class="th-budget__form-field">
                        <label for="th-budget-lane" class="th-budget__form-label">{{ t('teamhub', 'Lane') }}</label>
                        <select
                            id="th-budget-lane"
                            v-model="form.laneId"
                            :disabled="!!editingExpense"
                            required
                            class="th-budget__input">
                            <option v-if="!form.laneId" :value="null" disabled>
                                {{ t('teamhub', 'Choose a lane…') }}
                            </option>
                            <option v-for="lane in editableLanes"
                                :key="'sel-' + lane.laneId"
                                :value="lane.laneId">
                                {{ lane.stackTitle }}
                            </option>
                        </select>
                    </div>

                    <div class="th-budget__form-field">
                        <label for="th-budget-desc" class="th-budget__form-label">{{ t('teamhub', 'Description') }}</label>
                        <input
                            id="th-budget-desc"
                            v-model="form.description"
                            type="text"
                            required
                            autofocus
                            :placeholder="t('teamhub', 'What is this for?')"
                            class="th-budget__input" />
                    </div>

                    <div class="th-budget__form-row">
                        <div class="th-budget__form-field">
                            <label for="th-budget-projected" class="th-budget__form-label">
                                {{ t('teamhub', 'Projected amount ({currency})', { currency: budget.currency || t('teamhub', 'currency not set') }) }}
                            </label>
                            <input
                                id="th-budget-projected"
                                v-model.number="form.projected"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                                class="th-budget__input" />
                        </div>
                        <div class="th-budget__form-field">
                            <label for="th-budget-real" class="th-budget__form-label">{{ t('teamhub', 'Real amount') }}</label>
                            <input
                                id="th-budget-real"
                                v-model.number="form.real"
                                type="number"
                                step="0.01"
                                min="0"
                                :placeholder="t('teamhub', 'leave empty until incurred')"
                                class="th-budget__input" />
                        </div>
                    </div>

                    <div class="th-budget__form-field">
                        <label for="th-budget-date" class="th-budget__form-label">{{ t('teamhub', 'Date (optional)') }}</label>
                        <input
                            id="th-budget-date"
                            v-model="form.incurredAt"
                            type="date"
                            class="th-budget__input" />
                    </div>

                    <p v-if="formError" class="th-budget__form-error" role="alert">{{ formError }}</p>
                </form>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="closeExpenseModal">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    type="primary"
                    :disabled="submitting || !form.description.trim() || !form.laneId"
                    @click="submitExpense">
                    <template #icon><NcLoadingIcon v-if="submitting" :size="20" /></template>
                    {{ editingExpense ? t('teamhub', 'Save') : t('teamhub', 'Add') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Delete-confirm dialog -->
        <NcDialog v-if="deleteTarget"
            :name="t('teamhub', 'Delete expense')"
            :open="!!deleteTarget"
            @update:open="deleteTarget = null">
            <template #default>
                <p style="margin: 0;">
                    {{ t('teamhub', 'Delete “{description}”? This cannot be undone.', { description: deleteTarget.expense.description }) }}
                </p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="deleteTarget = null">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton type="error" :disabled="submitting" @click="submitDeleteExpense">
                    {{ t('teamhub', 'Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapState, mapGetters, mapMutations } from 'vuex'

import {
    NcActions, NcActionButton, NcButton, NcDialog,
    NcEmptyContent, NcLoadingIcon,
} from '@nextcloud/vue'

import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChartArc from 'vue-material-design-icons/ChartArc.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import WalletOutline from 'vue-material-design-icons/WalletOutline.vue'

// v3.101.1: shared widget-card chrome used by every full-tab iframe view.
import IframeWidgetCard from './IframeWidgetCard.vue'

// Shared with ProjectSwimlaneView.vue — same palette so a lane's colour is
// consistent between the Timeline swimlane and the Budget page. Kept in sync
// by convention (not a shared module) since it's an 8-entry list.
const LANE_PALETTE = [
    '#1e88e5', '#00897b', '#8e24aa', '#f4511e',
    '#6d4c41', '#3949ab', '#00acc1', '#7cb342',
]

function laneColour(stackOrder, stackId, idx) {
    const key = (typeof stackOrder === 'number' && stackOrder >= 0)
        ? stackOrder
        : (typeof stackId === 'number' ? stackId : idx)
    return LANE_PALETTE[Math.abs(key) % LANE_PALETTE.length]
}

export default {
    name: 'ProjectBudgetView',
    components: {
        NcActions, NcActionButton, NcButton, NcDialog, NcEmptyContent,
        NcLoadingIcon,
        AlertCircleOutline, ChartArc, CogOutline, Delete,
        FormatListBulletedIcon, Pencil, Plus, WalletOutline,
        IframeWidgetCard,
    },
    emits: ['open-project-settings'],
    data() {
        return {
            loading: true,
            error: null,
            budget: {
                isProject: false,
                currency: null,
                totalMinor: null,
                allocatedMinor: 0,
                spentProjectedMinor: 0,
                spentRealMinor: 0,
                lanes: [],
            },
            expenseModalOpen: false,
            editingExpense: null, // { laneId, expense }
            form: {
                laneId: null,
                description: '',
                projected: null,
                real: null,
                incurredAt: '',
            },
            formError: '',
            deleteTarget: null, // { laneId, expense }
            submitting: false,
        }
    },
    computed: {
        /* v3.103.6: currentView is watched below so we can reset scrollTop
           each time the user navigates INTO the Budget tab. Some mobile
           browsers remember the container's scrollTop across v-show
           display: none / block toggles, which was why Justin landed
           mid-container instead of at the Overview widget. */
        ...mapState(['currentTeamId', 'currentView']),
        /* v3.103.2: the Vuex store getter is `currentUserIsTeamAdmin` —
           the previous `mapGetters(['isTeamAdmin'])` silently produced
           `undefined` so every `v-if="isTeamAdmin"` was falsy and the
           "Budget settings" button never rendered. Alias here so local
           references stay readable. */
        ...mapGetters({ isTeamAdmin: 'currentUserIsTeamAdmin' }),

        /** v3.102.1: lanes the current user can add expenses to. Used to
         *  gate the widget-header "Add expense" button (if there are none,
         *  the button hides) AND to populate the modal's lane picker. */
        editableLanes() {
            return (this.budget.lanes || []).filter(l => l.canEdit)
        },

        /** v3.102.1: whether the widget-header "Add expense" button renders.
         *  Hidden when no lane is editable — matches the pre-v3.102.1 UX
         *  where the per-lane button only showed on editable lanes. */
        canAddAnyExpense() {
            return this.editableLanes.length > 0
        },

        /** Allocated as a % of the project total; 0 when total is unset. */
        allocatedPct() {
            const total = this.budget.totalMinor || 0
            if (total <= 0) return 0
            return Math.round((this.budget.allocatedMinor / total) * 100)
        },

        /** Remaining (real) as a % of the project total; 0 when total is unset. */
        remainingPct() {
            const total = this.budget.totalMinor || 0
            if (total <= 0) return 0
            const remaining = total - (this.budget.spentRealMinor || 0)
            return Math.round((remaining / total) * 100)
        },

        /** Colour class for the Remaining KPI. Goes red when over budget. */
        remainingKpiClass() {
            if (this.budget.totalMinor !== null && this.budget.totalMinor - (this.budget.spentRealMinor || 0) < 0) {
                return 'th-budget__kpi--remaining-over'
            }
            return 'th-budget__kpi--remaining'
        },

        /** True when there's any data worth charting. */
        hasChartData() {
            const lanes = this.budget.lanes || []
            if (!lanes.length) return false
            return lanes.some(l => (l.allocatedMinor || 0) > 0 || (l.spentRealMinor || 0) > 0)
        },

        /**
         * Utilisation donut — SVG circle-arc using `stroke-dasharray` /
         * `stroke-dashoffset` for the progress "fill". State class is based
         * on the same over/under semantics as the numeric colour rule, but
         * with a threshold-based rule at the project level:
         *   real > allocated  → over   (red)
         *   real < allocated  → under  (green) — this is the "healthy" case
         *   real == allocated → equal  (neutral)
         *   allocated == 0    → neutral (nothing meaningful to plot)
         * Percentage clips at 100 for the arc but the label shows the real
         * number so you can see "125%" when over budget.
         */
        donut() {
            const size = 180
            const stroke = 18
            const r = (size - stroke) / 2 - 2
            const cx = size / 2
            const cy = size / 2
            const circumference = 2 * Math.PI * r
            const allocated = this.budget.allocatedMinor || 0
            const real      = this.budget.spentRealMinor || 0
            let pct = 0
            let stateClass = 'th-budget__equal'
            if (allocated > 0) {
                pct = (real / allocated) * 100
                if (real > allocated) stateClass = 'th-budget__over'
                else if (real < allocated) stateClass = 'th-budget__under'
                else stateClass = 'th-budget__equal'
            }
            const drawPct = Math.min(100, Math.max(0, pct))
            const arcLen = (drawPct / 100) * circumference
            return {
                size, cx, cy, r, stroke,
                pct: pct,
                pctLabel: Math.round(pct).toString(),
                stateClass,
                dashArray: `${arcLen} ${circumference}`,
                dashOffset: 0,
            }
        },

        /**
         * Per-lane horizontal bar rows. Bars scale against the global project
         * max (allocated OR real, whichever is larger) so lanes are visually
         * comparable — a small lane's bar next to a big lane's bar reads as
         * "smaller" at a glance, not just "same-sized-with-a-tiny-fill."
         *
         * COLOUR RULE: the bar's colour follows the same real-vs-projected
         * comparison as the per-lane card below, NOT real-vs-allocated. This
         * is what Justin flagged in v3.94.1 — the mini bar was showing green
         * ("under allocation") for a lane that was over-projected (which the
         * card correctly showed as red). Same source of truth for the colour
         * now — a lane that's over its projected spend shows red in both
         * places. The bar WIDTH still tracks utilisation against the global
         * max; only the colour rule changed. `realColorClass()` returns ''
         * when there's nothing to compare against (0 projected).
         */
        /* v3.103.4: adds projected value + position % for the vertical
         * marker on each per-lane bar. Projected is folded into the
         * globalMax so a projected marker for a lane whose projection
         * exceeds every allocation still lands on-track. */
        barRows() {
            const lanes = this.budget.lanes || []
            const globalMax = lanes.reduce((m, l) => Math.max(
                m,
                l.allocatedMinor || 0,
                l.spentRealMinor || 0,
                l.spentProjectedMinor || 0,
            ), 1)
            return lanes.map((l, idx) => {
                const allocated = l.allocatedMinor || 0
                const real      = l.spentRealMinor || 0
                const projected = l.spentProjectedMinor || 0
                return {
                    laneId:    l.laneId,
                    name:      l.stackTitle,
                    laneColor: laneColour(l.stackOrder, l.deckStackId, idx),
                    allocated,
                    real,
                    projected,
                    allocPct:  Math.min(100, Math.round((allocated / globalMax) * 100)),
                    realPct:   Math.min(100, Math.round((real      / globalMax) * 100)),
                    projPct:   Math.min(100, Math.round((projected / globalMax) * 100)),
                    stateClass: this.realColorClass(real, projected),
                }
            })
        },
    },
    watch: {
        currentTeamId(newId, oldId) {
            if (newId && newId !== oldId) {
                this.fetchBudget()
            }
        },
        /* v3.103.6: whenever the user navigates INTO the Budget tab
           (currentView flips to 'budget'), reset the container's scroll
           to the top. This is the fix for Justin's mobile issue where
           Overview started off-screen because scrollTop was retained
           from a previous view of this component. */
        currentView(newView) {
            if (newView === 'budget') {
                this.$nextTick(() => this.resetScrollTop())
            }
        },
    },
    mounted() {
        this.fetchBudget()
        this.onVisibilityChange = this.onVisibilityChange.bind(this)
        document.addEventListener('visibilitychange', this.onVisibilityChange)
        window.addEventListener('focus', this.onVisibilityChange)
        // v3.103.6: force the container to the top after the first paint —
        // covers the preload-then-navigate path where the view mounts
        // before it's visible and some mobile browsers assign a non-zero
        // scrollTop when the display: none → block flip happens.
        this.$nextTick(() => this.resetScrollTop())
    },
    beforeDestroy() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange)
        window.removeEventListener('focus', this.onVisibilityChange)
    },
    methods: {
        t, n, laneColour,
        ...mapMutations(['SET_MANAGE_TEAM_DEEP_LINK']),

        /**
         * v3.103.3: deep-link to Manage Team → Project → Budget config.
         * Same pattern ProjectCompassPanel uses for its checklist links —
         * SET_MANAGE_TEAM_DEEP_LINK stashes { tab, section }, and
         * ManageTeamView's watcher scrolls + highlights the [data-section]
         * anchor once the view mounts. The `open-project-settings` emit
         * that follows is what parents already listen for to actually
         * switch to Manage Team.
         */
        openBudgetSettings() {
            this.SET_MANAGE_TEAM_DEEP_LINK({ tab: 'project', section: 'budget' })
            this.$emit('open-project-settings')
        },

        /**
         * v3.103.6: pin the iframe scroll container to its top. Fires from
         * `mounted()` (covers the initial preload path) and from a watcher
         * on `currentView` (covers every subsequent navigation back to
         * this tab). Guarded because $el is Node during SSR/unmount and
         * has no `scrollTop`.
         */
        resetScrollTop() {
            if (this.$el && typeof this.$el.scrollTop === 'number') {
                this.$el.scrollTop = 0
            }
        },

        /**
         * State class for real-vs-projected comparison. Returns:
         *   ''                        — no projection (nothing to compare)
         *   'th-budget__over'         — real > projected  (red)
         *   'th-budget__under'        — real < projected  (green)
         *   'th-budget__equal'        — real === projected (default text)
         * CSS specificity handles text-vs-fill via the element context —
         * text elements use `color`, SVG rects use `fill`, HTML bars use
         * `background`. See the style block below.
         */
        realColorClass(real, projected) {
            if (real === null || real === undefined) return ''
            if (!projected) return '' // 0 or null projected — no comparison
            if (real > projected) return 'th-budget__over'
            if (real < projected) return 'th-budget__under'
            return 'th-budget__equal'
        },

        /**
         * Per-lane budget-bar width as a percentage of the lane's own scale.
         * Scale = max(allocated, projected, real, 1) so a bar always renders
         * something even when values are zero, and an over-allocation stays
         * visible (up to 100%). Values above allocated clip to 100% by design
         * — the numeric stats above the bar carry the exact figure.
         */
        laneBarWidthPct(value, lane) {
            if (!value || value <= 0) return 0
            const scale = Math.max(
                lane.allocatedMinor || 0,
                lane.spentProjectedMinor || 0,
                lane.spentRealMinor || 0,
                1,
            )
            return Math.min(100, Math.round((value / scale) * 100))
        },

        laneBarAriaLabel(lane) {
            const alloc = lane.allocatedMinor !== null ? this.formatMoney(lane.allocatedMinor) : t('teamhub', 'not set')
            return t('teamhub', 'Budget bar. Allocated {allocated}. Real spent {real}. Projected {projected}.', {
                allocated: alloc,
                real: this.formatMoney(lane.spentRealMinor),
                projected: this.formatMoney(lane.spentProjectedMinor),
            })
        },

        onVisibilityChange() {
            if (!document.hidden && this.currentTeamId) {
                this.fetchBudget()
            }
        },

        async fetchBudget() {
            if (!this.currentTeamId) return
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/budget`),
                )
                this.budget = data
            } catch (err) {
                this.error = err?.response?.data?.error || err.message
            } finally {
                this.loading = false
            }
        },

        // ── Money & date formatting ──────────────────────────────────

        formatMoney(minor) {
            if (minor === null || minor === undefined) return ''
            const value = minor / 100
            const currency = this.budget.currency
            try {
                if (currency) {
                    return new Intl.NumberFormat(undefined, {
                        style: 'currency',
                        currency,
                        currencyDisplay: 'narrowSymbol',
                    }).format(value)
                }
                return new Intl.NumberFormat(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(value)
            } catch (_) {
                // Bad ISO code (e.g. a permissive backend-accepted code Intl doesn't know)
                // — fall back to plain number.
                return value.toFixed(2)
            }
        },

        formatDate(unixTs) {
            if (!unixTs) return t('teamhub', '—')
            try {
                return new Intl.DateTimeFormat(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric',
                }).format(new Date(unixTs * 1000))
            } catch (_) {
                return ''
            }
        },

        // ── Expense modal ────────────────────────────────────────────

        /* v3.102.1: accepts null (widget-header button — no preselection,
         * user picks in the modal) OR a lane object (legacy per-lane
         * button path — kept for future callers though no template site
         * uses it anymore). When only one lane is editable, preselect it
         * so the picker requires zero clicks. */
        openAddExpense(lane) {
            this.editingExpense = null
            let laneId = null
            if (lane && lane.laneId) {
                laneId = lane.laneId
            } else if (this.editableLanes.length === 1) {
                laneId = this.editableLanes[0].laneId
            }
            this.form = {
                laneId,
                description: '',
                projected: null,
                real: null,
                incurredAt: '',
            }
            this.formError = ''
            this.expenseModalOpen = true
        },

        openEditExpense(lane, expense) {
            this.editingExpense = { laneId: lane.laneId, expenseId: expense.id }
            this.form = {
                laneId: lane.laneId,
                description: expense.description,
                projected: expense.projectedMinor / 100,
                real: expense.realMinor !== null ? expense.realMinor / 100 : null,
                incurredAt: expense.incurredAt
                    ? new Date(expense.incurredAt * 1000).toISOString().slice(0, 10)
                    : '',
            }
            this.formError = ''
            this.expenseModalOpen = true
        },

        closeExpenseModal() {
            this.expenseModalOpen = false
            this.editingExpense = null
            this.formError = ''
        },

        parseMinor(value) {
            if (value === null || value === '' || value === undefined) return null
            const n = Number(value)
            if (!Number.isFinite(n) || n < 0) return null
            return Math.round(n * 100)
        },

        async submitExpense() {
            const description = this.form.description.trim()
            if (!description) {
                this.formError = t('teamhub', 'Description is required')
                return
            }
            const projected = this.parseMinor(this.form.projected)
            if (projected === null) {
                this.formError = t('teamhub', 'Projected amount must be a non-negative number')
                return
            }
            const real = this.form.real === null || this.form.real === '' || this.form.real === undefined
                ? null
                : this.parseMinor(this.form.real)
            if (this.form.real !== null && this.form.real !== '' && real === null) {
                this.formError = t('teamhub', 'Real amount must be a non-negative number')
                return
            }
            let incurredAt = null
            if (this.form.incurredAt) {
                // <input type="date"> yields YYYY-MM-DD in UTC-midnight terms
                const parsed = Date.parse(this.form.incurredAt + 'T00:00:00Z')
                if (!isNaN(parsed)) incurredAt = Math.floor(parsed / 1000)
            }

            this.submitting = true
            try {
                const body = {
                    description,
                    projected_minor: projected,
                    real_minor: real,
                    incurred_at: incurredAt,
                }
                if (this.editingExpense) {
                    const { data } = await axios.put(
                        generateUrl(
                            `/apps/teamhub/api/v1/teams/${this.currentTeamId}`
                            + `/budget/lanes/${this.editingExpense.laneId}`
                            + `/expenses/${this.editingExpense.expenseId}`,
                        ),
                        body,
                    )
                    this.budget = data
                    showSuccess(t('teamhub', 'Expense saved'))
                } else {
                    const { data } = await axios.post(
                        generateUrl(
                            `/apps/teamhub/api/v1/teams/${this.currentTeamId}`
                            + `/budget/lanes/${this.form.laneId}/expenses`,
                        ),
                        body,
                    )
                    this.budget = data
                    showSuccess(t('teamhub', 'Expense added'))
                }
                this.closeExpenseModal()
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                this.formError = msg
                showError(t('teamhub', 'Could not save: {error}', { error: msg }))
            } finally {
                this.submitting = false
            }
        },

        confirmDeleteExpense(lane, expense) {
            this.deleteTarget = { laneId: lane.laneId, expense }
        },

        async submitDeleteExpense() {
            if (!this.deleteTarget) return
            const { laneId, expense } = this.deleteTarget
            this.submitting = true
            try {
                const { data } = await axios.delete(
                    generateUrl(
                        `/apps/teamhub/api/v1/teams/${this.currentTeamId}`
                        + `/budget/lanes/${laneId}/expenses/${expense.id}`,
                    ),
                )
                this.budget = data
                this.deleteTarget = null
                showSuccess(t('teamhub', 'Expense deleted'))
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                showError(t('teamhub', 'Could not delete: {error}', { error: msg }))
            } finally {
                this.submitting = false
            }
        },
    },
}
</script>

<style scoped>
/* v3.104.1: retired `-webkit-overflow-scrolling: touch` (deprecated —
   modern iOS Safari does momentum scroll natively, and on some phones
   that property creates a scroll container whose gesture routing
   disagrees with the browser's default, meaning the container the user
   THINKS they're scrolling isn't the one that actually receives the
   gesture — that's what was making Overview unreachable). Also removed
   `overscroll-behavior: contain` which can freeze scroll on Android
   Chrome when combined with `overflow: auto` inside a flex ancestor.
   Mobile now uses `position: absolute; inset: 0` to lift the container
   out of the flex chain entirely — .teamhub-content is `position:
   relative`, so `inset: 0` gives .th-budget a definite bounding box
   without relying on the height-100% cascade that mobile browsers
   sometimes get wrong. */
.th-budget {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 16px;
    box-sizing: border-box;
    overflow-y: auto;
    overflow-x: hidden;
    background: var(--color-background-dark);
}
/* v3.104.2: flex column children default to flex-shrink: 1, which caused
   the Overview widget to be shrunk down to just its header when total
   content exceeded the container height (esp. mobile). Pinning shrink:0
   makes children hold their intrinsic size so total content genuinely
   overflows and overflow-y: auto engages. Widget spacing is owned by
   .th-iframe-widget's margin-bottom — do NOT also set `gap` here or
   spacing will double. */
.th-budget > * {
    flex-shrink: 0;
}
@media (max-width: 720px) {
    .th-budget {
        position: absolute;
        inset: 0;
        height: auto;
        padding: 12px;
    }
}

/* KPI cards (v3.94.1) — 4-across colored cards at the top.
   Each card gets: coloured left-edge accent bar, coloured swatch in the
   heading, subtle theme-adaptive background tint, and prominent value.
   Colour tokens are all NC theme vars so the palette follows the user's
   accent choice and works in both light and dark themes. */
/* v3.101.1: .th-budget__kpis-wrap and .th-budget__settings-btn are
   retired — the KPIs now sit directly in an IframeWidgetCard body,
   and the settings button moved to the widget's #actions slot. */
.th-budget__kpis {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    flex: 1;
    min-width: 0;
}
@media (min-width: 1100px) {
    .th-budget__kpis {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
.th-budget__kpi {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-left: 4px solid var(--th-kpi-color);
    border-radius: var(--border-radius-large, 8px);
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.th-budget__kpi-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-weight: 600;
}
.th-budget__kpi-swatch {
    display: inline-block;
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--th-kpi-color);
    flex-shrink: 0;
}
/* v3.103.5: default value size (desktop) unchanged. Mobile drops to
   20 px and wraps at soft breakpoints so a "€ 10.000,00" number stays
   inside its 2-column KPI tile on a phone-width viewport. `min-width: 0`
   on the KPI card allows the value box to actually shrink to the grid
   track — without it the value's intrinsic width overrides the track. */
.th-budget__kpi-value {
    font-size: 26px;
    font-weight: 700;
    color: var(--color-main-text);
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
    min-width: 0;
    overflow-wrap: anywhere;
}
@media (max-width: 720px) {
    .th-budget__kpi-value {
        font-size: 20px;
    }
}
.th-budget__kpi-sub {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

/* Per-card colour accents. Each KPI sets --th-kpi-color; the border-left
   and the swatch pull from that. Semantically:
     total     — primary (blue in default theme)
     allocated — primary-element (a slightly stronger accent)
     spent     — warning (amber)
     remaining — success (green), OR error (red) when over-budget         */
.th-budget__kpi--total     { --th-kpi-color: var(--color-primary); }
.th-budget__kpi--allocated { --th-kpi-color: var(--color-primary-element); }
.th-budget__kpi--spent     { --th-kpi-color: var(--color-warning); }
.th-budget__kpi--remaining        { --th-kpi-color: var(--color-success); }
.th-budget__kpi--remaining-over   { --th-kpi-color: var(--color-error); }
.th-budget__kpi--remaining-over .th-budget__kpi-value {
    color: var(--color-error-text);
}

/* Real-vs-projected state colours.
   `.th-budget__over` / `--under` / `--equal` are applied to whatever element
   needs to reflect the comparison; the CSS below fans out to the right
   property depending on element type:
     - regular text elements     → color
     - .th-budget__lane-bar-real → background
     - SVG rect (chart bars)     → fill  */
.th-budget__over          { color: var(--color-error-text); }
.th-budget__under         { color: var(--color-success-text); }
.th-budget__equal         { color: var(--color-main-text); }

.th-budget__lane-bar-real.th-budget__over  { background: var(--color-error); }
.th-budget__lane-bar-real.th-budget__under { background: var(--color-success); }
.th-budget__lane-bar-real.th-budget__equal { background: var(--color-main-text); }

/* Chart cards (v3.94.0) — donut + horizontal bar chart. Layout: side-by-side
   on desktop, stacked on mobile. Cards sit on a subtle background panel so
   they read as distinct dashboard elements. */
/* v3.102.1: two-column row that holds Utilisation (widget 2) and
   Workstream lanes (widget 3). Collapses to a single column on narrow
   viewports. --single modifier fires when Utilisation is hidden so the
   remaining widget spans the full row. */
.th-budget__widget-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    align-items: start;
}
@media (min-width: 1024px) {
    .th-budget__widget-row {
        grid-template-columns: 1fr 1fr;
    }
    .th-budget__widget-row--single {
        grid-template-columns: 1fr;
    }
}

/* v3.101.1: dropped margin-bottom — this used to be a top-level
   section; it now lives inside an IframeWidgetCard body which owns
   the bottom spacing via the card's margin-bottom. */
.th-budget__charts {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}
/* v3.102.1: dropped the 900px side-by-side layout for donut + bars
   inside the Utilisation widget — the widget itself now sits at half
   width in the widget-row on wide viewports, so a nested side-by-side
   grid would squeeze both charts to unreadable widths. Charts stack
   vertically inside the widget instead. */
.th-budget__chart-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large, 8px);
    padding: 16px;
}
.th-budget__chart-card-title {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 12px;
    font-weight: 600;
}
.th-budget__chart-card--donut {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.th-budget__donut {
    display: block;
    margin: 0 auto;
}
.th-budget__donut-track {
    stroke: var(--color-background-dark);
}
.th-budget__donut-arc {
    /* Default (equal); state classes fan out below */
    stroke: var(--color-primary-element);
    transition: stroke-dasharray 250ms ease-out;
}
.th-budget__donut-arc.th-budget__over   { stroke: var(--color-error); }
.th-budget__donut-arc.th-budget__under  { stroke: var(--color-success); }
.th-budget__donut-arc.th-budget__equal  { stroke: var(--color-primary-element); }
.th-budget__donut-pct {
    font-size: 34px;
    font-weight: 700;
    fill: var(--color-main-text);
}
.th-budget__donut-pct.th-budget__over   { fill: var(--color-error-text); }
.th-budget__donut-pct.th-budget__under  { fill: var(--color-success-text); }
.th-budget__donut-pct.th-budget__equal  { fill: var(--color-main-text); }
.th-budget__donut-sub {
    font-size: var(--th-font-micro);
    fill: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.th-budget__donut-legend {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 12px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    align-self: stretch;
}
.th-budget__donut-legend strong {
    color: var(--color-main-text);
    font-weight: 600;
}
.th-budget__donut-swatch {
    display: inline-block;
    width: 10px; height: 10px;
    border-radius: 2px;
    margin-right: 6px;
    vertical-align: middle;
    background: var(--color-primary-element);
}
.th-budget__donut-swatch--allocated { background: var(--color-background-dark); }
.th-budget__donut-swatch.th-budget__over   { background: var(--color-error); }
.th-budget__donut-swatch.th-budget__under  { background: var(--color-success); }
.th-budget__donut-swatch.th-budget__equal  { background: var(--color-primary-element); }

/* Horizontal bar chart per workstream */
/* v3.104.3: retuned to match the Time-widget "Logged per lane" tight
   style — 14 px pill bars, 6 px row-gap, 0.9em font, coloured lane
   swatch inline before the name. The projected vertical marker
   (.th-budget__bar-marker) stays; that's the one signal Time doesn't
   have and Justin explicitly wanted preserved. */
.th-budget__bars {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.th-budget__bar-row {
    display: grid;
    grid-template-columns: 160px 1fr 120px;
    gap: 12px;
    align-items: center;
    font-size: 0.9em;
}
.th-budget__bar-name {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-main-text);
    overflow: hidden;
}
.th-budget__bar-lane-swatch {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex: 0 0 auto;
}
.th-budget__bar-name-text {
    overflow: hidden;
    text-overflow: ellipsis; /* CSS visual overflow marker — not a text ellipsis in a translated string */
    white-space: nowrap;
    min-width: 0;
}
.th-budget__bar-track {
    position: relative;
    height: 14px;
    background: var(--color-background-dark);
    border-radius: 7px;
    overflow: hidden;
}
.th-budget__bar-allocated {
    position: absolute;
    left: 0; top: 0; bottom: 0;
    background: var(--color-background-darker);
    border-radius: 7px;
}
.th-budget__bar-real {
    position: absolute;
    left: 0; top: 0; bottom: 0;
    background: var(--color-primary-element);
    border-radius: 7px;
    transition: width 250ms ease-out;
}
.th-budget__bar-real.th-budget__over   { background: var(--color-error); }
.th-budget__bar-real.th-budget__under  { background: var(--color-success); }
.th-budget__bar-real.th-budget__equal  { background: var(--color-primary-element); }

/* v3.103.4: projected-position marker on the per-lane bar. Vertical
   line inside the track, sits above both allocated and real fills.
   Semantic --color-main-text so the marker stays visible over any
   fill colour in both light and dark themes. */
.th-budget__bar-marker {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--color-main-text);
    transform: translateX(-1px);
    pointer-events: none;
    z-index: 1;
}
.th-budget__bar-values {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    font-size: var(--th-font-meta);
    font-variant-numeric: tabular-nums;
}
.th-budget__bar-values > span:first-child {
    font-size: var(--th-font-body);
    font-weight: 600;
    color: var(--color-main-text);
}
.th-budget__bar-values-sub {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
}

.th-budget__status {
    display: flex;
    justify-content: center;
    padding: 32px 0;
}

/* Responsive grid: 1 column mobile, 2 columns at ≥ 900px. */
/* v3.102.1: single-column stack — the Workstream-lanes widget now sits
   inside a half-width column of the widget-row grid on wide viewports,
   so a nested 2-column lane grid would produce lane cards too narrow to
   read the expense tables inside. */
.th-budget__lanes {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

/* v3.103.4: lane section shed the card chrome (background + border +
   left-accent border + rounded corners + padding) to match the Time
   report's flat per-lane rhythm — head row + table, nothing else. The
   coloured swatch inside the head still identifies the lane. */
.th-budget__lane {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

/* v3.103.4: head + swatch match .th-time__lane-head / __lane-swatch —
   compact row, square 12 px swatch (2 px radius), 1em title, tabular
   total on the right. No divider under the head; the row-gap on the
   parent .th-budget__lane provides the breathing room to the table. */
.th-budget__lane-head {
    display: flex;
    align-items: center;
    gap: 8px;
}
.th-budget__lane-swatch {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 2px;
    background: var(--th-budget-lane-color, var(--color-primary));
    flex: 0 0 auto;
}
.th-budget__lane-title {
    flex: 1 1 auto;
    margin: 0;
    font-size: 1em;
    font-weight: var(--th-font-weight-semibold);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.th-budget__lane-total {
    font-variant-numeric: tabular-nums;
    color: var(--color-text-maxcontrast);
    font-weight: var(--th-font-weight-semibold);
    flex-shrink: 0;
}

.th-budget__expenses {
    width: 100%;
    border-collapse: collapse;
}
.th-budget__expenses th,
.th-budget__expenses td {
    text-align: left;
    padding: 8px;
    border-bottom: 1px solid var(--color-border);
    font-size: var(--th-font-meta);
    vertical-align: top;
}
.th-budget__expenses th {
    color: var(--color-text-maxcontrast);
    font-weight: var(--th-font-weight-medium);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: var(--th-font-micro);
}
.th-budget__expenses tr:last-child td {
    border-bottom: none;
}

/* v3.103.4: matches .th-time__log-title / .th-time__log-meta pattern
   in ProjectTimeView so both tables render their "row title + date"
   combined cell with the same weight + colour hierarchy. */
.th-budget__expense-title {
    color: var(--color-main-text);
    font-weight: var(--th-font-weight-medium);
    line-height: 1.35;
}
.th-budget__expense-meta {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
    margin-top: 2px;
}

.th-budget__col-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.th-budget__col-actions {
    width: 48px;
    text-align: right;
}

/* v3.103.4: matches .th-time__report-empty--small — italic +
   --th-font-micro on the maxcontrast text token, aligned left inside
   the lane so the "nothing here" reads as inline commentary rather
   than a full-block callout. */
.th-budget__lane-empty {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-micro);
    font-style: italic;
    padding: 10px 14px;
}

/* v3.103.2: modal form styling rebuilt for a clean, consistent look.
   Every field (select, text, number, date) shares the .th-budget__input
   class so they all read as siblings. Labels sit above fields with a
   stable single-line height. */
.th-budget__form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 340px;
}

.th-budget__form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    flex: 1;
}

.th-budget__form-label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    font-weight: var(--th-font-weight-medium);
    line-height: 1.3;
}

.th-budget__form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 480px) {
    .th-budget__form-row {
        grid-template-columns: 1fr;
    }
}

.th-budget__input {
    width: 100%;
    height: 36px;
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font: inherit;
    box-sizing: border-box;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.th-budget__input:hover:not(:disabled) {
    border-color: var(--color-border-dark);
}
.th-budget__input:focus,
.th-budget__input:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element);
}
.th-budget__input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: var(--color-background-dark);
}
select.th-budget__input {
    padding-right: 32px;
    appearance: auto;
}

.th-budget__form-error {
    color: var(--color-error-text);
    background: var(--color-error);
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    margin: 0;
    font-size: var(--th-font-meta);
}

.hidden-visually {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
