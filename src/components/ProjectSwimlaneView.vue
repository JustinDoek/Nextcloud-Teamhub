<template>
    <div class="th-sl">
        <div class="th-sl__toolbar">
            <NcButton type="tertiary"
                :aria-label="t('teamhub', 'Previous period')"
                @click="goPrev">
                <template #icon><ChevronLeft :size="20" /></template>
            </NcButton>
            <NcButton type="tertiary"
                :aria-label="t('teamhub', 'Jump to today')"
                @click="goToday">
                <template #icon><CalendarToday :size="20" /></template>
            </NcButton>
            <NcButton type="tertiary"
                :aria-label="t('teamhub', 'Next period')"
                @click="goNext">
                <template #icon><ChevronRight :size="20" /></template>
            </NcButton>

            <span class="th-sl__period" aria-live="polite">{{ periodLabel }}</span>

            <span class="th-sl__spacer" />

            <NcButton v-for="opt in viewStyleOptions"
                :key="'vs-' + opt.value"
                :type="viewStyle === opt.value ? 'primary' : 'tertiary'"
                @click="viewStyle = opt.value">
                {{ opt.label }}
            </NcButton>

            <span class="th-sl__toolbar-divider" />

            <!-- v3.99.7 — view-mode dropdown replaces the button group.
                 Adding "1 week" made the button row too wide for narrow
                 sidebars, and Justin asked specifically for a dropdown. -->
            <label class="th-sl__vm-label" :for="'th-sl-vm-' + _uid">
                {{ t('teamhub', 'View range') }}
            </label>
            <select
                :id="'th-sl-vm-' + _uid"
                class="th-sl__vm-select"
                :value="viewMode"
                :aria-label="t('teamhub', 'Timeline view range')"
                @change="setViewMode($event.target.value)">
                <option v-for="opt in viewModeOptions" :key="'vm-' + opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>

            <span class="th-sl__toolbar-divider" />

            <NcButton type="tertiary"
                :aria-label="printButtonLabel"
                :title="printButtonLabel"
                @click="printSwimlane">
                <template #icon><PrinterOutline :size="20" /></template>
            </NcButton>
        </div>

        <div v-if="loading" class="th-sl__status">
            <NcLoadingIcon :size="32" />
        </div>

        <NcEmptyContent v-else-if="error"
            :name="t('teamhub', 'Could not load the swimlane view')"
            :description="error">
            <template #icon><AlertCircleOutline :size="48" /></template>
            <template #action>
                <NcButton @click="fetchTimeline">
                    {{ t('teamhub', 'Retry') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <NcEmptyContent v-else-if="!canvasLayout.lanes.length"
            :name="t('teamhub', 'No workstreams yet')"
            :description="t('teamhub', 'Connect a Deck board to this project to see its stacks as swimlanes here.')">
            <template #icon><ViewAgendaOutline :size="48" /></template>
        </NcEmptyContent>

        <!-- View: Lanes — one row per card inside each lane, subject shown
             inside the bar. Every card is a single filled bar (never a bare
             dot): a real start→due span when both resolve, otherwise a bar
             exactly one calendar day wide anchored on the due date. -->
        <div v-else-if="viewStyle === 'lanes'"
            class="th-sl__scroll"
            role="group"
            :aria-label="t('teamhub', 'Project swimlanes')"
            :style="{ '--th-sl-bar-h': BAR_H + 'px' }"
            @scroll.passive="closePopover">
            <div class="th-sl__canvas" :style="{ width: axis.totalWidth + 'px', height: canvasLayout.totalHeight + 'px' }">
                <div v-for="marker in axis.dateMarkers"
                    :key="'dm-' + marker.key"
                    class="th-sl__dmark"
                    :class="{ 'th-sl__dmark--major': marker.major }"
                    :style="{ left: marker.x + 'px' }">
                    <span class="th-sl__dmark-label">{{ marker.label }}</span>
                    <div class="th-sl__dmark-rule" />
                </div>

                <div v-if="axis.todayX !== null" class="th-sl__today" :style="{ left: axis.todayX + 'px' }">
                    <span class="th-sl__today-label">{{ t('teamhub', 'Today') }}</span>
                    <div class="th-sl__today-rule" />
                </div>

                <div v-for="marker in axis.milestoneMarkers"
                    :key="marker.id"
                    class="th-sl__milestone"
                    :style="{ left: marker.x + 'px' }">
                    <span class="th-sl__milestone-label" :title="marker.title">{{ marker.title }}</span>
                    <div class="th-sl__milestone-rule" />
                </div>

                <template v-for="(lane, idx) in canvasLayout.lanes" :key="'lane-' + (lane.stackId ?? ('unassigned-' + idx))">
                    <div class="th-sl__lane-bg"
                        :class="{ 'th-sl__lane-bg--alt': idx % 2 === 1 }"
                        :style="{ top: lane.top + 'px', height: lane.height + 'px' }" />
                    <div class="th-sl__lane-label"
                        :style="{ top: (lane.top + 4) + 'px' }">
                        <span class="th-sl__lane-swatch"
                            :style="{ background: lane.colour.bg }"
                            aria-hidden="true" />
                        {{ lane.title }}
                    </div>
                </template>

                <svg v-if="canvasLayout.dependencyEdges.length"
                    class="th-sl__dep-overlay"
                    :width="axis.totalWidth"
                    :height="canvasLayout.totalHeight">
                    <defs>
                        <marker id="th-sl-dep-arrow"
                            viewBox="0 0 8 8" refX="7" refY="4"
                            markerWidth="6" markerHeight="6"
                            orient="auto-start-reverse">
                            <path d="M0,0 L8,4 L0,8 z" class="th-sl__dep-arrowhead" />
                        </marker>
                    </defs>
                    <polyline v-for="(edge, i) in canvasLayout.dependencyEdges"
                        :key="'dep-' + i"
                        :points="edge.points"
                        class="th-sl__dep-line"
                        marker-end="url(#th-sl-dep-arrow)" />
                </svg>

                <template v-for="lane in canvasLayout.lanes" :key="'cards-' + (lane.stackId ?? 'unassigned')">
                    <button v-for="card in lane.cards"
                        :key="'gcard-' + card.cardId"
                        type="button"
                        class="th-sl__gantt-bar"
                        :class="barStateClass(card)"
                        :style="{ left: card.barLeft + 'px', width: card.barWidth + 'px', top: card.top + 'px', background: lane.colour.bg, color: lane.colour.text }"
                        :title="card.title"
                        @click.stop="openPopover(card, 'lanes', $event)">
                        <span class="th-sl__gantt-bar-title">{{ card.title }}</span>
                        <AlertCircleOutline v-if="card.overdue" :size="12" aria-hidden="true" />
                    </button>
                </template>

                <div v-if="popover && popover.location === 'lanes'"
                    ref="popoverPanelLanes"
                    class="th-sl__popover-panel"
                    role="dialog"
                    :aria-label="popover.card.title"
                    tabindex="-1"
                    :style="{ top: popover.top + 'px', left: popover.left + 'px' }"
                    @click.stop
                    @keydown.esc="closePopover">
                    <button type="button"
                        class="th-sl__popover-close"
                        :aria-label="t('teamhub', 'Close')"
                        @click="closePopover">
                        <Close :size="16" />
                    </button>
                    <div class="th-sl__popover-title">{{ popover.card.title }}</div>
                    <div v-if="popover.card.startEv" class="th-sl__popover-row">
                        {{ t('teamhub', 'Start') }}: {{ formatWhen(popover.card.startEv) }}
                    </div>
                    <div v-if="popover.card.dueEv" class="th-sl__popover-row">
                        {{ t('teamhub', 'Due') }}: {{ formatWhen(popover.card.dueEv) }}
                    </div>
                    <div v-if="popover.card.boardName" class="th-sl__popover-row">
                        {{ t('teamhub', 'Board') }}: {{ popover.card.boardName }}
                    </div>
                    <div v-if="popover.card.stackName" class="th-sl__popover-row">
                        {{ t('teamhub', 'Column') }}: {{ popover.card.stackName }}
                    </div>
                    <div v-if="assigneeLabel(popover.card)" class="th-sl__popover-row">
                        {{ assigneeLabel(popover.card) }}: {{ assigneeNames(popover.card).join(', ') }}
                    </div>
                    <div v-if="popover.card.overdue" class="th-sl__popover-row th-sl__popover-row--error">
                        {{ t('teamhub', 'Overdue') }}
                    </div>
                    <div v-if="popover.card.completed" class="th-sl__popover-row th-sl__popover-row--success">
                        {{ t('teamhub', 'Completed') }}
                    </div>
                    <p v-if="popover.card.description" class="th-sl__popover-description">
                        {{ popover.card.description }}
                    </p>
                    <a v-if="popover.card.url" :href="popover.card.url" class="th-sl__popover-open">
                        {{ t('teamhub', 'Open in Deck') }}
                        <OpenInNew :size="16" />
                    </a>
                </div>
            </div>
        </div>

        <!-- View: List — fixed left column (lane name + one row per task
             subject), timeline canvas starts after that column. Same
             always-a-bar rule as Lanes, just without a title inside the bar
             (the left column already names the row). -->
        <div v-else
            class="th-sl__list"
            role="group"
            :aria-label="t('teamhub', 'Project task list')"
            :style="{ '--th-sl-bar-h': BAR_H + 'px' }">
            <div class="th-sl__list-body" @scroll.passive="closePopover">
                <div class="th-sl__list-names" :style="{ height: listLayout.totalHeight + 'px' }">
                    <template v-for="(lane, idx) in listLayout.lanes" :key="'names-' + (lane.stackId ?? ('unassigned-' + idx))">
                        <div class="th-sl__list-lane-header"
                            :class="{ 'th-sl__lane-bg--alt': idx % 2 === 1 }"
                            :style="{ top: lane.top + 'px', height: listLayout.laneLabelHeight + 'px' }">
                            <span class="th-sl__lane-swatch"
                                :style="{ background: lane.colour.bg }"
                                aria-hidden="true" />
                            {{ lane.title }}
                        </div>
                        <div v-for="card in lane.rows"
                            :key="'name-' + card.cardId"
                            class="th-sl__list-row-name"
                            :class="{ 'th-sl__lane-bg--alt': idx % 2 === 1 }"
                            :style="{ top: (card.top - BAR_INSET) + 'px', height: LANE_H + 'px' }"
                            :title="card.title">
                            {{ card.title }}
                        </div>
                    </template>
                </div>

                <div class="th-sl__list-timeline-scroll">
                    <div class="th-sl__list-timeline" :style="{ width: listLayout.totalWidth + 'px', height: listLayout.totalHeight + 'px' }">
                        <div v-for="marker in axis.dateMarkers"
                            :key="'ldm-' + marker.key"
                            class="th-sl__dmark"
                            :class="{ 'th-sl__dmark--major': marker.major }"
                            :style="{ left: marker.x + 'px' }">
                            <span class="th-sl__dmark-label">{{ marker.label }}</span>
                            <div class="th-sl__dmark-rule" />
                        </div>

                        <div v-if="axis.todayX !== null" class="th-sl__today" :style="{ left: axis.todayX + 'px' }">
                            <span class="th-sl__today-label">{{ t('teamhub', 'Today') }}</span>
                            <div class="th-sl__today-rule" />
                        </div>

                        <div v-for="marker in axis.milestoneMarkers"
                            :key="'lms-' + marker.id"
                            class="th-sl__milestone"
                            :style="{ left: marker.x + 'px' }">
                            <span class="th-sl__milestone-label" :title="marker.title">{{ marker.title }}</span>
                            <div class="th-sl__milestone-rule" />
                        </div>

                        <template v-for="(lane, idx) in listLayout.lanes" :key="'llane-' + (lane.stackId ?? ('unassigned-' + idx))">
                            <div class="th-sl__lane-bg"
                                :class="{ 'th-sl__lane-bg--alt': idx % 2 === 1 }"
                                :style="{ top: lane.top + 'px', height: lane.height + 'px' }" />
                        </template>

                        <svg v-if="listLayout.dependencyEdges.length"
                            class="th-sl__dep-overlay"
                            :width="listLayout.totalWidth"
                            :height="listLayout.totalHeight">
                            <defs>
                                <marker id="th-sl-list-dep-arrow"
                                    viewBox="0 0 8 8" refX="7" refY="4"
                                    markerWidth="6" markerHeight="6"
                                    orient="auto-start-reverse">
                                    <path d="M0,0 L8,4 L0,8 z" class="th-sl__dep-arrowhead" />
                                </marker>
                            </defs>
                            <polyline v-for="(edge, i) in listLayout.dependencyEdges"
                                :key="'ldep-' + i"
                                :points="edge.points"
                                class="th-sl__dep-line"
                                marker-end="url(#th-sl-list-dep-arrow)" />
                        </svg>

                        <template v-for="lane in listLayout.lanes" :key="'lrows-' + (lane.stackId ?? 'unassigned')">
                            <button v-for="card in lane.rows"
                                :key="'card-' + card.cardId"
                                type="button"
                                class="th-sl__bar"
                                :class="barStateClass(card)"
                                :style="{ left: card.barLeft + 'px', width: card.barWidth + 'px', top: card.top + 'px', background: lane.colour.bg, color: lane.colour.text }"
                                :title="card.title"
                                @click.stop="openPopover(card, 'list', $event)" />
                        </template>

                        <div v-if="popover && popover.location === 'list'"
                            ref="popoverPanelList"
                            class="th-sl__popover-panel"
                            role="dialog"
                            :aria-label="popover.card.title"
                            tabindex="-1"
                            :style="{ top: popover.top + 'px', left: popover.left + 'px' }"
                            @click.stop
                            @keydown.esc="closePopover">
                            <button type="button"
                                class="th-sl__popover-close"
                                :aria-label="t('teamhub', 'Close')"
                                @click="closePopover">
                                <Close :size="16" />
                            </button>
                            <div class="th-sl__popover-title">{{ popover.card.title }}</div>
                            <div v-if="popover.card.startEv" class="th-sl__popover-row">
                                {{ t('teamhub', 'Start') }}: {{ formatWhen(popover.card.startEv) }}
                            </div>
                            <div v-if="popover.card.dueEv" class="th-sl__popover-row">
                                {{ t('teamhub', 'Due') }}: {{ formatWhen(popover.card.dueEv) }}
                            </div>
                            <div v-if="popover.card.boardName" class="th-sl__popover-row">
                                {{ t('teamhub', 'Board') }}: {{ popover.card.boardName }}
                            </div>
                            <div v-if="popover.card.stackName" class="th-sl__popover-row">
                                {{ t('teamhub', 'Column') }}: {{ popover.card.stackName }}
                            </div>
                            <div v-if="assigneeLabel(popover.card)" class="th-sl__popover-row">
                                {{ assigneeLabel(popover.card) }}: {{ assigneeNames(popover.card).join(', ') }}
                            </div>
                            <div v-if="popover.card.overdue" class="th-sl__popover-row th-sl__popover-row--error">
                                {{ t('teamhub', 'Overdue') }}
                            </div>
                            <div v-if="popover.card.completed" class="th-sl__popover-row th-sl__popover-row--success">
                                {{ t('teamhub', 'Completed') }}
                            </div>
                            <p v-if="popover.card.description" class="th-sl__popover-description">
                                {{ popover.card.description }}
                            </p>
                            <a v-if="popover.card.url" :href="popover.card.url" class="th-sl__popover-open">
                                {{ t('teamhub', 'Open in Deck') }}
                                <OpenInNew :size="16" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl }                          from '@nextcloud/router'
import { mapState }                              from 'vuex'
import axios                                     from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import ChevronLeft         from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight        from 'vue-material-design-icons/ChevronRight.vue'
import CalendarToday       from 'vue-material-design-icons/CalendarToday.vue'
import AlertCircleOutline  from 'vue-material-design-icons/AlertCircleOutline.vue'
import ViewAgendaOutline   from 'vue-material-design-icons/ViewAgendaOutline.vue'
import OpenInNew           from 'vue-material-design-icons/OpenInNew.vue'
import Close                from 'vue-material-design-icons/Close.vue'
import PrinterOutline      from 'vue-material-design-icons/PrinterOutline.vue'

// Horizontal pixels per day per view mode. Module-level like TeamView's own
// timeline date helpers — needed before `this` exists in data(). 1W is
// deliberately not offered here: swimlane rows already give every card its
// own row within its lane regardless of zoom (see canvasLayout()'s per-card
// row packing), so the "force per-item rows" purpose 1W serves in the
// classic Timeline doesn't apply.
// v3.99.7 — 1W added. Wide day spacing so card labels have room in a
// 7-day span. Other values unchanged.
const PX_PER_DAY = { '1W': 90, '1M': 60, '3M': 32, '6M': 18 }
// Row pitch and bar height. Bar is 80% of the row — 4px clear above and 4px
// below, so adjacent bars have 8px of visible breathing room. These are the
// single source of truth: bar height is driven into CSS via a custom
// property (--th-sl-bar-h) on the scroll container, and row `top`s are
// computed off LANE_H in JS. Do not hardcode `height: NNpx` on
// .th-sl__gantt-bar / .th-sl__bar again — that path silently desynced the
// CSS from the JS and ate the gap in an earlier revision.
const BAR_H       = 32
const LANE_H      = 40
const BAR_INSET   = (LANE_H - BAR_H) / 2
const LANE_LABEL_H      = 22
const LANE_PAD_BOTTOM   = 12
const HEADER_GAP        = 22
const LIST_LANE_PAD_BOTTOM = 8
const MIN_BAR_W   = 24          // floor so a very-zoomed-out or same-day bar stays visible/clickable
const POPOVER_W   = 280         // matches .th-sl__popover-panel CSS width; used to keep the panel on-canvas

// Deterministic per-lane bar palette. Ordered so adjacent lanes contrast
// well (blue → teal → violet → amber → …). Assignment is by stack `order`
// when TimelineService returns it, falling back to stackId so the same
// stack always gets the same colour across refetches on installs where
// `order` is null (pre-BackfillDeckStackOrder). Each entry is a
// {bg, text} pair rather than a single hex so overdue text stays legible
// on the light backgrounds — the state-colour rule from SKILLS.md still
// wins (completed = success, overdue = error), the palette only applies
// to normal-state bars.
const LANE_PALETTE = [
    { bg: '#1e88e5', text: '#ffffff' }, // blue
    { bg: '#00897b', text: '#ffffff' }, // teal
    { bg: '#8e24aa', text: '#ffffff' }, // violet
    { bg: '#f4511e', text: '#ffffff' }, // deep orange
    { bg: '#6d4c41', text: '#ffffff' }, // brown
    { bg: '#3949ab', text: '#ffffff' }, // indigo
    { bg: '#00acc1', text: '#ffffff' }, // cyan
    { bg: '#7cb342', text: '#ffffff' }, // olive green — deliberately not the state-success green so users don't misread it as "completed"
]

function laneColour(stackOrder, stackId, idx) {
    const key = (typeof stackOrder === 'number' && stackOrder >= 0)
        ? stackOrder
        : (typeof stackId === 'number' ? stackId : idx)
    return LANE_PALETTE[Math.abs(key) % LANE_PALETTE.length]
}

function startOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1)
}
function startOfLocalDay(ts) {
    const d = new Date(ts * 1000)
    d.setHours(0, 0, 0, 0)
    return d.getTime() / 1000
}
function addPeriod(d, mode) {
    const r = new Date(d)
    if (mode === '1W') r.setDate(r.getDate() + 7)
    else if (mode === '3M') r.setMonth(r.getMonth() + 3)
    else if (mode === '6M') r.setMonth(r.getMonth() + 6)
    else r.setMonth(r.getMonth() + 1)
    return r
}
// v3.99.7 — previously subMonth(); renamed since 1W steps back by a week
// rather than a month. Callers use it for the "previous period" toolbar
// button; the step size matches the current view mode.
function subPeriod(d, mode) {
    const r = new Date(d)
    if (mode === '1W') r.setDate(r.getDate() - 7)
    else if (mode === '3M') r.setMonth(r.getMonth() - 3)
    else if (mode === '6M') r.setMonth(r.getMonth() - 6)
    else r.setMonth(r.getMonth() - 1)
    return r
}
function fmtDate(d, opts) {
    return new Intl.DateTimeFormat(document.documentElement.lang || undefined, opts).format(d)
}

