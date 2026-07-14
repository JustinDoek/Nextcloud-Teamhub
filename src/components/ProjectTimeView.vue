<template>
    <div class="th-time">
        <!-- v3.101.1: the Time iframe is now composed from three
             IframeWidgetCard widgets (Overview / Utilisation / Report)
             so each block has its own header + action row, matching the
             Budget iframe restructure and the dashboard's widget-card
             visual language. See gui.md follow-up. -->

        <!-- Widget 1: Time overview (KPI row).
             v3.102.1: settings button now carries its label ("Time settings")
             alongside the icon so admins can see at a glance where the
             button leads (Manage Team → Project → Time investment). Gated
             on isTeamAdmin — only users with manage-team access see it. -->
        <IframeWidgetCard :title="t('teamhub', 'Time overview')">
            <template #icon><ClockOutline :size="18" /></template>
            <template #actions>
                <!-- v3.103.3: primary variant so this reads at the same
                     colour rank as the widget-header Log-time button.
                     Deep-links to the Time investment config section of
                     Manage Team → Project via SET_MANAGE_TEAM_DEEP_LINK
                     (same pattern the Compass uses). -->
                <NcButton v-if="isTeamAdmin"
                    variant="primary"
                    :title="t('teamhub', 'Open Manage Team — Project — Time investment')"
                    @click="openTimeSettings">
                    <template #icon><CogOutline :size="16" /></template>
                    {{ t('teamhub', 'Time settings') }}
                </NcButton>
            </template>
            <div class="th-time__kpis">
                <div class="th-time__kpi th-time__kpi--available">
                    <div class="th-time__kpi-head">
                        <span class="th-time__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Available hours') }}
                    </div>
                    <div class="th-time__kpi-value">
                        <template v-if="time.totalAvailableMinutes > 0">
                            {{ formatHours(time.totalAvailableMinutes) }}
                        </template>
                        <template v-else>{{ t('teamhub', 'Uncapped') }}</template>
                    </div>
                    <div class="th-time__kpi-sub">
                        {{ n('teamhub', '{count} project member', '{count} project members', time.members.length, { count: time.members.length }) }}
                    </div>
                </div>

                <div class="th-time__kpi th-time__kpi--logged">
                    <div class="th-time__kpi-head">
                        <span class="th-time__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Logged') }}
                    </div>
                    <div class="th-time__kpi-value">{{ formatHours(time.totalLoggedMinutes) }}</div>
                    <div class="th-time__kpi-sub">
                        <template v-if="time.totalAvailableMinutes > 0">
                            {{ t('teamhub', '{pct}% of available', { pct: loggedPct }) }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'no cap set') }}
                        </template>
                    </div>
                </div>

                <div class="th-time__kpi" :class="remainingKpiClass">
                    <div class="th-time__kpi-head">
                        <span class="th-time__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Remaining') }}
                    </div>
                    <div class="th-time__kpi-value">
                        <template v-if="time.totalAvailableMinutes > 0">
                            {{ formatHours(time.totalAvailableMinutes - time.totalLoggedMinutes) }}
                        </template>
                        <template v-else>—</template>
                    </div>
                    <div class="th-time__kpi-sub">
                        <template v-if="time.totalAvailableMinutes > 0">
                            {{ t('teamhub', '{pct}% remaining', { pct: remainingPct }) }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'no cap set') }}
                        </template>
                    </div>
                </div>

                <div class="th-time__kpi th-time__kpi--utilisation">
                    <div class="th-time__kpi-head">
                        <span class="th-time__kpi-swatch" aria-hidden="true" />
                        {{ t('teamhub', 'Utilisation') }}
                    </div>
                    <div class="th-time__kpi-value">
                        <template v-if="time.totalAvailableMinutes > 0">{{ loggedPct }}%</template>
                        <template v-else>—</template>
                    </div>
                    <div class="th-time__kpi-sub">
                        {{ t('teamhub', 'across the project') }}
                    </div>
                </div>
            </div>
        </IframeWidgetCard>

        <!-- Loading + error + no-members states are top-level (not wrapped
             in a widget card) so the initial view stays a single clean
             status message instead of an empty widget with a spinner. -->
        <div v-if="loading && time.members.length === 0" class="th-time__loading">
            <NcLoadingIcon :size="32" />
            <p>{{ t('teamhub', 'Loading time data') }}</p>
        </div>
        <div v-else-if="error" class="th-time__error">
            <AlertCircleOutline :size="32" />
            <p>{{ error }}</p>
            <NcButton type="primary" @click="fetchTime">{{ t('teamhub', 'Retry') }}</NcButton>
        </div>

        <!-- No project members yet — admin needs to add someone from
             Manage Team → Project → Time investment. -->
        <NcEmptyContent v-else-if="time.members.length === 0"
            :name="t('teamhub', 'No project members yet')"
            :description="isTeamAdmin
                ? t('teamhub', 'Add members with an available-hours budget in Time settings.')
                : t('teamhub', 'The project admin has not added any members to the time budget yet.')">
            <template #icon><ClockOutline /></template>
            <template v-if="isTeamAdmin" #action>
                <NcButton type="primary" @click="$emit('open-project-settings')">
                    <template #icon><CogOutline :size="20" /></template>
                    {{ t('teamhub', 'Time settings') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <template v-else>
            <!-- v3.102.1: widgets 2 + 3 sit in a two-column row on wide viewports
                 so Utilisation and Time report fill the available width side by
                 side instead of stacking full-width. On narrow viewports they
                 fall back to a single column. If Utilisation is hidden (no data)
                 the Time-report widget takes the full row via the --single
                 modifier. -->
            <div class="th-time__widget-row"
                :class="{ 'th-time__widget-row--single': !(hasChartData || time.lanes.length > 0) }">

            <!-- Widget 2: Utilisation charts — per-member bars + per-lane
                 rollup. Only rendered when there's actual data; a chart of
                 zeros is visual noise since the KPI row already carries them. -->
            <IframeWidgetCard
                v-if="hasChartData || time.lanes.length > 0"
                :title="t('teamhub', 'Utilisation')">
                <template #icon><ChartBarIcon :size="18" /></template>

                <!-- v3.104.3: Time-utilisation donut — mirrors the Budget
                     iframe's donut. Denominator is total time available for
                     members with a quota, numerator is total logged for the
                     same members. Suppressed when no member has a cap
                     (uncapped-only projects have nothing meaningful to plot
                     against). -->
                <div v-if="timeDonut.hasCappedUsers"
                    class="th-time__chart-card th-time__chart-card--donut" role="img"
                    :aria-label="t('teamhub', 'Time utilisation. {v}% logged.', { v: timeDonut.pctLabel })">
                    <div class="th-time__chart-card-title">{{ t('teamhub', 'Time utilisation') }}</div>
                    <svg :viewBox="`0 0 ${timeDonut.size} ${timeDonut.size}`"
                        class="th-time__donut" width="180" height="180">
                        <circle :cx="timeDonut.cx" :cy="timeDonut.cy" :r="timeDonut.r"
                            fill="none" :stroke-width="timeDonut.stroke"
                            class="th-time__donut-track" />
                        <circle v-if="timeDonut.pct > 0"
                            :cx="timeDonut.cx" :cy="timeDonut.cy" :r="timeDonut.r"
                            fill="none" :stroke-width="timeDonut.stroke"
                            :stroke-dasharray="timeDonut.dashArray"
                            :stroke-dashoffset="timeDonut.dashOffset"
                            stroke-linecap="round"
                            :class="['th-time__donut-arc', timeDonut.stateClass]"
                            :transform="`rotate(-90 ${timeDonut.cx} ${timeDonut.cy})`" />
                        <text :x="timeDonut.cx" :y="timeDonut.cy - 4" text-anchor="middle"
                            class="th-time__donut-pct" :class="timeDonut.stateClass">
                            {{ timeDonut.pctLabel }}%
                        </text>
                        <text :x="timeDonut.cx" :y="timeDonut.cy + 20" text-anchor="middle"
                            class="th-time__donut-sub">
                            {{ t('teamhub', 'of available') }}
                        </text>
                    </svg>
                    <div class="th-time__donut-legend">
                        <div>
                            <span class="th-time__donut-swatch th-time__donut-swatch--allocated" />
                            {{ t('teamhub', 'Available') }}:
                            <strong>{{ timeDonut.availableLabel }}</strong>
                        </div>
                        <div>
                            <span :class="['th-time__donut-swatch', timeDonut.stateClass]" />
                            {{ t('teamhub', 'Logged') }}:
                            <strong>{{ timeDonut.loggedLabel }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Per-member horizontal bar chart. One row per project member.
                     Bars scale to the global available max so members are visually
                     comparable. Fill colour follows utilisation state. -->
                <div v-if="hasChartData" class="th-time__chart-card">
                <div class="th-time__chart-card-title">{{ t('teamhub', 'Logged vs available per member') }}</div>
                <div class="th-time__bars">
                    <div v-for="row in memberBars" :key="row.userId" class="th-time__bar-row">
                        <div class="th-time__bar-name">{{ row.displayName }}</div>
                        <div class="th-time__bar-track" role="img" :aria-label="row.ariaLabel">
                            <div class="th-time__bar-alloc" :style="{ width: row.availPct + '%' }" />
                            <div class="th-time__bar-real" :class="row.stateClass" :style="{ width: row.loggedPct + '%' }" />
                        </div>
                        <div class="th-time__bar-nums">
                            <span :class="['th-time__num', row.stateClass]">{{ formatHours(row.logged) }}</span>
                            <span class="th-time__num-sep">/</span>
                            <span>{{ row.available > 0 ? formatHours(row.available) : t('teamhub', 'no cap') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per-lane rollup — how much time landed on each workstream. -->
            <div v-if="time.lanes.length > 0" class="th-time__chart-card">
                <div class="th-time__chart-card-title">{{ t('teamhub', 'Logged per lane') }}</div>
                <div class="th-time__bars">
                    <div v-for="(lane, idx) in laneBars" :key="lane.stackId" class="th-time__bar-row">
                        <div class="th-time__bar-name">
                            <span class="th-time__lane-swatch" :style="{ background: lane.color }" aria-hidden="true" />
                            {{ lane.stackTitle }}
                        </div>
                        <div class="th-time__bar-track">
                            <div class="th-time__bar-real"
                                :style="{ width: lane.pct + '%', background: lane.color }" />
                        </div>
                        <div class="th-time__bar-nums">{{ formatHours(lane.loggedMinutes) }}</div>
                    </div>
                </div>
            </div>
            </IframeWidgetCard>

            <!-- Widget 3: Time report — the view switcher (per-lane vs
                 per-member) with the full log tables. Log-time button lives
                 in the widget header actions so it's discoverable at the
                 same spot as every other widget's primary action.
                 v3.103.2: the per-member <select> moved OUT of #actions and
                 INTO the report body next to the "Per member" toggle — it
                 belongs conceptually with the toggle, and keeping it in the
                 header caused a couple-pixel header-height jitter every time
                 the user switched views (raw <select> vs NcButton intrinsic
                 heights differ). Log-time button switched from secondary to
                 primary to match the "Add expense" button in the Budget
                 iframe — both are the primary action of their widget. -->
            <IframeWidgetCard :title="t('teamhub', 'Time report')">
                <template #icon><FormatListBulletedIcon :size="18" /></template>
                <template #actions>
                    <NcButton v-if="canLogSelf"
                        variant="primary"
                        @click="openLogSelfModal">
                        <template #icon><Plus :size="16" /></template>
                        {{ t('teamhub', 'Log time') }}
                    </NcButton>
                </template>

                <div class="th-time__report">
                <div class="th-time__report-head">
                    <div class="th-time__view-toggle" role="group" :aria-label="t('teamhub', 'Report view')">
                        <button type="button"
                            :class="{ active: viewMode === 'lane' }"
                            :aria-pressed="viewMode === 'lane' ? 'true' : 'false'"
                            @click="viewMode = 'lane'">
                            {{ t('teamhub', 'Per lane') }}
                        </button>
                        <button type="button"
                            :class="{ active: viewMode === 'member' }"
                            :aria-pressed="viewMode === 'member' ? 'true' : 'false'"
                            @click="viewMode = 'member'">
                            {{ t('teamhub', 'Per member') }}
                        </button>
                    </div>
                    <!-- v3.103.2: member picker sits INSIDE the report body
                         next to the Per lane / Per member toggle instead of in
                         the widget-header #actions slot. This keeps the widget
                         header the same height whether Per lane or Per member
                         is selected, and puts the picker where the user's eye
                         is already looking (right beside the toggle they just
                         clicked). Only rendered in Per member view. -->
                    <select v-if="viewMode === 'member'"
                        v-model="selectedMemberUserId"
                        class="th-time__member-picker"
                        :aria-label="t('teamhub', 'Choose member')">
                        <option v-for="m in time.members" :key="'ms-' + m.userId" :value="m.userId">
                            {{ m.displayName }}
                        </option>
                    </select>
                </div>

                <!-- Per-lane view: one section per Deck stack, table of
                     Member / Activity / Hours entries. -->
                <template v-if="viewMode === 'lane'">
                    <div v-if="time.logs.length === 0" class="th-time__report-empty">
                        {{ t('teamhub', 'No time logged yet.') }}
                    </div>
                    <div v-for="lane in laneRollup" :key="'lr-' + lane.stackId" class="th-time__lane-section">
                        <div class="th-time__lane-head">
                            <span class="th-time__lane-swatch" :style="{ background: lane.color }" aria-hidden="true" />
                            <h4 class="th-time__lane-title">{{ lane.stackTitle }}</h4>
                            <span class="th-time__lane-total">{{ formatHours(lane.total) }}</span>
                        </div>
                        <div v-if="lane.logs.length === 0" class="th-time__report-empty th-time__report-empty--small">
                            {{ t('teamhub', 'No entries in this lane yet.') }}
                        </div>
                        <table v-else class="th-time__report-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ t('teamhub', 'Member') }}</th>
                                    <th scope="col">{{ t('teamhub', 'Activity') }}</th>
                                    <th scope="col" class="th-time__num-col">{{ t('teamhub', 'Hours') }}</th>
                                    <th scope="col" class="th-time__action-col">
                                        <span class="hidden-visually">{{ t('teamhub', 'Actions') }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in lane.logs" :key="'ll-' + log.id">
                                    <td>{{ log.displayName }}</td>
                                    <td>
                                        <div class="th-time__log-title">{{ log.cardTitle }}</div>
                                        <div v-if="log.description" class="th-time__log-sub">{{ log.description }}</div>
                                        <div class="th-time__log-meta">{{ formatDate(log.workedAt) }}</div>
                                    </td>
                                    <td class="th-time__num-col">{{ formatHours(log.minutes) }}</td>
                                    <td class="th-time__action-col">
                                        <NcActions v-if="canEditLog(log)">
                                            <NcActionButton @click="openEditLog(log)">
                                                <template #icon><Pencil :size="20" /></template>
                                                {{ t('teamhub', 'Edit') }}
                                            </NcActionButton>
                                            <NcActionButton @click="confirmDeleteLog(log)">
                                                <template #icon><Delete :size="20" /></template>
                                                {{ t('teamhub', 'Delete') }}
                                            </NcActionButton>
                                        </NcActions>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <!-- Per-member view: pick a member, see their tracked
                     activities. Summary strip at the top shows the same
                     figures as the KPI cards but scoped to this person. -->
                <template v-else>
                    <div v-if="selectedMember" class="th-time__member-summary">
                        <div>
                            <div class="th-time__stat-label">{{ t('teamhub', 'Available') }}</div>
                            <div class="th-time__stat-value">
                                <template v-if="selectedMember.availableMinutes > 0">{{ formatHours(selectedMember.availableMinutes) }}</template>
                                <template v-else>{{ t('teamhub', 'Uncapped') }}</template>
                            </div>
                        </div>
                        <div>
                            <div class="th-time__stat-label">{{ t('teamhub', 'Logged') }}</div>
                            <div class="th-time__stat-value" :class="memberValueClass(selectedMember)">
                                {{ formatHours(selectedMember.loggedMinutes) }}
                            </div>
                        </div>
                        <div>
                            <div class="th-time__stat-label">{{ t('teamhub', 'Remaining') }}</div>
                            <div class="th-time__stat-value" :class="memberValueClass(selectedMember)">
                                <template v-if="selectedMember.remainingMinutes !== null">{{ formatHours(selectedMember.remainingMinutes) }}</template>
                                <template v-else>—</template>
                            </div>
                        </div>
                    </div>
                    <div v-if="selectedMemberLogs.length === 0" class="th-time__report-empty">
                        {{ t('teamhub', 'No entries for this member yet.') }}
                    </div>
                    <table v-else class="th-time__report-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ t('teamhub', 'Activity') }}</th>
                                <th scope="col">{{ t('teamhub', 'Lane') }}</th>
                                <th scope="col" class="th-time__num-col">{{ t('teamhub', 'Hours') }}</th>
                                <th scope="col" class="th-time__action-col">
                                    <span class="hidden-visually">{{ t('teamhub', 'Actions') }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in selectedMemberLogs" :key="'ml-' + log.id">
                                <td>
                                    <div class="th-time__log-title">{{ log.cardTitle }}</div>
                                    <div v-if="log.description" class="th-time__log-sub">{{ log.description }}</div>
                                    <div class="th-time__log-meta">{{ formatDate(log.workedAt) }}</div>
                                </td>
                                <td>
                                    <span class="th-time__lane-swatch th-time__lane-swatch--inline"
                                        :style="{ background: laneColorFor(log.stackId) }"
                                        aria-hidden="true" />
                                    {{ log.stackTitle }}
                                </td>
                                <td class="th-time__num-col">{{ formatHours(log.minutes) }}</td>
                                <td class="th-time__action-col">
                                    <NcActions v-if="canEditLog(log)">
                                        <NcActionButton @click="openEditLog(log)">
                                            <template #icon><Pencil :size="20" /></template>
                                            {{ t('teamhub', 'Edit') }}
                                        </NcActionButton>
                                        <NcActionButton @click="confirmDeleteLog(log)">
                                            <template #icon><Delete :size="20" /></template>
                                            {{ t('teamhub', 'Delete') }}
                                        </NcActionButton>
                                    </NcActions>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
                </div>
            </IframeWidgetCard>

            </div>
            <!-- /.th-time__widget-row -->
        </template>

        <!-- Log-time dialog -->
        <NcDialog v-if="logModalOpen"
            :name="editingLog ? t('teamhub', 'Edit time entry') : t('teamhub', 'Log time')"
            :open="logModalOpen"
            @update:open="closeLogModal">
            <template #default>
                <!-- v3.103.2: modal form rebuilt for a clean, consistent
                     appearance. Every field shares one .th-time__input class
                     — the previous mix of NcTextField chrome, raw <select>s,
                     and bare <input>s looked half-styled. Labels sit above
                     fields with a stable single-line height. -->
                <form class="th-time__form">
                    <div v-if="!editingLog && isTeamAdmin && time.members.length > 1" class="th-time__form-field">
                        <label for="th-time-forwho" class="th-time__form-label">{{ t('teamhub', 'Log for') }}</label>
                        <select
                            id="th-time-forwho"
                            v-model="form.forUserId"
                            class="th-time__input"
                            @change="onForUserChange">
                            <option v-for="m in time.members" :key="'lf-' + m.userId" :value="m.userId">
                                {{ m.displayName }}
                            </option>
                        </select>
                    </div>

                    <div v-if="!editingLog" class="th-time__form-field">
                        <label for="th-time-card" class="th-time__form-label">{{ t('teamhub', 'Deck card') }}</label>
                        <select id="th-time-card" v-model="form.cardId" class="th-time__input">
                            <option :value="null" disabled>{{ t('teamhub', 'Choose a card') }}</option>
                            <option v-for="c in loggableCards" :key="c.cardId" :value="c.cardId">
                                {{ c.cardTitle }} — {{ c.stackTitle }}
                            </option>
                        </select>
                        <div v-if="loggableCards.length === 0" class="th-time__form-hint">
                            {{ t('teamhub', 'No cards to log against. Ask the project lead to assign you to a card first.') }}
                        </div>
                    </div>

                    <div class="th-time__form-row">
                        <div class="th-time__form-field">
                            <label for="th-time-h" class="th-time__form-label">{{ t('teamhub', 'Hours') }}</label>
                            <input
                                id="th-time-h"
                                v-model.number="form.hours"
                                type="number" min="0" max="24" step="1"
                                class="th-time__input" />
                        </div>
                        <div class="th-time__form-field">
                            <label for="th-time-m" class="th-time__form-label">{{ t('teamhub', 'Minutes') }}</label>
                            <input
                                id="th-time-m"
                                v-model.number="form.mins"
                                type="number" min="0" max="59" step="5"
                                class="th-time__input" />
                        </div>
                        <div class="th-time__form-field">
                            <label for="th-time-date" class="th-time__form-label">{{ t('teamhub', 'Date worked') }}</label>
                            <input id="th-time-date" v-model="form.workedAt" type="date" class="th-time__input" />
                        </div>
                    </div>

                    <div class="th-time__form-field">
                        <label for="th-time-note" class="th-time__form-label">{{ t('teamhub', 'Note (optional)') }}</label>
                        <input
                            id="th-time-note"
                            v-model="form.description"
                            type="text"
                            :placeholder="t('teamhub', 'Short note')"
                            class="th-time__input" />
                    </div>

                    <div v-if="formError" class="th-time__form-error">{{ formError }}</div>
                </form>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="closeLogModal">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton type="primary" :disabled="submitting" @click="submitLog">
                    {{ editingLog ? t('teamhub', 'Save') : t('teamhub', 'Log') }}
                </NcButton>
            </template>
        </NcDialog>

        <!-- Delete-confirm -->
        <NcDialog v-if="deleteTarget"
            :name="t('teamhub', 'Delete time entry')"
            :open="!!deleteTarget"
            @update:open="deleteTarget = null">
            <template #default>
                <p style="margin: 0;">
                    {{ t('teamhub', 'Delete this {mins} entry? This cannot be undone.', { mins: formatHours(deleteTarget.minutes) }) }}
                </p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="deleteTarget = null">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton type="error" :disabled="submitting" @click="submitDeleteLog">{{ t('teamhub', 'Delete') }}</NcButton>
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
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

// v3.101.1: shared widget-card chrome used by every full-tab iframe view.
import IframeWidgetCard from './IframeWidgetCard.vue'

// Same 8-colour lane palette as ProjectBudgetView + ProjectSwimlaneView so a
// workstream's colour is consistent everywhere it's rendered.
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
    name: 'ProjectTimeView',
    components: {
        NcActions, NcActionButton, NcButton, NcDialog, NcEmptyContent,
        NcLoadingIcon,
        AlertCircleOutline, ChartBarIcon, ClockOutline, CogOutline,
        Delete, FormatListBulletedIcon, Pencil, Plus,
        IframeWidgetCard,
    },
    emits: ['open-project-settings'],
    data() {
        return {
            loading: true,
            error: null,
            time: {
                isProject: false,
                timeViewMinLevel: 1,
                totalAvailableMinutes: 0,
                totalLoggedMinutes: 0,
                members: [],
                lanes: [],
                logs: [],
            },
            loggableCards: [],
            viewMode: 'lane',        // 'lane' | 'member'
            selectedMemberUserId: null,
            logModalOpen: false,
            editingLog: null,        // full log row when editing
            form: {
                cardId: null,
                forUserId: null,     // admin-on-behalf: which user is this for
                hours: 0,
                mins: 0,
                description: '',
                workedAt: this.todayIso(),
            },
            formError: '',
            deleteTarget: null,      // full log row
            submitting: false,
        }
    },
    computed: {
        /* v3.103.6: currentView is watched below so we can reset scrollTop
           each time the user navigates INTO the Time tab — fixes the
           mobile issue where Time overview landed off-screen because
           scrollTop persisted across v-show display: none / block toggles. */
        ...mapState(['currentTeamId', 'currentView']),
        /* v3.103.2: the Vuex store getter is `currentUserIsTeamAdmin` —
           the previous `mapGetters(['isTeamAdmin'])` silently produced
           `undefined` so every `v-if="isTeamAdmin"` was falsy (including
           the "Time settings" button, the no-project-members empty-state
           gating, and the per-member picker's admin condition). Alias
           here so local references stay readable. */
        ...mapGetters({ isTeamAdmin: 'currentUserIsTeamAdmin' }),

        loggedPct() {
            const avail = this.time.totalAvailableMinutes || 0
            if (avail <= 0) return 0
            return Math.round((this.time.totalLoggedMinutes / avail) * 100)
        },

        remainingPct() {
            const avail = this.time.totalAvailableMinutes || 0
            if (avail <= 0) return 0
            const remaining = avail - (this.time.totalLoggedMinutes || 0)
            return Math.max(0, Math.round((remaining / avail) * 100))
        },

        remainingKpiClass() {
            const avail = this.time.totalAvailableMinutes || 0
            if (avail > 0 && (avail - this.time.totalLoggedMinutes) < 0) {
                return 'th-time__kpi--remaining-over'
            }
            return 'th-time__kpi--remaining'
        },

        hasChartData() {
            return this.time.members.some(m => m.availableMinutes > 0 || m.loggedMinutes > 0)
        },

        /**
         * v3.104.3: Time-utilisation donut — mirrors the Budget iframe's
         * donut. Denominator is the sum of `availableMinutes` across
         * members who HAVE a cap; uncapped members don't contribute to
         * the percentage (their logged time still appears in the
         * per-member bars below, they just don't have a ceiling to
         * measure against). Empty/no-capped state suppresses the donut
         * entirely via `hasCappedUsers`.
         *
         * State class follows the same over/under/equal semantics as the
         * Budget donut. `--color-text-*` variants are used for stroke to
         * match Time's existing bar colour convention.
         */
        timeDonut() {
            const size = 180
            const stroke = 18
            const r = (size - stroke) / 2 - 2
            const cx = size / 2
            const cy = size / 2
            const circumference = 2 * Math.PI * r
            let available = 0
            let logged    = 0
            for (const m of this.time.members) {
                if ((m.availableMinutes || 0) > 0) {
                    available += m.availableMinutes || 0
                    logged    += m.loggedMinutes    || 0
                }
            }
            let pct = 0
            let stateClass = 'th-time__equal'
            if (available > 0) {
                pct = (logged / available) * 100
                if (logged > available) stateClass = 'th-time__over'
                else if (logged < available) stateClass = 'th-time__under'
            }
            const drawPct = Math.min(100, Math.max(0, pct))
            const arcLen = (drawPct / 100) * circumference
            return {
                size, cx, cy, r, stroke,
                pct,
                pctLabel: Math.round(pct).toString(),
                stateClass,
                dashArray:  `${arcLen} ${circumference}`,
                dashOffset: 0,
                available,
                logged,
                availableLabel: this.formatHours(available),
                loggedLabel:    this.formatHours(logged),
                hasCappedUsers: available > 0,
            }
        },

        memberBars() {
            const members = this.time.members
            const globalMax = members.reduce(
                (m, r) => Math.max(m, r.availableMinutes || 0, r.loggedMinutes || 0), 1,
            )
            return members.map(m => {
                const avail  = m.availableMinutes || 0
                const logged = m.loggedMinutes || 0
                let stateClass = 'th-time__equal'
                if (avail > 0) {
                    if (logged > avail) stateClass = 'th-time__over'
                    else if (logged < avail) stateClass = 'th-time__under'
                }
                return {
                    userId:      m.userId,
                    displayName: m.displayName,
                    available:   avail,
                    logged,
                    availPct:    avail > 0 ? Math.min(100, Math.round((avail / globalMax) * 100)) : 0,
                    loggedPct:   Math.min(100, Math.round((logged / globalMax) * 100)),
                    stateClass,
                    ariaLabel:   t('teamhub', 'Time bar. Logged {logged}. Available {available}.', {
                        logged: this.formatHours(logged),
                        available: avail > 0 ? this.formatHours(avail) : t('teamhub', 'no cap'),
                    }),
                }
            })
        },

        laneBars() {
            const lanes = this.time.lanes || []
            const max = lanes.reduce((m, l) => Math.max(m, l.loggedMinutes || 0), 1)
            return lanes.map((l, idx) => ({
                stackId:       l.stackId,
                stackTitle:    l.stackTitle,
                loggedMinutes: l.loggedMinutes,
                pct:           Math.min(100, Math.round(((l.loggedMinutes || 0) / max) * 100)),
                color:         laneColour(l.stackOrder, l.stackId, idx),
            }))
        },

        selfUid() {
            const u = window.OC && window.OC.getCurrentUser && window.OC.getCurrentUser()
            return u ? u.uid : ''
        },

        /** Per-lane view: array of { stackId, stackTitle, color, total, logs[] } */
        laneRollup() {
            const lanes = this.time.lanes || []
            const logs  = this.time.logs || []
            const byStack = new Map()
            for (const log of logs) {
                if (!byStack.has(log.stackId)) byStack.set(log.stackId, [])
                byStack.get(log.stackId).push(log)
            }
            return lanes.map((lane, idx) => {
                const laneLogs = (byStack.get(lane.stackId) || []).slice()
                laneLogs.sort((a, b) => (b.workedAt || 0) - (a.workedAt || 0))
                return {
                    stackId:    lane.stackId,
                    stackTitle: lane.stackTitle,
                    color:      laneColour(lane.stackOrder, lane.stackId, idx),
                    total:      lane.loggedMinutes || 0,
                    logs:       laneLogs,
                }
            })
        },

        /** Selected member for the per-member view. */
        selectedMember() {
            if (!this.selectedMemberUserId) return null
            return this.time.members.find(m => m.userId === this.selectedMemberUserId) || null
        },

        /** All logs for the currently selected member, newest first. */
        selectedMemberLogs() {
            if (!this.selectedMemberUserId) return []
            return (this.time.logs || [])
                .filter(l => l.userId === this.selectedMemberUserId)
                .slice()
                .sort((a, b) => (b.workedAt || 0) - (a.workedAt || 0))
        },

        /** Every effective team member is auto-added to the project (backend
         *  reconcile-on-read), so every viewer of the tab can log for
         *  themselves — provided they have at least one Deck card assigned. */
        canLogSelf() {
            return this.loggableCards.length > 0 || this.isTeamAdmin
        },
    },
    watch: {
        currentTeamId(newId, oldId) {
            if (newId && newId !== oldId) {
                this.fetchTime()
                this.fetchLoggableCards()
            }
        },
        /* v3.103.6: whenever the user navigates INTO the Time tab, reset
           scroll to the top so Time overview is guaranteed visible.
           Same fix as ProjectBudgetView.currentView watcher. */
        currentView(newView) {
            if (newView === 'time') {
                this.$nextTick(() => this.resetScrollTop())
            }
        },
        // Keep the per-member picker sensible: default to self on first load,
        // fall back to the first member if self isn't in the list.
        'time.members'(newMembers) {
            if (!this.selectedMemberUserId
                || !newMembers.some(m => m.userId === this.selectedMemberUserId)) {
                const self = newMembers.find(m => m.userId === this.selfUid)
                this.selectedMemberUserId = self ? self.userId : (newMembers[0]?.userId || null)
            }
        },
    },
    mounted() {
        this.fetchTime()
        this.fetchLoggableCards()
        this.onVisibilityChange = this.onVisibilityChange.bind(this)
        document.addEventListener('visibilitychange', this.onVisibilityChange)
        window.addEventListener('focus', this.onVisibilityChange)
        // v3.103.6: pin scroll to the top after the first paint — covers
        // the preload path where the view mounts before it becomes visible.
        this.$nextTick(() => this.resetScrollTop())
    },
    beforeDestroy() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange)
        window.removeEventListener('focus', this.onVisibilityChange)
    },
    methods: {
        t, n,
        ...mapMutations(['SET_MANAGE_TEAM_DEEP_LINK']),

        /**
         * v3.103.3: deep-link to Manage Team → Project → Time investment
         * config. Same pattern as ProjectCompassPanel — stash the section
         * in Vuex, then emit the navigation event that TeamView handles.
         */
        openTimeSettings() {
            this.SET_MANAGE_TEAM_DEEP_LINK({ tab: 'project', section: 'time' })
            this.$emit('open-project-settings')
        },

        /**
         * v3.103.6: pin the iframe scroll container to its top. Fires from
         * `mounted()` and from the `currentView` watcher. Guarded because
         * $el is Node during SSR/unmount and has no `scrollTop`.
         */
        resetScrollTop() {
            if (this.$el && typeof this.$el.scrollTop === 'number') {
                this.$el.scrollTop = 0
            }
        },

        onVisibilityChange() {
            if (!document.hidden && this.currentTeamId) {
                this.fetchTime()
            }
        },

        // ── Fetches ───────────────────────────────────────────────────

        async fetchTime() {
            if (!this.currentTeamId) return
            this.loading = true
            this.error = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time`),
                )
                this.time = data
            } catch (err) {
                this.error = err?.response?.data?.error || err.message
            } finally {
                this.loading = false
            }
        },

        async fetchLoggableCards() {
            if (!this.currentTeamId) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time/loggable-cards`),
                )
                this.loggableCards = data
            } catch (_) {
                this.loggableCards = []
            }
        },

        // ── Formatting ────────────────────────────────────────────────

        /** Format minutes as "1h 30m" / "45m" / "0h". */
        formatHours(minutes) {
            const m = Math.max(0, Math.round(minutes || 0))
            if (m < 60) return t('teamhub', '{m}m', { m })
            const h = Math.floor(m / 60)
            const rem = m % 60
            if (rem === 0) return t('teamhub', '{h}h', { h })
            return t('teamhub', '{h}h {m}m', { h, m: rem })
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

        todayIso() {
            return new Date().toISOString().slice(0, 10)
        },

        // ── Lookups from the current payload ──────────────────────────

        laneColorFor(stackId) {
            const idx = (this.time.lanes || []).findIndex(l => l.stackId === stackId)
            const lane = idx >= 0 ? this.time.lanes[idx] : null
            return laneColour(lane?.stackOrder, stackId, idx < 0 ? 0 : idx)
        },

        canEditLog(log) {
            // Row's submitter (`createdBy`) can edit; admins can edit anyone's.
            return log.createdBy === this.selfUid || this.isTeamAdmin
        },

        /** Utilisation-threshold class for a member's Logged / Remaining
         *  figure. Uncapped members stay neutral; capped members go warn at
         *  70% of available, over at >100%. Applied via CSS to `color` (text)
         *  so we get an accessible red/amber without a soft background. */
        memberValueClass(m) {
            if (!m || m.availableMinutes <= 0) return ''
            const ratio = m.loggedMinutes / m.availableMinutes
            if (ratio > 1.0) return 'th-time__over'
            if (ratio >= 0.7) return 'th-time__warn'
            return ''
        },

        // ── Log-time modal ────────────────────────────────────────────

        /** "Log time" button in the report head — logs for self by default.
         *  Admins can switch the target in the dialog via the "Log for"
         *  picker (only rendered when isTeamAdmin && >1 members). */
        openLogSelfModal() {
            this.editingLog = null
            this.form = {
                cardId: null,
                forUserId: this.selfUid,
                hours: 0,
                mins: 0,
                description: '',
                workedAt: this.todayIso(),
            }
            this.formError = ''
            this.fetchLoggableCards()
            this.logModalOpen = true
        },

        /** Admin picker changed — refetch loggable cards for that user so
         *  the picker only shows cards they are currently assigned to. */
        async onForUserChange() {
            this.form.cardId = null
            if (this.form.forUserId && this.form.forUserId !== this.selfUid) {
                await this.fetchLoggableCardsForUser(this.form.forUserId)
            } else {
                await this.fetchLoggableCards()
            }
        },

        openEditLog(log) {
            this.editingLog = log
            const h = Math.floor(log.minutes / 60)
            const m = log.minutes % 60
            this.form = {
                cardId: log.cardId,
                forUserId: log.userId,
                hours: h,
                mins: m,
                description: log.description || '',
                workedAt: log.workedAt
                    ? new Date(log.workedAt * 1000).toISOString().slice(0, 10)
                    : this.todayIso(),
            }
            this.formError = ''
            this.logModalOpen = true
        },

        closeLogModal() {
            this.logModalOpen = false
            this.editingLog = null
            this.formError = ''
        },

        async fetchLoggableCardsForUser(userId) {
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time/loggable-cards`),
                    { params: { user_id: userId } },
                )
                this.loggableCards = data
            } catch (_) {
                this.loggableCards = []
            }
        },

        async submitLog() {
            const hours = Number(this.form.hours) || 0
            const mins  = Number(this.form.mins)  || 0
            const total = hours * 60 + mins
            if (total <= 0) {
                this.formError = t('teamhub', 'Time worked must be greater than zero')
                return
            }
            if (!this.editingLog && !this.form.cardId) {
                this.formError = t('teamhub', 'Choose a card')
                return
            }
            let workedAt = 0
            if (this.form.workedAt) {
                const parsed = Date.parse(this.form.workedAt + 'T00:00:00Z')
                if (!isNaN(parsed)) workedAt = Math.floor(parsed / 1000)
            }
            if (!workedAt) {
                this.formError = t('teamhub', 'Date worked is required')
                return
            }

            this.submitting = true
            try {
                if (this.editingLog) {
                    await axios.put(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time/logs/${this.editingLog.id}`),
                        {
                            minutes: total,
                            description: this.form.description.trim(),
                            worked_at: workedAt,
                        },
                    )
                    showSuccess(t('teamhub', 'Time entry saved'))
                } else {
                    await axios.post(
                        generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time/logs`),
                        {
                            card_id: this.form.cardId,
                            user_id: this.form.forUserId || this.selfUid,
                            minutes: total,
                            description: this.form.description.trim(),
                            worked_at: workedAt,
                        },
                    )
                    showSuccess(t('teamhub', 'Time logged'))
                }
                await this.fetchTime()
                this.closeLogModal()
            } catch (err) {
                const msg = err?.response?.data?.error || err.message
                this.formError = msg
                showError(t('teamhub', 'Could not save: {error}', { error: msg }))
            } finally {
                this.submitting = false
            }
        },

        confirmDeleteLog(log) {
            this.deleteTarget = log
        },

        async submitDeleteLog() {
            if (!this.deleteTarget) return
            const log = this.deleteTarget
            this.submitting = true
            try {
                await axios.delete(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/time/logs/${log.id}`),
                )
                showSuccess(t('teamhub', 'Time entry deleted'))
                await this.fetchTime()
                this.deleteTarget = null
            } catch (err) {
                showError(t('teamhub', 'Could not delete: {error}', { error: err?.response?.data?.error || err.message }))
            } finally {
                this.submitting = false
            }
        },
    },
}
</script>

