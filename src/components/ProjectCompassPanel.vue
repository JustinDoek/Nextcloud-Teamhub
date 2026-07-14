<template>
    <section
        v-if="visible"
        class="th-compass"
        :aria-label="t('teamhub', 'Project Compass — guided setup checklist')">

        <!-- Loading state — first fetch only. Subsequent refetches reuse the
             last payload silently so the panel doesn't flicker on visibility
             change. -->
        <div v-if="loading && !payload" class="th-compass__loading">
            {{ t('teamhub', 'Loading Project Compass') }}
        </div>

        <!-- Fetch failure — surfaced non-blockingly. -->
        <div v-else-if="fetchError" class="th-compass__error" role="alert">
            {{ t('teamhub', 'Could not load Project Compass: {error}', { error: fetchError }) }}
        </div>

        <template v-else-if="payload && payload.items.length > 0">
            <!-- Header row: title + "Show phase walkthrough" trigger.
                 The walkthrough is the folded-in ProjectPhaseGuide dialog
                 (v3.98.0). Auto-open on team creation is gone; users open
                 it on demand from here. -->
            <div class="th-compass__header">
                <div class="th-compass__title">
                    <CompassOutline :size="18" aria-hidden="true" />
                    <h2>{{ t('teamhub', 'Project Compass') }}</h2>
                </div>
                <div class="th-compass__header-actions">
                    <!-- v3.99.3 — Walkthrough button removed. The phase
                         stepper's info button opens the same
                         ProjectPhaseGuide dialog; keeping two entry
                         points was duplication. -->
                    <button
                        type="button"
                        class="th-compass__collapse"
                        :aria-expanded="!collapsed ? 'true' : 'false'"
                        :aria-label="collapsed ? t('teamhub', 'Expand Project Compass') : t('teamhub', 'Collapse Project Compass')"
                        @click="toggleCollapsed">
                        <ChevronUp v-if="!collapsed" :size="16" />
                        <ChevronDown v-else :size="16" />
                    </button>
                </div>
            </div>

            <div v-if="!collapsed" class="th-compass__body">
                <!-- Next up prompt — the topmost incomplete item, or the
                     "ready to advance" CTA when the checklist is complete. -->
                <div class="th-compass__nextup" :class="{ 'th-compass__nextup--ready': payload.readyToAdvance }">
                    <span class="th-compass__nextup-label">
                        <template v-if="payload.readyToAdvance">
                            {{ t('teamhub', 'Setup complete') }}
                        </template>
                        <template v-else>
                            {{ t('teamhub', 'Next up') }}
                        </template>
                    </span>
                    <template v-if="payload.readyToAdvance">
                        <span class="th-compass__nextup-text">
                            {{ readyText }}
                        </span>
                        <button
                            v-if="canAdvancePhase"
                            type="button"
                            class="th-compass__advance"
                            @click="$emit('advance-phase', payload.nextPhase)">
                            {{ t('teamhub', 'Advance phase') }}
                        </button>
                    </template>
                    <template v-else>
                        <span class="th-compass__nextup-text">{{ nextUpItem.label }}</span>
                        <button
                            type="button"
                            class="th-compass__nextup-link"
                            @click="followItem(nextUpItem)">
                            {{ t('teamhub', 'Go') }}
                            <ArrowRight :size="14" aria-hidden="true" />
                        </button>
                    </template>
                </div>

                <!-- Checklist -->
                <ul class="th-compass__list" role="list">
                    <li
                        v-for="item in payload.items"
                        :key="item.id"
                        class="th-compass__item"
                        :class="{
                            'th-compass__item--done': item.done,
                            'th-compass__item--advisory': item.advisory,
                        }">
                        <span class="th-compass__item-check" aria-hidden="true">
                            <CheckCircleOutline v-if="item.done" :size="16" />
                            <InformationOutline v-else-if="item.advisory" :size="16" />
                            <CircleOutline v-else :size="16" />
                        </span>
                        <div class="th-compass__item-body">
                            <div class="th-compass__item-label">
                                {{ item.label }}
                                <span v-if="item.advisory" class="th-compass__item-advisory-tag">
                                    {{ t('teamhub', 'Advisory') }}
                                </span>
                            </div>
                            <div class="th-compass__item-hint">{{ item.hint }}</div>
                        </div>
                        <template v-if="!item.done">
                            <button
                                v-if="itemHasOpenAffordance(item)"
                                type="button"
                                class="th-compass__item-link"
                                :aria-label="t('teamhub', 'Open: {label}', { label: item.label })"
                                @click="followItem(item)">
                                {{ t('teamhub', 'Open') }}
                            </button>
                            <button
                                v-if="item.markable"
                                type="button"
                                class="th-compass__item-mark"
                                :title="t('teamhub', 'Mark this item as done')"
                                :aria-label="t('teamhub', 'Mark as done')"
                                @click="toggleMark(item)">
                                {{ t('teamhub', 'Mark done') }}
                            </button>
                        </template>
                        <template v-else>
                            <span
                                class="th-compass__item-done-badge"
                                :aria-label="t('teamhub', 'Done')">
                                {{ t('teamhub', 'Done') }}
                            </span>
                            <button
                                v-if="item.markable"
                                type="button"
                                class="th-compass__item-unmark"
                                :title="t('teamhub', 'Unmark — this item still needs attention')"
                                :aria-label="t('teamhub', 'Unmark')"
                                @click="toggleMark(item)">
                                {{ t('teamhub', 'Unmark') }}
                            </button>
                        </template>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>

