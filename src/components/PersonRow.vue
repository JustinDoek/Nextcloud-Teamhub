<template>
    <div class="th-person" :class="['th-person--' + type, { 'th-person--compact': compact }]">
        <div class="th-person__avatar" :class="'th-person__avatar--' + type" aria-hidden="true">
            <AccountGroup v-if="type === 'group'" :size="iconSize" />
            <AccountMultiple v-else-if="type === 'circle'" :size="iconSize" />
            <EmailOutline v-else-if="type === 'email'" :size="iconSize" />
            <EarthArrowRight v-else-if="type === 'federated'" :size="iconSize" />
            <NcAvatar
                v-else
                :user="id"
                :display-name="displayName"
                :size="avatarSize"
                :hide-status="true"
                :disable-menu="true" />
        </div>
        <div class="th-person__text">
            <span class="th-person__name">{{ displayName || id }}</span>
            <span v-if="sublineText" class="th-person__subline">{{ sublineText }}</span>
        </div>
        <slot />
    </div>
</template>

<script>
/*
 * PersonRow — how a person (or a group, team, email or federated
 * candidate) is shown in every picker: avatar, name, and the one line
 * that says *which* person this is.
 *
 * Extracted (v4.10.7) because five pickers — Invite members, the wizard's
 * member step, the bulk-create member cell, the admin owner picker and the
 * audit user filter — each drew their own row, and each showed the display
 * name alone. Two colleagues with the same name were indistinguishable
 * everywhere, and Nextcloud's own fallback (the email or the uid) does not
 * say "Jari Feet from Finance". The backend answers that with `subline`
 * (PersonSublineService: job title · organisation by default, the
 * administrator's fields otherwise); this component is the one place that
 * renders it.
 *
 * Presentational only. The parent owns the click target, the keyboard
 * handling and the list semantics; this row is what goes inside.
 */
import { translate as t } from '@nextcloud/l10n'
import { NcAvatar } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EarthArrowRight from 'vue-material-design-icons/EarthArrowRight.vue'
import { ICON_BODY, ICON_NAV, AVATAR_SM, AVATAR_MD } from '../constants/uiTokens.js'

export default {
    name: 'PersonRow',

    components: { NcAvatar, AccountGroup, AccountMultiple, EmailOutline, EarthArrowRight },

    props: {
        // The uid (users), gid, team id, email address or federated cloud id.
        id: { type: String, required: true },
        displayName: { type: String, default: '' },
        // 'user' | 'group' | 'circle' | 'email' | 'federated' — the invite
        // types the search endpoints return.
        type: { type: String, default: 'user' },
        // The backend's disambiguating line. Only user rows carry one; the
        // other types are labelled by what they are.
        subline: { type: String, default: '' },
        // Table-cell density: a smaller avatar and smaller type; still two lines.
        compact: { type: Boolean, default: false },
    },

    computed: {
        avatarSize() { return this.compact ? AVATAR_SM : AVATAR_MD },
        iconSize() { return this.compact ? ICON_BODY : ICON_NAV },
        sublineText() {
            switch (this.type) {
            case 'group':
                // TRANSLATORS: type label under a search result that is a Nextcloud group
                return t('teamhub', 'Group')
            case 'circle':
                // TRANSLATORS: type label under a search result that is another team
                return t('teamhub', 'Team')
            case 'email':
                // TRANSLATORS: type label under a search result that is an email address to invite
                return t('teamhub', 'Email invite')
            case 'federated':
                // TRANSLATORS: type label under a search result that is a user on another Nextcloud server
                return t('teamhub', 'Federated user')
            default:
                return this.subline || ''
            }
        },
    },

    methods: { t },
}
</script>

<style scoped>
.th-person {
    display: flex;
    align-items: center;
    gap: var(--th-space-sm);
    min-width: 0;
    flex: 1;
}

.th-person__avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: var(--th-avatar-md);
    height: var(--th-avatar-md);
}

.th-person--compact .th-person__avatar {
    width: var(--th-avatar-sm);
    height: var(--th-avatar-sm);
}

.th-person__avatar--group,
.th-person__avatar--circle,
.th-person__avatar--email,
.th-person__avatar--federated {
    background: var(--color-background-dark);
    border-radius: 50%;
    color: var(--color-primary-element);
}

/* Two lines beside the avatar, always — the name on the first, the role on
   the second (Justin, 2026-09-20: "Jaap next to the icon on the first line
   and Agent on the second line next to the icon"). The compact variant only
   shrinks the avatar; it does not fold the two lines into one. */
.th-person__text {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    flex: 1;
    line-height: var(--th-line-height-body);
}

.th-person__name {
    font-size: var(--th-font-body);
    font-weight: var(--th-font-weight-medium);
    color: var(--color-main-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Italic: the role reads as a description of the person, not a second name. */
.th-person__subline {
    font-size: var(--th-font-meta);
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.th-person--compact .th-person__name,
.th-person--compact .th-person__subline {
    font-size: var(--th-font-meta);
}
</style>
