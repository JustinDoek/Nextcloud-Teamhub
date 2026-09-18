<template>
    <div class="prov-progress" :class="'prov-progress--' + (state?.status || 'pending')">
        <div class="prov-progress__head">
            <NcLoadingIcon v-if="active" :size="ICON_NAV" />
            <CheckCircle v-else-if="state?.status === 'completed'" :size="ICON_NAV" class="prov-progress__icon--ok" aria-hidden="true" />
            <AlertCircle v-else :size="ICON_NAV" class="prov-progress__icon--warn" aria-hidden="true" />
            <div class="prov-progress__head-text">
                <span class="prov-progress__title">{{ operationStatusLabel(state?.status) }}</span>
                <span v-if="state?.errorMessage && !active" class="prov-progress__subtitle">{{ state.errorMessage }}</span>
                <span v-else-if="state?.busy" class="prov-progress__subtitle">{{ t('teamhub', 'Another session is working on this; waiting for it.') }}</span>
                <span v-else-if="state?.stalled" class="prov-progress__subtitle">{{ t('teamhub', 'No progress for a while. It resumes on its own within a few minutes, or you can continue it now.') }}</span>
            </div>
        </div>

        <ol class="prov-progress__steps" :aria-label="t('teamhub', 'Setup steps')">
            <li v-for="row in rows" :key="row.key" class="prov-progress__step" :class="'prov-progress__step--' + row.status">
                <span class="prov-progress__step-mark" aria-hidden="true">
                    <NcLoadingIcon v-if="row.status === 'running'" :size="ICON_BODY" />
                    <CheckCircle v-else-if="row.status === 'completed'" :size="ICON_BODY" />
                    <MinusCircleOutline v-else-if="row.status === 'skipped' || row.status === 'rolled_back'" :size="ICON_BODY" />
                    <AlertCircle v-else-if="row.status === 'failed' || row.status === 'attention'" :size="ICON_BODY" />
                    <span v-else class="prov-progress__dot" />
                </span>
                <span class="prov-progress__step-body">
                    <span class="prov-progress__step-label">
                        {{ row.label }}
                        <span class="prov-progress__step-status">· {{ row.statusLabel }}</span>
                    </span>
                    <span v-if="row.errorMessage && (row.status === 'failed' || row.status === 'attention')" class="prov-progress__step-error">
                        {{ row.errorMessage }}
                    </span>
                    <span v-if="stepNote(row)" class="prov-progress__step-note">{{ stepNote(row) }}</span>
                </span>
                <span v-if="canAct && (row.status === 'failed' || row.status === 'attention') && row.retrySafe" class="prov-progress__step-action">
                    <NcButton variant="secondary" :disabled="busy" @click="$emit('retry', row.key)">
                        <template #icon><Refresh :size="ICON_TOOLBAR" /></template>
                        {{ t('teamhub', 'Retry') }}
                    </NcButton>
                </span>
            </li>
        </ol>

        <div v-if="!active && links.length" class="prov-progress__links">
            <a v-for="l in links" :key="l.url" :href="l.url" target="_blank" rel="noopener noreferrer" class="prov-progress__link">
                <OpenInNew :size="ICON_INLINE" aria-hidden="true" />
                {{ l.label }}
                <span class="prov-progress__sr">{{ t('teamhub', 'Opens in OpenProject') }}</span>
            </a>
        </div>

        <div v-if="canAct && !active" class="prov-progress__actions">
            <NcButton v-if="state?.stalled || state?.status === 'failed'" variant="primary" :disabled="busy" @click="$emit('continue')">
                <template #icon><PlayOutline :size="ICON_TOOLBAR" /></template>
                {{ state?.status === 'failed' ? t('teamhub', 'Retry from the failed step') : t('teamhub', 'Continue now') }}
            </NcButton>
            <NcButton v-if="rollbackOffered" variant="tertiary" :disabled="busy" @click="$emit('rollback')">
                <template #icon><DeleteOutline :size="ICON_TOOLBAR" /></template>
                {{ t('teamhub', 'Remove what was created') }}
            </NcButton>
        </div>

        <details v-if="admin && state" class="prov-progress__diag">
            <summary>{{ t('teamhub', 'For administrators') }}</summary>
            <dl class="prov-progress__dl">
                <dt>{{ t('teamhub', 'Operation') }}</dt><dd>#{{ state.id }} · {{ state.status }} · {{ state.mode }}</dd>
                <dt>{{ t('teamhub', 'Started by') }}</dt><dd>{{ state.createdBy }}</dd>
                <dt>{{ t('teamhub', 'Last heartbeat') }}</dt><dd>{{ state.heartbeatAt ? new Date(state.heartbeatAt * 1000).toLocaleString() : '—' }}</dd>
                <dt v-if="state.errorCode">{{ t('teamhub', 'Error code') }}</dt><dd v-if="state.errorCode">{{ state.errorCode }}</dd>
            </dl>
            <ul class="prov-progress__diag-steps">
                <li v-for="row in rows" :key="'d-' + row.key">
                    <code>{{ row.key }}</code> · {{ row.status }} · {{ n('teamhub', '{n} attempt', '{n} attempts', row.attempts, { n: row.attempts }) }}
                    <span v-if="row.errorCode"> · {{ row.errorCode }}</span>
                    <span v-if="row.externalId"> · {{ row.externalId }}</span>
                </li>
            </ul>
        </details>
    </div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import MinusCircleOutline from 'vue-material-design-icons/MinusCircleOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PlayOutline from 'vue-material-design-icons/PlayOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { ICON_BODY, ICON_INLINE, ICON_NAV, ICON_TOOLBAR } from '../constants/uiTokens.js'