<style scoped>
.th-time {
    /* v3.100.16: --color-text-warning was aliased to a raw hex to work
       around the (incorrect) assumption that NC doesn't ship a warning-
       text token. NC ships --color-warning-text with the same purpose,
       so we alias to that and let the theme drive the value. */
    --color-text-warning: var(--color-warning-text);
    padding: 16px;
    display: flex;
    flex-direction: column;
    /* v3.104.2: retired the `gap: 16px` — it stacked on top of
       .th-iframe-widget's `margin-bottom: 12px`, giving 28 px between
       widgets on Time while Budget was 12 px. Both surfaces now inherit
       widget-spacing from the widget itself for a consistent rhythm. */
    /* v3.104.1: retired `-webkit-overflow-scrolling: touch` and
       `overscroll-behavior: contain` — see the matching comment in
       ProjectBudgetView.vue's .th-budget block. Mobile uses
       `position: absolute; inset: 0` (see media query below) to lift
       the container out of the flex chain entirely so its scroll box
       is definite regardless of parent-height quirks. */
    height: 100%;
    box-sizing: border-box;
    overflow-y: auto;
    overflow-x: hidden;
    /* v3.102.1: iframe backdrop uses --color-background-dark (theme-safe)
       so widget cards read as elevated surfaces on a subtly darker canvas
       — matches ProjectBudgetView and the dashboard-widget rhythm. */
    background: var(--color-background-dark);
}
/* v3.104.2: flex column children default to flex-shrink: 1, which caused
   the Overview widget to be shrunk down to just its header when total
   content exceeded the container height (esp. mobile). Pinning shrink:0
   makes children hold their intrinsic size so total content genuinely
   overflows and overflow-y: auto engages. Mirrors .th-budget > *. */
