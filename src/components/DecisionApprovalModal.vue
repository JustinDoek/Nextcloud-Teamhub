<template>
    <NcModal
        v-if="open"
        size="small"
        :name="t('teamhub', 'Review decision')"
        @close="$emit('close')">
        <div class="th-approval-modal">
            <h3 class="th-approval-modal__title">{{ decision.question || t('teamhub', 'Untitled') }}</h3>
            <p class="th-approval-modal__hint">
                {{ t('teamhub', 'Briefly explain your decision — this becomes part of the audit trail.') }}
            </p>
            <div class="th-approval-modal__field">
                <label class="th-approval-modal__label">
                    {{ t('teamhub', 'Reason') }} <span class="th-approval-modal__required">*</span>
                </label>
                <textarea
                    ref="reasonInput"
                    v-model="reason"
                    class="th-approval-modal__textarea"
                    :placeholder="t('teamhub', 'Your rationale…')"
                    rows="3"
                    maxlength="500"
                    :aria-label="t('teamhub', 'Approval reason')" />
                <span class="th-approval-modal__counter">{{ reason.length }} / 500</span>
            </div>
            <div class="th-approval-modal__actions">
                <NcButton
                    variant="primary"
                    :disabled="!reason.trim() || saving"
                    class="th-approval-modal__btn--approve"
                    @click="submit('approve')">
                    <template #icon><CheckCircleIcon :size="16" /></template>
                    {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Approve') }}
                </NcButton>
                <NcButton
                    variant="secondary"
                    :disabled="!reason.trim() || saving"
                    class="th-approval-modal__btn--deny"
                    @click="submit('deny')">
                    <template #icon><CloseCircleIcon :size="16" /></template>
                    {{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Deny') }}
                </NcButton>
                <!-- v3.74.10 — Schedule approver meeting. Shown only when the
                     parent enables it (Calendar configured for the team). -->
                <NcButton
                    v-if="canScheduleMeeting"
                    variant="secondary"
                    :disabled="saving"
                    :title="t('teamhub', 'Open the meeting wizard pre-filled with the other approvers in this category so you can discuss the proposal together before deciding.')"
                    @click="$emit('schedule-meeting', decision)">
                    <template #icon><CalendarPlusIcon :size="16" /></template>
                    <!-- TRANSLATORS: short button label; hover-tooltip explains the action -->
                    {{ t('teamhub', 'Schedule meeting') }}
                </NcButton>
                <NcButton variant="tertiary" @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { translate as t }  from '@nextcloud/l10n'
import { NcModal, NcButton } from '@nextcloud/vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import CalendarPlusIcon from 'vue-material-design-icons/CalendarPlus.vue'

export default {
    name: 'DecisionApprovalModal',
    components: { NcModal, NcButton, CheckCircleIcon, CloseCircleIcon, CalendarPlusIcon },

    props: {
        open:     { type: Boolean, default: false },
        decision: { type: Object,  default: () => ({}) },
        saving:   { type: Boolean, default: false },
        // v3.74.10 — when true, show "Schedule meeting" in the action row.
        // Parent passes true only when the team has Calendar configured.
        canScheduleMeeting: { type: Boolean, default: false },
    },

    emits: ['close', 'approve', 'deny', 'schedule-meeting'],

    data() {
        return { reason: '' }
    },

    watch: {
        open(v) {
            if (v) {
                this.reason = ''
                this.$nextTick(() => this.$refs.reasonInput?.focus())
            }
        },
    },

    methods: {
        t,
        submit(action) {
            if (!this.reason.trim()) return
            this.$emit(action, { decision: this.decision, reason: this.reason.trim() })
        },
    },
}
</script>

<style scoped>
.th-approval-modal { padding: 20px 24px 24px; display: flex; flex-direction: column; gap: 12px; }
.th-approval-modal__title { margin: 0; font-size: var(--th-font-heading); font-weight: 700; color: var(--color-main-text); }
.th-approval-modal__hint { margin: 0; font-size: var(--th-font-meta); color: var(--color-text-maxcontrast); }
.th-approval-modal__field { display: flex; flex-direction: column; gap: 4px; }
.th-approval-modal__label { font-size: 13px; font-weight: 600; color: var(--color-main-text); }
.th-approval-modal__required { color: var(--color-error); }
/* v3.100.17: expanded from the condensed single-line rule (gui.md § 13)
   so each property lives on its own line, and added an explicit
   :focus-visible ring so keyboard users get a visible focus indicator
   (the previous rule stripped outline and relied on border-color alone).
   font-size stays at 13px — the sibling __label is also 13px; a design
   pass that maps 13px to a token needs to update both together. */
.th-approval-modal__textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--color-border-dark);
    border-radius: var(--border-radius);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 13px;
    resize: vertical;
    outline: none;
}
.th-approval-modal__textarea:focus {
    border-color: var(--color-primary-element);
}
.th-approval-modal__textarea:focus-visible {
    border-color: var(--color-primary-element);
    box-shadow: 0 0 0 2px var(--color-primary-element);
}
.th-approval-modal__counter { font-size: var(--th-font-micro); color: var(--color-text-maxcontrast); text-align: right; }
.th-approval-modal__actions { display: flex; gap: 8px; flex-wrap: wrap; }
/* v3.100.16: local override of --color-primary-element so NcButton's
   primary styling picks up success (approve) / error (deny) fills.
   Previously used raw hex — now points at the canonical NC tokens so
   both buttons follow the theme (light/dark/branded). Also overrides
   the -text token so the label contrast survives the fill swap. */
.th-approval-modal__btn--approve {
    --color-primary-element: var(--color-success) !important;
    --color-primary-element-hover: var(--color-success-hover) !important;
    --color-primary-element-text: var(--color-success-text) !important;
}
.th-approval-modal__btn--deny {
    --color-primary-element: var(--color-error) !important;
    --color-primary-element-hover: var(--color-error-hover) !important;
    --color-primary-element-text: var(--color-error-text) !important;
}
</style>
