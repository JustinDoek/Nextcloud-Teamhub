<template>
    <div>
        <div
            class="prov-banner"
            :class="'prov-banner--' + provisioning.status"
            role="status"
            aria-live="polite">
            <NcLoadingIcon v-if="active" :size="ICON_INLINE" />
            <AlertCircle v-else :size="ICON_INLINE" class="prov-banner__icon" aria-hidden="true" />
            <span class="prov-banner__text">{{ text }}</span>
            <!-- Raw <button>: the same chevron affordance as the warning strips
                 beside it (full-width row-button carve-out). Only for people
                 who may open the details; a member sees the sentence alone. -->
            <button
                v-if="canOpen"
                type="button"
                class="prov-banner__link"
                :aria-label="t('teamhub', 'Show setup progress')"
                :title="t('teamhub', 'Show setup progress')"
                @click="open">
                <ChevronRight :size="ICON_BODY" aria-hidden="true" />
            </button>
        </div>

        <NcModal v-if="showDetails" :name="t('teamhub', 'Workspace setup')" size="normal" @close="close">
            <div class="prov-banner__modal">
                <h3 class="prov-banner__modal-title">{{ t('teamhub', 'Workspace setup') }}</h3>
                <div v-if="loading && !state" class="prov-banner__loading">
                    <NcLoadingIcon :size="ICON_NAV" />
                </div>
                <p v-else-if="loadError" class="prov-banner__error" role="alert">{{ loadError }}</p>
                <ProvisioningProgress
                    v-else
                    :state="state"
                    :can-act="canAct"
                    :admin="isAdmin"
                    :busy="busy"
                    @retry="retry"
                    @continue="pump"
                    @rollback="askRollback" />
                <div class="prov-banner__modal-actions">
                    <NcButton variant="tertiary" @click="close">{{ t('teamhub', 'Close') }}</NcButton>
                </div>
            </div>
        </NcModal>

        <NcDialog
            v-if="rollbackAsk"
            :name="t('teamhub', 'Remove what was created?')"
            :message="rollbackMessage"
            size="small"
            @closing="rollbackAsk = false">
            <template #actions>
                <NcButton variant="tertiary" :disabled="busy" @click="rollbackAsk = false">
                    {{ t('teamhub', 'Cancel') }}
                </NcButton>
                <NcButton variant="error" :disabled="busy" @click="rollback(true)">
                    {{ t('teamhub', 'Remove') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ProvisioningProgress from './ProvisioningProgress.vue'
import { ICON_BODY, ICON_INLINE, ICON_NAV } from '../constants/uiTokens.js'
import { classifyError } from '../lib/openProject.js'
import { isActive, isUsable, pumpOperation, stepLabel } from '../lib/provisioning.js'

/**
 * ProvisioningBanner (v4.9.6, Phase 2) — the team page's "this workspace
 * is not finished" strip, in the Team info widget beside the other
 * warnings. Every member sees the sentence; the creator, an administrator
 * and the team's admins can open the details (the same
 * ProvisioningProgress the wizard shows) and, when they may, retry a step,
 * continue a stalled run or remove what was created.
 *
 * Reads `provisioning` from the layout bundle (store). The team is never
 * shown as ready while the operation is `failed` or `attention`.
 */
export default {
    name: 'ProvisioningBanner',

    components: { AlertCircle, ChevronRight, NcButton, NcDialog, NcLoadingIcon, NcModal, ProvisioningProgress },

    props: {
        provisioning: { type: Object, required: true },
        isTeamAdmin: { type: Boolean, default: false },
    },

    emits: ['changed'],

    data() {
        return {
            showDetails: false,
            state: null,
            loading: false,
            loadError: '',
            busy: false,
            rollbackAsk: false,
            rollbackNeeds: [],
            ICON_BODY,
            ICON_INLINE,
            ICON_NAV,
        }
    },

    computed: {
        uid() {
            return getCurrentUser()?.uid || ''
        },
        isAdmin() {
            return !!getCurrentUser()?.isAdmin
        },
        active() {
            return isActive(this.provisioning.status)
        },
        canOpen() {
            return this.isTeamAdmin || this.isAdmin || this.provisioning.createdBy === this.uid
        },
        canAct() {
            return this.isAdmin || this.provisioning.createdBy === this.uid
        },
        text() {
            const open = Array.isArray(this.provisioning.openSteps) ? this.provisioning.openSteps : []
            if (this.active) {
                return t('teamhub', 'This workspace is still being set up.')
            }
            if (this.provisioning.status === 'failed') {
                const first = open[0] ? stepLabel(open[0].key) : ''
                return first
                    ? t('teamhub', 'Workspace setup stopped at: {step}.', { step: first })
                    : t('teamhub', 'Workspace setup stopped before it finished.')
            }
            if (this.provisioning.status === 'attention') {
                return t('teamhub', 'Workspace setup finished with something to look at: {steps}.', { steps: open.map(s => stepLabel(s.key)).join(', ') })
            }
            if (this.provisioning.status === 'rolled_back') {
                return t('teamhub', 'Workspace setup was rolled back.')
            }
            return t('teamhub', 'Workspace setup is not finished.')
        },
        rollbackMessage() {
            return this.rollbackNeeds.length
                ? t('teamhub', 'These parts were created for this workspace and may already hold content: {parts}. Removing the team removes them too. The OpenProject project is never deleted by TeamHub.', { parts: this.rollbackNeeds.map(stepLabel).join(', ') })
                : t('teamhub', 'The team and the resources created for it are removed. Linked resources and the OpenProject project stay.')
        },
    },

    methods: {
        t,

        async open() {
            this.showDetails = true
            await this.load()
            if (this.canAct && isActive(this.state?.status) && this.state?.stalled) {
                // A stalled run: continue it while the person watches.
                this.pump()
            }
        },

        close() {
            this.showDetails = false
            if (this.state && (isUsable(this.state.status) || this.state.status === 'rolled_back')) {
                this.$emit('changed')
            }
        },

        async load() {
            this.loading = true
            this.loadError = ''
            try {
                const { data } = await axios.get(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioning.id}`))
                this.state = data
            } catch (e) {
                this.loadError = classifyError(e).message
            } finally {
                this.loading = false
            }
        },

        async pump() {
            if (this.busy) return
            this.busy = true
            try {
                await pumpOperation(
                    async () => (await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioning.id}/run`))).data,
                    state => { this.state = state },
                )
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },

        async retry(stepKey) {
            if (this.busy) return
            this.busy = true
            try {
                const { data } = await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioning.id}/retry`), { step: stepKey })
                this.state = data
                if (isActive(data.status)) {
                    this.busy = false
                    await this.pump()
                }
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },

        async askRollback() {
            if (this.busy) return
            this.busy = true
            try {
                const { data } = await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioning.id}/rollback`), { confirm: false })
                if (data.status === 'confirm_required') {
                    this.rollbackNeeds = Array.isArray(data.needsConfirm) ? data.needsConfirm : []
                } else if (data.status === 'rolled_back') {
                    this.state = data
                    showSuccess(t('teamhub', 'Removed what was created.'))
                    this.$emit('changed')
                    return
                } else {
                    this.rollbackNeeds = []
                }
                this.rollbackAsk = true
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },

        async rollback(confirm) {
            this.rollbackAsk = false
            this.busy = true
            try {
                const { data } = await axios.post(generateUrl(`/apps/teamhub/api/v1/provisioning/${this.provisioning.id}/rollback`), { confirm })
                if (data.status === 'rolled_back') {
                    showSuccess(t('teamhub', 'Removed what was created.'))
                    this.state = data
                    this.$emit('changed')
                } else {
                    showError(t('teamhub', 'The rollback did not finish. See the details for what remains.'))
                    await this.load()
                }
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },
    },
}
</script>