.th-time > * {
    flex-shrink: 0;
}
@media (max-width: 720px) {
    .th-time {
        position: absolute;
        inset: 0;
        height: auto;
        padding: 12px;
    }
}

/* KPI cards — copy the Budget page's rhythm: responsive grid, coloured
   accent border + swatch driven by NC theme tokens. */
/* v3.101.1: .th-time__kpis-wrap retired — KPIs sit directly inside an
   IframeWidgetCard body. The settings button moved to the widget's
   #actions slot. */
/* v3.103.5: explicit 4/2/1 column ladder — was auto-fit minmax(180px)
   which collapsed to a 1-column stack on any phone width, forcing
   Justin's "Time overview is 4 rows × 1 column" report. Values are
   only 3-4 chars ("340h", "218h", "64%") so 2-column tiles fit
   easily on mobile and halve the vertical footprint of the widget. */
.th-time__kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    flex: 1 1 auto;
}
@media (max-width: 900px) {
    .th-time__kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 360px) {
    .th-time__kpis {
        grid-template-columns: 1fr;
    }
}
.th-time__kpi {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    border-left-width: 4px;
}
.th-time__kpi-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9em;
    color: var(--color-text-lighter);
}
.th-time__kpi-swatch {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--color-primary-element);
    flex: 0 0 auto;
}
/* v3.103.5: min-width:0 + overflow-wrap so long values ("Uncapped")
   stay inside the KPI tile at narrow widths. Font size unchanged. */