<script>
import { mapState, mapMutations } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import CompassOutline from 'vue-material-design-icons/CompassOutline.vue'

/**
 * ProjectCompassPanel (v3.98.0).
 *
 * Guided setup checklist + Next-up prompt for Advanced project teams.
 * Renders on the team Home view between the phase stepper and the widget
 * grid. Only visible when the store's `project` fact confirms an Advanced
 * project. Compass folds the pre-3.98.0 one-shot ProjectPhaseGuide auto-
 * open behaviour into a persistent panel — the Guide walkthrough itself
 * remains accessible via the header "Walkthrough" button.
 *
 * Emits:
 *   - open-manage-team-section(section)  — TeamView routes to Manage Team
 *   - set-view(view)                     — TeamView switches tabs
 *   - invite                             — TeamView opens InviteMemberModal
 *   - advance-phase(nextPhase)           — TeamView bumps project.phase
 *   - open-walkthrough                   — TeamView shows the phase Guide
 */
export default {
    name: 'ProjectCompassPanel',

    components: {
        ChevronUp, ChevronDown, CheckCircleOutline, CircleOutline,
        ArrowRight, CompassOutline, InformationOutline,
    },

    emits: [
        'open-manage-team-section', 'set-view', 'invite', 'advance-phase',
        'open-swimlanes-modal',
        // v3.99.0 — Closing phase
        'generate-closing-artifact', 'archive-team',
        // v3.99.1 — Planning phase manual-mark items
        'open-add-meeting',
    ],

    data() {
        return {
            payload: null,
            loading: false,
            fetchError: null,
            // Persisted per-user collapse preference. localStorage keeps it
            // simple — this is a preference, not first-class state, and
            // survives page reloads without a store fetch.
            collapsed: false,
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'project', 'budgetConfig', 'timeConfig', 'currentView', 'intravoxAvailable']),

        visible() {
            return !!(
                this.project
                && this.project.isProject
                && this.project.mode === 'advanced'
            )
        },

        nextUpItem() {
            if (!this.payload) return null
            return this.payload.items.find(i => !i.done) || null
        },

        canAdvancePhase() {
            // Only the frontend gate — TeamView still requires admin level
            // for the actual advance call. Non-admins clicking see a 403
            // via the existing setPhase endpoint, which is acceptable — the
            // guidance itself is member-visible.
            return !!(this.payload && this.payload.readyToAdvance && this.payload.nextPhase)
        },

        readyText() {
            const p = this.payload && this.payload.nextPhase
            if (!p) return t('teamhub', 'Ready to close out this phase')
            switch (p) {
            case 'execution':
                // TRANSLATORS: shown on the Project Compass when all planning-phase setup items are complete
                return t('teamhub', 'Ready to enter Execution')
            case 'closing':
                // TRANSLATORS: shown on the Project Compass when all execution-phase items are complete
                return t('teamhub', 'Ready to enter Closing')
            default:
                return t('teamhub', 'Ready to advance the project phase')
            }
        },
    },

    watch: {
        currentTeamId(newId) {
            if (newId) this.fetch()
        },
        // Refetch when the project fact updates (e.g. after saving dates
        // in Manage Team) so the checklist reflects the change without a
        // manual reload.
        project: {
            deep: true,
            handler() { this.fetch() },
        },
        budgetConfig: {
            deep: true,
            handler() { this.fetch() },
        },
        timeConfig: {
            deep: true,
            handler() { this.fetch() },
        },
        // v3.99.1 — refetch when the user returns to the Home view from
        // another tab (Deck, Files, Budget, Time, …). The Compass is
        // only rendered on Home, but the panel doesn't remount on view
        // switches (it's v-show'd, not v-if'd), so mount/focus/visibility
        // watchers all miss this case. Fixes Justin's report: "After I
        // log the first expense I need to reload the team before it
        // updates in the Compass."
        currentView(view, prev) {
            if (view === 'msgstream' && prev !== 'msgstream') {
                this.fetch()
            }
        },
    },

    mounted() {
        try {
            this.collapsed = localStorage.getItem('teamhub.compass.collapsed') === '1'
        } catch (e) { /* ignore */ }
        this.fetch()
        this._onFocus = () => this.fetch()
        this._onVis = () => {
            if (document.visibilityState === 'visible') this.fetch()
        }
        window.addEventListener('focus', this._onFocus)
        document.addEventListener('visibilitychange', this._onVis)
    },

    beforeDestroy() {
        if (this._onFocus) {
            window.removeEventListener('focus', this._onFocus)
            this._onFocus = null
        }
        if (this._onVis) {
            document.removeEventListener('visibilitychange', this._onVis)
            this._onVis = null
        }
    },

    methods: {
        t,
        ...mapMutations(['SET_MANAGE_TEAM_DEEP_LINK']),

        async fetch() {
            if (!this.visible || !this.currentTeamId) return
            this.loading = true
            this.fetchError = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/readiness`),
                )
                this.payload = data && data.isProject ? data : null
            } catch (e) {
                this.fetchError = e?.response?.data?.error || e?.message || t('teamhub', 'Unknown error')
            } finally {
                this.loading = false
            }
        },

        followItem(item) {
            if (!item || !item.link) return
            const link = item.link
            switch (link.target) {
            case 'manage-team':
                this.SET_MANAGE_TEAM_DEEP_LINK({ tab: link.tab || 'project', section: link.section || 'top' })
                this.$emit('open-manage-team-section')
                break
            case 'set-view':
                this.$emit('set-view', link.view)
                break
            case 'invite-modal':
                this.$emit('invite')
                break
            case 'swimlanes-modal':
                // v3.98.2 — Planning-phase "Define workstreams" activity.
                this.$emit('open-swimlanes-modal')
                break
            case 'closing-artifact':
                // v3.99.0 — Closing-phase artifact generation.
                this.$emit('generate-closing-artifact')
                break
            case 'archive-team':
                // v3.99.0 — team archive final step (opens the policy
                // warning modal in TeamView).
                this.$emit('archive-team')
                break
            case 'add-meeting':
                // v3.99.1 — Planning-phase kickoff meeting item. Opens
                // the existing Add Meeting wizard on TeamView.
                this.$emit('open-add-meeting')
                break
            default:
                // Unknown target — no-op rather than throw.
                break
            }
        },

        /**
         * v3.99.1 — persist a user-confirmed Planning-phase mark. Used by
         * the inline "Mark as done" button on charter/kickoff items where
         * there's no programmatic "done" signal.
         */
        /**
         * v3.99.3 — some items require an external module (e.g., the
         * charter link opens the IntraVox Pages tab). Hide the Open
         * button when the required module isn't installed on this NC
         * instance; the Mark done button still works so the admin can
         * confirm they've handled the task another way (e.g. a plain
         * Files-folder charter doc when IntraVox is absent).
         */
        itemHasOpenAffordance(item) {
            const requires = item?.link?.requires
            if (requires === 'intravox' && !this.intravoxAvailable) return false
            return !!item?.link
        },

        async toggleMark(item) {
            if (!item?.markable) return
            try {
                await axios.put(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/marks/${item.markable}`),
                    { done: !item.done },
                )
                // Refetch so the flipped state and any downstream
                // readyToAdvance change land in the UI.
                this.fetch()
            } catch (e) {
                // Non-fatal — fetchError is user-visible via the panel.
                this.fetchError = e?.response?.data?.error || e?.message || t('teamhub', 'Failed to mark item')
            }
        },

        toggleCollapsed() {
            this.collapsed = !this.collapsed
            try {
                localStorage.setItem('teamhub.compass.collapsed', this.collapsed ? '1' : '0')
            } catch (e) { /* ignore */ }
        },
    },
}
</script>