/**
 * Build orthogonal (Manhattan-routed) dependency connectors: line leaves the
 * RIGHT edge of the blocker's bar, turns down/up at a midpoint corner, and
 * enters the LEFT edge of the blocked card's bar. No diagonals. Uses SVG
 * polyline points strings so the template can render them directly.
 *
 * If the blocked card starts before the blocker ends (unusual — the
 * scheduler-facing "successor precedes predecessor" case), the line
 * extends past the blocker's right edge by a small margin before turning,
 * so the corner is always visible instead of hidden inside/behind a bar.
 *
 * @param {boolean} supported  card_dependencies_supported flag from Vuex
 * @param {Array}   deckEvents timeline `deck`-source events
 * @param {Map<number, {left:{x:number,y:number}, right:{x:number,y:number}}>} cardAnchor
 * @returns {Array<{points: string}>}
 */
function buildDependencyEdges(supported, deckEvents, cardAnchor) {
    if (!supported) return []
    const CORNER_MARGIN = 12
    const edges = []
    for (const ev of deckEvents) {
        if (ev.type !== 'created') continue
        const meta = ev.meta || {}
        const blockedAnchor = cardAnchor.get(meta.cardId)
        if (!blockedAnchor) continue
        for (const blockerId of (meta.blockedByCardIds || [])) {
            const blockerAnchor = cardAnchor.get(blockerId)
            if (!blockerAnchor) continue
            const from = blockerAnchor.right   // predecessor ends
            const to   = blockedAnchor.left    // successor starts
            const cornerX = (to.x > from.x + CORNER_MARGIN * 2)
                ? (from.x + to.x) / 2
                : (from.x + CORNER_MARGIN)
            const pts = [
                [from.x, from.y],
                [cornerX, from.y],
                [cornerX, to.y],
                [to.x,   to.y],
            ].map(([x, y]) => x + ',' + y).join(' ')
            edges.push({ points: pts })
        }
    }
    return edges
}

