<template>
    <div class="th-decisions-list">
        <!-- Loading state — shared state row -->
        <div v-if="loading" class="th-widget__state">
            <span class="th-widget__spinner" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Loading decisions…') }}</span>
        </div>

        <!-- Error state — shared state row -->
        <div v-else-if="error" class="th-widget__state th-widget__state--error" role="alert">
            <AlertCircleOutlineIcon :size="18" aria-hidden="true" />
            <span class="th-widget__state-text">{{ t('teamhub', 'Could not load decisions') }}</span>
        </div>

        <!-- Empty state — shared state row -->
        <div v-else-if="!decisions.length" class="th-widget__state th-widget__state--empty">
            <GavelIcon :size="22" aria-hidden="true" />
            <span class="th-widget__state-text">{{ emptyLabel }}</span>
        </div>

        <!-- Decision rows -->
        <ul v-else class="th-widget__rows th-decisions-list__rows" :aria-label="t('teamhub', 'Decisions')">
            <li
                v-for="d in decisions"
                :key="d.id"
                class="th-decisions-list__row"
                :class="[`th-decisions-list__row--${d.status}`]">

                <!-- Status accent bar -->
                <span class="th-decisions-list__accent" aria-hidden="true" />

                <!-- Clickable row body — navigates to message stream -->
                <button
                    class="th-decisions-list__body"
                    :aria-label="t('teamhub', 'View decision: {question}', { question: d.question || t('teamhub', 'Untitled decision') })"
                    @click="navigateToMessage(d)">

                    <!-- Header line: question text + status pill -->
                    <div class="th-decisions-list__header">
                        <span class="th-decisions-list__question">{{ d.question || t('teamhub', 'Untitled decision') }}</span>
                        <span
                            class="th-widget__pill"
                            :class="`th-widget__pill--${statusToPillVariant(d.status)}`">
                            {{ statusLabel(d.status) }}
                        </span>
                    </div>

                    <!-- Meta line: category · proposer · date.
                         Impact and Level are not surfaced here — they live in
                         the detail panel where they're scannable. -->
                    <div class="th-decisions-list__meta">
                        <span v-if="d.category" class="th-decisions-list__meta-item">
                            <!-- TRANSLATORS: label preceding the category name on a decision row, e.g. "Category Beheer" -->
                            <span class="th-decisions-list__meta-label">{{ t('teamhub', 'Category') }}</span>
                            {{ d.category }}
                        </span>
                        <span class="th-decisions-list__meta-item">
                            <!-- TRANSLATORS: short attribution line on a decision row, e.g. "by alice@example.com" -->
                            {{ t('teamhub', 'by {name}', { name: d.proposedBy }) }}
                        </span>
                        <span class="th-decisions-list__date" :title="fullDate(d.createdAt)">{{ relativeDate(d.createdAt) }}</span>
                    </div>
                </button>

                <!-- Approver actions — Session I.
                     Outside the body button so they don't trigger navigation. -->
                <div
                    v-if="showApproverActions"
                    class="th-decisions-list__actions">
                    <button
                        type="button"
                        class="th-decisions-list__action th-decisions-list__action--review"
                        :disabled="actingDecisionId === d.id"
                        :aria-label="t('teamhub', 'Review this decision')"
                        :title="t('teamhub', 'Review')"
                        @click.stop="$emit('review-decision', d)">
                        <GavelIcon :size="14" aria-hidden="true" />
                        <!-- TRANSLATORS: button on a decision row to open the approval modal -->
                        <span class="th-decisions-list__action-label">{{ t('teamhub', 'Decision') }}</span>
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapState, mapMutations }                from 'vuex'
import GavelIcon               from 'vue-material-design-icons/Gavel.vue'
import AlertCircleOutlineIcon  from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckBoldIcon           from 'vue-material-design-icons/CheckBold.vue'
import CloseIcon               from 'vue-material-design-icons/Close.vue'