<style scoped>
/* v3.100.7 — density pass. Panel + banner + item paddings all trimmed
   so the Compass leaves more of the viewport for widgets. Numbers were
   picked to keep touch-friendly hit targets on the "Mark done" /
   "Open" / advance buttons — nothing under 28px tall gets clicked. */
.th-compass {
    display: block;
    margin: 8px 0;
    padding: 8px 12px;
    background: var(--color-background-hover);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
}

.th-compass__loading,
.th-compass__error {
    padding: 4px 0;
    color: var(--color-text-maxcontrast);
}

.th-compass__error {
    color: var(--color-error-text);
    background: var(--color-error);
    border-radius: var(--border-radius);
    padding: 8px 12px;
}

.th-compass__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
}

.th-compass__title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-primary-element);
}

.th-compass__title h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--color-main-text);
}

.th-compass__header-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.th-compass__collapse {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 28px;
    height: 28px;
    min-width: 28px;
    min-height: 28px;
    max-width: 28px;
    max-height: 28px;
    padding: 0;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
}

/* v3.100.14: split hover+focus so :focus-visible keeps its 2px ring. */
.th-compass__collapse:hover {
    background: var(--color-background-dark);
}

.th-compass__collapse:focus-visible {
    background: var(--color-background-dark);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.th-compass__nextup {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    margin-bottom: 6px;
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border-radius: var(--border-radius);
    font-size: 13px;
    flex-wrap: wrap;
}

.th-compass__nextup--ready {
    background: var(--color-success);
    color: var(--color-success-text);
}

.th-compass__nextup-label {
    font-weight: 700;
    text-transform: uppercase;
    font-size: var(--th-font-micro);
    letter-spacing: 0.05em;
    flex-shrink: 0;
}

.th-compass__nextup-text {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 500;
}

.th-compass__nextup-link,
.th-compass__advance {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border: none;
    border-radius: var(--border-radius);
    background: var(--color-primary-element-text);
    color: var(--color-primary-element);
    font-size: var(--th-font-meta);
    font-weight: 600;
    cursor: pointer;
}

.th-compass__nextup--ready .th-compass__advance {
    background: var(--color-success-text);
    color: var(--color-success);
}

.th-compass__nextup-link:focus-visible,
.th-compass__advance:focus-visible {
    outline: 2px solid var(--color-primary-element-text);
    outline-offset: 2px;
}

.th-compass__list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.th-compass__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.th-compass__item--done {
    opacity: 0.6;
}
/* Done items don't need the description — the label alone is enough
   confirmation, and hiding it removes ~16px per row. */
.th-compass__item--done .th-compass__item-hint {
    display: none;
}

/* v3.98.4 — advisory items surface state (over budget, slipping milestones)
 * but don't gate phase advancement. Muted left border + info icon so
 * admins see them as informational rather than as a to-do. */
.th-compass__item--advisory {
    border-left: 3px solid var(--color-warning);
}

.th-compass__item-check {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
}

.th-compass__item--advisory .th-compass__item-check {
    color: var(--color-warning);
}

.th-compass__item--done .th-compass__item-check {
    color: var(--color-success);
}

.th-compass__item-advisory-tag {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 8px;
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    vertical-align: middle;
}

.th-compass__item-body {
    flex: 1 1 auto;
    min-width: 0;
}

.th-compass__item-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-main-text);
}