.th-time__kpi-value {
    font-size: 1.4em;
    font-weight: 600;
    color: var(--color-main-text);
    min-width: 0;
    overflow-wrap: anywhere;
}
.th-time__kpi-sub {
    font-size: 0.85em;
    color: var(--color-text-lighter);
}
.th-time__kpi--available {
    border-left-color: var(--color-primary-element);
}
.th-time__kpi--available .th-time__kpi-swatch {
    background: var(--color-primary-element);
}
.th-time__kpi--logged {
    border-left-color: var(--color-text-success);
}
.th-time__kpi--logged .th-time__kpi-swatch {
    background: var(--color-text-success);
}
.th-time__kpi--remaining {
    border-left-color: var(--color-text-warning);
}
.th-time__kpi--remaining .th-time__kpi-swatch {
    background: var(--color-text-warning);
}
.th-time__kpi--remaining-over {
    border-left-color: var(--color-text-error);
}
.th-time__kpi--remaining-over .th-time__kpi-swatch {
    background: var(--color-text-error);
}
.th-time__kpi--remaining-over .th-time__kpi-value {
    color: var(--color-text-error);
}
.th-time__kpi--utilisation {
    border-left-color: var(--color-primary-element);
}
.th-time__kpi--utilisation .th-time__kpi-swatch {
    background: var(--color-primary-element);
}

