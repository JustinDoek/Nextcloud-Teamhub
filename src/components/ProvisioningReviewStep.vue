<template>
    <div class="prov-review">
        <p class="prov-review__hint">
            {{ t('teamhub', 'Everything TeamHub will create or link for this workspace. Nothing happens until you confirm.') }}
        </p>

        <section class="prov-review__section">
            <h4 class="prov-review__title">{{ t('teamhub', 'Project basics') }}</h4>
            <dl class="prov-review__dl">
                <dt>{{ t('teamhub', 'Name') }}</dt><dd>{{ summary.name }}</dd>
                <dt v-if="summary.description">{{ t('teamhub', 'Description') }}</dt><dd v-if="summary.description">{{ summary.description }}</dd>
                <dt>{{ t('teamhub', 'Policy') }}</dt><dd>{{ summary.policyLabel || t('teamhub', 'Instance default') }}</dd>
                <dt v-if="summary.startDate">{{ t('teamhub', 'Start date') }}</dt><dd v-if="summary.startDate">{{ summary.startDate }}</dd>
                <dt v-if="summary.endDate">{{ t('teamhub', 'End date') }}</dt><dd v-if="summary.endDate">{{ summary.endDate }}</dd>
                <dt v-if="summary.category">{{ t('teamhub', 'Category') }}</dt><dd v-if="summary.category">{{ summary.category }}</dd>
                <dt>{{ t('teamhub', 'Visibility') }}</dt>
                <dd>{{ summary.visibility === 'public' ? t('teamhub', 'Public project in OpenProject') : t('teamhub', 'Private project in OpenProject') }}</dd>
            </dl>
        </section>

        <section class="prov-review__section">
            <h4 class="prov-review__title">{{ t('teamhub', 'Resources') }}</h4>
            <ul class="prov-review__list" :aria-label="t('teamhub', 'Resources')">
                <li v-for="r in summary.resources" :key="r.id" class="prov-review__row">
                    <span class="prov-review__badge" :class="'prov-review__badge--' + r.kind">{{ classificationLabel(r.kind) }}</span>
                    <span class="prov-review__row-body">
                        <span class="prov-review__row-name">{{ r.label }}</span>
                        <span v-if="r.note" class="prov-review__row-note">{{ r.note }}</span>
                    </span>
                </li>
            </ul>
        </section>

        <section class="prov-review__section">
            <h4 class="prov-review__title">{{ t('teamhub', 'Members') }}</h4>
            <p v-if="!summary.members.length" class="prov-review__hint">{{ t('teamhub', 'Only you. You can invite people later.') }}</p>
            <ul v-else class="prov-review__list" :aria-label="t('teamhub', 'Members')">
                <li v-for="m in summary.members" :key="m.type + ':' + m.id" class="prov-review__row">
                    <span class="prov-review__badge" :class="m.omitted ? 'prov-review__badge--skipped' : 'prov-review__badge--new'">
                        {{ m.omitted ? t('teamhub', 'Left out') : roleLabel(m.teamRole) }}
                    </span>
                    <span class="prov-review__row-body">
                        <span class="prov-review__row-name">{{ m.displayName }}</span>
                        <span class="prov-review__row-note">{{ m.note }}</span>
                    </span>
                </li>
            </ul>
            <p v-if="summary.ownerName" class="prov-review__hint">
                {{ t('teamhub', '{name} becomes the owner of the team once everything is set up.', { name: summary.ownerName }) }}
            </p>
        </section>

        <NcNoteCard v-if="summary.warnings.length" type="warning">
            <ul class="prov-review__warnings">
                <li v-for="(w, i) in summary.warnings" :key="i">{{ w }}</li>
            </ul>
        </NcNoteCard>
    </div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { classificationLabel, roleLabel } from '../lib/provisioning.js'

/**
 * ProvisioningReviewStep (v4.9.6, Phase 2) — the wizard's last step before
 * anything is made: the basics, every resource with what will happen to it
 * (new / existing-linked / skipped / unavailable / required-but-missing),
 * every member with their roles on both sides, and the warnings that
 * survived the earlier steps. Pure rendering of the `summary` the wizard
 * builds.
 */
export default {
    name: 'ProvisioningReviewStep',

    components: { NcNoteCard },

    props: {
        /** { name, description, policyLabel, startDate, endDate, category, visibility, resources: [{id, label, kind, note}], members: [{id, type, displayName, teamRole, note, omitted}], ownerName, warnings: [] } */
        summary: { type: Object, required: true },
    },

    methods: { t, classificationLabel, roleLabel },
}
</script>

<style scoped>
.prov-review {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-lg);
}

.prov-review__hint {
    margin: 0;
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
}

.prov-review__section {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-sm);
}

.prov-review__title {
    margin: 0;
    font-size: var(--th-font-heading);
    font-weight: var(--th-font-weight-semibold);
}

.prov-review__dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--th-space-xs) var(--th-space-md);
    margin: 0;
    font-size: var(--th-font-meta);
}

.prov-review__dl dt {
    font-weight: var(--th-font-weight-medium);
    color: var(--color-text-maxcontrast);
}

.prov-review__dl dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.prov-review__list {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xs);
    list-style: none;
    margin: 0;
    padding: 0;
}

.prov-review__row {
    display: flex;
    align-items: flex-start;
    gap: var(--th-space-sm);
    font-size: var(--th-font-meta);
}

.prov-review__badge {
    flex: 0 0 auto;
    min-width: 7rem;
    padding: 0 var(--th-space-sm);
    border-radius: var(--th-radius-chip);
    background: var(--color-background-dark);
    color: var(--color-main-text);
    font-size: var(--th-font-micro);
    font-weight: var(--th-font-weight-medium);
    text-align: center;
    line-height: var(--th-line-height-relaxed);
}

.prov-review__badge--new {
    background: var(--color-success);
    color: var(--color-success-text);
}

.prov-review__badge--link {
    background: var(--color-info);
    color: var(--color-info-text);
}

.prov-review__badge--unavailable,
.prov-review__badge--required-missing {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.prov-review__row-body {
    display: flex;
    flex-direction: column;
    gap: var(--th-space-xxs);
    min-width: 0;
}

.prov-review__row-name {
    font-weight: var(--th-font-weight-medium);
}

.prov-review__row-note {
    color: var(--color-text-maxcontrast);
}

.prov-review__warnings {
    margin: 0;
    padding-left: var(--th-space-lg);
}
</style>