<style scoped>
.prov-banner {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    padding: var(--th-space-sm) var(--th-space-md);
    border-radius: var(--th-radius-control);
    background: var(--color-warning);
    color: var(--color-warning-text);
    font-size: var(--th-font-meta);
}

.prov-banner--failed {
    background: var(--color-error);
    color: var(--color-error-text);
}

.prov-banner--pending,
.prov-banner--running {
    background: var(--color-info);
    color: var(--color-info-text);
}

.prov-banner__icon {
    flex: 0 0 auto;
}

.prov-banner__text {
    flex: 1 1 auto;
    min-width: 0;
}

/* Raw <button>: chevron affordance matching the warning strips (row-button carve-out). */
.prov-banner__link {
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
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.prov-banner__link:hover {
    background: var(--color-background-hover);
}

.prov-banner__link:focus-visible {
    background: var(--color-background-hover);
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

.prov-banner__modal {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-xl);
}

.prov-banner__modal-title {
    margin: 0;
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-semibold);
}

.prov-banner__loading {
    display: flex;
    justify-content: center;
    padding: var(--th-space-xl);
}

.prov-banner__error {
    margin: 0;
    color: var(--color-error-text);
}

.prov-banner__modal-actions {
    display: flex;
    justify-content: flex-end;
}
</style>