export default {
    name: 'DecisionsList',

    components: {
        GavelIcon,
        AlertCircleOutlineIcon,
        CheckBoldIcon,
        CloseIcon,
    },

    emits: ['review-decision'],

    props: {
        /** Array of serialised decision objects from the API */
        decisions: { type: Array,   default: () => [] },
        loading:   { type: Boolean, default: false },
        error:     { type: Boolean, default: false },
        /** Label shown when there are no decisions to display */
        emptyLabel: {
            type: String,
            default() { return t('teamhub', 'No decisions yet') },
        },
        /**
         * Session I: when true, each row gets an inline Approve / Deny
         * button group. Clicking them emits 'approve-decision' or
         * 'deny-decision' with the decision object; parent owns the
         * actual API call and any modals.
         */
        showApproverActions: { type: Boolean, default: false },
        /**
         * Id of the decision currently being acted on; that row's
         * buttons are disabled while in-flight. -1 = none.
         */
        actingDecisionId: { type: [Number, String, null], default: null },
    },

    computed: {
        ...mapState(['decisionsConfig']),
        decisionsLevelEnabled() {
            return !!this.decisionsConfig?.decisions_level_enabled
        },
    },

    methods: {
        t,
        n,
        ...mapMutations(['SET_VIEW', 'SET_DECISIONS_TARGET']),

        /**
         * Navigate to the Decisions tab, targeting this decision's row.
         * SET_DECISIONS_TARGET stores the messageId; TeamDecisionsView watches
         * it and scrolls + expands the matching row on arrival.
         */
        navigateToMessage(decision) {
            this.SET_DECISIONS_TARGET(decision.messageId)
            this.SET_VIEW('decisions')
        },

        impactLabel(impact) {
            const map = {
                // TRANSLATORS: impact level label on a decision meta line
                low:    t('teamhub', 'Low'),
                // TRANSLATORS: impact level label on a decision meta line
                medium: t('teamhub', 'Medium'),
                // TRANSLATORS: impact level label on a decision meta line
                high:   t('teamhub', 'High'),
            }
            return map[impact] || impact
        },

        levelLabel(level) {
            const map = {
                operational: t('teamhub', 'Operational'),
                tactical:    t('teamhub', 'Tactical'),
                strategic:   t('teamhub', 'Strategic'),
            }
            return map[level] || level
        },

        statusLabel(status) {
            const map = {
                // TRANSLATORS: status pill — decision is open for discussion
                open:       t('teamhub', 'Open'),
                // TRANSLATORS: status pill — proposer finalized; now awaits an approver
                finalized:  t('teamhub', 'Awaits approval'),
                // TRANSLATORS: status pill — approver accepted the finalized proposal
                approved:   t('teamhub', 'Approved'),
                // TRANSLATORS: status pill — approver rejected the finalized proposal
                denied:     t('teamhub', 'Denied'),
                // TRANSLATORS: status pill — proposer cancelled before finalize
                withdrawn:  t('teamhub', 'Withdrawn'),
                // Legacy values (kept for any stale rows still in DB)
                proposed:   t('teamhub', 'Open'),
                decided:    t('teamhub', 'Approved'),
            }
            return map[status] || status
        },

        /**
         * Map a decision status to the shared pill colour variant.
         * Keeps the row pill colour scheme aligned with the detail-panel
         * link pills (one colour vocabulary across the app).
         */
        statusToPillVariant(status) {
            const map = {
                open:       'primary',
                proposed:   'primary',
                finalized:  'warning',
                approved:   'success',
                decided:    'success',
                denied:     'error',
                withdrawn:  'neutral',
            }
            return map[status] || 'neutral'
        },

        relativeDate(ts) {
            if (!ts) return ''
            const ms = typeof ts === 'number' ? ts * 1000 : Date.parse(ts)
            if (isNaN(ms)) return ''
            const diff = Date.now() - ms
            const days = Math.floor(diff / 86400000)
            if (days === 0) return t('teamhub', 'Today')
            if (days === 1) return t('teamhub', 'Yesterday')
            if (days < 7) return n('teamhub', '{n} day ago', '{n} days ago', days, { n: days })
            return new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        },

        fullDate(ts) {
            if (!ts) return ''
            const ms = typeof ts === 'number' ? ts * 1000 : Date.parse(ts)
            if (isNaN(ms)) return ''
            return new Date(ms).toLocaleString()
        },
    },
}
</script>

