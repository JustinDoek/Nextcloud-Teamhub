<template>
    <NcAppNavigationItem
        class="th-nav-group"
        :class="{ 'th-nav-group--updates': group.hasUpdates }"
        :name="label"
        allow-collapse
        :open="group.expanded"
        @update:open="$emit('toggle', { groupId: group.id, expanded: $event })">
        <template #icon>
            <FolderStarOutline v-if="group.builtin" :size="iconNav" />
            <FolderOutline v-else :size="iconNav" />
        </template>

        <!-- v4.7.5 — "something inside changed" as a mark, not a font weight.
             Bold alone was unreadable: NC renders sidebar entries at a weight
             close enough to bold that the two states were indistinguishable,
             so a group with updates looked exactly like one without. The
             counter slot is where NC puts a per-entry badge, so the megaphone
             lands right after the name.
             The icon is aria-hidden and the meaning is carried by real text
             beside it — an icon is not an accessible name, and `title` alone
             reaches neither a screen reader reliably nor a touch user at all. -->
        <template v-if="group.hasUpdates" #counter>
            <span class="th-nav-group__updates" :title="updatesLabel">
                <Bullhorn :size="iconInline" aria-hidden="true" />
                <span class="hidden-visually">{{ updatesLabel }}</span>
            </span>
        </template>

        <template #actions>
            <NcActionCheckbox
                :model-value="group.expanded"
                @update:model-value="$emit('toggle', { groupId: group.id, expanded: $event })">
                <!-- TRANSLATORS: checkbox in a sidebar group's menu; when on, the group
                     is open every time the app loads -->
                {{ t('teamhub', 'Expanded by default') }}
            </NcActionCheckbox>

            <!-- Favorites is the one group the app owns: its name is
                 translated rather than stored, and deleting it would leave
                 the feature with no visible affordance at all. -->
            <template v-if="!group.builtin">
                <!-- v4.7.15 — `label-outside` plus a visually hidden label,
                     for the reasons set out on the New-group field in
                     TeamNavItem.vue: the visible label made the row two
                     lines tall and pulled the pencil off the field's centre
                     line, and NcActionInput's `aria-label` prop is inert in
                     9.8.0, so the <label for> is the field's only accessible
                     name. The default slot becomes the placeholder. -->
                <NcActionInput
                    class="th-nav-group__hidden-label"
                    :model-value="renameTo"
                    label-outside
                    :label="t('teamhub', 'Rename group')"
                    show-trailing-button
                    :trailing-button-label="t('teamhub', 'Rename group')"
                    @update:model-value="renameTo = $event"
                    @submit="onRename">
                    <template #icon><PencilOutline :size="iconNav" /></template>
                    {{ t('teamhub', 'Rename group') }}
                </NcActionInput>
                <NcActionButton
                    close-after-click
                    :aria-label="t('teamhub', 'Delete group')"
                    @click="$emit('delete', group.id)">
                    <template #icon><DeleteOutline :size="iconNav" /></template>
                    {{ t('teamhub', 'Delete group') }}
                </NcActionButton>
            </template>
        </template>

        <TeamNavItem
            v-for="team in group.teams"
            :key="team.id"
            :team="team"
            :active="team.id === activeTeamId"
            :current-group-id="group.id"
            @select="$emit('select', $event)"
            @manage="$emit('manage', $event)"
            @copy-link="$emit('copy-link', $event)"
            @invite="$emit('invite', $event)"
            @email-members="$emit('email-members', $event)"
            @leave="$emit('leave', $event)"
            @pick-group="$emit('pick-group', $event)"
            @create-group="$emit('create-group', $event)" />

        <!-- An empty group is still worth a row: it is the only place its
             own Delete action lives, so hiding it would make a group
             created by mistake impossible to get rid of. -->
        <li v-if="!group.teams.length" class="th-nav-group__empty">
            {{ t('teamhub', 'No teams in this group yet') }}
        </li>
    </NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem, NcActionButton, NcActionCheckbox, NcActionInput } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderStarOutline from 'vue-material-design-icons/FolderStarOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import TeamNavItem from './TeamNavItem.vue'
import { ICON_INLINE, ICON_NAV } from '../constants/uiTokens.js'

/**
 * One collapsible group of teams in the sidebar (v4.7.3).
 *
 * The group owns nothing but its own chrome — every action, including its
 * children's, is emitted up to App.vue, which holds the store dispatches and
 * the modals. That keeps the two nav components free of app state and makes
 * a loose team and a grouped team render through the identical TeamNavItem.
 *
 * Collapse state is one value, not two: the chevron and the "Expanded by
 * default" checkbox write the same stored field, so a group stays however it
 * was last left. The checkbox exists because it is reachable and labelled —
 * the chevron is an icon.
 */