import { isActive, operationStatusLabel, progressRows, resourceLinks } from '../lib/provisioning.js'

/**
 * ProvisioningProgress (v4.9.6, Phase 2) — one operation as a list of steps
 * with their state, the actions a person may take (retry a step, continue a
 * stalled run, remove what was created), links to what exists, and the
 * administrator's diagnostics. Used by the creation wizard while it pumps,
 * by the team page's banner afterwards, and by Admin → TeamHub.
 *
 * Renders state only; every action is an event the owner turns into a
 * request, so the same component serves a creator and an administrator.
 */
export default {
    name: 'ProvisioningProgress',

    components: { AlertCircle, CheckCircle, DeleteOutline, MinusCircleOutline, NcButton, NcLoadingIcon, OpenInNew, PlayOutline, Refresh },

    props: {
        /** The operation as GET /api/v1/provisioning/{id} returns it. */
        state: { type: Object, default: null },
        /** Whether the viewer may retry / continue / roll back. */
        canAct: { type: Boolean, default: false },
        /** Whether to show the administrator's diagnostics block. */
        admin: { type: Boolean, default: false },
        /** A request is in flight — actions are disabled. */
        busy: { type: Boolean, default: false },
    },

    emits: ['retry', 'continue', 'rollback'],

    data() {
        return { ICON_BODY, ICON_INLINE, ICON_NAV, ICON_TOOLBAR }
    },

    computed: {
        rows() {
            return progressRows(this.state)
        },
        active() {
            return isActive(this.state?.status)
        },
        links() {
            return resourceLinks(this.state)
        },
        rollbackOffered() {
            return ['failed', 'attention'].includes(this.state?.status) && !!this.state?.teamId
        },
    },

    methods: {
        t,
        n,
        operationStatusLabel,

        /** A short note from the step's detail worth showing to a person. */
        stepNote(row) {
            const d = row.detail || {}
            if (row.key === 'openproject_project' && d.name) {
                return d.mode === 'linked'
                    ? t('teamhub', 'Connected to {name}', { name: d.name })
                    : t('teamhub', 'Created {name}', { name: d.name })
            }
            if (row.key === 'membership' && d.openProject) {
                const added = Object.values(d.openProject).filter(v => v === 'added').length
                const refused = Array.isArray(d.refused) ? d.refused.length : 0
                if (refused > 0) {
                    return n('teamhub', '{n} member could not be added in OpenProject', '{n} members could not be added in OpenProject', refused, { n: refused })
                }
                if (added > 0) {
                    return n('teamhub', '{n} member added in OpenProject', '{n} members added in OpenProject', added, { n: added })
                }
            }
            if (row.key === 'project_folder' && d.openProjectFolder) {
                return t('teamhub', 'The project folder OpenProject manages is linked')
            }
            if (row.status === 'skipped' && d.reason === 'linked_resource_kept') {
                return t('teamhub', 'Existing resource kept')
            }
            return ''
        },
    },
}
</script>

<style scoped>
.prov-progress {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-md);
}

.prov-progress__head {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
}

.prov-progress__head-text {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xxs);
}

.prov-progress__title {
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.prov-progress__subtitle {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-progress__icon--ok {
    color: var(--color-success-text);
}

.prov-progress__icon--warn {
    color: var(--color-warning-text);
}

.prov-progress__steps {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.prov-progress__step {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: start;
    gap: var(--th-space-sm);
    padding: var(--th-space-xs) var(--th-space-sm);
    border-radius: var(--th-radius-control);
}

.prov-progress__step--failed,
.prov-progress__step--attention {
    background: var(--color-background-dark);
}

.prov-progress__step-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--th-icon-nav);
    height: var(--th-icon-nav);
}

.prov-progress__step--completed .prov-progress__step-mark {
    color: var(--color-success-text);
}

.prov-progress__step--failed .prov-progress__step-mark {
    color: var(--color-error-text);
}

.prov-progress__step--attention .prov-progress__step-mark {
    color: var(--color-warning-text);
}

.prov-progress__dot {
    width: var(--th-space-sm);
    height: var(--th-space-sm);
    border-radius: 50%;
    background: var(--color-border-dark);
}

.prov-progress__step-body {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xxs);
    min-width: 0;
}

.prov-progress__step--pending .prov-progress__step-label {
    color: var(--color-text-maxcontrast);
}

.prov-progress__step-status {
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
}

.prov-progress__step-error {
    font-size: var(--th-font-meta);
    color: var(--color-error-text);
}

.prov-progress__step--attention .prov-progress__step-error {
    color: var(--color-warning-text);
}

.prov-progress__step-note {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-progress__links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

.prov-progress__link {
    display: inline-flex;
    align-items: center;
    gap: var(--th-space-xxs);
    font-size: var(--th-font-meta);
    color: var(--color-primary-element);
    text-decoration: underline;
}

.prov-progress__link:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
    border-radius: var(--th-radius-control);
}

.prov-progress__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.prov-progress__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--th-space-sm);
}

.prov-progress__diag {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-progress__diag summary {
    cursor: pointer;
}

.prov-progress__dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--th-space-xxs) var(--th-space-md);
    margin: var(--th-space-sm) 0;
}

.prov-progress__dl dt {
    font-weight: var(--th-font-weight-medium);
}

.prov-progress__dl dd {
    margin: 0;
}

.prov-progress__diag-steps {
    margin: 0;
    padding-left: var(--th-space-lg);
}
</style>
