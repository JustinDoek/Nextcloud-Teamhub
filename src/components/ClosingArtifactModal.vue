<template>
    <NcDialog
        v-if="open"
        :name="t('teamhub', 'Generate Closing artifact')"
        :open="open"
        @update:open="onDialogUpdate">
        <div class="th-closing">
            <template v-if="!result">
                <p class="th-closing__intro">
                    {{ t('teamhub', 'This will export the project\'s decisions, budget, time and milestones to a folder in this team\'s Files. The files are plain markdown so they stay readable long after the team is archived.') }}
                </p>
                <ul class="th-closing__list">
                    <li>{{ t('teamhub', 'Location: {folder} in this team\'s Files', { folder: 'Project Closing/' }) }}</li>
                    <li>{{ t('teamhub', 'Files written: summary.md, decisions.md, budget.md, time.md, milestones.md') }}</li>
                    <li>{{ t('teamhub', 'Regenerating overwrites the existing files. Nothing else in the team is changed.') }}</li>
                </ul>
                <p v-if="errorMessage" class="th-closing__error" role="alert">
                    {{ errorMessage }}
                </p>
                <div class="th-closing__footer">
                    <NcButton variant="secondary" :disabled="working" @click="$emit('update:open', false)">
                        {{ t('teamhub', 'Cancel') }}
                    </NcButton>
                    <NcButton
                        variant="primary"
                        :disabled="working"
                        @click="submit">
                        {{ working ? t('teamhub', 'Generating') : t('teamhub', 'Generate') }}
                    </NcButton>
                </div>
            </template>

            <template v-else>
                <p class="th-closing__success">
                    {{ t('teamhub', 'Closing artifact generated.') }}
                </p>
                <p class="th-closing__path">
                    <code>{{ result.filePath }}</code>
                </p>
                <div class="th-closing__footer">
                    <NcButton variant="primary" @click="$emit('update:open', false)">
                        {{ t('teamhub', 'Done') }}
                    </NcButton>
                </div>
            </template>
        </div>
    </NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcDialog, NcButton } from '@nextcloud/vue'
import { mapState } from 'vuex'

/**
 * ClosingArtifactModal (v3.99.0).
 *
 * Confirmation + progress + success state for POST /project/closing/generate.
 * Opened from the Compass Closing-phase "Generate the Closing artifact"
 * item. On success the modal shows the file path and emits
 * `generated` so TeamView can re-commit SET_PROJECT (updating
 * closing_artifact_at → the readiness item flips to done).
 */
export default {
    name: 'ClosingArtifactModal',
    components: { NcDialog, NcButton },
    props: {
        open: { type: Boolean, default: false },
    },
    emits: ['update:open', 'generated'],

    data() {
        return {
            working: false,
            errorMessage: '',
            result: null,
        }
    },

    computed: {
        ...mapState(['currentTeamId']),
    },

    watch: {
        open(isOpen) {
            if (isOpen) {
                this.working = false
                this.errorMessage = ''
                this.result = null
            }
        },
    },

    methods: {
        t,
        onDialogUpdate(val) {
            this.$emit('update:open', val)
        },

        async submit() {
            if (this.working || !this.currentTeamId) return
            this.working = true
            this.errorMessage = ''
            try {
                const { data } = await axios.post(
                    generateUrl(`/apps/teamhub/api/v1/teams/${this.currentTeamId}/project/closing/generate`),
                )
                this.result = data
                this.$emit('generated', data)
            } catch (e) {
                this.errorMessage = e?.response?.data?.error || e?.message || t('teamhub', 'Generation failed')
            } finally {
                this.working = false
            }
        },
    },
}
</script>

<style scoped>
.th-closing {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 8px 4px 4px;
    min-width: 380px;
}
.th-closing__intro,
.th-closing__success {
    margin: 0;
    color: var(--color-main-text);
    font-size: 13px;
    line-height: 1.5;
}
.th-closing__success {
    font-weight: 600;
}
.th-closing__list {
    margin: 0;
    padding-left: 18px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    line-height: 1.5;
}
.th-closing__error {
    padding: 6px 10px;
    background: var(--color-error);
    color: var(--color-error-text);
    border-radius: var(--border-radius);
    font-size: var(--th-font-meta);
    margin: 0;
}
.th-closing__path {
    margin: 0;
    padding: 6px 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius);
    font-size: var(--th-font-meta);
    word-break: break-all;
}
.th-closing__path code {
    font-family: monospace;
    color: var(--color-primary-element);
}
.th-closing__footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
}
</style>
