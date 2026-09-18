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
                :share-mode="shareMode"
                :submit-disabled="!canSubmit"
                class="th-compose-modal__form"
                @submitted="onSubmitted"
                @cancel="$emit('close')">
                <!-- v4.5.44 — the lifecycle beside the fields being filled in.
                     Only `create` is live here: nothing has been proposed yet,
                     so every later step is genuinely upcoming regardless of
                     which handling option is chosen below. -->
                <template #aside>
                    <DecisionProgressRail :active="['create']" />
                </template>

                <!-- v4.5.42 — rendered at the bottom of the form, immediately
                     above Submit. "How should this be handled" is a question
                     about the finished proposal, so it belongs after it.

                     A radiogroup rather than a dropdown: three options with
                     visibly different outcomes should be readable at a glance,
                     and each carries a line saying what happens next. -->
                <template #before-actions>
                    <!-- v4.5.46 — one row of radios, and helper text only for
                         the chosen one. Three permanently-visible descriptions
                         were ~90px of modal explaining two options the user had
                         already decided against. The text is unchanged; it is
                         shown when it is relevant instead of always. -->
                    <fieldset class="th-compose-modal__share">
                        <legend class="th-compose-modal__share-legend">
                            {{ t('teamhub', 'How should this proposal be handled?') }}
                        </legend>

                        <div class="th-compose-modal__share-row">
                            <label
                                v-for="option in shareOptions"
                                :key="option.value"
                                class="th-compose-modal__share-option"
                                :class="{ 'th-compose-modal__share-option--active': shareMode === option.value }">
                                <input
                                    v-model="shareMode"
                                    type="radio"
                                    name="th-share-mode"
                                    :value="option.value"
                                    :disabled="busy">
                                <span class="th-compose-modal__share-label">{{ option.label }}</span>
                            </label>
                        </div>

                        <!-- aria-live so a screen reader hears the consequence
                             change when the selection does; it is the only part
                             of the fieldset that moves. -->
                        <p class="th-compose-modal__share-desc" aria-live="polite">
                            {{ selectedShareOption.description }}
                        </p>
                    </fieldset>

                    <!-- People picker — only for the selected-audience mode -->
                    <div v-if="shareMode === 'selected'" class="th-compose-modal__people">
                        <label for="th-compose-people" class="th-compose-modal__people-label">
                            {{ t('teamhub', 'Discuss with') }}
                            <span class="th-compose-modal__required" aria-hidden="true">*</span>
                        </label>
                        <NcSelect
                            id="th-compose-people"
                            v-model="selectedPeople"
                            :options="memberOptions"
                            :multiple="true"
                            :close-on-select="false"
                            :disabled="busy"
                            label="displayName"
                            :placeholder="t('teamhub', 'Pick team members')" />
                        <p class="th-compose-modal__people-hint">
                            {{ t('teamhub', 'Only these people will see the proposal while it is open. Everyone sees it once you finalize it.') }}
                        </p>
                    </div>

                    <p v-if="shareMode === 'team' && !teamHasTalk" class="th-compose-modal__warning" role="alert">
                        {{ t('teamhub', 'This team has no Talk conversation, so the proposal cannot be posted for discussion. Connect Talk first, or choose another option.') }}
                    </p>
                </template>
            </PostMessageForm>
        </div>
    </NcModal>
</template>

<script>
/**
 * ComposeDecisionModal — the single entry point for proposing a decision
 * (v4.5.42; before that the message stream had a second, differently-behaving
 * one).
 *
 * Opened by:
 *   - TeamDecisionsView "Propose decision" toolbar button
 *   - DecisionsWidget "Propose decision" header button
 *
 * ## The three ways a proposal opens
 *
 * The modal owns the choice; PostMessageForm only turns it into `autoFinalize`.
 *
 *   immediate — finalized on creation, straight to the approvers. What the
 *               modal has always done.
 *   selected  — stays **open**, and gets a Talk group conversation with the
 *               people the proposer picked. Only they can see it until it is
 *               finalized.
 *   team      — stays **open**, and gets posted into the team conversation so
 *               anybody can respond. Visible to the whole team, which is what
 *               an open proposal has always been.
 *
 * ## Two requests, and why it is not one
 *
 * Creating the proposal and attaching a Talk surface are separate calls
 * because the second needs the decision id the first returns. The Talk step is
 * best-effort by design: if it fails, the proposal still exists, still open
 * and still editable — the proposer just has no conversation attached, and is
 * told so. Rolling the proposal back on a Talk failure would throw away work
 * the user has already done.
 *
 * Emits:
 *   - close            — cancelled or finished
 *   - decision-created — payload is the new message object carrying `decision`
 */
