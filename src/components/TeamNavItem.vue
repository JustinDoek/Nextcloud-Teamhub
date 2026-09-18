<template>
    <NcAppNavigationItem
        :name="team.name"
        :active="active"
        @click="$emit('select', team.id)">
        <template #icon>
            <AccountGroup :size="iconNav" />
        </template>
        <template v-if="team.unread > 0" #counter>
            <!-- NcCounterBubble reads its number from the `count` prop, NOT
                 from the default slot. Passing it via `{{ }}` silently sets
                 the prop to undefined → Intl.NumberFormat formats it as
                 the literal string "NaN" (root cause of the 3.81.x bug). -->
            <NcCounterBubble type="highlighted" :count="team.unread" />
        </template>
        <!-- Team actions — moved here from the Team-info widget so
             Team info can be hidden without losing Manage/Invite/
             Leave. Role-gated per team via team.level (0 = indirect,
             1 = member, 4 = moderator, 8 = admin, 9 = owner). -->
        <template #actions>
            <!-- v4.2.2: NcActionButton.closeAfterClick defaults
                 to FALSE in @nextcloud/vue 9, so the popover
                 would otherwise stay open after our handler
                 runs. Explicit true on all four so the menu
                 collapses as soon as the user picks an action. -->
            <NcActionButton
                v-if="(team.level || 0) >= 8"
                close-after-click
                :aria-label="t('teamhub', 'Manage team')"
                @click="$emit('manage', team.id)">
                <template #icon><CogOutline :size="iconNav" /></template>
                {{ t('teamhub', 'Manage team') }}
            </NcActionButton>
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
            <!-- v4.6.26 — no role gate. Writing to the people you
                 work with is not a privileged act, and the roster
                 of addresses is already member-visible in the
                 Members widget. Ungated like Copy link above,
                 rather than moderator-gated like Invite. -->
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

            <!-- v4.7.3 — which sidebar group this team sits in.
                 v4.7.15 — one button opening a dialog, where it used to be
                 a radio per group. The radio list read well at two or three
                 groups and turned the menu into mostly group list at ten.

                 A dropdown was tried first and does not work here:
                 NcActionInput's multiselect hard-codes `appendToBody: false`
                 on its NcSelect, so the option list is drawn inside the
                 popover and clipped by `.v-popper__inner`'s `overflow:
                 auto` — observed, not theorised. Forcing `append-to-body`
                 fixes the clipping and breaks selection instead, because a
                 teleported list is outside the popper and clicking it
                 dismisses the menu. A native <select> escapes the clip
                 (the browser draws its list as OS chrome) but needs a raw
                 <li> in the menu, and NcActions reads its children to pick
                 the menu's ARIA semantics — one non-NcAction child turns
                 the whole menu from `menu` to `unknown`.

                 So the list moves out of the popover entirely. A dialog
                 has room to grow, and the radios are the right control
                 again once they have somewhere to sit. -->
            <NcActionSeparator />
            <NcActionButton
                close-after-click
                @click="onPickGroup">
                <template #icon><FolderMoveOutline :size="iconNav" /></template>
                <!-- TRANSLATORS: opens a dialog for filing this team under one
                     of the reader's own sidebar groups; the ellipsis marks it
                     as opening a dialog rather than acting immediately -->
                {{ t('teamhub', 'Move to group…') }}
            </NcActionButton>
            <!-- Creating from here also moves the team in — a group
                 created from a team's menu and left empty would be a
                 dead end, since nothing else drops teams into one.

                 v4.7.15 — `label-outside` plus a visually hidden label.
                 The label used to render as a line of text above the
                 field, which pushed the row to two lines and threw the
                 folder icon out of line with the input: `.action-input`
                 lays out `align-items: flex-start` while the icon wrapper
                 sets `align-self: center`, so the icon centres on
                 label+field rather than on the field. `label-outside`
                 also stops NcInputField reserving space for a floating
                 label, so the field returns to its normal height and the
                 icon lands on its centre.

                 The <label> element itself stays in the DOM and is only
                 hidden visually. NcActionInput's own `aria-label` prop is
                 declared but never rendered anywhere in 9.8.0 — it is
                 inert, which is why it is gone from here — so this real
                 `for`/`id` pair is the field's only accessible name, and
                 SKILLS.md § WCAG asks for a label rather than a
                 placeholder. The default slot becomes the placeholder:
                 NcActionInput reads its text and passes it down. -->
            <NcActionInput
                class="th-nav-item__hidden-label"
                :model-value="newGroupName"
                label-outside
                :label="t('teamhub', 'New group')"
                show-trailing-button
                :trailing-button-label="t('teamhub', 'Create group')"
                @update:model-value="newGroupName = $event"
                @submit="onCreateGroup">
                <template #icon><FolderPlusOutline :size="iconNav" /></template>
                {{ t('teamhub', 'New group') }}
            </NcActionInput>
        </template>
    </NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem, NcCounterBubble, NcActionButton, NcActionInput, NcActionSeparator } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import FolderMoveOutline from 'vue-material-design-icons/FolderMoveOutline.vue'
import FolderPlusOutline from 'vue-material-design-icons/FolderPlusOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import LocationExit from 'vue-material-design-icons/LocationExit.vue'
import { ICON_NAV } from '../constants/uiTokens.js'

/**
 * One team row in the sidebar (v4.7.3).
 *
 * Extracted from App.vue when sidebar grouping landed: the row is now
 * rendered from two places — loose in the list, and nested inside a
 * TeamNavGroup — and a ~70-line block of role-gated actions is not something
 * to keep in two copies.
 *
 * Every action is emitted rather than handled. App.vue still owns what a
 * click means (it holds the modals, the router state and the store
 * dispatches); this component owns only what the row looks like.
 */
export default {
    name: 'TeamNavItem',

    components: {
        NcAppNavigationItem, NcCounterBubble, NcActionButton,
        NcActionInput, NcActionSeparator,
        AccountGroup, AccountPlus, CogOutline, EmailOutline, FolderMoveOutline,
        FolderPlusOutline, LinkVariant, LocationExit,
    },

    props: {
        team: {
            type: Object,
            required: true,
        },
        active: {
            type: Boolean,
            default: false,
        },
        /** Id of the group the team is in now; '' when it is loose. */
        currentGroupId: {
            type: String,
            default: '',
        },
    },

    emits: ['select', 'manage', 'copy-link', 'invite', 'email-members', 'leave', 'pick-group', 'create-group'],

    data() {
        return {
            newGroupName: '',
        }
    },

    computed: {
        iconNav() {
            return ICON_NAV
        },
    },

    methods: {
        t,
        n,

        /**
         * Carries the team's name as well as its id so App.vue can title the
         * dialog without looking the team up again — the same reason every
         * other action here emits rather than resolves.
         */
        onPickGroup() {
            this.$emit('pick-group', {
                teamId: this.team.id,
                teamName: this.team.name,
                groupId: this.currentGroupId,
            })
        },

        onCreateGroup() {
            const name = (this.newGroupName || '').trim()
            if (!name) {
                return
            }
            this.$emit('create-group', { name, teamId: this.team.id })
            this.newGroupName = ''
        },
    },
}
</script>

<style scoped lang="scss">
/* Hide the New-group field's label without removing it.
 *
 * `label-outside` is what stops NcInputField reserving room for a floating
 * label, which is what puts the folder icon back on the field's centre line
 * — but it is also the only condition under which NcActionInput renders the
 * outer <label for>. So the element we want gone is produced by the very
 * prop that fixes the alignment, and hiding it here is cheaper than
 * reimplementing the control.
 *
 * These are the declarations from @nextcloud/vue's own
 * `.action-input__text-label--hidden` modifier, which NcActionInput applies
 * only on a branch this one cannot reach (it is added when `labelOutside`
 * is false, and the element only exists when it is true).
 *
 * :deep() is required — the label lives inside NcActionInput's own scoped
 * template and so never carries this component's scope attribute. Same
 * reason as .th-nav-group in TeamNavGroup.vue. */
.th-nav-item__hidden-label :deep(.action-input__text-label) {
    position: absolute;
    inset-inline-start: 0;
    width: 1px;
    height: 1px;
    overflow: hidden;
    z-index: -1;
    opacity: 0;
}
</style>