<style scoped>
/* ──────────────────────────────────────────────────────────────────────
 * DecisionsList — widget-specific styling only.
 *
 * Loading/error/empty rows and the status/level pills now come from
 * src/styles/widget-tokens.css (.th-widget__state, .th-widget__pill).
 * What remains here is the row layout that's specific to a decision:
 * the status accent bar, the two-line body, the impact text colours,
 * and the approver action buttons.
 * ────────────────────────────────────────────────────────────────────── */

/* ── Container ── */
.th-decisions-list {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

/* ── Row layout — accent bar + body button + (optional) actions ── */
.th-decisions-list__row {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--color-border);
}

.th-decisions-list__row:last-child {
    border-bottom: none;
}

/* ── Status accent bar (widget-specific — no other widget has this) ── */
.th-decisions-list__accent {
    width: 3px;
    flex-shrink: 0;
    background: var(--color-border);
}

.th-decisions-list__row--open       .th-decisions-list__accent { background: var(--color-primary-element); }
.th-decisions-list__row--finalized  .th-decisions-list__accent { background: var(--th-color-warning); }
.th-decisions-list__row--approved   .th-decisions-list__accent { background: var(--th-color-success); }
.th-decisions-list__row--denied     .th-decisions-list__accent { background: var(--th-color-error); }
.th-decisions-list__row--withdrawn  .th-decisions-list__accent { background: var(--th-color-neutral); }
/* Legacy fallbacks for stale rows */
.th-decisions-list__row--proposed   .th-decisions-list__accent { background: var(--color-primary-element); }
.th-decisions-list__row--decided    .th-decisions-list__accent { background: var(--th-color-success); }

/* ── Row body — clickable two-line layout ── */
.th-decisions-list__body {
    flex: 1;
    min-width: 0;
    padding: 8px 10px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
}

.th-decisions-list__body:hover {
    background: var(--color-background-hover);
}

.th-decisions-list__body:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

/* ── Header line: question + status pill ── */
.th-decisions-list__header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 3px;
}

.th-decisions-list__question {
    flex: 1;
    min-width: 0;
    /* Bigger size, medium weight — size carries the hierarchy, not bold */
    font-size: var(--th-widget-row-primary-size);
    font-weight: var(--th-widget-row-primary-weight);
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Meta line: category · proposer · date.
   Impact and level are no longer surfaced here — they're in the detail
   panel. Category is now plain text with a small label prefix rather
   than a pill, keeping the row visually quiet. ── */
.th-decisions-list__meta {
    display: flex;
    align-items: baseline;
    gap: 8px;
    /* Smaller than the primary line; weight stays regular */
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-meta-weight);
    color: var(--th-widget-meta-color);
    flex-wrap: wrap;
}

.th-decisions-list__meta-item {
    display: inline-flex;
    align-items: baseline;
    gap: 4px;
    color: var(--color-main-text);
}

/* Small uppercase label preceding a value, e.g. "CATEGORY Beheer" */
.th-decisions-list__meta-label {
    color: var(--th-widget-meta-color);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.04em;
}

/* Date pushed to the right edge of the meta line */
.th-decisions-list__date {
    margin-left: auto;
    white-space: nowrap;
    color: var(--th-widget-meta-color);
}

/* ── Approver actions (Session I) ── */
.th-decisions-list__actions {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px 4px 0;
    flex-shrink: 0;
}

.th-decisions-list__action {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    /* Tokens — pill-sized text */
    font-size: 11px;
    font-weight: var(--th-widget-pill-weight);
    border-radius: 12px;
    border: 1px solid transparent;
    background: transparent;
    cursor: pointer;
    line-height: 1;
    transition: background 0.12s, color 0.12s, border-color 0.12s;
    white-space: nowrap;
}

.th-decisions-list__action:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-decisions-list__action:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.th-decisions-list__action--review {
    color: var(--th-color-success);
    border-color: var(--th-color-success);
}

.th-decisions-list__action--review:not(:disabled):hover {
    background: var(--th-color-success);
    color: #fff;
}

@media (max-width: 320px) {
    .th-decisions-list__action-label {
        display: none;
    }
}

</style>
