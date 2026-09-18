<template>
    <NcDialog
        :name="title"
        :open="true"
        size="small"
        @closing="$emit('close')">
        <fieldset class="th-gpm">
            <!-- The dialog's own title says which team is being filed, so a
                 second visible heading here would only repeat it. The legend
                 still has to exist: it is what names the radio group to a
                 screen reader, and a fieldset without one announces its
                 radios with no shared context. -->
            <legend class="th-gpm__legend">{{ t('teamhub', 'Group') }}</legend>

            <NcCheckboxRadioSwitch
                v-for="option in options"
                :key="option.id"
                :model-value="currentGroupId"
                :value="option.id"
                :name="radioName"
                type="radio"
                @update:model-value="onPick">
                {{ option.label }}
            </NcCheckboxRadioSwitch>
        </fieldset>
    </NcDialog>
</template>

<script>
import { NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Picks the sidebar group a team is filed under (v4.7.15).
 *
 * This list used to live in the team row's own action menu, one radio per
 * group. That was right while a user had two or three groups and wrong at
 * ten — the menu grew by a row per group. Every way of keeping it in the
 * popover was tried and rejected (the reasoning is on the "Move to group…"
 * button in TeamNavItem.vue); the list simply needs more room than a
 * popover has, so it moved out into a dialog.
 *
 * Radios rather than a dropdown now that there is room: they show where the
 * team is and where it can go at the same time, which was the original
 * instinct and is still the right one.
 *
 * Choosing applies immediately and closes, matching what the menu did — the
 * change is one dispatch away from undone, and a Save step would add a
 * click to the common case of filing one team. Like the other two nav
 * components this one holds no state and dispatches nothing: it emits, and
 * App.vue owns what that means.
 */
export default {
    name: 'TeamGroupPickerModal',

    components: {
        NcCheckboxRadioSwitch, NcDialog,
    },

    props: {
        teamId: {
            type: String,
            required: true,
        },
        /** Named in the dialog title, so the reader knows what they are filing. */
        teamName: {
            type: String,
            default: '',
        },
        /** Every group the team could be moved into. */
        groups: {
            type: Array,
            default: () => [],
        },
        /** Id of the group the team is in now; '' when it is loose. */
        currentGroupId: {
            type: String,
            default: '',
        },
    },

    emits: ['assign', 'close'],

    computed: {
        title() {
            // TRANSLATORS: dialog title; {team} is the name of the team being filed under a sidebar group
            return t('teamhub', 'Move {team} to a group', { team: this.teamName })
        },

        /**
         * Every group the team could sit in, "no group" first.
         *
         * '' is the ungrouped value, matching what `currentGroupId` carries
         * and what App.vue's handler expects — a radio compares by equality
         * and is happy with an empty string, so this needs no sentinel.
         */
        options() {
            return [
                {
                    id: '',
                    // TRANSLATORS: option meaning the team is not placed in any
                    // group and shows in the plain sidebar list
                    label: t('teamhub', 'Ungrouped'),
                },
                ...this.groups.map(group => ({
                    id: group.id,
                    label: this.groupLabel(group),
                })),
            ]
        },

        /**
         * Radios sharing a `name` are one group in the DOM. Only one picker
         * is ever mounted, but keying it to the team keeps that true even if
         * that ever stops being the case.
         */
        radioName() {
            return `teamhub-group-${this.teamId}`
        },
    },

    methods: {
        t,

        /**
         * The built-in group's label is translated at render time, never
         * stored — see TeamGroupService's class docblock for why.
         */
        groupLabel(group) {
            // TRANSLATORS: name of the built-in sidebar group for a user's most-used teams
            return group.builtin ? t('teamhub', 'Favorites') : group.name
        },

        /**
         * @param {string} groupId the chosen group's id, '' for no group
         */
        onPick(groupId) {
            if (groupId !== this.currentGroupId) {
                this.$emit('assign', { teamId: this.teamId, groupId: groupId || null })
            }
            this.$emit('close')
        },
    },
}
</script>

<style scoped lang="scss">
.th-gpm {
    display: flex;
    flex-direction: column;
    gap: var(--default-grid-baseline, 4px);
    border: 0;
    padding: 0;
    margin: 0;
}

/* Visually hidden rather than absent — see the note on the element.
   Scoped rather than a bare `.hidden-visually` so it cannot collide with
   the server's own copy of the class, matching TeamNavGroup.vue. */
.th-gpm__legend {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
