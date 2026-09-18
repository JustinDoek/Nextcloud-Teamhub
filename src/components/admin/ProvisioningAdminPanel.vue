<template>
    <section class="prov-admin">
        <div class="prov-admin__head">
            <h2 class="prov-admin__title">{{ t('teamhub', 'Workspace provisioning') }}</h2>
            <NcButton variant="tertiary" :disabled="loading" @click="load">
                <template #icon><RefreshIcon :size="ICON_TOOLBAR" /></template>
                {{ t('teamhub', 'Refresh') }}
            </NcButton>
        </div>
        <p class="prov-admin__desc">
            {{ t('teamhub', 'Workspaces created from the OpenProject Workspace template, newest first. An operation that stopped can be retried, continued or rolled back from here; it runs as the person who started it.') }}
        </p>

        <div v-if="loading && !operations.length" class="prov-admin__state">
            <NcLoadingIcon :size="ICON_NAV" />
        </div>
        <p v-else-if="error" class="prov-admin__error" role="alert">{{ error }}</p>
        <p v-else-if="!operations.length" class="prov-admin__state">{{ t('teamhub', 'No workspace has been provisioned yet.') }}</p>

        <table v-else class="prov-admin__table">
            <thead>
                <tr>
                    <th scope="col">{{ t('teamhub', 'Workspace') }}</th>
                    <th scope="col">{{ t('teamhub', 'Status') }}</th>
                    <th scope="col">{{ t('teamhub', 'Started by') }}</th>
                    <th scope="col">{{ t('teamhub', 'Updated') }}</th>
                    <th scope="col"><span class="prov-admin__sr">{{ t('teamhub', 'Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="op in operations" :key="op.id">
                    <td>
                        <span class="prov-admin__name">{{ op.name || '—' }}</span>
                        <code class="prov-admin__key">#{{ op.id }} · {{ op.mode }}</code>
                    </td>
                    <td>
                        <span class="prov-admin__badge" :class="'prov-admin__badge--' + op.status">{{ operationStatusLabel(op.status) }}</span>
                        <span v-if="op.stalled" class="prov-admin__stalled">{{ t('teamhub', 'stalled') }}</span>
                    </td>
                    <td>{{ op.createdBy }}</td>
                    <td>{{ new Date(op.updatedAt * 1000).toLocaleString() }}</td>
                    <td class="prov-admin__row-actions">
                        <NcButton variant="tertiary" @click="open(op)">
                            {{ t('teamhub', 'Details') }}
                        </NcButton>
                    </td>
                </tr>
            </tbody>
        </table>

        <NcModal v-if="selected" :name="t('teamhub', 'Workspace setup')" size="normal" @close="close">
            <div class="prov-admin__modal">
                <h3 class="prov-admin__modal-title">{{ selected.name || t('teamhub', 'Workspace setup') }}</h3>
                <div v-if="detailLoading && !detail" class="prov-admin__state"><NcLoadingIcon :size="ICON_NAV" /></div>
                <p v-else-if="detailError" class="prov-admin__error" role="alert">{{ detailError }}</p>
                <ProvisioningProgress
                    v-else
                    :state="detail"
                    :can-act="true"
                    :admin="true"
                    :busy="busy"
                    @retry="retry"
                    @continue="pump"
                    @rollback="askRollback" />
                <div class="prov-admin__modal-actions">
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
                <NcButton variant="tertiary" :disabled="busy" @click="rollbackAsk = false">{{ t('teamhub', 'Cancel') }}</NcButton>
                <NcButton variant="error" :disabled="busy" @click="rollback">{{ t('teamhub', 'Remove') }}</NcButton>
            </template>
        </NcDialog>
    </section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ProvisioningProgress from '../ProvisioningProgress.vue'
import { ICON_NAV, ICON_TOOLBAR } from '../../constants/uiTokens.js'
import { classifyError } from '../../lib/openProject.js'
import { isActive, operationStatusLabel, pumpOperation, stepLabel } from '../../lib/provisioning.js'

/**
 * ProvisioningAdminPanel (v4.9.6, Phase 2) — Admin → TeamHub → Maintenance:
 * every provisioning operation on the instance, with the same details and
 * actions the creator has. An administrator's retry, continue or rollback
 * runs as the creator (the service impersonates them, audited) — nothing
 * is made in the administrator's name.
 */
export default {
    name: 'ProvisioningAdminPanel',

    components: { NcButton, NcDialog, NcLoadingIcon, NcModal, ProvisioningProgress, RefreshIcon },

    data() {
        return {
            operations: [],
            loading: false,
            error: '',
            selected: null,
            detail: null,
            detailLoading: false,
            detailError: '',
            busy: false,
            rollbackAsk: false,
            rollbackNeeds: [],
            ICON_NAV,
            ICON_TOOLBAR,
        }
    },

    computed: {
        rollbackMessage() {
            return this.rollbackNeeds.length
                ? t('teamhub', 'These parts were created for this workspace and may already hold content: {parts}. Removing the team removes them too. The OpenProject project is never deleted by TeamHub.', { parts: this.rollbackNeeds.map(stepLabel).join(', ') })
                : t('teamhub', 'The team and the resources created for it are removed. Linked resources and the OpenProject project stay.')
        },
    },

    mounted() {
        this.load()
    },

    methods: {
        t,
        operationStatusLabel,

        url(path) {
            return generateUrl('/apps/teamhub/api/v1' + path)
        },

        async load() {
            this.loading = true
            this.error = ''
            try {
                const { data } = await axios.get(this.url('/admin/provisioning'))
                this.operations = Array.isArray(data.operations) ? data.operations : []
            } catch (e) {
                this.error = classifyError(e).message
            } finally {
                this.loading = false
            }
        },

        async open(op) {
            this.selected = op
            this.detail = null
            await this.loadDetail()
        },

        close() {
            this.selected = null
            this.detail = null
            this.load()
        },

        async loadDetail() {
            this.detailLoading = true
            this.detailError = ''
            try {
                const { data } = await axios.get(this.url('/provisioning/' + this.selected.id))
                this.detail = data
            } catch (e) {
                this.detailError = classifyError(e).message
            } finally {
                this.detailLoading = false
            }
        },

        async pump() {
            if (this.busy || !this.selected) return
            this.busy = true
            try {
                await pumpOperation(
                    async () => (await axios.post(this.url('/provisioning/' + this.selected.id + '/run'))).data,
                    state => { this.detail = state },
                )
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },

        async retry(stepKey) {
            if (this.busy || !this.selected) return
            this.busy = true
            try {
                const { data } = await axios.post(this.url('/provisioning/' + this.selected.id + '/retry'), { step: stepKey })
                this.detail = data
            } catch (e) {
                showError(classifyError(e).message)
                this.busy = false
                return
            }
            this.busy = false
            if (isActive(this.detail?.status)) {
                await this.pump()
            }
        },

        async askRollback() {
            if (this.busy || !this.selected) return
            this.busy = true
            try {
                const { data } = await axios.post(this.url('/provisioning/' + this.selected.id + '/rollback'), { confirm: false })
                if (data.status === 'rolled_back') {
                    this.detail = data
                    showSuccess(t('teamhub', 'Removed what was created.'))
                    return
                }
                this.rollbackNeeds = data.status === 'confirm_required' && Array.isArray(data.needsConfirm) ? data.needsConfirm : []
                this.rollbackAsk = true
            } catch (e) {
                showError(classifyError(e).message)
            } finally {
                this.busy = false
            }
        },

        async rollback() {
            this.rollbackAsk = false
            this.busy = true
            try {
                const { data } = await axios.post(this.url('/provisioning/' + this.selected.id + '/rollback'), { confirm: true })
                this.detail = data
                if (data.status === 'rolled_back') {
                    showSuccess(t('teamhub', 'Removed what was created.'))
                } else {
                    showError(t('teamhub', 'The rollback did not finish. See the details for what remains.'))
                    await this.loadDetail()
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
.prov-admin {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    margin-top: var(--th-space-xl);
    padding-top: var(--th-space-lg);
    border-top: 1px solid var(--color-border);
}

.prov-admin__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--th-space-sm);
}

.prov-admin__title {
    margin: 0;
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-semibold);
}

.prov-admin__desc,
.prov-admin__state {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-admin__error {
    margin: 0;
    color: var(--color-error-text);
}

.prov-admin__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.prov-admin__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--th-font-meta);
}

.prov-admin__table th,
.prov-admin__table td {
    padding: var(--th-space-sm);
    border-bottom: 1px solid var(--color-border);
    text-align: start;
    vertical-align: middle;
}

.prov-admin__name {
    display: block;
    font-weight: var(--th-font-weight-medium);
}

.prov-admin__key {
    font-size: var(--th-font-micro);
    color: var(--color-text-maxcontrast);
}

.prov-admin__badge {
    display: inline-block;
    padding: 0 var(--th-space-sm);
    border-radius: var(--th-radius-chip);
    background: var(--color-background-dark);
    color: var(--color-main-text);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-medium);
}

.prov-admin__badge--completed {
    background: var(--color-success);
    color: var(--color-success-text);
}

.prov-admin__badge--attention {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.prov-admin__badge--failed {
    background: var(--color-error);
    color: var(--color-error-text);
}

.prov-admin__badge--running,
.prov-admin__badge--pending {
    background: var(--color-info);
    color: var(--color-info-text);
}

.prov-admin__stalled {
    margin-left: var(--th-space-xs);
    font-size: var(--th-font-micro);
    color: var(--color-warning-text);
}

.prov-admin__row-actions {
    text-align: end;
}

.prov-admin__modal {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
    padding: var(--th-space-xl);
}

.prov-admin__modal-title {
    margin: 0;
    font-size: var(--th-font-heading-lg);
    font-weight: var(--th-font-weight-semibold);
}

.prov-admin__modal-actions {
    display: flex;
    justify-content: flex-end;
}
</style>