/**
 * Derive one card's schedule + display fields from its raw Deck events.
 * Shared by both views so the bar semantics never drift between them.
 *
 * Always produces a bar — never a bare dot. A real start→due span when both
 * resolve (NC 34 / Deck 1.16+ — deck_cards.startdate); otherwise a bar
 * exactly one calendar day wide, anchored on whichever date is available
 * (due preferred — "when it's scheduled" — then start/created/completed),
 * per Justin's call not to invent a start where Deck has none.
 *
 * @param {number} cardId
 * @param {Array}  evs  all this card's 'deck' events in the current window
 * @param {(ts: number) => number} xOf
 */
function buildCard(cardId, evs, xOf) {
    const startEv = evs.find(e => e.type === 'start')
    const dueEv   = evs.find(e => e.type === 'due')
    const otherEv = evs.find(e => e.type === 'completed') || evs.find(e => e.type === 'created') || evs[0]

    const startTs = startEv ? new Date(startEv.date).getTime() / 1000 : null
    const dueTs   = dueEv   ? new Date(dueEv.date).getTime() / 1000   : null

    let barLeft, barWidth
    if (startTs !== null && dueTs !== null && dueTs > startTs) {
        barLeft  = xOf(startTs)
        barWidth = Math.max(xOf(dueTs) - barLeft, MIN_BAR_W)
    } else {
        const anchorTs  = dueTs ?? startTs ?? (new Date(otherEv.date).getTime() / 1000)
        const dayStart  = startOfLocalDay(anchorTs)
        barLeft  = xOf(dayStart)
        barWidth = Math.max(xOf(dayStart + 86400) - barLeft, MIN_BAR_W)
    }

    const anyMeta = otherEv?.meta || dueEv?.meta || startEv?.meta || {}

    return {
        cardId,
        title: (evs[0] && evs[0].title) || '',
        startEv: startEv || null,
        dueEv: dueEv || null,
        barLeft, barWidth,
        overdue: !!anyMeta.overdue,
        completed: !!anyMeta.completed,
        url: otherEv?.url || dueEv?.url || startEv?.url || null,
        boardName: anyMeta.boardName || '',
        stackName: anyMeta.stackName || '',
        description: anyMeta.description || '',
        meta: anyMeta,
        row: 0,
        top: 0,
    }
}

