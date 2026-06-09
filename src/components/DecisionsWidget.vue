<template>
    <div class="th-decisions-widget">

        <!-- Tab bar -->
        <div class="th-decisions-widget__tabs" role="tablist" :aria-label="t('teamhub', 'Decision views')">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                role="tab"
                :aria-selected="activeTab === tab.id"
                :aria-controls="`th-dec-panel-${tab.id}`"
                :id="`th-dec-tab-${tab.id}`"
                class="th-decisions-widget__tab"
                :class="{ 'th-decisions-widget__tab--active': activeTab === tab.id }"
                @click="setTab(tab.id)">
                <component :is="tab.icon" :size="14" aria-hidden="true" />
                {{ tab.label }}
                <span
                    v-if="tab.id === 'approve' && approveDecisions.length"
                    class="th-decisions-widget__tab-badge"
                    :aria-label="n('teamhub', '{n} item awaiting your approval', '{n} items awaiting your approval', approveDecisions.length, { n: approveDecisions.length })">
                    {{ approveDecisions.length }}
                </span>
            </button>
        </div>

        <!-- Latest 5 panel -->
        <div
            id="th-dec-panel-latest"
            role="tabpanel"
            :aria-labelledby="'th-dec-tab-latest'"
            :hidden="activeTab !== 'latest'">
            <DecisionsList
                v-if="activeTab === 'latest'"
                :decisions="latestDecisions"
                :loading="loadingLatest"
                :error="errorLatest"
                :empty-label="t('teamhub', 'No decisions recorded yet')" />
        </div>

        <!-- Open panel — decisions with status 'open' -->
        <div
            id="th-dec-panel-open"
            role="tabpanel"
            :aria-labelledby="'th-dec-tab-open'"
            :hidden="activeTab !== 'open'">
            <DecisionsList
                v-if="activeTab === 'open'"
                :decisions="openDecisions"
                :loading="loadingOpen"
                :error="errorOpen"
                :empty-label="t('teamhub', 'No open decisions')" />
        </div>

        <!-- Approve panel — finalized decisions in categories I can approve.
             Only rendered when the current user is an approver somewhere. -->
        <div
            v-if="isApprover"
            id="th-dec-panel-approve"
            role="tabpanel"
            :aria-labelledby="'th-dec-tab-approve'"
            :hidden="activeTab !== 'approve'">
            <DecisionsList
                v-if="activeTab === 'approve'"
                :decisions="approveDecisions"
                :loading="loadingApprove"
                :error="errorApprove"
                :empty-label="t('teamhub', 'Nothing awaiting your approval')"
                :show-approver-actions="true"
                :acting-decision-id="approvingDecisionId"
                @review-decision="onReviewOpen" />
        </div>

        <!-- ── Approval modal (Session B — replaces old deny modal) ── -->
        <DecisionApprovalModal
            :open="!!reviewTarget"
            :decision="reviewTarget || {}"
            :saving="approvingDecisionId !== null"
            @approve="onReviewApprove"
            @deny="onReviewDeny"
            @close="reviewTarget = null" />
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { mapState, mapActions }                from 'vuex'
import { NcModal, NcButton }                   from '@nextcloud/vue'
import { generateUrl }                         from '@nextcloud/router'
import { showError }                            from '@nextcloud/dialogs'
import axios                                   from '@nextcloud/axios'
import DecisionsList                            from './DecisionsList.vue'
import DecisionApprovalModal                    from './DecisionApprovalModal.vue'
import GavelIcon                                from 'vue-material-design-icons/Gavel.vue'
import ClockOutlineIcon                         from 'vue-material-design-icons/ClockOutline.vue'
import CheckBoldIcon                            from 'vue-material-design-icons/CheckBold.vue'

