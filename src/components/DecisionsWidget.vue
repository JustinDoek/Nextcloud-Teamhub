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
            :can-schedule-meeting="canScheduleMeetingForReview"
            @approve="onReviewApprove"
            @deny="onReviewDeny"
            @schedule-meeting="openApproverMeetingWizard"
            @close="reviewTarget = null" />

        <!-- v3.74.10 — Schedule approver meeting wizard, mounted on demand
             from the approval modal's "Schedule meeting" button. -->
        <SuggestMeetingWizard
            v-if="showApproverMeetingWizard"
            :team-id="currentTeamId"
            :calendars="calendars"
            :prefilled-attendees="approverPrefill.attendees"
            :prefilled-title="approverPrefill.title"
            :prefilled-description="approverPrefill.description"
            :prefilled-category="approverPrefill.category"
            :prefill-banner="approverPrefill.banner"
            :lock-attendees="true"
            @created="onApproverMeetingCreated"
            @close="showApproverMeetingWizard = false; schedulingMeetingForDecisionId = null" />
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
import SuggestMeetingWizard                     from './SuggestMeetingWizard.vue'
import GavelIcon                                from 'vue-material-design-icons/Gavel.vue'
import ClockOutlineIcon                         from 'vue-material-design-icons/ClockOutline.vue'
import CheckBoldIcon                            from 'vue-material-design-icons/CheckBold.vue'

export default {
    name: 'DecisionsWidget',

    components: {
        DecisionsList,
        DecisionApprovalModal,
        SuggestMeetingWizard,
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

            // v3.74.10 — Schedule approver meeting flow (mirrors TeamDecisionsView).
            showApproverMeetingWizard: false,
            approverPrefill: {
                attendees: [],
                title: '',
                description: '',
                category: '',
                banner: '',
            },
            schedulingMeetingForDecisionId: null,
            openingMeetingWizard: false,
        }
    },

    computed: {
        ...mapState(['currentTeamId', 'resources']),

        currentUserId() {
            return window.OC?.currentUser || ''
        },

        /**
         * Calendar resource list for this team. Used by the approver-meeting
         * wizard mount and to gate the schedule-meeting button.
         */
        calendars() {
            return (this.resources && this.resources.calendar) || []
        },

        /**
         * True when the team has at least one Calendar configured. Without it
         * the meeting wizard cannot create an event, so we hide the button.
         */
        hasCalendar() {
            return this.calendars.length > 0
        },

        /**
         * True when the schedule-meeting button should appear inside the
         * approval modal for the currently-reviewed decision. Gates on:
         *   - team has a Calendar configured (else wizard can't create event),
         *   - the decision's category has more than one approver (no point
         *     scheduling a meeting with yourself).
         * Returns false until categories have loaded.
         */
        canScheduleMeetingForReview() {
            if (!this.hasCalendar) return false
            if (!this.categoriesLoaded) return false
            const d = this.reviewTarget
            if (!d || !d.category) return false
            const cat = this.categories.find(c => c.name === d.category)
            return Array.isArray(cat?.approvers) && cat.approvers.length > 1
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

        // ── v3.74.10 — Schedule approver meeting flow ───────────────────

        /**
         * Build the description for an approver meeting from the decision data
         * available on the approve tab. Mirrors TeamDecisionsView.buildMeetingDescription:
         * title + first 400 chars of body + link back to team home.
         *
         * The approve tab's DecisionsList rows do include the message body
         * via the same decision payload, so `decision.message?.message` works
         * the same way here.
         */
        buildMeetingDescription(d) {
            const lines = []
            if (d.question) lines.push(d.question)
            const body = (d.message && d.message.message) ? String(d.message.message) : ''
            if (body) {
                const truncated = body.length > 400 ? body.slice(0, 400).trimEnd() + '…' : body
                lines.push('')
                lines.push(truncated)
            }
            // Deep link to the specific proposal — see TeamDecisionsView.buildMeetingDescription
            // for the routing contract App.vue honours on mount. escape:false
            // prevents NC's t() from turning `&` into `&amp;` (output goes to
            // a plain-text iCal DESCRIPTION, not HTML).
            try {
                const deepUrl = window.location.origin
                    + generateUrl('/apps/teamhub')
                    + `?team=${encodeURIComponent(this.currentTeamId)}`
                    + `&decision=${encodeURIComponent(d.id)}`
                lines.push('')
                lines.push(t('teamhub', 'Link: {url}', { url: { value: deepUrl, escape: false } }))
            } catch (e) {
                console.warn('[TeamHub][DecisionsWidget] buildMeetingDescription link build failed:', e?.message)
            }
            return lines.join('\n')
        },

        /**
         * Called from DecisionApprovalModal's @schedule-meeting event.
         * Closes the approval modal (we don't want both modals open at once),
         * fetches approvers, then opens the wizard.
         */
        async openApproverMeetingWizard(decision) {
            if (!decision || this.openingMeetingWizard) return
            this.openingMeetingWizard = true
            // Close approval modal — the wizard takes over now.
            this.reviewTarget = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decision.id}/approvers`),
                )
                const approverIds = Array.isArray(data?.approvers)
                    ? data.approvers.map(a => a.userId).filter(Boolean)
                    : []
                this.approverPrefill = {
                    attendees:   approverIds,
                    // TRANSLATORS: prefilled meeting title for an approver-meeting on a proposal
                    title:       t('teamhub', 'Proposal meeting'),
                    description: this.buildMeetingDescription(decision),
                    category:    t('teamhub', 'Proposals'),
                    banner:      t('teamhub', 'This meeting will be scheduled with all approvers of this category. The attendee list is locked.'),
                }
                this.schedulingMeetingForDecisionId = decision.id
                this.showApproverMeetingWizard = true
            } catch (e) {
                console.warn('[TeamHub][DecisionsWidget] openApproverMeetingWizard approvers fetch failed:', e?.message)
                showError(t('teamhub', 'Could not load approvers for this proposal'))
            } finally {
                this.openingMeetingWizard = false
            }
        },

        async onApproverMeetingCreated(payload) {
            const decisionId = this.schedulingMeetingForDecisionId
            this.schedulingMeetingForDecisionId = null
            this.showApproverMeetingWizard = false
            if (!decisionId || !payload || !payload.eventUid) {
                console.warn('[TeamHub][DecisionsWidget] onApproverMeetingCreated missing decisionId or eventUid')
                return
            }
            try {
                const startMs  = payload.start ? new Date(payload.start).getTime() : 0
                const startSec = startMs > 0 ? Math.floor(startMs / 1000) : Math.floor(Date.now() / 1000)
                await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/meetings`),
                    {
                        eventUid:     payload.eventUid,
                        meetingTitle: payload.title || '',
                        meetingStart: startSec,
                    },
                )
            } catch (e) {
                console.warn('[TeamHub][DecisionsWidget] onApproverMeetingCreated record failed:', e?.message)
                showError(t('teamhub', 'Meeting created, but the link to the proposal could not be saved'))
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