export default {
    name: 'TeamNavGroup',

    components: {
        NcAppNavigationItem, NcActionButton, NcActionCheckbox, NcActionInput,
        Bullhorn, DeleteOutline, FolderOutline, FolderStarOutline, PencilOutline,
        TeamNavItem,
    },

    props: {
        /** One entry from the `sidebarTeamGroups` getter — carries `teams` and `hasUpdates`. */
        group: {
            type: Object,
            required: true,
        },
        activeTeamId: {
            type: String,
            default: null,
        },
    },

    emits: ['toggle', 'delete', 'rename', 'select', 'manage', 'copy-link', 'invite', 'email-members', 'leave', 'pick-group', 'create-group'],

    data() {
        return {
            renameTo: '',
        }
    },

    computed: {
        iconNav() {
            return ICON_NAV
        },
        iconInline() {
            return ICON_INLINE
        },

        /**
         * The built-in group's label is translated at render time, never
         * stored — see TeamGroupService's class docblock for why.
         */
        label() {
            // TRANSLATORS: name of the built-in sidebar group for a user's most-used teams
            return this.group.builtin ? t('teamhub', 'Favorites') : this.group.name
        },

        /**
         * The megaphone's accessible name and hover tooltip (v4.7.5).
         *
         * This used to ride on NcAppNavigationItem's `ariaDescription` prop
         * alongside a bold name. Both halves were wrong: the bold was
         * invisible against NC's own entry weight, and `aria-description`
         * has uneven screen-reader support. Real text in the counter slot,
         * visually hidden next to the icon, is announced by everything.
         */
        updatesLabel() {
            // TRANSLATORS: tooltip and screen-reader text on the megaphone marking a sidebar group that contains a team with unread updates
            return t('teamhub', 'A team in this group has updates')
        },
    },

    methods: {
        t,
        n,

        onRename() {
            const name = (this.renameTo || '').trim()
            if (!name) {
                return
            }
            this.$emit('rename', { groupId: this.group.id, name })
            this.renameTo = ''
        },
    },
}
</script>

<style scoped lang="scss">
/* The "has updates" weight. :deep() is required because the name element
   lives inside NcAppNavigationItem's own scoped template — the same reason
   .teamhub-nav-primary in App.vue reaches in this way.

   The class is `__name`, NOT `__title`: @nextcloud/vue 9 renders no
   `.app-navigation-entry__title` at all, so a rule written against that
   name applies to nothing and fails silently. (App.vue's own
   .teamhub-nav-primary block still carries the stale `__title` selector —
   logged in HANDOFF.md rather than fixed here, since it belongs to a
   different feature.)

   v4.7.5 — the base weight is pinned to regular. Without it the bold had
   nothing to contrast against: NC renders sidebar entries at a weight near
   enough to bold that both states read as "bold" and the signal carried
   nothing. The megaphone is now what actually says "updates"; this is the
   supporting cue, which is why it is a weight step and not a colour. */
.th-nav-group :deep(.app-navigation-entry__name) {
    font-weight: var(--th-font-weight-regular);
}

.th-nav-group--updates :deep(.app-navigation-entry__name) {
    font-weight: var(--th-font-weight-bold);
}

/* The megaphone sits in the counter slot, so it lands after the name where
   a badge would. Coloured with the primary element rather than the warning
   token: an update is news, not a problem. */
.th-nav-group__updates {
    display: inline-flex;
    align-items: center;
    color: var(--color-primary-element);
}

/* Scoped rather than a bare `.hidden-visually` so it cannot collide with
   the server's own copy of the class — same reasoning as the two
   definitions in ManageTeamView.vue. */
.th-nav-group__updates .hidden-visually {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

/* Hides the Rename field's label while leaving it in the DOM — the
   declarations and the reasoning are the same as
   .th-nav-item__hidden-label in TeamNavItem.vue, which carries the
   full note. */
.th-nav-group__hidden-label :deep(.action-input__text-label) {
    position: absolute;
    inset-inline-start: 0;
    width: 1px;
    height: 1px;
    overflow: hidden;
    z-index: -1;
    opacity: 0;
}

.th-nav-group__empty {
    padding: 0 var(--default-grid-baseline, 4px) 0 44px;
    color: var(--color-text-maxcontrast);
    font-size: var(--th-font-meta);
    line-height: var(--th-line-height-body);
    /* Matches the height of a real nav row so an empty group does not
       collapse to a sliver the user cannot aim at. */
    min-height: 44px;
    display: flex;
    align-items: center;
}
</style>
