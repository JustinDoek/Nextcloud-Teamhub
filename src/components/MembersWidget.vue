<template>
    <div class="members-widget">
        <div class="members-widget__header">
            <span class="members-widget__title">
                <AccountGroup :size="16" />
                {{ t('teamhub', 'Members') }}
            </span>
        </div>

        <div v-if="loading.members" class="members-widget__loading">
            <NcLoadingIcon :size="20" />
        </div>

        <ul v-else class="members-widget__list">
            <li v-for="member in members" :key="member.userId" class="member-item">
                <!-- NcAvatar has built-in contact action menu: profile, Talk, email -->
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
    </div>
</template>

<script>
import { mapState } from 'vuex'
import { translate as t } from '@nextcloud/l10n'
import { NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

export default {
    name: 'MembersWidget',
    components: { NcAvatar, NcLoadingIcon, AccountGroup },
    computed: {
        ...mapState(['members', 'loading']),
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

.member-item__role--owner   { background: var(--color-error-light);   color: var(--color-error); }
.member-item__role--admin   { background: var(--color-warning-light);  color: var(--color-warning); }
.member-item__role--moderator { background: var(--color-success-light); color: var(--color-success); }
</style>
