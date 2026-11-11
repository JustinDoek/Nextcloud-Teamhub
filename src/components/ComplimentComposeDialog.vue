<template>
    <!-- v4.13.2 — opened from the Compliment entry in a person's contacts
         menu (DESIGN §2.186). NcDialog rather than a bare NcModal: it owns
         the heading, the padding and the button row, and CLAUDE.md makes it
         the default for a dialog. -->
    <NcDialog
        :open="open"
        :name="t('teamhub', 'Compliment {name}', { name: target.displayName })"
        size="normal"
        @update:open="$emit('update:open', $event)">

        <div class="th-compliment-compose">
            <div class="th-compliment-compose__who">
                <NcAvatar
                    :user="target.uid"
                    :display-name="target.displayName"
                    :size="AVATAR_LG"
                    :hide-status="true"
                    :disable-menu="true" />
                <div class="th-compliment-compose__who-text">
                    <span class="th-compliment-compose__name">{{ target.displayName }}</span>
                    <span class="th-compliment-compose__hint">
                        {{ t('teamhub', 'They will see this the next time they open TeamHub.') }}
                    </span>
                </div>
            </div>

            <!-- The note is what makes a compliment personal rather than a
                 counter going up (Justin, 2026-10-16), so Send stays disabled
                 until there is one. The API accepts an empty note so an older
                 client is never refused; the UI is where the rule lives. -->
            <NcTextArea
                v-model="note"
                class="th-compliment-compose__note"
                :label="t('teamhub', 'Note')"
                :placeholder="t('teamhub', 'Say what they did well')"
                :maxlength="MAX_NOTE_LENGTH"
                :rows="4" />

            <p class="th-compliment-compose__count" aria-live="polite">
                <!-- TRANSLATORS: N is the number of characters still available in the note -->
                {{ n('teamhub', '%n character left', '%n characters left', remaining, { n: remaining }) }}
            </p>

            <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
        </div>

        <template #actions>
            <NcButton variant="tertiary" :disabled="sending" @click="$emit('update:open', false)">
                {{ t('teamhub', 'Cancel') }}
            </NcButton>
            <NcButton variant="primary" :disabled="sending || !hasNote" @click="send">
                <template #icon>
                    <NcLoadingIcon v-if="sending" :size="ICON_BODY" />
                    <PartyPopper v-else :size="ICON_BODY" aria-hidden="true" />
                </template>
                {{ t('teamhub', 'Send compliment') }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar, NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'

import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import { AVATAR_LG, ICON_BODY } from '../constants/uiTokens.js'

/** Mirrors ComplimentService::MAX_NOTE_LENGTH and the column's length. */
const MAX_NOTE_LENGTH = 500

export default {
    name: 'ComplimentComposeDialog',

    components: { NcAvatar, NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextArea, PartyPopper },

    props: {
        open: { type: Boolean, default: false },
        /** { uid, displayName } of the person being complimented. */
        target: { type: Object, required: true },
        /** The team whose page this was sent from; '' when there is none. */
        teamId: { type: String, default: '' },
    },

    emits: ['update:open', 'sent'],

    data() {
        return {
            AVATAR_LG,
            ICON_BODY,
            MAX_NOTE_LENGTH,
            note: '',
            sending: false,
            error: null,
        }
    },

    computed: {
        remaining() {
            return Math.max(0, MAX_NOTE_LENGTH - (this.note || '').length)
        },

        hasNote() {
            return (this.note || '').trim() !== ''
        },
    },

    watch: {
        /**
         * A fresh dialog every time. Without this, cancelling and
         * complimenting somebody else would hand them the first person's
         * half-written note.
         */
        open(isOpen) {
            if (isOpen) {
                this.note = ''
                this.error = null
                this.sending = false
            }
        },
    },

    methods: {
        t,
        n,

        async send() {
            this.sending = true
            this.error = null
            try {
                await axios.post(generateUrl('/apps/teamhub/api/v1/compliments'), {
                    to: this.target.uid,
                    note: this.note,
                    teamId: this.teamId || '',
                })
                showSuccess(t('teamhub', 'Compliment sent to {name}', { name: this.target.displayName }))
                this.$emit('sent', this.target.uid)
                this.$emit('update:open', false)
            } catch (e) {
                // The server's refusals are all actionable by the sender
                // (not on a shared team, themselves, too many today), so the
                // sentence it sends is shown rather than a generic failure.
                const msg = e?.response?.data?.error || ''
                this.error = msg
                    ? t('teamhub', 'Could not send the compliment: {error}', { error: msg })
                    : t('teamhub', 'Could not send the compliment')
            } finally {
                this.sending = false
            }
        },
    },
}
</script>

<style scoped>
.th-compliment-compose {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
}

.th-compliment-compose__who {
    display: flex;
    align-items: center;
    gap: var(--th-space-md);
}

.th-compliment-compose__who-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.th-compliment-compose__name {
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.th-compliment-compose__hint {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.th-compliment-compose__count {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    text-align: end;
}
</style>