import { translate as t } from '@nextcloud/l10n'
import { NcModal, NcSelect } from '@nextcloud/vue'
import { showError, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { mapState } from 'vuex'
import PostMessageForm from './PostMessageForm.vue'
import DecisionProgressRail from './DecisionProgressRail.vue'

export default {
    name: 'ComposeDecisionModal',

    components: { NcModal, NcSelect, PostMessageForm, DecisionProgressRail },

    props: {
        open: { type: Boolean, default: false },
    },

    emits: ['close', 'decision-created'],

    data() {
        return {
            shareMode: 'immediate',
            selectedPeople: [],
            busy: false,
        }
    },

    computed: {
        // `currentTeamId` is state; `currentTeam` is a getter, so it does not
        // belong in mapState — the id is all this modal needs anyway.
        ...mapState(['allEffectiveMembers', 'currentTeamId', 'resources', 'currentUser']),

        /**
         * Evaluated in a computed rather than at module scope so `t()` runs
         * after Nextcloud's l10n bundle has loaded — the same reason
         * MyWorkItemRow builds its snooze presets this way.
         */
        shareOptions() {
            return [
                {
                    value: 'immediate',
                    // TRANSLATORS: option label — the proposal is complete and goes straight to the approvers
                    label: t('teamhub', 'Finalize now'),
                    description: t('teamhub', 'The proposal is ready. It goes straight to the category approvers for a decision.'),
                },
                {
                    value: 'selected',
                    // TRANSLATORS: short radio label — discuss privately with named colleagues before finalizing
                    label: t('teamhub', 'Discuss privately'),
                    description: t('teamhub', 'Opens a Talk conversation with the people you pick. Only they can see the proposal until you finalize it.'),
                },
                {
                    value: 'team',
                    // TRANSLATORS: short radio label — discuss openly with the whole team before finalizing
                    label: t('teamhub', 'Discuss with team'),
                    description: t('teamhub', 'Posts the proposal in the team conversation so any member can respond. You finalize it when the discussion is done.'),
                },
            ]
        },

        /** The one whose helper text is on screen. Never undefined — shareMode is seeded from the list. */
        selectedShareOption() {
            return this.shareOptions.find(o => o.value === this.shareMode) || this.shareOptions[0]
        },

        /** Team members, minus the proposer — you are already in the room. */
        memberOptions() {
            const me = this.currentUser?.uid
            return (this.allEffectiveMembers || [])
                .filter(m => m.userId && m.userId !== me)
                .map(m => ({ userId: m.userId, displayName: m.displayName || m.userId }))
        },

        teamHasTalk() {
            return !!this.resources?.talk?.token
        },

        canSubmit() {
            if (this.shareMode === 'selected') {
                return this.selectedPeople.length > 0
            }
            if (this.shareMode === 'team') {
                return this.teamHasTalk
            }
            return true
        },
    },

    watch: {
        // A modal reopened after a previous proposal must not inherit the last
        // choice — the default is the safe one, and a stale people list would
        // silently restrict a proposal the user meant to be open.
        open(isOpen) {
            if (isOpen) {
                this.shareMode = 'immediate'
                this.selectedPeople = []
                this.busy = false
            }
        },
    },

    methods: {
        t,

        async onSubmitted(payload) {
            const decisionId = payload?.decision?.id ?? null

            if (this.shareMode === 'immediate' || !decisionId) {
                this.$emit('decision-created', payload)
                this.$emit('close')
                return
            }

            this.busy = true
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/decisions/${decisionId}/share`),
                    {
                        mode: this.shareMode,
                        userIds: this.selectedPeople.map(p => p.userId),
                    }
                )

                // The proposal exists either way; only the conversation may be
                // missing. Say which happened rather than reporting a generic
                // success or a generic failure.
                // v4.5.46 — the "does not support threads" branch is gone. It
                // fired on every instance and was wrong on all of them: a Talk
                // thread is a message somebody has replied to, so posting the
                // message is the whole job. See TalkService::startProposalThread.
                if (data?.share && data.share.ok === false) {
                    showWarning(t('teamhub', 'Proposal created, but the Talk conversation could not be started: {error}', {
                        error: data.share.error || t('teamhub', 'unknown error'),
                    }))
                }

                this.$emit('decision-created', { ...payload, decision: data?.decision ?? payload?.decision })
            } catch (e) {
                // v4.5.46 — quote the correlation id when the server sends one
                // (unexpected 500s only). It turns "I get a server error" into
                // a string that finds the stack trace in the NC log.
                const data = e?.response?.data
                const msg = data?.ref
                    ? `${data.error || t('teamhub', 'Server error')} (ref ${data.ref})`
                    : data?.error
                showError(msg
                    ? t('teamhub', 'Proposal created, but sharing it failed: {error}', { error: msg })
                    : t('teamhub', 'Proposal created, but sharing it failed'))
                this.$emit('decision-created', payload)
            } finally {
                this.busy = false
                this.$emit('close')
            }
        },
    },
}
</script>

<style scoped>
/* v4.5.46 — the whole modal on a laptop screen without scrolling.
   Spacing is on an 8/16 grid; the previous 20/24 padding and 12px gaps were
   the single biggest contributor to the height. */
.th-compose-modal {
    padding: 16px 16px 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 85vh;
    overflow-y: auto;
}

.th-compose-modal__title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--color-main-text);
}

.th-compose-modal__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: 1.4;
}

/* The action row sticks to the bottom of the scroll container, so Submit is
   reachable without scrolling past a long proposal. The negative margin makes
   the bar span the modal's padding rather than sitting in a gutter. */
.th-compose-modal :deep(.post-form__actions) {
    position: sticky;
    bottom: 0;
    z-index: 2;
    margin: 0 -16px;
    padding: 8px 16px;
    background: var(--color-main-background);
    border-top: 1px solid var(--color-border);
}

/* Share-mode chooser ---------------------------------------------------- */

.th-compose-modal__share {
    display: flex;
    flex-direction: column;
    gap: 6px;
    /* Sits inside PostMessageForm's slot now, so it owns its own top spacing
       and a rule separating it from the fields above. */
    margin: 8px 0 0;
    padding: 12px 0 0;
    border: none;
    border-top: 1px solid var(--color-border);
}

.th-compose-modal__share-legend {
    padding: 0 0 4px;
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
}

/* v4.5.46 — one row; wraps rather than scrolling on a narrow modal. */
.th-compose-modal__share-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* v4.5.47 — the pills were ~44px tall. Two things did it, neither of them
   the padding: NC's global `input[type=radio]` reserves a 24px box with its
   own margins, and the label inherited the form's default line-height. The
   radio is sized down and both are pinned, so the pill is as tall as its
   text plus 6px either side and no taller. */
.th-compose-modal__share-option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    min-height: 0;
    line-height: 1.2;
    border: 2px solid var(--color-border);
    border-radius: var(--th-radius-pill);
    cursor: pointer;
}

.th-compose-modal__share-option input[type='radio'] {
    /* Six locks, same reasoning as SKILLS.md § UI shapes: NC's global input
       rule sets min-height and margins that otherwise set the pill's height. */
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 14px;
    height: 14px;
    min-width: 14px;
    min-height: 14px;
    max-width: 14px;
    max-height: 14px;
    margin: 0;
    padding: 0;
}

.th-compose-modal__share-option:hover {
    background: var(--color-background-hover);
}

/* Light tint + a dark border for the chosen option — the multi-choice pattern
   from SKILLS.md, not the full-saturation state tint. The native radio stays
   visible, so selection is never carried by colour alone (WCAG 1.4.1). */
.th-compose-modal__share-option--active {
    background: var(--color-primary-element-light);
    border-color: var(--color-primary-element);
}

.th-compose-modal__share-option:focus-within {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 1px;
}

.th-compose-modal__share-label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-medium);
    line-height: 1.2;
    color: var(--color-main-text);
    white-space: nowrap;
}

.th-compose-modal__share-desc {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    line-height: var(--th-line-height-body);
}

/* People picker --------------------------------------------------------- */

.th-compose-modal__people {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
}

.th-compose-modal__people-label {
    font-size: var(--th-font-meta);
    font-weight: var(--th-font-weight-semibold);
    color: var(--color-main-text);
}

.th-compose-modal__required {
    color: var(--color-error-text);
}

.th-compose-modal__people-hint,
.th-compose-modal__warning {
    margin: 0;
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
    color: var(--color-text-maxcontrast);
}

.th-compose-modal__warning {
    color: var(--color-warning-text);
}

.th-compose-modal__form {
    /* The PostMessageForm has its own padding/background suited for inline use.
       Inside the modal we let it flow naturally and rely on the modal padding. */
    background: transparent;
}
</style>
