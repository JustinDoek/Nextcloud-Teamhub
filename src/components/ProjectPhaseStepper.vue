<template>
    <nav class="phase-stepper" :aria-label="t('teamhub', 'Project phase')">
        <ol class="phase-stepper__list">
            <li
                v-for="(p, i) in phases"
                :key="p.key"
                class="phase-stepper__item"
                :class="'phase-stepper__item--' + p.status"
                :aria-current="p.status === 'active' ? 'step' : false">
                <span class="phase-stepper__marker" aria-hidden="true">
                    <Check v-if="p.status === 'done'" :size="14" />
                    <span v-else class="phase-stepper__num">{{ i + 1 }}</span>
                </span>
                <span class="phase-stepper__label">{{ p.label }}</span>
                <span class="phase-stepper__sr">{{ p.srStatus }}</span>
                <span v-if="i < phases.length - 1" class="phase-stepper__line" aria-hidden="true" />
            </li>
        </ol>
        <template v-if="canManage">
            <span class="phase-stepper__divider" aria-hidden="true" />
            <button
                type="button"
                class="phase-stepper__info"
                :aria-label="t('teamhub', 'About this phase')"
                @click="$emit('show-info')">
                <InformationOutline :size="16" aria-hidden="true" />
            </button>
        </template>
    </nav>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Check from 'vue-material-design-icons/Check.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

// Canonical PMC phase order — must match ProjectService::PHASES on the backend.
const PHASE_ORDER = ['initiation', 'planning', 'execution', 'closing']

export default {
    name: 'ProjectPhaseStepper',
    components: { Check, InformationOutline },
    props: {
        // Current phase of an advanced project: one of PHASE_ORDER.
        phase: {
            type: String,
            default: 'planning',
        },
        // Whether to show the "About this phase" info button — admin/owner only,
        // mirrors the isTeamAdmin gating already used for "Manage team" elsewhere.
        canManage: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['show-info'],
    computed: {
        phaseLabels() {
            return {
                // TRANSLATORS: PMC project lifecycle phase — the project has been approved and is being set up
                initiation: t('teamhub', 'Initiation'),
                // TRANSLATORS: PMC project lifecycle phase — defining scope, tasks, milestones and schedule
                planning: t('teamhub', 'Planning'),
                // TRANSLATORS: PMC project lifecycle phase — carrying out the planned work
                execution: t('teamhub', 'Execution'),
                // TRANSLATORS: PMC project lifecycle phase — wrapping up, evaluating and archiving the project
                closing: t('teamhub', 'Closing'),
            }
        },
        currentIndex() {
            const idx = PHASE_ORDER.indexOf(this.phase)
            return idx === -1 ? 1 : idx // default to Planning if unknown
        },
        phases() {
            return PHASE_ORDER.map((key, i) => {
                let status = 'upcoming'
                if (i < this.currentIndex) status = 'done'
                else if (i === this.currentIndex) status = 'active'
                return {
                    key,
                    label: this.phaseLabels[key],
                    status,
                    srStatus: status === 'done'
                        // TRANSLATORS: screen-reader-only status appended to a completed project phase
                        ? t('teamhub', '(completed)')
                        : status === 'active'
                            // TRANSLATORS: screen-reader-only status appended to the current project phase
                            ? t('teamhub', '(current phase)')
                            : '',
                }
            })
        },
    },
    methods: { t, n },
}
</script>

<style scoped>
.phase-stepper {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    margin-bottom: 16px;
}

.phase-stepper__list {
    display: flex;
    align-items: center;
    list-style: none;
    margin: 0;
    padding: 0;
    /* Grow to fill the bar so the whole list (not just the info button) spans
       the full width of the canvas — items below share this space equally. */
    flex: 1 1 auto;
    min-width: 0;
}

.phase-stepper__item {
    display: flex;
    align-items: center;
    /* Each phase (marker + label + connector) shares the list's width equally,
       so the connector lines stretch to spread the whole bar full-width
       instead of clustering the steps on the left. */
    flex: 1 1 0;
    min-width: 0;
    gap: 8px;
    position: relative;
}

/* The last item has no connector line to stretch, so it shouldn't claim an
   equal share of the leftover width — it would otherwise drag its label away
   from its marker to fill the gap. */
.phase-stepper__item:last-child {
    flex: 0 0 auto;
}

.phase-stepper__marker {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 13px;
    font-weight: 700;
    /* Upcoming phases: neutral, non-state surface. */
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}

/* Completed phases — full-saturation success token + its matching text token
   (SKILLS.md state-colour rule). */
.phase-stepper__item--done .phase-stepper__marker {
    background: var(--color-success);
    color: var(--color-success-text);
}

/* Current phase — full-saturation primary token + its matching text token. */
.phase-stepper__item--active .phase-stepper__marker {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.phase-stepper__label {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    color: var(--color-text-maxcontrast);
}

.phase-stepper__item--done .phase-stepper__label,
.phase-stepper__item--active .phase-stepper__label {
    color: var(--color-main-text);
}

.phase-stepper__item--active .phase-stepper__label { font-weight: 700; }

.phase-stepper__line {
    /* Stretches to fill the leftover width in its (now flex:1) item, which is
       what spreads the connectors — and the whole bar — full width. */
    flex: 1 1 auto;
    min-width: 16px;
    height: 2px;
    margin: 0 8px;
    background: var(--color-border);
}

.phase-stepper__item--done .phase-stepper__line { background: var(--color-success); }

.phase-stepper__divider {
    /* A short divider right after the last phase — reads as "the info button
       is its own control", not part of the last phase item — without
       stretching to the far edge of the (full-width) bar. */
    flex: none;
    width: 1px;
    height: 20px;
    margin: 0 8px;
    background: var(--color-border);
}

.phase-stepper__info {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    /* Nextcloud's global button styles set a min-width/min-height (touch
       target sizing) that beats an unqualified width/height and stretches
       this into an oval — pin box-sizing + both min- and max- so the circle
       holds regardless of what the global button reset applies. */
    box-sizing: border-box;
    width: 26px;
    height: 26px;
    min-width: 26px;
    min-height: 26px;
    max-width: 26px;
    max-height: 26px;
    padding: 0;
    margin: 0;
    border: none;
    border-radius: 50%;
    line-height: 1;
    /* Full-saturation success token + matching text token (SKILLS.md state-
       colour rule) — same treatment as a completed phase marker above. */
    background: var(--color-success);
    color: var(--color-success-text);
    cursor: pointer;
}

.phase-stepper__info:hover {
    background: var(--color-success-hover, var(--color-success));
}

.phase-stepper__info:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Visually-hidden status text for assistive tech only. */
.phase-stepper__sr {
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

/* Narrow viewports: hide labels, keep the numbered markers + connectors. */
@media (max-width: 500px) {
    .phase-stepper__label { display: none; }
}
</style>
