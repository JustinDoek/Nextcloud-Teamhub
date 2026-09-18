<template>
    <ol class="th-rail" :aria-label="t('teamhub', 'Decision progress')">
        <li
            v-for="step in steps"
            :key="step.key"
            class="th-rail__step"
            :class="`th-rail__step--${step.state}`"
            :aria-current="step.state === 'active' ? 'step' : undefined">
            <span class="th-rail__marker" aria-hidden="true">
                <span class="th-rail__dot" />
                <span class="th-rail__line" />
            </span>
            <span class="th-rail__body">
                <span class="th-rail__label">{{ step.label }}</span>
                <!-- The state is spelled out for screen readers and for anyone
                     who cannot separate the dot styles by sight. WCAG 1.4.1:
                     the rail must not carry its meaning in colour alone, and
                     weight alone is not enough either. -->
                <span class="th-rail__state">{{ step.stateLabel }}</span>
            </span>
        </li>
    </ol>
</template>

<script>
/**
 * DecisionProgressRail — where a proposal is in its lifecycle (v4.5.44).
 *
 * A vertical four-step rail: create → discuss → finalize → decision. Rendered
 * beside the compose form (where only `create` is live) and inside the
 * proposer's drafting block on an open proposal (where `discuss` and
 * `finalize` are both live, because the proposer can do either next).
 *
 * ## The `active` prop is a list, not a cursor
 *
 * A single "current step" cannot describe an open proposal: once it is out for
 * discussion the proposer may edit it again *or* finalize it, and neither is
 * more current than the other. So the caller names the steps that are live and
 * everything before the first of them is inferred as done. That keeps the
 * two call sites from having to agree on a cursor position that does not
 * exist.
 *
 * Extracted rather than duplicated per SKILLS.md § Micro-component extraction:
 * two call sites, and the step vocabulary is the thing that must not drift
 * between them.
 */
import { translate as t } from '@nextcloud/l10n'

/** Lifecycle order. Index in this array is what makes "before" meaningful. */
const STEP_ORDER = ['create', 'discuss', 'finalize', 'decision']

export default {
    name: 'DecisionProgressRail',

    props: {
        /**
         * Step keys that are live right now. Order within the array does not
         * matter; position in STEP_ORDER does.
         */
        active: {
            type: Array,
            default: () => ['create'],
            validator: v => v.every(k => STEP_ORDER.includes(k)),
        },
    },

    computed: {
        /**
         * Built in a computed rather than at module scope so `t()` runs after
         * Nextcloud's l10n bundle has loaded — same reason MyWorkItemRow
         * builds its snooze presets this way.
         */
        stepLabels() {
            return {
                // v4.5.46 — one word per step. "…proposal" on three of the
                // four was the same noun three times in a column that is
                // already headed by the proposal, and it forced the rail
                // wider than it needed to be.
                // TRANSLATORS: decision lifecycle step — writing the proposal
                create: t('teamhub', 'Create'),
                // TRANSLATORS: decision lifecycle step — gathering feedback before finalizing
                discuss: t('teamhub', 'Discuss'),
                // TRANSLATORS: decision lifecycle step — locking the wording and sending it to approvers
                finalize: t('teamhub', 'Finalize'),
                // TRANSLATORS: decision lifecycle step — approvers approve or deny
                decision: t('teamhub', 'Decision'),
            }
        },

        steps() {
            const firstActive = STEP_ORDER.findIndex(k => this.active.includes(k))

            return STEP_ORDER.map((key, index) => {
                let state = 'upcoming'
                if (this.active.includes(key)) {
                    state = 'active'
                } else if (firstActive > -1 && index < firstActive) {
                    state = 'done'
                }

                return {
                    key,
                    state,
                    label: this.stepLabels[key],
                    stateLabel: this.stateLabels[state],
                }
            })
        },

        stateLabels() {
            return {
                // TRANSLATORS: progress rail — this step has been completed
                done: t('teamhub', 'Done'),
                // TRANSLATORS: progress rail — this step is where you are now
                active: t('teamhub', 'Now'),
                // TRANSLATORS: progress rail — this step has not been reached yet
                upcoming: t('teamhub', 'Later'),
            }
        },
    },

    methods: { t },
}
</script>

<style scoped>
.th-rail {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.th-rail__step {
    display: flex;
    gap: 10px;
    min-height: 44px;
}

/* The marker column: a dot with a connector running down from it. The last
   step's connector is hidden so the line stops at the final dot. */
.th-rail__marker {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 0 0 auto;
    width: 14px;
}

.th-rail__dot {
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 12px;
    height: 12px;
    min-width: 12px;
    min-height: 12px;
    max-width: 12px;
    max-height: 12px;
    margin-top: 3px;
    border-radius: 50%;
    border: 2px solid var(--color-border-dark);
    background: var(--color-main-background);
}

.th-rail__line {
    flex: 1 1 auto;
    width: 2px;
    margin: 2px 0;
    background: var(--color-border);
}

.th-rail__step:last-child .th-rail__line {
    visibility: hidden;
}

.th-rail__body {
    display: flex;
    flex-direction: column;
    gap: 1px;
    padding-bottom: 10px;
    min-width: 0;
}

.th-rail__label {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}

.th-rail__state {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* ── States ─────────────────────────────────────────────────────────────
   Three distinctions, each carried by more than colour: the dot fill, the
   label weight, and the spelled-out state line under it. */

.th-rail__step--done .th-rail__dot {
    background: var(--color-border-dark);
    border-color: var(--color-border-dark);
}

.th-rail__step--active .th-rail__dot {
    background: var(--color-primary-element);
    border-color: var(--color-primary-element);
    /* A ring rather than a bigger dot, so the dots stay aligned on the line. */
    box-shadow: 0 0 0 3px var(--color-primary-element-light);
}

.th-rail__step--active .th-rail__label {
    color: var(--color-main-text);
    font-weight: var(--th-font-weight-semibold);
}

.th-rail__step--active .th-rail__state {
    color: var(--color-primary-element);
    font-weight: var(--th-font-weight-semibold);
}

.th-rail__step--upcoming .th-rail__label,
.th-rail__step--upcoming .th-rail__state {
    opacity: 0.7;
}
</style>