.th-compass__item-hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    margin-top: 1px;
    line-height: 1.35;
}

.th-compass__item-link {
    align-self: center;
    padding: 4px 10px;
    background: transparent;
    border: 1px solid var(--color-primary-element);
    border-radius: var(--border-radius);
    color: var(--color-primary-element);
    font-size: var(--th-font-meta);
    font-weight: 500;
    cursor: pointer;
}

/* v3.100.14: split hover+focus so :focus-visible keeps its 2px ring. */
.th-compass__item-link:hover {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
}

.th-compass__item-link:focus-visible {
    background: var(--color-primary-element);
    color: var(--color-primary-element-text);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.th-compass__item-done-badge {
    align-self: center;
    padding: 2px 8px;
    background: var(--color-success);
    color: var(--color-success-text);
    border-radius: 10px;
    font-size: var(--th-font-micro);
    font-weight: 600;
}

/* v3.99.1 — inline "Mark done" / "Unmark" for user-confirmed items */
.th-compass__item-mark,
.th-compass__item-unmark {
    align-self: center;
    padding: 4px 10px;
    background: transparent;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    color: var(--color-main-text);
    font-size: var(--th-font-meta);
    font-weight: 500;
    cursor: pointer;
}
/* v3.100.14: split hover+focus so keyboard users get a visible focus
   indicator. Previously :hover and :focus-visible were grouped and set
   outline: none — WCAG 2.4.7 regression. */
.th-compass__item-mark:hover {
    background: var(--color-success);
    color: var(--color-success-text);
    border-color: var(--color-success);
}
.th-compass__item-mark:focus-visible {
    background: var(--color-success);
    color: var(--color-success-text);
    border-color: var(--color-success);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
.th-compass__item-unmark:hover {
    background: var(--color-background-hover);
}
.th-compass__item-unmark:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}
</style>