/* v3.101.1: .th-time__settings-btn retired — the settings button moved
   to the widget-card #actions slot as an icon-only NcButton. */

/* Loading / error / empty */
.th-time__loading, .th-time__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 32px;
    color: var(--color-text-lighter);
}
.th-time__error {
    color: var(--color-error);
}

/* v3.102.1: two-column row that holds Utilisation (widget 2) and
   Time report (widget 3). Collapses to a single column on narrow
   viewports. --single modifier fires when Utilisation is hidden so the
   Time-report widget spans the full row. */
.th-time__widget-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    align-items: start;
}
@media (min-width: 1024px) {
    .th-time__widget-row {
        grid-template-columns: 1fr 1fr;
    }
    .th-time__widget-row--single {
        grid-template-columns: 1fr;
    }
}

/* Chart cards */
.th-time__chart-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 12px 14px;
}
.th-time__chart-card + .th-time__chart-card {
    margin-top: 12px;
}
.th-time__chart-card-title {
    font-weight: 600;
    margin-bottom: 8px;
}

/* v3.104.3: Time-utilisation donut — cloned from the Budget iframe.
   Same 180×180 SVG, same stroke width, same over/under/equal state
   classes. Uses --color-text-error/success for the arc stroke so the
   donut ties into Time's existing bar-colour convention rather than
   Budget's --color-error/success raw variant.

   Layout: centered column with title, donut, then a two-line legend
   ("Available: 50h" / "Logged: 1h 30m") — matches Budget rhythm. */
