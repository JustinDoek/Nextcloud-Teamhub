<template>
    <NcDialog
        v-if="open"
        :name="t('teamhub', 'Archive team')"
        :open="open"
        @update:open="onDialogUpdate">
        <div class="th-arch-policy">
            <div v-if="loading" class="th-arch-policy__loading">
                {{ t('teamhub', 'Checking archive policy') }}
            </div>

            <template v-else-if="policy && policy.dataLossWarning">
                <div class="th-arch-policy__alert" role="alert">
                    <AlertOctagon :size="32" aria-hidden="true" />
                    <div class="th-arch-policy__alert-body">
                        <h3>{{ t('teamhub', 'All project data will be lost.') }}</h3>
                        <p>
                            {{ t('teamhub', 'Your Nextcloud administrator has configured immediate hard deletion with no archive bundle. Continuing will permanently remove the team, its files, and every TeamHub record attached to it — nothing can be restored.') }}
                        </p>
                        <p>
                            {{ t('teamhub', 'If you want a recoverable copy, ask your Nextcloud administrator to enable archiving before continuing.') }}
                        </p>
                        <p v-if="closingArtifactPath">
                            {{ t('teamhub', 'The Closing artifact you generated will remain in the Files folder location it was written to; only the team itself is being removed.') }}
                        </p>
                    </div>
                </div>
                <div class="th-arch-policy__footer">
                    <NcButton variant="secondary" @click="$emit('update:open', false)">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton variant="error" @click="onConfirm">
                        {{ t('teamhub', 'Continue anyway') }}
                    </NcButton>
                </div>
            </template>

            <template v-else-if="policy">
                <p class="th-arch-policy__ok">
                    {{ policyDescription }}
                </p>
                <div class="th-arch-policy__footer">
                    <NcButton variant="secondary" @click="$emit('update:open', false)">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton variant="primary" @click="onConfirm">
                        {{ t('teamhub', 'Continue') }}
                    </NcButton>
                </div>
            </template>

            <div v-else-if="errorMessage" class="th-arch-policy__error" role="alert">
                {{ errorMessage }}
            </div>
        </div>
    </NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcDialog, NcButton } from '@nextcloud/vue'
import AlertOctagon from 'vue-material-design-icons/AlertOctagon.vue'
import { mapState } from 'vuex'

/**
 * ArchivePolicyWarningModal (v3.99.0).
 *
 * Fetches /project/closing/archive-policy on open. If the admin
 * configured archive-off + hard-delete (data will be lost), shows the
 * warning with Cancel / Continue anyway. Otherwise shows a short
 * description of what will happen (soft delete grace period, archive
 * bundle produced, …) with Cancel / Continue. Confirming emits
 * `confirm` — the parent handles the actual archive call.
 */
export default {
    name: 'ArchivePolicyWarningModal',
    components: { NcDialog, NcButton, AlertOctagon },
    props: {
        open: { type: Boolean, default: false },
        // Optional — shown in the warning as reassurance that the artifact
        // outlives the team even when data is lost.
        closingArtifactPath: { type: String, default: '' },
    },
    emits: ['update:open', 'confirm'],

    data() {
        return {
            loading: false,
            policy: null,
            errorMessage: '',
        }
    },

    computed: {
        ...mapState(['currentTeamId']),

        policyDescription() {
            if (!this.policy) return ''
            const mode = this.policy.archiveMode
            if (this.policy.archiveBeforeDelete) {
                if (mode === 'soft30') return t('teamhub', 'A full archive bundle will be produced, then the team will be soft-deleted with a 30-day grace period during which administrators can restore it.')
                if (mode === 'soft60') return t('teamhub', 'A full archive bundle will be produced, then the team will be soft-deleted with a 60-day grace period during which administrators can restore it.')
                return t('teamhub', 'A full archive bundle will be produced and the team will be permanently deleted immediately after.')
            }
            if (mode === 'soft30') return t('teamhub', 'The team will be soft-deleted with a 30-day grace period during which administrators can restore it. No archive bundle is produced.')
            if (mode === 'soft60') return t('teamhub', 'The team will be soft-deleted with a 60-day grace period during which administrators can restore it. No archive bundle is produced.')
            return t('teamhub', 'The team will be permanently deleted immediately. No archive bundle is produced.')
        },
    },

    watch: {
        open(isOpen) {
            if (isOpen) this.fetchPolicy()
        },
    },

    methods: {
        t,
        onDialogUpdate(val) { this.$emit('update:open', val) },
        onConfirm() { this.$emit('confirm') },

        async fetchPolicy() {
            this.loading = true
            this.errorMessage = ''
            this.policy = null
            try {
                const { data } = await axios.get(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/closing/archive-policy`),
                )
                this.policy = data
            } catch (e) {
                this.errorMessage = e?.response?.data?.error || e?.message || t('teamhub', 'Failed to read archive policy')
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.th-arch-policy {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 8px 4px 4px;
    min-width: 380px;
}
.th-arch-policy__loading {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}
.th-arch-policy__alert {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: var(--color-error);
    color: var(--color-error-text);
    border-radius: var(--border-radius);
}
.th-arch-policy__alert h3 {
    margin: 0 0 8px;
    font-size: 15px;
    font-weight: 700;
}
.th-arch-policy__alert p {
    margin: 0 0 8px;
    font-size: 13px;
    line-height: 1.5;
}
.th-arch-policy__alert p:last-child {
    margin-bottom: 0;
}
.th-arch-policy__ok {
    margin: 0;
    padding: 12px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
    font-size: 13px;
    line-height: 1.5;
    color: var(--color-main-text);
}
.th-arch-policy__error {
    padding: 8px 12px;
    background: var(--color-error);
    color: var(--color-error-text);
    border-radius: var(--border-radius);
    font-size: 13px;
}
.th-arch-policy__footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>
