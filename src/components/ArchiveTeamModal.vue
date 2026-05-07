<template>
    <NcModal
        v-if="show"
        :name="t('teamhub', 'Archive and delete team')"
        size="normal"
        @close="$emit('close')">

        <div class="archive-modal">

            <h2 class="archive-modal__title">
                {{ t('teamhub', 'Archive and delete team') }}
            </h2>

            <!-- Mode-specific lead text -->
            <p v-if="mode === 'hard'" class="archive-modal__lead archive-modal__lead--danger">
                {{ t('teamhub', 'The team will be permanently deleted immediately after the archive is produced. This cannot be undone.') }}
            </p>
            <p v-else class="archive-modal__lead">
                <!-- TRANSLATORS: {n} is the number of grace days (30 or 60) before permanent deletion -->
                {{ n('teamhub',
                     'The team will be hidden immediately and permanently deleted in {n} day. Administrators can restore it before that deadline.',
                     'The team will be hidden immediately and permanently deleted in {n} days. Administrators can restore it before that deadline.',
                     graceDays,
                     { n: graceDays }) }}
            </p>

            <!-- Archive location -->
            <p class="archive-modal__detail">
                {{ archiveLocationText }}
            </p>

            <!-- Pseudonymize notice when enabled -->
            <div v-if="pseudonymized" class="archive-modal__notice" role="note">
                <span aria-hidden="true">🔒</span>
                {{ t('teamhub', 'User identifiers will be replaced with aliases in the archive (pseudonymized per organisation policy).') }}
            </div>

            <!-- Warning about member access -->
            <div class="archive-modal__warning" role="alert">
                <strong>{{ t('teamhub', 'Members will lose access to this team immediately.') }}</strong>
                {{ t('teamhub', 'Connected resources (Talk, Files, Calendar, Deck) are removed with the team.') }}
            </div>

            <!-- Error display -->
            <div v-if="error" class="archive-modal__error" role="alert">
                {{ error }}
            </div>

            <!-- Action buttons -->
            <div class="archive-modal__actions">
                <NcButton
                    :disabled="loading"
                    type="secondary"
                    @click="$emit('close')">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton
                    :disabled="loading"
                    :aria-label="t('teamhub', 'Confirm archiving and deleting this team')"
                    type="error"
                    @click="confirm">
                    <template v-if="loading" #icon>
                        <NcLoadingIcon :size="20" />
                    </template>
                    {{ loading ? t('teamhub', 'Archiving…') : t('teamhub', 'Archive and delete') }}
                </NcButton>
            </div>

        </div>
    </NcModal>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
    name: 'ArchiveTeamModal',

    components: {
        NcModal,
        NcButton,
        NcLoadingIcon,
    },

    props: {
        show: {
            type: Boolean,
            default: false,
        },
        teamId: {
            type: String,
            required: true,
        },
        /** Archive settings from GET /api/v1/admin/archive/settings */
        archiveSettings: {
            type: Object,
            default: () => ({}),
        },
    },

    emits: ['close', 'archived'],

    data() {
        return {
            loading: false,
            error: null,
        }
    },

    computed: {
        mode() {
            return this.archiveSettings.archiveMode || 'soft30'
        },
        graceDays() {
            if (this.mode === 'soft60') return 60
            if (this.mode === 'soft30') return 30
            return 0
        },
        archiveLocationText() {
            const loc = this.archiveSettings.archiveLocation || ''
            if (loc) {
                // TRANSLATORS: {location} is a Team Folder path or link like /f/150770
                return t('teamhub', 'An archive of all team data will be saved to {location}.', { location: loc })
            }
            return t('teamhub', 'An archive of all team data will be saved to your Files folder.')
        },
        pseudonymized() {
            return !!this.archiveSettings.anonymizeData
        },
    },

    methods: {
        t,
        n,

        async confirm() {
            this.loading = true
            this.error = null
            try {
                const url = generateUrl(`/apps/teamhub/api/v1/teams/${this.teamId}/archive`)
                const { data } = await axios.post(url)
                this.$emit('archived', data)
                this.$emit('close')
            } catch (err) {
                const status = err.response?.status
                if (status === 413) {
                    this.error = err.response?.data?.error
                        || t('teamhub', 'The team data is too large to archive. Contact your administrator to raise the size limit.')
                } else if (status === 409) {
                    this.error = t('teamhub', 'This team is already pending deletion.')
                } else {
                    this.error = err.response?.data?.error
                        || t('teamhub', 'Archive failed: {error}', { error: err.message })
                }
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.archive-modal {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 520px;
}

.archive-modal__title {
    font-size: 18px;
    font-weight: 500;
    margin: 0;
}

.archive-modal__lead {
    color: var(--color-text-maxcontrast);
    line-height: 1.5;
}

.archive-modal__lead--danger {
    color: var(--color-error);
    font-weight: 500;
}

.archive-modal__detail {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.archive-modal__notice {
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    padding: 10px 14px;
    font-size: 13px;
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.archive-modal__warning {
    background: var(--color-warning-bg, #fff3cd);
    border: 1px solid var(--color-warning-border, #ffc107);
    border-radius: var(--border-radius);
    padding: 10px 14px;
    font-size: 13px;
    line-height: 1.4;
}

.archive-modal__error {
    background: #ffebee;
    border: 2px solid #c62828;
    border-radius: var(--border-radius);
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #7f0000;
}

.archive-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
}
</style>
