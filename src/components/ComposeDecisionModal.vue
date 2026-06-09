<template>
    <NcModal
        v-if="open"
        size="large"
        :name="t('teamhub', 'Propose a decision')"
        :close-on-click-outside="false"
        @close="$emit('close')">
        <div class="th-compose-modal">
            <h2 class="th-compose-modal__title">{{ t('teamhub', 'Propose a decision') }}</h2>
            <p class="th-compose-modal__hint">
                {{ t('teamhub', 'Fill in the question and supporting details. Your proposal will be saved as a markdown file in the team\'s .proposals folder, with any attachments linked to the decision.') }}
            </p>
            <PostMessageForm
                ref="form"
                force-decision
                class="th-compose-modal__form"
                @submitted="onSubmitted"
                @cancel="$emit('close')" />
        </div>
    </NcModal>
</template>

<script>
/**
 * ComposeDecisionModal — opens a focused modal that wraps PostMessageForm in
 * decision-only mode. Used by:
 *   - TeamDecisionsView "Propose decision" toolbar button
 *   - DecisionsWidget "Propose decision" header button
 *   - (future) iframe-embedded compose links
 *
 * The form's `forceDecision` prop hides the Message/Poll/Question selector
 * so users see only decision-relevant fields. Attachments are handled by
 * PostMessageForm exactly as in the inline stream form (they ride along on
 * the underlying message that backs the decision); no extra wiring needed.
 *
 * Emits:
 *   - close            — user clicked Cancel, close button, or backdrop
 *   - decision-created — proposal submitted successfully; payload is the
 *                        new message/decision object from the store action
 */
import { translate as t } from '@nextcloud/l10n'
import { NcModal }        from '@nextcloud/vue'
import PostMessageForm    from './PostMessageForm.vue'

export default {
    name: 'ComposeDecisionModal',

    components: { NcModal, PostMessageForm },

    props: {
        open: { type: Boolean, default: false },
    },

    emits: ['close', 'decision-created'],

    methods: {
        t,

        onSubmitted(payload) {
            // PostMessageForm emits 'submitted' on successful post. For a
            // decision, the payload is the new message object which carries
            // the embedded `decision` field. Pass it through so the parent
            // can select the new decision in the detail panel.
            this.$emit('decision-created', payload)
            this.$emit('close')
        },
    },
}
</script>

<style scoped>
.th-compose-modal {
    padding: 20px 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 80vh;
    overflow-y: auto;
}

.th-compose-modal__title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: var(--color-main-text);
}

.th-compose-modal__hint {
    margin: 0;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    line-height: 1.4;
}

.th-compose-modal__form {
    /* The PostMessageForm has its own padding/background suited for inline use.
       Inside the modal we let it flow naturally and rely on the modal padding. */
    background: transparent;
}
</style>