.th-time__chart-card--donut {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.th-time__donut {
    display: block;
    margin: 0 auto;
}
.th-time__donut-track {
    stroke: var(--color-background-dark);
}
.th-time__donut-arc {
    stroke: var(--color-primary-element);
    transition: stroke-dasharray 250ms ease-out;
}
.th-time__donut-arc.th-time__over   { stroke: var(--color-text-error); }
.th-time__donut-arc.th-time__under  { stroke: var(--color-text-success); }
.th-time__donut-arc.th-time__equal  { stroke: var(--color-primary-element); }
.th-time__donut-pct {
    font-size: 26px;
    font-weight: 700;
    fill: var(--color-main-text);
}
.th-time__donut-pct.th-time__over   { fill: var(--color-text-error); }
.th-time__donut-pct.th-time__under  { fill: var(--color-text-success); }
.th-time__donut-pct.th-time__equal  { fill: var(--color-main-text); }
.th-time__donut-sub {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    fill: var(--color-text-maxcontrast);
}
.th-time__donut-legend {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-self: stretch;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}
.th-time__donut-legend strong {
    color: var(--color-main-text);
    font-weight: 600;
    margin-left: 4px;
}
.th-time__donut-swatch {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    margin-right: 6px;
    vertical-align: middle;
    background: var(--color-primary-element);
}
.th-time__donut-swatch--allocated { background: var(--color-background-dark); }
.th-time__donut-swatch.th-time__over   { background: var(--color-text-error); }
.th-time__donut-swatch.th-time__under  { background: var(--color-text-success); }
.th-time__donut-swatch.th-time__equal  { background: var(--color-primary-element); }
.th-time__bars {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.th-time__bar-row {
    display: grid;
    grid-template-columns: 160px 1fr 120px;
    align-items: center;
    gap: 12px;
    font-size: 0.9em;
}
.th-time__bar-name {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-main-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.th-time__lane-swatch {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex: 0 0 auto;
}
.th-time__bar-track {
    position: relative;
    height: 14px;
    background: var(--color-background-dark);
    border-radius: 7px;
    overflow: hidden;
}
.th-time__bar-alloc {
    position: absolute;
    top: 0; left: 0; bottom: 0;
    background: var(--color-background-darker);
    border-radius: 7px;
}
.th-time__bar-real {
    position: absolute;
    top: 0; left: 0; bottom: 0;
    background: var(--color-primary-element);
    border-radius: 7px;
    transition: width 0.2s ease-out;
}
.th-time__bar-real.th-time__over {
    background: var(--color-text-error);
}
.th-time__bar-real.th-time__under {
    background: var(--color-text-success);
}
.th-time__bar-real.th-time__equal,
.th-time__bar-real.th-time__warn {
    background: var(--color-text-warning);
}
.th-time__bar-nums {
    text-align: right;
    font-variant-numeric: tabular-nums;
    color: var(--color-text-lighter);
}
.th-time__num.th-time__over  { color: var(--color-text-error);   font-weight: 600; }
.th-time__num.th-time__warn  { color: var(--color-text-warning); font-weight: 600; }
.th-time__num.th-time__under { color: var(--color-text-success); font-weight: 600; }
.th-time__num.th-time__equal { color: var(--color-main-text); }
.th-time__num-sep { padding: 0 4px; color: var(--color-text-maxcontrast); }

/* Report section: shared shell + view switcher */
.th-time__report {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
/* v3.103.2: layout is now toggle-group + optional member-picker,
   adjacent (not opposite ends). Was `justify-content: space-between`
   from when the Log-time button also lived here — that button has
   moved to the widget header actions. */
.th-time__report-head {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.th-time__view-toggle {
    display: inline-flex;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    overflow: hidden;
}
.th-time__view-toggle button {
    background: transparent;
    border: none;
    padding: 6px 14px;
    color: var(--color-main-text);
    cursor: pointer;
    font-size: 0.9em;
}
.th-time__view-toggle button.active {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}
.th-time__view-toggle button + button {
    border-left: 1px solid var(--color-border);
}
.th-time__view-toggle button:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}
/* v3.103.2: picker moved from the widget header down into the report
   body next to the Per lane / Per member toggle group. Height matches
   the toggle group's rendered height so both sit on a level baseline. */
.th-time__member-picker {
    height: 34px;
    box-sizing: border-box;
    padding: 6px 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    min-width: 200px;
}
.th-time__member-picker:focus,
.th-time__member-picker:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element);
}
/* v3.103.4: empty-state pattern brought in line with the rest of the
   app — italic + --th-font-meta + maxcontrast text on a soft neutral
   surface (matches .th-widget__state / .th-widget__state--empty in
   widget-tokens.css). No more heavy background-hover block. */
.th-time__report-empty {
    padding: 16px 14px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    font-style: italic;
}
.th-time__report-empty--small {
    padding: 10px 14px;
    font-size: var(--th-font-micro);
    text-align: left;
}

/* Per-lane sections */
.th-time__lane-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.th-time__lane-head {
    display: flex;
    align-items: center;
    gap: 8px;
}
.th-time__lane-swatch {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 2px;
    flex: 0 0 auto;
}
.th-time__lane-swatch--inline {
    width: 10px;
    height: 10px;
    margin-right: 6px;
    vertical-align: middle;
}
.th-time__lane-title {
    margin: 0;
    font-size: 1em;
    font-weight: 600;
    flex: 1 1 auto;
}
.th-time__lane-total {
    font-variant-numeric: tabular-nums;
    color: var(--color-text-lighter);
    font-weight: 600;
}

/* Report tables */
.th-time__report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9em;
}
.th-time__report-table th,
.th-time__report-table td {
    text-align: left;
    padding: 8px 6px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}
.th-time__report-table th {
    font-size: 0.8em;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--color-text-lighter);
    font-weight: 600;
}
.th-time__report-table tr:last-child td { border-bottom: none; }
.th-time__num-col {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.th-time__action-col {
    width: 44px;
    text-align: right;
}

.th-time__log-title {
    font-weight: 500;
    color: var(--color-main-text);
}
.th-time__log-sub {
    font-size: 0.85em;
    color: var(--color-text-lighter);
    margin-top: 2px;
}
.th-time__log-meta {
    font-size: 0.8em;
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
}

/* Per-member summary strip */
.th-time__member-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 10px 12px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
}
.th-time__stat-label {
    font-size: 0.8em;
    color: var(--color-text-lighter);
}
.th-time__stat-value {
    font-size: 1.1em;
    font-weight: 600;
    color: var(--color-main-text);
}
.th-time__stat-value.th-time__over  { color: var(--color-text-error); }
.th-time__stat-value.th-time__warn  { color: var(--color-text-warning); }
.th-time__stat-value.th-time__under { color: var(--color-text-success); }

/* Log-time form */
/* v3.103.2: Log-time modal form styling rebuilt to match the Budget
   Add-expense form — same one-line labels above unified .th-time__input
   fields, hours/minutes/date in a single grid row so they read as a
   compact time-entry group. */
.th-time__form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 380px;
}
.th-time__form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    flex: 1;
}
.th-time__form-label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    font-weight: var(--th-font-weight-medium);
    line-height: 1.3;
}
.th-time__form-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1.4fr;
    gap: 12px;
}
@media (max-width: 480px) {
    .th-time__form-row {
        grid-template-columns: 1fr;
    }
}
.th-time__input {
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
.th-time__input:hover:not(:disabled) {
    border-color: var(--color-border-dark);
}
.th-time__input:focus,
.th-time__input:focus-visible {
    outline: none;
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element);
}
select.th-time__input {
    padding-right: 32px;
    appearance: auto;
}
.th-time__form-hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-top: 2px;
}
.th-time__form-error {
    color: var(--color-error-text);
    background: var(--color-error);
    padding: 8px 12px;
    border-radius: var(--border-radius-large);
    margin: 0;
    font-size: var(--th-font-meta);
}
</style>