export default {
    name: 'ProjectSwimlaneView',

    components: {
        NcButton, NcLoadingIcon, NcEmptyContent,
        ChevronLeft, ChevronRight, CalendarToday, AlertCircleOutline, ViewAgendaOutline, OpenInNew, Close, PrinterOutline,
    },

    data() {
        return {
            loading: true,
            error: null,
            viewMode: '1M',
            viewStyle: 'lanes', // 'lanes' | 'list'
            windowStart: startOfMonth(new Date()),
            rawEvents: [],
            rawStacks: [],
            popover: null, // { card, left, top } | null
            BAR_INSET,
            BAR_H,
            LANE_H,
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'timelineConfig']),

        cardDependenciesSupported() {
            return !!this.timelineConfig?.card_dependencies_supported
        },

        viewStyleOptions() {
            return [
                { value: 'lanes', label: t('teamhub', 'Lanes') },
                { value: 'list',  label: t('teamhub', 'List') },
            ]
        },

        printButtonLabel() {
            return t('teamhub', 'Print timeline')
        },
        viewModeOptions() {
            return [
                { value: '1W', label: t('teamhub', '1 week') },
                { value: '1M', label: t('teamhub', '1 month') },
                { value: '3M', label: t('teamhub', '3 months') },
                { value: '6M', label: t('teamhub', '6 months') },
            ]
        },

        windowRange() {
            return {
                from: Math.floor(this.windowStart.getTime() / 1000),
                to:   Math.floor(addPeriod(this.windowStart, this.viewMode).getTime() / 1000),
            }
        },

        periodLabel() {
            if (this.viewMode === '1W') {
                // v3.99.7 — "3 Aug – 9 Aug 2026" format for weeks.
                const end = new Date(addPeriod(this.windowStart, this.viewMode).getTime() - 1)
                return fmtDate(this.windowStart, { day: 'numeric', month: 'short' })
                    + ' – ' + fmtDate(end, { day: 'numeric', month: 'short', year: 'numeric' })
            }
            if (this.viewMode === '1M') {
                return fmtDate(this.windowStart, { month: 'long', year: 'numeric' })
            }
            const end = new Date(addPeriod(this.windowStart, this.viewMode).getTime() - 1)
            return fmtDate(this.windowStart, { month: 'short', year: 'numeric' })
                + ' – ' + fmtDate(end, { month: 'short', year: 'numeric' })
        },

        deckEvents() {
            return this.rawEvents.filter(ev => ev.source === 'deck')
        },

        milestoneEvents() {
            return this.rawEvents.filter(ev => ev.source === 'milestone')
        },

        /**
         * Shared time-axis geometry — date markers, the Today line, milestone
         * marker positions and the x() projector — used by both the Lanes
         * canvas and the List timeline pane so the two views always agree on
         * "where is this date horizontally."
         */
        axis() {
            const { from, to } = this.windowRange
            const totalS   = Math.max(1, to - from)
            const days     = totalS / 86400
            const pxPerDay = PX_PER_DAY[this.viewMode]
            const totalWidth = Math.max(900, days * pxPerDay)
            const pxPerS   = totalWidth / totalS
            const xOf = ts => Math.max(0, Math.min(totalWidth, (ts - from) * pxPerS))

            const milestoneMarkers = this.milestoneEvents.map(ev => ({
                id: ev.id, title: ev.title, x: xOf(new Date(ev.date).getTime() / 1000),
            }))

            const dateMarkers = []
            const DAY = 86400
            // v3.99.7 — 1W steps every day (7 markers for a 7-day span).
            const markerDays = (this.viewMode === '1M' || this.viewMode === '1W') ? 1 : 7
            const d = new Date(this.windowStart)
            while (d.getTime() / 1000 < to + DAY) {
                const ts = d.getTime() / 1000
                if (ts < from) { d.setDate(d.getDate() + markerDays); continue }
                const isMonthStart = d.getDate() === 1
                const isWeekStart  = d.getDay() === 1
                const isMajor = isMonthStart || (markerDays >= 7 && isWeekStart)
                let label
                if (this.viewMode === '1W') {
                    // Day + weekday abbreviation reads well in a 7-day span.
                    label = fmtDate(d, { weekday: 'short', day: 'numeric' })
                } else if (this.viewMode === '1M') {
                    label = fmtDate(d, isMajor ? { month: 'short', day: 'numeric' } : { day: 'numeric' })
                } else {
                    label = fmtDate(d, { month: 'short', day: 'numeric' })
                }
                dateMarkers.push({ x: xOf(ts), label, major: isMajor, key: ts })
                d.setDate(d.getDate() + markerDays)
            }

            const nowTs = Date.now() / 1000
            const todayX = (nowTs >= from && nowTs <= to) ? xOf(nowTs) : null

            return { xOf, totalWidth, dateMarkers, todayX, milestoneMarkers }
        },

        /**
         * View: Lanes. One card per row inside each lane — no collision
         * packing (per Justin: "every event should have its own row within
         * the swimming lane"). Rows sort by bar start, then by cardId so
         * layout is stable across refetches. Cross-lane dependency arrows
         * reuse the same anchor-map + straight-line SVG approach as the
         * classic Timeline's drawCardDependencyLinks().
         */
        canvasLayout() {
            const { xOf } = this.axis

            const laneByStackId = new Map()
            const lanes = this.rawStacks.map((s, i) => ({
                stackId: s.stackId, title: s.stackTitle, cards: [], top: 0, height: 0,
                colour: laneColour(s.order, s.stackId, i),
            }))
            lanes.forEach(l => laneByStackId.set(l.stackId, l))

            const cardsByLane = new Map() // lane object -> Map(cardId -> evs[])
            let unassigned = null
            for (const ev of this.deckEvents) {
                const meta = ev.meta || {}
                const cardId = meta.cardId
                if (cardId == null) continue
                let lane = laneByStackId.get(meta.stackId)
                if (!lane) {
                    if (!unassigned) {
                        unassigned = {
                            stackId: null, title: t('teamhub', 'Unassigned'), cards: [], top: 0, height: 0,
                            colour: { bg: 'var(--color-text-maxcontrast)', text: 'var(--color-main-background)' },
                        }
                    }
                    lane = unassigned
                }
                if (!cardsByLane.has(lane)) cardsByLane.set(lane, new Map())
                const cardMap = cardsByLane.get(lane)
                if (!cardMap.has(cardId)) cardMap.set(cardId, [])
                cardMap.get(cardId).push(ev)
            }
            if (unassigned && cardsByLane.get(unassigned)?.size) lanes.push(unassigned)

            const cardAnchor = new Map()
            let cumulativeY = HEADER_GAP
            for (const lane of lanes) {
                const cardMap = cardsByLane.get(lane) || new Map()
                const cards = [...cardMap.entries()].map(([cardId, evs]) => buildCard(cardId, evs, xOf))

                cards.sort((a, b) => (a.barLeft - b.barLeft) || (a.cardId - b.cardId))
                cards.forEach((card, i) => { card.row = i })

                lane.height = LANE_LABEL_H + Math.max(1, cards.length) * LANE_H + LANE_PAD_BOTTOM
                lane.top    = cumulativeY
                cumulativeY += lane.height

                cards.forEach(card => {
                    card.top = lane.top + LANE_LABEL_H + card.row * LANE_H + BAR_INSET
                    const cy = card.top + BAR_H / 2
                    cardAnchor.set(card.cardId, {
                        left:  { x: card.barLeft, y: cy },
                        right: { x: card.barLeft + card.barWidth, y: cy },
                    })
                })
                lane.cards = cards
            }

            const totalHeight = cumulativeY + 6

            const dependencyEdges = buildDependencyEdges(this.cardDependenciesSupported, this.deckEvents, cardAnchor)

            return { lanes, dependencyEdges, totalHeight }
        },

        /**
         * View: List. One row per distinct card (no collision packing — the
         * whole point is a stable, always-visible task list). The template
         * renders the name column separately from the timeline pane so they
         * scroll independently horizontally while staying in vertical
         * lock-step via identical row tops/heights.
         */
        listLayout() {
            const { xOf, totalWidth, dateMarkers, todayX, milestoneMarkers } = this.axis

            const laneByStackId = new Map()
            const lanes = this.rawStacks.map((s, i) => ({
                stackId: s.stackId, title: s.stackTitle, rows: [], top: 0, height: 0,
                colour: laneColour(s.order, s.stackId, i),
            }))
            lanes.forEach(l => laneByStackId.set(l.stackId, l))

            const cardsByLane = new Map() // lane object -> Map(cardId -> evs[])
            let unassigned = null
            for (const ev of this.deckEvents) {
                const meta = ev.meta || {}
                const cardId = meta.cardId
                if (cardId == null) continue
                let lane = laneByStackId.get(meta.stackId)
                if (!lane) {
                    if (!unassigned) {
                        unassigned = {
                            stackId: null, title: t('teamhub', 'Unassigned'), rows: [], top: 0, height: 0,
                            colour: { bg: 'var(--color-text-maxcontrast)', text: 'var(--color-main-background)' },
                        }
                    }
                    lane = unassigned
                }
                if (!cardsByLane.has(lane)) cardsByLane.set(lane, new Map())
                const cardMap = cardsByLane.get(lane)
                if (!cardMap.has(cardId)) cardMap.set(cardId, [])
                cardMap.get(cardId).push(ev)
            }
            if (unassigned && cardsByLane.get(unassigned)?.size) lanes.push(unassigned)

            const cardAnchor = new Map()
            let cumulativeY = HEADER_GAP

            for (const lane of lanes) {
                const cardMap = cardsByLane.get(lane) || new Map()
                const cards = [...cardMap.entries()].map(([cardId, evs]) => buildCard(cardId, evs, xOf))

                cards.sort((a, b) => a.barLeft - b.barLeft)

                lane.rows   = cards
                lane.height = LANE_LABEL_H + Math.max(1, cards.length) * LANE_H + LIST_LANE_PAD_BOTTOM
                lane.top    = cumulativeY
                cumulativeY += lane.height

                cards.forEach((card, i) => {
                    card.top = lane.top + LANE_LABEL_H + i * LANE_H + BAR_INSET
                    const cy = card.top + BAR_H / 2
                    cardAnchor.set(card.cardId, {
                        left:  { x: card.barLeft, y: cy },
                        right: { x: card.barLeft + card.barWidth, y: cy },
                    })
                })
            }

            const totalHeight = cumulativeY + 6

            const dependencyEdges = buildDependencyEdges(this.cardDependenciesSupported, this.deckEvents, cardAnchor)

            return {
                lanes, dependencyEdges, totalWidth, totalHeight, dateMarkers, todayX, milestoneMarkers,
                laneLabelHeight: LANE_LABEL_H,
            }
        },
    },

    watch: {
        currentTeamId(newId) {
            if (!newId) return
            this.closePopover()
            this.fetchTimeline()
        },
    },

    mounted() {
        if (this.currentTeamId) this.fetchTimeline()
        // Refetch whenever the browser tab regains visibility — covers the
        // "I edited a Deck card in another tab, came back here, the bar
        // hasn't moved" scenario without needing an explicit reload. Also
        // fires on window focus for the same reason (some browsers only
        // fire one or the other depending on how focus was lost).
        document.addEventListener('visibilitychange', this.onVisibilityChange)
        window.addEventListener('focus', this.onWindowFocus)
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onDocumentClick)
        document.removeEventListener('visibilitychange', this.onVisibilityChange)
        window.removeEventListener('focus', this.onWindowFocus)
    },

    methods: {
        t, n,

        async fetchTimeline() {
            if (!this.currentTeamId) return
            this.loading = true
            this.error   = null
            const { from, to } = this.windowRange
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/timeline`),
                    { params: { from, to } },
                )
                this.rawEvents = Array.isArray(data?.events) ? data.events : []
                this.rawStacks = Array.isArray(data?.stacks) ? data.stacks : []
            } catch (err) {
                console.error('[TeamHub][ProjectSwimlaneView] fetchTimeline error:', err)
                this.error = err?.response?.data?.error || err.message
            } finally {
                this.loading = false
            }
        },

        goPrev() {
            // v3.99.7 — step size matches the current view mode.
            this.windowStart = subPeriod(this.windowStart, this.viewMode)
            this.fetchTimeline()
        },
        goNext() {
            this.windowStart = addPeriod(this.windowStart, this.viewMode)
            this.fetchTimeline()
        },
        goToday() {
            // v3.99.7 — for 1W, anchor to the start of the current week
            // (Monday) rather than the start of the month, so "today"
            // sits inside the visible window rather than off to the right.
            if (this.viewMode === '1W') {
                const now = new Date()
                now.setHours(0, 0, 0, 0)
                // Monday as start-of-week; Sunday = 0 → treat as day 7.
                const dow = now.getDay() === 0 ? 7 : now.getDay()
                now.setDate(now.getDate() - (dow - 1))
                this.windowStart = now
            } else {
                this.windowStart = startOfMonth(new Date())
            }
            this.fetchTimeline()
        },
        setViewMode(mode) {
            if (this.viewMode === mode) return
            this.viewMode = mode
            // Reanchor when switching to/from 1W so "today" stays visible.
            this.goToday()
        },

        /**
         * v3.99.7 — print the swimlane view. Uses the browser's print
         * dialog; users pick "Save as PDF" for a file. Matches the Print
         * button on the classic Timeline tab.
         */
        printSwimlane() {
            window.print()
        },

        /**
         * The popover is rendered INSIDE the scrolling canvas (not
         * Teleport'd) so it sits in the same absolute-positioned coordinate
         * space as the bar. That guarantees it appears next to the clicked
         * bar and scrolls with it — none of the "pinned to the top of the
         * page, disconnected from the bar" behaviour we saw when this was
         * routed through NcPopover / floating-vue.
         *
         * We clamp against the canvas's right edge (so the panel doesn't
         * spill off into invisible scroll area on the far right of the
         * viewport), and prefer below-the-bar; if that overflows the
         * canvas's total height it flips above.
         */
        openPopover(card, location, event) {
            if (this.popover && this.popover.card === card) {
                this.closePopover()
                return
            }

            const canvasW = (location === 'lanes')
                ? this.axis.totalWidth
                : this.listLayout.totalWidth
            const canvasH = (location === 'lanes')
                ? this.canvasLayout.totalHeight
                : this.listLayout.totalHeight
            const PANEL_H_ESTIMATE = 240
            const GAP = 6

            let left = card.barLeft
            if (left + POPOVER_W > canvasW) {
                left = Math.max(0, canvasW - POPOVER_W)
            }

            let top = card.top + BAR_H + GAP
            if (top + PANEL_H_ESTIMATE > canvasH) {
                top = Math.max(0, card.top - PANEL_H_ESTIMATE - GAP)
            }

            this._popoverTrigger = event.currentTarget
            this.popover = { card, location, left, top }
            this.$nextTick(() => {
                const panel = this.$refs.popoverPanelLanes || this.$refs.popoverPanelList
                panel?.focus()
                document.addEventListener('click', this.onDocumentClick)
            })
        },

        closePopover() {
            if (!this.popover) return
            this.popover = null
            document.removeEventListener('click', this.onDocumentClick)
            const trigger = this._popoverTrigger
            this._popoverTrigger = null
            trigger?.focus()
        },

        onDocumentClick(event) {
            const panel = this.$refs.popoverPanelLanes || this.$refs.popoverPanelList
            if (panel && !panel.contains(event.target)) {
                this.closePopover()
            }
        },

        onVisibilityChange() {
            if (document.visibilityState === 'visible' && this.currentTeamId) {
                this.fetchTimeline()
            }
        },
        onWindowFocus() {
            if (this.currentTeamId) this.fetchTimeline()
        },

        barStateClass(card) {
            if (card.completed) return 'th-sl__bar--completed'
            if (card.overdue) return 'th-sl__bar--overdue'
            return ''
        },

        formatWhen(ev) {
            return fmtDate(new Date(ev.date), { day: 'numeric', month: 'short', year: 'numeric' })
        },

        assigneeNames(ev) {
            const meta = ev.meta || {}
            if (meta.assigneeNames && meta.assigneeNames.length) return meta.assigneeNames
            if (meta.assignees && meta.assignees.length) return meta.assignees
            return []
        },

        assigneeLabel(ev) {
            const names = this.assigneeNames(ev)
            if (!names.length) return ''
            return names.length > 1 ? t('teamhub', 'Assignees') : t('teamhub', 'Assigned to')
        },
    },
}
</script>

<style scoped>
.th-sl {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 320px;
}

.th-sl__toolbar {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.th-sl__period {
    margin-left: 8px;
    font-weight: bold;
}

.th-sl__spacer {
    flex: 1;
}

.th-sl__toolbar-divider {
    width: 1px;
    height: 20px;
    background: var(--color-border);
    margin: 0 4px;
}

/* v3.99.7 — view-range dropdown. */
.th-sl__vm-label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}
.th-sl__vm-select {
    min-width: 110px;
    padding: 4px 8px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
}
.th-sl__vm-select:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-sl__status {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 0;
}

.th-sl__scroll {
    flex: 1;
    overflow: auto;
    position: relative;
}

.th-sl__canvas {
    position: relative;
}

.th-sl__dmark, .th-sl__today, .th-sl__milestone {
    position: absolute;
    top: 0;
    bottom: 0;
}
/* v3.99.7 — today + milestone lines must render above the lane
 * backgrounds (which paint with --color-background-hover / --dark). Row
 * backgrounds are painted inside the lane elements later in the DOM, so
 * without an explicit z-index the vertical rules sat below them and
 * became invisible. Popovers are at z-index: 10, so 5 keeps them
 * comfortably in between. */
.th-sl__today, .th-sl__milestone {
    z-index: 5;
    pointer-events: none;
}

.th-sl__dmark-label, .th-sl__today-label, .th-sl__milestone-label {
    position: absolute;
    top: 2px;
    left: 4px;
    font-size: var(--th-font-micro);
    white-space: nowrap;
    color: var(--color-text-maxcontrast);
}

.th-sl__dmark-rule {
    position: absolute;
    top: 18px;
    bottom: 0;
    left: 0;
    width: 1px;
    background: var(--color-border);
}

.th-sl__dmark--major .th-sl__dmark-label {
    font-weight: bold;
    color: var(--color-main-text);
}
.th-sl__dmark--major .th-sl__dmark-rule {
    background: var(--color-border-dark);
}

.th-sl__today-label {
    color: var(--color-primary-element-text);
    background: var(--color-primary-element);
    padding: 0 4px;
    border-radius: 4px;
}
.th-sl__today-rule {
    position: absolute;
    top: 18px;
    bottom: 0;
    left: 0;
    width: 2px;
    background: var(--color-primary-element);
}

.th-sl__milestone-label {
    color: var(--color-error-text);
    background: var(--color-error);
    padding: 0 4px;
    border-radius: 4px;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.th-sl__milestone-rule {
    position: absolute;
    top: 18px;
    bottom: 0;
    left: 0;
    width: 2px;
    /* v3.99.8 — Justin: match the vertical rule to the milestone label's
       text color instead of the soft --color-error pink, so the line
       reads as clearly red at any zoom. */
    background: var(--color-error-text);
}

.th-sl__lane-bg {
    position: absolute;
    left: 0;
    right: 0;
    background: var(--color-background-hover);
}
.th-sl__lane-bg--alt {
    background: var(--color-background-dark);
}

.th-sl__lane-label {
    position: absolute;
    left: 4px;
    font-size: var(--th-font-meta);
    font-weight: bold;
    color: var(--color-text-maxcontrast);
    z-index: 1;
}

.th-sl__dep-overlay {
    position: absolute;
    top: 0;
    left: 0;
    pointer-events: none;
}
/* v3.100.16: dropped defensive #555 fallbacks on --color-text-maxcontrast
   (NC always defines this token). */
.th-sl__dep-line {
    stroke: var(--color-text-maxcontrast);
    stroke-width: 1.5;
    fill: none;               /* polyline defaults to fill:black, would draw a solid polygon */
    stroke-linejoin: round;   /* smooths the two 90° corners */
}
.th-sl__dep-arrowhead {
    fill: var(--color-text-maxcontrast);
}

/* Lanes view — the subject lives inside the bar itself. */
.th-sl__gantt-bar {
    position: absolute;
    height: var(--th-sl-bar-h, 32px);
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 6px;
    border: none;
    border-radius: 4px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    font-size: var(--th-font-meta);
    cursor: pointer;
    overflow: hidden;
}
.th-sl__gantt-bar:focus-visible {
    outline: 2px solid var(--color-main-text);
    outline-offset: 1px;
}
.th-sl__gantt-bar-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* List view — plain bar, no label (the left name column already has it). */
.th-sl__bar {
    position: absolute;
    height: var(--th-sl-bar-h, 32px);
    border: none;
    border-radius: 4px;
    background: var(--color-primary-element);
    cursor: pointer;
    padding: 0;
}
.th-sl__bar:focus-visible {
    outline: 2px solid var(--color-main-text);
    outline-offset: 1px;
}

/* State colours override the per-lane palette applied via inline style —
   completed cards read as "done" (success green) and overdue cards as
   error red regardless of which lane they came from. !important is
   necessary because the lane palette lives on inline :style, which
   would otherwise out-specify a normal class rule. */
.th-sl__gantt-bar--completed, .th-sl__bar--completed {
    background: var(--color-success) !important;
    color: var(--color-success-text) !important;
}
.th-sl__gantt-bar--overdue, .th-sl__bar--overdue {
    background: var(--color-error) !important;
    color: var(--color-error-text) !important;
}

.th-sl__lane-swatch {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    margin-right: 6px;
    vertical-align: middle;
    flex-shrink: 0;
}

.th-sl__popover-panel {
    position: absolute;
    z-index: 10;
    width: 280px;
    padding: 12px;
    border-radius: 8px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2);
}
.th-sl__popover-close {
    position: absolute;
    top: 6px;
    right: 6px;
    border: none;
    background: transparent;
    cursor: pointer;
    color: var(--color-text-maxcontrast);
    padding: 4px;
    border-radius: 50%;
}
.th-sl__popover-close:hover, .th-sl__popover-close:focus-visible {
    background: var(--color-background-hover);
}
.th-sl__popover-title {
    font-weight: bold;
    margin: 0 24px 6px 0;
}
.th-sl__popover-row {
    font-size: 13px;
    margin-bottom: 2px;
}
.th-sl__popover-row--success {
    color: var(--color-success-text);
    background: var(--color-success);
    display: inline-block;
    padding: 0 6px;
    border-radius: 8px;
}
.th-sl__popover-row--error {
    color: var(--color-error-text);
    background: var(--color-error);
    display: inline-block;
    padding: 0 6px;
    border-radius: 8px;
}
.th-sl__popover-description {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin: 8px 0 0;
    white-space: pre-wrap;
}
.th-sl__popover-open {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 8px;
    font-weight: bold;
}

/* ── View: List ──────────────────────────────────────────────────────── */
.th-sl__list {
    flex: 1;
    display: flex;
    min-height: 0;
}
.th-sl__list-body {
    flex: 1;
    display: flex;
    overflow-y: auto;
    min-height: 0;
}
.th-sl__list-names {
    position: relative;
    flex: 0 0 200px;
    width: 200px;
    border-right: 1px solid var(--color-border);
}
/* v3.99.8 — Justin: "the background doesn't really look great on the
   left bar with the lane names and subjects. Can you remove the
   background color for now?" The full-width alt shading in the timeline
   canvas still needs the same --lane-bg-- classes, so we can't strip
   them there; we scope the transparency to .th-sl__list-names (the left
   fixed column) via an override further down. */
.th-sl__list-lane-header {
    position: absolute;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    padding: 0 8px;
    font-size: var(--th-font-meta);
    font-weight: bold;
    color: var(--color-text-maxcontrast);
}
.th-sl__list-row-name {
    position: absolute;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    padding: 0 8px 0 16px;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.th-sl__list-names .th-sl__lane-bg--alt {
    background: transparent;
}
.th-sl__list-timeline-scroll {
    flex: 1;
    overflow-x: auto;
    min-width: 0;
}
.th-sl__list-timeline {
    position: relative;
}
</style>
