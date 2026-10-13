<template>
    <!-- A listed-but-not-joined sub-team: a name and nothing else. No click
         handler and no actions menu, because every one of them would fail —
         CLAUDE.md § Permissions asks for absent, not disabled. `aria-disabled`
         rather than `disabled` so the row is still reachable and announced:
         the point of `listed` is that the reader learns the sub-team exists. -->
    <NcAppNavigationItem
        v-if="team.listed_only"
        class="th-nav-subteam th-nav-subteam--listed"
        :name="team.name">
        <template #icon>
            <LockOutline :size="iconNav" />
        </template>
        <!-- The padlock and the sentence live in the counter slot, on a plain
             <span>, which is where TeamNavGroup puts its megaphone and the
             only shape proven here: `title` and `aria-disabled` are not
             declared props of NcAppNavigationItem in 9.8.0, and CLAUDE.md
             asks not to guess. The icon is aria-hidden because an icon is not
             an accessible name; the real text beside it is what gets
             announced. -->
        <template #counter>
            <span class="th-nav-subteam__locked" :title="listedTitle">
                <LockOutline :size="iconInline" aria-hidden="true" />
                <span class="hidden-visually">{{ listedTitle }}</span>
            </span>
        </template>
    </NcAppNavigationItem>

    <NcAppNavigationItem
        v-else
        class="th-nav-subteam"
        :name="team.name"
        :active="active"
        @click="$emit('select', team.id)">
        <template #icon>
            <AccountMultipleOutline :size="iconNav" />
        </template>
        <template v-if="team.unread > 0" #counter>
            <!-- Unread only. A sub-team cannot be a service desk, so there is
                 no unclaimed-request count to show beside it. -->
            <NcCounterBubble type="highlighted" :count="team.unread" />
        </template>

        <!-- A team's actions minus three, and the three are not an oversight:
             **Manage team** is gone because a sub-team has no administration
             of its own — its members, name, visibility and deletion live in
             the main team's Manage team → Sub-teams (DESIGN §2.175). **Move to
             group** and **New group** are gone because a sub-team renders
             inside its parent's row and therefore follows the parent into
             whatever personal group the parent is filed under; filing a child
             separately would render it twice or not at all, which is why
             `TeamGroupService::assignTeam()` refuses a sub-team id too.

             The remaining gates read `team.level` exactly as a team's do, and
             that lands correctly without a special case: the parent admin who
             created the sub-team is its owner (level 9), so they can invite
             and cannot leave; another parent admin has no row at all (level 0)
             and does their member management from the Sub-teams tab; a picked
             member or an invited guest is level 1 and can leave. -->
        <template #actions>
            <NcActionButton
                close-after-click
                :aria-label="t('teamhub', 'Copy team link')"
                @click="$emit('copy-link', team.id)">
                <template #icon><LinkVariant :size="iconNav" /></template>
                {{ t('teamhub', 'Copy link') }}
            </NcActionButton>
            <NcActionButton
                v-if="(team.level || 0) >= 4"
                close-after-click
                :aria-label="t('teamhub', 'Invite members')"
                @click="$emit('invite', team.id)">
                <template #icon><AccountPlus :size="iconNav" /></template>
                {{ t('teamhub', 'Invite members') }}
            </NcActionButton>
            <NcActionButton
                close-after-click
                :aria-label="t('teamhub', 'Email all team members')"
                @click="$emit('email-members', team.id)">
                <template #icon><EmailOutline :size="iconNav" /></template>
                {{ t('teamhub', 'Email all members') }}
            </NcActionButton>
            <NcActionButton
                v-if="(team.level || 0) >= 1 && (team.level || 0) < 9"
                close-after-click
                :aria-label="t('teamhub', 'Leave team')"
                @click="$emit('leave', team.id)">
                <template #icon><LocationExit :size="iconNav" /></template>
                {{ t('teamhub', 'Leave team') }}
            </NcActionButton>
        </template>
    </NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem, NcCounterBubble, NcActionButton } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import LocationExit from 'vue-material-design-icons/LocationExit.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import { ICON_INLINE, ICON_NAV } from '../constants/uiTokens.js'

/**
 * One sub-team row in the sidebar, nested inside its parent (v4.12.2).
 *
 * A leaf, deliberately not a recursive `TeamNavItem`. Three reasons, and the
 * third is the one that matters: its action set differs, it never has a
 * counter for unclaimed requests, and **a sub-team cannot have sub-teams** —
 * encoding that in the component tree means the depth-1 rule cannot be broken
 * by a data shape, only by someone writing a new component.
 *
 * Like `TeamNavItem`, every action is emitted rather than handled; `App.vue`
 * owns what a click means.
 */
export default {
    name: 'SubTeamNavItem',

    components: {
        NcAppNavigationItem, NcCounterBubble, NcActionButton,
        AccountMultipleOutline, AccountPlus, EmailOutline, LinkVariant,
        LocationExit, LockOutline,
    },

    props: {
        /** One entry from a parent's `sub_teams` array. */
        team: {
            type: Object,
            required: true,
        },
        active: {
            type: Boolean,
            default: false,
        },
    },

    emits: ['select', 'copy-link', 'invite', 'email-members', 'leave'],

    computed: {
        iconNav() {
            return ICON_NAV
        },

        iconInline() {
            return ICON_INLINE
        },

        listedTitle() {
            // TRANSLATORS: tooltip and screen-reader text on a sub-team whose name the
            // reader can see but which they are not a member of
            return t('teamhub', 'You are not a member of this sub-team')
        },
    },

    methods: {
        t,
        n,
    },
}
</script>

<style scoped lang="scss">
/* A listed sub-team reads as unavailable without relying on colour alone:
   the padlock icon carries the same meaning as the dimmed text, and the
   title plus the visually hidden text say it in words.

   :deep() is required — the name element lives inside
   NcAppNavigationItem's own scoped template, so a rule written against it
   here never carries this component's scope attribute. Same reason as
   .th-nav-group in TeamNavGroup.vue. */
.th-nav-subteam--listed :deep(.app-navigation-entry__name) {
    color: var(--color-text-maxcontrast);
}

/* No pointer affordance on a row that does nothing when clicked. */
.th-nav-subteam--listed :deep(.app-navigation-entry-link) {
    cursor: default;
}

/* The padlock in the counter slot. Neutral, not a warning colour: not being
   in a sub-team is a fact, not a problem. */
.th-nav-subteam__locked {
    display: inline-flex;
    align-items: center;
    color: var(--color-text-maxcontrast);
}

/* Scoped rather than a bare `.hidden-visually` so it cannot collide with the
   server's own copy of the class - the same reasoning as TeamNavGroup's. */
.th-nav-subteam__locked .hidden-visually {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