export default {
    name: 'DecisionsWidget',

    components: {
        DecisionsList,
        DecisionApprovalModal,
        NcModal,
        NcButton,
        GavelIcon,
        ClockOutlineIcon,
        CheckBoldIcon,
    },

    data() {
        return {
            activeTab: 'latest',

            latestDecisions: [],
            loadingLatest:   false,
            errorLatest:     false,

            openDecisions:   [],
            loadingOpen:     false,
            errorOpen:       false,

            // ── Session I: approver tab ───────────────────────────
            // Categories cache for "am I an approver?" computation. We
            // hit the manage-categories endpoint (open to all team members,
            // returns the approver list) to derive isApprover client-side
            // instead of adding a new endpoint.
            categories:        [],
            categoriesLoaded:  false,

            approveDecisions:  [],
            loadingApprove:    false,
            errorApprove:      false,

            approvingDecisionId: null,  // id currently being approved (disables row)

            // Session B: approval modal target (replaces old denyTarget/denyReason)
            reviewTarget:  null,  // decision object being reviewed
        }
    },

    computed: {
        ...mapState(['currentTeamId']),

        currentUserId() {
            return window.OC?.currentUser || ''
        },

        /**
         * True when the current user appears in at least one category's
         * approver list for this team. The categories list lands lazily,
         * so this flips on after mount.
         */
        isApprover() {
            if (!this.categoriesLoaded) return false
            const uid = this.currentUserId
            if (!uid) return false
            return this.categories.some(c =>
                Array.isArray(c.approvers) && c.approvers.includes(uid),
            )
        },

        tabs() {
            const list = [
                {
                    id: 'latest',
                    // TRANSLATORS: widget tab — the 5 most recently recorded decisions
                    label: t('teamhub', 'Latest'),
                    icon: 'ClockOutlineIcon',
                },
                {
                    id: 'open',
                    // TRANSLATORS: widget tab — decisions still open for discussion (status 'open')
                    label: t('teamhub', 'Open'),
                    icon: 'GavelIcon',
                },
            ]
            if (this.isApprover) {
                list.push({
                    id: 'approve',
                    // TRANSLATORS: widget tab — finalized decisions awaiting the current user's approval
                    label: t('teamhub', 'Approve'),
                    icon: 'CheckBoldIcon',
                })
            }
            return list
        },
    },

    watch: {
        currentTeamId(newId) {
            this.loadLatest()
            // Reset open tab so it reloads when next visited.
            this.openDecisions = []
            this.errorOpen     = false
            this.loadingOpen   = false
            // Reset approve-tab caches; categories are team-scoped.
            this.categories         = []
            this.categoriesLoaded   = false
            this.approveDecisions   = []
            this.errorApprove       = false
            this.loadingApprove     = false
            // If we were on the approve tab, bounce back to latest so we
            // never render an empty approve view for a team where the user
            // isn't an approver.
            if (this.activeTab === 'approve') this.activeTab = 'latest'
            this.loadCategories()
        },
    },

    mounted() {
        this.loadLatest()
        this.loadCategories()
        // Also load Approve list on mount so the badge count is visible at
        // page load — otherwise the count only appears after clicking the
        // Approve tab, defeating its purpose as an attention-getter.
        this.loadApprove()
    },

    methods: {
        t, n,
        ...mapActions(['fetchWidgetDecisions', 'approveDecision', 'denyDecision']),

        setTab(id) {
            this.activeTab = id
            if (id === 'open' && !this.openDecisions.length && !this.loadingOpen) {
                this.loadOpen()
            }
            if (id === 'approve' && !this.approveDecisions.length && !this.loadingApprove) {
                this.loadApprove()
            }
        },

        async loadLatest() {
            this.loadingLatest = true
            this.errorLatest   = false
            try {
                this.latestDecisions = await this.fetchWidgetDecisions({ status: null, limit: 5 })
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] loadLatest error:', err)
                this.errorLatest = true
            } finally {
                this.loadingLatest = false
            }
        },

        async loadOpen() {
            this.loadingOpen = true
            this.errorOpen   = false
            try {
                this.openDecisions = await this.fetchWidgetDecisions({ status: 'open', limit: 25 })
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] loadOpen error:', err)
                this.errorOpen = true
            } finally {
                this.loadingOpen = false
            }
        },

        // ── Session I: categories cache + approve tab ────────────────

        async loadCategories() {
            if (!this.currentTeamId) return
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/manage/categories`),
                )
                this.categories = Array.isArray(data?.items) ? data.items : []
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] loadCategories error (non-fatal):', err)
                // Leave categories empty — Approve tab will simply not appear.
            } finally {
                this.categoriesLoaded = true
            }
        },

        /**
         * Load finalized decisions and filter to the ones in categories
         * the current user can approve. We do the filtering client-side
         * because the API doesn't take an "approver-only" filter (yet) —
         * the categories cache makes this cheap.
         */
        async loadApprove() {
            this.loadingApprove = true
            this.errorApprove   = false
            try {
                const all = await this.fetchWidgetDecisions({ status: 'finalized', limit: 100 })
                const uid = this.currentUserId
                // Build a quick lookup: { categoryName -> Set(approver uids) }
                const approverIndex = new Map()
                for (const c of this.categories) {
                    approverIndex.set(c.name, new Set(c.approvers || []))
                }
                this.approveDecisions = (all || []).filter(d => {
                    if (!d.category) return false
                    const approvers = approverIndex.get(d.category)
                    return approvers && approvers.has(uid)
                })
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] loadApprove error:', err)
                this.errorApprove = true
            } finally {
                this.loadingApprove = false
            }
        },

        // ── Approve / Deny handlers ──────────────────────────────────

        // Session B: approval modal flow (replaces old onApprove + deny modal)
        onReviewOpen(decision) {
            this.reviewTarget = decision
        },

        async onReviewApprove({ decision, reason }) {
            this.approvingDecisionId = decision.id
            try {
                await this.approveDecision({
                    decisionId: decision.id,
                    messageId:  decision.messageId,
                    reason,
                })
                this.approveDecisions = this.approveDecisions.filter(d => d.id !== decision.id)
                this.loadLatest()
                this.reviewTarget = null
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] onReviewApprove error:', err)
                showError(t('teamhub', 'Approval failed: {error}', { error: err?.response?.data?.error || err.message }))
            } finally {
                this.approvingDecisionId = null
            }
        },

        async onReviewDeny({ decision, reason }) {
            this.approvingDecisionId = decision.id
            try {
                await this.denyDecision({
                    decisionId: decision.id,
                    reason,
                    messageId:  decision.messageId,
                })
                this.approveDecisions = this.approveDecisions.filter(d => d.id !== decision.id)
                this.loadLatest()
                this.reviewTarget = null
            } catch (err) {
                console.error('[TeamHub][DecisionsWidget] onReviewDeny error:', err)
                showError(t('teamhub', 'Denial failed: {error}', { error: err?.response?.data?.error || err.message }))
            } finally {
                this.approvingDecisionId = null
            }
        },
    },
}
</script>

<style scoped>
/* ──────────────────────────────────────────────────────────────────────
 * DecisionsWidget — widget-specific chrome only.
 * Shared typography, colour, and structural rules come from
 * src/styles/widget-tokens.css (imported globally in main.js).
 * ────────────────────────────────────────────────────────────────────── */

/* Outer panel — uses the shared .th-widget__panel class in template */
.th-decisions-widget {
    display: flex;
    flex-direction: column;
    height: 100%;
}

/* ── Tab bar (widget-specific — most widgets don't have tabs) ── */
.th-decisions-widget__tabs {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid var(--color-border);
    padding: 0 4px;
    gap: 2px;
    flex-shrink: 0;
}

.th-decisions-widget__tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 10px 7px;
    /* Tokens — tabs use the row-meta size (12px) at row-primary weight */
    font-size: var(--th-widget-row-meta-size);
    font-weight: var(--th-widget-row-primary-weight);
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

.th-decisions-widget__tab:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.th-decisions-widget__tab:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: -2px;
}

.th-decisions-widget__tab--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: var(--th-widget-title-weight);
}

/* Hidden panels */
[hidden] { display: none; }

/* ── Tab badge for the Approve tab (Session I) ──
   The hard red overrides the theme intentionally: action-required
   signalling must read at a glance regardless of NC theme. */
.th-decisions-widget__tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    margin-left: 6px;
    border-radius: 9px;
    background: #c8253f !important;
    color: #ffffff !important;
    /* Tokens — pill weight, slightly larger than the default pill size */
    font-size: 11px;
    font-weight: var(--th-widget-pill-weight);
    line-height: 1;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25), 0 1px 2px rgba(0,0,0,0.15);
}

.th-decisions-widget__tab--active .th-decisions-widget__tab-badge {
    background: #c8253f !important;
    color: #ffffff !important;
}
</style>
