<template>
    <div class="members-widget">
        <div class="members-widget__header">
            <span class="members-widget__title">
                <AccountGroup :size="16" />
                {{ t('teamhub', 'Members') }}
                <span class="members-widget__count">({{ effectiveMemberCount }})</span>
            </span>
        </div>

        <div v-if="loading.members" class="members-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <template v-else>
            <!-- Direct users -->
            <ul class="members-widget__list">
                <li v-for="member in members" :key="member.userId" class="member-item">
                    <NcAvatar
                        :user="member.userId"
                        :display-name="member.displayName"
                        :show-user-status="true"
                        :disable-menu="false"
                        :size="32" />
                    <div class="member-item__info">
                        <span class="member-item__name">{{ member.displayName }}</span>
                        <span class="member-item__role" :class="'member-item__role--' + member.role.toLowerCase()">
                            {{ member.role }}
                        </span>
                    </div>
                </li>
            </ul>

            <!-- More direct members note -->
            <div v-if="hasMoreMembers && !memberGroups.length && !memberCircles.length" class="members-widget__more">
                {{ t('teamhub', '{shown} of {total} members shown', { shown: members.length, total: effectiveMemberCount }) }}
            </div>

            <!-- Groups -->
            <template v-if="memberGroups.length">
                <div class="members-widget__section-label">{{ t('teamhub', 'Groups') }}</div>
                <ul class="members-widget__group-list">
                    <li v-for="group in memberGroups" :key="'g-' + group.displayName" class="group-item">
                        <div class="group-item__icon group-item__icon--group">
                            <AccountGroup :size="18" />
                        </div>
                        <div class="group-item__info">
                            <span class="group-item__name">{{ group.displayName }}</span>
                            <span class="group-item__count">{{ t('teamhub', '{n} users', { n: group.memberCount }) }}</span>
                        </div>
                    </li>
                </ul>
            </template>

            <!-- Teams / circles -->
            <template v-if="memberCircles.length">
                <div class="members-widget__section-label">{{ t('teamhub', 'Teams') }}</div>
                <ul class="members-widget__group-list">
                    <li v-for="circle in memberCircles" :key="'c-' + circle.displayName" class="group-item">
                        <div class="group-item__icon group-item__icon--circle">
                            <AccountMultiple :size="18" />
                        </div>
                        <div class="group-item__info">
                            <span class="group-item__name">{{ circle.displayName }}</span>
                            <span class="group-item__count">{{ t('teamhub', '{n} users', { n: circle.memberCount }) }}</span>
                        </div>
                    </li>
                </ul>
            </template>
        </template>
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'

export default {
    name: 'MembersWidget',
    components: { NcAvatar, NcLoadingIcon, AccountGroup, AccountMultiple },
    computed: {
        ...mapState(['members', 'memberGroups', 'memberCircles', 'loading', 'effectiveMemberCount', 'hasMoreMembers']),
    },
    methods: { t },
}
</script>

<style scoped>
.members-widget {
    padding: 8px 16px 12px;
}

.members-widget__header {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}

.members-widget__title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.members-widget__count {
    font-weight: 400;
    font-size: 12px;
}

.members-widget__loading { display: flex; justify-content: center; padding: 12px; }

.members-widget__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.member-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    border-radius: var(--border-radius);
}

.member-item__info {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

.member-item__name {
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.member-item__role {
    font-size: 11px;
    padding: 1px 7px;
    border-radius: var(--border-radius-pill);
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    flex-shrink: 0;
}

.member-item__role--owner   { background: var(--color-error-light);   color: var(--color-error-text); }
.member-item__role--admin   { background: var(--color-warning-light);  color: var(--color-warning-text); }
.member-item__role--moderator { background: var(--color-success-light); color: var(--color-success-text); }

.members-widget__more {
    margin-top: 8px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    text-align: center;
    padding-top: 6px;
    border-top: 1px solid var(--color-border);
}

/* ── Groups & Teams sections ──────────────────────────────────────── */
.members-widget__section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-maxcontrast);
    margin: 12px 0 4px;
    padding-top: 8px;
    border-top: 1px solid var(--color-border);
}

.members-widget__group-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.group-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 6px;
    border-radius: var(--border-radius);
}

.group-item__icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.group-item__icon--group {
    background: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success-text);
}

.group-item__icon--circle {
    background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
    color: var(--color-primary-element);
}

.group-item__info {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

.group-item__name {
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.group-item__count {
    font-size: 11px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    flex-shrink: 0;
}
</style>
